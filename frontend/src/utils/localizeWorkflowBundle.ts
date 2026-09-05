import { t } from '@/i18n'
import type { WorkflowBundle, WorkflowBrief, WorkflowGraph } from '@/types'

function translateText(value: unknown): string {
  const text = String(value ?? '')
  if (!text.trim()) return text
  return t(text)
}

function localizeGraph(graph: WorkflowGraph): WorkflowGraph {
  return {
    ...graph,
    nodes: (graph.nodes ?? []).map((node) => {
      const data = (node.data && typeof node.data === 'object')
        ? { ...(node.data as Record<string, unknown>) }
        : {}
      if (typeof data.label === 'string') data.label = translateText(data.label)
      if (typeof data.desc === 'string') data.desc = translateText(data.desc)
      return {
        ...node,
        label: translateText(node.label),
        data,
      }
    }),
  }
}

function localizeWorkflow(workflow: WorkflowBrief | null): WorkflowBrief | null {
  if (!workflow) return workflow
  return {
    ...workflow,
    graph: localizeGraph(workflow.graph),
  }
}

export function localizeWorkflowBundle(bundle: WorkflowBundle): WorkflowBundle {
  return {
    ...bundle,
    description: translateText(bundle.description),
    series_workflow: localizeWorkflow(bundle.series_workflow),
    episode_workflow: localizeWorkflow(bundle.episode_workflow),
  }
}
