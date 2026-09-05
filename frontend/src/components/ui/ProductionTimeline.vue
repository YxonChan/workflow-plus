<script setup lang="ts">
interface Item {
  id: string
  title: string
  status: 'queued' | 'running' | 'success' | 'failed' | 'skipped' | 'stale'
}

defineProps<{
  items: Item[]
  activeId?: string | null
}>()

const emit = defineEmits<{
  (e: 'select', id: string): void
}>()

const TONE: Record<Item['status'], string> = {
  queued: 'idle',
  running: 'busy',
  success: 'done',
  failed: 'fail',
  skipped: 'idle',
  stale: 'warning',
}
</script>

<template>
  <div class="production-timeline">
    <button
      v-for="(item, index) in items"
      :key="item.id"
      type="button"
      class="timeline-step"
      :class="[`is-${TONE[item.status]}`, { 'is-active': item.id === activeId }]"
      @click="emit('select', item.id)"
    >
      <span class="timeline-step__index">{{ index + 1 }}</span>
      <span class="timeline-step__label">{{ item.title }}</span>
    </button>
  </div>
</template>

<style scoped lang="scss">
.production-timeline {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  min-width: 0;
  overflow-x: auto;
}

.timeline-step {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  min-width: 108px;
  height: 34px;
  padding: 0 10px;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-soft);
  color: var(--body);
  cursor: pointer;
  transition: all var(--duration-fast);
  white-space: nowrap;
}

.timeline-step:hover,
.timeline-step.is-active {
  border-color: rgba(var(--brand-cyan-rgb), 0.38);
  background: rgba(var(--brand-cyan-rgb), 0.08);
  color: var(--primary);
}

.timeline-step__index {
  width: 18px;
  height: 18px;
  display: grid;
  place-items: center;
  border-radius: var(--radius-full);
  background: var(--surface-card);
  color: inherit;
  font-size: 10px;
  font-weight: 800;
}

.timeline-step__label {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  font-size: 12px;
  font-weight: 700;
}

.timeline-step.is-busy .timeline-step__index { background: rgba(var(--brand-cyan-rgb), 0.16); }
.timeline-step.is-done .timeline-step__index { background: rgba(22, 163, 74, 0.14); color: var(--accent-emerald); }
.timeline-step.is-fail .timeline-step__index { background: rgba(225, 29, 72, 0.12); color: var(--accent-rose); }
</style>
