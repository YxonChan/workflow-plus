import { getDocument, GlobalWorkerOptions } from 'pdfjs-dist'
// 内联 worker 源码，避免线上 /admin/static/pdf.worker-*.mjs 因 MIME/缓存/部署缺失导致
// “Failed to fetch dynamically imported module”。
import pdfWorkerSource from 'pdfjs-dist/build/pdf.worker.min.mjs?raw'

let pdfWorkerReady = false

function ensurePdfWorker(): void {
  if (pdfWorkerReady) return
  if (typeof window === 'undefined' || typeof URL === 'undefined' || typeof Blob === 'undefined') {
    throw new Error('当前环境不支持 PDF 解析')
  }
  const blob = new Blob([pdfWorkerSource], { type: 'text/javascript' })
  GlobalWorkerOptions.workerSrc = URL.createObjectURL(blob)
  pdfWorkerReady = true
}

export interface ParsedNovelFile {
  filename: string
  text: string
  chars: number
}

export async function parseNovelFile(file: File): Promise<ParsedNovelFile> {
  const filename = file.name || 'novel'
  const ext = filename.split('.').pop()?.toLowerCase()
  const text = ext === 'pdf'
    ? await parsePdfText(file)
    : await parseTxtText(file)
  const normalized = normalizeNovelText(text)
  if (!normalized) {
    throw new Error(ext === 'pdf' ? '未能从 PDF 中解析出文本，扫描版 PDF 需要 OCR' : '未能从 TXT 中读取到文本')
  }
  return {
    filename,
    text: normalized,
    chars: normalized.length,
  }
}

async function parseTxtText(file: File): Promise<string> {
  const buffer = await file.arrayBuffer()
  for (const encoding of ['utf-8', 'gb18030', 'gbk', 'big5']) {
    try {
      const decoded = new TextDecoder(encoding).decode(buffer)
      if (decoded.trim()) {
        return decoded
      }
    } catch {
      // Try the next encoding.
    }
  }
  return await file.text()
}

async function parsePdfText(file: File): Promise<string> {
  ensurePdfWorker()
  const data = await file.arrayBuffer()
  const pdf = await getDocument({ data }).promise
  const pages: string[] = []
  for (let pageNumber = 1; pageNumber <= pdf.numPages; pageNumber += 1) {
    const page = await pdf.getPage(pageNumber)
    const content = await page.getTextContent()
    pages.push(rebuildPdfPageText(content.items))
  }
  await pdf.destroy()
  return pages.join('\n\n')
}

type PdfTextItem = {
  str: string
  x: number
  y: number
}

/**
 * mPDF / 中文字体常把每个字拆成独立 text item。
 * 按 Y 坐标重组成行，再按 X 排序拼接，避免「第1集」被拆成三行导致本地解析失败。
 */
function rebuildPdfPageText(items: unknown[]): string {
  const rows: PdfTextItem[] = []
  for (const item of items) {
    if (!item || typeof item !== 'object' || !('str' in item)) continue
    const str = String((item as { str?: unknown }).str ?? '')
    if (!str.trim()) continue
    const transform = (item as { transform?: unknown }).transform
    const x = Array.isArray(transform) && typeof transform[4] === 'number' ? transform[4] : 0
    const y = Array.isArray(transform) && typeof transform[5] === 'number' ? transform[5] : 0
    rows.push({ str, x, y })
  }
  if (!rows.length) return ''

  rows.sort((a, b) => (b.y - a.y) || (a.x - b.x))

  const yTolerance = 2.5
  const lines: string[] = []
  let currentY = rows[0].y
  let currentLine: PdfTextItem[] = []

  const flushLine = () => {
    if (!currentLine.length) return
    currentLine.sort((a, b) => a.x - b.x)
    let line = ''
    for (let index = 0; index < currentLine.length; index += 1) {
      const part = currentLine[index].str
      if (index === 0) {
        line = part
        continue
      }
      const prev = currentLine[index - 1]
      const gap = currentLine[index].x - prev.x
      if (shouldInsertPdfSpace(line, part, gap)) {
        line += ' '
      }
      line += part
    }
    lines.push(line.trimEnd())
    currentLine = []
  }

  for (const row of rows) {
    if (Math.abs(row.y - currentY) > yTolerance) {
      flushLine()
      currentY = row.y
    }
    currentLine.push(row)
  }
  flushLine()
  return lines.join('\n')
}

function shouldInsertPdfSpace(left: string, right: string, gap: number): boolean {
  if (!left || !right) return false
  if (/\s$/.test(left) || /^\s/.test(right)) return false
  const leftChar = left.slice(-1)
  const rightChar = right.slice(0, 1)
  const bothCjk = isCjkChar(leftChar) && isCjkChar(rightChar)
  if (bothCjk) return gap > 8
  return gap > 1.2
}

function isCjkChar(char: string): boolean {
  return /[\u3400-\u9FFF\uF900-\uFAFF]/.test(char)
}

function normalizeNovelText(text: string): string {
  return text
    .replace(/^\uFEFF/, '')
    .replace(/\r\n?/g, '\n')
    .replace(/[ \t]+$/gm, '')
    .replace(/\n{4,}/g, '\n\n\n')
    .trim()
}
