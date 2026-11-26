import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router'
import { api, ApiError } from '../api/client'
import type { ClubEvent, EventDay } from '../api/types'
import { AuditHistory } from '../components/AuditHistory'
import { useMember, useToast } from '../shell/context'
import { EventHelferPanel } from './EventHelferPanel'
import { EventTasksPanel } from './EventTasksPanel'
import { attendanceGroupStartsOpen, groupAttendance } from './attendanceGroups'
import { GastroPlanPanel } from './GastroPlanPanel'
import { RidePlanPanel } from './RidePlanPanel'

const TABS = [
  { id: 'overview', label: 'Übersicht' },
  { id: 'rides', label: 'Fahrtenplanung' },
  { id: 'gastro', label: 'Gastronomie' },
  { id: 'tasks', label: 'Aufgaben' },
  { id: 'helfer', label: 'Helfer' },
] as const

type Tab = (typeof TABS)[number]['id']

function tabFromBereich(value: string | null): Tab {
  if (value === 'rides' || value === 'gastro' || value === 'tasks' || value === 'helfer') return value
  return 'overview'
}

export function EventDetailPage() {
  const { id } = useParams()
  const [params, setParams] = useSearchParams()
  const navigate = useNavigate()
  const member = useMember()
  const toast = useToast()
  const tab = tabFromBereich(params.get('bereich'))
  const [event, setEvent] = useState<ClubEvent | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [copying, setCopying] = useState(false)

  function openTab(next: Tab) {
    const current = params.get('bereich')
    const nextParams = new URLSearchParams(params)
    if (next === 'overview') {
      if (current === null) return
      nextParams.delete('bereich')
    } else if (current === next) {
      return
    } else {
      nextParams.set('bereich', next)
    }
    setParams(nextParams, { preventScrollReset: true })
  }

  useEffect(() => {
    if (!id) return
    setCopying(false)
    api<ClubEvent>(`/api/v1/events/${id}`)
      .then(setEvent)
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Veranstaltung konnte nicht geladen werden.'))
  }, [id])

  async function copyEvent() {
    if (!event) return
    setCopying(true)
    setError(null)
    try {
      const copied = await api<ClubEvent>(`/api/v1/events/${event.id}/copy`, { method: 'POST', body: '{}' })
      toast('Entwurf angelegt.')
      void navigate(`/veranstaltungen/${copied.id}`)
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Die Kopie konnte nicht angelegt werden.')
      setCopying(false)
    }
  }

  async function answer(dayId: string, value: 'yes' | 'no', comment: string) {
    setBusy(true)
    try {
      const updated = await api<ClubEvent>(`/api/v1/event-days/${dayId}/responses`, {
        method: 'POST',
        body: JSON.stringify({ answer: value, comment }),
      })
      setEvent(updated)
      toast('Antwort gespeichert.')
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Die Antwort konnte nicht gespeichert werden.')
    } finally {
      setBusy(false)
    }
  }

  if (error && !event) {
    return <div className="banner-error">{error}</div>
  }
  if (!event) {
    return <p className="empty">Wird geladen …</p>
  }

  const meetingPoints = event.days.flatMap((day) => day.meetingPoints.map((point) => ({ ...point, day: day.line1 })))

  return (
    <section>
      <div className="toolbar" style={{ marginBottom: 14 }}>
        <Link className="btn small light" to="/veranstaltungen">‹ Veranstaltungen</Link>
        <div style={{ display: 'flex', gap: 9, alignItems: 'center' }}>
          <span className={event.status === 'published' ? 'pill green' : event.status === 'cancelled' ? 'pill red' : 'pill gray'}>{event.statusLabel}</span>
          {member?.canOrganize ? <button className="btn small light secondary-action" disabled={copying} type="button" onClick={() => { void copyEvent() }}>Kopieren</button> : null}
          {member?.canOrganize ? <Link className="btn small" to={`/veranstaltungen/${event.id}/bearbeiten`}>✎ Bearbeiten</Link> : null}
        </div>
      </div>
      {error ? <div className="banner-error">{error}</div> : null}
      <div className="event-hero">
        <div className="eyebrow">{event.eyebrow}</div>
        <h2>{event.title}</h2>
        <div className="meta">
          {event.card ? <span>▦ {event.card.line1}</span> : null}
          {event.card?.line2 ? <span>◇ {event.card.line2}</span> : null}
          <span>▰ {event.clubLabels.join(' · ')}</span>
        </div>
      </div>
      <div className="subnav" role="tablist">
        {TABS.map((item) => (
          <button className={tab === item.id ? 'active' : ''} key={item.id} type="button" onClick={() => openTab(item.id)}>{item.label}</button>
        ))}
      </div>
      {tab === 'overview' ? (
        <div className="detailgrid">
          <div className="stack">
            {event.days.map((day) => (
              <DayAnswer busy={busy} day={day} key={day.id} onAnswer={answer} />
            ))}
            {event.internalDescription ? (
              <div className="panel">
                <div className="panel-head"><h2>Intern</h2></div>
                <div className="panel-inner"><p className="muted" style={{ margin: 0 }}>{event.internalDescription}</p></div>
              </div>
            ) : null}
          </div>
          <div className="stack">
            <div className="panel">
              <div className="panel-head"><h2>Treffpunkt</h2></div>
              <div className="panel-inner" style={{ paddingTop: 0 }}>
                {meetingPoints.length === 0 ? <b>Noch kein Treffpunkt</b> : meetingPoints.map((point) => (
                  <div className="item" key={point.id}>
                    <div>
                      <b>{point.placeName}{point.meetsAt ? ` · ${point.meetsAt}` : ''}</b>
                      <small>{point.day}{point.description ? ` · ${point.description}` : ''}</small>
                    </div>
                  </div>
                ))}
              </div>
            </div>
            {(event.vehicles ?? []).length > 0 ? (
              <div className="panel">
                <div className="panel-head"><h2>Fahrzeugliste</h2></div>
                <div className="panel-inner" style={{ paddingTop: 0 }}>
                  {event.vehicles.map((vehicle) => (
                    <div className="item" key={vehicle.vehicleId}>
                      <div>
                        <b>{vehicle.label}{vehicle.external ? ' · Extern' : ''}</b>
                        <small>{vehicle.rentalStatus ? `Mietstatus: ${vehicle.rentalStatus}` : vehicle.statusLabel}</small>
                      </div>
                    </div>
                  ))}
                </div>
              </div>
            ) : null}
            <div className="panel">
              <div className="panel-head"><h2>Einladungen</h2></div>
              <div className="panel-inner" style={{ paddingTop: 0 }}>
                {event.invitations.groups.length === 0 && event.invitations.people.length === 0 ? (
                  <p className="muted">Alle Mitglieder der zugeordneten Vereine.</p>
                ) : null}
                {event.invitations.groups.map((group) => (
                  <div className="item" key={group}><div><b>{group}</b><small>Gruppe</small></div></div>
                ))}
                {event.invitations.people.map((person) => (
                  <div className="item" key={person.sub}><div><b>{person.name}</b><small>Person</small></div></div>
                ))}
                {event.invitations.excluded.length > 0 ? <p className="muted">Ausgeschlossen: {event.invitations.excluded.map((person) => person.name).join(', ')}</p> : null}
              </div>
            </div>
            {member?.canOrganize ? <AuditHistory entityId={event.id} entityType="event" /> : null}
            <div className="panel">
              <div className="panel-head"><h2>Verknüpfte Bereiche</h2></div>
              <div className="panel-inner" style={{ paddingTop: 0 }}>
                <p className="muted" style={{ marginTop: 0 }}>Aufgabenlisten hängen am Reiter Aufgaben, Schichten am Reiter Helfer. Reservationen und Nextcloud folgen.</p>
              </div>
            </div>
          </div>
        </div>
      ) : null}
      {tab === 'rides' ? <RidePlanPanel event={event} onCancel={() => openTab('overview')} /> : null}
      {tab === 'gastro' ? <GastroPlanPanel event={event} onCancel={() => openTab('overview')} /> : null}
      {tab === 'tasks' ? <EventTasksPanel eventId={event.id} /> : null}
      {tab === 'helfer' ? <EventHelferPanel eventId={event.id} /> : null}
    </section>
  )
}

