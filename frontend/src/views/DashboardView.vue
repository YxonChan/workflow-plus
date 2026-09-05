<script setup lang="ts">
import { computed, ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useI18n } from 'vue-i18n'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import PageSkeleton from '@/components/ui/PageSkeleton.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { listGrouped } from '@/api/modelConfig'
import { listSeries } from '@/api/series'
import { listBundles } from '@/api/workflowBundle'
import { listWorkflowRuns, type WorkflowRunBrief } from '@/api/workflowRun'
import type { Series } from '@/types'
import { useAuthStore } from '@/stores/auth'
import { resolveIcon } from '@/utils/iconRegistry'

const router = useRouter()
const auth = useAuthStore()
const { t } = useI18n()
const modelCount = ref(0)
const loading = ref(true)
const seriesList = ref<Series[]>([])
const bundleCount = ref(0)
const runs = ref<WorkflowRunBrief[]>([])
const refreshingToken = ref(false)
const copiedToken = ref(false)

onMounted(async () => {
  try {
    const [groups, series, bundles, runList] = await Promise.all([
      listGrouped().catch(() => []),
      listSeries().catch(() => []),
      listBundles().catch(() => []),
      listWorkflowRuns(6).catch(() => []),
    ])
    modelCount.value = groups.reduce((sum, g) => sum + g.models.length, 0)
    seriesList.value = series
    bundleCount.value = bundles.length
    runs.value = runList
  } finally {
    loading.value = false
  }
})

interface SeriesRow {
  id: number
  title: string
  episodes: number
  done: number
  status: '解析中' | '制作中' | '已完成' | '计划中' | '有失败'
}

const recentSeries = computed<SeriesRow[]>(() =>
  seriesList.value.slice(0, 5).map((s) => {
    const episodes = s.episodes?.length ?? 0
    const done = (s.episodes ?? []).filter((e) => e.status === 'done').length
    const runStatus = s.active_workflow_run?.status
    let status: SeriesRow['status'] = '计划中'
    if (runStatus === 'queued' || runStatus === 'running') status = '解析中'
    else if (runStatus === 'failed') status = '有失败'
    else if (episodes > 0 && done >= episodes) status = '已完成'
    else if (done > 0) status = '制作中'
    return { id: s.id, title: s.title, episodes, done, status }
  }),
)

function seriesTone(status: SeriesRow['status']) {
  if (status === '已完成') return 'done'
  if (status === '解析中' || status === '制作中') return 'busy'
  if (status === '有失败') return 'fail'
  return 'warning'
}

const runStatusText: Record<string, string> = {
  queued: '排队中',
  running: '运行中',
  success: '已完成',
  failed: '失败',
  cancelled: '已取消',
  skipped: '跳过',
}

function runStatusLabel(status: string) {
  const key = runStatusText[status] ?? (status || '未知状态')
  return t(key)
}

function runTone(status: string) {
  if (status === 'success') return 'done'
  if (status === 'failed') return 'fail'
  if (status === 'cancelled' || status === 'skipped') return 'info'
  return 'busy'
}

function runSubtitle(run: WorkflowRunBrief) {
  if (run.status === 'failed' && run.error_message) return run.error_message
  if ((run.status === 'running' || run.status === 'queued') && run.current_node_label) {
    return t('当前节点：{node} · {progress}%', { node: run.current_node_label, progress: run.progress })
  }
  return t('运行于 {time}', { time: relativeTime(run.create_time) })
}

function relativeTime(value?: string | null) {
  if (!value) return t('未知时间')
  const time = new Date(value.replace(' ', 'T')).getTime()
  if (Number.isNaN(time)) return value
  const diff = Date.now() - time
  const minutes = Math.floor(diff / 60_000)
  if (minutes < 1) return t('刚刚')
  if (minutes < 60) return t('{count} 分钟前', { count: minutes })
  const hours = Math.floor(minutes / 60)
  if (hours < 24) return t('{count} 小时前', { count: hours })
  const days = Math.floor(hours / 24)
  if (days < 30) return t('{count} 天前', { count: days })
  return value.slice(0, 10)
}

const totalEpisodes = computed(() => seriesList.value.reduce((sum, s) => sum + (s.episodes?.length ?? 0), 0))
const activeSeriesMetric = computed(() => (loading.value ? '--' : String(seriesList.value.length).padStart(2, '0')))
const bundleMetric = computed(() => (loading.value ? '--' : String(bundleCount.value).padStart(2, '0')))
const episodeMetric = computed(() => (loading.value ? '--' : String(totalEpisodes.value).padStart(2, '0')))
const modelMetric = computed(() => (loading.value ? '--' : String(modelCount.value).padStart(2, '0')))

