<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import { listCreditPricing, type CreditPricingCatalog } from '@/api/creditPricing'

const { t } = useI18n()
const loading = ref(false)
const catalog = ref<CreditPricingCatalog | null>(null)

const creditUnitCny = computed(() => Number(catalog.value?.credit_unit_cny || 0.01))
/** 一毛钱（¥0.1）对应多少积分。 */
const creditsPerJiao = computed(() => {
  const unit = creditUnitCny.value
  if (!(unit > 0)) return 10
  return Number((0.1 / unit).toFixed(2))
})
const creditUnitLabel = computed(() => {
  const unit = creditUnitCny.value
  const fixed = Number.isInteger(unit) ? String(unit) : unit.toFixed(2).replace(/0+$/, '').replace(/\.$/, '')
  return `1 ${t('积分')} = ¥${fixed}`
})
const jiaoLabel = computed(() => `¥0.1 = ${creditsPerJiao.value} ${t('积分')}`)

function formatCredits(value: number): string {
  if (!Number.isFinite(value)) return '--'
  return Number.isInteger(value) ? String(value) : String(Number(value.toFixed(2)))
}

function formatCny(credits: number): string {
  const unit = creditUnitCny.value > 0 ? creditUnitCny.value : 0.01
  const cny = credits * unit
  return `¥${cny.toFixed(2)}`
}

/** 展示形态：190 / ¥1.90 */
function formatCreditsWithCny(credits: number): string {
  return `${formatCredits(credits)} / ${formatCny(credits)}`
}

/** 内部加价策略不对客户/页面明文展示。 */
function sanitizePublicPricingText(text: string): string {
  return String(text || '')
    .replace(/供应商成本上浮\s*\d+%（[^）]*）/g, '')
    .replace(/成本上浮\s*\d+%（[^）]*）/g, '')
    .replace(/上浮\s*\d+%/g, '')
    .replace(/平台价\s*=\s*供应商成本[^；。\n]*/g, '')
    .replace(/平台价\s*=\s*/g, '')
    .replace(/[×xX]\s*1\.25/g, '')
    .replace(/scaled to\s*[×xX]?\s*1\.25/gi, '')
    .replace(/scaled to\s+pending/gi, 'pending')
    .replace(/rates\s*[×xX]\s*1\.25/gi, 'rates')
    .replace(/[×xX]\s*markup\d*/gi, '')
    .replace(/\s*→\s*/g, ' → ')
    .replace(/\s{2,}/g, ' ')
    .replace(/[；;]\s*[；;]/g, '；')
    .replace(/^[；;，,\s]+|[；;，,\s]+$/g, '')
    .trim()
}

const publicNotes = computed(() =>
  (catalog.value?.notes || [])
    .map((note) => sanitizePublicPricingText(note))
    .filter((note) => note !== ''),
)

const textRows = computed(() => {
  const models = catalog.value?.text?.models || {}
  return Object.entries(models).map(([modelId, rule]) => ({
    model_id: modelId,
    label: String(rule.label || modelId),
    input: Number(rule.input_credits_per_1k || 0),
    output: Number(rule.output_credits_per_1k || 0),
    min: Number(rule.min_credits || 0),
    basis: sanitizePublicPricingText(String(rule.basis || '')),
  }))
})

const imageRows = computed(() => {
  const models = catalog.value?.image?.models || {}
  return Object.entries(models).flatMap(([modelId, rule]) => {
    const byQuality = rule.by_quality || {}
    return Object.entries(byQuality).map(([quality, credits]) => {
      const creditValue = Number(credits || 0)
      return {
        model_id: modelId,
        label: String(rule.label || modelId),
        quality,
        credits: creditValue,
        price_label: formatCreditsWithCny(creditValue),
        basis: sanitizePublicPricingText(String(rule.basis || '')),
      }
    })
  })
})

const videoRows = computed(() => {
  const models = catalog.value?.video?.models || {}
  return Object.entries(models).flatMap(([modelId, rule]) => {
    const byResolution = rule.by_resolution || {}
    return Object.entries(byResolution).map(([resolution, perSecond]) => {
      const perSec = Number(perSecond || 0)
      const for15s = perSec * 15
      return {
        model_id: modelId,
        label: String(rule.label || modelId),
        resolution,
        per_second: perSec,
        for_15s: for15s,
        per_second_label: formatCreditsWithCny(perSec),
        for_15s_label: formatCreditsWithCny(for15s),
        basis: sanitizePublicPricingText(String(rule.basis || '')),
      }
    })
  })
})

async function load() {
  loading.value = true
  try {
    catalog.value = await listCreditPricing()
  } finally {
    loading.value = false
  }
}

onMounted(load)
</script>

