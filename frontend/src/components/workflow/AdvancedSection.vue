<script setup lang="ts">
import { computed, ref } from 'vue'
import { ArrowRightBold } from '@element-plus/icons-vue'
import { useI18n } from 'vue-i18n'

interface Props {
  title?: string
  /** 默认是否展开；不传则默认收起 */
  defaultOpen?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  title: undefined,
  defaultOpen: false,
})

const { t } = useI18n()
const open = ref(props.defaultOpen)
const titleText = computed(() => props.title || t('高级设置'))

function toggle() {
  open.value = !open.value
}
</script>

<template>
  <div class="adv-section" :class="{ 'is-open': open }">
    <button type="button" class="adv-section__header" @click="toggle">
      <el-icon class="adv-section__chevron"><ArrowRightBold /></el-icon>
      <span class="adv-section__title">{{ titleText }}</span>
      <span class="adv-section__hint">{{ open ? t('收起') : t('展开') }}</span>
    </button>
    <div v-show="open" class="adv-section__body">
      <slot />
    </div>
  </div>
</template>

<style scoped lang="scss">
.adv-section {
  border: 1px solid var(--hairline);
  border-radius: var(--radius-md);
  background: var(--surface-soft);
  overflow: hidden;
}

.adv-section__header {
  display: flex;
  align-items: center;
  gap: var(--space-xs);
  width: 100%;
  padding: 10px var(--space-sm);
  border: none;
  background: transparent;
  color: var(--muted);
  cursor: pointer;
  transition: color var(--duration-fast) var(--ease-out),
    background var(--duration-fast) var(--ease-out);

  &:hover {
    color: var(--body);
    background: var(--surface-elevated);
  }
}

.adv-section__chevron {
  font-size: 12px;
  transition: transform var(--duration-fast) var(--ease-out);
  flex-shrink: 0;
}

.adv-section.is-open .adv-section__chevron {
  transform: rotate(90deg);
  color: var(--primary);
}

.adv-section__title {
  font-size: var(--text-caption-uc-size);
  font-weight: var(--text-caption-uc-weight);
  letter-spacing: var(--text-caption-uc-ls);
  text-transform: uppercase;
  color: inherit;
}

.adv-section__hint {
  margin-left: auto;
  font-size: 11px;
  font-weight: 500;
  color: var(--muted-soft);
}

.adv-section__body {
  padding: var(--space-sm);
  background: var(--surface-elevated);
  border-top: 1px solid var(--hairline);
}
</style>
