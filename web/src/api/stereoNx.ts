import { api } from './client'
import { downloadApiFile } from '@/utils/downloadFile'
import { uploadChunked, type ChunkedUploadProgress } from './chunkedUpload'
import { fetchImportJob, type FileImportJob } from './imports'
import type { MoneyS3ProtocolData, MoneyS3RunStatus } from './moneyS3'
import type { MigrationNonprofitNotice } from './migrationApi'

const BASE = '/admin/imports/stereo-nx'
const POLL_MS = 2000

export interface StereoCompany {
  index: number
  label: string
  identity: { ico: string; dic: string; name: string; vat_payer: boolean; accounting_mode?: 'tax_evidence' | 'double_entry' | null }
  bank_account_series?: {
    requires_confirmation: boolean
    ambiguous: boolean
    assignments: Array<{ account_index: number; account_label: string; series: string }>
    missing_accounts: number
    unmatched_series: string[]
  }
  matches_target: boolean
  profile_suggestions?: Partial<Record<'company_name' | 'dic' | 'street' | 'city' | 'zip' | 'email' | 'phone' | 'web', string>>
  profile_current?: Partial<Record<'company_name' | 'dic' | 'street' | 'city' | 'zip' | 'email' | 'phone' | 'web', string>>
}

export interface StereoPreview {
  companies: StereoCompany[]
  target: { ico: string; accounting_mode: string; vat_payer: boolean }
  nonprofit_notice?: MigrationNonprofitNotice
}

export interface StereoAccountMapping {
  accounts: Record<string, string>
  bank_accounts: Record<string, string>
  vat: null | { input: string; output: string; settlement: string; unresolved: string | null }
}

export interface StereoAccountMappingProposal extends StereoAccountMapping {
  used_accounts: Array<{ source_code: string; name: string; target_code: string; count: number }>
  banks: Array<{ source_key: string; label: string; target_code: string; identity_key?: string }>
  unresolved: Array<{
    source_key: string; side: string; source_code: string; reason: string
    date?: string; document_no?: string; description?: string; amount_cents?: number
    is_opening?: boolean; debit?: string; credit?: string; source_name?: string
  }>
  vat_counts: { input: number; output: number; settlement: number; unresolved: number; reverse_charge_unresolved?: number }
  available_accounts?: Array<{ code: string; name: string; is_active?: boolean }>
  suggested_accounts?: Array<{ code: string; name: string }>
  vat_account_names?: Partial<Record<'input' | 'output' | 'settlement' | 'unresolved', string>>
}

export interface StereoUpload {
  token: string
  filename: string
  size: number
  received: number
  complete: boolean
  created_at: number
}

export type StereoPartnerCountries = Record<string, string>

export interface StereoCountryCandidate {
  source_key: string
  name: string
  ico: string
  dic: string
  source_country: string
  country_code: string
  confirmed_country: string | null
  documents: Array<{ source_key: string; document_no: string; kind: 'issued' | 'purchase' }>
}

export interface StereoReport {
  ok: boolean
  reconciliation?: MoneyS3ProtocolData['reconciliation']
  partial?: boolean
  not_transferred?: Array<{ table: string; count: number }>
  preflight?: Array<{ level: string; code: string; message: string }>
  errors?: Array<string | { level: string; code: string; message: string }>
  warnings?: Array<string | { level: string; code: string; message: string; document_no?: string }>
  counts?: Record<string, number>
  written?: Record<string, number>
  review_reasons?: Record<string, number>
  movement_review_reasons?: Record<string, number>
  date_bounds?: { from: string; to: string }
  partner_country_candidates?: StereoCountryCandidate[]
  review_movements?: Array<{
    kind: 'bank' | 'cash'
    source_key: string
    document_no?: string
    review_codes: string[]
    target_id: number | null
    statement_id?: number | null
  }>
  review_documents?: Array<{
    kind: 'issued' | 'purchase'
    source_key: string
    document_no: string
    review_codes: string[]
    target_id: number | null
  }>
  [key: string]: unknown
}

/** Klientský pohled na synchronní výsledek; nemá serverové ID běhu ani čas vytvoření. */
export interface StereoProtocolRun {
  id: null
  mode: 'dry_run' | 'import'
  status: MoneyS3RunStatus
  agenda_ico: string | null
  agenda_name: string | null
  agenda_year: null
  created_at: null
  protocol: MoneyS3ProtocolData
}

