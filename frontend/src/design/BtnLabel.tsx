import type { ReactNode } from 'react'

export type ActionIconName =
  | 'add'
  | 'save'
  | 'edit'
  | 'delete'
  | 'close'
  | 'external'
  | 'logout'
  | 'upload'
  | 'link'
  | 'copy'
  | 'back'
  | 'up'
  | 'down'
  | 'check'
  | 'folder'
  | 'circle'
  | 'progress'

const ICON_ONLY = new Set<ActionIconName>([
  'add',
  'save',
  'edit',
  'delete',
  'close',
  'external',
  'logout',
  'upload',
  'link',
])

export function BtnLabel({
  icon,
  children,
  iconOnly,
}: {
  icon: ActionIconName
  children: ReactNode
  iconOnly?: boolean
}) {
  const clear = iconOnly ?? ICON_ONLY.has(icon)
  return (
    <>
      <ActionIcon name={icon} />
      <span className={clear ? 'btn-label is-clear' : 'btn-label'}>{children}</span>
    </>
  )
}

export function ActionIcon({ name }: { name: ActionIconName }) {
  return (
    <svg aria-hidden="true" className="btn-ico" viewBox="0 0 16 16">
      {iconPaths(name)}
    </svg>
  )
}

function iconPaths(name: ActionIconName) {
  const stroke = {
    fill: 'none',
    stroke: 'currentColor',
    strokeLinecap: 'round' as const,
    strokeLinejoin: 'round' as const,
    strokeWidth: 1.6,
  }
  if (name === 'add') return <path {...stroke} d="M8 3.1v9.8M3.1 8h9.8" />
  if (name === 'save') {
    return (
      <>
        <path {...stroke} d="M3.2 2.7h6.7L12.8 5.4v8H3.2V2.7Z" />
        <path {...stroke} d="M5.3 2.7v3.1h4.2" />
        <path {...stroke} d="M5.2 13.4v-3.3h5.6v3.3" />
      </>
    )
  }
  if (name === 'edit') {
    return (
      <>
        <path {...stroke} d="M9.2 3.1 12.9 6.8 6.3 13.4H2.6V9.7Z" />
        <path {...stroke} d="M8.1 4.2 11.8 7.9" />
      </>
    )
  }
  if (name === 'delete') {
    return (
      <>
        <path {...stroke} d="M3.1 4.5h9.8" />
        <path {...stroke} d="M6.2 4.5V3h3.6v1.5" />
        <path {...stroke} d="M4.3 4.5l.7 8.6h6l.7-8.6" />
      </>
    )
  }
  if (name === 'close') return <path {...stroke} d="M4.2 4.2 11.8 11.8M11.8 4.2 4.2 11.8" />
  if (name === 'external') {
    return (
      <>
        <path {...stroke} d="M6.5 3.5H3.4v9.1h9.2V9.6" />
        <path {...stroke} d="M8.6 3.4h4v4" />
        <path {...stroke} d="M12.5 3.5 7.2 8.8" />
      </>
    )
  }
  if (name === 'logout') {
    return (
      <>
        <path {...stroke} d="M6.5 3.2H3.3v9.6h3.2" />
        <path {...stroke} d="M7.2 8h5.6" />
        <path {...stroke} d="M10.6 5.5 13.2 8l-2.6 2.5" />
      </>
    )
  }
  if (name === 'upload') {
    return (
      <>
        <path {...stroke} d="M8 11.3V3.2" />
        <path {...stroke} d="M5.1 6.1 8 3.2l2.9 2.9" />
        <path {...stroke} d="M3.2 12.8h9.6" />
      </>
    )
  }
  if (name === 'link') {
    return (
      <>
        <path {...stroke} d="M6.6 9.4 9.4 6.6" />
        <path {...stroke} d="M9.1 4.3a2.45 2.45 0 0 1 3.5 3.5L11.2 9.2" />
        <path {...stroke} d="M6.9 11.7a2.45 2.45 0 0 1-3.5-3.5L4.8 6.8" />
      </>
    )
  }
  if (name === 'copy') {
    return (
      <>
        <path {...stroke} d="M6 5.2h6.4V13H6z" />
        <path {...stroke} d="M3.6 10.7V3h6.4" />
      </>
    )
  }
  if (name === 'back') return <path {...stroke} d="M10.3 3.2 5.5 8l4.8 4.8" />
  if (name === 'up') return <path {...stroke} d="M3.2 10.3 8 5.5l4.8 4.8" />
  if (name === 'down') return <path {...stroke} d="M3.2 5.7 8 10.5l4.8-4.8" />
  if (name === 'check') return <path {...stroke} d="M3.4 8.2 6.5 11.3 12.6 4.7" />
  if (name === 'folder') return <path {...stroke} d="M2.4 4.6h4.1l1.2 1.4h5.9V12.6H2.4V4.6Z" />
  if (name === 'circle') return <circle {...stroke} cx="8" cy="8" r="4.5" />
  return (
    <>
      <circle {...stroke} cx="8" cy="8" r="4.5" />
      <path {...stroke} d="M8 5.3V8l2 1.3" />
    </>
  )
}
