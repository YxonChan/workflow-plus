import { request } from './http'

export interface CreditPricingCatalog {
  enabled: boolean
  pricing_version: string
  updated_at: string
  credit_unit_cny: number
  usd_cny: number
  markup: number
  notes: string[]
  editable: boolean
  text: {
    default_model_id?: string
    models?: Record<string, {
      label?: string
      unit?: string
      input_credits_per_1k?: number
      output_credits_per_1k?: number
      min_credits?: number
      basis?: string
    }>
    fallback?: Record<string, unknown>
  }
  image: {
    default_model_id?: string
    models?: Record<string, {
      label?: string
      unit?: string
      by_quality?: Record<string, number>
      default_quality?: string
      basis?: string
    }>
    fallback?: Record<string, unknown>
  }
  video: {
    default_model_id?: string
    models?: Record<string, {
      label?: string
      unit?: string
      by_resolution?: Record<string, number>
      default_resolution?: string
      basis?: string
    }>
    fallback?: Record<string, unknown>
  }
}

export function listCreditPricing(): Promise<CreditPricingCatalog> {
  return request<CreditPricingCatalog>({
    method: 'POST',
    url: '/api/admin/credit-pricing/list',
    data: {},
  })
}
