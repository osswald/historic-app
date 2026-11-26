import { useEffect, useRef, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { EventNextcloud, NextcloudArea, NextcloudDocument, NextcloudFolderChoice } from '../api/types'
import { useToast } from '../shell/context'

export function EventNextcloudPanel({ eventId }: { eventId: string }) {
  const toast = useToast()
  const [data, setData] = useState<EventNextcloud | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [busy, setBusy] = useState(false)
  const [picking, setPicking] = useState(false)
  const [folders, setFolders] = useState<NextcloudFolderChoice[]>([])
  const [chosen, setChosen] = useState('')

  useEffect(() => {
    let cancelled = false
    api<EventNextcloud>(`/api/v1/events/${eventId}/nextcloud`)
      .then((body) => {
        if (!cancelled) setData(body)
      })
      .catch((reason: unknown) => {
        if (!cancelled) setError(reason instanceof ApiError ? reason.message : 'Der Nextcloud-Ordner konnte nicht geladen werden.')
      })
    return () => {
      cancelled = true
    }
  }, [eventId])

  async function openPicker() {
    setBusy(true)
    setError(null)
    try {
      const body = await api<{ folders: NextcloudFolderChoice[] }>(`/api/v1/events/${eventId}/nextcloud/folders`)
      setFolders(body.folders)
      setChosen('')
      setPicking(true)
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Die Ordner konnten nicht geladen werden.')
    } finally {
      setBusy(false)
    }
  }

  async function linkExisting() {
    if (!chosen) return
    setBusy(true)
    setError(null)
    try {
      setData(await api<EventNextcloud>(`/api/v1/events/${eventId}/nextcloud`, {
        method: 'POST',
        body: JSON.stringify({ fileId: chosen }),
      }))
      setPicking(false)
      toast('Der Nextcloud-Ordner ist verknüpft.')
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Der Ordner konnte nicht verknüpft werden.')
    } finally {
      setBusy(false)
    }
  }

  async function createFolder() {
    setBusy(true)
    setError(null)
    try {
      setData(await api<EventNextcloud>(`/api/v1/events/${eventId}/nextcloud`, {
        method: 'POST',
        body: JSON.stringify({ create: true }),
      }))
      toast('Der Nextcloud-Ordner ist angelegt.')
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Der Ordner konnte nicht angelegt werden.')
    } finally {
      setBusy(false)
    }
  }

  async function addFile(file: File | undefined) {
    if (!file) {
      setError('Bitte eine Datei wählen.')
      return
    }
    setBusy(true)
    setError(null)
    try {
      const body = new FormData()
      body.append('file', file)
      setData(await api<EventNextcloud>(`/api/v1/events/${eventId}/nextcloud/files`, {
        method: 'POST',
        body,
      }))
      toast('Die Datei ist hinzugefügt.')
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Die Datei konnte nicht hinzugefügt werden.')
    } finally {
      setBusy(false)
    }
  }

  const canChoose = data?.canEdit === true && data.configured && !data.linked

  return (
    <div className="panel nextcloud-panel">
      <div className="panel-head">
        <h2>Nextcloud</h2>
        {data?.reachable && data.url ? (
          <div className="panel-head-actions">
            <FileUpload busy={busy} onFile={(file) => { void addFile(file) }} />
            <a className="btn small" href={data.url} rel="noreferrer" target="_blank">Ordner öffnen</a>
          </div>
        ) : null}
      </div>
      <div className="panel-inner" style={{ paddingTop: 0 }}>
        {error ? <div className="banner-error">{error}</div> : null}
        {data?.notice ? <p className="empty">{data.notice}</p> : null}
        {!data && !error ? <p className="empty">Wird geladen …</p> : null}
        {data?.linked && data.name ? <p className="folder-name"><b>{data.name}</b></p> : null}
        {data && !data.linked && !data.notice ? <p className="muted">Noch kein Nextcloud-Ordner.</p> : null}
        {canChoose && !picking && data.proposedName ? <p className="muted">Neuer Ordner: {data.proposedName}</p> : null}
        {canChoose && !picking && !data.proposedName ? <p className="muted">Der Anlass hat noch kein Datum.</p> : null}
        {canChoose && !picking ? (
          <div className="nextcloud-actions">
            <button className="btn" disabled={busy} type="button" onClick={() => { void openPicker() }}>Bestehenden Ordner wählen</button>
            <button className="btn primary" disabled={busy || !data.proposedName} type="button" onClick={() => { void createFolder() }}>Ordner anlegen</button>
          </div>
        ) : null}
        {canChoose && picking ? (
          <div className="stack" style={{ marginBottom: 12 }}>
            <label className="nextcloud-pick">
              <span>Ordner unter Fahrten_Anlässe</span>
              <select className="input" value={chosen} onChange={(event) => setChosen(event.target.value)}>
                <option value="">Auswählen</option>
                {folders.map((folder) => <option key={folder.fileId} value={folder.fileId}>{folder.name}</option>)}
              </select>
            </label>
            {folders.length === 0 ? <p className="muted">Unter Fahrten_Anlässe liegt kein wählbarer Ordner.</p> : null}
            <div className="nextcloud-actions">
              <button className="btn primary" disabled={busy || chosen === ''} type="button" onClick={() => { void linkExisting() }}>Verknüpfen</button>
              <button className="btn" disabled={busy} type="button" onClick={() => setPicking(false)}>Abbrechen</button>
            </div>
          </div>
        ) : null}
        {data?.reachable && data.documents.length === 0 && data.areas.length === 0 ? <p className="muted">Keine Dokumente in diesem Ordner.</p> : null}
        <div className="simplelist">
          {(data?.areas ?? []).map((area) => <DocumentRow item={area} key={area.key} />)}
          {(data?.documents ?? []).map((document) => <DocumentRow file item={document} key={document.fileId} />)}
        </div>
      </div>
    </div>
  )
}

function FileUpload({ busy, onFile }: { busy: boolean; onFile: (file: File | undefined) => void }) {
  const inputRef = useRef<HTMLInputElement>(null)

  return (
    <>
      <button className="btn small" disabled={busy} type="button" onClick={() => inputRef.current?.click()}>Datei hinzufügen</button>
      <input
        ref={inputRef}
        aria-hidden="true"
        className="nextcloud-file-input"
        type="file"
        tabIndex={-1}
        onChange={(event) => {
          const file = event.currentTarget.files?.[0]
          event.currentTarget.value = ''
          onFile(file)
        }}
      />
    </>
  )
}

function DocumentRow({ item, file = false }: { item: NextcloudDocument | NextcloudArea; file?: boolean }) {
  return (
    <div className="item nextcloud-doc">
      <div className="nextcloud-doc-name">
        {file ? <FileMark name={item.name} /> : null}
        <b>{item.name}</b>
      </div>
      <a className="btn small" href={item.url} rel="noreferrer" target="_blank">Öffnen</a>
    </div>
  )
}

function FileMark({ name }: { name: string }) {
  const kind = fileKind(name)

  return (
    <svg className="nextcloud-file-icon" viewBox="0 0 16 16" aria-hidden="true">
      <path d="M4 1.6h5.1L12.4 5v8.4a.6.6 0 0 1-.6.6H4.6a.6.6 0 0 1-.6-.6V2.2a.6.6 0 0 1 .6-.6Z" fill="none" stroke="currentColor" strokeWidth="1.2" />
      <path d="M9 1.8V5.1h3.2" fill="none" stroke="currentColor" strokeWidth="1.2" />
      {kind === 'pdf' ? <path d="M5.2 8.2h5.2M5.2 10.2h5.2M5.2 12.2h3.2" stroke="currentColor" strokeLinecap="round" strokeWidth="1.1" /> : null}
      {kind === 'image' ? <path d="M5 12.2 7 9.8l1.2 1.3 1.4-1.8 1.6 2.9H5Z" fill="currentColor" /> : null}
      {kind === 'file' ? <path d="M5.2 8.4h5.2M5.2 10.4h5.2M5.2 12.2h3.4" stroke="currentColor" strokeLinecap="round" strokeWidth="1.1" /> : null}
    </svg>
  )
}

function fileKind(name: string): 'pdf' | 'image' | 'file' {
  const extension = name.split('.').pop()?.toLowerCase() ?? ''
  if (extension === 'pdf') return 'pdf'
  if (['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'heic', 'avif', 'tif', 'tiff'].includes(extension)) return 'image'
  return 'file'
}
