import { useEffect, useState, type FormEvent } from 'react'
import { api, ApiError } from '../api/client'
import type { CatalogValue } from '../api/types'
import { useToast } from '../shell/context'

type CatalogKind = 'type' | 'evu'
type Catalog = {
  rideTypes: CatalogValue[]
  evus: CatalogValue[]
}

export function RideCatalogSettingsPage() {
  const toast = useToast()
  const [catalog, setCatalog] = useState<Catalog | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [typeName, setTypeName] = useState('')
  const [evuName, setEvuName] = useState('')
  const [typeError, setTypeError] = useState<string | null>(null)
  const [evuError, setEvuError] = useState<string | null>(null)
  const [rowErrors, setRowErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    api<Catalog>('/api/v1/ride-catalog')
      .then((body) => {
        if (!cancelled) setCatalog(body)
      })
      .catch((reason: unknown) => {
        if (!cancelled) setError(reason instanceof ApiError ? reason.message : 'Die Listen konnten nicht geladen werden.')
      })
    return () => { cancelled = true }
  }, [])

  function replace(kind: CatalogKind, value: CatalogValue) {
    setCatalog((current) => {
      if (!current) return current
      const key = kind === 'type' ? 'rideTypes' : 'evus'
      return { ...current, [key]: current[key].map((item) => item.id === value.id ? value : item) }
    })
  }

  function drop(kind: CatalogKind, id: string) {
    setCatalog((current) => {
      if (!current) return current
      const key = kind === 'type' ? 'rideTypes' : 'evus'
      return { ...current, [key]: current[key].filter((item) => item.id !== id) }
    })
  }

  async function add(kind: CatalogKind, submit?: FormEvent) {
    submit?.preventDefault()
    const name = (kind === 'type' ? typeName : evuName).trim()
    const setFieldError = kind === 'type' ? setTypeError : setEvuError
    if (name === '') {
      setFieldError('Bitte einen Namen angeben.')
      return
    }
    setBusy(kind)
    setFieldError(null)
    try {
      const created = await api<CatalogValue>(kind === 'type' ? '/api/v1/ride-types' : '/api/v1/evus', {
        method: 'POST',
        body: JSON.stringify({ name }),
      })
      setCatalog((current) => {
        if (!current) return current
        if (kind === 'type') return { ...current, rideTypes: [...current.rideTypes, created] }
        return { ...current, evus: [...current.evus, created] }
      })
      if (kind === 'type') setTypeName('')
      else setEvuName('')
      toast(kind === 'type' ? 'Art der Fahrt ergänzt.' : 'EVU ergänzt.')
    } catch (reason) {
      const message = reason instanceof ApiError
        ? (reason.fields?.name ?? reason.message)
        : 'Der Wert konnte nicht ergänzt werden.'
      setFieldError(message)
    } finally {
      setBusy(null)
    }
  }

  async function setActive(kind: CatalogKind, value: CatalogValue, active: boolean) {
    setBusy(value.id)
    setRowErrors((current) => ({ ...current, [value.id]: '' }))
    try {
      const updated = await api<CatalogValue>(`${kind === 'type' ? '/api/v1/ride-types' : '/api/v1/evus'}/${value.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ active }),
      })
      replace(kind, updated)
      const label = kind === 'type' ? 'Art der Fahrt' : 'EVU'
      toast(active ? `${label} ist wieder aktiv.` : `${label} ist inaktiv.`)
    } catch (reason) {
      const message = reason instanceof ApiError ? reason.message : 'Der Eintrag konnte nicht geändert werden.'
      setRowErrors((current) => ({ ...current, [value.id]: message }))
    } finally {
      setBusy(null)
    }
  }

  async function remove(kind: CatalogKind, value: CatalogValue) {
    setBusy(value.id)
    setRowErrors((current) => ({ ...current, [value.id]: '' }))
    try {
      await api(`${kind === 'type' ? '/api/v1/ride-types' : '/api/v1/evus'}/${value.id}`, { method: 'DELETE' })
      drop(kind, value.id)
      toast(kind === 'type' ? 'Art der Fahrt gelöscht.' : 'EVU gelöscht.')
    } catch (reason) {
      const message = reason instanceof ApiError ? reason.message : 'Der Eintrag konnte nicht gelöscht werden.'
      setRowErrors((current) => ({ ...current, [value.id]: message }))
    } finally {
      setBusy(null)
    }
  }

  return (
    <section className="settings-page">
      <div className="heading">
        <div>
          <div className="eyebrow">Administration</div>
          <h1>Einstellungen</h1>
        </div>
      </div>
      {error ? <div className="banner-error">{error}</div> : null}
      {!catalog && !error ? <p className="empty">Wird geladen …</p> : null}
      {catalog ? (
        <div className="stack">
          <div className="note">Art der Fahrt und EVU gelten für die ganze Fahrtenplanung. Ein unbenutzter Eintrag kann gelöscht werden. Ein Eintrag, der bereits auf einer Fahrt steht, kann inaktiv gesetzt werden. Inaktive Werte bleiben an bestehenden Fahrten und erscheinen nicht mehr in der Auswahl.</div>
          <CatalogList
            busyId={busy}
            error={typeError}
            fieldId="ride-type-name"
            label="Art der Fahrt"
            name={typeName}
            rowErrors={rowErrors}
            values={catalog.rideTypes}
            onActive={(value, active) => { void setActive('type', value, active) }}
            onAdd={(submit) => { void add('type', submit) }}
            onChange={setTypeName}
            onDelete={(value) => { void remove('type', value) }}
          />
          <CatalogList
            busyId={busy}
            error={evuError}
            fieldId="evu-name"
            label="EVU"
            name={evuName}
            rowErrors={rowErrors}
            values={catalog.evus}
            onActive={(value, active) => { void setActive('evu', value, active) }}
            onAdd={(submit) => { void add('evu', submit) }}
            onChange={setEvuName}
            onDelete={(value) => { void remove('evu', value) }}
          />
        </div>
      ) : null}
    </section>
  )
}

function CatalogList({
  label,
  fieldId,
  values,
  name,
  error,
  rowErrors,
  busyId,
  onChange,
  onAdd,
  onActive,
  onDelete,
}: {
  label: string
  fieldId: string
  values: CatalogValue[]
  name: string
  error: string | null
  rowErrors: Record<string, string>
  busyId: string | null
  onChange: (value: string) => void
  onAdd: (submit: FormEvent) => void
  onActive: (value: CatalogValue, active: boolean) => void
  onDelete: (value: CatalogValue) => void
}) {
  return (
    <div className="panel">
      <div className="panel-head"><h2>{label}</h2></div>
      <div className="panel-inner">
        {values.length === 0 ? <p className="muted">Noch kein Eintrag.</p> : (
          <ul className="catalog-list">
            {values.map((value) => {
              const inactive = value.active === false
              const used = value.used === true
              return (
                <li key={value.id}>
                  <div className="catalog-row">
                    <div className="catalog-main">
                      <span>{value.name}</span>
                      {inactive ? <span className="catalog-flag">Inaktiv</span> : null}
                      {used ? <span className="catalog-flag">verwendet</span> : null}
                    </div>
                    <div className="catalog-actions">
                      {used || inactive ? (
                        <button className="btn small light" disabled={busyId === value.id} type="button" onClick={() => onActive(value, inactive)}>
                          {inactive ? 'Aktivieren' : 'Inaktiv setzen'}
                        </button>
                      ) : null}
                      {!used ? (
                        <button className="btn small light" disabled={busyId === value.id} type="button" onClick={() => onDelete(value)}>Löschen</button>
                      ) : null}
                    </div>
                  </div>
                  {rowErrors[value.id] ? <div className="field-error">{rowErrors[value.id]}</div> : null}
                </li>
              )
            })}
          </ul>
        )}
        <form onSubmit={onAdd}>
          <div className="formfield">
            <label htmlFor={fieldId}>{label} ergänzen</label>
            <div className="catalog-add">
              <input
                aria-label={`${label} ergänzen`}
                className="input"
                id={fieldId}
                value={name}
                onChange={(input) => onChange(input.target.value)}
              />
              <button className="btn light" disabled={busyId === (label === 'EVU' ? 'evu' : 'type')} type="submit">Hinzufügen</button>
            </div>
            {error ? <div className="field-error">{error}</div> : null}
          </div>
        </form>
      </div>
    </div>
  )
}
