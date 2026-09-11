<script setup lang="ts">
import { ref } from 'vue'
import { ElMessage } from 'element-plus'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const { t } = useI18n()

/** public/brand/logo.png → served under Vite base (/admin/) */
const brandLogoUrl = `${import.meta.env.BASE_URL}brand/logo.png`

const username = ref('')
const password = ref('')
const loading  = ref(false)

async function handleLogin() {
  if (!username.value.trim() || !password.value) {
    ElMessage.warning(t('请输入账号和密码'))
    return
  }
  loading.value = true
  try {
    await auth.login(username.value.trim(), password.value)
    ElMessage.success(t('登录成功'))
    const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/dashboard'
    await router.replace(redirect)
  } finally {
    loading.value = false
  }
}
</script>

<template>
  <div class="login-page">
    <div class="login-grid">
      <!-- ── Left: editorial brand panel ──────────── -->
      <div class="login-brand">
        <div class="login-brand__inner">
          <div class="brand-lockup">
            <img class="brand-mark" :src="brandLogoUrl" :alt="t('智梦工厂')" width="96" height="96" draggable="false" />
            <div class="brand-name">
              <span class="brand-name__zh">{{ t('智梦工厂') }}</span>
              <span class="brand-name__en">Intelligent Dream Factory</span>
            </div>
          </div>

          <h2 class="brand-headline">
            {{ t('把复杂工作') }}<br />
            {{ t('交给 AI') }}<br />
            {{ t('自动推进。') }}
          </h2>

          <p class="brand-body">
            {{ t('从原始剧本到最终成片，一个工作台完成') }}<br />
            {{ t('剧本拆解、画面生成、配音合成全流程。') }}
          </p>

          <!-- Stat row -->
          <div class="brand-stats">
            <div class="brand-stat">
              <span class="brand-stat__val">4</span>
              <span class="brand-stat__label">{{ t('模型类型') }}</span>
            </div>
            <div class="brand-stat-sep" />
            <div class="brand-stat">
              <span class="brand-stat__val">1</span>
              <span class="brand-stat__label">{{ t('统一平台') }}</span>
            </div>
            <div class="brand-stat-sep" />
            <div class="brand-stat">
              <span class="brand-stat__val">∞</span>
              <span class="brand-stat__label">{{ t('工作流') }}</span>
            </div>
          </div>
        </div>
      </div>

      <!-- ── Right: login card ─────────────────────── -->
      <div class="login-card-wrap">
        <div class="login-card">
          <div class="login-card__header">
            <img class="login-card__logo" :src="brandLogoUrl" :alt="t('智梦工厂')" width="72" height="72" draggable="false" />
            <p class="login-card__brand">{{ t('智梦工厂') }}</p>
            <h2 class="login-card__title">{{ t('登录') }}</h2>
            <p class="login-card__sub">{{ t('请输入您的账号和密码继续') }}</p>
          </div>

          <el-form label-position="top" @submit.prevent="handleLogin">
            <el-form-item :label="t('账号')">
              <el-input
                v-model="username"
                :placeholder="t('请输入账号（演示：user1）')"
                autocomplete="username"
              />
            </el-form-item>
            <el-form-item :label="t('密码')">
              <el-input
                v-model="password"
                type="password"
                :placeholder="t('请输入密码')"
                show-password
                autocomplete="current-password"
              />
            </el-form-item>
            <el-button
              type="primary"
              style="width: 100%; margin-top: 8px; height: 44px; font-size: 14px; font-weight: 700;"
              :loading="loading"
              native-type="submit"
            >
              {{ t('登录') }}
            </el-button>
          </el-form>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped lang="scss">
.login-page {
  min-height: 100vh;
  background: radial-gradient(circle at 8% 8%, rgba(99, 102, 241, 0.16), transparent 32%), var(--canvas);
  display: flex;
  align-items: stretch;
}

.login-grid {
  display: grid;
  grid-template-columns: 7fr 5fr;
  width: 100%;
  max-width: 1280px;
  margin: 0 auto;
}

