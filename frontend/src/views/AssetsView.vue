<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Search, Edit, Delete, Picture, InfoFilled, MagicStick, Upload, Warning, Loading, DocumentCopy, Share, CircleClose, CopyDocument } from '@element-plus/icons-vue'
import BadgePill from '@/components/ui/BadgePill.vue'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import PageSkeleton from '@/components/ui/PageSkeleton.vue'
import AssetEditorDialog from '@/components/assets/AssetEditorDialog.vue'
import AssetShareDialog, { type ShareTarget } from '@/components/assets/AssetShareDialog.vue'
import AssetReuseDialog from '@/components/assets/AssetReuseDialog.vue'
import SharedAssetsPanel from '@/components/assets/SharedAssetsPanel.vue'
import { t } from '@/i18n'
import { copyTextToClipboard } from '@/utils/clipboard'
import { generatedCover } from '@/utils/cover'
import { isAssetImageJobBusy, isAssetImageJobPending, isAssetImageJobQueued } from '@/utils/assetImageJob'
import { clearWorkerPageContext, writeWorkerPageContext } from '@/utils/workerContext'
import { listSeries } from '@/api/series'
import { batchGenerateCoreImages, cancelAssetImageJobs, createAsset, deleteAsset, deleteAssetImageVersion, getAssetImageJob, listAssets, selectAssetImageVersion, updateAsset } from '@/api/asset'
import { cacheBustMediaUrl, uploadImageFile } from '@/utils/uploadImage'
import { listGrouped as listModelConfigs } from '@/api/modelConfig'
import request from '@/api/http'
import { useAuthStore } from '@/stores/auth'
import type { Asset, AssetImage, AssetImageVersion, AssetPayload, AssetType, ModelConfig, Series } from '@/types'

const authStore = useAuthStore()

const activeAssetTab = ref<'mine' | 'shared'>('mine')
const shareDialogVisible = ref(false)
const shareTarget = ref<ShareTarget | null>(null)
const reuseDialogVisible = ref(false)

function openShareDialog(asset: Asset) {
  shareTarget.value = { kind: 'asset', id: asset.id, name: asset.name }
  shareDialogVisible.value = true
}

function openShareSeriesDialog() {
  if (!selectedSeries.value) return
  shareTarget.value = { kind: 'series', id: selectedSeries.value.id, name: selectedSeries.value.title }
  shareDialogVisible.value = true
}

function openReuseDialog() {
  if (assetWorkflowLocked.value) return ElMessage.warning(assetWorkflowLockedMessage.value)
  if (!selectedSeriesId.value) return ElMessage.warning(t('请先选择作品'))
  reuseDialogVisible.value = true
}

async function handleAssetReused() {
  await loadAssetsBySeries()
}

const generationLoading = ref<Record<string, boolean>>({})
const generationStatus = ref<Record<string, string>>({})
const selectingAssetImageVersionIds = ref<Record<number, boolean>>({})
const deletingAssetImageVersionIds = ref<Record<number, boolean>>({})
const batchGenerating = ref(false)
const batchCancelling = ref(false)
const cancellingAssetIds = ref<Record<number, boolean>>({})
const imageModels = ref<ModelConfig[]>([])
const selectedImageModelId = ref<number | null>(null)
const batchCorePromptAdditions = reactive<Record<AssetType, string>>({
  character: '',
  scene: '',
  prop: '',
})
const batchCoreReferenceImageUrl = ref('')
const activeCorePromptType = ref<AssetType>('character')
const ASSET_IMAGE_MODEL_STORAGE_KEY = 'malulu.assets.defaultImageModelId'
const BATCH_CORE_PROMPT_STORAGE_KEY = 'malulu.assets.batchCorePromptAdditions'
const imageJobTimers = new Map<string, number>()

const uploadingImage = ref(false)
const uploadProgress = ref(0)

async function handleFileUpload(img: AssetImage) {
  if (assetWorkflowLocked.value) return ElMessage.warning(assetWorkflowLockedMessage.value)
  if (uploadingImage.value) return
  const input = document.createElement('input')
  input.type = 'file'
  input.accept = 'image/*'
  input.onchange = async (e: any) => {
    const file = e.target.files[0]
    if (!file) return
    uploadingImage.value = true
    uploadProgress.value = 0
    try {
      img.url = await uploadImageFile(file, {
        onProgress: (percent) => {
          uploadProgress.value = percent
        },
      })
      ElMessage.success(t('图片已上传'))
    } catch (err: any) {
      ElMessage.error(err.message || t('上传失败'))
    } finally {
      uploadingImage.value = false
      uploadProgress.value = 0
    }
  }
  input.click()
}

async function uploadImageToUrl(assign: (url: string) => void) {
  if (assetWorkflowLocked.value) return ElMessage.warning(assetWorkflowLockedMessage.value)
  if (uploadingImage.value) return
  const input = document.createElement('input')
  input.type = 'file'
  input.accept = 'image/*'
  input.onchange = async (e: any) => {
    const file = e.target.files[0]
    if (!file) return
    uploadingImage.value = true
    uploadProgress.value = 0
    try {
      const url = await uploadImageFile(file, {
        onProgress: (percent) => {
          uploadProgress.value = percent
        },
      })
      assign(url)
      ElMessage.success(t('参考图已上传'))
    } catch (err: any) {
      ElMessage.error(err.message || t('上传失败'))
    } finally {
      uploadingImage.value = false
      uploadProgress.value = 0
    }
  }
  input.click()
}

function openImagePreview(url: string) {
  if (!url) return
  imagePreviewUrl.value = url
  imagePreviewVisible.value = true
}

function copyImageLink(url: string) {
  void copyTextToClipboard(url, t('图片链接已复制'))
}

async function handleGenerate(img: AssetImage, idx: number) {
  if (assetWorkflowLocked.value) {
    return ElMessage.warning(assetWorkflowLockedMessage.value)
  }
  if (imageModels.value.length === 0) {
    return ElMessage.warning(t('暂无可用图片模型，请联系管理员配置'))
  }
  if (!selectedImageModelId.value) {
    return ElMessage.warning(t('请先选择图片模型'))
  }
  const effectiveReferenceUrl = (img.reference_image_url || form.referenceImageUrl || (img.view_type !== 'main' ? form.images[0].url : '') || '').trim()
  if (img.view_type !== 'main' && !effectiveReferenceUrl) {
    return ElMessage.warning(t('请先生成或上传主视图'))
  }
  const prompt = resolveImagePrompt(img)
  if (!prompt) {
    return ElMessage.warning(t('请先填写这张图的生成指令'))
  }

  const key = `${img.view_type}-${idx}`
  generationLoading.value[key] = true
  generationStatus.value[key] = '排队中'
  
  try {
    const res: any = await request({
      url: '/api/assets/generate-image',
      method: 'POST',
      data: {
        asset_id: editingId.value,
        model_config_id: selectedImageModelId.value,
        image_prompt: prompt,
        description: form.description,
        asset_type: form.type,
        view_type: img.view_type,
        main_image_url: form.images[0].url,
        reference_image_url: effectiveReferenceUrl,
      }
    })

    if (res.url) {
      img.url = res.url
      generationLoading.value[key] = false
      generationStatus.value[key] = ''
      ElMessage.success(t('图片已生成'))
      return
    }

    if (!res.id) {
      if (!Array.isArray(res.jobs) || res.jobs.length === 0) {
        throw new Error('图片生成任务创建失败')
      }
    }

    const jobs = Array.isArray(res.jobs) ? res.jobs : (res.job ? [res.job] : [])
    ElMessage.success(t('已加入生成队列'))
    for (const job of jobs) {
      if (!job?.id) continue
      pollImageJob(job.id, img, key)
      break
    }
    await loadAssetsBySeries()
    syncEditingAssetFromList()
  } catch (err: any) {
    ElMessage.error(err.message || t('生成失败'))
    generationStatus.value[key] = ''
    generationLoading.value[key] = false
  }
}

async function pollImageJob(jobId: number, img: AssetImage, key: string, attempt = 0) {
  const timerKey = `${key}:${jobId}`
  const existingTimer = imageJobTimers.get(timerKey)
  if (existingTimer) {
    window.clearTimeout(existingTimer)
  }

  try {
    const job: any = await getAssetImageJob(jobId, { silent: true })

    if (job.status === 'success') {
      imageJobTimers.delete(timerKey)
      generationLoading.value[key] = false
      generationStatus.value[key] = ''
      ElMessage.success(editingId.value ? t('候选图已生成') : t('图片已生成'))
      if (editingId.value) {
        await loadAssetsBySeries()
        syncEditingAssetFromList()
      } else if (job.url) {
        img.url = cacheBustMediaUrl(String(job.url))
      }
      void authStore.refreshProfile(true)
      return
    }

    if (job.status === 'failed' || job.status === 'cancelled' || job.status === 'stale') {
      imageJobTimers.delete(timerKey)
      generationLoading.value[key] = false
      generationStatus.value[key] = ''
      if (job.status === 'failed') {
        ElMessage.error(job.error_message || t('生成失败'))
      }
      return
    }

    if (attempt >= 240) {
      imageJobTimers.delete(timerKey)
      generationLoading.value[key] = false
      generationStatus.value[key] = ''
      return
    }

    generationStatus.value[key] = isAssetImageJobBusy(job.status) ? '生成中' : '排队中'
    const delay = attempt < 5 ? 2000 : 4000
    const timer = window.setTimeout(() => pollImageJob(jobId, img, key, attempt + 1), delay)
    imageJobTimers.set(timerKey, timer)
  } catch (err: any) {
    if (attempt >= 3) {
      ElMessage.error(err.message || t('更新生成进度失败'))
      imageJobTimers.delete(timerKey)
      generationStatus.value[key] = ''
      generationLoading.value[key] = false
      return
    }
    const timer = window.setTimeout(() => pollImageJob(jobId, img, key, attempt + 1), 3000)
    imageJobTimers.set(timerKey, timer)
  }
}

function clearImageJobTimers() {
  for (const timer of imageJobTimers.values()) {
    window.clearTimeout(timer)
  }
  imageJobTimers.clear()
}

const TYPE_META: Record<AssetType, { label: string; hint: string }> = {
  character: { label: '人物', hint: '基础形态、正视图、三视图' },
  scene: { label: '场景', hint: '室内外场景、环境氛围图' },
  prop: { label: '道具', hint: '关键道具、可重复物件' },
}

const VIEW_TYPES = [
  { value: 'main', label: '核心视图' },
  { value: 'front', label: '正视图' },
  { value: 'side', label: '侧视图' },
  { value: 'back', label: '背视图' },
  { value: 'three_view', label: '三视图' },
  { value: 'reference', label: '参考图' },
]
const EXTRA_VIEW_TYPES = VIEW_TYPES.filter((item) => item.value !== 'main')

const VIEW_LABEL_MAP = Object.fromEntries(VIEW_TYPES.map((item) => [item.value, item.label])) as Record<string, string>

const CORE_VIEW_PROMPT_RULES = [
  {
    key: 'character',
    type: '人物',
    token: '{人物描述}',
    rule: '正面全身、侧面全身、背面全身和面部特写；同一角色、纯白背景、棚拍灯光，不要第二个人物、文字或水印。',
    placeholder: '例如：更卡通化，2D 动画风，清晰线稿，色彩更鲜艳，角色比例更年轻。',
  },
  {
    key: 'scene',
    type: '场景',
    token: '{场景描述}',
    rule: '同一场景空间的主视图、反向广角、侧向广角和斜向广角；保持门窗、家具、材质、动线一致，不要人物。',
    placeholder: '例如：更偏国风写实，空间层次更明确，光线柔和，材质细节更丰富。',
  },
  {
    key: 'prop',
    type: '物品',
    token: '{物品描述}',
    rule: '左侧主视图，右侧同一物品多角度参考；纯白棚拍背景，结构、材质、颜色一致，不要人物、房间或使用场景。',
    placeholder: '例如：3D 渲染质感，棚拍高光，边缘清晰，材质纹理更精细。',
  },
] as const

const activeCorePromptRule = computed(() =>
  CORE_VIEW_PROMPT_RULES.find((rule) => rule.key === activeCorePromptType.value) ?? CORE_VIEW_PROMPT_RULES[0],
)

function displayAssetTypeLabel(type: AssetType | 'all') {
  if (type === 'all') return t('全部类型')
  return t(TYPE_META[type]?.label ?? String(type))
}

function displayAssetTypeHint(type: AssetType) {
  return t(TYPE_META[type]?.hint ?? '')
}

function displayViewTypeLabel(viewType: string) {
  return t(VIEW_LABEL_MAP[viewType] ?? '参考图')
}

function displayGenerationStatus(value: string | undefined) {
  return t(value || 'AI 生成')
}

function displayCorePromptType(type: string) {
  return t(type)
}

function displayCorePromptToken(token: string) {
  return t(token)
}

function displayCorePromptRule(rule: string) {
  return t(rule)
}

function displayCorePromptPlaceholder(placeholder: string) {
  return t(placeholder)
}

function getViewLabel(viewType: string) {
  return VIEW_LABEL_MAP[viewType] ?? '参考图'
}

function getAssetSubjectLabel(type: AssetType) {
  if (type === 'character') return '角色'
  if (type === 'scene') return '场景'
  return '物品'
}

function getConsistencyRule(type: AssetType) {
  if (type === 'character') return '保持同一角色一致性：五官、脸型、发型、服装、体型、年龄感和气质必须与主图一致'
  if (type === 'scene') return '保持同一场景一致性：空间结构、装修材质、光线氛围、关键陈设和镜头风格必须与主图一致'
  return '保持同一物品一致性：外形结构、材质、颜色、纹理、比例和磨损细节必须与主图一致'
}

/** 仅核心视图输入框使用；其他视图直接用 defaultImagePrompt */
function coreViewPromptPlaceholder(img: AssetImage) {
  if (img.view_type !== 'main') {
    return defaultImagePrompt(img)
  }
  const base = defaultImagePrompt(img)
  if (form.type === 'character') {
    return `${base}（仅核心视图：生成时自动追加多视图棚拍规则）`
  }
  if (form.type === 'scene') {
    return `${base}（仅核心视图：生成时自动追加多视角场景参考板）`
  }
  if (form.type === 'prop') {
    return `${base}（仅核心视图：生成时自动追加物品多角度参考）`
  }
  return base
}

