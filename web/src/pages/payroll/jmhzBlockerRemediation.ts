import type { RouteLocationRaw } from 'vue-router'
import { apiErrorCode, apiErrorMessage } from '@/api/errors'
import type {
  PayrollJmhzProtocolRemediation,
  PayrollJmhzRemediationKind,
  PayrollJmhzXmlDryRunBlocker,
} from '@/api/payroll'
import { averageEarningsTarget } from './payrollRemediation'
import { payrollCodeKey } from './payrollServerMessage'

/**
 * Kam se nález měsíčního hlášení opravuje.
 *
 * Druh nápravy posílá server (`JmhzBlockerCatalog`) u každého nálezu, takže
 * UI už nemusí znát každý kód zvlášť. Dřív kód, který v překladech nebyl,
 * skončil „neznámou blokací" s odkazem na podporu, přestože server věděl,
 * kde se údaj doplňuje.
 */
export interface JmhzRemediationContext {
  /** `YYYY-MM` vykazovaného období, pokud je znám. */
  period?: string | null
  /** Vztah → osoba, aby šlo z nálezu na vztahu otevřít kartu osoby a naopak. */
  employments?: { id: number, employee_id: number }[]
}

const EMPLOYMENT_KINDS: PayrollJmhzRemediationKind[] = [
  'employment_terms',
  'employment_profile',
  'employment_identity',
  'registration',
  'absences',
  'averages',
  'time',
  'workplace',
  'deferred_income',
]

export function jmhzRemediationKind(blocker: PayrollJmhzXmlDryRunBlocker): PayrollJmhzRemediationKind {
  if (blocker.remediation?.kind) return blocker.remediation.kind
  switch (blocker.entity_type) {
    case 'employment': return 'employment_terms'
    case 'person':
    case 'employee': return 'employee_identity'
    case 'component': return 'components'
    case 'office': return 'office'
    case 'run':
    case 'revision': return 'runs'
    default: return 'retry'
  }
}

/**
 * Cíl prokliku. `null` = na nálezu není kam jít (znovu spustit test nebo
 * případ zpracovat ručně); UI pak ukáže jen krok.
 */
