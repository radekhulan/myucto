import { api } from './client'

export type TaxpayerType = 'fo' | 'po'
export type TaxReturnVariant = 'radne' | 'opravne' | 'dodatecne'

export interface TaxReturnLine {
  line: number | string
  code: string
  label: string
  value: number
  source: string
}

export interface TaxReturnAmendment {
  new_tax: number
  last_known_tax: number
  tax_difference: number
  d_zjist: string
  reason: string
  warnings?: string[]
}

// Feature 1 — projekce dosud nezaúčtovaných závěrkových operací do VH (náhled DPPO).
export interface TaxReturnProjectionItem {
  key: string
  label_key: string
  amount: number
  sign: number
  optional: boolean
}

export interface TaxReturnProjection {
  vh_posted: number
  vh_projected: number
  projected_base: number
  projected_tax: number
  is_projection: boolean
  items: TaxReturnProjectionItem[]
}

// Feature 2 — auto-návrhy připočitatelných (§25) / odečitatelných (§20) položek k ověření účetní.
export interface TaxReturnAddbackSuggestion {
  account_code: string
  name: string
  amount: number
  hint_key: string
  already_non_deductible: boolean
}

export interface TaxReturnDeductionSuggestion {
  key: string
  account_code: string
  amount: number
  hint_key: string
}

export type CheckSeverity = 'info' | 'warning' | 'blocker'

export interface PreFinalizeCheck {
  key: string
  severity: CheckSeverity
  ok: boolean
  na?: boolean
  value: Record<string, unknown> | null
}

export interface PreFinalizeCheckResult {
  checks: PreFinalizeCheck[]
  summary: { ok: number; warning: number; blocker: number; na: number }
  can_finalize: boolean
}

// Bankovní účet z podkladů DPPO (podklady.bank_accounts) — volba pro VetaNP (vrácení
// přeplatku), viz DppoReturnDataProvider::bankAccounts / IncomeTaxReport.vue.
export interface TaxReturnBankAccount {
  id: number
  account_number: string | null
  bank_code: string | null
  bank_name: string | null
  iban: string | null
  is_default: number
}

export interface AvailableVariant {
  variant: TaxReturnVariant
  variant_seq: number
  status: 'draft' | 'final'
  row_version: number
  updated_at: string | null
  submitted_at: string | null
  submission_status: string | null
}

export interface TaxReturnState {
  return: {
    year: number
    type: TaxpayerType
    variant: TaxReturnVariant
    variant_seq: number
    status: 'draft' | 'final'
    row_version: number
    inputs: Record<string, unknown>
    last_submission_id: number | null
    updated_at: string | null
  }
  form_code: string
  variant: TaxReturnVariant
  variant_seq: number
  available_variants: AvailableVariant[]
  last_known_tax_suggested: number | null
  computed: {
    lines: TaxReturnLine[]
    tax?: number
    balance_due?: number
    next_advances?: { regime: string; count: number; amount: number; total: number; note: string; filing_deadline?: string }
    summary?: Record<string, number>
    amendment?: TaxReturnAmendment
    projection?: TaxReturnProjection | null
    warnings?: string[]
  }
  podklady: Record<string, unknown>
  warnings: string[]
  prefinalize_check: PreFinalizeCheckResult | null
  constants_year: number
}

export interface InsuranceBranch {
  assessment_base: number
  min_base: number
  insurance: number
  advances_paid: number
  balance_due: number
  monthly_advance: number
  is_secondary: boolean
  note: string
  participates?: boolean
  min_applies?: boolean
  sickness?: { insured: boolean; monthly_base: number; monthly_premium: number; annual: number }
}

export type AdvanceKind = 'tax' | 'social' | 'health'

