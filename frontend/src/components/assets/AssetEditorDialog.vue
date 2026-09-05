<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Plus, Delete, Picture, Upload, DocumentCopy, MagicStick, Microphone, CircleCheckFilled, WarningFilled, CircleCloseFilled, Loading } from '@element-plus/icons-vue'
import VoiceAssetsPanel from '@/components/assets/VoiceAssetsPanel.vue'
import request from '@/api/http'
import { cancelAssetImageJobs, createAsset, deleteAssetImage, deleteAssetImageVersion, detectLookAvatar, getAssetImageJob, listAssets, selectAssetImageVersion, updateAsset } from '@/api/asset'
import { listGrouped as listModelConfigs } from '@/api/modelConfig'
import { useAuthStore } from '@/stores/auth'
import { t } from '@/i18n'
import { isAssetImageJobBusy, isAssetImageJobPending, isAssetImageJobQueued } from '@/utils/assetImageJob'
import { copyTextToClipboard } from '@/utils/clipboard'
import { cacheBustMediaUrl, uploadImageFile } from '@/utils/uploadImage'
import type { Asset, AssetImage, AssetImageVersion, AssetPayload, AssetType, ModelConfig, Series } from '@/types'

const authStore = useAuthStore()

const props = defineProps<{
  modelValue: boolean
  asset: Asset | null
  seriesList?: Series[]
  initialSeriesId?: number | null
  initialType?: AssetType
  initialImageId?: number | null
  locked?: boolean
  lockedMessage?: string
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'saved', asset: Asset): void
}>()

const visible = computed({
  get: () => props.modelValue,
  set: (value) => emit('update:modelValue', value),
})

const saving = ref(false)
const imagePreviewVisible = ref(false)
const imagePreviewUrl = ref('')
const imageModels = ref<ModelConfig[]>([])
const selectedImageModelId = ref<number | null>(null)
const generationLoading = ref<Record<string, boolean>>({})
const generationStatus = ref<Record<string, string>>({})
const pendingGenerationJobIds = ref<Record<string, number>>({})
const cancellingGenerationKeys = ref<Record<string, boolean>>({})
const lookAvatarLoading = ref<Record<string, boolean>>({})
const selectingAssetImageVersionIds = ref<Record<number, boolean>>({})
const deletingAssetImageVersionIds = ref<Record<number, boolean>>({})
const deletingAssetImageIds = ref<Record<number, boolean>>({})
const assetEditorScrollRef = ref<HTMLElement | null>(null)
const focusedImageId = ref<number | null>(null)
const uploadingImage = ref(false)
const uploadProgress = ref(0)
const imageJobTimers = new Map<string, number>()
const ASSET_IMAGE_MODEL_STORAGE_KEY = 'malulu.assets.defaultImageModelId'
const dialogTitle = computed(() => props.asset ? t('编辑资产') : t('新建资产'))
const form = reactive<{
  series_id: number
  type: AssetType
  name: string
  description: string
  tagsInput: string
  images: AssetImage[]
}>({
  series_id: 0,
  type: 'character',
  name: '',
  description: '',
  tagsInput: '',
  images: [{ view_type: 'main', reference_role: 'view', url: '', note: '核心视图', image_prompt: '', sort: 10 }],
})

const VIEW_TYPES = [
  { value: 'main', label: '核心视图' },
  { value: 'front', label: '正视图' },
  { value: 'side', label: '侧视图' },
  { value: 'back', label: '背视图' },
  { value: 'three_view', label: '三视图' },
  { value: 'reference', label: '参考图' },
]
const hasPersistedAsset = computed(() => !!props.asset?.id)
const hasPersistedCharacterAsset = computed(() => !!props.asset?.id && props.asset.type === 'character')
const lookImages = computed(() => form.images.filter((img) => img.reference_role === 'look'))
const currentSeries = computed(() => (props.seriesList ?? []).find((item) => item.id === form.series_id) ?? null)
const needsLookAvatarCheck = computed(() => form.type === 'character' && String(currentSeries.value?.visual_style || 'realistic') === 'realistic')
const isCharacterAsset = computed(() => form.type === 'character')
const coreSectionTitle = computed(() => isCharacterAsset.value ? t('核心形象') : t('核心视图'))
const seriesList = computed(() => props.seriesList ?? [])
const preservedSupplementalImages = ref<AssetImage[]>([])

watch(visible, (value) => {
  if (value) void ensureImageModels()
  if (!value) {
    emitLookAvatarSnapshot()
    clearImageJobTimers()
  }
})

onBeforeUnmount(clearImageJobTimers)

watch(
  () => [props.asset, props.modelValue, props.initialSeriesId, props.initialType, props.initialImageId] as const,
  ([asset, modelValue]) => {
    if (!modelValue) return
    if (!asset) {
      form.series_id = props.initialSeriesId ?? 0
      form.type = props.initialType ?? 'character'
      form.name = ''
      form.description = ''
      form.tagsInput = ''
      form.images = [{ view_type: 'main', reference_role: 'view', url: '', note: '核心视图', image_prompt: '', sort: 10 }]
      preservedSupplementalImages.value = []
      generationLoading.value = {}
      generationStatus.value = {}
      pendingGenerationJobIds.value = {}
      cancellingGenerationKeys.value = {}
      lookAvatarLoading.value = {}
      focusedImageId.value = null
      return
    }
    applyAssetImages(asset)
    restoreImageJobs(asset)
    focusInitialImage()
  },
  { immediate: true },
)

function focusInitialImage() {
  const imageId = props.initialImageId ? Number(props.initialImageId) : 0
  focusedImageId.value = imageId > 0 ? imageId : null
  if (imageId <= 0) return
  void nextTick(() => {
    const root = assetEditorScrollRef.value
    const target = root?.querySelector(`[data-asset-image-id="${imageId}"]`) as HTMLElement | null
    target?.scrollIntoView({ block: 'center', behavior: 'smooth' })
  })
}

async function ensureImageModels() {
  if (imageModels.value.length) return
  const groups = await listModelConfigs()
  const imageGroup = groups.find((group) => group.type === 'image') as ((typeof groups)[number] & { items?: ModelConfig[] }) | undefined
  imageModels.value = imageGroup?.models ?? imageGroup?.items ?? []
  const saved = Number(localStorage.getItem(ASSET_IMAGE_MODEL_STORAGE_KEY) || 0)
  selectedImageModelId.value = imageModels.value.some((model) => model.id === saved)
    ? saved
    : imageModels.value[0]?.id ?? null
}

function isLookImage(img: Pick<AssetImage, 'reference_role' | 'view_type'>) {
  return (img.reference_role ?? (img.view_type === 'look' ? 'look' : 'view')) === 'look'
}

function isCoreImage(img: Pick<AssetImage, 'reference_role' | 'view_type'>) {
  return !isLookImage(img) && img.view_type === 'main'
}

function cloneImage(img: AssetImage, fallbackPrompt = ''): AssetImage {
  return {
    ...img,
    reference_role: img.reference_role ?? (img.view_type === 'look' ? 'look' : 'view'),
    variant_name: img.variant_name ?? '',
    reference_key: img.reference_key ?? '',
    image_prompt: img.image_prompt ?? fallbackPrompt,
    versions: (img.versions ?? []).map((version) => ({ ...version })),
  }
}