export function jmhzRemediationTarget(
  blocker: PayrollJmhzXmlDryRunBlocker,
  entityId: number | null,
  context: JmhzRemediationContext = {},
): RouteLocationRaw | null {
  const kind = jmhzRemediationKind(blocker)
  const field = blocker.remediation?.field ?? null
  const period = context.period ?? null
  const periodQuery = period ? { period } : {}
  const employments = context.employments ?? []
  let employmentId: number | null = null
  let employeeId: number | null = null
  if (entityId !== null) {
    if (blocker.entity_type === 'employment') {
      employmentId = entityId
      employeeId = employments.find(item => item.id === entityId)?.employee_id ?? null
    } else if (blocker.entity_type === 'person' || blocker.entity_type === 'employee') {
      employeeId = entityId
      const own = employments.filter(item => item.employee_id === entityId)
      employmentId = own.length === 1 ? own[0]!.id : null
    }
  }
  const scope = {
    ...(employeeId === null ? {} : { person: String(employeeId) }),
    ...(employmentId === null ? {} : { employment: String(employmentId) }),
  }
  if (EMPLOYMENT_KINDS.includes(kind) && employmentId === null && employeeId === null && entityId !== null) {
    return { name: 'payroll-people' }
  }
  switch (kind) {
    case 'employment_terms':
    case 'workplace':
      return { name: 'payroll-people', query: { ...scope, panel: 'employment_terms', ...(field ? { field } : {}) } }
    case 'employment_profile':
      return { name: 'payroll-people', query: { ...scope, panel: 'jmhz_profile' } }
    case 'employment_identity':
      return {
        name: 'payroll-people',
        query: {
          ...scope,
          panel: 'jmhz_identity',
          field: field ?? (blocker.code === 'jmhz_identity_id_ppv_missing'
            ? 'jmhz.employment_external_identifier'
            : 'jmhz.person_external_identifier'),
        },
      }
    case 'registration':
      return employmentId === null
        ? { name: 'payroll-people' }
        : { name: 'payroll-people', query: { ...scope, panel: 'registration' } }
    case 'employee_identity':
      return employeeId === null
        ? { name: 'payroll-people' }
        : { name: 'payroll-people', query: { person: String(employeeId), panel: 'registration_identity' } }
    case 'statutory_evidence':
      return employeeId === null
        ? { name: 'payroll-people' }
        : { name: 'payroll-people', query: { person: String(employeeId), panel: 'statutory_evidence' } }
    case 'dependants':
      return employeeId === null
        ? { name: 'payroll-people' }
        : { name: 'payroll-people', query: { person: String(employeeId), panel: 'dependants' } }
    case 'absences':
      return employmentId === null
        ? { name: 'payroll-absences', query: { tab: 'absences', ...periodQuery } }
        : { name: 'payroll-absences', query: { employment: String(employmentId), tab: 'absences', ...periodQuery } }
    case 'averages': {
      if (employmentId !== null && period) {
        return averageEarningsTarget(
          employmentId,
          Number(period.slice(0, 4)),
          Math.ceil(Number(period.slice(5, 7)) / 3),
        )
      }
      return employmentId === null
        ? { name: 'payroll-absences', query: { tab: 'averages' } }
        : { name: 'payroll-absences', query: { employment: String(employmentId), tab: 'averages' } }
    }
    case 'time':
      return employmentId === null
        ? { name: 'payroll-time', query: periodQuery }
        : { name: 'payroll-time', query: { employment: String(employmentId), ...periodQuery } }
    case 'runs':
      return { name: 'payroll-runs', query: periodQuery }
    case 'components':
      return { name: 'payroll-components' }
    case 'ordinary_evidence':
      return { name: 'payroll-submissions', query: periodQuery, hash: '#jmhz-ordinary-evidence' }
    case 'employer_annual':
      return { name: 'payroll-settings', query: { tab: 'submissions' }, hash: '#jmhz-employer-annual-evidence' }
    case 'office':
      return { name: 'payroll-settings', query: { tab: 'employer' }, hash: '#payroll-employer-offices' }
    case 'annual_settlement':
      return { name: 'payroll-annual-settlement' }
    case 'deferred_income':
      return { name: 'payroll-people', query: { ...scope, panel: 'deferred_income' } }
    case 'takeover':
      return { name: 'payroll-imports', query: { tab: 'reconciliation' } }
    case 'correction':
    case 'submission':
      return { name: 'payroll-submissions-tab', params: { tab: 'transport' } }
    case 'support':
      return '/admin/support'
    case 'retry':
    case 'manual':
    default:
      return null
  }
}

/**
 * Cíl prokliku u chyby z protokolu ČSSZ. Protokol zná jen IK MPSV a ID PPV,
 * ne naše ID vztahu, takže karta konkrétního zaměstnance se neotevře: vede
 * se na agendu, kde se náprava dělá (identifikátory hledá účetní podle
 * údajů z protokolu, které se u chyby ukazují).
 */
export function jmhzProtocolRemediationTarget(
  remediation: PayrollJmhzProtocolRemediation,
): RouteLocationRaw | null {
  return jmhzRemediationTarget(
    {
      code: remediation.code,
      entity_type: 'protocol',
      entity_id: null,
      attribute_ids: [],
      remediation: { kind: remediation.kind, field: remediation.field },
    },
    null,
  )
}

/**
 * Chyba serveru v jazyce uživatele.
 *
 * Server píše důvody česky (a v češtině jsou podrobnější, jmenují dotčené
 * a krok). V jiném jazyce by účetní dostala českou větu, proto se tam
 * sáhne po překladu kódu chyby, když ho katalog zná.
 */
export function jmhzErrorMessage(
  t: (key: string) => string,
  te: (key: string) => boolean,
  locale: string,
  error: unknown,
  fallbackKey: string,
): string {
  const key = locale !== 'cs' ? payrollCodeKey(te, apiErrorCode(error)) : null
  if (key !== null) return t(key)

  return apiErrorMessage(error, t(fallbackKey))
}

/** Popisek kódu: nejdřív katalog kódů, pak starší blok, pak věta serveru. */
export function jmhzBlockerLabel(
  t: (key: string) => string,
  te: (key: string) => boolean,
  blocker: PayrollJmhzXmlDryRunBlocker,
): string {
  const catalog = `payroll.jmhz_gate.codes.${blocker.code}`
  if (te(catalog)) return t(catalog)
  const legacy = `payroll.submissions.overview.jmhz_dry_run_blockers.${blocker.code}`
  if (te(legacy)) return t(legacy)
  if (blocker.reason) return blocker.reason
  return t('payroll.jmhz_gate.unlabelled')
}
