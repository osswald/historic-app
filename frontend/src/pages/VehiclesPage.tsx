import { useEffect, useState, type FormEvent } from 'react'
import { api, ApiError } from '../api/client'
import type { FleetList, FleetVehicle, VehicleStatus } from '../api/types'
import { AuditHistory } from '../components/AuditHistory'
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
  external: boolean
  owner: string
  nvr: string
  p1On: string
  p5On: string
  oilOn: string
  nextMaintenanceOn: string
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
          <div className="hello">{technical ? 'Interner Überblick mit Status und letztem Batterieladen.' : 'Namen, Nummern und Batterieladungen der Fahrzeuge.'}</div>
        </div>
        {list?.canEdit ? <button className="btn primary" type="button" onClick={() => setCreating(true)}>+ Fahrzeug</button> : null}
      </div>
      {error ? <div className="banner-error">{error}</div> : null}
      <div className="note" style={{ marginBottom: 16 }}>
        {technical
          ? 'ZEDAS bleibt das Wartungssystem. Hier stehen Status, P1, P5, Öl, das nächste Wartungsdatum, Batterieladung und Zählerstände.'
          : 'ZEDAS bleibt das Wartungssystem. Jedes Mitglied kann eine Batterieladung erfassen. Technische Angaben sieht die Technische Kommission.'}
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
          <VehicleCard canEdit={list.canEdit} canRecordBattery={list.canRecordBattery} key={vehicle.id} technical={technical} vehicle={vehicle} onChange={replace} />
        ))}
      </div>
    </section>
  )
}

