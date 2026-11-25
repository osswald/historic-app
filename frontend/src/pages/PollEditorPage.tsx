import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { api, ApiError } from '../api/client'
import type { Daypart, PollDetail } from '../api/types'
import { incompleteMessage, missingFieldLabels } from '../forms/incomplete'
import { MobileSaveBar } from '../forms/MobileSaveBar'
import { useNarrow } from '../forms/narrow'
import { useRegisterUnsaved } from '../forms/unsaved'

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

const DAY_PARTS = ['morning', 'afternoon', 'evening'] as const

function depotDayIsOpen(day: DepotDay): boolean {
  return DAY_PARTS.some((part) => day[part].on && !day[part].adoptedEventId)
}

function daySummary(day: DepotDay): string {
  if (dayIsLocked(day) && !depotDayIsOpen(day)) return 'Übernommen'
  const labels = [
    day.morning.on ? 'Vormittag' : null,
    day.afternoon.on ? 'Nachmittag' : null,
    day.evening.on ? 'Abend' : null,
  ].filter((label): label is string => label !== null)
  return labels.length > 0 ? labels.join(', ') : 'Geschlossen'
}

function longDay(date: string): string {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(date)
  if (!match) return 'Ohne Datum'
  const stamp = new Date(Number(match[1]), Number(match[2]) - 1, Number(match[3]))
  return stamp.toLocaleDateString('de-CH', { weekday: 'short', day: 'numeric', month: 'short' })
}

