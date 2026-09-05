export type VisualStyle = 'realistic' | 'anime' | '3d'
export type VisualStyleVariant = string

export interface VisualStyleOption {
  label: string
  value: VisualStyle
}

export interface VisualStyleVariantOption {
  label: string
  value: VisualStyleVariant
  desc?: string
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

export const VISUAL_STYLE_VARIANTS: Record<VisualStyle, VisualStyleVariantOption[]> = {
  realistic: [
    { label: '电影感写实', value: 'cinematic', desc: '更像影视剧剧照，适合短剧首帧' },
    { label: '短剧写实', value: 'short_drama', desc: '偏竖屏短剧、人物关系和表演感' },
    { label: '纪实摄影', value: 'documentary', desc: '自然光、生活流、少修饰' },
    { label: '商业广告片', value: 'commercial', desc: '干净、高级、产品广告质感' },
    { label: '暗调悬疑', value: 'noir', desc: '低调光、强反差、悬疑氛围' },
  ],
  anime: [
    { label: '昭和动漫风', value: 'showa_anime', desc: '复古赛璐璐、胶片颗粒、怀旧色彩' },
    { label: '40-50年代美式手绘卡通', value: 'classic_american_cartoon', desc: '黄金时代影院动画、夸张表演、手绘背景' },
    { label: '平成经典动漫风', value: 'heisei_classic', desc: '90s-00s 电视动画感，线稿清晰' },
    { label: '萌系动漫风', value: 'moe', desc: '圆润可爱、明亮色彩、柔软线条' },
    { label: '京都动画风', value: 'kyoto_animation', desc: '清爽青春、细腻表情、日常光影' },
    { label: '新海诚风', value: 'shinkai', desc: '透明天空、逆光、细腻城市背景' },
    { label: '现代手游二次元风', value: 'modern_mobile_game', desc: '精致立绘、高饱和光效、数字绘' },
    { label: '国漫风', value: 'guoman', desc: '东方审美、数字动画渲染、层次丰富' },
    { label: '赛璐璐动画风', value: 'cel_shaded', desc: '明确色块、硬边阴影、线稿清晰' },
  ],
  '3d': [
    { label: '动画电影3D', value: 'animated_feature', desc: '角色友好、电影动画质感' },
    { label: '虚幻引擎写实', value: 'unreal', desc: '高精模型、真实材质、游戏过场质感' },
    { label: '风格化3D', value: 'stylized_3d', desc: '夸张造型、简化材质、清晰轮廓' },
    { label: '黏土动画', value: 'clay', desc: '手工材质、定格动画感' },
    { label: '低多边形', value: 'low_poly', desc: '几何切面、简洁明快' },
  ],
}

export function visualStyleVariantOptions(style: VisualStyle): VisualStyleVariantOption[] {
  return VISUAL_STYLE_VARIANTS[style] ?? []
}

export function normalizeVisualStyleVariant(style: VisualStyle, variant: unknown): VisualStyleVariant {
  const raw = String(variant ?? '').trim()
  if (!raw) return ''
  return visualStyleVariantOptions(style).some((option) => option.value === raw) ? raw : ''
}