function coreImageRow(): AssetImage {
  let main = form.images.find((img) => (img.reference_role ?? 'view') !== 'look' && img.view_type === 'main')
  if (!main) {
    main = { view_type: 'main', reference_role: 'view', url: '', note: '核心视图', image_prompt: '', sort: 10 }
    form.images.unshift(main)
  }
  return main
}

function imageJobKey(img: AssetImage, fallbackIndex = 0) {
  if (img.id) return `img-${img.id}`
  return `${img.reference_role ?? 'view'}-${img.view_type}-${fallbackIndex}`
}

function guardLocked() {
  if (!props.locked) return false
  ElMessage.warning(props.lockedMessage || t('当前作品正在生产，暂时不能编辑资产'))
  return true
}

function copyImageLink(url: string) {
  void copyTextToClipboard(url, t('图片链接已复制'))
}

function openImagePreview(url: string) {
  if (!url) return
  imagePreviewUrl.value = url
  imagePreviewVisible.value = true
}

async function uploadImageToUrl(assign: (url: string) => void) {
  if (guardLocked()) return
  if (uploadingImage.value) return
  const input = document.createElement('input')
  input.type = 'file'
  input.accept = 'image/*'
  input.onchange = async (event: any) => {
    const file = event.target.files?.[0]
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
      await nextTick()
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

async function refreshPersistedAssetFromServer(): Promise<Asset | null> {
  const assetId = Number(props.asset?.id || 0)
  const seriesId = Number(form.series_id || props.asset?.series_id || 0)
  if (assetId <= 0 || seriesId <= 0) return null
  try {
    const list = await listAssets({ series_id: seriesId }, { silent: true })
    const updated = (list || []).find((item) => Number(item.id) === assetId) || null
    if (!updated) return null
    applyAssetImages(updated)
    applyLookAvatarFromAsset(updated)
    emit('saved', updated)
    return updated
  } catch {
    return null
  }
}

function addLookVariant() {
  if (guardLocked()) return
  form.images.push({
    view_type: 'look',
    reference_role: 'look',
    variant_name: '',
    reference_key: '',
    url: '',
    note: '人物造型',
    image_prompt: '',
    sort: (form.images.length + 1) * 10,
  })
}

function getViewLabel(viewType: string) {
  return VIEW_TYPES.find((item) => item.value === viewType)?.label ?? '参考图'
}

function displayGenerationStatus(value: string | undefined) {
  return t(value || 'AI 生成')
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

function defaultImagePrompt(img: AssetImage) {
  const subject = getAssetSubjectLabel(form.type)
  const viewLabel = getViewLabel(img.view_type)
  const name = form.name.trim() || `未命名${subject}`
  const description = form.description.trim()
  if (img.view_type === 'main') {
    return description || `${subject}「${name}」`
  }
  if (img.reference_role === 'look') {
    const variantName = (img.variant_name || '').trim() || '默认造型'
    const referencePart = (coreImageRow().url || '').trim() ? '参考已上传的角色主图。' : ''
    const descriptionPart = description ? `。角色基础描述：${description}` : ''
    const layoutPart = '画面必须是左右分栏造型板：左栏正侧背三个无头全身（头从衣领处切除，只看服装），右栏同一人物放大特写（头部五官完整）；禁止红笔涂脸，禁止只出一张带头正面全身'
    return `${referencePart}生成角色「${name}」的造型版本「${variantName}」，${layoutPart}；保持同一角色一致性：五官、脸型、发型、体型、年龄感和气质稳定，重点变化在服装与整体造型，白底棚拍，不要场景，不要道具，不要第二个人${descriptionPart}`
  }
  const descriptionPart = description ? `。资产描述：${description}` : ''
  const referencePart = (coreImageRow().url || '').trim() ? '参考已上传的主图。' : ''
  return `${referencePart}根据主图生成${subject}「${name}」的${viewLabel}，${getConsistencyRule(form.type)}，不要改成新的${subject}，不要拼图，不要多场景${descriptionPart}`
}

function coreViewPromptPlaceholder(img: AssetImage) {
  const base = defaultImagePrompt(img)
  if (img.view_type !== 'main') return base
  if (form.type === 'character') return `${base}（核心视图会自动按多视图棚拍规则生成）`
  if (form.type === 'scene') return `${base}（核心视图会自动按多角度场景参考板生成）`
  return `${base}（核心视图会自动按物品多角度参考生成）`
}

function resolveImagePrompt(img: AssetImage) {
  const prompt = (img.image_prompt || '').trim()
  if (prompt) return prompt
  const generated = defaultImagePrompt(img)
  img.image_prompt = generated
  return generated
}

async function handleGenerate(img: AssetImage, idx: number) {
  if (guardLocked()) return
  if (img.reference_role === 'look' && !hasPersistedAsset.value) {
    return ElMessage.warning(t('请先保存人物本体，再为人物造型生成图片'))
  }
  await ensureImageModels()
  if (imageModels.value.length === 0) return ElMessage.warning(t('暂无可用图片模型，请联系管理员配置'))
  if (!selectedImageModelId.value) return ElMessage.warning(t('请先选择图片模型'))
  const prompt = resolveImagePrompt(img)
  if (!prompt) return ElMessage.warning(t('请先填写这张图的生成指令'))
  const effectiveReferenceUrl = (img.reference_image_url || (img.view_type !== 'main' ? coreImageRow().url : '') || '').trim()
  if (img.view_type !== 'main' && !effectiveReferenceUrl) {
    return ElMessage.warning(t('请先生成或上传主视图'))
  }

  const key = imageJobKey(img, idx)
  generationLoading.value = { ...generationLoading.value, [key]: true }
  generationStatus.value = { ...generationStatus.value, [key]: '排队中' }
  localStorage.setItem(ASSET_IMAGE_MODEL_STORAGE_KEY, String(selectedImageModelId.value))
  try {
    const res: any = await request({
      url: '/api/assets/generate-image',
      method: 'POST',
      data: {
        asset_id: props.asset?.id ?? 0,
        asset_image_id: img.id ?? 0,
        model_config_id: selectedImageModelId.value,
        image_prompt: prompt,
        description: form.description,
        asset_type: form.type,
        view_type: img.view_type,
        reference_role: img.reference_role ?? (img.view_type === 'look' ? 'look' : 'view'),
        variant_name: (img.variant_name || '').trim(),
        reference_key: (img.reference_key || '').trim(),
        note: (img.note || '').trim(),
        main_image_url: coreImageRow().url ?? '',
        reference_image_url: effectiveReferenceUrl,
      },
    })
    if (img.reference_role === 'look') {
      img.has_toapis_avatar = false
      img.toapis_status = ''
      img.look_avatar_pending = false
      img.look_avatar_job_id = 0
    }
    if (res.url) {
      img.url = cacheBustMediaUrl(String(res.url))
      generationLoading.value = { ...generationLoading.value, [key]: false }
      generationStatus.value = { ...generationStatus.value, [key]: '' }
      if (props.asset?.id) await refreshPersistedAssetFromServer()
      ElMessage.success(t('图片已生成'))
      return
    }
    const jobs = Array.isArray(res.jobs) ? res.jobs : (res.job ? [res.job] : [])
    const queuedJobId = Number(res.id || jobs[0]?.id || 0)
    if (!queuedJobId) throw new Error('图片生成任务创建失败')
    pendingGenerationJobIds.value = { ...pendingGenerationJobIds.value, [key]: queuedJobId }
    ElMessage.success(t('已加入生成队列'))
    pollImageJob(queuedJobId, img, key)
  } catch (err: any) {
    ElMessage.error(err.message || t('生成失败'))
    generationLoading.value = { ...generationLoading.value, [key]: false }
    generationStatus.value = { ...generationStatus.value, [key]: '' }
    const nextPending = { ...pendingGenerationJobIds.value }
    delete nextPending[key]
    pendingGenerationJobIds.value = nextPending
  }
}

async function handleCancelQueuedGeneration(img: AssetImage, idx: number) {
  if (guardLocked()) return
  const key = imageJobKey(img, idx)
  const jobId = Number(pendingGenerationJobIds.value[key] || 0)
  const assetId = Number(props.asset?.id || 0)
  if (!jobId && assetId <= 0) {
    return ElMessage.info(t('没有可取消的排队任务'))
  }
  if (cancellingGenerationKeys.value[key]) return

  cancellingGenerationKeys.value = { ...cancellingGenerationKeys.value, [key]: true }
  try {
    const result = await cancelAssetImageJobs(
      jobId > 0
        ? { job_ids: [jobId], asset_id: assetId > 0 ? assetId : undefined }
        : { asset_id: assetId },
    )
    const existingTimer = imageJobTimers.get(key)
    if (existingTimer) window.clearTimeout(existingTimer)
    imageJobTimers.delete(key)
    generationLoading.value = { ...generationLoading.value, [key]: false }
    generationStatus.value = { ...generationStatus.value, [key]: '' }
    const nextPending = { ...pendingGenerationJobIds.value }
    delete nextPending[key]
    pendingGenerationJobIds.value = nextPending
    if (result.cancelled > 0) {
      ElMessage.success(t('已取消 {count} 个排队任务', { count: result.cancelled }))
    } else {
      ElMessage.info(t('没有可取消的排队任务'))
    }
  } finally {
    cancellingGenerationKeys.value = { ...cancellingGenerationKeys.value, [key]: false }
  }
}

async function pollImageJob(jobId: number, img: AssetImage, key: string, attempt = 0) {
  const existingTimer = imageJobTimers.get(key)
  if (existingTimer) window.clearTimeout(existingTimer)
  try {
    const job: any = await getAssetImageJob(jobId, { silent: true })
    if (job.status === 'success') {
      generationLoading.value = { ...generationLoading.value, [key]: false }
      generationStatus.value = { ...generationStatus.value, [key]: '' }
      imageJobTimers.delete(key)
      const nextPending = { ...pendingGenerationJobIds.value }
      delete nextPending[key]
      pendingGenerationJobIds.value = nextPending
      if (job.url) img.url = cacheBustMediaUrl(String(job.url))
      if (img.reference_role === 'look') {
        img.has_toapis_avatar = false
        img.toapis_status = ''
        img.look_avatar_pending = false
        img.look_avatar_job_id = 0
      }
      // 已落库资产：回拉列表以刷新版本库/卡片，避免「成功了但界面仍旧图」。
      if (props.asset?.id) {
        await refreshPersistedAssetFromServer()
      } else {
        emit('saved', {
          ...(props.asset as Asset),
          images: form.images,
        } as Asset)
      }
      ElMessage.success(props.asset ? t('候选图已生成') : t('图片已生成'))
      void authStore.refreshProfile(true)
      return
    }
    if (job.status === 'failed' || job.status === 'cancelled' || job.status === 'stale') {
      generationLoading.value = { ...generationLoading.value, [key]: false }
      generationStatus.value = { ...generationStatus.value, [key]: '' }
      imageJobTimers.delete(key)
      const nextPending = { ...pendingGenerationJobIds.value }
      delete nextPending[key]
      pendingGenerationJobIds.value = nextPending
      if (job.status === 'failed') {
        ElMessage.error(job.error_message || t('生成失败'))
      }
      return
    }
    if (attempt >= 240) {
      generationLoading.value = { ...generationLoading.value, [key]: false }
      generationStatus.value = { ...generationStatus.value, [key]: '' }
      imageJobTimers.delete(key)
      return
    }
    pendingGenerationJobIds.value = { ...pendingGenerationJobIds.value, [key]: jobId }
    generationStatus.value = { ...generationStatus.value, [key]: isAssetImageJobBusy(job.status) ? '生成中' : '排队中' }
    const delay = attempt < 5 ? 2000 : 4000
    const timer = window.setTimeout(() => pollImageJob(jobId, img, key, attempt + 1), delay)
    imageJobTimers.set(key, timer)
  } catch (err: any) {
    if (attempt >= 3) {
      ElMessage.error(err.message || t('更新生成进度失败'))
      generationLoading.value = { ...generationLoading.value, [key]: false }
      generationStatus.value = { ...generationStatus.value, [key]: '' }
      imageJobTimers.delete(key)
      return
    }
    const timer = window.setTimeout(() => pollImageJob(jobId, img, key, attempt + 1), 3000)
    imageJobTimers.set(key, timer)
  }
}

function clearImageJobTimers() {
  for (const timer of imageJobTimers.values()) window.clearTimeout(timer)
  imageJobTimers.clear()
}

function assetImageVersionsFor(img: AssetImage): AssetImageVersion[] {
  const versions = Array.isArray(img.versions) ? [...img.versions] : []
  const currentUrl = String(img.url || '').trim()
  if (currentUrl && !versions.some((version) => String(version.url || '').trim() === currentUrl)) {
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
      is_selected: currentUrl
        ? String(version.url || '').trim() === currentUrl
        : !!version.is_selected,
    }))
    .sort((a, b) => Number(a.id) - Number(b.id))
}

function assetImageVersionLabel(version: AssetImageVersion, index: number) {
  if (version.is_selected) return t('当前')
  return t('候选 {number}', { number: String(index + 1).padStart(2, '0') })
}

async function selectAssetVersion(img: AssetImage, version: AssetImageVersion) {
  if (!props.asset?.id || !version?.id || version.id < 0) return
  selectingAssetImageVersionIds.value = { ...selectingAssetImageVersionIds.value, [version.id]: true }
  try {
    const updated = await selectAssetImageVersion(props.asset.id, version.id)
    syncFromAsset(updated)
    emit('saved', updated)
    ElMessage.success(t('已切换版本'))
  } finally {
    selectingAssetImageVersionIds.value = { ...selectingAssetImageVersionIds.value, [version.id]: false }
  }
}

async function deleteAssetVersion(img: AssetImage, version: AssetImageVersion) {
  if (!props.asset?.id || !version?.id || version.id < 0) return
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
    const updated = await deleteAssetImageVersion(props.asset.id, version.id)
    syncFromAsset(updated)
    emit('saved', updated)
    ElMessage.success(t('已删除候选版本'))
  } finally {
    deletingAssetImageVersionIds.value = { ...deletingAssetImageVersionIds.value, [version.id]: false }
  }
}

