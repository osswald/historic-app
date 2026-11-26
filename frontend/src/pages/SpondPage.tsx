import { useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'

type SpondOption = {
  name: string
  description: string | null
  accepted: number
  declined: number
  unanswered: number
}

type SpondEvent = {
  heading: string
  eventOn: string
  description: string | null
  comment: string | null
  accepted: number
  declined: number
  unanswered: number
  answerComments: string[]
  options: SpondOption[]
}

type SpondRead = {
  groupName: string
  memberCount: number
  eventCount: number
  earliestOn: string | null
  latestOn: string | null
  accepted: number
  declined: number
  unanswered: number
  storedIdCount: number
  matchedCount: number
  idsMatched: boolean
  readAt: string
  events: SpondEvent[]
}

function germanDate(iso: string): string {
  const [year, month, day] = iso.split('-').map(Number)
  if (!year || !month || !day) return iso
  return new Intl.DateTimeFormat('de-CH', { day: 'numeric', month: 'long', year: 'numeric' }).format(new Date(year, month - 1, day))
}

function germanWhen(iso: string): string {
  const date = new Date(iso)
  if (Number.isNaN(date.getTime())) return iso
  return new Intl.DateTimeFormat('de-CH', { dateStyle: 'long', timeStyle: 'short' }).format(date)
}

function dateBox(iso: string): { day: string; month: string } {
  const [year, month, day] = iso.split('-').map(Number)
  if (!year || !month || !day) return { day: '', month: '' }
  const date = new Date(year, month - 1, day)
  return {
    day: String(day),
    month: new Intl.DateTimeFormat('de-CH', { month: 'short' }).format(date).replace('.', ''),
  }
}

export function SpondPage() {
  const [read, setRead] = useState<SpondRead | null | undefined>(undefined)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api<{ read: SpondRead | null }>('/api/v1/spond/read')
      .then((body) => setRead(body.read))
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Der Spond-Lesestand konnte nicht geladen werden.'))
  }, [])

  const span = read?.earliestOn && read.latestOn
    ? `${germanDate(read.earliestOn)} bis ${germanDate(read.latestOn)}`
    : null

  return (
    <section>
      <div className="heading">
        <div>
          <div className="eyebrow">Anbindung</div>
          <h1>Spond</h1>
          <div className="hello">Spond bleibt das führende System. Diese Seite zeigt die gelesenen Termine, ohne etwas zurückzuschreiben.</div>
        </div>
      </div>
      {error ? <div className="banner-error">{error}</div> : null}
      {read === undefined && !error ? <p className="empty">Wird geladen …</p> : null}
      {read === null ? (
        <div className="panel">
          <div className="panel-inner">
            <div className="note">Noch keine Leseabfrage. Organisatoren und Administratoren starten sie mit php bin/console app:spond:read.</div>
          </div>
        </div>
      ) : null}
      {read ? (
        <>
          <div className="stats">
            <Stat label="Termine" value={read.eventCount} />
            <Stat label="Zugesagt" value={read.accepted} />
            <Stat label="Abgesagt" value={read.declined} />
            <Stat label="Unbeantwortet" value={read.unanswered} />
          </div>
          <div className="panel">
            <div className="panel-head">
              <h2>{read.groupName}</h2>
            </div>
            <div className="panel-inner">
              <dl className="kv">
                <dt>Mitglieder</dt>
                <dd>{read.memberCount}</dd>
                <dt>Zeitraum</dt>
                <dd>{span ?? 'keine Termine'}</dd>
                <dt>Gelesen</dt>
                <dd>{germanWhen(read.readAt)}</dd>
                <dt>Spond-IDs</dt>
                <dd>{read.idsMatched ? `${read.matchedCount} von ${read.storedIdCount} zugeordnet` : 'noch nicht abgeglichen'}</dd>
              </dl>
              <p className="note">Abgleich nur, wenn die Spond-ID des Kontos der Mitglieds-ID entspricht. Es werden keine Konten angelegt. Dieselben Termine liegen als veröffentlichte Anlässe im Kalender, dort können Fahrt und Gastronomie ergänzt werden.</p>
            </div>
            {read.events.map((event, index) => {
              const box = dateBox(event.eventOn)
              return (
                <div className="eventrow" key={`${event.eventOn}-${index}`}>
                  <div className="datebox">
                    <b>{box.day}</b>
                    <span>{box.month}</span>
                  </div>
                  <div className="eventcopy">
                    <h3>{event.heading}</h3>
                    <div className="eventmeta">{germanDate(event.eventOn)}</div>
                    <div className="eventmeta">
                      {event.accepted} zugesagt · {event.declined} abgesagt · {event.unanswered} unbeantwortet
                    </div>
                    {event.description ? <div className="eventmeta">{event.description}</div> : null}
                    {event.comment ? <div className="eventmeta">{event.comment}</div> : null}
                    {event.options.map((option, optionIndex) => (
                      <div className="eventmeta" key={`${option.name}-${optionIndex}`}>
                        {option.name}: {option.accepted} zugesagt · {option.declined} abgesagt · {option.unanswered} unbeantwortet
                        {option.description ? ` · ${option.description}` : ''}
                      </div>
                    ))}
                    {event.answerComments.map((comment, commentIndex) => (
                      <div className="eventmeta" key={`${commentIndex}-${comment}`}>Kommentar: {comment}</div>
                    ))}
                  </div>
                </div>
              )
            })}
          </div>
        </>
      ) : null}
    </section>
  )
}

function Stat({ label, value }: { label: string; value: number }) {
  return (
    <div className="stat">
      <div>
        <div className="n">{value}</div>
        <div className="lab">{label}</div>
      </div>
    </div>
  )
}