function defaultImagePrompt(img: AssetImage) {
  const subject = getAssetSubjectLabel(form.type)
  const viewLabel = getViewLabel(img.view_type)
  const name = form.name.trim() || `未命名${subject}`
  const description = form.description.trim()

  if (img.view_type === 'main') {
    const base =
      description ||
      (form.type === 'character'
        ? `角色「${name}」`
        : form.type === 'prop'
          ? `物品「${name}」`
          : `场景「${name}」`)
    // 核心视图默认只填「主体描述」；画风与多视图规则由后端生成时自动拼接
    return base
  }

  const descriptionPart = description ? `。资产描述：${description}` : ''

  const mainImageUrl = (form.images[0]?.url || '').trim()
  const referencePart = mainImageUrl ? '参考已上传的主图。' : ''
  return `${referencePart}根据主图生成${subject}「${name}」的${viewLabel}，${getConsistencyRule(form.type)}，不要改成新的${subject}，不要拼图，不要多场景${descriptionPart}`
}

function resolveImagePrompt(img: AssetImage) {
  const prompt = (img.image_prompt || '').trim()
  if (prompt) return prompt
  const generatedPrompt = defaultImagePrompt(img)
  img.image_prompt = generatedPrompt
  return generatedPrompt
}

const loading = ref(true)
const saving = ref(false)
const seriesList = ref<Series[]>([])
const assets = ref<Asset[]>([])
const selectedSeriesId = ref<number | null>(null)
const typeFilter = ref<'all' | AssetType>('all')
const keyword = ref('')
const assetSearchPlaceholder = computed(() => t('asset.search.placeholder'))

const dialogVisible = ref(false)
const editingId = ref<number | null>(null)
const editorAsset = ref<Asset | null>(null)
const editorInitialType = ref<AssetType>('character')
const imagePreviewVisible = ref(false)
const imagePreviewUrl = ref('')
const form = reactive<{
  series_id: number
  type: AssetType
  name: string
  description: string
  tagsInput: string
  referenceImageUrl: string
  images: AssetImage[]
}>({
  series_id: 0,
  type: 'character',
  name: '',
  description: '',
  tagsInput: '',
  referenceImageUrl: '',
  images: [{ view_type: 'front', url: '', note: '', sort: 10 }],
})

const selectedSeries = computed(() => {
  if (!selectedSeriesId.value) return null
  return seriesList.value.find((s) => s.id === selectedSeriesId.value) ?? null
})

watch(
  selectedSeries,
  (series) => {
    if (!series) {
      clearWorkerPageContext('assets')
      return
    }
    writeWorkerPageContext({
      source: 'assets',
      page: 'assets',
      series_id: series.id,
      series_title: series.title,
    })
  },
  { immediate: true },
)

const selectedSeriesWorkflowRun = computed(() => selectedSeries.value?.active_workflow_run ?? null)

const assetWorkflowLocked = computed(() => {
  const status = selectedSeriesWorkflowRun.value?.status
  return status === 'queued' || status === 'running' || status === 'failed'
})

const assetWorkflowBusy = computed(() => {
  const status = selectedSeriesWorkflowRun.value?.status
  return status === 'queued' || status === 'running'
})

const assetWorkflowLockedMessage = computed(() =>
  selectedSeriesWorkflowRun.value?.status === 'failed'
    ? t('作品解析失败，请先回到作品页处理')
    : t('作品正在解析，完成前不能编辑资产或生成图片'),
)

const filteredAssets = computed(() => {
  return assets.value.filter((a) => {
    if (typeFilter.value !== 'all' && a.type !== typeFilter.value) return false
    if (keyword.value.trim() !== '' && !a.name.includes(keyword.value.trim())) return false
    return true
  })
})

const groupedAssets = computed(() => {
  const groups: Record<AssetType, Asset[]> = { character: [], scene: [], prop: [] }
  for (const a of filteredAssets.value) groups[a.type].push(a)
  return groups
})

function firstImageUrl(asset: Asset) {
  const images = asset.images ?? []
  const core = images.find((img) => img.view_type === 'main' && (img.url || '').trim() !== '')
  if (core?.url) return core.url
  const nonLook = images.find((img) => img.reference_role !== 'look' && img.view_type !== 'look' && (img.url || '').trim() !== '')
  if (nonLook?.url) return nonLook.url
  return images.find((img) => (img.url || '').trim() !== '')?.url ?? ''
}

function cloneAssetImage(img: AssetImage, fallbackPrompt = ''): AssetImage {
  return {
    ...img,
    image_prompt: img.image_prompt ?? fallbackPrompt,
    versions: (img.versions ?? []).map((version) => ({ ...version })),
  }
}

function hydrateAsset(asset: Asset): Asset {
  return {
    ...asset,
    image_prompt: asset.image_prompt ?? '',
    tags: asset.tags ?? [],
    images: (asset.images ?? []).map((img) => cloneAssetImage(img)),
  }
}

function hasCoreImage(asset: Asset) {
  return (asset.images ?? []).some((img) => img.view_type === 'main' && (img.url || '').trim() !== '')
}

function hasPendingCoreImageJob(asset: Asset) {
  return (asset.image_jobs ?? []).some((job) => job.view_type === 'main' && isAssetImageJobPending(job.status))
}

function hasQueuedCoreImageJob(asset: Asset) {
  return (asset.image_jobs ?? []).some((job) => job.view_type === 'main' && isAssetImageJobQueued(job.status))
}

const coreImageMissingAssets = computed(() =>
  assets.value.filter((asset) => !hasCoreImage(asset) && !hasPendingCoreImageJob(asset))
)

const queuedCoreImageJobCount = computed(() =>
  assets.value.reduce((count, asset) => {
    const jobs = (asset.image_jobs ?? []).filter((job) => isAssetImageJobQueued(job.status))
    return count + jobs.length
  }, 0),
)

const quickGeneratingIds = ref<Record<number, boolean>>({})

/**
 * 列表页空封面上的快捷生成：直接为单个资产排队核心视图任务，
 * 不用先进编辑弹窗。提示词优先用资产描述，和批量生成行为一致。
 */
async function quickGenerateCore(asset: Asset) {
  if (assetWorkflowLocked.value) return ElMessage.warning(assetWorkflowLockedMessage.value)
  if (imageModels.value.length === 0) return ElMessage.warning(t('暂无可用图片模型，请联系管理员配置'))
  if (!selectedImageModelId.value) return ElMessage.warning(t('请先选择图片模型'))
  if (quickGeneratingIds.value[asset.id]) return

  const prompt = (asset.description || '').trim()
    || `${getAssetSubjectLabel(asset.type)}「${asset.name}」`

  quickGeneratingIds.value = { ...quickGeneratingIds.value, [asset.id]: true }
  try {
    await request({
      url: '/api/assets/generate-image',
      method: 'POST',
      data: {
        asset_id: asset.id,
        model_config_id: selectedImageModelId.value,
        image_prompt: prompt,
        description: asset.description ?? '',
        asset_type: asset.type,
        view_type: 'main',
        main_image_url: '',
        reference_image_url: batchCoreReferenceImageUrl.value.trim(),
      },
    })
    ElMessage.success(t('已加入生成队列'))
    await loadAssetsBySeries()
  } finally {
    quickGeneratingIds.value = { ...quickGeneratingIds.value, [asset.id]: false }
  }
}

let assetListPollTimer: number | null = null
let seriesLockPollTimer: number | null = null

/** 列表里有排队/生成中的图片任务时轮询刷新，生成完成后卡片自动出图并停掉定时器 */
function syncAssetListPoll() {
  const hasPending = assets.value.some((asset) =>
    (asset.image_jobs ?? []).some((job) => isAssetImageJobPending(job.status)),
  )
  const wasPolling = assetListPollTimer !== null
  if (hasPending && assetListPollTimer === null) {
    assetListPollTimer = window.setInterval(() => {
      void loadAssetsBySeries({ silent: true })
    }, 6000)
  }
  if (!hasPending && assetListPollTimer !== null) {
    window.clearInterval(assetListPollTimer)
    assetListPollTimer = null
    clearImageJobTimers()
  }
  if (wasPolling !== (assetListPollTimer !== null)) {
    window.dispatchEvent(new Event('malulu:worker-refresh'))
  }
}

/** 作品解析锁定态依赖 series.active_workflow_run；定时回补，避免遮罩偶发丢失。 */
function syncSeriesLockPoll() {
  const shouldPoll = !!selectedSeriesId.value
  if (shouldPoll && seriesLockPollTimer === null) {
    seriesLockPollTimer = window.setInterval(() => {
      void loadSeries({ silent: true })
    }, 5000)
  }
  if (!shouldPoll && seriesLockPollTimer !== null) {
    window.clearInterval(seriesLockPollTimer)
    seriesLockPollTimer = null
  }
}

async function loadSeries(options: { silent?: boolean } = {}) {
  try {
    const data = await listSeries(undefined, { silent: options.silent })
    seriesList.value = data
    if (!selectedSeriesId.value && data.length > 0) {
      selectedSeriesId.value = data[0].id
    }
  } catch (error) {
    if (options.silent) return
    throw error
  }
}

async function loadImageModels() {
  const groups = await listModelConfigs()
  const imageGroup = groups.find((group) => group.type === 'image')
  imageModels.value = imageGroup?.models ?? []

  const savedId = Number(localStorage.getItem(ASSET_IMAGE_MODEL_STORAGE_KEY) || 0)
  if (savedId > 0 && imageModels.value.some((model) => model.id === savedId)) {
    selectedImageModelId.value = savedId
    return
  }

  selectedImageModelId.value = imageModels.value[0]?.id ?? null
  persistImageModelPreference()
}

function loadBatchCorePromptPreference() {
  const raw = localStorage.getItem(BATCH_CORE_PROMPT_STORAGE_KEY) || ''
  if (!raw) return
  try {
    const parsed = JSON.parse(raw) as Partial<Record<AssetType, string>>
    for (const key of ['character', 'scene', 'prop'] as AssetType[]) {
      batchCorePromptAdditions[key] = String(parsed[key] || '')
    }
    batchCoreReferenceImageUrl.value = String((parsed as any).reference_image_url || '')
  } catch {
    batchCorePromptAdditions.character = raw
  }
}

async function loadAssetsBySeries(options: { silent?: boolean } = {}) {
  if (!selectedSeriesId.value) {
    assets.value = []
    syncAssetListPoll()
    return
  }
  try {
    const data = await listAssets({ series_id: selectedSeriesId.value }, { silent: options.silent })
    assets.value = (data || []).map((a) => hydrateAsset(a))
    syncAssetListPoll()
  } catch (error) {
    if (options.silent) return
    throw error
  }
}

async function loadData() {
  loading.value = true
  try {
    await Promise.all([loadSeries(), loadImageModels()])
    await loadAssetsBySeries()
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  loadBatchCorePromptPreference()
  void loadData().finally(() => {
    syncSeriesLockPoll()
  })
})
onBeforeUnmount(() => {
  clearImageJobTimers()
  if (assetListPollTimer !== null) {
    window.clearInterval(assetListPollTimer)
    assetListPollTimer = null
  }
  if (seriesLockPollTimer !== null) {
    window.clearInterval(seriesLockPollTimer)
    seriesLockPollTimer = null
  }
  clearWorkerPageContext('assets')
})

function persistImageModelPreference() {
  if (selectedImageModelId.value) {
    localStorage.setItem(ASSET_IMAGE_MODEL_STORAGE_KEY, String(selectedImageModelId.value))
  } else {
    localStorage.removeItem(ASSET_IMAGE_MODEL_STORAGE_KEY)
  }
}

function persistBatchCorePromptPreference() {
  const value = {
    character: batchCorePromptAdditions.character.trim(),
    scene: batchCorePromptAdditions.scene.trim(),
    prop: batchCorePromptAdditions.prop.trim(),
    reference_image_url: batchCoreReferenceImageUrl.value.trim(),
  }
  if (value.character || value.scene || value.prop || value.reference_image_url) {
    localStorage.setItem(BATCH_CORE_PROMPT_STORAGE_KEY, JSON.stringify(value))
  } else {
    localStorage.removeItem(BATCH_CORE_PROMPT_STORAGE_KEY)
  }
}

async function onSeriesChange() {
  typeFilter.value = 'all'
  keyword.value = ''
  loading.value = true
  try {
    await loadSeries()
    await loadAssetsBySeries()
  } finally {
    loading.value = false
    syncSeriesLockPoll()
  }
}

function resetForm() {
  clearImageJobTimers()
  generationLoading.value = {}
  generationStatus.value = {}
  editingId.value = null
  form.series_id = selectedSeriesId.value ?? 0
  form.type = 'character'
  form.name = ''
  form.description = ''
  form.tagsInput = ''
  form.referenceImageUrl = ''
  form.images = [
    { view_type: 'main', url: '', note: '核心视图', image_prompt: '', sort: 10 }
  ]
}

function openCreate(type: AssetType) {
  if (!selectedSeriesId.value) {
    ElMessage.warning(t('请先选择作品'))
    return
  }
  if (assetWorkflowLocked.value) {
    ElMessage.warning(assetWorkflowLockedMessage.value)
    return
  }
  editorAsset.value = null
  editorInitialType.value = type
  dialogVisible.value = true
}

function openEdit(asset: Asset) {
  if (assetWorkflowLocked.value) {
    ElMessage.warning(assetWorkflowLockedMessage.value)
    return
  }
  const latest = assets.value.find((item) => item.id === asset.id) ?? asset
  editorAsset.value = hydrateAsset(latest)
  editorInitialType.value = latest.type
  dialogVisible.value = true
}

function syncEditingAssetFromList() {
  if (!editingId.value) return
  const updated = assets.value.find((asset) => asset.id === editingId.value)
  if (!updated) return

  const currentByView = new Map(form.images.map((img) => [img.view_type, img]))
  for (const next of updated.images ?? []) {
    const current = currentByView.get(next.view_type)
    if (current) {
      Object.assign(current, cloneAssetImage(next, current.image_prompt ?? ''))
    }
  }
}