function goSeriesNew() {
  router.push({ name: 'series' })
}

async function copyToken() {
  if (!auth.token) return
  await navigator.clipboard.writeText(auth.token)
  copiedToken.value = true
  ElMessage.success(t('JWT 已复制到剪贴板'))
  window.setTimeout(() => {
    copiedToken.value = false
  }, 1600)
}

async function refreshJwt() {
  refreshingToken.value = true
  try {
    await auth.refreshSession()
    ElMessage.success(t('Token 已刷新'))
  } finally {
    refreshingToken.value = false
  }
}
</script>

<template>
  <div class="dashboard">
    <PageToolbar
      kicker="WORKSPACE"
      :title="t('工作空间')"
      :subtitle="t('集中管理创作任务、自动化流程、模型能力与团队执行状态。')"
    >
      <template #actions>
        <el-button @click="$router.push({ name: 'workflow' })">
          <el-icon><Share /></el-icon><span>{{ t('流程库') }}</span>
        </el-button>
        <el-button type="primary" @click="goSeriesNew">
          <el-icon><Plus /></el-icon><span>{{ t('新建作品') }}</span>
        </el-button>
      </template>
    </PageToolbar>

    <div class="dashboard__body scrollable">
      <PageSkeleton v-if="loading" variant="dashboard" />
      <template v-else>
      <section class="studio-grid studio-grid--metrics">
        <div class="studio-metric">
          <span class="studio-metric__label">{{ t('作品总数') }}</span>
          <span class="studio-metric__value">{{ activeSeriesMetric }}</span>
          <span class="studio-metric__hint">Active workspaces</span>
        </div>
        <div class="studio-metric">
          <span class="studio-metric__label">{{ t('流程模板') }}</span>
          <span class="studio-metric__value">{{ bundleMetric }}</span>
          <span class="studio-metric__hint">Automation templates</span>
        </div>
        <div class="studio-metric">
          <span class="studio-metric__label">{{ t('模型引擎') }}</span>
          <span class="studio-metric__value">{{ modelMetric }}</span>
          <span class="studio-metric__hint">Connected AI models</span>
        </div>
        <div class="studio-metric">
          <span class="studio-metric__label">{{ t('累计剧集') }}</span>
          <span class="studio-metric__value">{{ episodeMetric }}</span>
          <span class="studio-metric__hint">Tasks in delivery</span>
        </div>
      </section>

      <section class="dashboard__workspace">
        <div class="dashboard__lists">
          <section class="studio-card dashboard-panel">
            <div class="studio-panel-header">
              <div class="studio-panel-title">
                <el-icon><Film /></el-icon><span>{{ t('最近作品') }}</span>
              </div>
              <el-button text @click="$router.push({ name: 'series' })">{{ t('查看全部') }}</el-button>
            </div>
            <div v-if="recentSeries.length" class="panel-list">
              <button v-for="s in recentSeries" :key="s.id" type="button" class="series-row" @click="$router.push({ name: 'series' })">
                <div class="series-row__cover">
                  <el-icon><VideoCamera /></el-icon>
                </div>
                <div class="series-row__main">
                  <strong>{{ s.title }}</strong>
                  <span>{{ t('{done}/{total} 集已完成', { done: s.done, total: s.episodes }) }}</span>
                  <el-progress :percentage="s.episodes ? Math.round((s.done / s.episodes) * 100) : 0" :show-text="false" />
                </div>
                <StatusBadge :tone="seriesTone(s.status)" :pulse="s.status === '解析中'">
                  {{ t(s.status) }}
                </StatusBadge>
              </button>
            </div>
            <EmptyState v-else compact :title="t('还没有作品')" :hint="t('创建作品后，生产进度会出现在这里。')">
              <el-button type="primary" @click="goSeriesNew">
                <el-icon><Plus /></el-icon><span>{{ t('新建作品') }}</span>
              </el-button>
            </EmptyState>
          </section>

          <section class="studio-card dashboard-panel">
            <div class="studio-panel-header">
              <div class="studio-panel-title">
                <el-icon><Connection /></el-icon><span>{{ t('最近任务') }}</span>
              </div>
              <el-button v-if="auth.user?.role === 'admin'" text @click="$router.push({ name: 'logs' })">{{ t('查看日志') }}</el-button>
            </div>
            <div v-if="runs.length" class="panel-list">
              <button v-for="r in runs" :key="r.id" type="button" class="workflow-row" @click="$router.push({ name: 'series' })">
                <div class="workflow-row__icon">
                  <el-icon><Share /></el-icon>
                </div>
                <div class="workflow-row__main">
                  <strong>{{ r.series_title || t('任务 #{id}', { id: r.id }) }}</strong>
                  <span>{{ runSubtitle(r) }}</span>
                </div>
                <StatusBadge :tone="runTone(r.status)" :pulse="r.status === 'running'">{{ runStatusLabel(r.status) }}</StatusBadge>
              </button>
            </div>
            <EmptyState v-else compact icon="Connection" :title="t('暂无执行记录')" :hint="t('运行剧本解析或剧集流程后，最近的任务会出现在这里。')">
              <el-button type="primary" plain @click="goSeriesNew">
                <el-icon><VideoPlay /></el-icon><span>{{ t('开始一次生产') }}</span>
              </el-button>
            </EmptyState>
          </section>
        </div>

        <aside class="dashboard__side">
          <section class="next-action">
            <div class="next-action__copy">
              <span>{{ t('下一步生产') }}</span>
              <strong>{{ t('开始下一部作品') }}</strong>
              <p>{{ t('从流程模板开始，把小说、剧本或一句话输入推进到批量短视频。') }}</p>
            </div>
            <el-button type="primary" @click="goSeriesNew">
              <el-icon><VideoPlay /></el-icon><span>{{ t('进入作品生产') }}</span>
            </el-button>
          </section>

          <section class="studio-card agent-card">
            <div class="agent-card__head">
              <div class="studio-panel-title">
                <el-icon><Key /></el-icon><span>{{ t('Agent 接入') }}</span>
              </div>
              <StatusBadge :tone="auth.token ? 'done' : 'fail'">{{ auth.token ? t('已接入') : t('未登录') }}</StatusBadge>
            </div>
            <div class="agent-card__body">
              <span>{{ t('给本地 Agent 使用的当前登录凭证。') }}</span>
              <strong>{{ t('过期时间：{time}', { time: auth.tokenExpiresAtText }) }}</strong>
            </div>
            <div class="agent-card__actions">
              <el-button :disabled="!auth.token" :icon="resolveIcon(copiedToken ? 'Check' : 'CopyDocument')" @click="copyToken">
                <span>{{ t('复制 Token') }}</span>
              </el-button>
              <el-button :loading="refreshingToken" :icon="resolveIcon('Refresh')" @click="refreshJwt">
                <span>{{ t('刷新') }}</span>
              </el-button>
            </div>
          </section>

          <section class="studio-card quick-links">
            <div class="studio-panel-title">
              <el-icon><Share /></el-icon><span>{{ t('快捷入口') }}</span>
            </div>
            <div class="quick-links__list">
              <button type="button" @click="$router.push({ name: 'workflow' })">
                <span>{{ t('流程库') }}</span>
                <el-icon><ArrowRight /></el-icon>
              </button>
              <button v-if="auth.user?.role === 'admin'" type="button" @click="$router.push({ name: 'models' })">
                <span>{{ t('模型配置') }}</span>
                <el-icon><ArrowRight /></el-icon>
              </button>
              <button type="button" @click="$router.push({ name: 'assets' })">
                <span>{{ t('资产管理') }}</span>
                <el-icon><ArrowRight /></el-icon>
              </button>
            </div>
          </section>
        </aside>
      </section>
      </template>
    </div>
  </div>
