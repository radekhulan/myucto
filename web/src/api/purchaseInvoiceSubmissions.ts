import { requestSupplierId } from '@/workspace/supplierContext'
import { api } from './client'

export type PurchaseInvoiceSubmissionStatus =
  | 'submitted'
  | 'processing'
  | 'needs_information'
  | 'processed'
  | 'rejected'

export type PurchaseInvoiceSubmissionKindHint =
  | 'invoice'
  | 'receipt'
  | 'credit_note'
  | 'advance'
  | 'tax_document'
  | 'other'

export interface PurchaseInvoiceSubmission {
  id: number
  supplier_id: number
  document_id: number
  bank_transaction_id: number | null
  supersedes_submission_id: number | null
  replacement_submission_id: number | null
  submitted_by: number | null
  submitted_via: 'portal' | 'document_request' | 'staff' | 'email'
  note: string | null
  document_kind_hint: PurchaseInvoiceSubmissionKindHint | null
  status: PurchaseInvoiceSubmissionStatus
  status_reason: string | null
  purchase_invoice_id: number | null
  processed_at: string | null
  created_at: string
  updated_at: string
  original_name: string
  mime_type: string
  doc_type: 'pdf' | 'image' | 'xml' | 'other'
  size_bytes: number
  submitted_by_name: string | null
  vendor_invoice_number: string | null
  purchase_invoice_varsymbol: string | null
  vendor_name: string | null
  request_count: number
  /** Dimenze zvolené při nahrání (typ → hodnota), propíšou se do hlavičky vytěženého dokladu. */
  dimensions?: Record<number, number>
  duplicate?: boolean
  can_delete?: boolean
  /**
   * Interní diagnostika zpracování — servíruje ji jen účetní fronta.
   * V odpovědi klientského portálu tyhle klíče nejsou, viz
   * PortalPurchaseInvoiceSubmissionAction::portalView().
   */
  extraction_status?: 'not_started' | 'running' | 'succeeded' | 'failed'
  extraction_source?: string | null
  extraction_error?: string | null
  /** Vytěžení se nedokončilo (přerušený požadavek); podání jde vytěžit znovu nebo smazat. */
  processing_stale?: boolean
  document_sha256?: string
  thumb_status?: string
  processed_by?: number | null
  processed_by_name?: string | null
  processing_started_at?: string | null
  /** Odběratel přečtený vytěžením (ISDOC/AI); podle něj fronta navrhuje přesun. */
  detected_buyer_ic?: string | null
  detected_buyer_dic?: string | null
  /** Firma, které doklad podle odběratele patří a do které ho uživatel smí přesunout. */
  move_suggestion?: { supplier_id: number; supplier_name: string } | null
  /** Při rozděleném nahrání: název firmy, kam doklad odešel (null = aktivní firma). */
  target_supplier_name?: string | null
}

export interface SubmissionMoveTargets {
  targets: Array<{ id: number; name: string; ic: string | null; dic: string | null }>
  suggestion: { supplier_id: number; matched_by: string | null } | null
  /** Proč doklad přesunout nejde; null = jde. */
  blocked_reason: string | null
}

export interface PurchaseInvoiceSubmissionPage {
  items: PurchaseInvoiceSubmission[]
  total: number
}

export interface SubmissionUploadResult {
  items: PurchaseInvoiceSubmission[]
  created: number
  duplicates: number
  errors: Array<{ filename: string; code: string; message: string }>
}

/**
 * Přímá navigace v prohlížeči (iframe/img/`<a href>`) neposílá hlavičku
 * `X-Supplier-Id` z axios interceptoru — aktivní firma musí jít v query paramu,
 * jinak server spadne na fallback MIN(supplier.id) a doklad jiné firmy nenajde.
 */
function fileUrl(path: string): string {
  const sid = requestSupplierId()
  const params = new URLSearchParams()
  if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
  const qs = params.toString()
  return `/api${path}${qs ? '?' + qs : ''}`
}

function uploadForm(
  files: File[],
  note: string,
  documentKindHint: PurchaseInvoiceSubmissionKindHint | null,
  dimensions: Record<number, number | null> = {},
  distribute = false,
): FormData {
  const fd = new FormData()
  for (const file of files) fd.append('file[]', file, file.name)
  if (distribute) fd.append('distribute', '1')
  if (note.trim()) fd.append('note', note.trim())
  if (documentKindHint) fd.append('document_kind_hint', documentKindHint)
  for (const [typeId, valueId] of Object.entries(dimensions)) {
    if (valueId) fd.append(`dimensions[${typeId}]`, String(valueId))
  }
  return fd
}