function imageDeleteCopy(img: AssetImage) {
  if (isCoreImage(img)) {
    return {
      title: t('删除核心视图'),
      message: t('确定删除核心视图？删除后造型将无法继续沿用这张主图。'),
      success: t('核心视图已删除'),
    }
  }
  return {
    title: t('删除人物造型'),
    message: t('确定删除这套人物造型？删除后不可恢复。'),
    success: t('人物造型已删除'),
  }
}

function hasImageContent(img: AssetImage) {
  return Boolean(
    img.id
    || (img.url || '').trim()
    || (img.variant_name || '').trim()
    || (img.image_prompt || '').trim()
  )
}

function isDeletingAssetImage(img: AssetImage) {
  return !!img.id && !!deletingAssetImageIds.value[img.id]
}

async function removeImageRow(img: AssetImage) {
  if (guardLocked()) return
  const index = form.images.indexOf(img)
  if (index < 0) return

  const copy = imageDeleteCopy(img)
  if (hasImageContent(img)) {
    try {
      await ElMessageBox.confirm(copy.message, copy.title, {
        type: 'warning',
        confirmButtonText: t('删除'),
        cancelButtonText: t('取消'),
      })
    } catch {
      return
    }
  }

  const isCoreView = (img.reference_role ?? 'view') !== 'look' && img.view_type === 'main'
  const hadImageContent = hasImageContent(img)
  if (!props.asset?.id || !img.id) {
    if (isCoreView) {
      img.url = ''
      img.versions = []
    } else {
      form.images.splice(index, 1)
    }
    if (hadImageContent) ElMessage.success(copy.success)
    return
  }

  deletingAssetImageIds.value = { ...deletingAssetImageIds.value, [img.id]: true }
  try {
    const updated = await deleteAssetImage(props.asset.id, img.id)
    syncFromAsset(updated)
    emit('saved', updated)
    ElMessage.success(copy.success)
  } finally {
    deletingAssetImageIds.value = { ...deletingAssetImageIds.value, [img.id]: false }
  }
}

