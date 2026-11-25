import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { api, ApiError } from '../api/client'
import type { ClubEvent } from '../api/types'

type DayDraft = {
  key: string
  id?: string
  date: string
  startsAt: string
  endsAt: string
  location: string
  meetingPointName: string
  meetingPointDescription: string
}

function blankDay(): DayDraft {
  return { key: crypto.randomUUID(), date: '', startsAt: '', endsAt: '', location: '', meetingPointName: '', meetingPointDescription: '' }
}

export function EventEditorPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const editing = Boolean(id)
  const [title, setTitle] = useState('')
  const [clubs, setClubs] = useState<Array<'historic' | 'supporter'>>(['historic'])
  const [categories, setCategories] = useState('')
  const [status, setStatus] = useState<'draft' | 'published' | 'cancelled'>('draft')
  const [internalDescription, setInternalDescription] = useState('')
  const [publicDescription, setPublicDescription] = useState('')
  const [publishToWebsite, setPublishToWebsite] = useState(false)
  const [days, setDays] = useState<DayDraft[]>([blankDay()])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!id) return
    api<ClubEvent>(`/api/v1/events/${id}`).then((event) => {
      setTitle(event.title)
      setClubs(event.clubs)
      setCategories(event.categories.join(', '))
      setStatus(event.status === 'archived' ? 'draft' : event.status)
      setInternalDescription(event.internalDescription ?? '')
      setPublicDescription(event.publicDescription ?? '')
      setPublishToWebsite(event.publishToWebsite)
      setDays(event.days.map((day) => ({
        key: day.id,
        id: day.id,
        date: day.date,
        startsAt: day.startsAt ?? '',
        endsAt: day.endsAt ?? '',
        location: day.location ?? '',
        meetingPointName: day.meetingPointName ?? '',
        meetingPointDescription: day.meetingPointDescription ?? '',
      })))
    }).catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Veranstaltung konnte nicht geladen werden.'))
  }, [id])

  function toggleClub(club: 'historic' | 'supporter') {
    setClubs((current) => current.includes(club) ? current.filter((item) => item !== club) : [...current, club])
  }

  function updateDay(key: string, patch: Partial<DayDraft>) {
    setDays((current) => current.map((day) => day.key === key ? { ...day, ...patch } : day))
  }

  async function save(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    setErrors({})
    const payload = {
      title,
      clubs,
      categories: categories.split(',').map((item) => item.trim()).filter(Boolean),
      status,
      internalDescription,
      publicDescription,
      publishToWebsite,
      days: days.map((day) => ({
        id: day.id,
        date: day.date,
        startsAt: day.startsAt,
        endsAt: day.endsAt,
        location: day.location,
        meetingPointName: day.meetingPointName,
        meetingPointDescription: day.meetingPointDescription,
      })),
    }
    try {
      const saved = await api<ClubEvent>(editing ? `/api/v1/events/${id}` : '/api/v1/events', {
        method: editing ? 'PUT' : 'POST',
        body: JSON.stringify(payload),
      })
      void navigate(`/veranstaltungen/${saved.id}`)
    } catch (reason) {
      if (reason instanceof ApiError) {
        setError(reason.message)
        setErrors(reason.fields ?? {})
      } else {
        setError('Speichern ist fehlgeschlagen.')
      }
    } finally {
      setBusy(false)
    }
  }

  return (
    <section>
      <div className="toolbar" style={{ marginBottom: 13 }}>
        <Link className="btn small light" to={editing ? `/veranstaltungen/${id}` : '/veranstaltungen'}>‹ Zurück</Link>
        <span className="pill orange">{editing ? 'Bearbeitung' : 'Neue Veranstaltung'}</span>
      </div>
      <form onSubmit={(event) => { void save(event) }}>
        <div className="heading">
          <div>
            <div className="eyebrow">Organisation</div>
            <h1>{editing ? 'Veranstaltung bearbeiten' : 'Veranstaltung anlegen'}</h1>
            <div className="hello">Für Organisatoren und Administratoren.</div>
          </div>
          <button className="btn primary" disabled={busy} type="submit">Änderungen speichern</button>
        </div>
        {error ? <div className="banner-error">{error}</div> : null}
        <div className="detailgrid">
          <div className="stack">
            <div className="panel">
              <div className="panel-head"><h2>Allgemeine Angaben</h2></div>
              <div className="panel-inner">
                <Field error={errors.title} label="Titel der Veranstaltung">
                  <input className="input" value={title} onChange={(event) => setTitle(event.target.value)} />
                </Field>
                <div className="formfield">
                  <label>Verein</label>
                  <div className="check-line">
                    <label className="toggle"><input checked={clubs.includes('historic')} type="checkbox" onChange={() => toggleClub('historic')} /> SBB Historic-Team Winterthur</label>
                    <label className="toggle"><input checked={clubs.includes('supporter')} type="checkbox" onChange={() => toggleClub('supporter')} /> Supporterclub</label>
                  </div>
                  {errors.clubs ? <div className="field-error">{errors.clubs}</div> : null}
                </div>
                <Field label="Kategorien">
                  <input className="input" placeholder="Öffentliche Fahrt, Vereinsanlass" value={categories} onChange={(event) => setCategories(event.target.value)} />
                </Field>
                <Field error={errors.status} label="Status">
                  <select className="input" value={status} onChange={(event) => setStatus(event.target.value as 'draft' | 'published' | 'cancelled')}>
                    <option value="draft">Entwurf</option>
                    <option value="published">Veröffentlicht</option>
                    <option value="cancelled">Abgesagt</option>
                  </select>
                </Field>
                <Field label="Interne Beschreibung">
                  <textarea className="input" value={internalDescription} onChange={(event) => setInternalDescription(event.target.value)} />
                </Field>
                <Field label="Öffentliche Beschreibung">
                  <textarea className="input" placeholder="Separater Text für die Website" value={publicDescription} onChange={(event) => setPublicDescription(event.target.value)} />
                </Field>
                <label className="toggle">
                  <input checked={publishToWebsite} type="checkbox" onChange={(event) => setPublishToWebsite(event.target.checked)} />
                  Für die öffentliche Website vormerken. Die Übertragung folgt mit der WordPress-Anbindung.
                </label>
              </div>
            </div>
            <div className="panel">
              <div className="panel-head"><h2>Tage</h2></div>
              <div className="panel-inner">
                {errors.days ? <div className="field-error">{errors.days}</div> : null}
                {days.map((day, index) => (
                  <div className="day-block" key={day.key}>
                    <h3>Tag {index + 1}</h3>
                    <div className="formrow cols2">
                      <Field error={errors[`days.${index}.date`]} label="Datum">
                        <input className="input" type="date" value={day.date} onChange={(event) => updateDay(day.key, { date: event.target.value })} />
                      </Field>
                      <Field label="Zeit">
                        <div className="toolbar">
                          <input aria-label="Beginn" className="input" type="time" value={day.startsAt} onChange={(event) => updateDay(day.key, { startsAt: event.target.value })} />
                          <input aria-label="Ende" className="input" type="time" value={day.endsAt} onChange={(event) => updateDay(day.key, { endsAt: event.target.value })} />
                        </div>
                      </Field>
                    </div>
                    <Field label="Ort">
                      <input className="input" value={day.location} onChange={(event) => updateDay(day.key, { location: event.target.value })} />
                    </Field>
                    <Field label="Treffpunkt">
                      <input className="input" value={day.meetingPointName} onChange={(event) => updateDay(day.key, { meetingPointName: event.target.value })} />
                    </Field>
                    <Field label="Hinweis zum Treffpunkt">
                      <input className="input" value={day.meetingPointDescription} onChange={(event) => updateDay(day.key, { meetingPointDescription: event.target.value })} />
                    </Field>
                    {days.length > 1 ? <button className="btn small light" type="button" onClick={() => setDays((current) => current.filter((item) => item.key !== day.key))}>Tag entfernen</button> : null}
                  </div>
                ))}
                <button className="btn light" type="button" onClick={() => setDays((current) => [...current, blankDay()])}>Weiteren Tag hinzufügen</button>
              </div>
            </div>
          </div>
          <div className="panel">
            <div className="panel-head"><h2>Sichtbarkeit</h2></div>
            <div className="panel-inner">
              <div className="note">Entwürfe bleiben für gewöhnliche Mitglieder unsichtbar. Veröffentlichte Anlässe sehen angemeldete Mitglieder. Einladungen nach Auth0-Gruppen folgen, sobald die Claims bestätigt sind.</div>
            </div>
          </div>
        </div>
      </form>
    </section>
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
