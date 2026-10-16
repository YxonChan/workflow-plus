const assert = require('node:assert/strict')
const { test } = require('node:test')
const fs = require('node:fs')
const path = require('node:path')
const Module = require('node:module')
const ts = require('typescript')

const filename = path.resolve(__dirname, '../src/utils/stylePresetCatalog.ts')
const compiled = new Module(filename, module)
compiled.filename = filename
compiled.paths = module.paths
compiled._compile(ts.transpileModule(fs.readFileSync(filename, 'utf8'), {
  compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2020, esModuleInterop: true },
}).outputText, filename)
const catalog = compiled.exports
const cases = require('../../scripts/fixtures/style_parameter_cases.json')

for (const fixture of cases) {
  test(fixture.name, () => {
    const normalized = catalog.normalizeStyleParameters(fixture.raw)
    assert.equal(catalog.styleParameterIssues(normalized, fixture.style).length === 0, fixture.valid)
    for (const [key, values] of Object.entries(fixture.contains || {})) assert.deepEqual(normalized[key], values)
    assert.deepEqual(catalog.normalizeStyleParameters(normalized), normalized, 'normalization is idempotent')
    if (fixture.valid) {
      const tone = catalog.composeStylePrompt(normalized, fixture.style)
      const technique = catalog.composeStylePrompt(normalized, fixture.style, 'technique')
      assert.equal(tone.includes('画法采用'), false)
      assert.equal(technique.includes('整体主色调'), false)
    }
  })
}

test('contrasting moods warn without blocking', () => {
  const parameters = catalog.normalizeStyleParameters({ mood: ['joyful', 'melancholic'] })
  assert.deepEqual(catalog.styleMoodWarnings(parameters), [['joyful', 'melancholic']])
})

test('valid prompt follows parameter updates', () => {
  const parameters = catalog.normalizeStyleParameters({ contrast: ['High Contrast Hard'] })
  const before = catalog.composeStylePrompt(parameters, 'realistic')
  parameters.contrast = ['Low Contrast Flat']
  const after = catalog.composeStylePrompt(parameters, 'realistic')
  assert.notEqual(after, before)
  assert.equal(after.includes('High Contrast Hard'), false)
  assert.equal(after.includes('Low Contrast Flat'), true)
})
