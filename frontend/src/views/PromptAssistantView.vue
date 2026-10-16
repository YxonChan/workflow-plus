<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { Check, Close, CopyDocument, Delete, Document, Download, Edit, Files, Loading, MagicStick, Picture, Plus, Promotion, Refresh, Setting, UploadFilled, VideoCamera } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import StylePresetSelect from '@/components/series/StylePresetSelect.vue'
import { listGrouped } from '@/api/modelConfig'
import { listAssets } from '@/api/asset'
import { createPromptTemplate } from '@/api/promptTemplate'
import { createPromptSession, deletePromptSession, getPromptSession, listPromptSessions, savePromptResult, sendPromptMessage, uploadPromptReference, type PromptReference, type PromptSession, type PromptSessionBrief, type PromptSettings, type PromptShot } from '@/api/promptAssistant'
import type { Asset, ModelConfigGroup } from '@/types'
import { VISUAL_STYLE_SEGMENT_OPTIONS } from '@/utils/visualStyle'
import { buildPromptQuickCreateDraft } from '@/utils/promptAssistantHandoff'

const { t } = useI18n()
const router = useRouter()
const sessions = ref<PromptSessionBrief[]>([])
const session = ref<PromptSession | null>(null)
const modelGroups = ref<ModelConfigGroup[]>([])
const uploadedReferences = ref<PromptReference[]>([])
const uploading = ref(false)
const uploadDragging = ref(false)
const uploadProgress = reactive({ done: 0, total: 0 })
let uploadDragDepth = 0
const uploadInput = ref<HTMLInputElement | null>(null)
const assets = ref<Asset[]>([])
const loading = ref(true)
const loadError = ref('')
const sending = ref(false)
const actionError = ref('')
const draft = ref('')
const answers = reactive<Record<number, string>>({})
const transcript = ref<HTMLElement | null>(null)
const historyOpen = ref(false)
const settingsOpen = ref(false)
const saving = ref(false)
const mobileView = ref<'conversation' | 'result'>('conversation')
const resultTab = ref<'prompt' | 'brief'>('prompt')
const selectedResultId = ref<number | null>(null)
const editing = ref(false)
const editBody = ref('')
const editShots = ref<PromptShot[]>([])
let loadSequence = 0
let pollTimer: ReturnType<typeof setInterval> | undefined
let polling = false

const defaults = (): PromptSettings => ({ mode: 'image', text_model_id: 0, vision_model_id: 0, target_model_id: 0, visual_style: 'realistic', style_preset_id: 0, aspect_ratio: '1:1', duration: 5, duration_confirmed: false, asset_ids: [], reference_ids: [] })
const settings = reactive<PromptSettings>(defaults())
const textModels = computed(() => modelGroups.value.find((group) => group.type === 'text')?.models ?? [])
const visionModels = computed(() => textModels.value.filter((model) => model.options.supports_vision === true))
const selectedReferences = computed<PromptReference[]>(() => {
  const available = [...uploadedReferences.value, ...(session.value?.references ?? [])]
  const uploads = (settings.reference_ids ?? []).flatMap((id) => {
    const reference = available.find((item) => item.kind === 'upload' && item.id === id)
    return reference ? [reference] : []
  })
  const selectedAssets = settings.asset_ids.flatMap((id) => {
    const existing = available.find((item) => item.kind === 'asset' && item.asset_id === id)
    if (existing) return [existing]
    const asset = assets.value.find((item) => item.id === id)
    const image = asset?.images.find((item) => item.url)
    return asset && image ? [{ id: image.id, kind: 'asset' as const, asset_id: id, asset_image_id: image.id, name: asset.name, url: image.url }] : []
  })
  return [...uploads, ...selectedAssets]
})
const targetModels = computed(() => modelGroups.value.find((group) => group.type === settings.mode)?.models ?? [])
const presetId = computed({ get: () => settings.style_preset_id || null, set: (value: number | null) => { settings.style_preset_id = value || 0 } })
const currentResult = computed(() => session.value?.results.find((result) => result.id === selectedResultId.value) ?? session.value?.results[0])
const busy = computed(() => sending.value || session.value?.status === 'running')
const referenceCapacity = computed(() => Math.max(0, 6 - (settings.reference_ids?.length ?? 0) - settings.asset_ids.length))
const lastMessage = computed(() => session.value?.messages[session.value.messages.length - 1])
const questions = computed(() => !busy.value && lastMessage.value?.role === 'assistant' ? lastMessage.value.questions ?? [] : [])
const needsVisionModel = computed(() => selectedReferences.value.length > 0 && !visionModels.value.length)
const canSend = computed(() => !busy.value && !uploading.value && !needsVisionModel.value && textModels.value.length > 0 && (draft.value.trim() !== '' || Object.values(answers).some(Boolean) || selectedReferences.value.length > 0))
const settingsKey = (value: PromptSettings) => JSON.stringify([value.mode, value.text_model_id, value.vision_model_id ?? 0, value.target_model_id, value.visual_style, value.style_preset_id, value.aspect_ratio, value.duration, value.duration_confirmed ?? false, value.asset_ids, value.reference_ids ?? []])
const settingsChanged = computed(() => currentResult.value && settingsKey(currentResult.value.content.settings) !== settingsKey(settings))
const statusText = computed(() => busy.value ? t('正在生成') : session.value?.status === 'failed' ? t('生成失败') : session.value?.status === 'awaiting_input' ? t('待补充') : currentResult.value ? t('已生成') : t('待填写'))
const ratioOptions = computed(() => {
  const configured = targetModels.value.find((model) => model.id === settings.target_model_id)?.options.aspect_ratio_options
  return ['adaptive', ...(Array.isArray(configured) && configured.length ? configured.map(String) : ['1:1', '9:16', '16:9', '4:3', '3:4', '21:9'])]
})
const briefLabels: Record<string, string> = { subject: '创作主体', goal: '创作目标', audience: '目标受众', channel: '使用场景', selling_points: '产品卖点', scene: '画面场景', action: '动作设计', constraints: '必须保留的要求', asset_requirements: '需要的素材' }

