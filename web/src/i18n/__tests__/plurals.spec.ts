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

  // Import docházky psal u jednoho čísla „Osobní čísla 1 má víc osob".
  it.each([
    [1, 'Osobní číslo 1 má víc osob'],
    [2, 'Osobní čísla 1, 2 má víc osob'],
  ])('sdílená osobní čísla skloňuje podle počtu %i', (count, expected) => {
    const source = (cs as { payroll_imports: { attendance: { summary: { adopt_personal_numbers_ambiguous: string } } } })
      .payroll_imports.attendance.summary.adopt_personal_numbers_ambiguous
    i18n.global.mergeLocaleMessage('cs', { __plural_probe: source })
    const numbers = count === 1 ? '1' : '1, 2'
    expect(i18n.global.t('__plural_probe', { numbers }, count)).toContain(expected)
  })

  it.each([
    [1, 'U 1 řádku je'],
    [3, 'U 3 řádků je'],
  ])('konflikt rychlých vstupů skloňuje podle počtu %i', (count, expected) => {
    const source = (cs as { payroll: { quick_inputs: { save_partial_conflict: string } } })
      .payroll.quick_inputs.save_partial_conflict
    i18n.global.mergeLocaleMessage('cs', { __plural_probe: source })
    expect(i18n.global.t('__plural_probe', { count }, count)).toContain(expected)
  })

  it('česká zpráva o dvou tvarech by pro 1 vybrala druhý tvar', () => {
    const found: string[] = []
    const walk = (source: unknown, path: string): void => {
      if (typeof source === 'string') {
        if (source.split(' | ').length === 2 && /\{(count|n|numbers)\}/.test(source)) found.push(path)
        return
      }
      if (source !== null && typeof source === 'object') {
        for (const [key, value] of Object.entries(source)) walk(value, path === '' ? key : `${path}.${key}`)
      }
    }
    walk(cs, '')
    expect(found).toEqual([])
  })
})

/**
 * Prázdná větev plurálu („ | Další {count}…") shodí kompilaci zprávy
 * (vue-i18n SyntaxError 11) a s ní celý dialog, který ji vykresluje.
 */
describe('plurálové zprávy', () => {
  function emptyBranches(source: unknown, path: string, found: string[]): void {
    if (typeof source === 'string') {
      if (source.includes(' | ') && source.split('|').some(branch => branch.trim() === '')) found.push(path)
      return
    }
    if (source !== null && typeof source === 'object') {
      for (const [key, value] of Object.entries(source)) emptyBranches(value, path === '' ? key : `${path}.${key}`, found)
    }
  }

  it.each(['cs', 'en'] as const)('v %s nemají prázdnou větev', async (locale) => {
    const messages = (await import(`@/i18n/${locale}.json`)).default
    const found: string[] = []
    emptyBranches(messages, '', found)
    expect(found).toEqual([])
  })
})
