import { api } from './client'
import { downloadApiFile } from '@/utils/downloadFile'
import type { PayrollAgreementAnnualHours, PayrollOpenAgreement } from './payroll'

export type { PayrollOpenAgreement }

/** Soubor přenášený v JSON těle; obsah je base64 bez prefixu `data:`. */
export interface ImportFilePayload {
  name: string
  content_base64: string
}

// ─── Registrace JMHZ ──────────────────────────────────────────────────────────

export type RegistrationEnvironment = 'production' | 'test'
/**
 * `CSSZ_EXPORT` = export zaměstnanců z ePortálu ČSSZ (bez data nástupu),
 * `JMHZ_DERIVED` = přihlášení vztahu, který dokládá řada měsíčních hlášení (v žádném souboru není).
 * `NEMPRI25`, `NEMPRI20`, `HZUPN20` a `OZUSPOJ23` jsou podání dávek a záměrů slevy,
 * která už odeslal předchozí program (nic se znovu neodesílá).
 */
export type RegistrationDocumentType =
  | 'REGZEC25'
  | 'PREZEC26'
  | 'CSSZ_EXPORT'
  | 'JMHZ'
  | 'JMHZ_DERIVED'
  | 'NEMPRI25'
  | 'NEMPRI20'
  | 'HZUPN20'
  | 'OZUSPOJ23'
export type RegistrationRelationType =
  | 'employment'
  | 'small_scale_employment'
  | 'dpc'
  | 'dpp'
  | 'statutory_body'
export type RegistrationMatchStatus = 'new' | 'matched' | 'ambiguous' | 'not_found'
export type RegistrationMatchedBy = 'birth_number' | 'vcp' | 'oic' | 'id_ppv' | 'name_birth_date' | 'manual'
export type RegistrationOperation =
  | 'create_person'
  | 'create_employment'
  | 'update'
  | 'terminate'
  | 'assign_identifiers'
  | 'pair_required'
  | 'create_case'
  | 'update_case'
  | 'import_intent'
  | 'none'
  | 'unsupported'
/** Typ podání měsíčního hlášení: řádné, opravné, storno. */
export type RegistrationSubmissionType = 'R' | 'O' | 'S'
export type RegistrationOpeningBalanceStatus = 'ready' | 'blocked' | 'unchanged'
export type RegistrationAverageStatus = 'ready' | 'blocked' | 'exists'
/**
 * Převzetí měsíce z hlášení: převezme se / převezme se jen zčásti (část formulářů zůstala
 * bez vztahu nebo zablokovaná) / nejde / měsíc už počítá MyÚčto.
 */
export type RegistrationTakeoverStatus = 'ready' | 'partial' | 'blocked' | 'computed'
/** Výsledek použití: `incomplete` = některá věta selhala nebo formulář zůstal bez vztahu či zablokovaný. */
export type RegistrationApplyOutcome = 'complete' | 'incomplete'

export interface RegistrationFileInfo {
  name: string
  sha256: string
  document_type: RegistrationDocumentType | null
  record_count: number
  error: string | null
  warnings: string[]
  /** Období měsíčního hlášení (YYYY-MM); u registrací `null`. */
  period: string | null
  submission_type: RegistrationSubmissionType | null
}

export interface RegistrationHistory {
  period: string
  gross_minor: number | null
  tax_base_minor: number | null
  advance_tax_minor: number | null
  bonus_minor: number | null
  social_base_minor: number | null
  worked_hours: string | null
  average_hourly_minor: number | null
}

export interface RegistrationPair {
  key: string
  employment_id: number
}

export interface RegistrationEmploymentOption {
  employment_id: number
  employee_id: number
  label: string
  code: string
}

export interface RegistrationOpeningBalanceMonth {
  month: number
  social_assessment_base_minor_units: number
  advance_base_minor_units: number
  advance_tax_minor_units: number
  withholding_base_minor_units: number
  withholding_tax_minor_units: number
  applied_non_refundable_credits_minor_units: number
  applied_child_credit_minor_units: number
  tax_bonus_minor_units: number
  bonus_qualifying_income_minor_units: number
}

export interface RegistrationOpeningBalance {
  employee_id: number
  employee_name: string
  year: number
  months: RegistrationOpeningBalanceMonth[]
  status: RegistrationOpeningBalanceStatus
  reason: string | null
}

export interface RegistrationAverage {
  employment_id: number
  label: string
  year: number
  quarter: number
  gross_minor: number
  worked_minutes: number
  average_hourly_minor: number
  reported_hourly_minor: number | null
  status: RegistrationAverageStatus
  reason: string | null
}

export interface RegistrationTakeoverMonth {
  period: string
  status: RegistrationTakeoverStatus
  reason: string | null
  ready_count: number
  gross_minor: number
  net_minor: number
  advance_tax_minor: number
  blocked: { label: string; reason: string | null }[]
}

