import { useState, type ReactNode } from 'react'
import { useLocation, useNavigate } from 'react-router'
import { endSession } from '../auth/session'
import { useMember, useToast } from './context'

const ITEMS = [
  { id: 'dashboard', to: '/', label: 'Start', icon: '⌂' },
  { id: 'calendar', to: '/kalender', label: 'Kalender', icon: '▦' },
  { id: 'events', to: '/veranstaltungen', label: 'Veranstaltungen', icon: '▤' },
  { id: 'surveys', to: '/umfragen', label: 'Terminumfragen', icon: '☷' },
  { id: 'vehicles', to: '/fahrzeuge', label: 'Fahrzeuge', icon: '▰' },
  { id: 'gastronomy', to: '/gastronomie', label: 'Gastronomie', icon: '♧' },
]

function current(path: string): string {
  if (path.startsWith('/kalender')) return 'calendar'
  if (path.startsWith('/veranstaltungen')) return 'events'
  if (path.startsWith('/umfragen')) return 'surveys'
  if (path.startsWith('/fahrzeuge')) return 'vehicles'
  if (path.startsWith('/gastronomie')) return 'gastronomy'
  return 'dashboard'
}

function initials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean)
  if (parts.length === 0) return '–'
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase()
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
}

export function Shell({ children }: { children: ReactNode }) {
  const location = useLocation()
  const navigate = useNavigate()
  const member = useMember()
  const toast = useToast()
  const [more, setMore] = useState(false)
  const active = current(location.pathname)
  const name = member?.displayName ?? 'Mitglied'

  function go(to: string) {
    setMore(false)
    void navigate(to)
  }

  return (
    <div className="app">
      <header className="top">
        <div className="brand">
          <div className="brand-logo">
            <img src="/club-logo.png" alt="Historic Team Winterthur" />
          </div>
        </div>
        <div className="top-actions">
          <a className="public-link" href="https://new.historic-winterthur.ch/" rel="noopener noreferrer" target="_blank">Zur öffentlichen Website ↗</a>
          <button className="ghost desktop-only" type="button" onClick={() => toast('Benachrichtigungen folgen mit der E-Mail- und Push-Anbindung.')}>♧ Benachrichtigungen</button>
          <button className="avatar" title={name} type="button" onClick={() => endSession()}>{initials(name)}</button>
        </div>
      </header>
      <div className="primary-nav">
        <nav className="nav" aria-label="Hauptnavigation">
          {ITEMS.map((item) => (
            <button className={active === item.id ? 'selected' : ''} key={item.id} type="button" onClick={() => go(item.to)}>
              <span className="sym">{item.icon}</span>
              {item.label}
            </button>
          ))}
        </nav>
      </div>
      <div className="wrap">
        <main>{children}</main>
      </div>
      <nav aria-label="Mobile Hauptnavigation" className="mobile-nav">
        <button className={active === 'dashboard' ? 'selected' : ''} type="button" onClick={() => go('/')}><span className="sym">⌂</span>Start</button>
        <button className={active === 'calendar' ? 'selected' : ''} type="button" onClick={() => go('/kalender')}><span className="sym">▦</span>Kalender</button>
        <button className={active === 'surveys' ? 'selected' : ''} type="button" onClick={() => go('/umfragen')}><span className="sym">☷</span>Umfragen</button>
        <button className={more || ['events', 'vehicles', 'gastronomy'].includes(active) ? 'selected' : ''} type="button" onClick={() => setMore((open) => !open)}><span className="sym">☰</span>Mehr</button>
      </nav>
      {more ? (
        <div className="more-sheet">
          <button className={active === 'events' ? 'selected' : ''} type="button" onClick={() => go('/veranstaltungen')}>Veranstaltungen</button>
          <button className={active === 'vehicles' ? 'selected' : ''} type="button" onClick={() => go('/fahrzeuge')}>Fahrzeuge</button>
          <button className={active === 'gastronomy' ? 'selected' : ''} type="button" onClick={() => go('/gastronomie')}>Gastronomie</button>
        </div>
      ) : null}
    </div>
  )
}
