<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { listAdminUsers } from '@/api/adminUser'
import { getAdminOperationLog, listAdminOperationLogs, type AdminOperationLogBrief, type AdminOperationLogDetail } from '@/api/adminOperationLog'
import type { AdminUser } from '@/types'

const loading = ref(false)
const rows = ref<AdminOperationLogBrief[]>([])
const users = ref<AdminUser[]>([])
const total = ref(0)
const page = ref(1)
const limit = ref(20)
const operatorId = ref<number | null>(null)
const action = ref('')
const targetType = ref('')
const result = ref<'success' | 'failed' | ''>('')
const keyword = ref('')
const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<AdminOperationLogDetail | null>(null)
const { t } = useI18n()

const targetTypeOptions = [
  { value: '', label: '全部对象' },
  { value: 'series', label: '剧本' },
  { value: 'episode', label: '剧集' },
  { value: 'asset', label: '资产' },
]

const resultOptions = [
  { value: '', label: '全部结果' },
  { value: 'success', label: '成功' },
  { value: 'failed', label: '失败' },
]

function prettyAction(value: string) {
  const map: Record<string, string> = {
    'series.create': '剧本创建',
    'series.update': '剧本更新',
    'series.delete': '剧本删除',
    'episode.create': '剧集创建',
    'episode.update': '剧集更新',
    'episode.delete': '剧集删除',
    'asset.create': '资产创建',
    'asset.update': '资产更新',
    'asset.delete': '资产删除',
  }
  return t(map[value] || value || '--')
}

async function load() {
  loading.value = true
  try {
    const data = await listAdminOperationLogs({
      page: page.value,
      limit: limit.value,
      operator_user_id: operatorId.value || undefined,
      action: action.value.trim() || undefined,
      target_type: targetType.value || undefined,
      result: result.value || undefined,
      keyword: keyword.value.trim() || undefined,
    })
    rows.value = data.list
    total.value = data.total
  } finally {
    loading.value = false
  }
}

function resetAndLoad() {
  page.value = 1
  void load()
}

async function openDetail(row: AdminOperationLogBrief) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = null
  try {
    detail.value = await getAdminOperationLog(row.id)
  } finally {
    detailLoading.value = false
  }
}

function formatJson(value: Record<string, unknown> | string) {
  if (typeof value === 'string') return value
  if (!value || Object.keys(value).length === 0) return ''
  return JSON.stringify(value, null, 2)
}

const detailBeforeJson = computed(() => (detail.value ? formatJson(detail.value.before_json) : ''))
const detailAfterJson = computed(() => (detail.value ? formatJson(detail.value.after_json) : ''))
const detailMetaJson = computed(() => (detail.value ? formatJson(detail.value.meta_json) : ''))

onMounted(async () => {
  users.value = (await listAdminUsers()).list
  await load()
})
</script>