function applyAssetImages(asset: Asset) {
  form.series_id = asset.series_id
  form.type = asset.type
  form.name = asset.name
  form.description = asset.description ?? ''
  form.tagsInput = (asset.tags ?? []).join(', ')
  const existing = asset.images ?? []
  const main = existing.find((img) => isCoreImage(img))
  form.images = [
    main ? cloneImage(main, asset.image_prompt ?? '') : { view_type: 'main', reference_role: 'view', url: '', note: '核心视图', image_prompt: asset.image_prompt ?? '', sort: 10 },
    ...existing.filter((img) => isLookImage(img)).map((img) => cloneImage(img)),
  ]
  preservedSupplementalImages.value = existing
    .filter((img) => !isCoreImage(img) && !isLookImage(img))
    .map((img) => cloneImage(img))
}

function syncFromAsset(asset: Asset) {
  applyAssetImages(asset)
}

function lookAvatarKey(img: AssetImage) {
  return img.id ? `look-avatar-${img.id}` : `look-avatar-${img.view_type}`
}

function applyLookAvatarFromAsset(asset: Asset) {
  for (const img of form.images) {
    if (img.reference_role !== 'look' || !img.id) continue
    const matched = (asset.images ?? []).find((item) => item.id === img.id)
    if (!matched) continue
    img.has_toapis_avatar = !!matched.has_toapis_avatar
    img.toapis_status = matched.toapis_status ?? ''
    img.look_avatar_pending = !!matched.look_avatar_pending
    img.look_avatar_job_id = Number(matched.look_avatar_job_id || 0)
  }
}

function lookAvatarState(img: AssetImage): 'passed' | 'pending' | 'failed' | 'unchecked' | 'empty' | 'off' {
  if (!needsLookAvatarCheck.value) return 'off'
  if (img.has_toapis_avatar) return 'passed'
  if (
    img.look_avatar_pending
    || lookAvatarLoading.value[lookAvatarKey(img)]
    || (img.toapis_status || '') === 'processing'
  ) return 'pending'
  if ((img.toapis_status || '') === 'failed') return 'failed'
  if (!(img.url || '').trim()) return 'empty'
  return 'unchecked'
}

function lookAvatarButtonLabel(img: AssetImage) {
  const state = lookAvatarState(img)
  if (state === 'passed') return t('已过真人检测')
  if (state === 'pending') return t('真人检测中')
  if (state === 'failed') return t('重新检测')
  return t('去真人检测')
}

function lookAvatarStatusText(img: AssetImage) {
  const state = lookAvatarState(img)
  if (state === 'passed') return t('已过真人检测，可用于写实视频')
  if (state === 'pending') return t('真人检测进行中，完成后才能用于写实视频')
  if (state === 'failed') return t('真人检测失败，写实视频无法使用此造型')
  if (state === 'unchecked') return t('未过真人检测，写实视频无法使用此造型')
  return t('请先生成或上传造型图，再做真人检测')
}

function lookAvatarButtonDisabled(img: AssetImage) {
  if (!img.id || !(img.url || '').trim()) return true
  const state = lookAvatarState(img)
  return state === 'passed' || state === 'pending' || state === 'off'
}

async function handleDetectLookAvatar(img: AssetImage) {
  if (guardLocked()) return
  if (!props.asset?.id || !img.id) {
    return ElMessage.warning(t('请先保存人物本体，再为人物造型生成图片'))
  }
  if (!(img.url || '').trim()) {
    return ElMessage.warning(t('请先生成或上传这套造型图，再做真人检测'))
  }
  const key = lookAvatarKey(img)
  lookAvatarLoading.value = { ...lookAvatarLoading.value, [key]: true }
  img.look_avatar_pending = true
  img.toapis_status = 'processing'
  try {
    const res = await detectLookAvatar(props.asset.id, img.id)
    if (res.asset) {
      applyLookAvatarFromAsset(res.asset)
      emit('saved', res.asset)
    }
    if (res.already_active) {
      img.has_toapis_avatar = true
      img.toapis_status = 'active'
      img.look_avatar_pending = false
      lookAvatarLoading.value = { ...lookAvatarLoading.value, [key]: false }
      const refreshed = await refreshPersistedAssetFromServer()
      if (!refreshed && res.asset) emit('saved', res.asset)
      ElMessage.success(t('真人检测已通过'))
      return
    }
    const jobId = Number(res.job?.id || img.look_avatar_job_id || 0)
    if (!jobId) throw new Error(t('真人检测失败'))
    img.look_avatar_job_id = jobId
    img.look_avatar_pending = true
    img.toapis_status = img.toapis_status || 'processing'
    ElMessage.success(t('真人检测已加入队列'))
    pollLookAvatarJob(jobId, img, key)
  } catch (err: any) {
    img.look_avatar_pending = false
    img.toapis_status = 'failed'
    lookAvatarLoading.value = { ...lookAvatarLoading.value, [key]: false }
    ElMessage.error(err.message || t('真人检测失败'))
  }
}

