<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { usePageQuery, queryId, queryChoice } from '@/utils/pageQuery'
import {
  ArrowLeft,
  Close,
  Connection,
  Cpu,
  Download,
  Plus,
  Refresh,
  RefreshRight,
  Setting,
  VideoPause,
  VideoPlay,
} from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import {
  cancelScriptProject,
  createScriptProject,
  exportScriptProjectPdf,
  exportScriptProjectTxt,
  getScriptProject,
  getScriptExternalAccess,
  listScriptConfigs,
  listScriptProjects,
  listScriptTextModels,
  pauseScriptProject,
  resumeScriptProject,
  retryScriptProject,
  revokeScriptExternalAccess,
  rotateScriptExternalAccess,
  saveScriptConfigs,
  startScriptProject,
  type ScriptProjectPayload,
  type ScriptExternalAccessStatus,
} from '@/api/scriptCreation'
import { copyTextToClipboard } from '@/utils/clipboard'
import type {
  ScriptAiConfig,
  ScriptProject,
  ScriptProjectStatus,
  ScriptProjectSummary,
  ScriptStep,
  ScriptStepStatus,
  ScriptTextModel,
} from '@/types'

const { t } = useI18n()
const loading = ref(false)
const saving = ref(false)
const polling = ref(false)
const projects = ref<ScriptProjectSummary[]>([])
const configs = ref<ScriptAiConfig[]>([])
const configDraft = ref<ScriptAiConfig[]>([])
const textModels = ref<ScriptTextModel[]>([])
const selectedProject = ref<ScriptProject | null>(null)
const selectedStepId = usePageQuery<number | null>('step_id', null, queryId)
const locationProjectId = usePageQuery<number | null>('project_id', null, queryId)
const activeOutputTab = usePageQuery('tab', 'output', queryChoice(['output', 'handoff', 'input', 'prompt'] as const, 'output'))
const createVisible = ref(false)
const configVisible = ref(false)
const exporting = ref(false)
const exportingTxt = ref(false)
const externalAccessVisible = ref(false)
const externalAccessLoading = ref(false)
const externalAccess = ref<ScriptExternalAccessStatus | null>(null)
const revealedExternalToken = ref('')

const createForm = reactive<ScriptProjectPayload>({
  title: '',
  genre: '',
  output_language: 'zh-CN',
  region_style: 'mainland',
  synopsis: '',
  requirements: '',
})

const activeStatuses: ScriptProjectStatus[] = ['queued', 'running']
const promptVariableNames = '{{title}}、{{genre}}、{{output_language}}、{{region_style}}、{{synopsis}}、{{requirements}}、{{previous_handoff}}、{{previous_output}}、{{episode_body}}'
const promptVariableHint = computed(() => t('scriptCreation.promptVariables', { variables: promptVariableNames }))
const projectStatusLabels: Record<ScriptProjectStatus, string> = {
  draft: '等待启动',
  queued: '等待执行',
  running: 'AI 创作中',
  paused: '已暂停',
  completed: '已完成',
  failed: '执行失败',
  cancelled: '已取消',
}
const stepStatusLabels: Record<ScriptStepStatus, string> = {
  pending: '等待上一步',
  queued: '等待执行',
  running: 'AI 执行中',
  completed: '已完成',
  failed: '执行失败',
  skipped: '已跳过',
}

const selectedStep = computed(() => {
  const project = selectedProject.value
  if (!project) return null
  return project.steps.find((step) => step.id === selectedStepId.value)
    ?? project.steps.find((step) => step.step_key === project.current_step_key)
    ?? project.steps[0]
    ?? null
})

const defaultConfig = computed(() => configDraft.value.find((config) => config.config_key === 'default') ?? null)
const agentConfigs = computed(() => configDraft.value.filter((config) => config.config_key !== 'default'))
const externalAccessHeadline = computed(() => {
  if (externalAccess.value?.active) return t('首页与专用评分令牌均可使用')
  if (externalAccess.value?.configured) return t('首页 Agent 令牌可用，专用评分令牌已撤销')
  return t('首页 Agent 令牌可直接使用')
})
const externalAccessDescription = computed(() => externalAccess.value?.active
  ? t('Hermes 可任选首页 Agent 令牌或专用评分令牌调用；专用令牌仅开放剧本读取与评分权限，可独立撤销。')
  : t('Hermes 已可使用首页 Agent 令牌直接调用评分接口；如需限制为仅可读取剧本和提交评分，再生成专用评分令牌。'))
const dedicatedTokenStatus = computed(() => {
  if (externalAccess.value?.active) return t('已启用')
  if (externalAccess.value?.configured) return t('已撤销')
  return t('未生成')
})

function projectTone(status: ScriptProjectStatus) {
  if (status === 'completed') return 'done' as const
  if (status === 'failed') return 'fail' as const
  if (status === 'paused' || status === 'cancelled') return 'warning' as const
  if (status === 'queued' || status === 'running') return 'busy' as const
  return 'idle' as const
}

function stepTone(status: ScriptStepStatus) {
  if (status === 'completed') return 'done' as const
  if (status === 'failed') return 'fail' as const
  if (status === 'running' || status === 'queued') return 'busy' as const
  if (status === 'skipped') return 'warning' as const
  return 'idle' as const
}

function agentName(name?: string) {
  return name ? t(name) : '--'
}

function stepName(step?: { step_name?: string } | null) {
  return step?.step_name ? t(step.step_name) : '--'
}

function projectWaitingText(project: ScriptProjectSummary | ScriptProject) {
  const agent = agentName(project.current_step?.agent_name)
  if (project.status === 'draft') return t('等待启动 AI 创作流程')
  if (project.status === 'queued') return t('等待 {agent} 领取任务', { agent })
  if (project.status === 'running' && project.pause_requested) return t('正在等待当前 AI 返回，随后暂停流程')
  if (project.status === 'running') return t('正在等待 {agent} 返回内容', { agent })
  if (project.status === 'paused') return t('流程已暂停，等待继续操作')
  if (project.status === 'failed') return t('等待重试失败步骤')
  if (project.status === 'cancelled') return t('流程已取消')
  if (project.status === 'completed') return t('AI 创作流程已完成')
  return project.waiting_message || t('等待操作')
}

function modelLabel(model: ScriptTextModel) {
  return `${model.name} · ${model.model_id}${model.is_default ? ` · ${t('默认')}` : ''}`
}

function formatDuration(durationMs?: number) {
  if (!durationMs) return '--'
  if (durationMs < 1000) return `${durationMs} ms`
  return `${(durationMs / 1000).toFixed(1)} s`
}

