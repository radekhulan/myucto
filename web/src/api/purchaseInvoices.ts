import { api } from './client'
import type { DocumentLock } from './locks'
import type { DocItem } from './documents'
import type { CashSettlementResult, CnbRateDeviationMeta, PaymentMethod, PaymentMethodSource } from './invoices'
import { appIsoDate } from '@/utils/date'

export type PurchaseInvoiceStatus = 'draft' | 'received' | 'booked' | 'paid' | 'cancelled'
export type PurchaseDocumentKind = 'invoice' | 'receipt' | 'credit_note' | 'advance' | 'tax_document'
export type StructuredPurchaseImportSource = 'isdoc' | 'isdocx' | 'isdoc_embedded'

export interface StructuredPurchaseImportResult {
  purchase_invoice_id: number
  purchase_invoice_ids: number[]
  source: StructuredPurchaseImportSource
  duplicate: boolean
}

/**
 * Druhy dokladů přijaté faktury pro výběr v editoru (pořadí = pořadí v dropdownu).
 * `tax_document` = DDKP „daňový doklad k poskytnuté záloze" (§28 ZDPH) — účtuje jen DPH
 * (343/314), do nákladů nevstupuje, váže se na uhrazenou zálohu přes `parent_purchase_invoice_id`.
 * Label se bere přes i18n `purchase_invoice.document_kind.<kind>`.
 */
export const PURCHASE_DOCUMENT_KINDS: PurchaseDocumentKind[] =
  ['invoice', 'receipt', 'credit_note', 'advance', 'tax_document']
/**
 * Kdo kurz na dokladu nastavil (migrace 1303). Rozhoduje o tom, jestli ho smí přepsat
 * automatické přenačtení po změně rozhodného data (DUZP) nebo měny:
 *   - `cnb`, `fixed` = odvozeno z data (denní kurz ČNB / pevný kurz §24/7) → přepíše se
 *   - `import`, `idoklad`, `fakturoid` = přinesl cizí systém nebo doklad dodavatele → nepřepíše
 *   - `manual` = DEPRECATED, „neznámý / historický zápis" → nepřepíše
 *   - `user` = člověk vepsal kurz do formuláře → nepřepíše nikdy
 */
export type ExchangeRateSource = 'cnb' | 'manual' | 'idoklad' | 'fakturoid' | 'fixed' | 'import' | 'user'

/** Kurz, který server po změně rozhodného data / měny sám dosadil. */
export interface ResolvedExchangeRateMeta {
  currency: string
  rate: number
  rate_date: string
  fallback_used: boolean
  source: 'cache' | 'fresh' | 'last_known' | 'fixed'
  fixed_missing: boolean
}

/** Proč se kurz po změně rozhodného data / měny NEpřenačetl. */
export interface ExchangeRateNotReloadedMeta {
  reason: 'source_locked' | 'cnb_unavailable'
  rate: number | null
  rate_date: string | null
  source: ExchangeRateSource
}
/** Provenience platebního účtu pro QR platbu (viz migrace 0107). */
export type PaymentAccountSource = 'isdoc' | 'ai' | 'ai_reextract' | 'qr_image' | 'manual'

/**
 * §DM — druh nákladu na položce. Řídí nákladový účet i evidenci majetku:
 * service=518, material=501 (vč. PHM), small_asset=501 + karta drobného majetku,
 * fixed_asset=042 + odpisy. NULL = neurčeno (chová se jako dosud → 518).
 */
export type ExpenseKind = 'service' | 'material' | 'small_asset' | 'fixed_asset'

/** Odkud návrh přišel: pravidlo tenanta / klíčové slovo / práh §26/2 ZDP / AI. */
export type ExpenseKindSuggestionSource = 'rule' | 'keyword' | 'threshold' | 'ai'

/**
 * §DM — návrh druhu nákladu pro JEDNU položku + PROČ. Důvod není kosmetika: bez něj
 * uživatel nepozná, jestli se návrhu dá věřit, a klikal by naslepo.
 *
 * Návrh nic nemění — `expense_kind` na řádek zapíše až uživatel.
 */
export interface ExpenseKindSuggestion {
  expense_kind: ExpenseKind
  /** 0..1. */
  confidence: number
  /** Lidský důvod, např. „text obsahuje „tablet" ⇒ drobný majetek". */
  reason: string
  source: ExpenseKindSuggestionSource
  /** false → slabý důkaz, patří do fronty „ke kontrole". Nikdy se neaplikuje samo. */
  auto: boolean
  /** Co je na řádku teď — aby UI nenabízelo „použít" tam, kde se nic nezmění. */
  current_expense_kind: ExpenseKind | null
}

export interface ExpenseSuggestionsResponse {
  purchase_invoice_id: number
  /** Klíč = id položky (`PurchaseInvoiceItem.id`). Nové neuložené řádky tu nejsou. */
  items: Record<string, ExpenseKindSuggestion>
}

