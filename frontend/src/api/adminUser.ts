import { request } from './http'
import type { AdminUser } from '@/types'

const BASE = '/api/admin/users'

export function listAdminUsers(query: { keyword?: string } = {}): Promise<{ list: AdminUser[] }> {
  return request<{ list: AdminUser[] }>({ method: 'POST', url: `${BASE}/list`, data: query })
}

export function createAdminUser(payload: {
  username: string
  display_name: string
  password: string
  role: 'admin' | 'user'
  status: number
}): Promise<AdminUser> {
  return request<AdminUser>({ method: 'POST', url: `${BASE}/create`, data: payload })
}

export function updateAdminUser(id: number, payload: Partial<Pick<AdminUser, 'display_name' | 'role' | 'status'>>): Promise<AdminUser> {
  return request<AdminUser>({ method: 'POST', url: `${BASE}/update`, data: { ...payload, id } })
}

export function resetAdminUserPassword(id: number, password: string): Promise<void> {
  return request<void>({ method: 'POST', url: `${BASE}/reset-password`, data: { id, password } })
}

export function topUpAdminUserCredit(id: number, amount: number, note = ''): Promise<{
  user: AdminUser
  ledger_id: number
  balance: number
}> {
  return request<{ user: AdminUser; ledger_id: number; balance: number }>({
    method: 'POST',
    url: `${BASE}/topup-credit`,
    data: { id, amount, note },
  })
}

export function listAdminUserCreditLedger(id: number, limit = 50): Promise<{
  user: AdminUser
  list: Array<{
    id: number
    entry_type: string
    amount: number
    balance_after: number
    modality: string
    model_id: string
    description: string
    create_time: string | null
  }>
}> {
  return request({
    method: 'POST',
    url: `${BASE}/credit-ledger`,
    data: { id, limit },
  })
}
