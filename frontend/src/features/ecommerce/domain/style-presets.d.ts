import type { Preset } from '../types'
export const STYLE_PRESETS: Preset[]
export const STYLE_CATEGORIES: string[]
export const IMAGE_PURPOSES: { id: string; label: string; count: number; icon: string; defaultStyleId: string; legacy?: boolean; pageIds: string[] }[]
export function getStylePreset(id?: string): Preset | undefined
export function stylesForPurpose(purpose: string): Preset[]
export function styleSupportsPurpose(presetId: string, purpose: string): boolean
export function defaultStyleForPurpose(purpose: string): string | undefined
export function assertStyleForPurpose(presetId: string, purpose: string): void
