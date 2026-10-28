const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright')
const assert = require('node:assert/strict')
const { pathToFileURL } = require('node:url')
const path = require('node:path')
const fs = require('node:fs')
const base = process.env.ECOMMERCE_UI_URL || 'http://127.0.0.1:8086'
const clone = value => JSON.parse(JSON.stringify(value))

async function main() {
  const { createStorefrontProject, selectStorefrontGoal } = await import(pathToFileURL(path.resolve(__dirname, '../src/features/ecommerce/domain/storefront.js')))
  const output = path.resolve(__dirname, '../../tmp-inspect/ecommerce-video-summary')
  fs.mkdirSync(output, { recursive: true })
  const browser = await chromium.launch({ channel: 'msedge', headless: true })
  try {
    for (const width of [1440, 390]) {
      const page = await browser.newPage({ viewport: { width, height: width === 390 ? 844 : 1000 } })
      const errors = [], turns = [], quotes = []
      let paidSubmits = 0
      page.on('pageerror', error => errors.push(error.message))
      const state = createStorefrontProject(); selectStorefrontGoal(state, 'video')
      const photo = { id: 'product', name: '杯子参考', src: '/admin/ecommerce-box/styles/main-home-window.jpg', width: 2048, height: 2048 }
      state.name = state.product.config.name = '咖啡杯'
      state.product.uploads = [photo]; state.product.selectedUploadId = photo.id
      state.creative.video.duration = 5
      state.creative.video.story.idea = '女性在桌旁倒咖啡，端起杯子展示。'
      state.creative.video.story.enabled = true
      state.integration.videoModelId = 2
      const detail = { id: 1, name: state.name, state, revision: 1, runs: [], jobs: [], available_scene_ids: [] }
      const oldMessage = '过期的重复回复：已按10秒整理展示杯子的方案。'
      let brief = { version: 7, phase: 'questions', materials: [{ ...photo, kind: 'product', summary: '杯子的外观参考' }], roles: {}, characters: [],
        questions: [{ id: 'tone', text: '希望视频呈现什么氛围？', options: ['自然生活感', '精致广告感'] }], answers: {},
        messages: Array.from({ length: 6 }, () => ({ role: 'assistant', text: oldMessage })), proposal: '', story: null, message: '确认氛围后整理当前方案。', stale: false }
      const user = { id: 901, username: 'video-summary-fixture', role: 'user', credit_balance: 5000 }
      await page.addInitScript(user => {
        localStorage.setItem('malulu.auth.token', 'isolated-video-summary-fixture')
        localStorage.setItem('malulu.auth.user', JSON.stringify(user))
        localStorage.setItem('malulu.auth.expiresAt', '4102444800')
      }, user)
      await page.route('**/api/**', async route => {
        const url = new URL(route.request().url())
        const body = route.request().postData() ? route.request().postDataJSON() : {}
        let data = []
        if (url.pathname === '/api/auth/me') data = { user }
        if (url.pathname === '/api/model-configs/list') data = [
          { type: 'image', models: [{ id: 1, type: 'image', name: 'Fixture Image', model_id: 'gpt-image-2.5-flare', is_default: 1, options: {} }] },
          { type: 'video', models: [{ id: 2, type: 'video', name: 'Fixture Video', model_id: 'seedance-2', is_default: 1, options: {} }] },
          { type: 'text', models: [{ id: 3, type: 'text', name: 'Fixture Text', model_id: 'fixture-text', options: {} }] },
        ]
        if (url.pathname.endsWith('/projects/list')) data = [{ id: 1, name: detail.name, revision: detail.revision }]
        if (url.pathname.endsWith('/projects/detail')) data = clone(detail)
        if (url.pathname.endsWith('/projects/save')) { detail.state = body.state; detail.revision++; data = { revision: detail.revision } }
        if (url.pathname.endsWith('/brief')) data = clone(brief)
        if (url.pathname.endsWith('/brief-turn')) {
          turns.push(clone(body)); assert.equal(body.version, brief.version)
          brief = clone(brief); brief.version++
          if (body.action === 'consult') {
            assert.equal(body.input.answers.tone, '自然生活感')
            assert.equal(body.input.message, '保留杯身的原色')
            brief.answers.tone = { question: brief.questions[0].text, answer: body.input.answers.tone }
            brief.questions = []; brief.phase = 'ready'
            brief.messages.push({ role: 'user', text: body.input.message })
            brief.proposal = '5秒单镜头：女性在桌旁倒咖啡，端起原色杯子展示。'
            brief.message = '当前方案已更新，请确认。'
            brief.story = { title: '咖啡杯展示', duration: 5, hasDialogue: false, script: '0-5 秒：女性倒咖啡，端起杯子展示；保持杯身原色。', warnings: [],
              characters: [{ id: 'c1', name: '女性', description: '成年女性，自然日常穿搭', source: 'description', image: null }] }
            brief.characters = clone(brief.story.characters); brief.product_ids = [photo.id]; brief.scene = null
          } else if (body.action === 'confirm') {
            assert.equal(brief.phase, 'ready'); brief.phase = 'confirmed'
          } else assert.fail('loading this page must not analyze or regenerate a brief')
          data = clone(brief)
        }
        if (url.pathname.endsWith('/quote')) { quotes.push(clone(body)); data = { credits: 18, estimated: false, count: body.items.length, items: body.items } }
        if (url.pathname.endsWith('/generate')) { paidSubmits++; data = clone(detail) }
        await route.fulfill({ json: { code: 0, data, message: 'ok' } })
      })
      await page.goto(`${base}/admin/ecommerce-box/product/1`)
      const composer = page.getByTestId('story-composer')
      const question = page.getByRole('combobox', { name: '希望视频呈现什么氛围？', exact: true })
      await question.waitFor()
      assert.equal(await composer.locator('.brief-history').count(), 0)
      assert.equal(await composer.getByText('对话记录', { exact: true }).count(), 0)
      assert.equal(await composer.getByText(oldMessage, { exact: true }).count(), 0)
      assert.equal(turns.length, 0, 'initial load only reads the saved brief')
      await question.locator('xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " el-select ")][1]').click()
      await page.getByRole('option', { name: '自然生活感', exact: true }).click()
      await page.getByRole('textbox', { name: '补充或修改要求', exact: true }).fill('保留杯身的原色')
      await page.getByRole('button', { name: '完善视频方案', exact: true }).click()
      await page.getByTestId('video-proposal').waitFor()
      assert.equal(await page.getByTestId('video-proposal').locator('p').innerText(), brief.proposal)
      assert.equal(turns.length, 1)
      assert.equal(brief.messages.length, 7, 'removing history presentation does not erase saved context')
      assert.equal(brief.answers.tone.answer, '自然生活感')
      assert.equal(await composer.locator('.brief-history').count(), 0)
      await page.reload()
      await page.getByTestId('video-proposal').getByText(brief.proposal, { exact: true }).waitFor()
      assert.equal(turns.length, 1, 'reload does not request another model turn')
      assert.equal(await composer.getByText(oldMessage, { exact: true }).count(), 0)
      await page.getByRole('textbox', { name: '补充或修改要求', exact: true }).scrollIntoViewIfNeeded()
      await page.screenshot({ path: path.join(output, `${width}-current-proposal.png`), fullPage: true })
      await page.getByRole('button', { name: '满意，生成视频', exact: true }).click()
      await page.getByRole('dialog', { name: '确认生成', exact: true }).waitFor()
      assert.equal(turns.at(-1).action, 'confirm')
      const quote = quotes.at(-1).items[0]
      assert.equal(quote.mode, 'video')
      assert.equal(quote.request.original.brief_version, brief.version)
      assert.equal(quote.request.options.duration, 5)
      assert.deepEqual(quote.request.images, [photo.src])
      assert.equal(paidSubmits, 0, 'verification stops at the quote, without generating')
      assert.deepEqual(errors, [])
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'no horizontal overflow')
      console.log(`PASS ${width}px: no conversation history, saved context intact, current questions and feedback update the proposal, reload is free, confirmation still quotes the approved video`)
      await page.close()
    }
  } finally { await browser.close() }
}

main().catch(error => { console.error(error); process.exit(1) })
