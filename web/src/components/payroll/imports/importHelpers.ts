import { parseLocaleNumber } from '@/utils/localeNumber'
import type {
  AttendanceComponentCheck,
  AttendanceEmploymentOption,
  AttendanceLink,
  AttendanceMeaning,
  AttendancePerson,
  AttendancePersonCreate,
  AttendancePreview,
  AttendanceProfile,
  AttendanceProfileComponent,
  AttendanceProfileComponentKind,
  AttendanceProfileExport,
  AttendanceRule,
  AttendanceSheet,
  AttendanceSourceChecks,
  AttendanceUnit,
  AttendanceUnrecognizedColumn,
  ImportFilePayload,
  RegistrationEmploymentOption,
  RegistrationOpeningBalance,
  RegistrationPair,
  RegistrationReceivedOn,
  RegistrationRecord,
  RegistrationRelationChoice,
  RegistrationRelationType,
} from '@/api/payrollImports'

// Limity zrcadlí serverový `ImportFiles` — klient je hlídá dřív, aby uživatel
// nečekal na odeslání 40 MB jen proto, aby se dozvěděl, že je to moc.
export const IMPORT_MAX_FILES = 20
export const IMPORT_MAX_FILE_BYTES = 5_000_000
export const IMPORT_MAX_TOTAL_BYTES = 15_000_000
// XML registrací a měsíčních hlášení JMHZ: balík má až 1500 formulářů (desítky MB
// u některých programů), proto vyšší limit než u ostatních importů. Celek se vejde
// do těla požadavku (base64 = +33 %, výchozí strop IIS 30 MB).
export const IMPORT_XML_MAX_FILE_BYTES = 20_000_000
export const IMPORT_XML_MAX_TOTAL_BYTES = 20_000_000

export type ImportFileRejectReason =
  | 'unsupported_file'
  | 'file_too_large'
  | 'too_many_files'
  | 'total_too_large'
  | 'duplicate_file'

export interface ImportFileRejection {
  name: string
  reason: ImportFileRejectReason
}

export interface ImportFileLimits {
  maxFiles: number
  maxFileBytes: number
  maxTotalBytes: number
}

export const IMPORT_XML_LIMITS: ImportFileLimits = {
  maxFiles: IMPORT_MAX_FILES,
  maxFileBytes: IMPORT_XML_MAX_FILE_BYTES,
  maxTotalBytes: IMPORT_XML_MAX_TOTAL_BYTES,
}

const DEFAULT_LIMITS: ImportFileLimits = {
  maxFiles: IMPORT_MAX_FILES,
  maxFileBytes: IMPORT_MAX_FILE_BYTES,
  maxTotalBytes: IMPORT_MAX_TOTAL_BYTES,
}

type FileLike = Pick<File, 'name' | 'size'>

export function fileExtension(name: string): string {
  const dot = name.lastIndexOf('.')
  return dot >= 0 ? name.slice(dot + 1).toLowerCase() : ''
}

/**
 * Přidá nové soubory k už vybraným a vrátí, co se odmítlo a proč.
 *
 * Tentýž soubor přetažený podruhé (stejný název i velikost) se nepřidá — jinak
 * by server dostal dvakrát stejná data a docházka by vypadala jako konflikt.
 */
export function mergeImportFiles<T extends FileLike>(
  existing: T[],
  incoming: T[],
  allowedExtensions: string[],
  limits: ImportFileLimits = DEFAULT_LIMITS,
): { files: T[]; rejected: ImportFileRejection[] } {
  const allowed = allowedExtensions.map(item => item.toLowerCase())
  const files = [...existing]
  const rejected: ImportFileRejection[] = []
  let total = files.reduce((sum, file) => sum + file.size, 0)

  for (const file of incoming) {
    if (!allowed.includes(fileExtension(file.name))) {
      rejected.push({ name: file.name, reason: 'unsupported_file' })
      continue
    }
    if (file.size > limits.maxFileBytes) {
      rejected.push({ name: file.name, reason: 'file_too_large' })
      continue
    }
    if (files.some(item => item.name === file.name && item.size === file.size)) {
      rejected.push({ name: file.name, reason: 'duplicate_file' })
      continue
    }
    if (files.length >= limits.maxFiles) {
      rejected.push({ name: file.name, reason: 'too_many_files' })
      continue
    }
    if (total + file.size > limits.maxTotalBytes) {
      rejected.push({ name: file.name, reason: 'total_too_large' })
      continue
    }
    files.push(file)
    total += file.size
  }

  return { files, rejected }
}

/** `data:…;base64,XXXX` → `XXXX`; vstup bez prefixu se vrací beze změny. */
export function dataUrlToBase64(dataUrl: string): string {
  const separator = dataUrl.indexOf(',')
  return separator >= 0 ? dataUrl.slice(separator + 1) : dataUrl
}

export function readFileAsBase64(file: Blob): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onerror = () => reject(reader.error ?? new Error('file_read_failed'))
    reader.onload = () => resolve(dataUrlToBase64(String(reader.result ?? '')))
    reader.readAsDataURL(file)
  })
}

export async function filesToPayload(files: File[]): Promise<ImportFilePayload[]> {
  return await Promise.all(files.map(async file => ({
    name: file.name,
    content_base64: await readFileAsBase64(file),
  })))
}