function assetImageVersionsFor(img: AssetImage): AssetImageVersion[] {
  const versions = Array.isArray(img.versions) ? [...img.versions] : []
  const currentUrl = String(img.url || '')
  if (currentUrl && !versions.some((version) => version.url === currentUrl)) {
    versions.unshift({
      id: -Math.max(Number(img.id || 0), 1),
      asset_id: img.asset_id,
      asset_image_id: img.id,
      view_type: img.view_type,
      url: currentUrl,
      prompt: img.image_prompt ?? '',
      source: 'current',
      is_selected: true,
    })
  }
  return versions
    .filter((version) => String(version.url || '') !== '')
    .map((version) => ({
      ...version,
      is_selected: !!version.is_selected || (!!currentUrl && version.url === currentUrl),
    }))
    .sort((a, b) => Number(a.id) - Number(b.id))
}

function assetImageVersionLabel(version: AssetImageVersion, index: number) {
  if (version.is_selected) return '当前'
  return `候选 ${String(index + 1).padStart(2, '0')}`
}

async function selectAssetVersion(img: AssetImage, version: AssetImageVersion) {
  if (!editingId.value || !version?.id) return
  if (version.id < 0) return

  selectingAssetImageVersionIds.value = { ...selectingAssetImageVersionIds.value, [version.id]: true }
  try {
    const updated = await selectAssetImageVersion(editingId.value, version.id)
    const normalized = hydrateAsset(updated)
    const assetIndex = assets.value.findIndex((asset) => asset.id === normalized.id)
    if (assetIndex >= 0) {
      assets.value.splice(assetIndex, 1, normalized)
    } else {
      assets.value.unshift(normalized)
    }

    const updatedImage = normalized.images.find((item) => item.view_type === version.view_type)
    if (updatedImage) {
      Object.assign(img, cloneAssetImage(updatedImage, img.image_prompt ?? ''))
    }
    ElMessage.success(t('已切换版本'))
  } finally {
    selectingAssetImageVersionIds.value = { ...selectingAssetImageVersionIds.value, [version.id]: false }
  }
}

async function deleteAssetVersion(img: AssetImage, version: AssetImageVersion) {
  if (!editingId.value || !version?.id) return
  if (version.id < 0) return
  if (version.is_selected) return ElMessage.warning(t('当前版本正在使用，请先切换版本'))

  try {
    await ElMessageBox.confirm(t('确定删除这个候选版本？删除后不可恢复。'), t('删除候选版本'), {
      type: 'warning',
      confirmButtonText: t('删除'),
      cancelButtonText: t('取消'),
    })
  } catch {
    return
  }

  deletingAssetImageVersionIds.value = { ...deletingAssetImageVersionIds.value, [version.id]: true }
  try {
    const updated = await deleteAssetImageVersion(editingId.value, version.id)
    const normalized = hydrateAsset(updated)
    const assetIndex = assets.value.findIndex((asset) => asset.id === normalized.id)
    if (assetIndex >= 0) {
      assets.value.splice(assetIndex, 1, normalized)
    }

    const updatedImage = normalized.images.find((item) => item.view_type === img.view_type)
    if (updatedImage) {
      Object.assign(img, cloneAssetImage(updatedImage, img.image_prompt ?? ''))
    } else {
      img.versions = (img.versions ?? []).filter((item) => item.id !== version.id)
    }
    ElMessage.success(t('已删除候选版本'))
  } finally {
    deletingAssetImageVersionIds.value = { ...deletingAssetImageVersionIds.value, [version.id]: false }
  }
}

function restoreImageJobs(asset: Asset) {
  for (const job of asset.image_jobs ?? []) {
    const index = form.images.findIndex((img) => img.view_type === job.view_type)
    if (index < 0) continue

    const key = `${job.view_type}-${index}`
    if (isAssetImageJobPending(job.status)) {
      generationLoading.value[key] = true
      generationStatus.value[key] = isAssetImageJobBusy(job.status) ? '生成中' : '排队中'
      pollImageJob(job.id, form.images[index], key)
    }
  }
}

function addImageRow() {
  if (assetWorkflowLocked.value) {
    ElMessage.warning(assetWorkflowLockedMessage.value)
    return
  }
  form.images.push({
    view_type: 'reference',
    url: '',
    note: '',
    image_prompt: '',
    sort: (form.images.length + 1) * 10,
  })
}

function removeImageRow(index: number) {
  if (assetWorkflowLocked.value) {
    ElMessage.warning(assetWorkflowLockedMessage.value)
    return
  }
  if (index < 1) return
  form.images.splice(index, 1)
}

function replaceCoreImageRow() {
  if (assetWorkflowLocked.value) {
    ElMessage.warning(assetWorkflowLockedMessage.value)
    return
  }
  const main = form.images[0]
  if (!main) return
  void handleFileUpload(main)
}

async function submitAsset() {
  if (assetWorkflowLocked.value) return ElMessage.warning(assetWorkflowLockedMessage.value)
  if (!form.series_id) return ElMessage.warning(t('请选择所属作品'))
  if (!form.name.trim()) return ElMessage.warning(t('请输入资产名称'))

  const images = form.images
    .map((img, idx) => ({
      view_type: img.view_type || 'reference',
      url: (img.url || '').trim(),
      note: (img.note || '').trim(),
      image_prompt: (img.image_prompt || '').trim(),
      sort: img.sort ?? (idx + 1) * 10,
    }))
    .filter((img) => img.url !== '' || img.image_prompt !== '' || img.note !== '')

  if (!editingId.value && images.length === 0) return ElMessage.warning(t('请至少添加一张图片或生成指令'))

  const payload: AssetPayload = {
    series_id: form.series_id,
    type: form.type,
    name: form.name.trim(),
    description: form.description.trim(),
    image_prompt: '',
    tags: form.tagsInput
      .split(',')
      .map((x) => x.trim())
      .filter((x) => x !== ''),
  }
  if (images.length > 0 || !editingId.value) {
    payload.images = images
  }

  saving.value = true
  try {
    if (editingId.value) {
      await updateAsset(editingId.value, payload)
      ElMessage.success(t('资产已更新'))
    } else {
      await createAsset(payload)
      ElMessage.success(t('资产已创建'))
    }
    dialogVisible.value = false
    await loadAssetsBySeries()
  } finally {
    saving.value = false
  }
}

async function handleEditorSaved(asset: Asset) {
  const normalized = hydrateAsset(asset)
  const index = assets.value.findIndex((item) => item.id === normalized.id)
  if (index >= 0) {
    assets.value.splice(index, 1, normalized)
  } else {
    assets.value.unshift(normalized)
  }
  if (!dialogVisible.value) {
    await loadAssetsBySeries()
  }
}

async function handleDelete(asset: Asset) {
  if (assetWorkflowLocked.value) return ElMessage.warning(assetWorkflowLockedMessage.value)
  await ElMessageBox.confirm(t('确定删除资产「{name}」？', { name: asset.name }), t('删除资产'), {
    type: 'warning',
    confirmButtonText: t('删除'),
    cancelButtonText: t('取消'),
  })
  await deleteAsset(asset.id)
  ElMessage.success(t('资产已删除'))
  await loadAssetsBySeries()
}

async function handleBatchGenerateCoreImages() {
  if (assetWorkflowLocked.value) return ElMessage.warning(assetWorkflowLockedMessage.value)
  if (!selectedSeriesId.value) return ElMessage.warning(t('请先选择作品'))
  if (!selectedImageModelId.value) return ElMessage.warning(t('请先选择图片模型'))

  const count = coreImageMissingAssets.value.length
  if (count <= 0) {
    return ElMessage.info(t('当前作品没有缺少核心视图的资产'))
  }

  await ElMessageBox.confirm(
    t('将为 {count} 个缺少核心视图的资产排队生成。任务会逐个执行。', { count }),
    t('批量生成核心视图'),
    {
      type: 'warning',
      confirmButtonText: t('开始排队'),
      cancelButtonText: t('取消'),
    },
  )

  batchGenerating.value = true
  try {
    persistBatchCorePromptPreference()
    const result = await batchGenerateCoreImages({
      series_id: selectedSeriesId.value,
      model_config_id: selectedImageModelId.value,
      reference_image_url: batchCoreReferenceImageUrl.value.trim(),
      prompt_additions: {
        character: batchCorePromptAdditions.character.trim(),
        scene: batchCorePromptAdditions.scene.trim(),
        prop: batchCorePromptAdditions.prop.trim(),
      },
    })
    ElMessage.success(t('已加入队列 {created} 个，跳过 {skipped} 个', {
      created: result.created,
      skipped: result.skipped_with_core + result.skipped_queued,
    }))
    await loadAssetsBySeries()
  } finally {
    batchGenerating.value = false
  }
}

async function handleBatchCancelQueuedImageJobs() {
  if (assetWorkflowLocked.value) return ElMessage.warning(assetWorkflowLockedMessage.value)
  if (!selectedSeriesId.value) return ElMessage.warning(t('请先选择作品'))
  const count = queuedCoreImageJobCount.value
  if (count <= 0) {
    return ElMessage.info(t('当前作品没有排队中的生图任务'))
  }

  await ElMessageBox.confirm(
    t('将取消当前作品 {count} 个排队中的生图任务。已开始生成的任务不会中断。', { count }),
    t('取消排队'),
    {
      type: 'warning',
      confirmButtonText: t('确认取消'),
      cancelButtonText: t('返回'),
    },
  )

  batchCancelling.value = true
  try {
    const result = await cancelAssetImageJobs({ series_id: selectedSeriesId.value })
    if (result.cancelled > 0) {
      ElMessage.success(t('已取消 {count} 个排队任务', { count: result.cancelled }))
    } else {
      ElMessage.info(t('没有可取消的排队任务'))
    }
    await loadAssetsBySeries()
  } finally {
    batchCancelling.value = false
  }
}

async function handleCancelAssetQueuedJobs(asset: Asset) {
  if (assetWorkflowLocked.value) return ElMessage.warning(assetWorkflowLockedMessage.value)
  if (!hasQueuedCoreImageJob(asset)) {
    return ElMessage.info(t('该资产没有排队中的任务，或已开始生成'))
  }
  if (cancellingAssetIds.value[asset.id]) return

  cancellingAssetIds.value = { ...cancellingAssetIds.value, [asset.id]: true }
  try {
    const result = await cancelAssetImageJobs({ asset_id: asset.id })
    if (result.cancelled > 0) {
      ElMessage.success(t('已取消 {count} 个排队任务', { count: result.cancelled }))
    } else {
      ElMessage.info(t('没有可取消的排队任务'))
    }
    await loadAssetsBySeries()
  } finally {
    cancellingAssetIds.value = { ...cancellingAssetIds.value, [asset.id]: false }
  }
}
</script>

