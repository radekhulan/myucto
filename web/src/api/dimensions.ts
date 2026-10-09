import { api } from './client'

export type DimensionKind = 'cost_center' | 'project' | 'vehicle' | 'location' | 'deal' | 'custom'
export type DimensionLevel = 'global' | 'company'

export interface DimensionType {
  id: number
  supplier_id: number | null
  supplier_group_id: number | null
  level: DimensionLevel
  code: string
  name: string
  kind: DimensionKind
  is_active: boolean
  show_on_documents: boolean
  sort_order: number
  /** Účtotvorná dimenze — hodnota určí analytický účet podle mapy (nejvýš jeden typ na firmu). */
  drives_accounts?: boolean
  /** Výsledkové účty, na které se mapa uplatní (výchozí `5, 6`). */
  drives_accounts_mask?: string
  /** Doklady s touto dimenzí schvaluje odpovědná osoba hodnoty (F6). */
  requires_approval?: boolean
  /** Limit v Kč bez DPH; NULL/0 = schvaluje se vždy. */
  approval_threshold?: number | null
}

/** Řádek mapy účtotvorné dimenze: hodnota × syntetika → analytika (per firma). */
export interface DimensionAccountMapRow {
  id: number
  dimension_type_id: number
  dimension_value_id: number
  synthetic_account_id: number
  synthetic_code: string
  synthetic_name: string
  analytic_account_id: number
  analytic_code: string
  analytic_name: string
  valid_from: string | null
  valid_to: string | null
}

export interface DimensionAccountMapRowPayload {
  synthetic_account_id: number
  analytic_account_id: number
  valid_from: string | null
  valid_to: string | null
}

/** Syntetika v masce účtotvorného typu a její analytiky se stejnou daňovou uznatelností. */
export interface DimensionAccountCandidate {
  id: number
  code: string
  name: string
  analytics: { id: number; code: string; name: string }[]
}

export interface DimensionValue {
  id: number
  type_id: number
  supplier_id: number | null
  supplier_group_id: number | null
  level: DimensionLevel
  parent_id: number | null
  code: string
  name: string
  is_active: boolean
  responsible_user_id: number | null
  responsible_user_name?: string | null
  responsible_note: string | null
  car_id: number | null
  car_registration?: string | null
  project_id: number | null
  project_name?: string | null
  cost_center_id: number | null
  cost_center_code?: string | null
  note: string | null
  sort_order: number
}

export interface SupplierGroupMember {
  id: number
  company_name: string
  ic: string | null
}

export interface SupplierGroup {
  id: number
  name: string
  members: SupplierGroupMember[]
}

export interface DimensionOverview {
  enabled: boolean
  group: SupplierGroup | null
  types: DimensionType[]
  values: DimensionValue[]
}

export interface DimensionGroupInfo {
  group: SupplierGroup | null
  candidates: { id: number; name: string }[]
}

/** typ → hodnota; `null` = typ bez hodnoty (při ukládání se vypustí). */
export type DimensionMap = Record<number, number | null>

/** Podíl hodnoty v rozpadu (0–1). */
export interface DimensionSplitShare {
  value_id: number
  share: number
}

/** Rozpad mezi víc hodnot: typ → seznam podílů (součet 1). */
export type DimensionSplits = Record<number, DimensionSplitShare[]>

export interface DocumentDimensions {
  header: DimensionMap
  /** pořadí položky od 1 → mapa typ → hodnota */
  items: Record<number, DimensionMap>
  /** pořadí položky (0 = hlavička) → rozpad */
  splits?: Record<number, DimensionSplits>
}

export type DimensionRuleEnforcement = 'error' | 'warning' | 'none'

export interface DimensionRule {
  id: number
  dimension_type_id: number
  type_name: string
  type_code: string
  type_kind: DimensionKind
  type_active: boolean
  account_mask: string
  enforcement: DimensionRuleEnforcement
  default_value_id: number | null
  default_value_code: string | null
  default_value_name: string | null
  default_value_active: boolean | null
  default_from_card: boolean
  valid_from: string | null
  valid_to: string | null
  is_active: boolean
  note: string | null
}

