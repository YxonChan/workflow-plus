<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { ArrowLeft, CopyDocument, Delete, Edit, Lock, MoreFilled, Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import PageSkeleton from '@/components/ui/PageSkeleton.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import {
  createStylePreset,
  deleteStylePreset,
  duplicateStylePreset,
  listStylePresets,
  updateStylePreset,
} from '@/api/stylePreset'
import type { StylePreset } from '@/types'
import {
  STYLE_PARAMETER_GROUPS,
  composeStylePrompt,
  emptyStyleParameters,
  normalizeStyleParameters,
  parameterApplies,
  parameterOptions,
  styleParameterIssues,
  styleMoodWarnings,
  type StyleParameterGroup,
  type StyleParameters,
} from '@/utils/stylePresetCatalog'
import { VISUAL_STYLE_SEGMENT_OPTIONS, type VisualStyle } from '@/utils/visualStyle'

const { t } = useI18n()

const presets = ref<StylePreset[]>([])
const isLoading = ref(true)
const loadError = ref(false)
const saving = ref(false)
const deletingIds = ref<Set<number>>(new Set())
const duplicatingIds = ref<Set<number>>(new Set())
const expanded = ref<Set<number>>(new Set())
const view = ref<'library' | 'editor'>('library')
const editingId = ref<number | null>(null)

const form = reactive({
  name: '',
  summary: '',
  visual_styles: ['realistic'] as VisualStyle[],
  parameters: emptyStyleParameters(),
  technique_prompt: '',
  prompt: '',
  lighting_prompt: '',
  prompt_mode: 'parameters' as 'manual' | 'parameters',
  prompt_extra: '',
})
const styleFilter = ref<'all' | VisualStyle>('all')

const mainStyle = computed(() => form.visual_styles.length === 1 ? form.visual_styles[0] : undefined)
const parameterSections = computed(() => {
  const groups = STYLE_PARAMETER_GROUPS.filter((group) => mainStyle.value && parameterApplies(group, mainStyle.value))
  return [...new Set(groups.map((group) => group.section))].map((label) => ({
    label,
    groups: groups.filter((group) => group.section === label),
  }))
})
const parameterIssues = computed(() => mainStyle.value ? styleParameterIssues(form.parameters, mainStyle.value) : [])
const generatedPrompt = computed(() => mainStyle.value && !parameterIssues.value.length ? composeStylePrompt(form.parameters, mainStyle.value) : '')
const generatedTechnique = computed(() => mainStyle.value && !parameterIssues.value.length ? composeStylePrompt(form.parameters, mainStyle.value, 'technique') : '')
const effectivePrompt = computed(() => form.prompt_mode === 'manual' ? form.prompt : [generatedPrompt.value, form.prompt_extra.trim()].filter(Boolean).join('\n'))
const moodWarnings = computed(() => styleMoodWarnings(form.parameters).map((pair) => pair.map((value) =>
  t(STYLE_PARAMETER_GROUPS.find((group) => group.key === 'mood')!.options.find((option) => option.value === value)!.label),
).join(' / ')).join('、'))
const unresolvedSelections = computed(() => Object.entries(form.parameters).flatMap(([key, values]) => {
  const group = STYLE_PARAMETER_GROUPS.find((item) => item.key === key)
  return values.filter((value) => !group || !mainStyle.value || !parameterApplies(group, mainStyle.value)
    || values.length > group.max
    || !parameterOptions(group, mainStyle.value).some((option) => option.value === value)
    || (group.requires && !form.parameters[group.requires.key]?.includes(group.requires.value)))
    .map((value) => ({ key, value, label: group?.options.find((option) => option.value === value)?.label || value }))
}))

function parameterEnabled(group: StyleParameterGroup): boolean {
  return !group.requires || form.parameters[group.requires.key]?.includes(group.requires.value)
}

function selectParameter(group: StyleParameterGroup, value: unknown) {
  form.parameters[group.key] = Array.isArray(value) ? value : typeof value === 'string' && value ? [value] : []
  if (group.key === 'medium' && !form.parameters.medium.includes('Film') && form.parameters.film_stock.length) {
    form.parameters.film_stock = []
    ElMessage.info(t('已清空不适用的胶片型号'))
  }
}

