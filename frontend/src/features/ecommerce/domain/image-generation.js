const SIZES = {
  '1K': { '1:1': '1024x1024', '3:2': '1536x1024', '2:3': '1024x1536', '16:9': '1536x864', '9:16': '864x1536' },
  '2K': { '1:1': '2048x2048', '3:2': '2048x1365', '2:3': '1365x2048', '16:9': '2048x1152', '9:16': '1152x2048' },
};

export function imageFailureMessage(failure, fallback = '生成请求失败，请稍后重试') {
  const candidate = typeof failure === 'string' ? failure : failure?.error?.message || failure?.error || failure?.message;
  return typeof candidate === 'string' && candidate.trim() ? candidate : fallback;
}

export function classifyImageFailure(status, failure) {
  const message = imageFailureMessage(failure, '');
  const code = typeof failure?.error?.code === 'string' ? failure.error.code : '';
  if (/安全|policy|moderation|safety|unsafe|blocked|not allowed|无法用于生成图像|不适合.*图像/i.test(message + ' ' + code)) return 'safety_policy';
  if (status >= 500) return 'upstream';
  return 'request';
}

export function unsubmittedImagesMessage(total, submitted) {
  const remaining = Math.max(0, total - submitted);
  return remaining ? `剩余 ${remaining} 张图片未提交。` : '';
}

export function generationSize(ratio, quality) {
  const size = SIZES[quality]?.[ratio];
  if (!size) throw new Error('无效的图片尺寸');
  return size;
}

export function referenceCurve(node, viewport, anchor) {
  const width = (node.data.displayWidth || 300) * viewport.zoom;
  const aspect = node.data.width > 0 && node.data.height > 0 ? node.data.height / node.data.width : 1;
  const x = node.position.x * viewport.zoom + viewport.x + width / 2;
  const y = node.position.y * viewport.zoom + viewport.y + width * aspect;
  const bend = Math.max(35, Math.abs(anchor.y - y) * .45);
  return 'M ' + x + ' ' + y + ' C ' + x + ' ' + (y + bend) + ', ' + anchor.x + ' ' + (anchor.y - bend) + ', ' + anchor.x + ' ' + anchor.y;
}
