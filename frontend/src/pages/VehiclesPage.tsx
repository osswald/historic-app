import { useEffect, useState, type FormEvent } from 'react'
import { api, ApiError } from '../api/client'
import type { FleetList, FleetVehicle, VehicleStatus } from '../api/types'
import { useToast } from '../shell/context'

const STATUSES: Array<{ value: VehicleStatus; label: string }> = [
  { value: 'ready', label: 'Einsatzbereit' },
  { value: 'limited', label: 'Eingeschränkt' },
  { value: 'blocked', label: 'Gesperrt' },
]

const MONTHS = [
  'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
  'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
]

type Draft = {
  name: string
  number: string
  status: VehicleStatus
  tracksMotorHours: boolean
  technicalNote: string
}

export function VehiclesPage() {
  const toast = useToast()
  const [list, setList] = useState<FleetList | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)

  useEffect(() => {
    api<FleetList>('/api/v1/vehicles')
      .then(setList)
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Fahrzeuge konnten nicht geladen werden.'))
  }, [])

  function replace(vehicle: FleetVehicle) {
    setList((current) => current ? {
      ...current,
      vehicles: current.vehicles.some((item) => item.id === vehicle.id)
        ? current.vehicles.map((item) => item.id === vehicle.id ? vehicle : item)
        : [...current.vehicles, vehicle],
    } : current)
  }

  const technical = list?.canViewTechnical === true

  return (
    <section>
      <div className="heading">
        <div>
          <div className="eyebrow">Fahrzeugstamm</div>
          <h1>Historische Fahrzeuge</h1>
          <div className="hello">{technical ? 'Interner Überblick mit Status und letztem Batterieladen.' : 'Namen und Nummern der Vereinsfahrzeuge.'}</div>
        </div>
        {list?.canEdit ? <button className="btn primary" type="button" onClick={() => setCreating(true)}>+ Fahrzeug</button> : null}
      </div>
      {error ? <div className="banner-error">{error}</div> : null}
      <div className="note" style={{ marginBottom: 16 }}>
        {technical
          ? 'ZEDAS bleibt das Wartungssystem. Hier stehen Status, Batterieladung und Zählerstände.'
          : 'ZEDAS bleibt das Wartungssystem. Batterieladung und Zählerstände sieht die Technische Kommission.'}
      </div>
      {creating && list?.canEdit ? (
        <VehicleForm
          title="Fahrzeug erfassen"
          initial={emptyDraft()}
          onCancel={() => setCreating(false)}
          onSaved={(vehicle) => {
            replace(vehicle)
            setCreating(false)
            toast('Fahrzeug erfasst.')
          }}
        />
      ) : null}
      {!list && !error ? <p className="empty">Wird geladen …</p> : null}
      <div className="inline-cards">
        {list?.vehicles.map((vehicle) => (
          <VehicleCard canEdit={list.canEdit} key={vehicle.id} technical={technical} vehicle={vehicle} onChange={replace} />
        ))}
      </div>
    </section>
  )
}