async function changePromptMode(value: unknown) {
  if (value !== 'manual' && value !== 'parameters') return
  if (value === form.prompt_mode) return
  if (value === 'parameters' && (form.prompt.trim() || form.technique_prompt.trim() || form.lighting_prompt.trim())) {
    try {
      await ElMessageBox.confirm(t('保存参数模式将使用当前参数重新生成提示词，替换原手写内容。'), t('切换编辑模式'), {
        confirmButtonText: t('切换'), cancelButtonText: t('取消'), type: 'warning', closeOnClickModal: false,
      })
    } catch { return }
  }
  if (value === 'manual') {
    form.prompt = effectivePrompt.value
    form.technique_prompt = generatedTechnique.value
  }
  form.prompt_mode = value
}

async function changeVisualStyle(value: unknown) {
  if (value !== 'realistic' && value !== 'anime' && value !== '3d') return
  if (mainStyle.value === value) return
  if (form.prompt.trim() || form.technique_prompt.trim() || form.lighting_prompt.trim() || form.prompt_extra.trim()) {
    try {
      await ElMessageBox.confirm(t('切换主画风会清空原有手写描述和不适用参数。'), t('切换主画风'), {
        confirmButtonText: t('切换'), cancelButtonText: t('取消'), type: 'warning', closeOnClickModal: false,
      })
    } catch { return }
  }
  form.visual_styles = [value]
  for (const group of STYLE_PARAMETER_GROUPS) {
    if (!parameterApplies(group, value)) form.parameters[group.key] = []
    else form.parameters[group.key] = form.parameters[group.key].filter((item) => parameterOptions(group, value).some((option) => option.value === item))
  }
  form.prompt = ''
  form.technique_prompt = ''
  form.lighting_prompt = ''
  form.prompt_extra = ''
}

function matchesFilter(item: StylePreset): boolean {
  if (styleFilter.value === 'all') return true
  const styles = item.visual_styles?.length ? item.visual_styles : ['realistic']
  return styles.includes(styleFilter.value)
}

function styleLabels(item: StylePreset): string[] {
  const styles = item.visual_styles?.length ? item.visual_styles : ['realistic']
  return styles.map((style) => {
    const found = VISUAL_STYLE_SEGMENT_OPTIONS.find((option) => option.value === style)
    return found ? t(found.label) : style
  })
}

const systemPresets = computed(() => presets.value.filter((item) => Number(item.is_system) === 1 && matchesFilter(item)))
const userPresets = computed(() => presets.value.filter((item) => Number(item.is_system) !== 1 && matchesFilter(item)))

function toggleDetail(id: number) {
  const next = new Set(expanded.value)
  if (next.has(id)) next.delete(id)
  else next.add(id)
  expanded.value = next
}

function paletteChips(item: StylePreset): string[] {
  if ((item.prompt_mode || 'manual') === 'manual') return []
  return normalizeStyleParameters(item.parameters).palette.slice(0, 4)
}

function filledGroups(item: StylePreset) {
  if ((item.prompt_mode || 'manual') === 'manual') return []
  const parameters = normalizeStyleParameters(item.parameters)
  return STYLE_PARAMETER_GROUPS
    .map((group) => ({ ...group, values: parameters[group.key] }))
    .filter((group) => group.values.length > 0)
}

function resetForm() {
  form.name = ''
  form.summary = ''
  form.visual_styles = ['realistic']
  form.parameters = emptyStyleParameters()
  form.technique_prompt = ''
  form.prompt = ''
  form.lighting_prompt = ''
  form.prompt_mode = 'parameters'
  form.prompt_extra = ''
}

function assignParameters(parameters: StyleParameters) {
  form.parameters = normalizeStyleParameters(parameters)
}

function openCreate() {
  editingId.value = null
  resetForm()
  view.value = 'editor'
}

