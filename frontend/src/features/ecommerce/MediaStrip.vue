<script setup lang="ts">
import { ref } from 'vue'
import { t } from '@/i18n'
import { resolveIcon } from '@/utils/iconRegistry'
import type { Media } from './types'
withDefaults(defineProps<{ items: Media[]; selectedId?: string; busy?: boolean; upload?: boolean; removable?: boolean }>(), { removable: false })
const emit = defineEmits<{ files: [files: File[]]; select: [item: Media]; remove: [id: string]; preview: [item: Media] }>()
const input = ref<HTMLInputElement>()
const dragging = ref(false)
function files(list: FileList | null) { if (list?.length) emit('files', Array.from(list)); dragging.value = false }
function change(event: Event) { const target = event.target as HTMLInputElement; files(target.files); target.value = '' }
function drop(event: DragEvent) { files(event.dataTransfer?.files || null) }
</script>

<template>
  <div class="media-strip" :class="{ dragging }" @dragover.prevent="dragging = !!upload" @dragleave.prevent="dragging = false" @drop.prevent="upload && drop($event)">
    <div v-for="item in items" :key="item.id" class="media-tile" :class="{ selected: selectedId === item.id }">
      <button class="media-select" :title="item.name" @click="emit('select', item)" @dblclick="emit('preview', item)">
        <img :src="item.src" :alt="item.name" loading="lazy" />
        <span v-if="selectedId === item.id" class="selected-mark"><el-icon><component :is="resolveIcon('Check')" /></el-icon></span>
      </button>
      <button v-if="upload || removable" class="remove" :disabled="busy" :aria-label="t('移除图片')" :title="t('移除图片')" @click="emit('remove', item.id)"><el-icon><component :is="resolveIcon('Close')" /></el-icon></button>
      <span class="media-name">{{ item.name }}</span>
    </div>
    <button v-if="upload" class="upload-tile" :disabled="busy" @click="input?.click()" :aria-label="t('上传图片')">
      <el-icon :size="24" :class="{ 'is-loading': busy }"><component :is="resolveIcon(busy ? 'Loading' : 'UploadFilled')" /></el-icon>
      <span>{{ busy ? t('上传中') : t('添加图片') }}</span>
    </button>
    <input ref="input" type="file" accept="image/jpeg,image/png,image/webp" multiple hidden @change="change" />
  </div>
</template>

<style scoped>
.media-strip { display: grid; grid-template-columns: repeat(auto-fill,minmax(84px,1fr)); gap: 10px; min-height: 104px; border: 1px solid transparent; border-radius: 6px; }
.dragging { border-color: var(--brand-cyan); background: rgba(34,211,238,.07); }
.media-tile { position: relative; min-width: 0; }
.media-select,.upload-tile { padding: 0; width: 100%; aspect-ratio: 1; background: var(--surface-soft); border: 1px solid var(--hairline); border-radius: 6px; cursor: pointer; overflow: hidden; }
.media-select img { width: 100%; height: 100%; object-fit: contain; display: block; }
.selected .media-select { border-color: var(--brand-cyan); }
.selected-mark { position: absolute; right: 5px; bottom: 25px; background: #0e7490; color: #fff; width: 20px; height: 20px; display: grid; place-items: center; border-radius: 4px; }
.remove { position: absolute; right: -4px; top: -4px; background: #273247; color: #fff; border: 1px solid var(--hairline); border-radius: 4px; width: 22px; height: 22px; display: grid; place-items: center; cursor: pointer; }
.upload-tile { display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 8px; color: var(--muted); border-style: dashed; font: inherit; font-size: 12px; }
.upload-tile:hover { color: var(--brand-cyan); border-color: var(--brand-cyan); }
.upload-tile:disabled { opacity: .5; cursor: wait; }
.media-name { display: block; font-size: 11px; line-height: 20px; color: var(--muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
button:focus-visible { outline: 2px solid var(--brand-cyan); outline-offset: 2px; }
</style>
