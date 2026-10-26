import type { Project } from '../types'
export function buildVideoRequest(project: Project): {
  model: string; prompt: string; duration: number; aspect_ratio: string; resolution: string; generate_audio: boolean
  image_with_roles: { url: string; role: string; kind: string }[]
}