export function formatBytes(bytes: number, locale?: string): string {
  const units = ['B', 'kB', 'MB', 'GB']
  let amount = bytes
  let unit = 0
  while (amount >= 1024 && unit < units.length - 1) {
    amount /= 1024
    unit += 1
  }
  return `${amount.toLocaleString(locale, { maximumFractionDigits: unit === 0 ? 0 : 1 })} ${units[unit]}`
}

/** Otisk výběru souborů — když se změní, starý náhled už neplatí. */
export function filesFingerprint(files: FileLike[]): string {
  return files.map(file => `${file.name}:${file.size}`).join('|')
}

// ─── Registrace ───────────────────────────────────────────────────────────────

/**
 * Formulář hlášení bez přiřazeného vztahu server při zápisu přeskočí, proto
 * ho nejde vybrat — vybere se, až mu uživatel vztah přiřadí a náhled se přepočítá.
 */
export function isRegistrationApplicable(record: RegistrationRecord): boolean {
  return record.selectable && record.operation !== 'pair_required'
}

export function selectableRegistrationKeys(records: RegistrationRecord[]): string[] {
  return records.filter(isRegistrationApplicable).map(record => record.key)
}

/** Z výběru vyhodí klíče, které po novém náhledu už nejsou použitelné. */
export function pruneRegistrationSelection(selected: string[], records: RegistrationRecord[]): string[] {
  const allowed = new Set(selectableRegistrationKeys(records))
  return selected.filter(key => allowed.has(key))
}

export type RegistrationPairMap = Record<string, number>

export function buildRegistrationPairs(pairs: RegistrationPairMap): RegistrationPair[] {
  return Object.entries(pairs)
    .filter(([, employmentId]) => Number.isInteger(employmentId) && employmentId > 0)
    .map(([key, employmentId]) => ({ key, employment_id: employmentId }))
}

/** Nastaví nebo zruší (`null`) ruční přiřazení formuláře ke vztahu. */
export function setRegistrationPair(pairs: RegistrationPairMap, key: string, employmentId: number | null): RegistrationPairMap {
  const next = { ...pairs }
  if (employmentId === null || !Number.isInteger(employmentId) || employmentId <= 0) delete next[key]
  else next[key] = employmentId
  return next
}

/** Po novém náhledu zůstanou jen přiřazení formulářů, které v dávce pořád jsou, ke vztahům, které pořád existují. */
export function pruneRegistrationPairs(
  pairs: RegistrationPairMap,
  records: RegistrationRecord[],
  options: RegistrationEmploymentOption[],
): RegistrationPairMap {
  const keys = new Set(records.filter(record => record.document_type === 'JMHZ').map(record => record.key))
  const optionIds = new Set(options.map(option => option.employment_id))
  const next: RegistrationPairMap = {}
  for (const [key, employmentId] of Object.entries(pairs)) {
    if (keys.has(key) && optionIds.has(employmentId)) next[key] = employmentId
  }
  return next
}

/**
 * Výběr vztahu se ukazuje u formuláře, který na přiřazení čeká, u ručně
 * přiřazeného (aby šel změnit) i u přiřazení, které server odmítl (blocker).
 */
export function registrationNeedsPairSelect(record: RegistrationRecord, pairs: RegistrationPairMap): boolean {
  if (record.document_type !== 'JMHZ') return false
  return record.operation === 'pair_required'
    || record.match.matched_by === 'manual'
    || Object.prototype.hasOwnProperty.call(pairs, record.key)
}

export function hasReadyItem(items: { status: string }[]): boolean {
  return items.some(item => item.status === 'ready')
}

/** Převzatý měsíc jde převzít i „částečně" — převezme se, co je spárované. */
export function hasTakeoverItem(months: { status: string }[]): boolean {
  return months.some(month => month.status === 'ready' || month.status === 'partial')
}

// ─── Registrace: volba druhu vztahu ───────────────────────────────────────────

export type RegistrationRelationChoiceMap = Record<string, RegistrationRelationType>

/** Věta nabízí volbu, jen když podklady druh vztahu nerozliší (DPP / DPČ malého rozsahu). */
export function recordNeedsRelationChoice(record: RegistrationRecord): boolean {
  return (record.employment.relation_type_options ?? []).length > 1
}

export function setRelationChoice(
  choices: RegistrationRelationChoiceMap,
  record: RegistrationRecord,
  relationType: string,
): RegistrationRelationChoiceMap {
  const next = { ...choices }
  const options = record.employment.relation_type_options ?? []
  if (options.includes(relationType as RegistrationRelationType) && relationType !== options[0]) {
    next[record.key] = relationType as RegistrationRelationType
  } else {
    delete next[record.key]
  }
  return next
}

export function buildRelationChoices(choices: RegistrationRelationChoiceMap): RegistrationRelationChoice[] {
  return Object.entries(choices).map(([key, relationType]) => ({ key, relation_type: relationType }))
}

/** Po novém náhledu zůstanou jen volby u vět, které je pořád nabízejí. */
export function pruneRelationChoices(
  choices: RegistrationRelationChoiceMap,
  records: RegistrationRecord[],
): RegistrationRelationChoiceMap {
  const byKey = new Map(records.map(record => [record.key, record]))
  const next: RegistrationRelationChoiceMap = {}
  for (const [key, relationType] of Object.entries(choices)) {
    if ((byKey.get(key)?.employment.relation_type_options ?? []).includes(relationType)) next[key] = relationType
  }
  return next
}

