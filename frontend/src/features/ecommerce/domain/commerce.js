import { normalizeAvatarStatus } from '../avatar-status.js';
import { generationSize, imageFailureMessage, classifyImageFailure } from './image-generation.js';
import { DEFAULT_STYLE_ID, STYLE_PRESETS, getStylePreset, validateStyleLibrary, assertStyleForPurpose, styleMatchesPurpose } from './style-presets.js';
import { createVideoSettings, validateVideoSettings } from './commerce-video.js';
import { createCampaignSettings, validateCampaignSettings, validateCampaignMetadata, recoverCampaignRun } from './campaign.js';
import { validateCatalogProfile, catalogPrompt, assertCatalogModel } from './catalog-prompts.js';
import { createProductMarking, productMarkingPrompt, validateProductMarking } from './product-marking.js';
import { SCENE_PRESETS, sceneSource, selectedSceneReference } from './scene-presets.js';
import { createMarketingCopy, validateMarketingCopy, validatePoster } from './marketing-copy.js';

const DATABASE_NAME = 'libtv-commerce-workspace';
const STORE_NAME = 'projects';
const WORKSPACE_KEY = 'current';
const MAX_UPLOADS = 12;
const MAX_RESULTS = 100;
const QUALITIES = ['1K', '2K'];
const COUNTS = [1, 2, 4];
const optionList = (values) => values.map((value) => ({ value, label: value }));

export const MODEL_OPTIONS = {
  ethnicity: optionList(['东亚', '东南亚', '南亚', '欧洲', '非洲', '中东', '拉丁美洲', '混血']),
  gender: optionList(['女性', '男性']),
  age: optionList(['成人', '儿童']),
  build: optionList(['匀称', '偏瘦', '健壮']),
  hair: optionList(['自然短发', '齐肩发', '自然长发', '卷发', '光头']),
};

export const PRODUCT_OPTIONS = {
  category: optionList(['服装', '鞋履', '包袋', '配饰', '美妆', '家居', '其他']),
  presentation: optionList(['平铺', '立体陈列']),
};

export const SCENE_OPTIONS = {
  type: optionList(['极简棚拍', '居家客厅', '咖啡店', '户外自然', '城市街景', '美妆台面', '自定义']),
  lighting: optionList(['柔和自然光', '明亮商业光', '暖调氛围光', '清冷高级光', '自定义']),
};

export const CREATIVE_STYLES = optionList(['极简棚拍', '时尚杂志', '生活场景', '高级灰调', '清新自然']);
export const CREATIVE_FORMATS = [
  { value: 'detail', label: '电商详情组图' },
  { value: 'tryon', label: '模特试穿展示' },
  { value: 'poster', label: '商品宣传海报' },
];

const MODEL_DEFAULTS = { ethnicity: '东亚', gender: '女性', age: '成人', build: '匀称', hair: '自然短发', notes: '' };
const PRODUCT_DEFAULTS = { name: '', category: '服装', presentation: '平铺', notes: '' };
const SCENE_DEFAULTS = { type: '极简棚拍', lighting: '柔和自然光', notes: '' };
const FIELD_LABELS = { ethnicity: '外观族裔', gender: '性别', age: '年龄段', build: '体型', hair: '发型', notes: '补充要求', name: '商品名称', category: '商品类别', presentation: '陈列方式', type: '场景类型', lighting: '光线氛围' };

export function createProject() {
  return {
    schema: 1,
    name: '未命名电商项目',
    model: { source: 'ai', config: { ...MODEL_DEFAULTS }, uploads: [], selectedUploadId: '', quality: '2K', count: 1, results: [], activeResultId: '' },
    product: { config: { ...PRODUCT_DEFAULTS, marking: createProductMarking() }, uploads: [], quality: '2K', count: 1, results: [], activeResultId: '' },
    scene: { config: { ...SCENE_DEFAULTS }, presetId: '', selectedUploadId: '', uploads: [], quality: '2K', count: 1, results: [], activeResultId: '' },
    creative: { mode: 'image', output: 'quick', copy: createMarketingCopy(), catalogProfile: 'general', campaign: createCampaignSettings(), format: 'detail', style: '极简棚拍', notes: '', presetId: DEFAULT_STYLE_ID, library: validateStyleLibrary(), video: createVideoSettings(), quality: '2K', count: 4, useModel: true, useProduct: true, useScene: false, results: [], activeResultId: '' },
    canvas: { viewport: null, positions: { model: { x: 60, y: 100 }, product: { x: 520, y: 100 }, scene: { x: 900, y: 100 }, creative: { x: 1280, y: 70 } } },
  };
}

function record(value, label) {
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error(`${label}格式无效`);
  return value;
}

function text(value, label, maxLength = 4000) {
  if (typeof value !== 'string' || value.length > maxLength) throw new Error(`${label}无效或过长`);
  return value;
}

function choice(value, choices, label) {
  if (!choices.includes(value)) throw new Error(`${label}选项无效`);
  return value;
}

function configuration(value, defaults, options) {
  record(value, '配置');
  return Object.fromEntries(Object.keys(defaults).map((key) => [key, options[key]
    ? choice(value[key], options[key].map((option) => option.value), FIELD_LABELS[key])
    : text(value[key], FIELD_LABELS[key])]));
}

function validBase64(encoded) {
  if (!encoded.length || encoded.length % 4) return false;
  const pad = encoded.endsWith('==') ? 2 : encoded.endsWith('=') ? 1 : 0;
  const body = encoded.length - pad;
  for (let index = 0; index < body; index++) {
    const code = encoded.charCodeAt(index);
    if (!(code >= 65 && code <= 90 || code >= 97 && code <= 122 || code >= 48 && code <= 57 || code === 43 || code === 47)) return false;
  }
  return pad < 2 || encoded.charCodeAt(body) === 61;
}

