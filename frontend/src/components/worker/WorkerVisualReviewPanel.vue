<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useI18n } from 'vue-i18n'
import { listAssets } from '@/api/asset'
import { listSeries } from '@/api/series'
import {
  applyVisualRepairs,
  previewVisualRepairs,
  runVisualReview,
  uploadWorkerReference,
  type RepairPreviewResult,
  type VisualIssueDecision,
  type VisualReferencePayload,
  type VisualReviewResult,
} from '@/api/workerVisualReview'
import { readWorkerPageContext } from '@/utils/workerContext'
import type { Asset, Series } from '@/types'

interface SelectedReference {
  key: string
  kind: 'asset' | 'upload'
  asset_id: number
  asset_name: string
  image_url: string
  asset_image_id?: number
  asset_image_version_id?: number
  upload_token?: string
  label: string
}

interface PickerImage {
  key: string
  asset_id: number
  asset_name: string
  image_url: string
  asset_image_id: number
  asset_image_version_id?: number
  label: string
}

const emit = defineEmits<{ applied: [message: string] }>()
const { t } = useI18n()

const expanded = ref(false)
const loadingScope = ref(false)
const uploading = ref(false)
const reviewing = ref(false)
const previewing = ref(false)
const applying = ref(false)
const dragging = ref(false)
const pickerOpen = ref(false)
const fileInput = ref<HTMLInputElement | null>(null)
const seriesList = ref<Series[]>([])
const assets = ref<Asset[]>([])
const selectedSeriesId = ref<number | null>(null)
const selectedEpisodeId = ref<number | null>(null)
const references = ref<SelectedReference[]>([])
const review = ref<VisualReviewResult | null>(null)
const preview = ref<RepairPreviewResult | null>(null)
const decisions = ref<Record<string, VisualIssueDecision>>({})
const appliedRevisionId = ref<number | null>(null)

const selectedSeries = computed(() =>
  seriesList.value.find((series) => series.id === selectedSeriesId.value) ?? null,
)
const episodes = computed(() => selectedSeries.value?.episodes ?? [])
const characterAssets = computed(() => assets.value.filter((asset) => asset.type === 'character'))
const pickerImages = computed<PickerImage[]>(() => {
  const items: PickerImage[] = []
  for (const asset of characterAssets.value) {
    for (const image of asset.images ?? []) {
      const imageId = Number(image.id ?? 0)
      const versions = (image.versions ?? []).filter((version) => String(version.url || '').trim() !== '')
      if (versions.length > 0) {
        for (const version of versions) {
          items.push({
            key: `version:${version.id}`,
            asset_id: asset.id,
            asset_name: asset.name,
            asset_image_id: imageId,
            asset_image_version_id: version.id,
            image_url: version.url,
            label: image.variant_name || image.note || `${image.view_type} · #${version.id}`,
          })
        }
        continue
      }
      if (imageId > 0 && String(image.url || '').trim() !== '') {
        items.push({
          key: `image:${imageId}`,
          asset_id: asset.id,
          asset_name: asset.name,
          asset_image_id: imageId,
          image_url: image.url,
          label: image.variant_name || image.note || image.view_type,
        })
      }
    }
  }
  return items
})
const invalidUploadReference = computed(() =>
  references.value.some((reference) => reference.kind === 'upload' && reference.asset_id <= 0),
)
const canReview = computed(() =>
  selectedEpisodeId.value !== null
  && references.value.length > 0
  && !invalidUploadReference.value
  && !reviewing.value,
)
const modifyCount = computed(() =>
  review.value?.issues.filter((issue) => decisions.value[issue.issue_id] === 'modify').length ?? 0,
)

onMounted(async () => {
  loadingScope.value = true
  try {
    seriesList.value = await listSeries()
    const context = readWorkerPageContext()
    if (context?.series_id && seriesList.value.some((series) => series.id === context.series_id)) {
      selectedSeriesId.value = context.series_id
      await loadAssets()
      if (context.episode_id && episodes.value.some((episode) => episode.id === context.episode_id)) {
        selectedEpisodeId.value = context.episode_id
      }
    }
  } finally {
    loadingScope.value = false
  }
})

async function loadAssets() {
  if (!selectedSeriesId.value) {
    assets.value = []
    return
  }
  assets.value = await listAssets({ series_id: selectedSeriesId.value })
}

async function onSeriesChanged() {
  selectedEpisodeId.value = null
  references.value = []
  clearResults()
  pickerOpen.value = false
  await loadAssets()
}

