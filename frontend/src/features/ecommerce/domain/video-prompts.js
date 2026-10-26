import { videoAssetPrompt } from './video-references.js';
import { SCENE_PRESETS, selectedSceneReference, sceneSource } from './scene-presets.js';

// Keep exact legacy texts so upgrades can replace defaults without touching handwritten prompts.
export const VIDEO_PROMPT_PRESETS = {
  underwear: {
    model: [
      '成年模特正确穿着参考图中的内裤，在明亮棚拍中自然站立，随后缓慢转身展示正面与背面。保持原商品颜色、腰头、包边与覆盖范围，平视全身，不做挑逗动作，不特写私密部位，也不把内裤当包袋手提或翻开展示。',
      '成年模特穿着该内裤向前走两步再停下，展示真实贴合、缝线与面料垂感。动作克制，像服装目录片，不坐卧、不扭胯，不把商品当作箱包开合。',
      '先拍成年模特穿着该内裤后的完整轮廓，再切到脱离人体的平铺特写，只展示面料、缝线与腰边工艺。人物画面保持中性站姿，商品细节页不出现人体。',
    ],
    product: [
      '白底棚拍，内裤完整平铺，镜头缓慢平移展示腰头、缝线、包边与面料纹理，不出现人体，不按包袋方式手提、开合或展示内部隔层。',
      '无人体立体陈列，内裤以原覆盖范围展示，镜头绕商品轻转，强调真实颜色与工艺，不做穿着暗示，不把它拍成箱包或配饰。',
    ],
  },
  服装: {
    model: [
      '模特穿着参考图中的服装缓步走向镜头，停步后转身展示背面垂坠、袖口与剪裁，保持原色、纹样和所有可见细节。',
      '棚拍全身，模特自然走动并轻轻抬手，展示面料动态与版型，不改变款式，不把服装拍成包袋或鞋履。',
    ],
    product: [
      '服装完整平铺或立体撑起，镜头缓慢扫过领口、袖口、缝线与下摆，保持原色，不出现人体。',
    ],
  },
  鞋履: {
    model: [
      '模特穿着参考图中的鞋履在干净地面上走几步，再停下展示侧面轮廓。随后短暂特写鞋面、鞋底与后跟，保持原色与材质，不把鞋子提在手里当包展示。',
      '平视全身，模特缓慢转身，让左右鞋都进入画面，展示穿着后的贴合与鞋型，不做夸张步态。',
    ],
    product: [
      '鞋履成对或单只置于浅色台面，镜头环绕展示鞋面、侧面、后跟与鞋底纹路，不出现人体，不按包袋方式开合。',
    ],
  },
  包袋: {
    model: [
      '模特肩背或手提参考图中的包袋走向镜头，停住后转向侧面，展示包型、肩带、五金与缝线，再轻轻打开看内部隔层后合上。不把包袋当作服装穿上。',
      '模特将包袋挂在肩上自然走动，镜头跟拍包身摆动与五金反光，随后双手托起包袋展示正面与底部。',
    ],
    product: [
      '包袋置于台面，镜头缓慢环绕一周，展示正面、侧面、底部、拉链与五金；再打开看内衬与隔层后合上。不出现人体，也不把它拍成衣物穿着。',
    ],
  },
  配饰: {
    model: [
      '模特佩戴参考图中的配饰，缓慢转头或抬手，让饰品在自然光下展示形状、材质与佩戴位置，不遮挡面部关键特征，不把饰品当包袋展示。',
    ],
    product: [
      '配饰置于干净背景，镜头推近展示材质、扣件与细节，再拉远看完整造型，不出现人体。',
    ],
  },
  美妆: {
    model: [
      '成年模特在明亮梳妆光线下使用参考图中的美妆商品，先展示包装与质地，再点到肌肤上看真实颜色，动作干净，不夸张特写。',
    ],
    product: [
      '美妆商品与质地特写：打开包装，挤出或刷出少量真实膏体/粉质，镜头推近看颜色与纹理，不出现过度修饰效果。',
    ],
  },
  家居: {
    model: [
      '模特双手拿起参考图中的家居商品，放到使用场景中摆正，再绕商品走半圈展示体积与材质，不把家居物品当作服装穿着或包袋背负。',
    ],
    product: [
      '家居商品置于对应使用场景或干净台面，镜头缓慢环绕展示外形、表面纹理与功能细节，不出现不当穿着。',
    ],
  },
  其他: {
    model: [
      '模特手持或正确使用参考图中的商品，缓慢展示正面、侧面与关键细节，保持原色与结构，不把商品错误地穿上或当成其他品类。',
    ],
    product: [
      '商品单独陈列，镜头平移并轻微旋转，完整展示外形、材质与可见工艺，不出现人体，不改变品类。',
    ],
  },
};