function DayAnswer({
  day,
  busy,
  onAnswer,
}: {
  day: EventDay
  busy: boolean
  onAnswer: (dayId: string, answer: 'yes' | 'no', comment: string) => Promise<void>
}) {
  const [comment, setComment] = useState(day.myComment ?? '')
  useEffect(() => { setComment(day.myComment ?? '') }, [day.myComment])

  return (
    <div className="panel answer-block">
      <div className="panel-head"><h2>Meine Teilnahme</h2><span className="muted">{day.line1}</span></div>
      <div className="panel-inner" style={{ paddingTop: 0 }}>
        <p className="muted">Kannst du an diesem Tag teilnehmen?</p>
        <div className="choice-pair">
          <button className={day.myResponse === 'yes' ? 'btn choice active-yes' : 'btn choice'} disabled={busy} type="button" onClick={() => { void onAnswer(day.id, 'yes', comment) }}>✓ Ja</button>
          <button className={day.myResponse === 'no' ? 'btn choice active-no' : 'btn choice'} disabled={busy} type="button" onClick={() => { void onAnswer(day.id, 'no', comment) }}>✕ Nein</button>
        </div>
        <label className="comment-line">
          <span className="muted">Kommentar, optional</span>
          <input className="input" style={{ marginTop: 6 }} value={comment} onChange={(event) => setComment(event.target.value)} />
        </label>
        <p className="muted">Alle eingeladenen Mitglieder können die Rückmeldungen sehen.</p>
        <AttendanceGroups dayId={day.id} pending={day.pending} responses={day.responses} />
      </div>
    </div>
  )
}

function AttendanceGroups({
  dayId,
  responses,
  pending,
}: {
  dayId: string
  responses: EventDay['responses']
  pending: EventDay['pending']
}) {
  const groups = groupAttendance(responses, pending)
  const [open, setOpen] = useState<Record<string, boolean>>(() => Object.fromEntries(
    groups.map((group) => [group.id, attendanceGroupStartsOpen(group.id)]),
  ))

  return (
    <div className="attendance-groups">
      {groups.map((group) => {
        const expanded = open[group.id] === true
        const panelId = `attendance-${dayId}-${group.id}`
        return (
          <div className="attendance-group" key={group.id}>
            <button
              aria-controls={panelId}
              aria-expanded={expanded}
              className="attendance-group-head"
              type="button"
              onClick={() => setOpen((current) => ({ ...current, [group.id]: !expanded }))}
            >
              <span className="attendance-label">{group.label}</span>
              <span className="attendance-count">{group.people.length}</span>
              <span aria-hidden="true" className="attendance-chevron">{expanded ? '▾' : '▸'}</span>
            </button>
            {expanded ? (
              <div className="attendance-group-body" id={panelId}>
                {group.people.map((person, index) => (
                  <div className="item" key={`${group.id}-${index}`}>
                    <div>
                      <b>{person.name}</b>
                      {person.comment ? <small>{person.comment}</small> : null}
                    </div>
                  </div>
                ))}
              </div>
            ) : null}
          </div>
        )
      })}
    </div>
  )
}
