import { useEffect, useState, type FormEvent } from 'react'
import { api, ApiError } from '../api/client'
import type { CatalogValue } from '../api/types'
import { BtnLabel } from '../design/BtnLabel'
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
  const [eventCategories, setEventCategories] = useState<CatalogValue[] | null>(null)
  const [categoryName, setCategoryName] = useState('')
  const [typeError, setTypeError] = useState<string | null>(null)
  const [evuError, setEvuError] = useState<string | null>(null)
  const [categoryError, setCategoryError] = useState<string | null>(null)
  const [rowErrors, setRowErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState<string | null>(null)

  useEffect(() => {
    let cancelled = false
    Promise.all([
      api<Catalog>('/api/v1/ride-catalog'),
      api<{ categories: CatalogValue[] }>('/api/v1/event-categories'),
    ])
      .then(([rides, events]) => {
        if (cancelled) return
        setCatalog(rides)
        setEventCategories(events.categories)
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

  async function addCategory(submit?: FormEvent) {
    submit?.preventDefault()
    const name = categoryName.trim()
    if (name === '') {
      setCategoryError('Bitte einen Namen angeben.')
      return
    }
    setBusy('category')
    setCategoryError(null)
    try {
      const created = await api<CatalogValue>('/api/v1/event-categories', {
        method: 'POST',
        body: JSON.stringify({ name }),
      })
      setEventCategories((current) => current ? [...current, created] : [created])
      setCategoryName('')
      toast('Veranstaltungskategorie ergänzt.')
    } catch (reason) {
      const message = reason instanceof ApiError
        ? (reason.fields?.name ?? reason.message)
        : 'Der Wert konnte nicht ergänzt werden.'
      setCategoryError(message)
    } finally {
      setBusy(null)
    }
  }

  async function setCategoryActive(value: CatalogValue, active: boolean) {
    setBusy(value.id)
    setRowErrors((current) => ({ ...current, [value.id]: '' }))
    try {
      const updated = await api<CatalogValue>(`/api/v1/event-categories/${value.id}`, {
        method: 'PATCH',
        body: JSON.stringify({ active }),
      })
      setEventCategories((current) => current?.map((item) => item.id === updated.id ? updated : item) ?? null)
      toast(active ? 'Veranstaltungskategorie ist wieder aktiv.' : 'Veranstaltungskategorie ist inaktiv.')
    } catch (reason) {
      const message = reason instanceof ApiError ? reason.message : 'Der Eintrag konnte nicht geändert werden.'
      setRowErrors((current) => ({ ...current, [value.id]: message }))
    } finally {
      setBusy(null)
    }
  }

  async function removeCategory(value: CatalogValue) {
    setBusy(value.id)
    setRowErrors((current) => ({ ...current, [value.id]: '' }))
    try {
      await api(`/api/v1/event-categories/${value.id}`, { method: 'DELETE' })
      setEventCategories((current) => current?.filter((item) => item.id !== value.id) ?? null)
      toast('Veranstaltungskategorie gelöscht.')
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
      {catalog && eventCategories ? (
        <div className="stack">
          <div className="note">Art der Fahrt, EVU und Veranstaltungskategorien gelten für die ganze Planung. Ein unbenutzter Eintrag kann gelöscht werden. Ein Eintrag, der bereits verwendet wird, kann inaktiv gesetzt werden. Inaktive Werte bleiben an bestehenden Einträgen und erscheinen nicht mehr in der Auswahl.</div>
          <CatalogList
            busyId={busy}
            busyKey="type"
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
            busyKey="evu"
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
          <CatalogList
            busyId={busy}
            busyKey="category"
            error={categoryError}
            fieldId="event-category-name"
            label="Veranstaltungskategorie"
            name={categoryName}
            rowErrors={rowErrors}
            values={eventCategories}
            onActive={(value, active) => { void setCategoryActive(value, active) }}
            onAdd={(submit) => { void addCategory(submit) }}
            onChange={setCategoryName}
            onDelete={(value) => { void removeCategory(value) }}
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
  busyKey,
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
  busyKey: string
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
                          <BtnLabel icon={inactive ? 'check' : 'close'} iconOnly={false}>{inactive ? 'Aktivieren' : 'Inaktiv setzen'}</BtnLabel>
                        </button>
                      ) : null}
                      {!used ? (
                        <button className="btn small light" disabled={busyId === value.id} type="button" onClick={() => onDelete(value)}><BtnLabel icon="delete">Löschen</BtnLabel></button>
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
              <button className="btn light" disabled={busyId === busyKey} type="submit"><BtnLabel icon="add">Hinzufügen</BtnLabel></button>
            </div>
            {error ? <div className="field-error">{error}</div> : null}
          </div>
        </form>
      </div>
    </div>
  )
}
