import { api } from './client'

export type { BankReconciliationCandidate } from '@/types/bankReconciliation'

export interface BankPaymentCandidate {
  id: number
  statement_id: number
  posted_at: string
  amount: number
  currency: string
  counterparty_name: string | null
  variable_symbol: string | null
  description: string | null
  bank_ref: string | null
  account_number: string
  bank_code: string | null
}

export type NoticeConfirmationState = 'pending' | 'review' | 'confirmed'
export interface NoticeConfirmationSummary { pending: number; review: number; confirmed: number; total: number }
export interface NoticeConfirmationInspection {
  confirmation_state?: NoticeConfirmationState
  confirmation_record_exists?: boolean
  confirmation_reason?: string | null
  proposed_state?: NoticeConfirmationState
  proposed_reason?: string | null
  authoritative_transaction_id?: number | null
  authoritative_statement_id?: number | null
  authoritative_statement_ids?: number[]
  candidates?: BankPaymentCandidate[]
  blockers?: string[]
}
export interface NoticeConfirmationBatch {
  confirmation_summary: NoticeConfirmationSummary
  proposed_summary?: NoticeConfirmationSummary
  processed: number
  next_after_id: number | null
  has_more: boolean
  items: Array<NoticeConfirmationInspection & { id: number }>
}

export interface BankStatement {
  id: number
  confirmation_summary?: NoticeConfirmationSummary
  deleted_notice_confirmations?: number
  can_delete_notices?: boolean
  deletion_blockers?: Array<{ code: string; count: number }>
  /** Zdroj výpisu: 'gpc' = nahraný/importovaný GPC výpis, 'pdf' = rozparsovaný PDF výpis (banka bez GPC exportu), 'email_notice' = měsíční agregát e-mailových avíz, 'idoklad' = měsíční agregát pohybů z iDokladu, 'import' = migrace z jiného účetního systému. */
  source?: 'gpc' | 'pdf' | 'email_notice' | 'idoklad' | 'bank_api' | 'import'
  file_name: string
  account_number: string
  /** Kód banky (4místný), pokud je u výpisu evidovaný — pro zobrazení „účet / kód". */
  bank_code?: string | null
  /** Vlastní pojmenování účtu z currencies.label (např. "CZK — Fio Bank"), pokud match. */
  account_label: string | null
  currency: string | null
  statement_date: string
  statement_number: string | null
  prev_balance: number | null
  curr_balance: number | null
  balance_calculation?: {
    status: 'calculated' | 'confirmed' | 'missing_anchor' | 'mismatch' | 'unavailable'
    from?: string
    to?: string
    opening?: number | null
    closing?: number | null
    confirmed_closing?: number | null
    bank_statement_id?: number | null
    difference?: number | null
    /** Rozdíl mezi zůstatkem hlášeným bankou (avízo) a zůstatkem dopočteným z evidovaných pohybů — nenačtený nebo přebývající pohyb. */
    unexplained_difference?: number | null
    credit?: number
    debit?: number
    transaction_count?: number
  } | null
  transaction_count: number
  matched_count: number
  /** Nespárované pohyby, u kterých se faktura nečeká (vlastní převod, mzda). */
  non_invoice_count?: number
  ignored_count?: number
  /** Počet skutečných pohybů bez aktivního účetního zápisu (ignorované položky se nepočítají). */
  unposted_count: number
  /** Pohyby, které import uložil, ale jejich zpracování (párování, zaúčtování) selhalo. */
  unprocessed_count?: number
  /** Výpis má nezpracované pohyby — dokončí je „Znovu spárovat". */
  processing_failed?: boolean
  imported_at: string
  has_file: boolean
  /** Je k výpisu přiložené PDF (bank_statements.pdf_content)? */
  has_pdf: boolean
  /** Původní název nahraného PDF, pokud je. */
  pdf_name?: string | null
  evidence_pdfs?: Array<{ id: number; pdf_name: string | null }>
  /** Zdrojové výpisy měsíčního výpisu API; v seznamu výpisů jsou skryté. */
  evidence_statements?: Array<{ id: number; file_name: string | null; source: string; statement_date: string | null; transaction_count: number }>
  /** Výpis je zdrojem měsíčního výpisu API (smazat jde jen s odpojením od měsíce). */
  api_evidence?: boolean
  /** Měsíční výpis API (jen v seznamu); průběžně se doplňuje z banky a smazat nejde. */
  api_month?: boolean
}

