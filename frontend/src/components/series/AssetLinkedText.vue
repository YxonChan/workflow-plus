<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Asset } from '@/types'

interface Segment {
  type: 'text' | 'asset'
  content: string
  asset?: Asset
  reference?: AssetReference
}

interface AssetMention {
  term: string
  asset_id: number
  asset_image_id?: number | null
  asset_image_version_id?: number | null
  reference_role?: 'view' | 'look'
}

interface AssetReference {
  label: string
  role: 'view' | 'look'
  assetImageId?: number | null
  assetImageVersionId?: number | null
  url?: string
}

interface AssetTerm {
  asset: Asset
  reference?: AssetReference
}

interface DisplayReference {
  key: string
  label: string
  asset?: Asset
  reference?: AssetReference
}

const { t } = useI18n()

const props = defineProps<{
  text: string
  assets: Asset[]
  /** 后端 AI 匹配出的「文本词 → 资产」映射（output_json.asset_mentions），优先于名称匹配 */
  mentions?: AssetMention[]
  displayReferences?: DisplayReference[]
}>()

const emit = defineEmits<{
  (e: 'open-asset', asset: Asset, reference?: AssetReference): void
}>()

function assetImage(asset: Asset): string {
  return (asset.images ?? []).find((image) => image.view_type === 'main' && image.url?.trim())?.url
    ?? (asset.images ?? []).find((image) => image.url?.trim())?.url
    ?? ''
}

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')
}

/** 资产名形如「精卫（少女形态）」时，去掉括号后缀得到基础名「精卫」作为别名 */
function baseNameAlias(name: string): string {
  return name.replace(/[（(][^）)]*[）)]\s*$/, '').trim()
}

function lookReferenceName(asset: Asset, variantName: string): string {
  const characterName = asset.name?.trim() ?? ''
  const lookName = variantName.trim()
  if (!characterName) return lookName
  if (!lookName) return characterName
  return `${characterName}·${lookName}`
}

// 匹配候选词 → 资产：AI mentions 优先，名称/括号别名兜底；只收有图片的资产
const termMap = computed<Map<string, AssetTerm>>(() => {
  const withImage = props.assets.filter((asset) => asset.name?.trim() && assetImage(asset))
  const byId = new Map(props.assets.filter((asset) => asset.name?.trim()).map((asset) => [asset.id, asset]))
  const map = new Map<string, AssetTerm>()

  const add = (term: string, asset: Asset, reference?: AssetReference) => {
    const key = term.trim()
    if (key && !map.has(key)) map.set(key, { asset, reference })
  }

  const addWithMentionAlias = (term: string, asset: Asset, reference?: AssetReference) => {
    add(term, asset, reference)
    add(`@${term.replace(/^@/u, '')}`, asset, reference)
  }

  for (const ref of props.displayReferences ?? []) {
    if (!ref.asset || !ref.label) continue
    addWithMentionAlias(ref.label, ref.asset, ref.reference)
    addWithMentionAlias(ref.key, ref.asset, ref.reference)
  }

  for (const mention of props.mentions ?? []) {
    const asset = byId.get(mention.asset_id)
    if (asset && typeof mention.term === 'string') {
      const image = (asset.images ?? []).find((img) => img.id === mention.asset_image_id)
      addWithMentionAlias(mention.term, asset, image && mention.reference_role === 'look'
        ? {
            label: mention.term,
            role: 'look',
            assetImageId: image.id ?? mention.asset_image_id,
            url: image.url,
          }
        : undefined)
    }
  }
  for (const asset of withImage) {
    addWithMentionAlias(asset.name, asset)
    addWithMentionAlias(baseNameAlias(asset.name), asset)
  }
  for (const asset of props.assets.filter((item) => item.type === 'character' && item.name?.trim())) {
    for (const image of asset.images ?? []) {
      if ((image.reference_role ?? 'view') !== 'look') continue
      const variantName = image.variant_name?.trim() ?? ''
      if (!variantName) continue
      const url = image.url?.trim() ?? ''
      const label = lookReferenceName(asset, variantName)
      addWithMentionAlias(label, asset, {
        label,
        role: 'look',
        assetImageId: image.id ?? null,
        url,
      })
      const versions = Array.isArray(image.versions) ? image.versions.filter((version) => version?.url?.trim()) : []
      versions.forEach((version, versionIndex) => {
        const versionLabel = `${label}（版本 ${versionIndex + 1}）`
        addWithMentionAlias(versionLabel, asset, {
          label: versionLabel,
          role: 'look',
          assetImageId: image.id ?? null,
          assetImageVersionId: version.id ?? null,
          url: version.url.trim(),
        })
      })
    }
  }
  return map
})

