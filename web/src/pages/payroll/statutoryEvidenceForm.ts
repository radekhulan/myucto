/**
 * Pravidla formuláře zákonné evidence osoby.
 *
 * Server (`PayrollPersonStatutoryEvidenceValidator` + `…Repository`) je jediný
 * pán nad tím, co je platná právní skutečnost. Tenhle modul jeho pravidla
 * NEMĚKČÍ — jen je promítá do formuláře dřív, než uživatel klikne na Uložit:
 *
 * 1. **Odvozená pole se neptají.** Co plyne z jiné odpovědi (u českého rezidenta
 *    stát „CZ", u českého sociálního režimu „A1 se netýká"), formulář dosadí sám
 *    a schová.
 * 2. **Přepnutí volby dorovná závislá pole.** Neviditelné pole se vyprázdní (nebo
 *    dostane svou odvozenou hodnotu), aby formulář neposílal rozporné údaje.
 * 3. **Odkaz na doklad je volitelný.** Kdo ho chce evidovat, vybere typický
 *    podklad nebo napíše vlastní kanonickou referenci; prázdná hodnota uložení
 *    ani zákonný výpočet neblokuje.
 *
 * Modul je záměrně bez Vue: pravidla jde tak přečíst i otestovat samostatně.
 */
import type {
  PayrollStatutoryEvidenceRow,
  PayrollStatutoryEvidenceSection,
} from '@/api/payroll'
import { isHealthInsurerCode } from '@/utils/healthInsurers'

export type StatutoryFieldKind =
  | 'enum'
  | 'country'
  | 'date'
  | 'evidence'
  | 'insurer'
  | 'employer'

export interface StatutoryFieldSpec {
  key: string
  kind: StatutoryFieldKind
  options?: readonly string[]
  /** Kdy má pole vůbec smysl ukazovat. Neuvedeno = vždy. */
  visible?: (row: PayrollStatutoryEvidenceRow) => boolean
  /**
   * Hodnota, kterou pole nese, když je skryté. Výchozí `null` (server bere
   * prázdno jako „neuvedeno"); `a1_status` a `country_code` mají místo toho
   * odvozenou hodnotu, protože prázdné je server odmítne.
   */
  whenHidden?: (row: PayrollStatutoryEvidenceRow) => string | null
}

export interface StatutorySectionSpec {
  key: PayrollStatutoryEvidenceSection
  kind: 'interval' | 'month'
  fields: readonly StatutoryFieldSpec[]
}

export interface StatutoryFormContext {
  /** Den, ke kterému se evidence vyhodnocuje — mez platnosti A1. */
  effectiveOn: string
  /** Výchozí zdravotní pojišťovna (historie osoby → nastavení zaměstnavatele). */
  defaultInsurerCode: string | null
  /** Reference na základy u jiného zaměstnavatele, doložené pro daný měsíc. */
  employerReferences: readonly string[]
}

export interface StatutoryIssue {
  key: string
  params?: Record<string, string>
}

/** Tvar kanonické reference podle serverového validátoru. */
export const CANONICAL_REFERENCE = /^[A-Za-z0-9][A-Za-z0-9_.:/-]*$/

function text(row: PayrollStatutoryEvidenceRow, key: string): string {
  const value = row[key]
  return typeof value === 'string' ? value.trim() : ''
}

const isForeign = (row: PayrollStatutoryEvidenceRow): boolean =>
  text(row, 'jurisdiction') === 'foreign_regime_verified'

