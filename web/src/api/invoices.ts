import { api } from './client'
import type { DocumentLock } from './locks'
import { appIsoDate } from '@/utils/date'

export type InvoiceType = 'invoice' | 'proforma' | 'credit_note' | 'cancellation' | 'tax_document' | 'penalty' | 'payment_calendar'

/** Jeden segment výpočtu úroku z prodlení (rozpad jen kvůli hranici kalendářního roku — sazba je napříč segmenty stejná). */
export interface PenaltyInterestSegment {
  from: string
  to: string
  days: number
  repo_rate: number
  annual_rate: number
  day_count_basis: number
  interest: number
}

/** Náhled výpočtu úroku z prodlení (NV č. 351/2013 Sb.). */
export interface PenaltyPreview {
  principal: number
  due_date: string
  as_of: string
  total_days: number
  total_interest: number
  surcharge_points: number
  segments: PenaltyInterestSegment[]
  source_invoice_id: number
  source_varsymbol: string
  currency: string
  /** Do kdy pokrývá dřívější (nestornovaná) penalizace tohoto dokladu — null když žádná není. */
  previously_covered_through: string | null
}
export type InvoiceStatus = 'draft' | 'issued' | 'sent' | 'reminded' | 'paid' | 'cancelled'
export type ApprovalStatus = 'none' | 'requested' | 'approved' | 'rejected'
/** Odvozený platební stav (#89) — počítá se z paid_total vs. amount_to_pay; null pro draft/cancelled. */
export type PaymentStatus = 'unpaid' | 'partially_paid' | 'paid' | 'overpaid'

/** Evidovaná platba faktury (#89). */
export interface InvoicePayment {
  id: number
  invoice_id: number
  paid_on: string
  amount: number
  currency: string
  variable_symbol: string | null
  bank_reference: string | null
  note: string | null
  source: 'manual' | 'mark_paid' | 'bank' | 'legacy'
  bank_transaction_id: number | null
  bank_statement_id?: number | null
  bank_counterparty_name?: string | null
  tax_document_invoice_id: number | null
  tax_document_varsymbol?: string | null
  tax_document_status?: InvoiceStatus | null
  created_at: string
}

export interface InvoicePaymentsResponse {
  payments: InvoicePayment[]
  bank_transactions: RelatedBankTransaction[]
  paid_total: number
  amount_to_pay: number
  remaining: number
  payment_status: PaymentStatus | null
}

export interface RelatedBankTransaction {
  id: number
  statement_id: number
  statement_source: 'gpc' | 'pdf' | 'email_notice' | 'idoklad'
  posted_at: string
  amount: number
  currency: string | null
  variable_symbol: string | null
  constant_symbol: string | null
  specific_symbol: string | null
  counterparty_account: string | null
  counterparty_bank: string | null
  counterparty_name: string | null
  description: string | null
  bank_ref: string | null
  match_status: 'unmatched' | 'auto_exact' | 'auto_partial' | 'manual' | 'ignored'
}

/** Nespárovaná zálohová faktura (proforma) nabídnutá k propojení s daňovým dokladem. */
export interface AdvanceCandidate {
  id: number
  varsymbol: string | null
  invoice_type: InvoiceType
  status: InvoiceStatus
  issue_date: string | null
  total_with_vat: number
  currency: string
}

export interface InvoiceItem {
  id?: number
  invoice_id?: number
  description: string
  quantity: number
  unit: string
  unit_price_without_vat: number
  vat_rate_id: number
  vat_rate_snapshot?: number
  total_without_vat?: number
  total_vat?: number
  total_with_vat?: number
  order_index: number
  // 'discount' = systémově generovaná záporná slevová položka (z invoices.discount_percent).
  // Editor ji při načtení vyfiltruje — needituje se a negeneruje znovu při uložení.
  item_kind?: 'standard' | 'discount'
  linked_work_report_id?: number | null
  vat_code?: string
  vat_label_cs?: string
  vat_label_en?: string
  /** Vazba na skladovou kartu (Epic SKLAD, B5) — FE MUSÍ posílat zpět v round-tripu, jinak se DELETE+INSERT tiše smaže. */
  stock_item_id?: number | null
  /** Sklad, ze kterého se položka vydá při vystavení (auto-výdejka); null = default sklad supplieru. */
  warehouse_id?: number | null
  /** Prodávaná karta drobného majetku (1177) — výnos 642 a po vystavení karta → prodáno. */
  small_asset_id?: number | null
  /** Prodávaná karta dlouhodobého majetku (1177) — výnos 641 a po vystavení vyřazení type=sold. */
  asset_id?: number | null
  /** Název karty z JOINu (read-only) — našeptávač jím zobrazí vybranou položku bez dalšího dotazu. */
  small_asset_name?: string | null
  asset_name?: string | null
  oss_applicable?: boolean
  oss_consumer_country?: string | null
  oss_rate_type?: 'standard' | 'reduced' | 'second_reduced' | 'parking' | string | null
  oss_supply_type?: 'goods' | 'services' | null
  oss_exchange_rate?: number | null
  oss_exchange_rate_date?: string | null
  oss_taxable_amount_return?: number | null
  oss_vat_amount_return?: number | null
  oss_original_period?: string | null
  /**
   * „Místo plnění systém neurčil" (migrace 1293) — nastavuje odvození při importu.
   * FE ho MUSÍ posílat zpět v round-tripu, jinak ho replaceItems (DELETE+INSERT) tiše
   * smaže; není to editovatelné pole, uživatel ho zhasíná vypnutím OSS na položce.
   */
  oss_needs_manual_review?: boolean
}

