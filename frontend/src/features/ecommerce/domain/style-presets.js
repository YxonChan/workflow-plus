import designs from '../../../../../app/resources/ecommerce_image_designs.json' with { type: 'json' };

export const IMAGE_PURPOSES = designs.purposes;

// Keep persisted IDs so existing results and frozen edit snapshots remain readable.
const STYLE_DIRECTIONS = {
  'clean-studio': { name: '银灰科技 · 模块详情', category: '数码家电', description: '银灰 / 大图与细节分区 / 信息层级', prompt: '银白与中性灰的科技产品详情设计，柔和金属光泽，主体大图与独立的细节小窗形成清晰模块，整套统一对齐和信息层级。只根据目标商品已有外观安排细节，不复制示例的拆解零件、数字、品牌或功能。' },
  editorial: { name: '叙事画报 · 场景详情', category: '通用详情', description: '场景拼版 / 画报分栏 / 视觉叙事', prompt: '采用叙事画报的连续摄影与分栏节奏，以目标商品的整体、可见细节、实际使用环境形成连续主题。中性底色、真实光影、清晰图文层级；不生成分镜脚本表格、无关人物、机位图或示例说明文字。' },
  natural: { name: '清新自然 · 商品详情', category: '美妆生活', description: '清透绿白 / 主视觉与细节 / 图文分区', prompt: '采用浅绿与白色的清透电商详情设计，自然日光，主视觉、真实细节、场景和商品信息分区形成连续套图。可使用柔和绿白色块和局部放大窗；植物只作布景，不暗示成分或功效。' },
  technology: { name: '冷调科技 · 信息详情', category: '数码家电', description: '冷白冰蓝 / 信息分区 / 精密细节', prompt: '采用冷白与冰蓝的科技电商详情排版，主体大图、局部细节与信息区域有明确大小层级，浅蓝色块和清晰网格贯穿各页。不得编造性能数字、内部结构、拆解零件或功能图标；不把所有页面改成孤立白底商品照。' },
  'dark-premium': { name: '亚马逊 A+ · 质感详情', category: '跨境电商', description: 'A+ 模块 / 暗调质感 / 场景与细节', goal: 'detail', prompt: '采用亚马逊 A+ 商品详情的模块化视觉，炭黑、银灰与克制冰蓝强调，主视觉、卖点、细节和生活使用场景依次展开。每页为一个完整详情模块，深浅区块形成层次，统一字级和对齐；禁止整套都做白底商品照。只表现已确认事实，不复制模板里的商品、品牌、参数或认证。' },
  'warm-living': { name: '清新中式 · 场景海报', category: '美妆生活', description: '窗格日光 / 自然陈列 / 场景海报', prompt: '采用清新中式的真实商品场景，木质窗格、自然日光、适量浅色织物与空间留白。目标商品置于可信支撑面，叶影和材质自然，不照搬示例里的茶饮、书本或配料，不暗示附赠品或成分。' },
  'bold-color': { name: '纯白主图 · 极简商品', category: '通用详情', description: '纯白背景 / 完整主体 / 干净轮廓', prompt: '采用纯白背景的极简商品摄影，真实商品完整入画，干净边缘、柔和接触阴影，准确保留材质和原色。重点表现商品整体与已有细节，不复制示例中的耳机，不添加场景道具或虚构功能。' },
  sport: { name: '电商主图 · 卖点视觉', category: '通用详情', description: '商品大图 / 重点卖点 / 色块排版', prompt: '采用电商卖点主图的清晰信息层级，目标商品大图配合已确认卖点，背景以冷蓝或与商品协调的浅色块分区。主体、局部细节与文案位置各有层级，不凭空添加瓦数、容量、百分比、价格或销量，不沿用示例无关商品。' },
  craft: { name: '暖调生活 · 品牌详情', category: '美妆生活', description: '暖色生活 / 细节拼版 / 品牌信息', prompt: '采用温暖生活方式的品牌详情设计，柔和窗光、暖中性色块，完整商品、可见局部工艺与真实使用环境形成交替排版。每页有明确主题与疏密节奏，不复制示例食材，不推断配方、功效或原产地。' },
  beauty: { name: '美妆画册 · 品牌详情', category: '美妆生活', description: '品牌画册 / 包装细节 / 柔光场景', prompt: '采用浅色美妆品牌画册排版，整体包装大图、可见材质细节与自然场景分区组合，柔光、克制留白、统一字级。参考图中的花果和成分装饰不作为商品事实，不添加无依据的成分、香型、认证、功效或人物。' },
  home: { name: '家居空间 · 场景海报', category: '数码家电', description: '真实空间 / 窗光比例 / 场景主视觉', prompt: '采用明亮真实的居家空间，窗光、清楚透视和自然接触阴影，主体商品与环境保持真实尺寸关系，留出简洁文案区域。商品是空间的主角，不复制示例中的风扇或家具，不添加素材未证实的功能。' },
  monochrome: { name: '亚马逊 · 商品详情', category: '跨境电商', description: '深浅分区 / 商品大图 / 细节与场景', goal: 'hero', prompt: '采用亚马逊商品主图与副图套系设计：首图是纯白背景的完整商品，后续副图按主题分别呈现商品卖点信息、真实使用场景、局部细节与品牌收尾。副图采用炭黑与银灰配适量暖橙强调色，明确的主次和图文分区，整套保持一致字级与光线；绝不能把每张都做成相同的单商品白底照。不复制示例手机、英文广告、品牌、数字或功能。' },
};