export interface RegistrationTakeoverRelation {
  employee_id: number
  employment_id: number
  label: string
  start_on: string | null
  end_on: string | null
  monthly_wage_minor: number | null
  monthly_wage_from: string | null
  /** Čtvrtletí s průměrem, se kterým počítal předchozí program (`Q/RRRR`). */
  average_quarters: string[]
  leave_minutes: number
  /** Poslední převzaté hlášení vykazuje srážky ze mzdy — převzetí založí úkol na vztahu. */
  deductions_recorded?: boolean
  /** Poslední převzaté hlášení vykazuje nemoc, PPM nebo ošetřovné — převzetí založí úkol ověřit je. */
  sickness_review?: boolean
}

/** Převzetí historie mezd z hlášení (společná vrstva převzatých mezd). */
export interface RegistrationTakeover {
  start_period: string | null
  months: RegistrationTakeoverMonth[]
  relations: RegistrationTakeoverRelation[]
}

export interface RegistrationCandidate {
  employee_id: number
  employment_id: number
  label: string
}

export interface RegistrationChange {
  field: string
  label: string
  current: string | null
  imported: string | null
}

/** Podání NEMPRI, HZUPN nebo OZUSPOJ předchozího programu (`document_type` NEMPRI25, NEMPRI20, HZUPN20, OZUSPOJ23). */
export interface RegistrationBenefit {
  document: 'NEMPRI' | 'HZUPN' | 'OZUSPOJ'
  benefit_kind: string | null
  decision_number: string | null
  incapacity_from: string | null
  incapacity_to: string | null
  /** Případ dávky v evidenci, ke kterému se podání vztahuje (`null` = případ vznikne). */
  case_id: number | null
  case_source: 'myucto' | 'predecessor' | null
  received_on: string | null
  /** OZUSPOJ: den doručení z protokolu ČSSZ je povinný a soubor ho nenese. */
  needs_received_on: boolean
}

export interface RegistrationRecord {
  key: string
  file: string
  sequence: number
  document_type: RegistrationDocumentType
  action_code: number
  action_label: string
  prepared_on: string | null
  effective_on: string | null
  person: {
    full_name: string
    first_name: string | null
    last_name: string | null
    birth_date: string | null
    birth_number_masked: string | null
    has_oic: boolean
  }
  employment: {
    start_on: string | null
    end_on: string | null
    activity_code: string | null
    relation_type: RegistrationRelationType | null
    /** Druhy vztahu, mezi kterými volí účetní (podklady je nerozliší); prázdné = bez volby. */
    relation_type_options: RegistrationRelationType[]
    /** Nástup je jen odhad z prvního hlášeného měsíce — doplní se ze smlouvy. */
    start_estimated: boolean
    position_name: string | null
    has_id_ppv: boolean
  }
  match: {
    status: RegistrationMatchStatus
    matched_by: RegistrationMatchedBy | null
    employee_id: number | null
    employee_name: string | null
    employment_id: number | null
    employment_code: string | null
    candidates: RegistrationCandidate[]
  }
  operation: RegistrationOperation
  changes: RegistrationChange[]
  warnings: string[]
  blocker: string | null
  selectable: boolean
  /**
   * Export ČSSZ uvádí konec pojistného vztahu u vztahu, který evidence vede
   * jako trvající. Ukončení se zapíše jen po výslovném potvrzení u věty
   * (klíč věty v `terminations`).
   */
  termination_offer?: {
    end_on: string
    employment_code: string
    confirmed: boolean
  } | null
  /** Jen měsíční hlášení (`document_type: 'JMHZ'`). */
  period?: string | null
  form_id?: string | null
  history?: RegistrationHistory | null
  /** Jen podání dávek a záměrů slevy předchozího programu. */
  benefit?: RegistrationBenefit | null
}

export interface RegistrationPreview {
  environment: RegistrationEnvironment
  files: RegistrationFileInfo[]
  records: RegistrationRecord[]
  summary: {
    total: number
    create: number
    update: number
    terminate: number
    none: number
    blocked: number
    pair_required: number
  }
  employment_options: RegistrationEmploymentOption[]
  opening_balances: RegistrationOpeningBalance[]
  averages: RegistrationAverage[]
  /** `null`, když dávka nemá měsíční hlášení. */
  takeover: RegistrationTakeover | null
}

export interface RegistrationRelationChoice {
  key: string
  relation_type: RegistrationRelationType
}

/** Den doručení podání podle protokolu ČSSZ (nepovinný u NEMPRI a HZUPN, povinný u OZUSPOJ). */
export interface RegistrationReceivedOn {
  key: string
  received_on: string
}

export interface RegistrationPreviewPayload {
  environment: RegistrationEnvironment
  files: ImportFilePayload[]
  pairs?: RegistrationPair[]
  /** Zvolený druh vztahu u vět s `relation_type_options`. */
  relation_types?: RegistrationRelationChoice[]
  /** Klíče vět, u kterých účetní potvrdila „Ukončit vztah" podle exportu ČSSZ. */
  terminations?: string[]
  /** Den doručení podání předchozího programu podle protokolu ČSSZ. */
  received_on?: RegistrationReceivedOn[]
}

