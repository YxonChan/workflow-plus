import { request } from './http'
import type { SilentableRequestConfig } from './http'

export interface AgentAction {
  tool: string
  label: string
  ok: boolean
}

export interface AgentChatResult {
  worker: string
  reply: string
  actions: AgentAction[]
}

export interface AgentHistoryItem {
  role: 'user' | 'assistant'
  content: string
}

export interface AgentPageContext {
  page?: string
  series_id?: number
  series_title?: string
  episode_id?: number
  episode_number?: number
  episode_title?: string
  workflow_run_id?: number
}

/**
 * 数字员工对话（silent：失败不弹全局提示，由聊天气泡展示错误）。
 * Agent 可能要串多轮工具调用，超时放宽到 3 分钟。
 */
export function chatWithWorker(
  worker: string,
  message: string,
  history: AgentHistoryItem[],
  context: AgentPageContext = {},
): Promise<AgentChatResult> {
  const config: SilentableRequestConfig = {
    method: 'POST',
    url: '/api/workers/agent-chat',
    data: { worker, message, history, context },
    timeout: 180_000,
    silent: true,
  }
  return request<AgentChatResult>(config)
}
