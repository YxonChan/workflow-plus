<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { ElMessage } from 'element-plus'
import { useI18n } from 'vue-i18n'
import type { Asset, AssetType, Episode } from '@/types'
import ProductionTimeline from '@/components/ui/ProductionTimeline.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import AssetLinkedText from '@/components/series/AssetLinkedText.vue'
import { renderCallSheetPdf } from '@/api/callSheet'

interface Stage {
  id: string
  title: string
  kind: string
  status: 'queued' | 'running' | 'success' | 'failed' | 'skipped' | 'stale'
  desc?: string
  outputText?: string
  outputJson?: Record<string, any>
}

interface Props {
  episode: Episode
  stages: Stage[]
  episodes: Episode[]
  assets?: Asset[]
  saving?: boolean
  locked?: boolean
}
const props = defineProps<Props>()
const { t } = useI18n()
const emit = defineEmits<{
  (e: 'back'): void
  (e: 'run-next', stageId: string): void
  (e: 'save-plot'): void
  (e: 'update-plot', v: string): void
  (e: 'open-stage-detail', stageId: string): void
  (e: 'select-episode', id: number): void
}>()

const activeId = ref<string | null>(null)
watch(() => props.stages, (s) => {
  if (!activeId.value || !s.some((x) => x.id === activeId.value)) {
    const firstUnfinished = s.find((x) => x.status !== 'success' && x.status !== 'skipped')
    activeId.value = (firstUnfinished ?? s[0])?.id ?? null
  }
}, { immediate: true })

const activeStage = computed(() => props.stages.find((s) => s.id === activeId.value) ?? null)
const timelineItems = computed(() => props.stages.map((s) => ({ id: s.id, title: s.title, status: s.status })))

const STATUS = {
  queued: { text: '待生成', tone: 'idle' },
  running: { text: '生成中', tone: 'busy' },
  success: { text: '已完成', tone: 'done' },
  failed: { text: '失败', tone: 'fail' },
  skipped: { text: '跳过', tone: 'idle' },
  stale: { text: '需重生成', tone: 'warning' },
} as const

function shotsWithImage() {
  return (props.episode.shots ?? []).filter((s) => s.image_url)
}
function shotsWithVideo() {
  return (props.episode.shots ?? []).filter((s) => s.video_url)
}
const finalVideoUrl = computed(() => {
  const out = props.stages.find((s) => s.kind === 'output')
  const url = out?.outputJson?.video_url
  return typeof url === 'string' ? url : ''
})

const plot = computed({
  get: () => props.episode.plot_input ?? '',
  set: (v: string) => emit('update-plot', v),
})

// ── 通告单（HTML 文本节点）预览与导出 ────────────────────────────────────────────
function isHtmlDocument(content: string): boolean {
  return /<(?:!doctype|html|head|body|table)\b/i.test(content)
}

const activeStageHtml = computed(() => {
  const stage = activeStage.value
  if (!stage || stage.kind !== 'text') return ''
  const text = stage.outputText ?? ''
  return isHtmlDocument(text) ? text : ''
})

const callSheetPdfLoading = ref(false)

function downloadStageHtml() {
  const html = activeStageHtml.value
  if (!html) return
  const blob = new Blob([html], { type: 'text/html;charset=utf-8' })
  downloadBlob(`episode-${props.episode.id}-call-sheet.html`, blob)
}

async function downloadStagePdf() {
  const html = activeStageHtml.value
  if (!html) return
  callSheetPdfLoading.value = true
  try {
    const result = await renderCallSheetPdf(html, props.episode.id)
    downloadBlob(result.filename, base64ToBlob(result.base64, result.mime || 'application/pdf'))
    ElMessage.success(t('PDF 已生成'))
  } finally {
    callSheetPdfLoading.value = false
  }
}

function base64ToBlob(base64: string, mime: string) {
  const binary = atob(base64)
  const bytes = new Uint8Array(binary.length)
  for (let i = 0; i < binary.length; i += 1) {
    bytes[i] = binary.charCodeAt(i)
  }
  return new Blob([bytes], { type: mime })
}