export type MatchStatus = 'unmatched' | 'auto_exact' | 'auto_partial' | 'manual' | 'ignored'
export type PostingFilter = 'unposted' | 'posted'

/** Sloupce, podle kterých server řadí pohyby (whitelist BankTransactionSort). */
export type BankTransactionSortKey =
  | 'posted_at' | 'amount' | 'variable_symbol' | 'counterparty' | 'invoice' | 'status' | 'posting' | 'account'

/** Zkrácená poznámka účetního zápisu v řádku pohybu (plná verze = JournalNote). */
export interface BankTransactionJournalNote {
  id: number
  body: string
  pinned: boolean
}

export interface BankTransaction extends NoticeConfirmationInspection {
  id: number
  /** 'statement' = z nahraného výpisu, 'email_notice' = z e-mailového avíza, 'idoklad' = z pohybu importovaného z iDokladu. */
  source?: 'statement' | 'email_notice' | 'idoklad'
  statement_id: number
  posted_at: string
  amount: number
  /** Disponibilní zůstatek účtu z e-mailového avíza (Creditas/Fio/RB); u GPC transakcí null. */
  balance?: number | null
  currency: string | null
  /** Částka v CZK (u korunového pohybu = amount). null = kurz ke dni pohybu není k dispozici. */
  amount_czk?: number | null
  /** Kurz použitý pro amount_czk — týž, jakým se pohyb zaúčtuje (pevný kurz firmy → ČNB). */
  fx_rate?: number | null
  variable_symbol: string | null
  constant_symbol: string | null
  specific_symbol: string | null
  counterparty_account: string | null
  counterparty_bank: string | null
  counterparty_name: string | null
  /** Poslední čtyři číslice platební karty (celé číslo aplikace nezná). */
  card_last4?: string | null
  /** Karta firmy podle koncovky a data pohybu — jen v detailu výpisu. */
  card?: import('./paymentCards').PaymentCardSummary | null
  description: string | null
  bank_ref: string | null
  matched_invoice_id: number | null
  matched_purchase_invoice_id?: number | null
  matched_varsymbol?: string | null
  matched_invoice_amount?: number | null
  matched_client_name?: string | null
  /** Číslo přijaté faktury (vendor_invoice_number, fallback varsymbol), pokud je transakce spárovaná s přijatou. */
  matched_purchase_ref?: string | null
  /** Název dodavatele spárované přijaté faktury. */
  matched_vendor_name?: string | null
  /** Seznam vystavených faktur uhrazených touto transakcí (sloučená úhrada → víc než 1). */
  matched_invoices?: MatchedInvoice[]
  matched_purchase_invoices?: MatchedPurchaseInvoice[]
  ignore_origin?: string | null
  ignore_note?: string | null
  resolution?: 'payroll' | 'transfer' | 'outside_saldo' | null
  match_available?: boolean
  ignore_available?: boolean
  match_status: MatchStatus
  /** Kód důvodu posledního neúspěšného automatického párování (#46), např. `no_invoice_with_vs`. */
  match_reason?: string | null
  matched_at: string | null
  /** Datum pohybu nemá otevřené účetní období nebo spadá do účetního zámku. */
  period_closed?: boolean
  /** Dimenze pohybu (typ → hodnota); jen u firmy se zapnutými dimenzemi. */
  dimensions?: import('./dimensions').DimensionMap
  /** Stav zaúčtování transakce (Epic AUTOMATIZACE) — jen u double_entry firmy, jinak null. */
  posting?: {
    status: 'posted' | 'suggested' | null
    counter_account_codes?: string[]
    payroll_matched?: boolean
    /** Zaúčtováno mimo saldokontní účty (daň, odvod, poplatek, splátka) — pohyb doklad nečeká. */
    outside_saldo?: boolean
    payroll_posting_blocked?: boolean
    journal_entry_id?: number
    document_no?: string
    /** Poznámky zápisu `journal_entry_id` (připnuté první) — žijí v deníku, pohyb je jen ukazuje. */
    journal_notes?: BankTransactionJournalNote[]
    automated?: boolean
    automation_source?: 'rule' | 'learned' | 'payment_match' | 'transfer' | 'detector' | 'schedule' | 'knn' | 'llm'
    suggestion_id?: number
    suggestion_source?: 'rule' | 'learned' | 'payment_match' | 'transfer' | 'detector' | 'schedule' | 'knn' | 'llm'
    rule_id?: number
    rule_name?: string
    debit_account_code?: string
    credit_account_code?: string
    note?: string
    transfer?: {
      direction: 'out' | 'in'
      own_account_label: string | null
      pair: { tx_id: number; statement_id: number; posted_at: string; entry_id: number | null } | null
    }
  } | null
}

