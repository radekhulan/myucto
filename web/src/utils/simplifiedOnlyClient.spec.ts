import { describe, expect, it } from 'vitest'
import { simplifiedOnlyClientProblem } from '@/utils/simplifiedOnlyClient'

const base = {
  simplifiedOnly: true,
  supplierIsVatPayer: true,
  invoiceType: 'invoice',
  isSimplified: true,
  totalWithVat: 1000,
}

describe('simplifiedOnlyClientProblem', () => {
  it('pustí zjednodušený doklad do limitu', () => {
    expect(simplifiedOnlyClientProblem(base)).toBeNull()
    expect(simplifiedOnlyClientProblem({ ...base, totalWithVat: 10000 })).toBeNull()
  })

  it('nad limitem nabídne doplnění karty nebo snížení prodeje', () => {
    expect(simplifiedOnlyClientProblem({ ...base, totalWithVat: 10000.01 })).toBe('over_limit')
    expect(simplifiedOnlyClientProblem({ ...base, totalWithVat: -12100 })).toBe('over_limit')
  })

  it('běžnou fakturu ani zálohu drobnému zákazníkovi nepustí', () => {
    expect(simplifiedOnlyClientProblem({ ...base, isSimplified: false })).toBe('requires_simplified')
    expect(simplifiedOnlyClientProblem({ ...base, invoiceType: 'proforma' })).toBe('requires_simplified')
  })

  it('běžného klienta a neplátce neomezuje', () => {
    expect(simplifiedOnlyClientProblem({ ...base, simplifiedOnly: false, isSimplified: false, totalWithVat: 50000 })).toBeNull()
    expect(simplifiedOnlyClientProblem({ ...base, supplierIsVatPayer: false, isSimplified: false, totalWithVat: 50000 })).toBeNull()
  })

  it('dobropis pustí jen jako zjednodušený opravný doklad k původnímu dokladu', () => {
    const creditNote = { ...base, invoiceType: 'credit_note', totalWithVat: -1000, hasOriginal: true }
    expect(simplifiedOnlyClientProblem(creditNote)).toBeNull()
    expect(simplifiedOnlyClientProblem({ ...creditNote, hasOriginal: false })).toBe('credit_note_requires_original')
    expect(simplifiedOnlyClientProblem({ ...creditNote, isSimplified: false })).toBe('requires_simplified')
    expect(simplifiedOnlyClientProblem({ ...creditNote, totalWithVat: -12100 })).toBe('over_limit')
  })
})