<template>
  <div class="assets-page assets-page--studio-v2">
    <PageToolbar
      :kicker="t('ASSET REFERENCE')"
      :title="t('资产管理')"
      :subtitle="t('来自剧集生产的共享资产库；每集剧情扩写后会增量补齐人物、场景、人物造型和道具。')"
    >
      <template #actions>
        <div class="top-actions">
          <div class="image-model-picker">
            <span>{{ t('默认图片模型') }}</span>
            <el-select
              v-model="selectedImageModelId"
              :placeholder="t('选择图片模型')"
              :disabled="imageModels.length === 0"
              style="width: 220px"
              size="small"
              @change="persistImageModelPreference"
            >
              <el-option
                v-for="model in imageModels"
                :key="model.id"
                :label="model.name"
                :value="model.id"
              >
                <div class="model-option">
                  <span>{{ model.name }}</span>
                  <small>{{ model.model_id }}</small>
                </div>
              </el-option>
            </el-select>
          </div>
        <el-button
          type="primary"
          size="small"
          :loading="batchGenerating"
          :disabled="assetWorkflowLocked || !selectedSeriesId || !selectedImageModelId || coreImageMissingAssets.length === 0"
          @click="handleBatchGenerateCoreImages"
        >
          <el-icon><MagicStick /></el-icon>
          <span>{{ t('批量生成核心视图') }}</span>
          <small v-if="coreImageMissingAssets.length > 0">{{ coreImageMissingAssets.length }}</small>
        </el-button>
        <el-button
          size="small"
          plain
          type="warning"
          :loading="batchCancelling"
          :disabled="assetWorkflowLocked || !selectedSeriesId || queuedCoreImageJobCount === 0"
          @click="handleBatchCancelQueuedImageJobs"
        >
          <el-icon><CircleClose /></el-icon>
          <span>{{ t('取消排队') }}</span>
          <small v-if="queuedCoreImageJobCount > 0">{{ queuedCoreImageJobCount }}</small>
        </el-button>
        </div>
      </template>
    </PageToolbar>

    <div v-if="assetWorkflowLocked" class="asset-workflow-lock">
      <el-icon v-if="assetWorkflowBusy" class="is-loading"><Loading /></el-icon>
      <el-icon v-else><Warning /></el-icon>
      <span>{{ assetWorkflowLockedMessage }}</span>
    </div>

    <div class="assets-scope-bar">
      <div class="assets-scope-segment" role="tablist" :aria-label="t('资产')">
        <button
          type="button"
          role="tab"
          :aria-selected="activeAssetTab === 'mine'"
          :class="{ 'is-active': activeAssetTab === 'mine' }"
          @click="activeAssetTab = 'mine'"
        >
          <span>{{ t('我的资产') }}</span>
          <small>{{ t('自己的剧集资产和批量生成工作台') }}</small>
        </button>
        <button
          type="button"
          role="tab"
          :aria-selected="activeAssetTab === 'shared'"
          :class="{ 'is-active': activeAssetTab === 'shared' }"
          @click="activeAssetTab = 'shared'"
        >
          <span>{{ t('共享资产') }}</span>
          <small>{{ t('他人分享给你的资产库') }}</small>
        </button>
      </div>
    </div>

    <div v-if="activeAssetTab === 'mine'" class="assets-tab-panel">
    <div class="assets-toolbar">
      <div class="assets-toolbar__left">
        <el-select v-model="selectedSeriesId" :placeholder="t('选择剧本')" style="width: 200px" @change="onSeriesChange" size="small">
          <el-option v-for="s in seriesList" :key="s.id" :value="s.id" :label="s.title" />
        </el-select>
        <div v-if="selectedSeries" class="current-series">{{ selectedSeries.title }}</div>
        <el-button
          v-if="selectedSeries"
          class="share-work-btn"
          size="small"
          :disabled="assetWorkflowLocked"
          @click="openReuseDialog"
        >
          <el-icon><CopyDocument /></el-icon>
          <span>{{ t('从其他作品复用') }}</span>
        </el-button>
        <el-button v-if="selectedSeries" class="share-work-btn" size="small" @click="openShareSeriesDialog">
          <el-icon><Share /></el-icon>
          <span>{{ t('分享整部作品资产') }}</span>
        </el-button>

        <el-divider direction="vertical" />
        
        <el-select v-model="typeFilter" style="width: 110px" :disabled="!selectedSeriesId" size="small">
          <el-option :label="displayAssetTypeLabel('all')" value="all" />
          <el-option :label="displayAssetTypeLabel('character')" value="character" />
          <el-option :label="displayAssetTypeLabel('scene')" value="scene" />
          <el-option :label="displayAssetTypeLabel('prop')" value="prop" />
        </el-select>
        <el-input 
          v-model="keyword" 
          :placeholder="assetSearchPlaceholder"
          clearable 
          style="width: 180px" 
          :disabled="!selectedSeriesId" 
          size="small"
          :prefix-icon="Search"
        />
      </div>
    </div>

    <section class="core-prompt-panel">
      <div class="core-prompt-panel__main">
        <div class="core-prompt-panel__title">
          <el-icon><InfoFilled /></el-icon>
          <span>{{ t('核心视图内置提示词') }}</span>
        </div>
        <div class="core-prompt-rules">
          <div
            v-for="rule in CORE_VIEW_PROMPT_RULES"
            :key="rule.type"
            class="core-prompt-rule"
            :class="{ 'is-active': activeCorePromptType === rule.key }"
            @click="activeCorePromptType = rule.key"
          >
            <b>{{ displayCorePromptType(rule.type) }}</b>
            <code>{{ displayCorePromptToken(rule.token) }}</code>
            <span>{{ displayCorePromptRule(rule.rule) }}</span>
          </div>
        </div>
      </div>
      <div class="core-prompt-panel__editor">
        <div class="core-prompt-editor-head">
          <label>{{ t('{type}补充提示词', { type: displayCorePromptType(activeCorePromptRule.type) }) }}</label>
          <div class="core-prompt-tabs">
            <button
              v-for="rule in CORE_VIEW_PROMPT_RULES"
              :key="`${rule.key}-tab`"
              type="button"
              :class="{ 'is-active': activeCorePromptType === rule.key }"
              @click="activeCorePromptType = rule.key"
            >
              {{ displayCorePromptType(rule.type) }}
            </button>
          </div>
        </div>
        <el-input
          v-model="batchCorePromptAdditions[activeCorePromptType]"
          type="textarea"
          :rows="4"
          maxlength="1000"
          show-word-limit
          resize="none"
          :placeholder="displayCorePromptPlaceholder(activeCorePromptRule.placeholder)"
          :disabled="assetWorkflowLocked"
          @blur="persistBatchCorePromptPreference"
        />
        <div class="ref-image-control core-ref">
          <div class="ref-image-thumb" v-if="batchCoreReferenceImageUrl" @click="openImagePreview(batchCoreReferenceImageUrl)">
            <img :src="batchCoreReferenceImageUrl" />
          </div>
          <div class="ref-image-input-wrap">
            <div class="row">
              <el-input
                v-model="batchCoreReferenceImageUrl"
                :placeholder="t('批量参考图 URL，可选')"
                size="small"
                clearable
                :disabled="assetWorkflowLocked"
                @blur="persistBatchCorePromptPreference"
              />
              <el-button size="small" :disabled="assetWorkflowLocked" @click="uploadImageToUrl((url) => { batchCoreReferenceImageUrl = url; persistBatchCorePromptPreference() })">
                <el-icon><Upload /></el-icon>
              </el-button>
            </div>
          </div>
        </div>
      </div>
    </section>

    <div class="assets-content">
      <PageSkeleton v-if="loading" variant="assets" />
      <div v-else-if="!selectedSeriesId" class="empty-tip">
        {{ t('请先选择一个剧本，随后展示该剧本资产列表。') }}
      </div>

      <template v-else>
        <section v-for="(meta, type) in TYPE_META" :key="type" class="asset-section">
          <div class="asset-section__header">
            <div>
              <h3>{{ displayAssetTypeLabel(type as AssetType) }}</h3>
              <p>{{ displayAssetTypeHint(type as AssetType) }}</p>
            </div>
            <div class="asset-section__actions">
              <BadgePill variant="dark">{{ t('{count} 个', { count: groupedAssets[type as AssetType].length }) }}</BadgePill>
              <button class="studio-btn" :disabled="assetWorkflowLocked" @click="openCreate(type as AssetType)">
                <el-icon><Plus /></el-icon>
                <span>{{ t('新增{type}', { type: displayAssetTypeLabel(type as AssetType) }) }}</span>
              </button>
            </div>
          </div>

          <div class="asset-grid">
            <div
              v-for="asset in groupedAssets[type as AssetType]"
              :key="asset.id"
              class="asset-card"
              :class="{ 'is-disabled': assetWorkflowLocked }"
              @click="openEdit(asset)"
            >
              <div class="asset-card__preview" v-if="firstImageUrl(asset)">
                <img v-lazy-src="firstImageUrl(asset)" :alt="asset.name" />
                <el-button
                  class="image-copy-btn asset-card__copy"
                  size="small"
                  circle
                  :title="t('复制图片链接')"
                  @click.stop="copyImageLink(firstImageUrl(asset))"
                >
                  <el-icon><DocumentCopy /></el-icon>
                </el-button>
                <div class="asset-card__badge">
                  <el-icon><Picture /></el-icon>
                  <span>{{ asset.images.length }}</span>
                </div>
              </div>
              <div v-else class="asset-card__preview empty">
                <div class="asset-monogram" :style="generatedCover(asset.name).style">
                  <span>{{ generatedCover(asset.name).text }}</span>
                </div>
                <div class="asset-empty-action" @click.stop>
                  <el-button
                    v-if="hasQueuedCoreImageJob(asset)"
                    size="small"
                    round
                    type="warning"
                    plain
                    :loading="cancellingAssetIds[asset.id]"
                    :disabled="assetWorkflowLocked"
                    @click="handleCancelAssetQueuedJobs(asset)"
                  >
                    {{ t('取消排队') }}
                  </el-button>
                  <el-button
                    v-else-if="hasPendingCoreImageJob(asset)"
                    size="small"
                    round
                    loading
                    disabled
                  >
                    {{ t('生成中') }}
                  </el-button>
                  <el-button
                    v-else
                    type="primary"
                    size="small"
                    round
                    :loading="quickGeneratingIds[asset.id]"
                    :disabled="assetWorkflowLocked || !selectedImageModelId"
                    @click="quickGenerateCore(asset)"
                  >
                    <el-icon><MagicStick /></el-icon>
                    <span>{{ t('生成核心视图') }}</span>
                  </el-button>
                </div>
              </div>

              <div class="asset-card__content">
                <div class="asset-card__header">
                  <h4>{{ asset.name }}</h4>
                  <div class="asset-card__actions" @click.stop>
                    <el-button
                      link
                      :title="t('从其他作品复用')"
                      :disabled="assetWorkflowLocked"
                      @click="openReuseDialog"
                    >
                      <el-icon><CopyDocument /></el-icon>
                    </el-button>
                    <el-button link :title="t('分享')" @click="openShareDialog(asset)">
                      <el-icon><Share /></el-icon>
                    </el-button>
                    <el-button link :disabled="assetWorkflowLocked" @click="openEdit(asset)">
                      <el-icon><Edit /></el-icon>
                    </el-button>
                    <el-button link type="danger" :disabled="assetWorkflowLocked" @click="handleDelete(asset)">
                      <el-icon><Delete /></el-icon>
                    </el-button>
                  </div>
                </div>
                
                <p class="asset-card__desc">{{ asset.description || t('暂无资产描述...') }}</p>
                
                <div class="asset-card__tags" v-if="asset.tags && asset.tags.length > 0">
                  <span v-for="tag in asset.tags.slice(0, 3)" :key="tag" class="asset-tag">{{ tag }}</span>
                  <span v-if="asset.tags.length > 3" class="asset-tag more">+{{ asset.tags.length - 3 }}</span>
                </div>

                <div class="asset-card__footer">
                  <div class="asset-card__views-mini">
                    <div 
                      v-for="(img, idx) in asset.images.slice(0, 5)" 
                      :key="idx" 
                      class="view-pill"
                      :class="img.view_type"
                    >
                    {{ displayViewTypeLabel(img.view_type).charAt(0) || t('图') }}
                    </div>
                    <span v-if="asset.images.length > 5" class="view-more">+{{ asset.images.length - 5 }}</span>
                  </div>
                  <span v-if="asset.type === 'character' && (asset.look_count || 0) > 0" class="asset-look-count">
                    {{ t('{count} 套造型', { count: asset.look_count }) }}
                  </span>
                </div>
              </div>
            </div>
          </div>
        </section>
      </template>
    </div>
    </div>
    <div v-else class="assets-tab-panel assets-tab-panel--shared">
      <SharedAssetsPanel />
    </div>

    <AssetShareDialog v-model="shareDialogVisible" :target="shareTarget" />
    <AssetReuseDialog
      v-if="selectedSeriesId"
      v-model="reuseDialogVisible"
      :target-series-id="selectedSeriesId"
      :target-series-title="selectedSeries?.title || ''"
      @copied="handleAssetReused"
    />

    <AssetEditorDialog
      v-model="dialogVisible"
      :asset="editorAsset"
      :series-list="seriesList"
      :initial-series-id="selectedSeriesId"
      :initial-type="editorInitialType"
      :locked="assetWorkflowLocked"
      :locked-message="assetWorkflowLockedMessage"
      @saved="handleEditorSaved"
    />

    <el-dialog 
      v-if="false"
      v-model="dialogVisible" 
      :title="editingId ? '编辑资产' : '新建资产'" 
      width="min(96vw, 1160px)" 
      append-to-body 
      destroy-on-close
      class="asset-dialog studio-dialog asset-editor-dialog"
      top="5vh"
    >
      <div class="dialog-scroll-container">
        <el-form label-position="top" class="studio-form">
          <div class="form-section">
            <div class="section-label">
              <el-icon><InfoFilled /></el-icon>
              <span>基本信息</span>
            </div>
            <div class="form-grid">
              <el-form-item label="所属剧本" required>
                <el-select v-model="form.series_id" style="width: 100%" :disabled="assetWorkflowLocked">
                  <el-option v-for="s in seriesList" :key="s.id" :value="s.id" :label="s.title" />
                </el-select>
              </el-form-item>
              <el-form-item label="资产类型" required>
                <el-select v-model="form.type" style="width: 100%" :disabled="assetWorkflowLocked">
                  <el-option label="人物" value="character" />
                  <el-option label="场景" value="scene" />
                  <el-option label="物品" value="prop" />
                </el-select>
              </el-form-item>
            </div>

            <div class="form-grid">
              <el-form-item label="资产名称" required>
                <el-input v-model="form.name" placeholder="例如：男主-林川" :disabled="assetWorkflowLocked" />
              </el-form-item>
              <el-form-item label="标签（逗号分隔）">
                <el-input v-model="form.tagsInput" placeholder="主角, 青年, 现代装" :disabled="assetWorkflowLocked" />
              </el-form-item>
            </div>

            <el-form-item label="资产描述">
              <el-input v-model="form.description" type="textarea" :rows="2" placeholder="描述这个资产的设定 and 使用场景" :disabled="assetWorkflowLocked" />
            </el-form-item>

            <el-form-item label="本次 AI 画风参考图">
              <div class="ref-image-control">
                <div class="ref-image-thumb" v-if="form.referenceImageUrl" @click="openImagePreview(form.referenceImageUrl)">
                  <img :src="form.referenceImageUrl" />
                </div>
                <div class="ref-image-input-wrap">
                  <div class="row">
                    <el-input v-model="form.referenceImageUrl" placeholder="可选：画风参考图 URL。优先参考美术风格，不照搬主体" :disabled="assetWorkflowLocked" clearable />
                    <el-button :disabled="assetWorkflowLocked" @click="uploadImageToUrl((url) => { form.referenceImageUrl = url })">
                      <el-icon><Upload /></el-icon> 上传
                    </el-button>
                  </div>
                </div>
              </div>
            </el-form-item>
          </div>

          <div class="form-section">
            <div class="section-label">
              <el-icon><Picture /></el-icon>
              <span>核心视图</span>
            </div>
            <div class="fixed-views-grid single-core">
              <div 
                v-for="(img, idx) in form.images.slice(0, 1)" 
                :key="`fixed-${idx}`" 
                class="fixed-view-card"
                :class="{ 'has-url': img.url }"
              >
                <div class="fixed-view-card__preview" @click="img.url ? openImagePreview(img.url) : handleFileUpload(img)">
                  <img v-if="img.url" :src="img.url" />
                  <div v-else class="upload-placeholder">
                    <el-icon><Upload /></el-icon>
                    <span>上传核心视图</span>
                  </div>
                  <el-button
                    v-if="img.url"
                    class="image-copy-btn preview-copy-btn"
                    size="small"
                    circle
                    title="复制图片链接"
                    @click.stop="copyImageLink(img.url)"
                  >
                    <el-icon><DocumentCopy /></el-icon>
                  </el-button>
                  <el-button
                    v-if="img.url"
                    class="preview-upload-btn"
                    size="small"
                    circle
                    :disabled="assetWorkflowLocked"
                    @click.stop="handleFileUpload(img)"
                  >
                    <el-icon><Upload /></el-icon>
                  </el-button>
                  <div class="preview-overlay">
                    <el-icon><Picture /></el-icon>
                    <span>{{ img.url ? '点击预览' : '点击上传' }}</span>
                  </div>
                </div>
                <div class="fixed-view-card__input">
                  <div class="view-type-header">
                    <div class="view-type-tag">核心视图</div>
                    <div class="view-actions">
                      <el-button 
                        type="primary" 
                        size="small" 
                        :loading="generationLoading[`${img.view_type}-${idx}`]"
                        :disabled="assetWorkflowLocked"
                        @click.stop="handleGenerate(img, idx)"
                        class="ai-gen-btn"
                      >
                        <el-icon><MagicStick /></el-icon>
                        <span>{{ generationStatus[`${img.view_type}-${idx}`] || 'AI 生成' }}</span>
                      </el-button>
                      <el-button
                        type="primary"
                        link
                        :disabled="assetWorkflowLocked"
                        @click.stop="replaceCoreImageRow"
                        class="delete-btn"
                      >
                        <el-icon><Upload /></el-icon>
                        <span>重新上传</span>
                      </el-button>
                    </div>
                  </div>
                  <el-input v-model="img.url" placeholder="输入图片 URL" size="small" :disabled="assetWorkflowLocked" />
                  <div class="ref-image-control mini">
                    <div class="ref-image-thumb" v-if="img.reference_image_url" @click="openImagePreview(img.reference_image_url)">
                      <img :src="img.reference_image_url" />
                    </div>
                    <div class="ref-image-input-wrap">
                      <div class="row">
                        <el-input v-model="img.reference_image_url" placeholder="画风参考图 URL，可选；为空则用上方参考图" size="small" :disabled="assetWorkflowLocked" clearable />
                        <el-button size="small" :disabled="assetWorkflowLocked" @click="uploadImageToUrl((url) => { img.reference_image_url = url })">
                          <el-icon><Upload /></el-icon>
                        </el-button>
                      </div>
                    </div>
                  </div>
                  <el-input
                    v-model="img.image_prompt"
                    type="textarea"
                    :rows="3"
                    :placeholder="coreViewPromptPlaceholder(img)"
                    class="prompt-input view-prompt-input"
                    :disabled="assetWorkflowLocked"
                  />
                </div>
              </div>
              <div v-if="form.images[0]" class="asset-version-panel core-version-library">
                <div class="asset-version-panel__label">核心视图版本库</div>
                <div v-if="assetImageVersionsFor(form.images[0]).length" class="asset-version-strip">
                  <button
                    v-for="(version, versionIdx) in assetImageVersionsFor(form.images[0])"
                    :key="version.id"
                    class="asset-version-thumb"
                    :class="{ 'is-selected': version.is_selected }"
                    type="button"
                    @click="openImagePreview(version.url)"
                  >
                    <img :src="version.url" alt="" />
                    <span
                      class="image-copy-btn asset-version-copy"
                      role="button"
                      tabindex="0"
                      title="复制图片链接"
                      @click.stop="copyImageLink(version.url)"
                      @keydown.enter.stop.prevent="copyImageLink(version.url)"
                      @keydown.space.stop.prevent="copyImageLink(version.url)"
                    >
                      <el-icon><DocumentCopy /></el-icon>
                    </span>
                    <div class="asset-version-actions">
                      <span v-if="version.is_selected">{{ assetImageVersionLabel(version, versionIdx) }}</span>
                      <template v-if="!version.is_selected">
                        <em
                          class="asset-version-select"
                          @click.stop="selectAssetVersion(form.images[0], version)"
                        >
                          {{ selectingAssetImageVersionIds[version.id] ? '选用中' : '选用' }}
                        </em>
                        <i
                          class="asset-version-delete"
                          @click.stop="deleteAssetVersion(form.images[0], version)"
                        >
                          {{ deletingAssetImageVersionIds[version.id] ? '删除中' : '删除' }}
                        </i>
                      </template>
                    </div>
                  </button>
                </div>
                <div v-else class="asset-version-empty">暂无版本</div>
              </div>
            </div>
          </div>

          <div class="form-section">
            <div class="section-label">
              <el-icon><Picture /></el-icon>
              <span>更多视图</span>
              <el-button type="primary" size="small" :disabled="assetWorkflowLocked" @click="addImageRow" class="add-view-btn">
                <el-icon><Plus /></el-icon>
                <span>添加视图</span>
              </el-button>
            </div>

            <div class="view-list">
              <div v-for="(img, idx) in form.images.slice(1)" :key="`extra-${idx}`" class="view-row-card">
                <div class="view-row-card__preview" @click="img.url ? openImagePreview(img.url) : null">
                  <img v-if="img.url" :src="img.url" loading="lazy" />
                  <div v-else class="empty-preview">
                    <el-icon><Picture /></el-icon>
                    <span>未上传视图</span>
                  </div>
                  <el-button
                    v-if="img.url"
                    class="image-copy-btn preview-copy-btn"
                    size="small"
                    circle
                    title="复制图片链接"
                    @click.stop="copyImageLink(img.url)"
                  >
                    <el-icon><DocumentCopy /></el-icon>
                  </el-button>
                  <el-button
                    class="preview-upload-btn"
                    size="small"
                    circle
                    :disabled="assetWorkflowLocked"
                    @click.stop="handleFileUpload(img)"
                  >
                    <el-icon><Upload /></el-icon>
                  </el-button>
                  <div v-if="img.url" class="preview-overlay">
                    <el-icon><Picture /></el-icon>
                  </div>
                </div>
                
                <div class="view-row-card__content">
                  <div class="view-type-row">
                    <el-select v-model="img.view_type" size="small" :disabled="assetWorkflowLocked">
                      <el-option v-for="v in EXTRA_VIEW_TYPES" :key="v.value" :label="v.label" :value="v.value" />
                    </el-select>
                    <div class="view-actions">
                      <el-button 
                        type="primary" 
                        size="small" 
                        :loading="generationLoading[`${img.view_type}-${idx + 1}`]"
                        :disabled="assetWorkflowLocked"
                        @click="handleGenerate(img, idx + 1)"
                        class="ai-gen-btn"
                      >
                        <el-icon><MagicStick /></el-icon>
                        <span>{{ generationStatus[`${img.view_type}-${idx + 1}`] || 'AI 生成' }}</span>
                      </el-button>
                      <el-button 
                        type="danger" 
                        link
                        :disabled="assetWorkflowLocked"
                        @click="removeImageRow(idx + 1)"
                        class="delete-btn"
                      >
                        <el-icon><Delete /></el-icon>
                      </el-button>
                    </div>
                  </div>
                  
                  <div v-if="assetImageVersionsFor(img).length" class="asset-version-panel compact">
                    <div class="asset-version-panel__label">版本对比</div>
                    <div class="asset-version-strip">
                      <button
                        v-for="(version, versionIdx) in assetImageVersionsFor(img)"
                        :key="version.id"
                        class="asset-version-thumb"
                        :class="{ 'is-selected': version.is_selected }"
                        type="button"
                        @click="openImagePreview(version.url)"
                      >
                        <img :src="version.url" alt="" />
                        <span
                          class="image-copy-btn asset-version-copy"
                          role="button"
                          tabindex="0"
                          title="复制图片链接"
                          @click.stop="copyImageLink(version.url)"
                          @keydown.enter.stop.prevent="copyImageLink(version.url)"
                          @keydown.space.stop.prevent="copyImageLink(version.url)"
                        >
                          <el-icon><DocumentCopy /></el-icon>
                        </span>
                        <div class="asset-version-actions">
                          <span v-if="version.is_selected">{{ assetImageVersionLabel(version, versionIdx) }}</span>
                          <template v-if="!version.is_selected">
                            <em
                              class="asset-version-select"
                              @click.stop="selectAssetVersion(img, version)"
                            >
                              {{ selectingAssetImageVersionIds[version.id] ? '选用中' : '选用' }}
                            </em>
                            <i
                              class="asset-version-delete"
                              @click.stop="deleteAssetVersion(img, version)"
                            >
                              {{ deletingAssetImageVersionIds[version.id] ? '删除中' : '删除' }}
                            </i>
                          </template>
                        </div>
                      </button>
                    </div>
                  </div>
                  <el-input v-model="img.url" placeholder="图片 URL" size="small" :disabled="assetWorkflowLocked" />
                  <div class="ref-image-control mini">
                    <div class="ref-image-thumb" v-if="img.reference_image_url" @click="openImagePreview(img.reference_image_url)">
                      <img :src="img.reference_image_url" />
                    </div>
                    <div class="ref-image-input-wrap">
                      <div class="row">
                        <el-input v-model="img.reference_image_url" placeholder="画风参考图 URL，可选；为空则用主参考图" size="small" :disabled="assetWorkflowLocked" clearable />
                        <el-button size="small" :disabled="assetWorkflowLocked" @click="uploadImageToUrl((url) => { img.reference_image_url = url })">
                          <el-icon><Upload /></el-icon>
                        </el-button>
                      </div>
                    </div>
                  </div>
                  <el-input
                    v-model="img.image_prompt"
                    type="textarea"
                    :rows="2"
                    :placeholder="defaultImagePrompt(img)"
                    class="prompt-input view-prompt-input"
                    :disabled="assetWorkflowLocked"
                  />
                </div>
              </div>
              <div v-if="form.images.length <= 1" class="empty-extra-views">
                暂无更多视图，点击上方按钮添加。
              </div>
            </div>
          </div>
        </el-form>
      </div>
      <template #footer>
        <div class="dialog-footer">
          <el-button @click="dialogVisible = false" plain>取消</el-button>
          <el-button type="primary" :loading="saving" :disabled="assetWorkflowLocked" @click="submitAsset" class="submit-btn">
            保存资产
          </el-button>
        </div>
      </template>
    </el-dialog>

    <el-dialog
      v-model="imagePreviewVisible"
      width="min(92vw, 980px)"
      append-to-body
      class="asset-image-preview-dialog"
    >
      <div class="asset-image-preview">
        <el-button
          v-if="imagePreviewUrl"
          class="asset-image-preview__copy"
          size="small"
          :title="t('复制图片链接')"
          @click="copyImageLink(imagePreviewUrl)"
        >
          <el-icon><DocumentCopy /></el-icon>
          <span>{{ t('复制图片链接') }}</span>
        </el-button>
        <img :src="imagePreviewUrl" :alt="t('资产大图预览')" />
      </div>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.assets-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: var(--canvas);
}

