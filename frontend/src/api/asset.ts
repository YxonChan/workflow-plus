import request, { type ApiRequestOptions } from '@/api/http'
import type { Asset, AssetImageJob, AssetPayload } from '@/types'

const BASE = '/api/assets'

export function listAssets(
  params?: {
    series_id?: number
    type?: string
    keyword?: string
    /** mine=仅自己（默认）；shared=仅他人分享给我的；all=自己 + 他人分享给我的 */
    scope?: 'mine' | 'shared' | 'all'
  },
  options: ApiRequestOptions = {},
): Promise<Asset[]> {
  return request<Asset[]>({
    method: 'POST',
    url: `${BASE}/list`,
    data: params ?? {},
    timeout: options.timeout ?? 60_000,
    silent: options.silent,
  })
}

export function getAssetImageJob(id: number, options: ApiRequestOptions = {}): Promise<AssetImageJob> {
  return request<AssetImageJob>({
    method: 'POST',
    url: `${BASE}/image-job`,
    data: { id },
    timeout: options.timeout ?? 60_000,
    silent: options.silent,
  })
}

export function createAsset(payload: AssetPayload): Promise<Asset> {
  return request<Asset>({ method: 'POST', url: `${BASE}/create`, data: payload })
}

export function updateAsset(id: number, payload: Partial<AssetPayload>): Promise<Asset> {
  return request<Asset>({ method: 'POST', url: `${BASE}/update`, data: { ...payload, id } })
}

export function deleteAsset(id: number): Promise<void> {
  return request<void>({ method: 'POST', url: `${BASE}/delete`, data: { id } })
}

export function selectAssetImageVersion(assetId: number, versionId: number): Promise<Asset> {
  return request<Asset>({ method: 'POST', url: `${BASE}/select-image-version`, data: { asset_id: assetId, version_id: versionId } })
}

export function deleteAssetImageVersion(assetId: number, versionId: number): Promise<Asset> {
  return request<Asset>({ method: 'POST', url: `${BASE}/delete-image-version`, data: { asset_id: assetId, version_id: versionId } })
}

export function deleteAssetImage(assetId: number, assetImageId: number): Promise<Asset> {
  return request<Asset>({ method: 'POST', url: `${BASE}/delete-image`, data: { asset_id: assetId, asset_image_id: assetImageId } })
}

export function detectLookAvatar(assetId: number, assetImageId: number): Promise<{
  already_active?: boolean
  asset: Asset
  job: AssetImageJob | null
}> {
  return request({ method: 'POST', url: `${BASE}/detect-look-avatar`, data: { asset_id: assetId, asset_image_id: assetImageId } })
}

export function batchGenerateCoreImages(payload: {
  series_id: number
  model_config_id: number
  prompt_addition?: string
  prompt_additions?: Partial<Record<'character' | 'scene' | 'prop', string>>
  reference_image_url?: string
}): Promise<{
  created: number
  skipped_with_core: number
  skipped_queued: number
  jobs: AssetImageJob[]
}> {
  return request({ method: 'POST', url: `${BASE}/batch-generate-core-images`, data: payload })
}

/** 仅取消 status=queued 的生图任务；running/waiting 不中断。 */
export function cancelAssetImageJobs(payload: {
  series_id?: number
  asset_id?: number
  job_ids?: number[]
}): Promise<{ cancelled: number; series_id: number; asset_id: number }> {
  return request({ method: 'POST', url: `${BASE}/cancel-image-jobs`, data: payload })
}

export interface ReuseSeriesOption {
  id: number
  title: string
}

export interface ReuseAssetBrief {
  id: number
  series_id: number
  type: 'character' | 'scene' | 'prop' | string
  name: string
  description: string
  cover_url: string
  look_count: number
  has_voice: boolean
}

export function listAssetsForReuse(payload: {
  target_series_id: number
  source_series_id?: number
  type?: string
  keyword?: string
}): Promise<{
  target_series_id: number
  series: ReuseSeriesOption[]
  assets: ReuseAssetBrief[]
}> {
  return request({ method: 'POST', url: `${BASE}/list-for-reuse`, data: payload })
}

export function copyAssetFrom(payload: {
  source_asset_id: number
  target_series_id: number
  name?: string
  copy_looks?: boolean
  copy_voice?: boolean
}): Promise<{
  asset: Asset
  copied: { images: number; looks: number; voice: boolean }
}> {
  return request({ method: 'POST', url: `${BASE}/copy-from`, data: payload })
}