export interface RegistrationUnresolvedForm {
  key: string
  file: string
  period: string
  label: string
  reason: string
}

export interface RegistrationApplyPayload extends RegistrationPreviewPayload {
  /** Smí být prázdné, když se jen přebírá historie. */
  keys: string[]
  evidence_confirmed: boolean
  office_id: number | null
  pairs: RegistrationPair[]
  apply_opening_balances: boolean
  apply_averages: boolean
  auto_approve_changes: boolean
  auto_approve_averages: boolean
  apply_takeover: boolean
}

export type RegistrationResultStatus = 'applied' | 'failed' | 'skipped'
export type RegistrationResultOperation =
  | 'person_created'
  | 'employment_created'
  | 'identity_facts'
  | 'address'
  | 'terms'
  | 'activated'
  | 'identifiers'
  | 'terminated'
  | 'start_corrected'
  | 'no_show'
  | 'health_insurer'
  | 'tax_declaration'
  | 'tax_credit_claims'
  | 'social_discount'
  | 'dependants'
  | 'case_created'
  | 'case_updated'
  | 'nempri_predecessor'
  | 'hzupn_predecessor'
  | 'intent_created'
  | 'intent_ended'
  | 'intent_unchanged'

export interface RegistrationApplyResult {
  results: {
    key: string
    status: RegistrationResultStatus
    message: string | null
    employee_id: number | null
    employment_id: number | null
    operations: (RegistrationResultOperation | string)[]
    /** Založený vztah má jen odhadnutý nástup (jen věty registrací). */
    start_estimated?: boolean
  }[]
  summary: { applied: number; failed: number; skipped: number }
  outcome: RegistrationApplyOutcome
  /** Platné formuláře hlášení, které po zápisu zůstaly bez vztahu nebo zablokované. */
  unresolved: RegistrationUnresolvedForm[]
  opening_balances: {
    saved: number
    skipped: { employee_id: number; employee_name: string; year: number; reason: string }[]
  }
  averages: {
    created: number
    approved: number
    skipped: { employment_id: number; label: string; year: number; quarter: number; reason: string }[]
  }
  takeover: {
    saved: number
    periods: string[]
    relations: number
    counts: Record<string, number>
    skipped: { period: string | null; label: string; reason: string }[]
  } | null
  change_checklist: {
    completed: number
    failed: { employment_id: number; item_key: string; message: string }[]
  }
}

// ─── Docházka ─────────────────────────────────────────────────────────────────

export type AttendanceUnit = 'hours' | 'excel_duration' | 'amount' | 'text'
export type AttendanceMeaning =
  | 'ignore' | 'person_name' | 'personal_number' | 'birth_number' | 'birth_date' | 'health_insurer_code'
  | 'relation_label'
  | 'department' | 'cost_center' | 'position' | 'weekly_hours' | 'start_end_note' | 'start_date' | 'monthly_wage'
  | 'hourly_rate' | 'end_date' | 'bank_account' | 'worked_days'
  | 'address_street' | 'address_house_number' | 'address_city' | 'address_postal_code' | 'address_country'
  | 'citizenship' | 'cz_isco_code' | 'activity_code' | 'workplace' | 'tax_residence' | 'tax_declaration'
  | 'tax_declaration_from' | 'education_code' | 'employment_status_code'
  | 'birth_surname' | 'birth_place' | 'birth_country' | 'work_mode_code' | 'continuous_operation'
  | 'workplace_city' | 'position_name' | 'leadership'
  | 'worked_hours' | 'overtime_hours' | 'night_hours' | 'weekend_hours' | 'holiday_work_hours'
  | 'afternoon_hours' | 'fund_hours'
  | 'vacation_hours' | 'holiday_hours' | 'sick_hours' | 'doctor_hours' | 'care_hours'
  | 'paternity_hours' | 'unpaid_leave_hours' | 'unexcused_hours' | 'obstacle_employee_hours'
  | 'obstacle_employer_hours' | 'business_trip_hours' | 'home_office_hours' | 'compensatory_time_off_hours'
  | 'component' | 'net_meal_deduction' | 'net_other_deduction'
  | 'reference_gross' | 'reference_net' | 'reference_hours'

export interface AttendanceRule {
  sheet: string | null
  header: string
  meaning: AttendanceMeaning
  unit: AttendanceUnit | null
  /** `'*'` = kód složky se odvodí z hlavičky sloupce. */
  component_code: string | null
  /** Podmínka: pravidlo platí jen pro řádky, kde má sloupec `when_header` hodnotu `when_value`. */
  when_header?: string
  when_value?: string
  /** Jen překážka na straně zaměstnavatele: sazba náhrady mzdy v % průměru (60–100, výchozí 80). */
  rate_percent?: number
}

