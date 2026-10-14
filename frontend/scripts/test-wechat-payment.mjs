import assert from 'node:assert/strict'
import { createRequire } from 'node:module'
import { mkdir } from 'node:fs/promises'
import { resolve } from 'node:path'

// API fixtures exercise checkout states without creating real WeChat transactions.
const require = createRequire(import.meta.url)
const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright')
const base = process.env.PAYMENT_UI_BASE_URL || 'http://127.0.0.1:5176'
const output = resolve(process.env.PAYMENT_UI_OUTPUT || '../tmp-inspect/payment')
await mkdir(output, { recursive: true })
const browser = await chromium.launch({ channel: 'chrome', headless: true })
let checks = 0

async function scenario(viewport) {
  const context = await browser.newContext({ viewport })
  const page = await context.newPage()
  const errors = []
  page.on('pageerror', error => errors.push(error.message))
  let enabled = true
  let catalogFailure = false
  let createFailure = false
  let queryFailure = false
  let balance = 250
  let nextId = 1
  const orders = []
  const submissions = []
  const user = { id: 9, role: 'user', username: 'payment-test', display_name: '支付测试', preferred_locale: 'zh-CN', credit_balance: balance }
  const packages = [10, 100, 500].map(amount => ({ id: `credits_${amount}`, name: `${amount} 元积分包`, amount_cny: amount, credit_amount: amount * 100 }))
  await context.addInitScript(value => {
    localStorage.setItem('malulu.auth.token', 'test-token')
    localStorage.setItem('malulu.auth.user', JSON.stringify(value))
    localStorage.setItem('malulu.workerCrew.collapsed', '1')
  }, user)
  await page.route(url => url.pathname.startsWith('/api/'), async route => {
    const path = new URL(route.request().url()).pathname
    let data = {}
    let status = 200
    const payload = route.request().postDataJSON() || {}
    if (path === '/api/auth/me') data = { user: { ...user, credit_balance: balance } }
    if (path === '/api/workers/status') data = { workers: [], generated_at: '' }
    if (path === '/api/payments/packages') {
      data = { enabled, packages }
      if (catalogFailure) status = 503
    }
    if (path === '/api/credits/ledger') {
      data = { balance, summary: { consume_count: 0, credits_spent: 0, credits_text: 0, credits_image: 0, credits_video: 0, credit_unit_cny: 0.01, spent_cny: 0 },
        list: orders.filter(order => order.status === 'paid').map(order => ({ id: order.id, entry_type: 'topup', amount: order.credit_amount, balance_after: balance, description: '微信支付充值', create_time: '2026-10-09 22:00:00' })),
        pagination: { page: 1, page_size: 20, total: orders.filter(order => order.status === 'paid').length } }
    }
    if (path === '/api/payments/orders') data = { orders: [...orders].reverse() }
    if (path === '/api/payments/create') {
      submissions.push(payload)
      if (createFailure) status = 503
      else {
        const item = packages.find(item => item.id === payload.package_id)
        data = { id: nextId++, order_no: `WF261009_${nextId}`, package_id: item.id, amount_cny: item.amount_cny, credit_amount: item.credit_amount,
          status: 'pending', code_url: 'weixin://wxpay/bizpayurl?pr=test_fixture', expires_at: new Date(Date.now() + 15 * 60_000).toISOString() }
        orders.push(data)
      }
    }
    if (path === '/api/payments/status') {
      data = orders.find(order => order.id === payload.id)
      if (queryFailure) status = 502
    }
    await route.fulfill({ status, contentType: 'application/json', body: JSON.stringify({ code: status === 200 ? 0 : status, message: status === 200 ? 'success' : '测试网络异常', data }) })
  })
  const label = viewport.width < 600 ? 'mobile' : 'desktop'
  await page.goto(`${base}/admin/my-credits`)
  await page.locator('.package').nth(2).waitFor()
  await page.getByRole('button', { name: '收起菜单', exact: true }).click()
  await page.waitForTimeout(350)
  await page.screenshot({ path: `${output}/${label}-packages.png`, fullPage: true })
  await page.locator('.package').nth(2).getByRole('button', { name: '购买', exact: true }).click()
  await page.locator('.credit-pay-dialog .qr-frame img').waitFor()
  await page.waitForTimeout(350)
  assert.equal(submissions.at(-1).package_id, 'credits_500')
  assert.match(submissions.at(-1).request_id, /^[a-f0-9]{32}$/)
  assert.equal('amount_cny' in submissions.at(-1), false)
  const pixels = await page.locator('.qr-frame img').evaluate(img => {
    const canvas = document.createElement('canvas')
    canvas.width = canvas.height = 256
    const ctx = canvas.getContext('2d')
    ctx.drawImage(img, 0, 0)
    const rgba = ctx.getImageData(0, 0, 256, 256).data
    let dark = 0
    let white = 0
    for (let i = 0; i < rgba.length; i += 4) { if (rgba[i] < 40) dark++; if (rgba[i] > 240) white++ }
    return { dark, white }
  })
  assert(pixels.dark > 3000 && pixels.white > 3000)
  const dialogRect = await page.locator('.credit-pay-dialog').boundingBox()
  assert(dialogRect.x >= 0 && dialogRect.x + dialogRect.width <= viewport.width + 1)
  assert(dialogRect.y >= 0 && dialogRect.y + dialogRect.height <= viewport.height + 1)
  await page.screenshot({ path: `${output}/${label}-qr.png`, fullPage: true })
  orders[0].status = 'paid'
  balance += orders[0].credit_amount
  await page.getByRole('button', { name: '刷新支付状态', exact: true }).click()
  await page.locator('.result-title').filter({ hasText: '支付成功' }).waitFor()
  await page.waitForTimeout(350)
  await page.locator('.stat-tile--primary strong').filter({ hasText: '50,250' }).waitFor()
  await page.screenshot({ path: `${output}/${label}-paid.png`, fullPage: true })
  await page.getByRole('button', { name: '关闭', exact: true }).click()

  createFailure = true
  await page.locator('.package').nth(0).getByRole('button', { name: '购买', exact: true }).click()
  await page.waitForFunction(() => !!localStorage.getItem('workflow.payment.request.9'))
  await page.waitForFunction(() => !document.querySelector('.buy-button.is-loading'))
  const failedRequest = submissions.at(-1)
  createFailure = false
  await page.locator('.package').nth(0).getByRole('button', { name: '购买', exact: true }).click()
  await page.locator('.credit-pay-dialog .qr-frame img').waitFor()
  assert.equal(submissions.at(-1).request_id, failedRequest.request_id)
  queryFailure = true
  await page.getByRole('button', { name: '刷新支付状态', exact: true }).click()
  await page.locator('.status-warning').waitFor()
  assert.equal(await page.locator('.result-title').count(), 0)
  queryFailure = false
  await page.getByRole('button', { name: '关闭', exact: true }).click()
  await page.reload()
  await page.locator('.order-history summary').waitFor()
  await page.locator('.order-history summary').click()
  await page.getByRole('button', { name: '查看支付', exact: true }).click()
  await page.locator('.credit-pay-dialog .qr-frame img').waitFor()
  orders[1].expires_at = new Date(Date.now() - 1000).toISOString()
  await page.getByRole('button', { name: '刷新支付状态', exact: true }).click()
  await page.locator('.result-title').filter({ hasText: '二维码已过期' }).waitFor()
  assert.equal(await page.locator('.qr-frame img').count(), 0)
  await page.getByRole('button', { name: '关闭', exact: true }).click()

  enabled = false
  await page.reload()
  await page.getByText('微信支付暂未开通', { exact: true }).waitFor()
  assert.equal(await page.locator('.package .el-button:disabled').count(), 3)
  catalogFailure = true
  await page.reload()
  await page.getByText('积分套餐加载失败', { exact: true }).waitFor()
  catalogFailure = false
  await page.getByRole('button', { name: '重试', exact: true }).click()
  await page.locator('.package').nth(2).waitFor()
  assert.deepEqual(errors, [])
  checks += 12
  console.log(`${label}: 12 checkout checks passed; QR pixels`, pixels)
  await context.close()
}

try {
  await scenario({ width: 1440, height: 1000 })
  await scenario({ width: 390, height: 844 })
  await scenario({ width: 320, height: 640 })
  console.log(`${checks} UI checks passed; screenshots in ${output}`)
} finally {
  await browser.close()
}
