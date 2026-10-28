import { createProject, buildCreativeRequests } from './commerce.js';
import { buildCampaignRun, campaignShotRequest } from './campaign.js';
import { buildVideoRequest } from './commerce-video.js';
import { buildVideoPrompt } from './video-prompts.js';
import { buildTemplateDetailRequest } from './template-detail.js';
import { generationSize } from './image-generation.js';
import { storyPrompt } from './story.js';
import { hasMarketingText, posterSnapshot } from './marketing-copy.js';
import { prepareBriefReferences } from './video-brief.js';
import { IMAGE_PURPOSES, getStylePreset, styleSupportsPurpose, defaultStyleForPurpose, assertStyleForPurpose } from './style-presets.js';

export const STOREFRONT_GOALS = [
  ...IMAGE_PURPOSES.filter(purpose => !purpose.legacy),
  { id: 'video', label: '商品视频', count: 1, icon: 'VideoCamera' },
];

export function storefrontGoal(project) {
  const goal = project.integration?.goal;
  if (['hero', 'full'].includes(goal)) return 'detail';
  if (STOREFRONT_GOALS.some(item => item.id === goal)) return goal;
  if (project.creative.mode === 'video') return 'video';
  if (project.creative.output === 'campaign') return ['hero', 'full'].includes(project.creative.campaign.pack) ? 'detail' : project.creative.campaign.pack;
  if (['poster', 'tryon'].includes(project.creative.format)) return project.creative.format;
  return 'detail';
}

export function selectStorefrontGoal(project, goal) {
  if (['hero', 'full'].includes(goal)) goal = 'detail';
  if (!STOREFRONT_GOALS.some(item => item.id === goal)) throw new Error('请选择要制作的内容');
  project.integration ||= {};
  const changed = storefrontGoal(project) !== goal;
  project.integration.goal = goal;
  const creative = project.creative;
  creative.mode = goal === 'video' ? 'video' : 'image';
  creative.output = goal === 'main' ? 'campaign' : 'quick';
  if (creative.output === 'campaign') creative.campaign.pack = goal;
  else if (goal !== 'video') creative.format = goal;
  if (goal === 'detail') creative.count = 1;
  if (goal !== 'video' && !styleSupportsPurpose(creative.presetId, goal)) creative.presetId = defaultStyleForPurpose(goal);
  if (changed && goal !== 'video') creative.campaign.excludedIds = [];
}

export function createStorefrontProject() {
  const project = createProject();
  project.creative.useModel = false;
  project.creative.campaign.useModel = false;
  project.creative.video.useModel = false;
  project.creative.count = 1;
  project.creative.video.duration = 10;
  project.product.config.category = '其他';
  project.creative.copy.enabled = true;
  selectStorefrontGoal(project, 'detail');
  return project;
}

export function selectStorefrontStyle(project, presetId) {
  const preset = getStylePreset(presetId);
  if (!preset) throw new Error('图片风格无效');
  assertStyleForPurpose(presetId, storefrontGoal(project));
  project.creative.presetId = presetId;
}

function uploadedReference(section, kind) {
  const upload = section.uploads?.find(item => item.id === section.selectedUploadId) || section.uploads?.[0];
  if (!upload) return;
  const result = { ...upload, id: `direct-${kind}-${upload.id}`, createdAt: new Date().toISOString(), operation: 'generate',
    request: { prompt: '使用上传素材作为商品创作参考', images: [upload.src], mode: 'compose', size: generationSize('3:2', section.quality), n: 1,
      ...(kind === 'model' ? { modelAge: 'unknown' } : {}) } };
  section.results = [result];
  section.activeResultId = result.id;
}

export function prepareStorefrontProject(project) {
  const prepared = JSON.parse(JSON.stringify(project));
  // New work uses the visible uploads; keep a result-only fallback for legacy projects.
  if (prepared.product.uploads.length) uploadedReference(prepared.product, 'product');
  if (!prepared.product.results.some(item => item.id === prepared.product.activeResultId)) throw new Error('请先添加商品图片');
  if (prepared.creative.useModel && prepared.model.source === 'upload') uploadedReference(prepared.model, 'model');
  const creative = prepared.creative;
  if (creative.copy?.enabled && hasMarketingText(creative.copy)) creative.campaign.textMode = 'none';
  if (!prepared.model.results.length) creative.useModel = false;
  creative.useProduct = true;
  creative.campaign.useModel = creative.video.useModel = creative.useModel;
  creative.campaign.useScene = creative.video.useScene = creative.useScene;
  if (creative.video.story?.enabled) creative.video.useModel = false;
  creative.video.useProduct = true;
  return prepared;
}

function additionalProductImages(project, primary) {
  return [...new Set([
    ...project.product.uploads.map(item => item.src),
    ...(project.product.config.marking.mode === 'manual' && project.product.config.marking.annotatedSrc ? [project.product.config.marking.annotatedSrc] : []),
  ])].filter(src => !primary.includes(src));
}

