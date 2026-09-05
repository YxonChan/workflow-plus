import { request } from './http'
import type { SilentableRequestConfig } from './http'

export type WorkerState = 'working' | 'queued' | 'alert' | 'idle'

/** 员工最近一条任务摘要（覆盖 success/failed/进行中）。 */
export interface WorkerRecentItem {
  title: string
  status: string
  time: string
}

export interface DigitalWorker {
  key: 'assistant' | 'producer' | 'asset' | 'video'
  name: string
  link: string
  state: WorkerState
  queued: number
  running: number
  failed_today: number
  done_today: number
  current: string
  recent: WorkerRecentItem[]
}

export interface WorkerStatusPayload {
  workers: DigitalWorker[]
  generated_at: string
}

/** 数字员工可执行的一键动作类型。 */
export type WorkerActionType = 'retry_failed' | 'cancel_queued'

export interface WorkerActionResult {
  worker: string
  action: WorkerActionType
  affected: number
  workers: DigitalWorker[]
}

/** 数字员工状态轮询（silent：失败不弹全局错误提示）。 */
export function fetchWorkerStatus(): Promise<WorkerStatusPayload> {
  const config: SilentableRequestConfig = {
    method: 'POST',
    url: '/api/workers/status',
    silent: true,
  }
  return request<WorkerStatusPayload>(config)
}

/** 数字员工一键动作（重试失败/取消排队），返回受影响条数与刷新后的员工状态。 */
export function runWorkerAction(
  worker: string,
  action: WorkerActionType,
): Promise<WorkerActionResult> {
  return request<WorkerActionResult>({
    method: 'POST',
    url: '/api/workers/action',
    data: { worker, action },
  })
}
