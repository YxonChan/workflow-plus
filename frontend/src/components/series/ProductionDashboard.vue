<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  ArrowLeft,
  ArrowRight,
  Box,
  Check,
  Connection,
  DocumentCopy,
  EditPen,
  Files,
  Film,
  Loading,
  MagicStick,
  Picture,
  Plus,
  Delete,
  VideoPause,
  VideoPlay,
  CircleClose,
} from '@element-plus/icons-vue'
import { ElMessage } from 'element-plus'
import { previewEpisodeVideoPrompts, type VideoPromptPreview } from '@/api/series'
import { t as translateMessage } from '@/i18n'
import { isAssetImageJobPending, isAssetImageJobQueued } from '@/utils/assetImageJob'
import type { Asset, AssetImage, AssetImageVersion, Episode, Series, StoryboardAssetRefNode, StoryboardRichNode, WorkflowBundle } from '@/types'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import AssetLinkedText from '@/components/series/AssetLinkedText.vue'
import { copyTextToClipboard } from '@/utils/clipboard'
import { VISUAL_STYLE_OPTIONS, visualStyleVariantOptions, type VisualStyle } from '@/utils/visualStyle'

type StageNode = NonNullable<Episode['workflow_state']>['nodes'][number]
type AssetKind = 'character' | 'scene' | 'prop'
type NodeKindTone = 'input' | 'text' | 'image' | 'video' | 'output' | 'voice' | 'other'
type StoryboardShot = {
  index: number
  title: string
  text: string
  contentText?: string
  contentRichJson?: StoryboardRichNode[]
  assetRefs?: StoryboardAssetRefNode[]
  shotKey?: string
}
type AssetReferenceKind = AssetKind | 'look'

interface AssetReferenceTarget {
  label: string
  role: 'view' | 'look'
  assetImageId?: number | null
  assetImageVersionId?: number | null
  url?: string
}

interface AssetReferenceOption {
  key: string
  label: string
  insertText: string
  type: AssetReferenceKind
  subtitle: string
  image: string
  asset: Asset
  reference?: AssetReferenceTarget
}

interface StoryboardShotDraft {
  title: string
  text: string
  contentRichJson?: StoryboardRichNode[]
}

interface MentionState {
  active: boolean
  query: string
  start: number
  end: number
}

interface StoryboardHighlightSegment {
  type: 'text' | 'reference'
  content: string
  option?: AssetReferenceOption
}

function assetImageVersionUrl(version: AssetImageVersion): string {
  return String(version.url || '').trim()
}

function isCurrentAssetImageVersion(img: AssetImage, version: AssetImageVersion): boolean {
  const versionUrl = assetImageVersionUrl(version)
  if (!versionUrl) return false
  const currentUrl = String(img.url || '').trim()
  return !!version.is_selected || (!!currentUrl && versionUrl === currentUrl)
}

const props = defineProps<{
  series: Series | null
  episodes: Episode[]
  assets: Asset[]
  batchGenerating?: boolean
  batchCancelling?: boolean
  cancellingAssetIds?: Record<number, boolean>
  bundles?: WorkflowBundle[]
  busy?: boolean
  locked?: boolean
  lockedMessage?: string
  runningEpisodeIds?: number[]
  runningStageKeys?: string[]
}>()

const emit = defineEmits<{
  (e: 'back'): void
  (e: 'new'): void
  (e: 'delete-episode', episode: Episode): void
  (e: 'run-episode', id: number): void
  (e: 'cancel-auto-run', id: number): void
  (e: 'run-series-workflow'): void
  (e: 'update-series-title', title: string, done: (ok: boolean) => void): void
  (e: 'update-plot', episodeId: number, plotInput: string, done: (ok: boolean) => void): void
  (e: 'run-stage', episodeId: number, nodeId: string): void
  (e: 'cancel-stage', episodeId: number, nodeId: string): void
  (e: 'run-video-shot', episodeId: number, nodeId: string, shotIndex: number): void
  (e: 'cancel-video-shot', episodeId: number, nodeId: string, jobId: number): void
  (e: 'rerun-video-shot', episodeId: number, nodeId: string, jobId: number, prompt: string, shotIndex: number): void
  (e: 'regenerate-storyboard-shot', episodeId: number, nodeId: string, shotIndex: number, done: (ok: boolean) => void): void
  (e: 'select-media-version', episodeId: number, versionId: number): void
  (e: 'preview-video-prompts', episodeId: number, nodeId: string): void
  (e: 'bind-workflow', episodeId: number): void
  (e: 'edit-asset', asset: Asset, reference?: AssetReferenceTarget): void
  (e: 'batch-generate-core-images'): void
  (e: 'batch-cancel-queued-image-jobs'): void
  (e: 'cancel-asset-queued-image-jobs', asset: Asset): void
  (e: 'update-node-content', episodeId: number, nodeId: string, content: string, storyboardShots: Array<{ index: number; title: string; content_text: string; content_rich_json: StoryboardRichNode[]; shot_key?: string }>, options: { updateMode?: 'single_shot' | 'full'; changedShotIndex?: number }, done: (ok: boolean) => void): void
}>()

const { t } = useI18n()

const activeEpisodeId = ref<number | null>(null)
const expandedNodeIds = ref<Record<string, boolean>>({})
const selectedShotKeys = ref<Record<string, string>>({})
const shotPromptDrafts = ref<Record<string, string>>({})
const storyboardEditingKeys = ref<Record<string, boolean>>({})
const storyboardShotDrafts = ref<Record<string, StoryboardShotDraft>>({})
const storyboardSavePendingKeys = ref<Record<string, boolean>>({})
const storyboardRegeneratePendingKeys = ref<Record<string, boolean>>({})
const storyboardMentionStates = ref<Record<string, MentionState>>({})
const activatedVideoUrls = ref<Record<string, boolean>>({})
const videoPromptPreviewCache = ref<Record<string, { preview: VideoPromptPreview; signature: string }>>({})
const DEFAULT_VIDEO_NODE_PROMPT = '基于当前分镜描述生成视频片段；涉及人物、人物造型、场景、道具时优先参考资产库保持一致性。'
const previewAsset = ref<Asset | null>(null)
const previewVisible = ref(false)
const previewActiveUrl = ref('')
const plotEditingEpisodeId = ref<number | null>(null)
const plotDraft = ref('')
const plotSavePendingEpisodeId = ref<number | null>(null)
const seriesTitleEditing = ref(false)
const seriesTitleDraft = ref('')
const seriesTitleSaving = ref(false)

/** 仅剧本解析进行中禁止改名；集级自动执行不挡作品名称 */
const seriesTitleLocked = computed(() => !!props.busy)

const sortedEpisodes = computed(() => [...props.episodes].sort((a, b) => a.number - b.number))
const activeEpisode = computed(() =>
  sortedEpisodes.value.find((ep) => ep.id === activeEpisodeId.value) ?? sortedEpisodes.value[0] ?? null,
)

const activeEpisodeAutoRunning = computed(() => activeEpisode.value?.workflow_state?.auto_execution_locked === true)
const activePlotEditing = computed(() => !!activeEpisode.value && plotEditingEpisodeId.value === activeEpisode.value.id)
const activePlotSaving = computed(() => !!activeEpisode.value && plotSavePendingEpisodeId.value === activeEpisode.value.id)
const hasStoryboardRegeneratePending = computed(() => Object.keys(storyboardRegeneratePendingKeys.value).length > 0)
const activePlotLocked = computed(() => {
  const episode = activeEpisode.value
  return !episode || props.busy === true || props.locked === true || activeEpisodeAutoRunning.value || activePlotSaving.value || isEpisodeSubmitting(episode.id)
})

const seriesProgress = computed(() => {
  const total = props.series?.episodes?.length ?? props.episodes.length
  const done = (props.series?.episodes ?? props.episodes).filter((episode) => episode.status === 'done').length
  return { total, done }
})

const seriesRegionLabel = computed(() => {
  const region = String(props.series?.region || 'china')
  return region === 'western' ? t('欧美') : t('中国')
})

const seriesStyleLabel = computed(() => {
  const style = String(props.series?.visual_style || 'realistic') as VisualStyle
  const styleLabel = VISUAL_STYLE_OPTIONS.find((item) => item.value === style)?.label || style
  const variant = String(props.series?.visual_style_variant || '').trim()
  const variantLabel = variant
    ? (visualStyleVariantOptions(style).find((item) => item.value === variant)?.label || variant)
    : ''
  return variantLabel ? `${t(styleLabel)} · ${t(variantLabel)}` : t(styleLabel)
})

/** 已有剧集时视为剧本解析完成，隐藏「运行剧本解析」避免重复点击。 */
const seriesParseCompleted = computed(() => {
  const total = props.series?.episodes?.length ?? props.episodes.length
  return total > 0
})

const assetGroups = computed<Record<AssetKind, Asset[]>>(() => {
  const groups: Record<AssetKind, Asset[]> = { character: [], scene: [], prop: [] }
  for (const asset of props.assets) {
    if (asset.type === 'character' || asset.type === 'scene' || asset.type === 'prop') {
      groups[asset.type].push(asset)
    }
  }
  return groups
})

const assetReferenceOptions = computed<AssetReferenceOption[]>(() => {
  const options: AssetReferenceOption[] = []
  for (const asset of props.assets) {
    const name = asset.name?.trim() ?? ''
    if (!name) continue
    // owner_user_id 仅在“别人分享给我”的资产上才会被后端附带；不影响 label/insertText（保持正文纯净、与后端精确匹配一致），只标注在 subtitle 里。
    const ownerSuffix = asset.owner_user_id !== undefined
      ? ` · ${t('来自 {name}', { name: asset.owner_name || t('未知用户') })}`
      : ''
    const image = assetImage(asset)
    options.push({
      key: `asset:${asset.id}`,
      label: name,
      insertText: `@${name}`,
      type: asset.type,
      subtitle: assetTypeLabel(asset.type) + ownerSuffix,
      image,
      asset,
      reference: { label: name, role: 'view', url: image },
    })

    if (asset.type !== 'character') continue
    for (const img of asset.images ?? []) {
      if ((img.reference_role ?? 'view') !== 'look') continue
      const variantName = img.variant_name?.trim() ?? ''
      const url = img.url?.trim() ?? ''
      if (!variantName) continue
      const label = makeLookReferenceName(name, variantName)
      options.push({
        key: `look:${asset.id}:${img.id ?? variantName}`,
        label,
        insertText: `@${label}`,
        type: 'look',
        subtitle: t('人物造型') + ownerSuffix,
        image: url || image,
        asset,
        reference: {
          label,
          role: 'look',
          assetImageId: img.id ?? null,
          url,
        },
      })
      const versions = Array.isArray(img.versions) ? img.versions : []
      versions.forEach((version, versionIndex) => {
        const versionUrl = assetImageVersionUrl(version)
        if (!versionUrl || isCurrentAssetImageVersion(img, version)) return
        const versionLabel = `${label}（版本 ${versionIndex + 1}）`
        options.push({
          key: `look-version:${asset.id}:${img.id ?? variantName}:${version.id}`,
          label: versionLabel,
          insertText: `@${versionLabel}`,
          type: 'look',
          subtitle: t('人物造型') + ownerSuffix,
          image: versionUrl || url || image,
          asset,
          reference: {
            label: versionLabel,
            role: 'look',
            assetImageId: img.id ?? null,
            assetImageVersionId: version.id ?? null,
            url: versionUrl,
          },
        })
      })
    }
  }
  return options
})

const assetReferenceOptionsSorted = computed(() =>
  [...assetReferenceOptions.value]
    .filter((option) => option.label)
    .sort((a, b) => b.label.length - a.label.length),
)

const assetReferenceOptionsByNormalizedLabel = computed(() => {
  const map = new Map<string, AssetReferenceOption>()
  assetReferenceOptions.value.forEach((option) => {
    map.set(normalizedReferenceText(option.label), option)
  })
  return map
})

const assetReferenceOptionsByRefKey = computed(() => {
  const map = new Map<string, AssetReferenceOption>()
  assetReferenceOptions.value.forEach((option) => {
    const assetImageId = option.reference?.assetImageId ?? null
    const assetImageVersionId = option.reference?.assetImageVersionId ?? null
    const referenceRole = option.reference?.role ?? 'view'
    map.set(`${option.asset.id}:${assetImageId ?? 0}:${assetImageVersionId ?? 0}:${referenceRole}`, option)
  })
  return map
})

const storyboardShotsByNodeId = computed<Record<string, StoryboardShot[]>>(() => {
  const map: Record<string, StoryboardShot[]> = {}
  const episodeShotKeyByIndex = new Map<number, string>()
  for (const shot of activeEpisode.value?.shots ?? []) {
    const index = Number(shot.index || 0)
    const key = String(shot.shot_key || '').trim()
    if (index > 0 && key) episodeShotKeyByIndex.set(index, key)
  }
  stageRows(activeEpisode.value).forEach((node) => {
    if (!isStoryboardProcessNode(node)) return
    const structured = normalizeStoryboardShots(node.output_json)
    const shots = structured.length ? structured : parseStoryboardText(storyboardText(node).trim())
    map[node.workflow_node_id] = shots.map((shot) => ({
      ...shot,
      shotKey: shot.shotKey || episodeShotKeyByIndex.get(shot.index) || '',
    }))
  })
  return map
})

const previewImages = computed(() => (previewAsset.value ? assetImages(previewAsset.value) : []))

watch(
  sortedEpisodes,
  (episodes) => {
    if (!episodes.length) {
      activeEpisodeId.value = null
      plotEditingEpisodeId.value = null
      plotDraft.value = ''
      return
    }
    if (!activeEpisodeId.value || !episodes.some((episode) => episode.id === activeEpisodeId.value)) {
      activeEpisodeId.value = episodes[0].id
    }
  },
  { immediate: true },
)

watch(
  () => activeEpisode.value?.id,
  () => {
    plotEditingEpisodeId.value = null
    plotDraft.value = ''
  },
)

function episodeCover(episode: Episode): string {
  const fromShots = (episode.shots ?? []).map((shot) => shotImageUrl(shot)).find(Boolean) ?? ''
  return fromShots || episode.cover_url || ''
}

function episodeStatus(episode: Episode): { text: string; tone: 'busy' | 'done' | 'warning' } {
  const running = (episode.workflow_state?.nodes ?? []).some((node) => node.status === 'running')
  if (running) return { text: t('生成中'), tone: 'busy' }
  const stale = (episode.workflow_state?.nodes ?? []).some((node) => node.status === 'stale')
  if (stale) return { text: t('需重生成'), tone: 'warning' }
  if (episode.status === 'done') return { text: t('已完成'), tone: 'done' }
  return { text: t('待生成'), tone: 'warning' }
}

function episodePlotSummary(episode: Episode): string {
  return episode.plot_input?.trim() || t('暂无剧情简介。可以先执行流程节点，或补充这一集的剧情输入。')
}

function beginPlotEdit(episode: Episode) {
  if (activePlotLocked.value) return
  plotEditingEpisodeId.value = episode.id
  plotDraft.value = episode.plot_input ?? ''
}

function beginSeriesTitleEdit() {
  if (seriesTitleLocked.value || seriesTitleSaving.value || !props.series) return
  seriesTitleDraft.value = props.series.title || ''
  seriesTitleEditing.value = true
}

function cancelSeriesTitleEdit() {
  if (seriesTitleSaving.value) return
  seriesTitleEditing.value = false
  seriesTitleDraft.value = ''
}

function saveSeriesTitleEdit() {
  if (seriesTitleLocked.value || seriesTitleSaving.value || !props.series) return
  const next = seriesTitleDraft.value.trim()
  if (!next) {
    ElMessage.warning(t('请输入作品名称'))
    return
  }
  if (next === (props.series.title || '').trim()) {
    cancelSeriesTitleEdit()
    return
  }

  seriesTitleSaving.value = true
  emit('update-series-title', next, (ok) => {
    seriesTitleSaving.value = false
    if (ok) {
      seriesTitleEditing.value = false
      seriesTitleDraft.value = ''
    }
  })
}

watch(
  () => props.series?.id,
  () => {
    seriesTitleEditing.value = false
    seriesTitleDraft.value = ''
    seriesTitleSaving.value = false
  },
)

function beginStagePlotEdit(episode: Episode, nodeId: string) {
  expandedNodeIds.value = { ...expandedNodeIds.value, [nodeId]: true }
  beginPlotEdit(episode)
}

function cancelPlotEdit() {
  if (activePlotSaving.value) return
  plotEditingEpisodeId.value = null
  plotDraft.value = ''
}

function savePlotEdit(episode: Episode) {
  if (activePlotLocked.value || plotSavePendingEpisodeId.value !== null) return
  if (plotDraft.value === (episode.plot_input ?? '')) {
    cancelPlotEdit()
    return
  }

  plotSavePendingEpisodeId.value = episode.id
  emit('update-plot', episode.id, plotDraft.value, (ok) => {
    if (ok) {
      plotEditingEpisodeId.value = null
      plotDraft.value = ''
    }
    plotSavePendingEpisodeId.value = null
  })
}

function workflowLabel(name: string | undefined, id: number | null | undefined): string {
  const cleanName = String(name || '').trim()
  const cleanId = Number(id || 0)
  if (cleanName && cleanId > 0) return `${cleanName} #${cleanId}`
  if (cleanName) return cleanName
  if (cleanId > 0) return `#${cleanId}`
  return '未绑定'
}

function workflowNameText(name: string | undefined, id: number | null | undefined, fallback = '未绑定流程'): string {
  const cleanName = String(name || '').trim()
  const cleanId = Number(id || 0)
  if (cleanName) return cleanName
  if (cleanId > 0) return t('流程名称未加载')
  return t(fallback)
}

function bundleForEpisodeWorkflow(workflowId: number | null | undefined): WorkflowBundle | null {
  const cleanId = Number(workflowId || 0)
  if (cleanId <= 0) return null
  return (props.bundles ?? []).find((bundle) => Number(bundle.episode_workflow_id || 0) === cleanId) ?? null
}

function workflowDisplayText(name: string | undefined, id: number | null | undefined, fallback = '未绑定流程'): string {
  const bundle = bundleForEpisodeWorkflow(id)
  const workflowName = workflowNameText(name, id, fallback)
  if (!bundle) return workflowName
  const bundleName = String(bundle.name || '').trim()
  if (!bundleName || bundleName === workflowName) return workflowName
  return `${bundleName} · ${workflowName}`
}

function episodeProgress(episode: Episode): { done: number; total: number; percent: number; failed: number } {
  const nodes = episode.workflow_state?.nodes ?? []
  const total = nodes.length
  const done = nodes.filter((node) => node.status === 'success').length
  const failed = nodes.filter((node) => node.status === 'failed').length
  return {
    done,
    total,
    failed,
    percent: total > 0 ? Math.round((done / total) * 100) : 0,
  }
}

function stageRows(episode: Episode | null): StageNode[] {
  return episode?.workflow_state?.nodes ?? []
}

function stageNodeLabel(node: StageNode): string {
  const raw = String(node.label || '').trim()
  return raw ? translateMessage(raw) : node.workflow_node_id
}

function stageNodeError(node: StageNode): string {
  const raw = String(node.error_message || '').trim()
  return raw ? translateMessage(raw) : ''
}

function shotCount(episode: Episode | null, key: 'image_url' | 'video_url') {
  if (key === 'image_url') {
    return (episode?.shots ?? []).filter((shot) => !!shotImageUrl(shot)).length
  }
  return (episode?.shots ?? []).filter((shot) => !!shot[key]).length
}

function stageStatusText(status: StageNode['status']) {
  const map: Record<StageNode['status'], string> = {
    queued: '待处理',
    running: '生成中',
    success: '完成',
    failed: '失败',
    skipped: '跳过',
    stale: '需重生成',
  }
  return t(map[status] ?? '待处理')
}

function stageTone(status: StageNode['status']): 'done' | 'busy' | 'fail' | 'idle' | 'warning' {
  if (status === 'success') return 'done'
  if (status === 'running') return 'busy'
  if (status === 'failed') return 'fail'
  if (status === 'stale') return 'warning'
  return 'idle'
}

function isEpisodeSubmitting(episodeId: number | undefined) {
  return !!episodeId && (props.runningEpisodeIds ?? []).includes(episodeId)
}

function stageKey(episodeId: number | undefined, nodeId: string | undefined) {
  return episodeId && nodeId ? `${episodeId}:${nodeId}` : ''
}

function isStageSubmitting(episodeId: number | undefined, nodeId: string | undefined) {
  const key = stageKey(episodeId, nodeId)
  return !!key && (props.runningStageKeys ?? []).includes(key)
}

function isVideoShotSubmittingForNode(episodeId: number | undefined, nodeId: string | undefined) {
  if (!episodeId || !nodeId) return false
  const prefix = `${episodeId}:${nodeId}:shot:`
  return (props.runningStageKeys ?? []).some((key) => key.startsWith(prefix))
}

function isNodeGenerating(node: StageNode, episode: Episode | null): boolean {
  return node.status === 'running'
    || isStageSubmitting(episode?.id, node.workflow_node_id)
    || (
      episode?.workflow_state?.status === 'queued'
      && episode.workflow_state.current_node_label === node.label
    )
}

function isStageCancellable(node: StageNode, episode: Episode | null): boolean {
  // 视频排队中的镜头用镜头级「取消排队」，节点「取消」只留给整节点仍在 running 的场景，避免歧义。
  if (node.kind === 'video') {
    return node.status === 'running' || isStageSubmitting(episode?.id, node.workflow_node_id)
  }
  return node.status === 'running' || isStageSubmitting(episode?.id, node.workflow_node_id)
}

function isSingleVideoShotGenerating(node: StageNode, episode: Episode | null): boolean {
  if (!episode || node.kind !== 'video') return false
  if (isVideoShotSubmittingForNode(episode.id, node.workflow_node_id)) return true

  const output = (node.output_json ?? {}) as Record<string, any>
  if (output.single_shot === true || Number(output.only_shot_index ?? 0) > 0) return true

  const rows = videoShotRows(node, episode)
  const busyCount = rows.filter((shot) => isVideoShotBusy(shot)).length
  return rows.length > 1 && busyCount === 1
}

function isUpstreamStoryboardGeneratingForNode(node: StageNode, episode: Episode | null): boolean {
  if (!episode || node.kind !== 'video') return false

  const nodes = stageRows(episode)
  const nodeIndex = nodes.findIndex((item) => item.workflow_node_id === node.workflow_node_id)
  const upstreamNodes = nodeIndex >= 0 ? nodes.slice(0, nodeIndex) : nodes

  return upstreamNodes.some((item) => isStoryboardProcessNode(item) && isNodeGenerating(item, episode))
}

function shouldHideNodeOutputForGenerating(node: StageNode, episode: Episode | null): boolean {
  if (isUpstreamStoryboardGeneratingForNode(node, episode)) return true
  if (node.kind === 'video' && videoShotRows(node, episode).length > 0) return false
  if (isSingleVideoShotGenerating(node, episode)) return false
  return isNodeGenerating(node, episode)
}

function nodeGeneratingTitle(node: StageNode, episode: Episode | null): string {
  if (!isNodeGenerating(node, episode) && isUpstreamStoryboardGeneratingForNode(node, episode)) {
    return t('上游分镜正在生成')
  }
  return t('{name}正在生成', { name: stageNodeLabel(node) || nodeKindLabel(node.kind) })
}

function videoNodeId(episode: Episode | null): string {
  const node = (episode?.workflow_state?.nodes ?? []).find((item) => item.kind === 'video')
  return node?.workflow_node_id ?? ''
}

function shotIndexOf(shot: any): number {
  const value = Number(shot?.index ?? shot?.shot_index ?? shot?.id ?? 0)
  return Number.isFinite(value) && value > 0 ? value : 0
}

