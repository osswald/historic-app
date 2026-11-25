import { startDevSession } from '../auth/session'

export function LoginPage({ onLogin }: { onLogin: () => void }) {
  return (
    <div className="app login-app">
      <header className="top">
        <div className="brand">
          <div aria-hidden="true" className="brandmark">⇄</div>
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
            <p>Die Vereinsplattform ist den Mitgliedern des SBB Historic-Team Winterthur und des Supporterclubs vorbehalten.</p>
            <p className="muted">In dieser Entwicklungsumgebung meldest du dich als Demomitglied an. Sobald die Auth0-Anwendung eingetragen ist, läuft die Anmeldung über das bestehende Vereinslogin.</p>
            <button className="btn primary" type="button" onClick={() => { startDevSession(); onLogin() }}>Als Demomitglied weiter</button>
          </div>
        </div>
      </div>
    </div>
  )
}
