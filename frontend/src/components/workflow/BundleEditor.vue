<script setup lang="ts">
import { ref, computed, watch, nextTick } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import WorkflowStepList from '@/components/workflow/WorkflowStepList.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import { graphToSteps, stepsToGraph, validateSteps, type WorkflowStep } from '@/utils/workflowGraph'
import { updateWorkflow } from '@/api/workflow'
import { createBundle, updateBundle } from '@/api/workflowBundle'
import { createPromptTemplate, updatePromptTemplate } from '@/api/promptTemplate'
import { canEditNodePrompt, isAssetExtractionNode } from '@/utils/workflowPrompts'
import type { CustomNodeBlueprint } from '@/api/customNode'
import type { ModelType, PromptTemplate, WorkflowBundle, WorkflowScope } from '@/types'

type WorkflowModelOption = {
  id: number
  name: string
  endpoint?: string
  model_id?: string
  options?: Record<string, unknown>
}

interface Props {
  bundle: WorkflowBundle
  modelOptions: Record<ModelType, WorkflowModelOption[]>
  promptTemplates: PromptTemplate[]
  customNodes: CustomNodeBlueprint[]
  draftFromBundleId?: number | null
}

const props = defineProps<Props>()
const { t } = useI18n()
const emit = defineEmits<{
  (e: 'back'): void
  (e: 'saved', bundle: WorkflowBundle): void
  (e: 'template-created', template: PromptTemplate): void
}>()

const name = ref(props.bundle.name)
const description = ref(props.bundle.description)
const seriesSteps = ref<WorkflowStep[] | null>(
  props.bundle.series_workflow ? graphToSteps(props.bundle.series_workflow.graph, 'series') : null,
)
const episodeSteps = ref<WorkflowStep[]>(
  props.bundle.episode_workflow ? graphToSteps(props.bundle.episode_workflow.graph, 'episode') : [],
)
const isSaving = ref(false)
const isDirty = ref(false)
const isDraft = computed(() => (props.draftFromBundleId ?? 0) > 0 || props.bundle.id <= 0)

function markDirty() { isDirty.value = true }

let ready = false
nextTick(() => { ready = true })
watch([name, description], () => { if (ready) isDirty.value = true })
watch([seriesSteps, episodeSteps], () => { if (ready) isDirty.value = true }, { deep: true })

const hasSeries = computed(() => !!props.bundle.series_workflow && seriesSteps.value !== null)

// ── prompt library ───────────────────────────────────────────────────────────
async function onSaveTemplate(payload: {
  kind: string
  label: string
  prompt: string
  templateId?: number | null
  templateTitle?: string
  templateDescription?: string
  templateTags?: string[]
  templateIsSystem?: boolean
}, scope: WorkflowScope) {
  try {
    if ((payload.templateId ?? 0) > 0 && !payload.templateIsSystem) {
      const updated = await updatePromptTemplate(Number(payload.templateId), {
        title: String(payload.templateTitle || payload.label || '步骤指令').trim(),
        scope,
        node_kind: payload.kind as PromptTemplate['node_kind'],
        node_label: payload.label,
        description: String(payload.templateDescription || '').trim(),
        prompt: payload.prompt,
        tags: Array.isArray(payload.templateTags) && payload.templateTags.length > 0
          ? payload.templateTags
          : [payload.label].filter(Boolean),
      })
      emit('template-created', updated)
      ElMessage.success(t('我的词库已更新'))
      return
    }

    const result = await ElMessageBox.prompt(t('给这条提示词起个名称'), t('保存到我的词库'), {
      confirmButtonText: t('保存'),
      cancelButtonText: t('取消'),
      inputValue: payload.templateIsSystem
        ? t('{name} - 我的版本', { name: payload.templateTitle || payload.label || t('系统提示词') })
        : t('{name}指令', { name: payload.templateTitle || payload.label || t('步骤') }),
      inputValidator: (v) => (v?.trim() ? true : t('名称不能为空')),
    })
    const created = await createPromptTemplate({
      title: String(result.value).trim(),
      scope,
      node_kind: payload.kind as PromptTemplate['node_kind'],
      node_label: payload.label,
      description: String(payload.templateDescription || '').trim(),
      prompt: payload.prompt,
      tags: Array.isArray(payload.templateTags) && payload.templateTags.length > 0
        ? payload.templateTags
        : [payload.label].filter(Boolean),
    })
    emit('template-created', created)
    ElMessage.success(t('已保存到我的词库'))
  } catch { /* cancelled */ }
}

// ── save ─────────────────────────────────────────────────────────────────────
function missingPrompt(steps: WorkflowStep[], scope: WorkflowScope): WorkflowStep | null {
  for (const s of steps) {
    if (isAssetExtractionNode(scope, s.kind, s.label)) continue
    if (!canEditNodePrompt(scope, s.kind, s.label, String(s.params.executionMode ?? ''))) continue
    if (Number(s.params.promptTemplateId ?? s.params.prompt_template_id ?? 0) > 0) continue
    if (!String(s.params.prompt ?? '').trim()) return s
  }
  return null
}