function clearAnswers() { Object.keys(answers).forEach((key) => delete answers[Number(key)]) }
function defaultTextId() {
  return (textModels.value.find((model) => /deepseek/i.test(`${model.name} ${model.model_id}`)) ?? textModels.value.find((model) => model.is_default) ?? textModels.value[0])?.id ?? 0
}
function changeMode(value: unknown) {
  if (value !== 'image' && value !== 'video') return
  settings.mode = value
  settings.target_model_id = 0
  settings.aspect_ratio = value === 'video' ? '9:16' : '1:1'
  settings.duration_confirmed = false
}
function setSession(value: PromptSession, resetSettings = true) {
  session.value = value
  if (resetSettings) Object.assign(settings, defaults(), value.settings)
  if (!value.results.some((result) => result.id === selectedResultId.value)) selectedResultId.value = value.results[0]?.id ?? null
  void nextTick(() => { if (transcript.value) transcript.value.scrollTop = transcript.value.scrollHeight })
}
async function refreshLists() {
  sessions.value = await listPromptSessions()
}
async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const [history, groups] = await Promise.all([listPromptSessions(), listGrouped()])
    sessions.value = history
    modelGroups.value = groups
    if (!session.value) settings.text_model_id = defaultTextId()
  } catch (error) { loadError.value = error instanceof Error ? error.message : t('提示词数据加载失败') }
  finally { loading.value = false }
}
async function openSession(id: number) {
  if (editing.value && !(await confirmDiscard())) return
  const sequence = ++loadSequence
  historyOpen.value = false
  actionError.value = ''
  draft.value = ''
  clearAnswers()
  editing.value = false
  try {
    const value = await getPromptSession(id)
    if (sequence === loadSequence) setSession(value)
  } catch (error) { if (sequence === loadSequence) actionError.value = error instanceof Error ? error.message : t('提示词数据加载失败') }
}
async function newSession() {
  if (editing.value && !(await confirmDiscard())) return
  ++loadSequence
  resetUploadDrag()
  session.value = null
  uploadedReferences.value = []
  selectedResultId.value = null
  editing.value = false
  draft.value = ''
  actionError.value = ''
  clearAnswers()
  Object.assign(settings, defaults(), { text_model_id: defaultTextId() })
  mobileView.value = 'conversation'
  historyOpen.value = false
}
async function confirmDiscard(): Promise<boolean> {
  try { await ElMessageBox.confirm(t('放弃当前未保存的编辑？'), t('未保存的编辑'), { confirmButtonText: t('放弃编辑'), cancelButtonText: t('取消') }); return true }
  catch { return false }
}
async function removeSession(id: number) {
  if (session.value?.id === id && editing.value && !(await confirmDiscard())) return
  try { await ElMessageBox.confirm(t('删除这条提示词会话及其结果版本？'), t('删除会话'), { type: 'warning', confirmButtonText: t('删除'), cancelButtonText: t('取消') }) }
  catch { return }
  await deletePromptSession(id)
  if (session.value?.id === id) { editing.value = false; await newSession() }
  await refreshLists()
}
async function send(retry = false) {
  if (busy.value || (!retry && !canSend.value)) return
  if (editing.value && !(await confirmDiscard())) return
  let message = draft.value.trim()
  let requestId = crypto.randomUUID()
  let snapshot = JSON.parse(JSON.stringify(settings)) as PromptSettings
  if (retry && session.value) {
    const lastUser = [...session.value.messages].reverse().find((entry) => entry.role === 'user')
    if (!lastUser?.request_id) return
    message = lastUser.text
    requestId = lastUser.request_id
    snapshot = JSON.parse(JSON.stringify(session.value.settings)) as PromptSettings
  } else {
    const answerText = questions.value.flatMap((question, index) => answers[index] ? [`${question.text}\n${answers[index]}`] : []).join('\n\n')
    message = [answerText, message].filter(Boolean).join('\n\n') || (selectedReferences.value.length ? t('请根据参考图整理创作需求。') : '')
  }
  if (!message) return
  sending.value = true
  actionError.value = ''
  editing.value = false
  let id = session.value?.id
  try {
    if (!id) {
      const value = await createPromptSession(snapshot)
      setSession(value)
      id = value.id
    }
    const targetId = id
    const responsePromise = sendPromptMessage(targetId, message, requestId, snapshot)
    if (session.value?.id === targetId) {
      draft.value = ''
      clearAnswers()
      if (!retry) session.value.messages.push({ role: 'user', text: message, request_id: requestId, references: [...selectedReferences.value] })
      session.value.status = 'running'
      await nextTick()
      if (transcript.value) transcript.value.scrollTop = transcript.value.scrollHeight
    }
    const value = await responsePromise
    if (session.value?.id === targetId) { setSession(value); selectedResultId.value = value.results[0]?.id ?? null }
    await refreshLists()
  } catch (error) {
    if (!id || session.value?.id === id) {
      actionError.value = error instanceof Error ? error.message : t('生成失败')
      if (!id) draft.value = message
      else {
        try {
          const recovered = await getPromptSession(id, true)
          if (!recovered.messages.some((entry) => entry.request_id === requestId)) draft.value = message
          setSession(recovered, false)
        } catch { /* Keep the pending message for recovery. */ }
      }
    }
  } finally { sending.value = false }
}
function starter(mode: 'image' | 'video', text: string) { changeMode(mode); draft.value = text }
function completeSettings() {
  if (settings.mode === 'video') settings.duration_confirmed = true
  settingsOpen.value = false
}
function removeReference(reference: PromptReference) {
  if (reference.kind === 'asset') settings.asset_ids = settings.asset_ids.filter((id) => id !== reference.asset_id)
  else settings.reference_ids = (settings.reference_ids ?? []).filter((id) => id !== reference.id)
}
async function uploadFiles(files: File[]) {
  if (busy.value || uploading.value) return
  const valid = files.filter((file) => ['image/jpeg', 'image/png', 'image/webp'].includes(file.type) && file.size <= 10 * 1024 * 1024)
  if (valid.length !== files.length) ElMessage.warning(t('请选择 10MB 以内的 JPG、PNG 或 WebP 图片'))
  const capacity = referenceCapacity.value
  if (valid.length > capacity) ElMessage.warning(t('最多引用 6 个参考素材'))
  const batch = valid.slice(0, capacity)
  if (!batch.length) { if (uploadInput.value) uploadInput.value.value = ''; return }
  uploading.value = true
  uploadProgress.done = 0
  uploadProgress.total = batch.length
  const sequence = loadSequence
  try {
    for (const file of batch) {
      if (sequence !== loadSequence) return
      try {
        const reference = await uploadPromptReference(file)
        if (sequence !== loadSequence) return
        uploadedReferences.value.push(reference)
        settings.reference_ids = [...(settings.reference_ids ?? []), reference.id]
        if (!settings.vision_model_id) settings.vision_model_id = visionModels.value.find((model) => model.id === settings.text_model_id)?.id ?? visionModels.value[0]?.id ?? 0
      } catch {
        if (sequence !== loadSequence) return
        ElMessage.error(t('图片 {name} 上传失败，请重新添加', { name: file.name }))
      } finally {
        uploadProgress.done++
      }
    }
  } finally { uploading.value = false; if (uploadInput.value) uploadInput.value.value = '' }
}
function onPaste(event: ClipboardEvent) {
  const files = Array.from(event.clipboardData?.files ?? []).filter((file) => file.type.startsWith('image/'))
  if (files.length) { event.preventDefault(); void uploadFiles(files) }
}
function isFileDrag(event: DragEvent): boolean {
  return Array.from(event.dataTransfer?.types ?? []).includes('Files')
}
function resetUploadDrag() { uploadDragDepth = 0; uploadDragging.value = false }
function onDragEnter(event: DragEvent) {
  if (!isFileDrag(event)) return
  event.preventDefault()
  uploadDragDepth++
  uploadDragging.value = true
}
function onDragOver(event: DragEvent) {
  if (!isFileDrag(event)) return
  event.preventDefault()
  if (event.dataTransfer) event.dataTransfer.dropEffect = busy.value || uploading.value || !referenceCapacity.value ? 'none' : 'copy'
}
function onDragLeave(event: DragEvent) {
  if (!isFileDrag(event)) return
  uploadDragDepth = Math.max(0, uploadDragDepth - 1)
  if (!uploadDragDepth) uploadDragging.value = false
}
function onDrop(event: DragEvent) {
  resetUploadDrag()
  if (event.dataTransfer?.files.length) { event.preventDefault(); void uploadFiles(Array.from(event.dataTransfer.files)) }
}
async function openSettings() {
  settingsOpen.value = true
  try { assets.value = await listAssets({ scope: 'mine' }) }
  catch { ElMessage.error(t('参考素材加载失败')) }
}
watch(() => settings.visual_style, (value, previous) => {
  if (value !== previous && settingsOpen.value) settings.style_preset_id = 0
})
watch(() => settings.target_model_id, () => {
  if (!ratioOptions.value.includes(settings.aspect_ratio)) settings.aspect_ratio = ratioOptions.value[0] ?? 'adaptive'
})
watch(() => currentResult.value?.id, () => {
  editing.value = false
  editBody.value = currentResult.value?.content.prompt_body ?? ''
  editShots.value = (currentResult.value?.content.shots ?? []).map((shot) => ({ title: shot.title, duration: shot.duration, prompt_body: shot.prompt_body }))
})
function currentBody(): string { return editing.value ? editBody.value : currentResult.value?.content.prompt_body ?? '' }
async function toggleEditing() {
  if (editing.value && !(await confirmDiscard())) return
  editing.value = !editing.value
  editBody.value = currentResult.value?.content.prompt_body ?? ''
  editShots.value = (currentResult.value?.content.shots ?? []).map((shot) => ({ title: shot.title, duration: shot.duration, prompt_body: shot.prompt_body }))
}
function exportText(): string {
  const content = currentResult.value?.content
  if (!content) return ''
  const shots = editing.value ? editShots.value : content.shots
  return [content.style_block, buildPromptQuickCreateDraft(content, currentBody(), shots).prompt_body].filter(Boolean).join('\n\n')
}
async function copy(text: string) {
  try { await navigator.clipboard.writeText(text); ElMessage.success(t('已复制')) }
  catch { ElMessage.error(t('复制失败，请检查浏览器权限')) }
}
function download(text: string, filename: string) {
  const url = URL.createObjectURL(new Blob([text], { type: 'text/plain;charset=utf-8' }))
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  link.click()
  URL.revokeObjectURL(url)
}
async function saveEdits() {
  if (!session.value || !currentResult.value) return
  saving.value = true
  try {
    const value = await savePromptResult(session.value.id, currentResult.value.id, { prompt_body: editBody.value, shots: editShots.value })
    selectedResultId.value = null
    setSession(value)
    editing.value = false
    ElMessage.success(t('已保存新版本'))
  } finally { saving.value = false }
}
async function saveTemplate() {
  const result = currentResult.value
  if (!result || saving.value) return
  saving.value = true
  try {
    const shots = editing.value ? editShots.value : result.content.shots
    const prompt = buildPromptQuickCreateDraft(result.content, currentBody(), shots).prompt_body
    await createPromptTemplate({ title: result.content.title, scope: 'all', node_kind: result.content.settings.mode, node_label: '', description: '', prompt, tags: ['prompt-assistant'] })
    ElMessage.success(t('已保存到提示词模板'))
  } finally { saving.value = false }
}
function useInQuickCreate(shot?: PromptShot) {
  const result = currentResult.value
  if (!result) return
  const id = crypto.randomUUID()
  const shots = editing.value ? editShots.value : result.content.shots
  sessionStorage.setItem(`prompt-assistant:draft:${id}`, JSON.stringify(buildPromptQuickCreateDraft(result.content, currentBody(), shots, shot)))
  void router.push({ name: 'quickCreate', query: { prompt_draft: id } })
}
onMounted(() => {
  void load()
  pollTimer = setInterval(async () => {
    const id = session.value?.id
    if (!id || session.value?.status !== 'running' || polling) return
    polling = true
    try {
      const value = await getPromptSession(id, true)
      if (session.value?.id === id) setSession(value, false)
    } catch { /* The send request reports errors; polling remains silent. */ }
    finally { polling = false }
  }, 3000)
})
onBeforeUnmount(() => { ++loadSequence; if (pollTimer) clearInterval(pollTimer) })
</script>

