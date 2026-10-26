const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')

const base = process.env.WORKSPACE_TEST_BASE || 'http://127.0.0.1:5177'
const output = path.join(os.tmpdir(), 'workflow-plus-responsive-check')
fs.mkdirSync(output, { recursive: true })

async function main() {
  const browser = await chromium.launch({ channel: 'msedge', headless: true, ignoreDefaultArgs: ['--hide-scrollbars'] })
  try {
    const page = await browser.newPage()
    const errors = []
    page.on('pageerror', error => errors.push(error.message))
    const user = { id: 901, username: 'responsive-fixture', role: 'user', credit_balance: 5000 }
    await page.addInitScript(user => {
      localStorage.setItem('malulu.auth.token', 'isolated-responsive-fixture')
      localStorage.setItem('malulu.auth.user', JSON.stringify(user))
      localStorage.setItem('malulu.auth.expiresAt', '4102444800')
    }, user)
    const nodes = ['input', 'text', 'image', 'video'].map((kind, index) => ({
      workflow_node_id: `node-${index}`, kind, label: ['剧情输入', '分镜脚本', '分镜图片', '视频生成'][index],
      status: index < 2 ? 'success' : 'pending', output_text: index === 1 ? '女儿提油回家，将商品递给妈妈。妈妈询问价格，女儿介绍优惠。' : '',
    }))
    const episodes = Array.from({ length: 8 }, (_, index) => ({
      id: index + 1, series_id: 1, number: index + 1, title: '女儿回家与妈妈的日常故事', status: 'draft',
      plot_input: '女儿提着一桶油回家，妈妈在厨房炒菜，两人聊起价格和优惠。',
      workflow_id: 1, workflow_name: '商品剧情短片制作流程', shots: [], workflow_state: { nodes },
    }))
    const series = { id: 1, title: '春季商品宣传片与家庭生活故事', description: '生活场景中的商品宣传', episodes, region: 'china', visual_style: 'realistic' }
    await page.route('**/api/**', async route => {
      const url = new URL(route.request().url())
      if (!url.pathname.startsWith('/api/')) return route.continue()
      let data = []
      if (url.pathname === '/api/auth/me') data = { user }
      if (url.pathname === '/api/series/list') data = [series]
      if (url.pathname === '/api/episodes/detail') data = episodes[0]
      if (url.pathname === '/api/assets/list') data = [
        { id: 1, name: '妈妈', type: 'character', series_id: 1, description: '中年女性，穿着围裙', images: [], image_jobs: [] },
        { id: 2, name: '厨房', type: 'scene', series_id: 1, description: '明亮整洁的家庭厨房', images: [], image_jobs: [] },
      ]
      await route.fulfill({ json: { code: 0, data, message: 'success' } })
    })
    for (const viewport of [
      { width: 1920, height: 980 }, { width: 1536, height: 760 }, { width: 1366, height: 680 },
      { width: 1280, height: 620 }, { width: 1024, height: 650 }, { width: 390, height: 844 },
    ]) {
      await page.setViewportSize(viewport)
      await page.goto(`${base}/admin/series`)
      await page.locator('.gallery .media-card').first().waitFor()
      await checkLayout('gallery')
      await page.goto(`${base}/admin/series?series_id=1&episode_id=1&nodes=${encodeURIComponent('{"node-1":true}')}`)
      await page.locator('.stage-card').first().waitFor()
      await checkLayout('production')
      await page.getByRole('button', { name: '编辑概要', exact: true }).first().click()
      await page.locator('.episode-plot-editor textarea').waitFor()
      await checkLayout('editing')
      if (viewport.width === 1536) {
        await page.locator('.sidebar-toggle').click()
        await checkLayout('collapsed-navigation')
        await page.locator('.sidebar-toggle').click()
      }
      console.log(`PASS ${viewport.width}x${viewport.height}: works gallery, production and editor`)

      async function checkLayout(name) {
        const bounds = await page.evaluate(() => {
          const rect = selector => {
            const element = document.querySelector(selector)
            if (!element) return null
            const { x, y, width, height, right, bottom } = element.getBoundingClientRect()
            return { x, y, width, height, right, bottom, clientWidth: element.clientWidth, scrollWidth: element.scrollWidth }
          }
          return Object.fromEntries(['.app-workspace', '.gallery__body', '.production-board', '.episode-rail', '.episode-workbench', '.workbench-body', '.asset-panel', '.episode-summary', '.stage-card', '.stage-actions'].map(selector => [selector, rect(selector)]))
        })
        await page.screenshot({ path: path.join(output, `${viewport.width}-${name}.png`), fullPage: true })
        assert.ok(bounds['.app-workspace'].right <= viewport.width + 1, `${viewport.width}px workspace exceeds screen`)
        for (const [selector, rect] of Object.entries(bounds)) if (rect) {
          assert.ok(rect.scrollWidth <= rect.clientWidth + 1, `${viewport.width}px ${name} ${selector} overflows horizontally`)
          assert.ok(rect.right <= viewport.width + 1, `${viewport.width}px ${name} ${selector} exceeds viewport`)
        }
        if (name !== 'gallery') {
          const board = bounds['.production-board']
          const workbench = bounds['.episode-workbench']
          const body = bounds['.workbench-body']
          assert.ok(board.bottom <= viewport.height + 1, 'production board stays inside viewport')
          assert.ok(workbench.width >= Math.min(500, board.clientWidth) - 1, 'editor retains usable width')
          assert.ok(body.height >= 220, 'editor retains usable height')
          await page.locator('.stage-card').last().scrollIntoViewIfNeeded()
          const last = await page.locator('.stage-card').last().boundingBox()
          assert.ok(last.y < viewport.height && last.y + last.height > 0, 'last stage can be reached by scrolling')
          await page.locator('.asset-panel').scrollIntoViewIfNeeded()
        }
      }
    }
    assert.deepEqual(errors, [])
  } finally { await browser.close() }
  console.log(`Screenshots: ${output}`)
}
main().catch(error => { console.error(error); process.exitCode = 1 })
