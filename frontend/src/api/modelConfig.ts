import { request } from './http'
import type { ModelConfigGroup, ModelConfig, ModelConfigPayload, ModelConfigProbeResult } from '@/types'

const BASE = '/api/model-configs'
const ADMIN_BASE = '/api/admin/model-configs'

export function listGrouped(): Promise<ModelConfigGroup[]> {
  return request<ModelConfigGroup[]>({ method: 'POST', url: `${BASE}/list` })
}

export function createModelConfig(payload: ModelConfigPayload): Promise<ModelConfig> {
  return request<ModelConfig>({ method: 'POST', url: `${ADMIN_BASE}/create`, data: payload })
}

export function updateModelConfig(id: number, payload: ModelConfigPayload): Promise<ModelConfig> {
  return request<ModelConfig>({ method: 'POST', url: `${ADMIN_BASE}/update`, data: { ...payload, id } })
}

export function deleteModelConfig(id: number): Promise<void> {
  return request<void>({ method: 'POST', url: `${ADMIN_BASE}/delete`, data: { id } })
}

export function listAdminGrouped(query: { scope?: 'global' | 'user' | ''; user_id?: number } = {}): Promise<ModelConfigGroup[]> {
  return request<ModelConfigGroup[]>({ method: 'POST', url: `${ADMIN_BASE}/list`, data: query })
}

export function probeModelConfig(id: number): Promise<ModelConfigProbeResult> {
  return request<ModelConfigProbeResult>({ method: 'POST', url: `${ADMIN_BASE}/probe`, data: { id } })
}
