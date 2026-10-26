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
  const browser = await chromium.launch({ channel: 'msedge', headless: true, ignoreDefaultArgs: ['--hide-scrollbars'] })
  try {
    for (const viewport of [{ width: 1440, height: 1000 }, { width: 1280, height: 620 }, { width: 390, height: 844 }]) {
      const page = await browser.newPage({ viewport })
      const errors = []
      const sent = []
      let uploads = 0
      let failNextSend = false
      let delayNextSend = false
      let releaseSend
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
          if (failNextSend) {
            failNextSend = false
            return route.fulfill({ status: 503, json: { code: 503, message: 'Fixture submission failed' } })
          }
          if (delayNextSend) { delayNextSend = false; await new Promise(resolve => { releaseSend = resolve }) }
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
      await page.waitForFunction(() => document.querySelector('.qc-textarea')?.matches(':empty') && document.querySelectorAll('.qc-pending-ref').length === 0)
      assert.equal(await page.getByRole('combobox', { name: '图片分辨率', exact: true }).locator('xpath=ancestor::div[contains(@class, "el-select__wrapper")]').locator('.el-select__selected-item:not(.el-select__input-wrapper)').innerText(), '4K', 'submission retains generation settings')
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
      await page.waitForFunction(() => document.querySelector('.qc-textarea')?.matches(':empty'))
      await page.locator('.qc-toolbar .el-radio-button').filter({ hasText: '视频' }).click()
      await page.locator('.qc-textarea').fill('女儿提油回家，妈妈在厨房炒菜。')
      await page.locator('.qc-upload-input').setInputFiles({ name: 'product.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==', 'base64') })
      await page.waitForFunction(() => document.querySelectorAll('.qc-pending-ref').length === 1)
      failNextSend = true
      await page.locator('.qc-send:not(:disabled)').waitFor()
      await Promise.all([page.waitForResponse(response => response.url().endsWith('/api/quick-create/messages/send')), page.locator('.qc-send').click()])
      assert.equal(await page.locator('.qc-textarea').innerText(), '女儿提油回家，妈妈在厨房炒菜。', 'failed submission keeps the prompt')
      assert.equal(await page.locator('.qc-pending-ref').count(), 1, 'failed submission keeps references')
      await page.locator('.qc-send:not(:disabled)').waitFor()
      await page.locator('.qc-send').click()
      await page.waitForFunction(() => document.querySelector('.qc-textarea')?.matches(':empty') && document.querySelectorAll('.qc-pending-ref').length === 0)
      assert.equal(sent[3].mode, 'video')
      await page.locator('.qc-textarea').fill('第一条已提交的剧情')
      delayNextSend = true
      await page.locator('.qc-send:not(:disabled)').waitFor()
      await page.locator('.qc-send').click()
      for (let attempt = 0; !releaseSend && attempt < 100; attempt++) await new Promise(resolve => setTimeout(resolve, 20))
      assert.ok(releaseSend, 'delayed request must reach the fixture')
      await page.locator('.qc-textarea').fill('等待期间写好的下一条剧情')
      const response = page.waitForResponse(response => response.url().endsWith('/api/quick-create/messages/send'))
      releaseSend()
      await response
      await page.waitForFunction(() => document.querySelectorAll('.qc-message').length >= 4)
      assert.equal(await page.locator('.qc-textarea').innerText(), '等待期间写好的下一条剧情', 'a new draft survives the previous response')
      await page.locator('.qc-textarea').fill('女儿回家介绍商品，妈妈在厨房回应，画面保持自然生活感。'.repeat(60))
      await page.locator('.qc-upload-input').setInputFiles(Array.from({ length: 6 }, (_, index) => ({ name: `ref-${index}.png`, mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==', 'base64') })))
      await page.waitForFunction(() => document.querySelectorAll('.qc-pending-ref').length === 6)
      await page.locator('.qc-send').scrollIntoViewIfNeeded()
      const layout = await page.locator('.qc-composer').evaluate(element => ({
        right: element.getBoundingClientRect().right, bottom: element.getBoundingClientRect().bottom,
        overflow: element.scrollWidth > element.clientWidth,
        workspaceScrollTop: document.querySelector('.app-workspace').scrollTop,
      }))
      assert.ok(layout.right <= viewport.width + 1 && layout.bottom <= viewport.height + 1 && !layout.overflow, 'composer stays within the screen')
      assert.equal(layout.workspaceScrollTop, 0, 'composer scroll must not displace the whole workspace')
      const sendBounds = await page.locator('.qc-send').boundingBox()
      assert.ok(sendBounds.y >= 0 && sendBounds.y + sendBounds.height <= viewport.height, 'send button can be reached inside the composer')
      await page.screenshot({ path: path.join(output, `${viewport.width}-long-draft.png`), fullPage: true })
      assert.deepEqual(errors, [])
      console.log(`PASS: ${viewport.width}px controls, layout, clear after image/video submission, preserve failed/new drafts`)
      await page.close()
    }
  } finally { await browser.close() }
  console.log(`Screenshots: ${output}`)
}
main().catch((error) => { console.error(error); process.exitCode = 1 })
