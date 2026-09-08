<script setup lang="ts">
import { computed, defineAsyncComponent, onMounted, onBeforeUnmount, ref, watch, nextTick } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { usePageQuery, queryId, updatePageQuery } from '@/utils/pageQuery'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Box, Check, Connection, Delete, EditPen, Loading, Plus, QuestionFilled, UploadFilled, Warning } from '@element-plus/icons-vue'
import { useI18n } from 'vue-i18n'
import {
  listSeries,
  createSeries,
  updateSeries,
  deleteSeries,
  createEpisode,
  getEpisode,
  updateEpisode,
  runSeriesWorkflow,
  runEpisodeWorkflowNodeAsync,
  cancelEpisodeWorkflowAuto,
  cancelEpisodeWorkflowNode,
  cancelEpisodeVideoShot,
  previewEpisodeVideoPrompts,
  regenerateEpisodeStoryboardShot,
  rerunEpisodeVideoShot,
  selectEpisodeMediaVersion,
  updateEpisodeWorkflowNodeContent,
  type UpdateEpisodeWorkflowNodeContentOptions,
  type StoryboardShotPayload,
  type SeriesRunWorkflowResult,
  type VideoPromptPreview,
  type VideoPromptPreviewShot,
  deleteEpisode,
} from '@/api/series'
import { listWorkflows } from '@/api/workflow'
import { cancelWorkflowRun, getWorkflowRun, resumeWorkflowRun, workflowRunStreamUrl, type WorkflowRunDetail } from '@/api/workflowRun'
import { batchGenerateCoreImages, cancelAssetImageJobs, listAssets } from '@/api/asset'
import { listGrouped as listModelConfigs } from '@/api/modelConfig'
import { listBundles } from '@/api/workflowBundle'
import { listPromptTemplates } from '@/api/promptTemplate'
import { canEditNodePrompt, isSystemManagedPromptNode, promptTemplatesForNode } from '@/utils/workflowPrompts'
import { notifyTask } from '@/utils/taskNotify'
import { clearWorkerPageContext, writeWorkerPageContext } from '@/utils/workerContext'
import { t as translateMessage } from '@/i18n'
import {
  VISUAL_STYLE_OPTIONS,
  normalizeVisualStyleVariant,
  visualStyleVariantOptions,
  type VisualStyle,
} from '@/utils/visualStyle'
import { isAssetImageJobPending, isAssetImageJobQueued } from '@/utils/assetImageJob'
import type { Asset, Episode, PromptTemplate, Series, StoryboardAssetRefNode, StoryboardRichNode, Workflow, WorkflowBundle } from '@/types'
import WorksGallery from '@/components/series/WorksGallery.vue'
import StoryboardRegenerateDialog from '@/components/series/StoryboardRegenerateDialog.vue'

const NewWorkWizard = defineAsyncComponent(() => import('@/components/series/NewWorkWizard.vue'))
const ProductionDashboard = defineAsyncComponent(() => import('@/components/series/ProductionDashboard.vue'))
const AssetEditorDialog = defineAsyncComponent(() => import('@/components/assets/AssetEditorDialog.vue'))

interface DetailNodeRow {
  id: string
  title: string
  kind: string
  desc: string
  inputText?: string
  outputText?: string
  outputJson?: Record<string, any>
  prompt?: string
  promptTemplateId?: number | null
  executionMode?: string
  status: 'queued' | 'running' | 'success' | 'failed' | 'skipped' | 'stale'
  errorMessage?: string
}

interface NodePromptExecutionChoice {
  prompt?: string
  templateId?: number | null
}

const isLoading = ref(true)
const isSaving = ref(false)
const isSeriesSubmitting = ref(false)
const seriesList = ref<Series[]>([])
const workflows = ref<Workflow[]>([])
const promptTemplates = ref<PromptTemplate[]>([])
const workflowsLoaded = ref(false)
const bundlesLoaded = ref(false)
const promptTemplatesLoaded = ref(false)
const selectedSeriesId = usePageQuery<number | null>('series_id', null, queryId)
const selectedEpisodeId = usePageQuery<number | null>('episode_id', null, queryId)
const selectedEpisode = ref<Episode | null>(null)
const selectedDetailNodeId = ref<string | null>(null)
/** 点击执行后乐观标记为 running，直到接口返回或轮询刷新 */
const executingNodeId = ref<string | null>(null)
const pendingEpisodeRunIds = ref<Set<number>>(new Set())
const pendingStageRunKeys = ref<Set<string>>(new Set())
let episodeWorkflowPollTimer: ReturnType<typeof setInterval> | null = null
let seriesAssetPollTimer: ReturnType<typeof setInterval> | null = null
const activeWorkflowRun = ref<WorkflowRunDetail | null>(null)
const seriesAssets = ref<Asset[]>([])
const batchGeneratingCore = ref(false)
const batchCancellingCore = ref(false)
const cancellingCoreAssetIds = ref<Record<number, boolean>>({})
const ASSET_IMAGE_MODEL_STORAGE_KEY = 'malulu.assets.defaultImageModelId'
const assetEditorVisible = ref(false)
const editingAsset = ref<Asset | null>(null)
const editingAssetImageId = ref<number | null>(null)
const novelImportInputRef = ref<HTMLInputElement | null>(null)
const isNovelImporting = ref(false)
const isNovelDragOver = ref(false)
const episodeDetailCache = new Map<number, Episode>()
let workflowRunSource: EventSource | null = null
let workflowRunPollTimer: ReturnType<typeof setInterval> | null = null
const WORKFLOW_RUN_STORAGE_KEY = 'malulu.activeWorkflowRunId'
const WORKFLOW_CONTEXT_STORAGE_KEY = 'malulu.activeWorkflowContext'
const { t } = useI18n()

const rerunPromptDialogVisible = ref(false)
const rerunPromptForm = ref({
  nodeId: '',
  nodeTitle: '',
  nodeKind: 'text',
  mode: 'template' as 'template' | 'custom',
  templateId: null as number | null,
  prompt: '',
})
let rerunPromptResolver: ((value: NodePromptExecutionChoice | undefined) => void) | null = null
const videoPromptPreviewVisible = ref(false)
const videoPromptPreviewLoading = ref(false)
const videoPromptPreview = ref<VideoPromptPreview | null>(null)
const activeVideoPromptShotIndex = ref<number | null>(null)
const storyboardRegenerateDialogVisible = ref(false)
const storyboardRegenerateDialog = ref({ episodeId: 0, nodeId: '', shotIndex: 0, title: '', impactHtml: '' })
let storyboardRegenerateDialogResolver: ((value: { instruction: string; assetRefs: StoryboardAssetRefNode[] } | undefined) => void) | null = null
const activeVideoPromptShot = computed(() => {
  const shots = videoPromptPreview.value?.shots ?? []
  return shots.find((shot) => shot.index === activeVideoPromptShotIndex.value) ?? shots[0] ?? null
})

function workflowDisplayLabel(value: unknown, fallback = ''): string {
  const raw = String(value ?? '').trim()
  if (!raw) return fallback
  return translateMessage(raw)
}

function workflowDisplayMessage(value: unknown, fallback = ''): string {
  const raw = String(value ?? '').trim()
  if (!raw) return fallback
  return translateMessage(raw)
}

function resolveVideoPromptPreviewText(shot: VideoPromptPreviewShot | null | undefined): string {
  if (!shot) return ''
  const prompt = String(shot.prompt ?? '').trim()
  if (prompt !== '') return prompt
  const compiledPrompt = String(shot.compiled_prompt ?? '').trim()
  if (compiledPrompt !== '') return compiledPrompt
  return t('当前镜头暂无可发送的视频提示词，请检查上游分镜与视频节点配置。')
}

function formatVideoPreviewOptions(options: Record<string, unknown> | undefined) {
  const clean = { ...(options ?? {}) }
  delete clean.q
  return JSON.stringify(clean)
}

function clearVideoPromptPreviewState() {
  videoPromptPreviewVisible.value = false
  videoPromptPreviewLoading.value = false
  videoPromptPreview.value = null
  activeVideoPromptShotIndex.value = null
}

const seriesDialogVisible = ref(false)
const seriesForm = ref({
  id: 0,
  title: '',
  description: '',
  visualStyle: 'realistic' as VisualStyle,
  visualStyleVariant: '',
  region: 'china' as 'china' | 'western',
  createMode: 'manual' as 'manual' | 'workflow',
  workflowId: null as number | null,
  seedPlotInput: '',
  sourceFileToken: '',
  sourceFilename: '',
  episodeCount: null as number | null,
})
const episodeDrawerVisible = ref(false)
const episodeForm = ref<{
  seriesId: number
  title: string
  number: number
  plotInput: string
  promoSegmentCount: number
}>({
  seriesId: 0,
  title: '',
  number: 1,
  plotInput: '',
  promoSegmentCount: 3,
})

function workflowLooksLikePromo(workflow: Workflow | null | undefined): boolean {
  if (!workflow) return false
  const name = String(workflow.name || '')
  if (name.includes('宣传片') || name.toLowerCase().includes('promo')) return true
  const nodes = workflow.graph?.nodes
  if (!Array.isArray(nodes)) return false
  return nodes.some((node: any) => {
    const label = String(node?.label ?? node?.data?.label ?? '')
    return label.includes('宣传片')
  })
}

const createEpisodeWorkflowId = computed<number | null>(() => {
  const series = selectedSeries.value
  if (!series) return null
  for (const ep of series.episodes ?? []) {
    const id = Number(ep.workflow_id || 0)
    if (id > 0) return id
  }
  if (pendingEpisodeWorkflowId.value) return pendingEpisodeWorkflowId.value
  return preferredWorkflowId.value
})

const isPromoSeriesForCreate = computed(() => {
  const workflowId = createEpisodeWorkflowId.value
  if (!workflowId) return false
  return workflowLooksLikePromo(workflows.value.find((w) => w.id === workflowId) ?? null)
})

const promoSegmentOptions = computed(() => [1, 2, 3, 4].map((count) => ({
  value: count,
  label: t('{count} 段 · 约 {seconds} 秒', { count, seconds: count * 15 }),
})))

const selectedSeries = computed<Series | null>(() => {
  if (!selectedSeriesId.value) return null
  return seriesList.value.find((s) => s.id === selectedSeriesId.value) ?? null
})

watch(
  [selectedSeries, selectedEpisode],
  ([series, episode]) => {
    if (!series) {
      clearWorkerPageContext('series')
      return
    }
    writeWorkerPageContext({
      source: 'series',
      page: 'series',
      series_id: series.id,
      series_title: series.title,
      episode_id: episode?.id,
      episode_number: episode?.number,
      episode_title: episode?.title,
    })
  },
  { immediate: true },
)

const currentVisualStyleOptions = computed(() =>
  VISUAL_STYLE_OPTIONS.map((option) => ({ ...option, label: t(option.label) })),
)
const currentVisualStyleVariantOptions = computed(() =>
  visualStyleVariantOptions(seriesForm.value.visualStyle).map((option) => ({
    ...option,
    label: t(option.label),
    desc: option.desc ? t(option.desc) : option.desc,
  })),
)

const workflowNameById = computed(() => {
  const map = new Map<number, string>()
  for (const workflow of workflows.value) {
    const id = Number(workflow.id || 0)
    const name = String(workflow.name || '').trim()
    if (id > 0 && name) {
      map.set(id, name)
    }
  }
  return map
})

watch(
  () => seriesForm.value.visualStyle,
  (style) => {
    seriesForm.value.visualStyleVariant = normalizeVisualStyleVariant(style, seriesForm.value.visualStyleVariant)
  },
)

const dashboardEpisodes = computed<Episode[]>(() => {
  const episodes = selectedSeries.value?.episodes ?? []
  return episodes.map((episode) => {
    if (selectedEpisode.value?.id === episode.id) {
      return hydrateEpisodeWorkflowName(cloneEpisode(selectedEpisode.value))
    }
    const cached = episodeDetailCache.get(episode.id)
    return hydrateEpisodeWorkflowName(cached ? cloneEpisode(cached) : cloneEpisode(episode))
  })
})

// ── 作品生产台：作品库 → 单作品生产台 ────────────────────────────────────────────
const bundles = ref<WorkflowBundle[]>([])
const wizardVisible = ref(false)
const wizardPreselectId = ref<number | null>(null)

const view = computed<'gallery' | 'dashboard'>(() => {
  if (selectedSeriesId.value) return 'dashboard'
  return 'gallery'
})

function backToGallery() {
  selectedEpisode.value = null
  selectedEpisodeId.value = null
  selectedSeriesId.value = null
  updatePageQuery(router, { episode_id: undefined, nodes: undefined, shots: undefined })
}

async function openWizard() {
  wizardPreselectId.value = null
  await ensureBundles()
  wizardVisible.value = true
}

async function handleWizardSubmit(payload: {
  bundle: WorkflowBundle
  title: string
  input: string
  episodeCount: number | null
  promoSegmentCount: number | null
  visualStyle: 'realistic' | 'anime' | '3d'
  visualStyleVariant?: string
  region: 'china' | 'western'
}) {
  if (isSeriesSubmitting.value) return
  isSeriesSubmitting.value = true
  try {
    const b = payload.bundle
    const title = payload.title || `作品 ${new Date().toLocaleString()}`

    if (b.series_workflow_id) {
      // 批量：剧本段 → 拆集 → 每集剧集段
      pendingEpisodeWorkflowId.value = b.episode_workflow_id
      const explicitEpisodeCount = typeof payload.episodeCount === 'number' && payload.episodeCount > 0
        ? Math.max(1, Math.min(100, Math.floor(payload.episodeCount)))
        : null
      // 试验阶段：只创建作品并保存原文，不自动开跑；由用户手动「运行剧本解析」后再逐步执行节点。
      const created = await createSeries({
        title,
        description: '',
        source_text: payload.input,
        visual_style: payload.visualStyle,
        visual_style_variant: normalizeVisualStyleVariant(payload.visualStyle, payload.visualStyleVariant),
        region: payload.region,
        series_workflow_id: b.series_workflow_id,
        ...(explicitEpisodeCount !== null ? { episode_count: explicitEpisodeCount } : {}),
      })
      pendingEpisodeWorkflowId.value = b.episode_workflow_id
      ElMessage.success(t('作品已创建，请先运行剧本解析，再逐步执行节点'))
      wizardVisible.value = false
      await loadAll()
      await pickSeries(created.id)
    } else {
      // 单集：裸作品 + 一集，绑定剧集段工作流；试验阶段不自动执行本集。
      const created = await createSeries({
        title,
        description: '',
        visual_style: payload.visualStyle,
        visual_style_variant: normalizeVisualStyleVariant(payload.visualStyle, payload.visualStyleVariant),
        region: payload.region,
      })
      await createEpisode(created.id, {
        title: '第1集',
        number: 1,
        plot_input: payload.input,
        workflow_id: b.episode_workflow_id,
        ...(typeof payload.promoSegmentCount === 'number' ? { promo_segment_count: payload.promoSegmentCount } : {}),
      })
      ElMessage.success(t('作品已创建，可在流程节点中逐步执行'))
      wizardVisible.value = false
      await loadAll()
      await pickSeries(created.id)
    }
  } catch (e: any) {
    ElMessage.error(e?.message ?? t('创建失败，请稍后重试'))
  } finally {
    pendingEpisodeWorkflowId.value = null
    isSeriesSubmitting.value = false
  }
}

async function runDashboardEpisode(_episodeId: number) {
  ElMessage.info(t('请在流程节点中逐步执行各步骤'))
}

async function runDashboardSeriesWorkflow() {
  const series = selectedSeries.value
  if (!series?.id) return
  if (!series.series_workflow_id) {
    ElMessage.warning(t('当前作品未绑定剧本段工作流'))
    return
  }
  if ((series.episodes?.length ?? 0) > 0) {
    ElMessage.info(t('剧本解析已完成'))
    return
  }
  if (selectedSeriesWorkflowBusyLocked.value) {
    ElMessage.warning(workflowLockedMessage.value)
    return
  }
  try {
    // 不要把 description 摘要当成正文：完整剧本在后端 series.source_text。
    const fullSource = String(series.source_text || '').trim()
    const result = await runSeriesWorkflow(series.id, {
      workflow_id: series.series_workflow_id,
      episode_workflow_id: pendingEpisodeWorkflowId.value ?? preferredWorkflowId.value,
      ...(fullSource !== '' ? { source_text: fullSource } : {}),
    }) as SeriesRunWorkflowResult & { workflow_run?: { id?: number; status?: string }; queued?: boolean }
    const run = result.workflow_run
    if (run?.id) {
      activeWorkflowRun.value = {
        id: run.id,
        series_id: series.id,
        workflow_id: series.series_workflow_id,
        episode_workflow_id: pendingEpisodeWorkflowId.value ?? preferredWorkflowId.value,
        status: (run.status as WorkflowRunDetail['status']) ?? 'queued',
        progress: 0,
        current_node_label: '',
        error_message: '',
        nodes: [],
      }
      startWorkflowRunStream(run.id)
      ElMessage.success(t('剧本解析已排队，完成后请逐步执行各集节点'))
    } else {
      ElMessage.success(t('剧本解析完成，请逐步执行各集节点'))
      await loadAll()
      await pickSeries(series.id)
    }
  } catch (error: any) {
    ElMessage.error(error?.message ?? t('剧本解析失败'))
  }
}

async function cancelDashboardEpisodeAutoRun(episodeId: number) {
  if (pendingEpisodeRunIds.value.has(episodeId)) return
  setPendingEpisodeRun(episodeId, true)
  await selectEpisode(episodeId)
  try {
    await ElMessageBox.confirm(
      t('这会停止本集后续自动推进，并取消尚未完成的视频后台任务；已经成功生成的产物会保留。'),
      t('取消本集自动执行'),
      { type: 'warning', confirmButtonText: t('取消自动执行'), cancelButtonText: t('继续执行') },
    )
    const updated = await cancelEpisodeWorkflowAuto(episodeId)
    applyEpisodeUpdate(updated)
    ElMessage.success(t('本集自动执行已取消'))
  } catch (error: any) {
    if (error !== 'cancel') {
      const message = error?.message ?? ''
      if (message) {
        ElMessage.warning(message)
      }
    }
  } finally {
    setPendingEpisodeRun(episodeId, false)
  }
}

