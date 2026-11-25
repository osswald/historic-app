import { useEffect, useState, type FormEvent, type ReactNode } from 'react'
import { api, ApiError } from '../api/client'
import type { ClubEvent, GastroPlan, GastroView } from '../api/types'
import { AuditHistory } from '../components/AuditHistory'
import { useMember, useToast } from '../shell/context'

type DishDraft = {
  key: string
  name: string
  portions: string
}

type ResponsibleDraft = {
  key: string
  sub: string
  name: string
}

type Draft = {
  notes: string
  guestCount: string
  dishes: DishDraft[]
  responsible: ResponsibleDraft[]
}

export function GastroPlanPanel({ event }: { event: ClubEvent }) {
  const member = useMember()
  const toast = useToast()
  const [view, setView] = useState<GastroView | null>(null)
  const [draft, setDraft] = useState<Draft>(emptyDraft())
  const [error, setError] = useState<string | null>(null)
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({})
  const [busy, setBusy] = useState(false)

  useEffect(() => {
    api<GastroView>(`/api/v1/events/${event.id}/gastronomy`)
      .then((body) => {
        setView(body)
        setDraft(toDraft(body.plan))
      })
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Gastronomieplanung konnte nicht geladen werden.'))
  }, [event.id])

  function updateDish(key: string, patch: Partial<DishDraft>) {
    setDraft((current) => ({
      ...current,
      dishes: current.dishes.map((dish) => dish.key === key ? { ...dish, ...patch } : dish),
    }))
  }

  async function save(submit: FormEvent) {
    submit.preventDefault()
    setBusy(true)
    setError(null)
    setFieldErrors({})
    try {
      const saved = await api<GastroView>(`/api/v1/events/${event.id}/gastronomy`, {
        method: 'PUT',
        body: JSON.stringify({
          notes: draft.notes,
          guestCount: draft.guestCount.trim() === ''
            ? null
            : (/^\d+$/.test(draft.guestCount.trim()) ? Number(draft.guestCount.trim()) : draft.guestCount),
          dishes: draft.dishes
            .filter((dish) => dish.name.trim() !== '' || dish.portions.trim() !== '')
            .map((dish) => ({
              name: dish.name,
              portions: /^\d+$/.test(dish.portions.trim()) ? Number(dish.portions.trim()) : dish.portions,
            })),
          responsible: draft.responsible.map((person) => ({ sub: person.sub, name: person.name })),
        }),
      })
      setView(saved)
      setDraft(toDraft(saved.plan))
      toast('Gastronomieplanung gespeichert.')
    } catch (reason) {
      if (reason instanceof ApiError) {
        setError(reason.message)
        setFieldErrors(reason.fields ?? {})
      } else {
        setError('Die Gastronomieplanung konnte nicht gespeichert werden.')
      }
    } finally {
      setBusy(false)
    }
  }

  if (!view && !error) {
    return <p className="empty">Wird geladen …</p>
  }

  const plan = view?.plan ?? null
  const canEdit = view?.canEdit === true
  const alreadyNamed = member ? draft.responsible.some((person) => person.sub === member.sub) : true

  return (
    <div className="stack">
      {error ? <div className="banner-error">{error}</div> : null}
      <div className="panel">
        <div className="panel-head"><h2>Gastronomieplanung</h2></div>
        <div className="panel-inner" style={{ paddingTop: 0 }}>
          <div className="note">Eine frei geschätzte Gästezahl, Gerichte, Portionen, eine Notiz und die Verantwortlichen. Getränke und Preise werden hier nicht geführt.</div>
        </div>
        {!plan ? <p className="empty">Noch keine Gastronomieplanung.</p> : (
          <div className="panel-inner" style={{ paddingTop: 0 }}>
            {plan.dishes.length === 0 ? <p className="muted">Keine Gerichte erfasst.</p> : plan.dishes.map((dish) => (
              <div className="item" key={dish.id}>
                <div>
                  <b>{dish.name}</b>
                  <small>{dish.portions} Portionen</small>
                </div>
              </div>
            ))}
            <p className="muted">{plan.guestCount === null ? 'Gästezahl noch offen.' : `Geschätzte Gäste: ${plan.guestCount}`}</p>
            {plan.notes ? <p className="ride-note note">{plan.notes}</p> : null}
            <p className="muted">{plan.responsible.length === 0 ? 'Noch niemand verantwortlich.' : `Verantwortlich: ${plan.responsible.map((person) => person.name).join(', ')}`}</p>
          </div>
        )}
      </div>
      {canEdit ? (
        <form onSubmit={(submit) => { void save(submit) }}>
          <div className="panel">
            <div className="panel-head">
              <h2>Planung bearbeiten</h2>
              <button className="btn primary small" disabled={busy} type="submit">Planung speichern</button>
            </div>
            <div className="panel-inner" style={{ paddingTop: 0 }}>
              <div className="note">Administratoren, das Gastro-Team und die hier benannten Verantwortlichen dürfen die Planung ändern.</div>
              <Field error={fieldErrors.guestCount} label="Geschätzte Gästezahl">
                <input className="input" inputMode="numeric" value={draft.guestCount} onChange={(input) => setDraft((current) => ({ ...current, guestCount: input.target.value }))} />
              </Field>
              <div className="formfield">
                <label>Gerichte</label>
                {fieldErrors.dishes ? <div className="field-error">{fieldErrors.dishes}</div> : null}
                {draft.dishes.map((dish, index) => (
                  <div key={dish.key}>
                    <div className="dish-line">
                      <input aria-label={`Gericht ${index + 1}`} className="input" placeholder="Gericht" value={dish.name} onChange={(input) => updateDish(dish.key, { name: input.target.value })} />
                      <input aria-label={`Portionen ${index + 1}`} className="input" inputMode="numeric" placeholder="Portionen" value={dish.portions} onChange={(input) => updateDish(dish.key, { portions: input.target.value })} />
                      <button className="btn small light" type="button" onClick={() => setDraft((current) => ({ ...current, dishes: current.dishes.filter((item) => item.key !== dish.key) }))}>Entfernen</button>
                    </div>
                    {fieldErrors[`dishes.${index}.name`] || fieldErrors[`dishes.${index}.portions`] ? (
                      <div className="field-error">{fieldErrors[`dishes.${index}.name`] ?? fieldErrors[`dishes.${index}.portions`]}</div>
                    ) : null}
                  </div>
                ))}
                <button className="btn small light" type="button" onClick={() => setDraft((current) => ({ ...current, dishes: [...current.dishes, blankDish()] }))}>Gericht hinzufügen</button>
              </div>
              <Field error={fieldErrors.notes} label="Notiz">
                <textarea className="input" value={draft.notes} onChange={(input) => setDraft((current) => ({ ...current, notes: input.target.value }))} />
              </Field>
              <div className="formfield">
                <label>Verantwortliche</label>
                {fieldErrors.responsible ? <div className="field-error">{fieldErrors.responsible}</div> : null}
                {draft.responsible.length === 0 ? <p className="muted">Noch niemand benannt.</p> : draft.responsible.map((person) => (
                  <div className="responsible-line" key={person.key}>
                    <span>{person.name}</span>
                    <button className="btn small light" type="button" onClick={() => setDraft((current) => ({ ...current, responsible: current.responsible.filter((item) => item.key !== person.key) }))}>Entfernen</button>
                  </div>
                ))}
                {member && !alreadyNamed ? (
                  <button className="btn small light" type="button" onClick={() => setDraft((current) => ({
                    ...current,
                    responsible: [...current.responsible, { key: member.sub, sub: member.sub, name: member.displayName }],
                  }))}>Mich eintragen</button>
                ) : null}
              </div>
            </div>
          </div>
        </form>
      ) : null}
      {canEdit && plan ? <AuditHistory entityId={plan.id} entityType="gastro_plan" /> : null}
    </div>
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

function blankDish(): DishDraft {
  return { key: crypto.randomUUID(), name: '', portions: '' }
}

function emptyDraft(): Draft {
  return { notes: '', guestCount: '', dishes: [], responsible: [] }
}

function toDraft(plan: GastroPlan | null): Draft {
  if (!plan) {
    return emptyDraft()
  }
  return {
    notes: plan.notes ?? '',
    guestCount: plan.guestCount === null ? '' : String(plan.guestCount),
    dishes: plan.dishes.map((dish) => ({ key: dish.id, name: dish.name, portions: String(dish.portions) })),
    responsible: plan.responsible.map((person) => ({ key: person.sub, sub: person.sub, name: person.name })),
  }
}
