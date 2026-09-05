<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { listAdminSeries, type AdminSeriesRow, type AdminWorkflowRunStatus } from '@/api/adminProduction'
import { listAdminUsers } from '@/api/adminUser'
import type { AdminUser } from '@/types'

const loading = ref(false)
const rows = ref<AdminSeriesRow[]>([])
const users = ref<AdminUser[]>([])
const total = ref(0)
const page = ref(1)
const limit = ref(20)
const keyword = ref('')
const userId = ref<number | null>(null)
const { t } = useI18n()

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

function seriesStatus(row: AdminSeriesRow) {
  if (row.latest_run?.status === 'failed') return { text: t('有失败'), tone: 'fail' as const }
  if (row.latest_run?.status === 'queued' || row.latest_run?.status === 'running') return { text: t('处理中'), tone: 'busy' as const }
  if (row.episode_count > 0 && row.done_episode_count >= row.episode_count) return { text: t('已完成'), tone: 'done' as const }
  if (row.production_episode_count > 0 || row.done_episode_count > 0) return { text: t('制作中'), tone: 'warning' as const }
  return { text: t('计划中'), tone: 'info' as const }
}

async function load() {
  loading.value = true
  try {
    const data = await listAdminSeries({
      page: page.value,
      limit: limit.value,
      user_id: userId.value || undefined,
      keyword: keyword.value.trim() || undefined,
    })
    rows.value = data.list
    total.value = data.total
  } finally {
    loading.value = false
  }
}

function resetPageAndLoad() {
  page.value = 1
  void load()
}

onMounted(async () => {
  users.value = (await listAdminUsers()).list
  await load()
})
</script>

<template>
  <div class="admin-series">
    <PageToolbar kicker="SERIES RECORDS" :title="t('剧本记录')" :subtitle="t('只读查看所有用户创建的作品和剧集进度。')">
      <template #actions>
        <el-select v-model="userId" clearable :placeholder="t('全部用户')" style="width: 160px" @change="resetPageAndLoad">
          <el-option v-for="user in users" :key="user.id" :value="user.id" :label="user.display_name || user.username" />
        </el-select>
        <el-input v-model="keyword" clearable :placeholder="t('搜索剧本')" style="width: 180px" @keyup.enter="resetPageAndLoad" @clear="resetPageAndLoad" />
        <el-button :loading="loading" @click="load">
          <el-icon><Refresh /></el-icon><span>{{ t('刷新') }}</span>
        </el-button>
      </template>
    </PageToolbar>

    <div class="admin-series__body scrollable">
      <el-table v-loading="loading" :data="rows" row-key="id" class="records-table">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column prop="title" :label="t('剧本')" min-width="220" show-overflow-tooltip />
        <el-table-column prop="owner_name" :label="t('用户')" width="150" show-overflow-tooltip />
        <el-table-column :label="t('状态')" width="120">
          <template #default="{ row }">
            <StatusBadge :tone="seriesStatus(row).tone">{{ seriesStatus(row).text }}</StatusBadge>
          </template>
        </el-table-column>
        <el-table-column :label="t('剧集进度')" width="130">
          <template #default="{ row }">{{ row.done_episode_count }}/{{ row.episode_count }}</template>
        </el-table-column>
        <el-table-column :label="t('最近任务')" width="140">
          <template #default="{ row }">
            <StatusBadge v-if="row.latest_run" :tone="runTone(row.latest_run.status)" :pulse="row.latest_run.status === 'running'">
              {{ runStatusLabel(row.latest_run.status) }}
            </StatusBadge>
            <span v-else class="muted">{{ t('无任务') }}</span>
          </template>
        </el-table-column>
        <el-table-column :label="t('当前节点')" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.latest_run?.current_node_label || '--' }}</template>
        </el-table-column>
        <el-table-column prop="create_time" :label="t('创建时间')" width="180" />
        <template #empty>
          <EmptyState :title="t('暂无剧本记录')" :hint="t('没有匹配当前筛选条件的作品。')" />
        </template>
      </el-table>

      <div class="records-pagination">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="limit"
          :total="total"
          :page-sizes="[10, 20, 50, 100]"
          layout="total, sizes, prev, pager, next"
          @size-change="resetPageAndLoad"
          @current-change="load"
        />
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.admin-series {
  height: 100%;
  display: flex;
  flex-direction: column;
  background: var(--canvas);
}

.admin-series__body {
  flex: 1;
  min-height: 0;
  padding: var(--space-xl);
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}

.records-table {
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
}

.records-pagination {
  display: flex;
  justify-content: flex-end;
}

.muted {
  color: var(--muted);
  font-size: 12px;
}
</style>
