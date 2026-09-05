import { request } from './http'

const BASE = '/api/admin/production'

export type AdminWorkflowRunStatus = 'queued' | 'running' | 'success' | 'failed' | 'cancelled'

export interface AdminLatestRun {
  id: number
  status: AdminWorkflowRunStatus | ''
  progress: number
  current_node_label: string
  error_message: string
  create_time?: string | null
}

export interface AdminSeriesRow {
  id: number
  user_id: number
  owner_name: string
  owner_username: string
  title: string
  description: string
  visual_style: string
  visual_style_variant?: string
  region: string
  series_workflow_id?: number | null
  episode_count: number
  done_episode_count: number
  production_episode_count: number
  latest_run: AdminLatestRun | null
  create_time?: string | null
  update_time?: string | null
}

export interface AdminRunRow {
  id: number
  user_id: number
  owner_name: string
  owner_username: string
  series_id: number
  series_title: string
  workflow_id: number
  episode_workflow_id?: number | null
  status: AdminWorkflowRunStatus
  target_episode_count?: number | null
  progress: number
  current_node_label: string
  error_message: string
  started_at?: string | null
  finished_at?: string | null
  create_time?: string | null
  update_time?: string | null
}

export interface AdminProductionPage<T> {
  list: T[]
  total: number
  page: number
  limit: number
}

export interface AdminProductionOverview {
  metrics: {
    users: number
    series: number
    episodes: number
    runs: number
    queued_runs: number
    running_runs: number
    failed_runs: number
    today_runs: number
  }
  run_status_counts: Record<AdminWorkflowRunStatus, number>
  recent_series: AdminSeriesRow[]
  recent_runs: AdminRunRow[]
}

export function getAdminProductionOverview(): Promise<AdminProductionOverview> {
  return request<AdminProductionOverview>({ method: 'POST', url: `${BASE}/overview` })
}

export function listAdminSeries(query: {
  page?: number
  limit?: number
  user_id?: number
  keyword?: string
} = {}): Promise<AdminProductionPage<AdminSeriesRow>> {
  return request<AdminProductionPage<AdminSeriesRow>>({ method: 'POST', url: `${BASE}/series`, data: query })
}

export function listAdminRuns(query: {
  page?: number
  limit?: number
  user_id?: number
  status?: AdminWorkflowRunStatus | ''
  keyword?: string
} = {}): Promise<AdminProductionPage<AdminRunRow>> {
  return request<AdminProductionPage<AdminRunRow>>({ method: 'POST', url: `${BASE}/runs`, data: query })
}
