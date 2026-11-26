import { useEffect, useState, type FormEvent } from 'react'
import { api, ApiError } from '../api/client'
import type { EventTaskLists, TaskListDetail, TaskListSummary, TasksRead, TaskTemplate } from '../api/types'
import { useRegisterUnsaved } from '../forms/unsaved'
import { ShowDoneTasksToggle } from '../tasks/ShowDoneTasksToggle'
import { progressText, readShowDoneTasks, visibleTasks } from '../tasks/labels'
import { CategoryGroupNames, namedChoices, visibleText } from '../tasks/names'
import { TaskRow } from '../tasks/TaskRow'
import { BtnLabel } from '../design/BtnLabel'
import { useToast } from '../shell/context'

type ListsPayload = TasksRead<{ lists: TaskListSummary[] }>
type TemplatesPayload = TasksRead<{ templates: TaskTemplate[] }>
type DetailPayload = TasksRead<{ list: TaskListDetail | null }>

export function EventTasksPanel({ eventId }: { eventId: string }) {
  const toast = useToast()
  const [view, setView] = useState<EventTaskLists | null>(null)
  const [catalogLists, setCatalogLists] = useState<TaskListSummary[]>([])
  const [templates, setTemplates] = useState<TaskTemplate[]>([])
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

  function load() {
    return api<EventTaskLists>(`/api/v1/events/${eventId}/task-lists`).then(setView)
  }

  useEffect(() => {
    setError(null)
    setSelected(null)
    setDetail(null)
    load().catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Aufgabenlisten konnten nicht geladen werden.'))
    api<ListsPayload>('/api/v1/aufgaben/task-lists')
      .then((body) => setCatalogLists(body.lists))
      .catch(() => setCatalogLists([]))
    api<TemplatesPayload>('/api/v1/aufgaben/templates')
      .then((body) => setTemplates(body.templates))
      .catch(() => setTemplates([]))
  }, [eventId])

  const categories = namedChoices([...catalogLists.map((list) => list.category), ...templates.map((template) => template.category)])
  const groups = namedChoices([...catalogLists.map((list) => list.orgGroup), ...templates.map((template) => template.orgGroup)])
  const dirty = name.trim() !== '' || description.trim() !== '' || templateId !== '' || categoryId !== '' || orgGroupId !== '' || personal
  useRegisterUnsaved(`event-tasks-${eventId}`, dirty)

  function loadDetail(id: string) {
    return api<DetailPayload>(`/api/v1/aufgaben/task-lists/${encodeURIComponent(id)}`)
      .then(setDetail)
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Liste konnte nicht geladen werden.'))
  }

  function openList(id: string) {
    setSelected(id)
    setDetail(null)
    void loadDetail(id)
  }

  async function changeStatus(id: string, next: string) {
    setBusy(true)
    setError(null)
    try {
      await api(`/api/v1/aufgaben/tasks/${encodeURIComponent(id)}/status`, { method: 'PATCH', body: JSON.stringify({ status: next }) })
      toast('Status gespeichert. Führend bleibt die Aufgaben-App.')
      await Promise.all([
        load(),
        selected ? loadDetail(selected) : Promise.resolve(),
      ])
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Der Status konnte nicht gespeichert werden.')
    } finally {
      setBusy(false)
    }
  }

  async function createList(submit: FormEvent) {
    submit.preventDefault()
    setBusy(true)
    setError(null)
    const body: Record<string, string | boolean> = { name }
    if (description.trim()) body.description = description.trim()
    if (templateId) body.template_id = templateId
    if (categoryId) body.category_id = categoryId
    if (!personal && orgGroupId) body.org_group_id = orgGroupId
    if (personal) body.is_personal = true
    try {
      const created = await api<{ id: string }>(`/api/v1/events/${eventId}/task-lists`, { method: 'POST', body: JSON.stringify(body) })
      toast('Liste erstellt.')
      setName('')
      setDescription('')
      setTemplateId('')
      setCategoryId('')
      setOrgGroupId('')
      setPersonal(false)
      await load()
      if (created.id) openList(created.id)
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Die Liste konnte nicht erstellt werden.')
    } finally {
      setBusy(false)
    }
  }

  const opened = detail?.list ? visibleTasks(detail.list.tasks, showDone) : []
  const lists = view?.lists ?? []

  return (
    <div className="content-grid">
      <div className="stack">
        <div className="panel">
          <div className="panel-head tasks-head">
            <h2>Aufgabenlisten</h2>
            {lists.length > 0 ? <ShowDoneTasksToggle checked={showDone} onChange={setShowDone} /> : null}
          </div>
          {error ? <div className="banner-error">{error}</div> : null}
          {!view ? <p className="empty">Wird geladen …</p> : null}
          {view && lists.length === 0 ? <p className="empty">{view.notice ?? 'Keine Aufgabenliste an dieser Veranstaltung.'}</p> : null}
          {view && lists.length > 0 && view.notice ? <p className="empty">{view.notice}</p> : null}
          <div className="simplelist">
            {lists.map((list) => list.unavailable || !list.addressable ? (
              <div className="item task-link-quiet" key={list.id}>
                <div>
                  <b>{visibleText(list.name)}</b>
                  <CategoryGroupNames category={list.category} orgGroup={list.orgGroup} />
                </div>
              </div>
            ) : (
              <button className={selected === list.id ? 'item selected' : 'item'} key={list.id} type="button" onClick={() => openList(list.id)}>
                <div>
                  <b>{visibleText(list.name)}</b>
                  <CategoryGroupNames category={list.category} orgGroup={list.orgGroup} />
                  <small>{progressText(list.progress) ?? 'Kein Fortschritt'}</small>
                </div>
              </button>
            ))}
          </div>
          {detail?.notice ? <p className="empty">{detail.notice}</p> : null}
          {detail?.list ? (
            <div className="panel-inner">
              <div className="tasks-list-head">
                <b>{visibleText(detail.list.name)}</b>
                {detail.list.tasks.length > 0 ? <ShowDoneTasksToggle checked={showDone} onChange={setShowDone} /> : null}
              </div>
              <CategoryGroupNames category={detail.list.category} orgGroup={detail.list.orgGroup} />
              {detail.list.description ? <p className="muted">{detail.list.description}</p> : null}
              <p className="muted">{progressText(detail.list.progress) ?? 'Kein Fortschritt'}</p>
              {detail.list.tasks.length > 0 && opened.length === 0 ? <p className="empty">Keine offenen Aufgaben.</p> : null}
              <div className="simplelist">
                {opened.map((task) => <TaskRow busy={busy} key={task.id} task={task} onStatus={(next) => { void changeStatus(task.id, next) }} />)}
              </div>
            </div>
          ) : null}
        </div>
      </div>
      {view?.canEdit ? (
        <form className="panel event-task-create" onSubmit={(event) => { void createList(event) }}>
          <div className="panel-head"><h2>Liste erstellen</h2></div>
          <div className="panel-inner">
            <div className="formfield">
              <label htmlFor="event-task-list-name">Name</label>
              <input className="input" id="event-task-list-name" required value={name} onChange={(event) => setName(event.target.value)} />
            </div>
            <div className="formfield">
              <label htmlFor="event-task-list-description">Beschreibung</label>
              <input className="input" id="event-task-list-description" value={description} onChange={(event) => setDescription(event.target.value)} />
            </div>
            <div className="formfield">
              <label htmlFor="event-task-list-template">Vorlage</label>
              <select className="input" id="event-task-list-template" value={templateId} onChange={(event) => setTemplateId(event.target.value)}>
                <option value="">Ohne Vorlage</option>
                {templates.filter((template) => template.addressable).map((template) => (
                  <option key={template.id} value={template.id}>{visibleText(template.name)}{template.taskCount === null ? '' : ` · ${template.taskCount}`}</option>
                ))}
              </select>
            </div>
            <div className="formfield">
              <label htmlFor="event-task-list-category">Kategorie</label>
              <select className="input" id="event-task-list-category" value={categoryId} onChange={(event) => setCategoryId(event.target.value)}>
                <option value="">Ohne Kategorie</option>
                {categories.map((category) => (
                  <option key={category.id} value={category.id}>{category.name}</option>
                ))}
              </select>
            </div>
            <div className="formfield">
              <label htmlFor="event-task-list-group">Gruppe</label>
              <select
                className="input"
                disabled={personal}
                id="event-task-list-group"
                value={personal ? '' : orgGroupId}
                onChange={(event) => setOrgGroupId(event.target.value)}
              >
                <option value="">Ohne Gruppe</option>
                {groups.map((group) => (
                  <option key={group.id} value={group.id}>{group.name}</option>
                ))}
              </select>
              {personal ? <p className="muted">Eine persönliche Liste hat keine Gruppe.</p> : null}
            </div>
            <label className="check-line">
              <input checked={personal} type="checkbox" onChange={(event) => setPersonal(event.target.checked)} />
              Persönliche Liste
            </label>
            <button className="btn primary" disabled={busy} type="submit"><BtnLabel icon="add" iconOnly={false}>Liste erstellen</BtnLabel></button>
          </div>
        </form>
      ) : null}
    </div>
  )
}