export const stereoNxApi = {
  downloadProtocol: (token: string, jobId: number, language: 'cs' | 'en' = 'cs') =>
    downloadApiFile(`${BASE}/uploads/${token}/runs/${jobId}/pdf?lang=${language}`, `stereo-nx-protocol-${jobId}.pdf`),
  uploads: async (): Promise<StereoUpload[]> =>
    (await api.get<{ uploads: StereoUpload[] }>(`${BASE}/uploads`)).data.uploads,
  upload: (file: File, onProgress?: ChunkedUploadProgress, onStarted?: (token: string) => void) =>
    uploadChunked(BASE, file, onProgress, onStarted),
  preview: async (token: string): Promise<StereoPreview> =>
    (await api.post<StereoPreview>(`${BASE}/uploads/${token}/preview`, {})).data,
  fillCompanyProfile: async (token: string, company: number, fields: string[], expectedValues: Record<string, string>): Promise<{ filled_fields: string[] }> =>
    (await api.post<{ filled_fields: string[] }>(`${BASE}/uploads/${token}/company-profile`, { company, fields, expected_values: expectedValues })).data,
  accountMapping: async (token: string, company: number, matchBankAccountSeries = false): Promise<StereoAccountMappingProposal> =>
    (await api.post<StereoAccountMappingProposal>(`${BASE}/uploads/${token}/account-mapping`, { company, match_bank_account_series: matchBankAccountSeries })).data,
  partnerCountries: async (token: string, company: number, blankCountryIsCz = false, matchBankAccountSeries = false, countries: StereoPartnerCountries = {}): Promise<StereoCountryCandidate[]> =>
    (await api.post<{ partners: StereoCountryCandidate[] }>(`${BASE}/uploads/${token}/partner-countries`, { company, blank_country_is_cz: blankCountryIsCz, match_bank_account_series: matchBankAccountSeries, partner_countries: countries })).data.partners,
  /**
   * Zkouška nanečisto i převod běží na serveru jako job: po založení se polluje jeho stav
   * (společné `/admin/imports/{id}`, `onJob` dostává každý stav) a po doběhnutí se stáhne
   * výsledek. Job, který skončil bez výsledku, vrátí chybu s textem jobu.
   */
  run: async (token: string, company: number, mode: 'dry_run' | 'import', blankCountryIsCz: boolean, onJob?: (job: FileImportJob) => void, matchBankAccountSeries = false, accountMapping?: StereoAccountMapping, partnerCountries?: StereoPartnerCountries): Promise<StereoReport> =>
    stereoNxApi.follow(token, await stereoNxApi.start(token, company, mode, blankCountryIsCz, matchBankAccountSeries, accountMapping, partnerCountries), onJob),
  start: async (token: string, company: number, mode: 'dry_run' | 'import', blankCountryIsCz: boolean, matchBankAccountSeries = false, accountMapping?: StereoAccountMapping, partnerCountries?: StereoPartnerCountries): Promise<number> =>
    (await api.post<{ job_id: number }>(`${BASE}/uploads/${token}/run`, { company, mode, blank_country_is_cz: blankCountryIsCz, match_bank_account_series: matchBankAccountSeries, ...(accountMapping ? { account_mapping: accountMapping } : {}), ...(partnerCountries && Object.keys(partnerCountries).length ? { partner_countries: partnerCountries } : {}) })).data.job_id,
  /** Sleduje už založený job do konce a vrátí jeho výsledek (i job spuštěný před opuštěním stránky). */
  follow: async (token: string, jobId: number, onJob?: (job: FileImportJob) => void): Promise<StereoReport> => {
    for (;;) {
      const job = await fetchImportJob(jobId)
      onJob?.(job)
      if (job.status !== 'queued' && job.status !== 'running') break
      await new Promise<void>(resolve => { setTimeout(resolve, POLL_MS) })
    }
    return (await api.get<{ report: StereoReport }>(`${BASE}/uploads/${token}/runs/${jobId}`)).data.report
  },
  remove: async (token: string): Promise<void> => { await api.delete(`${BASE}/uploads/${token}`) },
}
