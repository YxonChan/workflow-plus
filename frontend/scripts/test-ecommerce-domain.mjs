import assert from 'node:assert/strict'
import { characterImageRequest, attachCharacterResult } from '../src/features/ecommerce/domain/story.js'
import { test } from 'node:test'
import { existsSync, readFileSync } from 'node:fs'
import { createProject, validateProject, buildCreativeRequests, buildEditRequest, buildProductRequest } from '../src/features/ecommerce/domain/commerce.js'
import { buildCampaignRun, campaignShotRequest } from '../src/features/ecommerce/domain/campaign.js'
import { buildVideoRequest } from '../src/features/ecommerce/domain/commerce-video.js'
import { buildVideoPrompt } from '../src/features/ecommerce/domain/video-prompts.js'
import { IMAGE_PURPOSES, STYLE_PRESETS, stylesForPurpose, styleSupportsPurpose } from '../src/features/ecommerce/domain/style-presets.js'
import { SCENE_PRESETS } from '../src/features/ecommerce/domain/scene-presets.js'
import { createStorefrontProject, selectStorefrontGoal, selectStorefrontStyle, storefrontImageRequests, storefrontVideoRequest, imageReferenceInput } from '../src/features/ecommerce/domain/storefront.js'
import { videoBriefSignature } from '../src/features/ecommerce/domain/video-brief.js'
import { campaignStyle } from '../src/features/ecommerce/domain/campaign-style.js'

test('approved video separates character photos from products and rejects stale input', () => {
  const project = createStorefrontProject(); selectStorefrontGoal(project, 'video')
  const oil = { id: 'oil', name: '食用油', src: '/storage/oil.png', width: 800, height: 1000 }
  const mother = { id: 'mother', name: '妈妈照片', src: '/storage/mother.png', width: 800, height: 1000 }
  const ignored = { id: 'unused', name: '不用的图', src: '/storage/unused.png', width: 800, height: 1000 }
  project.product.uploads = [mother, oil, ignored]; project.product.selectedUploadId = mother.id
  Object.assign(project.creative.video.story, { enabled: true, idea: '女儿送油给妈妈', expandedIdea: '女儿送油给妈妈', duration: 10,
    script: '0-10 秒：女儿将油送给妈妈，妈妈微笑接过。', characters: [{ id: 'c1', name: '妈妈', description: '中年女性', source: 'upload', image: mother }] })
  project.integration.videoBrief = { version: 91, signature: videoBriefSignature(project), productIds: ['oil'], scene: null }
  const request = storefrontVideoRequest(project)
  assert.deepEqual(request.image_with_roles.map(item => [item.kind, item.url]), [['character', mother.src], ['product', oil.src]])
  assert.match(request.prompt, /角色“妈妈”/)
  assert(!request.prompt.includes('补充视角'))
  project.creative.video.duration = 15
  assert.throws(() => storefrontVideoRequest(project), /更新并确认视频方案/)
})

function prepared() {
  const project = createProject()
  const source = '/storage/fixtures/coat.jpg'
  const model = '/storage/fixtures/model.jpg'
  project.product.uploads.push({ id: 'upload', name: '外套', src: source, width: 1024, height: 1536 })
  const request = buildProductRequest(project.product)
  project.product.results.push({ id: 'product', name: '外套', src: source, width: 1536, height: 1024,
    createdAt: new Date().toISOString(), operation: 'generate', request: { ...request, n: 1 } })
  project.product.activeResultId = 'product'
  project.model.results.push({ id: 'model', name: '模特', src: model, width: 1536, height: 1024,
    createdAt: new Date().toISOString(), operation: 'generate', request: { prompt: '成人模特', images: [], size: '1536x1024', n: 1, mode: 'compose', modelAge: '成人' } })
  project.model.activeResultId = 'model'
  project.scene.source = 'presets'; project.scene.presetId = 'office'
  return project
}

// Construct frozen fixtures as they were submitted before the old templates retired.
function beforeRetirement(presetId, callback) {
  const preset = STYLE_PRESETS.find(item => item.id === presetId)
  assert.equal(preset.retired, true)
  const enabled = preset.enabled
  try { preset.enabled = true; return callback() } finally { preset.enabled = enabled }
}
function legacyCampaignRun(project) {
  project.creative.presetId = 'natural'
  return beforeRetirement('natural', () => buildCampaignRun(project))
}

