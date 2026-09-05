import request from '@/api/http'

const BASE = '/api/admin/ai-request-logs'

export interface AiRequestLogBrief {
  id: number
  source: string
  workflow_run_id: number | null
  workflow_run_node_id: number | null
  model_config_id: number | null
  user_id: number
  user?: { id: number; username: string; display_name: string } | null
  llm_model: string
  finish_reason: string
  max_tokens: number
  prompt_tokens: number
  completion_tokens: number
  total_tokens: number
  http_status: number
  request_ok: number
  error_message: string
  duration_ms: number
  content_preview: string
  create_time?: string | null
}

export interface AiRequestLogDetail extends AiRequestLogBrief {
  endpoint: string
  context_json: Record<string, unknown> | string
  request_json: Record<string, unknown> | string
  usage_json: Record<string, unknown> | string
  response_body: string
  assistant_content: string
  curl_errno: number
  curl_error: string
}

export interface AiRequestLogPage {
  list: AiRequestLogBrief[]
  total: number
  page: number
  limit: number
}

export interface AiRequestLogQuery {
  page?: number
  limit?: number
  source?: string
  user_id?: number
  model?: string
  status?: 'success' | 'failed' | ''
  start_date?: string
  end_date?: string
  workflow_run_id?: number
}

export function listAiRequestLogs(query: AiRequestLogQuery = {}): Promise<AiRequestLogPage> {
  return request<AiRequestLogPage>({ method: 'POST', url: `${BASE}/list`, data: query })
}

export function getAiRequestLog(id: number): Promise<AiRequestLogDetail> {
  return request<AiRequestLogDetail>({ method: 'POST', url: `${BASE}/detail`, data: { id } })
}