export interface VatBreakdownRow {
  rate: number
  base: number
  vat: number
}

export interface InvoiceTotals {
  without_vat: number
  vat: number
  with_vat: number
  rounding?: number
  advance_paid_amount?: number
  amount_to_pay?: number
  discount_percent?: number
  discount_amount?: number
}

/**
 * Forma úhrady (migrace 1128) — sdílená doména pro vydané i přijaté faktury,
 * zrcadlí PHP `MyInvoice\Support\PaymentMethods`. `direct_debit` (inkaso/SIPO) je
 * důvod, proč pole vzniklo: na takovou přijatou fakturu se NESMÍ vystavit platební
 * příkaz, jinak se platí dvakrát.
 */
export type PaymentMethod =
  | 'bank_transfer'
  | 'direct_debit'
  | 'card'
  | 'cash'
  | 'cash_on_delivery'
  | 'offset'
  | 'other'

/** Pořadí pro selecty; klíč i18n je `payment_method.<hodnota>`. */
export const PAYMENT_METHODS: PaymentMethod[] = [
  'bank_transfer',
  'direct_debit',
  'card',
  'cash',
  'cash_on_delivery',
  'offset',
  'other',
]

/** Zdroj hodnoty `payment_method` — priorita manual > ai > vendor > default. */
export type PaymentMethodSource = 'default' | 'vendor' | 'ai' | 'manual'

/**
 * § 31 a § 31a ZDPH — jedna platba rozpisu splátkového nebo platebního kalendáře.
 *
 * Kalendář je sám o sobě daňovým dokladem právě proto, že rozpis obsahuje; bez něj
 * ho nelze vystavit a odběratel z něj nemůže uplatnit odpočet.
 */
export interface PaymentScheduleRow {
  id?: number
  due_on: string
  base_amount: number
  vat_amount: number
  total_amount: number
  note: string | null
}

export interface Invoice {
  id: number
  varsymbol: string | null
  invoice_type: InvoiceType
  parent_invoice_id: number | null
  client_id: number
  project_id: number | null
  branding_profile_id: number | null
  issue_date: string
  tax_date: string | null
  due_date: string
  currency_id: number
  currency: string
  reverse_charge: boolean
  /** § 30 ZDPH — zjednodušený daňový doklad (do 10 000 Kč vč. daně). */
  is_simplified: boolean
  /** § 31/31a ZDPH — rozpis plateb kalendáře (prázdné u ostatních typů). */
  payment_schedule?: PaymentScheduleRow[]
  /** Ceny položek zadané včetně DPH (brutto) — DPH se počítá shora koeficientem. */
  prices_include_vat: boolean
  /** Doklad není základem daně z příjmů (§4 osvobození / přefakturace) → vyloučen z DPFO/DPPO i SP/ZP. DPH/KH/tržby nedotčeny. */
  income_tax_exempt: boolean
  income_tax_exempt_reason: string | null
  language: 'cs' | 'en'
  supplier_order_number: string | null
  note_above_items: string | null
  note_below_items: string | null
  revenue_category_id: number | null
  revenue_category_label?: string | null
  revenue_category_code?: string | null
  /** Klasifikace plnění pro DPHDP3/KH nastavená na hlavičce (položky mohou mít vlastní). */
  vat_classification_code?: string | null
  // Cross-link na související doklady (z find()): u proformy → vystavený daňový doklad;
  // u dokladu s parent_invoice_id → rodič (proforma / původní faktura).
  final_invoice?: { id: number; varsymbol: string | null; status: InvoiceStatus } | null
  parent_invoice?: { id: number; varsymbol: string | null; status: InvoiceStatus; invoice_type: InvoiceType } | null
  // U daňového dokladu bez vazby: existují u odběratele nespárované zálohy k propojení?
  has_advance_candidates?: boolean
  // U nepropojené proformy: existují u odběratele nepropojené daňové doklady k propojení?
  has_final_candidates?: boolean
  recurring_template_id: number | null
  advance_paid_amount: number
  discount_percent: number
  payment_method: PaymentMethod
  /**
   * Hotovostní vyrovnání (migrace 1327): pokladna, do které se doklad inkasuje.
   * Platí jen s `payment_method = 'cash'`; při vystavení z ní vznikne zaúčtovaný
   * PPD a faktura je uhrazená. NULL = bez vyrovnání.
   */
  cash_register_id: number | null
  auto_send_reminders: boolean
  amount_to_pay: number
  /** Suma evidovaných plateb (#89); zbývá k úhradě = amount_to_pay - paid_total. */
  paid_total: number
  payment_status?: PaymentStatus | null
  total_without_vat: number
  total_vat: number
  total_with_vat: number
  rounding: number
  status: InvoiceStatus
  approval_status: ApprovalStatus
  approval_token: string | null
  approval_token_expires_at: string | null
  approval_requested_at: string | null
  approval_decided_at: string | null
  approval_decided_by_email: string | null
  approval_rejection_reason: string | null
  approval_reminder_at: string | null
  approval_reminder_count: number
  project_requires_approval?: boolean
  /** Token trvalého veřejného odkazu „web faktura" (/invoice/{token}); null dokud odkaz nevznikl. */
  public_token: string | null
  /** Poslední zobrazení web faktury klientem (anonymní přístup); null = zatím nezobrazeno. */
  public_viewed_at: string | null
  sent_at: string | null
  last_reminder_at: string | null
  reminder_count: number
  paid_at: string | null
  booked_at?: string | null
  cancelled_at: string | null
  pdf_path: string | null
  /** Zdrojové PDF z importu (iDoklad/Fakturoid) — oddělené od našeho rendered `pdf_path`. */
  imported_pdf_path: string | null
  imported_pdf_original_name?: string | null
  imported_pdf_size_bytes?: number | string | null
  created_at: string
  updated_at: string
  /** Výsledek děkovného e-mailu (issue #57) — vrací mark-paid, jen když se odesílalo. */
  payment_thanks?: { status: 'sent' | 'skipped' | 'failed'; reason?: string; recipients?: string[] } | null
  client_company_name?: string
  client_main_email?: string
  client_ic?: string | null
  client_dic?: string | null
  client_language?: 'cs' | 'en'
  client_currency_default?: string
  client_currency_default_id?: number
  client_reverse_charge?: boolean
  project_name?: string | null
  project_hourly_rate?: number | null
  project_payment_due_days?: number | null
  currency_symbol?: string
  currency_decimals?: number
  bank_account_number?: string | null
  bank_code?: string | null
  bank_name?: string | null
  bank_iban?: string | null
  bank_bic?: string | null
  project_billing_emails?: Array<{ email: string; label: string | null }>
  items: InvoiceItem[]
  vat_breakdown: VatBreakdownRow[]
  totals: InvoiceTotals
  exchange_rate?: number | null
  exchange_rate_date?: string | null
  czk_recap?: CzkRecap | null
  /** Zámek dokladu (F6) — jediný zdroj pravdy je BE, FE nic nedopočítává. Optional = BC. */
  locked?: DocumentLock
  _meta?: {
    exchange_rate?: ExchangeRateMeta
  }
  /** Non-blocking varování z create/update (kódy → t('invoice.warning.<code>')). */
  _warnings?: string[]
  /**
   * Karty majetku (1177), které se po vystavení nepodařilo uzavřít — faktura je platná,
   * ale kartu musí účetní dořešit ručně. `message` je adresný důvod z backendu.
   */
  asset_sale_warnings?: Array<{ code: string; name: string; message: string }>
  /** Detaily k `_warnings` kódům pro interpolaci v UI (§C kurz vs ČNB). */
  _warning_meta?: {
    exchange_rate_cnb_deviation?: CnbRateDeviationMeta
    oss_document_contradiction?: OssDocumentContradictionMeta
  }
  /** Výsledek hotovostního vyrovnání z posledního uložení/vystavení (migrace 1327). */
  _cash_settlement?: CashSettlementResult
}

