<script setup lang="ts">
interface Props {
  variant?: 'dark' | 'yellow' | 'emerald'
  uppercase?: boolean
}

withDefaults(defineProps<Props>(), {
  variant: 'dark',
  uppercase: false,
})
</script>

<template>
  <span
    class="badge-pill"
    :class="[`badge-pill--${variant}`, { 'badge-pill--uc': uppercase }]"
  >
    <slot />
  </span>
</template>

<style scoped lang="scss">
.badge-pill {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: var(--radius-pill);
  font-family: var(--font-sans);
  font-size: 11px;
  font-weight: 600;
  line-height: 1.4;
  white-space: nowrap;

  &--uc {
    letter-spacing: 0.8px;
    text-transform: uppercase;
    font-size: 10px;
  }

  &--dark {
    background: var(--surface-elevated);
    color: var(--muted);
    border: 1px solid var(--hairline);
  }

  &--yellow {
    background: var(--primary);
    color: var(--on-primary);
  }

  &--emerald {
    background: rgba(34, 197, 94, 0.12);
    color: var(--accent-emerald);
    border: 1px solid rgba(34, 197, 94, 0.25);
  }
}
</style>
