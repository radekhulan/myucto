import { api } from './client'
// Step-up (heslo / TOTP / passkey proof) je sdílený s EPO — volba podpisového
// certifikátu je rozhodnutí stejné třídy jako správa klíče samotného, takže
// kódování důkazu se nesmí rozejít se zbytkem aplikace.
import { stepUpProofBody, type EpoStepUpProof } from './epoSubmissions'

/** Stránka seznamu. Bez hodnot platí serverový výchozí strop, ne „všechno". */
export interface PayrollPageParams {
  limit?: number
  offset?: number
}

export function pageParams(page?: PayrollPageParams): Record<string, number> {
  return {
    ...(page?.limit === undefined ? {} : { limit: page.limit }),
    ...(page?.offset === undefined ? {} : { offset: page.offset }),
  }
}

export type PayrollModuleStatus = 'disabled' | 'setup' | 'qualification_required' | 'active' | 'suspended'
export type PayrollSupportStatus = 'supported' | 'manual_review' | 'not_supported'

export interface PayrollModuleState {
  supplier_id: number
  status: PayrollModuleStatus
  start_period: string | null
  row_version: number
  activated_at: string | null
  suspended_at: string | null
  created_at: string | null
  updated_at: string | null
}

export interface PayrollCapability {
  key: string
  status: PayrollSupportStatus
  available: boolean
  min_epic: string
}

export interface PayrollSupportMatrix {
  version: string
  supported_years: number[]
  employment_types: PayrollCapability[]
  features: PayrollCapability[]
}

export interface PayrollCompanyCapabilityBlocker {
  code: 'unsupported_relation_type'
    | 'foreign_employment_regime'
    | 'unsupported_jmhz_scenario'
    | 'foreign_social_jurisdiction'
    | 'foreign_health_jurisdiction'
    | string
  capability_key: string
  source_type: string
  source_id: number
  message: string
  parameters: Record<string, unknown>
}

export interface PayrollCompanyCapabilityAssessment {
  production_ready: boolean
  assessed_from: string | null
  blockers: PayrollCompanyCapabilityBlocker[]
}

export interface PayrollCapabilitiesResponse {
  state: PayrollModuleState
  support_matrix: PayrollSupportMatrix
  company_capability: PayrollCompanyCapabilityAssessment
}

export type PayrollRelationType = 'employment' | 'small_scale_employment' | 'dpp' | 'dpc' | 'partner_dependent' | 'statutory_body'
export type PayrollEmploymentStatus = 'planned' | 'preregistered' | 'active' | 'suspended' | 'ended' | 'archived' | 'no_show'
export type PayrollInsuranceParticipation = 'automatic' | 'included' | 'excluded' | 'foreign'
export type PayrollTaxRegime = 'advance' | 'withholding' | 'foreign' | 'manual_review'
/** § 5a odst. 1 písm. a) až c) zák. č. 589/1992 Sb. — tři sazby zaměstnavatele. */
export type PayrollSocialEmployerRateCategory =
  | 'ordinary'
  | 'rescue_and_company_fire_service'
  | 'risk_employment'
/**
 * Prohlášení plátce podle § 6 odst. 4 písm. b) ZDP: zakládá vztah účast na
 * nemocenském pojištění (`ineligible` — vždy zálohová daň), nebo ne (`eligible` —
 * do rozhodné částky srážková)? Ptáme se jen u vztahů, u kterých to z druhu
 * poznat nejde: odměna jednatele/člena orgánu, DPČ, práce společníka pro s. r. o.
 */
export type PayrollOtherWithholdingEligibility = 'unverified' | 'eligible' | 'ineligible'
export type PayrollChecklistStatus = 'pending' | 'completed' | 'not_applicable'

/**
 * Důvod, proč objekt nejde smazat. `message` je věta pro uživatele, podle které
 * se dá jednat — ukazuje se místo zašedlého tlačítka bez vysvětlení.
 * `employment_code` je vyplněný, když osobu blokuje konkrétní pracovní vztah.
 */
export interface PayrollDeleteBlocker {
  code: string
  message: string
  employment_id: number | null
  employment_code: string | null
}

/** Počty toho, co zmizí spolu s objektem. Klíče se překládají přes i18n. */
export type PayrollDeleteCascade = Record<string, number>

/**
 * Co osobě chybí, aby na ni šlo spustit mzdy. Odvozuje se ze stejných čtyř
 * podmínek, jaké vynucuje uložení profilu — štítek „Vyžaduje doplnění" už tedy
 * nemusí mlčet o tom, co doplnit.
 */
export type PayrollPersonSetupGap =
  | 'name'
  | 'residence'
  | 'contact'
  | 'identifier'
  | 'employment'

export interface PayrollPersonListItem {
  id: number
  full_name: string
  is_active: boolean
  profile_status: string
  legacy_taxpayer_type: string
  legacy_employment_type: string
  employment_count: number
  relation_types: PayrollRelationType[]
  setup_gaps: PayrollPersonSetupGap[]
  needs_setup: boolean
  can_delete: boolean
  delete_blocker: PayrollDeleteBlocker | null
  delete_cascade: PayrollDeleteCascade
}

export interface PayrollEmploymentAccounting {
  gross_debit: string
  gross_credit: string
  employer_insurance_debit: string
  employer_insurance_credit: string
}

export interface PayrollEmployment {
  id: number
  employee_id: number
  office_id: number | null
  office_code: string | null
  office_name: string | null
  code: string
  relation_type: PayrollRelationType
  meal_entitlement_basis: PayrollMealEntitlementBasis
  status: PayrollEmploymentStatus
  is_primary: boolean
  start_date: string | null
  actual_start_date: string | null
  end_date: string | null
  archived_at: string | null
  is_legacy_projection: boolean
  monthly_gross_minor: number | null
  row_version: number
  allowed_transitions: PayrollEmploymentStatus[]
  can_delete: boolean
  delete_blocker: PayrollDeleteBlocker | null
  delete_cascade: PayrollDeleteCascade
  accounting: PayrollEmploymentAccounting
  terms: PayrollEmploymentTerms[]
  checklist: PayrollEmploymentChecklistItem[]
  timeline: PayrollEmploymentEvent[]
}

export interface PayrollEmploymentTerms {
  id: number
  office_id: number | null
  office_code: string | null
  effective_from: string
  effective_to: string | null
  contract_signed_on: string | null
  planned_start_on: string
  actual_start_on: string | null
  fixed_term_end_on: string | null
  weekly_hours: string | null
  leave_entitlement_weeks_override?: number | null
  workload_basis_points: number
  work_place: string | null
  regular_workplace: string | null
  jmhz_workplace_municipality_code: string | null
  jmhz_workplace_country_code: string | null
  jmhz_external_codebook_overlay_key?: string | null
  jmhz_external_codebook_manifest_sha256?: string | null
  jmhz_apz_contribution_status: PayrollVerifiedTriState
  jmhz_apz_instrument_code: string | null
  jmhz_functional_benefits_status: PayrollVerifiedTriState
  jmhz_temporary_assignment_status: PayrollVerifiedTriState
  jmhz_orchard_discount_eligible?: boolean
  jmhz_specific_legal_fact_applies?: boolean
  jmhz_ozp_employment_support_applies?: boolean
  jmhz_deep_mining_work_applies?: boolean
  cz_isco_code: string | null
  activity_code: string | null
  jmhz_relationship_detail_code: string | null
  social_insurance_participation: PayrollInsuranceParticipation
  health_insurance_participation: PayrollInsuranceParticipation
  tax_regime: PayrollTaxRegime
  // Nepovinné schválně: obrazovky, které pole nenabízejí, posílají podmínky bez
  // něj a server v takovém případě ponechá uloženou hodnotu (jinak by uložení
  // nesouvisející změny shodilo daňové zařazení jednatele na „neurčeno").
  other_withholding_eligibility?: PayrollOtherWithholdingEligibility
  foreign_legislation_country_code: string | null
  a1_certificate_until: string | null
  // Odvozený příznak, ne samostatné pole: server ho drží v souladu se sazbovou
  // kategorií (§ 5a odst. 1 písm. c) je riziková práce). JMHZ ho čte dál.
  risky_work: boolean
  social_employer_rate_category: PayrollSocialEmployerRateCategory
  social_employer_rate_category_evidence: string | null
  social_part_time_discount_reason: PayrollSocialPartTimeDiscountReason
  social_part_time_discount_evidence: string | null
  social_part_time_discount_notified_on: string | null
  tax_declaration_signed: boolean
  is_primary: boolean
  change_reason: string | null
  row_version: number
  created_at: string
}

export interface PayrollEmploymentChecklistItem {
  id: number
  phase: 'onboarding' | 'change' | 'offboarding'
  item_key: string
  status: PayrollChecklistStatus
  due_date: string | null
  completed_at: string | null
  note: string | null
  row_version: number
}

export interface PayrollEmploymentEvent {
  id: number
  event_type: 'created' | 'terms_changed' | 'status_changed' | 'checklist_changed'
  from_status: PayrollEmploymentStatus | null
  to_status: PayrollEmploymentStatus | null
  effective_on: string
  note: string | null
  diff: Record<string, { from: unknown; to: unknown }> | null
  created_at: string
}

/**
 * `risky_work` a sazbová kategorie § 5a odst. 1 popisují TUTÉŽ věc, takže se
 * posílá jedno z nich, ne obojí: obrazovka s výběrem kategorie pošle kategorii
 * a server z ní boolean dopočítá, starší obrazovka pošle boolean a server ho
 * na kategorii přeloží. Poslat obojí a nesouhlasně je chyba, ne tichá volba —
 * proto jsou obě strany nepovinné, ne obě povinné.
 */
export type PayrollSocialPartTimeDiscountReason =
  | 'none'
  | 'age_55_plus'
  | 'child_care_under_10'
  | 'dependent_close_person_care'
  | 'study_under_26'
  | 'retraining_jobseeker'
  | 'disabled_person'
  | 'under_21'

export type PayrollEmploymentTermsPayload = Omit<
  PayrollEmploymentTerms,
  'id' | 'office_code' | 'effective_to' | 'jmhz_external_codebook_overlay_key'
    | 'jmhz_external_codebook_manifest_sha256' | 'row_version' | 'created_at'
    | 'risky_work' | 'social_employer_rate_category'
    | 'social_employer_rate_category_evidence'
    | 'social_part_time_discount_reason' | 'social_part_time_discount_evidence'
    | 'social_part_time_discount_notified_on'
    | 'jmhz_orchard_discount_eligible' | 'jmhz_specific_legal_fact_applies'
    | 'jmhz_ozp_employment_support_applies' | 'jmhz_deep_mining_work_applies'
> & {
  risky_work?: boolean
  social_employer_rate_category?: PayrollSocialEmployerRateCategory
  social_employer_rate_category_evidence?: string | null
  // Nárok podle § 7a nabízí jen karta vztahu; obrazovky, které o něm nevědí,
  // ho neposílají a server je čte jako „sleva se neuplatňuje". Poslat prázdno
  // je proto v pořádku, poslat nesmysl ne.
  social_part_time_discount_reason?: PayrollSocialPartTimeDiscountReason
  social_part_time_discount_evidence?: string | null
  social_part_time_discount_notified_on?: string | null
  jmhz_orchard_discount_eligible?: boolean
  jmhz_specific_legal_fact_applies?: boolean
  jmhz_ozp_employment_support_applies?: boolean
  jmhz_deep_mining_work_applies?: boolean
}

export interface PayrollEmploymentCreatePayload {
  code: string
  relation_type: PayrollRelationType
  meal_entitlement_basis?: PayrollMealEntitlementBasis
  monthly_gross_minor: number | null
  terms: PayrollEmploymentTermsPayload
}

export type PayrollMealEntitlementBasis = 'shift' | 'calendar_day'

export interface PayrollPerson extends PayrollPersonListItem {
  employments: PayrollEmployment[]
}

export interface PayrollPeopleResponse {
  items: PayrollPersonListItem[]
  total: number
  limit: number
  offset: number
}

/** Zúžení seznamu osob. Zužuje server, aby hledání nekončilo na hraně stránky. */
export type PayrollPeopleFilter = 'all' | 'active' | 'needs_setup'

/** Osoba v rozbalovací nabídce — jen to, čím se dá vybrat. */
export interface PayrollPersonOption {
  id: number
  full_name: string
  is_active: boolean
  needs_setup: boolean
}

export interface PayrollPeopleOptionsResponse {
  items: PayrollPersonOption[]
}

export interface PayrollPersonResponse {
  person: PayrollPerson
}

export interface PayrollPersonCreatePayload {
  full_name: string
  birth_date: string | null
  birth_number: string | null
  relation_type: PayrollRelationType
  planned_start_on: string
  monthly_gross: number | null
  office_id?: number | null
  /** Týdenní pracovní doba; bez ní dosadí server plný úvazek 40.00. */
  weekly_hours?: string | null
  /**
   * Zdravotní pojišťovna osoby. Server ji zapíše jako zákonnou evidenci
   * (`health_coverages`) v TÉŽE transakci jako zaměstnance — neplatný kód proto
   * nezaloží ani osobu.
   */
  health_insurer_code?: string | null
}

export type PayrollPersonProfileStatus = 'missing' | 'legacy' | 'setup' | 'ready'
export type PayrollPersonEditableProfileStatus = Exclude<PayrollPersonProfileStatus, 'missing'>
export type PayrollPayoutMethod = 'cash' | 'bank' | 'mixed' | 'partner_settlement'
export type PayrollSecureDeliveryChannel = 'portal' | 'paper'
export type PayrollPersonAddressType = 'residence' | 'mailing'
export type PayrollPersonContactType = 'email' | 'phone'
export type PayrollPersonIdentifierType = 'birth_number' | 'ecp' | 'vcp' | 'foreign_tax_identifier'
export type PayrollPersonSex = 'female' | 'male' | 'unspecified'
export type PayrollPersonAccountVerificationSource =
  | 'employee_confirmation'
  | 'bank_document'
  | 'user_verified'

export interface PayrollPersonIdentityHistory {
  id: number
  full_name: string
  first_name: string | null
  last_name: string | null
  title_prefix: string | null
  title_suffix: string | null
  birth_surname_masked: string | null
  birth_date: string | null
  birth_place: string | null
  birth_country_code: string | null
  citizenship_country_code: string | null
  sex: PayrollPersonSex | null
  effective_from: string
  effective_to: string | null
  row_version: number
}

export interface PayrollPersonAddress {
  id: number
  address_type: PayrollPersonAddressType
  address_masked: string
  effective_from: string
  effective_to: string | null
  row_version: number
}

export interface PayrollPersonContact {
  id: number
  contact_type: PayrollPersonContactType
  value_masked: string
  is_primary: boolean
  is_active: boolean
  row_version: number
}

export interface PayrollPersonIdentifier {
  id: number
  identifier_type: PayrollPersonIdentifierType
  value_masked: string
  row_version: number
}

export interface PayrollPersonAccount {
  id: number
  label: string
  bank_account_masked: string
  allocation_basis_points: number
  effective_from: string
  effective_to: string | null
  is_active: boolean
  row_version: number
  verification_source: PayrollPersonAccountVerificationSource | null
  verified_on: string | null
  verified_by: number | null
}

export interface PayrollPersonVerifiedAccount {
  id: number
  bank_account_masked: string
  verification_source: PayrollPersonAccountVerificationSource
  verified_on: string
  verified_by: number
  row_version: number
}

/**
 * Odkryté citlivé údaje. Odpověď je `private, no-store` — nikam se neukládá,
 * žije jen v paměti otevřené karty.
 */
export interface PayrollPersonSensitiveReveal {
  employee_id: number
  identifiers: { id: number, identifier_type: PayrollPersonIdentifierType, value: string }[]
  contacts: { id: number, contact_type: PayrollPersonContactType, value: string }[]
  accounts: { id: number, label: string, bank_account: string }[]
  dependants: { id: number, full_name: string, birth_number: string }[]
  addresses: {
    id: number
    address_type: PayrollPersonAddressType
    address: string
    effective_from: string
    effective_to: string | null
  }[]
}

/**
 * Úhrny za jeden měsíc předchozího zpracování, v haléřích. Uživatel je opisuje
 * ze sestavy původního programu, server z nich složí roční kumulaci.
 */