/** Strukturovaný platební účet dodavatele (pro QR platbu / ruční editaci). */
export interface PaymentQrAccount {
  account_number: string | null
  bank_code: string | null
  iban: string | null
  bic: string | null
  variable_symbol: string | null
}

/** Odpověď „Zaplatit pomocí QR" endpointu. */
export interface PaymentQrResponse {
  ok: boolean
  qr_data_uri: string | null
  source: PaymentAccountSource | null
  amount: number
  currency: string
  account: PaymentQrAccount
  editable: boolean
  needs_account: boolean
  /** true → účet ještě nikdo nezkusil doplnit, frontend smí spustit extract-account. */
  can_extract?: boolean
}

export interface PurchaseInvoiceItem {
  id?: number
  purchase_invoice_id?: number
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
  vat_classification_code?: string | null
  vat_code?: string
  vat_label_cs?: string
  vat_label_en?: string
  /** §DM — druh nákladu. Autoritativní klasifikace, BE z ní odvozuje `is_fixed_asset`. */
  expense_kind?: ExpenseKind | null
  /** §DČR — časové rozlišení nákladu (381): období od–do, do kterého náklad věcně patří. NULL = bez rozlišení. */
  accrual_from?: string | null
  accrual_to?: string | null
  /** Vazba na skladovou kartu (Epic SKLAD) — FE MUSÍ posílat zpět v round-tripu, jinak se DELETE+INSERT tiše smaže. */
  stock_item_id?: number | null
  /** Joined (read-only, jen v GET odpovědi) — pro label vybrané karty po reloadu. */
  stock_sku?: string | null
  stock_name?: string | null
  /** Karta drobného majetku vzniklá z téhle položky (§DM), pokud existuje — read-only join. */
  small_asset?: { id: number; name: string; status: 'in_use' | 'disposed' | 'sold' } | null
}

export interface PurchaseVatBreakdownRow {
  vat_rate: number
  without_vat: number
  vat: number
  with_vat: number
}

/**
 * Ruční override rekapitulace DPH dle dokladu dodavatele (§ 73 ZDPH).
 * Per sazba lze přepsat základ i daň; kalkulátor reziduum zapeče do řádkových totálů.
 */
export interface PurchaseVatOverride {
  rate: number
  base: number
  vat: number
}

export type PurchaseVatAllocationUsage = 'business' | 'personal' | 'mixed' | 'non_deductible'
export type PurchaseVatAllocationTaxTreatment = 'deductible' | 'non_deductible' | 'not_expense'

export interface PurchaseVatAllocation {
  id?: number
  purchase_invoice_id?: number
  description: string
  usage_type: PurchaseVatAllocationUsage
  vat_rate: number
  base_amount: number
  vat_amount: number
  total_amount: number
  vat_deduction: VatDeduction
  vat_deduction_percent: number
  tax_treatment: PurchaseVatAllocationTaxTreatment
  account_code: string
  vat_classification_code?: string | null
  order_index: number
}

export interface PurchaseInvoiceTotals {
  without_vat: number
  vat: number
  with_vat: number
  rounding: number
  advance_paid_amount: number
  amount_to_pay: number
}

export interface VendorSnapshot {
  id?: number
  company_name?: string
  first_name?: string | null
  last_name?: string | null
  ic?: string | null
  dic?: string | null
  street?: string
  city?: string
  zip?: string
  main_email?: string
  phone?: string | null
  language?: 'cs' | 'en'
  country_iso2?: string
  country_name_cs?: string
  country_name_en?: string
}

/** Nárok na odpočet DPH: plný / bez nároku / krácený (poměrný §75, viz vat_deduction_percent). */
export type VatDeduction = 'full' | 'none' | 'proportional' | 'reduced'

/** Stručné shrnutí navázané přijaté faktury (záloha ↔ vyúčtovací). */
export interface PurchaseInvoiceBrief {
  id: number
  varsymbol: string | null
  vendor_invoice_number: string | null
  document_kind: PurchaseDocumentKind | null
  status: PurchaseInvoiceStatus
  issue_date: string | null
  total_with_vat: number
  currency: string
}

export interface AiPostingSuggestion {
  id: number
  source: 'knn' | 'llm'
  payload: {
    debit_account_code: string
    credit_account_code?: string | null
    expense_category_id?: number | null
  }
  confidence: number
  reasoning: string | null
}

/** Bankovní úhrada přijaté faktury — proklik z detailu na příslušný bankovní výpis. */
export interface PurchaseInvoiceBankPayment {
  bank_transaction_id: number
  statement_id: number
  amount: number
  posted_at: string
  counterparty: string | null
  currency: string
  /** Zaúčtování úhrady (deník) — proklik na řádek deníku. */
  journal_entry_id: number
}

