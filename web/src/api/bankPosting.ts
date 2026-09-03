import { api } from './client'
import { apiErrorMessage } from './errors'
import type { BankTransaction, BankAccountOption } from './bank'

export type RuleDirection = 'incoming' | 'outgoing'
export type RuleMode = 'suggest' | 'auto'
export type SuggestionStatus = 'pending' | 'approved' | 'rejected' | 'auto_posted' | 'superseded' | 'needs_input' | 'blocked'
export type SuggestionSource = 'rule' | 'learned' | 'payment_match' | 'transfer' | 'detector' | 'schedule' | 'knn' | 'llm'

export interface BankPostingRule {
  id: number
  name: string
  is_active: boolean
  direction: RuleDirection
  counterparty_account: string | null
  counterparty_bank: string | null
  variable_symbol: string | null
  message_contains: string | null
  amount_min: number | null
  amount_max: number | null
  priority: number
  operation_type: string | null
  system_template_key: string | null
  auto_amount_cap: number | null
  applies_currency: string
  counterparty_prefix: string | null
  approved_streak: number
  promotion_candidate: boolean
  debit_account_code: string
  credit_account_code: string
  description: string | null
  mode: RuleMode
  hit_count: number
  rejected_streak: number
  last_hit_at: string | null
  created_at: string
  updated_at: string
  created_by_name?: string | null   // jen list endpoint (JOIN users)
}

export type BankPostingRulePayload = Omit<BankPostingRule,
  'id' | 'hit_count' | 'rejected_streak' | 'approved_streak' | 'promotion_candidate' | 'last_hit_at' | 'created_at' | 'updated_at' | 'mode' | 'created_by_name' | 'system_template_key'>
  & { mode?: RuleMode }   // create: BE vynutí 'suggest'; update změnu režimu ignoruje klient

export interface PostingSuggestion {
  id: number
  source: SuggestionSource
  transaction: {
    id: number
    posted_at: string
    amount: number
    currency: string
    counterparty_account: string | null
    counterparty_bank: string | null
    counterparty_name: string | null
    description: string | null
    variable_symbol: string | null
    constant_symbol: string | null
    specific_symbol: string | null
    statement_id: number
  }
  rule_id: number | null
  rule_name: string | null
  debit_account_code: string
  credit_account_code: string
  amount: number
  status: SuggestionStatus
  note: string | null
  journal_entry_id: number | null
  document_no: string | null
  period_closed: boolean
  confidence: number | null
  detector: string | null
  operation_type: string | null
  tax_advance_schedule_id?: number | null
  created_at: string
  correction: {
    created_at: string
    suggested_debit: string | null
    suggested_credit: string | null
    final_debit: string | null
    final_credit: string | null
  } | null
}

export type UnpostedBankTransaction = BankTransaction & {
  period_closed: boolean
  /** Náš zdrojový účet výpisu (jen scope='all' — napříč účty, viz záložka „Všechny pohyby"). */
  account_number: string
  bank_code: string | null
  account_label: string | null
}

export interface RuleHistory {
  events: Array<{ id: number; event_type: string; reason: string | null; created_by_name: string | null; created_at: string }>
  corrections: Array<{ id: number; event_type: string; suggested: string | null; final: string | null; amount: number | null; created_by_name: string | null; created_at: string }>
  stats: { hit_count: number; approved_streak: number; rejected_streak: number; override_count: number; success_rate: number }
  total: number
  page: number
  per_page: number
}

export interface RuleDryRunResult {
  matched_count: number
  already_posted_count: number
  shadowed_by_own_transfer: boolean
  sample: Array<{
    id: number
    posted_at: string
    amount: number
    description: string | null
    already_posted: boolean
  }>
}

export interface PostResult {
  journal_entry_id: number
  document_no: string
  rule_id?: number
  similar?: { count: number; period_months: number; last_seen: string | null }
}

export interface AiManualPostingSuggestion {
  suggestion_id: number
  debit_account_code: string
  credit_account_code: string
  reasoning: string
  confidence: number
}

