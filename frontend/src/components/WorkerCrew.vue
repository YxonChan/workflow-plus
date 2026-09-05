<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useI18n } from 'vue-i18n'
import {
  fetchWorkerStatus,
  runWorkerAction,
  type DigitalWorker,
  type WorkerActionType,
  type WorkerState,
} from '@/api/workerStatus'
import { chatWithWorker, type AgentAction, type AgentHistoryItem, type AgentPageContext } from '@/api/workerAgent'
import { notifyTask } from '@/utils/taskNotify'
import { readWorkerPageContext } from '@/utils/workerContext'
import WorkerVisualReviewPanel from '@/components/worker/WorkerVisualReviewPanel.vue'

/**
 * 数字员工悬浮组件：由一位制作助手统一承接后台生产任务。
 * - 列表视图：汇总剧本、资产、图片和视频任务状态
 * - 对话视图：统一 Agent 根据用户目标调用受控工具
 */

const COLLAPSED_KEY = 'malulu.workerCrew.collapsed'
const CHAT_KEY_PREFIX = 'malulu.workerCrew.chat.'
const WORKFLOW_CONTEXT_STORAGE_KEY = 'malulu.activeWorkflowContext'
const CHAT_KEEP = 40

interface ChatMsg {
  role: 'user' | 'assistant'
  content: string
  actions?: AgentAction[]
  failed?: boolean
  ts: number
}

interface AssistantCapability {
  title: string
  description: string
  example: string
}

interface WorkflowContextSnapshot {
  run_id: number
  series_id: number
  series_title?: string
  workflow_id: number
  episode_workflow_id?: number | null
  status: 'queued' | 'running' | 'success' | 'failed' | 'cancelled'
  progress: number
  current_node_label: string
  error_message: string
  result_json?: {
    episodes_written?: number
    assets_written?: number
  }
  nodes: Array<{
    id: number
    workflow_node_id: string
    label: string
    kind: string
    status: 'queued' | 'running' | 'success' | 'failed' | 'skipped'
    error_message: string
  }>
}

const router = useRouter()
const { t } = useI18n()
const workers = ref<DigitalWorker[]>([])
const loaded = ref(false)
const errored = ref(false)
const actionPending = ref('')
const errorBandDismissed = ref(false)
// 默认收起吸附侧边；仅当用户明确展开过（存 '0'）时保持展开
const collapsed = ref(localStorage.getItem(COLLAPSED_KEY) !== '0')

const view = ref<'list' | 'chat'>('list')
const activeKey = ref<DigitalWorker['key'] | null>(null)
const chatStore = ref<Record<string, ChatMsg[]>>({})
const draft = ref('')
const sending = ref(false)
const menuOpen = ref(false)
const chatBody = ref<HTMLElement | null>(null)
const workflowContext = ref<WorkflowContextSnapshot | null>(readWorkflowContextSnapshot())

let timer: number | null = null
let disposed = false
let hasWorkerSnapshot = false
let previousWorkers = new Map<DigitalWorker['key'], DigitalWorker>()
let contextTimer: number | null = null

const totalActive = computed(() =>
  workers.value.reduce((sum, w) => sum + w.running + w.queued, 0),
)
const totalFailed = computed(() =>
  workers.value.reduce((sum, w) => sum + w.failed_today, 0),
)
const totalDone = computed(() =>
  workers.value.reduce((sum, w) => sum + w.done_today, 0),
)
const totalRunning = computed(() =>
  workers.value.reduce((sum, w) => sum + w.running, 0),
)
// 已成功加载过一次即视为有数据；用于区分"首屏加载中"与"加载失败无数据"
const hasData = computed(() => workers.value.length > 0)
const activeWorker = computed(() =>
  workers.value.find((w) => w.key === activeKey.value) ?? null,
)
const activeMessages = computed(() =>
  activeKey.value ? chatStore.value[activeKey.value] ?? [] : [],
)
const activeWorkflowNodes = computed(() => workflowContext.value?.nodes ?? [])
const activeWorkflowBusy = computed(() =>
  workflowContext.value ? ['queued', 'running'].includes(workflowContext.value.status) : false,
)
const activeWorkflowSeriesTitle = computed(() => {
  const ctx = workflowContext.value
  if (!ctx) return ''
  if (ctx.series_title) return ctx.series_title
  const series = workers.value.find((w) => w.key === 'assistant')
  return ctx.series_id > 0 ? t('作品 #{id}', { id: ctx.series_id }) : series?.current ?? ''
})
const activeWorkflowSummary = computed(() => {
  const ctx = workflowContext.value
  if (!ctx) return ''
  if (ctx.status === 'running') return ctx.current_node_label || t('流程运行中')
  if (ctx.status === 'queued') return ctx.current_node_label || t('等待开工')
  if (ctx.status === 'failed') return ctx.error_message || t('任务失败')
  if (ctx.status === 'success') return t('流程已完成')
  return ''
})

const PALETTES: Record<DigitalWorker['key'], { shirt: string; accent: string }> = {
  assistant: { shirt: '#0f766e', accent: '#115e59' },
  producer: { shirt: '#0f9f9a', accent: '#0f766e' },
  asset: { shirt: '#7c3aed', accent: '#6d28d9' },
  video: { shirt: '#2563eb', accent: '#1d4ed8' },
}

const EMOJIS: Record<DigitalWorker['key'], string> = {
  assistant: '✦',
  producer: '📋',
  asset: '🎨',
  video: '🎬',
}

const WORKER_NAME_KEYS: Record<DigitalWorker['key'], string> = {
  assistant: '制作助手',
  producer: '制片助理',
  asset: '资产画师',
  video: '视频剪辑师',
}

const GREETING_KEYS: Record<DigitalWorker['key'], string> = {
  assistant: '我是制作助手，可以理解当前作品与剧集，运行只读制作体检，并统一处理资产、分镜、图片和视频任务。单集图片核验支持拖图或 @ 引用人物资产，先逐镜头列出冲突，确认后才创建新分镜版本。',
  producer: '我是制片助理，负责剧本拆解、剧集规划和工作流进度。可以问我"现在有哪些剧本在生产""哪个任务卡住了"，也可以让我取消失败的工作流任务。',
  asset: '我是资产画师，负责人物、场景、道具资产和参考图。可以按作品名查资产缺图情况，也可以让我补图、重试失败任务或取消排队中的生图任务。',
  video: '我是视频剪辑师，负责分镜视频生成。可以问我"视频任务跑到哪了""有没有失败的"，让我重试失败任务或取消排队任务。',
}

// 每位员工的预设问题，降低用户开口门槛
const PRESET_QUESTION_KEYS: Record<DigitalWorker['key'], string[]> = {
  assistant: ['用主角图片检查《作品名》第1集分镜', '有哪些排队中的生图任务？'],
  producer: ['现在有哪些剧本在生产？', '哪个任务卡住了？'],
  asset: ['哪些资产还缺参考图？', '有失败的生图任务吗？'],
  video: ['视频任务跑到哪了？', '有没有失败的视频任务？'],
}

