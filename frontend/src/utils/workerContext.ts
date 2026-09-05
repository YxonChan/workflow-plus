export interface WorkerPageContext {
  source: 'series' | 'assets'
  page: 'series' | 'assets'
  series_id?: number
  series_title?: string
  episode_id?: number
  episode_number?: number
  episode_title?: string
}

const STORAGE_KEY = 'malulu.workerPageContext'

function positiveInt(value: unknown): number | undefined {
  const number = Number(value)
  return Number.isInteger(number) && number > 0 ? number : undefined
}

export function writeWorkerPageContext(context: WorkerPageContext): void {
  if (typeof sessionStorage === 'undefined') return
  const seriesId = positiveInt(context.series_id)
  if (!seriesId) {
    clearWorkerPageContext(context.source)
    return
  }

  const payload: WorkerPageContext = {
    source: context.source,
    page: context.page,
    series_id: seriesId,
    series_title: String(context.series_title ?? '').trim().slice(0, 200),
  }
  const episodeId = positiveInt(context.episode_id)
  const episodeNumber = positiveInt(context.episode_number)
  if (episodeId) payload.episode_id = episodeId
  if (episodeNumber) payload.episode_number = episodeNumber
  if (context.episode_title) payload.episode_title = String(context.episode_title).trim().slice(0, 200)
  sessionStorage.setItem(STORAGE_KEY, JSON.stringify(payload))
}

export function readWorkerPageContext(): WorkerPageContext | null {
  if (typeof sessionStorage === 'undefined') return null
  try {
    const parsed = JSON.parse(sessionStorage.getItem(STORAGE_KEY) ?? '') as WorkerPageContext
    if (!parsed || !['series', 'assets'].includes(parsed.source)) return null
    const seriesId = positiveInt(parsed.series_id)
    if (!seriesId) return null
    return { ...parsed, series_id: seriesId }
  } catch {
    return null
  }
}

export function clearWorkerPageContext(source: WorkerPageContext['source']): void {
  if (typeof sessionStorage === 'undefined') return
  const current = readWorkerPageContext()
  if (!current || current.source === source) {
    sessionStorage.removeItem(STORAGE_KEY)
  }
}
