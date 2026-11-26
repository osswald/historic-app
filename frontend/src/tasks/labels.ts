import type { TaskProgress } from '../api/types'

const SHOW_DONE_KEY = 'historic.tasks.showDone'

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

export function readShowDoneTasks(): boolean {
  try {
    return sessionStorage.getItem(SHOW_DONE_KEY) === '1'
  } catch {
    return false
  }
}

export function writeShowDoneTasks(show: boolean): void {
  try {
    sessionStorage.setItem(SHOW_DONE_KEY, show ? '1' : '0')
  } catch {
    // Private mode can block storage; the toggle still works for this page view.
  }
}

export function statusButtonClass(current: boolean): string {
  return current ? 'btn small primary' : 'btn small'
}

export function visibleTasks<T extends { status: string | null }>(tasks: readonly T[], showDone: boolean): T[] {
  if (showDone) return [...tasks]
  return tasks.filter((task) => task.status !== 'done')
}

export function tasksEmptyCopy(total: number, showDone: boolean, empty: string): string {
  if (!showDone && total > 0) return 'Keine offenen Aufgaben.'
  return empty
}
