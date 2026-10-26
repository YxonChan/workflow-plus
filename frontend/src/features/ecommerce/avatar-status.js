export function normalizeAvatarStatus(value) {
  if (typeof value !== 'string' || !value.trim()) return '';
  const raw = value.trim();
  const status = raw.toLowerCase().replace(/[\s-]+/g, '_');
  if (['active', 'approved', 'success', 'succeeded', 'ready', 'available', 'completed', 'passed'].includes(status) || ['已入库', '已通过', '通过', '成功', '可用'].includes(raw)) return 'active';
  if (['failed', 'fail', 'error', 'rejected', 'denied'].includes(status) || ['失败', '处理失败', '拒绝', '已拒绝'].includes(raw)) return 'failed';
  if (['processing', 'pending', 'reviewing', 'submitted', 'queued', 'in_progress'].includes(status) || ['审核中', '处理中', '审核处理中', '排队中'].includes(raw)) return 'processing';
  return raw;
}

export function unwrapAvatarRecord(data) {
  if (!data || typeof data !== 'object' || Array.isArray(data)) return data;
  let current = data;
  for (let depth = 0; depth < 4; depth++) {
    if (typeof current.asset_id === 'string' && current.asset_id) {
      const nested = [current.asset, current.data].find(item => item && typeof item === 'object' && !Array.isArray(item) && typeof item.asset_id === 'string' && item.asset_id);
      if (!nested) return current;
      const merged = { ...current };
      for (const [key, value] of Object.entries(nested)) {
        if (value == null || value === '') continue;
        merged[key] = value;
      }
      return merged;
    }
    const next = [current.asset, current.data].find(item => item && typeof item === 'object' && !Array.isArray(item));
    if (!next) return current;
    current = next;
  }
  return current;
}

export function mergeAvatarMetadata(current, incoming, extra = {}) {
  const next = { ...(current || {}) };
  for (const [key, value] of Object.entries(incoming || {})) {
    if (value == null || value === '') continue;
    next[key] = value;
  }
  Object.assign(next, extra);
  const status = normalizeAvatarStatus(next.status || '');
  if (status) next.status = status;
  return next;
}

export function avatarNeedsSync(avatar) {
  if (typeof avatar?.asset_id !== 'string' || !avatar.asset_id) return false;
  return !['active', 'failed'].includes(normalizeAvatarStatus(avatar.status || ''));
}
