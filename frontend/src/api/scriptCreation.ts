import { request } from './http'
import type { ScriptAiConfig, ScriptProject, ScriptProjectSummary, ScriptTextModel } from '@/types'

const BASE = '/api/script-creation'

export interface ScriptProjectPayload {
  title: string
  genre?: string
  output_language?: 'zh-CN'
  region_style?: 'mainland' | 'overseas'
  synopsis?: string
  requirements?: string
}

export interface ScriptPdfResult {
  filename: string
  mime: string
  base64: string
}

export interface ScriptExternalEndpoints {
  list: string
  detail: string
  score: string
}

export interface ScriptExternalAccessStatus {
  configured: boolean
  active: boolean
  client_name: string
  token_prefix: string
  last_used_at?: string | null
  update_time?: string | null
  endpoints: ScriptExternalEndpoints
}

export interface ScriptExternalAccessToken {
  client_id: number
  client_name: string
  token: string
  token_prefix: string
  endpoints: ScriptExternalEndpoints
}

export function listScriptConfigs(): Promise<{ configs: ScriptAiConfig[] }> {
  return request({ method: 'POST', url: `${BASE}/configs` })
}

export function saveScriptConfigs(configs: ScriptAiConfig[]): Promise<{ configs: ScriptAiConfig[] }> {
  return request({ method: 'POST', url: `${BASE}/configs/save`, data: { configs } })
}

export function listScriptTextModels(): Promise<{ models: ScriptTextModel[] }> {
  return request({ method: 'POST', url: `${BASE}/models` })
}

export function listScriptProjects(): Promise<{ projects: ScriptProjectSummary[] }> {
  return request({ method: 'POST', url: `${BASE}/projects/list` })
}

export function getScriptProject(
  id: number,
  options: { silent?: boolean; timeout?: number } = {},
): Promise<{ project: ScriptProject }> {
  return request({
    method: 'POST',
    url: `${BASE}/projects/detail`,
    data: { id },
    timeout: options.timeout ?? 60_000,
    silent: options.silent,
  })
}

export function exportScriptProjectPdf(id: number): Promise<ScriptPdfResult> {
  return request({ method: 'POST', url: `${BASE}/projects/pdf`, data: { id }, timeout: 600_000 })
}

export function exportScriptProjectTxt(id: number): Promise<ScriptPdfResult> {
  return request({ method: 'POST', url: `${BASE}/projects/txt`, data: { id }, timeout: 120_000 })
}

export function getScriptExternalAccess(): Promise<{ access: ScriptExternalAccessStatus }> {
  return request({ method: 'POST', url: `${BASE}/external-access` })
}

export function rotateScriptExternalAccess(name = 'Hermes Agent'): Promise<{ access: ScriptExternalAccessToken }> {
  return request({ method: 'POST', url: `${BASE}/external-access/rotate`, data: { name } })
}

export function revokeScriptExternalAccess(): Promise<{ access: ScriptExternalAccessStatus }> {
  return request({ method: 'POST', url: `${BASE}/external-access/revoke` })
}

export function createScriptProject(payload: ScriptProjectPayload): Promise<{ project: ScriptProject }> {
  return request({ method: 'POST', url: `${BASE}/projects/create`, data: payload })
}

export function updateScriptProject(id: number, payload: ScriptProjectPayload): Promise<{ project: ScriptProject }> {
  return request({ method: 'POST', url: `${BASE}/projects/update`, data: { id, ...payload } })
}

function projectAction(action: 'start' | 'pause' | 'resume' | 'retry' | 'cancel', id: number): Promise<{ project: ScriptProject }> {
  return request({ method: 'POST', url: `${BASE}/projects/${action}`, data: { id } })
}

export const startScriptProject = (id: number) => projectAction('start', id)
export const pauseScriptProject = (id: number) => projectAction('pause', id)
export const resumeScriptProject = (id: number) => projectAction('resume', id)
export const retryScriptProject = (id: number) => projectAction('retry', id)
export const cancelScriptProject = (id: number) => projectAction('cancel', id)