function currentLookImage(img: AssetImage): AssetImage {
  return (img.id ? form.images.find((item) => item.id === img.id) : undefined) ?? img
}

function emitLookAvatarSnapshot() {
  if (!props.asset) return
  emit('saved', {
    ...props.asset,
    images: (props.asset.images ?? []).map((item) => {
      const current = item.id ? form.images.find((img) => img.id === item.id) : undefined
      if (!current) return item
      return {
        ...item,
        has_toapis_avatar: current.has_toapis_avatar,
        toapis_status: current.toapis_status,
        look_avatar_pending: current.look_avatar_pending,
        look_avatar_job_id: current.look_avatar_job_id,
      }
    }),
  })
}

async function pollLookAvatarJob(jobId: number, img: AssetImage, key: string, attempt = 0) {
  const existingTimer = imageJobTimers.get(key)
  if (existingTimer) window.clearTimeout(existingTimer)
  const target = currentLookImage(img)
  try {
    const job: any = await getAssetImageJob(jobId, { silent: true })
    if (job.status === 'success') {
      target.has_toapis_avatar = true
      target.toapis_status = 'active'
      target.look_avatar_pending = false
      target.look_avatar_job_id = 0
      lookAvatarLoading.value = { ...lookAvatarLoading.value, [key]: false }
      imageJobTimers.delete(key)
      const refreshed = await refreshPersistedAssetFromServer()
      if (!refreshed) emitLookAvatarSnapshot()
      ElMessage.success(t('真人检测已通过'))
      return
    }
    if (job.status === 'failed' || job.status === 'cancelled') {
      target.has_toapis_avatar = false
      target.toapis_status = 'failed'
      target.look_avatar_pending = false
      lookAvatarLoading.value = { ...lookAvatarLoading.value, [key]: false }
      imageJobTimers.delete(key)
      emitLookAvatarSnapshot()
      ElMessage.error(job.error_message || t('真人检测失败'))
      return
    }
    if (attempt >= 240) {
      target.look_avatar_pending = false
      lookAvatarLoading.value = { ...lookAvatarLoading.value, [key]: false }
      imageJobTimers.delete(key)
      return
    }
    target.look_avatar_pending = true
    if ((target.toapis_status || '') === 'failed' || !(target.toapis_status || '').trim()) {
      target.toapis_status = 'processing'
    }
    lookAvatarLoading.value = { ...lookAvatarLoading.value, [key]: true }
    const delay = attempt < 5 ? 2000 : 4000
    const timer = window.setTimeout(() => pollLookAvatarJob(jobId, target, key, attempt + 1), delay)
    imageJobTimers.set(key, timer)
  } catch (err: any) {
    if (attempt >= 3) {
      target.look_avatar_pending = false
      lookAvatarLoading.value = { ...lookAvatarLoading.value, [key]: false }
      imageJobTimers.delete(key)
      ElMessage.error(err.message || t('真人检测失败'))
      return
    }
    const timer = window.setTimeout(() => pollLookAvatarJob(jobId, target, key, attempt + 1), 3000)
    imageJobTimers.set(key, timer)
  }
}

function restoreImageJobs(asset: Asset) {
  generationLoading.value = {}
  generationStatus.value = {}
  pendingGenerationJobIds.value = {}
  cancellingGenerationKeys.value = {}
  lookAvatarLoading.value = {}
  for (const job of asset.image_jobs ?? []) {
    const index = form.images.findIndex((img) => job.asset_image_id ? img.id === job.asset_image_id : img.view_type === job.view_type)
    if (index < 0) continue
    const key = imageJobKey(form.images[index], index)
    if (isAssetImageJobPending(job.status)) {
      generationLoading.value = { ...generationLoading.value, [key]: true }
      generationStatus.value = { ...generationStatus.value, [key]: isAssetImageJobBusy(job.status) ? '生成中' : '排队中' }
      if (isAssetImageJobQueued(job.status)) {
        pendingGenerationJobIds.value = { ...pendingGenerationJobIds.value, [key]: job.id }
      }
      pollImageJob(job.id, form.images[index], key)
    }
  }
  for (const img of form.images) {
    if (img.reference_role !== 'look') continue
    const pending = !!img.look_avatar_pending || (img.toapis_status || '') === 'processing'
    if (!pending || !img.look_avatar_job_id) continue
    const key = lookAvatarKey(img)
    lookAvatarLoading.value = { ...lookAvatarLoading.value, [key]: true }
    pollLookAvatarJob(img.look_avatar_job_id, img, key)
  }
}

function replaceCoreImageRow() {
  if (guardLocked()) return
  void uploadImageToUrl((url) => {
    const main = coreImageRow()
    main.url = url
  })
}