export type AttendanceProfileComponentKind =
  | 'hourly_wage'
  | 'task_wage'
  | 'bonus'
  | 'premium'
  | 'compensation'
  | 'allowance'
  | 'other'

export interface AttendanceProfileComponent {
  code: string
  name: string
  kind: AttendanceProfileComponentKind
}

export interface AttendanceFileInfo {
  name: string
  sha256: string
  format: 'xlsx' | 'csv'
  sheets: number
  error: string | null
}

export interface AttendanceColumn {
  letter: string
  header: string
  meaning: AttendanceMeaning
  unit: AttendanceUnit | null
  component_code: string | null
  rule_source: 'profile' | 'suggested' | 'none' | string
  samples: string[]
  /** Pravidla s podmínkou, která mají v řádcích přednost před `meaning`. */
  conditions?: AttendanceColumnCondition[]
}

export interface AttendanceColumnCondition {
  when_header: string
  when_value: string
  meaning: AttendanceMeaning
  component_code: string | null
}

export interface AttendanceSheet {
  id: string
  file: string
  sheet: string
  header_row: number | null
  data_rows: number
  used: boolean
  columns: AttendanceColumn[]
}

export interface AttendanceEmploymentOption {
  employment_id: number
  employee_id: number
  label: string
  code: string
  status: string
  relation_type?: string
}

export type AttendanceMatchStatus = 'linked' | 'matched' | 'ambiguous' | 'conflict' | 'not_found'

export interface AttendanceConflict {
  hours?: string | null
  amount?: string | null
  amount_minor?: number | null
  source: string
}

export interface AttendanceMetric {
  meaning: AttendanceMeaning
  hours: string
  source: string
  conflicts: AttendanceConflict[]
}

export interface AttendanceComponentValue {
  component_code: string
  amount: string
  amount_minor: number
  /** Původní částka s haléři, když ji import zaokrouhlil nahoru na celé koruny. */
  rounded_from_minor?: number
  source: string
  conflicts: AttendanceConflict[]
}

/** Srážka z čisté mzdy (obědy, srážky) — import z ní založí dohodu o srážce na měsíc. */
export interface AttendanceDeductionValue {
  meaning: AttendanceMeaning
  amount: string
  amount_minor: number
  source: string
  conflicts: AttendanceConflict[]
}

export interface AttendancePerson {
  key: string
  display_name: string
  personal_number: string | null
  /** Osobní číslo má ve firmě nebo v podkladech víc osob: k párování se nepoužilo a nepřevezme se. */
  number_ambiguous?: boolean
  birth_number_masked: string | null
  relation_label: string | null
  department: string | null
  cost_center: string | null
  position: string | null
  weekly_hours: string | null
  start_end_note: string | null
  monthly_wage: string | null
  /** Sjednaná hodinová sazba a konec dohody tak, jak jsou v podkladech. */
  hourly_rate?: string | null
  end_date?: string | null
  /** Sjednaný konec dohody přečtený ze sloupce (`YYYY-MM-DD`). */
  agreed_end_on?: string | null
  /** Číslo účtu z podkladů jen maskované; celé ho při založení doplní server. */
  bank_account_masked?: string | null
  /** Nástup a ukončení přečtené z poznámky (`YYYY-MM-DD`). */
  start_on?: string | null
  end_on?: string | null
  sources: { sheet_id: string; row: number }[]
  match: {
    status: AttendanceMatchStatus
    matched_by: 'link' | 'birth_number' | 'employment_code' | 'name' | null
    employment_id: number | null
    employee_id: number | null
    employee_name: string | null
    employment_code: string | null
    /** Rozpor rodného a osobního čísla (stav `conflict`), řádek se nepároval. */
    conflict?: string | null
    candidates: { employment_id: number; employee_id: number; label: string }[]
  }
  /** Odpracované dny v měsíci ze sloupce podkladů; zapíšou se do souhrnu pracovního měsíce. */
  worked_days?: number | null
  metrics: AttendanceMetric[]
  components: AttendanceComponentValue[]
  deductions?: AttendanceDeductionValue[]
  reference: { gross_minor: number | null; net_minor: number | null; hours: string | null }
  /** Chyby řádku, kvůli kterým import osobu nezaloží (neplatné rodné číslo); ke vztahu v evidenci ji přiřadit jde. */
  errors?: string[]
  /** Roční hodiny na DPP po započtení importované docházky; jen u osob přiřazených na DPP. */
  dpp_annual_hours?: PayrollAgreementAnnualHours & { imported_minutes: number }
  /** Údaje pro registraci ČSSZ, které se zapíšou (nové osobě všechny, existující jen chybějící). */
  registration?: AttendanceRegistrationItem[]
  /** Návrh pravděpodobného výdělku ze sazby nebo z odměny za odpracované hodiny. */
  probable_earning?: AttendanceProbableEarning | null
  /** Osoba je v evidenci (vazba, rodné nebo osobní číslo), jen nemá vztah v období: založí se jí nový vztah. */
  existing_person?: AttendanceExistingPerson | null
  /** Otevřená DPP osoby, která v období nemá vztah (jen u osob k založení). */
  open_agreement?: PayrollOpenAgreement | null
  /**
   * Mzda ze sazby a hodin (podklady bez částky): `computed` = dopočtená
   * (sazba × hodiny), `amount_in_source` = podklady nesou částku, `no_hours` =
   * chybí hodiny, `hourly_terms` = vztah má hodinové odměňování na kartě.
   */
  rate_wage?: AttendanceRateWage | null
  warnings: string[]
}

