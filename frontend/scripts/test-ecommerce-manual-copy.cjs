const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright')
const assert = require('node:assert/strict')
const { pathToFileURL } = require('node:url')
const path = require('node:path')
const fs = require('node:fs')
const base = process.env.ECOMMERCE_UI_URL || 'http://127.0.0.1:8086'
const clone = value => JSON.parse(JSON.stringify(value))

async function main() {
  const { createStorefrontProject } = await import(pathToFileURL(path.resolve(__dirname, '../src/features/ecommerce/domain/storefront.js')))
  const output = path.resolve(__dirname, '../../tmp-inspect/ecommerce-manual-copy')
  fs.mkdirSync(output, { recursive: true })
  const browser = await chromium.launch({ channel: 'msedge', headless: true })
  try {
    for (const width of [1440, 390]) {
      const page = await browser.newPage({ viewport: { width, height: width === 390 ? 844 : 1000 } })
      const errors = [], quotes = [], submits = [], drafts = []
      let uploads = 0
      page.on('pageerror', error => errors.push(error.message))
      const state = createStorefrontProject()
      state.name = state.product.config.name = '陶瓷咖啡杯'
      const source = '/admin/ecommerce-box/styles/main-home-window.jpg'
      const detail = { id: 1, name: state.name, revision: 1, state, runs: [], jobs: [], available_scene_ids: [] }
      const user = { id: 901, username: 'manual-copy-fixture', role: 'user', credit_balance: 5000 }
      await page.addInitScript(user => {
        localStorage.setItem('malulu.auth.token', 'isolated-manual-copy-fixture')
        localStorage.setItem('malulu.auth.user', JSON.stringify(user))
        localStorage.setItem('malulu.auth.expiresAt', '4102444800')
      }, user)
      await page.route('**/api/**', async route => {
        const url = new URL(route.request().url())
        const body = !url.pathname.endsWith('/upload') && route.request().postData() ? route.request().postDataJSON() : {}
        let data = []
        if (url.pathname === '/api/auth/me') data = { user }
        if (url.pathname === '/api/model-configs/list') data = [
          { type: 'image', models: [{ id: 1, type: 'image', name: 'Fixture Image', model_id: 'gpt-image-2.5-flare', is_default: 1, options: {} }] },
          { type: 'text', models: [{ id: 2, type: 'text', name: 'Fixture Text', model_id: 'fixture-text', is_default: 1, options: {} }] },
        ]
        if (url.pathname.endsWith('/projects/list')) data = [{ id: 1, name: detail.name, revision: detail.revision }]
        if (url.pathname.endsWith('/projects/detail')) data = clone(detail)
        if (url.pathname.endsWith('/projects/save')) { detail.state = body.state; detail.revision++; data = { revision: detail.revision } }
        if (url.pathname.endsWith('/upload')) { uploads++; data = { id: 'upload-' + uploads, name: 'cup.jpg', src: source, width: 2048, height: 2048 } }
        if (url.pathname.endsWith('/quote')) { quotes.push(clone(body)); data = { credits: 18, estimated: false, count: body.items.length, items: body.items } }
        if (url.pathname.endsWith('/generate')) { submits.push(clone(body)); data = clone(detail) }
        if (url.pathname.endsWith('/draft-copy')) {
          drafts.push(clone(body))
          data = { headline: '日常好物', subtitle: '自选文案', selling_points: ['简洁外观'] }
        }
        await route.fulfill({ json: { code: 0, data, message: 'ok' } })
      })
      const waitQuote = async predicate => {
        for (let i = 0; i < 80; i++) {
          const quote = quotes.at(-1)
          if (quote && predicate(quote.items[0].request)) return quote.items[0].request
          await page.waitForTimeout(100)
        }
        throw new Error('Expected quotation not observed')
      }
      const saved = () => page.waitForFunction(() => document.querySelector('.save-state')?.textContent === '已保存')
      const introText = '材质：陶瓷\n容量：300ml\n价格：¥59.90\n简介：你我日常的一杯温暖'
      const intro = () => page.getByRole('textbox', { name: '商品简介', exact: true })
      const copySwitch = () => page.locator('.el-switch').filter({ has: page.getByRole('switch', { name: '加入宣传文案', exact: true }) })
      await page.goto(`${base}/admin/ecommerce-box/product/1`)
      await intro().waitFor()
      await page.waitForFunction(() => document.querySelector('.product-name-input input')?.value === '陶瓷咖啡杯')
      assert.equal(await intro().count(), 1)
      assert.equal(await intro().getAttribute('maxlength'), '4000')
      assert.equal(await page.locator('.product-processing').count(), 0)
      await page.getByTestId('product-upload').setInputFiles(path.resolve(__dirname, '../public/ecommerce-box/styles/main-home-window.jpg'))
      await waitQuote(request => request.original.design?.purpose === 'detail')
      assert.equal(uploads, 1)
      assert.equal(drafts.length, 0, 'upload does not invoke AI drafting, even with a text model and empty copy')
      await intro().fill(introText)
      const confirmed = await waitQuote(request => request.prompt.includes(JSON.stringify('价格：¥59.90')))
      assert(!confirmed.prompt.includes('不添加新的营销文字'))
      assert.equal(confirmed.original.ui_snapshot.product.config.notes, introText)
      assert.equal(confirmed.original.poster, undefined)
      assert.equal(await page.getByRole('textbox', { name: '宣传标题', exact: true }).inputValue(), '', 'a generated headline is not required')
      for (const [index, point] of ['握持舒适', '日常相伴', '清爽外观'].entries()) {
        await page.getByRole('button', { name: '添加卖点', exact: true }).click()
        await page.getByRole('textbox', { name: `卖点 ${index + 1}`, exact: true }).fill(point)
      }
      assert.equal(await page.getByRole('button', { name: '添加卖点', exact: true }).count(), 0, 'three-point limit is visible')
      await page.getByRole('button', { name: '删除卖点 2', exact: true }).click()
      assert.equal(await page.getByRole('textbox', { name: '卖点 2', exact: true }).inputValue(), '清爽外观')
      await waitQuote(request => request.prompt.includes('清爽外观') && !request.prompt.includes('日常相伴'))
      await saved()
      assert.deepEqual(detail.state.creative.copy.sellingPoints, ['握持舒适', '清爽外观'])
      assert.equal(detail.state.product.config.notes, introText)
      await page.locator('.product-intro-section').scrollIntoViewIfNeeded()
      await page.screenshot({ path: path.join(output, `${width}-manual-description.png`), fullPage: true })
      await copySwitch().click()
      await waitQuote(request => request.prompt.includes('商品简介仅作为商品参考，不排入图中文字'))
      assert.equal(await intro().inputValue(), introText, 'disabling text preserves the confirmed description')
      await copySwitch().click()
      await waitQuote(request => request.prompt.includes(JSON.stringify('价格：¥59.90')))
      await saved()
      await page.reload()
      await intro().waitFor()
      await page.waitForFunction(value => document.querySelector('textarea[aria-label="商品简介"]')?.value === value, introText)
      assert.equal(await intro().inputValue(), introText)
      assert.equal(await page.getByRole('textbox', { name: '卖点 1', exact: true }).inputValue(), '握持舒适')
      assert.equal(drafts.length, 0, 'saving, changing text settings and refreshing do not draft or overwrite copy')
      await waitQuote(request => request.prompt.includes(JSON.stringify('价格：¥59.90')))
      await page.waitForFunction(() => !document.querySelector('.generate-button')?.disabled)
      await page.locator('.generate-button').click()
      for (let i = 0; i < 50 && !submits.length; i++) await page.waitForTimeout(100)
      assert.equal(submits.length, 1, 'manual-only copy submits the image plan without a text-model step')
      assert.equal(drafts.length, 0)
      assert(submits[0].items[0].request.prompt.includes(JSON.stringify('材质：陶瓷')))
      assert(submits[0].items[0].request.prompt.includes(JSON.stringify('价格：¥59.90')))
      assert.equal(submits[0].items[0].request.original.ui_snapshot.productConfig.notes, introText)
      const selectPurpose = async name => {
        const input = page.getByRole('combobox', { name: '图片用途', exact: true })
        await input.locator('xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " el-select ")][1]').click()
        await page.getByRole('option', { name, exact: true }).click()
      }
      await selectPurpose('商品主图')
      await page.getByRole('textbox', { name: '宣传副标题', exact: true }).fill('每天的一杯温暖')
      await page.getByRole('button', { name: '删除卖点 2', exact: true }).click()
      await page.getByRole('button', { name: '删除卖点 1', exact: true }).click()
      const main = await waitQuote(request => request.original.design.purpose === 'main' && request.original.poster?.subtitle === '每天的一杯温暖' && !request.original.poster.sellingPoints.length)
      assert.equal(main.original.poster.headline, '', 'main-image copy works without a generated headline')
      assert.equal(await intro().inputValue(), introText)
      await selectPurpose('宣传海报')
      await page.getByRole('textbox', { name: '宣传副标题', exact: true }).fill('')
      await page.getByPlaceholder('到手价，如 ¥129', { exact: true }).fill('¥59.90')
      const poster = await waitQuote(request => request.original.design.purpose === 'poster' && request.original.poster?.facts.price === '¥59.90')
      assert.equal(poster.original.poster.headline, '', 'confirmed poster price does not need an AI headline')
      assert.equal(drafts.length, 0)
      await page.getByRole('button', { name: 'AI 拟写', exact: true }).click()
      for (let i = 0; i < 50 && !drafts.length; i++) await page.waitForTimeout(100)
      assert.equal(drafts.length, 1, 'only the explicit AI drafting button calls the text service (mocked)')
      assert.deepEqual(errors, [])
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'no horizontal overflow')
      console.log(`PASS ${width}px: manual description and price reach detail text, points editable, persistence and switch work, subtitle-only main and price-only poster; no automatic drafting, all model calls mocked`)
      await page.close()
    }
  } finally { await browser.close() }
}

main().catch(error => { console.error(error); process.exit(1) })
