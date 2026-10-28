import scenes from '../../../../../app/resources/ecommerce_scenes.json' with { type: 'json' };

export const SCENE_PRESETS = scenes.map(item => ({ ...item, cover: '/admin/ecommerce-box/scenes/' + item.id + '.jpg' }));

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
