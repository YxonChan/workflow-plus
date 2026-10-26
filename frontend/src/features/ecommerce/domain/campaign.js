import { generationSize } from './image-generation.js';
import { IMAGE_PURPOSES, getStylePreset, assertStyleForPurpose } from './style-presets.js';
import { campaignStyle } from './campaign-style.js';
import { validateCatalogProfile, catalogPrompt, assertCatalogModel } from './catalog-prompts.js';
import { productMarkingPrompt, validateProductMarking } from './product-marking.js';
import { selectedSceneReference } from './scene-presets.js';
import { campaignLayout } from './campaign-layout.js';

export const CAMPAIGN_FACT_FIELDS = [
  ['title', '商品标题'], ['sellingPoints', '已确认卖点'], ['material', '材质 / 成分'],
  ['specs', '尺寸 / 规格'], ['care', '使用 / 护理'], ['package', '包装 / 清单'], ['service', '售后 / 服务'],
];
export const CAMPAIGN_PAGES = [
  { id: 'M1', role: 'hero', title: '商品主图', ratio: '1:1', instruction: '生成一张独立方形商品主图，完整商品为唯一视觉中心，缩略展示仍能看清轮廓与卖点。只完成当前主图，不添加套图缩略图、连续详情模块或无关商品。' },
  { id: 'H1', role: 'hero', title: '白底商品主图', ratio: '1:1', instruction: '纯白背景，只展示目标商品整体，商品完整清晰。服饰使用自然平铺或无人体的立体陈列，不绘制模特、道具、额外文字或图标。' },
  { id: 'H2', role: 'hero', title: '核心质感副图', ratio: '1:1', fact: 'sellingPoints', instruction: '以整体商品为主，搭配一个素材中明确可见的局部细节，直观展示真实商品特点，不添加隐藏结构。' },
  { id: 'H3', role: 'hero', title: '使用场景主图', ratio: '1:1', instruction: '在符合实际商品用途的自然场景展示商品，人物可在启用模特时出现，商品始终是视觉主角。' },
  { id: 'H4', role: 'hero', title: '细节展示副图', ratio: '1:1', instruction: '清楚展示参考图已有的关键工艺、纹理或结构，选用一个有依据的特写；特写须来自同一件完整商品，不得单独生成上半身或下半身裁片。不使用虚构的优劣对比或前后效果对比。' },
  { id: 'H5', role: 'hero', title: '上架收尾主图', ratio: '1:1', fact: 'service', instruction: '以完整商品搭配简洁留白，收束本组商业视觉；没有已确认服务文案时仅展示商品，不添加折扣、价格、物流、保障或促销承诺。' },
  { id: 'D1', role: 'detail', title: '详情首屏', ratio: '2:3', fact: 'sellingPoints', instruction: '作为商品详情首屏，建立清晰的商品印象，以完整主体与有序留白承接主图风格。' },
  { id: 'D2', role: 'detail', title: '商品亮点', ratio: '2:3', fact: 'sellingPoints', instruction: '围绕素材可确认的一个商品亮点展示整体与局部关系，避免多个无关重点堆砌。' },
  { id: 'D3', role: 'detail', title: '材质与纹理', ratio: '2:3', fact: 'material', instruction: '展示参考图中真实可见的面料、表面纹理或工艺细节，并保持与完整单品的从属关系；不得把局部工艺拼成另一件上半身或下半身商品。没有材质证据时只表现视觉纹理，不推测成分或内部结构。' },
  { id: 'D4', role: 'detail', title: '规格与比例', ratio: '2:3', fact: 'specs', instruction: '展示完整商品及自然比例；只有提供真实规格时才排版具体数值，缺少规格时仅呈现已知外观，不编造尺寸表。' },
  { id: 'D5', role: 'detail', title: '使用展示', ratio: '2:3', fact: 'care', instruction: '以商品实际用途安排自然的穿着、持握或陈列画面。只有提供操作资料时才添加步骤或护理说明，缺少资料时不推测功能。' },
  { id: 'D6', role: 'detail', title: '生活场景', ratio: '2:3', instruction: '展示一个与商品用途相符的自然场景，环境适量，主体可辨，场景道具不暗示为套装赠品。' },
  { id: 'D7', role: 'detail', title: '商品清单', ratio: '2:3', fact: 'package', instruction: '以清晰有序的商品陈列收尾，只有真实素材和提供清单支持的包装或配件可以出现。没有清单时只展示单个目标商品。' },
  { id: 'D8', role: 'detail', title: '服务信息', ratio: '2:3', fact: 'service', instruction: '以真实服务信息搭配商品构成简洁页面。没有已确认服务资料时改为商品整体和可见工艺展示，不编造认证、质检、评价或保障。' },
  { id: 'D9', role: 'detail', title: '详情收尾', ratio: '2:3', fact: 'sellingPoints', instruction: '使用与首屏统一的完整商品主视觉收束详情图，重申已确认商品信息，不增加新的营销承诺。' },
];

