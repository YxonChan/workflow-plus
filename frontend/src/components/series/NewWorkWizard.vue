<script setup lang="ts">
import { ref, computed, watch, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { useI18n } from 'vue-i18n'
import { graphToSteps } from '@/utils/workflowGraph'
import {
  VISUAL_STYLE_SEGMENT_OPTIONS,
  normalizeVisualStyleVariant,
  visualStyleVariantOptions,
  type VisualStyle,
} from '@/utils/visualStyle'
import { parseNovelFile } from '@/utils/novelImport'
import type { WorkflowBundle } from '@/types'

interface Props {
  visible: boolean
  bundles: WorkflowBundle[]
  /** 深链预选的套餐 id */
  preselectId?: number | null
}
const props = defineProps<Props>()
const { t } = useI18n()
const emit = defineEmits<{
  (e: 'update:visible', v: boolean): void
  (e: 'submit', payload: {
    bundle: WorkflowBundle
    title: string
    input: string
    episodeCount: number | null
    promoSegmentCount: number | null
    visualStyle: VisualStyle
    visualStyleVariant: string
    region: 'china' | 'western'
  }): void
}>()

const selectedId = ref<number | null>(null)
const input = ref('')
const title = ref('')
const episodeCount = ref<number | null>(null)
const promoSegmentCount = ref(3)
const visualStyle = ref<VisualStyle>('realistic')
const visualStyleVariant = ref('')
const region = ref<'china' | 'western'>('china')
const novelImportInputRef = ref<HTMLInputElement | null>(null)
const isNovelImporting = ref(false)
const isNovelDragOver = ref(false)
const importedNovelFilename = ref('')

const selected = computed(() => props.bundles.find((b) => b.id === selectedId.value) ?? null)
const hasSeries = computed(() => !!selected.value?.series_workflow_id)
const isPromoWorkflow = computed(() => {
  const nodes = selected.value?.episode_workflow?.graph?.nodes
  return Array.isArray(nodes) && nodes.some((node: any) => {
    const label = String(node?.label ?? node?.data?.label ?? '')
    return label.includes('宣传片') && label.includes('分镜处理')
  })
})
const systemBundles = computed(() => props.bundles.filter((b) => Number(b.is_system) === 1))
const userBundles = computed(() => props.bundles.filter((b) => Number(b.is_system) !== 1))
const visualStyleSegmentOptions = computed(() =>
  VISUAL_STYLE_SEGMENT_OPTIONS.map((option) => ({ ...option, label: t(option.label) })),
)
const currentVisualStyleVariantOptions = computed(() =>
  visualStyleVariantOptions(visualStyle.value).map((option) => ({
    ...option,
    label: t(option.label),
    desc: option.desc ? t(option.desc) : option.desc,
  })),
)
const regionOptions = computed(() => [
  { label: t('国内'), value: 'china' },
  { label: t('海外'), value: 'western' },
])
const promoSegmentOptions = computed(() => [1, 2, 3, 4].map((count) => ({
  value: count,
  label: t('{count} 段 · 约 {seconds} 秒', { count, seconds: count * 15 }),
})))

/** 输入形态：完整小说 vs 一句话 —— 看剧本段首步标签 */
const inputMode = computed<'novel' | 'oneline'>(() => {
  const sw = selected.value?.series_workflow
  if (!sw) return 'oneline'
  const first = graphToSteps(sw.graph, 'series')[0]
  return first && first.label.includes('小说') ? 'novel' : 'oneline'
})

const inputLabel = computed(() => (inputMode.value === 'novel' ? t('完整小说 / 剧本正文') : t('一句话创意')))
const inputPlaceholder = computed(() =>
  inputMode.value === 'novel'
    ? t('把完整的小说 / 剧本正文粘贴到这里，系统会自动拆成多集…')
    : t('用一句话描述你的故事，例如：失忆的总裁爱上送外卖的前妻…'),
)

function reset() {
  selectedId.value = props.preselectId ?? props.bundles.find((b) => Number(b.is_system) === 1)?.id ?? props.bundles[0]?.id ?? null
  input.value = ''
  title.value = ''
  episodeCount.value = null
  promoSegmentCount.value = 3
  visualStyle.value = 'realistic'
  visualStyleVariant.value = ''
  region.value = 'china'
  isNovelImporting.value = false
  isNovelDragOver.value = false
  importedNovelFilename.value = ''
}

watch(() => props.visible, (v) => { if (v) reset() })
watch(() => props.preselectId, (id) => {
  if (!props.visible || !id) return
  selectedId.value = id
})
onMounted(() => {
  if (props.visible) reset()
})
watch(visualStyle, (style) => {
  visualStyleVariant.value = normalizeVisualStyleVariant(style, visualStyleVariant.value)
})

function close() { emit('update:visible', false) }

function submit() {
  if (!selected.value) { ElMessage.warning(t('请选择一个流程')); return }
  if (isNovelImporting.value) { ElMessage.warning(t('文件还在导入中')); return }
  if (!input.value.trim()) { ElMessage.warning(t('请填写{label}', { label: inputLabel.value })); return }
  emit('submit', {
    bundle: selected.value,
    title: title.value.trim(),
    input: input.value.trim(),
    episodeCount: episodeCount.value,
    promoSegmentCount: isPromoWorkflow.value ? promoSegmentCount.value : null,
    visualStyle: visualStyle.value,
    visualStyleVariant: normalizeVisualStyleVariant(visualStyle.value, visualStyleVariant.value),
    region: region.value,
  })
}

function displayText(value?: string | null): string {
  const clean = String(value || '').trim()
  return clean ? t(clean) : ''
}

function flowSections(b: WorkflowBundle): Array<{ key: 'series' | 'split' | 'episode'; label: string; steps: string[] }> {
  const seriesSteps = b.series_workflow ? graphToSteps(b.series_workflow.graph, 'series').map((x) => displayText(x.label)) : []
  const episodeSteps = b.episode_workflow ? graphToSteps(b.episode_workflow.graph, 'episode').map((x) => displayText(x.label)) : []
  const sections: Array<{ key: 'series' | 'split' | 'episode'; label: string; steps: string[] }> = []
  if (seriesSteps.length) {
    sections.push({ key: 'series', label: t('剧本段'), steps: seriesSteps })
    sections.push({ key: 'split', label: t('分集'), steps: [t('拆分剧集')] })
  }
  sections.push({ key: 'episode', label: t('剧集段'), steps: episodeSteps })
  return sections
}

function sourceLabel(b: WorkflowBundle): string {
  return Number(b.is_system) === 1 ? t('官方推荐') : t('自定义')
}

function pickNovelFile() {
  if (inputMode.value !== 'novel' || isNovelImporting.value) return
  novelImportInputRef.value?.click()
}

async function onNovelFileChange(event: Event) {
  const target = event.target as HTMLInputElement
  const file = target.files?.[0]
  target.value = ''
  if (file) {
    await importNovelToInput(file)
  }
}

async function onNovelDrop(event: DragEvent) {
  isNovelDragOver.value = false
  const file = event.dataTransfer?.files?.[0]
  if (file) {
    await importNovelToInput(file)
  }
}

async function importNovelToInput(file: File) {
  const filename = file.name || ''
  const ext = filename.split('.').pop()?.toLowerCase()
  if (ext !== 'txt' && ext !== 'pdf') {
    ElMessage.warning(t('只支持 TXT 和 PDF 文件'))
    return
  }
  if (file.size > 100 * 1024 * 1024) {
    ElMessage.warning(t('文件不能超过 100MB'))
    return
  }

  isNovelImporting.value = true
  try {
    const result = await parseNovelFile(file)
    input.value = result.text
    importedNovelFilename.value = result.filename
    ElMessage.success(t('已导入 {filename}，共 {count} 字', { filename: result.filename, count: result.chars }))
  } catch (error: any) {
    ElMessage.error(error?.message ?? t('导入失败'))
  } finally {
    isNovelImporting.value = false
    isNovelDragOver.value = false
  }
}
</script>

<template>
  <el-dialog
    :model-value="visible"
    :title="t('新建作品')"
    width="min(720px, calc(100vw - 32px))"
    class="custom-dialog new-work-dialog"
    align-center
    append-to-body
    @update:model-value="emit('update:visible', $event)"
  >
    <div class="wizard">
      <!-- ① 选流程 -->
      <section class="step">
        <div class="step__label"><span class="step__num">1</span> {{ t('选择流程') }}</div>
        <div class="flow-list scrollable">
          <section v-if="systemBundles.length" class="flow-group">
            <div class="flow-group__title">
              <span>{{ t('官方流程') }}</span>
              <em>{{ t('系统内置 · 推荐配置') }}</em>
            </div>
            <button
              v-for="b in systemBundles"
              :key="b.id"
              type="button"
              class="flow-card is-system"
              :class="{ active: b.id === selectedId }"
              @click="selectedId = b.id"
            >
              <div class="flow-card__head">
                <span class="flow-card__name">{{ displayText(b.name) }}</span>
                <span class="flow-card__tag">{{ sourceLabel(b) }}</span>
                <el-icon v-if="b.id === selectedId" class="flow-card__check"><Check /></el-icon>
              </div>
              <p v-if="b.description" class="flow-card__desc">{{ displayText(b.description) }}</p>
              <div class="flow-card__sections">
                <div
                  v-for="section in flowSections(b)"
                  :key="section.key"
                  class="flow-section"
                  :class="`flow-section--${section.key}`"
                >
                  <span class="flow-section__label">{{ section.label }}</span>
                  <div class="flow-section__steps">
                    <template v-for="(step, stepIndex) in section.steps" :key="`${section.key}-${stepIndex}`">
                      <span class="chip">{{ step }}</span>
                      <span v-if="stepIndex < section.steps.length - 1" class="sep">›</span>
                    </template>
                  </div>
                </div>
              </div>
            </button>
          </section>

          <section class="flow-group">
            <div class="flow-group__title">
              <span>{{ t('我的流程') }}</span>
              <em>{{ userBundles.length ? t('可查看并编辑提示词') : t('复制官方流程后会出现在这里') }}</em>
            </div>
            <button
              v-for="b in userBundles"
              :key="b.id"
              type="button"
              class="flow-card"
              :class="{ active: b.id === selectedId }"
              @click="selectedId = b.id"
            >
              <div class="flow-card__head">
                <span class="flow-card__name">{{ displayText(b.name) }}</span>
                <span class="flow-card__tag">{{ sourceLabel(b) }}</span>
                <el-icon v-if="b.id === selectedId" class="flow-card__check"><Check /></el-icon>
              </div>
              <p v-if="b.description" class="flow-card__desc">{{ displayText(b.description) }}</p>
              <div class="flow-card__sections">
                <div
                  v-for="section in flowSections(b)"
                  :key="section.key"
                  class="flow-section"
                  :class="`flow-section--${section.key}`"
                >
                  <span class="flow-section__label">{{ section.label }}</span>
                  <div class="flow-section__steps">
                    <template v-for="(step, stepIndex) in section.steps" :key="`${section.key}-${stepIndex}`">
                      <span class="chip">{{ step }}</span>
                      <span v-if="stepIndex < section.steps.length - 1" class="sep">›</span>
                    </template>
                  </div>
                </div>
              </div>
            </button>
            <div v-if="!userBundles.length" class="flow-empty">{{ t('还没有自定义流程') }}</div>
          </section>
        </div>
      </section>

      <!-- ② 输入 -->
      <section v-if="selected" class="step">
        <div class="step__label"><span class="step__num">2</span> {{ inputLabel }}</div>
        <div
          v-if="inputMode === 'novel'"
          class="novel-dropzone"
          :class="{ 'is-over': isNovelDragOver, 'is-loading': isNovelImporting }"
          @click="pickNovelFile"
          @dragenter.prevent="isNovelDragOver = true"
          @dragover.prevent="isNovelDragOver = true"
          @dragleave.prevent="isNovelDragOver = false"
          @drop.prevent="onNovelDrop"
        >
          <input
            ref="novelImportInputRef"
            class="novel-dropzone__input"
            type="file"
            accept=".txt,.pdf,text/plain,application/pdf"
            @change="onNovelFileChange"
          >
          <div class="novel-dropzone__icon">
            <el-icon><UploadFilled /></el-icon>
          </div>
          <div class="novel-dropzone__body">
            <div class="novel-dropzone__title">
              {{ isNovelImporting ? t('正在导入文件...') : t('拖拽 TXT / PDF 到这里，或点击选择文件') }}
            </div>
            <div class="novel-dropzone__meta">
              {{ importedNovelFilename ? t('已导入：{filename}', { filename: importedNovelFilename }) : t('导入后会自动填入下面的小说正文，仍可继续手动编辑。') }}
            </div>
          </div>
        </div>
        <el-input
          v-model="input"
          type="textarea"
          :rows="inputMode === 'novel' ? 8 : 3"
          :autosize="inputMode === 'novel' ? { minRows: 8, maxRows: 14 } : { minRows: 3, maxRows: 7 }"
          resize="vertical"
          :placeholder="inputPlaceholder"
        />
        <div v-if="hasSeries" class="row">
          <span class="row__label">{{ t('目标集数（可选）') }}</span>
          <el-input v-model.number="episodeCount" type="number" min="1" max="100" :placeholder="t('自动')" style="width: 120px" />
        </div>
        <div v-else-if="isPromoWorkflow" class="row">
          <span class="row__label">{{ t('宣传片长度') }}</span>
          <el-segmented
            v-model="promoSegmentCount"
            :options="promoSegmentOptions"
          />
        </div>
      </section>

      <!-- ③ 设置 -->
      <section v-if="selected" class="step">
        <div class="step__label"><span class="step__num">3</span> {{ t('作品设置') }}</div>
        <div class="settings-grid">
          <div class="setting-field setting-field--full">
            <span class="setting-field__label">{{ t('作品名称') }}</span>
            <el-input v-model="title" :placeholder="t('留空则自动命名')" />
          </div>
          <div class="setting-field">
            <span class="setting-field__label">{{ t('画风') }}</span>
            <el-segmented
              v-model="visualStyle"
              class="setting-field__segmented"
              :options="visualStyleSegmentOptions"
            />
          </div>
          <div class="setting-field">
            <span class="setting-field__label">{{ t('地区') }}</span>
            <el-segmented
              v-model="region"
              class="setting-field__segmented"
              :options="regionOptions"
            />
          </div>
          <div class="setting-field setting-field--full">
            <span class="setting-field__label">{{ t('细分风格') }}</span>
            <el-select
              v-model="visualStyleVariant"
              clearable
              filterable
              :placeholder="t('跟随一级风格')"
            >
              <el-option
                v-for="option in currentVisualStyleVariantOptions"
                :key="option.value"
                :label="option.label"
                :value="option.value"
              >
                <div class="style-variant-option">
                  <span>{{ option.label }}</span>
                  <em v-if="option.desc">{{ option.desc }}</em>
                </div>
              </el-option>
            </el-select>
          </div>
        </div>
      </section>
    </div>

    <template #footer>
      <el-button @click="close">{{ t('取消') }}</el-button>
      <el-button type="primary" :disabled="!selected || isNovelImporting" @click="submit">
        <el-icon><VideoPlay /></el-icon><span>{{ t('开始生产') }}</span>
      </el-button>
    </template>
  </el-dialog>
</template>

<style scoped lang="scss">
:global(.new-work-dialog) {
  max-height: calc(100vh - 40px);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

:global(.new-work-dialog .el-dialog__header) {
  flex: 0 0 auto;
  padding: 18px 22px 12px;
  border-bottom: 1px solid var(--hairline);
}

:global(.new-work-dialog .el-dialog__body) {
  flex: 1 1 auto;
  min-height: 0;
  overflow: hidden;
  padding: 0;
}

:global(.new-work-dialog .el-dialog__footer) {
  flex: 0 0 auto;
  padding: 14px 22px;
  border-top: 1px solid var(--hairline);
  background: var(--surface);
}

.wizard {
  max-height: calc(100vh - 178px);
  overflow-y: auto;
  padding: 18px 22px 22px;
  display: flex;
  flex-direction: column;
  gap: var(--space-lg);
}

.wizard::-webkit-scrollbar,
.flow-list::-webkit-scrollbar {
  width: 8px;
}

.wizard::-webkit-scrollbar-track,
.flow-list::-webkit-scrollbar-track {
  background: transparent;
}

.wizard::-webkit-scrollbar-thumb,
.flow-list::-webkit-scrollbar-thumb {
  border: 2px solid transparent;
  border-radius: 999px;
  background: rgba(113, 128, 150, 0.35);
  background-clip: padding-box;
}

.step {
  min-width: 0;
}

.step__label {
  display: flex; align-items: center; gap: 8px;
  font-size: var(--text-title-sm-size); font-weight: 700; color: var(--on-dark);
  margin-bottom: var(--space-sm);
}
.step__num {
  width: 20px; height: 20px; display: flex; align-items: center; justify-content: center;
  border-radius: var(--radius-full); background: var(--primary); color: var(--on-primary);
  font-size: 12px; font-weight: 700;
}

.flow-list {
  max-height: min(360px, 42vh);
  overflow-y: auto;
  padding-right: 4px;
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
}

.flow-group {
  display: flex;
  flex-direction: column;
  gap: var(--space-sm);
}

.flow-group__title {
  display: flex;
  align-items: baseline;
  gap: 8px;
  font-size: 12px;
  font-weight: 700;
  color: var(--on-dark);

  em {
    font-style: normal;
    font-weight: 500;
    color: var(--muted);
  }
}

.flow-empty {
  padding: 12px 14px;
  border: 1px dashed var(--hairline);
  border-radius: var(--radius-md);
  color: var(--muted);
  font-size: 12px;
  background: var(--surface-soft);
}

.flow-card {
  text-align: left; width: 100%;
  padding: var(--space-md);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-soft);
  cursor: pointer;
  transition: all var(--duration-fast);
  &:hover { border-color: var(--hairline-strong); }
  &.active { border-color: var(--primary); background: rgba(var(--primary-rgb), 0.06); }
  &.is-system { background: rgba(125, 84, 255, 0.05); }
}
.flow-card__head { display: flex; align-items: center; justify-content: space-between; gap: 12px; }
.flow-card__name { font-size: 14px; font-weight: 700; color: var(--on-dark); }
.flow-card__check { color: var(--primary); }
.flow-card__tag {
  margin-left: auto;
  padding: 1px 7px;
  border-radius: var(--radius-pill);
  font-size: 10px;
  font-weight: 700;
  color: var(--muted);
  border: 1px solid var(--hairline);
}
.flow-card__desc {
  margin: 6px 0 0;
  font-size: 12px;
  color: var(--muted);
  line-height: 1.5;
}

.flow-card__sections {
  margin-top: 10px;
  display: grid;
  gap: 8px;
}

.flow-section {
  display: grid;
  grid-template-columns: 92px minmax(0, 1fr);
  gap: 8px;
  align-items: start;
}

.flow-section__label {
  width: 100%;
  min-height: 22px;
  padding: 3px 7px;
  border-radius: var(--radius-sm);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  text-align: center;
  font-size: 11px;
  font-weight: 800;
  line-height: 1.2;
  white-space: nowrap;
}

.flow-section__steps {
  min-width: 0;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px;
}

.flow-card .chip {
  max-width: 100%;
  padding: 2px 7px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--hairline);
  background: var(--surface-elevated);
  color: var(--body);
  font-size: 11px;
  line-height: 1.45;
  overflow: hidden;
  text-overflow: ellipsis;
}

