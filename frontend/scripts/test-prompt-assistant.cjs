const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const path = require('node:path')
const os = require('node:os')

const base = process.env.PROMPT_UI_BASE_URL || 'http://127.0.0.1:5177'
const outputDir = path.join(os.tmpdir(), 'workflow-plus-prompt-check')
fs.mkdirSync(outputDir, { recursive: true })
const user = { id: 901, username: 'prompt-check', display_name: 'Prompt Check', role: 'user', preferred_locale: 'zh-CN', credit_balance: 1000 }
const model = (id, type, name) => ({ id, type, name, model_id: type === 'text' ? 'deepseek-flash' : 'fixture', enabled: 1, is_default: 1, options: {}, endpoint: '', api_key_mask: '', create_time: null, update_time: null })
const groups = ['text', 'image', 'video'].map((type, i) => ({ type, title: type, models: [{ ...model(i + 1, type, type === 'text' ? 'DeepSeek' : type), options: type === 'text' ? { supports_vision: true } : {} }] }))
const builtin = { id: 'builtin:product-image', name: 'product-image', title: '产品图片', description: '围绕真实产品生成图片提示词', modes: ['image'], status: 'ready', is_builtin: true, version: '1.0', license: 'MIT', source_url: '', evaluations: {}, instructions: '保留真实产品外观和已提供的约束。', markdown: '---\nname: product-image\ndescription: Product image prompts\n---\nPreserve product details.' }
const candidate = { ...builtin, id: 'private:1', name: 'custom-visual-method', title: 'custom-visual-method', status: 'draft', is_builtin: false }
const videoSkill = { ...builtin, id: 'builtin:product-video', name: 'product-video', title: '产品营销视频', modes: ['video'] }

