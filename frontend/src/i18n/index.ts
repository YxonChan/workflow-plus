import { createI18n } from 'vue-i18n'
import zhCN from './locales/zh-CN'

export type AppLocale = 'zh-CN'

export const i18n = createI18n({
  legacy: false,
  locale: 'zh-CN',
  fallbackLocale: 'zh-CN',
  fallbackFormat: true,
  messages: {
    'zh-CN': zhCN,
  },
  missingWarn: false,
  fallbackWarn: false,
})

export function getLocale(): AppLocale {
  return 'zh-CN'
}

export function setLocale(_locale: AppLocale = 'zh-CN') {
  i18n.global.locale.value = 'zh-CN'
  if (typeof document !== 'undefined') {
    document.documentElement.lang = 'zh-CN'
  }
}

function interpolate(source: string, named?: Record<string, unknown>) {
  if (!named) return source
  return source.replace(/\{(\w+)\}/g, (match, key) =>
    Object.prototype.hasOwnProperty.call(named, key) ? String(named[key] ?? '') : match,
  )
}

export function t(key: string, named?: Record<string, unknown>) {
  const locale = getLocale()
  if (!i18n.global.te(key, locale) && !i18n.global.te(key, 'zh-CN')) {
    return interpolate(key, named)
  }
  const translated = named ? i18n.global.t(key, named) : i18n.global.t(key)
  return translated === key ? interpolate(key, named) : translated
}

setLocale()

export default i18n
