import request, { type ApiRequestOptions } from '@/api/http'

export interface AuthUser {
  id: number
  username: string
  display_name: string
  role: 'admin' | 'user'
  preferred_locale: 'zh-CN'
  credit_balance?: number
}

export interface LoginResponse {
  token: string
  access_token: string
  expires_at: number
  expires_in: number
  refresh_token: string
  refresh_expires_at: number
  refresh_expires_in: number
  user: AuthUser
}

export function login(username: string, password: string): Promise<LoginResponse> {
  return request<LoginResponse>({
    method: 'POST',
    url: '/api/auth/login',
    data: { username, password },
  })
}

export function getMe(options: ApiRequestOptions = {}): Promise<{ user: AuthUser }> {
  return request<{ user: AuthUser }>({
    method: 'POST',
    url: '/api/auth/me',
    data: {},
    timeout: options.timeout ?? 60_000,
    silent: options.silent,
  })
}

export function refreshToken(refresh_token: string): Promise<LoginResponse> {
  return request<LoginResponse>({
    method: 'POST',
    url: '/api/auth/refresh',
    data: { refresh_token },
  })
}