function openEditor(item: StylePreset) {
  editingId.value = item.id
  form.name = item.name
  form.summary = item.summary
  form.visual_styles = (item.visual_styles?.length ? [...item.visual_styles] : ['realistic']) as VisualStyle[]
  assignParameters(item.parameters)
  form.technique_prompt = item.technique_prompt || ''
  form.prompt = item.prompt
  form.lighting_prompt = item.lighting_prompt
  form.prompt_mode = item.prompt_mode || 'manual'
  form.prompt_extra = item.prompt_extra || ''
  view.value = 'editor'
}

function backToLibrary() {
  view.value = 'library'
  editingId.value = null
}

async function loadAll(silent = false) {
  if (!silent) isLoading.value = true
  loadError.value = false
  try {
    presets.value = await listStylePresets()
  } catch {
    loadError.value = true
    presets.value = []
  } finally {
    isLoading.value = false
  }
}

onMounted(() => {
  void loadAll()
})

function payloadFromForm() {
  const parameters = normalizeStyleParameters(form.parameters)
  return {
    name: form.name.trim(),
    summary: form.summary.trim(),
    visual_styles: [...form.visual_styles],
    parameters,
    technique_prompt: form.prompt_mode === 'parameters' ? generatedTechnique.value : form.technique_prompt.trim(),
    prompt: effectivePrompt.value.trim(),
    lighting_prompt: form.prompt_mode === 'parameters' ? '' : form.lighting_prompt.trim(),
    prompt_mode: form.prompt_mode,
    prompt_extra: form.prompt_extra.trim(),
  }
}

async function save() {
  if (saving.value) return
  const payload = payloadFromForm()
  if (!payload.name) {
    ElMessage.warning(t('请填写风格名称'))
    return
  }
  if (payload.visual_styles.length !== 1) {
    ElMessage.warning(t('请选择一个主画风'))
    return
  }
  if (form.prompt_mode === 'parameters' && parameterIssues.value.length) {
    ElMessage.warning(t('请处理冲突或旧版参数：{groups}', { groups: parameterIssues.value.map((label) => t(label)).join('、') }))
    return
  }
  if (form.prompt_mode === 'parameters' && !generatedPrompt.value) {
    ElMessage.warning(t('请至少选择一项整片质感参数'))
    return
  }
  if (!payload.prompt) {
    ElMessage.warning(t('请填写整片质感提示词'))
    return
  }

  saving.value = true
  try {
    if (editingId.value) {
      await updateStylePreset(editingId.value, payload)
    } else {
      await createStylePreset(payload)
    }
    ElMessage.success(t('已保存'))
    backToLibrary()
    await loadAll(true)
  } catch {
    // 请求层已提示错误
  } finally {
    saving.value = false
  }
}

async function handleDuplicate(item: StylePreset) {
  if (duplicatingIds.value.has(item.id)) return
  duplicatingIds.value = new Set(duplicatingIds.value).add(item.id)
  try {
    const created = await duplicateStylePreset(item.id)
    ElMessage.success(t('已复制，可直接修改'))
    await loadAll(true)
    const fresh = presets.value.find((preset) => preset.id === created.id) ?? created
    openEditor(fresh)
  } catch {
    // 请求层已提示错误
  } finally {
    const next = new Set(duplicatingIds.value)
    next.delete(item.id)
    duplicatingIds.value = next
  }
}

async function handleDelete(item: StylePreset) {
  if (Number(item.is_system) === 1 || deletingIds.value.has(item.id)) return
  try {
    await ElMessageBox.confirm(
      t('确定删除风格「{name}」？', { name: item.name }),
      t('删除风格'),
      {
        confirmButtonText: t('删除'),
        cancelButtonText: t('取消'),
        type: 'warning',
        closeOnClickModal: false,
      },
    )
  } catch {
    return
  }

  deletingIds.value = new Set(deletingIds.value).add(item.id)
  try {
    await deleteStylePreset(item.id)
    ElMessage.success(t('已删除'))
    await loadAll(true)
  } catch {
    // 请求层已提示错误
  } finally {
    const next = new Set(deletingIds.value)
    next.delete(item.id)
    deletingIds.value = next
  }
}

