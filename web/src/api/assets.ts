import { api } from './client'

/**
 * Majetek a odpisy (Epic F3) — typovaný klient pro /api/accounting/assets.
 * Tenant-scoped přes X-Supplier-Id (přidává api/client.ts); zápisy vyžadují
 * roli účetní|admin. Chyby chodí jako { error: { code, message } }.
 */

export type AssetKind = 'tangible' | 'intangible'
export type AssetStatus = 'draft' | 'in_use' | 'disposed'
export type TaxMethod = 'straight' | 'accelerated' | 'extraordinary' | 'by_accounting' | 'none'
export type FirstYearIncrease = 'none' | 'p10' | 'p15' | 'p20'
/** Metoda účetních odpisů: rovnoměrně po měsících | shodně s daňovým odpisem. */
export type AccMethod = 'straight_line' | 'by_tax'
export type DisposalType = 'sold' | 'liquidated' | 'donated' | 'damaged'

export interface ImportedPlanRevision {
  revision: number
  effective_from: string
  remaining_months: number
  remaining_amount_cents: number
  reason: string
  user_id: number
  created_at: string
  fiscal_start_month: number
}

export interface ImportedDepreciationPlan {
  version: 1
  source_program: string
  source_tax_method: string
  source_accounting_method: string
  accounting: Array<{ month: string; amount_cents: number }>
  tax: Array<{ fiscal_year: number; amount_cents: number }>
  revisions?: ImportedPlanRevision[]
}

export interface Asset {
  id: number
  supplier_id: number
  inventory_number: string
  name: string
  description: string | null
  kind: AssetKind
  asset_account_code: string
  accumulated_account_code: string | null
  acquisition_account_code: string
  purchase_invoice_id: number | null
  purchase_invoice_item_id: number | null
  input_price: number
  acquisition_date: string
  put_into_use_date: string | null
  disposal_date: string | null
  disposal_type: DisposalType | null
  disposal_price: number | null
  /** Zápis deníku, který vyřazení zaúčtoval mimo modul majetku (převod, ruční zápis). */
  disposal_entry_id?: number | null
  /** Souhrnná karta účtu bez karet: účet, jehož pohyby z deníku karta nese. */
  summary_account_code?: string | null
  status: AssetStatus
  tax_method: TaxMethod
  tax_group: number | null
  tax_first_year_increase: FirstYearIncrease
  is_first_owner: boolean | number
  is_m1_vehicle: boolean | number
  m1_limit_exception: boolean | number
  is_zero_emission: boolean | number
  opening_tax_years: number
  opening_tax_amount: number
  opening_acc_months: number
  opening_acc_amount: number
  acc_useful_life_months: number | null
  acc_method: AccMethod
  acc_residual_value: number
  created_at: string
  updated_at: string
}

/** Řádek seznamu — karta + agregáty odpisů z LEFT JOIN (§3.1). */
export interface AssetListItem extends Asset {
  tax_amount_sum?: number | null
  tax_full_sum?: number | null
  acc_amount_sum?: number | null
  improvements_total?: number | null
  increased_input_price?: number | null
  tax_residual?: number | null
  acc_residual?: number | null
}

export interface AssetListResponse {
  items: AssetListItem[]
  total: number
  page: number
  per_page: number
}

export interface AssetImprovement {
  id: number
  supplier_id: number
  asset_id: number
  completed_on: string
  amount: number
  description: string | null
  purchase_invoice_id: number | null
  created_at: string
}

/** Zamčená pole (R13) — GET detailu; FE má fallback dle statusu. */
export interface AssetLocks {
  tax_params?: boolean
  acquisition?: boolean
  in_use?: boolean
}

/** Potvrzený/zaúčtovaný řádek odpisů (depreciation_entries) v detailu karty. */
export interface DepreciationEntry {
  id: number
  asset_id: number
  kind: 'tax' | 'accounting'
  fiscal_year: number
  amount: number
  full_amount: number
  residual_value_end: number
  is_paused: boolean | number
  is_half: boolean | number
  months_count: number | null
  status: 'confirmed' | 'posted'
}