export interface DimensionRulePayload {
  dimension_type_id: number
  account_mask: string
  enforcement: DimensionRuleEnforcement
  default_value_id: number | null
  default_from_card: boolean
  valid_from: string | null
  valid_to: string | null
  is_active: boolean
  note: string | null
}

export interface DimensionRuleWarning {
  account_code: string
  account_name: string
  type_id: number
  type_name: string
  message: string
}

export interface DimensionRuleAuditRow {
  line_id: number
  entry_id: number
  entry_date: string
  document_no: string | null
  source_type: string
  source_id: number | null
  account_code: string
  account_name: string
  side: 'debit' | 'credit'
  amount: number
  type_id: number
  type_name: string
  enforcement: 'error' | 'warning'
}

export interface DimensionRuleAuditSummary {
  type_id: number
  type_name: string
  synthetic: string
  enforcement: 'error' | 'warning'
  source_type: string
  lines: number
  amount: number
}

export interface DimensionRuleAudit {
  rows: DimensionRuleAuditRow[]
  summary: DimensionRuleAuditSummary[]
  total: number
  truncated: boolean
}

export interface DimensionCoverageRow {
  type_id: number
  type_name: string
  synthetic: string
  lines: number
  covered: number
  ratio: number
  suggested: boolean
}

export interface DocumentDimensionsSaveResult extends DocumentDimensions {
  /** `locked` = zápis leží v uzavřeném nebo zamčeném období (mění se jen analytika). */
  restamp: { lines: number; needs_repost: boolean; account_change?: boolean; locked?: boolean }
}

/** Zaúčtovaný řádek dokladu s dimenzemi, jaké by nesl po uložení (náhled). */
export interface DocumentDimensionsPreviewLine {
  id: number
  entry_id: number
  account_code: string | null
  account_name: string | null
  side: 'debit' | 'credit'
  amount: number
  is_red_storno: boolean
  dimensions: Record<number, number>
}

export interface DocumentDimensionsPreview extends DocumentDimensionsSaveResult {
  /** Uložení by se odmítlo: řádek by bylo nutné rozdělit, ale zápis je v uzavřeném období. */
  refused: boolean
  lines: DocumentDimensionsPreviewLine[]
}

export interface DocumentDimensionsPayload {
  header: DimensionMap
  /** Bez položek zůstanou dimenze položek dokladu beze změny. */
  items?: Record<number, DimensionMap>
  /** Bez rozpadů zůstane rozpad dokladu beze změny (jen typ s novou jedinou hodnotou ho ztratí). */
  splits?: Record<number, DimensionSplits>
}

/** Tělo pro uložení i náhled; `items` a `splits` jen tehdy, když je volající opravdu posílá. */
export function documentDimensionsBody(payload: DocumentDimensionsPayload) {
  return {
    header: compactDimensions(payload.header),
    ...(payload.items === undefined ? {} : {
      items: Object.fromEntries(Object.entries(payload.items).map(([no, map]) => [no, compactDimensions(map)])),
    }),
    ...(payload.splits === undefined ? {} : { splits: payload.splits }),
  }
}

export type DimensionDocType = 'purchase-invoices' | 'invoices' | 'cash-documents' | 'bank-transactions' | 'journal-templates' | 'recurring-templates' | 'other-items' | 'assets'

/** Karta s výchozími dimenzemi (klient slouží jako odběratel i dodavatel). */
export type DimensionDefaultsEntity = 'clients' | 'projects' | 'stock/items' | 'eshop/categories'

/** history = návrh z posledního přijatého dokladu téhož dodavatele (nejnižší přednost). */
export type DimensionPrefillSource = 'project' | 'client' | 'document' | 'history'

/** Předvyplnění hlavičky dokladu: typ → hodnota a odkud se vzala. */
export interface DimensionPrefill {
  header: Record<number, number>
  sources: Record<number, DimensionPrefillSource>
}

export interface DimensionPrefillParams {
  client_id?: number | null
  project_id?: number | null
  invoice_id?: number | null
  purchase_invoice_id?: number | null
  /** 1 = prázdné typy doplnit z historie dodavatele (client_id). */
  history?: 0 | 1
  /** Kontrolovaný přijatý doklad — do historie se nepočítá. */
  exclude_purchase_invoice_id?: number | null
}