// ─── Registrace: podání dávek a záměrů slevy předchozího programu ─────────────

export type RegistrationReceivedOnMap = Record<string, string>

/** Podání NEMPRI, HZUPN nebo OZUSPOJ předchozího programu (nese `benefit`). */
export function isBenefitRecord(record: RegistrationRecord): boolean {
  return record.benefit !== undefined && record.benefit !== null
}

/**
 * Den doručení z protokolu ČSSZ se nabízí u podání předchozího programu, které jde
 * zapsat; u OZUSPOJ je povinný, u NEMPRI a HZUPN jen volitelný.
 */
export function recordAcceptsReceivedOn(record: RegistrationRecord): boolean {
  if (!record.benefit) return false
  return record.benefit.needs_received_on
    || record.operation === 'create_case'
    || record.operation === 'update_case'
    || record.operation === 'import_intent'
}

/** Nastaví nebo zruší (prázdná hodnota) den doručení věty. */
export function setReceivedOn(map: RegistrationReceivedOnMap, key: string, value: string): RegistrationReceivedOnMap {
  const next = { ...map }
  if (value === '') delete next[key]
  else next[key] = value
  return next
}

export function buildReceivedOn(map: RegistrationReceivedOnMap): RegistrationReceivedOn[] {
  return Object.entries(map)
    .filter(([, value]) => /^\d{4}-\d{2}-\d{2}$/.test(value))
    .map(([key, received_on]) => ({ key, received_on }))
}

/** Po novém náhledu zůstanou jen dny doručení u vět, které podání předchozího programu pořád jsou. */
export function pruneReceivedOn(map: RegistrationReceivedOnMap, records: RegistrationRecord[]): RegistrationReceivedOnMap {
  const keys = new Set(records.filter(isBenefitRecord).map(record => record.key))
  return Object.fromEntries(Object.entries(map).filter(([key]) => keys.has(key)))
}

/**
 * Výchozí stav přepínače převzetí historie: dokud na něj uživatel nesáhl,
 * je zapnutý přesně tehdy, když je co převzít; vědomé vypnutí se drží.
 * Bez připravené položky je vypnutý vždy.
 */
export function resolveHistoryToggle(current: boolean, touched: boolean, hasReady: boolean): boolean {
  if (!hasReady) return false
  return touched ? current : true
}

export type RegistrationApplyBlock = 'no_preview' | 'no_selection' | 'no_confirmation' | null

export function registrationApplyBlock(state: {
  hasPreview: boolean
  selectedCount: number
  historySelected: boolean
  confirmed: boolean
}): RegistrationApplyBlock {
  if (!state.hasPreview) return 'no_preview'
  if (state.selectedCount === 0 && !state.historySelected) return 'no_selection'
  if (!state.confirmed) return 'no_confirmation'
  return null
}

export interface OpeningBalanceTotals {
  months: number
  advance_base_minor: number
  advance_tax_minor: number
  withholding_base_minor: number
  withholding_tax_minor: number
}

export function openingBalanceTotals(balance: Pick<RegistrationOpeningBalance, 'months'>): OpeningBalanceTotals {
  return balance.months.reduce<OpeningBalanceTotals>((sum, month) => ({
    months: sum.months + 1,
    advance_base_minor: sum.advance_base_minor + month.advance_base_minor_units,
    advance_tax_minor: sum.advance_tax_minor + month.advance_tax_minor_units,
    withholding_base_minor: sum.withholding_base_minor + month.withholding_base_minor_units,
    withholding_tax_minor: sum.withholding_tax_minor + month.withholding_tax_minor_units,
  }), { months: 0, advance_base_minor: 0, advance_tax_minor: 0, withholding_base_minor: 0, withholding_tax_minor: 0 })
}

/** Odpracované minuty → hodiny jako desetinný řetězec pro `formatHours`. */
export function minutesToHours(minutes: number): string {
  return (minutes / 60).toFixed(2)
}

// ─── Docházka: číselníky mapování ─────────────────────────────────────────────

export const ATTENDANCE_MEANING_GROUPS: { key: string; meanings: AttendanceMeaning[] }[] = [
  { key: 'other', meanings: ['ignore'] },
  {
    key: 'identity',
    meanings: [
      'person_name', 'personal_number', 'birth_number', 'birth_date', 'health_insurer_code',
      'relation_label', 'department', 'cost_center', 'position', 'weekly_hours', 'start_end_note',
      'monthly_wage',
    ],
  },
  {
    key: 'work',
    meanings: [
      'worked_hours', 'overtime_hours', 'night_hours', 'weekend_hours', 'holiday_work_hours',
      'afternoon_hours', 'fund_hours',
    ],
  },
  {
    key: 'absence',
    meanings: [
      'vacation_hours', 'holiday_hours', 'sick_hours', 'doctor_hours', 'care_hours',
      'paternity_hours', 'unpaid_leave_hours', 'unexcused_hours', 'obstacle_employee_hours',
      'obstacle_employer_hours', 'business_trip_hours', 'home_office_hours',
      'compensatory_time_off_hours',
    ],
  },
  {
    key: 'money',
    meanings: ['component', 'net_meal_deduction', 'net_other_deduction', 'reference_gross', 'reference_net', 'reference_hours'],
  },
]