const ASSISTANT_CAPABILITIES: AssistantCapability[] = [
  {
    title: '运行制作体检',
    description: '只读汇总缺失内容、失败任务和一致性问题。',
    example: '检查当前作品是否可以继续生成',
  },
  {
    title: '查询生产进度',
    description: '查看当前生产、队列、失败任务和工作流状态。',
    example: '现在有哪些任务正在生产？',
  },
  {
    title: '查询作品与资产',
    description: '查看作品、剧集、人物、场景、道具及缺图情况。',
    example: '查看《作品名》的剧集和缺图资产',
  },
  {
    title: '查看与修改分镜',
    description: '查看指定镜头，并按要求修改单个分镜。',
    example: '把《作品名》第1集第3镜改成近景',
  },
  {
    title: '生成与管理图片',
    description: '生成或重画资产、镜头图片，重试或取消生图任务。',
    example: '为《作品名》第1集第3镜生成一张图片',
  },
  {
    title: '检查内容一致性',
    description: '支持文本检查，也可用实际人物图片逐镜头核验单集；确认后再改。',
    example: '用主角图片检查《作品名》第1集分镜',
  },
  {
    title: '管理视频任务',
    description: '查询、重试或取消指定剧集的视频任务。',
    example: '查询《作品名》第1集的视频任务',
  },
]

// 员工状态文案（recent 项用）
const STATUS_LABELS: Record<string, string> = {
  queued: '排队中',
  blocked: '等待中',
  running: '进行中',
  waiting: '生成中',
  success: '已完成',
  failed: '失败',
  cancelled: '已取消',
  skipped: '已跳过',
}

function statusLabel(status: string): string {
  return t(STATUS_LABELS[status] ?? status)
}

function workflowStatusLabel(status: WorkflowContextSnapshot['status']): string {
  const map: Record<WorkflowContextSnapshot['status'], string> = {
    queued: '排队中',
    running: '运行中',
    failed: '失败',
    success: '已完成',
    cancelled: '已取消',
  }
  return t(map[status] ?? status)
}

function paletteOf(key: DigitalWorker['key']) {
  return PALETTES[key] ?? PALETTES.producer
}

function emojiOf(key: DigitalWorker['key']) {
  return EMOJIS[key] ?? '🧑‍💻'
}

function workerName(w: DigitalWorker | DigitalWorker['key']): string {
  const key = typeof w === 'string' ? w : w.key
  return t(WORKER_NAME_KEYS[key] ?? (typeof w === 'string' ? w : w.name))
}

function greetingOf(key: DigitalWorker['key']): string {
  return t(GREETING_KEYS[key])
}

function presetQuestionsOf(key: DigitalWorker['key']): string[] {
  return PRESET_QUESTION_KEYS[key].map((question) => t(question))
}

function stateText(w: DigitalWorker): string {
  if (w.state === 'working') return t('干活中 · {count} 个任务', { count: w.running })
  if (w.state === 'queued') return t('排队 {count} 个任务', { count: w.queued })
  if (w.state === 'alert') return t('今日 {count} 个任务失败', { count: w.failed_today })
  return t('空闲中')
}

function chatPlaceholder(name: string): string {
  return t('对{name}说点什么…（Enter 发送）', { name })
}

function activeCount(w: DigitalWorker): number {
  return w.running + w.queued
}

function readWorkflowContextSnapshot(): WorkflowContextSnapshot | null {
  try {
    const raw = localStorage.getItem(WORKFLOW_CONTEXT_STORAGE_KEY)
    if (!raw) return null
    const parsed = JSON.parse(raw) as WorkflowContextSnapshot
    if (!parsed || typeof parsed !== 'object') return null
    if (parsed.status !== 'queued' && parsed.status !== 'running') {
      localStorage.removeItem(WORKFLOW_CONTEXT_STORAGE_KEY)
      return null
    }
    return parsed
  } catch {
    return null
  }
}

function refreshWorkflowContext() {
  workflowContext.value = readWorkflowContextSnapshot()
}

function notifyWorkerChanges(nextWorkers: DigitalWorker[]) {
  if (!hasWorkerSnapshot) {
    previousWorkers = new Map(nextWorkers.map((w) => [w.key, { ...w }]))
    hasWorkerSnapshot = true
    return
  }

  for (const worker of nextWorkers) {
    const prev = previousWorkers.get(worker.key)
    if (!prev) continue

    const prevActive = activeCount(prev)
    const nextActive = activeCount(worker)
    const doneDelta = worker.done_today - prev.done_today
    const failedDelta = worker.failed_today - prev.failed_today

    if (failedDelta > 0) {
      notifyTask({
        title: t('{name}需要处理', { name: workerName(worker) }),
        message: worker.current || t('{count} 个任务失败了，点开数字员工查看原因。', { count: failedDelta }),
        type: 'error',
        tag: `worker-${worker.key}-failed-${worker.failed_today}`,
      })
      continue
    }

    if (doneDelta > 0 && nextActive < prevActive) {
      const completedText = doneDelta === 1 ? t('刚完成 1 个任务') : t('刚完成 {count} 个任务', { count: doneDelta })
      const restText = nextActive > 0 ? t('，还有 {count} 个在跑', { count: nextActive }) : t('，当前没有待处理任务')
      notifyTask({
        title: t('{name}有新进展', { name: workerName(worker) }),
        message: `${completedText}${restText}。`,
        type: 'success',
        tag: `worker-${worker.key}-done-${worker.done_today}`,
      })
    }
  }

  previousWorkers = new Map(nextWorkers.map((w) => [w.key, { ...w }]))
}

function isAwake(state: WorkerState): boolean {
  return state !== 'idle'
}

function toggle(next: boolean) {
  collapsed.value = next
  if (next) menuOpen.value = false
  localStorage.setItem(COLLAPSED_KEY, next ? '1' : '0')
}

/**
 * 目前前台只展示一位统一制作助手；展开入口时直接进入对话，
 * 不再要求用户经过旧的“员工列表 → 选择员工”两级流程。
 */
function openCrew() {
  toggle(false)
  const worker = workers.value.find((item) => item.key === 'assistant') ?? workers.value[0]
  if (worker) openChat(worker)
}

function gotoWorkspace(w: DigitalWorker, ev?: Event) {
  ev?.stopPropagation()
  // 后端 link 带 /admin 前缀，前端 router base 已是 /admin/
  const path = w.link.replace(/^\/admin/, '') || '/dashboard'
  router.push(path)
}

// ── 对话 ───────────────────────────────────────────────────────────────────────

