import { request } from './http'
import type { Workflow, WorkflowPayload } from '@/types'

const BASE = '/api/workflows'

export function listWorkflows(): Promise<Workflow[]> {
  return request<Workflow[]>({ method: 'POST', url: `${BASE}/list` })
}

export function getWorkflow(id: number): Promise<Workflow> {
  return request<Workflow>({ method: 'POST', url: `${BASE}/detail`, data: { id } })
}

export function createWorkflow(payload: WorkflowPayload): Promise<Workflow> {
  return request<Workflow>({ method: 'POST', url: `${BASE}/create`, data: payload })
}

export function updateWorkflow(id: number, payload: WorkflowPayload): Promise<Workflow> {
  return request<Workflow>({ method: 'POST', url: `${BASE}/update`, data: { ...payload, id } })
}

export function deleteWorkflow(id: number): Promise<void> {
  return request<void>({ method: 'POST', url: `${BASE}/delete`, data: { id } })
}

export function duplicateWorkflow(id: number): Promise<Workflow> {
  return request<Workflow>({ method: 'POST', url: `${BASE}/duplicate`, data: { id } })
}