function shotImageUrl(shot: any): string {
  const direct = typeof shot?.image_url === 'string' ? shot.image_url.trim() : ''
  if (direct) return direct

  const versions = Array.isArray(shot?.media_versions) ? shot.media_versions : []
  const selected = versions.find((version: any) => version?.media_type === 'image' && version?.is_selected && version?.url)
  const first = selected ?? versions.find((version: any) => version?.media_type === 'image' && version?.url)
  return typeof first?.url === 'string' ? first.url.trim() : ''
}

function isVideoShotSubmitting(episodeId: number | undefined, nodeId: string, shotIndex: number) {
  return !!episodeId && !!nodeId && shotIndex > 0
    && (props.runningStageKeys ?? []).includes(`${episodeId}:${nodeId}:shot:${shotIndex}`)
}

/** 节点 running 或任意镜头提交中时，禁用其他镜头的生成，避免 409。 */
function isVideoNodeShotActionLocked(node: StageNode, episode: Episode | null, shot?: Record<string, any>): boolean {
  if (!episode) return false
  if (node.status === 'running') return true
  if (isVideoShotSubmittingForNode(episode.id, node.workflow_node_id)) return true
  if (shot && isVideoShotBusy(shot)) return true
  const rows = videoShotRows(node, episode)
  return rows.some((row) => isVideoShotBusy(row) && (!shot || shotKey(row) !== shotKey(shot)))
}

function stageActionLabel(node: StageNode, episodeId: number | undefined) {
  if (node.kind === 'video' && activeEpisode.value && videoShotRows(node, activeEpisode.value).length > 0) {
    return t('整节点重跑')
  }
  if (isNodeGenerating(node, activeEpisode.value)) return t('提交中')
  return node.status === 'success' || node.status === 'stale' ? t('重新执行') : t('执行节点')
}

function nodeKindTone(kind: string | undefined): NodeKindTone {
  const value = String(kind || '').toLowerCase()
  if (['input', 'text', 'image', 'video', 'output', 'voice'].includes(value)) return value as NodeKindTone
  return 'other'
}

function nodeKindLabel(kind: string | undefined) {
  const map: Record<NodeKindTone, string> = {
    input: '输入',
    text: '文本',
    image: '图片',
    video: '视频',
    output: '输出',
    voice: '音频',
    other: '节点',
  }
  return t(map[nodeKindTone(kind)])
}

function stageSummary(node: StageNode, episode: Episode | null) {
  if (node.error_message) return t('生成失败，展开查看错误详情。')
  if (shouldHideNodeOutputForGenerating(node, episode)) return t('{name}，完成后会刷新最新产物。', { name: nodeGeneratingTitle(node, episode) })
  if (node.kind === 'output') {
    const output = (node.output_json ?? {}) as Record<string, any>
    if (outputVideoUrl(node)) return t('最终成片已生成')
    const count = Number(output.video_count || 0)
    return count > 0 ? t('已合成 {count} 个视频产物', { count }) : t('等待输出最终成片')
  }
  if (isAssetExtractionNode(node)) {
    return assetOutputSummary(node)
  }

  const raw = node.raw_output?.trim()
  if (raw) return raw.slice(0, 180)

  const output = (node.output_json ?? {}) as Record<string, any>
  for (const key of ['text', 'content', 'result', 'script', 'plot', 'summary', 'note']) {
    const value = output[key]
    if (typeof value === 'string' && value.trim()) return value.trim().slice(0, 180)
  }

  if (node.kind === 'image') {
    const count = shotCount(episode, 'image_url')
    return count > 0 ? t('已生成 {count} 张分镜画面', { count }) : t('等待生成分镜画面')
  }
  if (node.kind === 'video') {
    const count = nodeVideoItems(node, episode).length
    return count > 0 ? t('已生成 {count} 段视频片段', { count }) : t('等待生成视频片段')
  }
  return node.status === 'success' ? t('节点已完成，暂无可预览文本') : t('暂无产物')
}

function prettyOutput(value: unknown) {
  if (!value) return ''
  if (typeof value === 'string') return value
  return JSON.stringify(value, null, 2)
}

function isAssetExtractionNode(node: StageNode): boolean {
  return String(node.label || '').includes('资产提取')
}

function parseJsonLikeObject(text: string): Record<string, any> | null {
  const raw = text.trim()
  if (!raw) return null
  try {
    const parsed = JSON.parse(raw)
    return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed as Record<string, any> : null
  } catch {
    const start = raw.indexOf('{')
    const end = raw.lastIndexOf('}')
    if (start >= 0 && end > start) {
      try {
        const parsed = JSON.parse(raw.slice(start, end + 1))
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed as Record<string, any> : null
      } catch {
        return null
      }
    }
    return null
  }
}

function assetOutputAssets(node: StageNode): Array<Record<string, any>> {
  const fromOutput = (node.output_json as Record<string, any> | undefined)?.assets
  if (Array.isArray(fromOutput) && fromOutput.length > 0) {
    return fromOutput.filter((item) => item && typeof item === 'object')
  }
  const fromRaw = parseJsonLikeObject(node.raw_output || '')?.assets
  return Array.isArray(fromRaw) ? fromRaw.filter((item) => item && typeof item === 'object') : []
}

function assetOutputLooks(node: StageNode): Array<Record<string, any>> {
  const fromOutput = (node.output_json as Record<string, any> | undefined)?.character_looks
  if (Array.isArray(fromOutput) && fromOutput.length > 0) {
    return fromOutput.filter((item) => item && typeof item === 'object')
  }
  const fromRaw = parseJsonLikeObject(node.raw_output || '')?.character_looks
  return Array.isArray(fromRaw) ? fromRaw.filter((item) => item && typeof item === 'object') : []
}

function assetOutputStats(node: StageNode) {
  const output = (node.output_json ?? {}) as Record<string, any>
  const assets = assetOutputAssets(node)
  const looks = assetOutputLooks(node)
  let characters = 0
  let scenes = 0
  let props = 0
  let other = 0
  for (const asset of assets) {
    const type = String(asset.type || '').toLowerCase()
    if (type === 'character') characters += 1
    else if (type === 'scene') scenes += 1
    else if (type === 'prop') props += 1
    else other += 1
  }
  const lookCount = Math.max(
    looks.length,
    Number(output.character_looks_extracted ?? 0),
    Number(output.character_looks_created ?? 0) + Number(output.character_looks_updated ?? 0),
    Number(output.character_looks_added ?? output.costume_props_added ?? 0),
  )
  return {
    extracted: assets.length,
    characters,
    scenes,
    props,
    other,
    looks: lookCount,
    created: Number(output.assets_created ?? 0),
    updated: Number(output.assets_updated ?? 0),
    looksCreated: Number(output.character_looks_created ?? 0),
    looksUpdated: Number(output.character_looks_updated ?? 0),
    charactersFromLooks: Number(output.characters_synthesized_from_looks ?? 0),
    queued: Number(output.image_jobs_created ?? 0),
    lookQueued: Number(output.look_image_jobs_created ?? 0),
  }
}

function assetOutputSummary(node: StageNode): string {
  const stats = assetOutputStats(node)
  if (stats.extracted <= 0 && stats.looks <= 0) return t('暂时没有读取到本集资产。')

  const parts: string[] = []
  if (stats.characters > 0) parts.push(t('人物 {count} 个', { count: stats.characters }))
  if (stats.scenes > 0) parts.push(t('场景 {count} 个', { count: stats.scenes }))
  if (stats.props > 0) parts.push(t('道具 {count} 个', { count: stats.props }))
  if (stats.other > 0) parts.push(t('其他资产 {count} 个', { count: stats.other }))
  if (stats.looks > 0) parts.push(t('人物造型 {count} 套', { count: stats.looks }))
  if (parts.length === 0 && stats.extracted > 0) {
    parts.push(t('资产 {count} 个', { count: stats.extracted }))
  }

  const writeParts: string[] = []
  if (stats.created > 0) writeParts.push(t('新增 {count}', { count: stats.created }))
  if (stats.updated > 0) writeParts.push(t('更新 {count}', { count: stats.updated }))
  if (stats.charactersFromLooks > 0) writeParts.push(t('由造型补人物 {count}', { count: stats.charactersFromLooks }))
  if (stats.queued > 0) writeParts.push(t('核心图排队 {count} 张', { count: stats.queued }))
  if (stats.lookQueued > 0) writeParts.push(t('造型图排队 {count} 张', { count: stats.lookQueued }))

  const main = parts.join(' · ')
  return writeParts.length > 0 ? `${main}（${writeParts.join(' · ')}）` : main
}

function assetOutputStatCards(node: StageNode): Array<{ key: string; label: string; count: number; unit: string }> {
  const stats = assetOutputStats(node)
  const cards = [
    { key: 'character', label: t('人物'), count: stats.characters, unit: t('个') },
    { key: 'scene', label: t('场景'), count: stats.scenes, unit: t('个') },
    { key: 'prop', label: t('道具'), count: stats.props, unit: t('个') },
    { key: 'look', label: t('人物造型'), count: stats.looks, unit: t('套') },
  ]
  if (stats.other > 0) {
    cards.push({ key: 'other', label: t('其他'), count: stats.other, unit: t('个') })
  }
  if (stats.queued > 0) {
    cards.push({ key: 'core_image', label: t('核心图排队'), count: stats.queued, unit: t('张') })
  }
  if (stats.lookQueued > 0) {
    cards.push({ key: 'look_image', label: t('造型图排队'), count: stats.lookQueued, unit: t('张') })
  }
  return cards.filter((item) => item.count > 0)
}

function assetTypeLabel(type: string): string {
  if (type === 'character') return t('人物')
  if (type === 'scene') return t('场景')
  if (type === 'prop') return t('道具')
  if (type === 'look') return t('人物造型')
  return t('资产')
}

function isStoryboardTextNode(node: StageNode): boolean {
  return node.kind === 'text' && String(node.label || '').includes('分镜')
}

function isStoryboardProcessNode(node: StageNode): boolean {
  return node.kind === 'text' && String(node.label || '').includes('分镜处理')
}

function storyboardText(node: StageNode): string {
  return node.raw_output || prettyOutput(node.output_json)
}

function makeLookReferenceName(characterName: string, variantName: string): string {
  const name = characterName.trim()
  const look = variantName.trim()
  if (!name) return look
  if (!look) return name
  return `${name}·${look}`
}

function normalizeStoryboardShots(value: unknown): Array<{ index: number; title: string; text: string }> {
  if (!value) return []

  if (typeof value === 'string') {
    return parseStoryboardText(value)
  }

  if (!Array.isArray(value) && typeof value === 'object') {
    const record = value as Record<string, unknown>
    for (const key of ['shots', 'shot_list', 'storyboard', 'frames', 'scenes', 'segments']) {
      const nested = normalizeStoryboardShots(record[key])
      if (nested.length) return nested
    }

    for (const key of ['text', 'content', 'result', 'script', 'raw', 'output']) {
      const nested = normalizeStoryboardShots(record[key])
      if (nested.length) return nested
    }

    return []
  }

  if (!Array.isArray(value)) return []

  return value
    .map((item, fallbackIndex) => {
      if (typeof item === 'string') {
        const text = item.trim()
        return text
          ? { index: fallbackIndex + 1, title: `镜头 ${fallbackIndex + 1}`, text, contentText: text, contentRichJson: richNodesFromText(text), assetRefs: assetRefsFromRichNodes(richNodesFromText(text)) }
          : null
      }
      if (!item || typeof item !== 'object') return null
      const record = item as Record<string, unknown>
      const index = Number(record.index ?? record.number ?? record.shot_index ?? fallbackIndex + 1)
      const title = String(record.title ?? record.name ?? record.scene ?? `镜头 ${index}`).trim()
      const text = String(record.content_text ?? record.description ?? record.desc ?? record.visual ?? record.prompt ?? record.content ?? record.text ?? '').trim()
      const rich = Array.isArray(record.content_rich_json) ? record.content_rich_json as StoryboardRichNode[] : richNodesFromText(text)
      const refs = Array.isArray(record.asset_refs) ? record.asset_refs as StoryboardAssetRefNode[] : assetRefsFromRichNodes(rich)
      const shotKey = String(record.shot_key ?? record.shotKey ?? '').trim()
      return Number.isFinite(index) && index > 0 && text
        ? { index, title, text, contentText: text, contentRichJson: rich, assetRefs: refs, shotKey: shotKey || undefined }
        : null
    })
    .filter((item): item is StoryboardShot => !!item)
}

function parseStoryboardText(text: string): StoryboardShot[] {
  const source = text.trim()
  if (!source) return []

  const videoNodePattern = /(?:^|\n)\s*【\s*(?:视频节点|Video\s+Node)\s*(\d{1,3})\s*｜\s*(?:(?:\d+(?:\.\d+)?\s*(?:s|秒))\s*｜\s*)?([^】]+)\s*】\s*([\s\S]*?)(?=\n\s*(?:---\s*)?\n?\s*【\s*(?:视频节点|Video\s+Node)\s*\d{1,3}\s*｜|\s*$)/giu
  const shots: Array<{ index: number; title: string; text: string }> = []
  let match: RegExpExecArray | null
  while ((match = videoNodePattern.exec(source)) !== null) {
    const index = Number(match[1])
    const title = String(match[2] || '').trim()
    const body = String(match[3] || '').trim()
    if (Number.isFinite(index) && index > 0 && (title || body)) {
      const rich = richNodesFromText(body || title)
      shots.push({ index, title: title || `镜头 ${index}`, text: body || title, contentText: body || title, contentRichJson: rich, assetRefs: assetRefsFromRichNodes(rich) })
    }
  }
  if (shots.length) return shots

  const numberedPattern = /(?:^|\n)\s*(?:镜头|分镜|shot)?\s*(\d{1,3})\s*[.、:：)）-]\s*([\s\S]*?)(?=\n\s*(?:镜头|分镜|shot)?\s*\d{1,3}\s*[.、:：)）-]|\s*$)/giu
  while ((match = numberedPattern.exec(source)) !== null) {
    const index = Number(match[1])
    const body = String(match[2] || '').trim()
    if (Number.isFinite(index) && index > 0 && body) {
      const firstLine = body.split('\n').find(Boolean)?.trim() || `镜头 ${index}`
      const rich = richNodesFromText(body)
      shots.push({ index, title: firstLine.slice(0, 24), text: body, contentText: body, contentRichJson: rich, assetRefs: assetRefsFromRichNodes(rich) })
    }
  }

  return shots
}

function storyboardShotsForNode(node: StageNode): StoryboardShot[] {
  return storyboardShotsByNodeId.value[node.workflow_node_id] ?? []
}

function storyboardCardKey(node: StageNode, shot: StoryboardShot): string {
  return `${node.workflow_node_id}:${shot.index}`
}

function storyboardDraftKey(key: string, shot: StoryboardShot): StoryboardShotDraft {
  return storyboardShotDrafts.value[key] ?? { title: shot.title || `镜头 ${shot.index}`, text: shot.contentText || shot.text || '', contentRichJson: shot.contentRichJson ?? richNodesFromText(shot.contentText || shot.text || '') }
}

function storyboardShotDraft(node: StageNode, shot: StoryboardShot): StoryboardShotDraft {
  return storyboardDraftKey(storyboardCardKey(node, shot), shot)
}

function isStoryboardShotEditing(node: StageNode, shot: StoryboardShot): boolean {
  return !!storyboardEditingKeys.value[storyboardCardKey(node, shot)]
}

function isStoryboardShotSaving(node: StageNode, shot: StoryboardShot): boolean {
  return !!storyboardSavePendingKeys.value[storyboardCardKey(node, shot)]
}

function isStoryboardShotRegenerating(node: StageNode, shot: StoryboardShot): boolean {
  return !!storyboardRegeneratePendingKeys.value[storyboardCardKey(node, shot)]
}

function isStoryboardShotActionLocked(node: StageNode, shot: StoryboardShot): boolean {
  return props.busy === true
    || props.locked === true
    || activeEpisodeAutoRunning.value
    || node.status === 'running'
    || isStageSubmitting(activeEpisode.value?.id ?? 0, node.workflow_node_id)
    || isStoryboardShotSaving(node, shot)
    || isStoryboardShotRegenerating(node, shot)
}

function beginStoryboardShotEdit(node: StageNode, shot: StoryboardShot) {
  const key = storyboardCardKey(node, shot)
  storyboardEditingKeys.value = { ...storyboardEditingKeys.value, [key]: true }
  storyboardShotDrafts.value = {
    ...storyboardShotDrafts.value,
    [key]: { title: shot.title || `镜头 ${shot.index}`, text: shot.contentText || shot.text || '', contentRichJson: shot.contentRichJson ?? richNodesFromText(shot.contentText || shot.text || '') },
  }
}

function clearStoryboardShotEdit(key: string) {
  const editing = { ...storyboardEditingKeys.value }
  const drafts = { ...storyboardShotDrafts.value }
  const mentions = { ...storyboardMentionStates.value }
  delete editing[key]
  delete drafts[key]
  delete mentions[key]
  storyboardEditingKeys.value = editing
  storyboardShotDrafts.value = drafts
  storyboardMentionStates.value = mentions
}

function cancelStoryboardShotEdit(node: StageNode, shot: StoryboardShot) {
  clearStoryboardShotEdit(storyboardCardKey(node, shot))
}

function setStoryboardShotDraft(node: StageNode, shot: StoryboardShot, patch: Partial<StoryboardShotDraft>) {
  const key = storyboardCardKey(node, shot)
  const current = storyboardDraftKey(key, shot)
  const next = { ...current, ...patch }
  if (patch.text !== undefined && patch.contentRichJson === undefined) {
    next.contentRichJson = richNodesFromText(next.text)
  }
  storyboardShotDrafts.value = {
    ...storyboardShotDrafts.value,
    [key]: next,
  }
}

function cleanStoryboardTitle(value: string, index: number): string {
  return value
    .replace(/^【\s*(?:视频节点|Video\s+Node)\s*\d{1,3}\s*｜?/iu, '')
    .replace(/】$/u, '')
    .trim()
    || `镜头 ${index}`
}

function formatStoryboardShot(shot: StoryboardShot): string {
  const number = String(shot.index).padStart(2, '0')
  const title = cleanStoryboardTitle(shot.title, shot.index)
  const text = String(shot.contentText || shot.text || '').trim()
  return `【视频节点${number}｜${title}】\n${text}`
}

function formatStoryboardShots(shots: StoryboardShot[]): string {
  return [...shots]
    .sort((a, b) => a.index - b.index)
    .map(formatStoryboardShot)
    .join('\n\n')
}

function saveStoryboardShot(node: StageNode, shot: StoryboardShot) {
  const episode = activeEpisode.value
  if (!episode?.id) return
  const key = storyboardCardKey(node, shot)
  const draft = storyboardDraftKey(key, shot)
  const title = cleanStoryboardTitle(draft.title, shot.index)
  const text = draft.text.trim()
  if (!text) return

  const nextShots = storyboardShotsForNode(node).map((item) =>
    item.index === shot.index ? {
      ...item,
      title,
      text,
      contentText: text,
      contentRichJson: draft.contentRichJson ?? richNodesFromText(text),
      assetRefs: assetRefsFromRichNodes(draft.contentRichJson ?? richNodesFromText(text)),
    } : item,
  )
  const content = formatStoryboardShots(nextShots)
  const storyboardShots = nextShots.map((item) => ({
    index: item.index,
    title: cleanStoryboardTitle(item.title, item.index),
    content_text: item.contentText || item.text,
    content_rich_json: item.contentRichJson ?? richNodesFromText(item.contentText || item.text),
    ...(item.shotKey ? { shot_key: item.shotKey } : {}),
  }))
  storyboardSavePendingKeys.value = { ...storyboardSavePendingKeys.value, [key]: true }
  emit('update-node-content', episode.id, node.workflow_node_id, content, storyboardShots, {
    updateMode: 'single_shot',
    changedShotIndex: shot.index,
  }, (ok: boolean) => {
    const nextPending = { ...storyboardSavePendingKeys.value }
    delete nextPending[key]
    storyboardSavePendingKeys.value = nextPending
    if (ok) clearStoryboardShotEdit(key)
  })
}

function regenerateStoryboardShot(node: StageNode, shot: StoryboardShot) {
  const episode = activeEpisode.value
  if (!episode?.id || isStoryboardShotActionLocked(node, shot) || hasStoryboardRegeneratePending.value) return
  const key = storyboardCardKey(node, shot)
  storyboardRegeneratePendingKeys.value = { ...storyboardRegeneratePendingKeys.value, [key]: true }
  emit('regenerate-storyboard-shot', episode.id, node.workflow_node_id, shot.index, () => {
    const nextPending = { ...storyboardRegeneratePendingKeys.value }
    delete nextPending[key]
    storyboardRegeneratePendingKeys.value = nextPending
  })
}

function normalizedReferenceText(value: string): string {
  return value.replace(/^@/u, '').trim().toLowerCase()
}

function explicitReferenceLabels(text: string): string[] {
  return findReferenceRanges(text).map((range) => range.option.label)
}

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/** 资产名形如「精卫（少女形态）」时，去掉括号后缀得到基础名「精卫」作为别名 */
function baseNameAlias(name: string): string {
  return name.replace(/[（(][^）)]*[）)]\s*$/, '').trim()
}

// 文本词 → 资产引用项：覆盖资产名、括号别名、人物造型标签，以及它们的 @ 前缀形式，
// 让编辑器与展示态（AssetLinkedText）一致地包裹所有引用（含正文中未带 @ 的资产名）
const referenceTermMap = computed<Map<string, AssetReferenceOption>>(() => {
  const map = new Map<string, AssetReferenceOption>()
  const add = (term: string, option: AssetReferenceOption) => {
    const key = term.trim()
    if (key && !map.has(key)) map.set(key, option)
  }
  for (const option of assetReferenceOptions.value) {
    add(option.label, option)
    add(`@${option.label}`, option)
    if (option.type !== 'look') {
      const alias = baseNameAlias(option.label)
      if (alias && alias !== option.label) {
        add(alias, option)
        add(`@${alias}`, option)
      }
    }
  }
  return map
})

// 在文本中找出所有引用区间（最长优先、互不重叠、按出现顺序），与展示态匹配逻辑一致
function findReferenceRanges(text: string): Array<{ start: number; end: number; option: AssetReferenceOption }> {
  const source = text || ''
  const map = referenceTermMap.value
  if (!source || !map.size) return []
  // 长词优先，避免「武松」抢先吃掉「武松·第1集常服」
  const terms = Array.from(map.keys()).sort((a, b) => b.length - a.length)
  const pattern = new RegExp(terms.map(escapeRegExp).join('|'), 'g')
  const ranges: Array<{ start: number; end: number; option: AssetReferenceOption }> = []
  for (const match of source.matchAll(pattern)) {
    const option = map.get(match[0])
    if (!option) continue
    const start = match.index ?? 0
    ranges.push({ start, end: start + match[0].length, option })
  }
  return ranges
}

function optionMatchesLabel(option: AssetReferenceOption, label: string): boolean {
  return normalizedReferenceText(option.label) === normalizedReferenceText(label)
}

function assetReferenceOptionForLabel(label: string): AssetReferenceOption | undefined {
  return assetReferenceOptionsByNormalizedLabel.value.get(normalizedReferenceText(label))
}

function assetReferenceOptionForRef(ref: StoryboardAssetRefNode): AssetReferenceOption | undefined {
  return assetReferenceOptionsByRefKey.value.get(`${ref.asset_id}:${ref.asset_image_id ?? 0}:${ref.asset_image_version_id ?? 0}:${ref.reference_role}`)
}

function richNodesFromText(text: string): StoryboardRichNode[] {
  const source = text || ''
  if (!source) return []
  const ranges = findReferenceRanges(source)
  if (!ranges.length) return [{ type: 'text', text: source }]

  const nodes: StoryboardRichNode[] = []
  let cursor = 0
  for (const range of ranges) {
    if (range.start < cursor) continue
    const option = range.option
    if (range.start > cursor) {
      nodes.push({ type: 'text', text: source.slice(cursor, range.start) })
    }
    nodes.push({
      type: 'asset_ref',
      asset_id: option.asset.id,
      asset_image_id: option.reference?.assetImageId ?? null,
      asset_image_version_id: option.reference?.assetImageVersionId ?? null,
      reference_role: option.reference?.role ?? 'view',
      label: option.label,
      asset_type: option.type,
    })
    cursor = range.end
  }
  if (cursor < source.length) {
    nodes.push({ type: 'text', text: source.slice(cursor) })
  }
  return preferLookOverBaseRichNodes(mergeAdjacentRichTextNodes(nodes))
}