async function setup(page, viewport) {
  const state = { sessions: [], references: [], skills: [builtin, videoSkill], chatCalls: [], quickCreateCalls: [], templates: [], failNext: false, candidateNext: false, reviewUnavailableNext: false, selectReferenceIndex: 0, uploadAttempts: 0, failUploadAttempt: 0, uploadDelay: 0, videoShotDurations: [5, 5] }
  const modelGroups = structuredClone(groups)
  modelGroups.find((group) => group.type === 'video').models.push(
    { ...model(4, 'video', '自定义时长模型'), is_default: 0, options: { duration_options: [6, 8] } },
    { ...model(5, 'video', 'MiniMax 测试模型'), is_default: 0, options: { provider: 'minimax_v2' } },
  )
  await page.setViewportSize(viewport)
  await page.addInitScript((profile) => {
    localStorage.setItem('malulu.auth.token', 'prompt-assistant-isolated-test')
    localStorage.setItem('malulu.auth.user', JSON.stringify(profile))
    localStorage.setItem('malulu.auth.expiresAt', '4102444800')
  }, user)
  await page.route('**/api/**', async (route) => {
    const url = new URL(route.request().url())
    if (!url.pathname.startsWith('/api/')) return route.continue()
    if (url.pathname === '/api/prompt-assistant/upload') {
      const attempt = ++state.uploadAttempts
      if (state.uploadDelay) await new Promise((resolve) => setTimeout(resolve, state.uploadDelay))
      if (attempt === state.failUploadAttempt) return route.fulfill({ json: { code: 1, message: 'Fixture upload failed' } })
      const id = state.references.length + 1
      const data = { id, kind: 'upload', name: `product-${id}.png`, url: `${base}/admin/logo.png?reference=${id}` }
      state.references.push(data)
      return route.fulfill({ json: { code: 0, data, message: 'success' } })
    }
    const payload = route.request().postData() ? route.request().postDataJSON() : {}
    let data = []
    const findSession = () => state.sessions.find((item) => item.id === payload.id)
    if (url.pathname === '/api/auth/me') data = { user }
    if (url.pathname === '/api/model-configs/list') data = modelGroups
    if (url.pathname === '/api/assets/list') data = [{ id: 23, type: 'prop', name: '保温杯', description: '白色保温杯', images: [] }]
    if (url.pathname === '/api/quick-create/messages/list') data = { messages: [], has_more: false, oldest_id: 0, limit: 10 }
    if (url.pathname === '/api/quick-create/messages/send') {
      state.quickCreateCalls.push(payload)
      data = { message: { ...payload, id: state.quickCreateCalls.length, status: 'success', result_urls: [], error_message: '' } }
    }
    if (url.pathname === '/api/quick-create/messages/status') data = { messages: [] }
    if (url.pathname === '/api/prompt-assistant/list') data = state.sessions.map((item) => ({ id: item.id, title: item.title, status: item.status, mode: item.settings.mode, update_time: item.update_time }))
    if (url.pathname === '/api/prompt-assistant/skills') data = state.skills
    if (url.pathname === '/api/prompt-assistant/skill-detail') data = state.skills.find((item) => item.id === payload.id)
    if (url.pathname === '/api/prompt-assistant/create') {
      data = { id: state.sessions.length + 1, title: '新的提示词', settings: payload.settings, status: 'idle', brief: {}, messages: [], results: [], request_id: '', error_message: '', update_time: '2026-10-15 16:00:00' }
      state.sessions.push(data)
    }
    if (url.pathname === '/api/prompt-assistant/detail') data = findSession()
    if (url.pathname === '/api/prompt-assistant/chat') {
      state.chatCalls.push(payload)
      data = findSession()
      data.settings = payload.settings
      data.references = (payload.settings.reference_ids || []).map((id) => state.references.find((reference) => reference.id === id))
      data.request_id = payload.request_id
      const seen = data.messages.some((entry) => entry.request_id === payload.request_id)
      if (!seen) data.messages.push({ role: 'user', text: payload.message, request_id: payload.request_id, references: [...data.references] })
      data.title = data.messages[0].text.slice(0, 48)
      if (state.failNext) {
        state.failNext = false
        data.status = 'failed'
        data.error_message = '模型响应超时，请重试本次消息'
      } else if (data.messages.length === 1 && !seen) {
        data.status = 'awaiting_input'
        const questions = [{ text: '使用场景', options: ['电商详情', '社交平台'] }]
        if (payload.settings.mode === 'video' && !payload.settings.duration_confirmed) questions.unshift({ text: '这个视频需要生成多少秒？', options: ['5秒', '10秒', '15秒'] })
        data.messages.push({ role: 'assistant', text: '这是什么产品？准备在哪里使用？', questions, action: 'ask_user' })
      } else {
        data.status = 'completed'
        if (payload.settings.mode === 'video' && payload.message.includes('这个视频需要生成多少秒？\n5秒')) {
          data.settings = { ...data.settings, duration: 5, duration_confirmed: true }
        }
        data.error_message = ''
        data.brief = { subject: '保温杯', goal: '产品展示', constraints: '保留产品真实外观' }
        const selected = state.candidateNext ? candidate : payload.settings.mode === 'video' ? videoSkill : builtin
        if (state.candidateNext) { state.skills.push(candidate); state.candidateNext = false }
        const content = { title: '保温杯产品展示', prompt_body: '白色保温杯置于干净的展示台上，产品完整清晰，柔和侧光勾勒杯身轮廓。', style_block: '写实画风，保持产品材质与外观一致。', full_prompt: '写实画风\n\n白色保温杯置于干净的展示台上。',
          settings: data.settings, brief: data.brief, shots: payload.settings.mode === 'video' ? [
            { title: '展示产品', duration: state.videoShotDurations[0], prompt_body: '镜头缓慢接近保温杯，保持产品完整可见。' },
            { title: '结束定格', duration: state.videoShotDurations[1], prompt_body: '镜头停下，保温杯居中定格。' },
          ] : [], skills: [{ id: selected.id, name: selected.title, version: '1.0' }], match_reason: '适用于真实产品展示', assumptions: [], asset_requirements: [], asset_ids: [], checks: { status: 'checked', issues: [], notes: [] } }
        if (payload.settings.visual_style === 'anime') content.style_block = '二维动漫画风，保持产品外观一致。'
        if (state.reviewUnavailableNext) {
          state.reviewUnavailableNext = false
          content.checks = { status: 'needs_review', issues: ['自动审校未完成，已保留生成的提示词，请人工核验产品事实、画风和约束。'], notes: [] }
        }
        content.references = state.selectReferenceIndex ? data.references.filter((_, index) => index + 1 === state.selectReferenceIndex) : [...data.references]
        content.reference_usage = content.references.map((_, index) => ({ reference_index: index + 1, instruction: '保留产品外形、颜色和材质，背景替换为纯色影棚，不沿用原图背景。' }))
        if (content.reference_usage.length) content.prompt_body = content.reference_usage.map((usage) => `@图片${usage.reference_index}：${usage.instruction}`).join('\n') + '\n\n' + content.prompt_body
        if (!selected.is_builtin) content.candidate_skill_id = selected.id
        data.results.unshift({ id: data.results.length + 1, version: data.results.length + 1, content, create_time: '2026-10-15 16:01:00' })
        data.messages.push({ role: 'assistant', text: content.checks.status === 'needs_review' ? '提示词已保留，但本次自动检查未完成，使用前请确认内容。' : '已整理产品展示提示词。', questions: [], action: 'generate_prompt' })
      }
    }
    if (url.pathname === '/api/prompt-assistant/save-result') {
      data = findSession()
      const source = data.results.find((result) => result.id === payload.result_id)
      data.results.unshift({ ...source, id: data.results.length + 1, version: data.results.length + 1, content: { ...source.content, ...payload.patch, checks: { status: 'edited', issues: [], notes: [] } } })
    }
    if (url.pathname === '/api/prompt-templates/create') { state.templates.push(payload); data = { ...payload, id: 7 } }
    if (url.pathname === '/api/prompt-assistant/validate-skill') {
      data = state.skills.find((skill) => skill.id === payload.id)
      data.status = 'ready'
      data.evaluations = { evidence: ['保留约束并正确处理缺失信息'], issues: [], passed: true }
    }
    if (url.pathname === '/api/prompt-assistant/delete-skill') state.skills = state.skills.filter((skill) => skill.id !== payload.id)
    if (url.pathname === '/api/prompt-assistant/delete') state.sessions = state.sessions.filter((item) => item.id !== payload.id)
    await route.fulfill({ json: { code: 0, data, message: 'success' } })
  })
  await page.goto(`${base}/admin/prompts`)
  try { await page.getByRole('heading', { name: '这次想创作什么？' }).waitFor() }
  catch (error) {
    console.error('URL:', page.url(), 'BODY:', await page.locator('body').innerText())
    await page.screenshot({ path: path.join(outputDir, 'failure.png'), fullPage: true })
    throw error
  }
  return state
}

