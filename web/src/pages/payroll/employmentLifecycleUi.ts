import type { PayrollEmploymentStatus } from '@/api/payroll'
import { appIsoDate } from '@/utils/date'

export interface EmploymentTransitionPresentation {
  target: PayrollEmploymentStatus
  variant: 'primary' | 'success' | 'warning' | 'danger' | 'neutral'
  tier: 'primary' | 'secondary' | 'overflow' | 'advanced'
  icon: 'check' | 'play' | 'pause' | 'archive' | 'x' | 'cycle'
}

const PRESENTATION: Record<PayrollEmploymentStatus, Omit<EmploymentTransitionPresentation, 'target'>> = {
  planned: { variant: 'neutral', tier: 'secondary', icon: 'play' },
  preregistered: { variant: 'primary', tier: 'primary', icon: 'check' },
  active: { variant: 'success', tier: 'primary', icon: 'play' },
  suspended: { variant: 'warning', tier: 'secondary', icon: 'pause' },
  ended: { variant: 'danger', tier: 'overflow', icon: 'x' },
  archived: { variant: 'neutral', tier: 'advanced', icon: 'archive' },
  no_show: { variant: 'danger', tier: 'advanced', icon: 'x' },
}

export function transitionPresentation(
  allowed: PayrollEmploymentStatus[],
  from?: PayrollEmploymentStatus,
): EmploymentTransitionPresentation[] {
  // Z archivu vede zpátky jediná cesta a server ji vybral podle historie.
  // Uživatel ale nehledá „skončený" ani „nenastoupil" — hledá „vrátit z archivu",
  // takže se nabídne pod tímhle jménem a s neutrální, ne nebezpečnou barvou.
  if (from === 'archived') {
    return allowed.map(target => ({
      target,
      variant: 'neutral' as const,
      tier: 'secondary' as const,
      icon: 'cycle' as const,
    }))
  }
  return allowed.map(target => ({ target, ...PRESENTATION[target] }))
}

/**
 * Kód vztahu se ukazuje jen tehdy, když něco znamená.
 *
 * Vztahy převzaté z původní evidence dostaly při materializaci kód `legacy`
 * (migrace 1188, `is_legacy_projection = 1`). Je to interní značka, ne údaj
 * zaměstnavatele — na kartě člověka vypadá jako název pracovního poměru a mate.
 * Kdo si kód vyplní sám, uvidí ho beze změny.
 *
 * Sdílené místo záměrně: kartu zaměstnance i přehled karet to musí zobrazovat
 * stejně, jinak se to potřetí rozejde.
 */
export function employmentCodeLabel(code: string | null | undefined): string {
  const trimmed = (code ?? '').trim()
  return trimmed === '' || trimmed.toLowerCase() === 'legacy' ? '' : trimmed
}

/**
 * Poznámka k události časové osy. Technické poznámky vložené migrací nejsou
 * text pro uživatele — „Legacy projekce" (migrace 1196) je značka převodu,
 * ne informace. Databázi neupravujeme, aby se neztratila stopa; filtrujeme
 * až při zobrazení.
 */
const INTERNAL_EVENT_NOTES = new Set(['legacy projekce'])

export function employmentEventNote(note: string | null | undefined): string {
  const trimmed = (note ?? '').trim()
  return INTERNAL_EVENT_NOTES.has(trimmed.toLowerCase()) ? '' : trimmed
}

/**
 * Hodnota v diffu časové osy.
 *
 * Časová osa dřív překládala JMÉNO pole, ale hodnotu vypisovala syrově z databáze —
 * uživatel tak četl „Registrace ČSSZ / JMHZ: pending → completed" nebo
 * „Typ vztahu: — → partner_dependent". Překlad se nedá udělat v šabloně, protože
 * podle pole se liší i překladový slovník; proto se tady rozhodne, ČÍM hodnota je,
 * a komponenta to teprve vykreslí (potřebuje `t` i `formatDate`).
 */
export type EmploymentDiffValue =
  | { kind: 'empty' }
  | { kind: 'text', text: string }
  | { kind: 'date', iso: string }
  | { kind: 'key', key: string }

const CHECKLIST_ITEM_KEYS = new Set([
  'employment_contract', 'legacy_start_date', 'health_insurance_registration',
  'social_jmhz_registration', 'tax_declaration', 'contract_amendment',
  'health_insurance_change', 'social_jmhz_change', 'termination_document',
  'health_insurance_deregistration', 'social_jmhz_deregistration',
  'enforcement_insolvency_review', 'later_income_review',
  'eldp_submission', 'taxable_income_confirmation',
])

const DATE_FIELDS = new Set([
  'effective_from', 'contract_signed_on', 'planned_start_on', 'actual_start_on',
  'fixed_term_end_on', 'a1_certificate_until',
])

const BOOLEAN_FIELDS = new Set(['risky_work', 'tax_declaration_signed', 'is_primary'])

