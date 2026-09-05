<script setup lang="ts">
import { computed } from 'vue'
import { resolveIcon } from '@/utils/iconRegistry'

const props = withDefaults(defineProps<{
  icon?: string
  title: string
  hint?: string
  compact?: boolean
}>(), {
  icon: 'FolderOpened',
  compact: false,
})

const displayIcon = computed(() => resolveIcon(props.icon))
</script>

<template>
  <div class="studio-empty" :class="{ 'studio-empty--compact': compact }">
    <div class="studio-empty__icon">
      <el-icon :size="28"><component :is="displayIcon" /></el-icon>
    </div>
    <h3>{{ title }}</h3>
    <p v-if="hint">{{ hint }}</p>
    <div v-if="$slots.default" class="studio-empty__actions">
      <slot />
    </div>
  </div>
</template>

<style scoped lang="scss">
.studio-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: var(--space-sm);
  min-height: 260px;
  padding: var(--space-xxl) var(--space-lg);
  border: 1px dashed var(--hairline-strong);
  border-radius: var(--radius-lg);
  background: var(--surface-raised);
  text-align: center;
}

.studio-empty__icon {
  width: 48px;
  height: 48px;
  display: grid;
  place-items: center;
  border-radius: var(--radius-md);
  background: var(--surface-soft);
  color: var(--muted);
}

h3 {
  margin: var(--space-xs) 0 0;
  color: var(--on-dark);
  font-size: 16px;
  font-weight: 800;
  letter-spacing: 0;
}

p {
  max-width: 360px;
  margin: 0;
  color: var(--muted);
  font-size: 13px;
  line-height: 1.55;
}

.studio-empty__actions {
  margin-top: var(--space-xs);
  display: flex;
  gap: var(--space-sm);
}

.studio-empty--compact {
  gap: var(--space-xs);
  min-height: 180px;
  padding: var(--space-lg);

  .studio-empty__icon {
    width: 40px;
    height: 40px;
  }

  h3 {
    margin-top: 4px;
    font-size: 14px;
  }

  p {
    font-size: 12px;
  }
}
</style>