const NAME_HINTS = [
  [/内裤|内衣|文胸|泳裤|安全裤|打底裤/, 'underwear'],
  [/鞋|靴|凉鞋|拖鞋/, '鞋履'],
  [/包|袋|箱|托特|背包/, '包袋'],
  [/项链|耳环|手链|戒指|帽|围巾|腰带/, '配饰'],
  [/口红|粉底|眼影|护肤|面霜/, '美妆'],
];

export function videoPromptKey(project) {
  if (project?.creative?.catalogProfile === 'underwear') return 'underwear';
  const name = `${project?.product?.config?.name || ''} ${project?.product?.config?.notes || ''}`;
  const hinted = NAME_HINTS.find(([pattern]) => pattern.test(name));
  if (hinted) return hinted[1];
  const category = project?.product?.config?.category;
  return VIDEO_PROMPT_PRESETS[category] ? category : '其他';
}

const PRODUCT_DIRECTION = {
  underwear: {
    subject: '内衣商品',
    model: '成年模特正确穿着参考图中的内裤或其他内衣款式，以原图品类为准。放松站立并自然换一次重心，让版型看清；完整遮挡私密部位，保持中性站姿与全身构图。',
    product: '内衣按参考图形态自然平铺或独立陈列，完整保留腰头、包边与覆盖范围；无人体、无穿着暗示，不撑大或改变形状。',
  },
  服装: {
    subject: '服装',
    model: '模特已穿好商品，自然侧身再放松站定。手臂随身体自然松动，衣料因重心变化产生褶皱后回落。若为套装，上衣与裤子或裙子始终成套出现。',
    longer: '模特已穿好商品，按日常步速轻松走两步，站定后自然侧身。动作有轻重和停顿，手臂随步伐自然摆动，衣料在停步后回落。若为套装，上衣与裤子或裙子始终成套出现。',
    product: '服装按原图自然平铺或立体陈列，镜头前始终保留全部衣物。套装同时展示上衣和裤子或裙子，不裁掉下装，不把多视图当成多套衣服。',
    integrity: '若为套装，上衣与裤子或裙子始终成套出现。',
  },
  鞋履: {
    subject: '鞋履',
    model: '模特穿好参考鞋履，按日常步速向前迈一步后停下，双脚真实着地承重，鞋面随足部弯曲，停步后自然站稳。',
    product: '鞋履以原图单只或成对关系陈列，完整展示鞋头、后跟和侧面轮廓；鞋与地面接触关系真实，不自行变换左右脚或数量。',
  },
  包袋: {
    subject: '包袋',
    model: '模特按参考结构自然肩背或手提包袋，侧过身让包型清楚，包身随身体轻摆后自然落稳，肩带受力真实，手指握持放松。',
    product: '包袋稳定放置在与场景相容的展示面，先完整展示包型，再随镜头小范围移动呈现正面与侧面，包底接触阴影真实。',
  },
  配饰: {
    subject: '配饰',
    model: '模特自然佩戴配饰，随佩戴位置轻轻转头或调整手腕，让饰品清楚入画。动作到位后放松停留，反光随角度自然变化，配饰不被遮挡。',
    product: '配饰完整置于场景内干净稳定的展示面，保持原数量、尺寸关系与排列，不把它变成另一种饰品。',
  },
  美妆: {
    subject: '美妆商品',
    model: '模特拿着原商品，自然调整握持角度，正面清楚后放松停留；手指不遮挡主体，不演示未经参考确认的试用效果。',
    product: '美妆包装按原图形态稳定陈列，保留瓶盖、容器、标签与真实比例，包装不自行开启、变形或溢出液体。',
  },
  家居: {
    subject: '家居商品',
    model: '模特自然站在商品旁，轻轻示意使用位置后放下手，像家人随手拍家居物件。大型或固定家具不拿起，不移动未经确认的部件。',
    product: '家居商品按真实用途置于场景内，保持尺寸、朝向、支撑点与空间比例，重物不悬浮、不自转。',
  },
  其他: {
    subject: '商品',
    model: '模特与商品自然同框，以符合真实用途的姿态展示商品，动作到位后放松停留，商品轮廓始终清晰。',
    product: '商品以原图形态稳定陈列，保留数量、颜色、尺寸关系和所有可见部件，不随意变形或重新设计。',
  },
};