<template>
  <div class="prompt-page">
    <PageToolbar :title="t('提示词助手')">
      <template #meta><StatusBadge :tone="session?.status === 'failed' ? 'fail' : busy ? 'busy' : 'idle'" :pulse="busy">{{ statusText }}</StatusBadge></template>
      <template #actions>
        <el-button class="history-command" :icon="Files" @click="historyOpen = true">{{ t('会话记录') }}</el-button>
        <el-button type="primary" :icon="Plus" @click="newSession">{{ t('新建提示词') }}</el-button>
      </template>
    </PageToolbar>
    <div v-if="loadError" class="load-failure"><el-alert :title="loadError" type="error" :closable="false" /><el-button :icon="Refresh" @click="load">{{ t('重新加载') }}</el-button></div>
    <div v-if="!loading && !textModels.length && !loadError" class="model-warning"><el-alert :title="t('没有可用的文本模型，请联系管理员配置')" type="warning" :closable="false" /></div>
    <div class="mobile-view-switch"><el-segmented v-model="mobileView" :options="[{ label: t('需求对话'), value: 'conversation' }, { label: t('提示词结果'), value: 'result' }]" /></div>
    <div v-loading="loading" class="prompt-workspace" :class="`mobile-${mobileView}`">
      <aside class="session-sidebar">
        <div class="section-heading"><span>{{ t('会话记录') }}</span><span class="count-label">{{ sessions.length }}</span></div>
        <div class="session-list scrollable">
          <div v-for="item in sessions" :key="item.id" class="session-row" :class="{ active: session?.id === item.id }">
            <button class="session-open" @click="openSession(item.id)"><el-icon><Picture v-if="item.mode === 'image'" /><VideoCamera v-else /></el-icon><span><strong>{{ item.title }}</strong><small>{{ item.update_time }}</small></span></button>
            <el-tooltip :content="t('删除会话')"><el-button text :icon="Delete" :aria-label="t('删除会话')" :disabled="item.status === 'running'" @click="removeSession(item.id)" /></el-tooltip>
          </div>
          <div v-if="!sessions.length" class="small-empty">{{ t('暂无会话记录') }}</div>
        </div>
      </aside>
      <section class="conversation-area" @dragenter="onDragEnter" @dragover="onDragOver" @dragleave="onDragLeave" @drop="onDrop" @dragend="resetUploadDrag">
        <div class="conversation-toolbar">
          <el-segmented :model-value="settings.mode" :disabled="busy" :options="[{ label: t('图片'), value: 'image' }, { label: t('视频'), value: 'video' }]" @update:model-value="changeMode" />
          <span v-if="settings.mode === 'video'" class="duration-status">{{ settings.duration_confirmed ? `${settings.duration}${t('秒')}` : t('时长待确认') }}</span>
          <el-tooltip :content="t('生成设置')"><el-button :icon="Setting" :aria-label="t('生成设置')" :disabled="busy" @click="openSettings" /></el-tooltip>
        </div>
        <div ref="transcript" class="transcript scrollable" aria-live="polite">
          <div v-if="!session?.messages.length" class="conversation-empty">
            <el-icon class="empty-icon"><MagicStick /></el-icon>
            <h2>{{ t('这次想创作什么？') }}</h2>
            <div class="starter-list">
              <button @click="starter('image', t('为我的产品制作一张电商展示图'))"><el-icon><Picture /></el-icon><span>{{ t('产品展示图片') }}</span><el-icon><Plus /></el-icon></button>
              <button @click="starter('video', t('把产品卖点做成一段竖屏广告视频'))"><el-icon><VideoCamera /></el-icon><span>{{ t('产品广告视频') }}</span><el-icon><Plus /></el-icon></button>
              <button @click="starter('video', t('为我的软件服务制作一段宣传视频'))"><el-icon><Document /></el-icon><span>{{ t('软件服务宣传片') }}</span><el-icon><Plus /></el-icon></button>
            </div>
          </div>
          <article v-for="(message, index) in session?.messages ?? []" :key="`${index}:${message.role}`" class="message" :class="message.role">
            <div class="message-author"><el-icon v-if="message.role === 'assistant'"><MagicStick /></el-icon>{{ t(message.role === 'user' ? '我' : '提示词助手') }}</div>
            <div class="message-text">{{ message.text }}</div>
            <div v-if="message.references?.length" class="reference-strip message-references"><div v-for="(reference, referenceIndex) in message.references" :key="`${reference.kind}:${reference.id}`" class="reference-item"><el-image :src="reference.url" :alt="reference.name" fit="contain" :preview-src-list="message.references.map((item) => item.url)" preview-teleported /><span class="reference-label">@{{ t('图片') }}{{ referenceIndex + 1 }}</span></div></div>
            <div v-if="index === (session?.messages.length ?? 0) - 1 && message.role === 'assistant' && !busy" class="questions">
              <fieldset v-for="(question, questionIndex) in questions" :key="questionIndex"><legend>{{ question.text }}</legend><el-radio-group v-model="answers[questionIndex]"><el-radio v-for="option in question.options" :key="option" :value="option">{{ option }}</el-radio></el-radio-group></fieldset>
            </div>
          </article>
          <div v-if="busy" class="generating"><span class="activity-dot" />{{ t('正在整理需求与提示词') }}</div>
        </div>
        <div class="composer" @paste="onPaste">
          <el-alert v-if="actionError || session?.error_message" :title="actionError || session?.error_message" type="error" :closable="false" />
          <el-button v-if="session?.status === 'failed'" :icon="Refresh" :disabled="busy" @click="send(true)">{{ t('重试本次消息') }}</el-button>
          <el-alert v-if="needsVisionModel" :title="t('当前没有可用的图片理解模型，请联系管理员启用图片输入能力')" type="warning" :closable="false" />
          <div class="composer-input" :class="{ 'is-dragging': uploadDragging, 'is-busy': busy }" :aria-busy="uploading || busy">
            <div v-if="selectedReferences.length || uploading" class="reference-strip composer-references">
              <div v-for="(reference, referenceIndex) in selectedReferences" :key="`${reference.kind}:${reference.id}`" class="reference-item">
                <el-image :src="reference.url" :alt="reference.name" fit="contain" :preview-src-list="selectedReferences.map((item) => item.url)" preview-teleported />
                <span class="reference-label">@{{ t('图片') }}{{ referenceIndex + 1 }}</span>
                <el-tooltip :content="t('移除参考图')"><el-button :icon="Close" :aria-label="t('移除参考图')" :disabled="busy || uploading" @click="removeReference(reference)" /></el-tooltip>
              </div>
              <div v-if="uploading" class="reference-uploading" role="status"><el-icon class="is-loading"><Loading /></el-icon><span>{{ uploadProgress.done }}/{{ uploadProgress.total }}</span></div>
            </div>
            <el-input v-model="draft" type="textarea" :rows="3" :maxlength="6000" :disabled="busy" :placeholder="t('描述你想创作的内容，或补充需求…')" :aria-label="t('创作需求')" />
            <input ref="uploadInput" class="reference-file-input" type="file" accept="image/jpeg,image/png,image/webp" multiple :aria-label="t('上传参考图')" :disabled="busy || uploading" @change="uploadFiles(Array.from(uploadInput?.files ?? []))" />
            <div class="composer-footer">
              <div class="composer-tools">
                <el-tooltip :content="t('上传参考图')"><el-button class="composer-tool" text :icon="Picture" :aria-label="t('上传参考图')" :loading="uploading" :disabled="busy || uploading || !referenceCapacity" @click="uploadInput?.click()" /></el-tooltip>
                <span v-if="selectedReferences.length" class="composer-reference-count">{{ selectedReferences.length }}/6</span>
                <span v-if="uploading" class="composer-upload-status" role="status">{{ t('正在上传 {done}/{total}', uploadProgress) }}</span>
              </div>
              <el-tooltip :content="questions.length ? t('提交回答') : t('发送')"><el-button class="composer-send" type="primary" :icon="Promotion" :aria-label="questions.length ? t('提交回答') : t('发送')" :loading="sending" :disabled="!canSend" @click="send(false)" /></el-tooltip>
            </div>
            <div v-if="uploadDragging" class="composer-drop-overlay" aria-live="polite"><el-icon><UploadFilled /></el-icon><span>{{ busy || uploading ? t('请等待当前操作完成') : !referenceCapacity ? t('最多引用 6 个参考素材') : t('松开即可添加图片') }}</span></div>
          </div>
        </div>
      </section>
      <section class="result-area">
        <div class="section-heading"><span>{{ t('提示词结果') }}</span><el-select v-if="session?.results.length" v-model="selectedResultId" class="version-select" :disabled="editing || busy" :aria-label="t('提示词版本')"><el-option v-for="result in session.results" :key="result.id" :value="result.id" :label="t('版本 {version}', { version: result.version })" /></el-select></div>
        <div v-if="!currentResult" class="result-empty"><el-icon><Document /></el-icon><span>{{ t('暂无提示词结果') }}</span></div>
        <template v-else>
          <div class="result-tabs"><button :class="{ active: resultTab === 'prompt' }" @click="resultTab = 'prompt'">{{ t('提示词') }}</button><button :class="{ active: resultTab === 'brief' }" @click="resultTab = 'brief'">{{ t('需求摘要') }}</button></div>
          <div class="result-content scrollable">
            <el-alert v-if="settingsChanged" :title="t('设置已更改，当前结果仍使用原设置')" type="warning" :closable="false" />
            <template v-if="resultTab === 'prompt'">
              <div class="result-title"><h2>{{ currentResult.content.title }}</h2></div>
              <div class="result-tools">
                <el-tooltip :content="t('复制完整提示词')"><el-button :icon="CopyDocument" :aria-label="t('复制完整提示词')" @click="copy(exportText())" /></el-tooltip>
                <el-tooltip :content="t('导出提示词')"><el-button :icon="Download" :aria-label="t('导出提示词')" @click="download(exportText(), 'prompt.txt')" /></el-tooltip>
                <el-tooltip :content="t('编辑提示词')"><el-button :icon="Edit" :aria-label="t('编辑提示词')" :disabled="busy" @click="toggleEditing" /></el-tooltip>
                <el-button v-if="editing" type="primary" :icon="Check" :loading="saving" @click="saveEdits">{{ t('保存版本') }}</el-button>
              </div>
              <el-input v-if="editing" v-model="editBody" type="textarea" :autosize="{ minRows: 8, maxRows: 20 }" :maxlength="16000" :aria-label="t('提示词正文')" />
              <pre v-else class="prompt-text">{{ currentResult.content.prompt_body }}</pre>
              <div v-for="(shot, index) in (editing ? editShots : currentResult.content.shots)" :key="index" class="shot-item"><div class="shot-heading"><strong>{{ index + 1 }}. {{ shot.title }}</strong><span>{{ shot.duration }}s</span></div><el-input v-if="editing" v-model="shot.prompt_body" type="textarea" :autosize="{ minRows: 4, maxRows: 12 }" :maxlength="5000" :aria-label="t('分镜提示词')" /><pre v-else class="prompt-text">{{ shot.prompt_body }}</pre><el-button v-if="!editing" link type="primary" @click="useInQuickCreate(shot)">{{ t('在速创中使用此镜头') }}</el-button></div>
              <div v-if="currentResult.content.references?.length" class="result-references"><h3>{{ t('参考图') }}</h3><div class="reference-strip"><div v-for="(reference, referenceIndex) in currentResult.content.references" :key="`${reference.kind}:${reference.id}`" class="reference-item"><el-image :src="reference.url" :alt="reference.name" fit="contain" :preview-src-list="currentResult.content.references.map((item) => item.url)" preview-teleported /><span class="reference-label">@{{ t('图片') }}{{ referenceIndex + 1 }}</span></div></div></div>
            </template>
            <dl v-else class="brief-list"><template v-for="(value, key) in currentResult.content.brief" :key="key"><dt>{{ t(briefLabels[key] || key) }}</dt><dd>{{ value || '--' }}</dd></template><dt>{{ t('画风') }}</dt><dd>{{ t(VISUAL_STYLE_SEGMENT_OPTIONS.find((option) => option.value === currentResult?.content.settings.visual_style)?.label || '写实') }}</dd><dt>{{ t('交付规格') }}</dt><dd>{{ currentResult.content.settings.aspect_ratio }}<span v-if="currentResult.content.settings.mode === 'video'"> / {{ currentResult.content.settings.duration }}s</span></dd></dl>
          </div>
          <div class="result-footer"><el-button :icon="Document" :loading="saving" @click="saveTemplate">{{ t('保存模板') }}</el-button><el-button type="primary" :icon="MagicStick" @click="useInQuickCreate()">{{ t(currentResult.content.shots.length ? '在速创中使用完整视频' : '在速创中使用') }}</el-button></div>
        </template>
      </section>
    </div>
    <el-drawer v-model="historyOpen" :title="t('会话记录')" direction="ltr" size="min(360px, 90vw)"><div class="session-list"><div v-for="item in sessions" :key="item.id" class="session-row" :class="{ active: session?.id === item.id }"><button class="session-open" @click="openSession(item.id)"><span><strong>{{ item.title }}</strong><small>{{ item.update_time }}</small></span></button><el-button text :icon="Delete" :aria-label="t('删除会话')" :disabled="item.status === 'running'" @click="removeSession(item.id)" /></div><div v-if="!sessions.length" class="small-empty">{{ t('暂无会话记录') }}</div></div></el-drawer>
    <el-dialog v-model="settingsOpen" :title="t('生成设置')" width="min(520px, 94vw)" :close-on-click-modal="false">
      <el-form label-position="top">
        <el-form-item :label="t('决策模型')"><el-select v-model="settings.text_model_id" :disabled="busy"><el-option v-for="model in textModels" :key="model.id" :label="model.name" :value="model.id" /></el-select></el-form-item>
        <el-form-item :label="t('图片理解模型')"><el-select v-model="settings.vision_model_id" :disabled="busy"><el-option :value="0" :label="t('自动选择')" /><el-option v-for="model in visionModels" :key="model.id" :label="model.name" :value="model.id" /></el-select></el-form-item>
        <el-form-item :label="t('目标生成模型')"><el-select v-model="settings.target_model_id"><el-option :value="0" :label="t('通用提示词')" /><el-option v-for="model in targetModels" :key="model.id" :label="model.name" :value="model.id" /></el-select></el-form-item>
        <el-form-item :label="t('主画风')"><el-segmented v-model="settings.visual_style" :options="VISUAL_STYLE_SEGMENT_OPTIONS.map((option) => ({ value: option.value, label: t(option.label) }))" /></el-form-item>
        <el-form-item :label="t('风格预设')"><StylePresetSelect v-model="presetId" :visual-style="settings.visual_style" /></el-form-item>
        <div class="settings-grid"><el-form-item :label="t('画幅比例')"><el-select v-model="settings.aspect_ratio"><el-option v-for="ratio in ratioOptions" :key="ratio" :value="ratio" :label="ratio === 'adaptive' ? t('智能比例') : ratio" /></el-select></el-form-item><el-form-item v-if="settings.mode === 'video'" :label="t('总时长（秒）')"><el-input-number v-model="settings.duration" :min="1" :max="180" :precision="0" @change="settings.duration_confirmed = true" /></el-form-item></div>
        <el-form-item :label="t('参考素材')"><el-select v-model="settings.asset_ids" multiple filterable :multiple-limit="6"><el-option v-for="asset in assets" :key="asset.id" :value="asset.id" :label="asset.name" /></el-select></el-form-item>
      </el-form><template #footer><el-button type="primary" @click="completeSettings">{{ t('完成') }}</el-button></template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.prompt-page { height: 100%; min-height: 0; display: flex; flex-direction: column; }
