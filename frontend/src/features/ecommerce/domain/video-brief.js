export function videoBriefSignature(project) {
  const video = project.creative.video;
  return JSON.stringify({ uploads: project.product.uploads, product: project.product.config, idea: video.story.idea, customScript: video.prompt, duration: video.duration,
    style: video.style, ratio: video.aspect_ratio, resolution: video.resolution, model: project.integration?.videoModelId,
    notes: project.creative.notes, useModel: project.creative.useModel, useScene: project.creative.useScene,
    modelRef: project.creative.useModel ? project.model : null, sceneRef: project.creative.useScene ? project.scene : null });
}

export function prepareBriefReferences(project) {
  const brief = project.integration?.videoBrief;
  if (!brief) return project;
  if (brief.signature !== videoBriefSignature(project)) throw new Error('素材或剧情已修改，请更新并确认视频方案');
  const prepared = JSON.parse(JSON.stringify(project));
  prepared.product.uploads = prepared.product.uploads.filter(media => brief.productIds.includes(media.id));
  if (!prepared.product.uploads.length) throw new Error('请至少指定一张商品图');
  prepared.product.activeResultId = ''; prepared.product.results = [];
  if (!prepared.product.uploads.some(media => media.id === prepared.product.selectedUploadId)) prepared.product.selectedUploadId = prepared.product.uploads[0].id;
  prepared.product.config.marking = { mode: 'auto', imageId: '', marks: [] };
  prepared.integration.useProcessedProduct = false;
  prepared.creative.useModel = false;
  prepared.creative.useScene = !!brief.scene;
  if (brief.scene) {
    prepared.scene.source = 'upload'; prepared.scene.uploads = [brief.scene]; prepared.scene.selectedUploadId = brief.scene.id;
    prepared.scene.activeResultId = ''; prepared.scene.results = [];
  }
  return prepared;
}
