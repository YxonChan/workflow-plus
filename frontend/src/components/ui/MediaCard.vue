<script setup lang="ts">
import { computed } from 'vue'
import { generatedCover } from '@/utils/cover'
import { resolveIcon } from '@/utils/iconRegistry'

const props = defineProps<{
  image?: string | null
  title: string
  meta?: string
  icon?: string
  interactive?: boolean
}>()

/** 无封面时的生成式占位：标题首字 + 由标题哈希出的渐变色，让每张卡片都不一样 */
const placeholder = computed(() => generatedCover(props.title))
const displayIcon = computed(() => resolveIcon(props.icon || 'Picture'))
</script>

<template>
  <article class="media-card" :class="{ 'is-interactive': interactive }">
    <div class="media-card__preview">
      <img v-if="image" v-lazy-src="image" alt="" />
      <div v-else class="media-card__empty" :style="placeholder.style">
        <span class="media-card__monogram">{{ placeholder.text }}</span>
        <el-icon class="media-card__empty-icon" :size="14"><component :is="displayIcon" /></el-icon>
      </div>
      <div v-if="$slots.badge" class="media-card__badge">
        <slot name="badge" />
      </div>
      <div v-if="$slots.actions" class="media-card__actions">
        <slot name="actions" />
      </div>
    </div>
    <div class="media-card__body">
      <strong>{{ title }}</strong>
      <span v-if="meta">{{ meta }}</span>
      <slot />
    </div>
  </article>
</template>

<style scoped lang="scss">
.media-card {
  min-width: 0;
  overflow: hidden;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-card);
  transition: border-color var(--duration-fast), transform var(--duration-fast), box-shadow var(--duration-fast);
}

.media-card.is-interactive {
  cursor: pointer;

  &:hover {
    border-color: rgba(var(--brand-cyan-rgb), 0.36);
    transform: translateY(-2px);
    box-shadow: 0 12px 28px rgba(16, 32, 51, 0.08);
  }
}

.media-card__preview {
  position: relative;
  aspect-ratio: 16 / 10;
  background: linear-gradient(135deg, var(--surface-soft), var(--surface-elevated));
  overflow: hidden;
}

img {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.media-card__empty {
  position: relative;
  width: 100%;
  height: 100%;
  display: grid;
  place-items: center;
}

.media-card__monogram {
  font-size: 34px;
  font-weight: 800;
  letter-spacing: 2px;
  line-height: 1;
  user-select: none;
}

.media-card__empty-icon {
  position: absolute;
  right: 10px;
  bottom: 8px;
  opacity: 0.55;
}

.media-card__badge {
  position: absolute;
  top: var(--space-xs);
  left: var(--space-xs);
}

.media-card__actions {
  position: absolute;
  top: var(--space-xs);
  right: var(--space-xs);
  z-index: 3;
}

.media-card__body {
  min-width: 0;
  padding: var(--space-sm) var(--space-md) var(--space-md);
  display: flex;
  flex-direction: column;
  gap: 3px;
}

strong {
  color: var(--on-dark);
  font-size: 13px;
  font-weight: 800;
  line-height: 1.35;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

span {
  color: var(--muted);
  font-size: 12px;
  line-height: 1.35;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
</style>
