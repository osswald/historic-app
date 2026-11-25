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
import { GastroPage } from './pages/GastroPage'
import { VehiclesPage } from './pages/VehiclesPage'
import { LoginPage } from './pages/LoginPage'
import { PollDetailPage } from './pages/PollDetailPage'
import { PollEditorPage } from './pages/PollEditorPage'
import { PollsPage } from './pages/PollsPage'
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
              <Route element={<PollsPage />} path="/umfragen" />
              <Route element={<PollEditorPage />} path="/umfragen/neu" />
              <Route element={<PollDetailPage />} path="/umfragen/:id" />
              <Route element={<PollEditorPage />} path="/umfragen/:id/bearbeiten" />
              <Route element={<VehiclesPage />} path="/fahrzeuge" />
              <Route element={<GastroPage />} path="/gastronomie" />
            </Routes>
          </Shell>
          <div aria-live="polite" className={toast ? 'toast show' : 'toast'} role="status">{toast}</div>
        </ToastContext>
      </MemberContext>
    </BrowserRouter>
  )
}
