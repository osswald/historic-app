import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { api, ApiError } from '../api/client'
import type { ClubEvent, DayOffset, Ride, RidePlan } from '../api/types'
import { useToast } from '../shell/context'
import { clockLabel, dayLabel, offsetLabel } from './rideTime'

type RideDraft = {
  key: string
  id?: string
  eventDayId: string
  departureTime: string
  departureOffset: DayOffset
  arrivalTime: string
  arrivalOffset: DayOffset
  vehicleLabel: string
  clubVehicle: boolean
  internalNote: string
}

const OFFSETS: DayOffset[] = [-1, 0, 1]

export function RidePlanPanel({ event }: { event: ClubEvent }) {
  const toast = useToast()
  const [plan, setPlan] = useState<RidePlan | null>(null)
  const [drafts, setDrafts] = useState<RideDraft[]>([])
  const [warnings, setWarnings] = useState<string[]>([])
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api<RidePlan>(`/api/v1/events/${event.id}/rides`)
      .then((body) => {
        setPlan(body)
        setDrafts(body.rides.map(toDraft))
        setWarnings(body.warnings)
      })
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Fahrten konnten nicht geladen werden.'))
  }, [event.id])

  function updateDraft(key: string, patch: Partial<RideDraft>) {
    setDrafts((current) => current.map((ride) => ride.key === key ? { ...ride, ...patch } : ride))
  }

  async function save(submit: FormEvent) {
    submit.preventDefault()
    setBusy(true)
    setError(null)
    setFieldErrors({})
    try {
      const saved = await api<RidePlan>(`/api/v1/events/${event.id}/rides`, {
        method: 'PUT',
        body: JSON.stringify({
          rides: drafts.map((ride) => ({
            id: ride.id,
            eventDayId: ride.eventDayId,
            departureTime: ride.departureTime,
            departureOffset: ride.departureOffset,
            arrivalTime: ride.arrivalTime,
            arrivalOffset: ride.arrivalOffset,
            vehicleLabel: ride.vehicleLabel,
            clubVehicle: ride.clubVehicle,
            internalNote: ride.internalNote,
          })),
        }),
      })
      setPlan(saved)
      setDrafts(saved.rides.map(toDraft))
      setWarnings(saved.warnings)
      toast(saved.warnings.length > 0 ? 'Gespeichert. Bitte die Hinweise zur Doppelbelegung prüfen.' : 'Fahrt gespeichert.')
    } catch (reason) {
      if (reason instanceof ApiError) {
        setError(reason.message)
        setFieldErrors(reason.fields ?? {})
      } else {
        setError('Die Fahrten konnten nicht gespeichert werden.')
      }
    } finally {
      setBusy(false)
    }
  }

  if (!plan && !error) {
    return <p className="empty">Wird geladen …</p>
  }

  const canEdit = plan?.canEdit === true
  const rides = plan?.rides ?? []

  return (
    <div className="stack">
      {error ? <div className="banner-error">{error}</div> : null}
      {warnings.length > 0 ? (
        <div className="banner-warn" role="status">
          {warnings.map((warning) => <p key={warning}>{warning}</p>)}
        </div>
      ) : null}
      <div className="panel">
        <div className="panel-head"><h2>Abfahrt und Ankunft</h2></div>
        <div className="panel-inner" style={{ paddingTop: 0 }}>
          <div className="note">Erfasst werden nur Abfahrt und Ankunft. Der Tagversatz ist der Vortag, der Veranstaltungstag oder der Folgetag. Zwischenzeiten werden nicht geführt.</div>
        </div>
        {rides.length === 0 ? <p className="empty">Noch keine Fahrt erfasst.</p> : (
          <>
            <div className="route-head"><span>Tag</span><span>Fahrzeug</span><span>Abfahrt</span><span>Ankunft</span></div>
            {rides.map((ride) => {
              const day = event.days.find((item) => item.id === ride.eventDayId)
              return (
                <div className="route-row" key={ride.id}>
                  <span>{day ? dayLabel(day.date) : 'Tag'}</span>
                  <span>
                    <strong>{ride.vehicleLabel || 'Fahrzeug offen'}</strong>
                    {ride.clubVehicle ? <small>Vereinsfahrzeug</small> : null}
                  </span>
                  <span>{clockLabel(ride.departureTime, ride.departureOffset)}</span>
                  <span>{clockLabel(ride.arrivalTime, ride.arrivalOffset)}</span>
                </div>
              )
            })}
          </>
        )}
        {canEdit ? rides.filter((ride) => ride.internalNote).map((ride) => (
          <div className="ride-note note" key={`${ride.id}-note`}>{ride.internalNote}</div>
        )) : null}
      </div>
      {canEdit ? (
        <form onSubmit={(submit) => { void save(submit) }}>
          <div className="panel">
            <div className="panel-head">
              <h2>Fahrten bearbeiten</h2>
              <button className="btn primary small" disabled={busy} type="submit">Fahrten speichern</button>
            </div>
            <div className="panel-inner" style={{ paddingTop: 0 }}>
              <div className="note">Interne Notizen sehen Organisatoren und die Fahrtenplanung. Eine Doppelbelegung eines Vereinsfahrzeugs wird gemeldet und blockiert das Speichern nicht.</div>
              {fieldErrors.rides ? <div className="field-error">{fieldErrors.rides}</div> : null}
              {drafts.map((ride, index) => (
                <div className="day-block" key={ride.key}>
                  <h3>Fahrt {index + 1}</h3>
                  <Field error={fieldErrors[`rides.${index}.eventDayId`]} label="Veranstaltungstag">
                    <select className="input" value={ride.eventDayId} onChange={(input) => updateDraft(ride.key, { eventDayId: input.target.value })}>
                      {event.days.map((day) => <option key={day.id} value={day.id}>{dayLabel(day.date)}</option>)}
                    </select>
                  </Field>
                  <div className="formrow cols2">
                    <Field error={fieldErrors[`rides.${index}.departureTime`]} label="Abfahrt">
                      <input className="input" type="time" value={ride.departureTime} onChange={(input) => updateDraft(ride.key, { departureTime: input.target.value })} />
                    </Field>
                    <Field error={fieldErrors[`rides.${index}.departureOffset`]} label="Tag der Abfahrt">
                      <OffsetSelect value={ride.departureOffset} onChange={(offset) => updateDraft(ride.key, { departureOffset: offset })} />
                    </Field>
                  </div>
                  <div className="formrow cols2">
                    <Field error={fieldErrors[`rides.${index}.arrivalTime`]} label="Ankunft">
                      <input className="input" type="time" value={ride.arrivalTime} onChange={(input) => updateDraft(ride.key, { arrivalTime: input.target.value })} />
                    </Field>
                    <Field error={fieldErrors[`rides.${index}.arrivalOffset`]} label="Tag der Ankunft">
                      <OffsetSelect value={ride.arrivalOffset} onChange={(offset) => updateDraft(ride.key, { arrivalOffset: offset })} />
                    </Field>
                  </div>
                  <Field error={fieldErrors[`rides.${index}.vehicleLabel`]} label="Fahrzeug">
                    <input className="input" placeholder="z. B. Vereinsfahrzeug" value={ride.vehicleLabel} onChange={(input) => updateDraft(ride.key, { vehicleLabel: input.target.value })} />
                  </Field>
                  <label className="toggle">
                    <input checked={ride.clubVehicle} type="checkbox" onChange={(input) => updateDraft(ride.key, { clubVehicle: input.target.checked })} />
                    Vereinsfahrzeug
                  </label>
                  <Field error={fieldErrors[`rides.${index}.internalNote`]} label="Interne Notiz">
                    <textarea className="input" value={ride.internalNote} onChange={(input) => updateDraft(ride.key, { internalNote: input.target.value })} />
                  </Field>
                  <button className="btn small light" type="button" onClick={() => setDrafts((current) => current.filter((item) => item.key !== ride.key))}>Fahrt entfernen</button>
                </div>
              ))}
              <button className="btn light" type="button" onClick={() => setDrafts((current) => [...current, blankRide(event)])}>Fahrt hinzufügen</button>
            </div>
          </div>
        </form>
      ) : null}
    </div>
  )
}

