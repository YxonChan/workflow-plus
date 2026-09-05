<script setup lang="ts">
import { ref, computed } from 'vue'
import { CopyDocument, Delete, Edit, Lock, MoreFilled, Plus } from '@element-plus/icons-vue'
import { useI18n } from 'vue-i18n'
import { graphToSteps } from '@/utils/workflowGraph'
import type { WorkflowBundle, WorkflowBrief } from '@/types'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import PageSkeleton from '@/components/ui/PageSkeleton.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'

interface Props {
  bundles: WorkflowBundle[]
  deletingIds?: number[]
  loading?: boolean
}

const props = defineProps<Props>()
const emit = defineEmits<{
  (e: 'create'): void
  (e: 'edit', b: WorkflowBundle): void
  (e: 'duplicate', b: WorkflowBundle): void
  (e: 'delete', b: WorkflowBundle): void
  (e: 'generate', b: WorkflowBundle): void
}>()

const { t } = useI18n()
const systemBundles = computed(() => props.bundles.filter((b) => Number(b.is_system) === 1))
const userBundles = computed(() => props.bundles.filter((b) => Number(b.is_system) !== 1))
const deletingSet = computed(() => new Set(props.deletingIds ?? []))

const expanded = ref<Set<number>>(new Set())
function toggleDetail(id: number) {
  const next = new Set(expanded.value)
  next.has(id) ? next.delete(id) : next.add(id)
  expanded.value = next
}

function handleBundleCommand(command: string | number | object) {
  if (typeof command !== 'string') return
  const [action, idText] = command.split(':')
  const bundle = props.bundles.find((b) => b.id === Number(idText))
  if (!bundle) return
  if (action === 'edit') emit('edit', bundle)
  if (action === 'duplicate') emit('duplicate', bundle)
  if (action === 'delete') emit('delete', bundle)
}

function labelsOf(wf: WorkflowBrief | null, scope: 'series' | 'episode'): string[] {
  if (!wf) return []
  return graphToSteps(wf.graph, scope).map((s) => s.label)
}

function displayText(value: string | undefined | null): string {
  const text = String(value || '').trim()
  if (!text) return ''
  return t(text)
}

function displayBundleName(bundle: WorkflowBundle): string {
  return Number(bundle.is_system) === 1 ? displayText(bundle.name) : bundle.name
}

interface StepChip { text: string; kind: 'series' | 'episode' | 'fan'; hasSeparator: boolean }
function stepChipsOf(b: WorkflowBundle): StepChip[] {
  const chips: StepChip[] = []
  const series = labelsOf(b.series_workflow, 'series')
  const episode = labelsOf(b.episode_workflow, 'episode')

  series.forEach((l, i) => {
    const isLast = i === series.length - 1
    chips.push({
      text: displayText(l),
      kind: 'series',
      hasSeparator: !isLast,
    })
  })

  if (series.length && episode.length) {
    chips.push({
      text: t('分集'),
      kind: 'fan',
      hasSeparator: true,
    })
  }

  episode.forEach((l, i) => {
    const isLast = i === episode.length - 1
    chips.push({
      text: displayText(l),
      kind: 'episode',
      hasSeparator: !isLast,
    })
  })

  return chips
}

interface DetailStep { label: string; phase: '剧本段' | '剧集段' }
function detailSteps(b: WorkflowBundle): DetailStep[] {
  const out: DetailStep[] = []
  labelsOf(b.series_workflow, 'series').forEach((l) => out.push({ label: displayText(l), phase: '剧本段' }))
  labelsOf(b.episode_workflow, 'episode').forEach((l) => out.push({ label: displayText(l), phase: '剧集段' }))
  return out
}
</script>

