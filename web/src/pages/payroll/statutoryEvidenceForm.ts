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
  | 'document'
  | 'insurer'
  | 'employer'
  /** Vlastní kanonické označení (reference zaměstnavatele). */
  | 'reference'
  /** Částka v haléřích; formulář ji zadává a ukazuje v korunách. */
  | 'money'

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
  /**
   * Pole, jehož hodnota pojmenovává stav sekce v přehledu „co teď platí".
   * Bez něj by přehled musel hádat, které z pěti polí je to podstatné.
   */
  summaryKey: string
  /** Doplněk přehledu (kód pojišťovny); vykresluje ho panel, ne tenhle modul. */
  summaryDetailKey?: string
  /**
   * Pole, které řadu dělí na SOUBĚŽNÉ řady (u slev na dani druh slevy).
   * Neuvedeno = jedna řada, ve které smí být otevřený jen jeden záznam.
   */
  scopeKey?: string
  /**
   * Prázdná evidence je legitimní stav, ne chybějící údaj. Platí pro slevy:
   * kdo žádnou neuplatňuje, nemá co vyplnit — na rozdíl od rezidence nebo
   * příslušnosti k pojištění, bez kterých se výpočet nespočítá.
   */
  optional?: boolean
  /**
   * Pole, jehož hodnota `unverified` znamená nedoložený stav. Neuvedeno =
   * rozhoduje `summaryKey` (u většiny sekcí je to totéž pole).
   */
  verificationKey?: string
  /**
   * Účinnost se zadává po dnech, ne po celých měsících. Platí pro výjimky
   * z minima zdravotního pojištění: § 3 odst. 9 písm. c) zákona č. 592/1992 Sb.
   * snižuje minimum poměrně podle kalendářních dnů a server je po dnech čte.
   */
  dayPrecision?: boolean
  /**
   * Hodnota `summaryKey` je volný text (reference), ne výčet; přehled ji
   * ukáže, jak je, místo překladu.
   */
  summaryRaw?: boolean
  /** Klíč překladu přehledu nepovinné sekce bez záznamu; výchozí „Žádná sleva". */
  emptyKey?: string
  /**
   * Řádky nezamyká hranice schválené mzdy. Platí pro důchodové údaje: mzdový
   * běh je nečte (server je drží mimo jeho snímek) a ELDP i hlášení si hodnotu
   * zmrazí samy v okamžiku sestavení.
   */
  unfrozen?: boolean
  /**
   * Jediný záznam bez konce platnosti (den dosažení důchodového věku). Pole
   * „Platí do" se neukazuje a další záznam se nenabízí.
   */
  single?: boolean
  fields: readonly StatutoryFieldSpec[]
}

export interface StatutoryFormContext {
  /** Den, ke kterému se evidence vyhodnocuje — mez platnosti A1. */
  effectiveOn: string
  /** Výchozí zdravotní pojišťovna (historie osoby → nastavení zaměstnavatele). */
  defaultInsurerCode: string | null
  /** Reference na základy u jiného zaměstnavatele, doložené pro daný měsíc. */
  employerReferences: readonly string[]
  /**
   * Den dosažení důchodového věku, který server spočítal z data narození
   * (jen je-li jednoznačný). Předvyplní se do nového záznamu, nic se neukládá samo.
   */
  pensionAgeSuggestion?: string | null
}

export interface StatutoryIssue {
  key: string
  params?: Record<string, string>
}

/** Tvar kanonické reference podle serverového validátoru. */
export const CANONICAL_REFERENCE = /^[A-Za-z0-9][A-Za-z0-9_.:/-]*$/

function text(row: PayrollStatutoryEvidenceRow, key: string): string {
  const value = row[key]
  return typeof value === 'string' ? value.trim() : typeof value === 'number' ? String(value) : ''
}

const isForeign = (row: PayrollStatutoryEvidenceRow): boolean =>
  text(row, 'jurisdiction') === 'foreign_regime_verified'

