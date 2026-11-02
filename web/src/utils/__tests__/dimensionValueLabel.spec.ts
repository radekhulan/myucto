import { describe, expect, it } from 'vitest'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import { dimensionValueLabel, dimensionValueName } from '../dimensionValueLabel'

function i18nOf(messages: Record<string, unknown>) {
  const lookup = (key: string): unknown => key.split('.').reduce<unknown>((node, part) => (node && typeof node === 'object' ? (node as Record<string, unknown>)[part] : undefined), messages)
  return { t: (key: string) => String(lookup(key) ?? key), te: (key: string) => typeof lookup(key) === 'string' }
}

const economic = { code: 'economic', name: 'Hospodářská činnost', semantic_key: 'economic' }

describe('dimensionValueLabel', () => {
  it('systémová hodnota se ukazuje bez interního kódu a v jazyce aplikace', () => {
    expect(dimensionValueLabel(i18nOf(cs), economic, 'activity')).toBe('Hospodářská činnost')
    expect(dimensionValueLabel(i18nOf(en), economic, 'activity')).toBe('Economic activity')
    expect(dimensionValueLabel(i18nOf(en), { code: 'exempt', name: 'Osvobozeno', semantic_key: 'exempt' }, 'tax_regime')).toBe('Exempt')
  })

  it('systémová hodnota bez překladu má název z databáze, kód zůstává skrytý', () => {
    const unit = { code: 'unit:12', name: 'Jednotka A1', semantic_key: 'unit:12' }
    expect(dimensionValueLabel(i18nOf(en), unit, 'unit')).toBe('Jednotka A1')
    expect(dimensionValueName(i18nOf(en), economic, null)).toBe('Hospodářská činnost')
  })

  it('vlastní hodnota má kód a název, stejné nebo prázdné se neopakují', () => {
    expect(dimensionValueLabel(i18nOf(cs), { code: 'STR01', name: 'Brno' })).toBe('STR01 – Brno')
    expect(dimensionValueLabel(i18nOf(cs), { code: 'Brno', name: 'Brno' })).toBe('Brno')
    expect(dimensionValueLabel(i18nOf(cs), { code: 'P', name: null })).toBe('P')
    expect(dimensionValueName(i18nOf(cs), { code: 'economic', name: 'Vlastní', semantic_key: null }, 'activity')).toBe('Vlastní')
  })
})
