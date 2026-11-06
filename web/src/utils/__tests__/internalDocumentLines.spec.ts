import { describe, expect, it } from 'vitest'
import {
  autoBalanceAmount, autoBalanceIndex, balancingLine, emptyLine, isBalanced, lineTotals, resolvedLines,
  type DraftLine,
} from '../internalDocumentLines'

function line(account: string, side: 'debit' | 'credit', amount: number | null, red = false): DraftLine {
  return { ...emptyLine(side, account), amount, is_red_storno: red }
}

describe('internalDocumentLines', () => {
  it('dopočítá jediný řádek s účtem a bez částky', () => {
    const lines = [line('321', 'debit', 1500), line('311', 'credit', null)]
    expect(autoBalanceIndex(lines)).toBe(1)
    expect(autoBalanceAmount(lines)).toBe(1500)
    expect(resolvedLines(lines)[1].amount).toBe(1500)
    expect(isBalanced(lines)).toBe(true)
    expect(lineTotals(lines)).toEqual({ debit: 1500, credit: 1500, diff: 0 })
  })

  it('rozpad na víc řádků dorovná zbytkem a haléře počítá přesně', () => {
    const lines = [line('518', 'debit', 0.1), line('343', 'debit', 0.2), line('321', 'credit', null)]
    expect(autoBalanceAmount(lines)).toBe(0.3)
    expect(isBalanced(lines)).toBe(true)
  })

  it('bez účtu nebo při dvou prázdných částkách nic nedopočítá', () => {
    expect(autoBalanceIndex([line('321', 'debit', 100), line('', 'credit', null)])).toBeNull()
    expect(autoBalanceIndex([line('321', 'debit', null), line('311', 'credit', null)])).toBeNull()
    expect(isBalanced([line('321', 'debit', null), line('311', 'credit', null)])).toBe(false)
  })

  it('řádek na špatné straně kladnou částkou nevyrovná', () => {
    const lines = [line('321', 'debit', 100), line('311', 'debit', null)]
    expect(autoBalanceAmount(lines)).toBeNull()
    expect(isBalanced(lines)).toBe(false)
  })

  it('červené storno snižuje svou stranu', () => {
    const lines = [line('321', 'debit', 1000), line('321', 'debit', 200, true), line('311', 'credit', null)]
    expect(autoBalanceAmount(lines)).toBe(800)
    expect(lineTotals(lines).debit).toBe(800)
  })

  it('Dorovnat navrhne řádek na straně s menším součtem', () => {
    expect(balancingLine([line('321', 'debit', 1500), line('311', 'credit', 1400)]))
      .toEqual(expect.objectContaining({ side: 'credit', amount: 100, account_code: '' }))
    expect(balancingLine([line('321', 'debit', 100), line('311', 'credit', 250)]))
      .toEqual(expect.objectContaining({ side: 'debit', amount: 150 }))
    expect(balancingLine([line('321', 'debit', 100), line('311', 'credit', 100)])).toBeNull()
  })
})