export const STATUTORY_SECTIONS: readonly StatutorySectionSpec[] = [
  {
    key: 'tax_declarations',
    kind: 'interval',
    fields: [
      { key: 'status', kind: 'enum', options: ['signed', 'not-signed', 'unverified'] },
      {
        key: 'evidence_reference',
        kind: 'evidence',
        visible: row => text(row, 'status') !== 'unverified',
      },
    ],
  },
  {
    key: 'tax_residences',
    kind: 'interval',
    fields: [
      { key: 'residence', kind: 'enum', options: ['czech-resident', 'non-resident', 'unverified'] },
      {
        key: 'country_code',
        kind: 'country',
        // Český rezident má stát „CZ" z definice — ptát se na něj je otázka,
        // na kterou už uživatel odpověděl o řádek výš.
        visible: row => text(row, 'residence') === 'non-resident',
        whenHidden: row => (text(row, 'residence') === 'czech-resident' ? 'CZ' : null),
      },
      {
        key: 'evidence_reference',
        kind: 'evidence',
        visible: row => text(row, 'residence') !== 'unverified',
      },
    ],
  },
  {
    key: 'social_jurisdictions',
    kind: 'interval',
    fields: [
      {
        key: 'jurisdiction',
        kind: 'enum',
        options: ['czech_regime_verified', 'foreign_regime_verified', 'unverified'],
      },
      { key: 'foreign_country_code', kind: 'country', visible: isForeign },
      { key: 'jurisdiction_evidence_reference', kind: 'evidence', visible: isForeign },
      {
        key: 'a1_status',
        kind: 'enum',
        options: ['not_applicable', 'verified', 'unverified'],
        // Český režim A1 vylučuje (validátor to vyžaduje doslova), takže se
        // celá sekce A1 objeví až u zahraničního režimu.
        visible: isForeign,
        whenHidden: () => 'not_applicable',
      },
      {
        key: 'a1_certificate_reference',
        kind: 'evidence',
        visible: row => isForeign(row) && text(row, 'a1_status') === 'verified',
      },
      {
        key: 'a1_valid_until',
        kind: 'date',
        visible: row => isForeign(row) && text(row, 'a1_status') === 'verified',
      },
    ],
  },
  {
    key: 'social_discount_claims',
    kind: 'interval',
    fields: [
      { key: 'status', kind: 'enum', options: ['not_claimed', 'verified', 'unverified'] },
      {
        key: 'evidence_reference',
        kind: 'evidence',
        visible: row => text(row, 'status') === 'verified',
      },
    ],
  },
  {
    key: 'health_coverages',
    kind: 'interval',
    fields: [
      {
        key: 'jurisdiction',
        kind: 'enum',
        options: ['czech_regime_verified', 'foreign_regime_verified', 'unverified'],
      },
      { key: 'foreign_country_code', kind: 'country', visible: isForeign },
      { key: 'jurisdiction_evidence_reference', kind: 'evidence', visible: isForeign },
      { key: 'insurer_status', kind: 'enum', options: ['verified', 'unverified', 'not_applicable'] },
      {
        key: 'insurer_code',
        kind: 'insurer',
        visible: row => text(row, 'insurer_status') !== 'not_applicable',
      },
      {
        key: 'insurer_evidence_reference',
        kind: 'evidence',
        visible: row => text(row, 'insurer_status') === 'verified',
      },
    ],
  },
  {
    key: 'health_month_evidence',
    kind: 'month',
    fields: [
      {
        key: 'top_up_responsibility',
        kind: 'enum',
        options: ['employee', 'employer_obstacle_verified', 'unverified'],
      },
      {
        key: 'top_up_responsibility_evidence_reference',
        kind: 'evidence',
        visible: row => text(row, 'top_up_responsibility') === 'employer_obstacle_verified',
      },
      { key: 'selected_top_up_employer_reference', kind: 'employer' },
      {
        key: 'selected_top_up_employer_evidence_reference',
        kind: 'evidence',
        visible: row => text(row, 'selected_top_up_employer_reference') !== '',
      },
    ],
  },
] as const

/**
 * Typické důvody, proč je právní skutečnost doložená.
 *
 * Hodnota je kanonická reference, která odejde na server; člověk vidí jen
 * překlad. Řetězce musí projít {@link CANONICAL_REFERENCE} — proto jen písmena,
 * číslice, `:` a `-`.
 */
export const EVIDENCE_REASONS: Readonly<Record<string, readonly string[]>> = {
  'tax_declarations.evidence_reference': [
    'declaration:38k-signed',
    'declaration:38k-not-signed',
  ],
  'tax_residences.evidence_reference': [
    'residence:cz-birth-number-address',
    'residence:cz-domicile-certificate',
    'residence:foreign-domicile-certificate',
    'residence:foreign-tax-authority-confirmation',
  ],
  'social_jurisdictions.jurisdiction_evidence_reference': [
    'social:a1-certificate',
    'social:foreign-insurance-confirmation',
    'social:posting-contract',
  ],
  'social_jurisdictions.a1_certificate_reference': ['a1:certificate-issued'],
  'social_discount_claims.evidence_reference': [
    'pension:award-decision',
    'pension:employee-claim',
  ],
  'health_coverages.jurisdiction_evidence_reference': [
    'health:s1-form',
    'health:foreign-insurance-confirmation',
  ],
  'health_coverages.insurer_evidence_reference': [
    'health:insurer-registration',
    'health:insured-card',
  ],
  'health_month_evidence.top_up_responsibility_evidence_reference': [
    'minimum:employer-obstacle',
    'minimum:unpaid-leave-agreement',
  ],
  'health_month_evidence.selected_top_up_employer_evidence_reference': [
    'minimum:other-employer-confirmation',
  ],
}

/** Volba „jiné" v nabídce důvodů — odemkne volný text. */
export const CUSTOM_REASON = 'custom'

/** Klíč překladu důvodu; `:`, `-` a `.` by v cestě vue-i18n mátly. */
export function reasonLabelKey(reference: string): string {
  return reference.replace(/[^A-Za-z0-9]/g, '_')
}

