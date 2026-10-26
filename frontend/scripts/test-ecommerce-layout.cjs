const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright')
const assert = require('node:assert/strict')
const fs = require('node:fs')
const os = require('node:os')
const path = require('node:path')
const base = process.env.ECOMMERCE_UI_URL || 'http://127.0.0.1:5177'

async function main() {
  const browser = await chromium.launch({ channel: 'msedge', headless: true })
  try {
    const page = await browser.newPage({ viewport: { width: 1440, height: 1100 } })
    await page.route('**/api/**', route => route.fulfill({ json: { code: 0, data: [] } }))
    await page.goto(`${base}/admin/login`)
    const checks = await page.evaluate(async () => {
      const { renderPoster } = await import('/admin/src/features/ecommerce/poster-renderer.ts')
      const { campaignStyle } = await import('/admin/src/features/ecommerce/domain/campaign-style.js')
      if (campaignStyle('monochrome', 'none', false, true).palette[0] !== '#222426') throw new Error('The dev server is serving an outdated Amazon palette')
      const prototype = CanvasRenderingContext2D.prototype
      const drawImage = prototype.drawImage, fillText = prototype.fillText
      const results = []
      document.body.replaceChildren()
      document.body.style.cssText = 'margin:24px;background:#e9edef;font:14px sans-serif;display:grid;grid-template-columns:repeat(3,1fr);gap:20px'
      for (const ratio of [1, 1.5]) for (const layout of ['header', 'split', 'footer']) {
        const source = document.createElement('canvas'); source.width = 1024; source.height = 1024 * ratio
        const context = source.getContext('2d')
        context.fillStyle = '#dce8e6'; context.fillRect(0, 0, source.width, source.height)
        context.fillStyle = '#638a83'; context.fillRect(260, 150, 504, source.height - 300)
        context.fillStyle = '#ffffff'; context.fillRect(330, 330, 364, source.height - 660)
        const textBoxes = [], photoBoxes = []
        prototype.drawImage = function (image, x, y, width, height) { photoBoxes.push({ x, y, width, height }); return drawImage.call(this, image, x, y, width, height) }
        prototype.fillText = function (text, x, y) {
          textBoxes.push({ x, y, width: this.measureText(text).width, height: Number(this.font.match(/(\d+)px/)[1]) * 1.35 })
          return fillText.call(this, text, x, y)
        }
        let canvas
        try {
          canvas = await renderPoster(source.toDataURL(), { presetId: 'monochrome', layout,
            headline: '保持商品真实外观展示每一处清晰可见的细节',
            subtitle: '已确认的商品资料用于排版展示，让完整商品与说明各有空间，让完整商品与说明各有空间。'.slice(0, 40),
            sellingPoints: ['已确认的商品外观与细节说明文字', '已确认的商品外观与细节说明文字', '已确认的商品外观与细节说明文字'] }, 640)
        } finally { prototype.drawImage = drawImage; prototype.fillText = fillText }
        const tolerance = 1
        const inside = box => box.x >= -tolerance && box.y >= -tolerance && box.x + box.width <= canvas.width + tolerance && box.y + box.height <= canvas.height + tolerance
        const overlap = (a, b) => a.x < b.x + b.width - tolerance && a.x + a.width > b.x + tolerance && a.y < b.y + b.height - tolerance && a.y + a.height > b.y + tolerance
        results.push({ layout, ratio, inside: [...textBoxes, ...photoBoxes].every(inside),
          noOverlap: textBoxes.every((box, i) => textBoxes.slice(i + 1).every(other => !overlap(box, other)) && photoBoxes.every(photo => !overlap(box, photo))),
          fullPhoto: photoBoxes.length === 1 && Math.abs(photoBoxes[0].height / photoBoxes[0].width - ratio) < .001,
          pixels: new Set(canvas.getContext('2d').getImageData(0, 0, canvas.width, canvas.height).data).size })
        const figure = document.createElement('figure'); figure.style.cssText = 'margin:0;min-width:0'
        canvas.style.cssText = 'width:100%;height:auto;display:block'
        const caption = document.createElement('figcaption'); caption.textContent = `${ratio === 1 ? '1:1' : '2:3'} / ${layout}`
        figure.append(caption, canvas); document.body.append(figure)
      }
      return results
    })
    for (const check of checks) {
      assert(check.inside && check.noOverlap && check.fullPhoto && check.pixels > 30, JSON.stringify(check))
    }
    const output = path.join(os.tmpdir(), 'workflow-plus-ecommerce-check'); fs.mkdirSync(output, { recursive: true })
    await page.screenshot({ path: path.join(output, 'campaign-layouts.png'), fullPage: true })
    console.log(`PASS: ${checks.length} square/portrait layouts fit long text, preserve the full photo and have no overlapping text or product`)
  } finally { await browser.close() }
}
main().catch(error => { console.error(error); process.exitCode = 1 })