export function createCampaignSettings() {
  return { pack: 'full', detailCount: 7, excludedIds: [], useModel: true, useScene: false, textMode: 'none', facts: Object.fromEntries(CAMPAIGN_FACT_FIELDS.map(([key]) => [key, ''])), activeRun: null };
}
function object(value, label) { if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error(label + '格式无效'); }
function text(value, label, max = 4000) { if (typeof value !== 'string' || value.length > max) throw new Error(label + '格式无效'); return value; }
function choice(value, choices, label) { if (!choices.includes(value)) throw new Error(label + '选项无效'); return value; }
function identifier(value, label) { if (typeof value !== 'string' || !/^[A-Za-z0-9_-]{1,200}$/.test(value)) throw new Error(label + '无效'); return value; }
function timestamp(value, label, optional = false) { text(value, label, 100); if (!(optional && value === '') && !Number.isFinite(Date.parse(value))) throw new Error(label + '无效'); return value; }
function roles(value) {
  const allowed = ['model', 'product', 'scene'];
  if (!Array.isArray(value) || !value.length || value.length > allowed.length || value.some(role => !allowed.includes(role)) || new Set(value).size !== value.length || value.some((role, index) => index > 0 && allowed.indexOf(value[index - 1]) > allowed.indexOf(role))) throw new Error('套图引用角色无效');
  return [...value];
}
export function validateCampaignMetadata(value) {
  object(value, '套图记录');
  if (!getStylePreset(value.presetId)) throw new Error('套图风格无效');
  const shot = CAMPAIGN_PAGES.find(page => page.id === value.shotId);
  if (!shot || shot.role !== value.role) throw new Error('套图页面无效');
  if (!text(value.styleLock, '整套风格', 8000).trim()) throw new Error('整套风格不能为空');
  return { runId: identifier(value.runId, '套图编号'), shotId: shot.id, role: shot.role, title: text(value.title, '套图标题', 200), referenceRoles: roles(value.referenceRoles), styleLock: text(value.styleLock, '整套风格', 8000), presetId: value.presetId };
}
export function validateCampaignSettings(value, validateSource) {
  if (value === undefined) return createCampaignSettings();
  object(value, '套图配置'); object(value.facts, '商品资料');
  if (typeof value.useModel !== 'boolean') throw new Error('套图模特开关无效');
  if (value.useScene !== undefined && typeof value.useScene !== 'boolean') throw new Error('套图场景开关无效');
  if (!Array.isArray(value.excludedIds) || value.excludedIds.length > 14 || value.excludedIds.some(id => !CAMPAIGN_PAGES.some(page => page.id === id))) throw new Error('套图勾选页面无效');
  const settings = { pack: choice(value.pack, ['main', 'full', 'hero', 'detail'], '套图内容'), detailCount: choice(value.detailCount, [7, 8, 9], '详情页数量'), excludedIds: [...new Set(value.excludedIds)], useModel: value.useModel, useScene: value.useScene ?? false, textMode: choice(value.textMode, ['none', 'facts'], '商品文案'), facts: Object.fromEntries(CAMPAIGN_FACT_FIELDS.map(([key, label]) => [key, text(value.facts[key], label, 1200)])), activeRun: null };
  if (value.activeRun === null) return settings;
  const run = value.activeRun; object(run, '套图计划');
  if (!getStylePreset(run.presetId)) throw new Error('套图风格无效');
  const quality = choice(run.quality, ['1K', '2K'], '套图清晰度');
  const referenceRoles = roles(run.referenceRoles);
  if (!referenceRoles.includes('product')) throw new Error('套图必须包含商品参考');
  if (!Array.isArray(run.references) || run.references.length !== referenceRoles.length) throw new Error('套图参考素材无效');
  const references = run.references.map(validateSource);
  const productMarking = run.productMarking !== undefined ? validateProductMarking(run.productMarking) : null;
  const styleLock = text(run.styleLock, '整套风格', 8000);
  if (!styleLock.trim()) throw new Error('整套风格不能为空');
  if (!Array.isArray(run.shots) || !run.shots.length || run.shots.length > 14) throw new Error('套图计划页数无效');
  const ids = new Set();
  const shots = run.shots.map(shot => {
    object(shot, '套图页面');
    const page = CAMPAIGN_PAGES.find(item => item.id === shot.id);
    if (!page || ids.has(shot.id) || shot.role !== page.role || shot.ratio !== page.ratio) throw new Error('套图页面编号或比例无效');
    ids.add(shot.id);
    if (shot.size !== generationSize(page.ratio, quality)) throw new Error('套图页面尺寸无效');
    const status = choice(shot.status, ['pending', 'running', 'succeeded', 'failed', 'interrupted', 'removed'], '套图状态');
    const resultId = text(shot.resultId, '套图结果编号', 200);
    if (status === 'succeeded' && !resultId) throw new Error('已完成页面缺少结果编号');
    const prompt = text(shot.prompt, '页面提示词', 30000);
    if (!prompt.startsWith(styleLock + '\n\n')) throw new Error('页面缺少一致的整套风格');
    return { id: shot.id, role: page.role, title: text(shot.title, '页面标题', 200), ratio: page.ratio, prompt, size: shot.size, status, resultId, error: text(shot.error, '页面错误', 10000), submittedAt: timestamp(shot.submittedAt, '提交时间', true) };
  });
  settings.activeRun = { id: identifier(run.id, '套图编号'), createdAt: timestamp(run.createdAt, '套图创建时间'), presetId: run.presetId, quality, styleLock, referenceRoles, references, shots, ...(run.catalogProfile !== undefined ? { catalogProfile: validateCatalogProfile(run.catalogProfile) } : {}), ...(productMarking ? { productMarking } : {}) };
  return settings;
}

