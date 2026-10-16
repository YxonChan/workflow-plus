import { request } from './http'
import type { VisualStyle } from '@/utils/visualStyle'

export interface PromptSettings {
  mode: 'image' | 'video'
  text_model_id: number
  vision_model_id?: number
  target_model_id: number
  visual_style: VisualStyle
  style_preset_id: number
  aspect_ratio: string
  duration: number
  duration_confirmed?: boolean
  asset_ids: number[]
  reference_ids?: number[]
}

export interface PromptReference { id: number; kind: 'upload' | 'asset'; name: string; url: string; asset_id?: number; asset_image_id?: number; type?: string }

export interface PromptQuestion { text: string; options: string[] }
export interface PromptMessage {
  role: 'user' | 'assistant'
  text: string
  request_id?: string
  questions?: PromptQuestion[]
  action?: string
  references?: PromptReference[]
}
export interface PromptShot { title: string; duration: number; prompt_body: string; full_prompt?: string }
export interface PromptContent {
  title: string
  prompt_body: string
  full_prompt: string
  style_block: string
  shots: PromptShot[]
  settings: PromptSettings
  brief: Record<string, string>
  skills: Array<{ id: string; name: string; version: string }>
  match_reason: string
  assumptions: string[]
  asset_requirements: string[]
  asset_ids: number[]
  references?: PromptReference[]
  candidate_skill_id?: string
  reference_usage?: Array<{ reference_index: number; instruction: string }>
  checks: { status: 'checked' | 'needs_review' | 'edited'; issues: string[]; notes: string[] }
}
export interface PromptResult { id: number; version: number; content: PromptContent; create_time: string }
export interface PromptSessionBrief { id: number; title: string; mode: 'image' | 'video'; status: string; update_time: string }
export interface PromptSession {
  id: number
  title: string
  status: string
  settings: PromptSettings
  references?: PromptReference[]
  brief: Record<string, string>
  messages: PromptMessage[]
  results: PromptResult[]
  request_id: string
  error_message: string
  update_time: string
}
export interface PromptSkill {
  id: string
  name: string
  title: string
  description: string
  modes: Array<'image' | 'video'>
  status: 'draft' | 'ready' | 'validating'
  is_builtin: boolean
  version: string
  license: string
  source_url: string
  instructions?: string
  markdown?: string
  evaluations: { test_cases?: string[]; evidence?: string[]; issues?: string[]; passed?: boolean; evaluated_at?: string }
}

const BASE = '/api/prompt-assistant'
export function uploadPromptReference(file: File) {
  const data = new FormData()
  data.append('file', file)
  return request<PromptReference>({ method: 'POST', url: `${BASE}/upload`, data, silent: true })
}
export const listPromptSessions = () => request<PromptSessionBrief[]>({ method: 'POST', url: `${BASE}/list` })
export const createPromptSession = (settings: PromptSettings) => request<PromptSession>({ method: 'POST', url: `${BASE}/create`, data: { settings } })
export const getPromptSession = (id: number, silent = false) => request<PromptSession>({ method: 'POST', url: `${BASE}/detail`, data: { id }, silent })
export const deletePromptSession = (id: number) => request<void>({ method: 'POST', url: `${BASE}/delete`, data: { id } })
export const sendPromptMessage = (id: number, message: string, request_id: string, settings: PromptSettings) =>
  request<PromptSession>({ method: 'POST', url: `${BASE}/chat`, data: { id, message, request_id, settings }, timeout: 620_000, silent: true })
export const savePromptResult = (id: number, result_id: number, patch: { prompt_body: string; shots: PromptShot[] }) =>
  request<PromptSession>({ method: 'POST', url: `${BASE}/save-result`, data: { id, result_id, patch } })
export const listPromptSkills = () => request<PromptSkill[]>({ method: 'POST', url: `${BASE}/skills` })
export const getPromptSkill = (id: string) => request<PromptSkill>({ method: 'POST', url: `${BASE}/skill-detail`, data: { id } })
export const validatePromptSkill = (id: string, text_model_id: number) =>
  request<PromptSkill>({ method: 'POST', url: `${BASE}/validate-skill`, data: { id, text_model_id }, timeout: 620_000 })
export const deletePromptSkill = (id: string) => request<void>({ method: 'POST', url: `${BASE}/delete-skill`, data: { id } })
