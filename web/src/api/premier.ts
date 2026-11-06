import { createMigrationApi, type MigrationNonprofitNotice } from './migrationApi'
import type { AcceptedDifference, MoneyS3RunStatus, MoneyS3Step } from './moneyS3'

/**
 * Průvodce „Přechod z PREMIER": záloha dat (iZIP/iCAB) vytvořená přímo v programu
 * (Správce → Záloha dat, F11), náhled agend a výběr roků, zkouška nanečisto a ostrý
 * převod na pozadí. Záloha obsahuje všechny roky; vybrané se převádějí v jednom jobu
 * vzestupně, protože počáteční stavy roku vycházejí z let předchozích. Stav
 * běžícího převodu se čte přes společné `/admin/imports/{id}` (fetchImportJob
 * v api/imports.ts). Na rozdíl od POHODY tu není exportní nástroj (záloha se dělá
 * přímo v PREMIERu) ani druh převodu (mzdy PREMIER nevede v téže záloze).
 */

export interface PremierAgenda {
  dir: string
  ico: string
  dic: string
  company: string
  /** Účetní rok firmy (label = rok začátku období). */
  year: number
  /** Hranice období u firmy s hospodářským rokem; kalendářní firma je nedostává. */
  period_start?: string
  period_end?: string
  /** Počet zápisů deníku agendy roku — PREMIER na rozdíl od POHODY nevrací rozpad po dokladech. */
  entries: number
  has_accounting: boolean
  has_payroll: boolean
}

export interface PremierMessage {
  level: 'error' | 'warning' | 'info'
  code: string
  message: string
  context?: Record<string, unknown>
}

export interface PremierUpload {
  nonprofit_notice?: MigrationNonprofitNotice
  token: string
  status: 'ready'
  file_name: string
  sha256: string
  uploaded_at: string
  uploaded_by: number
  supplier_ico: string
  default_year: number | null
  agendas: PremierAgenda[]
  /** Kontrola před převodem po letech (klíč = rok), jen pro agendy s IČO firmy. */
  preflight: Record<string, PremierMessage[]>
}

/** Záloha se ještě nahrává po částech nebo ji server na pozadí rozbaluje a čte. */
export interface PremierUploadPending {
  token: string
  status: 'uploading' | 'processing' | 'failed'
  file_name: string
  size: number
  received: number
  job_id: number | null
  error: string | null
}

export function isPremierUploadReady(upload: PremierUpload | PremierUploadPending): upload is PremierUpload {
  return 'agendas' in upload
}

export interface PremierReconciliationYear {
  year: number
  period_id: number
  ok: boolean
  checks: { key: string; ok: boolean }[]
  journal_diffs: import('./moneyS3').MoneyS3Diff[]
  documents: { key: string; documents: number; journal: number; ok: boolean; other_accounts?: unknown }[]
  unmapped_accounts?: unknown
}

export interface PremierProtocolData {
  mode: 'dry_run' | 'import'
  status: MoneyS3RunStatus
  failure: string | null
  error?: string
  steps: MoneyS3Step[]
  /** Převod selhal JEN na rozdílech k přijetí. */
  acceptable_only?: boolean
  accepted_differences?: AcceptedDifference[]
  agenda?: { ico: string; dic: string; company: string; year: number; dir: string }
  /** Job víc roků: roky jobu vzestupně a pořadí tohoto roku. */
  job_years?: { years: number[]; index: number; dry_run_isolated: boolean }
  preflight?: PremierMessage[]
  reconciliation?: PremierReconciliationYear[]
  orphans?: { type: 'purchase_invoice' | 'invoice' | 'cash' | 'bank'; document_no: string; id: number }[]
  automation?: { before?: string | null; during: string; restored: boolean; after: string | null }
}

export interface PremierRun {
  id: number
  job_id: number | null
  mode: 'dry_run' | 'import'
  status: MoneyS3RunStatus
  agenda_ico: string | null
  agenda_year: number | null
  program_version: string | null
  created_at: string
  finished_at: string | null
  protocol?: PremierProtocolData | null
}

export interface PremierStartParams {
  mode: 'dry_run' | 'import'
  /** Vybrané roky; převádějí se vzestupně, každý s vlastním během a protokolem. */
  years: number[]
  /** Ostrý převod přijme rozdíly, na kterých selhala zkouška nanečisto. */
  accept_differences?: boolean
}

export const PREMIER_BASE = '/admin/imports/premier'

export const premierApi = {
  ...createMigrationApi<PremierUpload, PremierUploadPending, PremierRun, PremierStartParams>(PREMIER_BASE),
}