/**
 * Hotovostní vyrovnání faktury (migrace 1327) — co backend s pokladním dokladem
 * udělal při posledním uložení. `reason` je strojový kód pro
 * `t('cash_settlement.reason.<reason>')`, u `failed` může být i chybový kód pokladny.
 */
export interface CashSettlementResult {
  status: 'noop' | 'created' | 'removed' | 'unchanged' | 'skipped' | 'failed'
  cash_document_id: number | null
  doc_number: string | null
  reason: string | null
  message?: string
}

/**
 * Doklad rozpadlý mezi OSS podání a tuzemské přiznání. Hláška v UI je bez parametrů
 * (`invoice.warning.oss_document_contradiction`), tohle je strojově čitelný detail
 * pro klienty API a pro budoucí adresnější zobrazení.
 */
export interface OssDocumentContradictionMeta {
  consumer_countries: string[]
  domestic_rates: string[]
  domestic_country: string
  affected_items: number
  message: string
}

/** Detail odchylky účetního kurzu na dokladu od denního ČNB kurzu (§C / K4). */
export interface CnbRateDeviationMeta {
  used_rate: number
  cnb_rate: number
  cnb_rate_date: string
  diff_percent: number
}

export interface CzkRecap {
  rate: number
  rate_date: string
  fallback_used: boolean
  breakdown: Array<{
    rate: number
    base_czk: number
    vat_czk: number
    with_vat_czk: number
  }>
  total_without_vat_czk: number
  total_vat_czk: number
  total_with_vat_czk: number
}

export interface ExchangeRateMeta {
  currency: string
  rate: number
  rate_date: string
  fallback_used: boolean
  source: 'cache' | 'fresh' | 'last_known' | 'fixed'
  /** Firma je v pevném kurzovém režimu (§24/7), ale pro toto období/měnu není
   *  pevný kurz nastavený — doklad se přesto uložil s denním kurzem ČNB. */
  fixed_missing?: boolean
}

export interface InvoiceListItem {
  id: number
  varsymbol: string | null
  invoice_type: InvoiceType
  parent_invoice_id: number | null
  recurring_template_id?: number | null
  client_id: number
  project_id: number | null
  issue_date: string
  tax_date: string | null
  due_date: string
  currency_id?: number
  currency: string
  total_without_vat: number
  total_vat: number
  total_with_vat: number
  advance_paid_amount: number
  amount_to_pay: number
  paid_total?: number
  payment_status?: PaymentStatus | null
  status: InvoiceStatus
  payment_method: PaymentMethod
  /** Kurz CZK / 1 jednotka měny dokladu; null u CZK dokladů. */
  exchange_rate?: number | null
  sent_at: string | null
  last_reminder_at: string | null
  reminder_count: number
  paid_at: string | null
  cancelled_at: string | null
  booked_at?: string | null
  client_company_name: string
  project_name: string | null
  project_requires_approval?: boolean
  has_work_report?: boolean
  /**
   * Doklad má řádek k ručnímu posouzení místa plnění MIMO OSS — tiše vstupuje na
   * ř. 1/2 tuzemského přiznání. Otázka zní „patří sem vůbec?".
   */
  oss_review_domestic?: boolean
  /**
   * Doklad má řádek k ručnímu posouzení místa plnění V OSS podání. Otázka zní
   * „sedí země a typ sazby, nebo to patří do tuzemska?".
   */
  oss_review_oss?: boolean
  month_bucket: string
  /** Zámek dokladu (F6) — jediný zdroj pravdy je BE, FE nic nedopočítává. Optional = BC. */
  locked?: DocumentLock
}

