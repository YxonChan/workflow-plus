import type { WorkflowGraph, WorkflowScope } from '@/types'

// ── Step model (linear editor view over the graph data) ────────────────────────
export type NodeKind = 'input' | 'text' | 'image' | 'video' | 'voice' | 'output'

export interface WorkflowStep {
  uid: string
  kind: NodeKind
  label: string
  icon: string
  desc: string
  isCustom: boolean
  params: Record<string, any>
  /** 原节点 id（从已有 graph 载入时保留，保存时复用以稳定 id） */
  nodeId?: string
}

/** 各 scope 允许的节点类型（与原 WorkflowView.allowedKindsByScope 一致） */
export const allowedKindsByScope: Record<WorkflowScope, NodeKind[]> = {
  episode: ['input', 'text', 'image', 'video', 'voice', 'output'],
  series: ['input', 'text', 'output'],
}

const NODE_SPACING = 280
let uidSeq = 0
function makeUid(prefix = 'step'): string {
  uidSeq += 1
  return `${prefix}-${Date.now().toString(36)}-${uidSeq}`
}

/** 按 kind/scope 生成默认 params（与原 ensureNodeDataParams/onDrop 默认值一致） */
export function createDefaultParams(kind: NodeKind, scope: WorkflowScope): Record<string, any> {
  if (kind === 'input') {
    return { prompt: '', content: '' }
  }
  if (kind === 'output') {
    return scope === 'series'
      ? { prompt: '', target: 'series' }
      : { prompt: '', format: 'mp4', resolution: '1920x1080' }
  }
  if (kind === 'video') {
    return {
      prompt: '',
      video_style_prompt: '',
      modelId: null,
      duration: 0,
      aspect_ratio: '16:9',
      resolution: '480p',
      chainShots: true,
      generate_audio: true,
      enable_web_search: false,
    }
  }
  // text / image / voice
  return { prompt: '', modelId: null, executionMode: 'ai' }
}

/** 补齐缺失的默认 params（载入旧数据时用），不覆盖已有值 */
function ensureParams(kind: NodeKind, scope: WorkflowScope, params: Record<string, any>): Record<string, any> {
  const defaults = createDefaultParams(kind, scope)
  const merged: Record<string, any> = { ...defaults, ...params }
  // video 的旧数据可能缺字段
  if (kind === 'video') {
    if (merged.duration === undefined || merged.duration === '') merged.duration = 0
    if (!merged.aspect_ratio) merged.aspect_ratio = '16:9'
    if (!merged.resolution) merged.resolution = '480p'
    if (merged.video_style_prompt === undefined) merged.video_style_prompt = ''
    if (merged.chainShots === undefined) merged.chainShots = true
    if (merged.generate_audio === undefined) merged.generate_audio = true
    if (merged.enable_web_search === undefined) merged.enable_web_search = false
  }
  if (kind === 'text' && !merged.executionMode) merged.executionMode = 'ai'
  return merged
}

function kindOf(node: Record<string, unknown>): NodeKind {
  const data = (node.data ?? {}) as Record<string, unknown>
  const k = String(data.kind ?? 'text')
  return (['input', 'text', 'image', 'video', 'voice', 'output'].includes(k) ? k : 'text') as NodeKind
}

function sortByX(nodes: Array<Record<string, unknown>>): Array<Record<string, unknown>> {
  return [...nodes].sort((a, b) => {
    const ax = Number((a.position as { x?: number } | undefined)?.x ?? 0)
    const bx = Number((b.position as { x?: number } | undefined)?.x ?? 0)
    return ax - bx
  })
}

/**
 * graph → 线性步骤。优先沿边链排序（从 input/无入度节点出发），
 * 断链或孤立节点按 position.x 兜底追加，保证所有节点被覆盖一次。
 */
export function graphToSteps(graph: WorkflowGraph | null | undefined, scope: WorkflowScope): WorkflowStep[] {
  const nodes = (graph?.nodes ?? []) as Array<Record<string, unknown>>
  if (nodes.length === 0) return []

  const byId = new Map<string, Record<string, unknown>>(nodes.map((n) => [String(n.id), n]))
  const edges = (graph?.edges ?? []) as Array<Record<string, unknown>>
  const next = new Map<string, string>()
  const hasIncoming = new Set<string>()
  for (const e of edges) {
    const s = String(e.source ?? '')
    const t = String(e.target ?? '')
    if (!byId.has(s) || !byId.has(t)) continue
    if (!next.has(s)) next.set(s, t)
    hasIncoming.add(t)
  }

  const startNode =
    nodes.find((n) => kindOf(n) === 'input' && !hasIncoming.has(String(n.id)))
    ?? nodes.find((n) => !hasIncoming.has(String(n.id)))
    ?? sortByX(nodes)[0]

  const ordered: Array<Record<string, unknown>> = []
  const visited = new Set<string>()
  let cur: string | undefined = startNode ? String(startNode.id) : undefined
  while (cur && byId.has(cur) && !visited.has(cur)) {
    visited.add(cur)
    ordered.push(byId.get(cur)!)
    cur = next.get(cur)
  }
  for (const n of sortByX(nodes)) {
    const id = String(n.id)
    if (!visited.has(id)) {
      visited.add(id)
      ordered.push(n)
    }
  }

  return ordered.map((node) => {
    const data = (node.data ?? {}) as Record<string, unknown>
    const kind = kindOf(node)
    const rawParams = (data.params && typeof data.params === 'object')
      ? (data.params as Record<string, any>)
      : {}
    return {
      uid: makeUid(),
      kind,
      label: String(node.label ?? '').trim() || defaultLabelForKind(kind),
      icon: String(data.icon ?? defaultIconForKind(kind)),
      desc: String(data.desc ?? ''),
      isCustom: Boolean(data.isCustom),
      params: ensureParams(kind, scope, { ...rawParams }),
      nodeId: String(node.id),
    }
  })
}

