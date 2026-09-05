<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { CopyDocument } from '@element-plus/icons-vue'
import { t } from '@/i18n'
import {
  copyAssetFrom,
  listAssetsForReuse,
  type ReuseAssetBrief,
  type ReuseSeriesOption,
} from '@/api/asset'

const props = defineProps<{
  modelValue: boolean
  targetSeriesId: number
  targetSeriesTitle: string
  /** 打开时预选来源作品（可选） */
  initialSourceSeriesId?: number | null
}>()

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void
  (e: 'copied', assetId: number): void
}>()

const dialogVisible = ref(false)
const loading = ref(false)
const copying = ref(false)
const seriesOptions = ref<ReuseSeriesOption[]>([])
const assets = ref<ReuseAssetBrief[]>([])
const sourceSeriesId = ref<number | null>(null)
const typeFilter = ref<'all' | 'character' | 'scene' | 'prop'>('all')
const keyword = ref('')
const selectedAssetId = ref<number | null>(null)
const copyLooks = ref(true)
const copyVoice = ref(true)
const copyName = ref('')

const dialogStyle = {
  width: 'min(94vw, 760px)',
  maxWidth: '760px',
  height: 'auto',
  maxHeight: 'calc(100dvh - 48px)',
  margin: '24px auto',
}

const selectedAsset = computed(() => assets.value.find((a) => a.id === selectedAssetId.value) ?? null)
const isCharacterSelected = computed(() => selectedAsset.value?.type === 'character')

watch(
  () => props.modelValue,
  (open) => {
    dialogVisible.value = open
    if (open) void bootstrap()
  },
  { immediate: true },
)

watch(dialogVisible, (open) => {
  if (open !== props.modelValue) emit('update:modelValue', open)
})

watch([sourceSeriesId, typeFilter, keyword], () => {
  if (dialogVisible.value && sourceSeriesId.value) void loadAssets()
})

watch(selectedAsset, (asset) => {
  if (!asset) {
    copyName.value = ''
    return
  }
  copyName.value = asset.name
})

async function bootstrap() {
  selectedAssetId.value = null
  copyLooks.value = true
  copyVoice.value = true
  copyName.value = ''
  keyword.value = ''
  typeFilter.value = 'all'
  assets.value = []
  loading.value = true
  try {
    const data = await listAssetsForReuse({ target_series_id: props.targetSeriesId })
    seriesOptions.value = data.series
    const preferred = props.initialSourceSeriesId && data.series.some((s) => s.id === props.initialSourceSeriesId)
      ? props.initialSourceSeriesId
      : (data.series[0]?.id ?? null)
    sourceSeriesId.value = preferred
    if (preferred) await loadAssets()
  } catch (err: unknown) {
    ElMessage.error((err as Error)?.message || t('加载可复用资产失败'))
  } finally {
    loading.value = false
  }
}

async function loadAssets() {
  if (!sourceSeriesId.value) {
    assets.value = []
    return
  }
  loading.value = true
  try {
    const data = await listAssetsForReuse({
      target_series_id: props.targetSeriesId,
      source_series_id: sourceSeriesId.value,
      type: typeFilter.value === 'all' ? undefined : typeFilter.value,
      keyword: keyword.value.trim() || undefined,
    })
    assets.value = data.assets
    if (selectedAssetId.value && !assets.value.some((a) => a.id === selectedAssetId.value)) {
      selectedAssetId.value = null
    }
  } catch (err: unknown) {
    ElMessage.error((err as Error)?.message || t('加载可复用资产失败'))
  } finally {
    loading.value = false
  }
}

function typeLabel(type: string): string {
  if (type === 'character') return t('人物')
  if (type === 'scene') return t('场景')
  if (type === 'prop') return t('道具')
  return type
}

async function submit() {
  if (!selectedAsset.value) {
    ElMessage.warning(t('请先选择要复用的资产'))
    return
  }
  if (!props.targetSeriesId) {
    ElMessage.warning(t('请先选择目标作品'))
    return
  }
  copying.value = true
  try {
    const result = await copyAssetFrom({
      source_asset_id: selectedAsset.value.id,
      target_series_id: props.targetSeriesId,
      name: copyName.value.trim() || undefined,
      copy_looks: isCharacterSelected.value ? copyLooks.value : false,
      copy_voice: isCharacterSelected.value ? copyVoice.value : false,
    })
    const parts = [t('已复制到当前作品：{name}', { name: result.asset.name })]
    if (result.copied.looks > 0) parts.push(t('造型 {count} 套', { count: result.copied.looks }))
    if (result.copied.voice) parts.push(t('含音色'))
    ElMessage.success(parts.join(' · '))
    emit('copied', result.asset.id)
    dialogVisible.value = false
  } catch (err: unknown) {
    ElMessage.error((err as Error)?.message || t('复用失败'))
  } finally {
    copying.value = false
  }
}
</script>