export type AttendanceRateWage =
  | { status: 'computed'; component_code: string; hours: string; hourly_rate_minor: number; amount_minor: number }
  | { status: 'amount_in_source' | 'no_hours' | 'hourly_terms' }

export interface AttendanceExistingPerson {
  employee_id: number
  employee_name: string
  matched_by: 'link' | 'birth_number' | 'employment_code' | 'name_birth_date'
  /** Druh posledního vztahu osoby, výchozí druh nové dohody. */
  relation_type: RegistrationRelationType
  /** Shoda jen podle jména a data narození: založení vyžaduje potvrzení uživatele. */
  requires_confirmation?: boolean
}

/** Pravděpodobný výdělek z podkladů, viz AttendanceImportService::probableEarnings(). */
export interface AttendanceProbableEarning {
  source: 'rate' | 'amount'
  hourly_minor: number
  amount_minor: number | null
  hours: string | null
}

export interface AttendanceProbableEarningChange extends AttendanceProbableEarning {
  key: string
  display_name: string
  employment_id: number
}

/** Údaj pro registraci ČSSZ v náhledu, viz AttendanceRegistrationData::display() na serveru. */
export interface AttendanceRegistrationItem {
  key: 'address' | 'citizenship' | 'birth' | 'cz_isco_code' | 'activity_code' | 'workplace' | 'tax_residence' | 'tax_declaration'
    | 'work_mode_code' | 'position_name' | 'workplace_city' | 'continuous_operation' | 'leadership' | 'house_number'
    | 'employment_status_code' | 'education_code'
  value: string
}

export interface AttendanceComponentCheck {
  component_code: string
  status: 'ok' | 'missing' | 'not_one_off' | 'will_create'
  message: string | null
  name: string | null
  kind: AttendanceProfileComponentKind | string | null
}

export interface AttendanceUnrecognizedColumn {
  sheet_id: string
  letter: string
  header: string
  samples: string[]
}

export interface AttendancePreviewProfile {
  id: number | null
  name: string | null
  auto: boolean
}

/** Mzdový běh, který po převzetí měsíční mzdy potřebuje přepočet. */
export interface AttendanceRunNeedingRefresh {
  run_id: number
  /** YYYY-MM */
  period: string
  status: string
}

/**
 * Rozdíl mezi měsíční mzdou z podkladů a sjednanými podmínkami vztahu.
 * Zapíše se jen změna s `reason === null`.
 */
export interface AttendanceWageChange {
  key: string
  display_name: string
  employment_id: number
  current_minor: number | null
  imported_minor: number
  /** `correct` = doplnění chybějící mzdy, `add` = nová verze podmínek od 1. dne období. */
  mode: 'correct' | 'add'
  reason: string | null
  runs_needing_refresh: AttendanceRunNeedingRefresh[]
}

/** Nová verze vzorového profilu pro profil, který firma upravila. */
export interface AttendanceSampleUpgrade {
  profile_id: number
  name: string
  sample_key?: AttendanceSampleKey
  version: number | null
  latest_version: number
  rules: AttendanceRule[]
  components: AttendanceProfileComponent[]
}

/** Jiný import, který už v období založil platné mzdové vstupy. */
export interface AttendanceOtherSource {
  attendance_import_id: number | null
  input_import_id: number
  files: string[]
  active_inputs: number
  created_at: string
}

/**
 * Kontroly před použitím dávky: období podle názvů souborů a listů a vstupy
 * téhož období z jiného importu. Nález musí účetní před použitím potvrdit.
 */
export interface AttendanceSourceChecks {
  period: {
    selected: string
    detected: { period: string; sources: string[] }[]
    mismatch: boolean
  }
  other_sources: AttendanceOtherSource[]
  requires_confirmation: boolean
}