Object.assign(STYLE_DIRECTIONS, {
  'tryon-studio': { name: '干净棚拍 · 模特试穿', category: '人物展示', description: '浅色摄影棚 / 自然站姿 / 突出版型', cover: '/admin/ecommerce-box/scenes/white-studio.jpg', prompt: '生成最终模特试穿照片，浅色摄影棚背景、均匀柔光、自然站姿，人物与服装完整清晰。突出真实上身效果、版型和面料，不生成独立商品白底照、人物设定图、三视图、图文详情页或套图总览。' },
  'tryon-street': { name: '自然街拍 · 模特试穿', category: '人物展示', description: '城市街景 / 日常穿搭 / 自然光线', cover: '/admin/ecommerce-box/scenes/street.jpg', prompt: '生成最终模特日常街拍试穿照片，清爽城市街景、自然日光和可信透视，轻松自然的站姿或小幅行走。人物与目标服饰为主体，街景不抢占展示重点，不生成独立商品陈列、图文拼版或多页套图。' },
  'tryon-living': { name: '日常生活 · 模特试穿', category: '人物展示', description: '室内窗光 / 生活穿搭 / 真实质感', cover: '/admin/ecommerce-box/scenes/living.jpg', prompt: '生成最终模特生活方式试穿照片，明亮室内、柔和窗光、自然日常姿态，真实服装褶皱和得体穿搭，目标服饰清楚可辨。不复制独立商品白底图的构图，不生成详情排版或套图缩略总览。' },
});

export const STYLE_PRESETS = Object.entries(STYLE_DIRECTIONS).map(([id, { goal, ...direction }]) => ({
  id, ...direction, ...designs.styles[id], cover: direction.cover || '/admin/ecommerce-box/styles/' + id + '.jpg',
}));

export const DEFAULT_STYLE_ID = 'clean-studio';
export const STYLE_CATEGORIES = ['全部', '跨境电商', '通用详情', '美妆生活', '数码家电'];
export function getStylePreset(id = DEFAULT_STYLE_ID) { return STYLE_PRESETS.find(style => style.id === id); }
export function stylesForPurpose(purpose) { return STYLE_PRESETS.filter(style => style.purposes.includes(purpose)); }
export function styleSupportsPurpose(presetId, purpose) { return getStylePreset(presetId)?.purposes.includes(purpose) ?? false; }
export function defaultStyleForPurpose(purpose) { return IMAGE_PURPOSES.find(item => item.id === purpose)?.defaultStyleId; }
export function assertStyleForPurpose(presetId, purpose) {
  if (!styleSupportsPurpose(presetId, purpose)) throw new Error('图片风格与用途不匹配，请重新选择');
}

export function validateStyleLibrary(value) {
  if (value === undefined) return { favorites: [], recent: [] };
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('风格库格式无效');
  const list = key => {
    if (!Array.isArray(value[key]) || value[key].length > STYLE_PRESETS.length || value[key].some(id => !getStylePreset(id))) throw new Error('风格库选项无效');
    return [...new Set(value[key])];
  };
  return { favorites: list('favorites'), recent: list('recent') };
}