function onEpisodeChanged() {
  references.value = []
  clearResults()
  pickerOpen.value = false
}

function clearResults() {
  review.value = null
  preview.value = null
  decisions.value = {}
  appliedRevisionId.value = null
}

function ensureEpisodeSelected(): boolean {
  if (!selectedSeriesId.value || !selectedEpisodeId.value) {
    ElMessage.warning(t('请先选择作品和要检查的剧集'))
    return false
  }
  return true
}

function openFilePicker() {
  if (!ensureEpisodeSelected()) return
  fileInput.value?.click()
}

function toggleAssetPicker() {
  if (!ensureEpisodeSelected()) return
  pickerOpen.value = !pickerOpen.value
}

async function onFiles(files: File[]) {
  if (!ensureEpisodeSelected() || files.length === 0) return
  const available = Math.max(0, 3 - references.value.length)
  if (available <= 0) return ElMessage.warning(t('每次最多引用 3 张人物图片'))
  const selected = files.slice(0, available)
  uploading.value = true
  try {
    for (const file of selected) {
      if (!['image/jpeg', 'image/png', 'image/gif', 'image/webp'].includes(file.type)) {
        ElMessage.warning(t('{name}不是支持的图片格式', { name: file.name }))
        continue
      }
      if (file.size > 10 * 1024 * 1024) {
        ElMessage.warning(t('{name}超过 10MB', { name: file.name }))
        continue
      }
      const result = await uploadWorkerReference(file)
      references.value.push({
        key: `upload:${result.reference_token}`,
        kind: 'upload',
        asset_id: 0,
        asset_name: '',
        image_url: result.url,
        upload_token: result.reference_token,
        label: file.name,
      })
    }
    clearResults()
  } finally {
    uploading.value = false
  }
}

function onFileInput(event: Event) {
  const input = event.target as HTMLInputElement
  void onFiles(Array.from(input.files ?? []))
  input.value = ''
}

function onDrop(event: DragEvent) {
  dragging.value = false
  void onFiles(Array.from(event.dataTransfer?.files ?? []))
}

function addAssetReference(item: PickerImage) {
  if (references.value.length >= 3) return ElMessage.warning(t('每次最多引用 3 张人物图片'))
  if (references.value.some((reference) => reference.key === item.key)) return
  references.value.push({
    key: item.key,
    kind: 'asset',
    asset_id: item.asset_id,
    asset_name: item.asset_name,
    asset_image_id: item.asset_image_id,
    asset_image_version_id: item.asset_image_version_id,
    image_url: item.image_url,
    label: item.label,
  })
  pickerOpen.value = false
  clearResults()
}

function bindUploadAsset(reference: SelectedReference, value: string) {
  const assetId = Number(value)
  const asset = characterAssets.value.find((item) => item.id === assetId)
  reference.asset_id = asset?.id ?? 0
  reference.asset_name = asset?.name ?? ''
  clearResults()
}

function removeReference(key: string) {
  references.value = references.value.filter((reference) => reference.key !== key)
  clearResults()
}

function referencePayload(reference: SelectedReference): VisualReferencePayload {
  return {
    kind: reference.kind,
    asset_id: reference.asset_id,
    asset_image_id: reference.asset_image_id,
    asset_image_version_id: reference.asset_image_version_id,
    upload_token: reference.upload_token,
  }
}

async function runReview() {
  if (!canReview.value || !selectedEpisodeId.value) return
  reviewing.value = true
  clearResults()
  try {
    review.value = await runVisualReview(selectedEpisodeId.value, references.value.map(referencePayload))
    decisions.value = Object.fromEntries(review.value.issues.map((issue) => [issue.issue_id, 'ignore']))
    if (review.value.issue_count === 0) {
      ElMessage.success(t('本集未发现明确的人物外观文本冲突'))
    }
  } finally {
    reviewing.value = false
  }
}

async function buildPreview() {
  if (!review.value || modifyCount.value <= 0) return
  previewing.value = true
  preview.value = null
  try {
    preview.value = await previewVisualRepairs(
      review.value.review_token,
      review.value.issues.map((issue) => ({
        issue_id: issue.issue_id,
        action: decisions.value[issue.issue_id] ?? 'ignore',
      })),
    )
  } finally {
    previewing.value = false
  }
}