async function updateDashboardSeriesTitle(title: string, done: (ok: boolean) => void) {
  const series = selectedSeries.value
  if (!series) {
    done(false)
    return
  }
  if (selectedSeriesWorkflowBusyLocked.value) {
    ElMessage.warning(workflowLockedMessage.value)
    done(false)
    return
  }

  const nextTitle = title.trim()
  if (!nextTitle) {
    ElMessage.warning(t('请输入作品名称'))
    done(false)
    return
  }

  try {
    // 后端 update 会用 payload 覆盖 description 等字段，改名时必须带回现有值
    const updated = await updateSeries(series.id, {
      title: nextTitle,
      description: series.description ?? '',
      visual_style: series.visual_style,
      visual_style_variant: series.visual_style_variant,
      region: series.region,
      series_workflow_id: series.series_workflow_id,
    })
    seriesList.value = seriesList.value.map((item) =>
      item.id === series.id
        ? {
            ...item,
            ...updated,
            title: updated.title ?? nextTitle,
            episodes: item.episodes,
            active_workflow_run: item.active_workflow_run,
          }
        : item,
    )
    ElMessage.success(t('作品名称已更新'))
    done(true)
  } catch (error: any) {
    ElMessage.error(error?.message ?? t('保存作品名称失败'))
    done(false)
  }
}

async function updateDashboardPlot(episodeId: number, plotInput: string, done: (ok: boolean) => void) {
  if (selectedSeriesWorkflowLocked.value) {
    ElMessage.warning(workflowLockedMessage.value)
    done(false)
    return
  }

  try {
    await selectEpisode(episodeId)
    if (!selectedEpisode.value || selectedEpisode.value.id !== episodeId) {
      throw new Error('未找到当前剧集')
    }

    const previous = cloneEpisode(selectedEpisode.value)
    selectedEpisode.value = {
      ...selectedEpisode.value,
      plot_input: plotInput,
    }

    try {
      await confirmPlotInputSave()
    } catch (error) {
      selectedEpisode.value = cloneEpisode(previous)
      if (error !== 'cancel' && error !== 'close') {
        throw error
      }
      done(false)
      return
    }

    const updated = await updateEpisode(episodeId, { plot_input: plotInput })
    applyEpisodeUpdate(updated)
    ElMessage.success(t('剧情概要已保存'))
    done(true)
  } catch (error: any) {
    ElMessage.error(error?.message ?? t('保存剧情概要失败'))
    done(false)
  }
}

function assetHasCoreImage(asset: Asset): boolean {
  return (asset.images ?? []).some((img) => img.view_type === 'main' && String(img.url || '').trim() !== '')
}

function assetHasUsableReferenceImage(asset: Asset): boolean {
  if (assetHasCoreImage(asset)) return true
  return (asset.images ?? []).some((img) => String((img as any).reference_role || 'view').toLowerCase() === 'look' && String(img.url || '').trim() !== '')
}

function referencedAssetIdsFromShotLike(shot: Record<string, any> | null | undefined): number[] {
  if (!shot || typeof shot !== 'object') return []
  const ids = new Set<number>()
  const pushId = (value: unknown) => {
    const id = Number(value ?? 0)
    if (Number.isFinite(id) && id > 0) ids.add(id)
  }
  for (const asset of (Array.isArray(shot.assets) ? shot.assets : [])) {
    if (!asset || typeof asset !== 'object') continue
    pushId((asset as any).id ?? (asset as any).asset_id)
  }
  const refs = Array.isArray(shot.asset_refs)
    ? shot.asset_refs
    : (Array.isArray((shot as any).assetRefs) ? (shot as any).assetRefs : [])
  for (const ref of refs) {
    if (!ref || typeof ref !== 'object') continue
    pushId((ref as any).asset_id ?? (ref as any).id)
  }
  return [...ids]
}

function collectReferencedAssetIdsForVideo(nodeId?: string, shotIndex?: number): number[] {
  const ids = new Set<number>()
  const nodes = selectedEpisode.value?.workflow_state?.nodes ?? []
  const videoNode = nodeId
    ? nodes.find((item) => item.workflow_node_id === nodeId)
    : nodes.find((item) => item.kind === 'video')
  const outputShots = Array.isArray((videoNode?.output_json as any)?.shots)
    ? ((videoNode?.output_json as any).shots as any[])
    : []
  for (const shot of outputShots) {
    const index = Number(shot?.index ?? shot?.shot_index ?? 0)
    if (shotIndex && shotIndex > 0 && index !== shotIndex) continue
    for (const id of referencedAssetIdsFromShotLike(shot)) ids.add(id)
  }
  if (ids.size === 0) {
    for (const shot of (selectedEpisode.value?.shots ?? [])) {
      const index = Number((shot as any).index ?? (shot as any).shot_index ?? 0)
      if (shotIndex && shotIndex > 0 && index !== shotIndex) continue
      for (const id of referencedAssetIdsFromShotLike(shot as any)) ids.add(id)
    }
  }
  return [...ids]
}

function seriesAssetsMissingCoreViews(referencedIds?: number[]): Asset[] {
  const seriesId = selectedSeriesId.value
  // No resolved refs for this video/shot => do not block on unrelated series assets.
  // Never fall back to scanning the whole series library.
  if (!Array.isArray(referencedIds) || referencedIds.length === 0) return []
  const idSet = new Set(referencedIds)
  return seriesAssets.value.filter((asset) => {
    if (seriesId && asset.series_id !== seriesId) return false
    if (!idSet.has(asset.id)) return false
    return !assetHasUsableReferenceImage(asset)
  })
}
function guardSeriesCoreViewsReadyForVideo(nodeId?: string, shotIndex?: number): boolean {
  const referencedIds = collectReferencedAssetIdsForVideo(nodeId, shotIndex)
  const missing = seriesAssetsMissingCoreViews(referencedIds)
  if (missing.length === 0) return true
  const names = missing.slice(0, 8).map((asset) => asset.name || `#${asset.id}`).join('、')
  const more = missing.length > 8 ? t(' 等 {count} 个', { count: missing.length }) : ''
  ElMessage.warning(t('当前镜头引用的资产尚未就绪，无法生成视频。缺少：{names}{more}', { names, more }))
  return false
}

async function runDashboardStage(episodeId: number, nodeId: string) {
  if (pendingStageRunKeys.value.has(stageRunKey(episodeId, nodeId))) return
  setPendingStageRun(episodeId, nodeId, true)
  // 注意：不能在这里提前 markEpisodeNodeRunning，否则 runNextNode 的
  // status === 'running' 防重守卫会误判为执行中而直接跳过，导致"重新执行"无效。
  await selectEpisode(episodeId)
  const activeNode = selectedEpisode.value?.workflow_state?.nodes?.find((item) => item.workflow_node_id === nodeId)
  if (activeNode?.kind === 'video' && !guardSeriesCoreViewsReadyForVideo(nodeId)) {
    setPendingStageRun(episodeId, nodeId, false)
    return
  }
  const existingVideoShots = Array.isArray((activeNode?.output_json as any)?.shots)
    ? ((activeNode?.output_json as any)?.shots as any[])
    : []
  if (activeNode?.kind === 'video' && existingVideoShots.length > 0) {
    try {
      await ElMessageBox.confirm(
        t('这会重新提交当前分镜下的全部视频镜头。若只想生成某一段，请先展开节点，再点击对应镜头里的“生成这一段”或“重新生成这一段”。'),
        t('整节点重跑视频'),
        {
          type: 'warning',
          confirmButtonText: t('继续整节点重跑'),
          cancelButtonText: t('取消'),
        },
      )
    } catch {
      setPendingStageRun(episodeId, nodeId, false)
      return
    }
  }
  selectedDetailNodeId.value = nodeId
  await nextTick()
  try {
    await runNextNode({ allowPending: true })
  } finally {
    setPendingStageRun(episodeId, nodeId, false)
  }
}

async function cancelDashboardStage(episodeId: number, nodeId: string) {
  if (selectedSeriesWorkflowLocked.value) return ElMessage.warning(workflowLockedMessage.value)

  try {
    await ElMessageBox.confirm(
      t('确定取消当前节点生成？已生成成功的镜头/产物会保留；只会停止尚未完成的任务。取消后可以重新执行这个节点。'),
      t('取消节点生成'),
      { type: 'warning', confirmButtonText: t('取消生成'), cancelButtonText: t('继续等待') },
    )
  } catch {
    return
  }

  await selectEpisode(episodeId)
  const result = await cancelEpisodeWorkflowNode(episodeId, nodeId)
  applyEpisodeUpdate(result.episode)
  setPendingStageRun(episodeId, nodeId, false)
  if (executingNodeId.value === nodeId) {
    executingNodeId.value = null
  }
  ElMessage.success(result.cancelled_video_jobs > 0
    ? t('已取消节点和 {count} 个未完成视频任务（已成功镜头保留）', { count: result.cancelled_video_jobs })
    : t('已取消节点生成'))
}

async function cancelDashboardVideoShot(episodeId: number, nodeId: string, jobId: number) {
  if (selectedSeriesWorkflowLocked.value) return ElMessage.warning(workflowLockedMessage.value)
  if (jobId <= 0) return ElMessage.warning(t('这段视频还没有生成记录'))

  try {
    await ElMessageBox.confirm(
      t('只取消这一镜的排队/生成。已经成功的其他镜头不会删除；若开启镜头串联，仍在等待这一镜的下游镜头也会一并取消排队。'),
      t('取消本镜排队'),
      { type: 'warning', confirmButtonText: t('取消本镜'), cancelButtonText: t('先不取消') },
    )
  } catch {
    return
  }

  await selectEpisode(episodeId)
  const result = await cancelEpisodeVideoShot(episodeId, nodeId, jobId)
  applyEpisodeUpdate(result.episode)
  setPendingStageRun(episodeId, nodeId, false, result.shot_index || undefined)
  ElMessage.success(
    result.shot_index > 0
      ? t('已取消镜头 {index} 的排队（已成功镜头保留）', { index: result.shot_index })
      : t('已取消本镜排队（已成功镜头保留）'),
  )
}

async function runDashboardVideoShot(episodeId: number, nodeId: string, shotIndex: number) {
  const key = stageRunKey(episodeId, nodeId, shotIndex)
  if (pendingStageRunKeys.value.has(key)) return
  if (selectedSeriesWorkflowLocked.value) return ElMessage.warning(workflowLockedMessage.value)
  if (shotIndex <= 0) return ElMessage.warning(t('没有找到这个镜头'))
  if (isVideoNodeBusyForShotActions(episodeId, nodeId, shotIndex)) {
    return ElMessage.warning(t('当前视频节点正在生成中，请等待完成后再操作其他镜头'))
  }
  if (!guardSeriesCoreViewsReadyForVideo(nodeId, shotIndex)) return

  setPendingStageRun(episodeId, nodeId, true, shotIndex)
  await selectEpisode(episodeId)
  try {
    markEpisodeNodeRunning(episodeId, nodeId)
    const updated = await runEpisodeWorkflowNodeAsync(episodeId, nodeId, undefined, { shotIndex })
    applyEpisodeUpdate(updated)
    ElMessage.success(t('镜头 {index} 已开始生成', { index: shotIndex }))
  } finally {
    setPendingStageRun(episodeId, nodeId, false, shotIndex)
  }
}

async function rerunDashboardVideoShot(episodeId: number, nodeId: string, jobId: number, prompt: string, shotIndex = 0) {
  if (selectedSeriesWorkflowLocked.value) return ElMessage.warning(workflowLockedMessage.value)
  const trimmed = prompt.trim()
  if (jobId <= 0) return ElMessage.warning(t('这段视频还没有生成记录'))
  if (!trimmed) return ElMessage.warning(t('请先填写视频指令'))

  const resolvedShotIndex = shotIndex > 0
    ? shotIndex
    : resolveVideoShotIndexForJob(episodeId, nodeId, jobId)
  const key = stageRunKey(episodeId, nodeId, resolvedShotIndex || shotIndex)
  if (pendingStageRunKeys.value.has(key)) return
  if (isVideoNodeBusyForShotActions(episodeId, nodeId, resolvedShotIndex)) {
    return ElMessage.warning(t('当前视频节点正在生成中，请等待完成后再操作其他镜头'))
  }
  if (!guardSeriesCoreViewsReadyForVideo(nodeId, resolvedShotIndex || undefined)) return

  setPendingStageRun(episodeId, nodeId, true, resolvedShotIndex || undefined)
  try {
    await selectEpisode(episodeId)
    markEpisodeNodeRunning(episodeId, nodeId)
    const updated = await rerunEpisodeVideoShot(episodeId, nodeId, jobId, trimmed)
    applyEpisodeUpdate(updated)
    ElMessage.success(t('这一段已重新提交'))
  } finally {
    setPendingStageRun(episodeId, nodeId, false, resolvedShotIndex || undefined)
  }
}

function resolveVideoShotIndexForJob(episodeId: number, nodeId: string, jobId: number): number {
  const episode = selectedEpisode.value?.id === episodeId
    ? selectedEpisode.value
    : (episodeDetailCache.get(episodeId) ?? seriesList.value.flatMap((s) => s.episodes || []).find((e) => e.id === episodeId))
  const node = episode?.workflow_state?.nodes?.find((item) => item.workflow_node_id === nodeId)
  const shots = Array.isArray((node?.output_json as any)?.shots) ? ((node?.output_json as any).shots as any[]) : []
  const matched = shots.find((shot) => Number(shot?.job_id ?? shot?.video_job_id ?? 0) === jobId)
  const index = Number(matched?.index ?? matched?.shot_index ?? 0)
  return Number.isFinite(index) && index > 0 ? index : 0
}

function isVideoNodeBusyForShotActions(episodeId: number, nodeId: string, shotIndex = 0): boolean {
  const episode = selectedEpisode.value?.id === episodeId
    ? selectedEpisode.value
    : (episodeDetailCache.get(episodeId) ?? null)
  const node = episode?.workflow_state?.nodes?.find((item) => item.workflow_node_id === nodeId)
  if (node?.status === 'running') return true
  const prefix = `${episodeId}:${nodeId}:shot:`
  for (const key of pendingStageRunKeys.value) {
    if (!key.startsWith(prefix)) continue
    if (shotIndex > 0 && key === stageRunKey(episodeId, nodeId, shotIndex)) continue
    return true
  }
  if (pendingStageRunKeys.value.has(stageRunKey(episodeId, nodeId))) return true
  return false
}

async function selectDashboardMediaVersion(episodeId: number, versionId: number) {
  if (selectedSeriesWorkflowLocked.value) return ElMessage.warning(workflowLockedMessage.value)
  await selectEpisode(episodeId)
  const updated = await selectEpisodeMediaVersion(episodeId, versionId)
  applyEpisodeUpdate(updated)
  ElMessage.success(t('已切换版本'))
}

async function previewDashboardVideoPrompts(episodeId: number, nodeId: string) {
  videoPromptPreviewLoading.value = true
  videoPromptPreview.value = null
  try {
    await selectEpisode(episodeId)
    videoPromptPreview.value = await previewEpisodeVideoPrompts(episodeId, nodeId)
    activeVideoPromptShotIndex.value = videoPromptPreview.value.shots[0]?.index ?? null
    videoPromptPreviewVisible.value = true
  } catch (error: any) {
    clearVideoPromptPreviewState()
    ElMessage.warning(error?.message ?? t('需要先执行上游节点，才能预览视频提示词'))
  } finally {
    videoPromptPreviewLoading.value = false
  }
}

async function regenerateDashboardStoryboardShot(
  episodeId: number,
  nodeId: string,
  shotIndex: number,
  done?: (ok: boolean) => void,
) {
  if (selectedSeriesWorkflowLocked.value) {
    ElMessage.warning(workflowLockedMessage.value)
    done?.(false)
    return
  }
  if (shotIndex <= 0) {
    ElMessage.warning(t('没有找到这个镜头'))
    done?.(false)
    return
  }

  try {
    await selectEpisode(episodeId)
    const impact = buildStoryboardRegeneratePromptHtml(nodeId, shotIndex)
    const selection = await openStoryboardRegenerateDialog(episodeId, nodeId, shotIndex, impact.html)
    if (!selection) {
      done?.(false)
      return
    }
    const updated = await regenerateEpisodeStoryboardShot(episodeId, nodeId, shotIndex, selection.instruction, selection.assetRefs)
    applyEpisodeUpdate(updated)
    clearVideoPromptPreviewState()
    ElMessage.success(t('镜头 {index} 已重新生成，仅当前镜头视频与最终成片已失效', { index: shotIndex }))
    done?.(true)
  } catch (error: any) {
    const message = String(error?.message || error || '')
    if (message && !['cancel', 'close'].includes(message)) {
      ElMessage.error(message)
    }
    done?.(false)
  }
}

function openStoryboardRegenerateDialog(
  episodeId: number,
  nodeId: string,
  shotIndex: number,
  impactHtml: string,
): Promise<{ instruction: string; assetRefs: StoryboardAssetRefNode[] } | undefined> {
  return new Promise((resolve) => {
    storyboardRegenerateDialogResolver = resolve
    storyboardRegenerateDialog.value = {
      episodeId,
      nodeId,
      shotIndex,
      title: t('AI重新生成镜头 {index}', { index: shotIndex }),
      impactHtml,
    }
    storyboardRegenerateDialogVisible.value = true
  })
}

function resolveStoryboardRegenerateDialog(value?: { instruction: string; assetRefs: StoryboardAssetRefNode[] }) {
  storyboardRegenerateDialogVisible.value = false
  const resolver = storyboardRegenerateDialogResolver
  storyboardRegenerateDialogResolver = null
  resolver?.(value)
}

function handleStoryboardRegenerateDialogVisibility(visible: boolean) {
  storyboardRegenerateDialogVisible.value = visible
  if (!visible && storyboardRegenerateDialogResolver) {
    resolveStoryboardRegenerateDialog()
  }
}

