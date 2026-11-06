import { api } from './client'
import type { PaymentMethod } from './invoices'

// E-mailové kontakty odběratele dle účelu (#86)
export type EmailContactUsageCode = 'communication' | 'documents' | 'reminders' | 'approvals'
export type EmailContactRecipient = 'to' | 'cc' | 'bcc'
export interface EmailContactUsage {
  usage: EmailContactUsageCode
  recipient: EmailContactRecipient
}
export interface ClientEmailContact {
  id?: number
  email: string
  label?: string | null
  contact_name?: string | null
  is_active: boolean
  sort_order?: number
  usages: EmailContactUsage[]
}

export interface ClientBankAccount {
  id: number
  client_id: number
  account_number: string
  bank_code: string | null
  iban: string | null
  source_manual: boolean
  source_vat_registry: boolean
  source_bank_statement: boolean
  last_bank_transaction_id: number | null
  is_active: boolean
  first_seen_at: string
  last_seen_at: string
}

export interface Client {
  id: number
  company_name: string
  first_name?: string | null
  last_name?: string | null
  ic?: string | null
  /** DIČ / VAT ID s country prefixem (u SK klienta = IČ DPH). */
  dic?: string | null
  /** Národní daňové číslo bez prefixu — SK DIČ, DE/AT Steuernummer, PL NIP, HU Adószám (#120). */
  tax_number?: string | null
  street: string
  city: string
  zip: string
  country_iso2: string
  /** Země klienta je členský stát EU — řídí auto-RC u identifikované osoby (#94). */
  country_is_eu?: boolean
  main_email: string | null
  phone?: string | null
  language: 'cs' | 'en'
  currency_default_id: number
  currency_default: string
  reverse_charge: boolean
  /**
   * Režim OSS u odběratele (migrace 1298). 'auto' = rozhoduje derivace ze sazby, země
   * a DIČ; 'never' = OSS se u něj neuplatňuje. Hodnota „vždy OSS" neexistuje záměrně —
   * karta smí OSS vyloučit, ne vynutit.
   */
  oss_mode?: 'auto' | 'never'
  /** Výchozí typ plnění pro OSS; null = odvodit z měrné jednotky / CZ-NACE dodavatele. */
  oss_default_supply_type?: 'goods' | 'services' | null
  /** § 36a ZDPH / § 23 odst. 7 ZDP — spojená osoba (označuje uživatel, z dat to nejde). */
  related_party?: boolean
  related_party_type?: 'capital' | 'otherwise' | 'close_person' | 'employment' | null
  related_party_note?: string | null
  /** Plátce DPH (ARES/VIES). U dodavatele řídí nárok na odpočet — neplátce ⇒ vat_deduction='none'. */
  is_vat_payer?: boolean
  is_customer?: boolean
  is_vendor?: boolean
  /** Dodavatel je benzínka — pro automatické rozpoznávání tankování v knize jízd. */
  is_fuel_station?: boolean
  auto_send_reminders: boolean
  payment_due_default?: number | null
  payment_due_unit?: 'days' | 'month' | null
  /** Předvolená forma úhrady faktur od tohoto dodavatele (migrace 1128); null = neurčeno. */
  default_payment_method?: PaymentMethod | null
  hourly_rate: number
  /** Cenová hladina odběratele; null = „Default" (hladina 0, bez slev). */
  price_level_id?: number | null
  /** Název přiřazené hladiny (jen čtení). */
  price_level_name?: string | null
  note?: string | null
  default_expense_category_id?: number | null
  default_revenue_category_id?: number | null
  // Vrací UpdateClientAction: počet faktur, do kterých byla doplněna nově nastavená
  // výchozí kategorie nákladu/tržby (pro toast po uložení klienta).
  expense_category_backfilled?: number
  revenue_category_backfilled?: number
  invoice_number_format?: string | null
  proforma_number_format?: string | null
  credit_note_number_format?: string | null
  invoice_number_period?: 'year' | 'month' | 'none' | null
  default_branding_profile_id?: number | null
  /** Předmět e-mailu s fakturou se zástupnými znaky (#277); null = výchozí. */
  email_subject_format?: string | null
  /** Název přiloženého PDF bez přípony se zástupnými znaky; null = výchozí. */
  email_attachment_name_format?: string | null
  /** Samostatný ISDOC v e-mailu; null = zdědit nastavení dodavatele. */
  email_attach_isdoc?: boolean | null
  archived_at?: string | null
  active_projects_count?: number
  invoices_count?: number
  purchase_invoices_count?: number
  /** Zakázky odběratele; bez oprávnění `projects` přijde prázdný seznam. */
  projects?: ProjectSummary[]
  /** Počet všech zakázek (i archivovaných) bez ohledu na oprávnění; řídí nabídku smazání. */
  projects_count?: number
  // total_czk fieldy slouží pro multi-currency klienty, kde frontend agreguje obraty z více měn
  // do CZK (přepočet přes i.exchange_rate fixovaný k DUZP). Single-currency klienti je ignorují.
  revenue_by_month?: Array<{ month: string; currency: string; total: number; total_czk: number }>
  revenue_by_year?:  Array<{ year: number; currency: string; total: number; total_czk: number; count: number }>
  revenue_by_project?: Array<{ project_id: number | null; project_name: string | null; currency: string; total: number; total_czk: number; count: number }>
  // Náklady (purchase_invoices) — server-side aggregované, ne závislé na paginaci listu.
  costs_by_month?: Array<{ month: string; currency: string; total: number; total_czk: number }>
  costs_by_year?:  Array<{ year: number; currency: string; total: number; total_czk: number; count: number }>
  unpaid_summary?:   Array<{ currency: string; unpaid_total: number; unpaid_total_czk: number; unpaid_count: number; overdue_total: number; overdue_total_czk: number; overdue_count: number }>
  // Cache stats z client_revenue_cache (per c.currency_default) + live computed costs
  revenue?: number
  costs?: number
  purchase_count?: number
  last_purchase_date?: string | null
  last_invoice_date?: string | null
  invoice_count?: number
  email_contacts?: ClientEmailContact[]
  bank_accounts?: ClientBankAccount[]
  created_at?: string
  updated_at?: string
  /** Jen v odpovědi POST/PUT — non-blocking varování (IČO mod 11 / DIČ formát — audit 2026-07). */
  _warnings?: string[]
  /** Jen v odpovědi POST/PUT, jen když existuje — jiná karta se stejným IČO/DIČ po normalizaci (FR 2, 2026-08-06). */
  _duplicate_candidates?: ClientDuplicateMatch[]
}

