const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright')
const assert = require('node:assert/strict')
const { pathToFileURL } = require('node:url')
const path = require('node:path')
const fs = require('node:fs')
const base = process.env.ECOMMERCE_UI_URL || 'http://127.0.0.1:8086'
const clone = value => JSON.parse(JSON.stringify(value))

async function main() {
  const { createStorefrontProject } = await import(pathToFileURL(path.resolve(__dirname, '../src/features/ecommerce/domain/storefront.js')))
  const { buildProductRequest } = await import(pathToFileURL(path.resolve(__dirname, '../src/features/ecommerce/domain/commerce.js')))
  const output = path.resolve(__dirname, '../../tmp-inspect/ecommerce-product-tools')
  fs.mkdirSync(output, { recursive: true })
  const browser = await chromium.launch({ channel: 'msedge', headless: true })
  try {
    for (const width of [1440, 390]) {
      const page = await browser.newPage({ viewport: { width, height: width === 390 ? 844 : 1000 } })
      const errors = [], quotes = []
      let paidSubmits = 0, uploadCount = 0
      page.on('pageerror', error => errors.push(error.message))
      const state = createStorefrontProject(); state.creative.copy.enabled = false
      const source = '/admin/ecommerce-box/styles/main-home-window.jpg'
      const processed = '/admin/ecommerce-box/styles/main-food-table.jpg'
      const annotated = '/storage/fixtures/product-marking.jpg'
      state.name = state.product.config.name = '陶瓷杯'
      state.product.uploads = [{ id: 'product', name: '商品原图', src: source, width: 2048, height: 2048 }]
      state.product.selectedUploadId = 'product'
      state.product.results = [{ id: 'processed', name: '商品提取', src: processed, width: 2048, height: 1365,
        createdAt: '2026-10-27T10:00:00Z', operation: 'generate', request: { ...buildProductRequest(state.product), n: 1 } }]
      state.product.activeResultId = 'processed'; state.integration.useProcessedProduct = true
      const detail = { id: 1, name: state.name, revision: 1, state, runs: [], jobs: [], available_scene_ids: [] }
      const user = { id: 901, username: 'product-tools-fixture', role: 'user', credit_balance: 5000 }
      await page.addInitScript(user => {
        localStorage.setItem('malulu.auth.token', 'isolated-product-tools-fixture')
        localStorage.setItem('malulu.auth.user', JSON.stringify(user))
        localStorage.setItem('malulu.auth.expiresAt', '4102444800')
      }, user)
      await page.route('**/api/**', async route => {
        const url = new URL(route.request().url())
        const body = !url.pathname.endsWith('/upload') && route.request().postData() ? route.request().postDataJSON() : {}
        let data = []
        if (url.pathname === '/api/auth/me') data = { user }
        if (url.pathname === '/api/model-configs/list') data = [{ type: 'image', models: [{ id: 1, type: 'image', name: 'Fixture', model_id: 'gpt-image-2.5-flare', is_default: 1, options: {} }] }]
        if (url.pathname.endsWith('/projects/list')) data = [{ id: 1, name: detail.name, revision: detail.revision }]
        if (url.pathname.endsWith('/projects/detail')) data = clone(detail)
        if (url.pathname.endsWith('/projects/save')) { detail.state = body.state; detail.revision++; data = { revision: detail.revision } }
        if (url.pathname.endsWith('/upload')) { uploadCount++; data = { id: 'marked-upload', name: 'product-marking.jpg', src: annotated, width: 2048, height: 2048 } }
        if (url.pathname.endsWith('/quote')) { quotes.push(clone(body)); data = { credits: 18, estimated: false, count: body.items.length, items: body.items } }
        if (url.pathname.endsWith('/generate')) { paidSubmits++; data = clone(detail) }
        await route.fulfill({ json: { code: 0, data, message: 'ok' } })
      })
      await page.goto(`${base}/admin/ecommerce-box/product/1`)
      const marker = page.getByRole('button', { name: '标记商品', exact: true })
      await marker.waitFor()
      assert.equal(await marker.count(), 1, 'marking has exactly one entry point')
      assert.equal(await page.getByRole('textbox', { name: '商品名称', exact: true }).count(), 1, 'product name is not duplicated')
      assert.equal(await marker.evaluate(el => !!el.closest('.product-section')), true, 'marking sits beside the source images')
      assert.equal(await page.locator('.product-processing').count(), 0, 'product processing module is removed, not collapsed')
      for (const name of ['商品资料与处理（可选）', '商品资料', '商品类别', '陈列方式', '使用处理后的商品图', '提取干净商品图']) {
        assert.equal(await page.getByText(name, { exact: true }).count(), 0, name + ' is absent')
      }
      await page.waitForFunction(() => !document.querySelector('.generate-button')?.disabled)
      const originalQuote = quotes.findLast(quote => quote.items?.[0]?.kind === 'creative')
      assert.equal(originalQuote.items[0].request.original.images[0], source, 'old processed-product selection cannot silently replace a visible upload')
      assert(!originalQuote.items[0].request.images.includes(processed))
      const advanced = page.getByRole('button', { name: '更多配置', exact: true })
      await advanced.click()
      assert.equal(await advanced.getAttribute('aria-expanded'), 'false')
      await marker.click()
      const dialog = page.getByRole('dialog', { name: '标记目标商品', exact: true })
      await dialog.waitFor()
      await page.waitForFunction(() => document.querySelector('.marker-stage img')?.naturalWidth > 0)
      await page.locator('.marker-stage').click({ position: { x: 70, y: 80 } })
      await dialog.getByRole('button', { name: '使用标记', exact: true }).click()
      await dialog.waitFor({ state: 'hidden' })
      await page.waitForFunction(() => document.querySelector('.save-state')?.textContent === '已保存')
      assert.equal(uploadCount, 1, 'marking only uploads the annotation')
      assert.equal(detail.state.product.config.marking.marks.length, 1)
      assert.equal(detail.state.integration.useProcessedProduct, false, 'new marks do not silently reuse an older extraction')
      await page.waitForFunction(() => !document.querySelector('.generate-button')?.disabled)
      const direct = quotes.findLast(quote => quote.items?.[0]?.kind === 'creative' && quote.items[0].request.images.includes(annotated))
      assert(direct, 'direct generation uses marks without a separate extraction')
      assert.equal(direct.items[0].request.original.images[0], source)
      assert.match(direct.items[0].request.prompt, /手动标记模式/)
      assert(!quotes.some(quote => quote.items?.some(item => item.kind === 'product')), 'marking does not trigger product extraction')
      await page.locator('.creation-fields').evaluate(el => { el.scrollTop = 0 })
      await page.screenshot({ path: path.join(output, `${width}-top-marker.png`), fullPage: true })
      await advanced.click()
      assert.equal(await page.locator('.product-processing').count(), 0, 'expanding settings does not restore the removed module')
      const requirements = page.getByRole('textbox', { name: '制作要求', exact: true })
      await requirements.fill('杯身为白色，容量 300ml')
      await page.waitForFunction(() => !document.querySelector('.generate-button')?.disabled)
      assert(quotes.some(quote => quote.items?.[0]?.request.original.images[0] === source && quote.items[0].request.prompt.includes('容量 300ml')), 'product facts can still be provided through the main requirements field')
      await page.waitForFunction(() => document.querySelector('.save-state')?.textContent === '已保存')
      assert.equal(detail.state.product.results[0].src, processed, 'removing the UI does not delete historical results')
      await page.locator('.inline-settings').screenshot({ path: path.join(output, `${width}-settings-without-processing.png`) })
      assert(!quotes.some(quote => quote.items?.some(item => item.kind === 'product')), 'the page never quotes extraction')
      assert.equal(paidSubmits, 0, 'none of these controls submits a paid generation')
      assert.deepEqual(errors, [])
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'no horizontal page overflow')
      console.log(`PASS ${width}px: processing module removed, top marking and requirements retained, visible originals override old extraction, history preserved; no paid submits`)
      await page.close()
    }
  } finally { await browser.close() }
}

main().catch(error => { console.error(error); process.exit(1) })