function handleCommand(command: string | number | object) {
  if (typeof command !== 'string') return
  const [action, idText] = command.split(':')
  const item = presets.value.find((preset) => preset.id === Number(idText))
  if (!item) return
  if (action === 'edit') openEditor(item)
  if (action === 'duplicate') void handleDuplicate(item)
  if (action === 'delete') void handleDelete(item)
}
</script>

<template>
  <div class="style-lib">
    <template v-if="view === 'library'">
      <PageToolbar
        :kicker="t('画面质感')"
        :title="t('风格化')"
      >
        <template #actions>
          <el-button type="primary" @click="openCreate">
            <el-icon><Plus /></el-icon><span>{{ t('新建风格') }}</span>
          </el-button>
        </template>
      </PageToolbar>

      <div class="style-lib__body scrollable">
        <div v-if="!isLoading && !loadError" class="style-filter">
          <button type="button" :class="{ 'is-active': styleFilter === 'all' }" @click="styleFilter = 'all'">{{ t('全部') }}</button>
          <button
            v-for="option in VISUAL_STYLE_SEGMENT_OPTIONS"
            :key="option.value"
            type="button"
            :class="{ 'is-active': styleFilter === option.value }"
            @click="styleFilter = option.value"
          >{{ t(option.label) }}</button>
        </div>
        <PageSkeleton v-if="isLoading" variant="gallery" :count="4" />
        <div v-else-if="loadError" class="style-lib__inner">
          <EmptyState icon="MagicStick" :title="t('风格列表加载失败')" :hint="t('检查网络后重新加载。')">
            <el-button @click="loadAll()">{{ t('重新加载') }}</el-button>
          </EmptyState>
        </div>
        <div v-else class="style-lib__inner">
          <section v-if="systemPresets.length" class="lib-section">
            <h2 class="lib-section__title">
              {{ t('官方风格') }}
              <StatusBadge tone="system">{{ t('系统内置 · 只读') }}</StatusBadge>
            </h2>
            <div class="card-grid">
              <article v-for="item in systemPresets" :key="item.id" class="style-card is-system">
                <div class="style-card__head">
                  <el-icon class="style-card__lock"><Lock /></el-icon>
                  <h3 class="style-card__name">{{ item.name }}</h3>
                </div>
                <div class="style-card__styles">
                  <span v-for="label in styleLabels(item)" :key="label">{{ label }}</span>
                </div>
                <p v-if="item.summary" class="style-card__summary">{{ item.summary }}</p>
                <div v-if="!expanded.has(item.id) && paletteChips(item).length" class="style-card__chips">
                  <span v-for="chip in paletteChips(item)" :key="chip" class="param-chip">{{ chip }}</span>
                </div>
                <p v-if="!expanded.has(item.id)" class="style-card__prompt">{{ item.prompt }}</p>
                <div v-else class="style-card__detail">
                  <div v-for="group in filledGroups(item)" :key="group.key" class="detail-row">
                    <span class="detail-row__label">{{ t(group.label) }}</span>
                    <span class="detail-row__value">{{ group.values.join('、') }}</span>
                  </div>
                  <p v-if="item.technique_prompt" class="detail-prompt">{{ item.technique_prompt }}</p>
                  <p class="detail-prompt">{{ item.prompt }}</p>
                  <p v-if="item.lighting_prompt" class="detail-prompt detail-prompt--light">{{ item.lighting_prompt }}</p>
                </div>
                <div class="style-card__footer">
                  <div class="style-card__utility">
                    <button class="detail-btn" type="button" @click="toggleDetail(item.id)">
                      {{ expanded.has(item.id) ? t('收起') : t('查看详情') }}
                    </button>
                  </div>
                  <el-button
                    size="small"
                    type="primary"
                    :loading="duplicatingIds.has(item.id)"
                    @click="handleDuplicate(item)"
                  >
                    {{ t('复制为我的风格') }}
                  </el-button>
                </div>
              </article>
            </div>
          </section>

          <section class="lib-section">
            <h2 class="lib-section__title">{{ t('我的风格') }}</h2>
            <div v-if="userPresets.length" class="card-grid">
              <article v-for="item in userPresets" :key="item.id" class="style-card">
                <div class="style-card__head">
                  <h3 class="style-card__name">{{ item.name }}</h3>
                </div>
                <div class="style-card__styles">
                  <span v-for="label in styleLabels(item)" :key="label">{{ label }}</span>
                </div>
                <p v-if="item.summary" class="style-card__summary">{{ item.summary }}</p>
                <div v-if="!expanded.has(item.id) && paletteChips(item).length" class="style-card__chips">
                  <span v-for="chip in paletteChips(item)" :key="chip" class="param-chip param-chip--user">{{ chip }}</span>
                </div>
                <p v-if="!expanded.has(item.id)" class="style-card__prompt">{{ item.prompt }}</p>
                <div v-else class="style-card__detail">
                  <div v-for="group in filledGroups(item)" :key="group.key" class="detail-row">
                    <span class="detail-row__label">{{ t(group.label) }}</span>
                    <span class="detail-row__value">{{ group.values.join('、') }}</span>
                  </div>
                  <p v-if="item.technique_prompt" class="detail-prompt">{{ item.technique_prompt }}</p>
                  <p class="detail-prompt">{{ item.prompt }}</p>
                  <p v-if="item.lighting_prompt" class="detail-prompt detail-prompt--light">{{ item.lighting_prompt }}</p>
                </div>
                <div class="style-card__footer">
                  <div class="style-card__utility">
                    <button class="detail-btn" type="button" @click="toggleDetail(item.id)">
                      {{ expanded.has(item.id) ? t('收起') : t('查看详情') }}
                    </button>
                    <el-dropdown trigger="click" placement="bottom-end" @command="handleCommand">
                      <el-button class="more-btn" size="small" text :disabled="deletingIds.has(item.id)">
                        <el-icon><MoreFilled /></el-icon>
                      </el-button>
                      <template #dropdown>
                        <el-dropdown-menu>
                          <el-dropdown-item :command="`edit:${item.id}`">
                            <el-icon><Edit /></el-icon> {{ t('编辑') }}
                          </el-dropdown-item>
                          <el-dropdown-item :command="`duplicate:${item.id}`">
                            <el-icon><CopyDocument /></el-icon> {{ t('复制') }}
                          </el-dropdown-item>
                          <el-dropdown-item :command="`delete:${item.id}`" :disabled="deletingIds.has(item.id)" divided>
                            <el-icon><Delete /></el-icon> {{ deletingIds.has(item.id) ? t('删除中') : t('删除') }}
                          </el-dropdown-item>
                        </el-dropdown-menu>
                      </template>
                    </el-dropdown>
                  </div>
                  <el-button size="small" type="primary" :disabled="deletingIds.has(item.id)" @click="openEditor(item)">
                    {{ t('编辑') }}
                  </el-button>
                </div>
              </article>
            </div>
            <EmptyState
              v-else
              icon="MagicStick"
              :title="t('还没有自己的风格')"
              :hint="t('复制一条官方风格来改，或从空白新建。')"
            />
          </section>
        </div>
      </div>
    </template>

    <template v-else>
      <PageToolbar
        :kicker="t('画面质感')"
        :title="editingId ? t('编辑风格') : t('新建风格')"
      >
        <template #actions>
          <el-button @click="backToLibrary">
            <el-icon><ArrowLeft /></el-icon><span>{{ t('返回') }}</span>
          </el-button>
          <el-button type="primary" :loading="saving" @click="save">{{ t('保存') }}</el-button>
        </template>
      </PageToolbar>

      <div class="style-lib__body scrollable">
        <form class="style-editor" @submit.prevent="save">
          <div class="field-grid">
            <label class="style-field">
              <span>{{ t('名称') }}</span>
              <el-input v-model="form.name" maxlength="120" :placeholder="t('例如：雨夜都市冷调')" />
            </label>
            <label class="style-field">
              <span>{{ t('简介') }}</span>
              <el-input v-model="form.summary" maxlength="255" :placeholder="t('用一句话说明适合什么片子')" />
            </label>
            <div class="style-field style-field--full">
              <span>{{ t('主画风') }}</span>
              <el-radio-group :model-value="mainStyle" :aria-label="t('主画风')" @update:model-value="changeVisualStyle">
                <el-radio
                  v-for="option in VISUAL_STYLE_SEGMENT_OPTIONS"
                  :key="option.value"
                  :value="option.value"
                >{{ t(option.label) }}</el-radio>
              </el-radio-group>
              <el-alert v-if="!mainStyle" :title="t('旧风格包含多个画风，请选择一个主画风后保存。')" type="warning" :closable="false" />
            </div>
            <div class="style-field style-field--full">
              <span>{{ t('编辑模式') }}</span>
              <el-radio-group :model-value="form.prompt_mode" :aria-label="t('编辑模式')" @update:model-value="changePromptMode">
                <el-radio-button value="parameters">{{ t('参数') }}</el-radio-button>
                <el-radio-button value="manual">{{ t('手写提示词') }}</el-radio-button>
              </el-radio-group>
            </div>
          </div>

          <template v-if="form.prompt_mode === 'parameters'">
          <section v-if="parameterIssues.length" class="editor-section">
            <el-alert :title="t('请处理冲突或旧版参数：{groups}', { groups: parameterIssues.map((label) => t(label)).join('、') })" type="error" :closable="false" />
            <div class="pending-selections">
              <el-tag v-for="item in unresolvedSelections" :key="`${item.key}:${item.value}`" closable type="warning"
                @close="form.parameters[item.key] = form.parameters[item.key].filter((value) => value !== item.value)">
                {{ t(item.label) }}
              </el-tag>
            </div>
          </section>
          <section v-for="section in parameterSections" :key="section.label" class="editor-section">
            <h2>{{ t(section.label) }}</h2>
            <div class="param-grid">
              <label v-for="group in section.groups" :key="group.key" class="style-field">
                <span>{{ t(group.label) }}</span>
                <el-select
                  :model-value="group.max === 1 ? (form.parameters[group.key].length === 1 ? form.parameters[group.key][0] : undefined) : form.parameters[group.key]"
                  :multiple="group.max > 1"
                  :multiple-limit="group.max > 1 ? group.max : 0"
                  :disabled="!parameterEnabled(group)"
                  clearable
                  filterable
                  :placeholder="t(!parameterEnabled(group) ? '先选择胶片介质' : group.max === 1 ? '不指定' : '最多选择 {count} 项', { count: group.max })"
                  @update:model-value="selectParameter(group, $event)"
                >
                  <el-option
                    v-for="option in parameterOptions(group, mainStyle!)"
                    :key="option.value"
                    :label="t(option.label)"
                    :value="option.value"
                  />
                </el-select>
              </label>
            </div>
          </section>
          <el-alert v-if="moodWarnings" :title="t('氛围存在明显反差：{values}', { values: moodWarnings })" type="warning" :closable="false" />
          <section class="editor-section">
            <h2>{{ t('补充描述') }}</h2>
            <el-input v-model="form.prompt_extra" type="textarea" :rows="3" maxlength="8000" show-word-limit resize="vertical" />
          </section>
          <section class="editor-section">
            <h2>{{ t('提示词预览') }}</h2>
            <p v-if="generatedTechnique" class="prompt-preview">{{ generatedTechnique }}</p>
            <p class="prompt-preview">{{ effectivePrompt || t('尚未设置') }}</p>
          </section>
          </template>

          <template v-else>
          <section class="editor-section">
            <h2>{{ t('画法提示词') }}</h2>
            <p>{{ t('人物、道具设定图也会使用。只写线条、上色、材质和造型比例，不写色调和光线。可以留空。') }}</p>
            <el-input
              v-model="form.technique_prompt"
              type="textarea"
              :rows="4"
              maxlength="8000"
              show-word-limit
              resize="vertical"
            />
          </section>

          <section class="editor-section">
            <h2>{{ t('整片质感提示词') }}</h2>
            <p>{{ t('用于场景图、镜头首帧和视频。') }}</p>
            <el-input
              v-model="form.prompt"
              type="textarea"
              :rows="8"
              maxlength="20000"
              show-word-limit
              resize="vertical"
            />
          </section>

          <section class="editor-section">
            <h2>{{ t('片段光影') }}</h2>
            <p>{{ t('整片色调锁定后，只写这一段的光影变化。可以留空。') }}</p>
            <el-input
              v-model="form.lighting_prompt"
              type="textarea"
              :rows="4"
              maxlength="8000"
              show-word-limit
              resize="vertical"
            />
          </section>
          </template>
        </form>
      </div>
    </template>
  </div>