/** Hotovostní úhrada přijaté faktury — pokladní doklad (PPD/VPD) POSTED přes journal_entry_id. */
export interface PurchaseInvoiceCashPayment {
  cash_document_id: number
  doc_number: string | null
  amount: number
  date: string
  register_id: number
  register_name: string | null
  /** Zaúčtování úhrady (deník) — proklik na řádek deníku. */
  journal_entry_id: number
  currency: string
}

/** Úhrada zápočtem proti zvolenému účtu (invoice_settlements, migrace 1126). */
export interface PurchaseInvoiceSettlementPayment {
  settlement_id: number
  amount: number
  date: string
  account_code: string
  account_name: string
  note: string | null
  /** Zaúčtování zápočtu (deník); NULL v daňové evidenci. */
  journal_entry_id: number | null
}

export interface PurchaseInvoice {
  id: number
  supplier_id: number
  vendor_id: number
  varsymbol: string | null
  vendor_invoice_number: string
  document_kind: PurchaseDocumentKind
  issue_date: string
  tax_date: string | null
  due_date: string
  received_at: string
  /**
   * Odkud se datum přijetí vzalo (migrace 1037): 'manual' = účetní ho vědomě zadala,
   * 'import' = jen otisk dne importu / AI vytěžení. Řídí, zda datum přijetí vstoupí
   * do období odpočtu podle § 73 odst. 1 písm. a) ZDPH (issue #9).
   */
  received_at_source?: 'manual' | 'import'
  currency_id: number
  currency: string
  currency_symbol?: string
  currency_decimals?: number
  exchange_rate: number | null
  exchange_rate_date: string | null
  exchange_rate_source: ExchangeRateSource
  reverse_charge: boolean
  prices_include_vat?: boolean
  is_fixed_asset: boolean
  /**
   * Plátcovství dodavatele k datu plnění (snapshot na dokladu, migrace 0133). U legacy
   * dokladů (snapshot NULL) backend fallbackuje na živý příznak klienta. Řídí nárok na odpočet.
   */
  vendor_is_vat_payer: boolean
  /** Nárok na odpočet DPH (full=plný, none=bez nároku → mimo DPH evidenci, proportional=krácený §75). */
  vat_deduction: VatDeduction
  /** Procento odpočtu při vat_deduction='proportional' (§75 poměrný; 0–100, default 100). */
  vat_deduction_percent: number
  /** Daňová uznatelnost nákladu pro daň z příjmů (DPFO/DPPO). */
  tax_deductible: boolean
  language: 'cs' | 'en'
  note_above_items: string | null
  note_below_items: string | null
  vendor_snapshot: VendorSnapshot | null
  own_snapshot: Record<string, unknown> | null
  total_without_vat: number
  total_vat: number
  total_with_vat: number
  rounding: number
  advance_paid_amount: number
  amount_to_pay: number
  // Multi-currency platba (USD faktura placená z CZK účtu)
  payment_currency_id: number | null
  payment_currency: string | null
  payment_exchange_rate: number | null
  paid_amount_payment_ccy: number | null
  paid_amount_invoice_ccy: number | null
  exchange_diff_base: number | null
  // Platební účet dodavatele pro „Zaplatit pomocí QR" (migrace 0107)
  payment_account_number: string | null
  payment_bank_code: string | null
  payment_iban: string | null
  payment_bic: string | null
  payment_variable_symbol: string | null
  payment_account_source: PaymentAccountSource | null
  /** Forma úhrady (migrace 1128). `direct_debit` = inkaso → netvoří se platební příkaz. */
  payment_method: PaymentMethod
  payment_method_source: PaymentMethodSource
  /**
   * Hotovostní vyrovnání (migrace 1327): pokladna, ze které se faktura hradí.
   * Platí jen s `payment_method = 'cash'`; při přijetí dokladu z ní vznikne
   * zaúčtovaný VPD a faktura přejde na `paid`. NULL = bez vyrovnání.
   */
  cash_register_id: number | null
  status: PurchaseInvoiceStatus
  booked_at: string | null
  paid_at: string | null
  cancelled_at: string | null
  pdf_path: string | null
  pdf_hash: string | null
  pdf_size_bytes: number | null
  pdf_original_name: string | null
  pdf_uploaded_at: string | null
  /** Strojově čitelný zdrojový originál (ISDOC/ISDOCX/…) — důkazní stopa. */
  source_path: string | null
  source_hash: string | null
  source_size_bytes: number | null
  source_original_name: string | null
  source_format: 'isdoc' | 'isdocx' | 'pdf' | 'pohoda_xml' | 'idoklad_json' | 'fakturoid_json' | null
  source_uploaded_at: string | null
  vat_classification_code: string | null
  expense_category_id: number | null
  /** Název + kód kategorie nákladu (join z expense_categories, jen v detailu). */
  expense_category_label?: string | null
  expense_category_code?: string | null
  /** Zakázka (issue #29) — analytická dimenze nákladu, nezávislá na dodavateli. */
  project_id?: number | null
  project_name?: string | null
  project_number?: string | null
  ai_posting_suggestion?: AiPostingSuggestion | null
  /**
   * Podle čeho se určil druh nákladu a účet (jen detail, migrace 1751).
   * `source: 'rule'` + `rule_id` = rozhodlo firemní pravidlo z Pravidel nákladů;
   * ostatní zdroje pravidlo nemají a UI to musí říct, ne pravidlo předstírat.
   * `rule_exists: false` = pravidlo se od zaúčtování smazalo, stopa zůstala.
   */
  expense_classification?: {
    source: 'rule' | 'catalog' | 'keyword' | 'threshold' | 'ai' | 'mixed' | null
    rule_id: number | null
    rule_name: string | null
    rule_exists: boolean
    rule_is_active: boolean | null
    classified_items: number
    total_items: number
  } | null
  /** Záloha (advance), kterou tato finální faktura vyúčtovává (vazba uložená na finální). */
  advance_purchase_invoice_id: number | null
  /** AI návrh propojení se zálohou (čeká na potvrzení uživatelem). */
  advance_link_suggested_id: number | null
  /** Shrnutí navázané zálohy (pokud advance_purchase_invoice_id != null). */
  linked_advance?: PurchaseInvoiceBrief | null
  /** Dobropis (credit_note): opravovaná přijatá faktura (migrace 1096). */
  parent_purchase_invoice_id?: number | null
  /** Shrnutí opravované faktury (pokud parent_purchase_invoice_id != null). */
  linked_parent?: PurchaseInvoiceBrief | null
  /** Dobropis bez vazby: existuje faktura téhož dodavatele k propojení? */
  has_parent_candidates?: boolean
  /** Reverzní pohled: dobropisy, které tuto fakturu opravují (migrace 1096). */
  corrected_by?: PurchaseInvoiceBrief[] | null
  /** Shrnutí AI-navržené zálohy (suggest & confirm). */
  advance_link_suggestion?: PurchaseInvoiceBrief | null
  /** U zálohy (advance): finální faktura, která ji vyúčtovává (reverzní pohled). */
  settled_by?: PurchaseInvoiceBrief | null
  /** Bankovní úhrady dokladu (POSTED bank zápisy) — proklik na bankovní výpis. */
  bank_payments?: PurchaseInvoiceBankPayment[] | null
  /** Hotovostní úhrady dokladu (POSTED pokladní doklady) — proklik na pokladnu + deník. */
  cash_payments?: PurchaseInvoiceCashPayment[] | null
  /** Úhrady zápočtem proti účtu (355/365 apod.) — proklik na zaúčtování zápočtu. */
  settlement_payments?: PurchaseInvoiceSettlementPayment[] | null
  /**
   * Ručně/legacy uhrazeno (status='paid') BEZ zaúčtované úhrady (banka, pokladna ani
   * zápočet) → závazek 321 zůstává v deníku otevřený. FE zobrazí výrazné upozornění.
   */
  mark_paid_unposted?: boolean
  /** Vyúčtovací faktura bez vazby: existuje nespárovaná záloha téhož dodavatele? */
  has_advance_candidates?: boolean
  /** Záloha bez vyúčtování: existuje nepropojená finální faktura téhož dodavatele? */
  has_settlement_candidates?: boolean
  /**
   * Záloha/DDKP zůstává nespárovaná, ale na 314 je otevřený zůstatek a od stejného
   * dodavatele existuje nespárovaná faktura, která k němu pravděpodobně patří (task #17).
   * `remaining_vat_on_343` je vyplněné jen u DDKP (document_kind='tax_document') — kolik
   * DPH z kandidátní faktury zbývá doúčtovat na 343 nad rámec už uplatněné DPH z DDKP.
   */
  unsettled_notice?: {
    paid_amount: number
    candidate: {
      id: number
      varsymbol: string | null
      vendor_invoice_number: string | null
      total_with_vat: number
    }
    remaining_vat_on_343: number | null
    message: string
  } | null
  /**
   * Diagnostický popis problému z AI extrakce (např. AI sečetla mezisoučty
   * jako další položky → suma řádků se výrazně liší od AI-vráceného totalu).
   * NULL = vše OK / faktura nebyla AI-importována.
   */
  extraction_warning: string | null
  created_by: number
  created_at: string
  updated_at: string
  /**
   * Non-blocking varování z create/update endpointu (kódy překládané přes
   * t('purchase_invoice.warning.<code>')). Např. `credit_note_positive_total`
   * = dobropis má kladný součet (dvojí negace znaménka). Viz issue #35.
   */
  _warnings?: string[]
  /** Detaily k vybraným `_warnings` kódům pro interpolaci v UI (§C kurz vs ČNB). */
  _warning_meta?: {
    exchange_rate_cnb_deviation?: CnbRateDeviationMeta
    exchange_rate_not_reloaded?: ExchangeRateNotReloadedMeta
  }
  /** Metadata k serverem provedenému přenačtení kurzu (migrace 1303). */
  _meta?: {
    exchange_rate?: ResolvedExchangeRateMeta
  }
  /** Výsledek hotovostního vyrovnání z posledního uložení (migrace 1327). */
  _cash_settlement?: CashSettlementResult
  // Joined fields
  vendor_company_name?: string
  vendor_ic?: string | null
  vendor_dic?: string | null
  vendor_main_email?: string
  vendor_language?: 'cs' | 'en'
  /** Ruční rekapitulace DPH dle dokladu (§ 73). NULL = počítá se standardně. */
  vat_overrides: PurchaseVatOverride[] | null
  /** Volitelné rozdělení rekapitulace DPH na účetní a odpočtové režimy. */
  vat_allocations: PurchaseVatAllocation[]
  // Related
  items: PurchaseInvoiceItem[]
  vat_breakdown: PurchaseVatBreakdownRow[]
  totals: PurchaseInvoiceTotals
  /** Zámek dokladu (F6) — jediný zdroj pravdy je BE, FE nic nedopočítává. Optional = BC. */
  locked?: DocumentLock
}