<template>
  <div class="wf-lib">
    <PageToolbar
      :kicker="t('WORKFLOW LIBRARY')"
      :title="t('完整流程')"
      :subtitle="t('挑一个流程，从小说/一句话到批量短视频一条龙；想自定义就复制改造或新建。')"
    >
      <template #actions>
        <el-button type="primary" @click="emit('create')">
          <el-icon><Plus /></el-icon><span>{{ t('新建流程') }}</span>
        </el-button>
      </template>
    </PageToolbar>

    <div class="wf-lib__body scrollable">
      <PageSkeleton v-if="loading" variant="gallery" :count="6" />
      <div v-else class="wf-lib__inner">
        <!-- 官方流程 -->
        <section v-if="systemBundles.length" class="lib-section">
          <h3 class="lib-section__title">{{ t('官方流程') }}<StatusBadge tone="system">{{ t('系统内置 · 只读') }}</StatusBadge></h3>
          <div class="card-grid">
            <article v-for="b in systemBundles" :key="b.id" class="wf-card is-system">
              <div class="wf-card__head">
                <el-icon class="wf-card__lock"><Lock /></el-icon>
                <span class="wf-card__name">{{ displayBundleName(b) }}</span>
              </div>
              <p v-if="b.description" class="wf-card__desc">{{ displayText(b.description) }}</p>
              <div v-if="!expanded.has(b.id)" class="wf-card__chips">
                <div v-for="(c, i) in stepChipsOf(b)" :key="i" class="chip-wrapper">
                  <span :class="['chip', `chip--${c.kind}`]">{{ c.text }}</span>
                  <span v-if="c.hasSeparator" class="chip-sep">›</span>
                </div>
              </div>
              <div v-if="expanded.has(b.id)" class="wf-card__detail">
                <div v-for="(s, i) in detailSteps(b)" :key="i" class="detail-step">
                  <span class="detail-step__idx">{{ i + 1 }}</span>
                  <span class="detail-step__name">{{ s.label }}</span>
                  <span
                    class="detail-step__phase"
                    :class="s.phase === '剧本段' ? 'detail-step__phase--series' : 'detail-step__phase--episode'"
                  >{{ t(s.phase) }}</span>
                </div>
              </div>
              <div class="wf-card__footer">
                <div class="wf-card__utility">
                  <button class="detail-btn" type="button" @click="toggleDetail(b.id)">
                    {{ expanded.has(b.id) ? t('收起') : t('查看详情') }}
                  </button>
                </div>
                <div class="wf-card__actions wf-card__actions--official">
                  <el-button size="small" @click="emit('duplicate', b)">{{ t('复制改造') }}</el-button>
                  <el-button size="small" type="primary" @click="emit('generate', b)">{{ t('用这个生成') }}</el-button>
                </div>
              </div>
            </article>
          </div>
        </section>

        <!-- 我的流程 -->
        <section class="lib-section">
          <h3 class="lib-section__title">{{ t('我的流程') }}</h3>
          <div v-if="userBundles.length" class="card-grid">
            <article v-for="b in userBundles" :key="b.id" class="wf-card">
              <div class="wf-card__head">
                <span class="wf-card__name">{{ displayBundleName(b) }}</span>
              </div>
              <p v-if="b.description" class="wf-card__desc">{{ displayText(b.description) }}</p>
              <div v-if="!expanded.has(b.id)" class="wf-card__chips">
                <div v-for="(c, i) in stepChipsOf(b)" :key="i" class="chip-wrapper">
                  <span :class="['chip', `chip--${c.kind}`]">{{ c.text }}</span>
                  <span v-if="c.hasSeparator" class="chip-sep">›</span>
                </div>
              </div>
              <div v-if="expanded.has(b.id)" class="wf-card__detail">
                <div v-for="(s, i) in detailSteps(b)" :key="i" class="detail-step">
                  <span class="detail-step__idx">{{ i + 1 }}</span>
                  <span class="detail-step__name">{{ s.label }}</span>
                  <span
                    class="detail-step__phase"
                    :class="s.phase === '剧本段' ? 'detail-step__phase--series' : 'detail-step__phase--episode'"
                  >{{ t(s.phase) }}</span>
                </div>
              </div>
              <div class="wf-card__footer">
                <div class="wf-card__utility">
                  <button class="detail-btn" type="button" @click="toggleDetail(b.id)">
                    {{ expanded.has(b.id) ? t('收起') : t('查看详情') }}
                  </button>
                  <el-dropdown trigger="click" placement="bottom-end" @command="handleBundleCommand">
                    <el-button class="more-btn" size="small" text :loading="deletingSet.has(b.id)" :disabled="deletingSet.has(b.id)">
                      <el-icon><MoreFilled /></el-icon>
                    </el-button>
                    <template #dropdown>
                      <el-dropdown-menu>
                        <el-dropdown-item :command="`edit:${b.id}`"><el-icon><Edit /></el-icon> {{ t('编辑') }}</el-dropdown-item>
                        <el-dropdown-item :command="`duplicate:${b.id}`"><el-icon><CopyDocument /></el-icon> {{ t('复制') }}</el-dropdown-item>
                        <el-dropdown-item :command="`delete:${b.id}`" :disabled="deletingSet.has(b.id)" divided>
                          <el-icon><Delete /></el-icon> {{ deletingSet.has(b.id) ? t('删除中') : t('删除') }}
                        </el-dropdown-item>
                      </el-dropdown-menu>
                    </template>
                  </el-dropdown>
                </div>
                <div class="wf-card__actions wf-card__actions--user">
                  <el-button size="small" :disabled="deletingSet.has(b.id)" @click="emit('edit', b)">{{ t('编辑') }}</el-button>
                  <el-button size="small" type="primary" @click="emit('generate', b)">{{ t('用这个生成') }}</el-button>
                </div>
              </div>
            </article>
          </div>
          <EmptyState
            v-else
            icon="Share"
            :title="t('还没有自己的流程')"
            :hint="t('复制一个官方流程来改，或点右上角「新建流程」从零开始。')"
          />
        </section>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.wf-lib { display: flex; flex-direction: column; height: 100%; min-height: 0; background: var(--canvas); }

.wf-lib__body { flex: 1; min-height: 0; overflow-y: auto; padding: var(--space-xl) var(--space-lg); }
.wf-lib__inner { max-width: 1280px; margin: 0 auto; }

