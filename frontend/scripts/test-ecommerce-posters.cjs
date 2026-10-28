const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright')
const assert = require('node:assert/strict')
const { pathToFileURL } = require('node:url')
const path = require('node:path')
const fs = require('node:fs')
const base = process.env.ECOMMERCE_UI_URL || 'http://127.0.0.1:8086'
const clone = value => JSON.parse(JSON.stringify(value))

async function main() {
  const { createStorefrontProject, selectStorefrontGoal, selectStorefrontStyle, storefrontImageRequests } = await import(pathToFileURL(path.resolve(__dirname, '../src/features/ecommerce/domain/storefront.js')))
  const output = path.resolve(__dirname, '../../tmp-inspect/ecommerce-posters')
  fs.mkdirSync(output, { recursive: true })
  const browser = await chromium.launch({ channel: 'msedge', headless: true })
  try {
    for (const width of [1440, 390]) {
      const page = await browser.newPage({ viewport: { width, height: width === 390 ? 844 : 1000 }, acceptDownloads: true })
      const errors = [], paidRequests = []
      page.on('pageerror', error => errors.push(error.message))
      const state = createStorefrontProject()
      selectStorefrontGoal(state, 'poster')
      state.name = state.product.config.name = 'Poster regression fixture'
      const source = '/admin/ecommerce-box/styles/poster-hero.jpg'
      state.product.uploads = [{ id: 'fixture', name: 'Product', src: source, width: 768, height: 1152 }]
      state.product.selectedUploadId = 'fixture'
      Object.assign(state.creative.copy, { enabled: true, headline: 'A daily favorite', subtitle: 'Made for every day', sellingPoints: ['Natural texture'], facts: { price: '$59.90', originalPrice: '', discount: '', certification: '', sales: '', cta: 'Shop now' } })
      const jobs = []
      for (const presetId of ['poster-hero', 'poster-tech']) {
        selectStorefrontStyle(state, presetId)
        for (const layout of ['header', 'split', 'footer']) {
          const request = storefrontImageRequests(state)[0].request
          request.poster.layout = layout
          const id = jobs.length + 1, title = `${presetId}-${layout}`
          jobs.push({ id, run_id: id, project_id: 1, kind: 'creative', mode: 'image', status: 'success', title, page_key: '', model_config_id: 1, result_url: source, error_message: '', create_time: '2026-10-28 10:00:00', update_time: '2026-10-28 10:00:00',
            request: { prompt: request.prompt, images: request.images, options: { size: '2:3', resolution: '2K' }, creative_goal: 'poster', model_id: 'fixture-image', original: clone(request) } })
        }
      }
      state.creative.results = jobs.map(job => ({ id: 'job-' + job.id, name: job.title, src: source, width: 768, height: 1152, createdAt: '2026-10-28T10:00:00Z', operation: 'generate', request: clone(job.request.original) }))
      state.creative.activeResultId = state.creative.results[0].id
      const detail = { id: 1, name: state.name, state, revision: 1, runs: [], jobs, available_scene_ids: [] }
      const user = { id: 901, username: 'poster-fixture', role: 'user', credit_balance: 5000 }
      await page.addInitScript(user => {
        localStorage.setItem('malulu.auth.token', 'isolated-poster-fixture')
        localStorage.setItem('malulu.auth.user', JSON.stringify(user))
        localStorage.setItem('malulu.auth.expiresAt', '4102444800')
      }, user)
      // Serve a text-free local fixture; no image generation or external media access.
      await page.route('**/admin/ecommerce-box/styles/poster-hero.jpg', route => route.fulfill({ contentType: 'image/svg+xml', body: '<svg xmlns="http://www.w3.org/2000/svg" width="768" height="1152"><rect width="768" height="1152" fill="#e1e8e4"/><rect x="230" y="320" width="308" height="512" rx="50" fill="#56776b"/></svg>' }))
      await page.route('**/api/**', async route => {
        const url = new URL(route.request().url())
        const body = route.request().postData() ? route.request().postDataJSON() : {}
        let data = []
        if (url.pathname === '/api/auth/me') data = { user }
        if (url.pathname === '/api/model-configs/list') data = [{ type: 'image', models: [{ id: 1, type: 'image', name: 'Fixture', model_id: 'fixture-image', is_default: 1, options: {} }] }]
        if (url.pathname.endsWith('/projects/list')) data = [{ id: 1, name: detail.name, revision: detail.revision }]
        if (url.pathname.endsWith('/projects/detail')) data = clone(detail)
        if (url.pathname.endsWith('/projects/save')) { detail.state = body.state; detail.revision++; data = { revision: detail.revision } }
        if (url.pathname.endsWith('/quote')) data = { credits: 18, estimated: false, count: body.items.length, items: body.items }
        if (/\/(generate|draft-copy|upload)$/.test(url.pathname)) paidRequests.push(url.pathname)
        await route.fulfill({ json: { code: 0, data, message: 'ok' } })
      })
      const composed = async selector => {
        await page.waitForFunction(selector => {
          const image = document.querySelector(selector)
          return image?.src.startsWith('data:image/png') && image.complete && image.naturalWidth > 0
        }, selector)
      }
      await page.goto(`${base}/admin/ecommerce-box/product/1`)
      await page.locator('.history-item').first().waitFor()
      await page.waitForFunction(() => [...document.querySelectorAll('.history-thumbnail img')].length === 6 && [...document.querySelectorAll('.history-thumbnail img')].every(image => image.src.startsWith('data:image/png') && image.complete && image.naturalWidth > 0))
      for (const job of jobs) {
        await page.locator('.history-item').filter({ hasText: job.title }).locator('.history-select').click()
        await composed('.detail-preview img')
        assert.equal(await page.locator('.poster-error').count(), 0, job.title + ': thumbnail and detail compose successfully')
        const downloadEvent = page.waitForEvent('download')
        await page.locator('.detail-utilities').getByRole('button', { name: '下载', exact: true }).click()
        const downloadPath = path.join(output, `${width}-${job.title}.png`)
        await (await downloadEvent).saveAs(downloadPath)
        const pixels = await page.evaluate(async ({ src, layout }) => {
          const image = new Image(); image.src = src; await image.decode()
          const canvas = document.createElement('canvas'); canvas.width = image.naturalWidth; canvas.height = image.naturalHeight
          const context = canvas.getContext('2d'); context.drawImage(image, 0, 0)
          const box = layout === 'split' ? [0, 0, Math.floor(canvas.width * .35), canvas.height]
            : layout === 'footer' ? [0, Math.floor(canvas.height * .70), canvas.width, Math.floor(canvas.height * .30)]
              : [0, 0, canvas.width, Math.floor(canvas.height * .20)]
          const data = context.getImageData(...box).data, colors = new Set()
          for (let i = 0; i < data.length; i += 4) colors.add(`${data[i]},${data[i + 1]},${data[i + 2]}`)
          return { width: canvas.width, height: canvas.height, colors: colors.size }
        }, { src: 'data:image/png;base64,' + fs.readFileSync(downloadPath).toString('base64'), layout: job.request.original.poster.layout })
        assert(pixels.width >= 640 && pixels.height > pixels.width && pixels.colors > 10, job.title + ': exported copy area contains text, not a flat background')
        await page.locator('.detail-preview').click()
        await composed('.poster-full-preview img')
        assert.equal(await page.locator('.poster-full-preview .poster-error').count(), 0)
        await page.screenshot({ path: path.join(output, `${width}-${job.title}-preview.png`), animations: 'disabled' })
        await page.keyboard.press('Escape')
        await page.locator('.poster-full-preview').waitFor({ state: 'hidden' })
        if (width === 390) { await page.keyboard.press('Escape'); await page.locator('.artwork-details').waitFor({ state: 'hidden' }) }
      }
      await page.reload()
      await composed('.history-thumbnail img')
      assert.equal(await page.locator('.poster-error').count(), 0, 'saved results render after refresh without regeneration')
      assert.deepEqual(paidRequests, [], 'viewing and downloading results never generates or charges for an image or copy')
      assert.deepEqual(errors, [])
      console.log(`PASS ${width}px: both poster presets, three layouts, history/detail/full previews, PNG exports with text and historical reload; no paid requests`)
      await page.close()
    }
  } finally { await browser.close() }
}

main().catch(error => { console.error(error); process.exit(1) })