const SCENE_VIDEO = {
  street: {
    id: 'street',
    action: '人物像在城市街景里正常散步：按日常步速往前走，偶尔侧头或短暂停步，不做棚拍摆姿，也不走秀。',
    longer: '人物沿街道按日常步速走几步，途中自然转头或停步整理衣角，再继续走；像路人手机随手拍，不走秀、不摆商业 pose。',
    lighting: '人物与街道共用同一套自然光：受光面、阴影方向和色温必须与场景一致，脸与衣服不能另起一套棚灯；禁止人物边缘发亮、抠图感或后期贴合痕迹。',
  },
  studio: {
    id: 'studio',
    action: '人物在摄影棚里做简单自然造型：换重心、小幅转体或抬手整理衣摆，像朋友用手机帮忙拍试穿，不是专业棚拍教程。',
    longer: '人物先放松站定，再换两次重心并小幅转体展示版型，动作之间有停顿；像手机试拍，不做夸张棚拍 pose。',
    lighting: '人物与棚内环境共用同一柔光：光比、阴影方向和地面接触影一致，不要给人物单独打一盏硬光，禁止抠图贴合感。',
  },
  living: {
    id: 'living',
    action: '人物做家居日常动作：在客厅自然走动、侧身整理衣摆，或轻触身边家具后收回手，像家人随手拍，不摆商业 pose。',
    longer: '人物在家居空间里自然走两步，停下来整理衣角或侧身看向窗外，再放松站定；动作生活化，不像影棚摆拍。',
    lighting: '人物与家居环境共用同一窗光或室内光：肤色、衣料高光和家具受光方向一致，禁止人物比背景更亮或出现贴图层。',
  },
  outdoor: {
    id: 'outdoor',
    action: '人物在户外自然走动或停步望远，动作像朋友出行随手拍，不摆专业外景 pose。',
    longer: '人物在户外按日常步速走几步，停下来侧身看景或整理衣摆，再继续走；保持生活化节奏。',
    lighting: '人物与户外环境共用同一日光：色温、阴影长短和方向一致，脸部不要额外补成棚拍光，禁止抠图感。',
  },
  cafe: {
    id: 'cafe',
    action: '人物在门店或咖啡店环境里自然走动、停步侧身，或轻触身边桌椅后收回手，像顾客随手拍，不摆商业 pose。',
    longer: '人物在店内走两步后停下，自然侧身或整理衣摆，再放松站定；动作像日常到店，不像影棚拍摄。',
    lighting: '人物与店内环境共用同一室内光：色温、高光和阴影方向一致，禁止人物边缘发亮或后期贴合痕迹。',
  },
  office: {
    id: 'office',
    action: '人物在办公室环境里自然走动或停步整理衣摆，像同事随手拍，不摆商业 pose。',
    longer: '人物在办公空间走两步，停下来侧身或整理衣角，再放松站定；保持日常办公节奏。',
    lighting: '人物与办公室共用同一套室内光：受光面和阴影方向一致，禁止人物另起棚灯光，不要抠图贴合感。',
  },
};

function sceneSearchText(section, preset, reference) {
  if (preset) return [preset.id, preset.name, preset.category, preset.type, preset.notes].filter(Boolean).join(' ');
  return [reference?.name, section?.config?.type, section?.config?.notes].filter(Boolean).join(' ');
}

export function sceneVideoProfile(project) {
  if (!project?.creative?.video?.useScene) return null;
  const section = project.scene;
  const reference = selectedSceneReference(section);
  if (!reference) return null;
  const preset = sceneSource(section) === 'presets' ? SCENE_PRESETS.find(item => item.id === section.presetId) : null;
  if (preset) {
    if (preset.id === 'street') return SCENE_VIDEO.street;
    if (preset.id === 'studio' || preset.id === 'white-studio') return SCENE_VIDEO.studio;
    if (preset.id === 'living') return SCENE_VIDEO.living;
    if (preset.id === 'beach' || preset.id === 'grass') return SCENE_VIDEO.outdoor;
    if (preset.id === 'cafe') return SCENE_VIDEO.cafe;
    if (preset.id === 'office') return SCENE_VIDEO.office;
  }
  const text = sceneSearchText(section, preset, reference);
  if (/城市街景|街景|street/.test(text)) return SCENE_VIDEO.street;
  if (/极简棚拍|摄影棚|白棚|white-studio|\bstudio\b/.test(text)) return SCENE_VIDEO.studio;
  if (/居家|客厅|家居|生活|living/.test(text)) return SCENE_VIDEO.living;
  if (/户外|海滩|草地|beach|grass|自然/.test(text)) return SCENE_VIDEO.outdoor;
  if (/咖啡|门店|cafe/.test(text)) return SCENE_VIDEO.cafe;
  if (/办公|办公室|office/.test(text)) return SCENE_VIDEO.office;
  return {
    id: 'generic',
    action: '人物动作必须符合当前场景的日常用途：在这个环境里自然走动或停步整理衣着，像手机随手拍，不摆与场景无关的棚拍 pose。',
    longer: '人物在当前场景里按日常节奏走两步并停下，做一次符合场景的小动作后放松站定；不要走秀或影棚摆拍。',
    lighting: '人物与场景共用同一套光线：受光面、阴影方向和色温一致，禁止人物边缘发亮、抠图感或后期贴合痕迹。',
  };
}