/** Jiná karta tenanta se stejným normalizovaným IČO nebo DIČ (FR 2, vendor bugreport 2026-08-06). */
export interface ClientDuplicateMatch {
  id: number
  company_name: string
  match_field: 'ic' | 'dic'
}

/** Skupina karet se shodným IČO/DIČ po normalizaci — report v přehledu dodavatelů (FR 2). */
export interface ClientDuplicateGroup {
  key_type: 'ic' | 'dic'
  key_value: string
  clients: Array<{ id: number; company_name: string; ic: string | null; dic: string | null }>
}

export interface ProjectSummary {
  id: number
  name: string
  status: 'active' | 'paused' | 'closed'
  currency: string
  hourly_rate: number
  payment_due_days: number
  project_number?: string | null
}

export interface VatStatusResult {
  id: number
  is_vat_payer: boolean
  /** Zdroj výsledku: 'ares' (CZ dle IČO), 'vies' (zahr. dle DIČ), 'unknown' (nezjištěno → uložený příznak). */
  source: 'ares' | 'vies' | 'unknown'
  ic: string | null
  dic: string | null
}

export interface AresLookupResult {
  found: boolean
  source: 'cache' | 'fresh'
  data?: {
    company_name: string
    ic: string
    dic: string
    street: string
    city: string
    zip: string
    country_iso2: string
    is_vat_payer: boolean
    date_active?: string
    legal_form?: string
    /** Zápis v OR pro PO (např. „Spisová značka C 45039 vedená u Krajského soudu v Plzni"). Prázdné u OSVČ. */
    commercial_register?: string
    /** Typ poplatníka odvozený z právní formy: 'fo' = OSVČ (DPFO), 'po' = firma (DPPO), '' = neurčeno. */
    taxpayer_type?: 'fo' | 'po' | ''
  }
}