export interface PayrollOpeningMonth {
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

export interface PayrollOpeningBalances {
  year: number
  months: PayrollOpeningMonth[]
  /** Id aktuální verze podle druhu kumulace; oprava se na ně navazuje. */
  openings: Record<string, number | null>
  /** Volitelná uživatelská dohledávka k převzatým úhrnům. */
  source_reference: string
  /** Po schválené mzdě za daný rok už počáteční stavy měnit nelze. */
  locked: boolean
}

/**
 * Zákonná evidence osoby — prohlášení k dani, daňová rezidence, sociální
 * a zdravotní příslušnost, sleva pracujícího důchodce a měsíční evidence
 * zdravotního minima.
 *
 * Řádky jsou časové řady, takže se posílají a vrací jako celé kolekce; server
 * si z cílového stavu spočítá rozdíl. Hodnoty jsou úmyslně `string | null` —
 * jde o výčty a reference, jejichž povolené hodnoty hlídá server (a validátor
 * mzdového snímku), ne prohlížeč.
 */
export interface PayrollStatutoryEvidenceRow {
  id?: number
  row_version?: number
  effective_from?: string
  effective_to?: string | null
  period_start?: string
  evidence_note?: string | null
  [field: string]: string | number | null | undefined
}

export type PayrollStatutoryEvidenceSection =
  | 'tax_declarations'
  | 'tax_residences'
  | 'social_jurisdictions'
  | 'social_discount_claims'
  | 'health_coverages'
  | 'health_month_evidence'

export interface PayrollStatutoryEvidence {
  employee_id: number
  effective_on: string
  /** Poslední den uzavřený schválenou mzdou; do něj se historie nepřepisuje. */
  frozen_through: string | null
  sections: Record<PayrollStatutoryEvidenceSection, PayrollStatutoryEvidenceRow[]>
  other_employer_bases: PayrollStatutoryEvidenceRow[]
  /**
   * Důvody, proč by mzdový běh k datu snímku skončil v ručním posouzení.
   * Klíče jsou tytéž, jaké hlásí `PayrollRunStatutoryInputAssembler`.
   */
  blockers: string[]
}

export interface PayrollStatutoryEvidencePayload {
  effective_on: string
  sections: Record<PayrollStatutoryEvidenceSection, PayrollStatutoryEvidenceRow[]>
}

export type PayrollForeignPermitKind = 'residence' | 'work'
export type PayrollForeignPermitStatus = 'future' | 'valid' | 'expiring' | 'expired' | 'superseded'

export interface PayrollForeignPermit {
  id: number
  permit_kind: PayrollForeignPermitKind
  permit_label: string
  issuing_country_code: string
  effective_from: string
  valid_until: string
  document_id: number | null
  supersedes_permit_id: number | null
  recorded_at: string
  status: PayrollForeignPermitStatus
}

export interface PayrollForeignPermitAlert {
  permit_id: number
  permit_kind: PayrollForeignPermitKind
  permit_label: string
  valid_until: string
  status: Extract<PayrollForeignPermitStatus, 'expiring' | 'expired'>
  days_remaining: number
}

export interface PayrollForeignPermitView {
  employee_id: number
  as_of: string
  warning_days: number
  history: PayrollForeignPermit[]
  alerts: PayrollForeignPermitAlert[]
}

export interface PayrollForeignPermitPayload {
  permit_kind: PayrollForeignPermitKind
  permit_label: string
  issuing_country_code: string
  effective_from: string
  valid_until: string
  document_id: number
  supersedes_permit_id?: number | null
}

export interface PayrollPersonProfile {
  employee_id: number
  full_name: string
  profile_status: PayrollPersonProfileStatus
  payout_method: PayrollPayoutMethod
  partner_settlement_account_code: string | null
  cash_allocation_basis_points: number
  payout_effective_on: string | null
  secure_delivery_channel: PayrollSecureDeliveryChannel
  row_version: number
  identity_history: PayrollPersonIdentityHistory[]
  addresses: PayrollPersonAddress[]
  contacts: PayrollPersonContact[]
  identifiers: PayrollPersonIdentifier[]
  accounts: PayrollPersonAccount[]
  created_at: string | null
  updated_at: string | null
}

export interface PayrollPersonIdentityPayload {
  id?: number
  full_name: string
  first_name: string
  last_name: string
  title_prefix?: string | null
  title_suffix?: string | null
  birth_surname?: string | null
  birth_surname_source_id?: number
  birth_date?: string | null
  birth_place?: string | null
  birth_country_code?: string | null
  citizenship_country_code?: string | null
  sex?: PayrollPersonSex | null
  effective_from: string
  effective_to: string | null
}

export interface PayrollPersonAddressPayload {
  id?: number
  address_type: PayrollPersonAddressType
  street_line?: string
  city?: string
  postal_code?: string
  country_code?: string
  effective_from: string
  effective_to: string | null
}

export interface PayrollPersonContactPayload {
  id?: number
  contact_type: PayrollPersonContactType
  value?: string | null
  is_primary: boolean
  is_active: boolean
}

export interface PayrollPersonIdentifierPayload {
  id?: number
  identifier_type: PayrollPersonIdentifierType
  value?: string | null
}

export interface PayrollPersonAccountPayload {
  id?: number
  label: string
  bank_account?: string | null
  allocation_basis_points: number
  effective_from: string
  effective_to: string | null
  is_active: boolean
}

export interface PayrollPersonProfilePayload {
  row_version: number
  profile_status: PayrollPersonEditableProfileStatus
  payout_method: PayrollPayoutMethod
  partner_settlement_account_code: string | null
  cash_allocation_basis_points: number
  payout_effective_on: string
  secure_delivery_channel: PayrollSecureDeliveryChannel
  identity_history: PayrollPersonIdentityPayload[]
  addresses: PayrollPersonAddressPayload[]
  contacts: PayrollPersonContactPayload[]
  identifiers: PayrollPersonIdentifierPayload[]
  accounts: PayrollPersonAccountPayload[]
}

export interface PayrollPersonQuickEditEmploymentPayload {
  id: number
  row_version: number
  monthly_gross_minor: number | null
  terms: PayrollEmploymentTermsPayload
}

export interface PayrollPersonQuickEditPayload {
  profile: PayrollPersonProfilePayload
  employment: PayrollPersonQuickEditEmploymentPayload | null
}

export interface PayrollPersonQuickEditResponse {
  profile: PayrollPersonProfile
  employment: PayrollEmployment | null
}

export interface PayrollPersonAccountVerificationPayload {
  verification_source: PayrollPersonAccountVerificationSource
  verified_on: string
  row_version: number
}

export type PayrollPayoutDestinationKind = 'bank' | 'cash' | 'partner_settlement'
export type PayrollPayoutAllocationKind = 'fixed' | 'percentage' | 'remainder'

/**
 * Výplatní pravidlo osoby — teprve ono říká, kam čistá mzda skutečně odejde.
 * `payout_method` na kartě je jen deklarace; bez pravidla se výplata nedá
 * zpracovat.
 *
 * `allocation_reference` generuje server (drží identitu vůči zmrazeným
 * alokacím), klient ji nikdy neposílá ani nemění.
 */
export interface PayrollPayoutRule {
  id: number
  supplier_id: number
  employee_id: number
  allocation_reference: string
  destination_kind: PayrollPayoutDestinationKind
  /** `account:<id>` u banky, kód účtu z osnovy u zápočtu, NULL u hotovosti. */
  destination_reference: string | null
  allocation_kind: PayrollPayoutAllocationKind
  amount_minor: number | null
  basis_points: number | null
  priority_no: number
  is_active: boolean
  /**
   * Je cíl pravidla ověřený? `null` u hotovosti a zápočtu na účet společníka —
   * tam ověření nedává smysl a `false` by se četlo jako vada.
   *
   * `false` neblokuje uložení pravidla (musí jít připravit dřív, než ověření
   * proběhne), ale mzdu na takový účet nepůjde připravit k výplatě.
   */
  destination_verified: boolean | null
  row_version: number
  created_at: string | null
  updated_at: string | null
}

/**
 * Nefatální nález nad pravidlem — zápis prošel, ale příprava plateb by na tom
 * spadla. Zpráva ze serveru je česky; panel si vykresluje vlastní i18n větu,
 * `warnings` je strojově čitelný kontrakt pro ostatní konzumenty API.
 */
export interface PayrollPayoutRuleWarning {
  code: 'unverified_destination'
  rule_id: number
  account_id: number | null
  message: string
}

export interface PayrollPayoutRuleProposalRule {
  destination_kind: PayrollPayoutDestinationKind
  destination_reference: string | null
  allocation_kind: PayrollPayoutAllocationKind
  amount_minor: number | null
  basis_points: number | null
  priority_no: number
}

export interface PayrollPayoutRuleProposal {
  payout_method: PayrollPayoutMethod | null
  available: boolean
  applicable: boolean
  has_active_rules: boolean
  /** Česká věta ze serveru — zobrazuje se uživateli tak, jak přijde. */
  blocked_reason: string | null
  rules: PayrollPayoutRuleProposalRule[]
}

export interface PayrollPayoutRulesResponse {
  rules: PayrollPayoutRule[]
  proposal: PayrollPayoutRuleProposal
  warnings: PayrollPayoutRuleWarning[]
}

export interface PayrollPayoutRulePayload {
  destination_kind: PayrollPayoutDestinationKind
  destination_reference: string | null
  allocation_kind: PayrollPayoutAllocationKind
  amount_minor?: number | null
  basis_points?: number | null
  priority_no: number
  is_active: boolean
}

export type PayrollTimeCategory =
  | 'regular'
  | 'overtime'
  | 'night'
  | 'weekend'
  | 'holiday'
  | 'difficult_environment'

export interface PayrollTimeMonthState {
  id: number | null
  employment_id: number
  period_start: string
  status: 'open' | 'approved'
  revision_no: number
  row_version: number
  approved_at: string | null
  reopened_at: string | null
  reopen_reason: string | null
}

export interface PayrollCalendarDay {
  date: string
  weekday: number
  is_weekend: boolean
  is_holiday: boolean
  day_kind: 'workday' | 'non_working' | 'holiday'
  planned_minutes: number
  holiday_code: string | null
  holiday_name: string | null
}

export interface PayrollWorkCalendar {
  id: number
  employment_id: number
  name: string
  timezone_name: string
  schedule_type: 'regular' | 'irregular' | 'shift'
  week_pattern: Record<string, number>
  weekly_minutes: number
  valid_from: string
  valid_to: string | null
  row_version: number
  fund_minutes: number
  days: PayrollCalendarDay[]
}

export interface PayrollShift {
  id: number
  employment_id: number
  calendar_id: number | null
  series_key: string
  revision_no: number
  starts_at: string
  ends_at: string
  timezone_name: string
  break_minutes: number
  net_minutes: number
  remote_work: boolean
  standby_minutes: number
  status: 'draft' | 'published'
  row_version: number
}

export interface PayrollTimeEntry {
  id: number
  employment_id: number
  series_key: string
  revision_no: number
  category: PayrollTimeCategory
  starts_at: string
  ends_at: string
  timezone_name: string
  break_minutes: number
  net_minutes: number
  source_kind: 'manual' | 'import' | 'schedule'
  status: 'draft' | 'approved'
  row_version: number
}

export interface PayrollJmhzWorkSummaryPreview {
  derivation_version: string
  control_catalog_key: string | null
  control_manifest_sha256: string | null
  source_snapshot_sha256: string
  suggestions: {
    standard_fund_hours: string | null
    agreed_fund_hours: string | null
    weekly_work_hours: string | null
    evidence_days: number
    worked_hours: string | null
  }
  issues: Array<{ code: string; message: string }>
  requires_unworked_hours_followup: boolean
}

export interface PayrollJmhzWorkSummaryRevision {
  id: number
  time_month_id: number
  time_month_revision_no: number
  derivation_version: string
  source_snapshot_sha256: string
  summary_sha256: string
  confirmation_note: string
  conditional_blocks_confirmed: 1 | null
  unworked_hours_occurred: 0 | 1 | null
  work_obstacles_occurred: 0 | 1 | null
  unworked_total_millihours: number | null
  unworked_paid_millihours: number | null
  dpn_without_employer_compensation_millihours: number | null
  dpn_with_employer_compensation_millihours: number | null
  vacation_millihours: number | null
  care_millihours: number | null
  employee_obstacle_paid_millihours: number | null
  employer_obstacle_millihours: number | null
  approved_at: string
}

export interface PayrollJmhzWorkSummaryApproval {
  source_snapshot_sha256: string
  standard_fund_hours: string
  agreed_fund_hours: string
  weekly_work_hours: string
  worked_hours: string
  unworked_hours_occurred: boolean
  work_obstacles_occurred: boolean
  unworked_total_hours: string | null
  unworked_paid_hours: string | null
  dpn_without_employer_compensation_hours: string | null
  dpn_with_employer_compensation_hours: string | null
  vacation_hours: string | null
  care_hours: string | null
  employee_obstacle_paid_hours: string | null
  employer_obstacle_hours: string | null
  confirmation_note?: string
}

/**
 * Stav limitů přesčasové práce podle § 93 zákoníku práce. Minuty, ne millihodiny —
 * shodně se zbytkem docházky.
 */
export interface PayrollOvertimeLimitFinding {
  code: string
  severity: 'warning' | 'info'
  message: string
  actual_minutes: number
  limit_minutes: number
  scope_from: string
  scope_to: string
  consent_evidenced: boolean
  /** Ustanovení, o které se nález opírá — zobrazuje se jako štítek u věty. */
  provision: string
  /** Porušený zákaz, ne překročený limit: bez ruční výjimky běh neschválíte. */
  requires_override: boolean
}

export type PayrollOvertimeAveragingBasis = 'statutory' | 'collective_agreement'

export interface PayrollOvertimeLimits {
  employment_id: number
  findings: PayrollOvertimeLimitFinding[]
  weeks: Array<{ week_start: string; week_end: string; minutes: number }>
  ordered_year_minutes: number
  ordered_year_limit_minutes: number
  agreed_year_minutes: number
  averaging_from: string | null
  averaging_to: string | null
  averaging_weeks: number
  averaging_minutes: number
  averaging_limit_minutes: number
  averaging_compensated_minutes: number
  averaging_basis: PayrollOvertimeAveragingBasis
  averaging_reference: string | null
  prohibited_minutes: Partial<Record<'juvenile' | 'pregnancy' | 'child_under_one' | 'part_time', number>>
  requires_override: boolean
  consent_evidenced: boolean
  limits_from_ruleset: boolean
}

/** Dohoda o práci přesčas nad nařízený rozsah (§ 93 odst. 3). */
export interface PayrollOvertimeConsent {
  id: number
  employment_id: number
  valid_from: string
  valid_to: string | null
  document_reference: string | null
  note: string | null
  row_version: number
  created_at: string
}

export type PayrollOvertimeProtectionKind = 'pregnancy' | 'child_under_one'

/** Zákaz práce přesčas u chráněné skupiny (§ 240 odst. 3). */
export interface PayrollOvertimeProtection {
  id: number
  employment_id: number
  protection: PayrollOvertimeProtectionKind
  valid_from: string
  valid_to: string | null
  document_reference: string | null
  note: string | null
  row_version: number
  created_at: string
}

/** Náhradní volno za práci přesčas (§ 93 odst. 5). */
export interface PayrollOvertimeCompensation {
  id: number
  employment_id: number
  overtime_date: string
  minutes: number
  granted_on: string | null
  document_reference: string | null
  note: string | null
  row_version: number
  created_at: string
}

/** Vyrovnávací období podle § 93 odst. 4 — firemní údaj, ne konstanta. */
export interface PayrollOvertimeAveragingPeriod {
  id: number
  valid_from: string
  valid_to: string | null
  weeks: number
  basis: PayrollOvertimeAveragingBasis
  collective_agreement_reference: string | null
  note: string | null
  row_version: number
  created_at: string
}

export interface PayrollTimeOverviewItem {
  employment: {
    id: number
    employee_id: number
    code: string
    relation_type: PayrollRelationType
    status: string
    start_date: string | null
    end_date: string | null
    full_name: string
  }
  calendar: PayrollWorkCalendar | null
  month: PayrollTimeMonthState
  summary: {
    fund_minutes: number
    planned_minutes: number
    actual_minutes: number
    difference_minutes: number
    category_minutes: Record<PayrollTimeCategory, number>
    incomplete: boolean
  }
  jmhz_work_summary: {
    preview: PayrollJmhzWorkSummaryPreview | null
    current_revision: PayrollJmhzWorkSummaryRevision | null
  }
  overtime_limits: PayrollOvertimeLimits | null
  overtime_consents: PayrollOvertimeConsent[]
  overtime_protections: PayrollOvertimeProtection[]
  overtime_compensations: PayrollOvertimeCompensation[]
  /**
   * Porovnání dvou evidencí náhradního volna za měsíc: absence typu
   * `compensatory_time_off` (den čerpání) proti `payroll_overtime_compensations`
   * (den přesčasu). Sjednotit je nejde — mají jiný klíč — ale rozpor mezi nimi
   * nesmí zůstat tichý.
   */
  compensatory_time_off_check: PayrollCompensatoryTimeOffCheck | null
  shifts: PayrollShift[]
  entries: PayrollTimeEntry[]
}

export type PayrollCompensatoryTimeOffFinding =
  | 'absence_without_compensation'
  | 'compensation_without_absence'
  | 'grant_date_unknown'

export interface PayrollCompensatoryTimeOffCheck {
  employment_id: number
  period: string
  status: 'ok' | PayrollCompensatoryTimeOffFinding
  findings: PayrollCompensatoryTimeOffFinding[]
  absence_rows: number
  granted_rows: number
  granted_minutes: number
  ungranted_rows: number
}

export interface PayrollTimeOverview {
  period: string
  incomplete_only: boolean
  /** Zúžení na jeden vztah, které server SKUTEČNĚ uplatnil (§ zúžení z karty). */
  employment_id: number | null
  items: PayrollTimeOverviewItem[]
  total: number
  limit: number
  offset: number
}

export interface PayrollTimeImportError {
  row_number: number
  error_code: string
  field_name: string | null
  error_message: string
}

export interface PayrollTimeImportPreview {
  format: 'csv' | 'xlsx'
  supported: boolean
  status: 'preview'
  period: string
  original_name: string
  total_rows: number
  accepted_rows: number
  rejected_rows: number
  duplicate_rows: number
  errors: PayrollTimeImportError[]
}

export type PayrollComponentKind =
  | 'base_wage'
  | 'hourly_wage'
  | 'task_wage'
  | 'bonus'
  | 'premium'
  | 'commission'
  | 'allowance'
  | 'compensation'
  | 'severance'
  | 'competitive_clause'
  | 'backpay'
  | 'non_cash'
  | 'benefit_meal'
  | 'benefit_vehicle'
  | 'benefit_pension'
  | 'benefit_care'
  | 'benefit_education'
  | 'benefit_recreation'
  | 'benefit_health'
  | 'benefit_accommodation'
  | 'risky_savings'
  | 'travel_reimbursement'
  | 'other'

export type PayrollComponentValueKind = 'monetary' | 'non_monetary'
export type PayrollComponentFrequency = 'regular' | 'one_off'
export type PayrollComponentTaxTreatment = 'included' | 'exempt' | 'withholding_candidate' | 'manual_review'
export type PayrollComponentInclusion = 'included' | 'excluded' | 'manual_review'

/**
 * Koš osvobození plnění podle § 6 odst. 9 ZDP. Limit platí na ÚHRN plnění za dané
 * ustanovení, ne na jednu mzdovou složku. Rozhodné období je roční u písm. d) a m),
 * měsíční u písm. i) a za jednu směnu u písm. b).
 */
export type PayrollBenefitExemptionBasket =
  | 'non_cash_health'
  | 'non_cash_leisure'
  | 'old_age_savings'
  | 'meal_per_shift'
  | 'temporary_accommodation'

/**
 * Čím je nezdanění složky podložené. `not_subject_to_tax` NENÍ osvobození —
 * plnění podle § 6 odst. 7 ZDP předmětem daně vůbec není a na mzdovém listu
 * se mezi osvobozené částky nevykazuje.
 */
export type PayrollExemptionBasis =
  | 'not_subject_to_tax'
  | 'statutory_exempt'
  | 'benefit_basket'
  | 'periodic_benefit_limit'

export interface PayrollMealShiftEntitlement {
  period_start: string
  basis: 'shift' | 'calendar_day' | 'mixed'
  qualifying_count: number
  second_contribution_count: number
  count: number
  complete: boolean
  missing: string[]
}

export interface PayrollBenefitBasketUsage {
  basket: PayrollBenefitExemptionBasket
  statute: string
  /**
   * Počet směn s nárokem, ze kterých se strop poskládal. `null` u košů, jejichž
   * limit na směnách nestojí — nula by tvrdila, že se nic neodpracovalo.
   */
  shift_entitlements: number | null
  entitlement?: PayrollMealShiftEntitlement | null
  limit_minor: number
  used_before_minor: number
  used_after_minor: number
  remaining_minor: number
  exempt_minor: number
  taxable_minor: number
  limit_exceeded: boolean
  allocation?: {
    mode: 'uniform_per_entitlement' | 'no_entitlement'
    entitlement_count: number
    amount_per_entitlement_minor: number
    limit_per_entitlement_minor: number
    exempt_per_entitlement_minor: number
    taxable_per_entitlement_minor: number
  } | null
}

export interface PayrollComponent {
  id: number
  supplier_id: number
  code: string
  name: string
  component_kind: PayrollComponentKind
  value_kind: PayrollComponentValueKind
  frequency_kind: PayrollComponentFrequency
  tax_treatment: PayrollComponentTaxTreatment
  social_participation_treatment: PayrollComponentInclusion
  social_treatment: PayrollComponentInclusion
  health_participation_treatment: PayrollComponentInclusion
  health_treatment: PayrollComponentInclusion
  average_earning_treatment: PayrollComponentInclusion
  enforcement_treatment: PayrollComponentInclusion
  jmhz_treatment: PayrollComponentInclusion
  statistics_treatment: PayrollComponentInclusion
  accounting_debit_code: string | null
  accounting_credit_code: string | null
  annual_limit_minor: number | null
  exemption_basket: PayrollBenefitExemptionBasket | null
  exemption_basis: PayrollExemptionBasis | null
  valid_from: string
  valid_to: string | null
  is_active: boolean
  row_version: number
  created_at: string
  updated_at: string
}

export type PayrollComponentPayload = Omit<
  PayrollComponent,
  'id' | 'supplier_id' | 'row_version' | 'created_at' | 'updated_at'
>

export interface PayrollRiskySavingsItem {
  id: number
  employment_id: number
  period_start: string
  revision_no: number
  risk_factor: PayrollRiskySavingsRiskFactor
  work_category: 3
  qualifying_shift_eighths: number
  right_claimed_on: string
  employee_informed_on: string | null
  pension_company: string
  institution_account_id: number
  institution_account_masked: string | null
  institution_account_row_version: number | null
  institution_account_hash: string | null
  payment_target_name: string | null
  product_reference: string
  variable_symbol: string | null
  specific_symbol: string | null
  payment_message: string | null
  evidence_reference: string | null
  status: 'draft' | 'approved'
  row_version: number
  full_name: string
  employment_code: string
  contribution_id: number | null
  revision_id: number | null
  assessment_base_minor: number | null
  contribution_minor: number | null
  payment_due_on: string | null
  paid_on: string | null
  contribution_status: 'approved' | 'paid' | null
}

export type PayrollRiskySavingsRiskFactor =
  | 'vibration'
  | 'cold'
  | 'heat'
  | 'dynamic_physical_load'

export interface PayrollRiskySavingsEvidencePayload {
  employment_id: number
  period: string
  source_evidence_id: number | null
  row_version: number | null
  risk_factor: PayrollRiskySavingsRiskFactor
  qualifying_shift_eighths: number
  right_claimed_on: string
  employee_informed_on: string | null
  pension_company: string
  institution_account_id: number
  product_reference: string
  variable_symbol: string | null
  specific_symbol: string | null
  payment_message: string | null
  evidence_reference: string | null
  approve: boolean
}

export interface PayrollComponentJmhzTarget {
  attribute_id: string
  name: string
  xsd_mapping: string
  data_type: string
  monthly_marker: string
  parent_attribute_id: string | null
  ancestor_attribute_ids: string[]
  aggregation_role: 'detail' | 'catch_all_total'
  aggregation_scope: 'employment' | 'employee_summary'
}

export type PayrollVerifiedTriState = 'unverified' | 'no' | 'yes'

export interface PayrollEmploymentJmhzEvidenceOptions {
  package_key: string
  manifest_sha256: string
  external_codebooks: {
    overlay_key: string
    manifest_sha256: string
    snapshot_date: string
    effective_from: string
    verified_through: string
    base_spec_manifest_sha256: string
  }
  activity_codes: Array<{
    code: string
    label: string
    relationship_detail_mode: 'forbidden' | 'select' | 'fixed_none'
  }>
  relationship_detail_codes: Array<{ code: string; label: string }>
  apz_instruments: Array<{ code: string; label: string }>
  countries: Array<{ code: string; label: string }>
}

export interface PayrollJmhzMunicipalityOption {
  code: string
  label: string
}

/** Položka klasifikace zaměstnání CZ-ISCO tak, jak ji vrací našeptávač. */
export interface PayrollCzIscoOption {
  code: string
  label: string
  /** 4 = podskupina, 5 = kategorie. Jiné úrovně endpoint nenabízí. */
  level: number
  parent_code: string | null
  parent_label: string | null
}

/** Provenience připnutého číselníku ČSÚ — do UI jde jako popisek pod polem. */
export interface PayrollCzIscoCodebookInfo {
  package_key: string
  manifest_sha256: string
  classification_version: string
  effective_from: string
  legal_basis: string
  licence: string
  licence_url: string
  source_url: string
  entry_count: number
}

export interface PayrollCzIscoSearchResult {
  items: PayrollCzIscoOption[]
  codebook: PayrollCzIscoCodebookInfo
}

export interface PayrollComponentJmhzMapping {
  id: number
  component_definition_id: number
  package_key: string
  spec_manifest_sha256: string
  target_attribute_id: string
  target_attribute_name: string
  target_xsd_mapping: string
  is_active: boolean
  disabled_at: string | null
  row_version: number
  parent_attribute_id: string | null
  ancestor_attribute_ids: string[]
  aggregation_role: 'detail' | 'catch_all_total' | null
  aggregation_scope: 'employment' | 'employee_summary' | null
  topology_hash: string | null
  is_current_package: boolean
}

export type PayrollComponentJmhzMappingStatus =
  | 'configured'
  | 'missing'
  | 'excluded'
  | 'manual_review'

export interface PayrollComponentJmhzMappingState {
  component_id: number
  jmhz_treatment: PayrollComponentInclusion
  status: PayrollComponentJmhzMappingStatus
  mapping: PayrollComponentJmhzMapping | null
}

export type PayrollRecurringCalculationKind =
  | 'fixed_amount'
  | 'employment_gross_basis_points'
  | 'manual_review'

export type PayrollRecurringAllocationRule =
  | 'full_month'
  | 'calendar_days'
  | 'working_days'
  | 'hours'
  | 'manual_review'

export interface PayrollRecurringComponent {
  id: number
  supplier_id: number
  employee_id: number
  employment_id: number
  employment_code: string
  employee_name: string
  component_id: number
  component_code: string
  component_name: string
  calculation_kind: PayrollRecurringCalculationKind
  amount_minor: number | null
  rate_basis_points: number | null
  valid_from: string
  valid_to: string | null
  allocation_rule: PayrollRecurringAllocationRule
  maximum_amount_minor: number | null
  note: string | null
  is_active: boolean
  row_version: number
  created_by: number | null
  updated_by: number | null
  created_at: string
  updated_at: string
}

export interface PayrollRecurringComponentPayload {
  employment_id: number
  component_id: number
  calculation_kind: PayrollRecurringCalculationKind
  amount_minor: number | null
  rate_basis_points: number | null
  valid_from: string
  valid_to: string | null
  allocation_rule: PayrollRecurringAllocationRule
  maximum_amount_minor: number | null
  note: string | null
  is_active: boolean
}

// `travel` vzniká materializací schváleného vyúčtování pracovní cesty
// (BusinessTripMaterializer, migrace 1308) — do vstupů se dostane bez zásahu
// uživatele, takže ho klient musí znát i popsat.
export type PayrollInputSourceKind = 'manual' | 'recurring' | 'time' | 'absence' | 'import' | 'correction' | 'travel'
export type PayrollInputStatus = 'draft' | 'approved' | 'locked' | 'cancelled'

export interface PayrollInput {
  id: number
  supplier_id: number
  employee_id: number
  employee_name: string
  employment_id: number
  employment_code: string
  relation_type: PayrollRelationType
  component_id: number
  component_code: string
  component_name: string
  component_kind: PayrollComponentKind
  value_kind: PayrollComponentValueKind
  period_start: string
  source_period_start: string | null
  amount_minor: number
  quantity_milliunits: number | null
  source_kind: PayrollInputSourceKind
  external_id: string | null
  import_id: number | null
  recurring_component_id?: number | null
  status: PayrollInputStatus
  component_snapshot_json: string | null
  /** Zmrazený koš osvobození § 6 odst. 9 ZDP — vyplní se až schválením vstupu. */
  benefit_basket?: string | null
  benefit_exempt_minor?: number | null
  benefit_taxable_minor?: number | null
  row_version: number
  created_by: number | null
  approved_by: number | null
  approved_at: string | null
  created_at: string
  updated_at: string
}

export interface PayrollInputPayload {
  employee_id: number
  employment_id: number
  component_id: number
  period: string
  source_period: string | null
  amount_minor: number
  quantity_milliunits: number | null
  source_kind: PayrollInputSourceKind
  external_id: string | null
}

export interface PayrollQuickInputRef {
  id: number
  amount_minor: number
  quantity_milliunits: number | null
  source_kind: PayrollInputSourceKind
  status: PayrollInputStatus
  row_version: number
  source_snapshot: Record<string, unknown> | null
}

export interface PayrollQuickInputRow {
  employee_id: number
  employment_id: number
  employment_row_version: number
  full_name: string
  birth_number_masked: string | null
  employment_code: string
  relation_type: PayrollRelationType
  effective_status: PayrollEmploymentStatus
  suspended_in_month: boolean
  /** Lehký příznak pro přehled; úplné absence se vracejí jen kartám stránky. */
  away_in_month?: boolean
  base_amount_minor: number
  base_managed_elsewhere: boolean
  base_conflict: boolean
  partial_month: boolean
  base_requires_entry: boolean
  overtime_mode: 'hours' | 'amount'
  overtime_hours_milli: number | null
  overtime_amount_minor: number
  overtime_hourly_rate_minor: number | null
  overtime_average_snapshot_id: number | null
  overtime_average_snapshot_version: number | null
  overtime_hours_available: boolean
  overtime_hours_relation_supported: boolean
  overtime_managed_elsewhere: boolean
  overtime_conflict: boolean
  bonus_amount_minor: number
  bonus_managed_elsewhere: boolean
  bonus_conflict: boolean
  other_amount_minor: number
  non_monetary_amount_minor: number
  excluded_from_gross_amount_minor: number
  gross_preview_minor: number
  inputs: {
    base: PayrollQuickInputRef | null
    overtime: PayrollQuickInputRef | null
    bonus: PayrollQuickInputRef | null
  }
  blockers: string[]
}

export interface PayrollQuickInputMonth {
  period: string
  items: PayrollQuickInputRow[]
  /** Počet vztahů v měsíci; `items` je jen aktuální stránka. */
  total: number
}

export type PayrollEmployeeCardStatusFilter = 'active' | 'away' | 'attention' | 'all'

export interface PayrollEmployeeCardAbsence {
  id: number
  employment_id: number
  absence_type: string
  date_from: string
  date_to: string
  status: 'requested' | 'approved'
}

export interface PayrollEmployeeCardRow extends PayrollQuickInputRow {
  absences: PayrollEmployeeCardAbsence[]
}

export interface PayrollEmployeeCardMonth {
  period: string
  items: PayrollEmployeeCardRow[]
  /** Počet vztahů odpovídajících serverovému filtru. */
  total: number
  /** Všechny osoby firmy, včetně těch bez účinného vztahu v měsíci. */
  company_headcount: number
  /** Souhrn celého měsíce, nikoli jen zobrazené stránky nebo filtru. */
  summary: {
    people: number
    gross_preview_minor: number
    away: number
    attention: number
  }
}

export interface PayrollQuickInputSavePayload {
  period: string
  rows: Array<{
    employment_id: number
    employment_row_version: number
    /** null = pole zůstalo prázdné; 0 = uživatel zadal nulový základ. */
    base_amount_minor: number | null
    overtime_mode: 'hours' | 'amount'
    overtime_hours_milli: number | null
    overtime_amount_minor: number | null
    overtime_average_snapshot_id: number | null
    overtime_average_snapshot_version: number | null
    bonus_amount_minor: number
    versions: {
      base: number | null
      overtime: number | null
      bonus: number | null
    }
  }>
}

export interface PayrollInputImpactMoney {
  minor_units: number
  currency: string
}

export interface PayrollInputPreview {
  support_status: PayrollSupportStatus
  blocker: string | null
  component_snapshot: Record<string, unknown>
  impact: Record<string, PayrollInputImpactMoney> | null
  annual_limit_minor: number | null
  annual_used_minor: number
  annual_after_minor: number
  annual_limit_exceeded: boolean
  exemption_basket: PayrollBenefitBasketUsage | null
  meal_entitlement?: PayrollMealShiftEntitlement | null
}

export interface PayrollRecurringMaterialization {
  period: string
  created_count: number
  replayed_count: number
  manual_review_count: number
  created: Array<{ recurring_component_id: number; input_id: number; amount_minor: number }>
  replayed: Array<{ recurring_component_id: number; input_id: number; amount_minor: number }>
  manual_review: Array<{
    recurring_component_id: number
    employment_id: number
    component_id: number
    reason: string
  }>
}

export interface PayrollInputImportIssue {
  row_number: number
  error_code: string
  field_name: string | null
  error_message: string
}

export interface PayrollInputImportPreviewRow {
  row_number: number
  payload: Record<string, unknown>
  impact: PayrollInputPreview
}

export interface PayrollInputImportPreview {
  format: 'csv' | 'xlsx'
  source_name: string
  period: string
  content_hash: string
  row_count: number
  accepted_count: number
  rejected_count: number
  duplicate_count: number
  rows: PayrollInputImportPreviewRow[]
  errors: PayrollInputImportIssue[]
  duplicates: PayrollInputImportIssue[]
}

export interface PayrollInputImportRow {
  id: number
  source_row_number: number
  external_id: string | null
  status: 'valid' | 'error' | 'accepted' | 'duplicate'
  input_id: number | null
  normalized_payload: Record<string, unknown>
  errors: Array<{ code: string; field: string | null; message: string }>
  created_at: string
}

export interface PayrollInputImportResult {
  id: number
  supplier_id: number
  period_start: string
  source_kind: 'csv' | 'xlsx' | 'api'
  source_name: string
  content_hash: string
  status: 'preview' | 'accepted' | 'partial' | 'rejected'
  row_count: number
  accepted_count: number
  rejected_count: number
  duplicate_count: number
  row_version: number
  accepted_at: string | null
  created_by: number | null
  created_at: string
  replayed: boolean
  rows: PayrollInputImportRow[]
}

export interface PayrollInputImportPayload {
  period: string
  format: 'csv' | 'xlsx'
  source_name: string
  content_base64: string
}

export interface PayrollEmployerAccounts {
  employment_gross_debit: string
  employment_gross_credit: string
  partner_gross_debit: string
  partner_gross_credit: string
  statutory_gross_debit: string
  statutory_gross_credit: string
  employer_insurance_debit: string
  social_insurance_credit: string
  health_insurance_credit: string
  income_tax_credit: string
  other_deductions_credit: string
  partner_settlement_credit: string
}

export interface PayrollAccountOption {
  id: number
  account_code: string
  name: string
  account_type: 'expense' | 'liability'
  is_synthetic: boolean
  parent_id: number | null
  is_active: boolean
}

export interface PayrollOffice {
  id: number
  code: string
  name: string
  social_security_variable_symbol: string | null
  is_active: boolean
  row_version: number
}

export interface PayrollOfficeRegistration {
  id: number
  office_id: number
  effective_from: string
  social_security_variable_symbol: string
  source_reference: string
  created_by: number | null
  created_at: string
}

export interface PayrollEmployerSettings {
  supplier_id: number
  row_version: number
  employer_registration_number: string | null
  social_security_office_code: string | null
  default_health_insurer_code: string | null
  payroll_contact_name: string | null
  payroll_contact_email: string | null
  payroll_contact_phone: string | null
  default_office_code: string | null
  accounts: PayrollEmployerAccounts
  offices: PayrollOffice[]
  created_at: string | null
  updated_at: string | null
}

export interface PayrollEmployerSettingsResponse {
  settings: PayrollEmployerSettings
}

export interface PayrollOfficePayload {
  code: string
  name: string
  social_security_variable_symbol: string | null
  is_active: boolean
}

export interface PayrollEmployerSettingsPayload {
  row_version: number
  default_office_code: string
  employer_registration_number: string | null
  social_security_office_code: string | null
  default_health_insurer_code: string | null
  payroll_contact_name: string | null
  payroll_contact_email: string | null
  payroll_contact_phone: string | null
  accounts: PayrollEmployerAccounts
  offices: PayrollOfficePayload[]
}

export type PayrollRegzelEnvironment = 'production' | 'test'

export interface PayrollJmhzExternalIdentifierStatus {
  id: number
  value_masked: string
  valid_from: string
  valid_to: string | null
  source_kind: 'trusted_receipt' | 'verified_manual_import'
  row_version: number
}

export interface PayrollJmhzIdentityStatus {
  employee_id: number
  employment_id: number
  environment: PayrollRegzelEnvironment
  on_date: string
  person_external_identifier: PayrollJmhzExternalIdentifierStatus | null
  employment_external_identifier: PayrollJmhzExternalIdentifierStatus | null
}

export interface PayrollJmhzIdentityPayload {
  environment: PayrollRegzelEnvironment
  person_external_identifier?: string | null
  employment_external_identifier?: string | null
  valid_from: string
  source_reference?: string | null
  evidence_confirmed: true
}

export type PayrollSubmissionObligationStatus =
  | 'open'
  | 'prepared'
  | 'submitted'
  | 'fulfilled'
  | 'overdue'
  | 'cancelled'
  | 'manual_review'

export type PayrollSubmissionDeadlinePhase =
  | 'not_open'
  | 'open'
  | 'due_soon'
  | 'due_today'
  | 'overdue'
  | 'awaiting_result'
  | 'fulfilled'
  | 'action_required'
  | 'cancelled'

/** Skupina agend; klasifikuje ji server, klient ji z kódu agendy neodvozuje. */
export type PayrollSubmissionAgendaGroup = 'jmhz' | 'health' | 'other'

export interface PayrollSubmissionOverviewItem {
  id: number
  environment: PayrollRegzelEnvironment
  agenda_code: string
  agenda_group: PayrollSubmissionAgendaGroup
  subject_type: string
  subject_reference: string
  period_start: string
  period_end: string
  obligation_kind: string
  preferred_channel: string
  status: PayrollSubmissionObligationStatus
  row_version: number
  earliest_submission_on: string
  due_on: string
  calendar_basis: string
  deadline: {
    phase: PayrollSubmissionDeadlinePhase
    days_to_due: number
    is_action_required: boolean
    is_overdue: boolean
  }
  latest_submission: {
    id: number
    status: string
    submission_kind: string
    channel: string
    submitted_at: string | null
    decided_at: string | null
  } | null
}

export interface PayrollSubmissionOverviewResponse {
  environment: PayrollRegzelEnvironment
  period: string
  /** `null` = bez filtru; jinak platí `items`, `total` i oba souhrny pro tuhle skupinu. */
  agenda_group: PayrollSubmissionAgendaGroup | null
  summary: {
    total: number
    open: number
    prepared: number
    submitted: number
    fulfilled: number
    overdue: number
    manual_review: number
    other: number
  }
  deadline_summary: Record<PayrollSubmissionDeadlinePhase, number>
  items: PayrollSubmissionOverviewItem[]
  total: number
  limit: number
  offset: number
}

export interface PayrollOperationalHealth {
  document_batches: {
    queued: number
    running: number
    retry_wait: number
    failed: number
  }
  period_export_jobs: {
    queued: number
    processing: number
    retry_wait: number
    failed: number
  }
  submissions: {
    rejected: number
    correction_required: number
    open_blocker_or_error_issues: number
  }
  isds_outbox: {
    failed: number
    send_uncertain: number
    rejected: number
  }
  overdue_unpaid_liabilities: number
}

export type PayrollStatutoryAgendaCapability =
  | 'manual_review'
  | 'prepared_only'
  | 'not_supported'

export interface PayrollStatutoryAgendaCapabilityItem {
  agenda_code: 'NEMPRI' | 'HZUPN' | 'ELDP' | 'STATUTORY_ACCIDENT_INSURANCE'
  replacement_mode: 'fully_replaced' | 'partially_replaced' | 'standalone' | 'unknown'
  capability: PayrollStatutoryAgendaCapability
  transport_capability: 'not_supported'
  evidence_supported: boolean
  reason_code: string
  workflow_codes: string[]
}

export interface PayrollStatutoryObligationEvidence {
  id: number
  environment: PayrollRegzelEnvironment
  agenda_code: 'NEMPRI' | 'HZUPN' | 'STATUTORY_ACCIDENT_INSURANCE'
  employee_id: number | null
  full_name: string | null
  period_start: string
  period_end: string
  case_reference: string
  receipt_reference: string
  completed_on: string
  payment_amount_minor: number | null
  payment_currency: 'CZK' | null
  document_id: number
  document_title: string
  document_sha256: string
  capability_matrix_version: string
  capability_matrix_sha256: string
  attestation_version: string
  created_by: number
  created_at: string
}

export interface PayrollStatutoryObligationOverview {
  environment: PayrollRegzelEnvironment
  period: string
  matrix_version: string
  matrix_sha256: string
  agendas: PayrollStatutoryAgendaCapabilityItem[]
  evidence: PayrollStatutoryObligationEvidence[]
}

interface PayrollStatutoryObligationEvidencePayloadBase {
  environment: PayrollRegzelEnvironment
  period: string
  case_reference: string
  receipt_reference: string
  completed_on: string
  document_id: number
}

export type PayrollStatutoryObligationEvidencePayload =
  PayrollStatutoryObligationEvidencePayloadBase & (
    | {
      agenda_code: 'NEMPRI' | 'HZUPN'
      employee_id: number
      manual_submission_confirmed: true
    }
    | {
      agenda_code: 'STATUTORY_ACCIDENT_INSURANCE'
      payment_amount: string
      manual_payment_confirmed: true
    }
  )

export interface PayrollSubmissionDetail {
  submission: {
    id: number
    environment: PayrollRegzelEnvironment
    obligation_id: number
    agenda_code: string
    subject_type: string
    subject_reference: string
    period_start: string
    period_end: string
    submission_kind: string
    channel: string
    status: string
    row_version: number
    source_revision_id: number | null
    corrects_submission_id: number | null
    correlation_reference: string | null
    submitted_at: string | null
    decided_at: string | null
    created_at: string
    updated_at: string
  }
  parts: Array<{
    id: number
    part_reference: string
    agenda_code: string
    subject_reference: string
    status: string
    source_entity_type: string
    source_entity_reference: string
    row_version: number
    created_at: string
    updated_at: string
  }>
  artifacts: Array<{
    id: number
    part_id: number | null
    artifact_kind: string
    direction: string
    mime_type: string
    byte_size: number
    xsd_version: string | null
    catalog_version: string | null
    channel: string
    created_at: string
  }>
  receipts: Array<{
    id: number
    part_id: number | null
    artifact_id: number
    receipt_reference: string
    correlation_reference: string | null
    protocol_code: string
    remote_status: string | null
    verification_status: string
    received_at: string
    created_at: string
  }>
  issues: Array<{
    id: number
    part_id: number | null
    severity: string
    validation_stage: string
    issue_code: string
    entity_type: string | null
    entity_reference: string | null
    is_resolved: boolean
    row_version: number
    resolved_at: string | null
    created_at: string
    updated_at: string
  }>
}

export type PayrollSubmissionInboxProblemKind =
  | 'due_soon'
  | 'due_today'
  | 'overdue'
  | 'rejected'
  | 'waiting_for_identity'
  | 'manual_review'

export type PayrollSubmissionInboxEscalationLevel = 'due_soon' | 'due_today' | 'overdue'

export type PayrollSubmissionInboxStatus = 'open' | 'acknowledged' | 'snoozed' | 'resolved'

export interface PayrollSubmissionInboxItem {
  id: number
  obligation_id: number
  submission_id: number | null
  agenda_code: string
  subject_type: string
  subject_reference: string
  period_start: string
  period_end: string
  due_on: string
  problem_kind: PayrollSubmissionInboxProblemKind
  escalation_level: PayrollSubmissionInboxEscalationLevel
  status: PayrollSubmissionInboxStatus
  snoozed_until: string | null
  snooze_reason: string | null
  acknowledged_at: string | null
  resolved_at: string | null
  row_version: number
  created_at: string
  updated_at: string
}

/**
 * Výběr stavů inboxu. Filtruje SERVER, aby `total` popisoval právě ty řádky,
 * které stránka ukáže; `unresolved` je výchozí, protože inbox je pracovní
 * seznam. Vyřešená položka je doklad, že se problém vyřešil — proto jde
 * dohledat, ne že by se zahodila.
 */
export type PayrollSubmissionInboxStatusFilter = 'unresolved' | 'resolved' | 'all'

export interface PayrollSubmissionInboxResponse {
  environment: PayrollRegzelEnvironment
  status: PayrollSubmissionInboxStatusFilter
  summary: {
    total: number
    open: number
    acknowledged: number
    snoozed: number
  }
  items: PayrollSubmissionInboxItem[]
  total: number
  limit: number
  offset: number
}

export interface PayrollHealthPaymentOverview {
  schema_reference: 'payroll-health-payment-overview.v1'
  document_kind: 'internal_health_payment_overview'
  official_submission: {
    supported: false
    reason_code: string
  }
  supplier_id: number
  run_id: number
  revision_id: number
  revision_no: number
  period: string
  currency_code: 'CZK'
  insurer: {
    code: string
  }
  source: {
    statutory_result_id: number
    statutory_result_hash: string
    ruleset_id: string
    ruleset_hash: string
  }
  totals: {
    person_count: number
    assessment_base_minor_units: number
    employee_contribution_minor_units: number
    employer_contribution_minor_units: number
    total_contribution_minor_units: number
  }
  people: Array<{
    employee_reference: string
    display_name: string
    assessment_base_minor_units: number
    employee_contribution_minor_units: number
    employer_contribution_minor_units: number
    total_contribution_minor_units: number
  }>
  /** Živá read-only projekce platebního ledgeru; není součástí otisku PPZ. */
  payment_reconciliation: {
    liability_ids: number[]
    expected_minor: number
    liability_minor: number
    liability_difference_minor: number
    bank_settled_minor: number
    outgoing_remaining_minor: number
    incoming_remaining_minor: number
    bank_remaining_minor: number
    state: 'missing' | 'mismatch' | 'open' | 'partially_settled' | 'settled'
    closing_blocked: boolean
    blockers: Array<'liability_missing' | 'liability_difference' | 'bank_unsettled'>
  }
  sha256: string
  filename: string
}

/**
 * Mzdová účtárna = registrace u OSSZ. Přehled o výši pojistného se podává za
 * registraci, takže běh přes víc účtáren dá víc přehledů. `submittable` je
 * false, dokud účtárna nemá variabilní symbol zaměstnavatele.
 */
export interface PayrollJmhzPvpojOffice {
  office_id: number
  code: string
  name: string
  social_security_variable_symbol: string | null
  submittable: boolean
}

export interface PayrollJmhzCodebookEntry {
  item_code: string
  label: string
  ordinal: number
}

export interface PayrollJmhzEmployerAnnualEvidence {
  id: number
  report_year: number
  revision_no: number
  previous_revision_id: number | null
  schema_reference: string
  spec_manifest_sha256: string
  collective_agreement_types: string[]
  ownership_form: string
  average_headcount_hundredths: number
  average_disabled_headcount_hundredths: number
  disabled_share_hundredths: number
  ozp_reporting_office_id: number | null
  evidence_reference: string | null
  payload_sha256: string
  created_at: string
}

export interface PayrollJmhzEmployerAnnualEvidenceView {
  evidence: PayrollJmhzEmployerAnnualEvidence | null
  offices: Array<{ id: number; code: string; name: string }>
  collective_agreement_types: PayrollJmhzCodebookEntry[]
  ownership_forms: PayrollJmhzCodebookEntry[]
}

export interface PayrollJmhzEmployerAnnualEvidencePayload {
  expected_revision_id: number | null
  collective_agreement_types: string[]
  ownership_form: string
  average_headcount: string
  average_disabled_headcount: string
  ozp_reporting_office_id: number | null
  evidence_reference: string | null
}

export interface PayrollJmhzPvpojPreview {
  schema_reference: 'payroll-jmhz-pvpoj-preview.v1'
  document_kind: 'internal_jmhz_pvpoj_preview'
  workflow_status: 'preview_only'
  official_submission: {
    supported: false
    reason_code: string
  }
  xsd: {
    bundle_version: string
    schema_version: string
    entry_point: string
    namespace: string
  }
  supplier_id: number
  run_id: number
  revision_id: number
  revision_no: number
  period: string
  currency_code: 'CZK'
  office: {
    office_id: number
    code: string
    name: string
    variable_symbol: string
  }
  office_allocation: {
    method: string
    root_result_is_single_source_of_truth: boolean
    offices: Array<{
      office_id: number
      employee_contribution_minor_units: number
      employer_contribution_minor_units: number
      amount_minor_units: number
    }>
  }
  source: {
    revision_input_hash: string
    statutory_result_id: number
    statutory_result_hash: string
    ruleset_id: string
    ruleset_hash: string
  }
  pvpoj: {
    pojistne: {
      zakladZamestnavateleA: number
      pojistneZamestnavateleA: number
      pojistneZamestnavateleCelkem: number
      pojistneZamestnance: number
      pojistneCelkem: number
    }
    slevaZamestnavatele?: {
      pocetZamestnancu: number
      uhrnVymerovacichZakladu: number
      pojistneSleva: number
    }
    slevyZamestnancu?: {
      pocetZamestnancu: number
      uhrnVymerovacichZakladu: number
      pojistneSleva: number
    }
    pojistneUhrada: number
  }
  reconciliation: Array<{
    employee_reference: string
    relationship_references: string[]
    capped_assessment_base_minor_units: number
    employee_contribution_before_discount_minor_units: number
    employee_discount_minor_units: number
    employee_contribution_minor_units: number
  }>
  sha256: string
  filename: string
}

export interface PayrollJmhzOrdinaryEvidenceFacts {
  reportable_wage_deductions_recorded: boolean
  employee_social_discount_claimed: boolean
  specific_legal_fact_occurred: boolean
  ozp_employment_support_claimed: boolean
  deep_mining_work_occurred: boolean
}

/**
 * Pracovní vztah revize, za který se ordinary evidence potvrzuje.
 *
 * Evidence je zmrazená per vztah, takže revize s víc lidmi (a každá revize
 * přes dvě mzdové účtárny) potřebuje jedno potvrzení na každý řádek.
 */
export interface PayrollJmhzOrdinaryEvidenceScope {
  employee_id: number
  employment_id: number
  employee_name: string
  confirmed: boolean
  resolution: 'confirmed' | 'automatic_on_preparation' | 'attention_required'
  attention_code: string | null
  attention_message: string | null
}

export interface PayrollJmhzOrdinaryEvidenceState {
  scopes: PayrollJmhzOrdinaryEvidenceScope[]
  evidences: PayrollJmhzOrdinaryEvidence[]
}

export interface PayrollJmhzOrdinaryEvidence {
  id: number
  run_id: number
  revision_id: number
  revision_no: number
  employee_id: number
  employment_id: number
  period_start: string
  schema_reference: 'payroll-jmhz-ordinary-evidence.v1'
  source_manifest_sha256: string
  facts: PayrollJmhzOrdinaryEvidenceFacts
  source_kind: 'explicit_confirmation' | 'derived_from_frozen_payroll_sources'
  confirmed_at: string
  created_at: string
  created: boolean
}

export interface PayrollJmhzPreparation {
  id: number
  environment: 'test' | 'production'
  run_id: number
  source_revision_id: number
  period_start: string
  scenario_key: string
  builder_version: string
  readiness_status: 'blocked' | 'source_ready'
  issue_count: number
  issues: Array<{
    code: string
    entity_type: string
    count: number
    attribute_ids: string[]
  }>
  source_manifest_sha256: string
  readiness_sha256: string
  snapshot_fingerprint: string
  official_submission_supported: false
  created: boolean
}

export interface PayrollJmhzFrozenSubmission {
  submission_id: number
  part_id: number
  artifact_id: number
  status: string
  row_version: number
  environment: PayrollJmhzTransportEnvironment
  source_snapshot_hash: string
  artifact_sha256: string
  created: boolean
  submission_guid: string
  variable_symbol: string
}

export interface PayrollJmhzIsdsRecipient {
  environment: PayrollJmhzTransportEnvironment
  box_id: string
  name: string
  note: string
}

export interface PayrollJmhzIsdsEnqueueResult {
  outbox_id: number
  created: boolean
  environment: PayrollJmhzTransportEnvironment
  recipient: PayrollJmhzIsdsRecipient
  subject: string
  sender_ident: string
  attachment: {
    filename: string
    mime: string
    sha256: string
    bytes: number
  }
  transport: {
    automatic: boolean
    channel: 'gateway' | 'manual_upload'
    reason: string | null
  }
  response_hint: {
    subject_prefix: string
    attachment_prefix: string
    note: string
  }
}

export interface PayrollJmhzXmlDryRunBlocker {
  code: string
  entity_type: string
  entity_id: number | null
  attribute_ids: string[]
}

/**
 * `not_evaluable` a `unverifiable` se nesmí slít: první znamená, že kontrolu
 * lokálně vyhodnotit NELZE (rozhodne až protokol ČSSZ) a odeslání nebrání,
 * druhé že vyhodnotit ji lze, ale chybí předpoklad — a odeslání brání.
 * Backend je rozlišuje v `JmhzControlOutcome` a `counts` klíčuje všemi.
 */
export type PayrollJmhzControlOutcome =
  | 'passed'
  | 'failed'
  | 'not_applicable'
  | 'not_evaluable'
  | 'unverifiable'
  | 'unimplemented'

export interface PayrollJmhzControlFinding {
  control_id: number
  name: string
  outcome: PayrollJmhzControlOutcome
  scope: string
  passability: 'blocking' | 'passable' | 'unavailable'
  technical: boolean
  part: string
  form_ordinal: number | null
  message: string
  attribute_ids: string[]
  error_code: number | null
}

export interface PayrollJmhzControlReport {
  schema_reference: string
  catalog_key: string
  catalog_manifest_sha256: string
  submittable: boolean
  counts: Record<PayrollJmhzControlOutcome, number>
  deviations: { control_id: number, reason: string }[]
  blocking: PayrollJmhzControlFinding[]
  warnings: PayrollJmhzControlFinding[]
  coverage_gaps: PayrollJmhzControlFinding[]
  evaluated: PayrollJmhzControlFinding[]
}

export interface PayrollJmhzXmlDryRun {
  status: 'blocked' | 'dry_run_valid' | 'dry_run_incomplete'
  preparation_id: number
  blockers: PayrollJmhzXmlDryRunBlocker[]
  controls?: PayrollJmhzControlReport
  deadline?: {
    period_start: string
    earliest_submission_on: string
    due_on: string
    calendar_basis: string
    ruleset_id: string
  } | null
  xml?: string
  xml_sha256?: string
  schema?: {
    package_key: string
    data_version: string
    bundle_sha256: string
    document_sha256: string
  }
  official_submission: {
    supported: false
    reason_code: string
    reason: string
  }
}

/** PREZEC26 = částečné přihlášení před nástupem, REGZEC25 = plná registrace. */
export type PayrollRegistrationAgenda = 'PREZEC26' | 'REGZEC25'

export interface PayrollRegistrationDeadline {
  earliest_registration_on: string
  due_on: string
  calendar_basis: string
  ruleset_id: string
}

export interface PayrollRegistrationEmployerDeadline {
  earliest_registration_on: string
  due_on: string
  deemed_employer_from: string
  no_show_notification_due_on: string
  calendar_basis: string
  ruleset_id: string
}

export interface PayrollRegistrationPreview {
  employment_id: number
  agenda_code: PayrollRegistrationAgenda
  interaction: string
  action_code: number
  xml: string
  xml_sha256: string
  deadline: PayrollRegistrationDeadline
  employer_registration: PayrollRegistrationEmployerDeadline | null
  official_submission: { supported: false, reason: string }
}

export interface PayrollRegistrationSubmission {
  submission_id: number
  obligation_id: number
  part_id: number
  artifact_id: number
  /** Nejdál `ready`. „Připraveno" není „přihlášeno". */
  status: string
  row_version: number
  environment: string
  agenda_code: PayrollRegistrationAgenda
  interaction: string
  artifact_sha256: string
  created: boolean
  deadline: PayrollRegistrationDeadline
}

export type PayrollRegistrationEventInteraction =
  | 'termination'
  | 'change'
  | 'correction'
  | 'variable_symbol_transfer'
  | 'czech_legislation_start'
  | 'czech_legislation_end'
  | 'cancellation'

export interface PayrollRegistrationEvent {
  id: number
  employment_id: number
  environment: PayrollJmhzTransportEnvironment
  interaction: PayrollRegistrationEventInteraction
  action_code: 2 | 3 | 4 | 5 | 6 | 7 | 8
  effective_on: string
  source_kind: string
  source_reference: string
  snapshot_fingerprint: string
  approved_at: string
  consumed: boolean
  created: boolean
}

export interface PayrollRegistrationPensionPeriodInput {
  from: string
  to: string
}

export interface PayrollRegistrationEventInput {
  environment: PayrollJmhzTransportEnvironment
  interaction: PayrollRegistrationEventInteraction
  effective_on: string
  source_reference?: string
  ended_by_death?: boolean
  unemployment?: {
    mode?: 'provided' | 'not_provided_2' | 'not_provided_3'
    early_termination_reason?: string
    average_net_earnings?: string
    pension_periods?: PayrollRegistrationPensionPeriodInput[]
    employment_type?: '1' | '2'
    termination_reason?: string
    service_termination_reason?: string
    entitlement?: boolean
    paid_in_full?: boolean
    replacement?: string
    golden_handshake?: string
    severance_pay?: string
    disposal?: string
  }
  changes?: Record<string, unknown>
  corrections?: Record<string, unknown>
  discovered_on?: string
  source_submission_id?: number
  new_variable_symbol?: string
  foreign_insurance?: {
    current: 'P' | 'S'
    name: string
    country_code: string
    identifier?: string
    street?: string
    house_number?: string
    orientation_number?: string
    postal_code?: string
    city?: string
    sector?: string
  }
  not_started?: true
}

/** Ručně spuštěný VREP přenos jedné zmrazené PREZEC/REGZEC registrace. */
export interface PayrollRegistrationTransportResult {
  agenda_code: PayrollRegistrationAgenda
  submission_class: 'CSSZ_PREZEC' | 'CSSZ_REGZEC'
  payload_sha256: string
  attempt: PayrollJmhzTransportAttempt
  acknowledgement: PayrollJmhzTransportAcknowledgement | null
  /** `true` až po načtení protokolu o zpracování z ČSSZ. */
  settled: boolean
}

export interface PayrollRegistrationTransportStatus {
  agenda_code: PayrollRegistrationAgenda
  submission_class: 'CSSZ_PREZEC' | 'CSSZ_REGZEC'
  attempt: PayrollJmhzTransportAttempt | null
}

export interface PayrollRegzelProfile {
  supplier_id: number
  social_enterprise: boolean
  employment_agency: boolean
  protected_labor_market: boolean
  tax_office_code: string | null
  tax_office_workplace_code: string | null
  payer_reference_number: string | null
  is_complete: boolean
  evidence_confirmed_at: string
  row_version: number
  updated_at: string
}

export interface PayrollRegzelProfilePayload {
  row_version: number
  social_enterprise: boolean
  employment_agency: boolean
  protected_labor_market: boolean
  tax_office_code: string
  tax_office_workplace_code: string | null
  payer_reference_number: string | null
  evidence_confirmed: boolean
}

export interface PayrollRegzelProfileResponse {
  profile: PayrollRegzelProfile | null
  suggested_tax_office_workplace_code: string | null
}

/**
 * Evidenční list důchodového pojištění. `submission_status` je vždy
 * `prepared` — odeslání spouští člověk mimo tuhle obrazovku.
 */
export interface PayrollEldpPrepared {
  statement_id: number
  created: boolean
  statement_kind: 'annual' | 'termination'
  section_count: number
  insurance_days: number
  excluded_days_total: number
  due_on: string
  earliest_submission_on: string
  obligation_id: number
  submission_id: number
  part_id: number
  artifact_id: number
  submission_status: string
  xml_sha256: string
  environment: PayrollRegzelEnvironment
}

export interface PayrollEldpStatement {
  id: number
  statement_kind: 'annual' | 'termination'
  period_from: string
  period_to: string
  section_count: number
  insurance_days: number
  excluded_days_total: number
  deducted_days_total: number
  due_on: string
  earliest_submission_on: string
  xml_sha256: string
  payload: Record<string, unknown>
}

export interface PayrollEldpSupport {
  agenda_code: string
  evidence_schema: string
  submission_schema_available: boolean
  stops_at_status: string
  legal_basis: string
  deadline_rulesets: string[]
}

export type PayrollEldpAuthorityStatus = 'submitted' | 'accepted'

export interface PayrollEldpManualEvidence {
  id: number
  statement_id: number
  obligation_id: number
  authority_status: PayrollEldpAuthorityStatus
  confirmation_document_id: number
  confirmation_sha256: string
  confirmation_byte_size: number
  confirmation_mime_type: string
  authority_reference: string
  confirmed_on: string
  recorded_by: number
  recorded_at: string | null
}

export interface PayrollEldpManualCompletionOverview {
  statement_id: number
  obligation_id: number
  obligation_status: string
  obligation_row_version: number
  submission_id: number
  local_submission_status: string
  evidence: PayrollEldpManualEvidence[]
}

export interface PayrollEldpManualCompletionResult extends PayrollEldpManualEvidence {
  created: boolean
  obligation_status: string
  obligation_row_version: number
  local_submission_status: string
  submission_id: number
}

export interface PayrollRegzelSnapshot {
  id: number
  environment: PayrollRegzelEnvironment
  office_id: number
  document_type: 'REGZELDOPL25'
  interaction_code: 'supplemental_information'
  mapping_version: string
  xsd_version: string
  source_snapshot_hash: string
  xml_sha256: string
  xml_byte_size: number
  request_fingerprint?: string
  created_at?: string
  created?: boolean
}

export type PayrollBusinessDayRule = 'none' | 'previous_business_day' | 'next_business_day'
export type PayrollBalanceRoundingMode = 'exact_minor_units' | 'nearest_crown' | 'up_to_crown'
export type PayrollOptionalPolicyState = 'not_used' | 'manual_review' | 'configured'
export type PayrollDeliveryChannel = 'disabled' | 'employee_portal' | 'smime_email' | 'manual_handover'
export type PayrollPolicySourceKind = 'manual' | 'import' | 'migration' | 'system'

export interface PayrollEmployerPolicy {
  id: number
  supplier_id: number
  valid_from: string
  valid_to: string | null
  payday_day: number
  payday_month_offset: 0 | 1
  payday_business_day_rule: PayrollBusinessDayRule
  balance_rounding_mode: PayrollBalanceRoundingMode
  home_office_policy: PayrollOptionalPolicyState
  travel_expense_policy: PayrollOptionalPolicyState
  leave_entitlement_weeks: number
  four_eyes_required: boolean
  automatic_calculation_enabled: boolean
  automatic_posting_enabled: boolean
  automatic_payments_enabled: boolean
  delivery_channel: PayrollDeliveryChannel
  delivery_verified_on: string | null
  source_kind: PayrollPolicySourceKind
  source_reference: string | null
  created_by: number | null
  updated_by: number | null
  row_version: number
  created_at: string
  updated_at: string
}

export type PayrollEmployerPolicyPayload = Omit<
  PayrollEmployerPolicy,
  'id' | 'supplier_id' | 'created_by' | 'updated_by' | 'created_at' | 'updated_at'
>

export type PayrollDimensionType = 'cost_center' | 'project' | 'activity'

export interface PayrollDimension {
  id: number
  supplier_id: number
  dimension_type: PayrollDimensionType
  code: string
  name: string
  valid_from: string
  valid_to: string | null
  is_active: boolean
  default_account_code: string | null
  created_by: number | null
  updated_by: number | null
  row_version: number
  created_at: string
  updated_at: string
}

export type PayrollDimensionPayload = Omit<
  PayrollDimension,
  'id' | 'supplier_id' | 'created_by' | 'updated_by' | 'created_at' | 'updated_at'
>

export interface PayrollEmploymentDimension {
  id: number
  supplier_id: number
  employment_id: number
  dimension_id: number
  dimension_type: PayrollDimensionType
  dimension_code: string
  dimension_name: string
  valid_from: string
  valid_to: string | null
  created_by: number | null
  updated_by: number | null
  row_version: number
  created_at: string
  updated_at: string
}

export interface PayrollEmploymentDimensionPayload {
  dimension_id: number
  valid_from: string
  valid_to: string | null
  row_version?: number
}

/**
 * Navazující agendy karty zaměstnance. Pořadí drží server (repository), aby se
 * rozcestník i souhrn řadily stejně a nedaly se rozejít.
 */
export type PayrollAgendaKey =
  | 'time'
  | 'absences'
  | 'travel'
  | 'quick_inputs'
  | 'components'
  | 'average_earnings'
  | 'deduction_agreements'
  | 'enforcement'
  | 'documents'
  | 'annual_settlement'

export interface PayrollAgendaSummaryItem {
  key: PayrollAgendaKey
  /** Kolik záznamů agenda pro tenhle vztah (resp. osobu) vede. */
  count: number
  /** Datum posledního záznamu; `null` = agenda je prázdná. */
  last_on: string | null
  /** Souhrnná nebo poslední částka, kde má smysl; jinak `null`. */
  amount_minor: number | null
}

export interface PayrollEmploymentAgendaSummary {
  employment_id: number
  employee_id: number
  /** Chybí agendy, na které volající nemá oprávnění — ne nula, která by lhala. */
  agendas: PayrollAgendaSummaryItem[]
}

export interface PayrollSetupCheckItem {
  code: string
  /**
   * `pending` = kontrola nevyšla, ale nastavení neblokuje (nepovinná
   * připravenost). Chyběl tu a stránka pak u takové kontroly vypsala syrový
   * klíč překladu — viz `PayrollSetupCheckService::addCheck()`.
   */
  status: 'ok' | 'blocked' | 'pending'
  message: string
}

export interface PayrollSetupCheck {
  ready: boolean
  effective_on: string
  policy_id: number | null
  checks: PayrollSetupCheckItem[]
  blockers: string[]
}

export type PayrollInstitutionType =
  | 'social_security'
  | 'tax_office'
  | 'health_insurer'
  | 'statutory_insurance'
  | 'other_recipient'

export type PayrollInstitutionAccountSource =
  | 'official_registry'
  | 'official_document'
  | 'institution_notice'
  | 'user_verified'
  | 'imported'

export interface PayrollInstitutionAccount {
  id: number
  supplier_id: number
  institution_id: number
  institution_type: PayrollInstitutionType
  institution_code: string
  institution_name: string
  /**
   * Čitelné číslo účtu instituce. `null` jen u poškozeného nebo nedokončeného
   * záznamu — pak zbývá maskovaná podoba.
   */
  bank_account: string | null
  bank_account_masked: string
  currency_code: string
  variable_symbol: string | null
  specific_symbol: string | null
  constant_symbol: string | null
  valid_from: string
  valid_to: string | null
  source_kind: PayrollInstitutionAccountSource
  source_reference: string
  verified_on: string
  verified_by: number | null
  row_version: number
  created_at: string
  updated_at: string
}

export interface PayrollInstitutionAccountCreatePayload {
  institution_type: PayrollInstitutionType
  institution_code: string
  institution_name: string
  bank_account: string
  currency_code: string
  variable_symbol: string | null
  specific_symbol: string | null
  constant_symbol: string | null
  valid_from: string
  valid_to: string | null
  source_kind: PayrollInstitutionAccountSource
  source_reference: string
  verified_on: string
}

export interface PayrollInstitutionAccountUpdatePayload {
  row_version: number
  institution_name: string
  variable_symbol: string | null
  specific_symbol: string | null
  constant_symbol: string | null
  valid_to: string | null
  source_kind: PayrollInstitutionAccountSource
  source_reference: string
  verified_on: string
}

export type PayrollDocumentKind =
  | 'payslip'
  | 'payroll_sheet'
  | 'taxable_income_advance_certificate'
  | 'taxable_income_withholding_certificate'
  | 'employment_certificate'
  | 'average_earnings_certificate'
  | 'average_earnings_statement'
  | 'annual_settlement_result'
  | 'monthly_bundle'

export type PayrollTaxCertificateKind = Extract<
  PayrollDocumentKind,
  | 'taxable_income_advance_certificate'
  | 'taxable_income_withholding_certificate'
>

export interface PayrollTaxCertificateGenerationPayload {
  supersedes_document_id: number | null
  correction_reason: string | null
}

export interface PayrollDocument {
  id: number
  run_id: number | null
  revision_id: number | null
  annual_revision_id?: number | null
  annual_revision_no?: number
  tax_year?: number
  purpose?: string
  revision_no?: number
  revision_status?: 'approved' | 'superseded'
  office_id?: number | null
  office_name?: string | null
  employee_id: number | null
  employee_name?: string | null
  employment_id?: number
  employment_end_date?: string
  employment_exit_revision_id?: number | null
  employment_exit_revision_no?: number
  document_kind: PayrollDocumentKind
  document_revision_no?: number
  supersedes_document_id?: number | null
  file_sha256: string
  size_bytes: number
  mime_type: 'application/pdf' | 'application/zip'
  suggested_filename: string
  created_at: string
}

export interface PayrollDocumentRevision {
  run_id: number
  revision_id: number
  revision_no: number
  status: 'approved' | 'superseded'
  office_id: number | null
  office_name: string | null
}

export interface PayrollDocumentList {
  period: string
  revisions: PayrollDocumentRevision[]
  items: PayrollDocument[]
  total: number
  limit: number
  offset: number
}

export interface PayrollAnnualDocumentList {
  year: number
  items: PayrollDocument[]
  total: number
  limit: number
  offset: number
}

export type PayrollPeriodExportScope = 'monthly' | 'annual'

export interface PayrollPeriodExport {
  id: number
  scope: PayrollPeriodExportScope
  period_start: string
  period_end: string
  file_sha256: string
  size_bytes: number
  suggested_filename: string
}

export type PayrollPeriodExportJobStatus =
  | 'queued'
  | 'processing'
  | 'retry_wait'
  | 'failed'
  | 'completed'

export interface PayrollPeriodExportJob {
  id: number
  scope: PayrollPeriodExportScope
  period_start: string
  period_end: string
  status: PayrollPeriodExportJobStatus
  attempt_count: number
  available_at: string
  export_id: number | null
  last_error_code: string | null
  last_error_message: string | null
  created_at: string
  started_at: string | null
  completed_at: string | null
}

export type PayrollYearCloseStatus = 'open' | 'closed'

export type PayrollYearCloseBlockerCode =
  | 'schema_unavailable'
  | 'missing_months'
  | 'open_corrections'
  | 'open_submissions'
  | 'open_liabilities'
  | 'open_leave'
  | 'open_enforcement'
  | 'reconciliation_differences'

export interface PayrollYearCloseBlocker {
  code: PayrollYearCloseBlockerCode
  count?: number
  months?: string[]
  tables?: string[]
}

export interface PayrollYearClose {
  id: number | null
  supplier_id: number
  calendar_year: number
  status: PayrollYearCloseStatus
  row_version: number
  closed_at: string | null
  closed_by: number | null
  reopened_at: string | null
  reopened_by: number | null
  created_at: string | null
  updated_at: string | null
}

export interface PayrollYearCloseStatusResponse {
  closure: PayrollYearClose
  blockers: PayrollYearCloseBlocker[]
}

export interface PayrollAnnualReportMonth {
  period: string
  approved_revision_count: number
  headcount: number
  gross_minor: number | null
  employer_cost_minor: number | null
}

export interface PayrollAnnualReport {
  year: number
  totals: {
    approved_revision_count: number
    headcount_person_months: number
    gross_minor: number | null
    employer_cost_minor: number | null
  }
  months: PayrollAnnualReportMonth[]
}

/* ── Roční zúčtování záloh a daňového zvýhodnění (§ 38ch ZDP) ─────────────── */

/** Požádal poplatník o roční zúčtování? `unknown` NENÍ „nepožádal". */
export type PayrollAnnualSettlementRequestStatus =
  | 'unknown'
  | 'requested'
  | 'not_requested'
  | 'withdrawn'

/** Doklady od předchozích plátců daně (§ 38ch odst. 3). */
export type PayrollAnnualSettlementPriorEmployers =
  | 'unknown'
  | 'none'
  | 'all_documented'
  | 'missing'

/** Podá nebo je povinen podat přiznání? (§ 38g, § 38ch odst. 1 věta druhá) */
export type PayrollAnnualSettlementFilingObligation =
  | 'unknown'
  | 'none'
  | 'required'

/** Položky uplatňované až ročně (§ 38h odst. 6) — modul je neumí spočítat. */
export type PayrollAnnualSettlementAnnualClaims =
  | 'unknown'
  | 'none'
  | 'present_unsupported'

export type PayrollAnnualSettlementCaregiverStatus = 'unknown' | 'none' | 'present'

export interface PayrollAnnualSettlementCaregiver {
  id?: number
  position?: number
  given_name: string
  family_name: string
  birth_date: string
  /** Leden až prosinec; A = uplatňoval(a), N = neuplatňoval(a). */
  months_mask: string
}

/** Jak zúčtování dopadlo. */
export type PayrollAnnualSettlementOutcome =
  | 'overpayment'
  | 'overpayment_below_threshold'
  | 'no_difference'
  | 'underpayment_not_withheld'

/**
 * Proč zúčtování provést nelze. Kód je klíč do slovníku
 * (`payroll.annual_settlement.blocker.*`), nikdy se nezobrazuje syrový.
 */
export type PayrollAnnualSettlementBlocker =
  | 'not_requested'
  | 'requested_after_deadline'
  | 'declaration_not_signed'
  | 'declaration_unverified'
  | 'prior_employer_documents_missing'
  | 'prior_employer_documents_late'
  | 'must_file_tax_return'
  | 'filing_obligation_unknown'
  | 'annual_only_claims_unsupported'
  | 'annual_only_claims_unknown'
  | 'external_certificate_unverified'
  | 'external_certificate_incomplete'
  | 'accumulator_missing'
  | 'no_approved_months'
  | 'settlement_deadline_passed'
  | 'non_resident'
  | 'credit_evidence_unverified'
  | 'child_evidence_unverified'
  | 'child_claim_conflict'
  | 'child_jmhz_evidence_incomplete'
  | 'already_settled'
  | 'ruleset_year_not_covered'

export interface PayrollAnnualSettlementRequest {
  id?: number
  employee_id?: number
  tax_year: number
  request_status: PayrollAnnualSettlementRequestStatus
  requested_on: string | null
  request_evidence_reference: string | null
  prior_employers: PayrollAnnualSettlementPriorEmployers
  prior_documents_received_on: string | null
  filing_obligation: PayrollAnnualSettlementFilingObligation
  filing_obligation_reason: string | null
  annual_claims: PayrollAnnualSettlementAnnualClaims
  annual_claims_note: string | null
  other_household_caregiver_status: PayrollAnnualSettlementCaregiverStatus
  other_household_caregivers: PayrollAnnualSettlementCaregiver[]
  note: string | null
  row_version: number
}

export interface PayrollAnnualSettlementResult {
  schema_version: string
  tax_year: number
  performed: boolean
  blockers: PayrollAnnualSettlementBlocker[]
  outcome: PayrollAnnualSettlementOutcome | null
  rounded_tax_base_minor_units: number
  tax_before_credits_minor_units: number
  annual_credits_minor_units: number
  applied_credits_minor_units: number
  child_entitlement_minor_units: number
  child_credit_minor_units: number
  annual_tax_bonus_minor_units: number
  tax_after_all_credits_minor_units: number
  tax_difference_minor_units: number
  bonus_difference_minor_units: number
  settlement_difference_minor_units: number
  payable_minor_units: number
  annual_bonus_threshold_met: boolean
  annual_bonus_candidate_minor_units?: number
  annual_bonus_income_threshold_met?: boolean
  annual_bonus_amount_threshold_met?: boolean
  annual_bonus_eligible?: boolean
  annual_bonus_eligibility_reason?:
    | 'income_below_threshold'
    | 'amount_below_threshold'
    | 'eligible'
  bonus_qualifying_income_minor_units?: number
  bonus_minimum_income_minor_units?: number
  bonus_minimum_amount_minor_units?: number
  monthly_tax_bonus_minor_units?: number
}

export interface PayrollAnnualSettlementStoredOutcome {
  id: number
  employee_id: number
  tax_year: number
  annual_revision_id: number
  outcome: PayrollAnnualSettlementOutcome
  tax_difference_minor: number
  bonus_difference_minor: number
  settlement_difference_minor: number
  payable_minor: number
  settled_on: string
  /** Běh, revize a období, ve kterých se doplatek vyplatil (§ 38ch odst. 5). */
  payout_run_id: number | null
  payout_revision_id: number | null
  payout_period_start: string | null
}

export interface PayrollAnnualSettlementListItem {
  employee_id: number
  employee_name: string
  request_status: PayrollAnnualSettlementRequestStatus | null
  requested_on: string | null
  prior_employers: PayrollAnnualSettlementPriorEmployers | null
  filing_obligation: PayrollAnnualSettlementFilingObligation | null
  annual_claims: PayrollAnnualSettlementAnnualClaims | null
  row_version: number | null
  outcome_id: number | null
  outcome: PayrollAnnualSettlementOutcome | null
  tax_difference_minor: number | null
  bonus_difference_minor: number | null
  settlement_difference_minor: number | null
  payable_minor: number | null
  settled_on: string | null
  payout_run_id: number | null
  payout_revision_id: number | null
  payout_period_start: string | null
  annual_revision_id: number | null
}

export interface PayrollAnnualSettlementList {
  tax_year: number
  /** § 38ch odst. 1 a 3 — poslední den pro žádost i pro doklady. */
  request_deadline: string
  /** § 38ch odst. 4 — poslední den pro provedení. */
  settlement_deadline: string
  /** Období mzdy, v němž se přeplatek nejpozději vrací (§ 38ch odst. 5). */
  payout_period: string
  payout_threshold_minor: number
  items: PayrollAnnualSettlementListItem[]
  /** Počet lidí v CELÉM zúžení, ne na načtené stránce. */
  total: number
  limit: number
  offset: number
  search: string
  state: PayrollAnnualSettlementListState
}

/** Pojmenované zúžení přehledu, ne dopočet ze stránky. */
export type PayrollAnnualSettlementListState =
  | 'all'
  | 'requested'
  | 'settled'
  | 'unsettled'

export interface PayrollAnnualSettlementCreditRow {
  label: string
  amount_minor_units: number
}

export interface PayrollAnnualSettlementChildRow {
  label: string
  months: number
  amount_minor_units: number
}

/**
 * Kód údaje, který § 38ch odst. 3 žádá a na potvrzení chybí. Klíč do slovníku
 * `payroll.annual_settlement.certificate.field.*`.
 */
export type PayrollAnnualSettlementCertificateField =
  | 'gross_income'
  | 'advance_base'
  | 'advance_tax'
  | 'credit_35ba'
  | 'credit_35c'
  | 'tax_bonus'

/**
 * Potvrzení od předchozího plátce daně (§ 38ch odst. 3, tiskopis 25 5460).
 *
 * Částky jsou `null`, když je potvrzení nenese. `null` NENÍ nula — nula je
 * doložený údaj, kdežto `null` znamená, že zúčtování provést nelze.
 */
export interface PayrollAnnualSettlementCertificate {
  certificate_reference: string
  payer_name: string | null
  payer_tax_identification: string | null
  /** § 38ch odst. 3 věta druhá — do 15. února po uplynutí období. */
  received_on: string | null
  /** ř. 1 tiskopisu — úhrn zúčtovaných příjmů. */
  gross_income_minor_units: number | null
  /** ř. 5 tiskopisu — základ daně. */
  advance_base_minor_units: number | null
  /** ř. 8 tiskopisu — záloha na daň celkem. */
  advance_tax_minor_units: number | null
  /** Úhrn poskytnutých měsíčních slev podle § 35ba. */
  non_refundable_credit_minor_units: number | null
  /** Úhrn poskytnutých měsíčních slev podle § 35c. */
  child_credit_minor_units: number | null
  /** ř. 9 tiskopisu — úhrn vyplacených měsíčních daňových bonusů. */
  tax_bonus_minor_units: number | null
  evidence_status: 'unverified' | 'verified'
  evidence_reference: string | null
  missing_statutory_fields: PayrollAnnualSettlementCertificateField[]
}

export interface PayrollAnnualSettlementPreview {
  tax_year: number
  employee_id: number
  request: PayrollAnnualSettlementRequest
  result: PayrollAnnualSettlementResult
  credit_rows: PayrollAnnualSettlementCreditRow[]
  child_rows: PayrollAnnualSettlementChildRow[]
  certificates: PayrollAnnualSettlementCertificate[]
  already_settled: PayrollAnnualSettlementStoredOutcome | null
}

export interface PayrollAnnualSettlementRun {
  tax_year: number
  employee_id: number
  performed: boolean
  created?: boolean
  result: PayrollAnnualSettlementResult
  outcome?: PayrollAnnualSettlementStoredOutcome | null
  already_settled?: PayrollAnnualSettlementStoredOutcome | null
  document?: PayrollDocument
}

export interface PayrollAnnualSettlementRequestPayload {
  request_status: PayrollAnnualSettlementRequestStatus
  requested_on: string | null
  request_evidence_reference: string | null
  prior_employers: PayrollAnnualSettlementPriorEmployers
  prior_documents_received_on: string | null
  filing_obligation: PayrollAnnualSettlementFilingObligation
  filing_obligation_reason: string | null
  annual_claims: PayrollAnnualSettlementAnnualClaims
  annual_claims_note: string | null
  other_household_caregiver_status: PayrollAnnualSettlementCaregiverStatus
  other_household_caregivers: PayrollAnnualSettlementCaregiver[]
  note: string | null
  row_version?: number
}

/**
 * Zápis potvrzení od jiného plátce. Prázdná částka se posílá jako `null`,
 * ne jako nula — nula je doložený údaj a znamenala by, že se s ní počítá.
 */
export interface PayrollAnnualSettlementCertificatePayload {
  certificate_reference: string
  payer_name: string | null
  payer_tax_identification: string | null
  received_on: string | null
  gross_income_minor_units: number | null
  advance_base_minor_units: number | null
  advance_tax_minor_units: number | null
  non_refundable_credit_minor_units: number | null
  child_credit_minor_units: number | null
  tax_bonus_minor_units: number | null
  evidence_status: 'unverified' | 'verified'
  evidence_reference: string | null
}

export interface PayrollEmploymentExitReadinessItem {
  available: boolean
  readiness_code: string | null
}

export interface PayrollEmploymentExitDocumentList {
  employment_id: number
  readiness: {
    employment_certificate: PayrollEmploymentExitReadinessItem & {
      deduction_claim_ids: number[]
    }
    average_earnings_certificate: PayrollEmploymentExitReadinessItem & {
      decisive_year: number | null
      decisive_quarter: number | null
    }
    average_earnings_statement: PayrollEmploymentExitReadinessItem & {
      decisive_year: number | null
      decisive_quarter: number | null
    }
  }
  items: PayrollDocument[]
}

export type PayrollTerminationReasonKind =
  | 'none'
  | 'gross_breach'
  | 'sickness_regime_breach'
  | 'organizational'
  | 'health'
  | 'employer_breach'
  | 'employee_unilateral'
  | 'agreement'

export interface PayrollPensionInsurancePeriod {
  from: string
  to: string
}

/** Oddelene potvrzeni podle § 313 odst. 2 zakoniku prace. */
export interface PayrollAverageEarningsCertificateEvidence {
  termination_assessment_complete: boolean
  termination_reason_kind: PayrollTerminationReasonKind
  employee_stated_reason: string | null
  pension_insurance_periods: PayrollPensionInsurancePeriod[]
  correction_reason: string | null
}

/** Samostatne potvrzeni o prumernem vydelku podle § 356 odst. 1 a 2. */
export interface PayrollAverageEarningsStatementEvidence {
  requested_purpose: string
  correction_reason: string | null
}

export type PayrollDocumentBatchStatus =
  | 'queued'
  | 'running'
  | 'retry_wait'
  | 'failed'
  | 'completed'

export type PayrollDocumentBatchItemStatus =
  | 'queued'
  | 'processing'
  | 'retry_wait'
  | 'failed'
  | 'succeeded'

export interface PayrollDocumentBatch {
  id: number
  run_id: number
  revision_id: number
  period_start: string
  status: PayrollDocumentBatchStatus
  item_count: number
  succeeded_count: number
  failed_count: number
  bundle_document_id: number | null
  bundle_filename: string | null
  created_at: string
  started_at: string | null
  completed_at: string | null
  updated_at: string
}

export interface PayrollDocumentBatchItem {
  id: number
  batch_id: number
  employee_id: number
  employee_name: string
  status: PayrollDocumentBatchItemStatus
  attempt_count: number
  available_at: string
  document_id: number | null
  last_error_code: string | null
  last_error_message: string | null
  completed_at: string | null
  updated_at: string
}

export interface PayrollEmploymentCertificateDeductionEvidence {
  source_claim_id: number
  beneficiary: string
  ordering_authority: string
  decision_reference: string
}

export interface PayrollEmploymentCertificatePensionPeriod {
  category: 'I' | 'II'
  from: string
  to: string
}

export interface PayrollEmploymentCertificateEvidence {
  work_description: string
  achieved_qualification: string
  exposure_assessment_complete: boolean
  exposure_facts: string[]
  deduction_assessment_complete: boolean
  deductions: PayrollEmploymentCertificateDeductionEvidence[]
  pension_category_assessment_complete: boolean
  pre1993_pension_category_periods: PayrollEmploymentCertificatePensionPeriod[]
  dpp_issuance_basis: null | 'wage_deductions' | 'sickness_insurance'
  correction_reason: string | null
}

export type PayrollRunStatus =
  | 'draft'
  | 'inputs_locked'
  | 'calculated'
  | 'reviewed'
  | 'approved'
  | 'posted'
  | 'payment_ready'
  | 'paid'
  | 'closed'
  | 'correction_pending'
  | 'reopened'
  | 'cancelled'

export type PayrollRunCommand =
  | 'lock_inputs'
  | 'calculate'
  | 'review'
  | 'approve'
  | 'post'
  | 'prepare_payments'
  | 'mark_paid'
  | 'request_correction'
  | 'reopen'
  | 'cancel'
  | 'close'

/**
 * Co se při příkazu doopravdy stalo. Samotný přechod stavu to neřekne: firma
 * v daňové evidenci projde `post` bez účetního zápisu a běh, kde je celá čistá
 * mzda zápočtem na účet společníka, projde platbami bez jediné platby.
 * Uživateli se to musí říct nahlas, ne zamlčet.
 */
export type PayrollRunOutcomeCode =
  | 'posted'
  | 'already_posted'
  | 'posting_not_applicable'
  | 'payments_prepared'
  | 'payments_not_applicable'
  | 'payments_settled'

export interface PayrollRunOutcome {
  outcome: PayrollRunOutcomeCode
  details: Record<string, unknown>
}

export interface PayrollRunValidation {
  id: number
  severity: 'blocker' | 'warning' | 'info'
  code: string
  entity_type: string
  entity_id: number | null
  message: string
  remediation_path: string | null
  requires_override: boolean
  /*
   * Varování s `requires_override` zastaví schválení běhu, dokud za něj někdo
   * nepřevezme odpovědnost. Tyhle tři sloupce nesou, kdo to byl, kdy a proč —
   * bez nich karta běhu jen mlčky ukáže nálepku a uživatel neví, co má udělat.
   */
  override_reason: string | null
  overridden_by: number | null
  overridden_by_name: string | null
  overridden_at: string | null
}

export interface PayrollRunValidationOverrideResponse {
  granted: boolean
  /** false = výjimku odklepl tentýž člověk, který běh počítal (politika, ne blokace) */
  four_eyes_met: boolean
  idempotent_replay: boolean
  run: PayrollRun
  validation: PayrollRunValidation
}

export interface PayrollIncomeTaxRate {
  decimal: string
  numerator: number
  scale: number
  denominator: number
}

export interface PayrollIncomeTaxRateStep {
  label: string
  input_minor_units: number
  rate: PayrollIncomeTaxRate
  unrounded_numerator: number
  unrounded_denominator: number
  rounding_mode: string
  output_minor_units: number
}

export interface PayrollIncomeTaxAdvanceResult {
  taxable_income_minor_units: number
  rounded_tax_base_minor_units: number
  low_rate_base_minor_units: number
  high_rate_base_minor_units: number
  rate_steps: PayrollIncomeTaxRateStep[]
  tax_before_credits_minor_units: number
  non_refundable_credits_minor_units: number
  child_credit_minor_units: number
  tax_bonus_eligible: boolean
  tax_after_credits_minor_units: number
  tax_bonus_minor_units: number
  tax_bonus_candidate_minor_units?: number
  tax_bonus_minimum_income_minor_units?: number
  tax_bonus_minimum_amount_minor_units?: number
  tax_bonus_income_threshold_met?: boolean
  tax_bonus_amount_threshold_met?: boolean
  tax_bonus_eligibility_reason?:
    | 'eligible'
    | 'declaration_not_signed'
    | 'income_below_threshold'
    | 'amount_below_threshold'
  ruleset_id: string
  ruleset_hash: string
}

export interface PayrollIncomeTaxRelationshipResult {
  relationship_reference: string
  kind:
    | 'employment'
    | 'small-scale-employment'
    | 'dpp'
    | 'dpc'
    | 'managing-partner-dependent'
    | 'statutory-body'
  taxable_base_minor_units: number
  regime: 'advance' | 'withholding' | 'manual-review'
  withholding_group: string | null
}

export interface PayrollIncomeTaxWithholdingGroup {
  group: string
  base_minor_units: number
  tax_minor_units: number
  rate_step: PayrollIncomeTaxRateStep
}

export interface PayrollIncomeTaxResult {
  status: 'calculated' | 'manual-review'
  calculation_date: string
  employee_reference: string
  payer_reference: string
  relationships: PayrollIncomeTaxRelationshipResult[]
  advance_tax: PayrollIncomeTaxAdvanceResult | null
  withholding_groups: PayrollIncomeTaxWithholdingGroup[]
  withholding_base_minor_units: number
  withholding_tax_minor_units: number
  claimed_non_refundable_credits_minor_units: number
  applied_non_refundable_credits_minor_units: number
  claimed_child_credit_minor_units: number
  applied_child_credit_minor_units: number
  annual_accumulator: Record<string, unknown>
  issues: string[]
  policy_id: string
  policy_hash: string
  ruleset_id: string
  ruleset_hash: string
}

export interface PayrollRunResultPerson {
  employee_id: number
  statutory?: {
    person_reference: string
    status: 'calculated' | 'manual_review' | 'error'
    income_tax?: PayrollIncomeTaxResult
    issues?: string[]
  }
}

export interface PayrollRunResultSnapshot {
  totals?: {
    cash_payable_minor?: number
    enforcement_withheld_minor?: number
    payable_after_enforcement_minor?: number
  }
  people?: PayrollRunResultPerson[]
}

export interface PayrollRun {
  id: number
  supplier_id: number
  office_id: number | null
  period_start: string
  payment_date: string
  status: PayrollRunStatus
  current_revision_no: number
  row_version: number
  revision_id: number | null
  revision_no: number | null
  revision_kind: 'regular' | 'correction' | null
  revision_status: string | null
  payment_materialization_supported: boolean
  can_delete: boolean
  result_snapshot: PayrollRunResultSnapshot | null
  available_commands: PayrollRunCommand[]
  validations: PayrollRunValidation[]
}

export type PayrollRunHistoryTotalKey =
  | 'cash_payable_minor'
  | 'enforcement_withheld_minor'
  | 'payable_after_enforcement_minor'

export interface PayrollRunHistoryTotalDiff {
  before: number | null
  after: number | null
  delta: number | null
}

export interface PayrollRunRevisionDiff {
  input_changed: boolean
  ruleset_changed: boolean
  result_changed: boolean
  totals: Partial<Record<PayrollRunHistoryTotalKey, PayrollRunHistoryTotalDiff>>
}

export interface PayrollRunRevisionHistory {
  id: number
  revision_no: number
  previous_revision_id: number | null
  revision_kind: 'regular' | 'correction'
  status: string
  created_at: string
  calculated_at: string | null
  reviewed_at: string | null
  approved_at: string | null
  ruleset_manifest_hash: string
  input_snapshot_hash: string
  result_snapshot_hash: string | null
  totals: Partial<Record<PayrollRunHistoryTotalKey, number | null>> | null
  diff_from_previous: PayrollRunRevisionDiff | null
}

export interface PayrollRunHistoryEvent {
  id: number
  revision_id: number | null
  event_type: string
  from_status: PayrollRunStatus | null
  to_status: PayrollRunStatus | null
  reason: string | null
  actor_name: string | null
  created_at: string
}

export interface PayrollRunHistory {
  run_id: number
  revisions: PayrollRunRevisionHistory[]
  events: PayrollRunHistoryEvent[]
}

export interface PayrollRunsPage {
  runs: PayrollRun[]
  total: number
  limit: number
  offset: number
}

export interface PayrollProductionQualification {
  id: number
  supplier_id: number
  module_state_row_version: number
  support_matrix_version: string
  support_matrix_sha256: string
  evidence_sha256: string
  qualified_by: number | null
  qualified_at: string
}

export interface PayrollProductionQualificationEvidence {
  parallel_runs: Array<{ payroll_run_id: number; document_id: number }>
  correction_scenario: { payroll_run_id: number; document_id: number }
  recovery_drill: { completed_on: string; document_id: number }
  expert_approval: {
    approver_name: string
    approver_role: string
    approved_on: string
    document_id: number
  }
  rollback_plan: { verified_on: string; document_id: number }
  post_go_live_monitoring: { prepared_on: string; document_id: number }
}

export interface PayrollRunCommandResponse {
  command: PayrollRunCommand
  from_status: PayrollRunStatus
  to_status: PayrollRunStatus
  run: PayrollRun
  revision: Record<string, unknown> | null
  idempotent_replay: boolean
  outcome: PayrollRunOutcome | null
}

export type PayrollDependantRelation =
  | 'child_own'
  | 'child_adopted'
  | 'child_in_care'
  | 'child_of_spouse'
  | 'grandchild'
  | 'spouse'
  | 'partner'

export type PayrollDependantClaimReason =
  | 'own_household'
  | 'shared_custody'
  | 'adoption'
  | 'foster_care'
  | 'study_continues'
  | 'other'

export type PayrollDependantBlocker =
  | 'relation_not_child'
  | 'evidence_unverified'
  | 'shared_household_unconfirmed'
  | 'other_claimant_not_excluded'
  | 'declaration_missing'
  | 'outside_existence'
  | 'superseded'

export interface PayrollDependantCredit {
  status: 'calculated' | 'manual_review'
  rate_key: string | null
  monthly_credit_minor_units: number | null
  manual_review_reason: string | null
}

export interface PayrollDependantClaim {
  id: number
  child_reference: string
  child_order: number
  claim_reason: PayrollDependantClaimReason | null
  ztp_p: boolean
  evidence_status: 'verified' | 'unverified'
  evidence_reference: string | null
  shared_household_confirmed: boolean
  other_claimant_excluded: boolean
  effective_from: string
  effective_to: string | null
  superseded_by_id: number | null
  is_frozen: boolean
  blockers: PayrollDependantBlocker[]
  credit: PayrollDependantCredit
  row_version: number
}

export interface PayrollDependant {
  id: number
  relation: PayrollDependantRelation
  full_name: string
  given_name: string | null
  family_name: string | null
  birth_date: string
  birth_number_masked: string | null
  has_birth_number: boolean
  ztp_p: boolean
  student: boolean
  existence_from: string
  existence_to: string | null
  note: string | null
  can_claim_monthly: boolean
  row_version: number
  claims: PayrollDependantClaim[]
}

export interface PayrollDependantsResponse {
  employee_id: number
  effective_on: string
  frozen_through: string | null
  dependants: PayrollDependant[]
}

export interface PayrollDependantPayload {
  relation: PayrollDependantRelation
  full_name: string
  given_name: string | null
  family_name: string | null
  birth_date: string
  birth_number?: string | null
  ztp_p: boolean
  student: boolean
  existence_from: string
  existence_to: string | null
  note: string | null
  row_version?: number
}

export interface PayrollDependantClaimPayload {
  child_order: number
  claim_reason: PayrollDependantClaimReason | null
  evidence_status: 'verified' | 'unverified'
  evidence_reference: string | null
  shared_household_confirmed: boolean
  other_claimant_excluded: boolean
  ztp_p: boolean
  effective_from: string
  effective_to: string | null
  row_version?: number
}

/**
 * Volba podpisového certifikátu pro mzdová podání na ČSSZ.
 *
 * Certifikáty se nahrávají v jednom trezoru (Systém → Elektronické podpisy);
 * tady se jen vybírá, KTERÝ z nich podepisuje podání téhle firmy — a odděleně
 * pro testovací a produkční prostředí, protože testovací certifikát bývá jiný
 * a záměna se pozná až z protokolu ČSSZ, typicky po termínu.
 */
export type PayrollSigningEnvironment = 'production' | 'test'

export interface PayrollSigningCertificate {
  id: number
  label: string
  subject: string
  issuer: string
  /** Kanonický hex (bez oddělovačů a vedoucích nul); `null`, když ho neznáme. */
  serial_hex: string | null
  /** Totéž decimálně — ČSSZ tiskne sériové číslo na papíře v tomhle zápisu. */
  serial_decimal: string | null
  valid_from: string | null
  valid_to: string | null
  expired: boolean
  not_yet_valid: boolean
  usable_now: boolean
  expires_in_days: number | null
  enabled_for_supplier: boolean
  ik_mpsv_present: boolean
}

export interface PayrollSigningWarning {
  code: string
  message: string
}

export interface PayrollSigningProfile {
  environment: string
  credential_id: number
  owner_user_id: number
  cssz_registered_serial: string | null
  row_version: number
  created_at: string | null
  updated_at: string | null
  /** `false`, když volbu uložil jiný uživatel svým certifikátem. */
  certificate_accessible: boolean
  certificate: PayrollSigningCertificate | null
  expired: boolean
}

export interface PayrollSigningProfileView {
  environment: PayrollSigningEnvironment
  environments: PayrollSigningEnvironment[]
  storage_available: boolean
  profile: PayrollSigningProfile | null
  certificates: PayrollSigningCertificate[]
  warnings: PayrollSigningWarning[]
}

export interface PayrollSigningProfileResult {
  environment: PayrollSigningEnvironment
  profile: PayrollSigningProfile
  warnings: PayrollSigningWarning[]
}

export interface PayrollSigningProfilePayload {
  environment: PayrollSigningEnvironment
  credential_id: number
  /** Prázdné = uložit bez ověření proti oznámení o pověření. */
  cssz_registered_serial?: string | null
  /** Posílá se jen při ZMĚNĚ existující volby — u prvního uložení ho backend odmítne. */
  row_version?: number | null
}

/**
 * Ledger odeslaných měsíčních hlášení na ČSSZ.
 *
 * Přírůstkový a nikdy se nepřepisuje: každý pokus o odeslání zakládá vlastní
 * řádek, takže několik pokusů k jednomu podání je normální stav a zároveň
 * doklad o tom, co se dělo — ne nepořádek, který by se měl schovat.
 */
export type PayrollJmhzTransportEnvironment = 'test' | 'production'

/**
 * Šest stavů pokusu. `awaiting_protocol` NENÍ přijaté podání: ČSSZ potvrzuje
 * převzetí okamžitě a o výsledku rozhoduje až později. Hotovo znamená teprve
 * `completed`, tedy „dotáhli jsme protokol o zpracování".
 */
export type PayrollJmhzTransportStatus =
  | 'prepared'
  | 'sent'
  | 'awaiting_protocol'
  | 'completed'
  | 'failed'
  | 'expired'

export interface PayrollJmhzTransportAttempt {
  id: number
  supplier_id: number
  environment: string
  submission_id: number
  channel: string
  attempt_no: number
  status: PayrollJmhzTransportStatus
  /** Období hlášení z povinnosti; `null` u pokusu, jehož podání už v evidenci není. */
  period_start: string | null
  period_end: string | null
  /** Druh a stav podání, ke kterému pokus patří; `null` jen u osiřelého ledgeru. */
  submission_kind: string | null
  submission_status: string | null
  corrects_submission_id: number | null
  /** CorrelationID přidělené branou VREP; bez něj se na výsledek nelze zeptat. */
  correlation_reference: string | null
  request_sha256: string | null
  response_http_status: number | null
  error_code: string | null
  error_message: string | null
  /** Kdy se automatika ozve příště — u čekajícího pokusu dotaz, u dotaženého uzavření. */
  next_retry_at: string | null
  /** Kolikrát jsme se ČSSZ ptali na výsledek. Roste i po neúspěšném dotazu. */
  poll_count: number
  last_polled_at: string | null
  /** Proč poslední dotaz nedal odpověď; `null` = poslední dotaz prošel. */
  last_poll_error: string | null
  sent_at: string | null
  completed_at: string | null
  /** Kdy byla transakce u VREP uzavřena. `null` = transakce ještě visí otevřená. */
  closed_at: string | null
  close_attempts: number
  close_error: string | null
  row_version: number
  created_by: number | null
  created_at: string
  updated_at: string
}

/** Zmrazené storno nebo opravné podání připravené k odeslání. */
export interface PayrollJmhzCorrectiveSubmission {
  submission_id: number
  part_id: number
  artifact_id: number
  status: string
  row_version: number
  environment: PayrollJmhzTransportEnvironment
  artifact_sha256: string
  created: boolean
  submission_kind: 'cancellation' | 'correction'
  /** Podání, které se ruší nebo opravuje — bez něj se posloupnost nedá dohledat. */
  corrects_submission_id: number
  submission_guid: string
  variable_symbol: string
  month: number
  year: number
}

export interface PayrollJmhzCorrectableComponent {
  form_guid: string
  person_external_identifier: string
  employment_external_identifier: string
}

export interface PayrollJmhzContentCorrectionForm {
  employee_name: string | null
  person_external_identifier: string
  employment_external_identifier: string
  effective_state: 'accepted' | 'rejected' | 'cancelled' | 'missing'
  action: 'correct_values' | 'complete_form'
}

export interface PayrollJmhzContentCorrectionPreparation {
  id: number
  source_revision_id: number
  revision_no: number
  period_start: string
  created_at: string
  document_sha256: string
}

export interface PayrollJmhzContentCorrectionPreparations {
  environment: PayrollJmhzTransportEnvironment
  submission_id: number
  preparations: PayrollJmhzContentCorrectionPreparation[]
  auto_selected_preparation_id: number | null
}

export interface PayrollJmhzContentCorrectionCandidates {
  environment: PayrollJmhzTransportEnvironment
  submission_id: number
  preparation_id: number
  document_sha256: string
  forms: PayrollJmhzContentCorrectionForm[]
}

export interface PayrollJmhzTransportHistory {
  environment: PayrollJmhzTransportEnvironment
  attempts: PayrollJmhzTransportAttempt[]
  ready_submissions: PayrollJmhzReadySubmission[]
  total: number
  limit: number
  offset: number
}

export interface PayrollJmhzReadySubmission {
  submission_id: number
  submission_kind: string
  submission_status: 'ready'
  corrects_submission_id: number | null
  period_start: string
  period_end: string
  created_at: string
  outbox_id: number | null
  outbox_dispatch_state: string | null
  outbox_acceptance_state: string | null
  outbox_external_message_id: string | null
}

/** Potvrzení o PŘEVZETÍ zprávy, ne o přijetí podání. */
export interface PayrollJmhzTransportAcknowledgement {
  correlation_id: string
  poll_interval_seconds: number | null
  gateway_timestamp: string | null
}

/** Kontrola z katalogu ČSSZ dohledaná ke kódu chyby. */
export interface PayrollJmhzProtocolControl {
  name: string
  detail: string | null
  area: string | null
  category: string | null
  /** Atributy, kterých se kontrola týká — bez nich se hláška nedá dohledat v datech. */
  attribute_ids: string[]
}

export interface PayrollJmhzProtocolError {
  /** Číselný kód z protokolu (DIS = ID kontroly + 20000, cJMHZ = + 40000). */
  code: number
  message: string
  origin: 'dis' | 'cjmhz' | 'platform'
  control_id: number | null
  form_guid: string | null
  ik_mpsv: string | null
  id_ppv: string | null
  /**
   * `null` u chyby, kterou náš katalog nezná — prostor kódů ČSSZ je širší.
   * Taková chyba se ukazuje syrová, nikdy se neskrývá.
   */
  control: PayrollJmhzProtocolControl | null
}

/** `status` je jméno případu výčtu na backendu, tedy PascalCase. */
export type PayrollJmhzProtocolStatus =
  | 'ProcessedAndComplete'
  | 'NotAccepted'
  | 'Rejected'
  | 'PartiallyAccepted'
  | 'Processing'
  | 'ContainsPassableErrors'

export interface PayrollJmhzProtocolReport {
  status: PayrollJmhzProtocolStatus
  errors: PayrollJmhzProtocolError[]
}

export interface PayrollJmhzTransportPoll {
  attempt: PayrollJmhzTransportAttempt
  acknowledgement: PayrollJmhzTransportAcknowledgement | null
  /** `true` teprve tehdy, když ČSSZ vrátila protokol o zpracování. */
  settled: boolean
  report: PayrollJmhzProtocolReport | null
}

/**
 * Protokol ČSSZ načtený ze souboru z datové schránky.
 *
 * Podání odeslané cizím softwarem naše aplikace nezná, takže přehled stavu
 * odeslání by u takové firmy zůstal prázdný, i když podala. Načtený protokol
 * je doklad o podání — ale NENÍ to náš pokus o odeslání, a v přehledu se tak
 * ani nesmí tvářit.
 */
export type PayrollJmhzImportedProtocolKind =
  | 'processing'
  | 'completeness'
  | 'partial_submission'

export interface PayrollJmhzImportedProtocol {
  id: number
  supplier_id: number
  environment: string
  protocol_kind: PayrollJmhzImportedProtocolKind
  /** Ověřený variabilní symbol; cizí protokol se neuloží. */
  variable_symbol: string
  period_month: number | null
  period_year: number | null
  /** `idPodani` — GUID, kterým se protokol páruje k podání. */
  submission_guid: string | null
  correlation_reference: string | null
  /** Kód stavu hlášení 1–6 podle číselníku ČSSZ. */
  status_code: number
  status_name: PayrollJmhzProtocolStatus
  error_count: number
  protocol_dated_at: string | null
  submitted_at: string | null
  source_filename: string | null
  payload_sha256: string
  row_version: number
  imported_by: number | null
  created_at: string
  updated_at: string
  /**
   * Vysvětlené chyby. Seznam protokolů je NENESE — dotahují se na vyžádání
   * přes `jmhzImportedProtocolErrors()` pro jeden rozbalený řádek.
   */
  errors?: PayrollJmhzProtocolError[]
  /** `false`, když se uložený originál nepodařilo znovu přečíst. */
  detail_available?: boolean
}

export interface PayrollJmhzImportedProtocolHistory {
  environment: PayrollJmhzTransportEnvironment
  protocols: PayrollJmhzImportedProtocol[]
  total: number
  limit: number
  offset: number
}

export interface PayrollJmhzImportedProtocolErrors {
  environment: PayrollJmhzTransportEnvironment
  protocol_id: number
  errors: PayrollJmhzProtocolError[]
  detail_available: boolean
}

export interface PayrollJmhzImportedProtocolResult {
  environment: PayrollJmhzTransportEnvironment
  protocol: PayrollJmhzImportedProtocol
  /** `false` u opakovaného načtení téhož protokolu — řádek se přepsal. */
  created: boolean
  errors: PayrollJmhzProtocolError[]
}

export const payrollApi = {
  capabilities: () =>
    api.get<PayrollCapabilitiesResponse>('/payroll/capabilities').then(response => response.data),
  activation: () =>
    api.get<{
      state: PayrollModuleState
      production_qualification: PayrollProductionQualification | null
      company_capability: PayrollCompanyCapabilityAssessment
    }>('/payroll/settings/activation')
      .then(response => response.data),
  setActivation: (payload: { enabled: boolean; start_period: string | null; row_version: number }) =>
    api.put<{ state: PayrollModuleState }>('/payroll/settings/activation', payload).then(response => response.data.state),
  qualifyProduction: (payload: {
    row_version: number
    support_matrix_version: string
    evidence: PayrollProductionQualificationEvidence
  }) => api.post<{
    state: PayrollModuleState
    qualification: PayrollProductionQualification
  }>('/payroll/settings/activation/production-qualification', payload).then(response => response.data),
  /**
   * Stránka seznamu osob. Filtr i hledání jdou na server — kdyby zužoval
   * prohlížeč, hledal by jen v načtené stránce a člověka ze třetí stránky by
   * prohlásil za neexistujícího.
   */
  peoplePage: (params: {
    limit: number
    offset: number
    filter?: PayrollPeopleFilter
    q?: string
  }) =>
    api.get<PayrollPeopleResponse>('/payroll/people', {
      params: {
        limit: params.limit,
        offset: params.offset,
        filter: params.filter,
        q: params.q === '' ? undefined : params.q,
      },
    }).then(response => response.data),
  /**
   * Jména osob pro rozbalovací nabídky. Levný pohled: server ho zvládne jedním
   * dotazem, protože nepočítá rozhodnutí o smazatelnosti, které si stránkovaný
   * seznam osob počítá řádek po řádku.
   */
  peopleOptions: () =>
    api.get<PayrollPeopleOptionsResponse>('/payroll/people', { params: { view: 'options' } })
      .then(response => response.data.items),
  createPerson: (payload: PayrollPersonCreatePayload) =>
    api.post<PayrollPersonResponse>('/payroll/people', payload)
      .then(response => response.data.person),
  person: (id: number) =>
    api.get<PayrollPersonResponse>(`/payroll/people/${id}`).then(response => response.data.person),
  personProfile: (id: number) =>
    api.get<{ profile: PayrollPersonProfile }>(`/payroll/people/${id}/profile`)
      .then(response => response.data.profile),
  /** Zákonná evidence osoby k danému dni včetně celé historie a blokátorů běhu. */
  statutoryEvidence: (employeeId: number, effectiveOn: string) =>
    api.get<{ evidence: PayrollStatutoryEvidence }>(
      `/payroll/people/${employeeId}/statutory-evidence`,
      { params: { effective_on: effectiveOn } },
    ).then(response => response.data.evidence),
  saveStatutoryEvidence: (
    employeeId: number,
    payload: PayrollStatutoryEvidencePayload,
  ) =>
    api.put<{ evidence: PayrollStatutoryEvidence }>(
      `/payroll/people/${employeeId}/statutory-evidence`,
      payload,
    ).then(response => response.data.evidence),
  foreignPermits: (employeeId: number, asOf?: string) =>
    api.get<{ permits: PayrollForeignPermitView }>(
      `/payroll/people/${employeeId}/foreign-permits`,
      { params: asOf === undefined ? {} : { as_of: asOf } },
    ).then(response => response.data.permits),
  createForeignPermit: (employeeId: number, payload: PayrollForeignPermitPayload) =>
    api.post<{ permits: PayrollForeignPermitView }>(
      `/payroll/people/${employeeId}/foreign-permits`,
      payload,
    ).then(response => response.data.permits),
    /** Počáteční stavy zákonných kumulací za rok — úhrny z předchozího zpracování. */
  statutoryOpenings: (employeeId: number, year: number) =>
    api.get<{ openings: PayrollOpeningBalances }>(
      `/payroll/people/${employeeId}/statutory-openings`,
      { params: { year } },
    ).then(response => response.data.openings),
  saveStatutoryOpenings: (
    employeeId: number,
    payload: { year: number, source_reference?: string, months: PayrollOpeningMonth[] },
  ) =>
    api.put<{ openings: PayrollOpeningBalances }>(
      `/payroll/people/${employeeId}/statutory-openings`,
      payload,
    ).then(response => response.data.openings),
  /** Označení vztahu pro import docházky — párovací klíč CSV, ne údaj o vztahu. */
  renameEmployment: (employmentId: number, rowVersion: number, code: string) =>
    api.patch<{ employment: PayrollEmployment }>(
      `/payroll/employments/${employmentId}/code`,
      { row_version: rowVersion, code },
    ).then(response => response.data.employment),
  setEmploymentMealEntitlementBasis: (
    employmentId: number,
    rowVersion: number,
    mealEntitlementBasis: PayrollMealEntitlementBasis,
  ) =>
    api.patch<{ employment: PayrollEmployment }>(
      `/payroll/employments/${employmentId}/meal-entitlement-basis`,
      { row_version: rowVersion, meal_entitlement_basis: mealEntitlementBasis },
    ).then(response => response.data.employment),
  savePersonProfile: (id: number, payload: PayrollPersonProfilePayload) =>
    api.put<{ profile: PayrollPersonProfile }>(`/payroll/people/${id}/profile`, payload)
      .then(response => response.data.profile),
  /**
   * Odkrytí maskovaných údajů. Endpoint existoval od začátku, ale nikdo ho nevolal —
   * karta zaměstnance tak ukazovala „••••4523" bez možnosti se na vlastní data podívat.
   *
   * `reason` je povinný (10–500 znaků) a zapisuje se do auditní stopy. Kartě stačí
   * konstantní důvod: kdo se dívá a kdy, plyne ze záznamu, a dialog na každý pohled
   * by z běžné práce udělal obřad.
   */
  revealPersonSensitive: (id: number) =>
    api.post<{ sensitive: PayrollPersonSensitiveReveal }>(
      `/payroll/people/${id}/sensitive-reveal`,
      { reason: 'Zobrazení údajů na kartě zaměstnance' },
    ).then(response => response.data.sensitive),
  personDependants: (id: number) =>
    api.get<PayrollDependantsResponse>(`/payroll/people/${id}/dependants`)
      .then(response => response.data),
  createPersonDependant: (id: number, payload: PayrollDependantPayload) =>
    api.post<PayrollDependantsResponse>(`/payroll/people/${id}/dependants`, payload)
      .then(response => response.data),
  savePersonDependant: (id: number, dependantId: number, payload: PayrollDependantPayload) =>
    api.put<PayrollDependantsResponse>(
      `/payroll/people/${id}/dependants/${dependantId}`,
      payload,
    ).then(response => response.data),
  createPersonDependantClaim: (
    id: number,
    dependantId: number,
    payload: PayrollDependantClaimPayload,
  ) =>
    api.post<PayrollDependantsResponse>(
      `/payroll/people/${id}/dependants/${dependantId}/claims`,
      payload,
    ).then(response => response.data),
  savePersonDependantClaim: (
    id: number,
    dependantId: number,
    claimId: number,
    payload: PayrollDependantClaimPayload,
  ) =>
    api.put<PayrollDependantsResponse>(
      `/payroll/people/${id}/dependants/${dependantId}/claims/${claimId}`,
      payload,
    ).then(response => response.data),
  savePersonQuickEdit: (id: number, payload: PayrollPersonQuickEditPayload) =>
    api.put<PayrollPersonQuickEditResponse>(`/payroll/people/${id}/quick-edit`, payload)
      .then(response => response.data),
  verifyPersonAccount: (
    personId: number,
    accountId: number,
    payload: PayrollPersonAccountVerificationPayload,
  ) =>
    api.post<{ account: PayrollPersonVerifiedAccount }>(
      `/payroll/people/${personId}/accounts/${accountId}/verify`,
      payload,
    ).then(response => response.data.account),
  personPayoutRules: (personId: number) =>
    api.get<PayrollPayoutRulesResponse>(
      `/payroll/people/${personId}/payout-rules`,
    ).then(response => response.data),
  createPersonPayoutRule: (personId: number, payload: PayrollPayoutRulePayload) =>
    api.post<{ rule: PayrollPayoutRule; warnings: PayrollPayoutRuleWarning[] }>(
      `/payroll/people/${personId}/payout-rules`,
      payload,
    ).then(response => response.data),
  updatePersonPayoutRule: (
    personId: number,
    ruleId: number,
    payload: PayrollPayoutRulePayload & { row_version: number },
  ) =>
    api.put<{ rule: PayrollPayoutRule; warnings: PayrollPayoutRuleWarning[] }>(
      `/payroll/people/${personId}/payout-rules/${ruleId}`,
      payload,
    ).then(response => response.data),
  // Server pravidlo jen deaktivuje (zmrazené alokace na něj odkazují), proto
  // DELETE vrací celý řádek. `row_version` jde v těle — axios ho u DELETE
  // posílá přes `data`.
  deactivatePersonPayoutRule: (personId: number, ruleId: number, rowVersion: number) =>
    api.delete<{ rule: PayrollPayoutRule; warnings: PayrollPayoutRuleWarning[] }>(
      `/payroll/people/${personId}/payout-rules/${ruleId}`,
      { data: { row_version: rowVersion } },
    ).then(response => response.data),
  applyPersonPayoutRuleDefaults: (personId: number) =>
    api.post<PayrollPayoutRulesResponse>(
      `/payroll/people/${personId}/payout-rules/apply-defaults`,
    ).then(response => response.data),
  createEmployment: (personId: number, payload: PayrollEmploymentCreatePayload) =>
    api.post<{ employment: PayrollEmployment }>(`/payroll/people/${personId}/employments`, payload)
      .then(response => response.data.employment),
  addEmploymentTerms: (employmentId: number, rowVersion: number, payload: PayrollEmploymentTermsPayload) =>
    api.put<{ employment: PayrollEmployment }>(`/payroll/employments/${employmentId}/terms`, {
      row_version: rowVersion,
      ...payload,
    }).then(response => response.data.employment),
  employmentJmhzEvidenceOptions: () =>
    api.get<{ options: PayrollEmploymentJmhzEvidenceOptions }>(
      '/payroll/jmhz/employment-evidence-options',
    ).then(response => response.data.options),
  searchJmhzMunicipalities: (query: string, limit = 20) =>
    api.get<{ items: PayrollJmhzMunicipalityOption[] }>(
      '/payroll/jmhz/municipalities',
      { params: { q: query, limit } },
    ).then(response => response.data.items),
  // Hledání v CZ-ISCO běží na serveru — číselník má skoro dva tisíce položek
  // a do bundlu nepatří. Dotaz kratší než dva znaky vrátí 422, volající ho
  // proto vůbec nemá posílat.
  searchCzIsco: (query: string, limit = 20) =>
    api.get<PayrollCzIscoSearchResult>(
      '/payroll/cz-isco',
      { params: { q: query, limit } },
    ).then(response => response.data),
  transitionEmployment: (
    employmentId: number,
    target: PayrollEmploymentStatus,
    payload: { row_version: number; effective_on: string; note?: string | null },
  ) =>
    api.post<{ employment: PayrollEmployment }>(
      `/payroll/employments/${employmentId}/transitions/${target}`,
      payload,
    ).then(response => response.data.employment),
  /**
   * Smazání vztahu, který vůbec neměl vzniknout. Není to náhrada za „nenástup" —
   * ten je záznam o tom, že člověk byl přijat a nenastoupil.
   */
  deleteEmployment: (employmentId: number, rowVersion: number) =>
    api.delete<{ deleted: boolean; cascade: PayrollDeleteCascade }>(
      `/payroll/employments/${employmentId}`,
      { data: { row_version: rowVersion } },
    ).then(response => response.data.cascade),
  deletePerson: (employeeId: number) =>
    api.delete<{ deleted: boolean; cascade: PayrollDeleteCascade }>(
      `/payroll/people/${employeeId}`,
    ).then(response => response.data.cascade),
  updateEmploymentChecklist: (
    employmentId: number,
    itemKey: string,
    payload: { row_version: number; status: PayrollChecklistStatus; note?: string | null },
  ) =>
    api.put<{ employment: PayrollEmployment }>(
      `/payroll/employments/${employmentId}/checklist/${itemKey}`,
      payload,
    ).then(response => response.data.employment),
  accountOptions: () =>
    api.get<{ accounts: PayrollAccountOption[] }>('/payroll/settings/account-options')
      .then(response => response.data.accounts),
  employerSettings: () =>
    api.get<PayrollEmployerSettingsResponse>('/payroll/settings/employer').then(response => response.data.settings),
  saveEmployerSettings: (payload: PayrollEmployerSettingsPayload) =>
    api.put<PayrollEmployerSettingsResponse>('/payroll/settings/employer', payload).then(response => response.data.settings),
  officeRegistrations: (officeId: number) =>
    api.get<{ registrations: PayrollOfficeRegistration[] }>(`/payroll/settings/offices/${officeId}/registrations`)
      .then(response => response.data.registrations),
  createOfficeRegistration: (officeId: number, payload: Pick<PayrollOfficeRegistration,
    'effective_from' | 'social_security_variable_symbol' | 'source_reference'>) =>
    api.post<{ registration: PayrollOfficeRegistration }>(`/payroll/settings/offices/${officeId}/registrations`, payload)
      .then(response => response.data.registration),
  /**
   * `agenda_group` filtruje na SERVERU. Odfiltrovat si skupinu až z přijaté
   * stránky by znamenalo pager počítaný přes všechny agendy nad tabulkou,
   * která ukazuje jen jednu.
   */
  submissionOverview: (
    environment: PayrollRegzelEnvironment,
    period: string,
    options?: PayrollPageParams & { agenda_group?: PayrollSubmissionAgendaGroup },
  ) =>
    api.get<PayrollSubmissionOverviewResponse>('/payroll/submissions/overview', {
      params: {
        environment,
        period,
        ...(options?.agenda_group ? { agenda_group: options.agenda_group } : {}),
        ...pageParams(options),
      },
    }).then(response => response.data),
  operationalHealth: () =>
    api.get<PayrollOperationalHealth>('/payroll/operational-health')
      .then(response => response.data),
  statutoryObligationOverview: (
    environment: PayrollRegzelEnvironment,
    period: string,
  ) => api.get<PayrollStatutoryObligationOverview>(
    '/payroll/submissions/statutory-obligations',
    { params: { environment, period } },
  ).then(response => response.data),
  recordStatutoryObligationEvidence: (
    payload: PayrollStatutoryObligationEvidencePayload,
    idempotencyKey: string,
  ) => api.post<{
    evidence: PayrollStatutoryObligationEvidence
    created: boolean
  }>(
    '/payroll/submissions/statutory-obligations/evidence',
    payload,
    { headers: { 'Idempotency-Key': idempotencyKey } },
  ).then(response => response.data),
  submissionDetail: (submissionId: number) =>
    api.get<PayrollSubmissionDetail>(`/payroll/submissions/${submissionId}`)
      .then(response => response.data),
  submissionInbox: (
    environment: PayrollRegzelEnvironment,
    page?: PayrollPageParams & { status?: PayrollSubmissionInboxStatusFilter },
  ) =>
    api.get<PayrollSubmissionInboxResponse>('/payroll/submissions/inbox', {
      params: {
        environment,
        ...(page?.status === undefined ? {} : { status: page.status }),
        ...pageParams(page),
      },
    }).then(response => response.data),
  acknowledgeSubmissionInboxItem: (itemId: number, rowVersion: number) =>
    api.post<{ id: number; status: string; row_version: number }>(
      `/payroll/submissions/inbox/${itemId}/acknowledge`,
      { row_version: rowVersion },
    ).then(response => response.data),
  snoozeSubmissionInboxItem: (
    itemId: number,
    rowVersion: number,
    snoozedUntil: string,
    reason: string,
  ) =>
    api.post<{ id: number; status: string; row_version: number; snoozed_until: string }>(
      `/payroll/submissions/inbox/${itemId}/snooze`,
      { row_version: rowVersion, snoozed_until: snoozedUntil, reason },
    ).then(response => response.data),
  downloadSubmissionArtifact: async (
    submissionId: number,
    artifact: PayrollSubmissionDetail['artifacts'][number],
  ): Promise<void> => {
    const grant = await api.post<{ token: string; expires_at: string }>(
      `/payroll/submissions/${submissionId}/artifacts/${artifact.id}/download-grant`,
    ).then(response => response.data)
    let response
    try {
      response = await api.get<Blob>(
        `/payroll/submissions/${submissionId}/artifacts/${artifact.id}/download`,
        {
          responseType: 'blob',
          headers: { 'X-Payroll-Download-Token': grant.token },
        },
      )
    } catch (error: any) {
      const data = error?.response?.data
      if (data instanceof Blob) {
        try {
          error.response.data = JSON.parse(await data.text())
        } catch {
          error.response.data = data
        }
      }
      throw error
    }
    const disposition = response.headers['content-disposition']
    const matchedFilename = typeof disposition === 'string'
      ? /filename="([^"]+)"/u.exec(disposition)?.[1]
      : undefined
    const extension = artifact.mime_type === 'application/xml'
      ? 'xml'
      : artifact.mime_type === 'application/pdf'
        ? 'pdf'
        : artifact.mime_type === 'application/zip'
          ? 'zip'
          : artifact.mime_type === 'application/json'
            ? 'json'
            : 'bin'
    const objectUrl = URL.createObjectURL(response.data)
    try {
      const anchor = document.createElement('a')
      anchor.href = objectUrl
      anchor.download = matchedFilename
        ?? `mzdove-podani-${submissionId}-artefakt-${artifact.id}.${extension}`
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
    } finally {
      URL.revokeObjectURL(objectUrl)
    }
  },
  /**
   * Mzdové účtárny, za které se z revize podává. Bez nich nemá uživatel kde
   * zjistit, kterou registraci má do náhledu poslat.
   */
  jmhzPvpojOffices: (revisionId: number) =>
    api.get<{ offices: PayrollJmhzPvpojOffice[] }>(
      `/payroll/submissions/jmhz-pvpoj/${revisionId}/offices`,
    ).then(response => response.data.offices),
  jmhzEmployerAnnualEvidence: (reportYear: number) =>
    api.get<PayrollJmhzEmployerAnnualEvidenceView>(
      `/payroll/submissions/jmhz-employer-annual-evidence/${reportYear}`,
    ).then(response => response.data),
  saveJmhzEmployerAnnualEvidence: (
    reportYear: number,
    payload: PayrollJmhzEmployerAnnualEvidencePayload,
  ) => api.post<PayrollJmhzEmployerAnnualEvidenceView>(
    `/payroll/submissions/jmhz-employer-annual-evidence/${reportYear}`,
    payload,
  ).then(response => response.data),
  jmhzPvpojPreview: (revisionId: number, officeId?: number | null) =>
    api.get<PayrollJmhzPvpojPreview>(
      `/payroll/submissions/jmhz-pvpoj/${revisionId}`,
      officeId == null ? undefined : { params: { office: officeId } },
    ).then(response => response.data),
  jmhzOrdinaryEvidence: (revisionId: number) =>
    api.get<PayrollJmhzOrdinaryEvidenceState>(
      `/payroll/submissions/jmhz-ordinary-evidence/${revisionId}`,
    ).then(response => response.data),
  jmhzIdentity: (
    employmentId: number,
    environment: PayrollRegzelEnvironment,
    onDate: string,
  ) => api.get<{ identity: PayrollJmhzIdentityStatus }>(
    `/payroll/jmhz/identities/${employmentId}`,
    { params: { environment, on_date: onDate } },
  ).then(response => response.data.identity),
  saveJmhzIdentity: (
    employmentId: number,
    payload: PayrollJmhzIdentityPayload,
  ) => api.put<{ assigned: Record<string, unknown> }>(
    `/payroll/jmhz/identities/${employmentId}`,
    payload,
  ).then(response => response.data.assigned),
  confirmJmhzOrdinaryEvidence: (
    revisionId: number,
    employmentId: number,
    idempotencyKey: string,
  ) => api.post<PayrollJmhzOrdinaryEvidence>(
    `/payroll/submissions/jmhz-ordinary-evidence/${revisionId}/${employmentId}`,
    {
      facts: {
        reportable_wage_deductions_recorded: false,
        employee_social_discount_claimed: false,
        specific_legal_fact_occurred: false,
        ozp_employment_support_claimed: false,
        deep_mining_work_occurred: false,
      },
      evidence_confirmed: true,
    },
    { headers: { 'Idempotency-Key': idempotencyKey } },
  ).then(response => response.data),
  freezeJmhzPreparation: (
    revisionId: number,
    idempotencyKey: string,
    environment: 'test' | 'production' = 'test',
  ) => api.post<PayrollJmhzPreparation>(
    `/payroll/submissions/jmhz-preparation/${revisionId}`,
    { environment },
    { headers: { 'Idempotency-Key': idempotencyKey } },
  ).then(response => response.data),
  jmhzXmlDryRun: (
    preparationId: number,
    environment: 'test' | 'production' = 'test',
    officeId?: number | null,
  ) => api.get<PayrollJmhzXmlDryRun>(
    `/payroll/submissions/jmhz-xml-dry-run/${preparationId}`,
    {
      params: officeId == null
        ? { environment }
        : { environment, office: officeId },
    },
  ).then(response => response.data),
  freezeJmhzSubmission: (
    preparationId: number,
    obligationId: number | null,
    environment: PayrollJmhzTransportEnvironment,
    officeId?: number | null,
  ) => api.post<PayrollJmhzFrozenSubmission>(
    `/payroll/submissions/jmhz-freeze/${preparationId}`,
    {
      environment,
      ...(obligationId == null ? {} : { obligation_id: obligationId }),
      ...(officeId == null ? {} : { office: officeId }),
    },
  ).then(response => response.data),
  previewEmploymentRegistration: (
    employmentId: number,
    environment: 'test' | 'production' = 'test',
    eventId?: number | null,
  ) => api.get<PayrollRegistrationPreview>(
    `/payroll/submissions/registration/${employmentId}`,
    { params: { environment, ...(eventId == null ? {} : { event_id: eventId }) } },
  ).then(response => response.data),
  prepareEmploymentRegistration: (
    employmentId: number,
    environment: 'test' | 'production' = 'test',
    eventId?: number | null,
  ) => api.post<PayrollRegistrationSubmission>(
    `/payroll/submissions/registration/${employmentId}`,
    { environment, ...(eventId == null ? {} : { event_id: eventId }) },
  ).then(response => response.data),
  employmentRegistrationEvents: (
    employmentId: number,
    environment: PayrollJmhzTransportEnvironment = 'test',
  ) => api.get<{ items: PayrollRegistrationEvent[] }>(
    `/payroll/submissions/registration/${employmentId}/events`,
    { params: { environment } },
  ).then(response => response.data.items),
  approveEmploymentRegistrationEvent: (
    employmentId: number,
    payload: PayrollRegistrationEventInput,
  ) => api.post<PayrollRegistrationEvent>(
    `/payroll/submissions/registration/${employmentId}/events`,
    payload,
  ).then(response => response.data),
  sendEmploymentRegistrationTransport: (
    submissionId: number,
    environment: PayrollJmhzTransportEnvironment,
    idempotencyKey: string,
  ) => api.post<PayrollRegistrationTransportResult>(
    `/payroll/submissions/registration-transport/${submissionId}`,
    { environment },
    { headers: { 'Idempotency-Key': idempotencyKey } },
  ).then(response => response.data),
  employmentRegistrationTransportStatus: (
    submissionId: number,
    environment: PayrollJmhzTransportEnvironment,
  ) => api.get<PayrollRegistrationTransportStatus>(
    `/payroll/submissions/registration-transport/${submissionId}`,
    { params: { environment } },
  ).then(response => response.data),
  /**
   * Ruční dotaz na výsledek PREZEC/REGZEC. Registrace nemají automatický poll;
   * každé síťové volání vyvolá účetní z detailu pracovního vztahu.
   */
  pollEmploymentRegistrationTransportAttempt: (
    attemptId: number,
    environment: PayrollJmhzTransportEnvironment,
  ) => api.post<PayrollJmhzTransportPoll>(
    `/payroll/submissions/registration-transport/${attemptId}/poll`,
    { environment },
  ).then(response => response.data),
  closeEmploymentRegistrationTransportAttempt: (
    attemptId: number,
    environment: PayrollJmhzTransportEnvironment,
  ) => api.post<{
    closed: boolean
    already_closed: boolean
    attempt: PayrollJmhzTransportAttempt
  }>(
    `/payroll/submissions/registration-transport/${attemptId}/close`,
    { environment },
  ).then(response => response.data),
  downloadJmhzPvpojPreview: async (
    preview: PayrollJmhzPvpojPreview,
  ): Promise<void> => {
    const response = await api.get<Blob>(
      `/payroll/submissions/jmhz-pvpoj/${preview.revision_id}/download`,
      {
        responseType: 'blob',
        // Stažení musí trefit TUTÉŽ registraci jako náhled — jinak by soubor
        // pojmenovaný podle jedné účtárny nesl čísla jiné.
        params: { office: preview.office.office_id },
      },
    )
    const objectUrl = URL.createObjectURL(response.data)
    try {
      const anchor = document.createElement('a')
      anchor.href = objectUrl
      anchor.download = preview.filename
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
    } finally {
      URL.revokeObjectURL(objectUrl)
    }
  },
  healthPaymentOverviews: (revisionId: number) =>
    api.get<{
      items: PayrollHealthPaymentOverview[]
      electronic_submission: {
        direct_portal: { supported: false; reason_code: string }
        isds: {
          supported: true
          requires_ready: true
          requires_production_gate: true
          requires_user_confirmation: true
        }
      }
    }>(`/payroll/submissions/health-overviews/${revisionId}`)
      .then(response => response.data),
  downloadHealthPaymentOverview: async (
    overview: PayrollHealthPaymentOverview,
  ): Promise<void> => {
    const response = await api.get<Blob>(
      `/payroll/submissions/health-overviews/${overview.revision_id}/${overview.insurer.code}/download`,
      { responseType: 'blob' },
    )
    const disposition = response.headers['content-disposition']
    const matchedFilename = typeof disposition === 'string'
      ? /filename="([^"]+)"/u.exec(disposition)?.[1]
      : undefined
    const contentType = String(
      response.headers['content-type'] ?? response.data.type,
    )
    const extension = contentType.includes('pdf') ? 'pdf' : 'xml'
    const objectUrl = URL.createObjectURL(response.data)
    try {
      const anchor = document.createElement('a')
      anchor.href = objectUrl
      anchor.download = matchedFilename
        ?? `zp-prehled-${overview.period}-${overview.insurer.code}-revize-${overview.revision_id}.${extension}`
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
    } finally {
      URL.revokeObjectURL(objectUrl)
    }
  },
  eldpStatement: (params: {
    employment_id: number
    year: number
    environment: PayrollRegzelEnvironment
  }) =>
    api.get<{
      statement: PayrollEldpStatement | null
      supported: PayrollEldpSupport
      manual_completion: PayrollEldpManualCompletionOverview | null
    }>('/payroll/submissions/eldp', { params })
      .then(response => response.data),
  prepareEldp: (payload: {
    employment_id: number
    year: number
    environment: PayrollRegzelEnvironment
    excluded_days_confirmed: boolean
    deducted_days_none: boolean
    requested_by_authority: boolean
    authority_request_received_on: string | null
    note: string
    idempotency_key: string
  }) =>
    api.post<{ statement: PayrollEldpPrepared }>('/payroll/submissions/eldp', payload)
      .then(response => response.data.statement),
  completeEldp: (statementId: number, payload: {
    environment: PayrollRegzelEnvironment
    expected_obligation_row_version: number
    authority_status: PayrollEldpAuthorityStatus
    confirmation_document_id: number
    authority_reference: string
    confirmed_on: string
    idempotency_key: string
  }) =>
    api.post<{ manual_completion: PayrollEldpManualCompletionResult }>(
      `/payroll/submissions/eldp/${statementId}/manual-completion`,
      payload,
    ).then(response => response.data.manual_completion),
  regzelProfile: () =>
    api.get<PayrollRegzelProfileResponse>('/payroll/submissions/regzel/profile')
      .then(response => response.data),
  saveRegzelProfile: (payload: PayrollRegzelProfilePayload) =>
    api.put<{ profile: PayrollRegzelProfile }>('/payroll/submissions/regzel/profile', payload)
      .then(response => response.data.profile),
  regzelSnapshots: (environment: PayrollRegzelEnvironment, page?: PayrollPageParams) =>
    api.get<{
      environment: PayrollRegzelEnvironment
      items: PayrollRegzelSnapshot[]
      total: number
      limit: number
      offset: number
    }>('/payroll/submissions/regzel/snapshots', {
      params: { environment, ...pageParams(page) },
    }).then(response => response.data),
  prepareRegzel: (payload: {
    office_id: number
    environment: PayrollRegzelEnvironment
    evidence_confirmed: boolean
    idempotency_key: string
  }) =>
    api.post<{ snapshot: PayrollRegzelSnapshot }>('/payroll/submissions/regzel/prepare', payload)
      .then(response => response.data.snapshot),
  downloadRegzelSnapshot: async (snapshot: PayrollRegzelSnapshot): Promise<void> => {
    const response = await api.get<Blob>(
      `/payroll/submissions/regzel/snapshots/${snapshot.id}/xml`,
      {
        params: { environment: snapshot.environment },
        responseType: 'blob',
      },
    )
    const objectUrl = URL.createObjectURL(response.data)
    try {
      const anchor = document.createElement('a')
      anchor.href = objectUrl
      anchor.download = `REGZELDOPL25-${snapshot.environment === 'test' ? 'TEST' : 'PRODUKCE'}-${snapshot.id}.xml`
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
    } finally {
      URL.revokeObjectURL(objectUrl)
    }
  },
  employerPolicies: (effectiveOn?: string, page?: PayrollPageParams) =>
    api.get<{ policies: PayrollEmployerPolicy[]; total: number }>('/payroll/settings/policies', {
      params: {
        ...(effectiveOn ? { effective_on: effectiveOn } : {}),
        ...pageParams(page),
      },
    }).then(response => ({
      items: response.data.policies,
      total: response.data.total,
    })),
  createEmployerPolicy: (payload: PayrollEmployerPolicyPayload) =>
    api.post<{ policy: PayrollEmployerPolicy }>('/payroll/settings/policies', payload)
      .then(response => response.data.policy),
  updateEmployerPolicy: (id: number, payload: PayrollEmployerPolicyPayload) =>
    api.put<{ policy: PayrollEmployerPolicy }>(`/payroll/settings/policies/${id}`, payload)
      .then(response => response.data.policy),
  payrollSetupCheck: (effectiveOn: string) =>
    api.get<{ setup: PayrollSetupCheck }>('/payroll/setup-check', {
      params: { effective_on: effectiveOn },
    }).then(response => response.data.setup),
  institutionAccounts: (effectiveOn?: string) =>
    api.get<{ accounts: PayrollInstitutionAccount[] }>('/payroll/settings/institution-accounts', {
      params: effectiveOn ? { effective_on: effectiveOn } : undefined,
    }).then(response => response.data.accounts),
  createInstitutionAccount: (payload: PayrollInstitutionAccountCreatePayload) =>
    api.post<{ account: PayrollInstitutionAccount }>('/payroll/settings/institution-accounts', payload)
      .then(response => response.data.account),
  updateInstitutionAccount: (id: number, payload: PayrollInstitutionAccountUpdatePayload) =>
    api.put<{ account: PayrollInstitutionAccount }>(`/payroll/settings/institution-accounts/${id}`, payload)
      .then(response => response.data.account),
  payrollDimensions: (dimensionType?: PayrollDimensionType) =>
    api.get<{ dimensions: PayrollDimension[] }>('/payroll/settings/dimensions', {
      params: dimensionType ? { type: dimensionType } : undefined,
    }).then(response => response.data.dimensions),
  createPayrollDimension: (payload: PayrollDimensionPayload) =>
    api.post<{ dimension: PayrollDimension }>('/payroll/settings/dimensions', payload)
      .then(response => response.data.dimension),
  updatePayrollDimension: (id: number, payload: PayrollDimensionPayload) =>
    api.put<{ dimension: PayrollDimension }>(`/payroll/settings/dimensions/${id}`, payload)
      .then(response => response.data.dimension),
  deletePayrollDimension: (id: number) =>
    api.delete<{ deleted: boolean }>(`/payroll/settings/dimensions/${id}`)
      .then(response => response.data.deleted),
  /**
   * Souhrn navazujících agend jednoho vztahu (rozcestník na kartě zaměstnance).
   *
   * Jeden dotaz místo deseti: bez něj by karta musela sáhnout do každé agendy
   * zvlášť a tři z nich vracejí celý měsíc za celou firmu. Agendy, na které
   * uživatel nemá právo, server do odpovědi vůbec nedá.
   */
  employmentAgendaSummary: (employmentId: number) =>
    api.get<{ summary: PayrollEmploymentAgendaSummary }>(
      `/payroll/employments/${employmentId}/agenda-summary`,
    ).then(response => response.data.summary),
  employmentDimensions: (employmentId: number) =>
    api.get<{ dimensions: PayrollEmploymentDimension[] }>(`/payroll/employments/${employmentId}/dimensions`)
      .then(response => response.data.dimensions),
  createEmploymentDimension: (employmentId: number, payload: PayrollEmploymentDimensionPayload) =>
    api.post<{ dimension: PayrollEmploymentDimension }>(
      `/payroll/employments/${employmentId}/dimensions`,
      payload,
    ).then(response => response.data.dimension),
  updateEmploymentDimension: (
    employmentId: number,
    assignmentId: number,
    payload: PayrollEmploymentDimensionPayload,
  ) =>
    api.put<{ dimension: PayrollEmploymentDimension }>(
      `/payroll/employments/${employmentId}/dimensions/${assignmentId}`,
      payload,
    ).then(response => response.data.dimension),
  /**
   * `employeeId` zúží seznam na jednu osobu už na serveru. Zužovat načtenou
   * stránku v prohlížeči nešlo: dokument z jiné strany se tiše neprojevil.
   */
  listDocuments: (period: string, page?: PayrollPageParams, employeeId?: number) =>
    api.get<PayrollDocumentList>('/payroll/documents', {
      params: {
        period,
        ...pageParams(page),
        ...(employeeId ? { employee_id: employeeId } : {}),
      },
    }).then(response => response.data),
  listAnnualDocuments: (year: number, page?: PayrollPageParams, employeeId?: number) =>
    api.get<PayrollAnnualDocumentList>('/payroll/documents/annual', {
      params: {
        year,
        ...pageParams(page),
        ...(employeeId ? { employee_id: employeeId } : {}),
      },
    }).then(response => response.data),
  listAnnualSettlements: (
    year: number,
    page?: PayrollPageParams,
    filters?: { search?: string; state?: PayrollAnnualSettlementListState },
  ) =>
    api.get<PayrollAnnualSettlementList>(`/payroll/annual-settlements/${year}`, {
      params: {
        ...pageParams(page),
        ...(filters?.search ? { search: filters.search } : {}),
        ...(filters?.state && filters.state !== 'all' ? { state: filters.state } : {}),
      },
    }).then(response => response.data),
  previewAnnualSettlement: (year: number, employeeId: number) =>
    api.get<PayrollAnnualSettlementPreview>(
      `/payroll/annual-settlements/${year}/people/${employeeId}`,
    ).then(response => response.data),
  saveAnnualSettlementRequest: (
    year: number,
    employeeId: number,
    payload: PayrollAnnualSettlementRequestPayload,
  ) =>
    api.put<{ request: PayrollAnnualSettlementRequest }>(
      `/payroll/annual-settlements/${year}/people/${employeeId}/request`,
      payload,
    ).then(response => response.data.request),
  /**
   * Uloží CELÝ seznam potvrzení od předchozích plátců za rok (§ 38ch odst. 3).
   * Doklady dávají smysl jen jako úplná sada od všech předchozích plátců,
   * takže se posílají jedním požadavkem, ne po řádcích.
   */
  saveAnnualSettlementCertificates: (
    year: number,
    employeeId: number,
    certificates: PayrollAnnualSettlementCertificatePayload[],
  ) =>
    api.put<{ certificates: PayrollAnnualSettlementCertificate[] }>(
      `/payroll/annual-settlements/${year}/people/${employeeId}/certificates`,
      { certificates },
    ).then(response => response.data.certificates),
  /**
   * Provede roční zúčtování. Nesplněné podmínky NEJSOU chyba — vrátí se
   * `performed: false` a seznam překážek, které má obrazovka vypsat.
   */
  settleAnnualSettlement: (year: number, employeeId: number) =>
    api.post<PayrollAnnualSettlementRun>(
      `/payroll/annual-settlements/${year}/people/${employeeId}/settle`,
      {},
    ).then(response => response.data),
  yearCloseStatus: (year: number) =>
    api.get<PayrollYearCloseStatusResponse>(`/payroll/year-close/${year}`)
      .then(response => response.data),
  closeYear: (year: number, rowVersion: number) =>
    api.post<{ closure: PayrollYearClose }>(`/payroll/year-close/${year}/close`, {
      row_version: rowVersion,
    }).then(response => response.data.closure),
  reopenYear: (year: number, rowVersion: number, reason: string) =>
    api.post<{ closure: PayrollYearClose }>(`/payroll/year-close/${year}/reopen`, {
      row_version: rowVersion,
      reason,
    }).then(response => response.data.closure),
  annualReport: (year: number) =>
    api.get<{ report: PayrollAnnualReport }>(`/payroll/reports/annual/${year}`)
      .then(response => response.data.report),
  generatePayrollSheet: (employeeId: number, year: number) =>
    api.post<PayrollDocument>(
      `/payroll/people/${employeeId}/documents/payroll-sheet/${year}`,
      {},
    ).then(response => response.data),
  generateTaxCertificate: (
    employeeId: number,
    year: number,
    kind: PayrollTaxCertificateKind,
    payload: PayrollTaxCertificateGenerationPayload,
  ) => {
    const routeKind = kind === 'taxable_income_advance_certificate'
      ? 'advance'
      : 'withholding'
    return api.post<PayrollDocument>(
      `/payroll/people/${employeeId}/documents/tax-certificate/${routeKind}/${year}`,
      payload,
    ).then(response => response.data)
  },
  generateMonthlyBundle: (runId: number, revisionId: number, idempotencyKey: string) =>
    api.post<PayrollDocument>(
      `/payroll/runs/${runId}/revisions/${revisionId}/documents/monthly-bundle`,
      {},
      { headers: { 'Idempotency-Key': idempotencyKey } },
    ).then(response => response.data),
  downloadPeriodExport: async (
    scope: PayrollPeriodExportScope,
    period: string | number,
  ): Promise<PayrollPeriodExport> => {
    let job = await api.post<PayrollPeriodExportJob>(
      `/payroll/exports/${scope}/${period}`,
      {},
    ).then(response => response.data)
    for (let poll = 0; job.status !== 'completed' && poll < 120; poll += 1) {
      if (job.status === 'failed') {
        throw new Error(job.last_error_message ?? 'Export mezd selhal.')
      }
      await new Promise<void>((resolve) => window.setTimeout(resolve, 1000))
      job = await api.get<PayrollPeriodExportJob>(
        `/payroll/exports/jobs/${job.id}`,
      ).then(response => response.data)
    }
    if (job.status !== 'completed' || job.export_id === null) {
      throw new Error('Export mezd se nedokončil v očekávaném čase.')
    }
    const grant = await api.post<{
      grant_id: number
      export_id: number
      token: string
      expires_at: string
    }>(
      `/payroll/exports/jobs/${job.id}/download-grants`,
      { ttl_seconds: 120 },
    ).then(response => response.data)
    const response = await api.post<Blob>(
      '/payroll/exports/download',
      { token: grant.token },
      { responseType: 'blob' },
    )
    const objectUrl = URL.createObjectURL(response.data)
    try {
      const anchor = document.createElement('a')
      anchor.href = objectUrl
      anchor.download = `mzdy-${scope === 'monthly' ? String(period) : String(period)}.zip`
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
    } finally {
      URL.revokeObjectURL(objectUrl)
    }

    return {
      id: job.export_id,
      scope: job.scope,
      period_start: job.period_start,
      period_end: job.period_end,
      file_sha256: '',
      size_bytes: 0,
      suggested_filename: `mzdy-${scope === 'monthly' ? String(period) : String(period)}.zip`,
    }
  },
  employmentExitDocuments: (employmentId: number) =>
    api.get<PayrollEmploymentExitDocumentList>(
      `/payroll/employments/${employmentId}/documents/exit`,
    ).then(response => response.data),
  generateEmploymentCertificate: (
    employmentId: number,
    payload: PayrollEmploymentCertificateEvidence,
    idempotencyKey: string,
  ) =>
    api.post<PayrollDocument>(
      `/payroll/employments/${employmentId}/documents/exit/employment-certificate`,
      payload,
      { headers: { 'Idempotency-Key': idempotencyKey } },
    ).then(response => response.data),
  generateAverageEarningsCertificate: (
    employmentId: number,
    payload: PayrollAverageEarningsCertificateEvidence,
    idempotencyKey: string,
  ) =>
    api.post<PayrollDocument>(
      `/payroll/employments/${employmentId}/documents/exit/average-earnings-certificate`,
      payload,
      { headers: { 'Idempotency-Key': idempotencyKey } },
    ).then(response => response.data),
  generateAverageEarningsStatement: (
    employmentId: number,
    payload: PayrollAverageEarningsStatementEvidence,
    idempotencyKey: string,
  ) =>
    api.post<PayrollDocument>(
      `/payroll/employments/${employmentId}/documents/exit/average-earnings-statement`,
      payload,
      { headers: { 'Idempotency-Key': idempotencyKey } },
    ).then(response => response.data),
  generateDocumentBatch: (runId: number, revisionId: number) =>
    api.post<{ batch: PayrollDocumentBatch }>(
      `/payroll/runs/${runId}/revisions/${revisionId}/documents/batch`,
      {},
      { headers: { 'Idempotency-Key': `payroll-document-batch:${runId}:${revisionId}` } },
    ).then(response => response.data.batch),
  documentBatch: (batchId: number) =>
    api.get<{ batch: PayrollDocumentBatch }>(
      `/payroll/documents/batches/${batchId}`,
    ).then(response => response.data.batch),
  documentBatchItems: (batchId: number, page?: PayrollPageParams) =>
    api.get<{ items: PayrollDocumentBatchItem[], total: number }>(
      `/payroll/documents/batches/${batchId}/items`,
      { params: pageParams(page) },
    ).then(response => response.data),
  retryDocumentBatchItem: (batchId: number, itemId: number) =>
    api.post<{ item: PayrollDocumentBatchItem }>(
      `/payroll/documents/batches/${batchId}/items/${itemId}/retry`,
      {},
    ).then(response => response.data.item),
  /**
   * Stránka seznamu běhů. `result_snapshot` nese jen `totals` — osobní rozpad
   * v seznamu není, ten se dotahuje přes `run()` pro jeden konkrétní běh.
   * Bez `limit` platí serverový výchozí strop, ne „všechno".
   */
  runsPage: (period?: string, page?: { limit?: number, offset?: number }) =>
    api.get<PayrollRunsPage>('/payroll/runs', {
      params: {
        ...(period ? { period } : {}),
        ...(page?.limit === undefined ? {} : { limit: page.limit }),
        ...(page?.offset === undefined ? {} : { offset: page.offset }),
      },
    }).then(response => response.data),
  runs: (period?: string) =>
    api.get<PayrollRunsPage>('/payroll/runs', {
      params: period ? { period } : undefined,
    }).then(response => response.data.runs),
  /** Jeden běh i s osobním rozpadem ve `result_snapshot.people`. */
  run: (runId: number) =>
    api.get<{ run: PayrollRun }>(`/payroll/runs/${runId}`)
      .then(response => response.data.run),
  /** Lehká auditní historie bez vstupních a výsledkových snapshotů. */
  runHistory: (runId: number) =>
    api.get<{ history: PayrollRunHistory }>(`/payroll/runs/${runId}/history`)
      .then(response => response.data.history),
  createRun: (payload: {
    period_start: string
    payment_date: string
    office_id: number | null
  }) =>
    api.post<{ run: PayrollRun }>('/payroll/runs', payload)
      .then(response => response.data.run),
  deleteRun: (runId: number, rowVersion: number) =>
    api.delete<void>(`/payroll/runs/${runId}`, {
      data: { row_version: rowVersion },
    }).then(() => undefined),
  commandRun: (
    runId: number,
    command: PayrollRunCommand,
    payload: { row_version: number; reason?: string },
    idempotencyKey: string,
  ) =>
    api.post<PayrollRunCommandResponse>(
      `/payroll/runs/${runId}/commands/${command}`,
      payload,
      { headers: { 'Idempotency-Key': idempotencyKey } },
    ).then(response => response.data),
  /**
   * Schválení výjimky u varování, které blokuje schválení běhu. Odůvodnění je
   * povinné a server na něj má minimum — prázdná nebo jednoslovná odpověď
   * neprojde.
   */
  overrideRunValidation: (
    runId: number,
    validationId: number,
    payload: { row_version: number; reason: string },
    idempotencyKey: string,
  ) =>
    api.post<PayrollRunValidationOverrideResponse>(
      `/payroll/runs/${runId}/validations/${validationId}/override`,
      payload,
      { headers: { 'Idempotency-Key': idempotencyKey } },
    ).then(response => response.data),
  /** Odvolání výjimky — jen dokud běh není schválený. */
  revokeRunValidationOverride: (
    runId: number,
    validationId: number,
    payload: { row_version: number },
    idempotencyKey: string,
  ) =>
    api.delete<PayrollRunValidationOverrideResponse>(
      `/payroll/runs/${runId}/validations/${validationId}/override`,
      {
        data: payload,
        headers: { 'Idempotency-Key': idempotencyKey },
      },
    ).then(response => response.data),
  downloadDocument: async (payrollDocument: PayrollDocument): Promise<void> => {
    const grant = await api.post<{ token: string; expires_at: string }>(
      `/payroll/documents/${payrollDocument.id}/download-grant`,
    ).then(response => response.data)
    const response = await api.get<Blob>(
      `/payroll/documents/${payrollDocument.id}/download`,
      {
        responseType: 'blob',
        headers: { 'X-Payroll-Download-Token': grant.token },
      },
    )
    const objectUrl = URL.createObjectURL(response.data)
    try {
      const anchor = document.createElement('a')
      anchor.href = objectUrl
      anchor.download = payrollDocument.suggested_filename
      document.body.appendChild(anchor)
      anchor.click()
      anchor.remove()
    } finally {
      URL.revokeObjectURL(objectUrl)
    }
  },
  timeMonth: (
    period: string,
    incomplete = false,
    page?: PayrollPageParams,
    employmentId?: number | null,
  ) =>
    api.get<PayrollTimeOverview>('/payroll/time/month', {
      params: {
        period,
        incomplete: incomplete ? 1 : 0,
        ...pageParams(page),
        ...(employmentId ? { employment_id: employmentId } : {}),
      },
    }).then(response => response.data),
  saveTimeCalendar: (employmentId: number, payload: Record<string, unknown>) =>
    api.put<{ calendar: PayrollWorkCalendar }>(`/payroll/time/calendars/${employmentId}`, payload)
      .then(response => response.data.calendar),
  saveShift: (payload: Record<string, unknown>) =>
    api.post<{ shift: PayrollShift; month: PayrollTimeMonthState }>('/payroll/time/shifts', payload)
      .then(response => response.data),
  saveTimeEntry: (payload: Record<string, unknown>) =>
    api.post<{ entry: PayrollTimeEntry; month: PayrollTimeMonthState }>('/payroll/time/entries', payload)
      .then(response => response.data),
  saveOvertimeConsent: (payload: {
    employment_id: number
    id?: number | null
    valid_from: string
    valid_to: string | null
    document_reference: string | null
    note: string | null
    row_version: number
  }) =>
    api.post<{ consent: PayrollOvertimeConsent }>('/payroll/time/overtime-consents', payload)
      .then(response => response.data.consent),
  saveOvertimeProtection: (payload: {
    employment_id: number
    id?: number | null
    protection: PayrollOvertimeProtectionKind
    valid_from: string
    valid_to: string | null
    document_reference: string | null
    note: string | null
    row_version: number
  }) =>
    api.post<{ protection: PayrollOvertimeProtection }>('/payroll/time/overtime-protections', payload)
      .then(response => response.data.protection),
  saveOvertimeCompensation: (payload: {
    employment_id: number
    id?: number | null
    overtime_date: string
    minutes: number
    granted_on: string | null
    document_reference: string | null
    note: string | null
    row_version: number
  }) =>
    api.post<{ compensation: PayrollOvertimeCompensation }>('/payroll/time/overtime-compensations', payload)
      .then(response => response.data.compensation),
  listOvertimeAveragingPeriods: () =>
    api.get<{ periods: PayrollOvertimeAveragingPeriod[] }>('/payroll/time/overtime-averaging-periods')
      .then(response => response.data.periods),
  saveOvertimeAveragingPeriod: (payload: {
    id?: number | null
    valid_from: string
    valid_to: string | null
    weeks: number
    basis: PayrollOvertimeAveragingBasis
    collective_agreement_reference: string | null
    note: string | null
    row_version: number
  }) =>
    api.post<{ period: PayrollOvertimeAveragingPeriod }>('/payroll/time/overtime-averaging-periods', payload)
      .then(response => response.data.period),
  previewTimeImport: (payload: { period: string; format: 'csv' | 'xlsx'; original_name: string; content: string }) =>
    api.post<{ preview: PayrollTimeImportPreview }>('/payroll/time/imports/preview', payload)
      .then(response => response.data.preview),
  importTime: (payload: { period: string; format: 'csv' | 'xlsx'; original_name: string; content: string }) =>
    api.post<{ import: Record<string, unknown> }>('/payroll/time/imports', payload)
      .then(response => response.data.import),
  approveTimeMonth: (period: string, payload: {
    employment_id: number
    row_version: number
    jmhz_work_summary?: PayrollJmhzWorkSummaryApproval
  }) =>
    api.post<{ month: PayrollTimeMonthState }>(`/payroll/time/months/${period}/approve`, payload)
      .then(response => response.data.month),
  reopenTimeMonth: (period: string, payload: { employment_id: number; row_version: number; reason: string }) =>
    api.post<{ month: PayrollTimeMonthState }>(`/payroll/time/months/${period}/reopen`, payload)
      .then(response => response.data.month),
  components: (effectiveOn?: string) =>
    api.get<{ components: PayrollComponent[] }>('/payroll/components', {
      params: effectiveOn ? { effective_on: effectiveOn } : undefined,
    }).then(response => response.data.components),
  riskySavings: (period: string) =>
    api.get<{
      items: PayrollRiskySavingsItem[]
      minimum_shift_eighths: number
      rate_basis_points: number
    }>('/payroll/risky-savings', { params: { period } })
      .then(response => response.data),
  saveRiskySavingsEvidence: (payload: PayrollRiskySavingsEvidencePayload) =>
    api.put<{ evidence: PayrollRiskySavingsItem }>(
      '/payroll/risky-savings/evidence',
      payload,
    ).then(response => response.data.evidence),
  createComponent: (payload: PayrollComponentPayload) =>
    api.post<{ component: PayrollComponent }>('/payroll/components', payload)
      .then(response => response.data.component),
  updateComponent: (id: number, rowVersion: number, payload: PayrollComponentPayload) =>
    api.put<{ component: PayrollComponent }>(`/payroll/components/${id}`, {
      ...payload,
      row_version: rowVersion,
    }).then(response => response.data.component),
  deleteComponent: (id: number, rowVersion: number) =>
    api.delete<{ deleted: true; cascade: PayrollDeleteCascade }>(
      `/payroll/components/${id}`,
      { data: { row_version: rowVersion } },
    ).then(response => response.data.cascade),
  componentJmhzTargets: () =>
    api.get<{
      package_key: string
      manifest_sha256: string
      topology_hash: string
      targets: PayrollComponentJmhzTarget[]
    }>('/payroll/components/jmhz-targets').then(response => response.data),
  componentJmhzMappings: () =>
    api.get<{ items: PayrollComponentJmhzMappingState[] }>('/payroll/components/jmhz-mappings')
      .then(response => response.data.items),
  saveComponentJmhzMapping: (
    componentId: number,
    targetAttributeId: string,
    rowVersion: number | null,
  ) => api.put<PayrollComponentJmhzMappingState>(
    `/payroll/components/${componentId}/jmhz-mapping`,
    { target_attribute_id: targetAttributeId, row_version: rowVersion },
  ).then(response => response.data),
  removeComponentJmhzMapping: (componentId: number, rowVersion: number) =>
    api.delete(`/payroll/components/${componentId}/jmhz-mapping`, {
      data: { row_version: rowVersion },
    }),
  recurringComponents: (employmentId?: number, page?: PayrollPageParams) =>
    api.get<{
      recurring_components: PayrollRecurringComponent[]
      total: number
      limit: number
      offset: number
    }>('/payroll/recurring-components', {
      params: {
        ...(employmentId ? { employment_id: employmentId } : {}),
        ...pageParams(page),
      },
    }).then(response => response.data),
  createRecurringComponent: (payload: PayrollRecurringComponentPayload) =>
    api.post<{ recurring_component: PayrollRecurringComponent }>('/payroll/recurring-components', payload)
      .then(response => response.data.recurring_component),
  updateRecurringComponent: (
    id: number,
    rowVersion: number,
    payload: PayrollRecurringComponentPayload,
  ) =>
    api.put<{ recurring_component: PayrollRecurringComponent }>(`/payroll/recurring-components/${id}`, {
      ...payload,
      row_version: rowVersion,
    }).then(response => response.data.recurring_component),
  deleteRecurringComponent: (id: number, rowVersion: number) =>
    api.delete<{ deleted: true; cascade: PayrollDeleteCascade }>(
      `/payroll/recurring-components/${id}`,
      { data: { row_version: rowVersion } },
    ).then(response => response.data.cascade),
  materializeRecurringComponents: (period: string) =>
    api.post<{ materialization: PayrollRecurringMaterialization }>(
      '/payroll/recurring-components/materialize',
      { period },
    ).then(response => response.data.materialization),
  /** `employmentId` zúží seznam na jeden vztah už na serveru, ne až za stránkováním. */
  inputs: (period: string, page?: PayrollPageParams, employmentId?: number) =>
    api.get<{ inputs: PayrollInput[]; total: number }>('/payroll/inputs', {
      params: {
        period,
        ...pageParams(page),
        ...(employmentId ? { employment_id: employmentId } : {}),
      },
    }).then(response => ({ items: response.data.inputs, total: response.data.total })),
  quickInputs: (period: string, page?: PayrollPageParams, employmentId?: number) =>
    api.get<{ month: PayrollQuickInputMonth }>('/payroll/quick-inputs', {
      params: {
        period,
        ...pageParams(page),
        ...(employmentId ? { employment_id: employmentId } : {}),
      },
    }).then(response => response.data.month),
  employeeCards: (
    period: string,
    page: PayrollPageParams,
    filters: { search: string, status: PayrollEmployeeCardStatusFilter },
  ) => api.get<{ month: PayrollEmployeeCardMonth }>('/payroll/quick-inputs', {
    params: {
      period,
      view: 'cards',
      ...pageParams(page),
      search: filters.search,
      status: filters.status,
    },
  }).then(response => response.data.month),
  /**
   * Zúžení se posílá i při ukládání — odpověď je táž stránka, ze které se
   * plní formulář, a ta musí zůstat zúžená.
   */
  saveQuickInputs: (
    payload: PayrollQuickInputSavePayload,
    page?: PayrollPageParams,
    employmentId?: number,
  ) =>
    api.put<{ month: PayrollQuickInputMonth }>('/payroll/quick-inputs', payload, {
      params: {
        ...pageParams(page),
        ...(employmentId ? { employment_id: employmentId } : {}),
      },
    }).then(response => response.data.month),
  previewInput: (payload: PayrollInputPayload) =>
    api.post<{ preview: PayrollInputPreview }>('/payroll/inputs/preview', payload)
      .then(response => response.data.preview),
  createInput: (payload: PayrollInputPayload) =>
    api.post<{ input: PayrollInput }>('/payroll/inputs', payload)
      .then(response => response.data.input),
  updateInput: (id: number, rowVersion: number, payload: PayrollInputPayload) =>
    api.put<{ input: PayrollInput }>(`/payroll/inputs/${id}`, {
      ...payload,
      row_version: rowVersion,
    }).then(response => response.data.input),
  approveInput: (id: number, rowVersion: number) =>
    api.post<{ input: PayrollInput }>(`/payroll/inputs/${id}/approve`, {
      row_version: rowVersion,
    }).then(response => response.data.input),
  cancelInput: (id: number, rowVersion: number) =>
    api.post<{ input: PayrollInput }>(`/payroll/inputs/${id}/cancel`, {
      row_version: rowVersion,
    }).then(response => response.data.input),
  reverseBenefitInput: (id: number, rowVersion: number, reason: string) =>
    api.post<{ input: PayrollInput }>(`/payroll/inputs/${id}/reverse-benefit`, {
      row_version: rowVersion,
      reason,
    }).then(response => response.data.input),
  previewInputImport: (payload: PayrollInputImportPayload) =>
    api.post<{ preview: PayrollInputImportPreview }>('/payroll/input-imports/preview', payload)
      .then(response => response.data.preview),
  applyInputImport: (payload: PayrollInputImportPayload) =>
    api.post<{ import: PayrollInputImportResult }>('/payroll/input-imports/apply', payload)
      .then(response => response.data.import),
  signingProfile: (environment: PayrollSigningEnvironment) =>
    api.get<PayrollSigningProfileView>('/payroll/submissions/signing-profile', {
      params: { environment },
    }).then(response => response.data),
  saveSigningProfile: (
    payload: PayrollSigningProfilePayload,
    proof: EpoStepUpProof,
  ) => api.put<PayrollSigningProfileResult>('/payroll/submissions/signing-profile', {
    environment: payload.environment,
    credential_id: payload.credential_id,
    cssz_registered_serial: payload.cssz_registered_serial ?? '',
    // Klíč se vynechá úplně, když volba ještě neexistuje: backend bere i `null`
    // jako „neposláno", ale posílat pole, které nemá význam, jen svádí k tomu
    // začít ho posílat i s nesmyslnou hodnotou.
    ...(payload.row_version ? { row_version: payload.row_version } : {}),
    ...stepUpProofBody(proof),
  }).then(response => response.data),
  deleteSigningProfile: (
    environment: PayrollSigningEnvironment,
    proof: EpoStepUpProof,
  ) => api.delete<{ environment: PayrollSigningEnvironment; deleted: boolean }>(
    '/payroll/submissions/signing-profile',
    { data: { environment, ...stepUpProofBody(proof) } },
  ).then(response => response.data),
  /** Stránka pokusů o odeslání, od nejnovějšího. */
  jmhzTransportHistory: (
    environment: PayrollJmhzTransportEnvironment,
    page?: PayrollPageParams,
  ) =>
    api.get<PayrollJmhzTransportHistory>('/payroll/submissions/jmhz-transport', {
      params: { environment, ...pageParams(page) },
    }).then(response => response.data),
  sendJmhzTransport: (
    submissionId: number,
    variableSymbol: string,
    environment: PayrollJmhzTransportEnvironment,
    idempotencyKey: string,
  ) => api.post<PayrollJmhzTransportPoll>(
    `/payroll/submissions/${submissionId}/jmhz-transport`,
    { environment, variable_symbol: variableSymbol },
    { headers: { 'Idempotency-Key': idempotencyKey } },
  ).then(response => response.data),
  enqueueJmhzIsds: (
    submissionId: number,
    environment: PayrollJmhzTransportEnvironment,
  ) => api.post<PayrollJmhzIsdsEnqueueResult>(
    `/payroll/submissions/${submissionId}/jmhz-isds`,
    { environment },
  ).then(response => response.data),
  /**
   * Dotaz na výsledek. Variabilní symbol zaměstnavatele je povinný — brána VREP
   * si jím ověřuje, že se ptá ten, kdo podával.
   */
  pollJmhzTransportAttempt: (
    attemptId: number,
    variableSymbol: string,
    environment: PayrollJmhzTransportEnvironment,
  ) => api.get<PayrollJmhzTransportPoll>(
    `/payroll/submissions/jmhz-transport/${attemptId}`,
    { params: { variable_symbol: variableSymbol, environment } },
  ).then(response => response.data),
  /**
   * Uzavření transakce. Podací protokol ho vyžaduje, ale až po dotažení
   * protokolu — uzavřít dřív znamená přijít o výsledek.
   */
  closeJmhzTransportAttempt: (
    attemptId: number,
    variableSymbol: string,
    environment: PayrollJmhzTransportEnvironment,
  ) => api.post<{
    closed: boolean
    already_closed: boolean
    attempt: PayrollJmhzTransportAttempt
  }>(
    `/payroll/submissions/jmhz-transport/${attemptId}/close`,
    { environment },
    { params: { variable_symbol: variableSymbol, environment } },
  ).then(response => response.data),
  /**
   * Storno celého podání za období. Jen ho ZMRAZÍ — odesílá se pak stejnou
   * cestou jako řádné hlášení, aby mu patřil tentýž ledger pokusů.
   */
  cancelJmhzSubmission: (
    submissionId: number,
    environment: PayrollJmhzTransportEnvironment,
  ) => api.post<PayrollJmhzCorrectiveSubmission>(
    `/payroll/submissions/${submissionId}/jmhz-cancel`,
    { environment },
  ).then(response => response.data),
  /** Vztahy načtené ze zmrazeného řádného XML; zákonné identifikátory se neopisují ručně. */
  jmhzCorrectableComponents: (
    submissionId: number,
    environment: PayrollJmhzTransportEnvironment,
  ) => api.get<{
    environment: PayrollJmhzTransportEnvironment
    submission_id: number
    components: PayrollJmhzCorrectableComponent[]
  }>(
    `/payroll/submissions/${submissionId}/jmhz-cancel-components`,
    { params: { environment } },
  ).then(response => response.data),
  /** Opravné podání, které stornuje jen vyjmenované pracovněprávní vztahy. */
  cancelJmhzSubmissionComponents: (
    submissionId: number,
    environment: PayrollJmhzTransportEnvironment,
    formGuids: string[],
  ) => api.post<PayrollJmhzCorrectiveSubmission>(
    `/payroll/submissions/${submissionId}/jmhz-cancel-components`,
    { environment, form_guids: formGuids },
  ).then(response => response.data),
  jmhzContentCorrectionPreparations: (
    submissionId: number,
    environment: PayrollJmhzTransportEnvironment,
  ) => api.get<PayrollJmhzContentCorrectionPreparations>(
    `/payroll/submissions/${submissionId}/jmhz-content-correction-preparations`,
    { params: { environment } },
  ).then(response => response.data),
  jmhzContentCorrectionCandidates: (
    submissionId: number,
    preparationId: number,
    environment: PayrollJmhzTransportEnvironment,
  ) => api.get<PayrollJmhzContentCorrectionCandidates>(
    `/payroll/submissions/${submissionId}/jmhz-content-correction`,
    { params: { environment, preparation_id: preparationId } },
  ).then(response => response.data),
  freezeJmhzContentCorrection: (
    submissionId: number,
    preparationId: number,
    environment: PayrollJmhzTransportEnvironment,
    employmentExternalIdentifiers: string[],
  ) => api.post<PayrollJmhzCorrectiveSubmission>(
    `/payroll/submissions/${submissionId}/jmhz-content-correction`,
    {
      environment,
      preparation_id: preparationId,
      employment_external_identifiers: employmentExternalIdentifiers,
    },
  ).then(response => response.data),
  /** Protokoly načtené ze souboru, od nejnovějšího období. */
  jmhzImportedProtocols: (
    environment: PayrollJmhzTransportEnvironment,
    page?: PayrollPageParams,
  ) =>
    api.get<PayrollJmhzImportedProtocolHistory>(
      '/payroll/submissions/jmhz-protocol-import',
      { params: { environment, ...pageParams(page) } },
    ).then(response => response.data),
  /**
   * Vysvětlené chyby jednoho protokolu. Seznam je nenese — počítají se z
   * uloženého originálu, takže dotáhnout je pro celou stránku by znamenalo
   * číst desítky XML kvůli jedinému rozbalenému řádku.
   */
  jmhzImportedProtocolErrors: (
    protocolId: number,
    environment: PayrollJmhzTransportEnvironment,
  ) =>
    api.get<PayrollJmhzImportedProtocolErrors>(
      `/payroll/submissions/jmhz-protocol-import/${protocolId}/errors`,
      { params: { environment } },
    ).then(response => response.data),
  /**
   * Načte XML protokol z datové schránky. Server ho odmítne, pokud jeho
   * variabilní symbol nepatří téhle firmě — cizí doklad se neuloží.
   */
  importJmhzProtocol: (
    file: File,
    environment: PayrollJmhzTransportEnvironment,
  ) => {
    const fd = new FormData()
    fd.append('file', file)
    fd.append('environment', environment)
    return api.post<PayrollJmhzImportedProtocolResult>(
      '/payroll/submissions/jmhz-protocol-import',
      fd,
      {
        params: { environment },
        headers: { 'Content-Type': 'multipart/form-data' },
      },
    ).then(response => response.data)
  },
}