export interface MonthGroup {
  month: string
  count: number
  totals_per_currency: Array<{
    currency: string
    without_vat: number
    vat: number
    with_vat: number
    /** Predikce — součet konceptů (draft) vystavených faktur/dobropisů v měsíci. */
    draft_without_vat: number
    draft_vat: number
    draft_with_vat: number
  }>
  invoices: InvoiceListItem[]
}

export interface InvoicePayload {
  invoice_type?: InvoiceType
  client_id: number
  project_id?: number | null
  branding_profile_id?: number | null
  issue_date?: string
  tax_date?: string | null
  due_date?: string
  currency_id?: number
  reverse_charge?: boolean
  /** § 30 ZDPH; server ověří limit i výjimky § 30 odst. 2. */
  is_simplified?: boolean
  /** § 31/31a ZDPH; chybějící klíč rozpis NEMĚNÍ, prázdné pole ho smaže. */
  payment_schedule?: PaymentScheduleRow[]
  prices_include_vat?: boolean
  income_tax_exempt?: boolean
  income_tax_exempt_reason?: string | null
  language?: 'cs' | 'en'
  supplier_order_number?: string | null
  note_above_items?: string | null
  note_below_items?: string | null
  advance_paid_amount?: number
  discount_percent?: number
  payment_method?: PaymentMethod
  /**
   * Hotovostní vyrovnání (migrace 1327) — pokladna pro inkaso hotově.
   * Zapisuje se jen když je klíč přítomen; null volbu i dřív založený PPD zruší.
   */
  cash_register_id?: number | null
  auto_send_reminders?: boolean
  exchange_rate?: number | null
  // Volitelný ruční override čísla faktury (varsymbol). Prázdný řetězec / null =
  // generuje se automaticky při issue dle supplier templatu (Settings → Číslování faktur)
  // s fallbackem na cfg.varsymbol.templates. Backend ho akceptuje jen u draftu;
  // po vystavení je číslo immutable (snapshot).
  varsymbol?: string | null
  vat_classification_code?: string | null
  revenue_category?: string | null
  revenue_category_id?: number | null
  items: Array<{
    description: string
    quantity: number
    unit: string
    unit_price_without_vat: number
    vat_rate_id: number
    order_index: number
    stock_item_id?: number | null
    warehouse_id?: number | null
    small_asset_id?: number | null
    asset_id?: number | null
    oss_applicable?: boolean
    oss_consumer_country?: string | null
    oss_rate_type?: string | null
    oss_supply_type?: 'goods' | 'services' | null
    oss_exchange_rate?: number | null
    oss_exchange_rate_date?: string | null
    oss_taxable_amount_return?: number | null
    oss_vat_amount_return?: number | null
    oss_original_period?: string | null
    /** Round-trip příznaku „místo plnění systém neurčil" — viz InvoiceItem. */
    oss_needs_manual_review?: boolean
  }>
}

/**
 * Rozsah filtru „nejisté místo plnění (OSS)". Sdílený s BE
 * (`VatLedgerService::MANUAL_REVIEW_SCOPES`).
 */
export type OssReviewScope = 'any' | 'oss' | 'domestic'

export interface ListFilters {
  status?: string | string[]
  type?: string | string[]
  client_id?: number
  project_id?: number
  year?: number
  month?: number
  date_from?: string
  date_to?: string
  currency?: string
  unpaid_only?: boolean
  overdue?: boolean
  /**
   * Neuhrazené K DATU X (YYYY-MM-DD) — na rozdíl od `unpaid_only` (dnešní status) jde
   * o stav, jaký doklad měl k historickému dni: vystavený do X, u kterého k X nebyl
   * uhrazen celý `amount_to_pay`. Stejná definice jako v Saldokontu (BE zdroj pravdy
   * je společný), ať si sestavy neodporují.
   */
  unpaid_as_of?: string
  /** Zaúčtování (jen podvojné účetnictví): '1' = zaúčtováno, '0' = nezaúčtováno. */
  booked?: '1' | '0'
  /**
   * Jen doklady s řádkem k ručnímu posouzení místa plnění (OSS). Rozsah říká, kde
   * řádek leží — každý konec se řeší jinak:
   *  - `domestic` = mimo OSS; jde na ř. 1/2 tuzemského přiznání, ale nikdo nepotvrdil,
   *    že tam patří,
   *  - `oss` = v OSS podání, ale s otazníkem nad zemí nebo typem sazby,
   *  - `any` = obojí.
   */
  oss_review?: OssReviewScope
  /**
   * Kategorie tržby — ponechat JEN vybrané. Hodnoty jsou ID číselníku, sentinel
   * `none` znamená doklad bez kategorie (NULL se do IN/NOT IN nechytá). Cizí ID
   * backend zahodí; zbude-li po tom prázdno, vrátí prázdný seznam, ne „bez filtru".
   */
  revenue_category_id?: string[]
  /**
   * Kategorie tržby — vybrané SKRÝT (typicky drobné faktury za předplatné). Doklady
   * bez kategorie zůstávají, dokud se nevyloučí i sentinel `none`.
   */
  revenue_category_exclude?: string[]
  q?: string
  page?: number
  per_page?: number
}

