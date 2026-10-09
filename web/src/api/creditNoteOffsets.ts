import { api } from './client'

/** Zápočet dobropisu proti opravované faktuře (issue #140). */
export type CreditNoteOffsetDocType = 'invoice' | 'purchase_invoice'

export interface CreditNoteOffset {
  id: number
  doc_type: CreditNoteOffsetDocType
  invoice_id: number
  credit_note_id: number
  amount: number
  offset_on: string
  invoice_number: string | null
  credit_note_number: string | null
}

export interface CreditNoteOffsetState {
  offsets: CreditNoteOffset[]
  can_offset: boolean
  reason: string | null
}

function base(docType: CreditNoteOffsetDocType, id: number): string {
  return docType === 'invoice'
    ? `/invoices/${id}/credit-note-offset`
    : `/purchase-invoices/${id}/credit-note-offset`
}

export const creditNoteOffsetsApi = {
  get: (docType: CreditNoteOffsetDocType, id: number) =>
    api.get<CreditNoteOffsetState>(base(docType, id)).then(r => r.data),
  apply: (docType: CreditNoteOffsetDocType, id: number) =>
    api.post<CreditNoteOffsetState>(base(docType, id)).then(r => r.data),
  revert: (docType: CreditNoteOffsetDocType, id: number) =>
    api.delete<CreditNoteOffsetState>(base(docType, id)).then(r => r.data),
}
