import request from '@/api/http'

const BASE = '/api/asset-shares'

export interface ShareTargetUser {
  id: number
  display_name: string
  username: string
}

export interface AssetShareStatus {
  everyone: boolean
  users: Array<{ id: number; display_name: string }>
}

export function listShareTargets(keyword?: string): Promise<{ users: ShareTargetUser[] }> {
  return request<{ users: ShareTargetUser[] }>({
    method: 'POST',
    url: `${BASE}/targets`,
    data: keyword ? { keyword } : {},
  })
}

export function getAssetShareStatus(assetId: number): Promise<AssetShareStatus> {
  return request<AssetShareStatus>({ method: 'POST', url: `${BASE}/for-asset`, data: { asset_id: assetId } })
}

export function updateAssetShare(payload: {
  asset_id: number
  everyone: boolean
  user_ids: number[]
}): Promise<void> {
  return request<void>({ method: 'POST', url: `${BASE}/update`, data: payload })
}

export function getSeriesShareStatus(seriesId: number): Promise<AssetShareStatus> {
  return request<AssetShareStatus>({ method: 'POST', url: `${BASE}/for-series`, data: { series_id: seriesId } })
}

export function updateSeriesShare(payload: {
  series_id: number
  everyone: boolean
  user_ids: number[]
}): Promise<void> {
  return request<void>({ method: 'POST', url: `${BASE}/update-series`, data: payload })
}