export interface InvoiceListMeta {
  total: number
  page?: number
  per_page?: number
  pages?: number
}

// ── Hromadné nastavení OSS (OSS-7) ────────────────────────────────────────────

/** Které položky vybraných dokladů se berou. */
export type OssBulkScope = 'needs_review' | 'missing_rate_type' | 'oss' | 'all'

export interface OssBulkSet {
  oss_applicable?: boolean | null
  oss_consumer_country?: string | null
  oss_rate_type?: string | null
  oss_supply_type?: 'goods' | 'services' | null
  clear_needs_review?: boolean
}

export interface OssBulkItemChange {
  item_id: number
  description: string
  from: Record<string, unknown>
  to: Record<string, unknown>
}

export interface OssBulkDocument {
  invoice_id: number
  varsymbol: string | null
  status: string | null
  tax_date: string | null
  action: 'update' | 'skip'
  /** null u `action = 'update'`; jinak strojový důvod pro překlad hlášky. */
  skip_reason: string | null
  skip_detail: string | null
  items_matched: number
  changes: OssBulkItemChange[]
  /** Varování z číselníku sazeb členských států — nebrání zápisu. */
  warnings: string[]
  /**
   * Položky, kterým „k ručnímu posouzení" zůstane rozsvícené i přes `clear_needs_review`
   * — příznak tam drží ROZPOR celého dokladu, který hromadná akce nezhojí.
   */
  keep_review: string[]
}

export interface OssBulkResult {
  applied: boolean
  scope: OssBulkScope
  set: OssBulkSet
  documents: OssBulkDocument[]
  summary: {
    documents_total: number
    documents_to_change: number
    documents_skipped: number
    items_to_change: number
    warnings: number
    skipped_by_reason: Record<string, number>
  }
  /** Jen u provedení (`applied`), ne u náhledu. */
  completed_invoice_ids?: number[]
  /** Doklady, u kterých se nepovedlo zahodit cache PDF — data jsou zapsaná, PDF je stará. */
  pdf_not_invalidated?: number[]
}

/**
 * Tělo chyby `bulk_update_failed` (HTTP 500). Dávka se u první chyby zastaví, takže
 * uživatel potřebuje vědět, co je hotové a co se ani nezkusilo — bez toho je u 200
 * dokladů opakování akce sázka do loterie.
 */
export interface OssBulkFailure {
  completed_invoice_ids: number[]
  failed_invoice: { invoice_id: number; varsymbol: string | null }
  not_attempted_invoice_ids: number[]
  pdf_not_invalidated: number[]
}

