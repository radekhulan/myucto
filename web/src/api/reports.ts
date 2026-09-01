import { api } from './client'

export interface DphPriznaniLine {
  base: number
  vat: number
  count: number
  label: string
}

export type DphCrossCheck343Reason = 'timing_73' | 'value_mismatch' | 'missing_entry' | 'extra_entry'

export interface DphCrossCheckDocument {
  invoice_id: number
  doc_number: string | null
  source: string
  declared: number
  counter: number
  difference: number
  reason?: DphCrossCheck343Reason | null
  claim_period?: string | null
  entry_date?: string | null
  received_at?: string | null
}

export interface DphCrossCheckFinding {
  check: string
  label: string
  severity: 'mismatch' | 'info'
  blocking: boolean
  declared: number | null
  counter: number | null
  difference: number
  explained?: number
  documents: DphCrossCheckDocument[]
  note: string
}

/** Typ podání DPHDP3 (C7'): řádné / opravné (§138) / dodatečné (§141) / dodatečné-opravné. */
export type DphVariant = 'radne' | 'opravne' | 'dodatecne' | 'dodatecne_opravne'
/** Typ podání KH (C7'): řádné / řádné-opravné / následné (§101f) / následné-opravné. */
/**
 * Typ podání KH. `vyzva_*` jsou rychlé odpovědi na výzvu správce daně (§ 101g) — hlášení
 * bez oddílů A/B/C, vyžadují č.j. výzvy.
 */
/** Typ podání souhrnného hlášení: řádné, nebo následné (opravné řádky se stornem). */
export type ShvVariant = 'radne' | 'nasledne'

export type KhVariant =
  | 'radne' | 'opravne' | 'nasledne' | 'nasledne_opravne'
  | 'vyzva_nulove' | 'vyzva_potvrzeni'

export interface PostFilingChangeDoc {
  source: 'sale' | 'purchase' | 'cash'
  invoice_id: number
  doc_number: string | null
  total: number
  updated_at: string
}

export interface PostFilingChanges {
  has_filing: boolean
  snapshot_available: boolean
  submission: { id: number; generated_at: string; form_variant: string } | null
  documents: PostFilingChangeDoc[]
}

export interface DphPriznaniPreview {
  summary: {
    period: string
    period_type: 'monthly' | 'quarterly'
    quarter: number | null
    lines: Record<string, DphPriznaniLine>
    total_vat_output: number
    total_vat_input: number
    tax_due: number
    is_excess_deduction: boolean
    submission_deadline: string
    supplier_vat_period: string
    // C7' — typ podání + podklady dodatečného přiznání.
    variant: DphVariant
    dapdph_forma: string
    is_amendment: boolean
    d_zjist: string | null
    last_known_tax: number | null
    tax_difference: number | null
    reference_submission_id: number | null
  }
  warnings: string[]
  cross_check: DphCrossCheckFinding[]
  post_filing_changes: PostFilingChanges
}

export interface DphSettings {
  vat_period: 'monthly' | 'quarterly' | null
  is_vat_payer: boolean
  /** Identifikovaná osoba (§ 6g–6l, issue #94) — přiznání typu I, vždy měsíčně. */
  is_identified?: boolean
  taxpayer_type: 'fo' | 'po' | null
  has_financial_office: boolean
}

export interface DphTrendRow {
  period: string
  vat_output: number
  vat_input: number
  vat_due: number
}

export interface DphDraftsPrediction {
  year: number
  month: number
  period: 'monthly' | 'quarterly'
  vat_output: number
  vat_input: number
  tax_due: number
  sale_count: number
  sale_draft_count: number
  purchase_count: number
  purchase_draft_count: number
}

export interface DphBookRow {
  invoice_id: number
  direction: 'issued' | 'received'
  doc_number: string | null
  original_doc_number: string | null
  tax_date: string | null
  accounting_date: string | null
  /**
   * Období odpočtu dle § 73 ZDPH (issue #9) — datum, podle kterého doklad spadl do TÉHLE
   * sestavy. U vystavených plnění a u korekcí § 74b null (řídí se jiným pravidlem).
   */
  claim_date: string | null
  /** Které datum období odpočtu určilo — vysvětlení pro uživatele, ne vstup výpočtu. */
  claim_basis: 'tax_date' | 'issue_date' | 'received_at' | null
  received_at: string | null
  description: string
  counterparty_name: string
  counterparty_dic: string
  vat_classification_code: string | null
  vat_rate: number
  currency: string
  exchange_rate: number
  base: number
  vat: number
  total: number
  status: string
  is_draft: boolean
  kh_section: string | null
}

