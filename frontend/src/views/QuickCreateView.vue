<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { useI18n } from 'vue-i18n'
import { listAssets } from '@/api/asset'
import { listSeries } from '@/api/series'
import { listGrouped } from '@/api/modelConfig'
import {
  listQuickCreateMessages,
  pollQuickCreateMessages,
  editQuickCreateMessage,
  sendQuickCreateMessage,
  type QuickCreateAssetRef,
  type QuickCreateMessage,
  type QuickCreateMode,
} from '@/api/quickCreate'
import { request } from '@/api/http'
import type { Asset, AssetImage, ModelConfig, ModelConfigGroup, Series } from '@/types'
import { resolveIcon } from '@/utils/iconRegistry'
import {
  isMiniMaxVideoModel,
  pickAllowedResolution,
  videoAspectRatioOptions as resolveVideoAspectRatioOptions,
  videoResolutionOptions as resolveVideoResolutionOptions,
} from '@/utils/videoModelOptions'
import { useAuthStore } from '@/stores/auth'

const HISTORY_PAGE_SIZE = 10

const { t } = useI18n()
const authStore = useAuthStore()

// ── 会话状态 ─────────────────────────────────────────────────────────────────
const messages = ref<QuickCreateMessage[]>([])
const loadingHistory = ref(false)
const loadingMoreHistory = ref(false)
const historyHasMore = ref(false)
const historyOldestId = ref(0)
let historyTopTriggerArmed = true
let historyBottomTriggerArmed = true
const sending = ref(false)
const SEND_DEBOUNCE_MS = 2000
const sendCooldown = ref(false)
let sendCooldownTimer: ReturnType<typeof setTimeout> | null = null
const streamEl = ref<HTMLElement | null>(null)
/** 视频加载阶段：idle 不请求；preview 拉元数据出首帧；ready 显示控件可播放 */
type QcVideoStage = 'idle' | 'preview' | 'ready'
const videoStages = ref<Record<string, QcVideoStage>>({})
let videoObserver: IntersectionObserver | null = null

// ── 图片编辑/重新生成 ────────────────────────────────────────────────────────
const editDialogVisible = ref(false)
const editInstruction = ref('')
const editTarget = ref<{ message: QuickCreateMessage; resultIndex: number; url: string } | null>(null)
const editSubmitting = ref(false)
const imageActionKey = ref('')
const referencePreviewVisible = ref(false)
const referencePreview = ref<PendingRef | null>(null)
interface QcReferenceHoverPreview {
  url: string
  mediaType: 'image' | 'video'
  name: string
  top: number
  left: number
}
const referenceHoverPreview = ref<QcReferenceHoverPreview | null>(null)
let referenceHoverHideTimer: ReturnType<typeof setTimeout> | null = null

// ── 输入状态 ─────────────────────────────────────────────────────────────────
const mode = ref<QuickCreateMode>('video')
const promptText = ref('')
const inputEl = ref<HTMLElement | null>(null)

interface PendingRef {
  key: string
  kind: 'asset' | 'upload'
  asset_id?: number
  asset_image_id?: number
  name: string
  type: string
  url: string
  media_type: 'image' | 'video'
  duration_seconds?: number
}
const pendingRefs = ref<PendingRef[]>([])
const pendingRefImageUrls = computed(() => pendingRefs.value.filter((r) => r.media_type === 'image').map((r) => r.url))
let isComposingPrompt = false
let skipPendingRefRender = false

watch(pendingRefs, () => {
  if (isComposingPrompt || skipPendingRefRender) return
  const root = inputEl.value
  const shouldRestoreCaret = Boolean(root && document.activeElement === root)
  const caretPosition = shouldRestoreCaret ? editorCaretOffset() : undefined
  void nextTick(() => renderPromptEditor(caretPosition))
}, { deep: true })

const uploadInputRef = ref<HTMLInputElement | null>(null)
const uploadDragging = ref(false)
const uploadDragDepth = ref(0)
const uploadingReferences = ref(false)
const draggedPendingRefKey = ref<string | null>(null)
const MAX_PENDING_REFS = 6
const MAX_UPLOAD_SIZE = 100 * 1024 * 1024
const MAX_UPLOAD_SIZE_MB = 100
const ACCEPTED_IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp']
const ACCEPTED_VIDEO_EXTENSIONS = ['mp4', 'mov']
const MAX_VIDEO_UPLOAD_SIZE = 50 * 1024 * 1024

// ── 长提示词折叠展示 ──────────────────────────────────────────────────────────
const PROMPT_COLLAPSE_LENGTH = 160
const expandedPrompts = ref<Set<number>>(new Set())

function isLongPrompt(message: QuickCreateMessage): boolean {
  return message.prompt.length > PROMPT_COLLAPSE_LENGTH
}

type QcPromptReference = QuickCreateAssetRef | PendingRef
type QcPromptPart =
  | { type: 'text'; text: string }
  | { type: 'reference'; text: string; ref: QcPromptReference; index: number }

function isCompletedReferenceToken(raw: string, refs: QcPromptReference[]): number {
  const token = raw.slice(1).trim()
  const numbered = token.match(/^(?:素材|asset|图片|image)\s*(\d+)$/i)
  if (numbered) {
    const numberedIndex = Number(numbered[1]) - 1
    return numberedIndex >= 0 && numberedIndex < refs.length ? numberedIndex : -1
  }
  return refs.findIndex((ref) => (ref.name ?? '').trim() === token)
}

const REFERENCE_TOKEN_RE = /@(?:图片\s*\d+|Image\s+\d+|素材\s*\d+|Asset\s+\d+)/gi
const REFERENCE_OR_NAME_TOKEN_RE = /@(?:图片\s*\d+|Image\s+\d+|素材\s*\d+|Asset\s+\d+|[^\s@，。！？,!?；;：:、（）()\[\]{}]+)/gi

function cloneRegexp(source: RegExp): RegExp {
  return new RegExp(source.source, source.flags)
}

function buildPromptParts(prompt: string, refs: QcPromptReference[], options: { liveTyping?: boolean } = {}): QcPromptPart[] {
  if (!prompt || refs.length === 0) return [{ type: 'text', text: prompt }]

  const parts: QcPromptPart[] = []
  const tokenPattern = options.liveTyping ? cloneRegexp(REFERENCE_TOKEN_RE) : cloneRegexp(REFERENCE_OR_NAME_TOKEN_RE)
  let cursor = 0
  for (const match of prompt.matchAll(tokenPattern)) {
    const raw = match[0]
    const start = match.index ?? -1
    if (start < 0) continue
    const referenceIndex = isCompletedReferenceToken(raw, refs)
    if (referenceIndex < 0) continue

    if (start > cursor) parts.push({ type: 'text', text: prompt.slice(cursor, start) })
    parts.push({ type: 'reference', text: raw, ref: refs[referenceIndex], index: referenceIndex })
    cursor = start + raw.length
  }
  if (cursor < prompt.length) parts.push({ type: 'text', text: prompt.slice(cursor) })
  return parts.length ? parts : [{ type: 'text', text: prompt }]
}

function promptParts(message: QuickCreateMessage): QcPromptPart[] {
  return buildPromptParts(message.prompt ?? '', message.asset_refs ?? [])
}

function pendingPromptParts(): QcPromptPart[] {
  return buildPromptParts(promptText.value, pendingRefs.value, { liveTyping: true })
}

function referenceMediaUrl(ref: QcPromptReference): string {
  return 'url' in ref ? ref.url : (ref.video_url ?? ref.image_url ?? '')
}

function isReferenceVideo(ref: QcPromptReference): boolean {
  return 'url' in ref ? ref.media_type === 'video' : Boolean(ref.video_url)
}

function togglePromptExpand(messageId: number) {
  const next = new Set(expandedPrompts.value)
  if (next.has(messageId)) next.delete(messageId)
  else next.add(messageId)
  expandedPrompts.value = next
}

// ── 生成参数 ─────────────────────────────────────────────────────────────────
const aspectRatio = ref('adaptive')
const videoResolution = ref('480p')
const imageResolution = ref('1K')
const imageQuality = ref<'low' | 'medium' | 'high'>('medium')
const duration = ref(5)
const count = ref(1)
const generateAudio = ref(false)

const imageAspectRatioOptions = [
  { value: 'adaptive', label: '智能比例' },
  { value: '16:9', label: '16:9' },
  { value: '9:16', label: '9:16' },
  { value: '1:1', label: '1:1' },
  { value: '4:3', label: '4:3' },
  { value: '3:4', label: '3:4' },
]
const imageResolutionOptions = ['1K', '2K', '4K']
const imageQualityOptions = [
  { value: 'low', label: '低清' },
  { value: 'medium', label: '中清' },
  { value: 'high', label: '高清' },
] as const
const countOptions = [1, 2, 3, 4]

// ── 模型选择 ─────────────────────────────────────────────────────────────────
const modelGroups = ref<ModelConfigGroup[]>([])
const selectedImageModelId = ref(0)
const selectedVideoModelId = ref(0)

const availableModels = computed<ModelConfig[]>(() => {
  const group = modelGroups.value.find((g) => g.type === mode.value)
  return group?.models ?? []
})

const selectedModelId = computed({
  get: () => (mode.value === 'image' ? selectedImageModelId.value : selectedVideoModelId.value),
  set: (value: number) => {
    if (mode.value === 'image') selectedImageModelId.value = value
    else selectedVideoModelId.value = value
  },
})

const selectedVideoModel = computed<ModelConfig | undefined>(() => {
  const group = modelGroups.value.find((g) => g.type === 'video')
  return group?.models.find((model) => model.id === selectedVideoModelId.value)
})
const selectedImageModel = computed<ModelConfig | undefined>(() => {
  const group = modelGroups.value.find((g) => g.type === 'image')
  return group?.models.find((model) => model.id === selectedImageModelId.value)
})
const isMiniMaxVideo = computed(() => isMiniMaxVideoModel(selectedVideoModel.value))
const isTencentVodImage = computed(() => {
  const model = selectedImageModel.value
  if (!model) return false
  const provider = String(model.options?.provider ?? '').toLowerCase()
  return provider === 'tencent_vod' || provider === 'tencent' || provider === 'vod_aigc' || model.endpoint.includes('vod.tencentcloudapi.com')
})
const supportsImageAspectRatio = computed(() => mode.value !== 'image' || !isTencentVodImage.value)
const supportsImageResolution = computed(() => mode.value !== 'image' || !isTencentVodImage.value)
const supportsImageQuality = computed(() => mode.value === 'image')
const videoResolutionOptions = computed(() => resolveVideoResolutionOptions(selectedVideoModel.value))
const videoAspectRatioChoices = computed(() => [
  { value: 'adaptive', label: '智能比例' },
  ...resolveVideoAspectRatioOptions(selectedVideoModel.value).map((value) => ({ value, label: value })),
])
const durationOptions = computed(() => isMiniMaxVideo.value
  ? Array.from({ length: 12 }, (_, index) => index + 4)
  : [5, 10, 15])
const referenceAccept = computed(() => mode.value === 'video' && isMiniMaxVideo.value
  ? 'image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,.mov'
  : 'image/jpeg,image/png,image/gif,image/webp')

watch(isTencentVodImage, (enabled) => {
  if (!enabled || mode.value !== 'image') return
  aspectRatio.value = 'adaptive'
  imageResolution.value = '1K'
})

watch(selectedVideoModel, (model) => {
  if (!model || mode.value !== 'video') return
  videoResolution.value = pickAllowedResolution(videoResolution.value, model)
  if (aspectRatio.value !== 'adaptive' && !resolveVideoAspectRatioOptions(model).includes(aspectRatio.value)) {
    aspectRatio.value = 'adaptive'
  }
}, { immediate: true })

watch(isMiniMaxVideo, (enabled, previous) => {
  if (enabled) {
    if (!['768P', '2K'].includes(videoResolution.value)) videoResolution.value = '2K'
    duration.value = Math.max(4, Math.min(15, duration.value || 5))
    generateAudio.value = false
    return
  }
  if (previous) {
    videoResolution.value = '720p'
    duration.value = [5, 10, 15].includes(duration.value) ? duration.value : 5
    const before = pendingRefs.value.length
    pendingRefs.value = pendingRefs.value.filter((ref) => ref.media_type !== 'video')
    if (pendingRefs.value.length !== before) {
      ElMessage.warning(t('切换到其他视频模型后，已移除不受支持的参考视频'))
    }
  }
})

watch(mode, (nextMode) => {
  if (nextMode !== 'image') return
  pendingRefs.value = pendingRefs.value.filter((ref) => ref.media_type !== 'video')
})

// ── @ 资产联想 ────────────────────────────────────────────────────────────────
const mentionVisible = ref(false)
const mentionKeyword = ref('')
const mentionStart = ref(-1)
const mentionEnd = ref(-1)
const mentionAssets = ref<Asset[]>([])
const mentionLoading = ref(false)
let mentionTimer: ReturnType<typeof setTimeout> | null = null
const UPLOAD_SERIES_FILTER = -1

// 作品变多后 @ 候选会很杂，默认要求先输入关键词或选定某个作品才展开列表。
const seriesList = ref<Series[]>([])
const mentionSeriesFilter = ref(0)
const myUserId = computed(() => authStore.user?.id ?? 0)

// ── 视图模式 / 密度 / 本机收藏 ───────────────────────────────────────────────
type QcViewMode = 'chat' | 'gallery'
type QcDensity = 'comfortable' | 'cozy' | 'compact'
type QcResultFilter = 'all' | 'favorites'

interface QcMediaItem {
  key: string
  message: QuickCreateMessage
  resultIndex: number
  url: string
}

interface QcViewPrefs {
  viewMode: QcViewMode
  density: QcDensity
  filter: QcResultFilter
}

const viewMode = ref<QcViewMode>('chat')
const density = ref<QcDensity>('cozy')
const resultFilter = ref<QcResultFilter>('all')
const favoriteKeys = ref<Set<string>>(new Set())

const viewPrefsStorageKey = computed(
  () => `workflow.quickCreate.viewPrefs.${myUserId.value || 'anonymous'}`,
)
const favoritesStorageKey = computed(
  () => `workflow.quickCreate.favorites.${myUserId.value || 'anonymous'}`,
)