// 同一镜头里引用了人物 look（妆造）时，把该人物的「基础(view)」引用归并到 look，
// 这样基础人物图不再单独占用一个图片位，正文里裸名「武松」也按该 look 处理
function preferLookOverBaseRichNodes(nodes: StoryboardRichNode[]): StoryboardRichNode[] {
  const lookByAsset = new Map<number, StoryboardAssetRefNode>()
  for (const node of nodes) {
    if (node.type === 'asset_ref' && node.reference_role === 'look' && !lookByAsset.has(node.asset_id)) {
      lookByAsset.set(node.asset_id, node)
    }
  }
  if (!lookByAsset.size) return nodes
  return nodes.map((node) => {
    if (node.type !== 'asset_ref' || node.reference_role === 'look') return node
    const look = lookByAsset.get(node.asset_id)
    if (!look) return node
    return { ...node, asset_image_id: look.asset_image_id, asset_image_version_id: look.asset_image_version_id ?? null, reference_role: look.reference_role }
  })
}

function assetRefsFromRichNodes(nodes: StoryboardRichNode[]): StoryboardAssetRefNode[] {
  const seen = new Set<string>()
  const refs: StoryboardAssetRefNode[] = []
  for (const node of nodes) {
    if (node.type !== 'asset_ref') continue
    const key = `${node.asset_id}:${node.asset_image_id || 0}:${node.asset_image_version_id || 0}:${node.reference_role}`
    if (seen.has(key)) continue
    seen.add(key)
    refs.push(node)
  }
  return refs
}

function mergeAdjacentRichTextNodes(nodes: StoryboardRichNode[]): StoryboardRichNode[] {
  const merged: StoryboardRichNode[] = []
  nodes.forEach((node) => {
    if (node.type === 'text') {
      const last = merged[merged.length - 1]
      if (last?.type === 'text') {
        last.text += node.text
      } else if (node.text) {
        merged.push({ ...node })
      }
      return
    }
    merged.push({ ...node })
  })
  return merged
}

function storyboardHighlightSegments(text: string): StoryboardHighlightSegment[] {
  const source = text || ''
  if (!source) return [{ type: 'text', content: '' }]
  const segments: StoryboardHighlightSegment[] = []
  let cursor = 0

  for (const range of findReferenceRanges(source)) {
    if (range.start < cursor) continue
    if (range.start > cursor) {
      segments.push({ type: 'text', content: source.slice(cursor, range.start) })
    }
    segments.push({ type: 'reference', content: source.slice(range.start, range.end), option: range.option })
    cursor = range.end
  }
  if (cursor < source.length) {
    segments.push({ type: 'text', content: source.slice(cursor) })
  }
  return segments.length ? segments : [{ type: 'text', content: source }]
}

function matchedStoryboardReferences(text: string): AssetReferenceOption[] {
  const source = text || ''
  const seen = new Set<string>()
  const matches: AssetReferenceOption[] = []

  const explicitLabels = explicitReferenceLabels(source)
  if (explicitLabels.length) {
    for (const label of explicitLabels) {
      const option = assetReferenceOptionForLabel(label)
      if (!option || seen.has(option.key)) continue
      seen.add(option.key)
      matches.push(option)
    }
    return matches
  }

  for (const option of assetReferenceOptionsSorted.value) {
    if (!option.label || seen.has(option.key)) continue
    if (source.includes(option.label) || source.includes(`@${option.label}`)) {
      if (
        option.type === 'character'
        && matches.some((item) => item.type === 'look' && item.asset.id === option.asset.id)
      ) {
        continue
      }
      seen.add(option.key)
      matches.push(option)
    }
  }
  return matches.slice(0, 6)
}

function storyboardReferenceText(node: StageNode, shot: StoryboardShot): string {
  return isStoryboardShotEditing(node, shot)
    ? storyboardShotDraft(node, shot).text
    : (shot.contentText || shot.text)
}

function storyboardReferencesForShot(node: StageNode, shot: StoryboardShot): AssetReferenceOption[] {
  const refs = isStoryboardShotEditing(node, shot)
    ? assetRefsFromRichNodes(storyboardShotDraft(node, shot).contentRichJson ?? richNodesFromText(storyboardShotDraft(node, shot).text))
    : (shot.assetRefs ?? assetRefsFromRichNodes(shot.contentRichJson ?? richNodesFromText(shot.contentText || shot.text)))
  const options: AssetReferenceOption[] = []
  const seen = new Set<string>()
  refs.forEach((ref) => {
    const option = assetReferenceOptionForRef(ref)
    if (!option || seen.has(option.key)) return
    seen.add(option.key)
    options.push(option)
  })
  // look 替代基础：同一人物若已有 look（妆造）引用，则去掉其基础(view)引用，避免多占一个图片位
  const lookAssetIds = new Set(
    options.filter((option) => option.reference?.role === 'look').map((option) => option.asset.id),
  )
  return options.filter(
    (option) => option.reference?.role === 'look' || !lookAssetIds.has(option.asset.id),
  )
}

