import type { ModelConfig } from '@/types'

export type ImageModelLike = Pick<ModelConfig, 'model_id' | 'options'> | undefined
export const toapisImageIds = ['gpt-image-2.5-flare', 'gpt-image-2.5-sunburst', 'gpt-image-2.5-flare-official', 'gpt-image-2.5-sunburst-official']
export const imageRatios = ['1:1', '3:2', '2:3', '4:3', '3:4', '5:4', '4:5', '16:9', '9:16', '21:9']
export const officialImageSizes = ['1024x1024', '1536x1024', '1024x1536']
export const officialImageQualities = ['auto', 'low', 'medium', 'high', 'xhigh', 'max']
export const isToapisImage = (model: ImageModelLike) => toapisImageIds.includes(String(model?.model_id ?? '').toLowerCase())
export const isOfficialImage = (model: ImageModelLike) => isToapisImage(model) && String(model?.model_id).endsWith('-official')

export function imageSizeOptions(model: ImageModelLike): string[] {
  const configured = model?.options?.size_options
  const sizes = Array.isArray(configured) ? configured.map(String).filter((size) => /^\d+x\d+$/.test(size)) : []
  const current = String(model?.options?.size ?? '')
  if (/^\d+x\d+$/.test(current)) sizes.push(current)
  return [...new Set([...officialImageSizes, ...sizes])]
}

export function imagePixelsForRatio(ratio: string): string {
  return ({ '1:1': '1024x1024', '3:2': '1536x1024', '2:3': '1024x1536', '16:9': '1536x864',
    '9:16': '864x1536', '4:3': '1536x1152', '3:4': '1152x1536', '5:4': '1280x1024',
    '4:5': '1024x1280', '21:9': '1792x768' } as Record<string, string>)[ratio] || '1024x1024'
}