export interface DphBookSection {
  key: string
  direction: 'USKUTEČNĚNÁ' | 'PŘIJATÁ'
  label: string
  dphdp3_line: string
  vat_rate: number
  is_secondary: boolean
  rows: DphBookRow[]
  subtotal_base: number
  subtotal_vat: number
  subtotal_total: number
}

export interface DphBookPreview {
  period: {
    year: number
    month: number
    period_type: 'monthly' | 'quarterly'
    quarter: number | null
    start: string
    end: string
    label: string
  }
  supplier: Record<string, unknown>
  sections: DphBookSection[]
  totals: {
    issued: { base: number; vat: number; total: number }
    received: { base: number; vat: number; total: number }
    vat_balance: number
  }
}

export interface KhPreview {
  summary: {
    period: string
    a1_count: number
    a2_count: number
    a4_count: number
    a5_count_aggregated: number
    b1_count: number
    b2_count: number
    b3_count_aggregated: number
    submission_deadline: string
    variant: KhVariant
    khdph_forma: string
    is_follow_up: boolean
    is_vyzva_odpoved?: boolean
    vyzva_odp?: string | null
    d_zjist: string | null
    c_jed_vyzvy: string | null
  }
  warnings: string[]
}

export interface OssPreview {
  period: {
    year: number
    quarter: number
    start: string
    end: string
    label: string
    submission_deadline: string
  }
  settings: {
    oss_enabled: boolean
    oss_valid_from: string | null
    oss_valid_to: string | null
    oss_identification_country: string | null
    oss_return_currency: string
  }
  summary: {
    return_currency: string
    /**
     * Rozhodný kurzový den ECB. Liší se od konce období, když ECB pro poslední den
     * nezveřejnila (víkend, svátek TARGET) — účetní přesně tohle kontroluje proti
     * tabulce ECB, takže se to musí zobrazit, ne jen přenést.
     */
    return_rate_date: string | null
    /** Měna dokladu → kolik jejích jednotek za 1 jednotku měny podání (24,195 Kč za 1 €). */
    return_rates: Record<string, number>
    total_base: number
    total_vat: number
    total_corrections: number
    total_payable: number
    invoice_count: number
    row_count: number
    correction_row_count: number
    invalid_correction_count: number
    conversion_missing_count: number
    /**
     * OSS řádky období čekající na ruční posouzení. Táž množina, kterou v seznamu
     * faktur vrátí `filter[oss_review]=oss` — náhled na ni proto umí prokliknout.
     */
    manual_review_count?: number
  }
  countries: Array<{
    country: string
    base: number
    vat: number
    rates: Array<{
      rate: number
      rate_type: string | null
      base: number
      vat: number
      count: number
    }>
    rows: Array<{
      invoice_id: number
      item_id: number
      doc_number: string | null
      invoice_type: string
      tax_date: string | null
      client_name: string
      description: string
      currency: string
      base: number
      vat: number
      base_return: number
      vat_return: number
      vat_rate: number
      rate_type: string | null
      supply_type: string | null
    }>
  }>
  corrections: Array<{
    period: string
    year: number
    quarter: number
    state_consumption: string
    correction: number
    count: number
    rows: Array<{
      invoice_id: number
      item_id: number
      doc_number: string | null
      invoice_type: string
      tax_date: string | null
      client_name: string
      description: string
      currency: string
      base_return: number
      vat_return: number
      original_period: string
    }>
  }>
  /** Čerpání celounijního prahu 10 000 EUR (§ 8 odst. 3 ZDPH) za CELÝ kalendářní rok. */
  threshold: OssThresholdProgress
  warnings: string[]
}

export interface OssThresholdProgress {
  year: number
  threshold_eur: number
  total_eur: number
  pct: number
  exceeded: boolean
  exceeded_on: string | null
  near_threshold: boolean
  by_country: Array<{ country: string; amount_eur: number }>
  unconverted_rows: number
  warnings: string[]
}

