import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { api, ApiError } from '../api/client'
import type { ClubEvent, DayOffset, OwnVehicleOption, Ride, RidePlan, RideVehicleKind, StationChoice, StationHit } from '../api/types'
import { useToast } from '../shell/context'
import { clockLabel, dayLabel, offsetLabel } from './rideTime'

type VehicleDraft = {
  key: string
  kind: RideVehicleKind
  vehicleId: string
  externalName: string
}

type RideDraft = {
  key: string
  id?: string
  eventDayId: string
  departureTime: string
  departureOffset: DayOffset
  arrivalTime: string
  arrivalOffset: DayOffset
  trainNumber: string
  origin: StationChoice | null
  destination: StationChoice | null
  vehicles: VehicleDraft[]
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

  function updateVehicle(rideKey: string, vehicleKey: string, patch: Partial<VehicleDraft>) {
    setDrafts((current) => current.map((ride) => ride.key === rideKey ? {
      ...ride,
      vehicles: ride.vehicles.map((vehicle) => vehicle.key === vehicleKey ? { ...vehicle, ...patch } : vehicle),
    } : ride))
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
            trainNumber: ride.trainNumber,
            origin: ride.origin,
            destination: ride.destination,
            vehicles: ride.vehicles.map((vehicle) => vehicle.kind === 'own'
              ? { kind: 'own', vehicleId: vehicle.vehicleId }
              : { kind: 'external', externalName: vehicle.externalName }),
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
  const ownVehicles = plan?.ownVehicles ?? []

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
          <div className="note">Erfasst werden Zugnummer, Start- und Zielbahnhof sowie Abfahrt und Ankunft. Der Tagversatz ist der Vortag, der Veranstaltungstag oder der Folgetag. Zwischenzeiten werden nicht geführt.</div>
        </div>
        {rides.length === 0 ? <p className="empty">Noch keine Fahrt erfasst.</p> : (
          <>
            <div className="route-head route-ride"><span>Tag</span><span>Zug</span><span>Strecke</span><span>Fahrzeuge</span><span>Abfahrt</span><span>Ankunft</span></div>
            {rides.map((ride) => {
              const day = event.days.find((item) => item.id === ride.eventDayId)
              return (
                <div className="route-row route-ride" key={ride.id}>
                  <span>{day ? dayLabel(day.date) : 'Tag'}</span>
                  <span><strong>{ride.trainNumber}</strong></span>
                  <span>
                    <strong>{ride.origin.name} → {ride.destination.name}</strong>
                  </span>
                  <span>
                    {ride.vehicles.map((vehicle) => (
                      <span key={vehicle.id}>{vehicle.label}{vehicle.kind === 'own' ? <small>Vereinsfahrzeug</small> : <small>Extern</small>}</span>
                    ))}
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
              <div className="note">Vereinsfahrzeuge kommen aus dem Fahrzeugstamm. Externe Fahrzeuge sind nur ein Name. Start- und Zielbahnhof kommen aus der öffentlichen Haltestellenliste, nur Züge. Eine Doppelbelegung eines Vereinsfahrzeugs wird gemeldet und blockiert das Speichern nicht.</div>
              {fieldErrors.rides ? <div className="field-error">{fieldErrors.rides}</div> : null}
              {drafts.map((ride, index) => (
                <div className="day-block" key={ride.key}>
                  <h3>Fahrt {index + 1}</h3>
                  <Field error={fieldErrors[`rides.${index}.eventDayId`]} label="Veranstaltungstag">
                    <select className="input" value={ride.eventDayId} onChange={(input) => updateDraft(ride.key, { eventDayId: input.target.value })}>
                      {event.days.map((day) => <option key={day.id} value={day.id}>{dayLabel(day.date)}</option>)}
                    </select>
                  </Field>
                  <Field error={fieldErrors[`rides.${index}.trainNumber`]} label="Zugnummer">
                    <input className="input" value={ride.trainNumber} onChange={(input) => updateDraft(ride.key, { trainNumber: input.target.value })} />
                  </Field>
                  <div className="formrow cols2">
                    <StationField
                      error={fieldErrors[`rides.${index}.origin`] ?? fieldErrors[`rides.${index}.origin.didokCode`] ?? fieldErrors[`rides.${index}.origin.name`]}
                      label="Startbahnhof"
                      value={ride.origin}
                      onChange={(origin) => updateDraft(ride.key, { origin })}
                    />
                    <StationField
                      error={fieldErrors[`rides.${index}.destination`] ?? fieldErrors[`rides.${index}.destination.didokCode`] ?? fieldErrors[`rides.${index}.destination.name`]}
                      label="Zielbahnhof"
                      value={ride.destination}
                      onChange={(destination) => updateDraft(ride.key, { destination })}
                    />
                  </div>
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
                  <div className="formfield">
                    <label>Fahrzeuge</label>
                    {fieldErrors[`rides.${index}.vehicles`] ? <div className="field-error">{fieldErrors[`rides.${index}.vehicles`]}</div> : null}
                    {ownVehicles.length === 0 ? <p className="muted">Im Fahrzeugstamm sind noch keine Vereinsfahrzeuge.</p> : null}
                    {ride.vehicles.map((vehicle, vehicleIndex) => (
                      <div className="vehicle-line" key={vehicle.key}>
                        <select className="input" value={vehicle.kind} onChange={(input) => updateVehicle(ride.key, vehicle.key, { kind: input.target.value === 'external' ? 'external' : 'own' })}>
                          <option value="own">Vereinsfahrzeug</option>
                          <option value="external">Extern</option>
                        </select>
                        {vehicle.kind === 'own' ? (
                          <select className="input" value={vehicle.vehicleId} onChange={(input) => updateVehicle(ride.key, vehicle.key, { vehicleId: input.target.value })}>
                            <option value="">Fahrzeug wählen</option>
                            {ownVehicles.map((option) => <option key={option.id} value={option.id}>{option.label} · {option.statusLabel}</option>)}
                          </select>
                        ) : (
                          <input className="input" placeholder="Name des externen Fahrzeugs" value={vehicle.externalName} onChange={(input) => updateVehicle(ride.key, vehicle.key, { externalName: input.target.value })} />
                        )}
                        <button className="btn small light" type="button" onClick={() => updateDraft(ride.key, { vehicles: ride.vehicles.filter((item) => item.key !== vehicle.key) })}>Entfernen</button>
                        {fieldErrors[`rides.${vehicleIndex}.vehicles.${vehicleIndex}.vehicleId`] || fieldErrors[`rides.${index}.vehicles.${vehicleIndex}.vehicleId`] || fieldErrors[`rides.${index}.vehicles.${vehicleIndex}.externalName`] ? (
                          <div className="field-error vehicle-line-error">{fieldErrors[`rides.${index}.vehicles.${vehicleIndex}.vehicleId`] ?? fieldErrors[`rides.${index}.vehicles.${vehicleIndex}.externalName`]}</div>
                        ) : null}
                      </div>
                    ))}
                    <button className="btn small light" type="button" onClick={() => updateDraft(ride.key, { vehicles: [...ride.vehicles, blankVehicle(ownVehicles)] })}>Fahrzeug hinzufügen</button>
                  </div>
                  <Field error={fieldErrors[`rides.${index}.internalNote`]} label="Interne Notiz">
                    <textarea className="input" value={ride.internalNote} onChange={(input) => updateDraft(ride.key, { internalNote: input.target.value })} />
                  </Field>
                  <button className="btn small light" type="button" onClick={() => setDrafts((current) => current.filter((item) => item.key !== ride.key))}>Fahrt entfernen</button>
                </div>
              ))}
              <button className="btn light" type="button" onClick={() => setDrafts((current) => [...current, blankRide(event, ownVehicles)])}>Fahrt hinzufügen</button>
            </div>
          </div>
        </form>
      ) : null}
    </div>
  )
}

function StationField({ label, value, onChange, error }: { label: string; value: StationChoice | null; onChange: (station: StationChoice) => void; error?: string }) {
  const [query, setQuery] = useState('')
  const [results, setResults] = useState<StationHit[]>([])
  const [searchError, setSearchError] = useState<string | null>(null)
  const [searching, setSearching] = useState(false)
  const [settled, setSettled] = useState(false)

  useEffect(() => {
    const term = query.trim()
    if (term.length < 1) {
      setResults([])
      setSearchError(null)
      setSearching(false)
      setSettled(false)
      return
    }
    let cancelled = false
    setSearching(true)
    setSettled(false)
    const timer = window.setTimeout(() => {
      api<{ stations: StationHit[] }>(`/api/v1/stations?q=${encodeURIComponent(term)}`)
        .then((body) => {
          if (cancelled) return
          setResults(body.stations)
          setSearchError(null)
        })
        .catch((reason: unknown) => {
          if (cancelled) return
          setResults([])
          setSearchError(reason instanceof ApiError ? reason.message : 'Die Bahnhofsuche ist gerade nicht erreichbar.')
        })
        .finally(() => {
          if (!cancelled) {
            setSearching(false)
            setSettled(true)
          }
        })
    }, 300)
    return () => {
      cancelled = true
      window.clearTimeout(timer)
    }
  }, [query])

  return (
    <Field error={error ?? searchError ?? undefined} label={label}>
      <p className="station-chosen">{value ? value.name : 'Noch kein Bahnhof gewählt.'}</p>
      <input aria-label={`${label} suchen`} className="input" placeholder="Name oder Kürzel" value={query} onChange={(input) => setQuery(input.target.value)} />
      {searching ? <p className="muted">Suche …</p> : null}
      {!searching && settled && query.trim().length >= 1 && results.length === 0 && !searchError ? <p className="muted">Keine Zugshaltestelle gefunden.</p> : null}
      {results.length > 0 ? (
        <ul className="station-results">
          {results.map((station) => (
            <li key={station.id ?? station.didokCode}>
              <button type="button" onClick={() => { onChange({ didokCode: station.didokCode, name: station.name }); setQuery(''); setResults([]) }}>
                <span>{station.name}</span>
                {station.abbreviation ? <small>{station.abbreviation}</small> : null}
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </Field>
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

function blankVehicle(ownVehicles: OwnVehicleOption[]): VehicleDraft {
  return {
    key: crypto.randomUUID(),
    kind: 'own',
    vehicleId: ownVehicles[0]?.id ?? '',
    externalName: '',
  }
}

function blankRide(event: ClubEvent, ownVehicles: OwnVehicleOption[]): RideDraft {
  return {
    key: crypto.randomUUID(),
    eventDayId: event.days[0]?.id ?? '',
    departureTime: '',
    departureOffset: 0,
    arrivalTime: '',
    arrivalOffset: 0,
    trainNumber: '',
    origin: null,
    destination: null,
    vehicles: [blankVehicle(ownVehicles)],
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
    trainNumber: ride.trainNumber,
    origin: ride.origin,
    destination: ride.destination,
    vehicles: ride.vehicles.map((vehicle) => ({
      key: vehicle.id,
      kind: vehicle.kind,
      vehicleId: vehicle.vehicleId ?? '',
      externalName: vehicle.kind === 'external' ? vehicle.label : '',
    })),
    internalNote: ride.internalNote ?? '',
  }
}

function parseOffset(value: string): DayOffset {
  if (value === '-1') return -1
  if (value === '1') return 1
  return 0
}