function mentionStateFromText(text: string, cursor: number): MentionState {
  const safeCursor = Math.max(0, Math.min(cursor, text.length))
  const prefix = text.slice(0, safeCursor)
  const start = prefix.lastIndexOf('@')
  if (start < 0) return { active: false, query: '', start: -1, end: safeCursor }
  const query = prefix.slice(start + 1)
  if (/[\s，。！？、；：,.!?;:()[\]{}【】《》"'“”]/u.test(query)) {
    return { active: false, query: '', start: -1, end: safeCursor }
  }
  return { active: true, query, start, end: safeCursor }
}

function setStoryboardMentionState(key: string, state: MentionState) {
  storyboardMentionStates.value = { ...storyboardMentionStates.value, [key]: state }
}

function refreshStoryboardMentionState(event: Event, node: StageNode, shot: StoryboardShot) {
  const key = storyboardCardKey(node, shot)
  const target = event.target as HTMLTextAreaElement | null
  if (!target) return
  setStoryboardMentionState(key, mentionStateFromText(target.value, target.selectionStart ?? target.value.length))
}

function handleStoryboardShotTextInput(event: Event, node: StageNode, shot: StoryboardShot) {
  const target = event.target as HTMLTextAreaElement | null
  if (!target) return
  setStoryboardShotDraft(node, shot, { text: target.value, contentRichJson: richNodesFromText(target.value) })
  refreshStoryboardMentionState(event, node, shot)
}

function storyboardReferenceDeleteRange(
  text: string,
  selectionStart: number,
  selectionEnd: number,
  key: 'Backspace' | 'Delete',
): { start: number; end: number } | null {
  const ranges = findReferenceRanges(text)
  if (!ranges.length) return null

  if (selectionStart !== selectionEnd) {
    const touched = ranges.filter((range) => range.start < selectionEnd && range.end > selectionStart)
    if (!touched.length) return null
    return {
      start: Math.min(selectionStart, ...touched.map((range) => range.start)),
      end: Math.max(selectionEnd, ...touched.map((range) => range.end)),
    }
  }

  if (key === 'Backspace') {
    return ranges.find((range) => selectionStart > range.start && selectionStart <= range.end) ?? null
  }
  return ranges.find((range) => selectionStart >= range.start && selectionStart < range.end) ?? null
}

function handleStoryboardShotTextKeydown(event: KeyboardEvent, node: StageNode, shot: StoryboardShot) {
  if (event.key !== 'Backspace' && event.key !== 'Delete') return
  if (event.altKey || event.ctrlKey || event.metaKey) return
  const target = event.target as HTMLTextAreaElement | null
  if (!target) return
  const range = storyboardReferenceDeleteRange(
    target.value,
    target.selectionStart ?? 0,
    target.selectionEnd ?? 0,
    event.key,
  )
  if (!range) return

  event.preventDefault()
  const nextText = target.value.slice(0, range.start) + target.value.slice(range.end)
  target.value = nextText
  setStoryboardShotDraft(node, shot, { text: nextText, contentRichJson: richNodesFromText(nextText) })
  const state = mentionStateFromText(nextText, range.start)
  setStoryboardMentionState(storyboardCardKey(node, shot), state)
  window.requestAnimationFrame(() => {
    target.setSelectionRange(range.start, range.start)
    syncStoryboardTextareaHighlight({ target } as unknown as Event)
  })
}

function handleStoryboardShotTitleInput(event: Event, node: StageNode, shot: StoryboardShot) {
  const target = event.target as HTMLInputElement | null
  if (!target) return
  setStoryboardShotDraft(node, shot, { title: target.value })
}

function syncStoryboardTextareaHighlight(event: Event) {
  const textarea = event.target as HTMLTextAreaElement | null
  const wrap = textarea?.closest('.storyboard-card__textarea-wrap') as HTMLElement | null
  const highlight = wrap?.querySelector('.storyboard-card__textarea-highlight') as HTMLElement | null
  if (!textarea || !highlight) return
  highlight.scrollTop = textarea.scrollTop
  highlight.scrollLeft = textarea.scrollLeft
}

function hideStoryboardMentionLater(node: StageNode, shot: StoryboardShot) {
  const key = storyboardCardKey(node, shot)
  window.setTimeout(() => {
    const current = storyboardMentionStates.value[key]
    if (!current?.active) return
    setStoryboardMentionState(key, { ...current, active: false })
  }, 140)
}

function storyboardMentionOptions(node: StageNode, shot: StoryboardShot): AssetReferenceOption[] {
  const key = storyboardCardKey(node, shot)
  const state = storyboardMentionStates.value[key]
  if (!state?.active) return []
  const query = normalizedReferenceText(state.query)
  if (query && assetReferenceOptionsByNormalizedLabel.value.has(normalizedReferenceText(state.query))) {
    return []
  }
  return assetReferenceOptions.value
    .filter((option) => {
      if (!query) return true
      const label = normalizedReferenceText(option.label)
      const subtitle = normalizedReferenceText(option.subtitle)
      return label.includes(query) || subtitle.includes(query)
    })
    .slice(0, 8)
}

function applyStoryboardAssetReference(node: StageNode, shot: StoryboardShot, option: AssetReferenceOption) {
  const key = storyboardCardKey(node, shot)
  const draft = storyboardDraftKey(key, shot)
  const state = storyboardMentionStates.value[key]
  if (!state?.active || state.start < 0) return
  const before = draft.text.slice(0, state.start)
  const after = draft.text.slice(state.end)
  const spacer = after.startsWith(' ') || after.startsWith('\n') || after === '' ? '' : ' '
  const text = `${before}${option.insertText}${spacer}${after}`
  const contentRichJson = richNodesFromText(text)
  storyboardShotDrafts.value = {
    ...storyboardShotDrafts.value,
    [key]: { ...draft, text, contentRichJson },
  }
  setStoryboardMentionState(key, { active: false, query: '', start: -1, end: -1 })
}

function nodeAssetMentions(node: StageNode): Array<{ term: string; asset_id: number; asset_image_id?: number | null; reference_role?: 'view' | 'look' }> {
  const raw = (node.output_json as Record<string, unknown> | undefined)?.asset_mentions
  if (!Array.isArray(raw)) return []
  return raw.filter(
    (item): item is { term: string; asset_id: number; asset_image_id?: number | null; reference_role?: 'view' | 'look' } =>
      !!item && typeof item === 'object'
      && typeof (item as any).term === 'string'
      && typeof (item as any).asset_id === 'number',
  )
}

function outputVideoUrl(node: StageNode | null | undefined) {
  const output = (node?.output_json ?? {}) as Record<string, any>
  const value = output.video_url ?? output.url ?? output.final_video_url
  return typeof value === 'string' && value.trim() ? value.trim() : ''
}

function nodeVideoItems(node: StageNode, episode: Episode | null) {
  const output = (node.output_json ?? {}) as Record<string, any>
  const items: Array<{ id: string; url: string; poster?: string; label: string }> = []

  const finalUrl = node.kind === 'output' ? outputVideoUrl(node) : ''
  if (finalUrl) items.push({ id: `${node.workflow_node_id}-final`, url: finalUrl, label: t('最终成片') })

  const outputShots = Array.isArray(output.shots) ? output.shots : []
  outputShots.forEach((shot: any, index: number) => {
    const url = typeof shot?.video_url === 'string' ? shot.video_url.trim() : ''
    if (url) {
      items.push({
        id: `${node.workflow_node_id}-shot-${shot.id ?? index}`,
        url,
        poster: typeof shot?.image_url === 'string' ? shot.image_url : undefined,
        label: t('视频 {number}', { number: index + 1 }),
      })
    }
  })

  return items
}

function nodeImages(episode: Episode | null) {
  return (episode?.shots ?? []).filter((shot) => shotImageUrl(shot))
}

function videoShotRows(node: StageNode, episode: Episode | null): Array<any> {
  const byIndex = new Map<number, Record<string, any>>()

  const firstText = (...values: any[]) => {
    for (const value of values) {
      if (typeof value === 'string' && value.trim() !== '') return value
    }
    return ''
  }

  const explicitText = (record: Record<string, any>, key: string, fallback: string) => {
    return Object.prototype.hasOwnProperty.call(record, key)
      ? (typeof record[key] === 'string' ? record[key].trim() : '')
      : fallback
  }

  const nodes = stageRows(episode)
  const videoIndex = nodes.findIndex((item) => item.workflow_node_id === node.workflow_node_id)
  const storyboardNodes = (videoIndex >= 0 ? nodes.slice(0, videoIndex) : nodes)
    .filter((item) => isStoryboardTextNode(item) && storyboardShotsForNode(item).length)

  for (const storyboardNode of storyboardNodes) {
    for (const shot of storyboardShotsForNode(storyboardNode)) {
      const existing = byIndex.get(shot.index) ?? {}
      byIndex.set(shot.index, {
        ...existing,
        id: existing.id ?? `storyboard-${shot.index}`,
        index: shot.index,
        title: existing.title ?? shot.title ?? `镜头 ${shot.index}`,
        desc: shot.text || existing.desc || '',
        assetRefs: shot.assetRefs ?? existing.assetRefs ?? [],
        asset_refs: shot.assetRefs ?? existing.asset_refs ?? [],
        image_url: existing.image_url ?? '',
        video_url: existing.video_url ?? '',
      })
    }
  }

  for (const shot of episode?.shots ?? []) {
    const index = shotIndexOf(shot)
    if (index > 0) {
      const existing = byIndex.get(index) ?? {}
      const shotJobId = Number(shot.job_id ?? shot.video_job_id ?? 0)
      byIndex.set(index, {
        ...existing,
        ...shot,
        id: shot.id ?? existing.id ?? `shot-${index}`,
        index,
        job_id: shotJobId > 0 ? shotJobId : (Number(existing.job_id ?? 0) > 0 ? existing.job_id : null),
        title: existing.title ?? `镜头 ${index}`,
        desc: shot.desc ?? existing.desc ?? '',
        shot_status: shot.status ?? existing.shot_status ?? '',
        image_url: shotImageUrl(shot) || existing.image_url || '',
      })
    }
  }

  const output = (node.output_json ?? {}) as Record<string, any>
  const outputShots = Array.isArray(output.shots) ? output.shots : []
  outputShots.forEach((shot: any, fallbackIndex: number) => {
    if (!shot || typeof shot !== 'object') return
    const index = Number(shot.index ?? shot.shot_index ?? fallbackIndex + 1)
    if (!Number.isFinite(index) || index <= 0) return
    const existing = byIndex.get(index) ?? {}
    const outputJobId = Number(shot.job_id ?? shot.video_job_id ?? 0)
    const mediaVersions = Array.isArray(existing.media_versions)
      ? existing.media_versions
      : Array.isArray(shot.media_versions)
        ? shot.media_versions
        : []
    byIndex.set(index, {
      ...existing,
      ...shot,
      id: existing.id ?? shot.shot_id ?? shot.id ?? `output-${index}`,
      job_id: outputJobId > 0 ? outputJobId : (Number(existing.job_id ?? 0) > 0 ? existing.job_id : null),
      index,
      desc: existing.desc ?? shot.description ?? '',
      prompt: firstText(shot.final_prompt, existing.final_prompt, shot.prompt, existing.prompt),
      final_prompt: firstText(shot.final_prompt, existing.final_prompt),
      job_status: shot.status ?? existing.job_status ?? '',
      job_error: shot.error_message ?? existing.job_error ?? '',
      image_url: firstText(existing.image_url, shot.image_url, shot.input_image_url, shot.source_image_url),
      video_url: firstText(existing.video_url, explicitText(shot, 'video_url', '')),
      end_frame_url: firstText(existing.end_frame_url, explicitText(shot, 'end_frame_url', '')),
      media_versions: mediaVersions,
    })
  })

  return [...byIndex.values()]
    .sort((a, b) => shotIndexOf(a) - shotIndexOf(b))
}

function videoShotStatus(shot: Record<string, any>): string {
  return String(shot.job_status || shot.status || shot.shot_status || '').toLowerCase()
}

function isVideoShotBusy(shot: Record<string, any>): boolean {
  return ['queued', 'running', 'blocked', 'generating'].includes(videoShotStatus(shot))
}

/** 排队/等待/生成中且有 job_id 时可取消本镜；已成功镜头不会出现此按钮。 */
function canCancelVideoShot(shot: Record<string, any>): boolean {
  return videoShotJobId(shot) > 0 && isVideoShotBusy(shot)
}

function videoShotJobId(shot: Record<string, any>): number {
  const direct = Number(shot.job_id ?? shot.video_job_id ?? 0)
  if (direct > 0) return direct
  const versions = Array.isArray(shot.media_versions) ? shot.media_versions : []
  for (const version of versions) {
    const id = Number(version?.video_job_id ?? 0)
    if (id > 0) return id
  }
  return 0
}

function videoShotActionLabel(shot: Record<string, any>): string {
  const status = videoShotStatus(shot)
  if (status === 'running' || status === 'generating') return t('生成中')
  if (status === 'queued') return t('排队中')
  if (status === 'blocked') return t('等待上一段')
  if (status === 'stale' || (hasVideoVersions(shot) && !String(shot.video_url || '').trim())) {
    return t('按当前意图生成')
  }
  if (shot.video_url || shot.job_id || status === 'failed') return t('重新生成这一段')
  return t('生成这一段')
}

/** 分镜已变、尚无按新意图成片，但历史版本仍在。 */
function isVideoShotIntentStale(shot: Record<string, any>): boolean {
  const status = videoShotStatus(shot)
  if (status === 'stale') return true
  return hasVideoVersions(shot) && !String(shot.video_url || '').trim()
}

function versionPromptText(version: any): string {
  return String(version?.prompt || '').trim()
}

function versionIsSelectable(version: any): boolean {
  if (!version || version.is_selected) return false
  if (version.orphaned) return false
  if (version.is_selectable === false) return false
  return typeof version.url === 'string' && !!version.url.trim()
}

function videoShotStatusLabel(shot: Record<string, any>): string {
  const status = videoShotStatus(shot)
  if (status === 'running' || status === 'generating') return t('生成中')
  if (status === 'queued') return t('排队中')
  if (status === 'blocked') return t('等待中')
  if (status === 'stale') return t('需重生成')
  if (status === 'failed') return t('失败')
  if (shot.video_url || status === 'success' || status === 'done') return t('已生成')
  return ''
}

// ── 镜头主从选择 / 改词重生成 ──────────────────────────────────────
function shotKey(shot: Record<string, any>): string {
  return String(shot.id ?? `idx-${shotIndexOf(shot)}`)
}

function selectedShotKeyFor(nodeId: string): string {
  return selectedShotKeys.value[nodeId] ?? ''
}

function activeVideoShot(node: StageNode, episode: Episode | null): Record<string, any> | null {
  const rows = videoShotRows(node, episode)
  if (!rows.length) return null
  const key = selectedShotKeyFor(node.workflow_node_id)
  return rows.find((row) => shotKey(row) === key) ?? rows[0]
}

function isShotActive(node: StageNode, shot: Record<string, any>): boolean {
  const key = selectedShotKeyFor(node.workflow_node_id)
  if (key) return key === shotKey(shot)
  // 没显式选中时，默认高亮第一条
  const rows = videoShotRows(node, activeEpisode.value)
  return rows.length > 0 && shotKey(rows[0]) === shotKey(shot)
}

function selectShot(nodeId: string, shot: Record<string, any>) {
  selectedShotKeys.value = { ...selectedShotKeys.value, [nodeId]: shotKey(shot) }
}

function videoPromptCacheKey(episodeId: number | undefined, nodeId: string): string {
  return episodeId && nodeId ? `${episodeId}:${nodeId}` : ''
}

function videoPromptSourceSignature(episode: Episode | null, nodeId: string): string {
  if (!episode || !nodeId) return ''
  const nodes = stageRows(episode)
  const videoIndex = nodes.findIndex((item) => item.workflow_node_id === nodeId)
  const upstreamNodes = (videoIndex >= 0 ? nodes.slice(0, videoIndex) : nodes).map((item) => ({
    id: item.workflow_node_id,
    status: item.status,
    raw: String(item.raw_output || ''),
    output: JSON.stringify(item.output_json ?? {}),
  }))
  const shots = (episode.shots ?? []).map((shot: any) => ({
    index: shotIndexOf(shot),
    desc: String(shot?.desc ?? ''),
    image: shotImageUrl(shot),
    video: String(shot?.video_url ?? ''),
    end: String(shot?.video_end_frame_url ?? shot?.end_frame_url ?? ''),
    versions: Array.isArray(shot?.media_versions) ? shot.media_versions.length : 0,
  }))
  return JSON.stringify({ storyboardRevisionId: episode.current_storyboard_revision_id ?? null, upstreamNodes, shots })
}

async function ensureVideoPromptPreview(episode: Episode | null, nodeId: string) {
  if (!episode?.id || !nodeId) return
  const node = stageRows(episode).find((item) => item.workflow_node_id === nodeId)
  if (!node || node.kind !== 'video' || (node.status !== 'success' && node.status !== 'running')) {
    const keyToDrop = videoPromptCacheKey(episode.id, nodeId)
    if (keyToDrop && videoPromptPreviewCache.value[keyToDrop]) {
      const next = { ...videoPromptPreviewCache.value }
      delete next[keyToDrop]
      videoPromptPreviewCache.value = next
    }
    return
  }
  const key = videoPromptCacheKey(episode.id, nodeId)
  const signature = videoPromptSourceSignature(episode, nodeId)
  if (!key || !signature) return
  const cached = videoPromptPreviewCache.value[key]
  if (cached?.signature === signature) return

  try {
    const preview = await previewEpisodeVideoPrompts(episode.id, nodeId)
    videoPromptPreviewCache.value = {
      ...videoPromptPreviewCache.value,
      [key]: { preview, signature },
    }
  } catch {
    // 上游还没准备好时，静默回退到最近一次生成 prompt
  }
}

function previewPromptForShot(nodeId: string, shot: Record<string, any>): string {
  const episodeId = activeEpisode.value?.id
  const key = videoPromptCacheKey(episodeId, nodeId)
  const preview = key ? videoPromptPreviewCache.value[key]?.preview : null
  const prompt = preview?.shots?.find((item) => item.index === shotIndexOf(shot))?.prompt ?? ''
  return String(prompt).trim()
}

function shotOriginalPrompt(nodeId: string, shot: Record<string, any>): string {
  const finalPrompt = String(shot.final_prompt || '').trim()
  if (finalPrompt) return finalPrompt
  const previewPrompt = previewPromptForShot(nodeId, shot)
  if (previewPrompt) return previewPrompt
  const storedPrompt = String(shot.prompt || '').trim()
  if (storedPrompt && storedPrompt !== DEFAULT_VIDEO_NODE_PROMPT) return storedPrompt
  const description = String(shot.desc || shot.description || '').trim()
  if (description) return description
  return storedPrompt
}

function shotPromptDraft(nodeId: string, shot: Record<string, any>): string {
  const key = shotKey(shot)
  return key in shotPromptDrafts.value ? shotPromptDrafts.value[key] : shotOriginalPrompt(nodeId, shot)
}

function setShotPromptDraft(shot: Record<string, any>, value: string) {
  shotPromptDrafts.value = { ...shotPromptDrafts.value, [shotKey(shot)]: value }
}

function resetShotPromptDraft(shot: Record<string, any>) {
  const next = { ...shotPromptDrafts.value }
  delete next[shotKey(shot)]
  shotPromptDrafts.value = next
}

function shotPromptDirty(nodeId: string, shot: Record<string, any>): boolean {
  return shotPromptDraft(nodeId, shot).trim() !== shotOriginalPrompt(nodeId, shot)
}

function assetHasCoreImage(asset: Asset): boolean {
  return (asset.images ?? []).some((img) => img.view_type === 'main' && String(img.url || '').trim() !== '')
}

function assetHasUsableReferenceImage(asset: Asset): boolean {
  if (assetHasCoreImage(asset)) return true
  return (asset.images ?? []).some((img) => String(img.reference_role || 'view').toLowerCase() === 'look' && String(img.url || '').trim() !== '')
}

function referencedAssetIdsForShot(shot: Record<string, any>): number[] {
  const ids = new Set<number>()
  const pushId = (value: unknown) => {
    const id = Number(value ?? 0)
    if (Number.isFinite(id) && id > 0) ids.add(id)
  }
  for (const asset of (Array.isArray(shot.assets) ? shot.assets : [])) {
    if (!asset || typeof asset !== "object") continue
    pushId((asset as any).id ?? (asset as any).asset_id)
  }
  const refs = Array.isArray(shot.asset_refs)
    ? shot.asset_refs
    : (Array.isArray(shot.assetRefs) ? shot.assetRefs : [])
  for (const ref of refs) {
    if (!ref || typeof ref !== "object") continue
    pushId((ref as any).asset_id ?? (ref as any).id)
  }
  if (ids.size === 0) {
    const text = `${shot.desc || ""} ${shot.prompt || ""} ${shot.final_prompt || ""}`
    const seriesId = props.series?.id ?? 0
    for (const asset of (props.assets ?? [])) {
      if (seriesId > 0 && asset.series_id !== seriesId) continue
      const name = String(asset.name || "").trim()
      if (name && text.includes(name)) ids.add(asset.id)
    }
  }
  return [...ids]
}

function assetsMissingCoreViewsForShot(shot: Record<string, any>): Asset[] {
  const seriesId = props.series?.id ?? 0
  const referencedIds = referencedAssetIdsForShot(shot)
  if (referencedIds.length === 0) return []
  const idSet = new Set(referencedIds)
  return (props.assets ?? []).filter((asset) => {
    if (!idSet.has(asset.id)) return false
    if (seriesId > 0 && asset.series_id !== seriesId) return false
    return !assetHasUsableReferenceImage(asset)
  })
}

function guardCoreViewsReady(shot?: Record<string, any>): boolean {
  const missing = shot ? assetsMissingCoreViewsForShot(shot) : []
  if (missing.length === 0) return true
  const names = missing
    .slice(0, 8)
    .map((asset) => asset.name || `#${asset.id}`)
    .join("、")
  const more = missing.length > 8 ? t(" 等 {count} 个", { count: missing.length }) : ""
  ElMessage.warning(
    t("当前镜头引用的资产尚未就绪，无法生成视频。缺少：{names}{more}", {
      names,
      more,
    }),
  )
  return false
}

function rerunVideoShot(episodeId: number, nodeId: string, shot: Record<string, any>) {
  if (!guardCoreViewsReady(shot)) return
  const jobId = Number(shot.job_id ?? 0)
  const key = shotKey(shot)
  const hasManualDraft = key in shotPromptDrafts.value
  let prompt = shotPromptDraft(nodeId, shot).trim()
  const storedPrompt = String(shot.prompt || '').trim()
  const hasCompiledPrompt = !!String(shot.final_prompt || '').trim() || !!previewPromptForShot(nodeId, shot)
  if (!hasManualDraft && !hasCompiledPrompt && storedPrompt === DEFAULT_VIDEO_NODE_PROMPT) {
    prompt = storedPrompt
  }
  if (jobId <= 0 || !prompt) return
  emit('rerun-video-shot', episodeId, nodeId, jobId, prompt, shotIndexOf(shot))
}

function submitVideoShot(episodeId: number, nodeId: string, shot: Record<string, any>) {
  const node = stageRows(activeEpisode.value).find((item) => item.workflow_node_id === nodeId)
  if (node && isVideoNodeShotActionLocked(node, activeEpisode.value, shot) && !isVideoShotBusy(shot)) {
    ElMessage.warning(t('当前视频节点正在生成中，请等待完成后再操作其他镜头'))
    return
  }
  if (!guardCoreViewsReady(shot)) return
  const jobId = Number(shot.job_id ?? 0)
  if (jobId > 0) {
    rerunVideoShot(episodeId, nodeId, shot)
    return
  }
  emit('run-video-shot', episodeId, nodeId, shotIndexOf(shot))
}

function isExpanded(nodeId: string) {
  return !!expandedNodeIds.value[nodeId]
}

function toggleNode(nodeId: string) {
  expandedNodeIds.value = { ...expandedNodeIds.value, [nodeId]: !expandedNodeIds.value[nodeId] }
}

function isVideoActivated(url: string) {
  return !!activatedVideoUrls.value[url]
}

watch(
  () => {
    const episode = activeEpisode.value
    if (!episode?.id) return []
    return stageRows(episode)
      .filter((node) => node.kind === 'video' && isExpanded(node.workflow_node_id))
      .map((node) => ({
        nodeId: node.workflow_node_id,
        signature: videoPromptSourceSignature(episode, node.workflow_node_id),
      }))
  },
  (entries) => {
    const episode = activeEpisode.value
    if (!episode) return
    entries.forEach((entry) => {
      if (entry.signature) void ensureVideoPromptPreview(episode, entry.nodeId)
    })
  },
  { immediate: true, deep: true },
)

// ── 镜头媒体版本（抽卡历史 / 回滚） ────────────────────────────────
const versionDialogVisible = ref(false)
const versionDialogShot = ref<Record<string, any> | null>(null)

const VERSION_SOURCE_LABELS: Record<string, string> = {
  generated: '生成',
  rerun: '重新生成',
  archive: '历史版本',
  manual: '手动上传',
}

function mediaVersionsOf(shot: Record<string, any> | null, type: 'image' | 'video'): any[] {
  const list = Array.isArray(shot?.media_versions) ? (shot as Record<string, any>).media_versions : []
  return list.filter((v: any) => v?.media_type === type && typeof v?.url === 'string' && v.url)
}

function videoVersionCount(shot: Record<string, any>): number {
  return mediaVersionsOf(shot, 'video').length
}

function hasVideoVersions(shot: Record<string, any>): boolean {
  return videoVersionCount(shot) > 0
}

const dialogVideoVersions = computed(() => mediaVersionsOf(versionDialogShot.value, 'video'))
const dialogImageVersions = computed(() => mediaVersionsOf(versionDialogShot.value, 'image'))
const orphanedVideoVersions = computed(() => {
  const list = Array.isArray(activeEpisode.value?.orphaned_media_versions)
    ? activeEpisode.value!.orphaned_media_versions!
    : []
  return list.filter((version) => version?.media_type === 'video' && typeof version?.url === 'string' && version.url.trim())
})

function openVersions(shot: Record<string, any>) {
  versionDialogShot.value = shot
  versionDialogVisible.value = true
}

function selectedVideoVersionIndex(shot: Record<string, any> | null): number {
  const versions = mediaVersionsOf(shot, 'video')
  if (!versions.length) return -1
  const selectedIndex = versions.findIndex((version: any) => !!version?.is_selected)
  if (selectedIndex >= 0) return selectedIndex
  const currentUrl = String(shot?.video_url || '').trim()
  return currentUrl ? versions.findIndex((version: any) => String(version?.url || '').trim() === currentUrl) : -1
}

function selectedVideoVersionLabel(shot: Record<string, any>): string {
  const count = videoVersionCount(shot)
  if (count <= 0) return ''
  const index = selectedVideoVersionIndex(shot)
  return index >= 0 ? t('第 {index}/{count} 版', { index: index + 1, count }) : t('{count} 个版本', { count })
}

function adjacentVideoVersion(shot: Record<string, any>, direction: -1 | 1): any | null {
  const versions = mediaVersionsOf(shot, 'video')
  if (!versions.length) return null
  const index = selectedVideoVersionIndex(shot)
  const nextIndex = (index >= 0 ? index : direction > 0 ? -1 : versions.length) + direction
  return versions[nextIndex] ?? null
}

function chooseVersion(version: any, closeDialog = true) {
  if (!versionIsSelectable(version) || !activeEpisode.value) return
  emit('select-media-version', activeEpisode.value.id, Number(version.id))
  if (closeDialog) {
    versionDialogVisible.value = false
  }
}

function chooseAdjacentVideoVersion(shot: Record<string, any>, direction: -1 | 1) {
  const version = adjacentVideoVersion(shot, direction)
  if (version) {
    chooseVersion(version, false)
  }
}

function versionSourceLabel(source?: string): string {
  return t(VERSION_SOURCE_LABELS[String(source || '')] || '生成')
}

function versionThumb(version: any): string {
  if (version?.media_type === 'video') {
    return String(version?.poster_url || version?.end_frame_url || '')
  }
  return String(version?.url || '')
}

function versionTimeLabel(version: any): string {
  const raw = String(version?.create_time || '')
  return raw ? raw.slice(5, 16) : ''
}

function activateVideo(url: string) {
  activatedVideoUrls.value = { ...activatedVideoUrls.value, [url]: true }
}

function copyImageLink(url: string | undefined) {
  void copyTextToClipboard(url || '', t('图片链接已复制'))
}

function copyVideoLink(url: string | undefined) {
  void copyTextToClipboard(url || '', t('视频链接已复制'))
}

function assetImage(asset: Asset): string {
  return (asset.images ?? []).find((image) => image.view_type === 'main' && image.url?.trim())?.url
    ?? (asset.images ?? []).find((image) => image.url?.trim())?.url
    ?? ''
}

function assetHasPendingCoreJob(asset: Asset): boolean {
  return (asset.image_jobs ?? []).some((job) => job.view_type === 'main' && isAssetImageJobPending(job.status))
}

function assetHasQueuedCoreJob(asset: Asset): boolean {
  return (asset.image_jobs ?? []).some((job) => job.view_type === 'main' && isAssetImageJobQueued(job.status))
}

const missingCoreAssetCount = computed(() =>
  (props.assets ?? []).filter((asset) => !assetImage(asset) && !assetHasPendingCoreJob(asset)).length,
)

const queuedCoreImageJobCount = computed(() =>
  (props.assets ?? []).reduce((count, asset) => {
    return count + (asset.image_jobs ?? []).filter((job) => isAssetImageJobQueued(job.status)).length
  }, 0),
)

function assetImages(asset: Asset): string[] {
  const urls = (asset.images ?? []).map((image) => image.url?.trim() ?? '').filter(Boolean)
  const main = (asset.images ?? []).find((image) => image.view_type === 'main' && image.url?.trim())?.url
  return main ? [main, ...urls.filter((url) => url !== main)] : urls
}

function assetTypeTone(type: Asset['type']) {
  if (type === 'character') return 'character'
  if (type === 'scene') return 'scene'
  return 'prop'
}

function normalizeAssetName(value: unknown): string {
  return String(value || '').trim().toLowerCase()
}

function resolveOutputAsset(asset: any): Asset {
  const type = asset?.type === 'character' || asset?.type === 'scene' || asset?.type === 'prop'
    ? asset.type
    : 'prop'
  const names = [
    normalizeAssetName(asset?.match_name),
    normalizeAssetName(asset?.name),
  ].filter(Boolean)
  const matched = props.assets.find((item) => {
    if (item.type !== type) return false
    const itemName = normalizeAssetName(item.name)
    return names.some((name) => name === itemName)
  })

  return matched ?? {
    id: 0,
    series_id: props.series?.id ?? 0,
    type,
    name: String(asset?.name || asset?.match_name || '未命名资产'),
    description: String(asset?.description || ''),
    prompt: '',
    tags: Array.isArray(asset?.tags) ? asset.tags.map((tag: unknown) => String(tag)) : [],
    status: 'pending',
    images: [],
  } as Asset
}

function openAssetPreview(asset: Asset) {
  const first = assetImage(asset)
  previewAsset.value = asset
  previewActiveUrl.value = assetImages(asset)[0] ?? first ?? ''
  previewVisible.value = true
}

function openAssetEditor(asset: Asset, reference?: AssetReferenceTarget) {
  if (asset.id > 0) {
    emit('edit-asset', asset, reference)
    return
  }
  openAssetPreview(asset)
}
</script>

<template>
  <div class="production-board">
    <aside class="episode-rail">
      <div class="rail-head">
        <el-button class="ghost-button" size="small" @click="emit('back')">
          <el-icon><ArrowLeft /></el-icon><span>{{ t('作品库') }}</span>
        </el-button>
        <div class="rail-title">
          <strong>{{ series?.title || t('未命名作品') }}</strong>
          <span>{{ t('剧集生产、节点执行与产物预览。') }}</span>
          <div v-if="activeEpisode" class="rail-workflow" :title="workflowLabel(activeEpisode.workflow_name, activeEpisode.workflow_id)">
            <em>{{ t('本剧集使用流程') }}</em>
            <b>{{ workflowDisplayText(activeEpisode.workflow_name, activeEpisode.workflow_id, t('未绑定集级流程')) }}</b>
          </div>
        </div>
      </div>

      <div class="work-summary">
        <div class="work-summary__head">
          <span>{{ t('当前作品') }}</span>
          <el-button
            v-if="!seriesTitleEditing"
            class="work-summary__edit"
            size="small"
            text
            :disabled="seriesTitleLocked"
            :title="seriesTitleLocked ? (lockedMessage || t('当前不可修改')) : t('修改名称')"
            @click="beginSeriesTitleEdit"
          >
            <el-icon><EditPen /></el-icon><span>{{ t('修改名称') }}</span>
          </el-button>
        </div>
        <div v-if="seriesTitleEditing" class="work-summary__title-editor">
          <el-input
            v-model="seriesTitleDraft"
            maxlength="120"
            show-word-limit
            :disabled="seriesTitleSaving"
            :placeholder="t('请输入作品名称')"
            @keydown.enter.prevent="saveSeriesTitleEdit"
            @keydown.esc.prevent="cancelSeriesTitleEdit"
          />
          <div class="work-summary__title-actions">
            <el-button size="small" :disabled="seriesTitleSaving" @click="cancelSeriesTitleEdit">{{ t('取消') }}</el-button>
            <el-button size="small" type="primary" :loading="seriesTitleSaving" @click="saveSeriesTitleEdit">
              <el-icon v-if="!seriesTitleSaving"><Check /></el-icon><span>{{ t('保存') }}</span>
            </el-button>
          </div>
        </div>
        <strong v-else>{{ series?.title || t('未命名作品') }}</strong>
        <em>{{ t('{done}/{total} 集已成片', { done: seriesProgress.done, total: seriesProgress.total }) }}</em>
        <div class="work-summary__meta">
          <span>{{ t('内容地区') }}：{{ seriesRegionLabel }}</span>
          <span>{{ t('视觉风格') }}：{{ seriesStyleLabel }}</span>
        </div>
      </div>

      <div v-if="series?.series_workflow_id" class="rail-toolbar">
        <span>{{ t('剧本段') }}</span>
        <el-button
          v-if="!seriesParseCompleted"
          size="small"
          type="primary"
          plain
          :disabled="busy"
          @click="emit('run-series-workflow')"
        >
          <el-icon><Film /></el-icon><span>{{ t('运行剧本解析') }}</span>
        </el-button>
        <em v-else class="rail-toolbar__done">{{ t('剧本解析已完成') }}</em>
      </div>

      <div class="rail-toolbar">
        <span>{{ t('剧集目录') }}</span>
        <el-button size="small" :disabled="busy" @click="emit('new')">
          <el-icon><Plus /></el-icon><span>{{ t('新增') }}</span>
        </el-button>
      </div>

      <div class="episode-list scrollable">
        <div
          v-for="episode in sortedEpisodes"
          :key="episode.id"
          class="episode-item"
          :class="{ 'is-active': episode.id === activeEpisode?.id }"
        >
          <button
            type="button"
            class="episode-item__main"
            @click="activeEpisodeId = episode.id"
          >
            <span class="episode-item__number">{{ t('第 {number} 集', { number: episode.number }) }}</span>
            <strong :title="episode.title || `Episode ${episode.number}`">{{ episode.title || `Episode ${episode.number}` }}</strong>
            <div class="episode-item__meta">
              <span class="episode-item__status" :class="`is-${episodeStatus(episode).tone}`">{{ episodeStatus(episode).text }}</span>
              <em v-if="episodeProgress(episode).total">
                {{ t('{done}/{total} 节点', { done: episodeProgress(episode).done, total: episodeProgress(episode).total }) }}
              </em>
            </div>
            <div v-if="episodeProgress(episode).total" class="episode-item__progress">
              <span :style="{ width: `${episodeProgress(episode).percent}%` }"></span>
            </div>
            <small v-if="episodeProgress(episode).failed" class="episode-item__warning">
              {{ t('{count} 个节点失败', { count: episodeProgress(episode).failed }) }}
            </small>
          </button>
          <el-button
            class="episode-item__delete"
            size="small"
            text
            type="danger"
            :disabled="busy || locked || episode.workflow_state?.auto_execution_locked === true"
            :title="t('删除本集')"
            @click.stop="emit('delete-episode', episode)"
          >
            <el-icon><Delete /></el-icon>
          </el-button>
        </div>
      </div>
    </aside>

    <main class="episode-workbench">
      <header class="workbench-head">
        <div>
          <span>EPISODE DETAIL</span>
          <strong v-if="activeEpisode">{{ t('第 {number} 集', { number: activeEpisode.number }) }} · {{ activeEpisode.title || `Episode ${activeEpisode.number}` }}</strong>
          <strong v-else>{{ t('剧集详情') }}</strong>
          <small class="workbench-head__meta">{{ t('内容地区') }}：{{ seriesRegionLabel }} · {{ t('视觉风格') }}：{{ seriesStyleLabel }}</small>
        </div>
        <el-button
          v-if="activeEpisode"
          size="small"
          type="danger"
          plain
          :disabled="busy || locked || activeEpisodeAutoRunning"
          @click="emit('delete-episode', activeEpisode)"
        >
          <el-icon><Delete /></el-icon><span>{{ t('删除本集') }}</span>
        </el-button>
      </header>

      <div v-if="activeEpisode" class="workbench-body scrollable">
        <section class="episode-summary-card">
          <div class="episode-preview">
            <img v-if="episodeCover(activeEpisode)" v-lazy-src="episodeCover(activeEpisode)" alt="" />
            <div v-else class="episode-preview__empty">
              <el-icon><Film /></el-icon>
              <span>{{ t('暂无画面') }}</span>
            </div>
            <el-button
              v-if="episodeCover(activeEpisode)"
              class="image-copy-btn episode-preview__copy"
              size="small"
              circle
              :title="t('复制图片链接')"
              @click="copyImageLink(episodeCover(activeEpisode))"
            >
              <el-icon><DocumentCopy /></el-icon>
            </el-button>
            <StatusBadge class="episode-preview__badge" :tone="episodeStatus(activeEpisode).tone" :pulse="episodeStatus(activeEpisode).tone === 'busy'">
              {{ episodeStatus(activeEpisode).text }}
            </StatusBadge>
          </div>

          <div class="episode-summary">
            <div class="episode-summary__copy">
              <span>{{ t('当前剧集') }}</span>
              <div class="episode-summary__title-row">
                <h2>{{ t('第 {number} 集', { number: activeEpisode.number }) }} · {{ activeEpisode.title || `Episode ${activeEpisode.number}` }}</h2>
                <el-button
                  v-if="!activePlotEditing"
                  class="episode-summary__edit"
                  size="small"
                  :disabled="activePlotLocked"
                  @click="beginPlotEdit(activeEpisode)"
                >
                  <el-icon><EditPen /></el-icon><span>{{ t('编辑概要') }}</span>
                </el-button>
              </div>
              <div v-if="activePlotEditing" class="episode-plot-editor">
                <el-input
                  v-model="plotDraft"
                  type="textarea"
                  :rows="4"
                  resize="vertical"
                  :disabled="activePlotSaving"
                  :placeholder="t('填写这一集的剧情简介…')"
                />
                <div class="episode-plot-editor__actions">
                  <el-button size="small" :disabled="activePlotSaving" @click="cancelPlotEdit">
                    {{ t('取消') }}
                  </el-button>
                  <el-button size="small" type="primary" :loading="activePlotSaving" @click="savePlotEdit(activeEpisode)">
                    <el-icon v-if="!activePlotSaving"><Check /></el-icon><span>{{ t('保存概要') }}</span>
                  </el-button>
                </div>
              </div>
              <p v-else>{{ episodePlotSummary(activeEpisode) }}</p>
            </div>

            <div class="episode-summary__footer">
              <div class="episode-summary__meta">
                <span><strong>{{ episodeProgress(activeEpisode).done }}/{{ episodeProgress(activeEpisode).total || stageRows(activeEpisode).length }}</strong>{{ t('流程进度') }}</span>
                <span><strong>{{ shotCount(activeEpisode, 'image_url') }}</strong>{{ t('画面') }}</span>
                <span><strong>{{ shotCount(activeEpisode, 'video_url') }}</strong>{{ t('视频') }}</span>
              </div>
              <div class="episode-summary__actions">
                <el-button
                  v-if="activeEpisodeAutoRunning"
                  class="run-button"
                  type="danger"
                  :loading="isEpisodeSubmitting(activeEpisode.id)"
                  :disabled="busy || isEpisodeSubmitting(activeEpisode.id)"
                  @click="emit('cancel-auto-run', activeEpisode.id)"
                >
                  <el-icon><VideoPause /></el-icon><span>{{ t('取消自动执行') }}</span>
                </el-button>
              </div>
            </div>
          </div>
        </section>

        <section class="panel">
          <div class="panel-head">
            <strong>{{ t('流程节点') }}</strong>
            <span>{{ t('展开节点查看产物，单独执行每一步。') }}</span>
          </div>

          <div v-if="stageRows(activeEpisode).length" class="stage-list">
            <article
              v-for="(node, nodeIndex) in stageRows(activeEpisode)"
              :key="node.workflow_node_id"
              class="stage-card"
              :class="[
                `is-${node.status}`,
                `kind-${nodeKindTone(node.kind)}`,
                { 'is-expanded': isExpanded(node.workflow_node_id) },
              ]"
            >
              <button type="button" class="stage-main" @click="toggleNode(node.workflow_node_id)">
                <div class="stage-index">
                  <el-icon><ArrowRight /></el-icon>
                </div>
                <div class="stage-copy">
                  <div class="stage-title">
                    <strong>{{ stageNodeLabel(node) }}</strong>
                    <span :class="`stage-kind stage-kind--${nodeKindTone(node.kind)}`">{{ nodeKindLabel(node.kind) }}</span>
                    <em>Step {{ String(nodeIndex + 1).padStart(2, '0') }}</em>
                  </div>
                  <p>{{ stageSummary(node, activeEpisode) }}</p>
                  <p v-if="stageNodeError(node)" class="stage-error">{{ stageNodeError(node) }}</p>
                  <span class="stage-expand-hint">
                    {{ isExpanded(node.workflow_node_id) ? t('收起产物') : t('展开产物') }}
                  </span>
                </div>
              </button>

              <div class="stage-actions">
                <StatusBadge :tone="stageTone(node.status)" :pulse="node.status === 'running'">
                  {{ stageStatusText(node.status) }}
                </StatusBadge>
                <el-button
                  v-if="node.kind === 'video'"
                  size="small"
                  :disabled="busy || activeEpisodeAutoRunning"
                  @click="emit('preview-video-prompts', activeEpisode.id, node.workflow_node_id)"
                >
                  {{ t('查看提示词') }}
                </el-button>
                <el-button
                  v-if="node.kind === 'input'"
                  size="small"
                  :disabled="activePlotLocked"
                  @click="beginStagePlotEdit(activeEpisode, node.workflow_node_id)"
                >
                  <el-icon><EditPen /></el-icon><span>{{ t('编辑概要') }}</span>
                </el-button>
                <el-button
                  size="small"
                  :loading="node.status === 'running' || isStageSubmitting(activeEpisode.id, node.workflow_node_id)"
                  :disabled="busy || activeEpisodeAutoRunning || node.status === 'running' || isStageSubmitting(activeEpisode.id, node.workflow_node_id)"
                  @click="emit('run-stage', activeEpisode.id, node.workflow_node_id)"
                >
                  {{ stageActionLabel(node, activeEpisode.id) }}
                </el-button>
                <el-button
                  v-if="node.kind !== 'video' && isStageCancellable(node, activeEpisode)"
                  size="small"
                  type="danger"
                  plain
                  :disabled="busy || activeEpisodeAutoRunning"
                  @click="emit('cancel-stage', activeEpisode.id, node.workflow_node_id)"
                >
                  {{ t('取消') }}
                </el-button>
              </div>

              <div v-if="isExpanded(node.workflow_node_id)" class="stage-expanded">
                <div v-if="shouldHideNodeOutputForGenerating(node, activeEpisode)" class="node-generating">
                  <span class="node-generating__spinner" aria-hidden="true"></span>
                  <div>
                    <strong>{{ nodeGeneratingTitle(node, activeEpisode) }}</strong>
                    <p>{{ t('旧产物已暂时隐藏，等这次生成完成后会自动显示最新结果。') }}</p>
                  </div>
                </div>

                <div v-else-if="isAssetExtractionNode(node)" class="asset-output-panel asset-output-panel--single">
                  <div class="asset-output-panel__head">
                    <div>
                      <strong>{{ t('资产提取结果') }}</strong>
                      <span>{{ t('按类型汇总本集整理出的资产数量，不再展示原始 JSON。') }}</span>
                    </div>
                    <em>{{ node.workflow_node_id }}</em>
                  </div>
                  <p class="asset-output-summary">{{ assetOutputSummary(node) }}</p>
                  <div v-if="assetOutputStatCards(node).length" class="asset-output-stats">
                    <div
                      v-for="card in assetOutputStatCards(node)"
                      :key="card.key"
                      class="asset-output-stat"
                      :class="`asset-output-stat--${card.key}`"
                    >
                      <span>{{ card.label }}</span>
                      <strong>{{ card.count }}</strong>
                      <em>{{ card.unit }}</em>
                    </div>
                  </div>
                  <div v-if="assetOutputAssets(node).length" class="asset-output-name-list">
                    <div
                      v-for="group in [
                        { type: 'character', label: t('人物'), items: assetOutputAssets(node).filter((a) => String(a.type || '') === 'character') },
                        { type: 'scene', label: t('场景'), items: assetOutputAssets(node).filter((a) => String(a.type || '') === 'scene') },
                        { type: 'prop', label: t('道具'), items: assetOutputAssets(node).filter((a) => String(a.type || '') === 'prop') },
                      ].filter((group) => group.items.length > 0)"
                      :key="group.type"
                      class="asset-output-name-group"
                    >
                      <strong>{{ group.label }} · {{ group.items.length }}</strong>
                      <div class="asset-output-name-chips">
                        <button
                          v-for="(asset, assetIndex) in group.items"
                          :key="`${group.type}-${asset.name || 'asset'}-${assetIndex}`"
                          type="button"
                          class="asset-output-name-chip"
                          @click="openAssetEditor(resolveOutputAsset(asset))"
                        >
                          {{ asset.name || t('未命名资产') }}
                        </button>
                      </div>
                    </div>
                    <div
                      v-if="assetOutputLooks(node).length"
                      class="asset-output-name-group"
                    >
                      <strong>{{ t('人物造型') }} · {{ assetOutputLooks(node).length }}</strong>
                      <div class="asset-output-name-chips">
                        <span
                          v-for="(look, lookIndex) in assetOutputLooks(node)"
                          :key="`look-${look.character_name || ''}-${look.look_name || lookIndex}`"
                          class="asset-output-name-chip asset-output-name-chip--static"
                        >
                          {{ makeLookReferenceName(String(look.character_name || ''), String(look.look_name || '')) || t('未命名造型') }}
                        </span>
                      </div>
                    </div>
                  </div>
                  <div v-else-if="!assetOutputStatCards(node).length" class="empty-output">{{ t('没有读取到资产明细。') }}</div>
                </div>

                <div v-else class="node-output">
                  <div class="node-output__head">
                    <span>{{ t('{kind}产物', { kind: nodeKindLabel(node.kind) }) }}</span>
                    <em v-if="isStoryboardProcessNode(node) && storyboardShotsForNode(node).length">
                      {{ t('共 {count} 个分镜', { count: storyboardShotsForNode(node).length }) }} · {{ node.workflow_node_id }}
                    </em>
                    <em v-else>{{ node.workflow_node_id }}</em>
                  </div>
                  <div v-if="node.kind === 'input'" class="node-plot-panel">
                    <div v-if="activePlotEditing" class="episode-plot-editor episode-plot-editor--node">
                      <el-input
                        v-model="plotDraft"
                        type="textarea"
                        :rows="6"
                        resize="vertical"
                        :disabled="activePlotSaving"
                        :placeholder="t('填写这一集的剧情简介…')"
                      />
                      <div class="episode-plot-editor__actions">
                        <el-button size="small" :disabled="activePlotSaving" @click="cancelPlotEdit">
                          {{ t('取消') }}
                        </el-button>
                        <el-button size="small" type="primary" :loading="activePlotSaving" @click="savePlotEdit(activeEpisode)">
                          <el-icon v-if="!activePlotSaving"><Check /></el-icon><span>{{ t('保存概要') }}</span>
                        </el-button>
                      </div>
                    </div>
                    <template v-else>
                      <p class="node-plot-panel__text">{{ episodePlotSummary(activeEpisode) }}</p>
                      <el-button size="small" :disabled="activePlotLocked" @click="beginStagePlotEdit(activeEpisode, node.workflow_node_id)">
                        <el-icon><EditPen /></el-icon><span>{{ t('编辑概要') }}</span>
                      </el-button>
                    </template>
                  </div>
                  <div v-else-if="isStoryboardProcessNode(node) && storyboardShotsForNode(node).length" class="storyboard-card-board">
                    <article
                      v-for="shot in storyboardShotsForNode(node)"
                      :key="`${node.workflow_node_id}-${shot.index}`"
                      class="storyboard-card"
                      :class="{ 'is-editing': isStoryboardShotEditing(node, shot) }"
                    >
                      <header class="storyboard-card__head">
                        <div class="storyboard-card__title">
                          <span>{{ t('镜头 {number}', { number: shot.index }) }}</span>
                          <strong>{{ shot.title || `镜头 ${shot.index}` }}</strong>
                        </div>
                        <div class="storyboard-card__actions">
                          <span v-if="storyboardReferencesForShot(node, shot).length" class="storyboard-card__asset-count">
                            {{ t('{count} 个引用', { count: storyboardReferencesForShot(node, shot).length }) }}
                          </span>
                          <template v-if="isStoryboardShotEditing(node, shot)">
                            <el-button
                              size="small"
                              :loading="isStoryboardShotSaving(node, shot)"
                              :disabled="isStoryboardShotActionLocked(node, shot) || hasStoryboardRegeneratePending || !storyboardShotDraft(node, shot).text.trim()"
                              @click="saveStoryboardShot(node, shot)"
                            >
                              {{ t('保存') }}
                            </el-button>
                            <el-button
                              size="small"
                              text
                              :disabled="isStoryboardShotSaving(node, shot)"
                              @click="cancelStoryboardShotEdit(node, shot)"
                            >
                              {{ t('取消') }}
                            </el-button>
                          </template>
                          <template v-else>
                            <el-button
                              size="small"
                              :loading="isStoryboardShotRegenerating(node, shot)"
                              :disabled="isStoryboardShotActionLocked(node, shot) || hasStoryboardRegeneratePending"
                              @click="regenerateStoryboardShot(node, shot)"
                            >
                              {{ t('AI重新生成') }}
                            </el-button>
                            <el-button
                              size="small"
                              :disabled="isStoryboardShotActionLocked(node, shot) || hasStoryboardRegeneratePending"
                              @click="beginStoryboardShotEdit(node, shot)"
                            >
                              {{ t('编辑') }}
                            </el-button>
                          </template>
                        </div>
                      </header>

                      <div v-if="isStoryboardShotEditing(node, shot)" class="storyboard-card__editor">
                        <label>
                          <span>{{ t('镜头标题') }}</span>
                          <input
                            class="storyboard-card__title-input"
                            :value="storyboardShotDraft(node, shot).title"
                            :disabled="isStoryboardShotSaving(node, shot)"
                            @input="handleStoryboardShotTitleInput($event, node, shot)"
                          />
                        </label>
                        <label class="storyboard-card__text-field">
                          <span>{{ t('分镜内容') }}</span>
                          <div class="storyboard-card__textarea-wrap">
                            <pre class="storyboard-card__textarea-highlight" aria-hidden="true"><template
                              v-for="(segment, segmentIndex) in storyboardHighlightSegments(storyboardShotDraft(node, shot).text)"
                              :key="segmentIndex"
                            ><span
                              v-if="segment.type === 'reference' && segment.option"
                              class="storyboard-inline-ref"
                              :class="`storyboard-inline-ref--${segment.option.type}`"
                            >{{ segment.content }}</span><template v-else>{{ segment.content }}</template></template></pre>
                            <textarea
                              class="storyboard-card__textarea"
                              :value="storyboardShotDraft(node, shot).text"
                              :disabled="isStoryboardShotSaving(node, shot)"
                              rows="8"
                              :placeholder="t('storyboard.editor.placeholder')"
                              @input="handleStoryboardShotTextInput($event, node, shot)"
                              @keydown="handleStoryboardShotTextKeydown($event, node, shot)"
                              @scroll="syncStoryboardTextareaHighlight"
                              @keyup="refreshStoryboardMentionState($event, node, shot)"
                              @click="refreshStoryboardMentionState($event, node, shot)"
                              @focus="refreshStoryboardMentionState($event, node, shot)"
                              @blur="hideStoryboardMentionLater(node, shot)"
                            />
                          </div>
                          <div v-if="storyboardMentionOptions(node, shot).length" class="storyboard-mention-menu">
                            <button
                              v-for="option in storyboardMentionOptions(node, shot)"
                              :key="option.key"
                              type="button"
                              class="storyboard-mention-option"
                              @mousedown.prevent="applyStoryboardAssetReference(node, shot, option)"
                            >
                              <span class="storyboard-mention-option__thumb" :class="{ 'is-empty': !option.image }">
                                <img v-if="option.image" :src="option.image" alt="" loading="lazy" />
                                <em v-else>{{ option.label.slice(0, 1) }}</em>
                              </span>
                              <span>
                                <strong>{{ option.label }}</strong>
                                <em>{{ option.subtitle }}</em>
                              </span>
                            </button>
                          </div>
                        </label>
                        <p class="storyboard-card__edit-hint">
                          {{ t('保存会更新整个“分镜处理”产物；当前仅让这个镜头对应的视频和最终成片失效，已生成图片会保留。') }}
                        </p>
                      </div>
                      <AssetLinkedText
                        v-else
                        :text="shot.contentText || shot.text"
                        :assets="assets"
                        :mentions="nodeAssetMentions(node)"
                        :display-references="storyboardReferencesForShot(node, shot).map((ref) => ({ key: ref.insertText, label: ref.label, asset: ref.asset, reference: ref.reference }))"
                        @open-asset="openAssetEditor"
                      />
                    </article>
                  </div>
                  <AssetLinkedText
                    v-else-if="node.kind !== 'video' && node.kind !== 'output' && (node.raw_output || prettyOutput(node.output_json))"
                    :text="node.raw_output || prettyOutput(node.output_json)"
                    :assets="assets"
                    :mentions="nodeAssetMentions(node)"
                    @open-asset="openAssetEditor"
                  />
                  <div v-else-if="node.kind !== 'video' && node.kind !== 'output'" class="empty-output">{{ t('暂无可展开产物。') }}</div>
                </div>

                <div v-if="!shouldHideNodeOutputForGenerating(node, activeEpisode) && node.kind === 'image' && nodeImages(activeEpisode).length" class="media-wall">
                  <div v-for="shot in nodeImages(activeEpisode)" :key="shot.id" class="media-image-card">
                    <img v-lazy-src="shotImageUrl(shot)" alt="" />
                    <el-button
                      class="image-copy-btn media-image-card__copy"
                      size="small"
                      circle
                      :title="t('复制图片链接')"
                      @click="copyImageLink(shotImageUrl(shot))"
                    >
                      <el-icon><DocumentCopy /></el-icon>
                    </el-button>
                  </div>
                </div>

                <div v-else-if="!shouldHideNodeOutputForGenerating(node, activeEpisode) && node.kind === 'video'" class="video-production-panel">
                  <div class="video-production-panel__head">
                    <div>
                      <strong>{{ t('视频生成产物') }}</strong>
                      <span>{{ t('可以生成单个镜头，也可以一次提交全部镜头。') }}</span>
                    </div>
                    <el-button
                      type="primary"
                      size="small"
                      :loading="node.status === 'running' || isStageSubmitting(activeEpisode.id, node.workflow_node_id)"
                      :disabled="busy || node.status === 'running' || isStageSubmitting(activeEpisode.id, node.workflow_node_id)"
                      @click="emit('run-stage', activeEpisode.id, node.workflow_node_id)"
                    >
                      {{ t('全部生成') }}
                    </el-button>
                  </div>

                  <div v-if="videoShotRows(node, activeEpisode).length" class="video-shot-board">
                    <aside class="video-shot-list">
                      <button
                        v-for="shot in videoShotRows(node, activeEpisode)"
                        :key="shot.id"
                        type="button"
                        class="video-shot-list__item"
                        :class="{ 'is-active': isShotActive(node, shot) }"
                        @click="selectShot(node.workflow_node_id, shot)"
                      >
                        <span class="video-shot-list__thumb" :class="{ 'is-empty': !(shot.video_url || shot.image_url) }">
                          <img v-if="shot.video_url || shot.image_url" v-lazy-src="shot.image_url || shot.end_frame_url" alt="" />
                          <el-icon v-else><Picture /></el-icon>
                          <em
                            v-if="videoShotStatusLabel(shot)"
                            class="video-shot-list__status"
                            :class="{ 'is-busy': isVideoShotBusy(shot), 'is-failed': videoShotStatus(shot) === 'failed', 'is-stale': videoShotStatus(shot) === 'stale' }"
                          >{{ videoShotStatusLabel(shot) }}</em>
                          <em
                            v-if="hasVideoVersions(shot)"
                            class="video-shot-list__versions"
                            :title="t('{count} 个视频版本，点开可查看 / 切换', { count: videoVersionCount(shot) })"
                          >V{{ videoVersionCount(shot) }}</em>
                        </span>
                        <span class="video-shot-list__meta">
                          <span class="video-shot-list__title-row">
                            <strong>{{ t('镜头 {number}', { number: shot.index || shot.id }) }}</strong>
                            <span
                              v-if="hasVideoVersions(shot)"
                              class="video-shot-list__meta-action"
                              role="button"
                              tabindex="0"
                              :title="t('查看这条镜头的 {count} 个视频版本', { count: videoVersionCount(shot) })"
                              @click.stop="openVersions(shot)"
                              @keydown.enter.stop.prevent="openVersions(shot)"
                              @keydown.space.stop.prevent="openVersions(shot)"
                            >
                              <el-icon><Files /></el-icon>
                              <span>{{ videoVersionCount(shot) }}</span>
                            </span>
                            <span
                              v-if="canCancelVideoShot(shot)"
                              class="video-shot-list__meta-action is-danger"
                              role="button"
                              tabindex="0"
                              :title="t('只取消这一镜排队，已成功镜头保留')"
                              @click.stop="emit('cancel-video-shot', activeEpisode.id, node.workflow_node_id, videoShotJobId(shot))"
                              @keydown.enter.stop.prevent="emit('cancel-video-shot', activeEpisode.id, node.workflow_node_id, videoShotJobId(shot))"
                              @keydown.space.stop.prevent="emit('cancel-video-shot', activeEpisode.id, node.workflow_node_id, videoShotJobId(shot))"
                            >
                              {{ t('取消排队') }}
                            </span>
                          </span>
                          <em>{{ shot.desc?.slice(0, 28) || t('暂无描述') }}</em>
                        </span>
                      </button>
                    </aside>

                    <section v-if="activeVideoShot(node, activeEpisode)" class="video-shot-detail">
                      <template v-for="shot in [activeVideoShot(node, activeEpisode)]" :key="shot.id">
                        <header class="video-shot-detail__head">
                          <strong>{{ t('镜头 {number}', { number: shot.index || shot.id }) }}</strong>
                          <span
                            v-if="videoShotStatusLabel(shot)"
                            class="video-shot-detail__status"
                            :class="{ 'is-busy': isVideoShotBusy(shot), 'is-failed': videoShotStatus(shot) === 'failed', 'is-stale': videoShotStatus(shot) === 'stale' }"
                          >{{ videoShotStatusLabel(shot) }}</span>
                          <div v-if="hasVideoVersions(shot)" class="video-shot-version-switch">
                            <el-button
                              size="small"
                              plain
                              :disabled="!adjacentVideoVersion(shot, -1)"
                              :title="t('切换到上一版视频')"
                              @click="chooseAdjacentVideoVersion(shot, -1)"
                            >
                              <el-icon><ArrowLeft /></el-icon>
                              <span>{{ t('上一版') }}</span>
                            </el-button>
                            <button
                              type="button"
                              class="video-shot-version-switch__current"
                              :title="t('打开完整版本记录')"
                              @click="openVersions(shot)"
                            >
                              <el-icon><Files /></el-icon>
                              <span>{{ selectedVideoVersionLabel(shot) }}</span>
                            </button>
                            <el-button
                              size="small"
                              plain
                              :disabled="!adjacentVideoVersion(shot, 1)"
                              :title="t('切换到下一版视频')"
                              @click="chooseAdjacentVideoVersion(shot, 1)"
                            >
                              <span>{{ t('下一版') }}</span>
                              <el-icon><ArrowRight /></el-icon>
                            </el-button>
                          </div>
                        </header>

                        <div v-if="isVideoShotIntentStale(shot)" class="video-shot-detail__intent-banner">
                          {{ t('分镜意图已更新，请按新意图重新生成。历史成片仍保留在版本库中，可预览或重新选用。') }}
                        </div>

                        <div class="video-shot-detail__body">
                          <div class="video-shot-detail__media">
                            <div
                              class="video-shot-detail__player"
                              :class="{
                                'is-empty': !shot.video_url,
                                'is-active': !!shot.video_url && isVideoActivated(shot.video_url),
                              }"
                            >
                              <video
                                v-if="shot.video_url && isVideoActivated(shot.video_url)"
                                :key="shot.video_url"
                                :src="shot.video_url"
                                controls
                                preload="metadata"
                              />
                              <button v-else-if="shot.video_url" type="button" @click="activateVideo(shot.video_url)">
                                <el-icon><VideoPlay /></el-icon>
                                <span>{{ t('点击加载视频') }}</span>
                              </button>
                              <span v-else class="video-shot-detail__placeholder">
                                <el-icon><VideoPlay /></el-icon>
                                <em>{{ hasVideoVersions(shot) ? t('当前没有选用成片') : t('这一段还没有视频') }}</em>
                              </span>
                            </div>
                          </div>

                          <aside class="video-shot-detail__side">
                            <div class="video-shot-detail__intent">
                              <header class="video-shot-detail__section-title">{{ t('当前意图') }}</header>
                              <p v-if="shot.desc" class="video-shot-detail__desc">{{ shot.desc }}</p>
                              <p v-else class="video-shot-detail__empty">{{ t('暂无分镜描述') }}</p>
                            </div>

                            <div class="video-shot-detail__prompt">
                              <label>{{ t('提示词（仅本次生效，可修改后重新生成这一段）') }}</label>
                              <el-input
                                :model-value="shotPromptDraft(node.workflow_node_id, shot)"
                                type="textarea"
                                :rows="5"
                                :autosize="{ minRows: 4, maxRows: 10 }"
                                :placeholder="t('填写本镜头视频的提示词')"
                                @update:model-value="(val: string) => setShotPromptDraft(shot, val)"
                              />
                            </div>

                            <div class="video-shot-detail__actions">
                              <el-button
                                type="primary"
                                :loading="isVideoShotSubmitting(activeEpisode.id, node.workflow_node_id, shotIndexOf(shot)) || isVideoShotBusy(shot)"
                                :disabled="busy || activeEpisodeAutoRunning || isVideoNodeShotActionLocked(node, activeEpisode, shot) || assetsMissingCoreViewsForShot(shot).length > 0 || (!!shot.job_id && !shotPromptDraft(node.workflow_node_id, shot).trim())"
                                @click="submitVideoShot(activeEpisode.id, node.workflow_node_id, shot)"
                              >
                                {{ videoShotActionLabel(shot) }}
                              </el-button>
                              <el-button
                                v-if="canCancelVideoShot(shot)"
                                type="danger"
                                plain
                                :disabled="busy"
                                :title="t('只取消这一镜的排队/生成；已成功的其他镜头不受影响')"
                                @click="emit('cancel-video-shot', activeEpisode.id, node.workflow_node_id, videoShotJobId(shot))"
                              >
                                {{ t('取消本镜排队') }}
                              </el-button>
                              <el-button
                                text
                                :disabled="!shotPromptDirty(node.workflow_node_id, shot)"
                                @click="resetShotPromptDraft(shot)"
                              >
                                {{ t('恢复原提示词') }}
                              </el-button>
                            </div>
                            <p v-if="canCancelVideoShot(shot)" class="video-shot-detail__hint">{{ t('「取消本镜排队」只停当前这一镜（及仍在等它的下游镜）；已经生成成功的镜头会保留。') }}</p>
                            <p v-else-if="isVideoNodeShotActionLocked(node, activeEpisode, shot) && !isVideoShotBusy(shot)" class="video-shot-detail__hint">{{ t('当前视频节点正在生成中，请等待完成后再操作其他镜头') }}</p>
                            <p v-else-if="assetsMissingCoreViewsForShot(shot).length > 0" class="video-shot-detail__hint">{{ t('当前镜头引用的资产尚未就绪，无法生成视频。缺少：{names}{more}', { names: assetsMissingCoreViewsForShot(shot).slice(0, 8).map((asset) => asset.name || `#${asset.id}`).join('、'), more: assetsMissingCoreViewsForShot(shot).length > 8 ? t(' 等 {count} 个', { count: assetsMissingCoreViewsForShot(shot).length }) : '' }) }}</p>
                            <p v-else-if="!shot.job_id" class="video-shot-detail__hint">{{ t('还没有这一段的生成记录，先点「{action}」即可首次生成。', { action: videoShotActionLabel(shot) }) }}</p>
                            <p v-else class="video-shot-detail__hint">{{ t('只会重新提交当前镜头，不会触发顶部的全部生成。') }}</p>
                          </aside>
                        </div>
                      </template>
                    </section>
                  </div>
                  <div v-else class="empty-output">{{ t('还没有可生成视频的镜头，请先完成上游分镜。') }}</div>
                </div>

                <div v-else-if="!shouldHideNodeOutputForGenerating(node, activeEpisode) && node.kind === 'output'" class="final-video-output">
                  <div v-if="outputVideoUrl(node)" class="final-video-card">
                    <div class="final-video-card__head">
                      <div>
                        <strong>{{ t('最终成片') }}</strong>
                        <span>{{ t('已合成为 1 个 MP4 视频，可直接预览或下载。') }}</span>
                      </div>
                      <div class="final-video-card__actions">
                        <el-button size="small" @click="copyVideoLink(outputVideoUrl(node))">
                          {{ t('复制链接') }}
                        </el-button>
                        <el-button size="small" type="primary" tag="a" :href="outputVideoUrl(node)" download target="_blank" rel="noopener">
                          {{ t('下载视频') }}
                        </el-button>
                      </div>
                    </div>
                    <video
                      v-if="isVideoActivated(outputVideoUrl(node))"
                      :src="outputVideoUrl(node)"
                      controls
                      preload="metadata"
                    />
                    <button v-else type="button" class="final-video-card__poster" @click="activateVideo(outputVideoUrl(node))">
                      <el-icon><VideoPlay /></el-icon>
                      <span>{{ t('点击预览视频') }}</span>
                    </button>
                  </div>
                  <div v-else class="empty-output">{{ t('等待输出最终成片') }}</div>
                </div>
              </div>
            </article>
          </div>

          <EmptyState
            v-else
            class="stage-empty"
            icon="Connection"
            :title="activeEpisode.workflow_id ? t('本集还没开始生成') : t('本集还没绑定工作流')"
            :hint="activeEpisode.workflow_id
              ? t('直接在下方流程节点里逐步执行即可；首次执行会自动初始化本集流程。')
              : t('先为该集选择一个剧集工作流，然后就能开始生成画面和视频。')"
          >
            <el-button
              v-if="!activeEpisode.workflow_id"
              type="primary"
              :disabled="busy"
              @click="emit('bind-workflow', activeEpisode.id)"
            >
              <el-icon><Connection /></el-icon><span>{{ t('绑定工作流') }}</span>
            </el-button>
          </EmptyState>
        </section>

      </div>

      <EmptyState
        v-else
        class="workbench-empty"
        icon="Tickets"
        :title="t('还没有剧集')"
        :hint="t('等剧本解析完成会自动拆集，或点「新增」手动添加。')"
      />
    </main>

    <aside class="asset-panel">
      <div class="asset-panel__head">
        <el-icon><Box /></el-icon>
        <span>{{ t('资产参考') }}</span>
        <div class="asset-panel__actions">
          <el-button
            v-if="assets.length"
            class="asset-panel__batch"
            size="small"
            type="primary"
            plain
            :loading="batchGenerating"
            :disabled="busy || locked || missingCoreAssetCount <= 0"
            @click="emit('batch-generate-core-images')"
          >
            <el-icon v-if="!batchGenerating"><MagicStick /></el-icon>
            <span>{{ t('批量生成核心视图') }}</span>
          </el-button>
          <el-button
            v-if="queuedCoreImageJobCount > 0"
            class="asset-panel__batch"
            size="small"
            type="warning"
            plain
            :loading="batchCancelling"
            :disabled="busy || locked"
            @click="emit('batch-cancel-queued-image-jobs')"
          >
            <el-icon v-if="!batchCancelling"><CircleClose /></el-icon>
            <span>{{ t('取消排队') }}</span>
            <small>{{ queuedCoreImageJobCount }}</small>
          </el-button>
        </div>
      </div>

      <div class="asset-panel__body scrollable">
        <template v-for="(label, key) in { character: t('人物'), scene: t('场景'), prop: t('道具') }" :key="key">
          <section v-if="assetGroups[key as AssetKind].length" class="asset-group">
            <div class="asset-group__title" :class="`asset-group__title--${key}`">
              <span>{{ label }}</span>
              <em>{{ assetGroups[key as AssetKind].length }}</em>
            </div>
            <div class="asset-grid">
              <button
                v-for="asset in assetGroups[key as AssetKind]"
                :key="asset.id"
                type="button"
                class="asset-card"
                :class="[`asset-card--${assetTypeTone(asset.type)}`, { 'is-clickable': !!assetImage(asset), 'is-empty': !assetImage(asset) && !assetHasPendingCoreJob(asset), 'is-generating': assetHasPendingCoreJob(asset) }]"
                :title="asset.name"
                @click="openAssetEditor(asset)"
              >
                <div class="asset-card__thumb">
                  <img v-if="assetImage(asset)" v-lazy-src="assetImage(asset)" alt="" />
                  <div v-else-if="!assetHasPendingCoreJob(asset)">{{ asset.name.slice(0, 1) }}</div>
                  <div v-if="assetHasQueuedCoreJob(asset)" class="asset-card__generating asset-card__generating--queued">
                    <em>{{ t('排队中') }}</em>
                    <button
                      type="button"
                      class="asset-card__cancel-queue"
                      :disabled="busy || locked || !!cancellingAssetIds?.[asset.id]"
                      @click.stop="emit('cancel-asset-queued-image-jobs', asset)"
                    >
                      {{ cancellingAssetIds?.[asset.id] ? t('取消中') : t('取消排队') }}
                    </button>
                  </div>
                  <div v-else-if="assetHasPendingCoreJob(asset)" class="asset-card__generating">
                    <el-icon class="is-loading"><Loading /></el-icon>
                    <em>{{ t('生成中') }}</em>
                  </div>
                </div>
                <span>{{ asset.name }}</span>
              </button>
            </div>
          </section>
        </template>

        <div v-if="!assets.length" class="asset-empty">
          <el-icon><Box /></el-icon>
          <p>{{ t('暂无资产') }}</p>
          <span>{{ t('人物、场景、道具与人物造型参考会展示在这里。') }}</span>
        </div>
      </div>
    </aside>

    <el-dialog
      v-model="previewVisible"
      class="asset-preview-dialog"
      width="min(94vw, 1200px)"
      append-to-body
      align-center
      :title="previewAsset?.name || t('资产预览')"
    >
      <div v-if="previewAsset" class="asset-preview">
        <div class="asset-preview__stage">
          <img v-if="previewActiveUrl" :src="previewActiveUrl" alt="" />
          <div v-else class="asset-preview__empty">
            <el-icon><Picture /></el-icon>
            <span>{{ t('暂无参考图') }}</span>
          </div>
          <el-button
            v-if="previewActiveUrl"
            class="image-copy-btn asset-preview__copy"
            size="small"
            :title="t('复制图片链接')"
            @click="copyImageLink(previewActiveUrl)"
          >
            <el-icon><DocumentCopy /></el-icon>
            <span>{{ t('复制图片链接') }}</span>
          </el-button>
        </div>
        <div class="asset-preview__side">
          <strong>{{ previewAsset.name }}</strong>
          <p>{{ previewAsset.description || t('暂无资产描述') }}</p>
          <div v-if="previewAsset.tags?.length" class="asset-tags">
            <span v-for="tag in previewAsset.tags" :key="tag">{{ tag }}</span>
          </div>
          <div v-if="previewImages.length > 1" class="asset-thumbs">
            <button
              v-for="(url, index) in previewImages"
              :key="index"
              type="button"
              :class="{ 'is-active': url === previewActiveUrl }"
              @click="previewActiveUrl = url"
            >
              <img v-lazy-src="url" alt="" />
            </button>
          </div>
        </div>
      </div>
    </el-dialog>

    <!-- 镜头版本面板：历史成片库 / 参考图 / 未挂载历史 -->
    <el-dialog
      v-model="versionDialogVisible"
      class="shot-version-dialog"
      width="min(94vw, 880px)"
      append-to-body
      align-center
      :title="versionDialogShot ? `${t('镜头 {number}', { number: versionDialogShot.index || '' })} · ${t('历史成片库')}` : t('历史成片库')"
    >
      <div class="shot-version">
        <section v-if="dialogVideoVersions.length" class="shot-version__section">
          <header class="shot-version__title">{{ t('历史成片（{count}）', { count: dialogVideoVersions.length }) }}</header>
          <div class="shot-version__grid">
            <div
              v-for="version in dialogVideoVersions"
              :key="version.id"
              class="version-card"
              :class="{ 'is-selected': version.is_selected, 'is-stale': !version.is_selected && versionDialogShot && isVideoShotIntentStale(versionDialogShot) }"
            >
              <button
                type="button"
                class="version-card__main"
                :disabled="!versionIsSelectable(version)"
                @click="chooseVersion(version)"
              >
                <div class="version-card__thumb">
                  <img v-if="versionThumb(version)" v-lazy-src="versionThumb(version)" alt="" />
                  <div v-else class="version-card__thumb-empty"><el-icon><VideoPlay /></el-icon></div>
                  <span v-if="version.is_selected" class="version-card__badge">{{ t('当前选用') }}</span>
                  <span v-else-if="versionDialogShot && isVideoShotIntentStale(versionDialogShot)" class="version-card__badge is-muted">{{ t('基于旧分镜') }}</span>
                </div>
                <div class="version-card__meta">
                  <span class="version-card__source">{{ versionSourceLabel(version.source) }}</span>
                  <span class="version-card__time">{{ versionTimeLabel(version) }}</span>
                </div>
                <p v-if="versionPromptText(version)" class="version-card__prompt" :title="versionPromptText(version)">
                  {{ t('生成时提示词') }}：{{ versionPromptText(version) }}
                </p>
              </button>
              <a
                class="version-card__preview"
                :href="version.url"
                target="_blank"
                rel="noopener"
              >{{ t('打开视频 ↗') }}</a>
            </div>
          </div>
        </section>

        <section v-if="dialogImageVersions.length" class="shot-version__section">
          <header class="shot-version__title">{{ t('参考图版本（{count}）', { count: dialogImageVersions.length }) }}</header>
          <div class="shot-version__grid">
            <button
              v-for="version in dialogImageVersions"
              :key="version.id"
              type="button"
              class="version-card"
              :class="{ 'is-selected': version.is_selected }"
              :disabled="!versionIsSelectable(version)"
              @click="chooseVersion(version)"
            >
              <div class="version-card__thumb">
                <img v-if="versionThumb(version)" v-lazy-src="versionThumb(version)" alt="" />
                <span v-if="version.is_selected" class="version-card__badge">{{ t('当前选用') }}</span>
              </div>
              <div class="version-card__meta">
                <span class="version-card__source">{{ versionSourceLabel(version.source) }}</span>
                <span class="version-card__time">{{ versionTimeLabel(version) }}</span>
              </div>
            </button>
          </div>
        </section>

        <section v-if="orphanedVideoVersions.length" class="shot-version__section">
          <header class="shot-version__title">{{ t('未挂载历史成片（{count}）', { count: orphanedVideoVersions.length }) }}</header>
          <p class="shot-version__hint">{{ t('对应镜头已删除或无法精确匹配，仅可预览，不能选用为当前成片。') }}</p>
          <div class="shot-version__grid">
            <div
              v-for="version in orphanedVideoVersions"
              :key="`orphan-${version.id}`"
              class="version-card is-orphaned"
            >
              <div class="version-card__thumb">
                <img v-if="versionThumb(version)" v-lazy-src="versionThumb(version)" alt="" />
                <div v-else class="version-card__thumb-empty"><el-icon><VideoPlay /></el-icon></div>
                <span class="version-card__badge is-muted">{{ t('仅预览') }}</span>
              </div>
              <div class="version-card__meta">
                <span class="version-card__source">{{ versionSourceLabel(version.source) }}</span>
                <span class="version-card__time">{{ versionTimeLabel(version) }}</span>
              </div>
              <p v-if="versionPromptText(version)" class="version-card__prompt" :title="versionPromptText(version)">
                {{ t('生成时提示词') }}：{{ versionPromptText(version) }}
              </p>
              <a
                class="version-card__preview"
                :href="version.url"
                target="_blank"
                rel="noopener"
              >{{ t('打开视频 ↗') }}</a>
            </div>
          </div>
        </section>

        <div v-if="!dialogVideoVersions.length && !dialogImageVersions.length && !orphanedVideoVersions.length" class="shot-version__empty">
          {{ t('这个镜头还没有可切换的版本。') }}
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.production-board {
  flex: 1;
  min-height: 0;
  display: grid;
  grid-template-columns: clamp(276px, 18vw, 328px) minmax(0, 1fr) clamp(320px, 22vw, 400px);
  overflow: hidden;
  background: linear-gradient(180deg, rgba(var(--brand-cyan-rgb), 0.08), transparent 220px), var(--canvas);
}