/** Výsledek zaúčtování z hooku match/rematch (M5) — FE toastuje rozdíl „spárováno" × „a zaúčtováno". */
export interface MatchPostingResult {
  action: 'posted' | 'suggested' | 'skipped'
  reason?: string
}

/** Jedna vystavená faktura uhrazená bankovní transakcí (z invoice_payments). */
export interface MatchedInvoice {
  invoice_id: number
  varsymbol: string | null
  invoice_type: string
  amount: number
  client_name: string | null
}

export interface MatchedPurchaseInvoice {
  purchase_invoice_id: number
  ref: string | null
  vendor_name: string | null
  amount: number
  currency: string
}

/** Kandidát na spárování dle částky + data (±14 dní, fallback ±90 dní) — vystavená i přijatá faktura. */
export interface MatchCandidate {
  type: 'invoice' | 'purchase_invoice'
  id: number
  ref: string | null
  amount: number
  currency: string
  /** Částka přepočtená do měny transakce (jen u cross-currency, jinak null). */
  converted_amount: number | null
  converted_currency: string | null
  issue_date: string
  due_date: string | null
  party: string | null
  /** Faktura je už zaplacená — UI zobrazí varovný štítek (duplicitní/druhá platba). */
  paid: boolean
  /** Fallback kandidát bez FX převodu — syrová částka sedí, ale měna faktury neodpovídá
   *  měně transakce (klient zaplatil "stejné číslo" z cizoměnového účtu). Ověřit ručně. */
  currency_mismatch: boolean
}

/** Jedna faktura v návrhu sloučené úhrady. */
export interface SplitSuggestionInvoice {
  id: number
  ref: string | null
  amount: number
  currency: string
  /** Částka přepočtená do měny platby (jen u cross-currency, jinak null). */
  converted: number | null
  /** Faktura je už zaplacená → spárování = rekonciliace existující platby (ne nová úhrada). */
  is_paid?: boolean
  issue_date: string
  due_date: string | null
  vendor_name?: string | null
}

/** Návrh kombinace faktur jednoho klienta, jejíž součet odpovídá příchozí platbě. */
export interface SplitSuggestion {
  document_type?: 'invoice' | 'purchase_invoice'
  client_id: number | null
  client_name: string | null
  currency: string
  total: number
  count: number
  invoices: SplitSuggestionInvoice[]
}

export interface MatchSignalMap { [signal: string]: number }

export interface MatchSuggestionCandidate {
  type: 'invoice' | 'purchase_invoice' | 'split'
  invoice_id: number | null
  invoice_ids: number[] | null
  purchase_invoice_id: number | null
  score: number
  signals: MatchSignalMap
  flags: string[]
  fee_amount: number | null
  overpayment_amount: number | null
  display: {
    ref: string | null
    party: string | null
    amount: number
    currency: string
    due_date: string | null
    paid: boolean
  }
}

