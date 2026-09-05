<script setup lang="ts">
import { computed } from 'vue'
import { resolveIcon } from '@/utils/iconRegistry'

const props = defineProps<{
  title: string
  icon?: string
}>()

const displayIcon = computed(() => (props.icon ? resolveIcon(props.icon) : null))
</script>

<template>
  <aside class="inspector-panel">
    <div class="inspector-panel__head">
      <el-icon v-if="displayIcon"><component :is="displayIcon" /></el-icon>
      <span>{{ title }}</span>
    </div>
    <div class="inspector-panel__body">
      <slot />
    </div>
  </aside>
</template>

<style scoped lang="scss">
.inspector-panel {
  border-left: 1px solid var(--hairline);
  background: var(--surface-card);
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.inspector-panel__head {
  height: 48px;
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  padding: 0 var(--space-md);
  border-bottom: 1px solid var(--hairline);
  color: var(--body-strong);
  font-size: 12px;
  font-weight: 800;
}

.inspector-panel__head .el-icon {
  color: var(--accent-cyan);
}

.inspector-panel__body {
  flex: 1;
  min-height: 0;
  overflow-y: auto;
  padding: var(--space-md);
}
</style>
