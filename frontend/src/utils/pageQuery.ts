import { computed, shallowRef, watch } from 'vue'
import { useRouter, type LocationQueryValue, type Router } from 'vue-router'

type QueryValue = string | undefined
type Pending = { path: string; values: Map<string, QueryValue>; running: boolean }
const pendingByRouter = new WeakMap<Router, Pending>()

// Parent and child views may update different query fields in the same tick.
// Serialize and merge those updates so one navigation cannot erase another.
export function updatePageQuery(router: Router, patch: Record<string, QueryValue>) {
  const path = router.currentRoute.value.path
  let pending = pendingByRouter.get(router)
  if (!pending || pending.path !== path) {
    pending = { path, values: new Map(), running: false }
    pendingByRouter.set(router, pending)
  }
  for (const [key, value] of Object.entries(patch)) pending.values.set(key, value)
  if (pending.running) return
  pending.running = true
  const batch = pending
  void Promise.resolve().then(async () => {
    try {
      while (batch.values.size && router.currentRoute.value.path === batch.path) {
        const changes = new Map(batch.values)
        const current = router.currentRoute.value
        const query = { ...current.query }
        for (const [key, value] of changes) {
          if (value === undefined) delete query[key]
          else query[key] = value
        }
        await router.replace({ path: batch.path, query, hash: current.hash })
        for (const [key, value] of changes) {
          if (batch.values.get(key) === value) batch.values.delete(key)
        }
      }
    } finally {
      if (pendingByRouter.get(router) === batch) pendingByRouter.delete(router)
    }
  }).catch((error) => console.error('Unable to update page location', error))
}

export function queryId(value: string): number | null {
  const id = Number(value)
  return Number.isSafeInteger(id) && id > 0 ? id : null
}

export function queryChoice<T extends string>(choices: readonly T[], fallback: T) {
  return (value: string): T => choices.includes(value as T) ? value as T : fallback
}

export function bindPageQuery<T extends string | number | null>(
  router: Router,
  key: string,
  fallback: T,
  decode: (value: string) => T,
) {
  const path = router.currentRoute.value.path
  const read = (raw: LocationQueryValue | LocationQueryValue[] | undefined): T =>
    typeof raw === 'string' ? decode(raw) : fallback
  const state = shallowRef<T>(read(router.currentRoute.value.query[key]))
  watch(state, (value) => {
    if (router.currentRoute.value.path !== path) return
    const pending = pendingByRouter.get(router)
    if (!pending?.values.has(key) && read(router.currentRoute.value.query[key]) === value) return
    updatePageQuery(router, { [key]: value === fallback || value === null ? undefined : String(value) })
  }, { flush: 'sync' })
  watch(() => router.currentRoute.value, (route) => {
    if (route.path !== path) return
    const pending = pendingByRouter.get(router)
    if (pending?.path === path && pending.values.has(key)) return
    state.value = read(route.query[key])
  }, { flush: 'sync' })
  return state
}

export function usePageQuery<T extends string | number | null>(key: string, fallback: T, decode: (value: string) => T) {
  return bindPageQuery(useRouter(), key, fallback, decode)
}

export function usePageQueryRecord<T extends string | boolean>(key: string, valueType: 'string' | 'boolean') {
  const json = usePageQuery(key, '{}', (raw) => {
    try {
      const parsed: unknown = JSON.parse(raw)
      if (!parsed || typeof parsed !== 'object' || Array.isArray(parsed)) return '{}'
      return JSON.stringify(Object.fromEntries(Object.entries(parsed).filter(([, value]) => typeof value === valueType)))
    } catch {
      return '{}'
    }
  })
  return computed<Record<string, T>>({
    get: () => JSON.parse(json.value),
    set: (value) => { json.value = JSON.stringify(value) },
  })
}
