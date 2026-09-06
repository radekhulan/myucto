import { describe, expect, it } from 'vitest'
import { dateInputLocale, formatIsoForInput, isoParts, parseInputDate } from '@/utils/dateInput'

describe('dateInputLocale', () => {
  it('čeština píše den-měsíc-rok s tečkou, angličtina měsíc-den-rok s lomítkem', () => {
    expect(dateInputLocale('cs')).toEqual({ tag: 'cs-CZ', order: ['day', 'month', 'year'], separator: '.' })
    expect(dateInputLocale('en')).toEqual({ tag: 'en-US', order: ['month', 'day', 'year'], separator: '/' })
  })
})

describe('formatIsoForInput', () => {
  it('formátuje podle jazyka aplikace, ne prohlížeče', () => {
    expect(formatIsoForInput('2026-09-01', 'cs')).toBe('01. 09. 2026')
    expect(formatIsoForInput('2026-09-01', 'en')).toBe('09/01/2026')
  })

  it('prázdné a neplatné hodnoty nechá pole prázdné', () => {
    expect(formatIsoForInput('', 'cs')).toBe('')
    expect(formatIsoForInput(null, 'cs')).toBe('')
    expect(formatIsoForInput('2026-02-31', 'cs')).toBe('')
    expect(formatIsoForInput('nesmysl', 'cs')).toBe('')
  })
})

describe('parseInputDate', () => {
  it('česky: tečky, mezery i zkrácený zápis', () => {
    expect(parseInputDate('1.9.2026', 'cs')).toBe('2026-09-01')
    expect(parseInputDate('01. 09. 2026', 'cs')).toBe('2026-09-01')
    expect(parseInputDate('  1 . 9 . 2026 ', 'cs')).toBe('2026-09-01')
    expect(parseInputDate('1.9.26', 'cs')).toBe('2026-09-01')
    expect(parseInputDate('01092026', 'cs')).toBe('2026-09-01')
    expect(parseInputDate('31.12.2026', 'cs')).toBe('2026-12-31')
  })

  it('anglicky: měsíc před dnem — přesně to, co issue #276 řeší', () => {
    expect(parseInputDate('9/1/2026', 'en')).toBe('2026-09-01')
    expect(parseInputDate('09/01/2026', 'en')).toBe('2026-09-01')
    expect(parseInputDate('09012026', 'en')).toBe('2026-09-01')
    // Stejný text má v každém jazyce jiný význam — proto je jazyk parametr.
    expect(parseInputDate('9/1/2026', 'cs')).toBe('2026-01-09')
  })

  it('dvouciferný rok jde vypnout — během psaní je „1.9.20" mezistav, ne rok 2020', () => {
    expect(parseInputDate('1.9.20', 'cs', { allowShortYear: false })).toBeNull()
    expect(parseInputDate('1.9.2026', 'cs', { allowShortYear: false })).toBe('2026-09-01')
    expect(parseInputDate('9/1/26', 'en')).toBe('2026-09-01')
    expect(parseInputDate('9/1/26', 'en', { allowShortYear: false })).toBeNull()
    expect(parseInputDate('1.9.203', 'cs')).toBeNull()
    expect(parseInputDate('1.9.20326', 'cs')).toBeNull()
  })

  it('ISO projde v každém jazyce (schránka, API)', () => {
    expect(parseInputDate('2026-09-01', 'cs')).toBe('2026-09-01')
    expect(parseInputDate('2026-09-01', 'en')).toBe('2026-09-01')
  })

  it('neexistující datum odmítne místo tichého posunu', () => {
    expect(parseInputDate('31.2.2026', 'cs')).toBeNull()
    expect(parseInputDate('29.2.2025', 'cs')).toBeNull()
    expect(parseInputDate('29.2.2024', 'cs')).toBe('2024-02-29')
    expect(parseInputDate('0.9.2026', 'cs')).toBeNull()
    expect(parseInputDate('1.13.2026', 'cs')).toBeNull()
    expect(parseInputDate('2026-02-31', 'cs')).toBeNull()
  })

  it('rozepsaný nebo cizí vstup vrací null, ne částečné datum', () => {
    expect(parseInputDate('', 'cs')).toBeNull()
    expect(parseInputDate('1.9.', 'cs')).toBeNull()
    expect(parseInputDate('1.9.202', 'cs')).toBeNull()
    expect(parseInputDate('1.9.20266', 'cs')).toBeNull()
    expect(parseInputDate('dnes', 'cs')).toBeNull()
    expect(parseInputDate('1.9.2026.5', 'cs')).toBeNull()
  })
})

describe('isoParts', () => {
  it('rozloží jen platné ISO datum', () => {
    expect(isoParts('2026-09-01')).toEqual({ year: 2026, month: 9, day: 1 })
    expect(isoParts('2026-9-1')).toBeNull()
    expect(isoParts('2026-02-30')).toBeNull()
  })
})
