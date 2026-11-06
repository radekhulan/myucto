import { describe, expect, it } from 'vitest'
import { i18n } from '@/i18n'
import cs from '@/i18n/cs.json'

/**
 * Česká čísla mají čtyři tvary a překlady je tak píšou. Vestavěné pravidlo
 * vue-i18n zná jen tři, takže čtvrtý tvar propadal a od pěti výš se psalo
 * „5 záznamy". Test drží pravidlo, ne konkrétní hlášku.
 */
describe('české plurály', () => {
  const message = 'žádný záznam | 1 záznam | {count} záznamy | {count} záznamů'
  const shorter = 'žádná verze | 1 verze | {count} verze'

  function translate(source: string, count: number): string {
    i18n.global.mergeLocaleMessage('cs', { __plural_probe: source })
    return i18n.global.t('__plural_probe', { count }, count)
  }

  it.each([
    [0, 'žádný záznam'],
    [1, '1 záznam'],
    [2, '2 záznamy'],
    [4, '4 záznamy'],
    [5, '5 záznamů'],
    [11, '11 záznamů'],
    [100, '100 záznamů'],
  ])('pro %i vybere správný tvar', (count, expected) => {
    expect(translate(message, count)).toBe(expected)
  })

  it('u zprávy se třemi tvary sáhne po posledním, ne mimo rozsah', () => {
    expect(translate(shorter, 5)).toBe('5 verze')
  })

  // Karta mzdových běhů psala „chybí mzdový běh za 2 měsíců".
  it.each([
    [1, 'chybí mzdový běh za 1 měsíc '],
    [2, 'chybí mzdové běhy za 2 měsíce '],
    [5, 'chybí mzdové běhy za 5 měsíců '],
  ])('chybějící předchozí běhy skloňuje podle počtu %i', (count, expected) => {
    const source = (cs as { payroll: { runs: { missing_previous: { title: string } } } })
      .payroll.runs.missing_previous.title
    i18n.global.mergeLocaleMessage('cs', { __plural_probe: source })
    expect(i18n.global.t('__plural_probe', { count, period: '1/2027' }, count)).toContain(expected)
  })
})
