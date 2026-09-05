import { t } from '@/i18n'

const STATUS_TEXT: Record<number, string> = {
  400: '请求参数有误，请检查后再试',
  401: '登录状态已失效，请重新登录',
  403: '你没有权限执行这个操作',
  404: '请求的内容不存在，请刷新页面后重试',
  408: '请求超时了，请稍后再试',
  429: '请求太频繁了，请稍后再试',
  500: '服务暂时开了小差，请稍后再试',
  502: '服务暂时不可用，请稍后再试',
  503: '服务正在维护或启动中，请稍后再试',
  504: '服务响应超时，请稍后再试',
}

const MESSAGE_RULES: Array<{ test: RegExp; text: string }> = [
  { test: /\broute not found\b/i, text: '请求地址不存在，请刷新页面后重试' },
  { test: /\b404\b.*\bnot found\b/i, text: '请求的内容不存在，请检查地址或稍后再试' },
  { test: /request failed with status code 404/i, text: '请求的内容不存在，请刷新页面后重试' },
  { test: /request failed with status code 401/i, text: '登录状态已失效，请重新登录' },
  { test: /request failed with status code 403/i, text: '你没有权限执行这个操作' },
  { test: /request failed with status code 5\d{2}/i, text: '服务暂时开了小差，请稍后再试' },
  { test: /\bnetwork error\b/i, text: '网络连接失败，请检查服务是否正常启动' },
  { test: /\bfailed to fetch\b/i, text: '网络连接失败，请检查服务是否正常启动' },
  { test: /\btimeout\b/i, text: '请求超时了，请稍后再试' },
  { test: /\bforbidden\b/i, text: '你没有权限执行这个操作' },
  { test: /\bunauthorized\b/i, text: '登录状态已失效，请重新登录' },
  { test: /\binternal server error\b/i, text: '服务暂时开了小差，请稍后再试' },
]

function stripHtml(value: string) {
  return value.replace(/<[^>]+>/g, ' ')
}

function cleanText(value: unknown) {
  return stripHtml(String(value ?? ''))
    .replace(/\s+/g, ' ')
    .replace(/^Error:\s*/i, '')
    .trim()
}

export function messageFromStatus(status?: number, fallback?: string) {
  if (!status) return fallback
  return STATUS_TEXT[status] ? t(STATUS_TEXT[status]) : fallback
}

export function normalizeDisplayMessage(input: unknown, fallback = '操作失败，请稍后再试') {
  const translatedFallback = t(fallback)
  const text = cleanText(input)
  if (!text) return translatedFallback

  for (const rule of MESSAGE_RULES) {
    if (rule.test.test(text)) {
      return t(rule.text)
    }
  }

  if (/^request failed with status code (\d{3})$/i.test(text)) {
    const [, rawStatus] = text.match(/^request failed with status code (\d{3})$/i) || []
    return messageFromStatus(Number(rawStatus), translatedFallback) || translatedFallback
  }

  return text
}
