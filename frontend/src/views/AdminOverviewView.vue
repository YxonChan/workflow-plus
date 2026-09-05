<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import {
  getAdminProductionOverview,
  type AdminProductionOverview,
  type AdminWorkflowRunStatus,
} from '@/api/adminProduction'

const loading = ref(false)
const overview = ref<AdminProductionOverview | null>(null)
const { t } = useI18n()

const metrics = computed(() => overview.value?.metrics ?? {
  users: 0,
  series: 0,
  episodes: 0,
  runs: 0,
  queued_runs: 0,
  running_runs: 0,
  failed_runs: 0,
  today_runs: 0,
})

const runStatusText: Record<AdminWorkflowRunStatus, string> = {
  queued: '排队中',
  running: '运行中',
  success: '已完成',
  failed: '失败',
  cancelled: '已取消',
}

function runStatusLabel(status: AdminWorkflowRunStatus) {
  return t(runStatusText[status] || status)
}

function runTone(status: AdminWorkflowRunStatus | '') {
  if (status === 'success') return 'done'
  if (status === 'failed') return 'fail'
  if (status === 'cancelled') return 'info'
  return 'busy'
}

function formatNumber(value: number) {
  return value.toLocaleString()
}

async function load() {
  loading.value = true
  try {
    overview.value = await getAdminProductionOverview()
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="admin-overview">
    <PageToolbar kicker="ADMIN OPS" :title="t('运营总览')" :subtitle="t('查看全站作品、剧集和流程任务状态。')">
      <template #actions>
        <el-button :loading="loading" @click="load">
          <el-icon><Refresh /></el-icon><span>{{ t('刷新') }}</span>
        </el-button>
      </template>
    </PageToolbar>

    <div class="admin-overview__body scrollable">
      <section class="metrics-grid">
        <div class="metric-tile">
          <span>{{ t('用户数') }}</span>
          <strong>{{ formatNumber(metrics.users) }}</strong>
        </div>
        <div class="metric-tile">
          <span>{{ t('剧本数') }}</span>
          <strong>{{ formatNumber(metrics.series) }}</strong>
        </div>
        <div class="metric-tile">
          <span>{{ t('剧集数') }}</span>
          <strong>{{ formatNumber(metrics.episodes) }}</strong>
        </div>
        <div class="metric-tile is-accent">
          <span>{{ t('今日任务') }}</span>
          <strong>{{ formatNumber(metrics.today_runs) }}</strong>
        </div>
        <div class="metric-tile">
          <span>{{ t('排队任务') }}</span>
          <strong>{{ formatNumber(metrics.queued_runs) }}</strong>
        </div>
        <div class="metric-tile">
          <span>{{ t('运行任务') }}</span>
          <strong>{{ formatNumber(metrics.running_runs) }}</strong>
        </div>
        <div class="metric-tile">
          <span>{{ t('失败任务') }}</span>
          <strong>{{ formatNumber(metrics.failed_runs) }}</strong>
        </div>
        <div class="metric-tile">
          <span>{{ t('任务总数') }}</span>
          <strong>{{ formatNumber(metrics.runs) }}</strong>
        </div>
      </section>

      <div class="overview-grid">
        <section class="ops-panel">
          <div class="ops-panel__head">
            <strong>{{ t('最近创建的剧本') }}</strong>
            <el-button text @click="$router.push({ name: 'adminSeries' })">{{ t('查看全部') }}</el-button>
          </div>
          <el-table
            v-if="overview?.recent_series.length"
            v-loading="loading"
            :data="overview.recent_series"
            row-key="id"
            class="ops-table"
          >
            <el-table-column prop="title" :label="t('剧本')" min-width="180" show-overflow-tooltip />
            <el-table-column prop="owner_name" :label="t('用户')" width="140" show-overflow-tooltip />
            <el-table-column :label="t('剧集')" width="90">
              <template #default="{ row }">{{ row.done_episode_count }}/{{ row.episode_count }}</template>
            </el-table-column>
            <el-table-column :label="t('最近任务')" width="120">
              <template #default="{ row }">
                <StatusBadge v-if="row.latest_run" :tone="runTone(row.latest_run.status)">
                  {{ runStatusLabel(row.latest_run.status) }}
                </StatusBadge>
                <span v-else class="muted">{{ t('无任务') }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="create_time" :label="t('创建时间')" width="170" />
          </el-table>
          <EmptyState v-else :title="t('暂无剧本记录')" :hint="t('用户创建作品后会出现在这里。')" />
        </section>

        <section class="ops-panel">
          <div class="ops-panel__head">
            <strong>{{ t('最近流程任务') }}</strong>
            <el-button text @click="$router.push({ name: 'adminRuns' })">{{ t('查看全部') }}</el-button>
          </div>
          <el-table
            v-if="overview?.recent_runs.length"
            v-loading="loading"
            :data="overview.recent_runs"
            row-key="id"
            class="ops-table"
          >
            <el-table-column prop="id" label="ID" width="80" />
            <el-table-column prop="series_title" :label="t('剧本')" min-width="160" show-overflow-tooltip />
            <el-table-column prop="owner_name" :label="t('用户')" width="130" show-overflow-tooltip />
            <el-table-column :label="t('状态')" width="110">
              <template #default="{ row }">
                <StatusBadge :tone="runTone(row.status)" :pulse="row.status === 'running'">
                  {{ runStatusLabel(row.status) }}
                </StatusBadge>
              </template>
            </el-table-column>
            <el-table-column prop="progress" :label="t('进度')" width="110">
              <template #default="{ row }">{{ row.progress }}%</template>
            </el-table-column>
            <el-table-column prop="create_time" :label="t('创建时间')" width="170" />
          </el-table>
          <EmptyState v-else :title="t('暂无任务记录')" :hint="t('用户运行流程后会出现在这里。')" />
        </section>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.admin-overview {
  height: 100%;
  display: flex;
  flex-direction: column;
  background: var(--canvas);
}

.admin-overview__body {
  flex: 1;
  min-height: 0;
  padding: var(--space-xl);
  display: flex;
  flex-direction: column;
  gap: var(--space-lg);
}

.metrics-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: var(--space-md);
}

.metric-tile {
  min-height: 88px;
  padding: var(--space-lg);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-card);
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 6px;

  span {
    color: var(--muted);
    font-size: 12px;
    font-weight: 700;
  }

  strong {
    color: var(--on-dark);
    font-size: 28px;
    font-weight: 850;
    letter-spacing: 0;
  }

  &.is-accent {
    border-color: rgba(var(--brand-cyan-rgb), 0.28);
    background: var(--surface-tint);
  }
}

.overview-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  gap: var(--space-lg);
}

.ops-panel {
  min-height: 360px;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-card);
  overflow: hidden;
}

.ops-panel__head {
  min-height: 54px;
  padding: 0 var(--space-md);
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid var(--hairline);

  strong {
    color: var(--on-dark);
    font-size: 14px;
    font-weight: 800;
  }
}

.ops-table {
  border: none;
}

.muted {
  color: var(--muted);
  font-size: 12px;
}

@media (max-width: 1100px) {
  .metrics-grid,
  .overview-grid {
    grid-template-columns: 1fr 1fr;
  }
}

@media (max-width: 760px) {
  .metrics-grid,
  .overview-grid {
    grid-template-columns: 1fr;
  }
}
</style>
