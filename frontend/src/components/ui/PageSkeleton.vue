<script setup lang="ts">
withDefaults(defineProps<{
  variant?: 'gallery' | 'dashboard' | 'assets' | 'feed'
  count?: number
}>(), {
  variant: 'gallery',
  count: 8,
})
</script>

<template>
  <div class="page-skeleton" :class="`page-skeleton--${variant}`" aria-busy="true" aria-live="polite">
    <template v-if="variant === 'dashboard'">
      <div class="sk-metrics">
        <div v-for="n in 4" :key="`m-${n}`" class="sk-metric">
          <span class="sk-line sk-line--sm" />
          <span class="sk-line sk-line--xl" />
          <span class="sk-line sk-line--xs" />
        </div>
      </div>
      <div class="sk-dash-grid">
        <div v-for="col in 2" :key="`c-${col}`" class="sk-panel">
          <span class="sk-line sk-line--md" />
          <div v-for="n in 4" :key="`r-${col}-${n}`" class="sk-row">
            <span class="sk-block sk-block--avatar" />
            <div class="sk-row__text">
              <span class="sk-line sk-line--lg" />
              <span class="sk-line sk-line--sm" />
            </div>
          </div>
        </div>
      </div>
    </template>

    <template v-else-if="variant === 'assets'">
      <section v-for="sec in 3" :key="`s-${sec}`" class="sk-asset-section">
        <span class="sk-line sk-line--md sk-asset-section__title" />
        <div class="sk-card-grid">
          <div v-for="n in 4" :key="`a-${sec}-${n}`" class="sk-card">
            <span class="sk-block sk-block--cover" />
            <span class="sk-line sk-line--lg" />
            <span class="sk-line sk-line--sm" />
          </div>
        </div>
      </section>
    </template>

    <template v-else-if="variant === 'feed'">
      <div v-for="n in 3" :key="`f-${n}`" class="sk-feed">
        <div class="sk-feed__user">
          <span class="sk-line sk-line--lg" />
          <span class="sk-line sk-line--md" />
        </div>
        <span class="sk-block sk-block--media" />
      </div>
    </template>

    <div v-else class="sk-card-grid">
      <div v-for="n in count" :key="`g-${n}`" class="sk-card">
        <span class="sk-block sk-block--cover" />
        <span class="sk-line sk-line--lg" />
        <span class="sk-line sk-line--sm" />
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.page-skeleton {
  min-height: 240px;
  padding: var(--space-xl);
}

.sk-card-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(232px, 1fr));
  gap: var(--space-lg);
}

.sk-card,
.sk-metric,
.sk-panel,
.sk-feed {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding: var(--space-sm);
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-card);
}

.sk-block,
.sk-line {
  display: block;
  border-radius: var(--radius-sm);
  background: linear-gradient(
    90deg,
    rgba(148, 163, 184, 0.08) 0%,
    rgba(99, 102, 241, 0.18) 45%,
    rgba(148, 163, 184, 0.08) 100%
  );
  background-size: 220% 100%;
  animation: sk-shimmer 1.35s ease-in-out infinite;
}

.sk-block--cover {
  width: 100%;
  aspect-ratio: 16 / 10;
  border-radius: var(--radius-md);
}

.sk-block--avatar {
  width: 36px;
  height: 36px;
  flex-shrink: 0;
  border-radius: var(--radius-full);
}

.sk-block--media {
  width: min(100%, 420px);
  height: 180px;
  border-radius: var(--radius-md);
}

.sk-line--xs { width: 38%; height: 8px; }
.sk-line--sm { width: 52%; height: 10px; }
.sk-line--md { width: 40%; height: 12px; }
.sk-line--lg { width: 72%; height: 12px; }
.sk-line--xl { width: 56%; height: 28px; }

.sk-metrics {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: var(--space-md);
  margin-bottom: var(--space-lg);
}

.sk-dash-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: var(--space-lg);
}

.sk-row {
  display: flex;
  align-items: center;
  gap: var(--space-sm);
}

.sk-row__text {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.sk-asset-section {
  margin-bottom: var(--space-xl);
}

.sk-asset-section__title {
  margin-bottom: var(--space-md);
}

.sk-feed {
  margin-bottom: var(--space-lg);
  max-width: 640px;
}

.sk-feed__user {
  display: flex;
  flex-direction: column;
  gap: 8px;
  align-items: flex-end;
}

@keyframes sk-shimmer {
  0% { background-position: 100% 0; }
  100% { background-position: 0 0; }
}

@media (max-width: 960px) {
  .sk-metrics,
  .sk-dash-grid {
    grid-template-columns: 1fr;
  }
}
</style>