async function assertLayout(page) {
  const layout = await page.evaluate(() => ({ width: innerWidth, scrollWidth: document.documentElement.scrollWidth,
    composer: document.querySelector('.composer').getBoundingClientRect().toJSON(), viewport: innerHeight }))
  assert(layout.scrollWidth <= layout.width + 1, JSON.stringify(layout))
  if (await page.locator('.composer').isVisible()) assert(layout.composer.bottom <= layout.viewport + 1, JSON.stringify(layout))
}

async function imageDrag(page, buffer, count, eventName, selector = '.conversation-area') {
  await page.evaluate(({ imageBase64, count, eventName, selector }) => {
    const data = new DataTransfer()
    for (let index = 0; index < count; index++) data.items.add(new File([Uint8Array.from(atob(imageBase64), (char) => char.charCodeAt(0))], `reference-${index + 1}.png`, { type: 'image/png' }))
    document.querySelector(selector).dispatchEvent(new DragEvent(eventName, { dataTransfer: data, bubbles: true, cancelable: true }))
  }, { imageBase64: buffer.toString('base64'), count, eventName, selector })
}

async function selectQuickOption(page, selector, label) {
  await page.locator(`${selector} .el-select__wrapper`).click()
  await page.locator('.el-select-dropdown:visible').getByRole('option', { name: label, exact: true }).click()
  await page.locator('.el-select-dropdown:visible').waitFor({ state: 'hidden' })
}

