<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { ElMessage } from 'element-plus'
import { Search } from '@element-plus/icons-vue'
import { t } from '@/i18n'
import { listAssets } from '@/api/asset'
import type { Asset, AssetType } from '@/types'

const loading = ref(false)
const assets = ref<Asset[]>([])
const keyword = ref('')
const typeFilter = ref<AssetType | ''>('')

const TYPE_LABEL: Record<AssetType, string> = {
  character: '人物',
  scene: '场景',
  prop: '道具',
}

onMounted(load)

async function load() {
  loading.value = true
  try {
    assets.value = await listAssets({ scope: 'shared' })
  } catch (err: unknown) {
    ElMessage.error((err as Error)?.message || t('共享资产加载失败'))
  } finally {
    loading.value = false
  }
}

const filtered = computed(() => {
  const kw = keyword.value.trim().toLowerCase()
  return assets.value.filter((asset) => {
    if (typeFilter.value && asset.type !== typeFilter.value) return false
    if (kw && !asset.name.toLowerCase().includes(kw)) return false
    return true
  })
})

interface OwnerGroup {
  ownerId: number
  ownerName: string
  assets: Asset[]
}

const groups = computed<OwnerGroup[]>(() => {
  const map = new Map<number, OwnerGroup>()
  for (const asset of filtered.value) {
    const ownerId = asset.owner_user_id ?? 0
    if (!map.has(ownerId)) {
      map.set(ownerId, { ownerId, ownerName: asset.owner_name || t('未知用户'), assets: [] })
    }
    map.get(ownerId)!.assets.push(asset)
  }
  return Array.from(map.values()).sort((a, b) => a.ownerName.localeCompare(b.ownerName))
})

function coverUrl(asset: Asset): string {
  const images = asset.images ?? []
  const core = images.find((img) => img.view_type === 'main' && (img.url || '').trim() !== '')
  if (core?.url) return core.url
  const nonLook = images.find((img) => img.reference_role !== 'look' && img.view_type !== 'look' && (img.url || '').trim() !== '')
  if (nonLook?.url) return nonLook.url
  return images.find((img) => (img.url || '').trim() !== '')?.url || ''
}

function ownerInitial(name: string): string {
  return (name.trim()[0] || '?').toUpperCase()
}
</script>

<template>
  <div class="shared-assets-panel" v-loading="loading">
    <div class="shared-assets-header">
      <div>
        <h3>{{ t('共享资产') }}</h3>
        <p>{{ t('共享资产会按分享者分组显示，可直接预览参考图。') }}</p>
      </div>
      <span class="shared-assets-count">{{ t('{count} 个', { count: filtered.length }) }}</span>
    </div>

    <div class="shared-assets-toolbar">
      <el-input
        v-model="keyword"
        :placeholder="t('搜索资产名称')"
        size="small"
        clearable
        class="shared-assets-search"
        :prefix-icon="Search"
      />
      <el-radio-group v-model="typeFilter" size="small" class="shared-assets-type-filter">
        <el-radio-button value="">{{ t('全部类型') }}</el-radio-button>
        <el-radio-button value="character">{{ t('人物') }}</el-radio-button>
        <el-radio-button value="scene">{{ t('场景') }}</el-radio-button>
        <el-radio-button value="prop">{{ t('道具') }}</el-radio-button>
      </el-radio-group>
    </div>

    <div v-if="!loading && groups.length === 0" class="shared-assets-empty">
      <div class="shared-assets-empty__mark">S</div>
      <strong>{{ t('还没有人分享资产给你') }}</strong>
      <span>{{ t('收到分享后，资产会按分享者出现在这里。') }}</span>
    </div>

    <div v-for="group in groups" :key="group.ownerId" class="shared-assets-group">
      <div class="shared-assets-group-title">
        <span class="shared-assets-owner-avatar">{{ ownerInitial(group.ownerName) }}</span>
        <div>
          <h4>{{ t('来自 {name}', { name: group.ownerName }) }}</h4>
          <p>{{ t('{count} 个', { count: group.assets.length }) }}</p>
        </div>
      </div>
      <div class="shared-assets-grid">
        <div v-for="asset in group.assets" :key="asset.id" class="shared-asset-card">
          <div class="shared-asset-cover">
            <el-image
              v-if="coverUrl(asset)"
              :src="coverUrl(asset)"
              :preview-src-list="[coverUrl(asset)]"
              fit="cover"
              loading="lazy"
              class="shared-asset-cover-img"
            />
            <div v-else class="shared-asset-cover-empty">{{ t('暂无图片') }}</div>
          </div>
          <div class="shared-asset-info">
            <div class="shared-asset-info__top">
              <span class="shared-asset-name">{{ asset.name }}</span>
              <span class="shared-asset-type">{{ t(TYPE_LABEL[asset.type]) }}</span>
            </div>
            <p>{{ asset.description || t('暂无描述') }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.shared-assets-panel {
  min-height: 360px;
  padding: 22px 24px 40px;
  background: var(--canvas);
}

.shared-assets-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  margin-bottom: 16px;

  h3 {
    margin: 0;
    color: var(--on-dark);
    font-size: 18px;
    font-weight: 950;
    letter-spacing: 0;
  }

  p {
    margin: 5px 0 0;
    color: var(--muted);
    font-size: 12px;
    font-weight: 650;
  }
}

