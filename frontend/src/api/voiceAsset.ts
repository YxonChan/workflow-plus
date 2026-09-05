import request from '@/api/http'
import type { VoiceAsset, VoiceAssetLimits } from '@/types'

const BASE = '/api/voice-assets'

export function getCharacterVoiceAsset(assetId: number): Promise<{ voice_asset: VoiceAsset | null; limits: VoiceAssetLimits }> {
  return request({ method: 'POST', url: `${BASE}/list`, data: { asset_id: assetId } })
}

export function uploadVoiceAsset(payload: {
  file: File
  asset_id: number
  name: string
  rights_confirmed: boolean
}): Promise<{ voice_asset: VoiceAsset; deduplicated: boolean }> {
  const data = new FormData()
  data.append('file', payload.file)
  data.append('asset_id', String(payload.asset_id))
  data.append('name', payload.name)
  data.append('rights_confirmed', payload.rights_confirmed ? '1' : '0')

  return request({
    method: 'POST',
    url: `${BASE}/upload`,
    data,
    headers: { 'Content-Type': 'multipart/form-data' },
  })
}

export function deleteVoiceAsset(assetId: number, id: number): Promise<void> {
  return request({ method: 'POST', url: `${BASE}/delete`, data: { asset_id: assetId, id } })
}