</template>

<style scoped lang="scss">
.dashboard {
  height: 100%;
  display: flex;
  flex-direction: column;
  background: var(--canvas);
  overflow: hidden;

  // 首页统一主色：indigo primary，不再混 cyan / 局部蓝块
  :deep(.page-toolbar__kicker) {
    color: var(--primary);
  }

  :deep(.studio-panel-title .el-icon) {
    color: var(--primary);
  }

  :deep(.el-progress-bar__inner) {
    background-color: var(--primary) !important;
  }
}

.dashboard__body {
  flex: 1;
  min-height: 0;
  padding: var(--space-lg) var(--space-xl);
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}

.dashboard__body .studio-metric {
  min-height: 94px;
  padding: 14px var(--space-md);
  border-top: 3px solid rgba(var(--primary-rgb), 0.72);
  background: var(--surface-card);
}

.dashboard__body .studio-metric__value {
  font-size: 26px;
  color: var(--on-dark);
}

.dashboard__body .studio-metric__hint {
  margin-top: 4px;
}

.dashboard__workspace {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 320px;
  gap: var(--space-lg);
  align-items: start;
}

.dashboard__lists {
  min-width: 0;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-lg);
}

.dashboard-panel {
  min-height: 254px;
  overflow: hidden;
}

.panel-list {
  padding: var(--space-sm);
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
}