export interface PurchaseInvoiceListItem {
  id: number
  supplier_id: number
  vendor_id: number
  varsymbol: string | null
  vendor_invoice_number: string
  document_kind: PurchaseDocumentKind
  issue_date: string
  tax_date: string | null
  due_date: string
  received_at: string
  currency_id: number
  currency: string
  currency_symbol?: string
  currency_decimals?: number
  exchange_rate: number | null
  exchange_rate_date: string | null
  total_without_vat: number
  total_vat: number
  total_with_vat: number
  advance_paid_amount: number
  amount_to_pay: number
  status: PurchaseInvoiceStatus
  booked_at: string | null
  paid_at: string | null
  cancelled_at: string | null
  /** Nárok na odpočet DPH (full/none/proportional) — pro doplňkový sloupec listu. */
  vat_deduction?: VatDeduction
  vat_deduction_percent?: number
  tax_deductible?: boolean
  /** Název + kód kategorie nákladu (LEFT JOIN expense_categories v list SELECTu). */
  expense_category_label?: string | null
  expense_category_code?: string | null
  vendor_company_name: string
  vendor_ic: string | null
  month_bucket: string
  /** §DM — aspoň jedna položka je drobný majetek (EXISTS v list SELECTu) → ikonka v seznamu. */
  has_small_asset?: boolean
  extraction_warning: string | null
  payment_ordered_at: string | null
  /** Zámek dokladu (F6) — jediný zdroj pravdy je BE, FE nic nedopočítává. Optional = BC. */
  locked?: DocumentLock
}

