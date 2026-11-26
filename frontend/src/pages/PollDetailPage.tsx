import { useEffect, useState } from 'react'
import { Link, useParams } from 'react-router'
import { api, ApiError } from '../api/client'
import type { Answer, Daypart, PollDetail, PollOption } from '../api/types'
import { AuditHistoryButton } from '../components/AuditHistory'
import { BtnLabel } from '../design/BtnLabel'
import { useNarrow } from '../forms/narrow'
import { useMember, useToast } from '../shell/context'

const DAYPARTS: Daypart[] = ['morning', 'afternoon', 'evening']

export function PollDetailPage() {
  const { id } = useParams()
  const member = useMember()
  const toast = useToast()
  const [poll, setPoll] = useState<PollDetail | null>(null)
  const [choices, setChoices] = useState<Record<string, Answer | ''>>({})
  const [comment, setComment] = useState('')
  const [picked, setPicked] = useState<Record<string, boolean>>({})
  const [statuses, setStatuses] = useState<Record<string, 'draft' | 'published'>>({})
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [onlyOpen, setOnlyOpen] = useState(false)
  const [openDays, setOpenDays] = useState<Record<string, boolean>>({})
  const narrow = useNarrow()

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
    setBusy(true)
    setError(null)
    try {
      const saved = await api<PollDetail>(`/api/v1/polls/${poll.id}/answers`, {
        method: 'POST',
        body: JSON.stringify({
          comment,
          votes: poll.options
            .filter((option) => choices[option.id] === 'yes' || choices[option.id] === 'no')
            .map((option) => ({ optionId: option.id, answer: choices[option.id] })),
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
    const adoptions = poll.options
      .filter((option) => picked[option.id] && !option.adoptedEventId)
      .map((option) => ({ optionId: option.id, status: statuses[option.id] ?? 'draft' }))
    if (adoptions.length === 0) {
      setError('Bitte mindestens einen noch nicht übernommenen Vorschlag auswählen.')
      return
    }
    setBusy(true)
    setError(null)
    try {
      const created = await api<{ events: Array<{ id: string; title: string }> }>(`/api/v1/polls/${poll.id}/event`, {
        method: 'POST',
        body: JSON.stringify({ adoptions }),
      })
      const fresh = await api<PollDetail>(`/api/v1/polls/${poll.id}`)
      apply(fresh)
      setPicked({})
      toast(created.events.length === 1 ? 'Veranstaltung erzeugt.' : `${created.events.length} Veranstaltungen erzeugt.`)
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Die Veranstaltung konnte nicht erzeugt werden.')
    } finally {
      setBusy(false)
    }
  }

  if (!poll) {
    return <section>{error ? <div className="banner-error">{error}</div> : <p className="empty">Wird geladen …</p>}</section>
  }

  const locked = poll.status === 'closed'

  return (
    <section>
      <div className="heading">
        <div>
          <div className="eyebrow">{poll.kindLabel}{poll.location ? ` · ${poll.location}` : ''}</div>
          <h1>{poll.title}</h1>
          <div className="hello">{poll.description ?? 'Markiere alle Zeitfenster, an denen du verfügbar bist.'}</div>
        </div>
        {member?.canOrganize ? (
          <div className="detail-actions">
            <AuditHistoryButton entityId={poll.id} entityType="poll" />
            <Link className="btn light" to={`/umfragen/${poll.id}/bearbeiten`}><BtnLabel icon="edit">Bearbeiten</BtnLabel></Link>
          </div>
        ) : null}
      </div>
      {error ? <div className="banner-error">{error}</div> : null}
      <div className="panel">
        <div className="panel-head">
          <h2>Meine Verfügbarkeit</h2>
          <span className={poll.myState === 'complete' ? 'pill green' : 'pill orange'}>
            {poll.status === 'closed' ? 'Geschlossen' : poll.myStateLabel}
          </span>
        </div>
        <div className="panel-inner" style={{ paddingTop: 0 }}>
          <p className="muted" style={{ margin: '0 0 13px' }}>
            {poll.kind === 'depot'
              ? 'Monatsumfrage: alle eingetragenen Kalendertage mit Vormittag, Nachmittag und Abend. '
              : ''}
            Speichern setzt nicht markierte aktuelle Optionen auf Nein. Eine später ergänzte oder geänderte Option bleibt offen und verlangt eine Ergänzung.
            {poll.deadlinePassed && poll.status !== 'closed' ? ' Die Antwortfrist ist abgelaufen und dient später nur der Erinnerung.' : ''}
          </p>
          {poll.kind === 'depot' && narrow ? (
            <label className="toggle">
              <input checked={onlyOpen} type="checkbox" onChange={(event) => setOnlyOpen(event.target.checked)} />
              Nur offene Tage
            </label>
          ) : null}
          {poll.kind === 'depot' ? poll.days.map((day) => {
            const open = day.options.some((option) => option.needsSupplement || (choices[option.id] ?? '') === '')
            if (narrow && onlyOpen && !open) return null
            const slots = DAYPARTS.map((daypart) => {
              const option = day.options.find((item) => item.daypart === daypart)
              return option ? <Slot busy={busy} choice={choices[option.id] ?? ''} key={option.id} locked={locked} option={option} onChoose={choose} /> : <div key={daypart} />
            })
            if (!narrow) {
              return (
                <div className={day.options.some((option) => option.needsSupplement) ? 'surveyrow needs' : 'surveyrow'} key={day.date}>
                  <div>
                    <b>{day.label}</b>
                    <small>Depottag Winterthur</small>
                  </div>
                  {slots}
                </div>
              )
            }
            const expanded = openDays[day.date] === true
            return (
              <div className="day-fold" key={day.date}>
                <button aria-expanded={expanded} className="day-fold-head" type="button" onClick={() => setOpenDays((current) => ({ ...current, [day.date]: !expanded }))}>
                  <span>{day.label}</span>
                  <small>{open ? 'Offen' : 'Beantwortet'}</small>
                  <span>{expanded ? '▾' : '▸'}</span>
                </button>
                {expanded ? <div className="day-fold-body">{slots}</div> : null}
              </div>
            )
          }) : poll.options.map((option) => (
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
            <button className="btn light" disabled={busy || locked} type="button" onClick={declineAll}><BtnLabel icon="close" iconOnly={false}>Generell Nein</BtnLabel></button>
            <button className="btn primary" disabled={busy || locked} type="button" onClick={() => { void save() }}><BtnLabel icon="save">Antwort speichern</BtnLabel></button>
          </div>
        </div>
      </div>
      {member?.canOrganize ? (
        <div className="panel" style={{ marginTop: 16 }}>
          <div className="panel-head"><h2>Als Veranstaltung übernehmen</h2></div>
          <div className="panel-inner">
            <p className="muted" style={{ marginTop: 0 }}>Jeder Vorschlag kann einzeln als Entwurf oder veröffentlicht übernommen werden. Ein bereits übernommener Vorschlag bleibt gesperrt. Die Antworten der Umfrage werden nicht übernommen.</p>
            <div className="stack">
              {poll.options.map((option) => (
                option.adoptedEventId ? (
                  <p key={option.id}>
                    {option.dateLabel}{option.daypartLabel ? ` · ${option.daypartLabel}` : ''} ist übernommen. <Link to={`/veranstaltungen/${option.adoptedEventId}`}>Zur Veranstaltung ›</Link>
                  </p>
                ) : (
                  <div className="vehicle-line" key={option.id}>
                    <label className="toggle">
                      <input checked={picked[option.id] === true} type="checkbox" onChange={() => setPicked((current) => ({ ...current, [option.id]: !current[option.id] }))} />
                      {option.dateLabel}{option.daypartLabel ? ` · ${option.daypartLabel}` : ''}{option.startsAt ? ` · ${timeLabel(option)}` : ''}
                    </label>
                    <select className="input" value={statuses[option.id] ?? 'draft'} onChange={(event) => setStatuses((current) => ({ ...current, [option.id]: event.target.value === 'published' ? 'published' : 'draft' }))}>
                      <option value="draft">Entwurf</option>
                      <option value="published">Veröffentlicht</option>
                    </select>
                  </div>
                )
              ))}
            </div>
            <button className="btn primary" disabled={busy} style={{ marginTop: 14 }} type="button" onClick={() => { void adopt() }}><BtnLabel icon="check" iconOnly={false}>Ausgewählte übernehmen</BtnLabel></button>
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
        <div className="choice-pair">
          <button className={choice === 'yes' ? 'btn small choice active-yes' : 'btn small choice'} disabled={locked || busy} type="button" onClick={() => onChoose(option.id, 'yes')}><BtnLabel icon="check" iconOnly={false}>Ja</BtnLabel></button>
          <button className={choice === 'no' ? 'btn small choice active-no' : 'btn small choice'} disabled={locked || busy} type="button" onClick={() => onChoose(option.id, 'no')}><BtnLabel icon="close" iconOnly={false}>Nein</BtnLabel></button>
        </div>
        {option.needsSupplement ? <small>Ergänzung erforderlich</small> : null}
        <AnswerLines answers={option.yesAnswers ?? []} count={option.yesCount} label="Ja" />
        <AnswerLines answers={option.noAnswers ?? []} count={option.noCount} label="Nein" />
      </div>
    </div>
  )
}

function AnswerLines({ answers, count, label }: { answers: Array<{ name: string; comment: string | null }>; count: number; label: string }) {
  if (count === 0) return null
  return (
    <div className="yes-names">
      {count} {label}
      {answers.map((answer) => (
        <div key={`${label}-${answer.name}`}>{answer.name}{answer.comment ? ` · ${answer.comment}` : ''}</div>
      ))}
    </div>
  )
}

function timeLabel(option: PollOption): string {
  if (option.startsAt && option.endsAt) return `${option.startsAt}–${option.endsAt}`
  if (option.startsAt) return option.startsAt
  return 'Ohne Uhrzeit'
}
