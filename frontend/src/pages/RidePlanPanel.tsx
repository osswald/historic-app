import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { api, ApiError } from '../api/client'
import type { ClubEvent, DayOffset, EventVehicle, FleetOption, Ride, RidePlan, StationChoice, StationHit } from '../api/types'
import { incompleteMessage, missingFieldLabels } from '../forms/incomplete'
import { MobileSaveBar } from '../forms/MobileSaveBar'
import { VehiclePick } from '../forms/VehiclePick'
import { useRegisterUnsaved } from '../forms/unsaved'
import { useToast } from '../shell/context'
import { clockLabel, dayLabel, offsetLabel } from './rideTime'

type VehicleDraft = {
  key: string
  vehicleId: string
}

type ListedVehicle = {
  key: string
  vehicleId: string
  rentalStatus: string
}

type RideDraft = {
  key: string
  id?: string
  eventDayId: string
  departureTime: string
  departureOffset: DayOffset | null
  arrivalTime: string
  arrivalOffset: DayOffset | null
  trainNumber: string
  origin: StationChoice | null
  destination: StationChoice | null
  vehicles: VehicleDraft[]
  internalNote: string
}

const OFFSETS: DayOffset[] = [-1, 0, 1]

export function RidePlanPanel({ event, onCancel }: { event: ClubEvent; onCancel?: () => void }) {
  const toast = useToast()
  const [plan, setPlan] = useState<RidePlan | null>(null)
  const [drafts, setDrafts] = useState<RideDraft[]>([])
  const [listed, setListed] = useState<ListedVehicle[]>([])
  const [warnings, setWarnings] = useState<string[]>([])
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [incomplete, setIncomplete] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [ready, setReady] = useState(false)
  const [baseline, setBaseline] = useState<string | null>(null)
  const [composing, setComposing] = useState(false)

  useEffect(() => {
    setComposing(false)
    api<RidePlan>(`/api/v1/events/${event.id}/rides`)
      .then((body) => {
        setPlan(body)
        setDrafts(body.rides.map(toDraft))
        setListed(body.eventVehicles.map(toListed))
        setWarnings(body.warnings)
        setReady(true)
      })
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Fahrten konnten nicht geladen werden.'))
  }, [event.id])

  const snapshot = JSON.stringify({ drafts, listed })
  useEffect(() => {
    if (!ready) return
    setBaseline((current) => current ?? snapshot)
  }, [ready, snapshot])
  const dirty = baseline !== null && snapshot !== baseline
  useRegisterUnsaved(`rides-${event.id}`, dirty)

  function updateDraft(key: string, patch: Partial<RideDraft>) {
    setDrafts((current) => current.map((ride) => ride.key === key ? { ...ride, ...patch } : ride))
  }

  function updateVehicle(rideKey: string, vehicleKey: string, patch: Partial<VehicleDraft>) {
    setDrafts((current) => current.map((ride) => ride.key === rideKey ? {
      ...ride,
      vehicles: ride.vehicles.map((vehicle) => vehicle.key === vehicleKey ? { ...vehicle, ...patch } : vehicle),
    } : ride))
  }

  function cancel() {
    onCancel?.()
  }

  async function save(submit: FormEvent) {
    submit.preventDefault()
    const missing = missingRideFields(drafts)
    if (missing.length > 0) {
      setIncomplete(incompleteMessage(missing))
      setFieldErrors(rideFieldErrors(drafts))
      setError(null)
      return
    }
    setBusy(true)
    setError(null)
    setIncomplete(null)
    setFieldErrors({})
    try {
      const saved = await api<RidePlan>(`/api/v1/events/${event.id}/rides`, {
        method: 'PUT',
        body: JSON.stringify({
          eventVehicles: listed
            .filter((vehicle) => vehicle.vehicleId !== '')
            .map((vehicle) => ({ vehicleId: vehicle.vehicleId, rentalStatus: vehicle.rentalStatus })),
          rides: drafts.map((ride) => ({
            id: ride.id,
            eventDayId: ride.eventDayId,
            departureTime: ride.departureTime === '' ? null : ride.departureTime,
            departureOffset: ride.departureTime === '' ? null : ride.departureOffset,
            arrivalTime: ride.arrivalTime === '' ? null : ride.arrivalTime,
            arrivalOffset: ride.arrivalTime === '' ? null : ride.arrivalOffset,
            trainNumber: ride.trainNumber,
            origin: ride.origin,
            destination: ride.destination,
            vehicles: ride.vehicles
              .filter((vehicle) => vehicle.vehicleId !== '')
              .map((vehicle) => ({ vehicleId: vehicle.vehicleId })),
            internalNote: ride.internalNote,
          })),
        }),
      })
      const nextDrafts = saved.rides.map(toDraft)
      const nextListed = saved.eventVehicles.map(toListed)
      setPlan(saved)
      setDrafts(nextDrafts)
      setListed(nextListed)
      setBaseline(JSON.stringify({ drafts: nextDrafts, listed: nextListed }))
      setWarnings(saved.warnings)
      toast(saved.warnings.length > 0 ? 'Gespeichert. Bitte die Hinweise zur Doppelbelegung prüfen.' : 'Fahrt gespeichert.')
    } catch (reason) {
      if (reason instanceof ApiError && reason.message === 'Die Eingaben sind unvollständig.') {
        setIncomplete(incompleteMessage(labelsForRideErrors(reason.fields)))
        setFieldErrors(reason.fields ?? {})
      } else if (reason instanceof ApiError) {
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
  const fleet = plan?.fleet ?? []
  const listedOptions = listed
    .map((item) => fleet.find((vehicle) => vehicle.id === item.vehicleId))
    .filter((vehicle): vehicle is FleetOption => vehicle !== undefined)

  function addRide() {
    setDrafts((current) => [...current, blankRide(event, listedOptions)])
    setComposing(true)
  }

  if (rides.length === 0 && !composing) {
    return (
      <div className="stack">
        {error ? <div className="banner-error">{error}</div> : null}
        {canEdit ? (
          <button className="btn light empty-add" type="button" onClick={addRide}>Fahrt hinzufügen</button>
        ) : (
          <p className="muted">Noch keine Fahrt erfasst.</p>
        )}
      </div>
    )
  }

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
          <div className="note">Erfasst werden Zugnummer, Start- und Zielbahnhof und mindestens ein Fahrzeug. Abfahrt und Ankunft können offen bleiben. Der Tagversatz gilt nur, wenn die zugehörige Zeit gesetzt ist. Zwischenzeiten werden nicht geführt.</div>
        </div>
        {rides.length === 0 ? <p className="empty">Noch keine Fahrt erfasst.</p> : (
          <>
            <div className="route-head route-ride"><span>Tag</span><span>Zug</span><span>Strecke</span><span>Fahrzeuge</span><span>Abfahrt</span><span>Ankunft</span></div>
            {rides.map((ride) => {
              const day = event.days.find((item) => item.id === ride.eventDayId)
              return (
                <div className="route-row route-ride" key={ride.id}>
                  <span data-label="Tag">{day ? dayLabel(day.date) : 'Tag'}</span>
                  <span data-label="Zug"><strong>{ride.trainNumber}</strong></span>
                  <span data-label="Strecke">
                    <strong title={`${ride.origin.name} → ${ride.destination.name}`}>{stationLabel(ride.origin)} → {stationLabel(ride.destination)}</strong>
                  </span>
                  <span data-label="Fahrzeuge">
                    {ride.vehicles.map((vehicle) => (
                      <span key={vehicle.id}>{vehicle.label}{vehicle.external ? <small>Extern</small> : <small>Vereinsfahrzeug</small>}</span>
                    ))}
                  </span>
                  <span data-label="Abfahrt">{clockLabel(ride.departureTime, ride.departureOffset)}</span>
                  <span data-label="Ankunft">{clockLabel(ride.arrivalTime, ride.arrivalOffset)}</span>
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
        <form className="editor-form" onSubmit={(submit) => { void save(submit) }}>
          <div className="panel">
            <div className="panel-head">
              <h2>Fahrten bearbeiten</h2>
              <button className="btn primary small desktop-save" disabled={busy} type="submit">Fahrten speichern</button>
            </div>
            <div className="panel-inner" style={{ paddingTop: 0 }}>
              <div className="note">Die Fahrzeugliste des Anlasses kommt aus dem Fahrzeugstamm, inklusive externer Fahrzeuge. Jede Fahrt verwendet eine Teilmenge. Bahnhöfe erscheinen mit Kürzel; der volle Name steht im Tooltip. Eine Doppelbelegung eines Vereinsfahrzeugs gilt für den ganzen Anlasszeitraum, nennt Entwurf oder veröffentlicht und blockiert das Speichern nicht. Abgesagte Anlässe zählen nicht.</div>
              <div className="formfield">
                <label>Fahrzeugliste des Anlasses</label>
                {fieldErrors.eventVehicles ? <div className="field-error">{fieldErrors.eventVehicles}</div> : null}
                {listed.map((vehicle, index) => {
                  const chosen = fleet.find((item) => item.id === vehicle.vehicleId)
                  return (
                    <div key={vehicle.key}>
                      <div className="vehicle-line">
                        <VehiclePick
                          options={fleet.map((item) => ({ id: item.id, label: `${item.label}${item.external ? ' · Extern' : ''} · ${item.statusLabel}` }))}
                          value={vehicle.vehicleId}
                          onChange={(vehicleId) => setListed((current) => current.map((item) => item.key === vehicle.key ? { ...item, vehicleId } : item))}
                        />
                        {chosen?.external ? (
                          <input aria-label="Mietstatus" className="input" placeholder="Mietstatus" value={vehicle.rentalStatus} onChange={(input) => setListed((current) => current.map((item) => item.key === vehicle.key ? { ...item, rentalStatus: input.target.value } : item))} />
                        ) : null}
                        <button className="btn small light" type="button" onClick={() => {
                          setListed((current) => current.filter((item) => item.key !== vehicle.key))
                          setDrafts((current) => current.map((ride) => ({ ...ride, vehicles: ride.vehicles.filter((item) => item.vehicleId !== vehicle.vehicleId) })))
                        }}>Entfernen</button>
                      </div>
                      {fieldErrors[`eventVehicles.${index}.vehicleId`] || fieldErrors[`eventVehicles.${index}.rentalStatus`] ? (
                        <div className="field-error">{fieldErrors[`eventVehicles.${index}.vehicleId`] ?? fieldErrors[`eventVehicles.${index}.rentalStatus`]}</div>
                      ) : null}
                    </div>
                  )
                })}
                <button className="btn small light" type="button" onClick={() => setListed((current) => [...current, { key: crypto.randomUUID(), vehicleId: '', rentalStatus: '' }])}>Fahrzeug aufnehmen</button>
              </div>
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
                      <input className="input" type="time" value={ride.departureTime} onChange={(input) => updateDraft(ride.key, { departureTime: input.target.value, departureOffset: input.target.value === '' ? null : (ride.departureOffset ?? 0) })} />
                    </Field>
                    {ride.departureTime !== '' ? (
                      <Field error={fieldErrors[`rides.${index}.departureOffset`]} label="Tag der Abfahrt">
                        <OffsetSelect value={ride.departureOffset ?? 0} onChange={(offset) => updateDraft(ride.key, { departureOffset: offset })} />
                      </Field>
                    ) : null}
                  </div>
                  <div className="formrow cols2">
                    <Field error={fieldErrors[`rides.${index}.arrivalTime`]} label="Ankunft">
                      <input className="input" type="time" value={ride.arrivalTime} onChange={(input) => updateDraft(ride.key, { arrivalTime: input.target.value, arrivalOffset: input.target.value === '' ? null : (ride.arrivalOffset ?? 0) })} />
                    </Field>
                    {ride.arrivalTime !== '' ? (
                      <Field error={fieldErrors[`rides.${index}.arrivalOffset`]} label="Tag der Ankunft">
                        <OffsetSelect value={ride.arrivalOffset ?? 0} onChange={(offset) => updateDraft(ride.key, { arrivalOffset: offset })} />
                      </Field>
                    ) : null}
                  </div>
                  <div className="formfield">
                    <label>Fahrzeuge</label>
                    {fieldErrors[`rides.${index}.vehicles`] ? <div className="field-error">{fieldErrors[`rides.${index}.vehicles`]}</div> : null}
                    {listedOptions.length === 0 ? <p className="muted">Zuerst ein Fahrzeug in die Liste des Anlasses aufnehmen.</p> : null}
                    {ride.vehicles.map((vehicle, vehicleIndex) => (
                      <div className="vehicle-line" key={vehicle.key}>
                        <VehiclePick
                          options={listedOptions.map((option) => ({ id: option.id, label: `${option.label}${option.external ? ' · Extern' : ''}` }))}
                          value={vehicle.vehicleId}
                          onChange={(vehicleId) => updateVehicle(ride.key, vehicle.key, { vehicleId })}
                        />
                        <button className="btn small light" type="button" onClick={() => updateDraft(ride.key, { vehicles: ride.vehicles.filter((item) => item.key !== vehicle.key) })}>Entfernen</button>
                        {fieldErrors[`rides.${index}.vehicles.${vehicleIndex}.vehicleId`] ? (
                          <div className="field-error vehicle-line-error">{fieldErrors[`rides.${index}.vehicles.${vehicleIndex}.vehicleId`]}</div>
                        ) : null}
                      </div>
                    ))}
                    <button className="btn small light" type="button" onClick={() => updateDraft(ride.key, { vehicles: [...ride.vehicles, blankVehicle(listedOptions)] })}>Fahrzeug hinzufügen</button>
                  </div>
                  <Field error={fieldErrors[`rides.${index}.internalNote`]} label="Interne Notiz">
                    <textarea className="input" value={ride.internalNote} onChange={(input) => updateDraft(ride.key, { internalNote: input.target.value })} />
                  </Field>
                  <button className="btn small light" type="button" onClick={() => setDrafts((current) => current.filter((item) => item.key !== ride.key))}>Fahrt entfernen</button>
                </div>
              ))}
              <button className="btn light" type="button" onClick={addRide}>Fahrt hinzufügen</button>
            </div>
          </div>
          <MobileSaveBar busy={busy} dirty={dirty} incomplete={incomplete} onCancel={cancel} />
        </form>
      ) : null}
    </div>
  )
}

function StationField({ label, value, onChange, error }: { label: string; value: StationChoice | null; onChange: (station: StationChoice) => void; error?: string }) {
  const [query, setQuery] = useState('')
  const [nameOpen, setNameOpen] = useState(false)
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
      {value ? (
        <>
          <p className="station-chosen">
            <button
              className="ghost station-chosen-name"
              title={value.name}
              type="button"
              onClick={() => { if (value.abbreviation?.trim()) setNameOpen((open) => !open) }}
            >
              {stationLabel(value)}
            </button>
          </p>
          {nameOpen && value.abbreviation?.trim() ? <p className="station-official">{value.name}</p> : null}
        </>
      ) : <p className="station-chosen">Noch kein Bahnhof gewählt.</p>}
      <input aria-label={`${label} suchen`} className="input" placeholder="Name oder Kürzel" value={query} onChange={(input) => setQuery(input.target.value)} />
      {searching ? <p className="muted">Suche …</p> : null}
      {!searching && settled && query.trim().length >= 1 && results.length === 0 && !searchError ? <p className="muted">Keine Zugshaltestelle gefunden.</p> : null}
      {results.length > 0 ? (
        <ul className="station-results">
          {results.map((station) => (
            <li key={station.id ?? station.didokCode}>
              <button title={station.name} type="button" onClick={() => { onChange({ didokCode: station.didokCode, name: station.name, abbreviation: station.abbreviation }); setQuery(''); setResults([]) }}>
                <span>{station.abbreviation?.trim() ? station.abbreviation : station.name}</span>
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

function stationLabel(station: StationChoice): string {
  return station.abbreviation?.trim() ? station.abbreviation : station.name
}

function blankVehicle(options: FleetOption[]): VehicleDraft {
  return {
    key: crypto.randomUUID(),
    vehicleId: options[0]?.id ?? '',
  }
}

function blankRide(event: ClubEvent, options: FleetOption[]): RideDraft {
  return {
    key: crypto.randomUUID(),
    eventDayId: event.days[0]?.id ?? '',
    departureTime: '',
    departureOffset: null,
    arrivalTime: '',
    arrivalOffset: null,
    trainNumber: '',
    origin: null,
    destination: null,
    vehicles: [blankVehicle(options)],
    internalNote: '',
  }
}

function toDraft(ride: Ride): RideDraft {
  return {
    key: ride.id,
    id: ride.id,
    eventDayId: ride.eventDayId,
    departureTime: ride.departureTime ?? '',
    departureOffset: ride.departureTime ? ride.departureOffset : null,
    arrivalTime: ride.arrivalTime ?? '',
    arrivalOffset: ride.arrivalTime ? ride.arrivalOffset : null,
    trainNumber: ride.trainNumber,
    origin: ride.origin,
    destination: ride.destination,
    vehicles: ride.vehicles.map((vehicle) => ({
      key: vehicle.id,
      vehicleId: vehicle.vehicleId ?? '',
    })),
    internalNote: ride.internalNote ?? '',
  }
}

function toListed(vehicle: EventVehicle): ListedVehicle {
  return { key: vehicle.vehicleId, vehicleId: vehicle.vehicleId, rentalStatus: vehicle.rentalStatus ?? '' }
}

function parseOffset(value: string): DayOffset {
  if (value === '-1') return -1
  if (value === '1') return 1
  return 0
}

function rideTag(label: string, index: number, total: number): string {
  return total > 1 ? `${label} (Fahrt ${index + 1})` : label
}

function missingRideFields(drafts: RideDraft[]): string[] {
  return missingFieldLabels(drafts.flatMap((ride, index) => [
    { label: rideTag('Zugnummer', index, drafts.length), filled: ride.trainNumber.trim() !== '' },
    { label: rideTag('Startbahnhof', index, drafts.length), filled: ride.origin !== null },
    { label: rideTag('Zielbahnhof', index, drafts.length), filled: ride.destination !== null },
    { label: rideTag('Fahrzeuge', index, drafts.length), filled: ride.vehicles.some((vehicle) => vehicle.vehicleId !== '') },
  ]))
}

function rideFieldErrors(drafts: RideDraft[]): Record<string, string> {
  const next: Record<string, string> = {}
  drafts.forEach((ride, index) => {
    if (ride.trainNumber.trim() === '') next[`rides.${index}.trainNumber`] = 'Bitte eine Zugnummer angeben.'
    if (ride.origin === null) next[`rides.${index}.origin`] = 'Bitte den Startbahnhof aus der Suche wählen.'
    if (ride.destination === null) next[`rides.${index}.destination`] = 'Bitte den Zielbahnhof aus der Suche wählen.'
    if (!ride.vehicles.some((vehicle) => vehicle.vehicleId !== '')) next[`rides.${index}.vehicles`] = 'Bitte mindestens ein Fahrzeug angeben.'
  })
  return next
}

function labelsForRideErrors(fields: Record<string, string> | undefined): string[] {
  if (!fields) return []
  return Object.keys(fields).map((key) => {
    if (key.includes('trainNumber')) return 'Zugnummer'
    if (key.includes('origin')) return 'Startbahnhof'
    if (key.includes('destination')) return 'Zielbahnhof'
    if (key.includes('vehicles')) return 'Fahrzeuge'
    if (key.includes('eventDayId')) return 'Veranstaltungstag'
    if (key.includes('departureTime')) return 'Abfahrt'
    if (key.includes('arrivalTime')) return 'Ankunft'
    if (key.includes('internalNote')) return 'Interne Notiz'
    return ''
  })
}
