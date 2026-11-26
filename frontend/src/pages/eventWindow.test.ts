import { afterEach, describe, expect, it } from 'vitest'
import { eventIncludesTodayOrLater, readShowPastEvents, writeShowPastEvents, zurichToday } from './eventWindow'

describe('event window', () => {
  afterEach(() => {
    sessionStorage.clear()
  })

  it('uses the Europe/Zurich calendar day, including just after local midnight', () => {
    expect(zurichToday(new Date('2026-11-26T22:30:00.000Z'))).toBe('2026-11-26')
    expect(zurichToday(new Date('2026-11-26T23:30:00.000Z'))).toBe('2026-11-27')
  })

  it('keeps an event whose latest day is today, even when that day already started', () => {
    expect(eventIncludesTodayOrLater(['2026-11-26'], '2026-11-26')).toBe(true)
  })

  it('keeps a multi-day event that still includes today', () => {
    expect(eventIncludesTodayOrLater(['2026-11-24', '2026-11-26', '2026-11-25'], '2026-11-26')).toBe(true)
  })

  it('treats an event as past only when every day is before today', () => {
    expect(eventIncludesTodayOrLater(['2026-11-24', '2026-11-25'], '2026-11-26')).toBe(false)
    expect(eventIncludesTodayOrLater(['2026-11-27'], '2026-11-26')).toBe(true)
  })

  it('does not hide an event that has no usable day', () => {
    expect(eventIncludesTodayOrLater([], '2026-11-26')).toBe(true)
    expect(eventIncludesTodayOrLater(['gestern'], '2026-11-26')).toBe(true)
  })

  it('remembers whether past events are shown for this visit', () => {
    expect(readShowPastEvents()).toBe(false)
    writeShowPastEvents(true)
    expect(readShowPastEvents()).toBe(true)
    writeShowPastEvents(false)
    expect(readShowPastEvents()).toBe(false)
  })
})