export const ATTENDANCE_MEANINGS: AttendanceMeaning[] = ATTENDANCE_MEANING_GROUPS.flatMap(group => group.meanings)
export const ATTENDANCE_UNITS: AttendanceUnit[] = ['hours', 'excel_duration', 'amount', 'text']
export const PROFILE_COMPONENT_KINDS: AttendanceProfileComponentKind[] = [
  'hourly_wage', 'task_wage', 'bonus', 'premium', 'compensation', 'allowance', 'other',
]

/** Kód složky v pravidle, který znamená „odvodit z hlavičky sloupce". */
export const AUTO_COMPONENT_CODE = '*'

const TEXT_MEANINGS = new Set<AttendanceMeaning>([
  'person_name', 'personal_number', 'birth_number', 'birth_date', 'health_insurer_code', 'relation_label',
  'department', 'cost_center', 'position', 'start_end_note', 'monthly_wage',
])
const MONEY_MEANINGS = new Set<AttendanceMeaning>([
  'component', 'net_meal_deduction', 'net_other_deduction', 'reference_gross', 'reference_net',
])

/**
 * Jednotka, která k významu dává smysl. U hodinových významů zůstává
 * `excel_duration` i „hodiny", pokud je uživatel zvolil; jinak `null`
 * = server ji pozná z formátu buňky. Pravidlo profilu platí pro další
 * měsíce, takže pevné „hodiny" by trvání 1,5 dne četlo jako 1,5 hodiny.
 */
export function ruleUnitForMeaning(meaning: AttendanceMeaning, current: AttendanceUnit | null): AttendanceUnit | null {
  if (meaning === 'ignore') return null
  if (TEXT_MEANINGS.has(meaning)) return 'text'
  if (MONEY_MEANINGS.has(meaning)) return 'amount'
  return current === 'hours' || current === 'excel_duration' ? current : null
}

/** Shodně se serverem: trim, malá písmena, bez diakritiky, sjednocené mezery. */
export function normalizeHeader(value: string): string {
  return value
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/\s+/g, ' ')
    .trim()
}

/** `' odmena '` → `'ODMENA'`, `'*'` zůstává, prázdná hodnota = `null`. */
export function normalizeRuleComponentCode(value: string | null | undefined): string | null {
  const code = (value ?? '').trim()
  if (code === '') return null
  return code === AUTO_COMPONENT_CODE ? code : code.toUpperCase()
}

// ─── Docházka: editor profilu ─────────────────────────────────────────────────

export interface RuleDraft {
  /** Jen pro `v-for` klíč — pořadí řádků se mění šipkami. */
  uid: string
  sheet: string
  header: string
  meaning: AttendanceMeaning
  unit: AttendanceUnit | null
  component_code: string
  /** Podmínka pravidla: sloupec a hodnota; obojí prázdné = bez podmínky. */
  when_header: string
  when_value: string
  /** Sazba náhrady u překážky na straně zaměstnavatele; prázdné = výchozí. */
  rate_percent: string
}

/** Význam, u kterého pravidlo nese sazbu náhrady mzdy, a sazba bez jejího zadání. */
export const OBSTACLE_RATE_MEANING: AttendanceMeaning = 'obstacle_employer_hours'
export const DEFAULT_OBSTACLE_RATE = 80

export interface ComponentDraft {
  uid: string
  code: string
  name: string
  kind: AttendanceProfileComponentKind
}

export interface ProfileDraft {
  id: number | null
  name: string
  is_sample: boolean
  rules: RuleDraft[]
  components: ComponentDraft[]
}

let draftSequence = 0
function nextUid(prefix: string): string {
  draftSequence += 1
  return `${prefix}-${draftSequence}`
}

export function ruleToDraft(rule: AttendanceRule): RuleDraft {
  return {
    uid: nextUid('rule'),
    sheet: rule.sheet ?? '',
    header: rule.header,
    meaning: rule.meaning,
    unit: rule.unit,
    component_code: rule.component_code ?? '',
    when_header: rule.when_header ?? '',
    when_value: rule.when_value ?? '',
    rate_percent: rule.rate_percent === undefined ? '' : String(rule.rate_percent),
  }
}

export function emptyRuleDraft(overrides: Partial<Omit<RuleDraft, 'uid'>> = {}): RuleDraft {
  return {
    uid: nextUid('rule'),
    sheet: '',
    header: '',
    meaning: 'ignore',
    unit: null,
    component_code: '',
    when_header: '',
    when_value: '',
    rate_percent: '',
    ...overrides,
  }
}

export function componentToDraft(component: AttendanceProfileComponent): ComponentDraft {
  return { uid: nextUid('component'), code: component.code, name: component.name, kind: component.kind }
}

export function emptyComponentDraft(): ComponentDraft {
  return { uid: nextUid('component'), code: '', name: '', kind: 'bonus' }
}

export function profileToDraft(profile: AttendanceProfile): ProfileDraft {
  return {
    id: profile.id,
    name: profile.name,
    is_sample: profile.is_sample,
    rules: profile.rules.map(ruleToDraft),
    components: (profile.components ?? []).map(componentToDraft),
  }
}

