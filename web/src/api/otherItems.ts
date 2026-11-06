import { api } from './client'
import type { MatchCandidate } from './bank'

export type OtherItemSide = 'receivable' | 'payable'
export type OtherItemStatus = 'draft' | 'posted' | 'reversed' | 'confirmed' | 'cancelled' | 'paid' | 'partial' | 'forecast'

export interface OtherItemPostingLine {
  account_code: string
  amount: number
}

/** Účetní události s profilem druhu a vlastní kontací položky (migrace 2238). */
export type OtherItemPostingEvent = 'origin' | 'payment_bank' | 'payment_cash' | 'interest' | 'fee'
export type OtherItemPostingSource = 'item' | 'kind' | 'default'
export type OtherItemPaymentEvent = 'payment_bank' | 'payment_cash'
/** Úrok a poplatek rozdělené splátky (migrace 2241); účtují se s úhradou do nákladů nebo výnosů. */
export type OtherItemComponentEvent = 'interest' | 'fee'
/** Události s vlastní kontací položky: úhrady a složky splátky. */
export type OtherItemOverrideEvent = OtherItemPaymentEvent | OtherItemComponentEvent

export interface OtherItemDimensionRef {
  type_id: number
  value_id: number
}

/** Kontace jedné události; null na straně položky = účet položky, u úhrady na peněžní straně banka/pokladna. */
export interface OtherItemPostingRuleInput {
  debit_account_code: string | null
  credit_account_code: string | null
  description_template: string | null
  dimensions?: OtherItemDimensionRef[]
  is_active?: boolean
}

export interface OtherItemPostingRule extends OtherItemPostingRuleInput {
  id: number
  event: OtherItemPostingEvent
  kind_id?: number
  updated_at?: string | null
}

export type OtherItemPostingProfiles = Record<OtherItemPostingEvent, OtherItemPostingRule | null>

export interface OtherItemEffectiveLine {
  account_code: string
  side: 'debit' | 'credit'
  amount: number
}

/** Účinná kontace události s původem (položka, druh, výchozí). */
export interface OtherItemEffectivePosting {
  event: OtherItemPostingEvent
  source: OtherItemPostingSource
  account_code?: string
  account_source?: OtherItemPostingSource
  counter_source?: 'item' | 'kind' | null
  posting_lines?: OtherItemPostingLine[]
  lines?: OtherItemEffectiveLine[]
  debit_account_code?: string
  credit_account_code?: string
  money_account_code?: string
  description: string | null
  description_template: string | null
  kind_id: number | null
  profile_id: number | null
}

export type OtherItemPostingOverridesInput = Partial<Record<OtherItemOverrideEvent, OtherItemPostingRuleInput | null>>

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
  /** Kolik zbývá zaplatit; u rozdělených splátek i úrok a poplatek (jen detail). */
  remaining_payment?: number
  /** Kalendář má splátku rozdělenou na jistinu, úrok a poplatek (jen detail). */
  has_split_installments?: boolean
  /** Vydaná faktura, kterou zápis pohledávky účtuje (jen detail). */
  linked_invoice?: { id: number; varsymbol: string | null; status: string } | null
  status: OtherItemStatus | string
  certainty: 'confirmed' | 'scheduled' | 'estimate'
  editable: boolean
  document_count: number
  source_url: string | null
  variable_symbol?: string | null
  /** Účet protistrany: český `předčíslí-číslo` s kódem banky, nebo IBAN bez kódu banky. */
  counterparty_account?: string | null
  counterparty_bank_code?: string | null
  account_code?: string | null
  counter_account_code?: string | null
  posting_lines?: OtherItemPostingLine[]
  note?: string | null
  journal_entry_id?: number | null
  /** Vlastní kontace úhrad položky (podvojné účetnictví). */
  posting_overrides?: Partial<Record<OtherItemOverrideEvent, OtherItemPostingRule>>
  /** Účinná kontace vzniku a úhrad; prázdný objekt v daňové evidenci. */
  posting_rules?: Partial<Record<OtherItemPostingEvent, OtherItemEffectivePosting>>
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
  /** `předčíslí-číslo/kód banky` nebo IBAN; server ho ověří a uloží kanonicky. */
  counterparty_account?: string | null
  counterparty_bank_code?: string | null
  account_code: string | null
  counter_account_code: string | null
  posting_lines?: OtherItemPostingLine[]
  note: string | null
  /** Vlastní kontace úhrad; vynechané = beze změny, null u události = zděděná kontace. */
  posting_overrides?: OtherItemPostingOverridesInput
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
  /** Splátka, kterou úhrada hradí; přebytek pokrývá další splátky podle pořadí. */
  installment_id?: number | null
  bank_transaction_id: number | null
  cash_document_id: number | null
  /** Jistina, o kterou úhrada snižuje saldo (u nerozdělené splátky celá úhrada). */
  amount: number
  interest_amount?: number
  fee_amount?: number
  /** Peněžní částka úhrady: jistina + úrok + poplatek. */
  total_amount?: number
  payment_on: string
  /** Kód automatického přiřazení (OtherItemAutoMatchPolicy, pravidlo banky); null = ručně. */
  auto_match_reason?: OtherItemAutoMatchReason | null
  reversed_on: string | null
}

