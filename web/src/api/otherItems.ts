import { api } from './client'
import type { MatchCandidate } from './bank'

export type OtherItemSide = 'receivable' | 'payable'
export type OtherItemStatus = 'draft' | 'posted' | 'reversed' | 'confirmed' | 'cancelled' | 'paid' | 'partial' | 'forecast'

export interface OtherItemPostingLine {
  account_code: string
  amount: number
}

export interface OtherItem {
  id: string | number
  source_kind: string
  source_id: number | string
  side: OtherItemSide
  kind: string
  /** Vlastní druh (migrace 2228); null = vestavěný druh podle `kind`. */
  kind_id?: number | string | null
  kind_name?: string | null
  /** Číslo z řady; koncept ho ještě nemá. */
  document_no?: string | null
  title: string
  partner_id?: number | null
  partner_name: string | null
  issued_on: string | null
  accounting_on?: string | null
  due_on: string | null
  currency: string
  exchange_rate?: number | null
  amount_czk?: number | null
  amount: number
  paid_amount: number
  settled_outside_amount?: number
  remaining_amount: number
  status: OtherItemStatus | string
  certainty: 'confirmed' | 'scheduled' | 'estimate'
  editable: boolean
  document_count: number
  source_url: string | null
  variable_symbol?: string | null
  account_code?: string | null
  counter_account_code?: string | null
  posting_lines?: OtherItemPostingLine[]
  note?: string | null
  journal_entry_id?: number | null
}

export interface OtherItemPayload {
  side: OtherItemSide
  kind: string
  /** Vlastní druh; vynechané = vestavěný druh (AI návrh). */
  kind_id?: number | null
  title: string
  partner_id: number | null
  partner_name: string | null
  issued_on: string
  accounting_on: string
  due_on: string
  currency: string
  exchange_rate: number | null
  amount: number
  variable_symbol: string | null
  account_code: string | null
  counter_account_code: string | null
  posting_lines?: OtherItemPostingLine[]
  note: string | null
}

export interface OtherItemsResult {
  items: OtherItem[]
  sources?: OtherItem[]
  total: number
  page: number
  per_page: number
  totals?: Record<string, unknown>
}

export interface OtherItemAllocation {
  id: number
  bank_transaction_id: number | null
  cash_document_id: number | null
  amount: number
  payment_on: string
  reversed_on: string | null
}

export interface OtherItemAllocationPayload {
  bank_transaction_id?: number
  cash_document_id?: number
  amount: number
}

export interface OtherItemPaymentCandidate {
  source: 'bank' | 'cash'
  id: number
  payment_on: string
  amount: number
  currency: string
  description: string
}

export interface OtherItemRepostPayload {
  account_code?: string
  counter_account_code?: string
  posting_lines?: OtherItemPostingLine[]
  entry_date: string
  reason: string
}

/**
 * Druh ostatní položky. Vestavěný (`builtin_code`, bez názvu) má `id` až po první změně
 * nastavení; vlastní druh má název a `id` vždy.
 */
export interface OtherItemKind {
  id: number | null
  side: OtherItemSide
  builtin: boolean
  builtin_code: string | null
  name: string | null
  own_series: boolean
  is_active: boolean
  /** Prefix vlastní řady (i vypnuté); null = druh vlastní řadu ještě neměl. */
  series_prefix?: string | null
}

export interface OtherItemKindPayload {
  side?: OtherItemSide
  name?: string
  own_series?: boolean
  prefix?: string
  is_active?: boolean
}

export const otherItemKindsApi = {
  list: () => api.get<{ items: OtherItemKind[] }>('/accounting/other-items/kinds').then(r => r.data.items),
  create: (payload: OtherItemKindPayload) => api.post<OtherItemKind>('/accounting/other-items/kinds', payload).then(r => r.data),
  update: (id: number, payload: OtherItemKindPayload) => api.put<OtherItemKind>(`/accounting/other-items/kinds/${id}`, payload).then(r => r.data),
  updateBuiltin: (side: OtherItemSide, code: string, payload: OtherItemKindPayload) =>
    api.put<OtherItemKind>(`/accounting/other-items/kinds/builtin/${side}/${code}`, payload).then(r => r.data),
  remove: (id: number) => api.delete(`/accounting/other-items/kinds/${id}`).then(r => r.data),
}

export const otherItemsApi = {
  list: (params: { side?: OtherItemSide; status?: 'open' | 'all'; kind?: string; kind_id?: number; q?: string; from?: string; to?: string; page?: number; sort_by?: string; sort_dir?: 'asc' | 'desc' }) =>
    api.get<OtherItemsResult>('/accounting/other-items', { params }).then(r => ({
      ...r.data,
      items: r.data.items.map(item => ({
        ...item,
        id: `manual:${item.id}`,
        source_id: Number(item.id),
        source_kind: 'manual',
        certainty: item.status === 'draft' ? 'estimate' as const : 'confirmed' as const,
        editable: item.status === 'draft',
        document_count: item.document_count || 0,
        source_url: `/other-items/${item.id}`,
      })),
    })),
  get: (id: number) => api.get<OtherItem>(`/accounting/other-items/${id}`).then(r => r.data),
  create: (payload: OtherItemPayload) => api.post<OtherItem>('/accounting/other-items', payload).then(r => r.data),
  update: (id: number, payload: OtherItemPayload) => api.put<OtherItem>(`/accounting/other-items/${id}`, payload).then(r => r.data),
  remove: (id: number) => api.delete(`/accounting/other-items/${id}`).then(r => r.data),
  post: (id: number) => api.post<OtherItem>(`/accounting/other-items/${id}/post`).then(r => r.data),
  reverse: (id: number, payload: { reason: string; entry_date?: string }) =>
    api.post<OtherItem>(`/accounting/other-items/${id}/reverse`, payload).then(r => r.data),
  repost: (id: number, payload: OtherItemRepostPayload) =>
    api.post<OtherItem>(`/accounting/other-items/${id}/repost`, payload).then(r => r.data),
  allocations: (id: number) => api.get<{ items: OtherItemAllocation[] }>(`/accounting/other-items/${id}/allocations`).then(r => r.data.items),
  paymentCandidates: (id: number, q: string) =>
    api.get<{ items: OtherItemPaymentCandidate[] }>(`/accounting/other-items/${id}/payment-candidates`, { params: { q, limit: 20 } }).then(r => r.data.items),
  allocate: (id: number, payload: OtherItemAllocationPayload) =>
    api.post<OtherItem>(`/accounting/other-items/${id}/allocations`, payload).then(r => r.data),
  unallocate: (id: number, allocationId: number) =>
    api.delete<OtherItem>(`/accounting/other-items/${id}/allocations/${allocationId}`).then(r => r.data),
  /** Otevřené položky pro dialog „Spárovat" u bankovního pohybu. */
  bankCandidates: (bankTransactionId: number, q = '') =>
    api.get<{ candidates: MatchCandidate[] }>('/accounting/other-items/bank-candidates', {
      params: { bank_transaction_id: bankTransactionId, q: q || undefined },
    }).then(r => r.data.candidates),
  /** Přiřadí pohyb položce; nezaúčtovaný pohyb server zároveň zaúčtuje proti účtu položky. */
  matchBankTransaction: (id: number, bankTransactionId: number) =>
    api.post<OtherItem>(`/accounting/other-items/${id}/match-bank-transaction`, { bank_transaction_id: bankTransactionId })
      .then(r => r.data),
}
