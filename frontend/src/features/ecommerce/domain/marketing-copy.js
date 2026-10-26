import { getStylePreset } from './style-presets.js';
import { campaignLayout } from './campaign-layout.js';

export function createMarketingCopy() {
  return { enabled: false, headline: '', subtitle: '', sellingPoints: [] };
}

export function validateMarketingCopy(value) {
  if (value === undefined) return createMarketingCopy();
  if (!value || typeof value !== 'object' || typeof value.enabled !== 'boolean') throw new Error('宣传文案格式无效');
  const text = (input, max) => { if (typeof input !== 'string' || [...input].length > max) throw new Error('宣传文案过长或无效'); return input; };
  if (!Array.isArray(value.sellingPoints) || value.sellingPoints.length > 3) throw new Error('宣传卖点最多三条');
  return { enabled: value.enabled, headline: text(value.headline, 20), subtitle: text(value.subtitle, 40), sellingPoints: value.sellingPoints.map(point => text(point, 16)).filter(point => point.trim()) };
}

export function validatePoster(value) {
  const copy = validateMarketingCopy({ ...value, enabled: true });
  if (!getStylePreset(value?.presetId)) throw new Error('文案版式无效');
  if (value.layout !== undefined && !['header', 'split', 'footer'].includes(value.layout)) throw new Error('文案版式无效');
  return { headline: copy.headline, subtitle: copy.subtitle, sellingPoints: copy.sellingPoints, presetId: value.presetId, ...(value.layout ? { layout: value.layout } : {}) };
}

export function posterSnapshot(project, pageKey = '') {
  const copy = validateMarketingCopy(project.creative.copy);
  if (!copy.enabled || !copy.headline.trim() || pageKey === 'H1' || project.integration?.goal === 'tryon'
    || (pageKey === 'M1' && getStylePreset(project.creative.presetId)?.whiteBackground)) return null;
  const page = campaignLayout(pageKey);
  const pointIndex = ({ H2: 0, H4: 1, D2: 0, D3: 1, D4: 2 })[pageKey];
  const point = pointIndex !== undefined ? copy.sellingPoints[pointIndex] : '';
  const headline = pageKey && !['M1', 'D1', 'H5', 'D9'].includes(pageKey) ? point || page.headline : copy.headline;
  const subtitle = pageKey && !['M1', 'D1', 'H5', 'D9'].includes(pageKey) ? '' : copy.subtitle;
  const sellingPoints = pageKey ? (point ? [] : copy.sellingPoints.slice(0, 1)) : copy.sellingPoints;
  return validatePoster({ headline, subtitle, sellingPoints, presetId: project.creative.presetId, layout: page.layout });
}