export interface DimensionTypePayload {
  code?: string
  name?: string
  kind?: DimensionKind
  level?: DimensionLevel
  is_active?: boolean
  show_on_documents?: boolean
  sort_order?: number
  drives_accounts?: boolean
  drives_accounts_mask?: string
  requires_approval?: boolean
  approval_threshold?: number | null
}

export interface DimensionValuePayload {
  code?: string
  name?: string
  parent_id?: number | null
  is_active?: boolean
  responsible_user_id?: number | null
  responsible_note?: string | null
  car_id?: number | null
  project_id?: number | null
  cost_center_id?: number | null
  note?: string | null
  sort_order?: number
}

export interface DimensionProfitAmounts {
  revenue: number
  cost: number
  result: number
}

export interface DimensionProfitRow {
  value_id: number
  parent_id: number | null
  code: string
  name: string
  is_active: boolean
  depth: number
  has_children: boolean
  own: DimensionProfitAmounts
  total: DimensionProfitAmounts
  responsible_user_id?: number | null
  responsible_user_name?: string | null
}

/** Rozpad po syntetických účtech: řádky = účty, sloupce = kořeny sestavy (+ bez hodnoty). */
export interface DimensionProfitMatrix {
  columns: { key: string; value_id: number | null; code: string; name: string | null }[]
  rows: { code: string; name: string; account_type: 'revenue' | 'expense'; cells: number[]; total: number }[]
  results: number[]
  total_result: number
}

export interface DimensionProfitReport {
  type: DimensionType
  from: string
  to: string
  supplier_ids: number[]
  /** `cash_journal` = firma v daňové evidenci, sestava z peněžního deníku (příjmy a výdaje). */
  basis?: 'journal' | 'cash_journal'
  hidden_companies: number
  value_id?: number | null
  responsible_user_id?: number | null
  /** Omezeno na větev nebo odpovědnou osobu — řádek „bez hodnoty" se nevykazuje. */
  restricted?: boolean
  rows: DimensionProfitRow[]
  unassigned: DimensionProfitAmounts
  totals: DimensionProfitAmounts
  companies?: (DimensionProfitAmounts & { id: number; name: string })[]
  matrix?: DimensionProfitMatrix
}

export interface DimensionProfitParams {
  type_id: number
  from: string
  to: string
  scope?: 'group'
  value_id?: number
  responsible_user_id?: number
  accounts?: 1
  companies?: 1
}

export interface DimensionAnalyticsAmounts extends DimensionProfitAmounts {
  tax_deductible_cost: number
  non_deductible_cost: number
  income_tax_cost: number
}

export interface DimensionAnalyticsMonth extends DimensionAnalyticsAmounts {
  month: string
}

export interface DimensionAnalyticsReport {
  type: DimensionType
  year: number
  supplier_ids: number[]
  basis?: 'journal' | 'cash_journal'
  rows: DimensionProfitRow[]
  unassigned: DimensionAnalyticsAmounts
  totals: DimensionAnalyticsAmounts
  value_totals: Record<string, DimensionAnalyticsAmounts>
  monthly: DimensionAnalyticsMonth[]
  previous_monthly: DimensionAnalyticsMonth[]
  value_monthly: Record<string, DimensionAnalyticsMonth[]>
  companies: Array<{ id: number; name: string } & DimensionAnalyticsAmounts>
  company_value_totals: Record<string, Record<string, DimensionAnalyticsAmounts>>
  available_companies: SupplierGroupMember[]
  hidden_companies: number
}

export interface DimensionCashFlowAccount {
  account_code: string
  name: string
  amount: number
}

export interface DimensionCashFlowGroup {
  total: number
  accounts: DimensionCashFlowAccount[]
}

/** Peněžní tok nepřímou metodou (celá firma nebo hodnota dimenze). */
export interface DimensionCashFlowReport {
  from: string
  to: string
  supplier_ids: number[]
  hidden_companies: number
  dimension: { type_id: number; value_id: number; value_ids: number[]; label: string | null } | null
  profit: number
  non_cash: DimensionCashFlowGroup
  working_capital: DimensionCashFlowGroup
  operating: number
  investing: DimensionCashFlowGroup
  financing: DimensionCashFlowGroup
  net_cash_flow: number
  cash_movement: number
  untagged_cash: number
  reconciles: boolean
}

