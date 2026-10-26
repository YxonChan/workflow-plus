<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { onBeforeRouteLeave } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { zipSync } from 'fflate'
import { t } from '@/i18n'
import { resolveIcon } from '@/utils/iconRegistry'
import { listAssets } from '@/api/asset'
import type { Asset } from '@/types'
import MediaStrip from '@/features/ecommerce/MediaStrip.vue'
import ReferenceJobs from '@/features/ecommerce/ReferenceJobs.vue'
import ProductMarker from '@/features/ecommerce/ProductMarker.vue'
import StoryComposer from '@/features/ecommerce/StoryComposer.vue'
import PosterImage from '@/features/ecommerce/PosterImage.vue'
import { posterBlob } from '@/features/ecommerce/poster-renderer'
import { validatePoster } from '@/features/ecommerce/domain/marketing-copy.js'
import { ApiError } from '@/api/http'
import { characterImageRequest } from '@/features/ecommerce/domain/story.js'
import { ecommerce } from '@/features/ecommerce/api'
import { useEcommerce, clone, errorText } from '@/features/ecommerce/useEcommerce'
import { buildEditRequest, PRODUCT_OPTIONS, validateProject } from '@/features/ecommerce/domain/commerce.js'
import { STOREFRONT_GOALS, storefrontGoal, selectStorefrontGoal, selectStorefrontStyle } from '@/features/ecommerce/domain/storefront.js'
import { STYLE_PRESETS, stylesForPurpose } from '@/features/ecommerce/domain/style-presets.js'
import { SCENE_PRESETS, selectedSceneReference } from '@/features/ecommerce/domain/scene-presets.js'
import type { ImageRequest, Job, Marking, Media, PlanItem, Poster, Project, ProjectDetail, Quote, Result, SectionKind, StoryCharacter } from '@/features/ecommerce/types'

const work = useEcommerce()
const { projects, project, id, jobs, runs, loading, dirty, saving, saveError, imageModels, videoModels, imageModel, isOfficial } = work
const goal = computed({ get: () => storefrontGoal(project.value), set: value => selectStorefrontGoal(project.value, value) })
const lastImageGoal = ref('detail')
watch(goal, value => { if (value !== 'video') lastImageGoal.value = value }, { immediate: true })
const contentMode = computed({ get: () => goal.value === 'video' ? 'video' : 'image', set: value => { goal.value = value === 'video' ? 'video' : lastImageGoal.value; if (value === 'video' && project.value.product.uploads.length) void nextTick(() => storyComposer.value?.analyze()) } })
const imageGoals = STOREFRONT_GOALS.filter(item => item.id !== 'video')
const copyApplicable = computed(() => !['video', 'tryon'].includes(goal.value) && !(goal.value === 'main' && style.value.whiteBackground))
const copyOverlayApplicable = computed(() => copyApplicable.value && goal.value !== 'detail')
const copyNeeded = computed(() => copyApplicable.value && project.value.creative.copy.enabled && !project.value.creative.copy.headline.trim())
const copyBusy = ref(false), copyError = ref('')
let pendingCopy: { signature: string; key: string } | null = null
const copyEditorJob = ref<Job | null>(null), editedPoster = ref<Poster | null>(null)
const posterPreview = ref<{ src: string; poster: Poster | null; name: string } | null>(null)
const savingAssets = ref<number[]>([])
const outputCount = computed(() => ['main', 'detail', 'video'].includes(goal.value) ? 1 : project.value.creative.count)
const selectedGoal = computed(() => STOREFRONT_GOALS.find(item => item.id === goal.value)!)
const storyMode = computed(() => goal.value === 'video' && project.value.creative.video.story.enabled)
const storyComposer = ref<InstanceType<typeof StoryComposer>>()
const storyWorking = ref(false)
const videoStage = ref('questions')
const storyNeedsExpansion = computed(() => {
  const { story, duration } = project.value.creative.video
  return storyMode.value && !!story.idea.trim() && (!story.script.trim() || story.idea !== story.expandedIdea || story.duration !== duration)
})
const hasTextModel = computed(() => work.models.value.some(model => model.type === 'text'))
const style = computed(() => STYLE_PRESETS.find(item => item.id === project.value.creative.presetId) || STYLE_PRESETS[0]!)
const styleOpen = ref(false)
const styleCategory = ref('全部')
const compatibleStyles = computed(() => stylesForPurpose(goal.value))
const styleCategories = computed(() => ['全部', ...new Set(compatibleStyles.value.map(item => item.category))])
const visibleStyles = computed(() => compatibleStyles.value.filter(item => styleCategory.value === '全部' || item.category === styleCategory.value))
watch(goal, () => { styleCategory.value = '全部'; styleOpen.value = false })
const advancedOpen = ref(false)
const modelSetup = ref(false)
const modelChoice = computed({
  get: () => !project.value.creative.useModel ? 'auto' : modelImage.value ? `${project.value.model.source === 'upload' ? 'upload' : 'result'}:${modelImage.value.id}` : 'auto',
  set: value => {
    modelSetup.value = value === 'generate'
    if (value === 'auto') project.value.creative.useModel = false
    else if (value === 'upload') chooseReference('model')
    else if (value === 'asset') void attempt(() => openAssets('model'))
    else if (value.startsWith('upload:')) { const item = project.value.model.uploads.find(item => item.id === value.slice(7)); if (item) selectUpload('model', item) }
    else if (value.startsWith('result:')) { const item = project.value.model.results.find(item => item.id === value.slice(7)); if (item) selectReferenceResult('model', item) }
  },
})
const busy = ref(false)
const uploadsBusy = ref(false)
const downloadBusy = ref(false)
const pageError = ref('')
const productInput = ref<HTMLInputElement>()
const referenceInput = ref<HTMLInputElement>()
const referenceKind = ref<SectionKind>('model')
const importInput = ref<HTMLInputElement>()
const resultsPane = ref<HTMLElement>()
const dragging = ref(false)
const markerImage = ref<Media | null>(null)
const markerOpen = ref(false)
const assetsOpen = ref(false)
const assetKind = ref<SectionKind>('product')
const assets = ref<Asset[]>([])
const assetsLoading = ref(false)
const fullPreview = ref<Media | null>(null)
const videoPreview = ref<Job | null>(null)
const editResult = ref<Result | null>(null)
const editKind = ref<SectionKind>('creative')
const editInstruction = ref('')
const quote = ref<Quote | null>(null)
const quoteItems = ref<PlanItem[]>([])
const quoteOpen = ref(false)
const quoteKey = ref('')
const estimate = ref<Quote | null>(null)
const estimateItems = ref<PlanItem[]>([])
const estimating = ref(false)
const estimateError = ref('')
let estimateTimer: ReturnType<typeof setTimeout> | undefined
let estimateEpoch = 0
let disposed = false
let pendingSubmit: { signature: string; key: string; items: PlanItem[] } | null = null
const selectedRunId = ref(0)
const hiddenJobs = computed(() => project.value.integration?.hiddenJobs || [])
const outputJobs = computed(() => jobs.value.filter(job => job.kind === 'creative' && !hiddenJobs.value.includes(job.id)))
const outputRuns = computed(() => runs.value.filter(run => jobs.value.some(job => job.kind === 'creative' && job.run_id === run.id)).slice().reverse())
const selectedRun = computed(() => runs.value.find(run => run.id === selectedRunId.value))
const visibleJobs = computed(() => {
  if (!selectedRunId.value) return outputJobs.value.slice().reverse()
  const first = jobs.value.find(job => job.kind === 'creative' && job.run_id === selectedRunId.value)
  const campaignId = (first?.request.original as ImageRequest | undefined)?.campaign?.runId
  if (!campaignId) return outputJobs.value.filter(job => job.run_id === selectedRunId.value)
  const latest = new Map<string, Job>()
  for (const job of jobs.value.filter(job => job.kind === 'creative')) {
    if ((job.request.original as ImageRequest).campaign?.runId === campaignId) latest.set(job.page_key, job)
  }
  return [...latest.values()].filter(job => !hiddenJobs.value.includes(job.id)).sort((a, b) => a.page_key[0]!.localeCompare(b.page_key[0]!) * -1 || Number(a.page_key.slice(1)) - Number(b.page_key.slice(1)))
})
const successfulJobs = computed(() => visibleJobs.value.filter(job => job.status === 'success'))
const activeJobs = computed(() => visibleJobs.value.filter(job => ['queued', 'running', 'unknown'].includes(job.status)))
const hasActiveRun = computed(() => !!selectedRun.value && jobs.value.some(job => job.run_id === selectedRunId.value && ['queued', 'running', 'unknown'].includes(job.status)))
const productImage = computed(() => project.value.product.uploads.find(item => item.id === project.value.product.selectedUploadId) || project.value.product.uploads[0])
const modelImage = computed(() => project.value.model.source === 'upload'
  ? project.value.model.uploads.find(item => item.id === project.value.model.selectedUploadId) || project.value.model.uploads[0]
  : project.value.model.results.find(item => item.id === project.value.model.activeResultId))
const sceneImage = computed(() => selectedSceneReference(project.value.scene))
const removed = ref<{ kind: SectionKind; item: Result | null; index: number; jobId: number; activeResultId: string } | null>(null)
const deleting = ref(false)
const longImageOpen = ref(false)
const exportCampaignId = ref('')
const exportPageIds = ref<string[]>([])
const exportBusy = ref(false)
const detailCampaigns = computed(() => {
  const groups = new Map<string, { id: string; title: string; pages: Job[] }>()
  for (const job of jobs.value.filter(job => job.kind === 'creative')) {
    const metadata = (job.request.original as ImageRequest).campaign
    if (!metadata || job.mode !== 'image' || !/^D\d+$/.test(job.page_key)) continue
    if (!groups.has(metadata.runId)) groups.set(metadata.runId, { id: metadata.runId, title: job.create_time, pages: [] })
    const group = groups.get(metadata.runId)!
    const existing = group.pages.findIndex(page => page.page_key === job.page_key)
    if (existing >= 0) group.pages[existing] = job; else group.pages.push(job)
  }
  return [...groups.values()].reverse().map(group => ({ ...group, pages: group.pages.filter(job => job.status === 'success' && !hiddenJobs.value.includes(job.id)).sort((a, b) => Number(a.page_key.slice(1)) - Number(b.page_key.slice(1))) })).filter(group => group.pages.length)
})
const exportPages = computed(() => detailCampaigns.value.find(group => group.id === exportCampaignId.value)?.pages || [])
const labels: Record<string, string> = { ethnicity: t('外观族裔'), gender: t('性别'), age: t('年龄段'), build: t('体型'), hair: t('发型'), category: t('商品类别'), presentation: t('陈列方式') }
const isGenerating = (job: Job) => job.status === 'running' || (job.status === 'queued' && job.provider_submitted === true)
const statusText = (job: Job) => isGenerating(job) ? t('生成中') : ({ queued: t('等待生成'), running: t('生成中'), success: t('已完成'), failed: t('生成失败'), unknown: t('待核对'), cancelled: t('已取消') })[job.status]
const generationSignature = computed(() => JSON.stringify({ projectId: id.value, product: project.value.product, model: project.value.model, scene: project.value.scene,
  creative: { ...project.value.creative, results: [], activeResultId: '' }, integration: { ...project.value.integration, hiddenJobs: [] } }))

