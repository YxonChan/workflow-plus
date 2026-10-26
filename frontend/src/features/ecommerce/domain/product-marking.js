const MAX_MARKS = 12;

export const PRODUCT_MARKING_MODES = [
  { value: 'auto', label: '自动识别' },
  { value: 'manual', label: '手动标记' },
];

export function createProductMarking() {
  return { mode: 'auto', imageId: '', marks: [] };
}

function record(value, label) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error(`${label}格式无效`);
  return value;
}

function text(value, label, maxLength = 200) {
  if (typeof value !== 'string' || value.length > maxLength) throw new Error(`${label}无效或过长`);
  return value;
}

function number(value, label, min, max) {
  if (!Number.isFinite(value) || value < min || value > max) throw new Error(`${label}无效`);
  return value;
}

export function validateProductMarking(value, uploads = null) {
  if (value === undefined || value === null) return createProductMarking();
  record(value, '商品标记');
  const mode = value.mode === undefined ? 'auto' : value.mode;
  if (!['auto', 'manual'].includes(mode)) throw new Error('商品标记模式无效');
  const imageId = text(value.imageId ?? '', '商品标记图片编号');
  const annotatedSrc = text(value.annotatedSrc ?? '', '商品标记参考图', 50 * 1024 * 1024);
  if (annotatedSrc && !/^(data:image\/(png|jpeg|webp);base64,[A-Za-z0-9+/=]+|\/storage\/[^\s]+|https?:\/\/[^\s]+)$/.test(annotatedSrc)) throw new Error('商品标记参考图无效');
  if (imageId && Array.isArray(uploads) && !uploads.some(item => item.id === imageId)) throw new Error('商品标记图片不存在');
  if (!Array.isArray(value.marks) || value.marks.length > MAX_MARKS) throw new Error('商品标记数量无效');
  const ids = new Set();
  const marks = value.marks.map((item, index) => {
    record(item, '商品标记区域');
    if (item.shape !== undefined && !['point', 'region'].includes(item.shape)) throw new Error('商品标记类型无效');
    const id = text(item.id ?? `mark-${index + 1}`, '商品标记编号');
    if (!id.trim() || ids.has(id)) throw new Error('商品标记编号重复');
    ids.add(id);
    const label = item.label === undefined ? index + 1 : item.label;
    if (!Number.isInteger(label) || label < 1 || label > 99) throw new Error('商品标记数字无效');
    const x = number(item.x, '商品标记横坐标', 0, 1);
    const y = number(item.y, '商品标记纵坐标', 0, 1);
    const width = number(item.width, '商品标记宽度', 0.01, 1);
    const height = number(item.height, '商品标记高度', 0.01, 1);
    if (x + width > 1 || y + height > 1) throw new Error('商品标记区域超出图片范围');
    return {
      id,
      label,
      x,
      y,
      width,
      height,
      ...(item.shape === 'point' ? { shape: 'point' } : {}),
    };
  });
  if (mode === 'manual' && marks.length && !imageId) throw new Error('手动标记缺少图片编号');
  return { mode, imageId, marks, ...(annotatedSrc ? { annotatedSrc } : {}) };
}

export function productMarkingPrompt(value) {
  const marking = validateProductMarking(value);
  if (marking.mode !== 'manual') return '';
  if (!marking.marks.length) return '商品识别采用手动标记模式，但当前没有有效标记；请按上传素材自动识别主要商品。';
  const lines = marking.marks.map(mark => {
    if (mark.shape === 'point') return `标记${mark.label}：参考图中横向${Math.round((mark.x + mark.width / 2) * 100)}%、纵向${Math.round((mark.y + mark.height / 2) * 100)}%处的编号定位点。提取该点所在的完整商品主体，保留完整轮廓；点的大小不代表裁剪区域，不要只截取点周围的局部。`;
    const left = Math.round(mark.x * 100);
    const top = Math.round(mark.y * 100);
    const right = Math.round((mark.x + mark.width) * 100);
    const bottom = Math.round((mark.y + mark.height) * 100);
    return `标记${mark.label}：参考图归一化区域约为左${left}%、上${top}%、右${right}%、下${bottom}%，只识别该区域内的目标主体。`;
  });
  return ['商品识别采用手动标记模式。上传商品参考图上的数字标记仅用于定位，不要把数字、边框或辅助线绘制到输出图中。', ...lines].join('\n');
}

export { MAX_MARKS };
