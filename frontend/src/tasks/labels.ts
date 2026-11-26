import type { TaskProgress } from '../api/types'

export const TASK_STATUSES = [
  { value: 'todo', label: 'Offen' },
  { value: 'in_progress', label: 'In Arbeit' },
  { value: 'done', label: 'Erledigt' },
] as const

export function progressText(progress: TaskProgress | null): string | null {
  if (!progress) return null
  const parts: string[] = []
  if (progress.done !== null && progress.total !== null) {
    parts.push(`${progress.done} von ${progress.total} erledigt`)
  } else if (progress.done !== null) {
    parts.push(`${progress.done} erledigt`)
  }
  if (progress.percent !== null) {
    const percent = Number.isInteger(progress.percent)
      ? String(progress.percent)
      : String(progress.percent).replace('.', ',')
    parts.push(`${percent} %`)
  }
  return parts.length > 0 ? parts.join(' · ') : null
}
