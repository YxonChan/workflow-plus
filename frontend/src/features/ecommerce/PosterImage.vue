<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { renderPoster } from './poster-renderer'
import type { Poster } from './types'
const props = defineProps<{ src: string; poster?: Poster | null; alt: string; large?: boolean }>()
const rendered = ref(''), failed = ref(false)
let epoch = 0
watch(() => [props.src, props.poster, props.large], async () => {
  const current = ++epoch; rendered.value = ''; failed.value = false
  const poster = props.poster
  if (!poster || (!poster.headline.trim() && !poster.subtitle.trim() && !poster.sellingPoints.length && !Object.values(poster.facts || {}).some(value => value.trim()))) return
  try {
    const canvas = await renderPoster(props.src, props.poster, props.large ? 1600 : 640)
    if (current === epoch) rendered.value = canvas.toDataURL('image/png')
  } catch { if (current === epoch) failed.value = true }
}, { immediate: true, deep: true })
onBeforeUnmount(() => { epoch++ })
</script>

<template>
  <div class="poster-image"><img :src="rendered || src" :alt="alt" /><span v-if="failed" class="poster-error" role="alert">{{ $t('文案排版失败，请重新加载') }}</span></div>
</template>

<style scoped>
.poster-image { width: 100%; height: 100%; position: relative; display: block; }
.poster-image > img { width: 100%; height: 100%; display: block; object-fit: contain; }
.poster-error { position: absolute; bottom: 0; inset-inline: 0; padding: 8px; background: #7f1d1d; color: #fff; font-size: 12px; }
</style>
