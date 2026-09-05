<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Asset, StoryboardAssetRefNode } from '@/types'

interface AssetReference {
  label: string
  role: 'view' | 'look'
  assetImageId?: number | null
  assetImageVersionId?: number | null
}

interface MentionOption {
  key: string
  label: string
  subtitle: string
  image: string
  asset: Asset
  reference: AssetReference
}

interface MentionState {
  active: boolean
  query: string
  start: number
  end: number
}

const props = defineProps<{
  modelValue: boolean
  title: string
  impactHtml: string
  shotIndex: number
  assets: Asset[]
}>()

const emit = defineEmits<{
  (event: 'update:modelValue', value: boolean): void
  (event: 'confirm', value: { instruction: string; assetRefs: StoryboardAssetRefNode[] }): void
}>()

const { t } = useI18n()
const instruction = ref('')
const mentionState = ref<MentionState>({ active: false, query: '', start: -1, end: -1 })
const textareaRef = ref<HTMLTextAreaElement | null>(null)

function assetImage(asset: Asset): string {
  return asset.images?.find((image) => image.reference_role !== 'look' && image.url?.trim())?.url
    ?? asset.images?.find((image) => image.url?.trim())?.url
    ?? ''
}

function assetTypeLabel(type: Asset['type'] | 'look'): string {
  if (type === 'character') return t('人物')
  if (type === 'scene') return t('场景')
  if (type === 'prop') return t('道具')
  return t('人物造型')
}

function lookLabel(asset: Asset, variantName: string): string {
  const name = asset.name.trim()
  const variant = variantName.trim()
  return variant ? `${name}·${variant}` : name
}

const assetOptions = computed<MentionOption[]>(() => {
  const options: MentionOption[] = []
  const seen = new Set<string>()

  const add = (option: MentionOption) => {
    if (!option.label || seen.has(option.key)) return
    seen.add(option.key)
    options.push(option)
  }

  for (const asset of props.assets) {
    const image = assetImage(asset)
    if (asset.name.trim() && image) {
      add({
        key: `asset:${asset.id}`,
        label: asset.name.trim(),
        subtitle: assetTypeLabel(asset.type),
        image,
        asset,
        reference: { label: asset.name.trim(), role: 'view', assetImageId: null, assetImageVersionId: null },
      })
    }

    if (asset.type !== 'character') continue
    for (const assetImageItem of asset.images ?? []) {
      if (assetImageItem.reference_role !== 'look' || !assetImageItem.variant_name?.trim() || !assetImageItem.url?.trim()) continue
      const label = lookLabel(asset, assetImageItem.variant_name)
      add({
        key: `look:${asset.id}:${assetImageItem.id ?? label}`,
        label,
        subtitle: assetTypeLabel('look'),
        image: assetImageItem.url.trim(),
        asset,
        reference: { label, role: 'look', assetImageId: assetImageItem.id ?? null, assetImageVersionId: null },
      })
      for (const [versionIndex, version] of (assetImageItem.versions ?? []).entries()) {
        if (!version?.url?.trim()) continue
        const versionLabel = `${label}（版本 ${versionIndex + 1}）`
        add({
          key: `look-version:${asset.id}:${assetImageItem.id ?? label}:${version.id ?? versionIndex}`,
          label: versionLabel,
          subtitle: assetTypeLabel('look'),
          image: version.url.trim(),
          asset,
          reference: {
            label: versionLabel,
            role: 'look',
            assetImageId: assetImageItem.id ?? null,
            assetImageVersionId: version.id ?? null,
          },
        })
      }
    }
  }

  return options
})

const visibleOptions = computed(() => {
  if (!mentionState.value.active) return []
  const query = mentionState.value.query.trim().toLowerCase()
  return assetOptions.value
    .filter((option) => !query || option.label.toLowerCase().includes(query) || option.subtitle.toLowerCase().includes(query))
    .slice(0, 8)
})