async function attempt(action: () => Promise<unknown> | unknown) {
  pageError.value = ''
  try { await action() } catch (error) { pageError.value = errorText(error); work.notify(error) }
}
function queueEstimate() {
  clearTimeout(estimateTimer)
  const epoch = ++estimateEpoch
  estimate.value = null; estimateItems.value = []; estimateError.value = ''
  if (loading.value || !id.value || (!project.value.product.uploads.length && !project.value.product.results.length)) { estimating.value = false; return }
  if (storyNeedsExpansion.value) { estimating.value = false; return }
  estimating.value = true
  estimateTimer = setTimeout(async () => {
    try {
      const items = work.build('creative')
      const result = await ecommerce.quote(id.value, items)
      if (disposed || epoch !== estimateEpoch) return
      estimate.value = result; estimateItems.value = items
    } catch (error) { if (epoch === estimateEpoch) estimateError.value = errorText(error) }
    finally { if (epoch === estimateEpoch) estimating.value = false }
  }, 450)
}
watch([generationSignature, loading, imageModel], queueEstimate)
watch(id, () => { selectedRunId.value = 0; pendingSubmit = null; removed.value = null; pageError.value = ''; storyWorking.value = false; pendingCopy = null; copyError.value = ''; copyEditorJob.value = null; lastImageGoal.value = goal.value === 'video' ? 'poster' : goal.value })
watch(outputRuns, value => { if (selectedRunId.value === 0 && value.length) selectedRunId.value = value[0]!.id })
onMounted(() => { void attempt(work.init) })
onBeforeUnmount(() => { disposed = true; clearTimeout(estimateTimer) })
onBeforeRouteLeave(async () => { try { await work.save(); return !saveError.value } catch { return false } })

