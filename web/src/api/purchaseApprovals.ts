import axios from 'axios'
import { api } from './client'
import { apiErrorCode, apiErrorMessage } from './errors'
import { dimensionValuePlainLabel } from '@/utils/dimensionValueLabel'

/** Souhrnný stav schvalování dokladu (sloupec `purchase_invoices.approval_status`). */
export type PurchaseApprovalStatus = 'none' | 'pending' | 'approved' | 'rejected'
/** Stav jednoho kola schvalování u jednoho schvalovatele. */
export type PurchaseApprovalRoundStatus = 'pending' | 'approved' | 'rejected' | 'cancelled'

export interface PurchaseApprovalDimensionValue {
  id: number
  code: string
  name: string
  type_name?: string
}

export interface PurchaseApprovalUser {
  id: number
  name: string
  email: string | null
}

export interface PurchaseApprovalInvoiceBrief {
  id: number
  document_number: string | null
  supplier_name: string | null
  issue_date: string | null
  due_date: string | null
  total_without_vat: number
  total_with_vat: number
  currency: string
  has_pdf: boolean
  status: string
  approval_status: PurchaseApprovalStatus
}

export interface PurchaseApprovalRow {
  id: number
  purchase_invoice_id: number
  round: number
  status: PurchaseApprovalRoundStatus
  amount_czk: number
  dimension_value: PurchaseApprovalDimensionValue
  approver: PurchaseApprovalUser
  requested_at: string | null
  decided_at: string | null
  decided_via: 'app' | 'email' | null
  comment: string | null
  invoice: PurchaseApprovalInvoiceBrief
}

export interface PurchaseApprovalDecideResult extends PurchaseApprovalRow {
  invoice_approval_status: PurchaseApprovalStatus
}

export interface PurchaseApprovalRequirement {
  dimension_value: PurchaseApprovalDimensionValue
  approver: PurchaseApprovalUser | null
  amount_czk: number
}

export interface PurchaseInvoiceApprovals {
  data: PurchaseApprovalRow[]
  required: boolean
  requirements: PurchaseApprovalRequirement[]
}

export interface PurchaseApprovalListMeta {
  total: number
  page: number
  pages: number
}

export interface PurchaseApprovalListParams {
  status: 'pending' | 'decided'
  scope?: 'mine' | 'all'
  page?: number
}

/** Tělo odpovědi přechodu draft → received u dokladu, který vyžaduje schválení. */
export interface ApprovalRequestedFlag {
  approval_requested?: boolean
  approvals?: PurchaseApprovalRow[]
}

export type PublicPurchaseApprovalState = 'pending' | 'approved' | 'rejected' | 'cancelled'

export interface PublicPurchaseApprovalItem {
  description: string
  quantity: number
  unit: string
  unit_price: number
  total_without_vat: number
}

export interface PublicPurchaseApproval {
  status: PublicPurchaseApprovalState
  round: number
  dimension_value: PurchaseApprovalDimensionValue | null
  approver_name: string | null
  company: { name: string; logo_url: string | null }
  invoice: {
    document_number: string | null
    supplier_name: string | null
    supplier_ico: string | null
    issue_date: string | null
    tax_date: string | null
    due_date: string | null
    currency: string
    total_without_vat: number
    total_vat: number
    total_with_vat: number
    items: PublicPurchaseApprovalItem[]
    has_pdf: boolean
  }
  decided_at: string | null
  comment: string | null
  expired: boolean
}

export interface PurchaseApprovalDecidePayload {
  decision: 'approve' | 'reject'
  comment?: string | null
}

export const purchaseApprovalsApi = {
  list: (params: PurchaseApprovalListParams) =>
    api.get<{ data: PurchaseApprovalRow[]; meta: PurchaseApprovalListMeta }>('/purchase-invoice-approvals', { params })
      .then(r => r.data),
  count: () =>
    api.get<{ pending: number }>('/purchase-invoice-approvals/count').then(r => r.data.pending ?? 0),
  decide: (id: number, payload: PurchaseApprovalDecidePayload) =>
    api.post<PurchaseApprovalDecideResult>(`/purchase-invoice-approvals/${id}/decide`, payload).then(r => r.data),
  remind: (id: number) =>
    api.post<unknown>(`/purchase-invoice-approvals/${id}/remind`).then(r => r.data),

  forInvoice: (invoiceId: number) =>
    api.get<PurchaseInvoiceApprovals>(`/purchase-invoices/${invoiceId}/approvals`).then(r => r.data),
  request: (invoiceId: number) =>
    api.post<PurchaseInvoiceApprovals>(`/purchase-invoices/${invoiceId}/approvals/request`).then(r => r.data),
  cancel: (invoiceId: number) =>
    api.post<unknown>(`/purchase-invoices/${invoiceId}/approvals/cancel`).then(r => r.data),
}

// Veřejné schvalování z e-mailu: samostatný klient bez přesměrování na /login.
const publicApi = axios.create({
  baseURL: '/api/public/purchase-approval',
  withCredentials: false,
  headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
})
publicApi.interceptors.request.use((config) => {
  config.headers.set('Accept-Language', localStorage.getItem('locale') || 'cs')
  return config
})

export const publicPurchaseApprovalApi = {
  get: (token: string) => publicApi.get<PublicPurchaseApproval>(`/${token}`).then(r => r.data),
  decide: (token: string, payload: PurchaseApprovalDecidePayload) =>
    publicApi.post<PublicPurchaseApproval>(`/${token}/decide`, payload).then(r => r.data),
  pdfUrl: (token: string) => `/api/public/purchase-approval/${token}/pdf`,
}

/** Chyba 422 `approval_no_approver`: středisko nemá odpovědnou osobu, doklad nejde odeslat ke schválení. */
export function isApprovalNoApprover(err: unknown): boolean {
  return apiErrorCode(err) === 'approval_no_approver'
}

/**
 * Název střediska z chyby `approval_no_approver`. Smlouva tvar těla neurčuje,
 * proto se zkouší několik umístění; bez názvu zůstane obecná hláška serveru.
 */
export function approvalNoApproverDimension(err: any): string {
  const e = err?.response?.data?.error ?? {}
  if (Array.isArray(e.dimension_values) && e.dimension_values.length > 0) {
    return e.dimension_values
      .map((v: { code?: string; name?: string | null; semantic_key?: string | null }) => dimensionValuePlainLabel({ code: v.code ?? '', name: v.name ?? null, semantic_key: v.semantic_key }))
      .filter(Boolean)
      .join(', ')
  }
  const value = e.dimension_value ?? e.dimension ?? null
  const name = (typeof value === 'object' && value ? (value.name ?? value.code) : null)
    ?? e.dimension_value_name ?? e.dimension_name ?? e.name
  return typeof name === 'string' ? name : ''
}

export function approvalErrorMessage(err: unknown, t: (key: string, params?: Record<string, unknown>) => string): string {
  if (isApprovalNoApprover(err)) {
    const name = approvalNoApproverDimension(err)
    return name
      ? t('purchase_approval.no_approver', { name })
      : t('purchase_approval.no_approver_generic')
  }
  return apiErrorMessage(err)
}
