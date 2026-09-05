import { request } from './http'

export type VisualReferenceKind = 'asset' | 'upload'
export type VisualIssueDecision = 'modify' | 'exception' | 'ignore'

export interface VisualReferencePayload {
  kind: VisualReferenceKind
  asset_id: number
  asset_image_id?: number
  asset_image_version_id?: number
  upload_token?: string
}

export interface VisualReviewIssue {
  issue_id: string
  severity: 'error' | 'warning'
  shot_id: number
  shot_index: number
  asset_id: number
  asset_name: string
  field: string
  field_label: string
  expected: string
  actual: string
  image_evidence: string
  storyboard_evidence: string
  confidence: number
  message: string
  suggestion: string
}

export interface VisualReviewResult {
  read_only: true
  review_token: string
  expires_in: number
  scope: {
    series_id: number
    episode_id: number
    episode_number: number
    episode_title: string
    storyboard_revision_id: number
  }
  checked_shots: number
  baselines: Array<{
    asset_id: number
    asset_name: string
    image_url: string
    source: VisualReferenceKind
    traits: Record<string, { value: string; label: string; evidence: string; confidence: number }>
    confidence: number
    notes: string[]
  }>
  issue_count: number
  issues: VisualReviewIssue[]
  unmentioned_assets: string[]
  limits: string
}

export interface RepairPreviewItem {
  shot_id: number
  shot_index: number
  before: string
  after: string
  issue_ids: string[]
  changes: Array<{ asset_name: string; field_label: string; from: string; to: string }>
}

export interface RepairPreviewResult {
  confirmation_token: string
  expires_in: number
  scope: { episode_id: number; storyboard_revision_id: number }
  modified_shot_count: number
  decision_summary: Record<VisualIssueDecision, number>
  previews: RepairPreviewItem[]
  impact: string
}

export interface RepairApplyResult {
  applied: true
  episode_id: number
  previous_storyboard_revision_id: number
  storyboard_revision_id: number
  modified_shot_count: number
}

export interface WorkerUploadResult {
  url: string
  reference_token: string
  expires_in: number
}

export function uploadWorkerReference(file: File): Promise<WorkerUploadResult> {
  const form = new FormData()
  form.append('file', file)
  form.append('purpose', 'worker_chat')
  return request<WorkerUploadResult>({
    method: 'POST',
    url: '/api/upload/image',
    data: form,
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 120_000,
  })
}

export function runVisualReview(episodeId: number, references: VisualReferencePayload[]): Promise<VisualReviewResult> {
  return request<VisualReviewResult>({
    method: 'POST',
    url: '/api/workers/visual-review',
    data: { episode_id: episodeId, references },
    timeout: 180_000,
  })
}

export function previewVisualRepairs(
  reviewToken: string,
  decisions: Array<{ issue_id: string; action: VisualIssueDecision }>,
): Promise<RepairPreviewResult> {
  return request<RepairPreviewResult>({
    method: 'POST',
    url: '/api/workers/repair-preview',
    data: { review_token: reviewToken, decisions },
    timeout: 180_000,
  })
}

export function applyVisualRepairs(confirmationToken: string): Promise<RepairApplyResult> {
  return request<RepairApplyResult>({
    method: 'POST',
    url: '/api/workers/repair-apply',
    data: { confirmation_token: confirmationToken },
    timeout: 180_000,
  })
}
