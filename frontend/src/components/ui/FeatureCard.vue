<script setup lang="ts">
interface Props {
  variant?: 'dark' | 'yellow' | 'elevated'
}

withDefaults(defineProps<Props>(), {
  variant: 'dark',
})
</script>

<template>
  <div class="feature-card" :class="`feature-card--${variant}`">
    <slot />
  </div>
</template>

<style scoped lang="scss">
.feature-card {
  border-radius: var(--radius-lg);
  padding: var(--space-xl);
  border: 1px solid transparent;

  &--dark {
    background: var(--surface-card);
    border-color: var(--hairline);
    color: var(--body);
  }

  &--elevated {
    background: var(--surface-elevated);
    border-color: var(--hairline);
    color: var(--body);
  }

  &--yellow {
    background: var(--primary);
    border-color: transparent;
    color: var(--on-yellow);
  }
}
</style>
