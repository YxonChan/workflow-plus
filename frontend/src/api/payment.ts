import { request } from '@/api/http'

export interface PaymentPackage {
  id: string
  name: string
  amount_cny: number
  credit_amount: number
}

export interface PaymentOrder {
  id: number
  order_no: string
  package_id: string
  amount_cny: number
  credit_amount: number
  status: 'pending' | 'paid' | 'closed' | string
  code_url: string
  expires_at: string
  sync_warning?: string
  paid_at?: string | null
  create_time?: string | null
}

export function listPaymentPackages(): Promise<{ enabled: boolean; packages: PaymentPackage[] }> {
  return request({ method: 'GET', url: '/api/payments/packages' })
}

export function createPaymentOrder(packageId: string, requestId: string): Promise<PaymentOrder> {
  return request({ method: 'POST', url: '/api/payments/create', data: { package_id: packageId, request_id: requestId } })
}

export function listPaymentOrders(): Promise<{ orders: PaymentOrder[] }> {
  return request({ method: 'POST', url: '/api/payments/orders', silent: true })
}

export function getPaymentOrderStatus(id: number): Promise<PaymentOrder> {
  return request({ method: 'POST', url: '/api/payments/status', data: { id }, silent: true })
}
