export type VisualStyle = 'realistic' | 'anime' | '3d'

export interface VisualStyleOption {
  label: string
  value: VisualStyle
}

export const VISUAL_STYLE_OPTIONS: VisualStyleOption[] = [
  { label: '真人/写实', value: 'realistic' },
  { label: '动漫/二次元', value: 'anime' },
  { label: '3D渲染', value: '3d' },
]

export const VISUAL_STYLE_SEGMENT_OPTIONS = [
  { label: '写实', value: 'realistic' },
  { label: '动漫', value: 'anime' },
  { label: '3D', value: '3d' },
] satisfies Array<{ label: string; value: VisualStyle }>