<template>
  <div class="operation-logs">
    <PageToolbar kicker="OPERATION AUDIT" :title="t('操作日志')" :subtitle="t('记录管理员可见的剧本、剧集和资产增删改。')">
      <template #actions>
        <el-select v-model="operatorId" clearable :placeholder="t('全部用户')" style="width: 150px" @change="resetAndLoad">
          <el-option v-for="user in users" :key="user.id" :value="user.id" :label="user.display_name || user.username" />
        </el-select>
        <el-select v-model="targetType" style="width: 130px" @change="resetAndLoad">
          <el-option v-for="opt in targetTypeOptions" :key="opt.value" :value="opt.value" :label="t(opt.label)" />
        </el-select>
        <el-select v-model="result" style="width: 120px" @change="resetAndLoad">
          <el-option v-for="opt in resultOptions" :key="opt.value" :value="opt.value" :label="t(opt.label)" />
        </el-select>
        <el-input v-model="action" clearable :placeholder="t('动作筛选')" style="width: 160px" @keyup.enter="resetAndLoad" @clear="resetAndLoad" />
        <el-input v-model="keyword" clearable :placeholder="t('关键词')" style="width: 160px" @keyup.enter="resetAndLoad" @clear="resetAndLoad" />
        <el-button :loading="loading" @click="resetAndLoad">
          <el-icon><Refresh /></el-icon><span>{{ t('刷新') }}</span>
        </el-button>
      </template>
    </PageToolbar>

    <div class="operation-logs__body scrollable">
      <el-table v-loading="loading" :data="rows" row-key="id" class="records-table" @row-click="openDetail">
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column :label="t('操作人')" width="150">
          <template #default="{ row }">{{ row.operator?.display_name || row.operator?.username || `#${row.operator_user_id}` }}</template>
        </el-table-column>
        <el-table-column :label="t('动作')" width="150">
          <template #default="{ row }">{{ prettyAction(row.action) }}</template>
        </el-table-column>
        <el-table-column :label="t('对象')" width="120">
          <template #default="{ row }">{{ t(row.target_type || '--') }}</template>
        </el-table-column>
        <el-table-column :label="t('名称')" min-width="220" show-overflow-tooltip>
          <template #default="{ row }">{{ row.target_name_snapshot || '--' }}</template>
        </el-table-column>
        <el-table-column :label="t('结果')" width="100">
          <template #default="{ row }">
            <StatusBadge :tone="row.result === 'success' ? 'done' : 'fail'">{{ row.result === 'success' ? t('成功') : t('失败') }}</StatusBadge>
          </template>
        </el-table-column>
        <el-table-column prop="create_time" :label="t('时间')" width="180" />
        <template #empty>
          <EmptyState :title="t('暂无日志')" :hint="t('执行剧本、剧集或资产的创建更新删除后，这里会显示审计记录。')" />
        </template>
      </el-table>

      <div class="records-pagination">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="limit"
          :total="total"
          :page-sizes="[20, 50, 100]"
          layout="total, sizes, prev, pager, next"
          @current-change="load"
          @size-change="resetAndLoad"
        />
      </div>
    </div>

    <el-drawer v-model="detailVisible" size="640px" :title="detail ? `${t('日志')} #${detail.id}` : t('日志详情')">
      <div v-loading="detailLoading" class="detail-panel">
        <template v-if="detail">
          <div class="detail-meta">
            <div><span>{{ t('操作人') }}</span><strong>{{ detail.operator?.display_name || detail.operator?.username || detail.operator_name_snapshot || `#${detail.operator_user_id}` }}</strong></div>
            <div><span>{{ t('动作') }}</span><strong>{{ prettyAction(detail.action) }}</strong></div>
            <div><span>{{ t('对象') }}</span><strong>{{ t(detail.target_type || '--') }}</strong></div>
            <div><span>{{ t('结果') }}</span><strong>{{ detail.result }}</strong></div>
            <div><span>{{ t('IP') }}</span><strong>{{ detail.ip || '--' }}</strong></div>
            <div><span>{{ t('时间') }}</span><strong>{{ detail.create_time || '--' }}</strong></div>
          </div>
          <section v-if="detailBeforeJson" class="detail-block"><strong>{{ t('变更前') }}</strong><pre>{{ detailBeforeJson }}</pre></section>
          <section v-if="detailAfterJson" class="detail-block"><strong>{{ t('变更后') }}</strong><pre>{{ detailAfterJson }}</pre></section>
          <section v-if="detailMetaJson" class="detail-block"><strong>{{ t('上下文') }}</strong><pre>{{ detailMetaJson }}</pre></section>
        </template>
      </div>
    </el-drawer>
  </div>
</template>

<style scoped lang="scss">
.operation-logs { height: 100%; display: flex; flex-direction: column; background: var(--canvas); overflow: hidden; }
.operation-logs__body { flex: 1; min-height: 0; padding: var(--space-xl); display: flex; flex-direction: column; gap: var(--space-md); }
.records-table { border: 1px solid var(--hairline); border-radius: var(--radius-md); }
.records-pagination { display: flex; justify-content: flex-end; }
.detail-panel { display: flex; flex-direction: column; gap: var(--space-md); }
.detail-meta { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: var(--space-sm) var(--space-md); }
.detail-meta span { display: block; color: var(--muted); font-size: 12px; }
.detail-meta strong { display: block; font-size: 13px; word-break: break-word; }
.detail-block { display: flex; flex-direction: column; gap: 6px; }
.detail-block pre { margin: 0; padding: var(--space-md); border: 1px solid var(--hairline); border-radius: var(--radius-md); white-space: pre-wrap; word-break: break-word; max-height: 320px; overflow: auto; }
</style>
