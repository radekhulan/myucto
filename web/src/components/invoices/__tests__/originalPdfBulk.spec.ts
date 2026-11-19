import { describe, expect, it } from 'vitest'
import type { InvoiceOriginalPdfMatch } from '@/api/invoices'
import { buildRows, countStates, isPdfFile } from '../originalPdfBulk'

function file(name: string, type = ''): File {
  return new File(['x'], name, { type })
}

function match(fileName: string, status: InvoiceOriginalPdfMatch['status'], invoiceId: number | null = null): InvoiceOriginalPdfMatch {
  return { file: fileName, status, reason: null, invoice_id: invoiceId, candidate_ids: invoiceId ? [invoiceId] : [], invoice: null, candidates: [] }
}

describe('originalPdfBulk', () => {
  it('rozpozná PDF podle typu i přípony', () => {
    expect(isPdfFile(file('a.PDF'))).toBe(true)
    expect(isPdfFile(file('scan', 'application/pdf'))).toBe(true)
    expect(isPdfFile(file('a.jpg', 'image/jpeg'))).toBe(false)
  })

  it('páruje výsledky podle pořadí PDF souborů a ne-PDF vynechá', () => {
    const files = [file('FV1.pdf'), file('foto.jpg', 'image/jpeg'), file('FV1.pdf'), file('x.pdf')]
    const rows = buildRows(files, [match('FV1.pdf', 'ambiguous'), match('FV1.pdf', 'ambiguous'), match('x.pdf', 'matched', 7)])

    expect(rows.map(r => r.state)).toEqual(['ambiguous', 'not_pdf', 'ambiguous', 'matched'])
    expect(rows[3].match?.invoice_id).toBe(7)
    expect(countStates(rows)).toMatchObject({ matched: 1, ambiguous: 2, not_pdf: 1, unmatched: 0 })
  })
})
