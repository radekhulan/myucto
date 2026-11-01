import { describe, expect, it } from 'vitest'
import type { BankStatement } from '@/api/bank'
import {
  statementClosingBalance,
  statementDownloadsWithMovements,
  statementGpcTitle,
  statementGpcUrl,
  statementHasGpc,
} from '@/utils/bankStatement'

describe('stažení z banky v detailu měsíce', () => {
  it('nabídne jen stažení s pohyby', () => {
    const empty = { id: 1, transaction_count: 0 }
    const withMovements = { id: 2, transaction_count: 20 }

    expect(statementDownloadsWithMovements({ evidence_statements: [empty, withMovements] })).toEqual([withMovements])
    expect(statementDownloadsWithMovements({})).toEqual([])
    expect(statementDownloadsWithMovements(null)).toEqual([])
  })
})

describe('intraday advice balance', () => {
  const advice = {
    id: 123, source: 'bank_api', has_file: false, curr_balance: null,
    balance_calculation: { status: 'calculated', opening: null, closing: 125, bank_statement_id: null },
  } as BankStatement

  it('shows the known balance without offering a fabricated GPC opening balance', () => {
    expect(statementClosingBalance(advice)).toBe(125)
    expect(statementGpcUrl(advice)).toBeUndefined()
    expect(statementGpcTitle(advice)).toBe('bank.balance_missing_anchor')
  })

  it('offers the original bank document after finalization', () => {
    const finalized = { ...advice, balance_calculation: { ...advice.balance_calculation, bank_statement_id: 456 } } as BankStatement
    expect(statementGpcUrl(finalized)).toContain('456')
    expect(statementGpcTitle(finalized)).toBe('bank.download_gpc')
  })
})

describe('GPC u výpisu nahraného jako PDF', () => {
  // Doklad od banky je PDF, soubor GPC k němu neexistuje — ale zůstatky i pohyby
  // ano, takže GPC pro účetní jde složit. Právě pro tyhle banky se import PDF
  // výpisů zaváděl, takže tlačítko tu chybět nemá.
  const pdfStatement = {
    id: 6, source: 'pdf', has_file: false, curr_balance: 13288.62,
    balance_calculation: {
      status: 'confirmed', opening: 1755.08, closing: 13288.62, bank_statement_id: null,
    },
  } as BankStatement

  it('nabídne vygenerovaný GPC', () => {
    expect(statementHasGpc(pdfStatement)).toBe(true)
    expect(statementGpcUrl(pdfStatement)).toContain('6')
    expect(statementGpcTitle(pdfStatement)).toBe('bank.gpc_calculated_hint')
  })

  it('bez počátečního zůstatku tlačítko ukáže, ale nepustí a řekne proč', () => {
    // Stejné chování jako u průběžného avíza z bankovního API: GPC bez kotvy
    // zůstatku by měl vymyšlený počáteční stav, takže se nenabízí ke stažení.
    const withoutOpening = {
      ...pdfStatement,
      balance_calculation: { ...pdfStatement.balance_calculation, opening: null },
    } as BankStatement

    expect(statementHasGpc(withoutOpening)).toBe(true)
    expect(statementGpcUrl(withoutOpening)).toBeUndefined()
    expect(statementGpcTitle(withoutOpening)).toBe('bank.balance_missing_anchor')
  })

  it('nahrané GPC se dál stahuje v originále, ne přepočtem', () => {
    const uploaded = { id: 7, source: 'gpc', has_file: true } as BankStatement

    expect(statementHasGpc(uploaded)).toBe(true)
    expect(statementGpcTitle(uploaded)).toBe('bank.download_gpc')
  })
})
