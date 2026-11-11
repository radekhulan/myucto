import type { LocationQuery } from 'vue-router'
import type {
  PayrollComponent,
  PayrollComponentJmhzMappingState,
  PayrollPeopleFilter,
} from '@/api/payroll'
import { payrollQueryValue } from './payrollAgendaLinks'

/**
 * Parametry, se kterými na stránku míří odkaz z nálezu (kontrola před během,
 * blokace hlášení). Odkaz, jehož parametr stránka nečte, otevře nefiltrovaný
 * seznam a účetní hledá, co měla vidět rovnou. Že stránky parametry z odkazů
 * serveru opravdu čtou, hlídá `PayrollE2eHppRemediationLinkCoverageTest`.
 */

export const PAYROLL_PEOPLE_FILTERS: readonly PayrollPeopleFilter[] = [
  'all',
  'active',
  'needs_setup',
  'needs_data',
  'blocking_data',
  'dpp_hours_limit',
]

/** `?filter=` seznamu zaměstnanců (nález „chybí zákonné údaje" vede na `blocking_data`). */
export function payrollPeopleFilterFromQuery(
  query: LocationQuery,
  fallback: PayrollPeopleFilter = 'active',
): PayrollPeopleFilter {
  const value = payrollQueryValue(query, 'filter')
  return value !== null && (PAYROLL_PEOPLE_FILTERS as readonly string[]).includes(value)
    ? value as PayrollPeopleFilter
    : fallback
}

export type PayrollComponentJmhzFilter = 'missing' | 'manual_review' | 'invalid'

const COMPONENT_JMHZ_FILTERS: readonly PayrollComponentJmhzFilter[] = ['missing', 'manual_review', 'invalid']

/** `?jmhz=` číselníku složek: souhrnný nález zařazení vede na složky s tímtéž problémem. */
export function componentJmhzFilterFromQuery(query: LocationQuery): PayrollComponentJmhzFilter | null {
  const value = payrollQueryValue(query, 'jmhz')
  return value !== null && (COMPONENT_JMHZ_FILTERS as readonly string[]).includes(value)
    ? value as PayrollComponentJmhzFilter
    : null
}

/**
 * Patří složka do filtru? Stejné rozdělení jako `JmhzComponentSourceRule::issueCode()`
 * na serveru: zahrnutá bez zařazení chybí, `manual_review` čeká na kontrolu
 * a jiné zacházení než zahrnuto / vyřazeno / ke kontrole je neplatné.
 */
export function componentMatchesJmhzFilter(
  filter: PayrollComponentJmhzFilter,
  component: Pick<PayrollComponent, 'jmhz_treatment'>,
  state: Pick<PayrollComponentJmhzMappingState, 'status'>,
): boolean {
  switch (filter) {
    case 'missing': return state.status === 'missing'
    case 'manual_review': return component.jmhz_treatment === 'manual_review'
    case 'invalid': return !['included', 'excluded', 'manual_review'].includes(String(component.jmhz_treatment))
  }
}

/**
 * Odkaz z nálezu mzdového běhu nebo kontroly před během na agendu měsíce.
 *
 * Docházka, mzdové vstupy, běhy, insolvence, rizikové spoření i podání se bez
 * `period` otevřou na zpracovávaném měsíci, ne na měsíci běhu. Server cestu
 * skládá bez období tam, kde ho nezná (uložená kontrola výpočtu), proto ho
 * doplní karta běhu i kontrola před během.
 */
export function runRemediationHref(remediationPath: string, runPeriod: string | null | undefined): string {
  const period = (runPeriod ?? '').slice(0, 7)
  if (!/^\d{4}-(0[1-9]|1[0-2])$/.test(period)) return remediationPath
  const [base, hash = ''] = remediationPath.split('#', 2) as [string, string?]
  if ((/^\/payroll\/(runs|time|quick-inputs|insolvency|submissions)(?:\/[a-z_]+)?(?:\?|$)/.test(base)
    || base.startsWith('/payroll/components?tab=risky_savings')) && !/[?&]period=/.test(base)) {
    return `${base}${base.includes('?') ? '&' : '?'}period=${encodeURIComponent(period)}${hash ? `#${hash}` : ''}`
  }
  return remediationPath
}