function loadChat(key: string): ChatMsg[] {
  try {
    const raw = localStorage.getItem(CHAT_KEY_PREFIX + key)
    const parsed = raw ? JSON.parse(raw) : []
    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

function persistChat(key: string) {
  const msgs = chatStore.value[key] ?? []
  // 原地裁剪，保持数组引用不变（send() 中还握着这个引用）
  if (msgs.length > CHAT_KEEP) {
    msgs.splice(0, msgs.length - CHAT_KEEP)
  }
  try {
    localStorage.setItem(CHAT_KEY_PREFIX + key, JSON.stringify(msgs))
  } catch {
    // 存储满时放弃持久化，不影响会话
  }
}

function openChat(w: DigitalWorker) {
  activeKey.value = w.key
  menuOpen.value = false
  if (!chatStore.value[w.key]) {
    chatStore.value[w.key] = loadChat(w.key)
  }
  view.value = 'chat'
  scrollToBottom()
}

function backToList() {
  menuOpen.value = false
  view.value = 'list'
}

function toggleMenu() {
  menuOpen.value = !menuOpen.value
  void nextTick(() => {
    if (!chatBody.value) return
    chatBody.value.scrollTop = menuOpen.value ? 0 : chatBody.value.scrollHeight
  })
}

function clearChat() {
  const key = activeKey.value
  if (!key) return
  chatStore.value[key] = []
  persistChat(key)
}

function scrollToBottom() {
  void nextTick(() => {
    if (chatBody.value) {
      chatBody.value.scrollTop = chatBody.value.scrollHeight
    }
  })
}

function buildAgentPageContext(): AgentPageContext {
  const pageContext = readWorkerPageContext()
  const workflow = workflowContext.value
  const routeName = String(router.currentRoute.value.name ?? '')
  const context: AgentPageContext = {
    page: pageContext?.page ?? routeName,
  }

  const seriesId = pageContext?.series_id ?? workflow?.series_id
  if (seriesId && seriesId > 0) context.series_id = seriesId
  if (pageContext?.series_title) context.series_title = pageContext.series_title
  else if (workflow?.series_title) context.series_title = workflow.series_title
  if (pageContext?.episode_id) context.episode_id = pageContext.episode_id
  if (pageContext?.episode_number) context.episode_number = pageContext.episode_number
  if (pageContext?.episode_title) context.episode_title = pageContext.episode_title
  if (workflow?.run_id) context.workflow_run_id = workflow.run_id

  return context
}

async function send() {
  const key = activeKey.value
  if (!key || sending.value) return
  const text = draft.value.trim()
  if (!text) return

  const msgs = (chatStore.value[key] = chatStore.value[key] ?? [])
  msgs.push({ role: 'user', content: text, ts: Date.now() })
  draft.value = ''
  sending.value = true
  persistChat(key)
  scrollToBottom()

  // 最近 12 条作为上下文（不含刚发的这条，后端会单独接收）
  const history: AgentHistoryItem[] = msgs
    .slice(0, -1)
    .filter((m) => !m.failed)
    .slice(-12)
    .map((m) => ({ role: m.role, content: m.content }))

  try {
    const res = await chatWithWorker(key, text, history, buildAgentPageContext())
    msgs.push({
      role: 'assistant',
      content: res.reply,
      actions: res.actions?.length ? res.actions : undefined,
      ts: Date.now(),
    })
  } catch (error) {
    const message = error instanceof Error ? error.message : t('请求失败，请稍后再试')
    msgs.push({ role: 'assistant', content: message, failed: true, ts: Date.now() })
  } finally {
    sending.value = false
    persistChat(key)
    scrollToBottom()
  }
}

function onDraftKeydown(ev: KeyboardEvent) {
  if (ev.key === 'Enter' && !ev.shiftKey) {
    ev.preventDefault()
    void send()
  }
}

// ── 状态轮询 ───────────────────────────────────────────────────────────────────

async function refresh() {
  try {
    const payload = await fetchWorkerStatus()
    const nextWorkers = payload.workers ?? []
    if (nextWorkers.length === 0) {
      // 成功响应中的瞬时空列表不应清掉上一帧，否则已打开的对话窗口会消失。
      loaded.value = true
      errored.value = true
      return
    }
    notifyWorkerChanges(nextWorkers)
    workers.value = nextWorkers
    loaded.value = true
    errored.value = false
    errorBandDismissed.value = false
    refreshWorkflowContext()
    if (!collapsed.value && view.value === 'list' && activeKey.value === null && nextWorkers.length === 1) {
      openChat(nextWorkers[0])
    }
  } catch {
    // 保留上一次数据，但标记错误态：无历史数据→降级胶囊；有数据→顶部警告条
    errored.value = true
  } finally {
    schedule()
  }
}

/** 降级胶囊上的手动重连。 */
function manualRetry() {
  if (timer !== null) window.clearTimeout(timer)
  void refresh()
}

/** 一键动作（重试失败/取消排队）：调用后用返回的最新状态刷新面板。 */
async function runAction(w: DigitalWorker, action: WorkerActionType, ev?: Event) {
  ev?.stopPropagation()
  const tag = `${w.key}:${action}`
  if (actionPending.value) return
  actionPending.value = tag
  try {
    const res = await runWorkerAction(w.key, action)
    if (Array.isArray(res.workers) && res.workers.length) {
      workers.value = res.workers
      previousWorkers = new Map(res.workers.map((item) => [item.key, { ...item }]))
    }
    if (res.affected > 0) {
      ElMessage.success(action === 'retry_failed'
        ? t('已重试 {count} 个任务', { count: res.affected })
        : t('已取消 {count} 个任务', { count: res.affected }))
    } else {
      ElMessage.info(action === 'retry_failed' ? t('没有需要重试的任务') : t('没有需要取消的任务'))
    }
  } catch (error) {
    const message = error instanceof Error ? error.message : t('操作失败，请稍后再试')
    ElMessage.error(message)
  } finally {
    actionPending.value = ''
  }
}

/** 点击预设问题：填入草稿并直接发送。 */
function sendPreset(text: string) {
  if (sending.value) return
  menuOpen.value = false
  draft.value = text
  void send()
}

function onVisualReviewApplied(message: string) {
  const key = activeKey.value
  if (!key) return
  menuOpen.value = false
  const messages = (chatStore.value[key] = chatStore.value[key] ?? [])
  messages.push({ role: 'assistant', content: message, ts: Date.now() })
  persistChat(key)
  scrollToBottom()
  void refresh()
}

function schedule() {
  if (disposed) return
  if (timer !== null) window.clearTimeout(timer)
  if (document.hidden) {
    timer = window.setTimeout(refresh, 30_000)
    return
  }
  if (totalActive.value > 0) {
    timer = window.setTimeout(refresh, 5_000)
  }
}

function scheduleContextRefresh() {
  if (disposed) return
  if (contextTimer !== null) window.clearTimeout(contextTimer)
  contextTimer = window.setTimeout(() => {
    refreshWorkflowContext()
    scheduleContextRefresh()
  }, 2000)
}

function onVisibilityChange() {
  if (!document.hidden) {
    if (timer !== null) window.clearTimeout(timer)
    void refresh()
  }
}

function onWorkerRefreshSignal() {
  if (disposed) return
  if (timer !== null) window.clearTimeout(timer)
  void refresh()
}

onMounted(() => {
  void refresh()
  scheduleContextRefresh()
  document.addEventListener('visibilitychange', onVisibilityChange)
  window.addEventListener('malulu:worker-refresh', onWorkerRefreshSignal)
})

onBeforeUnmount(() => {
  disposed = true
  if (timer !== null) window.clearTimeout(timer)
  if (contextTimer !== null) window.clearTimeout(contextTimer)
  document.removeEventListener('visibilitychange', onVisibilityChange)
  window.removeEventListener('malulu:worker-refresh', onWorkerRefreshSignal)
})
</script>

<template>
  <div class="worker-crew" :class="{ 'worker-crew--docked': !loaded || !hasData || collapsed }">
    <!-- 首屏加载中：骨架胶囊（不再空白闪现） -->
    <div v-if="!loaded && !errored" class="crew-pill crew-pill--dock crew-pill--skeleton">
      <span class="pill-dot dot-idle" />
      <span class="pill-label">{{ t('数字员工') }}</span>
      <span class="pill-skeleton-bar" />
    </div>

    <!-- 首屏失败且无历史数据：降级胶囊，入口永不消失，点击重连 -->
    <button
      v-else-if="!hasData"
      class="crew-pill crew-pill--dock crew-pill--offline"
      type="button"
      :title="t('数字员工暂时连不上，点击重试')"
      @click="manualRetry"
    >
      <span class="pill-dot dot-offline" />
      <span class="pill-label">{{ t('数字员工') }}</span>
      <span class="pill-offline-text">{{ t('连接中 · 点击重试') }}</span>
    </button>

    <!-- 正常态：收起胶囊 / 展开面板 -->
    <template v-else>
    <!-- 收起态：右侧吸附浮标 -->
    <button
      v-if="collapsed"
      class="crew-pill crew-pill--dock"
      type="button"
      :title="t('展开数字员工')"
      @click="openCrew"
    >
      <span
        v-for="w in workers"
        :key="w.key"
        class="pill-dot"
        :class="`dot-${w.state}`"
        :title="`${workerName(w)}：${stateText(w)}`"
      />
      <span class="pill-label">{{ t('数字员工') }}</span>
      <span v-if="totalActive > 0" class="pill-count">{{ totalActive }}</span>
      <span v-else-if="totalFailed > 0" class="pill-count pill-count--alert">!</span>
    </button>

    <!-- 展开态：员工面板 -->
    <div v-else class="crew-panel" :class="{ 'crew-panel--chat': view === 'chat' }">
      <div class="crew-header">
        <template v-if="view === 'chat' && activeWorker">
          <button class="crew-back" type="button" :title="t('返回员工列表')" @click="backToList">‹</button>
          <span class="crew-title">
            {{ emojiOf(activeWorker.key) }} {{ workerName(activeWorker) }}
            <em :class="`state-text-${activeWorker.state}`">{{ stateText(activeWorker) }}</em>
          </span>
          <span class="crew-header-actions">
            <button
              v-if="activeWorker.key === 'assistant'"
              class="crew-mini-btn crew-menu-btn"
              :class="{ 'is-active': menuOpen }"
              type="button"
              :aria-pressed="menuOpen"
              :title="menuOpen ? t('返回对话') : t('打开功能菜单')"
              @click="toggleMenu"
            >{{ menuOpen ? `← ${t('对话')}` : `☰ ${t('菜单')}` }}</button>
            <button class="crew-mini-btn" type="button" :title="t('清空对话')" @click="clearChat">{{ t('清空') }}</button>
            <button class="crew-mini-btn" type="button" :title="t('前往工作台')" @click="gotoWorkspace(activeWorker)">{{ t('工作台↗') }}</button>
            <button class="crew-collapse" type="button" @click="toggle(true)">{{ t('收起') }}</button>
          </span>
        </template>
        <template v-else>
          <span class="crew-title">
            {{ t('数字员工') }}
            <em v-if="totalActive > 0">{{ t('{count} 个任务进行中', { count: totalActive }) }}</em>
            <em v-else>{{ t('随时待命') }}</em>
          </span>
          <button class="crew-collapse" type="button" @click="toggle(true)">{{ t('收起') }}</button>
        </template>
      </div>

      <!-- 刷新失败但仍有历史数据时的提示条（数据不消失，只提示陈旧） -->
      <div v-if="errored && !errorBandDismissed" class="crew-errorband">
        <span class="errorband-text">{{ t('状态刷新失败，下方为最近一次数据') }}</span>
        <button class="errorband-retry" type="button" @click="manualRetry">{{ t('重试') }}</button>
        <button class="errorband-close" type="button" :title="t('关闭')" @click="errorBandDismissed = true">✕</button>
      </div>

      <!-- 今日汇总条：面板一打开就有信息密度（仅列表视图） -->
      <div v-if="view === 'list'" class="crew-summary">
        <span class="sum-item"><b>{{ totalRunning }}</b> {{ t('进行中') }}</span>
        <span class="sum-dot" />
        <span class="sum-item"><b>{{ totalDone }}</b> {{ t('今日完成') }}</span>
        <span class="sum-dot" />
        <span class="sum-item" :class="{ 'sum-item--alert': totalFailed > 0 }"><b>{{ totalFailed }}</b> {{ t('失败') }}</span>
      </div>

      <div v-if="workflowContext" class="crew-context">
        <div class="crew-context__head">
          <span class="crew-context__label">{{ t('当前生产') }}</span>
          <span class="crew-context__badge" :class="`badge-${workflowContext.status}`">
            {{ workflowStatusLabel(workflowContext.status) }}
          </span>
        </div>
        <div class="crew-context__title">{{ activeWorkflowSeriesTitle || t('当前作品') }}</div>
        <div class="crew-context__summary">{{ activeWorkflowSummary }}</div>
        <el-progress
          :percentage="Math.max(0, Math.min(100, workflowContext.progress || 0))"
          :indeterminate="workflowContext.status === 'queued'"
          :status="workflowContext.status === 'failed' ? 'exception' : workflowContext.status === 'success' ? 'success' : undefined"
          :show-text="false"
        />
        <div v-if="activeWorkflowNodes.length" class="crew-context__nodes">
          <span
            v-for="node in activeWorkflowNodes.slice(0, 6)"
            :key="node.id"
            class="crew-context__node"
            :class="`node-${node.status}`"
            :title="node.error_message || node.label"
          >
            {{ node.label || node.kind }}
          </span>
        </div>
      </div>

      <!-- 员工列表视图 -->
      <div v-if="view === 'list'" class="crew-list">
        <div
          v-for="w in workers"
          :key="w.key"
          class="crew-member"
          :class="`is-${w.state}`"
          role="button"
          :title="t('和{name}对话', { name: workerName(w) })"
          @click="openChat(w)"
        >
          <!-- 自绘动画员工 -->
          <svg class="crew-avatar" :class="`state-${w.state}`" viewBox="0 0 96 84" aria-hidden="true">
            <g class="person">
              <circle class="head" cx="48" cy="26" r="13" fill="#f8c8a0" />
              <path
                class="hair"
                d="M35 24 q1 -13 13 -13 q12 0 13 13 q-6 -7 -13 -7 q-7 0 -13 7 Z"
                :fill="paletteOf(w.key).accent"
              />
              <g class="eyes">
                <template v-if="isAwake(w.state)">
                  <circle cx="43" cy="27" r="1.8" fill="#1f2937" />
                  <circle cx="53" cy="27" r="1.8" fill="#1f2937" />
                </template>
                <template v-else>
                  <path d="M40.5 27 h5" stroke="#1f2937" stroke-width="1.6" stroke-linecap="round" />
                  <path d="M50.5 27 h5" stroke="#1f2937" stroke-width="1.6" stroke-linecap="round" />
                </template>
              </g>
              <rect class="body" x="31" y="40" width="34" height="22" rx="9" :fill="paletteOf(w.key).shirt" />
              <rect class="arm arm-l" x="33" y="52" width="11" height="5" rx="2.5" fill="#f8c8a0" />
              <rect class="arm arm-r" x="52" y="52" width="11" height="5" rx="2.5" fill="#f8c8a0" />
            </g>

            <rect class="desk" x="14" y="64" width="68" height="6" rx="3" fill="#d8c4a4" />
            <g class="laptop">
              <rect x="37" y="49" width="22" height="14" rx="2" fill="#1f2937" />
              <rect class="screen-glow" x="39" y="51" width="18" height="10" rx="1" fill="#7dd3fc" />
            </g>

            <!-- 状态装饰 -->
            <g v-if="w.state === 'working'" class="busy-dots" fill="#94a3b8">
              <circle class="dot d1" cx="68" cy="12" r="2.2" />
              <circle class="dot d2" cx="75" cy="10" r="2.2" />
              <circle class="dot d3" cx="82" cy="8" r="2.2" />
            </g>
            <text v-else-if="w.state === 'queued'" class="hourglass" x="70" y="18" font-size="12">⏳</text>
            <text v-else-if="w.state === 'alert'" class="alert-mark" x="69" y="20" font-size="16" fill="#e11d48" font-weight="700">!</text>
            <g v-else class="zzz" fill="#94a3b8" font-weight="700">
              <text class="z z1" x="66" y="20" font-size="9">Z</text>
              <text class="z z2" x="73" y="14" font-size="7">z</text>
              <text class="z z3" x="79" y="9" font-size="5">z</text>
            </g>
          </svg>

          <div class="member-info">
            <div class="member-name">
              <span>{{ emojiOf(w.key) }} {{ workerName(w) }}</span>
              <span class="member-state" :class="`state-text-${w.state}`">{{ stateText(w) }}</span>
            </div>
            <div v-if="w.current" class="member-task">{{ w.current }}</div>
            <div v-if="w.queued || w.running || w.done_today || w.failed_today" class="member-chips">
              <span v-if="w.queued > 0" class="chip">{{ t('队列') }} {{ w.queued }}</span>
              <span v-if="w.running > 0" class="chip chip--running">{{ t('进行') }} {{ w.running }}</span>
              <span v-if="w.done_today > 0" class="chip chip--done">{{ t('今日完成') }} {{ w.done_today }}</span>
              <span v-if="w.failed_today > 0" class="chip chip--failed">{{ t('失败') }} {{ w.failed_today }}</span>
            </div>

            <!-- 空闲时展示最近任务记录，替代"今天还没有任务"的空文案 -->
            <ul v-if="!w.current && w.recent.length" class="member-recent">
              <li v-for="(r, ri) in w.recent.slice(0, 2)" :key="ri" class="recent-item">
                <span class="recent-dot" :class="`recent-${r.status}`" />
                <span class="recent-title">{{ r.title }}</span>
                <span class="recent-meta">{{ statusLabel(r.status) }} · {{ r.time }}</span>
              </li>
            </ul>
            <div v-else-if="!w.current && !w.recent.length" class="member-hint">
              {{ t('点这里问问 TA 能帮你做什么 →') }}
            </div>

            <!-- 快捷动作：根据状态条件出现 -->
            <div class="member-actions">
              <button
                v-if="w.failed_today > 0 && (w.key === 'asset' || w.key === 'video')"
                class="act-btn act-btn--retry"
                type="button"
                :disabled="actionPending === `${w.key}:retry_failed`"
                @click="runAction(w, 'retry_failed', $event)"
              >↻ {{ t('重试失败') }} {{ w.failed_today }}</button>
              <button
                v-if="w.queued > 0 && (w.key === 'asset' || w.key === 'video')"
                class="act-btn"
                type="button"
                :disabled="actionPending === `${w.key}:cancel_queued`"
                @click="runAction(w, 'cancel_queued', $event)"
              >✕ {{ t('取消排队') }} {{ w.queued }}</button>
              <button class="act-btn act-btn--chat" type="button" @click.stop="openChat(w)">💬 {{ t('问问 TA') }}</button>
            </div>
          </div>

          <button class="member-goto" type="button" :title="t('前往工作台')" @click="gotoWorkspace(w, $event)">↗</button>
        </div>
      </div>

      <!-- 对话视图 -->
      <template v-else-if="activeWorker">
        <div ref="chatBody" class="chat-body" :class="{ 'is-menu-open': menuOpen }">
          <section v-if="activeWorker.key === 'assistant'" v-show="menuOpen" class="chat-menu">
            <div class="chat-menu__head">
              <strong>{{ t('功能菜单') }}</strong>
              <span>{{ t('选择一项开始处理；单集图片核验可按需展开。') }}</span>
            </div>

            <WorkerVisualReviewPanel @applied="onVisualReviewApplied" />

            <section class="capability-list">
              <div class="capability-heading">
                <strong>{{ t('功能清单') }}</strong>
                <span>{{ t('点击一项即可提问，也可以直接问我“你能做什么？”') }}</span>
              </div>
              <div class="capability-grid">
                <button
                  v-for="capability in ASSISTANT_CAPABILITIES"
                  :key="capability.title"
                  class="capability-card"
                  type="button"
                  @click="sendPreset(t(capability.example))"
                >
                  <strong>{{ t(capability.title) }}</strong>
                  <span>{{ t(capability.description) }}</span>
                  <small>{{ t('示例：{question}', { question: t(capability.example) }) }}</small>
                </button>
              </div>
            </section>
          </section>

          <template v-if="!menuOpen">
            <div class="chat-msg chat-msg--assistant">
              <div class="chat-bubble chat-bubble--greeting">{{ greetingOf(activeWorker.key) }}</div>
            </div>

            <div v-if="activeWorker.key !== 'assistant' && !activeMessages.length && !sending" class="chat-presets">
              <button
                v-for="(q, qi) in presetQuestionsOf(activeWorker.key)"
                :key="qi"
                class="preset-chip"
                type="button"
                @click="sendPreset(q)"
              >{{ q }}</button>
            </div>

            <div
              v-for="(m, i) in activeMessages"
              :key="i"
              class="chat-msg"
              :class="m.role === 'user' ? 'chat-msg--user' : 'chat-msg--assistant'"
            >
              <div v-if="m.actions?.length" class="chat-actions" aria-label="本轮已执行工具">
                <span
                  v-for="(a, j) in m.actions"
                  :key="j"
                  class="chat-action"
                  :class="{ 'chat-action--failed': !a.ok }"
                >{{ a.ok ? t('已执行：{label}', { label: a.label }) : t('执行失败：{label}', { label: a.label }) }}</span>
              </div>
              <div class="chat-bubble" :class="{ 'chat-bubble--failed': m.failed }">{{ m.content }}</div>
            </div>

            <div v-if="sending" class="chat-msg chat-msg--assistant">
              <div class="chat-bubble chat-bubble--thinking">
                <span class="think-dot" /><span class="think-dot" /><span class="think-dot" />
              </div>
            </div>
          </template>
        </div>

        <div v-show="!menuOpen" class="chat-composer">
          <textarea
            v-model="draft"
            class="chat-input"
            rows="2"
            :placeholder="chatPlaceholder(workerName(activeWorker))"
            :disabled="sending"
            @keydown="onDraftKeydown"
          />
          <button class="chat-send" type="button" :disabled="sending || !draft.trim()" @click="send">
            {{ t('发送') }}
          </button>
        </div>
      </template>
    </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.worker-crew {
  position: fixed;
  right: 20px;
  bottom: 20px;
  z-index: 1800;
  font-family: var(--font-sans);

  &--docked {
    right: 0;
    bottom: auto;
    top: 50%;
    transform: translateY(-50%);
  }
}

/* ── 收起态胶囊 ─────────────────────────────────────────────── */
.crew-pill {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 14px;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-pill);
  background: var(--surface-card);
  box-shadow: 0 6px 24px rgba(15, 23, 42, 0.12);
  cursor: pointer;
  transition: transform 0.15s ease, box-shadow 0.15s ease;

  &:hover {
    transform: translateY(-2px);
    box-shadow: 0 10px 28px rgba(15, 23, 42, 0.16);
  }
}

/* 吸附右侧边缘的竖向浮标 */
.crew-pill--dock {
  flex-direction: column;
  gap: 8px;
  padding: 14px 10px;
  border-right: none;
  border-radius: 14px 0 0 14px;
  box-shadow: -6px 8px 28px rgba(15, 23, 42, 0.14);
  writing-mode: horizontal-tb;

  .pill-label {
    writing-mode: vertical-rl;
    letter-spacing: 0.12em;
    line-height: 1.2;
  }

  .pill-offline-text {
    writing-mode: vertical-rl;
    letter-spacing: 0.08em;
  }

  &:hover {
    transform: translateX(-3px);
    box-shadow: -10px 10px 32px rgba(15, 23, 42, 0.18);
  }
}

.worker-crew--docked .crew-pill--skeleton:hover {
  transform: none;
}

/* 首屏骨架胶囊：不可点，提示加载中 */
.crew-pill--skeleton {
  cursor: default;
  opacity: 0.7;

  &:hover {
    transform: none;
    box-shadow: 0 6px 24px rgba(15, 23, 42, 0.12);
  }
}

.pill-skeleton-bar {
  width: 28px;
  height: 8px;
  border-radius: var(--radius-pill);
  background: var(--surface-soft);
  animation: crew-pulse 1.2s ease-in-out infinite;
}

/* 降级胶囊：接口连不上时的兜底入口 */
.crew-pill--offline {
  border-style: dashed;
}

.pill-offline-text {
  font-size: 12px;
  color: var(--muted);
}

.dot-offline {
  width: 9px;
  height: 9px;
  border-radius: 50%;
  background: var(--muted-soft);
  animation: crew-pulse 1.2s ease-in-out infinite;
}

.pill-label {
  font-size: 13px;
  font-weight: 600;
  color: var(--body-strong);
}

.pill-count {
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: var(--radius-pill);
  background: var(--brand-cyan);
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  line-height: 18px;
  text-align: center;

  &--alert {
    background: var(--accent-rose);
  }
}

.pill-dot {
  width: 9px;
  height: 9px;
  border-radius: 50%;

  &.dot-working {
    background: var(--accent-emerald);
    animation: crew-pulse 1.2s ease-in-out infinite;
  }
  &.dot-queued {
    background: var(--brand-amber);
  }
  &.dot-alert {
    background: var(--accent-rose);
    animation: crew-pulse 0.8s ease-in-out infinite;
  }
  &.dot-idle {
    background: var(--muted-soft);
  }
}

@keyframes crew-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.35; }
}

