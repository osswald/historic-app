import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { api, ApiError } from '../api/client'
import type { Daypart, PollDetail } from '../api/types'

type SlotDraft = { id?: string; on: boolean; adoptedEventId?: string | null }
type DepotDay = { key: string; date: string; morning: SlotDraft; afternoon: SlotDraft; evening: SlotDraft }
type NormalOption = { key: string; id?: string; date: string; startsAt: string; endsAt: string; adoptedEventId?: string | null }

const EMPTY_SLOT: SlotDraft = { on: true }
const MONTH_NAMES = [
  'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
  'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
]

function blankDepotDay(): DepotDay {
  return { key: crypto.randomUUID(), date: '', morning: { ...EMPTY_SLOT }, afternoon: { ...EMPTY_SLOT }, evening: { ...EMPTY_SLOT } }
}

function blankNormal(): NormalOption {
  return { key: crypto.randomUUID(), date: '', startsAt: '', endsAt: '' }
}

function defaultMonth(): string {
  const now = new Date()
  const year = now.getFullYear()
  const month = now.getMonth() + 2
  if (month > 12) return `${year + 1}-01`
  return `${year}-${String(month).padStart(2, '0')}`
}

function datesInMonth(yearMonth: string): string[] {
  const match = /^(\d{4})-(\d{2})$/.exec(yearMonth)
  if (!match) return []
  const year = Number(match[1])
  const month = Number(match[2])
  if (month < 1 || month > 12) return []
  const last = new Date(Date.UTC(year, month, 0)).getUTCDate()
  return Array.from({ length: last }, (_, index) => {
    const day = String(index + 1).padStart(2, '0')
    return `${match[1]}-${match[2]}-${day}`
  })
}

function monthTitle(yearMonth: string): string {
  const match = /^(\d{4})-(\d{2})$/.exec(yearMonth)
  if (!match) return ''
  const month = Number(match[2])
  return `Depottage ${MONTH_NAMES[month - 1]} ${match[1]}`
}

function fillDepotMonth(yearMonth: string, existing: DepotDay[]): DepotDay[] {
  const byDate = new Map(existing.filter((day) => day.date !== '').map((day) => [day.date, day]))
  const filled = datesInMonth(yearMonth).map((date) => {
    const previous = byDate.get(date)
    if (previous) {
      return {
        ...previous,
        morning: { ...previous.morning, on: true },
        afternoon: { ...previous.afternoon, on: true },
        evening: { ...previous.evening, on: true },
      }
    }
    return {
      key: crypto.randomUUID(),
      date,
      morning: { ...EMPTY_SLOT },
      afternoon: { ...EMPTY_SLOT },
      evening: { ...EMPTY_SLOT },
    }
  })
  const covered = new Set(filled.map((day) => day.date))
  const lockedElsewhere = existing.filter((day) => day.date !== '' && !covered.has(day.date) && dayIsLocked(day))
  return [...filled, ...lockedElsewhere]
}

function dayIsLocked(day: DepotDay): boolean {
  return Boolean(day.morning.adoptedEventId || day.afternoon.adoptedEventId || day.evening.adoptedEventId)
}