export function emptyProfileDraft(
  name = '',
  rules: AttendanceRule[] = [],
  components: AttendanceProfileComponent[] = [],
): ProfileDraft {
  return { id: null, name, is_sample: false, rules: rules.map(ruleToDraft), components: components.map(componentToDraft) }
}

/** Kopie profilu k uložení pod novým názvem; vzor se kopií stává vlastním profilem. */
export function duplicateDraft(draft: ProfileDraft, name: string): ProfileDraft {
  return {
    id: null,
    name,
    is_sample: false,
    rules: draft.rules.map(rule => ({ ...rule, uid: nextUid('rule') })),
    components: draft.components.map(component => ({ ...component, uid: nextUid('component') })),
  }
}

/**
 * Pravidla k odeslání. Řádek bez hlavičky se neposílá — nic by nenašel a
 * validace ho před uložením stejně zastaví; při zkoušce na souborech by jen
 * zbytečně shodil celý požadavek.
 */
export function draftRules(draft: ProfileDraft): AttendanceRule[] {
  return draft.rules
    .filter(rule => rule.header.trim() !== '')
    .map(rule => {
      const result: AttendanceRule = {
        sheet: rule.sheet.trim() === '' ? null : rule.sheet.trim(),
        header: rule.header.trim(),
        meaning: rule.meaning,
        unit: rule.meaning === 'ignore' ? null : ruleUnitForMeaning(rule.meaning, rule.unit),
        component_code: rule.meaning === 'component' ? normalizeRuleComponentCode(rule.component_code) : null,
      }
      // Podmínka jen u pravidla, které ji má — neúplnou zastaví ruleIssues i server.
      const whenHeader = rule.when_header.trim()
      const whenValue = rule.when_value.trim()
      if (whenHeader !== '' || whenValue !== '') {
        result.when_header = whenHeader
        result.when_value = whenValue
      }
      const rate = rule.rate_percent.trim()
      if (rule.meaning === OBSTACLE_RATE_MEANING && rate !== '') result.rate_percent = Number(rate)
      return result
    })
}

export function draftComponents(draft: ProfileDraft): AttendanceProfileComponent[] {
  return draft.components
    .filter(component => component.code.trim() !== '' || component.name.trim() !== '')
    .map(component => ({
      code: component.code.trim().toUpperCase(),
      name: component.name.trim(),
      kind: component.kind,
    }))
}

/** Otisk obsahu profilu — shoda s otiskem uložené verze = žádné neuložené změny. */
export function draftSignature(draft: ProfileDraft): string {
  return JSON.stringify({ name: draft.name.trim(), rules: draftRules(draft), components: draftComponents(draft) })
}

export type ProfileDraftIssue =
  | { kind: 'name_missing' }
  | { kind: 'name_taken' }
  | { kind: 'no_rules' }
  | { kind: 'rule_header_missing'; row: number }
  | { kind: 'rule_component_missing'; row: number }
  | { kind: 'rule_condition_incomplete'; row: number }
  | { kind: 'rule_condition_auto'; row: number }
  | { kind: 'rule_rate_invalid'; row: number }
  | { kind: 'rule_rate_mixed' }
  | { kind: 'component_code_missing'; row: number }
  | { kind: 'component_code_duplicate'; row: number; code: string }
  | { kind: 'component_code_auto'; row: number }
  | { kind: 'component_name_missing'; row: number }

/** Chyby, které brání uložení; `row` je pořadí řádku od 1, jak ho vidí uživatel. */
export function profileDraftIssues(draft: ProfileDraft, otherNames: string[] = []): ProfileDraftIssue[] {
  const issues: ProfileDraftIssue[] = []
  const name = draft.name.trim()
  if (name === '') issues.push({ kind: 'name_missing' })
  else if (otherNames.some(other => other.trim().toLocaleLowerCase() === name.toLocaleLowerCase())) {
    issues.push({ kind: 'name_taken' })
  }

  issues.push(...ruleIssues(draft))

  const seen = new Set<string>()
  draft.components.forEach((component, index) => {
    const row = index + 1
    const code = component.code.trim().toUpperCase()
    if (code === '' && component.name.trim() === '') return
    if (code === '') issues.push({ kind: 'component_code_missing', row })
    else if (code === AUTO_COMPONENT_CODE) issues.push({ kind: 'component_code_auto', row })
    else if (seen.has(code)) issues.push({ kind: 'component_code_duplicate', row, code })
    if (code !== '') seen.add(code)
    if (component.name.trim() === '') issues.push({ kind: 'component_name_missing', row })
  })

  return issues
}