async function upload(kind: SectionKind, files: File[]) {
  dragging.value = false
  if (uploadsBusy.value || kind === 'creative' || !files.length) return
  const target = project.value[kind]
  if (target.uploads.length + files.length > 12) throw new Error(t('每个区域最多上传 12 张图片'))
  if (files.some(file => !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 10 * 1024 * 1024)) throw new Error(t('请选择不超过 10MB 的 JPG、PNG 或 WebP 图片'))
  const projectId = id.value
  uploadsBusy.value = true
  try {
    for (const file of files) {
      const media = await ecommerce.upload(projectId, file)
      if (id.value !== projectId) return
      target.uploads.push({ ...media, id: crypto.randomUUID() })
      target.selectedUploadId = target.uploads.at(-1)!.id
      if (kind === 'product') project.value.integration!.useProcessedProduct = false
      if (kind === 'model') { target.source = 'upload'; project.value.creative.useModel = true }
      if (kind === 'scene') { target.source = 'upload'; project.value.creative.useScene = true }
    }
    await work.save()
  } finally { uploadsBusy.value = false }
  if (kind === 'product' && copyNeeded.value && hasTextModel.value) void draftCopy()
  if (goal.value === 'video') void storyComposer.value?.analyze()
}
function chooseReference(kind: SectionKind) { referenceKind.value = kind; referenceInput.value?.click() }
function readFiles(event: Event, kind: SectionKind) {
  const input = event.target as HTMLInputElement
  const files = Array.from(input.files || []); input.value = ''
  void attempt(() => upload(kind, files))
}
function selectUpload(kind: SectionKind, item: Media) {
  project.value[kind].selectedUploadId = item.id
  if (kind === 'product') project.value.integration!.useProcessedProduct = false
  if (kind === 'model') { project.value.model.source = 'upload'; project.value.creative.useModel = true }
  if (kind === 'scene') { project.value.scene.source = 'upload'; project.value.creative.useScene = true }
}
function removeUpload(kind: SectionKind, mediaId: string) {
  const target = project.value[kind]
  target.uploads = target.uploads.filter(item => item.id !== mediaId)
  if (target.selectedUploadId === mediaId) target.selectedUploadId = target.uploads[0]?.id || ''
  if (kind === 'product' && project.value.product.config.marking.imageId === mediaId) project.value.product.config.marking = { mode: 'auto', imageId: '', marks: [] }
  if (kind === 'model' && !target.uploads.length && !target.results.length) project.value.creative.useModel = false
  if (kind === 'scene' && !target.uploads.length && !target.results.length) project.value.creative.useScene = false
  if (kind === 'product') for (const character of project.value.creative.video.story.characters) {
    if (character.image?.id === mediaId) { character.source = 'description'; character.image = null }
  }
  if (goal.value === 'video' && project.value.product.uploads.length) void nextTick(() => storyComposer.value?.analyze())
}
async function openAssets(kind: SectionKind) {
  assetKind.value = kind; assetsOpen.value = true; assetsLoading.value = true
  try { assets.value = await listAssets({ scope: 'mine' }) } finally { assetsLoading.value = false }
}
async function selectAsset(asset: Asset, imageId: number) {
  const kind = assetKind.value
  if (project.value[kind].uploads.length >= 12) throw new Error(t('每个区域最多上传 12 张图片'))
  const projectId = id.value
  const media = await ecommerce.useAsset(projectId, asset.id, imageId)
  if (id.value !== projectId) return
  media.id = crypto.randomUUID(); project.value[kind].uploads.push(media); selectUpload(kind, media)
  assetsOpen.value = false
  if (kind === 'product' && copyNeeded.value && hasTextModel.value) void draftCopy()
  if (goal.value === 'video') void storyComposer.value?.analyze()
}
function openMarker() { markerImage.value = productImage.value || null; markerOpen.value = !!markerImage.value }
async function saveMarking(marking: Marking) {
  const blob = await (await fetch(marking.annotatedSrc!)).blob()
  const media = await ecommerce.upload(id.value, new File([blob], 'product-marking.jpg', { type: 'image/jpeg' }))
  project.value.product.config.marking = { ...marking, annotatedSrc: media.src }; markerOpen.value = false
}
function selectReferenceResult(kind: SectionKind, item: Media) {
  project.value[kind].activeResultId = item.id
  if (kind === 'product') project.value.integration!.useProcessedProduct = true
  if (kind === 'model') { project.value.model.source = 'ai'; project.value.creative.useModel = true }
  if (kind === 'scene') { project.value.scene.source = 'custom'; project.value.creative.useScene = true }
}
function selectScene(presetId: string) {
  project.value.scene.source = 'presets'; project.value.scene.presetId = presetId
  project.value.scene.activeResultId = ''; project.value.creative.useScene = true
}
function renameProduct() { if (project.value.product.config.name.trim()) project.value.name = project.value.product.config.name.trim().slice(0, 160) }
async function saveStory() { await work.save(); if (saveError.value) throw new Error(saveError.value) }
function generateCharacter(character: StoryCharacter) { return prepare(work.imageItems('model', characterImageRequest(character, project.value.model.quality), character.name)) }
async function draftCopy(force = false) {
  if (copyBusy.value) return false
  if (!force && !copyNeeded.value) return true
  const projectId = id.value, target = project.value.creative.copy
  const signature = () => JSON.stringify({ projectId: id.value, uploads: project.value.product.uploads, primary: project.value.product.selectedUploadId, product: project.value.product.config, requirements: project.value.creative.notes })
  const before = signature(), previous = JSON.stringify(target)
  if (!pendingCopy || pendingCopy.signature !== before) pendingCopy = { signature: before, key: crypto.randomUUID() }
  const requestKey = pendingCopy.key
  copyBusy.value = true; copyError.value = ''
  try {
    await saveStory()
    if (disposed || projectId !== id.value || signature() !== before) return false
    const result = await ecommerce.draftCopy(projectId, requestKey)
    if (disposed || projectId !== id.value || signature() !== before || JSON.stringify(target) !== previous) return false
    Object.assign(target, { headline: result.headline, subtitle: result.subtitle, sellingPoints: result.selling_points })
    pendingCopy = null
    await saveStory()
    return true
  } catch (error) {
    if (projectId === id.value) copyError.value = errorText(error)
    if (error instanceof ApiError && ![0, 409, 502, 504].includes(error.code)) pendingCopy = null
    return false
  } finally { copyBusy.value = false }
}
function jobPoster(job: Job): Poster | null {
  if (templateJob(job)) return null
  if (Object.prototype.hasOwnProperty.call(project.value.integration?.posterOverrides || {}, String(job.id))) return project.value.integration!.posterOverrides![String(job.id)] || null
  return (job.request.original as ImageRequest).poster || null
}
function templateJob(job: Job) { return (job.request.original as ImageRequest).design?.layout === 'template' }
function openCopyEditor(job: Job) {
  copyEditorJob.value = job
  editedPoster.value = clone(jobPoster(job) || { headline: project.value.creative.copy.headline, subtitle: project.value.creative.copy.subtitle, sellingPoints: project.value.creative.copy.sellingPoints, presetId: project.value.creative.presetId })
}
async function savePosterCopy(remove = false) {
  if (!copyEditorJob.value || !editedPoster.value) return
  const overrides = project.value.integration!.posterOverrides ||= {}
  overrides[String(copyEditorJob.value.id)] = remove ? null : validatePoster(editedPoster.value)
  await saveStory(); copyEditorJob.value = null
}
async function jobFile(job: Job) { const poster = jobPoster(job); return job.mode === 'image' && poster?.headline.trim() ? posterBlob(job.result_url, poster) : fetchFile(job.result_url) }
function showResults() { resultsPane.value?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }
async function generateMain() {
  if (busy.value || storyWorking.value || copyBusy.value) return
  if (goal.value === 'video') {
    if (await storyComposer.value?.continueFlow()) await prepare(work.build('creative'))
    return
  }
  if (copyNeeded.value) {
    if (await draftCopy()) await prepare(work.build('creative'))
    return
  }
  if (busy.value || estimating.value || !estimate.value) return
  const signature = generationSignature.value
  if (!pendingSubmit || pendingSubmit.signature !== signature) pendingSubmit = { signature, key: crypto.randomUUID(), items: clone(estimateItems.value) }
  const submission = pendingSubmit
  busy.value = true
  try {
    await work.save()
    if (saveError.value) throw new Error(saveError.value)
    const detail = await ecommerce.generate(id.value, submission.items, submission.key)
    acceptSubmission(detail, true)
    pendingSubmit = null
  } finally { busy.value = false }
}
function acceptSubmission(detail: ProjectDetail, selectBatch = false) {
  work.integrate(detail)
  if (selectBatch) selectedRunId.value = detail.runs.at(-1)?.id || 0
  if (!advancedOpen.value) showResults()
  ElMessage.success(t('已开始制作'))
}
async function prepare(items: PlanItem[]) {
  if (busy.value) return
  busy.value = true
  try {
    await work.save()
    if (saveError.value) throw new Error(saveError.value)
    quoteItems.value = clone(items); quote.value = await ecommerce.quote(id.value, items)
    quoteKey.value = crypto.randomUUID(); quoteOpen.value = true
  } finally { busy.value = false }
}
async function submitQuote() {
  busy.value = true
  try {
    const detail = await ecommerce.generate(id.value, quoteItems.value, quoteKey.value)
    for (const item of quoteItems.value) {
      const original = item.request.original as ImageRequest
      const character = project.value.creative.video.story.characters.find(character => character.id === original.storyCharacterId && character.description === original.storyCharacterDescription)
      if (character) { character.source = 'ai'; character.image = null }
    }
    acceptSubmission(detail, quoteItems.value.some(item => item.kind === 'creative'))
    await work.save()
    quoteOpen.value = false; editResult.value = null
  } finally { busy.value = false }
}
function jobResult(job: Job) { return project.value[job.kind].results.find(item => item.id === `job-${job.id}`) }
function startEdit(kind: SectionKind, result: Result) { editKind.value = kind; editResult.value = result; editInstruction.value = '' }
async function edit() {
  if (!editResult.value) return
  const section = clone(project.value[editKind.value])
  if (editKind.value === 'creative') (section as Project['creative']).mode = 'image'
  const request = buildEditRequest(editKind.value, section, editResult.value, editInstruction.value)
  const job = jobs.value.find(job => `job-${job.id}` === editResult.value!.id)
  if (job) { const poster = jobPoster(job); if (poster) request.poster = clone(poster); else delete request.poster }
  request.n = 1
  await prepare(work.imageItems(editKind.value, request, `${editResult.value.name} · ${t('修改版')}`))
}
async function retry(job: Job) { await prepare([{ kind: job.kind, mode: job.mode, title: job.title, page_key: job.page_key, model_config_id: job.model_config_id, request: clone(job.request) }]) }
async function checkOriginal(job: Job) { work.integrate(await ecommerce.check(job.id)) }
async function control(action: 'pause' | 'resume' | 'cancel') { if (selectedRunId.value) work.integrate(await ecommerce.control(selectedRunId.value, action)) }
async function removeJob(job: Job) {
  if (deleting.value || !['success', 'failed', 'cancelled'].includes(job.status)) return
  if (saveError.value) throw new Error(saveError.value)
  const section = project.value[job.kind]; const index = section.results.findIndex(item => item.id === `job-${job.id}`)
  removed.value = { kind: job.kind, item: index >= 0 ? clone(section.results[index]!) : null, index, jobId: job.id, activeResultId: section.activeResultId }
  if (index >= 0) section.results.splice(index, 1)
  if (section.activeResultId === `job-${job.id}`) section.activeResultId = section.results.at(-1)?.id || ''
  project.value.integration!.hiddenJobs = [...new Set([...hiddenJobs.value, job.id])]
  deleting.value = true
  try { await saveStory() } finally { deleting.value = false }
}
async function undoRemove() {
  if (!removed.value || deleting.value) return
  if (saveError.value) throw new Error(saveError.value)
  const previous = removed.value
  const section = project.value[previous.kind]
  if (previous.item) section.results.splice(previous.index, 0, previous.item)
  if (previous.activeResultId === `job-${previous.jobId}`) section.activeResultId = previous.activeResultId
  project.value.integration!.hiddenJobs = hiddenJobs.value.filter(id => id !== previous.jobId); removed.value = null
  deleting.value = true
  try { await saveStory() } finally { deleting.value = false }
}
async function saveAsset(job: Job) {
  if (savingAssets.value.includes(job.id)) return
  const projectId = id.value, signature = JSON.stringify([job.result_url, jobPoster(job)])
  savingAssets.value.push(job.id)
  try {
    let url = ''
    if (jobPoster(job)?.headline.trim()) {
      const media = await ecommerce.upload(projectId, new File([await jobFile(job)], 'poster.png', { type: 'image/png' })); url = media.src
    }
    const saved = await ecommerce.saveAsset(job.id, url)
    if (id.value === projectId) {
      job.asset_id = saved.asset_id
      ;(project.value.integration!.savedPosters ||= {})[String(job.id)] = signature
      await saveStory()
    }
    ElMessage.success(t('已保存至资产'))
  } finally { savingAssets.value = savingAssets.value.filter(id => id !== job.id) }
}
function assetSaved(job: Job) { return !!job.asset_id && (project.value.integration?.savedPosters?.[String(job.id)] === JSON.stringify([job.result_url, jobPoster(job)]) || (!jobPoster(job) && !project.value.integration?.savedPosters?.[String(job.id)])) }
function safeName(value: string) { return value.replace(/[<>:"/\\|?*\x00-\x1f]/g, '_').slice(0, 140) || 'product' }
function downloadBlob(blob: Blob, name: string) {
  const url = URL.createObjectURL(blob); const a = document.createElement('a'); a.href = url; a.download = safeName(name); a.click(); setTimeout(() => URL.revokeObjectURL(url), 1000)
}
async function fetchFile(url: string) { const response = await fetch(url); if (!response.ok) throw new Error(t('下载失败，请稍后重试')); return response.blob() }
async function downloadJob(job: Job) { downloadBlob(await jobFile(job), `${job.page_key || job.title}.${job.mode === 'image' ? 'png' : 'mp4'}`) }
async function downloadAll() {
  downloadBusy.value = true
  try {
    const files: Record<string, Uint8Array> = {}; let total = 0
    for (const [index, job] of successfulJobs.value.entries()) {
      const blob = await jobFile(job); total += blob.size
      if (total > 200 * 1024 * 1024) throw new Error(t('文件较大，请逐项下载'))
      const extension = job.mode === 'video' ? 'mp4' : blob.type === 'image/jpeg' ? 'jpg' : blob.type === 'image/webp' ? 'webp' : 'png'
      files[`${String(index + 1).padStart(2, '0')}-${safeName(job.title)}.${extension}`] = new Uint8Array(await blob.arrayBuffer())
    }
    downloadBlob(new Blob([new Uint8Array(zipSync(files, { level: 0 })).buffer], { type: 'application/zip' }), `${safeName(project.value.name)}.zip`)
  } finally { downloadBusy.value = false }
}
function selectExportCampaign(value: string) { exportCampaignId.value = value; exportPageIds.value = exportPages.value.map(page => page.page_key) }
function openLongImage() {
  if (!detailCampaigns.value.length) throw new Error(t('暂无可导出的详情页'))
  const metadata = visibleJobs.value[0]?.request.original as ImageRequest | undefined
  selectExportCampaign(detailCampaigns.value.find(group => group.id === metadata?.campaign?.runId)?.id || detailCampaigns.value[0]!.id)
  longImageOpen.value = true
}
async function exportLongImage() {
  const selected = exportPages.value.filter(job => exportPageIds.value.includes(job.page_key))
  if (!selected.length) return
  exportBusy.value = true
  try {
    const images: HTMLImageElement[] = []
    for (const job of selected) {
      const url = URL.createObjectURL(await jobFile(job))
      try { const image = new Image(); image.src = url; await image.decode(); images.push(image) } finally { URL.revokeObjectURL(url) }
    }
    const width = Math.min(1600, images[0]!.naturalWidth)
    const heights = images.map(image => Math.round(image.naturalHeight / image.naturalWidth * width)); const height = heights.reduce((a, b) => a + b, 0)
    if (height > 30000 || width * height > 48000000) throw new Error(t('长图尺寸过大，请减少选择的详情页'))
    const canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height
    const context = canvas.getContext('2d')!; context.fillStyle = '#fff'; context.fillRect(0, 0, width, height)
    let y = 0; images.forEach((image, index) => { context.drawImage(image, 0, y, width, heights[index]!); y += heights[index]! })
    const blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, 'image/jpeg', .95))
    if (!blob) throw new Error(t('下载失败，请稍后重试'))
    downloadBlob(blob, `${project.value.name}-detail.jpg`); longImageOpen.value = false
  } finally { exportBusy.value = false }
}
async function exportProject() {
  await work.save()
  const state = clone(project.value); const cache = new Map<string, string>()
  const convert = async (value: unknown, key = ''): Promise<unknown> => {
    if (typeof value === 'string' && value && ['src', 'url', 'images', 'references', 'productImages', 'annotatedSrc'].includes(key)) {
      if (!cache.has(value)) {
        const blob = await fetchFile(value)
        cache.set(value, await new Promise<string>((resolve, reject) => { const reader = new FileReader(); reader.onload = () => resolve(String(reader.result)); reader.onerror = reject; reader.readAsDataURL(blob) }))
      }
      return cache.get(value)
    }
    if (Array.isArray(value)) return Promise.all(value.map(item => convert(item, key)))
    if (value && typeof value === 'object') { const out: Record<string, unknown> = {}; for (const [k, v] of Object.entries(value)) out[k] = await convert(v, k); return out }
    return value
  }
  downloadBlob(new Blob([JSON.stringify(await convert(state), null, 2)], { type: 'application/json' }), `${state.name}.json`)
}
async function importProject(event: Event) {
  const input = event.target as HTMLInputElement; const file = input.files?.[0]; input.value = ''
  if (!file) return
  if (file.size > 80 * 1024 * 1024) throw new Error(t('项目文件不能超过 80MB'))
  const data = JSON.parse(await file.text()) as Project
  const candidate = data.schema === 1 ? data : (data as unknown as { project: Project }).project
  const state = validateProject(candidate); state.integration = candidate.integration || {}
  state.creative.video.tasks = []; state.creative.video.activeTaskId = ''; state.creative.campaign.activeRun = null
  await work.create(state, true); ElMessage.success(t('项目已导入'))
}
async function deleteProject() {
  try { await ElMessageBox.confirm(t('删除当前商品及其生成记录？'), t('删除商品'), { type: 'warning' }) } catch { return }
  await ecommerce.delete(id.value)
  projects.value = await ecommerce.list()
  if (projects.value[0]) await work.open(Number(projects.value[0].id)); else await work.create()
}
</script>

<template>
  <div v-loading="loading" class="ecommerce-box storefront">
    <header class="storefront-header">
      <div class="page-title"><el-icon :size="24"><component :is="resolveIcon('Box')" /></el-icon><h1>{{ t('电商宝盒') }}</h1><span class="save-state" :class="{ error: saveError }">{{ saveError ? t('保存失败') : saving ? t('保存中') : dirty ? t('待保存') : t('已保存') }}</span></div>
      <div class="project-controls"><el-select :model-value="id || undefined" :disabled="busy || uploadsBusy" :aria-label="t('切换商品')" @change="value => attempt(() => work.open(Number(value)))"><el-option v-for="item in projects" :key="item.id" :value="Number(item.id)" :label="item.name" /></el-select><el-button :icon="resolveIcon('Plus')" :disabled="busy || uploadsBusy" @click="attempt(() => work.create())">{{ t('新商品') }}</el-button><el-dropdown trigger="click" :disabled="busy"><el-button :icon="resolveIcon('MoreFilled')" :aria-label="t('商品操作')" /><template #dropdown><el-dropdown-menu><el-dropdown-item :icon="resolveIcon('Download')" @click="attempt(exportProject)">{{ t('导出项目') }}</el-dropdown-item><el-dropdown-item :icon="resolveIcon('Upload')" @click="importInput?.click()">{{ t('导入项目') }}</el-dropdown-item><el-dropdown-item divided :icon="resolveIcon('Delete')" @click="attempt(deleteProject)">{{ t('删除商品') }}</el-dropdown-item></el-dropdown-menu></template></el-dropdown></div>
    </header>
    <el-alert v-if="pageError || saveError" :title="saveError || pageError" type="error" show-icon :closable="!saveError" @close="pageError = ''"><template v-if="saveError" #default><el-button link @click="saveError = ''; attempt(work.save)">{{ t('重试保存') }}</el-button></template></el-alert>
    <div class="storefront-workspace">
      <aside class="creation-pane">
        <div class="creation-fields">
          <section class="product-section">
            <div class="section-heading"><h2>{{ goal === 'video' ? t('创作素材') : t('商品图片') }}</h2><el-button link :icon="resolveIcon('FolderOpened')" @click="attempt(() => openAssets('product'))">{{ t('我的资产') }}</el-button></div>
            <div class="product-dropzone" :class="{ dragging, empty: !project.product.uploads.length }" @dragover.prevent="dragging = true" @dragleave.prevent="dragging = false" @drop.prevent="event => attempt(() => upload('product', Array.from(event.dataTransfer?.files || [])))">
              <button v-if="!project.product.uploads.length" type="button" class="empty-upload" :disabled="uploadsBusy || loading" @click="productInput?.click()"><span class="upload-symbol"><el-icon :size="28" :class="{ 'is-loading': uploadsBusy }"><component :is="resolveIcon(uploadsBusy ? 'Loading' : 'Plus')" /></el-icon></span><strong>{{ uploadsBusy ? t('上传中') : goal === 'video' ? t('添加商品或人物图片') : t('添加商品图片') }}</strong><span>JPG / PNG / WebP</span></button>
              <MediaStrip v-else :items="project.product.uploads" :selected-id="project.product.selectedUploadId" :busy="uploadsBusy" upload @files="files => attempt(() => upload('product', files))" @select="item => selectUpload('product', item)" @remove="mediaId => removeUpload('product', mediaId)" @preview="item => fullPreview = item" />
            </div>
            <div v-if="project.product.uploads.length" class="product-tools"><span>{{ goal === 'video' ? t('{count} 张素材', { count: project.product.uploads.length }) : t('{count} 张商品参考', { count: project.product.uploads.length }) }}</span></div>
          </section>
          <section class="goal-section">
            <el-radio-group v-model="contentMode" class="content-mode" :disabled="busy || storyWorking || copyBusy" :aria-label="t('制作类型')"><el-radio-button value="image"><el-icon><component :is="resolveIcon('Picture')" /></el-icon>{{ t('商品图片') }}</el-radio-button><el-radio-button value="video"><el-icon><component :is="resolveIcon('VideoCamera')" /></el-icon>{{ t('商品视频') }}</el-radio-button></el-radio-group>
            <div v-if="goal === 'video'" class="video-quick-options">
              <div class="duration-options"><span>{{ t('视频时长') }}</span><el-radio-group v-model="project.creative.video.duration" :disabled="busy || storyWorking" :aria-label="t('视频时长')" size="small"><el-radio-button v-for="duration in [5,10,15]" :key="duration" :value="duration">{{ t('{count} 秒', { count: duration }) }}</el-radio-button></el-radio-group></div>
              <StoryComposer ref="storyComposer" :key="id" :project="project" :project-id="id" :jobs="jobs" :busy="busy" :has-text-model="hasTextModel" :save="saveStory" @working="value => storyWorking = value" @stage="value => videoStage = value" @generate="character => attempt(() => generateCharacter(character))" @check="job => attempt(() => checkOriginal(job))" />
            </div>
          </section>
          <section v-if="goal === 'detail'" class="style-section detail-template"><div class="section-heading"><h2>{{ t('详情模板') }}</h2></div><button type="button" class="selected-style" @click="styleOpen = true"><img :src="style.cover" :alt="style.name" /><span><strong>{{ style.name }}</strong><small>{{ style.description }}</small></span><el-icon><component :is="resolveIcon('ArrowRight')" /></el-icon></button></section>
          <section v-if="copyApplicable" class="copy-section">
            <div class="section-heading"><h2>{{ t('宣传文案') }}</h2><el-switch v-model="project.creative.copy.enabled" :disabled="copyBusy" :aria-label="t('加入宣传文案')" size="small" /></div>
            <template v-if="project.creative.copy.enabled">
              <el-input v-model="project.creative.copy.headline" maxlength="20" :disabled="copyBusy" :aria-label="t('宣传标题')" :placeholder="t('宣传标题')" />
              <el-input v-model="project.creative.copy.subtitle" maxlength="40" :disabled="copyBusy" :aria-label="t('宣传副标题')" :placeholder="t('补充一句（选填）')" />
              <div v-if="project.creative.copy.sellingPoints.length" class="copy-points"><el-input v-for="(_, index) in project.creative.copy.sellingPoints" :key="index" v-model="project.creative.copy.sellingPoints[index]" maxlength="16" :disabled="copyBusy" :aria-label="t('卖点 {count}', { count: index + 1 })" /></div>
              <div class="copy-action"><span>{{ t('文案按文本用量计费') }}</span><el-button link :icon="resolveIcon('MagicStick')" :loading="copyBusy" :disabled="busy || uploadsBusy || !hasTextModel || !project.product.uploads.length" @click="draftCopy(true)">{{ project.creative.copy.headline ? t('重新拟写') : t('AI 拟写') }}</el-button></div>
              <p v-if="copyError" role="alert" class="copy-error">{{ copyError }}</p>
            </template>
          </section>
          <section v-if="goal !== 'video'" class="requirements-section"><div class="section-heading"><h2>{{ t('有什么要求？') }}</h2></div><el-input v-model="project.creative.notes" type="textarea" :rows="4" maxlength="4000" :aria-label="t('制作要求')" :placeholder="goal === 'tryon' ? t('例如：年轻女模特，日常通勤穿搭，突出衣服版型（选填）') : t('例如：清爽干净的背景，突出纯棉材质，保留商品原色（选填）')" /></section>
          <section v-if="goal === 'tryon'" class="model-choice"><div class="section-heading"><h2>{{ t('模特') }}</h2></div><el-select v-model="modelChoice" :aria-label="t('模特')" :disabled="busy"><el-option value="auto" :label="t('自动搭配')" /><el-option v-for="item in project.model.uploads" :key="item.id" :value="`upload:${item.id}`" :label="item.name" /><el-option v-for="item in project.model.results" :key="item.id" :value="`result:${item.id}`" :label="item.name" /><el-option value="upload" :label="t('上传模特')" /><el-option value="asset" :label="t('从我的资产选择')" /><el-option value="generate" :label="t('生成虚拟模特')" /></el-select><div v-if="modelSetup" class="model-setup"><el-input v-model="project.model.config.notes" type="textarea" :rows="2" :aria-label="t('模特要求')" :placeholder="t('例如：年轻女模特，自然短发，日常穿搭')" /><el-button :loading="busy" :icon="resolveIcon('MagicStick')" @click="attempt(() => prepare(work.build('model')))">{{ t('生成模特参考') }}</el-button></div></section>
          <button type="button" class="advanced-trigger" :disabled="busy || storyWorking" :aria-expanded="advancedOpen" @click="advancedOpen = !advancedOpen"><el-icon><component :is="resolveIcon('Setting')" /></el-icon><span>{{ t('更多配置') }}</span><el-icon><component :is="resolveIcon(advancedOpen ? 'ArrowUp' : 'ArrowDown')" /></el-icon></button>
          <div id="ecommerce-inline-settings" />
        </div>
        <footer class="creation-footer">
          <div class="creation-summary"><span>{{ goal === 'video' ? t('{count} 秒视频', { count: project.creative.video.duration }) : t('{count} 张成品', { count: outputCount }) }}</span><strong v-if="estimate">{{ estimate.estimated ? t('预计 {count} 积分', { count: estimate.credits }) : t('{count} 积分', { count: estimate.credits }) }}</strong><span v-else-if="estimating">{{ t('计算费用中') }}</span></div>
          <p v-if="estimateError" class="estimate-error">{{ estimateError }}</p><p v-if="estimate?.estimated" class="settlement-note">{{ t('按实际用量结算') }}</p>
          <el-button class="generate-button" size="large" type="primary" :icon="resolveIcon('MagicStick')" :loading="busy || storyWorking || copyBusy" :disabled="loading || uploadsBusy || storyWorking || copyBusy || (goal === 'video' ? !hasTextModel || !project.product.uploads.length : estimating || !estimate) || !!saveError" @click="attempt(generateMain)">{{ goal === 'video' ? ['ready','confirmed'].includes(videoStage) ? t('满意，生成视频') : t('完善视频方案') : t('生成{content}', { content: t(selectedGoal.label) }) }}</el-button>
          <el-button v-if="visibleJobs.length" class="mobile-results-link" link @click="showResults">{{ t('查看成品') }}<el-icon><component :is="resolveIcon('Bottom')" /></el-icon></el-button>
        </footer>
      </aside>

      <main ref="resultsPane" class="results-pane">
        <div class="results-heading"><div><h2>{{ t('商品成品') }}</h2><span v-if="visibleJobs.length">{{ t('{count} 项', { count: visibleJobs.length }) }}</span></div><div class="result-tools"><el-select v-if="outputRuns.length" v-model="selectedRunId" class="batch-select" :aria-label="t('生成批次')"><el-option v-for="run in outputRuns" :key="run.id" :value="run.id" :label="`${run.create_time.slice(5,16)} / ${run.snapshot.items.length}`" /></el-select><el-button :icon="resolveIcon('Refresh')" :aria-label="t('刷新')" :title="t('刷新')" @click="attempt(work.refresh)" /></div></div>
        <div v-if="visibleJobs.length" class="output-toolbar"><div class="batch-status"><span v-if="activeJobs.length" class="activity-dot" /><span>{{ activeJobs.length ? t('已完成 {done} / {total}', { done: successfulJobs.length, total: visibleJobs.length }) : t('{count} 项已完成', { count: successfulJobs.length }) }}</span><template v-if="hasActiveRun"><el-button link :icon="resolveIcon(selectedRun?.paused ? 'VideoPlay' : 'VideoPause')" @click="attempt(() => control(selectedRun?.paused ? 'resume' : 'pause'))">{{ selectedRun?.paused ? t('继续制作') : t('暂停后续') }}</el-button><el-button link :icon="resolveIcon('Close')" :title="t('取消未提交项')" :aria-label="t('取消未提交项')" @click="attempt(() => control('cancel'))" /></template></div><div class="download-actions"><el-button v-if="detailCampaigns.length" :icon="resolveIcon('Download')" @click="attempt(openLongImage)">{{ t('详情长图') }}</el-button><el-button :icon="resolveIcon('Download')" :disabled="!successfulJobs.length" :loading="downloadBusy" @click="attempt(downloadAll)">{{ t('打包下载') }}</el-button></div></div>
        <div v-if="removed" class="undo-bar" role="status"><span>{{ t('成品已删除') }}</span><el-button link :disabled="deleting" @click="attempt(undoRemove)">{{ t('撤销') }}</el-button></div>
        <div v-if="!visibleJobs.length" class="output-empty">
          <div v-if="productImage" class="source-preview"><PosterImage :src="productImage.src" :poster="copyOverlayApplicable && project.creative.copy.enabled && project.creative.copy.headline.trim() ? { ...project.creative.copy, presetId: project.creative.presetId } : null" :alt="productImage.name" large /><span>{{ copyOverlayApplicable && project.creative.copy.enabled && project.creative.copy.headline.trim() ? t('文案预览') : t('商品参考') }}</span></div>
          <div v-else class="empty-silhouette"><el-icon :size="70"><component :is="resolveIcon('Goods')" /></el-icon></div>
          <h3>{{ t(selectedGoal.label) }}</h3><div class="planned-output"><span>{{ goal === 'video' ? t('{count} 秒', { count: project.creative.video.duration }) : t('{count} 张', { count: outputCount }) }}</span><span>{{ goal === 'video' ? project.creative.video.aspect_ratio : style.name }}</span><span>{{ t('待制作') }}</span></div>
        </div>
        <div v-else class="job-grid"><article v-for="job in visibleJobs" :key="job.id" class="job-tile" :class="job.status">
          <button type="button" class="job-preview" :disabled="job.status !== 'success'" :aria-label="t('预览{title}', { title: job.title })" @click="job.mode === 'video' ? videoPreview = job : posterPreview = { src: job.result_url, poster: jobPoster(job), name: job.title }"><PosterImage v-if="job.status === 'success' && job.mode === 'image'" :src="job.result_url" :poster="jobPoster(job)" :alt="job.title" /><video v-else-if="job.status === 'success'" :src="job.result_url" preload="metadata" /><div v-else class="job-placeholder"><el-icon :size="26" :class="{ 'is-loading': isGenerating(job) }"><component :is="resolveIcon(isGenerating(job) ? 'Loading' : job.status === 'failed' || job.status === 'unknown' ? 'Warning' : 'Clock')" /></el-icon><span>{{ statusText(job) }}</span></div><span v-if="job.mode === 'video' && job.status === 'success'" class="video-play"><el-icon :size="30"><component :is="resolveIcon('VideoPlay')" /></el-icon></span></button>
          <el-button v-if="['success','failed','cancelled'].includes(job.status)" class="delete-result" :icon="resolveIcon('Delete')" :title="t('删除成品')" :aria-label="t('删除成品')" :disabled="deleting || !!saveError" @click="attempt(() => removeJob(job))" />
          <div class="job-info"><strong>{{ job.title }}</strong><small>{{ statusText(job) }}</small></div><p v-if="job.error_message" class="job-error">{{ job.error_message }}</p>
          <div class="job-actions">
            <template v-if="job.status === 'success'"><el-button v-if="job.mode === 'image'" :icon="resolveIcon('EditPen')" @click="startEdit(job.kind, jobResult(job)!)">{{ t('改图') }}</el-button><el-button v-if="job.mode === 'image' && job.page_key !== 'H1' && !templateJob(job)" :icon="resolveIcon('Document')" :title="t('改文案')" :aria-label="t('改文案')" @click="openCopyEditor(job)" /><el-button :icon="resolveIcon('Download')" :title="t('下载')" :aria-label="t('下载')" @click="attempt(() => downloadJob(job))" /><el-button v-if="job.mode === 'image'" :icon="resolveIcon('FolderAdd')" :title="t('保存至资产')" :aria-label="t('保存至资产')" :loading="savingAssets.includes(job.id)" :disabled="assetSaved(job)" @click="attempt(() => saveAsset(job))" /></template>
            <el-button v-if="job.status === 'unknown' || job.status === 'failed'" size="small" @click="attempt(async () => work.integrate(await ecommerce.check(job.id)))">{{ t('核对原任务') }}</el-button>
            <el-dropdown v-if="['success','failed'].includes(job.status)" trigger="click"><el-button :icon="resolveIcon('MoreFilled')" :title="t('更多操作')" :aria-label="t('更多操作')" /><template #dropdown><el-dropdown-menu><el-dropdown-item :icon="resolveIcon('RefreshRight')" @click="attempt(() => retry(job))">{{ t('重新生成') }}</el-dropdown-item></el-dropdown-menu></template></el-dropdown>
          </div>
        </article></div>
      </main>
    </div>

    <input ref="productInput" data-testid="product-upload" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden @change="event => readFiles(event, 'product')" />
    <input ref="referenceInput" data-testid="reference-upload" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden @change="event => readFiles(event, referenceKind)" />
    <input ref="importInput" type="file" accept="application/json,.json" hidden @change="event => attempt(() => importProject(event))" />

    <Teleport v-if="advancedOpen" to="#ecommerce-inline-settings"><section class="inline-settings" :aria-label="t('制作设置')">
      <div class="advanced-settings">
        <section v-if="goal !== 'video'" class="settings-group"><el-form label-position="top"><el-form-item :label="t('图片用途')"><el-select v-model="goal" :aria-label="t('图片用途')"><el-option v-for="option in imageGoals" :key="option.id" :value="option.id" :label="t(option.label)" /></el-select></el-form-item></el-form></section>
        <section v-if="goal !== 'video' && goal !== 'detail'" class="settings-group style-section"><div class="section-heading"><h2>{{ t('图片风格') }}</h2></div><button type="button" class="selected-style" @click="styleOpen = true"><img :src="style.cover" :alt="style.name" /><span><strong>{{ style.name }}</strong><small>{{ style.description }}</small></span><el-icon><component :is="resolveIcon('ArrowRight')" /></el-icon></button></section>
        <details class="settings-disclosure settings-group output-settings">
          <summary><el-icon class="disclosure-caret"><component :is="resolveIcon('ArrowRight')" /></el-icon><strong>{{ t('输出规格') }}</strong><span>{{ goal === 'video' ? t('{count} 秒', { count: project.creative.video.duration }) : t('{count} 张', { count: outputCount }) }}</span></summary>
          <el-form label-position="top">
            <template v-if="goal !== 'video'">
              <el-form-item :label="t('图片模型')"><el-select v-model="project.integration!.imageModelId" :aria-label="t('图片模型')"><el-option v-for="model in imageModels" :key="model.id" :value="model.id" :label="model.name" /></el-select></el-form-item>
              <div class="form-pair">
                <el-form-item :label="t('清晰度')"><el-select v-model="project.creative.quality"><el-option value="1K" /><el-option value="2K" /></el-select></el-form-item>
                <el-form-item v-if="goal === 'poster' || goal === 'tryon'" :label="t('生成数量')"><el-select v-model="project.creative.count"><el-option v-for="count in [1,2,4]" :key="count" :value="count" :label="String(count)" /></el-select></el-form-item>
                <el-form-item v-if="isOfficial" :label="t('画质')"><el-select v-model="project.integration!.officialQuality"><el-option v-for="quality in ['auto','low','medium','high','xhigh','max']" :key="quality" :value="quality" /></el-select></el-form-item>
              </div>
            </template>
            <template v-else>
              <el-form-item :label="t('视频模型')"><el-select v-model="project.integration!.videoModelId"><el-option v-for="model in videoModels" :key="model.id" :value="model.id" :label="model.name" /></el-select></el-form-item>
              <el-form-item :label="t('视频风格')"><el-radio-group v-model="project.creative.video.style" class="video-style-options"><el-radio-button value="showcase">{{ t('商业展示') }}</el-radio-button><el-radio-button value="ugc">{{ t('生活种草') }}</el-radio-button></el-radio-group></el-form-item>
              <el-form-item :label="t('画面比例')"><el-select v-model="project.creative.video.aspect_ratio" :aria-label="t('画面比例')"><el-option value="9:16" :label="t('竖屏 9:16')" /><el-option value="16:9" :label="t('横屏 16:9')" /><el-option value="1:1" :label="t('方形 1:1')" /></el-select></el-form-item>
              <div class="form-pair">
                <el-form-item :label="t('清晰度')"><el-select v-model="project.creative.video.resolution"><el-option v-for="resolution in ['480p','720p','1080p','4k']" :key="resolution" :value="resolution" /></el-select></el-form-item>
                <el-form-item :label="t('生成声音')"><el-switch v-model="project.creative.video.generate_audio" /></el-form-item>
              </div>
              <details v-if="!project.creative.video.story.enabled" class="settings-disclosure script-settings">
                <summary><el-icon class="disclosure-caret"><component :is="resolveIcon('ArrowRight')" /></el-icon>{{ t('自定义视频脚本') }}</summary>
                <el-input v-model="project.creative.video.prompt" type="textarea" :rows="4" maxlength="6000" :aria-label="t('自定义视频脚本')" />
              </details>
            </template>
            <el-form-item v-if="goal === 'video'" :label="t('额外要求（选填）')"><el-input v-model="project.creative.notes" type="textarea" :rows="2" maxlength="4000" :placeholder="t('例如：保留商品原色，背景更简洁')" /></el-form-item>
          </el-form>
        </details>

        <details v-if="!(goal === 'main' && style.whiteBackground)" class="settings-disclosure settings-group reference-settings">
          <summary><el-icon class="disclosure-caret"><component :is="resolveIcon('ArrowRight')" /></el-icon><strong>{{ t('指定人物或场景') }}</strong></summary>
          <div v-if="goal !== 'video' && goal !== 'tryon'" class="reference-setting-body model-reference-body">
            <el-form label-position="top"><el-form-item :label="t('模特')"><el-select v-model="modelChoice" :aria-label="t('模特')"><el-option value="auto" :label="t('自动搭配')" /><el-option v-for="item in project.model.uploads" :key="item.id" :value="`upload:${item.id}`" :label="item.name" /><el-option v-for="item in project.model.results" :key="item.id" :value="`result:${item.id}`" :label="item.name" /><el-option value="upload" :label="t('上传模特')" /><el-option value="asset" :label="t('从我的资产选择')" /><el-option value="generate" :label="t('生成虚拟模特')" /></el-select></el-form-item></el-form>
            <div v-if="modelSetup" class="model-setup"><el-input v-model="project.model.config.notes" type="textarea" :rows="2" maxlength="4000" :aria-label="t('模特要求')" /><el-button :icon="resolveIcon('MagicStick')" :loading="busy" @click="attempt(() => prepare(work.build('model')))">{{ t('生成模特参考') }}</el-button></div>
            <ReferenceJobs :jobs="jobs" kind="model" :busy="busy" @check="job => attempt(() => checkOriginal(job))" @retry="job => attempt(() => retry(job))" />
          </div>
          <div class="reference-setting-row scene-setting-row">
            <span class="reference-avatar"><img v-if="sceneImage && project.creative.useScene" :src="sceneImage.src" :alt="t('场景参考')" /><el-icon v-else :size="19"><component :is="resolveIcon('Picture')" /></el-icon></span>
            <div class="reference-setting-label"><strong>{{ t('场景参考') }}</strong><span>{{ project.creative.useScene ? sceneImage?.name || t('未添加') : t('未使用') }}</span></div>
            <el-switch v-model="project.creative.useScene" :aria-label="t('使用场景参考')" />
          </div>
          <div v-if="project.creative.useScene" class="reference-setting-body scene-reference-body">
            <div class="scene-grid"><button v-for="preset in SCENE_PRESETS" :key="preset.id" type="button" :class="{ selected: project.scene.source === 'presets' && preset.id === project.scene.presetId }" @click="selectScene(preset.id)"><img :src="preset.cover" :alt="preset.name" /><span>{{ preset.name }}</span><el-icon v-if="project.scene.source === 'presets' && preset.id === project.scene.presetId" class="scene-check"><component :is="resolveIcon('CircleCheckFilled')" /></el-icon></button></div>
            <div class="reference-actions"><el-button :icon="resolveIcon('Upload')" @click="chooseReference('scene')">{{ t('上传场景') }}</el-button><el-button :icon="resolveIcon('FolderOpened')" @click="attempt(() => openAssets('scene'))">{{ t('我的资产') }}</el-button></div>
            <MediaStrip v-if="project.scene.uploads.length" :items="project.scene.uploads" removable :selected-id="project.scene.selectedUploadId" @select="item => selectUpload('scene', item)" @remove="mediaId => removeUpload('scene', mediaId)" />
            <MediaStrip v-if="project.scene.results.length" :items="project.scene.results" :selected-id="project.scene.activeResultId" @select="item => selectReferenceResult('scene', item)" @preview="item => fullPreview = item" />
            <details class="settings-disclosure"><summary><el-icon class="disclosure-caret"><component :is="resolveIcon('ArrowRight')" /></el-icon>{{ t('生成自定义场景') }}</summary><el-input v-model="project.scene.config.notes" type="textarea" :rows="2" maxlength="4000" /><el-button :icon="resolveIcon('MagicStick')" :loading="busy" @click="attempt(() => prepare(work.build('scene')))">{{ t('生成场景参考') }}</el-button></details>
            <ReferenceJobs :jobs="jobs" kind="scene" :busy="busy" @check="job => attempt(() => checkOriginal(job))" @retry="job => attempt(() => retry(job))" />
          </div>
        </details>

        <details class="settings-disclosure settings-group product-processing">
          <summary><el-icon class="disclosure-caret"><component :is="resolveIcon('ArrowRight')" /></el-icon><strong>{{ t('商品细节与处理') }}</strong></summary>
          <el-form label-position="top"><el-form-item :label="t('商品名称')"><el-input v-model="project.product.config.name" maxlength="120" :aria-label="t('商品名称')" @change="renameProduct" /></el-form-item><el-form-item :label="t('商品资料')"><el-input v-model="project.product.config.notes" type="textarea" :rows="2" maxlength="4000" :aria-label="t('商品资料')" /></el-form-item><div class="form-pair"><el-form-item v-for="(options, key) in PRODUCT_OPTIONS" :key="key" :label="labels[key]"><el-select v-model="project.product.config[key as 'category' | 'presentation']"><el-option v-for="option in options" :key="option.value" :value="option.value" :label="option.label" /></el-select></el-form-item></div></el-form>
          <div class="reference-actions"><el-button :icon="resolveIcon('Aim')" :disabled="!productImage" @click="openMarker">{{ t('标记商品') }}</el-button><el-button :icon="resolveIcon('MagicStick')" :disabled="!project.product.uploads.length" :loading="busy" @click="attempt(() => prepare(work.build('product')))">{{ t('提取干净商品图') }}</el-button></div>
          <el-checkbox v-if="project.product.results.length" v-model="project.integration!.useProcessedProduct">{{ t('使用处理后的商品图') }}</el-checkbox>
          <MediaStrip v-if="project.product.results.length" :items="project.product.results" :selected-id="project.product.activeResultId" @select="item => selectReferenceResult('product', item)" @preview="item => fullPreview = item" />
          <ReferenceJobs :jobs="jobs" kind="product" :busy="busy" @check="job => attempt(() => checkOriginal(job))" @retry="job => attempt(() => retry(job))" />
        </details>
      </div>
      <div class="settings-footer"><el-button link :icon="resolveIcon('ArrowUp')" @click="advancedOpen = false">{{ t('收起配置') }}</el-button></div>
    </section></Teleport>
    <el-dialog :model-value="!!copyEditorJob" :title="t('修改文案')" width="760px" class="ecommerce-dialog" append-to-body @close="copyEditorJob = null"><div v-if="copyEditorJob && editedPoster" class="poster-editor"><div class="poster-editor-preview"><PosterImage :src="copyEditorJob.result_url" :poster="editedPoster.headline.trim() ? editedPoster : null" :alt="copyEditorJob.title" large /></div><el-form label-position="top"><el-form-item :label="t('宣传标题')"><el-input v-model="editedPoster.headline" maxlength="20" :aria-label="t('成品标题')" /></el-form-item><el-form-item :label="t('宣传副标题')"><el-input v-model="editedPoster.subtitle" maxlength="40" :aria-label="t('成品副标题')" /></el-form-item><el-form-item v-for="(_, index) in editedPoster.sellingPoints" :key="index" :label="t('卖点 {count}', { count: index + 1 })"><el-input v-model="editedPoster.sellingPoints[index]" maxlength="16" /></el-form-item></el-form></div><template #footer><el-button @click="attempt(() => savePosterCopy(true))">{{ t('去掉文字') }}</el-button><el-button type="primary" :disabled="!editedPoster?.headline.trim()" @click="attempt(() => savePosterCopy())">{{ t('保存文案') }}</el-button></template></el-dialog>
    <el-dialog :model-value="!!posterPreview" :title="posterPreview?.name" width="1000px" class="ecommerce-dialog" append-to-body @close="posterPreview = null"><div v-if="posterPreview" class="poster-full-preview"><PosterImage :src="posterPreview.src" :poster="posterPreview.poster" :alt="posterPreview.name" large /></div></el-dialog>
    <el-dialog v-model="styleOpen" :title="goal === 'detail' ? t('选择详情模板') : `${t(selectedGoal.label)} · ${t('图片风格')}`" width="900px" class="ecommerce-dialog" append-to-body><div class="style-filters"><el-radio-group v-model="styleCategory" size="small"><el-radio-button v-for="category in styleCategories" :key="category" :value="category">{{ t(category) }}</el-radio-button></el-radio-group></div><div class="style-gallery"><button v-for="preset in visibleStyles" :key="preset.id" type="button" :class="{ selected: preset.id === project.creative.presetId }" @click="selectStorefrontStyle(project, preset.id); styleOpen = false"><img :src="preset.cover" :alt="preset.name" /><strong>{{ preset.name }}</strong><small>{{ preset.description }}</small><el-icon v-if="preset.id === project.creative.presetId"><component :is="resolveIcon('CircleCheckFilled')" /></el-icon></button></div></el-dialog>
    <el-dialog v-model="markerOpen" :title="t('标记目标商品')" width="760px" class="ecommerce-dialog" append-to-body destroy-on-close><ProductMarker v-if="markerImage" :image="markerImage" :value="project.product.config.marking" @confirm="value => attempt(() => saveMarking(value))" @cancel="markerOpen = false" /></el-dialog>
    <el-dialog v-model="assetsOpen" :title="t('我的资产')" width="800px" class="ecommerce-dialog" append-to-body><div v-loading="assetsLoading" class="asset-gallery"><template v-for="asset in assets" :key="asset.id"><button v-for="image in asset.images.filter(image => image.url)" :key="image.id" type="button" @click="attempt(() => selectAsset(asset, image.id!))"><img :src="image.url" :alt="asset.name" /><span>{{ asset.name }}</span></button></template><el-empty v-if="!assetsLoading && !assets.some(asset => asset.images.some(image => image.url))" :description="t('暂无可用资产')" /></div></el-dialog>
    <el-dialog :model-value="!!fullPreview" :title="fullPreview?.name" width="1000px" class="ecommerce-dialog" append-to-body @close="fullPreview = null"><img v-if="fullPreview" class="full-preview" :src="fullPreview.src" :alt="fullPreview.name" /></el-dialog>
    <el-dialog :model-value="!!videoPreview" :title="videoPreview?.title" width="900px" class="ecommerce-dialog" append-to-body destroy-on-close @close="videoPreview = null"><video v-if="videoPreview" class="video-preview" :src="videoPreview.result_url" controls autoplay playsinline /></el-dialog>
    <el-dialog :model-value="!!editResult" :title="t('修改成品')" width="620px" class="ecommerce-dialog" append-to-body @close="editResult = null"><div v-if="editResult" class="edit-preview"><img :src="editResult.src" :alt="editResult.name" /><span>{{ editResult.name }}</span></div><el-input v-model="editInstruction" type="textarea" :rows="4" maxlength="4000" :placeholder="t('描述需要修改的内容')" /><template #footer><el-button @click="editResult = null">{{ t('取消') }}</el-button><el-button type="primary" :loading="busy" :disabled="!editInstruction.trim()" @click="attempt(edit)">{{ t('查看修改费用') }}</el-button></template></el-dialog>
    <el-dialog v-model="quoteOpen" :title="t('确认生成')" width="600px" class="ecommerce-dialog" append-to-body :close-on-click-modal="!busy" :show-close="!busy"><template v-if="quote"><div class="quote-summary"><div><strong>{{ quote.count }}</strong><span>{{ t('个输出') }}</span></div><div><strong>{{ quote.credits }}</strong><span>{{ quote.estimated ? t('预计积分，按实际用量结算') : t('积分') }}</span></div></div><div class="quote-pages"><div v-for="(item, index) in quoteItems" :key="index" class="quote-page"><strong>{{ item.title }}</strong><span>{{ t('{count} 张参考图', { count: item.request.images.length }) }}</span></div></div></template><template #footer><el-button :disabled="busy" @click="quoteOpen = false">{{ t('取消') }}</el-button><el-button type="primary" :loading="busy" @click="attempt(submitQuote)">{{ t('确认生成') }}</el-button></template></el-dialog>
    <el-dialog v-model="longImageOpen" :title="t('导出详情长图')" width="560px" class="ecommerce-dialog" append-to-body><el-form label-position="top"><el-form-item :label="t('套图计划')"><el-select :model-value="exportCampaignId" @change="selectExportCampaign"><el-option v-for="group in detailCampaigns" :key="group.id" :value="group.id" :label="group.title" /></el-select></el-form-item><el-form-item :label="t('选择详情页')"><el-checkbox-group v-model="exportPageIds"><el-checkbox v-for="page in exportPages" :key="page.id" :value="page.page_key">{{ page.title }}</el-checkbox></el-checkbox-group></el-form-item></el-form><template #footer><el-button @click="longImageOpen = false">{{ t('取消') }}</el-button><el-button type="primary" :icon="resolveIcon('Download')" :loading="exportBusy" :disabled="!exportPageIds.length" @click="attempt(exportLongImage)">{{ t('导出') }}</el-button></template></el-dialog>
  </div>
</template>

<style scoped>
.storefront { height: 100%; min-width: 0; display: flex; flex-direction: column; background: var(--canvas); color: var(--body); font-size: 13px; letter-spacing: 0; }
h1,h2,h3,p { margin: 0; } h1 { font-size: 21px; font-weight: 600; } h2 { font-size: 14px; font-weight: 600; } h3 { font-size: 17px; font-weight: 500; }
.storefront-header { display: flex; align-items: center; justify-content: space-between; gap: 18px; padding: 21px 26px; border-bottom: 1px solid var(--hairline); }
.page-title { display: flex; align-items: center; gap: 11px; min-width: 0; }.page-title > .el-icon { color: var(--brand-cyan); }.save-state { color: var(--muted); font-size: 11px; margin-left: 8px; white-space: nowrap; }.save-state.error { color: #fb7185; }
.project-controls { display: flex; align-items: center; gap: 8px; min-width: 0; }.project-controls .el-select { width: 190px; }.project-controls .el-button + .el-button { margin-left: 0; }.storefront > .el-alert { margin: 10px 20px 0; width: auto; flex-shrink: 0; }
.storefront-workspace { flex: 1; min-height: 0; display: grid; grid-template-columns: 360px minmax(0,1fr); overflow: hidden; }.creation-pane { min-height: 0; display: flex; flex-direction: column; border-right: 1px solid var(--hairline); }.creation-fields { padding: 22px 22px 0; overflow-y: auto; scrollbar-gutter: stable; min-height: 0; flex: 1; }.creation-fields section + section { margin-top: 24px; }.section-heading { display: flex; align-items: center; justify-content: space-between; gap: 10px; margin-bottom: 12px; }.section-heading .el-button { font-size: 12px; }
.product-dropzone { border: 1px solid transparent; border-radius: 6px; min-height: 112px; }.product-dropzone.empty { border: 1px dashed #334056; background: var(--surface-soft); }.product-dropzone.dragging { border-color: var(--brand-cyan); background: rgba(34,211,238,.06); }.empty-upload { display: flex; width: 100%; min-height: 160px; flex-direction: column; align-items: center; justify-content: center; gap: 10px; padding: 18px; background: transparent; border: 0; color: var(--body); font: inherit; cursor: pointer; }.empty-upload:disabled { cursor: wait; }.empty-upload strong { font-size: 13px; font-weight: 500; }.empty-upload > span:last-child { font-size: 10px; color: var(--muted); }.upload-symbol { width: 43px; height: 43px; display: grid; place-items: center; color: var(--brand-cyan); }.product-dropzone :deep(.media-strip) { grid-template-columns: repeat(3,minmax(0,1fr)); gap: 9px; }.product-tools { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin: 2px 0 12px; color: var(--muted); font-size: 11px; }.product-tools .el-button { font-size: 11px; }.product-name-input { margin: 14px 0 10px; }
.goal-options { width: 100%; display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 8px; }.goal-options :deep(.el-radio-button) { width: 100%; }.goal-options :deep(.el-radio-button__inner) { width: 100%; height: 50px; display: flex; align-items: center; gap: 7px; padding: 0 10px; border: 1px solid var(--hairline); border-radius: 6px; box-shadow: none; background: var(--surface-soft); font-size: 12px; text-align: left; }.goal-options :deep(.el-radio-button.is-active .el-radio-button__inner) { color: var(--brand-cyan); border-color: #218b9f; background: rgba(34,211,238,.07); }.goal-options .el-icon { font-size: 15px; }.goal-options small { margin-left: auto; font-size: 10px; color: var(--muted); white-space: nowrap; }.goal-options span { min-width: 0; }.video-quick-options { margin-top: 16px; }.form-pair { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }.form-pair > * { min-width: 0; }
.selected-style { width: 100%; min-height: 78px; padding: 0; display: flex; align-items: center; gap: 12px; border: 0; color: var(--body); background: transparent; text-align: left; cursor: pointer; font: inherit; }.selected-style img { width: 62px; height: 78px; border: 1px solid var(--hairline); object-fit: cover; border-radius: 4px; }.selected-style > span { flex: 1; min-width: 0; }.selected-style strong { display: block; font-size: 13px; font-weight: 500; line-height: 21px; }.selected-style small { display: block; font-size: 11px; color: var(--muted); line-height: 18px; margin-top: 4px; }.selected-style > .el-icon { color: var(--muted); }
.reference-summary { display: flex; flex-wrap: wrap; gap: 10px; margin-top: 16px; }.reference-summary button { padding: 0; display: flex; align-items: center; gap: 7px; min-width: 0; max-width: 100%; color: var(--muted); background: transparent; border: 0; font: inherit; font-size: 11px; cursor: pointer; }.reference-summary img { width: 28px; height: 28px; object-fit: contain; border: 1px solid var(--hairline); border-radius: 3px; }.reference-summary span { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.reference-summary .missing { color: #fbbf24; }
.advanced-trigger { margin: 22px 0 16px; width: 100%; display: flex; align-items: center; gap: 8px; border: 0; border-top: 1px solid var(--hairline); padding: 17px 0 0; background: transparent; color: var(--muted); font: inherit; font-size: 12px; cursor: pointer; }.advanced-trigger > .el-icon:last-child { margin-left: auto; }
.creation-footer { padding: 15px 22px 18px; border-top: 1px solid var(--hairline); flex-shrink: 0; background: var(--canvas); }.creation-summary { display: flex; justify-content: space-between; gap: 12px; align-items: baseline; margin-bottom: 12px; font-size: 12px; color: var(--muted); }.creation-summary strong { color: var(--body); font-size: 14px; font-weight: 500; }.generate-button { width: 100%; height: 44px; font-size: 14px; }.estimate-error { font-size: 12px; line-height: 20px; color: #fda4af; margin-bottom: 10px; }.settlement-note { color: var(--muted); font-size: 11px; margin: -4px 0 10px; }.mobile-results-link { display: none; }
.results-pane { overflow-y: auto; min-width: 0; padding: 23px 26px 32px; }.results-heading { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 20px; }.results-heading > div:first-child { display: flex; align-items: center; gap: 12px; }.results-heading > div:first-child > span { font-size: 11px; color: var(--muted); }.result-tools { display: flex; gap: 8px; align-items: center; }.batch-select { width: 162px; }.output-toolbar { display: flex; justify-content: space-between; gap: 12px; align-items: center; flex-wrap: wrap; padding: 0 0 18px; }.batch-status { display: flex; align-items: center; gap: 9px; min-height: 32px; color: var(--muted); font-size: 12px; flex-wrap: wrap; }.batch-status .el-button { font-size: 12px; }.activity-dot { width: 6px; height: 6px; border-radius: 50%; background: var(--brand-cyan); }.download-actions { display: flex; gap: 8px; }.download-actions .el-button + .el-button { margin-left: 0; }
.output-empty { min-height: 480px; height: calc(100% - 80px); display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 20px; padding: 30px 0; box-sizing: border-box; }.empty-silhouette { width: 174px; height: 200px; display: grid; place-items: center; color: #344151; }.source-preview { position: relative; width: min(100%,420px); height: min(45vh,440px); min-height: 220px; }.source-preview img { width: 100%; height: 100%; object-fit: contain; }.source-preview span { position: absolute; bottom: 10px; left: 12px; padding: 5px 8px; border-radius: 4px; background: rgba(7,11,20,.8); color: #cbd5e1; font-size: 11px; }.planned-output { display: flex; gap: 14px; align-items: center; justify-content: center; flex-wrap: wrap; color: var(--muted); font-size: 12px; }.planned-output span + span { padding-left: 14px; border-left: 1px solid var(--hairline); }
.job-grid { display: grid; grid-template-columns: repeat(auto-fill,minmax(200px,1fr)); gap: 16px; align-items: start; }.job-tile { min-width: 0; border: 1px solid var(--hairline); border-radius: 6px; overflow: hidden; background: var(--surface-card); }.job-preview { width: 100%; aspect-ratio: 1; background: #0b101a; border: 0; padding: 0; display: block; position: relative; cursor: pointer; color: var(--muted); }.job-preview:disabled { cursor: default; }.job-preview img,.job-preview video { width: 100%; height: 100%; object-fit: contain; display: block; }.job-placeholder { height: 100%; display: flex; align-items: center; justify-content: center; flex-direction: column; gap: 13px; font-size: 12px; }.running .job-placeholder { color: var(--brand-cyan); }.unknown .job-placeholder { color: #fbbf24; }.failed .job-placeholder { color: #fb7185; }.job-info { padding: 11px 12px 0; display: flex; justify-content: space-between; align-items: center; gap: 6px; }.job-info strong { display: block; min-width: 0; font-size: 12px; font-weight: 500; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }.job-info small { flex-shrink: 0; font-size: 10px; color: var(--muted); }.job-actions { display: flex; align-items: center; gap: 6px; padding: 12px; flex-wrap: wrap; min-height: 32px; }.job-actions .el-button { padding: 7px; font-size: 12px; }.job-actions .el-button + .el-button { margin-left: 0; }.job-error { padding: 9px 12px 0; font-size: 11px; line-height: 18px; color: #fda4af; overflow-wrap: anywhere; }.video-play { position: absolute; inset: 0; display: grid; place-items: center; color: #fff; background: rgba(0,0,0,.15); }.undo-bar { display: flex; align-items: center; gap: 12px; margin-top: 16px; color: var(--muted); font-size: 12px; }
.settings-group { padding: 20px 0; border-bottom: 1px solid var(--hairline); }
.job-tile { position: relative; }
.job-tile > .delete-result { position: absolute; top: 8px; right: 8px; width: 30px; height: 30px; margin: 0; padding: 0; color: var(--body); background: var(--surface-card); border-color: var(--hairline); border-radius: 4px; }
.job-tile > .delete-result:hover:not(:disabled) { color: #fb7185; border-color: #9f3b51; }
.undo-bar { margin: 0 0 16px; padding: 10px 12px; background: var(--surface-soft); border: 1px solid var(--hairline); border-radius: 6px; }
.settings-group:first-child { padding-top: 0; }
.settings-group-heading { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 18px; }
.settings-group-heading h2 { font-size: 13px; font-weight: 600; }
.settings-group-heading > span { color: var(--muted); font-size: 11px; }
.advanced-settings :deep(.el-form-item__label),.video-quick-options :deep(.el-form-item__label) { color: var(--muted); font-size: 12px; line-height: 19px; padding-bottom: 6px; }
.advanced-settings :deep(.el-form-item) { margin-bottom: 15px; }
.advanced-settings :deep(.el-form > .el-form-item:last-child) { margin-bottom: 0; }
.advanced-settings :deep(.el-input__wrapper),.advanced-settings :deep(.el-select__wrapper) { min-height: 36px; box-sizing: border-box; border-radius: 6px; }
.advanced-settings :deep(.el-textarea__inner) { border-radius: 6px; }
.advanced-settings :deep(.el-select),.video-quick-options :deep(.el-select) { width: 100%; }
.reference-setting-row { display: flex; align-items: center; gap: 12px; min-height: 46px; }
.reference-avatar { width: 42px; height: 44px; flex-shrink: 0; display: grid; place-items: center; color: var(--muted); background: var(--surface-soft); border: 1px solid var(--hairline); border-radius: 6px; overflow: hidden; }
.reference-avatar img { width: 100%; height: 100%; object-fit: contain; }
.reference-setting-label { min-width: 0; flex: 1; display: flex; flex-direction: column; gap: 5px; }
.reference-setting-label strong { font-size: 12px; font-weight: 500; }
.reference-setting-label > span { color: var(--muted); font-size: 11px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.reference-setting-row .el-switch { flex-shrink: 0; }
.scene-setting-row { margin-top: 18px; }
.reference-setting-body { padding-top: 14px; }
.reference-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 0 0 14px; }
.reference-actions .el-button { margin-left: 0; font-size: 12px; padding: 8px 12px; border-radius: 6px; }
.reference-setting-body :deep(.media-strip) { grid-template-columns: repeat(auto-fill,minmax(72px,1fr)); min-height: 0; margin-bottom: 14px; }
.settings-disclosure > summary { display: flex; align-items: center; gap: 8px; min-height: 22px; list-style: none; color: var(--muted); cursor: pointer; font-size: 12px; }
.settings-disclosure > summary::-webkit-details-marker { display: none; }
.settings-disclosure > summary strong { color: var(--body); font-size: 13px; font-weight: 500; }
.settings-disclosure > summary > span { margin-left: auto; color: var(--muted); font-size: 11px; }
.settings-disclosure > summary .disclosure-caret { font-size: 11px; flex-shrink: 0; }
.settings-disclosure[open] > summary .disclosure-caret { transform: rotate(90deg); }
.settings-disclosure[open] > summary { margin-bottom: 16px; }
.settings-disclosure > .el-button { margin-top: 12px; }
.settings-disclosure > summary:focus-visible { outline: 2px solid var(--brand-cyan); outline-offset: 4px; }
.script-settings { margin-bottom: 16px; }
.inline-actions { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin: 14px 0; }
.inline-actions > span { font-size: 12px; color: var(--muted); }
.inline-actions .el-button + .el-button { margin-left: 0; }
.page-selection { display: grid; grid-template-columns: 1fr 1fr; margin: 14px 0 20px; }
.page-selection .el-checkbox { height: auto; min-height: 32px; margin-right: 0; }
.page-selection :deep(.el-checkbox__label) { white-space: normal; font-size: 12px; line-height: 20px; }
.scene-grid { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: 8px; margin-bottom: 14px; }
.scene-grid button { position: relative; padding: 0; min-width: 0; border: 1px solid var(--hairline); border-radius: 5px; overflow: hidden; cursor: pointer; background: var(--surface-soft); color: var(--body); font: inherit; }
.scene-grid button.selected { border-color: var(--brand-cyan); }
.scene-grid img { width: 100%; aspect-ratio: 4/3; object-fit: cover; display: block; }
.scene-grid span { display: block; padding: 7px 3px; font-size: 10px; }
.scene-check { position: absolute; right: 5px; top: 5px; color: var(--brand-cyan); background: var(--canvas); border-radius: 50%; }
.settings-footer { display: flex; align-items: center; justify-content: space-between; gap: 16px; }
.settings-footer > span { color: var(--muted); font-size: 12px; }
.settings-footer .el-button { min-width: 90px; border-radius: 6px; }
.video-style-options { display: flex; width: 100%; }
.content-mode { display: flex; width: 100%; }
.content-mode :deep(.el-radio-button) { flex: 1; min-width: 0; }
.content-mode :deep(.el-radio-button__inner) { display: flex; width: 100%; min-height: 42px; justify-content: center; align-items: center; gap: 8px; padding: 10px 8px; font-size: 13px; }
.copy-section > .el-input,.copy-points > .el-input { margin-bottom: 9px; }
.copy-action { display: flex; justify-content: space-between; align-items: center; gap: 8px; }
.copy-action > span { font-size: 10px; color: var(--muted); }
.copy-action .el-button { font-size: 12px; }
.copy-error { font-size: 12px; color: #fda4af; line-height: 20px; margin-top: 8px; }
.poster-editor { display: grid; grid-template-columns: minmax(0,1fr) minmax(0,1fr); gap: 24px; }
.poster-editor-preview { height: 380px; min-width: 0; }
.poster-editor > .el-form { min-width: 0; }
.poster-full-preview { height: 72vh; }
@media (max-width: 560px) { .poster-editor { grid-template-columns: 1fr; gap: 16px; }.poster-editor-preview { height: 260px; } }
.duration-options { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.duration-options > span { font-size: 12px; color: var(--muted); }
.requirements-section :deep(.el-textarea__inner) { font-size: 13px; line-height: 22px; border-radius: 6px; }
.advanced-trigger:disabled { cursor: default; opacity: .5; }
.inline-settings { padding-bottom: 18px; }
.inline-settings .scene-grid { grid-template-columns: repeat(2,minmax(0,1fr)); }
.inline-settings .settings-footer { justify-content: flex-end; padding-top: 15px; }
.model-choice > .el-select { width: 100%; }
.model-setup { margin-top: 12px; display: grid; gap: 10px; }
.video-style-options :deep(.el-radio-button) { flex: 1; }
.video-style-options :deep(.el-radio-button__inner) { display: flex; align-items: center; justify-content: center; gap: 7px; width: 100%; padding: 10px 6px; font-size: 12px; }
.style-filters { margin-bottom: 18px; overflow-x: auto; }.style-gallery { display: grid; grid-template-columns: repeat(4,minmax(0,1fr)); gap: 14px; }.style-gallery button { padding: 0 0 12px; position: relative; text-align: left; border: 1px solid var(--hairline); border-radius: 6px; overflow: hidden; background: var(--surface-soft); color: var(--body); cursor: pointer; }.style-gallery button.selected { border-color: var(--brand-cyan); }.style-gallery img { width: 100%; aspect-ratio: 3/4; object-fit: cover; display: block; }.style-gallery strong,.style-gallery small { display: block; margin: 10px 10px 0; font-size: 12px; font-weight: 500; line-height: 18px; }.style-gallery small { color: var(--muted); font-size: 10px; font-weight: 400; margin-top: 4px; }.style-gallery .el-icon { position: absolute; top: 8px; right: 8px; color: var(--brand-cyan); font-size: 22px; background: #0b101a; border-radius: 50%; }.asset-gallery { display: grid; grid-template-columns: repeat(auto-fill,minmax(130px,1fr)); gap: 12px; min-height: 160px; }.asset-gallery button { padding: 0; border: 1px solid var(--hairline); background: var(--surface-soft); border-radius: 6px; overflow: hidden; cursor: pointer; color: var(--body); }.asset-gallery img { width: 100%; height: 130px; object-fit: contain; }.asset-gallery span { display: block; padding: 8px; font-size: 12px; }.full-preview { width: 100%; max-height: 75vh; object-fit: contain; }.video-preview { width: 100%; max-height: 75vh; background: #000; }.edit-preview { display: flex; align-items: center; gap: 16px; margin-bottom: 18px; }.edit-preview img { width: 110px; height: 130px; object-fit: contain; }.quote-summary { display: flex; gap: 34px; flex-wrap: wrap; border-bottom: 1px solid var(--hairline); padding-bottom: 20px; margin-bottom: 8px; }.quote-summary > div { display: flex; align-items: baseline; gap: 9px; }.quote-summary strong { font-size: 26px; font-weight: 500; }.quote-summary span { font-size: 12px; color: var(--muted); }.quote-pages { max-height: 45vh; overflow: auto; }.quote-page { display: flex; align-items: center; justify-content: space-between; gap: 12px; border-bottom: 1px solid var(--hairline); padding: 14px 0; }.quote-page strong { font-size: 13px; font-weight: 500; }.quote-page span { color: var(--muted); font-size: 11px; white-space: nowrap; }button:focus-visible { outline: 2px solid var(--brand-cyan); outline-offset: 2px; }
@media (max-width: 1200px) { .storefront-workspace { grid-template-columns: minmax(240px, 300px) minmax(0,1fr); }.creation-fields { padding: 20px 18px 0; }.creation-footer { padding: 15px 18px 18px; }.results-pane { padding: 23px 20px 28px; }.job-grid { grid-template-columns: repeat(auto-fill,minmax(172px,1fr)); gap: 12px; }.goal-options :deep(.el-radio-button__inner) { padding: 0 8px; gap: 5px; } }
@media (max-width: 900px) { .storefront-workspace { display: block; overflow-y: auto; }.creation-pane { border-right: 0; border-bottom: 1px solid var(--hairline); }.creation-fields { overflow: visible; padding-bottom: 0; }.creation-footer { position: static; }.results-pane { overflow: visible; }.output-empty { min-height: 330px; height: auto; }.source-preview { height: 290px; }.storefront-header { padding: 18px; flex-wrap: wrap; gap: 14px; }.project-controls { margin-left: auto; }.goal-options { grid-template-columns: repeat(3,minmax(0,1fr)); }.mobile-results-link { display: flex; margin: 10px auto 0; }.product-dropzone :deep(.media-strip) { grid-template-columns: repeat(auto-fill,minmax(90px,1fr)); }.creation-fields section + section { margin-top: 20px; }.empty-upload { min-height: 145px; }.selected-style img { height: 68px; width: 52px; }.selected-style { min-height: 68px; }.style-gallery { grid-template-columns: repeat(3,minmax(0,1fr)); } }
@media (max-width: 560px) { h1 { font-size: 19px; }.storefront-header { padding: 16px 14px; }.page-title { gap: 8px; }.save-state { margin-left: 4px; }.project-controls { width: 100%; }.project-controls .el-select { flex: 1; width: 100px; }.project-controls > .el-button { padding: 8px 10px; }.creation-fields { padding: 20px 14px 0; }.creation-footer { padding: 15px 14px 18px; }.results-pane { padding: 20px 14px 28px; }.goal-options { grid-template-columns: repeat(2,minmax(0,1fr)); }.goal-options :deep(.el-radio-button__inner) { height: 46px; }.results-heading { gap: 8px; flex-wrap: wrap; }.batch-select { width: 138px; }.job-grid { grid-template-columns: repeat(2,minmax(0,1fr)); gap: 10px; }.job-info { display: block; padding: 10px 8px 0; }.job-info small { display: block; margin-top: 3px; }.job-actions { gap: 5px; padding: 9px 8px; }.job-actions .el-button { padding: 6px; font-size: 11px; }.output-toolbar { gap: 10px; }.download-actions { width: 100%; }.download-actions .el-button { padding: 8px 10px; font-size: 12px; }.style-gallery { grid-template-columns: repeat(2,minmax(0,1fr)); }.scene-grid { grid-template-columns: repeat(2,minmax(0,1fr)); }.page-selection { grid-template-columns: 1fr; }.quote-page { align-items: flex-start; flex-direction: column; gap: 6px; } }
@media (max-width: 900px) { .storefront-workspace { scrollbar-gutter: stable; }.creation-fields { scrollbar-gutter: auto; } }
@media (prefers-reduced-motion: reduce) { .storefront * { scroll-behavior: auto; } }
</style>

<style>
.ecommerce-dialog { max-width: calc(100vw - 24px); border-radius: 8px; }.ecommerce-dialog .el-dialog__body { overflow-x: hidden; }.ecommerce-dialog .el-radio-button__inner { font-size: 12px; }
</style>
