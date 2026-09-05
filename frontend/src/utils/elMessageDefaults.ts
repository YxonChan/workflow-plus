import { ElMessage } from 'element-plus'
import type { MessageHandler, MessageParams } from 'element-plus'

/**
 * 全局 ElMessage：错误不自动消失，一律带关闭按钮。
 * 必须在业务模块弹出提示之前加载。
 */
function wrap(
  original: (options?: MessageParams) => MessageHandler,
  duration: number,
): (options?: MessageParams) => MessageHandler {
  return (options?: MessageParams) => {
    const normalized =
      typeof options === 'string' || typeof options === 'number' || options == null
        ? { message: options ?? '' }
        : { ...options }

    return original({
      showClose: true,
      grouping: true,
      duration,
      ...normalized,
    })
  }
}

ElMessage.error = wrap(ElMessage.error.bind(ElMessage), 0)
ElMessage.warning = wrap(ElMessage.warning.bind(ElMessage), 8000)
ElMessage.success = wrap(ElMessage.success.bind(ElMessage), 3000)
ElMessage.info = wrap(ElMessage.info.bind(ElMessage), 4000)
