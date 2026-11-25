import { useEffect, useMemo, useState } from 'react'
import { Link } from 'react-router'
import { api, ApiError } from '../api/client'
import type { ClubEvent } from '../api/types'
import { EventRow } from '../components/EventRow'
import { useMember } from '../shell/context'

export function EventsPage() {
  const member = useMember()
  const [events, setEvents] = useState<ClubEvent[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [query, setQuery] = useState('')

  useEffect(() => {
    api<{ events: ClubEvent[] }>('/api/v1/events')
      .then((body) => setEvents(body.events))
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Liste konnte nicht geladen werden.'))
  }, [])

  const visible = useMemo(() => {
    const needle = query.trim().toLowerCase()
    return (events ?? []).filter((event) => {
      if (needle === '') return true
      const haystack = [
        event.title,
        event.publicDescription ?? '',
        event.internalDescription ?? '',
        event.location ?? '',
        event.categories.join(' '),
        event.clubLabels.join(' '),
        event.days.map((day) => [
          day.date,
          day.line1,
          day.location ?? '',
          day.meetingPoints.map((point) => `${point.placeName} ${point.description ?? ''}`).join(' '),
        ].join(' ')).join(' '),
      ].join(' ').toLowerCase()
      return haystack.includes(needle)
    })
  }, [events, query])

  return (
    <section>
      <div className="heading">
        <div>
          <div className="eyebrow">Veranstaltungen</div>
          <h1>Alle Anlässe</h1>
          <div className="hello">Einladungen, Fahrten und Einsätze.</div>
        </div>
        {member?.canOrganize ? <Link className="btn primary" to="/veranstaltungen/neu">+ Veranstaltung planen</Link> : null}
      </div>
      {member?.canOrganize ? <Link className="btn primary mobile-create" to="/veranstaltungen/neu">+ Veranstaltung planen</Link> : null}
      {error ? <div className="banner-error">{error}</div> : null}
      <div className="toolbar" style={{ marginBottom: 19 }}>
        <input aria-label="Veranstaltungen suchen" className="input" placeholder="Veranstaltungen suchen …" style={{ maxWidth: 355 }} value={query} onChange={(event) => setQuery(event.target.value)} />
      </div>
      <div className="panel">
        {events && visible.length === 0 ? <p className="empty">Keine Veranstaltung passt zur Suche.</p> : null}
        {visible.map((event) => event.card ? <EventRow card={event.card} key={event.id} /> : null)}
        {!events && !error ? <p className="empty">Wird geladen …</p> : null}
      </div>
    </section>
  )
}
