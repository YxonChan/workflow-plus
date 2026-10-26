import { request } from '@/api/http'
import type { Media, PlanItem, Project, ProjectBrief, ProjectDetail, Quote, StoryExpansion, CopyDraft, VideoBrief } from './types'

function post<T>(path: string, data: unknown = {}, silent = false): Promise<T> {
  return request<T>({ method: 'POST', url: `/api/ecommerce/${path}`, data, silent, timeout: 120_000 })
}
export const ecommerce = {
  list: () => post<ProjectBrief[]>('projects/list'),
  create: (state: Project, legacy = false) => post<ProjectDetail>('projects/create', { state, import: legacy }),
  detail: (id: number, silent = false) => post<ProjectDetail>('projects/detail', { id }, silent),
  save: (id: number, revision: number, state: Project) => post<{ revision: number }>('projects/save', { id, revision, state }),
  delete: (id: number) => post<void>('projects/delete', { id }),
  upload: (id: number, file: File) => { const data = new FormData(); data.append('id', String(id)); data.append('file', file); return post<Media>('upload', data) },
  useAsset: (id: number, asset_id: number, image_id: number) => post<Media>('use-asset', { id, asset_id, image_id }),
  quote: (id: number, items: PlanItem[]) => post<Quote>('quote', { id, items }),
  expandStory: (id: number, request_key: string, input: { idea: string; duration: number }) => request<StoryExpansion>({ method: 'POST', url: '/api/ecommerce/expand-story', data: { id, request_key, input }, timeout: 240_000 }),
  draftCopy: (id: number, request_key: string) => request<CopyDraft>({ method: 'POST', url: '/api/ecommerce/draft-copy', data: { id, request_key }, timeout: 240_000 }),
  brief: (id: number) => post<VideoBrief>('brief', { id }),
  briefTurn: (id: number, version: number, action: string, input: unknown, request_key: string) => request<VideoBrief>({ method: 'POST', url: '/api/ecommerce/brief-turn', data: { id, version, action, input, request_key }, timeout: 300_000 }),
  generate: (id: number, items: PlanItem[], request_key: string) => post<ProjectDetail>('generate', { id, items, request_key }),
  control: (run_id: number, action: 'pause' | 'resume' | 'cancel') => post<ProjectDetail>('control', { run_id, action }),
  check: (job_id: number) => post<ProjectDetail>('check-original', { job_id }),
  saveAsset: (job_id: number, image_url = '') => post<{ asset_id: number }>('save-asset', { job_id, image_url }),
}