/** Důvody nabídnuté k aktuálnímu stavu řádku. */
export function reasonOptions(
  section: PayrollStatutoryEvidenceSection,
  field: string,
  row: PayrollStatutoryEvidenceRow,
): readonly string[] {
  const all = EVIDENCE_REASONS[`${section}.${field}`] ?? []
  if (section === 'tax_declarations' && field === 'evidence_reference') {
    const signed = text(row, 'status') !== 'not-signed'
    return all.filter(reason => (reason === 'declaration:38k-not-signed') !== signed)
  }
  if (section === 'tax_residences' && field === 'evidence_reference') {
    const prefix = text(row, 'residence') === 'non-resident'
      ? 'residence:foreign-'
      : 'residence:cz-'
    return all.filter(reason => reason.startsWith(prefix))
  }
  return all
}

export function isFieldVisible(
  field: StatutoryFieldSpec,
  row: PayrollStatutoryEvidenceRow,
): boolean {
  return field.visible?.(row) ?? true
}

export function visibleFields(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
): StatutoryFieldSpec[] {
  return section.fields.filter(field => isFieldVisible(field, row))
}

/**
 * Dorovná závislá pole tak, aby řádek prošel serverem.
 *
 * Volá se po každé změně i nad novým řádkem — invariant „formulář nikdy nedrží
 * stav, který server odmítne" platí jen tehdy, když se dorovnává vždy.
 */
export function normalizeRow(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
): void {
  // Nerezident se zahraničím „CZ" je protimluv; pole se vyprázdní, ať ho
  // uživatel vyplní, místo aby ho server odmítl až po uložení.
  if (section.key === 'tax_residences'
    && text(row, 'residence') === 'non-resident'
    && text(row, 'country_code') === 'CZ'
  ) {
    row.country_code = null
  }

  for (const field of section.fields) {
    if (!isFieldVisible(field, row)) {
      row[field.key] = field.whenHidden?.(row) ?? null
      continue
    }
    if (field.kind !== 'evidence') continue
    const known = EVIDENCE_REASONS[`${section.key}.${field.key}`] ?? []
    const current = text(row, field.key)
    const offered = reasonOptions(section.key, field.key, row)
    // Vlastní označení (mimo číselník důvodů) je uživatelův vstup — ten se
    // nepřepisuje. Jen typický důvod, který k nové volbě nepatří, se uklidí.
    if (known.includes(current) && !offered.includes(current)) {
      row[field.key] = null
    }
  }
}

/** Kaskáda po změně jednoho pole; končí vždy dorovnáním celého řádku. */
export function applyFieldChange(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
  changed: string,
  context: StatutoryFormContext,
): void {
  if (section.key === 'health_coverages' && changed === 'jurisdiction') {
    if (isForeign(row)) {
      row.insurer_status = 'not_applicable'
    } else if (text(row, 'insurer_status') === 'not_applicable') {
      row.insurer_status = 'verified'
    }
  }
  if (section.key === 'health_coverages'
    && (changed === 'jurisdiction' || changed === 'insurer_status')
    && text(row, 'insurer_status') !== 'not_applicable'
    && text(row, 'insurer_code') === ''
    && context.defaultInsurerCode !== null
  ) {
    row.insurer_code = context.defaultInsurerCode
  }
  normalizeRow(section, row)
}

/**
 * Běžná česká situace, ne „první možnost z enumu": rezident CZ, český sociální
 * i zdravotní režim, A1 se netýká, sleva důchodce se neuplatňuje a pojišťovna
 * je ta, u které je osoba vedená (nebo výchozí pojišťovna zaměstnavatele).
 */
const DEFAULT_VALUES: Readonly<
  Record<PayrollStatutoryEvidenceSection, Readonly<Record<string, string | null>>>
> = {
  tax_declarations: { status: 'not-signed', evidence_reference: null },
  tax_residences: { residence: 'czech-resident', country_code: 'CZ', evidence_reference: null },
  social_jurisdictions: {
    jurisdiction: 'czech_regime_verified',
    foreign_country_code: null,
    jurisdiction_evidence_reference: null,
    a1_status: 'not_applicable',
    a1_certificate_reference: null,
    a1_valid_until: null,
  },
  social_discount_claims: { status: 'not_claimed', evidence_reference: null },
  health_coverages: {
    jurisdiction: 'czech_regime_verified',
    foreign_country_code: null,
    jurisdiction_evidence_reference: null,
    insurer_status: 'verified',
    insurer_code: null,
    insurer_evidence_reference: null,
  },
  health_month_evidence: {
    top_up_responsibility: 'employee',
    top_up_responsibility_evidence_reference: null,
    selected_top_up_employer_reference: null,
    selected_top_up_employer_evidence_reference: null,
  },
}

