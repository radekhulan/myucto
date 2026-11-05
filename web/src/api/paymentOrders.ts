import { requestSupplierId } from '@/workspace/supplierContext'
import { api } from './client'
import type { PaymentAccountSource } from './purchaseInvoices'
import type { PaymentMethod, PaymentMethodSource } from './invoices'

/** Stav ověření platebního účtu protistrany (CRPDPH / registr plátců). */
export type PaymentAccountVerified = 'verified' | 'not_listed' | 'unreliable' | 'na'

/** Plátcovský (vlastní) bankovní účet, ze kterého se hradí příkaz. */
export interface PayerAccount {
  id: number
  code: string
  label: string | null
  symbol: string | null
  account_number: string | null
  bank_code: string | null
  bank_name: string | null
  iban: string | null
  bic: string | null
  is_default: boolean
  is_active: boolean
}

/** Kandidát na zařazení do platebního příkazu (nezaplacená přijatá faktura). */
export interface PaymentCandidate {
  id: number
  vendor_id: number
  vendor_company_name: string | null
  vendor_dic: string | null
  vendor_invoice_number: string | null
  varsymbol: string | null
  document_kind: string | null
  issue_date: string | null
  due_date: string | null
  currency: string
  currency_symbol: string | null
  amount_to_pay: number
  total_with_vat: number
  account_number: string | null
  bank_code: string | null
  iban: string | null
  bic: string | null
  variable_symbol: string | null
  constant_symbol: string | null
  payment_account_source: PaymentAccountSource | null
  payment_ordered_at: string | null
  /**
   * Forma úhrady (migrace 1128). Defaultně sem chodí jen 'bank_transfer' — jiné hodnoty
   * se objeví jen se zapnutým `include_non_transfer` a do příkazu je zařadit NELZE.
   */
  payment_method: PaymentMethod
  payment_method_source: PaymentMethodSource
  has_account: boolean
  has_pdf: boolean
  can_verify: boolean
  abo_eligible: boolean
  /** Má platný IBAN (mod-97) → lze zařadit do SEPA (pain.001.001.03) exportu. */
  sepa_eligible: boolean
  account_verified: PaymentAccountVerified
  /**
   * Účetní zápis dokladu — otevírá náhled bez odskoku ze stránky.
   * `null` u nezaúčtovaných dokladů a v daňové evidenci, kde deník není.
   */
  journal_entry_id: number | null
}

export interface VerifyAccountResponse {
  account_verified: PaymentAccountVerified
  found: boolean
  unreliable: boolean | null
  accounts: string[]
  dic: string | null
}

/** Stránkovací meta (jednotný kontrakt list endpointů). */
export interface PageMeta {
  total: number
  page: number
  per_page: number
  pages: number
}

/** Zdroj účtu klienta pro vratku (karta klienta). */
export type RefundAccountSource = 'manual' | 'vat_registry' | 'bank_statement'

/**
 * Vratka odběrateli: vystavená faktura nebo dobropis s částkou k vyplacení.
 * Chodí jen se zapnutým vyplácením přeplatků u firmy, jen CZK a ne hotově.
 */
export interface RefundCandidate {
  id: number
  invoice_type: 'invoice' | 'credit_note'
  client_id: number
  client_company_name: string | null
  varsymbol: string | null
  issue_date: string | null
  due_date: string | null
  currency: string
  currency_symbol: string | null
  /** Částka k vyplacení (kladná). */
  amount_to_pay: number
  total_with_vat: number
  account_number: string | null
  bank_code: string | null
  iban: string | null
  bic: string | null
  variable_symbol: string
  payment_account_source: RefundAccountSource | null
  payment_ordered_at: string | null
  payment_method: PaymentMethod
  has_account: boolean
  abo_eligible: boolean
  account_verified: PaymentAccountVerified
}

export interface PaymentOrderCandidatesResponse {
  payer_accounts: PayerAccount[]
  data: PaymentCandidate[]
  meta: PageMeta
  /** Jen se zapnutým vyplácením přeplatků u firmy. */
  refund_candidates?: RefundCandidate[]
  /** Daně firmy finančnímu úřadu (DPH z podaných přiznání, zálohy na daň z příjmů). */
  tax_candidates?: TaxPaymentCandidate[]
  /** Ostatní závazky k úhradě (jen CZK): nejbližší splátka, nebo celý zbytek. */
  other_candidates?: OtherPayablePaymentCandidate[]
}

