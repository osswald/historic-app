import { useEffect, useState, type FormEvent } from 'react'
import { api, ApiError } from '../api/client'
import type { TaskItem, TaskListDetail, TaskListSummary, TasksRead, TaskTemplate } from '../api/types'
import { ShowDoneTasksToggle } from '../tasks/ShowDoneTasksToggle'
import { progressText, readShowDoneTasks, TASK_STATUSES, tasksEmptyCopy, visibleTasks } from '../tasks/labels'
import { useToast } from '../shell/context'

type ListsPayload = TasksRead<{ lists: TaskListSummary[] }>
type TemplatesPayload = TasksRead<{ templates: TaskTemplate[] }>
type TasksPayload = TasksRead<{ tasks: TaskItem[] }>
type DetailPayload = TasksRead<{ list: TaskListDetail | null }>

export function AufgabenPage() {
  const toast = useToast()
  const [lists, setLists] = useState<ListsPayload | null>(null)
  const [templates, setTemplates] = useState<TemplatesPayload | null>(null)
  const [tasks, setTasks] = useState<TasksPayload | null>(null)
  const [status, setStatus] = useState('')
  const [detail, setDetail] = useState<DetailPayload | null>(null)
  const [selected, setSelected] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [name, setName] = useState('')
  const [description, setDescription] = useState('')
  const [templateId, setTemplateId] = useState('')
  const [categoryId, setCategoryId] = useState('')
  const [orgGroupId, setOrgGroupId] = useState('')
  const [personal, setPersonal] = useState(false)
  const [busy, setBusy] = useState(false)
  const [showDone, setShowDone] = useState(readShowDoneTasks)

  function loadTasks(nextStatus = status) {
    const query = nextStatus ? `?status=${encodeURIComponent(nextStatus)}` : ''
    return api<TasksPayload>(`/api/v1/aufgaben/tasks${query}`).then(setTasks)
  }

  function loadLists() {
    return api<ListsPayload>('/api/v1/aufgaben/task-lists').then(setLists)
  }

  function load() {
    setError(null)
    Promise.all([
      loadLists(),
      api<TemplatesPayload>('/api/v1/aufgaben/templates').then(setTemplates),
      loadTasks(),
    ]).catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Aufgaben konnten nicht geladen werden.'))
  }

  useEffect(() => { load() }, [])

  function openList(id: string) {
    setSelected(id)
    setDetail(null)
    api<DetailPayload>(`/api/v1/aufgaben/task-lists/${encodeURIComponent(id)}`)
      .then(setDetail)
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Liste konnte nicht geladen werden.'))
  }

  async function changeStatus(id: string, next: string) {
    setBusy(true)
    setError(null)
    try {
      await api(`/api/v1/aufgaben/tasks/${encodeURIComponent(id)}/status`, { method: 'PATCH', body: JSON.stringify({ status: next }) })
      toast('Status gespeichert. Führend bleibt die Aufgaben-App.')
      await loadTasks()
      if (selected) openList(selected)
      await loadLists()
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Der Status konnte nicht gespeichert werden.')
    } finally {
      setBusy(false)
    }
  }

  async function createList(event: FormEvent) {
    event.preventDefault()
    setBusy(true)
    setError(null)
    const body: Record<string, string | boolean> = { name }
    if (description.trim()) body.description = description.trim()
    if (templateId) body.template_id = templateId
    if (categoryId.trim()) body.category_id = categoryId.trim()
    if (orgGroupId.trim()) body.org_group_id = orgGroupId.trim()
    if (personal) body.is_personal = true
    try {
      await api('/api/v1/aufgaben/task-lists', { method: 'POST', body: JSON.stringify(body) })
      toast('Liste erstellt.')
      setName('')
      setDescription('')
      setTemplateId('')
      setCategoryId('')
      setOrgGroupId('')
      setPersonal(false)
      await loadLists()
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Die Liste konnte nicht erstellt werden.')
    } finally {
      setBusy(false)
    }
  }

  const showCompleted = showDone || status === 'done'
  const mine = visibleTasks(tasks?.tasks ?? [], showCompleted)
  const opened = detail?.list ? visibleTasks(detail.list.tasks, showDone) : []

  return (
    <section>
      <div className="heading">
        <div>
          <div className="eyebrow">Aufgaben</div>
          <h1>Aufgaben</h1>
          <div className="hello">Listen, Vorlagen und eigene Aufgaben bleiben in der Aufgaben-App führend.</div>
        </div>
        <a className="btn light" href="https://tasks.historic-winterthur.ch/" rel="noreferrer" target="_blank">Zur Aufgaben-App</a>
      </div>
      {error ? <div className="banner-error">{error}</div> : null}
      <div className="content-grid">
        <div className="stack">
          <div className="panel">
            <div className="panel-head tasks-head">
              <h2>Meine Aufgaben</h2>
              <div className="tasks-head-actions">
                <ShowDoneTasksToggle checked={showDone} onChange={setShowDone} />
                <select className="input" value={status} onChange={(event) => { setStatus(event.target.value); void loadTasks(event.target.value) }}>
                  <option value="">Alle Status</option>
                  {TASK_STATUSES.map((item) => <option key={item.value} value={item.value}>{item.label}</option>)}
                </select>
              </div>
            </div>
            <Notice text={sectionText(tasks, mine.length, tasksEmptyCopy(tasks?.tasks.length ?? 0, showCompleted, 'Keine eigenen Aufgaben.'))} />
            <div className="simplelist">
              {mine.map((task) => <TaskRow busy={busy} key={task.id} task={task} onStatus={(next) => { void changeStatus(task.id, next) }} />)}
            </div>
          </div>
          <div className="panel">
            <div className="panel-head"><h2>Aufgabenlisten</h2></div>
            <Notice text={sectionText(lists, lists?.lists.length ?? 0, 'Keine Aufgabenlisten.')} />
            <div className="simplelist">
              {(lists?.lists ?? []).map((list) => (
                <button className="item" key={list.id} type="button" disabled={!list.addressable} onClick={() => openList(list.id)}>
                  <div>
                    <b>{list.name ?? list.id}</b>
                    <small>{progressText(list.progress) ?? 'Kein Fortschritt'}</small>
                  </div>
                </button>
              ))}
            </div>
            {detail?.notice ? <p className="empty">{detail.notice}</p> : null}
            {detail?.list ? (
              <div className="panel-inner">
                <div className="tasks-list-head">
                  <b>{detail.list.name ?? detail.list.id}</b>
                  {detail.list.tasks.length > 0 ? <ShowDoneTasksToggle checked={showDone} onChange={setShowDone} /> : null}
                </div>
                {detail.list.description ? <p className="muted">{detail.list.description}</p> : null}
                <p className="muted">{progressText(detail.list.progress) ?? 'Kein Fortschritt'}</p>
                {detail.list.isPersonal ? <p className="muted">Persönliche Liste</p> : null}
                {detail.list.tasks.length > 0 && opened.length === 0 ? <p className="empty">Keine offenen Aufgaben.</p> : null}
                <div className="simplelist">
                  {opened.map((task) => <TaskRow busy={busy} key={task.id} task={task} onStatus={(next) => { void changeStatus(task.id, next) }} />)}
                </div>
              </div>
            ) : null}
          </div>
        </div>
        <div className="stack">
          <div className="panel">
            <div className="panel-head"><h2>Vorlagen</h2></div>
            <Notice text={sectionText(templates, templates?.templates.length ?? 0, 'Keine Vorlagen.')} />
            <div className="simplelist">
              {(templates?.templates ?? []).map((template) => (
                <div className="item" key={template.id}>
                  <div>
                    <b>{template.name ?? template.id}</b>
                    <small>{template.taskCount === null ? 'Aufgabenanzahl offen' : `${template.taskCount} Aufgaben`}</small>
                  </div>
                </div>
              ))}
            </div>
          </div>
          <form className="panel" onSubmit={(event) => { void createList(event) }}>
            <div className="panel-head"><h2>Liste erstellen</h2></div>
            <div className="panel-inner">
              <div className="formfield">
                <label htmlFor="task-list-name">Name</label>
                <input className="input" id="task-list-name" required value={name} onChange={(event) => setName(event.target.value)} />
              </div>
              <div className="formfield">
                <label htmlFor="task-list-description">Beschreibung</label>
                <input className="input" id="task-list-description" value={description} onChange={(event) => setDescription(event.target.value)} />
              </div>
              <div className="formfield">
                <label htmlFor="task-list-template">Vorlage</label>
                <select className="input" id="task-list-template" value={templateId} onChange={(event) => setTemplateId(event.target.value)}>
                  <option value="">Ohne Vorlage</option>
                  {(templates?.templates ?? []).filter((template) => template.addressable).map((template) => (
                    <option key={template.id} value={template.id}>{template.name ?? template.id}{template.taskCount === null ? '' : ` · ${template.taskCount}`}</option>
                  ))}
                </select>
              </div>
              <div className="formfield">
                <label htmlFor="task-list-category">Kategorie-ID</label>
                <input className="input" id="task-list-category" value={categoryId} onChange={(event) => setCategoryId(event.target.value)} />
              </div>
              <div className="formfield">
                <label htmlFor="task-list-group">Gruppen-ID</label>
                <input className="input" id="task-list-group" value={orgGroupId} onChange={(event) => setOrgGroupId(event.target.value)} />
              </div>
              <label className="check-line">
                <input checked={personal} type="checkbox" onChange={(event) => setPersonal(event.target.checked)} />
                Persönliche Liste
              </label>
              <button className="btn primary" disabled={busy} type="submit">Liste erstellen</button>
            </div>
          </form>
        </div>
      </div>
    </section>
  )
}

function sectionText(payload: { notice: string | null } | null, count: number, empty: string): string | null {
  if (!payload) return 'Wird geladen …'
  if (payload.notice) return payload.notice
  return count === 0 ? empty : null
}

function Notice({ text }: { text: string | null }) {
  if (!text) return null
  return <p className="empty">{text}</p>
}

function TaskRow({ task, busy, onStatus }: { task: TaskItem; busy: boolean; onStatus: (status: string) => void }) {
  return (
    <div className="item">
      <div className={task.status === 'done' ? 'check checked' : 'check'}>{task.status === 'done' ? '✓' : ''}</div>
      <div>
        <b>{task.title ?? task.id}</b>
        {task.details ? <small>{task.details}</small> : null}
        <small>{task.statusLabel ?? task.status ?? 'Ohne Status'}</small>
        {task.addressable ? (
          <span className="check-line">
            {TASK_STATUSES.map((item) => (
              <button className={task.status === item.value ? 'btn small' : 'btn small light'} disabled={busy || task.status === item.value} key={item.value} type="button" onClick={() => onStatus(item.value)}>
                {item.label}
              </button>
            ))}
          </span>
        ) : null}
      </div>
    </div>
  )
}
