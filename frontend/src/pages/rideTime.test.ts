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
    expect(clockLabel('09:10', null)).toBe('09:10')
    expect(clockLabel('01:23', 1)).toBe('01:23+')
    expect(clockLabel('23:55', -1)).toBe('23:55-')
  })

  it('leaves an unknown time open and without a day offset', () => {
    expect(clockLabel(null, null)).toBe('offen')
    expect(clockLabel('', null)).toBe('offen')
  })

  it('labels the event day without inventing a time', () => {
    expect(dayLabel('2027-05-15')).toBe('15. Mai')
  })
})