<template>
  <el-dialog
    v-model="dialogVisible"
    class="asset-reuse-dialog"
    :title="t('从其他作品复用资产')"
    append-to-body
    destroy-on-close
    :style="dialogStyle"
  >
    <p class="reuse-hint">
      {{ t('复制为当前作品的独立副本，不是共用。人物可一并带走造型与音色；写实造型的真人检测不会复制，需在副本上重新检测。') }}
    </p>

    <div class="reuse-target">
      <span>{{ t('目标作品') }}</span>
      <strong>{{ targetSeriesTitle || t('未选择') }}</strong>
    </div>

    <div class="reuse-filters">
      <el-select
        v-model="sourceSeriesId"
        filterable
        :placeholder="t('选择来源作品')"
        style="min-width: 220px; flex: 1"
        :disabled="loading || seriesOptions.length === 0"
      >
        <el-option
          v-for="item in seriesOptions"
          :key="item.id"
          :label="item.title"
          :value="item.id"
        />
      </el-select>
      <el-select v-model="typeFilter" style="width: 120px" :disabled="loading">
        <el-option :label="t('全部')" value="all" />
        <el-option :label="t('人物')" value="character" />
        <el-option :label="t('场景')" value="scene" />
        <el-option :label="t('道具')" value="prop" />
      </el-select>
      <el-input
        v-model="keyword"
        clearable
        :placeholder="t('搜索资产名')"
        style="width: 160px"
        :disabled="loading"
      />
    </div>

    <div v-loading="loading" class="reuse-list">
      <el-empty
        v-if="!loading && seriesOptions.length === 0"
        :description="t('没有其他可复用的作品')"
      />
      <el-empty
        v-else-if="!loading && sourceSeriesId && assets.length === 0"
        :description="t('该作品暂无匹配资产')"
      />
      <button
        v-for="asset in assets"
        :key="asset.id"
        type="button"
        class="reuse-card"
        :class="{ 'is-selected': selectedAssetId === asset.id }"
        @click="selectedAssetId = asset.id"
      >
        <div class="reuse-card__thumb">
          <img v-if="asset.cover_url" :src="asset.cover_url" alt="" />
          <span v-else>{{ asset.name.slice(0, 1) }}</span>
        </div>
        <div class="reuse-card__body">
          <strong>{{ asset.name }}</strong>
          <em>{{ typeLabel(asset.type) }}</em>
          <small>
            <template v-if="asset.type === 'character'">
              {{ t('{count} 套造型', { count: asset.look_count }) }}
              · {{ asset.has_voice ? t('有音色') : t('无音色') }}
            </template>
            <template v-else>{{ asset.description || t('暂无描述') }}</template>
          </small>
        </div>
      </button>
    </div>

    <div v-if="selectedAsset" class="reuse-options">
      <el-form-item :label="t('副本名称')">
        <el-input v-model="copyName" maxlength="120" show-word-limit />
      </el-form-item>
      <template v-if="isCharacterSelected">
        <el-checkbox v-model="copyLooks">{{ t('一并复制造型') }}</el-checkbox>
        <el-checkbox v-model="copyVoice">{{ t('一并复制音色') }}</el-checkbox>
      </template>
    </div>

    <template #footer>
      <el-button @click="dialogVisible = false">{{ t('取消') }}</el-button>
      <el-button
        type="primary"
        :loading="copying"
        :disabled="!selectedAssetId || !targetSeriesId"
        @click="submit"
      >
        <el-icon><CopyDocument /></el-icon>
        <span>{{ t('复制到当前作品') }}</span>
      </el-button>
    </template>
  </el-dialog>
</template>

<style scoped lang="scss">
.reuse-hint {
  margin: 0 0 12px;
  color: var(--muted);
  font-size: 13px;
  line-height: 1.5;
}

.reuse-target {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
  padding: 8px 12px;
  border-radius: 10px;
  background: var(--surface-muted, rgba(15, 23, 42, 0.04));
  font-size: 13px;

  strong {
    font-weight: 700;
  }
}

.reuse-filters {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}

.reuse-list {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 10px;
  min-height: 180px;
  max-height: min(42vh, 360px);
  overflow: auto;
  padding: 2px;
}

.reuse-card {
  display: flex;
  gap: 10px;
  align-items: center;
  padding: 10px;
  border: 1px solid var(--hairline);
  border-radius: 12px;
  background: var(--surface-card, #fff);
  text-align: left;
  cursor: pointer;
  transition: border-color 0.15s ease, box-shadow 0.15s ease;

  &:hover {
    border-color: rgba(15, 159, 154, 0.45);
  }

  &.is-selected {
    border-color: var(--brand-cyan, #0f9f9a);
    box-shadow: 0 0 0 1px rgba(15, 159, 154, 0.25);
  }
}

.reuse-card__thumb {
  width: 48px;
  height: 48px;
  border-radius: 10px;
  overflow: hidden;
  flex-shrink: 0;
  display: grid;
  place-items: center;
  background: rgba(15, 23, 42, 0.06);
  font-weight: 800;
  color: var(--muted);

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }
}

.reuse-card__body {
  min-width: 0;
  display: grid;
  gap: 2px;

  strong {
    font-size: 13px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  em {
    font-style: normal;
    font-size: 11px;
    color: var(--brand-cyan, #0f9f9a);
  }

  small {
    font-size: 11px;
    color: var(--muted);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

.reuse-options {
  margin-top: 14px;
  display: grid;
  gap: 8px;
}
</style>
