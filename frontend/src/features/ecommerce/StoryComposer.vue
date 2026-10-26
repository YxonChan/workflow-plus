<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { t } from '@/i18n'
import { resolveIcon } from '@/utils/iconRegistry'
import { ApiError } from '@/api/http'
import { ecommerce } from './api'
import { errorText } from './useEcommerce'
import { videoBriefSignature } from './domain/video-brief.js'
import type { Job, Project, StoryCharacter, VideoBrief } from './types'

const props = defineProps<{ project: Project; projectId: number; jobs: Job[]; busy: boolean; hasTextModel: boolean; save: () => Promise<void> }>()
const emit = defineEmits<{ generate: [character: StoryCharacter]; check: [job: Job]; retry: [job: Job]; working: [value: boolean]; stage: [value: string] }>()
const story = computed(() => props.project.creative.video.story)
const expanding = ref(false), uploading = ref(false), error = ref(''), listening = ref(false)
const characterInput = ref<HTMLInputElement>(), uploadingId = ref('')
const reviewOpen = ref(false)
const brief = ref<VideoBrief | null>(null), answerInputs = ref<Record<string, string>>({}), roleInputs = ref<Record<string, string>>({})
const kindInputs = ref<Record<string, string>>({}), feedback = ref(''), loaded = ref(false)
let lastSignature = '', selectionSnapshot = ''
let pending: { signature: string; key: string; version: number; input: unknown; action: string } | null = null
let disposed = false
let applying = false
interface SpeechResult { isFinal: boolean; 0: { transcript: string } }
interface Recognition {
  lang: string; continuous: boolean; interimResults: boolean
  onresult: ((event: { resultIndex: number; results: ArrayLike<SpeechResult> }) => void) | null
  onerror: ((event: { error: string }) => void) | null; onend: (() => void) | null
  start(): void; stop(): void; abort(): void
}
type RecognitionConstructor = new () => Recognition
const speechWindow = window as unknown as { SpeechRecognition?: RecognitionConstructor; webkitSpeechRecognition?: RecognitionConstructor }
const SpeechRecognition = speechWindow.SpeechRecognition || speechWindow.webkitSpeechRecognition
const dictationSupported = !!SpeechRecognition && window.isSecureContext
let recognition: Recognition | null = null
const signature = () => videoBriefSignature(props.project)
const selections = () => JSON.stringify([kindInputs.value, roleInputs.value])
const stale = computed(() => !!brief.value?.version && (brief.value.stale || signature() !== lastSignature || selections() !== selectionSnapshot || !!feedback.value.trim() || Object.values(answerInputs.value).some(Boolean)))
const stage = computed(() => !stale.value && ['ready', 'confirmed'].includes(brief.value?.phase || '') ? brief.value!.phase : 'questions')
watch(stage, value => emit('stage', value), { immediate: true })
function stopDictation() { recognition?.abort(); recognition = null; listening.value = false }
function dictate() {
  if (listening.value) { recognition?.stop(); return }
  if (!SpeechRecognition || !dictationSupported) return
  error.value = ''
  const target = story.value
  const session = new SpeechRecognition(); recognition = session
  session.lang = 'zh-CN'; session.continuous = true; session.interimResults = false
  session.onresult = event => {
    if (disposed || recognition !== session) return
    for (let index = event.resultIndex; index < event.results.length; index++) {
      if (event.results[index]!.isFinal) target.idea = (target.idea + event.results[index]![0].transcript).slice(0, 2500)
    }
  }
  session.onerror = event => {
    error.value = event.error === 'not-allowed' ? t('麦克风权限未开启，请允许访问后重试') : t('语音输入未完成，可继续输入文字后重试')
    listening.value = false
  }
  session.onend = () => { if (recognition === session) { listening.value = false; recognition = null } }
  try { listening.value = true; session.start() } catch { error.value = t('无法启动语音输入，请使用文字输入'); recognition = null; listening.value = false }
}
function apply(result: VideoBrief) {
  applying = true
  brief.value = result; lastSignature = signature()
  kindInputs.value = Object.fromEntries(result.materials.map(media => [media.id, media.kind]))
  roleInputs.value = { ...result.roles }; selectionSnapshot = selections(); answerInputs.value = {}; feedback.value = ''
  if (result.story && !result.stale) {
    Object.assign(story.value, result.story, { enabled: true, idea: story.value.idea, expandedIdea: story.value.idea })
    if (result.story.hasDialogue) props.project.creative.video.generate_audio = true
    props.project.integration!.videoBrief = { version: result.version, signature: signature(), productIds: result.product_ids || [], scene: result.scene || null }
  }
  applying = false
}
let loadingFlight: Promise<void> | null = null
async function load() {
  if (loaded.value) return
  if (loadingFlight) return loadingFlight
  const projectId = props.projectId
  loadingFlight = (async () => {
    const result = await ecommerce.brief(projectId)
    if (!disposed && props.projectId === projectId) { apply(result); loaded.value = true }
  })().finally(() => { loadingFlight = null })
  return loadingFlight
}
async function request(action: 'analyze' | 'consult' | 'confirm') {
  if (expanding.value || uploading.value || !props.hasTextModel) return false
  stopDictation(); error.value = ''
  const projectId = props.projectId
  expanding.value = true
  try {
    await load()
    const input = action === 'consult' ? { kinds: kindInputs.value, roles: roleInputs.value, answers: answerInputs.value, message: feedback.value } : {}
    const before = signature(), requestSignature = JSON.stringify([before, action, input])
    if (!pending || pending.signature !== requestSignature) pending = { signature: requestSignature, key: crypto.randomUUID(), version: brief.value?.version || 0, input: JSON.parse(JSON.stringify(input)), action }
    const turn = pending
    await props.save()
    if (disposed || projectId !== props.projectId || before !== signature()) return false
    const result = await ecommerce.briefTurn(projectId, turn.version, turn.action, turn.input, turn.key)
    if (disposed || props.projectId !== projectId || before !== signature()) return false
    apply(result); pending = null; await props.save()
    return true
  } catch (caught) {
    if (!disposed && props.projectId === projectId) error.value = errorText(caught)
    if (caught instanceof ApiError && caught.code > 0 && caught.code < 500 && caught.code !== 409) pending = null
    if (caught instanceof ApiError && caught.code === 409) {
      const latest = await ecommerce.brief(projectId).catch(() => null)
      if (!disposed && props.projectId === projectId && latest && latest.version > (brief.value?.version || 0)) {
        apply(latest); pending = null
      }
    }
    return false
  } finally { expanding.value = false }
}
async function analyze() { return request('analyze') }
async function continueFlow() {
  if (stage.value === 'confirmed') return true
  if (stage.value === 'ready') return request('confirm')
  await request('consult'); return false
}
defineExpose({ continueFlow, analyze })
onMounted(() => { void load().catch(caught => { error.value = errorText(caught) }) })
function chooseUpload(character: StoryCharacter) { uploadingId.value = character.id; characterInput.value?.click() }
async function resetCharacter(character: StoryCharacter) {
  character.source = 'description'; character.image = null
  await analyze()
}
async function upload(event: Event) {
  const input = event.target as HTMLInputElement, file = input.files?.[0]; input.value = ''
  if (!file || uploading.value) return
  const character = story.value.characters.find(item => item.id === uploadingId.value), projectId = props.projectId
  if (!character) return
  error.value = ''
  if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 10 * 1024 * 1024) { error.value = t('请选择不超过 10MB 的 JPG、PNG 或 WebP 图片'); return }
  uploading.value = true
  try {
    const media = await ecommerce.upload(projectId, file)
    if (!disposed && props.projectId === projectId && story.value.characters.includes(character)) {
      character.image = media; character.source = 'upload'; await props.save()
    }
  } catch (caught) { if (!disposed) error.value = errorText(caught) }
  finally { uploading.value = false }
  await analyze()
}
function characterJob(character: StoryCharacter) { return props.jobs.filter(job => job.request.original.storyCharacterId === character.id && job.request.original.storyCharacterDescription === character.description).at(-1) }
function jobBusy(character: StoryCharacter) { return ['queued', 'running', 'unknown'].includes(characterJob(character)?.status || '') }
watch(() => props.projectId, () => { stopDictation(); error.value = ''; pending = null })
watch(() => story.value.idea, idea => { story.value.enabled = !!idea.trim() }, { flush: 'sync' })
watch(() => story.value.characters.map(character => character.image?.src || ''), (urls, before) => {
  if (!applying && !expanding.value && !uploading.value && loaded.value && urls.some((url, index) => url && url !== before[index])) void analyze()
}, { flush: 'sync' })
watch([expanding, uploading], () => emit('working', expanding.value || uploading.value), { flush: 'sync' })
onBeforeUnmount(() => { disposed = true; stopDictation(); emit('working', false) })
</script>