;(async () => {
  const browser = await chromium.launch({ channel: 'msedge', headless: true })
  const errors = []
  try {
    const page = await browser.newPage()
    page.on('console', (message) => { if (message.type() === 'error' || message.type() === 'warning') console.error('BROWSER:', message.text()) })
    page.on('requestfailed', (request) => console.error('REQUEST FAILED:', request.url(), request.failure()?.errorText))
    page.on('pageerror', (error) => { errors.push(error.message); console.error('PAGE ERROR:', error.message) })
    const state = await setup(page, { width: 1440, height: 960 })
    const imageFile = { name: 'product.png', mimeType: 'image/png', buffer: fs.readFileSync(path.join(__dirname, '../public/logo.png')) }
    await assertLayout(page)
    await page.screenshot({ path: path.join(outputDir, 'desktop-empty.png'), fullPage: true })
    await page.getByRole('button', { name: '产品展示图片', exact: true }).click()
    await page.locator('input[type="file"]').setInputFiles(imageFile)
    await page.locator('.composer-references .reference-item').waitFor()
    assert.equal(await page.getByRole('button', { name: 'Skill 库', exact: true }).count(), 0)
    await page.getByRole('button', { name: '发送', exact: true }).click()
    await page.locator('.questions .el-radio').filter({ hasText: '电商详情' }).click()
    await page.getByRole('textbox', { name: '创作需求', exact: true }).fill('白色保温杯，突出轻便，产品外观必须保持一致。')
    await page.getByRole('button', { name: '提交回答', exact: true }).click()
    await page.getByRole('heading', { name: '保温杯产品展示', exact: true }).waitFor()
    assert(state.chatCalls[1].message.includes('电商详情'))
    assert.equal(state.chatCalls[0].settings.text_model_id, 1)
    assert.deepEqual(state.chatCalls[0].settings.reference_ids, [1])
    assert.equal(state.chatCalls[0].settings.vision_model_id, 1)
    await page.locator('.result-references img').waitFor()
    assert.equal(await page.locator('.result-references .reference-label').innerText(), '@图片1')
    assert((await page.locator('.prompt-text').first().innerText()).includes('@图片1：保留产品外形'))
    await page.waitForFunction(() => [...document.querySelectorAll('.result-references img')].every((image) => image.complete && image.naturalWidth > 0))
    assert.equal(await page.getByText('待确认与素材要求', { exact: true }).count(), 0)
    assert.equal(await page.getByText('风格与生成方法', { exact: true }).count(), 0)
    assert.equal(await page.getByText('设置已更改，当前结果仍使用原设置').count(), 0)
    await page.screenshot({ path: path.join(outputDir, 'desktop-result.png'), fullPage: true })
    await page.getByRole('button', { name: '编辑提示词', exact: true }).click()
    await page.getByRole('textbox', { name: '提示词正文', exact: true }).fill('编辑后的保温杯展示提示词。')
    await page.getByRole('button', { name: '编辑提示词', exact: true }).click()
    await page.getByRole('button', { name: '取消', exact: true }).click()
    assert.equal(await page.getByRole('textbox', { name: '提示词正文', exact: true }).inputValue(), '编辑后的保温杯展示提示词。')
    await page.getByRole('button', { name: '保存版本', exact: true }).click()
    await page.getByText('编辑后的保温杯展示提示词。', { exact: true }).waitFor()
    assert.equal(state.sessions[0].results.length, 2)
    await page.getByRole('button', { name: '保存模板', exact: true }).click()
    await page.getByText('已保存到提示词模板', { exact: true }).waitFor()
    assert.equal(state.templates[0].prompt, '编辑后的保温杯展示提示词。')
    await page.getByRole('button', { name: '需求摘要', exact: true }).click()
    await page.locator('.brief-list').getByText('保留产品真实外观', { exact: true }).waitFor()
    await page.getByRole('button', { name: '提示词', exact: true }).click()
    state.failNext = true
    await page.getByRole('textbox', { name: '创作需求', exact: true }).fill('再做一版')
    await page.getByRole('button', { name: '发送', exact: true }).click()
    await page.getByRole('button', { name: '重试本次消息', exact: true }).waitFor()
    const failedRequestId = state.chatCalls.at(-1).request_id
    state.reviewUnavailableNext = true
    await page.getByRole('button', { name: '重试本次消息', exact: true }).click()
    await page.getByRole('heading', { name: '保温杯产品展示', exact: true }).waitFor()
    assert.equal(state.chatCalls.at(-1).request_id, failedRequestId)
    await page.getByText('提示词已保留，但本次自动检查未完成，使用前请确认内容。', { exact: true }).waitFor()
    assert.equal(await page.locator('.result-notes').count(), 0)
    await page.locator('.prompt-text').waitFor()
    await page.screenshot({ path: path.join(outputDir, 'desktop-review-unavailable.png'), fullPage: true, animations: 'disabled' })
    state.candidateNext = true
    await page.getByRole('textbox', { name: '创作需求', exact: true }).fill('用新的特殊创作方法再做一版')
    await page.getByRole('button', { name: '发送', exact: true }).click()
    await page.waitForFunction(() => document.querySelectorAll('.message.assistant').length === 4)
    assert.equal(await page.getByText('本次使用候选 Skill', { exact: true }).count(), 0)
    await page.getByRole('button', { name: '在速创中使用', exact: true }).click()
    await page.waitForURL('**/admin/quick-create?prompt_draft=*')
    await page.locator('[contenteditable="true"]').filter({ hasText: '白色保温杯置于干净的展示台上' }).waitFor()
    assert.equal((await page.locator('[contenteditable="true"]').innerText()).split('写实画风').length - 1, 1)
    await page.locator('.qc-pending-ref').waitFor()
    assert.equal(await page.locator('.qc-pending-ref').count(), 1)
    assert.equal(await page.locator('.qc-pending-ref img').getAttribute('src'), state.references[0].url)
    assert.equal(await page.evaluate(() => Object.keys(sessionStorage).filter((key) => key.startsWith('prompt-assistant:draft:')).length), 0)

    const mobile = await browser.newPage()
    mobile.on('pageerror', (error) => errors.push(error.message))
    const mobileState = await setup(mobile, { width: 390, height: 844 })
    await assertLayout(mobile)
    await mobile.screenshot({ path: path.join(outputDir, 'mobile-empty.png'), fullPage: true, animations: 'disabled' })
    await mobile.getByRole('textbox', { name: '创作需求', exact: true }).fill('制作一个产品展示图片')
    await mobile.locator('input[type="file"]').setInputFiles([imageFile, imageFile])
    await mobile.locator('.composer-references .reference-item').nth(1).waitFor()
    await mobile.getByRole('button', { name: '移除参考图', exact: true }).first().click()
    assert.equal(await mobile.locator('.composer-references .reference-item').count(), 1)
    await mobile.getByRole('button', { name: '发送', exact: true }).click()
    await mobile.locator('.questions .el-radio').filter({ hasText: '电商详情' }).click()
    await mobile.getByRole('button', { name: '提交回答', exact: true }).click()
    await mobile.locator('.mobile-view-switch .el-segmented__item').filter({ hasText: '提示词结果' }).click()
    await mobile.getByRole('heading', { name: '保温杯产品展示', exact: true }).waitFor()
    assert.equal(await mobile.getByRole('radio', { name: '提示词结果', exact: true }).isChecked(), true)
    await assertLayout(mobile)
    await mobile.screenshot({ path: path.join(outputDir, 'mobile-result.png'), fullPage: true, animations: 'disabled' })
    assert.equal(mobileState.sessions[0].results.length, 1)
    assert.equal(mobileState.sessions[0].results[0].content.references[0].id, 2)
    await mobile.getByRole('button', { name: '会话记录', exact: true }).click()
    await mobile.locator('.el-drawer').getByRole('button', { name: /制作一个产品展示图片/ }).waitFor()

    const tablet = await browser.newPage()
    tablet.on('pageerror', (error) => errors.push(error.message))
    const tabletState = await setup(tablet, { width: 1024, height: 768 })
    await tablet.locator('input[type="file"]').setInputFiles(imageFile)
    await tablet.locator('.composer-references .reference-item').waitFor()
    await tablet.getByRole('button', { name: '产品广告视频', exact: true }).click()
    await tablet.getByRole('button', { name: '生成设置', exact: true }).click()
    const dialog = tablet.locator('.el-dialog')
    await dialog.locator('.el-segmented__item').filter({ hasText: '动漫' }).click()
    await dialog.locator('.el-input-number input').fill('10')
    await dialog.getByRole('button', { name: '完成', exact: true }).click()
    await tablet.getByRole('button', { name: '发送', exact: true }).click()
    await tablet.locator('.questions .el-radio').filter({ hasText: '电商详情' }).click()
    await tablet.getByRole('button', { name: '提交回答', exact: true }).click()
    const tabletResultSwitch = tablet.locator('.mobile-view-switch .el-segmented__item').filter({ hasText: '提示词结果' })
    if (await tabletResultSwitch.isVisible()) await tabletResultSwitch.click()
    await tablet.locator('.shot-item').first().waitFor()
    assert.equal(tabletState.sessions[0].results[0].content.settings.visual_style, 'anime')
    assert.equal(tabletState.sessions[0].results[0].content.settings.duration, 10)
    assert.equal(tabletState.chatCalls[0].settings.duration_confirmed, true)
    assert.equal(await tablet.locator('.shot-item').count(), 2)
    await assertLayout(tablet)
    await tablet.screenshot({ path: path.join(outputDir, 'tablet-video.png'), fullPage: true, animations: 'disabled' })
    await tablet.getByRole('button', { name: '在速创中使用此镜头', exact: true }).first().click()
    await tablet.waitForURL('**/admin/quick-create?prompt_draft=*')
    await tablet.locator('[contenteditable="true"]').filter({ hasText: '镜头缓慢接近保温杯' }).waitFor()
    assert.equal((await tablet.locator('[contenteditable="true"]').innerText()).split('二维动漫画风').length - 1, 1)
    assert.equal(await tablet.locator('.qc-pending-ref').count(), 1)
    assert.equal(await tablet.locator('.qc-pending-ref img').getAttribute('src'), tabletState.references[0].url)

    const timingPage = await browser.newPage()
    timingPage.on('pageerror', (error) => errors.push(error.message))
    const timingState = await setup(timingPage, { width: 1440, height: 960 })
    timingState.videoShotDurations = [3, 2]
    await timingPage.getByRole('button', { name: '产品广告视频', exact: true }).click()
    await timingPage.locator('.duration-status').getByText('时长待确认', { exact: true }).waitFor()
    await timingPage.locator('input[type="file"]').setInputFiles([imageFile, imageFile])
    await timingPage.locator('.composer-references .reference-item').nth(1).waitFor()
    await timingPage.getByRole('button', { name: '发送', exact: true }).click()
    assert.equal(timingState.chatCalls[0].settings.duration_confirmed, false)
    await timingPage.screenshot({ path: path.join(outputDir, 'duration-confirmation.png'), fullPage: true, animations: 'disabled' })
    await timingPage.locator('.questions .el-radio').filter({ hasText: /^5秒$/ }).click()
    await timingPage.locator('.questions .el-radio').filter({ hasText: '电商详情' }).click()
    await timingPage.getByRole('button', { name: '提交回答', exact: true }).click()
    await timingPage.locator('.duration-status').getByText('5秒', { exact: true }).waitFor()
    await timingPage.getByRole('button', { name: '在速创中使用此镜头', exact: true }).first().click()
    await timingPage.locator('.qc-duration-select').getByText('3秒', { exact: true }).waitFor()
    await timingPage.getByText('当前模型不支持 3 秒，请选择可用时长或切换模型', { exact: true }).waitFor()
    const timingEditor = timingPage.locator('[contenteditable="true"]')
    let timingText = await timingEditor.innerText()
    assert(timingText.includes('视频时长：3秒'))
    assert(timingText.includes('镜头1：展示产品（0-3秒）'))
    assert(!timingText.includes('镜头2：'))
    assert.equal(await timingPage.locator('.qc-send').isDisabled(), true)
    await timingEditor.press('Enter')
    assert.equal(timingState.quickCreateCalls.length, 0)
    await timingPage.screenshot({ path: path.join(outputDir, 'quick-create-unsupported-shot.png'), fullPage: true, animations: 'disabled' })
    // Retiming must preserve edits and image references.
    await timingEditor.fill(`${timingText}\n用户补充：杯子必须完整可见。`)
    await selectQuickOption(timingPage, '.qc-duration-select', '5秒')
    timingText = await timingEditor.innerText()
    assert(timingText.includes('视频时长：5秒'))
    assert(timingText.includes('镜头1：展示产品（0-5秒）'))
    assert(timingText.includes('用户补充：杯子必须完整可见。'))
    assert.equal(await timingPage.locator('.qc-send').isDisabled(), false)
    await selectQuickOption(timingPage, '.qc-model-select', '自定义时长模型')
    await timingPage.getByText('当前模型不支持 5 秒，请选择可用时长或切换模型', { exact: true }).waitFor()
    assert.equal(await timingPage.locator('.qc-send').isDisabled(), true)
    await selectQuickOption(timingPage, '.qc-duration-select', '6秒')
    assert((await timingEditor.innerText()).includes('视频时长：6秒'))
    await selectQuickOption(timingPage, '.qc-model-select', 'MiniMax 测试模型')
    await selectQuickOption(timingPage, '.qc-duration-select', '4秒')
    assert.equal(await timingPage.locator('.qc-send').isDisabled(), false)
    await selectQuickOption(timingPage, '.qc-model-select', 'video')
    await timingPage.getByText('当前模型不支持 4 秒，请选择可用时长或切换模型', { exact: true }).waitFor()
    await timingPage.setViewportSize({ width: 390, height: 844 })
    await timingPage.locator('.app-sidebar.is-collapsed').waitFor()
    await timingPage.waitForFunction(() => document.documentElement.scrollWidth <= innerWidth + 1, null, { timeout: 3000 }).catch(async () => {
      console.error('Mobile overflow:', await timingPage.evaluate(() => [...document.querySelectorAll('body *')].map((node) => ({ tag: node.tagName, class: String(node.className), right: node.getBoundingClientRect().right, width: node.getBoundingClientRect().width })).filter((node) => node.right > innerWidth + 1 && node.width > 0).slice(0, 20)))
    })
    assert(await timingPage.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), JSON.stringify(await timingPage.evaluate(() => ({ width: innerWidth, scroll: document.documentElement.scrollWidth }))))
    await timingPage.locator('.qc-send').scrollIntoViewIfNeeded()
    await timingPage.waitForFunction(() => document.querySelector('.qc-send').getBoundingClientRect().bottom <= innerHeight + 1)
    await timingPage.screenshot({ path: path.join(outputDir, 'mobile-unsupported-shot.png'), fullPage: true, animations: 'disabled' })
    await timingPage.setViewportSize({ width: 1440, height: 960 })
    await timingPage.goto(`${base}/admin/prompts`)
    await timingPage.locator('.session-open').first().click()
    await timingPage.getByRole('button', { name: '在速创中使用完整视频', exact: true }).click()
    await timingPage.locator('.qc-duration-select').getByText('5秒', { exact: true }).waitFor()
    timingText = await timingEditor.innerText()
    assert(timingText.includes('视频时长：5秒'))
    assert(timingText.includes('画幅比例：9:16'))
    assert(timingText.includes('镜头1：展示产品（0-3秒）'))
    assert(timingText.includes('镜头2：结束定格（3-5秒）'))
    assert.equal(await timingPage.locator('.qc-pending-ref').count(), 2)
    assert.equal(await timingPage.locator('.qc-send').isDisabled(), false)
    await timingPage.screenshot({ path: path.join(outputDir, 'quick-create-full-video-timing.png'), fullPage: true, animations: 'disabled' })
    await selectQuickOption(timingPage, '.qc-duration-select', '10秒')
    timingText = await timingEditor.innerText()
    assert(timingText.includes('视频时长：10秒'))
    assert(timingText.includes('镜头1：展示产品（0-6秒）'))
    assert(timingText.includes('镜头2：结束定格（6-10秒）'))
    await Promise.all([
      timingPage.waitForResponse((response) => response.url().endsWith('/api/quick-create/messages/send')),
      timingPage.locator('.qc-send').click(),
    ])
    assert.equal(timingState.quickCreateCalls.length, 1)
    assert.equal(timingState.quickCreateCalls[0].options.duration, 10)
    assert.equal(timingState.quickCreateCalls[0].options.aspect_ratio, '9:16')
    assert.equal(timingState.quickCreateCalls[0].asset_refs.length, 2)
    assert(timingState.quickCreateCalls[0].prompt.includes('镜头2：结束定格（6-10秒）'))
    // If a user edits timing headings, leave that text intact and request a timing review.
    await timingEditor.fill(timingText.replace('视频时长：10秒', '自定义时间安排'))
    await selectQuickOption(timingPage, '.qc-duration-select', '15秒')
    await timingPage.getByText('时长已更改，请将提示词中的时间安排同步为 15 秒', { exact: true }).waitFor()
    assert((await timingEditor.innerText()).includes('自定义时间安排'))

    const selectionPage = await browser.newPage()
    selectionPage.on('pageerror', (error) => errors.push(error.message))
    const selectionState = await setup(selectionPage, { width: 1440, height: 960 })
    selectionState.selectReferenceIndex = 2
    await imageDrag(selectionPage, imageFile.buffer, 2, 'dragenter')
    await selectionPage.locator('.composer-drop-overlay').waitFor()
    await imageDrag(selectionPage, imageFile.buffer, 2, 'dragenter', '.composer textarea')
    await imageDrag(selectionPage, imageFile.buffer, 2, 'dragleave', '.composer textarea')
    assert.equal(await selectionPage.locator('.composer-drop-overlay').count(), 1)
    await selectionPage.screenshot({ path: path.join(outputDir, 'desktop-drag-upload.png'), fullPage: true, animations: 'disabled' })
    await imageDrag(selectionPage, imageFile.buffer, 2, 'drop', '.transcript')
    await selectionPage.locator('.composer-references .reference-item').nth(1).waitFor()
    assert.equal(await selectionPage.locator('.composer-drop-overlay').count(), 0)
    assert.equal(selectionState.uploadAttempts, 2)
    await selectionPage.getByRole('textbox', { name: '创作需求', exact: true }).fill('参考第二张图的杯子，背景换成纯色影棚。')
    await selectionPage.getByRole('button', { name: '发送', exact: true }).click()
    await selectionPage.locator('.questions .el-radio').filter({ hasText: '电商详情' }).click()
    await selectionPage.getByRole('button', { name: '提交回答', exact: true }).click()
    await selectionPage.getByRole('heading', { name: '保温杯产品展示', exact: true }).waitFor()
    assert.equal(await selectionPage.locator('.result-references img').count(), 1)
    assert.equal(await selectionPage.locator('.result-references img').getAttribute('src'), selectionState.references[1].url)
    const selectedBody = await selectionPage.locator('.prompt-text').first().innerText()
    assert(selectedBody.includes('@图片1：保留产品外形'))
    assert(selectedBody.includes('不沿用原图背景'))
    assert(!selectedBody.includes('@图片2'))
    await selectionPage.evaluate(() => { navigator.clipboard.writeText = async (text) => { window.promptCopiedText = text } })
    await selectionPage.getByRole('button', { name: '复制完整提示词', exact: true }).click()
    assert((await selectionPage.evaluate(() => window.promptCopiedText)).includes(selectedBody))
    await assertLayout(selectionPage)
    await selectionPage.screenshot({ path: path.join(outputDir, 'desktop-reference-selection.png'), fullPage: true, animations: 'disabled' })
    await selectionPage.getByRole('button', { name: '在速创中使用', exact: true }).click()
    await selectionPage.waitForURL('**/admin/quick-create?prompt_draft=*')
    await selectionPage.locator('.qc-pending-ref').waitFor()
    assert.equal(await selectionPage.locator('.qc-pending-ref').count(), 1)
    assert.equal(await selectionPage.locator('.qc-pending-ref img').getAttribute('src'), selectionState.references[1].url)
    assert((await selectionPage.locator('[contenteditable="true"]').innerText()).includes('不沿用原图背景'))

    const batchPage = await browser.newPage()
    batchPage.on('pageerror', (error) => errors.push(error.message))
    const batchState = await setup(batchPage, { width: 1440, height: 960 })
    batchState.uploadDelay = 500
    batchState.failUploadAttempt = 2
    await batchPage.getByRole('textbox', { name: '创作需求', exact: true }).fill('参考这些图片中的产品，制作一张电商展示图。')
    await imageDrag(batchPage, imageFile.buffer, 3, 'dragenter')
    await imageDrag(batchPage, imageFile.buffer, 3, 'dragleave')
    assert.equal(await batchPage.locator('.composer-drop-overlay').count(), 0)
    await imageDrag(batchPage, imageFile.buffer, 3, 'drop')
    await batchPage.locator('.reference-uploading').waitFor()
    assert.equal(await batchPage.getByRole('button', { name: '发送', exact: true }).isDisabled(), true)
    await batchPage.locator('.reference-uploading').waitFor({ state: 'hidden' })
    assert.equal(batchState.uploadAttempts, 3)
    assert.equal(await batchPage.locator('.composer-references .reference-item').count(), 2)
    await batchPage.getByText('图片 reference-2.png 上传失败，请重新添加', { exact: true }).waitFor()
    await batchPage.locator('.composer-input').screenshot({ path: path.join(outputDir, 'composer-multiple-images.png'), animations: 'disabled' })
    batchState.uploadDelay = 0
    await imageDrag(batchPage, imageFile.buffer, 6, 'drop')
    await batchPage.waitForFunction(() => document.querySelectorAll('.composer-references .reference-item').length === 6)
    assert.equal(batchState.uploadAttempts, 7)
    assert.equal(await batchPage.getByRole('button', { name: '上传参考图', exact: true }).isDisabled(), true)
    await imageDrag(batchPage, imageFile.buffer, 1, 'drop')
    assert.equal(batchState.uploadAttempts, 7)
    await batchPage.getByRole('button', { name: '移除参考图', exact: true }).first().click()
    await imageDrag(batchPage, imageFile.buffer, 1, 'drop')
    await batchPage.waitForFunction(() => document.querySelectorAll('.composer-references .reference-item').length === 6)
    assert.equal(batchState.uploadAttempts, 8)
    await assertLayout(batchPage)

    const textOnly = await browser.newPage()
    textOnly.on('pageerror', (error) => errors.push(error.message))
    const textState = await setup(textOnly, { width: 1280, height: 900 })
    await textOnly.getByRole('textbox', { name: '创作需求', exact: true }).fill('自由设计一张保温杯图片')
    await textOnly.getByRole('button', { name: '发送', exact: true }).click()
    await textOnly.locator('.questions .el-radio').filter({ hasText: '电商详情' }).click()
    await textOnly.getByRole('button', { name: '提交回答', exact: true }).click()
    await textOnly.getByRole('heading', { name: '保温杯产品展示', exact: true }).waitFor()
    assert.deepEqual(textState.sessions[0].results[0].content.references, [])
    assert.equal(await textOnly.locator('.result-references').count(), 0)
    for (const eventName of ['paste', 'drop']) {
      await textOnly.evaluate(({ imageBase64, eventName }) => {
        const data = new DataTransfer()
        data.items.add(new File([Uint8Array.from(atob(imageBase64), (char) => char.charCodeAt(0))], 'pasted.png', { type: 'image/png' }))
        const event = eventName === 'paste' ? new ClipboardEvent('paste', { clipboardData: data, bubbles: true, cancelable: true })
          : new DragEvent('drop', { dataTransfer: data, bubbles: true, cancelable: true })
        document.querySelector('.composer textarea').dispatchEvent(event)
      }, { imageBase64: imageFile.buffer.toString('base64'), eventName })
      await textOnly.waitForFunction((count) => document.querySelectorAll('.composer-references .reference-item').length === count,
        eventName === 'paste' ? 1 : 2)
    }
    await textOnly.getByRole('button', { name: '在速创中使用', exact: true }).click()
    await textOnly.waitForURL('**/admin/quick-create?prompt_draft=*')
    await textOnly.locator('[contenteditable="true"]').filter({ hasText: '白色保温杯' }).waitFor()
    assert.equal(await textOnly.locator('.qc-pending-ref').count(), 0)
    assert.deepEqual(errors, [])
    console.log('Prompt UI checks passed: single-shot/full-video timing and references, duration validation/model switching/retiming, outgoing payload, multi-image drag/drop, uploads, versions, retry, desktop/tablet/mobile layout.')
    console.log('Screenshots:', outputDir)
  } catch (error) {
    console.error(error)
    for (const [index, page] of browser.contexts().flatMap((context) => context.pages()).entries()) {
      if (!page.url().startsWith(base)) continue
      console.error('FAILED PAGE:', page.url(), await page.locator('body').innerText())
      await page.screenshot({ path: path.join(outputDir, `failure-${index}.png`), fullPage: true })
    }
    process.exitCode = 1
  } finally { await browser.close() }
})()