async function updateDashboardNodeContent(
  episodeId: number,
  nodeId: string,
  content: string,
  storyboardShots: Array<{ index: number; title: string; content_text: string; content_rich_json: StoryboardRichNode[] }>,
  options?: UpdateEpisodeWorkflowNodeContentOptions,
  done?: (ok: boolean) => void,
) {
  if (selectedSeriesWorkflowLocked.value) {
    ElMessage.warning(workflowLockedMessage.value)
    done?.(false)
    return
  }
  const trimmed = content.trim()
  if (!trimmed) {
    ElMessage.warning(t('分镜内容不能为空'))
    done?.(false)
    return
  }

  try {
    await selectEpisode(episodeId)
    const node = selectedEpisode.value?.workflow_state?.nodes?.find((item) => item.workflow_node_id === nodeId)
    const title = String(node?.label || nodeId || '节点')
    const isSingleShotUpdate = options?.updateMode === 'single_shot' && Number(options?.changedShotIndex || 0) > 0
    const impact = buildImpactMessageHtml(
      title,
      nodeId,
      isSingleShotUpdate
        ? t('保存后会更新镜头 {index} 的分镜内容。', { index: options?.changedShotIndex ?? '' })
        : t('保存后会用新的「{title}」内容继续后续流程。', { title }),
      isSingleShotUpdate
        ? {
            changedShotIndex: options?.changedShotIndex,
            onlyKinds: ['video', 'output'],
            closingLine: t('仅当前镜头的视频和最终成片会从“当前结果”中移除；已生成图片会保留。'),
          }
        : {},
    )
    await ElMessageBox.confirm(
      impact.html,
      t('保存{name}', { name: title }),
      {
        type: impact.hasImpact ? 'warning' : 'info',
        confirmButtonText: t('确认保存'),
        cancelButtonText: t('取消'),
        dangerouslyUseHTMLString: true,
      },
    )
    const updated = await updateEpisodeWorkflowNodeContent(episodeId, nodeId, trimmed, storyboardShots as StoryboardShotPayload[], options)
    applyEpisodeUpdate(updated)
    clearVideoPromptPreviewState()
    ElMessage.success(isSingleShotUpdate
      ? t('分镜已保存，仅当前镜头视频与最终成片已失效')
      : t('分镜已保存，下游当前产物已失效'))
    done?.(true)
  } catch (error: any) {
    const message = String(error?.message || error || '')
    if (message && !['cancel', 'close'].includes(message)) {
      ElMessage.error(message)
    }
    done?.(false)
  }
}

async function copyText(text: string) {
  try {
    await navigator.clipboard.writeText(text)
  } catch {
    const textarea = document.createElement('textarea')
    textarea.value = text
    textarea.style.position = 'fixed'
    textarea.style.opacity = '0'
    document.body.appendChild(textarea)
    textarea.select()
    document.execCommand('copy')
    document.body.removeChild(textarea)
  }
  ElMessage.success(t('已复制到剪贴板'))
}

const pendingEpisodeRunIdList = computed(() => Array.from(pendingEpisodeRunIds.value))
const pendingStageRunKeyList = computed(() => Array.from(pendingStageRunKeys.value))

function stageRunKey(episodeId: number, nodeId: string, shotIndex = 0): string {
  return shotIndex > 0 ? `${episodeId}:${nodeId}:shot:${shotIndex}` : `${episodeId}:${nodeId}`
}

function setPendingEpisodeRun(episodeId: number, pending: boolean) {
  const next = new Set(pendingEpisodeRunIds.value)
  if (pending) next.add(episodeId)
  else next.delete(episodeId)
  pendingEpisodeRunIds.value = next
}

function setPendingStageRun(episodeId: number, nodeId: string, pending: boolean, shotIndex = 0) {
  const next = new Set(pendingStageRunKeys.value)
  const key = stageRunKey(episodeId, nodeId, shotIndex)
  if (pending) next.add(key)
  else next.delete(key)
  pendingStageRunKeys.value = next
}

function markEpisodeNodeRunning(episodeId: number, nodeId: string) {
  const mark = (episode: Episode): Episode => {
    const nodes = episode.workflow_state?.nodes
    if (!nodes?.length) return episode
    return {
      ...episode,
      status: 'production',
      workflow_state: {
        ...episode.workflow_state,
        status: 'running',
        nodes: nodes.map((node) =>
          node.workflow_node_id === nodeId
            ? { ...node, status: 'running', error_message: '' }
            : node,
        ),
      },
    }
  }

  const cached = episodeDetailCache.get(episodeId)
  if (cached) {
    episodeDetailCache.set(episodeId, mark(cached))
  }
  if (selectedEpisode.value?.id === episodeId) {
    selectedEpisode.value = mark(selectedEpisode.value)
    syncEpisodeWorkflowPoll()
  }
  seriesList.value = seriesList.value.map((series) => ({
    ...series,
    episodes: series.episodes.map((episode) => (episode.id === episodeId ? mark(episode) : episode)),
  }))
}

// ── 剧集绑定工作流（生产台空状态入口） ─────────────────────────────────────────
const bindWorkflowVisible = ref(false)
const bindWorkflowEpisodeId = ref<number | null>(null)
const bindWorkflowId = ref<number | null>(null)
const isBindingWorkflow = ref(false)

function openBindWorkflow(episodeId: number) {
  if (selectedSeriesWorkflowLocked.value) return ElMessage.warning(workflowLockedMessage.value)
  bindWorkflowEpisodeId.value = episodeId
  bindWorkflowId.value = preferredWorkflowId.value
  bindWorkflowVisible.value = true
}

async function confirmBindWorkflow() {
  if (!bindWorkflowEpisodeId.value) return
  if (!bindWorkflowId.value) return ElMessage.warning(t('请选择一个剧集工作流'))

  isBindingWorkflow.value = true
  try {
    const updated = await updateEpisode(bindWorkflowEpisodeId.value, {
      workflow_id: bindWorkflowId.value,
    })
    applyEpisodeUpdate(updated)
    bindWorkflowVisible.value = false
    ElMessage.success(t('工作流已绑定'))
  } finally {
    isBindingWorkflow.value = false
  }
}

const activeWorkflowRunStatusText = computed(() => {
  const run = activeWorkflowRun.value
  if (!run) return ''
  const map: Record<string, string> = {
    queued: t('排队中'),
    running: t('解析中'),
    success: t('已完成'),
    failed: t('失败'),
    cancelled: t('已取消'),
  }
  return map[run.status] ?? run.status
})

function workflowRunNodeStatusText(status: string): string {
  const map: Record<string, string> = {
    queued: t('待处理'),
    running: t('进行中'),
    success: t('完成'),
    failed: t('失败'),
    skipped: t('跳过'),
    stale: t('需重生成'),
    cancelled: t('已取消'),
  }
  return map[status] ?? status
}

const activeWorkflowRunIsBusy = computed(() => {
  const status = activeWorkflowRun.value?.status
  return status === 'queued' || status === 'running'
})

const activeWorkflowRunBlocksEditing = computed(() => {
  const status = activeWorkflowRun.value?.status
  return status === 'queued' || status === 'running' || status === 'failed'
})

const activeWorkflowRunPercent = computed(() => {
  const run = activeWorkflowRun.value
  if (!run) return 0
  if (run.status === 'queued') return Math.max(2, run.progress || 2)
  return Math.max(0, Math.min(100, run.progress || 0))
})

const selectedSeriesWorkflowLocked = computed(() => {
  const run = activeWorkflowRun.value
  if (selectedEpisodeAutoExecutionLocked.value) return true
  if (!run || !activeWorkflowRunBlocksEditing.value || !selectedSeriesId.value) return false
  return run.series_id === selectedSeriesId.value
})

const selectedSeriesWorkflowBusyLocked = computed(() => {
  const run = activeWorkflowRun.value
  if (!run || !activeWorkflowRunIsBusy.value || !selectedSeriesId.value) return false
  return run.series_id === selectedSeriesId.value
})

const selectedEpisodeAutoExecutionLocked = computed(() => {
  return !!selectedEpisode.value?.workflow_state?.auto_execution_locked
})

const workflowLockedMessage = computed(() =>
  selectedEpisodeAutoExecutionLocked.value
    ? t('本集正在后台自动执行，暂时不能手动改节点、重跑镜头或更换工作流')
    : (activeWorkflowRun.value?.status === 'failed'
      ? t('作品解析失败，请先继续执行或删除后重建')
      : t('作品正在解析，完成后才能修改剧集或资产')),
)

const episodeWorkflowOptions = computed(() =>
  workflows.value
    .filter((w) => w.scope === 'episode')
    .map((w) => ({
      id: w.id,
      label: w.is_default ? t('{name}（系统默认）', { name: w.name }) : w.name,
    })),
)

const seriesWorkflowOptions = computed(() =>
  workflows.value
    .filter((w) => w.scope === 'series')
    .map((w) => ({
    id: w.id,
    label: w.is_default ? t('{name}（系统默认）', { name: w.name }) : w.name,
  })),
)

const preferredWorkflowId = computed<number | null>(() => {
  const def = workflows.value.find((w) => w.scope === 'episode' && Number(w.is_default) === 1)
  if (def) return def.id
  return episodeWorkflowOptions.value.length > 0 ? episodeWorkflowOptions.value[0].id : null
})

const preferredSeriesWorkflowId = computed<number | null>(() => {
  const options = seriesWorkflowOptions.value
  if (options.length > 0) return options[0].id
  return null
})

const selectedDetailWorkflowId = computed<number | null>(() => {
  return selectedEpisode.value?.workflow_id ?? null
})

const selectedDetailWorkflow = computed<Workflow | null>(() => {
  const id = selectedDetailWorkflowId.value
  if (!id) return null
  return workflows.value.find((w) => w.id === id) ?? null
})

const episodeNodeStateMap = computed(() => {
  const map = new Map<string, NonNullable<Episode['workflow_state']>['nodes'][number]>()
  for (const node of selectedEpisode.value?.workflow_state?.nodes ?? []) {
    map.set(node.workflow_node_id, node)
  }
  return map
})

const selectedSeriesWorkflowForCreation = computed(() => {
  if (!seriesForm.value.workflowId) return null
  return workflows.value.find((w) => w.id === seriesForm.value.workflowId) || null
})

const seriesWorkflowInputNode = computed(() => {
  const wf = selectedSeriesWorkflowForCreation.value
  if (!wf || !wf.graph || !wf.graph.nodes) return null
  return (wf.graph.nodes as any[]).find((n) => n.data?.kind === 'input' || n.kind === 'input') || null
})

const getSeriesWorkflowInputLabel = computed(() => {
  const node = seriesWorkflowInputNode.value
  if (!node) return t('初始剧情输入（可选）')
  return node.label || node.data?.label || t('输入内容')
})

const getSeriesWorkflowInputDesc = computed(() => {
  const node = seriesWorkflowInputNode.value
  if (!node) return t('可选：创建后自动作为第一集输入节点内容')
  return node.data?.desc || node.desc || t('请输入内容')
})

const detailNodes = computed<DetailNodeRow[]>(() => {
  const wf = selectedDetailWorkflow.value
  const nodes = orderWorkflowNodes(wf?.graph)
  return nodes.map((n, idx) => {
    const id = String(n.id ?? `node-${idx}`)
    const state = episodeNodeStateMap.value.get(id)
    const kind = String(n.data?.kind ?? 'node')
    const params = (n.data?.params ?? {}) as Record<string, any>
    return {
      id,
      title: String(n.label ?? n.data?.label ?? t('节点{number}', { number: idx + 1 })),
      kind,
      desc: kind === 'input'
        ? t('使用当前剧集的剧情简介作为输入')
        : String(n.data?.desc ?? n.data?.params?.prompt ?? t('暂无描述')),
      inputText: kind === 'input'
        ? (selectedEpisode.value?.plot_input ?? '')
        : undefined,
      outputText:
        kind === 'input' || executingNodeId.value === id
          ? undefined
        : formatNodeOutput(state),
      outputJson: state?.output_json,
      prompt: typeof params.prompt === 'string' ? params.prompt : '',
      promptTemplateId: Number(params.promptTemplateId ?? params.prompt_template_id ?? 0) || null,
      executionMode: typeof params.executionMode === 'string' ? params.executionMode : '',
      status:
        executingNodeId.value === id
          ? 'running'
          : (state?.status ?? 'queued'),
      errorMessage: state?.error_message,
    }
  })
})

function downstreamNodesFrom(nodeId: string | null): DetailNodeRow[] {
  const rows = detailNodes.value
  if (nodeId === null) return rows
  const index = rows.findIndex((node) => node.id === nodeId)
  return index >= 0 ? rows.slice(index + 1) : []
}

function localizedWorkflowNodeTitle(title: string): string {
  const trimmed = title.trim()
  return trimmed ? t(trimmed) : trimmed
}

function summarizeDownstreamImpact(
  nodeId: string | null,
  options: { changedShotIndex?: number; onlyKinds?: string[] } = {},
) {
  const downstream = downstreamNodesFrom(nodeId)
  const filteredDownstream = options.onlyKinds?.length
    ? downstream.filter((node) => options.onlyKinds!.includes(node.kind))
    : downstream
  const downstreamTitles = filteredDownstream.map((node) => localizedWorkflowNodeTitle(node.title))
  const successfulNodes = filteredDownstream.filter((node) => node.status === 'success')
  const shots = selectedEpisode.value?.shots ?? []
  const targetShots = options.changedShotIndex && options.changedShotIndex > 0
    ? shots.filter((shot) => Number(shot?.index ?? shot?.id ?? 0) === options.changedShotIndex)
    : shots
  const imageCount = filteredDownstream.some((node) => node.kind === 'image')
    ? targetShots.filter((shot) => String(shot?.image_url || '').trim() !== '').length
    : 0
  const videoCount = filteredDownstream.some((node) => node.kind === 'video')
    ? targetShots.filter((shot) => String(shot?.video_url || '').trim() !== '').length
    : 0
  const outputCount = filteredDownstream.filter((node) => node.kind === 'output' && node.status === 'success').length
  return {
    downstream: filteredDownstream,
    downstreamTitles,
    successfulNodes,
    imageCount,
    videoCount,
    outputCount,
    hasImpact: filteredDownstream.length > 0 && (successfulNodes.length > 0 || imageCount > 0 || videoCount > 0 || outputCount > 0),
  }
}

function buildImpactMessageHtml(
  subject: string,
  nodeId: string | null,
  lead: string,
  options: { changedShotIndex?: number; onlyKinds?: string[]; closingLine?: string } = {},
) {
  const impact = summarizeDownstreamImpact(nodeId, options)
  const lines = [lead]
  if (impact.downstreamTitles.length) {
    lines.push(t('会影响的下游节点：{nodes}', { nodes: impact.downstreamTitles.join('、') }))
  }
  const artifactParts = []
  if (impact.imageCount > 0) artifactParts.push(t('{count} 张画面', { count: impact.imageCount }))
  if (impact.videoCount > 0) artifactParts.push(t('{count} 段视频', { count: impact.videoCount }))
  if (impact.outputCount > 0) artifactParts.push(t('{count} 个输出结果', { count: impact.outputCount }))
  if (artifactParts.length) {
    lines.push(t('当前下游产物会失效：{items}', { items: artifactParts.join('、') }))
  }
  lines.push(options.closingLine || t('旧产物会从“当前结果”中移除，并标记为需要重新生成。'))
  return {
    hasImpact: impact.hasImpact,
    html: lines.map(escapeHtml).join('<br>'),
  }
}

function escapeHtml(value: string): string {
  return value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}

function buildStoryboardRegeneratePromptHtml(nodeId: string, shotIndex: number) {
  const impact = summarizeDownstreamImpact(nodeId, {
    changedShotIndex: shotIndex,
    onlyKinds: ['video', 'output'],
  })
  const artifactParts = []
  if (impact.videoCount > 0) artifactParts.push(t('{count} 段视频', { count: impact.videoCount }))
  if (impact.outputCount > 0) artifactParts.push(t('{count} 个输出结果', { count: impact.outputCount }))
  const downstreamText = impact.downstreamTitles.length
    ? impact.downstreamTitles.join(' / ')
    : t('无已完成下游节点')
  const staleText = artifactParts.length
    ? artifactParts.join(' / ')
    : t('暂无当前产物会失效')

  return {
    hasImpact: impact.hasImpact,
    html: `
      <div class="storyboard-regenerate-message">
        <div class="storyboard-regenerate-message__hero">
          <span class="storyboard-regenerate-message__mark">AI</span>
          <div>
            <strong>${escapeHtml(t('只替换当前镜头'))}</strong>
            <p>${escapeHtml(t('AI 会根据你的补充要求重写镜头 {index}，不会重新规划整套分镜。', { index: shotIndex }))}</p>
          </div>
        </div>
        <div class="storyboard-regenerate-message__grid">
          <div>
            <span>${escapeHtml(t('影响范围'))}</span>
            <strong>${escapeHtml(staleText)}</strong>
          </div>
          <div>
            <span>${escapeHtml(t('保留内容'))}</span>
            <strong>${escapeHtml(t('其他分镜文本、已生成图片'))}</strong>
          </div>
          <div>
            <span>${escapeHtml(t('下游节点'))}</span>
            <strong>${escapeHtml(downstreamText)}</strong>
          </div>
        </div>
        <div class="storyboard-regenerate-message__field">
          <span>${escapeHtml(t('本次修改要求'))}</span>
          <p>${escapeHtml(t('写清楚这次想改什么；留空则由 AI 在保持上下文连贯的前提下优化。'))}</p>
        </div>
      </div>
    `,
  }
}

function shouldConfirmNodeRun(node: DetailNodeRow): boolean {
  if (node.status === 'success' || node.status === 'stale') {
    return true
  }
  return summarizeDownstreamImpact(node.id).hasImpact
}

function savedPlotInputForSelectedEpisode() {
  const episode = selectedEpisode.value
  if (!episode) return ''
  const cached = episodeDetailCache.get(episode.id)
  if (cached) {
    return String(cached.plot_input ?? '')
  }
  const card = selectedSeries.value?.episodes.find((item) => item.id === episode.id)
  return String(card?.plot_input ?? '')
}

