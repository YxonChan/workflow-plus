import { generationSize } from './image-generation.js';

export function createStory() {
  return { enabled: false, idea: '', expandedIdea: '', script: '', title: '', duration: 0, hasDialogue: false, warnings: [], characters: [] };
}

export function validateStory(value) {
  if (value === undefined) return createStory();
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('剧情配置无效');
  const text = (input, max) => { if (typeof input !== 'string' || input.length > max) throw new Error('剧情内容无效或过长'); return input; };
  if (typeof value.enabled !== 'boolean' || typeof value.hasDialogue !== 'boolean' || !Number.isInteger(value.duration) || value.duration < 0 || value.duration > 15
    || !Array.isArray(value.warnings) || value.warnings.length > 5 || !Array.isArray(value.characters) || value.characters.length > 4) throw new Error('剧情配置无效');
  const ids = new Set();
  const characters = value.characters.map(item => {
    if (!item || typeof item !== 'object' || !/^c[1-4]$/.test(item.id) || ids.has(item.id) || !['description', 'upload', 'ai'].includes(item.source)) throw new Error('剧情人物配置无效');
    ids.add(item.id);
    let image = null;
    if (item.image) {
      const img = item.image, src = text(img.src, 20000000);
      if (!/^(?:https?:\/\/|\/(?!\/)|data:image\/(?:jpeg|png|webp);base64,)/.test(src) || !Number.isFinite(img.width) || !Number.isFinite(img.height) || img.width <= 0 || img.height <= 0) throw new Error('人物图片无效');
      image = { id: text(img.id, 200), name: text(img.name, 500), src, width: img.width, height: img.height };
    }
    return { id: item.id, name: text(item.name, 30), description: text(item.description, 350), source: item.source, image };
  });
  return { enabled: value.enabled, hasDialogue: value.hasDialogue, duration: value.duration, idea: text(value.idea, 2500),
    expandedIdea: text(value.expandedIdea, 2500), script: text(value.script, 6000), title: text(value.title, 100),
    warnings: value.warnings.map(item => text(item, 300)), characters };
}

export function storyPrompt(project) {
  const video = project.creative.video, story = video.story;
  if (!story.script.trim()) throw new Error('请先填写想法并扩展剧情');
  if (story.idea !== story.expandedIdea || story.duration !== video.duration) throw new Error('剧情或时长已修改，请重新扩展剧情');
  if (story.hasDialogue && !video.generate_audio) throw new Error('剧情包含对白，请开启生成声音');
  for (const character of story.characters) {
    if (character.source !== 'description' && !character.image) throw new Error(`请为${character.name}上传或生成人物图片，或改用文字设定`);
  }
  return [`${video.duration} 秒剧情短片。严格按以下剧情、人物和对白拍摄，商品为故事中的同一件商品。`,
    '【人物设定】', ...story.characters.map(character => `${character.id} ${character.name}：${character.description}`),
    '【分镜与对白】', story.script,
    video.generate_audio ? '人物按指定身份说话，中文自然对白，保持声线一致；不串台、不抢话，无额外旁白，不自动增加字幕或背景音乐。' : '静音画面，不生成对白或其他声音。',
    '保持角色身份、服装、空间位置及商品持有关系连续。商品参考只锁定外观，不照搬原背景；没有要求的角色与模特不加入剧情。'].join('\n');
}

export function characterImageRequest(character, quality) {
  return { mode: 'compose', size: generationSize('2:3', quality), n: 1, images: [], modelAge: 'unknown',
    storyCharacterId: character.id, storyCharacterDescription: character.description,
    prompt: `生成一张剧情人物参考照片，独立人物“${character.name}”：${character.description}。真实摄影质感，正面自然站姿，全身完整入画、脸部清晰、日常合宜穿着，浅灰干净背景，均匀柔光。仅出现这一人，不出现商品、其他人、文字、拼版或水印。人物形象为虚构创作设定。` };
}

export function attachCharacterResult(project, job, image) {
  const original = job.request.original;
  const character = project.creative.video.story?.characters.find(item => item.id === original.storyCharacterId);
  if (character?.source === 'ai' && character.description === original.storyCharacterDescription && character.image?.id !== image.id) {
    character.image = { id: image.id, name: image.name, src: image.src, width: image.width, height: image.height };
  }
}