export const bankPostingApi = {
  // Buď dvojice MD/D (obě strany na částku pohybu), nebo `lines[]` s vlastními částkami —
  // to druhé je nutné tam, kde se částka řádku liší od částky pohybu (prodej cenných papírů,
  // kurzový rozdíl, rozúčtování na víc účtů).
  postTransaction: (txId: number, p: {
    debit_account_code?: string
    credit_account_code?: string
    lines?: Array<{ account_code: string; side: 'debit' | 'credit'; amount: number }>
    description?: string
    create_rule?: BankPostingRulePayload & { backfill_suggestions?: boolean }
  }) => api.post<PostResult>(`/bank-transactions/${txId}/post`, p).then(r => r.data),
  unpost: (txId: number) =>
    api.post<{ reversed: true; reversal_entry_id: number }>(`/bank-transactions/${txId}/unpost`, {})
      .then(r => r.data),

  aiSuggest: (txId: number, query: string) =>
    api.post<AiManualPostingSuggestion>(`/bank-transactions/${txId}/ai-suggest`, { query })
      .then(r => r.data),
  aiAvailability: () =>
    api.get<{ available: boolean }>('/bank-ai-suggestion-availability').then(r => r.data),

  listSuggestions: (params: { status?: SuggestionStatus; account?: string; page?: number; per_page?: number } = {}) =>
    api.get<{ items: PostingSuggestion[]; total: number; page: number; per_page: number }>(
      '/accounting/bank-posting-suggestions', { params }).then(r => r.data),
  suggestionsCount: () =>
    api.get<{ pending: number }>('/accounting/bank-posting-suggestions/count')
      .then(r => r.data.pending),
  // scope='all' → záložka „Všechny pohyby" (i zaúčtované, napříč účty a roky).
  listUnposted: (params: {
    page?: number; per_page?: number; year?: number; q?: string; scope?: 'unposted' | 'all'; account?: string
  } = {}) =>
    api.get<{
      items: UnpostedBankTransaction[]; total: number; page: number; per_page: number
      scope: 'unposted' | 'all'; years: number[]; accounts: BankAccountOption[]
    }>('/accounting/bank-posting-unposted', { params }).then(r => r.data),
  unpostedCount: () =>
    api.get<{ unposted: number }>('/accounting/bank-posting-unposted/count')
      .then(r => r.data.unposted),
  approveSuggestion: (id: number, overrides?: { debit_account_code?: string; credit_account_code?: string }) =>
    api.post<{ journal_entry_id: number; document_no: string }>(
      `/accounting/bank-posting-suggestions/${id}/approve`, overrides ?? {}).then(r => r.data),
  rejectSuggestion: (id: number, note?: string) =>
    api.post<{ rejected: true; rule_disabled?: boolean }>(
      `/accounting/bank-posting-suggestions/${id}/reject`, note ? { note } : {}).then(r => r.data),
  bulkApprove: (ids: number[]) =>
    api.post<{ approved: number; failed: Array<{ id: number; code: string }> }>(
      '/accounting/bank-posting-suggestions/bulk-approve', { ids }).then(r => r.data),

  listRules: (params: { direction?: RuleDirection; active?: boolean; page?: number; per_page?: number } = {}) =>
    api.get<{ items: BankPostingRule[]; total: number; page: number; per_page: number }>('/accounting/bank-posting-rules', { params }).then(r => r.data),
  createRule: (p: BankPostingRulePayload & { backfill_suggestions?: boolean }) =>
    api.post<{ rule: BankPostingRule; backfilled?: number }>('/accounting/bank-posting-rules', p)
      .then(r => r.data),
  updateRule: (id: number, p: Partial<BankPostingRulePayload> & { mode?: RuleMode; is_active?: boolean; backfill_suggestions?: boolean }) =>
    api.put<BankPostingRule & { backfilled?: number }>(`/accounting/bank-posting-rules/${id}`, p).then(r => r.data),
  deleteRule: (id: number) =>
    api.delete(`/accounting/bank-posting-rules/${id}`).then(() => undefined),
  dryRunRule: (p: BankPostingRulePayload) =>
    api.post<RuleDryRunResult>('/accounting/bank-posting-rules/dry-run', p).then(r => r.data),
  promoteRule: (id: number) =>
    api.post<{ rule: BankPostingRule }>(`/accounting/bank-posting-rules/${id}/promote`, {}).then(r => r.data.rule),
  demoteRule: (id: number) =>
    api.post<{ rule: BankPostingRule }>(`/accounting/bank-posting-rules/${id}/demote`, {}).then(r => r.data.rule),
  backfillRule: (id: number) =>
    api.post<{ backfilled: number }>(`/accounting/bank-posting-rules/${id}/backfill`, {}).then(r => r.data),
  ruleHistory: (id: number, page = 1, perPage = 25) =>
    api.get<RuleHistory>(`/accounting/bank-posting-rules/${id}/history`, { params: { page, per_page: perPage } }).then(r => r.data),

  // Vlastní bankovní účty firmy — metadata pro kontaci (druh, label, analytika 221.xxx).
  listAccounts: () =>
    api.get<{ accounts: SupplierBankAccount[] }>('/accounting/bank-accounts').then(r => r.data.accounts),
  updateAccount: (id: number, patch: Partial<Pick<SupplierBankAccount, 'kind' | 'label' | 'analytic_suffix' | 'is_active'>>) =>
    api.patch<SupplierBankAccount>(`/accounting/bank-accounts/${id}`, patch).then(r => r.data),
}