export function campaignPages(settings) {
  const ids = IMAGE_PURPOSES.find(purpose => purpose.id === settings.pack)?.pageIds || [];
  return ids.map(id => CAMPAIGN_PAGES.find(page => page.id === id)).filter(page => page && (page.role !== 'detail' || Number(page.id.slice(1)) <= settings.detailCount));
}

export function campaignShotResults(run, results = []) {
  const byId = new Map((results || []).filter(item => item?.id).map(item => [item.id, item]));
  return (run?.shots || [])
    .filter(shot => shot.resultId && byId.has(shot.resultId))
    .map(shot => {
      const result = byId.get(shot.resultId);
      return { ...result, name: shot.id + ' · ' + (result.name || shot.title) };
    });
}

export function campaignDetailResults(run, results = []) {
  const byId = new Map((results || []).filter(item => item?.id).map(item => [item.id, item]));
  return (run?.shots || [])
    .filter(shot => shot.role === 'detail' && shot.status === 'succeeded' && shot.resultId && byId.has(shot.resultId))
    .sort((a, b) => Number(a.id.slice(1)) - Number(b.id.slice(1)))
    .map(shot => {
      const result = byId.get(shot.resultId);
      return { ...result, name: shot.id + ' · ' + (result.name || shot.title) };
    });
}
function selected(section, label) {
  const item = section.results.find(result => result.id === section.activeResultId) || (!section.activeResultId ? section.results.at(-1) : null);
  if (!item) throw new Error('请先生成并选中' + label + '结果');
  return item.src;
}
const CAMPAIGN_NO_SCENE_PAGES = new Set(['H1', 'H4', 'D3']);
function campaignIdentity(config, catalogProfile) {
  const { useModel, useScene } = config;
  if (useModel && useScene) {
    return catalogProfile === 'underwear'
      ? '第一张参考仅提供成年模特身份，保持真实年龄、面容、肤色、发型及自然身体比例。第二张是唯一内衣商品依据。第三张参考只作为场景与环境依据，保持空间结构、透视、光线方向和氛围，场景不抢占商品主体。人物页面只使用该成年模特，商品单独展示页面不绘制模特。'
      : '第一张参考仅提供模特身份，保持年龄、面容、肤色、发型和自然身体比例。第二张是唯一商品依据。第三张参考只作为场景与环境依据，保持空间结构、透视、光线方向和氛围，场景不抢占商品主体。人物页面只使用此模特，穿着完整得体，展示最终服饰搭配效果；儿童保持合龄形象、自然姿态及完整不透明的日常穿着。服装自然穿在对应位置，鞋包等按真实用途展示。商品单独展示页面不绘制模特。';
  }
  if (useModel) {
    return catalogProfile === 'underwear'
      ? '第一张参考仅提供成年模特身份，保持真实年龄、面容、肤色、发型及自然身体比例。第二张是唯一内衣商品依据。人物页面只使用该成年模特，商品单独展示页面不绘制模特。'
      : '第一张参考仅提供模特身份，保持年龄、面容、肤色、发型和自然身体比例。第二张是唯一商品依据。人物页面只使用此模特，穿着完整得体，展示最终服饰搭配效果；儿童保持合龄形象、自然姿态及完整不透明的日常穿着。服装自然穿在对应位置，鞋包等按真实用途展示。商品单独展示页面不绘制模特。';
  }
  if (useScene) return '第一张参考图是唯一商品依据，只展示商品，不绘制人物或人体；服装使用自然平铺或立体陈列。第二张参考只作为场景与环境依据，保持空间结构、透视、光线方向和氛围；将商品自然置于场景中，商品始终是视觉主角，场景不抢占商品，不复制场景中的无关对象、文字或品牌。';
  return '唯一参考图是商品依据，只展示商品，不绘制人物或人体；服装使用自然平铺或立体陈列。';
}
function shotReferencePlan(run, shot) {
  const skipScene = CAMPAIGN_NO_SCENE_PAGES.has(shot.id);
  const referenceRoles = [];
  const images = [];
  run.referenceRoles.forEach((role, index) => {
    if (role === 'scene' && skipScene) return;
    referenceRoles.push(role);
    images.push(run.references[index]);
  });
  return { referenceRoles, images };
}
export function buildCampaignRun(project) {
  const config = validateCampaignSettings(project.creative.campaign, value => value);
  const preset = getStylePreset(project.creative.presetId);
  if (!preset) throw new Error('套图风格无效');
  assertStyleForPurpose(preset.id, config.pack);
  const whiteMain = config.pack === 'main' && preset.whiteBackground;
  if (whiteMain) { config.useModel = false; config.useScene = false; }
  const pages = campaignPages(config).filter(page => !config.excludedIds.includes(page.id));
  if (!pages.length) throw new Error('请至少选择一页');
  const referenceRoles = [config.useModel ? 'model' : null, 'product', config.useScene ? 'scene' : null].filter(Boolean);
  const references = referenceRoles.map(role => {
    if (role !== 'scene') return selected(project[role], role === 'model' ? '模特' : '商品');
    const scene = selectedSceneReference(project.scene);
    if (!scene) throw new Error('请先选择场景，或生成场景结果');
    return scene.src;
  });
  const catalogProfile = validateCatalogProfile(project.creative.catalogProfile);
  const productMarking = validateProductMarking(project.product?.config?.marking, project.product?.uploads || []);
  assertCatalogModel(project, catalogProfile, config.useModel);
  const styleLock = config.pack === 'main'
    ? `单张主图视觉规范：${preset.name}。${preset.prompt}\n准确保留商品原色与真实外观，以商品本身为主体，不采用连续详情或套图总览布局。`
    : campaignStyle(preset.id, config.textMode, config.useScene, project.creative.copy?.enabled && !!project.creative.copy.headline.trim()).text;
  const outline = config.pack === 'main' ? '本次只生成一张独立商品主图，不生成副图或详情页面。'
    : '本套页面分工：' + pages.map(page => `${page.id} ${page.title}`).join(' → ') + '。统一品牌视觉，但每页只完成自己的主题，不重复同一张白底商品照，也不把全套缩略图拼成一张总览。';
  const identity = campaignIdentity(config, catalogProfile);
  const grounding = '忠实保留目标商品颜色、外形、版型、品牌字样、材质纹理及可见工艺。不要复制参考图的多视图拼接布局。只绘制素材有依据的角度、配件和包装，不编造隐藏结构或商品规格。服装类单品（连体衣、上衣、裤子、裙子等）必须保持为完整单件；详情页可突出局部工艺，但必须同时保留完整单品可识别性，不得把局部拼成另一件上半身或下半身商品，也不得把一件连体衣拆成独立上下装。不添加无依据的价格、功效、成分、认证、销量、顾客评价、促销、保障或前后对比结果。';
  const quality = project.creative.quality;
  return {
    id: crypto.randomUUID(), createdAt: new Date().toISOString(), presetId: preset.id, quality, styleLock, references, referenceRoles,
    ...(catalogProfile !== 'general' ? { catalogProfile } : {}),
    ...(productMarking.mode === 'manual' ? { productMarking } : {}),
    shots: pages.map(page => {
      const supplied = config.textMode === 'facts' && page.id !== 'H1' && !whiteMain
        ? [config.facts.title.trim() || project.product.config.name.trim(), page.fact ? config.facts[page.fact].trim() : ''].filter(Boolean) : [];
      const copy = supplied.length ? `本页唯一允许排版的商品文案（按资料原文使用，不扩写事实）：\n${supplied.map(line => JSON.stringify(line)).join('\n')}` : '本页不添加额外文字、数字、图标或营销标签，保留商品原有品牌和包装字样。';
      const sceneNote = config.useScene
        ? (CAMPAIGN_NO_SCENE_PAGES.has(page.id)
          ? (page.id === 'H1' ? '本页不使用场景参考，保持纯白底且无环境装饰。' : '本页不使用场景参考，只表现商品本身细节。')
          : '本页必须依据场景参考图构建可辨认的环境与背景，保留地点、空间结构和光线方向；商品为主角，但不得把场景替换成纯色色板或白棚，道具不得暗示为套装或赠品。')
        : '';
      return { id: page.id, role: page.role, title: page.title, ratio: page.ratio, size: generationSize(page.ratio, quality), prompt: [styleLock, outline, `生成一张 ${page.ratio} ${page.role === 'hero' ? '方形商品主图或副图' : '竖版详情分图'}。页面编号 ${page.id}，主题：${page.title}。只输出这一张独立图片。`, identity, grounding, productMarkingPrompt(productMarking), `本页构图：${page.instruction}\n${campaignLayout(page.id).instruction}`, page.id === 'H1' || whiteMain ? '本页采用纯白底商品摄影，使用整套的照明规则，背景保持纯白且无装饰、文字、人物或场景；白底规则优先于风格色板。' : `风格方向：${preset.prompt}`, sceneNote, catalogPrompt(catalogProfile, config.useModel && !['H1', 'H4', 'D3'].includes(page.id)), copy].filter(Boolean).join('\n\n'), status: 'pending', resultId: '', error: '', submittedAt: '' };
    }),
  };
}
export function campaignShotRequest(run, shot) {
  const { referenceRoles, images } = shotReferencePlan(run, shot);
  return { prompt: shot.prompt, images, mode: 'compose', size: shot.size, n: 1, ...(run.catalogProfile ? { catalogProfile: run.catalogProfile } : {}), ...(run.productMarking?.mode === 'manual' ? { marking: run.productMarking } : {}), campaign: { runId: run.id, shotId: shot.id, role: shot.role, title: shot.title, referenceRoles, styleLock: run.styleLock, presetId: run.presetId } };
}

export function recoverCampaignRun(run) {
  for (const shot of run?.shots || []) if (shot.status === 'running') {
    shot.status = 'interrupted';
    shot.error = '页面提交后工作区被关闭或重新载入，结果未确认；请先检查平台记录，重做可能重复计费。';
  }
}
