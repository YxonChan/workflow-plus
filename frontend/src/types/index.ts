// ── Backend API response envelope ────────────────────────────────────────────
export interface ApiResponse<T = unknown> {
  code: number
  data?: T
  message?: string
}

// ── Model config ──────────────────────────────────────────────────────────────
export type ModelType = 'text' | 'image' | 'video' | 'voice'

export interface ModelConfig {
  id: number
  type: ModelType
  name: string
  model_id: string
  endpoint: string
  scope?: 'global' | 'user'
  user_id?: number
  user?: AdminUserBrief | null
  is_default?: number
  enabled?: number
  /** Masked key returned by backend, e.g. "********abcd" or "未设置" */
  api_key_mask: string
  options: Record<string, unknown>
  create_time: string | null
  update_time: string | null
}

export interface ModelConfigGroup {
  type: ModelType
  title: string
  description: string
  icon: string
  models: ModelConfig[]
}

export interface ModelConfigPayload {
  type: ModelType
  name: string
  model_id: string
  endpoint: string
  /** Blank = don't change the existing key */
  api_key: string
  options: Record<string, unknown>
  scope?: 'global' | 'user'
  user_id?: number
  is_default?: number
  enabled?: number
}

export interface ModelConfigProbeResult {
  id: number
  name: string
  type: ModelType
  model_id: string
  endpoint: string
  mode: 'live_request' | 'preflight'
  method: string
  ok: boolean
  reachable: boolean
  http_status: number
  duration_ms: number
  message: string
  detail: string
}

export interface AdminUserBrief {
  id: number
  username: string
  display_name: string
}

export interface AdminUser extends AdminUserBrief {
  role: 'admin' | 'user'
  status: number
  credit_balance?: number
  create_time?: string | null
  update_time?: string | null
}

// ── Workflow templates ────────────────────────────────────────────────────────
export interface WorkflowGraph {
  nodes: Array<Record<string, unknown>>
  edges: Array<Record<string, unknown>>
}

export interface WorkflowViewport {
  x: number
  y: number
  zoom: number
}

export type WorkflowScope = 'episode' | 'series'

export interface Workflow {
  id: number
  name: string
  description: string
  graph: WorkflowGraph
  viewport: WorkflowViewport
  is_default: number
  is_system?: number
  scope: WorkflowScope
  create_time: string | null
  update_time: string | null
}

export interface WorkflowBrief {
  id: number
  name: string
  scope: WorkflowScope
  is_default: number
  is_system?: number
  graph: WorkflowGraph
}

export interface WorkflowBundle {
  id: number
  name: string
  description: string
  is_system: number
  series_workflow_id: number | null
  episode_workflow_id: number
  series_workflow: WorkflowBrief | null
  episode_workflow: WorkflowBrief | null
  create_time?: string | null
  update_time?: string | null
}

export interface WorkflowPayload {
  name: string
  description?: string
  graph?: WorkflowGraph
  viewport?: WorkflowViewport
  is_default?: number
  scope?: WorkflowScope
}

export interface PromptTemplate {
  id: number
  title: string
  scope: WorkflowScope | 'all'
  node_kind: ModelType | 'input' | 'output' | 'all'
  node_label: string
  description: string
  prompt: string
  tags: string[]
  is_system: number
  sort: number
  create_time?: string | null
  update_time?: string | null
}

// ── Series & Episodes ─────────────────────────────────────────────────────────
export interface Shot {
  id: number
  episode_id: number
  storyboard_revision_id?: number | null
  shot_key?: string
  index: number
  desc: string
  content_text?: string
  content_rich_json?: StoryboardRichNode[]
  asset_refs?: StoryboardAssetRefNode[]
  duration: string
  status: 'pending' | 'done' | 'generating'
  image_url?: string
  video_url?: string
  video_end_frame_url?: string
  media_versions?: ShotMediaVersion[]
  create_time?: string
  update_time?: string
}

export interface ShotMediaVersion {
  id: number
  shot_id: number
  shot_key?: string | null
  storyboard_revision_id?: number | null
  media_type: 'image' | 'video'
  url: string
  poster_url?: string
  end_frame_url?: string
  prompt?: string
  source?: string
  is_selected?: boolean
  orphaned?: boolean
  is_selectable?: boolean
  matches_current_intent?: boolean
  video_job_id?: number | null
  parent_version_id?: number | null
  ai_request_log_id?: number | null
  meta_json?: Record<string, any>
  create_time?: string | null
}