test('project round trips with source constraints, mark coordinates and current storage URLs', () => {
  const project = prepared()
  project.product.config.marking = { mode: 'manual', imageId: 'upload', marks: [{ id: 'm', label: 1, x: .1, y: .2, width: .3, height: .4 }], annotatedSrc: '/storage/fixtures/marked.jpg' }
  const clean = validateProject(project)
  const req = buildProductRequest(clean.product)
  assert.deepEqual(req.images, ['/storage/fixtures/marked.jpg'])
  assert.match(req.prompt, /左10%、上20%、右40%、下60%/)
  project.product.config.marking.marks[0].x = .9
  assert.throws(() => validateProject(project), /超出/)
})
test('historical campaign creates 5+7/8/9 ordered pages and freezes refs and one style', () => {
  for (const count of [7, 8, 9]) {
    const project = prepared()
    project.creative.campaign.detailCount = count
    project.creative.campaign.useScene = true
    const run = legacyCampaignRun(project)
    assert.equal(run.shots.length, 5 + count)
    assert.deepEqual(run.referenceRoles, ['model', 'product', 'scene'])
    const originalPrompt = run.shots[0].prompt
    project.creative.presetId = 'main-color-block'; project.product.results[0].src = '/storage/fixtures/other.jpg'
    assert.equal(run.shots[0].prompt, originalPrompt)
    assert.equal(run.references[1], '/storage/fixtures/coat.jpg')
    for (const shot of run.shots) {
      assert(shot.prompt.startsWith(run.styleLock + '\n\n'))
      const request = campaignShotRequest(run, shot)
      assert.equal(request.n, 1)
      assert.equal(request.images.length, ['H1', 'H4', 'D3'].includes(shot.id) ? 2 : 3)
      assert.equal(request.size, shot.role === 'hero' ? '2048x2048' : '1365x2048')
    }
  }
})
test('selected campaign pages and supplied facts do not invent missing specifications', () => {
  const project = prepared()
  project.creative.campaign.excludedIds = ['H2', 'D3']
  project.creative.campaign.textMode = 'facts'
  project.creative.campaign.facts.title = '纯棉外套'
  const run = legacyCampaignRun(project)
  assert(!run.shots.some(shot => ['H2', 'D3'].includes(shot.id)))
  assert.match(run.shots.find(shot => shot.id === 'D1').prompt, /纯棉外套/)
  assert.match(run.shots[0].prompt, /不添加额外文字/)
})
test('edit retains original page ratio, style and ordered references', () => {
  const project = prepared()
  const run = legacyCampaignRun(project)
  const request = campaignShotRequest(run, run.shots[0])
  const result = { id: 'hero', src: '/storage/fixtures/hero.jpg', name: '主图', width: 2048, height: 2048,
    createdAt: new Date().toISOString(), operation: 'generate', request }
  const edit = buildEditRequest('creative', project.creative, result, '背景更明亮')
  assert.equal(edit.size, '2048x2048')
  assert.deepEqual(edit.images, [result.src, ...request.images])
  assert(edit.prompt.startsWith(run.styleLock))
  assert.equal(edit.campaign.shotId, 'H1')
})
test('try-on, posters and notes have distinct instructions', () => {
  const project = prepared()
  selectStorefrontGoal(project, 'tryon')
  assert.match(buildCreativeRequests(project)[0].prompt, /模特试穿展示图/)
  selectStorefrontGoal(project, 'poster'); project.creative.notes = '仅使用文字：春日外套'
  const request = buildCreativeRequests(project)[0]
  assert.match(request.prompt, /商品宣传海报/)
  assert.match(request.prompt, /春日外套/)
})
test('video sends original model URL with explicit duration and ordered refs, without avatar creation', () => {
  const project = prepared()
  project.creative.video.useScene = true
  project.creative.video.duration = 12
  project.creative.video.prompt = buildVideoPrompt(project, 0)
  project.creative.video.promptTemplate = project.creative.video.prompt
  const request = buildVideoRequest(project)
  assert.equal(request.duration, 12)
  assert.deepEqual(request.image_with_roles.map(ref => ref.kind), ['model', 'product', 'scene'])
  assert.equal(request.image_with_roles[0].url, '/storage/fixtures/model.jpg')
  assert(!JSON.stringify(request).includes('asset://'))
  assert.match(request.prompt, /12 秒/)
  assert.match(request.prompt, /@图片1/)
})
test('all registered styles and 10 scenes point to module assets', () => {
  assert.equal(STYLE_PRESETS.length, 37); assert.equal(SCENE_PRESETS.length, 10)
  assert([...STYLE_PRESETS, ...SCENE_PRESETS].every(preset => preset.cover.startsWith('/admin/ecommerce-box/')))
  for (const preset of [...STYLE_PRESETS.filter(item => item.enabled !== false), ...SCENE_PRESETS]) {
    assert(existsSync(new URL('../public' + preset.cover.slice('/admin'.length), import.meta.url)), preset.id + ' reference must exist')
  }
})

test('delivered template pack is the only available main and detail catalog', () => {
  const imported = STYLE_PRESETS.filter(style => style.templatePack === 'commerce-v1')
  assert.equal(imported.length, 20)
  assert(imported.every(style => style.enabled === true))
  assert.equal(stylesForPurpose('main').length, 8)
  assert.equal(stylesForPurpose('detail').length, 12)
  assert([...stylesForPurpose('main'), ...stylesForPurpose('detail')].every(style => style.templatePack === 'commerce-v1'))
  assert.equal(stylesForPurpose('poster').length, 2)
  assert.equal(stylesForPurpose('tryon').length, 3)
})

