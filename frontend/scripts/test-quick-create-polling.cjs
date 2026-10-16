const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright')
const assert = require('node:assert/strict')
const path = require('node:path')
const os = require('node:os')
const fs = require('node:fs')

async function main() {
  const browser = await chromium.launch({ channel: 'msedge', headless: true })
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1000 } })
    const base = process.env.QUICK_CREATE_TEST_BASE || 'http://127.0.0.1:5177'
    const errors = []
    page.on('pageerror', (error) => errors.push(error.message))
    const user = { id: 901, username: 'poll-fixture', role: 'user', credit_balance: 5000 }
    await page.addInitScript((user) => {
      localStorage.setItem('malulu.auth.token', 'isolated-poll-test')
      localStorage.setItem('malulu.auth.user', JSON.stringify(user))
      localStorage.setItem('malulu.auth.expiresAt', '4102444800')
    }, user)
    let polls = 0
    let pending = false
    let overlap = false
    const message = { id: 103, mode: 'image', prompt: 'Polling regression fixture', status: 'queued',
      asset_refs: [], options: {}, result_urls: [], error_message: '', model_config_id: 1 }
    await page.route('**/api/**', async (route) => {
      const url = new URL(route.request().url())
      if (!url.pathname.startsWith('/api/')) return route.continue()
      let data = []
      if (url.pathname === '/api/auth/me') data = { user }
      if (url.pathname === '/api/model-configs/list') data = [{ type: 'image', title: 'image', models: [
        { id: 1, type: 'image', model_id: 'gpt-image-2.5-flare', name: 'Flare', endpoint: '', options: {}, enabled: 1, is_default: 1 },
      ] }]
      if (url.pathname === '/api/quick-create/messages/list') data = { messages: [message], has_more: false }
      if (url.pathname === '/api/quick-create/quote') data = { credits: 13.5, estimated: false, count: 1 }
      if (url.pathname === '/api/quick-create/messages/status') {
        overlap ||= pending
        pending = true
        polls++
        if (polls === 1) {
          pending = false
          return route.fulfill({ status: 503, json: { code: 503, message: 'Temporary failure' } })
        }
        if (polls === 2) {
          await new Promise((resolve) => setTimeout(resolve, 6500))
          data = { messages: [{ ...message, status: 'running' }] }
        } else {
          data = { messages: [{ ...message, status: 'success', result_urls: [`${base}/admin/logo.png`] }] }
        }
        await route.fulfill({ json: { code: 0, data, message: 'success' } })
        pending = false
        return
      }
      return route.fulfill({ json: { code: 0, data, message: 'success' } })
    })
    await page.goto(`${base}/admin/quick-create`)
    await page.getByText('排队中', { exact: true }).waitFor({ timeout: 10000 }).catch(async (error) => {
      throw new Error(`${error.message}; url=${page.url()}; errors=${JSON.stringify(errors)}; body=${(await page.locator('body').innerText()).slice(0, 1200)}`)
    })
    await page.getByText('生成中', { exact: true }).waitFor({ timeout: 20000 })
    await page.locator('.qc-media-image').waitFor({ timeout: 10000 })
    assert.equal(overlap, false, 'slow status requests must not overlap')
    assert.equal(polls, 3, 'polling must recover after transient failure and stop after success')
    await page.waitForTimeout(4300)
    assert.equal(polls, 3, 'completed task must stop polling')
    assert.equal(await page.getByText('检测人脸', { exact: true }).count(), 0)
    assert.deepEqual(errors, [])
    const output = path.join(os.tmpdir(), 'workflow-plus-toapis-check')
    fs.mkdirSync(output, { recursive: true })
    await page.screenshot({ path: path.join(output, 'polling-completed.png'), fullPage: true })
    console.log('PASS: queue -> transient failure -> slow running -> success, no overlapping polls, no retired face control')
  } finally {
    await browser.close()
  }
}
main().catch((error) => { console.error(error); process.exitCode = 1 })