</template>

<style scoped lang="scss">
.style-lib {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  background: var(--canvas);
}

.style-lib__body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: var(--space-xl) var(--space-lg);
}

.style-filter {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  max-width: 1280px;
  margin: 0 auto var(--space-lg);

  button {
    border: 1px solid var(--hairline);
    border-radius: 999px;
    background: transparent;
    color: var(--on-dark-soft);
    padding: 4px 12px;
    cursor: pointer;
  }

  button.is-active {
    border-color: var(--primary-active);
    color: var(--on-dark);
    background: rgba(var(--primary-rgb), 0.18);
  }
}

.style-card__styles {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 8px;

  span {
    border-radius: 999px;
    background: rgba(var(--primary-rgb), 0.16);
    color: var(--on-dark);
    font-size: 12px;
    padding: 2px 8px;
  }
}

.style-lib__inner,
.style-editor {
  max-width: 1280px;
  margin: 0 auto;
}

.style-editor {
  max-width: 960px;
}

.lib-section {
  margin-bottom: var(--space-xxl);
}

.lib-section__title {
  margin: 0 0 var(--space-md);
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  color: var(--on-dark);
  font-size: var(--text-title-sm-size);
  font-weight: 700;
}

.card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  align-items: stretch;
  gap: var(--space-md);
}

