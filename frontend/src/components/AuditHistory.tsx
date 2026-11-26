import { useCallback, useEffect, useId, useRef, useState } from 'react'
import { api, ApiError } from '../api/client'
import { BtnLabel } from '../design/BtnLabel'
import type { AuditChange, AuditEntry } from '../api/types'

export function AuditHistoryButton({ entityType, entityId }: { entityType: string; entityId: string }) {
  const [open, setOpen] = useState(false)
  const titleId = useId()
  const close = useCallback(() => setOpen(false), [])

  return (
    <>
      <button
        aria-expanded={open}
        aria-haspopup="dialog"
        aria-label="Änderungsverlauf"
        className="btn small light audit-open"
        title="Änderungsverlauf"
        type="button"
        onClick={() => setOpen(true)}
      >
        <HistoryIcon />
      </button>
      {open ? (
        <AuditDialog entityId={entityId} entityType={entityType} titleId={titleId} onClose={close} />
      ) : null}
    </>
  )
}

function AuditDialog({
  entityType,
  entityId,
  titleId,
  onClose,
}: {
  entityType: string
  entityId: string
  titleId: string
  onClose: () => void
}) {
  const [entries, setEntries] = useState<AuditEntry[] | null>(null)
  const [error, setError] = useState<string | null>(null)
  const closeRef = useRef<HTMLButtonElement>(null)

  useEffect(() => {
    let cancelled = false
    const params = new URLSearchParams({ entityType, entityId })
    api<{ entries: AuditEntry[] }>(`/api/v1/audit?${params.toString()}`)
      .then((body) => {
        if (!cancelled) setEntries(body.entries)
      })
      .catch((reason: unknown) => {
        if (!cancelled) setError(reason instanceof ApiError ? reason.message : 'Der Änderungsverlauf konnte nicht geladen werden.')
      })
    return () => {
      cancelled = true
    }
  }, [entityType, entityId])

  useEffect(() => {
    closeRef.current?.focus()
    function onKey(event: KeyboardEvent) {
      if (event.key === 'Escape') onClose()
    }
    document.addEventListener('keydown', onKey)
    return () => document.removeEventListener('keydown', onKey)
  }, [onClose])

  return (
    <div className="audit-backdrop" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose() }}>
      <div aria-labelledby={titleId} aria-modal="true" className="audit-modal" role="dialog">
        <div className="audit-modal-head">
          <h2 id={titleId}>Änderungsverlauf</h2>
          <button className="btn small light audit-close" ref={closeRef} type="button" onClick={onClose}><BtnLabel icon="close">Schliessen</BtnLabel></button>
        </div>
        {error ? <div className="field-error">{error}</div> : null}
        {!entries && !error ? <p className="muted">Wird geladen …</p> : null}
        {entries && entries.length === 0 ? <p className="muted">Noch keine Einträge.</p> : null}
        {entries?.map((entry) => <AuditRow entry={entry} key={entry.id} />)}
      </div>
    </div>
  )
}

function AuditRow({ entry }: { entry: AuditEntry }) {
  const changes = (entry.changes ?? []).flatMap((change) => {
    const line = changeLine(change)
    return line === null ? [] : [{ label: change.label, line }]
  })

  return (
    <article className="audit-entry">
      {entry.occurredAt ? <p className="audit-when">{entry.occurredAt}</p> : null}
      {entry.actor ? <p className="audit-who">{entry.actor}</p> : null}
      <p className="audit-what">{entry.actionLabel}</p>
      {changes.map((change, index) => (
        <p className="audit-change" key={`${entry.id}-${index}`}>
          <span className="audit-field">{change.label}</span>
          <span className="audit-values">{change.line}</span>
        </p>
      ))}
    </article>
  )
}

function changeLine(change: AuditChange): string | null {
  const before = change.before?.trim() ?? ''
  const after = change.after?.trim() ?? ''
  if (before === '' && after === '') return null
  return `${before === '' ? '—' : change.before} → ${after === '' ? '—' : change.after}`
}

function HistoryIcon() {
  return (
    <svg aria-hidden="true" height="18" viewBox="0 0 18 18" width="18">
      <circle cx="9" cy="9" fill="none" r="6.25" stroke="currentColor" strokeWidth="1.6" />
      <path d="M9 5.4V9.15L11.55 10.65" fill="none" stroke="currentColor" strokeLinecap="round" strokeLinejoin="round" strokeWidth="1.6" />
    </svg>
  )
}