export type MonthlyExportPart =
  | 'sales_pdf' | 'sales_isdoc' | 'purchase_pdf' | 'purchase_isdoc'
  | 'bank_pdf' | 'bank_gpc' | 'gopay_pdf' | 'gopay_xml' | 'dph_book'

/** Období hromadného exportu — měsíc nebo celé čtvrtletí. */
export type ExportPeriodArg =
  | { type: 'monthly'; year: number; month: number }
  | { type: 'quarterly'; year: number; quarter: number }

export interface MonthlyExportPreview {
  period: string
  period_type: 'monthly' | 'quarterly'
  counts: Record<MonthlyExportPart, number>
}

export interface MonthlyExportJob {
  id: number
  status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled'
  total_items: number | null
  processed: number
  created_count: number
  failed_count: number
  current_step: string | null
  log_text?: string | null
  last_error: string | null
  cancel_requested: boolean
  result_name: string | null
  result_size: number | null
  params: Record<string, unknown> | null
  created_at: string
  finished_at: string | null
}

/** Query/body parametry období pro hromadný export (period + year + month|quarter). */
function monthlyExportPeriodParams(period: ExportPeriodArg): Record<string, string | number> {
  return period.type === 'quarterly'
    ? { period: 'quarterly', year: period.year, quarter: period.quarter }
    : { period: 'monthly', year: period.year, month: period.month }
}

export type ClosingPackagePart =
  | 'balance_sheet' | 'income_statement' | 'general_ledger'
  | 'trial_balance' | 'journal' | 'balance_inventory' | 'dph_book' | 'income_tax' | 'income_tax_advances'
  | 'asset_inventory' | 'saldo_over_1y' | 'accruals' | 'statement_notes'
  | 'cash_flow' | 'equity_changes'

/**
 * EP-6: POVINNÉ jádro balíčku — bez těchto částí je balíček `failed`, ne `completed`.
 *
 * Musí odpovídat `ClosingPackageService::REQUIRED_PARTS`. Chyběla tu `statement_notes`,
 * takže povinná příloha závěrky se z UI vůbec nevyžádala — a protože se stav vyhodnocuje
 * jen nad VYŽÁDANÝMI částmi, balíček bez ní končil jako „hotovo".
 */
export const CLOSING_PACKAGE_REQUIRED_PARTS: ClosingPackagePart[] = [
  'balance_sheet', 'income_statement', 'general_ledger', 'trial_balance', 'journal', 'balance_inventory',
  'statement_notes',
]

export interface ClosingPackagePreview {
  period_id: number
  fiscal_year: number
  counts: Record<ClosingPackagePart, number>
}

export interface ClosingPackageJob {
  id: number
  status: 'queued' | 'running' | 'completed' | 'failed' | 'cancelled' | 'completed_with_warnings'
  total_items: number | null
  processed: number
  created_count: number
  failed_count: number
  current_step: string | null
  log_text?: string | null
  last_error: string | null
  cancel_requested: boolean
  result_name: string | null
  result_size: number | null
  params: Record<string, unknown> | null
  created_at: string
  finished_at: string | null
}

/** Řádek auditu „kurz na dokladu vs. ČNB" (§C / K4). */
export interface CnbRateAuditItem {
  doc_type: 'invoice' | 'purchase_invoice'
  doc_id: number
  doc_no: string | null
  date: string
  currency: string
  used_rate: number
  cnb_rate: number
  cnb_rate_date: string
  diff_percent: number
  impact_czk: number
}

export interface CnbRateAuditResult {
  from: string
  to: string
  threshold_percent: number
  items: CnbRateAuditItem[]
  missing_cnb_count: number
  fixed_mode_skipped: boolean
}

/** FR3 — úplnost číselné řady vydaných dokladů (mezera v řadě = auditní signál pro FÚ). */
export interface InvoiceSeriesBucket {
  period_key: string
  used_count: number
  range_from: number
  range_to: number
  /** Stropovaný výčet chybějících čísel — viz missing_truncated. */
  missing: number[]
  /** Skutečný počet mezer; může být vyšší než délka `missing`. */
  missing_total: number
  missing_truncated: boolean
  missing_preview: string[]
}

