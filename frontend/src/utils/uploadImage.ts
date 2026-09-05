import request from '@/api/http'

export type UploadImageProgressHandler = (percent: number) => void

/** 给预览 URL 加时间戳，避免同路径替换后浏览器/懒加载仍显示旧图或空白。 */
export function cacheBustMediaUrl(url: string): string {
  const raw = String(url || '').trim()
  if (!raw) return ''
  const base = raw.split('#')[0].split('?')[0]
  const hash = raw.includes('#') ? `#${raw.split('#').slice(1).join('#')}` : ''
  return `${base}?t=${Date.now()}${hash}`
}

/**
 * 上传图片到 /api/upload/image。
 * 不手动设置 multipart Content-Type（由拦截器/浏览器补 boundary）。
 */
export async function uploadImageFile(
  file: File,
  options: {
    purpose?: string
    timeout?: number
    onProgress?: UploadImageProgressHandler
  } = {},
): Promise<string> {
  const formData = new FormData()
  formData.append('file', file)
  if (options.purpose) {
    formData.append('purpose', options.purpose)
  }

  const res = await request<{ url?: string }>({
    url: '/api/upload/image',
    method: 'POST',
    data: formData,
    timeout: options.timeout ?? 180_000,
    onUploadProgress: (event) => {
      if (!options.onProgress) return
      const total = Number(event.total || 0)
      if (total > 0) {
        options.onProgress(Math.max(0, Math.min(100, Math.round((event.loaded * 100) / total))))
      }
    },
  })

  const url = String(res?.url || '').trim()
  if (!url) {
    throw new Error('上传成功但未返回图片地址')
  }
  return cacheBustMediaUrl(url)
}