async function confirmPlotInputSave() {
  const episode = selectedEpisode.value
  if (!episode) return
  const nextPlotInput = String(episode.plot_input ?? '')
  const previousPlotInput = savedPlotInputForSelectedEpisode()
  if (nextPlotInput === previousPlotInput) {
    return
  }

  const impact = buildImpactMessageHtml(
    '剧情输入',
    null,
    t('保存后会按新的剧情输入重新计算整条流程。'),
  )
  await ElMessageBox.confirm(
    impact.html,
    t('保存剧情输入'),
    {
      type: impact.hasImpact ? 'warning' : 'info',
      confirmButtonText: t('继续保存'),
      cancelButtonText: t('取消'),
      dangerouslyUseHTMLString: true,
    },
  )
}

function episodeHasActiveWorkflowNode(episode: Episode | null | undefined): boolean {
  const status = episode?.workflow_state?.status
  return episode?.workflow_state?.auto_execution_locked === true || status === 'queued' || status === 'running'
}

function stopEpisodeWorkflowPoll() {
  if (episodeWorkflowPollTimer !== null) {
    clearInterval(episodeWorkflowPollTimer)
    episodeWorkflowPollTimer = null
  }
}

function syncEpisodeWorkflowPoll() {
  if (!episodeHasActiveWorkflowNode(selectedEpisode.value)) {
    stopEpisodeWorkflowPoll()
    return
  }
  if (episodeWorkflowPollTimer !== null) {
    return
  }
  episodeWorkflowPollTimer = window.setInterval(() => {
    void refreshSelectedEpisodeWorkflowState()
  }, 3000)
}

async function refreshSelectedEpisodeWorkflowState() {
  const episodeId = selectedEpisodeId.value
  if (!episodeId) return
  try {
    const episode = await getEpisode(episodeId, { silent: true })
    applyEpisodeUpdate(episode)
    if (!episodeHasActiveWorkflowNode(episode)) {
      executingNodeId.value = null
      stopEpisodeWorkflowPoll()
    }
  } catch {
    // 轮询失败时保留当前展示，下次间隔再试（silent：不弹全局超时）
  }
}

function orderWorkflowNodes(graph: Workflow['graph'] | undefined): Array<Record<string, any>> {
  const nodes = (graph?.nodes ?? []) as Array<Record<string, any>>
  const edges = (graph?.edges ?? []) as Array<Record<string, any>>
  if (nodes.length <= 1 || edges.length === 0) return nodes

  const nodeMap = new Map<string, Record<string, any>>()
  const inDegree = new Map<string, number>()
  const adjacency = new Map<string, string[]>()
  const originalIndex = new Map<string, number>()

  nodes.forEach((node, index) => {
    const id = String(node.id ?? '')
    if (!id) return
    nodeMap.set(id, node)
    inDegree.set(id, 0)
    adjacency.set(id, [])
    originalIndex.set(id, index)
  })

  edges.forEach((edge) => {
    const source = String(edge.source ?? '')
    const target = String(edge.target ?? '')
    if (!nodeMap.has(source) || !nodeMap.has(target)) return
    adjacency.get(source)!.push(target)
    inDegree.set(target, (inDegree.get(target) ?? 0) + 1)
  })

  const queue = Array.from(nodeMap.keys())
    .filter((id) => (inDegree.get(id) ?? 0) === 0)
    .sort((a, b) => (originalIndex.get(a) ?? 0) - (originalIndex.get(b) ?? 0))
  const ordered: Record<string, any>[] = []

  while (queue.length > 0) {
    const id = queue.shift()!
    const node = nodeMap.get(id)
    if (node) ordered.push(node)

    const nextIds = (adjacency.get(id) ?? [])
      .slice()
      .sort((a, b) => (originalIndex.get(a) ?? 0) - (originalIndex.get(b) ?? 0))
    for (const nextId of nextIds) {
      const nextDegree = (inDegree.get(nextId) ?? 0) - 1
      inDegree.set(nextId, nextDegree)
      if (nextDegree === 0) {
        queue.push(nextId)
      }
    }
  }

  return ordered.length === nodes.length ? ordered : nodes
}

function formatNodeOutput(state: NonNullable<Episode['workflow_state']>['nodes'][number] | undefined): string {
  if (!state || (state.status !== 'success' && state.status !== 'running')) return ''
  if (state.kind === 'video' || state.kind === 'output') return ''

  const label = String(state.label || '')
  if (label.includes('资产提取') || (label.includes('资产') && label.includes('生成') && !label.includes('帧') && !label.includes('视频'))) {
    return formatAssetExtractionOutput(state.output_json, state.raw_output)
  }

  const raw = state.raw_output?.trim()
  if (raw) return raw

  const output = state.output_json
  if (!output || Object.keys(output).length === 0) return ''
  for (const key of ['text', 'content', 'result', 'script', 'plot', 'summary', 'note']) {
    const value = output[key]
    if (typeof value === 'string' && value.trim()) {
      return value.trim()
    }
  }
  return JSON.stringify(output, null, 2)
}

function formatAssetExtractionOutput(outputJson: unknown, rawOutput?: string | null): string {
  const output = (outputJson && typeof outputJson === 'object' && !Array.isArray(outputJson))
    ? outputJson as Record<string, any>
    : {}
  let assets: Array<Record<string, any>> = Array.isArray(output.assets)
    ? output.assets.filter((item) => item && typeof item === 'object')
    : []
  let looks: Array<Record<string, any>> = Array.isArray(output.character_looks)
    ? output.character_looks.filter((item) => item && typeof item === 'object')
    : []

  if (assets.length === 0 || looks.length === 0) {
    const raw = String(rawOutput || '').trim()
    if (raw) {
      try {
        const parsed = JSON.parse(raw)
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
          if (assets.length === 0 && Array.isArray(parsed.assets)) {
            assets = parsed.assets.filter((item: unknown) => item && typeof item === 'object')
          }
          if (looks.length === 0 && Array.isArray(parsed.character_looks)) {
            looks = parsed.character_looks.filter((item: unknown) => item && typeof item === 'object')
          }
        }
      } catch {
        // ignore malformed raw model output
      }
    }
  }

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

  const parts: string[] = []
  if (characters > 0) parts.push(t('人物 {count} 个', { count: characters }))
  if (scenes > 0) parts.push(t('场景 {count} 个', { count: scenes }))
  if (props > 0) parts.push(t('道具 {count} 个', { count: props }))
  if (other > 0) parts.push(t('其他资产 {count} 个', { count: other }))
  if (lookCount > 0) parts.push(t('人物造型 {count} 套', { count: lookCount }))
  if (parts.length === 0 && assets.length > 0) parts.push(t('资产 {count} 个', { count: assets.length }))
  if (parts.length === 0) return t('暂时没有读取到本集资产。')

  const writeParts: string[] = []
  const created = Number(output.assets_created ?? 0)
  const updated = Number(output.assets_updated ?? 0)
  const queued = Number(output.image_jobs_created ?? 0)
  const lookQueued = Number(output.look_image_jobs_created ?? 0)
  if (created > 0) writeParts.push(t('新增 {count}', { count: created }))
  if (updated > 0) writeParts.push(t('更新 {count}', { count: updated }))
  const fromLooks = Number(output.characters_synthesized_from_looks ?? 0)
  if (fromLooks > 0) writeParts.push(t('由造型补人物 {count}', { count: fromLooks }))
  if (queued > 0) writeParts.push(t('核心图排队 {count} 张', { count: queued }))
  if (lookQueued > 0) writeParts.push(t('造型图排队 {count} 张', { count: lookQueued }))

  const main = parts.join(' · ')
  return writeParts.length > 0 ? `${main}（${writeParts.join(' · ')}）` : main
}

const selectedDetailNode = computed(() => detailNodes.value.find((node) => node.id === selectedDetailNodeId.value) ?? null)

const rerunPromptTemplateOptions = computed(() =>
  promptTemplatesForNode(
    promptTemplates.value,
    'episode',
    rerunPromptForm.value.nodeKind as any,
    rerunPromptForm.value.nodeTitle,
  ),
)

const selectedRerunPromptTemplate = computed(() => {
  const id = Number(rerunPromptForm.value.templateId ?? 0)
  return id ? promptTemplates.value.find((tpl) => tpl.id === id) ?? null : null
})

const selectedRerunPromptIsSystem = computed(() =>
  !!selectedRerunPromptTemplate.value && Number(selectedRerunPromptTemplate.value.is_system) === 1,
)

const rerunPromptTextareaDisabled = computed(() =>
  rerunPromptForm.value.mode === 'template' && selectedRerunPromptIsSystem.value,
)

function applyRerunPromptTemplate(templateId: number | string | null) {
  const id = Number(templateId)
  rerunPromptForm.value.templateId = id > 0 ? id : null
  const tpl = id > 0 ? promptTemplates.value.find((item) => item.id === id) : null
  if (!tpl) {
    rerunPromptForm.value.prompt = ''
    return
  }
  if (Number(tpl.is_system) === 1) {
    rerunPromptForm.value.prompt = tpl.prompt
    rerunPromptForm.value.mode = 'template'
    return
  }
  rerunPromptForm.value.prompt = tpl.prompt
  rerunPromptForm.value.mode = 'template'
}

function openRerunPromptDialog(node: DetailNodeRow): Promise<NodePromptExecutionChoice | undefined> {
  const templateId = node.promptTemplateId ?? null
  const tpl = templateId ? promptTemplates.value.find((item) => item.id === templateId) ?? null : null
  rerunPromptForm.value = {
    nodeId: node.id,
    nodeTitle: node.title,
    nodeKind: node.kind,
    mode: 'template',
    templateId,
    prompt: tpl?.prompt ?? (node.prompt ?? ''),
  }
  rerunPromptDialogVisible.value = true
  return new Promise((resolve) => {
    rerunPromptResolver = resolve
  })
}

function cancelRerunPromptDialog() {
  rerunPromptDialogVisible.value = false
  rerunPromptResolver?.(undefined)
  rerunPromptResolver = null
}

function confirmRerunPromptDialog() {
  const form = rerunPromptForm.value
  if (form.mode === 'custom') {
    const prompt = form.prompt.trim()
    if (!prompt) {
      ElMessage.warning(t('请先填写本次提示词'))
      return
    }
    rerunPromptDialogVisible.value = false
    rerunPromptResolver?.({ prompt })
    rerunPromptResolver = null
    return
  }

  if (form.templateId && form.templateId > 0) {
    rerunPromptDialogVisible.value = false
    rerunPromptResolver?.({ templateId: form.templateId })
    rerunPromptResolver = null
    return
  }

  rerunPromptDialogVisible.value = false
  rerunPromptResolver?.({})
  rerunPromptResolver = null
}

function isAssetExtractionNode(node: DetailNodeRow | null | undefined) {
  const title = String(node?.title ?? '')
  if (!title) return false
  if (title.includes('分镜') && title.includes('资产')) return false
  return title.includes('资产提取')
    || (title.includes('资产') && title.includes('生成') && !title.includes('帧') && !title.includes('视频'))
}

async function confirmAssetExtractionRun(node: DetailNodeRow) {
  if (!shouldConfirmNodeRun(node)) return
  const impact = buildImpactMessageHtml(
    node.title,
    node.id,
    t('会重新整理本集的人物、场景、道具和人物造型，已有资产不会被删除。'),
  )
  await ElMessageBox.confirm(
    impact.html,
    node.status === 'success' ? t('重新整理本集资产') : t('整理本集资产'),
    {
      type: impact.hasImpact ? 'warning' : 'info',
      confirmButtonText: t('确认执行'),
      cancelButtonText: t('取消'),
      dangerouslyUseHTMLString: true,
    },
  )
}

async function confirmSystemManagedNodeRun(node: DetailNodeRow) {
  if (!shouldConfirmNodeRun(node)) return
  const impact = buildImpactMessageHtml(node.title, node.id, t('会按当前内容重新生成这一步。'))
  await ElMessageBox.confirm(
    impact.html,
    node.status === 'success' || node.status === 'stale' ? t('重新执行节点') : t('执行节点'),
    {
      type: impact.hasImpact ? 'warning' : 'info',
      confirmButtonText: t('确认执行'),
      cancelButtonText: t('取消'),
      dangerouslyUseHTMLString: true,
    },
  )
}

async function confirmEditableTextNodeRun(node: DetailNodeRow) {
  if (!shouldConfirmNodeRun(node)) return
  const impact = buildImpactMessageHtml(node.title, node.id, t('会按你刚刚选择的提示词重新生成这一步。'))
  await ElMessageBox.confirm(
    impact.html,
    node.status === 'success' || node.status === 'stale' ? t('重新执行节点') : t('执行节点'),
    {
      type: impact.hasImpact ? 'warning' : 'info',
      confirmButtonText: t('确认执行'),
      cancelButtonText: t('取消'),
      dangerouslyUseHTMLString: true,
    },
  )
}

async function loadAll() {
  isLoading.value = true
  try {
    await loadSeriesCatalog()
    if (selectedSeriesId.value) {
      await ensureDashboardData(selectedSeriesId.value)
      await syncSelectedEpisodeWithSeries()
    }
  } finally {
    isLoading.value = false
  }
}

async function loadSeriesCatalog() {
  const sData = await listSeries()
  seriesList.value = sData
  if (selectedSeriesId.value && !sData.some((series) => series.id === selectedSeriesId.value)) {
    backToGallery()
  }
  syncEpisodeCacheFromSeries()

  // 不再自动选中第一部作品：默认落地在作品库（gallery）
  restoreActiveWorkflowRunFromSeries()
}

async function ensureWorkflows() {
  if (workflowsLoaded.value) return
  workflows.value = await listWorkflows()
  workflowsLoaded.value = true
}

async function ensureBundles() {
  if (bundlesLoaded.value) return
  bundles.value = await listBundles()
  bundlesLoaded.value = true
}

async function ensurePromptTemplates() {
  if (promptTemplatesLoaded.value) return
  promptTemplates.value = await listPromptTemplates()
  promptTemplatesLoaded.value = true
}

async function ensureDashboardData(seriesId: number | null) {
  await Promise.all([
    ensureWorkflows(),
    ensureBundles(),
    ensurePromptTemplates(),
    loadSeriesAssets(seriesId),
  ])
}

async function loadSeriesAssets(seriesId: number | null, options: { silent?: boolean } = {}) {
  if (!seriesId) {
    seriesAssets.value = []
    stopSeriesAssetPoll()
    return
  }

  try {
    seriesAssets.value = await listAssets({ series_id: seriesId }, { silent: options.silent })
    syncSeriesAssetPoll()
  } catch (error) {
    if (options.silent) return
    throw error
  }
}

function seriesHasPendingImageJobs(assets: Asset[]): boolean {
  return assets.some((asset) =>
    (asset.image_jobs ?? []).some((job) => isAssetImageJobPending(job.status)),
  )
}

function stopSeriesAssetPoll() {
  if (seriesAssetPollTimer !== null) {
    clearInterval(seriesAssetPollTimer)
    seriesAssetPollTimer = null
  }
}

function syncSeriesAssetPoll() {
  if (!seriesHasPendingImageJobs(seriesAssets.value)) {
    stopSeriesAssetPoll()
    return
  }
  if (seriesAssetPollTimer !== null) return
  seriesAssetPollTimer = window.setInterval(() => {
    void loadSeriesAssets(selectedSeriesId.value, { silent: true })
  }, 4000)
}

function extractionNodeSignature(episode: Episode | null | undefined): string {
  return (episode?.workflow_state?.nodes ?? [])
    .filter((node) => isAssetExtractionNode({ title: node.label, id: node.workflow_node_id } as DetailNodeRow))
    .map((node) => `${node.workflow_node_id}:${node.status}`)
    .join('|')
}

function maybeRefreshAssetsAfterExtraction(previous: Episode | null | undefined, next: Episode) {
  const prevSig = extractionNodeSignature(previous)
  const nextSig = extractionNodeSignature(next)
  const extractionSucceeded = (next.workflow_state?.nodes ?? []).some((node) =>
    isAssetExtractionNode({ title: node.label, id: node.workflow_node_id } as DetailNodeRow)
    && node.status === 'success',
  )
  if (previous && nextSig !== prevSig && extractionSucceeded) {
    void loadSeriesAssets(next.series_id || selectedSeriesId.value)
  }
}

async function handleBatchGenerateCoreImages() {
  if (selectedSeriesWorkflowLocked.value) return ElMessage.warning(workflowLockedMessage.value)
  const seriesId = selectedSeriesId.value
  if (!seriesId) return ElMessage.warning(t('请先选择作品'))
  const missing = seriesAssets.value.filter((asset) => {
    const hasCore = (asset.images ?? []).some((img) => img.view_type === 'main' && String(img.url || '').trim() !== '')
    const pending = (asset.image_jobs ?? []).some((job) => job.view_type === 'main' && isAssetImageJobPending(job.status))
    return !hasCore && !pending
  }).length
  if (missing <= 0) {
    return ElMessage.info(t('当前作品没有缺少核心视图的资产'))
  }

  await ElMessageBox.confirm(
    t('将为 {count} 个缺少核心视图的资产排队生成。任务会逐个执行。', { count: missing }),
    t('批量生成核心视图'),
    {
      type: 'warning',
      confirmButtonText: t('开始排队'),
      cancelButtonText: t('取消'),
    },
  )

  batchGeneratingCore.value = true
  try {
    const groups = await listModelConfigs()
    const models = groups.find((group) => group.type === 'image')?.models ?? []
    const savedId = Number(localStorage.getItem(ASSET_IMAGE_MODEL_STORAGE_KEY) || 0)
    const modelId = (savedId > 0 && models.some((model) => model.id === savedId)
      ? savedId
      : (models.find((model) => model.is_default)?.id || models[0]?.id || 0))
    if (!modelId) {
      return ElMessage.warning(t('暂无可用图片模型，请联系管理员配置'))
    }
    const result = await batchGenerateCoreImages({
      series_id: seriesId,
      model_config_id: modelId,
    })
    ElMessage.success(t('已加入队列 {created} 个，跳过 {skipped} 个', {
      created: result.created,
      skipped: result.skipped_with_core + result.skipped_queued,
    }))
    await loadSeriesAssets(seriesId)
  } finally {
    batchGeneratingCore.value = false
  }
}