export interface MatchSuggestion {
  id: number
  bank_transaction_id: number
  kind: 'single' | 'split' | 'vs_typo' | 'overpayment' | 'fee_gap'
  reason: string
  top_score: number
  margin: number | null
  deterministic_core: boolean
  status: string
  candidates: MatchSuggestionCandidate[]
  created_at: string
}

/** Stránkovací meta (jednotný kontrakt list endpointů). */
export interface PageMeta {
  total: number
  page: number
  per_page: number
  pages: number
}

/** Souhrn pro měsíční avízo-výpis (source='email_notice') — počítaný na backendu přes
 *  VŠECHNY transakce výpisu, nezávisle na stránkování/filtru transakcí v detailu. */
export interface BankStatementNoticeSummary {
  balance: number | null
  balance_at: string | null
  credit: number
  debit: number
}

export interface BankStatementDetail extends BankStatement {
  credit_total: number
  debit_total: number
  /** Aktuálně načtená stránka transakcí (dle `transactions_meta` + filtru `status`). */
  transactions: BankTransaction[]
  transactions_meta: PageMeta
  /** Počet transakcí VÝPISU (ne jen načtené stránky) čekajících na návrh zaúčtování. */
  pending_posting_count: number
  notice_summary: BankStatementNoticeSummary | null
  /** Text chyby, kterou skončilo zpracování pohybů po importu. */
  processing_error?: string | null
}

export interface BankTransactionsParams {
  show_confirmed?: 1
  confirmation_state?: NoticeConfirmationState
  page?: number
  per_page?: number
  status?: MatchStatus | ''
  posting_status?: PostingFilter | ''
  sort?: BankTransactionSortKey
  direction?: 'asc' | 'desc'
}

/**
 * Varování z importu výpisu:
 * - `transactions_skipped_as_duplicate`: soubor obsahoval pohyby, které se shodují
 *   (fingerprint) s už evidovanými, takže se nezaložily. `parsed/inserted/skipped`
 *   jsou přesná čísla ze StatementImporter.
 * - `processing_failed`: výpis je uložený, ale párování a zaúčtování pohybů selhalo
 *   (`error` = text chyby). Dokončí ho „Znovu spárovat".
 * FE si hlášku skládá z i18n — `message` je jen český text pro activity_log.
 */
export interface ImportWarning {
  code: 'transactions_skipped_as_duplicate' | 'processing_failed'
  message?: string
  error?: string
  parsed?: number
  inserted?: number
  skipped?: number
}

export interface IgnoreNoticeCandidate {
  index: number
  notice_id: number
  posted_at: string
  notice_date: string
  amount: number
  currency: string
  variable_symbol: string | null
  counterparty: string | null
  counterparty_account: string
  reason: 'variable_symbol' | 'counterparty_account'
  ignore_note: string | null
}
export interface IgnoreNoticePreview {
  fingerprint: string
  candidates: IgnoreNoticeCandidate[]
}
export type IgnoreNoticeDecision = { skip: true } | { fingerprint: string; selected: number[] }

export interface ImportResult {
  ignored_transferred?: number
  statement_id: number
  transactions: number
  matched: number
  /** Celý soubor (file_hash) už byl dřív naimportovaný — očekávaná duplicita při
   *  opětovném nahrání téhož výpisu, nic se nezaložilo. Klidné hlášení. */
  duplicate: boolean
  /** PDF patřilo k už existujícímu výpisu (typicky GPC) — jen se k němu přiložilo. */
  attached_to_existing?: boolean
  pdf_name?: string
  /** Počet řádků 075 v souboru (i když je `duplicate` a nic se nezaložilo). */
  parsed_transactions?: number
  /** Počet pohybů přeskočených jako duplicita UVNITŘ jinak nového výpisu (viz `warnings`). */
  skipped_duplicates?: number
  /** Neprázdné jen když `skipped_duplicates > 0` a výpis NENÍ celý duplicitní —
   *  to je podezřelé (přeskočené pohyby v jinak novém souboru), ne očekávaná duplicita. */
  warnings?: ImportWarning[]
  /** Pohyby jsou uložené, ale jejich zpracování selhalo (viz `warnings`). */
  processing_failed?: boolean
  /** Účet výpisu patří jiné firmě uživatele: výpis se naimportoval do ní. */
  target_supplier_id?: number
  target_supplier_name?: string | null
}

