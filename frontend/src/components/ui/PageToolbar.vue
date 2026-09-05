<script setup lang="ts">
defineProps<{
  title: string
  subtitle?: string
  kicker?: string
}>()
</script>

<template>
  <header class="page-toolbar">
    <div class="page-toolbar__copy">
      <span v-if="kicker" class="page-toolbar__kicker">{{ kicker }}</span>
      <h1>{{ title }}</h1>
      <p v-if="subtitle">{{ subtitle }}</p>
    </div>
    <div v-if="$slots.meta" class="page-toolbar__meta">
      <slot name="meta" />
    </div>
    <div v-if="$slots.actions" class="page-toolbar__actions">
      <slot name="actions" />
    </div>
  </header>
</template>

<style scoped lang="scss">
.page-toolbar {
  display: flex;
  align-items: center;
  gap: var(--space-lg);
  min-height: 68px;
  padding: var(--space-md) var(--space-xl);
  border-bottom: 1px solid var(--hairline);
  background: rgba(18, 26, 43, 0.9);
  backdrop-filter: blur(16px);
  flex-shrink: 0;
}

.page-toolbar__copy {
  flex: 1;
  min-width: 0;
}

.page-toolbar__kicker {
  display: block;
  margin-bottom: 2px;
  color: var(--accent-cyan);
  font-size: 11px;
  font-weight: 800;
  letter-spacing: 0;
}

h1 {
  margin: 0;
  color: var(--on-dark);
  font-size: 20px;
  font-weight: 800;
  letter-spacing: 0;
  line-height: 1.2;
}

p {
  margin: 4px 0 0;
  color: var(--muted);
  font-size: 13px;
  line-height: 1.35;
}

.page-toolbar__meta,
.page-toolbar__actions {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  flex-shrink: 0;
}

@media (max-width: 760px) {
  .page-toolbar {
    align-items: stretch;
    flex-direction: column;
    padding: var(--space-md);
  }

  .page-toolbar__actions {
    justify-content: flex-start;
    flex-wrap: wrap;
  }
}
</style>
