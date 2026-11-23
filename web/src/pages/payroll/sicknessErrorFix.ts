import type { RouteLocationRaw } from 'vue-router'

/**
 * Kam s chybou případu dávky (NEMPRI, HZUPN): na kartu osoby k výplatnímu
 * účtu nebo identitě, k podmínkám vztahu, do mzdových běhů za chybějící měsíc,
 * do kontroly převodu mezd, do nastavení zaměstnavatele, nebo do editoru
 * případu. Blokátor bez místa, kde se opraví, by účetní nechal hádat.
 *
 * Server posílá v `error.problems` každou chybu zvlášť (náhled je hlásí
 * najednou); starší odpověď nese jen `error.code`.
 */

export interface SicknessErrorProblem {
  code: string
  payroll_periods?: string[]
  takeover_periods?: string[]
}

export type SicknessErrorFixKind =
  | 'person'
  | 'employment_terms'
  | 'runs'
  | 'reconciliation'
  | 'settings'
  | 'edit'

export interface SicknessErrorFixLink {
  kind: Exclude<SicknessErrorFixKind, 'edit'>
  to: RouteLocationRaw
}

export interface SicknessErrorFix {
  links: SicknessErrorFixLink[]
  /** Nabídnout i editor případu (údaje případu, ruční měsíce rozhodného období). */
  edit: boolean
}

interface CaseRef {
  employee_id: number
  employment_id: number
}

/** Sekce karty osoby, kde se chyba opraví (`?panel=` v Lidé). */
const PERSON_PANELS: Record<string, string> = {
  nempri_payment_connection_missing: 'accounts',
  nempri_payment_connection_required: 'accounts',
  nempri_payment_connection_invalid: 'accounts',
  nempri_bank_code_unknown: 'accounts',
  // Způsob výplaty (zápočet přes partnera) je na téže záložce Výplata.
  nempri_payment_connection_partner_settlement: 'accounts',
  nempri_payment_address_invalid: 'addresses',
  nempri_birth_number_missing: 'identifiers',
  sickness_identity_incomplete: 'registration_identity',
  nempri_cared_person_not_found: 'dependants',
}

/** Údaje pracovního vztahu: panel a pole podmínek vztahu. */
const EMPLOYMENT_TERMS_FIELDS: Record<string, string> = {
  nempri_activity_code_missing: 'activity_code',
}

/** Identifikátory zaměstnavatele pro ČSSZ žijí v Nastavení mezd. */
const SETTINGS_TARGETS: Record<string, RouteLocationRaw> = {
  sickness_variable_symbol_missing: { name: 'payroll-settings', query: { tab: 'employer' }, hash: '#payroll-employer-offices' },
  sickness_ossz_code_missing: { name: 'payroll-settings', query: { tab: 'employer' } },
}

const PERIOD = /^\d{4}-(0[1-9]|1[0-2])$/

function periods(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((period): period is string => typeof period === 'string' && PERIOD.test(period)) : []
}

/** Chyby ze serveru: `error.problems`, jinak jediný `error.code`. */
export function sicknessErrorProblems(err: any): SicknessErrorProblem[] {
  const error = err?.response?.data?.error
  const listed = Array.isArray(error?.problems) ? error.problems : []
  const problems = listed
    .filter((problem: any) => typeof problem?.code === 'string' && problem.code !== '')
    .map((problem: any): SicknessErrorProblem => ({
      code: problem.code,
      payroll_periods: periods(problem.payroll_periods),
      takeover_periods: periods(problem.takeover_periods),
    }))
  if (problems.length > 0) return problems

  return typeof error?.code === 'string' && error.code !== ''
    ? [{ code: error.code, payroll_periods: periods(error.payroll_periods), takeover_periods: periods(error.takeover_periods) }]
    : []
}

export function sicknessErrorFix(problems: SicknessErrorProblem[], item: CaseRef): SicknessErrorFix | null {
  const links: SicknessErrorFixLink[] = []
  const seen = new Set<string>()
  const add = (kind: SicknessErrorFixLink['kind'], to: RouteLocationRaw) => {
    const key = kind + JSON.stringify(to)
    if (seen.has(key)) return
    seen.add(key)
    links.push({ kind, to })
  }
  let edit = false
  for (const problem of problems) {
    const code = problem.code
    const panel = PERSON_PANELS[code]
    if (panel !== undefined) {
      add('person', { name: 'payroll-people', query: { person: String(item.employee_id), panel } })
      if (code === 'nempri_cared_person_not_found') edit = true
      continue
    }
    const field = EMPLOYMENT_TERMS_FIELDS[code]
    if (field !== undefined) {
      add('employment_terms', {
        name: 'payroll-people',
        query: { person: String(item.employee_id), employment: String(item.employment_id), panel: 'employment_terms', field },
      })
      continue
    }
    const settings = SETTINGS_TARGETS[code]
    if (settings !== undefined) {
      add('settings', settings)
      continue
    }
    if (code === 'nempri_decisive_month_missing') {
      // Měsíc, který počítá MyÚčto, opraví schválený mzdový běh za to
      // období; převzatý měsíc Kontrola převodu mezd. Odpověď bez rozpadu
      // (starší server) vede jako dřív do kontroly převodu.
      const payroll = problem.payroll_periods ?? []
      const takeover = problem.takeover_periods ?? []
      if (payroll.length > 0) add('runs', { name: 'payroll-runs', query: { period: payroll[0] } })
      if (takeover.length > 0 || payroll.length === 0) add('reconciliation', { path: '/payroll/imports', query: { tab: 'reconciliation' } })
      // Měsíce jde zadat i ručně u případu.
      edit = true
      continue
    }
    if (code.startsWith('nempri_') || code.startsWith('hzupn_') || code.startsWith('dlo_')
      || code.startsWith('sickness_event_') || code === 'sickness_protection_period_excluded'
    ) {
      // Událost po skončení vztahu: opravuje se den vzniku u případu, nebo
      // konec vztahu na kartě osoby — editor případu nabídne první z obou.
      edit = true
    }
  }

  return links.length > 0 || edit ? { links, edit } : null
}