.episode-rail,
.asset-panel {
  min-height: 0;
  display: flex;
  flex-direction: column;
  background: rgba(18, 26, 43, 0.92);
  backdrop-filter: blur(14px);
}

.episode-rail {
  border-right: 1px solid var(--hairline);
}

.rail-head {
  padding: 18px;
  border-bottom: 1px solid #edf2f7;
  display: flex;
  flex-direction: column;
  gap: 16px;
  background: var(--surface-card);
}

.ghost-button {
  align-self: flex-start;
  background: var(--surface-soft) !important;
  border-color: var(--hairline) !important;
  color: #314158 !important;
}

.rail-title strong,
.work-summary strong {
  display: block;
  color: var(--on-dark);
  font-weight: 950;
  line-height: 1.2;
}

.rail-title strong {
  font-size: 24px;
}

.rail-title {
  min-width: 0;
}

.rail-title span,
.work-summary span,
.work-summary em {
  display: block;
  margin-top: 5px;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.45;
  font-style: normal;
}

.rail-workflow {
  min-width: 0;
  margin-top: 13px;
  padding-top: 12px;
  border-top: 1px solid #edf2f7;
}

.rail-workflow em,
.rail-workflow b {
  display: block;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.rail-workflow em {
  color: #8a9aaf;
  font-size: 11px;
  font-style: normal;
  font-weight: 950;
}

.rail-workflow b {
  margin-top: 4px;
  color: var(--on-dark);
  font-size: 13px;
  font-weight: 950;
  line-height: 1.35;
}

.work-summary {
  margin: 14px;
  padding: 14px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: linear-gradient(180deg, var(--surface-elevated), var(--surface-card));
}

.work-summary__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  min-width: 0;
}

