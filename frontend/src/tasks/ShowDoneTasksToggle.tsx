import { writeShowDoneTasks } from './labels'

export function ShowDoneTasksToggle({ checked, onChange }: { checked: boolean; onChange: (show: boolean) => void }) {
  return (
    <label className="toggle">
      <input
        checked={checked}
        type="checkbox"
        onChange={(event) => {
          const next = event.target.checked
          writeShowDoneTasks(next)
          onChange(next)
        }}
      />
      Erledigte anzeigen
    </label>
  )
}
