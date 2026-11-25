import { useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { AuditEntry } from '../api/types'

export function AuditHistory({ entityType, entityId }: { entityType: string; entityId: string }) {
  const [entries, setEntries] = useState<AuditEntry[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    const params = new URLSearchParams({ entityType, entityId })
    api<{ entries: AuditEntry[] }>(`/api/v1/audit?${params.toString()}`)
      .then((body) => setEntries(body.entries))
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Der Änderungsverlauf konnte nicht geladen werden.'))
  }, [entityType, entityId])

  return (
    <div className="panel">
      <div className="panel-head"><h2>Änderungsverlauf</h2></div>
      <div className="panel-inner" style={{ paddingTop: 0 }}>
        {error ? <div className="field-error">{error}</div> : null}
        {!entries && !error ? <p className="muted">Wird geladen …</p> : null}
        {entries && entries.length === 0 ? <p className="muted">Noch keine Einträge.</p> : null}
        {entries?.map((entry) => (
          <div className="item" key={entry.id}>
            <div>
              <b>{entry.actionLabel}</b>
              <small>{entry.occurredAt} · {entry.summary}</small>
            </div>
          </div>
        ))}
      </div>
    </div>
  )
}
