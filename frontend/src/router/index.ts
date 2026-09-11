import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { t } from '@/i18n'

const router = createRouter({
  history: createWebHistory('/admin/'),
  routes: [
    {
      path: '/',
      redirect: '/dashboard',
    },
    {
      path: '/dashboard',
      name: 'dashboard',
      component: () => import('@/views/DashboardView.vue'),
      meta: { titleKey: '总览', productionOnly: true },
    },
    {
      path: '/ops',
      name: 'adminOverview',
      component: () => import('@/views/AdminOverviewView.vue'),
      meta: { titleKey: '运营总览', requiresAdmin: true },
    },
    {
      path: '/admin-series',
      name: 'adminSeries',
      component: () => import('@/views/AdminSeriesView.vue'),
      meta: { titleKey: '剧本记录', requiresAdmin: true },
    },
    {
      path: '/admin-runs',
      name: 'adminRuns',
      component: () => import('@/views/AdminRunsView.vue'),
      meta: { titleKey: '任务记录', requiresAdmin: true },
    },
    {
      path: '/series',
      name: 'series',
      component: () => import('@/views/SeriesView.vue'),
      meta: { titleKey: '作品生产', productionOnly: true },
    },
    {
      path: '/workflow',
      name: 'workflow',
      component: () => import('@/views/WorkflowView.vue'),
      meta: { titleKey: '流程编排', productionOnly: true },
    },
    {
      path: '/quick-create',
      name: 'quickCreate',
      component: () => import('@/views/QuickCreateView.vue'),
      meta: { titleKey: '灵感速创', productionOnly: true },
    },
    {
      path: '/script-creation',
      name: 'scriptCreation',
      component: () => import('@/views/ScriptCreationView.vue'),
      meta: { titleKey: '剧本创作', productionOnly: true },
    },
    {
      path: '/models',
      name: 'models',
      component: () => import('@/views/models/IndexView.vue'),
      meta: { titleKey: '模型配置', requiresAdmin: true },
    },
    {
      path: '/assets',
      name: 'assets',
      component: () => import('@/views/AssetsView.vue'),
      meta: { titleKey: '资产管理', productionOnly: true },
    },
    {
      path: '/my-credits',
      name: 'myCredits',
      component: () => import('@/views/MyCreditsView.vue'),
      meta: { titleKey: '积分清单', productionOnly: true },
    },
    {
      path: '/logs',
      name: 'logs',
      component: () => import('@/views/LogsView.vue'),
      meta: { titleKey: 'AI 日志', requiresAdmin: true },
    },
    {
      path: '/operation-logs',
      name: 'operationLogs',
      component: () => import('@/views/AdminOperationLogsView.vue'),
      meta: { titleKey: '操作日志', requiresAdmin: true },
    },
    {
      path: '/users',
      name: 'users',
      component: () => import('@/views/UsersView.vue'),
      meta: { titleKey: '用户管理', requiresAdmin: true },
    },
    {
      path: '/credit-pricing',
      name: 'creditPricing',
      component: () => import('@/views/CreditPricingView.vue'),
      meta: { titleKey: '积分物价表', requiresAdmin: true },
    },
    {
      path: '/stats',
      name: 'stats',
      component: () => import('@/views/StatsView.vue'),
      meta: { titleKey: '积分统计', requiresAdmin: true },
    },
    {
      path: '/login',
      name: 'login',
      component: () => import('@/views/LoginView.vue'),
      meta: { titleKey: '登录', layout: 'blank' },
    },
    {
      path: '/:pathMatch(.*)*',
      redirect: '/dashboard',
    },
  ],
})

function updateDocumentTitle(to = router.currentRoute.value) {
  const titleKey = to.meta?.titleKey as string | undefined
  document.title = titleKey ? `${t(titleKey)} — 智梦工厂` : '智梦工厂 Intelligent Dream Factory'
}

router.afterEach((to) => {
  updateDocumentTitle(to)
  if (typeof sessionStorage !== 'undefined') {
    sessionStorage.removeItem(chunkReloadKey(to.fullPath))
  }
})

const CHUNK_RELOAD_PREFIX = 'admin:chunk-reload:'

function chunkReloadKey(path: string): string {
  return `${CHUNK_RELOAD_PREFIX}${path || '/'}`
}

function isChunkLoadError(error: unknown): boolean {
  const message = String((error as { message?: string } | null)?.message ?? error ?? '')
  return /Failed to fetch dynamically imported module|error loading dynamically imported module|Importing a module script failed|Loading chunk [\w-]+ failed/i.test(message)
}

// 发版后旧入口 JS 会引用已删除的 hashed chunk；捕获后强制整页刷新一次拉新 index。
router.onError((error, to) => {
  if (typeof window === 'undefined' || !isChunkLoadError(error)) return
  const key = chunkReloadKey(to.fullPath)
  if (sessionStorage.getItem(key) === '1') return
  sessionStorage.setItem(key, '1')
  window.location.reload()
})

if (typeof window !== 'undefined') {
}

router.beforeEach(async (to) => {
  const auth = useAuthStore()
  const isLogin = to.name === 'login'

  if (!auth.isAuthenticated && !isLogin) {
    return { name: 'login', query: { redirect: to.fullPath } }
  }

  // 已登录则同步服务端资料（含积分）；本地缓存的 user 可能是登录时的旧余额。
  if (auth.isAuthenticated && !isLogin) {
    try {
      if (!auth.user || !auth.user.role) {
        await auth.hydrate()
      } else {
        void auth.refreshProfile(false)
      }
    } catch {
      auth.logout()
      return { name: 'login', query: { redirect: to.fullPath } }
    }
  }

  if (auth.isAuthenticated && isLogin) {
    return { name: auth.user?.role === 'admin' ? 'adminOverview' : 'dashboard' }
  }

  if (auth.isAuthenticated && to.meta?.requiresAdmin && auth.user?.role !== 'admin') {
    return { name: 'dashboard' }
  }

  if (auth.isAuthenticated && to.meta?.productionOnly && auth.user?.role === 'admin') {
    return { name: 'adminOverview' }
  }
})

export default router