export interface DimensionCashFlowParams {
  from: string
  to: string
  dimension_value_id?: number
  dimension_descendants?: 0 | 1
  scope?: 'group'
}

/** Vyhodí z mapy prázdné typy — server bere jen vyplněné dvojice. */
export function compactDimensions(map: DimensionMap | null | undefined): Record<number, number> {
  const out: Record<number, number> = {}
  for (const [typeId, valueId] of Object.entries(map ?? {})) {
    if (valueId) out[Number(typeId)] = valueId
  }
  return out
}

export const dimensionsApi = {
  overview: () => api.get<DimensionOverview>('/accounting/dimensions').then(r => r.data),
  setEnabled: (enabled: boolean, createDefaults = false) =>
    api.put<DimensionOverview>('/accounting/dimensions/settings', { enabled, create_defaults: createDefaults }).then(r => r.data),
  createDefaults: () => api.post<DimensionOverview>('/accounting/dimensions/defaults').then(r => r.data),

  createType: (payload: DimensionTypePayload) =>
    api.post<DimensionType>('/accounting/dimensions/types', payload).then(r => r.data),
  updateType: (id: number, payload: DimensionTypePayload) =>
    api.patch<DimensionType>(`/accounting/dimensions/types/${id}`, payload).then(r => r.data),
  deleteType: (id: number) =>
    api.delete<{ deleted: boolean }>(`/accounting/dimensions/types/${id}`).then(r => r.data),

  createValue: (typeId: number, payload: DimensionValuePayload) =>
    api.post<DimensionValue>(`/accounting/dimensions/types/${typeId}/values`, payload).then(r => r.data),
  updateValue: (id: number, payload: DimensionValuePayload) =>
    api.patch<DimensionValue>(`/accounting/dimensions/values/${id}`, payload).then(r => r.data),
  deleteValue: (id: number) =>
    api.delete<{ deleted: boolean }>(`/accounting/dimensions/values/${id}`).then(r => r.data),
  accountMap: (valueId?: number) =>
    api.get<DimensionAccountMapRow[]>('/accounting/dimensions/account-map', { params: valueId ? { value_id: valueId } : {} }).then(r => r.data),
  accountCandidates: (typeId: number) =>
    api.get<DimensionAccountCandidate[]>(`/accounting/dimensions/types/${typeId}/account-candidates`).then(r => r.data),
  saveAccountMap: (valueId: number, rows: DimensionAccountMapRowPayload[]) =>
    api.put<DimensionAccountMapRow[]>(`/accounting/dimensions/values/${valueId}/account-map`, { rows }).then(r => r.data),
  responsibleCandidates: () =>
    api.get<{ id: number; name: string }[]>('/accounting/dimensions/responsible-candidates').then(r => r.data),

  getDocument: (doc: DimensionDocType, id: number) =>
    api.get<DocumentDimensions>(`/accounting/dimensions/documents/${doc}/${id}`).then(r => r.data),
  saveDocument: (doc: DimensionDocType, id: number, payload: DocumentDimensionsPayload) =>
    api.put<DocumentDimensionsSaveResult>(`/accounting/dimensions/documents/${doc}/${id}`, documentDimensionsBody(payload))
      .then(r => r.data),
  /** Co by po uložení nesl každý zaúčtovaný řádek dokladu — nic se neuloží. */
  previewDocument: (doc: DimensionDocType, id: number, payload: DocumentDimensionsPayload) =>
    api.post<DocumentDimensionsPreview>(`/accounting/dimensions/documents/${doc}/${id}/preview`, documentDimensionsBody(payload))
      .then(r => r.data),

  getDefaults: (entity: DimensionDefaultsEntity, id: number) =>
    api.get<{ dimensions: Record<number, number> }>(`/${entity}/${id}/dimensions`).then(r => r.data.dimensions),
  saveDefaults: (entity: DimensionDefaultsEntity, id: number, map: DimensionMap) =>
    api.put<{ dimensions: Record<number, number> }>(`/${entity}/${id}/dimensions`, { dimensions: compactDimensions(map) })
      .then(r => r.data.dimensions),
  prefill: (params: DimensionPrefillParams) =>
    api.get<DimensionPrefill>('/accounting/dimensions/prefill', {
      params: Object.fromEntries(Object.entries(params).filter(([, v]) => v != null && v > 0)),
    }).then(r => r.data),

  getJournal: (entryId: number) =>
    api.get<Record<number, Record<number, number>>>(`/accounting/dimensions/journal/${entryId}`).then(r => r.data),
  /** `splits` = id řádku → rozpad; bez nich zůstanou rozpady řádků beze změny. */
  saveJournal: (entryId: number, lines: Record<number, DimensionMap>, splits?: Record<number, DimensionSplits>) =>
    api.put<{ changed: number; lines: Record<number, Record<number, number>>; splits: Record<number, DimensionSplits> }>(`/accounting/dimensions/journal/${entryId}`, {
      lines: Object.fromEntries(Object.entries(lines).map(([id, map]) => [id, compactDimensions(map)])),
      ...(splits === undefined ? {} : { splits }),
    }).then(r => r.data),

  listRules: () => api.get<DimensionRule[]>('/accounting/dimensions/rules').then(r => r.data),
  createRule: (payload: DimensionRulePayload) =>
    api.post<DimensionRule>('/accounting/dimensions/rules', payload).then(r => r.data),
  updateRule: (id: number, payload: DimensionRulePayload) =>
    api.put<DimensionRule>(`/accounting/dimensions/rules/${id}`, payload).then(r => r.data),
  deleteRule: (id: number) =>
    api.delete<{ deleted: boolean }>(`/accounting/dimensions/rules/${id}`).then(r => r.data),
  /** Zaúčtované řádky, kterým podle pravidel chybí dimenze. */
  auditRules: (params: { date_from: string; date_to: string }) =>
    api.get<DimensionRuleAudit>('/accounting/dimensions/rules/audit', { params }).then(r => r.data),
  /** Pokrytí syntetických účtů dimenzemi — podklad pro návrh pravidel. */
  ruleCoverage: (params: { date_from: string; date_to: string }) =>
    api.get<DimensionCoverageRow[]>('/accounting/dimensions/rules/coverage', { params }).then(r => r.data),

  group: () => api.get<DimensionGroupInfo>('/accounting/dimensions/group').then(r => r.data),
  createGroup: (name: string) => api.post<DimensionGroupInfo>('/accounting/dimensions/group', { name }).then(r => r.data),
  joinGroup: (groupId: number) => api.put<DimensionGroupInfo>('/accounting/dimensions/group', { group_id: groupId }).then(r => r.data),
  renameGroup: (name: string) => api.put<DimensionGroupInfo>('/accounting/dimensions/group', { name }).then(r => r.data),
  leaveGroup: () => api.delete<DimensionGroupInfo>('/accounting/dimensions/group').then(r => r.data),

  profit: (params: DimensionProfitParams) =>
    api.get<DimensionProfitReport>('/accounting/reports/dimension-profit', { params }).then(r => r.data),
  analytics: (params: { type_id: number; year: number; supplier_id?: number | 'all' }) =>
    api.get<DimensionAnalyticsReport>('/accounting/reports/dimension-analytics', { params }).then(r => r.data),
  exportProfit: (params: DimensionProfitParams, format: 'xlsx' | 'pdf' = 'xlsx') =>
    api.get<Blob>('/accounting/reports/dimension-profit/export', { params: { ...params, format }, responseType: 'blob' }).then(r => r.data),
  exportAnalytics: (params: { type_id: number; year: number; supplier_id?: number | 'all'; table: 'comparison' | 'companies' | 'monthly'; value_id?: number | 'total' | 'unassigned'; metric?: 'revenue' | 'cost' | 'result'; format: 'xlsx' | 'pdf' }) =>
    api.get<Blob>('/accounting/reports/dimension-analytics/export', { params, responseType: 'blob' }).then(r => r.data),
  cashFlow: (params: DimensionCashFlowParams) =>
    api.get<DimensionCashFlowReport>('/accounting/reports/dimension-cash-flow', { params }).then(r => r.data),
  exportCashFlow: (params: DimensionCashFlowParams) =>
    api.get<Blob>('/accounting/reports/dimension-cash-flow/export', { params, responseType: 'blob' }).then(r => r.data),
}