async function submitAsset() {
  if (guardLocked()) return
  if (!form.series_id) return ElMessage.warning(t('请选择所属作品'))
  if (!form.name.trim()) return ElMessage.warning(t('请输入资产名称'))

  const images = [...form.images, ...preservedSupplementalImages.value]
    .map((img, idx) => ({
      id: img.id,
      view_type: img.view_type || 'reference',
      reference_role: img.reference_role ?? (img.view_type === 'look' ? 'look' : 'view'),
      variant_name: (img.variant_name || '').trim(),
      reference_key: (img.reference_key || '').trim(),
      url: (img.url || '').trim(),
      note: (img.note || '').trim(),
      image_prompt: (img.image_prompt || '').trim(),
      sort: img.sort ?? (idx + 1) * 10,
    }))
    .filter((img) => img.url !== '' || img.note !== '' || img.image_prompt !== '' || img.variant_name !== '')

  if (!props.asset && images.length === 0) {
    return ElMessage.warning(t('请至少添加一张图片或生成指令'))
  }

  const payload: AssetPayload = {
    series_id: form.series_id,
    type: form.type,
    name: form.name.trim(),
    description: form.description.trim(),
    image_prompt: '',
    tags: form.tagsInput.split(',').map((item) => item.trim()).filter(Boolean),
    images,
  }

  saving.value = true
  try {
    const updated = props.asset
      ? await updateAsset(props.asset.id, payload)
      : await createAsset(payload)
    ElMessage.success(props.asset ? t('资产已更新') : t('资产已创建'))
    emit('saved', updated)
    visible.value = false
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <el-dialog
    v-model="visible"
    :title="dialogTitle"
    width="min(96vw, 1080px)"
    append-to-body
    destroy-on-close
    class="asset-editor-dialog"
    top="4vh"
  >
    <div ref="assetEditorScrollRef" class="asset-editor-scroll">
      <el-form label-position="top" class="asset-editor-form">
        <section class="editor-panel identity-core-panel">
          <div class="identity-row">
            <el-select v-model="form.type" :disabled="locked" class="identity-type">
              <el-option :label="t('人物')" value="character" />
              <el-option :label="t('场景')" value="scene" />
              <el-option :label="t('物品')" value="prop" />
            </el-select>
            <el-input v-model="form.name" :disabled="locked" :placeholder="t('资产名称')" />
            <el-select v-model="form.series_id" :disabled="locked" class="identity-series" :placeholder="t('所属剧本')">
              <el-option v-for="series in seriesList" :key="series.id" :label="series.title" :value="series.id" />
            </el-select>
          </div>
          <el-input
            v-model="form.description"
            type="textarea"
            :rows="2"
            :disabled="locked"
            :placeholder="t('一句话描述外貌或设定，生成图时会用到')"
          />

          <div class="editor-section-title core-section-title">
            <el-icon><Picture /></el-icon>
            <span>{{ coreSectionTitle }}</span>
            <el-select
              v-model="selectedImageModelId"
              class="image-model-select"
              size="small"
              :placeholder="t('图像模型')"
              :disabled="locked || !imageModels.length"
            >
              <el-option v-for="model in imageModels" :key="model.id" :label="model.name" :value="model.id" />
            </el-select>
          </div>
          <div v-if="uploadingImage" class="upload-progress-banner">
            <el-progress :percentage="uploadProgress" :stroke-width="10" />
            <span>{{ t('正在上传图片… {percent}%', { percent: uploadProgress }) }}</span>
          </div>
          <div v-if="coreImageRow()" class="core-editor-card">
            <div class="core-editor-preview" @click="coreImageRow().url ? openImagePreview(coreImageRow().url) : uploadImageToUrl((url) => { coreImageRow().url = url })">
              <img v-if="coreImageRow().url" :src="coreImageRow().url" alt="" />
              <div v-else class="empty-preview">
                <el-icon><Upload /></el-icon>
                <span>{{ uploadingImage ? t('上传中…') : t('上传核心视图') }}</span>
              </div>
              <div v-if="uploadingImage" class="preview-upload-mask">
                <el-progress type="circle" :percentage="uploadProgress" :width="72" />
              </div>
              <el-button v-if="coreImageRow().url" class="image-copy-btn preview-copy-btn" size="small" circle @click.stop="copyImageLink(coreImageRow().url)">
                <el-icon><DocumentCopy /></el-icon>
              </el-button>
            </div>
            <div class="core-editor-fields">
              <div class="view-action-row core-action-row">
                <el-button
                  v-if="generationStatus[imageJobKey(coreImageRow(), 0)] === t('排队中')"
                  type="warning"
                  size="small"
                  class="ai-gen-btn"
                  plain
                  :loading="cancellingGenerationKeys[imageJobKey(coreImageRow(), 0)]"
                  :disabled="locked"
                  @click="handleCancelQueuedGeneration(coreImageRow(), 0)"
                >
                  <span>{{ t('取消排队') }}</span>
                </el-button>
                <el-button
                  v-else
                  type="primary"
                  size="small"
                  class="ai-gen-btn"
                  :loading="generationLoading[imageJobKey(coreImageRow(), 0)]"
                  :disabled="locked"
                  @click="handleGenerate(coreImageRow(), 0)"
                >
                  <el-icon><MagicStick /></el-icon>
                  <span>{{ displayGenerationStatus(generationStatus[imageJobKey(coreImageRow(), 0)]) }}</span>
                </el-button>
                <el-button type="primary" link :disabled="locked" @click="replaceCoreImageRow">
                  <el-icon><Upload /></el-icon>
                  <span>{{ t('重新上传') }}</span>
                </el-button>
                <el-button
                  v-if="coreImageRow().url"
                  class="core-delete-btn"
                  type="danger"
                  link
                  :aria-label="t('删除')"
                  :title="t('删除')"
                  :disabled="locked"
                  :loading="isDeletingAssetImage(coreImageRow())"
                  @click="removeImageRow(coreImageRow())"
                >
                  <el-icon><Delete /></el-icon>
                  <span class="button-label">{{ t('删除') }}</span>
                </el-button>
              </div>
              <el-input
                v-model="coreImageRow().image_prompt"
                :disabled="locked"
                type="textarea"
                :rows="4"
                :placeholder="coreViewPromptPlaceholder(coreImageRow())"
              />
            </div>
          </div>
          <div v-if="coreImageRow() && assetImageVersionsFor(coreImageRow()).length" class="version-panel">
            <div class="version-panel__label">{{ t('核心视图版本库') }}</div>
            <div class="version-strip">
              <button
                v-for="(version, versionIdx) in assetImageVersionsFor(coreImageRow())"
                :key="version.id"
                type="button"
                class="version-thumb"
                :class="{ 'is-selected': version.is_selected }"
                @click="openImagePreview(version.url)"
              >
                <img :src="version.url" alt="" />
                <span class="image-copy-btn version-copy" role="button" tabindex="0" @click.stop="copyImageLink(version.url)">
                  <el-icon><DocumentCopy /></el-icon>
                </span>
                <div class="version-actions">
                  <span v-if="version.is_selected">{{ assetImageVersionLabel(version, versionIdx) }}</span>
                  <template v-else>
                    <em @click.stop="selectAssetVersion(coreImageRow(), version)">
                      {{ selectingAssetImageVersionIds[version.id] ? t('选用中') : t('选用') }}
                    </em>
                    <i @click.stop="deleteAssetVersion(coreImageRow(), version)">
                      {{ deletingAssetImageVersionIds[version.id] ? t('删除中') : t('删除') }}
                    </i>
                  </template>
                </div>
              </button>
            </div>
          </div>
        </section>

        <section v-if="isCharacterAsset" class="editor-panel">
          <div class="editor-section-title">
            <el-icon><Picture /></el-icon>
            <span>{{ t('人物造型') }}</span>
            <el-button class="add-view-btn" type="primary" size="small" :disabled="locked" @click="addLookVariant">
              <el-icon><Plus /></el-icon>
              <span>{{ t('添加造型') }}</span>
            </el-button>
          </div>
          <p v-if="needsLookAvatarCheck" class="look-section-note">{{ t('写实视频必须先过真人检测') }}</p>
          <div class="look-card-list">
            <article
              v-for="(img, idx) in lookImages"
              :key="img.id || `look-${idx}`"
              class="look-card"
              :class="[
                `look-card--${lookAvatarState(img)}`,
                { 'is-focused': !!img.id && focusedImageId === img.id },
              ]"
              :data-asset-image-id="img.id || undefined"
            >
              <div class="look-card-preview" @click="img.url ? openImagePreview(img.url) : uploadImageToUrl((url) => { img.url = url })">
                <img v-if="img.url" :src="img.url" alt="" />
                <div v-else class="empty-preview">
                  <el-icon><Picture /></el-icon>
                  <span>{{ uploadingImage ? t('上传中…') : t('暂无造型图') }}</span>
                </div>
                <div v-if="uploadingImage && !img.url" class="preview-upload-mask">
                  <el-progress type="circle" :percentage="uploadProgress" :width="64" />
                </div>
                <span v-if="img.url" class="image-copy-btn preview-copy-btn" role="button" tabindex="0" @click.stop="copyImageLink(img.url)">
                  <el-icon><DocumentCopy /></el-icon>
                </span>
              </div>
              <div class="look-card-body">
                <div
                  v-if="needsLookAvatarCheck"
                  class="look-status-banner"
                  :class="`look-status-banner--${lookAvatarState(img)}`"
                >
                  <el-icon v-if="lookAvatarState(img) === 'passed'"><CircleCheckFilled /></el-icon>
                  <el-icon v-else-if="lookAvatarState(img) === 'pending'" class="is-loading"><Loading /></el-icon>
                  <el-icon v-else-if="lookAvatarState(img) === 'failed'"><CircleCloseFilled /></el-icon>
                  <el-icon v-else><WarningFilled /></el-icon>
                  <strong>{{ lookAvatarStatusText(img) }}</strong>
                </div>
                <div class="look-card-toolbar">
                  <el-input v-model="img.variant_name" size="small" :disabled="locked" :placeholder="t('造型名称，例如：第2集常服')" />
                  <el-button
                    v-if="generationStatus[imageJobKey(img, idx + 10)] === '排队中'"
                    type="warning"
                    size="small"
                    plain
                    :loading="cancellingGenerationKeys[imageJobKey(img, idx + 10)]"
                    :disabled="locked"
                    @click="handleCancelQueuedGeneration(img, idx + 10)"
                  >
                    <span>{{ t('取消排队') }}</span>
                  </el-button>
                  <el-button
                    v-else
                    type="primary"
                    size="small"
                    :loading="generationLoading[imageJobKey(img, idx + 10)]"
                    :disabled="locked || !hasPersistedAsset"
                    @click="handleGenerate(img, idx + 10)"
                  >
                    <el-icon><MagicStick /></el-icon>
                    <span>{{ displayGenerationStatus(generationStatus[imageJobKey(img, idx + 10)]) }}</span>
                  </el-button>
                  <el-button
                    v-if="needsLookAvatarCheck"
                    size="small"
                    :type="lookAvatarState(img) === 'passed' ? 'success' : lookAvatarState(img) === 'failed' ? 'danger' : 'warning'"
                    :loading="!!lookAvatarLoading[lookAvatarKey(img)]"
                    :disabled="locked || lookAvatarButtonDisabled(img)"
                    @click="handleDetectLookAvatar(img)"
                  >
                    <span>{{ lookAvatarButtonLabel(img) }}</span>
                  </el-button>
                  <el-button type="danger" link :disabled="locked" :loading="isDeletingAssetImage(img)" @click="removeImageRow(img)">
                    <el-icon><Delete /></el-icon>
                  </el-button>
                </div>
                <el-input v-model="img.image_prompt" type="textarea" :rows="3" :disabled="locked" :placeholder="t('这套造型的独立提示词')" />
                <div v-if="assetImageVersionsFor(img).length" class="version-panel compact">
                  <div class="version-panel__label">{{ t('造型版本库') }}</div>
                  <div class="version-strip">
                    <button
                      v-for="(version, versionIdx) in assetImageVersionsFor(img)"
                      :key="version.id"
                      type="button"
                      class="version-thumb"
                      :class="{ 'is-selected': version.is_selected }"
                      @click="openImagePreview(version.url)"
                    >
                      <img :src="version.url" alt="" />
                      <div class="version-actions">
                        <span v-if="version.is_selected">{{ assetImageVersionLabel(version, versionIdx) }}</span>
                        <template v-else>
                          <em @click.stop="selectAssetVersion(img, version)">
                            {{ selectingAssetImageVersionIds[version.id] ? t('选用中') : t('选用') }}
                          </em>
                          <i @click.stop="deleteAssetVersion(img, version)">
                            {{ deletingAssetImageVersionIds[version.id] ? t('删除中') : t('删除') }}
                          </i>
                        </template>
                      </div>
                    </button>
                  </div>
                </div>
              </div>
            </article>
            <div v-if="lookImages.length === 0" class="empty-extra-views">
              {{ hasPersistedAsset ? t('暂无人物造型，点击上方按钮添加。') : t('请先保存人物本体，再添加人物造型。') }}
            </div>
          </div>
        </section>

        <section v-if="isCharacterAsset" class="editor-panel">
          <div class="editor-section-title">
            <el-icon><Microphone /></el-icon>
            <span>{{ t('角色音色') }}</span>
          </div>
          <VoiceAssetsPanel
            v-if="hasPersistedCharacterAsset"
            :asset-id="props.asset?.id ?? 0"
            :character-name="form.name || props.asset?.name || t('未命名角色')"
            :disabled="locked"
          />
          <div v-else class="empty-extra-views">{{ t('请先保存角色资产，再上传角色音色。') }}</div>
        </section>
      </el-form>
    </div>

    <template #footer>
      <div class="asset-editor-footer">
        <el-button plain @click="visible = false">{{ t('取消') }}</el-button>
        <el-button type="primary" :loading="saving" :disabled="locked" @click="submitAsset">{{ t('保存资产') }}</el-button>
      </div>
    </template>
  </el-dialog>

  <el-dialog v-model="imagePreviewVisible" width="min(92vw, 980px)" append-to-body class="asset-image-preview-dialog">
    <div class="asset-image-preview">
      <img :src="imagePreviewUrl" alt="" />
    </div>
  </el-dialog>
</template>

<style scoped lang="scss">
.asset-editor-dialog {
  :deep(.el-dialog) {
    max-height: 90vh;
    display: flex;
    flex-direction: column;
    border: 1px solid var(--hairline);
    border-radius: 12px;
    overflow: hidden;
  }

  :deep(.el-dialog__header) {
    padding: 18px 26px 14px;
    margin: 0;
    border-bottom: 1px solid var(--hairline);
  }

  :deep(.el-dialog__title) {
    color: var(--on-dark);
    font-size: 18px;
    font-weight: 950;
  }

  :deep(.el-dialog__body) {
    min-height: 0;
    padding: 0;
    overflow: hidden;
    background: var(--surface-soft);
  }

  :deep(.el-dialog__footer) {
    padding: 14px 26px;
    border-top: 1px solid var(--hairline);
  }
}

.asset-editor-scroll {
  max-height: calc(90vh - 126px);
  padding: 18px 22px 22px;
  overflow: auto;
}

.asset-editor-form {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.editor-panel {
  padding: 16px;
  border: 1px solid var(--hairline);
  border-radius: 12px;
  background: var(--surface-card);
  box-shadow: 0 8px 22px rgba(16, 32, 51, 0.04);
}

.identity-row {
  display: grid;
  grid-template-columns: 110px minmax(0, 1fr) minmax(180px, 240px);
  gap: 8px;
  margin-bottom: 10px;
}

.identity-type,
.identity-series {
  width: 100%;
}

.core-section-title {
  margin-top: 16px;
}

.editor-section-title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;
  color: var(--on-dark);
  font-size: 13px;
  font-weight: 950;
}

.editor-section-title .add-view-btn {
  margin-left: auto;
}

.image-model-select {
  width: 180px;
  margin-left: auto;
}

.core-editor-card {
  display: grid;
  grid-template-columns: minmax(190px, 220px) minmax(0, 1fr);
  gap: 16px;
  padding: 14px;
  border: 1px solid var(--hairline);
  border-radius: 12px;
  background: var(--surface-soft);
}

.upload-progress-banner {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 10px;
  padding: 8px 10px;
  border-radius: 8px;
  background: rgba(37, 99, 235, 0.08);
  color: #1d4ed8;
  font-size: 12px;
  font-weight: 700;

  .el-progress {
    flex: 1 1 auto;
  }
}

.preview-upload-mask {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  background: rgba(15, 23, 42, 0.45);
  z-index: 2;
}

.core-editor-preview {
  position: relative;
  display: grid;
  place-items: center;
  height: 188px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-soft);
  overflow: hidden;
  cursor: pointer;
}