async function handleBatchCancelQueuedImageJobs() {
  if (selectedSeriesWorkflowLocked.value) return ElMessage.warning(workflowLockedMessage.value)
  const seriesId = selectedSeriesId.value
  if (!seriesId) return ElMessage.warning(t('请先选择作品'))
  const count = seriesAssets.value.reduce((total, asset) => {
    return total + (asset.image_jobs ?? []).filter((job) => isAssetImageJobQueued(job.status)).length
  }, 0)
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

  batchCancellingCore.value = true
  try {
    const result = await cancelAssetImageJobs({ series_id: seriesId })
    if (result.cancelled > 0) {
      ElMessage.success(t('已取消 {count} 个排队任务', { count: result.cancelled }))
    } else {
      ElMessage.info(t('没有可取消的排队任务'))
    }
    await loadSeriesAssets(seriesId)
  } finally {
    batchCancellingCore.value = false
  }
}

async function handleCancelAssetQueuedImageJobs(asset: Asset) {
  if (selectedSeriesWorkflowLocked.value) return ElMessage.warning(workflowLockedMessage.value)
  const hasQueued = (asset.image_jobs ?? []).some((job) => isAssetImageJobQueued(job.status))
  if (!hasQueued) {
    return ElMessage.info(t('该资产没有排队中的任务，或已开始生成'))
  }
  if (cancellingCoreAssetIds.value[asset.id]) return

  cancellingCoreAssetIds.value = { ...cancellingCoreAssetIds.value, [asset.id]: true }
  try {
    const result = await cancelAssetImageJobs({ asset_id: asset.id })
    if (result.cancelled > 0) {
      ElMessage.success(t('已取消 {count} 个排队任务', { count: result.cancelled }))
    } else {
      ElMessage.info(t('没有可取消的排队任务'))
    }
    await loadSeriesAssets(selectedSeriesId.value)
  } finally {
    cancellingCoreAssetIds.value = { ...cancellingCoreAssetIds.value, [asset.id]: false }
  }
}

async function refreshAssetsAfterNode(node: DetailNodeRow | null | undefined, seriesId?: number | null) {
  if (!isAssetExtractionNode(node)) return
  await loadSeriesAssets(seriesId ?? selectedSeriesId.value)
}

function openAssetEditor(asset: Asset, reference?: { assetImageId?: number | null }) {
  const latest = seriesAssets.value.find((item) => item.id === asset.id) ?? asset
  editingAsset.value = latest
  editingAssetImageId.value = reference?.assetImageId ? Number(reference.assetImageId) : null
  assetEditorVisible.value = true
}

async function handleAssetSaved(asset: Asset) {
  const index = seriesAssets.value.findIndex((item) => item.id === asset.id)
  if (index >= 0) {
    seriesAssets.value.splice(index, 1, asset)
  }
  if (!assetEditorVisible.value) {
    await loadSeriesAssets(selectedSeriesId.value)
  }
}

function syncEpisodeCacheFromSeries() {
  for (const series of seriesList.value) {
    for (const episode of series.episodes ?? []) {
      const cached = episodeDetailCache.get(episode.id)
      episodeDetailCache.set(episode.id, {
        ...(cached ?? episode),
        ...episode,
        shots: cached?.shots ?? episode.shots,
      })
    }
  }

  if (selectedEpisodeId.value && !selectedEpisode.value) {
    const cached = episodeDetailCache.get(selectedEpisodeId.value)
    if (cached) {
      selectedEpisode.value = cloneEpisode(cached)
    }
  }
}

const route = useRoute()
const router = useRouter()

/** 来自完整流程库「用这个生成」深链：/series?bundle=<id> → 打开新建向导并预选 */
async function applyWorkflowDeepLink() {
  const v = route.query.bundle
  const bid = Number(Array.isArray(v) ? v[0] : (v ?? ''))
  if (!bid) return
  await ensureBundles()
  wizardPreselectId.value = bundles.value.some((b) => b.id === bid) ? bid : bid
  wizardVisible.value = true
  const nextQuery = { ...route.query }
  delete nextQuery.bundle
  await router.replace({ query: nextQuery })
}

const plotInputRef = ref<{ focus?: () => void } | null>(null)
/** 深链指定的剧集段工作流 id（套餐生成时覆盖默认剧集工作流） */
const pendingEpisodeWorkflowId = ref<number | null>(null)

let pageReady = false
onMounted(async () => {
  await loadAll()
  pageReady = true
  restoreActiveWorkflowRun()
  await applyWorkflowDeepLink()
})
watch(() => route.query.bundle, (v) => {
  if (v) void applyWorkflowDeepLink()
})
onBeforeUnmount(() => {
  stopWorkflowRunStream()
  stopWorkflowRunPoll()
  stopEpisodeWorkflowPoll()
  stopSeriesAssetPoll()
  clearWorkflowContextSnapshot()
  clearWorkerPageContext('series')
})

// 解析进度只属于当前作品。切换作品或返回作品库时，立即解除旧任务绑定，
// 避免旧作品的顶部进度条残留在新作品页面。
let seriesSelectionPromise = Promise.resolve()
watch(selectedSeriesId, (seriesId, previousSeriesId) => {
  const runSeriesId = Number(activeWorkflowRun.value?.series_id || 0)
  if (runSeriesId > 0 && runSeriesId !== Number(seriesId || 0)) {
    stopWorkflowRunStream()
    stopWorkflowRunPoll()
    activeWorkflowRun.value = null
    localStorage.removeItem(WORKFLOW_RUN_STORAGE_KEY)
    clearWorkflowContextSnapshot()
  }
  if (seriesId && seriesId !== previousSeriesId) {
    void ensureActiveWorkflowRunSynced()
  }
  if (pageReady && seriesId !== previousSeriesId) {
    clearSelectedEpisode()
    seriesSelectionPromise = loadAll()
  }
}, { flush: 'sync' })

function stopWorkflowRunStream() {
  if (workflowRunSource !== null) {
    workflowRunSource.close()
    workflowRunSource = null
  }
}

function stopWorkflowRunPoll() {
  if (workflowRunPollTimer !== null) {
    clearInterval(workflowRunPollTimer)
    workflowRunPollTimer = null
  }
}

function startWorkflowRunPoll(runId: number) {
  if (runId <= 0) return
  stopWorkflowRunPoll()
  workflowRunPollTimer = setInterval(() => {
    void refreshWorkflowRunStatus(runId, true)
  }, 4000)
}

async function refreshWorkflowRunStatus(runId: number, silent = false) {
  if (runId <= 0) return
  try {
    const run = await getWorkflowRun(runId, { silent: true })
    if (!selectedSeriesId.value || Number(run.series_id) !== Number(selectedSeriesId.value)) {
      return
    }
    if (silent) {
      applyWorkflowRunSnapshot(run)
      if (run.status === 'success' || run.status === 'failed' || run.status === 'cancelled') {
        stopWorkflowRunPoll()
        if (run.status === 'success' || run.status === 'cancelled') {
          stopWorkflowRunStream()
          localStorage.removeItem(WORKFLOW_RUN_STORAGE_KEY)
          if (run.status === 'cancelled') {
            activeWorkflowRun.value = null
            clearWorkflowContextSnapshot()
          }
        }
      }
      return
    }
    await handleWorkflowRunUpdate(run)
  } catch {
    // 保持当前遮罩，等待下一次轮询或 SSE
  }
}

function applyWorkflowRunSnapshot(run: WorkflowRunDetail) {
  activeWorkflowRun.value = {
    id: run.id,
    series_id: run.series_id,
    workflow_id: run.workflow_id,
    episode_workflow_id: run.episode_workflow_id ?? null,
    status: run.status,
    progress: run.progress,
    current_node_label: run.current_node_label ?? '',
    error_message: run.error_message ?? '',
    result_json: run.result_json ?? {},
    nodes: run.nodes ?? [],
  }
  syncWorkflowContextSnapshot()
}

function isBlockingWorkflowRunStatus(status?: string): boolean {
  return status === 'queued' || status === 'running' || status === 'failed'
}

async function handleWorkflowRunUpdate(run: WorkflowRunDetail) {
  // SSE 可能在切换作品后仍收到旧任务的最后一条事件，不能重新污染当前页面。
  if (!selectedSeriesId.value || Number(run.series_id) !== Number(selectedSeriesId.value)) {
    return
  }
  activeWorkflowRun.value = run
  syncWorkflowContextSnapshot()

  if (run.status === 'success') {
    stopWorkflowRunStream()
    stopWorkflowRunPoll()
    localStorage.removeItem(WORKFLOW_RUN_STORAGE_KEY)
    clearWorkflowContextSnapshot()
    const episodes = run.result_json?.episodes_written ?? 0
    const message = t('写入 {count} 集；资产将在每集扩写后增量补齐', { count: episodes })
    ElMessage.success(t('作品解析完成：{message}', { message }))
    notifyTask({
      title: t('制片助理有新进展'),
      message,
      type: 'success',
      tag: `workflow-run-${run.id}`,
    })
    await loadAll()
    return
  }

  if (run.status === 'failed') {
    stopWorkflowRunStream()
    stopWorkflowRunPoll()
    localStorage.removeItem(WORKFLOW_RUN_STORAGE_KEY)
    syncWorkflowContextSnapshot()
    const message = run.error_message || t('未知错误')
    ElMessage.error(t('作品解析失败：{message}', { message }))
    notifyTask({
      title: t('制片助理需要处理'),
      message,
      type: 'error',
      tag: `workflow-run-${run.id}`,
    })
  }
}

async function handleResumeWorkflowRun() {
  const runId = activeWorkflowRun.value?.id
  if (!runId) return
  try {
    const run = await resumeWorkflowRun(runId)
    activeWorkflowRun.value = run
    syncWorkflowContextSnapshot()
    ElMessage.success(t('已重新提交，会从未完成的位置继续'))
    startWorkflowRunStream(runId)
  } catch {
    ElMessage.error(t('继续执行失败，请稍后重试'))
  }
}

async function handleCancelWorkflowRun() {
  const runId = activeWorkflowRun.value?.id
  if (!runId) return
  await ElMessageBox.confirm(t('确定取消当前解析任务？已经生成的剧集会保留。'), t('取消任务'), {
    type: 'warning',
    confirmButtonText: t('取消任务'),
    cancelButtonText: t('返回'),
  })
  try {
    const run = await cancelWorkflowRun(runId)
    stopWorkflowRunStream()
    stopWorkflowRunPoll()
    activeWorkflowRun.value = null
    localStorage.removeItem(WORKFLOW_RUN_STORAGE_KEY)
    clearWorkflowContextSnapshot()
    ElMessage.success(t('任务已取消'))
    await loadAll()
    if (run.status !== 'cancelled') {
      activeWorkflowRun.value = run
    }
  } catch {
    ElMessage.error(t('取消任务失败，请稍后重试'))
  }
}

function startWorkflowRunStream(runId: number) {
  stopWorkflowRunStream()
  localStorage.setItem(WORKFLOW_RUN_STORAGE_KEY, String(runId))
  if (typeof EventSource === 'undefined') {
    // 无 SSE 时不要清空遮罩，改走轮询保活
    startWorkflowRunPoll(runId)
    ElMessage.warning(t('当前浏览器无法实时接收进度，已改为定时刷新状态'))
    return
  }

  workflowRunSource = new EventSource(workflowRunStreamUrl(runId))
  workflowRunSource.addEventListener('workflow_run', (event) => {
    try {
      const run = JSON.parse((event as MessageEvent).data) as WorkflowRunDetail
      stopWorkflowRunPoll()
      void handleWorkflowRunUpdate(run)
    } catch {
      ElMessage.error(t('进度更新失败，请刷新页面'))
    }
  })
  workflowRunSource.onerror = () => {
    if (activeWorkflowRun.value?.status === 'success' || activeWorkflowRun.value?.status === 'failed') {
      stopWorkflowRunPoll()
      return
    }
    // SSE 断线重连期间用轮询兜底，避免遮罩偶发消失
    startWorkflowRunPoll(runId)
  }
}

function restoreActiveWorkflowRun() {
  void ensureActiveWorkflowRunSynced()
}

function syncWorkflowContextSnapshot() {
  const run = activeWorkflowRun.value
  if (!run) {
    clearWorkflowContextSnapshot()
    return
  }
  try {
      localStorage.setItem(
      WORKFLOW_CONTEXT_STORAGE_KEY,
      JSON.stringify({
        run_id: run.id,
        series_id: run.series_id,
        series_title: selectedSeries.value?.title ?? '',
        workflow_id: run.workflow_id,
        episode_workflow_id: run.episode_workflow_id ?? null,
        status: run.status,
        progress: run.progress,
        current_node_label: run.current_node_label ?? '',
        error_message: run.error_message ?? '',
        result_json: run.result_json ?? {},
        nodes: (run.nodes ?? []).map((node) => ({
          id: node.id,
          workflow_node_id: node.workflow_node_id,
          label: node.label,
          kind: node.kind,
          status: node.status,
          error_message: node.error_message,
        })),
      }),
    )
  } catch {
    // best effort
  }
}

function clearWorkflowContextSnapshot() {
  try {
    localStorage.removeItem(WORKFLOW_CONTEXT_STORAGE_KEY)
  } catch {
    // best effort
  }
}

function restoreActiveWorkflowRunFromSeries() {
  void ensureActiveWorkflowRunSynced()
}

/** 从作品列表 / localStorage / 详情接口回补锁定态，避免 SSE 断线或切页后遮罩丢失。 */
async function ensureActiveWorkflowRunSynced() {
  const seriesId = Number(selectedSeriesId.value || 0)
  if (seriesId <= 0) return

  const fromList = selectedSeries.value?.active_workflow_run
  if (
    fromList
    && Number(fromList.series_id) === seriesId
    && isBlockingWorkflowRunStatus(fromList.status)
  ) {
    applyWorkflowRunSnapshot({
      id: fromList.id,
      series_id: fromList.series_id,
      workflow_id: fromList.workflow_id,
      episode_workflow_id: fromList.episode_workflow_id ?? null,
      status: fromList.status,
      progress: fromList.progress,
      current_node_label: fromList.current_node_label ?? '',
      error_message: fromList.error_message ?? '',
      result_json: fromList.result_json ?? {},
      nodes: fromList.nodes ?? [],
    })
    if (fromList.status === 'queued' || fromList.status === 'running') {
      startWorkflowRunStream(fromList.id)
    } else {
      stopWorkflowRunStream()
      stopWorkflowRunPoll()
    }
    return
  }

  const raw = localStorage.getItem(WORKFLOW_RUN_STORAGE_KEY)
  const storedRunId = raw ? Number(raw) : 0
  const currentRunId = Number(activeWorkflowRun.value?.id || 0)
  const runId = storedRunId > 0 ? storedRunId : currentRunId
  if (runId <= 0) {
    if (activeWorkflowRun.value && Number(activeWorkflowRun.value.series_id) === seriesId && isBlockingWorkflowRunStatus(activeWorkflowRun.value.status)) {
      return
    }
    return
  }

  try {
    const run = await getWorkflowRun(runId)
    if (Number(run.series_id) !== seriesId) {
      return
    }
    if (!isBlockingWorkflowRunStatus(run.status)) {
      if (run.status === 'success' || run.status === 'cancelled') {
        stopWorkflowRunStream()
        stopWorkflowRunPoll()
        activeWorkflowRun.value = null
        localStorage.removeItem(WORKFLOW_RUN_STORAGE_KEY)
        clearWorkflowContextSnapshot()
      }
      return
    }
    applyWorkflowRunSnapshot(run)
    if (run.status === 'queued' || run.status === 'running') {
      startWorkflowRunStream(run.id)
    }
  } catch {
    // 保留现有遮罩
  }
}

let episodeSelectionRequest = 0
watch(selectedEpisodeId, (episodeId, previousEpisodeId) => {
  executingNodeId.value = null
  clearVideoPromptPreviewState()
  if (!pageReady || episodeId === previousEpisodeId) return
  const series = selectedSeries.value
  if (!series || !episodeId) {
    selectedEpisode.value = null
    return
  }
  if (!series.episodes.some((episode) => episode.id === episodeId)) {
    selectedEpisodeId.value = null
    return
  }
  void selectEpisode(episodeId).catch(() => {
    if (selectedEpisodeId.value === episodeId) selectedEpisode.value = null
  })
})

watch(
  () => selectedEpisode.value?.workflow_state?.nodes?.map((n) => `${n.workflow_node_id}:${n.status}`).join('|') ?? '',
  () => {
    syncEpisodeWorkflowPoll()
    const nodeId = selectedDetailNodeId.value
    const node = nodeId ? detailNodes.value.find((item) => item.id === nodeId) ?? null : null
    if (node?.kind === 'video' && node.status !== 'success' && node.status !== 'running') {
      clearVideoPromptPreviewState()
    }
  },
)

watch(
  [selectedDetailWorkflowId, detailNodes],
  () => {
    const rows = detailNodes.value
    if (rows.length === 0) {
      selectedDetailNodeId.value = null
      return
    }
    const exists = rows.some((r) => r.id === selectedDetailNodeId.value)
    if (!exists) {
      selectedDetailNodeId.value = rows[0].id
    }
  },
  { immediate: true },
)

async function pickSeries(id: number) {
  selectedSeriesId.value = id
  await seriesSelectionPromise
}

function clearSelectedEpisode() {
  selectedEpisodeId.value = null
  selectedEpisode.value = null
  selectedDetailNodeId.value = null
  clearVideoPromptPreviewState()
}

async function syncSelectedEpisodeWithSeries() {
  const s = selectedSeries.value
  if (!s || s.episodes.length === 0) {
    clearSelectedEpisode()
    return
  }

  const selectedBelongsToSeries = selectedEpisodeId.value
    ? s.episodes.some((ep) => ep.id === selectedEpisodeId.value)
    : false

  if (selectedBelongsToSeries && selectedEpisodeId.value) {
    const cached = episodeDetailCache.get(selectedEpisodeId.value)
    if (cached && !episodeNeedsDetailFetch(cached)) {
      selectedEpisode.value = cloneEpisode(cached)
      return
    }
    await selectEpisode(selectedEpisodeId.value)
    return
  }

  await selectEpisode(s.episodes[0].id)
}