function mediaKey(messageId: number, resultIndex: number): string {
  return `${messageId}:${resultIndex}`
}

function isFavorite(messageId: number, resultIndex: number): boolean {
  return favoriteKeys.value.has(mediaKey(messageId, resultIndex))
}

function toggleFavorite(messageId: number, resultIndex: number) {
  const key = mediaKey(messageId, resultIndex)
  const next = new Set(favoriteKeys.value)
  if (next.has(key)) next.delete(key)
  else next.add(key)
  favoriteKeys.value = next
}

function loadViewPrefs() {
  try {
    const raw = localStorage.getItem(viewPrefsStorageKey.value)
    if (!raw) return
    const parsed = JSON.parse(raw) as Partial<QcViewPrefs>
    if (parsed.viewMode === 'chat' || parsed.viewMode === 'gallery') viewMode.value = parsed.viewMode
    if (parsed.density === 'comfortable' || parsed.density === 'cozy' || parsed.density === 'compact') {
      density.value = parsed.density
    }
    if (parsed.filter === 'all' || parsed.filter === 'favorites') resultFilter.value = parsed.filter
  } catch {
    // ignore broken prefs
  }
}

function saveViewPrefs() {
  const payload: QcViewPrefs = {
    viewMode: viewMode.value,
    density: density.value,
    filter: resultFilter.value,
  }
  localStorage.setItem(viewPrefsStorageKey.value, JSON.stringify(payload))
}

function loadFavorites() {
  try {
    const raw = localStorage.getItem(favoritesStorageKey.value)
    if (!raw) {
      favoriteKeys.value = new Set()
      return
    }
    const parsed = JSON.parse(raw) as unknown
    if (!Array.isArray(parsed)) {
      favoriteKeys.value = new Set()
      return
    }
    favoriteKeys.value = new Set(parsed.filter((item): item is string => typeof item === 'string'))
  } catch {
    favoriteKeys.value = new Set()
  }
}

function saveFavorites() {
  localStorage.setItem(favoritesStorageKey.value, JSON.stringify([...favoriteKeys.value]))
}

/** 画廊：成功成品扁平化，新→旧 */
const mediaItems = computed<QcMediaItem[]>(() => {
  const items: QcMediaItem[] = []
  for (let i = messages.value.length - 1; i >= 0; i--) {
    const message = messages.value[i]
    if (message.status !== 'success') continue
    message.result_urls.forEach((url, resultIndex) => {
      if (!url) return
      items.push({
        key: mediaKey(message.id, resultIndex),
        message,
        resultIndex,
        url,
      })
    })
  }
  return items
})

const visibleMediaItems = computed(() => {
  if (resultFilter.value !== 'favorites') return mediaItems.value
  return mediaItems.value.filter((item) => favoriteKeys.value.has(item.key))
})

const favoriteCount = computed(() =>
  mediaItems.value.reduce((count, item) => (favoriteKeys.value.has(item.key) ? count + 1 : count), 0),
)

const galleryImageItems = computed(() =>
  visibleMediaItems.value.filter((item) => item.message.mode === 'image'),
)

const galleryImageUrls = computed(() => galleryImageItems.value.map((item) => item.url))

function galleryImagePreviewIndex(item: QcMediaItem): number {
  return Math.max(0, galleryImageItems.value.findIndex((entry) => entry.key === item.key))
}

function pruneStaleFavorites() {
  const valid = new Set(mediaItems.value.map((item) => item.key))
  const next = new Set([...favoriteKeys.value].filter((key) => valid.has(key)))
  if (next.size !== favoriteKeys.value.size) favoriteKeys.value = next
}

function visibleResultIndexes(message: QuickCreateMessage): number[] {
  if (message.status !== 'success') return []
  const indexes = message.result_urls
    .map((url, index) => (url ? index : -1))
    .filter((index) => index >= 0)
  if (resultFilter.value !== 'favorites') return indexes
  return indexes.filter((index) => isFavorite(message.id, index))
}

function isMessageVisibleInChat(message: QuickCreateMessage): boolean {
  if (resultFilter.value !== 'favorites') return true
  if (message.status === 'success') return visibleResultIndexes(message).length > 0
  return false
}

const visibleChatMessages = computed(() => messages.value.filter((message) => isMessageVisibleInChat(message)))

watch([viewMode, density, resultFilter], saveViewPrefs)
watch(favoriteKeys, saveFavorites, { deep: true })
watch(myUserId, () => {
  loadViewPrefs()
  loadFavorites()
})

const activePolls = new Set<number>()
let pollTimer: ReturnType<typeof setInterval> | null = null

function applyHistoryPage(
  page: { messages: QuickCreateMessage[]; has_more: boolean; oldest_id: number },
  mode: 'replace' | 'prepend',
) {
  if (mode === 'replace') {
    messages.value = page.messages
  } else if (page.messages.length) {
    const existing = new Set(messages.value.map((m) => m.id))
    const older = page.messages.filter((m) => !existing.has(m.id))
    if (older.length) messages.value = [...older, ...messages.value]
  }
  historyHasMore.value = Boolean(page.has_more)
  historyOldestId.value = page.oldest_id > 0 ? page.oldest_id : historyOldestId.value
  page.messages
    .filter((m) => m.status === 'queued' || m.status === 'running')
    .forEach((m) => activePolls.add(m.id))
}

async function loadMoreHistory() {
  if (!historyHasMore.value || loadingMoreHistory.value || loadingHistory.value) return
  if (historyOldestId.value <= 0) return

  if (viewMode.value === 'chat') historyTopTriggerArmed = false
  else historyBottomTriggerArmed = false

  const el = streamEl.value
  const prevHeight = el?.scrollHeight ?? 0
  const prevTop = el?.scrollTop ?? 0
  loadingMoreHistory.value = true
  try {
    const page = await listQuickCreateMessages({
      limit: HISTORY_PAGE_SIZE,
      before_id: historyOldestId.value,
    })
    applyHistoryPage(page, 'prepend')
    pruneStaleFavorites()
    ensurePolling()
    if (viewMode.value === 'chat' && el) {
      await nextTick()
      el.scrollTop = el.scrollHeight - prevHeight + prevTop
    }
  } catch (err: unknown) {
    ElMessage.error((err as Error)?.message || t('加载更多失败'))
  } finally {
    loadingMoreHistory.value = false
  }
}

function onStreamScroll() {
  const el = streamEl.value
  if (!el || !historyHasMore.value || loadingMoreHistory.value) return
  if (viewMode.value === 'chat') {
    if (el.scrollTop > 120) {
      historyTopTriggerArmed = true
      return
    }
    if (el.scrollTop <= 80 && historyTopTriggerArmed) void loadMoreHistory()
    return
  }
  // 画廊：成品新→旧，更早记录在下方
  const nearBottom = el.scrollTop + el.clientHeight >= el.scrollHeight - 120
  if (!nearBottom) historyBottomTriggerArmed = true
  else if (historyBottomTriggerArmed) void loadMoreHistory()
}

function videoStageOf(messageId: number, resultIndex: number): QcVideoStage {
  return videoStages.value[mediaKey(messageId, resultIndex)] || 'idle'
}

function setVideoStage(messageId: number, resultIndex: number, stage: QcVideoStage) {
  const key = mediaKey(messageId, resultIndex)
  const current = videoStages.value[key] || 'idle'
  if (current === stage) return
  // ready 不应被 preview 降级；idle→preview→ready 单向升级
  if (current === 'ready' && stage !== 'ready') return
  if (current === 'preview' && stage === 'idle') return
  videoStages.value = { ...videoStages.value, [key]: stage }
}

function activateVideoPreview(messageId: number, resultIndex: number) {
  setVideoStage(messageId, resultIndex, 'preview')
}

function activateVideoPlayback(messageId: number, resultIndex: number, event?: Event) {
  setVideoStage(messageId, resultIndex, 'ready')
  void nextTick(() => {
    const shell = (event?.currentTarget as HTMLElement | null)?.parentElement
    const video = shell?.querySelector('video') as HTMLVideoElement | null
    if (!video) return
    video.muted = false
    void video.play().catch(() => undefined)
  })
}

/** 部分浏览器 preload=metadata 首帧仍黑，轻推 currentTime 逼出预览帧 */
function revealVideoPoster(event: Event) {
  const video = event.target as HTMLVideoElement | null
  if (!video || video.dataset.posterReady === '1') return
  try {
    if (video.currentTime < 0.05) {
      video.currentTime = 0.08
    }
    video.dataset.posterReady = '1'
  } catch {
    // ignore seek errors on incomplete media
  }
}

function onPreviewVideoReady(event: Event, messageId: number, resultIndex: number) {
  revealVideoPoster(event)
  if (videoStageOf(messageId, resultIndex) === 'ready') {
    const video = event.target as HTMLVideoElement
    void video.play().catch(() => undefined)
  }
}

function ensureVideoObserver() {
  if (videoObserver || typeof IntersectionObserver === 'undefined') return
  videoObserver = new IntersectionObserver(
    (entries) => {
      for (const entry of entries) {
        if (!entry.isIntersecting) continue
        const node = entry.target as HTMLElement
        const messageId = Number(node.dataset.messageId || 0)
        const resultIndex = Number(node.dataset.resultIndex || 0)
        if (messageId > 0) {
          activateVideoPreview(messageId, resultIndex)
          videoObserver?.unobserve(node)
        }
      }
    },
    { root: null, rootMargin: '240px 0px', threshold: 0.01 },
  )
}

function bindLazyVideo(el: Element | null, messageId: number, resultIndex: number) {
  if (!el) return
  const node = el as HTMLElement
  node.dataset.messageId = String(messageId)
  node.dataset.resultIndex = String(resultIndex)
  if (videoStageOf(messageId, resultIndex) !== 'idle') return
  ensureVideoObserver()
  videoObserver?.observe(node)
}

onMounted(async () => {
  loadViewPrefs()
  loadFavorites()
  loadingHistory.value = true
  try {
    const [historyRes, groupsRes, seriesRes] = await Promise.all([
      listQuickCreateMessages({ limit: HISTORY_PAGE_SIZE }),
      listGrouped(),
      listSeries({ scope: 'all' }).catch(() => []),
    ])
    applyHistoryPage(historyRes, 'replace')
    modelGroups.value = groupsRes.filter((g) => g.type === 'image' || g.type === 'video')
    seriesList.value = seriesRes
    pruneStaleFavorites()
    const defaultOf = (type: string) => {
      const models = modelGroups.value.find((g) => g.type === type)?.models ?? []
      const preferred = models.find((m) => m.is_default) ?? models[0]
      return preferred ? preferred.id : 0
    }
    selectedImageModelId.value = defaultOf('image')
    selectedVideoModelId.value = defaultOf('video')
    ensurePolling()
    await nextTick()
    renderPromptEditor()
    autoGrowTextarea()
  } catch {
    ElMessage.error(t('速创历史加载失败'))
  } finally {
    loadingHistory.value = false
    await nextTick()
    scrollToBottom()
  }
})

onBeforeUnmount(() => {
  if (pollTimer) clearInterval(pollTimer)
  if (mentionTimer) clearTimeout(mentionTimer)
  if (sendCooldownTimer) clearTimeout(sendCooldownTimer)
  if (referenceHoverHideTimer) clearTimeout(referenceHoverHideTimer)
  videoObserver?.disconnect()
  videoObserver = null
})

function ensurePolling() {
  if (pollTimer || activePolls.size === 0) return
  pollTimer = setInterval(async () => {
    if (activePolls.size === 0) {
      if (pollTimer) clearInterval(pollTimer)
      pollTimer = null
      return
    }
    try {
      const res = await pollQuickCreateMessages([...activePolls])
      for (const fresh of res.messages) {
        const idx = messages.value.findIndex((m) => m.id === fresh.id)
        if (idx >= 0) messages.value[idx] = fresh
        if (fresh.status === 'success' || fresh.status === 'failed') {
          activePolls.delete(fresh.id)
        }
      }
    } catch {
      // 静默轮询：网络抖动不打断用户
    }
  }, 4000)
}

function scrollToBottom() {
  void nextTick(() => {
    if (streamEl.value) streamEl.value.scrollTop = streamEl.value.scrollHeight
  })
}

const TEXTAREA_MAX_HEIGHT = 240

function autoGrowTextarea() {
  const el = inputEl.value
  if (!el) return
  // height=auto 时浏览器常把 scrollTop 甩到底，长文 @ 引用后会跳离当前编辑位置
  const prevScrollTop = el.scrollTop
  el.style.height = '0px'
  el.style.height = `${Math.min(el.scrollHeight, TEXTAREA_MAX_HEIGHT)}px`
  el.scrollTop = prevScrollTop
}

/** 按光标位置微调编辑器内部滚动，避免选中 @ 资产后视野跳到文末 */
const EDITOR_ZWSP = '\u200b'

function stripEditorZwsp(value: string): string {
  return value.replaceAll(EDITOR_ZWSP, '')
}

function editableText(node: Node): string {
  if (node.nodeType === Node.TEXT_NODE) return stripEditorZwsp(node.nodeValue ?? '')
  if (node.nodeType !== Node.ELEMENT_NODE) return ''
  const element = node as HTMLElement
  if (element.dataset.editorReference === 'true') return element.dataset.token ?? ''
  if (element.tagName === 'BR') return '\n'
  const text = Array.from(element.childNodes).map(editableText).join('')
  if ((element.tagName === 'DIV' || element.tagName === 'P') && element.parentElement) {
    return element === element.parentElement.lastElementChild ? text : `${text}\n`
  }
  return text
}

function editorText(): string {
  return inputEl.value ? editableText(inputEl.value) : promptText.value
}

function editorNodeLength(node: Node): number {
  return editableText(node).length
}

function editorOffsetBeforeNode(root: HTMLElement, target: Node): number {
  let offset = 0
  let found = false
  const walk = (node: Node): void => {
    if (found) return
    if (node === target) {
      found = true
      return
    }
    for (const child of Array.from(node.childNodes)) {
      walk(child)
      if (found) return
      offset += editorNodeLength(child)
    }
  }
  walk(root)
  return offset
}

