import { useEffect, useId, useRef, useState, type ReactNode } from 'react'
import { useLocation, useNavigate } from 'react-router'
import { endSession, sessionToken } from '../auth/session'
import { ActionIcon } from '../design/BtnLabel'
import { initials } from '../design/initials'
import { useMember, useToast } from './context'

const ITEMS = [
  { id: 'dashboard', to: '/', label: 'Start', icon: '⌂' },
  { id: 'calendar', to: '/kalender', label: 'Kalender', icon: '▦' },
  { id: 'events', to: '/veranstaltungen', label: 'Veranstaltungen', icon: '▤' },
  { id: 'surveys', to: '/umfragen', label: 'Terminumfragen', icon: '☷' },
  { id: 'vehicles', to: '/fahrzeuge', label: 'Fahrzeuge', icon: '▰' },
  { id: 'gastronomy', to: '/gastronomie', label: 'Gastronomie', icon: '♧' },
  { id: 'tasks', to: '/aufgaben', label: 'Aufgaben', icon: '☑' },
]

function current(path: string): string {
  if (path.startsWith('/kalender')) return 'calendar'
  if (path.startsWith('/veranstaltungen')) return 'events'
  if (path.startsWith('/umfragen')) return 'surveys'
  if (path.startsWith('/fahrzeuge')) return 'vehicles'
  if (path.startsWith('/gastronomie')) return 'gastronomy'
  if (path.startsWith('/aufgaben')) return 'tasks'
  if (path.startsWith('/spond')) return 'spond'
  if (path.startsWith('/einstellungen')) return 'settings'
  return 'dashboard'
}

