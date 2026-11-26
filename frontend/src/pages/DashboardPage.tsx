import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { api, ApiError } from '../api/client'
import type { DashboardData } from '../api/types'
import { EventRow } from '../components/EventRow'
import { useMember, useToast } from '../shell/context'
import { readShowDoneTasks, tasksEmptyCopy, visibleTasks } from '../tasks/labels'
import { ShowDoneTasksToggle } from '../tasks/ShowDoneTasksToggle'

function greeting(): string {
  const hour = new Date().getHours()
  if (hour < 11) return 'Guten Morgen.'
  if (hour < 18) return 'Guten Tag.'
  return 'Guten Abend.'
}

export function DashboardPage() {
  const member = useMember()
  const toast = useToast()
  const [data, setData] = useState<DashboardData | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [showDone, setShowDone] = useState(readShowDoneTasks)

  function load() {
    setError(null)
    api<DashboardData>('/api/v1/dashboard')
      .then(setData)
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Startseite konnte nicht geladen werden.'))
  }

  useEffect(() => { load() }, [])

  async function answer(dayId: string, value: 'yes' | 'no') {
    setBusy(true)
    try {
      await api(`/api/v1/event-days/${dayId}/responses`, { method: 'POST', body: JSON.stringify({ answer: value }) })
      toast('Antwort gespeichert.')
      load()
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Die Antwort konnte nicht gespeichert werden.')
    } finally {
      setBusy(false)
    }
  }

  const counts = data?.counts
  const homeTasks = visibleTasks(data?.tasks ?? [], showDone)

  return (
    <section>
      <div className="heading welcome-heading">
        <div>
          <div className="eyebrow">Seit 1995 · Lok-Depot-Ost Winterthur</div>
          <h1>{greeting()}</h1>
          <div className="hello">Dein Vereinsleben – Veranstaltungen, Rückmeldungen und Aufgaben an einem Ort.</div>
        </div>
        {member?.canOrganize ? <Link className="btn primary" to="/veranstaltungen/neu">+ Veranstaltung</Link> : null}
      </div>
      {error ? <div className="banner-error">{error}</div> : null}
      <div className="stats">
        <Stat icon="✉" label="Offene Antworten" value={counts?.openResponses ?? '–'} warn />
        <Stat icon="▦" label="Terminumfrage" value={counts?.polls ?? '–'} />
        <Stat icon="♧" label="Helferschichten" value={counts?.shifts ?? '–'} />
        <Stat icon="☑" label="Meine Aufgaben" value={counts?.tasks ?? '–'} />
      </div>
      <div className="content-grid">
        <div className="stack">
          <div className="panel">
            <div className="panel-head">
              <h2>Anstehende Veranstaltungen</h2>
              <Link className="a" to="/veranstaltungen">Alle ansehen ›</Link>
            </div>
            {data && data.upcoming.length === 0 ? <p className="empty">Keine anstehenden Veranstaltungen.</p> : null}
            {data?.upcoming.map((card) => <EventRow busy={busy} card={card} key={card.id} onAnswer={(dayId, value) => { void answer(dayId, value) }} />)}
            {!data && !error ? <p className="empty">Wird geladen …</p> : null}
          </div>
          <div className="panel">
            <div className="panel-head">
              <h2>Die nächsten 7 Tage</h2>
              <Link className="a" to="/kalender">Kalender öffnen ›</Link>
            </div>
            <div className="miniweek">
              {(data?.nextSevenDays ?? []).map((day) => (
                <div className={day.hasEvent ? 'day on' : 'day'} key={day.date}>
                  {day.weekday}<strong>{day.day}</strong>
                  {day.hasEvent ? <div className="dot" /> : null}
                </div>
              ))}
            </div>
          </div>
        </div>
        <div className="stack">
          <div className="panel">
            <div className="panel-head tasks-head">
              <h2>Meine Aufgaben</h2>
              <div className="tasks-head-actions">
                <ShowDoneTasksToggle checked={showDone} onChange={setShowDone} />
                <Link className="a" to="/aufgaben">Aufgaben öffnen ›</Link>
              </div>
            </div>
            {data?.tasksNotice ? <p className="empty">{data.tasksNotice}</p> : null}
            {!data?.tasksNotice && data && homeTasks.length === 0 ? <p className="empty">{tasksEmptyCopy(data.tasks.length, showDone, 'Keine eigenen Aufgaben.')}</p> : null}
            <div className="simplelist">
              {homeTasks.map((task) => (
                <div className="item" key={task.id}>
                  <div className={task.status === 'done' ? 'check checked' : 'check'}>{task.status === 'done' ? '✓' : ''}</div>
                  <div>
                    <b>{task.title ?? task.id}</b>
                    {task.details ? <small>{task.details}</small> : null}
                    <small>{task.statusLabel ?? task.status ?? 'Ohne Status'}</small>
                  </div>
                </div>
              ))}
            </div>
          </div>
          <div className="panel">
            <div className="panel-head"><h2>Meine Helferschichten</h2></div>
            <p className="empty">Persönliche Schichten erscheinen hier, sobald die Helferplanung verknüpft ist.</p>
          </div>
            <div className="panel">
            <div className="panel-head">
              <h2>Offene Terminumfrage</h2>
              <Link className="a" to={data?.openPoll ? `/umfragen/${data.openPoll.id}` : '/umfragen'}>{data?.openPoll?.state === 'complete' ? 'Ansehen ›' : 'Beantworten ›'}</Link>
            </div>
            {data?.openPoll ? (
              <div className="panel-inner" style={{ paddingTop: 0 }}>
                <b style={{ fontSize: 13 }}>{data.openPoll.title}</b>
                <p className="muted" style={{ margin: '7px 0 0' }}>{data.openPoll.prompt}</p>
              </div>
            ) : <p className="empty">Keine offene Terminumfrage.</p>}
          </div>
        </div>
      </div>
    </section>
  )
}

function Stat({ icon, label, value, warn = false }: { icon: string; label: string; value: number | string; warn?: boolean }) {
  return (
    <div className={warn ? 'stat warn' : 'stat'}>
      <div className="ico">{icon}</div>
      <div>
        <div className="n">{value}</div>
        <div className="lab">{label}</div>
      </div>
    </div>
  )
}