.page-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px 24px;
  border-bottom: 1px solid var(--hairline);
  background: var(--surface-card);
  flex-shrink: 0;

  h1 {
    margin: 0;
    font-family: var(--font-sans);
    font-size: 20px;
    font-weight: 700;
    letter-spacing: 0;
    color: var(--on-dark);
  }

  p {
    margin: 4px 0 0;
    color: var(--muted);
    font-size: 12px;
    letter-spacing: 0.2px;
  }
}

.top-actions {
  display: flex;
  align-items: center;
  gap: 16px;

  :deep(.el-button) {
    background: var(--surface-card) !important;
    border: 1px solid var(--hairline) !important;
    color: var(--body-strong) !important;
    height: 36px;
    border-radius: var(--radius-md);
    font-weight: 600;
    transition: all var(--duration-fast) var(--ease-out);

    &:hover:not(:disabled) {
      border-color: var(--primary) !important;
      color: var(--primary) !important;
      box-shadow: 0 0 10px rgba(var(--primary-rgb), 0.15);
    }

    small {
      min-width: 18px;
      height: 18px;
      padding: 0 6px;
      border-radius: var(--radius-pill);
      display: inline-flex;
      align-items: center;
      justify-content: center;
      background: var(--primary);
      color: var(--on-primary);
      font-size: 10px;
      font-weight: 800;
      margin-left: 6px;
    }
  }
}

.image-model-picker {
  display: inline-flex;
  align-items: center;
  gap: 10px;
  padding: 0 12px;
  height: 36px;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-card);

  span {
    color: var(--muted);
    font-size: 11px;
    font-weight: 700;
    white-space: nowrap;
    text-transform: uppercase;
    letter-spacing: 1px;
  }

  :deep(.el-select) {
    .el-select__wrapper {
      background: transparent !important;
      border: none !important;
      box-shadow: none !important;
      padding: 0 !important;
      height: auto !important;
      min-height: auto !important;
    }
    .el-select__placeholder {
      color: var(--body-strong);
      font-size: 12px;
      font-weight: 600;
    }
  }
}

.model-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 4px 0;

  span {
    font-weight: 600;
    font-size: 13px;
  }

  small {
    color: var(--muted);
    font-family: var(--font-mono);
    font-size: 10px;
  }
}

/* ── Studio Button ─────────────────────────────────────────────────────────── */
.studio-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  background: rgba(var(--brand-cyan-rgb), 0.08);
  border: 1px solid rgba(var(--brand-cyan-rgb), 0.22);
  border-radius: var(--radius-md);
  color: var(--primary);
  font-family: var(--font-sans);
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.5px;
  cursor: pointer;
  backdrop-filter: blur(8px);
  transition: all var(--duration-normal) var(--ease-out);

  .el-icon {
    font-size: 14px;
    transition: transform var(--duration-fast) ease;
  }

  &:hover:not(:disabled) {
    background: var(--primary);
    border-color: var(--primary);
    color: var(--on-primary);
    box-shadow: none;

    .el-icon {
      transform: scale(1.1);
    }
  }

  &:active:not(:disabled) {
    transform: scale(0.97);
  }

  &:disabled {
    opacity: 0.25;
    cursor: not-allowed;
  }
}

.assets-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  height: 56px;
  padding: 0 24px;
  background: var(--canvas);
  border-bottom: 1px solid var(--hairline);
  position: sticky;
  top: 0;
  z-index: 10;

  :deep(.el-select) {
    .el-select__wrapper {
      background: var(--surface-card) !important;
      border: 1px solid var(--hairline) !important;
      box-shadow: 0 0 0 1px var(--hairline) inset !important;
      border-radius: var(--radius-md);
      color: var(--body-strong) !important;
      transition: all var(--duration-fast) var(--ease-out);

      &:hover {
        border-color: var(--hairline-strong) !important;
      }
      &.is-focus {
        border-color: var(--accent-cyan) !important;
        box-shadow: 0 0 0 2px rgba(var(--brand-cyan-rgb), 0.16) !important;
      }
    }

    .el-select__placeholder,
    .el-select__selected-item {
      color: var(--body-strong) !important;
    }
  }

  :deep(.el-input) {
    .el-input__wrapper {
      background: var(--surface-card) !important;
      border: 1px solid var(--hairline) !important;
      box-shadow: 0 0 0 1px var(--hairline) inset !important;
      border-radius: var(--radius-md);
      color: var(--body-strong) !important;
      transition: all var(--duration-fast) var(--ease-out);

      &:hover {
        border-color: var(--hairline-strong) !important;
      }
      &.is-focus {
        border-color: var(--accent-cyan) !important;
        box-shadow: 0 0 0 2px rgba(var(--brand-cyan-rgb), 0.16) !important;
      }
    }

    .el-input__inner,
    .el-input__prefix,
    .el-input__suffix {
      color: var(--body-strong) !important;
    }

    .el-input__inner::placeholder {
      color: var(--muted-soft) !important;
    }
  }
}

