import type {
  PayrollInputImportIssue,
  PayrollInputImportPreview,
  PayrollInputStatus,
} from '@/api/payroll'
import type { PayrollAbsenceEmployment } from '@/api/payrollAbsences'

export interface PayrollImportFingerprintSource {
  period: string
  format: 'csv' | 'xlsx'
  source_name: string
  content_base64: string
}

export interface PayrollEmploymentOption {
  employee_id: number
  employment_id: number
  full_name: string
  code: string
  relation_type: string
  status: string
}

/**
 * Smí se mzdový vstup ještě opravit?
 *
 * Kdo smí schvalovat, ukládá rovnou schválené vstupy — a musel by si tím první
 * uloženou částkou zabetonovat vlastní řádek, kdyby schválené pole zůstalo
 * zamčené. Dokud vstup nepohltil mzdový běh (`locked`), jde ho opravit; server
 * ho na tu dobu vrátí do konceptu a schválí znovu. Bez práva schvalovat platí
 * původní pravidlo: upravit jde jen koncept.
 *
 * Pravidlo žije tady, protože ho potřebují DVĚ obrazovky — rychlé vstupy (kde
 * se edituje) a karty zaměstnanců na přehledu (kde se jen říká, jestli je
 * částka ještě otevřená). Když si každá držela vlastní kopii, tvrdily o téže
 * částce dvě různé věci.
 */
export function payrollInputEditable(
  status: PayrollInputStatus | null | undefined,
  canApprove: boolean,
): boolean {
  if (status === null || status === undefined || status === 'draft') return true

  return status === 'approved' && canApprove
}

export function localPayrollPeriod(date = new Date()): string {
  return `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}`
}

export function payrollImportFingerprint(source: PayrollImportFingerprintSource): string {
  return JSON.stringify([
    source.period,
    source.format,
    source.source_name,
    source.content_base64,
  ])
}

export function canApplyPayrollImport(
  preview: PayrollInputImportPreview | null,
  previewFingerprint: string | null,
  currentFingerprint: string,
): boolean {
  return preview !== null
    && preview.accepted_count > 0
    && previewFingerprint !== null
    && previewFingerprint === currentFingerprint
}

export function payrollImportIssues(
  preview: PayrollInputImportPreview | null,
): Array<PayrollInputImportIssue & { kind: 'error' | 'duplicate' }> {
  if (preview === null) return []
  return [
    ...preview.errors.map(issue => ({ ...issue, kind: 'error' as const })),
    ...preview.duplicates.map(issue => ({ ...issue, kind: 'duplicate' as const })),
  ].sort((left, right) => left.row_number - right.row_number)
}

/**
 * Vztahy, na které lze v daném měsíci zadat mzdový vstup.
 *
 * Archivovaný vztah a vztah, do kterého nikdo nenastoupil, se nenabízejí —
 * server je odmítne stejně (PayrollInputRepository::assertValidReferences).
 */
export const PAYROLL_INPUT_EXCLUDED_STATUSES = ['archived', 'no_show']

/**
 * Nabídka vztahů z JEDNOHO hromadného výpisu (`GET /payroll/time/context`).
 *
 * Dřív se stavěla ze seznamu osob a detailu KAŽDÉ z nich — jeden HTTP požadavek na
 * zaměstnance, a každý vracel celou historii vztahů (podmínky, checklist, timeline),
 * z níž stránka četla čtyři pole. Hromadný výpis to zvládne jedním dotazem a
 * archivované ani nenastoupivší vztahy vůbec nevrací; filtr statusů se tady přesto
 * opakuje, protože ten výpis primárně slouží absencím a jeho podmínka se může změnit
 * bez ohledu na to, co smí do mzdového vstupu.
 */
export function payrollEmploymentOptionsFromContext(
  employments: PayrollAbsenceEmployment[],
): PayrollEmploymentOption[] {
  return employments
    .map(employment => ({
      employee_id: employment.employee_id,
      employment_id: employment.id,
      full_name: employment.full_name,
      code: employment.code,
      relation_type: employment.relation_type,
      status: employment.status,
    }))
    .filter(option => !PAYROLL_INPUT_EXCLUDED_STATUSES.includes(option.status))
    // Řazení drží klient: české řazení podle jména se v collation serveru
    // a v `localeCompare` liší.
    .sort((left, right) =>
      left.full_name.localeCompare(right.full_name, 'cs')
      || left.code.localeCompare(right.code, 'cs'))
}

export function parsePayrollAmountToMinor(value: string): number | null {
  const normalized = value.trim().replace(/\s/g, '').replace(',', '.')
  const match = /^(-?)(\d+)(?:\.(\d{1,2}))?$/.exec(normalized)
  if (!match) return null
  const whole = Number(match[2])
  const fraction = Number((match[3] ?? '').padEnd(2, '0'))
  if (!Number.isSafeInteger(whole)) return null
  const minor = whole * 100 + fraction
  if (!Number.isSafeInteger(minor)) return null
  return match[1] === '-' ? -minor : minor
}

export function parsePayrollHoursToMilli(value: string): number | null {
  const normalized = value.trim().replace(',', '.')
  const match = /^(\d+)(?:\.(\d{1,3}))?$/.exec(normalized)
  if (!match) return null
  const whole = Number(match[1])
  const fraction = Number((match[2] ?? '').padEnd(3, '0'))
  if (!Number.isSafeInteger(whole)) return null
  const milli = whole * 1000 + fraction
  return Number.isSafeInteger(milli) ? milli : null
}

export function payrollMinorToInput(value: number | null): string {
  if (value === null) return ''
  const sign = value < 0 ? '-' : ''
  const absolute = Math.abs(value)
  return `${sign}${Math.floor(absolute / 100)},${String(absolute % 100).padStart(2, '0')}`
}

export function monthStart(period: string): string {
  return /^\d{4}-\d{2}$/.test(period) ? `${period}-01` : ''
}
