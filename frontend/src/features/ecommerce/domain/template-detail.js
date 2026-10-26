import { getStylePreset, assertStyleForPurpose } from './style-presets.js';
import { generationSize } from './image-generation.js';
import { validateMarketingCopy } from './marketing-copy.js';
import { selectedSceneReference } from './scene-presets.js';
import { validateCatalogProfile, assertCatalogModel, catalogPrompt } from './catalog-prompts.js';
import { productMarkingPrompt, validateProductMarking } from './product-marking.js';

function selected(section, label) {
  const result = section.results.find(item => item.id === section.activeResultId) || (!section.activeResultId ? section.results.at(-1) : null);
  if (!result) throw new Error('请先添加' + label + '参考');
  return result.src;
}

export function buildTemplateDetailRequest(project) {
  const creative = project.creative;
  assertStyleForPurpose(creative.presetId, 'detail');
  const preset = getStylePreset(creative.presetId);
  if (!preset.detailLayout) throw new Error('详情模板缺少版式定义');
  const profile = validateCatalogProfile(creative.catalogProfile);
  assertCatalogModel(project, profile, creative.useModel);
  const referenceRoles = [creative.useModel ? 'model' : null, 'product', creative.useScene ? 'scene' : null].filter(Boolean);
  const images = referenceRoles.map(role => {
    if (role !== 'scene') return selected(project[role], role === 'model' ? '模特' : '商品');
    const scene = selectedSceneReference(project.scene);
    if (!scene) throw new Error('请先选择场景，或生成场景结果');
    return scene.src;
  });
  const identity = referenceRoles.map((role, index) => `第 ${index + 1} 张参考图${role === 'product' ? '是唯一商品外观依据，准确保留形状、原色、品牌、材质和可见结构' : role === 'model' ? '只用于指定人物身份，保持面容、年龄与自然身体比例；人物仅在模板的人物模块中出现' : '只用于场景模块，保持环境、透视与光线，不将所有模块替换为场景照'}。`).join('\n');
  const copy = validateMarketingCopy(creative.copy);
  const supplied = copy.enabled
    ? [copy.headline, copy.subtitle, ...copy.sellingPoints].map(text => text.trim()).filter(Boolean)
    : [];
  const marking = validateProductMarking(project.product.config.marking, project.product.uploads);
  return {
    mode: 'compose', n: 1, size: generationSize('2:3', creative.quality), images, referenceRoles,
    prompt: [
      '只生成一张完整的竖版商品详情图。模板中的主视觉、细节小图、场景和文字区，必须在同一张连续画布内共同完成，不拆成独立页面，不输出多张图片。',
      `详情模板：${preset.name}。\n版式要求：${preset.detailLayout}`,
      '以附带模板的整体网格、区块相对位置、主视觉大小、图片与文字比例、留白、配色及字级为设计依据。将模板中的示例商品替换为本次真实商品，保持整体版式相似。不要退化成单张孤立白底商品照，也不要把参考模板当作其中一个小贴图。',
      identity,
      '每个图像模块都展示同一件真实商品的整体、已见细节或合理使用场景。不可编造背面、内部结构、包装、配件、尺寸、成分、功效、认证、价格、销量、服务承诺或评价。单件服装不得拆成独立上下装。',
      !creative.useModel ? '没有指定人物参考时，不复制模板肖像；对应模块改用商品场景或商品细节，保留原模块位置与大小。' : '',
      supplied.length
        ? `将以下已确认文案按标题、副标题和卖点的层级分配到模板对应文字区，使用清楚可读的简体中文，按原文使用且每条只出现一次：\n${supplied.map(text => JSON.stringify(text)).join('\n')}\n没有资料的模块以商品图像与留白完成，不写假字或长篇占位文案。`
        : '不添加新的营销文字、数字或虚构文案，不生成文字占位符或假字；保留商品原有包装字样，通过商品图像模块与留白完成模板结构。',
      productMarkingPrompt(marking), catalogPrompt(profile, creative.useModel),
    ].filter(Boolean).join('\n\n'),
    ...(profile !== 'general' ? { catalogProfile: profile } : {}),
    ...(marking.mode === 'manual' ? { marking } : {}),
  };
}
