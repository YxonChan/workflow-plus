<script setup lang="ts">
withDefaults(defineProps<{
  tone?: 'idle' | 'busy' | 'done' | 'fail' | 'warning' | 'info' | 'system'
  pulse?: boolean
}>(), {
  tone: 'idle',
  pulse: false,
})
</script>

<template>
  <span class="status-badge" :class="[`is-${tone}`, { 'is-pulsing': pulse }]">
    <span class="status-badge__dot" />
    <slot />
  </span>
</template>

<style scoped lang="scss">
.status-badge {
  --badge-color: var(--muted);
  --badge-bg: var(--surface-soft);
  --badge-border: var(--hairline);

  display: inline-flex;
  align-items: center;
  gap: 6px;
  min-height: 24px;
  padding: 0 9px;
  border: 1px solid var(--badge-border);
  border-radius: var(--radius-pill);
  background: var(--badge-bg);
  color: var(--badge-color);
  font-size: 11px;
  font-weight: 700;
  line-height: 1;
  white-space: nowrap;
}

.status-badge__dot {
  width: 6px;
  height: 6px;
  border-radius: var(--radius-full);
  background: currentColor;
  flex-shrink: 0;
}

.status-badge.is-busy {
  --badge-color: var(--accent-cyan);
  --badge-bg: rgba(var(--brand-cyan-rgb), 0.08);
  --badge-border: rgba(var(--brand-cyan-rgb), 0.22);
}

.status-badge.is-done {
  --badge-color: var(--accent-emerald);
  --badge-bg: rgba(22, 163, 74, 0.08);
  --badge-border: rgba(22, 163, 74, 0.2);
}

.status-badge.is-fail {
  --badge-color: var(--accent-rose);
  --badge-bg: rgba(225, 29, 72, 0.08);
  --badge-border: rgba(225, 29, 72, 0.2);
}

.status-badge.is-warning {
  --badge-color: var(--accent-amber);
  --badge-bg: rgba(217, 119, 6, 0.1);
  --badge-border: rgba(217, 119, 6, 0.2);
}

.status-badge.is-info {
  --badge-color: var(--accent-blue);
  --badge-bg: rgba(37, 99, 235, 0.08);
  --badge-border: rgba(37, 99, 235, 0.18);
}

.status-badge.is-system {
  --badge-color: var(--accent-lilac);
  --badge-bg: rgba(124, 58, 237, 0.08);
  --badge-border: rgba(124, 58, 237, 0.18);
}

.status-badge.is-pulsing .status-badge__dot {
  animation: badgePulse 1.4s ease-in-out infinite;
}

@keyframes badgePulse {
  0%, 100% { transform: scale(1); opacity: 0.65; }
  50% { transform: scale(1.45); opacity: 1; }
}
</style>