.work-summary__head > span {
  margin-top: 0;
}

.work-summary__edit {
  flex-shrink: 0;
  height: 26px !important;
  padding: 0 8px !important;
  color: var(--primary) !important;
  font-weight: 800 !important;
}

.work-summary strong {
  font-size: 16px;
  margin-top: 6px;
  word-break: break-word;
}

.work-summary__title-editor {
  margin-top: 8px;
  display: grid;
  gap: 8px;
}

.work-summary__title-actions {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

.work-summary__meta {
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-top: 8px;
  color: var(--muted);
  font-size: 12px;
  font-weight: 700;
  line-height: 1.4;
}

.rail-toolbar {
  height: 50px;
  padding: 0 14px;
  border-top: 1px solid #edf2f7;
  border-bottom: 1px solid #edf2f7;
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--surface-elevated);
}

.rail-toolbar > span,
.asset-panel__head span {
  color: var(--on-dark);
  font-size: 12px;
  font-weight: 950;
}

.rail-toolbar__done {
  color: var(--muted);
  font-size: 12px;
  font-style: normal;
  font-weight: 700;
}

.episode-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 8px 14px 18px;
}

.episode-item {
  position: relative;
  width: 100%;
  min-height: 92px;
  margin: 0 0 8px;
  border: 1px solid transparent;
  border-radius: 10px;
  background: transparent;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  align-items: stretch;
  overflow: hidden;
}

.episode-item::before {
  content: '';
  position: absolute;
  left: 7px;
  top: 16px;
  bottom: 16px;
  width: 3px;
  border-radius: 999px;
  background: transparent;
  pointer-events: none;
}

.episode-item:hover {
  background: var(--surface-soft);
}

.episode-item.is-active {
  border-color: rgba(var(--brand-cyan-rgb), 0.24);
  background: var(--surface-soft);
}

.episode-item.is-active::before {
  background: var(--accent-cyan);
}

.episode-item__main {
  min-width: 0;
  margin: 0;
  padding: 13px 8px 13px 18px;
  border: 0;
  background: transparent;
  text-align: left;
  cursor: pointer;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 7px;
}

.episode-item__delete {
  align-self: start;
  margin: 8px 8px 0 0;
  opacity: 0;
  transition: opacity .15s ease;
}

.episode-item:hover .episode-item__delete,
.episode-item.is-active .episode-item__delete {
  opacity: 1;
}

.episode-item__number {
  color: var(--muted);
  font-size: 11px;
  font-weight: 900;
  line-height: 1.2;
}

