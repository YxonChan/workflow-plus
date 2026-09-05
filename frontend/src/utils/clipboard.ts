import { ElMessage } from 'element-plus'
import { t } from '@/i18n'

export async function copyTextToClipboard(text: string, successMessage = '已复制到剪贴板') {
  const value = text.trim()
  if (!value) {
    ElMessage.warning(t('没有可复制的内容'))
    return
  }

  try {
    await navigator.clipboard.writeText(value)
  } catch {
    const textarea = document.createElement('textarea')
    textarea.value = value
    textarea.style.position = 'fixed'
    textarea.style.left = '-9999px'
    document.body.appendChild(textarea)
    textarea.focus()
    textarea.select()
    document.execCommand('copy')
    document.body.removeChild(textarea)
  }

  ElMessage.success(t(successMessage))
}
