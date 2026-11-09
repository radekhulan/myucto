import { describe, expect, it } from 'vitest'
import { apiErrorInfo, apiErrorText, entryFormAt, hasAnySingle, linesToPairs, pairsToLines } from '../entryForm'
import type { EntryFormHistoryRow } from '@/api/accounting'

const row = (valid_from: string, entry_form: 'single' | 'composite'): EntryFormHistoryRow =>
  ({ valid_from, entry_form, created_by: null, created_at: '', note: null })

describe('entryFormAt', () => {
  it('bez historie je firma složená', () => {
    expect(entryFormAt([], '2027-01-01')).toBe('composite')
    expect(hasAnySingle([])).toBe(false)
  })

  it('rozhoduje nejnovější řádek s valid_from <= datum', () => {
    const h = [row('2027-01-01', 'single'), row('2028-01-01', 'composite')]
    expect(entryFormAt(h, '2026-12-31')).toBe('composite')
    expect(entryFormAt(h, '2027-01-01')).toBe('single')
    expect(entryFormAt(h, '2027-12-31')).toBe('single')
    expect(entryFormAt(h, '2028-01-01')).toBe('composite')
    expect(hasAnySingle(h)).toBe(true)
  })

  it('nezáleží na pořadí řádků historie', () => {
    const h = [row('2028-01-01', 'composite'), row('2027-01-01', 'single')]
    expect(entryFormAt(h, '2027-06-01')).toBe('single')
  })
})

describe('apiErrorText', () => {
  const t = (key: string, params?: Record<string, unknown>) =>
    ({
      'accounting.error.pair_same_account': 'Účty musí být různé.',
      'accounting.pairs.pair_label': `Dvojice ${params?.n}`,
      'common.error': 'Chyba',
    })[key] ?? key
  const err = (code: string, message: string, context?: Record<string, unknown>) =>
    ({ response: { data: { error: { code, message, context } } } })

  it('přeloží kód a připojí číslo dvojice', () => {
    expect(apiErrorText(err('pair_same_account', 'x', { pair_no: 2 }), t)).toBe('Dvojice 2: Účty musí být různé.')
  })

  it('neznámý kód spadne na text serveru, pak na obecnou chybu', () => {
    expect(apiErrorText(err('jiny_kod', 'Text ze serveru'), t)).toBe('Text ze serveru')
    expect(apiErrorText({}, t)).toBe('Chyba')
    expect(apiErrorText(err('validation_failed', 'Konkrétní důvod'), t)).toBe('Konkrétní důvod')
    expect(apiErrorInfo(err('pair_same_account', 'x', { pair_no: 0 })).pairNo).toBeNull()
  })
})

describe('linesToPairs', () => {
  const l = (account_code: string, side: 'debit' | 'credit', amount: number) => ({ account_code, side, amount })

  it('1 : N rozdělí hub na dvojice', () => {
    expect(linesToPairs([l('311', 'debit', 1210), l('602', 'credit', 1000), l('343', 'credit', 210)])).toEqual([
      { debit_account_code: '311', credit_account_code: '602', amount: 1000 },
      { debit_account_code: '311', credit_account_code: '343', amount: 210 },
    ])
  })

  it('shodné částky spáruje jedna ku jedné, i v jiném pořadí', () => {
    expect(linesToPairs([l('518', 'debit', 100), l('343', 'debit', 21), l('321', 'credit', 21), l('325', 'credit', 100)])).toEqual([
      { debit_account_code: '518', credit_account_code: '325', amount: 100 },
      { debit_account_code: '343', credit_account_code: '321', amount: 21 },
    ])
  })

  it('M : N bez shody a nevyrovnaný zápis nepáruje', () => {
    expect(linesToPairs([l('501', 'debit', 100), l('502', 'debit', 50), l('321', 'credit', 80), l('325', 'credit', 70)])).toBeNull()
    expect(linesToPairs([l('311', 'debit', 100), l('602', 'credit', 90)])).toBeNull()
    expect(linesToPairs([])).toBeNull()
  })

  it('pairsToLines dá MD a Dal pro každou dvojici', () => {
    expect(pairsToLines([{ debit_account_code: '221', credit_account_code: '311', amount: 5 }])).toEqual([
      { account_code: '221', side: 'debit', amount: 5, is_red_storno: false },
      { account_code: '311', side: 'credit', amount: 5, is_red_storno: false },
    ])
  })
})