export function PollEditorPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const editing = Boolean(id)
  const narrow = useNarrow()
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
  const [incomplete, setIncomplete] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [loaded, setLoaded] = useState(!editing)
  const [baseline, setBaseline] = useState<string | null>(null)
  const [onlyOpen, setOnlyOpen] = useState(false)
  const [openDays, setOpenDays] = useState<Record<string, boolean>>({})

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
      setLoaded(true)
    }).catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Umfrage konnte nicht geladen werden.'))
  }, [id])

  const snapshot = JSON.stringify({
    title, kind, status, clubs, description, location, deadline, depotMonth, depotDays, normalOptions,
  })
  useEffect(() => {
    if (!loaded) return
    setBaseline((current) => current ?? snapshot)
  }, [loaded, snapshot])
  const dirty = baseline !== null && snapshot !== baseline
  useRegisterUnsaved(editing ? `poll-${id}` : 'poll-new', dirty)

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

  function cancel() {
    void navigate(editing ? `/umfragen/${id}` : '/umfragen')
  }

  async function save(event: FormEvent) {
    event.preventDefault()
    const missing = missingPollFields({ title, clubs, kind, depotDays, normalOptions })
    if (missing.length > 0) {
      setIncomplete(incompleteMessage(missing))
      setErrors(pollFieldErrors({ title, clubs, kind, depotDays, normalOptions }))
      setError(null)
      return
    }
    setBusy(true)
    setError(null)
    setIncomplete(null)
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
      if (reason instanceof ApiError && reason.message === 'Die Eingaben sind unvollständig.') {
        setIncomplete(incompleteMessage(labelsForPollErrors(reason.fields)))
        setErrors(reason.fields ?? {})
      } else if (reason instanceof ApiError) {
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
      <form className="editor-form" onSubmit={(event) => { void save(event) }}>
        <div className="heading">
          <div>
            <div className="eyebrow">Organisation</div>
            <h1>{editing ? 'Umfrage bearbeiten' : 'Umfrage anlegen'}</h1>
            <div className="hello">Monatliche Depottage: alle Kalendertage mit Vormittag, Nachmittag und Abend, ohne Uhrzeit.</div>
          </div>
          <button className="btn primary heading-save" disabled={busy} type="submit">Änderungen speichern</button>
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
                    {narrow ? (
                      <label className="toggle">
                        <input checked={onlyOpen} type="checkbox" onChange={(event) => setOnlyOpen(event.target.checked)} />
                        Nur offene Tage
                      </label>
                    ) : null}
                    {depotDays.map((day, index) => {
                      if (narrow && onlyOpen && !depotDayIsOpen(day)) return null
                      const body = (
                        <>
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
                            {DAY_PARTS.map((daypart) => (
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
                        </>
                      )
                      if (!narrow) return <div className="depot-day-line" key={day.key}>{body}</div>
                      const expanded = openDays[day.key] === true
                      return (
                        <div className="day-fold" key={day.key}>
                          <button
                            aria-expanded={expanded}
                            className="day-fold-head"
                            type="button"
                            onClick={() => setOpenDays((current) => ({ ...current, [day.key]: !expanded }))}
                          >
                            <span>{longDay(day.date)}</span>
                            <small>{daySummary(day)}</small>
                            <span>{expanded ? '▾' : '▸'}</span>
                          </button>
                          {expanded ? <div className="day-fold-body">{body}</div> : null}
                        </div>
                      )
                    })}
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
        <MobileSaveBar busy={busy} dirty={dirty} incomplete={incomplete} onCancel={cancel} />
      </form>
    </section>
  )
}

function missingPollFields(input: {
  title: string
  clubs: string[]
  kind: 'normal' | 'depot'
  depotDays: DepotDay[]
  normalOptions: NormalOption[]
}): string[] {
  const fields: Array<{ label: string; filled: boolean }> = [
    { label: 'Titel', filled: input.title.trim() !== '' },
    { label: 'Verein', filled: input.clubs.length > 0 },
  ]
  if (input.kind === 'depot') {
    const included = input.depotDays.map((day, index) => ({ day, index })).filter(({ day }) => DAY_PARTS.some((part) => day[part].on || day[part].adoptedEventId))
    if (!included.some(({ day }) => day.date !== '')) fields.push({ label: 'Datum', filled: false })
    for (const { day, index } of included) {
      if (day.date !== '') continue
      fields.push({ label: input.depotDays.length > 1 ? `Datum (Tag ${index + 1})` : 'Datum', filled: false })
    }
  } else {
    const touched = input.normalOptions.map((option, index) => ({ option, index })).filter(({ option }) => option.date !== '' || option.startsAt !== '' || option.endsAt !== '')
    if (!touched.some(({ option }) => option.date !== '')) fields.push({ label: 'Datum', filled: false })
    for (const { option, index } of touched) {
      fields.push({ label: input.normalOptions.length > 1 ? `Datum (Termin ${index + 1})` : 'Datum', filled: option.date !== '' })
    }
  }
  return missingFieldLabels(fields)
}

function pollFieldErrors(input: {
  title: string
  clubs: string[]
  kind: 'normal' | 'depot'
  depotDays: DepotDay[]
  normalOptions: NormalOption[]
}): Record<string, string> {
  const next: Record<string, string> = {}
  if (input.title.trim() === '') next.title = 'Bitte einen Titel mit höchstens 200 Zeichen angeben.'
  if (input.clubs.length === 0) next.clubs = 'Bitte mindestens einen Verein auswählen.'
  if (input.kind === 'depot') {
    const included = input.depotDays.some((day) => day.date !== '' && DAY_PARTS.some((part) => day[part].on || day[part].adoptedEventId))
    if (!included) next.options = 'Bitte mindestens eine Option angeben.'
    input.depotDays.forEach((day, index) => {
      const active = DAY_PARTS.some((part) => day[part].on || day[part].adoptedEventId)
      if (active && day.date === '') next[`options.${index}.date`] = 'Bitte ein gültiges Datum angeben.'
    })
  } else if (!input.normalOptions.some((option) => option.date !== '')) {
    next.options = 'Bitte mindestens eine Option angeben.'
  }
  return next
}

function labelsForPollErrors(fields: Record<string, string> | undefined): string[] {
  if (!fields) return []
  return Object.keys(fields).map((key) => {
    if (key === 'title') return 'Titel'
    if (key === 'clubs') return 'Verein'
    if (key === 'options' || key.endsWith('.date')) return 'Datum'
    if (key.endsWith('.endsAt') || key.endsWith('.startsAt')) return 'Zeit'
    if (key === 'deadline') return 'Antwortfrist'
    if (key === 'kind') return 'Art'
    if (key === 'status') return 'Status'
    return ''
  })
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