/* ── 展开态面板 ─────────────────────────────────────────────── */
.crew-panel {
  display: flex;
  flex-direction: column;
  width: min(380px, calc(100vw - 40px));
  border: 1px solid var(--hairline);
  border-radius: 14px;
  background: var(--surface-card);
  box-shadow: 0 14px 44px rgba(15, 23, 42, 0.16);
  overflow: hidden;

  &--chat {
    height: min(560px, calc(100vh - 40px));
  }
}

.crew-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 10px 14px;
  border-bottom: 1px solid var(--hairline);
  background: var(--surface-raised);
  flex-shrink: 0;
}

.crew-title {
  flex: 1;
  min-width: 0;
  font-size: 13px;
  font-weight: 700;
  color: var(--body-strong);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;

  em {
    margin-left: 6px;
    font-style: normal;
    font-size: 12px;
    font-weight: 500;
    color: var(--muted);
  }
}

.crew-back {
  border: none;
  background: none;
  font-size: 20px;
  line-height: 1;
  color: var(--muted);
  cursor: pointer;
  padding: 0 4px;

  &:hover {
    color: var(--body-strong);
  }
}

.crew-header-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.crew-mini-btn {
  border: none;
  background: none;
  font-size: 12px;
  color: var(--muted);
  cursor: pointer;
  white-space: nowrap;

  &:hover {
    color: var(--body-strong);
  }
}

