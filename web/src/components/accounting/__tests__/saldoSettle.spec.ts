import { describe, it, expect, vi } from 'vitest'
import { SUGGEST_ACCOUNTS, distribute, isAdvanceAccount, isSettleable, withPeriodForce, isPeriodClosedError } from '@/components/accounting/saldoSettle'

const periodClosed = { response: { status: 409, data: { error: { code: 'period_closed', message: 'Uzavřené období' } } } }

describe('saldoSettle', () => {
  it('rozdělí volnou částku zápisu na doklady v pořadí, každému nejvýš jeho zbytek', () => {
    expect(distribute(1000, [700, 500])).toEqual([700, 300])
    expect(distribute(1500, [700, 500])).toEqual([700, 500])
    expect(distribute(0.3, [0.1, 0.1, 0.1])).toEqual([0.1, 0.1, 0.1])
  })

  it('rozpadem zápisu vyrovná jen korunový doklad s kladným zbytkem na účtu dokladů', () => {
    const invoice = { doc_type: 'invoice', settleable: true, currency_code: 'CZK', remaining_czk: 10 }
    expect(isSettleable(invoice, '311')).toBe(true)
    // Doklad jen z počátečních stavů nemá vlastní předpis, rozpad by ho nesnížil.
    expect(isSettleable({ ...invoice, settleable: false }, '311')).toBe(false)
    expect(isSettleable({ doc_type: 'invoice', currency_code: 'CZK', remaining_czk: 10 }, '311')).toBe(false)
    expect(isSettleable(invoice, '314')).toBe(false)
    expect(isSettleable({ ...invoice, currency_code: 'EUR' }, '311')).toBe(false)
    expect(isSettleable({ ...invoice, remaining_czk: -10 }, '311')).toBe(false)
    expect(isSettleable({ ...invoice, kind: 'advance_pending' }, '311')).toBe(false)
    expect(isSettleable({ ...invoice, doc_type: 'other_item' }, '311')).toBe(false)
  })

  it('na zálohovém účtu vyrovná čerpáním jen zálohu toho druhu, který účet nese', () => {
    const purchaseAdvance = { doc_type: 'purchase_invoice', settleable: true, currency_code: 'CZK', remaining_czk: 10 }
    const proforma = { doc_type: 'invoice', settleable: true, currency_code: 'CZK', remaining_czk: 10 }
    expect(isAdvanceAccount('314')).toBe(true)
    expect(isAdvanceAccount('311')).toBe(false)
    expect(isSettleable(purchaseAdvance, '314')).toBe(true)
    expect(isSettleable(proforma, '324')).toBe(true)
    expect(isSettleable(proforma, '314')).toBe(false)
    expect(isSettleable(purchaseAdvance, '324')).toBe(false)
    // Záloha zaplacená přímo na saldokontní účet a cizí měna se čerpáním nevyrovnávají.
    expect(isSettleable({ ...purchaseAdvance, kind: 'advance_pending' }, '314')).toBe(false)
    expect(isSettleable({ ...purchaseAdvance, currency_code: 'EUR' }, '314')).toBe(false)
    expect(isSettleable({ ...purchaseAdvance, settleable: false }, '314')).toBe(false)
    expect(SUGGEST_ACCOUNTS).toEqual(['311', '321', '315', '325', '314', '324'])
  })

  it('uzavřené období přebije jen správce po potvrzení', async () => {
    const run = vi.fn(async (force: boolean) => {
      if (!force) throw periodClosed
      return 'ok'
    })
    expect(isPeriodClosedError(periodClosed)).toBe(true)

    await expect(withPeriodForce(run, false, () => true)).rejects.toBe(periodClosed)
    expect(await withPeriodForce(run, true, () => false)).toBeNull()
    expect(await withPeriodForce(run, true, () => true)).toBe('ok')
    expect(run).toHaveBeenLastCalledWith(true)
  })
})
