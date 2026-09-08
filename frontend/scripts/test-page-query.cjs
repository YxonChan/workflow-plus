const assert = require('node:assert/strict')
const { test } = require('node:test')
const fs = require('node:fs')
const path = require('node:path')
const Module = require('node:module')
const ts = require('typescript')
const { effectScope, createSSRApp, h } = require('vue')
const { renderToString } = require('vue/server-renderer')
const { createRouter, createMemoryHistory } = require('vue-router')

const filename = path.resolve(__dirname, '../src/utils/pageQuery.ts')
const compiled = new Module(filename, module)
compiled.filename = filename
compiled.paths = module.paths
compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020 },
}).outputText, filename)
const { bindPageQuery, queryId, queryChoice, updatePageQuery, usePageQueryRecord } = compiled.exports

async function routerAt(url) {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [{ path: '/:pathMatch(.*)*', component: {} }],
  })
  await router.push(url)
  return router
}

async function settle() {
  for (let i = 0; i < 5; i++) await new Promise(setImmediate)
}

function bind(router, key, fallback = null, decode = queryId) {
  const scope = effectScope()
  return { scope, state: scope.run(() => bindPageQuery(router, key, fallback, decode)) }
}

test('series and episode survive a new router and component instance', async () => {
  const router = await routerAt('/series?bundle=7#details')
  const series = bind(router, 'series_id')
  const episode = bind(router, 'episode_id')
  series.state.value = 42
  episode.state.value = 9
  await settle()
  assert.deepEqual(router.currentRoute.value.query, { bundle: '7', series_id: '42', episode_id: '9' })
  assert.equal(router.currentRoute.value.hash, '#details')
  series.scope.stop()
  episode.scope.stop()
  const restoredRouter = await routerAt(router.currentRoute.value.fullPath)
  const restored = bind(restoredRouter, 'series_id')
  assert.equal(restored.state.value, 42)
  restored.scope.stop()
})

test('rapid selection followed by return to gallery does not reopen the work', async () => {
  const router = await routerAt('/series')
  const series = bind(router, 'series_id')
  series.state.value = 42
  series.state.value = null
  await settle()
  assert.equal(router.currentRoute.value.query.series_id, undefined)
  assert.equal(series.state.value, null)
  series.scope.stop()
})

test('back and forward restore the selection without rewriting history', async () => {
  const router = await routerAt('/series?series_id=1')
  const series = bind(router, 'series_id')
  await router.push('/series?series_id=2')
  assert.equal(series.state.value, 2)
  router.back()
  await settle()
  assert.equal(series.state.value, 1)
  router.forward()
  await settle()
  assert.equal(series.state.value, 2)
  series.scope.stop()
})

test('asset filters and editor id restore independently', async () => {
  const router = await routerAt('/assets?series_id=42&tab=shared&type=scene&q=room&asset_id=19')
  const bindings = [
    bind(router, 'series_id'),
    bind(router, 'tab', 'mine', queryChoice(['mine', 'shared'], 'mine')),
    bind(router, 'type', 'all', queryChoice(['all', 'character', 'scene', 'prop'], 'all')),
    bind(router, 'q', '', (value) => value),
    bind(router, 'asset_id'),
  ]
  assert.deepEqual(bindings.map(({ state }) => state.value), [42, 'shared', 'scene', 'room', 19])
  bindings[4].state.value = null
  await settle()
  assert.equal(router.currentRoute.value.query.asset_id, undefined)
  assert.equal(router.currentRoute.value.query.series_id, '42')
  bindings.forEach(({ scope }) => scope.stop())
})

test('malformed, repeated and unsafe ids fall back to the list', async () => {
  for (const value of ['-1', '0', '1.2', 'NaN', '9007199254740992', '1&series_id=2']) {
    const router = await routerAt(`/series?series_id=${value}`)
    const series = bind(router, 'series_id')
    assert.equal(series.state.value, null)
    series.scope.stop()
  }
})

test('new changes during an in-flight query update are retained', async () => {
  const router = await routerAt('/assets')
  let release
  router.beforeEach(() => new Promise((resolve) => { release = resolve }))
  const series = bind(router, 'series_id')
  const keyword = bind(router, 'q', '', (value) => value)
  series.state.value = 1
  await settle()
  keyword.state.value = 'room'
  series.state.value = 2
  release()
  await settle()
  release()
  await settle()
  assert.equal(router.currentRoute.value.query.series_id, '2')
  assert.equal(router.currentRoute.value.query.q, 'room')
  assert.equal(series.state.value, 2)
  series.scope.stop()
  keyword.scope.stop()
})

test('leaving the page cannot leak its selection into the next route', async () => {
  const router = await routerAt('/series?series_id=1')
  const series = bind(router, 'series_id')
  await router.push('/assets')
  series.state.value = 2
  await settle()
  assert.equal(router.currentRoute.value.fullPath, '/assets')
  series.scope.stop()
})

test('return to gallery clears only the owned query parameters', async () => {
  const router = await routerAt('/series?series_id=1&episode_id=2&bundle=3')
  updatePageQuery(router, { series_id: undefined, episode_id: undefined })
  await settle()
  assert.deepEqual(router.currentRoute.value.query, { bundle: '3' })
})

test('workflow and script detail locations restore after refresh', async () => {
  for (const [url, keys, expected] of [
    ['/workflow?bundle_id=12&draft_from=3', ['bundle_id', 'draft_from'], [12, 3]],
    ['/script-creation?project_id=5&step_id=8', ['project_id', 'step_id'], [5, 8]],
  ]) {
    const router = await routerAt(url)
    const bindings = keys.map((key) => bind(router, key))
    assert.deepEqual(bindings.map(({ state }) => state.value), expected)
    bindings.forEach(({ scope }) => scope.stop())
  }
})

test('expanded nodes and shot selection deserialize and reject malformed records', async () => {
  for (const [raw, expected] of [
    ['{"node-1":true,"node-2":false,"bad":"true"}', { 'node-1': true, 'node-2': false }],
    ['not-json', {}],
    ['null', {}],
    ['[]', {}],
  ]) {
    const router = await routerAt(`/series?nodes=${encodeURIComponent(raw)}&shots=${encodeURIComponent('{"node-1":"shot-3"}')}`)
    let nodes, shots
    const app = createSSRApp({ setup() {
      nodes = usePageQueryRecord('nodes', 'boolean')
      shots = usePageQueryRecord('shots', 'string')
      return () => h('div')
    } })
    app.use(router)
    await renderToString(app)
    assert.deepEqual(nodes.value, expected)
    assert.deepEqual(shots.value, { 'node-1': 'shot-3' })
  }
})