function externalEndpoint(path?: string) {
  return path ? `${window.location.origin}${path}` : '--'
}

function scoreValue(value: number | { score: number; comment: string }) {
  return typeof value === 'number' ? value : value.score
}

function cloneConfigs(items: ScriptAiConfig[]) {
  return items.map((item) => ({ ...item }))
}

async function loadBootstrap() {
  loading.value = true
  try {
    const [projectResult, configResult, modelResult] = await Promise.all([
      listScriptProjects(),
      listScriptConfigs(),
      listScriptTextModels(),
    ])
    projects.value = projectResult.projects
    configs.value = configResult.configs
    textModels.value = modelResult.models
  } finally {
    loading.value = false
  }
}

async function loadProjects() {
  const result = await listScriptProjects()
  projects.value = result.projects
}

let projectRequest = 0
let loadingProjectId: number | null = null
async function openProject(id: number) {
  const requestId = ++projectRequest
  loadingProjectId = id
  locationProjectId.value = id
  loading.value = true
  try {
    const result = await getScriptProject(id)
    if (requestId === projectRequest && locationProjectId.value === id) setProject(result.project)
  } finally {
    if (requestId === projectRequest) {
      loadingProjectId = null
      loading.value = false
    }
  }
}

function setProject(project: ScriptProject) {
  const previousStepId = selectedStepId.value
  selectedProject.value = project
  locationProjectId.value = project.id
  const selectedStillExists = project.steps.some((step) => step.id === previousStepId)
  if (!selectedStillExists) {
    selectedStepId.value = project.steps.find((step) => step.step_key === project.current_step_key)?.id
      ?? project.steps[0]?.id
      ?? null
  }
  const index = projects.value.findIndex((item) => item.id === project.id)
  if (index >= 0) projects.value[index] = project
}

function selectStep(step: ScriptStep) {
  selectedStepId.value = step.id
  activeOutputTab.value = 'output'
}

function closeProject() {
  projectRequest++
  loadingProjectId = null
  loading.value = false
  locationProjectId.value = null
  selectedProject.value = null
  selectedStepId.value = null
  void loadProjects()
}

function resetCreateForm() {
  Object.assign(createForm, {
    title: '',
    genre: '',
    output_language: 'zh-CN',
    region_style: 'mainland',
    synopsis: '',
    requirements: '',
  })
}

function openCreate() {
  resetCreateForm()
  createVisible.value = true
}

async function handleCreate() {
  if (!createForm.title.trim()) {
    ElMessage.warning(t('请输入剧本项目名称'))
    return
  }
  saving.value = true
  try {
    const result = await createScriptProject({ ...createForm, title: createForm.title.trim() })
    createVisible.value = false
    await loadProjects()
    setProject(result.project)
    ElMessage.success(t('剧本项目已创建'))
  } finally {
    saving.value = false
  }
}

function openConfigs() {
  configDraft.value = cloneConfigs(configs.value)
  configVisible.value = true
}

function toggleParameterOverride(config: ScriptAiConfig, enabled: string | number | boolean) {
  if (Boolean(enabled)) {
    config.temperature = defaultConfig.value?.temperature ?? 0.7
    config.max_tokens = defaultConfig.value?.max_tokens ?? 65536
  } else {
    config.temperature = null
    config.max_tokens = null
  }
}

async function handleConfigSave() {
  if (!defaultConfig.value?.system_prompt.trim() || !defaultConfig.value.task_prompt.trim()) {
    ElMessage.warning(t('默认配置的提示词不能为空'))
    return
  }
  saving.value = true
  try {
    const result = await saveScriptConfigs(configDraft.value)
    configs.value = result.configs
    configDraft.value = cloneConfigs(result.configs)
    configVisible.value = false
    ElMessage.success(t('AI 配置已保存'))
  } finally {
    saving.value = false
  }
}

async function refreshSelectedProject() {
  const project = selectedProject.value
  if (!project || polling.value) return
  polling.value = true
  try {
    const result = await getScriptProject(project.id, { silent: true })
    if (locationProjectId.value === project.id) setProject(result.project)
  } catch {
    // 轮询失败不打断页面
  } finally {
    polling.value = false
  }
}

async function downloadExportedFile(result: { filename: string; mime: string; base64: string }, fallbackMime: string) {
  const binary = atob(result.base64)
  const bytes = new Uint8Array(binary.length)
  for (let index = 0; index < binary.length; index += 1) bytes[index] = binary.charCodeAt(index)
  const url = URL.createObjectURL(new Blob([bytes], { type: result.mime || fallbackMime }))
  const link = document.createElement('a')
  link.href = url
  link.download = result.filename
  link.click()
  URL.revokeObjectURL(url)
}

async function handleExportPdf() {
  const project = selectedProject.value
  if (!project) return
  exporting.value = true
  try {
    const result = await exportScriptProjectPdf(project.id)
    await downloadExportedFile(result, 'application/pdf')
    ElMessage.success(t('剧本 PDF 已生成'))
  } finally {
    exporting.value = false
  }
}

async function handleExportTxt() {
  const project = selectedProject.value
  if (!project) return
  exportingTxt.value = true
  try {
    const result = await exportScriptProjectTxt(project.id)
    await downloadExportedFile(result, 'text/plain;charset=utf-8')
    ElMessage.success(t('剧本 TXT 已生成，可直接用于新建作品'))
  } finally {
    exportingTxt.value = false
  }
}

async function openExternalAccess() {
  externalAccessVisible.value = true
  revealedExternalToken.value = ''
  externalAccessLoading.value = true
  try {
    const result = await getScriptExternalAccess()
    externalAccess.value = result.access
  } finally {
    externalAccessLoading.value = false
  }
}

async function handleRotateExternalAccess() {
  if (externalAccess.value?.configured) {
    try {
      await ElMessageBox.confirm(
        t('重置后旧专用令牌立即失效，首页 Agent 令牌不受影响。'),
        t('重置专用评分令牌'),
        { type: 'warning', confirmButtonText: t('确认重置'), cancelButtonText: t('取消') },
      )
    } catch {
      return
    }
  }
  externalAccessLoading.value = true
  try {
    const result = await rotateScriptExternalAccess()
    revealedExternalToken.value = result.access.token
    const status = await getScriptExternalAccess()
    externalAccess.value = status.access
    ElMessage.success(t('专用评分令牌已生成，请立即保存'))
  } finally {
    externalAccessLoading.value = false
  }
}