export const invoicesApi = {
  listGrouped: (filters: ListFilters = {}) => {
    const params: Record<string, string | number> = {}
    if (filters.q) params.q = filters.q
    if (filters.status) {
      params['filter[status]'] = Array.isArray(filters.status) ? filters.status.join(',') : filters.status
    }
    if (filters.type) {
      params['filter[type]'] = Array.isArray(filters.type) ? filters.type.join(',') : filters.type
    }
    if (filters.client_id)   params['filter[client_id]']   = filters.client_id
    if (filters.project_id)  params['filter[project_id]']  = filters.project_id
    if (filters.year)        params['filter[year]']        = filters.year
    if (filters.month)       params['filter[month]']       = filters.month
    if (filters.date_from)   params['filter[date_from]']   = filters.date_from
    if (filters.date_to)     params['filter[date_to]']     = filters.date_to
    if (filters.currency)    params['filter[currency]']    = filters.currency
    if (filters.unpaid_only) params['filter[unpaid_only]'] = 1
    if (filters.overdue)     params['filter[overdue]']     = 1
    if (filters.unpaid_as_of) params['filter[unpaid_as_of]'] = filters.unpaid_as_of
    if (filters.booked)      params['filter[booked]']      = filters.booked
    if (filters.oss_review)  params['filter[oss_review]']  = filters.oss_review
    if (filters.revenue_category_id?.length) {
      params['filter[revenue_category_id]'] = filters.revenue_category_id.join(',')
    }
    if (filters.revenue_category_exclude?.length) {
      params['filter[revenue_category_exclude]'] = filters.revenue_category_exclude.join(',')
    }
    if (filters.page)        params.page                   = filters.page
    if (filters.per_page)    params.per_page               = filters.per_page
    return api.get<{ data: MonthGroup[]; meta: InvoiceListMeta }>('/invoices', { params }).then(r => r.data)
  },

  /**
   * Plochý seznam OTEVŘENÝCH (nezaplacených) vystavených faktur a proforem pro picker
   * (např. kotva sloučené úhrady v bankovním párování). Vrací max `limit` položek
   * seřazených dle splatnosti. Hledá fulltextem (varsymbol + jméno klienta).
   */
  searchOpen: (q: string, limit = 20): Promise<InvoiceListItem[]> =>
    invoicesApi.listGrouped({
      q,
      status: ['issued', 'sent', 'reminded'],
      type: ['invoice', 'proforma'],
      unpaid_only: true,
      per_page: limit,
    }).then(r => r.data.flatMap(g => g.invoices)),

  /**
   * Jako searchOpen, ale vč. ZAPLACENÝCH faktur — pro kotvu sloučené úhrady, kde
   * spárování zaplacené faktury znamená rekonciliaci existující platby (proto musí
   * jít vybrat i 'paid'). Bez unpaid_only, status zahrnuje 'paid'.
   */
  searchMatchable: (q: string, limit = 20): Promise<InvoiceListItem[]> =>
    invoicesApi.listGrouped({
      q,
      status: ['issued', 'sent', 'reminded', 'paid'],
      type: ['invoice', 'proforma'],
      per_page: limit,
    }).then(r => r.data.flatMap(g => g.invoices)),

  exportSelectedPdf: (ids: number[], signPdf = false) =>
    api.get<Blob>('/invoices/export.pdf', {
      params: {
        ids: ids.join(','),
        ...(signPdf ? { sign_pdf: 1 } : {}),
      },
      responseType: 'blob',
    }),

  get:    (id: number) => api.get<Invoice>(`/invoices/${id}`).then(r => r.data),
  /**
   * Vrátí náhled, jaké číslo faktura dostane při Vystavení (BEZ inkrementu counteru).
   * Používá se v editoru jako placeholder „automaticky: JD2026-01".
   */
  previewVarsymbol: (
    type: 'invoice' | 'proforma' | 'credit_note' | 'payment_calendar',
    issueDate: string,
    clientId?: number,
    revenueCategoryId?: number,
  ) =>
    api.get<{ varsymbol: string; has_template: boolean }>(
      `/invoices/preview-varsymbol`,
      {
        params: {
          type,
          issue_date: issueDate,
          ...(clientId ? { client_id: clientId } : {}),
          ...(revenueCategoryId ? { revenue_category_id: revenueCategoryId } : {}),
        },
      },
    ).then(r => r.data),
  create: (payload: InvoicePayload) => api.post<Invoice>('/invoices', payload).then(r => r.data),
  update: (id: number, payload: InvoicePayload, force = false) =>
    api.put<Invoice>(`/invoices/${id}${force ? '?force=1' : ''}`, payload).then(r => r.data),
  /**
   * Smazání faktury. Pro draft kdokoliv ≥ accountant, pro vystavené/zaplacené/stornované jen admin.
   * Vrací `cascade_deleted` = počet navazujících dokladů (storno, dobropis), které byly
   * smazány zároveň přes ON DELETE CASCADE (migrace 0015).
   */
  delete: (id: number, force = false) =>
    api.delete<{ ok: boolean; cascade_deleted: number }>(`/invoices/${id}`, {
      params: force ? { force: 1 } : undefined,
    }).then(r => r.data),

  // Akce nad fakturou
  issue:    (id: number) => api.post<Invoice>(`/invoices/${id}/issue`).then(r => r.data),
  markPaid: (id: number, paidAt?: string, opts?: { sendThanks?: boolean; thanksTrigger?: 'manual' | 'bulk' }) =>
    api.post<Invoice>(`/invoices/${id}/mark-paid`, {
      paid_at: paidAt || appIsoDate(),
      ...(opts?.sendThanks ? { send_payment_thanks: true, thanks_trigger: opts.thanksTrigger || 'manual' } : {}),
    }).then(r => r.data),
  unmarkPaid: (id: number) =>
    api.post<Invoice>(`/invoices/${id}/unmark-paid`, {}).then(r => r.data),
  /**
   * Obnoví snapshoty stran (dodavatel/odběratel/banka) ze živých dat i u uzamčeného
   * dokladu — částky, stav ani číslo se nemění. Jen admin. Na výkazy DPH to vliv
   * nemá (ty čtou živou tabulku klientů), projeví se v PDF a v exportech.
   */
  rebuildSnapshots: (id: number) =>
    api.post<Invoice>(`/invoices/${id}/rebuild-snapshots`, {}).then(r => r.data),
  // Evidence plateb / částečné úhrady (#89)
  listPayments: (id: number) =>
    api.get<InvoicePaymentsResponse>(`/invoices/${id}/payments`).then(r => r.data),
  createPayment: (id: number, payload: {
    amount: number
    paid_on?: string
    variable_symbol?: string | null
    bank_reference?: string | null
    note?: string | null
    send_payment_thanks?: boolean
  }) =>
    api.post<{
      invoice: Invoice
      payments: InvoicePayment[]
      payment: InvoicePayment
      became_paid: boolean
      remaining: number
      payment_thanks?: { status: 'sent' | 'skipped' | 'failed'; reason?: string } | null
    }>(`/invoices/${id}/payments`, payload).then(r => r.data),
  deletePayment: (id: number, paymentId: number) =>
    api.delete<{ invoice: Invoice; payments: InvoicePayment[]; became_unpaid: boolean; remaining: number }>(
      `/invoices/${id}/payments/${paymentId}`,
    ).then(r => r.data),
  // Daňový doklad k přijaté platbě (DUZP = datum platby) — DRAFT, idempotentní
  createPaymentTaxDocument: (id: number, paymentId: number) =>
    api.post<{ tax_document_id: number; payments: InvoicePayment[] }>(
      `/invoices/${id}/payments/${paymentId}/tax-document`,
    ).then(r => r.data),
  cancel: (id: number, mode: 'internal' | 'credit_note', reason: string = '') =>
    api.post<{ cancellation_id?: number; credit_note_id?: number; edit_url?: string; invoice?: Invoice }>(
      `/invoices/${id}/cancel`,
      { mode, reason },
    ).then(r => r.data),
  issueFinal: (proformaId: number, opts?: { tax_date?: string; due_date?: string; advance_paid_amount?: number | null }) =>
    api.post<{ final_invoice_id: number; edit_url: string; invoice: Invoice }>(
      `/invoices/${proformaId}/issue-final`,
      opts || {},
    ).then(r => r.data),
  // Zpětné propojení daňového dokladu se zálohovou fakturou (proforma)
  advanceCandidates: (id: number) =>
    api.get<{ candidates: AdvanceCandidate[] }>(`/invoices/${id}/advance-candidates`).then(r => r.data.candidates),
  // Opačný směr — z detailu zálohy nabídni nepropojené daňové doklady téhož odběratele
  finalCandidates: (id: number) =>
    api.get<{ candidates: AdvanceCandidate[] }>(`/invoices/${id}/final-candidates`).then(r => r.data.candidates),
  linkAdvance: (id: number, advanceId: number) =>
    api.post<Invoice>(`/invoices/${id}/link-advance`, { advance_id: advanceId }).then(r => r.data),
  unlinkAdvance: (id: number) =>
    api.delete<Invoice>(`/invoices/${id}/link-advance`).then(r => r.data),
  clone: (id: number, opts?: { increment_month_in_descriptions?: boolean; issue_date?: string }) =>
    api.post<{ draft_id: number }>(`/invoices/${id}/clone`, opts || {}).then(r => r.data),
  bulkReissue: (invoiceIds: number[], opts?: { increment_month_in_descriptions?: boolean; issue_date?: string }) =>
    api.post<{ created: Array<{ source_id: number; draft_id: number }>; errors: Array<{ source_id: number; error: string }> }>(
      '/invoices/bulk-reissue',
      { invoice_ids: invoiceIds, ...opts },
    ).then(r => r.data),

  /**
   * Hromadné nastavení OSS (OSS-7). Náhled je povinný — provedení bez `confirm: true`
   * backend odmítne (428), takže UI nesmí volat `bulkOssApply` bez předchozího náhledu.
   */
  bulkOssPreview: (invoiceIds: number[], scope: OssBulkScope, set: OssBulkSet) =>
    api.post<OssBulkResult>('/invoices/bulk-oss/preview',
      { invoice_ids: invoiceIds, scope, set }).then(r => r.data),
  bulkOssApply: (invoiceIds: number[], scope: OssBulkScope, set: OssBulkSet) =>
    api.post<OssBulkResult>('/invoices/bulk-oss',
      { invoice_ids: invoiceIds, scope, set, confirm: true }).then(r => r.data),

  pdfUrl: (id: number, download: boolean = false) => {
    // Přímá navigace v prohlížeči neposílá X-Supplier-Id header (na rozdíl od axios) —
    // proto přidáváme supplier_id jako query param. Middleware ho přečte jako fallback.
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (download) params.set('download', '1')
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/invoices/${id}/pdf${qs ? '?' + qs : ''}`
  },

  importedPdfUrl: (id: number, inline: boolean = false) => {
    // Přímá navigace / iframe / <a href> neposílá X-Supplier-Id header (na rozdíl od
    // axios) — proto přidáváme supplier_id jako query param (middleware ho čte jako fallback).
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (inline) params.set('inline', '1')
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/invoices/${id}/imported-pdf${qs ? '?' + qs : ''}`
  },

  listPdfs: (id: number) =>
    api.get<{ items: Array<{
      id: number
      filename: string
      size_bytes: number
      sha256: string
      was_sent: boolean
      sent_to: string[] | null
      reason: string
      archived_at: string
    }> }>(`/invoices/${id}/pdfs`).then(r => r.data.items),

  archivedPdfUrl: (id: number, archiveId: number, download: boolean = false) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (download) params.set('download', '1')
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/invoices/${id}/pdfs/${archiveId}${qs ? '?' + qs : ''}`
  },

  send: (id: number, payload?: { to?: string[]; cc?: string[]; bcc?: string[]; subject_override?: string | null; note?: string }) =>
    api.post<{ sent_to: string[]; cc: string[]; bcc: string[]; sent_at: string; is_test: false }>(
      `/invoices/${id}/send`,
      payload || {},
    ).then(r => r.data),

  /** Vyřešení příjemci dle kontaktů klienta / e-mailů zakázky (#86) — pro prefill modalu s provenancí. */
  recipients: (id: number, type: 'documents' | 'reminders' | 'approvals' = 'documents') =>
    api.get<{
      type: string
      to: string[]
      cc: string[]
      bcc: string[]
      resolved: Array<{ email: string; recipient: 'to' | 'cc' | 'bcc'; source: 'contact' | 'project' | 'main_email'; usage: string | null; label: string | null }>
    }>(`/invoices/${id}/recipients`, { params: { type } }).then(r => r.data),

  sendReminder: (id: number) =>
    api.post<{ invoice: Invoice; sent_to: string[]; days_overdue: number; sent_at: string }>(
      `/invoices/${id}/reminder`,
    ).then(r => r.data),

  bulkSendReminders: (invoiceIds: number[]) =>
    api.post<{
      sent: Array<{ invoice_id: number; sent_to: string[]; days_overdue: number }>;
      errors: Array<{ invoice_id: number; error: string }>;
    }>('/invoices/bulk-reminder', { invoice_ids: invoiceIds }).then(r => r.data),

  // Penalizace — úrok z prodlení (NV 351/2013)
  penaltyPreview: (id: number, params: { as_of?: string; principal?: number } = {}) =>
    api.get<PenaltyPreview>(`/invoices/${id}/penalty/preview`, { params }).then(r => r.data),

  createPenalty: (id: number, body: { as_of?: string; principal?: number } = {}) =>
    api.post<Invoice>(`/invoices/${id}/penalty`, body).then(r => r.data),

  activity: (id: number) =>
    api.get<Array<{
      id: number; user_id: number | null; user_email: string | null; user_name: string | null;
      action: string; payload: Record<string, unknown> | null; ip: string | null; created_at: string;
    }>>(`/invoices/${id}/activity`).then(r => r.data),

  sendTest: (id: number) =>
    api.post<{ sent_to: string[]; sent_at: string; is_test: true }>(
      `/invoices/${id}/send-test`,
      {},
    ).then(r => r.data),

  sendTestReminder: (id: number) =>
    api.post<{ sent_to: string[]; sent_at: string; days_overdue: number; is_test: true }>(
      `/invoices/${id}/reminder-test`,
      {},
    ).then(r => r.data),

  // Schvalování výkazu zákazníkem
  requestApproval: (id: number) =>
    api.post<{ sent_to: string[]; sent_at: string; invoice: Invoice }>(
      `/invoices/${id}/request-approval`,
      {},
    ).then(r => r.data),

  requestApprovalTest: (id: number) =>
    api.post<{ sent_to: string[]; sent_at: string; is_test: true }>(
      `/invoices/${id}/request-approval-test`,
      {},
    ).then(r => r.data),

  // Web faktura — trvalý veřejný odkaz (ensure = idempotentní vytvoření + URL)
  publicLink: (id: number) =>
    api.post<{ url: string; token: string; public_viewed_at: string | null }>(
      `/invoices/${id}/public-link`,
      {},
    ).then(r => r.data),

  regeneratePublicLink: (id: number) =>
    api.post<{ url: string; token: string; public_viewed_at: null }>(
      `/invoices/${id}/public-link/regenerate`,
      {},
    ).then(r => r.data),

  updateApprovalStatus: (id: number, status: ApprovalStatus, rejectionReason?: string) =>
    api.put<{
      invoice: Invoice
      auto_send?: { issued: boolean; sent_to: string[]; varsymbol: string | null }
      auto_send_error?: string
    }>(
      `/invoices/${id}/approval-status`,
      { status, rejection_reason: rejectionReason || null },
    ).then(r => r.data),

  // Volitelné přílohy k dokladu (přibalí se při odeslání faktury / proformy / dobropisu)
  listAttachments: (invoiceId: number) =>
    api.get<{ items: InvoiceAttachment[] }>(`/invoices/${invoiceId}/attachments`).then(r => r.data.items),
  uploadAttachments: (invoiceId: number, files: File[]) => {
    const fd = new FormData()
    for (const f of files) fd.append('file[]', f, f.name)
    return api.post<{ created: number[]; items: InvoiceAttachment[]; total_size: number }>(
      `/invoices/${invoiceId}/attachments`,
      fd,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data)
  },
  deleteAttachment: (invoiceId: number, attachmentId: number) =>
    api.delete<{ deleted: number }>(`/invoices/${invoiceId}/attachments/${attachmentId}`).then(r => r.data),
  attachmentUrl: (invoiceId: number, attachmentId: number, download: boolean = false) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (download) params.set('download', '1')
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/invoices/${invoiceId}/attachments/${attachmentId}${qs ? '?' + qs : ''}`
  },

  // Work report (výkaz víceprací)
  getWorkReport: (invoiceId: number) =>
    api.get<WorkReport | null>(`/invoices/${invoiceId}/work-report`).then(r => r.data),
  saveWorkReport: (invoiceId: number, payload: WorkReportPayload, force = false) =>
    api.put<WorkReport>(`/invoices/${invoiceId}/work-report`, payload, {
      params: force ? { force: 1 } : undefined,
    }).then(r => r.data),
  saveWorkReportMaterials: (invoiceId: number, payload: WorkReportMaterialsPayload, force = false) =>
    api.put<WorkReport>(`/invoices/${invoiceId}/work-report/materials`, payload, {
      params: force ? { force: 1 } : undefined,
    }).then(r => r.data),
  deleteWorkReport: (invoiceId: number, force = false) =>
    api.delete<{ deleted: true }>(`/invoices/${invoiceId}/work-report`, {
      params: force ? { force: 1 } : undefined,
    }).then(r => r.data),
}

