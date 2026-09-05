import { request } from './http'

const BASE = '/api/admin/stats'

export interface CreditStatRow {
  key: string
  label: string
  consume_count: number
  credits_spent: number
  credits_text: number
  credits_image: number
  credits_video: number
}

export interface CreditStatSummary {
  consume_count: number
  credits_spent: number
  credits_text: number
  credits_image: number
  credits_video: number
  credit_unit_cny: number
  spent_cny: number
}

export function listCreditStats(query: {
  group_by?: 'day' | 'user' | 'model' | 'modality'
  user_id?: number
  modality?: 'text' | 'image' | 'video' | ''
  start_date?: string
  end_date?: string
} = {}): Promise<{ group_by: string; summary: CreditStatSummary; list: CreditStatRow[] }> {
  return request<{ group_by: string; summary: CreditStatSummary; list: CreditStatRow[] }>({
    method: 'POST',
    url: `${BASE}/credits`,
    data: query,
  })
}