export type StoryboardReferenceRole = 'view' | 'look'
export type StoryboardAssetKind = AssetType | 'look'

export interface StoryboardTextNode {
  type: 'text'
  text: string
}

export interface StoryboardAssetRefNode {
  type: 'asset_ref'
  asset_id: number
  asset_image_id?: number | null
  asset_image_version_id?: number | null
  reference_role: StoryboardReferenceRole
  label: string
  asset_type: StoryboardAssetKind
}

export type StoryboardRichNode = StoryboardTextNode | StoryboardAssetRefNode

export interface Episode {
  id: number
  series_id: number
  current_storyboard_revision_id?: number | null
  number: number
  title: string
  workflow_id: number | null
  workflow_name?: string
  plot_input: string
  promo_segment_count?: number | null
  status: 'draft' | 'production' | 'done'
  /** 列表接口返回的封面（第一张已生成分镜图），无图时为空字符串 */
  cover_url?: string
  shots?: Shot[]
  orphaned_media_versions?: ShotMediaVersion[]
  workflow_state?: EpisodeWorkflowState
  create_time?: string
  update_time?: string
}

export interface EpisodeWorkflowStateNode {
  id: number
  workflow_node_id: string
  label: string
  kind: string
  sort: number
  status: 'queued' | 'running' | 'success' | 'failed' | 'skipped' | 'stale'
  error_message?: string
  output_json?: Record<string, unknown>
  raw_output?: string
  duration_ms?: number
  started_at?: string | null
  finished_at?: string | null
}

export interface EpisodeWorkflowState {
  run_id: number | null
  status: 'unbound' | 'idle' | 'queued' | 'running' | 'success' | 'failed' | 'cancelled' | 'stale'
  auto_execution_run_id?: number | null
  auto_execution_status?: 'idle' | 'queued' | 'running' | 'waiting_async' | 'success' | 'failed' | 'cancelled'
  auto_execution_locked?: boolean
  current_node_label?: string
  progress?: number
  nodes: EpisodeWorkflowStateNode[]
}

export type SeriesRegion = 'china' | 'western'

export interface Series {
  id: number
  title: string
  description: string
  /** 完整剧本/小说正文，供手动「运行剧本解析」使用 */
  source_text?: string | null
  visual_style: string
  visual_style_variant?: string
  region: SeriesRegion
  /** 仅 scope=shared|all 查询时返回：作品所有者信息，用于标注"来自 XXX" */
  owner_user_id?: number
  owner_name?: string
  /** 列表接口返回的封面：第一集分镜图，其次资产核心视图，无图时为空字符串 */
  cover_url?: string
  series_workflow_id?: number | null
  series_workflow_name?: string
  active_workflow_run?: {
    id: number
    series_id: number
    workflow_id: number
    episode_workflow_id?: number | null
    status: 'queued' | 'running' | 'success' | 'failed' | 'cancelled'
    progress: number
    current_node_label?: string
    error_message?: string
    result_json?: {
      episodes_written?: number
      assets_written?: number
    }
    nodes?: Array<{
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
    }>
  } | null
  episodes: Episode[]
  create_time?: string
  update_time?: string
}

// ── Assets ───────────────────────────────────────────────────────────────────
export type AssetType = 'character' | 'scene' | 'prop'

export interface VoiceAsset {
  id: number
  asset_id: number
  name: string
  source_url: string
  original_filename: string
  mime_type: string
  extension: string
  file_size: number
  duration_ms: number
  status: 'pending' | 'processing' | 'ready' | 'failed'
  codec_name: string
  sample_rate: number
  channels: number
  create_time?: string | null
  update_time?: string | null
}

export interface VoiceAssetLimits {
  max_size_bytes: number
  min_duration_seconds: number
  max_duration_seconds: number
  allowed_extensions: string[]
}

