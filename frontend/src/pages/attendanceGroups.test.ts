import { describe, expect, it } from 'vitest'
import { attendanceGroupStartsOpen, groupAttendance } from './attendanceGroups'

describe('groupAttendance', () => {
  it('groups invited people into Ja, Nein and Ausstehend, in that order', () => {
    const groups = groupAttendance(
      [
        { name: 'Mitglied Ja', answer: 'yes', comment: 'Dabei' },
        { name: 'Mitglied Nein', answer: 'no', comment: null },
        { name: 'Spond offen', answer: 'unanswered', comment: null },
      ],
      [
        { name: 'Eingeladen offen' },
        { name: 'Mitglied Ja' },
      ],
    )

    expect(groups.map((group) => group.label)).toEqual(['Ja', 'Nein', 'Ausstehend'])
    expect(groups.map((group) => group.id)).toEqual(['yes', 'no', 'pending'])
    expect(groups.map((group) => group.people.length)).toEqual([1, 1, 2])
    expect(groups[0]?.people).toEqual([{ name: 'Mitglied Ja', comment: 'Dabei' }])
    expect(groups[1]?.people).toEqual([{ name: 'Mitglied Nein', comment: null }])
    expect(groups[2]?.people.map((person) => person.name)).toEqual(['Spond offen', 'Eingeladen offen'])
  })

  it('keeps someone who already answered out of Ausstehend', () => {
    const groups = groupAttendance(
      [{ name: 'Mitglied Ja', answer: 'yes', comment: null }],
      [{ name: 'Mitglied Ja' }],
    )

    expect(groups[2]?.people).toEqual([])
  })

  it('still returns the three groups when nobody is listed', () => {
    const groups = groupAttendance([], [])

    expect(groups).toHaveLength(3)
    expect(groups.every((group) => group.people.length === 0)).toBe(true)
  })

  it('starts only Ja expanded', () => {
    expect(attendanceGroupStartsOpen('yes')).toBe(true)
    expect(attendanceGroupStartsOpen('no')).toBe(false)
    expect(attendanceGroupStartsOpen('pending')).toBe(false)
  })
})
