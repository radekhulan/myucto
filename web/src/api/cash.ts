import { api } from './client'

export type CashDocType = 'in' | 'out'                                   // in = PPD, out = VPD
export type CashPurpose = 'sale' | 'purchase' | 'invoice_payment'
                        | 'purchase_payment' | 'transfer' | 'other'      // šestihodnotový (O3/C3)
export type CashDocumentStatus = 'draft' | 'posted' | 'reversed'         // BE kanon (O2/C2)

/** Rozsah nároku na odpočet DPH u řádku rozpadu (§ 75 poměrný / § 76 koeficient). */
export type CashVatDeduction = 'full' | 'none' | 'proportional' | 'reduced'
/** Uznatelnost pro daň z příjmů (§ 24/25) — na DPH nemá vliv. */
export type CashTaxTreatment = 'deductible' | 'non_deductible' | 'not_expense'

export interface CashVatLine {
  vat_rate: number; base_amount: number; vat_amount: number
  /**
   * M-7: bez těchhle polí je editor při uložení neposílal a `normalize()` je na
   * serveru resetoval na `full` / 100 / `deductible` — první uložení draftu z UI
   * tak zahodilo poměrný odpočet i neuznatelnost, které do dokladu dostal import
   * nebo API klient.
   */
  vat_deduction?: CashVatDeduction
  vat_deduction_percent?: number
  tax_treatment?: CashTaxTreatment
  /**
   * L-4: pořízení dlouhodobého majetku za hotové. Hodnota se v přiznání uvede navíc
   * na ř. 47 jako doplňující údaj k odpočtu; bez příznaku tam hotovostní nákup stroje
   * nikdy nedorazil. Jen u výdajového dokladu — u příjmového je to tržba.
   */
  is_fixed_asset?: boolean
}
// vat_rate je number — sazby se čtou z API (taxConstants per rok), ŽÁDNÝ hardcode 21|12 (A4)

export interface CashRegister {
  id: number; supplier_id: number; name: string
  currency_code: string
  account_code: string                    // DB i API klíčem je KÓD (O4/C4)
  account_id: number; account_name: string    // obohacení pro FE select
  is_default: boolean; is_active: boolean
  /** L-3: pokladna má vlastní číselnou řadu PPD/VPD; false = společná řada firmy. */
  own_series?: boolean
  documents_count: number
  balance: number; balance_date: string   // v list i detail response (O12 — bez /balance endpointu)
  balance_foreign?: number | null          // duální zůstatek valutové pokladny (§11); null u CZK
  created_at: string
}

export interface CashRegisterPayload {
  name: string; account_code: string; currency_code?: string; is_default?: boolean; own_series?: boolean
}

export interface CashDocument {
  id: number; supplier_id: number; register_id: number
  doc_type: CashDocType; purpose: CashPurpose
  doc_number: string | null               // null jen u draftu (FE v1 drafty nezobrazuje)
  issue_date: string; tax_date: string | null
  partner_name: string | null; partner_ic: string | null; partner_dic: string | null
  description: string
  total_amount: number; currency_code: string
  fx_rate?: number; amount_foreign?: number | null   // valutová pokladna (§11): total_amount = CZK ekvivalent
  vat_mode: 'none' | 'vat'; vat_lines: CashVatLine[]
  invoice_id: number | null; invoice_number?: string | null
  purchase_invoice_id: number | null; purchase_invoice_number?: string | null
  rule_key: string | null; counter_account_code: string | null
  status: CashDocumentStatus
  journal_entry_id: number | null; reversal_entry_id: number | null
  created_by: number | null; created_at: string
  created_by_name?: string | null              // obohaceno v listu (LEFT JOIN users)
  register?: { id: number; name: string | null; account_code: string | null }
}

export interface CreateCashDocumentPayload {
  register_id: number; doc_type: CashDocType; purpose: CashPurpose
  issue_date: string; tax_date?: string
  description: string; total_amount: number
  amount_foreign?: number | null; fx_rate?: number   // valutová pokladna — částka v cizí měně + volitelný ruční kurz
  partner_name?: string; partner_ic?: string; partner_dic?: string
  vat_mode?: 'none' | 'vat'; vat_lines?: CashVatLine[]
  invoice_id?: number; purchase_invoice_id?: number
  rule_key?: string; counter_account_code?: string
  post?: boolean                          // default true — create+post v 1 transakci (O2)
}

export interface CashDocumentCreateResult {
  id: number; doc_number: string | null; journal_entry_id: number | null
  status: CashDocumentStatus; warnings: string[]
}

export interface CashDocumentPostResult {
  doc_number: string; journal_entry_id: number | null; warnings: string[]
}

/**
 * Odpověď mazání. `doc_number` je vyplněné jen u dokladu, který už číslo dostal —
 * a jen tehdy backend hlásí `cash.warning.series_gap` (u draftu díra nevzniká).
 */
export interface CashDocumentDeleteResult {
  deleted: boolean; warnings: string[]
  doc_number?: string | null
  deleted_entry_ids?: number[]
}

export interface CashDocumentFilters {
  register_id?: number; doc_type?: CashDocType; purpose?: CashPurpose
  status?: CashDocumentStatus; from?: string; to?: string; q?: string
  page?: number; per_page?: number
}
export interface CashDocumentListResponse {
  items: CashDocument[]; total: number; page: number; per_page: number
}

/** Kolik neuhrazených dokladů našeptávač zobrazí (server umí max. 50). */
export const UNPAID_PAGE_SIZE = 20

export interface UnpaidDocumentOption {
  id: number; kind: 'invoice' | 'purchase_invoice'; number: string
  partner_name: string; total: number; paid: number; remaining: number
  currency_code: string; issued_on: string
  /** Zálohová faktura — úhrada se účtuje jako přijatá záloha (211/324), ne na saldokonto 311. */
  is_proforma?: boolean
  invoice_type?: string
  /** Přijatá faktura: běžná / daňový doklad k záloze (DDKP). */
  document_kind?: string | null
}

