<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { useI18n } from 'vue-i18n'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import {
  getAiRequestLog,
  listAiRequestLogs,
  type AiRequestLogBrief,
  type AiRequestLogDetail,
} from '@/api/aiRequestLog'
import { listAdminUsers } from '@/api/adminUser'
import type { AdminUser } from '@/types'

const loading = ref(false)
const rows = ref<AiRequestLogBrief[]>([])
const total = ref(0)
const page = ref(1)
const limit = ref(20)
const sourceFilter = ref('')
const userIdFilter = ref<number | null>(null)
const statusFilter = ref<'success' | 'failed' | ''>('')
const modelFilter = ref('')
const runIdFilter = ref<string>('')
const users = ref<AdminUser[]>([])

const detailVisible = ref(false)
const detailLoading = ref(false)
const detail = ref<AiRequestLogDetail | null>(null)
const { t } = useI18n()

const sourceOptions = computed(() => [
  { value: '', label: '全部来源' },
  { value: 'series_workflow', label: '剧本解析' },
  { value: 'episode_workflow', label: '剧集流程' },
  { value: 'episode_workflow_video', label: '视频生成' },
  { value: 'image_generation', label: '图片生成' },
].map((item) => ({ ...item, label: t(item.label) })))

const sourceLabels: Record<string, string> = {
  series_workflow: '剧本解析',
  episode_workflow: '剧集流程',
  episode_workflow_video: '视频生成',
  image_generation: '图片生成',
}

function sourceLabel(source: string) {
  return t(sourceLabels[source] || source || '--')
}