export interface AssetImageVersion {
  id: number
  asset_id?: number
  asset_image_id?: number
  model_config_id?: number
  view_type: string
  url: string
  prompt?: string
  source?: string
  is_selected?: boolean
  job_id?: number | null
  ai_request_log_id?: number | null
  create_time?: string
  update_time?: string
}

export interface AssetImage {
  id?: number
  asset_id?: number
  view_type: string
  reference_role?: 'view' | 'look'
  variant_name?: string
  reference_key?: string
  url: string
  note?: string
  image_prompt?: string
  reference_image_url?: string
  sort?: number
  versions?: AssetImageVersion[]
  has_toapis_avatar?: boolean
  toapis_status?: string
  look_avatar_pending?: boolean
  look_avatar_job_id?: number
}

export interface AssetImageJob {
  id: number
  asset_id?: number | null
  asset_image_id?: number | null
  model_config_id: number
  status: 'queued' | 'running' | 'waiting' | 'holding' | 'success' | 'failed' | 'cancelled'
  view_type: string
  url: string
  error_message?: string
  create_time?: string
  update_time?: string
}

export interface Asset {
  id: number
  series_id: number
  type: AssetType
  name: string
  description: string
  image_prompt: string
  tags: string[]
  look_count?: number
  images: AssetImage[]
  image_jobs?: AssetImageJob[]
  /** 仅 scope=shared|all 查询时返回：资产所有者信息，用于"来自 XXX"角标 */
  owner_user_id?: number
  owner_name?: string
  create_time?: string
  update_time?: string
}

export interface AssetPayload {
  series_id: number
  type: AssetType
  name: string
  description?: string
  image_prompt?: string
  tags?: string[]
  images?: AssetImage[]
}

// ── AI-driven Script Creation ───────────────────────────────────────────────
export type ScriptAiConfigKey = 'default' | 'planner' | 'director' | 'writer' | 'reviewer'
export type ScriptProjectStatus = 'draft' | 'queued' | 'running' | 'paused' | 'completed' | 'failed' | 'cancelled'
export type ScriptStepStatus = 'pending' | 'queued' | 'running' | 'completed' | 'failed' | 'skipped'

export interface ScriptTextModel {
  id: number
  name: string
  model_id: string
  scope: 'global' | 'user'
  is_default: number
}

export interface ScriptAiConfig {
  id: number
  config_key: ScriptAiConfigKey
  name: string
  description: string
  model_config_id: number
  system_prompt: string
  task_prompt: string
  temperature: number | null
  max_tokens: number | null
  enabled: number
  sort: number
}

export interface ScriptStep {
  id: number
  project_id: number
  step_key: 'planning' | 'direction' | 'drafting' | 'reviewing'
  step_name: string
  agent_config_key: Exclude<ScriptAiConfigKey, 'default'>
  agent_name: string
  sort: number
  run_no: number
  status: ScriptStepStatus
  attempts: number
  model_config_id: number
  system_prompt_snapshot: string
  task_prompt_snapshot: string
  input_content: string
  output_content: string
  handoff_content?: string
  error_message: string
  duration_ms: number
  started_at?: string | null
  completed_at?: string | null
  create_time?: string | null
  update_time?: string | null
}

export interface ScriptScore {
  id: number
  project_id: number
  external_client_id: number
  scorer_name: string
  score_version: string
  request_id?: string | null
  overall_score: number
  dimensions: Record<string, number | { score: number; comment: string }>
  summary: string
  strengths: string
  weaknesses: string
  suggestions: string
  status: 'submitted'
  create_time?: string | null
  update_time?: string | null
}

export interface ScriptProjectSummary {
  id: number
  user_id: number
  title: string
  genre: string
  output_language: 'zh-CN'
  region_style: 'mainland' | 'overseas'
  synopsis: string
  requirements: string
  status: ScriptProjectStatus
  current_step_key: ScriptStep['step_key']
  current_step: Partial<ScriptStep> | null
  waiting_message: string
  pause_requested: number
  run_no: number
  final_content: string
  latest_score: ScriptScore | null
  error_message: string
  progress_completed: number
  progress_total: number
  started_at?: string | null
  finished_at?: string | null
  create_time?: string | null
  update_time?: string | null
}

export interface ScriptProject extends ScriptProjectSummary {
  steps: ScriptStep[]
  score_history: ScriptScore[]
}