async function handleRevokeExternalAccess() {
  try {
    await ElMessageBox.confirm(
      t('撤销后专用令牌无法调用，首页 Agent 令牌仍可使用。'),
      t('撤销专用评分令牌'),
      { type: 'warning', confirmButtonText: t('确认撤销'), cancelButtonText: t('取消') },
    )
  } catch {
    return
  }
  externalAccessLoading.value = true
  try {
    const result = await revokeScriptExternalAccess()
    externalAccess.value = result.access
    revealedExternalToken.value = ''
    ElMessage.success(t('专用评分令牌已撤销'))
  } finally {
    externalAccessLoading.value = false
  }
}

async function handleStart() {
  const project = selectedProject.value
  if (!project) return
  if (project.run_no > 0) {
    try {
      await ElMessageBox.confirm(
        t('重新启动会清空当前步骤产物并从第一步重新执行。'),
        t('重新启动 AI 流程'),
        { type: 'warning', confirmButtonText: t('重新启动'), cancelButtonText: t('取消') },
      )
    } catch {
      return
    }
  }
  saving.value = true
  try {
    const result = await startScriptProject(project.id)
    setProject(result.project)
    ElMessage.success(t('AI 创作流程已启动'))
  } finally {
    saving.value = false
  }
}

async function handlePause() {
  const project = selectedProject.value
  if (!project) return
  const result = await pauseScriptProject(project.id)
  setProject(result.project)
  ElMessage.success(t('已提交暂停请求'))
}

async function handleResume() {
  const project = selectedProject.value
  if (!project) return
  const result = await resumeScriptProject(project.id)
  setProject(result.project)
  ElMessage.success(t('AI 创作流程已继续'))
}

async function handleRetry() {
  const project = selectedProject.value
  if (!project) return
  const result = await retryScriptProject(project.id)
  setProject(result.project)
  ElMessage.success(t('失败步骤已重新排队'))
}

async function handleCancel() {
  const project = selectedProject.value
  if (!project) return
  try {
    await ElMessageBox.confirm(
      t('取消后将停止后续 AI 步骤；正在请求中的模型可能仍会返回本步结果。'),
      t('取消 AI 流程'),
      { type: 'warning', confirmButtonText: t('确认取消'), cancelButtonText: t('返回') },
    )
  } catch {
    return
  }
  const result = await cancelScriptProject(project.id)
  setProject(result.project)
  ElMessage.success(t('AI 创作流程已取消'))
}

let pollTimer: ReturnType<typeof setInterval> | null = null
let pageReady = false

async function restoreProject() {
  const id = locationProjectId.value
  if (!id) {
    projectRequest++
    loadingProjectId = null
    loading.value = false
    selectedProject.value = null
    return
  }
  if (selectedProject.value?.id === id) return
  if (loadingProjectId === id) return
  if (!projects.value.some((project) => project.id === id)) {
    closeProject()
    return
  }
  await openProject(id)
}

watch(locationProjectId, () => { if (pageReady) void restoreProject() })

onMounted(async () => {
  await loadBootstrap()
  await restoreProject()
  pageReady = true
  pollTimer = setInterval(() => {
    if (selectedProject.value && activeStatuses.includes(selectedProject.value.status)) {
      void refreshSelectedProject()
    }
  }, 2500)
})

onBeforeUnmount(() => {
  if (pollTimer) clearInterval(pollTimer)
})
</script>