function episodeNeedsDetailFetch(episode: Episode | null | undefined): boolean {
  if (!episode || !Array.isArray(episode.shots)) return true
  return episode.shots.some((shot) => !Array.isArray(shot.media_versions))
}

async function selectEpisode(id: number) {
  if (selectedEpisodeId.value === id && selectedEpisode.value && !episodeNeedsDetailFetch(selectedEpisode.value)) return
  const requestId = ++episodeSelectionRequest
  selectedEpisodeId.value = id
  const cached = episodeDetailCache.get(id)
  if (cached && !episodeNeedsDetailFetch(cached)) {
    selectedEpisode.value = cloneEpisode(cached)
    return
  }

  const card = selectedSeries.value?.episodes.find((ep) => ep.id === id)
  if (card) {
    const episode = cloneEpisode(card)
    episodeDetailCache.set(id, episode)
    selectedEpisode.value = cloneEpisode(episode)
  }

  const episode = await getEpisode(id)
  if (requestId !== episodeSelectionRequest || selectedEpisodeId.value !== id) return
  episodeDetailCache.set(id, episode)
  selectedEpisode.value = cloneEpisode(episode)
}

function cloneEpisode(ep: Episode): Episode {
  return {
    ...ep,
    shots: ep.shots
      ? ep.shots.map((shot) => ({
          ...shot,
          media_versions: Array.isArray(shot.media_versions)
            ? shot.media_versions.map((version) => ({ ...version }))
            : shot.media_versions,
        }))
      : ep.shots,
  }
}

function hydrateEpisodeWorkflowName(ep: Episode): Episode {
  const currentName = String(ep.workflow_name || '').trim()
  const workflowId = Number(ep.workflow_id || 0)
  if (currentName || workflowId <= 0) {
    return ep
  }

  const name = workflowNameById.value.get(workflowId)
  return name ? { ...ep, workflow_name: name } : ep
}

function applyEpisodeUpdate(updated: Episode) {
  const previous =
    selectedEpisode.value?.id === updated.id
      ? selectedEpisode.value
      : episodeDetailCache.get(updated.id)
  const merged: Episode = {
    ...(previous ?? updated),
    ...updated,
    shots: updated.shots ?? previous?.shots,
  }

  episodeDetailCache.set(updated.id, cloneEpisode(merged))
  if (selectedEpisodeId.value === updated.id) {
    selectedEpisode.value = cloneEpisode(merged)
  }
  maybeRefreshAssetsAfterExtraction(previous, merged)
  syncEpisodeWorkflowPoll()

  seriesList.value = seriesList.value.map((series) => ({
    ...series,
    episodes: series.episodes.map((episode) =>
      episode.id === updated.id
        ? {
            ...episode,
            ...merged,
            shots: merged.shots ?? episode.shots,
          }
        : episode,
    ),
  }))
}

async function openCreateSeries() {
  await ensureWorkflows()
  seriesForm.value = {
    id: 0,
    title: '',
    description: '',
    visualStyle: 'realistic',
    visualStyleVariant: '',
    region: 'china',
    createMode: 'manual',
    workflowId: preferredSeriesWorkflowId.value,
    seedPlotInput: '',
    sourceFileToken: '',
    sourceFilename: '',
    episodeCount: null,
  }
  pendingEpisodeWorkflowId.value = null
  seriesDialogVisible.value = true
}

function pickNovelFile() {
  if (isNovelImporting.value) return
  novelImportInputRef.value?.click()
}

async function onNovelFileChange(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (file) {
    await importNovelToInput(file)
  }
}

async function onNovelDrop(event: DragEvent) {
  isNovelDragOver.value = false
  const file = event.dataTransfer?.files?.[0]
  if (file) {
    await importNovelToInput(file)
  }
}

async function importNovelToInput(file: File) {
  const name = file.name || ''
  const ext = name.split('.').pop()?.toLowerCase()
  if (ext !== 'txt' && ext !== 'pdf') {
    return ElMessage.warning(t('只支持 TXT 和 PDF 文件'))
  }
  if (file.size > 100 * 1024 * 1024) {
    return ElMessage.warning(t('文件不能超过 100MB'))
  }

  isNovelImporting.value = true
  try {
    const { parseNovelFile } = await import('@/utils/novelImport')
    const result = await parseNovelFile(file)
    seriesForm.value.sourceFileToken = ''
    seriesForm.value.sourceFilename = result.filename
    seriesForm.value.seedPlotInput = result.text
    ElMessage.success(t('已导入 {filename}，共 {count} 字', { filename: result.filename, count: result.chars }))
  } finally {
    isNovelImporting.value = false
  }
}

function openEditSeries() {
  if (!selectedSeries.value) return
  seriesForm.value = {
    id: selectedSeries.value.id,
    title: selectedSeries.value.title,
    description: selectedSeries.value.description ?? '',
    visualStyle: (selectedSeries.value.visual_style as any) || 'realistic',
    visualStyleVariant: normalizeVisualStyleVariant(
      ((selectedSeries.value.visual_style as any) || 'realistic') as VisualStyle,
      selectedSeries.value.visual_style_variant,
    ),
    region: (selectedSeries.value.region as 'china' | 'western') || 'china',
    createMode: 'manual',
    workflowId: null,
    seedPlotInput: '',
    sourceFileToken: '',
    sourceFilename: '',
    episodeCount: null,
  }
  pendingEpisodeWorkflowId.value = null
  seriesDialogVisible.value = true
}

async function submitSeries() {
  if (isSeriesSubmitting.value) return
  isSeriesSubmitting.value = true
  try {
    const isEdit = seriesForm.value.id > 0

    if (isEdit) {
      if (!seriesForm.value.title.trim()) return ElMessage.warning(t('请输入作品名称'))
      await updateSeries(seriesForm.value.id, {
        title: seriesForm.value.title.trim(),
        description: seriesForm.value.description ?? '',
        visual_style: seriesForm.value.visualStyle,
        visual_style_variant: normalizeVisualStyleVariant(seriesForm.value.visualStyle, seriesForm.value.visualStyleVariant),
        region: seriesForm.value.region,
      })
      ElMessage.success(t('作品已更新'))
    } else {
      if (seriesForm.value.createMode === 'manual') {
        if (!seriesForm.value.title.trim()) return ElMessage.warning(t('请输入作品名称'))
        const created = await createSeries({
          title: seriesForm.value.title.trim(),
          description: seriesForm.value.description ?? '',
          visual_style: seriesForm.value.visualStyle,
          visual_style_variant: normalizeVisualStyleVariant(seriesForm.value.visualStyle, seriesForm.value.visualStyleVariant),
          region: seriesForm.value.region,
        })
        ElMessage.success(t('作品已创建'))
        await pickSeries(created.id)
      } else {
        if (seriesWorkflowOptions.value.length === 0) {
          return ElMessage.warning(t('请先创建一个作品解析流程'))
        }
        if (!preferredWorkflowId.value) {
          return ElMessage.warning(t('请先创建一个剧集生产流程'))
        }
        if (!seriesForm.value.workflowId) return ElMessage.warning(t('请选择工作流'))

        if (seriesWorkflowInputNode.value && !seriesForm.value.seedPlotInput.trim()) {
          return ElMessage.warning(t('请填写{label}', { label: getSeriesWorkflowInputLabel.value }))
        }

        const title = seriesForm.value.title.trim() || t('工作流作品 {time}', { time: new Date().toLocaleString() })
        const explicitEpisodeCount =
          typeof seriesForm.value.episodeCount === 'number' && seriesForm.value.episodeCount > 0
            ? Math.max(1, Math.min(100, Math.floor(seriesForm.value.episodeCount)))
            : null
        const seed = seriesForm.value.seedPlotInput.trim()
        const created = await createSeries({
          title,
          description: seriesForm.value.description || t('通过工作流创建'),
          source_text: seed || undefined,
          visual_style: seriesForm.value.visualStyle,
          visual_style_variant: normalizeVisualStyleVariant(seriesForm.value.visualStyle, seriesForm.value.visualStyleVariant),
          region: seriesForm.value.region,
          series_workflow_id: seriesForm.value.workflowId ?? null,
          ...(explicitEpisodeCount !== null ? { episode_count: explicitEpisodeCount } : {}),
        })
        pendingEpisodeWorkflowId.value = pendingEpisodeWorkflowId.value ?? preferredWorkflowId.value
        ElMessage.success(t('作品已创建，请先运行剧本解析，再逐步执行节点'))

      await pickSeries(created.id)
    }
  }
  seriesDialogVisible.value = false
  await loadAll()
  } finally {
    isSeriesSubmitting.value = false
  }
}

async function handleDeleteSeries() {
  if (!selectedSeries.value) return
  if (selectedSeriesWorkflowBusyLocked.value) return ElMessage.warning(workflowLockedMessage.value)
  await ElMessageBox.confirm(t('确定删除作品「{title}」及其剧集？', { title: selectedSeries.value.title }), t('删除作品'), {
    type: 'warning',
    confirmButtonText: t('删除'),
    cancelButtonText: t('取消'),
  })
  await deleteSeries(selectedSeries.value.id)
  ElMessage.success(t('作品已删除'))
  selectedSeriesId.value = null
  selectedEpisodeId.value = null
  selectedEpisode.value = null
  await loadAll()
}

function openCreateEpisode() {
  if (!selectedSeries.value) return ElMessage.warning(t('请先选择作品'))
  if (selectedSeriesWorkflowLocked.value) return ElMessage.warning(workflowLockedMessage.value)
  const usedNumbers = new Set((selectedSeries.value.episodes ?? []).map((ep) => ep.number))
  let nextNum = 1
  while (usedNumbers.has(nextNum)) {
    nextNum += 1
  }
  
  episodeForm.value = {
    seriesId: selectedSeries.value.id,
    title: '',
    number: nextNum,
    plotInput: '',
    promoSegmentCount: 3,
  }
  episodeDrawerVisible.value = true
}

function handleSeriesCreateModeChange(mode: 'manual' | 'workflow') {
  if (mode === 'workflow' && !seriesForm.value.workflowId) {
    seriesForm.value.workflowId = preferredSeriesWorkflowId.value
  }
}

function detailKindLabel(kind: string): string {
  const map: Record<string, string> = {
    input: t('输入'),
    text: t('文本'),
    image: t('图片'),
    video: t('视频'),
    voice: t('语音'),
    output: t('输出'),
  }
  return map[kind] ?? kind
}

async function submitEpisode() {
  if (selectedSeriesWorkflowLocked.value) return ElMessage.warning(workflowLockedMessage.value)
  if (!episodeForm.value.title.trim()) return ElMessage.warning(t('请输入剧集标题'))
  if (!episodeForm.value.plotInput.trim()) return ElMessage.warning(t('请输入剧情简介'))
  if (isEpisodeNumberUsed(episodeForm.value.number)) return ElMessage.warning(t('第 {number} 集已存在，请换一个剧集序号', { number: episodeForm.value.number }))

  const workflowId = createEpisodeWorkflowId.value
  const promoSegmentCount = isPromoSeriesForCreate.value
    ? Math.max(1, Math.min(4, Math.floor(Number(episodeForm.value.promoSegmentCount) || 3)))
    : null

  await createEpisode(episodeForm.value.seriesId, {
    title: episodeForm.value.title.trim(),
    number: episodeForm.value.number,
    plot_input: episodeForm.value.plotInput.trim(),
    ...(workflowId ? { workflow_id: workflowId } : {}),
    ...(promoSegmentCount !== null ? { promo_segment_count: promoSegmentCount } : {}),
  })
  ElMessage.success(t('剧集已添加'))
  episodeDrawerVisible.value = false
  await loadAll()
}

function isEpisodeNumberUsed(number: number): boolean {
  const series = selectedSeries.value
  if (!series) return false
  return (series.episodes ?? []).some((ep) => ep.number === number)
}

async function handleDeleteEpisode(ep: Episode) {
  if (selectedSeriesWorkflowLocked.value) return ElMessage.warning(workflowLockedMessage.value)
  if (ep.workflow_state?.auto_execution_locked) {
    return ElMessage.warning(t('当前剧集正在自动执行，请先取消自动执行后再删除'))
  }
  const title = (ep.title || '').trim() || t('未命名')
  await ElMessageBox.confirm(
    t('确定删除第 {number} 集「{title}」？该集的分镜、图片、视频任务会一并清除，且不可恢复。', {
      number: ep.number,
      title,
    }),
    t('删除剧集'),
    {
      type: 'warning',
      confirmButtonText: t('删除'),
      cancelButtonText: t('取消'),
    },
  )
  await deleteEpisode(ep.id)
  ElMessage.success(t('剧集已删除'))
  if (selectedEpisodeId.value === ep.id) {
    selectedEpisodeId.value = null
    selectedEpisode.value = null
  }
  await loadAll()
}

async function saveEpisodeDetail() {
  if (!selectedEpisode.value) return
  if (selectedSeriesWorkflowLocked.value) return ElMessage.warning(workflowLockedMessage.value)
  await confirmPlotInputSave()
  isSaving.value = true
  try {
    const updated = await updateEpisode(selectedEpisode.value.id, {
      plot_input: selectedEpisode.value.plot_input,
    })
    applyEpisodeUpdate(updated)
    ElMessage.success(t('剧情输入已保存'))
  } finally {
    isSaving.value = false
  }
}

async function runNextNode(options: { allowPending?: boolean } = {}) {
  if (selectedSeriesWorkflowLocked.value) return ElMessage.warning(workflowLockedMessage.value)
  if (!selectedEpisode.value) return ElMessage.warning(t('请先选择剧集'))
  if (!selectedEpisode.value.workflow_id) return ElMessage.warning(t('请先选择工作流'))
  if (!selectedDetailNodeId.value) return ElMessage.warning(t('请选择要执行的节点'))
  if (!selectedEpisode.value.plot_input?.trim()) return ElMessage.warning(t('请先填写剧情简介'))
  const currentNode = selectedDetailNode.value
  const nodeId = selectedDetailNodeId.value
  const episodeId = selectedEpisode.value.id
  if (
    executingNodeId.value === nodeId
    || currentNode?.status === 'running'
    || (!options.allowPending && pendingStageRunKeys.value.has(stageRunKey(episodeId, nodeId)))
  ) {
    return ElMessage.info(t('这个节点正在生成，请稍候'))
  }
  const wasRerun = currentNode?.status === 'success' || currentNode?.status === 'stale'
  let promptOverride: string | undefined
  let promptTemplateId: number | undefined
  if (isAssetExtractionNode(currentNode)) {
    await confirmAssetExtractionRun(currentNode)
  } else if (currentNode?.kind === 'text' && !canEditNodePrompt('episode', 'text', currentNode.title, currentNode.executionMode)) {
    if (isSystemManagedPromptNode('episode', 'text', currentNode.title)) {
      await confirmSystemManagedNodeRun(currentNode)
    }
  } else if (currentNode?.kind === 'text' && currentNode.executionMode !== 'local') {
    if (shouldConfirmNodeRun(currentNode)) {
      const pickedPrompt = await openRerunPromptDialog(currentNode)
      if (pickedPrompt === undefined) return
      promptOverride = pickedPrompt.prompt
      promptTemplateId = pickedPrompt.templateId ? Number(pickedPrompt.templateId) : undefined
      await confirmEditableTextNodeRun(currentNode)
    }
  }

  executingNodeId.value = nodeId
  isSaving.value = true
  try {
    setPendingStageRun(episodeId, nodeId, true)
    markEpisodeNodeRunning(episodeId, nodeId)
    const updated = await runEpisodeWorkflowNodeAsync(episodeId, nodeId, promptOverride, { promptTemplateId })
    applyEpisodeUpdate(updated)
    await refreshAssetsAfterNode(currentNode, updated.series_id ?? selectedSeriesId.value)
    if (currentNode?.kind === 'video') {
      ElMessage.success(t('{name}已提交后台生成', { name: currentNode?.title ?? t('视频节点') }))
    } else {
      ElMessage.success(wasRerun
        ? t('{name}已提交后台重新生成', { name: currentNode?.title ?? t('节点') })
        : t('{name}已提交后台生成', { name: currentNode?.title ?? t('节点') }))
    }
  } catch {
    executingNodeId.value = null
  } finally {
    isSaving.value = false
    setPendingStageRun(episodeId, nodeId, false)
    if (!episodeHasActiveWorkflowNode(selectedEpisode.value)) {
      executingNodeId.value = null
    }
  }
}

</script>