/** Jen chyby pravidel — ty brání i zkoušce na souborech, název profilu ne. */
export function ruleIssues(draft: ProfileDraft): ProfileDraftIssue[] {
  const issues: ProfileDraftIssue[] = []
  if (draft.rules.length === 0) issues.push({ kind: 'no_rules' })
  draft.rules.forEach((rule, index) => {
    const row = index + 1
    if (rule.header.trim() === '') issues.push({ kind: 'rule_header_missing', row })
    // Složka bez kódu by vytvořila vstup „do ztracena".
    if (rule.meaning === 'component' && normalizeRuleComponentCode(rule.component_code) === null) {
      issues.push({ kind: 'rule_component_missing', row })
    }
    const hasHeader = rule.when_header.trim() !== ''
    const hasValue = rule.when_value.trim() !== ''
    if (hasHeader !== hasValue) issues.push({ kind: 'rule_condition_incomplete', row })
    if ((hasHeader || hasValue) && rule.meaning === 'component'
      && normalizeRuleComponentCode(rule.component_code) === AUTO_COMPONENT_CODE) {
      issues.push({ kind: 'rule_condition_auto', row })
    }
    const rate = rule.rate_percent.trim()
    if (rule.meaning === OBSTACLE_RATE_MEANING && rate !== ''
      && (!/^\d+$/.test(rate) || Number(rate) < 60 || Number(rate) > 100)) {
      issues.push({ kind: 'rule_rate_invalid', row })
    }
  })
  // Hodnoty stejného významu se nesčítají, platí jedna — sazba proto také jedna.
  const rates = new Set(draft.rules
    .filter(rule => rule.meaning === OBSTACLE_RATE_MEANING)
    .map(rule => rule.rate_percent.trim() === '' ? DEFAULT_OBSTACLE_RATE : Number(rule.rate_percent.trim())))
  if (rates.size > 1) issues.push({ kind: 'rule_rate_mixed' })
  return issues
}

/** Sazba pod 80 % připadá v úvahu jen u § 207 písm. b) a § 209 — editor na to upozorní. */
export function obstacleRateIsReduced(rule: RuleDraft): boolean {
  const rate = Number(rule.rate_percent.trim())
  return rule.meaning === OBSTACLE_RATE_MEANING && rule.rate_percent.trim() !== '' && rate >= 60 && rate < DEFAULT_OBSTACLE_RATE
}

/** Posun položky o `delta`; mimo rozsah vrací kopii beze změny. */
export function moveItem<T>(list: T[], index: number, delta: number): T[] {
  const target = index + delta
  const next = [...list]
  if (index < 0 || index >= list.length || target < 0 || target >= list.length) return next
  const [item] = next.splice(index, 1)
  next.splice(target, 0, item)
  return next
}

/** Název, který ve firmě ještě není; jinak přidá „(2)", „(3)"… */
export function uniqueProfileName(base: string, existing: string[]): string {
  const trimmed = base.trim()
  const taken = new Set(existing.map(name => name.trim().toLocaleLowerCase()))
  if (!taken.has(trimmed.toLocaleLowerCase())) return trimmed
  let counter = 2
  while (taken.has(`${trimmed} (${counter})`.toLocaleLowerCase())) counter += 1
  return `${trimmed} (${counter})`
}

export const PROFILE_EXPORT_FORMAT = 'myucto-attendance-profile'

export type ProfileExportParseResult =
  | { ok: true; profile: AttendanceProfileExport }
  | { ok: false; reason: 'invalid_json' | 'wrong_format' | 'unsupported_version' | 'invalid_content' }

/**
 * Předběžná kontrola souboru před odesláním. Server obsah validuje znovu —
 * tady jde jen o to, aby uživatel u omylem vybraného souboru dostal
 * srozumitelnou hlášku, ne obecnou chybu serveru.
 */
export function parseProfileExport(text: string): ProfileExportParseResult {
  let data: unknown
  try {
    data = JSON.parse(text)
  } catch {
    return { ok: false, reason: 'invalid_json' }
  }
  if (typeof data !== 'object' || data === null || Array.isArray(data)) return { ok: false, reason: 'wrong_format' }
  const record = data as Record<string, unknown>
  if (record.format !== PROFILE_EXPORT_FORMAT) return { ok: false, reason: 'wrong_format' }
  if (record.version !== 1) return { ok: false, reason: 'unsupported_version' }
  if (typeof record.name !== 'string' || !Array.isArray(record.rules)) return { ok: false, reason: 'invalid_content' }
  const components = record.components ?? []
  if (!Array.isArray(components)) return { ok: false, reason: 'invalid_content' }
  return {
    ok: true,
    profile: {
      format: PROFILE_EXPORT_FORMAT,
      version: 1,
      name: record.name,
      rules: record.rules as AttendanceRule[],
      components: components as AttendanceProfileComponent[],
    },
  }
}

/** Název souboru exportu bez diakritiky a mezer — přežije e-mail i sdílený disk. */
export function profileExportFilename(name: string): string {
  const slug = normalizeHeader(name).replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')
  return `profil-dochazky-${slug || 'export'}.json`
}

// ─── Docházka: výsledek rozpoznání ────────────────────────────────────────────

export interface RecognitionStats {
  recognized: number
  sheets: number
  unrecognized: number
}

export function recognitionStats(preview: Pick<AttendancePreview, 'sheets' | 'unrecognized_columns'>): RecognitionStats {
  const used = preview.sheets.filter(sheet => sheet.used)
  return {
    recognized: used.reduce((sum, sheet) => sum + sheet.columns.filter(column => column.meaning !== 'ignore').length, 0),
    sheets: used.length,
    unrecognized: (preview.unrecognized_columns ?? []).length,
  }
}

export function sheetLabel(sheets: AttendanceSheet[], sheetId: string): string {
  const sheet = sheets.find(item => item.id === sheetId)
  return sheet ? `${sheet.sheet} · ${sheet.file}` : sheetId
}

/**
 * Pravidlo pro sloupec, který se nerozpoznal. Význam zůstává „ignorovat",
 * dokud ho uživatel nezvolí — i tak má smysl: sloupec se přestane hlásit.
 */