/** Hodnoty dimenze, které smí zvolit klient na portálu (jen aktivní střediska firmy). */
export interface PortalDimensionChoiceType {
  id: number
  name: string
  values: Array<{ id: number; code: string; name: string; parent_id: number | null }>
}

export const portalPurchaseInvoiceSubmissionsApi = {
  list: (status?: PurchaseInvoiceSubmissionStatus) =>
    api.get<PurchaseInvoiceSubmissionPage>('/portal/purchase-invoice-submissions', {
      params: status ? { status } : undefined,
    }).then(r => r.data),
  dimensions: () =>
    api.get<{ types: PortalDimensionChoiceType[] }>('/portal/purchase-invoice-submissions/dimensions').then(r => r.data.types),
  upload: (files: File[], note: string, documentKindHint: PurchaseInvoiceSubmissionKindHint | null, dimensions: Record<number, number | null> = {}) =>
    api.post<SubmissionUploadResult>(
      '/portal/purchase-invoice-submissions',
      uploadForm(files, note, documentKindHint, dimensions),
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data),
  resubmit: (id: number, file: File, note: string) =>
    api.post<SubmissionUploadResult>(
      `/portal/purchase-invoice-submissions/${id}/resubmit`,
      uploadForm([file], note, null),
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data),
  remove: (id: number) =>
    api.delete<{ ok: boolean; document_id: number }>(`/portal/purchase-invoice-submissions/${id}`).then(r => r.data),
  previewUrl: (id: number) => fileUrl(`/portal/purchase-invoice-submissions/${id}/preview`),
  downloadUrl: (id: number) => fileUrl(`/portal/purchase-invoice-submissions/${id}/download`),
}

export const purchaseInvoiceSubmissionsApi = {
  list: (status?: PurchaseInvoiceSubmissionStatus) =>
    api.get<PurchaseInvoiceSubmissionPage>('/purchase-invoice-submissions', {
      params: status ? { status } : undefined,
    }).then(r => r.data),
  /**
   * Doklad, který přišel mimo portál (e-mailem, papírově) — účetní ho vloží do fronty sama.
   * `distribute` pošle každý soubor do firmy podle odběratele (jen firmy s právem na příchozí doklady).
   */
  upload: (files: File[], note: string, documentKindHint: PurchaseInvoiceSubmissionKindHint | null, dimensions: Record<number, number | null> = {}, distribute = false) =>
    api.post<SubmissionUploadResult>(
      '/purchase-invoice-submissions',
      uploadForm(files, note, documentKindHint, dimensions, distribute),
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data),
  get: (id: number) =>
    api.get<PurchaseInvoiceSubmission>(`/purchase-invoice-submissions/${id}`).then(r => r.data),
  extract: (id: number) =>
    api.post<PurchaseInvoiceSubmission>(`/purchase-invoice-submissions/${id}/extract`, {}).then(r => r.data),
  needsInformation: (id: number, reason: string) =>
    api.post<PurchaseInvoiceSubmission>(`/purchase-invoice-submissions/${id}/needs-information`, { reason }).then(r => r.data),
  reject: (id: number, reason: string) =>
    api.post<PurchaseInvoiceSubmission>(`/purchase-invoice-submissions/${id}/reject`, { reason }).then(r => r.data),
  /** Doplní dimenze vybraným podáním; zvolené typy přepíše, ostatní nechá. */
  bulkDimensions: (ids: number[], dimensions: Record<number, number | null>) =>
    api.post<{ updated: number[]; skipped: number[] }>('/purchase-invoice-submissions/dimensions', { ids, dimensions }).then(r => r.data),
  /** Vyřadí podání z fronty a originál pošle do koše Dokumentů (documents.inbox.delete). */
  remove: (id: number) =>
    api.delete<{ ok: boolean; document_id: number }>(`/purchase-invoice-submissions/${id}`).then(r => r.data),
  /** Firmy, do kterých jde doklad přesunout, a návrh podle odběratele. */
  moveTargets: (id: number) =>
    api.get<SubmissionMoveTargets>(`/purchase-invoice-submissions/${id}/move-targets`).then(r => r.data),
  /** Přesune doklad do příchozích dokladů jiné firmy; ve zdrojové firmě jde originál do koše. */
  move: (id: number, targetSupplierId: number) =>
    api.post<{ target_supplier_id: number; submission_id: number; duplicate: boolean }>(
      `/purchase-invoice-submissions/${id}/move`,
      { target_supplier_id: targetSupplierId },
    ).then(r => r.data),
  previewUrl: (id: number) => fileUrl(`/purchase-invoice-submissions/${id}/preview`),
  downloadUrl: (id: number) => fileUrl(`/purchase-invoice-submissions/${id}/download`),
}
