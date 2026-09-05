import type { Directive } from 'vue'

type LazySrcBinding =
  | string
  | null
  | undefined
  | {
      src?: string | null
      eager?: boolean
    }

type LazyImageElement = HTMLImageElement & {
  __lazySrcCleanup__?: () => void
  __lazySrcValue__?: string
}

const PLACEHOLDER_SRC =
  "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 8 5'%3E%3Crect width='8' height='5' fill='%23f3f6f9'/%3E%3C/svg%3E"

function resolveBinding(binding: LazySrcBinding) {
  if (typeof binding === 'string') {
    return { src: binding.trim(), eager: false }
  }
  if (!binding) {
    return { src: '', eager: false }
  }
  return {
    src: String(binding.src || '').trim(),
    eager: binding.eager === true,
  }
}

function applyImageHints(el: HTMLImageElement) {
  if (!el.getAttribute('loading')) {
    el.setAttribute('loading', 'lazy')
  }
  if (!el.getAttribute('decoding')) {
    el.setAttribute('decoding', 'async')
  }
  if (!el.getAttribute('fetchpriority')) {
    el.setAttribute('fetchpriority', 'low')
  }
}

function cleanup(el: LazyImageElement) {
  el.__lazySrcCleanup__?.()
  delete el.__lazySrcCleanup__
}

function loadImage(el: LazyImageElement, src: string) {
  if (!src) {
    el.removeAttribute('src')
    return
  }
  if (el.__lazySrcValue__ === src && el.getAttribute('src') === src) {
    return
  }
  el.src = src
  el.__lazySrcValue__ = src
}

function bindLazyImage(el: LazyImageElement, binding: LazySrcBinding) {
  cleanup(el)
  applyImageHints(el)

  const { src, eager } = resolveBinding(binding)
  if (!src) {
    el.removeAttribute('src')
    el.__lazySrcValue__ = ''
    return
  }

  if (eager || typeof window === 'undefined' || !('IntersectionObserver' in window)) {
    loadImage(el, src)
    return
  }

  if (!el.getAttribute('src')) {
    el.src = PLACEHOLDER_SRC
  }

  const observer = new IntersectionObserver(
    (entries) => {
      if (!entries.some((entry) => entry.isIntersecting || entry.intersectionRatio > 0)) return
      loadImage(el, src)
      observer.disconnect()
      delete el.__lazySrcCleanup__
    },
    {
      rootMargin: '280px 0px',
      threshold: 0.01,
    },
  )

  observer.observe(el)
  el.__lazySrcCleanup__ = () => observer.disconnect()
}

export const lazySrcDirective: Directive<HTMLImageElement, LazySrcBinding> = {
  mounted(el, binding) {
    bindLazyImage(el as LazyImageElement, binding.value)
  },
  updated(el, binding) {
    const target = el as LazyImageElement
    const { src } = resolveBinding(binding.value)
    const prev = String(target.__lazySrcValue__ || '')
    // 上传/生成后 URL 从空或旧值变为新值时，立即加载，避免仍停在占位图。
    if (src && src !== prev) {
      cleanup(target)
      applyImageHints(target)
      loadImage(target, src)
      return
    }
    bindLazyImage(target, binding.value)
  },
  beforeUnmount(el) {
    cleanup(el as LazyImageElement)
  },
}