function OffsetSelect({ value, onChange }: { value: DayOffset; onChange: (offset: DayOffset) => void }) {
  return (
    <select className="input" value={String(value)} onChange={(input) => onChange(parseOffset(input.target.value))}>
      {OFFSETS.map((offset) => <option key={offset} value={String(offset)}>{offsetLabel(offset)}</option>)}
    </select>
  )
}

function Field({ label, error, children }: { label: string; error?: string; children: ReactNode }) {
  return (
    <div className="formfield">
      <label>{label}</label>
      {children}
      {error ? <div className="field-error">{error}</div> : null}
    </div>
  )
}

function blankRide(event: ClubEvent): RideDraft {
  return {
    key: crypto.randomUUID(),
    eventDayId: event.days[0]?.id ?? '',
    departureTime: '',
    departureOffset: 0,
    arrivalTime: '',
    arrivalOffset: 0,
    vehicleLabel: '',
    clubVehicle: false,
    internalNote: '',
  }
}

function toDraft(ride: Ride): RideDraft {
  return {
    key: ride.id,
    id: ride.id,
    eventDayId: ride.eventDayId,
    departureTime: ride.departureTime,
    departureOffset: ride.departureOffset,
    arrivalTime: ride.arrivalTime,
    arrivalOffset: ride.arrivalOffset,
    vehicleLabel: ride.vehicleLabel,
    clubVehicle: ride.clubVehicle,
    internalNote: ride.internalNote ?? '',
  }
}

function parseOffset(value: string): DayOffset {
  if (value === '-1') return -1
  if (value === '1') return 1
  return 0
}
