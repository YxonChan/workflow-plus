import { readFileSync, readdirSync } from 'node:fs'
import { extname, join, relative } from 'node:path'
import { baseCompile } from '@intlify/message-compiler'
import ts from 'typescript'

const root = process.cwd()
const sourceRoot = join(root, 'src')
const localeRoot = join(sourceRoot, 'i18n', 'locales')

function collectSourceFiles(directory) {
  return readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const path = join(directory, entry.name)
    if (entry.isDirectory()) return collectSourceFiles(path)
    return ['.vue', '.ts'].includes(extname(entry.name)) ? [path] : []
  })
}

function localeEntries(path) {
  const content = readFileSync(path, 'utf8')
  const source = ts.createSourceFile(path, content, ts.ScriptTarget.Latest, true, ts.ScriptKind.TS)
  const entries = []

  function visit(node) {
    if (
      ts.isPropertyAssignment(node)
      && ts.isStringLiteralLike(node.name)
      && ts.isStringLiteralLike(node.initializer)
    ) {
      const position = source.getLineAndCharacterOfPosition(node.getStart(source))
      entries.push({
        key: node.name.text,
        value: node.initializer.text,
        line: position.line + 1,
      })
    }
    ts.forEachChild(node, visit)
  }

  visit(source)

  return entries
}

const files = collectSourceFiles(sourceRoot)
  .filter((file) => !file.startsWith(localeRoot))
const zhPath = join(localeRoot, 'zh-CN.ts')
const zhEntries = localeEntries(zhPath)
const zhKeys = new Set(zhEntries.map((entry) => entry.key))

const allowed = [
  /\/\/.*[\u4e00-\u9fff]/,
  /\/\*[\s\S]*?[\u4e00-\u9fff][\s\S]*?\*\//,
  /<!--.*[\u4e00-\u9fff].*-->/,
  /console\./,
  /[\u4e00-\u9fff].*prompt/i,
]

let failed = false
for (const file of files) {
  const content = readFileSync(file, 'utf8')
  const lines = content.split(/\r?\n/)
  const template = extname(file) === '.vue'
    ? content.match(/<template>([\s\S]*?)<\/template>/)?.[1] ?? ''
    : ''
  lines.forEach((line, index) => {
    if (!template || !template.includes(line)) return
    if (!/[\u4e00-\u9fff]/.test(line)) return
    if (line.includes("t('") || line.includes('t("') || line.includes('{{ t(') || line.includes(':label="t(') || line.includes(':title="t(')) return
    if (allowed.some((rule) => rule.test(line.trim()))) return
    failed = true
    console.log(`${relative(root, file)}:${index + 1}: ${line.trim()}`)
  })

  for (const match of content.matchAll(/(?<![\w.])t\(\s*(['"])((?:\\.|(?!\1).)*)\1/g)) {
    const key = match[2]
    const line = content.slice(0, match.index).split(/\r?\n/).length
    if (!zhKeys.has(key)) {
      try {
        baseCompile(key)
      } catch (error) {
        failed = true
        console.log(`${relative(root, file)}:${line}: zh-CN fallback key has invalid message syntax (${error.code ?? 'unknown'}): ${key}`)
      }
    }
  }
}

for (const [path, entries] of [[zhPath, zhEntries]]) {
  for (const entry of entries) {
    try {
      baseCompile(entry.value)
    } catch (error) {
      failed = true
      console.log(`${relative(root, path)}:${entry.line}: invalid locale message syntax (${error.code ?? 'unknown'}): ${entry.key}`)
    }
  }
}

if (failed) {
  console.error('\ni18n static checks failed.')
  process.exit(1)
}

console.log(`i18n static checks passed for ${files.length} source files.`)
