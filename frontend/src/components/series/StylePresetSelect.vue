<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import { useI18n } from 'vue-i18n'
import { listStylePresets } from '@/api/stylePreset'
import type { StylePreset } from '@/types'
import { VISUAL_STYLE_SEGMENT_OPTIONS, type VisualStyle } from '@/utils/visualStyle'

const props = withDefaults(defineProps<{
  modelValue: number | null
  visualStyle?: VisualStyle
  restrictVisualStyle?: boolean
  showEditHints?: boolean
  size?: 'default' | 'small'
  inlineMeta?: boolean
}>(), {
  visualStyle: 'realistic',
  restrictVisualStyle: true,
  showEditHints: false,
  size: 'default',
  inlineMeta: false,
})

const emit = defineEmits<{
  'update:modelValue': [value: number | null]
}>()

const { t } = useI18n()
const router = useRouter()
const presets = ref<StylePreset[]>([])
const loading = ref(false)
const failed = ref(false)
const baselineTechnique = ref('')
const baselineReady = ref(false)

const styleLabel = computed(() => {
  const found = VISUAL_STYLE_SEGMENT_OPTIONS.find((item) => item.value === props.visualStyle)
  return found ? t(found.label) : props.visualStyle
})

function applies(item: StylePreset, style: VisualStyle): boolean {
  const styles = item.visual_styles?.length ? item.visual_styles : ['realistic']
  return styles.includes(style)
}

const applicable = computed(() => (
  props.restrictVisualStyle
    ? presets.value.filter((item) => applies(item, props.visualStyle))
    : presets.value
))
const placeholder = computed(() => (
  failed.value
    ? t('风格列表加载失败')
    : (props.restrictVisualStyle ? t('不设风格，按画风默认') : t('不使用风格'))
))

function styleTags(item: StylePreset): string[] {
  const styles = item.visual_styles?.length ? item.visual_styles : ['realistic']
  return styles.map((style) => {
    const found = VISUAL_STYLE_SEGMENT_OPTIONS.find((option) => option.value === style)
    return found ? t(found.label) : style
  })
}
const officialPresets = computed(() => applicable.value.filter((item) => Number(item.is_system) === 1))
const userPresets = computed(() => applicable.value.filter((item) => Number(item.is_system) !== 1))
const manageHref = computed(() => router.resolve({ name: 'styles' }).href)

const selectedTechnique = computed(() => {
  const current = presets.value.find((item) => item.id === props.modelValue)
  return current?.technique_prompt?.trim() || ''
})

const techniqueChanged = computed(() => baselineReady.value && selectedTechnique.value !== baselineTechnique.value)

async function reload() {
  loading.value = true
  failed.value = false
  try {
    presets.value = await listStylePresets()
    ensureSelection()
    if (!baselineReady.value) {
      baselineTechnique.value = selectedTechnique.value
      baselineReady.value = true
    }
  } catch {
    failed.value = true
    presets.value = []
  } finally {
    loading.value = false
  }
}

function ensureSelection() {
  if (props.modelValue == null) return
  const current = presets.value.find((item) => item.id === props.modelValue)
  if (!props.restrictVisualStyle) {
    if (current && (current.visual_styles?.length || 1) === 1) return
    if (presets.value.length === 0) return
    emit('update:modelValue', null)
    if (current) ElMessage.info(t('原风格包含多个画风，请先在风格管理中选择一个主画风'))
    return
  }
  if (current && applies(current, props.visualStyle)) return
  emit('update:modelValue', null)
  ElMessage.info(t('原风格不适用于{style}，已清空', { style: styleLabel.value }))
}

function onVisible(open: boolean) {
  if (open) void reload()
}

watch(() => props.visualStyle, () => {
  if (props.restrictVisualStyle && presets.value.length) ensureSelection()
})

void reload()
</script>