export function Shell({ children }: { children: ReactNode }) {
  const location = useLocation()
  const navigate = useNavigate()
  const member = useMember()
  const toast = useToast()
  const [more, setMore] = useState(false)
  const [accountOpen, setAccountOpen] = useState(false)
  const [photoFailed, setPhotoFailed] = useState(false)
  const accountRef = useRef<HTMLDivElement>(null)
  const accountButtonRef = useRef<HTMLButtonElement>(null)
  const logoutRef = useRef<HTMLButtonElement>(null)
  const accountMenuId = useId()
  const active = current(location.pathname)
  const isAdmin = member?.groups?.includes('admin') === true
  const items = [
    ...ITEMS,
    ...(member?.canOrganize ? [{ id: 'spond', to: '/spond', label: 'Spond', icon: '↺' }] : []),
    ...(isAdmin ? [{ id: 'settings', to: '/einstellungen', label: 'Einstellungen', icon: '⚙' }] : []),
  ]
  const name = member?.displayName ?? 'Mitglied'
  const picture = member?.picture
  const photoUrl = typeof picture === 'string' && picture.startsWith('https://') && !photoFailed ? picture : null

  useEffect(() => {
    setPhotoFailed(false)
  }, [picture])

  useEffect(() => {
    if (!accountOpen) return
    logoutRef.current?.focus()
    function onPointer(event: PointerEvent) {
      if (!accountRef.current?.contains(event.target as Node)) setAccountOpen(false)
    }
    function onKey(event: KeyboardEvent) {
      if (event.key !== 'Escape') return
      setAccountOpen(false)
      accountButtonRef.current?.focus()
    }
    document.addEventListener('pointerdown', onPointer)
    document.addEventListener('keydown', onKey)
    return () => {
      document.removeEventListener('pointerdown', onPointer)
      document.removeEventListener('keydown', onKey)
    }
  }, [accountOpen])

  function go(to: string) {
    setMore(false)
    void navigate(to)
  }

  function logout() {
    if (sessionToken()) {
      endSession()
      return
    }
    const form = document.createElement('form')
    form.method = 'POST'
    form.action = '/api/v1/auth/logout'
    document.body.appendChild(form)
    form.submit()
  }

  return (
    <div className="app">
      <div className="app-chrome">
        <header className="top">
          <div className="brand">
            <div className="brand-logo">
              <img src="/club-logo.png" alt="Historic Team Winterthur" />
            </div>
          </div>
          <div className="top-actions">
            <a className="public-link" href="https://new.historic-winterthur.ch/" rel="noopener noreferrer" target="_blank">Zur öffentlichen Website ↗</a>
            <button className="ghost desktop-only" type="button" onClick={() => toast('Benachrichtigungen folgen mit der E-Mail- und Push-Anbindung.')}>♧ Benachrichtigungen</button>
            <div
              className="account"
              ref={accountRef}
              onBlur={(event) => {
                if (!accountRef.current?.contains(event.relatedTarget as Node)) setAccountOpen(false)
              }}
            >
              <button
                ref={accountButtonRef}
                aria-controls={accountOpen ? accountMenuId : undefined}
                aria-expanded={accountOpen}
                aria-haspopup="menu"
                aria-label={name}
                className={photoUrl ? 'avatar account-button has-photo' : 'avatar account-button'}
                title={name}
                type="button"
                onClick={() => setAccountOpen((open) => !open)}
                onKeyDown={(event) => {
                  if (event.key === 'ArrowDown') {
                    event.preventDefault()
                    setAccountOpen(true)
                  }
                }}
              >
                {photoUrl ? <img alt="" src={photoUrl} onError={() => setPhotoFailed(true)} /> : initials(name)}
              </button>
              {accountOpen ? (
                <div
                  aria-label="Konto"
                  className="account-menu"
                  id={accountMenuId}
                  role="menu"
                  onKeyDown={(event) => {
                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp' || event.key === 'Home' || event.key === 'End') {
                      event.preventDefault()
                      logoutRef.current?.focus()
                    }
                  }}
                >
                  <button ref={logoutRef} className="account-logout" role="menuitem" type="button" onClick={logout}>
                    <ActionIcon name="logout" />
                    Abmelden
                  </button>
                </div>
              ) : null}
            </div>
          </div>
        </header>
        <div className="primary-nav">
          <nav className="nav" aria-label="Hauptnavigation">
            {items.map((item) => (
              <button className={active === item.id ? 'selected' : ''} key={item.id} type="button" onClick={() => go(item.to)}>
                <span className="sym">{item.icon}</span>
                {item.label}
              </button>
            ))}
          </nav>
        </div>
      </div>
      <div className="wrap">
        <main>{children}</main>
      </div>
      <nav aria-label="Mobile Hauptnavigation" className="mobile-nav">
        <button className={active === 'dashboard' ? 'selected' : ''} type="button" onClick={() => go('/')}><span className="sym">⌂</span>Start</button>
        <button className={active === 'calendar' ? 'selected' : ''} type="button" onClick={() => go('/kalender')}><span className="sym">▦</span>Kalender</button>
        <button className={active === 'surveys' ? 'selected' : ''} type="button" onClick={() => go('/umfragen')}><span className="sym">☷</span>Umfragen</button>
        <button className={more || ['events', 'vehicles', 'gastronomy', 'tasks', 'spond', 'settings'].includes(active) ? 'selected' : ''} type="button" onClick={() => setMore((open) => !open)}><span className="sym">☰</span>Mehr</button>
      </nav>
      {more ? (
        <div className="more-sheet">
          <button className={active === 'events' ? 'selected' : ''} type="button" onClick={() => go('/veranstaltungen')}>Veranstaltungen</button>
          <button className={active === 'vehicles' ? 'selected' : ''} type="button" onClick={() => go('/fahrzeuge')}>Fahrzeuge</button>
          <button className={active === 'gastronomy' ? 'selected' : ''} type="button" onClick={() => go('/gastronomie')}>Gastronomie</button>
          <button className={active === 'tasks' ? 'selected' : ''} type="button" onClick={() => go('/aufgaben')}>Aufgaben</button>
          {member?.canOrganize ? <button className={active === 'spond' ? 'selected' : ''} type="button" onClick={() => go('/spond')}>Spond</button> : null}
          {member?.groups?.includes('admin') ? <button className={active === 'settings' ? 'selected' : ''} type="button" onClick={() => go('/einstellungen')}>Einstellungen</button> : null}
        </div>
      ) : null}
    </div>
  )
}
