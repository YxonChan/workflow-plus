export const STYLE_PARAMETER_KEYS = [
  'medium',
  'palette',
  'contrast',
  'lighting',
  'grain',
  'mood',
  'lens',
  'era',
] as const

export type StyleParameterKey = (typeof STYLE_PARAMETER_KEYS)[number]

export type StyleParameters = Record<StyleParameterKey, string[]>

export interface StyleParameterOption {
  value: string
  label: string
}

export interface StyleParameterGroup {
  key: StyleParameterKey
  label: string
  hint: string
  options: StyleParameterOption[]
}

const SENTENCE: Record<StyleParameterKey, (text: string) => string> = {
  medium: (text) => `胶片介质选用${text}`,
  palette: (text) => `整体色调采用${text}`,
  contrast: (text) => `对比度采用${text}`,
  lighting: (text) => `光线以${text}为主`,
  grain: (text) => `颗粒与纹理使用${text}`,
  mood: (text) => `氛围关键词为${text}`,
  lens: (text) => `镜头风格采用${text}`,
  era: (text) => `年代感与导演视觉参考融合${text}`,
}

function option(value: string, gloss: string): StyleParameterOption {
  return { value, label: `${value} · ${gloss}` }
}

export const STYLE_PARAMETER_GROUPS: StyleParameterGroup[] = [
  {
    key: 'medium',
    label: '拍摄介质',
    hint: '胶片、数字机器或历史介质',
    options: [
      option('Kodak Vision3 250D 5207', '日光、清透'),
      option('Kodak Vision3 500T 5219', '夜戏、暖肤色'),
      option('Kodak Vision3 50D 5203', '阳光、极细腻'),
      option('Kodak Portra 400', '奶油肤色'),
      option('Kodak Portra 160', '人像细颗粒'),
      option('Kodak Ektachrome E100', '青蓝反转片'),
      option('Kodachrome 64', '70-80 年代浓郁'),
      option('Fujifilm Eterna 500T 8573', '冷调青绿'),
      option('Fujifilm Pro 400H', '日系清新'),
      option('CineStill 800T', '霓虹红色光晕'),
      option('35mm film', '电影工业标准'),
      option('35mm Anamorphic', '宽银幕椭圆焦外'),
      option('65mm/IMAX film', '大画幅'),
      option('16mm film', '独立电影颗粒'),
      option('Super 8mm film', '家庭录像'),
      option('ARRI Alexa Digital', '现代数字电影'),
      option('Sony Venice', '高端人物片'),
      option('VHS Camcorder', '家用录像带'),
      option('Three-strip Technicolor', '古典三色'),
    ],
  },
  {
    key: 'palette',
    label: '色调',
    hint: '整片调色，保持全片一致',
    options: [
      option('Teal & Orange', '青橙商业'),
      option('Bleach Bypass', '低饱和高对比'),
      option('Golden Hour Warm', '金色暖光'),
      option('Honey Glow', '蜂蜜柔光'),
      option('Amber Warm Memory', '琥珀记忆'),
      option('Sepia Tone', '棕褐怀旧'),
      option('Cold Blue Night', '冷蓝夜色'),
      option('Nordic Noir Cold', '北欧冷峻'),
      option('Steel Grey Blue', '钢蓝灰'),
      option('Neon Cyberpunk', '品红青蓝霓虹'),
      option('Hong Kong Neon', '港片霓虹'),
      option('Wes Anderson Pastel', '粉彩喜剧'),
      option('Amélie Green-Red', '法式绿红'),
      option('Almodóvar Red', '红黄饱和'),
      option('Desaturated Grey', '去饱和'),
      option('Muted Earth + Cool Shadow', '大地色冷阴影'),
      option('Washed-out Pastel', '水洗淡彩'),
      option('Forest Green Moody', '深绿压抑'),
      option('Soft Pink Romance', '柔粉'),
      option('Monochrome B&W High Contrast', '高反差黑白'),
      option('Technicolor 3-strip', '古典浓郁'),
    ],
  },
  {
    key: 'contrast',
    label: '对比度',
    hint: '黑位、高光和灰度曲线',
    options: [
      option('High Contrast Hard', '硬调'),
      option('Medium Contrast Standard', '标准中对比'),
      option('Low Contrast Flat', '低对比'),
      option('Crushed Blacks', '黑位下沉'),
      option('Lifted Blacks', '黑位提亮'),
      option('Rolled-off Highlights', '高光柔和'),
      option('Filmic S-Curve', '电影 S 曲线'),
      option('Soft Mid-tone Roll-off', '中间调柔和'),
      option('Matte Finish Look', '哑光'),
      option('Punchy Saturated Contrast', '高饱和冲击'),
    ],
  },
  {
    key: 'lighting',
    label: '光线',
    hint: '主光性质，不写具体剧情',
    options: [
      option('Soft Diffused Daylight', '柔和日光'),
      option('Hard Sunlight Noon', '正午硬光'),
      option('Magic Hour / Golden Hour', '黄金时刻'),
      option('Blue Hour Twilight', '蓝调时刻'),
      option('Moonlight Cool Blue', '冷蓝月光'),
      option('Window Light Side', '窗光侧打'),
      option('Tungsten Warm Indoor', '钨丝灯暖室内'),
      option('Dappled Light through Leaves', '树叶斑驳光'),
      option('Firelight / Candlelight Flicker', '火光烛光'),
      option('Neon Sign Glow', '霓虹招牌'),
      option('Sodium Vapor Orange Streetlight', '钠灯路灯'),
      option('Practical Lights Only', '仅实用光'),
      option('Volumetric God Rays', '体积光'),
      option('Rembrandt Lighting', '伦勃朗光'),
      option('Chiaroscuro', '明暗对照'),
      option('Silhouette Backlight', '剪影逆光'),
      option('Bloom Highlight Glow', '高光柔晕'),
      option('Top Light Harsh', '顶光压抑'),
      option('Fluorescent Greenish', '荧光绿'),
    ],
  },
  {
    key: 'grain',
    label: '颗粒与纹理',
    hint: '颗粒、光晕、柔焦和介质痕迹',
    options: [
      option('Fine Grain 35mm', '35mm 细颗粒'),
      option('Medium Film Grain', '中等胶片颗粒'),
      option('Heavy Film Grain', '重颗粒'),
      option('Ultra-fine Digital Clean', '数字纯净'),
      option('Halation Orange', '橙色光晕'),
      option('Halation Red Glow', '红色光晕'),
      option('Black Pro-Mist', '黑柔焦'),
      option('Bloom Highlight Glow', '高光晕染'),
      option('Light Leaks Red-Orange', '红橙漏光'),
      option('Anamorphic Horizontal Lens Flare', '横向光晕'),
      option('Soft Focus Diffusion', '柔焦'),
      option('Dust & Scratches', '灰尘划痕'),
      option('VHS Scanlines & Noise', '录像带扫描线'),
      option('Gate Weave & Flicker', '片门抖动'),
    ],
  },
  {
    key: 'mood',
    label: '氛围关键词',
    hint: '情绪和质感词，供提示词引用',
    options: [
      option('whimsical', '异想天开'),
      option('playful', '俏皮'),
      option('joyful', '喜悦'),
      option('romantic', '浪漫'),
      option('warm', '温暖'),
      option('lush', '丰盈'),
      option('cinematic', '电影感'),
      option('refined', '考究'),
      option('sun-drenched', '沐浴阳光'),
      option('hopeful', '充满希望'),
      option('sweet', '甜美'),
      option('nostalgic', '怀旧'),
      option('melancholic', '忧郁'),
      option('lonely', '孤独'),
      option('eerie', '诡异'),
      option('tense', '紧张'),
      option('foreboding', '不祥'),
      option('nocturnal', '夜间'),
      option('dreamy', '梦幻'),
      option('gritty', '粗粝'),
      option('epic', '史诗'),
      option('intimate', '亲密'),
      option('serene', '宁静'),
    ],
  },
  {
    key: 'lens',
    label: '镜头',
    hint: '焦距、运动和构图',
    options: [
      option('28mm Documentary', '28mm 纪实'),
      option('35mm Reportage', '35mm 报道'),
      option('50mm Standard Normal', '50mm 标准'),
      option('85mm Portrait Compression', '85mm 人像压缩'),
      option('100mm Telephoto Intimate', '100mm 长焦'),
      option('Macro Extreme Close-up', '微距特写'),
      option('Wide-angle 24mm Distortion', '24mm 广角'),
      option('Anamorphic 2x Squeeze', '变形宽银幕'),
      option('Vintage Spherical', '复古球面'),
      option('Modern Spherical', '现代锐利球面'),
      option('Steadicam Smooth', '斯坦尼康'),
      option('Handheld Documentary', '手持纪实'),
      option('Locked-off Static Tripod', '固定机位'),
      option('Slow Dolly Push-in', '慢推'),
      option('Whip Pan', '甩镜'),
      option('Crash Zoom', '急推'),
      option('Symmetrical Centered', '对称居中'),
      option('Rule of Thirds Classical', '三分法'),
      option('Negative Space Minimal', '留白'),
      option('Over-the-shoulder Intimate', '过肩'),
    ],
  },
  {
    key: 'era',
    label: '年代与导演',
    hint: '年代外观，或一位摄影指导 / 导演的视觉参照',
    options: [
      option('2020s Streaming Series Look', '流媒体剧集'),
      option('2020s A24 indie', '当代独立片'),
      option('2010s Teal&Orange', '主流商业青橙'),
      option('1970s New Hollywood', '新好莱坞颗粒'),
      option('1980s Neon Synthwave', '霓虹合成器'),
      option('1990s Hong Kong New Wave', '王家卫、杜琪峰'),
      option('1940s Film Noir', '黑色电影'),
      option('1950s Technicolor Musical', '特艺彩色'),
      option('Wes Anderson aesthetic', '对称粉彩'),
      option('Jean-Pierre Jeunet whimsical', '法式童话'),
      option('Hirokazu Kore-eda warm', '家庭暖调'),
      option('Wong Kar-wai mood', '霓虹慢门'),
      option('Roger Deakins style', '自然光低调'),
      option('Linus Sandgren style', '浓郁复古'),
      option('Christopher Doyle style', '港式霓虹手持'),
      option('Greig Fraser style', '当代电影感'),
      option('David Fincher tone', '冷峻黄绿'),
      option('Denis Villeneuve atmosphere', '史诗大气'),
      option('Documentary Natural', '纪实自然'),
    ],
  },
]