// ── Brand panel ────────────────────────────────────────────────────────────────
.login-brand {
  display: flex;
  align-items: center;
  padding: var(--space-section) var(--space-section) var(--space-section) var(--space-xxl);
  border-right: 1px solid rgba(99, 102, 241, 0.14);
}

.login-brand__inner {
  display: flex;
  flex-direction: column;
  gap: var(--space-xl);
  max-width: 520px;
}

.brand-lockup {
  display: flex;
  align-items: center;
  gap: var(--space-md);
  justify-content: flex-start;
}

.brand-name {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.brand-name__zh {
  font-family: var(--font-sans);
  font-size: 22px;
  font-weight: 700;
  letter-spacing: 0.04em;
  color: var(--on-dark);
  line-height: 1.2;
}

.brand-name__en {
  font-size: 12px;
  font-weight: 500;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  color: var(--muted);
  line-height: 1.3;
}

.brand-mark {
  width: 96px;
  height: 96px;
  flex-shrink: 0;
  object-fit: contain;
  object-position: center;
  display: block;
  border: 0;
  background: transparent;
  filter: drop-shadow(0 4px 16px rgba(0, 0, 0, 0.28));
}

.brand-headline {
  margin: 0;
  font-family: var(--font-sans);
  font-size: var(--text-display-lg-size);
  font-weight: var(--text-display-lg-weight);
  line-height: var(--text-display-lg-lh);
  letter-spacing: var(--text-display-lg-ls);
  color: var(--on-dark);
}

.brand-body {
  margin: 0;
  font-size: 16px;
  font-weight: 400;
  color: var(--muted);
  line-height: 1.6;
}

.brand-stats {
  display: flex;
  align-items: center;
  gap: var(--space-lg);
}

.brand-stat {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.brand-stat__val {
  font-family: var(--font-sans);
  font-size: var(--text-stat-size);
  font-weight: var(--text-stat-weight);
  line-height: var(--text-stat-lh);
  letter-spacing: var(--text-stat-ls);
  color: var(--primary);
}

.brand-stat__label {
  font-size: 12px;
  font-weight: 600;
  color: var(--muted);
}

.brand-stat-sep {
  width: 1px;
  height: 48px;
  background: var(--hairline);
}

// ── Login card ─────────────────────────────────────────────────────────────────
.login-card-wrap {
  display: flex;
  align-items: center;
  justify-content: center;
  padding: var(--space-section) var(--space-xxl);
}

.login-card {
  width: 100%;
  max-width: 380px;
  background: var(--surface-card);
  border: 1px solid rgba(99, 102, 241, 0.14);
  border-radius: var(--radius-lg);
  padding: 40px;
  box-shadow: 0 24px 56px rgba(30, 41, 59, 0.09);
}

.login-card__header {
  margin-bottom: var(--space-xl);
}

.login-card__logo {
  width: 72px;
  height: 72px;
  object-fit: contain;
  object-position: center;
  background: transparent;
  border: 0;
  display: block;
  margin: 0 auto var(--space-sm);
  filter: drop-shadow(0 2px 10px rgba(0, 0, 0, 0.2));
}

.login-card__brand {
  margin: 0 0 var(--space-md);
  text-align: center;
  font-size: 15px;
  font-weight: 700;
  letter-spacing: 0.06em;
  color: var(--on-dark);
}

.login-card__title {
  margin: 0 0 var(--space-xs);
  font-family: var(--font-sans);
  font-size: var(--text-display-sm-size);
  font-weight: var(--text-display-sm-weight);
  line-height: var(--text-display-sm-lh);
  letter-spacing: var(--text-display-sm-ls);
  color: var(--on-dark);
}

.login-card__sub {
  margin: 0;
  font-size: 13px;
  color: var(--muted);
}

// ── Responsive ─────────────────────────────────────────────────────────────────
@media (max-width: 900px) {
  .login-grid {
    grid-template-columns: 1fr;
  }

  .login-brand {
    display: none;
  }

  .login-card-wrap {
    padding: var(--space-xl);
    align-items: flex-start;
    padding-top: var(--space-xxl);
  }
}
</style>
