import { describe, expect, it } from 'vitest'
import { incompleteMessage, missingFieldLabels } from './incomplete'

describe('incompleteMessage', () => {
  it('keeps the existing sentence when nothing is named', () => {
    expect(incompleteMessage([])).toBe('Die Eingaben sind unvollständig.')
  })

  it('names one missing field', () => {
    expect(incompleteMessage(['Zugnummer'])).toBe('Die Eingaben sind unvollständig. Es fehlt Zugnummer.')
  })

  it('names two missing fields with und', () => {
    expect(incompleteMessage(['Zugnummer', 'Startbahnhof'])).toBe(
      'Die Eingaben sind unvollständig. Es fehlen Zugnummer und Startbahnhof.',
    )
  })

  it('names three missing fields', () => {
    expect(incompleteMessage(['Zugnummer', 'Startbahnhof', 'Zielbahnhof'])).toBe(
      'Die Eingaben sind unvollständig. Es fehlen Zugnummer, Startbahnhof und Zielbahnhof.',
    )
  })

  it('drops duplicates and blank labels', () => {
    expect(incompleteMessage([' Datum ', 'Datum', ''])).toBe('Die Eingaben sind unvollständig. Es fehlt Datum.')
  })
})

describe('missingFieldLabels', () => {
  it('lists only empty required fields and keeps the visible labels', () => {
    expect(missingFieldLabels([
      { label: 'Zugnummer', filled: false },
      { label: 'Abfahrt', filled: false },
      { label: 'Startbahnhof', filled: true },
      { label: 'Zugnummer', filled: false },
    ])).toEqual(['Zugnummer', 'Abfahrt'])
  })
})