export interface InvoiceSeriesGroup {
  types: ('invoice' | 'credit_note')[]
  client_id: number
  client_name: string | null
  /** Řada kategorie tržby; 0 = řada není vázaná na kategorii. */
  revenue_category_id: number
  revenue_category_name: string | null
  period: 'year' | 'month' | 'none'
  template_by_type: Record<string, string>
  buckets: InvoiceSeriesBucket[]
}

export interface InvoiceSeriesCompletenessResult {
  year: number
  series: InvoiceSeriesGroup[]
  total_missing: number
}

/** Řádek náhledu §74b — korekce odpočtu DPH u neuhrazených závazků (pohled dlužníka). */
export interface S74bRow {
  purchase_invoice_id: number
  vendor_name: string
  vendor_dic: string | null
  vendor_invoice_number: string | null
  tax_date: string | null
  due_date: string | null
  total_with_vat: number
  claimed_deduction_vat: number
  unpaid_ratio: number
  aged: boolean
  target_reduction: number
  net_corrected: number
  delta: number
  movement: 'reduction' | 'restoration' | null
  state: string
  dphdp3_line_hint: string | null
  kh_zdph_44: boolean
}

export interface S74bTotals {
  reduction: number
  restoration: number
  net_delta: number
}

export interface S74bPreview {
  period: { year: number; month: number; period_end: string }
  rows: S74bRow[]
  totals: S74bTotals
}

export interface S74bRecordResult extends S74bPreview {
  recorded: number
}

// ── § 76 ZDPH — koeficient krácení nároku na odpočet ────────────────────────
export interface VatCoefficientStatus {
  year: number
  /** Explicitně nastavený zálohový koeficient (§ 76/6), null = nenastaven. */
  provisional_percent: number | null
  /** Koeficient skutečně uplatňovaný na ř. 52 (fallback = vypořádací z minulého roku). */
  resolved_provisional_percent: number | null
  /** true = zálohový se přenáší z vypořádání minulého roku, ne z ručního nastavení. */
  carried_forward: boolean
  /** Vypořádací koeficient (§ 76/7) — null, dokud neproběhne roční vypořádání. */
  final_percent: number | null
  numerator_czk: number | null
  denominator_czk: number | null
  settled_at: string | null
}

// ── § 46–46g ZDPH — oprava základu daně u nedobytné pohledávky (věřitel) ────
export type S46LegalGround = 'insolvency' | 'execution' | 'death' | 'liquidation' | 'small_receivable'

/**
 * Netting shodný s § 74b: target = output_vat × unpaid_ratio, delta = target −
 * net_corrected. delta > 0 → oprava (correction), delta < 0 → obnova (restoration).
 */
export interface S46Row {
  invoice_id: number
  varsymbol: string
  client_name: string
  client_dic: string | null
  tax_date: string | null
  due_date: string
  total_with_vat: number
  output_vat: number
  unpaid_ratio: number
  net_corrected: number
  target: number
  delta: number
  movement: 'correction' | 'restoration' | null
  legal_ground: S46LegalGround | null
}

export interface S46RestorationsPreview {
  period: { year: number; month: number; period_end: string }
  rows: S46Row[]
  total: number
  recorded?: number
}

// ── § 36a ZDPH / § 23 odst. 7 ZDP — spojené osoby a ceny obvyklé ────────────
export type RelatedPartyType = 'capital' | 'otherwise' | 'close_person' | 'employment'

export interface RelatedPartyTransaction {
  direction: 'issued' | 'received'
  doc_type: 'invoice' | 'purchase_invoice'
  doc_id: number
  doc_no: string
  partner_name: string
  related_party_type: RelatedPartyType | null
  tax_date: string
  amount: number
}

/**
 * MĚŘITELNÁ odchylka — položka fakturovaná spojené osobě proti MEDIÁNU cen téže položky
 * fakturovaných nespojeným. Kde srovnatelný vzorek není, backend odchylku netvrdí
 * a řádek vůbec nevrátí.
 */
export interface RelatedPartyDeviation {
  doc_type: 'invoice'
  doc_id: number
  doc_no: string
  partner_name: string
  description: string
  unit_price: number
  market_price: number
  deviation_pct: number
  samples: number
  note: string
}

