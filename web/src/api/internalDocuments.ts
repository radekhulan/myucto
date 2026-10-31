import { api } from './client'
import type { JournalSide, LinkableDocType } from './accounting'
import type { DimensionMap } from './dimensions'

export type InternalDocumentStatus = 'draft' | 'posted' | 'reversed'
export type InternalDocumentKind = 'other' | 'offset' | 'reclassification' | 'write_off' | 'fx_difference' | 'correction' | 'accrual'

export const INTERNAL_DOCUMENT_KINDS: InternalDocumentKind[] = [
  'offset', 'reclassification', 'write_off', 'fx_difference', 'accrual', 'correction', 'other',
]

export interface InternalDocumentLine {
  account_code: string
  side: JournalSide
  amount: number
  is_red_storno?: boolean
  cost_center?: string | null
  dimensions?: DimensionMap
}

export interface InternalDocumentLink {
  doc_type: LinkableDocType
  doc_id: number
  note?: string | null
  /** Číslo navázaného dokladu z API; při ukládání se neposílá. */
  doc_title?: string | null
}

export interface InternalDocument {
  id: number
  kind: InternalDocumentKind
  title: string
  document_date: string
  entry_date: string
  partner_id: number | null
  partner_name: string | null
  amount: number
  note: string | null
  status: InternalDocumentStatus
  /** journal = hlavička nad existujícím ručním zápisem (zápis v deníku nejde smazat). */
  origin?: 'draft' | 'journal'
  document_no: string | null
  journal_entry_id: number | null
  reversal_entry_id: number | null
  reversal_reason: string | null
  posted_at: string | null
  reversed_at: string | null
  document_count?: number
  lines?: InternalDocumentLine[]
  document_links: InternalDocumentLink[]
}

export interface InternalDocumentPayload {
  kind: InternalDocumentKind
  title: string
  document_date: string
  entry_date: string
  partner_id: number | null
  partner_name: string | null
  note: string | null
  lines: InternalDocumentLine[]
  document_links: InternalDocumentLink[]
}

export interface InternalDocumentFilters {
  status?: InternalDocumentStatus | 'all'
  kind?: InternalDocumentKind
  partner_id?: number
  q?: string
  from?: string
  to?: string
  page?: number
  per_page?: number
  sort_by?: string
  sort_dir?: 'asc' | 'desc'
}

export interface InternalDocumentList {
  items: InternalDocument[]
  total: number
  page: number
  per_page: number
  totals?: { posted_amount: number }
}

/** Odkaz deníku na interní doklad (zaúčtování nebo storno). */
export interface JournalInternalDocumentRef {
  id: number
  document_no: string | null
  status: InternalDocumentStatus
  role: 'posting' | 'reversal'
}

export const internalDocumentsApi = {
  list: (params: InternalDocumentFilters) =>
    api.get<InternalDocumentList>('/accounting/internal-documents', { params }).then(r => r.data),
  get: (id: number) => api.get<InternalDocument>(`/accounting/internal-documents/${id}`).then(r => r.data),
  create: (payload: InternalDocumentPayload) =>
    api.post<InternalDocument>('/accounting/internal-documents', payload).then(r => r.data),
  update: (id: number, payload: Partial<InternalDocumentPayload>) =>
    api.put<InternalDocument>(`/accounting/internal-documents/${id}`, payload).then(r => r.data),
  remove: (id: number) => api.delete(`/accounting/internal-documents/${id}`).then(r => r.data),
  post: (id: number) => api.post<InternalDocument>(`/accounting/internal-documents/${id}/post`).then(r => r.data),
  reverse: (id: number, payload: { reason: string; entry_date?: string }) =>
    api.post<InternalDocument>(`/accounting/internal-documents/${id}/reverse`, payload).then(r => r.data),
  fromEntry: (entryId: number, payload: { kind?: InternalDocumentKind; title?: string; note?: string | null } = {}) =>
    api.post<InternalDocument>(`/accounting/internal-documents/from-entry/${entryId}`, payload).then(r => r.data),
  /** Cesta exportu CSV se stejnými filtry jako seznam (pro `downloadApiFile`). */
  exportPath: (params: InternalDocumentFilters) => {
    const query = new URLSearchParams()
    for (const [key, value] of Object.entries(params)) {
      if (value !== undefined && value !== null && value !== '' && key !== 'page' && key !== 'per_page') query.set(key, String(value))
    }
    const qs = query.toString()
    return `/accounting/internal-documents/export${qs ? `?${qs}` : ''}`
  },
}
