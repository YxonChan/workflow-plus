import request, { type ApiRequestOptions } from '@/api/http'

const BASE = '/api/workflow-runs'

export interface WorkflowRunNode {
  id: number
  workflow_node_id: string
  label: string
  kind: string
  sort: number
  status: 'queued' | 'running' | 'success' | 'failed' | 'skipped'
  error_message: string
  duration_ms: number
  started_at?: string | null
  finished_at?: string | null
  ai_request_log_id?: number | null
  request_payload_json?: {
    model_config_id?: number
    llm_model?: string
    max_tokens?: number
    messages?: Array<Record<string, unknown>>
  } | null
  ai_meta_json?: Record<string, unknown> | null
  raw_output_preview?: string
}

export interface WorkflowRunDetail {
  id: number
  series_id: number
  workflow_id: number
  episode_workflow_id?: number | null
  status: 'queued' | 'running' | 'success' | 'failed' | 'cancelled'
  progress: number
  current_node_label: string
  error_message: string
  result_json?: {
    episodes_written?: number
    assets_written?: number
  }
  nodes: WorkflowRunNode[]
}

export interface WorkflowRunBrief {
  id: number
  series_id: number
  series_title: string
  workflow_id: number
  status: 'queued' | 'running' | 'success' | 'failed' | 'cancelled'
  progress: number
  current_node_label: string
  error_message: string
  started_at?: string | null
  finished_at?: string | null
  create_time?: string | null
}

export function listWorkflowRuns(limit = 10): Promise<WorkflowRunBrief[]> {
  return request<WorkflowRunBrief[]>({ method: 'POST', url: `${BASE}/list`, data: { limit } })
}

export function getWorkflowRun(id: number, options: ApiRequestOptions = {}): Promise<WorkflowRunDetail> {
  return request<WorkflowRunDetail>({
    method: 'POST',
    url: `${BASE}/detail`,
    data: { id },
    timeout: options.timeout ?? 60_000,
    silent: options.silent,
  })
}

export function resumeWorkflowRun(id: number): Promise<WorkflowRunDetail> {
  return request<WorkflowRunDetail>({ method: 'POST', url: `${BASE}/resume`, data: { id } })
}

export function cancelWorkflowRun(id: number): Promise<WorkflowRunDetail> {
  return request<WorkflowRunDetail>({ method: 'POST', url: `${BASE}/cancel`, data: { id } })
}

export function workflowRunStreamUrl(id: number): string {
  const token = localStorage.getItem('malulu.auth.token') || ''
  const query = new URLSearchParams({ id: String(id), token })
  return `${BASE}/stream?${query.toString()}`
}
