import request, { type ApiRequestOptions } from '@/api/http'
import type { Series, Episode, StoryboardRichNode, StoryboardAssetRefNode } from '@/types'

const BASE_SERIES = '/api/series'
const BASE_EPISODES = '/api/episodes'

export interface VideoPromptPreviewShot {
  index: number
  description: string
  compiled_prompt: string
  content_text?: string
  content_rich_json?: StoryboardRichNode[]
  asset_refs?: StoryboardAssetRefNode[]
  duration: string
  source_image_url: string
  input_image_url: string
  prompt: string
  reference_images: Array<{ alias: string; url: string; name: string; type: string }>
  assets: Array<{ id?: number | null; name: string; type: string; image_url: string }>
}

export interface VideoPromptPreview {
  episode_id: number
  workflow_node_id: string
  label: string
  video_options: Record<string, any>
  video_style_prompt?: string
  video_style_source?: 'system_default' | 'custom' | string
  shot_count: number
  shots: VideoPromptPreviewShot[]
  generated_at: string
}

export function listSeries(
  params?: { scope?: 'mine' | 'shared' | 'all' },
  options: ApiRequestOptions = {},
): Promise<Series[]> {
  return request<Series[]>({
    method: 'POST',
    url: `${BASE_SERIES}/list`,
    data: params ?? {},
    timeout: options.timeout ?? 60_000,
    silent: options.silent,
  })
}

export function updateSeries(id: number, data: Partial<Series>): Promise<Series> {
  return request<Series>({ method: 'POST', url: `${BASE_SERIES}/update`, data: { ...data, id } })
}

export function deleteSeries(id: number): Promise<void> {
  return request<void>({ method: 'POST', url: `${BASE_SERIES}/delete`, data: { id } })
}

export function createEpisode(seriesId: number, data: Partial<Episode>): Promise<Episode> {
  return request<Episode>({ method: 'POST', url: `${BASE_EPISODES}/create`, data: { ...data, series_id: seriesId } })
}

export function getEpisode(id: number, options: ApiRequestOptions = {}): Promise<Episode> {
  return request<Episode>({
    method: 'POST',
    url: `${BASE_EPISODES}/detail`,
    data: { id },
    timeout: options.timeout ?? 60_000,
    silent: options.silent,
  })
}

export function updateEpisode(id: number, data: Partial<Episode>): Promise<Episode> {
  return request<Episode>({ method: 'POST', url: `${BASE_EPISODES}/update`, data: { ...data, id } })
}

/** @deprecated 整集一键自动执行已停用；后端返回 410。请用 runEpisodeWorkflowNode / runEpisodeWorkflowNodeAsync。 */
export function runEpisodeWorkflowAuto(id: number, promoSegmentCount?: number): Promise<Episode> {
  return request<Episode>({
    method: 'POST',
    url: `${BASE_EPISODES}/run-auto`,
    data: {
      id,
      ...(promoSegmentCount !== undefined ? { promo_segment_count: promoSegmentCount } : {}),
    },
  })
}

export function cancelEpisodeWorkflowAuto(id: number): Promise<Episode> {
  return request<Episode>({
    method: 'POST',
    url: `${BASE_EPISODES}/cancel-auto-run`,
    data: { id },
  })
}

export function runEpisodeWorkflowNode(
  id: number,
  nodeId: string,
  promptOverride?: string,
  options: { shotIndex?: number; promptTemplateId?: number } = {},
): Promise<Episode> {
  return request<Episode>({
    method: 'POST',
    url: `${BASE_EPISODES}/run-node`,
    data: {
      id,
      node_id: nodeId,
      ...(promptOverride !== undefined ? { prompt_override: promptOverride } : {}),
      ...(options.shotIndex ? { shot_index: options.shotIndex } : {}),
      ...(options.promptTemplateId ? { prompt_template_id: options.promptTemplateId } : {}),
    },
    timeout: 600_000,
  })
}

export function runEpisodeWorkflowNodeAsync(
  id: number,
  nodeId: string,
  promptOverride?: string,
  options: { shotIndex?: number; promptTemplateId?: number } = {},
): Promise<Episode> {
  return request<Episode>({
    method: 'POST',
    url: `${BASE_EPISODES}/run-node-async`,
    data: {
      id,
      node_id: nodeId,
      ...(promptOverride !== undefined ? { prompt_override: promptOverride } : {}),
      ...(options.shotIndex ? { shot_index: options.shotIndex } : {}),
      ...(options.promptTemplateId ? { prompt_template_id: options.promptTemplateId } : {}),
    },
  })
}

export function previewEpisodeVideoPrompts(id: number, nodeId: string): Promise<VideoPromptPreview> {
  return request<VideoPromptPreview>({
    method: 'POST',
    url: `${BASE_EPISODES}/preview-video-prompts`,
    data: { id, node_id: nodeId },
    silent: true,
  })
}

export function regenerateEpisodeStoryboardShot(
  id: number,
  nodeId: string,
  shotIndex: number,
  instruction = '',
  assetRefs: StoryboardAssetRefNode[] = [],
): Promise<Episode> {
  return request<Episode>({
    method: 'POST',
    url: `${BASE_EPISODES}/regenerate-storyboard-shot`,
    data: {
      id,
      node_id: nodeId,
      shot_index: shotIndex,
      instruction,
      ...(assetRefs.length ? { asset_refs: assetRefs } : {}),
    },
    timeout: 600_000,
  })
}