function editorOffsetAtPoint(root: HTMLElement, target: Node, offsetInNode: number): number {
  let offset = 0
  let found = false
  const walk = (node: Node): void => {
    if (found) return
    if (node === target) {
      if (node.nodeType === Node.TEXT_NODE) {
        const raw = node.nodeValue ?? ''
        const prefix = raw.startsWith(EDITOR_ZWSP) ? 1 : 0
        offset += Math.min(Math.max(0, offsetInNode - prefix), stripEditorZwsp(raw).length)
      } else {
        offset += Array.from(node.childNodes)
          .slice(0, offsetInNode)
          .reduce((sum, child) => sum + editorNodeLength(child), 0)
      }
      found = true
      return
    }
    for (const child of Array.from(node.childNodes)) {
      walk(child)
      if (found) return
      offset += editorNodeLength(child)
    }
  }
  walk(root)
  return offset
}

function editorCaretOffset(): number {
  const root = inputEl.value
  const selection = window.getSelection()
  if (!root || !selection || selection.rangeCount === 0) return mentionEnd.value >= 0 ? mentionEnd.value : promptText.value.length
  const range = selection.getRangeAt(0)
  if (!root.contains(range.startContainer)) return mentionEnd.value >= 0 ? mentionEnd.value : promptText.value.length
  const parent = range.startContainer.nodeType === Node.ELEMENT_NODE
    ? range.startContainer as HTMLElement
    : range.startContainer.parentElement
  const reference = parent?.closest('[data-editor-reference="true"]')
  if (reference && root.contains(reference)) {
    const before = editorOffsetBeforeNode(root, reference)
    const referenceLength = editorNodeLength(reference)
    const host = reference.parentNode
    if (host && range.startContainer === host) {
      const index = Array.prototype.indexOf.call(host.childNodes, reference)
      return range.startOffset > index ? before + referenceLength : before
    }
    const next = reference.nextSibling
    const noTextAfter = !next || (next.nodeType === Node.TEXT_NODE && !(next.nodeValue ?? ''))
    if (noTextAfter) return before + referenceLength
    const referenceRect = reference.getBoundingClientRect()
    const point = range.getBoundingClientRect()
    return point.left >= referenceRect.left + referenceRect.width / 2
      ? before + referenceLength
      : before
  }
  return editorOffsetAtPoint(root, range.startContainer, range.startOffset)
}

function setEditorCaretOffset(position: number) {
  const root = inputEl.value
  if (!root) return
  const clamped = Math.max(0, Math.min(position, editorText().length))
  const range = document.createRange()
  const selection = window.getSelection()
  let remaining = clamped
  let placed = false

  const place = (node: Node): void => {
    if (placed) return
    if (node.nodeType === Node.TEXT_NODE) {
      const raw = node.nodeValue ?? ''
      const length = stripEditorZwsp(raw).length
      if (remaining <= length) {
        const prefix = raw.startsWith(EDITOR_ZWSP) ? 1 : 0
        range.setStart(node, Math.min(raw.length, prefix + remaining))
        range.collapse(true)
        placed = true
      } else {
        remaining -= length
      }
      return
    }
    if (node.nodeType !== Node.ELEMENT_NODE) return
    const element = node as HTMLElement
    if (element.dataset.editorReference === 'true') {
      const parent = element.parentNode
      if (!parent) return
      const index = Array.prototype.indexOf.call(parent.childNodes, element)
      const length = editorNodeLength(element)
      if (remaining <= 0) {
        const prev = element.previousSibling
        if (prev?.nodeType === Node.TEXT_NODE) range.setStart(prev, prev.nodeValue?.length ?? 0)
        else range.setStart(parent, index)
        range.collapse(true)
        placed = true
      } else if (remaining <= length) {
        const next = element.nextSibling
        if (next?.nodeType === Node.TEXT_NODE) range.setStart(next, next.nodeValue?.startsWith(EDITOR_ZWSP) ? 1 : 0)
        else range.setStart(parent, index + 1)
        range.collapse(true)
        placed = true
      } else {
        remaining -= length
      }
      return
    }
    for (const child of Array.from(node.childNodes)) place(child)
  }
  place(root)
  if (!placed) {
    range.selectNodeContents(root)
    range.collapse(false)
  }
  selection?.removeAllRanges()
  selection?.addRange(range)
}

function ensureEditorCaretVisible() {
  const root = inputEl.value
  const selection = window.getSelection()
  if (!root || !selection || selection.rangeCount === 0) return
  const range = selection.getRangeAt(0).cloneRange()
  if (!range.collapsed) range.collapse(true)
  const rect = range.getBoundingClientRect()
  const rootRect = root.getBoundingClientRect()
  const padding = 8
  if (rect.top < rootRect.top + padding) root.scrollTop -= rootRect.top + padding - rect.top
  else if (rect.bottom > rootRect.bottom - padding) root.scrollTop += rect.bottom - (rootRect.bottom - padding)
}

function restoreEditorCaret(position: number) {
  autoGrowTextarea()
  inputEl.value?.focus()
  setEditorCaretOffset(position)
  ensureEditorCaretVisible()
}

function createEditorReference(part: Extract<QcPromptPart, { type: 'reference' }>): HTMLElement {
  const wrapper = document.createElement('span')
  wrapper.className = 'qc-inline-reference qc-editor-reference'
  wrapper.contentEditable = 'false'
  wrapper.dataset.editorReference = 'true'
  wrapper.dataset.token = part.text
  wrapper.title = part.ref.name || part.text

  const token = document.createElement('span')
  token.className = 'qc-inline-reference-token'
  token.contentEditable = 'false'
  token.textContent = part.text
  wrapper.appendChild(token)

  const url = referenceMediaUrl(part.ref)
  if (url) {
    if (isReferenceVideo(part.ref)) {
      const video = document.createElement('video')
      video.src = url
      video.muted = true
      video.loop = true
      video.playsInline = true
      video.preload = 'metadata'
      video.className = 'qc-inline-reference-thumb'
      video.contentEditable = 'false'
      video.addEventListener('loadedmetadata', revealVideoPoster)
      wrapper.appendChild(video)
    } else {
      const image = document.createElement('img')
      image.src = url
      image.alt = part.ref.name || part.text
      image.className = 'qc-inline-reference-thumb'
      image.contentEditable = 'false'
      wrapper.appendChild(image)
    }
  }
  wrapper.addEventListener('mouseenter', (event) => showReferenceHoverPreview(event, part.ref))
  wrapper.addEventListener('mouseleave', hideReferenceHoverPreview)
  return wrapper
}

function renderPromptEditor(caretPosition?: number) {
  const root = inputEl.value
  if (!root) return
  const parts = pendingPromptParts()
  const nodes: Node[] = []
  let lastWasReference = false
  for (const part of parts) {
    if (part.type === 'text') {
      nodes.push(document.createTextNode((lastWasReference ? EDITOR_ZWSP : '') + part.text))
      lastWasReference = false
    } else {
      if (!lastWasReference && nodes.length === 0) nodes.push(document.createTextNode(EDITOR_ZWSP))
      else if (lastWasReference) nodes.push(document.createTextNode(EDITOR_ZWSP))
      nodes.push(createEditorReference(part))
      lastWasReference = true
    }
  }
  if (!nodes.length || lastWasReference || nodes[nodes.length - 1].nodeType !== Node.TEXT_NODE) nodes.push(document.createTextNode(EDITOR_ZWSP))
  root.replaceChildren(...nodes)
  if (caretPosition !== undefined) restoreEditorCaret(caretPosition)
}

function promptEditorNeedsRender(): boolean {
  const root = inputEl.value
  if (!root) return false
  const renderedTokens = Array.from(root.querySelectorAll<HTMLElement>('[data-editor-reference="true"]'))
    .map((node) => node.dataset.token ?? '')
  const expectedTokens = pendingPromptParts()
    .filter((part): part is Extract<QcPromptPart, { type: 'reference' }> => part.type === 'reference')
    .map((part) => part.text)
  return renderedTokens.length !== expectedTokens.length
    || renderedTokens.some((token, index) => token !== expectedTokens[index])
}

function onPromptInput() {
  const root = inputEl.value
  const placeholderBreak = Boolean(
    root
    && stripEditorZwsp(root.textContent ?? '') === ''
    && root.querySelector('br')
    && !root.querySelector('[data-editor-reference="true"]'),
  )
  if (placeholderBreak) root?.replaceChildren()
  const caretPosition = editorCaretOffset()
  const nextText = placeholderBreak ? '' : editorText()
  if (nextText !== promptText.value) promptText.value = nextText
  if (isComposingPrompt) {
    refreshMentionState()
    return
  }
  if (promptEditorNeedsRender()) renderPromptEditor(caretPosition)
  autoGrowTextarea()
  refreshMentionState()
}

function onPromptCompositionStart() {
  isComposingPrompt = true
}

function onPromptCompositionEnd() {
  isComposingPrompt = false
  onPromptInput()
}

function onPromptPaste(event: ClipboardEvent) {
  event.preventDefault()
  const text = event.clipboardData?.getData('text/plain') ?? ''
  if (text) document.execCommand('insertText', false, text)
}

function editorReferenceFromRange(root: HTMLElement, range: Range): HTMLElement | null {
  const node = range.startContainer
  const parent = node.nodeType === Node.ELEMENT_NODE ? node as HTMLElement : node.parentElement
  const reference = parent?.closest('[data-editor-reference="true"]') as HTMLElement | null | undefined
  return reference && root.contains(reference) ? reference : null
}

function editorInsertOffsetForMention(root: HTMLElement, range: Range): number {
  const reference = editorReferenceFromRange(root, range)
  if (!reference) return editorCaretOffset()
  const before = editorOffsetBeforeNode(root, reference)
  const length = editorNodeLength(reference)
  const host = reference.parentNode
  if (host && range.startContainer === host) {
    const index = Array.prototype.indexOf.call(host.childNodes, reference)
    return range.startOffset > index ? before + length : before
  }
  return before + length
}

let mentionAtHandled = false

function insertMentionTriggerAt(insertAt: number, replaceEnd = insertAt) {
  const at = Math.max(0, Math.min(insertAt, promptText.value.length))
  const end = Math.max(at, Math.min(replaceEnd, promptText.value.length))
  promptText.value = `${promptText.value.slice(0, at)}@${promptText.value.slice(end)}`
  renderPromptEditor(at + 1)
  refreshMentionState()
}

function onPromptBeforeInput(event: InputEvent) {
  if (isComposingPrompt || event.inputType !== 'insertText' || event.data !== '@') return
  const root = inputEl.value
  const selection = window.getSelection()
  if (!root || !selection || selection.rangeCount === 0) return
  const range = selection.getRangeAt(0)
  if (!root.contains(range.startContainer) || mentionAtHandled) return
  event.preventDefault()
  mentionAtHandled = true
  const insertAt = editorInsertOffsetForMention(root, range)
  const replaceEnd = range.collapsed ? insertAt : Math.max(insertAt, editorOffsetAtPoint(root, range.endContainer, range.endOffset))
  insertMentionTriggerAt(insertAt, replaceEnd)
  void nextTick(() => { mentionAtHandled = false })
}

// ── @ 联想逻辑 ────────────────────────────────────────────────────────────────
function mentionChipRanges(): Array<[number, number]> {
  const ranges: Array<[number, number]> = []
  let offset = 0
  for (const part of pendingPromptParts()) {
    const partEnd = offset + part.text.length
    if (part.type === 'reference') ranges.push([offset, partEnd])
    offset = partEnd
  }
  return ranges
}

function mentionQueryAtCursor(cursor: number): { start: number; end: number; keyword: string } | null {
  const text = promptText.value
  const ranges = mentionChipRanges()
  let safeCursor = Math.max(0, Math.min(cursor, text.length))
  for (const [start, end] of ranges) if (safeCursor > start && safeCursor < end) safeCursor = end
  if (safeCursor < text.length && text[safeCursor] === '@' && !ranges.some(([start, end]) => safeCursor >= start && safeCursor < end)) {
    return { start: safeCursor, end: safeCursor + 1, keyword: '' }
  }
  const prefix = text.slice(0, safeCursor)
  let at = prefix.lastIndexOf('@')
  while (at >= 0) {
    if (!ranges.some(([start, end]) => at >= start && at < end)) {
      const keyword = prefix.slice(at + 1)
      if (/[\s，。！？,!?；;：:、（）()\[\]{}]/.test(keyword)) return null
      return { start: at, end: safeCursor, keyword }
    }
    at = prefix.lastIndexOf('@', at - 1)
  }
  return null
}

function refreshMentionState() {
  if (!inputEl.value) return
  const cursor = editorCaretOffset()
  const query = mentionQueryAtCursor(cursor)
  if (!query) {
    mentionVisible.value = false
    mentionStart.value = -1
    mentionEnd.value = -1
    mentionKeyword.value = ''
    return
  }
  mentionStart.value = query.start
  mentionEnd.value = query.end
  mentionKeyword.value = query.keyword
  mentionVisible.value = true
  if (mentionTimer) clearTimeout(mentionTimer)
  mentionTimer = setTimeout(loadMentionAssets, 200)
}

/** 未输入关键词、也没选定作品时不展开列表，避免作品一多 @ 就是一大坨。 */
const mentionNeedsNarrowing = computed(
  () => mentionKeyword.value.trim() === '' && mentionSeriesFilter.value === 0,
)
const uploadRefs = computed(() => pendingRefs.value.filter((ref) => ref.kind === 'upload'))

async function loadMentionAssets() {
  if (mentionSeriesFilter.value === UPLOAD_SERIES_FILTER) {
    mentionAssets.value = []
    mentionLoading.value = false
    return
  }
  if (mentionNeedsNarrowing.value) {
    mentionAssets.value = []
    return
  }
  mentionLoading.value = true
  try {
    const keyword = mentionKeyword.value.trim()
    mentionAssets.value = (await listAssets({
      scope: 'all',
      ...(keyword ? { keyword } : {}),
      ...(mentionSeriesFilter.value > 0 ? { series_id: mentionSeriesFilter.value } : {}),
    })).filter(
      (asset) => (asset.images ?? []).some((img) => img.url),
    ).slice(0, 6)
  } catch {
    mentionAssets.value = []
  } finally {
    mentionLoading.value = false
  }
}

function onMentionSeriesFilterChange() {
  void loadMentionAssets()
}

