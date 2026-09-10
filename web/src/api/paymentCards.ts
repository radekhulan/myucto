import { api } from './client'

export type PaymentCardType = 'debit' | 'credit' | 'prepaid' | 'fuel' | 'other'
export type PaymentCardNetwork = 'visa' | 'mastercard' | 'maestro' | 'amex' | 'other'

export const PAYMENT_CARD_TYPES: PaymentCardType[] = ['debit', 'credit', 'prepaid', 'fuel', 'other']
export const PAYMENT_CARD_NETWORKS: PaymentCardNetwork[] = ['visa', 'mastercard', 'maestro', 'amex', 'other']

/** Platební karta firmy. Aplikace zná jen koncovku (poslední 4 číslice). */
export interface PaymentCard {
  id: number
  label: string
  holder_name: string | null
  /** Zobrazovaný držitel: jméno držitele, jinak zaměstnanec, jinak uživatel. */
  holder: string | null
  last4: string
  card_type: PaymentCardType
  card_network: PaymentCardNetwork | null
  currency_id: number | null
  currency_code: string | null
  account_label: string | null
  account_number: string | null
  employee_id: number | null
  employee_name: string | null
  user_id: number | null
  user_name: string | null
  valid_from: string | null
  valid_to: string | null
  is_active: boolean
  /** false = kartu založil import výpisu z neznámé koncovky; čeká na doplnění. */
  is_verified: boolean
  /** Číslo analytiky mezičlenu ('101'); kód skládá syntetika z nastavení účtování karet. */
  analytic_suffix: string | null
  archived: boolean
  archived_at: string | null
  note: string | null
  created_at: string
  /** Jen v detailu karty: mezičlen karty (analytika, zůstatek = nedoložené platby). */
  clearing?: PaymentCardClearing
}

export interface PaymentCardClearingOption {
  id: number
  account_code: string
  name: string
  card_id: number | null
}

export interface PaymentCardClearing {
  account_code: string | null
  account_id: number | null
  balance: number
  synthetic: string
  enabled: boolean
  options: PaymentCardClearingOption[]
}

export type CardClearingSynthetic = '378' | '261' | '395'

export interface CardClearingSettings {
  configured: boolean
  enabled: boolean
  effective_from: string | null
  clearing_synthetic: CardClearingSynthetic
  writeoff_account_id: number | null
  holder_account_id: number | null
  fx_loss_account_id: number | null
  fx_gain_account_id: number | null
  rounding_loss_account_id: number | null
  rounding_gain_account_id: number | null
  auto_create_cards: boolean
  unmatched_alert_days: number
}

export interface CardClearingAccountOption {
  id: number
  account_code: string
  name: string
  account_type: string
  is_synthetic: boolean
  parent_id: number | null
  non_deductible: boolean
}

export interface CardClearingSettingsResponse {
  configured: boolean
  double_entry: boolean
  settings: CardClearingSettings
  defaults: {
    writeoff_account_code: string
    holder_account_code: string
    fx_loss_account_code: string
    fx_gain_account_code: string
    rounding_loss_account_code: string
    rounding_gain_account_code: string
    effective_from: string | null
  }
  synthetic_options: Array<{ account_code: CardClearingSynthetic; available: boolean; balance: number }>
  account_options: CardClearingAccountOption[]
  unverified_cards: number
}

export type CardWriteOffTarget = 'expense' | 'holder'

/** Stručný popis karty u bankovního pohybu. */
export interface PaymentCardSummary {
  id: number
  label: string
  last4: string
  holder: string | null
  employee_id: number | null
  user_id: number | null
  archived: boolean
}

export interface PaymentCardPayload {
  label: string
  last4: string
  card_type: PaymentCardType
  card_network: PaymentCardNetwork | null
  currency_id: number | null
  holder_name: string | null
  employee_id: number | null
  user_id: number | null
  valid_from: string | null
  valid_to: string | null
  is_active: boolean
  note: string | null
}

export interface PaymentCardHolders {
  employees: Array<{ id: number; name: string }>
  users: Array<{ id: number; name: string }>
}

