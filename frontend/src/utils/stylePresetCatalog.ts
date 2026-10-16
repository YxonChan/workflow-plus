import rules from './styleParameterRules.json'
import type { VisualStyle } from './visualStyle'

export interface StyleParameterOption {
  value: string
  label: string
  styles?: string[]
}

export interface StyleParameterGroup {
  key: string
  label: string
  section: string
  scope?: string
  styles?: string[]
  requires?: { key: string; value: string }
  max: number
  prefix: string
  options: StyleParameterOption[]
}

export const STYLE_PARAMETER_GROUPS: StyleParameterGroup[] = rules.groups
export const STYLE_PARAMETER_KEYS = STYLE_PARAMETER_GROUPS.map((group) => group.key)
export type StyleParameters = Record<string, string[]>

export function emptyStyleParameters(): StyleParameters {
  return Object.assign(Object.create(null), Object.fromEntries(STYLE_PARAMETER_KEYS.map((key) => [key, []])))
}

// Keep custom legacy values and conflicting selections visible until the user resolves them.
export function normalizeStyleParameters(value: unknown): StyleParameters {
  const source = value && typeof value === 'object' ? value as Record<string, unknown> : {}
  const next = emptyStyleParameters()
  for (const [key, raw] of Object.entries(source)) {
    const items = Array.isArray(raw) ? raw : typeof raw === 'string' ? raw.split(/[,，、]/u) : []
    for (const item of items) {
      if (typeof item !== 'string') continue
      const text = item.trim()
      if (!text) continue
      if (key === 'medium' && text === '35mm Anamorphic') {
        for (const [targetKey, value] of [['medium', 'Film'], ['format', '35mm film'], ['lens', 'Anamorphic 2x Squeeze']]) {
          if (!next[targetKey].includes(value)) next[targetKey].push(value)
        }
        continue
      }
      const aliased = (rules.aliases as Record<string, string>)[text] || text
      const current = STYLE_PARAMETER_GROUPS.find((group) => group.key === key)
      const target = current?.options.some((option) => option.value === aliased)
        ? current
        : STYLE_PARAMETER_GROUPS.find((group) => group.options.some((option) => option.value === aliased))
      const legacyTargets = rules.legacyTargets as Record<string, Record<string, string>>
      const targetKey = legacyTargets[key]?.[aliased] || target?.key || key
      next[targetKey] ??= []
      if (!next[targetKey].includes(aliased)) next[targetKey].push(aliased)
      if (key === 'medium' && ['film_stock', 'format'].includes(targetKey) && !next.medium.includes('Film')) {
        next.medium.push('Film')
      }
    }
  }
  return next
}

export function parameterOptions(group: StyleParameterGroup, style: VisualStyle): StyleParameterOption[] {
  return group.options.filter((option) => !option.styles || option.styles.includes(style))
}

export function parameterApplies(group: StyleParameterGroup, style: VisualStyle): boolean {
  return !group.styles || group.styles.includes(style)
}

export function styleParameterIssues(parameters: StyleParameters, style: VisualStyle): string[] {
  const issues: string[] = []
  for (const [key, values] of Object.entries(parameters)) {
    if (!values.length) continue
    const group = STYLE_PARAMETER_GROUPS.find((item) => item.key === key)
    if (!group) { issues.push(key); continue }
    if (!parameterApplies(group, style) || values.length > group.max
      || values.some((value) => !parameterOptions(group, style).some((option) => option.value === value))
      || (group.requires && !parameters[group.requires.key]?.includes(group.requires.value))) {
      issues.push(group.label)
    }
  }
  for (const pair of rules.hardConflicts) {
    if (pair.every((item) => parameters[item.key]?.includes(item.value))) {
      issues.push(pair.map((item) => STYLE_PARAMETER_GROUPS.find((group) => group.key === item.key)!.label).join(' / '))
    }
  }
  return [...new Set(issues)]
}

export function styleMoodWarnings(parameters: StyleParameters): string[][] {
  return rules.softConflicts.filter((pair) => pair.every((value) => parameters.mood.includes(value)))
}

export function composeStylePrompt(parameters: StyleParameters, style: VisualStyle, scope = 'tone'): string {
  const parts = STYLE_PARAMETER_GROUPS
    .filter((group) => parameterApplies(group, style) && (group.scope || 'tone') === scope)
    .filter((group) => !group.requires || parameters[group.requires.key]?.includes(group.requires.value))
    .flatMap((group) => {
      const options = parameterOptions(group, style)
      const values = (parameters[group.key] || []).filter((value) => options.some((option) => option.value === value))
      return values.length ? [`${group.prefix}${values.join('、')}`] : []
    })
  if (parts.length && scope === 'tone') parts.push('具体光源、时段、焦距、构图与运镜按场景和分镜决定')
  return parts.length ? `${parts.join('。')}。` : ''
}
