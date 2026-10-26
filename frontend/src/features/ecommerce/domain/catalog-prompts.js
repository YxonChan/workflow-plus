export const CATALOG_PROFILES = [
  { value: 'general', label: '通用电商' },
  { value: 'underwear', label: '内衣商品' },
];

export function validateCatalogProfile(value = 'general') {
  if (!CATALOG_PROFILES.some(profile => profile.value === value)) throw new Error('商品提示词预设无效');
  return value;
}

export function catalogPrompt(profile, useModel) {
  if (validateCatalogProfile(profile) !== 'underwear') return '';
  return [
    '商品提示词预设：内衣商品。制作真实、非色情的内衣电商目录摄影，重点是商品款式、完整轮廓、面料、缝线、肩带、包边及素材中可见的工艺。忠实保留原商品颜色、覆盖范围与结构，不把内衣改成其他品类，不虚构塑形、丰胸或身体变化效果。',
    useModel
      ? '人物展示仅使用明确年满18岁的成年模特，保留参考人物的真实身份与年龄，不将未成年人改为成人。采用平视机位、自然直立站姿、放松手臂与中性表情，呈现普通服装目录照；衣物正确穿戴、完整遮挡私密部位。人物不做挑逗动作，不使用床上或情色场景，不聚焦胸部、臀部或裆部。若原商品无法完整遮挡私密部位，则使用该商品的单独陈列，不改变商品设计来制造遮挡。'
      : '本页只展示商品，不出现人物、人体、皮肤或穿戴者，采用完整平铺或无人体的立体陈列。',
    '局部细节页只拍脱离人体的商品面料、缝线与结构，不拍身体部位特写。原有纯商品画面保持无人，原有人物画面仅在本页构图明确需要时保留人物。使用明亮中性的商业摄影布景、均匀柔光和清晰真实的商品色彩。',
  ].join('\n');
}

export function assertCatalogModel(project, profile, useModel) {
  if (validateCatalogProfile(profile) !== 'underwear' || !useModel) return;
  const section = project.model;
  const model = section.results.find(item => item.id === section.activeResultId) || (!section.activeResultId ? section.results.at(-1) : null);
  const age = model?.request?.modelAge;
  if (age === '儿童' || (!age && section.source === 'ai' && section.config.age === '儿童')) {
    throw new Error('内衣商品的人物展示仅支持成年模特，请选择成年模特结果，或关闭模特引用后生成纯商品图');
  }
}
