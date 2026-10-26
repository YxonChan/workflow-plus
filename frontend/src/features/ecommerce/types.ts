export type SectionKind = 'model' | 'product' | 'scene' | 'creative'
export interface Mark { id: string; label: number; x: number; y: number; width: number; height: number; shape?: 'point' }
export interface Marking { mode: 'auto' | 'manual'; imageId: string; marks: Mark[]; annotatedSrc?: string }
export interface Media { id: string; name: string; src: string; width: number; height: number }
export interface MarketingCopy { enabled: boolean; headline: string; subtitle: string; sellingPoints: string[] }
export interface Poster { headline: string; subtitle: string; sellingPoints: string[]; presetId: string; layout?: 'header' | 'split' | 'footer' }
export interface CopyDraft { headline: string; subtitle: string; selling_points: string[]; product_name: string; observations: string[]; analyzed_images: number }
export interface BriefMaterial extends Media { kind: 'product' | 'person' | 'scene' | 'unknown' | 'ignore'; summary: string }
export interface VideoBrief {
  version: number; phase: 'empty' | 'materials' | 'questions' | 'ready' | 'confirmed'; stale?: boolean; material_key?: string; context_hash?: string
  materials: BriefMaterial[]; characters: StoryCharacter[]; roles: Record<string, string>
  questions: { id: string; text: string; options: string[] }[]; answers: Record<string, { question: string; answer: string }>
  messages: { role: 'user' | 'assistant'; text: string }[]; message?: string; proposal: string; story: Omit<StoryExpansion, 'idea'> | null
  product_ids?: string[]; scene?: BriefMaterial | null; reference_urls?: string[]
}
export interface ImageRequest {
  prompt: string; images: string[]; mode: 'compose' | 'edit'; size: string; n: number
  design?: { purpose: string; presetId: string; layout?: 'template' }
  referenceRoles?: string[]; campaign?: { runId: string; shotId: string; role: string; title: string; styleLock: string; referenceRoles: string[] }
  productImages?: string[]
  styleReference?: string
  autoModel?: boolean
  poster?: Poster
  storyCharacterId?: string; storyCharacterDescription?: string
  modelAge?: string; catalogProfile?: string; marking?: Marking
}
export interface Result extends Media { createdAt: string; operation: 'generate' | 'edit'; request: ImageRequest }
export interface Section {
  config: Record<string, string> & { marking?: never }
  uploads: Media[]; results: Result[]; activeResultId: string; quality: string; count: number
  source?: string; selectedUploadId?: string; presetId?: string
}
export interface ProductSection extends Omit<Section, 'config'> {
  config: { name: string; category: string; presentation: string; notes: string; marking: Marking }
}
export interface CampaignSettings {
  pack: string; detailCount: number; excludedIds: string[]; useModel: boolean; useScene: boolean
  textMode: string; facts: Record<string, string>; activeRun: CampaignRun | null
}
export interface CampaignShot { id: string; title: string; role: string; ratio: string; size: string; prompt: string; status: string; resultId: string; error: string; submittedAt: string }
export interface CampaignRun { id: string; createdAt: string; presetId: string; quality: string; styleLock: string; referenceRoles: string[]; references: string[]; shots: CampaignShot[] }
export interface StoryCharacter { id: string; name: string; description: string; source: 'description' | 'upload' | 'ai'; image: Media | null }
export interface Story { enabled: boolean; idea: string; expandedIdea: string; script: string; title: string; duration: number; hasDialogue: boolean; warnings: string[]; characters: StoryCharacter[] }
export interface StoryExpansion { idea: string; script: string; title: string; duration: number; hasDialogue: boolean; warnings: string[]; characters: StoryCharacter[] }
export interface VideoSettings {
  story: Story
  style: 'showcase' | 'ugc'
  prompt: string; promptTemplate: string; promptVariant: number; model: string; duration: number; aspect_ratio: string
  resolution: string; generate_audio: boolean; useModel: boolean; useProduct: boolean; useScene: boolean
  tasks: unknown[]; activeTaskId: string
}
export interface CreativeSection extends Omit<Section, 'config'> {
  copy: MarketingCopy
  mode: 'image' | 'video'; output: string; format: string; style: string; notes: string; presetId: string
  catalogProfile: string; campaign: CampaignSettings; video: VideoSettings
  library: { favorites: string[]; recent: string[] }; useModel: boolean; useProduct: boolean; useScene: boolean
}
export interface Project {
  schema: 1; name: string; model: Section; product: ProductSection; scene: Section; creative: CreativeSection
  canvas: { viewport: null; positions: Record<string, { x: number; y: number }> }
  integration?: { imageModelId?: number; videoModelId?: number; officialQuality?: string; hiddenJobs?: number[]; goal?: string; useProcessedProduct?: boolean; posterOverrides?: Record<string, Poster | null>; savedPosters?: Record<string, string>; videoBrief?: { version: number; signature: string; productIds: string[]; scene: Media | null } }
}
export interface Preset { id: string; name: string; cover: string; category: string; prompt?: string; description?: string; type?: string; notes?: string; purposes?: string[]; whiteBackground?: boolean; detailLayout?: string }
export interface JobRequest { prompt: string; images: string[]; options: Record<string, string | number | boolean>; original: ImageRequest | Record<string, unknown>; model_id?: string; creative_goal?: string; video_style?: 'showcase' | 'ugc'; skill_snapshot?: { id: string; version: string }[] }
export interface PlanItem { kind: SectionKind; mode: 'image' | 'video'; title: string; page_key: string; model_config_id: number; request: JobRequest }
export interface Job extends Omit<PlanItem, 'request'> {
  id: number; run_id: number; project_id: number; status: 'queued' | 'running' | 'success' | 'failed' | 'unknown' | 'cancelled'
  provider_submitted?: boolean
  request: JobRequest; result_url: string; error_message: string; asset_id?: number; create_time: string; update_time: string
}
export interface Run { id: number; paused: boolean; snapshot: { credits: number; estimated: boolean; items: PlanItem[] }; create_time: string }
export interface ProjectDetail { id: number; name: string; revision: number; state: Project; jobs: Job[]; runs: Run[] }
export interface ProjectBrief { id: number; name: string; revision: number; update_time: string }
export interface Quote { credits: number; estimated: boolean; count: number; items: PlanItem[] }
