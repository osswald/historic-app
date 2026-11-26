import { afterEach, describe, expect, it } from 'vitest'
import { progressText, readShowDoneTasks, statusButtonClass, TASK_STATUSES, tasksEmptyCopy, visibleTasks, writeShowDoneTasks } from './labels'

describe('task labels', () => {
  afterEach(() => {
    sessionStorage.clear()
  })

  it('uses the three statuses from the tasks API', () => {
    expect(TASK_STATUSES.map((status) => status.value)).toEqual(['todo', 'in_progress', 'done'])
    expect(TASK_STATUSES.map((status) => status.label)).toEqual(['Offen', 'In Arbeit', 'Erledigt'])
  })

  it('paints only the current status in the primary color', () => {
    expect(statusButtonClass(true)).toBe('btn small primary')
    expect(statusButtonClass(false)).toBe('btn small')
    const classes = TASK_STATUSES.map((status) => statusButtonClass(status.value === 'in_progress'))
    expect(classes).toEqual(['btn small', 'btn small primary', 'btn small'])
  })

  it('formats list progress in German', () => {
    expect(progressText({ done: 4, total: 12, percent: 33.3 })).toBe('4 von 12 erledigt · 33,3 %')
    expect(progressText({ done: 1, total: 2, percent: 50 })).toBe('1 von 2 erledigt · 50 %')
    expect(progressText(null)).toBeNull()
  })

  it('hides completed tasks until this visit asks to show them', () => {
    const tasks = [
      { status: 'todo' as const },
      { status: 'in_progress' as const },
      { status: 'done' as const },
      { status: null },
    ]
    expect(visibleTasks(tasks, false).map((task) => task.status)).toEqual(['todo', 'in_progress', null])
    expect(visibleTasks(tasks, true).map((task) => task.status)).toEqual(['todo', 'in_progress', 'done', null])
    expect(tasksEmptyCopy(3, false, 'Keine eigenen Aufgaben.')).toBe('Keine offenen Aufgaben.')
    expect(tasksEmptyCopy(0, false, 'Keine eigenen Aufgaben.')).toBe('Keine eigenen Aufgaben.')
    expect(tasksEmptyCopy(3, true, 'Keine eigenen Aufgaben.')).toBe('Keine eigenen Aufgaben.')
  })

  it('remembers whether completed tasks are shown for this visit', () => {
    expect(readShowDoneTasks()).toBe(false)
    writeShowDoneTasks(true)
    expect(readShowDoneTasks()).toBe(true)
    writeShowDoneTasks(false)
    expect(readShowDoneTasks()).toBe(false)
  })
})