.episode-item strong {
  width: 100%;
  min-height: 34px;
  color: #122034;
  font-size: 13px;
  font-weight: 950;
  line-height: 1.35;
  display: -webkit-box;
  white-space: normal;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.episode-item__status {
  max-width: 100%;
  height: 20px;
  padding: 0 8px;
  border: 1px solid var(--hairline);
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  color: var(--muted);
  background: var(--surface-card);
  font-size: 10px;
  font-weight: 900;
  white-space: nowrap;
}

.episode-item__meta {
  width: 100%;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.episode-item__meta em {
  color: var(--muted);
  font-size: 10px;
  font-style: normal;
  font-weight: 900;
  white-space: nowrap;
}

.episode-item__status::before {
  content: '';
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: currentColor;
}

.episode-item__status.is-busy {
  color: var(--accent-cyan);
  border-color: rgba(var(--brand-cyan-rgb), 0.32);
  background: rgba(var(--brand-cyan-rgb), 0.1);
}

.episode-item__status.is-done {
  color: var(--accent-emerald);
  border-color: rgba(16, 185, 129, 0.25);
  background: rgba(16, 185, 129, 0.08);
}

.episode-item__status.is-warning {
  color: #d97706;
  border-color: rgba(245, 158, 11, 0.25);
  background: rgba(245, 158, 11, 0.08);
}

.episode-item__progress {
  width: 100%;
  height: 5px;
  border-radius: 999px;
  overflow: hidden;
  background: var(--surface-soft);
}

.episode-item__progress span {
  display: block;
  height: 100%;
  min-width: 4px;
  border-radius: inherit;
  background: linear-gradient(90deg, var(--accent-cyan), var(--accent-emerald));
}

.episode-item__warning {
  color: #dc2626;
  font-size: 11px;
  font-weight: 900;
}

.episode-workbench {
  min-width: 0;
  min-height: 0;
  display: flex;
  flex-direction: column;
}

.workbench-head {
  min-height: 86px;
  padding: 18px 28px;
  border-bottom: 1px solid var(--hairline);
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 18px;
  background: rgba(18, 26, 43, 0.86);
  backdrop-filter: blur(14px);
}

.workbench-head > div {
  min-width: 0;
}

.workbench-head span {
  color: var(--muted);
  font-size: 10px;
  font-weight: 950;
  letter-spacing: 0.12em;
}

.workbench-head strong {
  display: block;
  margin-top: 4px;
  max-width: 980px;
  color: var(--on-dark);
  font-size: 19px;
  font-weight: 950;
  line-height: 1.3;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.workbench-head__meta {
  display: block;
  margin-top: 6px;
  color: var(--muted);
  font-size: 12px;
  font-weight: 700;
  letter-spacing: 0;
  line-height: 1.4;
}

.run-button {
  height: 38px !important;
  min-width: 164px !important;
  padding: 0 14px !important;
  border-radius: 10px !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  gap: 6px !important;
  flex: 0 0 auto !important;
  font-size: 13px !important;
  font-weight: 900 !important;
  white-space: nowrap !important;
}

.workbench-body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  overscroll-behavior: contain;
  scrollbar-gutter: stable;
  padding: 18px clamp(16px, 1.5vw, 28px) max(48px, env(safe-area-inset-bottom));
  scroll-padding-bottom: 96px;
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.episode-summary-card {
  flex: 0 0 auto;
  min-height: 0;
  display: grid;
  grid-template-columns: minmax(190px, 32%) minmax(0, 1fr);
  align-items: stretch;
  overflow: hidden;
  border: 1px solid var(--hairline);
  border-radius: 12px;
  background: var(--surface-card);
  box-shadow: 0 8px 24px rgba(16, 32, 51, 0.045);
}

.episode-preview {
  position: relative;
  min-height: 218px;
  border-right: 1px solid #e4ebf2;
  background: linear-gradient(135deg, rgba(var(--brand-cyan-rgb), 0.08), transparent), #f2f6fa;
}

.episode-preview img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.episode-preview__empty {
  height: 100%;
  min-height: 218px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-direction: column;
  gap: 8px;
  color: var(--muted-soft);
  font-size: 12px;
  font-weight: 900;
}

.episode-preview__badge {
  position: absolute;
  top: 10px;
  left: 10px;
}

.episode-preview__copy {
  position: absolute;
  top: 10px;
  right: 10px;
  z-index: 3;
}

.image-copy-btn {
  --el-button-bg-color: var(--surface-card);
  --el-button-border-color: rgba(148, 163, 184, 0.42);
  --el-button-text-color: var(--body);
  --el-button-hover-bg-color: var(--surface-elevated);
  --el-button-hover-border-color: var(--accent-cyan);
  --el-button-hover-text-color: var(--accent-cyan);
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.14);
  backdrop-filter: blur(8px);
}

.episode-summary {
  min-width: 0;
  min-height: 0;
  padding: 18px 20px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 12px;
}

.episode-summary__copy {
  min-width: 0;
  min-height: auto;
  display: flex;
  flex-direction: column;
}

.episode-summary__copy > span {
  flex: 0 0 auto;
  margin-bottom: 6px;
  color: var(--muted);
  font-size: 11px;
  font-weight: 950;
  letter-spacing: 0.08em;
}

.episode-summary__copy h2 {
  flex: 0 0 auto;
  margin: 0 0 6px;
  color: var(--on-dark);
  font-size: 18px;
  font-weight: 950;
  line-height: 1.25;
  display: -webkit-box;
  white-space: normal;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.episode-summary__title-row {
  min-width: 0;
  display: flex;
  align-items: flex-start;
  gap: 10px;
}

.episode-summary__title-row h2 {
  flex: 1 1 auto;
  min-width: 0;
}

.episode-summary__edit {
  flex: 0 0 auto;
}

.episode-summary__copy p {
  flex: 0 1 auto;
  min-height: 0;
  margin: 0;
  color: var(--muted);
  font-size: 13px;
  line-height: 1.55;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.episode-plot-editor {
  min-width: 0;
  display: grid;
  gap: 8px;
}

.episode-plot-editor :deep(.el-textarea__inner) {
  min-height: 96px;
  color: #24364b;
  font-size: 13px;
  line-height: 1.55;
}

.episode-plot-editor__actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
}

.episode-plot-editor--node {
  width: 100%;
}

.node-plot-panel {
  display: grid;
  gap: 10px;
}

.node-plot-panel__text {
  margin: 0;
  color: var(--body);
  font-family: var(--font-mono);
  font-size: 13px;
  line-height: 1.7;
  white-space: pre-wrap;
}

.episode-summary__footer {
  min-width: 0;
  margin-top: auto;
  display: flex;
  align-items: center;
  flex-direction: row;
  justify-content: space-between;
  gap: 12px;
  padding-top: 12px;
  border-top: 1px solid #edf2f7;
}

.episode-summary__meta {
  width: auto;
  min-width: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 8px 16px;
  align-items: center;
}

.episode-summary__meta span {
  display: inline-flex;
  align-items: baseline;
  gap: 5px;
  color: var(--muted);
  font-size: 12px;
  font-weight: 800;
  white-space: nowrap;
}

.episode-summary__meta strong {
  color: var(--on-dark);
  font-size: 16px;
  font-weight: 950;
}

.episode-summary__actions {
  width: auto;
  min-width: 0;
  flex: 0 1 auto;
  display: flex;
  align-items: center;
  justify-content: flex-start;
  flex-wrap: wrap;
  gap: 10px;
}

.episode-summary__actions :deep(.el-button) {
  flex: 0 1 auto;
  min-width: 136px;
}

/* legacy class kept for older rendered content during HMR */
.summary-stat {
  min-width: 0;
  width: 112px;
  padding: 10px 12px;
  border: 1px solid #e3ebf3;
  border-radius: 10px;
  background: var(--surface-soft);
}

.summary-stat strong {
  display: block;
  color: var(--on-dark);
  font-size: 17px;
  font-weight: 950;
}

.summary-stat span {
  display: block;
  margin-top: 3px;
  color: var(--muted);
  font-size: 11px;
  font-weight: 900;
  white-space: nowrap;
}

.panel {
  flex: 0 0 auto;
  min-width: 0;
  max-width: 100%;
  padding: 16px;
  border: 1px solid var(--hairline);
  border-radius: 12px;
  background: var(--surface-card);
  box-shadow: 0 8px 24px rgba(16, 32, 51, 0.035);
}

.panel-head {
  min-height: 32px;
  padding: 0 2px 10px;
  margin-bottom: 12px;
  border-bottom: 1px solid #edf2f7;
  display: flex;
  justify-content: space-between;
  gap: 12px;
}

.panel-head strong {
  color: var(--on-dark);
  font-size: 15px;
  font-weight: 950;
}

.panel-head span {
  color: var(--muted);
  font-size: 12px;
}

.stage-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.stage-card {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(132px, 156px);
  gap: 14px;
  align-items: start;
  min-width: 0;
  max-width: 100%;
  padding: 12px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-soft);
  transition: border-color var(--duration-fast) var(--ease-out), box-shadow var(--duration-fast) var(--ease-out), background var(--duration-fast) var(--ease-out);
}

.stage-card:hover {
  border-color: var(--hairline-strong);
  background: var(--surface-card);
}

.stage-card.is-failed {
  border-color: rgba(239, 68, 68, 0.22);
  background: rgba(251, 113, 133, 0.1);
}

.stage-card.is-running {
  border-color: rgba(var(--brand-cyan-rgb), 0.28);
  background: var(--surface-soft);
}

.stage-card.kind-input {
  border-left: 4px solid #64748b;
}

.stage-card.kind-text {
  border-left: 4px solid #2563eb;
}

.stage-card.kind-image {
  border-left: 4px solid #f59e0b;
}

.stage-card.kind-video {
  border-left: 4px solid #8b5cf6;
}

.stage-card.kind-output {
  border-left: 4px solid #10b981;
}

.stage-card.kind-voice {
  border-left: 4px solid #06b6d4;
}

.stage-card.is-expanded {
  border-color: rgba(var(--brand-cyan-rgb), 0.32);
  background: var(--surface-card);
  box-shadow: 0 10px 26px rgba(16, 32, 51, 0.07);
}

.stage-card.is-expanded .stage-index .el-icon {
  transform: rotate(90deg);
}

.stage-main {
  min-width: 0;
  border: 1px solid var(--hairline);
  border-radius: 9px;
  padding: 12px 14px 12px 12px;
  background: var(--surface-card);
  text-align: left;
  cursor: pointer;
  display: grid;
  grid-template-columns: 34px minmax(0, 1fr);
  gap: 12px;
  transition: border-color var(--duration-fast) var(--ease-out), background var(--duration-fast) var(--ease-out);
}

.stage-main:hover {
  border-color: rgba(var(--brand-cyan-rgb), 0.38);
  background: var(--surface-elevated);
}

.stage-index {
  width: 30px;
  height: 30px;
  border: 1px solid #d9e4ef;
  border-radius: 8px;
  display: grid;
  place-items: center;
  color: #728197;
  background: var(--surface-soft);
}

.stage-index .el-icon {
  transition: transform var(--duration-fast) var(--ease-out);
}

.stage-copy {
  min-width: 0;
}

.stage-title {
  min-width: 0;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto auto;
  align-items: baseline;
  gap: 8px;
}

.stage-title strong {
  min-width: 0;
  color: var(--on-dark);
  font-size: 14px;
  font-weight: 950;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.stage-kind {
  min-height: 20px;
  padding: 0 7px;
  border-radius: 999px;
  display: inline-flex !important;
  align-items: center;
  justify-content: center;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.2;
  font-weight: 900;
  white-space: nowrap;
}

.stage-kind--input {
  color: var(--muted);
  background: rgba(100, 116, 139, 0.1);
}

.stage-kind--text {
  color: #1d4ed8;
  background: rgba(37, 99, 235, 0.1);
}

.stage-kind--image {
  color: #b45309;
  background: rgba(245, 158, 11, 0.13);
}

.stage-kind--video {
  color: #6d28d9;
  background: rgba(139, 92, 246, 0.12);
}

.stage-kind--output {
  color: #047857;
  background: rgba(16, 185, 129, 0.12);
}

.stage-kind--voice {
  color: #0e7490;
  background: rgba(6, 182, 212, 0.12);
}

.stage-kind--other {
  color: var(--muted);
  background: rgba(100, 116, 139, 0.1);
}

.stage-title em {
  color: #a0aec0;
  font-size: 11px;
  font-style: normal;
  font-weight: 900;
  white-space: nowrap;
}

.stage-copy p {
  margin: 8px 0 0;
  color: #506177;
  font-size: 12px;
  line-height: 1.55;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.stage-expand-hint {
  display: inline-flex;
  margin-top: 8px;
  color: var(--accent-cyan);
  font-size: 11px;
  font-weight: 900;
}

.stage-error {
  margin-top: 8px !important;
  padding: 8px 10px;
  border: 1px solid rgba(239, 68, 68, 0.18);
  border-radius: 8px;
  background: rgba(239, 68, 68, 0.07);
  color: #b91c1c !important;
  -webkit-line-clamp: 2 !important;
}

.stage-actions {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  justify-content: space-between;
  gap: 10px;
  min-width: 0;
  min-height: 92px;
}

.stage-actions :deep(.status-badge) {
  max-width: 100%;
}

.stage-actions :deep(.el-button) {
  width: auto;
  min-width: 118px;
  height: 34px;
  margin: 0;
  padding: 0 12px;
  border-radius: 9px;
  font-weight: 900;
  white-space: nowrap;
}

.stage-expanded {
  grid-column: 1 / -1;
  display: grid;
  gap: 12px;
  min-width: 0;
  max-width: 100%;
  margin-top: 0;
  padding: 14px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: linear-gradient(180deg, var(--surface-elevated), var(--surface-card));
  overflow: hidden;
}

.node-output__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 8px;
}

.node-output__head span {
  color: var(--on-dark);
  font-size: 12px;
  font-weight: 950;
}

.node-output__head em {
  color: var(--muted);
  font-size: 11px;
  font-style: normal;
}

.node-output pre {
  max-height: 260px;
  margin: 0;
  overflow: auto;
  padding: 12px;
  border: 1px solid var(--hairline);
  border-radius: 8px;
  background: var(--surface-card);
  color: var(--on-dark);
  font-size: 12px;
  line-height: 1.6;
  white-space: pre-wrap;
  font-family: var(--font-mono);
}

.node-generating {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 18px;
  border: 1px solid #bfdbfe;
  border-radius: 12px;
  background: linear-gradient(135deg, rgba(var(--primary-rgb), 0.14), rgba(var(--brand-cyan-rgb), 0.08));
  color: #1e3a8a;
}

.node-generating__spinner {
  width: 20px;
  height: 20px;
  flex: 0 0 auto;
  border: 3px solid rgba(37, 99, 235, 0.18);
  border-top-color: #2563eb;
  border-radius: 50%;
  animation: node-generating-spin 0.85s linear infinite;
}

.node-generating strong {
  display: block;
  margin-bottom: 3px;
  color: var(--on-dark);
  font-size: 13px;
  font-weight: 950;
}

.node-generating p {
  margin: 0;
  color: var(--muted);
  font-size: 12px;
  font-weight: 850;
}

@keyframes node-generating-spin {
  to {
    transform: rotate(360deg);
  }
}

.storyboard-card-board {
  max-height: min(72vh, 820px);
  overflow-y: auto;
  padding-right: 6px;
  display: grid;
  gap: 12px;
  scrollbar-gutter: stable;
}

.storyboard-card {
  display: grid;
  gap: 10px;
  padding: 14px;
  border: 1px solid var(--hairline);
  border-radius: 14px;
  background:
    linear-gradient(135deg, rgba(14, 165, 233, 0.06), transparent 38%),
    var(--surface-card);
  box-shadow: 0 10px 26px rgba(15, 23, 42, 0.05);
}

.storyboard-card.is-editing {
  border-color: rgba(14, 165, 233, 0.38);
  box-shadow: 0 16px 34px rgba(14, 165, 233, 0.12);
}

.storyboard-card > :deep(.asset-linked-text) {
  max-height: min(48vh, 520px);
  overflow: auto;
  padding: 12px;
  border: 1px solid #edf3f8;
  border-radius: 10px;
  background: var(--surface-elevated);
  color: var(--on-dark);
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 1.65;
  scrollbar-gutter: stable;
}

.storyboard-card__head,
.storyboard-card__actions,
.storyboard-card__refs {
  display: flex;
  align-items: center;
  gap: 8px;
}

.storyboard-card__head {
  justify-content: space-between;
  align-items: flex-start;
}

.storyboard-card__title {
  min-width: 0;
  display: grid;
  gap: 3px;
}

.storyboard-card__title span {
  width: fit-content;
  padding: 2px 8px;
  border-radius: 999px;
  background: rgba(34, 211, 238, 0.1);
  color: #0369a1;
  font-size: 11px;
  font-weight: 950;
}

.storyboard-card__title strong {
  min-width: 0;
  color: var(--on-dark);
  font-size: 14px;
  font-weight: 950;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.storyboard-card__actions {
  flex: 0 0 auto;
  justify-content: flex-end;
  flex-wrap: wrap;
  max-width: min(100%, 360px);
}

.storyboard-card__asset-count {
  padding: 3px 8px;
  border-radius: 999px;
  background: var(--surface-soft);
  color: var(--muted);
  font-size: 11px;
  font-weight: 900;
}

.storyboard-card__refs {
  flex-wrap: wrap;
}

.storyboard-ref-chip {
  padding: 3px 8px;
  border: 0;
  border-radius: 999px;
  background: var(--surface-soft);
  color: var(--muted);
  font-size: 11px;
  font-weight: 900;
  cursor: zoom-in;
}

.storyboard-ref-chip--character {
  color: #1d4ed8;
  background: rgba(37, 99, 235, 0.1);
}

.storyboard-ref-chip--scene {
  color: #047857;
  background: rgba(16, 185, 129, 0.12);
}

.storyboard-ref-chip--prop {
  color: #b45309;
  background: rgba(245, 158, 11, 0.13);
}

.storyboard-ref-chip--look {
  color: #7c2d12;
  background: rgba(251, 146, 60, 0.18);
}

.storyboard-card__editor {
  display: grid;
  gap: 10px;
}

.storyboard-card__live-refs {
  display: grid;
  gap: 7px;
  padding: 10px;
  border: 1px dashed #cbdced;
  border-radius: 10px;
  background: var(--surface-soft);
}

.storyboard-card__live-refs > span {
  color: var(--muted);
  font-size: 12px;
  font-weight: 950;
}

.storyboard-card__live-refs > em {
  color: var(--muted);
  font-size: 11px;
  font-style: normal;
  font-weight: 800;
}

.storyboard-card__editor label {
  display: grid;
  gap: 6px;
}

.storyboard-card__editor label > span {
  color: var(--muted);
  font-size: 12px;
  font-weight: 950;
}

.storyboard-card__title-input,
.storyboard-card__textarea {
  width: 100%;
  box-sizing: border-box;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-elevated);
  color: var(--on-dark);
  outline: none;
  transition: border-color 0.16s ease, box-shadow 0.16s ease, background 0.16s ease;
}

.storyboard-card__title-input {
  height: 36px;
  padding: 0 11px;
  font-size: 13px;
  font-weight: 850;
}

.storyboard-card__textarea-wrap {
  position: relative;
  min-height: 190px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-elevated);
  transition: border-color 0.16s ease, box-shadow 0.16s ease, background 0.16s ease;
}

.storyboard-card__textarea-wrap:focus-within {
  border-color: rgba(14, 165, 233, 0.66);
  background: var(--surface-card);
  box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.12);
}

.storyboard-card__textarea-highlight {
  position: absolute;
  inset: 0;
  z-index: 1;
  box-sizing: border-box;
  min-height: 190px;
  margin: 0;
  overflow: hidden;
  padding: 12px;
  border: 0;
  border-radius: 10px;
  background: transparent;
  color: var(--on-dark);
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 1.65;
  pointer-events: none;
  white-space: pre-wrap;
  word-break: break-word;
}

.storyboard-inline-ref {
  display: inline;
  border-radius: 6px;
  font: inherit;
}

.storyboard-inline-ref--character {
  color: #1d4ed8;
  background: rgba(37, 99, 235, 0.1);
}

.storyboard-inline-ref--scene {
  color: #047857;
  background: rgba(16, 185, 129, 0.12);
}

.storyboard-inline-ref--prop {
  color: #b45309;
  background: rgba(245, 158, 11, 0.13);
}

.storyboard-inline-ref--look {
  color: #7c2d12;
  background: rgba(251, 146, 60, 0.18);
}

.storyboard-card__textarea {
  position: relative;
  z-index: 2;
  min-height: 190px;
  padding: 12px;
  border: 0;
  background: transparent;
  color: transparent;
  caret-color: var(--on-dark);
  resize: vertical;
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 1.65;
  -webkit-text-fill-color: transparent;
}

.storyboard-card__title-input:focus,
.storyboard-card__textarea:focus {
  border-color: rgba(14, 165, 233, 0.66);
  box-shadow: 0 0 0 3px rgba(14, 165, 233, 0.12);
}

.storyboard-card__textarea:focus {
  box-shadow: none;
}

.storyboard-card__textarea::placeholder {
  color: var(--muted);
  -webkit-text-fill-color: var(--muted);
}

.storyboard-card__textarea::selection {
  background: rgba(14, 165, 233, 0.2);
}

.storyboard-card__text-field {
  position: relative;
}

.storyboard-mention-menu {
  position: relative;
  z-index: 8;
  max-height: 220px;
  overflow: auto;
  margin-top: 8px;
  padding: 8px;
  border: 1px solid var(--hairline);
  border-radius: 12px;
  background: rgba(18, 26, 43, 0.96);
  box-shadow: 0 12px 28px rgba(15, 23, 42, 0.12);
}

.storyboard-mention-option {
  width: 100%;
  display: grid;
  grid-template-columns: 38px minmax(0, 1fr);
  align-items: center;
  gap: 9px;
  padding: 7px;
  border: 0;
  border-radius: 10px;
  background: transparent;
  color: var(--on-dark);
  cursor: pointer;
  text-align: left;
}

.storyboard-mention-option:hover {
  background: var(--surface-soft);
}

.storyboard-mention-option__thumb {
  width: 38px;
  height: 38px;
  display: grid;
  place-items: center;
  border-radius: 10px;
  overflow: hidden;
  background: var(--surface-soft);
  color: #0369a1;
  font-size: 14px;
  font-weight: 950;
}

.storyboard-mention-option__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.storyboard-mention-option strong,
.storyboard-mention-option em {
  display: block;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.storyboard-mention-option strong {
  font-size: 12px;
  font-weight: 950;
}

.storyboard-mention-option em {
  margin-top: 2px;
  color: var(--muted);
  font-size: 11px;
  font-style: normal;
  font-weight: 800;
}

.storyboard-card__edit-hint {
  margin: 0;
  color: var(--muted);
  font-size: 11px;
  font-weight: 800;
}

.empty-output {
  padding: 18px;
  border: 1px dashed #cfd9e5;
  border-radius: 10px;
  background: var(--surface-soft);
  color: var(--muted);
  font-size: 12px;
  text-align: center;
}

.asset-output-panel {
  display: grid;
  gap: 10px;
}

.asset-output-panel--single {
  padding: 14px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-elevated);
}

.asset-output-panel__head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 12px;
  padding-bottom: 10px;
  border-bottom: 1px solid #e6eef7;
}

.asset-output-panel__head div {
  min-width: 0;
  display: grid;
  gap: 3px;
}

.asset-output-panel__head strong {
  color: var(--on-dark);
  font-size: 14px;
  font-weight: 950;
}

.asset-output-panel__head span {
  color: var(--muted);
  font-size: 12px;
}

.asset-output-panel__head em {
  max-width: 360px;
  color: #7f8ea3;
  font-size: 11px;
  font-style: normal;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.asset-output-summary {
  margin: -2px 0 2px;
  color: var(--muted);
  font-size: 12px;
  font-weight: 850;
  line-height: 1.5;
}

.asset-output-stats {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
  gap: 10px;
}

.asset-output-stat {
  display: grid;
  grid-template-columns: 1fr auto auto;
  align-items: end;
  gap: 6px;
  padding: 12px 14px;
  border: 1px solid var(--hairline);
  border-radius: 12px;
  background: var(--surface-soft);
}

.asset-output-stat span {
  grid-column: 1 / -1;
  color: var(--muted);
  font-size: 12px;
  font-weight: 850;
}

.asset-output-stat strong {
  color: var(--on-dark);
  font-size: 28px;
  line-height: 1;
  font-weight: 950;
}

.asset-output-stat em {
  color: var(--muted);
  font-size: 12px;
  font-style: normal;
  font-weight: 850;
  padding-bottom: 4px;
}

.asset-output-stat--character {
  background: linear-gradient(180deg, #eff6ff 0%, #f8fbff 100%);
  border-color: #dbeafe;
}

.asset-output-stat--scene {
  background: linear-gradient(180deg, #ecfdf5 0%, #f7fffb 100%);
  border-color: #d1fae5;
}

.asset-output-stat--prop {
  background: linear-gradient(180deg, #fff7ed 0%, #fffbf5 100%);
  border-color: #ffedd5;
}

.asset-output-stat--look {
  background: linear-gradient(180deg, #f5f3ff 0%, #faf9ff 100%);
  border-color: #ede9fe;
}

.asset-output-stat--core_image,
.asset-output-stat--look_image {
  background: linear-gradient(180deg, #f8fafc 0%, #ffffff 100%);
  border-color: #e2e8f0;
}

.asset-output-name-list {
  display: grid;
  gap: 12px;
}

.asset-output-name-group {
  display: grid;
  gap: 8px;
}

.asset-output-name-group strong {
  color: var(--on-dark);
  font-size: 12px;
  font-weight: 950;
}

.asset-output-name-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.asset-output-name-chip {
  appearance: none;
  border: 1px solid var(--hairline);
  border-radius: 999px;
  background: var(--surface-elevated);
  color: var(--on-dark);
  padding: 6px 12px;
  font-size: 12px;
  font-weight: 850;
  cursor: pointer;
  transition: border-color .15s ease, background .15s ease, transform .15s ease;
}

.asset-output-name-chip:hover {
  border-color: #93c5fd;
  background: #eff6ff;
  transform: translateY(-1px);
}

.asset-output-name-chip--static {
  cursor: default;
  background: var(--surface-soft);
}

.asset-output-name-chip--static:hover {
  border-color: var(--hairline);
  background: var(--surface-soft);
  transform: none;
}

.asset-output-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 10px;
}

.asset-output-card {
  min-width: 0;
  padding: 12px 12px 10px;
  border: 1px solid var(--hairline);
  border-top: 3px solid #94a3b8;
  border-radius: 10px;
  background: var(--surface-card);
  color: inherit;
  cursor: pointer;
  text-align: left;
  transition: transform 0.16s ease, border-color 0.16s ease, box-shadow 0.16s ease;
}

.asset-output-card:hover {
  border-color: #bfd2e8;
  box-shadow: 0 10px 24px rgba(16, 32, 51, 0.08);
  transform: translateY(-1px);
}

.asset-output-card--character {
  border-top-color: #2563eb;
}

.asset-output-card--scene {
  border-top-color: #10b981;
}

.asset-output-card--prop {
  border-top-color: #f59e0b;
}

.asset-output-card__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 8px;
}

.asset-output-card__head strong {
  min-width: 0;
  overflow: hidden;
  color: var(--on-dark);
  font-size: 13px;
  font-weight: 950;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.asset-output-card__head span {
  flex: 0 0 auto;
  padding: 2px 7px;
  border-radius: 999px;
  background: var(--surface-soft);
  color: var(--muted);
  font-size: 11px;
  font-weight: 900;
}

.asset-output-card p {
  min-height: 54px;
  margin: 0;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.55;
}

.asset-output-card em {
  display: block;
  margin-top: 8px;
  color: var(--muted);
  font-size: 11px;
  font-style: normal;
  font-weight: 850;
}

.asset-output-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 5px;
  margin-top: 8px;
}

.asset-output-tags span {
  padding: 2px 6px;
  border-radius: 7px;
  background: var(--surface-soft);
  color: var(--muted);
  font-size: 10px;
  font-weight: 800;
}

.media-wall {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
  gap: 10px;
}

.media-wall--video {
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
}

.media-image-card,
.video-card {
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-card);
  overflow: hidden;
}

.media-image-card {
  position: relative;
}

.media-image-card img {
  width: 100%;
  aspect-ratio: 16 / 10;
  object-fit: cover;
  display: block;
}

.media-image-card__copy,
.video-card__copy {
  position: absolute;
  top: 10px;
  right: 10px;
  z-index: 4;
}

.video-card__copy {
  width: 28px;
  height: 28px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border: 1px solid rgba(148, 163, 184, 0.42);
  border-radius: 50%;
  cursor: pointer;
}

.video-card video,
.video-card__poster {
  width: 100%;
  aspect-ratio: 16 / 9;
  display: block;
  background: #0f172a;
}

.video-card video {
  object-fit: contain;
}

.video-card__poster {
  border: 0;
  padding: 0;
  cursor: pointer;
  position: relative;
  overflow: hidden;
  color: var(--accent-cyan);
}

.video-card__poster img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.video-card__poster .el-icon,
.video-card__poster span {
  position: absolute;
  z-index: 1;
}

.video-card__poster .el-icon {
  inset: 0;
  margin: auto;
  width: 42px;
  height: 42px;
  border-radius: 50%;
  display: grid;
  place-items: center;
  background: rgba(18, 26, 43, 0.9);
}

.video-card__poster .video-card__copy .el-icon {
  position: static;
  inset: auto;
  width: auto;
  height: auto;
  margin: 0;
  border-radius: 0;
  display: inline-flex;
  background: transparent;
}

.video-card__poster span {
  left: 50%;
  bottom: 10px;
  transform: translateX(-50%);
  padding: 4px 9px;
  border-radius: 999px;
  background: rgba(18, 26, 43, 0.9);
  color: var(--on-dark);
  font-size: 11px;
  font-weight: 950;
  white-space: nowrap;
}

.video-card__poster .video-card__copy {
  left: auto;
  top: 10px;
  right: 10px;
  bottom: auto;
  transform: none;
  padding: 0;
  background: rgba(18, 26, 43, 0.92);
}

.final-video-output {
  min-width: 0;
  max-width: min(920px, 100%);
}

.final-video-card {
  min-width: 0;
  max-width: 100%;
  border: 1px solid #dbe7f3;
  border-radius: 10px;
  background: var(--surface-card);
  overflow: hidden;
}

.final-video-card__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px 14px;
  padding: 12px 14px;
  border-bottom: 1px solid #e6eef7;
}

.final-video-card__head > div:first-child {
  min-width: 0;
  flex: 1 1 160px;
}

.final-video-card__head strong {
  display: block;
  color: var(--on-dark);
  font-size: 14px;
  font-weight: 950;
}

.final-video-card__head span {
  display: block;
  margin-top: 3px;
  color: var(--muted);
  font-size: 12px;
  font-weight: 700;
  overflow-wrap: anywhere;
}

.final-video-card__actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  flex: 0 1 auto;
  min-width: 0;
}

.final-video-card video,
.final-video-card__poster {
  width: 100%;
  aspect-ratio: 16 / 9;
  display: block;
  background: #0f172a;
}

.final-video-card video {
  width: 100%;
  max-width: 100%;
  height: auto;
  max-height: min(72vh, 720px);
  aspect-ratio: auto;
  display: block;
  margin: 0 auto;
  object-fit: contain;
}

.final-video-card__poster {
  position: relative;
  border: 0;
  cursor: pointer;
  color: var(--accent-cyan);
}

.final-video-card__poster .el-icon {
  position: absolute;
  inset: 0;
  width: 52px;
  height: 52px;
  margin: auto;
  display: grid;
  place-items: center;
  border-radius: 50%;
  background: rgba(18, 26, 43, 0.92);
}

.final-video-card__poster span {
  position: absolute;
  left: 50%;
  bottom: 18px;
  transform: translateX(-50%);
  padding: 5px 11px;
  border-radius: 999px;
  background: rgba(18, 26, 43, 0.92);
  color: var(--on-dark);
  font-size: 12px;
  font-weight: 950;
  white-space: nowrap;
}

.video-production-panel {
  display: grid;
  gap: 12px;
  min-width: 0;
  max-width: 100%;
  overflow: hidden;
}

.video-production-panel__head {
  padding: 12px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-card);
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px 12px;
  min-width: 0;
}