test('retired templates migrate drafts but frozen historical results remain editable', () => {
  const retired = STYLE_PRESETS.filter(style => style.retired)
  assert.equal(retired.length, 12)
  for (const preset of retired) {
    const project = uploadOnly()
    const purpose = preset.purposes.includes('main') ? 'main' : 'detail'
    selectStorefrontGoal(project, purpose)
    const request = beforeRetirement(preset.id, () => {
      selectStorefrontStyle(project, preset.id)
      return storefrontImageRequests(project)[0].request
    })
    project.creative.library = { favorites: [preset.id], recent: [preset.id] }
    project.creative.results = [{ id: 'archived', name: '历史成品', src: '/storage/fixtures/archived.jpg', width: 2048, height: 2048,
      operation: 'generate', createdAt: new Date().toISOString(), request }]
    project.creative.activeResultId = 'archived'
    assert.throws(() => storefrontImageRequests(project), /用途不匹配/)
    assert.throws(() => selectStorefrontStyle(project, preset.id), /用途不匹配/)
    const restored = validateProject(JSON.parse(JSON.stringify(project)))
    restored.integration = { ...project.integration }
    selectStorefrontGoal(restored, purpose)
    assert.equal(restored.creative.presetId, purpose === 'main' ? 'main-color-block' : 'detail-brand-minimal')
    assert.deepEqual(restored.creative.results[0].request, request, 'draft migration never rewrites a frozen compose request')
    const edit = buildEditRequest('creative', restored.creative, restored.creative.results[0], '光线更柔和')
    assert.deepEqual(edit.design, request.design)
    assert.equal(edit.styleReference, preset.cover)
    assert.equal(edit.size, request.size)
    assert.deepEqual(edit.images, ['/storage/fixtures/archived.jpg', ...request.images])
    assert.deepEqual(edit.productImages, request.productImages)
    restored.creative.results.push({ ...restored.creative.results[0], id: 'archived-edit', operation: 'edit', request: edit })
    assert.deepEqual(validateProject(restored).creative.results[1].request.design, request.design)
    restored.creative.results[0].request.design.purpose = purpose === 'main' ? 'detail' : 'main'
    assert.throws(() => validateProject(restored), /用途不匹配/, 'archival access cannot bypass purpose compatibility')
  }
})

test('historical pure-white main excludes copy, model and scene without remaining selectable', () => {
  const project = uploadOnly(); selectStorefrontGoal(project, 'main')
  project.creative.useModel = true; project.creative.useScene = true
  project.creative.campaign.textMode = 'facts'; project.creative.campaign.facts.title = '不能画上去'
  const request = beforeRetirement('bold-color', () => {
    selectStorefrontStyle(project, 'bold-color')
    return storefrontImageRequests(project)[0].request
  })
  assert.equal(request.poster, undefined)
  assert.deepEqual(request.campaign.referenceRoles, ['product'])
  assert.match(request.prompt, /纯白底商品摄影/)
  assert(!request.prompt.includes('不能画上去'))
  assert(!stylesForPurpose('main').some(style => style.id === 'bold-color'))
})

test('template pack is ready for supplied images but unavailable templates cannot be generated', () => {
  const manifest = JSON.parse(readFileSync(new URL('../../docs/ecommerce-template-pack/manifest.json', import.meta.url), 'utf8'))
  const project = uploadOnly()
  assert.equal(manifest.filter(item => item.purpose === 'main').length, 8)
  assert.equal(manifest.filter(item => item.purpose === 'detail').length, 12)
  for (const spec of manifest) {
    const preset = STYLE_PRESETS.find(item => item.id === spec.id)
    assert(preset?.prompt && preset.generationRules.length && preset.palette.length === 4)
    assert.equal(preset.cover.split('/').at(-1), spec.filename)
    assert(readFileSync(new URL('../../docs/ecommerce-template-pack/' + spec.promptFile, import.meta.url), 'utf8').includes(spec.filename))
    assert.equal(campaignStyle(preset.id, 'none').palette.length, 4, 'new main styles can render application copy without palette errors')
    selectStorefrontGoal(project, spec.purpose)
    // Exercise the enabled path in memory without activating templates or making paid requests.
    const enabled = preset.enabled
    try {
      preset.enabled = false
      assert(!stylesForPurpose(spec.purpose).includes(preset))
      assert.throws(() => selectStorefrontStyle(project, preset.id), /用途不匹配/)
      project.creative.presetId = preset.id
      assert.throws(() => storefrontImageRequests(project), /用途不匹配/)
      preset.enabled = true
      selectStorefrontStyle(project, preset.id)
      const request = storefrontImageRequests(project)[0].request
      assert.equal(request.styleReference, preset.cover)
      assert(request.prompt.includes(preset.prompt))
      if (spec.purpose === 'detail') {
        assert.equal(request.design.layout, 'template')
        assert(request.prompt.includes(preset.detailLayout))
      }
      const result = { id: 'pack-result', name: preset.name, src: '/storage/fixtures/pack.jpg', width: 1024, height: 1536,
        operation: 'generate', createdAt: new Date().toISOString(), request }
      assert.deepEqual(buildEditRequest('creative', project.creative, result, '更明亮').design, request.design)
    } finally { preset.enabled = enabled }
  }
})

test('poster styles include palettes required by client-side copy rendering', () => {
  const posters = stylesForPurpose('poster')
  assert.deepEqual(posters.map(style => style.id), ['poster-hero', 'poster-tech'])
  for (const poster of posters) {
    assert.equal(poster.palette.length, 4, poster.id + ': poster palette must have four colors')
    assert.equal(typeof poster.lighting, 'string')
    assert.equal(campaignStyle(poster.id, 'facts').palette.length, 4)
  }
})

test('catalog names, categories and descriptions all have English mappings', () => {
  const locale = readFileSync(new URL('../src/i18n/locales/ecommerceStyles.ts', import.meta.url), 'utf8').split('export const ecommerceStyleEn = ')[1]
  const keys = new Set([...locale.matchAll(/^  (".*"): /gm)].map(match => JSON.parse(match[1])))
  for (const preset of STYLE_PRESETS) for (const key of ['name', 'category', 'description']) assert(keys.has(preset[key]), preset.id + ': ' + key)
})