<template>
  <div class="style-preset-select" :class="{ 'is-inline': inlineMeta }">
    <el-select
      :model-value="modelValue ?? undefined"
      clearable
      filterable
      :fit-input-width="false"
      popper-class="style-preset-popper"
      :size="size"
      :loading="loading"
      :placeholder="placeholder"
      style="width: 100%"
      @update:model-value="emit('update:modelValue', $event == null || $event === '' ? null : Number($event))"
      @visible-change="onVisible"
    >
      <el-option-group v-if="officialPresets.length" :label="t('官方风格')">
        <el-option
          v-for="item in officialPresets"
          :key="item.id"
          :label="item.name"
          :value="item.id"
          :disabled="!restrictVisualStyle && item.visual_styles?.length > 1"
        >
          <div class="style-preset-option">
            <div class="style-preset-option__head">
              <span>{{ item.name }}</span>
              <i v-for="tag in (!restrictVisualStyle ? styleTags(item) : [])" :key="tag">{{ tag }}</i>
            </div>
            <em v-if="item.summary">{{ item.summary }}</em>
          </div>
        </el-option>
      </el-option-group>
      <el-option-group v-if="userPresets.length" :label="t('我的风格')">
        <el-option
          v-for="item in userPresets"
          :key="item.id"
          :label="item.name"
          :value="item.id"
          :disabled="!restrictVisualStyle && item.visual_styles?.length > 1"
        >
          <div class="style-preset-option">
            <div class="style-preset-option__head">
              <span>{{ item.name }}</span>
              <i v-for="tag in (!restrictVisualStyle ? styleTags(item) : [])" :key="tag">{{ tag }}</i>
            </div>
            <em v-if="item.summary">{{ item.summary }}</em>
          </div>
        </el-option>
      </el-option-group>
    </el-select>
    <div class="style-preset-select__meta">
      <el-button v-if="failed" link type="primary" @click="reload">{{ t('重新加载') }}</el-button>
      <a class="style-preset-select__manage" :href="manageHref" target="_blank" rel="noopener">{{ t('管理风格') }}</a>
    </div>
    <p v-if="showEditHints" class="style-preset-select__hint">
      {{ t('更换后只影响之后的生成；已生成的场景图、首帧和视频不会自动重做。') }}
    </p>
    <p v-if="showEditHints && techniqueChanged" class="style-preset-select__hint">
      {{ t('画法也变了，建议重新生成人物和道具设定图。') }}
    </p>
  </div>
</template>

<style scoped lang="scss">
.style-preset-select {
  width: 100%;
  min-width: 0;
}

.style-preset-select.is-inline {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 12px;

  .el-select {
    flex: 1;
    min-width: 0;
  }

  .style-preset-select__meta {
    margin-top: 0;
    flex-shrink: 0;
  }
}

.style-preset-option {
  display: flex;
  flex-direction: column;
  gap: 4px;
  line-height: 1.45;
  white-space: normal;
}

.style-preset-option__head {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;

  span {
    color: var(--on-dark, #f1f5f9);
    font-size: 14px;
    font-weight: 600;
    line-height: 1.4;
  }

  i {
    flex-shrink: 0;
    color: var(--body, #cbd5e1);
    font-style: normal;
    font-size: 11px;
    font-weight: 600;
    line-height: 1;
    padding: 3px 6px;
    border-radius: 999px;
    background: rgba(148, 163, 184, 0.16);
  }
}

.style-preset-select__meta {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  margin-top: 6px;
}

.style-preset-select__manage {
  color: var(--brand-cyan, #22d3ee);
  font-size: 12px;
  text-decoration: none;
}

.style-preset-select__hint {
  margin: 6px 0 0;
  color: var(--text-muted, #94a3b8);
  font-size: 12px;
  line-height: 1.5;
}

.style-preset-select.is-inline .style-preset-select__hint {
  flex-basis: 100%;
}
</style>

<style lang="scss">
.style-preset-popper.el-select__popper {
  min-width: min(440px, 92vw) !important;

  .el-select-dropdown__wrap {
    max-height: 420px !important;
  }

  .el-select-group__title {
    color: var(--muted, #94a3b8);
    font-size: 12px;
    line-height: 28px;
  }

  .el-select-dropdown__item {
    height: auto !important;
    min-height: 52px;
    line-height: 1.45 !important;
    white-space: normal !important;
    overflow: visible !important;
    text-overflow: unset !important;
    padding: 10px 16px !important;
  }

  .style-preset-option em {
    display: block;
    color: var(--body, #cbd5e1);
    font-style: normal;
    font-size: 12px;
    font-weight: 400;
    line-height: 1.5;
    white-space: normal;
  }

  .el-select-dropdown__item.selected {
    background: rgba(34, 211, 238, 0.08);
  }

  .el-select-dropdown__item.selected .style-preset-option__head span {
    color: var(--accent-cyan, #22d3ee);
  }
}
</style>
