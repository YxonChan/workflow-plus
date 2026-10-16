import type { PromptContent, PromptReference, PromptSettings, PromptShot } from '@/api/promptAssistant'

export interface PromptVideoTiming {
  duration: number
  shots: Array<Pick<PromptShot, 'title' | 'duration'>>
}

export interface PromptQuickCreateDraft {
  settings: PromptSettings
  prompt_body: string
  style_block?: string
  references?: PromptReference[]
  duration: number
  timing?: PromptVideoTiming
}

function seconds(value: number): string {
  return String(Math.round(value * 10) / 10)
}

function timingLines(timing: PromptVideoTiming, duration = timing.duration): string[] {
  const total = timing.shots.reduce((sum, shot) => sum + shot.duration, 0)
  let elapsed = 0
  return [`视频时长：${seconds(duration)}秒`, ...timing.shots.map((shot, index) => {
    const start = elapsed
    elapsed += shot.duration
    const end = index === timing.shots.length - 1 ? duration : elapsed / total * duration
    return `镜头${index + 1}：${shot.title}（${seconds(start / total * duration)}-${seconds(end)}秒）`
  })]
}

export function buildPromptQuickCreateDraft(content: PromptContent, body: string, shots: PromptShot[], shot?: PromptShot): PromptQuickCreateDraft {
  const duration = shot?.duration ?? content.settings.duration
  const draft: PromptQuickCreateDraft = {
    settings: content.settings, prompt_body: body, style_block: content.style_block,
    references: content.references, duration,
  }
  if (content.settings.mode !== 'video') return draft
  const selectedShots = shot ? [shot] : shots
  draft.timing = { duration, shots: selectedShots.map(({ title, duration }) => ({ title, duration })) }
  const [durationLine, ...shotLines] = timingLines(draft.timing)
  draft.prompt_body = [
    durationLine,
    content.settings.aspect_ratio && content.settings.aspect_ratio !== 'adaptive' ? `画幅比例：${content.settings.aspect_ratio}` : '',
    shot ? '生成范围：仅以下镜头，不生成其他情节。' : '',
    shot ? `整体背景（仅作为主体、场景和约束参考）：\n${body}` : body,
    ...selectedShots.map((item, index) => `${shotLines[index]}\n${item.prompt_body}`),
  ].filter(Boolean).join('\n\n')
  return draft
}

/** Update only generated timing headings, preserving the user's changes to the prompt body. */
export function updatePromptVideoTiming(prompt: string, timing: PromptVideoTiming, from: number, to: number): string | null {
  const previous = timingLines(timing, from)
  const next = timingLines(timing, to)
  const lines = prompt.split('\n')
  if (!previous.every((line) => lines.includes(line))) return null
  const replacements = new Map(previous.map((line, index) => [line, next[index]!]))
  return lines.map((line) => replacements.get(line) ?? line).join('\n')
}
