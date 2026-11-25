import { describe, expect, it } from 'vitest'
import { clockLabel, dayLabel, offsetLabel } from './rideTime'

describe('ride times', () => {
  it('names the day offset relative to the event day', () => {
    expect(offsetLabel(-1)).toBe('Vortag')
    expect(offsetLabel(0)).toBe('Veranstaltungstag')
    expect(offsetLabel(1)).toBe('Folgetag')
  })

  it('keeps a same-day clock bare and marks the neighbouring days', () => {
    expect(clockLabel('09:10', 0)).toBe('09:10')
    expect(clockLabel('06:00', 1)).toBe('06:00 (Folgetag)')
    expect(clockLabel('18:00', -1)).toBe('18:00 (Vortag)')
  })

  it('labels the event day without inventing a time', () => {
    expect(dayLabel('2027-05-15')).toBe('15. Mai')
  })
})
