import { api } from './client'
import { createMigrationApi, type MigrationNonprofitNotice } from './migrationApi'
import { downloadApiFile } from '@/utils/downloadFile'
import type { AcceptedDifference, MoneyS3Diff, MoneyS3RunStatus, MoneyS3Step, SourceDifference } from './moneyS3'

/**
 * Průvodce „Přechod z POHODA": XML export agendy vytvořený exportním nástrojem,
 * náhled agend a výběr roků, zkouška nanečisto a ostrý převod na pozadí. Stav běžícího
 * převodu se čte přes společné `/admin/imports/{id}` (fetchImportJob v api/imports.ts).
 */

export interface PohodaAgendaCounts {
  journal: number
  opening: number
  first_date: string | null
  last_date: string | null
  /** Roky po roce agendy, do kterých padají zápisy deníku (chybí u přehledu nahraného dřív). */
  later_years?: number[]
  issued: number
  purchase: number
  internal: number
  cash: number
  bank: number
  partners: number
}

export interface PohodaAgendaFile {
  file: string
  state: string
  note: string | null
}

/** Mzdy z datového souboru POHODA Mzdy / PAMICA (`91_mzdy.xml`) za rok agendy. */
export interface PohodaPayrollSummary {
  employees: number
  months: number
  payslips: number
  first: string | null
  last: string | null
}

/** Jak převést karty skladu POHODY bez druhu zásoby; `skip` = sklad se nepřevádí. */
export type PohodaStockChoice = 'goods' | 'material' | 'skip'

/** Sklad z datového souboru POHODY (`92_sklad.xml`): sklady s počty karet a navrženou volbou. */
export interface PohodaStockSummary {
  warehouses: { code: string; name: string; cards: number; stocked: number; without_kind: number; suggested: PohodaStockChoice }[]
  cards: number
  price_lists: number
  /** Den, ke kterému se stav převede. */
  date: string | null
}

/** Účetní období firmy (label = rok začátku období) s hranicemi. */
export interface PohodaCompanyPeriod {
  year: number
  period_start: string
  period_end: string
}

/**
 * Účetní rok firmy s hospodářským rokem, do kterého agenda účetnictví patří, a pozdější
 * období, do kterých vede doklady. Kalendářní firma ho nedostává (průvodce bere rok agendy).
 */
export interface PohodaCompanyYear extends PohodaCompanyPeriod {
  later_years: number[]
  later_periods: PohodaCompanyPeriod[]
}

export interface PohodaAgenda {
  ico: string
  year: number
  company_year?: PohodaCompanyYear
  company: string
  program: string
  exported_at: string | null
  counts: PohodaAgendaCounts
  files: PohodaAgendaFile[]
  /** Chybí u přehledu nahraného před podporou mezd - pak jde vždy o účetnictví. */
  has_accounting?: boolean
  has_payroll?: boolean
  payroll?: PohodaPayrollSummary | null
  /** Chybí u přehledu nahraného před převodem skladu, `null` = export sklad nemá. */
  stock?: PohodaStockSummary | null
}

/** Co se převádí: účetní rok, nebo mzdy (vlastní akce, i pro export jen se mzdami). */
export type PohodaKind = 'accounting' | 'payroll'
/** Průvodce převodem: účetní agenda z POHODY, nebo mzdy z mzdového programu PAMICA. */
export type PohodaSystem = 'pohoda' | 'pamica'

export interface PohodaMessage {
  level: 'error' | 'warning' | 'info'
  code: string
  message: string
  context?: Record<string, unknown>
}

export interface PohodaUpload {
  nonprofit_notice?: MigrationNonprofitNotice
  token: string
  status: 'ready'
  file_name: string
  sha256: string
  uploaded_at: string
  uploaded_by: number
  supplier_ico: string
  default_year: number | null
  agendas: PohodaAgenda[]
  /** Kontrola před převodem po letech (klíč = rok), jen pro agendy s IČO firmy. */
  preflight: Record<string, PohodaMessage[]>
  /** Kontrola před převodem mezd po letech (klíč = rok), jen agendy se mzdami. */
  payroll_preflight?: Record<string, PohodaMessage[]>
}

/** Export se ještě nahrává po částech nebo ho server na pozadí rozbaluje a čte. */
export interface PohodaUploadPending {
  token: string
  status: 'uploading' | 'processing' | 'failed'
  file_name: string
  size: number
  received: number
  job_id: number | null
  error: string | null
  /** Průběh jobu, který export zpracovává nebo dopočítává náhled. */
  progress?: { step: string; processed: number; total: number } | null
  /** Export na serveru zůstal a přípravu náhledu jde zopakovat. */
  retryable?: boolean
}

export function isPohodaUploadReady(upload: PohodaUpload | PohodaUploadPending): upload is PohodaUpload {
  return 'agendas' in upload
}