.flow-section--series .flow-section__label {
  color: #1d4ed8;
  background: rgba(37, 99, 235, 0.09);
}

.flow-section--series .chip {
  border-color: rgba(37, 99, 235, 0.16);
  background: rgba(37, 99, 235, 0.06);
}

.flow-section--split .flow-section__label {
  color: #b45309;
  background: rgba(245, 158, 11, 0.12);
}

.flow-section--split .chip {
  border-color: rgba(245, 158, 11, 0.2);
  background: rgba(245, 158, 11, 0.08);
  color: #92400e;
}

.flow-section--episode .flow-section__label {
  color: #047857;
  background: rgba(16, 185, 129, 0.1);
}

.flow-section--episode .chip {
  border-color: rgba(16, 185, 129, 0.18);
  background: rgba(16, 185, 129, 0.07);
}
.flow-card .sep { color: var(--muted-soft); font-size: 11px; }

.row { display: flex; align-items: center; gap: 8px; margin-top: var(--space-sm); flex-wrap: wrap; }
.row__label { font-size: 12px; color: var(--muted); white-space: nowrap; }

.novel-dropzone {
  width: 100%;
  min-height: 90px;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px;
  margin-bottom: 12px;
  border: 1px dashed var(--hairline-strong);
  border-radius: var(--radius-md);
  background: var(--surface-soft);
  cursor: pointer;
  transition: border-color var(--duration-fast), background var(--duration-fast);
}

