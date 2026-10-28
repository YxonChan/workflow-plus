const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright')
const assert = require('node:assert/strict')
const { pathToFileURL } = require('node:url')
const path = require('node:path')
const fs = require('node:fs')
const base = process.env.ECOMMERCE_UI_URL || 'http://127.0.0.1:8086'
const output = path.resolve(__dirname, '../../tmp-inspect/ecommerce-redesign')
fs.mkdirSync(output, { recursive: true })
const clone = value => JSON.parse(JSON.stringify(value))

async function main() {
  const { createStorefrontProject } = await import(pathToFileURL(path.resolve(__dirname, '../src/features/ecommerce/domain/storefront.js')))
  const browser = await chromium.launch({ channel: 'msedge', headless: true })
  try {
    for (const width of [1854, 1440, 1180, 390]) {
      const page = await browser.newPage({ viewport: { width, height: width === 390 ? 844 : 1000 } })
      const errors = []; page.on('pageerror', error => errors.push(error.message))
      const state = createStorefrontProject()
      state.name = '陶瓷咖啡杯'; state.product.config.name = state.name; state.creative.copy.enabled = false
      state.creative.notes = '保持杯子原色，使用柔和自然光。'
      const photo = '/admin/ecommerce-box/scenes/kitchen.jpg'
      state.product.uploads = [{ id: 'cup', name: '商品原图', src: photo, width: 1672, height: 941 }]
      state.product.selectedUploadId = 'cup'
      const original = { prompt: 'fixture prompt', images: [photo], referenceRoles: ['product'], mode: 'compose', size: '1024x1024', n: 1,
        design: { purpose: 'main', presetId: 'home' }, ui_snapshot: { userPrompt: '白色陶瓷咖啡杯，自然光，保留纹理。', creative: { ...clone(state.creative), notes: '历史创作要求', results: [] }, product: clone(state.product), model: clone(state.model), scene: clone(state.scene), integration: { goal: 'detail' } } }
      const job = (id, date, title, mode = 'image', status = 'success') => ({ id, run_id: id, project_id: 1, kind: 'creative', mode, status, title, page_key: '', model_config_id: 1,
        result_url: status === 'success' ? photo : '', error_message: status === 'failed' ? 'Fixture provider failure' : '', create_time: date, update_time: date,
        request: { prompt: `实际冻结提示词 ${id}：保留商品纹理。`, images: [photo], options: { size: '1:1', resolution: '2K' }, creative_goal: mode === 'video' ? 'video' : id === 2 ? 'detail' : 'main', model_id: mode === 'video' ? 'seedance-2' : 'gpt-image-2.5-flare', original: clone(original) } })
      let detail = { id: 1, name: state.name, revision: 1, state, jobs: [job(1, '2026-10-25 10:00:00', '陶瓷咖啡杯 · 主图'), job(2, '2026-10-26 15:20:00', '陶瓷咖啡杯 · 详情图'), job(3, '2026-10-26 17:30:00', '陶瓷咖啡杯 · 场景视频', 'video', 'failed')], runs: [], available_scene_ids: ['kitchen'] }
      let paidSubmits = 0; let detailReads = 0; let createCount = 0
      const products = [{ id: 1, name: '陶瓷咖啡杯', product_name: '陶瓷咖啡杯', cover_url: photo, revision: 1, update_time: '2026-10-26 17:30:00' }, { id: 2, name: '运动鞋', cover_url: '/admin/ecommerce-box/scenes/street.jpg', revision: 1, update_time: '2026-10-25 12:00:00' }]
      const user = { id: 901, username: 'design-fixture', role: 'user', credit_balance: 5000 }
      await page.addInitScript(user => { localStorage.setItem('malulu.auth.token', 'isolated-fixture'); localStorage.setItem('malulu.auth.user', JSON.stringify(user)); localStorage.setItem('malulu.auth.expiresAt', '4102444800'); localStorage.setItem('ecommerce:last:901', '1') }, user)
      await page.route('**/api/**', async route => {
        const url = new URL(route.request().url())
        const body = route.request().postData() ? route.request().postDataJSON() : {}
        let data = []
        if (url.pathname === '/api/auth/me') data = { user }
        if (url.pathname === '/api/model-configs/list') data = [{ type: 'image', models: [{ id: 1, name: 'GPT Image 2.5', model_id: 'gpt-image-2.5-flare', type: 'image', is_default: 1, options: {} }] }]
        if (url.pathname.endsWith('/projects/list')) data = products
        if (url.pathname.endsWith('/projects/detail')) { detailReads++; data = clone(detail) }
        if (url.pathname.endsWith('/projects/create')) { createCount++; data = { ...clone(detail), id: 3, state: body.state, jobs: [] } }
        if (url.pathname.endsWith('/projects/save')) { detail.state = body.state; detail.revision++; data = { revision: detail.revision } }
        if (url.pathname.endsWith('/quote')) data = { credits: 18, estimated: false, count: body.items.length, items: body.items }
        if (url.pathname.endsWith('/generate')) { paidSubmits++; data = clone(detail) }
        await route.fulfill({ json: { code: 0, data, message: 'ok' } })
      })
      await page.goto(`${base}/admin/ecommerce-box`)
      await page.waitForURL(/ecommerce-box$/)
      await page.locator('.product-card').first().waitFor()
      assert.equal(await page.locator('.product-card').count(), 2)
      assert.equal(detailReads, 0, 'home does not auto-open the last product')
      assert.equal(createCount, 0, 'home does not silently create a product')
      await page.screenshot({ path: path.join(output, `${width}-home.png`), fullPage: true })
      await page.locator('.product-card').first().click()
      await page.getByRole('heading', { name: '生成记录', exact: true }).waitFor()
      await page.waitForFunction(() => document.querySelectorAll('.history-item').length === 3).catch(async reason => { console.error(errors, await page.locator('body').innerText()); throw reason })
      assert.match(page.url(), /ecommerce-box\/product\/1$/)
      console.log(await page.locator('.ecommerce-box').evaluate(el => { const c = el.__vueParentComponent; return { loading: c?.setupState.loading, workLoading: c?.setupState.work?.loading, advanced: c?.setupState.advancedOpen } }))
      assert.equal(await page.locator('.history-item').count(), 3, 'all batches visible')
      assert.match(await page.locator('.history-item').first().innerText(), /场景视频/)
      await page.locator('.history-tabs button').nth(1).evaluate(el => el.click())
      await page.waitForFunction(() => document.querySelectorAll('.history-item').length === 2)
      assert.equal(await page.locator('.history-item').count(), 2)
      await page.locator('.sort-button').evaluate(el => el.click())
      assert.match(await page.locator('.history-item').first().innerText(), /主图/)
      await page.locator('.sort-button').evaluate(el => el.click())
      await page.locator('.history-select').first().evaluate(el => el.click())
      await page.getByRole('heading', { name: '作品详情', exact: true }).waitFor()
      await page.getByRole('button', { name: '查看完整提示词', exact: true }).evaluate(el => el.click())
      assert.match(await page.locator('.full-prompt').innerText(), /实际冻结提示词 2/)
      assert.equal(await page.locator('.detail-references img').count(), 1)
      assert.match(await page.locator('.parameter-table').innerText(), /gpt-image-2.5-flare/)
      await page.getByRole('button', { name: '复用配置', exact: true }).evaluate(el => el.click())
      await page.getByText('已复用配置，请确认后生成', { exact: true }).waitFor()
      assert.equal(await page.locator('.el-message--error').count(), 0, 'fixture history has valid reference roles for reuse')
      assert.equal(paidSubmits, 0, 'reuse never submits or bills generation')
      if (width > 1200) await page.screenshot({ path: path.join(output, `${width}-workbench.png`), fullPage: true })
      else { await page.screenshot({ path: path.join(output, `${width}-details.png`), fullPage: true }); await page.getByRole('button', { name: '关闭作品详情', exact: true }).click(); await page.screenshot({ path: path.join(output, `${width}-workbench.png`), fullPage: true }) }
      assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'no horizontal page overflow')
      await page.getByRole('button', { name: '我的商品', exact: true }).evaluate(el => el.click())
      await page.getByRole('heading', { name: '我的商品', exact: true }).waitFor()
      assert.equal(paidSubmits, 0)
      assert.deepEqual(errors, [])
      console.log(`PASS ${width}px: product landing, explicit navigation, complete timeline, filters, details, frozen prompts, refs, free configuration reuse, return home`)
      await page.close()
    }
  } finally { await browser.close() }
  console.log(`Screenshots: ${output}`)
}
main().catch(error => { console.error(error); process.exit(1) })
