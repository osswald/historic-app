import { useEffect, useState } from 'react'
import { Link } from 'react-router'
import { api, ApiError } from '../api/client'
import type { GastroOverviewRow } from '../api/types'

export function GastroPage() {
  const [rows, setRows] = useState<GastroOverviewRow[] | null>(null)
  const [error, setError] = useState<string | null>(null)

  useEffect(() => {
    api<{ plans: GastroOverviewRow[] }>('/api/v1/gastronomy')
      .then((body) => setRows(body.plans))
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Gastronomieplanungen konnten nicht geladen werden.'))
  }, [])

  return (
    <section>
      <div className="heading">
        <div>
          <div className="eyebrow">Supporterclub</div>
          <h1>Gastronomieplanung</h1>
          <div className="hello">Pro Veranstaltung eine Planung mit geschätzter Gästezahl.</div>
        </div>
      </div>
      {error ? <div className="banner-error">{error}</div> : null}
      <div className="panel">
        {!rows && !error ? <p className="empty">Wird geladen …</p> : null}
        {rows && rows.length === 0 ? <p className="empty">Keine sichtbare Veranstaltung.</p> : null}
        {rows?.map((row) => (
          <div className="item" key={row.eventId}>
            <div>
              <b>{row.title}</b>
              <small>
                {row.statusLabel}
                {row.hasPlan
                  ? ` · ${row.guestCount === null ? 'Gästezahl offen' : `${row.guestCount} Gäste`}${row.dishes.length > 0 ? ` · ${row.dishes.join(', ')}` : ''}`
                  : ' · Noch keine Planung'}
              </small>
            </div>
            <Link to={`/veranstaltungen/${row.eventId}?bereich=gastro`}>Öffnen</Link>
          </div>
        ))}
      </div>
    </section>
  )
}
