/**
 * 无封面时的生成式占位：取标题首字 + 由标题哈希出的渐变色。
 * 同一个名字永远得到同一组颜色，让空封面看起来是“有设计的”，而不是坏掉的灰块。
 */
export interface GeneratedCover {
  style: { background: string; color: string }
  text: string
}

export function generatedCover(seedText: string): GeneratedCover {
  const seed = (seedText || '').trim() || '未命名'
  let hash = 0
  for (let i = 0; i < seed.length; i += 1) {
    hash = (hash * 31 + seed.charCodeAt(i)) >>> 0
  }
  const hue = hash % 360
  const hue2 = (hue + 46) % 360
  return {
    style: {
      background: `linear-gradient(135deg, hsl(${hue} 58% 90%), hsl(${hue2} 62% 80%))`,
      color: `hsl(${hue} 42% 34%)`,
    },
    text: seed.slice(0, 2),
  }
}