export interface AdvanceSchedule {
  id: number
  taxpayer_type: TaxpayerType
  advance_kind: AdvanceKind
  period_year: number
  seq_no: number
  amount: number
  due_date: string
  variable_symbol: string | null
  status: 'planned' | 'paid'
  paid_amount: number
  paid_on: string | null
  matched_transaction_id: number | null
  /** Zdroj úhrady: bank = spárováno s transakcí, manual = ručně potvrzeno účetní. */
  paid_source: 'bank' | 'manual'
  match_confidence: 'exact' | 'uncertain'
  is_overdue: boolean
}

export type AdvancePeriodicity = 'quarterly' | 'semiannual' | 'annual' | 'none'

// #43/#46 — rozhodnutí FÚ o výši záloh (§174 DŘ) s rozsahem OD-DO (effective_to = null → otevřený konec).
export interface AdvanceOverride {
  id: number
  taxpayer_type: TaxpayerType
  advance_kind: AdvanceKind
  period_year: number
  effective_from: string
  effective_to: string | null
  amount: number
  periodicity: AdvancePeriodicity
  note: string | null
  source: 'fu_decision' | 'manual'
}

export interface AdvanceOverrideInput {
  amount: number
  periodicity: AdvancePeriodicity
  effective_from: string
  effective_to?: string | null
  note?: string
  source?: 'fu_decision' | 'manual'
}

// #46 — globální přehled: rozhodnutí FÚ napříč roky + předpis placení záloh napříč roky.
export interface AdvanceOverridesOverview {
  overrides: AdvanceOverride[]
  schedules: AdvanceSchedule[]
}

export interface AdvanceMatchResult {
  matched: number
  /** Součty ze spárovaných bankovních plateb — návrh, ne nutně to, co je v přiznání. */
  totals: { tax: number; social: number; health: number }
  details: Array<{
    schedule_id: number
    kind: AdvanceKind
    due_date: string
    expected_amount: number
    paid_amount: number
    paid_on: string
    transaction_id: number
    /** Nebyla nalezena transakce se shodnou částkou — spárováno jen dle VS a data (ověřit). */
    amount_mismatch: boolean
  }>
  /** Druhy, kde se návrh skutečně zapsal do přiznání (pole bylo prázdné). */
  applied: AdvanceKind[]
  /** Druhy, kde v přiznání už byla ruční nenulová hodnota — NEpřepsáno, jen návrh v `totals`. */
  skipped_existing: AdvanceKind[]
  conflict: boolean
  return_prefilled: boolean
}

// Featura A — rekonciliace proti PODANÉMU přiznání (upload EPO XML DPPDP9 od účetní).
export interface ReconcileFilingInfo {
  verze_pis: string
  dapdpp_forma: string
  typ_zo: string
  zdobd_od: string | null
  zdobd_do: string | null
  supplier: { ic: string; dic: string; name: string }
  rate_pct: number | null
}

export interface ReconcileAmendment {
  kc_dppiv1: number | null
  kc_dppiv2: number | null
  kc_dppiv3: number | null
  d_zjist: string
}

export interface ReconcileExtraLine {
  value: number
  label: string
}

export interface ReconcileDiffRow {
  line: number
  code: string
  label: string
  our_value: number
  filed_value: number
  diff: number
  match: boolean
  filed_present: boolean
}

export interface ReconcileDiff {
  rows: ReconcileDiffRow[]
  matched: number
  mismatched: number
  max_abs_diff: number
  max_abs_diff_line: number | null
}

export interface ReconcileResult {
  filing: ReconcileFilingInfo
  amendment: ReconcileAmendment
  extra: Record<string, ReconcileExtraLine>
  diff: ReconcileDiff
  warnings: string[]
  variant: TaxReturnVariant
  variant_seq: number
  return_status: 'draft' | 'final' | null
}

export interface InsuranceSummary {
  year: number
  tax_base_7: number
  is_secondary: boolean
  social: InsuranceBranch
  health: InsuranceBranch
  deadlines: { social: string; health: string; note: string }
  rates: Record<string, number>
  warnings: string[]
}

