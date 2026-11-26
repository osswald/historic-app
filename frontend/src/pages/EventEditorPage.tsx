import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { api, ApiError } from '../api/client'
import type { CatalogValue, ClubEvent, FleetList, InvitationPerson } from '../api/types'
import { BtnLabel } from '../design/BtnLabel'
import { useMember } from '../shell/context'
import { incompleteMessage, missingFieldLabels } from '../forms/incomplete'
import { MobileSaveBar } from '../forms/MobileSaveBar'
import { useRegisterUnsaved } from '../forms/unsaved'
import { VehiclePick } from '../forms/VehiclePick'

type MeetingDraft = {
  key: string
  placeName: string
  meetsAt: string
  description: string
}

type DayDraft = {
  key: string
  id?: string
  date: string
  startsAt: string
  endsAt: string
  location: string
  meetingPoints: MeetingDraft[]
}

type VehicleDraft = {
  key: string
  vehicleId: string
  rentalStatus: string
}

function blankMeeting(): MeetingDraft {
  return { key: crypto.randomUUID(), placeName: '', meetsAt: '', description: '' }
}

function blankDay(): DayDraft {
  return { key: crypto.randomUUID(), date: '', startsAt: '', endsAt: '', location: '', meetingPoints: [] }
}

function pointIsBlank(point: MeetingDraft): boolean {
  return point.placeName.trim() === '' && point.meetsAt === '' && point.description.trim() === ''
}

function dayIsBlank(day: DayDraft): boolean {
  return day.date === '' && day.startsAt === '' && day.endsAt === '' && day.location.trim() === '' && day.meetingPoints.every(pointIsBlank)
}

