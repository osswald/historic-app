import { useEffect } from 'react'
import { startDevSession } from '../auth/session'

const NOTICES: Record<string, string> = {
  fehlgeschlagen: 'Die Anmeldung hat nicht geklappt. Bitte versuche es erneut.',
  'nicht-bereit': 'Die Auth0-Anmeldung ist noch nicht eingerichtet.',
}

export function LoginPage({ checking = false, onLogin }: { checking?: boolean; onLogin: () => void }) {
  const notice = NOTICES[new URLSearchParams(window.location.search).get('anmeldung') ?? ''] ?? null

  useEffect(() => {
    if (!notice) return
    const url = new URL(window.location.href)
    url.searchParams.delete('anmeldung')
    const next = `${url.pathname}${url.search}${url.hash}`
    window.history.replaceState(null, '', next === '' ? '/' : next)
  }, [notice])

  return (
    <div className="app login-app">
      <header className="top">
        <div className="brand">
          <div className="brand-logo">
            <img src="/club-logo.png" alt="Historic Team Winterthur" />
          </div>
          <div className="brand-name">
            SBB Historic-Team Winterthur
            <div className="brand-sub">Industriekulturerbe auf Schienen.</div>
          </div>
        </div>
        <div className="top-actions">
          <a className="public-link" href="https://new.historic-winterthur.ch/" rel="noopener noreferrer" target="_blank">Zur öffentlichen Website ↗</a>
        </div>
      </header>
      <div className="login-wrap">
        <div className="panel login-card">
          <div className="panel-head"><h2>Anmeldung</h2></div>
          <div className="panel-inner">
            {checking ? <p>Wird geladen …</p> : (
              <>
                {notice ? <div className="banner-error">{notice}</div> : null}
                <p>Die Vereinsplattform ist den Mitgliedern des SBB Historic-Team Winterthur und des Supporterclubs vorbehalten.</p>
                <p className="muted">Die Anmeldung läuft über das Vereinslogin. Die Demo-Anmeldung bleibt vorerst zusätzlich verfügbar.</p>
                <div className="login-actions">
                  <a className="btn primary" href="/api/v1/auth/login">Mit Auth0 anmelden</a>
                  <button className="btn light" type="button" onClick={() => { startDevSession(); onLogin() }}>Als Demomitglied weiter</button>
                </div>
              </>
            )}
          </div>
        </div>
      </div>
    </div>
  )
}
