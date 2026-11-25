import { useEffect, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router'
import { api, ApiError } from '../api/client'
import type { Answer, Daypart, PollDetail, PollOption } from '../api/types'
import { useMember, useToast } from '../shell/context'

const DAYPARTS: Daypart[] = ['morning', 'afternoon', 'evening']

export function PollDetailPage() {
  const { id } = useParams()
  const member = useMember()
  const toast = useToast()
  const navigate = useNavigate()
  const [poll, setPoll] = useState<PollDetail | null>(null)
  const [choices, setChoices] = useState<Record<string, Answer | ''>>({})
  const [comment, setComment] = useState('')
  const [picked, setPicked] = useState<Record<string, boolean>>({})
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)

  function apply(next: PollDetail) {
    setPoll(next)
    const answers: Record<string, Answer | ''> = {}
    for (const option of next.options) answers[option.id] = option.answer ?? ''
    setChoices(answers)
    setComment(next.comment ?? '')
  }

  useEffect(() => {
    if (!id) return
    api<PollDetail>(`/api/v1/polls/${id}`)
      .then(apply)
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Umfrage konnte nicht geladen werden.'))
  }, [id])

  function choose(optionId: string, value: Answer) {
    setChoices((current) => ({ ...current, [optionId]: value }))
  }

  function declineAll() {
    if (!poll) return
    const next: Record<string, Answer | ''> = {}
    for (const option of poll.options) next[option.id] = 'no'
    setChoices(next)
  }

  async function save() {
    if (!poll) return
    if (poll.options.some((option) => choices[option.id] !== 'yes' && choices[option.id] !== 'no')) {
      setError('Bitte jede Option mit Ja oder Nein beantworten. Speichern gilt erst für alle angezeigten Optionen.')
      return
    }
    setBusy(true)
    setError(null)
    try {
      const saved = await api<PollDetail>(`/api/v1/polls/${poll.id}/answers`, {
        method: 'POST',
        body: JSON.stringify({
          comment,
          votes: poll.options.map((option) => ({ optionId: option.id, answer: choices[option.id] })),
        }),
      })
      apply(saved)
      toast('Antwort gespeichert.')
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Die Antwort konnte nicht gespeichert werden.')
    } finally {
      setBusy(false)
    }
  }

  async function adopt() {
    if (!poll) return
    const optionIds = poll.options.filter((option) => picked[option.id]).map((option) => option.id)
    if (optionIds.length === 0) {
      setError('Bitte mindestens eine Option für die Veranstaltung auswählen.')
      return
    }
    setBusy(true)
    setError(null)
    try {
      const event = await api<{ id: string }>(`/api/v1/polls/${poll.id}/event`, {
        method: 'POST',
        body: JSON.stringify({ optionIds }),
      })
      toast('Veranstaltung erzeugt. Die Antworten gelten dort neu.')
      void navigate(`/veranstaltungen/${event.id}`)
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Die Veranstaltung konnte nicht erzeugt werden.')
      setBusy(false)
    }
  }

  if (!poll) {
    return <section>{error ? <div className="banner-error">{error}</div> : <p className="empty">Wird geladen …</p>}</section>
  }

  const locked = !poll.acceptsAnswers

  return (
    <section>
      <div className="heading">
        <div>
          <div className="eyebrow">{poll.kindLabel}{poll.location ? ` · ${poll.location}` : ''}</div>
          <h1>{poll.title}</h1>
          <div className="hello">{poll.description ?? 'Markiere alle Zeitfenster, an denen du verfügbar bist.'}</div>
        </div>
        {member?.canOrganize ? <Link className="btn light" to={`/umfragen/${poll.id}/bearbeiten`}>Bearbeiten</Link> : null}
      </div>
      {error ? <div className="banner-error">{error}</div> : null}
      <div className="panel">
        <div className="panel-head">
          <h2>Meine Verfügbarkeit</h2>
          <span className={poll.myState === 'complete' ? 'pill green' : 'pill orange'}>
            {locked && poll.status === 'closed' ? 'Geschlossen' : locked && poll.deadlinePassed ? 'Antwortfrist abgelaufen' : poll.myStateLabel}
          </span>
        </div>
        <div className="panel-inner" style={{ paddingTop: 0 }}>
          <p className="muted" style={{ margin: '0 0 13px' }}>
            Erst Speichern setzt Ja oder Nein für alle aktuellen Optionen. Eine später ergänzte oder geänderte Option bleibt offen und verlangt eine Ergänzung.
          </p>
          {poll.kind === 'depot' ? poll.days.map((day) => (
            <div className={day.options.some((option) => option.needsSupplement) ? 'surveyrow needs' : 'surveyrow'} key={day.date}>
              <div>
                <b>{day.label}</b>
                <small>Depottag Winterthur</small>
              </div>
              {DAYPARTS.map((daypart) => {
                const option = day.options.find((item) => item.daypart === daypart)
                return option ? <Slot busy={busy} choice={choices[option.id] ?? ''} key={option.id} locked={locked} option={option} onChoose={choose} /> : <div key={daypart} />
              })}
            </div>
          )) : poll.options.map((option) => (
            <div className={option.needsSupplement ? 'surveyrow needs normal' : 'surveyrow normal'} key={option.id}>
              <div>
                <b>{option.dateLabel}</b>
                <small>{timeLabel(option)}</small>
              </div>
              <Slot busy={busy} choice={choices[option.id] ?? ''} locked={locked} option={option} onChoose={choose} />
            </div>
          ))}
          <div className="formfield comment-line">
            <label htmlFor="poll-comment">Kommentar</label>
            <textarea className="input" disabled={locked} id="poll-comment" value={comment} onChange={(event) => setComment(event.target.value)} />
          </div>
          <div className="poll-actions">
            <button className="btn light" disabled={busy || locked} type="button" onClick={declineAll}>Generell Nein</button>
            <button className="btn primary" disabled={busy || locked} type="button" onClick={() => { void save() }}>Antwort speichern</button>
          </div>
        </div>
      </div>
      {poll.eventId ? (
        <p className="note">Aus dieser Umfrage wurde bereits eine Veranstaltung erzeugt. Die Zusagen dort sind neu. <Link to={`/veranstaltungen/${poll.eventId}`}>Zur Veranstaltung ›</Link></p>
      ) : null}
      {member?.canOrganize && !poll.eventId ? (
        <div className="panel" style={{ marginTop: 16 }}>
          <div className="panel-head"><h2>Als Veranstaltung übernehmen</h2></div>
          <div className="panel-inner">
            <p className="muted" style={{ marginTop: 0 }}>Es entsteht höchstens eine Veranstaltung. Die Antworten der Umfrage werden nicht übernommen.</p>
            <div className="stack">
              {poll.options.map((option) => (
                <label className="toggle" key={option.id}>
                  <input checked={picked[option.id] === true} type="checkbox" onChange={() => setPicked((current) => ({ ...current, [option.id]: !current[option.id] }))} />
                  {option.dateLabel}{option.daypartLabel ? ` · ${option.daypartLabel}` : ''}{option.startsAt ? ` · ${timeLabel(option)}` : ''}
                </label>
              ))}
            </div>
            <button className="btn primary" disabled={busy} style={{ marginTop: 14 }} type="button" onClick={() => { void adopt() }}>Veranstaltung erzeugen</button>
          </div>
        </div>
      ) : null}
    </section>
  )
}

function Slot({ option, choice, locked, busy, onChoose }: { option: PollOption; choice: Answer | ''; locked: boolean; busy: boolean; onChoose: (id: string, value: Answer) => void }) {
  return (
    <div className="slot">
      <div>
        {option.daypartLabel ? <div className="slot-label">{option.daypartLabel}</div> : null}
        <div className="toolbar">
          <button className={choice === 'yes' ? 'btn small choice active-yes' : 'btn small choice'} disabled={locked || busy} type="button" onClick={() => onChoose(option.id, 'yes')}>✓ Ja</button>
          <button className={choice === 'no' ? 'btn small choice active-no' : 'btn small choice'} disabled={locked || busy} type="button" onClick={() => onChoose(option.id, 'no')}>✕ Nein</button>
        </div>
        {option.needsSupplement ? <small>Ergänzung erforderlich</small> : null}
        {option.yesCount > 0 ? <div className="yes-names">{option.yesCount} Ja · {option.yesNames.join(', ')}</div> : null}
      </div>
    </div>
  )
}

function timeLabel(option: PollOption): string {
  if (option.startsAt && option.endsAt) return `${option.startsAt}–${option.endsAt}`
  if (option.startsAt) return option.startsAt
  return 'Ohne Uhrzeit'
}
