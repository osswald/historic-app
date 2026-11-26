import { useEffect, useMemo, useRef, useState } from 'react'
import { Link } from 'react-router'
import { api, ApiError } from '../api/client'
import type { ClubEvent } from '../api/types'
import { responsePill } from '../components/EventRow'
import { useMember } from '../shell/context'

type Mode = 'month' | 'week' | 'list'

const WEEKDAYS = ['Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa', 'So']

export function CalendarPage() {
  const [events, setEvents] = useState<ClubEvent[]>([])
  const [error, setError] = useState<string | null>(null)
  const [cursor, setCursor] = useState(() => new Date())
  const [mode, setMode] = useState<Mode>(() => window.matchMedia('(max-width: 700px)').matches ? 'list' : 'month')
  const [mine, setMine] = useState(false)
  const [category, setCategory] = useState('')
  const [person, setPerson] = useState('')
  const [group, setGroup] = useState('')
  const member = useMember()

  useEffect(() => {
    api<{ events: ClubEvent[] }>('/api/v1/events')
      .then((body) => setEvents(body.events))
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Der Kalender konnte nicht geladen werden.'))
  }, [])

  const categories = useMemo(
    () => [...new Set(events.flatMap((event) => event.categories))].sort((a, b) => a.localeCompare(b, 'de')),
    [events],
  )
  const people = useMemo(() => {
    const names = events.flatMap((event) => [
      ...event.invitations.people.map((item) => item.name),
      ...event.days.flatMap((day) => [...day.responses.map((response) => response.name), ...day.pending.map((item) => item.name)]),
    ])
    return [...new Set(names)].sort((a, b) => a.localeCompare(b, 'de'))
  }, [events])
  const groups = member?.groups ?? []
  const shown = useMemo(
    () => events.filter((event) => {
      if (mine && !event.days.some((day) => day.myResponse === 'yes')) return false
      if (category !== '' && !event.categories.includes(category)) return false
      if (person !== '' && !event.invitations.people.some((item) => item.name === person) && !event.days.some((day) => day.responses.some((response) => response.name === person) || day.pending.some((item) => item.name === person))) return false
      if (group !== '' && !event.invitations.groups.includes(group)) return false
      return true
    }),
    [events, mine, category, person, group],
  )

  const year = cursor.getFullYear()
  const month = cursor.getMonth()
  const title = cursor.toLocaleDateString('de-CH', { month: 'long', year: 'numeric' })

  return (
    <section className="calendar-page">
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
        <div className="cal-filters">
          <select aria-label="Kategorie" className="input" value={category} onChange={(event) => setCategory(event.target.value)}>
            <option value="">Alle Kategorien</option>
            {categories.map((item) => <option key={item} value={item}>{item}</option>)}
          </select>
          <select aria-label="Person" className="input" value={person} onChange={(event) => setPerson(event.target.value)}>
            <option value="">Alle Personen</option>
            {people.map((item) => <option key={item} value={item}>{item}</option>)}
          </select>
          <select aria-label="Gruppe" className="input" value={group} onChange={(event) => setGroup(event.target.value)}>
            <option value="">Alle Gruppen</option>
            {groups.map((item) => <option key={item} value={item}>{item}</option>)}
          </select>
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

function CalendarTitleLink({ event, chip = false }: { event: ClubEvent; chip?: boolean }) {
  const measureRef = useRef<HTMLSpanElement>(null)
  const [fullTitle, setFullTitle] = useState<string | undefined>(undefined)

  useEffect(() => {
    const target = measureRef.current
    if (!target) return
    const update = () => {
      if (target.clientWidth === 0) {
        setFullTitle(undefined)
        return
      }
      const clipped = target.scrollWidth - target.clientWidth > 0 || target.scrollHeight - target.clientHeight > 1
      setFullTitle(clipped ? event.title : undefined)
    }
    update()
    const observer = new ResizeObserver(update)
    observer.observe(target)
    return () => observer.disconnect()
  }, [event.title])

  return (
    <Link
      className={chip ? 'cal-event cal-tip' : 'cal-tip'}
      data-full-title={fullTitle}
      to={`/veranstaltungen/${event.id}`}
    >
      {chip
        ? <span className="cal-event-label" ref={measureRef}>{event.title}</span>
        : <span className="cal-title-text" ref={measureRef}>{event.title}</span>}
    </Link>
  )
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
              {hits.map((event) => <em key={event.id}><CalendarTitleLink chip event={event} /></em>)}
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
    <div className="panel-inner cal-week">
      {days.map((date) => {
        const key = iso(date.getFullYear(), date.getMonth(), date.getDate())
        const hits = eventsOn(events, key)
        return (
          <div className="day-block" key={key}>
            <h3>{date.toLocaleDateString('de-CH', { weekday: 'long', day: 'numeric', month: 'long' })}</h3>
            {hits.length === 0 ? <p className="muted cal-week-empty">Keine Veranstaltung</p> : (
              <div className="cal-week-events">
                {hits.map((event) => {
                  const pill = listPill(event)
                  return (
                    <div className="cal-week-event" key={event.id}>
                      <div className="eventcopy">
                        <h3><CalendarTitleLink event={event} /></h3>
                        {event.card?.line2 ? <div className="eventmeta">{event.card.line2}</div> : null}
                      </div>
                      <span className={pill.className}>{pill.label}</span>
                    </div>
                  )
                })}
              </div>
            )}
          </div>
        )
      })}
    </div>
  )
}

function listPill(event: ClubEvent): { className: string; label: string } {
  if (event.card && (event.card.response === 'yes' || event.card.response === 'no')) {
    return responsePill(event.card)
  }
  return { className: 'pill gray', label: event.statusLabel }
}

function List({ events, year, month }: { events: ClubEvent[]; year: number; month: number }) {
  const rows = events.filter((event) => event.days.some((day) => {
    const date = new Date(day.date)
    return date.getFullYear() === year && date.getMonth() === month
  }))
  if (rows.length === 0) return <p className="empty">In diesem Monat liegt keine Veranstaltung.</p>
  return (
    <div className="cal-list">
      {rows.map((event) => {
        const pill = listPill(event)
        const card = event.card
        return (
          <div className="eventrow" key={event.id}>
            {card ? (
              <div className="datebox">
                <b>{card.day}</b>
                <span>{card.month}</span>
              </div>
            ) : null}
            <div className="eventcopy">
              <h3><CalendarTitleLink event={event} /></h3>
              {card && (card.line1 || card.line2) ? (
                <div className="eventmeta">
                  {card.line1}
                  {card.line2 ? <><br />{card.line2}</> : null}
                </div>
              ) : null}
            </div>
            <span className={pill.className}>{pill.label}</span>
          </div>
        )
      })}
    </div>
  )
}