export interface PohodaReconciliationYear {
  year: number
  period_id: number
  ok: boolean
  checks: { key: string; ok: boolean }[]
  /** `money` = hodnoty z deníku POHODY (backend sdílí tvar s Money S3). */
  journal_diffs: MoneyS3Diff[]
  documents: { key: string; documents: number; journal: number; ok: boolean; other_accounts?: unknown; source_differences?: SourceDifference[] }[]
  unmapped_accounts?: unknown
  /** Saldokonto proti hlavní knize po obdobích převodu; rozdíl je jen upozornění, `ok` nemění. */
  saldo?: {
    year: number
    period_id: number
    as_of: string
    status: 'ok' | 'differences' | 'unavailable'
    differences: { account: string; name: string; gl_balance: number; open_items_total: number; difference: number }[]
    error?: string
  }[]
}

export interface PohodaProtocolData {
  mode: 'dry_run' | 'import'
  kind?: PohodaKind
  status: MoneyS3RunStatus
  failure: string | null
  error?: string
  steps: MoneyS3Step[]
  /** Převod selhal JEN na rozdílech k přijetí. */
  acceptable_only?: boolean
  accepted_differences?: AcceptedDifference[]
  agenda?: { ico: string; year: number; program: string; exported_at: string | null; dir: string; skipped_years?: number[] }
  /** Job víc roků: roky jobu vzestupně a pořadí tohoto roku. */
  job_years?: { years: number[]; index: number; dry_run_isolated: boolean }
  preflight?: PohodaMessage[]
  reconciliation?: PohodaReconciliationYear[]
  orphans?: { type: 'purchase_invoice' | 'invoice' | 'cash' | 'bank'; document_no: string; id: number }[]
  automation?: { before?: string | null; during: string; restored: boolean; after: string | null }
}

export interface PohodaRun {
  id: number
  job_id: number | null
  mode: 'dry_run' | 'import'
  status: MoneyS3RunStatus
  agenda_ico: string | null
  agenda_year: number | null
  pohoda_version: string | null
  created_at: string
  finished_at: string | null
  /** Druh převodu z protokolu; běh bez protokolu (ještě běží) je `accounting`. */
  kind?: PohodaKind
  protocol?: PohodaProtocolData | null
}

export interface PohodaStartParams {
  mode: 'dry_run' | 'import'
  /** Vybrané roky; převádějí se vzestupně, každý s vlastním během a protokolem. */
  years: number[]
  kind?: PohodaKind
  /** Mzdy: OIČ a ID PPV z PAMICA pocházejí z protokolů ČSSZ, převod je smí uložit. */
  confirm_identifiers?: boolean
  approve_taken_over?: boolean
  /**
   * Mzdy: začátek vedení mezd leží před měsíci zpracovanými PAMICA (kontrola `payroll_start_behind_takeover`).
   * `advance` = posunout ho za poslední zpracovaný měsíc a převést, `keep` = vědomě ponechat.
   * Ostrý převod bez rozhodnutí backend odmítne.
   */
  start_decision?: 'advance' | 'keep'
  /** Mzdy: převáděné měsíce `YYYY-MM` (obě meze včetně); bez nich všechny uzavřené měsíce exportu. */
  period_from?: string
  period_to?: string
  /** Ostrý převod přijme rozdíly, na kterých selhala zkouška nanečisto. */
  accept_differences?: boolean
  /** Převod skladu z nejnovější agendy: volba po skladech (kód skladu => volba). Bez ní se sklad nepřevádí. */
  stock?: { warehouses: Record<string, PohodaStockChoice> }
}

export interface PohodaToolFile {
  name: string
  size: number
}

export const POHODA_BASE = '/admin/imports/pohoda'

export const pohodaApi = {
  // Náhled exportu server jen čte z meta.json (přehled počítá job na pozadí), odpověď je hned.
  ...createMigrationApi<PohodaUpload, PohodaUploadPending, PohodaRun, PohodaStartParams>(POHODA_BASE, { showTimeout: 60_000 }),
  // Nástroj se liší podle programu: POHODA exportuje účetní agendu přes XML rozhraní,
  // PAMICA se čte přímo z mzdového datového souboru.
  toolFiles: (system: PohodaSystem = 'pohoda'): Promise<{ files: PohodaToolFile[] }> =>
    api.get<{ files: PohodaToolFile[] }>(`${POHODA_BASE}/tool?variant=${system}`).then(r => r.data),
  downloadTool: (system: PohodaSystem = 'pohoda'): Promise<unknown> =>
    downloadApiFile(`${POHODA_BASE}/tool/download?variant=${system}`, `${system}-export.zip`),
  downloadToolFile: (name: string, system: PohodaSystem = 'pohoda'): Promise<unknown> =>
    // Jméno v query: IIS i nginx blokují přípony .cmd/.ps1 v cestě URL.
    downloadApiFile(`${POHODA_BASE}/tool/download?variant=${system}&name=${encodeURIComponent(name)}`, name),
}