.style-card {
  display: flex;
  flex-direction: column;
  min-height: 240px;
  padding: var(--space-lg);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-card);

  &.is-system {
    border-color: rgba(var(--primary-rgb), 0.35);
    background: linear-gradient(180deg, var(--surface-elevated), var(--surface-card));
  }
}

.style-card__head {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  min-width: 0;
}

.style-card__lock {
  margin-top: 3px;
  color: var(--primary-active);
  font-size: 14px;
}

.style-card__name {
  margin: 0;
  min-width: 0;
  color: var(--on-dark);
  font-size: var(--text-title-sm-size);
  font-weight: 700;
  line-height: 1.25;
}

.style-card__summary {
  margin: 6px 0 0;
  color: var(--muted);
  font-size: 12px;
  line-height: 1.5;
}

.style-card__chips {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: var(--space-sm);
}

.param-chip {
  max-width: 100%;
  min-height: 24px;
  padding: 0 9px;
  overflow: hidden;
  border: 1px solid rgba(var(--brand-cyan-rgb), 0.28);
  border-radius: var(--radius-sm);
  background: rgba(var(--brand-cyan-rgb), 0.1);
  color: var(--brand-cyan);
  font-size: 11px;
  font-weight: 600;
  line-height: 24px;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.param-chip--user {
  border-color: rgba(167, 139, 250, 0.32);
  background: rgba(167, 139, 250, 0.12);
  color: var(--brand-lilac);
}

.style-card__prompt,
.detail-prompt {
  margin: var(--space-sm) 0 0;
  color: var(--body);
  font-size: 13px;
  line-height: 1.55;
  overflow-wrap: anywhere;
}

.style-card__prompt {
  display: -webkit-box;
  overflow: hidden;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 3;
}

.style-card__detail {
  margin-top: var(--space-sm);
  padding-top: var(--space-sm);
  border-top: 1px solid var(--hairline);
}

.detail-row {
  display: grid;
  grid-template-columns: 88px minmax(0, 1fr);
  gap: 8px;
  margin-top: 8px;
}

.detail-row__label {
  color: var(--muted);
  font-size: 12px;
  font-weight: 600;
}

.detail-row__value,
.detail-prompt {
  color: var(--body);
  font-size: 13px;
  line-height: 1.5;
  overflow-wrap: anywhere;
}

.detail-prompt--light {
  padding-top: var(--space-sm);
  border-top: 1px solid var(--hairline);
  color: var(--muted);
}

.style-card__footer {
  display: flex;
  flex-direction: column;
  gap: 12px;
  margin-top: auto;
  padding-top: var(--space-md);
  border-top: 1px solid var(--hairline);
}

.style-card__utility {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-sm);
  min-height: 28px;
}