function mentionSeriesLabel(series: Series): string {
  const isShared = series.owner_user_id !== undefined && series.owner_user_id !== myUserId.value
  if (!isShared) return series.title
  return `${series.title}（${t('来自 {name}', { name: series.owner_name || t('未知用户') })}）`
}

function refReferenceAlias(index: number): string {
  return `image_${index + 1}`
}

function pendingReferenceAlias(ref: PendingRef, index: number): string {
  if (ref.media_type !== 'video') return refReferenceAlias(index)
  const videoIndex = pendingRefs.value.slice(0, index).filter((item) => item.media_type === 'video').length
  return `video_${videoIndex + 1}`
}

function pendingImagePreviewIndex(index: number): number {
  return pendingRefs.value.slice(0, index).filter((item) => item.media_type === 'image').length
}

function refTextPlaceholder(index: number): string {
  return `@图片${index + 1}`
}

const MENTION_INSERT_SENTINEL = '<<<QC_NEW_REF>>>'

function resequencePromptReferences(prompt: string, refs: PendingRef[], insertedRef: PendingRef, insertStart: number, insertEnd: number) {
  const start = Math.max(0, Math.min(insertStart, prompt.length))
  const end = Math.max(start, Math.min(insertEnd, prompt.length))
  const merged = `${prompt.slice(0, start)}${MENTION_INSERT_SENTINEL}${prompt.slice(end)}`
  const pattern = new RegExp(`${MENTION_INSERT_SENTINEL}|${REFERENCE_TOKEN_RE.source}`, 'gi')
  const nextRefs: PendingRef[] = []
  const seen = new Set<string>()
  const nextPrompt = merged.replace(pattern, (raw, offset: number) => {
    const ref = raw === MENTION_INSERT_SENTINEL ? insertedRef : refs[isCompletedReferenceToken(raw, refs)]
    if (!ref) return raw
    if (!seen.has(ref.key)) { seen.add(ref.key); nextRefs.push(ref) }
    const tokenIndex = nextRefs.findIndex((item) => item.key === ref.key)
    const token = refTextPlaceholder(tokenIndex)
    const nextChar = merged[offset + raw.length] ?? ''
    return /\d/.test(nextChar) ? `${token} ` : token
  })
  if (!seen.has(insertedRef.key)) nextRefs.push(insertedRef)
  const leftover = refs.filter((ref) => !seen.has(ref.key))
  return { prompt: nextPrompt, refs: [...nextRefs, ...leftover] }
}

function countReferenceTokens(text: string): number {
  return Array.from(text.matchAll(cloneRegexp(REFERENCE_TOKEN_RE))).length
}

function offsetAfterNthReference(prompt: string, n: number): number {
  let index = 0
  for (const match of prompt.matchAll(cloneRegexp(REFERENCE_TOKEN_RE))) {
    if (index === n) return (match.index ?? 0) + match[0].length
    index += 1
  }
  return prompt.length
}

function mentionInsertText(text: string, after: string): string {
  return /\s/.test(after[0] ?? '') ? text : `${text} `
}

interface MentionEntry {
  key: string
  kind: 'asset' | 'upload'
  asset?: Asset
  image?: AssetImage
  uploadRef?: PendingRef
  label: string
  isShared: boolean
}

/** 人物造型（reference_role=look）显示具体造型名；同一资产有多张图时补充视图也单独可选。 */
function imageVariantSuffix(image: AssetImage, totalImages: number): string {
  if (image.reference_role === 'look') {
    return (image.variant_name || '').trim() || t('默认造型')
  }
  if (totalImages <= 1) return ''
  if (image.view_type === 'main') return t('核心视图')
  return (image.note || '').trim() || t('补充视图')
}

const mentionEntries = computed<MentionEntry[]>(() => {
  const myUserId = authStore.user?.id
  const keyword = mentionKeyword.value.trim().toLowerCase()
  const entries: MentionEntry[] = []
  if (mentionSeriesFilter.value === UPLOAD_SERIES_FILTER) {
    uploadRefs.value.forEach((ref) => {
      const refIndex = pendingRefs.value.findIndex((item) => item.key === ref.key)
      const imageIndex = refIndex >= 0 ? refIndex : 0
      const label = `${refTextPlaceholder(imageIndex)} · ${ref.name}`
      if (keyword && !label.toLowerCase().includes(keyword) && !ref.name.toLowerCase().includes(keyword)) return
      entries.push({
        key: ref.key,
        kind: 'upload',
        uploadRef: ref,
        label,
        isShared: false,
      })
    })
    return entries.slice(0, 20)
  }
  for (const asset of mentionAssets.value) {
    const images = (asset.images ?? []).filter((img) => img.url)
    const isShared = asset.owner_user_id !== undefined && asset.owner_user_id !== myUserId
    for (const image of images.slice(0, 4)) {
      const suffix = imageVariantSuffix(image, images.length)
      let label = suffix ? `${asset.name} · ${suffix}` : asset.name
      if (isShared) {
        label += `（${t('来自 {name}', { name: asset.owner_name || t('未知用户') })}）`
      }
      entries.push({
        key: `asset:${asset.id}:${image.id ?? 0}`,
        kind: 'asset',
        asset,
        image,
        label,
        isShared,
      })
    }
  }
  return entries.slice(0, 20)
})

function pickMentionEntry(entry: MentionEntry) {
  const start = mentionStart.value >= 0 ? mentionStart.value : 0
  const end = mentionEnd.value > start ? mentionEnd.value : start + 1 + mentionKeyword.value.length
  mentionVisible.value = false
  mentionStart.value = -1
  mentionEnd.value = -1
  mentionKeyword.value = ''

  let insertedRef: PendingRef | undefined
  if (entry.kind === 'upload') insertedRef = pendingRefs.value.find((ref) => ref.key === entry.key)
  else if (entry.asset && entry.image) {
    insertedRef = pendingRefs.value.find((ref) => ref.key === entry.key)
    if (!insertedRef) {
      if (pendingRefs.value.length >= MAX_PENDING_REFS) {
        ElMessage.warning(t('最多引用 6 个参考素材'))
        return
      }
      insertedRef = { key: entry.key, kind: 'asset', asset_id: entry.asset.id, asset_image_id: entry.image.id, name: entry.label, type: entry.asset.type, url: entry.image.url, media_type: 'image' }
    }
  }
  if (!insertedRef) return
  const tokenIndex = countReferenceTokens(promptText.value.slice(0, start))
  skipPendingRefRender = true
  const sequenced = resequencePromptReferences(promptText.value, pendingRefs.value, insertedRef, start, end)
  pendingRefs.value = sequenced.refs
  promptText.value = sequenced.prompt
  const nextCursor = offsetAfterNthReference(promptText.value, tokenIndex)
  void nextTick(() => {
    renderPromptEditor()
    restoreEditorCaret(nextCursor)
    skipPendingRefRender = false
  })
}

function removeRef(key: string) {
  pendingRefs.value = pendingRefs.value.filter((r) => r.key !== key)
}

function openReferencePreview(ref: PendingRef) {
  if (ref.media_type !== 'video') return
  referencePreview.value = ref
  referencePreviewVisible.value = true
}

function openMessageReferencePreview(messageId: number, ref: QuickCreateAssetRef, index: number) {
  const url = (ref.video_url ?? '').trim()
  if (!url) return
  openReferencePreview({
    key: `message-video:${messageId}:${index}`,
    kind: ref.kind,
    asset_id: ref.asset_id,
    asset_image_id: ref.asset_image_id,
    name: ref.name || t('参考视频预览'),
    type: ref.type || 'upload',
    url,
    media_type: 'video',
    duration_seconds: ref.duration_seconds,
  })
}

function cancelReferenceHoverHide() {
  if (referenceHoverHideTimer) {
    clearTimeout(referenceHoverHideTimer)
    referenceHoverHideTimer = null
  }
}

function showReferenceHoverPreview(event: MouseEvent, ref: QuickCreateAssetRef | PendingRef) {
  const isPendingRef = 'url' in ref
  const isVideo = isPendingRef ? ref.media_type === 'video' : Boolean(ref.video_url)
  const url = isPendingRef ? ref.url.trim() : (ref.video_url ?? ref.image_url ?? '').trim()
  const target = event.currentTarget
  if (!url || !(target instanceof HTMLElement)) return

  cancelReferenceHoverHide()
  const rect = target.getBoundingClientRect()
  const width = isVideo ? 300 : 240
  const height = isVideo ? 210 : 280
  const margin = 12
  let left = rect.right + margin
  if (left + width > window.innerWidth - margin) left = rect.left - width - margin
  left = Math.max(margin, Math.min(left, window.innerWidth - width - margin))
  let top = rect.top - 4
  if (top + height > window.innerHeight - margin) top = window.innerHeight - height - margin
  top = Math.max(margin, top)

  referenceHoverPreview.value = {
    url,
    mediaType: isVideo ? 'video' : 'image',
    name: ref.name || (isVideo ? t('参考视频预览') : t('参考图')),
    top,
    left,
  }
}

function hideReferenceHoverPreview() {
  cancelReferenceHoverHide()
  referenceHoverHideTimer = setTimeout(() => {
    referenceHoverPreview.value = null
    referenceHoverHideTimer = null
  }, 120)
}

function closeReferencePreview() {
  referencePreviewVisible.value = false
  referencePreview.value = null
}

function startPendingRefDrag(key: string, event: DragEvent) {
  draggedPendingRefKey.value = key
  if (event.dataTransfer) {
    event.dataTransfer.effectAllowed = 'move'
    event.dataTransfer.setData('text/plain', key)
  }
}

function dropPendingRef(targetKey: string) {
  const sourceKey = draggedPendingRefKey.value
  draggedPendingRefKey.value = null
  if (!sourceKey || sourceKey === targetKey) return
  const sourceIndex = pendingRefs.value.findIndex((ref) => ref.key === sourceKey)
  const targetIndex = pendingRefs.value.findIndex((ref) => ref.key === targetKey)
  if (sourceIndex < 0 || targetIndex < 0) return
  const next = [...pendingRefs.value]
  const [moved] = next.splice(sourceIndex, 1)
  next.splice(targetIndex, 0, moved)
  pendingRefs.value = next
}

function isAcceptedImageFile(file: File): boolean {
  const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
  return (!file.type || file.type.startsWith('image/')) && ACCEPTED_IMAGE_EXTENSIONS.includes(extension)
}

function isAcceptedVideoFile(file: File): boolean {
  const extension = file.name.split('.').pop()?.toLowerCase() ?? ''
  return (!file.type || file.type.startsWith('video/') || file.type === 'application/quicktime')
    && ACCEPTED_VIDEO_EXTENSIONS.includes(extension)
}

async function uploadReferenceFiles(files: File[]) {
  if (uploadingReferences.value) return
  const capacity = MAX_PENDING_REFS - pendingRefs.value.length
  if (capacity <= 0) {
    ElMessage.warning(t('最多引用 6 个参考素材'))
    return
  }

  const selectedFiles = files.slice(0, capacity)
  if (files.length > capacity) {
    ElMessage.warning(t('最多还能添加 {count} 张参考图', { count: capacity }))
  }

  uploadingReferences.value = true
  let uploadedCount = 0
  try {
    for (const file of selectedFiles) {
      const isVideo = isAcceptedVideoFile(file)
      if (isVideo && (mode.value !== 'video' || !isMiniMaxVideo.value)) {
        ElMessage.warning(t('只有 MiniMax 视频模型支持 MP4、MOV 参考视频'))
        continue
      }
      if (!isVideo && !isAcceptedImageFile(file)) {
        ElMessage.warning(t('仅支持 JPG、JPEG、PNG、GIF、WebP 图片，MiniMax 视频模式另支持 MP4、MOV'))
        continue
      }
      if (isVideo && pendingRefs.value.filter((ref) => ref.media_type === 'video').length >= 3) {
        ElMessage.warning(t('MiniMax 参考视频最多 3 段'))
        continue
      }
      if (isVideo && file.size > MAX_VIDEO_UPLOAD_SIZE) {
        ElMessage.warning(t('单段参考视频不能超过 50MB'))
        continue
      }
      if (!isVideo && file.size > MAX_UPLOAD_SIZE) {
        ElMessage.warning(t('单张图片不能超过 {size}MB', { size: MAX_UPLOAD_SIZE_MB }))
        continue
      }

      const formData = new FormData()
      formData.append('file', file)
      formData.append('purpose', 'quick_create')
      try {
        const res: { url: string; duration_seconds?: number } = await request({
          url: isVideo ? '/api/upload/video' : '/api/upload/image',
          method: 'POST',
          data: formData,
          headers: { 'Content-Type': 'multipart/form-data' },
        })
        pendingRefs.value.push({
          key: `upload:${Date.now()}:${uploadedCount}`,
          kind: 'upload',
          name: file.name,
          type: 'upload',
          url: res.url,
          media_type: isVideo ? 'video' : 'image',
          duration_seconds: res.duration_seconds,
        })
        uploadedCount += 1
      } catch (err: unknown) {
        ElMessage.error(`${file.name}：${(err as Error)?.message || t('上传失败')}`)
      }
    }
    if (uploadedCount > 0) {
      ElMessage.success(t('已上传 {count} 个参考素材', { count: uploadedCount }))
    }
  } finally {
    uploadingReferences.value = false
  }
}

function uploadReference() {
  if (pendingRefs.value.length >= MAX_PENDING_REFS) {
    ElMessage.warning(t('最多引用 6 个参考素材'))
    return
  }
  uploadInputRef.value?.click()
}

function handleUploadInput(event: Event) {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files ?? [])
  input.value = ''
  if (files.length) void uploadReferenceFiles(files)
}

function handleUploadDragEnter() {
  uploadDragDepth.value += 1
  uploadDragging.value = true
}

function handleUploadDragLeave() {
  uploadDragDepth.value = Math.max(0, uploadDragDepth.value - 1)
  if (uploadDragDepth.value === 0) uploadDragging.value = false
}

function handleUploadDrop(event: DragEvent) {
  uploadDragDepth.value = 0
  uploadDragging.value = false
  const files = Array.from(event.dataTransfer?.files ?? [])
  if (files.length) void uploadReferenceFiles(files)
}