<template>
  <div class="script-creation">
    <template v-if="!selectedProject">
      <PageToolbar kicker="SCRIPT AI FLOW" :title="t('剧本创作')" :subtitle="t('配置独立 AI 角色，启动后自动完成策划、导演、编剧与审校流程。')">
        <template #actions>
          <el-button @click="openExternalAccess"><el-icon><Connection /></el-icon><span>{{ t('外部评分接入') }}</span></el-button>
          <el-button @click="openConfigs"><el-icon><Setting /></el-icon><span>{{ t('AI 配置') }}</span></el-button>
          <el-button :loading="loading" @click="loadProjects"><el-icon><Refresh /></el-icon><span>{{ t('刷新') }}</span></el-button>
          <el-button type="primary" @click="openCreate"><el-icon><Plus /></el-icon><span>{{ t('新建剧本项目') }}</span></el-button>
        </template>
      </PageToolbar>

      <div class="script-list scrollable">
        <div class="flow-intro panel-surface">
          <div class="flow-intro__icon"><el-icon><Cpu /></el-icon></div>
          <div>
            <strong>{{ t('角色由 AI 驱动') }}</strong>
            <p>{{ t('用户只负责配置提示词、提供创作目标并观察流程；所有阶段内容由对应 AI 自动生成。') }}</p>
          </div>
          <el-button link type="primary" @click="openConfigs">{{ t('检查 AI 配置') }}</el-button>
        </div>

        <el-table v-loading="loading" :data="projects" row-key="id" class="records-table" @row-dblclick="(row) => openProject(row.id)">
          <el-table-column prop="title" :label="t('剧本项目')" min-width="220" show-overflow-tooltip />
          <el-table-column prop="genre" :label="t('题材')" width="130"><template #default="{ row }">{{ row.genre || '--' }}</template></el-table-column>
          <el-table-column :label="t('当前步骤')" width="150"><template #default="{ row }">{{ stepName(row.current_step) }}</template></el-table-column>
          <el-table-column :label="t('当前 AI')" width="150"><template #default="{ row }">{{ agentName(row.current_step?.agent_name) }}</template></el-table-column>
          <el-table-column :label="t('流程状态')" width="130">
            <template #default="{ row }"><StatusBadge :tone="projectTone(row.status)" :pulse="activeStatuses.includes(row.status)">{{ t(projectStatusLabels[row.status]) }}</StatusBadge></template>
          </el-table-column>
          <el-table-column :label="t('最新评分')" width="110" align="center">
            <template #default="{ row }"><strong v-if="row.latest_score" class="table-score">{{ row.latest_score.overall_score.toFixed(1) }}</strong><span v-else>--</span></template>
          </el-table-column>
          <el-table-column :label="t('进度')" width="110"><template #default="{ row }">{{ row.progress_completed }}/{{ row.progress_total }}</template></el-table-column>
          <el-table-column :label="t('正在等待')" min-width="240" show-overflow-tooltip><template #default="{ row }">{{ projectWaitingText(row) }}</template></el-table-column>
          <el-table-column prop="update_time" :label="t('更新时间')" width="180" />
          <el-table-column :label="t('操作')" width="100" fixed="right"><template #default="{ row }"><el-button link type="primary" @click="openProject(row.id)">{{ t('打开') }}</el-button></template></el-table-column>
          <template #empty><EmptyState :title="t('暂无剧本项目')" :hint="t('先配置各角色 AI，再创建项目并启动自动创作流程。')" /></template>
        </el-table>
      </div>
    </template>

    <template v-else>
      <PageToolbar kicker="SCRIPT AI WORKSPACE" :title="selectedProject.title" :subtitle="selectedProject.genre || t('AI 自动剧本创作工作台')">
        <template #meta>
          <StatusBadge :tone="projectTone(selectedProject.status)" :pulse="activeStatuses.includes(selectedProject.status)">{{ t(projectStatusLabels[selectedProject.status]) }}</StatusBadge>
          <span class="toolbar-progress">{{ selectedProject.progress_completed }}/{{ selectedProject.progress_total }}</span>
        </template>
        <template #actions>
          <el-button @click="closeProject"><el-icon><ArrowLeft /></el-icon><span>{{ t('返回项目列表') }}</span></el-button>
          <el-button @click="openExternalAccess"><el-icon><Connection /></el-icon><span>{{ t('外部评分') }}</span></el-button>
          <el-button v-if="selectedProject.status === 'completed' && selectedProject.final_content" :loading="exportingTxt" @click="handleExportTxt"><el-icon><Download /></el-icon><span>{{ t('导出 TXT') }}</span></el-button>
          <el-button v-if="selectedProject.status === 'completed' && selectedProject.final_content" :loading="exporting" @click="handleExportPdf"><el-icon><Download /></el-icon><span>{{ t('导出 PDF') }}</span></el-button>
          <el-button @click="openConfigs"><el-icon><Setting /></el-icon><span>{{ t('AI 配置') }}</span></el-button>
          <el-button :loading="polling" @click="refreshSelectedProject"><el-icon><Refresh /></el-icon><span>{{ t('刷新') }}</span></el-button>
          <el-button v-if="['draft', 'completed', 'cancelled'].includes(selectedProject.status)" type="primary" :loading="saving" @click="handleStart"><el-icon><VideoPlay /></el-icon><span>{{ selectedProject.run_no ? t('重新启动') : t('启动流程') }}</span></el-button>
          <el-button v-if="activeStatuses.includes(selectedProject.status)" :loading="saving" @click="handlePause"><el-icon><VideoPause /></el-icon><span>{{ t('暂停') }}</span></el-button>
          <el-button v-if="selectedProject.status === 'paused'" type="primary" :loading="saving" @click="handleResume"><el-icon><VideoPlay /></el-icon><span>{{ t('继续') }}</span></el-button>
          <el-button v-if="selectedProject.status === 'failed'" type="primary" :loading="saving" @click="handleRetry"><el-icon><RefreshRight /></el-icon><span>{{ t('重试失败步骤') }}</span></el-button>
          <el-button v-if="['queued', 'running', 'paused'].includes(selectedProject.status)" type="danger" plain :loading="saving" @click="handleCancel"><el-icon><Close /></el-icon><span>{{ t('取消流程') }}</span></el-button>
        </template>
      </PageToolbar>

      <div class="script-workbench">
        <aside class="flow-panel panel-surface">
          <div class="panel-heading">
            <div><strong>{{ t('AI 创作流程') }}</strong><small>{{ t('按顺序自动执行') }}</small></div>
            <span>{{ selectedProject.progress_completed }}/{{ selectedProject.progress_total }}</span>
          </div>
          <div class="step-list">
            <button v-for="(step, index) in selectedProject.steps" :key="step.id" type="button" class="step-item" :class="{ 'is-active': selectedStep?.id === step.id, [`is-${step.status}`]: true }" @click="selectStep(step)">
              <span class="step-index">{{ index + 1 }}</span>
              <span class="step-copy"><strong>{{ stepName(step) }}</strong><small>{{ agentName(step.agent_name) }}</small></span>
              <StatusBadge :tone="stepTone(step.status)" :pulse="step.status === 'running'">{{ t(stepStatusLabels[step.status]) }}</StatusBadge>
            </button>
          </div>
          <div class="flow-note"><span>{{ t('执行规则') }}</span><p>{{ t('上一步会产出完整交付与精简交接包；下一位 AI 默认只读取交接包。审校阶段若可分集，将按集修订后自动合并。') }}</p></div>
        </aside>

        <main class="output-panel panel-surface">
          <div class="output-heading">
            <div>
              <span class="section-kicker">{{ selectedStep?.step_key.toUpperCase() }}</span>
              <h2>{{ stepName(selectedStep) }}</h2>
              <p>{{ agentName(selectedStep?.agent_name) }} · {{ t(stepStatusLabels[selectedStep?.status || 'pending']) }}</p>
            </div>
            <div class="step-metrics"><span>{{ t('尝试') }} {{ selectedStep?.attempts || 0 }}</span><span>{{ t('耗时') }} {{ formatDuration(selectedStep?.duration_ms) }}</span></div>
          </div>

          <el-tabs v-model="activeOutputTab" class="output-tabs">
            <el-tab-pane name="output" :label="t('AI 输出')">
              <pre v-if="selectedStep?.output_content" class="content-preview">{{ selectedStep.output_content }}</pre>
              <EmptyState v-else :title="t(stepStatusLabels[selectedStep?.status || 'pending'])" :hint="selectedStep?.status === 'running' ? t('正在等待该 AI 返回完整内容。') : t('完成前序步骤后，产物会自动显示在这里。')" />
              <p v-if="selectedStep?.error_message" class="error-message">{{ selectedStep.error_message }}</p>
            </el-tab-pane>
            <el-tab-pane name="handoff" :label="t('交接包')">
              <pre v-if="selectedStep?.handoff_content" class="content-preview is-muted">{{ selectedStep.handoff_content }}</pre>
              <EmptyState v-else :title="t('暂无交接包')" :hint="t('步骤完成后会保存给下一步 AI 的精简交接内容。审校定稿通常不需要交接包。')" />
            </el-tab-pane>
            <el-tab-pane name="input" :label="t('上一步输入')">
              <pre v-if="selectedStep?.input_content" class="content-preview is-muted">{{ selectedStep.input_content }}</pre>
              <EmptyState v-else :title="t('暂无输入快照')" :hint="t('步骤开始执行后会保存实际输入。')" />
            </el-tab-pane>
            <el-tab-pane name="prompt" :label="t('提示词快照')">
              <div v-if="selectedStep?.task_prompt_snapshot" class="prompt-snapshot">
                <strong>{{ t('系统提示词') }}</strong><pre>{{ selectedStep.system_prompt_snapshot }}</pre>
                <strong>{{ t('任务提示词') }}</strong><pre>{{ selectedStep.task_prompt_snapshot }}</pre>
              </div>
              <EmptyState v-else :title="t('暂无提示词快照')" :hint="t('步骤领取任务后会保存本次实际使用的提示词。')" />
            </el-tab-pane>
          </el-tabs>
        </main>

        <aside class="runtime-panel panel-surface">
          <div class="waiting-card" :class="`is-${selectedProject.status}`">
            <span>{{ t('当前正在等待') }}</span>
            <strong>{{ projectWaitingText(selectedProject) }}</strong>
            <p v-if="selectedProject.pause_requested">{{ t('暂停请求已记录，当前 AI 返回后流程会停止。') }}</p>
          </div>

          <section class="runtime-section">
            <h3>{{ t('运行状态') }}</h3>
            <dl>
              <div><dt>{{ t('当前步骤') }}</dt><dd>{{ stepName(selectedProject.current_step) }}</dd></div>
              <div><dt>{{ t('当前 AI') }}</dt><dd>{{ agentName(selectedProject.current_step?.agent_name) }}</dd></div>
              <div><dt>{{ t('运行轮次') }}</dt><dd>#{{ selectedProject.run_no || 0 }}</dd></div>
              <div><dt>{{ t('开始时间') }}</dt><dd>{{ selectedProject.started_at || '--' }}</dd></div>
            </dl>
          </section>

          <section class="runtime-section brief-section">
            <h3>{{ t('创作输入') }}</h3>
            <span>{{ t('题材') }}</span><p>{{ selectedProject.genre || t('未指定') }}</p>
            <span>{{ t('输出语言') }}</span><p>{{ t('简体中文') }}</p>
            <span>{{ t('地区风格') }}</span><p>{{ t(selectedProject.region_style === 'overseas' ? '海外' : '中国大陆') }}</p>
            <span>{{ t('故事梗概') }}</span><p>{{ selectedProject.synopsis || t('未提供') }}</p>
            <span>{{ t('创作要求') }}</span><p>{{ selectedProject.requirements || t('未提供') }}</p>
          </section>

          <section v-if="selectedProject.latest_score" class="runtime-section score-section">
            <div class="score-heading">
              <h3>{{ t('Hermes 评分') }}</h3>
              <strong>{{ selectedProject.latest_score.overall_score.toFixed(1) }}</strong>
            </div>
            <p class="score-meta">{{ selectedProject.latest_score.scorer_name }} · {{ selectedProject.latest_score.score_version }} · {{ selectedProject.latest_score.update_time || '--' }}</p>
            <div v-if="Object.keys(selectedProject.latest_score.dimensions).length" class="score-dimensions">
              <div v-for="(value, name) in selectedProject.latest_score.dimensions" :key="name"><span>{{ name }}</span><strong>{{ scoreValue(value) }}</strong></div>
            </div>
            <p v-if="selectedProject.latest_score.summary" class="score-summary">{{ selectedProject.latest_score.summary }}</p>
            <div v-if="selectedProject.latest_score.strengths || selectedProject.latest_score.weaknesses || selectedProject.latest_score.suggestions" class="score-feedback">
              <div v-if="selectedProject.latest_score.strengths"><span>{{ t('优点') }}</span><p>{{ selectedProject.latest_score.strengths }}</p></div>
              <div v-if="selectedProject.latest_score.weaknesses"><span>{{ t('不足') }}</span><p>{{ selectedProject.latest_score.weaknesses }}</p></div>
              <div v-if="selectedProject.latest_score.suggestions"><span>{{ t('修改建议') }}</span><p>{{ selectedProject.latest_score.suggestions }}</p></div>
            </div>
          </section>

          <section v-else-if="selectedProject.status === 'completed'" class="runtime-section score-section is-empty">
            <h3>{{ t('Hermes 评分') }}</h3>
            <p>{{ t('剧本已可供外部 Agent 获取，当前尚未收到评分。') }}</p>
          </section>

          <section v-if="selectedProject.error_message" class="runtime-section error-section">
            <h3>{{ t('失败原因') }}</h3><p>{{ selectedProject.error_message }}</p>
          </section>
        </aside>
      </div>
    </template>

    <el-dialog v-model="createVisible" :title="t('新建剧本项目')" width="min(720px, calc(100vw - 32px))" class="script-create-dialog" destroy-on-close>
      <el-form label-position="top" class="dialog-form">
        <div class="form-grid">
          <el-form-item :label="t('剧本项目名称')">
            <el-input v-model="createForm.title" :placeholder="t('例如：雾港来信')" maxlength="180" show-word-limit />
            <small class="form-help">{{ t('用于在项目列表中识别作品，建议填写明确、唯一的名称。') }}</small>
          </el-form-item>
          <el-form-item :label="t('题材')">
            <el-input v-model="createForm.genre" :placeholder="t('例如：都市悬疑、古装爱情、科幻喜剧')" maxlength="80" />
            <small class="form-help">{{ t('填写作品类型或类型组合，帮助 AI 确定叙事规律和受众预期。') }}</small>
          </el-form-item>
        </div>
        <div class="form-grid">
          <el-form-item :label="t('输出语言')">
            <el-input :model-value="t('简体中文')" readonly />
            <small class="form-help">{{ t('剧本内容固定使用简体中文。') }}</small>
          </el-form-item>
          <el-form-item :label="t('地区风格')">
            <el-select v-model="createForm.region_style">
              <el-option :label="t('中国大陆')" value="mainland" />
              <el-option :label="t('海外')" value="overseas" />
            </el-select>
            <small class="form-help">{{ t('控制人物命名、文化语境、场景与叙事习惯，不改变输出语言。') }}</small>
          </el-form-item>
        </div>
        <el-form-item :label="t('故事梗概')">
          <el-input v-model="createForm.synopsis" :placeholder="t('例如：失忆记者回到雾港调查旧案，却发现失踪者与自己的过去有关。')" type="textarea" :rows="4" />
          <small class="form-help">{{ t('说明主角是谁、想达成什么、遇到什么阻碍，以及希望故事走向何种结局。') }}</small>
        </el-form-item>
        <el-form-item :label="t('创作要求')">
          <el-input v-model="createForm.requirements" :placeholder="t('例如：12 集竖屏短剧，每集约 2 分钟；节奏紧凑，前三集完成核心悬念建立；避免血腥画面。')" type="textarea" :rows="4" />
          <small class="form-help">{{ t('可填写集数与时长、目标受众、风格、叙事视角、尺度限制，以及必须保留的角色或情节。') }}</small>
        </el-form-item>
        <el-alert type="info" :closable="false" :title="t('创建后由 AI 自动生成全部阶段内容，用户无需选择写手或审核负责人。')" />
      </el-form>
      <template #footer><el-button @click="createVisible = false">{{ t('取消') }}</el-button><el-button type="primary" :loading="saving" @click="handleCreate">{{ t('创建') }}</el-button></template>
    </el-dialog>

    <el-dialog v-model="configVisible" :title="t('AI 配置')" width="min(1120px, 94vw)" class="ai-config-dialog" destroy-on-close>
      <div class="config-scroll">
        <section v-if="defaultConfig" class="default-config config-card">
          <div class="config-card__heading">
            <div><span class="section-kicker">DEFAULT</span><h3>{{ t(defaultConfig.name) }}</h3><p>{{ t(defaultConfig.description) }}</p></div>
            <StatusBadge tone="system">{{ t('全局兜底') }}</StatusBadge>
          </div>
          <div class="config-fields">
            <el-form-item :label="t('默认文本模型')">
              <el-select v-model="defaultConfig.model_config_id" filterable><el-option :label="t('使用账号默认文本模型')" :value="0" /><el-option v-for="model in textModels" :key="model.id" :label="modelLabel(model)" :value="model.id" /></el-select>
            </el-form-item>
            <el-form-item :label="t('温度')"><el-input-number v-model="defaultConfig.temperature" :min="0" :max="2" :step="0.1" /></el-form-item>
            <el-form-item :label="t('最大输出 Token')"><el-input-number v-model="defaultConfig.max_tokens" :min="512" :max="65536" :step="512" /></el-form-item>
          </div>
          <el-form-item :label="t('默认系统提示词')"><el-input v-model="defaultConfig.system_prompt" type="textarea" :rows="5" /></el-form-item>
          <el-form-item :label="t('默认任务模板')"><el-input v-model="defaultConfig.task_prompt" type="textarea" :rows="7" /></el-form-item>
          <p class="placeholder-help">{{ promptVariableHint }}</p>
        </section>

        <div class="agent-config-grid">
          <section v-for="config in agentConfigs" :key="config.config_key" class="config-card agent-config" :class="{ 'is-disabled': !config.enabled }">
            <div class="config-card__heading">
              <div><span class="section-kicker">{{ config.config_key.toUpperCase() }}</span><h3>{{ t(config.name) }}</h3><p>{{ t(config.description) }}</p></div>
              <el-switch v-model="config.enabled" :active-value="1" :inactive-value="0" :active-text="t('启用')" :inactive-text="t('停用')" />
            </div>
            <el-form-item :label="t('角色文本模型')">
              <el-select v-model="config.model_config_id" filterable><el-option :label="t('继承默认配置')" :value="0" /><el-option v-for="model in textModels" :key="model.id" :label="modelLabel(model)" :value="model.id" /></el-select>
            </el-form-item>
            <div class="override-row">
              <span>{{ t('独立模型参数') }}</span>
              <el-switch :model-value="config.temperature !== null || config.max_tokens !== null" @change="(value) => toggleParameterOverride(config, value)" />
            </div>
            <div v-if="config.temperature !== null || config.max_tokens !== null" class="config-fields is-two">
              <el-form-item :label="t('温度')"><el-input-number v-model="config.temperature" :min="0" :max="2" :step="0.1" /></el-form-item>
              <el-form-item :label="t('最大输出 Token')"><el-input-number v-model="config.max_tokens" :min="512" :max="65536" :step="512" /></el-form-item>
            </div>
            <el-form-item :label="t('角色系统提示词')"><el-input v-model="config.system_prompt" type="textarea" :rows="5" /></el-form-item>
            <el-form-item :label="t('角色任务提示词')"><el-input v-model="config.task_prompt" type="textarea" :rows="6" /></el-form-item>
          </section>
        </div>
      </div>
      <template #footer><el-button @click="configVisible = false">{{ t('取消') }}</el-button><el-button type="primary" :loading="saving" @click="handleConfigSave">{{ t('保存 AI 配置') }}</el-button></template>
    </el-dialog>

    <el-dialog v-model="externalAccessVisible" :title="t('Hermes 评分接入')" width="min(760px, calc(100vw - 32px))" destroy-on-close>
      <div v-loading="externalAccessLoading" class="external-access">
        <el-alert
          type="success"
          :closable="false"
          :title="externalAccessHeadline"
          :description="externalAccessDescription"
        />

        <section v-if="externalAccess" class="external-access__section">
          <h3>{{ t('可用鉴权方式') }}</h3>
          <div class="auth-method-list">
            <div><span>{{ t('首页 Agent 令牌') }}</span><strong>{{ t('可直接使用') }}</strong></div>
            <div><span>{{ t('专用评分令牌') }}</span><strong>{{ dedicatedTokenStatus }}</strong></div>
          </div>
        </section>

        <section v-if="externalAccess?.configured" class="external-access__section">
          <dl class="external-access__meta">
            <div><dt>{{ t('专用令牌客户端') }}</dt><dd>{{ externalAccess.client_name }}</dd></div>
            <div><dt>{{ t('专用令牌前缀') }}</dt><dd>{{ externalAccess.token_prefix || '--' }}</dd></div>
            <div><dt>{{ t('专用令牌最后调用') }}</dt><dd>{{ externalAccess.last_used_at || '--' }}</dd></div>
          </dl>
        </section>

        <section v-if="revealedExternalToken" class="external-access__section token-reveal">
          <el-alert type="warning" :closable="false" :title="t('完整专用令牌只显示这一次，请立即交给 Hermes Agent 保存。')" />
          <el-input :model-value="revealedExternalToken" readonly>
            <template #append><el-button @click="copyTextToClipboard(revealedExternalToken)">{{ t('复制') }}</el-button></template>
          </el-input>
        </section>

        <section v-if="externalAccess" class="external-access__section">
          <h3>{{ t('请求地址') }}</h3>
          <div class="endpoint-list">
            <div v-for="(path, action) in externalAccess.endpoints" :key="action">
              <span>{{ action.toUpperCase() }}</span>
              <code>{{ externalEndpoint(path) }}</code>
              <el-button link type="primary" @click="copyTextToClipboard(externalEndpoint(path))">{{ t('复制') }}</el-button>
            </div>
          </div>
          <p class="external-access__hint">Authorization: Bearer &lt;token&gt; · Content-Type: application/json</p>
        </section>
      </div>
      <template #footer>
        <el-button v-if="externalAccess?.active" type="danger" plain :loading="externalAccessLoading" @click="handleRevokeExternalAccess">{{ t('撤销专用令牌') }}</el-button>
        <el-button type="primary" :loading="externalAccessLoading" @click="handleRotateExternalAccess">{{ externalAccess?.configured ? t('重置专用令牌') : t('生成专用令牌') }}</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.script-creation { height: 100%; display: flex; flex-direction: column; background: var(--canvas); }
