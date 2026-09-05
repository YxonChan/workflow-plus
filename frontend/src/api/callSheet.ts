import request from '@/api/http'

const BASE = '/api/call-sheets'

export interface CallSheetPdfResult {
  filename: string
  mime: string
  base64: string
}

export function renderCallSheetPdf(html: string, episodeId?: number): Promise<CallSheetPdfResult> {
  return request<CallSheetPdfResult>({
    method: 'POST',
    url: `${BASE}/pdf`,
    data: { html, episode_id: episodeId },
    timeout: 600_000,
  })
}
