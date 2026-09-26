import { api } from './client'
import type { PayrollEmployerAccounts, PayrollEmployerSettings } from './payroll'

// PAM-16 - návrh mzdových předkontací odvozený ze zaúčtování převzatého
// z původního mzdového programu.
//
// ⚠ Nejsou to účetní zápisy. Mzdy zaúčtuje MyÚčto vlastní cestou podle svého
// nastavení; z převzatých dat se bere jen podklad pro to nastavení.

/** Zdroj převzatého zaúčtování; musí sedět na ENUM v migraci 1852. */
export type PayrollPostingMapSource = 'pamica' | 'pohoda' | 'money_s3' | 'other' | 'stereo_nx'

/**
 * Stav jednoho významu.
 *
 * `conflict` NENÍ „skoro hotovo": na jeden význam vyšly dva účty a vybrat mezi
 * nimi umí jen účetní. `outside_chart` je jednoznačný účet, který ale firma
 * v osnově nemá - nabídnout ho jako hotovou volbu by nešlo uložit.
 */
export type PayrollPostingMapStatus = 'unambiguous' | 'conflict' | 'outside_chart' | 'missing'

export type PayrollPostingMapKey = keyof PayrollEmployerAccounts

export interface PayrollPostingMapCandidate {
  /** Účet ve tvaru osnovy MyÚčta (`336.001`). */
  code: string
  /** Týž účet tak, jak ho psal původní program (`336001`). */
  source_codes: string[]
  line_count: number
  amount_minor: number
  concepts: string[]
  labels: string[]
  cost_centers: string[]
  in_chart: boolean
  /** Syntetika účtu, když se od něj liší; jen informace, ne návrh. */
  synthetic_code: string | null
  synthetic_in_chart: boolean
}

export interface PayrollPostingMapEntry {
  key: PayrollPostingMapKey
  status: PayrollPostingMapStatus
  /** Význam, který žádný zdroj netrefuje; zůstává na výchozí hodnotě vždy. */
  derivable: boolean
  default_code: string
  current_code: string
  /** Vyplněno JEN u `unambiguous`; rozpor se za účetní nerozhoduje. */
  suggested_code: string | null
  candidates: PayrollPostingMapCandidate[]
}

export interface PayrollPostingMapUnmapped {
  label: string
  reference: string
  debit_code: string | null
  credit_code: string | null
  debit_source_code: string | null
  credit_source_code: string | null
  line_count: number
  amount_minor: number
}

export interface PayrollPostingMapProposal {
  id: number
  source: PayrollPostingMapSource
  status: 'draft' | 'confirmed'
  source_year: number | null
  source_reference: string | null
  proposal: {
    source: string
    keys: PayrollPostingMapEntry[]
    unmapped: PayrollPostingMapUnmapped[]
    summary: {
      row_count: number
      line_count: number
      unambiguous: number
      conflict: number
      outside_chart: number
      missing: number
    }
  }
  confirmed_accounts: Record<string, string> | null
  confirmed_at: string | null
  updated_at: string
}

export interface PayrollPostingMapResponse {
  proposal: PayrollPostingMapProposal | null
  sources: PayrollPostingMapSource[]
}

export const payrollPostingMapApi = {
  show: (source?: PayrollPostingMapSource | null) =>
    api.get<PayrollPostingMapResponse>(
      `/payroll/migration/posting-map${source ? `?source=${encodeURIComponent(source)}` : ''}`,
    ).then(response => response.data),
  /**
   * Promítne do nastavení zaměstnavatele JEN potvrzené předkontace. Server
   * zapisuje toutéž cestou jako obrazovka Nastavení mezd, takže platí stejné
   * kontroly osnovy i stejné hlídání `row_version`.
   */
  confirm: (payload: {
    source: PayrollPostingMapSource
    row_version: number
    confirmations: Record<string, string>
  }) =>
    api.post<{ settings: PayrollEmployerSettings; proposal: PayrollPostingMapProposal }>(
      '/payroll/migration/posting-map/confirm',
      payload,
    ).then(response => response.data),
}