function downloadBlob(filename: string, blob: Blob) {
  const url = URL.createObjectURL(blob)
  const link = document.createElement('a')
  link.href = url
  link.download = filename
  document.body.appendChild(link)
  link.click()
  link.remove()
  URL.revokeObjectURL(url)
}

function epStatusTone(ep: Episode): 'busy' | 'done' | 'idle' {
  const running = (ep.workflow_state?.nodes ?? []).some((n) => n.status === 'running' || n.status === 'queued')
  if (running) return 'busy'
  if (ep.status === 'done') return 'done'
  return 'idle'
}

const sortedEpisodes = computed(() => [...props.episodes].sort((a, b) => a.number - b.number))

// ── Asset Reference (third column) ──────────────────────────────────────────────
const ASSET_LABELS: Record<AssetType, string> = { character: '人物', scene: '场景', prop: '道具' }

function assetImg(a: Asset): string {
  return (a.images ?? []).find((i) => i.view_type === 'main' && (i.url || '').trim() !== '')?.url
    ?? (a.images ?? []).find((i) => (i.url || '').trim() !== '')?.url
    ?? ''
}

const assetGroups = computed(() => {
  const g: Record<AssetType, Asset[]> = { character: [], scene: [], prop: [] }
  for (const a of props.assets ?? []) {
    if (a.type === 'character' || a.type === 'scene' || a.type === 'prop') g[a.type].push(a)
  }
  return g
})

const hasAssets = computed(() => (props.assets ?? []).length > 0)

const previewAsset = ref<Asset | null>(null)
const previewVisible = ref(false)

function previewAssetImages(asset: Asset): string[] {
  const urls = (asset.images ?? [])
    .map((i) => (i.url || '').trim())
    .filter((u) => u !== '')
  // 主视图优先排在最前
  const main = (asset.images ?? []).find((i) => i.view_type === 'main' && (i.url || '').trim() !== '')?.url
  if (main) {
    return [main, ...urls.filter((u) => u !== main)]
  }
  return urls
}

const previewImages = computed(() => (previewAsset.value ? previewAssetImages(previewAsset.value) : []))
const previewActiveUrl = ref('')

function openAssetPreview(asset: Asset) {
  if (!assetImg(asset)) return
  previewAsset.value = asset
  previewActiveUrl.value = previewAssetImages(asset)[0] ?? ''
  previewVisible.value = true
}
</script>