function isCustomPromptEnabled(value: unknown): boolean {
  return value === true || value === 1 || value === '1' || value === 'true'
}

function sanitizeStepsForSave(steps: WorkflowStep[], scope: WorkflowScope): WorkflowStep[] {
  return steps.map((step) => {
    const params = { ...step.params }
    if (!canEditNodePrompt(scope, step.kind, step.label, String(params.executionMode ?? ''))) {
      params.prompt = ''
      params.promptTemplateId = null
      params.prompt_template_id = null
      params.customPromptEnabled = false
      return { ...step, params }
    }

    const templateId = Number(params.promptTemplateId ?? params.prompt_template_id ?? 0)
    if (templateId > 0) {
      params.promptTemplateId = templateId
      params.prompt_template_id = templateId
      params.customPromptEnabled = isCustomPromptEnabled(params.customPromptEnabled)
      if (!params.customPromptEnabled) {
        params.prompt = ''
      }
    } else {
      params.promptTemplateId = null
      params.prompt_template_id = null
    }
    return { ...step, params }
  })
}

async function save() {
  if (!name.value.trim()) {
    ElMessage.warning(t('请填写流程名称'))
    return
  }
  const structureError = (hasSeries.value && seriesSteps.value
    ? validateSteps(seriesSteps.value, 'series')
    : null)
    ?? validateSteps(episodeSteps.value, 'episode')
  if (structureError) {
    await ElMessageBox.alert(structureError, t('流程无法闭环'), {
      type: 'warning',
      confirmButtonText: t('知道了'),
    })
    return
  }
  const missing = (hasSeries.value && seriesSteps.value ? missingPrompt(seriesSteps.value, 'series') : null)
    ?? missingPrompt(episodeSteps.value, 'episode')
  if (missing) {
    await ElMessageBox.alert(t('请先补全「{label}」的指令内容', { label: t(missing.label) }), t('还不能保存'), {
      type: 'warning',
      confirmButtonText: t('知道了'),
    })
    return
  }

  isSaving.value = true
  try {
    let targetBundle = props.bundle
    if (isDraft.value) {
      targetBundle = await createBundle({
        name: name.value.trim(),
        description: description.value.trim(),
        from_bundle_id: props.draftFromBundleId ?? undefined,
        include_series: true,
      })
    }

    const ep = targetBundle.episode_workflow
    if (ep) {
      await updateWorkflow(ep.id, {
        name: ep.name,
        description: '',
        graph: stepsToGraph(sanitizeStepsForSave(episodeSteps.value, 'episode')) as any,
        viewport: { x: 0, y: 0, zoom: 1 },
        is_default: ep.is_default,
        scope: 'episode',
      })
    }
    const sr = targetBundle.series_workflow
    if (sr && seriesSteps.value) {
      await updateWorkflow(sr.id, {
        name: sr.name,
        description: '',
        graph: stepsToGraph(sanitizeStepsForSave(seriesSteps.value, 'series')) as any,
        viewport: { x: 0, y: 0, zoom: 1 },
        is_default: sr.is_default,
        scope: 'series',
      })
    }
    const updated = await updateBundle(targetBundle.id, {
      name: name.value.trim(),
      description: description.value.trim(),
    })
    isDirty.value = false
    ElMessage.success(isDraft.value ? t('流程已创建') : t('修改已保存'))
    emit('saved', updated)
  } catch (error: any) {
    await ElMessageBox.alert(error?.message ?? t('保存时遇到问题，请稍后再试'), t('保存失败'), {
      type: 'error',
      confirmButtonText: t('知道了'),
    })
  } finally {
    isSaving.value = false
  }
}

async function back() {
  if (isDirty.value) {
    try {
      await ElMessageBox.confirm(t('有未保存的改动，确定离开？'), t('返回库'), {
        confirmButtonText: t('放弃改动'),
        cancelButtonText: t('继续编辑'),
        type: 'warning',
      })
    } catch { return }
  }
  emit('back')
}
</script>