function mentionStateFromText(text: string, cursor: number): MentionState {
  const safeCursor = Math.max(0, Math.min(cursor, text.length))
  const prefix = text.slice(0, safeCursor)
  const start = prefix.lastIndexOf('@')
  if (start < 0) return { active: false, query: '', start: -1, end: safeCursor }
  const query = prefix.slice(start + 1)
  if (/^[\s]|[，。！？、；：,.!?;:()[\]{}【】《》"'“”\r\n]/u.test(query)) {
    return { active: false, query: '', start: -1, end: safeCursor }
  }
  return { active: true, query, start, end: safeCursor }
}

function refreshMentionState() {
  const textarea = textareaRef.value
  if (!textarea) return
  mentionState.value = mentionStateFromText(textarea.value, textarea.selectionStart ?? textarea.value.length)
}

function handleInput(event: Event) {
  instruction.value = (event.target as HTMLTextAreaElement).value
  refreshMentionState()
}

function hideMentionLater() {
  window.setTimeout(() => {
    mentionState.value = { active: false, query: '', start: -1, end: -1 }
  }, 140)
}

function applyAssetReference(option: MentionOption) {
  const state = mentionState.value
  if (!state.active || state.start < 0) return
  const before = instruction.value.slice(0, state.start)
  const after = instruction.value.slice(state.end)
  const spacer = after.startsWith(' ') || after.startsWith('\n') || after === '' ? '' : ' '
  instruction.value = `${before}@${option.label}${spacer}${after}`
  mentionState.value = { active: false, query: '', start: -1, end: -1 }
  nextTick(() => {
    const textarea = textareaRef.value
    if (!textarea) return
    const cursor = before.length + option.label.length + 1
    textarea.focus()
    textarea.setSelectionRange(cursor, cursor)
  })
}

function selectedAssetRefs(text: string): StoryboardAssetRefNode[] {
  const refs: StoryboardAssetRefNode[] = []
  const seen = new Set<string>()
  const ranges: Array<{ start: number; end: number; option: MentionOption }> = []
  for (const option of [...assetOptions.value].sort((a, b) => b.label.length - a.label.length)) {
    const token = `@${option.label}`
    let cursor = 0
    while (true) {
      const start = text.indexOf(token, cursor)
      if (start < 0) break
      const end = start + token.length
      if (!ranges.some((range) => start < range.end && end > range.start)) {
        ranges.push({ start, end, option })
      }
      cursor = end
    }
  }
  ranges.sort((a, b) => a.start - b.start)
  for (const { option } of ranges) {
    const key = `${option.asset.id}:${option.reference.assetImageId ?? 0}:${option.reference.assetImageVersionId ?? 0}:${option.reference.role}`
    if (seen.has(key)) continue
    seen.add(key)
    refs.push({
      type: 'asset_ref',
      asset_id: option.asset.id,
      asset_image_id: option.reference.assetImageId ?? null,
      asset_image_version_id: option.reference.assetImageVersionId ?? null,
      reference_role: option.reference.role,
      label: option.label,
      asset_type: option.reference.role === 'look' ? 'look' : option.asset.type,
    })
  }
  return refs
}

function close() {
  emit('update:modelValue', false)
}

function confirm() {
  const value = instruction.value.trim()
  if (value.length > 2000) return
  emit('confirm', { instruction: value, assetRefs: selectedAssetRefs(value) })
}

watch(
  () => props.modelValue,
  (visible) => {
    if (!visible) return
    instruction.value = ''
    mentionState.value = { active: false, query: '', start: -1, end: -1 }
  },
)
</script>

<template>
  <el-dialog
    :model-value="modelValue"
    :title="title"
    width="min(560px, calc(100vw - 32px))"
    class="custom-dialog storyboard-regenerate-dialog"
    :close-on-click-modal="false"
    append-to-body
    @update:model-value="emit('update:modelValue', $event)"
  >
    <div class="storyboard-regenerate-message" v-html="impactHtml" />
    <div class="storyboard-regenerate-input">
      <label>{{ t('本次修改要求') }}</label>
      <div class="storyboard-regenerate-input__wrap">
        <textarea
          ref="textareaRef"
          :value="instruction"
          rows="5"
          :placeholder="t('例如：加强情绪爆发，改成雨夜街边，保留人物和剧情走向。可留空。')"
          @input="handleInput"
          @keyup="refreshMentionState"
          @click="refreshMentionState"
          @focus="refreshMentionState"
          @blur="hideMentionLater"
        />
        <div v-if="visibleOptions.length" class="storyboard-regenerate-mention-menu">
          <button
            v-for="option in visibleOptions"
            :key="option.key"
            type="button"
            class="storyboard-regenerate-mention-option"
            @mousedown.prevent="applyAssetReference(option)"
          >
            <img v-if="option.image" :src="option.image" alt="" loading="lazy" />
            <span v-else>{{ option.label.slice(0, 1) }}</span>
            <strong>{{ option.label }}</strong>
            <em>{{ option.subtitle }}</em>
          </button>
        </div>
      </div>
      <p>{{ t('输入 @ 可选择本作品已有资产；选中的资产会作为本次重新生成的明确引用。') }}</p>
      <p v-if="instruction.length > 2000" class="is-error">{{ t('本次修改要求不能超过 2000 字') }}</p>
    </div>
    <template #footer>
      <el-button @click="close">{{ t('取消') }}</el-button>
      <el-button type="primary" :disabled="instruction.length > 2000" @click="confirm">
        {{ t('确认重新生成') }}
      </el-button>
    </template>
  </el-dialog>
</template>

<style scoped lang="scss">
.storyboard-regenerate-input {
  margin-top: 14px;

  label {
    display: block;
    margin-bottom: 6px;
    color: var(--body-strong);
    font-size: 12px;
    font-weight: 900;
  }

  p {
    margin: 6px 0 0;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.5;
  }

  .is-error {
    color: var(--danger, #dc2626);
  }
}

.storyboard-regenerate-input__wrap {
  position: relative;
}

textarea {
  display: block;
  width: 100%;
  min-height: 116px;
  padding: 10px 12px;
  border: 1px solid var(--hairline-strong);
  border-radius: 10px;
  outline: none;
  resize: vertical;
  color: var(--body-strong);
  background: var(--surface-card);
  font: inherit;
  font-size: 13px;
  line-height: 1.6;

  &:focus {
    border-color: var(--primary);
    box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
  }
}

.storyboard-regenerate-mention-menu {
  position: absolute;
  z-index: 10;
  right: 0;
  bottom: calc(100% + 6px);
  left: 0;
  max-height: 260px;
  overflow: auto;
  padding: 5px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-card);
  box-shadow: 0 14px 32px rgba(15, 23, 42, 0.16);
}

.storyboard-regenerate-mention-option {
  display: grid;
  grid-template-columns: 30px minmax(0, 1fr) auto;
  align-items: center;
  gap: 8px;
  width: 100%;
  padding: 7px 8px;
  border: 0;
  border-radius: 7px;
  color: var(--body-strong);
  background: transparent;
  cursor: pointer;
  text-align: left;

  &:hover {
    background: rgba(var(--brand-cyan-rgb), 0.1);
  }

  img,
  > span {
    width: 30px;
    height: 30px;
    border-radius: 7px;
    object-fit: cover;
    background: var(--surface-soft);
    color: var(--muted);
    display: grid;
    place-items: center;
  }

  strong,
  em {
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  strong {
    font-size: 12px;
  }

  em {
    color: var(--muted);
    font-size: 11px;
    font-style: normal;
  }
}
</style>
