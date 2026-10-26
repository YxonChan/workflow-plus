const { chromium } = require(process.env.PLAYWRIGHT_MODULE_PATH || 'playwright')
const assert = require('node:assert/strict')
const { unzipSync } = require('fflate')
const { pathToFileURL } = require('node:url')
const path = require('node:path')
const fs = require('node:fs')
const os = require('node:os')
const base = process.env.ECOMMERCE_UI_URL || 'http://127.0.0.1:5177'
const output = path.join(os.tmpdir(), 'workflow-plus-ecommerce-check')
fs.mkdirSync(output, { recursive: true })
const clone = value => JSON.parse(JSON.stringify(value))
const png = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==', 'base64')
const file = name => ({ name, mimeType: 'image/png', buffer: png })
async function choose(page, root, label) {
  await root.scrollIntoViewIfNeeded()
  await root.locator('xpath=ancestor::div[contains(concat(" ", normalize-space(@class), " "), " el-select ")][1]').click()
  const listboxId = await root.getAttribute('aria-controls')
  const listbox = listboxId ? page.locator(`[id="${listboxId}"]`) : page.locator('.el-select-dropdown:visible')
  await listbox.getByRole('option', { name: label, exact: true }).click()
}
async function waitUntil(predicate) {
  for (let count = 0; count < 100; count++) { if (predicate()) return; await new Promise(resolve => setTimeout(resolve, 50)) }
  assert.fail('Fixture condition timed out')
}
async function main() {
  const { createStorefrontProject, selectStorefrontGoal } = await import(pathToFileURL(path.resolve(__dirname, '../src/features/ecommerce/domain/storefront.js')))
  const { videoBriefSignature } = await import(pathToFileURL(path.resolve(__dirname, '../src/features/ecommerce/domain/video-brief.js')))
  // Native scrollbar space must be present to detect disclosure layout shifts.
  const browser = await chromium.launch({ channel: 'msedge', headless: true, ignoreDefaultArgs: ['--hide-scrollbars'] })
  try {
    const widths = process.env.ECOMMERCE_UI_WIDTHS?.split(',').map(Number)
    for (const viewport of [{ width: 1440, height: 1000 }, { width: 1180, height: 800 }, { width: 390, height: 844 }].filter(viewport => !widths || widths.includes(viewport.width))) {
      console.log(`Checking ${viewport.width}px`)
      const page = await browser.newPage({ viewport })
      const errors = []; const submitted = []; const quoted = []
      page.on('pageerror', error => errors.push(error.message))
      const state = createStorefrontProject(); state.name = '春季商品拍摄'
      assert.equal(state.integration.goal, 'detail', 'new products default to a single template detail image')
      selectStorefrontGoal(state, 'detail'); state.creative.copy.enabled = false
      const product = `${base}/admin/ecommerce-box/styles/technology.jpg`
      const alternate = `${base}/admin/ecommerce-box/styles/editorial.jpg`
      let detail = { id: 1, name: state.name, revision: 1, state, runs: [], jobs: [] }
      let uploads = 0; let failNextSubmit = false; const checked = []; const briefCalls = []; const copyCalls = []; const savedAssets = []
      let brief = { version: 0, phase: 'empty', materials: [], roles: {}, characters: [], questions: [], answers: {}, messages: [], proposal: '', story: null }
      const user = { id: 901, username: 'ecommerce-fixture', role: 'user', credit_balance: 5000 }
      await page.addInitScript(user => {
        window.SpeechRecognition = class {
          start() { this.onresult?.({ resultIndex: 0, results: [{ isFinal: true, 0: { transcript: '女儿提着一桶油给妈妈，妈妈在厨房炒菜，询问价格，女儿回答现在买很实惠。' } }] }); this.onend?.() }
          stop() { this.onend?.() }
          abort() { this.onend?.() }
        }
        localStorage.setItem('malulu.auth.token', 'isolated-ecommerce-fixture')
        localStorage.setItem('malulu.auth.user', JSON.stringify(user))
        localStorage.setItem('malulu.auth.expiresAt', '4102444800')
      }, user)
      const imageModels = ['gpt-image-2.5-flare', 'gpt-image-2.5-flare-official'].map((model_id, index) => ({ id: index + 1, type: 'image', name: model_id, model_id, options: {}, enabled: 1, is_default: index === 0 ? 1 : 0 }))
      await page.route('**/fixture-video.mp4', route => route.fulfill({ status: 204 }))
      await page.route('**/api/**', async route => {
        const url = new URL(route.request().url()); if (!url.pathname.startsWith('/api/')) return route.continue()
        const body = !url.pathname.endsWith('/upload') && route.request().postData() ? route.request().postDataJSON() : {}
        let data = []
        if (url.pathname === '/api/auth/me') data = { user }
        if (url.pathname === '/api/model-configs/list') data = [{ type: 'image', models: imageModels }, { type: 'video', models: [{ id: 3, name: 'Seedance 2', model_id: 'seedance-2', type: 'video', options: {} }] }, { type: 'text', models: [{ id: 4, name: 'DeepSeek', model_id: 'deepseek-chat', type: 'text', options: {} }] }]
        if (url.pathname === '/api/ecommerce/projects/list') data = [{ id: 1, name: detail.state.name, revision: detail.revision }]
        if (url.pathname === '/api/ecommerce/projects/detail') data = clone(detail)
        if (url.pathname === '/api/ecommerce/projects/save') {
          assert.equal(body.revision, detail.revision)
          detail.state = body.state; detail.revision++; data = { revision: detail.revision }
        }
        if (url.pathname === '/api/ecommerce/upload') data = { id: `upload-${++uploads}`, name: route.request().postDataBuffer()?.toString().match(/filename="([^"]+)"/)?.[1] || `上传 ${uploads}`, src: `${product}?upload=${uploads}`, width: 800, height: 1000 }
        if (url.pathname === '/api/ecommerce/quote') {
          quoted.push(clone(body))
          data = { credits: body.items.length * (body.items[0].model_config_id === 2 ? 75 : 18), estimated: body.items[0].model_config_id === 2, count: body.items.length, items: body.items }
        }
        if (url.pathname === '/api/ecommerce/generate') {
          submitted.push(clone(body))
          if (!detail.runs.some(run => run.request_key === body.request_key)) {
            const runId = detail.runs.length + 1
            detail.runs.push({ id: runId, request_key: body.request_key, paused: false, snapshot: { credits: body.items.length * 18, estimated: false, items: body.items }, create_time: '2026-10-20 10:00:00' })
            body.items.forEach(item => { detail.jobs.push({ ...item, id: detail.jobs.length + 1, run_id: runId, project_id: 1, status: 'queued', result_url: '', error_message: '', create_time: '2026-10-20 10:00:00' }) })
          }
          if (failNextSubmit) { failNextSubmit = false; return route.fulfill({ status: 503, json: { code: 503, message: 'Fixture connection lost after acceptance' } }) }
          data = clone(detail)
        }
        if (url.pathname === '/api/ecommerce/control') { detail.runs.find(run => run.id === body.run_id).paused = body.action !== 'resume'; data = clone(detail) }
        if (url.pathname === '/api/ecommerce/check-original') { checked.push(body.job_id); detail.jobs.find(job => job.id === body.job_id).status = 'failed'; data = clone(detail) }
        if (url.pathname === '/api/ecommerce/save-asset') { savedAssets.push(clone(body)); detail.jobs.find(job => job.id === body.job_id).asset_id = 12; data = { asset_id: 12 } }
        if (url.pathname === '/api/ecommerce/draft-copy') {
          copyCalls.push(clone(body))
          data = { headline: '日常好物 轻松相伴', subtitle: '让每一次使用都多一份心意', selling_points: ['简洁外观', '日常相伴'], product_name: '商品', observations: [], analyzed_images: 1 }
        }
        if (url.pathname === '/api/ecommerce/brief') data = { ...clone(brief), stale: brief.context_hash !== videoBriefSignature(detail.state) }
        if (url.pathname === '/api/ecommerce/brief-turn') {
          briefCalls.push(clone(body)); assert.equal(body.version, brief.version)
          const contextHash = videoBriefSignature(detail.state)
          const previousHash = brief.context_hash
          const previousMaterials = JSON.stringify(brief.materials.map(media => media.src))
          const materialList = [...detail.state.product.uploads]
          for (const character of detail.state.creative.video.story.characters) if (character.source !== 'description' && character.image) materialList.push(character.image)
          for (const [section, flag] of [['model', 'useModel'], ['scene', 'useScene']]) {
            const ref = detail.state[section]
            if (detail.state.creative[flag]) materialList.push(...ref.uploads.filter(media => media.id === ref.selectedUploadId))
          }
          const materials = [...new Map(materialList.map(media => [media.src, media])).values()]
          brief = clone(brief); brief.version++; brief.stale = false; brief.context_hash = contextHash
          brief.materials = materials.map(media => brief.materials.find(known => known.id === media.id) || { ...media,
            kind: /model|mother|daughter|妈妈|女儿/.test(media.name) ? 'person' : /scene/.test(media.name) ? 'scene' : 'product',
            summary: /model|mother|daughter|妈妈|女儿/.test(media.name) ? '一位成年女性的人物参考照片' : /scene/.test(media.name) ? '室内场景参考' : '商品外观与包装参考' })
          if (body.action === 'analyze' && (previousHash !== contextHash || previousMaterials !== JSON.stringify(materials.map(media => media.src)))) {
            brief.phase = 'materials'; brief.proposal = ''; brief.story = null; brief.message = '已识别素材，请填写剧情并确认人物用途。'
          }
          if (body.action === 'consult') {
            for (const media of brief.materials) if (body.input.kinds?.[media.id]) media.kind = body.input.kinds[media.id]
            Object.assign(brief.roles, body.input.roles || {})
            for (const question of brief.questions) if (body.input.answers?.[question.id]) brief.answers[question.id] = { question: question.text, answer: body.input.answers[question.id] }
            if (body.input.message) brief.messages.push({ role: 'user', text: body.input.message })
            brief.characters = [
              { id: 'c1', name: '女儿', description: '成年女性，齐肩黑发，浅色上衣', source: 'description', image: null },
              { id: 'c2', name: '妈妈', description: '中年女性，短发，家居围裙', source: 'description', image: null }]
            for (const explicit of detail.state.creative.video.story.characters) {
              const media = brief.materials.find(media => media.src === explicit.image?.src && media.kind === 'person')
              if (media && !brief.roles[media.id]) {
                for (const old of brief.materials) if (old.id !== media.id && brief.roles[old.id] === explicit.id) { old.kind = 'ignore'; delete brief.roles[old.id] }
                brief.roles[media.id] = explicit.id
              }
            }
            brief.questions = brief.answers.tone ? [] : [{ id: 'tone', text: '希望这段视频是什么感觉？', options: ['自然生活感', '精致广告感'] }]
            const ready = !brief.questions.length && brief.materials.every(media => media.kind !== 'person' || brief.roles[media.id])
            brief.phase = ready ? 'ready' : 'questions'; brief.message = ready ? '方案已整理好，看看是否满意。' : '这张人物照片要扮演谁？再选一下视频氛围。'
            brief.messages.push({ role: 'assistant', text: brief.message })
            brief.product_ids = brief.materials.filter(media => media.kind === 'product').map(media => media.id)
            brief.scene = brief.materials.find(media => media.kind === 'scene') || null
            brief.proposal = ready ? `${detail.state.creative.video.duration}秒竖屏生活短片：女儿提油回家送给妈妈，妈妈炒菜时问价格，女儿回答“现在买很实惠”。使用所选照片作为妈妈的外观。${body.input.message || ''}` : ''
            brief.story = ready ? { title: '回家送油', duration: detail.state.creative.video.duration, hasDialogue: true,
              script: `0-${detail.state.creative.video.duration} 秒：女儿提油回家，递给妈妈。妈妈说：“这油多少钱？”女儿说：“现在买很实惠。”`,
              warnings: ['优惠信息发布前需核实'], characters: brief.characters.map(character => {
                const image = brief.materials.find(media => media.kind === 'person' && brief.roles[media.id] === character.id)
                return { ...character, source: image ? 'upload' : 'description', image: image || null }
              }) } : null
          }
          if (body.action === 'confirm') { assert.equal(brief.phase, 'ready'); brief.phase = 'confirmed' }
          data = clone(brief)
        }
        await route.fulfill({ json: { code: 0, message: 'success', data } })
      })
      async function clickGenerate() {
        await page.locator('.generate-button:not([disabled])').waitFor({ timeout: 15000 })
        await page.locator('.generate-button').click()
      }
      async function refresh() { await page.getByRole('button', { name: '刷新', exact: true }).click() }
      async function selectImageGoal(label) {
        await page.locator('.content-mode .el-radio-button').filter({ hasText: '商品图片' }).click()
        if (await page.locator('.advanced-trigger').getAttribute('aria-expanded') !== 'true') await page.locator('.advanced-trigger').click()
        await choose(page, page.getByRole('combobox', { name: '图片用途', exact: true }), label)
        await page.getByRole('button', { name: '收起配置', exact: true }).click()
      }
      async function screenshot(name) { await page.screenshot({ path: path.join(output, `${viewport.width}-${name}.png`), fullPage: true, animations: 'disabled' }) }
      try {
        await page.goto(`${base}/admin/ecommerce-box`)
        await page.getByRole('heading', { name: '电商宝盒', exact: true }).waitFor({ timeout: 15000 })
        await page.getByRole('button', { name: /添加商品图片/ }).waitFor()
        assert.equal(await page.locator('.generate-button').isDisabled(), true)
        assert.equal(await page.locator('.box-tabs').count(), 0, 'main flow has no tab navigation')
        assert.equal(await page.locator('.content-mode .el-radio-button').count(), 2, 'main flow only asks image or video')
        await screenshot('empty')
        if (process.env.ECOMMERCE_UI_LAYOUT_ONLY) {
          async function toggleLayout(label, trigger = '.advanced-trigger') {
            await page.locator(trigger).scrollIntoViewIfNeeded()
            const frames = await page.evaluate(async trigger => {
              const measure = () => Object.fromEntries(['.storefront-workspace', '.creation-pane', '.creation-fields', '.product-dropzone', '.content-mode', '.advanced-trigger', '.creation-footer', '.results-pane'].map(selector => {
                const element = document.querySelector(selector), rect = element.getBoundingClientRect()
                return [selector, { x: rect.x, y: rect.y, width: rect.width, height: rect.height, clientWidth: element.clientWidth }]
              }))
              const frames = [measure()]
              document.querySelector(trigger).click()
              for (let index = 0; index < 16; index++) { await new Promise(requestAnimationFrame); frames.push(measure()) }
              return frames
            }, trigger)
            for (const frame of frames.slice(1)) {
              for (const [selector, before] of Object.entries(frames[0])) {
                const properties = viewport.width > 900 && ['.storefront-workspace', '.creation-pane', '.creation-fields', '.creation-footer', '.results-pane'].includes(selector)
                  ? ['x', 'y', 'width', 'height', 'clientWidth'] : ['x', 'width', 'clientWidth']
                for (const property of properties) assert.ok(Math.abs(frame[selector][property] - before[property]) < 1, `${viewport.width}px ${label}: ${selector} ${property} shifted from ${before[property]} to ${frame[selector][property]}`)
              }
            }
            assert.equal(await page.locator('.el-drawer:visible').count(), 0)
            assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), 'no horizontal page overflow')
            await screenshot(`layout-${label}`)
          }
          await toggleLayout('image-open')
          await toggleLayout('image-output-open', '.output-settings > summary')
          await toggleLayout('image-output-close', '.output-settings > summary')
          await toggleLayout('image-close')
          await page.locator('.content-mode .el-radio-button').filter({ hasText: '商品视频' }).click()
          await toggleLayout('video-open')
          await toggleLayout('video-close')
          await page.getByTestId('product-upload').setInputFiles([file('oil.png'), file('mother.png'), file('scene.png')])
          await waitUntil(() => brief.materials.length === 3)
          await toggleLayout('materials-open')
          await toggleLayout('materials-close')
          assert.deepEqual(errors, [])
          console.log(`PASS ${viewport.width}px: stable controls, native scrollbar gutters and inline settings across image/video/materials toggles`)
          continue
        }
        if (viewport.width !== 1440) {
          assert.equal(await page.getByRole('heading', { name: '详情模板', exact: true }).count(), 1)
          assert.equal(await page.locator('.output-plan').count(), 0, 'detail workflow has no page or count plan')
          await screenshot('purpose-plan')
          await page.locator('.selected-style').click()
          assert.equal(await page.locator('.style-gallery button').count(), 8)
          assert.equal(await page.locator('.style-gallery button').filter({ hasText: '模特试穿' }).count(), 0)
          assert(!await page.evaluate(() => document.documentElement.scrollWidth > innerWidth), 'purpose styles fit narrow screens')
          await screenshot('purpose-styles')
          await page.locator('.el-dialog:visible .el-dialog__headerbtn').click()
          await page.locator('.advanced-trigger').click()
          await selectImageGoal('模特试穿')
          await page.locator('.advanced-trigger').click()
          await page.locator('.selected-style').click()
          assert.equal(await page.locator('.style-gallery button').count(), 3)
          assert.equal(await page.locator('.style-gallery button').filter({ hasText: '亚马逊' }).count(), 0)
          await screenshot('tryon-styles')
          await page.locator('.style-gallery button').first().click()
          await page.getByRole('button', { name: '收起配置', exact: true }).click()
          assert.equal(await page.locator('.model-choice .el-select').count(), 1)
          await screenshot('simple-tryon')
          await page.locator('.content-mode .el-radio-button').filter({ hasText: '商品视频' }).click()
          await page.getByTestId('product-upload').setInputFiles([file('oil.png'), file('mother.png')])
          await waitUntil(() => brief.materials.length === 2)
          const motherId = detail.state.product.uploads[1].id
          await page.locator('.duration-options .el-radio-button').filter({ hasText: '15 秒' }).click()
          await page.locator('.advanced-trigger').click()
          await page.locator('.output-settings > summary').click()
          await choose(page, page.getByRole('combobox', { name: '画面比例', exact: true }), '竖屏 9:16')
          assert.equal(await page.locator('.creation-fields .inline-settings').count(), 1)
          assert.equal(await page.locator('.el-drawer').count(), 0)
          await screenshot('settings')
          await page.getByRole('button', { name: '收起配置', exact: true }).click()
          let composer = page.getByTestId('story-composer')
          await composer.getByRole('button', { name: '口述剧情', exact: true }).click()
          await clickGenerate()
          await choose(page, composer.locator(`[data-material="${motherId}"]`).getByRole('combobox', { name: /人物角色/ }), '妈妈')
          await choose(page, composer.getByRole('combobox', { name: '希望这段视频是什么感觉？', exact: true }), '自然生活感')
          await screenshot('video-questions')
          await clickGenerate()
          await composer.getByTestId('video-proposal').waitFor()
          await page.getByRole('button', { name: '满意，生成视频', exact: true }).waitFor()
          assert.equal(submitted.length, 0)
          await composer.getByTestId('video-proposal').scrollIntoViewIfNeeded()
          await screenshot('video-proposal')
          await clickGenerate()
          await page.getByRole('heading', { name: '确认生成', exact: true }).waitFor()
          await page.getByRole('button', { name: '确认生成', exact: true }).click()
          await waitUntil(() => submitted.length === 1)
          assert.deepEqual(submitted[0].items[0].request.images, [`${product}?upload=2`, `${product}?upload=1`])
          assert.equal(submitted[0].items[0].request.options.duration, 15)
          const calls = briefCalls.length
          await page.reload()
          composer = page.getByTestId('story-composer')
          await composer.getByTestId('video-proposal').waitFor()
          assert.equal(briefCalls.length, calls)
          await composer.getByTestId('video-proposal').scrollIntoViewIfNeeded()
          await screenshot('video-reload')
          if (viewport.width === 1180) {
            async function requestMotherImage() {
              if (await composer.locator('.story-review').getAttribute('open') === null) await composer.locator('.story-review > summary').click()
              await composer.locator('[data-character="c2"]').getByRole('button', { name: '生成人物', exact: true }).click()
              await page.getByRole('heading', { name: '确认生成', exact: true }).waitFor()
              await page.getByRole('button', { name: '确认生成', exact: true }).click()
            }
            await requestMotherImage()
            await waitUntil(() => submitted.length === 2 && detail.state.creative.video.story.characters[1].source === 'ai')
            const firstMother = detail.jobs.at(-1)
            firstMother.status = 'success'; firstMother.result_url = alternate
            await refresh()
            await waitUntil(() => brief.materials.some(media => media.src === alternate))
            await clickGenerate()
            await composer.getByTestId('video-proposal').waitFor()
            assert.equal(detail.state.creative.video.story.characters[1].image.src, alternate)
            assert.equal(brief.materials.find(media => media.id === motherId).kind, 'ignore')
            await clickGenerate()
            await page.getByRole('heading', { name: '确认生成', exact: true }).waitFor()
            assert.deepEqual(quoted.at(-1).items[0].request.images, [alternate, `${product}?upload=1`])
            await page.locator('.el-dialog:visible').getByRole('button', { name: '取消', exact: true }).click()
            await requestMotherImage()
            await waitUntil(() => submitted.length === 3 && detail.state.creative.video.story.characters[1].source === 'ai')
            assert.equal(detail.state.creative.video.story.characters[1].image, null, 'older character result cannot fill a newer pending job')
            const secondMother = detail.jobs.at(-1)
            secondMother.status = 'success'; secondMother.result_url = `${alternate}?replacement=2`
            await refresh()
            await waitUntil(() => brief.materials.some(media => media.src === secondMother.result_url))
            await clickGenerate()
            await composer.getByTestId('video-proposal').waitFor()
            assert.equal(detail.state.creative.video.story.characters[1].image.src, secondMother.result_url)
          }
          assert(!await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth), 'responsive flow has no horizontal overflow')
          assert.deepEqual(errors, [])
          console.log(`PASS ${viewport.width}px: left-column settings, one model dropdown, multi-upload, recognition, role and question selects, explicit approval, ordered references and reload`)
          continue
        }
        await page.getByTestId('product-upload').setInputFiles([file('a.png'), file('b.png')])
        await page.waitForFunction(() => document.querySelectorAll('.product-dropzone .media-tile').length === 2)
        await page.locator('.product-dropzone .media-strip').evaluate(element => {
          const transfer = new DataTransfer()
          for (const name of ['drop-a.png', 'drop-b.png']) transfer.items.add(new File([new Uint8Array([137,80,78,71])], name, { type: 'image/png' }))
          element.dispatchEvent(new DragEvent('drop', { bubbles: true, cancelable: true, dataTransfer: transfer }))
        })
        await page.waitForFunction(() => document.querySelectorAll('.product-dropzone .media-tile').length === 4)
        assert.equal(uploads, 4, 'each dropped file uploads exactly once')
        console.log('  uploads and image setup')
        assert.equal(await page.locator('.creation-fields .el-select').count(), 0, 'default flow has no parameter dropdowns')
        assert.equal(await page.locator('.creation-fields .selected-style').count(), 1, 'one compatible detail template is visible in the main flow')
        await page.getByRole('textbox', { name: '制作要求', exact: true }).fill('棉质，保留缝线和领口')
        await page.getByRole('textbox', { name: '制作要求', exact: true }).blur()
        await waitUntil(() => quoted.at(-1)?.items[0]?.request.prompt.includes('棉质'))
        assert.equal(quoted.at(-1).items.length, 1)
        assert(quoted.at(-1).items.every(item => item.page_key === '' && item.request.original.design.layout === 'template' && item.kind === 'creative'))
        await screenshot('ready')
        await page.locator('.advanced-trigger').click()
        await page.locator('.creation-fields .inline-settings').waitFor()
        const purposeInput = page.getByRole('combobox', { name: '图片用途', exact: true })
        await choose(page, purposeInput, '商品主图')
        await waitUntil(() => quoted.at(-1)?.items.length === 1 && quoted.at(-1)?.items[0]?.page_key === 'M1')
        assert.equal(await page.locator('.output-plan').count(), 0)
        assert.equal(await page.locator('.reference-settings').count(), 0, 'pure white main image has no person or scene controls')
        await page.locator('.selected-style').click()
        assert.equal(await page.locator('.style-gallery button').count(), 4)
        assert.equal(await page.locator('.style-gallery button').filter({ hasText: '商品详情' }).count(), 0)
        await screenshot('main-styles')
        await page.locator('.el-dialog:visible .el-dialog__headerbtn').click()
        await choose(page, purposeInput, '模特试穿')
        await waitUntil(() => quoted.at(-1)?.items[0]?.request.original.design?.purpose === 'tryon')
        await page.locator('.selected-style').click()
        assert.equal(await page.locator('.style-gallery button').count(), 3)
        assert.equal(await page.locator('.style-gallery button').filter({ hasText: '主图' }).count(), 0)
        await page.locator('.style-gallery button').filter({ hasText: '自然街拍' }).click()
        await waitUntil(() => quoted.at(-1)?.items[0]?.request.original.design?.presetId === 'tryon-street')
        assert.equal(quoted.at(-1).items[0].request.original.styleReference, undefined)
        assert.match(quoted.at(-1).items[0].request.prompt, /最终模特日常街拍试穿照片/)
        await screenshot('tryon-style')
        await choose(page, purposeInput, '商品详情图')
        await waitUntil(() => quoted.at(-1)?.items.length === 1 && quoted.at(-1)?.items[0]?.request.original.design?.layout === 'template')
        assert.equal(await page.locator('.output-plan').count(), 0, 'template details do not expose page selection')
        await page.locator('.selected-style').click()
        await page.locator('.style-gallery button').filter({ hasText: '亚马逊 · 商品详情' }).click()
        await waitUntil(() => quoted.at(-1)?.items[0]?.request.original.design?.presetId === 'monochrome')
        assert.equal(quoted.at(-1).items.length, 1)
        assert.equal(quoted.at(-1).items[0].page_key, '')
        assert.equal(quoted.at(-1).items[0].request.original.styleReference, '/admin/ecommerce-box/styles/monochrome.jpg')
        assert.match(quoted.at(-1).items[0].request.prompt, /同一张连续画布/)
        assert(!quoted.at(-1).items[0].request.prompt.includes('纯摄影模式'), 'the served generation plan preserves the whole template layout')
        await screenshot('amazon-template')
        assert.equal(await page.locator('.el-drawer').count(), 0, 'settings expand in the left column without a drawer')
        assert.equal(await page.locator('.advanced-settings .scene-grid').count(), 0, 'disabled scenes do not occupy the settings panel')
        assert.equal(await page.getByRole('button', { name: '上传模特', exact: true }).isVisible(), false, 'references stay optional and collapsed')
        await page.locator('.product-processing > summary').click()
        await page.getByRole('textbox', { name: '商品名称', exact: true }).fill('春季新品')
        await page.getByRole('textbox', { name: '商品资料', exact: true }).fill('棉质，保留缝线和领口')
        await page.locator('.product-processing > summary').click()
        await screenshot('settings-default')
        await page.getByRole('button', { name: '收起配置', exact: true }).click()
        await clickGenerate()
        await page.locator('.job-tile').first().waitFor()
        assert.equal(submitted.length, 1, 'main generation submits directly without extra confirmation')
        assert.equal(await page.getByRole('heading', { name: '确认生成', exact: true }).count(), 0)
        assert.equal(submitted[0].items.length, 1)
        assert.equal(submitted[0].items[0].request.options.size, '2:3')
        assert.equal(submitted[0].items[0].page_key, '')
        assert.equal(submitted[0].items[0].request.original.design.layout, 'template')
        assert.equal(submitted[0].items[0].request.original.styleReference, '/admin/ecommerce-box/styles/monochrome.jpg')
        await page.locator('.job-tile .job-info small').filter({ hasText: '等待生成' }).first().waitFor()
        detail.jobs.at(-1).provider_submitted = true
        await refresh()
        await page.locator('.job-tile .job-info small').filter({ hasText: '生成中' }).first().waitFor()
        assert.equal(detail.jobs.at(-1).status, 'queued', 'submitted polling task keeps internal queue scheduling state')
        assert.equal(await page.locator('.job-tile .job-placeholder .is-loading').count(), 1, 'submitted task shows generation spinner')
        await page.getByRole('button', { name: '暂停后续', exact: true }).click()
        await page.getByRole('button', { name: '继续制作', exact: true }).click()
        assert.equal(detail.runs[0].paused, false)
        detail.jobs.forEach(job => { job.status = 'success'; job.result_url = product })
        await refresh()
        await page.locator('.job-tile.success').first().waitFor()
        await screenshot('results')
        let downloadEvent = page.waitForEvent('download')
        await page.getByRole('button', { name: '打包下载', exact: true }).click()
        const zipPath = path.join(output, `${viewport.width}-bundle.zip`)
        await (await downloadEvent).saveAs(zipPath)
        const archive = unzipSync(fs.readFileSync(zipPath))
        assert.equal(Object.keys(archive).length, 1)
        assert(Object.values(archive).every(bytes => bytes.length > 100))
        await page.locator('.job-tile.success').first().getByRole('button', { name: '改图', exact: true }).click()
        await page.getByPlaceholder('描述需要修改的内容').fill('背景更加明亮')
        await page.getByRole('button', { name: '查看修改费用', exact: true }).click()
        await page.getByRole('heading', { name: '确认生成', exact: true }).waitFor()
        await page.getByRole('button', { name: '确认生成', exact: true }).click()
        await waitUntil(() => submitted.length === 2)
        assert.equal(submitted[1].items[0].request.options.size, '2:3')
        assert(submitted[1].items[0].request.images.length >= 3)
        assert.equal(submitted[1].items[0].page_key, '')
        detail.jobs.at(-1).status = 'success'; detail.jobs.at(-1).result_url = alternate
        await refresh()
        await page.waitForFunction(reference => document.querySelector('.job-tile img')?.src === reference, alternate)
        assert.equal(await page.locator('.job-tile').count(), 1, 'edited detail composition becomes its own result')
        const editedJob = detail.jobs.at(-1)
        await page.locator('.job-tile.success').first().getByRole('button', { name: '删除成品', exact: true }).click()
        await waitUntil(() => detail.state.integration.hiddenJobs?.includes(editedJob.id))
        assert.equal(await page.locator('.job-tile').count(), 0, 'deleting the edited detail composition leaves no stale page')
        await page.getByText('成品已删除', { exact: true }).waitFor()
        await refresh()
        assert.equal(await page.locator('.job-tile').count(), 0, 'polling does not restore a deleted result')
        await screenshot('deleted-result')
        await page.getByRole('button', { name: '撤销', exact: true }).click()
        await waitUntil(() => !detail.state.integration.hiddenJobs.includes(editedJob.id))
        await page.waitForFunction(reference => document.querySelector('.job-tile img')?.src === reference, alternate)
        assert.equal(await page.locator('.job-tile').count(), 1, 'undo restores the deleted result')
        await page.locator('.job-tile.success').first().getByRole('button', { name: '删除成品', exact: true }).click()
        await waitUntil(() => detail.state.integration.hiddenJobs.includes(editedJob.id))
        await page.reload()
        await page.locator('.product-dropzone .media-tile').first().waitFor()
        assert.equal(await page.locator('.job-tile').count(), 0, 'deletion persists across page reloads')
        assert.equal(await page.locator('.product-dropzone .media-tile').count(), 4)
        assert.equal(await page.locator('.product-dropzone .media-tile.selected .media-select img').getAttribute('src'), `${product}?upload=4`)
        await selectImageGoal('商品详情图')
        await page.locator('.advanced-trigger').click()
        await page.locator('.selected-style').click()
        await page.locator('.style-gallery button').filter({ hasText: '冷调科技' }).click()
        await page.getByRole('button', { name: '收起配置', exact: true }).click()
        await waitUntil(() => quoted.at(-1)?.items.length === 1 && quoted.at(-1)?.items[0]?.request.original.design?.presetId === 'technology')
        await clickGenerate()
        await page.waitForFunction(() => document.querySelectorAll('.job-tile').length === 1)
        assert.equal(submitted[2].items.length, 1)
        assert.equal(submitted[2].items[0].request.original.design.layout, 'template')
        const detailRun = detail.runs.at(-1).id
        detail.jobs.filter(job => job.run_id === detailRun).forEach(job => { job.status = 'success'; job.result_url = alternate })
        await refresh()
        await page.locator('.job-tile.success').first().waitFor()
        assert.equal(await page.getByRole('button', { name: '详情长图', exact: true }).count(), 0, 'new template details are already complete and need no page stitching')
        detail.jobs.push({ ...clone(detail.jobs[0]), id: 900, run_id: 0, kind: 'model', status: 'unknown', result_url: '', error_message: 'Fixture unconfirmed reference' })
        await refresh()
        await page.locator('.advanced-trigger').click()
        await page.locator('.reference-settings > summary').click()
        assert.equal(await page.getByText('外观族裔', { exact: true }).count(), 0, 'no model attribute questionnaire')
        const referenceJob = page.locator('.advanced-settings .reference-job').first()
        await referenceJob.getByRole('button', { name: '核对原任务', exact: true }).click()
        await referenceJob.getByRole('button', { name: '重新生成', exact: true }).waitFor()
        assert.deepEqual(checked, [900])
        await referenceJob.getByRole('button', { name: '重新生成', exact: true }).click()
        await page.getByRole('heading', { name: '确认生成', exact: true }).waitFor()
        await page.locator('.el-dialog:visible').getByRole('button', { name: '取消', exact: true }).click()
        assert.equal(submitted.length, 3, 'reference retry waits for price confirmation')
        await choose(page, page.getByRole('combobox', { name: '模特', exact: true }), '上传模特')
        await page.getByTestId('reference-upload').setInputFiles(file('model.png'))
        await waitUntil(() => uploads === 5)
        await page.locator('.el-switch').filter({ has: page.getByRole('switch', { name: '使用场景参考', exact: true }) }).click()
        await page.getByRole('button', { name: '上传场景', exact: true }).click()
        await page.getByTestId('reference-upload').setInputFiles(file('scene.png'))
        await waitUntil(() => uploads === 6)
        await page.locator('.output-settings > summary').click()
        await choose(page, page.getByRole('combobox', { name: '图片模型', exact: true }), 'gpt-image-2.5-flare-official')
        await screenshot('settings')
        await page.getByRole('button', { name: '收起配置', exact: true }).click()
        await waitUntil(() => quoted.at(-1)?.items[0]?.model_config_id === 2 && quoted.at(-1)?.items[0]?.request.images.length === 7)
        await page.locator('.creation-summary').getByText(/预计/).waitFor()
        await clickGenerate()
        await waitUntil(() => submitted.length === 4)
        assert(submitted[3].items.every(item => /^\d+x\d+$/.test(item.request.options.size) && !('resolution' in item.request.options)))
        assert.equal(submitted[3].items.length, 1)
        assert.equal(submitted[3].items[0].request.images.length, 7)
        console.log('  single-template details, edits, downloads and inline references')
        await page.locator('.content-mode .el-radio-button').filter({ hasText: '商品视频' }).click()
        await waitUntil(() => briefCalls.at(-1)?.action === 'analyze' && brief.phase === 'materials')
        const motherId = detail.state.model.selectedUploadId
        const ignoredId = detail.state.product.uploads[1].id
        await page.locator('.duration-options .el-radio-button').filter({ hasText: '15 秒' }).click()
        await page.locator('.advanced-trigger').click()
        await page.locator('.output-settings > summary').click()
        await choose(page, page.getByRole('combobox', { name: '画面比例', exact: true }), '竖屏 9:16')
        await page.locator('.video-style-options .el-radio-button').filter({ hasText: '生活种草' }).click()
        assert.equal(await page.locator('.el-drawer').count(), 0)
        await page.getByRole('button', { name: '收起配置', exact: true }).click()
        let composer = page.getByTestId('story-composer')
        await composer.getByRole('button', { name: '口述剧情', exact: true }).click()
        assert.match(await composer.getByRole('textbox', { name: '剧情想法', exact: true }).inputValue(), /女儿提着一桶油给妈妈/)
        await composer.locator(`[data-material="${motherId}"]`).waitFor()
        await clickGenerate()
        await composer.getByRole('combobox', { name: '希望这段视频是什么感觉？', exact: true }).waitFor()
        assert.equal(submitted.length, 4, 'consulting needs never submits a paid generation')
        await choose(page, composer.locator(`[data-material="${motherId}"]`).getByRole('combobox', { name: /人物角色/ }), '妈妈')
        await choose(page, composer.locator(`[data-material="${ignoredId}"]`).getByRole('combobox', { name: /素材用途/ }), '不使用')
        await choose(page, composer.getByRole('combobox', { name: '希望这段视频是什么感觉？', exact: true }), '自然生活感')
        await screenshot('video-questions')
        await clickGenerate()
        await composer.getByTestId('video-proposal').waitFor()
        await page.getByRole('button', { name: '满意，生成视频', exact: true }).waitFor()
        assert.equal(submitted.length, 4, 'proposal needs explicit customer approval')
        assert.equal(await composer.getByRole('textbox', { name: '分镜与对白', exact: true }).isVisible(), false, 'storyboard details stay collapsed')
        await composer.getByRole('textbox', { name: '补充或修改要求', exact: true }).fill('结尾女儿把油放在灶台旁，妈妈笑着点头。')
        await clickGenerate()
        await composer.getByTestId('video-proposal').filter({ hasText: '妈妈笑着点头' }).waitFor()
        assert.equal(brief.answers.tone.answer, '自然生活感', 'revisions retain previously answered questions')
        assert.equal(await composer.getByRole('combobox', { name: '希望这段视频是什么感觉？', exact: true }).count(), 0, 'answered questions are not asked again')
        assert.match(brief.messages.find(message => message.role === 'user').text, /灶台旁/)
        const callsBeforeReload = briefCalls.length
        await screenshot('video-proposal')
        await page.reload()
        composer = page.getByTestId('story-composer')
        await composer.getByTestId('video-proposal').waitFor()
        assert.equal(briefCalls.length, callsBeforeReload, 'reload reads the saved conversation without a paid request')
        assert.equal(await composer.locator(`[data-material="${motherId}"]`).getByRole('combobox', { name: /人物角色/ }).getAttribute('aria-label'), 'model.png人物角色')
        await composer.locator('.brief-history > summary').click()
        await composer.locator('.brief-history').getByText('希望这段视频是什么感觉？：自然生活感', { exact: true }).waitFor()
        await composer.locator('.brief-history > summary').click()
        await clickGenerate()
        await page.getByRole('heading', { name: '确认生成', exact: true }).waitFor()
        assert.equal(briefCalls.at(-1).action, 'confirm')
        assert.equal(submitted.length, 4, 'video still waits for its actual price confirmation')
        await page.getByRole('button', { name: '确认生成', exact: true }).click()
        await waitUntil(() => submitted.length === 5)
        const videoRequest = submitted[4].items[0].request
        assert.equal(videoRequest.options.duration, 15)
        assert.equal(videoRequest.options.aspect_ratio, '9:16')
        assert.equal(videoRequest.original.brief_version, brief.version)
        assert.equal(videoRequest.images[0], `${product}?upload=5`, 'mother portrait is an identity reference')
        assert(!videoRequest.images.includes(`${product}?upload=2`), 'ignored material is excluded from the provider payload')
        assert.equal(videoRequest.images[1], `${product}?upload=4`, 'product reference follows the identity image')
        assert.equal(videoRequest.images.length, 5, 'only the three approved product images, mother and scene are used')
        assert.match(videoRequest.prompt, /角色“妈妈”/)
        assert.match(videoRequest.prompt, /这油多少钱/)
        assert(!JSON.stringify(videoRequest).includes('asset://'))
        assert.equal(await page.getByRole('button', { name: '删除成品', exact: true }).count(), 0, 'in-flight videos are not deleted while their provider state is unresolved')
        const completedVideo = detail.jobs.at(-1)
        completedVideo.status = 'success'; completedVideo.result_url = `${base}/fixture-video.mp4`
        await refresh()
        await page.locator('.job-tile.success').getByRole('button', { name: '删除成品', exact: true }).click()
        await waitUntil(() => detail.state.integration.hiddenJobs.includes(completedVideo.id))
        assert.equal(await page.locator('.job-tile').count(), 0, 'completed videos can be deleted like images')
        await page.getByRole('button', { name: '撤销', exact: true }).click()
        await waitUntil(() => !detail.state.integration.hiddenJobs.includes(completedVideo.id))
        assert.equal(await page.locator('.job-tile').count(), 1, 'deleting the final result leaves undo available')
        await screenshot('video')
        console.log('  video recognition, conversation, approval and references')
        await page.locator('.duration-options .el-radio-button').filter({ hasText: '10 秒' }).click()
        await page.getByRole('button', { name: '完善视频方案', exact: true }).waitFor()
        await clickGenerate()
        await composer.getByTestId('video-proposal').filter({ hasText: '10秒' }).waitFor()
        assert.equal(submitted.length, 5, 'changed duration requires a new proposal before submission')
        const beforeExtra = uploads
        await page.getByTestId('product-upload').setInputFiles(file('extra-product.png'))
        await waitUntil(() => uploads === beforeExtra + 1 && brief.materials.some(media => media.name === 'extra-product.png'))
        assert.equal(briefCalls.at(-1).action, 'analyze')
        assert.equal(brief.answers.tone.answer, '自然生活感')
        assert.equal(brief.roles[motherId], 'c2', 'new image keeps the selected mother role')
        await clickGenerate()
        await composer.getByTestId('video-proposal').waitFor()
        assert.equal(await composer.getByRole('combobox', { name: '希望这段视频是什么感觉？', exact: true }).count(), 0)
        await selectImageGoal('宣传海报')
        await waitUntil(() => quoted.at(-1)?.items[0]?.mode === 'image' && quoted.at(-1)?.items.length === 1)
        failNextSubmit = true
        await clickGenerate()
        await waitUntil(() => submitted.length === 6)
        await page.locator('.storefront > .el-alert').waitFor()
        await clickGenerate()
        await waitUntil(() => submitted.length === 7)
        assert.equal(submitted[5].request_key, submitted[6].request_key, 'network retry keeps its original idempotency key')
        assert.deepEqual(submitted[5].items, submitted[6].items, 'retry uses the same frozen plan')
        assert.equal(detail.runs.length, 6, 'lost response retry creates only one run')
        assert.equal(await page.getByText('检测人脸', { exact: true }).count(), 0)
        await selectImageGoal('模特试穿')
        await choose(page, page.getByRole('combobox', { name: '模特', exact: true }), '自动搭配')
        await page.getByRole('textbox', { name: '制作要求', exact: true }).fill('自然短发，通勤穿搭')
        await waitUntil(() => quoted.at(-1)?.items[0]?.request.original.autoModel === true)
        assert.equal(await page.locator('.creation-fields .model-reference-body').count(), 0, 'try-on adds no model setup panel')
        await screenshot('simple-tryon')
        assert.equal(await page.locator('.model-choice .el-select').count(), 1, 'model uses one dropdown in the left column')
        await clickGenerate(); await waitUntil(() => submitted.length === 8)
        assert.equal(submitted[7].items[0].kind, 'creative', 'try-on generates the finished image directly')
        assert.equal(submitted[7].items[0].request.original.autoModel, true)
        assert.match(submitted[7].items[0].request.prompt, /成年虚拟模特/)
        await selectImageGoal('宣传海报')
        await page.locator('.el-switch').filter({ has: page.getByRole('switch', { name: '加入宣传文案', exact: true }) }).click()
        const beforeCopyUploads = uploads
        await page.getByTestId('product-upload').setInputFiles(file('copy-product.png'))
        await waitUntil(() => copyCalls.length === 1 && uploads === beforeCopyUploads + 1)
        await page.getByRole('textbox', { name: '宣传标题', exact: true }).waitFor()
        await waitUntil(() => detail.state.creative.copy.headline === '日常好物 轻松相伴')
        await page.getByRole('textbox', { name: '宣传标题', exact: true }).fill('把好物带回家')
        await page.getByRole('textbox', { name: '宣传副标题', exact: true }).fill('每一天都有新心意')
        await waitUntil(() => quoted.at(-1)?.items[0]?.request.original.poster?.headline === '把好物带回家')
        await page.getByRole('textbox', { name: '宣传副标题', exact: true }).blur()
        await screenshot('poster-form')
        await clickGenerate(); await waitUntil(() => submitted.length === 9)
        assert.equal(copyCalls.length, 1, 'generation reuses the drafted and edited copy')
        const posterJob = detail.jobs.at(-1)
        assert.equal(posterJob.request.original.poster.headline, '把好物带回家')
        assert(!posterJob.request.prompt.includes('把好物带回家'), 'marketing words are rendered separately')
        posterJob.status = 'success'; posterJob.result_url = product
        await refresh()
        const posterTile = page.locator('.job-tile.success').first()
        await page.waitForFunction(() => document.querySelector('.job-tile.success img')?.src.startsWith('data:image/png'))
        await screenshot('poster-result')
        await posterTile.getByRole('button', { name: '改文案', exact: true }).click()
        await page.getByRole('textbox', { name: '成品标题', exact: true }).fill('好物相伴 用心生活')
        await screenshot('poster-edit')
        await page.getByRole('button', { name: '保存文案', exact: true }).click()
        await waitUntil(() => detail.state.integration.posterOverrides?.[posterJob.id]?.headline === '好物相伴 用心生活')
        assert.equal(submitted.length, 9, 'editing words makes no image generation request')
        downloadEvent = page.waitForEvent('download')
        await posterTile.getByRole('button', { name: '下载', exact: true }).click()
        const posterPath = path.join(output, `${viewport.width}-poster.png`)
        await (await downloadEvent).saveAs(posterPath)
        const posterData = fs.readFileSync(posterPath)
        const posterPixels = await page.evaluate(async src => {
          const image = new Image(); image.src = src; await image.decode()
          const canvas = document.createElement('canvas'); canvas.width = image.naturalWidth; canvas.height = image.naturalHeight
          const context = canvas.getContext('2d'); context.drawImage(image, 0, 0)
          const header = context.getImageData(0, 0, canvas.width, Math.floor(canvas.height * .2)).data
          const colors = new Set(); for (let offset = 0; offset < header.length; offset += 4) colors.add(`${header[offset]},${header[offset + 1]},${header[offset + 2]}`)
          return { width: canvas.width, height: canvas.height, headerColors: colors.size }
        }, `data:image/png;base64,${posterData.toString('base64')}`)
        assert(posterPixels.width >= 640 && posterPixels.height > 0 && posterPixels.headerColors > 10, 'export contains rendered header text')
        downloadEvent = page.waitForEvent('download')
        await page.getByRole('button', { name: '打包下载', exact: true }).click()
        const posterZip = path.join(output, `${viewport.width}-poster.zip`)
        await (await downloadEvent).saveAs(posterZip)
        const zippedPoster = Buffer.from(Object.values(unzipSync(fs.readFileSync(posterZip)))[0])
        const samePoster = await page.evaluate(async sources => {
          const pixels = await Promise.all(sources.map(async src => {
            const image = new Image(); image.src = src; await image.decode()
            const canvas = document.createElement('canvas'); canvas.width = image.width; canvas.height = image.height
            const context = canvas.getContext('2d'); context.drawImage(image, 0, 0)
            return context.getImageData(0, 0, canvas.width, canvas.height)
          }))
          if (pixels[0].width !== pixels[1].width || pixels[0].height !== pixels[1].height) return false
          let different = 0
          for (let i = 0; i < pixels[0].data.length; i += 4) if ([0, 1, 2, 3].some(channel => pixels[0].data[i + channel] !== pixels[1].data[i + channel])) different++
          // Repeated canvas renders can differ by a few rasterized edge pixels.
          return different / (pixels[0].width * pixels[0].height) < .00001
        }, [zippedPoster, posterData].map(buffer => `data:image/png;base64,${buffer.toString('base64')}`))
        assert(samePoster, 'ZIP contains the composed poster with the same text and photo')
        await posterTile.getByRole('button', { name: '保存至资产', exact: true }).click()
        await waitUntil(() => savedAssets.length === 1 && detail.state.integration.savedPosters?.[posterJob.id])
        assert(savedAssets[0].image_url.includes('?upload='), 'save-to-assets receives the uploaded composition')
        await page.reload()
        await page.waitForFunction(() => document.querySelector('.job-tile.success img')?.src.startsWith('data:image/png'))
        assert.equal(copyCalls.length, 1, 'reload does not redraft or charge for text')
        await page.locator('.job-tile.success').first().getByRole('button', { name: '改文案', exact: true }).click()
        assert.equal(await page.getByRole('textbox', { name: '成品标题', exact: true }).inputValue(), '好物相伴 用心生活')
        await page.getByRole('button', { name: '去掉文字', exact: true }).click()
        await waitUntil(() => Object.hasOwn(detail.state.integration.posterOverrides, posterJob.id) && detail.state.integration.posterOverrides[posterJob.id] === null)
        assert.equal(submitted.length, 9)
        assert(!await page.evaluate(() => document.documentElement.scrollWidth > window.innerWidth), 'page has no horizontal overflow')
        assert.deepEqual(errors, [])
        console.log(`PASS ${viewport.width}px: inline settings, single model dropdown, image recognition, role assignment, retained answers, proposal approval and billing gate, reference separation, duration changes, upload/drop, campaigns, editable posters, downloads, Official parameters and idempotent retry`)
      } catch (error) { await screenshot('failure'); throw error }
      finally { await page.close() }
    }
  } finally { await browser.close() }
  console.log(`Screenshots: ${output}`)
}
main().catch(error => { console.error(error); process.exitCode = 1 })
