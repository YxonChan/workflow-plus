<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { usePageQuery, queryId } from '@/utils/pageQuery'
import { useRouter } from 'vue-router'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import WorkflowLibrary from '@/components/workflow/WorkflowLibrary.vue'
import BundleEditor from '@/components/workflow/BundleEditor.vue'
import {
  listBundles,
  createBundle,
  deleteBundle,
  duplicateBundle,
} from '@/api/workflowBundle'
import { listCustomNodes, type CustomNodeBlueprint } from '@/api/customNode'
import { listPromptTemplates } from '@/api/promptTemplate'
import { listGrouped as listModelConfigs } from '@/api/modelConfig'
import type { WorkflowBundle, ModelConfigGroup, ModelType, PromptTemplate } from '@/types'
import '@vue-flow/core/dist/style.css'
import '@vue-flow/core/dist/theme-default.css'
import '@vue-flow/controls/dist/style.css'

const router = useRouter()
const { t } = useI18n()

const bundles = ref<WorkflowBundle[]>([])
const customNodes = ref<CustomNodeBlueprint[]>([])
const promptTemplates = ref<PromptTemplate[]>([])
type WorkflowModelOption = {
  id: number
  name: string
  endpoint?: string
  model_id?: string
  options?: Record<string, unknown>
}

const modelOptions = ref<Record<ModelType, WorkflowModelOption[]>>({
  text: [], image: [], video: [], voice: [],
})
const isLoading = ref(true)
const deletingIds = ref<Set<number>>(new Set())

const view = ref<'library' | 'editor'>('library')
const editingBundle = ref<WorkflowBundle | null>(null)
const editingDraftFromBundleId = ref<number | null>(null)
const locationBundleId = usePageQuery<number | null>('bundle_id', null, queryId)
const locationDraftId = usePageQuery<number | null>('draft_from', null, queryId)

function applyModelGroups(groups: ModelConfigGroup[]) {
  const next: Record<ModelType, WorkflowModelOption[]> = { text: [], image: [], video: [], voice: [] }
  for (const g of groups) {
    next[g.type] = g.models.map((m) => ({
      id: m.id,
      name: m.name,
      endpoint: m.endpoint,
      model_id: m.model_id,
      options: m.options ?? {},
    }))
  }
  modelOptions.value = next
}

async function loadAll() {
  isLoading.value = true
  try {
    const [groups, list, nodes, templates] = await Promise.all([
      listModelConfigs(),
      listBundles(),
      listCustomNodes(),
      listPromptTemplates(),
    ])
    applyModelGroups(groups as ModelConfigGroup[])
    bundles.value = list
    customNodes.value = nodes
    promptTemplates.value = templates
  } finally {
    isLoading.value = false
  }
}

function restoreEditor() {
  if (isLoading.value) return
  const bundle = bundles.value.find((item) => item.id === locationBundleId.value)
  if (!bundle) {
    backToLibrary()
    return
  }
  openEditor(bundle, locationDraftId.value)
}

onMounted(async () => {
  await loadAll()
  restoreEditor()
})
watch([locationBundleId, locationDraftId], restoreEditor)

function openEditor(b: WorkflowBundle, draftFromBundleId: number | null = null) {
  editingBundle.value = b
  editingDraftFromBundleId.value = draftFromBundleId
  view.value = 'editor'
  locationBundleId.value = b.id
  locationDraftId.value = draftFromBundleId
}
function backToLibrary() {
  view.value = 'library'
  editingBundle.value = null
  editingDraftFromBundleId.value = null
  locationBundleId.value = null
  locationDraftId.value = null
}

async function handleCreate() {
  try {
    const result = await ElMessageBox.prompt(t('给新流程起个名称'), t('新建完整流程'), {
      confirmButtonText: t('创建并编辑'),
      cancelButtonText: t('取消'),
      inputValue: t('我的完整流程'),
      inputValidator: (v) => (v?.trim() ? true : t('名称不能为空')),
    })
    const created = await createBundle({ name: String(result.value).trim(), include_series: true })
    bundles.value.push(created)
    openEditor(created)
  } catch { /* cancelled */ }
}

async function handleDuplicate(b: WorkflowBundle) {
  try {
    const created = await duplicateBundle(b.id)
    bundles.value.push(created)
    openEditor(created)
    ElMessage.success(t('已复制，可直接改造'))
  } catch (error: any) {
    ElMessage.error(error?.message ?? t('复制失败'))
  }
}

async function handleDelete(b: WorkflowBundle) {
  if (Number(b.is_system) === 1) {
    ElMessage.warning(t('官方流程不可删除，请复制后再编辑'))
    return
  }
  if (deletingIds.value.has(b.id)) return

  deletingIds.value = new Set(deletingIds.value).add(b.id)
  try {
    await ElMessageBox.confirm(t('确定删除流程「{name}」？', { name: b.name }), t('删除流程'), {
      confirmButtonText: t('删除'),
      cancelButtonText: t('取消'),
      type: 'warning',
      closeOnClickModal: false,
      closeOnPressEscape: true,
      beforeClose: (action, instance, done) => {
        if (action === 'confirm') {
          instance.confirmButtonLoading = true
          instance.confirmButtonText = t('删除中...')
          done()
          return
        }
        done()
      },
    })
  } catch {
    const next = new Set(deletingIds.value)
    next.delete(b.id)
    deletingIds.value = next
    return
  }

  try {
    await deleteBundle(b.id)
    bundles.value = bundles.value.filter((x) => x.id !== b.id)
    ElMessage.success(t('流程已删除'))
  } catch (error: any) {
    ElMessage.error(error?.message ?? t('删除失败'))
  } finally {
    const next = new Set(deletingIds.value)
    next.delete(b.id)
    deletingIds.value = next
  }
}

function handleGenerate(b: WorkflowBundle) {
  router.push({ name: 'series', query: { bundle: String(b.id) } })
}

function handleSaved(updated: WorkflowBundle) {
  const idx = bundles.value.findIndex((x) => x.id === updated.id)
  if (idx !== -1) bundles.value[idx] = updated
  else bundles.value.push(updated)
  backToLibrary()
}

function handleTemplateCreated(tpl: PromptTemplate) {
  const idx = promptTemplates.value.findIndex((item) => item.id === tpl.id)
  if (idx >= 0) {
    promptTemplates.value[idx] = tpl
    return
  }
  promptTemplates.value.push(tpl)
}
</script>

<template>
  <div class="workflow-page">
    <WorkflowLibrary
      v-if="view === 'library' || !editingBundle"
      :bundles="bundles"
      :loading="isLoading"
      :deleting-ids="[...deletingIds]"
      @create="handleCreate"
      @edit="openEditor"
      @duplicate="handleDuplicate"
      @delete="handleDelete"
      @generate="handleGenerate"
    />
    <BundleEditor
      v-else
      :bundle="editingBundle!"
      :model-options="modelOptions"
      :prompt-templates="promptTemplates"
      :custom-nodes="customNodes"
      :draft-from-bundle-id="editingDraftFromBundleId"
      @back="backToLibrary"
      @saved="handleSaved"
      @template-created="handleTemplateCreated"
    />
  </div>
</template>

<style scoped lang="scss">
.workflow-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
  background: var(--canvas);
}
</style>
