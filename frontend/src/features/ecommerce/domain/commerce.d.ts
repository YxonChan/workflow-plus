import type { Project, Section, ProductSection, CreativeSection, ImageRequest, Result, SectionKind } from '../types'
export function createProject(): Project
export function validateProject(value: unknown): Project
export function buildModelRequest(section: Section): ImageRequest
export function buildProductRequest(section: ProductSection): ImageRequest
export function buildSceneRequest(section: Section): ImageRequest
export function buildCreativeRequests(project: Project): ImageRequest[]
export function buildEditRequest(kind: SectionKind, section: Section | ProductSection | CreativeSection, base: Result, instruction: string): ImageRequest
export const MODEL_OPTIONS: Record<string, { value: string; label: string }[]>
export const PRODUCT_OPTIONS: Record<string, { value: string; label: string }[]>
export const SCENE_OPTIONS: Record<string, { value: string; label: string }[]>
export const CREATIVE_FORMATS: { value: string; label: string }[]