<template>
  <div class="bundle-editor">
    <header class="be-bar">
      <button class="bar-back" @click="back">
        <el-icon><ArrowLeft /></el-icon><span>{{ t('返回库') }}</span>
      </button>
      <div class="bar-title">
        <label class="bar-field bar-field--name">
          <span>{{ t('流程名称') }}</span>
          <input v-model="name" class="bar-name" :placeholder="t('完整流程名称')" @input="markDirty" />
        </label>
        <label class="bar-field">
          <span>{{ t('流程描述') }}</span>
          <input v-model="description" class="bar-desc" :placeholder="t('简单描述这个流程的用途（可选）')" @input="markDirty" />
        </label>
      </div>
      <div class="bar-actions">
        <StatusBadge v-if="isDraft" tone="warning">{{ t('草稿') }}</StatusBadge>
        <StatusBadge v-else-if="isDirty" tone="warning">{{ t('未保存') }}</StatusBadge>
        <StatusBadge v-else tone="done">{{ t('已同步') }}</StatusBadge>
        <el-button type="primary" :loading="isSaving" @click="save">
          <el-icon v-if="!isSaving"><DocumentChecked /></el-icon><span>{{ isDraft ? t('保存并创建') : t('保存') }}</span>
        </el-button>
      </div>
    </header>

    <div class="be-body scrollable">
      <div class="be-inner">
        <!-- 剧本段 -->
        <template v-if="hasSeries && seriesSteps">
          <div class="phase-head">
            <span class="phase-badge">{{ t('剧本段') }}</span>
            <span class="phase-hint">{{ t('对整本小说/剧本执行一次，拆成多集') }}</span>
          </div>
          <WorkflowStepList
            :steps="seriesSteps"
            scope="series"
            :model-options="modelOptions"
            :prompt-templates="promptTemplates"
            :custom-nodes="customNodes"
            @save-template="(p:any) => onSaveTemplate(p, 'series')"
            @change="markDirty"
          />

          <div class="phase-divider">
            <span class="phase-divider__line" />
            <span class="phase-divider__text">{{ t('以下对每一集分别执行') }}</span>
            <span class="phase-divider__line" />
          </div>
        </template>

        <!-- 剧集段 -->
        <div class="phase-head">
          <span class="phase-badge">{{ t('剧集段') }}</span>
          <span class="phase-hint">{{ hasSeries ? t('每一集各跑一次，生成视频') : t('单集执行，生成视频') }}</span>
        </div>
        <WorkflowStepList
          :steps="episodeSteps"
          scope="episode"
          :model-options="modelOptions"
          :prompt-templates="promptTemplates"
          :custom-nodes="customNodes"
          @save-template="(p:any) => onSaveTemplate(p, 'episode')"
          @change="markDirty"
        />
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.bundle-editor {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  background: var(--canvas);
}

.be-bar {
  display: flex;
  align-items: center;
  gap: var(--space-md);
  min-height: 68px;
  padding: 0 var(--space-lg);
  background: var(--surface-card);
  border-bottom: 1px solid var(--hairline);
  flex-shrink: 0;
}

.bar-back {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 7px 12px;
  border: 1px solid var(--hairline-strong);
  border-radius: var(--radius-md);
  background: transparent;
  color: var(--body);
  font-size: 13px;
  cursor: pointer;
  flex-shrink: 0;

  &:hover { color: var(--on-dark); border-color: var(--muted); }
}

.bar-title {
  flex: 1;
  min-width: 0;
  display: grid;
  grid-template-columns: minmax(220px, 0.9fr) minmax(260px, 1.1fr);
  gap: var(--space-sm);
  align-items: end;
}

.bar-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;

  span {
    font-size: 11px;
    font-weight: 700;
    color: var(--muted);
  }
}

.bar-name {
  width: 100%;
  height: 36px;
  background: var(--surface-soft);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  outline: none;
  padding: 0 12px;
  color: var(--on-dark);
  font-size: 15px;
  font-weight: 700;

  &:hover { border-color: var(--hairline-strong); }
  &:focus {
    border-color: var(--accent-cyan);
    box-shadow: 0 0 0 3px rgba(var(--brand-cyan-rgb), 0.08);
  }
  &::placeholder { color: var(--muted-soft); }
}
.bar-desc {
  width: 100%;
  height: 36px;
  background: var(--surface-soft);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  outline: none;
  padding: 0 12px;
  color: var(--body);
  font-size: 13px;

  &:hover { border-color: var(--hairline-strong); }
  &:focus {
    border-color: var(--accent-cyan);
    box-shadow: 0 0 0 3px rgba(var(--brand-cyan-rgb), 0.08);
  }
  &::placeholder { color: var(--muted-soft); }
}
.bar-actions { display: flex; align-items: center; gap: var(--space-sm); flex-shrink: 0; }
.be-body { flex: 1; min-height: 0; overflow-y: auto; padding: var(--space-xl) var(--space-lg); }
.be-inner { max-width: 700px; margin: 0 auto; }

.phase-head {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  margin-bottom: var(--space-sm);
}
.phase-badge {
  padding: 2px 10px;
  border-radius: var(--radius-pill);
  background: rgba(var(--brand-cyan-rgb), 0.12);
  color: var(--accent-cyan);
  font-size: 11px;
  font-weight: 700;
}
.phase-hint { font-size: 12px; color: var(--muted); }

.phase-divider {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  margin: var(--space-lg) 0;
}
.phase-divider__line { flex: 1; height: 1px; background: var(--hairline-strong); }
.phase-divider__text {
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 1px;
  color: var(--muted);
  white-space: nowrap;
}

@media (max-width: 900px) {
  .be-bar {
    align-items: stretch;
    flex-direction: column;
    padding: var(--space-md);
  }

  .bar-title {
    grid-template-columns: 1fr;
  }

  .bar-actions {
    justify-content: flex-end;
  }
}
</style>