export interface PurchaseMonthGroup {
  month: string
  count: number
  totals_per_currency: Array<{
    currency: string
    without_vat: number
    vat: number
    with_vat: number
  }>
  invoices: PurchaseInvoiceListItem[]
}

export interface PurchaseInvoicePayload {
  /** Staging originál, který účetní tímto ručním zápisem zpracovává. */
  submission_id?: number
  vendor_id: number
  vendor_invoice_number: string
  document_kind?: PurchaseDocumentKind
  varsymbol?: string | null
  issue_date: string
  tax_date?: string | null
  due_date: string
  received_at?: string
  currency_id: number
  exchange_rate?: number | null
  exchange_rate_date?: string | null
  exchange_rate_source?: ExchangeRateSource
  reverse_charge?: boolean
  prices_include_vat?: boolean
  is_fixed_asset?: boolean
  /** Snapshot plátcovství dodavatele k datu plnění (migrace 0133). */
  vendor_is_vat_payer?: boolean
  vat_deduction?: VatDeduction
  vat_deduction_percent?: number
  tax_deductible?: boolean
  language?: 'cs' | 'en'
  note_above_items?: string | null
  note_below_items?: string | null
  advance_paid_amount?: number
  rounding?: number
  payment_currency_id?: number | null
  payment_exchange_rate?: number | null
  paid_amount_payment_ccy?: number | null
  paid_amount_invoice_ccy?: number | null
  exchange_diff_base?: number | null
  vat_classification_code?: string | null
  expense_category_id?: number | null
  /** Zakázka (issue #29). */
  project_id?: number | null
  /** Dobropis (credit_note): ID opravované přijaté faktury (migrace 1096). */
  parent_purchase_invoice_id?: number | null
  /** Ruční rekapitulace DPH dle dokladu (§ 73). null/[] = počítat standardně. */
  vat_overrides?: PurchaseVatOverride[] | null
  /** Prázdné pole = použít hlavičkový režim faktury. */
  vat_allocations?: PurchaseVatAllocation[]
  /** Platební účet dodavatele pro QR platbu (migrace 0107). */
  payment?: {
    account_number?: string | null
    bank_code?: string | null
    iban?: string | null
    bic?: string | null
    variable_symbol?: string | null
    source?: PaymentAccountSource
  }
  /**
   * Forma úhrady (migrace 1128). Backend jí u téhle cesty vždy nastaví
   * `payment_method_source = 'manual'` — volba v editoru je vědomý úkon účetní.
   */
  payment_method?: PaymentMethod
  /**
   * Hotovostní vyrovnání (migrace 1327) — pokladna pro úhradu hotově.
   * Zapisuje se jen když je klíč přítomen; null volbu i dřív založený VPD zruší.
   */
  cash_register_id?: number | null
  items: Array<{
    description: string
    quantity: number
    unit: string
    unit_price_without_vat: number
    vat_rate_id: number
    order_index: number
    vat_classification_code?: string | null
    expense_kind?: ExpenseKind | null
    accrual_from?: string | null
    accrual_to?: string | null
    stock_item_id?: number | null
  }>
}

