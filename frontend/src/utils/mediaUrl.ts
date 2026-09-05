const configuredMediaBase = String(import.meta.env.VITE_MEDIA_PUBLIC_BASE_URL || '').replace(/\/+$/, '')

export function mediaUrl(value: unknown): unknown {
  if (typeof value !== 'string') return value
  const url = value.trim()
  if (url === '') return value
  if (/^(?:https?:|data:|blob:|mailto:|tel:)/i.test(url)) return value

  const origin = configuredMediaBase || window.location.origin
  if (url.startsWith('/storage/')) {
    return origin + url
  }
  if (url.startsWith('storage/')) {
    return origin + '/' + url
  }

  return value
}

export function normalizeMediaUrls<T>(value: T): T {
  if (Array.isArray(value)) {
    return value.map((item) => normalizeMediaUrls(item)) as T
  }
  if (value === null || typeof value !== 'object') {
    return value
  }

  const out: Record<string, unknown> = {}
  for (const [key, item] of Object.entries(value as Record<string, unknown>)) {
    const normalized = normalizeMediaUrls(item)
    out[key] = shouldNormalizeMediaKey(key) ? mediaUrl(normalized) : normalized
  }
  return out as T
}

function shouldNormalizeMediaKey(key: string): boolean {
  const lower = key.toLowerCase()
  return lower === 'url'
    || lower === 'poster'
    || lower.endsWith('_url')
    || lower.endsWith('url')
}