function VehicleCard({ vehicle, technical, canEdit, onChange }: { vehicle: FleetVehicle; technical: boolean; canEdit: boolean; onChange: (vehicle: FleetVehicle) => void }) {
  const toast = useToast()
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const latestBattery = vehicle.batteryCharges?.[0]

  async function charge() {
    setBusy(true)
    setError(null)
    try {
      const saved = await api<FleetVehicle>(`/api/v1/vehicles/${vehicle.id}/battery-charges`, { method: 'POST', body: '{}' })
      onChange(saved)
      toast('Batterieladung protokolliert.')
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Die Batterieladung konnte nicht gespeichert werden.')
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="vehicle">
      <h3>{vehicle.label} <span className={pillClass(vehicle.status)}>{vehicle.statusLabel}</span></h3>
      {technical && vehicle.tracksMotorHours === false ? <p>Wagen ohne Motorstunden-Zähler.</p> : null}
      {technical ? <p>{latestBattery ? `Letzte Batterieladung: ${formatDate(latestBattery.chargedOn)}` : 'Noch keine Batterieladung erfasst.'}</p> : null}
      {error ? <div className="field-error">{error}</div> : null}
      {canEdit ? (
        <div className="vehicle-actions">
          <button className="btn small light" disabled={busy} type="button" onClick={() => { void charge() }}>Batterien geladen</button>
          <button className="btn small light" type="button" onClick={() => setOpen((value) => !value)}>{open ? 'Schliessen' : 'Details'}</button>
        </div>
      ) : null}
      {open && technical ? <VehicleDetails vehicle={vehicle} onChange={onChange} /> : null}
    </div>
  )
}

function VehicleDetails({ vehicle, onChange }: { vehicle: FleetVehicle; onChange: (vehicle: FleetVehicle) => void }) {
  const toast = useToast()
  const [kind, setKind] = useState<'motor_hours' | 'kilometres'>(vehicle.tracksMotorHours === false ? 'kilometres' : 'motor_hours')
  const [value, setValue] = useState('')
  const [readOn, setReadOn] = useState(today())
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  async function record(submit: FormEvent) {
    submit.preventDefault()
    setBusy(true)
    setError(null)
    setFieldErrors({})
    try {
      const saved = await api<FleetVehicle>(`/api/v1/vehicles/${vehicle.id}/meter-readings`, {
        method: 'POST',
        body: JSON.stringify({ kind, value, readOn }),
      })
      onChange(saved)
      setValue('')
      toast('Zählerstand erfasst.')
    } catch (reason) {
      if (reason instanceof ApiError) {
        setError(reason.message)
        setFieldErrors(reason.fields ?? {})
      } else {
        setError('Der Zählerstand konnte nicht gespeichert werden.')
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="vehicle-details">
      {vehicle.technicalNote ? <p>{vehicle.technicalNote}</p> : null}
      <form onSubmit={(submit) => { void record(submit) }}>
        <div className="formfield">
          <label>Zählerstand</label>
          <div className="vehicle-line">
            <select className="input" value={kind} onChange={(input) => setKind(input.target.value === 'kilometres' ? 'kilometres' : 'motor_hours')}>
              {vehicle.tracksMotorHours === false ? null : <option value="motor_hours">Motorstunden</option>}
              <option value="kilometres">Kilometer</option>
            </select>
            <input className="input" inputMode="decimal" value={value} onChange={(input) => setValue(input.target.value)} />
            <input className="input" type="date" value={readOn} onChange={(input) => setReadOn(input.target.value)} />
          </div>
          {fieldErrors.kind || fieldErrors.value || fieldErrors.readOn ? <div className="field-error">{fieldErrors.kind ?? fieldErrors.value ?? fieldErrors.readOn}</div> : null}
          {error ? <div className="field-error">{error}</div> : null}
          <button className="btn small light" disabled={busy} type="submit">Zählerstand erfassen</button>
        </div>
      </form>
      {(vehicle.meterReadings ?? []).length > 0 ? (
        <ul className="meter-list">
          {(vehicle.meterReadings ?? []).map((reading) => (
            <li key={reading.id}>{reading.kindLabel}: {reading.value} · {formatDate(reading.readOn)} · {reading.recordedBy}</li>
          ))}
        </ul>
      ) : <p>Noch keine Zählerstände erfasst.</p>}
      <VehicleForm
        initial={{
          name: vehicle.name,
          number: vehicle.number ?? '',
          status: vehicle.status,
          tracksMotorHours: vehicle.tracksMotorHours !== false,
          technicalNote: vehicle.technicalNote ?? '',
        }}
        title="Stammdaten"
        vehicleId={vehicle.id}
        onSaved={(saved) => {
          onChange(saved)
          toast('Fahrzeug gespeichert.')
        }}
      />
    </div>
  )
}

function VehicleForm({ title, initial, vehicleId, onSaved, onCancel }: { title: string; initial: Draft; vehicleId?: string; onSaved: (vehicle: FleetVehicle) => void; onCancel?: () => void }) {
  const [draft, setDraft] = useState(initial)
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  async function save(submit: FormEvent) {
    submit.preventDefault()
    setBusy(true)
    setError(null)
    setFieldErrors({})
    try {
      const saved = await api<FleetVehicle>(vehicleId ? `/api/v1/vehicles/${vehicleId}` : '/api/v1/vehicles', {
        method: vehicleId ? 'PUT' : 'POST',
        body: JSON.stringify({
          name: draft.name,
          number: draft.number === '' ? null : draft.number,
          status: draft.status,
          tracksMotorHours: draft.tracksMotorHours,
          technicalNote: draft.technicalNote === '' ? null : draft.technicalNote,
        }),
      })
      onSaved(saved)
    } catch (reason) {
      if (reason instanceof ApiError) {
        setError(reason.message)
        setFieldErrors(reason.fields ?? {})
      } else {
        setError('Das Fahrzeug konnte nicht gespeichert werden.')
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <form className="vehicle-form" onSubmit={(submit) => { void save(submit) }}>
      <h3>{title}</h3>
      {error ? <div className="field-error">{error}</div> : null}
      <div className="formfield">
        <label>Name</label>
        <input className="input" value={draft.name} onChange={(input) => setDraft({ ...draft, name: input.target.value })} />
        {fieldErrors.name ? <div className="field-error">{fieldErrors.name}</div> : null}
      </div>
      <div className="formfield">
        <label>Nummer</label>
        <input className="input" value={draft.number} onChange={(input) => setDraft({ ...draft, number: input.target.value })} />
        {fieldErrors.number ? <div className="field-error">{fieldErrors.number}</div> : null}
      </div>
      <div className="formfield">
        <label>Status</label>
        <select className="input" value={draft.status} onChange={(input) => setDraft({ ...draft, status: input.target.value as VehicleStatus })}>
          {STATUSES.map((status) => <option key={status.value} value={status.value}>{status.label}</option>)}
        </select>
        {fieldErrors.status ? <div className="field-error">{fieldErrors.status}</div> : null}
      </div>
      <label className="toggle">
        <input checked={draft.tracksMotorHours} type="checkbox" onChange={(input) => setDraft({ ...draft, tracksMotorHours: input.target.checked })} />
        Motorstunden-Zähler
      </label>
      <div className="formfield">
        <label>Technische Notiz</label>
        <textarea className="input" value={draft.technicalNote} onChange={(input) => setDraft({ ...draft, technicalNote: input.target.value })} />
        {fieldErrors.technicalNote ? <div className="field-error">{fieldErrors.technicalNote}</div> : null}
      </div>
      <div className="vehicle-actions">
        <button className="btn primary small" disabled={busy} type="submit">Speichern</button>
        {onCancel ? <button className="btn small light" type="button" onClick={onCancel}>Abbrechen</button> : null}
      </div>
    </form>
  )
}

function emptyDraft(): Draft {
  return { name: '', number: '', status: 'ready', tracksMotorHours: true, technicalNote: '' }
}

function pillClass(status: VehicleStatus): string {
  if (status === 'limited') return 'pill orange'
  if (status === 'blocked') return 'pill red'
  return 'pill green'
}

function formatDate(value: string): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value)
  if (!match) return value
  const month = MONTHS[Number(match[2]) - 1]
  if (!month) return value
  return `${Number(match[3])}. ${month} ${match[1]}`
}

function today(): string {
  const date = new Date()
  const month = String(date.getMonth() + 1).padStart(2, '0')
  const day = String(date.getDate()).padStart(2, '0')
  return `${date.getFullYear()}-${month}-${day}`
}