export function defaultRow(
  section: StatutorySectionSpec,
  monthStart: string,
  context: StatutoryFormContext,
): PayrollStatutoryEvidenceRow {
  const row: PayrollStatutoryEvidenceRow = { ...DEFAULT_VALUES[section.key] }
  row.evidence_note = null
  if (section.kind === 'month') {
    row.period_start = monthStart
  } else {
    row.effective_from = monthStart
    row.effective_to = null
  }
  if (section.key === 'health_coverages') {
    row.insurer_code = context.defaultInsurerCode
  }
  normalizeRow(section, row)
  return row
}

export function monthEndOf(iso: string): string {
  const [year, month] = iso.split('-').map(Number)
  if (!year || !month) return iso
  const end = new Date(Date.UTC(year, month, 0))
  return `${end.getUTCFullYear()}-${String(end.getUTCMonth() + 1).padStart(2, '0')}-${String(end.getUTCDate()).padStart(2, '0')}`
}

/**
 * Chyby, které formulář pozná dřív než server — a hlavně je pojmenuje řešením.
 * Serverová „Ověřený A1 musí mít důkaz a platit k datu snímku." uživateli
 * neřekne, do kdy má A1 platit ani co má udělat, když ho nemá.
 */
export function rowIssues(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
  context: StatutoryFormContext,
): StatutoryIssue[] {
  const issues: StatutoryIssue[] = []
  const from = section.kind === 'month' ? text(row, 'period_start') : text(row, 'effective_from')
  if (from === '') {
    issues.push({ key: 'period_required' })
  } else if (!from.endsWith('-01')) {
    issues.push({ key: 'period_month_start', params: { day: `${from.slice(0, 7)}-01` } })
  }
  if (section.kind === 'interval') {
    const to = text(row, 'effective_to')
    if (to !== '' && from !== '' && to < from) {
      issues.push({ key: 'effective_to_before_from' })
    } else if (to !== '' && to !== monthEndOf(to)) {
      issues.push({ key: 'effective_to_month_end', params: { day: monthEndOf(to) } })
    }
  }

  for (const field of section.fields) {
    if (!isFieldVisible(field, row)) continue
    const value = text(row, field.key)
    const label = `payroll.people.statutory_evidence.field.${field.key}`
    if (field.kind === 'country') {
      if (value === '') issues.push({ key: 'country_required', params: { label } })
      else if (section.key === 'tax_residences' && value === 'CZ') {
        issues.push({ key: 'country_must_be_foreign' })
      }
    }
    if (field.kind === 'insurer') {
      if (value === '') {
        if (text(row, 'insurer_status') === 'verified') issues.push({ key: 'insurer_required' })
      } else if (!isHealthInsurerCode(value)) {
        issues.push({ key: 'insurer_unknown', params: { code: value } })
      }
    }
    if (field.kind === 'evidence') {
      if (value !== '' && !CANONICAL_REFERENCE.test(value)) {
        issues.push({ key: 'reference_invalid', params: { label } })
      }
    }
    if (field.kind === 'employer' && value !== ''
      && !context.employerReferences.includes(value)
    ) {
      issues.push({ key: 'employer_unknown' })
    }
  }

  // Protějšek serverového pravidla „ověřená česká zdravotní jurisdikce nemůže
  // mít pojišťovnu jako nepoužitelnou". Kaskáda v `applyFieldChange` řeší jen
  // přepnutí JURISDIKCE; `insurer_status` si uživatel může přepnout přímo, a to
  // je jediná cesta, jak se do zakázané kombinace ve formuláři dostat.
  if (section.key === 'health_coverages'
    && text(row, 'jurisdiction') === 'czech_regime_verified'
    && text(row, 'insurer_status') === 'not_applicable'
  ) {
    issues.push({ key: 'insurer_not_applicable_in_czech_regime' })
  }

  if (section.key === 'social_jurisdictions' && text(row, 'a1_status') === 'verified') {
    // Validátor porovnává platnost A1 se dnem snímku i se začátkem účinnosti
    // řádku — rozhoduje ten pozdější z nich.
    const limit = from > context.effectiveOn ? from : context.effectiveOn
    const until = text(row, 'a1_valid_until')
    if (until === '') issues.push({ key: 'a1_valid_until_required', params: { day: limit } })
    else if (until < limit) issues.push({ key: 'a1_valid_until_too_early', params: { day: limit } })
  }

  return issues
}

/** Chyby, které dávají smysl až nad celou řadou. */
export function sectionIssues(
  section: StatutorySectionSpec,
  rows: readonly PayrollStatutoryEvidenceRow[],
): StatutoryIssue[] {
  if (section.kind !== 'interval') return []
  const open = rows.filter(row => text(row, 'effective_to') === '').length
  return open > 1 ? [{ key: 'multiple_open_rows' }] : []
}

export { text as statutoryText }
