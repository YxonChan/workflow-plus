import { request } from './http'

const BASE = '/api/credits'

export interface CreditLedgerItem {
  id: number
  entry_type: string
  amount: number
  amount_abs: number
  balance_after: number
  modality: string
  model_id: string
  ref_type: string
  ref_id: number
  description: string
  create_time: string | null
}

export interface CreditLedgerSummary {
  consume_count: number
  credits_spent: number
  credits_text: number
  credits_image: number
  credits_video: number
  credit_unit_cny: number
  spent_cny: number
}

export interface CreditLedgerResult {
  balance: number
  summary: CreditLedgerSummary
  list: CreditLedgerItem[]
  pagination: {
    page: number
    page_size: number
    total: number
  }
}

export function listMyCreditLedger(query: {
  page?: number
  page_size?: number
  entry_type?: 'consume' | 'topup' | 'refund' | 'adjust' | ''
  modality?: 'text' | 'image' | 'video' | ''
  start_date?: string
  end_date?: string
} = {}): Promise<CreditLedgerResult> {
  return request<CreditLedgerResult>({
    method: 'POST',
    url: `${BASE}/ledger`,
    data: query,
  })
}