function uploadOnly() {
  const project = createStorefrontProject()
  selectStorefrontGoal(project, 'detail')
  project.product.uploads = ['front', 'back'].map(id => ({ id, name: id, src: `/storage/fixtures/${id}.jpg`, width: 1000, height: 1500 }))
  project.product.selectedUploadId = 'front'
  return project
}

test('upload-only storefront makes one complete template detail without paid intermediate steps', () => {
  const project = uploadOnly()
  const before = JSON.stringify(project)
  const items = storefrontImageRequests(project)
  assert.equal(items.length, 1)
  assert.equal(items[0].pageKey, '')
  assert.equal(items[0].request.size, '1365x2048')
  assert.equal(imageReferenceInput(items[0].request).images.length, 3)
  assert.deepEqual(items[0].request.referenceRoles, ['product'])
  assert.equal(items[0].request.campaign, undefined)
  assert.deepEqual(items[0].request.design, { purpose: 'detail', presetId: 'detail-brand-minimal', layout: 'template' })
  assert.equal(JSON.stringify(project), before, 'building a plan does not create synthetic saved results')
})

test('new storefront work prefers visible uploads to hidden legacy extraction selections', () => {
  const project = uploadOnly()
  const processed = { id: 'old-extraction', name: '商品提取', src: '/storage/fixtures/extracted.jpg', width: 2048, height: 1365,
    createdAt: new Date().toISOString(), operation: 'generate', request: { ...buildProductRequest(project.product), n: 1 } }
  project.product.results = [processed]; project.product.activeResultId = processed.id
  project.integration.useProcessedProduct = true
  const historical = JSON.stringify(processed)
  for (const goal of ['main', 'detail', 'poster', 'tryon']) {
    selectStorefrontGoal(project, goal)
    const before = JSON.stringify(project)
    const request = storefrontImageRequests(project)[0].request
    assert(request.images.includes('/storage/fixtures/front.jpg'))
    assert(!imageReferenceInput(request).images.includes(processed.src))
    assert.equal(JSON.stringify(project), before, 'new generation leaves historical data intact')
  }
  selectStorefrontGoal(project, 'video')
  assert(!storefrontVideoRequest(project).image_with_roles.some(ref => ref.url === processed.src))
  assert.equal(JSON.stringify(project.product.results[0]), historical)
  project.product.uploads = []; project.product.selectedUploadId = ''
  selectStorefrontGoal(project, 'detail')
  assert(storefrontImageRequests(project)[0].request.images.includes(processed.src), 'result-only legacy projects retain their only product reference')
})

test('marketing copy is frozen separately from the product photo and survives edits', () => {
  const project = uploadOnly(); selectStorefrontGoal(project, 'poster')
  project.creative.copy = { enabled: true, headline: '日常之选', subtitle: '让生活多一点心动', sellingPoints: ['简洁外观'] }
  const request = storefrontImageRequests(project)[0].request
  assert.equal(request.poster.headline, '日常之选')
  assert(!request.prompt.includes('让生活多一点心动'), 'marketing text is rendered separately, not drawn by the image model')
  const result = { id: 'poster', src: '/storage/fixtures/poster.jpg', name: '宣传图', width: 1365, height: 2048, createdAt: new Date().toISOString(), operation: 'generate', request }
  project.creative.results.push(result)
  const restored = validateProject(JSON.parse(JSON.stringify(project)))
  const edit = buildEditRequest('creative', restored.creative, restored.creative.results[0], '光线明亮一点')
  assert.deepEqual(edit.poster, request.poster)
  project.creative.copy.headline = '改后的文案'
  assert.equal(request.poster.headline, '日常之选', 'submitted copies do not change with the next draft')
  selectStorefrontGoal(project, 'detail')
  const detail = storefrontImageRequests(project)[0].request
  assert.equal(detail.poster, undefined, 'template text is composed inside the complete layout')
  assert.match(detail.prompt, /改后的文案/)
  assert.match(detail.prompt, /让生活多一点心动/)
  project.creative.copy.headline = '字'.repeat(21)
  assert.throws(() => validateProject(project), /宣传文案/)
})

test('old pack goals migrate to one detail image while retaining uploaded product information', () => {
  const project = uploadOnly()
  for (const [goal, count] of [['detail',1], ['hero',1], ['full',1], ['poster',1]]) {
    selectStorefrontGoal(project, goal)
    const items = storefrontImageRequests(project)
    assert.equal(items.length, count)
    assert(items.every(item => imageReferenceInput(item.request).images.includes('/storage/fixtures/back.jpg')))
    if (['hero', 'full'].includes(goal)) assert.equal(project.integration.goal, 'detail')
  }
  project.product.config.name = '红色保温杯'; project.product.config.notes = '容量 500ml'
  selectStorefrontGoal(project, 'hero')
  assert.match(storefrontImageRequests(project)[0].request.prompt, /红色保温杯/)
  assert.match(storefrontImageRequests(project)[0].request.prompt, /500ml/)
})

