<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useAppStore } from '@/stores/app'
import { useAuthStore } from '@/stores/auth'
import { storeToRefs } from 'pinia'
import { resolveIcon } from '@/utils/iconRegistry'

const route = useRoute()
const router = useRouter()
const appStore = useAppStore()
const auth = useAuthStore()
const { t } = useI18n()
const { isRailCollapsed } = storeToRefs(appStore)

/** public/brand/logo.png → served under Vite base (/admin/) */
const brandLogoUrl = `${import.meta.env.BASE_URL}brand/logo.png`

const creditBalanceText = computed(() =>
  Number(auth.user?.credit_balance || 0).toLocaleString(undefined, {
    minimumFractionDigits: 0,
    maximumFractionDigits: 2,
  }),
)

const isAdmin = computed(() => auth.user?.role === 'admin')
const homeRouteName = computed(() => (isAdmin.value ? 'adminOverview' : 'dashboard'))

const navItems = computed(() => {
  if (isAdmin.value) {
    return [
      { name: 'adminOverview', label: t('运营总览'), desc: t('全局生产状态'), icon: 'DataAnalysis' },
      { name: 'adminSeries', label: t('剧本记录'), desc: t('用户创建作品'), icon: 'Film' },
      { name: 'adminRuns', label: t('任务记录'), desc: t('流程执行情况'), icon: 'Operation' },
      { name: 'users', label: t('用户'), desc: t('账号权限'), icon: 'User' },
      { name: 'creditPricing', label: t('物价表'), desc: t('积分扣费标准'), icon: 'Tickets' },
      { name: 'models', label: t('模型'), desc: t('引擎配置'), icon: 'Cpu' },
      { name: 'logs', label: t('日志'), desc: t('AI 调用记录'), icon: 'Document' },
      { name: 'operationLogs', label: t('操作日志'), desc: t('业务审计'), icon: 'List' },
      { name: 'stats', label: t('统计'), desc: t('积分消耗'), icon: 'TrendCharts' },
    ]
  }

  return [
    { name: 'dashboard', label: t('总览'), desc: t('制作状态'), icon: 'DataBoard' },
    { name: 'series', label: t('作品'), desc: t('剧本到成片'), icon: 'Film' },
    { name: 'scriptCreation', label: t('剧本创作'), desc: t('AI 自动创作'), icon: 'EditPen' },
    { name: 'quickCreate', label: t('速创'), desc: t('对话快速生成'), icon: 'MagicStick' },
    { name: 'assets', label: t('资产'), desc: t('角色场景道具'), icon: 'Picture' },
    { name: 'workflow', label: t('流程'), desc: t('模板与编排'), icon: 'Share' },
    { name: 'myCredits', label: t('积分'), desc: t('消耗清单'), icon: 'Tickets' },
  ]
})

function isActive(name: string) {
  return route.name === name
}

function navigate(name: string) {
  void router.push({ name })
}

function logout() {
  auth.logout()
  void router.replace({ name: 'login' })
}

function toggleRail() {
  appStore.toggleRail()
}

function goHome() {
  void router.push({ name: homeRouteName.value })
}

</script>

