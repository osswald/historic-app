import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router'
import { api, ApiError } from '../api/client'
import type { ClubEvent } from '../api/types'
import { useMember } from '../shell/context'

type Mode = 'month' | 'week' | 'list'

const WEEKDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']

export function CalendarPage() {
  const [events, setEvents] = useState<ClubEvent[]>([])
  const [error, setError] = useState<string | null>(null)
  const [cursor, setCursor] = useState(() => new Date())
  const [mode, setMode] = useState<Mode>(() => window.matchMedia('(max-width: 700px)').matches ? 'list' : 'month')
  const [mine, setMine] = useState(false)
  const member = useMember()

  useEffect(() => {
    api<{ events: ClubEvent[] }>('/api/v1/events')
      .then((body) => setEvents(body.events))
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Der Kalender konnte nicht geladen werden.'))
  }, [])

  const shown = useMemo(
    () => events.filter((event) => !mine || event.days.some((day) => day.myResponse === 'yes')),
    [events, mine],
  )

  const year = cursor.getFullYear()
  const month = cursor.getMonth()
  const title = cursor.toLocaleDateString('de-CH', { month: 'long', year: 'numeric' })

  return (
    <section>
      <div className="heading">
        <div>
          <div className="eyebrow">Kalender und Termine</div>
          <h1>Kalender</h1>
          <div className="hello">Deine Veranstaltungen im Überblick.</div>
        </div>
        {member?.canOrganize ? <Link className="btn primary" to="/veranstaltungen/neu">+ Neuer Anlass</Link> : <span />}
      </div>
      {error ? <div className="banner-error">{error}</div> : null}
      <div className="panel">
        <div className="panel-head">
          <h2 style={{ textTransform: 'capitalize' }}>{title}</h2>
          <div className="toolbar">
            <button className="btn small light" type="button" onClick={() => setCursor(new Date(year, month - 1, 1))}>‹</button>
            <button className="btn small light" type="button" onClick={() => setCursor(new Date())}>Heute</button>
            <button className="btn small light" type="button" onClick={() => setCursor(new Date(year, month + 1, 1))}>›</button>
            <button className={mode === 'month' ? 'btn small' : 'btn small light'} type="button" onClick={() => setMode('month')}>Monat</button>
            <button className={mode === 'week' ? 'btn small' : 'btn small light'} type="button" onClick={() => setMode('week')}>Woche</button>
            <button className={mode === 'list' ? 'btn small' : 'btn small light'} type="button" onClick={() => setMode('list')}>Liste</button>
            <button className={mine ? 'btn small' : 'btn small light'} type="button" onClick={() => setMine((value) => !value)}>Meine Termine</button>
          </div>
        </div>
        {mode === 'month' ? <Month events={shown} month={month} year={year} /> : null}
        {mode === 'week' ? <Week events={shown} cursor={cursor} /> : null}
        {mode === 'list' ? <List events={shown} month={month} year={year} /> : null}
      </div>
    </section>
  )
}

function eventsOn(events: ClubEvent[], date: string): ClubEvent[] {
  return events.filter((event) => event.days.some((day) => day.date === date))
}

function iso(year: number, month: number, day: number): string {
  const monthText = String(month + 1).padStart(2, '0')
  const dayText = String(day).padStart(2, '0')
  return `${year}-${monthText}-${dayText}`
}

function Month({ events, year, month }: { events: ClubEvent[]; year: number; month: number }) {
  const first = new Date(year, month, 1)
  const offset = (first.getDay() + 6) % 7
  const count = new Date(year, month + 1, 0).getDate()
  const cells: Array<number | null> = [...Array(offset).fill(null), ...Array.from({ length: count }, (_, index) => index + 1)]
  return (
    <div className="panel-inner">
      <div className="cal-grid" style={{ marginBottom: 7 }}>
        {WEEKDAYS.map((day) => <div className="cal-head" key={day}>{day}</div>)}
      </div>
      <div className="cal-grid">
        {cells.map((day, index) => {
          if (day === null) return <div key={`empty-${index}`} />
          const date = iso(year, month, day)
          const hits = eventsOn(events, date)
          return (
            <div className={hits.length ? 'day cal-cell on' : 'day cal-cell'} key={date}>
              <strong>{day}</strong>
              {hits.map((event) => <em key={event.id}><Link to={`/veranstaltungen/${event.id}`}>{event.title}</Link></em>)}
            </div>
          )
        })}
      </div>
    </div>
  )
}

function Week({ events, cursor }: { events: ClubEvent[]; cursor: Date }) {
  const monday = new Date(cursor)
  const offset = (monday.getDay() + 6) % 7
  monday.setDate(monday.getDate() - offset)
  const days = Array.from({ length: 7 }, (_, index) => {
    const date = new Date(monday)
    date.setDate(monday.getDate() + index)
    return date
  })
  return (
    <div className="panel-inner stack">
      {days.map((date) => {
        const key = iso(date.getFullYear(), date.getMonth(), date.getDate())
        const hits = eventsOn(events, key)
        return (
          <div key={key}>
            <b>{date.toLocaleDateString('de-CH', { weekday: 'long', day: 'numeric', month: 'long' })}</b>
            {hits.length === 0 ? <p className="muted">Keine Veranstaltung</p> : hits.map((event) => (
              <p key={event.id}><Link to={`/veranstaltungen/${event.id}`}>{event.title}</Link></p>
            ))}
          </div>
        )
      })}
    </div>
  )
}

function List({ events, year, month }: { events: ClubEvent[]; year: number; month: number }) {
  const rows = events.filter((event) => event.days.some((day) => {
    const date = new Date(day.date)
    return date.getFullYear() === year && date.getMonth() === month
  }))
  if (rows.length === 0) return <p className="empty">In diesem Monat liegt keine Veranstaltung.</p>
  return (
    <div>
      {rows.map((event) => (
        <div className="eventrow" key={event.id}>
          <div className="eventcopy">
            <h3><Link to={`/veranstaltungen/${event.id}`}>{event.title}</Link></h3>
            <div className="eventmeta">{event.card?.line1}</div>
          </div>
          <span className="pill gray">{event.statusLabel}</span>
        </div>
      ))}
    </div>
  )
}
