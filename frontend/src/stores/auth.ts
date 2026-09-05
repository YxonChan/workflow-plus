import { computed, ref } from 'vue'
import { defineStore } from 'pinia'
import { getMe, login as loginApi, refreshToken as refreshTokenApi, type AuthUser } from '@/api/auth'

const TOKEN_KEY = 'malulu.auth.token'
const REFRESH_TOKEN_KEY = 'malulu.auth.refreshToken'
const EXPIRES_AT_KEY = 'malulu.auth.expiresAt'
const REFRESH_EXPIRES_AT_KEY = 'malulu.auth.refreshExpiresAt'
const USER_KEY = 'malulu.auth.user'

/** 积分/资料刷新最短间隔，避免路由切换连打 /auth/me */
const PROFILE_REFRESH_MIN_MS = 8_000

function readUser(): AuthUser | null {
  const raw = localStorage.getItem(USER_KEY)
  if (!raw) return null
  try {
    return JSON.parse(raw) as AuthUser
  } catch {
    return null
  }
}

export const useAuthStore = defineStore('auth', () => {
  const token = ref(localStorage.getItem(TOKEN_KEY) || '')
  const refreshToken = ref(localStorage.getItem(REFRESH_TOKEN_KEY) || '')
  const expiresAt = ref(Number(localStorage.getItem(EXPIRES_AT_KEY) || 0))
  const refreshExpiresAt = ref(Number(localStorage.getItem(REFRESH_EXPIRES_AT_KEY) || 0))
  const user = ref<AuthUser | null>(readUser())
  const isAuthenticated = computed(() => token.value !== '')
  const tokenExpiresAtText = computed(() => formatUnixTime(expiresAt.value))

  let lastProfileRefreshAt = 0
  let profileRefreshInFlight: Promise<AuthUser | null> | null = null

  if (typeof window !== 'undefined') {
    window.addEventListener('malulu:auth-refreshed', syncFromStorage)
  }

  function setSession(session: {
    token: string
    refresh_token?: string
    expires_at?: number
    refresh_expires_at?: number
    user: AuthUser
  }) {
    const nextToken = session.token
    token.value = nextToken
    user.value = session.user
    if (session.refresh_token !== undefined) {
      refreshToken.value = session.refresh_token
      localStorage.setItem(REFRESH_TOKEN_KEY, session.refresh_token)
    }
    if (session.expires_at !== undefined) {
      expiresAt.value = Number(session.expires_at || 0)
      localStorage.setItem(EXPIRES_AT_KEY, String(expiresAt.value))
    }
    if (session.refresh_expires_at !== undefined) {
      refreshExpiresAt.value = Number(session.refresh_expires_at || 0)
      localStorage.setItem(REFRESH_EXPIRES_AT_KEY, String(refreshExpiresAt.value))
    }
    localStorage.setItem(TOKEN_KEY, nextToken)
    localStorage.setItem(USER_KEY, JSON.stringify(session.user))
    lastProfileRefreshAt = Date.now()
  }

  async function login(username: string, password: string) {
    const result = await loginApi(username, password)
    setSession(result)
    return result.user
  }

  async function refreshSession() {
    if (!refreshToken.value) throw new Error('缺少 refresh token')
    const result = await refreshTokenApi(refreshToken.value)
    setSession(result)
    return result
  }

  async function hydrate(silent = false) {
    if (!token.value) return null
    const result = await getMe({ silent })
    user.value = result.user
    localStorage.setItem(USER_KEY, JSON.stringify(result.user))
    lastProfileRefreshAt = Date.now()
    return result.user
  }

  /**
   * 从服务端同步用户资料（含积分余额）。
   * @param force 为 true 时忽略节流（生成成功后应 force）
   * @param silent 为 true 时失败不弹全局超时提示（后台轮询用）
   */
  async function refreshProfile(force = false, silent = false): Promise<AuthUser | null> {
    if (!token.value) return null

    const now = Date.now()
    if (!force && now - lastProfileRefreshAt < PROFILE_REFRESH_MIN_MS) {
      return user.value
    }
    if (profileRefreshInFlight) {
      return profileRefreshInFlight
    }

    profileRefreshInFlight = hydrate(silent)
      .catch(() => user.value)
      .finally(() => {
        profileRefreshInFlight = null
      })

    return profileRefreshInFlight
  }

  function logout() {
    token.value = ''
    refreshToken.value = ''
    expiresAt.value = 0
    refreshExpiresAt.value = 0
    user.value = null
    lastProfileRefreshAt = 0
    profileRefreshInFlight = null
    localStorage.removeItem(TOKEN_KEY)
    localStorage.removeItem(REFRESH_TOKEN_KEY)
    localStorage.removeItem(EXPIRES_AT_KEY)
    localStorage.removeItem(REFRESH_EXPIRES_AT_KEY)
    localStorage.removeItem(USER_KEY)
  }

  function syncFromStorage() {
    token.value = localStorage.getItem(TOKEN_KEY) || ''
    refreshToken.value = localStorage.getItem(REFRESH_TOKEN_KEY) || ''
    expiresAt.value = Number(localStorage.getItem(EXPIRES_AT_KEY) || 0)
    refreshExpiresAt.value = Number(localStorage.getItem(REFRESH_EXPIRES_AT_KEY) || 0)
    user.value = readUser()
  }

  return {
    token,
    refreshToken,
    expiresAt,
    refreshExpiresAt,
    tokenExpiresAtText,
    user,
    isAuthenticated,
    login,
    refreshSession,
    hydrate,
    refreshProfile,
    logout,
    syncFromStorage,
  }
})

function formatUnixTime(value: number) {
  if (!value) return '未知'
  return new Date(value * 1000).toLocaleString()
}