<template>
  <section class="story-composer" data-testid="story-composer">
    <div class="story-heading"><h3>{{ t('想怎么拍？') }}</h3><el-tooltip :content="dictationSupported ? listening ? t('结束口述') : t('口述剧情') : t('当前浏览器不支持语音输入，可输入文字')"><span><el-button :icon="resolveIcon(listening ? 'VideoPause' : 'Microphone')" :type="listening ? 'danger' : 'default'" :disabled="!dictationSupported || expanding || busy" :aria-label="listening ? t('结束口述') : t('口述剧情')" @click="dictate" /></span></el-tooltip></div>
    <el-input v-model="story.idea" type="textarea" :rows="4" maxlength="2500" :aria-label="t('剧情想法')" :placeholder="t('例如：女儿提着一桶油回家送给妈妈，妈妈在厨房炒菜，两人聊起口感和价格。写下你希望她们说的话。')" :disabled="expanding || busy" />
    <div class="story-expand-action"><span>{{ listening ? t('正在聆听…') : t('素材识别与对话按文本用量计费') }}</span><el-button v-if="!brief?.materials.length" link :loading="expanding" :disabled="!hasTextModel || busy || uploading || !project.product.uploads.length" @click="analyze">{{ t('识别素材') }}</el-button></div>
    <p v-if="!hasTextModel" class="story-warning">{{ t('请先在模型配置中启用文本模型') }}</p>
    <p v-if="error" role="alert" class="story-error">{{ error }}</p>
    <div v-if="brief?.materials.length" class="brief-materials">
      <article v-for="media in brief.materials" :key="media.id" class="brief-material" :data-material="media.id">
        <img :src="media.src" :alt="media.name" /><div><p>{{ media.summary }}</p><el-select v-model="kindInputs[media.id]" :disabled="expanding || busy" :aria-label="t('{name}素材用途', { name: media.name })" size="small"><el-option value="product" :label="t('商品')" /><el-option value="person" :label="t('人物')" /><el-option value="scene" :label="t('场景')" /><el-option value="unknown" :label="t('待确认')" /><el-option value="ignore" :label="t('不使用')" /></el-select><el-select v-if="kindInputs[media.id] === 'person'" v-model="roleInputs[media.id]" clearable :disabled="expanding || busy || !brief.characters.length" :placeholder="brief.characters.length ? t('选择人物角色') : t('填写剧情后选择角色')" :aria-label="t('{name}人物角色', { name: media.name })" size="small"><el-option v-for="character in brief.characters" :key="character.id" :value="character.id" :label="character.name" /></el-select></div>
      </article>
    </div>
    <div v-if="brief?.version" class="brief-conversation">
      <p v-if="brief.message" class="brief-message">{{ brief.message }}</p>
      <div v-for="question in brief.questions" :key="question.id" class="brief-question"><label>{{ question.text }}</label><el-select v-model="answerInputs[question.id]" :disabled="expanding || busy" :aria-label="question.text" allow-create filterable :placeholder="t('请选择或填写')"><el-option v-for="option in question.options" :key="option" :value="option" :label="option" /></el-select></div>
      <div v-if="brief.proposal" class="brief-proposal" data-testid="video-proposal"><h3>{{ t('视频方案') }}</h3><p>{{ brief.proposal }}</p></div>
      <el-input v-model="feedback" type="textarea" :rows="2" maxlength="2000" :disabled="expanding || busy" :aria-label="t('补充或修改要求')" :placeholder="t('有想调整的地方，直接说（选填）')" />
      <p v-if="stale && brief.proposal" class="story-warning">{{ t('需求已修改，请更新视频方案') }}</p>
      <details v-if="brief.messages.length" class="brief-history"><summary>{{ t('对话记录') }}</summary><p v-for="(message, index) in brief.messages" :key="index"><strong>{{ message.role === 'user' ? t('我') : t('创作助手') }}</strong>{{ message.text }}</p><p v-for="(answer, key) in brief.answers" :key="key">{{ answer.question }}：{{ answer.answer }}</p></details>
    </div>
    <details v-if="story.script && brief?.proposal && !stale" class="story-review" :open="reviewOpen" @toggle="event => reviewOpen = (event.target as HTMLDetailsElement).open">
      <summary>{{ t('查看剧情与人物') }}</summary>
      <div class="story-script"><div class="story-heading"><h3>{{ t('分镜与对白') }}</h3><span>{{ t('{count} 秒', { count: story.duration }) }}</span></div><el-input :model-value="story.script" type="textarea" :rows="8" :aria-label="t('分镜与对白')" readonly /></div>
      <p v-for="warning in story.warnings" :key="warning" class="story-warning">{{ warning }}</p>
      <div v-if="story.characters.length" class="story-characters"><h3>{{ t('剧情人物') }}</h3>
        <article v-for="character in story.characters" :key="character.id" class="story-character" :data-character="character.id">
          <div class="character-heading"><img v-if="character.image && character.source !== 'description'" :src="character.image.src" :alt="character.name" /><span v-else class="character-placeholder"><el-icon :size="22"><component :is="resolveIcon('User')" /></el-icon></span><div><strong>{{ character.name }}</strong><span>{{ character.source === 'description' ? t('自动搭配') : character.image ? t('已指定人物') : t('等待生成') }}</span></div></div>
          <p class="character-description">{{ character.description }}</p>
          <div class="character-actions"><el-button :icon="resolveIcon('Upload')" :loading="uploading && uploadingId === character.id" :disabled="busy || expanding || uploading || jobBusy(character)" @click="chooseUpload(character)">{{ character.image ? t('更换照片') : t('上传照片') }}</el-button><el-button :icon="resolveIcon('MagicStick')" :disabled="busy || expanding || uploading || jobBusy(character) || !character.description.trim()" @click="emit('generate', character)">{{ t('生成人物') }}</el-button><el-button v-if="character.source !== 'description' && !project.product.uploads.some(media => media.src === character.image?.src)" link :disabled="busy || expanding || uploading || jobBusy(character)" @click="resetCharacter(character)">{{ t('恢复自动') }}</el-button><el-button v-if="characterJob(character)?.status === 'unknown'" link @click="emit('check', characterJob(character)!)">{{ t('核对原任务') }}</el-button></div>
          <p v-if="characterJob(character)?.status === 'failed'" class="story-error">{{ characterJob(character)?.error_message || t('生成失败') }}</p>
        </article>
      </div>
    </details>
    <input ref="characterInput" data-testid="character-upload" type="file" accept="image/jpeg,image/png,image/webp" hidden @change="upload" />
  </section>
