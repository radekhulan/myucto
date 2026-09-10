import { api } from './client'

/**
 * Kontrola zaúčtovaných dokladů proti vytěžení jejich příloh. Odznak a potvrzení
 * „v pořádku" visí pod cestou dokladu (oprávnění dokladu), přehled rozporů a hromadný
 * přepočet pod sekcí Dokumenty.
 */
export type AttachmentEntityType = 'purchase_invoice' | 'invoice' | 'cash_document'
export type AttachmentField = 'tax_date_period' | 'tax_date' | 'amount' | 'counterparty_ico' | 'variable_symbol'
export type AttachmentSeverity = 'warning' | 'info'
export type AttachmentCheckState = 'none' | 'ok' | 'acknowledged' | 'warning' | 'info'

export interface AttachmentFinding {
  field: AttachmentField
  severity: AttachmentSeverity
  doc: string | number | null
  attachment: string | number | null
  diff?: number
  currency?: string
}

export interface AttachmentCheckRow {
  entity_type: AttachmentEntityType
  entity_id: number
  sha256: string
  document_id: number | null
  document_name?: string | null
  doc_no: string
  partner_name: string | null
  amount: number | null
  currency: string
  doc_tax_date: string | null
  attachment_tax_date: string | null
  status: 'match' | 'mismatch' | 'skipped'
  severity: AttachmentSeverity | null
  findings: AttachmentFinding[]
  acknowledged: boolean
  open?: boolean
  ack_reason: string | null
  ack_at: string | null
  checked_at?: string
}

export interface AttachmentCheckResult {
  rows: AttachmentCheckRow[]
  summary: { state: AttachmentCheckState; open: number; compared: number }
}

export type AttachmentListState = 'open' | 'acknowledged' | 'all'

function entityPath(type: AttachmentEntityType, id: number): string {
  if (type === 'purchase_invoice') return `/purchase-invoices/${id}/attachment-check`
  if (type === 'invoice') return `/invoices/${id}/attachment-check`
  return `/accounting/cash-documents/${id}/attachment-check`
}

export const attachmentChecksApi = {
  forEntity: (type: AttachmentEntityType, id: number) =>
    api.get<AttachmentCheckResult>(entityPath(type, id)).then(r => r.data),

  acknowledge: (type: AttachmentEntityType, id: number, sha256: string, reason: string) =>
    api.post<AttachmentCheckResult>(`${entityPath(type, id)}/acknowledge`, { sha256, reason }).then(r => r.data),

  list: (state: AttachmentListState = 'open') =>
    api.get<{ rows: AttachmentCheckRow[]; total: number; state: AttachmentListState }>('/attachment-checks', { params: { state } }).then(r => r.data),

  recheck: (from?: string | null, to?: string | null) =>
    api.post<{ checked: number; mismatches: number; open: number; removed: number }>('/attachment-checks/recheck', { from: from || null, to: to || null }).then(r => r.data),
}
