import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { api, ApiError } from '../api/client'
import type { PollCard } from '../api/types'
import { useMember } from '../shell/context'

export function PollsPage() {
  const member = useMember()
  const [polls, setPolls] = useState<PollCard[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api<{ polls: PollCard[] }>('/api/v1/polls')
      .then((body) => setPolls(body.polls))
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Umfragen konnten nicht geladen werden.'))
  }, [])

  return (
    <section>
      <div className="heading">
        <div>
          <div className="eyebrow">Terminumfragen</div>
          <h1>Umfragen</h1>
          <div className="hello">Terminsuche und manuell angelegte monatliche Depottage mit allen Kalendertagen. Eine Antwort gilt erst nach dem Speichern.</div>
        </div>
        {member?.canOrganize ? <Link className="btn primary" to="/umfragen/neu">+ Umfrage</Link> : null}
      </div>
      {member?.canOrganize ? <Link className="btn primary mobile-create" to="/umfragen/neu">+ Umfrage</Link> : null}
      {error ? <div className="banner-error">{error}</div> : null}
      <div className="panel">
        {!polls && !error ? <p className="empty">Wird geladen …</p> : null}
        {polls && polls.length === 0 ? <p className="empty">Keine Umfragen.</p> : null}
        {polls?.map((poll) => (
          <div className="eventrow" key={poll.id}>
            <div className="eventcopy">
              <h3><Link to={`/umfragen/${poll.id}`}>{poll.title}</Link></h3>
              <div className="eventmeta">
                {poll.kindLabel} · {poll.optionCount} Optionen
                {poll.deadline ? ` · Antwortfrist ${formatDate(poll.deadline)}` : ''}
              </div>
            </div>
            <span className={pillClass(poll)}>{poll.status === 'closed' ? 'Geschlossen' : poll.myStateLabel}</span>
          </div>
        ))}
      </div>
    </section>
  )
}

function pillClass(poll: PollCard): string {
  if (poll.status === 'closed') return 'pill gray'
  if (poll.myState === 'complete') return 'pill green'
  return 'pill orange'
}

function formatDate(value: string): string {
  const [year, month, day] = value.split('-')
  return `${day}.${month}.${year}`
}