export interface AssetDetail extends Asset {
  readonly imported_depreciation_plan?: ImportedDepreciationPlan | null
  improvements?: AssetImprovement[]
  entries?: DepreciationEntry[]
  improvements_total?: number
  increased_input_price?: number
  tax_residual?: number
  acc_residual?: number
  accumulated_depreciation?: number
  locked?: AssetLocks | null
}

export interface AssetWarning {
  code: string
  message: string
}

/** Odpověď zápisových operací: { asset, warnings } (AssetService). */
export interface AssetSaveResult {
  asset: AssetDetail
  warnings: AssetWarning[]
}

export interface ImprovementResult {
  improvement: AssetImprovement
  warnings: AssetWarning[]
}

export interface AssetPayload {
  inventory_number: string
  name: string
  description?: string | null
  kind: AssetKind
  asset_account_code: string
  accumulated_account_code?: string | null
  acquisition_account_code: string
  purchase_invoice_id?: number | null
  input_price: number
  acquisition_date: string
  put_into_use_date?: string | null
  status?: AssetStatus
  tax_method: TaxMethod
  tax_group?: number | null
  tax_first_year_increase?: FirstYearIncrease
  is_first_owner?: boolean
  is_m1_vehicle?: boolean
  m1_limit_exception?: boolean
  is_zero_emission?: boolean
  opening_tax_years?: number
  opening_tax_amount?: number
  opening_acc_months?: number
  opening_acc_amount?: number
  acc_useful_life_months?: number | null
  acc_method?: AccMethod
  acc_residual_value?: number
}

export interface PurchaseCandidate {
  id: number
  varsymbol: string | null
  vendor_invoice_number: string | null
  vendor: string | null
  description?: string | null
  issue_date: string | null
  tax_date: string | null
  total_without_vat: number
  total_with_vat: number
  currency: string
  exchange_rate: number | null
  vat_deduction: string | null
  has_asset: boolean | number
}

/** Řádek plánu odpisů — tvar §2.2 (tax i accounting). */
export interface DepreciationPlanRow {
  fiscal_year: number
  amount: number
  full_amount: number
  residual_start: number
  residual_end: number
  is_half: boolean | number
  is_paused: boolean | number
  months_count: number | null
  months: { month: string; amount: number }[] | null
  source: 'confirmed' | 'computed' | 'imported'
  note: string | null
  depreciation_entry_id?: number | null
  journal_entry_id?: number | null
  /** Ručně přepsaný daňový odpis roku: důvod, původní odpis a kdy (R8). */
  override_reason?: string | null
  override_original_amount?: number | null
  override_at?: string | null
}

export interface AssetSummary {
  readonly effective_acc_useful_life_months?: number | null
  input_price: number
  increased_input_price: number
  tax_residual: number
  acc_residual: number
  accumulated_depreciation: number
}

export interface DepreciationPlan {
  asset_summary: AssetSummary
  tax: DepreciationPlanRow[]
  accounting: DepreciationPlanRow[]
}

export interface AssetRevisionPayload {
  effective_from: string
  remaining_months: number
  reason: string
  expected_revision: number
}

export interface AssetRevisionPreview {
  revision: number
  effective_from: string
  remaining_months: number
  remaining_amount: number
  calendar_start_month: number
  calendar_fingerprint: string
  before: DepreciationPlan
  after: DepreciationPlan
}

export interface AssetRevisionSavePayload extends AssetRevisionPayload {
  expected_calendar_start_month: number
  expected_calendar_fingerprint: string
}

export interface BookYearResult {
  booked: number
  skipped: number
  total_accounting: number
  total_tax: number
  errors: { asset_id: number; code: string }[]
}

export interface DisposePayload {
  date: string
  type: DisposalType
  price?: number | null
  /** false = vyřazení už zaúčtoval deník, karta se vyřadí bez zaúčtování. */
  book_entry?: boolean
  entry_id?: number | null
}

/** Účet neodpisovaného majetku a stav jeho souhrnné karty. */
export interface AccountSummaryCandidate {
  account_code: string
  name: string
  balance: number
  movements: number
  active_cards: number
  summary_asset_id: number | null
  summary_value: number | null
  eligible: boolean
}

export interface AccountSummaryResult {
  asset: AssetDetail
  created: boolean
  improvements: number
  warnings: AssetWarning[]
}

