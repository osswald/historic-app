const SHOW_PAST_KEY = 'historic.events.showPast'

export function zurichToday(now: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', {
    timeZone: 'Europe/Zurich',
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).format(now)
}

export function eventIncludesTodayOrLater(dates: string[], today: string): boolean {
  let latest: string | null = null
  for (const date of dates) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(date)) continue
    if (latest === null || date > latest) latest = date
  }
  if (latest === null) return true
  return latest >= today
}

export function readShowPastEvents(): boolean {
  try {
    return sessionStorage.getItem(SHOW_PAST_KEY) === '1'
  } catch {
    return false
  }
}

export function writeShowPastEvents(show: boolean): void {
  try {
    sessionStorage.setItem(SHOW_PAST_KEY, show ? '1' : '0')
  } catch {
    // Private mode can block storage; the toggle still works for this page view.
  }
}
