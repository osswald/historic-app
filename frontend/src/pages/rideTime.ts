import type { DayOffset } from '../api/types'

const MONTHS = [
  'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
  'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
]

export function offsetLabel(offset: DayOffset): string {
  if (offset === -1) return 'Vortag'
  if (offset === 1) return 'Folgetag'
  return 'Veranstaltungstag'
}

export function clockLabel(time: string | null, offset: DayOffset | null): string {
  if (!time) return 'offen'
  if (offset === -1) return `${time}-`
  if (offset === 1) return `${time}+`
  return time
}

export function dayLabel(date: string): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date)
  if (!match) return date
  const month = MONTHS[Number(match[2]) - 1]
  if (!month) return date
  return `${Number(match[3])}. ${month}`
}
