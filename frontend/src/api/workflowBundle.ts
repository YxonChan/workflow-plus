import { request } from './http'
import type { WorkflowBundle } from '@/types'

const BASE = '/api/workflow-bundles'

export function listBundles(): Promise<WorkflowBundle[]> {
  return request<WorkflowBundle[]>({ method: 'POST', url: `${BASE}/list` })
}

export interface CreateBundlePayload {
  name: string
  description?: string
  from_bundle_id?: number
  include_series?: boolean
}

export function createBundle(payload: CreateBundlePayload): Promise<WorkflowBundle> {
  return request<WorkflowBundle>({ method: 'POST', url: `${BASE}/create`, data: payload })
}

export function updateBundle(id: number, payload: { name: string; description: string }): Promise<WorkflowBundle> {
  return request<WorkflowBundle>({ method: 'POST', url: `${BASE}/update`, data: { id, ...payload } })
}

export function deleteBundle(id: number): Promise<void> {
  return request<void>({ method: 'POST', url: `${BASE}/delete`, data: { id } })
}

export function duplicateBundle(id: number): Promise<WorkflowBundle> {
  return request<WorkflowBundle>({ method: 'POST', url: `${BASE}/duplicate`, data: { id } })
}