</template>

<style scoped>
.story-composer { margin-top: 20px; }
.brief-materials { margin: 18px 0; border-top: 1px solid var(--hairline); }
.brief-material { display: grid; grid-template-columns: 48px minmax(0,1fr); gap: 10px; padding: 12px 0; border-bottom: 1px solid var(--hairline); }
.brief-material > img { width: 48px; height: 64px; object-fit: contain; border-radius: 4px; background: var(--surface-card); }
.brief-material > div { min-width: 0; }
.brief-material .el-select,.brief-question .el-select { width: 100%; min-width: 0; }
.brief-material p,.brief-message { margin: 0 0 9px; font-size: 12px; line-height: 20px; overflow-wrap: anywhere; }
.brief-material .el-select + .el-select { margin-top: 8px; }
.brief-question { margin: 14px 0; }
.brief-question label { display: block; margin-bottom: 8px; font-size: 12px; line-height: 20px; }
.brief-conversation > .el-textarea { margin-top: 12px; }
.brief-proposal { border-inline-start: 2px solid var(--brand-cyan); padding-inline-start: 12px; margin: 18px 0; }
.brief-proposal p { font-size: 13px; line-height: 23px; white-space: pre-wrap; overflow-wrap: anywhere; margin: 10px 0 0; }
.brief-history { margin-top: 14px; font-size: 12px; color: var(--muted); }
.brief-history summary { cursor: pointer; }
.brief-history p { line-height: 20px; overflow-wrap: anywhere; }
.brief-history strong { display: block; color: var(--body); margin-bottom: 4px; }
.story-review { margin-top: 14px; border-top: 1px solid var(--hairline); padding-top: 14px; }
.story-review > summary { cursor: pointer; color: var(--muted); font-size: 12px; }
.story-heading { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 10px; }
h3 { font-size: 13px; font-weight: 600; margin: 0; color: var(--body); }
.story-heading span,.story-expand-action > span { font-size: 11px; color: var(--muted); }
.story-heading .el-button { width: 30px; height: 30px; padding: 0; border-radius: 6px; }
.story-expand-action { display: flex; justify-content: space-between; gap: 8px; align-items: center; margin-top: 10px; }
.story-expand-action .el-button,.character-actions .el-button { margin: 0; height: 30px; border-radius: 6px; font-size: 12px; }
.story-warning,.story-error { margin: 10px 0 0; font-size: 12px; line-height: 19px; overflow-wrap: anywhere; }
.story-warning { color: #e4bc70; }.story-error { color: #fda4af; }
.story-script,.story-characters { margin-top: 22px; }
.story-character { margin-top: 12px; padding: 12px 0; border-bottom: 1px solid var(--hairline); }
.character-heading { display: flex; gap: 12px; margin-bottom: 12px; align-items: center; }
.character-heading > div { flex: 1; min-width: 0; display: grid; gap: 8px; }
.character-heading strong { font-size: 13px; overflow-wrap: anywhere; }
.character-description { margin: 0; font-size: 12px; line-height: 20px; color: var(--muted); overflow-wrap: anywhere; }
.character-heading > div > span { font-size: 11px; color: var(--muted); }
.character-heading > img,.character-placeholder { flex: 0 0 52px; width: 52px; height: 70px; object-fit: cover; border-radius: 4px; }
.character-placeholder { display: grid; place-items: center; background: var(--surface-card); color: var(--muted); border: 1px solid var(--hairline); box-sizing: border-box; }
.character-actions { margin-top: 10px; display: flex; align-items: center; flex-wrap: wrap; gap: 8px; }
:deep(.el-textarea__inner) { font-size: 12px; line-height: 21px; border-radius: 6px; }
:deep(.el-select__wrapper) { min-height: 30px; font-size: 12px; border-radius: 6px; }
</style>
