import { useEffect, useState } from 'react'
import { createBrowserRouter, Outlet, RouterProvider } from 'react-router'
import { NavigationGuard } from './forms/unsaved'
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

const router = createBrowserRouter([
  {
    element: <ShellLayout />,
    children: [
      { path: '/', element: <DashboardPage /> },
      { path: '/kalender', element: <CalendarPage /> },
      { path: '/veranstaltungen', element: <EventsPage /> },
      { path: '/veranstaltungen/neu', element: <EventEditorPage /> },
      { path: '/veranstaltungen/:id', element: <EventDetailPage /> },
      { path: '/veranstaltungen/:id/bearbeiten', element: <EventEditorPage /> },
      { path: '/umfragen', element: <PollsPage /> },
      { path: '/umfragen/neu', element: <PollEditorPage /> },
      { path: '/umfragen/:id', element: <PollDetailPage /> },
      { path: '/umfragen/:id/bearbeiten', element: <PollEditorPage /> },
      { path: '/fahrzeuge', element: <VehiclesPage /> },
      { path: '/gastronomie', element: <GastroPage /> },
    ],
  },
])

function ShellLayout() {
  return (
    <Shell>
      <NavigationGuard />
      <Outlet />
    </Shell>
  )
}

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
    <MemberContext value={member}>
      <ToastContext value={setToast}>
        <RouterProvider router={router} />
        <div aria-live="polite" className={toast ? 'toast show' : 'toast'} role="status">{toast}</div>
      </ToastContext>
    </MemberContext>
  )
}
