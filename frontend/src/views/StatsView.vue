<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import { listCreditStats, type CreditStatRow, type CreditStatSummary } from '@/api/adminStats'
import { listAdminUsers } from '@/api/adminUser'
import type { AdminUser } from '@/types'

const loading = ref(false)
const rows = ref<CreditStatRow[]>([])
const users = ref<AdminUser[]>([])
const groupBy = ref<'day' | 'user' | 'model' | 'modality'>('day')
const userId = ref<number | null>(null)
const modality = ref<'text' | 'image' | 'video' | ''>('')
const dateRange = ref<[string, string] | null>(null)
const summary = ref<CreditStatSummary>({
  consume_count: 0,
  credits_spent: 0,
  credits_text: 0,
  credits_image: 0,
  credits_video: 0,
  credit_unit_cny: 0.01,
  spent_cny: 0,
})
const { t } = useI18n()

const modalityLabel: Record<string, string> = {
  text: '文本',
  image: '图片',
  video: '视频',
  unknown: '未知',
}

async function load() {
  loading.value = true
  try {
    const data = await listCreditStats({
      group_by: groupBy.value,
      user_id: userId.value || undefined,
      modality: modality.value || undefined,
      start_date: dateRange.value?.[0],
      end_date: dateRange.value?.[1],
    })
    rows.value = data.list
    summary.value = data.summary
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

function rowLabel(row: CreditStatRow) {
  if (groupBy.value === 'modality') {
    return modalityLabel[row.label] || row.label || t('未知')
  }
  return row.label || '--'
}

onMounted(async () => {
  users.value = (await listAdminUsers()).list
  await load()
})
</script>

<template>
  <div class="stats-view">
    <PageToolbar
      kicker="CREDIT ANALYTICS"
      :title="t('积分统计')"
      :subtitle="t('按用户、模型、模态和日期汇总已消耗积分（仅扣费流水）。')"
    >
      <template #actions>
        <el-select v-model="groupBy" style="width: 120px" @change="load">
          <el-option :label="t('按日期')" value="day" />
          <el-option :label="t('按用户')" value="user" />
          <el-option :label="t('按模型')" value="model" />
          <el-option :label="t('按模态')" value="modality" />
        </el-select>
        <el-select v-model="userId" clearable :placeholder="t('全部用户')" style="width: 150px" @change="load">
          <el-option
            v-for="user in users"
            :key="user.id"
            :value="user.id"
            :label="user.display_name || user.username"
          />
        </el-select>
        <el-select v-model="modality" clearable :placeholder="t('全部模态')" style="width: 120px" @change="load">
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
          @change="load"
        />
        <el-button :loading="loading" @click="load">
          <el-icon><Refresh /></el-icon><span>{{ t('刷新') }}</span>
        </el-button>
      </template>
    </PageToolbar>

    <div class="stats-view__body scrollable">
      <div class="stats-strip">
        <div class="stat-tile stat-tile--primary">
          <span>{{ t('累计消耗积分') }}</span>
          <strong>{{ formatCredits(summary.credits_spent) }}</strong>
          <em>{{ formatCny(summary.spent_cny) }} · 1 {{ t('积分') }} = ¥0.01</em>
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

      <el-table v-loading="loading" :data="rows" row-key="key" class="stats-table">
        <el-table-column :label="t('维度')" min-width="180">
          <template #default="{ row }">{{ rowLabel(row) }}</template>
        </el-table-column>
        <el-table-column :label="t('扣费笔数')" width="120">
          <template #default="{ row }">{{ formatCredits(row.consume_count) }}</template>
        </el-table-column>
        <el-table-column :label="t('消耗积分')" min-width="140">
          <template #default="{ row }">
            <strong class="credits-spent">{{ formatCredits(row.credits_spent) }}</strong>
          </template>
        </el-table-column>
        <el-table-column :label="t('文本')" width="120">
          <template #default="{ row }">{{ formatCredits(row.credits_text) }}</template>
        </el-table-column>
        <el-table-column :label="t('图片')" width="120">
          <template #default="{ row }">{{ formatCredits(row.credits_image) }}</template>
        </el-table-column>
        <el-table-column :label="t('视频')" width="120">
          <template #default="{ row }">{{ formatCredits(row.credits_video) }}</template>
        </el-table-column>
        <el-table-column :label="t('约合人民币')" width="140">
          <template #default="{ row }">{{ formatCny(row.credits_spent * 0.01) }}</template>
        </el-table-column>
      </el-table>
    </div>
  </div>
</template>

<style scoped lang="scss">
.stats-view {
  height: 100%;
  display: flex;
  flex-direction: column;
  background: var(--canvas);
}

.stats-view__body {
  flex: 1;
  min-height: 0;
  padding: var(--space-xl);
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}

.stats-strip {
  display: grid;
  grid-template-columns: 1.4fr repeat(4, minmax(0, 1fr));
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
    font-size: 26px;
    letter-spacing: 0;
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

.stats-table {
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
}

.credits-spent {
  color: var(--primary);
  font-weight: 800;
}

@media (max-width: 1100px) {
  .stats-strip {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
