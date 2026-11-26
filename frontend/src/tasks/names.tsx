import type { TaskCategory, TaskListRef, TaskOrgGroup } from '../api/types'

export function visibleText(value: string | null | undefined): string {
  if (typeof value !== 'string') return ''
  return value.trim()
}

export function CategoryGroupNames({ category, orgGroup }: { category?: TaskCategory | null; orgGroup?: TaskOrgGroup | null }) {
  const categoryName = visibleText(category?.name)
  const groupName = visibleText(orgGroup?.name)
  if (categoryName === '' && groupName === '') return null
  const color = categoryName !== '' ? hexColor(category?.color) : null
  return (
    <small>
      {categoryName !== '' ? (
        <span className="task-name-part">
          {color ? <span aria-hidden="true" className="task-swatch" style={{ background: color }} /> : null}
          {categoryName}
        </span>
      ) : null}
      {categoryName !== '' && groupName !== '' ? ' · ' : null}
      {groupName !== '' ? groupName : null}
    </small>
  )
}

export function TaskListName({ taskList }: { taskList?: TaskListRef | null }) {
  const name = visibleText(taskList?.name)
  if (name === '') return null
  return <small>{name}</small>
}

export type NamedChoice = { id: string; name: string }

export function namedChoices(values: Array<{ id: string | null; name: string | null } | null | undefined>): NamedChoice[] {
  const byId = new Map<string, NamedChoice>()
  for (const value of values) {
    if (!value || typeof value.id !== 'string') continue
    const id = value.id.trim()
    const name = visibleText(value.name)
    if (id === '' || name === '' || byId.has(id)) continue
    byId.set(id, { id, name })
  }
  return [...byId.values()].sort((left, right) => left.name.localeCompare(right.name, 'de'))
}

function hexColor(value: string | null | undefined): string | null {
  if (typeof value !== 'string') return null
  return /^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6}|[0-9A-Fa-f]{8})$/.test(value) ? value : null
}
