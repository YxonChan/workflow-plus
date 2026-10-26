import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { t } from '@/i18n'
import { listGrouped } from '@/api/modelConfig'
import { useAuthStore } from '@/stores/auth'
import { attachCharacterResult } from './domain/story.js'
import type { ModelConfig } from '@/types'
import { ecommerce } from './api'
import { validateProject, buildModelRequest, buildProductRequest, buildSceneRequest } from './domain/commerce.js'
import { createStorefrontProject, storefrontGoal, selectStorefrontGoal, storefrontImageRequests, storefrontVideoRequest, imageReferenceInput } from './domain/storefront.js'
import type { ImageRequest, Job, PlanItem, Project, ProjectBrief, ProjectDetail, Result, Run, SectionKind } from './types'

export const clone = <T>(value: T): T => JSON.parse(JSON.stringify(value)) as T
export const errorText = (error: unknown) => error instanceof Error ? error.message : String(error)
export const kindName = (kind: SectionKind) => ({ model: t('模特'), product: t('商品'), scene: t('场景'), creative: t('成品') })[kind]

export function useEcommerce() {
  const auth = useAuthStore()
  const projects = ref<ProjectBrief[]>([])
  const initialProject = createStorefrontProject()
  initialProject.integration = {}
  const project = ref<Project>(initialProject)
  const id = ref(0)
  const revision = ref(1)
  const jobs = ref<Job[]>([])
  const runs = ref<Run[]>([])
  const models = ref<ModelConfig[]>([])
  const loading = ref(true)
  const dirty = ref(false)
  const saving = ref(false)
  const saveError = ref('')
  let hydrating = false
  let savedJson = ''
  let saveTimer: ReturnType<typeof setTimeout> | undefined
  let pollTimer: ReturnType<typeof setTimeout> | undefined
  let disposed = false
  let saveFlight: Promise<void> | null = null
  let requestEpoch = 0
  const imageModels = computed(() => models.value.filter(model => model.type === 'image' && /^gpt-image-2\.5-(flare|sunburst)(-official)?$/.test(model.model_id)))
  const videoModels = computed(() => models.value.filter(model => model.type === 'video' && /seedance-2/.test(model.model_id)))
  const imageModel = computed(() => imageModels.value.find(model => model.id === project.value.integration?.imageModelId))
  const isOfficial = computed(() => imageModel.value?.model_id.endsWith('-official') ?? false)

  function integrate(detail: ProjectDetail, initial = false) {
    if (initial) {
      hydrating = true
      const state = validateProject(detail.state)
      if (detail.state.creative.copy === undefined) state.creative.copy.enabled = true
      state.integration = detail.state.integration || {}
      if (!state.integration.goal) {
        state.creative.useModel = state.creative.useModel && !!(state.model.results.length || state.model.uploads.length)
      }
      selectStorefrontGoal(state, storefrontGoal(state))
      state.integration.imageModelId ||= imageModels.value.find(model => model.is_default)?.id || imageModels.value[0]?.id
      state.integration.videoModelId ||= videoModels.value.find(model => model.is_default)?.id || videoModels.value[0]?.id
      state.integration.officialQuality ||= 'high'
      project.value = state
      id.value = Number(detail.id)
      revision.value = Number(detail.revision)
      savedJson = JSON.stringify(state)
      dirty.value = false
      saveError.value = ''
      hydrating = false
    }
    jobs.value = detail.jobs.map(job => ({ ...job, id: Number(job.id), run_id: Number(job.run_id) }))
    runs.value = detail.runs
    const attachCharacter = (job: Job, result: Result) => {
      const original = job.request.original
      if (original.storyCharacterId && jobs.value.some(other => other.id > job.id && other.request.original.storyCharacterId === original.storyCharacterId
        && other.request.original.storyCharacterDescription === original.storyCharacterDescription)) return
      attachCharacterResult(project.value, job, result)
    }
    for (const job of jobs.value) {
      if (job.mode !== 'image' || job.status !== 'success' || !job.result_url || project.value.integration?.hiddenJobs?.includes(job.id)) continue
      const section = project.value[job.kind]
      const existing = section.results.find(result => result.id === `job-${job.id}`)
      if (existing) { attachCharacter(job, existing); continue }
      const original = job.request.original as ImageRequest
      const size = original.size?.split('x').map(Number) || [1024, 1536]
      const result: Result = { id: `job-${job.id}`, name: job.title, src: job.result_url, width: size[0] || 1024, height: size[1] || 1536,
        createdAt: job.create_time.replace(' ', 'T') + 'Z', operation: original.mode === 'edit' ? 'edit' : 'generate', request: original }
      section.results.push(result)
      if (job.kind === 'model' && !original.storyCharacterId) { project.value.model.source = 'ai'; project.value.creative.useModel = true }
      attachCharacter(job, result)
      if (!section.activeResultId || job.kind !== 'creative') section.activeResultId = result.id
      if (job.kind === 'scene') project.value.scene.source = 'custom'
    }
  }

  async function save(): Promise<void> {
    clearTimeout(saveTimer)
    if (saveFlight) { await saveFlight; if (dirty.value && !saveError.value) await save(); return }
    if (!id.value || !dirty.value || saveError.value) return
    const state = clone(project.value)
    const content = JSON.stringify(state)
    const projectId = id.value
    saving.value = true
    saveFlight = (async () => {
      try {
        const result = await ecommerce.save(projectId, revision.value, state)
        if (id.value !== projectId) return
        revision.value = result.revision
        savedJson = content
        dirty.value = JSON.stringify(project.value) !== content
        const brief = projects.value.find(item => Number(item.id) === projectId)
        if (brief) brief.name = state.name
      } catch (error) {
        saveError.value = errorText(error)
        throw error
      } finally { saving.value = false; saveFlight = null }
    })()
    await saveFlight
    if (dirty.value && !saveError.value) saveTimer = setTimeout(() => { void save().catch(() => {}) }, 700)
  }

  watch(project, () => {
    if (hydrating || !id.value) return
    dirty.value = JSON.stringify(project.value) !== savedJson
    clearTimeout(saveTimer)
    if (dirty.value && !saveError.value) saveTimer = setTimeout(() => { void save().catch(() => {}) }, 900)
  }, { deep: true, flush: 'sync' })

  async function open(projectId: number) {
    await save()
    if (saveError.value) throw new Error(saveError.value)
    loading.value = true
    const epoch = ++requestEpoch
    try {
      const detail = await ecommerce.detail(projectId)
      if (epoch !== requestEpoch || disposed) return
      integrate(detail, true)
      localStorage.setItem(`ecommerce:last:${auth.user?.id}`, String(id.value))
    } finally { loading.value = false }
  }

  async function create(state = createStorefrontProject(), legacy = false) {
    await save()
    if (saveError.value) throw new Error(saveError.value)
    const detail = await ecommerce.create(state, legacy)
    ++requestEpoch
    integrate(detail, true)
    projects.value = await ecommerce.list()
    localStorage.setItem(`ecommerce:last:${auth.user?.id}`, String(id.value))
  }

  async function refresh() {
    const projectId = id.value
    const epoch = requestEpoch
    if (!projectId) return
    const detail = await ecommerce.detail(projectId, true)
    if (id.value === projectId && epoch === requestEpoch && !disposed) integrate(detail)
  }

  async function poll() {
    try { if (!loading.value && document.visibilityState !== 'hidden') await refresh() } catch { /* Keep the draft on temporary network failures. */ }
    if (!disposed) pollTimer = setTimeout(poll, jobs.value.some(job => ['running', 'queued'].includes(job.status)) ? 4000 : 15000)
  }

  async function init() {
    loading.value = true
    try {
      const [groups, list] = await Promise.all([listGrouped(), ecommerce.list()])
      models.value = groups.flatMap(group => group.models)
      projects.value = list
      const last = Number(localStorage.getItem(`ecommerce:last:${auth.user?.id}`))
      const chosen = list.find(item => Number(item.id) === last) || list[0]
      if (chosen) await open(Number(chosen.id)); else await create()
      void poll()
    } finally { loading.value = false }
  }

  function imageItems(kind: SectionKind, request: ImageRequest, title: string, pageKey = ''): PlanItem[] {
    if (!imageModel.value) throw new Error(t('请先选择可用的图片模型'))
    const [width, height] = request.size.split('x').map(Number) as [number, number]
    const ratio = Math.abs(width / height - 1) < 0.02 ? '1:1' : width > height ? '3:2' : '2:3'
    const options = isOfficial.value
      ? { size: `${Math.round(width / 16) * 16}x${Math.round(height / 16) * 16}`, quality: project.value.integration?.officialQuality || 'high' }
      : { size: ratio, aspect_ratio: ratio, resolution: project.value[kind].quality, quality: 'high' }
    const input = imageReferenceInput(request)
    return Array.from({ length: request.n }, (_, index) => ({ kind, mode: 'image', title: request.n > 1 ? `${title} ${index + 1}` : title,
      page_key: pageKey || request.campaign?.shotId || '', model_config_id: imageModel.value!.id,
      request: { prompt: input.prompt, images: input.images, options, ...(kind === 'creative' ? { creative_goal: request.design?.purpose || storefrontGoal(project.value) } : {}), original: { ...clone(request), prompt: input.prompt, n: 1 } } }))
  }

  function build(kind: SectionKind): PlanItem[] {
    const p = project.value
    if (kind === 'model') return imageItems(kind, buildModelRequest(p.model), t('模特参考'))
    if (kind === 'product') return imageItems(kind, buildProductRequest(p.product), p.product.config.name || t('商品提取'))
    if (kind === 'scene') return imageItems(kind, buildSceneRequest(p.scene), t('场景参考'))
    if (p.creative.mode === 'video') {
      const model = videoModels.value.find(item => item.id === p.integration?.videoModelId)
      if (!model) throw new Error(t('请选择可用的 Seedance 2 视频模型'))
      p.creative.video.model = model.model_id
      const req = storefrontVideoRequest(p)
      return [{ kind, mode: 'video', title: p.product.config.name || t('电商视频'), page_key: 'video', model_config_id: model.id,
        request: { prompt: req.prompt, images: req.image_with_roles.map(item => item.url), original: p.integration?.videoBrief ? { brief_version: p.integration.videoBrief.version } : {}, creative_goal: 'video', video_style: p.creative.video.style, options: {
          duration: req.duration, aspect_ratio: req.aspect_ratio, resolution: req.resolution, generate_audio: req.generate_audio } } }]
    }
    return storefrontImageRequests(p).flatMap(item => imageItems(kind, item.request, item.title, item.pageKey))
  }

  function beforeUnload(event: BeforeUnloadEvent) { if (dirty.value) { event.preventDefault(); event.returnValue = '' } }
  window.addEventListener('beforeunload', beforeUnload)
  onBeforeUnmount(() => { disposed = true; clearTimeout(saveTimer); clearTimeout(pollTimer); window.removeEventListener('beforeunload', beforeUnload) })
  function notify(error: unknown) { ElMessage.error(errorText(error)) }
  return { projects, project, id, revision, jobs, runs, models, loading, dirty, saving, saveError, imageModels, videoModels,
    imageModel, isOfficial, save, open, create, refresh, init, integrate, imageItems, build, notify }
}
