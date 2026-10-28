import { campaignStyle } from './domain/campaign-style.js'
import { validatePoster } from './domain/marketing-copy.js'
import type { Poster } from './types'

function wrap(context: CanvasRenderingContext2D, text: string, width: number) {
  const lines: string[] = []; let line = ''
  for (const character of Array.from(text.replace(/\s+/g, ' ').trim())) {
    if (line && context.measureText(line + character).width > width) { lines.push(line); line = '' }
    line += character
  }
  if (line) lines.push(line)
  return lines
}

function fit(context: CanvasRenderingContext2D, text: string, width: number, size: number, maxLines: number, weight = 400) {
  let lines: string[] = []
  for (; size >= 8; size--) {
    context.font = `${weight} ${size}px "Microsoft YaHei", "PingFang SC", "Noto Sans CJK SC", sans-serif`
    lines = wrap(context, text, width)
    if (lines.length <= maxLines) break
  }
  return { lines, size, height: lines.length * size * 1.35 }
}

export async function renderPoster(src: string, value: Poster, maxWidth = 2048): Promise<HTMLCanvasElement> {
  const poster = validatePoster(value)
  const image = new Image(); image.crossOrigin = 'anonymous'; image.src = src
  await Promise.all([image.decode(), document.fonts.ready])
  const width = Math.min(maxWidth, Math.max(640, image.naturalWidth))
  const height = Math.round(width * image.naturalHeight / image.naturalWidth)
  if (height < width * .45 || height > 4096 || width * height > 10000000) throw new Error('图片比例不适合宣传排版')
  const canvas = document.createElement('canvas'); canvas.width = width; canvas.height = height
  const context = canvas.getContext('2d')
  if (!context) throw new Error('当前浏览器无法生成宣传图')
  const palette = campaignStyle(poster.presetId, 'facts').palette
  const facts = poster.facts || { price: '', originalPrice: '', discount: '', certification: '', sales: '', cta: '' }
  const factLines = [facts.price, facts.originalPrice, facts.discount, facts.certification, facts.sales, facts.cta].filter(Boolean)
  const detailLines = poster.sellingPoints
  // Facts are user-confirmed claims and are rendered after generation. Keep
  // them in a compact block so prices and trust badges do not look like AI copy.
  const factText = factLines.join('  ·  ')
  const margin = width * .065, available = width - margin * 2
  context.fillStyle = palette[0]; context.fillRect(0, 0, width, height)
  const photo = (x: number, y: number, boxWidth: number, boxHeight: number) => {
    const scale = Math.min(boxWidth / image.naturalWidth, boxHeight / image.naturalHeight)
    const photoWidth = image.naturalWidth * scale, photoHeight = image.naturalHeight * scale
    context.drawImage(image, x + (boxWidth - photoWidth) / 2, y + (boxHeight - photoHeight) / 2, photoWidth, photoHeight)
  }
  if (poster.layout === 'split') {
    const column = width * .36, textWidth = column - margin
    context.fillStyle = palette[1]; context.fillRect(0, 0, column, height)
    photo(column, 0, width - column, height)
    const title = fit(context, poster.headline, textWidth, Math.round(width * .048), 4, 700), titleFont = context.font
    const subtitle = fit(context, poster.subtitle, textWidth, Math.round(width * .025), 4), subtitleFont = context.font
    const points = fit(context, [...detailLines, factText].filter(Boolean).join(' / '), textWidth, Math.round(width * .023), 6), pointsFont = context.font
    const gap = width * .025
    const total = title.height + subtitle.height + points.height + gap * 2
    let y = Math.max(margin, (height - total) / 2)
    const x = margin * .5
    context.fillStyle = palette[3]; context.fillRect(x, y - gap, width * .055, Math.max(2, width * .004))
    context.fillStyle = palette[2]; context.textBaseline = 'top'
    for (const [block, font] of [[title, titleFont], [subtitle, subtitleFont], [points, pointsFont]] as const) {
      context.font = font
      for (const line of block.lines) { context.fillText(line, x, y); y += block.size * 1.35 }
      if (block.lines.length) y += gap
    }
    return canvas
  }
  const title = fit(context, poster.headline, available, Math.round(width * .066), 2, 700)
  const titleFont = context.font
  const subtitle = fit(context, poster.subtitle, available, Math.round(width * .026), 2)
  const subtitleFont = context.font
  const textBottom = margin * .75 + title.height + (subtitle.lines.length ? width * .018 + subtitle.height : 0)
  const header = Math.max(height * .20, textBottom + margin * .45)
  const footer = detailLines.length || factLines.length ? Math.min(height * .22, height * (.10 + (factLines.length ? .055 : 0))) : 0
  const imageHeight = height - header - footer
  if (poster.layout === 'footer') {
    const band = header + footer, start = height - band
    photo(0, 0, width, start)
    context.fillStyle = palette[1]; context.fillRect(0, start, width, band)
    context.fillStyle = palette[3]; context.fillRect(margin, start + margin * .35, width * .045, Math.max(2, width * .003))
    context.fillStyle = palette[2]; context.textBaseline = 'top'; context.font = titleFont
    let y = start + margin * .65
    for (const line of title.lines) { context.fillText(line, margin, y); y += title.size * 1.35 }
    y += width * .018; context.font = subtitleFont
    for (const line of subtitle.lines) { context.fillText(line, margin, y); y += subtitle.size * 1.35 }
    if (detailLines.length || factLines.length) {
      const points = fit(context, [...detailLines, factText].filter(Boolean).join('  /  '), available, Math.round(width * .024), 3)
      y = height - footer + (footer - points.height) / 2
      for (const line of points.lines) { context.fillText(line, margin, y); y += points.size * 1.35 }
    }
    return canvas
  }
  // Fit the complete photo below the headline; text never covers the product or its packaging.
  photo(0, header, width, imageHeight)
  context.fillStyle = palette[3]; context.fillRect(margin, margin * .45, width * .045, Math.max(2, width * .003))
  context.fillStyle = palette[2]; context.font = titleFont; context.textBaseline = 'top'
  let y = margin * .75
  for (const line of title.lines) { context.fillText(line, margin, y); y += title.size * 1.35 }
  y += width * .018; context.font = subtitleFont
  for (const line of subtitle.lines) { context.fillText(line, margin, y); y += subtitle.size * 1.35 }
  if (footer) {
    context.fillStyle = palette[1]; context.fillRect(0, height - footer, width, footer)
    const points = fit(context, [...detailLines, factText].filter(Boolean).join('  /  '), available, Math.round(width * .024), 3)
    context.fillStyle = palette[2]; y = height - footer + (footer - points.height) / 2
    for (const line of points.lines) { context.fillText(line, margin, y); y += points.size * 1.35 }
  }
  return canvas
}

export async function posterBlob(src: string, poster: Poster) {
  const canvas = await renderPoster(src, poster)
  const blob = await new Promise<Blob | null>(resolve => canvas.toBlob(resolve, 'image/png'))
  if (!blob) throw new Error('宣传图导出失败，请重试')
  return blob
}