test('confirmed product description becomes exact detail text without a generated headline', () => {
  const project = uploadOnly()
  const lines = ['材质：100% 纯棉', '规格：M / L / XL', '价格：¥129.90', '简介：轻松穿出日常风格']
  project.product.config.notes = lines.join('\r\n')
  project.creative.copy.sellingPoints = ['柔软亲肤']
  // Old poster-only facts must not silently appear in a detail image.
  project.creative.copy.facts.price = '¥999'
  const restored = validateProject(JSON.parse(JSON.stringify(project)))
  assert.equal(restored.product.config.notes, project.product.config.notes)
  const request = storefrontImageRequests(restored)[0].request
  for (const line of lines) {
    assert(request.prompt.includes(JSON.stringify(line)), 'description lines are explicitly approved on-image text')
    assert.equal(request.prompt.split(line).length - 1, 1, 'facts are not duplicated as additional background text')
  }
  assert.match(request.prompt, /柔软亲肤/)
  assert.match(request.prompt, /材质、规格、价格、数字和单位必须保持原文/)
  assert(!request.prompt.includes('不添加新的营销文字'), 'manual-only copy does not conflict with a no-text instruction')
  assert(!request.prompt.includes('¥999'), 'hidden poster facts are not part of confirmed detail copy')
  assert.equal(request.poster, undefined)
  restored.creative.results.push({ id: 'manual-detail', name: '详情图', src: '/storage/fixtures/manual-detail.jpg',
    width: 1365, height: 2048, createdAt: new Date().toISOString(), operation: 'generate', request })
  const history = validateProject(JSON.parse(JSON.stringify(restored)))
  history.product.config.notes = '价格：¥149'
  const edit = buildEditRequest('creative', history.creative, history.creative.results[0], '背景更浅一些')
  assert(history.creative.results[0].request.prompt.includes('¥129.90'), 'history retains the frozen confirmed description')
  assert.match(edit.prompt, /当前页面主题、文案及视觉风格/)
  assert(!edit.prompt.includes('¥149'))
  history.creative.copy.enabled = false
  const withoutText = storefrontImageRequests(history)[0].request
  assert.match(withoutText.prompt, /商品简介仅作为商品参考，不排入图中文字/)
  assert(!withoutText.prompt.includes('将以下已确认文案'))
  assert.match(withoutText.prompt, /商品资料.*价格：¥149/)
})

test('description-only details and empty optional points survive persistence', () => {
  const project = uploadOnly()
  project.product.config.notes = '材质：陶瓷\n容量：300ml\n价格：¥59'
  project.creative.copy.sellingPoints = ['', '']
  const restored = validateProject(JSON.parse(JSON.stringify(project)))
  assert.deepEqual(restored.creative.copy.sellingPoints, [])
  for (const preset of stylesForPurpose('detail')) {
    selectStorefrontStyle(restored, preset.id)
    const request = storefrontImageRequests(restored)[0].request
    assert(request.prompt.includes(JSON.stringify('价格：¥59')))
    assert.match(request.prompt, /将以下已确认文案/)
  }
  selectStorefrontGoal(restored, 'main')
  assert.equal(restored.product.config.notes, project.product.config.notes, 'changing image purpose retains manual facts')
  const main = storefrontImageRequests(restored)[0].request
  assert.match(main.prompt, /商品资料/)
  assert(!main.prompt.includes('将以下已确认文案'), 'main images keep descriptions as background, not a dense detail body')
})

test('manual subtitle, selling points and poster prices do not require an AI headline', () => {
  const project = uploadOnly()
  for (const purpose of ['main', 'poster']) {
    selectStorefrontGoal(project, purpose)
    for (const field of ['subtitle', 'sellingPoints']) {
      project.creative.copy.subtitle = field === 'subtitle' ? '每天的舒适选择' : ''
      project.creative.copy.sellingPoints = field === 'sellingPoints' ? ['柔软亲肤'] : []
      const request = storefrontImageRequests(project)[0].request
      assert.equal(request.poster.headline, '')
      assert.deepEqual(request.poster.sellingPoints, project.creative.copy.sellingPoints)
      assert.equal(request.poster.subtitle, project.creative.copy.subtitle)
      assert.match(request.prompt, /本次仅生成无营销文字的商品底图/)
    }
  }
  project.creative.copy.subtitle = ''; project.creative.copy.sellingPoints = []
  project.creative.copy.facts.price = '¥129.90'
  assert.equal(storefrontImageRequests(project)[0].request.poster.facts.price, '¥129.90')
  selectStorefrontGoal(project, 'main')
  assert.equal(storefrontImageRequests(project)[0].request.poster, undefined, 'stale poster prices do not create a blank main-image overlay')
  project.creative.copy.headline = '舒适之选'
  assert.equal(storefrontImageRequests(project)[0].request.poster.facts.price, '', 'stale format cannot leak poster claims into a main image')
})