function refillQuickCreateInput(message: QuickCreateMessage) {
  const options = message.options ?? {}
  mode.value = message.mode
  promptText.value = message.prompt ?? ''
  pendingRefs.value = (message.asset_refs ?? [])
    .filter((ref) => Boolean(ref.image_url || ref.video_url))
    .map((ref, index) => ({
      key: ref.kind === 'asset'
        ? `asset:${ref.asset_id ?? 0}:${ref.asset_image_id ?? 0}`
        : `upload:${ref.image_url || ref.video_url}:${index}`,
      kind: ref.kind,
      asset_id: ref.kind === 'asset' ? ref.asset_id : undefined,
      asset_image_id: ref.kind === 'asset' ? ref.asset_image_id : undefined,
      name: ref.name || t('参考图'),
      type: ref.type || ref.kind,
      url: ref.image_url || ref.video_url || '',
      media_type: ref.media_type === 'video' || Boolean(ref.video_url) ? 'video' : 'image',
      duration_seconds: ref.duration_seconds,
    }))

  aspectRatio.value = options.aspect_ratio || 'adaptive'
  count.value = Number(options.count) > 0 ? Number(options.count) : 1
  if (message.mode === 'video') {
    const videoModel = modelGroups.value.find((g) => g.type === 'video')?.models.find((model) => model.id === message.model_config_id)
    videoResolution.value = pickAllowedResolution(String(options.resolution || ''), videoModel)
    duration.value = Number(options.duration) > 0 ? Number(options.duration) : 5
    generateAudio.value = Boolean(options.generate_audio)
    if (message.model_config_id > 0) selectedVideoModelId.value = message.model_config_id
  } else {
    imageResolution.value = options.resolution || '1K'
    const quality = String(options.quality || 'medium').toLowerCase()
    imageQuality.value = quality === 'low' || quality === 'medium' || quality === 'high' ? quality : 'medium'
    if (message.model_config_id > 0) selectedImageModelId.value = message.model_config_id
  }

  mentionVisible.value = false
  mentionKeyword.value = ''
  mentionStart.value = -1
  void nextTick(() => {
    renderPromptEditor()
    autoGrowTextarea()
    inputEl.value?.focus()
    restoreEditorCaret(promptText.value.length)
    document.querySelector('.qc-composer')?.scrollIntoView({ behavior: 'smooth', block: 'center' })
  })
  ElMessage.success(t('已回填原始输入，可修改后重新生成'))
}

// ── 发送 ─────────────────────────────────────────────────────────────────────
const canSend = computed(() => !sending.value && !sendCooldown.value && promptText.value.trim().length > 0)

function armSendCooldown() {
  sendCooldown.value = true
  if (sendCooldownTimer) clearTimeout(sendCooldownTimer)
  sendCooldownTimer = setTimeout(() => {
    sendCooldown.value = false
    sendCooldownTimer = null
  }, SEND_DEBOUNCE_MS)
}

async function send() {
  if (!canSend.value) return
  if (!selectedModelId.value) {
    ElMessage.warning(mode.value === 'image' ? t('没有可用的图片模型') : t('没有可用的视频模型'))
    return
  }
  armSendCooldown()
  sending.value = true
  try {
    const res = await sendQuickCreateMessage({
      mode: mode.value,
      prompt: promptText.value.trim(),
      model_config_id: selectedModelId.value,
      asset_refs: pendingRefs.value.map((r, index) =>
        r.kind === 'asset'
          ? { asset_id: r.asset_id, asset_image_id: r.asset_image_id, reference_alias: refReferenceAlias(index) }
          : {
              url: r.url,
              name: r.name,
              media_type: r.media_type,
              duration_seconds: r.duration_seconds,
              reference_alias: pendingReferenceAlias(r, index),
            },
      ),
      options: {
        aspect_ratio: mode.value === 'image' && !supportsImageAspectRatio.value
          ? ''
          : (aspectRatio.value === 'adaptive' ? '' : aspectRatio.value),
        resolution: mode.value === 'image'
          ? (supportsImageResolution.value ? imageResolution.value : '')
          : videoResolution.value,
        count: count.value,
        ...(mode.value === 'image' ? { quality: imageQuality.value } : {}),
        ...(mode.value === 'video' ? { duration: duration.value, generate_audio: generateAudio.value } : {}),
      },
    })
    messages.value.push(res.message)
    activePolls.add(res.message.id)
    ensurePolling()
    void nextTick(autoGrowTextarea)
    scrollToBottom()
  } catch (err: unknown) {
    ElMessage.error((err as Error)?.message || t('发送失败'))
  } finally {
    sending.value = false
  }
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter' && !e.shiftKey && !mentionVisible.value) {
    e.preventDefault()
    void send()
  }
  if (e.key === 'Escape') {
    mentionVisible.value = false
    mentionStart.value = -1
    mentionEnd.value = -1
    mentionKeyword.value = ''
  }
}

function refUrlsOf(message: QuickCreateMessage): string[] {
  return message.asset_refs.map((r) => r.image_url).filter((url): url is string => Boolean(url))
}

function refPreviewIndex(message: QuickCreateMessage, index: number): number {
  return message.asset_refs.slice(0, index).filter((r) => r.image_url).length
}

function statusLabel(message: QuickCreateMessage): string {
  switch (message.status) {
    case 'queued':
      return t('排队中')
    case 'running':
      return t('生成中')
    case 'failed':
      return t('生成失败')
    default:
      return ''
  }
}

function imageActionId(message: QuickCreateMessage, resultIndex: number): string {
  return `${message.id}:${resultIndex}`
}

function openImageEditor(message: QuickCreateMessage, resultIndex: number, url: string) {
  editTarget.value = { message, resultIndex, url }
  editInstruction.value = ''
  editDialogVisible.value = true
}

async function submitImageEdit() {
  const target = editTarget.value
  const instruction = editInstruction.value.trim()
  if (!target || !instruction) {
    ElMessage.warning(t('请输入本次修改要求'))
    return
  }
  if (instruction.length > 2000) {
    ElMessage.warning(t('本次修改要求不能超过 2000 字'))
    return
  }

  editSubmitting.value = true
  imageActionKey.value = imageActionId(target.message, target.resultIndex)
  try {
    const res = await editQuickCreateMessage({
      source_message_id: target.message.id,
      result_index: target.resultIndex,
      instruction,
    })
    messages.value.push(res.message)
    activePolls.add(res.message.id)
    ensurePolling()
    editDialogVisible.value = false
    editTarget.value = null
    ElMessage.success(t('图片编辑任务已加入队列'))
    scrollToBottom()
  } catch (err: unknown) {
    ElMessage.error((err as Error)?.message || t('发送失败'))
  } finally {
    editSubmitting.value = false
    imageActionKey.value = ''
  }
}

function regenerateMessage(message: QuickCreateMessage) {
  refillQuickCreateInput(message)
}

function guessDownloadExtension(url: string, mode: QuickCreateMode, blobType = ''): string {
  const clean = url.split('?')[0] || ''
  const match = clean.match(/\.([a-zA-Z0-9]{2,5})$/)
  if (match?.[1]) return match[1].toLowerCase()
  if (blobType.includes('jpeg')) return 'jpg'
  if (blobType.includes('png')) return 'png'
  if (blobType.includes('webp')) return 'webp'
  if (blobType.includes('mp4')) return 'mp4'
  if (blobType.includes('webm')) return 'webm'
  return mode === 'video' ? 'mp4' : 'png'
}

function downloadResult(message: QuickCreateMessage, url: string, resultIndex: number) {
  try {
    const ext = guessDownloadExtension(url, message.mode)
    const filename = `quick-create-${message.id}-${resultIndex + 1}.${ext}`
    const link = document.createElement('a')
    link.href = url
    link.download = filename
    link.target = '_blank'
    link.rel = 'noopener noreferrer'
    document.body.appendChild(link)
    link.click()
    link.remove()
  } catch (err: unknown) {
    ElMessage.error((err as Error)?.message || t('下载失败'))
  }
}
</script>