export function EventEditorPage() {
  const { id } = useParams()
  const navigate = useNavigate()
  const member = useMember()
  const editing = Boolean(id)
  const [title, setTitle] = useState('')
  const [clubs, setClubs] = useState<Array<'historic' | 'supporter'>>(['historic'])
  const [categoryId, setCategoryId] = useState('')
  const [categoryOptions, setCategoryOptions] = useState<CatalogValue[]>([])
  const [currentCategory, setCurrentCategory] = useState<CatalogValue | null>(null)
  const [status, setStatus] = useState<'draft' | 'published' | 'cancelled'>('draft')
  const [internalDescription, setInternalDescription] = useState('')
  const [publicDescription, setPublicDescription] = useState('')
  const [publishToWebsite, setPublishToWebsite] = useState(false)
  const [days, setDays] = useState<DayDraft[]>([blankDay()])
  const [places, setPlaces] = useState<string[]>([])
  const [fleet, setFleet] = useState<FleetList['vehicles']>([])
  const [fleetReady, setFleetReady] = useState(false)
  const [vehicles, setVehicles] = useState<VehicleDraft[]>([])
  const [invitedGroups, setInvitedGroups] = useState<string[]>([])
  const [invitedPeople, setInvitedPeople] = useState<InvitationPerson[]>([])
  const [excludedPeople, setExcludedPeople] = useState<InvitationPerson[]>([])
  const [knownPeople, setKnownPeople] = useState<InvitationPerson[]>([])
  const [groupDraft, setGroupDraft] = useState('')
  const [personSub, setPersonSub] = useState('')
  const [excludedSub, setExcludedSub] = useState('')
  const [errors, setErrors] = useState<Record<string, string>>({})
  const [error, setError] = useState<string | null>(null)
  const [incomplete, setIncomplete] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [loaded, setLoaded] = useState(!editing)
  const [baseline, setBaseline] = useState<string | null>(null)

  useEffect(() => {
    if (!id) return
    api<ClubEvent>(`/api/v1/events/${id}`).then((event) => {
      setTitle(event.title)
      setClubs(event.clubs)
      setCategoryId(event.category?.id ?? '')
      setCurrentCategory(event.category)
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
        meetingPoints: day.meetingPoints.map((point) => ({
          key: point.id,
          placeName: point.placeName,
          meetsAt: point.meetsAt ?? '',
          description: point.description ?? '',
        })),
      })))
      setVehicles((event.vehicles ?? []).map((vehicle) => ({
        key: vehicle.vehicleId,
        vehicleId: vehicle.vehicleId,
        rentalStatus: vehicle.rentalStatus ?? '',
      })))
      setInvitedGroups(event.invitations.groups)
      setInvitedPeople(event.invitations.people)
      setExcludedPeople(event.invitations.excluded)
      setLoaded(true)
    }).catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Veranstaltung konnte nicht geladen werden.'))
  }, [id])

  const snapshot = JSON.stringify({
    title, clubs, categoryId, status, internalDescription, publicDescription, publishToWebsite, days, vehicles,
    invitedGroups, invitedPeople, excludedPeople,
  })
  useEffect(() => {
    if (!loaded) return
    setBaseline((current) => current ?? snapshot)
  }, [loaded, snapshot])
  const dirty = baseline !== null && snapshot !== baseline
  useRegisterUnsaved(editing ? `event-${id}` : 'event-new', dirty)

  useEffect(() => {
    api<{ categories: CatalogValue[] }>('/api/v1/event-categories')
      .then((body) => setCategoryOptions(body.categories))
      .catch(() => setCategoryOptions([]))
    api<{ places: Array<{ name: string }> }>('/api/v1/places')
      .then((body) => setPlaces(body.places.map((place) => place.name)))
      .catch(() => setPlaces([]))
    api<{ people: InvitationPerson[] }>('/api/v1/people')
      .then((body) => setKnownPeople(body.people))
      .catch(() => setKnownPeople([]))
    api<FleetList>('/api/v1/vehicles')
      .then((body) => {
        setFleet(body.vehicles)
        setFleetReady(true)
      })
      .catch(() => setFleetReady(false))
  }, [])

  function toggleClub(club: 'historic' | 'supporter') {
    setClubs((current) => current.includes(club) ? current.filter((item) => item !== club) : [...current, club])
  }

  function updateDay(key: string, patch: Partial<DayDraft>) {
    setDays((current) => current.map((day) => day.key === key ? { ...day, ...patch } : day))
  }

  function updateMeeting(dayKey: string, pointKey: string, patch: Partial<MeetingDraft>) {
    setDays((current) => current.map((day) => day.key === dayKey ? {
      ...day,
      meetingPoints: day.meetingPoints.map((point) => point.key === pointKey ? { ...point, ...patch } : point),
    } : day))
  }

  function cancel() {
    void navigate(editing ? `/veranstaltungen/${id}` : '/veranstaltungen')
  }

  function addGroup() {
    const name = groupDraft.trim()
    setGroupDraft('')
    if (name === '' || invitedGroups.includes(name)) return
    setInvitedGroups((current) => [...current, name])
  }

  function addNamed(sub: string, target: 'invite' | 'exclude') {
    const person = knownPeople.find((item) => item.sub === sub)
    if (!person) return
    if (target === 'invite') {
      setExcludedPeople((current) => current.filter((item) => item.sub !== sub))
      setInvitedPeople((current) => current.some((item) => item.sub === sub) ? current : [...current, person])
      setPersonSub('')
      return
    }
    setInvitedPeople((current) => current.filter((item) => item.sub !== sub))
    setExcludedPeople((current) => current.some((item) => item.sub === sub) ? current : [...current, person])
    setExcludedSub('')
  }

  async function save(event: FormEvent) {
    event.preventDefault()
    const missing = missingEventFields(title, clubs, days)
    const localErrors = eventFieldErrors(title, clubs, days)
    if (missing.length > 0) {
      setIncomplete(incompleteMessage(missing))
      setErrors(localErrors)
      setError(null)
      return
    }
    setBusy(true)
    setError(null)
    setIncomplete(null)
    setErrors({})
    const payload = {
      title,
      clubs,
      categoryId: categoryId === '' ? null : categoryId,
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
        meetingPoints: day.meetingPoints
          .filter((point) => point.placeName.trim() !== '' || point.meetsAt !== '' || point.description.trim() !== '')
          .map((point) => ({
            placeName: point.placeName,
            meetsAt: point.meetsAt,
            description: point.description,
          })),
      })),
      invitations: {
        groups: invitedGroups,
        people: invitedPeople.map((person) => ({ sub: person.sub })),
        excluded: excludedPeople.map((person) => ({ sub: person.sub })),
      },
      ...(fleetReady ? {
        vehicles: vehicles
          .filter((vehicle) => vehicle.vehicleId !== '')
          .map((vehicle) => ({
            vehicleId: vehicle.vehicleId,
            rentalStatus: vehicle.rentalStatus,
          })),
      } : {}),
    }
    try {
      const saved = await api<ClubEvent>(editing ? `/api/v1/events/${id}` : '/api/v1/events', {
        method: editing ? 'PUT' : 'POST',
        body: JSON.stringify(payload),
      })
      void navigate(`/veranstaltungen/${saved.id}`)
    } catch (reason) {
      if (reason instanceof ApiError && reason.message === 'Die Eingaben sind unvollständig.') {
        setIncomplete(incompleteMessage(labelsForEventErrors(reason.fields)))
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
        <Link className="btn small light" to={editing ? `/veranstaltungen/${id}` : '/veranstaltungen'}><BtnLabel icon="back" iconOnly={false}>Zurück</BtnLabel></Link>
        <span className="pill orange">{editing ? 'Bearbeitung' : 'Neue Veranstaltung'}</span>
      </div>
      <form className="editor-form" onSubmit={(event) => { void save(event) }}>
        <div className="heading">
          <div>
            <div className="eyebrow">Organisation</div>
            <h1>{editing ? 'Veranstaltung bearbeiten' : 'Veranstaltung anlegen'}</h1>
            <div className="hello">Für Organisatoren und Administratoren.</div>
          </div>
          <button className="btn primary heading-save" disabled={busy} type="submit"><BtnLabel icon="save">Änderungen speichern</BtnLabel></button>
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
                <Field error={errors.categoryId} label="Kategorie">
                  <select aria-label="Kategorie" className="input" value={categoryId} onChange={(event) => setCategoryId(event.target.value)}>
                    <option value="">—</option>
                    {categoryChoices(categoryOptions, currentCategory).map((item) => (
                      <option key={item.id} value={item.id}>{item.active === false ? `${item.name} (inaktiv)` : item.name}</option>
                    ))}
                  </select>
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
                      <input className="input" list="place-directory" value={day.location} onChange={(event) => updateDay(day.key, { location: event.target.value })} />
                    </Field>
                    <div className="formfield">
                      <label>Treffpunkte</label>
                      {day.meetingPoints.map((point, pointIndex) => (
                        <div key={point.key}>
                          <div className="vehicle-line">
                            <input aria-label={`Treffpunkt ${pointIndex + 1}`} className="input" list="place-directory" placeholder="Ort" value={point.placeName} onChange={(event) => updateMeeting(day.key, point.key, { placeName: event.target.value })} />
                            <input aria-label={`Uhrzeit Treffpunkt ${pointIndex + 1}`} className="input" type="time" value={point.meetsAt} onChange={(event) => updateMeeting(day.key, point.key, { meetsAt: event.target.value })} />
                            <button className="btn small light" type="button" onClick={() => updateDay(day.key, { meetingPoints: day.meetingPoints.filter((item) => item.key !== point.key) })}><BtnLabel icon="delete">Entfernen</BtnLabel></button>
                          </div>
                          <input aria-label={`Hinweis Treffpunkt ${pointIndex + 1}`} className="input" placeholder="Hinweis" style={{ marginTop: 6 }} value={point.description} onChange={(event) => updateMeeting(day.key, point.key, { description: event.target.value })} />
                          {errors[`days.${index}.meetingPoints.${pointIndex}.placeName`] || errors[`days.${index}.meetingPoints.${pointIndex}.meetsAt`] ? (
                            <div className="field-error">{errors[`days.${index}.meetingPoints.${pointIndex}.placeName`] ?? errors[`days.${index}.meetingPoints.${pointIndex}.meetsAt`]}</div>
                          ) : null}
                        </div>
                      ))}
                      <button className="btn small light secondary-action" type="button" onClick={() => updateDay(day.key, { meetingPoints: [...day.meetingPoints, blankMeeting()] })}><BtnLabel icon="add">Treffpunkt hinzufügen</BtnLabel></button>
                    </div>
                    {days.length > 1 ? <button className="btn small light" type="button" onClick={() => setDays((current) => current.filter((item) => item.key !== day.key))}><BtnLabel icon="delete">Tag entfernen</BtnLabel></button> : null}
                  </div>
                ))}
                <button className="btn light secondary-action" type="button" onClick={() => setDays((current) => [...current, blankDay()])}><BtnLabel icon="add">Weiteren Tag hinzufügen</BtnLabel></button>
                <datalist id="place-directory">
                  {places.map((place) => <option key={place} value={place} />)}
                </datalist>
              </div>
            </div>
            <div className="panel">
              <div className="panel-head"><h2>Fahrzeugliste</h2></div>
              <div className="panel-inner">
                <div className="note">Die Fahrten verwenden später eine Teilmenge dieser Liste. Externe Fahrzeuge sind Stammdaten und können je Anlass einen Mietstatus tragen.</div>
                {errors.vehicles ? <div className="field-error">{errors.vehicles}</div> : null}
                {vehicles.map((vehicle, index) => {
                  const chosen = fleet.find((item) => item.id === vehicle.vehicleId)
                  return (
                    <div key={vehicle.key}>
                      <div className="vehicle-line">
                        <VehiclePick
                          options={fleet.map((item) => ({ id: item.id, label: `${item.label}${item.external ? ' · Extern' : ''}` }))}
                          value={vehicle.vehicleId}
                          onChange={(vehicleId) => setVehicles((current) => current.map((item) => item.key === vehicle.key ? { ...item, vehicleId } : item))}
                        />
                        {chosen?.external ? (
                          <input aria-label="Mietstatus" className="input" placeholder="Mietstatus" value={vehicle.rentalStatus} onChange={(event) => setVehicles((current) => current.map((item) => item.key === vehicle.key ? { ...item, rentalStatus: event.target.value } : item))} />
                        ) : null}
                        <button className="btn small light" type="button" onClick={() => setVehicles((current) => current.filter((item) => item.key !== vehicle.key))}><BtnLabel icon="delete">Entfernen</BtnLabel></button>
                      </div>
                      {errors[`vehicles.${index}.vehicleId`] || errors[`vehicles.${index}.rentalStatus`] ? (
                        <div className="field-error">{errors[`vehicles.${index}.vehicleId`] ?? errors[`vehicles.${index}.rentalStatus`]}</div>
                      ) : null}
                    </div>
                  )
                })}
                <button className="btn small light" type="button" onClick={() => setVehicles((current) => [...current, { key: crypto.randomUUID(), vehicleId: '', rentalStatus: '' }])}><BtnLabel icon="add">Fahrzeug hinzufügen</BtnLabel></button>
              </div>
            </div>
          </div>
          <div className="panel">
            <div className="panel-head"><h2>Einladungen</h2></div>
            <div className="panel-inner">
              <div className="note">Entwürfe sehen Organisatoren der zugeordneten Vereine. Ohne Liste sehen die Mitglieder dieser Vereine den veröffentlichten Anlass. Gruppen gelten für kommende Anlässe und folgen der aktuellen Gruppenzugehörigkeit. Ausgeschlossene Personen sehen den Anlass nicht. Es wird keine E-Mail verschickt.</div>
              <Field error={errors['invitations.groups']} label="Gruppen">
                <div className="toolbar">
                  <input aria-label="Gruppe" className="input" list="session-groups" placeholder="Gruppe" value={groupDraft} onChange={(event) => setGroupDraft(event.target.value)} />
                  <button className="btn small light" type="button" onClick={addGroup}><BtnLabel icon="add">Hinzufügen</BtnLabel></button>
                </div>
              </Field>
              <datalist id="session-groups">
                {(member?.groups ?? []).map((group) => <option key={group} value={group} />)}
              </datalist>
              {invitedGroups.map((group) => (
                <div className="item" key={group}>
                  <div><b>{group}</b><small>Gruppe</small></div>
                  <button className="btn small light" type="button" onClick={() => setInvitedGroups((current) => current.filter((item) => item !== group))}><BtnLabel icon="delete">Entfernen</BtnLabel></button>
                </div>
              ))}
              <Field error={errors['invitations.people']} label="Personen">
                <div className="toolbar">
                  <select aria-label="Person einladen" className="input" value={personSub} onChange={(event) => setPersonSub(event.target.value)}>
                    <option value="">Bekannte Person</option>
                    {knownPeople.map((person) => <option key={person.sub} value={person.sub}>{person.name}</option>)}
                  </select>
                  <button className="btn small light" type="button" onClick={() => addNamed(personSub, 'invite')}><BtnLabel icon="add" iconOnly={false}>Einladen</BtnLabel></button>
                </div>
              </Field>
              {knownPeople.length === 0 ? <p className="muted">Noch keine bekannte Person. Gruppen können trotzdem eingeladen werden.</p> : null}
              {invitedPeople.map((person) => (
                <div className="item" key={person.sub}>
                  <div><b>{person.name}</b><small>Eingeladen</small></div>
                  <button className="btn small light" type="button" onClick={() => setInvitedPeople((current) => current.filter((item) => item.sub !== person.sub))}><BtnLabel icon="delete">Entfernen</BtnLabel></button>
                </div>
              ))}
              <Field error={errors['invitations.excluded']} label="Ausschliessen">
                <div className="toolbar">
                  <select aria-label="Person ausschliessen" className="input" value={excludedSub} onChange={(event) => setExcludedSub(event.target.value)}>
                    <option value="">Bekannte Person</option>
                    {knownPeople.map((person) => <option key={person.sub} value={person.sub}>{person.name}</option>)}
                  </select>
                  <button className="btn small light" type="button" onClick={() => addNamed(excludedSub, 'exclude')}><BtnLabel icon="close" iconOnly={false}>Ausschliessen</BtnLabel></button>
                </div>
              </Field>
              {excludedPeople.map((person) => (
                <div className="item" key={person.sub}>
                  <div><b>{person.name}</b><small>Ausgeschlossen</small></div>
                  <button className="btn small light" type="button" onClick={() => setExcludedPeople((current) => current.filter((item) => item.sub !== person.sub))}><BtnLabel icon="delete">Entfernen</BtnLabel></button>
                </div>
              ))}
            </div>
          </div>
        </div>
        <MobileSaveBar busy={busy} dirty={dirty} incomplete={incomplete} onCancel={cancel} />
      </form>
    </section>
  )
}

