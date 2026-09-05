<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import { listMyCreditLedger, type CreditLedgerItem, type CreditLedgerSummary } from '@/api/credit'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const auth = useAuthStore()

const loading = ref(false)
const rows = ref<CreditLedgerItem[]>([])
const balance = ref(0)
const page = ref(1)
const pageSize = ref(20)
const total = ref(0)
const entryType = ref<'consume' | 'topup' | 'refund' | 'adjust' | ''>('consume')
const modality = ref<'text' | 'image' | 'video' | ''>('')
const dateRange = ref<[string, string] | null>(null)
const summary = ref<CreditLedgerSummary>({
  consume_count: 0,
  credits_spent: 0,
  credits_text: 0,
  credits_image: 0,
  credits_video: 0,
  credit_unit_cny: 0.01,
  spent_cny: 0,
})

const entryTypeLabel: Record<string, string> = {
  consume: '消耗',
  topup: '充值',
  refund: '退款',
  adjust: '调整',
}

const modalityLabel: Record<string, string> = {
  text: '文本',
  image: '图片',
  video: '视频',
}

const balanceText = computed(() => formatCredits(balance.value || Number(auth.user?.credit_balance || 0)))

async function load(resetPage = false) {
  if (resetPage) page.value = 1
  loading.value = true
  try {
    const data = await listMyCreditLedger({
      page: page.value,
      page_size: pageSize.value,
      entry_type: entryType.value || undefined,
      modality: modality.value || undefined,
      start_date: dateRange.value?.[0],
      end_date: dateRange.value?.[1],
    })
    rows.value = data.list
    summary.value = data.summary
    balance.value = data.balance
    total.value = data.pagination.total
    page.value = data.pagination.page
    pageSize.value = data.pagination.page_size
    if (auth.user) {
      auth.user.credit_balance = data.balance
    }
  } finally {
    loading.value = false
  }
}

function formatCredits(value: number) {
  return Number(value || 0).toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  })
}

function formatCny(value: number) {
  return `¥${Number(value || 0).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 4,
  })}`
}

function amountText(row: CreditLedgerItem) {
  const sign = row.amount > 0 ? '+' : row.amount < 0 ? '' : ''
  return `${sign}${formatCredits(row.amount)}`
}

function amountClass(row: CreditLedgerItem) {
  if (row.amount < 0) return 'is-spend'
  if (row.amount > 0) return 'is-gain'
  return ''
}

onMounted(() => {
  void load()
})
</script>

