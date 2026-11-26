import { useEffect, useState } from 'react'
import { api, ApiError } from '../api/client'
import type { TaskItem, TaskListDetail, TaskListSummary, TasksRead, TaskTemplate, TaskTemplateDetail } from '../api/types'
import { ShowDoneTasksToggle } from '../tasks/ShowDoneTasksToggle'
import { progressText, readShowDoneTasks, TASK_STATUSES, tasksEmptyCopy, visibleTasks } from '../tasks/labels'
import { CategoryGroupNames, visibleText } from '../tasks/names'
import { TaskRow } from '../tasks/TaskRow'
import { useToast } from '../shell/context'

type ListsPayload = TasksRead<{ lists: TaskListSummary[] }>
type TemplatesPayload = TasksRead<{ templates: TaskTemplate[] }>
type TasksPayload = TasksRead<{ tasks: TaskItem[] }>
type DetailPayload = TasksRead<{ list: TaskListDetail | null }>
type TemplateDetailPayload = TasksRead<{ template: TaskTemplateDetail | null }>

export function AufgabenPage() {
  const toast = useToast()
  const [lists, setLists] = useState<ListsPayload | null>(null)
  const [templates, setTemplates] = useState<TemplatesPayload | null>(null)
  const [tasks, setTasks] = useState<TasksPayload | null>(null)
  const [status, setStatus] = useState('')
  const [detail, setDetail] = useState<DetailPayload | null>(null)
  const [selected, setSelected] = useState<string | null>(null)
  const [templateDetail, setTemplateDetail] = useState<TemplateDetailPayload | null>(null)
  const [selectedTemplate, setSelectedTemplate] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
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

  function loadTemplate(id: string) {
    return api<TemplateDetailPayload>(`/api/v1/aufgaben/templates/${encodeURIComponent(id)}`)
      .then(setTemplateDetail)
      .catch((reason: unknown) => setError(reason instanceof ApiError ? reason.message : 'Die Vorlage konnte nicht geladen werden.'))
  }

  function openTemplate(id: string) {
    setSelectedTemplate(id)
    setTemplateDetail(null)
    void loadTemplate(id)
  }

  async function changeStatus(id: string, next: string) {
    setBusy(true)
    setError(null)
    try {
      await api(`/api/v1/aufgaben/tasks/${encodeURIComponent(id)}/status`, { method: 'PATCH', body: JSON.stringify({ status: next }) })
      toast('Status gespeichert. Führend bleibt die Aufgaben-App.')
      await Promise.all([
        loadTasks(),
        loadLists(),
        selected ? loadDetail(selected) : Promise.resolve(),
        selectedTemplate ? loadTemplate(selectedTemplate) : Promise.resolve(),
      ])
    } catch (reason) {
      setError(reason instanceof ApiError ? reason.message : 'Der Status konnte nicht gespeichert werden.')
    } finally {
      setBusy(false)
    }
  }

  const showCompleted = showDone || status === 'done'
  const mine = visibleTasks(tasks?.tasks ?? [], showCompleted)
  const opened = detail?.list ? visibleTasks(detail.list.tasks, showDone) : []
  const templateTasks = templateDetail?.template?.tasksIncluded
    ? visibleTasks(templateDetail.template.tasks, showDone)
    : []

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
                <button className="item" key={template.id} type="button" disabled={!template.addressable} onClick={() => openTemplate(template.id)}>
                  <div>
                    <b>{visibleText(template.name)}</b>
                    <CategoryGroupNames category={template.category} orgGroup={template.orgGroup} />
                    <small>{template.taskCount === null ? 'Aufgabenanzahl offen' : `${template.taskCount} Aufgaben`}</small>
                  </div>
                </button>
              ))}
            </div>
            {templateDetail?.notice ? <p className="empty">{templateDetail.notice}</p> : null}
            {templateDetail?.template ? (
              <div className="panel-inner">
                <div className="tasks-list-head">
                  <b>{visibleText(templateDetail.template.name)}</b>
                  {templateDetail.template.tasksIncluded && templateDetail.template.tasks.length > 0 ? (
                    <ShowDoneTasksToggle checked={showDone} onChange={setShowDone} />
                  ) : null}
                </div>
                <CategoryGroupNames category={templateDetail.template.category} orgGroup={templateDetail.template.orgGroup} />
                {templateDetail.template.description ? <p className="muted">{templateDetail.template.description}</p> : null}
                <p className="muted">{templateDetail.template.taskCount === null ? 'Aufgabenanzahl offen' : `${templateDetail.template.taskCount} Aufgaben`}</p>
                {!templateDetail.template.tasksIncluded ? <p className="muted">Die einzelnen Aufgaben dieser Vorlage stellt die Aufgaben-App hier nicht bereit.</p> : null}
                {templateDetail.template.tasksIncluded && templateDetail.template.tasks.length > 0 && templateTasks.length === 0 ? (
                  <p className="empty">Keine offenen Aufgaben.</p>
                ) : null}
                {templateDetail.template.tasksIncluded ? (
                  <div className="simplelist">
                    {templateTasks.map((task) => <TaskRow busy={busy} key={task.id} task={task} onStatus={(next) => { void changeStatus(task.id, next) }} />)}
                  </div>
                ) : null}
              </div>
            ) : null}
          </div>
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
