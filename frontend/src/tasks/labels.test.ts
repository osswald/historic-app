import { describe, expect, it } from 'vitest'
import { progressText, TASK_STATUSES } from './labels'

describe('task labels', () => {
  it('uses the three statuses from the tasks API', () => {
    expect(TASK_STATUSES.map((status) => status.value)).toEqual(['todo', 'in_progress', 'done'])
    expect(TASK_STATUSES.map((status) => status.label)).toEqual(['Offen', 'In Arbeit', 'Erledigt'])
  })

  it('formats list progress in German', () => {
    expect(progressText({ done: 4, total: 12, percent: 33.3 })).toBe('4 von 12 erledigt · 33,3 %')
    expect(progressText({ done: 1, total: 2, percent: 50 })).toBe('1 von 2 erledigt · 50 %')
    expect(progressText(null)).toBeNull()
  })
})
