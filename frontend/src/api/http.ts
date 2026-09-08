import axios, { type AxiosRequestConfig } from 'axios'
import { ElMessage } from 'element-plus'
import type { ApiResponse } from '@/types'
import { messageFromStatus, normalizeDisplayMessage } from '@/utils/messageText'
import { normalizeMediaUrls } from '@/utils/mediaUrl'

const TOKEN_KEY = 'malulu.auth.token'
const REFRESH_TOKEN_KEY = 'malulu.auth.refreshToken'
const EXPIRES_AT_KEY = 'malulu.auth.expiresAt'
const REFRESH_EXPIRES_AT_KEY = 'malulu.auth.refreshExpiresAt'
const USER_KEY = 'malulu.auth.user'
const REFRESH_THRESHOLD_SECONDS = 24 * 60 * 60

let refreshPromise: Promise<string> | null = null

export class ApiError extends Error {
  constructor(
    public readonly code: number,
    message: string,
  ) {
    super(message)
    this.name = 'ApiError'
  }
}

/** 标记为 silent 的请求失败时不弹全局 ElMessage（用于后台轮询类请求）。 */
export type SilentableRequestConfig = AxiosRequestConfig & { silent?: boolean }

export type ApiRequestOptions = {
  silent?: boolean
  timeout?: number
}

function isSilent(config?: AxiosRequestConfig): boolean {
  return Boolean((config as SilentableRequestConfig | undefined)?.silent)
}

const http = axios.create({
  baseURL: '/',
  // 经代理/服务端忙时 30s 过短，易误报顶部「请求超时」；交互接口默认放宽。
  timeout: 60_000,
  headers: { 'Content-Type': 'application/json' },
})

http.interceptors.request.use(async (config) => {
  if (shouldRefreshBeforeRequest(config)) {
    await refreshAccessToken()
  }
  const token = localStorage.getItem(TOKEN_KEY) || ''
  if (token) {
    config.headers.Authorization = `Bearer ${token}`
  }
  const locale = 'zh-CN'
  config.headers['X-Malulu-Locale'] = locale
  config.headers['Accept-Language'] = locale
  config.headers['think-lang'] = locale
  // FormData 必须由浏览器自动带 multipart boundary；默认 application/json 会导致上传异常/空图。
  if (typeof FormData !== 'undefined' && config.data instanceof FormData) {
    const headers = config.headers as Record<string, unknown>
    delete headers['Content-Type']
    delete headers['content-type']
    if (typeof (config.headers as { delete?: (key: string) => void }).delete === 'function') {
      ;(config.headers as { delete: (key: string) => void }).delete('Content-Type')
      ;(config.headers as { delete: (key: string) => void }).delete('content-type')
    }
  }
  return config
})

// ── Response interceptor: unwrap {code, data, message} envelope ───────────────
http.interceptors.response.use(
  (response) => {
    const body = response.data as ApiResponse
    if (body.code === 0) {
      return normalizeMediaUrls(body.data) as never
    }
    const message = normalizeDisplayMessage(body.message, '请求失败，请稍后再试')
    if (!isSilent(response.config)) {
      ElMessage.error(message)
    }
    return Promise.reject(new ApiError(body.code, message))
  },
  async (error) => {
    const originalConfig = error.config as (AxiosRequestConfig & { __authRetried?: boolean }) | undefined
    if (
      error.response?.status === 401 &&
      originalConfig &&
      !originalConfig.__authRetried &&
      shouldRetryAfterUnauthorized(originalConfig)
    ) {
      try {
        originalConfig.__authRetried = true
        const token = await refreshAccessToken()
        originalConfig.headers = originalConfig.headers ?? {}
        originalConfig.headers.Authorization = `Bearer ${token}`
        return http.request(originalConfig)
      } catch {
        clearSession()
        if (window.location.pathname !== '/admin/login') {
          redirectToLogin()
        }
      }
    }

    const rawMessage =
      (error.response?.data as ApiResponse | undefined)?.message ??
      error.message ??
      messageFromStatus(error.response?.status) ??
      '网络错误，请重试'
    const message = normalizeDisplayMessage(
      rawMessage,
      messageFromStatus(error.response?.status, '网络错误，请重试') || '网络错误，请重试',
    )
    if (error.response?.status === 401) {
      clearSession()
      if (window.location.pathname !== '/admin/login') {
        redirectToLogin()
      }
    }
    if (!isSilent(originalConfig)) {
      ElMessage.error(message)
    }
    return Promise.reject(new ApiError(error.response?.status ?? 0, message))
  },
)