.lib-section { margin-bottom: var(--space-xxl); }
.lib-section__title {
  margin: 0 0 var(--space-md);
  font-size: var(--text-title-sm-size);
  font-weight: 700;
  color: var(--on-dark);
  display: flex; align-items: center; gap: var(--space-sm);
}

.card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
  align-items: stretch;
  gap: var(--space-md);
}

.wf-card {
  display: flex;
  flex-direction: column;
  min-height: 282px;
  background: var(--surface-card);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  padding: var(--space-lg);
  &.is-system { border-color: rgba(124, 58, 237, 0.24); background: linear-gradient(180deg, var(--surface-elevated), var(--surface-card)); }
}
.wf-card__head { display: flex; align-items: flex-start; gap: 6px; min-width: 0; }
.wf-card__lock { color: var(--primary); font-size: 14px; }
.wf-card__name {
  min-width: 0;
  font-size: var(--text-title-sm-size);
  font-weight: 700;
  color: var(--on-dark);
  line-height: 1.25;
}
.wf-card__desc { margin: 6px 0 0; font-size: 12px; color: var(--muted); line-height: 1.5; }

.wf-card__chips {
  margin-top: var(--space-sm);
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  align-content: flex-start;
  gap: 6px;
}
.chip {
  max-width: 100%;
  min-height: 24px;
  display: inline-flex;
  align-items: center;
  font-size: 11px;
  line-height: 1;
  white-space: nowrap;
}
.chip--series,
.chip--episode {
  padding: 0 9px;
  border-radius: var(--radius-sm);
  border: 1px solid var(--hairline);
  font-weight: 600;
  overflow: hidden;
  text-overflow: ellipsis;
}
.chip--series {
  background: rgba(37, 99, 235, 0.08);
  border-color: rgba(37, 99, 235, 0.18);
  color: #1d4ed8;
}
.chip--episode {
  background: rgba(16, 185, 129, 0.09);
  border-color: rgba(16, 185, 129, 0.2);
  color: #047857;
}
.chip-wrapper {
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.chip-sep {
  color: var(--muted-soft);
  font-size: 11px;
  font-weight: 600;
  user-select: none;
}
.chip--fan {
  padding: 0 9px;
  border-radius: var(--radius-pill);
  background: rgba(245, 158, 11, 0.14);
  border: 1px solid rgba(245, 158, 11, 0.24);
  color: #b45309;
  font-weight: 700;
  overflow: hidden;
  text-overflow: ellipsis;
}

.wf-card__detail {
  margin-top: var(--space-sm);
  padding-top: var(--space-sm);
  border-top: 1px solid var(--hairline);
  display: flex; flex-direction: column; gap: 6px;
}
.detail-step { display: flex; align-items: center; gap: 8px; }
.detail-step__idx {
  width: 20px; height: 20px; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  border-radius: var(--radius-full);
  background: var(--surface-elevated);
  border: 1px solid var(--hairline-strong);
  font-size: 11px; font-weight: 700; color: var(--body-strong);
}
.detail-step__name {
  font-size: 13px;
  color: var(--on-dark);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  min-width: 0;
}
.detail-step__phase {
  margin-left: auto;
  padding: 0 6px;
  border-radius: var(--radius-pill);
  font-size: 9px;
  font-weight: 700;
  border: 1px solid var(--hairline);
  background: var(--surface-elevated);
  flex-shrink: 0;
  white-space: nowrap;
}
.detail-step__phase--series {
  color: #1d4ed8;
  border-color: rgba(37, 99, 235, 0.18);
  background: rgba(37, 99, 235, 0.07);
}
.detail-step__phase--episode {
  color: #047857;
  border-color: rgba(16, 185, 129, 0.2);
  background: rgba(16, 185, 129, 0.08);
}

.wf-card__footer {
  margin-top: auto;
  padding-top: var(--space-md);
  border-top: 1px solid var(--hairline);
  display: flex;
  flex-direction: column;
  gap: 12px;
}
.wf-card__utility {
  min-height: 28px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-sm);
}
.detail-btn {
  height: 28px;
  border: 1px solid transparent;
  border-radius: var(--radius-sm);
  background: transparent;
  padding: 0 8px;
  color: var(--muted);
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  white-space: nowrap;

  &:hover {
    color: var(--body);
    background: var(--surface-elevated);
    border-color: var(--hairline);
  }
}
.more-btn {
  width: 32px;
  height: 28px;
  padding-inline: 0;
}
.wf-card__actions {
  display: grid;
  gap: 8px;
  width: 100%;

  :deep(.el-button) {
    width: 100%;
    min-width: 0;
    margin-left: 0;
    justify-content: center;
    white-space: nowrap;
  }

  :deep(.el-button > span) {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}
.wf-card__actions--official {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}
.wf-card__actions--user {
  grid-template-columns: 1fr 1.8fr;
}

@media (max-width: 720px) {
  .wf-lib__body { padding: var(--space-lg) var(--space-md); }
  .card-grid { grid-template-columns: 1fr; }
  .wf-card { min-height: 260px; }
  .wf-card__actions--official { grid-template-columns: 1fr; }
  .wf-card__actions--user {
    grid-template-columns: 1fr;
  }
}
</style>
