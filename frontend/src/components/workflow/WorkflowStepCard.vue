<script setup lang="ts">
import { computed, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { useI18n } from 'vue-i18n'
import AdvancedSection from '@/components/workflow/AdvancedSection.vue'
import { kindLabel as kindLabelOf, type WorkflowStep } from '@/utils/workflowGraph'
import { canEditNodePrompt, isAssetExtractionNode, isSystemManagedPromptNode, promptTemplatesForNode } from '@/utils/workflowPrompts'
import { resolveIcon } from '@/utils/iconRegistry'
import {
  pickAllowedResolution,
  videoAspectRatioOptions,
  videoResolutionOptions,
} from '@/utils/videoModelOptions'
import type { PromptTemplate, WorkflowScope } from '@/types'

type WorkflowModelOption = {
  id: number
  name: string
  endpoint?: string
  model_id?: string
  options?: Record<string, unknown>
}

interface Props {
  step: WorkflowStep
  scope: WorkflowScope
  index: number
  total: number
  models: WorkflowModelOption[]
  promptTemplates: PromptTemplate[]
}

const props = defineProps<Props>()
const { t } = useI18n()
const emit = defineEmits<{
  (e: 'remove'): void
  (e: 'move-up'): void
  (e: 'move-down'): void
  (e: 'save-template', payload: {
    kind: string
    label: string
    prompt: string
    templateId?: number | null
    templateTitle?: string
    templateDescription?: string
    templateTags?: string[]
    templateIsSystem?: boolean
  }): void
}>()

const isInput = computed(() => props.step.kind === 'input')
const isOutput = computed(() => props.step.kind === 'output')
const isIO = computed(() => isInput.value || isOutput.value)
const isVideo = computed(() => props.step.kind === 'video')
const isSeriesOutput = computed(() => isOutput.value && props.scope === 'series')
const hasModel = computed(() => ['text', 'image', 'video', 'voice'].includes(props.step.kind))
const selectedModel = computed(() => {
  if (!hasModel.value) return null
  const modelId = Number(props.step.params.modelId ?? 0)
  if (modelId > 0) {
    return props.models.find((model) => model.id === modelId) ?? null
  }
  return null
})
const selectedVideoModel = computed(() => {
  if (!isVideo.value) return null
  return selectedModel.value ?? props.models[0] ?? null
})
const videoResolutionChoices = computed(() => videoResolutionOptions(selectedVideoModel.value))
const videoAspectRatioChoices = computed(() => videoAspectRatioOptions(selectedVideoModel.value))
const promptAvailable = computed(() =>
  canEditNodePrompt(props.scope, props.step.kind, props.step.label, String(props.step.params.executionMode ?? '')),
)

/** 失效 modelId（已删除模型）会导致 el-select 直接显示数字；统一纠正。 */
watch(
  () => [hasModel.value, props.step.params.modelId, props.models] as const,
  () => {
    if (!hasModel.value) return
    const raw = props.step.params.modelId
    if (raw === null || raw === undefined || raw === '') return
    const modelId = Number(raw)
    if (!Number.isFinite(modelId) || modelId <= 0) {
      props.step.params.modelId = null
      return
    }
    const exists = props.models.some((model) => model.id === modelId)
    if (exists) return
    if (isVideo.value && props.models[0]) {
      props.step.params.modelId = props.models[0].id
      return
    }
    props.step.params.modelId = null
  },
  { immediate: true },
)

watch(
  [isVideo, selectedVideoModel, videoResolutionChoices, videoAspectRatioChoices],
  () => {
    if (!isVideo.value) return
    props.step.params.resolution = pickAllowedResolution(
      String(props.step.params.resolution ?? ''),
      selectedVideoModel.value,
    )
    const aspect = String(props.step.params.aspect_ratio ?? '').trim()
    if (aspect && !videoAspectRatioChoices.value.includes(aspect)) {
      props.step.params.aspect_ratio = videoAspectRatioChoices.value[0] ?? '16:9'
    }
  },
  { immediate: true },
)
const isAssetExtractionStep = computed(() =>
  isAssetExtractionNode(props.scope, props.step.kind, props.step.label),
)
const hasAdvancedSettings = computed(() => {
  if (isAssetExtractionStep.value) return false
  if (isVideo.value) return true
  return props.scope === 'series' && props.step.kind === 'text'
})
const systemManagedPrompt = computed(() =>
  isSystemManagedPromptNode(props.scope, props.step.kind, props.step.label),
)

const kindText = computed(() => t(kindLabelOf(props.step.kind)))
const modelGroupLabel = computed(() => t(({ text: '文本', image: '图片', video: '视频', voice: '语音' } as Record<string, string>)[props.step.kind] ?? ''))
const stepDisplayLabel = computed(() => t(props.step.label))

const canMoveUp = computed(() => !isIO.value && props.index > 1)
const canMoveDown = computed(() => !isIO.value && props.index < props.total - 2)
const canRemove = computed(() => !isIO.value)

const templateOptions = computed<PromptTemplate[]>(() =>
  promptTemplatesForNode(props.promptTemplates, props.scope, props.step.kind, props.step.label),
)
const selectedTemplate = computed(() => {
  const id = Number(props.step.params.promptTemplateId ?? props.step.params.prompt_template_id ?? 0)
  return id ? props.promptTemplates.find((t) => t.id === id) ?? null : null
})
const selectedSystemTemplate = computed(() =>
  selectedTemplate.value && Number(selectedTemplate.value.is_system) === 1 ? selectedTemplate.value : null,
)
const selectedUserTemplate = computed(() =>
  selectedTemplate.value && Number(selectedTemplate.value.is_system) !== 1 ? selectedTemplate.value : null,
)
function isCustomPromptEnabled(value: unknown): boolean {
  return value === true || value === 1 || value === '1' || value === 'true'
}

const customPromptEnabled = computed(() => isCustomPromptEnabled(props.step.params.customPromptEnabled))
const selectedTemplatePrompt = computed(() => String(selectedTemplate.value?.prompt ?? ''))
const promptValue = computed({
  get: () => {
    if (selectedTemplate.value && !customPromptEnabled.value) {
      return selectedTemplatePrompt.value
    }
    const direct = String(props.step.params.prompt ?? '')
    if (direct.trim() !== '') {
      return direct
    }
    if (selectedSystemTemplate.value) {
      return selectedSystemTemplate.value.prompt
    }
    return direct
  },
  set: (value: string) => {
    props.step.params.prompt = value
  },
})
const promptReadonly = computed(() => {
  if (!promptAvailable.value) return true
  if (selectedTemplate.value && !customPromptEnabled.value) return true
  return systemManagedPrompt.value && !customPromptEnabled.value && !selectedUserTemplate.value
})
const canPromoteToCustom = computed(() =>
  promptReadonly.value && (systemManagedPrompt.value || !!selectedTemplate.value),
)
const saveTemplateButtonLabel = computed(() =>
  selectedUserTemplate.value ? t('更新我的词库') : t('保存到我的词库'),
)

function displayTemplateTitle(template: PromptTemplate | null | undefined): string {
  if (!template) return ''
  return Number(template.is_system) === 1 ? t(template.title) : template.title
}

function applyTemplate(templateId: number | string | null) {
  if (!promptAvailable.value) return
  const id = Number(templateId)
  if (!id) {
    props.step.params.promptTemplateId = null
    props.step.params.prompt_template_id = null
    return
  }
  const tpl = props.promptTemplates.find((t) => t.id === id)
  if (!tpl) return
  props.step.params.promptTemplateId = tpl.id
  props.step.params.prompt_template_id = tpl.id
  props.step.params.customPromptEnabled = false
  props.step.params.prompt = tpl.prompt
  ElMessage.success(t('已绑定当前词库；如需改写，请先转为自定义'))
}

function enableCustomPrompt() {
  if (!promptAvailable.value) return
  const basePrompt = String(promptValue.value || '').trim()
  if (!basePrompt) {
    ElMessage.warning(t('当前没有可用于自定义的提示词正文'))
    return
  }
  props.step.params.prompt = basePrompt
  props.step.params.customPromptEnabled = true
  if (selectedTemplate.value) {
    props.step.params.promptTemplateId = null
    props.step.params.prompt_template_id = null
  }
  ElMessage.success(t('已切换为自定义提示词'))
}

function onSaveTemplate() {
  if (!promptAvailable.value || promptReadonly.value) return
  const prompt = String(promptValue.value ?? '').trim()
  if (!prompt) {
    ElMessage.warning(t('当前步骤还没有指令内容'))
    return
  }
  emit('save-template', {
    kind: props.step.kind,
    label: props.step.label,
    prompt,
    templateId: selectedUserTemplate.value?.id ?? null,
    templateTitle: selectedUserTemplate.value?.title ?? selectedSystemTemplate.value?.title ?? '',
    templateDescription: selectedUserTemplate.value?.description ?? selectedSystemTemplate.value?.description ?? '',
    templateTags: selectedUserTemplate.value?.tags ?? selectedSystemTemplate.value?.tags ?? [],
    templateIsSystem: Boolean(selectedSystemTemplate.value),
  })
}
</script>

<template>
  <div class="step-card" :class="`step-card--${step.kind}`">
    <div class="step-card__bar" />
    <div class="step-card__main">
      <!-- Header -->
      <div class="step-card__head">
        <span class="step-card__index">{{ index + 1 }}</span>
        <el-icon class="step-card__icon"><component :is="resolveIcon(step.icon)" /></el-icon>
        <input
          v-if="step.isCustom"
          v-model="step.label"
          class="step-card__name-input"
          :placeholder="t('步骤名称')"
        />
        <span v-else class="step-card__name">{{ stepDisplayLabel }}</span>
        <span class="step-card__kind">{{ kindText }}</span>

        <div class="step-card__actions">
          <button v-if="canMoveUp" class="step-icon-btn" :title="t('上移')" @click="emit('move-up')">
            <el-icon><Top /></el-icon>
          </button>
          <button v-if="canMoveDown" class="step-icon-btn" :title="t('下移')" @click="emit('move-down')">
            <el-icon><Bottom /></el-icon>
          </button>
          <button v-if="canRemove" class="step-icon-btn danger" :title="t('删除步骤')" @click="emit('remove')">
            <el-icon><Delete /></el-icon>
          </button>
        </div>
      </div>

      <!-- Body -->
      <div class="step-card__body">
        <!-- INPUT -->
        <template v-if="isInput">
          <p class="step-hint">{{ t('流水线入口。剧本正文或单集剧情会在执行时注入，这一步不需要配置提示词。') }}</p>
        </template>

        <!-- OUTPUT -->
        <template v-else-if="isOutput">
          <template v-if="isSeriesOutput">
            <p class="step-hint">{{ t('把上游分集结果写入剧集管理；资产会在每集扩写后增量补齐。这一步由系统写入，不需要配置提示词。') }}</p>
          </template>
          <template v-else>
            <p class="step-hint">
              {{ t('导出为') }} <strong>{{ (step.params.format || 'mp4').toUpperCase() }}</strong>
              · {{ t('分辨率跟随各镜头生成视频，拼接导出不可单独指定') }}
            </p>
            <AdvancedSection :title="t('导出参数')">
              <el-form label-position="top" size="default">
                <el-form-item :label="t('文件格式')">
                  <el-select v-model="step.params.format" style="width: 100%">
                    <el-option value="mp4" :label="t('MP4 · H.264 通用')" />
                    <el-option value="mov" :label="t('MOV · ProRes 高清')" />
                    <el-option value="webm" :label="t('WebM · VP9 网页')" />
                    <el-option value="zip" :label="t('ZIP · 原始素材包')" />
                  </el-select>
                </el-form-item>
              </el-form>
            </AdvancedSection>
          </template>
        </template>

        <!-- AI steps (text/image/video/voice) -->
        <template v-else>
          <el-form label-position="top" size="default">
            <el-form-item v-if="hasModel" :label="t('{type}模型', { type: modelGroupLabel })" class="mb-2">
              <el-select v-model="step.params.modelId" :placeholder="t('使用默认模型')" clearable style="width: 100%">
                <el-option v-for="m in models" :key="m.id" :label="m.name" :value="m.id" />
              </el-select>
              <p v-if="models.length === 0" class="step-hint error">{{ t('暂无可用模型，请联系管理员配置') }}</p>
            </el-form-item>

            <p v-if="isAssetExtractionStep" class="step-hint asset-node-note">
              {{ t('该节点会自动读取上游剧情或分镜内容，提取并合并人物、场景、道具和人物造型资产，供后续生图与视频节点引用。这里只需选择文本模型；模型能力会影响资产识别、去重合并、描述完整度，以及后续参考图生成的一致性。') }}
            </p>

            <p v-else-if="systemManagedPrompt && step.kind === 'text'" class="step-hint system-managed-hint">
              {{ t('该步骤默认使用系统内置规则执行。你可以查看系统提示词，并在需要时转为自定义覆盖。') }}
            </p>

            <div v-if="promptAvailable && !isAssetExtractionStep" class="prompt-tools">
              <el-form-item :label="t('从词库加载')" class="mb-2">
                <el-select
                  v-model="step.params.promptTemplateId"
                  :placeholder="t('选择已保存的提示词...')"
                  filterable
                  clearable
                  style="width: 100%"
                  @change="applyTemplate"
                >
                  <el-option v-for="t in templateOptions" :key="t.id" :label="displayTemplateTitle(t)" :value="t.id">
                    <div class="template-option">
                      <span>{{ displayTemplateTitle(t) }}</span>
                      <span v-if="Number(t.is_system) === 1" class="template-option__badge">{{ $t('官方') }}</span>
                    </div>
                  </el-option>
                </el-select>
                <p v-if="selectedSystemTemplate" class="step-hint template-hint">
                  {{ t('当前正在查看官方词库「{name}」。正文可见但不可直接修改；点击“转为自定义”后可在此基础上编辑并保存为你的词库。', { name: displayTemplateTitle(selectedSystemTemplate) }) }}
                </p>
                <p v-else-if="systemManagedPrompt && promptReadonly" class="step-hint template-hint">
                  {{ t('当前显示的是系统默认提示词。你可以先查看，再转为自定义覆盖。') }}
                </p>
              </el-form-item>
            </div>

            <el-form-item v-if="promptAvailable && !isAssetExtractionStep" :label="t('提示词 / 指令')" class="mb-2">
              <el-input
                v-model="promptValue"
                type="textarea"
                :rows="4"
                :readonly="promptReadonly"
                :placeholder="step.params.executionMode === 'local' ? t('本地解析模式不需要提示词，可留空') : t('一句话描述这一步要做什么...')"
              />
            </el-form-item>
            <div v-if="promptAvailable && !isAssetExtractionStep" class="prompt-actions">
              <button v-if="canPromoteToCustom" type="button" class="save-tpl-btn prompt-save-btn" @click="enableCustomPrompt">
                <el-icon><EditPen /></el-icon><span>{{ t('转为自定义') }}</span>
              </button>
              <button v-if="!promptReadonly" type="button" class="save-tpl-btn prompt-save-btn" @click="onSaveTemplate">
                <el-icon><FolderAdd /></el-icon><span>{{ saveTemplateButtonLabel }}</span>
              </button>
            </div>

            <AdvancedSection v-if="hasAdvancedSettings">
              <!-- 执行方式（剧本文本） -->
              <el-form-item v-if="scope === 'series' && step.kind === 'text'" :label="t('执行方式')" class="mb-3">
                <el-segmented
                  v-model="step.params.executionMode"
                  :options="[{ label: 'AI', value: 'ai' }, { label: t('本地解析'), value: 'local' }]"
                />
                <p class="step-hint">{{ t('本地解析适合已按 EPISODE 分好的完整剧本。') }}</p>
              </el-form-item>

              <!-- 视频参数 -->
              <template v-if="isVideo">
                <el-form-item :label="t('视频前置提示词')" class="mb-3">
                  <el-input
                    v-model="step.params.video_style_prompt"
                    type="textarea"
                    :rows="5"
                    :placeholder="t('可选。用于统一视频画面风格、摄影语言、色彩限制、时代背景、地区人文环境、禁用元素等；留空时使用系统默认视频风格。')"
                  />
                  <p class="step-hint">
                    {{ t('填写后优先作为视频风格与人文环境前置提示词，不替代上游分镜内容、参考图一致性或时长参数。') }}
                  </p>
                </el-form-item>
                <div class="video-grid">
                  <el-form-item :label="t('画幅比例')">
                    <el-select v-model="step.params.aspect_ratio" style="width: 100%">
                      <el-option
                        v-for="ratio in videoAspectRatioChoices"
                        :key="ratio"
                        :value="ratio"
                        :label="ratio === '16:9' ? t('16:9 横屏') : ratio === '9:16' ? t('9:16 竖屏') : ratio === '1:1' ? t('1:1 方形') : ratio"
                      />
                    </el-select>
                  </el-form-item>
                  <el-form-item :label="t('分辨率')">
                    <el-select v-model="step.params.resolution" style="width: 100%">
                      <el-option
                        v-for="resolution in videoResolutionChoices"
                        :key="resolution"
                        :value="resolution"
                        :label="resolution"
                      />
                    </el-select>
                  </el-form-item>
                  <el-form-item :label="t('时长')">
                    <el-select v-model="step.params.duration" style="width: 100%">
                      <el-option :value="0" :label="t('自动（推荐）')" />
                      <el-option v-for="s in [5,6,7,8,9,10,11,12,13,14,15]" :key="s" :label="t('{count} 秒', { count: s })" :value="s" />
                    </el-select>
                  </el-form-item>
                </div>
                <div class="video-switches">
                  <label class="switch-row"><span>{{ t('联网搜索') }}</span><el-switch v-model="step.params.enable_web_search" /></label>
                  <label class="switch-row"><span>{{ t('镜头衔接') }}</span><el-switch v-model="step.params.chainShots" /></label>
                  <label class="switch-row"><span>{{ t('生成音频') }}</span><el-switch v-model="step.params.generate_audio" /></label>
                </div>
              </template>
            </AdvancedSection>
          </el-form>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.step-card {
  position: relative;
  display: flex;
  background: var(--surface-card);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  overflow: hidden;
  box-shadow: 0 1px 2px rgba(16, 32, 51, 0.03);
}

.step-card__bar {
  width: 3px;
  flex-shrink: 0;
  background: var(--muted-soft);
}
.step-card--input .step-card__bar,
.step-card--output .step-card__bar { background: var(--primary); }
.step-card--text .step-card__bar { background: var(--accent-blue); }
.step-card--image .step-card__bar { background: var(--accent-amber); }
.step-card--video .step-card__bar { background: var(--accent-lilac); }
.step-card--voice .step-card__bar { background: var(--accent-emerald); }

.step-card__main {
  flex: 1;
  min-width: 0;
  padding: var(--space-md);
}

.step-card__head {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
}

.step-card__index {
  width: 22px;
  height: 22px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: var(--radius-sm);
  background: var(--surface-elevated);
  border: 1px solid var(--hairline-strong);
  color: var(--body-strong);
  font-size: 12px;
  font-weight: 700;
}

.step-card__icon { color: var(--body); flex-shrink: 0; }

.step-card__name {
  font-size: var(--text-title-sm-size);
  font-weight: 600;
  color: var(--on-dark);
}

.step-card__name-input {
  flex: 1;
  min-width: 0;
  max-width: 200px;
  background: var(--surface-soft);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  padding: 4px 8px;
  color: var(--on-dark);
  font-size: var(--text-title-sm-size);
  font-weight: 600;
  outline: none;

  &:focus { border-color: var(--accent-cyan); }
}

.step-card__kind {
  padding: 1px 7px;
  border-radius: var(--radius-sm);
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.5px;
  background: var(--surface-elevated);
  color: var(--muted);
  border: 1px solid var(--hairline);
}

.step-card__actions {
  margin-left: auto;
  display: flex;
  gap: 4px;
}

.step-icon-btn {
  width: 26px;
  height: 26px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: none;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--muted);
  cursor: pointer;
  transition: all var(--duration-fast);

  &:hover { color: var(--on-dark); background: var(--surface-elevated); }
  &.danger:hover { color: var(--accent-rose); }
}