.asset-workflow-lock {
  display: flex;
  align-items: center;
  gap: 8px;
  min-height: 40px;
  padding: 0 24px;
  border-bottom: 1px solid rgba(245, 158, 11, 0.2);
  background: rgba(245, 158, 11, 0.04);
  color: #f59e0b;
  font-size: 12px;
  font-weight: 600;
  letter-spacing: 0.2px;

  .el-icon {
    font-size: 14px;
    flex-shrink: 0;
  }
}

.assets-scope-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  min-height: 68px;
  padding: 12px 24px;
  border-top: 1px solid var(--hairline);
  border-bottom: 1px solid var(--hairline);
  background:
    linear-gradient(180deg, rgba(var(--brand-cyan-rgb), 0.045), rgba(255, 255, 255, 0)),
    var(--surface-card);
}

.assets-scope-segment {
  display: inline-grid;
  grid-template-columns: repeat(2, minmax(168px, 1fr));
  gap: 4px;
  padding: 4px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-soft);
  box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.05);

  button {
    min-height: 44px;
    padding: 8px 14px;
    border: 1px solid transparent;
    border-radius: 8px;
    background: transparent;
    color: var(--muted);
    cursor: pointer;
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    justify-content: center;
    gap: 2px;
    text-align: left;
    transition:
      background var(--duration-fast) var(--ease-out),
      border-color var(--duration-fast) var(--ease-out),
      color var(--duration-fast) var(--ease-out),
      box-shadow var(--duration-fast) var(--ease-out);

    span {
      color: inherit;
      font-size: 13px;
      font-weight: 900;
      line-height: 1.15;
    }

    small {
      max-width: 220px;
      color: var(--muted-soft);
      font-size: 11px;
      font-weight: 650;
      line-height: 1.2;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    &:hover {
      color: var(--body-strong);
      background: rgba(148, 163, 184, 0.12);
    }

    &.is-active {
      color: var(--on-dark);
      border-color: rgba(var(--brand-cyan-rgb), 0.3);
      background: var(--surface-card);
      box-shadow: 0 8px 20px rgba(16, 32, 51, 0.08);

      &::before {
        content: '';
        width: 18px;
        height: 2px;
        border-radius: 999px;
        background: var(--accent-cyan);
        margin-bottom: 2px;
      }
    }
  }
}

.assets-tab-panel {
  min-width: 0;
}

.assets-tab-panel--shared {
  min-height: calc(100vh - 180px);
}

.assets-toolbar__left {
  display: flex;
  align-items: center;
  gap: 14px;
  min-width: 0;

  :deep(.el-divider--vertical) {
    border-color: var(--hairline);
    margin: 0 4px;
  }
}

.share-work-btn {
  height: 32px;
  padding: 0 12px !important;
  border: 1px solid rgba(var(--brand-cyan-rgb), 0.26) !important;
  border-radius: 8px !important;
  background: rgba(var(--brand-cyan-rgb), 0.07) !important;
  color: var(--accent-cyan) !important;
  font-size: 12px;
  font-weight: 850;
  box-shadow: none !important;

  .el-icon {
    margin-right: 5px;
    font-size: 14px;
  }

  &:hover {
    border-color: rgba(var(--brand-cyan-rgb), 0.42) !important;
    background: rgba(var(--brand-cyan-rgb), 0.11) !important;
    color: var(--accent-cyan) !important;
  }
}

.core-prompt-panel {
  display: grid;
  grid-template-columns: minmax(0, 1.08fr) minmax(360px, 0.92fr);
  gap: 16px;
  padding: 14px 24px;
  border-bottom: 1px solid var(--hairline);
  background: linear-gradient(180deg, rgba(255, 255, 255, 0.018), rgba(255, 255, 255, 0.006));
}

.core-prompt-panel__main,
.core-prompt-panel__editor {
  min-width: 0;
}

.core-prompt-panel__title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  color: var(--body-strong);
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.5px;
  margin-bottom: 8px;

  .el-icon {
    color: var(--primary);
  }
}

.core-prompt-rules {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.core-prompt-rule {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-height: 112px;
  padding: 10px;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-soft);
  color: var(--muted);
  font-size: 12px;
  line-height: 1.45;
  cursor: pointer;
  transition: border-color var(--duration-fast) var(--ease-out), background var(--duration-fast) var(--ease-out);

  &:hover,
  &.is-active {
    border-color: rgba(var(--primary-rgb), 0.38);
    background: rgba(var(--primary-rgb), 0.035);
  }

  b {
    color: var(--primary);
    font-size: 12px;
  }

  code {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-height: 22px;
    width: fit-content;
    max-width: 100%;
    padding: 0 8px;
    border: 1px solid var(--hairline);
    border-radius: var(--radius-sm);
    background: var(--surface-soft);
    color: var(--body-strong);
    font-family: var(--font-mono);
    font-size: 11px;
  }

  span {
    min-width: 0;
    display: -webkit-box;
    overflow: hidden;
    -webkit-line-clamp: 3;
    -webkit-box-orient: vertical;
  }
}

.core-prompt-panel__editor {
  :deep(.el-textarea__inner) {
    min-height: 112px;
    background: var(--surface-card) !important;
    border: 1px solid var(--hairline) !important;
    border-radius: var(--radius-md) !important;
    color: var(--on-dark) !important;
    font-family: var(--font-sans);
    font-size: 12px;
    line-height: 1.55;
    box-shadow: none !important;
  }

  :deep(.el-input__count) {
    background: transparent;
    color: var(--muted);
  }
}

.core-prompt-editor-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 8px;

  label {
    color: var(--body-strong);
    font-size: 12px;
    font-weight: 800;
    letter-spacing: 0.5px;
  }
}

.core-prompt-tabs {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-soft);

  button {
    min-width: 44px;
    height: 24px;
    border: 0;
    border-radius: var(--radius-sm);
    background: transparent;
    color: var(--muted);
    font-size: 12px;
    font-weight: 700;
    cursor: pointer;

    &.is-active {
      background: var(--primary);
      color: var(--on-primary);
    }
  }
}

.current-series {
  color: var(--primary);
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0.5px;
  padding: 4px 12px;
  background: rgba(var(--brand-cyan-rgb), 0.08);
  border: 1px solid rgba(var(--brand-cyan-rgb), 0.22);
  border-radius: var(--radius-pill);
  max-width: 200px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.assets-content {
  flex: 1;
  overflow-y: auto;
  padding: 32px 24px var(--space-xxl);
  display: flex;
  flex-direction: column;
  gap: 40px;
  
  &::-webkit-scrollbar {
    width: 6px;
  }
  &::-webkit-scrollbar-thumb {
    background: var(--hairline-strong);
    border-radius: var(--radius-pill);
  }

  .empty-tip {
    padding: 80px 0;
    text-align: center;
    color: var(--muted);
    font-size: 14px;
    border: 1px dashed var(--hairline);
    border-radius: var(--radius-lg);
    background: rgba(255, 255, 255, 0.01);
  }
}

.asset-section {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.asset-section__header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-bottom: 14px;
  border-bottom: 1px solid var(--hairline);

  h3 {
    margin: 0;
    color: var(--on-dark);
    font-size: 13px;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;
    display: flex;
    align-items: center;
    gap: 8px;

    &::before {
      content: '';
      width: 3px;
      height: 12px;
      background: var(--primary);
      border-radius: 1px;
    }
  }

  p {
    margin: 4px 0 0;
    font-size: 11px;
    font-weight: 500;
    color: var(--muted);
    letter-spacing: 0.2px;
  }
}

.asset-section__actions {
  display: flex;
  gap: 12px;
  align-items: center;
}

.asset-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
  gap: 20px;
}

.asset-card {
  background: var(--surface-card);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-lg);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  cursor: pointer;
  transition: all var(--duration-normal) var(--ease-out);
  position: relative;

  &.is-disabled {
    cursor: not-allowed;
    opacity: 0.6;
  }

  &.is-disabled:hover {
    transform: none;
    border-color: var(--hairline);
    box-shadow: none;
  }

  &:hover {
    transform: translateY(-4px);
    border-color: var(--primary);
    background: var(--surface-elevated);
    box-shadow: 0 16px 36px rgba(16, 32, 51, 0.1);

    .asset-card__actions {
      opacity: 1;
      transform: translateX(0);
    }

    .asset-card__preview {
      border-color: rgba(var(--brand-cyan-rgb), 0.32);

      &::before {
        top: 6px;
        left: 6px;
        border-color: var(--primary);
      }
      &::after {
        bottom: 6px;
        right: 6px;
        border-color: var(--primary);
      }

      img {
        transform: scale(1.04);
      }
    }
  }
}

.asset-card__preview {
  height: 170px;
  margin: 10px 10px 0;
  border-radius: var(--radius-md);
  background: var(--surface-soft);
  border: 1px solid var(--hairline);
  overflow: hidden;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: border-color var(--duration-normal) var(--ease-out);

  // Studio Viewfinder corner marks
  &::before, &::after {
    content: '';
    position: absolute;
    width: 10px;
    height: 10px;
    border: 1px solid var(--muted-soft);
    pointer-events: none;
    z-index: 2;
    transition: all var(--duration-normal) var(--ease-out);
  }
  &::before {
    top: 8px;
    left: 8px;
    border-right: none;
    border-bottom: none;
  }
  &::after {
    bottom: 8px;
    right: 8px;
    border-left: none;
    border-top: none;
  }

  img {
    width: 100%;
    height: 100%;
    object-fit: contain;
    transition: transform var(--duration-normal) var(--ease-out);
  }

  &.empty {
    position: relative;
  }
}

.asset-monogram {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;

  span {
    font-size: 36px;
    font-weight: 800;
    letter-spacing: 2px;
    line-height: 1;
    user-select: none;
  }
}

.asset-empty-action {
  position: absolute;
  bottom: 10px;
  left: 0;
  right: 0;
  display: flex;
  justify-content: center;
  z-index: 3;
}

.asset-card__badge {
  position: absolute;
  top: 10px;
  right: 10px;
  background: rgba(18, 26, 43, 0.88);
  backdrop-filter: blur(8px);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-pill);
  padding: 3px 8px;
  display: flex;
  align-items: center;
  gap: 4px;
  color: var(--on-dark);
  font-family: var(--font-mono);
  font-size: 10px;
  font-weight: 700;
  z-index: 2;

  .el-icon {
    font-size: 11px;
    color: var(--primary);
  }
}

.image-copy-btn {
  --el-button-bg-color: var(--surface-card);
  --el-button-border-color: rgba(148, 163, 184, 0.38);
  --el-button-text-color: var(--body);
  --el-button-hover-bg-color: var(--surface-elevated);
  --el-button-hover-border-color: var(--primary);
  --el-button-hover-text-color: var(--primary);
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.12);
  backdrop-filter: blur(8px);
}

.asset-card__copy {
  position: absolute;
  top: 10px;
  left: 10px;
  z-index: 4;
}

.preview-copy-btn {
  position: absolute;
  top: 10px;
  right: 52px;
  z-index: 5;
}

.asset-version-copy {
  position: absolute;
  top: 5px;
  right: 5px;
  z-index: 4;
  width: 24px;
  height: 24px;
  min-height: 24px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid rgba(148, 163, 184, 0.38);
  border-radius: 50%;
  cursor: pointer;
}

.asset-card__content {
  padding: 16px;
  flex: 1;
  display: flex;
  flex-direction: column;
}

.asset-card__header {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 8px;

  h4 {
    margin: 0;
    color: var(--on-dark);
    font-size: 15px;
    font-weight: 700;
    letter-spacing: 0;
    line-height: 1.2;
  }
}

.asset-card__actions {
  display: flex;
  gap: 4px;
  opacity: 0;
  transform: translateX(6px);
  transition: all var(--duration-fast) var(--ease-out);

  :deep(.el-button) {
    padding: 4px !important;
    height: auto !important;
    background: transparent !important;
    border: none !important;
    color: var(--muted) !important;

    &:hover {
      color: var(--primary) !important;
    }

    &.el-button--danger:hover {
      color: var(--accent-rose) !important;
    }
    
    .el-icon {
      font-size: 15px;
    }
  }
}

.asset-card__desc {
  margin: 0 0 14px;
  font-size: 12px;
  line-height: 1.5;
  color: var(--body);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  height: 36px;
}

.asset-card__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 14px;
}

.asset-tag {
  font-family: var(--font-mono);
  font-size: 10px;
  font-weight: 600;
  color: var(--muted);
  background: var(--surface-soft);
  padding: 2px 6px;
  border-radius: var(--radius-xs);
  border: 1px solid var(--hairline);
  text-transform: uppercase;
  letter-spacing: 0.3px;

  &.more {
    background: transparent;
    border-style: dashed;
  }
}

