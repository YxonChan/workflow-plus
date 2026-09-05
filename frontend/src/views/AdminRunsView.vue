<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { listAdminRuns, type AdminRunRow, type AdminWorkflowRunStatus } from '@/api/adminProduction'
import { listAdminUsers } from '@/api/adminUser'
import type { AdminUser } from '@/types'

const loading = ref(false)
const rows = ref<AdminRunRow[]>([])
const users = ref<AdminUser[]>([])
const total = ref(0)
const page = ref(1)
const limit = ref(20)
const keyword = ref('')
const userId = ref<number | null>(null)
const status = ref<AdminWorkflowRunStatus | ''>('')
const { t } = useI18n()

const runStatusText: Record<AdminWorkflowRunStatus, string> = {
  queued: '排队中',
  running: '运行中',
  success: '已完成',
  failed: '失败',
  cancelled: '已取消',
}

function runStatusLabel(statusValue: AdminWorkflowRunStatus) {
  return t(runStatusText[statusValue] || statusValue)
}

function runTone(statusValue: AdminWorkflowRunStatus) {
  if (statusValue === 'success') return 'done'
  if (statusValue === 'failed') return 'fail'
  if (statusValue === 'cancelled') return 'info'
  return 'busy'
}

async function load() {
  loading.value = true
  try {
    const data = await listAdminRuns({
      page: page.value,
      limit: limit.value,
      user_id: userId.value || undefined,
      status: status.value,
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
  <div class="admin-runs">
    <PageToolbar kicker="RUN RECORDS" :title="t('任务记录')" :subtitle="t('只读查看所有用户发起的流程任务和失败信息。')">
      <template #actions>
        <el-select v-model="userId" clearable :placeholder="t('全部用户')" style="width: 160px" @change="resetPageAndLoad">
          <el-option v-for="user in users" :key="user.id" :value="user.id" :label="user.display_name || user.username" />
        </el-select>
        <el-select v-model="status" clearable :placeholder="t('全部状态')" style="width: 130px" @change="resetPageAndLoad">
          <el-option :label="t('排队中')" value="queued" />
          <el-option :label="t('运行中')" value="running" />
          <el-option :label="t('已完成')" value="success" />
          <el-option :label="t('失败')" value="failed" />
          <el-option :label="t('已取消')" value="cancelled" />
        </el-select>
        <el-input v-model="keyword" clearable :placeholder="t('搜索剧本/节点')" style="width: 190px" @keyup.enter="resetPageAndLoad" @clear="resetPageAndLoad" />
        <el-button :loading="loading" @click="load">
          <el-icon><Refresh /></el-icon><span>{{ t('刷新') }}</span>
        </el-button>
      </template>
    </PageToolbar>

    <div class="admin-runs__body scrollable">
      <el-table v-loading="loading" :data="rows" row-key="id" class="records-table">
        <el-table-column prop="id" label="ID" width="86" />
        <el-table-column prop="series_title" :label="t('剧本')" min-width="210" show-overflow-tooltip />
        <el-table-column prop="owner_name" :label="t('用户')" width="150" show-overflow-tooltip />
        <el-table-column :label="t('状态')" width="120">
          <template #default="{ row }">
            <StatusBadge :tone="runTone(row.status)" :pulse="row.status === 'running'">
              {{ runStatusLabel(row.status) }}
            </StatusBadge>
          </template>
        </el-table-column>
        <el-table-column :label="t('进度')" width="150">
          <template #default="{ row }">
            <el-progress :percentage="row.progress" :show-text="false" />
          </template>
        </el-table-column>
        <el-table-column :label="t('当前节点')" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.current_node_label || '--' }}</template>
        </el-table-column>
        <el-table-column :label="t('失败信息')" min-width="220" show-overflow-tooltip>
          <template #default="{ row }">{{ row.error_message || '--' }}</template>
        </el-table-column>
        <el-table-column prop="create_time" :label="t('创建时间')" width="180" />
        <el-table-column prop="finished_at" :label="t('完成时间')" width="180" />
        <template #empty>
          <EmptyState :title="t('暂无任务记录')" :hint="t('没有匹配当前筛选条件的流程任务。')" />
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
.admin-runs {
  height: 100%;
  display: flex;
  flex-direction: column;
  background: var(--canvas);
}

.admin-runs__body {
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
</style>