/** Zveřejněný bankovní účet z registru plátců DPH (CRPDPH/MFČR). */
export interface CrpDphAccount {
  prefix: string
  number: string
  bank_code: string
  iban: string | null
  /** Hotový lidský zápis: „19-2000145399/0800" nebo IBAN. */
  display: string
}

export interface BankLookupResult {
  found: boolean
  /** true = nespolehlivý plátce, false = spolehlivý, null = neznámé/nenalezeno. */
  unreliable: boolean | null
  accounts: CrpDphAccount[]
  source: 'cache' | 'fresh' | 'error'
}

export interface ViesLookupResult {
  valid: boolean
  source: 'cache' | 'rest' | 'soap' | 'ares' | 'error'
  name?: string
  address?: string
  parsed?: {
    street: string
    city: string
    zip: string
  } | null
  country?: string
  vat_number?: string
}

/**
 * Národní daňové číslo vedle VAT ID (#120) — země, kde existuje a píše se na doklady,
 * s nativním labelem pole. SK: DIČ bez prefixu (má ho i neplátce; `dic` u SK = IČ DPH).
 * Jinde národní číslo = VAT ID bez prefixu nebo se na faktury neuvádí → pole se nezobrazuje.
 */
export const TAX_NUMBER_LABELS: Record<string, string> = {
  SK: 'DIČ',
  DE: 'Steuernummer',
  AT: 'Steuernummer',
  PL: 'NIP',
  HU: 'Adószám',
}

export interface ClientPayload {
  company_name: string
  first_name?: string | null
  last_name?: string | null
  ic?: string | null
  /** DIČ / VAT ID s country prefixem (u SK = IČ DPH). */
  dic?: string | null
  /** Národní daňové číslo bez prefixu (viz TAX_NUMBER_LABELS). */
  tax_number?: string | null
  street: string
  city: string
  zip: string
  country_iso2: string
  main_email?: string | null
  phone?: string | null
  language: 'cs' | 'en'
  currency_default_id: number
  reverse_charge: boolean
  /**
   * Režim OSS u odběratele (migrace 1298). 'auto' = rozhoduje derivace ze sazby, země
   * a DIČ; 'never' = OSS se u něj neuplatňuje. Hodnota „vždy OSS" neexistuje záměrně —
   * karta smí OSS vyloučit, ne vynutit.
   */
  oss_mode?: 'auto' | 'never'
  /** Výchozí typ plnění pro OSS; null = odvodit z měrné jednotky / CZ-NACE dodavatele. */
  oss_default_supply_type?: 'goods' | 'services' | null
  /** § 36a ZDPH / § 23 odst. 7 ZDP — spojená osoba (označuje uživatel, z dat to nejde). */
  related_party?: boolean
  related_party_type?: 'capital' | 'otherwise' | 'close_person' | 'employment' | null
  related_party_note?: string | null
  is_vat_payer?: boolean
  is_customer?: boolean
  is_vendor?: boolean
  /** Dodavatel je benzínka — pro automatické rozpoznávání tankování v knize jízd. */
  is_fuel_station?: boolean
  auto_send_reminders: boolean
  payment_due_default?: number | null
  payment_due_unit?: 'days' | 'month' | null
  /** Předvolená forma úhrady faktur od tohoto dodavatele (migrace 1128); null = neurčeno. */
  default_payment_method?: PaymentMethod | null
  hourly_rate?: number
  /** Posílá se JEN při zapnutém skladu; bez klíče backend hladinu nemění. */
  price_level_id?: number | null
  note?: string | null
  default_expense_category_id?: number | null
  default_revenue_category_id?: number | null
  invoice_number_format?: string | null
  proforma_number_format?: string | null
  credit_note_number_format?: string | null
  invoice_number_period?: 'year' | 'month' | 'none' | null
  default_branding_profile_id?: number | null
  /** Předmět e-mailu s fakturou se zástupnými znaky (#277); null = výchozí. */
  email_subject_format?: string | null
  /** Název přiloženého PDF bez přípony se zástupnými znaky; null = výchozí. */
  email_attachment_name_format?: string | null
  /** Samostatný ISDOC v e-mailu; null = zdědit nastavení dodavatele. */
  email_attach_isdoc?: boolean | null
  /** Replace-all (#86): pošli kompletní pole; vynech klíč, pokud kontakty neměníš. */
  email_contacts?: ClientEmailContact[]
}

