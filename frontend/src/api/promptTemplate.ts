import { request } from './http'
import type { PromptTemplate } from '@/types'

const BASE = '/api/prompt-templates'

export type PromptTemplatePayload = Pick<
  PromptTemplate,
  'title' | 'scope' | 'node_kind' | 'node_label' | 'description' | 'prompt' | 'tags'
>

export function listPromptTemplates(): Promise<PromptTemplate[]> {
  return request<PromptTemplate[]>({ method: 'POST', url: `${BASE}/list` })
}

export function createPromptTemplate(payload: PromptTemplatePayload): Promise<PromptTemplate> {
  return request<PromptTemplate>({ method: 'POST', url: `${BASE}/create`, data: payload })
}

export function updatePromptTemplate(id: number, payload: PromptTemplatePayload): Promise<PromptTemplate> {
  return request<PromptTemplate>({ method: 'POST', url: `${BASE}/update`, data: { id, ...payload } })
}

export function deletePromptTemplate(id: number): Promise<void> {
  return request<void>({ method: 'POST', url: `${BASE}/delete`, data: { id } })
}