<template>
  <div class="series-v2">
    <WorksGallery
      v-if="view === 'gallery'"
      :series="seriesList"
      :loading="isLoading"
      @open="pickSeries"
      @new="openWizard"
    />

    <NewWorkWizard
      v-if="wizardVisible"
      v-model:visible="wizardVisible"
      :bundles="bundles"
      :preselect-id="wizardPreselectId"
      @submit="handleWizardSubmit"
    />

    <template v-if="view !== 'gallery'">
    <header class="page-top">
      <div class="page-top__head">
        <div>
          <h1>{{ selectedSeries?.title || t('作品') }}</h1>
          <p>{{ t('管理这部作品的剧集、生产进度与产物。') }}</p>
        </div>
      </div>
      <div class="top-actions">
        <el-tooltip
          :disabled="!selectedSeriesWorkflowLocked"
          :content="workflowLockedMessage"
          placement="bottom"
        >
          <span>
            <el-button :disabled="selectedSeriesWorkflowLocked" @click="$router.push({ name: 'assets' })">
              <el-icon><Box /></el-icon>
              <span>{{ t('资源包') }}</span>
            </el-button>
          </span>
        </el-tooltip>
        <el-button
          type="danger"
          plain
          :disabled="!selectedSeries || selectedSeriesWorkflowBusyLocked"
          @click="handleDeleteSeries"
        >
          <el-icon><Delete /></el-icon>
          <span>{{ t('删除作品') }}</span>
        </el-button>
        <el-button type="primary" @click="openWizard">
          <el-icon><Plus /></el-icon>
          <span>{{ t('新建作品') }}</span>
        </el-button>
      </div>
    </header>

    <div v-if="selectedSeriesWorkflowLocked" class="workflow-lock-bar">
      <el-icon class="is-loading"><Loading /></el-icon>
      <span>{{ workflowLockedMessage }}</span>
    </div>

    <div
      v-if="activeWorkflowRun"
      class="workflow-run-panel"
      :class="`workflow-run-panel--${activeWorkflowRun.status}`"
    >
      <div class="workflow-run-panel__main">
        <div class="workflow-run-panel__title">
          <el-icon v-if="activeWorkflowRunIsBusy" class="is-loading"><Loading /></el-icon>
          <el-icon v-else-if="activeWorkflowRun.status === 'success'"><Check /></el-icon>
          <el-icon v-else><Warning /></el-icon>
          <strong>{{ t('作品解析 #{id}', { id: activeWorkflowRun.id }) }}</strong>
        </div>
        <span class="workflow-run-panel__badge">{{ activeWorkflowRunStatusText }}</span>
        <span v-if="activeWorkflowRun.current_node_label">{{ t('当前节点：{node}', { node: workflowDisplayLabel(activeWorkflowRun.current_node_label) }) }}</span>
        <el-button
          v-if="activeWorkflowRun.status === 'failed'"
          size="small"
          type="primary"
          plain
          @click="handleResumeWorkflowRun"
        >
          {{ t('继续执行') }}
        </el-button>
        <el-button
          v-if="activeWorkflowRun.status === 'queued' || activeWorkflowRun.status === 'running' || activeWorkflowRun.status === 'failed'"
          size="small"
          type="danger"
          plain
          @click="handleCancelWorkflowRun"
        >
          {{ t('取消任务') }}
        </el-button>
      </div>
      <el-progress
        :percentage="activeWorkflowRunPercent"
        :indeterminate="activeWorkflowRun.status === 'queued'"
        :status="activeWorkflowRun.status === 'failed' ? 'exception' : activeWorkflowRun.status === 'success' ? 'success' : undefined"
      />
      <div v-if="activeWorkflowRun.nodes.length" class="workflow-run-panel__nodes">
        <span
          v-for="node in activeWorkflowRun.nodes"
          :key="node.id"
          :class="`node-status node-status--${node.status}`"
        >
          {{ workflowDisplayLabel(node.label, node.kind) }}：{{ workflowRunNodeStatusText(node.status) }}
        </span>
      </div>
      <div
        v-for="node in activeWorkflowRun.nodes.filter((n) => n.status === 'failed' && (n.request_payload_json || n.ai_meta_json))"
        :key="`debug-${node.id}`"
        class="workflow-run-panel__debug"
      >
        <details open>
          <summary>{{ t('调试 · {node}（日志 #{id}）', { node: workflowDisplayLabel(node.label, node.kind), id: node.ai_request_log_id ?? '—' }) }}</summary>
          <p v-if="node.error_message" class="workflow-run-panel__debug-error">{{ workflowDisplayMessage(node.error_message) }}</p>
          <div v-if="node.request_payload_json" class="workflow-run-panel__debug-block">
            <div class="workflow-run-panel__debug-title">{{ t('请求参数 request_payload_json') }}</div>
            <pre>{{ JSON.stringify(node.request_payload_json, null, 2) }}</pre>
          </div>
          <div v-if="node.ai_meta_json" class="workflow-run-panel__debug-block">
            <div class="workflow-run-panel__debug-title">{{ t('AI 元数据 ai_meta_json') }}</div>
            <pre>{{ JSON.stringify(node.ai_meta_json, null, 2) }}</pre>
          </div>
          <div v-if="node.raw_output_preview" class="workflow-run-panel__debug-block">
            <div class="workflow-run-panel__debug-title">{{ t('模型原始输出 raw_output_preview') }}</div>
            <pre>{{ node.raw_output_preview }}</pre>
          </div>
        </details>
      </div>
      <p v-if="activeWorkflowRun.error_message" class="workflow-run-panel__error">{{ workflowDisplayMessage(activeWorkflowRun.error_message) }}</p>
    </div>

    <ProductionDashboard
      v-if="view === 'dashboard' && selectedSeries"
      :key="selectedSeriesId ?? 0"
      :series="selectedSeries"
      :episodes="dashboardEpisodes"
      :assets="seriesAssets"
      :batch-generating="batchGeneratingCore"
      :batch-cancelling="batchCancellingCore"
      :cancelling-asset-ids="cancellingCoreAssetIds"
      :bundles="bundles"
      :busy="selectedSeriesWorkflowBusyLocked"
      :locked-message="workflowLockedMessage"
      :running-episode-ids="pendingEpisodeRunIdList"
      :running-stage-keys="pendingStageRunKeyList"
      @back="backToGallery"
      @new="openCreateEpisode"
      @delete-episode="handleDeleteEpisode"
      @run-episode="runDashboardEpisode"
      @cancel-auto-run="cancelDashboardEpisodeAutoRun"
      @run-series-workflow="runDashboardSeriesWorkflow"
      @update-series-title="updateDashboardSeriesTitle"
      @update-plot="updateDashboardPlot"
      @run-stage="runDashboardStage"
      @cancel-stage="cancelDashboardStage"
      @run-video-shot="runDashboardVideoShot"
      @cancel-video-shot="cancelDashboardVideoShot"
      @rerun-video-shot="rerunDashboardVideoShot"
      @regenerate-storyboard-shot="regenerateDashboardStoryboardShot"
      @select-media-version="selectDashboardMediaVersion"
      @preview-video-prompts="previewDashboardVideoPrompts"
      @bind-workflow="openBindWorkflow"
      @edit-asset="openAssetEditor"
      @batch-generate-core-images="handleBatchGenerateCoreImages"
      @batch-cancel-queued-image-jobs="handleBatchCancelQueuedImageJobs"
      @cancel-asset-queued-image-jobs="handleCancelAssetQueuedImageJobs"
      @update-node-content="updateDashboardNodeContent"
    />

    <AssetEditorDialog
      v-if="assetEditorVisible || editingAsset"
      v-model="assetEditorVisible"
      :asset="editingAsset"
      :series-list="seriesList"
      :initial-image-id="editingAssetImageId"
      :locked="selectedSeriesWorkflowLocked"
      :locked-message="workflowLockedMessage"
      @saved="handleAssetSaved"
    />

    </template>

    <StoryboardRegenerateDialog
      :model-value="storyboardRegenerateDialogVisible"
      :title="storyboardRegenerateDialog.title"
      :impact-html="storyboardRegenerateDialog.impactHtml"
      :shot-index="storyboardRegenerateDialog.shotIndex"
      :assets="seriesAssets"
      @update:model-value="handleStoryboardRegenerateDialogVisibility"
      @confirm="resolveStoryboardRegenerateDialog"
    />

    <el-dialog
      v-model="videoPromptPreviewVisible"
      :title="t('视频提示词预览')"
      width="min(96vw, 980px)"
      class="custom-dialog video-prompt-preview-dialog"
      append-to-body
    >
      <div v-loading="videoPromptPreviewLoading" class="video-prompt-preview">
        <template v-if="videoPromptPreview">
          <div class="video-prompt-preview__summary">
            <strong>{{ videoPromptPreview.label }}</strong>
            <span>{{ t('{count} 个镜头', { count: videoPromptPreview.shot_count }) }}</span>
            <span>{{ t('参数：{value}', { value: formatVideoPreviewOptions(videoPromptPreview.video_options) }) }}</span>
          </div>
          <div class="video-prompt-preview__layout">
            <aside class="video-prompt-preview__shots">
              <button
                v-for="shot in videoPromptPreview.shots"
                :key="shot.index"
                type="button"
                :class="{ active: activeVideoPromptShot?.index === shot.index }"
                @click="activeVideoPromptShotIndex = shot.index"
              >
                <strong>{{ t('镜头 {number}', { number: shot.index }) }}</strong>
                <span>{{ shot.duration }}s</span>
                <em>{{ shot.description }}</em>
              </button>
            </aside>

            <section
              v-if="activeVideoPromptShot"
              class="video-prompt-shot"
            >
              <p class="video-prompt-shot__desc">{{ activeVideoPromptShot.description }}</p>
              <template v-if="videoPromptPreview.video_style_prompt">
                <div class="video-prompt-shot__section video-prompt-shot__section--aux">
                  <div class="video-prompt-shot__head">
                    <span>{{ t('视频前置提示词') }}</span>
                    <el-button size="small" @click="copyText(videoPromptPreview.video_style_prompt)">{{ t('复制') }}</el-button>
                  </div>
                  <div class="video-prompt-shot__prompt-scroll video-prompt-shot__prompt-scroll--aux">
                    <pre>{{ videoPromptPreview.video_style_prompt }}</pre>
                  </div>
                </div>
              </template>
              <div v-if="activeVideoPromptShot.reference_images.length" class="video-prompt-shot__refs">
                <span v-for="ref in activeVideoPromptShot.reference_images" :key="ref.alias">
                    {{ ref.alias }} · {{ ref.type || 'reference' }} · {{ ref.name || t('未命名') }}
                  </span>
              </div>
              <div class="video-prompt-shot__section video-prompt-shot__section--main">
                <div class="video-prompt-shot__head">
                  <span>{{ t('将发送给视频接口的提示词') }}</span>
                  <el-button size="small" @click="copyText(resolveVideoPromptPreviewText(activeVideoPromptShot))">{{ t('复制') }}</el-button>
                </div>
                <div class="video-prompt-shot__prompt-scroll video-prompt-shot__prompt-scroll--main">
                  <pre>{{ resolveVideoPromptPreviewText(activeVideoPromptShot) }}</pre>
                </div>
              </div>
            </section>
          </div>
        </template>
        <EmptyState
          v-else-if="!videoPromptPreviewLoading"
          icon="VideoCamera"
          :title="t('暂无可预览内容')"
          :hint="t('请先执行上游分镜节点，再查看视频提示词。')"
        />
      </div>
      <template #footer>
        <div class="dialog-footer">
          <el-button @click="videoPromptPreviewVisible = false">{{ t('关闭') }}</el-button>
        </div>
      </template>
    </el-dialog>

    <el-dialog
      v-model="rerunPromptDialogVisible"
      :title="t('重新执行节点')"
      width="680px"
      class="custom-dialog rerun-prompt-dialog"
      :close-on-click-modal="false"
      @closed="cancelRerunPromptDialog"
    >
      <div class="rerun-prompt-head">
        <span>{{ t('当前节点') }}</span>
        <strong>{{ rerunPromptForm.nodeTitle || t('文本节点') }}</strong>
        <em>{{ t('修改仅对本次执行生效，不会保存回流程模板。') }}</em>
      </div>

      <el-form label-position="top">
        <el-form-item :label="t('提示词来源')">
          <el-select
            v-model="rerunPromptForm.templateId"
            :placeholder="t('选择词库，或切换为自定义本次提示词')"
            clearable
            filterable
            style="width: 100%"
            @change="applyRerunPromptTemplate"
          >
            <el-option
              v-for="template in rerunPromptTemplateOptions"
              :key="template.id"
              :label="template.is_system ? t('{name}（官方）', { name: template.title }) : template.title"
              :value="template.id"
            />
          </el-select>
          <p v-if="selectedRerunPromptIsSystem && rerunPromptForm.mode === 'template'" class="prompt-dialog-hint">
            {{ t('已选择官方词库「{name}」，正文可查看；切换到“自定义本次提示词”后可在此基础上改写。', { name: selectedRerunPromptTemplate?.title ?? '' }) }}
          </p>
        </el-form-item>

        <el-form-item :label="t('执行方式')">
          <el-radio-group v-model="rerunPromptForm.mode">
            <el-radio-button value="template">{{ t('使用当前来源') }}</el-radio-button>
            <el-radio-button value="custom">{{ t('自定义本次提示词') }}</el-radio-button>
          </el-radio-group>
        </el-form-item>

        <el-form-item :label="t('本次提示词 / 指令')">
          <el-input
            v-model="rerunPromptForm.prompt"
            type="textarea"
            :rows="8"
            :disabled="rerunPromptTextareaDisabled"
            :placeholder="t('填写本次执行使用的提示词。系统词库可先查看，再切换到自定义模式改写。')"
          />
        </el-form-item>
      </el-form>

      <template #footer>
        <div class="dialog-footer">
          <el-button @click="cancelRerunPromptDialog">{{ t('取消') }}</el-button>
          <el-button type="primary" @click="confirmRerunPromptDialog">{{ t('确认执行') }}</el-button>
        </div>
      </template>
    </el-dialog>

    <el-dialog v-model="bindWorkflowVisible" :title="t('绑定剧集工作流')" width="480px" class="custom-dialog">
      <el-form label-position="top">
        <el-form-item :label="t('选择剧集工作流')" required>
          <el-select v-model="bindWorkflowId" style="width: 100%" :placeholder="t('请选择工作流')">
            <el-option v-for="w in episodeWorkflowOptions" :key="w.id" :value="w.id" :label="w.label" />
          </el-select>
        </el-form-item>
        <p class="bind-workflow-hint">{{ t('绑定后即可开始生成本集；剧集开始执行后工作流不可更换。') }}</p>
      </el-form>
      <template #footer>
        <el-button @click="bindWorkflowVisible = false">{{ t('取消') }}</el-button>
        <el-button type="primary" :loading="isBindingWorkflow" @click="confirmBindWorkflow">{{ t('绑定') }}</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="seriesDialogVisible" :title="seriesForm.id ? t('编辑剧本') : t('新建剧本')" width="640px" class="custom-dialog">
      <el-form label-position="top">
        <el-form-item v-if="!seriesForm.id" :label="t('创建方式')" required>
          <div class="mode-selector">
            <div 
              class="mode-card" 
              :class="{ active: seriesForm.createMode === 'manual' }"
              @click="seriesForm.createMode = 'manual'; handleSeriesCreateModeChange('manual')"
            >
              <div class="mode-card__icon">
                <el-icon><EditPen /></el-icon>
              </div>
              <div class="mode-card__content">
                <div class="mode-card__title">{{ t('手动创建') }}</div>
                <div class="mode-card__desc">{{ t('直接填写剧本名称和简介，后续手动添加剧集。') }}</div>
              </div>
              <div class="mode-card__check">
                <el-icon v-if="seriesForm.createMode === 'manual'"><Check /></el-icon>
              </div>
            </div>
            <div 
              class="mode-card" 
              :class="{ active: seriesForm.createMode === 'workflow' }"
              @click="seriesForm.createMode = 'workflow'; handleSeriesCreateModeChange('workflow')"
            >
              <div class="mode-card__icon">
                <el-icon><Connection /></el-icon>
              </div>
              <div class="mode-card__content">
                <div class="mode-card__title">{{ t('通过工作流') }}</div>
                <div class="mode-card__desc">{{ t('选择一个“剧本”类型工作流，自动生成剧本结构。') }}</div>
              </div>
              <div class="mode-card__check">
                <el-icon v-if="seriesForm.createMode === 'workflow'"><Check /></el-icon>
              </div>
            </div>
          </div>
        </el-form-item>

        <template v-if="!seriesForm.id && seriesForm.createMode === 'workflow'">
          <div class="form-row">
            <el-form-item :label="t('选择工作流')" required style="flex: 2">
              <el-select v-model="seriesForm.workflowId" style="width: 100%" :placeholder="t('请选择工作流')">
                <el-option
                  v-for="w in seriesWorkflowOptions"
                  :key="w.id"
                  :label="w.label"
                  :value="w.id"
                />
              </el-select>
            </el-form-item>
            <el-form-item :label="t('目标集数（可选）')" style="flex: 1">
              <el-input
                v-model.number="seriesForm.episodeCount"
                type="number"
                min="1"
                max="100"
                clearable
                :placeholder="t('不填则按解析结果')"
              />
            </el-form-item>
          </div>
          <div class="form-hint">{{ t('留空则由“剧集规划”节点按剧本长度自动决定。') }}</div>
          
          <el-form-item 
            v-if="seriesForm.workflowId"
            :label="getSeriesWorkflowInputLabel" 
            :required="!!seriesWorkflowInputNode"
          >
            <div
              class="novel-dropzone"
              :class="{ 'is-over': isNovelDragOver, 'is-loading': isNovelImporting }"
              @click="pickNovelFile"
              @dragenter.prevent="isNovelDragOver = true"
              @dragover.prevent="isNovelDragOver = true"
              @dragleave.prevent="isNovelDragOver = false"
              @drop.prevent="onNovelDrop"
            >
              <input
                ref="novelImportInputRef"
                class="novel-dropzone__input"
                type="file"
                accept=".txt,.pdf,text/plain,application/pdf"
                @change="onNovelFileChange"
              >
              <div class="novel-dropzone__icon">
                <el-icon><UploadFilled /></el-icon>
              </div>
              <div class="novel-dropzone__body">
                <div class="novel-dropzone__title">
                  {{ isNovelImporting ? t('正在导入文件...') : t('拖拽 TXT / PDF 到这里，或点击选择文件') }}
                </div>
                <div class="novel-dropzone__meta">{{ t('导入后会自动填入下面的小说正文，仍可继续手动编辑。') }}</div>
              </div>
            </div>
            <el-input
              v-model="seriesForm.seedPlotInput"
              type="textarea"
              :rows="8"
              :placeholder="getSeriesWorkflowInputDesc"
            />
          </el-form-item>
        </template>

        <el-form-item :label="t('剧本名称')" :required="seriesForm.id > 0 || seriesForm.createMode === 'manual'">
          <el-input v-model="seriesForm.title" :placeholder="t('例如：都市奇缘 第一季')" />
        </el-form-item>
        <el-form-item required>
          <template #label>
            <span class="form-label-with-tip">
              {{ t('内容地区') }}
              <el-tooltip placement="top" :show-after="200">
                <template #content>
                  {{ t('影响工作流拆解时的姓名、场景与文化设定，以及后续剧集 AI 生成语境。') }}
                </template>
                <el-icon class="form-label-tip" tabindex="0"><QuestionFilled /></el-icon>
              </el-tooltip>
            </span>
          </template>
          <el-radio-group v-model="seriesForm.region">
            <el-radio-button value="china">{{ t('中国') }}</el-radio-button>
            <el-radio-button value="western">{{ t('欧美') }}</el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item :label="t('视觉风格')" required>
          <el-radio-group v-model="seriesForm.visualStyle">
            <el-radio-button
              v-for="option in currentVisualStyleOptions"
              :key="option.value"
              :value="option.value"
            >
              {{ option.label }}
            </el-radio-button>
          </el-radio-group>
          <div class="form-hint">{{ t('定义整部剧本的画风，会影响所有分镜图片的生成效果。') }}</div>
        </el-form-item>
        <el-form-item :label="t('细分风格')">
          <el-select
            v-model="seriesForm.visualStyleVariant"
            clearable
            filterable
            :placeholder="t('跟随一级风格')"
            style="width: 100%"
          >
            <el-option
              v-for="option in currentVisualStyleVariantOptions"
              :key="option.value"
              :label="option.label"
              :value="option.value"
            >
              <div class="style-variant-option">
                <span>{{ option.label }}</span>
                <em v-if="option.desc">{{ option.desc }}</em>
              </div>
            </el-option>
          </el-select>
          <div class="form-hint">{{ t('可选。用于进一步控制时代感、线条、色彩和渲染方式。') }}</div>
        </el-form-item>
        <el-form-item :label="t('剧本简介')">
          <el-input v-model="seriesForm.description" type="textarea" :rows="3" :placeholder="t('简要描述这部剧本的内容...')" />
        </el-form-item>
      </el-form>
      <template #footer>
        <div class="dialog-footer">
          <el-button :disabled="isSeriesSubmitting" @click="seriesDialogVisible = false">{{ t('取消') }}</el-button>
          <el-button type="primary" :loading="isSeriesSubmitting" @click="submitSeries">
            {{ seriesForm.createMode === 'workflow' && !seriesForm.id ? t('创建并排队') : t('保存剧本') }}
          </el-button>
        </div>
      </template>
    </el-dialog>

    <el-drawer
      v-model="episodeDrawerVisible"
      :title="t('新建剧集')"
      size="560px"
      class="custom-drawer"
      destroy-on-close
    >
      <el-form label-position="top" class="drawer-form">
        <div class="drawer-section">
          <div class="section-title">{{ t('剧集信息') }}</div>
          <div class="form-row">
            <el-form-item :label="t('剧集序号')" required style="flex: 1">
              <el-input-number v-model="episodeForm.number" :min="1" style="width: 100%" />
              <div v-if="isEpisodeNumberUsed(episodeForm.number)" class="field-error">
                {{ t('第 {number} 集已存在', { number: episodeForm.number }) }}
              </div>
            </el-form-item>
            <el-form-item :label="t('剧集标题')" required style="flex: 2">
              <el-input v-model="episodeForm.title" :placeholder="t('例如：初次相遇')" />
            </el-form-item>
          </div>
        </div>

        <div v-if="isPromoSeriesForCreate" class="drawer-section">
          <div class="section-title">{{ t('宣传片长度') }}</div>
          <el-form-item :label="t('视频段数 / 总时长')" required>
            <el-segmented
              v-model="episodeForm.promoSegmentCount"
              :options="promoSegmentOptions"
            />
            <div class="form-hint">{{ t('与创建宣传片作品时相同：每段约 15 秒，可选 1-4 段。') }}</div>
          </el-form-item>
        </div>

        <div class="drawer-section">
          <div class="section-title">{{ t('剧情输入') }}</div>
          <el-form-item :label="isPromoSeriesForCreate ? t('宣传片创意 / 文案') : t('剧情简介 / 剧本')" required>
            <el-input
              v-model="episodeForm.plotInput"
              type="textarea"
              :rows="12"
              :placeholder="isPromoSeriesForCreate
                ? t('填写本集宣传片创意或文案。创建后按所选时长生成分镜与视频。')
                : t('填写本集剧情简介或剧本片段。创建后可在右侧详情选择剧集工作流。')"
            />
          </el-form-item>
        </div>
      </el-form>
      <template #footer>
        <div class="drawer-footer">
          <el-button @click="episodeDrawerVisible = false">{{ t('取消') }}</el-button>
          <el-button type="primary" size="large" @click="submitEpisode">{{ t('创建剧集') }}</el-button>
        </div>
      </template>
    </el-drawer>
  </div>