function redirectToLogin() {
  const { pathname, search, hash } = window.location
  const path = pathname.startsWith('/admin/') ? pathname.slice('/admin'.length) : '/dashboard'
  window.location.href = `/admin/login?redirect=${encodeURIComponent(path + search + hash)}`
}

/**
 * Generic request helper that returns the unwrapped `data` field.
 * The interceptor already unwraps it, so axios resolves with the data directly.
 */
export function request<T>(config: SilentableRequestConfig): Promise<T> {
  return http.request(config) as Promise<T>
}

function shouldRefreshBeforeRequest(config: AxiosRequestConfig) {
  if (isAuthEndpoint(config.url)) return false
  const token = localStorage.getItem(TOKEN_KEY) || ''
  const refreshToken = localStorage.getItem(REFRESH_TOKEN_KEY) || ''
  const expiresAt = Number(localStorage.getItem(EXPIRES_AT_KEY) || 0)
  if (!token || !refreshToken || !expiresAt) return false
  return expiresAt - nowSeconds() <= REFRESH_THRESHOLD_SECONDS
}

function shouldRetryAfterUnauthorized(config: AxiosRequestConfig) {
  if (isAuthEndpoint(config.url)) return false
  return (localStorage.getItem(REFRESH_TOKEN_KEY) || '') !== ''
}

function isAuthEndpoint(url?: string) {
  const value = String(url || '')
  return value.includes('/api/auth/login') || value.includes('/api/auth/refresh')
}

async function refreshAccessToken() {
  if (refreshPromise) return refreshPromise

  const refreshToken = localStorage.getItem(REFRESH_TOKEN_KEY) || ''
  if (!refreshToken) {
    throw new ApiError(401, '缺少 refresh token')
  }

  refreshPromise = axios
    .post<ApiResponse<{
      token: string
      access_token?: string
      expires_at: number
      refresh_token: string
      refresh_expires_at: number
      user: unknown
    }>>('/api/auth/refresh', { refresh_token: refreshToken }, {
      baseURL: '/',
      timeout: 30_000,
      headers: {
        'Content-Type': 'application/json',
        'X-Malulu-Locale': 'zh-CN',
        'Accept-Language': 'zh-CN',
        'think-lang': 'zh-CN',
      },
    })
    .then((response) => {
      const body = response.data
      if (body.code !== 0) {
        throw new ApiError(body.code, body.message || '刷新 token 失败')
      }
      const session = body.data
      const nextToken = session.token || session.access_token || ''
      if (!nextToken || !session.refresh_token) {
        throw new ApiError(401, '刷新 token 响应不完整')
      }
      localStorage.setItem(TOKEN_KEY, nextToken)
      localStorage.setItem(REFRESH_TOKEN_KEY, session.refresh_token)
      localStorage.setItem(EXPIRES_AT_KEY, String(session.expires_at || 0))
      localStorage.setItem(REFRESH_EXPIRES_AT_KEY, String(session.refresh_expires_at || 0))
      if (session.user) {
        localStorage.setItem(USER_KEY, JSON.stringify(session.user))
      }
      window.dispatchEvent(new CustomEvent('malulu:auth-refreshed'))
      return nextToken
    })
    .finally(() => {
      refreshPromise = null
    })

  return refreshPromise
}

function clearSession() {
  localStorage.removeItem(TOKEN_KEY)
  localStorage.removeItem(REFRESH_TOKEN_KEY)
  localStorage.removeItem(EXPIRES_AT_KEY)
  localStorage.removeItem(REFRESH_EXPIRES_AT_KEY)
  localStorage.removeItem(USER_KEY)
}

function nowSeconds() {
  return Math.floor(Date.now() / 1000)
}

export default http
