import type { Project, Story, StoryCharacter, ImageRequest, Job, Media } from '../types'
export function createStory(): Story
export function validateStory(value: unknown): Story
export function storyPrompt(project: Project): string
export function characterImageRequest(character: StoryCharacter, quality: string): ImageRequest
export function attachCharacterResult(project: Project, job: Job, image: Media): void