.asset-card__footer {
  margin-top: auto;
  padding-top: 12px;
  border-top: 1px solid var(--hairline);
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.asset-card__views-mini {
  display: flex;
  align-items: center;
  gap: 4px;
}

.view-pill {
  width: 18px;
  height: 18px;
  border-radius: var(--radius-full);
  background: var(--surface-elevated);
  border: 1px solid var(--hairline);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 9px;
  font-weight: 800;
  color: var(--muted);
  transition: all var(--duration-fast) var(--ease-out);

  &:hover {
    border-color: var(--primary);
    color: var(--primary);
    transform: scale(1.1);
  }

  &.main { border-color: var(--accent-cyan); color: var(--accent-cyan); background: rgba(var(--brand-cyan-rgb), 0.1); }
  &.multi { border-color: #69fffa; color: #69fffa; background: rgba(105, 255, 250, 0.1); }
  &.front { border-color: rgba(var(--brand-cyan-rgb), 0.4); color: var(--accent-cyan); }
  &.side { border-color: rgba(105, 255, 250, 0.4); color: #69fffa; }
  &.back { border-color: rgba(255, 105, 180, 0.4); color: #ff69b4; }
}

.view-more {
  font-family: var(--font-mono);
  font-size: 9px;
  font-weight: 700;
  color: var(--muted-soft);
  margin-left: 2px;
}

.asset-look-count {
  padding: 3px 8px;
  border-radius: 999px;
  background: rgba(var(--brand-cyan-rgb), 0.1);
  color: var(--accent-cyan);
  font-size: 11px;
  font-weight: 900;
}

/* ── Studio Dialog ─────────────────────────────────────────────────────────── */
.studio-dialog {
  :deep(.el-dialog) {
    background: var(--surface-card) !important;
    background-image: none !important;
    border: 1px solid var(--hairline) !important;
    border-radius: var(--radius-md) !important;
    box-shadow: 0 24px 70px rgba(16, 32, 51, 0.18) !important;
    overflow: hidden;
    position: relative;

    &::before {
      content: '';
      position: absolute;
      top: 0;
      left: 0;
      right: 0;
      height: 1px;
      display: none;
    }
  }

  :deep(.el-dialog__header) {
    padding: 20px 24px 12px !important;
    margin: 0 !important;
    border-bottom: 1px solid var(--hairline);
  }

  :deep(.el-dialog__title) {
    color: var(--on-dark) !important;
    font-size: 16px !important;
    font-weight: 700 !important;
    letter-spacing: 0;
  }

  :deep(.el-dialog__headerbtn) {
    top: 18px !important;
    right: 20px !important;
    
    .el-dialog__close {
      color: var(--muted) !important;
      &:hover {
        color: var(--primary) !important;
      }
    }
  }

  :deep(.el-dialog__body) {
    padding: 0 !important;
    background: var(--surface-card) !important;
  }

  .dialog-scroll-container {
    padding: 24px;
    max-height: 70vh;
    overflow-y: auto;
    overflow-x: hidden;

    &::-webkit-scrollbar {
      width: 8px;
    }
    &::-webkit-scrollbar-track {
      background: var(--surface-soft);
    }
    &::-webkit-scrollbar-thumb {
      background: var(--hairline-strong);
      border-radius: 10px;
      border: 2px solid var(--surface-card);
      &:hover {
        background: #666;
      }
    }
  }

  :deep(.el-dialog__footer) {
    padding: 16px 24px !important;
    border-top: 1px solid var(--hairline);
    background: var(--surface-raised);
  }
}

.studio-form {
  display: grid;
  grid-template-columns: 360px 1fr;
  gap: 28px;
  align-items: start;

  /* Form Dual-Column Mapping */
  .form-section:nth-child(1) {
    grid-column: 1;
    border-top: none;
    padding-top: 0;
    margin-top: 0;

    .form-grid {
      grid-template-columns: 1fr;
      gap: 14px;
    }
  }

  .form-section:nth-child(2) {
    grid-column: 2;
    border-top: none;
    padding-top: 0;
    margin-top: 0;
  }

  .form-section:nth-child(3) {
    grid-column: span 2;
    border-top: 1px solid var(--hairline);
    margin-top: 12px;
    padding-top: 24px;
  }

  .form-section {
    display: flex;
    flex-direction: column;
  }

  .section-label {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-bottom: 16px;
    color: var(--body-strong);
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 1.5px;
    text-transform: uppercase;

    .el-icon {
      font-size: 13px;
      color: var(--primary);
    }

    .add-view-btn {
      margin-left: auto;
      background: var(--primary) !important;
      border: none !important;
      color: var(--on-primary) !important;
      font-weight: 700;
      height: 28px;
      padding: 0 12px;
      border-radius: var(--radius-sm);
      display: inline-flex;
      align-items: center;
      gap: 4px;

      span {
        display: inline-block !important;
        font-size: 11px;
      }
      
      &:hover {
        opacity: 0.9;
        box-shadow: none;
      }
    }
  }

  .ai-gen-btn {
    background: var(--primary) !important;
    border: none !important;
    color: var(--on-primary) !important;
    font-weight: 700;
    height: 24px;
    padding: 0 8px;
    border-radius: var(--radius-sm);
    display: inline-flex;
    align-items: center;
    gap: 4px;

    &:hover {
      opacity: 0.9;
      box-shadow: none;
    }

    span {
      display: inline-block !important;
      font-size: 11px;
    }
  }

  :deep(.el-form-item) {
    margin-bottom: 16px;
  }

  :deep(.el-form-item__label) {
    color: var(--muted) !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    margin-bottom: 6px !important;
  }

  :deep(.el-input__wrapper),
  :deep(.el-textarea__inner),
  :deep(.el-select__wrapper) {
    background: var(--surface-card) !important;
    box-shadow: none !important;
    border: 1px solid var(--hairline) !important;
    border-radius: var(--radius-md) !important;
    transition: all var(--duration-fast) var(--ease-out);

    &:hover {
      border-color: var(--hairline-strong) !important;
    }

    &.is-focus {
      border-color: var(--accent-cyan) !important;
      background: var(--surface-card) !important;
    }
  }

  :deep(.el-input__inner),
  :deep(.el-textarea__inner) {
    color: var(--on-dark) !important;
    font-family: var(--font-sans);
    font-size: 13px;

    &::placeholder {
      color: var(--muted-soft);
    }
  }

  .prompt-input {
    :deep(.el-textarea__inner) {
      font-family: var(--font-mono) !important;
      font-size: 12px;
      line-height: 1.6;
      padding: 10px 12px;
      background: var(--surface-card) !important;
    }
  }

  .view-prompt-input {
    :deep(.el-textarea__inner) {
      min-height: 68px;
      resize: vertical;
    }
  }
}

.fixed-views-grid {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.fixed-view-card {
  background: rgba(255, 255, 255, 0.01);
  border: 1px dashed var(--hairline);
  border-radius: var(--radius-lg);
  padding: 16px;
  display: grid;
  grid-template-columns: 200px 1fr;
  gap: 20px;
  transition: all var(--duration-normal) var(--ease-out);

  &:hover {
    border-color: rgba(var(--brand-cyan-rgb), 0.28);
    background: rgba(var(--brand-cyan-rgb), 0.04);
  }

  &.has-url {
    border-style: solid;
    border-color: var(--hairline-strong);
    background: rgba(255, 255, 255, 0.02);
  }

  &__preview {
    height: 180px;
    border-radius: var(--radius-md);
    overflow: hidden;
    background: var(--surface-soft);
    display: flex;
    align-items: center;
    justify-content: center;
    border: 1px solid var(--hairline);
    position: relative;
    cursor: pointer;

    // Viewfinder corners inside dialog viewport
    &::before, &::after {
      content: '';
      position: absolute;
      width: 10px;
      height: 10px;
      border: 1px solid rgba(255, 255, 255, 0.15);
      pointer-events: none;
      z-index: 2;
      transition: all var(--duration-normal) var(--ease-out);
    }
    &::before {
      top: 8px;
      left: 8px;
      border-right: none;
      border-bottom: none;
    }
    &::after {
      bottom: 8px;
      right: 8px;
      border-left: none;
      border-top: none;
    }

    &:hover {
      &::before { border-color: var(--primary); }
      &::after { border-color: var(--primary); }

      .preview-overlay {
        opacity: 1;
      }
    }

    .preview-overlay {
      position: absolute;
      inset: 0;
      background: rgba(7, 11, 20, 0.72);
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 6px;
      color: var(--primary);
      font-size: 11px;
      font-weight: 700;
      opacity: 0;
      transition: all var(--duration-fast) ease;
      backdrop-filter: blur(4px);
      pointer-events: none;
      z-index: 1;

      .el-icon {
        font-size: 20px;
      }
    }

    .preview-upload-btn {
      position: absolute;
      top: 8px;
      right: 8px;
      z-index: 3;
      background: rgba(18, 26, 43, 0.9);
      border-color: var(--hairline-strong);
      color: var(--muted);
      backdrop-filter: blur(8px);
      
      &:hover {
        color: var(--primary);
        border-color: var(--primary);
      }
    }

    img {
      width: 100%;
      height: 100%;
      object-fit: contain;
    }

    .upload-placeholder {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      color: var(--muted);

      .el-icon {
        font-size: 24px;
        opacity: 0.5;
        color: var(--muted);
      }

      span {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: 0.5px;
      }
    }
  }

  &__input {
    display: flex;
    flex-direction: column;
    gap: 8px;

    .view-type-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
    }

    .view-type-tag {
      font-family: var(--font-mono);
      font-size: 10px;
      font-weight: 800;
      color: var(--primary);
      text-transform: uppercase;
      letter-spacing: 1.5px;
    }

    :deep(.el-button--primary.is-link) {
      color: var(--primary) !important;
      font-weight: 700;
      font-size: 12px;
      display: inline-flex;
      align-items: center;
      gap: 4px;

      span {
        display: inline-block !important;
      }

      &:hover {
        opacity: 0.8;
      }
    }
  }
}

.asset-version-panel {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 8px;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-raised);

  &.compact {
    margin-top: -2px;
  }

  &__label {
    color: var(--muted);
    font-size: 11px;
    font-weight: 700;
  }
}

.core-version-library {
  min-height: 108px;
  margin-left: 8px;
  margin-right: 8px;
}

.asset-version-strip {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding-bottom: 2px;
}

.asset-version-thumb {
  position: relative;
  width: 108px;
  min-width: 108px;
  min-height: 124px;
  overflow: hidden;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  background: var(--surface-soft);
  padding: 0;
  cursor: pointer;
  color: var(--on-dark);

  img {
    width: 100%;
    height: 82px;
    object-fit: cover;
    display: block;
  }
}

.asset-version-actions {
  min-height: 42px;
  padding: 5px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  flex-wrap: nowrap;
  border-top: 1px solid var(--hairline);
  background: var(--surface-soft);

  span {
    padding: 2px 5px;
    border-radius: var(--radius-xs);
    background: rgba(255, 255, 255, 0.08);
    color: var(--on-dark);
    font-size: 10px;
    font-weight: 700;
    line-height: 1.2;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }

  em,
  i {
    flex: 1;
    padding: 4px 6px;
    border-radius: var(--radius-xs);
    font-style: normal;
    font-size: 10px;
    font-weight: 800;
    line-height: 1.2;
    text-align: center;
    white-space: nowrap;
  }

  .asset-version-select {
    background: var(--primary);
    color: var(--on-primary);
  }

  .asset-version-delete {
    background: var(--accent-rose);
    color: #fff;
  }
}

.asset-version-thumb.is-selected {
  border-color: var(--accent-cyan);
  box-shadow: 0 0 0 1px rgba(var(--brand-cyan-rgb), 0.28);
}

.asset-version-empty {
  min-height: 68px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px dashed var(--hairline);
  border-radius: var(--radius-sm);
  color: var(--muted-soft);
  font-size: 12px;
}

.empty-extra-views {
  padding: 40px;
  text-align: center;
  color: var(--muted-soft);
  font-size: 12px;
  border: 1px dashed var(--hairline);
  border-radius: var(--radius-lg);
  background: rgba(255, 255, 255, 0.005);
  grid-column: 1 / -1;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 12px;

  &::before {
    content: '\e711'; /* Picture icon in element-plus */
    font-family: 'element-icons';
    font-size: 32px;
    opacity: 0.2;
  }
}

.view-list {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  gap: 20px;
}

.view-row-card {
  background: var(--surface-raised);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition: all var(--duration-normal) var(--ease-out);
  position: relative;

  &:hover {
    border-color: rgba(var(--brand-cyan-rgb), 0.35);
    background: var(--surface-card);
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(16, 32, 51, 0.08);

    .view-row-card__preview {
      border-color: rgba(var(--brand-cyan-rgb), 0.28);
      &::before, &::after {
        border-color: var(--primary);
      }
    }
  }

  &__preview {
    height: 160px;
    margin: 10px;
    background: var(--surface-soft);
    border: 1px solid var(--hairline);
    border-radius: var(--radius-md);
    position: relative;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
    transition: all var(--duration-normal) var(--ease-out);

    .empty-preview {
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      color: var(--muted-soft);

      .el-icon {
        font-size: 24px;
        opacity: 0.4;
      }
      
      span {
        font-size: 10px;
        font-weight: 700;
        letter-spacing: 1px;
        text-transform: uppercase;
      }
    }

    // Viewfinder corners
    &::before, &::after {
      content: '';
      position: absolute;
      width: 8px;
      height: 8px;
      border: 1px solid rgba(255, 255, 255, 0.15);
      pointer-events: none;
      z-index: 2;
      transition: all var(--duration-normal) var(--ease-out);
    }
    &::before {
      top: 8px;
      left: 8px;
      border-right: none;
      border-bottom: none;
    }
    &::after {
      bottom: 8px;
      right: 8px;
      border-left: none;
      border-top: none;
    }

    &:hover .preview-overlay {
      opacity: 1;
    }

    .preview-overlay {
      position: absolute;
      inset: 0;
      background: rgba(7, 11, 20, 0.72);
      display: flex;
      align-items: center;
      justify-content: center;
      color: var(--primary);
      opacity: 0;
      transition: all var(--duration-fast) ease;
      backdrop-filter: blur(4px);
      pointer-events: none;
      z-index: 1;

      .el-icon {
        font-size: 20px;
      }
    }

    .preview-upload-btn {
      position: absolute;
      top: 8px;
      right: 8px;
      z-index: 3;
      width: 24px;
      height: 24px;
      padding: 0;
      background: rgba(18, 26, 43, 0.9);
      border: 1px solid var(--hairline-strong);
      color: var(--muted);
      backdrop-filter: blur(4px);
      
      &:hover {
        color: var(--primary);
        border-color: var(--primary);
        background: var(--surface-card);
      }
    }

    img {
      width: 100%;
      height: 100%;
      object-fit: contain;
      transition: transform var(--duration-normal) var(--ease-out);
    }
  }

  &__content {
    padding: 0 12px 12px;
    display: flex;
    flex-direction: column;
    gap: 10px;

    .view-type-row {
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 8px;

      :deep(.el-select) {
        flex: 1;
        .el-select__wrapper {
          background: var(--surface-card) !important;
          border-color: var(--hairline) !important;
        }
      }

      .view-actions {
        display: flex;
        align-items: center;
        gap: 8px;
        flex-shrink: 0;
      }
    }

    :deep(.el-input) {
      .el-input__wrapper {
        background: var(--surface-card) !important;
        border-color: var(--hairline) !important;
      }
    }

    .prompt-input {
      :deep(.el-textarea__inner) {
        background: var(--surface-card) !important;
        font-family: var(--font-mono) !important;
        font-size: 11px;
        line-height: 1.4;
        padding: 8px;
        border-color: var(--hairline) !important;

        &:focus {
          border-color: var(--primary) !important;
          background: var(--surface-card) !important;
        }
      }
    }
  }

  .delete-btn {
    padding: 4px !important;
    height: auto !important;
    color: var(--muted-soft) !important;

    &:hover {
      color: var(--accent-rose) !important;
    }
    
    .el-icon {
      font-size: 16px;
    }
  }
}

.dialog-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;

  :deep(.el-button) {
    height: 36px;
    border-radius: var(--radius-md);
    font-weight: 600;
    transition: all var(--duration-fast) var(--ease-out);

    &:first-child {
      background: transparent !important;
      border: 1px solid var(--hairline) !important;
      color: var(--body) !important;

      &:hover {
        border-color: var(--hairline-strong) !important;
        color: var(--on-dark) !important;
      }
    }

    &.submit-btn {
      background: var(--primary) !important;
      border-color: var(--primary) !important;
      color: var(--on-primary) !important;
      padding: 0 24px;

      &:hover {
        background: var(--primary-active) !important;
        border-color: var(--primary-active) !important;
        box-shadow: none;
      }
    }
  }
}

.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}

.asset-image-preview-dialog {
  :deep(.el-dialog) {
    background: var(--surface-card) !important;
    border: 1px solid var(--hairline) !important;
    box-shadow: 0 24px 70px rgba(16, 32, 51, 0.18) !important;
  }

  :deep(.el-dialog__header) {
    padding: 12px 16px !important;
    margin: 0 !important;
    border-bottom: 1px solid var(--hairline) !important;
  }

  :deep(.el-dialog__body) {
    padding: 16px !important;
  }
}

.asset-image-preview {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  min-height: 320px;
  max-height: 74vh;
  overflow: hidden;
  background: var(--surface-soft);

  img {
    display: block;
    max-width: 100%;
    max-height: 74vh;
    object-fit: contain;
  }
}

.asset-image-preview__copy {
  position: absolute;
  top: 14px;
  right: 14px;
  z-index: 2;
}

@media (max-width: 1100px) {
  .core-prompt-panel {
    grid-template-columns: 1fr;
  }

  .core-prompt-rules {
    grid-template-columns: 1fr;
  }

  .fixed-view-card {
    grid-template-columns: 1fr;
  }
}

/* ── Reference Image Upload UI ─────────────────────────────────────────────── */
.ref-image-control {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  width: 100%;

  &.core-ref {
    margin-top: 8px;
  }

  &.mini {
    gap: 8px;
    .ref-image-thumb {
      width: 32px;
      height: 32px;
      border-radius: var(--radius-xs);
    }
  }
}

.ref-image-thumb {
  width: 44px;
  height: 44px;
  flex-shrink: 0;
  border-radius: var(--radius-sm);
  border: 1px solid var(--hairline-strong);
  overflow: hidden;
  cursor: pointer;
  background: var(--surface-soft);
  transition: border-color var(--duration-fast) var(--ease-out);

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
  
  &:hover {
    border-color: var(--primary);
  }
}

.ref-image-input-wrap {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
  
  .row {
    display: flex;
    align-items: center;
    gap: 8px;
  }
}

/* ── Light studio reset: replace the old dark asset-lab skin ──────────────── */
.assets-page {
  background: var(--canvas);
  color: var(--body);
}

.asset-hero,
.filter-panel,
.core-prompt-panel,
.asset-section {
  background: var(--surface-card) !important;
  border-color: var(--hairline) !important;
  box-shadow: 0 1px 2px rgba(16, 32, 51, 0.03) !important;
}

.hero-content h1,
.section-title h2,
.core-prompt-panel__title h3,
.asset-card__name,
.form-section-title {
  color: var(--on-dark) !important;
}

.hero-content p,
.section-title p,
.asset-card__desc,
.field-hint,
.core-prompt-rule p,
.core-prompt-editor-head span {
  color: var(--muted) !important;
}

.core-prompt-rule,
.core-prompt-panel__main,
.core-prompt-panel__editor,
.asset-card,
.fixed-view-card,
.view-row-card,
.asset-version-panel {
  background: var(--surface-raised) !important;
  border-color: var(--hairline) !important;
  color: var(--body) !important;
  box-shadow: none !important;
}

.asset-card {
  border-radius: var(--radius-md) !important;

  &:hover {
    border-color: rgba(var(--brand-cyan-rgb), 0.35) !important;
    background: var(--surface-card) !important;
    box-shadow: 0 12px 28px rgba(16, 32, 51, 0.08) !important;
  }
}

.asset-card__preview,
.fixed-view-card__preview,
.view-row-card__preview,
.asset-version-thumb,
.asset-image-preview,
.ref-image-thumb {
  background: var(--surface-soft) !important;
  border-color: var(--hairline) !important;
}

.core-prompt-tabs,
.console-block,
.asset-version-actions,
.view-row-card__content :deep(.el-input__wrapper),
.view-row-card__content :deep(.el-select__wrapper),
.view-row-card__content :deep(.el-textarea__inner),
.studio-form :deep(.el-input__wrapper),
.studio-form :deep(.el-select__wrapper),
.studio-form :deep(.el-textarea__inner) {
  background: var(--surface-card) !important;
  border-color: var(--hairline) !important;
  color: var(--body) !important;
}

.core-prompt-tab.is-active,
.type-tab.is-active,
.view-type-tag,
.asset-view-dot.main,
.asset-view-dot.front,
.section-label .el-icon,
.ai-gen-btn,
.add-view-btn {
  color: var(--accent-cyan) !important;
}

.ai-gen-btn,
.add-view-btn,
.asset-version-select,
.dialog-footer .submit-btn {
  background: var(--primary) !important;
  border-color: var(--primary) !important;
  color: var(--on-primary) !important;
  box-shadow: none !important;
}

.asset-version-delete {
  background: var(--accent-rose) !important;
  color: #ffffff !important;
}

.studio-dialog {
  :deep(.el-dialog) {
    background: var(--surface-card) !important;
    background-image: none !important;
    border-color: var(--hairline) !important;
    border-radius: var(--radius-md) !important;
    box-shadow: 0 24px 70px rgba(16, 32, 51, 0.18) !important;

    &::before {
      display: none !important;
    }
  }

  :deep(.el-dialog__body),
  .dialog-scroll-container {
    background: var(--surface-card) !important;
  }

  :deep(.el-dialog__footer) {
    background: var(--surface-raised) !important;
  }
}

.studio-form {
  :deep(.el-input__inner),
  :deep(.el-textarea__inner) {
    color: var(--on-dark) !important;
  }

  .prompt-input :deep(.el-textarea__inner),
  .view-prompt-input :deep(.el-textarea__inner) {
    background: var(--surface-card) !important;
    color: var(--body-strong) !important;
  }
}

.asset-image-preview-dialog {
  :deep(.el-dialog) {
    background: var(--surface-card) !important;
    border-color: var(--hairline) !important;
    box-shadow: 0 24px 70px rgba(16, 32, 51, 0.18) !important;
  }
}

.assets-page--studio-v2 .assets-toolbar {
  :deep(.el-select__wrapper),
  :deep(.el-input__wrapper) {
    background: var(--surface-card) !important;
    border: 1px solid var(--hairline) !important;
    box-shadow: 0 0 0 1px var(--hairline) inset !important;
    color: var(--body-strong) !important;
  }

  :deep(.el-select__wrapper:hover),
  :deep(.el-input__wrapper:hover) {
    border-color: var(--hairline-strong) !important;
  }

  :deep(.el-select__wrapper.is-focus),
  :deep(.el-input__wrapper.is-focus) {
    border-color: var(--accent-cyan) !important;
    box-shadow: 0 0 0 2px rgba(var(--brand-cyan-rgb), 0.16) !important;
  }

  :deep(.el-input__inner),
  :deep(.el-select__placeholder),
  :deep(.el-select__selected-item),
  :deep(.el-input__prefix),
  :deep(.el-input__suffix) {
    color: var(--body-strong) !important;
  }

  :deep(.el-input__inner::placeholder) {
    color: var(--muted-soft) !important;
  }
}

.asset-editor-dialog {
  :deep(.el-dialog) {
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    border-radius: 12px !important;
  }

  :deep(.el-dialog__header) {
    padding: 18px 26px 14px !important;
    border-bottom: 1px solid var(--hairline) !important;
    background: var(--surface-card) !important;
  }

  :deep(.el-dialog__title) {
    color: var(--on-dark) !important;
    font-size: 18px !important;
    font-weight: 950 !important;
  }

  :deep(.el-dialog__body) {
    min-height: 0;
    overflow: hidden;
    background: var(--surface-soft) !important;
  }

  :deep(.el-dialog__footer) {
    flex: 0 0 auto;
    padding: 14px 26px !important;
    border-top: 1px solid var(--hairline) !important;
    background: var(--surface-card) !important;
  }

  .dialog-scroll-container {
    max-height: calc(90vh - 126px);
    padding: 18px 22px 22px;
    background: var(--surface-soft) !important;
  }

  .studio-form {
    grid-template-columns: minmax(320px, 0.42fr) minmax(520px, 0.58fr);
    gap: 16px;
  }

  .form-section {
    padding: 16px;
    border: 1px solid var(--hairline) !important;
    border-radius: 12px;
    background: var(--surface-card);
    box-shadow: 0 8px 22px rgba(16, 32, 51, 0.04);
  }

  .studio-form .form-section:nth-child(1),
  .studio-form .form-section:nth-child(2) {
    margin: 0;
    padding-top: 16px;
  }

  .studio-form .form-section:nth-child(3) {
    grid-column: 1 / -1;
    margin-top: 0;
    padding-top: 16px;
  }

  .section-label {
    margin-bottom: 14px;
    color: var(--on-dark) !important;
    font-size: 13px;
    font-weight: 950;
    letter-spacing: 0;
    text-transform: none;
  }

  .section-label .el-icon {
    color: var(--accent-cyan) !important;
  }

  :deep(.el-form-item) {
    margin-bottom: 14px;
  }

  :deep(.el-form-item__label) {
    color: var(--muted) !important;
    font-size: 12px !important;
    font-weight: 850 !important;
  }

  :deep(.el-input__wrapper),
  :deep(.el-textarea__inner),
  :deep(.el-select__wrapper) {
    min-height: 38px;
    border-color: #d6e1ec !important;
    border-radius: 9px !important;
    background: var(--surface-card) !important;
    color: var(--on-dark) !important;
  }

  :deep(.el-textarea__inner) {
    min-height: 86px;
    line-height: 1.55;
  }

  :deep(.el-input__inner),
  :deep(.el-textarea__inner),
  :deep(.el-select__placeholder),
  :deep(.el-select__selected-item) {
    color: var(--on-dark) !important;
  }

  .fixed-view-card {
    grid-template-columns: minmax(190px, 220px) minmax(0, 1fr);
    gap: 16px;
    padding: 14px;
    border-style: solid;
    border-color: var(--hairline);
    border-radius: 12px;
    background: var(--surface-soft);
  }

  .fixed-view-card:hover {
    transform: none;
    background: var(--surface-soft);
    border-color: #bdd3e8;
  }

  .fixed-view-card__preview {
    height: 188px;
    border-radius: 10px;
    background: var(--surface-soft);
  }

  .fixed-view-card__input {
    gap: 10px;
  }

  .view-type-header {
    min-height: 30px;
  }

  .view-type-tag {
    padding: 4px 8px;
    border-radius: 999px;
    background: rgba(var(--brand-cyan-rgb), 0.1);
    color: var(--accent-cyan) !important;
    font-size: 11px;
    letter-spacing: 0;
    text-transform: none;
  }

  .ai-gen-btn,
  .add-view-btn {
    height: 30px;
    border-radius: 9px;
  }

  .core-version-library {
    min-height: 104px;
    margin: 0;
    padding: 10px;
    border-radius: 12px;
    background: var(--surface-card);
  }

  .asset-version-panel__label {
    color: var(--muted);
    font-size: 12px;
    font-weight: 900;
  }

  .asset-version-thumb {
    width: 96px;
    min-width: 96px;
    min-height: 112px;
    border-color: var(--hairline);
    background: var(--surface-soft);
  }

  .asset-version-thumb img {
    height: 76px;
  }

  .asset-version-actions {
    background: var(--surface-card);
    border-top-color: var(--hairline);
  }

  .asset-version-actions span {
    background: var(--surface-soft);
    color: var(--on-dark);
  }

  .view-list {
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 14px;
  }

  .view-row-card {
    border-color: var(--hairline);
    border-radius: 12px;
    background: var(--surface-card);
  }

  .view-row-card:hover {
    transform: none;
    border-color: #bdd3e8;
  }

  .view-row-card__preview {
    height: 148px;
    background: var(--surface-soft);
  }

  .empty-extra-views {
    min-height: 150px;
    justify-content: center;
    border-color: var(--hairline);
    background: var(--surface-soft);
  }

  .dialog-footer {
    align-items: center;
  }

  .dialog-footer :deep(.el-button) {
    height: 38px;
    min-width: 86px;
    border-radius: 9px;
  }
}

@media (max-width: 980px) {
  .assets-scope-bar {
    align-items: stretch;
    padding: 10px 14px;
  }

  .assets-scope-segment {
    width: 100%;
    grid-template-columns: 1fr;
  }

  .assets-toolbar {
    height: auto;
    padding: 12px 14px;
  }

  .assets-toolbar__left {
    flex-wrap: wrap;
  }

  .asset-editor-dialog {
    .studio-form {
      grid-template-columns: 1fr;
    }

    .studio-form .form-section:nth-child(1),
    .studio-form .form-section:nth-child(2),
    .studio-form .form-section:nth-child(3) {
      grid-column: 1;
    }

    .fixed-view-card {
      grid-template-columns: 1fr;
    }
  }
}
</style>
