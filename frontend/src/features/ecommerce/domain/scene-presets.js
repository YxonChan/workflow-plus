export const SCENE_PRESETS = [
  { id: 'office', name: '现代办公室', category: '办公', type: '自定义', notes: '明亮现代办公室，落地窗、简洁桌椅，留出商品展示空间，无人物、无商品。' },
  { id: 'white-studio', name: '简约白棚', category: '电商棚拍', type: '极简棚拍', notes: '' },
  { id: 'cafe', name: '时尚门店', category: '室内', type: '咖啡店', notes: '' },
  { id: 'living', name: '家居客厅', category: '生活', type: '居家客厅', notes: '' },
  { id: 'beach', name: '阳光海滩', category: '户外', type: '户外自然', notes: '阳光海滩，蓝天、海面、沙滩与棕榈树，留出商品展示空间，无人物、无商品。' },
  { id: 'grass', name: '户外草地', category: '户外', type: '户外自然', notes: '开阔绿色草地，远处树木，柔和自然光，留出商品展示空间，无人物、无商品。' },
  { id: 'street', name: '城市街景', category: '户外', type: '城市街景', notes: '' },
  { id: 'studio', name: '摄影棚', category: '电商棚拍', type: '极简棚拍', notes: '空旷摄影棚，浅灰背景墙与地面，柔和商业布光，无人物、无商品。' },
].map(item => ({ ...item, cover: '/admin/ecommerce-box/scenes/' + item.id + '.jpg' }));

export function sceneSource(section) {
  return section.source ?? (section.results?.length ? 'custom' : section.uploads?.length ? 'upload' : 'presets');
}

export function selectedSceneReference(section) {
  if (!section) return null;
  const source = sceneSource(section);
  if (source === 'presets') {
    const preset = SCENE_PRESETS.find(item => item.id === section.presetId);
    return preset ? { id: 'scene-preset-' + preset.id, name: preset.name, src: preset.cover } : null;
  }
  if (source === 'upload') return section.selectedUploadId
    ? section.uploads?.find(item => item.id === section.selectedUploadId) || null
    : section.uploads?.[0] || null;
  if (source === 'custom') return section.activeResultId
    ? section.results?.find(item => item.id === section.activeResultId) || null
    : section.results?.at(-1) || null;
  return null;
}