<template>
  <div class="my-credits-view">
    <PageToolbar
      kicker="MY CREDITS"
      :title="t('积分清单')"
      :subtitle="t('查看本人积分余额、消耗汇总与扣费流水。')"
    >
      <template #actions>
        <el-select v-model="entryType" clearable :placeholder="t('全部类型')" style="width: 120px" @change="load(true)">
          <el-option :label="t('消耗')" value="consume" />
          <el-option :label="t('充值')" value="topup" />
          <el-option :label="t('退款')" value="refund" />
          <el-option :label="t('调整')" value="adjust" />
        </el-select>
        <el-select v-model="modality" clearable :placeholder="t('全部模态')" style="width: 120px" @change="load(true)">
          <el-option :label="t('文本')" value="text" />
          <el-option :label="t('图片')" value="image" />
          <el-option :label="t('视频')" value="video" />
        </el-select>
        <el-date-picker
          v-model="dateRange"
          type="daterange"
          value-format="YYYY-MM-DD"
          :start-placeholder="t('开始日期')"
          :end-placeholder="t('结束日期')"
          style="width: 240px"
          @change="load(true)"
        />
        <el-button :loading="loading" @click="load()">
          <el-icon><Refresh /></el-icon><span>{{ t('刷新') }}</span>
        </el-button>
      </template>
    </PageToolbar>

    <div class="my-credits-view__body scrollable">
      <div class="stats-strip">
        <div class="stat-tile stat-tile--primary">
          <span>{{ t('当前余额') }}</span>
          <strong>{{ balanceText }}</strong>
          <em>{{ t('积分') }} · 1 {{ t('积分') }} = ¥0.01</em>
        </div>
        <div class="stat-tile">
          <span>{{ t('累计消耗积分') }}</span>
          <strong>{{ formatCredits(summary.credits_spent) }}</strong>
          <em>{{ formatCny(summary.spent_cny) }}</em>
        </div>
        <div class="stat-tile">
          <span>{{ t('扣费笔数') }}</span>
          <strong>{{ formatCredits(summary.consume_count) }}</strong>
        </div>
        <div class="stat-tile">
          <span>{{ t('文本积分') }}</span>
          <strong>{{ formatCredits(summary.credits_text) }}</strong>
        </div>
        <div class="stat-tile">
          <span>{{ t('图片积分') }}</span>
          <strong>{{ formatCredits(summary.credits_image) }}</strong>
        </div>
        <div class="stat-tile">
          <span>{{ t('视频积分') }}</span>
          <strong>{{ formatCredits(summary.credits_video) }}</strong>
        </div>
      </div>

      <el-table v-loading="loading" :data="rows" row-key="id" class="credits-table" empty-text="暂无流水">
        <el-table-column :label="t('时间')" min-width="170">
          <template #default="{ row }">{{ row.create_time || '--' }}</template>
        </el-table-column>
        <el-table-column :label="t('类型')" width="100">
          <template #default="{ row }">{{ t(entryTypeLabel[row.entry_type] || row.entry_type || '未知') }}</template>
        </el-table-column>
        <el-table-column :label="t('模态')" width="90">
          <template #default="{ row }">
            {{ row.modality ? t(modalityLabel[row.modality] || row.modality) : '—' }}
          </template>
        </el-table-column>
        <el-table-column :label="t('模型')" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ row.model_id || '—' }}</template>
        </el-table-column>
        <el-table-column :label="t('变动积分')" width="130">
          <template #default="{ row }">
            <strong class="amount" :class="amountClass(row)">{{ amountText(row) }}</strong>
          </template>
        </el-table-column>
        <el-table-column :label="t('余额')" width="120">
          <template #default="{ row }">{{ formatCredits(row.balance_after) }}</template>
        </el-table-column>
        <el-table-column :label="t('说明')" min-width="220" show-overflow-tooltip>
          <template #default="{ row }">{{ row.description || '—' }}</template>
        </el-table-column>
      </el-table>

      <div class="credits-pager">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="pageSize"
          background
          layout="total, prev, pager, next, sizes"
          :total="total"
          :page-sizes="[20, 50, 100]"
          @current-change="load()"
          @size-change="load(true)"
        />
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.my-credits-view {
  height: 100%;
  display: flex;
  flex-direction: column;
  background: var(--canvas);
}

.my-credits-view__body {
  flex: 1;
  min-height: 0;
  padding: var(--space-xl);
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}

.stats-strip {
  display: grid;
  grid-template-columns: 1.2fr repeat(5, minmax(0, 1fr));
  gap: var(--space-md);
}

.stat-tile {
  min-height: 92px;
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
    font-size: 24px;
    line-height: 1.1;
  }

  em {
    font-style: normal;
    color: var(--muted-soft);
    font-size: 12px;
  }

  &--primary {
    border-color: rgba(var(--primary-rgb), 0.35);
    background:
      radial-gradient(circle at 12% 0%, rgba(var(--primary-rgb), 0.18), transparent 42%),
      var(--surface-card);

    strong {
      color: var(--primary);
      font-size: 30px;
    }
  }
}

.credits-table {
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
}

.amount {
  font-weight: 800;

  &.is-spend {
    color: #dc2626;
  }

  &.is-gain {
    color: #059669;
  }
}

.credits-pager {
  display: flex;
  justify-content: flex-end;
}

@media (max-width: 1100px) {
  .stats-strip {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