.novel-dropzone:hover,
.novel-dropzone.is-over {
  border-color: var(--primary);
  background: rgba(var(--primary-rgb), 0.06);
}

.novel-dropzone.is-loading {
  cursor: progress;
  opacity: 0.78;
}

.novel-dropzone__input {
  display: none;
}

.novel-dropzone__icon {
  flex: 0 0 42px;
  width: 42px;
  height: 42px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-md);
  background: var(--surface-elevated);
  color: var(--primary);
  font-size: 22px;
}

.novel-dropzone__body {
  min-width: 0;
}

.novel-dropzone__title {
  color: var(--on-dark);
  font-size: 14px;
  font-weight: 700;
  line-height: 1.4;
}

.novel-dropzone__meta {
  margin-top: 4px;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.5;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.settings-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.1fr) minmax(0, 0.9fr);
  gap: 14px 16px;
  margin-top: var(--space-sm);
}

.setting-field {
  min-width: 0;
  display: grid;
  gap: 7px;
}

.setting-field--full {
  grid-column: 1 / -1;
}

.setting-field__label {
  color: var(--muted);
  font-size: 12px;
  line-height: 1.2;
  white-space: nowrap;
}

.setting-field__segmented {
  width: 100%;
}

.setting-field__segmented :deep(.el-segmented__group) {
  width: 100%;
}

.setting-field__segmented :deep(.el-segmented__item) {
  flex: 1 1 0;
  min-width: 0;
}

.setting-field__segmented :deep(.el-segmented__item-label) {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.style-variant-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  width: 100%;

  em {
    flex: 1;
    min-width: 0;
    color: var(--muted);
    font-size: 12px;
    font-style: normal;
    overflow: hidden;
    text-align: right;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}

@media (max-width: 720px) {
  :global(.new-work-dialog) {
    width: calc(100vw - 20px) !important;
    max-height: calc(100vh - 20px);
    margin: 10px auto !important;
  }

  .wizard {
    max-height: calc(100vh - 158px);
    padding: 14px;
  }

  .flow-list {
    max-height: 34vh;
  }

  .flow-section {
    grid-template-columns: 1fr;
    gap: 5px;
  }

  .flow-section__label {
    width: fit-content;
    justify-content: flex-start;
  }

  .settings-grid {
    grid-template-columns: 1fr;
  }

  .novel-dropzone {
    align-items: flex-start;
  }

  .novel-dropzone__meta {
    white-space: normal;
  }
}
</style>