.detail-btn {
  height: 28px;
  padding: 0 8px;
  border: 1px solid transparent;
  border-radius: var(--radius-sm);
  background: transparent;
  color: var(--muted);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;

  &:hover {
    border-color: var(--hairline);
    background: var(--surface-elevated);
    color: var(--body);
  }

  &:focus-visible {
    outline: 2px solid var(--primary);
    outline-offset: 2px;
  }
}

.more-btn {
  width: 32px;
  height: 28px;
  padding-inline: 0;
}

.style-card__footer :deep(.el-button) {
  margin-left: 0;
}

.field-grid,
.param-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-md);
}

.editor-section {
  margin-top: var(--space-xl);

  h2 {
    margin: 0;
    color: var(--on-dark);
    font-size: var(--text-title-sm-size);
    font-weight: 700;
  }

  p {
    margin: 4px 0 var(--space-sm);
    color: var(--muted);
    font-size: 13px;
    line-height: 1.45;
  }
}

.param-grid {
  margin-top: var(--space-sm);
}

.pending-selections {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 8px;

  :deep(.el-tag) {
    max-width: 100%;
    height: auto;
    min-height: 24px;
    white-space: normal;
  }

  :deep(.el-tag__content) {
    overflow-wrap: anywhere;
  }
}

.editor-section .prompt-preview {
  white-space: pre-wrap;
  overflow-wrap: anywhere;
  color: var(--body);
  line-height: 1.7;
}