test('all detail templates produce one composition with exact copy, independent references and frozen edits', () => {
  const project = uploadOnly(); selectStorefrontGoal(project, 'poster')
  project.creative.campaign.excludedIds = ['H3']
  project.creative.copy = { enabled: true, headline: '日常好物', subtitle: '与你相伴', sellingPoints: ['红色外观', '清晰纹理', '500ml容量'] }
  assert.throws(() => selectStorefrontStyle(project, 'monochrome'), /用途不匹配/)
  assert.equal(project.integration.goal, 'poster')
  selectStorefrontGoal(project, 'detail')
  project.creative.count = 4
  project.creative.campaign.detailCount = 9
  project.creative.campaign.excludedIds = ['D1', 'D2', 'D3']
  const templates = stylesForPurpose('detail')
  assert(templates.length >= 8)
  for (const preset of templates) {
    selectStorefrontStyle(project, preset.id)
    const items = storefrontImageRequests(project)
    assert.equal(items.length, 1)
    const item = items[0]
    assert.equal(item.pageKey, '')
    assert.equal(item.request.n, 1)
    assert.equal(item.request.poster, undefined)
    assert.equal(item.request.campaign, undefined)
    assert(item.request.prompt.includes(preset.detailLayout))
    assert.match(item.request.prompt, /同一张连续画布/)
    assert.match(item.request.prompt, /日常好物/)
    assert.match(item.request.prompt, /500ml容量/)
    const input = imageReferenceInput(item.request)
    assert.deepEqual(input.images, ['/storage/fixtures/front.jpg', '/storage/fixtures/back.jpg', preset.cover])
    assert.match(input.prompt, /第 2 至 2 张图补充同一件商品/)
    assert.match(input.prompt, /第 3 张是独立的版式风格参考/)
    assert.match(input.prompt, /把所有模块组合在这一张完整详情图中/)
    assert.match(input.prompt, /不复制其中商品、人物、品牌、文案、数字/)
  }
  selectStorefrontStyle(project, 'detail-beauty-soft')
  const request = storefrontImageRequests(project)[0].request
  const result = { id: 'amazon', name: '卖点', src: '/storage/fixtures/amazon.jpg', width: 2048, height: 2048, operation: 'generate', createdAt: new Date().toISOString(), request }
  project.creative.results.push(result)
  const restored = validateProject(JSON.parse(JSON.stringify(project)))
  const edit = buildEditRequest('creative', restored.creative, restored.creative.results[0], '光线柔和一些')
  assert.equal(edit.styleReference, request.styleReference)
  assert.equal(edit.poster, undefined)
  assert.deepEqual(edit.design, { purpose: 'detail', presetId: 'detail-beauty-soft', layout: 'template' })
  assert.deepEqual(imageReferenceInput(edit).images, [result.src, '/storage/fixtures/front.jpg', '/storage/fixtures/back.jpg', request.styleReference])
  selectStorefrontStyle(project, 'detail-tech-dark')
  assert.equal(project.integration.goal, 'detail')
  assert.equal(storefrontImageRequests(project).length, 1)
  assert.equal(request.design.presetId, 'detail-beauty-soft', 'submitted layout remains frozen when the next style changes')
  project.creative.copy.enabled = false
  assert.match(storefrontImageRequests(project)[0].request.prompt, /不添加新的营销文字/)
})

test('purpose controls compatible templates without changing page exclusions or the goal', () => {
  const project = uploadOnly()
  for (const purpose of IMAGE_PURPOSES.filter(purpose => !purpose.legacy)) {
    selectStorefrontGoal(project, purpose.id)
    assert(stylesForPurpose(purpose.id).length > 0)
    assert(styleSupportsPurpose(project.creative.presetId, purpose.id))
    for (const preset of STYLE_PRESETS) {
      if (styleSupportsPurpose(preset.id, purpose.id)) {
        project.creative.campaign.excludedIds = ['D9']
        selectStorefrontStyle(project, preset.id)
        assert.equal(project.integration.goal, purpose.id)
        assert.deepEqual(project.creative.campaign.excludedIds, ['D9'])
      } else {
        const before = JSON.stringify(project)
        assert.throws(() => selectStorefrontStyle(project, preset.id), /用途不匹配/)
        assert.equal(JSON.stringify(project), before)
      }
    }
  }
  selectStorefrontGoal(project, 'tryon')
  project.creative.presetId = 'monochrome'
  assert.throws(() => storefrontImageRequests(project), /用途不匹配/)
  selectStorefrontGoal(project, 'tryon')
  assert.equal(project.creative.presetId, 'tryon-studio', 'loading an old conflicting draft selects a compatible default')
  assert.equal(storefrontImageRequests(project)[0].request.design.purpose, 'tryon')
})

test('new main image stays single, square and preserves its design in edits', () => {
  const project = uploadOnly(); selectStorefrontGoal(project, 'main')
  project.creative.copy = { enabled: true, headline: '商品标题', subtitle: '', sellingPoints: [] }
  const items = storefrontImageRequests(project)
  assert.deepEqual(items.map(item => item.pageKey), ['M1'])
  const request = items[0].request
  assert.equal(request.poster.headline, '商品标题')
  assert.equal(request.size, '2048x2048')
  assert.deepEqual(request.campaign.referenceRoles, ['product'])
  assert.match(request.prompt, /一张独立商品主图/)
  assert.deepEqual(request.design, { purpose: 'main', presetId: 'main-color-block' })
  selectStorefrontStyle(project, 'main-tech-rim')
  const designed = storefrontImageRequests(project)[0].request
  assert.equal(designed.poster.headline, '商品标题')
  assert.equal(designed.campaign.shotId, 'M1')
  const result = { id: 'single', name: '主图', src: '/storage/fixtures/single.jpg', width: 2048, height: 2048, createdAt: new Date().toISOString(), operation: 'generate', request: designed }
  project.creative.results.push(result)
  const restored = validateProject(JSON.parse(JSON.stringify(project)))
  selectStorefrontGoal(restored, 'tryon')
  const edit = buildEditRequest('creative', restored.creative, restored.creative.results[0], '减弱阴影')
  assert.deepEqual(edit.design, { purpose: 'main', presetId: 'main-tech-rim' }, 'editing retains the output purpose even after switching modes')
})