.crew-menu-btn.is-active {
  padding: 3px 7px;
  border-radius: 7px;
  background: rgba(15, 159, 154, 0.1);
  color: var(--brand-cyan);
}

.crew-collapse {
  border: none;
  background: none;
  font-size: 12px;
  color: var(--muted);
  cursor: pointer;
  white-space: nowrap;

  &:hover {
    color: var(--body-strong);
  }
}

.state-text-working { color: var(--accent-emerald) !important; }
.state-text-queued { color: var(--accent-amber) !important; }
.state-text-alert { color: var(--accent-rose) !important; }

.crew-list {
  display: flex;
  flex-direction: column;
}

.crew-context {
  padding: 10px 14px 12px;
  border-bottom: 1px solid var(--hairline);
  background: linear-gradient(180deg, var(--surface-soft), var(--surface-card));
}

.crew-context__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.crew-context__label {
  font-size: 11px;
  font-weight: 700;
  color: var(--muted);
  letter-spacing: 0;
}

.crew-context__badge {
  padding: 2px 8px;
  border-radius: var(--radius-pill);
  font-size: 11px;
  font-weight: 700;
  background: var(--surface-raised);
  color: var(--muted);
}

.badge-running,
.badge-queued {
  color: var(--brand-cyan);
}

.badge-failed {
  color: var(--accent-rose);
}