.core-editor-preview img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.core-editor-fields {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.view-action-row,
.reference-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.core-action-row {
  flex-wrap: wrap;
}

.ai-gen-btn {
  margin-left: auto;
  height: 30px;
  border-radius: 9px;
  font-weight: 850;
}

.core-action-row .ai-gen-btn {
  flex: 1 1 128px;
  min-width: 128px;
  margin-left: 0;
}

.core-action-row :deep(.el-button) {
  flex: 0 0 auto;
  height: 36px;
  margin-left: 0;
}

.core-action-row :deep(.el-button.is-link) {
  padding: 0 12px;
  border-radius: 9px;
}

.core-action-row :deep(.el-button--danger.is-link),
.core-action-row .core-delete-btn {
  width: 44px;
  padding: 0;
  justify-content: center;
}

.core-action-row .core-delete-btn .button-label {
  display: none;
}

.preview-copy-btn {
  position: absolute;
  top: 10px;
  right: 10px;
}

.empty-preview {
  display: grid;
  place-items: center;
  gap: 8px;
  color: var(--muted);
  font-size: 12px;
  font-weight: 850;
}

.look-section-note {
  margin: -4px 0 12px;
  color: var(--accent-amber);
  font-size: 12px;
  font-weight: 800;
}

.look-card-list {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.look-card {
  display: grid;
  grid-template-columns: minmax(180px, 240px) minmax(0, 1fr);
  gap: 14px;
  padding: 12px;
  border: 1px solid var(--hairline);
  border-radius: 12px;
  background: var(--surface-card);
  transition: border-color 0.18s ease, box-shadow 0.18s ease;
}

.look-card.is-focused {
  border-color: rgba(14, 165, 233, 0.72);
  box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.14);
}

.look-card--passed {
  border-color: rgba(52, 211, 153, 0.72);
  background: rgba(52, 211, 153, 0.08);
}

.look-card--unchecked,
.look-card--empty {
  border-color: rgba(251, 191, 36, 0.78);
  background: rgba(251, 191, 36, 0.1);
}

.look-card--failed {
  border-color: rgba(251, 113, 133, 0.78);
  background: rgba(251, 113, 133, 0.1);
}

.look-card--pending {
  border-color: rgba(96, 165, 250, 0.72);
  background: rgba(96, 165, 250, 0.08);
}

.look-card-preview {
  position: relative;
  min-height: 188px;
  display: grid;
  place-items: center;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-soft);
  overflow: hidden;
  cursor: pointer;
}

