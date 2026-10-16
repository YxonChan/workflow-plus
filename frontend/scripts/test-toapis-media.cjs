const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright')
const assert = require('node:assert/strict')
const path = require('node:path')
const fs = require('node:fs')
const os = require('node:os')
const base = 'http://127.0.0.1:5177'
const output = path.join(os.tmpdir(), 'workflow-plus-toapis-check')
fs.mkdirSync(output, { recursive: true })

async function select(page, locator, label) {
  await locator.locator("xpath=ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' el-select ')][1]").click()
  await page.locator('.el-select-dropdown:visible').getByRole('option', { name: label, exact: true }).click()
}
async function main() {
  const browser = await chromium.launch({ channel: 'msedge', headless: true })
  try {
    for (const viewport of [{ width: 1440, height: 1000 }, { width: 390, height: 844 }]) {
      const page = await browser.newPage({ viewport })
      const errors = []
      const sent = []
      let uploads = 0
      page.on('pageerror', (error) => errors.push(error.message))
      const user = { id: 901, username: 'media-fixture', role: 'user', preferred_locale: 'zh-CN', credit_balance: 5000 }
      await page.addInitScript((user) => {
        localStorage.setItem('malulu.auth.token', 'isolated-media-test')
        localStorage.setItem('malulu.auth.user', JSON.stringify(user))
        localStorage.setItem('malulu.auth.expiresAt', '4102444800')
      }, user)
      const models = ['gpt-image-2.5-flare', 'gpt-image-2.5-sunburst', 'gpt-image-2.5-flare-official', 'gpt-image-2.5-sunburst-official'].map((id, index) => ({
        id: index + 1, type: 'image', model_id: id, name: id, endpoint: '', options: { provider: 'toapis' }, enabled: 1, is_default: index === 0 ? 1 : 0,
      }))
      await page.route('**/api/**', async (route) => {
        const url = new URL(route.request().url())
        if (!url.pathname.startsWith('/api/')) return route.continue()
        const body = route.request().postData() && !url.pathname.includes('upload') ? route.request().postDataJSON() : {}
        let data = []
        if (url.pathname === '/api/auth/me') data = { user }
        if (url.pathname === '/api/model-configs/list') data = [{ type: 'image', title: 'image', models }, { type: 'video', title: 'video', models: [{ id: 5, type: 'video', name: 'Seedance', model_id: 'seedance-2', endpoint: '', options: {} }] }]
        if (url.pathname === '/api/quick-create/messages/list') data = { messages: [], has_more: false }
        if (url.pathname === '/api/quick-create/messages/status') data = { messages: [] }
        if (url.pathname === '/api/quick-create/quote') {
          const official = body.model_config_id >= 3
          data = { credits: (official ? 86.4 : ({ '1K': 13.5, '2K': 18, '4K': 22.5 }[body.options.resolution] || 13.5)) * body.options.count, estimated: official, count: body.options.count }
        }
        if (url.pathname.includes('upload')) data = { url: `${base}/admin/logo.png?ref=${++uploads}`, media_type: 'image' }
        if (url.pathname === '/api/quick-create/messages/send') {
          sent.push(body)
          data = { message: { ...body, id: sent.length, status: 'success', asset_refs: body.asset_refs, options: body.options, result_urls: [] } }
        }
        await route.fulfill({ json: { code: 0, message: 'success', data } })
      })
      await page.goto(`${base}/admin/quick-create`)
      await page.locator('.qc-toolbar').waitFor({ timeout: 10000 }).catch(async (error) => {
        await page.screenshot({ path: path.join(output, 'failure.png'), fullPage: true })
        throw new Error(`${error.message}; URL=${page.url()}; errors=${JSON.stringify(errors)}; body=${(await page.locator('body').innerText()).slice(0, 800)}`)
      })
      await page.locator('.qc-toolbar .el-radio-button').filter({ hasText: '图片' }).click()
      await page.locator('.qc-textarea').fill('保留产品外观，生成产品海报')
      await select(page, page.getByRole('combobox', { name: '图片分辨率', exact: true }), '4K')
      assert.equal(await page.getByRole('combobox', { name: '图片画质', exact: true }).count(), 0)
      await page.waitForFunction(() => document.querySelector('.qc-credit-quote')?.textContent.includes('22.5'))
      await page.locator('.qc-transparency').click()
      await page.locator('.qc-upload-input').setInputFiles([
        { name: 'a.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==', 'base64') },
        { name: 'b.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==', 'base64') },
      ])
      await page.waitForFunction(() => document.querySelectorAll('.qc-pending-ref').length === 2)
      await select(page, page.locator('.qc-toolbar .el-select').last(), '2条')
      await page.waitForFunction(() => document.querySelector('.qc-credit-quote')?.textContent.includes('45'))
      assert.equal(await page.getByText('检测人脸', { exact: true }).count(), 0)
      await page.screenshot({ path: path.join(output, `${viewport.width}-ordinary.png`), fullPage: true })
      await Promise.all([page.waitForResponse((response) => response.url().endsWith('/api/quick-create/messages/send')), page.locator('.qc-send').click()])
      assert.equal(sent[0].options.resolution, '4K')
      assert.equal(sent[0].options.quality, 'high')
      assert.equal(sent[0].options.background, 'transparent')
      assert.equal(sent[0].asset_refs.length, 2)
      assert.equal(sent[0].options.count, 2)
      await select(page, page.locator('.qc-model-select'), 'gpt-image-2.5-flare-official')
      assert.equal(await page.getByRole('combobox', { name: '图片分辨率', exact: true }).count(), 0)
      await select(page, page.getByRole('combobox', { name: '图片尺寸', exact: true }), '1536x1024')
      await select(page, page.getByRole('combobox', { name: '图片画质', exact: true }), '最高画质')
      await page.locator('.qc-textarea').fill('生成高清产品主图')
      await page.waitForFunction(() => document.querySelector('.qc-credit-quote')?.textContent.includes('按实际用量结算'))
      await page.screenshot({ path: path.join(output, `${viewport.width}-official.png`), fullPage: true })
      const bounds = await page.locator('.qc-toolbar').evaluate((element) => ({ width: element.clientWidth, scroll: element.scrollWidth }))
      assert(bounds.scroll <= bounds.width + 1, 'toolbar overflows')
      await page.waitForTimeout(2100)
      await Promise.all([page.waitForResponse((response) => response.url().endsWith('/api/quick-create/messages/send')), page.locator('.qc-send').click()])
      assert.equal(sent[1].options.size, '1536x1024')
      assert.equal(sent[1].options.quality, 'max')
      assert(!('resolution' in sent[1].options) && !('aspect_ratio' in sent[1].options))
      assert.deepEqual(errors, [])
      console.log(`PASS: ${viewport.width}px ordinary/official controls, references, quote and submit`)
      await page.close()
    }
  } finally { await browser.close() }
  console.log(`Screenshots: ${output}`)
}
main().catch((error) => { console.error(error); process.exitCode = 1 })
