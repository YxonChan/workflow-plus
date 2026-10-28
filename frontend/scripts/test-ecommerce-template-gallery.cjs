const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright')
const assert = require('node:assert/strict')
const { pathToFileURL } = require('node:url')
const path = require('node:path')
const fs = require('node:fs')
const base = process.env.ECOMMERCE_UI_URL || 'http://127.0.0.1:8086'
const clone = value => JSON.parse(JSON.stringify(value))

async function main() {
  const { createStorefrontProject } = await import(pathToFileURL(path.resolve(__dirname, '../src/features/ecommerce/domain/storefront.js')))
  const { STYLE_PRESETS, stylesForPurpose } = await import(pathToFileURL(path.resolve(__dirname, '../src/features/ecommerce/domain/style-presets.js')))
  const output = path.resolve(__dirname, '../../tmp-inspect/ecommerce-template-gallery')
  fs.mkdirSync(output, { recursive: true })
  const browser = await chromium.launch({ channel: 'msedge', headless: true })
  try {
    for (const width of [1440, 390]) {
      const page = await browser.newPage({ viewport: { width, height: width === 390 ? 844 : 1000 } })
      const errors = [], quotes = []; let paidSubmits = 0
      page.on('pageerror', error => errors.push(error.message))
      const state = createStorefrontProject(); state.creative.copy.enabled = false
      // Loading an old draft must select a new template without failing validation.
      state.creative.presetId = 'clean-studio'
      state.product.uploads = [{ id: 'fixture', name: '商品参考', src: '/admin/ecommerce-box/styles/technology.jpg', width: 1024, height: 1536 }]
      state.product.selectedUploadId = 'fixture'
      const frozen = { prompt: '历史详情冻结提示词', images: [state.product.uploads[0].src], referenceRoles: ['product'], mode: 'compose', size: '1365x2048', n: 1,
        design: { purpose: 'detail', presetId: 'clean-studio', layout: 'template' }, styleReference: '/admin/ecommerce-box/styles/clean-studio.jpg' }
      const snapshot = { creative: clone(state.creative), product: clone(state.product), model: clone(state.model), scene: clone(state.scene), integration: { goal: 'detail' } }
      const result = { id: 'job-1', name: '历史详情成品', src: frozen.styleReference, width: 1365, height: 2048, createdAt: '2026-10-27T10:00:00Z', operation: 'generate', request: clone(frozen) }
      state.creative.results = [result]; state.creative.activeResultId = result.id
      const detail = { id: 1, name: '模板测试', state, revision: 1, runs: [], available_scene_ids: [], jobs: [{ id: 1, run_id: 1, project_id: 1, kind: 'creative', mode: 'image', status: 'success',
        title: result.name, page_key: '', model_config_id: 1, result_url: result.src, error_message: '', create_time: '2026-10-27 10:00:00', update_time: '2026-10-27 10:00:00',
        request: { prompt: frozen.prompt, images: [...frozen.images, frozen.styleReference], options: { size: '2:3', resolution: '2K' }, creative_goal: 'detail', model_id: 'gpt-image-2.5-flare', original: { ...clone(frozen), ui_snapshot: snapshot } } }] }
      const user = { id: 901, username: 'template-fixture', role: 'user', credit_balance: 5000 }
      await page.addInitScript(user => {
        localStorage.setItem('malulu.auth.token', 'isolated-template-fixture')
        localStorage.setItem('malulu.auth.user', JSON.stringify(user))
        localStorage.setItem('malulu.auth.expiresAt', '4102444800')
      }, user)
      await page.route('**/api/**', async route => {
        const url = new URL(route.request().url())
        const body = route.request().postData() ? route.request().postDataJSON() : {}
        let data = []
        if (url.pathname === '/api/auth/me') data = { user }
        if (url.pathname === '/api/model-configs/list') data = [{ type: 'image', models: [{ id: 1, type: 'image', name: 'Fixture', model_id: 'gpt-image-2.5-flare', is_default: 1, options: {} }] }]
        if (url.pathname.endsWith('/projects/list')) data = [{ id: 1, name: detail.name, revision: detail.revision }]
        if (url.pathname.endsWith('/projects/detail')) data = clone(detail)
        if (url.pathname.endsWith('/projects/save')) { detail.state = body.state; detail.revision++; data = { revision: detail.revision } }
        if (url.pathname.endsWith('/quote')) { quotes.push(clone(body)); data = { credits: 18, estimated: false, count: body.items.length, items: body.items } }
        if (url.pathname.endsWith('/generate')) { paidSubmits++; data = clone(detail) }
        await route.fulfill({ json: { code: 0, data, message: 'ok' } })
      })
      await page.goto(`${base}/admin/ecommerce-box/product/1`)
      await page.locator('.selected-style').waitFor()
      await page.waitForFunction(() => !document.querySelector('.selected-style img')?.getAttribute('src')?.includes('undefined'))
      assert((await page.locator('.selected-style img').getAttribute('src')).endsWith('/detail-brand-minimal.jpg'), 'old detail draft migrates to the new default')
      for (const purpose of ['detail', 'main']) {
        if (purpose === 'main') {
          const input = page.getByRole('combobox', { name: '图片用途', exact: true })
          await input.locator('xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " el-select ")][1]').click()
          await page.getByRole('option', { name: '商品主图', exact: true }).click()
        }
        await page.locator('.selected-style').click()
        const gallery = page.locator('.style-gallery')
        await gallery.waitFor()
        const compatible = stylesForPurpose(purpose)
        assert.equal(compatible.length, purpose === 'detail' ? 12 : 8)
        assert.equal(await gallery.locator('button').count(), compatible.length)
        const imported = compatible.filter(item => item.templatePack === 'commerce-v1')
        assert.equal(imported.length, purpose === 'detail' ? 12 : 8, 'all supplied templates are active')
        for (const pending of STYLE_PRESETS.filter(item => item.enabled === false)) {
          assert.equal(await gallery.getByRole('button', { name: pending.name, exact: false }).count(), 0)
        }
        await page.waitForFunction(() => [...document.querySelectorAll('.style-gallery img')].every(image => image.complete && image.naturalWidth > 0))
        const category = compatible.find(item => item.category === '美妆生活')?.category
        if (category) {
          await page.locator('.style-filters .el-radio-button').filter({ hasText: category }).click()
          assert.equal(await gallery.locator('button').count(), compatible.filter(item => item.category === category).length)
          await page.locator('.style-filters .el-radio-button').filter({ hasText: '全部' }).click()
        }
        await page.screenshot({ path: path.join(output, `${width}-${purpose}.png`), fullPage: true })
        const selected = imported.find(item => item.id === (purpose === 'detail' ? 'detail-beauty-soft' : 'main-beauty-podium'))
        const importedCard = gallery.locator('button').filter({ hasText: selected.name })
        await importedCard.scrollIntoViewIfNeeded()
        await page.screenshot({ path: path.join(output, `${width}-${purpose}-imported.png`), fullPage: true })
        await importedCard.click()
        await page.waitForFunction(name => document.querySelector('.selected-style strong')?.textContent === name, selected.name)
        await page.waitForTimeout(1000)
        const quote = quotes.findLast(quote => quote.items?.[0]?.request.original.design.presetId === selected.id)
        assert(quote, 'new template selection is reflected in the quote')
        assert.equal(quote.items[0].request.original.styleReference, selected.cover)
        assert(quote.items[0].request.images.some(src => src.endsWith(selected.cover)), 'the actual model input contains the imported reference')
        assert(quote.items[0].request.prompt.includes(selected.prompt))
        assert(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'no horizontal overflow')
      }
      await page.locator('.history-select').click()
      await page.getByRole('button', { name: '查看完整提示词', exact: true }).click()
      assert.equal(await page.locator('.full-prompt').innerText(), frozen.prompt, 'retired template result retains its frozen prompt')
      await page.getByRole('button', { name: '复用配置', exact: true }).click()
      await page.getByText('已复用配置，请确认后生成', { exact: true }).waitFor()
      assert((await page.locator('.selected-style img').getAttribute('src')).endsWith('/detail-brand-minimal.jpg'), 'reusing an old configuration selects the new default')
      await page.getByRole('button', { name: '改图', exact: true }).click()
      // The existing mobile history sheet stacks above dialogs; close it before editing.
      if (width === 390) await page.getByRole('dialog', { name: '作品详情', exact: true }).getByRole('button', { name: '关闭作品详情', exact: true }).click()
      await page.getByPlaceholder('描述需要修改的内容', { exact: true }).fill('光线柔和一些')
      await page.getByRole('button', { name: '查看修改费用', exact: true }).click()
      await page.getByRole('dialog', { name: '确认生成', exact: true }).waitFor()
      const editQuote = quotes.findLast(quote => quote.items?.[0]?.request.original.mode === 'edit')
      assert(editQuote, 'historical edit can still be quoted without generating')
      const edit = editQuote.items[0].request.original
      assert.deepEqual(edit.design, frozen.design)
      assert.equal(edit.styleReference, frozen.styleReference)
      assert.equal(edit.size, frozen.size)
      assert.deepEqual(edit.images, [result.src, ...frozen.images])
      assert.equal(paidSubmits, 0, 'gallery selection does not submit generation or spend credits')
      assert.deepEqual(errors, [])
      console.log(`PASS ${width}px: only 8 new main + 12 new detail templates, retired draft migration, previews, filtering, quoted reference, historical configuration reuse and frozen edit`)
      await page.close()
    }
  } finally { await browser.close() }
}

main().catch(error => { console.error(error); process.exit(1) })