/** Kandidát účtu při shodném čísle ve více měnách nebo bankách (#167/#206). */
export interface AmbiguousAccount {
  account_id: number
  code: string
  label: string
  bank_code?: string | null
  account_number?: string
}

/** Účet pro filtr v přehledu výpisů (distinct account_number + jeho label z currencies). */
export interface BankAccountOption {
  account_number: string
  bank_code?: string | null
  label: string | null
}

export interface BankStatementPage {
  items: BankStatement[]
  total: number
  page: number
  limit: number
  /** Roky přítomné ve výpisech (pro filtr rok), descending. */
  years: number[]
  /** Účty přítomné ve výpisech (pro filtr na číslo účtu). */
  accounts: BankAccountOption[]
  /** Je v cfg.php nastavené adresářové skenování (bank_import.scan_root)? Řídí tlačítko „Skenovat adresář". */
  scan_configured: boolean
  /** Počet výpisů s nezpracovanými pohyby přes celý scope (bez filtrů a stránkování). */
  processing_failed_statements?: number
}

export interface BankListParams {
  show_confirmed?: 1
  confirmation_state?: NoticeConfirmationState
  page?: number
  year?: number | ''
  month?: number | ''
  account?: string
  bank_code?: string
  counterparty_account?: string
  client_id?: number | ''
  amount?: number | string | ''
  posting_status?: 'unposted' | ''
}

/** Jeden bod měsíční řady zůstatku (nativní měna účtu). */
export interface AccountBalanceMonth {
  /** 'YYYY-MM'. */
  month: string
  /** Závěrečný zůstatek za měsíc (carry-forward), null když účet ještě neexistoval. */
  balance: number | null
}

/** Stav jednoho bankovního účtu dle GPC výpisů a zůstatků z e-mailových avíz. */
export interface AccountBalance {
  /** currencies.id */
  id: number
  code: string
  label: string
  account_number: string
  bank_code: string | null
  is_default: boolean
  /** Aktuální stav = nejnovější známý zůstatek (GPC výpis, nebo čerstvější avízo). */
  current_balance: number
  /** Aktuální stav přepočtený na CZK aktuálním kurzem; null když měna nemá kurz. */
  current_balance_czk: number | null
  /** Datum, ke kterému aktuální stav platí (výpis / avízo). */
  statement_date: string
  /** Odkud aktuální stav pochází: GPC výpis, nebo disponibilní zůstatek z avíza. */
  current_source: 'gpc' | 'pdf' | 'email_notice' | 'idoklad' | 'bank_api' | 'import'
  statement_count: number
  months: AccountBalanceMonth[]
}

export interface AccountBalancesResponse {
  base_currency: string
  accounts: AccountBalance[]
  total_czk: {
    current: number
    months: { month: string; balance_czk: number | null }[]
    series: {
      account_id: number
      label: string
      account_number: string
      bank_code: string | null
      months: { month: string; balance_czk: number | null }[]
    }[]
  }
  /** Měny bez jakéhokoli kurzu v cache (nešly přepočíst na CZK). */
  missing_rates: string[]
}

