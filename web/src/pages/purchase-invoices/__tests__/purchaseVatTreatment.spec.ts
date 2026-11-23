import { describe, it, expect } from 'vitest'
import {
  allocationDeductionKey, allocationsHaveMixedDeduction, headerDeductionFromAllocations, headerDiffersFromAllocations, isNoClaimCode,
  itemConflictsWithHeader, itemVatState, uniformAllocationDeduction, type HeaderVat,
} from '@/pages/purchase-invoices/purchaseVatTreatment'

const list = [
  { code: '40', dphdp3_line: '40', dphdp3_line_secondary: null, kh_section: 'B.2', is_reverse_charge: false },
  { code: '41', dphdp3_line: '41', dphdp3_line_secondary: null, kh_section: 'B.2', is_reverse_charge: false },
  { code: '42', dphdp3_line: null, dphdp3_line_secondary: null, kh_section: null, is_reverse_charge: false },
  { code: '24e', dphdp3_line: '5', dphdp3_line_secondary: '43', kh_section: 'A.2', is_reverse_charge: true },
]
const header = (h: Partial<HeaderVat> = {}): HeaderVat => ({
  vat_deduction: 'full', vat_deduction_percent: 100, vat_classification_code: null, ...h,
})

describe('purchaseVatTreatment — stav položky v evidenci DPH', () => {
  it('kód 42 je bez nároku, 40 a samovyměření ne', () => {
    expect(isNoClaimCode('42', list)).toBe(true)
    expect(isNoClaimCode('40', list)).toBe(false)
    expect(isNoClaimCode('24e', list)).toBe(false)
  })

  it('položka 41 pod hlavičkou „bez nároku" není „shodná s hlavičkou", ale bez nároku a v rozporu', () => {
    const h = header({ vat_deduction: 'none', vat_classification_code: '41' })
    expect(itemVatState('41', h, false, list)).toEqual({ state: 'no_claim_header', effectiveCode: '41' })
    expect(itemConflictsWithHeader('41', h, false, list)).toBe(true)
  })

  it('hlavička 42 a položka 40 jsou v rozporu i u nové položky při zakládání', () => {
    const h = header({ vat_classification_code: '42' })
    expect(itemConflictsWithHeader('40', h, false, list)).toBe(true)
    expect(itemConflictsWithHeader(null, h, false, list)).toBe(false)
    expect(itemVatState(null, h, false, list)).toEqual({ state: 'no_claim_code', effectiveCode: '42' })
  })

  it('s rozpisem DPH rozhoduje rozpis, ne položka', () => {
    const h = header({ vat_deduction: 'none' })
    expect(itemVatState('40', h, true, list).state).toBe('allocations')
    expect(itemConflictsWithHeader('40', h, true, list)).toBe(false)
    expect(headerDiffersFromAllocations(h, [{ vat_deduction: 'full', vat_deduction_percent: 100 }])).toBe(true)
    expect(headerDiffersFromAllocations(header(), [{ vat_deduction: 'full', vat_deduction_percent: 100 }])).toBe(false)
  })

  it('smíšený nárok poznají jen alokace s různým nárokem', () => {
    const full = { vat_deduction: 'full' as const, vat_deduction_percent: 100 }
    expect(allocationsHaveMixedDeduction([full, full])).toBe(false)
    expect(allocationsHaveMixedDeduction([full, { vat_deduction: 'none', vat_deduction_percent: 0 }])).toBe(true)
    expect(allocationsHaveMixedDeduction([{ vat_deduction: 'proportional', vat_deduction_percent: 60 }, { vat_deduction: 'proportional', vat_deduction_percent: 70 }])).toBe(true)
    expect(allocationsHaveMixedDeduction([])).toBe(false)
  })
})

describe('purchaseVatTreatment — jednotný nárok rozpisu se propíše do hlavičky', () => {
  const full = { vat_deduction: 'full' as const, vat_deduction_percent: 100 }
  const none = { vat_deduction: 'none' as const, vat_deduction_percent: 0 }

  it('plný nárok všech alokací převezme hlavička', () => {
    expect(uniformAllocationDeduction([full, full])).toEqual({ vat_deduction: 'full', vat_deduction_percent: 100 })
    expect(headerDeductionFromAllocations([full, full], 'none', true)).toEqual({ vat_deduction: 'full', vat_deduction_percent: 100 })
  })

  it('poměrný nárok nese procento, bez nároku nulu', () => {
    const p = { vat_deduction: 'proportional' as const, vat_deduction_percent: 70 }
    expect(headerDeductionFromAllocations([p, p], 'full', true)).toEqual({ vat_deduction: 'proportional', vat_deduction_percent: 70 })
    expect(headerDeductionFromAllocations([{ vat_deduction: 'none', vat_deduction_percent: 100 }], 'full', true))
      .toEqual({ vat_deduction: 'none', vat_deduction_percent: 0 })
  })

  it('smíšený nebo prázdný rozpis hlavičku nemění', () => {
    expect(uniformAllocationDeduction([full, none])).toBeNull()
    expect(headerDeductionFromAllocations([full, none], null, true)).toBeNull()
    expect(headerDeductionFromAllocations([], null, true)).toBeNull()
    expect(allocationDeductionKey([full, none])).toBeNull()
  })

  it('beze změny rozpisu (načtený doklad) hlavička zůstává', () => {
    expect(headerDeductionFromAllocations([full], allocationDeductionKey([full]), true)).toBeNull()
  })

  it('u neplátce má přednost vynucené „bez nároku"', () => {
    expect(headerDeductionFromAllocations([full], 'none', false)).toBeNull()
  })
})
