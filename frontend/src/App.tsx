import { useEffect, useState } from 'react'
import { BrowserRouter, Route, Routes } from 'react-router'
import { api } from './api/client'
import type { Member } from './api/types'
import { sessionToken } from './auth/session'
import { CalendarPage } from './pages/CalendarPage'
import { DashboardPage } from './pages/DashboardPage'
import { EventDetailPage } from './pages/EventDetailPage'
import { EventEditorPage } from './pages/EventEditorPage'
import { EventsPage } from './pages/EventsPage'
import { LaterPage } from './pages/LaterPage'
import { LoginPage } from './pages/LoginPage'
import { Shell } from './shell/Shell'
import { MemberContext, ToastContext } from './shell/context'

export default function App() {
  const [authed, setAuthed] = useState(() => sessionToken() !== null)
  const [member, setMember] = useState<Member | null>(null)
  const [toast, setToast] = useState<string | null>(null)

  useEffect(() => {
    const onLogout = () => {
      setAuthed(false)
      setMember(null)
    }
    window.addEventListener('historic-logout', onLogout)
    return () => window.removeEventListener('historic-logout', onLogout)
  }, [])

  useEffect(() => {
    if (!authed) return
    api<Member>('/api/v1/me').then(setMember).catch(() => setMember(null))
  }, [authed])

  useEffect(() => {
    if (!toast) return
    const timer = window.setTimeout(() => setToast(null), 3000)
    return () => window.clearTimeout(timer)
  }, [toast])

  if (!authed) {
    return <LoginPage onLogin={() => setAuthed(true)} />
  }

  return (
    <BrowserRouter>
      <MemberContext value={member}>
        <ToastContext value={setToast}>
          <Shell>
            <Routes>
              <Route element={<DashboardPage />} path="/" />
              <Route element={<CalendarPage />} path="/kalender" />
              <Route element={<EventsPage />} path="/veranstaltungen" />
              <Route element={<EventEditorPage />} path="/veranstaltungen/neu" />
              <Route element={<EventDetailPage />} path="/veranstaltungen/:id" />
              <Route element={<EventEditorPage />} path="/veranstaltungen/:id/bearbeiten" />
              <Route element={<LaterPage eyebrow="Terminumfragen" text="Normale Terminsuche und monatliche Depottage-Umfragen mit Ja/Nein, Speichern und Ergänzung erforderlich sind der nächste fachliche Schritt." title="Terminumfragen" />} path="/umfragen" />
              <Route element={<LaterPage eyebrow="Fahrzeugstamm" text="Der interne Fahrzeugstamm mit Status, Batterieladung und Zählerständen folgt nach den Veranstaltungen. ZEDAS bleibt das Wartungssystem." title="Historische Fahrzeuge" />} path="/fahrzeuge" />
              <Route element={<LaterPage eyebrow="Supporterclub" text="Pro Veranstaltung gibt es später eine Gastronomieplanung. Getränke kommen aus dem CashCtrl-Mandanten des Supporterclubs." title="Gastronomieplanung" />} path="/gastronomie" />
            </Routes>
          </Shell>
          <div aria-live="polite" className={toast ? 'toast show' : 'toast'} role="status">{toast}</div>
        </ToastContext>
      </MemberContext>
    </BrowserRouter>
  )
}