test('layout references obey the image limit and cannot become arbitrary imported URLs', () => {
  const project = uploadOnly(); selectStorefrontStyle(project, 'detail-aplus-light')
  for (let i = 2; i < 12; i++) project.product.uploads.push({ id: `extra-${i}`, name: 'extra', src: `/storage/fixtures/${i}.jpg`, width: 1000, height: 1000 })
  const request = storefrontImageRequests(project)[0].request
  assert.throws(() => imageReferenceInput(request), /参考图片超过上限/)
  const clean = uploadOnly(); const allowed = storefrontImageRequests(clean)[0].request
  clean.creative.results.push({ id: 'x', name: '商品', src: '/storage/fixtures/x.jpg', width: 2048, height: 2048, operation: 'generate', createdAt: new Date().toISOString(), request: { ...allowed, styleReference: 'https://untrusted.example/style.jpg' } })
  assert.throws(() => validateProject(clean), /版式参考无效/)
  clean.creative.results[0].request = { ...allowed, poster: { headline: '商品', subtitle: '', sellingPoints: [], presetId: 'monochrome', layout: 'unknown' } }
  assert.throws(() => validateProject(clean), /文案版式无效/)
})

test('uploaded model and scene are reused immediately with correctly ordered supplemental product photos', () => {
  const project = uploadOnly()
  project.model.source = 'upload'; project.model.uploads = [{ id: 'model-upload', name: '模特', src: '/storage/fixtures/person.jpg', width: 1000, height: 1500 }]
  project.model.selectedUploadId = 'model-upload'; project.creative.useModel = true
  project.scene.source = 'presets'; project.scene.presetId = 'office'; project.creative.useScene = true
  const items = storefrontImageRequests(project)
  const detail = imageReferenceInput(items[0].request)
  assert.deepEqual(items[0].request.referenceRoles, ['model', 'product', 'scene'])
  assert.equal(detail.images[0], '/storage/fixtures/person.jpg')
  assert.equal(detail.images[2], SCENE_PRESETS.find(preset => preset.id === 'office').cover)
  assert.match(detail.prompt, /第 4 至 4 张图/)
  assert.match(detail.prompt, /第 5 张是独立的版式风格参考/)
  selectStorefrontGoal(project, 'tryon')
  assert.equal(storefrontImageRequests(project).length, 1)
})

test('supplemental product references survive reload and edits while the original page stays square', () => {
  const project = uploadOnly()
  selectStorefrontGoal(project, 'main')
  const request = storefrontImageRequests(project)[0].request
  const result = { id: 'finished-main', src: '/storage/fixtures/main.jpg', name: '主图', width: 2048, height: 2048, createdAt: new Date().toISOString(), operation: 'generate', request }
  project.creative.results.push(result); project.creative.activeResultId = result.id
  const restored = validateProject(JSON.parse(JSON.stringify(project)))
  assert.deepEqual(restored.creative.results[0].request.productImages, ['/storage/fixtures/back.jpg'])
  assert.equal(restored.product.selectedUploadId, 'front')
  const edit = buildEditRequest('creative', restored.creative, restored.creative.results[0], '背景更亮')
  assert.equal(edit.size, '2048x2048')
  assert.deepEqual(imageReferenceInput(edit).images, ['/storage/fixtures/main.jpg','/storage/fixtures/front.jpg','/storage/fixtures/back.jpg', '/admin/ecommerce-box/styles/main-color-block.jpg'])
  restored.creative.results[0].request.productImages = ['file:///etc/passwd']
  assert.throws(() => validateProject(restored), /图片地址无效/)
})

test('upload-only video builds its script automatically with explicit duration and all product angles', () => {
  const project = uploadOnly(); selectStorefrontGoal(project, 'video')
  project.creative.video.duration = 15; project.creative.video.aspect_ratio = '9:16'
  const request = storefrontVideoRequest(project)
  assert.equal(request.duration, 15); assert.equal(request.aspect_ratio, '9:16')
  assert.deepEqual(request.image_with_roles.map(item => item.url), ['/storage/fixtures/front.jpg','/storage/fixtures/back.jpg'])
  assert.match(request.prompt, /15 秒/)
  assert.equal(project.creative.video.prompt, '', 'automatic scripts do not become stale handwritten prompts')
  for (let index = 0; index < 8; index++) project.product.uploads.push({ id: `extra-${index}`, name: 'extra', src: `/storage/fixtures/extra-${index}.jpg`, width: 1000, height: 1500 })
  assert.throws(() => storefrontVideoRequest(project), /最多使用 9 张参考图/)
})

test('try-on directly creates a model with the product and preserves that identity when edited', () => {
  assert.throws(() => storefrontImageRequests(createStorefrontProject()), /添加商品图片/)
  const project = uploadOnly(); selectStorefrontGoal(project, 'tryon')
  project.creative.useModel = true // Old drafts may have enabled model references without adding one.
  project.creative.notes = '自然短发，通勤穿搭'
  project.creative.useScene = true; project.scene.source = 'presets'; project.scene.presetId = 'office'
  const request = storefrontImageRequests(project)[0].request
  assert.equal(request.autoModel, true)
  assert.deepEqual(request.referenceRoles, ['product', 'scene'])
  assert.match(request.prompt, /成年虚拟模特/)
  assert.match(request.prompt, /自然短发/)
  assert(!/不出现人物|不绘制人物|不添加人物/.test(request.prompt))
  const result = { id: 'tryon', src: '/storage/fixtures/tryon.jpg', name: '试穿', width: 1365, height: 2048, createdAt: new Date().toISOString(), operation: 'generate', request }
  project.creative.results.push(result)
  const restored = validateProject(JSON.parse(JSON.stringify(project)))
  assert.equal(restored.creative.results[0].request.autoModel, true)
  const edit = buildEditRequest('creative', restored.creative, restored.creative.results[0], '背景明亮一点')
  assert.equal(edit.autoModel, true)
  assert.deepEqual(edit.images, [result.src, ...request.images])
  assert.match(edit.prompt, /虚拟模特是唯一人物身份依据/)
  assert(!edit.prompt.includes('不添加人物'))
})

