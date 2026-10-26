<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { t } from '@/i18n'
import { resolveIcon } from '@/utils/iconRegistry'
import { clone } from './useEcommerce'
import type { Mark, Marking, Media } from './types'
const props = defineProps<{ image: Media; value: Marking }>()
const emit = defineEmits<{ confirm: [marking: Marking]; cancel: [] }>()
const stage = ref<HTMLElement>()
const imageEl = ref<HTMLImageElement>()
const shape = ref('point')
const marks = ref<Mark[]>([])
const draft = ref<Mark | null>(null)
const start = ref<{ x: number; y: number } | null>(null)
watch(() => props.image.id, () => { marks.value = props.value.imageId === props.image.id ? clone(props.value.marks) : [] }, { immediate: true })
const visibleMarks = computed(() => [...marks.value, ...(draft.value ? [draft.value] : [])])
function point(event: PointerEvent) {
  const box = stage.value!.getBoundingClientRect()
  return { x: Math.max(0, Math.min(1, (event.clientX - box.left) / box.width)), y: Math.max(0, Math.min(1, (event.clientY - box.top) / box.height)) }
}
function down(event: PointerEvent) {
  if (marks.value.length >= 12) return
  start.value = point(event)
  stage.value?.setPointerCapture(event.pointerId)
  move(event)
}
function move(event: PointerEvent) {
  if (!start.value) return
  const end = point(event)
  const p = start.value
  const isPoint = shape.value === 'point'
  const width = isPoint ? .03 : Math.max(.01, Math.abs(end.x - p.x))
  const height = isPoint ? .03 : Math.max(.01, Math.abs(end.y - p.y))
  const x = Math.min(1 - width, isPoint ? Math.max(0, p.x - width / 2) : Math.min(p.x, end.x))
  const y = Math.min(1 - height, isPoint ? Math.max(0, p.y - height / 2) : Math.min(p.y, end.y))
  draft.value = { id: crypto.randomUUID(), label: marks.value.length + 1, x, y, width, height, ...(isPoint ? { shape: 'point' as const } : {}) }
}
function up() { if (draft.value) marks.value.push(draft.value); draft.value = null; start.value = null }
async function confirm() {
  const image = imageEl.value!
  const canvas = document.createElement('canvas')
  canvas.width = image.naturalWidth; canvas.height = image.naturalHeight
  const ctx = canvas.getContext('2d')!
  ctx.drawImage(image, 0, 0)
  const scale = Math.max(2, canvas.width / 400)
  ctx.lineWidth = scale; ctx.font = `bold ${Math.max(18, canvas.width / 40)}px sans-serif`
  for (const mark of marks.value) {
    const x = mark.x * canvas.width; const y = mark.y * canvas.height
    ctx.strokeStyle = '#e11d48'; ctx.fillStyle = '#e11d48'
    if (mark.shape === 'point') { ctx.beginPath(); ctx.arc(x + mark.width * canvas.width / 2, y + mark.height * canvas.height / 2, scale * 3, 0, Math.PI * 2); ctx.fill() }
    else ctx.strokeRect(x, y, mark.width * canvas.width, mark.height * canvas.height)
    ctx.fillText(String(mark.label), x + 4 * scale, Math.max(24, y + 8 * scale))
  }
  emit('confirm', { mode: 'manual', imageId: props.image.id, marks: clone(marks.value), annotatedSrc: canvas.toDataURL('image/jpeg', .9) })
}
</script>

<template>
  <div class="marker-tools">
    <el-radio-group v-model="shape" size="small"><el-radio-button value="point">{{ t('点选') }}</el-radio-button><el-radio-button value="region">{{ t('框选') }}</el-radio-button></el-radio-group>
    <span>{{ marks.length }} / 12</span>
    <el-button :icon="resolveIcon('RefreshLeft')" :disabled="!marks.length" @click="marks.pop()" :title="t('撤销标记')" :aria-label="t('撤销标记')" />
    <el-button :icon="resolveIcon('Delete')" :disabled="!marks.length" @click="marks = []" :title="t('清空标记')" :aria-label="t('清空标记')" />
  </div>
  <div ref="stage" class="marker-stage" @pointerdown.prevent="down" @pointermove="move" @pointerup="up" @pointercancel="start = null; draft = null">
    <img ref="imageEl" :src="image.src" :alt="image.name" crossorigin="anonymous" draggable="false" />
    <div v-for="mark in visibleMarks" :key="mark.id" class="mark" :class="{ point: mark.shape === 'point' }" :style="{ left: mark.x * 100 + '%', top: mark.y * 100 + '%', width: mark.width * 100 + '%', height: mark.height * 100 + '%' }"><span>{{ mark.label }}</span></div>
  </div>
  <div class="marker-footer"><el-button @click="emit('cancel')">{{ t('取消') }}</el-button><el-button type="primary" :disabled="!marks.length" @click="confirm">{{ t('使用标记') }}</el-button></div>
</template>

<style scoped>
.marker-tools { display: flex; align-items: center; gap: 12px; padding-bottom: 16px; color: var(--muted); }
.marker-tools .el-button + .el-button { margin-left: 0; }
.marker-stage { position: relative; width: min(100%,640px); margin: 0 auto; cursor: crosshair; touch-action: none; user-select: none; }
.marker-stage img { display: block; width: 100%; height: auto; pointer-events: none; }
.mark { position: absolute; border: 2px solid #e11d48; background: rgba(225,29,72,.08); pointer-events: none; box-sizing: border-box; }
.mark span { position: absolute; left: 0; top: -1px; background: #e11d48; color: white; min-width: 18px; text-align: center; font-size: 12px; line-height: 18px; }
.mark.point { border-radius: 50%; min-width: 8px; min-height: 8px; background: #e11d48; }
.mark.point span { left: 12px; top: -16px; border-radius: 3px; }
.marker-footer { display: flex; justify-content: flex-end; padding-top: 18px; }
</style>