export interface PurchaseListFilters {
  status?: PurchaseInvoiceStatus | PurchaseInvoiceStatus[]
  document_kind?: PurchaseDocumentKind | PurchaseDocumentKind[]
  vendor_id?: number
  /** Zakázka (issue #29); 'none' = doklady bez zakázky. */
  project_id?: number | 'none'
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
  /** Bez zaúčtované úhrady (banka ani pokladna) — odhalí ručně/legacy uhrazené doklady. */
  unmatched?: boolean
  needs_review?: boolean
  /** '1' = předané k úhradě, '0' = nepředané (odvozeno z payment_ordered_at). */
  payment_ordered?: '1' | '0'
  /** Zaúčtování (jen podvojné účetnictví): '1' = zaúčtováno, '0' = nezaúčtováno. */
  booked?: '1' | '0'
  /** Filtr na dávku hromadného AI importu (#232). */
  import_batch_id?: string
  q?: string
  page?: number
  per_page?: number
}

export interface ImportBatch {
  import_batch_id: string
  created_at: string
  count: number
}

export interface PurchaseListMeta {
  total: number
  page?: number
  per_page?: number
  pages?: number
}

export interface InboxScanResultDetail {
  file: string
  status: 'created' | 'skipped' | 'failed' | 'rejected' | 'mapper_pending' | 'config_missing' | 'inbox_missing' | 'limit_reached'
  reason?: string
  purchase_invoice_id?: number
  isdoc_invoice_count?: number
  supplier_ic?: string | null
  /** PDF spárované s ISDOC podle shodného základu jména (bez volání AI). */
  paired_pdf?: string | null
  /** Měkké varování: spárované PDF si s daty nesedí (doklad přesto vznikl i s přílohou). */
  warning?: string
}

export interface InboxScanResult {
  created: number
  skipped: number
  failed: number
  dry_run: boolean
  inbox_dir: string
  details: InboxScanResultDetail[]
}