.script-list { flex: 1; min-height: 0; padding: var(--space-xl); }
.panel-surface { min-width: 0; min-height: 0; border: 1px solid var(--hairline); border-radius: var(--radius-md); background: var(--surface-card); overflow: hidden; }
.records-table { border: 1px solid var(--hairline); border-radius: var(--radius-md); }
.records-table :deep(.el-table__empty-text) { width: 100%; max-width: none; }
.records-table :deep(.studio-empty) { width: 100%; box-sizing: border-box; }
.table-score { color: var(--accent-cyan); font-size: 15px; }
.flow-intro { margin-bottom: var(--space-md); padding: var(--space-md) var(--space-lg); display: flex; align-items: center; gap: var(--space-md); }
.flow-intro__icon { width: 42px; height: 42px; display: grid; place-items: center; border-radius: var(--radius-md); background: var(--surface-tint); color: var(--accent-cyan); font-size: 20px; }
.flow-intro > div:nth-child(2) { flex: 1; }
.flow-intro strong { color: var(--on-dark); }
.flow-intro p { margin: 3px 0 0; color: var(--muted); font-size: 12px; }
.toolbar-progress { color: var(--muted); font-size: 12px; font-weight: 700; }
.script-workbench { flex: 1; min-height: 0; display: grid; grid-template-columns: 270px minmax(440px, 1fr) 330px; gap: var(--space-md); padding: var(--space-md); overflow: hidden; }
.flow-panel, .runtime-panel { overflow-y: auto; }
.panel-heading { min-height: 58px; padding: 0 var(--space-md); display: flex; align-items: center; justify-content: space-between; border-bottom: 1px solid var(--hairline); }
.panel-heading > div { display: flex; flex-direction: column; }
.panel-heading strong { color: var(--on-dark); }
.panel-heading small, .panel-heading span { color: var(--muted); font-size: 11px; }
.step-list { padding: var(--space-sm) 0; }
.step-item { width: calc(100% - 24px); margin: 6px 12px; padding: 12px 10px; display: grid; grid-template-columns: 28px minmax(0, 1fr); align-items: start; gap: 6px 9px; border: 1px solid transparent; border-radius: var(--radius-md); background: transparent; color: var(--body); text-align: left; cursor: pointer; }
.step-item:hover, .step-item.is-active { border-color: rgba(var(--brand-cyan-rgb), 0.28); background: var(--surface-tint); }
.step-index { width: 28px; height: 28px; display: grid; grid-row: 1 / span 2; place-items: center; border-radius: var(--radius-full); background: var(--surface-soft); color: var(--muted); font-size: 12px; font-weight: 800; }
.step-item.is-running .step-index { background: rgba(var(--brand-cyan-rgb), 0.12); color: var(--accent-cyan); }
.step-item.is-completed .step-index { color: var(--accent-emerald); }
.step-copy { min-width: 0; display: flex; flex-direction: column; }
.step-copy strong { color: var(--on-dark); font-size: 13px; overflow-wrap: anywhere; }
.step-copy small { color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.step-item :deep(.status-badge) { grid-column: 2; justify-self: start; max-width: 100%; }
.flow-note { margin: var(--space-md); padding-top: var(--space-md); border-top: 1px solid var(--hairline); }
.flow-note span { color: var(--muted); font-size: 11px; }
.flow-note p { margin: 5px 0 0; color: var(--body); font-size: 12px; line-height: 1.55; }
.output-panel { display: flex; flex-direction: column; }
.output-heading { min-height: 82px; padding: var(--space-md) var(--space-lg); display: flex; align-items: center; justify-content: space-between; gap: var(--space-md); border-bottom: 1px solid var(--hairline); }
.section-kicker { color: var(--accent-cyan); font-size: 10px; font-weight: 800; }
.output-heading h2, .config-card__heading h3 { margin: 2px 0; color: var(--on-dark); }
.output-heading h2 { font-size: 18px; }
.output-heading p, .config-card__heading p { margin: 0; color: var(--muted); font-size: 12px; }
.step-metrics { display: flex; gap: var(--space-md); color: var(--muted); font-size: 11px; white-space: nowrap; }
.output-tabs { flex: 1; min-height: 0; display: flex; flex-direction: column; }
.output-tabs :deep(.el-tabs__header) { margin: 0; padding: 0 var(--space-lg); }
.output-tabs :deep(.el-tabs__content) { flex: 1; min-height: 0; overflow: auto; padding: var(--space-lg); }
.content-preview, .prompt-snapshot pre { margin: 0; color: var(--body-strong); font-family: var(--font-sans); font-size: 14px; line-height: 1.75; white-space: pre-wrap; overflow-wrap: anywhere; }
.content-preview.is-muted { color: var(--body); }
.prompt-snapshot { display: flex; flex-direction: column; gap: var(--space-sm); }
.prompt-snapshot strong { color: var(--on-dark); font-size: 12px; }
.prompt-snapshot pre { margin-bottom: var(--space-lg); padding: var(--space-md); border: 1px solid var(--hairline); border-radius: var(--radius-md); background: var(--surface-raised); font-size: 12px; }
.error-message { padding: var(--space-md); border-radius: var(--radius-md); background: rgba(225, 29, 72, 0.06); color: var(--accent-rose); white-space: pre-wrap; }
.waiting-card { margin: var(--space-md); padding: var(--space-lg); display: flex; flex-direction: column; gap: 6px; border: 1px solid rgba(var(--brand-cyan-rgb), 0.22); border-radius: var(--radius-md); background: var(--surface-tint); }
.waiting-card > span { color: var(--muted); font-size: 11px; }
.waiting-card strong { color: var(--on-dark); font-size: 16px; line-height: 1.4; }
.waiting-card p { margin: 0; color: var(--accent-amber); font-size: 12px; }
.waiting-card.is-failed { border-color: rgba(225, 29, 72, 0.2); background: rgba(225, 29, 72, 0.05); }
.waiting-card.is-completed { border-color: rgba(22, 163, 74, 0.2); background: rgba(22, 163, 74, 0.05); }
.runtime-section { padding: 0 var(--space-md) var(--space-lg); }
.runtime-section h3 { margin: var(--space-sm) 0; color: var(--on-dark); font-size: 13px; }
.runtime-section dl { margin: 0; }
.runtime-section dl > div { padding: 9px 0; display: flex; justify-content: space-between; gap: var(--space-sm); border-bottom: 1px solid var(--hairline); }
.runtime-section dt, .brief-section span { color: var(--muted); font-size: 11px; }
.runtime-section dd { margin: 0; color: var(--body); font-size: 12px; text-align: right; }
.brief-section p { margin: 4px 0 var(--space-md); color: var(--body); font-size: 12px; line-height: 1.55; white-space: pre-wrap; }
.error-section p { color: var(--accent-rose); font-size: 12px; white-space: pre-wrap; }
.score-section { margin: 0 var(--space-md) var(--space-lg); padding: var(--space-md); border: 1px solid rgba(var(--brand-cyan-rgb), 0.2); border-radius: var(--radius-md); background: var(--surface-tint); }
.score-section h3 { margin: 0; }
.score-heading { display: flex; align-items: center; justify-content: space-between; gap: var(--space-sm); }
.score-heading > strong { color: var(--accent-cyan); font-size: 26px; line-height: 1; }
.score-meta, .score-summary, .score-section.is-empty p { margin: 7px 0 0; color: var(--muted); font-size: 11px; line-height: 1.55; white-space: pre-wrap; }
.score-dimensions { margin-top: var(--space-sm); display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 6px; }
.score-dimensions > div { padding: 7px 9px; display: flex; justify-content: space-between; gap: 8px; border-radius: var(--radius-sm); background: var(--surface-card); color: var(--body); font-size: 11px; }
.score-dimensions strong { color: var(--on-dark); }
.score-feedback { margin-top: var(--space-sm); display: flex; flex-direction: column; gap: 8px; }
.score-feedback > div { padding-top: 8px; border-top: 1px solid var(--hairline); }
.score-feedback span { color: var(--muted); font-size: 10px; font-weight: 700; }
.score-feedback p { margin: 4px 0 0; color: var(--body); font-size: 11px; line-height: 1.55; white-space: pre-wrap; }
.external-access { min-height: 160px; display: flex; flex-direction: column; gap: var(--space-md); }
.external-access__section { display: flex; flex-direction: column; gap: var(--space-sm); }
.external-access__section h3 { margin: 0; color: var(--on-dark); font-size: 13px; }
.external-access__meta { margin: 0; display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: var(--space-sm); }
.external-access__meta > div { min-width: 0; padding: var(--space-sm); border: 1px solid var(--hairline); border-radius: var(--radius-md); background: var(--surface-card); }
.external-access__meta dt { color: var(--muted); font-size: 11px; }
.external-access__meta dd { margin: 4px 0 0; color: var(--body-strong); font-size: 12px; overflow-wrap: anywhere; }
.auth-method-list { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-sm); }
.auth-method-list > div { padding: var(--space-sm) var(--space-md); display: flex; align-items: center; justify-content: space-between; gap: var(--space-sm); border: 1px solid var(--hairline); border-radius: var(--radius-md); background: var(--surface-card); }
.auth-method-list span { color: var(--body); font-size: 12px; }
.auth-method-list strong { color: var(--accent-emerald); font-size: 11px; }
.endpoint-list { border: 1px solid var(--hairline); border-radius: var(--radius-md); overflow: hidden; }
.endpoint-list > div { padding: 9px 11px; display: grid; grid-template-columns: 58px minmax(0, 1fr) auto; align-items: center; gap: var(--space-sm); border-bottom: 1px solid var(--hairline); }
.endpoint-list > div:last-child { border-bottom: 0; }
.endpoint-list span { color: var(--accent-cyan); font-size: 10px; font-weight: 800; }
.endpoint-list code { min-width: 0; color: var(--body); font-size: 11px; overflow-wrap: anywhere; }
.external-access__hint { margin: 0; color: var(--muted); font-family: var(--font-mono); font-size: 11px; overflow-wrap: anywhere; }
.dialog-form :deep(.el-select), .config-card :deep(.el-select) { width: 100%; }
.form-help { width: 100%; margin-top: 6px; color: var(--muted); font-size: 11px; line-height: 1.45; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 var(--space-md); }
.config-scroll { max-width: 100%; max-height: 72vh; overflow-x: hidden; overflow-y: auto; padding-right: 4px; }
.config-card { padding: var(--space-lg); border: 1px solid var(--hairline); border-radius: var(--radius-md); background: var(--surface-card); }
.default-config { margin-bottom: var(--space-md); border-color: rgba(124, 58, 237, 0.22); background: rgba(124, 58, 237, 0.035); }
.config-card__heading { margin-bottom: var(--space-md); display: flex; align-items: flex-start; justify-content: space-between; gap: var(--space-md); }
.config-card__heading > div { min-width: 0; flex: 1; }
.config-card__heading :deep(.status-badge), .config-card__heading :deep(.el-switch) { flex: 0 0 auto; }
.config-card__heading h3 { font-size: 16px; }
.config-fields { display: grid; grid-template-columns: minmax(240px, 1fr) minmax(150px, 0.45fr) minmax(180px, 0.55fr); gap: 0 var(--space-md); }
.config-fields > *, .form-grid > *, .agent-config-grid > * { min-width: 0; }
.config-fields :deep(.el-form-item__label) { min-width: 0; height: auto; line-height: 1.35; white-space: normal; overflow-wrap: anywhere; }
.config-fields :deep(.el-input-number) { width: 100%; min-width: 0; }
.config-fields :deep(.el-input-number .el-input__wrapper) { min-width: 0; }
.config-fields.is-two { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.agent-config-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-md); }
.agent-config.is-disabled { opacity: 0.68; }
.override-row { margin-bottom: var(--space-md); display: flex; align-items: center; justify-content: space-between; color: var(--body); font-size: 12px; }
.placeholder-help { margin: -8px 0 0; color: var(--muted); font-size: 11px; }
@media (max-width: 1280px) { .script-workbench { grid-template-columns: 230px minmax(400px, 1fr) 290px; } }
@media (max-width: 1180px) { .config-fields { grid-template-columns: minmax(220px, 1fr) repeat(2, minmax(160px, 0.55fr)); } }
@media (max-width: 980px) { .script-workbench { overflow-y: auto; grid-template-columns: 1fr; } .flow-panel, .runtime-panel, .output-panel { min-height: 420px; } .agent-config-grid, .config-fields { grid-template-columns: 1fr; } }
@media (max-width: 680px) { .form-grid, .external-access__meta, .auth-method-list { grid-template-columns: 1fr; } .flow-intro { align-items: flex-start; flex-wrap: wrap; } .config-card { padding: var(--space-md); } .config-card__heading { flex-wrap: wrap; } .config-card__heading :deep(.status-badge), .config-card__heading :deep(.el-switch) { max-width: 100%; } .endpoint-list > div { grid-template-columns: 50px minmax(0, 1fr); } .endpoint-list :deep(.el-button) { grid-column: 2; justify-self: start; } }
</style>