export interface ListResponse<T> {
  data: T[]
  meta: { total: number; page: number; per_page: number; pages: number }
}

export interface ClientListResponse {
  data: Client[]
  meta: {
    total: number
    page: number
    per_page: number
    pages: number
    role_counts?: { all: number; customers: number; vendors: number }
  }
}

export type ClientRoleFilter = 'all' | 'customers' | 'vendors'

export const clientsApi = {
  /** `price_level`: 0 = „Default" (bez hladiny), jinak id hladiny; null/undefined = bez filtru. */
  list: (params?: { q?: string; page?: number; per_page?: number; archived?: boolean; role?: ClientRoleFilter; sort?: 'name' | 'revenue' | 'last_activity'; expense_category_id?: number | null; price_level?: number | null }) =>
    api
      .get<ClientListResponse>('/clients', {
        params: {
          q: params?.q || undefined,
          page: params?.page,
          per_page: params?.per_page,
          sort: params?.sort,
          role: params?.role && params.role !== 'all' ? params.role : undefined,
          expense_category_id: params?.expense_category_id || undefined,
          price_level: params?.price_level ?? undefined,
          ...(params?.archived ? { 'filter[archived]': 1 } : {}),
        },
      })
      .then((r) => r.data),

  get: (id: number) => api.get<Client>(`/clients/${id}`).then((r) => r.data),

  /** Report existujících duplicitních karet (stejné IČO/DIČ po normalizaci) — FR 2. */
  duplicates: () =>
    api.get<{ groups: ClientDuplicateGroup[]; count: number }>('/clients/duplicates').then((r) => r.data),

  /** Online ověření plátcovství DPH dodavatele (ARES dle IČO / VIES dle DIČ); uloží na klienta. */
  getVatStatus: (id: number) =>
    api.get<VatStatusResult>(`/clients/${id}/vat-status`).then((r) => r.data),

  create: (payload: ClientPayload) => api.post<Client>('/clients', payload).then((r) => r.data),
  update: (id: number, payload: ClientPayload) =>
    api.put<Client>(`/clients/${id}`, payload).then((r) => r.data),

  archive:   (id: number) => api.post(`/clients/${id}/archive`),
  unarchive: (id: number) => api.post(`/clients/${id}/unarchive`),
  delete:    (id: number) => api.delete(`/clients/${id}`).then((r) => r.data),

  lookupAres: (ic: string) =>
    api.post<AresLookupResult>('/clients/lookup-ares', { ic }).then((r) => r.data),
  lookupVies: (vatId: string) =>
    api.post<ViesLookupResult>('/clients/lookup-vies', { vat_id: vatId }).then((r) => r.data),
  /** Zveřejněné bankovní účty z registru plátců DPH podle DIČ. */
  lookupBank: (dic: string) =>
    api.post<BankLookupResult>('/clients/lookup-bank', { dic }).then((r) => r.data),
  listBankAccounts: (id: number) =>
    api.get<ClientBankAccount[]>(`/clients/${id}/bank-accounts`).then((r) => r.data),
  addBankAccount: (id: number, data: { account_number: string; bank_code?: string | null; iban?: string | null }) =>
    api.post<ClientBankAccount>(`/clients/${id}/bank-accounts`, data).then((r) => r.data),
  deleteBankAccount: (id: number, accountId: number) =>
    api.delete(`/clients/${id}/bank-accounts/${accountId}`).then((r) => r.data),
  syncBankAccountsFromRegistry: (id: number) =>
    api.post<{ found: boolean; source: string; synced: number; accounts: ClientBankAccount[] }>(`/clients/${id}/bank-accounts/sync-registry`).then((r) => r.data),
}