<template>
  <div class="quick-create" :class="{ 'is-gallery': viewMode === 'gallery' }">
    <div class="qc-view-bar">
      <div class="qc-view-bar-group" role="group" :aria-label="t('视图')">
        <button
          type="button"
          class="qc-view-bar-btn"
          :class="{ 'is-active': viewMode === 'chat' }"
          @click="viewMode = 'chat'"
        >
          {{ t('对话') }}
        </button>
        <button
          type="button"
          class="qc-view-bar-btn"
          :class="{ 'is-active': viewMode === 'gallery' }"
          @click="viewMode = 'gallery'"
        >
          {{ t('画廊') }}
        </button>
      </div>

      <div
        v-if="viewMode === 'gallery'"
        class="qc-view-bar-group"
        role="group"
        :aria-label="t('排列密度')"
      >
        <button
          type="button"
          class="qc-view-bar-btn"
          :class="{ 'is-active': density === 'comfortable' }"
          @click="density = 'comfortable'"
        >
          {{ t('宽松') }}
        </button>
        <button
          type="button"
          class="qc-view-bar-btn"
          :class="{ 'is-active': density === 'cozy' }"
          @click="density = 'cozy'"
        >
          {{ t('标准') }}
        </button>
        <button
          type="button"
          class="qc-view-bar-btn"
          :class="{ 'is-active': density === 'compact' }"
          @click="density = 'compact'"
        >
          {{ t('紧凑') }}
        </button>
      </div>

      <div class="qc-view-bar-group" role="group" :aria-label="t('筛选')">
        <button
          type="button"
          class="qc-view-bar-btn"
          :class="{ 'is-active': resultFilter === 'all' }"
          @click="resultFilter = 'all'"
        >
          {{ t('全部') }}
        </button>
        <button
          type="button"
          class="qc-view-bar-btn"
          :class="{ 'is-active': resultFilter === 'favorites' }"
          @click="resultFilter = 'favorites'"
        >
          {{ t('仅收藏') }}
        </button>
      </div>

      <div class="qc-view-bar-meta">
        <template v-if="viewMode === 'gallery'">
          {{ t('{count} 个成品', { count: mediaItems.length }) }}
          <span class="qc-view-bar-dot">·</span>
        </template>
        {{ t('{count} 个本机收藏', { count: favoriteCount }) }}
      </div>
    </div>

    <!-- 消息流 / 画廊 -->
    <div ref="streamEl" class="qc-stream" @scroll.passive="onStreamScroll">
      <div v-if="loadingHistory" class="qc-skeleton-list" aria-busy="true">
        <div v-for="n in 4" :key="n" class="qc-skeleton-card">
          <div class="qc-skeleton-line is-short" />
          <div class="qc-skeleton-line" />
          <div class="qc-skeleton-media" />
        </div>
      </div>

      <template v-else-if="viewMode === 'chat'">
        <div
          v-if="historyHasMore || loadingMoreHistory"
          class="qc-history-hint"
        >
          <span v-if="loadingMoreHistory">{{ t('正在加载更早记录…') }}</span>
          <button
            v-else
            type="button"
            class="qc-history-load-btn"
            @click="loadMoreHistory"
          >
            {{ t('加载更早记录') }}
          </button>
        </div>

        <div v-if="messages.length === 0" class="qc-hero">
          <h1>{{ t('体验快速生成，让创意摇动') }}</h1>
          <p>{{ t('输入描述，@ 引用资产，直接生成图片或视频') }}</p>
        </div>
        <div
          v-else-if="resultFilter === 'favorites' && visibleChatMessages.length === 0"
          class="qc-hero"
        >
          <h1>{{ t('还没有本机收藏') }}</h1>
          <p>{{ t('在成品卡片上点星标即可收藏，收藏仅保存在本机浏览器') }}</p>
        </div>

        <div v-for="message in visibleChatMessages" :key="message.id" class="qc-message">
          <div class="qc-bubble qc-bubble-user">
            <div v-if="message.asset_refs.length" class="qc-refs">
              <div
                v-for="(ref, i) in message.asset_refs"
                :key="i"
                class="qc-ref-thumb-wrap"
                :class="{ 'is-video': Boolean(ref.video_url) }"
                @mouseenter="showReferenceHoverPreview($event, ref)"
                @mouseleave="hideReferenceHoverPreview"
              >
                <el-image
                  v-if="ref.image_url"
                  :src="ref.image_url"
                  :preview-src-list="refUrlsOf(message)"
                  :initial-index="refPreviewIndex(message, i)"
                  :preview-teleported="true"
                  :hide-on-click-modal="true"
                  fit="cover"
                  loading="lazy"
                  class="qc-ref-thumb"
                />
                <button
                  v-else-if="ref.video_url"
                  type="button"
                  class="qc-ref-video-button"
                  :title="t('点击放大预览')"
                  :aria-label="t('预览参考视频')"
                  @click.stop="openMessageReferencePreview(message.id, ref, i)"
                >
                  <video :src="ref.video_url" muted playsinline preload="metadata" class="qc-ref-video" />
                  <span class="qc-ref-video-play" aria-hidden="true">
                    <el-icon :size="24"><component :is="resolveIcon('VideoPlay')" /></el-icon>
                  </span>
                </button>
                <span class="qc-ref-thumb-name">@{{ ref.name }}</span>
              </div>
            </div>
            <div
              class="qc-prompt"
              :class="{ 'is-collapsed': isLongPrompt(message) && !expandedPrompts.has(message.id) }"
            >
              <template v-if="message.prompt">
                <template v-for="(part, partIndex) in promptParts(message)" :key="`${message.id}-prompt-${partIndex}`">
                  <template v-if="part.type === 'text'">{{ part.text }}</template>
                  <span
                    v-else
                    class="qc-inline-reference"
                    @mouseenter="showReferenceHoverPreview($event, part.ref)"
                    @mouseleave="hideReferenceHoverPreview"
                  >
                    <span class="qc-inline-reference-token">{{ part.text }}</span>
                    <video
                      v-if="part.ref.video_url"
                      :src="part.ref.video_url"
                      muted
                      playsinline
                      preload="metadata"
                      class="qc-inline-reference-thumb"
                    />
                    <img
                      v-else-if="part.ref.image_url"
                      :src="part.ref.image_url"
                      :alt="part.ref.name"
                      class="qc-inline-reference-thumb"
                    />
                  </span>
                </template>
              </template>
              <template v-else>{{ t('（无文字描述）') }}</template>
            </div>
            <button
              v-if="isLongPrompt(message)"
              type="button"
              class="qc-prompt-toggle"
              @click="togglePromptExpand(message.id)"
            >
              {{ expandedPrompts.has(message.id) ? t('收起') : t('展开全部') }}
            </button>
          </div>
          <div class="qc-bubble qc-bubble-result">
            <template v-if="message.status === 'success'">
              <div class="qc-results">
                <template v-for="i in visibleResultIndexes(message)" :key="i">
                  <div
                    class="qc-media-card"
                    :class="message.mode === 'video' ? 'is-video' : 'is-image'"
                  >
                    <div class="qc-media-frame">
                      <button
                        type="button"
                        class="qc-fav-btn"
                        :class="{ 'is-active': isFavorite(message.id, i) }"
                        :title="isFavorite(message.id, i) ? t('取消收藏') : t('本机收藏')"
                        :aria-label="isFavorite(message.id, i) ? t('取消收藏') : t('本机收藏')"
                        @click.stop="toggleFavorite(message.id, i)"
                      >
                        <el-icon :size="16">
                          <component :is="resolveIcon(isFavorite(message.id, i) ? 'StarFilled' : 'Star')" />
                        </el-icon>
                      </button>
                      <div
                        v-if="message.mode === 'video'"
                        class="qc-video-shell"
                        :ref="(el) => bindLazyVideo(el as Element | null, message.id, i)"
                      >
                        <video
                          v-if="videoStageOf(message.id, i) !== 'idle'"
                          :src="message.result_urls[i]"
                          :controls="videoStageOf(message.id, i) === 'ready'"
                          :preload="videoStageOf(message.id, i) === 'ready' ? 'auto' : 'metadata'"
                          :muted="videoStageOf(message.id, i) !== 'ready'"
                          playsinline
                          class="qc-media-video"
                          @loadeddata="onPreviewVideoReady($event, message.id, i)"
                          @seeked="revealVideoPoster"
                        />
                        <div v-else class="qc-media-skeleton qc-video-skeleton" />
                        <button
                          v-if="videoStageOf(message.id, i) !== 'ready'"
                          type="button"
                          class="qc-video-play-overlay"
                          :aria-label="t('播放视频')"
                          @click.stop="activateVideoPlayback(message.id, i, $event)"
                        >
                          <el-icon :size="28"><component :is="resolveIcon('VideoPlay')" /></el-icon>
                          <span>{{ videoStageOf(message.id, i) === 'idle' ? t('加载预览') : t('播放') }}</span>
                        </button>
                      </div>
                      <el-image
                        v-else
                        :src="message.result_urls[i]"
                        :preview-src-list="message.result_urls"
                        :initial-index="i"
                        fit="cover"
                        loading="lazy"
                        class="qc-media-image"
                        :preview-teleported="true"
                        :hide-on-click-modal="true"
                      >
                        <template #placeholder>
                          <div class="qc-media-skeleton" />
                        </template>
                      </el-image>
                    </div>
                    <div class="qc-media-actions-bar">
                      <button
                        type="button"
                        class="qc-media-action-btn"
                        :class="{ 'is-loading': message.mode === 'image' && imageActionKey === imageActionId(message, i) }"
                        :disabled="message.mode === 'image' && Boolean(imageActionKey)"
                        @click.stop="regenerateMessage(message)"
                      >
                        <el-icon><component :is="resolveIcon('Refresh')" /></el-icon>
                        <span>{{ t('重新生成') }}</span>
                      </button>
                      <button
                        type="button"
                        class="qc-media-action-btn"
                        @click.stop="downloadResult(message, message.result_urls[i], i)"
                      >
                        <el-icon><component :is="resolveIcon('Download')" /></el-icon>
                        <span>{{ t('下载') }}</span>
                      </button>
                    </div>
                  </div>
                </template>
              </div>
            </template>
            <template v-else-if="message.status === 'failed'">
              <div class="qc-error">
                <el-icon><component :is="resolveIcon('CircleClose')" /></el-icon>
                {{ message.error_message || t('生成失败') }}
              </div>
              <div class="qc-media-actions-bar qc-error-actions">
                <button
                  type="button"
                  class="qc-media-action-btn"
                  @click.stop="regenerateMessage(message)"
                >
                  <el-icon><component :is="resolveIcon('Refresh')" /></el-icon>
                  <span>{{ t('重新生成') }}</span>
                </button>
              </div>
            </template>
            <template v-else>
              <div class="qc-pending">
                <el-icon class="is-loading"><component :is="resolveIcon('Loading')" /></el-icon>
                {{ statusLabel(message) }}
              </div>
            </template>
          </div>
        </div>
      </template>

      <template v-else>
        <div v-if="mediaItems.length === 0" class="qc-hero">
          <h1>{{ t('暂无成品') }}</h1>
          <p>{{ t('生成成功的图片或视频会出现在画廊中') }}</p>
        </div>
        <div
          v-else-if="resultFilter === 'favorites' && visibleMediaItems.length === 0"
          class="qc-hero"
        >
          <h1>{{ t('还没有本机收藏') }}</h1>
          <p>{{ t('在成品卡片上点星标即可收藏，收藏仅保存在本机浏览器') }}</p>
        </div>
        <div
          v-else
          class="qc-gallery-grid"
          :class="`is-${density}`"
        >
          <div
            v-for="item in visibleMediaItems"
            :key="item.key"
            class="qc-media-card is-gallery"
            :class="item.message.mode === 'video' ? 'is-video' : 'is-image'"
          >
            <div class="qc-media-frame">
              <button
                type="button"
                class="qc-fav-btn"
                :class="{ 'is-active': isFavorite(item.message.id, item.resultIndex) }"
                :title="isFavorite(item.message.id, item.resultIndex) ? t('取消收藏') : t('本机收藏')"
                :aria-label="isFavorite(item.message.id, item.resultIndex) ? t('取消收藏') : t('本机收藏')"
                @click.stop="toggleFavorite(item.message.id, item.resultIndex)"
              >
                <el-icon :size="16">
                  <component :is="resolveIcon(isFavorite(item.message.id, item.resultIndex) ? 'StarFilled' : 'Star')" />
                </el-icon>
              </button>
              <div
                v-if="item.message.mode === 'video'"
                class="qc-video-shell"
                :ref="(el) => bindLazyVideo(el as Element | null, item.message.id, item.resultIndex)"
              >
                <video
                  v-if="videoStageOf(item.message.id, item.resultIndex) !== 'idle'"
                  :src="item.url"
                  :controls="videoStageOf(item.message.id, item.resultIndex) === 'ready'"
                  :preload="videoStageOf(item.message.id, item.resultIndex) === 'ready' ? 'auto' : 'metadata'"
                  :muted="videoStageOf(item.message.id, item.resultIndex) !== 'ready'"
                  playsinline
                  class="qc-media-video"
                  @loadeddata="onPreviewVideoReady($event, item.message.id, item.resultIndex)"
                  @seeked="revealVideoPoster"
                />
                <div v-else class="qc-media-skeleton qc-video-skeleton" />
                <button
                  v-if="videoStageOf(item.message.id, item.resultIndex) !== 'ready'"
                  type="button"
                  class="qc-video-play-overlay"
                  :aria-label="t('播放视频')"
                  @click.stop="activateVideoPlayback(item.message.id, item.resultIndex, $event)"
                >
                  <el-icon :size="28"><component :is="resolveIcon('VideoPlay')" /></el-icon>
                  <span>{{ videoStageOf(item.message.id, item.resultIndex) === 'idle' ? t('加载预览') : t('播放') }}</span>
                </button>
              </div>
              <el-image
                v-else
                :src="item.url"
                :preview-src-list="galleryImageUrls"
                :initial-index="galleryImagePreviewIndex(item)"
                fit="cover"
                loading="lazy"
                class="qc-media-image"
                :preview-teleported="true"
                :hide-on-click-modal="true"
              >
                <template #placeholder>
                  <div class="qc-media-skeleton" />
                </template>
              </el-image>
            </div>
            <div class="qc-media-actions-bar">
              <button
                type="button"
                class="qc-media-action-btn"
                :title="t('重新生成')"
                :class="{ 'is-loading': item.message.mode === 'image' && imageActionKey === imageActionId(item.message, item.resultIndex) }"
                :disabled="item.message.mode === 'image' && Boolean(imageActionKey)"
                @click.stop="regenerateMessage(item.message)"
              >
                <el-icon><component :is="resolveIcon('Refresh')" /></el-icon>
                <span>{{ t('重新生成') }}</span>
              </button>
              <button
                type="button"
                class="qc-media-action-btn"
                :title="t('下载')"
                @click.stop="downloadResult(item.message, item.url, item.resultIndex)"
              >
                <el-icon><component :is="resolveIcon('Download')" /></el-icon>
                <span>{{ t('下载') }}</span>
              </button>
            </div>
          </div>
        </div>
        <div
          v-if="mediaItems.length > 0 && (historyHasMore || loadingMoreHistory)"
          class="qc-history-hint is-bottom"
        >
          <span v-if="loadingMoreHistory">{{ t('正在加载更早记录…') }}</span>
          <button
            v-else
            type="button"
            class="qc-history-load-btn"
            @click="loadMoreHistory"
          >
            {{ t('加载更早记录') }}
          </button>
        </div>
      </template>
    </div>

    <!-- 输入卡片 -->
    <div class="qc-composer">
      <div class="qc-retention-hint">
        <el-icon :size="13"><component :is="resolveIcon('QuestionFilled')" /></el-icon>
        {{ t('历史按页加载；重要图片和视频请及时下载保存') }}
      </div>

      <div v-if="pendingRefs.length" class="qc-pending-refs">
        <div
          v-for="(ref, i) in pendingRefs"
          :key="ref.key"
          class="qc-pending-ref"
          :class="{ 'is-dragging': draggedPendingRefKey === ref.key }"
          draggable="true"
          @mouseenter="showReferenceHoverPreview($event, ref)"
          @mouseleave="hideReferenceHoverPreview"
          @dragstart="startPendingRefDrag(ref.key, $event)"
          @dragover.prevent
          @drop.prevent="dropPendingRef(ref.key)"
          @dragend="draggedPendingRefKey = null"
        >
          <el-image
            v-if="ref.media_type === 'image'"
            :src="ref.url"
            :preview-src-list="pendingRefImageUrls"
            :initial-index="pendingImagePreviewIndex(i)"
            :preview-teleported="true"
            :hide-on-click-modal="true"
            fit="cover"
            loading="lazy"
            class="qc-pending-ref-thumb"
          />
          <button
            v-else
            type="button"
            class="qc-pending-ref-video-button"
            :title="t('点击放大预览')"
            :aria-label="t('预览参考视频')"
            @click.stop="openReferencePreview(ref)"
          >
            <video :src="ref.url" muted playsinline preload="metadata" class="qc-pending-ref-thumb" />
            <span class="qc-pending-ref-zoom" aria-hidden="true">
              <el-icon :size="14"><component :is="resolveIcon('ZoomIn')" /></el-icon>
            </span>
          </button>
          <button type="button" class="qc-pending-ref-remove" :title="t('移除')" @click.stop="removeRef(ref.key)">
            <el-icon :size="12"><component :is="resolveIcon('CircleClose')" /></el-icon>
          </button>
          <span class="qc-pending-ref-order">{{ i + 1 }}</span>
          <span v-if="ref.kind === 'upload'" class="qc-pending-ref-status" :title="t('已上传')">
            <el-icon :size="10"><component :is="resolveIcon('Check')" /></el-icon>
            {{ t('已上传') }}
          </span>
          <span class="qc-pending-ref-name">{{ ref.name }}</span>
        </div>
      </div>

      <div
        class="qc-input-row"
        :class="{ 'is-upload-dragging': uploadDragging }"
        @dragenter.prevent="handleUploadDragEnter"
        @dragover.prevent
        @dragleave.prevent="handleUploadDragLeave"
        @drop.prevent="handleUploadDrop"
      >
        <input
          ref="uploadInputRef"
          class="qc-upload-input"
          type="file"
          :accept="referenceAccept"
          multiple
          @change="handleUploadInput"
        />
        <div
          class="qc-upload-dropzone"
          :class="{ 'is-dragging': uploadDragging, 'is-uploading': uploadingReferences }"
        >
          <button type="button" class="qc-upload" :title="isMiniMaxVideo ? t('上传参考图片或视频') : t('上传参考图')" :disabled="uploadingReferences" @click="uploadReference">
            <el-icon :size="18"><component :is="resolveIcon('Plus')" /></el-icon>
            <span>{{ uploadingReferences ? t('上传中') : isMiniMaxVideo ? t('添加图片或视频') : t('添加图片') }}</span>
            <em>{{ isMiniMaxVideo ? t('视频最多 3 段') : t('最多 6 张') }}</em>
          </button>
        </div>

        <div class="qc-textarea-wrap">
          <div
            ref="inputEl"
            class="qc-textarea"
            contenteditable="true"
            role="textbox"
            aria-multiline="true"
            :data-placeholder="t('使用@可快速引用资产，如：参考@场景1，生成@角色2 和@角色3 打斗的视频。')"
            @keydown="onKeydown"
            @beforeinput="onPromptBeforeInput"
            @compositionstart="onPromptCompositionStart"
            @compositionend="onPromptCompositionEnd"
            @keyup="refreshMentionState"
            @click="refreshMentionState"
            @input="onPromptInput"
            @paste="onPromptPaste"
          />
          <div v-if="uploadDragging" class="qc-upload-overlay" aria-hidden="true">
            <el-icon :size="22"><component :is="resolveIcon('Plus')" /></el-icon>
            <span>{{ isMiniMaxVideo ? t('松开即可添加参考素材') : t('松开即可添加图片') }}</span>
          </div>
          <!-- @ 联想浮层：资产按图片/造型拆开，可精确选择具体某一套人物造型 -->
          <div v-if="mentionVisible" class="qc-mention">
            <div class="qc-mention-filter">
              <el-select
                v-model="mentionSeriesFilter"
                size="small"
                :placeholder="t('全部作品')"
                @change="onMentionSeriesFilterChange"
                @click.stop
              >
                <el-option :value="0" :label="t('全部作品')" />
                <el-option
                  v-if="uploadRefs.length"
                  :value="UPLOAD_SERIES_FILTER"
                  :label="t('本次上传文件')"
                />
                <el-option
                  v-for="series in seriesList"
                  :key="series.id"
                  :value="series.id"
                  :label="mentionSeriesLabel(series)"
                />
              </el-select>
            </div>
            <div v-if="mentionNeedsNarrowing" class="qc-mention-empty">
              {{ t('输入名称，或先选择作品缩小范围') }}
            </div>
            <div v-else v-loading="mentionLoading" class="qc-mention-list">
              <div v-if="!mentionLoading && mentionEntries.length === 0" class="qc-mention-empty">
                {{ t('没有匹配的资产') }}
              </div>
              <button
                v-for="entry in mentionEntries"
                :key="entry.key"
                type="button"
                class="qc-mention-item"
                :class="{ 'is-shared': entry.isShared }"
                @mousedown.prevent
                @click="pickMentionEntry(entry)"
              >
                <video v-if="entry.kind === 'upload' && entry.uploadRef?.media_type === 'video'" :src="entry.uploadRef.url" class="qc-mention-thumb" />
                <img v-else-if="entry.kind === 'upload' && entry.uploadRef?.url" :src="entry.uploadRef.url" class="qc-mention-thumb" />
                <img v-else-if="entry.image?.url" :src="entry.image.url" class="qc-mention-thumb" />
                <span class="qc-mention-name">{{ entry.label }}</span>
                <span class="qc-mention-type">{{ entry.kind === 'upload' ? t('上传') : entry.asset?.type }}</span>
              </button>
            </div>
          </div>
        </div>
      </div>

      <p class="qc-upload-limit-hint">
        {{ isMiniMaxVideo
          ? t('已选择 {count}/6 个素材；图片不超过 100MB，参考视频最多 3 段、单段不超过 50MB、总时长不超过 15 秒。', { count: pendingRefs.length })
          : t('已选择 {count}/6 张；支持拖入或选择多张图片，单张不超过 100MB。格式：JPG、JPEG、PNG、GIF、WebP。', { count: pendingRefs.length }) }}
      </p>

      <div class="qc-toolbar">
        <!-- 模式切换 -->
        <el-radio-group v-model="mode" size="small">
          <el-radio-button value="video">{{ t('视频') }}</el-radio-button>
          <el-radio-button value="image">{{ t('图片') }}</el-radio-button>
        </el-radio-group>

        <!-- 模型选择 -->
        <el-select v-model="selectedModelId" size="small" class="qc-model-select" :placeholder="t('选择模型')">
          <el-option v-for="model in availableModels" :key="model.id" :value="model.id" :label="model.name" />
        </el-select>

        <el-select v-if="mode === 'video' || supportsImageAspectRatio" v-model="aspectRatio" size="small" class="qc-param">
          <el-option
            v-for="opt in (mode === 'video' ? videoAspectRatioChoices : imageAspectRatioOptions)"
            :key="opt.value"
            :value="opt.value"
            :label="opt.label === '智能比例' ? t('智能比例') : opt.label"
          />
        </el-select>

        <el-select v-if="mode === 'video'" v-model="videoResolution" size="small" class="qc-param qc-param-narrow">
          <el-option v-for="opt in videoResolutionOptions" :key="opt" :value="opt" :label="opt" />
        </el-select>
        <el-select v-else-if="supportsImageResolution" v-model="imageResolution" size="small" class="qc-param qc-param-narrow">
          <el-option v-for="opt in imageResolutionOptions" :key="opt" :value="opt" :label="opt" />
        </el-select>

        <el-select v-if="supportsImageQuality" v-model="imageQuality" size="small" class="qc-param qc-param-narrow">
          <el-option v-for="opt in imageQualityOptions" :key="opt.value" :value="opt.value" :label="t(opt.label)" />
        </el-select>

        <el-select v-if="mode === 'video'" v-model="duration" size="small" class="qc-param qc-param-narrow">
          <el-option v-for="opt in durationOptions" :key="opt" :value="opt" :label="`${opt}${t('秒')}`" />
        </el-select>

        <el-select v-model="count" size="small" class="qc-param qc-param-narrow">
          <el-option v-for="opt in countOptions" :key="opt" :value="opt" :label="`${opt}${t('条')}`" />
        </el-select>

        <el-checkbox v-if="mode === 'video' && !isMiniMaxVideo" v-model="generateAudio" size="small">
          {{ t('输出声音') }}
        </el-checkbox>

        <button type="button" class="qc-send" :disabled="!canSend" @click="send">
          <el-icon :size="18"><component :is="resolveIcon('Top')" /></el-icon>
        </button>
      </div>
    </div>

    <el-dialog
      v-model="editDialogVisible"
      :title="t('编辑此图')"
      width="min(92vw, 720px)"
      append-to-body
      destroy-on-close
      class="qc-edit-dialog"
    >
      <div v-if="editTarget" class="qc-edit-content">
        <div class="qc-edit-preview-wrap">
          <img :src="editTarget.url" :alt="t('当前生成图片')" class="qc-edit-preview" />
        </div>
        <div class="qc-edit-form">
          <p class="qc-edit-hint">{{ t('当前图片会自动作为参考图，无需下载或重新上传。') }}</p>
          <el-input
            v-model="editInstruction"
            type="textarea"
            :rows="6"
            :maxlength="2000"
            show-word-limit
            :placeholder="t('例如：保留人物和构图，只把左手的杯子改成手机。')"
            @keydown.enter.ctrl="submitImageEdit"
          />
          <p class="qc-edit-disclaimer">{{ t('当前版本属于基于原图的重新生成，未提供蒙版时不能保证其他区域像素完全不变。') }}</p>
        </div>
      </div>
      <template #footer>
        <el-button @click="editDialogVisible = false">{{ t('取消') }}</el-button>
        <el-button type="primary" :loading="editSubmitting" :disabled="!editInstruction.trim()" @click="submitImageEdit">
          {{ t('编辑并生成') }}
        </el-button>
      </template>
    </el-dialog>

    <el-dialog
      v-model="referencePreviewVisible"
      :title="referencePreview?.name || t('参考视频预览')"
      width="min(92vw, 860px)"
      append-to-body
      destroy-on-close
      class="qc-reference-preview-dialog"
      @closed="closeReferencePreview"
    >
      <div v-if="referencePreview" class="qc-reference-preview-content">
        <video
          :src="referencePreview.url"
          controls
          autoplay
          playsinline
          preload="metadata"
          class="qc-reference-preview-video"
        />
      </div>
    </el-dialog>

    <Teleport to="body">
      <div
        v-if="referenceHoverPreview"
        class="qc-reference-hover-preview"
        :style="{
          top: `${referenceHoverPreview.top}px`,
          left: `${referenceHoverPreview.left}px`,
          width: `${referenceHoverPreview.mediaType === 'video' ? 300 : 240}px`,
        }"
        role="tooltip"
        @mouseenter="cancelReferenceHoverHide"
        @mouseleave="hideReferenceHoverPreview"
      >
        <video
          v-if="referenceHoverPreview.mediaType === 'video'"
          :src="referenceHoverPreview.url"
          autoplay
          loop
          muted
          playsinline
          preload="metadata"
          class="qc-reference-hover-media"
        />
        <img
          v-else
          :src="referenceHoverPreview.url"
          :alt="referenceHoverPreview.name"
          class="qc-reference-hover-media"
        />
        <span class="qc-reference-hover-name">{{ referenceHoverPreview.name }}</span>
      </div>
    </Teleport>
  </div>
