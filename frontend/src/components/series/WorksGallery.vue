<script setup lang="ts">
import { computed } from 'vue'
import PageSkeleton from '@/components/ui/PageSkeleton.vue'
import { useI18n } from 'vue-i18n'
import { DocumentCopy, Plus } from '@element-plus/icons-vue'
import type { Series } from '@/types'
import PageToolbar from '@/components/ui/PageToolbar.vue'
import MediaCard from '@/components/ui/MediaCard.vue'
import StatusBadge from '@/components/ui/StatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { copyTextToClipboard } from '@/utils/clipboard'

interface Props {
  series: Series[]
  loading?: boolean
}
const props = defineProps<Props>()
const emit = defineEmits<{
  (e: 'open', id: number): void
  (e: 'new'): void
}>()
const { t } = useI18n()

function cover(s: Series): string | null {
  if (s.cover_url) return s.cover_url
  for (const ep of s.episodes ?? []) {
    if (ep.cover_url) return ep.cover_url
    for (const shot of ep.shots ?? []) {
      if (shot.image_url) return shot.image_url
    }
  }
  return null
}

function counts(s: Series) {
  const eps = s.episodes ?? []
  const done = eps.filter((e) => e.status === 'done').length
  return { total: eps.length, done }
}

function statusLabel(s: Series): { text: string; tone: 'busy' | 'done' | 'idle' } {
  const run = s.active_workflow_run
  if (run && (run.status === 'queued' || run.status === 'running')) {
    return { text: t('拆解中 {progress}%', { progress: Math.max(2, run.progress || 0) }), tone: 'busy' }
  }
  if (run && run.status === 'failed') {
    return { text: t('解析失败'), tone: 'idle' }
  }
  const { total, done } = counts(s)
  if (total === 0) return { text: t('待开始'), tone: 'idle' }
  if (done >= total) return { text: t('已完成'), tone: 'done' }
  return { text: t('{done}/{total} 集已成片', { done, total }), tone: 'idle' }
}

function badgeTone(s: Series): 'busy' | 'done' | 'warning' {
  const status = statusLabel(s)
  if (status.tone === 'busy') return 'busy'
  if (status.tone === 'done') return 'done'
  return 'warning'
}

const sorted = computed(() => [...props.series].sort((a, b) => b.id - a.id))

function copyCover(url: string | null, event: Event) {
  event.stopPropagation()
  void copyTextToClipboard(url || '', t('图片链接已复制'))
}
</script>

<template>
  <div class="gallery">
    <PageToolbar
      kicker="SERIES PRODUCTION"
      :title="t('作品库')"
      :subtitle="t('挑一个流程，从小说 / 一句话到批量短视频；每部作品在这里生产、查看。')"
    >
      <template #actions>
        <el-button type="primary" @click="emit('new')">
          <el-icon><Plus /></el-icon><span>{{ t('新建作品') }}</span>
        </el-button>
      </template>
    </PageToolbar>

    <div class="gallery__body scrollable">
      <PageSkeleton v-if="loading" variant="gallery" :count="8" />
      <div v-else-if="sorted.length" class="grid">
        <MediaCard
          v-for="s in sorted"
          :key="s.id"
          :image="cover(s)"
          :title="s.title || t('未命名作品')"
          :meta="`${t('{done}/{total} 集完成', { done: counts(s).done, total: counts(s).total })}${s.description ? ' · ' + s.description : ''}`"
          icon="Film"
          interactive
          @click="emit('open', s.id)"
        >
          <template #badge>
            <StatusBadge :tone="badgeTone(s)" :pulse="statusLabel(s).tone === 'busy'">
              {{ statusLabel(s).text }}
            </StatusBadge>
          </template>
          <template v-if="cover(s)" #actions>
            <el-button
              class="cover-copy-btn"
              size="small"
              circle
              :title="t('复制图片链接')"
              @click="copyCover(cover(s), $event)"
            >
              <el-icon><DocumentCopy /></el-icon>
            </el-button>
          </template>
        </MediaCard>
      </div>
      <EmptyState
        v-else
        icon="Film"
        :title="t('还没有作品')"
        :hint="t('点「新建作品」，选一个流程、给点输入，就能开始批量生产短视频。')"
      >
        <el-button type="primary" @click="emit('new')"><el-icon><Plus /></el-icon><span>{{ t('新建作品') }}</span></el-button>
      </EmptyState>
    </div>
  </div>
</template>

<style scoped lang="scss">
.gallery { display: flex; flex-direction: column; height: 100%; min-height: 0; background: var(--canvas); }

.gallery__body { flex: 1; min-height: 0; overflow-y: auto; padding: var(--space-xl) var(--space-xl); }
.grid {
  display: grid; grid-template-columns: repeat(auto-fill, minmax(232px, 1fr)); gap: var(--space-lg);
}

.cover-copy-btn {
  --el-button-bg-color: var(--surface-card);
  --el-button-border-color: rgba(148, 163, 184, 0.42);
  --el-button-text-color: var(--body);
  --el-button-hover-bg-color: var(--surface-elevated);
  --el-button-hover-border-color: var(--accent-cyan);
  --el-button-hover-text-color: var(--accent-cyan);
  width: 36px;
  height: 36px;
  padding: 0;
  box-shadow: 0 8px 20px rgba(15, 23, 42, 0.14);
  backdrop-filter: blur(8px);

  :deep(.el-icon) {
    width: 16px;
    height: 16px;
    color: currentColor;
  }
}
</style>