export const purchaseInvoicesApi = {
  listGrouped: (filters: PurchaseListFilters = {}) => {
    const params: Record<string, string | number> = {}
    if (filters.q) params.q = filters.q
    if (filters.status) {
      params['filter[status]'] = Array.isArray(filters.status) ? filters.status.join(',') : filters.status
    }
    if (filters.document_kind) {
      params['filter[document_kind]'] = Array.isArray(filters.document_kind)
        ? filters.document_kind.join(',')
        : filters.document_kind
    }
    if (filters.vendor_id)   params['filter[vendor_id]']   = filters.vendor_id
    if (filters.project_id)  params['filter[project_id]']  = filters.project_id
    if (filters.year)        params['filter[year]']        = filters.year
    if (filters.month)       params['filter[month]']       = filters.month
    if (filters.date_from)   params['filter[date_from]']   = filters.date_from
    if (filters.date_to)     params['filter[date_to]']     = filters.date_to
    if (filters.currency)    params['filter[currency]']    = filters.currency
    if (filters.unpaid_only)  params['filter[unpaid_only]']  = 1
    if (filters.overdue)      params['filter[overdue]']      = 1
    if (filters.unpaid_as_of) params['filter[unpaid_as_of]'] = filters.unpaid_as_of
    if (filters.unmatched)    params['filter[unmatched]']    = 1
    if (filters.needs_review) params['filter[needs_review]'] = 1
    if (filters.payment_ordered) params['filter[payment_ordered]'] = filters.payment_ordered
    if (filters.booked)      params['filter[booked]']      = filters.booked
    if (filters.import_batch_id) params['filter[import_batch_id]'] = filters.import_batch_id
    if (filters.page)        params.page                   = filters.page
    if (filters.per_page)    params.per_page               = filters.per_page
    return api.get<{ data: PurchaseMonthGroup[]; meta: PurchaseListMeta }>(
      '/purchase-invoices',
      { params },
    ).then(r => r.data)
  },

  get:    (id: number) => api.get<PurchaseInvoice>(`/purchase-invoices/${id}`).then(r => r.data),
  acceptAiPostingSuggestion: (id: number, override?: Partial<AiPostingSuggestion['payload']>) =>
    api.post<{ status: 'accepted'; applied: AiPostingSuggestion['payload'] }>(
      `/ai/suggestions/${id}/accept`, override ? { override } : {},
    ).then(r => r.data),
  rejectAiPostingSuggestion: (id: number) =>
    api.post<{ status: 'rejected' }>(`/ai/suggestions/${id}/reject`, {}).then(r => r.data),
  /**
   * §DM — návrhy druhu nákladu pro položky dokladu (read-only, nic neúčtuje).
   * Jen podvojné účetnictví; u daňové evidence vrací BE 4xx → volající to smí ignorovat.
   */
  expenseSuggestions: (id: number) =>
    api.get<ExpenseSuggestionsResponse>(
      `/accounting/purchase-invoices/${id}/expense-suggestions`,
    ).then(r => r.data),
  create: (payload: PurchaseInvoicePayload) =>
    api.post<PurchaseInvoice>('/purchase-invoices', payload).then(r => r.data),

  /**
   * Deterministický jednosouborový import z editoru nové PF. Nevstupuje do admin
   * dávkového importu ani do AI: podporuje .isdoc, .isdocx a PDF/A-3 s embedded ISDOC.
   */
  importStructured: (file: File) => {
    const fd = new FormData()
    fd.append('file', file, file.name)
    return api.post<StructuredPurchaseImportResult>(
      '/purchase-invoices/import-structured',
      fd,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data)
  },

  update: (id: number, payload: PurchaseInvoicePayload, force = false) =>
    api.put<PurchaseInvoice>(
      `/purchase-invoices/${id}${force ? '?force=1' : ''}`,
      payload,
    ).then(r => r.data),
  delete: (id: number, force = false) =>
    api.delete<{ ok: boolean; pdf_deleted?: boolean }>(
      `/purchase-invoices/${id}${force ? '?force=1' : ''}`,
    ).then(r => r.data),

  setItems: (id: number, items: PurchaseInvoicePayload['items']) =>
    api.put<PurchaseInvoice>(`/purchase-invoices/${id}/items`, { items }).then(r => r.data),

  setExchangeRate: (id: number, rate: number | null, rateDate: string | null, source: ExchangeRateSource = 'user') =>
    api.post<PurchaseInvoice>(`/purchase-invoices/${id}/exchange-rate`, {
      rate, rate_date: rateDate, source,
    }).then(r => r.data),

  transition: (id: number, target: PurchaseInvoiceStatus, paidDate?: string) =>
    api.post<PurchaseInvoice>(`/purchase-invoices/${id}/transition`, {
      target,
      ...(target === 'paid' ? { paid_date: paidDate || appIsoDate() } : {}),
    }).then(r => r.data),

  dismissExtractionWarning: (id: number) =>
    api.post<PurchaseInvoice>(`/purchase-invoices/${id}/dismiss-extraction-warning`).then(r => r.data),

  /** Rychlá změna typu dokladu (#232) — oprava AI klasifikace po importu. */
  setDocumentKind: (id: number, documentKind: PurchaseDocumentKind) =>
    api.post<PurchaseInvoice>(`/purchase-invoices/${id}/document-kind`, {
      document_kind: documentKind,
    }).then(r => r.data),

  /**
   * Zařazení dokladu k zakázce (issue #29). Funguje i u ZAÚČTOVANÉ faktury — zakázka
   * je analytická dimenze, takže se jen přerazítkují řádky deníku (§35 se neporušuje).
   */
  setProject: (id: number, projectId: number | null) =>
    api.post<PurchaseInvoice>(`/purchase-invoices/${id}/project`, {
      project_id: projectId,
    }).then(r => r.data),

  /** Posledních N dávek hromadného AI importu (#232) — pro „dohledat import". */
  listImportBatches: (limit = 20) =>
    api.get<{ data: ImportBatch[] }>('/purchase-invoices/import-batches', { params: { limit } })
      .then(r => r.data.data),

  // „Zaplatit pomocí QR" — QR z uloženého účtu (GET), jednorázové lazy doplnění
  // účtu z ISDOC/AI (POST), ruční editace účtu (PUT).
  paymentQr: (id: number) =>
    api.get<PaymentQrResponse>(`/purchase-invoices/${id}/payment-qr`).then(r => r.data),
  extractPaymentAccount: (id: number) =>
    api.post<PaymentQrResponse>(`/purchase-invoices/${id}/payment-qr/extract-account`).then(r => r.data),
  updatePaymentAccount: (id: number, payload: Partial<PaymentQrAccount>) =>
    api.put<PaymentQrResponse>(`/purchase-invoices/${id}/payment-account`, payload).then(r => r.data),

  // Propojení se zálohovou fakturou (advance) — proti dvojímu započtení nákladu
  advanceCandidates: (id: number) =>
    api.get<{ candidates: PurchaseInvoiceBrief[] }>(`/purchase-invoices/${id}/advance-candidates`)
      .then(r => r.data.candidates),
  // Opačný směr — z detailu zálohy nabídni nepropojené vyúčtovací faktury téhož dodavatele
  settlementCandidates: (id: number) =>
    api.get<{ candidates: PurchaseInvoiceBrief[] }>(`/purchase-invoices/${id}/settlement-candidates`)
      .then(r => r.data.candidates),
  linkAdvance: (id: number, advanceId: number) =>
    api.post<PurchaseInvoice>(`/purchase-invoices/${id}/link-advance`, { advance_id: advanceId })
      .then(r => r.data),
  unlinkAdvance: (id: number) =>
    api.delete<PurchaseInvoice>(`/purchase-invoices/${id}/link-advance`).then(r => r.data),
  dismissAdvanceSuggestion: (id: number) =>
    api.delete<PurchaseInvoice>(`/purchase-invoices/${id}/advance-suggestion`).then(r => r.data),

  uploadPdf: (id: number, file: File) => {
    const fd = new FormData()
    fd.append('file', file, file.name)
    return api.post<{ ok: boolean; pdf_path: string; pdf_hash: string; pdf_size_bytes: number; pdf_original_name: string }>(
      `/purchase-invoices/${id}/pdf`,
      fd,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data)
  },

  deletePdf: (id: number) =>
    api.delete<{ ok: boolean; file_deleted: boolean; still_used_by: number }>(
      `/purchase-invoices/${id}/pdf`,
    ).then(r => r.data),

  activity: (id: number) =>
    api.get<Array<{
      id: number; user_id: number | null; user_email: string | null; user_name: string | null;
      action: string; payload: Record<string, unknown> | null; ip: string | null; created_at: string;
    }>>(`/purchase-invoices/${id}/activity`).then(r => r.data),

  pdfUrl: (id: number, inline = false) => {
    // Přímá navigace v prohlížeči — supplier_id v query param (X-Supplier-Id header se neposílá).
    // inline=true → Content-Disposition: inline (iframe preview; bez tohoto Edge/IE blokuje pro attachment).
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    if (inline) params.set('inline', '1')
    const qs = params.toString()
    return `/api/purchase-invoices/${id}/pdf${qs ? '?' + qs : ''}`
  },
  /** URL ke stažení strojového zdrojového originálu (ISDOC/ISDOCX/…) — vždy attachment. */
  sourceUrl: (id: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/purchase-invoices/${id}/source${qs ? '?' + qs : ''}`
  },

  /** Naše vygenerované PDF (mPDF z dat). Když nemáme originál nebo chceme vlastní layout. */
  ourPdfUrl: (id: number, inline = false) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    if (inline) params.set('inline', '1')
    const qs = params.toString()
    return `/api/purchase-invoices/${id}/our-pdf${qs ? '?' + qs : ''}`
  },

  /** ISDOC XML export přijaté faktury (role inversion — vendor=supplier, my=customer). */
  isdocUrl: (id: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/purchase-invoices/${id}/isdoc${qs ? '?' + qs : ''}`
  },

  /** Pohoda XML export přijaté faktury (dataPackItem s `<pur:purchase>`). */
  pohodaUrl: (id: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/purchase-invoices/${id}/pohoda${qs ? '?' + qs : ''}`
  },

  // ── Přílohy: link/unlink DMS dokumentů (Epic F7) ──
  // Vazba přes document_links(entity_type='purchase_invoice'); fixní pdf_*/source_*
  // sloupce PF netknuté (subsystém B).
  listDmsDocuments: (id: number) =>
    api.get<{ documents: DocItem[] }>(`/purchase-invoices/${id}/documents`).then(r => r.data.documents ?? []),
  linkDmsDocument: (id: number, documentId: number) =>
    api.post<{ documents: DocItem[] }>(`/purchase-invoices/${id}/documents`, { document_id: documentId })
      .then(r => r.data.documents ?? []),
  unlinkDmsDocument: (id: number, documentId: number) =>
    api.delete<{ documents: DocItem[] }>(`/purchase-invoices/${id}/documents`, { params: { document_id: documentId } })
      .then(r => r.data.documents ?? []),

  scanInbox: (dryRun = false) =>
    api.post<InboxScanResult>('/purchase-invoices/scan-inbox', { dry_run: dryRun }).then(r => r.data),

  /**
   * Export přijatých faktur za měsíc nebo čtvrtletí.
   * Vrací URL pro přímou navigaci (axios by stáhl jako blob).
   */
  exportUrl: (
    period: { type: 'monthly'; year: number; month: number } | { type: 'quarterly'; year: number; quarter: number },
    dateBy: 'tax' | 'issue' | 'received' = 'tax',
    format: 'pdf-zip' | 'pohoda' | 'isdoc' | 'csv' = 'pdf-zip',
  ) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({
      period: period.type,
      year: String(period.year),
      format,
      date_by: dateBy,
    })
    if (period.type === 'quarterly') {
      params.set('quarter', String(period.quarter))
    } else {
      params.set('month', String(period.month))
    }
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/purchase-invoices/export?${params.toString()}`
  },
}
