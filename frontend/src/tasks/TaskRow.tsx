import type { TaskItem } from '../api/types'
import { statusButtonClass, TASK_STATUSES } from './labels'
import { TaskListName, visibleText } from './names'

export function TaskRow({ task, busy, onStatus }: { task: TaskItem; busy: boolean; onStatus: (status: string) => void }) {
  return (
    <div className="item">
      <div className={task.status === 'done' ? 'check checked' : 'check'}>{task.status === 'done' ? '✓' : ''}</div>
      <div>
        <b>{visibleText(task.title)}</b>
        <TaskListName taskList={task.taskList} />
        {task.details ? <small>{task.details}</small> : null}
        <small>{task.statusLabel ?? task.status ?? 'Ohne Status'}</small>
        {task.addressable ? (
          <span className="status-actions">
            {TASK_STATUSES.map((item) => {
              const current = task.status === item.value
              return (
                <button
                  aria-pressed={current}
                  className={statusButtonClass(current)}
                  disabled={busy}
                  key={item.value}
                  type="button"
                  onClick={() => { if (!current) onStatus(item.value) }}
                >
                  {item.label}
                </button>
              )
            })}
          </span>
        ) : null}
      </div>
    </div>
  )
}
