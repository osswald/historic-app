import { useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'

type SpondOption = {
  name: string
  description: string | null
  accepted: number
  declined: number
  unanswered: number
}

type SpondRead = {
  groupName: string
  memberCount: number
  eventHeading: string
  eventOn: string
  description: string | null
  comment: string | null
  accepted: number
  declined: number
  unanswered: number
  answerComments: string[]
  options: SpondOption[]
  storedIdCount: number
  matchedCount: number
  idsMatched: boolean
  readAt: string
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

export function SpondPage() {
  const [read, setRead] = useState<SpondRead | null | undefined>(undefined)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api<{ read: SpondRead | null }>('/api/v1/spond/read')
      .then((body) => setRead(body.read))
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Der Spond-Lesestand konnte nicht geladen werden.'))
  }, [])

  return (
    <section>
      <div className="heading">
        <div>
          <div className="eyebrow">Anbindung</div>
          <h1>Spond</h1>
          <div className="hello">Spond bleibt das führende System. Diese Seite zeigt die letzte Leseabfrage, ohne etwas zurückzuschreiben.</div>
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
            <Stat label="Mitglieder" value={read.memberCount} />
            <Stat label="Zugesagt" value={read.accepted} />
            <Stat label="Abgesagt" value={read.declined} />
            <Stat label="Unbeantwortet" value={read.unanswered} />
          </div>
          <div className="panel">
            <div className="panel-head">
              <h2>{read.eventHeading}</h2>
            </div>
            <div className="panel-inner">
              <dl className="kv">
                <dt>Gruppe</dt>
                <dd>{read.groupName}</dd>
                <dt>Datum</dt>
                <dd>{germanDate(read.eventOn)}</dd>
                <dt>Gelesen</dt>
                <dd>{germanWhen(read.readAt)}</dd>
                <dt>Spond-IDs</dt>
                <dd>{read.idsMatched ? `${read.matchedCount} von ${read.storedIdCount} zugeordnet` : 'noch nicht abgeglichen'}</dd>
              </dl>
              <p className="note">Abgleich über historic_winterthur.spond_id. Personen ausserhalb dieser Leseabfrage werden nicht angelegt.</p>
              {read.description ? <p className="hello">{read.description}</p> : null}
              {read.comment ? <p className="hello">{read.comment}</p> : null}
              {read.options.length > 0 ? (
                <div className="simplelist">
                  {read.options.map((option, index) => (
                    <div className="item" key={`${option.name}-${index}`}>
                      <div>
                        <b>{option.name}</b>
                        <small>
                          {option.accepted} zugesagt · {option.declined} abgesagt · {option.unanswered} unbeantwortet
                          {option.description ? ` · ${option.description}` : ''}
                        </small>
                      </div>
                    </div>
                  ))}
                </div>
              ) : null}
              {read.answerComments.length > 0 ? (
                <div className="simplelist">
                  {read.answerComments.map((comment, index) => (
                    <div className="item" key={`${index}-${comment}`}>
                      <div>
                        <b>Kommentar</b>
                        <small>{comment}</small>
                      </div>
                    </div>
                  ))}
                </div>
              ) : null}
            </div>
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