export interface RelatedPartyOverview {
  from: string
  to: string
  transactions: RelatedPartyTransaction[]
  total: number
  deviations: RelatedPartyDeviation[]
}

export interface RelatedPartyAdjustment {
  id: number
  client_id: number | null
  partner_name: string
  movement: 'increase' | 'decrease'
  amount: number
  reason: string
}

export interface RelatedPartyAdjustments {
  rows: RelatedPartyAdjustment[]
  total_increase: number
  total_decrease: number
  net_delta: number
}

// ── § 43 ZDPH — oprava VÝŠE daně per doklad ────────────────────────────────
/** Sazbová skupina PŮVODNÍHO plnění (§ 43 odst. 2) — ř. 1 vs ř. 2, ne dnešní sazba. */
export type S43RateKind = 'basic' | 'reduced'

export interface S43Correction {
  id: number
  doc_type: 'invoice' | 'purchase_invoice'
  doc_id: number
  period_year: number
  period_month: number
  rate_kind: S43RateKind
  base_delta: number
  vat_delta: number
  corrective_doc_number: string | null
  delivered_on: string
  reason: string
}

// ── § 79 / § 79a ZDPH — odpočet při registraci a jeho snížení (ř. 45) ──────
export interface S79Item {
  id: number
  kind: 'registration' | 'deregistration'
  label: string
  acquired_on: string
  effective_on: string
  asset_kind: 'inventory' | 'fixed_asset'
  period_years: number | null
  vat_amount: number
  /** Částka do ř. 45 se znaménkem: nárok kladně, snížení záporně. */
  amount: number
  applies: boolean
  reason: string
}

export interface S79Overview {
  from: string
  to: string
  rows: S79Item[]
  total: number
}