export const bankApi = {
  noticeConfirmations: (id: number, params: { dry_run: boolean; limit?: number; after_id?: number }) =>
    api.post<NoticeConfirmationBatch>(`/bank-statements/${id}/notice-confirmations`, params).then(r => r.data),
  confirmNotice: (id: number, authoritative_transaction_id: number, reason: string) =>
    api.post<NoticeConfirmationInspection>(`/bank-transactions/${id}/notice-confirmation`, { authoritative_transaction_id, reason }).then(r => r.data),
  list: (params: BankListParams = {}) =>
    api.get<BankStatementPage>('/bank-statements', { params: {
      page: params.page ?? 1,
      ...(params.show_confirmed ? { show_confirmed: 1 } : {}),
      ...(params.confirmation_state ? { confirmation_state: params.confirmation_state } : {}),
      ...(params.year !== undefined && params.year !== '' ? { 'filter[year]': params.year } : {}),
      ...(params.month !== undefined && params.month !== '' ? { 'filter[month]': params.month } : {}),
      ...(params.account ? { 'filter[account]': params.account } : {}),
      ...(params.bank_code ? { 'filter[bank_code]': params.bank_code } : {}),
      ...(params.counterparty_account ? { 'filter[counterparty_account]': params.counterparty_account } : {}),
      ...(params.client_id ? { 'filter[client_id]': params.client_id } : {}),
      ...(params.amount !== undefined && params.amount !== '' ? { 'filter[amount]': params.amount } : {}),
      ...(params.posting_status ? { 'filter[posting_status]': params.posting_status } : {}),
    } }).then(r => r.data),
  /** Detail výpisu + STRÁNKOVANÁ transakce (`transactions_meta`). Volitelný filtr `status`. */
  get: (id: number, params: BankTransactionsParams = {}) =>
    api.get<BankStatementDetail>(`/bank-statements/${id}`, { params: {
      page: params.page ?? 1,
      ...(params.show_confirmed ? { show_confirmed: 1 } : {}),
      ...(params.confirmation_state ? { confirmation_state: params.confirmation_state } : {}),
      ...(params.per_page ? { per_page: params.per_page } : {}),
      ...(params.status ? { status: params.status } : {}),
      ...(params.posting_status ? { posting_status: params.posting_status } : {}),
      ...(params.sort ? { sort: params.sort, direction: params.direction ?? 'asc' } : {}),
    } }).then(r => r.data),
  /** Přehled zůstatků na účtech dle GPC výpisů (tabulka + měsíční vývoj + CZK součet). */
  accountBalances: () =>
    api.get<AccountBalancesResponse>('/bank-statements/account-balances').then(r => r.data),
  /**
   * Nahraje GPC/ABO výpis. `accountId` (currencies.id) je volitelný — povinný jen
   * u víceměnového účtu se sdíleným číslem účtu, kdy server vrátí 409
   * `ambiguous_account_currency` se seznamem kandidátů (#167).
   */
  upload: (file: File, accountId?: number, reconciliationConfirmations: string[] = [], ignoreDecision?: IgnoreNoticeDecision) => {
    const fd = new FormData()
    fd.append('file', file)
    if (ignoreDecision !== undefined) fd.append('ignore_decision', JSON.stringify(ignoreDecision))
    if (accountId !== undefined) fd.append('account_id', String(accountId))
    if (reconciliationConfirmations.length > 0) {
      fd.append('reconciliation_confirmations', JSON.stringify(reconciliationConfirmations))
    }
    return api.post<ImportResult>('/bank-statements/upload', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  /**
   * Nahraje a rozparsuje PDF výpis banky bez GPC/ABO exportu (Creditas jako první,
   * rozšiřitelné). Stejná 409 `ambiguous_account_currency` volba účtu jako `upload()`.
   */
  importPdf: (file: File, accountId?: number, ignoreDecision?: IgnoreNoticeDecision) => {
    const fd = new FormData()
    fd.append('file', file)
    if (ignoreDecision !== undefined) fd.append('ignore_decision', JSON.stringify(ignoreDecision))
    if (accountId !== undefined) fd.append('account_id', String(accountId))
    return api.post<ImportResult>('/bank-statements/upload-pdf', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  /** `fallback=true` = v ±14 dnech nic nesedělo, vráceny širší (±90 dní) a/nebo cross-currency návrhy. */
  matchCandidates: (txId: number, search?: string) =>
    api.get<{ candidates: MatchCandidate[]; fallback: boolean }>(`/bank-transactions/${txId}/match-candidates`, { params: { search } })
      .then(r => r.data),
  paymentCandidates: (params: { invoiceId?: number; purchaseInvoiceId?: number; search?: string; page?: number }) =>
    api.get<{ items: BankPaymentCandidate[]; total: number; page: number; pages: number; limit: number }>('/bank-transactions/payment-candidates', {
      params: { invoice_id: params.invoiceId, purchase_invoice_id: params.purchaseInvoiceId, search: params.search, page: params.page },
    }).then(r => r.data),
  matchDocument: (txId: number, ref: { invoiceId?: number; purchaseInvoiceId?: number; forceAdvanceFinal?: boolean }) =>
    api.post<{
      matched: true; paid_at?: string; purchase_invoice_id?: number; posting?: MatchPostingResult | null
      partial_payment?: boolean; remaining?: number; currency?: string
    }>(`/bank-transactions/${txId}/match-document`, {
      ...(ref.invoiceId ? { invoice_id: ref.invoiceId } : {}),
      ...(ref.purchaseInvoiceId ? { purchase_invoice_id: ref.purchaseInvoiceId } : {}),
      ...(ref.forceAdvanceFinal ? { force_advance_final: true } : {}),
    }).then(r => r.data),
  /** `forceAdvanceFinal` = vědomé párování platby neuhrazené zálohy s konečnou fakturou (po potvrzení). */
  matchManual: (txId: number, ref: { invoiceId?: number; purchaseInvoiceId?: number; varsymbol?: string; forceAdvanceFinal?: boolean }) =>
    api.post<{
      matched: true; paid_at?: string; purchase_invoice_id?: number; posting?: MatchPostingResult | null
      /** Přijatá faktura: platba nepokryla zbytek, doklad zůstal částečně uhrazený. */
      partial_payment?: boolean; remaining?: number; currency?: string
    }>(`/bank-transactions/${txId}/match`, {
      ...(ref.invoiceId ? { invoice_id: ref.invoiceId } : {}),
      ...(ref.purchaseInvoiceId ? { purchase_invoice_id: ref.purchaseInvoiceId } : {}),
      ...(ref.varsymbol ? { varsymbol: ref.varsymbol } : {}),
      ...(ref.forceAdvanceFinal ? { force_advance_final: true } : {}),
    }).then(r => r.data),
  /** Sloučená úhrada: jedna příchozí platba → více vystavených faktur (téhož klienta). */
  matchMultiple: (txId: number, invoiceIds: number[]) =>
    api.post<{ matched: true; split: true; paid_at?: string; invoice_ids: number[]; final_draft_ids?: number[]; posting?: MatchPostingResult | null }>(
      `/bank-transactions/${txId}/match`, { invoice_ids: invoiceIds },
    ).then(r => r.data),
  matchMultiplePurchases: (txId: number, purchaseInvoiceIds: number[]) =>
    api.post<{ matched: true; split: true; purchase_invoice_ids: number[]; posting?: MatchPostingResult | null }>(
      `/bank-transactions/${txId}/match`, { purchase_invoice_ids: purchaseInvoiceIds },
    ).then(r => r.data),
  /** Návrhy sloučené úhrady (kombinace faktur jednoho klienta dle částky + okna dní). */
  splitSuggestions: (txId: number, opts: { invoiceId?: number; purchaseInvoiceId?: number; window?: number; max?: number } = {}) =>
    api.get<{ suggestions: SplitSuggestion[]; window: number; max: number }>(
      `/bank-transactions/${txId}/split-suggestions`,
      { params: {
        ...(opts.invoiceId ? { invoice_id: opts.invoiceId } : {}),
        ...(opts.purchaseInvoiceId ? { purchase_invoice_id: opts.purchaseInvoiceId } : {}),
        // `window: 0` = bez datového omezení, takže se testuje na undefined, ne na falsy.
        ...(opts.window !== undefined ? { window: opts.window } : {}),
        ...(opts.max ? { max: opts.max } : {}),
      } },
    ).then(r => r.data),
  matchSuggestions: (statementId: number) =>
    api.get<{ suggestions: MatchSuggestion[] }>(`/bank-statements/${statementId}/match-suggestions`).then(r => r.data),
  acceptMatchSuggestion: (id: number, candidate: number) =>
    api.post<{ matched: true; posting?: MatchPostingResult | null }>(
      `/bank-match-suggestions/${id}/accept`, { candidate_index: candidate },
    ).then(r => r.data),
  rejectMatchSuggestion: (id: number, reason?: string) =>
    api.post<{ rejected: true }>(
      `/bank-match-suggestions/${id}/reject`, reason ? { reason } : {},
    ).then(r => r.data),
  unignore: (txId: number) => api.post<{ unmatched: true }>(`/bank-transactions/${txId}/unignore`).then(r => r.data),
  ignore: (txId: number, note: string | null = null) =>
    api.post<{ ignored: true; ignore_note: string | null }>(`/bank-transactions/${txId}/ignore`, { note }).then(r => r.data),
  unmatch: (txId: number) =>
    api.post<{ unmatched: true }>(`/bank-transactions/${txId}/unmatch`, {}).then(r => r.data),
  createPurchaseInvoice: (txId: number, vendorId: number) =>
    api.post<{ purchase_invoice_id: number; vendor_id: number; currency: string }>(
      `/bank-transactions/${txId}/create-purchase-invoice`, { vendor_id: vendorId },
    ).then(r => r.data),
  rematch: (statementId: number) =>
    api.post<{
      considered: number; newly_matched: number; newly_partial: number
      still_unmatched: number; taken_over: number; superseded: number
    }>(`/bank-statements/${statementId}/rematch`, {}).then(r => r.data),
  scan: () => api.post<{ scanned: number; imported: number; duplicate: number; errors: number }>(
    '/bank-statements/scan', {},
  ).then(r => r.data),
  delete: (id: number) =>
    api.delete<{ deleted: true }>(`/bank-statements/${id}`).then(r => r.data),
  /**
   * Build download URL pro originální GPC. Vrací absolutní URL — UI ji použije
   * v `<a href>` (browser stáhne přímo). Auth cookie se posílá automaticky.
   */
  gpcExportUrl: (id: number): string => {
    const base = api.defaults.baseURL ?? ''
    return `${base.replace(/\/$/, '')}/bank-statements/${id}/export-gpc`
  },
  unmatchedAllExportUrl: (params: { year?: number; q?: string; account?: string; posting_status?: PostingFilter; sort?: BankTransactionSortKey; direction?: 'asc' | 'desc' }) => {
    const query = new URLSearchParams(Object.entries(params).map(([key, value]) => [key, String(value)]))
    return `/api/bank-statements/export-unmatched?${query}`
  },
  unmatchedExportUrl: (id: number): string => {
    const base = api.defaults.baseURL ?? ''
    return `${base.replace(/\/$/, '')}/bank-statements/${id}/export-unmatched`
  },
  unmatchedRecipients: (id: number) =>
    api.get<{ to: string[]; count: number; account: string }>(`/bank-statements/${id}/unmatched-recipients`).then(r => r.data),
  sendUnmatched: (id: number) =>
    api.post<{ sent_to: string[]; count: number; deferred: boolean }>(`/bank-statements/${id}/send-unmatched`, { confirmed: true }).then(r => r.data),
  downloadUrl: (id: number): string => {
    const base = api.defaults.baseURL ?? ''
    return `${base.replace(/\/$/, '')}/bank-statements/${id}/download`
  },
  /** Download URL přiloženého PDF výpisu (analogie downloadUrl pro GPC). */
  pdfUrl: (id: number): string => {
    const base = api.defaults.baseURL ?? ''
    return `${base.replace(/\/$/, '')}/bank-statements/${id}/pdf`
  },
  uploadPdf: (id: number, file: File) => {
    const fd = new FormData()
    fd.append('file', file)
    return api.post<{ uploaded: true; pdf_name: string }>(`/bank-statements/${id}/pdf`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  deletePdf: (id: number) =>
    api.delete<{ deleted: true }>(`/bank-statements/${id}/pdf`).then(r => r.data),
}
