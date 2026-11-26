export type AttendanceGroupId = 'yes' | 'no' | 'pending'

export type AttendancePerson = {
  name: string
  comment: string | null
}

export type AttendanceGroup = {
  id: AttendanceGroupId
  label: 'Ja' | 'Nein' | 'Ausstehend'
  people: AttendancePerson[]
}

type NamedResponse = {
  name: string
  answer: string
  comment?: string | null
}

type NamedPending = {
  name: string
}

/** Nur Ja ist beim Öffnen der Seite aufgeklappt. */
export function attendanceGroupStartsOpen(id: AttendanceGroupId): boolean {
  return id === 'yes'
}

/**
 * Drei Gruppen in fester Reihenfolge: Ja, Nein, Ausstehend.
 * Ausstehend sind Eingeladene ohne Ja und ohne Nein, auch unbeantwortete Spond-Rückmeldungen.
 */
export function groupAttendance(responses: NamedResponse[], pending: NamedPending[]): AttendanceGroup[] {
  const yes: AttendancePerson[] = []
  const no: AttendancePerson[] = []
  const answered = new Set<string>()

  for (const response of responses) {
    const person = { name: response.name, comment: response.comment ?? null }
    if (response.answer === 'yes') {
      yes.push(person)
      answered.add(response.name)
    } else if (response.answer === 'no') {
      no.push(person)
      answered.add(response.name)
    }
  }

  const waiting: AttendancePerson[] = []
  const waitingNames = new Set<string>()
  for (const response of responses) {
    if (response.answer === 'yes' || response.answer === 'no' || waitingNames.has(response.name)) continue
    waiting.push({ name: response.name, comment: response.comment ?? null })
    waitingNames.add(response.name)
  }
  for (const person of pending) {
    if (answered.has(person.name) || waitingNames.has(person.name)) continue
    waiting.push({ name: person.name, comment: null })
    waitingNames.add(person.name)
  }

  return [
    { id: 'yes', label: 'Ja', people: yes },
    { id: 'no', label: 'Nein', people: no },
    { id: 'pending', label: 'Ausstehend', people: waiting },
  ]
}