async function confirmApply() {
  if (!preview.value || applying.value) return
  await ElMessageBox.confirm(
    t('将修改本集 {count} 个镜头并创建一个新分镜版本。其他镜头保持不变，是否继续？', {
      count: preview.value.modified_shot_count,
    }),
    t('确认修改分镜'),
    {
      type: 'warning',
      confirmButtonText: t('确认创建新版本'),
      cancelButtonText: t('取消'),
    },
  )
  applying.value = true
  try {
    const result = await applyVisualRepairs(preview.value.confirmation_token)
    appliedRevisionId.value = result.storyboard_revision_id
    ElMessage.success(t('已创建新分镜版本 #{id}', { id: result.storyboard_revision_id }))
    emit('applied', t('已确认修改 {count} 个镜头，并创建分镜版本 #{id}。', {
      count: result.modified_shot_count,
      id: result.storyboard_revision_id,
    }))
  } finally {
    applying.value = false
  }
}
</script>

<template>
  <section class="visual-review">
    <button class="visual-review__toggle" type="button" @click="expanded = !expanded">
      <span>▣ {{ t('单集图片核验') }}</span>
      <small>{{ t('拖图或 @ 引用资产 · 只读检查后再确认修改') }}</small>
      <b>{{ expanded ? '−' : '+' }}</b>
    </button>

    <div v-if="expanded" class="visual-review__body">
      <div class="scope-row">
        <select v-model="selectedSeriesId" :disabled="loadingScope" @change="onSeriesChanged">
          <option :value="null">{{ t('先选择作品') }}</option>
          <option v-for="series in seriesList" :key="series.id" :value="series.id">{{ series.title }}</option>
        </select>
        <select v-model="selectedEpisodeId" :disabled="!selectedSeriesId" @change="onEpisodeChanged">
          <option :value="null">{{ t('再选择一集') }}</option>
          <option v-for="episode in episodes" :key="episode.id" :value="episode.id">
            {{ t('第 {number} 集 · {title}', { number: episode.number, title: episode.title }) }}
          </option>
        </select>
      </div>

      <div
        class="reference-drop"
        :class="{ 'is-dragging': dragging, 'is-disabled': !selectedEpisodeId }"
        @dragenter.prevent="dragging = true"
        @dragover.prevent="dragging = true"
        @dragleave.prevent="dragging = false"
        @drop.prevent="onDrop"
      >
        <span>{{ selectedEpisodeId ? t('拖入人物图片，或从资产库精确引用') : t('选择剧集后才能添加人物图片') }}</span>
        <div>
          <button type="button" :disabled="!selectedEpisodeId || uploading" @click="openFilePicker">
            {{ uploading ? t('上传中…') : t('＋ 拖入/上传') }}
          </button>
          <button type="button" :disabled="!selectedEpisodeId" @click="toggleAssetPicker">@ {{ t('引用资产') }}</button>
        </div>
        <input ref="fileInput" hidden type="file" accept="image/jpeg,image/png,image/gif,image/webp" multiple @change="onFileInput">
      </div>

      <div v-if="pickerOpen" class="asset-picker">
        <div v-if="!pickerImages.length" class="asset-picker__empty">{{ t('当前作品没有可引用的人物图片') }}</div>
        <button v-for="item in pickerImages" :key="item.key" type="button" @click="addAssetReference(item)">
          <img :src="item.image_url" :alt="item.asset_name">
          <span><b>@{{ item.asset_name }}</b><small>{{ item.label }}</small></span>
        </button>
      </div>

      <div v-if="references.length" class="reference-list">
        <div v-for="reference in references" :key="reference.key" class="reference-item">
          <img :src="reference.image_url" :alt="reference.asset_name || reference.label">
          <span>
            <b>{{ reference.asset_name ? `@${reference.asset_name}` : reference.label }}</b>
            <small>{{ reference.kind === 'upload' ? t('临时图片 · 不保存到资产库') : reference.label }}</small>
            <select
              v-if="reference.kind === 'upload'"
              :value="reference.asset_id || ''"
              @change="bindUploadAsset(reference, ($event.target as HTMLSelectElement).value)"
            >
              <option value="">{{ t('选择图片对应的人物') }}</option>
              <option v-for="asset in characterAssets" :key="asset.id" :value="asset.id">{{ asset.name }}</option>
            </select>
          </span>
          <button type="button" :title="t('移除')" @click="removeReference(reference.key)">×</button>
        </div>
      </div>

      <p v-if="invalidUploadReference" class="review-warning">{{ t('拖入的图片必须关联当前作品中的一个人物资产。') }}</p>
      <button class="review-run" type="button" :disabled="!canReview" @click="runReview">
        {{ reviewing ? t('正在识别图片并检查本集…') : t('只读检查本集分镜') }}
      </button>

      <div v-if="review" class="review-result">
        <div class="review-result__summary">
          <b>{{ t('已检查 {shots} 个镜头，发现 {issues} 个问题', { shots: review.checked_shots, issues: review.issue_count }) }}</b>
          <small>{{ review.limits }}</small>
        </div>
        <p v-if="review.unmentioned_assets.length" class="review-warning">
          {{ t('以下人物没有在本集分镜中被明确提及：{names}', { names: review.unmentioned_assets.join('、') }) }}
        </p>
        <article v-for="issue in review.issues" :key="issue.issue_id" class="review-issue">
          <div class="review-issue__head">
            <b>{{ t('镜头 {index} · {name} · {field}', { index: issue.shot_index, name: issue.asset_name, field: issue.field_label }) }}</b>
            <em :class="`is-${issue.severity}`">{{ issue.severity === 'error' ? t('错误') : t('需确认') }}</em>
          </div>
          <p>{{ issue.message }}</p>
          <small>{{ t('图片：{expected}；分镜证据：{evidence}；置信度：{confidence}%', {
            expected: issue.expected,
            evidence: issue.storyboard_evidence,
            confidence: Math.round(issue.confidence * 100),
          }) }}</small>
          <div class="issue-decisions">
            <label><input v-model="decisions[issue.issue_id]" type="radio" :name="issue.issue_id" value="modify">{{ t('修改分镜') }}</label>
            <label><input v-model="decisions[issue.issue_id]" type="radio" :name="issue.issue_id" value="exception">{{ t('剧情例外') }}</label>
            <label><input v-model="decisions[issue.issue_id]" type="radio" :name="issue.issue_id" value="ignore">{{ t('忽略') }}</label>
          </div>
        </article>
        <button v-if="review.issues.length" class="review-preview" type="button" :disabled="modifyCount === 0 || previewing" @click="buildPreview">
          {{ previewing ? t('正在生成差异预览…') : t('预览 {count} 项已选修改', { count: modifyCount }) }}
        </button>
      </div>

      <div v-if="preview" class="repair-preview">
        <b>{{ t('修改预览 · 仅影响 {count} 个镜头', { count: preview.modified_shot_count }) }}</b>
        <article v-for="item in preview.previews" :key="item.shot_id">
          <strong>{{ t('镜头 {index}', { index: item.shot_index }) }}</strong>
          <div><small>{{ t('修改前') }}</small><p>{{ item.before }}</p></div>
          <div class="is-after"><small>{{ t('修改后') }}</small><p>{{ item.after }}</p></div>
        </article>
        <p>{{ preview.impact }}</p>
        <button type="button" :disabled="applying || !!appliedRevisionId" @click="confirmApply">
          {{ appliedRevisionId ? t('已创建版本 #{id}', { id: appliedRevisionId }) : applying ? t('正在创建新版本…') : t('确认修改并创建新版本') }}
        </button>
      </div>
    </div>
  </section>
