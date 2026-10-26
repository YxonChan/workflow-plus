import { productMarkingPrompt, validateProductMarking } from './product-marking.js';
import { videoAssetPrompt, videoReferences } from './video-references.js';
import { buildVideoPrompt } from './video-prompts.js';
import { createStory, validateStory } from './story.js';

export function createVideoSettings() {
  return { style: 'showcase', story: createStory(), prompt: '', promptTemplate: '', promptVariant: 0, model: 'seedance-2', duration: 5, aspect_ratio: '16:9', resolution: '720p', generate_audio: true, useModel: true, useProduct: true, useScene: false, tasks: [], activeTaskId: '' };
}

function string(value, label, max = 6000) {
  if (typeof value !== 'string' || value.length > max) throw new Error(label + '格式无效');
  return value;
}

function member(value, values, label) {
  if (!values.includes(value)) throw new Error(label + '选项无效');
  return value;
}

function videoUrl(value) {
  string(value, '视频地址', 20000);
  let url;
  try { url = new URL(value); } catch { throw new Error('视频地址无效'); }
  if (!['http:', 'https:'].includes(url.protocol) || url.username || url.password) throw new Error('视频地址无效');
  return value;
}

export function validateVideoTask(value) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('视频任务格式无效');
  if (typeof value.id !== 'string' || !/^[A-Za-z0-9_-]{1,128}$/.test(value.id)) throw new Error('视频任务编号无效');
  const task = { id: value.id, status: member(value.status ?? '', ['', 'queued', 'in_progress', 'completed', 'failed'], '视频状态') };
  for (const field of ['object', 'model', 'prompt', 'submittedAt', 'checkedAt', 'name']) {
    if (value[field] !== undefined) task[field] = string(value[field], '视频任务信息', field === 'prompt' ? 10000 : 6000);
  }
  for (const field of ['created_at', 'completed_at', 'expires_at']) {
    if (value[field] !== undefined && value[field] !== null) {
      if (typeof value[field] !== 'string' && !Number.isFinite(value[field])) throw new Error('视频时间无效');
      task[field] = value[field];
    }
  }
  if (value.progress != null) {
    if (!Number.isFinite(value.progress) || value.progress < 0 || value.progress > 100) throw new Error('视频进度无效');
    task.progress = value.progress;
  }
  if (value.error != null) {
    task.error = {};
    if (typeof value.error === 'string') task.error.message = string(value.error, '视频错误');
    else for (const field of ['code', 'message', 'type']) if (value.error[field] != null) task.error[field] = string(value.error[field], '视频错误');
  }
  if (value.result != null) {
    if (!Array.isArray(value.result.data) || value.result.data.length > 10) throw new Error('视频结果无效');
    task.result = { type: 'video', data: value.result.data.map(item => ({ url: videoUrl(item.url), ...(item.format ? { format: string(item.format, '视频格式', 30) } : {}) })) };
  }
  return task;
}

export function validateVideoSettings(value) {
  if (value === undefined) return createVideoSettings();
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('视频配置格式无效');
  const model = member(value.model, ['seedance-2', 'seedance-2-fast', 'seedance-2-mini'], '视频模型');
  if (!Number.isInteger(value.duration) || !(value.duration >= 4 && value.duration <= 15 || value.duration === 0 && model !== 'seedance-2-mini')) throw new Error('视频时长无效');
  for (const key of ['generate_audio', 'useModel', 'useProduct']) if (typeof value[key] !== 'boolean') throw new Error('视频开关无效');
  if (value.useScene !== undefined && typeof value.useScene !== 'boolean') throw new Error('视频场景开关无效');
  const promptVariant = value.promptVariant ?? 0;
  if (![0, 1].includes(promptVariant)) throw new Error('视频分镜方案无效');
  if (!Array.isArray(value.tasks) || value.tasks.length > 100) throw new Error('视频任务最多保存 100 条');
  const tasks = value.tasks.map(validateVideoTask);
  if (new Set(tasks.map(task => task.id)).size !== tasks.length) throw new Error('视频任务编号重复');
  const activeTaskId = string(value.activeTaskId, '当前视频任务', 128);
  if (activeTaskId && !tasks.some(task => task.id === activeTaskId)) throw new Error('当前视频任务不存在');
  return {
    style: member(value.style ?? 'showcase', ['showcase', 'ugc'], '视频风格'),
    story: validateStory(value.story),
    prompt: string(value.prompt, '视频提示词'), promptTemplate: string(value.promptTemplate ?? '', '视频默认提示词'), promptVariant, model, duration: value.duration,
    aspect_ratio: member(value.aspect_ratio, ['21:9', '16:9', '4:3', '1:1', '3:4', '9:16', 'adaptive'], '视频比例'),
    resolution: member(value.resolution, model === 'seedance-2' ? ['480p', '720p', '1080p', '4k'] : ['480p', '720p'], '视频分辨率'),
    generate_audio: value.generate_audio, useModel: value.useModel, useProduct: value.useProduct, useScene: value.useScene ?? false, tasks, activeTaskId,
  };
}

export function buildVideoRequest(project) {
  const video = validateVideoSettings(project.creative.video);
  if (!video.prompt.trim()) throw new Error('请填写视频提示词');
  const productMarking = video.useProduct ? validateProductMarking(project.product?.config?.marking, project.product?.uploads || []) : null;
  const references = videoReferences(project);
  for (const [kind, enabled, label] of [['model', video.useModel, '模特'], ['product', video.useProduct, '商品'], ['scene', video.useScene, '场景']]) {
    if (enabled && !references.some(entry => entry.kind === kind)) throw new Error(kind === 'scene' ? '请先选择预设、上传或生成场景，或关闭场景引用' : '请先生成' + label + '结果，或关闭对应引用');
  }
  const assets = videoAssetPrompt(project);
  // Regenerate an automatic template from current references so changing a scene never leaves stale asset bindings.
  const automaticPrompt = video.prompt === video.promptTemplate
    ? buildVideoPrompt(project, video.promptVariant, video.useModel)
    : video.prompt.trim();
  const prompt = [automaticPrompt, video.prompt === video.promptTemplate ? '' : assets && !video.prompt.includes(assets) ? '【提交素材与场景约束】\n' + assets : '', productMarking ? productMarkingPrompt(productMarking) : ''].filter(Boolean).join('\n\n');
  if (prompt.length > 10000) throw new Error('视频提示词与素材约束合计超过 10000 字，请精简商品要求或提示词');
  const image_with_roles = [];
  for (const { kind, item: result } of references) {
    const url = result.src;
    image_with_roles.push({ url, role: 'reference_image', kind });
  }
  return { model: video.model, prompt, duration: video.duration, aspect_ratio: video.aspect_ratio, resolution: video.resolution, generate_audio: video.generate_audio, image_with_roles };
}
