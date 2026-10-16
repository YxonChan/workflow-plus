import { request } from './http'
import type { StylePreset } from '@/types'

const BASE = '/api/style-presets'

export type StylePresetPayload = Pick<
  StylePreset,
  'name' | 'summary' | 'visual_styles' | 'parameters' | 'technique_prompt' | 'prompt' | 'lighting_prompt' | 'prompt_mode' | 'prompt_extra'
>

export function listStylePresets(): Promise<StylePreset[]> {
  return request<StylePreset[]>({ method: 'POST', url: `${BASE}/list` })
}

export function createStylePreset(payload: StylePresetPayload): Promise<StylePreset> {
  return request<StylePreset>({ method: 'POST', url: `${BASE}/create`, data: payload })
}

export function updateStylePreset(id: number, payload: StylePresetPayload): Promise<StylePreset> {
  return request<StylePreset>({ method: 'POST', url: `${BASE}/update`, data: { id, ...payload } })
}

export function deleteStylePreset(id: number): Promise<void> {
  return request<void>({ method: 'POST', url: `${BASE}/delete`, data: { id } })
}

export function duplicateStylePreset(id: number): Promise<StylePreset> {
  return request<StylePreset>({ method: 'POST', url: `${BASE}/duplicate`, data: { id } })
}