function missingEventFields(title: string, clubs: string[], days: DayDraft[]): string[] {
  const fields: Array<{ label: string; filled: boolean }> = [
    { label: 'Titel der Veranstaltung', filled: title.trim() !== '' },
    { label: 'Verein', filled: clubs.length > 0 },
  ]
  const meaningful = days.map((day, index) => ({ day, index })).filter(({ day }) => !dayIsBlank(day))
  if (meaningful.length === 0) fields.push({ label: 'Datum', filled: false })
  for (const { day, index } of meaningful) {
    fields.push({ label: days.length > 1 ? `Datum (Tag ${index + 1})` : 'Datum', filled: day.date !== '' })
    const points = day.meetingPoints.map((point, pointIndex) => ({ point, pointIndex })).filter(({ point }) => !pointIsBlank(point))
    for (const { point, pointIndex } of points) {
      const suffix = points.length > 1 || days.length > 1 ? ` (Treffpunkt ${pointIndex + 1})` : ''
      fields.push({ label: `Ort${suffix}`, filled: point.placeName.trim() !== '' })
      fields.push({ label: `Uhrzeit${suffix}`, filled: point.meetsAt !== '' })
    }
  }
  return missingFieldLabels(fields)
}

function eventFieldErrors(title: string, clubs: string[], days: DayDraft[]): Record<string, string> {
  const next: Record<string, string> = {}
  if (title.trim() === '') next.title = 'Bitte einen Titel mit höchstens 200 Zeichen angeben.'
  if (clubs.length === 0) next.clubs = 'Bitte mindestens einen Verein auswählen.'
  const meaningful = days.map((day, index) => ({ day, index })).filter(({ day }) => !dayIsBlank(day))
  if (meaningful.length === 0) next.days = 'Bitte mindestens einen Veranstaltungstag angeben.'
  for (const { day, index } of meaningful) {
    if (day.date === '') next[`days.${index}.date`] = 'Bitte ein gültiges Datum angeben.'
    day.meetingPoints.forEach((point, pointIndex) => {
      if (pointIsBlank(point)) return
      if (point.placeName.trim() === '') next[`days.${index}.meetingPoints.${pointIndex}.placeName`] = 'Bitte einen Ort mit höchstens 200 Zeichen angeben.'
      if (point.meetsAt === '') next[`days.${index}.meetingPoints.${pointIndex}.meetsAt`] = 'Bitte die Uhrzeit des Treffpunkts angeben.'
    })
  }
  return next
}

function labelsForEventErrors(fields: Record<string, string> | undefined): string[] {
  if (!fields) return []
  return Object.keys(fields).map((key) => {
    if (key === 'title') return 'Titel der Veranstaltung'
    if (key === 'clubs') return 'Verein'
    if (key === 'categoryId') return 'Kategorie'
    if (key === 'days' || /\.date$/.test(key)) return 'Datum'
    if (key.includes('placeName')) return 'Ort'
    if (key.includes('meetsAt')) return 'Uhrzeit'
    if (key.includes('vehicleId')) return 'Fahrzeug'
    if (key.includes('rentalStatus')) return 'Mietstatus'
    return ''
  })
}

function categoryChoices(options: CatalogValue[], current: CatalogValue | null): CatalogValue[] {
  const active = options.filter((item) => item.active !== false)
  if (current && !active.some((item) => item.id === current.id)) {
    return [current, ...active]
  }
  return active
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