<template>
  <aside class="app-sidebar" :class="{ 'is-collapsed': isRailCollapsed }">
    <!-- Top Brand：仅居中 logo -->
    <div class="sidebar-top">
      <button type="button" class="brand" @click="goHome" :title="t('总览')" aria-label="Home">
        <img class="brand-logo" :src="brandLogoUrl" alt="" width="96" height="96" draggable="false" />
      </button>
    </div>

    <!-- Navigation Menu -->
    <div class="sidebar-menu">
      <button
        v-for="item in navItems"
        :key="item.name"
        type="button"
        class="menu-item"
        :class="{ 'is-active': isActive(item.name) }"
        :title="isRailCollapsed ? item.label : ''"
        @click="navigate(item.name)"
      >
        <el-icon class="menu-icon" :size="22">
          <component :is="resolveIcon(item.icon)" />
        </el-icon>
        <span class="menu-copy" v-if="!isRailCollapsed">
          <span class="menu-label">{{ item.label }}</span>
          <span class="menu-desc">{{ item.desc }}</span>
        </span>
      </button>
    </div>

    <!-- Bottom Actions / User Info -->
    <div class="sidebar-bottom">
      <el-dropdown trigger="click" placement="right-end" class="user-dropdown">
        <div class="user-profile">
          <div class="user-avatar">
            <el-icon><component :is="resolveIcon('UserFilled')" /></el-icon>
          </div>
          <div class="user-info" v-if="!isRailCollapsed">
            <span class="username">{{ auth.user?.display_name || auth.user?.username || t('当前用户') }}</span>
            <span class="user-role">
              {{ auth.user?.role === 'admin' ? t('管理员') : t('普通用户') }}
              · {{ t('积分') }} {{ creditBalanceText }}
            </span>
          </div>
        </div>
        <template #dropdown>
          <el-dropdown-menu class="sidebar-user-menu">
            <el-dropdown-item disabled class="menu-user-title">
              {{ auth.user?.display_name || auth.user?.username || t('当前用户') }}
            </el-dropdown-item>
            <el-dropdown-item divided @click="logout" class="logout-item">
              <el-icon><component :is="resolveIcon('SwitchButton')" /></el-icon>{{ t('退出登录') }}
            </el-dropdown-item>
          </el-dropdown-menu>
        </template>
      </el-dropdown>

      <button type="button" class="sidebar-toggle" @click="toggleRail" :title="isRailCollapsed ? t('展开菜单') : t('收起菜单')">
        <el-icon :size="16">
          <component :is="resolveIcon(isRailCollapsed ? 'Expand' : 'Fold')" />
        </el-icon>
      </button>
    </div>
  </aside>
</template>