.prompt-workspace { flex: 1; min-height: 0; display: grid; grid-template-columns: 210px minmax(0, 1fr) 370px; }
.session-sidebar, .result-area { background: var(--surface-soft); min-width: 0; min-height: 0; display: flex; flex-direction: column; }
.session-sidebar { border-right: 1px solid var(--hairline); }
.section-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; min-height: 60px; padding: 12px 18px; border-bottom: 1px solid var(--hairline); color: var(--body-strong); font-weight: 600; }
.count-label { color: var(--muted); font-size: 12px; }
.session-list { padding: 8px; flex: 1; }
.session-row { display: flex; align-items: center; border-radius: 6px; margin-bottom: 4px; }
.session-row.active, .session-row:hover { background: var(--surface-elevated); }
.session-row.active { box-shadow: inset 2px 0 var(--primary); }
.session-open { background: none; border: 0; padding: 12px 8px; color: var(--body); display: flex; align-items: flex-start; gap: 9px; flex: 1; min-width: 0; text-align: left; cursor: pointer; font: inherit; }
.session-open > span { min-width: 0; flex: 1; }
.session-open strong { display: block; white-space: nowrap; text-overflow: ellipsis; overflow: hidden; font-weight: 500; font-size: 13px; }
.session-open small { display: block; color: var(--muted); margin-top: 4px; font-size: 11px; }
.session-row :deep(.el-button) { padding: 6px; width: 28px; flex-shrink: 0; }
.small-empty { padding: 28px 12px; color: var(--muted); font-size: 13px; text-align: center; }
.conversation-area { min-width: 0; min-height: 0; display: flex; flex-direction: column; }
.conversation-toolbar { min-height: 60px; display: flex; align-items: center; justify-content: space-between; padding: 12px 24px; border-bottom: 1px solid var(--hairline); }
.duration-status { margin-left: auto; margin-right: 12px; color: var(--muted); font-size: 12px; }
.transcript { flex: 1; min-height: 0; padding: 28px 28px 18px; }
.conversation-empty { min-height: 260px; height: 100%; max-width: 430px; margin: auto; display: flex; flex-direction: column; justify-content: center; }
.empty-icon { font-size: 26px; color: var(--accent-cyan); margin-bottom: 12px; }
.conversation-empty h2 { font-size: 20px; color: var(--on-dark); margin: 0 0 24px; }
.starter-list { display: grid; gap: 8px; }
.starter-list button { display: flex; align-items: center; gap: 12px; padding: 14px; border: 1px solid var(--hairline); border-radius: 6px; background: var(--surface-soft); color: var(--body); font: inherit; cursor: pointer; text-align: left; }
.starter-list button:hover { border-color: var(--hairline-strong); background: var(--surface-elevated); }
.starter-list button span { flex: 1; min-width: 0; }
.starter-list .el-icon { flex-shrink: 0; color: var(--muted); }
.message { margin-bottom: 24px; max-width: 100%; }
.message-author { display: flex; gap: 8px; align-items: center; font-size: 12px; color: var(--muted); margin-bottom: 8px; }
.message-author .el-icon { color: var(--accent-cyan); }
.message-text { white-space: pre-wrap; overflow-wrap: anywhere; line-height: 1.8; }
.message.user { padding: 14px 16px; border-radius: 6px; background: var(--surface-elevated); margin-left: 32px; }
.questions { display: grid; gap: 18px; margin-top: 18px; }
.questions fieldset { margin: 0; padding: 0; border: 0; min-width: 0; }
.questions legend { font-size: 13px; color: var(--body-strong); margin-bottom: 8px; }
.questions :deep(.el-radio-group) { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
.questions :deep(.el-radio) { height: auto; min-height: 28px; margin-right: 0; max-width: 100%; }
.questions :deep(.el-radio__label) { white-space: normal; overflow-wrap: anywhere; }
.generating { display: flex; gap: 10px; align-items: center; color: var(--muted); font-size: 13px; padding: 12px 0; }
.activity-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--accent-cyan); animation: pulse 1.4s ease-in-out infinite; }
@keyframes pulse { 50% { opacity: 0.3; } }
.composer { display: grid; gap: 10px; padding: 14px 24px 20px; }
.composer-input { position: relative; min-width: 0; border: 1px solid var(--hairline-strong); border-radius: 8px; background: var(--surface-soft); transition: border-color 120ms ease, box-shadow 120ms ease; }
.composer-input:focus-within { border-color: var(--primary-active); box-shadow: 0 0 0 2px rgba(var(--primary-rgb), 0.08); }
.composer-input.is-busy { opacity: 0.7; }
.composer-input :deep(.el-textarea__inner) { min-height: 84px !important; max-height: 180px; padding: 14px 16px 6px; border: 0 !important; border-radius: 8px 8px 0 0; background: transparent !important; box-shadow: none !important; resize: none; line-height: 1.7; }
.composer-input :deep(.el-textarea__inner:focus) { border: 0 !important; outline: none; box-shadow: none !important; }
.composer-footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 6px 10px 10px; min-height: 48px; }
.composer-tools { display: flex; align-items: center; gap: 6px; min-width: 0; }
.composer-tool.el-button { width: 32px; height: 32px; min-height: 32px; padding: 0; border-radius: 6px; color: var(--muted); }
.composer-send.el-button { flex: 0 0 34px; width: 34px; height: 34px; min-height: 34px; padding: 0; border-radius: 6px; }
.composer-send.el-button.is-disabled { background: var(--surface-elevated); border-color: var(--hairline); color: var(--muted-soft); }
.composer-reference-count, .composer-upload-status { color: var(--muted); font-size: 12px; line-height: 20px; }
.composer-upload-status { overflow-wrap: anywhere; }
.composer-drop-overlay { position: absolute; z-index: 5; inset: -1px; border: 1px dashed var(--accent-cyan); border-radius: 8px; background: var(--surface-elevated); display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; color: var(--body-strong); pointer-events: none; font-size: 13px; }
.composer-drop-overlay > .el-icon { color: var(--accent-cyan); font-size: 26px; }
.reference-file-input { display: none; }
.reference-strip { display: flex; flex-wrap: wrap; gap: 8px; }
.reference-strip :deep(.el-image) { width: 64px; height: 64px; flex: 0 0 64px; background: var(--surface-elevated); border: 1px solid var(--hairline); border-radius: 6px; }
.reference-item { position: relative; width: 64px; height: 64px; }
.reference-label { position: absolute; left: 1px; right: 1px; bottom: 1px; padding: 2px; background: var(--surface-elevated); color: var(--text); font-size: 11px; line-height: 16px; text-align: center; pointer-events: none; border-radius: 0 0 5px 5px; }
.reference-item :deep(.el-button) { position: absolute; right: -5px; top: -5px; width: 20px; height: 20px; min-height: 20px; padding: 2px; border-radius: 50%; }
.composer-references { max-height: 150px; overflow-y: auto; padding: 14px 16px 2px; }
.reference-uploading { width: 64px; height: 64px; border: 1px dashed var(--hairline-strong); border-radius: 6px; display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 6px; color: var(--muted); font-size: 11px; }
.message-references { margin-top: 12px; }
.result-references { margin-top: 24px; }
.result-references h3 { font-size: 13px; color: var(--muted); }
.result-area { border-left: 1px solid var(--hairline); }
.version-select { width: 112px; }
.result-tabs { display: flex; gap: 20px; padding: 0 20px; border-bottom: 1px solid var(--hairline); }
.result-tabs button { background: none; border: 0; border-bottom: 2px solid transparent; padding: 12px 0; font: inherit; color: var(--muted); cursor: pointer; }
.result-tabs button.active { color: var(--body-strong); border-bottom-color: var(--primary); }
.result-empty { flex: 1; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 14px; color: var(--muted); font-size: 13px; }
.result-empty .el-icon { font-size: 28px; color: var(--muted-soft); }
.result-content { flex: 1; padding: 20px; min-height: 0; }
.result-title { display: flex; flex-wrap: wrap; align-items: center; gap: 10px; margin-bottom: 16px; }
.result-title h2 { margin: 0; color: var(--body-strong); font-size: 16px; line-height: 1.5; overflow-wrap: anywhere; }
.result-tools { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 18px; }
.result-tools :deep(.el-button + .el-button) { margin-left: 0; }
.prompt-text { white-space: pre-wrap; overflow-wrap: anywhere; margin: 0; font: inherit; line-height: 1.8; }
.shot-item { border-top: 1px solid var(--hairline); padding-top: 18px; margin-top: 22px; }
.shot-heading { display: flex; justify-content: space-between; gap: 10px; margin-bottom: 12px; }
.shot-heading strong { font-size: 13px; }
.shot-heading span { color: var(--muted); font-size: 12px; flex-shrink: 0; }
.result-footer { display: flex; flex-wrap: wrap; gap: 8px; padding: 16px 20px; border-top: 1px solid var(--hairline); }
.result-footer :deep(.el-button + .el-button) { margin-left: 0; }
.brief-list { margin: 0; }
.brief-list dt { color: var(--muted); font-size: 12px; margin-bottom: 6px; }
.brief-list dd { margin: 0 0 20px; white-space: pre-wrap; overflow-wrap: anywhere; }
.load-failure, .model-warning { padding: 12px 24px; }
.load-failure { display: flex; gap: 12px; }
.settings-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.settings-grid :deep(.el-input-number) { width: 100%; }
.mobile-view-switch, .history-command { display: none; }
button:focus-visible { outline: 2px solid var(--accent-cyan); outline-offset: 2px; }
@media (max-width: 1280px) { .session-sidebar { display: none; } .prompt-workspace { grid-template-columns: minmax(0, 1fr) 340px; } .history-command { display: inline-flex; } }
@media (max-width: 980px) {
  .prompt-workspace { display: flex; }
  .conversation-area, .result-area { flex: 1; }
  .mobile-conversation .result-area, .mobile-result .conversation-area { display: none; }
  .mobile-view-switch { display: block; padding: 10px 16px; border-bottom: 1px solid var(--hairline); }
  .mobile-view-switch :deep(.el-segmented) { width: 100%; }
  .result-area { border-left: 0; }
}
@media (max-width: 760px) {
  .prompt-page :deep(.page-toolbar) { gap: 10px; }
  .prompt-page :deep(.page-toolbar__meta) { position: absolute; top: 20px; right: 16px; }
  .prompt-page :deep(.page-toolbar__actions) { gap: 6px; }
  .prompt-page :deep(.page-toolbar__actions .el-button) { margin: 0; padding: 8px 10px; font-size: 12px; }
  .conversation-toolbar { padding: 10px 16px; min-height: 52px; }
  .transcript { padding: 20px 16px; }
  .composer { padding: 12px 16px; }
  .conversation-empty { min-height: 240px; }
  .conversation-empty h2 { font-size: 18px; }
  .message.user { margin-left: 16px; }
  .section-heading { padding: 12px 16px; }
  .result-content { padding: 16px; }
}
@media (prefers-reduced-motion: reduce) { .activity-dot { animation: none; } .composer-input { transition: none; } }
</style>
