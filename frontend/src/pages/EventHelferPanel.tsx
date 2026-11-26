import { useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { EventHelfer, HelferPerson, HelferPlan } from '../api/types'
import { useToast } from '../shell/context'

export function EventHelferPanel({ eventId }: { eventId: string }) {
  const toast = useToast()
  const [data, setData] = useState<EventHelfer | null>(null)
  const [plans, setPlans] = useState<HelferPlan[]>([])
  const [chosen, setChosen] = useState('')
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  function load() {
    setError(null)
    api<EventHelfer>(`/api/v1/events/${eventId}/helfer`)
      .then((body) => {
        setData(body)
        if (!body.canEdit || body.present) {
          setPlans([])
          return
        }
        api<{ events: HelferPlan[] }>('/api/v1/helfer/events')
          .then((list) => setPlans(list.events))
          .catch(() => setPlans([]))
      })
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Helferplanung konnte nicht geladen werden.'))
  }

  useEffect(() => { load() }, [eventId])

  async function linkExisting() {
    if (!chosen) return
    setBusy(true)
    try {
      setData(await api<EventHelfer>(`/api/v1/events/${eventId}/helfer/link`, { method: 'POST', body: JSON.stringify({ helferEventId: chosen }) }))
      toast('Die Helferplanung ist verknüpft.')
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Die Helferplanung konnte nicht verknüpft werden.')
    } finally {
      setBusy(false)
    }
  }

  async function publish() {
    setBusy(true)
    try {
      setData(await api<EventHelfer>(`/api/v1/events/${eventId}/helfer`, { method: 'POST' }))
      toast('Der Anlass liegt in der Helferplanung.')
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Der Anlass konnte nicht angelegt werden.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="panel">
      <div className="panel-head">
        <h2>Helferplanung</h2>
        <div className="panel-head-actions">
          {data?.planUrl ? <a className="btn primary" href={data.planUrl} rel="noreferrer" target="_blank">Helferplanung öffnen</a> : null}
          {data?.url ? <a className="btn" href={data.url} rel="noreferrer" target="_blank">Mein Helferlink</a> : null}
          {data?.canEdit && data.configured && !data.present ? (
            <button className="btn" disabled={busy} type="button" onClick={() => { void publish() }}>Neu anlegen</button>
          ) : null}
        </div>
      </div>
      <div className="panel-inner" style={{ paddingTop: 0 }}>
        {error ? <div className="banner-error">{error}</div> : null}
        {data?.notice ? <p className="empty">{data.notice}</p> : null}
        {data?.assigned ? <p>Du bist verbindlich eingeteilt. Die Teilnahme bleibt auf Ja.</p> : null}
        {data?.canEdit && data.configured && !data.present ? (
          <div className="stack" style={{ marginBottom: 12 }}>
            <label>
              Bestehende Helferplanung
              <select value={chosen} onChange={(event) => setChosen(event.target.value)}>
                <option value="">Auswählen</option>
                {plans.map((plan) => <option key={plan.id} value={plan.id}>{plan.title}{plan.date ? ` · ${plan.date}` : ''}</option>)}
              </select>
            </label>
            <button className="btn primary" disabled={busy || chosen === ''} type="button" onClick={() => { void linkExisting() }}>Verknüpfen</button>
          </div>
        ) : null}
        {data && !data.notice && !data.present ? <p className="empty">Noch keine Helferplanung verknüpft.</p> : null}
        {data && data.present && data.shifts.length === 0 ? <p className="empty">Noch keine Schichten.</p> : null}
        <div className="simplelist">
          {(data?.shifts ?? []).map((shift) => (
            <div className="item" key={shift.id || shift.title}>
              <div>
                <b>{shift.title}</b>
                <small>{[shift.category, shiftTime(shift.startsAt, shift.endsAt)].filter(Boolean).join(' · ')}</small>
                <small>{shift.assignedCount} zugeteilt · {shift.reported} gemeldet · {shift.open} offen · Bedarf {shift.needed}</small>
                {shift.remarks ? <small>{shift.remarks}</small> : null}
                <People people={shift.people ?? []} />
              </div>
              {shift.url ? <a className="btn small" href={shift.url} rel="noreferrer" target="_blank">Schicht öffnen</a> : null}
            </div>
          ))}
        </div>
      </div>
    </div>
  )
}

function People({ people }: { people: HelferPerson[] }) {
  const assigned = people.filter((person) => person.status === 'zugeteilt')
  const reported = people.filter((person) => person.status === 'gemeldet')
  if (assigned.length === 0 && reported.length === 0) return null
  return (
    <>
      {assigned.length > 0 ? <ul className="helfer-people">{assigned.map((person, index) => <li key={`zugeteilt-${index}`}>{person.name}</li>)}</ul> : null}
      {reported.length > 0 ? <ul className="helfer-people">{reported.map((person, index) => <li className="reported" key={`gemeldet-${index}`}>{person.name} · gemeldet</li>)}</ul> : null}
    </>
  )
}

function shiftTime(startsAt: string | null, endsAt: string | null): string {
  const start = clock(startsAt)
  const end = clock(endsAt)
  if (start && end) return `${start}–${end}`
  return start || end
}

function clock(value: string | null): string {
  if (!value) return ''
  const date = new Date(value)
  if (Number.isNaN(date.getTime())) return ''
  return new Intl.DateTimeFormat('de-CH', { hour: '2-digit', minute: '2-digit', timeZone: 'Europe/Zurich' }).format(date)
}