export interface AttendancePreview {
  period: string
  content_hash: string
  profile: AttendancePreviewProfile
  /** Starší server kontroly neposílá. */
  source_checks?: AttendanceSourceChecks
  files: AttendanceFileInfo[]
  sheets: AttendanceSheet[]
  unrecognized_columns: AttendanceUnrecognizedColumn[]
  rules: AttendanceRule[]
  employment_options: AttendanceEmploymentOption[]
  persons: AttendancePerson[]
  component_checks: AttendanceComponentCheck[]
  wage_changes: AttendanceWageChange[]
  /** Pravděpodobné výdělky k potvrzení u vztahů, které žádný nemají. */
  probable_earning_changes?: AttendanceProbableEarningChange[]
  upgrade_available: AttendanceSampleUpgrade | null
  /** Náhled i použití zaokrouhlují složky vykazované v JMHZ nahoru na celé koruny. Starší server klíč nemá. */
  round_whole_crowns?: boolean
  /** Výchozí stav volby: zapnuto za měsíce vykazované přes JMHZ. */
  whole_crowns_default?: boolean
  summary: {
    persons: number
    matched: number
    ambiguous: number
    not_found: number
    /** Z `not_found`: rozpor rodného a osobního čísla. Starší server klíč nemá. */
    conflicts?: number
    /** Osoby s odpracovanými dny v podkladech. */
    worked_days?: number
    metrics: number
    components: number
    amount_minor_total: number
    deductions?: number
    deduction_minor_total?: number
    /** Přiřazené osoby, kterým v evidenci chybí údaje pro registraci z podkladů. */
    registration_gaps?: number
    /** Složky vykazované v JMHZ, jejichž částka má haléře. */
    halere_components?: number
    /** Mzda ze sazby a hodin: komu vznikne (a kolik celkem) a komu ne. */
    rate_wages?: { computed: number; amount_minor: number; no_hours: number; hourly_terms: number }
  }
}

export interface AttendancePreviewPayload {
  period: string
  files: ImportFilePayload[]
  rules: AttendanceRule[] | null
  profile_id: number | null
  components?: AttendanceProfileComponent[] | null
  /** Bez klíče rozhodne server podle období (JMHZ = zapnuto). */
  round_whole_crowns?: boolean
}

export interface AttendanceLink {
  person_key: string
  employment_id: number
}

export interface AttendanceApplyPayload {
  period: string
  files: ImportFilePayload[]
  rules: AttendanceRule[]
  links: AttendanceLink[]
  save_links: boolean
  create_inputs: boolean
  create_components: boolean
  adopt_personal_numbers: boolean
  adopt_monthly_wage: boolean
  /** Založit srážky z podkladů jako dohody o srážce na importovaný měsíc. */
  create_deductions?: boolean
  components?: AttendanceProfileComponent[] | null
  profile_id?: number | null
  /** Účetní potvrdila nález kontrol období a dvojích vstupů (`source_checks`). */
  confirm_source_checks?: boolean
  /** Doplnit přiřazeným osobám chybějící údaje pro registraci ČSSZ z podkladů. */
  fill_registration_data?: boolean
  /** Zapsat navržené pravděpodobné výdělky vztahům, které žádný nemají. */
  adopt_probable_earnings?: boolean
  /** Zaokrouhlit složky vykazované v JMHZ nahoru na celé koruny; bez klíče rozhodne server podle období. */
  round_whole_crowns?: boolean
}

/** Výsledek zápisu srážek z podkladů (dohody o srážce na měsíc). */
export interface AttendanceDeductionReport {
  created: number
  updated: number
  unchanged: number
  conflicts: { key: string; display_name: string; reason: string }[]
}

export interface AttendancePersonalNumberConflict {
  key: string
  display_name: string
  reason: string
}

export interface AttendanceBatch {
  id: number
  period: string
  source_system: 'attendance' | string
  created_at: string
  created_by_name: string | null
  files: { name: string; sha256: string }[]
  person_count: number
  metric_count: number
  input_count: number
}

export interface AttendanceApplyResult {
  replayed: boolean
  batch: AttendanceBatch
  inputs: {
    import_id: number | null
    created: number
    duplicates: number
    errors: { row_number: number; error_message: string }[]
  }
  links_saved: number
  skipped_persons: { key: string; display_name: string; reason: string }[]
  personal_numbers_adopted: number
  personal_number_conflicts: AttendancePersonalNumberConflict[]
  monthly_wages_adopted: number
  wage_conflicts: { key: string; display_name: string; reason: string }[]
  runs_needing_refresh: AttendanceRunNeedingRefresh[]
  deductions?: AttendanceDeductionReport | null
  /** Doplnění údajů pro registraci ČSSZ; null = volba vypnutá. */
  registration?: { filled: number; notes: { key: string; display_name: string; reason: string }[] } | null
  /** Zápis pravděpodobných výdělků; null = volba vypnutá. */
  probable_earnings?: { adopted: number; conflicts: { key: string; display_name: string; reason: string }[] } | null
}

/** Hrubá a čistá mzda z mzdového exportu v dávce proti výpočtu mzdového běhu období. */
export interface AttendanceBatchComparisonRow {
  employment_id: number
  employee_name: string
  employment_code: string
  reference_gross_minor: number | null
  reference_net_minor: number | null
  computed_gross_minor: number | null
  gross_diff_minor: number | null
  computed_net_minor: number | null
  net_diff_minor: number | null
  /** Osoba má víc vztahů — čistá mzda je ve výpočtu jen za osobu, neporovnává se. */
  net_shared: boolean
  status: 'match' | 'diff' | 'missing'
}

