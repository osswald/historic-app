import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { api, ApiError } from '../api/client'
import type { CatalogValue, ClubEvent, DayOffset, EventVehicle, FleetOption, Ride, RidePlan, RidePlanning, StationChoice, StationHit, StatusOption } from '../api/types'
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
  vias: StationChoice[]
  rideTypeId: string
  evuId: string
  vehicles: VehicleDraft[]
  internalNote: string
}

type PlanningDraft = {
  driverReported: boolean | null
  pathStatus: string
  hireStatus: string
  shunting: string
  breaks: string
  furtherInfo: string
}

type RideEditorState = {
  draft: RideDraft
  baseline: string
  index: number
  originalType: CatalogValue | null
  originalEvu: CatalogValue | null
}

const OFFSETS: DayOffset[] = [-1, 0, 1]

export function RidePlanPanel({ event, onCancel }: { event: ClubEvent; onCancel?: () => void }) {
  const toast = useToast()
  const [plan, setPlan] = useState<RidePlan | null>(null)
  const [listed, setListed] = useState<ListedVehicle[]>([])
  const [planning, setPlanning] = useState<PlanningDraft>(emptyPlanning())
  const [warnings, setWarnings] = useState<string[]>([])
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [incomplete, setIncomplete] = useState<string | null>(null)
  const [rideError, setRideError] = useState<string | null>(null)
  const [rideFieldErrors, setRideFieldErrors] = useState<Record<string, string>>({})
  const [rideIncomplete, setRideIncomplete] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [rideBusy, setRideBusy] = useState(false)
  const [ready, setReady] = useState(false)
  const [baseline, setBaseline] = useState<string | null>(null)
  const [editor, setEditor] = useState<RideEditorState | null>(null)
  const modalOpen = editor !== null

  useEffect(() => {
    setEditor(null)
    setReady(false)
    setBaseline(null)
    let cancelled = false
    api<RidePlan>(`/api/v1/events/${event.id}/rides`)
      .then((body) => {
        if (cancelled) return
        setPlan(body)
        setListed(body.eventVehicles.map(toListed))
        setPlanning(toPlanning(body.planning))
        setWarnings(body.warnings)
        setReady(true)
      })
      .catch((reason: unknown) => {
        if (cancelled) return
        setError(reason instanceof ApiError ? reason.message : 'Die Fahrten konnten nicht geladen werden.')
      })
    return () => { cancelled = true }
  }, [event.id])

  useEffect(() => {
    if (!modalOpen) return
    const onKey = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return
      setEditor(null)
      setRideError(null)
      setRideFieldErrors({})
      setRideIncomplete(null)
    }
    const previous = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    window.addEventListener('keydown', onKey)
    return () => {
      document.body.style.overflow = previous
      window.removeEventListener('keydown', onKey)
    }
  }, [modalOpen])

  const pageSnapshot = JSON.stringify({ listed, planning })
  useEffect(() => {
    if (!ready) return
    setBaseline((current) => current ?? pageSnapshot)
  }, [ready, pageSnapshot])
  const pageDirty = baseline !== null && pageSnapshot !== baseline
  const modalDirty = editor !== null && JSON.stringify(editor.draft) !== editor.baseline
  useRegisterUnsaved(`rides-${event.id}`, pageDirty || modalDirty)

  function closeEditor() {
    setEditor(null)
    setRideError(null)
    setRideFieldErrors({})
    setRideIncomplete(null)
  }

  function patchEditor(patch: Partial<RideDraft>) {
    setEditor((current) => current ? { ...current, draft: { ...current.draft, ...patch } } : current)
  }

  function patchEditorVehicle(vehicleKey: string, patch: Partial<VehicleDraft>) {
    setEditor((current) => {
      if (!current) return current
      return {
        ...current,
        draft: {
          ...current.draft,
          vehicles: current.draft.vehicles.map((vehicle) => vehicle.key === vehicleKey ? { ...vehicle, ...patch } : vehicle),
        },
      }
    })
  }

  function cancel() {
    onCancel?.()
  }

  function rememberPlan(saved: RidePlan, keepLocalPage: boolean) {
    setPlan(saved)
    setWarnings(saved.warnings)
    if (keepLocalPage) return
    const nextListed = saved.eventVehicles.map(toListed)
    const nextPlanning = toPlanning(saved.planning)
    setListed(nextListed)
    setPlanning(nextPlanning)
    setBaseline(JSON.stringify({ listed: nextListed, planning: nextPlanning }))
  }

  async function savePage(submit: FormEvent) {
    submit.preventDefault()
    if (!plan) return
    const allowed = new Set(listed.filter((vehicle) => vehicle.vehicleId !== '').map((vehicle) => vehicle.vehicleId))
    const rideDrafts = plan.rides.map((ride) => {
      const draft = toDraft(ride)
      return { ...draft, vehicles: draft.vehicles.filter((vehicle) => allowed.has(vehicle.vehicleId)) }
    })
    if (rideDrafts.some((ride) => !ride.vehicles.some((vehicle) => vehicle.vehicleId !== ''))) {
      setError('Eine Fahrt braucht mindestens ein Fahrzeug aus der Fahrzeugliste des Anlasses.')
      setIncomplete(null)
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
          rides: rideDrafts.map(toPayload),
          planning: planningPayload(planning),
        }),
      })
      rememberPlan(saved, false)
      toast('Fahrtenplanung gespeichert.')
    } catch (reason) {
      if (reason instanceof ApiError && reason.message === 'Die Eingaben sind unvollständig.') {
        setIncomplete(incompleteMessage(labelsForRideErrors(reason.fields)))
        setFieldErrors(reason.fields ?? {})
      } else if (reason instanceof ApiError) {
        setError(reason.message)
        setFieldErrors(reason.fields ?? {})
      } else {
        setError('Die Fahrtenplanung konnte nicht gespeichert werden.')
      }
    } finally {
      setBusy(false)
    }
  }

  async function saveRide(submit: FormEvent) {
    submit.preventDefault()
    if (!plan || !editor) return
    const missing = missingRideFields([editor.draft])
    if (missing.length > 0) {
      setRideIncomplete(incompleteMessage(missing))
      setRideFieldErrors(rideFieldErrorsFor(editor.draft, editor.index))
      setRideError(null)
      return
    }
    const rides = plan.rides.map((ride) => toPayload(toDraft(ride)))
    const payload = toPayload(editor.draft)
    if (editor.draft.id) {
      const index = rides.findIndex((_, item) => plan.rides[item]?.id === editor.draft.id)
      if (index >= 0) rides[index] = payload
      else rides.push(payload)
    } else {
      rides.push(payload)
    }
    setRideBusy(true)
    setRideError(null)
    setRideIncomplete(null)
    setRideFieldErrors({})
    try {
      // Omitted rides are deleted, so the open ride is merged into the saved list.
      // Planning and the event vehicle list stay out of this request.
      const saved = await api<RidePlan>(`/api/v1/events/${event.id}/rides`, {
        method: 'PUT',
        body: JSON.stringify({ rides }),
      })
      const hadWarnings = saved.warnings.length > 0
      rememberPlan(saved, pageDirty)
      closeEditor()
      toast(hadWarnings ? 'Gespeichert. Bitte die Hinweise zur Doppelbelegung prüfen.' : 'Fahrt gespeichert.')
    } catch (reason) {
      if (reason instanceof ApiError && reason.message === 'Die Eingaben sind unvollständig.') {
        setRideIncomplete(incompleteMessage(labelsForRideErrors(reason.fields)))
        setRideFieldErrors(reason.fields ?? {})
      } else if (reason instanceof ApiError) {
        setRideError(reason.message)
        setRideFieldErrors(reason.fields ?? {})
      } else {
        setRideError('Die Fahrt konnte nicht gespeichert werden.')
      }
    } finally {
      setRideBusy(false)
    }
  }

  async function removeRide() {
    if (!plan || !editor?.draft.id) return
    const rides = plan.rides
      .filter((ride) => ride.id !== editor.draft.id)
      .map((ride) => toPayload(toDraft(ride)))
    setRideBusy(true)
    setRideError(null)
    try {
      const saved = await api<RidePlan>(`/api/v1/events/${event.id}/rides`, {
        method: 'PUT',
        body: JSON.stringify({ rides }),
      })
      rememberPlan(saved, pageDirty)
      closeEditor()
      toast('Fahrt entfernt.')
    } catch (reason) {
      setRideError(reason instanceof ApiError ? reason.message : 'Die Fahrt konnte nicht entfernt werden.')
    } finally {
      setRideBusy(false)
    }
  }

  if (!plan && !error) {
    return <p className="empty">Wird geladen …</p>
  }
  if (!plan) {
    return <div className="stack ride-plan">{error ? <div className="banner-error">{error}</div> : null}</div>
  }

  const canEdit = plan.canEdit === true
  const rides = plan.rides
  const fleet = plan.fleet
  const multiDay = event.days.length > 1
  const listedOptions = listed
    .map((item) => fleet.find((vehicle) => vehicle.id === item.vehicleId))
    .filter((vehicle): vehicle is FleetOption => vehicle !== undefined)
  const rowClass = `route-row route-ride${multiDay ? '' : ' single-day'}${canEdit ? ' with-action' : ''}`
  const headClass = `route-head route-ride${multiDay ? '' : ' single-day'}${canEdit ? ' with-action' : ''}`

  function openNew() {
    const draft = blankRide(event, listedOptions)
    setRideError(null)
    setRideFieldErrors({})
    setRideIncomplete(null)
    setEditor({
      draft,
      baseline: JSON.stringify(draft),
      index: rides.length,
      originalType: null,
      originalEvu: null,
    })
  }

  function openExisting(ride: Ride, index: number) {
    const draft = toDraft(ride)
    setRideError(null)
    setRideFieldErrors({})
    setRideIncomplete(null)
    setEditor({
      draft,
      baseline: JSON.stringify(draft),
      index,
      originalType: ride.rideType,
      originalEvu: ride.evu,
    })
  }

  const typeOptions = catalogChoices(plan.rideTypes, editor?.originalType ?? null)
  const evuOptions = catalogChoices(plan.evus, editor?.originalEvu ?? null)
  const editorVehicles = editor ? vehiclesForEditor(editor.draft, listedOptions, fleet) : []

  return (
    <div className="stack ride-plan">
      {error ? <div className="banner-error">{error}</div> : null}
      {warnings.length > 0 ? (
        <div className="banner-warn" role="status">
          {warnings.map((warning) => <p key={warning}>{warning}</p>)}
        </div>
      ) : null}
      <div className="panel ride-list">
        <div className="panel-head">
          <h2>Fahrten</h2>
          {canEdit ? <button className="btn light" type="button" onClick={openNew}>Fahrt hinzufügen</button> : null}
        </div>
        {rides.length === 0 ? <p className="empty">Noch keine Fahrt erfasst.</p> : (
          <>
            <div className={headClass}>
              {multiDay ? <span>Tag</span> : null}
              <span>Zug</span>
              <span>Strecke</span>
              <span>Fahrzeuge</span>
              <span>Abfahrt</span>
              <span>Ankunft</span>
              {canEdit ? <span /> : null}
            </div>
            {rides.map((ride, index) => {
              const day = event.days.find((item) => item.id === ride.eventDayId)
              return (
                <div className={rowClass} key={ride.id}>
                  {multiDay ? <span data-label="Tag">{day ? dayLabel(day.date) : 'Tag'}</span> : null}
                  <span data-label="Zug">
                    <strong>{ride.trainNumber}</strong>
                    {rideFacts(ride).map((fact, factIndex) => <small className="ride-meta" key={factIndex}>{fact}</small>)}
                  </span>
                  <span data-label="Strecke">
                    <strong className="route-line">
                      {routeStations(ride).map((station, stationIndex) => (
                        <StationMark key={`${station.didokCode}-${stationIndex}`} leading={stationIndex > 0} station={station} />
                      ))}
                    </strong>
                  </span>
                  <span data-label="Fahrzeuge">
                    <span className="ride-vehicles">
                      {ride.vehicles.map((vehicle) => (
                        <span className={`ride-vehicle${vehicle.external ? ' external' : ' club'}`} key={vehicle.id}>
                          {vehicle.label}
                          {vehicle.external ? <small>Extern</small> : null}
                        </span>
                      ))}
                    </span>
                  </span>
                  <span className="ride-clock" data-label="Abfahrt">{clockLabel(ride.departureTime, ride.departureOffset)}</span>
                  <span className="ride-clock" data-label="Ankunft">{clockLabel(ride.arrivalTime, ride.arrivalOffset)}</span>
                  {canEdit ? (
                    <span data-label="Bearbeiten">
                      <button className="btn small light" type="button" onClick={() => openExisting(ride, index)}>Bearbeiten</button>
                    </span>
                  ) : null}
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
        <form className="editor-form" onSubmit={(submit) => { void savePage(submit) }}>
          <div className="panel">
            <div className="panel-head">
              <h2>Allgemeine Fahrtenplanung</h2>
              <button className="btn primary small desktop-save" disabled={busy} type="submit">Speichern</button>
            </div>
            <div className="panel-inner">
              <div className="formrow cols3">
                <Field error={fieldErrors['planning.driverReported']} label="Lokführer gemeldet">
                  <select
                    className="input"
                    value={planning.driverReported === null ? '' : planning.driverReported ? 'yes' : 'no'}
                    onChange={(input) => setPlanning((current) => ({
                      ...current,
                      driverReported: input.target.value === 'yes' ? true : input.target.value === 'no' ? false : null,
                    }))}
                  >
                    <option value="">—</option>
                    <option value="yes">Ja</option>
                    <option value="no">Nein</option>
                  </select>
                </Field>
                <Field error={fieldErrors['planning.pathStatus']} label="Trassenplanung">
                  <StatusSelect
                    options={plan.pathStatuses}
                    value={planning.pathStatus}
                    onChange={(pathStatus) => setPlanning((current) => ({ ...current, pathStatus }))}
                  />
                </Field>
                <Field error={fieldErrors['planning.hireStatus']} label="Fahrzeugmiete">
                  <StatusSelect
                    options={plan.hireStatuses}
                    value={planning.hireStatus}
                    onChange={(hireStatus) => setPlanning((current) => ({ ...current, hireStatus }))}
                  />
                </Field>
              </div>
              <Field error={fieldErrors['planning.shunting']} label="Rangierplanung">
                <textarea className="input" value={planning.shunting} onChange={(input) => setPlanning((current) => ({ ...current, shunting: input.target.value }))} />
              </Field>
              <Field error={fieldErrors['planning.breaks']} label="Pausen">
                <textarea className="input" value={planning.breaks} onChange={(input) => setPlanning((current) => ({ ...current, breaks: input.target.value }))} />
              </Field>
              <Field error={fieldErrors['planning.furtherInfo']} label="Weitere Infos">
                <textarea className="input" value={planning.furtherInfo} onChange={(input) => setPlanning((current) => ({ ...current, furtherInfo: input.target.value }))} />
              </Field>
            </div>
          </div>
          <div className="panel">
            <div className="panel-head"><h2>Fahrzeuge des Anlasses</h2></div>
            <div className="panel-inner event-vehicles">
              {fieldErrors.eventVehicles ? <div className="field-error">{fieldErrors.eventVehicles}</div> : null}
              {listed.map((vehicle, index) => {
                const chosen = fleet.find((item) => item.id === vehicle.vehicleId)
                return (
                  <div key={vehicle.key}>
                    <div className={`event-vehicle-line${chosen ? (chosen.external ? ' external' : ' club') : ''}`}>
                      <VehiclePick
                        options={fleet.map((item) => ({ id: item.id, label: `${item.label}${item.external ? ' · Extern' : ''} · ${item.statusLabel}` }))}
                        value={vehicle.vehicleId}
                        onChange={(vehicleId) => setListed((current) => current.map((item) => item.key === vehicle.key ? { ...item, vehicleId } : item))}
                      />
                      {chosen?.external ? (
                        <input aria-label="Mietstatus" className="input" placeholder="Mietstatus" value={vehicle.rentalStatus} onChange={(input) => setListed((current) => current.map((item) => item.key === vehicle.key ? { ...item, rentalStatus: input.target.value } : item))} />
                      ) : null}
                      <button className="btn small light" type="button" onClick={() => setListed((current) => current.filter((item) => item.key !== vehicle.key))}>Entfernen</button>
                    </div>
                    {fieldErrors[`eventVehicles.${index}.vehicleId`] || fieldErrors[`eventVehicles.${index}.rentalStatus`] ? (
                      <div className="field-error">{fieldErrors[`eventVehicles.${index}.vehicleId`] ?? fieldErrors[`eventVehicles.${index}.rentalStatus`]}</div>
                    ) : null}
                  </div>
                )
              })}
              <button className="btn small light" type="button" onClick={() => setListed((current) => [...current, { key: crypto.randomUUID(), vehicleId: '', rentalStatus: '' }])}>Fahrzeug aufnehmen</button>
            </div>
          </div>
          {modalOpen ? null : <MobileSaveBar busy={busy} dirty={pageDirty} incomplete={incomplete} onCancel={cancel} />}
        </form>
      ) : (
        <PlanningRead planning={plan.planning} />
      )}
      {editor ? (
        <div className="ride-modal">
          <button className="ride-modal-backdrop" type="button" aria-label="Abbrechen" onClick={closeEditor} />
          <form
            aria-labelledby="ride-modal-title"
            aria-modal="true"
            className="ride-modal-card editor-form"
            role="dialog"
            onSubmit={(submit) => { void saveRide(submit) }}
          >
            <div className="panel-head">
              <h2 id="ride-modal-title">{editor.draft.id ? 'Fahrt bearbeiten' : 'Fahrt hinzufügen'}</h2>
            </div>
            <div className="ride-modal-body">
              {rideError ? <div className="banner-error">{rideError}</div> : null}
              {rideIncomplete ? <p className="save-incomplete">{rideIncomplete}</p> : null}
              {multiDay ? (
                <Field error={rideFieldErrors[`rides.${editor.index}.eventDayId`]} label="Veranstaltungstag">
                  <select className="input" value={editor.draft.eventDayId} onChange={(input) => patchEditor({ eventDayId: input.target.value })}>
                    {event.days.map((day) => <option key={day.id} value={day.id}>{dayLabel(day.date)}</option>)}
                  </select>
                </Field>
              ) : null}
              <Field error={rideFieldErrors[`rides.${editor.index}.trainNumber`]} label="Zugnummer">
                <input className="input" value={editor.draft.trainNumber} onChange={(input) => patchEditor({ trainNumber: input.target.value })} />
              </Field>
              <div className="formrow cols2">
                <StationField
                  error={rideFieldErrors[`rides.${editor.index}.origin`] ?? rideFieldErrors[`rides.${editor.index}.origin.didokCode`] ?? rideFieldErrors[`rides.${editor.index}.origin.name`]}
                  label="Startbahnhof"
                  value={editor.draft.origin}
                  onChange={(origin) => patchEditor({ origin })}
                />
                <StationField
                  error={rideFieldErrors[`rides.${editor.index}.destination`] ?? rideFieldErrors[`rides.${editor.index}.destination.didokCode`] ?? rideFieldErrors[`rides.${editor.index}.destination.name`]}
                  label="Zielbahnhof"
                  value={editor.draft.destination}
                  onChange={(destination) => patchEditor({ destination })}
                />
              </div>
              <ViaField
                error={viaError(rideFieldErrors, editor.index)}
                stations={editor.draft.vias}
                onChange={(vias) => patchEditor({ vias })}
              />
              <div className="formrow cols2">
                <Field error={rideFieldErrors[`rides.${editor.index}.rideTypeId`]} label="Art der Fahrt">
                  <select className="input" value={editor.draft.rideTypeId} onChange={(input) => patchEditor({ rideTypeId: input.target.value })}>
                    <option value="">—</option>
                    {typeOptions.map((type) => <option key={type.id} value={type.id}>{catalogOptionLabel(type)}</option>)}
                  </select>
                </Field>
                <Field error={rideFieldErrors[`rides.${editor.index}.evuId`]} label="EVU">
                  <select className="input" value={editor.draft.evuId} onChange={(input) => patchEditor({ evuId: input.target.value })}>
                    <option value="">—</option>
                    {evuOptions.map((evu) => <option key={evu.id} value={evu.id}>{catalogOptionLabel(evu)}</option>)}
                  </select>
                </Field>
              </div>
              <div className="formrow cols2">
                <Field error={rideFieldErrors[`rides.${editor.index}.departureTime`]} label="Abfahrt">
                  <input className="input" type="time" value={editor.draft.departureTime} onChange={(input) => patchEditor({ departureTime: input.target.value, departureOffset: input.target.value === '' ? null : (editor.draft.departureOffset ?? 0) })} />
                </Field>
                {editor.draft.departureTime !== '' ? (
                  <Field error={rideFieldErrors[`rides.${editor.index}.departureOffset`]} label="Tag der Abfahrt">
                    <OffsetSelect value={editor.draft.departureOffset ?? 0} onChange={(offset) => patchEditor({ departureOffset: offset })} />
                  </Field>
                ) : null}
              </div>
              <div className="formrow cols2">
                <Field error={rideFieldErrors[`rides.${editor.index}.arrivalTime`]} label="Ankunft">
                  <input className="input" type="time" value={editor.draft.arrivalTime} onChange={(input) => patchEditor({ arrivalTime: input.target.value, arrivalOffset: input.target.value === '' ? null : (editor.draft.arrivalOffset ?? 0) })} />
                </Field>
                {editor.draft.arrivalTime !== '' ? (
                  <Field error={rideFieldErrors[`rides.${editor.index}.arrivalOffset`]} label="Tag der Ankunft">
                    <OffsetSelect value={editor.draft.arrivalOffset ?? 0} onChange={(offset) => patchEditor({ arrivalOffset: offset })} />
                  </Field>
                ) : null}
              </div>
              <div className="formfield">
                <label>Fahrzeuge</label>
                {rideFieldErrors[`rides.${editor.index}.vehicles`] ? <div className="field-error">{rideFieldErrors[`rides.${editor.index}.vehicles`]}</div> : null}
                {editorVehicles.length === 0 ? <p className="muted">Zuerst ein Fahrzeug in die Liste des Anlasses aufnehmen.</p> : null}
                {editor.draft.vehicles.map((vehicle, vehicleIndex) => (
                  <div className="vehicle-line" key={vehicle.key}>
                    <VehiclePick
                      options={editorVehicles.map((option) => ({ id: option.id, label: `${option.label}${option.external ? ' · Extern' : ''}` }))}
                      value={vehicle.vehicleId}
                      onChange={(vehicleId) => patchEditorVehicle(vehicle.key, { vehicleId })}
                    />
                    <button className="btn small light" type="button" onClick={() => patchEditor({ vehicles: editor.draft.vehicles.filter((item) => item.key !== vehicle.key) })}>Entfernen</button>
                    {rideFieldErrors[`rides.${editor.index}.vehicles.${vehicleIndex}.vehicleId`] ? (
                      <div className="field-error vehicle-line-error">{rideFieldErrors[`rides.${editor.index}.vehicles.${vehicleIndex}.vehicleId`]}</div>
                    ) : null}
                  </div>
                ))}
                <button className="btn small light" type="button" onClick={() => patchEditor({ vehicles: [...editor.draft.vehicles, blankVehicle(editorVehicles)] })}>Fahrzeug hinzufügen</button>
              </div>
              <Field error={rideFieldErrors[`rides.${editor.index}.internalNote`]} label="Interne Notiz">
                <textarea className="input" value={editor.draft.internalNote} onChange={(input) => patchEditor({ internalNote: input.target.value })} />
              </Field>
            </div>
            <div className="ride-modal-actions">
              <button className="btn primary" disabled={rideBusy} type="submit">Speichern</button>
              <button className="btn light" type="button" onClick={closeEditor}>Abbrechen</button>
              {editor.draft.id ? <button className="btn small light" disabled={rideBusy} type="button" onClick={() => { void removeRide() }}>Fahrt entfernen</button> : null}
            </div>
          </form>
        </div>
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
      {value ? <p className="station-chosen">{stationSelectLabel(value)}</p> : <p className="station-chosen">Noch kein Bahnhof gewählt.</p>}
      <input aria-label={`${label} suchen`} className="input" placeholder="Name oder Kürzel" value={query} onChange={(input) => setQuery(input.target.value)} />
      {searching ? <p className="muted">Suche …</p> : null}
      {!searching && settled && query.trim().length >= 1 && results.length === 0 && !searchError ? <p className="muted">Keine Zugshaltestelle gefunden.</p> : null}
      {results.length > 0 ? (
        <ul className="station-results">
          {results.map((station) => (
            <li key={station.id ?? station.didokCode}>
              <button type="button" onClick={() => { onChange({ didokCode: station.didokCode, name: station.name, abbreviation: station.abbreviation }); setQuery(''); setResults([]) }}>
                <span>{stationSelectLabel(station)}</span>
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

function ViaField({ stations, onChange, error }: { stations: StationChoice[]; onChange: (stations: StationChoice[]) => void; error?: string }) {
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
    <Field error={error ?? searchError ?? undefined} label="Via-Bahnhöfe">
      {stations.length === 0 ? <p className="muted">Keine Via-Bahnhöfe.</p> : null}
      {stations.map((station, index) => (
        <div className="via-line" key={`${station.didokCode}-${index}`}>
          <span className="via-name">{stationSelectLabel(station)}</span>
          <button className="btn small light" disabled={index === 0} type="button" onClick={() => onChange(moveVia(stations, index, -1))}>Hoch</button>
          <button className="btn small light" disabled={index === stations.length - 1} type="button" onClick={() => onChange(moveVia(stations, index, 1))}>Runter</button>
          <button className="btn small light" type="button" onClick={() => onChange(stations.filter((_, item) => item !== index))}>Entfernen</button>
        </div>
      ))}
      <input aria-label="Via-Bahnhof suchen" className="input" placeholder="Name oder Kürzel" value={query} onChange={(input) => setQuery(input.target.value)} />
      {searching ? <p className="muted">Suche …</p> : null}
      {!searching && settled && query.trim().length >= 1 && results.length === 0 && !searchError ? <p className="muted">Keine Zugshaltestelle gefunden.</p> : null}
      {results.length > 0 ? (
        <ul className="station-results">
          {results.map((station) => (
            <li key={station.id ?? station.didokCode}>
              <button type="button" onClick={() => {
                onChange([...stations, { didokCode: station.didokCode, name: station.name, abbreviation: station.abbreviation }])
                setQuery('')
                setResults([])
              }}>
                <span>{stationSelectLabel(station)}</span>
              </button>
            </li>
          ))}
        </ul>
      ) : null}
    </Field>
  )
}

function StatusSelect({ options, value, onChange }: { options: StatusOption[]; value: string; onChange: (value: string) => void }) {
  return (
    <select className="input" value={value} onChange={(input) => onChange(input.target.value)}>
      <option value="">—</option>
      {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
    </select>
  )
}

function PlanningRead({ planning }: { planning: RidePlanning }) {
  const driver = planning.driverReported === true ? 'Ja' : planning.driverReported === false ? 'Nein' : '—'
  return (
    <div className="panel">
      <div className="panel-head"><h2>Allgemeine Fahrtenplanung</h2></div>
      <div className="panel-inner">
        <dl className="kv planning-read">
          <dt>Lokführer gemeldet</dt>
          <dd>{driver}</dd>
          <dt>Trassenplanung</dt>
          <dd>{planning.pathStatusLabel ?? '—'}</dd>
          <dt>Fahrzeugmiete</dt>
          <dd>{planning.hireStatusLabel ?? '—'}</dd>
          <dt>Rangierplanung</dt>
          <dd>{planning.shunting?.trim() ? planning.shunting : '—'}</dd>
          <dt>Pausen</dt>
          <dd>{planning.breaks?.trim() ? planning.breaks : '—'}</dd>
          <dt>Weitere Infos</dt>
          <dd>{planning.furtherInfo?.trim() ? planning.furtherInfo : '—'}</dd>
        </dl>
      </div>
    </div>
  )
}

function stationSelectLabel(station: { name: string; abbreviation?: string | null }): string {
  const abbreviation = station.abbreviation?.trim()
  return abbreviation ? `${abbreviation} ${station.name}` : station.name
}

function StationMark({ station, leading = false }: { station: StationChoice; leading?: boolean }) {
  const abbreviation = station.abbreviation?.trim() ?? ''
  const name = station.name.trim()
  const shown = abbreviation !== '' ? abbreviation : (name !== '' ? name : '—')
  return (
    <>
      {leading ? <span className="route-sep"> → </span> : null}
      <abbr className="station-abbr" title={name !== '' ? name : undefined}>{shown}</abbr>
    </>
  )
}

function routeStations(ride: Ride): StationChoice[] {
  return [ride.origin, ...(ride.vias ?? []), ride.destination]
}

function rideFacts(ride: Ride): string[] {
  return [ride.rideType?.name, ride.evu?.name].flatMap((value) => {
    const text = value?.trim() ?? ''
    return text === '' ? [] : [text]
  })
}

function catalogChoices(options: CatalogValue[], original: CatalogValue | null): CatalogValue[] {
  const active = options.filter((item) => item.active !== false)
  if (original && original.active === false && !active.some((item) => item.id === original.id)) {
    return [{ ...original, active: false }, ...active]
  }
  return active
}

function catalogOptionLabel(item: CatalogValue): string {
  return item.active === false ? `${item.name} (inaktiv)` : item.name
}

function vehiclesForEditor(ride: RideDraft, listedOptions: FleetOption[], fleet: FleetOption[]): FleetOption[] {
  const byId = new Map(listedOptions.map((item) => [item.id, item]))
  for (const vehicle of ride.vehicles) {
    if (!vehicle.vehicleId || byId.has(vehicle.vehicleId)) continue
    const known = fleet.find((item) => item.id === vehicle.vehicleId)
    if (known) byId.set(known.id, known)
  }
  return [...byId.values()]
}

function moveVia(stations: StationChoice[], index: number, direction: -1 | 1): StationChoice[] {
  const target = index + direction
  if (target < 0 || target >= stations.length) return stations
  const next = stations.slice()
  const [item] = next.splice(index, 1)
  if (!item) return stations
  next.splice(target, 0, item)
  return next
}

function viaError(fields: Record<string, string>, index: number): string | undefined {
  const prefix = `rides.${index}.vias`
  if (fields[prefix]) return fields[prefix]
  const key = Object.keys(fields).find((item) => item.startsWith(`${prefix}.`))
  return key ? fields[key] : undefined
}

function emptyPlanning(): PlanningDraft {
  return {
    driverReported: null,
    pathStatus: '',
    hireStatus: '',
    shunting: '',
    breaks: '',
    furtherInfo: '',
  }
}

function planningPayload(planning: PlanningDraft) {
  return {
    driverReported: planning.driverReported,
    pathStatus: planning.pathStatus === '' ? null : planning.pathStatus,
    hireStatus: planning.hireStatus === '' ? null : planning.hireStatus,
    shunting: planning.shunting,
    breaks: planning.breaks,
    furtherInfo: planning.furtherInfo,
  }
}

function toPlanning(planning: RidePlanning | undefined): PlanningDraft {
  return {
    driverReported: planning?.driverReported ?? null,
    pathStatus: planning?.pathStatus ?? '',
    hireStatus: planning?.hireStatus ?? '',
    shunting: planning?.shunting ?? '',
    breaks: planning?.breaks ?? '',
    furtherInfo: planning?.furtherInfo ?? '',
  }
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
    vias: [],
    rideTypeId: '',
    evuId: '',
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
    vias: ride.vias ?? [],
    rideTypeId: ride.rideType?.id ?? '',
    evuId: ride.evu?.id ?? '',
    vehicles: ride.vehicles.map((vehicle) => ({
      key: vehicle.id,
      vehicleId: vehicle.vehicleId ?? '',
    })),
    internalNote: ride.internalNote ?? '',
  }
}

function toPayload(ride: RideDraft) {
  return {
    id: ride.id,
    eventDayId: ride.eventDayId,
    departureTime: ride.departureTime === '' ? null : ride.departureTime,
    departureOffset: ride.departureTime === '' ? null : ride.departureOffset,
    arrivalTime: ride.arrivalTime === '' ? null : ride.arrivalTime,
    arrivalOffset: ride.arrivalTime === '' ? null : ride.arrivalOffset,
    trainNumber: ride.trainNumber,
    origin: ride.origin,
    destination: ride.destination,
    vias: ride.vias,
    rideTypeId: ride.rideTypeId === '' ? null : ride.rideTypeId,
    evuId: ride.evuId === '' ? null : ride.evuId,
    vehicles: ride.vehicles
      .filter((vehicle) => vehicle.vehicleId !== '')
      .map((vehicle) => ({ vehicleId: vehicle.vehicleId })),
    internalNote: ride.internalNote,
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

function rideFieldErrorsFor(ride: RideDraft, index: number): Record<string, string> {
  const next: Record<string, string> = {}
  if (ride.trainNumber.trim() === '') next[`rides.${index}.trainNumber`] = 'Bitte eine Zugnummer angeben.'
  if (ride.origin === null) next[`rides.${index}.origin`] = 'Bitte den Startbahnhof aus der Suche wählen.'
  if (ride.destination === null) next[`rides.${index}.destination`] = 'Bitte den Zielbahnhof aus der Suche wählen.'
  if (!ride.vehicles.some((vehicle) => vehicle.vehicleId !== '')) next[`rides.${index}.vehicles`] = 'Bitte mindestens ein Fahrzeug angeben.'
  return next
}

function labelsForRideErrors(fields: Record<string, string> | undefined): string[] {
  if (!fields) return []
  return Object.keys(fields).map((key) => {
    if (key.includes('driverReported')) return 'Lokführer gemeldet'
    if (key.includes('pathStatus')) return 'Trassenplanung'
    if (key.includes('hireStatus')) return 'Fahrzeugmiete'
    if (key.includes('shunting')) return 'Rangierplanung'
    if (key.includes('breaks')) return 'Pausen'
    if (key.includes('furtherInfo')) return 'Weitere Infos'
    if (key.includes('trainNumber')) return 'Zugnummer'
    if (key.includes('vias')) return 'Via-Bahnhof'
    if (key.includes('rideType')) return 'Art der Fahrt'
    if (key.includes('evu')) return 'EVU'
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
