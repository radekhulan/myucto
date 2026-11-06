import { describe, expect, it } from 'vitest'
import { laterInstant, propertyCode } from '../propertyCode'

describe('property code', () => {
  it('turns a typed name into a code the registry backend accepts', () => {
    expect(propertyCode('KB36 dům')).toBe('KB36-dum')
    expect(propertyCode('Zahradní 12/3')).toBe('Zahradni-12-3')
    expect(propertyCode('  ')).toBe('zaznam')
    expect(propertyCode('ž'.repeat(100))).toMatch(/^[A-Za-z0-9_.-]{1,80}$/)
  })

  it('appends a suffix when the code is taken regardless of case', () => {
    expect(propertyCode('Dum', ['dum', 'DUM-2'])).toBe('Dum-3')
  })

  it('picks the later instant and skips empty values', () => {
    expect(laterInstant('', '2026-01-01T00:00:00+01:00', null, '2026-03-01T00:00:00+01:00')).toBe('2026-03-01T00:00:00+01:00')
    expect(laterInstant()).toBe('')
  })
})
