import type {
  PayrollRun,
  PayrollRunCommand,
  PayrollRunValidation,
  PayrollStatutoryBulkPreview,
} from '@/api/payroll'

/** Validace běhu, u které se hromadné doplnění nabízí. */
export const STATUTORY_REVIEW_CODE = 'statutory_calculation_manual_review'

/** Sekce s výchozím stavem v pořadí, v jakém je vrací server. */
export const BULK_DEFAULT_SECTIONS = [
  'tax_residences',
  'social_jurisdictions',
  'social_discount_claims',
] as const

/*
 * Kódy, které server posílá místo vět. Neznámý kód se ukáže tak, jak přišel:
 * je to lepší než prázdné místo a nová hodnota na serveru tím nerozbije UI.
 */
const CODE_GROUPS = {
  reason: ['employee_not_found', 'no_employment_in_period', 'period_frozen', 'foreign_element', 'nothing_to_add'],
  foreign: [
    'address_abroad',
    'foreign_citizenship',
    'foreign_permit',
    'foreign_legislation',
    'a1_certificate',
    'foreign_insurance_or_tax_regime',
    'foreign_statutory_evidence',
  ],
  discount: ['age_60_or_more', 'birth_date_missing', 'birth_date_invalid'],
  basis: ['run_month', 'employment_start', 'after_frozen_period'],
} as const

export type BulkCodeGroup = keyof typeof CODE_GROUPS

/** Klíč překladu kódu ze serveru, nebo `null`, když kód neznáme. */
export function bulkCodeKey(group: BulkCodeGroup, code: string): string | null {
  return (CODE_GROUPS[group] as readonly string[]).includes(code)
    ? `payroll.statutory_bulk.${group}.${code}`
    : null
}

/**
 * Koho poslat k zápisu. Připravené osoby vždy; osoby, kterým chybí jen
 * prohlášení, jen když účetní výslovně potvrdila zápis „nepodepsal".
 * Pořadí drží náhled, duplicity se vynechají.
 */
export function bulkApplyEmployeeIds(
  preview: Pick<PayrollStatutoryBulkPreview, 'ready_employee_ids' | 'declaration_missing_employee_ids'>,
  recordUnsignedDeclaration: boolean,
): number[] {
  const ids = new Set(preview.ready_employee_ids)
  if (recordUnsignedDeclaration) {
    for (const id of preview.declaration_missing_employee_ids) ids.add(id)
  }
  return [...ids]
}

/** Kolik osob má slevu důchodce vynechanou a proč (věk, chybějící datum narození). */
export function discountObstacleCounts(
  preview: Pick<PayrollStatutoryBulkPreview, 'people'>,
): Array<{ reason: string, count: number }> {
  const counts = new Map<string, number>()
  for (const person of preview.people) {
    if (person.status === 'excluded') continue
    const section = person.sections.social_discount_claims
    if (section?.state === 'excluded' && section.reason) {
      counts.set(section.reason, (counts.get(section.reason) ?? 0) + 1)
    }
  }
  return [...counts].map(([reason, count]) => ({ reason, count }))
}

/**
 * Od kdy se u koho zapisuje, pokud to není první den měsíce běhu. Jen u
 * doplňovaných osob, u vyřazených se nezapisuje nic.
 */
export function effectiveBasisCounts(
  preview: Pick<PayrollStatutoryBulkPreview, 'people'>,
): Array<{ basis: string, count: number }> {
  const counts = new Map<string, number>()
  for (const person of preview.people) {
    if (person.status !== 'ready' || !person.effective_from_basis) continue
    if (person.effective_from_basis === 'run_month') continue
    counts.set(person.effective_from_basis, (counts.get(person.effective_from_basis) ?? 0) + 1)
  }
  return [...counts].map(([basis, count]) => ({ basis, count }))
}

/*
 * Nedokončený zákonný výpočet, který výchozí evidence opravdu řeší.
 *
 * Tentýž kód nese i „chybí schválená mzdová složka" (proklik na rychlé zadání)
 * a další příčiny mimo zákonnou evidenci. Dialog doplnění rezidence,
 * příslušnosti a slevy s nimi nic neudělá, a když se nabízel u 182 lidí bez
 * mzdové složky, vedl do slepé uličky „0 osob k doplnění". Totéž platilo pro
 * chybějící zdravotní pojištění nebo počáteční úhrny: vedou do zákonné
 * evidence či na kartu vztahu, ale výchozí údaje je nedoplní. Rozhoduje proto
 * sekce, na kterou proklik míří (`field=statutory.<sekce>`), nebo nic
 * konkrétního (starší záznam bez prokliku).
 */
const BULK_FIXABLE_FIELDS = [
  'statutory.tax_declarations',
  ...BULK_DEFAULT_SECTIONS.map(section => `statutory.${section}`),
]

function isStatutoryEvidenceReview(validation: PayrollRunValidation): boolean {
  if (validation.code !== STATUTORY_REVIEW_CODE) return false
  const path = validation.remediation_path
  if (path === null || path === undefined || path === '') return true
  const query = path.includes('?') ? path.slice(path.indexOf('?') + 1) : ''
  const field = new URLSearchParams(query).get('field')
  return field !== null && BULK_FIXABLE_FIELDS.includes(field)
}

/** Osoby, u kterých běh hlásí nedokončený zákonný výpočet (jen záznamy vázané na osobu). */
export function statutoryReviewEmployeeIds(validations: PayrollRunValidation[]): number[] {
  const ids = new Set<number>()
  for (const validation of validations) {
    if (!isStatutoryEvidenceReview(validation)) continue
    if (validation.entity_type === 'employee' && validation.entity_id !== null) {
      ids.add(validation.entity_id)
    }
  }
  return [...ids]
}

/** První validace skupiny: akce se u běhu kreslí jednou, ne u každé osoby. */
export function firstStatutoryReviewId(validations: PayrollRunValidation[]): number | null {
  return validations.find(isStatutoryEvidenceReview)?.id ?? null
}

/*
 * Jak dostat nově zapsanou evidenci do běhu.
 *
 * `calculate` počítá ze zmrazeného snímku vstupů, takže sám nic nového
 * nepřinese. Nový snímek vzniká uzamčením vstupů (koncept), obnovou podkladů
 * (`refresh_inputs` u otevřené revize) nebo novou revizí (`reopen` ze
 * zrušeného nebo opravného běhu). Schválený běh se vrací k opravě. Zrušení
 * zůstává jen jako záloha pro odpověď serveru bez obnovy podkladů. Pořadí
 * vybírá první krok, který je v daném stavu opravdu k dispozici.
 */
const REFRESH_ORDER: PayrollRunCommand[] = [
  'lock_and_calculate',
  'lock_inputs',
  'reopen',
  'refresh_inputs',
  'cancel',
  'request_correction',
]

export function evidenceRefreshCommand(
  run: Pick<PayrollRun, 'available_commands'>,
): PayrollRunCommand | null {
  return REFRESH_ORDER.find(command => run.available_commands.includes(command)) ?? null
}