</template>

<style scoped lang="scss">
.series-v2 {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: var(--canvas);
}

.page-top__head {
  display: flex;
  align-items: center;
  gap: var(--space-md);
  min-width: 0;
}

.page-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 16px;
  padding: 16px 24px;
  border-bottom: 1px solid var(--hairline);
  flex-shrink: 0;

  h1 {
    margin: 0;
    font-size: 22px;
    font-weight: 700;
    letter-spacing: 0;
    color: var(--on-dark);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  p {
    margin: 4px 0 0;
    color: var(--muted-soft);
    font-size: 12px;
  }
}

.top-actions {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 10px;
  flex: 0 0 auto;
}

.top-actions :deep(.el-button) {
  min-width: max-content;
  white-space: nowrap;
}

@media (max-width: 980px) {
  .page-top {
    align-items: flex-start;
    flex-direction: column;
    padding: 14px 16px;
  }

  .page-top__head,
  .top-actions {
    width: 100%;
  }

  .page-top h1 {
    white-space: normal;
  }

  .top-actions {
    justify-content: flex-start;
  }
}

@media (max-width: 560px) {
  .top-actions :deep(.el-button) {
    flex: 1 1 calc(50% - 6px);
    min-width: 0;
  }

  .top-actions :deep(.el-button span) {
    overflow: hidden;
    text-overflow: ellipsis;
  }
}

.mode-selector {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
  width: 100%;
  margin-bottom: 8px;
}

.mode-card {
  position: relative;
  display: flex;
  flex-direction: column;
  padding: 20px;
  background: var(--surface-card);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-lg);
  cursor: pointer;
  transition: all var(--duration-normal) var(--ease-out);
  overflow: hidden;

  &:hover {
    border-color: var(--hairline-strong);
    background: var(--surface-elevated);
    transform: translateY(-2px);
  }

  &.active {
    border-color: var(--primary);
    background: rgba(250, 255, 105, 0.05);
    box-shadow: 0 8px 24px rgba(0, 0, 0, 0.2);
    
    .mode-card__icon {
      background: var(--primary);
      color: var(--on-primary);
    }
    
    .mode-card__title {
      color: var(--primary);
    }
  }
}

.mode-card__icon {
  width: 40px;
  height: 40px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--surface-elevated);
  border-radius: var(--radius-md);
  margin-bottom: 16px;
  font-size: 20px;
  color: var(--muted);
  transition: all var(--duration-normal);
}

.mode-card__title {
  font-size: 16px;
  font-weight: 700;
  color: var(--on-dark);
  margin-bottom: 6px;
}

.mode-card__desc {
  font-size: 12px;
  line-height: 1.5;
  color: var(--muted);
}

.mode-card__check {
  position: absolute;
  top: 12px;
  right: 12px;
  font-size: 18px;
  color: var(--primary);
}

.novel-dropzone {
  width: 100%;
  min-height: 92px;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 16px;
  margin-bottom: 12px;
  border: 1px dashed var(--hairline-strong);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.035);
  cursor: pointer;
  transition: border-color var(--duration-normal), background var(--duration-normal);

  &:hover,
  &.is-over {
    border-color: var(--primary);
    background: rgba(250, 255, 105, 0.07);
  }

  &.is-loading {
    cursor: progress;
    opacity: 0.78;
  }
}

.novel-dropzone__input {
  display: none;
}

.novel-dropzone__icon {
  flex: 0 0 42px;
  width: 42px;
  height: 42px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 8px;
  background: var(--surface-elevated);
  color: var(--primary);
  font-size: 22px;
}

.novel-dropzone__body {
  min-width: 0;
}

.novel-dropzone__title {
  font-size: 14px;
  font-weight: 700;
  color: var(--on-dark);
  line-height: 1.4;
}

.novel-dropzone__meta {
  margin-top: 4px;
  font-size: 12px;
  line-height: 1.5;
  color: var(--muted);
}

/* ── Dialog Enhancements ───────────────────────────────────────────────────── */
.form-row {
  display: flex;
  gap: 16px;
  margin-bottom: 8px;
}

.dialog-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding-top: 12px;
}

.custom-dialog {
  :deep(.el-dialog__body) {
    padding: 24px 32px !important;
  }
  
  :deep(.el-form-item) {
    margin-bottom: 24px;
  }
}

.form-label-with-tip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.form-label-tip {
  font-size: 14px;
  color: var(--muted);
  cursor: help;
  outline: none;
  transition: color 0.15s ease;

  &:hover,
  &:focus-visible {
    color: var(--primary);
  }
}

.workflow-run-panel {
  margin: 0 24px 16px;
  padding: 16px 18px;
  border: 1px solid rgba(250, 255, 105, 0.35);
  border-radius: 10px;
  background: rgba(250, 255, 105, 0.06);
}

.workflow-run-panel--success {
  border-color: rgba(102, 217, 135, 0.45);
  background: rgba(102, 217, 135, 0.08);
}

.workflow-run-panel--failed {
  border-color: rgba(255, 122, 122, 0.5);
  background: rgba(255, 122, 122, 0.08);
}

.workflow-run-panel__main {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  align-items: center;
  margin-bottom: 10px;
  color: var(--on-dark);

  span {
    color: var(--muted);
    font-size: 12px;
  }
}

.workflow-run-panel__title {
  display: inline-flex;
  align-items: center;
  gap: 8px;
}

.workflow-run-panel__badge {
  padding: 3px 8px;
  border: 1px solid var(--hairline);
  border-radius: 999px;
  background: rgba(0, 0, 0, 0.16);
}

.workflow-lock-bar {
  margin: 0 24px 10px;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border: 1px solid rgba(250, 255, 105, 0.28);
  border-radius: 8px;
  color: var(--primary);
  background: rgba(250, 255, 105, 0.08);
  font-size: 12px;
}

.workflow-run-panel__nodes {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 10px;
}

.node-status {
  padding: 4px 8px;
  border-radius: 999px;
  border: 1px solid var(--hairline);
  color: var(--muted);
  font-size: 11px;
}

.node-status--running {
  color: var(--primary);
}

.node-status--success {
  color: #66d987;
}

.node-status--failed {
  color: #ff7a7a;
}

.workflow-run-panel__debug {
  margin-top: 10px;
  border: 1px solid rgba(255, 122, 122, 0.35);
  border-radius: 8px;
  background: rgba(0, 0, 0, 0.25);
}

.workflow-run-panel__debug summary {
  cursor: pointer;
  padding: 8px 10px;
  font-size: 12px;
  font-weight: 600;
  color: #ffb4b4;
}

.workflow-run-panel__debug-block {
  padding: 0 10px 10px;
}

.workflow-run-panel__debug-title {
  font-size: 11px;
  color: var(--muted, #888);
  margin-bottom: 4px;
}

.workflow-run-panel__debug pre {
  margin: 0;
  max-height: 280px;
  overflow: auto;
  font-size: 11px;
  line-height: 1.45;
  white-space: pre-wrap;
  word-break: break-word;
  color: #e8e8e8;
  background: rgba(0, 0, 0, 0.35);
  padding: 8px;
  border-radius: 6px;
}

.workflow-run-panel__debug-error {
  margin: 0 10px 8px;
  font-size: 12px;
  color: #ff9a9a;
}

.workflow-run-panel__error {
  margin: 10px 0 0;
  color: #ff7a7a;
  font-size: 12px;
}

/* ── Drawer Enhancements ───────────────────────────────────────────────────── */
.custom-drawer {
  :deep(.el-drawer__header) {
    margin-bottom: 0;
    padding: 20px 24px;
    border-bottom: 1px solid var(--hairline);
    font-weight: 700;
    color: var(--on-dark);
  }

  :deep(.el-drawer__body) {
    padding: 0;
    background: var(--canvas);
  }
}

.drawer-form {
  padding: 24px;
}

.drawer-section {
  margin-bottom: 32px;

  .section-title {
    font-size: 12px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 1px;
    color: var(--muted);
    margin-bottom: 16px;
    padding-bottom: 8px;
    border-bottom: 1px solid var(--hairline);
  }
}

.field-error {
  margin-top: 6px;
  color: #ff7a7a;
  font-size: 12px;
}

.mode-selector.mini {
  grid-template-columns: 1fr 1fr;
  gap: 12px;

  .mode-card {
    padding: 12px;
    flex-direction: row;
    align-items: center;
    gap: 12px;

    .mode-card__icon {
      margin-bottom: 0;
      width: 32px;
      height: 32px;
      font-size: 16px;
    }

    .mode-card__title {
      margin-bottom: 0;
      font-size: 14px;
    }
  }
}

.drawer-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding: 20px 24px;
  border-top: 1px solid var(--hairline);
  background: var(--surface-soft);
}

.bind-workflow-hint {
  margin: 0;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.5;
}

.rerun-prompt-head {
  display: grid;
  gap: 6px;
  margin-bottom: 18px;
  padding: 14px 16px;
  border: 1px solid var(--hairline);
  border-radius: 8px;
  background: var(--surface-soft);

  span {
    font-size: 12px;
    font-weight: 700;
    color: var(--muted);
  }

  strong {
    color: var(--on-dark);
    font-size: 16px;
  }

  em {
    font-style: normal;
    color: var(--muted);
    font-size: 12px;
  }
}

.video-prompt-preview {
  max-width: 100%;
  min-height: 360px;
  min-width: 0;
  overflow: hidden;
}

.video-prompt-preview-dialog :deep(.el-dialog__body) {
  min-width: 0;
  max-width: 100%;
  overflow: hidden;
}

.video-prompt-preview__summary {
  min-width: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  align-items: center;
  margin-bottom: 14px;
  color: var(--muted);
  font-size: 12px;

  strong {
    min-width: 0;
    color: var(--on-dark);
    font-size: 15px;
    overflow-wrap: anywhere;
  }

  span {
    min-width: 0;
    max-width: 100%;
    overflow-wrap: anywhere;
    word-break: break-word;
  }
}

.video-prompt-preview__layout {
  display: grid;
  grid-template-columns: minmax(190px, 260px) minmax(0, 1fr);
  gap: 14px;
  height: min(62vh, 640px);
  min-height: 360px;
  min-width: 0;
  max-width: 100%;
  overflow: hidden;
}

.video-prompt-preview__shots {
  min-width: 0;
  min-height: 0;
  display: grid;
  align-content: start;
  gap: 8px;
  overflow: auto;
  padding-right: 4px;

  button {
    min-width: 0;
    display: grid;
    grid-template-columns: minmax(0, 1fr) auto;
    gap: 4px 8px;
    width: 100%;
    padding: 10px;
    border: 1px solid var(--hairline);
    border-radius: 8px;
    background: var(--surface-soft);
    color: var(--text);
    text-align: left;
    cursor: pointer;
  }

  button.active {
    border-color: var(--primary);
    background: var(--surface-soft);
  }

  strong {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    font-size: 13px;
  }

  span {
    color: var(--muted);
    font-size: 12px;
  }

  em {
    grid-column: 1 / -1;
    display: -webkit-box;
    overflow: hidden;
    color: var(--muted);
    font-style: normal;
    font-size: 12px;
    line-height: 1.4;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
  }
}

.video-prompt-shot {
  display: flex;
  flex-direction: column;
  gap: 10px;
  min-width: 0;
  min-height: 0;
  overflow: auto;
  padding-right: 4px;
  overscroll-behavior: contain;
}

.video-prompt-shot__section {
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-width: 0;
  min-height: 0;
}

.video-prompt-shot__section--main {
  flex: 1 1 auto;
}

.video-prompt-shot__section--aux {
  flex: 0 0 auto;
}

.video-prompt-shot__desc {
  margin: 0;
  color: var(--text);
  line-height: 1.6;
  overflow-wrap: anywhere;
  word-break: break-word;
}

.video-prompt-shot__refs {
  min-width: 0;
  max-width: 100%;
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  overflow: hidden auto;
  max-height: 96px;

  span {
    min-width: 0;
    max-width: 100%;
    padding: 4px 8px;
    border: 1px solid var(--hairline);
    border-radius: 999px;
    color: var(--muted);
    font-size: 12px;
    background: var(--surface-soft);
    overflow-wrap: anywhere;
    word-break: break-word;
  }
}

.video-prompt-shot__head {
  min-width: 0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  font-weight: 700;
  color: var(--text);

  span {
    min-width: 0;
    overflow-wrap: anywhere;
  }
}

.video-prompt-shot__prompt-scroll {
  min-width: 0;
  min-height: 0;
  max-height: min(42vh, 420px);
  overflow: auto;
  border: 1px solid var(--hairline);
  border-radius: 8px;
  background: var(--surface-soft);
}

.video-prompt-shot__prompt-scroll--aux {
  max-height: min(20vh, 180px);
}

.video-prompt-shot__prompt-scroll--main {
  flex: 1 1 auto;
  min-height: 160px;
  max-height: none;
}

.video-prompt-shot pre {
  max-width: 100%;
  overflow: visible;
  min-height: 0;
  min-width: 0;
  margin: 0;
  padding: 12px;
  border: 0;
  border-radius: 0;
  background: transparent;
  color: #132033;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  word-break: break-word;
  line-height: 1.55;
  font-size: 12px;
}

@media (max-width: 760px) {
  .video-prompt-preview__layout {
    grid-template-columns: 1fr;
    height: min(68vh, 680px);
  }

  .video-prompt-shot__prompt-scroll {
    max-height: min(32vh, 320px);
  }

  .video-prompt-preview__shots {
    display: flex;
    overflow-x: auto;
    padding-bottom: 4px;

    button {
      min-width: 180px;
    }
  }
}

.prompt-dialog-hint {
  margin: 8px 0 0;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.5;
}

.style-variant-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  width: 100%;

  em {
    flex: 1;
    min-width: 0;
    color: var(--muted);
    font-size: 12px;
    font-style: normal;
    overflow: hidden;
    text-align: right;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

</style>
