import { selectedSceneReference } from './scene-presets.js';

export function videoReferences(project) {
  const video = project.creative.video, references = [];
  if (video.story?.enabled) {
    for (const character of video.story.characters) {
      if (character.source !== 'description' && character.image) references.push({ kind: 'character', character, item: character.image });
    }
  }
  for (const [kind, enabled] of [['model', video.useModel], ['product', video.useProduct], ['scene', video.useScene]]) {
    if (kind === 'model' && video.story?.enabled) continue;
    if (!enabled) continue;
    const section = project[kind];
    const item = kind === 'scene' ? selectedSceneReference(section) : section?.activeResultId
      ? section.results?.find(result => result.id === section.activeResultId) : section?.results?.at(-1);
    if (item) references.push({ kind, item });
  }
  const marking = project.product?.config?.marking;
  if (video.useProduct && references.some(entry => entry.kind === 'product') && marking?.mode === 'manual' && marking.marks?.length && marking.annotatedSrc) {
    references.push({ kind: 'product', marking: true, item: { src: marking.annotatedSrc, name: '商品标记图' } });
  }
  return references;
}

export function videoAssetPrompt(project) {
  const references = videoReferences(project), video = project.creative.video;
  return references.map(({ kind, item, marking, character }, index) => {
    const label = '@图片' + (index + 1) + '（第' + (index + 1) + '张参考图）';
    if (marking) return label + '：商品主体编号定位图。编号只用于选择商品，不得出现在成片中。';
    if (kind === 'character') return label + '：仅作为角色“' + character.name + '”的身份参考，保留五官、年龄、发型及体态，服装按人物设定；不将此角色与其他人物互换，不复制图片背景或其他人。';
    if (kind === 'model') return label + '：唯一模特身份依据，保留五官、年龄、发型、肤色和身材。' + (video.useProduct ? '商品穿戴以商品图为准。' : '保留原有穿着与配饰。');
    if (kind === 'product') return label + '：唯一商品外观依据，保留颜色、面料、结构、纹样和原有标识；套装保留上衣及裤子或裙子，多视图代表同一商品。';
    return label + '：场景“' + item.name + '”。全片始终发生在此场景，保持空间结构；人物若入镜，肤色与衣料受光必须与场景同一光源和阴影方向，近景也保留原环境，禁止抠图贴合感；不复制无关人物和商品。';
  }).join('\n');
}