function supplierParam(params: URLSearchParams): void {
  const sid = localStorage.getItem('myinvoice.current_supplier_id')
  if (sid && /^\d+$/.test(sid)) params.set('supplier_id', sid)
}

// Druh přiznání + pořadí dodatečného jde jako query ?variant=&seq= (aditivní k routám;
// 'radne' + seq 1 = default/BC). seq má smysl jen u dodatečného (jinak se vynechá).
function vsQuery(variant?: TaxReturnVariant, seq?: number): string {
  const p = new URLSearchParams()
  if (variant && variant !== 'radne') p.set('variant', variant)
  if (variant === 'dodatecne' && seq && seq > 0) p.set('seq', String(seq))
  const s = p.toString()
  return s ? `?${s}` : ''
}

export const taxReturnApi = {
  get: (type: TaxpayerType, year: number, variant?: TaxReturnVariant, seq?: number) =>
    api.get<TaxReturnState>(`/tax-return/${type}/${year}${vsQuery(variant, seq)}`).then(r => r.data),

  saveInputs: (type: TaxpayerType, year: number, inputs: Record<string, unknown>, rowVersion: number, variant?: TaxReturnVariant, seq?: number) =>
    api.put<TaxReturnState>(`/tax-return/${type}/${year}/inputs${vsQuery(variant, seq)}`, { inputs, row_version: rowVersion }).then(r => r.data),

  finalize: (type: TaxpayerType, year: number, rowVersion: number, variant?: TaxReturnVariant, seq?: number) =>
    api.post<TaxReturnState>(`/tax-return/${type}/${year}/finalize${vsQuery(variant, seq)}`, { row_version: rowVersion }).then(r => r.data),

  reopen: (type: TaxpayerType, year: number, rowVersion: number, variant?: TaxReturnVariant, seq?: number) =>
    api.post<TaxReturnState>(`/tax-return/${type}/${year}/reopen${vsQuery(variant, seq)}`, { row_version: rowVersion }).then(r => r.data),

  insurance: (year: number) =>
    api.get<InsuranceSummary>(`/tax-return/fo/${year}/insurance`).then(r => r.data),

  xmlUrl: (type: TaxpayerType, year: number, variant?: TaxReturnVariant, seq?: number): string => {
    const params = new URLSearchParams()
    supplierParam(params)
    if (variant && variant !== 'radne') params.set('variant', variant)
    if (variant === 'dodatecne' && seq && seq > 0) params.set('seq', String(seq))
    return `/api/tax-return/${type}/${year}/xml?${params.toString()}`
  },
  previewXmlUrl: (year: number, variant?: TaxReturnVariant, seq?: number): string => {
    const params = new URLSearchParams()
    supplierParam(params)
    if (variant && variant !== 'radne') params.set('variant', variant)
    if (variant === 'dodatecne' && seq && seq > 0) params.set('seq', String(seq))
    return `/api/tax-return/fo/${year}/xml/preview?${params.toString()}`
  },

  insurancePdfUrl: (year: number): string => {
    const params = new URLSearchParams()
    supplierParam(params)
    return `/api/tax-return/fo/${year}/insurance/pdf?${params.toString()}`
  },

  csszXmlUrl: (year: number): string => {
    const params = new URLSearchParams()
    supplierParam(params)
    return `/api/tax-return/fo/${year}/insurance/xml/cssz?${params.toString()}`
  },

  // E11 — PDF Přehled OSVČ pro zdravotní pojišťovnu.
  healthPdfUrl: (year: number): string => {
    const params = new URLSearchParams()
    supplierParam(params)
    return `/api/tax-return/fo/${year}/insurance/pdf/health?${params.toString()}`
  },

  // E9 — předpisy záloh na daň a pojistné.
  advances: (type: TaxpayerType, year: number) =>
    api.get<{ year: number; schedules: AdvanceSchedule[] }>(`/tax-return/${type}/${year}/advances`).then(r => r.data),

  generateAdvances: (type: TaxpayerType, year: number) =>
    api.post<Record<string, number>>(`/tax-return/${type}/${year}/advances/generate`, {}).then(r => r.data),

  matchAdvances: (type: TaxpayerType, year: number) =>
    api.post<AdvanceMatchResult>(`/tax-return/${type}/${year}/advances/match`, {}).then(r => r.data),

  // #42 — vygenerovat předpisy PRO tento rok (z draftu min. roku / z rozhodnutí FÚ).
  generateAdvancesForPeriod: (type: TaxpayerType, year: number) =>
    api.post<Record<string, number>>(`/tax-return/${type}/${year}/advances/generate-period`, {}).then(r => r.data),

  // #46 — rozhodnutí FÚ s rozsahem OD-DO: id-based CRUD napříč roky (globální tabulka).
  // (Starší per-rok varianta `advances/override` byla odstraněna — nahrazena id-based CRUD.)
  // {year} v URL je pro globální operace ignorováno (rozsah je napříč roky) — posílá se aktuální rok stránky.
  advanceOverrides: (type: TaxpayerType, year: number) =>
    api.get<AdvanceOverridesOverview>(`/tax-return/${type}/${year}/advances/overrides`).then(r => r.data),

  createAdvanceOverride: (type: TaxpayerType, year: number, body: AdvanceOverrideInput) =>
    api.post<AdvanceOverridesOverview & { override: AdvanceOverride }>(
      `/tax-return/${type}/${year}/advances/overrides`, body).then(r => r.data),

  updateAdvanceOverrideEntry: (type: TaxpayerType, year: number, id: number, body: AdvanceOverrideInput) =>
    api.put<AdvanceOverridesOverview & { override: AdvanceOverride }>(
      `/tax-return/${type}/${year}/advances/overrides/${id}`, body).then(r => r.data),

  deleteAdvanceOverrideEntry: (type: TaxpayerType, year: number, id: number) =>
    api.delete<AdvanceOverridesOverview & { deleted: boolean }>(
      `/tax-return/${type}/${year}/advances/overrides/${id}`).then(r => r.data),

  // #43 bod 3 — ruční úprava výše a potvrzení úhrad.
  updateAdvanceAmount: (type: TaxpayerType, year: number, scheduleId: number, amount: number) =>
    api.post<{ schedules: AdvanceSchedule[] }>(`/tax-return/${type}/${year}/advances/${scheduleId}/amount`, { amount }).then(r => r.data),

  confirmAdvance: (type: TaxpayerType, year: number, scheduleId: number, amount?: number, paidOn?: string) =>
    api.post<{ schedules: AdvanceSchedule[]; return_prefilled: boolean }>(
      `/tax-return/${type}/${year}/advances/${scheduleId}/confirm`, { amount, paid_on: paidOn }).then(r => r.data),

  unconfirmAdvance: (type: TaxpayerType, year: number, scheduleId: number) =>
    api.post<{ schedules: AdvanceSchedule[] }>(`/tax-return/${type}/${year}/advances/${scheduleId}/unconfirm`, {}).then(r => r.data),

  confirmAllAdvances: (type: TaxpayerType, year: number, kind?: AdvanceKind) =>
    api.post<{ schedules: AdvanceSchedule[]; confirmed: number; return_prefilled: boolean }>(
      `/tax-return/${type}/${year}/advances/confirm-all`, { kind }).then(r => r.data),

  upcomingAdvances: () =>
    api.get<{ items: AdvanceSchedule[] }>(`/tax-return/advances/upcoming`).then(r => r.data),

  // Featura A — rekonciliace proti PODANÉMU přiznání (jen DPPO/po).
  reconcile: (year: number, file: File, variant?: TaxReturnVariant, seq?: number) => {
    const fd = new FormData()
    fd.append('file', file, file.name)
    return api.post<ReconcileResult>(`/tax-return/po/${year}/reconcile${vsQuery(variant, seq)}`, fd, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
}
