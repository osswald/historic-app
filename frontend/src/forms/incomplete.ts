export function missingFieldLabels(fields: ReadonlyArray<{ label: string; filled: boolean }>): string[] {
  const labels: string[] = []
  for (const field of fields) {
    const label = field.label.trim()
    if (field.filled || label === '' || labels.includes(label)) continue
    labels.push(label)
  }
  return labels
}

export function incompleteMessage(missing: readonly string[]): string {
  const labels = missingFieldLabels(missing.map((label) => ({ label, filled: false })))
  if (labels.length === 0) return 'Die Eingaben sind unvollständig.'
  return `Die Eingaben sind unvollständig. ${missingSentence(labels)}`
}

function missingSentence(labels: string[]): string {
  if (labels.length === 1) return `Es fehlt ${labels[0]}.`
  const last = labels[labels.length - 1]
  const head = labels.slice(0, -1)
  if (head.length === 1) return `Es fehlen ${head[0]} und ${last}.`
  return `Es fehlen ${head.join(', ')} und ${last}.`
}