</template>

<style scoped lang="scss">
.visual-review {
  border: 1px solid rgba(15, 159, 154, 0.3);
  border-radius: 10px;
  background: var(--surface-card);
}

.visual-review__toggle {
  display: grid;
  width: 100%;
  grid-template-columns: 1fr auto;
  gap: 2px 8px;
  padding: 8px 10px;
  border: 0;
  background: transparent;
  color: var(--body-strong);
  text-align: left;
  cursor: pointer;

  span { font-size: 12px; font-weight: 700; }
  small { grid-column: 1; color: var(--muted); font-size: 10px; }
  b { grid-column: 2; grid-row: 1 / 3; align-self: center; color: var(--brand-cyan); }
}

.visual-review__body {
  display: flex;
  flex-direction: column;
  gap: 8px;
  padding: 0 9px 9px;
}

.scope-row {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 6px;
}

select {
  min-width: 0;
  padding: 5px 7px;
  border: 1px solid var(--hairline);
  border-radius: 7px;
  background: var(--surface-card);
  color: var(--body-strong);
  font-size: 11px;
}

.reference-drop {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  padding: 10px;
  border: 1px dashed rgba(15, 159, 154, 0.45);
  border-radius: 8px;
  color: var(--muted);
  font-size: 11px;
  transition: 0.15s ease;

  &.is-dragging { background: rgba(15, 159, 154, 0.08); border-color: var(--brand-cyan); }
  &.is-disabled { opacity: 0.65; }
  div { display: flex; gap: 6px; }
  button {
    padding: 4px 8px;
    border: 1px solid rgba(15, 159, 154, 0.35);
    border-radius: 7px;
    background: var(--surface-card);
    color: var(--brand-cyan);
    font-size: 11px;
    cursor: pointer;
  }
  button:disabled { cursor: not-allowed; opacity: 0.5; }
}