/**
 * Ostatní závazek k úhradě. Jeden řádek = nejbližší otevřená splátka, která ještě
 * není v příkazu, nebo celý zbytek bez kalendáře. Zařazené splátky chodí s `ordered_at`
 * a `payable: false`. Účet, VS i částku určuje server, do příkazu se posílá jen ID.
 */
export interface OtherPayablePaymentCandidate {
  key: string
  other_item_id: number
  installment_id: number | null
  installment_position: number | null
  installment_count: number | null
  side: 'payable'
  kind: string
  kind_name: string | null
  title: string
  partner_name: string | null
  document_no: string | null
  due_date: string
  amount: number
  currency: 'CZK'
  account_number: string | null
  bank_code: string | null
  iban: string | null
  variable_symbol: string | null
  has_account: boolean
  abo_eligible: boolean
  ordered_at: string | null
  payable: boolean
  reason: 'no_account' | 'already_ordered' | null
}

/** Daň k úhradě FÚ. Účet, VS i částku určuje server, do příkazu se posílá jen ID. */
export interface TaxPaymentCandidate {
  key: string
  kind: 'vat' | 'advance'
  id: number
  label: string
  amount: number
  due_date: string | null
  office_name: string | null
  account_number: string | null
  bank_code: string | null
  iban: string | null
  account: string | null
  variable_symbol: string | null
  ordered_at: string | null
}

/** Položka uloženého platebního příkazu (detail) — kanonický pohled z backendu. */
export interface PaymentOrderItem {
  /** `null` u vratky odběrateli, ta nese `invoice_id`. */
  purchase_invoice_id: number | null
  invoice_id?: number
  tax_submission_id?: number
  tax_advance_schedule_id?: number
  other_item_id?: number
  other_item_installment_id?: number
  payee_name: string | null
  account_number: string | null
  bank_code: string | null
  iban: string | null
  bic: string | null
  amount: number
  currency: string
  variable_symbol: string | null
  constant_symbol: string | null
  specific_symbol: string | null
  message: string | null
  account_verified: PaymentAccountVerified
}

/** Detail platebního příkazu (hlavička + položky) — kanonický pohled `view()`. */
export interface PaymentOrderView {
  id: number
  currency: string
  payment_date: string
  total_amount: number
  item_count: number
  mark_paid: boolean
  note: string | null
  created_at: string
  payer: {
    account_number: string | null
    bank_code: string | null
    iban: string | null
    bic: string | null
    label: string | null
  }
  supplier: {
    company_name: string | null
    abo_client_number: string | null
  }
  items: PaymentOrderItem[]
}

/** Položka v přehledu historie platebních příkazů. */
export interface PaymentOrderListItem {
  id: number
  currency: string
  payment_date: string
  total_amount: number
  item_count: number
  mark_paid: boolean
  note: string | null
  created_at: string
  payer_account_label: string | null
  payer_account_number: string | null
  payer_bank_code: string | null
  payer_iban: string | null
}

export interface CreatePaymentOrderPayload {
  invoice_ids: number[]
  /** Vratky odběratelům (vystavené doklady k vyplacení). */
  refund_invoice_ids?: number[]
  /** DPH z podaných přiznání (tax_submissions.id). */
  tax_submission_ids?: number[]
  /** Zálohy na daň z příjmů (tax_advance_schedules.id). */
  tax_advance_ids?: number[]
  /** Ostatní závazky: ID položky a splátka, kterou seznam nabídl. */
  other_items?: Array<{ id: number; installment_id: number | null }>
  payer_currency_id: number
  payment_date: string
  constant_symbol?: string
  note?: string
  mark_paid?: boolean
}

/** Důvod přeskočení faktury při vytváření příkazu. */
export type PaymentOrderSkipReason =
  | 'not_found'
  | 'currency_mismatch'
  | 'nothing_to_pay'
  | 'no_account'
  | 'refund_disabled'
  | 'cash'
  | 'invalid_account'
  | 'already_ordered'

export interface CreatePaymentOrderResponse {
  order_id: number
  view: PaymentOrderView
  skipped: Array<{ id: number; reason: PaymentOrderSkipReason | string; document?: 'invoice' | 'tax_vat' | 'tax_advance' | 'other_item' }>
  clamped_date: boolean
}

/** Navržený účet klienta pro vratku (seřazeno: ručně, registr DPH, výpis). */
export interface RefundSuggestedAccount {
  id: number
  account_number: string | null
  bank_code: string | null
  iban: string | null
  bic: null
  source: RefundAccountSource
  account_verified: PaymentAccountVerified
}

