import { useState } from 'react'
import { useNarrow } from './narrow'

export function VehiclePick({
  value,
  options,
  onChange,
  emptyLabel = 'Fahrzeug wählen',
  ariaLabel,
}: {
  value: string
  options: Array<{ id: string; label: string }>
  onChange: (id: string) => void
  emptyLabel?: string
  ariaLabel?: string
}) {
  const narrow = useNarrow()
  const [open, setOpen] = useState(false)
  if (!narrow) {
    return (
      <select aria-label={ariaLabel} className="input" value={value} onChange={(event) => onChange(event.target.value)}>
        <option value="">{emptyLabel}</option>
        {options.map((option) => <option key={option.id} value={option.id}>{option.label}</option>)}
      </select>
    )
  }
  const current = options.find((option) => option.id === value)
  return (
    <div className="pick-list">
      <button aria-expanded={open} aria-label={ariaLabel} className="input pick-current" type="button" onClick={() => setOpen((shown) => !shown)}>
        {current?.label ?? emptyLabel}
      </button>
      {open ? (
        <ul className="station-results">
          <li>
            <button type="button" onClick={() => { onChange(''); setOpen(false) }}>{emptyLabel}</button>
          </li>
          {options.map((option) => (
            <li key={option.id}>
              <button type="button" onClick={() => { onChange(option.id); setOpen(false) }}>{option.label}</button>
            </li>
          ))}
        </ul>
      ) : null}
    </div>
  )
}