.style-field {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;

  > span {
    color: var(--body-strong);
    font-size: 13px;
    font-weight: 600;
  }

  :deep(.el-select) {
    width: 100%;
  }

  :deep(.el-select__wrapper) {
    height: auto;
    min-height: 40px;
    align-items: center;
    padding-top: 4px;
    padding-bottom: 4px;
  }

  :deep(.el-select__selection) {
    gap: 6px;
  }

  :deep(.el-tag) {
    --el-tag-bg-color: var(--surface-elevated);
    --el-tag-border-color: var(--hairline);
    --el-tag-text-color: var(--body);
    --el-tag-hover-color: var(--body-strong);

    height: auto !important;
    min-height: 24px;
    max-width: 100%;
    margin: 2px 0;
    padding: 3px 8px !important;
    border-radius: 6px !important;
    background: var(--surface-elevated) !important;
    border-color: var(--hairline) !important;
    color: var(--body) !important;
    font-size: 12px !important;
    font-weight: 600 !important;
    line-height: 1.35 !important;
  }

  :deep(.el-tag__content),
  :deep(.el-select__tags-text) {
    color: var(--body) !important;
    white-space: normal;
  }

  :deep(.el-tag__close) {
    color: var(--muted) !important;

    &:hover {
      background: rgba(255, 255, 255, 0.16) !important;
      color: #ffffff !important;
    }
  }
}

@media (max-width: 760px) {
  .style-lib__body {
    padding: var(--space-lg) var(--space-md);
    padding-right: 52px;
  }

  .card-grid,
  .field-grid,
  .param-grid {
    grid-template-columns: 1fr;
  }

  .detail-row {
    grid-template-columns: 1fr;
    gap: 2px;
  }
}
</style>