.badge-success {
  color: var(--accent-emerald);
}

.crew-context__title {
  margin-top: 6px;
  font-size: 13px;
  font-weight: 700;
  color: var(--body-strong);
}

.crew-context__summary {
  margin-top: 4px;
  font-size: 12px;
  color: var(--muted);
  line-height: 1.45;
}

.crew-context__nodes {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 10px;
}

.crew-context__node {
  max-width: 100%;
  padding: 2px 8px;
  border-radius: var(--radius-pill);
  background: var(--surface-soft);
  color: var(--muted);
  font-size: 11px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;

  &.node-running {
    background: rgba(15, 118, 110, 0.12);
    color: var(--accent-emerald);
  }

  &.node-success {
    background: rgba(37, 99, 235, 0.1);
    color: var(--accent-blue);
  }

  &.node-failed {
    background: rgba(225, 29, 72, 0.12);
    color: var(--accent-rose);
  }
}

/* ── 刷新失败提示条 ─────────────────────────────────────────── */
.crew-errorband {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 7px 14px;
  background: rgba(225, 29, 72, 0.06);
  border-bottom: 1px solid var(--hairline);
  font-size: 12px;
  color: var(--accent-rose);
}

.errorband-text {
  flex: 1;
  min-width: 0;
}

.errorband-retry {
  border: none;
  background: none;
  color: var(--brand-cyan);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}

