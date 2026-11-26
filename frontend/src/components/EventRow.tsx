import { Link } from 'react-router'
import type { EventCard } from '../api/types'

export function responsePill(card: Pick<EventCard, 'status' | 'response' | 'responseLabel' | 'statusLabel'>): { className: string; label: string } {
  if (card.status === 'cancelled') {
    return { className: 'pill red', label: card.statusLabel }
  }
  if (card.status === 'draft') {
    return { className: 'pill gray', label: card.statusLabel }
  }
  if (card.response === 'yes') {
    return { className: 'pill green', label: card.responseLabel }
  }
  if (card.response === 'no') {
    return { className: 'pill gray', label: card.responseLabel }
  }
  return { className: 'pill orange', label: card.responseLabel }
}

export function EventRow({
  card,
  busy,
  onAnswer,
}: {
  card: EventCard
  busy?: boolean
  onAnswer?: (dayId: string, answer: 'yes' | 'no') => void
}) {
  const pill = responsePill(card)
  return (
    <div className="eventrow">
      <div className="datebox">
        <b>{card.day}</b>
        <span>{card.month}</span>
      </div>
      <div className="eventcopy">
        <h3><Link to={`/veranstaltungen/${card.id}`}>{card.title}</Link></h3>
        <div className="eventmeta">
          {card.line1}
          {card.line2 ? <><br />{card.line2}</> : null}
        </div>
        {onAnswer && card.status === 'published' ? (
          <div className="actions choice-pair">
            <button className={card.response === 'yes' ? 'btn small choice active-yes' : 'btn small choice'} disabled={busy} type="button" onClick={() => onAnswer(card.dayId, 'yes')}>✓ Ja</button>
            <button className={card.response === 'no' ? 'btn small choice active-no' : 'btn small choice'} disabled={busy} type="button" onClick={() => onAnswer(card.dayId, 'no')}>✕ Nein</button>
          </div>
        ) : null}
      </div>
      <span className={pill.className}>{pill.label}</span>
    </div>
  )
}