<template>
  <div class="studio-cockpit">
    <!-- Left Pane: Episode Directory -->
    <aside class="studio-sidebar">
      <div class="sidebar-header">
        <span>{{ t('剧集目录') }}</span>
      </div>
      <div class="sidebar-list scrollable">
        <button
          v-for="ep in sortedEpisodes"
          :key="ep.id"
          type="button"
          class="sidebar-ep-item"
          :class="{ 'is-active': ep.id === episode.id }"
          @click="emit('select-episode', ep.id)"
        >
          <div class="ep-info">
            <span class="ep-number">{{ t('第 {number} 集', { number: ep.number }) }}</span>
            <span class="ep-title" v-if="ep.title">{{ ep.title }}</span>
          </div>
          <span class="status-dot" :class="`is-${epStatusTone(ep)}`" />
        </button>
      </div>
    </aside>

    <!-- Right Pane: Active Workspace -->
    <div class="studio-workspace">
      <!-- 阶段进度条 -->
      <div class="stages">
        <ProductionTimeline
          :items="timelineItems"
          :active-id="activeId"
          @select="activeId = $event"
        />
        <div class="stages__spacer" />
      </div>

      <div class="studio__body scrollable">
        <div v-if="activeStage" class="stage-panel">
          <div class="stage-panel__head">
            <h3>{{ activeStage.title }}</h3>
            <StatusBadge
              :tone="STATUS[activeStage.status].tone === 'fail' ? 'fail' : STATUS[activeStage.status].tone === 'done' ? 'done' : STATUS[activeStage.status].tone === 'busy' ? 'busy' : 'idle'"
              :pulse="activeStage.status === 'running'"
            >
              {{ STATUS[activeStage.status].text }}
            </StatusBadge>
            <div class="stage-panel__actions">
              <el-button
                size="small"
                :disabled="locked || activeStage.status === 'running'"
                @click="emit('run-next', activeStage.id)"
              >{{ t(activeStage.status === 'success' || activeStage.status === 'stale' ? '重新生成此步' : '生成此步') }}</el-button>
              <el-button
                v-if="['image','video','output','text'].includes(activeStage.kind)"
                size="small"
                @click="emit('open-stage-detail', activeStage.id)"
              >{{ t('精修 / 详情') }}</el-button>
            </div>
          </div>

          <!-- 输入：剧情 -->
          <div v-if="activeStage.kind === 'input'" class="stage-content">
            <p class="field-label">{{ t('剧情简介（注入流程的起点）') }}</p>
            <el-input v-model="plot" type="textarea" :rows="6" :disabled="locked" :placeholder="t('填写这一集的剧情简介…')" />
            <el-button type="primary" size="small" class="mt" :loading="saving" :disabled="locked" @click="emit('save-plot')">{{ t('保存剧情') }}</el-button>
          </div>

          <!-- 文本：剧情/分镜文本，通告单等 HTML 输出走 iframe 预览 -->
          <div v-else-if="activeStage.kind === 'text'" class="stage-content">
            <template v-if="activeStageHtml">
              <div class="call-sheet-actions">
                <el-button size="small" @click="downloadStageHtml">
                  <el-icon><Download /></el-icon><span>{{ t('下载 HTML') }}</span>
                </el-button>
                <el-button size="small" type="primary" :loading="callSheetPdfLoading" @click="downloadStagePdf">
                  <el-icon v-if="!callSheetPdfLoading"><Document /></el-icon><span>{{ t('导出 PDF') }}</span>
                </el-button>
              </div>
              <iframe class="call-sheet-frame" :srcdoc="activeStageHtml" :title="t('通告单预览')" sandbox="" />
            </template>
            <AssetLinkedText
              v-else-if="activeStage.outputText"
              class="text-output"
              :text="activeStage.outputText"
              :assets="assets ?? []"
              @open-asset="openAssetPreview"
            />
            <div v-else class="stage-empty">还没有内容，点「生成此步」。</div>
          </div>

          <!-- 画面：分镜首帧 -->
          <div v-else-if="activeStage.kind === 'image'" class="stage-content">
            <div v-if="shotsWithImage().length" class="media-grid">
              <div v-for="s in shotsWithImage()" :key="s.id" class="media-cell">
                <img :src="s.image_url" alt="" />
              </div>
            </div>
            <div v-else class="stage-empty">还没有画面，点「生成此步」。</div>
          </div>

          <!-- 视频：分镜片段 -->
          <div v-else-if="activeStage.kind === 'video'" class="stage-content">
            <div v-if="shotsWithVideo().length" class="media-grid">
              <div v-for="s in shotsWithVideo()" :key="s.id" class="media-cell">
                <video :src="s.video_url" :poster="s.image_url || undefined" controls preload="metadata" />
              </div>
            </div>
            <div v-else class="stage-empty">还没有视频片段，点「生成此步」。逐镜头重生成/换版本请点「精修 / 详情」。</div>
          </div>

          <!-- 成片 -->
          <div v-else-if="activeStage.kind === 'output'" class="stage-content">
            <div v-if="finalVideoUrl" class="final-video">
              <video :src="finalVideoUrl" controls preload="metadata" />
            </div>
            <div v-else class="stage-empty">所有镜头完成后，点「生成此步」合成最终成片。</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Right Pane: Asset Reference -->
    <aside class="studio-assets">
      <div class="assets-header">
        <span>资产参考</span>
      </div>
      <div class="assets-list scrollable">
        <template v-if="hasAssets">
          <template v-for="(label, key) in ASSET_LABELS" :key="key">
            <section v-if="assetGroups[key as AssetType].length" class="asset-group">
              <div class="asset-group__title">
                <span>{{ label }}</span>
                <em>{{ assetGroups[key as AssetType].length }}</em>
              </div>
              <div class="asset-grid">
                <button
                  v-for="a in assetGroups[key as AssetType]"
                  :key="a.id"
                  type="button"
                  class="asset-tile"
                  :class="{ 'is-clickable': !!assetImg(a) }"
                  :title="a.name"
                  @click="openAssetPreview(a)"
                >
                  <div class="asset-tile__thumb">
                    <img v-if="assetImg(a)" :src="assetImg(a)" alt="" loading="lazy" />
                    <div v-else class="asset-tile__empty">{{ a.name.slice(0, 1) }}</div>
                    <div v-if="assetImg(a)" class="asset-tile__zoom">
                      <el-icon><ZoomIn /></el-icon>
                    </div>
                  </div>
                  <span class="asset-tile__name">{{ a.name }}</span>
                </button>
              </div>
            </section>
          </template>
        </template>
        <div v-else class="assets-empty">
          <el-icon :size="28"><Box /></el-icon>
          <p>暂无资产</p>
          <span>到「资源包」里添加人物、场景、道具与人物造型</span>
        </div>
      </div>
    </aside>

    <!-- Asset Lightbox -->
    <el-dialog
      v-model="previewVisible"
      class="asset-ref-lightbox"
      width="min(92vw, 1040px)"
      append-to-body
      align-center
      :title="previewAsset?.name || '资产预览'"
    >
      <div v-if="previewAsset" class="asset-ref-preview">
        <div class="asset-ref-preview__stage">
          <img v-if="previewActiveUrl" :src="previewActiveUrl" alt="" />
        </div>
        <div class="asset-ref-preview__side">
          <div class="asset-ref-preview__meta">
            <strong>{{ previewAsset.name }}</strong>
            <span class="asset-ref-preview__type">{{ ASSET_LABELS[previewAsset.type] }}</span>
            <p>{{ previewAsset.description || '暂无资产描述' }}</p>
            <div v-if="previewAsset.tags?.length" class="asset-ref-preview__tags">
              <span v-for="t in previewAsset.tags" :key="t">{{ t }}</span>
            </div>
          </div>
          <div v-if="previewImages.length > 1" class="asset-ref-preview__thumbs">
            <button
              v-for="(url, i) in previewImages"
              :key="i"
              type="button"
              class="asset-ref-preview__thumb"
              :class="{ 'is-active': url === previewActiveUrl }"
              @click="previewActiveUrl = url"
            >
              <img :src="url" alt="" loading="lazy" />
            </button>
          </div>
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<style scoped lang="scss">
.studio-cockpit {
  flex: 1;
  display: flex;
  min-height: 0;
  height: 100%;
  overflow: hidden;
  background: var(--canvas);
}