export interface AttendanceBatchComparison {
  batch: AttendanceBatch
  run: { status: string; revision_status: string | null } | null
  rows: AttendanceBatchComparisonRow[]
  summary: { match: number; diff: number; missing: number }
}

export interface AttendancePersonCreate {
  person_key: string
  full_name: string
  first_name: string
  last_name: string
  birth_number: string | null
  relation_type: RegistrationRelationType
  weekly_hours: string | null
  /** Sjednaná měsíční mzda v celých korunách (z mzdového výměru v podkladech). */
  monthly_gross: number | null
  planned_start_on: string
  /** Hodinová sazba a konec dohody z podkladů; nečitelné vrátí server ve zprávě výsledku. */
  hourly_rate?: string | null
  fixed_term_end_on?: string | null
  personal_number: string | null
  activate: boolean
  /** Otevřená DPP osoby, která se použije místo nové dohody. */
  use_open_agreement?: number | null
  /** Výslovně nová DPP vedle otevřené dohody. */
  create_new_agreement?: boolean
  /** Potvrzení, že osoba z podkladů je osoba z evidence se shodným jménem a datem narození (její id). */
  confirm_existing_person?: number
  /** Výslovně nová karta osoby i přes shodu jména a data narození. */
  create_new_person?: boolean
}

export interface AttendancePersonsResult {
  results: {
    person_key: string
    status: 'created' | 'employment_created' | 'reused' | 'failed'
    employee_id: number | null
    employment_id: number | null
    message: string | null
  }[]
}

export interface AttendanceBatchRow {
  employment_id: number
  employee_name: string
  employment_code: string
  meaning: AttendanceMeaning | string
  hours: string | null
  amount_minor: number | null
  source: string
}

/** Vestavěné vzory profilu: docházkový systém GIRITON a obecná šablona MyÚčto. */
export type AttendanceSampleKey = 'giriton' | 'myucto' | 'pamica'

export interface AttendanceProfile {
  id: number
  name: string
  rules: AttendanceRule[]
  components: AttendanceProfileComponent[]
  is_sample: boolean
  /** Ze kterého vzoru profil vznikl; `null` u vlastního profilu. Starší server klíč neposílá. */
  sample_key?: AttendanceSampleKey | null
  /** Verze vzoru, ze které profil vznikl; `null` u vlastního profilu. */
  sample_version: number | null
  /** Seznam profilů: upravený vzor má novější verzi. Uložení profilu příznak nevrací. */
  upgrade_available?: boolean
  updated_at: string
}

export interface AttendanceProfileSavePayload {
  id: number | null
  name: string
  rules: AttendanceRule[]
  components: AttendanceProfileComponent[]
}

/** Přenosný tvar profilu (export/import JSON mezi instalacemi a firmami). */
export interface AttendanceProfileExport {
  format: 'myucto-attendance-profile'
  version: 1
  name: string
  rules: AttendanceRule[]
  components: AttendanceProfileComponent[]
}

// ─── OIČ z POHODY ─────────────────────────────────────────────────────────────

export type PohodaOicRowStatus =
  | 'ready'
  | 'already_stored'
  | 'conflict'
  | 'oic_owned_by_other'
  | 'not_found'
  | 'ambiguous'
  | 'no_employment'
  | 'duplicate'
  | 'invalid_birth_number'
  | 'invalid_oic'
  | 'no_oic'
export type PohodaOicResultStatus = 'applied' | 'failed' | 'skipped'

export interface PohodaOicFileInfo {
  name: string
  sha256: string
  sheet: string | null
  row_count: number
  /** IČ firmy z bloku nad hlavičkou exportu. */
  company_ico: string | null
  error: string | null
  warnings: string[]
}

export interface PohodaOicRow {
  key: string
  file: string
  sheet: string
  row: number
  name: string
  personal_number: string | null
  birth_number_masked: string | null
  oic_masked: string | null
  status: PohodaOicRowStatus
  message: string
  selectable: boolean
  employee_id: number | null
  employee_name: string | null
  employment_id: number | null
  employment_code: string | null
  valid_from: string | null
}

export interface PohodaOicPreview {
  environment: RegistrationEnvironment
  files: PohodaOicFileInfo[]
  rows: PohodaOicRow[]
  summary: {
    total: number
    ready: number
    already_stored: number
    conflict: number
    blocked: number
    without_oic: number
  }
}

export interface PohodaOicPreviewPayload {
  environment: RegistrationEnvironment
  files: ImportFilePayload[]
}

export interface PohodaOicApplyPayload extends PohodaOicPreviewPayload {
  keys: string[]
  evidence_confirmed: boolean
}