export function ruleFromUnrecognized(column: AttendanceUnrecognizedColumn, sheets: AttendanceSheet[]): RuleDraft {
  const sheet = sheets.find(item => item.id === column.sheet_id)
  return emptyRuleDraft({ sheet: sheet?.sheet ?? '', header: column.header })
}

export function componentsToCreate(checks: AttendanceComponentCheck[]): AttendanceComponentCheck[] {
  return checks.filter(check => check.status === 'will_create')
}

// ─── Docházka: osoby a vazby ──────────────────────────────────────────────────

export type ManualLinks = Record<string, number | null>

/** Ruční volba přebíjí automatickou shodu; `null` = vědomě bez vztahu. */
export function effectiveEmploymentId(person: AttendancePerson, manual: ManualLinks): number | null {
  return Object.prototype.hasOwnProperty.call(manual, person.key)
    ? manual[person.key] ?? null
    : person.match.employment_id
}

export function buildAttendanceLinks(persons: AttendancePerson[], manual: ManualLinks): AttendanceLink[] {
  const links: AttendanceLink[] = []
  for (const person of persons) {
    const employmentId = effectiveEmploymentId(person, manual)
    if (employmentId !== null) links.push({ person_key: person.key, employment_id: employmentId })
  }
  return links
}

/**
 * Po novém náhledu (třeba po založení osob) zůstanou jen ruční volby, které
 * pořád míří na existující osobu a platný vztah. Ruční „bez vztahu" u osoby,
 * která mezitím dostala automatickou shodu, se zahodí — jinak by nově založená
 * osoba zůstala nespárovaná.
 */
export function pruneManualLinks(
  manual: ManualLinks,
  persons: AttendancePerson[],
  options: AttendanceEmploymentOption[],
): ManualLinks {
  const optionIds = new Set(options.map(option => option.employment_id))
  const byKey = new Map(persons.map(person => [person.key, person]))
  const next: ManualLinks = {}
  for (const [key, value] of Object.entries(manual)) {
    const person = byKey.get(key)
    if (!person) continue
    if (value === null) {
      if (person.match.employment_id === null) next[key] = null
      continue
    }
    if (optionIds.has(value)) next[key] = value
  }
  return next
}

/**
 * Osobu lze rovnou založit, když ji import nenašel a nemá (ani ručně
 * zvolený) přiřazený pracovní vztah. Nejasná osoba má možné shody v evidenci —
 * nová osoba by z ní udělala duplicitu, vybírá se proto vztah. Sdílí ji krok
 * Osoby (checkbox u řádku) i krok Souhrn (nabídka založit chybějící osoby).
 */
export function personCanBeCreated(person: AttendancePerson, manual: ManualLinks): boolean {
  return person.match.status === 'not_found' && effectiveEmploymentId(person, manual) === null
}

export function personHasIdentifier(person: AttendancePerson): boolean {
  return Boolean(person.personal_number?.trim()) || Boolean(person.birth_number_masked)
}

/**
 * Osoby, které import založí sám. Nesou-li podklady osobní nebo rodná čísla
 * (mzdový export), zakládají se jen osoby s nimi: osoba jen ze seznamu bez
 * čísel bývá jinak zapsané jméno někoho z evidence (změna příjmení) a nová
 * karta by byla duplicitou. Takové osoby zůstanou k ruční volbě v kroku Osoby.
 */
export function autoCreatablePersons(persons: AttendancePerson[], manual: ManualLinks): AttendancePerson[] {
  const creatable = persons.filter(person => personCanBeCreated(person, manual))
  return persons.some(personHasIdentifier) ? creatable.filter(personHasIdentifier) : creatable
}

/** Klíče osob bez vztahu, které lze rovnou založit — pro předvýběr v kroku Osoby. */
export function creatablePersonKeys(persons: AttendancePerson[], manual: ManualLinks): string[] {
  return persons.filter(person => personCanBeCreated(person, manual)).map(person => person.key)
}

/** Kolik osob se při použití přeskočí, protože nemají přiřazený pracovní vztah. */
export function personsWithoutEmploymentCount(persons: AttendancePerson[], manual: ManualLinks): number {
  return persons.filter(person => effectiveEmploymentId(person, manual) === null).length
}

export function guessRelationType(label: string | null): RegistrationRelationType {
  const normalized = normalizeHeader(label ?? '')
  if (normalized === '') return 'employment'
  if (/\bdpp\b|provedeni prace/.test(normalized)) return 'dpp'
  if (/\bdpc\b|pracovni cinnosti/.test(normalized)) return 'dpc'
  if (/maleho rozsahu/.test(normalized)) return 'small_scale_employment'
  if (/jednatel|funkc|statutar/.test(normalized)) return 'statutory_body'
  return 'employment'
}

/**
 * Docházkové exporty mají jméno obvykle ve tvaru „Příjmení Jméno". Je to jen
 * návrh do formuláře — uživatel ho vidí a může pořadí prohodit.
 */
export function splitDisplayName(displayName: string): { first_name: string; last_name: string } {
  const words = displayName.trim().split(/\s+/).filter(Boolean)
  if (words.length === 0) return { first_name: '', last_name: '' }
  if (words.length === 1) return { first_name: '', last_name: words[0] }
  return { last_name: words[0], first_name: words.slice(1).join(' ') }
}