export function PollEditorPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const editing = Boolean(id)
  const [title, setTitle] = useState(() => (id ? '' : monthTitle(defaultMonth())))
  const [kind, setKind] = useState<'normal' | 'depot'>('depot')
  const [status, setStatus] = useState<'open' | 'closed'>('open')
  const [clubs, setClubs] = useState<Array<'historic' | 'supporter'>>(['historic'])
  const [description, setDescription] = useState('')
  const [location, setLocation] = useState('')
  const [deadline, setDeadline] = useState('')
  const [depotMonth, setDepotMonth] = useState(defaultMonth)
  const [depotDays, setDepotDays] = useState<DepotDay[]>(() => (id ? [blankDepotDay()] : fillDepotMonth(defaultMonth(), [])))
  const [normalOptions, setNormalOptions] = useState<NormalOption[]>([blankNormal()])
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    if (!id) return
    api<PollDetail>(`/api/v1/polls/${id}`).then((poll) => {
      setTitle(poll.title)
      setKind(poll.kind)
      setStatus(poll.status)
      setClubs(poll.clubs)
      setDescription(poll.description ?? '')
      setLocation(poll.location ?? '')
      setDeadline(poll.deadline ?? '')
      if (poll.kind === 'depot') {
        const days = poll.days.map((day) => ({
          key: day.date,
          date: day.date,
          morning: slotFrom(day.options, 'morning'),
          afternoon: slotFrom(day.options, 'afternoon'),
          evening: slotFrom(day.options, 'evening'),
        }))
        setDepotDays(days)
        const first = days[0]?.date
        if (first) setDepotMonth(first.slice(0, 7))
      } else {
        setNormalOptions(poll.options.map((option) => ({
          key: option.id,
          id: option.id,
          date: option.date,
          startsAt: option.startsAt ?? '',
          endsAt: option.endsAt ?? '',
          adoptedEventId: option.adoptedEventId,
        })))
      }
    }).catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Umfrage konnte nicht geladen werden.'))
  }, [id])

  function toggleClub(club: 'historic' | 'supporter') {
    setClubs((current) => current.includes(club) ? current.filter((item) => item !== club) : [...current, club])
  }

  function applyMonth(yearMonth: string) {
    setDepotMonth(yearMonth)
    setDepotDays((current) => fillDepotMonth(yearMonth, current))
    if (!editing && title.trim() === '') {
      setTitle(monthTitle(yearMonth))
    }
  }

  async function save(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    setErrors({})
    const options = kind === 'depot'
      ? depotDays.flatMap((day) => (['morning', 'afternoon', 'evening'] as const).flatMap((daypart) => {
          const slot = day[daypart]
          if (slot.adoptedEventId) return [{ id: slot.id, date: day.date, daypart, startsAt: '', endsAt: '' }]
          if (!slot.on) return []
          return [{ id: slot.id, date: day.date, daypart, startsAt: '', endsAt: '' }]
        }))
      : normalOptions.map((option) => ({ id: option.id, date: option.date, daypart: '', startsAt: option.startsAt, endsAt: option.endsAt }))
    try {
      const saved = await api<PollDetail>(editing ? `/api/v1/polls/${id}` : '/api/v1/polls', {
        method: editing ? 'PUT' : 'POST',
        body: JSON.stringify({ title, kind, status, clubs, description, location, deadline, options }),
      })
      void navigate(`/umfragen/${saved.id}`)
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
        <Link className="btn small light" to={editing ? `/umfragen/${id}` : '/umfragen'}>‹ Zurück</Link>
        <span className="pill orange">{editing ? 'Bearbeitung' : 'Neue Umfrage'}</span>
      </div>
      <form onSubmit={(event) => { void save(event) }}>
        <div className="heading">
          <div>
            <div className="eyebrow">Organisation</div>
            <h1>{editing ? 'Umfrage bearbeiten' : 'Umfrage anlegen'}</h1>
            <div className="hello">Monatliche Depottage: alle Kalendertage mit Vormittag, Nachmittag und Abend, ohne Uhrzeit.</div>
          </div>
          <button className="btn primary" disabled={busy} type="submit">Änderungen speichern</button>
        </div>
        {error ? <div className="banner-error">{error}</div> : null}
        <div className="detailgrid">
          <div className="stack">
            <div className="panel">
              <div className="panel-head"><h2>Allgemeine Angaben</h2></div>
              <div className="panel-inner">
                <Field error={errors.title} label="Titel">
                  <input className="input" value={title} onChange={(event) => setTitle(event.target.value)} />
                </Field>
                <Field error={errors.kind} label="Art">
                  <select
                    className="input"
                    disabled={editing}
                    value={kind}
                    onChange={(event) => {
                      const next = event.target.value as 'normal' | 'depot'
                      setKind(next)
                      if (next === 'depot' && depotDays.length <= 1 && !depotDays[0]?.date) {
                        applyMonth(depotMonth || defaultMonth())
                      }
                    }}
                  >
                    <option value="depot">Depottage (Monat)</option>
                    <option value="normal">Terminfindung</option>
                  </select>
                </Field>
                <div className="formfield">
                  <label>Verein</label>
                  <div className="check-line">
                    <label className="toggle"><input checked={clubs.includes('historic')} type="checkbox" onChange={() => toggleClub('historic')} /> SBB Historic-Team Winterthur</label>
                    <label className="toggle"><input checked={clubs.includes('supporter')} type="checkbox" onChange={() => toggleClub('supporter')} /> Supporterclub</label>
                  </div>
                  {errors.clubs ? <div className="field-error">{errors.clubs}</div> : null}
                </div>
                <Field error={errors.status} label="Status">
                  <select className="input" value={status} onChange={(event) => setStatus(event.target.value as 'open' | 'closed')}>
                    <option value="open">Offen</option>
                    <option value="closed">Geschlossen</option>
                  </select>
                </Field>
                <Field label="Hinweis">
                  <textarea className="input" value={description} onChange={(event) => setDescription(event.target.value)} />
                </Field>
                <Field label="Ort">
                  <input className="input" value={location} onChange={(event) => setLocation(event.target.value)} />
                </Field>
                <Field error={errors.deadline} label="Antwortfrist">
                  <input className="input" type="date" value={deadline} onChange={(event) => setDeadline(event.target.value)} />
                </Field>
              </div>
            </div>
            <div className="panel">
              <div className="panel-head"><h2>Optionen</h2></div>
              <div className="panel-inner">
                {errors.options ? <div className="field-error">{errors.options}</div> : null}
                {kind === 'depot' ? (
                  <>
                    <div className="formrow cols2" style={{ marginBottom: 12 }}>
                      <Field label="Monat der Depottage">
                        <input
                          className="input"
                          type="month"
                          value={depotMonth}
                          onChange={(event) => setDepotMonth(event.target.value)}
                        />
                      </Field>
                      <div className="formfield">
                        <label>&nbsp;</label>
                        <button className="btn light" type="button" onClick={() => applyMonth(depotMonth)}>
                          Alle Kalendertage eintragen
                        </button>
                      </div>
                    </div>
                    <p className="muted" style={{ margin: '0 0 12px' }}>
                      {depotDays.filter((day) => day.date).length} Tage · je Vormittag, Nachmittag und Abend. Einzelne Tage bleiben manuell anpassbar.
                    </p>
                    {depotDays.map((day, index) => (
                      <div className="depot-day-line" key={day.key}>
                        <div className="formfield">
                          <input
                            aria-label={`Datum Tag ${index + 1}`}
                            className="input"
                            type="date"
                            disabled={dayIsLocked(day)}
                            value={day.date}
                            onChange={(event) => setDepotDays((current) => current.map((item) => item.key === day.key ? { ...item, date: event.target.value } : item))}
                          />
                          {errors[`options.${index}.date`] ? <div className="field-error">{errors[`options.${index}.date`]}</div> : null}
                        </div>
                        <div className="check-line depot-slots">
                          {(['morning', 'afternoon', 'evening'] as const).map((daypart) => (
                            <label className="toggle" key={daypart}>
                              <input
                                checked={day[daypart].adoptedEventId ? true : day[daypart].on}
                                disabled={Boolean(day[daypart].adoptedEventId)}
                                type="checkbox"
                                onChange={() => setDepotDays((current) => current.map((item) => item.key === day.key ? { ...item, [daypart]: { ...item[daypart], on: !item[daypart].on } } : item))}
                              />
                              {daypart === 'morning' ? 'Vormittag' : daypart === 'afternoon' ? 'Nachmittag' : 'Abend'}
                            </label>
                          ))}
                        </div>
                        {depotDays.length > 1 && !dayIsLocked(day) ? (
                          <button className="btn small light" type="button" onClick={() => setDepotDays((current) => current.filter((item) => item.key !== day.key))}>
                            Entfernen
                          </button>
                        ) : null}
                      </div>
                    ))}
                  </>
                ) : normalOptions.map((option, index) => (
                  <div className="day-block" key={option.key}>
                    <h3>Termin {index + 1}</h3>
                    <div className="formrow cols2">
                      <Field error={errors[`options.${index}.date`]} label="Datum">
                        <input className="input" disabled={Boolean(option.adoptedEventId)} type="date" value={option.date} onChange={(event) => setNormalOptions((current) => current.map((item) => item.key === option.key ? { ...item, date: event.target.value } : item))} />
                      </Field>
                      <Field label="Zeit">
                        <div className="toolbar">
                          <input aria-label="Beginn" className="input" disabled={Boolean(option.adoptedEventId)} type="time" value={option.startsAt} onChange={(event) => setNormalOptions((current) => current.map((item) => item.key === option.key ? { ...item, startsAt: event.target.value } : item))} />
                          <input aria-label="Ende" className="input" disabled={Boolean(option.adoptedEventId)} type="time" value={option.endsAt} onChange={(event) => setNormalOptions((current) => current.map((item) => item.key === option.key ? { ...item, endsAt: event.target.value } : item))} />
                        </div>
                      </Field>
                    </div>
                    {errors[`options.${index}.endsAt`] ? <div className="field-error">{errors[`options.${index}.endsAt`]}</div> : null}
                    {option.adoptedEventId ? <p className="muted">Dieser Vorschlag ist übernommen und gesperrt.</p> : null}
                    {normalOptions.length > 1 && !option.adoptedEventId ? <button className="btn small light" type="button" onClick={() => setNormalOptions((current) => current.filter((item) => item.key !== option.key))}>Termin entfernen</button> : null}
                  </div>
                ))}
                <button className="btn light" type="button" onClick={() => kind === 'depot' ? setDepotDays((current) => [...current, blankDepotDay()]) : setNormalOptions((current) => [...current, blankNormal()])}>
                  {kind === 'depot' ? 'Weiteren Tag hinzufügen' : 'Weiteren Termin hinzufügen'}
                </button>
              </div>
            </div>
          </div>
          <div className="panel">
            <div className="panel-head"><h2>Übernahme</h2></div>
            <div className="panel-inner">
              <div className="note">Jeder Vorschlag kann einzeln als Entwurf oder veröffentlicht übernommen werden. Übernommene Vorschläge bleiben gesperrt, die übrigen bleiben änderbar. Antworten bleiben möglich, bis die Umfrage geschlossen wird. Die Antwortfrist sperrt das Speichern nicht.</div>
            </div>
          </div>
        </div>
      </form>
    </section>
  )
}

function slotFrom(options: PollDetail['options'], daypart: Daypart): SlotDraft {
  const option = options.find((item) => item.daypart === daypart)
  return option ? { id: option.id, on: true, adoptedEventId: option.adoptedEventId } : { on: false }
}

function Field({ label, error, children }: { label?: string; error?: string; children: ReactNode }) {
  return (
    <div className="formfield">
      {label ? <label>{label}</label> : null}
      {children}
      {error ? <div className="field-error">{error}</div> : null}
    </div>
  )
}