.video-production-panel__head > div:first-child {
  min-width: 0;
  flex: 1 1 180px;
}

.video-production-panel__head strong {
  display: block;
  color: var(--on-dark);
  font-size: 13px;
  font-weight: 950;
}

.video-production-panel__head span {
  display: block;
  margin-top: 2px;
  color: var(--muted);
  font-size: 12px;
  overflow-wrap: anywhere;
}

.video-production-panel__head :deep(.el-button) {
  flex: 0 0 auto;
  min-width: 92px;
  height: 32px;
  border-radius: 8px;
  font-weight: 900;
}

.video-shot-board {
  display: grid;
  grid-template-columns: minmax(0, 212px) minmax(0, 1fr);
  gap: 12px;
  align-items: start;
  min-width: 0;
  max-width: 100%;
}

.video-shot-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
  max-height: 560px;
  overflow-x: hidden;
  overflow-y: auto;
  padding-right: 2px;
}

.video-shot-list__item {
  display: flex;
  align-items: center;
  gap: 9px;
  min-width: 0;
  max-width: 100%;
  padding: 6px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-card);
  cursor: pointer;
  text-align: left;
  transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
  min-height: 54px;
}

.video-shot-list__item:hover {
  border-color: #c7d6e6;
}

.video-shot-list__item.is-active {
  border-color: var(--accent-cyan);
  background: rgba(var(--brand-cyan-rgb), 0.07);
  box-shadow: 0 4px 14px rgba(16, 32, 51, 0.08);
}

.video-shot-list__thumb {
  position: relative;
  flex: 0 0 auto;
  width: 56px;
  height: 38px;
  border-radius: 7px;
  overflow: hidden;
  background: var(--surface-soft);
  display: grid;
  place-items: center;
  color: var(--muted-soft);
}

.video-shot-list__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.video-shot-list__status {
  position: absolute;
  left: 0;
  bottom: 0;
  right: 0;
  padding: 1px 0;
  text-align: center;
  background: rgba(16, 185, 129, 0.92);
  color: #fff;
  font-size: 9px;
  font-style: normal;
  font-weight: 950;
}

.video-shot-list__status.is-busy {
  background: rgba(20, 184, 166, 0.94);
}

.video-shot-list__status.is-failed {
  background: rgba(239, 68, 68, 0.94);
}

.video-shot-list__status.is-stale {
  background: rgba(245, 158, 11, 0.94);
}

.video-shot-list__versions {
  position: absolute;
  top: 2px;
  right: 2px;
  padding: 0 5px;
  border-radius: 6px;
  background: rgba(37, 99, 235, 0.96);
  color: #fff;
  font-size: 9px;
  font-style: normal;
  font-weight: 950;
  line-height: 15px;
  letter-spacing: 0;
}

.video-shot-detail__versions {
  margin-left: auto;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.video-shot-list__meta {
  min-width: 0;
  flex: 1 1 auto;
  display: grid;
  gap: 2px;
}

.video-shot-list__title-row {
  min-width: 0;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}

.video-shot-list__meta strong {
  min-width: 0;
  color: var(--on-dark);
  font-size: 12px;
  font-weight: 950;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.video-shot-list__meta em {
  color: var(--muted);
  font-size: 11px;
  font-style: normal;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.video-shot-list__meta-action {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  gap: 3px;
  min-width: 30px;
  height: 20px;
  padding: 0 7px;
  border-radius: 999px;
  background: rgba(59, 130, 246, 0.12);
  color: #2563eb;
  font-size: 10px;
  font-style: normal;
  font-weight: 900;
  line-height: 20px;
  cursor: pointer;
  transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease;
}

.video-shot-list__meta-action .el-icon {
  width: 12px;
  height: 12px;
}

.video-shot-list__meta-action:hover,
.video-shot-list__meta-action:focus-visible {
  background: rgba(59, 130, 246, 0.18);
  color: #1d4ed8;
  transform: translateY(-1px);
  outline: none;
}

.video-shot-list__meta-action.is-danger {
  background: rgba(220, 38, 38, 0.12);
  color: #dc2626;
}

.video-shot-list__meta-action.is-danger:hover,
.video-shot-list__meta-action.is-danger:focus-visible {
  background: rgba(220, 38, 38, 0.18);
  color: #b91c1c;
}

.video-shot-detail {
  min-width: 0;
  width: 100%;
  max-width: 100%;
  border: 1px solid var(--hairline);
  border-radius: 12px;
  background: var(--surface-card);
  padding: 12px;
  display: grid;
  gap: 12px;
  justify-self: stretch;
  box-shadow: 0 6px 18px rgba(16, 32, 51, 0.04);
  overflow: hidden;
}

.video-shot-detail__head {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px 10px;
  min-width: 0;
}

.video-shot-detail__head strong {
  min-width: 0;
  color: var(--on-dark);
  font-size: 14px;
  font-weight: 950;
}

.video-shot-detail__status {
  padding: 2px 10px;
  border-radius: 999px;
  background: rgba(16, 185, 129, 0.92);
  color: #fff;
  font-size: 11px;
  font-weight: 950;
}

.video-shot-detail__status.is-busy {
  background: rgba(20, 184, 166, 0.94);
}

.video-shot-detail__status.is-failed {
  background: rgba(239, 68, 68, 0.94);
}

.video-shot-detail__status.is-stale {
  background: rgba(245, 158, 11, 0.94);
}

.video-shot-version-switch {
  margin-left: auto;
  display: inline-flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  min-width: 0;
  max-width: 100%;
}

.video-shot-version-switch :deep(.el-button) {
  margin-left: 0;
  flex: 0 1 auto;
}

.video-shot-version-switch__current {
  min-width: 0;
  max-width: 100%;
  min-height: 28px;
  padding: 0 10px;
  border: 1px solid rgba(37, 99, 235, 0.22);
  border-radius: 7px;
  background: rgba(37, 99, 235, 0.08);
  color: #1d4ed8;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  font-weight: 900;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.video-shot-version-switch__current:hover,
.video-shot-version-switch__current:focus-visible {
  border-color: rgba(37, 99, 235, 0.42);
  background: rgba(37, 99, 235, 0.14);
  outline: none;
}

.video-shot-detail__body {
  display: grid;
  grid-template-columns: minmax(0, 1.45fr) minmax(280px, 0.9fr);
  align-items: start;
  gap: 14px;
  min-width: 0;
  max-width: 100%;
}

.video-shot-detail__media {
  min-width: 0;
  max-width: 100%;
}

.video-shot-detail__player {
  min-width: 0;
  width: 100%;
  max-width: 100%;
  border-radius: 10px;
  overflow: hidden;
  background: #0f172a;
  display: flex;
  align-items: center;
  justify-content: center;
}

.video-shot-detail__player.is-active {
  width: 100%;
  max-width: 100%;
}

.video-shot-detail__player button,
.video-shot-detail__player .video-shot-detail__placeholder {
  width: 100%;
  aspect-ratio: 16 / 9;
  border: 0;
  display: grid;
  place-items: center;
  gap: 6px;
  background: #0f172a;
  color: var(--accent-cyan);
  font-weight: 900;
}

.video-shot-detail__player video {
  width: 100%;
  max-width: 100%;
  height: auto;
  max-height: min(58vh, 560px);
  aspect-ratio: auto;
  display: block;
  object-fit: contain;
  background: #0f172a;
}

.video-shot-detail__player button {
  cursor: pointer;
}

.video-shot-detail__player .el-icon {
  font-size: 30px;
}

.video-shot-detail__placeholder em {
  color: var(--muted);
  font-style: normal;
  font-size: 12px;
}

.video-shot-detail__side {
  min-width: 0;
  display: grid;
  gap: 12px;
  align-content: start;
}

.video-shot-detail__intent-banner {
  margin: 0;
  padding: 8px 10px;
  border-radius: 8px;
  border: 1px solid rgba(251, 191, 36, 0.35);
  background: rgba(251, 191, 36, 0.12);
  color: #fbbf24;
  font-size: 12px;
  line-height: 1.5;
}

.video-shot-detail__section-title {
  margin: 0 0 8px;
  color: var(--body-strong);
  font-size: 12px;
  font-weight: 800;
  letter-spacing: 0.02em;
}

.video-shot-detail__intent {
  min-width: 0;
  padding: 10px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-soft);
}

.video-shot-detail__desc {
  margin: 0;
  min-width: 0;
  max-height: 7.2em;
  overflow: auto;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.5;
  overflow-wrap: anywhere;
}

.video-shot-detail__empty {
  margin: 0;
  color: var(--muted-soft);
  font-size: 12px;
}

.video-shot-detail__prompt {
  display: grid;
  gap: 8px;
  min-width: 0;
  max-width: 100%;
}

.video-shot-detail__prompt label {
  color: var(--muted);
  font-size: 12px;
  font-weight: 900;
}

.video-shot-detail__prompt :deep(.el-textarea),
.video-shot-detail__prompt :deep(.el-textarea__inner) {
  max-width: 100%;
  box-sizing: border-box;
}

.video-shot-detail__actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  min-width: 0;
}

.video-shot-detail__actions :deep(.el-button) {
  height: 32px;
  max-width: 100%;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 900;
}

.video-shot-detail__hint {
  margin: 0;
  min-width: 0;
  color: #c0813a;
  font-size: 12px;
  font-weight: 850;
  overflow-wrap: anywhere;
}

@media (max-width: 1480px) {
  .video-shot-board {
    grid-template-columns: minmax(0, 180px) minmax(0, 1fr);
  }

  .video-shot-detail__body {
    grid-template-columns: minmax(0, 1.2fr) minmax(240px, 0.95fr);
    gap: 12px;
  }
}

@media (max-width: 1280px) {
  .video-shot-board {
    grid-template-columns: minmax(0, 1fr);
  }

  .video-shot-list {
    flex-direction: row;
    max-height: none;
    overflow-x: auto;
    overflow-y: hidden;
    padding-bottom: 4px;
    -webkit-overflow-scrolling: touch;
  }

  .video-shot-list__item {
    flex: 0 0 auto;
    width: min(220px, 78vw);
    max-width: min(220px, 78vw);
  }

  .video-shot-detail {
    padding: 10px;
  }

  .video-shot-detail__body {
    grid-template-columns: minmax(0, 1fr);
  }

  .video-shot-version-switch {
    margin-left: 0;
    width: 100%;
  }
}

@media (max-width: 720px) {
  .video-production-panel__head {
    align-items: stretch;
  }

  .video-production-panel__head :deep(.el-button) {
    width: 100%;
  }

  .video-shot-list__item {
    width: min(200px, 84vw);
    max-width: min(200px, 84vw);
  }

  .video-shot-detail__actions :deep(.el-button) {
    flex: 1 1 140px;
  }

  .final-video-card__head {
    align-items: stretch;
  }

  .final-video-card__actions {
    width: 100%;
  }

  .final-video-card__actions :deep(.el-button) {
    flex: 1 1 0;
  }

  .stage-expanded {
    padding: 10px;
  }
}

.video-card em {
  display: block;
  color: var(--muted);
  font-size: 11px;
  font-style: normal;
  font-weight: 850;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.video-card em {
  padding: 8px 10px;
}

.asset-panel {
  border-left: 1px solid var(--hairline);
}

.asset-panel__head {
  min-height: 70px;
  padding: 10px 12px 10px 18px;
  border-bottom: 1px solid var(--hairline);
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--surface-card);
}

.asset-panel__actions {
  margin-left: auto;
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

.asset-panel__batch {
  flex-shrink: 0;
}

.asset-panel__head .el-icon {
  color: var(--accent-cyan);
}

.asset-panel__body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 14px;
}

.asset-group {
  margin-bottom: 18px;
}

.asset-group__title {
  margin-bottom: 10px;
  padding: 8px 10px;
  border-radius: 9px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  color: var(--on-dark);
  font-size: 12px;
  font-weight: 950;
}

.asset-group__title--character {
  background: rgba(37, 99, 235, 0.08);
  color: #1d4ed8;
}

.asset-group__title--scene {
  background: rgba(16, 185, 129, 0.09);
  color: #047857;
}

.asset-group__title--prop {
  background: rgba(245, 158, 11, 0.12);
  color: #92400e;
}

.asset-group__title em {
  color: var(--muted);
  font-style: normal;
}

.asset-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px;
}

.asset-card {
  position: relative;
  min-width: 0;
  border: 0;
  padding: 0;
  background: transparent;
  text-align: left;
  cursor: default;
}

.asset-card.is-clickable {
  cursor: zoom-in;
}

.asset-card.is-empty {
  opacity: 0.72;
}

.asset-card::after {
  content: '';
  position: absolute;
  inset: 0 auto 20px 0;
  width: 2px;
  border-radius: 999px;
  pointer-events: none;
  background: transparent;
}

.asset-card--character::after {
  background: rgba(37, 99, 235, 0.58);
}

.asset-card--scene::after {
  background: rgba(16, 185, 129, 0.58);
}

.asset-card--prop::after {
  background: rgba(245, 158, 11, 0.66);
}

.asset-card__thumb {
  position: relative;
  aspect-ratio: 1 / 1;
  overflow: hidden;
  border: 1px solid var(--hairline);
  border-radius: 8px;
  background: var(--surface-soft);
  box-shadow: 0 4px 12px rgba(16, 32, 51, 0.04);
}

.asset-card__thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
  transition: transform var(--duration-fast);
}

.asset-card:hover img {
  transform: scale(1.05);
}

.asset-card__thumb div {
  width: 100%;
  height: 100%;
  display: grid;
  place-items: center;
  color: var(--muted);
  font-weight: 950;
}

.asset-card.is-generating .asset-card__thumb {
  border-color: rgba(99, 102, 241, 0.45);
}

.asset-card__generating {
  position: absolute;
  inset: 0;
  display: grid;
  place-items: center;
  gap: 4px;
  background: rgba(8, 12, 22, 0.62);
  color: #fff !important;
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0.02em;

  .el-icon {
    font-size: 16px;
  }

  em {
    font-style: normal;
  }
}

.asset-card__generating--queued {
  gap: 6px;
  padding: 6px;
}

.asset-card__cancel-queue {
  border: 1px solid rgba(251, 191, 36, 0.7);
  border-radius: 999px;
  background: rgba(251, 191, 36, 0.18);
  color: #fde68a;
  font-size: 10px;
  font-weight: 800;
  line-height: 1;
  padding: 4px 8px;
  cursor: pointer;
}

.asset-card__cancel-queue:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.asset-card span {
  display: block;
  margin-top: 5px;
  color: var(--muted);
  font-size: 10px;
  font-weight: 850;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.asset-empty {
  min-height: 300px;
  display: grid;
  place-items: center;
  align-content: center;
  gap: 6px;
  color: var(--muted-soft);
  text-align: center;
}

.asset-empty p {
  margin: 0;
  color: var(--muted);
  font-weight: 900;
}

.asset-empty span {
  max-width: 220px;
  font-size: 12px;
}

.asset-preview {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 260px;
  gap: 18px;
}

.asset-preview__stage {
  position: relative;
  min-height: 420px;
  display: grid;
  place-items: center;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-soft);
  overflow: hidden;
}

.asset-preview__stage img {
  width: 100%;
  max-height: 74vh;
  object-fit: contain;
}

.asset-preview__empty {
  display: grid;
  place-items: center;
  gap: 8px;
  color: var(--muted);
  font-size: 13px;
  font-weight: 850;
}

.asset-preview__empty .el-icon {
  color: #b4c3d5;
  font-size: 28px;
}

.asset-preview__copy {
  position: absolute;
  top: 14px;
  right: 14px;
  z-index: 3;
}

.asset-preview__side {
  min-width: 0;
}

.asset-preview__side strong {
  display: block;
  color: var(--on-dark);
  font-size: 17px;
  font-weight: 950;
}

.asset-preview__side p {
  color: var(--muted);
  font-size: 13px;
  line-height: 1.6;
}

.asset-tags,
.asset-thumbs {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.asset-tags span {
  padding: 2px 8px;
  border: 1px solid var(--hairline);
  border-radius: 8px;
  background: var(--surface-soft);
  color: var(--muted);
  font-size: 11px;
}

.asset-thumbs {
  margin-top: 14px;
}

.asset-thumbs button {
  width: 72px;
  aspect-ratio: 1 / 1;
  padding: 0;
  border: 2px solid transparent;
  border-radius: 8px;
  overflow: hidden;
  background: var(--surface-soft);
  cursor: pointer;
}

.asset-thumbs button.is-active {
  border-color: var(--accent-cyan);
}

.asset-thumbs img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

@media (max-width: 1500px) {
  .production-board {
    grid-template-columns: 284px minmax(0, 1fr) 320px;
  }

  .episode-summary-card {
    grid-template-columns: 190px minmax(0, 1fr);
  }

  .episode-preview,
  .episode-preview__empty {
    min-height: 190px;
  }

  .rail-head {
    padding: 14px;
    gap: 12px;
  }

  .rail-title strong {
    font-size: 20px;
  }

  .workbench-head {
    min-height: 74px;
    padding: 14px 18px;
  }
}

@media (max-width: 1320px) {
  .production-board {
    grid-template-columns: 280px minmax(0, 1fr);
    overflow-y: auto;
  }

  .asset-panel {
    grid-column: 1 / -1;
    min-height: 320px;
    max-height: 420px;
    border-top: 1px solid var(--hairline);
    border-left: 0;
  }

  .asset-panel__body {
    overflow-y: auto;
  }

  .episode-summary-card {
    grid-template-columns: 180px minmax(0, 1fr);
  }

  .episode-summary__footer {
    align-items: flex-start;
    flex-direction: column;
  }

  .episode-summary__meta,
  .episode-summary__actions {
    width: 100%;
  }

  .stage-card {
    grid-template-columns: 1fr;
  }

  .stage-actions {
    min-height: 0;
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
  }

  .stage-actions :deep(.el-button) {
    min-width: 110px;
  }
}

@media (max-width: 980px) {
  .production-board {
    grid-template-columns: 1fr;
    overflow-y: auto;
  }

  .episode-list {
    display: flex;
    overflow-x: auto;
    padding-bottom: 12px;
  }

  .episode-item {
    width: 220px;
    flex: 0 0 220px;
  }

  .episode-rail {
    min-height: auto;
  }

  .rail-head {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    align-items: start;
  }

  .work-summary {
    display: none;
  }

  .workbench-body,
  .asset-panel__body {
    overflow: visible;
  }

  .asset-panel {
    max-height: none;
  }
}

@media (max-width: 720px) {
  .workbench-head,
  .episode-summary__footer,
  .panel-head {
    flex-direction: column;
    align-items: flex-start;
  }

  .workbench-head strong {
    white-space: normal;
  }

  .workbench-body {
    padding: 14px;
  }

  .episode-summary-card,
  .asset-preview {
    grid-template-columns: 1fr;
  }

  .episode-summary-card,
  .episode-preview,
  .episode-preview__empty,
  .episode-summary {
    min-height: 0;
  }

  .episode-preview,
  .episode-preview__empty {
    min-height: 160px;
  }

  .episode-preview {
    border-right: 0;
    border-bottom: 1px solid #e4ebf2;
  }

  .episode-summary__title-row {
    flex-direction: column;
  }

  .episode-summary__edit {
    width: 100%;
  }

  .episode-summary__actions {
    width: 100%;
    justify-content: stretch;
  }

  .episode-summary__actions :deep(.el-button) {
    flex: 1 1 0 !important;
    min-width: 0 !important;
  }

  .stage-card {
    grid-template-columns: 1fr;
    padding: 10px;
  }

  .stage-actions {
    min-height: 0;
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
  }

  .stage-main {
    grid-template-columns: 30px minmax(0, 1fr);
    padding: 10px;
  }

  .stage-title {
    grid-template-columns: minmax(0, 1fr);
    align-items: start;
    gap: 4px;
  }

  .stage-actions {
    flex-wrap: wrap;
  }

  .stage-actions :deep(.el-button) {
    flex: 1 1 120px;
  }

}

/* ── 镜头版本面板 ─────────────────────────────────────────────── */
.video-shot-card__actions {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-wrap: wrap;
}

.shot-version {
  display: flex;
  flex-direction: column;
  gap: 20px;
}

.shot-version__title {
  font-size: 13px;
  font-weight: 700;
  color: var(--body-strong);
  margin-bottom: 10px;
}

.shot-version__grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
  gap: 12px;
}

.shot-version__empty {
  padding: 24px 0;
  text-align: center;
  color: var(--muted);
  font-size: 13px;
}

.version-card {
  display: flex;
  flex-direction: column;
  gap: 6px;
  padding: 8px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-card);
  cursor: pointer;
  text-align: left;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;

  &:hover:not(:disabled) {
    border-color: var(--brand-cyan);
    box-shadow: 0 4px 14px rgba(15, 23, 42, 0.1);
  }

  &.is-selected {
    border-color: var(--brand-cyan);
    cursor: default;
  }

  &:disabled {
    cursor: default;
  }
}

.version-card__thumb {
  position: relative;
  aspect-ratio: 16 / 9;
  border-radius: 6px;
  overflow: hidden;
  background: var(--surface-soft);

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.version-card__thumb-empty {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--muted-soft);
  font-size: 22px;
}

.version-card__badge {
  position: absolute;
  top: 6px;
  left: 6px;
  padding: 1px 7px;
  border-radius: var(--radius-pill);
  background: var(--brand-cyan);
  color: #fff;
  font-size: 11px;
  font-weight: 700;

  &.is-muted {
    background: rgba(148, 163, 184, 0.92);
  }
}

.version-card__main {
  display: flex;
  flex-direction: column;
  gap: 8px;
  width: 100%;
  padding: 0;
  border: 0;
  background: transparent;
  color: inherit;
  text-align: left;
  cursor: pointer;

  &:disabled {
    cursor: default;
  }
}

.version-card__prompt {
  margin: 0;
  max-height: 3.6em;
  overflow: hidden;
  color: var(--muted);
  font-size: 11px;
  line-height: 1.4;
  display: -webkit-box;
  -webkit-line-clamp: 3;
  -webkit-box-orient: vertical;
}

.version-card.is-stale {
  border-color: rgba(251, 191, 36, 0.45);
}

.version-card.is-orphaned {
  opacity: 0.92;
}

.shot-version__hint {
  margin: 0 0 10px;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.5;
}

.version-card__meta {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  font-size: 11px;
}

.version-card__source {
  font-weight: 600;
  color: var(--body-strong);
}

.version-card__time {
  color: var(--muted);
}

.version-card__preview {
  font-size: 11px;
  color: var(--brand-cyan);
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }
}
</style>
