export type AttendanceGroupId = 'yes' | 'no' | 'pending'

export type AttendancePerson = {
  name: string
  comment: string | null
  picture?: string
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
  picture?: string | null
}

type NamedPending = {
  name: string
  picture?: string | null
}

function listed(name: string, comment: string | null, picture?: string | null): AttendancePerson {
  const row: AttendancePerson = { name, comment }
  if (typeof picture === 'string' && picture.startsWith('https://')) row.picture = picture
  return row
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
    const row = listed(response.name, response.comment ?? null, response.picture)
    if (response.answer === 'yes') {
      yes.push(row)
      answered.add(response.name)
    } else if (response.answer === 'no') {
      no.push(row)
      answered.add(response.name)
    }
  }

  const waiting: AttendancePerson[] = []
  const waitingNames = new Set<string>()
  for (const response of responses) {
    if (response.answer === 'yes' || response.answer === 'no' || waitingNames.has(response.name)) continue
    waiting.push(listed(response.name, response.comment ?? null, response.picture))
    waitingNames.add(response.name)
  }
  for (const invitee of pending) {
    if (answered.has(invitee.name) || waitingNames.has(invitee.name)) continue
    waiting.push(listed(invitee.name, null, invitee.picture))
    waitingNames.add(invitee.name)
  }

  return [
    { id: 'yes', label: 'Ja', people: yes },
    { id: 'no', label: 'Nein', people: no },
    { id: 'pending', label: 'Ausstehend', people: waiting },
  ]
}