export interface CashBookItem {
  date: string; document_no: string | null; doc_type: CashDocType | null
  purpose: CashPurpose | null; tax_date: string | null
  partner_name: string | null; description: string | null
  income: number | null; expense: number | null; balance: number
  document_id: number | null; entry_id: number
}
/** Filtry pokladní knihy — `q`/`doc_type`/`purpose` zužují jen řádky, zůstatky a obraty zůstávají za období. */
export interface CashBookFilters {
  from: string; to: string
  q?: string; doc_type?: CashDocType | ''; purpose?: CashPurpose | ''
  page?: number; per_page?: number
}
export interface CashBookReport {
  register: CashRegister; opening_balance: number; items: CashBookItem[]
  income_total: number; expense_total: number; closing_balance: number
  balance_negative: boolean; total: number; page: number; per_page: number
  /** Filtrované okno se v podvojné větvi načítá do PHP po dávkách — nad limit se usekne. */
  truncated?: boolean
}

/** Předvolba „co to je" pro purpose=other — kontace s nohou na 211. */
export interface CashRulePreset {
  rule_key: string
  description: string
  /** Protiúčet odvozený z kontace — jen pro zobrazení; doklad posílá rule_key. */
  counter_account_code: string
  doc_type: 'in' | 'out'
}

export const cashApi = {
  listRulePresets: (docType?: 'in' | 'out') =>
    api.get<{ items: CashRulePreset[] }>('/accounting/cash-documents/rule-presets',
      { params: docType ? { doc_type: docType } : undefined }).then(r => r.data.items),
  listRegisters: (includeInactive = false) =>
    api.get<CashRegister[]>('/accounting/cash-registers',
      { params: includeInactive ? { include_inactive: 1 } : undefined }).then(r => r.data),
  createRegister: (p: CashRegisterPayload) =>
    api.post<CashRegister>('/accounting/cash-registers', p).then(r => r.data),
  updateRegister: (id: number, p: Partial<CashRegisterPayload> & { is_active?: boolean }) =>
    api.put<CashRegister>(`/accounting/cash-registers/${id}`, p).then(r => r.data),
  deleteRegister: (id: number) =>
    api.delete(`/accounting/cash-registers/${id}`).then(() => true),

  listDocuments: (f: CashDocumentFilters) =>
    api.get<CashDocumentListResponse>('/accounting/cash-documents', { params: f }).then(r => r.data),
  getDocument: (id: number) =>
    api.get<CashDocument>(`/accounting/cash-documents/${id}`).then(r => r.data),
  createDocument: (p: CreateCashDocumentPayload) =>
    api.post<CashDocumentCreateResult>('/accounting/cash-documents', p).then(r => r.data),
  // Úprava jen rozpracovaného (draft) dokladu — vystavený se opravuje stornem.
  updateDocument: (id: number, p: CreateCashDocumentPayload) =>
    api.put<CashDocument>(`/accounting/cash-documents/${id}`, p).then(r => r.data),
  // Zaúčtování draftu (přidělí číslo řady a založí deníkový zápis).
  postDocument: (id: number) =>
    api.post<CashDocumentPostResult>(`/accounting/cash-documents/${id}/post`).then(r => r.data),
  // Storno vrací varování stejně jako zaúčtování (posunutý protizápis, záporná pokladna).
  reverseDocument: (id: number, reason: string, entryDate?: string) =>
    api.post<{ reversal_entry_id: number | null; warnings: string[] }>(
      `/accounting/cash-documents/${id}/reverse`, { reason, entry_date: entryDate }).then(r => r.data),
  // force=1 → smaže doklad i s účetními zápisy (bez force jen draft).
  deleteDocument: (id: number, force = false) =>
    api.delete<CashDocumentDeleteResult>(`/accounting/cash-documents/${id}`,
      { params: force ? { force: 1 } : {} }).then(r => r.data),
  documentPdfUrl: (id: number) => `/api/accounting/cash-documents/${id}/pdf`,

  /**
   * L-8: vrací i příznak, že nabídka je oříznutá. Tiše oseknutý seznam tvrdí
   * „další faktura neexistuje" — uživatel pak místo úhrady vystaví hotovostní
   * prodej a DPH se vykáže dvakrát. Server se proto ptá o jeden řádek víc, než
   * kolik se zobrazí; přebytek se zahodí a jen se z něj pozná `truncated`.
   */
  /** `refundable` = vratka úhrady: nabídnou se faktury, na kterých už úhrada visí. */
  searchUnpaid: (kind: 'invoice' | 'purchase_invoice', q: string, limit = UNPAID_PAGE_SIZE, refundable = false) =>
    api.get<UnpaidDocumentOption[]>('/accounting/cash-documents/unpaid',
      { params: { kind, q, limit: limit + 1, refundable: refundable ? 1 : undefined } })
      .then(r => ({ items: r.data.slice(0, limit), truncated: r.data.length > limit })),

  getBook: (registerId: number, params: CashBookFilters) =>
    api.get<CashBookReport>(`/accounting/cash-registers/${registerId}/book`, { params }).then(r => r.data),
  bookPdfUrl: (registerId: number, f: CashBookFilters) => {
    const qs = new URLSearchParams({ from: f.from, to: f.to })
    if (f.q) qs.set('q', f.q)
    if (f.doc_type) qs.set('doc_type', f.doc_type)
    if (f.purpose) qs.set('purpose', f.purpose)
    return `/api/accounting/cash-registers/${registerId}/book/pdf?${qs.toString()}`
  },
}
