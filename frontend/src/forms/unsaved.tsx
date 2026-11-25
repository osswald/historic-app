import { useEffect, useRef, useSyncExternalStore } from 'react'
import { useBlocker } from 'react-router'

export const LEAVE_TEXT = 'Es gibt ungespeicherte Änderungen. Wirklich verlassen, ohne zu speichern?'

const dirtyIds = new Set<string>()
const listeners = new Set<() => void>()

function emit() {
  listeners.forEach((listener) => listener())
}

function subscribe(listener: () => void) {
  listeners.add(listener)
  return () => listeners.delete(listener)
}

function dirtyNow() {
  return dirtyIds.size > 0
}

export function setUnsaved(id: string, dirty: boolean) {
  const had = dirtyIds.has(id)
  if (dirty) dirtyIds.add(id)
  else dirtyIds.delete(id)
  if (had !== dirtyIds.has(id)) emit()
}

export function useRegisterUnsaved(id: string, dirty: boolean) {
  useEffect(() => {
    setUnsaved(id, dirty)
    return () => setUnsaved(id, false)
  }, [id, dirty])
}

export function confirmLeave(): boolean {
  if (!dirtyNow()) return true
  return window.confirm(LEAVE_TEXT)
}

export function NavigationGuard() {
  const dirty = useSyncExternalStore(subscribe, dirtyNow, () => false)
  const blocker = useBlocker(dirty)
  const blockerRef = useRef(blocker)
  blockerRef.current = blocker

  useEffect(() => {
    const current = blockerRef.current
    if (current.state !== 'blocked') return
    if (window.confirm(LEAVE_TEXT)) current.proceed()
    else current.reset()
  }, [blocker.state])

  useEffect(() => {
    if (!dirty) return
    const onBeforeUnload = (event: BeforeUnloadEvent) => {
      event.preventDefault()
      event.returnValue = ''
    }
    window.addEventListener('beforeunload', onBeforeUnload)
    return () => window.removeEventListener('beforeunload', onBeforeUnload)
  }, [dirty])

  return null
}