.shared-assets-count {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  min-height: 24px;
  padding: 0 9px;
  border: 1px solid var(--hairline);
  border-radius: 999px;
  background: var(--surface-card);
  color: var(--muted);
  font-size: 11px;
  font-weight: 850;
}

.shared-assets-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 20px;
  padding: 12px;
  border: 1px solid var(--hairline);
  border-radius: 10px;
  background: var(--surface-card);
  box-shadow: 0 1px 2px rgba(16, 32, 51, 0.03);
}

.shared-assets-search {
  width: 280px;

  :deep(.el-input__wrapper) {
    border-radius: 8px !important;
    background: var(--surface-raised) !important;
    border: 1px solid var(--hairline) !important;
    box-shadow: none !important;
  }
}

.shared-assets-type-filter {
  :deep(.el-radio-button__inner) {
    height: 30px;
    border-color: var(--hairline) !important;
    background: var(--surface-raised) !important;
    color: var(--muted) !important;
    font-size: 12px;
    font-weight: 800;
    box-shadow: none !important;
  }

  :deep(.el-radio-button__original-radio:checked + .el-radio-button__inner) {
    border-color: var(--primary) !important;
    background: var(--primary) !important;
    color: var(--on-primary) !important;
  }
}

.shared-assets-empty {
  min-height: 260px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  text-align: center;
  color: var(--muted);
  padding: 44px 0;
  border: 1px dashed var(--hairline-strong);
  border-radius: 12px;
  background: var(--surface-card);

  strong {
    color: var(--body-strong);
    font-size: 15px;
    font-weight: 900;
  }

  span {
    color: var(--muted);
    font-size: 12px;
  }
}

.shared-assets-empty__mark {
  width: 42px;
  height: 42px;
  border-radius: 12px;
  display: grid;
  place-items: center;
  background: rgba(var(--brand-cyan-rgb), 0.1);
  color: var(--accent-cyan);
  font-weight: 950;
}

.shared-assets-group {
  margin-bottom: 28px;
  padding: 16px;
  border: 1px solid var(--hairline);
  border-radius: 12px;
  background: var(--surface-card);
  box-shadow: 0 1px 2px rgba(16, 32, 51, 0.03);
}

.shared-assets-group-title {
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0 0 14px;

  h4 {
    margin: 0;
    color: var(--body-strong);
    font-size: 14px;
    font-weight: 950;
  }

  p {
    margin: 2px 0 0;
    color: var(--muted-soft);
    font-size: 11px;
    font-weight: 800;
  }
}

.shared-assets-owner-avatar {
  width: 32px;
  height: 32px;
  flex-shrink: 0;
  display: grid;
  place-items: center;
  border: 1px solid rgba(var(--brand-cyan-rgb), 0.22);
  border-radius: 9px;
  background: rgba(var(--brand-cyan-rgb), 0.08);
  color: var(--accent-cyan);
  font-size: 13px;
  font-weight: 950;
}

.shared-assets-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 14px;
}

.shared-asset-card {
  border: 1px solid var(--hairline);
  border-radius: 10px;
  overflow: hidden;
  background: var(--surface-raised);
  transition:
    border-color var(--duration-fast) var(--ease-out),
    box-shadow var(--duration-fast) var(--ease-out),
    transform var(--duration-fast) var(--ease-out);

  &:hover {
    transform: translateY(-1px);
    border-color: rgba(var(--brand-cyan-rgb), 0.35);
    box-shadow: 0 12px 28px rgba(16, 32, 51, 0.08);
  }
}

.shared-asset-cover {
  width: 100%;
  aspect-ratio: 4 / 3;
  background: var(--surface-soft);
  border-bottom: 1px solid var(--hairline);
}

.shared-asset-cover-img {
  width: 100%;
  height: 100%;
  cursor: zoom-in;
}

.shared-asset-cover-empty {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--muted-soft);
  font-size: 12px;
}

.shared-asset-info {
  padding: 10px 12px 12px;
  display: flex;
  flex-direction: column;
  gap: 7px;

  p {
    margin: 0;
    min-height: 34px;
    color: var(--muted);
    font-size: 12px;
    line-height: 1.45;
    display: -webkit-box;
    overflow: hidden;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
  }
}

.shared-asset-info__top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.shared-asset-name {
  font-size: 13px;
  font-weight: 900;
  color: var(--body-strong);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.shared-asset-type {
  flex-shrink: 0;
  padding: 3px 6px;
  border-radius: 999px;
  background: rgba(var(--brand-cyan-rgb), 0.08);
  color: var(--accent-cyan);
  font-size: 11px;
  font-weight: 850;
}

@media (max-width: 760px) {
  .shared-assets-toolbar,
  .shared-assets-header {
    align-items: stretch;
    flex-direction: column;
  }

  .shared-assets-search {
    width: 100%;
  }

  .shared-assets-grid {
    grid-template-columns: repeat(auto-fill, minmax(170px, 1fr));
  }
}
</style>