.step-card__body { margin-top: var(--space-sm); }

.step-hint {
  margin: 0 0 var(--space-xs);
  font-size: 12px;
  color: var(--muted);
  line-height: 1.5;

  &.error { color: var(--accent-rose); }
}

.asset-node-note {
  margin-top: -2px;
  padding: 10px 12px;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-soft);
}

.video-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: var(--space-sm);
}

.video-grid > * { min-width: 0; }

@media (max-width: 720px) {
  .video-grid { grid-template-columns: 1fr; }
}

.video-switches {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-md);
  margin: var(--space-xs) 0 var(--space-sm);
}

.switch-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: var(--body);
}

.prompt-tools {
  padding: var(--space-sm);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-soft);
  margin-bottom: var(--space-sm);
}

.template-option {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-sm);
  width: 100%;
}

.template-option__badge {
  padding: 0 6px;
  border-radius: var(--radius-pill);
  background: rgba(124, 58, 237, 0.1);
  color: #6d28d9;
  font-size: 10px;
  font-weight: 700;
}

.template-hint {
  margin: 6px 0 0;
  color: var(--muted);
}

.save-tpl-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border: 1px solid var(--hairline-strong);
  border-radius: var(--radius-md);
  background: transparent;
  color: var(--body);
  font-size: 12px;
  cursor: pointer;

  &:hover { color: var(--on-dark); border-color: var(--muted); }
}

.prompt-save-btn {
  margin: -2px 0 var(--space-md);
}

.prompt-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin: -2px 0 var(--space-md);
}

.mb-2 { margin-bottom: var(--space-sm); }
.mb-3 { margin-bottom: var(--space-md); }
</style>