export const STATUTORY_SECTIONS: readonly StatutorySectionSpec[] = [
  {
    key: 'tax_declarations',
    kind: 'interval',
    summaryKey: 'status',
    fields: [
      { key: 'status', kind: 'enum', options: ['signed', 'not-signed', 'unverified'] },
      // Den podpisu prohlášení vykazuje měsíční hlášení od ledna 2027 (10575).
      { key: 'signed_on', kind: 'date', visible: row => text(row, 'status') === 'signed' },
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
    summaryKey: 'residence',
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
    // Slevy podle § 35ba. Bez zaevidované slevy na poplatníka platí každý
    // podepsaný zaměstnanec o 2 570 Kč měsíčně vyšší zálohu — sekce je proto
    // sice nepovinná, ale u běžného zaměstnance se vyplňuje vždy.
    key: 'tax_credit_claims',
    kind: 'interval',
    summaryKey: 'credit_kind',
    scopeKey: 'credit_kind',
    optional: true,
    verificationKey: 'evidence_status',
    fields: [
      {
        key: 'credit_kind',
        kind: 'enum',
        options: ['taxpayer', 'disability-basic', 'disability-extended', 'ztp-p', 'student', 'student-doctoral'],
      },
      { key: 'evidence_status', kind: 'enum', options: ['verified', 'unverified'] },
      {
        key: 'evidence_reference',
        kind: 'evidence',
        visible: row => text(row, 'evidence_status') === 'verified',
      },
    ],
  },
  {
    key: 'social_jurisdictions',
    kind: 'interval',
    summaryKey: 'jurisdiction',
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
    // Slevu pracujícího důchodce uplatňuje zaměstnanec sám; bez záznamu se
    // neuplatňuje a výpočet ani podání to nezastaví.
    key: 'social_discount_claims',
    kind: 'interval',
    summaryKey: 'status',
    optional: true,
    emptyKey: 'current_discount_not_claimed',
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
    summaryKey: 'jurisdiction',
    summaryDetailKey: 'insurer_code',
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
      {
        key: 'health_evidence_document_id',
        kind: 'document',
        visible: row => text(row, 'insurer_status') === 'verified',
      },
    ],
  },
  {
    key: 'health_month_evidence',
    kind: 'month',
    summaryKey: 'top_up_responsibility',
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
  {
    // Výjimky z minimálního vyměřovacího základu ZP (§ 3 odst. 8 a 9
    // z. 592/1992). Většina lidí žádnou nemá, proto je sekce nepovinná; každý
    // důvod je vlastní řada, takže ZTP/P a státní pojištěnec běží souběžně.
    key: 'health_minimum_reductions',
    kind: 'interval',
    summaryKey: 'reason',
    scopeKey: 'reason',
    optional: true,
    dayPrecision: true,
    emptyKey: 'current_no_exemption',
    fields: [
      {
        key: 'reason',
        kind: 'enum',
        options: [
          'state_insured',
          'ztp_or_ztp_p',
          'pension_age_without_pension',
          'osvc_minimum_advance',
          'foster_reward_only',
          'sickness_care_or_quarantine',
          'child_under_7_care',
          'unverified',
        ],
      },
      {
        key: 'evidence_reference',
        kind: 'evidence',
        visible: row => text(row, 'reason') !== 'unverified',
      },
    ],
  },
  {
    // Vyměřovací základ u jiného zaměstnavatele za měsíc (§ 3 odst. 10):
    // minimum se posuzuje z úhrnu základů, doplatek jde přes zvoleného
    // zaměstnavatele. Na tyhle řádky se odkazuje měsíční evidence minima.
    key: 'health_other_employer_bases',
    kind: 'month',
    summaryKey: 'employer_reference',
    scopeKey: 'employer_reference',
    optional: true,
    summaryRaw: true,
    emptyKey: 'current_no_other_employer',
    fields: [
      { key: 'employer_reference', kind: 'reference' },
      { key: 'assessment_base_minor_units', kind: 'money' },
      { key: 'employment_from', kind: 'date' },
      { key: 'employment_to', kind: 'date' },
      { key: 'evidence_reference', kind: 'evidence' },
    ],
  },
  {
    // Den dosažení důchodového věku: od něj nese činnost kód D v ELDP i JMHZ.
    // Jediný záznam po dnech; bez něj se kód D nevytvoří.
    key: 'social_pension_age',
    kind: 'interval',
    summaryKey: 'basis',
    optional: true,
    dayPrecision: true,
    unfrozen: true,
    single: true,
    emptyKey: 'current_pension_age_unknown',
    fields: [
      {
        key: 'basis',
        kind: 'enum',
        options: ['statutory_table', 'cssz_information', 'employee_declaration'],
      },
      { key: 'evidence_reference', kind: 'evidence' },
    ],
  },
  {
    // Pobíraný důchod podle číselníku CIS Druh důchodu (REGZEC 10113). Každý
    // druh je vlastní řada; předčasný může být jen starobní důchod.
    key: 'social_pensions',
    kind: 'interval',
    summaryKey: 'pension_type_code',
    scopeKey: 'pension_type_code',
    optional: true,
    dayPrecision: true,
    unfrozen: true,
    emptyKey: 'current_no_pension',
    fields: [
      { key: 'pension_type_code', kind: 'enum', options: ['1', '2', '8', 'A', 'B', 'C'] },
      {
        key: 'early_retirement',
        kind: 'enum',
        options: ['0', '1'],
        visible: row => text(row, 'pension_type_code') === '1',
        whenHidden: () => '0',
      },
      { key: 'reduced_retirement_age', kind: 'enum', options: ['0', '1'] },
      { key: 'evidence_reference', kind: 'evidence' },
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
  'tax_credit_claims.evidence_reference': [
    'credit:38k-taxpayer-claim',
    'credit:disability-pension-decision',
    'credit:ztp-p-card',
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
  'health_minimum_reductions.evidence_reference': [
    'minimum:state-insured-confirmation',
    'minimum:pension-award-decision',
    'minimum:ztp-card',
    'minimum:pension-age-declaration',
    'minimum:osvc-advance-confirmation',
    'minimum:foster-reward-decision',
    'minimum:sickness-certificate',
    'minimum:child-under-7-confirmation',
  ],
  'health_other_employer_bases.evidence_reference': [
    'minimum:other-employer-confirmation',
  ],
  'social_pension_age.evidence_reference': [
    'pension-age:statutory-table',
    'pension-age:cssz-information',
    'pension-age:employee-declaration',
  ],
  'social_pensions.evidence_reference': ['pension:award-decision'],
}

/** Které doklady dávají smysl u kterého důvodu výjimky z minima. */
const MINIMUM_REDUCTION_REASONS: Readonly<Record<string, readonly string[]>> = {
  state_insured: ['minimum:state-insured-confirmation', 'minimum:pension-award-decision'],
  ztp_or_ztp_p: ['minimum:ztp-card'],
  pension_age_without_pension: ['minimum:pension-age-declaration'],
  osvc_minimum_advance: ['minimum:osvc-advance-confirmation'],
  foster_reward_only: ['minimum:foster-reward-decision'],
  sickness_care_or_quarantine: ['minimum:sickness-certificate'],
  child_under_7_care: ['minimum:child-under-7-confirmation'],
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
  if (section === 'tax_credit_claims' && field === 'evidence_reference') {
    const kind = text(row, 'credit_kind')
    const expected = kind === 'taxpayer'
      ? 'credit:38k-taxpayer-claim'
      : kind === 'ztp-p'
        ? 'credit:ztp-p-card'
        : 'credit:disability-pension-decision'
    return all.filter(reason => reason === expected)
  }
  if (section === 'tax_residences' && field === 'evidence_reference') {
    const prefix = text(row, 'residence') === 'non-resident'
      ? 'residence:foreign-'
      : 'residence:cz-'
    return all.filter(reason => reason.startsWith(prefix))
  }
  if (section === 'health_minimum_reductions' && field === 'evidence_reference') {
    const allowed = MINIMUM_REDUCTION_REASONS[text(row, 'reason')] ?? []
    return all.filter(reason => allowed.includes(reason))
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
 * Doklad je NEPOVINNÝ, a přesto zabíral většinu formuláře — odkaz na podklad,
 * ID dokumentu a poznámka jsou tři pětiny každého řádku. Panel je proto sbaluje
 * pod „Doplnit podklad"; tenhle predikát říká, co tam patří, ať se rozdělení
 * neopisuje v šabloně u každé sekce zvlášť.
 */
export function isEvidenceDetailField(field: StatutoryFieldSpec): boolean {
  return field.kind === 'evidence' || field.kind === 'document'
}

/** Věcná pole řádku — všechno, co není doklad. */
export function primaryFields(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
): StatutoryFieldSpec[] {
  return visibleFields(section, row).filter(field => !isEvidenceDetailField(field))
}

/** Pole dokladu; prázdné pole znamená, že sekce nemá co sbalovat. */
export function evidenceDetailFields(
  section: StatutorySectionSpec,
  row: PayrollStatutoryEvidenceRow,
): StatutoryFieldSpec[] {
  return visibleFields(section, row).filter(isEvidenceDetailField)
}

/**
 * Řádek, který k danému dni PLATÍ.
 *
 * Přehled nahoře musí odpovědět „co teď platí a od kdy" — a to je přesně ten
 * řádek, který k témuž dni čte serverový snímek: u intervalů poslední začátek
 * do daného dne s koncem po něm, u měsíční evidence záznam za týž měsíc.
 */
export function currentRow(
  section: StatutorySectionSpec,
  rows: readonly PayrollStatutoryEvidenceRow[],
  effectiveOn: string,
): PayrollStatutoryEvidenceRow | null {
  if (section.kind === 'month') {
    const month = effectiveOn.slice(0, 7)
    return rows.find(row => text(row, 'period_start').slice(0, 7) === month) ?? null
  }
  let found: PayrollStatutoryEvidenceRow | null = null
  for (const row of rows) {
    const from = text(row, 'effective_from')
    const to = text(row, 'effective_to')
    if (from === '' || from > effectiveOn) continue
    if (to !== '' && to < effectiveOn) continue
    if (found === null || from >= text(found, 'effective_from')) found = row
  }
  return found
}

/**
 * Řádky, které k danému dni PLATÍ.
 *
 * U sekce se souběžnými řadami je jich víc — jedna sleva od každého druhu —
 * takže „co teď platí" nesmí být jeden řádek. Jinde je to nejvýš jeden.
 */
export function currentRows(
  section: StatutorySectionSpec,
  rows: readonly PayrollStatutoryEvidenceRow[],
  effectiveOn: string,
): PayrollStatutoryEvidenceRow[] {
  const scopeKey = section.scopeKey
  if (scopeKey === undefined) {
    const row = currentRow(section, rows, effectiveOn)
    return row === null ? [] : [row]
  }
  const scopes = [...new Set(rows.map(row => text(row, scopeKey)))].sort()
  const found: PayrollStatutoryEvidenceRow[] = []
  for (const scope of scopes) {
    const row = currentRow(
      section,
      rows.filter(item => text(item, scopeKey) === scope),
      effectiveOn,
    )
    if (row !== null) found.push(row)
  }
  return found
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
  if (section.single === true) row.effective_to = null

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
  // Řádek slevy VZNIKÁ tím, že ho účetní založí — sám o sobě je uplatněním
  // nároku, takže „neuplatňuje se" tu není co zvolit. Výchozí `verified` je
  // proto poctivější než `unverified`: nedoložená sleva shodí celý zákonný
  // výpočet do ručního posouzení, takže by založený řádek beztak nic nedělal.
  tax_credit_claims: {
    credit_kind: 'taxpayer',
    evidence_status: 'verified',
    evidence_reference: null,
  },
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
    health_evidence_document_id: null,
  },
  health_month_evidence: {
    top_up_responsibility: 'employee',
    top_up_responsibility_evidence_reference: null,
    selected_top_up_employer_reference: null,
    selected_top_up_employer_evidence_reference: null,
  },
  // Nejčastější výjimka: státní pojištěnec (poživatel důchodu, student,
  // rodičovská…). Uživatel důvod jen přepne, když jde o jiný.
  health_minimum_reductions: { reason: 'state_insured', evidence_reference: null },
  health_other_employer_bases: {
    employer_reference: null,
    assessment_base_minor_units: null,
    employment_from: null,
    employment_to: null,
    evidence_reference: null,
  },
  social_pension_age: { basis: 'cssz_information', evidence_reference: null },
  social_pensions: {
    pension_type_code: '1',
    early_retirement: '0',
    reduced_retirement_age: '0',
    evidence_reference: null,
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
  if (section.key === 'health_other_employer_bases') {
    row.employment_from = monthStart
  }
  // Den dosažení věku není začátek měsíce: buď ho server jednoznačně spočítal
  // z data narození (příloha zákona), nebo ho účetní zapíše sama.
  if (section.key === 'social_pension_age') {
    const suggested = context.pensionAgeSuggestion ?? null
    if (suggested === null) {
      delete row.effective_from
    } else {
      row.effective_from = suggested
      row.basis = 'statutory_table'
      row.evidence_reference = 'pension-age:statutory-table'
    }
  }
  normalizeRow(section, row)
  return row
}

/** Haléře → text pro pole v korunách („15000", „15000.5"). */
export function minorUnitsToCrowns(value: string): string {
  if (!/^\d+$/.test(value)) return ''
  const minor = Number(value)
  return Number.isInteger(minor / 100) ? String(minor / 100) : (minor / 100).toFixed(2)
}

/**
 * Koruny z pole → haléře, jak je chce server (nezáporné celé číslo). Čárka
 * i tečka jako desetinný oddělovač; mezery (oddělovač tisíců) se ignorují.
 * Neplatný vstup vrací `null`, formulář pak nahlásí chybu, ne tichou nulu.
 */
export function crownsToMinorUnits(input: string): string | null {
  const normalized = input.replace(/\s/g, '').replace(',', '.')
  if (normalized === '') return null
  if (!/^\d+(\.\d{1,2})?$/.test(normalized)) return null
  const [whole = '0', fraction = ''] = normalized.split('.')
  return String(Number(whole) * 100 + Number(fraction.padEnd(2, '0')))
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
  const monthAligned = section.dayPrecision !== true
  if (from === '') {
    issues.push({ key: 'period_required' })
  } else if (monthAligned && !from.endsWith('-01')) {
    issues.push({ key: 'period_month_start', params: { day: `${from.slice(0, 7)}-01` } })
  }
  if (section.kind === 'interval') {
    const to = text(row, 'effective_to')
    if (to !== '' && from !== '' && to < from) {
      issues.push({ key: 'effective_to_before_from' })
    } else if (monthAligned && to !== '' && to !== monthEndOf(to)) {
      issues.push({ key: 'effective_to_month_end', params: { day: monthEndOf(to) } })
    }
  }
  if (section.key === 'health_other_employer_bases') {
    const employmentFrom = text(row, 'employment_from')
    const employmentTo = text(row, 'employment_to')
    if (employmentFrom === '') {
      issues.push({ key: 'employment_from_required' })
    } else if (employmentTo !== '' && employmentTo < employmentFrom) {
      issues.push({ key: 'employment_to_before_from' })
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
    if (field.kind === 'reference') {
      if (value === '') issues.push({ key: 'reference_required', params: { label } })
      else if (!CANONICAL_REFERENCE.test(value)) {
        issues.push({ key: 'reference_invalid', params: { label } })
      }
    }
    if (field.kind === 'money') {
      if (value === '') issues.push({ key: 'amount_required', params: { label } })
      else if (!/^\d+$/.test(value)) issues.push({ key: 'amount_invalid', params: { label } })
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

/**
 * Chyby, které dávají smysl až nad celou řadou.
 *
 * U sekce se souběžnými řadami (slevy po druzích) se počítá otevřený záznam
 * V RÁMCI DRUHU: sleva na poplatníka a na ZTP/P běží současně a otevřené jsou
 * obě právem. Bez toho by běžný souběh nešlo uložit.
 */
export function sectionIssues(
  section: StatutorySectionSpec,
  rows: readonly PayrollStatutoryEvidenceRow[],
): StatutoryIssue[] {
  if (section.kind !== 'interval') return []
  const openPerScope = new Map<string, number>()
  for (const row of rows) {
    if (text(row, 'effective_to') !== '') continue
    const scope = section.scopeKey === undefined ? '' : text(row, section.scopeKey)
    openPerScope.set(scope, (openPerScope.get(scope) ?? 0) + 1)
  }
  for (const open of openPerScope.values()) {
    if (open > 1) return [{ key: 'multiple_open_rows' }]
  }
  return []
}

export { text as statutoryText }