</template>

<style scoped lang="scss">
.quick-create {
  display: flex;
  flex-direction: column;
  height: 100%;
  max-width: 960px;
  margin: 0 auto;
  padding: var(--space-lg);
  gap: var(--space-md);
  transition: max-width 0.2s ease;

  &.is-gallery {
    max-width: min(1400px, 100%);
  }
}

// ── 顶栏：视图 / 密度 / 筛选（勿与底部 .qc-toolbar 混淆）────────────────────
.qc-view-bar {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
  flex-shrink: 0;
}

.qc-view-bar-group {
  display: inline-flex;
  padding: 3px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-card);
  gap: 2px;
}

.qc-view-bar-btn {
  height: 30px;
  padding: 0 12px;
  border: none;
  border-radius: 8px;
  background: transparent;
  color: var(--muted);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;

  &:hover {
    color: var(--body-strong);
  }

  &.is-active {
    background: rgba(var(--brand-cyan-rgb), 0.12);
    color: var(--primary);
  }
}

.qc-view-bar-meta {
  margin-left: auto;
  color: var(--muted);
  font-size: 12px;
  white-space: nowrap;
}

.qc-view-bar-dot {
  margin: 0 4px;
}

// ── 首屏骨架 / 分页提示 / 视频占位 ───────────────────────────────────────────
.qc-skeleton-list {
  display: flex;
  flex-direction: column;
  gap: var(--space-lg);
  padding: var(--space-md) var(--space-sm);
}

.qc-skeleton-card {
  display: flex;
  flex-direction: column;
  gap: 10px;
  max-width: 420px;
}

.qc-skeleton-line,
.qc-skeleton-media,
.qc-media-skeleton {
  border-radius: 10px;
  background: linear-gradient(90deg, #eef1f4 25%, #f7f8fa 37%, #eef1f4 63%);
  background-size: 400% 100%;
  animation: qc-skeleton-shine 1.2s ease-in-out infinite;
}

.qc-skeleton-line {
  height: 14px;
  width: 100%;

  &.is-short {
    width: 42%;
  }
}

.qc-skeleton-media,
.qc-media-skeleton {
  width: 100%;
  aspect-ratio: 1;
}

.qc-history-hint {
  display: flex;
  justify-content: center;
  align-items: center;
  min-height: 36px;
  color: var(--muted);
  font-size: 12px;

  &.is-bottom {
    margin-top: var(--space-md);
    padding-bottom: var(--space-sm);
  }
}

.qc-history-load-btn {
  border: 1px solid var(--hairline);
  border-radius: 999px;
  background: var(--surface-card);
  color: var(--primary);
  font-size: 12px;
  font-weight: 600;
  height: 30px;
  padding: 0 14px;
  cursor: pointer;

  &:hover {
    background: rgba(var(--brand-cyan-rgb), 0.08);
  }
}

.qc-video-shell {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  background: #0f172a;
}

.qc-video-skeleton {
  position: absolute;
  inset: 0;
  border-radius: 0;
}

.qc-video-play-overlay {
  position: absolute;
  inset: 0;
  z-index: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border: none;
  background: rgba(15, 23, 42, 0.28);
  color: #fff;
  cursor: pointer;

  span {
    font-size: 12px;
    font-weight: 600;
    text-shadow: 0 1px 2px rgba(0, 0, 0, 0.45);
  }

  &:hover {
    background: rgba(15, 23, 42, 0.4);
  }
}

@keyframes qc-skeleton-shine {
  0% { background-position: 100% 0; }
  100% { background-position: 0 0; }
}

// ── 消息流 ───────────────────────────────────────────────────────────────────
.qc-stream {
  flex: 1;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: var(--space-lg);
  padding: var(--space-md) var(--space-sm);
}

.qc-gallery-grid {
  display: grid;
  gap: var(--space-md);
  width: 100%;
  align-items: start;

  &.is-comfortable {
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
  }

  &.is-cozy {
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  }

  &.is-compact {
    grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  }
}

.qc-hero {
  margin: auto;
  text-align: center;

  h1 {
    font-size: 26px;
    font-weight: 800;
    color: var(--body-strong);
  }

  p {
    margin-top: var(--space-sm);
    color: var(--muted);
    font-size: 14px;
  }
}

.qc-message {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
}

.qc-bubble {
  border-radius: var(--radius-md);
  padding: var(--space-md);
  max-width: 85%;
}

.qc-bubble-user {
  align-self: flex-end;
  background: rgba(var(--brand-cyan-rgb), 0.08);
  border: 1px solid var(--hairline);
}

.qc-prompt {
  white-space: pre-wrap;
  word-break: break-word;
  font-size: 14px;
  line-height: 1.6;
  color: var(--body-strong);

  &.is-collapsed {
    display: -webkit-box;
    -webkit-line-clamp: 4;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }
}

.qc-inline-reference {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  margin: 0 2px;
  vertical-align: middle;
  white-space: nowrap;
  cursor: zoom-in;
}

:deep(.qc-editor-reference) {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  margin: 0 2px;
  vertical-align: middle;
  white-space: nowrap;
  cursor: zoom-in;
  user-select: all;
}

:deep(.qc-editor-reference .qc-inline-reference-token) {
  color: var(--primary);
  font-weight: 600;
}

:deep(.qc-editor-reference .qc-inline-reference-thumb) {
  display: inline-block;
  width: 30px;
  height: 22px;
  flex: 0 0 30px;
  border: 1px solid var(--hairline);
  border-radius: 4px;
  background: #0f172a;
  object-fit: cover;
  pointer-events: none;
}

:deep(.qc-editor-reference:hover .qc-inline-reference-thumb) {
  transform: scale(1.06);
}

.qc-inline-reference-token {
  color: var(--primary);
  font-weight: 600;
}

.qc-inline-reference-thumb {
  display: inline-block;
  width: 30px;
  height: 22px;
  border: 1px solid var(--hairline);
  border-radius: 4px;
  background: #0f172a;
  object-fit: cover;
  pointer-events: none;
  transition: transform var(--duration-fast) var(--ease-out);
}

.qc-inline-reference:hover .qc-inline-reference-thumb {
  transform: scale(1.06);
}

.qc-prompt-toggle {
  display: block;
  margin-top: 4px;
  margin-left: auto;
  border: none;
  background: transparent;
  color: var(--primary);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  padding: 0;

  &:hover {
    text-decoration: underline;
  }
}

.qc-refs {
  margin-bottom: var(--space-sm);
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-sm);
}

.qc-ref-thumb-wrap {
  width: 56px;
  flex-shrink: 0;

  &.is-video {
    width: 160px;
    max-width: 100%;
  }
}

.qc-ref-thumb {
  width: 56px;
  height: 56px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--hairline);
  display: block;
  cursor: zoom-in;
  overflow: hidden;
}

