<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { ElMessageBox } from 'element-plus'
import { useRouter } from 'vue-router'
import { t } from '@/i18n'
import { resolveIcon } from '@/utils/iconRegistry'
import { ecommerce } from '@/features/ecommerce/api'
import { errorText } from '@/features/ecommerce/useEcommerce'
import { createStorefrontProject } from '@/features/ecommerce/domain/storefront.js'
import type { ProjectBrief } from '@/features/ecommerce/types'

const products = ref<ProjectBrief[]>([])
const router = useRouter()
const loading = ref(true)
const busy = ref(false)
const error = ref('')

function formatDate(value: string) {
  const raw = typeof value === 'string' ? value : ''
  const date = new Date(raw.replace(' ', 'T'))
  return Number.isNaN(date.getTime()) ? (raw || t('未记录')) : date.toLocaleDateString('zh-CN', { month: 'short', day: 'numeric' })
}
function openProduct(product: ProjectBrief, event?: MouseEvent) {
  if ((event?.target as HTMLElement | null)?.closest('.product-menu')) return
  if (product.id === null || product.id === undefined) return
  void router.push({ name: 'ecommerceProduct', params: { id: String(product.id) } })
}
async function load() {
  loading.value = true
  try { products.value = await ecommerce.list(); error.value = '' } catch (reason) { error.value = errorText(reason) } finally { loading.value = false }
}
async function createProduct() {
  if (busy.value) return
  busy.value = true
  try {
    const state = createStorefrontProject()
    state.name = t('未命名商品')
    const detail = await ecommerce.create(state)
    await load()
    await router.push({ name: 'ecommerceProduct', params: { id: detail.id } })
  } catch (reason) { error.value = errorText(reason) } finally { busy.value = false }
}
async function deleteProduct(product: ProjectBrief) {
  if (product.id === null || product.id === undefined) { error.value = t('商品编号无效'); return }
  try { await ElMessageBox.confirm(t('删除商品及其所有生成记录？'), t('删除商品'), { type: 'warning' }) } catch { return }
  busy.value = true
  try { await ecommerce.delete(product.id); await load() } catch (reason) { error.value = errorText(reason) } finally { busy.value = false }
}
onMounted(load)
</script>

<template>
  <div class="commerce-home">
    <header class="home-header">
      <div class="home-title"><el-icon :size="24"><component :is="resolveIcon('Box')" /></el-icon><div><h1>{{ t('我的商品') }}</h1><p>{{ t('每个商品都有自己的创作记录与素材') }}</p></div></div>
      <el-button type="primary" :icon="resolveIcon('Plus')" :loading="busy" @click="createProduct">{{ t('新建商品') }}</el-button>
    </header>
    <el-alert v-if="error" :title="error" type="error" show-icon closable @close="error = ''" />
    <main class="product-home-body">
      <section v-if="loading" class="product-loading" aria-busy="true" :aria-label="t('正在加载商品')">
        <div class="loading-toolbar" aria-hidden="true"><span class="skeleton-line skeleton-line--label" /><span class="skeleton-line skeleton-line--action" /></div>
        <div class="product-skeleton-grid" aria-hidden="true">
          <article v-for="n in 5" :key="n" class="product-skeleton-card" :class="{ 'product-skeleton-card--feature': n === 1 }">
            <div class="skeleton-cover"><span class="skeleton-orbit" /></div>
            <div class="skeleton-card-body"><span class="skeleton-line skeleton-line--title" /><span class="skeleton-line skeleton-line--meta" /></div>
          </article>
        </div>
        <p class="loading-caption"><span class="loading-dot" />{{ t('正在加载商品') }}</p>
      </section>
      <template v-else>
        <div class="home-toolbar"><div><strong>{{ t('商品库') }}</strong><span>{{ t('{count} 个商品', { count: products.length }) }}</span></div><el-button link :icon="resolveIcon('Refresh')" :disabled="loading" @click="load">{{ t('刷新') }}</el-button></div>
        <div v-if="products.length" class="product-grid">
          <article v-for="product in products" :key="product.id ?? product.name" class="product-card" tabindex="0" @click="openProduct(product, $event)" @keydown.enter="openProduct(product, $event)">
            <div class="product-cover"><img v-if="product.cover_url" :src="product.cover_url" :alt="product.product_name || product.name" /><el-icon v-else :size="36"><component :is="resolveIcon('Goods')" /></el-icon><span class="product-open"><el-icon><component :is="resolveIcon('ArrowRight')" /></el-icon></span></div>
            <div class="product-card-body"><div><h2>{{ product.product_name || product.name || t('未命名商品') }}</h2><p>{{ t('最近编辑 {date}', { date: formatDate(product.update_time) }) }}</p></div><el-dropdown class="product-menu" trigger="click" @click.stop @mousedown.stop><el-button text :icon="resolveIcon('MoreFilled')" :aria-label="t('商品操作')" @click.stop /><template #dropdown><el-dropdown-menu><el-dropdown-item :icon="resolveIcon('Delete')" @click.stop="deleteProduct(product)">{{ t('删除商品') }}</el-dropdown-item></el-dropdown-menu></template></el-dropdown></div>
          </article>
          <button class="new-product-card" type="button" :disabled="busy" @click="createProduct"><el-icon :size="26"><component :is="resolveIcon('Plus')" /></el-icon><strong>{{ t('添加新商品') }}</strong><span>{{ t('从商品图片开始创作') }}</span></button>
        </div>
        <button v-else class="home-empty" type="button" :disabled="busy" @click="createProduct"><span class="empty-icon"><el-icon :size="30"><component :is="resolveIcon('Plus')" /></el-icon></span><strong>{{ t('还没有商品') }}</strong><span>{{ t('添加第一件商品，开始生成主图、详情图和视频') }}</span></button>
      </template>
    </main>
  </div>