.look-card-preview img {
  width: 100%;
  height: 100%;
  object-fit: contain;
}

.look-card-body {
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-width: 0;
}

.look-status-banner {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 13px;
  line-height: 1.4;
}

.look-status-banner .el-icon {
  flex: 0 0 auto;
  font-size: 18px;
}

.look-status-banner strong {
  font-weight: 850;
}

.look-status-banner--passed {
  background: #059669;
  color: #ecfdf5;
}

.look-status-banner--unchecked,
.look-status-banner--empty {
  background: #d97706;
  color: #fffbeb;
}

.look-status-banner--failed {
  background: #e11d48;
  color: #fff1f2;
}

.look-status-banner--pending {
  background: #2563eb;
  color: #eff6ff;
}

.look-status-banner .is-loading {
  animation: look-spin 1s linear infinite;
}

@keyframes look-spin {
  to { transform: rotate(360deg); }
}

.look-card-toolbar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
}

.look-card-toolbar .el-input {
  flex: 1 1 180px;
  min-width: 140px;
}

@media (max-width: 1100px) {
  .look-card {
    grid-template-columns: 1fr;
  }

  .look-card-preview {
    min-height: 160px;
  }
}

.empty-extra-views {
  min-height: 150px;
  display: grid;
  place-items: center;
  border: 1px dashed var(--hairline);
  border-radius: 12px;
  background: var(--surface-soft);
  color: var(--muted);
  font-size: 12px;
}

.version-panel {
  margin-top: 12px;
  padding: 10px;
  border: 1px solid var(--hairline);
  border-radius: 12px;
  background: var(--surface-card);
}

.version-panel.compact {
  margin-top: 0;
  padding: 8px;
  background: var(--surface-soft);
}

.version-panel__label {
  margin-bottom: 8px;
  color: var(--muted);
  font-size: 12px;
  font-weight: 900;
}

.version-strip {
  display: flex;
  gap: 8px;
  overflow-x: auto;
  padding-bottom: 2px;
}

.version-thumb {
  position: relative;
  width: 96px;
  min-width: 96px;
  min-height: 112px;
  padding: 0;
  overflow: hidden;
  border: 1px solid var(--hairline);
  border-radius: 8px;
  background: var(--surface-soft);
  color: var(--on-dark);
  cursor: pointer;
}

.version-thumb.is-selected {
  border-color: var(--accent-cyan);
  box-shadow: 0 0 0 1px rgba(var(--brand-cyan-rgb), 0.28);
}

.version-thumb img {
  width: 100%;
  height: 76px;
  object-fit: cover;
  display: block;
}

.version-copy {
  position: absolute;
  top: 5px;
  right: 5px;
}

.version-actions {
  min-height: 36px;
  padding: 5px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 5px;
  border-top: 1px solid var(--hairline);
  background: var(--surface-card);
}

.version-actions span,
.version-actions em,
.version-actions i {
  min-width: 0;
  padding: 3px 5px;
  border-radius: 6px;
  font-size: 10px;
  font-style: normal;
  font-weight: 900;
  line-height: 1.2;
  white-space: nowrap;
}

.version-actions span {
  background: var(--surface-soft);
  color: var(--on-dark);
}

.version-actions em {
  background: var(--primary);
  color: var(--on-primary);
}

.version-actions i {
  background: #ff4d6d;
  color: #fff;
}

.version-empty {
  min-height: 64px;
  display: grid;
  place-items: center;
  border: 1px dashed var(--hairline);
  border-radius: 8px;
  color: var(--muted);
  font-size: 12px;
}

.asset-editor-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
}

.asset-image-preview {
  display: grid;
  place-items: center;
}

.asset-image-preview img {
  max-width: 100%;
  max-height: 78vh;
  object-fit: contain;
}

@media (max-width: 1100px) {
  .identity-row,
  .core-editor-card {
    grid-template-columns: 1fr;
  }

  .image-model-select {
    width: min(180px, 100%);
  }
}

@media (max-width: 680px) {
  .asset-editor-scroll {
    padding: 12px;
  }

  .editor-panel {
    padding: 12px;
  }

  .editor-section-title,
  .view-action-row,
  .reference-row,
  .asset-editor-footer {
    flex-wrap: wrap;
  }

  .image-model-select,
  .editor-section-title .add-view-btn {
    width: 100%;
    margin-left: 0;
  }

  .asset-editor-footer :deep(.el-button) {
    flex: 1 1 140px;
    margin-left: 0;
  }
}
</style>