<template>
  <div class="credit-pricing-view">
    <PageToolbar
      kicker="CREDIT PRICING"
      :title="t('积分物价表')"
      :subtitle="t('只读参考价。第一期不支持在线修改。')"
    >
      <template #actions>
        <el-button :loading="loading" @click="load">
          <el-icon><Refresh /></el-icon><span>{{ t('刷新') }}</span>
        </el-button>
      </template>
    </PageToolbar>

    <div v-loading="loading" class="credit-pricing-view__body scrollable">
      <div v-if="catalog" class="meta-strip">
        <div class="meta-tile meta-tile--accent">
          <span>{{ t('积分单价') }}</span>
          <strong>{{ creditUnitLabel }}</strong>
        </div>
        <div class="meta-tile meta-tile--accent">
          <span>{{ t('一毛钱换算') }}</span>
          <strong>{{ jiaoLabel }}</strong>
        </div>
        <div class="meta-tile">
          <span>{{ t('物价表版本') }}</span>
          <strong>{{ catalog.pricing_version || '--' }}</strong>
        </div>
        <div class="meta-tile">
          <span>{{ t('更新日期') }}</span>
          <strong>{{ catalog.updated_at || '--' }}</strong>
        </div>
        <div class="meta-tile">
          <span>{{ t('计费开关') }}</span>
          <strong>{{ catalog.enabled ? t('已启用') : t('已关闭') }}</strong>
        </div>
        <div class="meta-tile">
          <span>{{ t('可编辑') }}</span>
          <strong>{{ catalog.editable ? t('是') : t('否') }}</strong>
        </div>
      </div>

      <el-alert
        v-for="(note, index) in publicNotes"
        :key="index"
        :title="note"
        type="info"
        :closable="false"
        show-icon
        class="note-alert"
      />

      <section class="pricing-section">
        <h3>{{ t('文本模型') }}</h3>
        <el-table :data="textRows" row-key="model_id">
          <el-table-column prop="label" :label="t('模型')" min-width="180" />
          <el-table-column prop="model_id" :label="t('模型 ID')" min-width="180" />
          <el-table-column prop="input" :label="t('输入积分 / 1K tokens')" min-width="160" />
          <el-table-column prop="output" :label="t('输出积分 / 1K tokens')" min-width="160" />
          <el-table-column prop="min" :label="t('最低扣费')" width="120" />
          <el-table-column prop="basis" :label="t('计价依据')" min-width="260" show-overflow-tooltip />
        </el-table>
      </section>

      <section class="pricing-section">
        <h3>{{ t('图片模型') }}</h3>
        <el-table :data="imageRows" :row-key="(row) => `${row.model_id}-${row.quality}`">
          <el-table-column prop="label" :label="t('模型')" min-width="160" />
          <el-table-column prop="model_id" :label="t('模型 ID')" min-width="160" />
          <el-table-column prop="quality" :label="t('质量')" width="120" />
          <el-table-column prop="price_label" :label="t('参考价（积分 / 人民币）')" min-width="180" />
          <el-table-column prop="basis" :label="t('计价依据')" min-width="260" show-overflow-tooltip />
        </el-table>
      </section>

      <section class="pricing-section">
        <h3>{{ t('视频模型') }}</h3>
        <el-table :data="videoRows" :row-key="(row) => `${row.model_id}-${row.resolution}`">
          <el-table-column prop="label" :label="t('模型')" min-width="180" />
          <el-table-column prop="model_id" :label="t('模型 ID')" min-width="200" />
          <el-table-column prop="resolution" :label="t('分辨率')" width="120" />
          <el-table-column prop="per_second_label" :label="t('每秒参考（积分 / 人民币）')" min-width="200" />
          <el-table-column prop="for_15s_label" :label="t('15 秒参考（积分 / 人民币）')" min-width="200" />
          <el-table-column prop="basis" :label="t('计价依据')" min-width="260" show-overflow-tooltip />
        </el-table>
      </section>
    </div>
  </div>
</template>

<style scoped lang="scss">
.credit-pricing-view {
  height: 100%;
  display: flex;
  flex-direction: column;
  background: var(--canvas);
}

.credit-pricing-view__body {
  flex: 1;
  padding: var(--space-lg);
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
}

.meta-strip {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: var(--space-md);
}

.meta-tile {
  background: var(--surface-card);
  border: 1px solid var(--hairline);
  border-radius: 12px;
  padding: 14px 16px;
  display: flex;
  flex-direction: column;
  gap: 6px;

  span {
    color: var(--text-secondary);
    font-size: 12px;
  }

  strong {
    font-size: 18px;
  }
}

.meta-tile--accent {
  border-color: color-mix(in srgb, var(--primary) 28%, var(--hairline));
  background: color-mix(in srgb, var(--primary) 14%, var(--surface-card));

  strong {
    color: var(--primary);
  }
}

@media (max-width: 1100px) {
  .meta-strip {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}

@media (max-width: 720px) {
  .meta-strip {
    grid-template-columns: 1fr;
  }
}

.note-alert {
  margin: 0;
}

.pricing-section {
  background: var(--surface-card);
  border: 1px solid var(--hairline);
  border-radius: 12px;
  padding: 16px;

  h3 {
    margin: 0 0 12px;
    font-size: 16px;
  }
}

@media (max-width: 960px) {
  .meta-strip {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