.asset-picker {
  display: grid;
  max-height: 180px;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 5px;
  overflow-y: auto;

  > button {
    display: flex;
    min-width: 0;
    gap: 6px;
    padding: 5px;
    border: 1px solid var(--hairline);
    border-radius: 7px;
    background: var(--surface-card);
    text-align: left;
    cursor: pointer;
  }
  img { width: 38px; height: 38px; border-radius: 5px; object-fit: cover; }
  span { min-width: 0; }
  b, small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  b { color: var(--body-strong); font-size: 10px; }
  small { color: var(--muted); font-size: 9px; }
}

.asset-picker__empty { grid-column: 1 / -1; color: var(--muted); font-size: 11px; text-align: center; }

.reference-list { display: flex; flex-direction: column; gap: 5px; }
.reference-item {
  display: grid;
  grid-template-columns: 42px 1fr auto;
  align-items: center;
  gap: 6px;
  padding: 5px;
  border-radius: 7px;
  background: var(--surface-soft);

  > img { width: 42px; height: 42px; border-radius: 5px; object-fit: cover; }
  > span { min-width: 0; }
  b, small { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
  b { color: var(--body-strong); font-size: 11px; }
  small { color: var(--muted); font-size: 9px; }
  select { width: 100%; margin-top: 3px; padding: 3px; }
  > button { border: 0; background: transparent; color: var(--muted); cursor: pointer; }
}

.review-warning { margin: 0; color: var(--brand-amber); font-size: 10px; }
.review-run, .review-preview, .repair-preview > button {
  padding: 7px 9px;
  border: 0;
  border-radius: 7px;
  background: var(--brand-cyan);
  color: #fff;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;

  &:disabled { cursor: not-allowed; opacity: 0.45; }
}

.review-result, .repair-preview { display: flex; flex-direction: column; gap: 7px; }
.review-result__summary {
  display: flex;
  flex-direction: column;
  gap: 2px;
  b { font-size: 11px; color: var(--body-strong); }
  small { font-size: 9px; color: var(--muted); }
}

.review-issue {
  padding: 7px;
  border: 1px solid var(--hairline);
  border-radius: 8px;
  background: var(--surface-raised);

  p { margin: 4px 0; color: var(--body); font-size: 10px; line-height: 1.45; }
  > small { color: var(--muted); font-size: 9px; line-height: 1.4; }
}

.review-issue__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 5px;
  b { font-size: 10px; color: var(--body-strong); }
  em { padding: 1px 5px; border-radius: 999px; font-size: 9px; font-style: normal; }
  .is-error { background: rgba(225, 29, 72, 0.1); color: var(--accent-rose); }
  .is-warning { background: rgba(245, 158, 11, 0.12); color: var(--brand-amber); }
}

.issue-decisions {
  display: flex;
  flex-wrap: wrap;
  gap: 7px;
  margin-top: 6px;
  label { color: var(--body); font-size: 10px; cursor: pointer; }
  input { margin: 0 2px 0 0; vertical-align: middle; }
}

.repair-preview {
  padding-top: 7px;
  border-top: 1px solid var(--hairline);

  > b { font-size: 11px; color: var(--body-strong); }
  > p { margin: 0; color: var(--muted); font-size: 9px; }
  article { display: flex; flex-direction: column; gap: 4px; }
  article > strong { font-size: 10px; color: var(--body-strong); }
  article div { padding: 5px; border-radius: 6px; background: rgba(225, 29, 72, 0.05); }
  article div.is-after { background: rgba(16, 185, 129, 0.07); }
  article small { color: var(--muted); font-size: 9px; }
  article p { max-height: 90px; margin: 2px 0 0; overflow-y: auto; color: var(--body); font-size: 9px; line-height: 1.4; white-space: pre-wrap; }
}

@media (max-width: 720px) {
  .scope-row, .asset-picker { grid-template-columns: 1fr; }
}
</style>
