import { api } from './client'

/**
 * Interní doklad zúčtování DPH převádí daň období z účtů vstupu a výstupu
 * na zúčtovací účet 343.900.
 *
 * Primární spouštěč je PODÁNÍ přiznání (backend, VatClearingTrigger); tenhle klient
 * obsluhuje RUČNÍ cestu z agendy DPH — náhled a spuštění přepočtu.
 */

/** Aktuálnost zúčtování — počítá se živě na backendu, neukládá se. */
export type VatClearingFreshness = 'ok' | 'missing' | 'stale' | 'not_applicable'

/** Proč se do období nesmí zapisovat (null = smí). */
export type VatClearingBlocker = 'period_not_open' | 'date_locked' | 'no_accounting_period' | null

export type VatClearingTrigger = 'return_filed' | 'return_draft' | 'manual' | 'cron'

export interface VatClearingRun {
  trigger_source: VatClearingTrigger
  submission_id: number | null
  submission_form: string | null
  submission_variant: string | null
  submitted_at: string | null
  computed_at: string
  entry_id: number | null
  input_vat: number
  output_vat: number
  settlement: number
}

export interface VatClearingStatus {
  supplier_id: number
  period_type: 'monthly' | 'quarterly'
  period_start: string
  period_end: string
  period_label: string
  source_id: number
  /** Co by se zaúčtovalo dnes. */
  input_vat: number
  output_vat: number
  settlement: number
  accounts: { input: string; output: string; settlement: string }
  accounts_detail: { account_code: string; direction: 'input' | 'output'; amount: number }[]
  status: string | null
  entry_id: number | null
  /** Co je na dokladu zaúčtované teď (null = doklad neexistuje). */
  posted: { input_vat: number; output_vat: number; settlement: number } | null
  freshness: VatClearingFreshness
  writable: boolean
  writable_reason: VatClearingBlocker
  run: VatClearingRun | null
}

export const vatClearingApi = {
  /** Náhled — nic nezapisuje. Kvartální plátce může poslat kterýkoli měsíc kvartálu. */
  status: (year: number, month: number) =>
    api.get<VatClearingStatus>('/accounting/vat-clearing', { params: { year, month } }).then(r => r.data),

  /** Zaúčtuje/přepočítá doklad. Zavřené ani zamčené období neobchází — vrátí chybu. */
  run: (year: number, month: number) =>
    api.post<VatClearingStatus>('/accounting/vat-clearing', { year, month }).then(r => r.data),
}