export interface PohodaOicApplyResult {
  environment: RegistrationEnvironment
  results: {
    key: string
    status: PohodaOicResultStatus
    message: string
    name: string
    employee_id: number | null
    employment_id: number | null
  }[]
  summary: { applied: number; failed: number; skipped: number }
}

export const payrollImportsApi = {
  previewRegistrations: (payload: RegistrationPreviewPayload) =>
    api.post<RegistrationPreview>('/payroll/imports/registrations/preview', payload)
      .then(response => response.data),
  applyRegistrations: (payload: RegistrationApplyPayload) =>
    api.post<RegistrationApplyResult>('/payroll/imports/registrations/apply', payload)
      .then(response => response.data),

  previewAttendance: (payload: AttendancePreviewPayload) =>
    api.post<AttendancePreview>('/payroll/imports/attendance/preview', payload)
      .then(response => response.data),
  applyAttendance: (payload: AttendanceApplyPayload) =>
    api.post<AttendanceApplyResult>('/payroll/imports/attendance/apply', payload)
      .then(response => response.data),
  // S podklady (jako u náhledu) doplní server rodná čísla, která klient zná jen maskovaná.
  createAttendancePersons: (
    period: string,
    persons: AttendancePersonCreate[],
    source?: Omit<AttendancePreviewPayload, 'period'>,
  ) =>
    api.post<AttendancePersonsResult>('/payroll/imports/attendance/persons', { period, persons, ...source })
      .then(response => response.data),
  attendanceBatches: (period?: string | null) =>
    api.get<{ batches: AttendanceBatch[] }>('/payroll/imports/attendance/batches', {
      params: period ? { period } : undefined,
    }).then(response => response.data.batches),
  attendanceBatch: (id: number) =>
    api.get<{ batch: AttendanceBatch; rows: AttendanceBatchRow[] }>(`/payroll/imports/attendance/batches/${id}`)
      .then(response => response.data),
  attendanceBatchComparison: (id: number) =>
    api.get<AttendanceBatchComparison>(`/payroll/imports/attendance/batches/${id}/comparison`)
      .then(response => response.data),
  attendanceProfiles: () =>
    api.get<{ profiles: AttendanceProfile[] }>('/payroll/imports/attendance/profiles')
      .then(response => response.data.profiles),
  saveAttendanceProfile: (payload: AttendanceProfileSavePayload) =>
    api.post<{ profile: AttendanceProfile }>('/payroll/imports/attendance/profiles', payload)
      .then(response => response.data.profile),
  deleteAttendanceProfile: (id: number) =>
    api.delete<{ deleted: true }>(`/payroll/imports/attendance/profiles/${id}`)
      .then(response => response.data),
  /** Obnoví vestavěný vzor podle klíče; `restored: false` = vzor ve firmě už je. */
  restoreAttendanceSampleProfile: (sampleKey: AttendanceSampleKey = 'giriton') =>
    api.post<{ profile: AttendanceProfile; restored: boolean }>('/payroll/imports/attendance/profiles/sample', {
      sample_key: sampleKey,
    }).then(response => response.data),
  /**
   * Šablona ke vzoru: XLSX pro Vzor MyÚčto (ukázkové řádky a list Návod),
   * u Vzoru PAMICA ukázkový XML export se smyšlenými osobami za zvolený měsíc.
   */
  downloadAttendanceTemplate: (fallbackFilename: string, sampleKey: AttendanceSampleKey = 'myucto', period?: string) => {
    const query = new URLSearchParams()
    if (sampleKey !== 'myucto') query.set('sample', sampleKey)
    if (period) query.set('period', period)
    const qs = query.toString()
    return downloadApiFile(`/payroll/imports/attendance/template${qs ? `?${qs}` : ''}`, fallbackFilename)
  },
  copyAttendanceProfile: (id: number, targetSupplierId: number) =>
    api.post<{ profile: AttendanceProfile }>(`/payroll/imports/attendance/profiles/${id}/copy`, {
      target_supplier_id: targetSupplierId,
    }).then(response => response.data.profile),
  exportAttendanceProfile: (id: number, fallbackFilename: string) =>
    downloadApiFile(`/payroll/imports/attendance/profiles/${id}/export`, fallbackFilename),
  importAttendanceProfile: (profile: AttendanceProfileExport, name?: string) =>
    api.post<{ profile: AttendanceProfile }>('/payroll/imports/attendance/profiles/import', {
      profile,
      ...(name !== undefined ? { name } : {}),
    }).then(response => response.data.profile),

  previewPohodaOic: (payload: PohodaOicPreviewPayload) =>
    api.post<PohodaOicPreview>('/payroll/imports/pohoda-oic/preview', payload)
      .then(response => response.data),
  applyPohodaOic: (payload: PohodaOicApplyPayload) =>
    api.post<PohodaOicApplyResult>('/payroll/imports/pohoda-oic/apply', payload)
      .then(response => response.data),
}