/**
 * Pole → překladový slovník a jeho povolené hodnoty. Hodnota mimo výčet se vypíše
 * syrově: `t()` na neexistujícím klíči vrátí sám klíč, což vypadá hůř než původní
 * text. Radši tedy syrová hodnota než `payroll.people.tax_regime.neco`.
 */
const ENUM_FIELDS: Record<string, { prefix: string, values: string[] }> = {
  status: {
    prefix: 'payroll.people.employment_status',
    values: ['planned', 'preregistered', 'active', 'suspended', 'ended', 'archived', 'no_show'],
  },
  relation_type: {
    prefix: 'payroll.people.relations',
    values: ['employment', 'small_scale_employment', 'dpp', 'dpc', 'partner_dependent', 'statutory_body'],
  },
  social_insurance_participation: {
    prefix: 'payroll.people.insurance_mode',
    values: ['automatic', 'included', 'excluded', 'foreign'],
  },
  health_insurance_participation: {
    prefix: 'payroll.people.insurance_mode',
    values: ['automatic', 'included', 'excluded', 'foreign'],
  },
  tax_regime: {
    prefix: 'payroll.people.tax_regime',
    values: ['advance', 'withholding', 'foreign', 'manual_review'],
  },
  other_withholding_eligibility: {
    prefix: 'payroll.people.other_withholding_eligibility',
    values: ['unverified', 'eligible', 'ineligible'],
  },
  social_employer_rate_category: {
    prefix: 'payroll.people.social_employer_rate_category',
    values: ['ordinary', 'rescue_and_company_fire_service', 'risk_employment'],
  },
  social_part_time_discount_reason: {
    prefix: 'payroll.people.social_part_time_discount_reason',
    values: [
      'none',
      'age_55_plus',
      'child_care_under_10',
      'dependent_close_person_care',
      'study_under_26',
      'retraining_jobseeker',
      'disabled_person',
      'under_21',
    ],
  },
  jmhz_apz_contribution_status: {
    prefix: 'payroll.people.jmhz_evidence.state',
    values: ['unverified', 'no', 'yes'],
  },
  jmhz_functional_benefits_status: {
    prefix: 'payroll.people.jmhz_evidence.state',
    values: ['unverified', 'no', 'yes'],
  },
  jmhz_temporary_assignment_status: {
    prefix: 'payroll.people.jmhz_evidence.state',
    values: ['unverified', 'no', 'yes'],
  },
}

export function employmentDiffValue(field: string, value: unknown): EmploymentDiffValue {
  if (value === null || value === undefined || value === '') return { kind: 'empty' }

  if (BOOLEAN_FIELDS.has(field)) {
    const truthy = value === true || value === 1 || value === '1' || value === 'true'
    return { kind: 'key', key: truthy ? 'common.yes' : 'common.no' }
  }

  const text = String(value)

  if (CHECKLIST_ITEM_KEYS.has(field)) {
    return ['pending', 'completed', 'not_applicable'].includes(text)
      ? { kind: 'key', key: `payroll.people.checklist_status.${text}` }
      : { kind: 'text', text }
  }

  const enumField = ENUM_FIELDS[field]
  if (enumField) {
    return enumField.values.includes(text)
      ? { kind: 'key', key: `${enumField.prefix}.${text}` }
      : { kind: 'text', text }
  }

  if (DATE_FIELDS.has(field) && /^\d{4}-\d{2}-\d{2}/.test(text)) {
    return { kind: 'date', iso: text }
  }

  // Úvazek se ukládá v bazických bodech (10000 = plný). Číslo „10000" nikomu nic neřekne.
  if (field === 'workload_basis_points') {
    const points = Number(text)
    if (Number.isFinite(points)) return { kind: 'text', text: `${points / 100} %` }
  }

  return { kind: 'text', text }
}

/**
 * Změnu stavu ukazuje časová osa už v hlavičce události („Skončený → Archivovaný"),
 * takže stejný údaj v diffu je druhý výpis téhož. Filtruje se jen tam, kde hlavička
 * opravdu vznikla — u události bez `from_status`/`to_status` je diff jediný zdroj.
 */
export function employmentDiffFields(
  diff: Record<string, unknown> | null | undefined,
  hasStatusHeader: boolean,
): string[] {
  const keys = Object.keys(diff ?? {})
  return hasStatusHeader ? keys.filter(key => key !== 'status') : keys
}

/**
 * „Dnešek" pro mzdové události (nástup, výstup, změna údajů).
 *
 * Bere se v účetní zóně, ne v zóně prohlížeče — datum nástupu je právní
 * skutečnost v českém kalendáři a nesmí se lišit podle toho, odkud mzdovou
 * účetní zrovna klika. Viz {@see appIsoDate}.
 */
export function todayIso(now = new Date()): string {
  return appIsoDate(now)
}
