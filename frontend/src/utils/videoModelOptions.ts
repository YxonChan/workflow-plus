/** 从视频模型 options 解析分辨率/画幅动态选项（对齐火山 Ark / MiniMax 等通道）。 */

export type VideoModelLike = {
  id?: number
  name?: string
  endpoint?: string
  model_id?: string
  options?: Record<string, unknown> | null
} | null | undefined

const ARK_RESOLUTIONS = ['480p', '720p', '1080p'] as const
const MINIMAX_RESOLUTIONS = ['768P', '2K'] as const
const DEFAULT_ASPECT_RATIOS = ['16:9', '9:16', '1:1', '4:3', '3:4', '21:9'] as const

function asStringArray(value: unknown): string[] {
  if (!Array.isArray(value)) return []
  return value
    .map((item) => String(item ?? '').trim())
    .filter((item) => item !== '')
}

function providerOf(model: VideoModelLike): string {
  const options = model?.options && typeof model.options === 'object' ? model.options : {}
  return String(options.provider ?? '').toLowerCase().trim()
}

function endpointOf(model: VideoModelLike): string {
  return String(model?.endpoint ?? '').toLowerCase()
}

export function isMiniMaxVideoModel(model: VideoModelLike): boolean {
  const provider = providerOf(model)
  const endpoint = endpointOf(model)
  return provider === 'minimax_v2'
    || provider === 'minimax'
    || endpoint.includes('api.minimaxi.com/v2/video_generation')
}

export function isArkVideoModel(model: VideoModelLike): boolean {
  const provider = providerOf(model)
  const endpoint = endpointOf(model)
  return provider === 'ark'
    || endpoint.includes('volces.com')
    || endpoint.includes('ark.cn-')
}

export function videoResolutionOptions(model: VideoModelLike): string[] {
  const options = model?.options && typeof model.options === 'object' ? model.options : {}
  const fromModel = asStringArray(options.resolution_options)
  if (fromModel.length > 0) return fromModel
  if (isMiniMaxVideoModel(model)) return [...MINIMAX_RESOLUTIONS]
  return [...ARK_RESOLUTIONS]
}

export function videoDefaultResolution(model: VideoModelLike): string {
  const options = model?.options && typeof model.options === 'object' ? model.options : {}
  const list = videoResolutionOptions(model)
  const preferred = String(options.resolution ?? '').trim()
  if (preferred && list.includes(preferred)) return preferred
  return list[0] ?? '480p'
}

export function videoAspectRatioOptions(model: VideoModelLike): string[] {
  const options = model?.options && typeof model.options === 'object' ? model.options : {}
  const fromModel = asStringArray(options.aspect_ratio_options)
  if (fromModel.length > 0) return fromModel
  return [...DEFAULT_ASPECT_RATIOS]
}

export function videoDurationOptions(model: VideoModelLike): number[] {
  const options = model?.options ?? {}
  // These bounds also apply in the current video adapters.
  const lower = isMiniMaxVideoModel(model) ? 4 : 5
  const min = Math.max(lower, Number(options.min_duration ?? lower))
  const max = Math.min(15, Number(options.max_duration ?? 15))
  const allowed = (value: number) => Number.isInteger(value) && value >= min && value <= max
  if (Array.isArray(options.duration_options)) {
    return [...new Set(options.duration_options.map(Number).filter(allowed))].sort((a, b) => a - b)
  }
  if (isMiniMaxVideoModel(model) || options.min_duration != null || options.max_duration != null) {
    return Array.from({ length: 12 }, (_, index) => index + 4).filter(allowed)
  }
  return [5, 10, 15].filter(allowed)
}

export function pickAllowedResolution(current: string, model: VideoModelLike): string {
  const list = videoResolutionOptions(model)
  const value = String(current ?? '').trim()
  if (value && list.includes(value)) return value
  return videoDefaultResolution(model)
}