function modelAction(direction, scene, duration) {
  const base = duration > 6 && (scene?.longer || direction.longer) || scene?.action || direction.model;
  return scene && direction.integrity && !base.includes('套装') ? `${base}${direction.integrity}` : base;
}

function showcasePrompt(project, useModel) {
  const video = project.creative.video;
  const product = project.product?.config || {};
  const subject = product.name?.trim() || '参考商品';
  const total = video.duration || 10;
  const first = Math.max(2, Math.floor(total * .3));
  const second = Math.max(first + 1, Math.floor(total * .75));
  const direction = PRODUCT_DIRECTION[videoPromptKey(project)] || PRODUCT_DIRECTION['其他'];
  const scene = sceneVideoProfile(project);
  const action = useModel ? (direction.model || PRODUCT_DIRECTION['其他'].model) : direction.product;
  const timeline = total <= 6
    ? `0-${total} 秒：完整商品清楚入画，镜头从三分之四视角缓慢推进，完成一次自然展示后停留。${action}全程同一镜头，不切换、不加速。`
    : `0-${first} 秒：以完整商品的三分之四视角建立主视觉，镜头缓慢推进，轮廓和标签清楚。\n${first}-${second} 秒：自然切到同一商品已有可见细节的近景，一次轻微侧移展示材质和轮廓，不生成内部结构。${action}\n${second}-${total} 秒：回到完整商品的稳定构图，保留清楚主体和克制留白，自然停留结束，不新增动作或部件。`;
  return [
    `为“${subject}”制作商业商品展示短片，总时长 ${total} 秒，画面比例 ${video.aspect_ratio === 'adaptive' ? '自适应参考素材' : video.aspect_ratio}。以真实商品、清楚外观和克制运镜为主，不采用强制手机手抖。`,
    '【引用资产】\n' + videoAssetPrompt(project),
    scene?.lighting || (video.useScene ? '使用已选场景的原有光源、色温和透视，商品与环境自然融合。' : '使用方向清楚的柔光与适度补光，保留商品真实颜色、材质和自然接触阴影，不用过曝高光掩盖标签。'),
    '【镜头时间线】\n' + timeline,
    useModel ? '仅使用已选模特，人物身份与商品外观保持一致，动作自然，商品不被手或身体遮挡。' : '仅拍商品和已指定环境，不出现人物、手或脸；商品保持真实支撑，不自行悬浮或旋转。',
    `【声音】\n${video.generate_audio ? '仅保留与已描述动作匹配的轻微环境声，不自行添加旁白、对白或背景音乐。' : '无声成片。'}`,
    '保持参考商品颜色、数量、结构、标签与细节，所有镜头使用同一件商品。不编造功效、规格或内部结构，不新增字幕、水印、认证和促销文案。',
  ].filter(Boolean).join('\n\n');
}