export interface RefundOrderPrefill {
  invoice: {
    id: number
    invoice_type: 'invoice' | 'credit_note'
    varsymbol: string | null
    client_id: number
    client_company_name: string | null
    currency: string
    due_date: string | null
    payment_ordered_at: string | null
  }
  amount: number
  variable_symbol: string
  accounts: RefundSuggestedAccount[]
  payer_accounts: PayerAccount[]
}

export interface CreateRefundOrderPayload {
  payer_currency_id: number
  payment_date: string
  account_number: string
  bank_code: string
  iban?: string
  save_to_client?: boolean
  note?: string
}

export interface CreateRefundOrderResponse {
  order_id: number
  view: PaymentOrderView
  clamped_date: boolean
  saved_account: Record<string, unknown> | null
}

export type PaymentOrderFormat = 'abo' | 'csv' | 'pdf' | 'sepa'

export const paymentOrdersApi = {
  delete: (id: number) => api.delete(`/purchase-invoices/payment-orders/${id}`),
  archiveAfterBankCancellation: (id: number) => api.post(`/purchase-invoices/payment-orders/${id}/archive`, { bank_cancellation_confirmed: true }),
  /**
   * Kandidáti + plátcovské účty, volitelně filtrované měnou plátce. Stránkovaně (load-more).
   *
   * `includeNonTransfer` vypne default filtr „jen bankovní převod" — inkasní faktury se
   * do příkazu nedávají, ale chybně označenou fakturu musí jít najít a opravit.
   */
  candidates: (currency?: string, page = 1, perPage = 50, includeNonTransfer = false) =>
    api.get<PaymentOrderCandidatesResponse>('/purchase-invoices/payment-orders/candidates', {
      params: {
        page,
        per_page: perPage,
        ...(currency ? { currency } : {}),
        ...(includeNonTransfer ? { include_non_transfer: 1 } : {}),
      },
    }).then(r => r.data),

  create: (payload: CreatePaymentOrderPayload) =>
    api.post<CreatePaymentOrderResponse>('/purchase-invoices/payment-orders', payload).then(r => r.data),

  /** „Jen označit" — zařadí faktury k úhradě bez exportu (volitelně rovnou paid). */
  markOrdered: (payload: { invoice_ids: number[]; refund_invoice_ids?: number[]; mark_paid?: boolean }) =>
    api.post<{ count: number }>('/purchase-invoices/payment-orders/mark', payload).then(r => r.data),

  /** Historie dávek, stránkovaně (load-more). */
  list: (page = 1, perPage = 50) =>
    api.get<{ data: PaymentOrderListItem[]; meta: PageMeta }>('/purchase-invoices/payment-orders', {
      params: { page, per_page: perPage },
    }).then(r => r.data),

  get: (id: number) =>
    api.get<PaymentOrderView>(`/purchase-invoices/payment-orders/${id}`).then(r => r.data),

  /** On-demand kontrola účtu faktury proti zveřejněným účtům plátce DPH (CRPDPH). */
  verifyAccount: (invoiceId: number) =>
    api.get<VerifyAccountResponse>('/purchase-invoices/payment-orders/verify-account', {
      params: { invoice_id: invoiceId },
    }).then(r => r.data),

  /**
   * Stažení vygenerovaného souboru příkazu (ABO/KPC, CSV nebo PDF).
   * Mirror Export.vue: přímá navigace v prohlížeči (session cookie),
   * supplier_id v query param (X-Supplier-Id header se při window.open neposílá).
   */
  downloadUrl: (id: number, format: PaymentOrderFormat): string => {
    const sid = requestSupplierId()
    const params = new URLSearchParams({ format })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/purchase-invoices/payment-orders/${id}/download?${params.toString()}`
  },

  downloadPaymentOrder: (id: number, format: PaymentOrderFormat): void => {
    window.open(paymentOrdersApi.downloadUrl(id, format), '_blank')
  },

  /** Podklady pro „Vrátit peníze" z detailu dokladu k vyplacení. */
  refundPrefill: (invoiceId: number) =>
    api.get<RefundOrderPrefill>(`/invoices/${invoiceId}/refund-order/prefill`).then(r => r.data),

  /** Platební příkaz s jedinou vratkou; stažení a odeslání přes běžné cesty příkazů. */
  createRefundOrder: (invoiceId: number, payload: CreateRefundOrderPayload) =>
    api.post<CreateRefundOrderResponse>(`/invoices/${invoiceId}/refund-order`, payload).then(r => r.data),
}