.qc-ref-thumb-name {
  display: block;
  margin-top: 2px;
  font-size: 10px;
  color: var(--muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-align: center;
}

.qc-ref-video-button {
  position: relative;
  display: block;
  width: 160px;
  max-width: 100%;
  aspect-ratio: 16 / 9;
  padding: 0;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  background: #0f172a;
  cursor: zoom-in;
  overflow: hidden;

  &:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
  }

  &:hover .qc-ref-video {
    transform: scale(1.03);
  }
}

.qc-ref-video {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  pointer-events: none;
  transition: transform var(--duration-fast) var(--ease-out);
}

.qc-ref-video-play {
  position: absolute;
  top: 50%;
  left: 50%;
  display: inline-flex;
  width: 42px;
  height: 42px;
  align-items: center;
  justify-content: center;
  border: 1px solid rgba(255, 255, 255, 0.45);
  border-radius: 50%;
  background: rgba(15, 23, 42, 0.72);
  color: #fff;
  transform: translate(-50%, -50%);
  backdrop-filter: blur(4px);
  pointer-events: none;
}

.qc-reference-hover-preview {
  position: fixed;
  z-index: 3000;
  width: min(300px, calc(100vw - 24px));
  padding: 6px;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md, 12px);
  background: var(--surface-card);
  box-shadow: 0 14px 36px rgba(15, 23, 42, 0.2);
  pointer-events: auto;
  animation: qc-reference-hover-in 120ms ease-out;
}

.qc-reference-hover-media {
  display: block;
  width: 100%;
  max-height: 240px;
  border-radius: var(--radius-sm, 8px);
  background: #0f172a;
  object-fit: contain;
}

.qc-reference-hover-name {
  display: block;
  margin-top: 5px;
  overflow: hidden;
  color: var(--muted);
  font-size: 11px;
  line-height: 1.3;
  text-align: center;
  text-overflow: ellipsis;
  white-space: nowrap;
}

@keyframes qc-reference-hover-in {
  from {
    opacity: 0;
    transform: translateY(3px) scale(0.98);
  }

  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

.qc-bubble-result {
  align-self: flex-start;
  background: var(--surface-card);
  border: 1px solid var(--hairline);
}

.qc-results {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-md);
}

.qc-media-card {
  display: flex;
  flex-direction: column;
  gap: 10px;
  max-width: 100%;
}

.qc-media-card.is-image {
  width: 232px;
}

.qc-media-card.is-video {
  width: 320px;
}

.qc-media-card.is-gallery {
  width: 100%;
  min-width: 0;
}

.qc-media-frame {
  position: relative;
  width: 100%;
  border-radius: 12px;
  overflow: hidden;
  background: var(--surface-soft);
}

.qc-media-card.is-image .qc-media-frame {
  height: 232px;
  border: 1px solid var(--hairline);
}

.qc-media-card.is-video .qc-media-frame {
  aspect-ratio: 1;
  border: 1px solid var(--hairline);
  background: #0f172a;
}

/* 画廊统一瓦片：图片/视频同一正方形框，cover 铺满，避免混排忽大忽小 */
.qc-media-card.is-gallery .qc-media-frame {
  height: auto;
  aspect-ratio: 1;
  border: 1px solid var(--hairline);
  background: #0f172a;
}

.qc-media-card.is-gallery .qc-media-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.qc-media-card .qc-media-video {
  position: absolute;
  inset: 0;
  width: 100%;
  height: 100%;
  object-fit: cover;
  background: #0f172a;
}

.qc-media-card.is-gallery .qc-media-actions-bar {
  min-height: 36px;
}

.qc-fav-btn {
  position: absolute;
  top: 8px;
  right: 8px;
  z-index: 2;
  width: 30px;
  height: 30px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid rgba(255, 255, 255, 0.35);
  border-radius: 50%;
  background: rgba(15, 23, 42, 0.55);
  color: #fff;
  cursor: pointer;
  backdrop-filter: blur(4px);

  &:hover {
    background: rgba(15, 23, 42, 0.72);
  }

  &.is-active {
    color: #f5c518;
    border-color: rgba(245, 197, 24, 0.55);
  }
}

.qc-gallery-grid.is-compact .qc-media-action-btn span {
  display: none;
}

.qc-gallery-grid.is-compact .qc-media-action-btn {
  width: 36px;
  padding: 0;
  justify-content: center;
}

.qc-media-video {
  display: block;
  width: 100%;
  background: #0f172a;
}

.qc-media-image {
  width: 100%;
  height: 100%;
  cursor: zoom-in;
}

/* 参考图：内容在上，下方常显固定图标按钮 */
.qc-media-actions-bar {
  display: flex;
  align-items: center;
  gap: 10px;
}

.qc-media-action-btn {
  height: 36px;
  padding: 0 12px;
  border: none;
  border-radius: 10px;
  background: #eef1f4;
  color: #5b6470;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 13px;
  line-height: 1;
  white-space: nowrap;
  cursor: pointer;
  flex: 0 0 auto;

  &:hover:not(:disabled) {
    background: #e3e7ec;
    color: var(--primary);
  }

  &:disabled,
  &.is-loading {
    cursor: wait;
    opacity: 0.6;
  }
}

.qc-edit-content {
  display: grid;
  grid-template-columns: minmax(180px, 280px) minmax(0, 1fr);
  gap: var(--space-lg);
  align-items: start;
}

.qc-edit-preview-wrap {
  border-radius: var(--radius-md);
  overflow: hidden;
  background: var(--surface-soft);
  border: 1px solid var(--hairline);
}

.qc-edit-preview {
  display: block;
  width: 100%;
  aspect-ratio: 1;
  object-fit: contain;
}

.qc-edit-form {
  min-width: 0;
}

.qc-edit-hint,
.qc-edit-disclaimer {
  margin: 0 0 var(--space-sm);
  color: var(--muted);
  font-size: 12px;
  line-height: 1.6;
}

.qc-edit-disclaimer {
  margin: var(--space-sm) 0 0;
  color: var(--muted-soft);
}

.qc-reference-preview-content {
  display: flex;
  justify-content: center;
  padding: 0 0 var(--space-xs);
  background: #0f172a;
  border-radius: var(--radius-md);
  overflow: hidden;
}

.qc-reference-preview-video {
  display: block;
  width: 100%;
  max-height: min(70vh, 640px);
  object-fit: contain;
  background: #0f172a;
}

@media (max-width: 680px) {
  .qc-edit-content {
    grid-template-columns: 1fr;
  }

  .qc-edit-preview-wrap {
    width: min(100%, 280px);
    justify-self: center;
  }
}

.qc-pending,
.qc-error {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  font-size: 13px;
  color: var(--muted);
}

.qc-error {
  color: var(--accent-rose);
}

.qc-error-actions {
  margin-top: var(--space-sm);
}

// ── 输入卡片 ─────────────────────────────────────────────────────────────────
.qc-composer {
  border: 1px solid var(--hairline);
  border-radius: var(--radius-lg, 16px);
  background: var(--surface-card);
  padding: var(--space-md);
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
  flex-shrink: 0;
}

.qc-retention-hint {
  display: flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  color: var(--muted-soft);
}

.qc-pending-refs {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-sm);
}

.qc-pending-ref {
  position: relative;
  width: 88px;
  flex-shrink: 0;
  cursor: grab;

  &:active {
    cursor: grabbing;
  }

  &.is-dragging {
    opacity: 0.45;
  }
}

.qc-pending-ref-thumb {
  width: 88px;
  height: 72px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--hairline);
  background: var(--surface-soft);
  display: block;
  cursor: zoom-in;
  overflow: hidden;
}

.qc-pending-ref-video-button {
  position: relative;
  display: block;
  width: 88px;
  height: 72px;
  padding: 0;
  border: 0;
  border-radius: var(--radius-sm);
  background: transparent;
  cursor: zoom-in;
  overflow: hidden;

  &:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
  }

  .qc-pending-ref-thumb {
    cursor: zoom-in;
  }
}

.qc-pending-ref-zoom {
  position: absolute;
  right: 4px;
  bottom: 4px;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: rgba(15, 23, 42, 0.72);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  pointer-events: none;
}

.qc-pending-ref-name {
  display: block;
  margin-top: 2px;
  font-size: 10px;
  color: var(--muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  text-align: center;
}

.qc-pending-ref-remove {
  position: absolute;
  top: 3px;
  right: 3px;
  width: 18px;
  height: 18px;
  border: none;
  border-radius: 50%;
  background: rgba(0, 0, 0, 0.45);
  backdrop-filter: blur(2px);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  padding: 0;
  opacity: 0.7;
  transform: scale(0.9);
  transition: opacity var(--duration-fast) var(--ease-out), transform var(--duration-fast) var(--ease-out), background var(--duration-fast);

  .qc-pending-ref:hover & {
    opacity: 1;
    transform: scale(1);
  }

  &:hover {
    background: var(--accent-rose);
  }
}

.qc-pending-ref-order {
  position: absolute;
  top: 3px;
  left: 3px;
  min-width: 16px;
  height: 16px;
  padding: 0 4px;
  border-radius: 999px;
  background: rgba(15, 23, 42, 0.66);
  color: #fff;
  font-size: 10px;
  line-height: 16px;
  text-align: center;
  pointer-events: none;
}

.qc-pending-ref-status {
  position: absolute;
  right: 4px;
  bottom: 24px;
  display: inline-flex;
  align-items: center;
  gap: 2px;
  min-height: 16px;
  padding: 0 5px;
  border-radius: 999px;
  background: rgba(16, 185, 129, 0.92);
  color: #fff;
  font-size: 9px;
  line-height: 16px;
  pointer-events: none;
}

.qc-input-row {
  position: relative;
  display: flex;
  gap: var(--space-sm);
  align-items: flex-start;

  &.is-upload-dragging .qc-textarea-wrap {
    border-radius: var(--radius-md);
    outline: 2px dashed var(--primary);
    outline-offset: 4px;
    background: rgba(var(--brand-cyan-rgb), 0.08);
  }
}

.qc-upload {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  width: 72px;
  height: 72px;
  border: 1px dashed var(--hairline-strong);
  border-radius: var(--radius-md);
  background: var(--surface-soft);
  color: var(--muted);
  cursor: pointer;
  font-size: 11px;
  flex-shrink: 0;

  &:hover {
    color: var(--primary);
    border-color: var(--primary);
  }
}

.qc-upload-input {
  display: none;
}

.qc-upload-dropzone {
  width: 92px;
  height: 72px;
  flex-shrink: 0;
  border-radius: var(--radius-md);
  transition: background var(--duration-fast) var(--ease-out), box-shadow var(--duration-fast) var(--ease-out);

  &.is-dragging {
    background: rgba(var(--brand-cyan-rgb), 0.12);
    box-shadow: 0 0 0 2px var(--primary) inset;
  }

  &.is-uploading {
    cursor: wait;
    opacity: 0.7;
  }

  .qc-upload {
    width: 100%;
    height: 100%;
  }

  .qc-upload em {
    color: var(--muted-soft);
    font-size: 9px;
    font-style: normal;
  }
}

.qc-upload-limit-hint {
  margin: -4px 0 0 84px;
  color: var(--muted-soft);
  font-size: 11px;
  line-height: 1.45;
}

.qc-textarea-wrap {
  position: relative;
  flex: 1;
  min-width: 0;
  border-radius: var(--radius-md);
  transition: background var(--duration-fast) var(--ease-out), outline var(--duration-fast) var(--ease-out);
}

.qc-upload-overlay {
  position: absolute;
  z-index: 21;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border-radius: var(--radius-md);
  background: rgba(239, 249, 255, 0.92);
  color: var(--primary);
  font-size: 13px;
  font-weight: 800;
  pointer-events: none;
}

.qc-textarea {
  width: 100%;
  min-height: 72px;
  max-height: 240px;
  border: none;
  outline: none;
  background: transparent;
  box-sizing: border-box;
  position: relative;
  z-index: 1;
  padding: 10px 12px;
  font-size: 14px;
  line-height: 1.6;
  color: var(--body-strong);
  font-family: inherit;
  overflow-y: auto;
  white-space: pre-wrap;
  word-break: break-word;
  cursor: text;

  &:empty::before {
    content: attr(data-placeholder);
    color: var(--muted-soft);
    pointer-events: none;
  }

  &:focus-visible {
    outline: none;
  }
}

// ── @ 联想浮层 ───────────────────────────────────────────────────────────────
.qc-mention {
  position: absolute;
  bottom: calc(100% + 6px);
  left: 0;
  width: 320px;
  max-height: 320px;
  display: flex;
  flex-direction: column;
  background: var(--surface-card);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
  padding: var(--space-xs);
  z-index: 20;
}

.qc-mention-filter {
  flex-shrink: 0;
  padding: 4px 4px 8px;
  border-bottom: 1px solid var(--hairline);
  margin-bottom: 4px;

  :deep(.el-select) {
    width: 100%;
  }
}

.qc-mention-list {
  flex: 1;
  overflow-y: auto;
}

.qc-mention-empty {
  padding: var(--space-md);
  text-align: center;
  color: var(--muted);
  font-size: 13px;
}

.qc-mention-item {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  width: 100%;
  border: none;
  background: transparent;
  padding: 6px 8px;
  border-radius: var(--radius-sm);
  cursor: pointer;
  text-align: left;

  &:hover {
    background: var(--surface-soft);
  }

  &.is-shared .qc-mention-name {
    color: var(--primary);
  }
}

.qc-mention-thumb {
  width: 32px;
  height: 32px;
  border-radius: var(--radius-sm);
  object-fit: cover;
  flex-shrink: 0;
}

.qc-mention-name {
  flex: 1;
  min-width: 0;
  font-size: 13px;
  font-weight: 600;
  color: var(--body-strong);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.qc-mention-type {
  font-size: 11px;
  color: var(--muted-soft);
}

// ── 工具条 ───────────────────────────────────────────────────────────────────
.qc-toolbar {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  flex-wrap: wrap;
}

.qc-model-select {
  width: 180px;
}

.qc-param {
  width: 110px;
}

.qc-param-narrow {
  width: 84px;
}

.qc-send {
  margin-left: auto;
  width: 36px;
  height: 36px;
  border: none;
  border-radius: var(--radius-md);
  background: var(--primary);
  color: #fff;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;

  &:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }
}
</style>