test('commercial video timelines fit the requested duration and stay distinct from UGC', () => {
  for (const duration of [5, 10, 15]) {
    const project = uploadOnly(); selectStorefrontGoal(project, 'video')
    project.creative.video.duration = duration
    const request = storefrontVideoRequest(project)
    assert.match(request.prompt, /商业商品展示短片/)
    assert(!request.prompt.includes('不要做成专业影棚大片或商业广告片'))
    const segments = [...request.prompt.matchAll(/(\d+)-(\d+) 秒：/g)].map(match => [Number(match[1]), Number(match[2])])
    assert.equal(segments[0][0], 0); assert.equal(segments.at(-1)[1], duration)
    assert.equal(segments.reduce((sum, [a,b]) => sum + b - a, 0), duration)
    assert(segments.every(([a,b], index) => b > a && (index === 0 || a === segments[index - 1][1])))
    project.creative.video.style = 'ugc'
    assert.match(storefrontVideoRequest(project).prompt, /手机手持随手拍短片/)
    assert.equal(validateProject(project).creative.video.style, 'ugc')
  }
})

test('story video keeps two identities, exact dialogue and product references without the single-model template', () => {
  const project = uploadOnly(); selectStorefrontGoal(project, 'video')
  const video = project.creative.video
  video.duration = 15; project.creative.useModel = true
  Object.assign(video.story, { enabled: true, idea: '女儿送油', expandedIdea: '女儿送油', duration: 15, hasDialogue: true,
    script: '0-6 秒：女儿把油递给妈妈。\n6-15 秒：妈妈说：“这油多少钱？”女儿说：“现在买很实惠。”',
    characters: [{ id: 'c1', name: '女儿', description: '成年女性，浅色上衣', source: 'upload', image: { id: 'daughter', name: '女儿', src: '/storage/fixtures/daughter.jpg', width: 1000, height: 1500 } },
      { id: 'c2', name: '妈妈', description: '中年女性，厨房围裙', source: 'ai', image: { id: 'mom', name: '妈妈', src: '/storage/fixtures/mom.jpg', width: 1000, height: 1500 } }] })
  const request = storefrontVideoRequest(project)
  assert.deepEqual(request.image_with_roles.map(item => item.url), ['/storage/fixtures/daughter.jpg', '/storage/fixtures/mom.jpg', '/storage/fixtures/front.jpg', '/storage/fixtures/back.jpg'])
  assert.match(request.prompt, /第1张参考图.*角色“女儿”/)
  assert.match(request.prompt, /第2张参考图.*角色“妈妈”/)
  assert.match(request.prompt, /这油多少钱/)
  assert(!request.prompt.includes('商业商品展示短片'))
  assert(!request.prompt.includes('唯一模特身份依据'))
  const restored = validateProject(JSON.parse(JSON.stringify(project)))
  assert.equal(restored.creative.video.story.characters[1].image.src, '/storage/fixtures/mom.jpg')
  video.duration = 10
  assert.throws(() => storefrontVideoRequest(project), /重新扩展剧情/)
  video.duration = 15; video.generate_audio = false
  assert.throws(() => storefrontVideoRequest(project), /开启生成声音/)
  video.generate_audio = true; video.story.characters[0].image = null
  assert.throws(() => storefrontVideoRequest(project), /女儿上传或生成人物图片/)
  video.story.characters[0].source = 'description'
  assert.equal(storefrontVideoRequest(project).image_with_roles.length, 3)
  video.story.idea = '新剧情'
  assert.throws(() => storefrontVideoRequest(project), /重新扩展剧情/)
  video.story.enabled = false; project.creative.useModel = false
  assert.match(storefrontVideoRequest(project).prompt, /商业商品展示短片/)
})

test('AI character results bind only to the matching role and description and survive persistence', () => {
  const project = uploadOnly()
  const character = { id: 'c1', name: '女儿', description: '成年女性，浅色上衣', source: 'ai', image: null }
  project.creative.video.story.characters = [character]
  const request = characterImageRequest(character, '2K')
  assert.equal(request.n, 1); assert.deepEqual(request.images, [])
  const result = { id: 'job-100', name: '女儿', src: '/storage/fixtures/person.jpg', width: 1000, height: 1500, operation: 'generate', createdAt: new Date().toISOString(), request }
  project.model.results.push(result)
  const restored = validateProject(JSON.parse(JSON.stringify(project)))
  assert.equal(restored.model.results[0].request.storyCharacterId, 'c1')
  attachCharacterResult(restored, { request: { original: restored.model.results[0].request } }, result)
  assert.equal(restored.creative.video.story.characters[0].image.src, result.src)
  character.description = '长发新造型'
  attachCharacterResult(project, { request: { original: request } }, result)
  assert.equal(character.image, null, 'an old generation cannot replace a changed character')
  restored.creative.video.story.characters[0].image.src = 'file:///etc/passwd'
  assert.throws(() => validateProject(restored), /人物图片无效/)
})