// ── Vlastní bankovní účty (kontace 221.xxx) ─────────────────────────────────
export type BankAccountKind = 'current' | 'savings' | 'term_deposit'

export interface SupplierBankAccount {
  id: number
  label: string | null
  account_number: string | null
  bank_code: string | null
  iban: string | null
  currency: string | null
  kind: BankAccountKind
  analytic_suffix: string | null
  is_active: boolean | number
  source: string | null
  currency_id: number | null
}

const ERROR_KEYS: Record<string, string> = {
  period_closed:          'bank.posting.err_period_closed',
  period_not_open:        'bank.posting.err_period_closed',
  // „Období neexistuje" NENÍ „období je uzavřené": náprava je opačná (založit,
  // ne znovuotevřít) a uživatel podle staré hlášky hledal uzavřený rok, který
  // v seznamu nebyl. Vlastní klíč s vlastní radou, kam jít.
  no_accounting_period:   'bank.posting.err_no_accounting_period',
  period_missing:         'bank.posting.err_no_accounting_period',
  already_posted:         'bank.posting.err_already_posted',
  account_not_found:      'bank.posting.err_account_not_found',
  unknown_account:        'bank.posting.err_account_not_found',
  foreign_currency:       'bank.posting.err_foreign_currency',
  transaction_ignored:    'bank.posting.err_ignored',
  // Dva různé důvody, dva různé kódy: dokud sdílely jeden, hlásila se u
  // špatné bankovní strany hláška o saldokontu a uživatel neměl co opravit.
  rule_bank_side_required: 'bank.posting.err_rule_bank_side',
  rule_saldo_forbidden:   'bank.posting.err_rule_account_forbidden',
  rule_account_forbidden: 'bank.posting.err_rule_account_forbidden',
  fx_rule_account_forbidden: 'bank.posting.err_fx_rule_account_forbidden',
  rule_criteria_missing:  'bank.posting.err_rule_criteria',
  rule_auto_requirements: 'bank.posting.err_rule_auto_requirements',
  suggestion_not_pending: 'bank.posting.err_suggestion_gone',
  suggestion_replaced:    'bank.posting.err_suggestion_replaced',
  posted_transaction_cannot_be_ignored: 'bank.posting.err_posted_cannot_ignore',
  cross_currency_manual_only: 'accounting.closing.errors.cross_currency_manual_only',
  duplicate_suspect: 'automation.reason.duplicate_suspect',
  override_not_allowed: 'bank.posting.err_override_not_allowed',
  amount_band_required: 'bank.posting.err_rule_auto_requirements',
  rule_not_ready: 'bank.posting.err_rule_auto_requirements',
  rule_promotion_required: 'bank.posting.err_rule_auto_requirements',
  rule_inactive: 'bank.posting.err_rule_inactive',
  refund_target_not_credit_note: 'bank.posting.err_refund_not_credit_note',
}

/** Lokalizace holého BE kódu (např. failed řádky bulk approve). */
export function bankPostingErrorKey(code: string): string | null {
  return ERROR_KEYS[code] ?? null
}

export function bankPostingErrorMessage(e: unknown, t: (k: string, p?: any) => string): string {
  const code = (e as any)?.response?.data?.error?.code
  return code && ERROR_KEYS[code] ? t(ERROR_KEYS[code]) : apiErrorMessage(e, t('bank.posting.err_generic'))
}
