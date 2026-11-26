import { useEffect, useRef } from 'react'
import { BtnLabel } from '../design/BtnLabel'

type Props = {
  dirty: boolean
  busy?: boolean
  incomplete?: string | null
  onCancel: () => void
}

export function MobileSaveBar({ dirty, busy = false, incomplete, onCancel }: Props) {
  const barRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    const root = document.documentElement
    const bar = barRef.current

    const place = () => {
      const nav = document.querySelector('.mobile-nav')
      const navVisible = nav instanceof HTMLElement && getComputedStyle(nav).display !== 'none'
      const navHeight = navVisible ? nav.offsetHeight : 0
      const viewport = window.visualViewport
      const keyboard = viewport ? Math.max(0, window.innerHeight - viewport.height - viewport.offsetTop) : 0
      if (keyboard > 80) root.style.setProperty('--editor-dock-bottom', `${keyboard}px`)
      else if (navHeight > 0) root.style.setProperty('--editor-dock-bottom', `${navHeight}px`)
      else root.style.removeProperty('--editor-dock-bottom')
      if (bar && getComputedStyle(bar).display !== 'none') root.style.setProperty('--save-bar-height', `${bar.offsetHeight}px`)
    }

    const keepFocusedVisible = () => {
      const active = document.activeElement
      if (!(active instanceof HTMLElement)) return
      if (!active.closest('.editor-form')) return
      if (!['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName)) return
      const current = barRef.current
      const rect = active.getBoundingClientRect()
      const margin = 16
      const headerHeight = window.matchMedia('(max-width: 700px)').matches ? 67 : 131
      const topLimit = headerHeight + margin
      if (current && getComputedStyle(current).display !== 'none') {
        const barTop = current.getBoundingClientRect().top
        if (rect.bottom > barTop - margin) {
          window.scrollBy({ top: rect.bottom - (barTop - margin) })
          return
        }
      }
      if (rect.top < topLimit) {
        window.scrollBy({ top: rect.top - topLimit })
      }
    }

    const onFocus = (event: FocusEvent) => {
      const target = event.target
      if (!(target instanceof HTMLElement)) return
      if (!target.closest('.editor-form')) return
      window.setTimeout(() => {
        place()
        keepFocusedVisible()
      }, 60)
    }

    place()
    const viewport = window.visualViewport
    const nav = document.querySelector('.mobile-nav')
    const media = window.matchMedia('(max-width: 700px)')
    viewport?.addEventListener('resize', place)
    viewport?.addEventListener('scroll', place)
    window.addEventListener('resize', place)
    media.addEventListener('change', place)
    document.addEventListener('focusin', onFocus)
    const observer = new ResizeObserver(() => {
      place()
      keepFocusedVisible()
    })
    if (bar) observer.observe(bar)
    if (nav) observer.observe(nav)

    return () => {
      viewport?.removeEventListener('resize', place)
      viewport?.removeEventListener('scroll', place)
      window.removeEventListener('resize', place)
      media.removeEventListener('change', place)
      document.removeEventListener('focusin', onFocus)
      observer.disconnect()
      root.style.removeProperty('--editor-dock-bottom')
      root.style.removeProperty('--save-bar-height')
    }
  }, [])

  return (
    <div aria-label="Speichern" className="mobile-save-bar" ref={barRef} role="region">
      {dirty ? <p className="save-dirty">Ungespeicherte Änderungen</p> : null}
      {incomplete ? <p className="save-incomplete">{incomplete}</p> : null}
      <div className="mobile-save-bar-actions">
        <button className="btn primary" disabled={busy} type="submit"><BtnLabel icon="save">Speichern</BtnLabel></button>
        <button className="btn light" type="button" onClick={onCancel}><BtnLabel icon="close" iconOnly={false}>Abbrechen</BtnLabel></button>
      </div>
    </div>
  )
}
