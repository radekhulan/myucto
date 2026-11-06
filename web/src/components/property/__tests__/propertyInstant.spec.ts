import { describe, expect, it } from 'vitest'
import { fromLocalInstant, propertyNow, toLocalInstant } from '../propertyInstant'

describe('property instant in Europe/Prague', () => {
  it('derives winter and summer offsets around both daylight saving transitions', () => {
    expect(fromLocalInstant('2026-03-29', '01:59')).toBe('2026-03-29T01:59:00+01:00')
    expect(fromLocalInstant('2026-03-29', '03:00')).toBe('2026-03-29T03:00:00+02:00')
    expect(fromLocalInstant('2026-10-25', '01:30')).toBe('2026-10-25T01:30:00+02:00')
    expect(fromLocalInstant('2026-10-25', '04:00')).toBe('2026-10-25T04:00:00+01:00')
    expect(fromLocalInstant('2026-12-31', '23:59')).toBe('2026-12-31T23:59:00+01:00')
  })

  it('shows any API instant in local minutes and rejects incomplete input', () => {
    expect(toLocalInstant('2026-01-01T00:00:00+01:00')).toEqual({ date: '2026-01-01', time: '00:00' })
    expect(toLocalInstant('2026-07-01T10:00:00.123456Z')).toEqual({ date: '2026-07-01', time: '12:00' })
    expect(toLocalInstant('not a date')).toEqual({ date: '', time: '' })
    expect(fromLocalInstant('2026-07-01', '')).toBe('')
  })

  it('defaults to the current local minute instead of a UTC timestamp with milliseconds', () => {
    expect(propertyNow(new Date('2026-07-01T10:15:42.987Z'))).toBe('2026-07-01T12:15:00+02:00')
    expect(propertyNow(new Date('2026-01-15T23:30:05Z'))).toBe('2026-01-16T00:30:00+01:00')
  })
})