.errorband-close {
  border: none;
  background: none;
  color: var(--muted);
  font-size: 12px;
  cursor: pointer;
}

/* ── 今日汇总条 ─────────────────────────────────────────────── */
.crew-summary {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 9px 14px;
  border-bottom: 1px solid var(--hairline);
  background: var(--surface-raised);
  font-size: 12px;
  color: var(--muted);

  b {
    color: var(--body-strong);
    font-size: 14px;
    font-weight: 700;
    margin-right: 3px;
  }
}

.sum-item--alert b {
  color: var(--accent-rose);
}

.sum-dot {
  width: 3px;
  height: 3px;
  border-radius: 50%;
  background: var(--muted-soft);
}

.crew-member {
  position: relative;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 12px;
  cursor: pointer;
  transition: background 0.15s ease;

  & + & {
    border-top: 1px dashed var(--hairline);
  }

  &:hover {
    background: var(--surface-soft);

    .member-goto {
      opacity: 1;
    }
  }

  &.is-alert {
    background: rgba(225, 29, 72, 0.04);
  }
}

.member-info {
  flex: 1;
  min-width: 0;
}

.member-name {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  font-size: 13px;
  font-weight: 600;
  color: var(--body-strong);
}

.member-state {
  flex-shrink: 0;
  font-size: 11px;
  font-weight: 500;
  color: var(--muted);
}

.member-task {
  margin-top: 3px;
  font-size: 12px;
  color: var(--body);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.member-chips {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 5px;
}

.member-goto {
  position: absolute;
  top: 8px;
  right: 10px;
  border: none;
  background: var(--surface-soft);
  border-radius: var(--radius-sm);
  padding: 1px 6px;
  font-size: 12px;
  color: var(--muted);
  cursor: pointer;
  opacity: 0;
  transition: opacity 0.15s ease;

  &:hover {
    color: var(--body-strong);
  }
}

.chip {
  padding: 1px 7px;
  border-radius: var(--radius-pill);
  background: var(--surface-soft);
  font-size: 11px;
  color: var(--muted);

  &--running { background: rgba(22, 163, 74, 0.1); color: var(--accent-emerald); }
  &--done { background: rgba(37, 99, 235, 0.08); color: var(--accent-blue); }
  &--failed { background: rgba(225, 29, 72, 0.1); color: var(--accent-rose); }
  &--empty { background: transparent; padding-left: 0; }
}

/* ── 空闲态：最近记录 / 引导 ─────────────────────────────────── */
.member-recent {
  list-style: none;
  margin: 5px 0 0;
  padding: 0;
}

.recent-item {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 11px;
  line-height: 1.7;
  color: var(--muted);
}

.recent-dot {
  flex-shrink: 0;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--muted-soft);

  &.recent-success { background: var(--accent-blue); }
  &.recent-failed { background: var(--accent-rose); }
  &.recent-running { background: var(--accent-emerald); }
  &.recent-cancelled,
  &.recent-skipped { background: var(--muted-soft); }
}

.recent-title {
  flex: 1;
  min-width: 0;
  color: var(--body);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.recent-meta {
  flex-shrink: 0;
  color: var(--muted);
}

.member-hint {
  margin-top: 5px;
  font-size: 11px;
  color: var(--muted);
}

/* ── 快捷动作 ───────────────────────────────────────────────── */
.member-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 8px;
}

.act-btn {
  border: 1px solid var(--hairline);
  border-radius: var(--radius-pill);
  padding: 2px 10px;
  background: var(--surface-card);
  font-size: 11px;
  color: var(--body-strong);
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease;

  &:hover {
    background: var(--surface-soft);
  }

  &:disabled {
    opacity: 0.55;
    cursor: not-allowed;
  }

  &--retry {
    border-color: rgba(225, 29, 72, 0.35);
    color: var(--accent-rose);
  }

  &--chat {
    margin-left: auto;
    border-color: rgba(15, 159, 154, 0.4);
    color: var(--brand-cyan);
  }
}

/* ── 对话视图 ───────────────────────────────────────────────── */
.chat-body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  background: var(--canvas);

  &.is-menu-open {
    background: var(--surface-soft);
  }
}

.chat-menu {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.chat-menu__head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 10px;

  strong {
    flex-shrink: 0;
    font-size: 13px;
    color: var(--body-strong);
  }

  span {
    font-size: 10px;
    line-height: 1.4;
    color: var(--muted);
    text-align: right;
  }
}

.chat-msg {
  display: flex;
  flex-direction: column;
  gap: 4px;

  &--user {
    align-items: flex-end;
  }

  &--assistant {
    align-items: flex-start;
  }
}

.chat-bubble {
  max-width: 86%;
  padding: 8px 11px;
  border-radius: 12px;
  font-size: 13px;
  line-height: 1.55;
  color: var(--body-strong);
  white-space: pre-wrap;
  word-break: break-word;

  .chat-msg--user & {
    background: var(--primary);
    color: var(--on-primary);
    border-bottom-right-radius: 4px;
  }

  .chat-msg--assistant & {
    background: var(--surface-card);
    border: 1px solid var(--hairline);
    border-bottom-left-radius: 4px;
  }

  &--greeting {
    color: var(--muted);
    font-size: 12px;
  }

  &--failed {
    border-color: rgba(225, 29, 72, 0.35) !important;
    background: rgba(225, 29, 72, 0.05) !important;
    color: var(--accent-rose);
  }

  &--thinking {
    display: flex;
    align-items: center;
    gap: 4px;
    padding: 10px 14px;
  }
}

.think-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: var(--muted-soft);
  animation: crew-pulse 1s ease-in-out infinite;

  &:nth-child(2) { animation-delay: 0.2s; }
  &:nth-child(3) { animation-delay: 0.4s; }
}