const segments = computed<Segment[]>(() => {
  const text = props.text ?? ''
  const map = termMap.value
  if (!text || !map.size) return [{ type: 'text', content: text }]

  // 长词优先，避免「精卫」抢先吃掉「精卫鸟」
  const terms = Array.from(map.keys()).sort((a, b) => b.length - a.length)
  const pattern = new RegExp(terms.map(escapeRegExp).join('|'), 'g')
  const result: Segment[] = []
  let cursor = 0

  for (const match of text.matchAll(pattern)) {
    const index = match.index ?? 0
    if (index > cursor) result.push({ type: 'text', content: text.slice(cursor, index) })
    const term = map.get(match[0])
    result.push({ type: 'asset', content: match[0], asset: term?.asset, reference: term?.reference })
    cursor = index + match[0].length
  }
  if (cursor < text.length) result.push({ type: 'text', content: text.slice(cursor) })
  return result
})

const segmentLineFlags = computed(() => {
  const text = props.text ?? ''
  const flags: boolean[] = []
  let cursor = 0
  segments.value.forEach((segment) => {
    const start = cursor
    const lineStart = text.lastIndexOf('\n', Math.max(0, start - 1)) + 1
    const nextBreak = text.indexOf('\n', start)
    const lineEnd = nextBreak >= 0 ? nextBreak : text.length
    const lineText = text.slice(lineStart, lineEnd).trimStart()
    flags.push(lineText.startsWith('引用资产：'))
    cursor += segment.content.length
  })
  return flags
})

const referenceDisplayMap = computed(() => {
  const map = new Map<string, string>()
  ;(props.displayReferences ?? []).forEach((ref, index) => {
    const cleanLabel = String(ref.label ?? '').trim()
    const display = `图片${index + 1}：${cleanLabel}`
    map.set(ref.key, display)
    map.set(cleanLabel, display)
    map.set(`@${cleanLabel}`, display)
  })
  return map
})

function referenceDisplayText(segment: Segment, index: number): string {
  if (!segment.asset || !segmentLineFlags.value[index]) return segment.content
  return referenceDisplayMap.value.get(segment.content) ?? segment.content
}
</script>

<template>
  <pre class="asset-linked-text"><template v-for="(segment, index) in segments"><button
      v-if="segment.type === 'asset' && segment.asset"
      :key="`asset-${index}`"
      type="button"
      class="asset-tag"
      :class="[`asset-tag--${segment.asset.type}`, { 'asset-tag--look': segment.reference?.role === 'look' }]"
      :title="segment.reference?.role === 'look' ? t('查看人物造型「{name}」', { name: segment.reference.label }) : t('查看资产「{name}」的图片', { name: segment.asset.name })"
      @click="emit('open-asset', segment.asset, segment.reference)"
    >{{ referenceDisplayText(segment, index) }}</button><template v-else>{{ segment.content }}</template></template></pre>
</template>

<style scoped lang="scss">
/* 容器外观（边框/字体/滚动）由父组件原有的 <pre> 样式提供（.node-output pre / .text-output），
   这里只保证文本折行的基础行为，避免与父级 scoped 样式冲突。 */
.asset-linked-text {
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;
}

.asset-tag {
  display: inline;
  margin: 0 1px;
  padding: 1px 6px;
  border: 0;
  border-radius: 6px;
  font: inherit;
  font-weight: 900;
  cursor: zoom-in;
  transition: background var(--duration-fast, 0.15s), color var(--duration-fast, 0.15s);
}

.asset-tag--character {
  color: #1d4ed8;
  background: rgba(37, 99, 235, 0.1);

  &:hover {
    background: rgba(37, 99, 235, 0.2);
  }
}

.asset-tag--look {
  color: #7c2d12;
  background: rgba(251, 146, 60, 0.18);

  &:hover {
    background: rgba(251, 146, 60, 0.28);
  }
}

.asset-tag--scene {
  color: #047857;
  background: rgba(16, 185, 129, 0.12);

  &:hover {
    background: rgba(16, 185, 129, 0.22);
  }
}

.asset-tag--prop {
  color: #b45309;
  background: rgba(245, 158, 11, 0.13);

  &:hover {
    background: rgba(245, 158, 11, 0.24);
  }
}
</style>
