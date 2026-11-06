import { api } from './client'

export interface SupplierListItem {
  id: number
  company_name: string
  display_name: string | null
  ic: string | null
  dic: string | null
  is_vat_payer: boolean
  email: string
  accounting_mode?: 'tax_evidence' | 'double_entry'
  country_iso: string
  clients_count: number
  invoices_count: number
}

export type SupplierDirectorySort = 'urgency' | 'name' | 'last_invoice' | 'last_activity' | 'overdue'

export interface SupplierDirectoryParams {
  sort?: SupplierDirectorySort
  dir?: 'asc' | 'desc'
  q?: string
  vat?: 'payer' | 'non_payer'
  mode?: 'double_entry' | 'tax_evidence'
  urgency?: 'with' | 'without'
}

/** Urgence firmy (BE `SupplierDirectory`): vážený součet věcí, které čekají na účetní. */
export interface SupplierUrgencyInfo {
  score: number
  level: 'high' | 'medium' | 'low' | 'none'
  vat: { status: 'overdue' | 'due_soon' | 'ok'; deadline: string; days: number; period: string } | null
  overdue_receivables: number
  overdue_payables: number
  unmatched_bank_transactions: number
  purchase_drafts: number
}

export interface SupplierDirectoryItem extends SupplierListItem {
  last_invoice_date: string | null
  last_activity_at: string | null
  urgency: SupplierUrgencyInfo
}

export interface Supplier {
  id: number
  company_name: string
  display_name: string | null
  street: string
  city: string
  zip: string
  country_id: number
  country_iso: string
  ic: string | null
  dic: string | null
  is_vat_payer: boolean
  email: string
  phone: string | null
  web: string | null
  tagline: string | null
  commercial_register: string | null
  default_currency_id: number
  default_currency: string
  default_vat_rate_id: number
  default_payment_due_days: number
  default_payment_due_unit: 'days' | 'month'
  default_hourly_rate: number
}

export interface SupplierCreatePayload {
  company_name: string
  display_name?: string
  street: string
  city: string
  zip: string
  country_iso2?: string
  ic?: string
  dic?: string
  is_vat_payer?: boolean
  email: string
  phone?: string
  web?: string
  tagline?: string
  commercial_register?: string
  taxpayer_type?: 'fo' | 'po'
  vat_period?: 'monthly' | 'quarterly'
  default_payment_due_days?: number
  default_payment_due_unit?: 'days' | 'month'
  default_hourly_rate?: number
  bank_account?: {
    currency: string
    account_number?: string
    bank_code?: string
    bank_name?: string
    iban?: string
    bic?: string
  }
  carry_over?: SupplierCarryOverRequest
}

/** Co lze při zakládání firmy převzít ze stávajících firem (BE `SupplierCarryOver`). Bez tajemství. */
export interface SupplierCarryOverPreview {
  ai: {
    available: boolean
    reason: 'different' | 'not_available' | null
    provider: string | null
    companies_count: number
    companies: string[]
  }
  certificates: Array<{
    id: number
    label: string
    subject: string
    valid_to: string
    companies_count: number
  }>
}

export interface SupplierCarryOverRequest {
  ai: boolean
  certificate_ids: number[]
  /** Ověření jako při sdílení certifikátu v trezoru; nutné jen s certifikátem. */
  step_up?: { password?: string; totp_code?: string; step_up_token?: string }
}

export type SupplierCarryOverError =
  | 'not_available' | 'different' | 'forbidden' | 'eu_residency' | 'not_owned' | 'not_shared_everywhere' | 'failed'

export interface SupplierCarryOverResult {
  ai?: { ok: boolean; provider?: string; error?: SupplierCarryOverError }
  certificates: Array<{ id: number; ok: boolean; error?: SupplierCarryOverError }>
}

export const suppliersApi = {
  list: () => api.get<SupplierListItem[]>('/suppliers').then(r => r.data),
  directory: (params: SupplierDirectoryParams) =>
    api.get<SupplierDirectoryItem[]>('/suppliers/directory', { params }).then(r => r.data),
  carryOverPreview: () =>
    api.get<SupplierCarryOverPreview>('/suppliers/carry-over').then(r => r.data),
  get: (id: number) => api.get<Supplier>(`/suppliers/${id}`).then(r => r.data),
  create: (payload: SupplierCreatePayload) =>
    api.post<{ id: number; carry_over_result?: SupplierCarryOverResult }>('/suppliers', payload).then(r => r.data),
  update: (id: number, payload: Partial<Supplier>) =>
    api.put<Supplier>(`/suppliers/${id}`, payload).then(r => r.data),
  delete: (id: number) =>
    api.delete<{ deleted: true }>(`/suppliers/${id}`).then(r => r.data),
}