// ── Left Sidebar (Episodes Directory) ──────────────────────────────────────────
.studio-sidebar {
  width: 200px;
  border-right: 1px solid var(--hairline);
  background: var(--surface-card);
  display: flex;
  flex-direction: column;
  flex-shrink: 0;
}

.sidebar-header {
  height: 48px;
  display: flex;
  align-items: center;
  padding: 0 var(--space-md);
  border-bottom: 1px solid var(--hairline);
  font-size: 11px;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.sidebar-list {
  flex: 1;
  padding: var(--space-sm) 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.sidebar-ep-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-sm) var(--space-md);
  border: none;
  background: transparent;
  cursor: pointer;
  text-align: left;
  border-radius: var(--radius-sm);
  margin: 0 8px;
  transition: all var(--duration-fast);

  &:hover {
    background: var(--surface-soft);
  }

  &.is-active {
    background: var(--surface-soft);
    
    .ep-number {
      color: var(--primary);
      font-weight: 700;
    }
  }
}

.ep-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.ep-number {
  font-size: 13px;
  font-weight: 500;
  color: var(--on-dark);
}

.ep-title {
  font-size: 11px;
  color: var(--muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-top: 1px;
}

.status-dot {
  width: 6px;
  height: 6px;
  border-radius: var(--radius-full);
  flex-shrink: 0;
  margin-left: var(--space-sm);

  &.is-idle {
    background: var(--muted-soft);
  }

  &.is-busy {
    background: var(--accent-blue);
    box-shadow: 0 0 6px rgba(59, 130, 246, 0.4);
  }

  &.is-done {
    background: var(--accent-emerald);
  }
}

// ── Center Workspace ──────────────────────────────────────────────────────────
.studio-workspace {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

// ── Right Sidebar (Asset Reference) ─────────────────────────────────────────────
.studio-assets {
  width: 300px;
  flex-shrink: 0;
  border-left: 1px solid var(--hairline);
  background: var(--surface-card);
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.assets-header {
  height: 48px;
  display: flex;
  align-items: center;
  padding: 0 var(--space-md);
  border-bottom: 1px solid var(--hairline);
  font-size: 11px;
  font-weight: 700;
  color: var(--muted);
  text-transform: uppercase;
  letter-spacing: 0.5px;
}

.assets-list {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: var(--space-md);
  display: flex;
  flex-direction: column;
  gap: var(--space-lg);
}

.asset-group__title {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 10px;
  font-size: 12px;
  font-weight: 700;
  color: var(--body-strong);

  em {
    font-style: normal;
    min-width: 18px;
    height: 18px;
    padding: 0 6px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: var(--radius-pill);
    background: var(--surface-soft);
    color: var(--muted);
    font-size: 10px;
    font-weight: 700;
  }
}

.asset-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.asset-tile {
  min-width: 0;
  padding: 0;
  border: 0;
  background: transparent;
  text-align: center;
  cursor: default;

  &.is-clickable {
    cursor: zoom-in;
  }
}

.asset-tile__thumb {
  position: relative;
  aspect-ratio: 1 / 1;
  border-radius: var(--radius-md);
  overflow: hidden;
  border: 1px solid var(--hairline);
  background: var(--surface-soft);

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
    transition: transform var(--duration-fast);
  }
}

.asset-tile__empty {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--muted);
  font-weight: 700;
  font-size: 20px;
}

.asset-tile__zoom {
  position: absolute;
  inset: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  background: rgba(0, 0, 0, 0.4);
  color: #fff;
  opacity: 0;
  transition: opacity var(--duration-fast);

  .el-icon {
    font-size: 22px;
  }
}

.asset-tile.is-clickable:hover {
  .asset-tile__thumb {
    border-color: rgba(var(--brand-cyan-rgb), 0.45);
  }
  img {
    transform: scale(1.05);
  }
  .asset-tile__zoom {
    opacity: 1;
  }
}

.asset-tile__name {
  display: block;
  margin-top: 6px;
  font-size: 11px;
  color: var(--muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.assets-empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: var(--space-xl) var(--space-md);
  text-align: center;
  color: var(--muted-soft);

  .el-icon { color: var(--muted-soft); }
  p { margin: 4px 0 0; font-size: 13px; color: var(--muted); font-weight: 600; }
  span { font-size: 11px; line-height: 1.5; }
}

// ── Asset Lightbox ──────────────────────────────────────────────────────────────
.asset-ref-preview {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 260px;
  gap: var(--space-lg);
  align-items: start;
}

.asset-ref-preview__stage {
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--surface-soft);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  overflow: hidden;
  min-height: 320px;

  img {
    width: 100%;
    max-height: 74vh;
    object-fit: contain;
    display: block;
  }
}

.asset-ref-preview__side {
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
  min-width: 0;
}

.asset-ref-preview__meta {
  display: flex;
  flex-direction: column;
  gap: 6px;
  color: var(--body);
  font-size: 13px;

  strong {
    color: var(--on-dark);
    font-size: 17px;
  }

  p {
    margin: 4px 0 0;
    line-height: 1.6;
    color: var(--muted);
  }
}

.asset-ref-preview__type {
  align-self: flex-start;
  padding: 2px 10px;
  border-radius: var(--radius-pill);
  background: rgba(var(--brand-cyan-rgb), 0.08);
  border: 1px solid rgba(var(--brand-cyan-rgb), 0.22);
  color: var(--primary);
  font-size: 11px;
  font-weight: 700;
}

.asset-ref-preview__tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-top: 4px;

  span {
    padding: 2px 8px;
    border-radius: var(--radius-sm);
    background: var(--surface-soft);
    border: 1px solid var(--hairline);
    font-size: 11px;
    color: var(--muted);
  }
}