function validSource(value) {
  if (typeof value !== 'string' || !value || value.trim() !== value || /[\u0000-\u001f\\]/.test(value)) return false;
  if (value.startsWith('data:')) {
    const comma = value.indexOf(',');
    if (!/^data:image\/(png|jpeg|webp|gif);base64$/.test(value.slice(0, comma))) return false;
    return validBase64(value.slice(comma + 1));
  }
  try {
    if (/^https?:\/\//i.test(value)) {
      const remote = new URL(value);
      return !remote.username && !remote.password;
    }
    if (!/^\/(storage|admin\/ecommerce-box|demo|uploads|assets|scene-previews)\//.test(value)) return false;
    const url = new URL(value, 'http://localhost');
    const pathname = decodeURIComponent(url.pathname);
    return url.origin === 'http://localhost' && /^\/(storage|admin\/ecommerce-box|demo|uploads|assets|scene-previews)\//.test(pathname)
      && !pathname.split('/').some((part) => part === '.' || part === '..')
      && !/[%\\\u0000-\u001f]/.test(pathname) && /\.(png|jpe?g|webp|gif)$/i.test(pathname);
  } catch {
    return false;
  }
}

function source(value) {
  if (!validSource(value)) throw new Error('图片地址无效，仅支持 PNG、JPEG、WebP、GIF 图片数据或本地素材路径');
  return value;
}

function uploadList(value) {
  if (!Array.isArray(value) || value.length > MAX_UPLOADS) throw new Error('每个区域最多支持 12 张上传图片');
  const ids = new Set();
  return value.map((item) => media(item, ids));
}

function media(value, ids) {
  record(value, '图片');
  const id = text(value.id, '图片编号', 200);
  if (!id.trim() || ids.has(id)) throw new Error('项目包含无效或重复的图片编号');
  ids.add(id);
  if (!Number.isFinite(value.width) || !Number.isFinite(value.height) || value.width <= 0 || value.height <= 0) throw new Error('图片尺寸无效');
  return { id, name: text(value.name, '图片名称', 500), src: source(value.src), width: value.width, height: value.height };
}

function requestParameters(section, ratio = '3:2') {
  record(section, '生成配置');
  choice(section.quality, QUALITIES, '清晰度');
  choice(section.count, COUNTS, '生成数量');
  return { size: generationSize(ratio, section.quality), n: section.count };
}

const MODEL_LAYOUT = '输出一张 3:2 横版电商模特参考合成图。画面从左至右依次展示同一个人的正面全身、侧面全身、背面全身，以及清晰的人物脸部特写。前三个视图须从头到脚完整入镜，比例和站姿协调，第四个视图突出真实五官、皮肤和发型细节。所有视图严格保持同一人物身份、脸型、年龄、肤色、发型和身材。浅灰或白色干净摄影棚背景，均匀柔和照明，真实摄影质感，无文字标签、无水印。';
const MODEL_CLOTHING = '模特统一穿白色不透明、宽松合体的长袖长裤连体工装及白鞋，无标志，衣服完整覆盖躯干及四肢。采用普通工作服的面料与剪裁，自然站立，姿态端正，呈现日常服装目录摄影效果。';
const CHILD_CONSTRAINT = '如果人物为儿童，必须保持自然合龄的儿童面容和身体比例，穿着宽松、完全不透明且完整覆盖的长袖长裤连体工装，采用合龄的日常造型与自然中性站姿。';
const PRODUCT_LAYOUT = '输出一张 3:2 横版电商商品多视图细节合成图。将目标商品的整体视角与关键局部细节清晰排列，主体完整，使用干净白色背景和均匀真实的棚拍光线。不同视图必须来自同一款目标商品；若目标为明确可售的上下装套装，整体视图必须同时完整保留上衣与下装，不得只保留其中一件。连体衣、连衣裙、上衣、裤子、裙子等单件服装必须作为一件完整单品呈现：局部工艺、面料、缝线特写可以并存，但不得把局部裁成另一件“上半身”或“下半身”商品，也不得把一件连体衣拆成独立上衣与下装。忠实保留颜色、版型、形状、材质、纹理、缝线、印花、五金及真实品牌标志和字样，不能修改或新增品牌标志。';
const PRODUCT_GROUNDING = [
  '只根据已上传图片提取用户指定的目标商品，不凭空设计商品。',
  '若素材是真人试穿、街拍、健身房或生活场景，只剥离目标服饰/商品本身；必须删除人物、皮肤、头发、面孔、四肢、鞋子（除非目标就是鞋履）、包袋（除非目标就是包袋）、健身器械、家具、地面、墙面和其他背景。',
  '画面中同时出现多件衣物或物品时，严格按商品名称、类别和补充说明锁定目标。未提供名称或说明时，只有颜色、面料、版型和商品拼图信息均表明成套销售的上下装可以作为同一套商品保留；其他未关联的外套、内衣、配饰、道具一律不提取。',
  '输出不得包含穿戴者、人物、手、人体或人台；服装保留自身完整结构，以平铺或无人体立体陈列呈现。',
  '服装类单件商品必须保留完整可售结构；局部细节视图只能是同一件商品的特写，不能拼成额外的上半身或下半身单品。',
  '未在素材中显示的背面、内里、被遮挡结构或隐藏角度，不得编造。缺少某个视角时，以已知角度或可见细节补充版面。不能将不同商品混合成一款。无额外文字标签、无水印。',
].join('');
const SCENE_LAYOUT = '输出一张 3:2 横版电商场景参考图。画面只呈现可用于商品主图的环境与背景，构图留出清晰的商品摆放或展示区域，空间层次自然，光线方向统一，避免抢占主体。';
const SCENE_GROUNDING = '场景只用于提供环境、地面、背景、光线和氛围依据，不生成商品、人物、品牌、文字、价格、促销标签或虚构道具。保持场景干净、可复用、真实摄影质感；道具不得暗示为商品套装或赠品。';
function productTargetPrompt(config) {
  const name = config.name.trim();
  const notes = config.notes.trim();
  const autoDetectSet = config.category === '服装' && !name && !notes;
  const target = name || (notes ? '补充说明指定的目标商品' : autoDetectSet ? '上传图片中的主要可售服装单品或明确成套搭配' : '上传图片中的主要可售商品');
  return [
    `目标提取：只提取“${target}”。商品类别：${config.category}。陈列方式：${config.presentation}。`,
    notes ? `用户补充说明具有最高优先级，用于锁定要提取的具体单品并排除干扰物：${notes}` : autoDetectSet ? '未填写商品名称或提取说明。请先判断素材中的服装销售单元：若商品拼图、可见文字或上衣与下装的颜色、面料、剪裁和装饰明确表明它们是同一套可售搭配，则把上衣与下装共同作为一个完整套装提取；不得因上半身更醒目而遗漏下装。若无法确认成套关系，只提取最主要、完整、可独立售卖的单件服装。' : '未填写补充说明时，提取画面中最主要、完整、可独立售卖的那一件商品，忽略人物与环境。',
    config.category === '服装' ? autoDetectSet ? '类别为服装时，明确套装的整体视图须同时呈现完整上衣与下装；连体衣、上衣、裤子等单件须作为完整单品，局部工艺可作特写但不得拆成上半身/下半身另一件商品；不要提取人物、健身器材、鞋袜或与套装无关的其他衣物。' : '类别为服装时，只输出该件服装本体，连体衣、上衣、裤子须作为完整单品；局部工艺可作特写，但不得把局部拼成上半身/下半身另一件商品；不要提取人物、健身器材、鞋袜或未指定的其他衣物。' : '',
  ].filter(Boolean).join('\n');
}
function sceneDirectionPrompt(config) {
  const notes = config.notes.trim();
  const customType = config.type === '自定义';
  const customLight = config.lighting === '自定义';
  if (customType && !notes) throw new Error('自定义场景请填写场景描述');
  if (customLight && !notes) throw new Error('自定义光线请在场景描述中写明光线要求');
  if (notes) {
    return [
      `用户场景描述优先执行：${notes}`,
      customType ? '场景类型为自定义，以上述描述为唯一空间与氛围依据。' : `预设类型仅作弱参考：${config.type}；与用户描述冲突时以用户描述为准。`,
      customLight ? '光线按用户描述执行。' : `光线氛围参考：${config.lighting}；与用户描述冲突时以用户描述为准。`,
    ].join('\n');
  }
  return `场景类型：${config.type}。光线氛围：${config.lighting}。`;
}

export function buildModelRequest(section) {
  const parameters = requestParameters(section);
  const config = configuration(section.config, MODEL_DEFAULTS, MODEL_OPTIONS);
  choice(section.source, ['ai', 'upload'], '模特来源');
  const uploads = uploadList(section.uploads);
  let identity;
  const images = [];
  if (section.source === 'upload') {
    const selected = uploads.find((item) => item.id === section.selectedUploadId);
    if (!selected) throw new Error('请先上传并选中一位模特');
    images.push(selected.src);
    identity = '以唯一上传参考图中的人物为身份依据，保留原人物的五官、年龄、性别、肤色、发型和身材。参考图中若出现多人，只处理主体明确的一位，禁止融合多个身份。除统一基础服装与指定修改外，不改变人物身份。';
  } else {
    identity = `生成一位虚构电商模特：外观族裔参考为${config.ethnicity}，性别为${config.gender}，年龄段为${config.age}，体型为${config.build}，发型为${config.hair}。`;
  }
  return {
    prompt: [MODEL_LAYOUT, identity, MODEL_CLOTHING, CHILD_CONSTRAINT, config.notes.trim() ? `补充要求（不得违背上述人物一致性与服装要求）：${config.notes.trim()}` : ''].filter(Boolean).join('\n\n'),
    modelAge: section.source === 'ai' ? config.age : 'unknown',
    images,
    mode: 'compose',
    ...parameters,
  };
}

export function buildProductRequest(section) {
  const parameters = requestParameters(section);
  const config = configuration(section.config, PRODUCT_DEFAULTS, PRODUCT_OPTIONS);
  const uploads = uploadList(section.uploads);
  const marking = validateProductMarking(section.config?.marking, uploads);
  if (!uploads.length) throw new Error('请先上传商品图片，商品生成必须以实物素材为依据');
  if (marking.mode === 'manual' && !marking.marks.length) throw new Error('手动标记模式请至少框选一个商品区域');
  const markedSource = marking.mode === 'manual' && marking.marks.length && marking.imageId ? marking.annotatedSrc || '' : '';
  if (markedSource) source(markedSource);
  const images = uploads.map(item => item.id === marking.imageId && markedSource ? markedSource : item.src);
  return {
    prompt: [PRODUCT_LAYOUT, productTargetPrompt(config), productMarkingPrompt(marking), PRODUCT_GROUNDING].filter(Boolean).join('\n\n'),
    images: [...new Set(images)],
    mode: 'compose',
    ...(marking.mode === 'manual' ? { marking } : {}),
    ...parameters,
  };
}

export function buildSceneRequest(section) {
  const parameters = requestParameters(section);
  const config = configuration(section.config, SCENE_DEFAULTS, SCENE_OPTIONS);
  const source = section.source === undefined ? (section.uploads?.length ? 'upload' : 'presets') : choice(section.source, ['presets', 'upload', 'custom'], '场景来源');
  const uploads = source === 'upload' ? uploadList(section.uploads) : [];
  const direction = sceneDirectionPrompt(config);
  const identity = uploads.length
    ? '以已上传场景参考图中的空间结构、透视、色调和光线方向为依据，只做必要的清理与统一，不复制其中的商品、人物或文字；用户场景描述优先于参考图中的次要杂物。'
    : config.notes.trim() || config.type === '自定义'
      ? '按用户场景描述生成适合电商商品展示的真实环境，保持空间结构简洁、主体区域明确。'
      : '根据所选场景类型生成适合电商商品展示的真实环境，保持空间结构简洁、主体区域明确。';
  return {
    prompt: [SCENE_LAYOUT, direction, identity, SCENE_GROUNDING].join('\n\n'),
    images: [...new Set(uploads.map(item => item.src))],
    mode: 'compose',
    ...parameters,
  };
}

const CREATIVE_GROUNDING = '第一张参考图只作为模特身份依据，保持其五官、年龄、肤色、发型和自然身体比例；不要复制参考图中的三视图拼接布局。第二张参考图是唯一商品依据，保持该商品的版型、颜色、品牌标志和真实字样、材质、纹理、缝线及可见细节。生成穿着完整、姿态自然的电商成品展示照片，只呈现最终搭配效果。服装类商品作为对应位置的成品服饰自然穿着，其余部位搭配简洁得体的日常服装；鞋履穿在脚上，包袋自然手持或背戴，其他非服装商品用符合用途的自然展示方式，禁止把鞋、包或非服装商品变成衣服。不可编造缺失视角、被遮挡结构或未提供的商品细节，不改变品牌、不添加不存在的配件或售卖套装。若模特为儿童，必须保持合龄面容、儿童身体比例及自然中性姿势，只使用合龄、完整覆盖且不透明的日常穿着。';
const CREATIVE_PRODUCT_GROUNDING = '唯一参考图是商品依据，保持该商品的版型、颜色、品牌标志和真实字样、材质、纹理、缝线及可见细节。只展示商品，不绘制人物、人体、手或人台；服装使用自然平铺或无人体的立体陈列。不可编造缺失视角、被遮挡结构或未提供的商品细节，不改变品牌、不添加不存在的配件或售卖套装。';
const CREATIVE_MODEL_GROUNDING = '唯一参考图只作为模特身份依据，保持其五官、年龄、肤色、发型和自然身体比例；不要复制参考图中的三视图拼接布局。生成穿着完整、姿态自然的电商人物展示照片。若模特为儿童，必须保持合龄面容、儿童身体比例及自然中性姿势，只使用合龄、完整覆盖且不透明的日常穿着。不编造未提供的商品、品牌或售卖套装。';
const CREATIVE_SCENE_GROUNDING = '唯一参考图只作为场景与环境依据，保持空间结构、透视关系、光线方向和氛围；不复制其中的商品、人物、品牌或文字。场景为商品展示服务，保留可放置商品的清晰区域。';
const CREATIVE_PAGES = [
  { title: '商品主视觉', instruction: '聚焦模特与目标商品的完整主视觉，以商品识别度为核心，主体清楚且有视觉层次。', productInstruction: '聚焦目标商品的完整主视觉，以商品识别度为核心，主体清楚且有视觉层次。不出现人物。', modelInstruction: '聚焦模特完整主视觉，以人物识别度为核心，主体清楚且有视觉层次。' },
  { title: '全身展示', instruction: '完整展示模特从头到脚的自然站姿，清楚呈现服装上身效果或非服装商品与人物的真实比例。仅使用素材可支持的正面或已知角度。', productInstruction: '完整展示商品整体外形与比例，使用平铺或立体陈列。仅使用素材可支持的正面或已知角度。不出现人物。', modelInstruction: '完整展示模特从头到脚的自然站姿。仅使用素材可支持的正面或已知角度。' },
  { title: '商品细节', instruction: '聚焦商品素材中明确可见的纹理、工艺与局部细节，以可信的近景呈现；人物可以局部入镜，不强行补足素材未展示的结构。', productInstruction: '聚焦商品素材中明确可见的纹理、工艺与局部细节，以可信的近景呈现；不强行补足素材未展示的结构。不出现人物。', modelInstruction: '聚焦面容、发型、服装剪裁或姿态中明确可见的细节，以可信的近景呈现。' },
  { title: '搭配场景', instruction: '在符合所选风格和实际用途的自然生活或棚拍场景中展示模特与商品，场景不抢占商品主体，不将道具暗示为附赠或套装商品。', productInstruction: '在符合所选风格和实际用途的自然生活或棚拍场景中展示商品，场景不抢占商品主体，不将道具暗示为附赠或套装商品。不出现人物。', modelInstruction: '在符合所选风格的自然生活或棚拍场景中展示模特，场景不抢占人物主体。' },
];

function flag(value, fallback, label) {
  if (value === undefined) return fallback;
  if (typeof value !== 'boolean') throw new Error(label);
  return value;
}

function creativeRoles(value) {
  const allowed = ['model', 'product', 'scene'];
  if (!Array.isArray(value) || !value.length || value.length > allowed.length || value.some(role => !allowed.includes(role)) || new Set(value).size !== value.length || value.some((role, index) => index > 0 && allowed.indexOf(value[index - 1]) > allowed.indexOf(role))) throw new Error('创作引用角色无效');
  return [...value];
}

function requestRoles(request) {
  return request.campaign?.referenceRoles || request.referenceRoles || ['model', 'product'];
}

function pageInstruction(page, useModel, useProduct, useScene) {
  const withScene = useScene ? '必须使用场景参考图构建可辨认的环境与背景，商品为主角，不得改成白棚或纯色底。' : '';
  if (useModel && useProduct && useScene) return `${page.instruction}${withScene}`;
  if (useProduct && useScene) return `${page.productInstruction}${withScene}`;
  if (useModel && useProduct) return page.instruction;
  if (useProduct) return page.productInstruction;
  if (useScene) return `以场景参考图为环境依据，${page.modelInstruction || page.productInstruction}${withScene}`;
  return page.modelInstruction;
}

function creativeGrounding(catalogProfile, useModel, useProduct, useScene) {
  if (useModel && useProduct && useScene) {
    return '第一张参考图只作为模特身份依据，保持其五官、年龄、肤色、发型和自然身体比例。第二张参考图是唯一商品依据，保持商品真实版型、颜色、品牌标志和可见细节。第三张参考图只作为场景与环境依据，保持空间结构、透视、光线方向和氛围。生成以商品为主体的电商成品展示图，场景不抢占商品，不复制任何参考图中的文字或无关对象。';
  }
  if (useModel && useProduct) {
    return catalogProfile === 'underwear'
      ? '第一张参考仅提供成年模特身份，第二张是唯一商品依据。保持人物面容、年龄与自然身体比例，保持商品真实款式、颜色、材质、品牌与可见细节，不复制参考图的多视图布局，不编造被遮挡结构。'
      : CREATIVE_GROUNDING;
  }
  if (useProduct && useScene) return '第一张参考图是唯一商品依据，保持商品版型、颜色、品牌标志、材质和可见细节。第二张参考图只作为场景与环境依据，保持空间结构、透视、光线方向和氛围。将商品自然置于场景中，商品始终是视觉主角，不出现人物或人体，不复制场景中的无关对象、文字或品牌。';
  if (useModel && useScene) return '第一张参考图只作为模特身份依据，保持其五官、年龄、肤色、发型和自然身体比例。第二张参考图只作为场景与环境依据，保持空间结构、透视、光线方向和氛围。生成自然的电商人物场景图，不编造商品、品牌或营销事实。';
  if (useScene) return CREATIVE_SCENE_GROUNDING;
  return useProduct ? CREATIVE_PRODUCT_GROUNDING : CREATIVE_MODEL_GROUNDING;
}

function editReferencePrompt(original) {
  const roles = requestRoles(original);
  if (original.autoModel) return '第一张成品中的虚拟模特是唯一人物身份依据，保持其面容、发型、年龄、体型、穿戴关系与姿势。后续图片按原顺序提供商品与可选场景参考，只校正真实商品和环境，不更换人物、不移除人物、不编造商品细节。';
  if (roles.includes('scene')) {
    if (roles.includes('model') && roles.includes('product')) return '第二张图是原模特身份参考，第三张图是原商品参考，第四张图是原场景参考，只用于校正身份、商品和环境。保持本页原有构图，场景不抢占商品主体，不增加人物、商品或未提供的道具。';
    if (roles.includes('product')) return '第二张图是最初绑定的唯一商品参考，第三张图是最初绑定的唯一场景参考，只用于校正商品真实信息与环境。保持本页原有商品构图，不增加人物、品牌字样或未提供的道具。';
    if (roles.includes('model')) return '第二张图是最初绑定的模特身份参考，第三张图是最初绑定的场景参考，只用于校正人物与环境。保持本页原有人物构图，不增加商品、品牌或未提供的道具。';
    return '第二张图是最初绑定的唯一场景参考，只用于校正环境、透视与光线。保持本页原有场景构图，不增加人物、商品或文字。';
  }
  if (roles.length === 1 && roles[0] === 'product') {
    return '第二张图是最初绑定的唯一商品参考，只用于校正真实商品信息。保持商品摄影，不添加人物，不编造缺失商品细节、品牌字样或营销事实。';
  }
  if (roles.length === 1) {
    return original.catalogProfile === 'underwear'
      ? '第二张图是最初绑定的唯一成年模特身份参考，只用于校正原有身份。保持本页原有人物构图，不增加商品或改变人物身份。'
      : '第二张图是最初绑定的唯一模特身份参考，只用于校正原有身份。不编造未提供的商品细节。儿童必须保持自然合龄的外观、穿着和中性姿势。';
  }
  if (original.catalogProfile === 'underwear') {
    return '第二张图是原模特身份参考，第三张图是原商品参考，仅校正原有身份和商品。保持本页原有人物或无人商品构图，不增加人物，不改变商品版型、覆盖范围、颜色及真实细节。';
  }
  return '第二张图是最初绑定的模特身份参考，第三张图是最初绑定的商品参考，只用于校正身份与真实商品信息。服装保持正确穿在模特身上，非服装保持符合用途的自然展示，不能把鞋包变成衣服。不编造缺失商品细节、品牌字样或营销事实。儿童必须保持自然合龄的外观、穿着和中性姿势。';
}

function creativeSettings(value) {
  record(value, '创作配置');
  requestParameters(value, '2:3');
  const presetId = value.presetId === undefined ? DEFAULT_STYLE_ID : value.presetId;
  if (!getStylePreset(presetId)) throw new Error('图片风格预设无效');
  return {
    mode: choice(value.mode, ['image', 'video'], '创作模式'),
    format: choice(value.format, CREATIVE_FORMATS.map((option) => option.value), '创作类型'),
    style: choice(value.style, CREATIVE_STYLES.map((option) => option.value), '创作风格'),
    notes: text(value.notes, '创作要求'),
    copy: validateMarketingCopy(value.copy),
    quality: value.quality,
    count: value.count,
    presetId,
    library: validateStyleLibrary(value.library),
    video: validateVideoSettings(value.video),
    output: choice(value.output ?? 'quick', ['quick', 'campaign'], '图片输出方式'),
    campaign: validateCampaignSettings(value.campaign, source),
    catalogProfile: validateCatalogProfile(value.catalogProfile),
    useModel: flag(value.useModel, true, '创作模特开关无效'),
    useProduct: flag(value.useProduct, true, '创作商品开关无效'),
    useScene: flag(value.useScene, false, '创作场景开关无效'),
  };
}

function selectedResult(section, label) {
  record(section, label);
  if (!Array.isArray(section.results) || !section.results.length) throw new Error(`请先生成${label}结果`);
  const activeId = text(section.activeResultId, `当前${label}结果编号`, 200);
  const selected = activeId ? section.results.find((item) => item.id === activeId) : section.results.at(-1);
  if (!selected) throw new Error(`当前选中的${label}结果不存在，请重新选择`);
  return source(selected.src);
}

export function buildCreativeRequests(project) {
  record(project, '电商项目');
  const settings = creativeSettings(project.creative);
  const autoModel = settings.format === 'tryon' && settings.useProduct && !settings.useModel;
  if (settings.mode === 'video') throw new Error('视频请使用视频生成接口');
  if (!settings.useModel && !settings.useProduct) throw new Error('请至少引用模特或商品');
  const referenceRoles = [settings.useModel ? 'model' : null, settings.useProduct ? 'product' : null, settings.useScene ? 'scene' : null].filter(Boolean);
  const images = [];
  if (settings.useModel) images.push(selectedResult(project.model, '模特'));
  if (settings.useProduct) images.push(selectedResult(project.product, '商品'));
  if (settings.useScene) {
    const scene = selectedSceneReference(project.scene);
    if (!scene) throw new Error('请先选择场景，或生成场景结果');
    images.push(source(scene.src));
  }
  assertCatalogModel(project, settings.catalogProfile, settings.useModel);
  const preset = getStylePreset(settings.presetId);
  assertStyleForPurpose(preset.id, settings.format);
  const productMarking = validateProductMarking(project.product?.config?.marking, project.product?.uploads || []);
  const markingPrompt = productMarkingPrompt(productMarking);
  const continuity = settings.useModel && settings.useProduct && settings.useScene
    ? '整组保持同一人物、商品、场景、色调和信息层级，每页分别表达一个重点。'
    : settings.useProduct && settings.useScene
      ? '整组保持同一商品、场景、色调和信息层级，每页分别表达一个重点。不出现人物。'
      : settings.useModel && settings.useProduct
        ? '整组保持同一人物、商品、色调和信息层级，每页分别表达一个重点。'
        : settings.useProduct
          ? '整组保持同一商品、色调和信息层级，每页分别表达一个重点。不出现人物。'
          : settings.useModel && settings.useScene
            ? '整组保持同一人物、场景、色调和信息层级，每页分别表达一个重点。不编造商品。'
            : settings.useScene
              ? '整组保持同一场景、色调和信息层级，每页分别表达一个重点。'
              : '整组保持同一人物、色调和信息层级，每页分别表达一个重点。';
  return CREATIVE_PAGES.slice(0, settings.count).map((page, index) => ({
    prompt: [
      settings.format === 'tryon'
        ? `生成一张 2:3 竖版模特试穿展示图，第 ${index + 1} 张。服饰正确穿着，保持商品结构与模特身份，展示自然的上身效果和真实面料。`
        : settings.format === 'poster'
          ? `生成一张 2:3 竖版商品宣传海报，第 ${index + 1} 张。突出唯一商品主角，有明确视觉焦点和留白。仅使用用户明确提供的文案，不编造促销或商品事实。`
          : `生成一张 2:3 竖版电商详情组图。这是本组第 ${index + 1} 页，共 ${settings.count} 页；本页主题：${page.title}。只输出本页的一张完整图片，不把整组页面拼成一张图。`,
      autoModel
        ? `第一张参考图是唯一商品依据，准确保留版型、材质、颜色、品牌与可见细节。根据商品款式自动搭配一位合适的成年虚拟模特，直接生成最终试穿成品，无需另出人物设定图。服装正确穿着，鞋包配饰按其真实用途展示，不把其他商品变成衣服。模特自然站姿与日常合宜搭配，商品为画面主体，不复制商品原图中的人物。${project.model.config.notes ? `人物偏好：${project.model.config.notes}` : ''}${settings.useScene ? '第二张参考图只用于场景环境，保持其空间、光线与氛围。' : ''}`
        : creativeGrounding(settings.catalogProfile, settings.useModel, settings.useProduct, settings.useScene),
      `${autoModel ? '整组保持同一虚拟模特、商品、色调和信息层级。自然展示正确穿戴效果与真实商品细节，不拼成人物三视图。' : `${continuity}\n本页重点：${pageInstruction(page, settings.useModel, settings.useProduct, settings.useScene)}`}\n图片风格预设：${preset.name}。\n${preset.prompt}`,
      settings.useScene ? '已引用场景参考图时，环境与背景必须以场景参考为准，风格预设只约束光线、色调与构图节奏，不得把场景改成纯白棚拍或纯色铺底。' : '',
      catalogPrompt(settings.catalogProfile, settings.useModel || autoModel),
      markingPrompt,
      settings.notes.trim() ? `用户创作要求（不改变商品事实）：${settings.notes.trim()}` : '',
      settings.catalogProfile === 'underwear' && settings.useModel && settings.useProduct && index === 2 ? '本页仅展示独立商品的真实局部细节，不出现模特或人体。' : '',
      '不添加水印和额外文字，不编造价格、促销、功效、成分、认证、销量或其他商品宣传事实。',
    ].filter(Boolean).join('\n\n'),
    images: [...images],
    mode: 'compose',
    size: generationSize('2:3', settings.quality),
    n: 1,
    referenceRoles: [...referenceRoles],
    ...(autoModel ? { autoModel: true } : {}),
    ...(settings.catalogProfile !== 'general' ? { catalogProfile: settings.catalogProfile } : {}),
    ...(productMarking.mode === 'manual' ? { marking: productMarking } : {}),
  }));
}

export function buildEditRequest(kind, section, baseResult, editPrompt) {
  choice(kind, ['model', 'product', 'scene', 'creative'], '编辑区域');
  const parameters = requestParameters(section, kind === 'creative' ? '2:3' : '3:2');
  if (kind === 'creative' && creativeSettings(section).mode === 'video') throw new Error('图片微调请切换到图片模式');
  const instruction = text(editPrompt, '微调要求').trim();
  if (!instruction) throw new Error('请填写要微调的内容');
  record(baseResult, '待编辑图片');
  const baseSource = source(baseResult.src);
  const images = [baseSource];
  let creativeOriginal;
  if (kind === 'product' || kind === 'scene' || kind === 'creative') {
    const originalRequest = validatedRequest(baseResult.request);
    if (kind === 'creative') creativeOriginal = originalRequest;
    const originals = originalRequest.mode === 'edit' ? originalRequest.images.slice(1) : originalRequest.images;
    if (kind === 'product' && !originals.length) throw new Error('当前商品版本缺少原始上传素材，请重新上传并生成商品多视图');
    if (kind === 'creative' && originals.length !== requestRoles(originalRequest).length) throw new Error('当前创作版本缺少原始模特和商品参考，请重新生成创作图片');
    for (const image of originals) if (kind === 'creative' || !images.includes(image)) images.push(image);
    if (images.length > MAX_UPLOADS + 1) throw new Error('该版本的原始商品参考图超出上限，请重新上传商品素材');
  }
  if (kind === 'creative') {
    return {
      prompt: [
        creativeOriginal.campaign?.styleLock || '',
        `基于第一张创作结果图片做局部微调。第一张图是唯一版式与构图基准，${creativeOriginal.size.split('x')[0] === creativeOriginal.size.split('x')[1] ? '保持原商品主图的方形结构' : '保持原海报或电商详情页的竖版结构'}、当前页面主题、文案及视觉风格。只修改下方明确提出的内容，其余人物身份、商品形状、版型、颜色、品牌、纹理、比例与细节保持不变。不改成人物设定图或三视图。`,
        editReferencePrompt(creativeOriginal),
        catalogPrompt(creativeOriginal.catalogProfile, creativeOriginal.autoModel || requestRoles(creativeOriginal).includes('model')),
        `微调要求：${instruction}`,
      ].filter(Boolean).join('\n\n'),
      images,
      mode: 'edit',
      size: creativeOriginal.size,
      n: 1,
      ...(creativeOriginal.campaign ? { campaign: creativeOriginal.campaign } : {}),
      ...(creativeOriginal.design ? { design: { ...creativeOriginal.design } } : {}),
      ...(creativeOriginal.referenceRoles ? { referenceRoles: [...creativeOriginal.referenceRoles] } : {}),
      ...(creativeOriginal.autoModel ? { autoModel: true } : {}),
      ...(creativeOriginal.poster ? { poster: creativeOriginal.poster } : {}),
      ...(creativeOriginal.catalogProfile ? { catalogProfile: creativeOriginal.catalogProfile } : {}),
      ...(creativeOriginal.productImages ? { productImages: [...creativeOriginal.productImages] } : {}),
      ...(creativeOriginal.styleReference ? { styleReference: creativeOriginal.styleReference } : {}),
    };
  }
  return {
    prompt: [
      '基于第一张图片做局部微调。第一张图片是本次编辑的唯一构图与整体结果基准，保持横版多视图布局。只修改下方明确提出的内容，未要求修改的特征、比例、颜色、材质、背景与细节保持不变。',
      kind === 'model'
        ? `保持原结果中同一人物的身份、五官与各视图一致性，不引入其他模特。${MODEL_LAYOUT}\n${MODEL_CLOTHING}\n${CHILD_CONSTRAINT}`
        : kind === 'scene'
          ? `后续图片仅用作场景环境参考，不改变当前结果中的空间构图与光线关系。${SCENE_LAYOUT}\n${SCENE_GROUNDING}`
          : `后续图片仅用作真实商品信息参考，不改变当前结果中的目标商品。${PRODUCT_LAYOUT}\n${PRODUCT_GROUNDING}`,
      `微调要求：${instruction}`,
    ].join('\n\n'),
    images,
    mode: 'edit',
    ...parameters,
    ...(kind === 'model' ? { modelAge: baseResult.request?.modelAge || 'unknown' } : {}),
  };
}

function validatedRequest(value) {
  record(value, '原始生成请求');
  const prompt = text(value.prompt, '原始提示词', 30000);
  if (!prompt.trim()) throw new Error('原始提示词不能为空');
  const mode = choice(value.mode, ['compose', 'edit'], '原始生成模式');
  if (!Array.isArray(value.images) || value.images.length > MAX_UPLOADS + Number(mode === 'edit')) throw new Error('原始参考图片格式无效');
  if (mode === 'edit' && !value.images.length) throw new Error('原始编辑请求缺少基准图片');
  const campaign = value.campaign ? validateCampaignMetadata(value.campaign) : null;
  if (value.design !== undefined) {
    record(value.design, '图片用途与风格');
    if (!styleMatchesPurpose(value.design.presetId, value.design.purpose)) throw new Error('图片风格与用途不匹配，请重新选择');
    if (value.design.layout !== undefined && (value.design.layout !== 'template' || value.design.purpose !== 'detail')) throw new Error('图片模板类型无效');
  }
  if (campaign && (value.n !== 1 || !QUALITIES.some(quality => value.size === generationSize(campaign.role === 'hero' ? '1:1' : '2:3', quality)))) throw new Error('套图原始请求数量或比例无效');
  const referenceRoles = value.referenceRoles !== undefined ? creativeRoles(value.referenceRoles) : null;
  const productImages = value.productImages === undefined ? [] : uploadReferences(value.productImages, value.images.length, mode);
  if (value.styleReference !== undefined && !STYLE_PRESETS.some(preset => preset.cover === value.styleReference)) throw new Error('版式参考无效');
  if (new Set([...value.images, ...productImages, ...(value.styleReference ? [value.styleReference] : [])]).size > MAX_UPLOADS + Number(mode === 'edit')) throw new Error('参考图片超过上限');
  return {
    prompt,
    images: value.images.map(source),
    mode,
    size: choice(value.size, QUALITIES.flatMap((quality) => ['1:1', '3:2', '2:3'].map((ratio) => generationSize(ratio, quality))), '原始生成尺寸'),
    n: choice(value.n, COUNTS, '原始生成数量'),
    ...(campaign ? { campaign } : {}),
    ...(value.design ? { design: { purpose: value.design.purpose, presetId: value.design.presetId, ...(value.design.layout ? { layout: value.design.layout } : {}) } } : {}),
    ...(referenceRoles ? { referenceRoles } : {}),
    ...(value.autoModel !== undefined ? { autoModel: flag(value.autoModel, false, '自动模特标记无效') } : {}),
    ...(value.poster !== undefined ? { poster: validatePoster(value.poster) } : {}),
    ...(productImages.length ? { productImages } : {}),
    ...(value.styleReference ? { styleReference: value.styleReference } : {}),
    ...(value.catalogProfile !== undefined ? { catalogProfile: validateCatalogProfile(value.catalogProfile) } : {}),
    ...(value.marking !== undefined ? { marking: validateProductMarking(value.marking) } : {}),
    ...(value.modelAge !== undefined ? { modelAge: choice(value.modelAge, ['成人', '儿童', 'unknown'], '原模特年龄段') } : {}),
    ...(value.storyCharacterId !== undefined ? { storyCharacterId: choice(value.storyCharacterId, ['c1', 'c2', 'c3', 'c4'], '剧情人物编号'), storyCharacterDescription: text(value.storyCharacterDescription, '剧情人物描述', 350) } : {}),
  };
}

function uploadReferences(images, primaryCount, mode) {
  if (!Array.isArray(images) || images.length + primaryCount > MAX_UPLOADS + Number(mode === 'edit')) throw new Error('补充商品参考图片超过上限');
  return [...new Set(images.map(source))];
}

function avatarMetadata(value) {
  record(value, '人像审核资料');
  const result = {};
  for (const key of ['asset_id', 'asset_url', 'group_id', 'asset_type', 'source_url', 'status', 'name', 'description', 'error', 'message', 'group_name', 'submitted_at', 'checked_at']) {
    if (value[key] != null) result[key] = text(value[key], '人像审核资料', 10000);
  }
  if (result.status) result.status = normalizeAvatarStatus(result.status) || result.status;
  return result;
}

function resultFields(kind, value, ids) {
  if (!Array.isArray(value.results) || value.results.length > MAX_RESULTS) throw new Error('每个区域最多保存 100 张结果图片');
  const results = value.results.map((item) => {
    const result = media(item, ids);
    const createdAt = text(item.createdAt, '生成时间', 100);
    if (!createdAt || !Number.isFinite(Date.parse(createdAt))) throw new Error('生成时间无效');
    const request = validatedRequest(item.request);
    if (kind === 'product' && !request.images.length) throw new Error('商品原始请求缺少上传素材');
    if (kind === 'creative' && request.images.length !== requestRoles(request).length + Number(request.mode === 'edit')) throw new Error('创作原始请求缺少模特或商品参考');
    return {
      ...result,
      createdAt,
      operation: choice(item.operation, ['generate', 'edit'], '生成操作'),
      request,
      ...(item.avatar ? { avatar: avatarMetadata(item.avatar) } : {}),
    };
  });
  const activeResultId = text(value.activeResultId, '当前结果编号', 200);
  if (activeResultId && !results.some((item) => item.id === activeResultId)) throw new Error('当前结果不存在');
  const recoveries = value.recoveries || [];
  if (!Array.isArray(recoveries) || recoveries.length > 100) throw new Error('下载恢复记录超过上限');
  return { results, activeResultId, ...(recoveries.length ? { recoveries: recoveries.map(item => {
    if (!/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(item.id || '')) throw new Error('下载恢复编号无效');
    if (!Array.isArray(item.received) || item.received.some(index => !Number.isInteger(index) || index < 0 || index > 100)) throw new Error('下载恢复进度无效');
    if (item.taskId !== undefined && item.taskId !== item.id) throw new Error('生成任务编号与记录不一致');
    return { id: item.id, ...(item.taskId ? { taskId: item.taskId } : {}), request: validatedRequest(item.request), error: text(item.error, '下载错误', 10000), received: [...new Set(item.received)] };
  }) } : {}) };
}

function validatedSection(kind, value, ids) {
  record(value, '项目区域');
  const isModel = kind === 'model';
  const isProduct = kind === 'product';
  const defaults = isModel ? MODEL_DEFAULTS : isProduct ? PRODUCT_DEFAULTS : SCENE_DEFAULTS;
  const options = isModel ? MODEL_OPTIONS : isProduct ? PRODUCT_OPTIONS : SCENE_OPTIONS;
  const config = configuration(value.config, defaults, options);
  requestParameters(value);
  if (!Array.isArray(value.uploads) || value.uploads.length > MAX_UPLOADS) throw new Error('每个区域最多支持 12 张上传图片');
  const uploads = value.uploads.map((item) => media(item, ids));
  const result = { config: isProduct ? { ...config, marking: validateProductMarking(value.config?.marking, uploads) } : config, uploads, quality: value.quality, count: value.count, ...resultFields(kind, value, ids) };
  if (isModel) {
    result.source = choice(value.source, ['ai', 'upload'], '模特来源');
    result.selectedUploadId = text(value.selectedUploadId, '当前模特编号', 200);
    if (result.selectedUploadId && !uploads.some((item) => item.id === result.selectedUploadId)) throw new Error('当前选中的模特不存在');
  }
  if (isProduct) {
    result.selectedUploadId = text(value.selectedUploadId ?? '', '当前商品图片编号', 200);
    if (result.selectedUploadId && !uploads.some(item => item.id === result.selectedUploadId)) throw new Error('当前选中的商品图片不存在');
  }
  if (kind === 'scene') {
    result.source = choice(sceneSource(value), ['presets', 'upload', 'custom'], '场景来源');
    const legacyPreset = value.source === 'presets' && value.presetId === undefined
      ? SCENE_PRESETS.find(item => item.type === config.type && item.notes === config.notes)?.id : '';
    result.presetId = choice(value.presetId ?? legacyPreset ?? '', ['', ...SCENE_PRESETS.map(item => item.id)], '预设场景');
    result.selectedUploadId = text(value.selectedUploadId ?? '', '当前场景图片编号', 200);
    if (result.selectedUploadId && !uploads.some(item => item.id === result.selectedUploadId)) throw new Error('当前选中的场景图片不存在');
  }
  return result;
}

function validatedCanvas(value) {
  record(value, '画布');
  record(value.positions, '画布节点位置');
  const point = (position) => {
    if (!Number.isFinite(position?.x) || !Number.isFinite(position?.y)) throw new Error('画布坐标无效');
    return { x: position.x, y: position.y };
  };
  const defaults = createProject().canvas.positions;
  const positions = Object.fromEntries(['model', 'product', 'scene', 'creative'].map((kind) => [kind, point(value.positions[kind] ?? defaults[kind])]));
  let viewport = null;
  if (value.viewport !== null) {
    record(value.viewport, '画布视口');
    if (!Number.isFinite(value.viewport.zoom) || value.viewport.zoom < 0.15 || value.viewport.zoom > 2) throw new Error('画布缩放无效');
    viewport = { ...point(value.viewport), zoom: value.viewport.zoom };
  }
  return { positions, viewport };
}

export function validateProject(value) {
  record(value, '电商项目');
  if (value.schema !== 1) throw new Error('电商项目版本不受支持');
  const ids = new Set();
  const defaults = createProject();
  const creative = value.creative === undefined ? defaults.creative : value.creative;
  return {
    schema: 1,
    name: text(value.name, '项目名称', 200).trim() || '未命名电商项目',
    model: validatedSection('model', value.model, ids),
    product: validatedSection('product', value.product, ids),
    scene: validatedSection('scene', value.scene === undefined ? defaults.scene : value.scene, ids),
    creative: { ...creativeSettings(creative), ...resultFields('creative', creative, ids) },
    canvas: validatedCanvas(value.canvas === undefined ? defaults.canvas : value.canvas),
  };
}

export function formatGenerationError(failure) {
  const original = imageFailureMessage(failure);
  if (/generated images?.*(?:unsafe|not safe)/i.test(original)) {
    return `上游报告生成结果未通过安全审核，本次没有返回可用图片。这不等于缺少模特或商品；具体触发原因尚无法确认。\n请检查所选模特与商品是否适合当前人物的年龄及日常展示，必要时更换素材或联系图片服务商核查。\n原始错误：${original}`;
  }
  if (classifyImageFailure(0, failure) === 'safety_policy') {
    return `上游提示请求可能因安全审核被拦截，或不适合图像生成，具体原因尚无法确认。\n原始错误：${original}`;
  }
  return original;
}

function openDatabase() {
  return new Promise((resolve, reject) => {
    if (!globalThis.indexedDB) return reject(new Error('当前浏览器不支持本地项目存储'));
    const request = globalThis.indexedDB.open(DATABASE_NAME, 1);
    let settled = false;
    request.onupgradeneeded = () => {
      if (!request.result.objectStoreNames.contains(STORE_NAME)) request.result.createObjectStore(STORE_NAME);
    };
    request.onerror = () => { settled = true; reject(request.error || new Error('无法打开本地项目存储')); };
    request.onblocked = () => { settled = true; reject(new Error('本地项目存储被其他页面占用，请关闭其他项目页面后重试')); };
    request.onsuccess = () => {
      const database = request.result;
      if (settled) return database.close();
      database.onversionchange = () => database.close();
      resolve(database);
    };
  });
}

async function projectTransaction(mode, value) {
  const database = await openDatabase();
  return new Promise((resolve, reject) => {
    try {
      const transaction = database.transaction(STORE_NAME, mode);
      const store = transaction.objectStore(STORE_NAME);
      const request = mode === 'readwrite' ? store.put(value, WORKSPACE_KEY) : store.get(WORKSPACE_KEY);
      transaction.oncomplete = () => { database.close(); resolve(mode === 'readwrite' ? value : request.result); };
      transaction.onerror = transaction.onabort = () => { database.close(); reject(transaction.error || request.error || new Error('本地项目存储操作失败')); };
    } catch (failure) { database.close(); reject(failure); }
  });
}

export function listResumableRecoveries(project) {
  record(project, '电商项目');
  const items = [];
  for (const kind of ['model', 'product', 'scene', 'creative']) {
    const recoveries = project[kind]?.recoveries;
    if (!Array.isArray(recoveries) || !recoveries.length) continue;
    for (const entry of recoveries) {
      if (!entry || typeof entry !== 'object' || Array.isArray(entry)) continue;
      if (typeof entry.id !== 'string' || !/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(entry.id)) continue;
      if (entry.taskId !== undefined && entry.taskId !== entry.id) continue;
      items.push({ kind, entry });
    }
  }
  return items;
}

export async function readProject() {
  const value = await projectTransaction('readonly');
  if (value == null) return null;
  const project = validateProject(value);
  recoverCampaignRun(project.creative.campaign.activeRun);
  return project;
}

function toPlain(value) {
  if (value == null || typeof value !== 'object') return value;
  if (Array.isArray(value)) return value.map(toPlain);
  const result = {};
  for (const key of Object.keys(value)) result[key] = toPlain(value[key]);
  return result;
}

export async function saveProject(value, options = {}) {
  return projectTransaction('readwrite', options.trusted ? toPlain(value) : validateProject(value));
}