async function load() {
  loading.value = true
  try {
    const data = await listAiRequestLogs({
      page: page.value,
      limit: limit.value,
      source: sourceFilter.value || undefined,
      user_id: userIdFilter.value || undefined,
      status: statusFilter.value || undefined,
      model: modelFilter.value || undefined,
      workflow_run_id: Number(runIdFilter.value) || undefined,
    })
    rows.value = data.list
    total.value = data.total
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  page.value = 1
  void load()
}

async function openDetail(row: AiRequestLogBrief) {
  detailVisible.value = true
  detailLoading.value = true
  detail.value = null
  try {
    detail.value = await getAiRequestLog(row.id)
  } finally {
    detailLoading.value = false
  }
}

function formatJson(value: Record<string, unknown> | string): string {
  if (typeof value === 'string') return value
  if (!value || Object.keys(value).length === 0) return ''
  return JSON.stringify(value, null, 2)
}

const detailRequestJson = computed(() => (detail.value ? formatJson(detail.value.request_json) : ''))
const detailContextJson = computed(() => (detail.value ? formatJson(detail.value.context_json) : ''))
const detailUsageJson = computed(() => (detail.value ? formatJson(detail.value.usage_json) : ''))

const detailResponsePreview = computed(() => {
  const body = detail.value?.response_body ?? ''
  if (body.length <= 6000) return body
  return `${body.slice(0, 3000)}\n...${t('[内容过长，已截断]')}...\n${body.slice(-3000)}`
})

async function copyText(text: string, label: string) {
  if (!text) return
  await navigator.clipboard.writeText(text)
  ElMessage.success(t('{label}已复制到剪贴板', { label: t(label) }))
}

function formatDuration(ms: number) {
  if (ms <= 0) return '--'
  if (ms < 1000) return `${ms}ms`
  return `${(ms / 1000).toFixed(1)}s`
}

onMounted(async () => {
  users.value = (await listAdminUsers()).list
  await load()
})
</script>

<template>
  <div class="logs-view">
    <PageToolbar
      kicker="AI REQUEST AUDIT"
      :title="t('AI 请求日志')"
      :subtitle="t('每一次模型调用的请求、响应和耗时都会记录在这里，便于排查生成失败的原因。')"
    >
      <template #actions>
        <el-select v-model="sourceFilter" style="width: 150px" @change="applyFilters">
          <el-option v-for="opt in sourceOptions" :key="opt.value" :value="opt.value" :label="opt.label" />
        </el-select>
        <el-select v-model="userIdFilter" clearable :placeholder="t('全部用户')" style="width: 150px" @change="applyFilters">
          <el-option v-for="user in users" :key="user.id" :value="user.id" :label="user.display_name || user.username" />
        </el-select>
        <el-select v-model="statusFilter" style="width: 120px" @change="applyFilters">
          <el-option :label="t('全部状态')" value="" />
          <el-option :label="t('成功')" value="success" />
          <el-option :label="t('失败')" value="failed" />
        </el-select>
        <el-input
          v-model="modelFilter"
          style="width: 150px"
          :placeholder="t('模型筛选')"
          clearable
          @keyup.enter="applyFilters"
          @clear="applyFilters"
        />
        <el-input
          v-model="runIdFilter"
          style="width: 150px"
          :placeholder="t('任务 ID 筛选')"
          clearable
          @keyup.enter="applyFilters"
          @clear="applyFilters"
        />
        <el-button :loading="loading" @click="applyFilters">
          <el-icon><Refresh /></el-icon><span>{{ t('刷新') }}</span>
        </el-button>
      </template>
    </PageToolbar>

    <div class="logs-view__body scrollable">
      <el-table
        v-loading="loading"
        :data="rows"
        class="logs-table"
        row-key="id"
        @row-click="openDetail"
      >
        <el-table-column prop="id" label="ID" width="80" />
        <el-table-column :label="t('用户')" width="120">
          <template #default="{ row }">
            {{ row.user?.display_name || row.user?.username || `#${row.user_id}` }}
          </template>
        </el-table-column>
        <el-table-column :label="t('来源')" width="110">
          <template #default="{ row }">
            <span class="source-pill">{{ sourceLabel(row.source) }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="llm_model" :label="t('模型')" min-width="160" show-overflow-tooltip />
        <el-table-column :label="t('状态')" width="100">
          <template #default="{ row }">
            <StatusBadge :tone="row.request_ok ? 'done' : 'fail'">
              {{ row.request_ok ? `${row.http_status || 200}` : (row.http_status || t('失败')) }}
            </StatusBadge>
          </template>
        </el-table-column>
        <el-table-column :label="t('耗时')" width="90">
          <template #default="{ row }">{{ formatDuration(row.duration_ms) }}</template>
        </el-table-column>
        <el-table-column label="Token" width="110">
          <template #default="{ row }">{{ row.total_tokens || '--' }}</template>
        </el-table-column>
        <el-table-column :label="t('任务')" width="90">
          <template #default="{ row }">
            <span v-if="row.workflow_run_id">#{{ row.workflow_run_id }}</span>
            <span v-else class="cell-muted">--</span>
          </template>
        </el-table-column>
        <el-table-column :label="t('摘要')" min-width="260">
          <template #default="{ row }">
            <span class="cell-preview">{{ row.error_message || row.content_preview || '--' }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="create_time" :label="t('时间')" width="170" />
        <template #empty>
          <EmptyState :title="t('暂无日志')" :hint="t('执行工作流或生成图片后，AI 调用记录会出现在这里。')" />
        </template>
      </el-table>

      <div class="logs-pagination">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="limit"
          :total="total"
          :page-sizes="[20, 50, 100]"
          layout="total, sizes, prev, pager, next"
          @current-change="load"
          @size-change="applyFilters"
        />
      </div>
    </div>

    <el-drawer v-model="detailVisible" size="640px" :title="detail ? t('日志 #{id}', { id: detail.id }) : t('日志详情')">
      <div v-loading="detailLoading" class="log-detail">
        <template v-if="detail">
          <div class="log-detail__meta">
            <div class="meta-item">
              <span class="meta-label">{{ t('来源') }}</span>
              <span>{{ sourceLabel(detail.source) }}</span>
            </div>
            <div class="meta-item">
              <span class="meta-label">{{ t('模型') }}</span>
              <span>{{ detail.llm_model || '--' }}</span>
            </div>
            <div class="meta-item">
              <span class="meta-label">{{ t('用户') }}</span>
              <span>{{ detail.user?.display_name || detail.user?.username || `#${detail.user_id}` }}</span>
            </div>
            <div class="meta-item">
              <span class="meta-label">{{ t('状态') }}</span>
              <StatusBadge :tone="detail.request_ok ? 'done' : 'fail'">
                HTTP {{ detail.http_status || '--' }}
              </StatusBadge>
            </div>
            <div class="meta-item">
              <span class="meta-label">{{ t('耗时') }}</span>
              <span>{{ formatDuration(detail.duration_ms) }}</span>
            </div>
            <div class="meta-item">
              <span class="meta-label">Token</span>
              <span>{{ detail.total_tokens || '--' }}</span>
            </div>
            <div class="meta-item meta-item--wide">
              <span class="meta-label">{{ t('接口') }}</span>
              <span class="meta-mono">{{ detail.endpoint || '--' }}</span>
            </div>
            <div v-if="detail.workflow_run_id" class="meta-item">
              <span class="meta-label">{{ t('任务') }}</span>
              <span>#{{ detail.workflow_run_id }}</span>
            </div>
            <div class="meta-item">
              <span class="meta-label">{{ t('时间') }}</span>
              <span>{{ detail.create_time || '--' }}</span>
            </div>
          </div>

          <el-alert
            v-if="detail.error_message || detail.curl_error"
            type="error"
            :closable="false"
            class="log-detail__error"
            :title="detail.error_message || detail.curl_error"
          />

          <section v-if="detailRequestJson" class="log-section">
            <div class="log-section__head">
              <strong>{{ t('请求参数') }}</strong>
              <el-button text size="small" @click="copyText(detailRequestJson, '请求参数')">{{ t('复制') }}</el-button>
            </div>
            <pre class="log-section__code">{{ detailRequestJson }}</pre>
          </section>

          <section v-if="detail.assistant_content" class="log-section">
            <div class="log-section__head">
              <strong>{{ t('模型输出') }}</strong>
              <el-button text size="small" @click="copyText(detail.assistant_content, '模型输出')">{{ t('复制') }}</el-button>
            </div>
            <pre class="log-section__code">{{ detail.assistant_content }}</pre>
          </section>

          <section v-else-if="detailResponsePreview" class="log-section">
            <div class="log-section__head">
              <strong>{{ t('原始响应') }}</strong>
              <el-button text size="small" @click="copyText(detail.response_body, '原始响应')">{{ t('复制') }}</el-button>
            </div>
            <pre class="log-section__code">{{ detailResponsePreview }}</pre>
          </section>

          <section v-if="detailUsageJson" class="log-section">
            <div class="log-section__head"><strong>{{ t('Token 用量') }}</strong></div>
            <pre class="log-section__code">{{ detailUsageJson }}</pre>
          </section>

          <section v-if="detailContextJson" class="log-section">
            <div class="log-section__head"><strong>{{ t('业务上下文') }}</strong></div>
            <pre class="log-section__code">{{ detailContextJson }}</pre>
          </section>
        </template>
      </div>
    </el-drawer>
  </div>
</template>

<style scoped lang="scss">
.logs-view {
  height: 100%;
  display: flex;
  flex-direction: column;
  background: var(--canvas);
  overflow: hidden;
}

.logs-view__body {
  flex: 1;
  min-height: 0;
  padding: var(--space-xl);
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}

.logs-table {
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);

  :deep(.el-table__row) {
    cursor: pointer;
  }
}

.source-pill {
  display: inline-block;
  padding: 2px 8px;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-pill);
  background: var(--surface-soft);
  color: var(--body-strong);
  font-size: 12px;
  white-space: nowrap;
}

.cell-preview {
  display: block;
  color: var(--muted);
  font-size: 12px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.cell-muted {
  color: var(--muted-soft);
}

.logs-pagination {
  display: flex;
  justify-content: flex-end;
}

.log-detail {
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
  min-height: 200px;
}

.log-detail__meta {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: var(--space-sm) var(--space-md);
  padding: var(--space-md);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-soft);
}

.meta-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
  font-size: 13px;

  &--wide {
    grid-column: 1 / -1;
  }
}

.meta-label {
  color: var(--muted);
  font-size: 11px;
  font-weight: 700;
}

.meta-mono {
  font-family: var(--font-mono);
  font-size: 12px;
  word-break: break-all;
}

.log-detail__error {
  flex-shrink: 0;
}

.log-section {
  display: flex;
  flex-direction: column;
  gap: var(--space-xs);
}

.log-section__head {
  display: flex;
  align-items: center;
  justify-content: space-between;

  strong {
    font-size: 13px;
    color: var(--body-strong);
  }
}

.log-section__code {
  margin: 0;
  padding: var(--space-md);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-raised);
  color: var(--body-strong);
  font-family: var(--font-mono);
  font-size: 12px;
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-word;
  max-height: 360px;
  overflow: auto;
}
</style>
