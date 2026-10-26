import type { Project, CampaignSettings, CampaignRun, CampaignShot, ImageRequest } from '../types'
export const CAMPAIGN_PAGES: { id: string; title: string; role: string; ratio: string; fact?: string }[]
export const CAMPAIGN_FACT_FIELDS: [string, string][]
export function campaignPages(settings: CampaignSettings): typeof CAMPAIGN_PAGES
export function buildCampaignRun(project: Project): CampaignRun
export function campaignShotRequest(run: CampaignRun, shot: CampaignShot): ImageRequest