.asset-ref-preview__thumbs {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
}

.asset-ref-preview__thumb {
  padding: 0;
  border: 2px solid transparent;
  border-radius: var(--radius-sm);
  overflow: hidden;
  background: var(--surface-soft);
  cursor: pointer;
  aspect-ratio: 1 / 1;

  img {
    width: 100%;
    height: 100%;
    object-fit: cover;
    display: block;
  }

  &.is-active {
    border-color: var(--primary);
  }
}

@media (max-width: 720px) {
  .asset-ref-preview {
    grid-template-columns: 1fr;
  }
}

.stages {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: var(--space-sm) var(--space-lg);
  border-bottom: 1px solid var(--hairline);
  background: var(--surface-card);
  overflow-x: auto;
  height: 48px;
  flex-shrink: 0;
}

.stages__spacer {
  flex: 1;
  min-width: 12px;
}

.studio__body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: var(--space-xl);
}

.stage-panel {
  max-width: 1100px;
  margin: 0 auto;
}

.stage-panel__head {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  margin-bottom: var(--space-lg);

  h3 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: var(--on-dark);
    letter-spacing: 0;
  }
}

.stage-panel__status {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: var(--radius-pill);
  background: var(--surface-soft);
  color: var(--muted);

  &.is-busy {
    color: var(--accent-blue);
  }

  &.is-done {
    color: var(--accent-emerald);
  }

  &.is-fail {
    color: var(--accent-rose);
  }
}