export const reportsApi = {
  dphSettings: () =>
    api.get<DphSettings>('/reports/dphdp3/settings').then(r => r.data),

  dphPreview: (
    year: number, month: number, period?: 'monthly' | 'quarterly',
    variant: DphVariant = 'radne', dZjist?: string, reason?: string,
  ) =>
    api.get<DphPriznaniPreview>('/reports/dphdp3/preview', {
      params: {
        year, month,
        ...(period ? { period } : {}),
        ...(variant !== 'radne' ? { variant } : {}),
        ...(dZjist ? { d_zjist: dZjist } : {}),
        ...(reason ? { reason } : {}),
      },
    }).then(r => r.data),

  // Pozn.: fronta „doklady změněné po podání" (C7') se čte vloženě z dphPreview —
  // samostatný klient post-filing-changes byl mrtvý kód (audit UI mezer 2026-07).

  dphTrend: (months = 12) =>
    api.get<DphTrendRow[]>('/reports/dphdp3/trend', { params: { months } }).then(r => r.data),

  dphDraftsPrediction: (year: number, month: number, period?: 'monthly' | 'quarterly') =>
    api.get<DphDraftsPrediction>('/reports/dphdp3/drafts-prediction', {
      params: { year, month, ...(period ? { period } : {}) },
    }).then(r => r.data),

  khPreview: (
    year: number, month: number, period?: 'monthly' | 'quarterly',
    variant: KhVariant = 'radne', dZjist?: string, cJedVyzvy?: string,
  ) =>
    api.get<KhPreview>('/reports/dphkh1/preview', {
      params: {
        year, month,
        ...(period ? { period } : {}),
        ...(variant !== 'radne' ? { variant } : {}),
        ...(dZjist ? { d_zjist: dZjist } : {}),
        ...(cJedVyzvy ? { c_jed_vyzvy: cJedVyzvy } : {}),
      },
    }).then(r => r.data),

  // Souhrnné hlášení (EU dodání) — plátci i identifikované osoby; lze kvartálně pro služby
  shvPreview: (
    year: number, month: number, period?: 'monthly' | 'quarterly',
    variant: ShvVariant = 'radne', dZjist?: string,
  ) =>
    api.get<{
      summary: {
        period: string
        rows_count: number
        total_amount: number
        rows: Array<{
          country_iso2: string
          k_stat: string
          vat_id: string
          sh_type: '0' | '1' | '2' | '3'
          amount: number
          count: number
          counterparty_name: string
        }>
        submission_deadline: string
        variant: ShvVariant
        shvies_forma: string
        is_follow_up: boolean
        d_zjist: string | null
        /** Počet storno řádků následného hlášení — kolik řádků se ve VIES ruší. */
        storno_rows: number
        reference_submission_id: number | null
      }
      warnings: string[]
    }>('/reports/dphshv/preview', {
      params: {
        year, month,
        ...(period ? { period } : {}),
        variant,
        ...(variant === 'nasledne' && dZjist ? { d_zjist: dZjist } : {}),
      },
    }).then(r => r.data),

  shvDownloadUrl: (
    year: number, month: number, period?: 'monthly' | 'quarterly',
    variant: ShvVariant = 'radne', dZjist?: string,
  ) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    if (period) params.set('period', period)
    params.set('variant', variant)
    if (variant === 'nasledne' && dZjist) params.set('d_zjist', dZjist)
    return `/api/reports/dphshv?${params.toString()}`
  },

  khDownloadUrl: (
    year: number, month: number, period?: 'monthly' | 'quarterly',
    variant: KhVariant = 'radne', dZjist?: string, cJedVyzvy?: string,
  ) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    if (period) params.set('period', period)
    if (variant !== 'radne') params.set('variant', variant)
    if (dZjist) params.set('d_zjist', dZjist)
    if (cJedVyzvy) params.set('c_jed_vyzvy', cJedVyzvy)
    return `/api/reports/dphkh1?${params.toString()}`
  },

  // Kniha DPH (interní VAT žurnál — NE EPO podání)
  dphBookPreview: (year: number, month: number, period?: 'monthly' | 'quarterly') =>
    api.get<DphBookPreview>('/reports/dph-book/preview', {
      params: period ? { year, month, period } : { year, month },
    }).then(r => r.data),

  dphBookPdfUrl: (year: number, month: number, period?: 'monthly' | 'quarterly') => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (period) params.set('period', period)
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/reports/dph-book?${params.toString()}`
  },

  ossPreview: (year: number, quarter: number) =>
    api.get<OssPreview>('/reports/oss/preview', { params: { year, quarter } }).then(r => r.data),

  /** Práh 10 000 EUR — dostupný i BEZ zapnutého OSS, protože ho potřebuje znát právě ten,
   *  kdo ještě registrovaný není. */
  ossThreshold: (year: number) =>
    api.get<OssThresholdProgress>('/reports/oss/threshold', { params: { year } }).then(r => r.data),

  ossDownloadUrl: (year: number, quarter: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), quarter: String(quarter) })
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    return `/api/reports/oss?${params.toString()}`
  },

  // Audit kurzů vs. ČNB (§C / K4) — cizoměnové doklady s odchylkou účetního kurzu
  cnbRateAudit: (from: string, to: string, threshold?: number) =>
    api.get<CnbRateAuditResult>('/reports/cnb-rate-audit', {
      params: { from, to, ...(threshold != null ? { threshold } : {}) },
    }).then(r => r.data),

  // FR3 — úplnost číselné řady vydaných dokladů (mezera = auditní signál pro FÚ)
  invoiceSeriesCompleteness: (year: number) =>
    api.get<InvoiceSeriesCompletenessResult>('/reports/invoice-series-completeness', {
      params: { year },
    }).then(r => r.data),

  // §74b — korekce odpočtu DPH u neuhrazených závazků (dlužník). Preview = dry-run,
  // record = vědomá evidence období do ledgeru (recordAging), NE zaúčtování.
  s74bPreview: (year: number, month: number) =>
    api.get<S74bPreview>('/reports/s74b/preview', { params: { year, month } }).then(r => r.data),

  s74bRecord: (year: number, month: number) =>
    api.post<S74bRecordResult>('/reports/s74b/record', { year, month }).then(r => r.data),

  // § 76 — koeficient krácení nároku na odpočet: zálohový (PUT) se uplatňuje na ř. 52
  // každé období roku; vypořádací vzniká JEN explicitním settle (nikdy jako vedlejší
  // efekt náhledu přiznání).
  vatCoefficient: (year: number) =>
    api.get<VatCoefficientStatus>('/reports/vat-coefficient', { params: { year } }).then(r => r.data),

  vatCoefficientSet: (year: number, provisionalPercent: number) =>
    api.put<{ year: number; provisional_percent: number }>('/reports/vat-coefficient', {
      year, provisional_percent: provisionalPercent,
    }).then(r => r.data),

  vatCoefficientSettle: (year: number) =>
    api.post<{ year: number; final_percent: number; numerator_czk: number; denominator_czk: number }>(
      '/reports/vat-coefficient/settle', { year },
    ).then(r => r.data),

  // § 46 — věřitelská oprava u nedobytné pohledávky. Kandidáti = pracovní seznam
  // (právní důvod dokládá účetní); correction ji vědomě zaeviduje; obnovy § 46e
  // po úhradě jsou dry-run (GET) + vědomý zápis (POST).
  s46Candidates: (asOf: string) =>
    api.get<{ as_of: string; rows: S46Row[] }>('/reports/s46/candidates', { params: { as_of: asOf } }).then(r => r.data),

  s46Correction: (payload: {
    invoice_id: number
    legal_ground: S46LegalGround
    delivered_on: string
    corrective_doc_number?: string | null
    note?: string | null
  }) => api.post<{ movement_id: number; vat_amount: number; period: { year: number; month: number }; row: S46Row }>(
    '/reports/s46/correction', payload,
  ).then(r => r.data),

  s46Restorations: (year: number, month: number) =>
    api.get<S46RestorationsPreview>('/reports/s46/restorations', { params: { year, month } }).then(r => r.data),

  s46RestorationsRecord: (year: number, month: number) =>
    api.post<S46RestorationsPreview>('/reports/s46/restorations', { year, month }).then(r => r.data),

  // § 36a / § 23 odst. 7 — transakce se spojenými osobami, měřitelné cenové odchylky
  // a evidence úprav základu daně. Read-only přehled + CRUD úprav.
  relatedParties: (from: string, to: string) =>
    api.get<RelatedPartyOverview>('/reports/related-parties', { params: { from, to } }).then(r => r.data),

  relatedPartyAdjustments: (year: number) =>
    api.get<RelatedPartyAdjustments>('/reports/related-parties/adjustments', { params: { year } }).then(r => r.data),

  createRelatedPartyAdjustment: (payload: {
    fiscal_year: number
    amount: number
    reason: string
    client_id?: number | null
    movement?: 'increase' | 'decrease'
  }) => api.post<{ id: number }>('/reports/related-parties/adjustments', payload).then(r => r.data),

  deleteRelatedPartyAdjustment: (id: number) =>
    api.delete(`/reports/related-parties/adjustments/${id}`).then(r => r.data),

  // § 43 — oprava výše daně. `period_year`/`period_month` je období PŮVODNÍHO plnění,
  // `delivered_on` jen určuje, kdy nejdřív šlo opravu provést.
  s43List: (year: number, month?: number) =>
    api.get<{ year: number; month: number | null; rows: S43Correction[] }>('/reports/s43', {
      params: month ? { year, month } : { year },
    }).then(r => r.data),

  s43Create: (payload: {
    source_type: 'invoice' | 'purchase_invoice'
    source_id: number
    period_year: number
    period_month: number
    rate_kind: S43RateKind
    base_delta: number
    vat_delta: number
    delivered_on: string
    reason: string
    corrective_doc_number?: string | null
  }) => api.post<{ id: number }>('/reports/s43', payload).then(r => r.data),

  s43Delete: (id: number) => api.delete(`/reports/s43/${id}`).then(r => r.data),

  // § 79 / § 79a — období vykázání řídí `effective_on`, ne datum pořízení majetku.
  s79List: (from: string, to: string) =>
    api.get<S79Overview>('/reports/s79', { params: { from, to } }).then(r => r.data),

  s79Create: (payload: {
    kind: 'registration' | 'deregistration'
    label: string
    acquired_on: string
    effective_on: string
    asset_kind: 'inventory' | 'fixed_asset'
    vat_amount: number
    period_years?: number | null
    note?: string | null
  }) => api.post<{ id: number }>('/reports/s79', payload).then(r => r.data),

  s79Delete: (id: number) => api.delete(`/reports/s79/${id}`).then(r => r.data),

  // Hromadný export — background job (počty per část pro UI checkboxy)
  monthlyExportPreview: (period: ExportPeriodArg) =>
    api.get<MonthlyExportPreview>('/reports/monthly-export/preview', { params: monthlyExportPeriodParams(period) })
      .then(r => r.data),

  /** Spustí export job na pozadí → vrátí job_id. */
  monthlyExportStart: (period: ExportPeriodArg, parts: string[]) =>
    api.post<{ job_id: number; status: string; params: Record<string, unknown> }>(
      '/reports/monthly-export/start', { ...monthlyExportPeriodParams(period), parts },
    ).then(r => r.data),

  /** Stav jobu (polling). */
  monthlyExportJob: (id: number) =>
    api.get<MonthlyExportJob>(`/reports/monthly-export/jobs/${id}`).then(r => r.data),

  /** Poslední exporty (historie — zůstávají ke stažení dokud nejsou smazané / uklizené). */
  monthlyExportJobs: (signal?: AbortSignal) =>
    api.get<MonthlyExportJob[]>('/reports/monthly-export/jobs', { signal }).then(r => r.data),

  monthlyExportCancel: (id: number) =>
    api.post<{ ok: boolean; cancel_requested: boolean }>(`/reports/monthly-export/jobs/${id}/cancel`).then(r => r.data),

  monthlyExportDeleteJob: (id: number) =>
    api.delete<{ ok: boolean; deleted: boolean }>(`/reports/monthly-export/jobs/${id}`).then(r => r.data),

  /** URL ke stažení hotového ZIPu — otevírá se přímou navigací (cookie auth + supplier_id query). */
  monthlyExportDownloadUrl: (id: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/reports/monthly-export/jobs/${id}/download${qs ? `?${qs}` : ''}`
  },

  // Uzávěrkový balíček — background job (ZIP se všemi sestavami uzávěrky účetního
  // období). Stejný job lifecycle jako hromadný export, ale vázán na period_id.
  closingPackagePreview: (periodId: number) =>
    api.get<ClosingPackagePreview>('/reports/closing-package/preview', { params: { period_id: periodId } })
      .then(r => r.data),

  closingPackageStart: (periodId: number, parts: string[], includeXlsx: boolean) =>
    api.post<{ job_id: number; status: string; params: Record<string, unknown> }>(
      '/reports/closing-package/start', { period_id: periodId, parts, include_xlsx: includeXlsx },
    ).then(r => r.data),

  closingPackageJob: (id: number) =>
    api.get<ClosingPackageJob>(`/reports/closing-package/jobs/${id}`).then(r => r.data),

  closingPackageJobs: () =>
    api.get<ClosingPackageJob[]>('/reports/closing-package/jobs').then(r => r.data),

  closingPackageCancel: (id: number) =>
    api.post<{ ok: boolean; cancel_requested: boolean }>(`/reports/closing-package/jobs/${id}/cancel`).then(r => r.data),

  closingPackageDeleteJob: (id: number) =>
    api.delete<{ ok: boolean; deleted: boolean }>(`/reports/closing-package/jobs/${id}`).then(r => r.data),

  closingPackageDownloadUrl: (id: number) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams()
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    const qs = params.toString()
    return `/api/reports/closing-package/jobs/${id}/download${qs ? `?${qs}` : ''}`
  },

  /** URL na download endpoint — frontend ho otevírá v novém okně */
  dphDownloadUrl: (
    year: number, month: number, period?: 'monthly' | 'quarterly', acknowledgeMismatch?: boolean,
    variant: DphVariant = 'radne', dZjist?: string, reason?: string,
  ) => {
    const sid = localStorage.getItem('myinvoice.current_supplier_id')
    const params = new URLSearchParams({ year: String(year), month: String(month) })
    if (period) params.set('period', period)
    if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
    if (acknowledgeMismatch) params.set('acknowledge_mismatch', '1')
    if (variant !== 'radne') params.set('variant', variant)
    if (dZjist) params.set('d_zjist', dZjist)
    if (reason) params.set('reason', reason)
    return `/api/reports/dphdp3?${params.toString()}`
  },
}