</template>

<style scoped>
.commerce-home { height: 100%; min-height: 0; overflow: auto; background: radial-gradient(circle at 76% 0%, rgba(35,76,160,.16), transparent 34%), #07101e; color: var(--body); padding: 34px clamp(24px, 5vw, 76px); box-sizing: border-box; }
.home-header { max-width: 1220px; margin: 0 auto 32px; display: flex; align-items: center; justify-content: space-between; gap: 24px; }.home-title { display: flex; align-items: center; gap: 14px; }.home-title > .el-icon { color: #55d6ff; }.home-title h1 { margin: 0; font-size: 26px; letter-spacing: -.02em; }.home-title p { margin: 6px 0 0; color: var(--muted); font-size: 13px; }.commerce-home :deep(.el-alert) { max-width: 1220px; margin: 0 auto 18px; }.product-home-body { max-width: 1220px; margin: 0 auto; }.home-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 18px; }.home-toolbar div { display: flex; align-items: baseline; gap: 12px; }.home-toolbar strong { font-size: 15px; }.home-toolbar span { color: var(--muted); font-size: 12px; }.product-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 18px; }.product-card,.new-product-card { min-width: 0; overflow: hidden; border: 1px solid #1d3555; border-radius: 12px; background: linear-gradient(145deg, rgba(18,37,65,.9), rgba(8,20,37,.96)); color: var(--body); box-shadow: 0 14px 36px rgba(0,0,0,.16); }.product-card { display: flex; flex-direction: column; cursor: pointer; transition: transform .18s ease, border-color .18s ease; }.product-card:hover,.product-card:focus-visible { transform: translateY(-3px); border-color: #4288f4; outline: none; }.product-cover { position: relative; flex: 0 0 198px; height: 198px; display: grid; place-items: center; background: #0b1728; color: #506783; }.product-cover img { width: 100%; height: 100%; object-fit: cover; display: block; }.product-open { position: absolute; right: 12px; bottom: 12px; display: grid; place-items: center; width: 30px; height: 30px; border-radius: 50%; background: rgba(4,13,28,.78); color: #8ec5ff; }.product-card-body { position: relative; z-index: 1; display: flex; min-height: 76px; background: rgba(8,20,37,.98); align-items: flex-start; justify-content: space-between; gap: 8px; padding: 15px 15px 16px; }.product-card-body h2 { margin: 0; font-size: 14px; font-weight: 600; }.product-card-body p { margin: 7px 0 0; color: var(--muted); font-size: 11px; }.product-card-body :deep(.el-button) { color: var(--muted); }.new-product-card { min-height: 262px; display: flex; flex-direction: column; align-items: center; justify-content: center; gap: 9px; border-style: dashed; color: #83b8ff; cursor: pointer; }.new-product-card span { color: var(--muted); font-size: 11px; }.new-product-card:hover { border-color: #4a8eff; background: rgba(41,88,160,.2); }.home-empty { width: min(520px,100%); min-height: 260px; margin: 80px auto; display: flex; flex-direction: column; justify-content: center; align-items: center; gap: 11px; border: 1px dashed #2e4c72; border-radius: 16px; background: rgba(13,31,54,.55); color: var(--body); cursor: pointer; }.empty-icon { display: grid; place-items: center; width: 58px; height: 58px; border-radius: 50%; background: rgba(55,111,236,.2); color: #82b7ff; }.home-empty span:last-child { color: var(--muted); font-size: 12px; }@media (max-width: 640px) { .commerce-home { padding: 24px 16px; }.home-header { align-items: flex-start; }.home-title h1 { font-size: 22px; }.home-title p { max-width: 180px; line-height: 18px; }.product-grid { grid-template-columns: 1fr 1fr; gap: 11px; }.product-cover { height: 145px; }.product-card-body { padding: 11px; }.new-product-card { min-height: 208px; } }

/* The loading state mirrors the product grid instead of blocking the whole page
   with Element Plus' dark spinner mask. */
.product-loading { position: relative; min-height: 390px; padding: 16px 0 24px; }
.loading-toolbar { display: flex; justify-content: space-between; align-items: center; margin: 0 0 18px; }
.product-skeleton-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 18px; }
.product-skeleton-card { overflow: hidden; border: 1px solid rgba(70, 105, 150, .38); border-radius: 14px; background: rgba(10, 27, 50, .66); }
.product-skeleton-card--feature { grid-column: span 2; }
.skeleton-cover { position: relative; height: 198px; overflow: hidden; background: linear-gradient(135deg, #102642 0%, #0c1d36 48%, #142f4b 100%); }
.skeleton-cover::after, .skeleton-card-body::after { content: ''; position: absolute; inset: 0; transform: translateX(-100%); background: linear-gradient(105deg, transparent 18%, rgba(120, 191, 255, .13) 48%, transparent 78%); animation: product-skeleton-shine 1.8s cubic-bezier(.4, 0, .2, 1) infinite; }
.skeleton-orbit { position: absolute; z-index: 1; top: 50%; left: 50%; width: 40px; height: 40px; margin: -20px; border: 1px solid rgba(105, 190, 255, .26); border-radius: 50%; box-shadow: 0 0 0 8px rgba(75, 148, 225, .06), 0 0 26px rgba(51, 151, 239, .15); }
.skeleton-orbit::after { content: ''; position: absolute; top: -2px; left: 18px; width: 5px; height: 5px; border-radius: 50%; background: #72d8ff; box-shadow: 0 0 14px #72d8ff; animation: product-skeleton-orbit 1.6s linear infinite; transform-origin: 2px 22px; }
.skeleton-card-body { position: relative; display: flex; flex-direction: column; gap: 11px; padding: 15px; overflow: hidden; background: rgba(8, 20, 37, .84); }
.skeleton-line { display: block; position: relative; z-index: 1; overflow: hidden; border-radius: 5px; background: rgba(105, 143, 183, .20); }
.skeleton-line--label { width: 86px; height: 13px; }.skeleton-line--action { width: 44px; height: 12px; border-radius: 10px; }.skeleton-line--title { width: 62%; height: 14px; }.skeleton-line--meta { width: 39%; height: 10px; opacity: .7; }
.loading-caption { display: flex; justify-content: center; align-items: center; gap: 8px; margin: 27px 0 0; color: #7692b3; font-size: 12px; letter-spacing: .04em; }.loading-dot { width: 6px; height: 6px; border-radius: 50%; background: #65d4ff; box-shadow: 0 0 12px rgba(101, 212, 255, .75); animation: product-loading-pulse 1.1s ease-in-out infinite; }
@keyframes product-skeleton-shine { to { transform: translateX(100%); } }
@keyframes product-skeleton-orbit { to { transform: rotate(360deg); } }
@keyframes product-loading-pulse { 50% { opacity: .3; transform: scale(.7); } }
@media (prefers-reduced-motion: reduce) { .skeleton-cover::after, .skeleton-card-body::after, .skeleton-orbit::after, .loading-dot { animation: none; } }
@media (max-width: 640px) { .product-loading { min-height: 300px; }.product-skeleton-grid { gap: 11px; }.product-skeleton-card--feature { grid-column: span 1; }.skeleton-cover { height: 145px; }.loading-caption { margin-top: 20px; } }
</style>