export interface PersonDraft {
  first_name: string
  last_name: string
}

export interface PersonCreateDefaults {
  relation_type: RegistrationRelationType
  weekly_hours: string
  planned_start_on: string
  activate: boolean
}

/**
 * `relationTypeFor` volitelně určí druh vztahu za osobu (odhad z podkladů);
 * bez něj se použije jednotný `defaults.relation_type` jako dosud (ruční
 * založení v kroku Osoby, kde druh vybírá uživatel jednou pro celou dávku).
 */
export function buildPersonsPayload(
  persons: AttendancePerson[],
  drafts: Record<string, PersonDraft>,
  defaults: PersonCreateDefaults,
  relationTypeFor?: (person: AttendancePerson) => RegistrationRelationType,
): AttendancePersonCreate[] {
  return persons.map(person => {
    const draft = drafts[person.key] ?? splitDisplayName(person.display_name)
    const firstName = draft.first_name.trim()
    const lastName = draft.last_name.trim()
    const weekly = (person.weekly_hours ?? '').trim() || defaults.weekly_hours.trim()
    const wage = parseLocaleNumber(person.monthly_wage) ?? NaN
    return {
      person_key: person.key,
      full_name: [firstName, lastName].filter(Boolean).join(' ') || person.display_name,
      first_name: firstName,
      last_name: lastName,
      birth_number: null,
      relation_type: relationTypeFor ? relationTypeFor(person) : defaults.relation_type,
      weekly_hours: weekly === '' ? null : weekly.replace(',', '.'),
      monthly_gross: Number.isFinite(wage) && wage > 0 ? Math.round(wage) : null,
      // Nástup z poznámky v podkladech je přesnější než jednotný den pro celou dávku.
      planned_start_on: person.start_on || defaults.planned_start_on,
      personal_number: person.personal_number,
      activate: defaults.activate,
    }
  })
}

// ─── Docházka: souhrn ─────────────────────────────────────────────────────────

export function summaryDeductions(persons: AttendancePerson[]): AttendanceMeaning[] {
  const present = new Set<AttendanceMeaning>()
  for (const person of persons) {
    for (const deduction of person.deductions ?? []) present.add(deduction.meaning)
  }
  return ATTENDANCE_MEANINGS.filter(meaning => present.has(meaning))
}

export function summaryMeanings(persons: AttendancePerson[]): AttendanceMeaning[] {
  const present = new Set<AttendanceMeaning>()
  for (const person of persons) {
    for (const metric of person.metrics) present.add(metric.meaning)
  }
  const ordered = ATTENDANCE_MEANINGS.filter(meaning => present.has(meaning))
  const unknown = [...present].filter(meaning => !ATTENDANCE_MEANINGS.includes(meaning)).sort()
  return [...ordered, ...unknown]
}

export function summaryComponents(persons: AttendancePerson[]): string[] {
  const present = new Set<string>()
  for (const person of persons) {
    for (const component of person.components) present.add(component.component_code)
  }
  return [...present].sort()
}

export function personHasConflicts(person: AttendancePerson): boolean {
  return person.metrics.some(metric => metric.conflicts.length > 0)
    || person.components.some(component => component.conflicts.length > 0)
}

/** „16.00" → „16,00" podle jazyka; hodnoty z API jsou desetinné řetězce. */
export function formatHours(value: string | null | undefined, locale?: string): string {
  if (value === null || value === undefined || value === '') return '—'
  const number = Number(value)
  if (!Number.isFinite(number)) return value
  return number.toLocaleString(locale, { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

export function firstDayOfPeriod(period: string): string {
  return /^\d{4}-(0[1-9]|1[0-2])$/.test(period) ? `${period}-01` : ''
}

/**
 * Výchozí hodnoty pro automatické založení chybějících osob ze souhrnu
 * importu docházky — bez proklikávání kroku Osoby. Druh vztahu je jen
 * záložní hodnota; skutečný se odhadne per osoba přes `guessRelationType`.
 */
export function autoPersonCreateDefaults(period: string): PersonCreateDefaults {
  return { relation_type: 'employment', weekly_hours: '40', planned_start_on: firstDayOfPeriod(period), activate: true }
}

export function isValidPeriod(period: string): boolean {
  return firstDayOfPeriod(period) !== ''
}

/**
 * Použití dávky čeká na potvrzení kontrol období a dvojích vstupů. Starší
 * server kontroly neposílá, pak se nic nepotvrzuje.
 */
export function sourceConfirmationMissing(checks: AttendanceSourceChecks | null | undefined, confirmed: boolean): boolean {
  return checks?.requires_confirmation === true && !confirmed
}

/** Období podkladů podle názvů, která se liší od vybraného (pro upozornění). */
export function mismatchedPeriods(checks: AttendanceSourceChecks | null | undefined): string[] {
  if (!checks?.period.mismatch) return []
  return checks.period.detected.map(item => item.period).filter(period => period !== checks.period.selected)
}

export function chunk<T>(items: readonly T[], size: number): T[][] {
  if (size < 1) throw new RangeError('Velikost dávky musí být aspoň 1.')
  const parts: T[][] = []
  for (let index = 0; index < items.length; index += size) {
    parts.push(items.slice(index, index + size))
  }
  return parts
}
