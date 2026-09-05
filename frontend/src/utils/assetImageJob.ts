export type AssetImageJobStatus = 'queued' | 'running' | 'waiting' | 'holding' | 'success' | 'failed' | 'cancelled' | 'stale'

export function isAssetImageJobPending(status?: string): boolean {
  return status === 'queued' || status === 'running' || status === 'waiting' || status === 'holding'
}

export function isAssetImageJobBusy(status?: string): boolean {
  return status === 'running' || status === 'waiting' || status === 'holding'
}

/** 仅排队中、尚未被 Worker 领取，可取消。 */
export function isAssetImageJobQueued(status?: string): boolean {
  return status === 'queued'
}