export function cancelEpisodeWorkflowNode(id: number, nodeId: string): Promise<{ cancelled: number; cancelled_video_jobs: number; episode: Episode }> {
  return request<{ cancelled: number; cancelled_video_jobs: number; episode: Episode }>({
    method: 'POST',
    url: `${BASE_EPISODES}/cancel-node`,
    data: { id, node_id: nodeId },
  })
}

export function cancelEpisodeVideoJobs(id: number, nodeId: string): Promise<{ cancelled: number; episode: Episode }> {
  return request<{ cancelled: number; episode: Episode }>({
    method: 'POST',
    url: `${BASE_EPISODES}/cancel-video-jobs`,
    data: { id, node_id: nodeId },
  })
}

export function cancelEpisodeVideoShot(
  id: number,
  nodeId: string,
  jobId: number,
): Promise<{ cancelled: number; job_id: number; shot_index: number; episode: Episode }> {
  return request<{ cancelled: number; job_id: number; shot_index: number; episode: Episode }>({
    method: 'POST',
    url: `${BASE_EPISODES}/cancel-video-shot`,
    data: { id, node_id: nodeId, job_id: jobId },
  })
}

export function rerunEpisodeVideoShot(
  id: number,
  nodeId: string,
  jobId: number,
  prompt: string,
): Promise<Episode> {
  return request<Episode>({
    method: 'POST',
    url: `${BASE_EPISODES}/rerun-video-shot`,
    data: { id, node_id: nodeId, job_id: jobId, prompt },
  })
}

export function rerunEpisodeImageShot(
  id: number,
  nodeId: string,
  shotId: number,
): Promise<Episode> {
  return request<Episode>({
    method: 'POST',
    url: `${BASE_EPISODES}/rerun-image-shot`,
    data: { id, node_id: nodeId, shot_id: shotId },
    timeout: 600_000,
  })
}

export function selectEpisodeMediaVersion(
  id: number,
  versionId: number,
): Promise<Episode> {
  return request<Episode>({
    method: 'POST',
    url: `${BASE_EPISODES}/select-media-version`,
    data: { id, version_id: versionId },
  })
}

export interface StoryboardShotPayload {
  index: number
  title: string
  content_text: string
  content_rich_json?: StoryboardRichNode[]
  shot_key?: string
}

export interface UpdateEpisodeWorkflowNodeContentOptions {
  updateMode?: 'single_shot' | 'full'
  changedShotIndex?: number
}

export function updateEpisodeWorkflowNodeContent(
  id: number,
  nodeId: string,
  content: string,
  storyboardShots?: StoryboardShotPayload[],
  options: UpdateEpisodeWorkflowNodeContentOptions = {},
): Promise<Episode> {
  return request<Episode>({
    method: 'POST',
    url: `${BASE_EPISODES}/update-node-content`,
    data: {
      id,
      node_id: nodeId,
      content,
      ...(storyboardShots?.length ? { storyboard_shots: storyboardShots } : {}),
      ...(options.updateMode ? { update_mode: options.updateMode } : {}),
      ...(options.changedShotIndex ? { changed_shot_index: options.changedShotIndex } : {}),
    },
  })
}

export function deleteEpisode(id: number): Promise<void> {
  return request<void>({ method: 'POST', url: `${BASE_EPISODES}/delete`, data: { id } })
}

export interface SeriesRunWorkflowPayload {
  workflow_id?: number | null
  episode_workflow_id?: number | null
  source_text?: string
  episode_count?: number
}

export interface SeriesRunWorkflowResult {
  series_id: number
  workflow_id?: number
  episodes_written?: number
  assets_written?: number
  queued?: boolean
  workflow_run?: {
    id: number
    status?: string
  }
  preview?: {
    episodes?: Array<Record<string, unknown>>
    assets?: Array<Record<string, unknown>>
  }
}

export interface CreateSeriesResult extends Series {
  workflow_result?: SeriesRunWorkflowResult
  workflow_run?: {
    id: number
    status: string
  }
}

export function createSeries(data: Partial<Series> & Record<string, unknown>): Promise<CreateSeriesResult> {
  return request<CreateSeriesResult>({
    method: 'POST',
    url: `${BASE_SERIES}/create`,
    data,
    timeout: 600_000,
  })
}

export interface ImportedNovelText {
  filename: string
  file_token: string
  extension: 'txt' | 'pdf'
  title: string
  description: string
  text: string
  chars: number
}

export function importNovelFile(file: File): Promise<ImportedNovelText> {
  const formData = new FormData()
  formData.append('file', file)
  return request<ImportedNovelText>({
    method: 'POST',
    url: '/api/upload/novel',
    data: formData,
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 120_000,
  })
}

export function runSeriesWorkflow(
  seriesId: number,
  data: SeriesRunWorkflowPayload,
): Promise<SeriesRunWorkflowResult> {
  return request<SeriesRunWorkflowResult>({
    method: 'POST',
    url: `${BASE_SERIES}/run-workflow`,
    data: { ...data, id: seriesId },
    timeout: 600_000,
  })
}