export function emptyStyleParameters(): StyleParameters {
  return {
    medium: [],
    palette: [],
    contrast: [],
    lighting: [],
    grain: [],
    mood: [],
    lens: [],
    era: [],
  }
}

export function normalizeStyleParameters(value: unknown): StyleParameters {
  const source = value && typeof value === 'object' ? value as Record<string, unknown> : {}
  const next = emptyStyleParameters()
  for (const key of STYLE_PARAMETER_KEYS) {
    const raw = source[key]
    const items = Array.isArray(raw) ? raw : []
    next[key] = items
      .map((item) => String(item ?? '').trim())
      .filter((item, index, list) => item !== '' && list.indexOf(item) === index)
      .slice(0, 24)
  }
  return next
}

export const FILM_STYLE_PARAMETER_KEYS = ['medium', 'grain', 'lens', 'era'] as const

export function composeStylePrompt(
  parameters: StyleParameters,
  keys: readonly StyleParameterKey[] = STYLE_PARAMETER_KEYS,
): string {
  const parts: string[] = []
  for (const key of keys) {
    const values = parameters[key].map((item) => item.trim()).filter(Boolean)
    if (!values.length) continue
    parts.push(SENTENCE[key](values.join('、')))
  }
  return parts.length ? `${parts.join('。')}。` : ''
}