function VehicleCard({ vehicle, technical, canEdit, canRecordBattery, onChange }: { vehicle: FleetVehicle; technical: boolean; canEdit: boolean; canRecordBattery: boolean; onChange: (vehicle: FleetVehicle) => void }) {
  const toast = useToast()
  const [open, setOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [chargedOn, setChargedOn] = useState(zurichToday())
  const latestBattery = vehicle.batteryCharges?.[0]

  async function charge(submit?: FormEvent) {
    submit?.preventDefault()
    setBusy(true)
    setError(null)
    try {
      const saved = await api<FleetVehicle>(`/api/v1/vehicles/${vehicle.id}/battery-charges`, {
        method: 'POST',
        body: JSON.stringify({ chargedOn }),
      })
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
      <h3>{vehicle.label}{vehicle.external ? ' · Extern' : ''} <span className={pillClass(vehicle.status)}>{vehicle.statusLabel}</span></h3>
      {technical && vehicle.tracksMotorHours === false ? <p>Wagen ohne Motorstunden-Zähler.</p> : null}
      <p>{latestBattery ? `Letzte Batterieladung: ${formatDate(latestBattery.chargedOn)}` : 'Noch keine Batterieladung erfasst.'}</p>
      {error ? <div className="field-error">{error}</div> : null}
      {canRecordBattery ? (
        <form className="vehicle-actions" onSubmit={(submit) => { void charge(submit) }}>
          <input aria-label="Datum der Batterieladung" className="input" max={zurichToday()} type="date" value={chargedOn} onChange={(input) => setChargedOn(input.target.value)} />
          <button className="btn small light" disabled={busy} type="submit">Batterien geladen</button>
          {canEdit ? <button className="btn small light" type="button" onClick={() => setOpen((value) => !value)}>{open ? 'Schliessen' : 'Details'}</button> : null}
        </form>
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
      <p className="muted">P1 {formatOptional(vehicle.p1On)} · P5 {formatOptional(vehicle.p5On)} · Öl {formatOptional(vehicle.oilOn)} · Nächste Wartung {formatOptional(vehicle.nextMaintenanceOn)}</p>
      {vehicle.owner ? <p>Eigentümerkontakt: {vehicle.owner}</p> : null}
      {vehicle.nvr ? <p>NVR: {vehicle.nvr}</p> : null}
      {(vehicle.meterReadings ?? []).length > 0 ? (
        <ul className="meter-list">
          {(vehicle.meterReadings ?? []).map((reading) => (
            <li key={reading.id}>{reading.kindLabel}: {reading.value} · {formatDate(reading.readOn)} · {reading.recordedBy}</li>
          ))}
        </ul>
      ) : <p>Noch keine Zählerstände erfasst.</p>}
      <VehicleForm
        initial={draftFrom(vehicle)}
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
          external: draft.external,
          owner: draft.owner === '' ? null : draft.owner,
          nvr: draft.nvr === '' ? null : draft.nvr,
          p1On: draft.p1On === '' ? null : draft.p1On,
          p5On: draft.p5On === '' ? null : draft.p5On,
          oilOn: draft.oilOn === '' ? null : draft.oilOn,
          nextMaintenanceOn: draft.nextMaintenanceOn === '' ? null : draft.nextMaintenanceOn,
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
        <input checked={draft.external} type="checkbox" onChange={(input) => setDraft({ ...draft, external: input.target.checked })} />
        Extern
      </label>
      <div className="formfield">
        <label>Eigentümerkontakt</label>
        <input className="input" value={draft.owner} onChange={(input) => setDraft({ ...draft, owner: input.target.value })} />
        {fieldErrors.owner ? <div className="field-error">{fieldErrors.owner}</div> : null}
      </div>
      <div className="formfield">
        <label>NVR</label>
        <input className="input" value={draft.nvr} onChange={(input) => setDraft({ ...draft, nvr: input.target.value })} />
        {fieldErrors.nvr ? <div className="field-error">{fieldErrors.nvr}</div> : null}
      </div>
      <label className="toggle">
        <input checked={draft.tracksMotorHours} type="checkbox" onChange={(input) => setDraft({ ...draft, tracksMotorHours: input.target.checked })} />
        Motorstunden-Zähler
      </label>
      <div className="formrow cols2">
        <div className="formfield">
          <label>P1</label>
          <input className="input" type="date" value={draft.p1On} onChange={(input) => setDraft({ ...draft, p1On: input.target.value })} />
        </div>
        <div className="formfield">
          <label>P5</label>
          <input className="input" type="date" value={draft.p5On} onChange={(input) => setDraft({ ...draft, p5On: input.target.value })} />
        </div>
      </div>
      <div className="formrow cols2">
        <div className="formfield">
          <label>Öl</label>
          <input className="input" type="date" value={draft.oilOn} onChange={(input) => setDraft({ ...draft, oilOn: input.target.value })} />
        </div>
        <div className="formfield">
          <label>Nächste Wartung</label>
          <input className="input" type="date" value={draft.nextMaintenanceOn} onChange={(input) => setDraft({ ...draft, nextMaintenanceOn: input.target.value })} />
        </div>
      </div>
      <div className="formfield">
        <label>Technische Notiz</label>
        <textarea className="input" value={draft.technicalNote} onChange={(input) => setDraft({ ...draft, technicalNote: input.target.value })} />
        {draft.status !== 'ready' ? <p className="muted">Bei Eingeschränkt und Gesperrt ist ein Grund erforderlich. Die Zuteilung bleibt erlaubt.</p> : null}
        {fieldErrors.technicalNote ? <div className="field-error">{fieldErrors.technicalNote}</div> : null}
      </div>
      {vehicleId ? <AuditHistory entityId={vehicleId} entityType="vehicle" /> : null}
      <div className="vehicle-actions">
        <button className="btn primary small" disabled={busy} type="submit">Speichern</button>
        {onCancel ? <button className="btn small light" type="button" onClick={onCancel}>Abbrechen</button> : null}
      </div>
    </form>
  )
}

function emptyDraft(): Draft {
  return { name: '', number: '', status: 'ready', tracksMotorHours: true, technicalNote: '', external: false, owner: '', nvr: '', p1On: '', p5On: '', oilOn: '', nextMaintenanceOn: '' }
}

function draftFrom(vehicle: FleetVehicle): Draft {
  return {
    name: vehicle.name,
    number: vehicle.number ?? '',
    status: vehicle.status,
    tracksMotorHours: vehicle.tracksMotorHours !== false,
    technicalNote: vehicle.technicalNote ?? '',
    external: vehicle.external,
    owner: vehicle.owner ?? '',
    nvr: vehicle.nvr ?? '',
    p1On: vehicle.p1On ?? '',
    p5On: vehicle.p5On ?? '',
    oilOn: vehicle.oilOn ?? '',
    nextMaintenanceOn: vehicle.nextMaintenanceOn ?? '',
  }
}

function formatOptional(value: string | null | undefined): string {
  return value ? formatDate(value) : '—'
}

function zurichToday(): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: 'Europe/Zurich', year: 'numeric', month: '2-digit', day: '2-digit' }).format(new Date())
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