export function buildVideoPrompt(project, variant = 0, useModel = project.creative.video.useModel) {
  if (project.creative.video.useProduct && (project.creative.video.style ?? 'showcase') === 'showcase') return showcasePrompt(project, useModel);
  const video = project.creative.video, product = project.product?.config || {};
  const context = { ...project, creative: { ...project.creative, video: { ...video, useModel } } };
  const key = videoPromptKey(project);
  const direction = PRODUCT_DIRECTION[key] || PRODUCT_DIRECTION['其他'];
  const scene = sceneVideoProfile(context);
  const assets = videoAssetPrompt(context);
  const subject = video.useProduct ? (product.name?.trim() || direction.subject) : useModel ? '模特形象' : '场景画面';
  const duration = video.duration;
  const action = video.useProduct ? (useModel ? modelAction(direction, scene, duration) : direction.product)
    : useModel ? (scene ? modelAction({ model: '模特保持参考穿着，自然侧身再放松站定。', longer: '模特保持参考穿着，在场景里自然走两步后站定。' }, scene, duration) : '模特保持参考穿着，自然侧身再放松站定。')
      : '只呈现参考空间的层次、材质和光线，不添加人物或商品。';
  const framing = useModel ? (!video.useProduct || ['服装', 'underwear', '鞋履'].includes(key)
    ? '手机竖拍或接近日常取景的全身构图，头顶和脚下留有空间，全部衣物与鞋履完整入画。'
    : '手机中景取景，以商品为中心，保留自然人物姿态。')
    : '完整主体入画，材质、边缘和接触阴影清楚。';
  const camera = useModel
    ? (variant === 1 ? '手机手持小幅跟随人物，保持日常拍摄距离，允许轻微手抖，不要稳定器轨道或电影推轨。' : '手机手持大致固定位置，人物在画面里自然活动，允许轻微手抖，不要三脚架影棚机位。')
    : video.useProduct ? (variant === 1 ? '手机手持小幅横移观察轮廓，商品保持原位。' : '手机手持轻微推进，商品保持原位，始终完整入画。')
      : (variant === 1 ? '手机手持小幅横移观察空间，保持真实透视。' : '手机手持轻微推进观察空间，保持真实透视。');
  const lighting = scene
    ? scene.lighting
    : video.useScene
      ? '人物与场景必须共用同一套光线：受光面、阴影方向和色温一致，顺着场景原有光线曝光，禁止人物边缘发亮、抠图感或后期贴合痕迹。'
      : '室内随手拍柔光：侧前方自然照亮主体，另一侧轻补，地面保留柔和接触阴影；像手机拍照，不要专业多灯棚拍说明书式布光。';
  return [
    `拍摄“${subject}”的手机手持随手拍短片。${duration ? `总时长 ${duration} 秒。` : '自动时长，按动作自然完成所需时间安排。'}画面比例${video.aspect_ratio === 'adaptive' ? '自适应参考素材' : video.aspect_ratio}，目标 30fps，正常速度播放，动作使用日常速度和自然停顿。不要做成专业影棚大片或商业广告片。`,
    assets ? '【引用资产】\n' + assets : '',
    lighting,
    `【拍摄过程】\n${framing}${camera}全程一镜到底。${action}${useModel ? '拍摄前后都像真人被手机随手拍到：自然呼吸、偶尔眨眼，表情放松，不按节拍摆姿；动作完成后仍放松等待，保留皮肤纹理。' : '仅拍商品或环境，不出现人物或手；静物不自行摆动、变形或旋转。'}${duration && duration <= 6 ? '短片只完成这一个小动作。' : '动作完成就自然停留，不为填满时长反复重复。'}`,
    video.useProduct && product.notes?.trim() ? '【商品补充要求】\n' + product.notes.trim() : '',
    `【声音】\n${video.generate_audio ? (useModel ? '只保留轻微现场底噪和动作实际产生的脚步、衣料声。' : '只保留当前环境的轻微底噪。') + '无对白、旁白和配乐。' : '无声成片。'}`,
    (video.useProduct ? '保持商品原色、结构和可见细节，不编造功能。' : '保持参考主体与环境连续。')
      + '不要慢动作、速度渐变、转场或定格，' + (useModel ? '不加美颜磨皮、额外人物、字幕和水印。' : '不加字幕和水印。'),
  ].filter(Boolean).join('\n\n');
}

export function videoPromptPool(project, useModel = true) {
  return [0, 1].map(variant => buildVideoPrompt(project, variant, useModel));
}

export function isVideoPromptPreset(text, project) {
  const value = typeof text === 'string' ? text.trim() : '';
  if (!value) return false;
  return value === project?.creative?.video?.promptTemplate
    || Object.values(VIDEO_PROMPT_PRESETS).some(group => group.model.includes(value) || group.product.includes(value));
}

export function pickVideoPrompt(project, current = '', useModel = true) {
  const pool = videoPromptPool(project, useModel);
  const choices = pool.filter(item => item !== current);
  const source = choices.length ? choices : pool;
  return source[Math.floor(Math.random() * source.length)];
}

export function videoPromptPlaceholder(project, useModel = true) {
  return buildVideoPrompt(project, project.creative.video.promptVariant ?? 0, useModel);
}

export function updateVideoPrompt(project, force = false) {
  const video = project.creative.video;
  if (!force && video.prompt.trim() && !isVideoPromptPreset(video.prompt, project)) return false;
  const variant = force ? (video.promptVariant === 1 ? 0 : 1) : (video.promptVariant ?? 0);
  const prompt = buildVideoPrompt(project, variant, video.useModel);
  if (prompt.length > 6000) throw new Error('自动分镜超过 6000 字，请精简商品名称、补充要求或场景名称，或手动填写提示词');
  video.promptVariant = variant;
  video.prompt = prompt;
  video.promptTemplate = prompt;
  return true;
}
