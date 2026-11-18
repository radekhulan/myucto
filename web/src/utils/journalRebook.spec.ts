import { describe, expect, it } from 'vitest'
import {
  buildRebookFilter,
  changedLines,
  isRebookFormValid,
  rebookReasonText,
  type JournalRebookForm,
} from './journalRebook'

function form(patch: Partial<JournalRebookForm> = {}): JournalRebookForm {
  return {
    from_account: '315001', to_account: '315002', side: 'both',
    date_from: '2026-01-01', date_to: '2026-12-31',
    source_types: ['invoice', 'purchase_invoice', 'bank'],
    text: '', amount_min: '', amount_max: '', mode: 'rewrite',
    ...patch,
  }
}

describe('journalRebook', () => {
  it('sestaví filtr: ořízne účty, prázdný text a částky pošle jako null, desetinnou čárku přijme', () => {
    expect(buildRebookFilter(form({ from_account: ' 315001 ', text: '  ', amount_min: '1 000,50', source_types: ['bank', 'invoice'] }))).toEqual({
      from_account: '315001', to_account: '315002', side: 'both',
      date_from: '2026-01-01', date_to: '2026-12-31',
      source_types: ['invoice', 'bank'],
      text: null, amount_min: 1000.5, amount_max: null,
    })
  })

  it('formulář není validní se stejným účtem, bez zdroje, s obráceným obdobím nebo neplatnou částkou', () => {
    expect(isRebookFormValid(form())).toBe(true)
    expect(isRebookFormValid(form({ to_account: '315001' }))).toBe(false)
    expect(isRebookFormValid(form({ source_types: [] }))).toBe(false)
    expect(isRebookFormValid(form({ date_from: '2026-12-31', date_to: '2026-01-01' }))).toBe(false)
    expect(isRebookFormValid(form({ amount_min: 'abc' }))).toBe(false)
    expect(isRebookFormValid(form({ amount_min: '500', amount_max: '100' }))).toBe(false)
  })

  it('změna ukáže jen řádky s jiným účtem', () => {
    const lines = changedLines({
      before: [{ account_code: '315001', side: 'debit', amount: 121 }, { account_code: '602000', side: 'credit', amount: 100 }],
      after: [{ account_code: '315002', side: 'debit', amount: 121 }, { account_code: '602000', side: 'credit', amount: 100 }],
    })
    expect(lines).toEqual([{ side: 'debit', amount: 121, from: '315001', to: '315002' }])
  })

  it('důvod vynechání lokalizuje známý kód, jinak vrátí zprávu serveru', () => {
    const t = (key: string) => `T:${key}`
    expect(rebookReasonText({ reason_code: 'period_not_open', message: 'x' }, t)).toBe('T:accounting.journal_rebook.reason.period_not_open')
    expect(rebookReasonText({ reason_code: 'repost_failed', message: 'Chyba účtování' }, t)).toBe('Chyba účtování')
    expect(rebookReasonText({ reason_code: 'neznamy', message: null }, t)).toBe('neznamy')
  })
})
