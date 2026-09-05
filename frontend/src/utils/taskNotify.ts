import { ElNotification } from 'element-plus'

type NoticeType = 'success' | 'warning' | 'error' | 'info'

interface TaskNoticeOptions {
  title: string
  message: string
  type?: NoticeType
  tag?: string
}

const NOTIFY_PERMISSION_KEY = 'malulu.taskNotification.permissionAsked'

function browserNotificationIcon(): string | undefined {
  const icon = document.querySelector<HTMLLinkElement>('link[rel="icon"]')?.href
  return icon || undefined
}

function sendBrowserNotification(options: TaskNoticeOptions) {
  if (!('Notification' in window)) return

  const create = () => {
    try {
      const notification = new Notification(options.title, {
        body: options.message,
        icon: browserNotificationIcon(),
        tag: options.tag,
      })
      notification.onclick = () => {
        window.focus()
        notification.close()
      }
    } catch {
      // Browser notification is best-effort; in-app notification below is the reliable path.
    }
  }

  if (Notification.permission === 'granted') {
    create()
    return
  }

  if (Notification.permission !== 'default') return

  try {
    localStorage.setItem(NOTIFY_PERMISSION_KEY, '1')
    void Notification.requestPermission().then((permission) => {
      if (permission === 'granted') create()
    })
  } catch {
    // Some browsers only allow permission prompts from direct user gestures.
  }
}

export function notifyTask(options: TaskNoticeOptions) {
  ElNotification({
    title: options.title,
    message: options.message,
    type: options.type ?? 'info',
    position: 'top-right',
    duration: options.type === 'error' ? 7000 : 5200,
    offset: 72,
  })
  sendBrowserNotification(options)
}

export function hasAskedTaskNotificationPermission(): boolean {
  return localStorage.getItem(NOTIFY_PERMISSION_KEY) === '1'
}