.chat-presets {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.preset-chip {
  border: 1px solid rgba(15, 159, 154, 0.4);
  border-radius: var(--radius-pill);
  padding: 4px 11px;
  background: var(--surface-card);
  font-size: 12px;
  color: var(--brand-cyan);
  cursor: pointer;
  transition: background 0.15s ease;

  &:hover {
    background: rgba(15, 159, 154, 0.08);
  }
}

.capability-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.capability-heading {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 8px;

  strong {
    flex-shrink: 0;
    font-size: 12px;
    color: var(--body-strong);
  }

  span {
    font-size: 10px;
    color: var(--muted);
    text-align: right;
  }
}

.capability-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 6px;
}

.capability-card {
  display: flex;
  min-width: 0;
  flex-direction: column;
  align-items: flex-start;
  gap: 3px;
  padding: 8px 9px;
  border: 1px solid rgba(15, 159, 154, 0.28);
  border-radius: 10px;
  background: var(--surface-card);
  text-align: left;
  cursor: pointer;
  transition: border-color 0.15s ease, background 0.15s ease, transform 0.15s ease;

  strong {
    font-size: 12px;
    color: var(--body-strong);
  }

  span {
    font-size: 11px;
    line-height: 1.4;
    color: var(--muted);
  }

  small {
    font-size: 10px;
    line-height: 1.35;
    color: var(--brand-cyan);
  }

  &:hover {
    border-color: rgba(15, 159, 154, 0.55);
    background: rgba(15, 159, 154, 0.06);
    transform: translateY(-1px);
  }
}

.chat-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
}

.chat-action {
  padding: 1px 8px;
  border-radius: 6px;
  background: rgba(15, 159, 154, 0.08);
  font-size: 11px;
  color: var(--muted);
  cursor: default;
  user-select: text;
  pointer-events: none;

  &--failed {
    background: rgba(225, 29, 72, 0.08);
    color: var(--accent-rose);
  }
}

.chat-composer {
  display: flex;
  align-items: flex-end;
  gap: 8px;
  padding: 10px 12px;
  border-top: 1px solid var(--hairline);
  background: var(--surface-card);
  flex-shrink: 0;
}

@media (max-width: 1320px) {
  .worker-crew:not(.worker-crew--docked) {
    right: 12px;
    bottom: 12px;
  }

  .crew-panel {
    width: min(340px, calc(100vw - 24px));
  }
}

@media (max-width: 720px) {
  .worker-crew:not(.worker-crew--docked) {
    left: 12px;
    right: 12px;
  }

  .worker-crew--docked {
    top: auto;
    bottom: 18%;
    transform: none;
  }

  .crew-panel {
    width: 100%;
    max-height: calc(100vh - 24px);
  }

  .crew-panel--chat {
    height: min(520px, calc(100vh - 24px));
  }

  .crew-header {
    align-items: flex-start;
  }

  .crew-header-actions {
    gap: 4px;
    flex-wrap: wrap;
    justify-content: flex-end;
  }

  .chat-composer {
    align-items: stretch;
    flex-direction: column;
  }

  .chat-send {
    width: 100%;
  }

  .capability-grid {
    grid-template-columns: 1fr;
  }
}

.chat-input {
  flex: 1;
  resize: none;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  padding: 7px 10px;
  font-size: 13px;
  font-family: var(--font-sans);
  line-height: 1.5;
  color: var(--body-strong);
  background: var(--surface-raised);
  outline: none;

  &:focus {
    border-color: var(--brand-cyan);
  }

  &:disabled {
    opacity: 0.6;
  }
}

.chat-send {
  border: none;
  border-radius: 10px;
  padding: 8px 14px;
  background: var(--primary);
  color: var(--on-primary);
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  flex-shrink: 0;

  &:disabled {
    background: var(--primary-disabled);
    cursor: not-allowed;
  }
}

/* ── 员工动画 ───────────────────────────────────────────────── */
.crew-avatar {
  width: 64px;
  height: 56px;
  flex-shrink: 0;

  .arm,
  .head,
  .eyes,
  .hair,
  .person,
  .screen-glow {
    transform-box: fill-box;
    transform-origin: center;
  }

  /* working：埋头打字 */
  &.state-working {
    .arm-l { animation: crew-type 0.42s ease-in-out infinite; }
    .arm-r { animation: crew-type 0.42s ease-in-out 0.21s infinite; }
    .head { animation: crew-bob 1.6s ease-in-out infinite; }
    .hair { animation: crew-bob 1.6s ease-in-out infinite; }
    .eyes { animation: crew-bob 1.6s ease-in-out infinite; }
    .screen-glow { animation: crew-glow 1.4s ease-in-out infinite; }
    .busy-dots .dot { animation: crew-blink 1.2s ease-in-out infinite; }
    .busy-dots .d2 { animation-delay: 0.2s; }
    .busy-dots .d3 { animation-delay: 0.4s; }
  }

  /* queued：原地小幅摇摆，等任务派下来 */
  &.state-queued {
    .person { animation: crew-sway 2.4s ease-in-out infinite; }
    .screen-glow { opacity: 0.55; }
    .hourglass { animation: crew-flip 2.4s ease-in-out infinite; transform-box: fill-box; transform-origin: center; }
  }

  /* alert：摇头 + 红色感叹号弹跳 */
  &.state-alert {
    .head, .hair, .eyes { animation: crew-shake 0.5s ease-in-out infinite; }
    .screen-glow { fill: #fda4af; opacity: 0.8; }
    .alert-mark { animation: crew-bounce 0.9s ease-in-out infinite; transform-box: fill-box; transform-origin: center; }
  }

  /* idle：打盹 */
  &.state-idle {
    .person { animation: crew-breathe 3.2s ease-in-out infinite; }
    .screen-glow { opacity: 0.18; }
    .zzz .z { animation: crew-float 2.8s ease-in-out infinite; transform-box: fill-box; transform-origin: center; }
    .zzz .z2 { animation-delay: 0.5s; }
    .zzz .z3 { animation-delay: 1s; }
  }
}

@keyframes crew-type {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-2.4px); }
}

@keyframes crew-bob {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(1.6px); }
}

@keyframes crew-glow {
  0%, 100% { opacity: 0.95; }
  50% { opacity: 0.5; }
}

@keyframes crew-blink {
  0%, 100% { opacity: 0.2; }
  50% { opacity: 1; }
}

@keyframes crew-sway {
  0%, 100% { transform: rotate(-1.6deg); }
  50% { transform: rotate(1.6deg); }
}

@keyframes crew-flip {
  0%, 40%, 100% { transform: rotate(0deg); }
  60%, 90% { transform: rotate(180deg); }
}

@keyframes crew-shake {
  0%, 100% { transform: translateX(0); }
  25% { transform: translateX(-1.4px); }
  75% { transform: translateX(1.4px); }
}

@keyframes crew-bounce {
  0%, 100% { transform: translateY(0); }
  50% { transform: translateY(-3px); }
}

@keyframes crew-breathe {
  0%, 100% { transform: scale(1); }
  50% { transform: scale(1.02); }
}

@keyframes crew-float {
  0% { opacity: 0; transform: translateY(3px); }
  40% { opacity: 1; }
  100% { opacity: 0; transform: translateY(-4px); }
}
</style>