export interface CardPaymentRow {
  id: number
  statement_id: number
  posted_at: string
  amount: number
  currency: string
  counterparty_name: string | null
  description: string | null
  card_last4: string
  /** Analytika mezičlenu, na které platba čeká na doklad (null = účtováno bez mezičlenu). */
  clearing_account?: string | null
  /** Platba na čerpací stanici: vozidlo držitele karty (reason ambiguous = víc vozidel). */
  vehicle_hint?: CardPaymentVehicleHint | null
}

export interface CardPaymentVehicleHint {
  car_id: number | null
  registration: string | null
  car_name: string | null
  reason: 'ok' | 'ambiguous'
}

export interface CardPaymentGroup {
  key: string
  card: PaymentCardSummary | null
  last4: string
  holder: string | null
  count: number
  totals: Record<string, number>
  transactions: CardPaymentRow[]
}

export interface UnmatchedCardPayments {
  from: string
  to: string
  count: number
  truncated: boolean
  groups: CardPaymentGroup[]
}

export interface ReceiptUploadResult {
  purchase_invoice_id: number
  duplicate: boolean
  marked_as_card: boolean
  bank_transaction_id: number
}

export interface CardRematchResult {
  bank_transaction_id: number
  result: { status: string; reason?: string; purchase_invoice_id?: number; requires_review?: boolean }
}

export const paymentCardsApi = {
  list: (includeArchived = false) =>
    api.get<{ cards: PaymentCard[] }>('/payment-cards', { params: includeArchived ? { include_archived: 1 } : {} })
      .then(r => r.data.cards),
  get: (id: number) => api.get<PaymentCard>(`/payment-cards/${id}`).then(r => r.data),
  holders: () => api.get<PaymentCardHolders>('/payment-cards/holders').then(r => r.data),
  create: (payload: PaymentCardPayload) =>
    api.post<{ card: PaymentCard; last4_truncated: boolean }>('/payment-cards', payload).then(r => r.data),
  update: (id: number, payload: PaymentCardPayload) =>
    api.put<{ card: PaymentCard; last4_truncated: boolean }>(`/payment-cards/${id}`, payload).then(r => r.data),
  archive: (id: number) => api.post<{ card: PaymentCard }>(`/payment-cards/${id}/archive`).then(r => r.data.card),
  restore: (id: number) => api.post<{ card: PaymentCard }>(`/payment-cards/${id}/restore`).then(r => r.data.card),

  unmatchedPayments: (params: { from?: string; to?: string } = {}) =>
    api.get<UnmatchedCardPayments>('/payment-cards/unmatched-payments', { params }).then(r => r.data),
  uploadReceipt: (transactionId: number, file: File) => {
    const fd = new FormData()
    fd.append('pdf', file, file.name)
    return api.post<ReceiptUploadResult>(`/payment-cards/unmatched-payments/${transactionId}/receipt`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
      timeout: 120000,
    }).then(r => r.data)
  },
  rematch: (transactionId: number) =>
    api.post<CardRematchResult>(`/payment-cards/unmatched-payments/${transactionId}/rematch`).then(r => r.data),

  // Účtování plateb kartou přes mezičlen (nastavení, analytika karty, uzavření bez dokladu).
  clearingSettings: () => api.get<CardClearingSettingsResponse>('/payment-cards/settings').then(r => r.data),
  saveClearingSettings: (payload: Partial<CardClearingSettings> & { confirm?: boolean }) =>
    api.put<CardClearingSettingsResponse>('/payment-cards/settings', payload).then(r => r.data),
  setAnalytic: (id: number, accountCode: string | null, confirm = false) =>
    api.put<{ card: PaymentCard; clearing: PaymentCardClearing }>(`/payment-cards/${id}/analytic`, { account_code: accountCode, confirm })
      .then(r => r.data),
  verify: (id: number) => api.post<{ card: PaymentCard }>(`/payment-cards/${id}/verify`).then(r => r.data.card),
  writeOff: (transactionId: number, target: CardWriteOffTarget, accountId: number | null = null) =>
    api.post<{ entry_id: number; account_code: string }>(
      `/payment-cards/unmatched-payments/${transactionId}/write-off`, { target, account_id: accountId },
    ).then(r => r.data),
  cancelWriteOff: (transactionId: number) =>
    api.delete<{ reversal_id: number | null }>(`/payment-cards/unmatched-payments/${transactionId}/write-off`).then(r => r.data),
}
