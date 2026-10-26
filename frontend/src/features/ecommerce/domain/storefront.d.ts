import type { Project, ImageRequest } from '../types'
export const STOREFRONT_GOALS: { id: string; label: string; count: number; icon: string }[]
export function storefrontGoal(project: Project): string
export function selectStorefrontGoal(project: Project, goal: string): void
export function selectStorefrontStyle(project: Project, presetId: string): void
export function createStorefrontProject(): Project
export function prepareStorefrontProject(project: Project): Project
export function storefrontImageRequests(project: Project): { title: string; pageKey: string; request: ImageRequest }[]
export function imageReferenceInput(request: ImageRequest): { images: string[]; prompt: string }
export function storefrontVideoRequest(project: Project): { model: string; prompt: string; duration: number; aspect_ratio: string; resolution: string; generate_audio: boolean; image_with_roles: { url: string; role: string; kind: string }[] }