.dashboard-panel :deep(.studio-empty) {
  min-height: 190px;
  margin: var(--space-sm);
}

.series-row,
.workflow-row {
  width: 100%;
  display: flex;
  align-items: center;
  gap: var(--space-md);
  padding: 10px var(--space-sm);
  border: 1px solid transparent;
  border-radius: var(--radius-md);
  background: transparent;
  cursor: pointer;
  text-align: left;
  transition: all var(--duration-fast);

  &:hover {
    border-color: rgba(var(--primary-rgb), 0.28);
    background: rgba(var(--primary-rgb), 0.08);
  }
}

.series-row__cover,
.workflow-row__icon {
  width: 46px;
  height: 46px;
  display: grid;
  place-items: center;
  border: 1px solid rgba(var(--primary-rgb), 0.22);
  border-radius: var(--radius-md);
  background: rgba(var(--primary-rgb), 0.12);
  color: var(--primary);
  flex-shrink: 0;
}

.series-row__main,
.workflow-row__main {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 4px;

  strong {
    color: var(--on-dark);
    font-size: 13px;
    font-weight: 800;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  span {
    color: var(--muted);
    font-size: 12px;
  }
}

.dashboard__side {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}

.next-action {
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: var(--space-lg);
  padding: var(--space-lg);
  border: 1px solid rgba(var(--primary-rgb), 0.28);
  border-radius: var(--radius-md);
  background:
    linear-gradient(145deg, rgba(var(--primary-rgb), 0.18), rgba(var(--primary-rgb), 0.06)),
    var(--surface-card);
  box-shadow: 0 12px 28px rgba(7, 11, 20, 0.28);
  color: var(--body);
}

.next-action__copy {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;

  span {
    color: var(--primary);
    font-size: 11px;
    font-weight: 850;
  }

  strong {
    color: var(--on-dark);
    font-size: 18px;
    font-weight: 850;
    line-height: 1.25;
  }

  p {
    margin: 0;
    color: var(--muted);
    font-size: 13px;
    line-height: 1.5;
  }
}

.next-action .el-button {
  width: 100%;
  margin-left: 0;
  font-weight: 850;
}

.agent-card,
.quick-links {
  padding: var(--space-md);
}

.agent-card__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-sm);
}

.agent-card__body {
  margin-top: var(--space-sm);
  display: grid;
  gap: 4px;

  span {
    color: var(--muted);
    font-size: 12px;
    line-height: 1.45;
  }

  strong {
    color: var(--body-strong);
    font-size: 12px;
    font-weight: 800;
  }
}

.agent-card__actions {
  margin-top: var(--space-md);
  display: grid;
  grid-template-columns: minmax(0, 1fr) 92px;
  gap: var(--space-xs);

  :deep(.el-button) {
    width: 100%;
    margin-left: 0;
    border-radius: var(--radius-md);
    font-weight: 800;
  }
}

.quick-links {
  display: grid;
  gap: var(--space-sm);
}

.quick-links__list {
  display: grid;
  gap: var(--space-xs);

  button {
    width: 100%;
    min-height: 38px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: var(--space-sm);
    padding: 0 var(--space-sm);
    border: 1px solid var(--hairline);
    border-radius: var(--radius-md);
    background: var(--surface-raised);
    color: var(--body-strong);
    cursor: pointer;
    font-size: 12px;
    font-weight: 800;
    text-align: left;
    transition: all var(--duration-fast);

    &:hover {
      border-color: rgba(var(--primary-rgb), 0.36);
      background: rgba(var(--primary-rgb), 0.1);
      color: var(--on-dark);

      .el-icon {
        color: var(--primary);
      }
    }

    .el-icon {
      color: var(--primary);
      flex-shrink: 0;
    }
  }
}

@media (max-width: 1180px) {
  .dashboard__workspace {
    grid-template-columns: 1fr;
  }

  .dashboard__side {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    align-items: stretch;
  }

  .next-action,
  .agent-card,
  .quick-links {
    min-height: 100%;
  }
}

@media (max-width: 980px) {
  .dashboard__lists,
  .dashboard__side {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 720px) {
  .dashboard__body {
    padding: var(--space-md);
  }

  .studio-grid--metrics {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }

  .series-row,
  .workflow-row {
    align-items: flex-start;
  }

  .series-row__cover,
  .workflow-row__icon {
    width: 40px;
    height: 40px;
  }
}

@media (max-width: 520px) {
  .studio-grid--metrics {
    grid-template-columns: 1fr;
  }

  .agent-card__actions {
    grid-template-columns: 1fr;
  }
}
</style>