/** 线性步骤 → graph：x=序号×280，顺序边串联，结构恒满足校验。 */
export function stepsToGraph(steps: WorkflowStep[]): WorkflowGraph {
  const nodes = steps.map((s, i) => ({
    id: s.nodeId || `${s.kind}-${makeUid('n')}`,
    type: 'studio',
    position: { x: i * NODE_SPACING, y: 200 },
    label: s.label,
    data: {
      icon: s.icon,
      desc: s.desc,
      kind: s.kind,
      isCustom: s.isCustom,
      params: s.params,
    },
  }))

  const edges: Array<Record<string, unknown>> = []
  for (let i = 0; i < nodes.length - 1; i += 1) {
    edges.push({
      id: `e-${nodes[i].id}-${nodes[i + 1].id}`,
      source: nodes[i].id,
      target: nodes[i + 1].id,
      animated: true,
      markerEnd: 'arrowclosed',
    })
  }

  return { nodes, edges }
}

export function defaultLabelForKind(kind: NodeKind): string {
  return ({
    input: '输入',
    text: '文本处理',
    image: '图片生成',
    video: '视频生成',
    voice: '语音生成',
    output: '输出',
  } as Record<NodeKind, string>)[kind]
}

export function defaultIconForKind(kind: NodeKind): string {
  return ({
    input: 'EditPen',
    text: 'MagicStick',
    image: 'Picture',
    video: 'VideoCamera',
    voice: 'Microphone',
    output: 'Download',
  } as Record<NodeKind, string>)[kind]
}

export function kindLabel(kind: NodeKind): string {
  return ({
    input: '输入',
    text: '文本',
    image: '图片',
    video: '视频',
    voice: '语音',
    output: '输出',
  } as Record<NodeKind, string>)[kind] ?? ''
}

/** 新建一个步骤（用于编辑器"插入一步"） */
export function newStep(
  partial: { kind: NodeKind; label: string; icon?: string; desc?: string; isCustom?: boolean },
  scope: WorkflowScope,
): WorkflowStep {
  return {
    uid: makeUid(),
    kind: partial.kind,
    label: partial.label,
    icon: partial.icon || defaultIconForKind(partial.kind),
    desc: partial.desc || '',
    isCustom: partial.isCustom ?? false,
    params: createDefaultParams(partial.kind, scope),
  }
}

/** 能向下游提供分镜内容的步骤类型；图片/视频步骤的前一步必须是其中之一（与后端 WorkflowValidate 一致） */
const SHOT_PRODUCER_KINDS = new Set<NodeKind>(['text', 'image', 'video'])

/**
 * 保存前校验步骤结构能否闭环执行。
 * 返回错误文案；合法时返回 null。规则与后端 WorkflowValidate::checkGraphTopology 保持一致，
 * 让用户在前端就能看到具体哪一步有问题，而不是保存时收到 422。
 */
export function validateSteps(steps: WorkflowStep[], scope: WorkflowScope): string | null {
  if (steps.length === 0) {
    return '流程不能为空，请先添加步骤'
  }

  const inputCount = steps.filter((s) => s.kind === 'input').length
  const outputCount = steps.filter((s) => s.kind === 'output').length
  if (inputCount !== 1 || outputCount !== 1) {
    return '流程必须有且仅有 1 个输入步骤和 1 个输出步骤'
  }
  if (steps[0].kind !== 'input') {
    return '第一步必须是输入步骤'
  }
  if (steps[steps.length - 1].kind !== 'output') {
    return '最后一步必须是输出步骤'
  }
  if (steps.length < 3) {
    return '输入和输出之间至少需要一个处理步骤（文本/图片/视频）'
  }

  const allowed = new Set(allowedKindsByScope[scope])
  for (const s of steps) {
    if (!allowed.has(s.kind)) {
      return scope === 'series'
        ? `剧本段只支持输入、文本、输出步骤，请移除「${s.label}」`
        : `不支持的步骤类型：「${s.label}」`
    }
  }

  for (let i = 1; i < steps.length; i += 1) {
    const s = steps[i]
    if (s.kind !== 'image' && s.kind !== 'video') continue
    const prev = steps[i - 1]
    if (!SHOT_PRODUCER_KINDS.has(prev.kind)) {
      const what = s.kind === 'image' ? '图片' : '视频'
      return `${what}步骤「${s.label}」前面必须是分镜（文本）或图片步骤，不能直接接在「${prev.label}」（${kindLabel(prev.kind)}）后面`
    }
  }

  return null
}