<style scoped lang="scss">
.app-sidebar {
  display: flex;
  flex-direction: column;
  width: 280px;
  height: 100vh;
  background: linear-gradient(180deg, #0a101c 0%, #10182a 55%, #121c33 100%);
  border-right: 1px solid var(--hairline);
  flex-shrink: 0;
  transition: width var(--duration-normal) var(--ease-out);
  overflow: hidden;
  z-index: 100;

  &.is-collapsed {
    width: 68px;

    .sidebar-top {
      height: 80px;
      padding: 0 var(--space-xs);
    }

    .brand-logo {
      width: 56px;
      height: 56px;
    }

    .menu-item {
      padding: 0;
      justify-content: center;
      width: 44px;
      margin: 4px auto;
    }

    .user-profile {
      justify-content: center;
      padding: 0;
      width: 40px;
      height: 40px;
    }

    .sidebar-bottom {
      align-items: center;
      padding: var(--space-md) var(--space-xs);
    }
  }
}

// ── Top Brand ─────────────────────────────────────────────────────────────────
.sidebar-top {
  height: 120px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  padding: 0 var(--space-md);
  flex-shrink: 0;
}

.brand {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 100%;
  height: 100%;
  margin: 0;
  padding: 0;
  border: 0;
  background: transparent;
  cursor: pointer;
}

.brand-logo {
  width: 96px;
  height: 96px;
  object-fit: contain;
  object-position: center;
  display: block;
  flex-shrink: 0;
  border: 0;
  border-radius: 0;
  background: transparent;
  filter: drop-shadow(0 2px 10px rgba(0, 0, 0, 0.4));
}

/* dark tech navigation shell */
.app-sidebar {
  background: linear-gradient(180deg, #0a101c 0%, #10182a 55%, #121c33 100%);
  border-right: 1px solid var(--hairline);
  box-shadow: inset -1px 0 0 rgba(34, 211, 238, 0.04);
}

.menu-item {
  color: #aeb9d0;

  &:hover {
    background: rgba(255, 255, 255, 0.07);
    color: #ffffff;
  }

  &.is-active {
    background: linear-gradient(135deg, rgba(99, 102, 241, 0.9), rgba(79, 70, 229, 0.72));
    color: #ffffff;
  }
}

.menu-desc,
.user-role {
  color: #8c9ab7;
}

.username,
.language-toggle,
.sidebar-toggle {
  color: #ffffff;
}

// ── Menu items ────────────────────────────────────────────────────────────────
.sidebar-menu {
  flex: 1;
  padding: var(--space-md) 0;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
}

.menu-item {
  position: relative;
  display: flex;
  align-items: center;
  gap: var(--space-sm);
  min-height: 54px;
  margin: 4px 16px;
  padding: 0 16px;
  border: none;
  background: transparent;
  border-radius: var(--radius-md);
  color: var(--muted);
  cursor: pointer;
  transition: all var(--duration-fast) var(--ease-out);
  white-space: nowrap;

  &::before {
    content: '';
    position: absolute;
    left: -12px;
    width: 3px;
    height: 22px;
    border-radius: var(--radius-pill);
    background: transparent;
  }

  .menu-icon {
    color: var(--muted);
    transition: color var(--duration-fast);
  }

  &:hover {
    color: var(--body-strong);
    background: var(--surface-soft);

    .menu-icon {
      color: var(--body-strong);
    }
  }

  &.is-active {
    color: var(--primary);
    background: rgba(var(--brand-cyan-rgb), 0.1);
    font-weight: 600;

    &::before {
      background: var(--accent-cyan);
    }

    .menu-icon {
      color: var(--primary);
    }
    
    .menu-label { font-weight: 800; }
  }
}

.menu-copy {
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
}

.menu-label {
  font-size: 14px;
  font-weight: 700;
  line-height: 1.15;
}

.menu-desc {
  margin-top: 2px;
  color: var(--muted-soft);
  font-size: 11px;
  font-weight: 600;
  line-height: 1.1;
}

// ── Bottom section ────────────────────────────────────────────────────────────
.sidebar-bottom {
  border-top: 1px solid var(--hairline);
  padding: var(--space-md) var(--space-md);
  display: flex;
  flex-direction: column;
  gap: var(--space-md);
  flex-shrink: 0;
}

.user-dropdown {
  width: 100%;
}

.user-profile {
  display: flex;
  align-items: center;
  gap: var(--space-md);
  padding: 6px;
  border-radius: var(--radius-md);
  cursor: pointer;
  transition: background var(--duration-fast);
  width: 100%;

  &:hover {
    background: var(--surface-soft);
  }
}

.user-avatar {
  width: 32px;
  height: 32px;
  border-radius: var(--radius-full);
  background: var(--surface-soft);
  border: 1px solid var(--hairline-strong);
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--muted);
  flex-shrink: 0;
}

.user-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
  text-align: left;
}

.username {
  font-size: 13px;
  font-weight: 600;
  color: var(--on-dark);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  line-height: 1.2;
}

.user-role {
  font-size: 11px;
  color: var(--muted);
  line-height: 1.2;
  margin-top: 2px;
}

.sidebar-toggle {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 32px;
  border: none;
  background: transparent;
  color: var(--muted-soft);
  cursor: pointer;
  transition: color var(--duration-fast);
  border-radius: var(--radius-sm);
  width: 100%;

  &:hover {
    color: var(--body-strong);
    background: var(--surface-soft);
  }
}

.language-switch {
  width: 100%;

  :deep(.el-radio-button) {
    flex: 1;
  }

  :deep(.el-radio-button__inner) {
    width: 100%;
    padding-inline: 8px;
  }
}

.language-toggle {
  width: 40px;
  height: 32px;
  border: 1px solid var(--hairline);
  border-radius: var(--radius-sm);
  background: var(--surface-card);
  color: var(--body-strong);
  font-size: 11px;
  font-weight: 800;
  cursor: pointer;
}

// Dropdown styles override
.sidebar-user-menu {
  width: 180px;
}

.menu-user-title {
  font-weight: 700;
  color: var(--on-dark) !important;
}

.logout-item {
  color: var(--accent-rose) !important;
  .el-icon {
    margin-right: 6px;
  }
}
</style>
