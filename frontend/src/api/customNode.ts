import { request } from './http'
import type { WorkflowScope } from '@/types'

export interface CustomNodeBlueprint {
  id: number
  label: string
  icon: string
  kind: 'text' | 'image' | 'video' | 'voice' | 'input' | 'output'
  desc: string
  scope?: WorkflowScope
  /** 1 = 系统固定节点，不可删改名 */
  is_fixed?: number
}

const BASE = '/api/custom-nodes'

export function listCustomNodes(): Promise<CustomNodeBlueprint[]> {
  return request<CustomNodeBlueprint[]>({ method: 'POST', url: `${BASE}/list` })
}

export function createCustomNode(payload: Partial<CustomNodeBlueprint>): Promise<CustomNodeBlueprint> {
  return request<CustomNodeBlueprint>({ method: 'POST', url: `${BASE}/create`, data: payload })
}

export function deleteCustomNode(id: number): Promise<void> {
  return request<void>({ method: 'POST', url: `${BASE}/delete`, data: { id } })
}