function productNotes(project, includeNotes = true) {
  return [project.product.config.name && `目标商品：${project.product.config.name}`,
    includeNotes && project.product.config.notes && `商品资料（仅作为已提供信息，不扩写事实）：${project.product.config.notes}`,
    project.creative.notes && `本次制作要求：${project.creative.notes}`].filter(Boolean).join('\n');
}

export function storefrontImageRequests(project) {
  assertStyleForPurpose(project.creative.presetId, storefrontGoal(project));
  const prepared = prepareStorefrontProject(project);
  const creative = prepared.creative;
  const purpose = storefrontGoal(prepared);
  const template = purpose === 'detail';
  const requests = template ? [{ title: '商品详情图', pageKey: '', request: buildTemplateDetailRequest(prepared) }]
    : creative.output === 'campaign'
    ? (() => { const run = buildCampaignRun(prepared); return run.shots.map(shot => ({ title: shot.title, pageKey: shot.id, request: campaignShotRequest(run, shot) })); })()
    : buildCreativeRequests(prepared).map((request, index) => ({ title: STOREFRONT_GOALS.find(item => item.id === storefrontGoal(prepared)).label + (creative.count > 1 ? ` ${index + 1}` : ''), pageKey: '', request }));
  return requests.map(item => {
    const poster = template ? null : posterSnapshot(prepared, item.pageKey);
    return { ...item, request: { ...item.request,
      design: { purpose, presetId: creative.presetId, ...(template ? { layout: 'template' } : {}) },
      prompt: [item.request.prompt, productNotes(prepared, !template || !creative.copy.enabled), poster ? '本次仅生成无营销文字的商品底图，标题和卖点由系统后期排版。不绘制文字占位符、假字、营销标签或文案框。商品完整清晰入画，不因后期文案而裁断主体。' : ''].filter(Boolean).join('\n\n'),
      ...(poster ? { poster } : {}),
      ...(item.pageKey !== 'H1' && storefrontGoal(prepared) !== 'tryon' ? { styleReference: getStylePreset(creative.presetId).cover } : {}),
      productImages: additionalProductImages(prepared, item.request.images) } };
  });
}

export function imageReferenceInput(request) {
  const extras = [...new Set(request.productImages || [])].filter(src => !request.images.includes(src));
  const images = [...request.images, ...extras];
  if (request.styleReference && !images.includes(request.styleReference)) images.push(request.styleReference);
  if (images.length > (request.mode === 'edit' ? 13 : 12)) throw new Error('参考图片超过上限，请减少商品图或关闭额外参考');
  const note = extras.length
    ? `补充商品参考：第 ${request.images.length + 1} 至 ${request.images.length + extras.length} 张图补充同一件商品的其他角度、细节或主体标记，与主商品参考共同核对。只采用可见事实，不复制原图背景、无关人物、编号或标记线，不把不同图片混成新的款式。`
    : '';
  const styleNote = request.styleReference
    ? `第 ${images.indexOf(request.styleReference) + 1} 张是独立的版式风格参考，不是商品或人物素材。${request.design?.layout === 'template' ? '复现模板的整体版式，保持模块分布、相对大小、配色和图文层级，把所有模块组合在这一张完整详情图中。示例中的推广封面、操作说明、平台标记及无关宣传标签不属于详情成品。' : '参考其色调、分区、主次与商业设计语言，并按本页主题完成一个页面；若示例展示多页缩略图，不能把整套缩略图或宣传封面复制进本页。'}不复制其中商品、人物、品牌、文案、数字、图标、功效、认证或配件。目标商品外观仅由前述商品参考决定，人物与场景以各自指定参考为准。`
    : '';
  return { images, prompt: [request.prompt, note, styleNote].filter(Boolean).join('\n\n') };
}

export function storefrontVideoRequest(project) {
  const prepared = prepareStorefrontProject(prepareBriefReferences(project));
  const video = prepared.creative.video;
  if (video.story?.enabled) {
    video.prompt = storyPrompt(prepared);
    video.promptTemplate = '';
  } else if (!video.prompt.trim() || video.prompt === video.promptTemplate) {
    video.prompt = buildVideoPrompt(prepared, video.promptVariant, video.useModel);
    video.promptTemplate = video.prompt;
  }
  const request = buildVideoRequest(prepared);
  const extras = additionalProductImages(prepared, request.image_with_roles.map(item => item.url));
  const offset = request.image_with_roles.length;
  if (offset + extras.length > 9) throw new Error('视频最多使用 9 张参考图，请减少商品图或关闭额外参考');
  request.image_with_roles.push(...extras.map(url => ({ url, role: 'reference_image', kind: 'product' })));
  request.prompt = [request.prompt, productNotes(prepared), extras.length ? `第 ${offset + 1} 至 ${offset + extras.length} 张参考图为同一商品的补充视角，只核对商品外观与可见细节，不复制原背景、人物或主体标记。` : ''].filter(Boolean).join('\n\n');
  if (request.prompt.length > 10000) throw new Error('视频脚本与商品资料过长，请精简后重试');
  return request;
}
