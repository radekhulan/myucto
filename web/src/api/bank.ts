import { api } from './client'

export interface BankStatement {
  id: number
  /** Zdroj výpisu: 'gpc' = nahraný/importovaný GPC výpis, 'pdf' = rozparsovaný PDF výpis (banka bez GPC exportu), 'email_notice' = měsíční agregát e-mailových avíz, 'idoklad' = měsíční agregát pohybů z iDokladu. */
  source?: 'gpc' | 'pdf' | 'email_notice' | 'idoklad' | 'bank_api'
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
    credit?: number
    debit?: number
    transaction_count?: number
  } | null
  transaction_count: number
  matched_count: number
  ignored_count?: number
  /** Počet skutečných pohybů bez aktivního účetního zápisu (ignorované položky se nepočítají). */
  unposted_count: number
  imported_at: string
  has_file: boolean
  /** Je k výpisu přiložené PDF (bank_statements.pdf_content)? */
  has_pdf: boolean
  /** Původní název nahraného PDF, pokud je. */
  pdf_name?: string | null
}

export type MatchStatus = 'unmatched' | 'auto_exact' | 'auto_partial' | 'manual' | 'ignored'
export type PostingFilter = 'unposted' | 'posted'

export interface BankTransaction {
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
  match_status: MatchStatus
  matched_at: string | null
  /** Datum pohybu nemá otevřené účetní období nebo spadá do účetního zámku. */
  period_closed?: boolean
  /** Stav zaúčtování transakce (Epic AUTOMATIZACE) — jen u double_entry firmy, jinak null. */
  posting?: {
    status: 'posted' | 'suggested' | null
    payroll_matched?: boolean
    payroll_posting_blocked?: boolean
    journal_entry_id?: number
    document_no?: string
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
}

/** Návrh kombinace faktur jednoho klienta, jejíž součet odpovídá příchozí platbě. */
export interface SplitSuggestion {
  client_id: number
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
}

export interface BankTransactionsParams {
  page?: number
  per_page?: number
  status?: MatchStatus | ''
  posting_status?: PostingFilter | ''
}

/**
 * Varování z importu výpisu — dnes jen jeden kód: soubor obsahoval pohyby, které
 * se shodují (fingerprint) s už evidovanými, takže se nezaložily. `parsed/inserted/
 * skipped` jsou přesná čísla ze StatementImporter, FE si z nich sám poskládá hlášku
 * (viz `bank.warning.transactions_skipped_as_duplicate` v i18n) — `message` je
 * jen český text pro activity_log, na UI se nepoužívá (chybí EN varianta).
 */
export interface ImportWarning {
  code: 'transactions_skipped_as_duplicate'
  message?: string
  parsed?: number
  inserted?: number
  skipped?: number
}

export interface ImportResult {
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
}

export interface BankListParams {
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
  current_source: 'gpc' | 'pdf' | 'email_notice' | 'idoklad' | 'bank_api'
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
  list: (params: BankListParams = {}) =>
    api.get<BankStatementPage>('/bank-statements', { params: {
      page: params.page ?? 1,
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
      ...(params.per_page ? { per_page: params.per_page } : {}),
      ...(params.status ? { status: params.status } : {}),
      ...(params.posting_status ? { posting_status: params.posting_status } : {}),
    } }).then(r => r.data),
  /** Přehled zůstatků na účtech dle GPC výpisů (tabulka + měsíční vývoj + CZK součet). */
  accountBalances: () =>
    api.get<AccountBalancesResponse>('/bank-statements/account-balances').then(r => r.data),
  /**
   * Nahraje GPC/ABO výpis. `accountId` (currencies.id) je volitelný — povinný jen
   * u víceměnového účtu se sdíleným číslem účtu, kdy server vrátí 409
   * `ambiguous_account_currency` se seznamem kandidátů (#167).
   */
  upload: (file: File, accountId?: number) => {
    const fd = new FormData()
    fd.append('file', file)
    if (accountId !== undefined) fd.append('account_id', String(accountId))
    return api.post<ImportResult>('/bank-statements/upload', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  /**
   * Nahraje a rozparsuje PDF výpis banky bez GPC/ABO exportu (Creditas jako první,
   * rozšiřitelné). Stejná 409 `ambiguous_account_currency` volba účtu jako `upload()`.
   */
  importPdf: (file: File, accountId?: number) => {
    const fd = new FormData()
    fd.append('file', file)
    if (accountId !== undefined) fd.append('account_id', String(accountId))
    return api.post<ImportResult>('/bank-statements/upload-pdf', fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  /** `fallback=true` = v ±14 dnech nic nesedělo, vráceny širší (±90 dní) a/nebo cross-currency návrhy. */
  matchCandidates: (txId: number) =>
    api.get<{ candidates: MatchCandidate[]; fallback: boolean }>(`/bank-transactions/${txId}/match-candidates`)
      .then(r => r.data),
  matchManual: (txId: number, ref: { invoiceId?: number; purchaseInvoiceId?: number; varsymbol?: string }) =>
    api.post<{ matched: true; paid_at?: string; purchase_invoice_id?: number; posting?: MatchPostingResult | null }>(`/bank-transactions/${txId}/match`, {
      ...(ref.invoiceId ? { invoice_id: ref.invoiceId } : {}),
      ...(ref.purchaseInvoiceId ? { purchase_invoice_id: ref.purchaseInvoiceId } : {}),
      ...(ref.varsymbol ? { varsymbol: ref.varsymbol } : {}),
    }).then(r => r.data),
  /** Sloučená úhrada: jedna příchozí platba → více vystavených faktur (téhož klienta). */
  matchMultiple: (txId: number, invoiceIds: number[]) =>
    api.post<{ matched: true; split: true; paid_at?: string; invoice_ids: number[]; final_draft_ids?: number[]; posting?: MatchPostingResult | null }>(
      `/bank-transactions/${txId}/match`, { invoice_ids: invoiceIds },
    ).then(r => r.data),
  /** Návrhy sloučené úhrady (kombinace faktur jednoho klienta dle částky + okna dní). */
  splitSuggestions: (txId: number, opts: { invoiceId?: number; window?: number; max?: number } = {}) =>
    api.get<{ suggestions: SplitSuggestion[]; window: number; max: number }>(
      `/bank-transactions/${txId}/split-suggestions`,
      { params: {
        ...(opts.invoiceId ? { invoice_id: opts.invoiceId } : {}),
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
  ignore: (txId: number) =>
    api.post<{ ignored: true }>(`/bank-transactions/${txId}/ignore`, {}).then(r => r.data),
  unmatch: (txId: number) =>
    api.post<{ unmatched: true }>(`/bank-transactions/${txId}/unmatch`, {}).then(r => r.data),
  createPurchaseInvoice: (txId: number, vendorId: number) =>
    api.post<{ purchase_invoice_id: number; vendor_id: number; currency: string }>(
      `/bank-transactions/${txId}/create-purchase-invoice`, { vendor_id: vendorId },
    ).then(r => r.data),
  rematch: (statementId: number) =>
    api.post<{ considered: number; newly_matched: number; newly_partial: number; still_unmatched: number }>(
      `/bank-statements/${statementId}/rematch`, {}).then(r => r.data),
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