export interface InvoiceAttachment {
  id: number
  invoice_id: number
  filename: string
  original_name: string
  size_bytes: number
  sha256: string
  mime_type: string
  uploaded_by: number | null
  uploaded_at: string
}

export interface WorkReportItem {
  id?: number
  description: string
  work_date?: string | null
  hours: number
  rate: number
  total_amount?: number
  order_index: number
}

export interface WorkReportMaterial {
  id?: number
  description: string
  quantity: number
  unit: string
  /** Cena/MJ v cenové konvenci dokladu (prices_include_vat). */
  unit_price: number
  total_amount?: number
  order_index: number
}

export interface WorkReport {
  id: number
  invoice_id: number
  project_id: number | null
  title: string
  total_hours: number
  total_amount: number
  /** Sazba DPH práce (12/21); null = fallback default faktury. */
  vat_rate_id: number | null
  material_title: string | null
  material_total: number
  material_vat_rate_id: number | null
  items: WorkReportItem[]
  materials: WorkReportMaterial[]
}

export interface WorkReportPayload {
  project_id: number | null
  title: string
  vat_rate_id?: number | null
  items: Array<{
    description: string
    work_date?: string | null
    hours: number
    rate: number
    order_index: number
  }>
}

export interface WorkReportMaterialsPayload {
  project_id: number | null
  material_title: string
  material_vat_rate_id: number | null
  materials: Array<{
    description: string
    quantity: number
    unit: string
    unit_price: number
    order_index: number
  }>
}