export type OtherItemAutoMatchReason = 'other_item_vs_account' | 'other_item_vs_installment' | 'other_item_vs_remaining'
  | 'other_item_account_installment' | 'bank_rule'

export interface OtherItemAllocationPayload {
  bank_transaction_id?: number
  cash_document_id?: number
  amount: number
}

/** Důvod shody platby s položkou (OtherItemPaymentCandidateRanker). */
export type OtherItemMatchReason = 'variable_symbol' | 'counterparty_account' | 'installment_amount' | 'installment_due' | 'remaining_amount'

export interface OtherItemMatchInstallment {
  id: number
  position: number
  due_on: string
  remaining_amount: number
}

export interface OtherItemPaymentCandidate {
  source: 'bank' | 'cash'
  id: number
  payment_on: string
  amount: number
  currency: string
  description: string
  /** 0 až 100; řazení kandidátů od nejsilnější shody. */
  match_score?: number
  match_reasons?: OtherItemMatchReason[]
  installment?: OtherItemMatchInstallment | null
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
  /** Účetní profily druhu (jen podvojné účetnictví). */
  posting_profiles?: OtherItemPostingProfiles
  /** Nezávazný návrh kontace vzniku vestavěného druhu. */
  posting_suggestion?: { debit_account_code: string; credit_account_code: string; available: boolean } | null
  /** Výchozí účty úroku a poplatku rozdělené splátky, když je neurčí profil ani položka. */
  posting_component_defaults?: Record<OtherItemComponentEvent, string>
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
  /** Uloží profily druhu po událostech; null u události profil smaže. */
  savePostingProfiles: (kind: Pick<OtherItemKind, 'id' | 'builtin' | 'builtin_code' | 'side'>,
    profiles: Partial<Record<OtherItemPostingEvent, OtherItemPostingRuleInput | null>>) =>
    api.put<{ kind_id: number; profiles: OtherItemPostingProfiles }>(kind.builtin || kind.id === null
      ? `/accounting/other-items/kinds/builtin/${kind.side}/${kind.builtin_code}/posting-profiles`
      : `/accounting/other-items/kinds/${kind.id}/posting-profiles`, profiles).then(r => r.data),
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
  /** Účet protistrany potvrzeného či zaúčtovaného dokladu (`číslo/kód` nebo IBAN, prázdné = smazat). */
  updateCounterpartyAccount: (id: number, counterpartyAccount: string | null) =>
    api.put<OtherItem>(`/accounting/other-items/${id}/counterparty-account`, { counterparty_account: counterpartyAccount }).then(r => r.data),
  remove: (id: number) => api.delete(`/accounting/other-items/${id}`).then(r => r.data),
  post: (id: number) => api.post<OtherItem>(`/accounting/other-items/${id}/post`).then(r => r.data),
  reverse: (id: number, payload: { reason: string; entry_date?: string }) =>
    api.post<OtherItem>(`/accounting/other-items/${id}/reverse`, payload).then(r => r.data),
  /** Vlastní kontace úhrad potvrzené nebo zaúčtované položky (koncept ji nese v úpravě). */
  setPostingOverrides: (id: number, overrides: OtherItemPostingOverridesInput) =>
    api.put<OtherItem>(`/accounting/other-items/${id}/posting-overrides`, { posting_overrides: overrides }).then(r => r.data),
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
