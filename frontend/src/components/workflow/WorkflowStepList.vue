<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import WorkflowStepCard from '@/components/workflow/WorkflowStepCard.vue'
import {
  newStep,
  allowedKindsByScope,
  type WorkflowStep,
  type NodeKind,
} from '@/utils/workflowGraph'
import { canEditNodePrompt, defaultPromptFor } from '@/utils/workflowPrompts'
import { resolveIcon } from '@/utils/iconRegistry'
import type { CustomNodeBlueprint } from '@/api/customNode'
import type { ModelType, PromptTemplate, WorkflowScope } from '@/types'

type WorkflowModelOption = {
  id: number
  name: string
  endpoint?: string
  model_id?: string
  options?: Record<string, unknown>
}

interface Props {
  steps: WorkflowStep[]
  scope: WorkflowScope
  modelOptions: Record<ModelType, WorkflowModelOption[]>
  promptTemplates: PromptTemplate[]
  customNodes: CustomNodeBlueprint[]
}

const props = defineProps<Props>()
const { t } = useI18n()
const emit = defineEmits<{
  (e: 'save-template', payload: { kind: string; label: string; prompt: string }): void
  (e: 'change'): void
}>()

function modelsForKind(kind: NodeKind): WorkflowModelOption[] {
  if (kind === 'text' || kind === 'image' || kind === 'video' || kind === 'voice') {
    return props.modelOptions[kind] ?? []
  }
  return []
}

const insertOptions = computed(() => {
  const allowed = new Set(allowedKindsByScope[props.scope])
  return props.customNodes
    .filter((n) => {
      const nScope = n.scope === 'series' ? 'series' : 'episode'
      return nScope === props.scope && allowed.has(n.kind as NodeKind) && n.kind !== 'input' && n.kind !== 'output'
    })
    .map((n) => ({
      kind: n.kind as NodeKind,
      label: n.label,
      icon: n.icon || undefined,
      desc: n.desc || '',
      isCustom: Number(n.is_fixed) !== 1,
    }))
})

function insertAt(index: number, opt: { kind: NodeKind; label: string; icon?: string; desc?: string; isCustom: boolean }) {
  const step = newStep(opt, props.scope)
  step.params.prompt = canEditNodePrompt(props.scope, opt.kind, opt.label, String(step.params.executionMode ?? ''))
    ? defaultPromptFor(props.promptTemplates, props.scope, opt.kind, opt.label)
    : ''
  props.steps.splice(index, 0, step)
  emit('change')
}

function removeStep(uid: string) {
  const idx = props.steps.findIndex((s) => s.uid === uid)
  if (idx >= 0) props.steps.splice(idx, 1)
  emit('change')
}

function moveStep(index: number, dir: -1 | 1) {
  const target = index + dir
  if (target <= 0 || target >= props.steps.length - 1) return
  const arr = props.steps
  ;[arr[index], arr[target]] = [arr[target], arr[index]]
  emit('change')
}
</script>

<template>
  <div class="step-list">
    <template v-for="(step, idx) in steps" :key="step.uid">
      <WorkflowStepCard
        :step="step"
        :scope="scope"
        :index="idx"
        :total="steps.length"
        :models="modelsForKind(step.kind)"
        :prompt-templates="promptTemplates"
        @remove="removeStep(step.uid)"
        @move-up="moveStep(idx, -1)"
        @move-down="moveStep(idx, 1)"
        @save-template="emit('save-template', $event)"
      />

      <div v-if="idx < steps.length - 1" class="insert-gap">
        <span class="insert-line" />
        <el-dropdown trigger="click" placement="bottom" @command="(opt:any) => insertAt(idx + 1, opt)">
          <button class="insert-btn" :disabled="insertOptions.length === 0">
            <el-icon><Plus /></el-icon><span>{{ t('插入一步') }}</span>
          </button>
          <template #dropdown>
            <el-dropdown-menu>
              <el-dropdown-item v-for="(opt, i) in insertOptions" :key="i" :command="opt">
                <el-icon><component :is="resolveIcon(opt.icon || 'Menu')" /></el-icon>
                {{ t(opt.label) }}
              </el-dropdown-item>
              <el-dropdown-item v-if="insertOptions.length === 0" disabled>{{ t('暂无可插入的步骤类型') }}</el-dropdown-item>
            </el-dropdown-menu>
          </template>
        </el-dropdown>
        <span class="insert-line" />
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.step-list { display: flex; flex-direction: column; }

.insert-gap {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  padding: 6px 0;
}
.insert-line { flex: 1; height: 1px; background: var(--hairline); }
.insert-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 4px 12px;
  border: 1px dashed var(--hairline-strong);
  border-radius: var(--radius-pill);
  background: var(--surface-soft);
  color: var(--muted);
  font-size: 12px;
  cursor: pointer;
  transition: all var(--duration-fast);

  &:hover:not(:disabled) { color: var(--primary); border-color: rgba(var(--primary-rgb), 0.5); }
  &:disabled { opacity: 0.4; cursor: not-allowed; }
}
</style>