.stage-panel__actions {
  margin-left: auto;
  display: flex;
  gap: 6px;
}

.field-label {
  font-size: 12px;
  color: var(--muted);
  margin: 0 0 6px;
}

.mt {
  margin-top: var(--space-sm);
}

.text-output {
  white-space: pre-wrap;
  word-break: break-word;
  background: var(--surface-card);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  padding: var(--space-md);
  font-size: 13px;
  line-height: 1.6;
  color: var(--body);
  margin: 0;
  font-family: var(--font-sans);
}

.call-sheet-actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-xs);
  margin-bottom: var(--space-sm);
}

.call-sheet-frame {
  width: 100%;
  min-height: 520px;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-card);
}

.stage-empty {
  padding: var(--space-xl);
  text-align: center;
  color: var(--muted-soft);
  font-size: 13px;
  background: var(--surface-soft);
  border: 1px dashed var(--hairline-strong);
  border-radius: var(--radius-md);
}

.media-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
  gap: var(--space-md);
}

.media-cell {
  border-radius: var(--radius-md);
  overflow: hidden;
  border: 1px solid var(--hairline);
  background: var(--surface-soft);

  img, video {
    width: 100%;
    display: block;
    aspect-ratio: 16/9;
    object-fit: cover;
    background: #000;
  }
}

.final-video {
  max-width: 640px;
  margin: 0 auto;

  video {
    width: 100%;
    border-radius: var(--radius-lg);
    border: 1px solid var(--hairline);
    background: #000;
  }
}
</style>
