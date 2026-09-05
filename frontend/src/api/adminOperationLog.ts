import request from '@/api/http'

const BASE = '/api/admin/operation-logs'

export interface AdminOperationLogOperator {
  id: number
  username: string
  display_name: string
}

export interface AdminOperationLogBrief {
  id: number
  operator_user_id: number
  operator: AdminOperationLogOperator | null
  action: string
  target_type: string
  target_id: number
  target_name_snapshot: string
  series_id: number | null
  episode_id: number | null
  workflow_run_id: number | null
  result: 'success' | 'failed'
  error_message: string
  create_time?: string | null
}

export interface AdminOperationLogDetail extends AdminOperationLogBrief {
  operator_name_snapshot: string
  ip: string
  user_agent: string
  request_id: string
  meta_json: Record<string, unknown> | string
  before_json: Record<string, unknown> | string
  after_json: Record<string, unknown> | string
}

export interface AdminOperationLogPage {
  list: AdminOperationLogBrief[]
  total: number
  page: number
  limit: number
}

export function listAdminOperationLogs(query: {
  page?: number
  limit?: number
  operator_user_id?: number
  action?: string
  target_type?: string
  target_id?: number
  result?: 'success' | 'failed' | ''
  keyword?: string
  start_date?: string
  end_date?: string
} = {}): Promise<AdminOperationLogPage> {
  return request<AdminOperationLogPage>({ method: 'POST', url: `${BASE}/list`, data: query })
}

export function getAdminOperationLog(id: number): Promise<AdminOperationLogDetail> {
  return request<AdminOperationLogDetail>({ method: 'POST', url: `${BASE}/detail`, data: { id } })
}