export const assetsApi = {
  previewRevision: (id: number, payload: AssetRevisionPayload) =>
    api.post<AssetRevisionPreview>(`/accounting/assets/${id}/depreciation/revision/preview`, payload).then(r => r.data),
  revise: (id: number, payload: AssetRevisionSavePayload) =>
    api.post<AssetSaveResult>(`/accounting/assets/${id}/depreciation/revision`, payload).then(r => r.data),
  list: (filters?: { status?: AssetStatus | ''; q?: string; page?: number; per_page?: number }) => {
    const params: Record<string, string | number> = {}
    if (filters?.status) params.status = filters.status
    if (filters?.q) params.q = filters.q
    if (filters?.page) params.page = filters.page
    if (filters?.per_page) params.per_page = filters.per_page
    return api.get<AssetListResponse>('/accounting/assets', { params }).then(r => r.data)
  },
  get: (id: number) =>
    api.get<AssetDetail>(`/accounting/assets/${id}`).then(r => r.data),
  create: (payload: AssetPayload) =>
    api.post<AssetSaveResult>('/accounting/assets', payload).then(r => r.data),
  update: (id: number, payload: Partial<AssetPayload>) =>
    api.put<AssetSaveResult>(`/accounting/assets/${id}`, payload).then(r => r.data),
  remove: (id: number) =>
    api.delete(`/accounting/assets/${id}`).then(r => r.data),
  purchaseCandidates: () =>
    api.get<PurchaseCandidate[]>('/accounting/assets/purchase-candidates').then(r => r.data),
  putIntoUse: (id: number, payload: { date: string; book_entry?: boolean }) =>
    api.post<AssetSaveResult>(`/accounting/assets/${id}/put-into-use`, payload).then(r => r.data),
  addImprovement: (id: number, payload: { completed_on: string; amount: number; description?: string; purchase_invoice_id?: number | null }) =>
    api.post<ImprovementResult>(`/accounting/assets/${id}/improvements`, payload).then(r => r.data),
  deleteImprovement: (id: number, impId: number) =>
    api.delete(`/accounting/assets/${id}/improvements/${impId}`).then(r => r.data),
  dispose: (id: number, payload: DisposePayload) =>
    api.post<AssetSaveResult>(`/accounting/assets/${id}/dispose`, payload).then(r => r.data),
  revertDisposal: (id: number) =>
    api.post<AssetSaveResult>(`/accounting/assets/${id}/dispose/revert`).then(r => r.data),
  plan: (id: number) =>
    api.get<DepreciationPlan>(`/accounting/assets/${id}/depreciation-plan`).then(r => r.data),
  /** Inventární karta majetku (PDF, #49) — blob, tenant scope jede přes api klienta. */
  depreciationCard: (id: number) =>
    api.get<Blob>(`/accounting/assets/${id}/depreciation-card`, { responseType: 'blob' }),
  bookYear: (fiscalYear: number) =>
    api.post<BookYearResult>('/accounting/assets/depreciations/book', { fiscal_year: fiscalYear }).then(r => r.data),
  pause: (id: number, fiscalYear: number) =>
    api.post(`/accounting/assets/${id}/depreciation/pause`, { fiscal_year: fiscalYear }).then(r => r.data),
  unpause: (id: number, fiscalYear: number) =>
    api.delete(`/accounting/assets/${id}/depreciation/pause/${fiscalYear}`).then(r => r.data),
  /** Ruční přepis daňového odpisu roku s povinným důvodem. */
  overrideTax: (id: number, payload: { fiscal_year: number; amount: number; reason: string }) =>
    api.post(`/accounting/assets/${id}/depreciation/tax-override`, payload).then(r => r.data),
  clearTaxOverride: (id: number, fiscalYear: number) =>
    api.delete(`/accounting/assets/${id}/depreciation/tax-override/${fiscalYear}`).then(r => r.data),
  /** Souhrnné karty účtů bez karet (portfolio pozemků jen v deníku). */
  accountSummaryCandidates: () =>
    api.get<{ items: AccountSummaryCandidate[] }>('/accounting/assets/account-summary').then(r => r.data.items),
  syncAccountSummary: (accountCode: string) =>
    api.post<AccountSummaryResult>('/accounting/assets/account-summary', { account_code: accountCode }).then(r => r.data),
}
