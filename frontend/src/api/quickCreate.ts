import { request } from './http'

export type QuickCreateMode = 'image' | 'video'
export type QuickCreateStatus = 'queued' | 'running' | 'success' | 'failed'

export interface QuickCreateAssetRef {
  kind: 'asset' | 'upload'
  asset_id: number
  asset_image_id?: number
  reference_alias?: string
  name: string
  type: string
  media_type?: 'image' | 'video'
  image_url?: string
  video_url?: string
  duration_seconds?: number
}

export interface QuickCreateOptions {
  aspect_ratio?: string
  resolution?: string
  quality?: 'low' | 'medium' | 'high' | string
  duration?: number
  count?: number
  generate_audio?: boolean
}

export interface QuickCreateMessage {
  id: number
  mode: QuickCreateMode
  prompt: string
  model_config_id: number
  parent_message_id?: number | null
  parent_result_index?: number | null
  edit_instruction?: string
  asset_refs: QuickCreateAssetRef[]
  options: QuickCreateOptions
  status: QuickCreateStatus
  result_urls: string[]
  error_message: string
  create_time?: string | null
  finished_at?: string | null
}

export interface QuickCreateSendPayload {
  mode: QuickCreateMode
  prompt: string
  model_config_id?: number
  asset_refs?: Array<{
    asset_id?: number
    asset_image_id?: number
    url?: string
    name?: string
    media_type?: 'image' | 'video'
    duration_seconds?: number
    reference_alias?: string
  }>
  options?: QuickCreateOptions
}

export interface QuickCreateEditPayload {
  source_message_id: number
  result_index: number
  instruction?: string
}

const BASE = '/api/quick-create'

export interface QuickCreateListParams {
  limit?: number
  before_id?: number
}

export interface QuickCreateListResult {
  messages: QuickCreateMessage[]
  has_more: boolean
  oldest_id: number
  limit: number
}

export function listQuickCreateMessages(params: QuickCreateListParams = {}): Promise<QuickCreateListResult> {
  return request<QuickCreateListResult>({
    method: 'POST',
    url: `${BASE}/messages/list`,
    data: {
      limit: params.limit ?? 20,
      ...(params.before_id && params.before_id > 0 ? { before_id: params.before_id } : {}),
    },
  })
}

export function sendQuickCreateMessage(payload: QuickCreateSendPayload): Promise<{ message: QuickCreateMessage }> {
  return request<{ message: QuickCreateMessage }>({ method: 'POST', url: `${BASE}/messages/send`, data: payload })
}

export function editQuickCreateMessage(payload: QuickCreateEditPayload): Promise<{ message: QuickCreateMessage }> {
  return request<{ message: QuickCreateMessage }>({ method: 'POST', url: `${BASE}/messages/edit`, data: payload })
}

export function pollQuickCreateMessages(ids: number[]): Promise<{ messages: QuickCreateMessage[] }> {
  return request<{ messages: QuickCreateMessage[] }>({
    method: 'POST',
    url: `${BASE}/messages/status`,
    data: { ids },
    silent: true,
    timeout: 60_000,
  })
}
