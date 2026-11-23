import { describe, expect, it } from 'vitest'
import { buildCsv, csvAmount, csvCell, fetchAllPages } from './bulkJobCsv'
import { bankBulkCsvRows, buildBankBulkFilter, isBankBulkFormValid, type BankBulkForm } from './bankBulk'
import { rebookCsvRows } from './journalRebook'

const t = (key: string) => `T:${key}`

describe('bulkJobCsv', () => {
  it('escapuje středník, uvozovky a nové řádky, ostatní nechá být', () => {
    expect(csvCell('Nájem; leden')).toBe('"Nájem; leden"')
    expect(csvCell('Firma "Alfa"')).toBe('"Firma ""Alfa"""')
    expect(csvCell('řádek 1\nřádek 2')).toBe('"řádek 1\nřádek 2"')
    expect(csvCell('a\rb')).toBe('"a\rb"')
    expect(csvCell('obyčejný text')).toBe('obyčejný text')
    expect(csvCell(null)).toBe('')
    expect(csvCell(42)).toBe('42')
  })

  it('částky píše s desetinnou čárkou a bez oddělovače tisíců', () => {
    expect(csvAmount(-12345.5)).toBe('-12345,50')
    expect(csvAmount('100')).toBe('100,00')
    expect(csvAmount(null)).toBe('')
  })

  it('soubor začíná BOM, odděluje středníkem a řádky CRLF', () => {
    expect(buildCsv(['A', 'B'], [['1;2', 'x'], [null, 'y']])).toBe('﻿A;B\r\n"1;2";x\r\n;y\r\n')
  })

  it('stahuje stránky, dokud nemá total', async () => {
    const calls: number[] = []
    const all = await fetchAllPages(async (page, perPage) => {
      calls.push(page)
      expect(perPage).toBe(200)
      return { items: page < 3 ? [page, page] : [page], total: 5 }
    })
    expect(all).toEqual([1, 1, 2, 2, 3])
    expect(calls).toEqual([1, 2, 3])
  })

  it('řádky přeúčtování: změněné účty z položky, jinak účty běhu', () => {
    const rows = rebookCsvRows([
      {
        id: 1, entry_id: 10, source_type: 'invoice', source_id: 5, document_no: 'FV1', entry_date: '2026-03-01',
        description: 'Služby; březen', amount: 1210, status: 'would_change', strategy: 'replace', target_date: null,
        date_shifted: false, reason_code: null, message: null, result_entry_id: null, items_synced: 0,
        before: [{ account_code: '315001', side: 'debit', amount: 1210 }],
        after: [{ account_code: '315002', side: 'debit', amount: 1210 }],
      },
      {
        id: 2, entry_id: 11, source_type: 'cash', source_id: 6, document_no: 'PD1', entry_date: '2026-03-02',
        description: null, amount: 50, status: 'skipped', strategy: null, target_date: null,
        date_shifted: false, reason_code: 'cash_account', message: null, result_entry_id: null, items_synced: 0,
        before: [], after: [],
      },
    ], { from_account: '315001', to_account: '315002' }, t)
    expect(rows[0]).toEqual(['FV1', '2026-03-01', 'Služby; březen', '1210,00', 'T:accounting.journal_rebook.tab.would_change',
      '315001', '315002', 'T:accounting.journal_rebook.strategy.replace', ''])
    expect(rows[1].slice(4)).toEqual(['T:accounting.journal_rebook.tab.skipped', '315001', '315002', '',
      'T:accounting.journal_rebook.reason.cash_account'])
  })

  it('řádky bankovního běhu: zaúčtování i doklad, neznámý důvod ze zprávy serveru', () => {
    const base = { id: 1, entry_id: null, source_type: 'bank' as const, source_id: 7, entry_date: '2026-04-10',
      result_entry_id: null }
    const rows = bankBulkCsvRows([
      { ...base, document_no: '123', description: 'Novák · mzda', amount: -25000, status: 'would_change',
        reason_code: null, message: null,
        after: [{ account_code: '331000', side: 'debit', amount: 25000 }, { account_code: '221001', side: 'credit', amount: 25000 }] },
      { ...base, document_no: null, description: 'Terminál', amount: 980, status: 'skipped',
        reason_code: 'match_failed', message: 'Párování selhalo', after: [{ document_type: 'invoice', document_id: 3, document_no: 'FV3' }] },
    ], t)
    expect(rows[0]).toEqual(['123', '2026-04-10', 'Novák · mzda', '-25000,00', 'T:accounting.bank_bulk.tab.would_change',
      'T:accounting.bank_bulk.side_debit 331000 / T:accounting.bank_bulk.side_credit 221001', ''])
    expect(rows[1].slice(5)).toEqual(['FV3', 'Párování selhalo'])
  })
})

describe('bankBulk filtr', () => {
  function form(patch: Partial<BankBulkForm> = {}): BankBulkForm {
    return {
      action: 'post', date_from: '2026-01-01', date_to: '2026-12-31', bank_account: '', direction: 'out',
      text: '', counterparty_account: '', variable_symbol: '', amount_min: '', amount_max: '',
      counter_account: '331000', description: '', document_type: 'invoice', document_id: null, ...patch,
    }
  }

  it('zaúčtování potřebuje protiúčet, párování doklad', () => {
    expect(isBankBulkFormValid(form())).toBe(true)
    expect(isBankBulkFormValid(form({ counter_account: ' ' }))).toBe(false)
    expect(isBankBulkFormValid(form({ action: 'match' }))).toBe(false)
    expect(isBankBulkFormValid(form({ action: 'match', document_id: 3 }))).toBe(true)
    expect(isBankBulkFormValid(form({ amount_min: '500', amount_max: '100' }))).toBe(false)
  })

  it('párování neposílá směr ani protiúčet, prázdná pole jako null', () => {
    expect(buildBankBulkFilter(form({ action: 'match', document_id: 3, text: ' mzda ', amount_min: '-1 000,5' }))).toEqual({
      action: 'match', date_from: '2026-01-01', date_to: '2026-12-31', bank_account: null, text: 'mzda',
      counterparty_account: null, variable_symbol: null, amount_min: 1000.5, amount_max: null,
      document_type: 'invoice', document_id: 3,
    })
    expect(buildBankBulkFilter(form())).toMatchObject({ action: 'post', direction: 'out', counter_account: '331000', description: null })
  })
})
