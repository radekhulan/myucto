<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useMediaQuery } from '@vueuse/core'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollChecklistStatus,
  type PayrollEmployment,
  type PayrollEmploymentChecklistItem,
  type PayrollEmploymentStatus,
  type PayrollEmploymentJmhzEvidenceOptions,
  type PayrollMealEntitlementBasis,
  type PayrollJmhzMunicipalityOption,
  type PayrollEmploymentTermsPayload,
} from '@/api/payroll'
import type { PayrollOffice } from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import CzIscoPicker from '@/components/payroll/CzIscoPicker.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import RequiredMark from '@/components/ui/RequiredMark.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnFilledSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'
import { loadPayrollOffices } from '@/composables/usePayrollOffices'
import { loadPayrollJmhzOptions } from '@/composables/usePayrollJmhzOptions'
import { useToast } from '@/composables/useToast'
import EmploymentAgendaPanel from './EmploymentAgendaPanel.vue'
import EmploymentDimensionsPanel from './EmploymentDimensionsPanel.vue'
import EmploymentSurchargePolicyPanel from './EmploymentSurchargePolicyPanel.vue'
import EmploymentExitDocumentsPanel from './EmploymentExitDocumentsPanel.vue'
import EmploymentJmhzIdentityPanel from './EmploymentJmhzIdentityPanel.vue'
import EmploymentRegistrationPanel from './EmploymentRegistrationPanel.vue'
import PayrollOpeningBalancesPanel from './PayrollOpeningBalancesPanel.vue'
import {
  employmentCodeLabel,
  employmentDiffFields,
  employmentDiffValue,
  employmentEventNote,
  todayIso,
  transitionPresentation,
} from './employmentLifecycleUi'

const props = defineProps<{
  employment: PayrollEmployment
  canWrite: boolean
  canWritePerson?: boolean
  canReadDocuments?: boolean
  canWriteDocuments?: boolean
  // Období, od kterého firma vede mzdy v MyÚčtu (`payroll_module_state.start_period`).
  payrollStartPeriod?: string | null
}>()
const emit = defineEmits<{
  updated: [employment: PayrollEmployment]
  deleted: [employmentId: number]
  /** „Kde se to nastavuje" — stránka osoby na to odroluje k panelu evidence. */
  focusStatutoryEvidence: []
}>()

const { t } = useI18n()
const toast = useToast()
const busy = ref(false)
const transitionDate = ref(todayIso())
const jmhzOptions = ref<PayrollEmploymentJmhzEvidenceOptions | null>(null)
const jmhzOptionsFailed = ref(false)
const municipalityOptions = ref<PayrollJmhzMunicipalityOption[]>([])
const municipalitiesLoading = ref(false)
const offices = ref<PayrollOffice[]>([])
const ordinaryProfileFields = [
  { key: 'jmhz_orchard_discount_eligible', label: 'orchard_discount_eligible' },
  { key: 'jmhz_specific_legal_fact_applies', label: 'specific_legal_fact_applies' },
  { key: 'jmhz_ozp_employment_support_applies', label: 'ozp_employment_support_applies' },
  { key: 'jmhz_deep_mining_work_applies', label: 'deep_mining_work_applies' },
] as const

const currentTerms = computed(() => props.employment.terms[0] ?? null)

/**
 * Prohlášení poplatníka k dani.
 *
 * Karta ho POUZE UKAZUJE. Dřív tu stálo bezpopiskové zaškrtávátko ve formuláři
 * nové verze podmínek, tedy druhé nezávisle editovatelné místo pro tentýž údaj,
 * který vede zákonná evidence osoby (`payroll_person_tax_declarations`). Když
 * se obě hodnoty rozešly, mzdový běh spadl na `tax_declaration_term_conflict` —
 * a rozejít se musely: prohlášení se podepisuje i odvolává kdykoliv v průběhu
 * vztahu, kdežto smluvní podmínky se kvůli podpisu neverzují.
 *
 * Rozhoduje přitom o měsíční slevě na poplatníka (§ 35ba, § 38k odst. 4 ZDP),
 * takže nestačí ho schovat — musí být vidět i s cestou tam, kde se nastavuje.
 */
const taxDeclaration = computed(() => props.employment.tax_declaration)
const taxDeclarationLabel = computed(() => taxDeclaration.value === null
  ? t('payroll.people.tax_declaration_state.missing')
  : t(`payroll.people.tax_declaration_state.${taxDeclaration.value.status}`))
const taxDeclarationSigned = computed(() => taxDeclaration.value?.status === 'signed')

/**
 * Zdravotní pojišťovna — stejné pravidlo jako u prohlášení k dani: karta ji
 * zrcadlí ze zákonné evidence osoby a vede tam, kde se nastavuje. Účetní ji
 * na kartě vztahu hledala, protože rozhoduje o odvodu z TOHOTO vztahu, ale
 * druhé zadávací místo pro týž údaj by se s prvním dřív nebo později rozešlo.
 */
const healthInsurer = computed(() => props.employment.health_insurer)
const healthInsurerLabel = computed(() => {
  const insurer = healthInsurer.value
  if (insurer === null) return t('payroll.people.health_insurer_state.missing')
  if (insurer.status !== 'verified') {
    return t(`payroll.people.health_insurer_state.${insurer.status}`)
  }
  return insurer.code ?? t('payroll.people.health_insurer_state.verified')
})
const healthInsurerVerified = computed(
  () => healthInsurer.value?.status === 'verified' && healthInsurer.value.code !== null,
)

function nextCalendarDay(isoDate: string): string {
  const [year, month, day] = isoDate.split('-').map(Number)
  const date = new Date(Date.UTC(year!, month! - 1, day! + 1))
  return date.toISOString().slice(0, 10)
}

const minimumNewTermsDate = computed(() => {
  const today = todayIso()
  const latest = currentTerms.value?.effective_from
  if (latest === undefined) return today
  const next = nextCalendarDay(latest)
  return next > today ? next : today
})

/**
 * Mzdová účtárna vztahu — jediné místo, kde se dá vybrat.
 *
 * Účtárna byla na kartě jen K PŘEČTENÍ (řádek s kódem vztahu), přestože z ní
 * vychází variabilní symbol zaměstnavatele pro sociální pojistné a mzdový běh
 * se dá na účtárnu zúžit. Vztah bez ní proto shodí uzamčení vstupů běhu
 * blokátorem `employment_without_office` — a nebylo ho čím spravit.
 *
 * Deaktivovaná účtárna, kterou vztah drží, zůstává v nabídce: jinak by ji výběr
 * tiše shodil na jinou při první úpravě podmínek.
 */
const officeOptions = computed(() => {
  const active = offices.value.map(office => ({
    value: office.id,
    label: office.name,
    secondary: office.code,
  }))
  const current = props.employment.office_id
  if (current !== null && !active.some(option => option.value === current)) {
    active.unshift({
      value: current,
      label: props.employment.office_name ?? String(current),
      secondary: props.employment.office_code ?? '',
    })
  }
  return active
})
const selectedOfficeOption = computed(
  () => officeOptions.value.find(option => option.value === termsForm.value?.office_id) ?? null,
)
/** Vztah bez účtárny — upozornění, ne zákaz: doplní se rovnou v základních údajích. */
const officeMissing = computed(() => props.employment.office_id === null)

/**
 * Podrobnosti (JMHZ evidence, režimy pojištění a daně, sazbové kategorie § 5a
 * a slevy § 7a) se otevřou samy jen tam, kde je někdo vyplnil. Běžný pracovní
 * poměr je nechá sbalené — bez toho měl formulář přes dvacet polí, ze kterých
 * pět lidí ze šesti nepotřebuje ani jedno.
 */
const advancedTermsPrefilled = computed(() => {
  const terms = termsForm.value
  if (terms === null) return false
  return terms.jmhz_workplace_municipality_code !== null
    || terms.jmhz_apz_contribution_status !== 'unverified'
    || terms.jmhz_functional_benefits_status !== 'unverified'
    || terms.jmhz_temporary_assignment_status !== 'unverified'
    || terms.jmhz_orchard_discount_eligible === true
    || terms.jmhz_specific_legal_fact_applies === true
    || terms.jmhz_ozp_employment_support_applies === true
    || terms.jmhz_deep_mining_work_applies === true
    || terms.regular_workplace !== null
    || terms.cz_isco_code !== null
    || terms.social_insurance_participation !== 'automatic'
    || terms.health_insurance_participation !== 'automatic'
    || terms.tax_regime !== 'advance'
    || terms.foreign_legislation_country_code !== null
    || terms.a1_certificate_until !== null
    || (terms.social_employer_rate_category ?? 'ordinary') !== 'ordinary'
    || (terms.social_part_time_discount_reason ?? 'none') !== 'none'
})

/**
 * Zařazení pro srážkovou daň se ptáme jen tam, kde ho z druhu vztahu nejde
 * odvodit. U pracovního poměru, zaměstnání malého rozsahu a DPP odpověď plyne
 * ze zákona sama (backend posílá `automatic`), takže by to bylo pole, kterým
 * uživatel nemůže nic změnit. Zrcadlí
 * EmploymentRelationshipKind::requiresOtherWithholdingStatement().
 */
const needsOtherWithholdingStatement = computed(
  () => ['dpc', 'partner_dependent', 'statutory_body'].includes(props.employment.relation_type),
)
/**
 * Stav, který se ukazuje: položka doložená dokladem je splněná, i když ji nikdo
 * ručně neodklepl (`effective_status`). Ruční evidence (`status`) zůstává tím,
 * podle čeho se zapisuje — proto se tlačítka řídí dál jí.
 */
function checklistStatus(item: PayrollEmploymentChecklistItem): PayrollChecklistStatus {
  return item.effective_status ?? item.status
}

const openChecklist = computed(() =>
  props.employment.checklist.filter(item => checklistStatus(item) === 'pending'),
)

/**
 * Nesplněné napřed. Karta jich ukazovala deset v pořadí, v jakém je naseedovala
 * databáze, takže „Doplnit datum nástupu" se schovalo mezi splněnými položkami.
 */
const sortedChecklist = computed(() =>
  [...props.employment.checklist].sort(
    (a, b) => Number(checklistStatus(a) !== 'pending') - Number(checklistStatus(b) !== 'pending'),
  ),
)

/**
 * Skončený vztah je archiv, ne pracovní plocha — u člověka se souběhy jinak
 * nedá poznat, který vztah je ten stávající. Sbalí se celý, aktivní zůstává otevřený.
 */
const isClosed = computed(() => ['ended', 'archived', 'no_show'].includes(props.employment.status))

const accentClass = computed(() => {
  if (isClosed.value) return 'border-l-neutral-300'
  return props.employment.status === 'active' ? 'border-l-success-500' : 'border-l-payroll-500'
})

const expanded = ref(!isClosed.value)

/**
 * Je karta rozložená do dvou sloupců (postranní pruh vlevo)?
 *
 * Tvar mřížky řeší CSS, ale rozcestník potřebuje VĚDĚT, že stojí v pruhu:
 * jeho dlaždice se lámou podle šířky okna, kdežto v pruhu mají pevnou šířku
 * bez ohledu na okno (viz `compact` v EmploymentAgendaPanel). Hodnota musí
 * sedět se zlomem v šabloně — jsou to dva zápisy téhož rozhodnutí a rozejít
 * se nesmí.
 *
 * ⚠️ Zlom je `xl` (1280 px), ne `2xl`. Měřeno na stroji zadavatele: obrazovka
 * 4096 px při škálování 156 % dá **1454 CSS pixelů**, takže na 2xl by pruh
 * nenaskočil nikdy a funkce by byla neviditelná právě tomu, kdo o ni požádal.
 * CSS pixely nejsou fyzické — na Windows je škálování 125–175 % běžné.
 *
 * Že se hlavní sloupec nezúží pod únosnou mez, je spočítané: při 1280 CSS px
 * zbude po menu aplikace ~972 px, po odečtení pruhu 16 rem a mezery ~692 px,
 * tedy tři sloupce polí po ~230 px. Pruh je proto na `xl` užší (16 rem) a až
 * na `2xl` se roztahuje na 20 rem.
 */
const wideRail = useMediaQuery('(min-width: 1280px)')

/* ───────────────────────── Úprava podmínek ─────────────────────────
 *
 * ⚠️ JÁDRO KARTY: rozdíl mezi OPRAVOU a NOVOU VERZÍ.
 *
 * Verzování podmínek je právně správně („od 1. 9. platí jiný úvazek"), ale
 * jako JEDINÁ cesta k úpravě to byla past. Kdo si přišel spravit překlep
 * v úvazku nebo doplnit účtárnu, kterou nikdo nevyplnil, musel projít
 * formulářem „Nová verze podmínek" s povinným datem účinnosti — a založil
 * tím druhou verzi, která tvrdí, že se podmínky k tomu datu změnily. Časová
 * osa pak lhala a mzdový běh počítal dvě období tam, kde je jedno.
 *
 * Řešení: pole jsou editovatelná ROVNOU, bez vstupu do jakéhokoliv formuláře,
 * a rozhodnutí padne AŽ v okamžiku ukládání, kdy uživatel ví, co změnil:
 *   - `correct` (výchozí) → PATCH …/terms/current, přepis platné verze,
 *   - `version`           → PUT  …/terms, nová verze od zadaného data.
 *
 * Výchozí je oprava, protože je to častější případ (překlep, doplnění údaje);
 * nová verze si vyžádá datum, tedy vědomý úkon. Server opravu odmítne, jakmile
 * je z období zúčtováno (`payroll_terms_settled`) — pak je jediná správná
 * cesta nová verze a karta na ni sama přepne.
 */
type TermsSaveMode = 'correct' | 'version'
const termsForm = ref<PayrollEmploymentTermsPayload | null>(null)
const grossInput = ref('')
const mealBasis = ref<PayrollMealEntitlementBasis>('shift')
const saveMode = ref<TermsSaveMode>('correct')
const versionEffectiveFrom = ref(todayIso())
const saveError = ref('')
/** Otisk uloženého stavu — proti němu se pozná, jestli je co ukládat. */
const baseline = ref('')

const canEditTerms = computed(() => props.canWrite
  && ['planned', 'preregistered', 'active', 'suspended'].includes(props.employment.status))

function minorToInput(value: number | null): string {
  if (value === null) return ''
  const whole = Math.trunc(value / 100)
  const fraction = Math.abs(value % 100)
  return fraction === 0 ? String(whole) : `${whole}.${String(fraction).padStart(2, '0')}`
}

/** `NaN` = uživatel napsal něco, co částka není; `null` = mzda není sjednaná. */
function inputToMinor(value: string): number | null {
  const normalized = value.trim().replace(',', '.')
  if (normalized === '') return null
  const match = /^(\d{1,10})(?:\.(\d{1,2}))?$/.exec(normalized)
  if (!match) return Number.NaN
  return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'))
}

/**
 * Otisk editovatelného stavu. Serializuje se i mzda a režim stravování,
 * protože „je co ukládat" se ptá na CELOU kartu, ne jen na verzi podmínek.
 */
function fingerprint(): string {
  return JSON.stringify([termsForm.value, grossInput.value.trim(), mealBasis.value])
}

const dirty = computed(() => baseline.value !== '' && fingerprint() !== baseline.value)

function hydrate(employment: PayrollEmployment) {
  const terms = employment.terms[0]
  if (!terms) {
    termsForm.value = null
    baseline.value = ''
    return
  }
  termsForm.value = {
    office_id: terms.office_id,
    effective_from: terms.effective_from,
    contract_signed_on: terms.contract_signed_on,
    planned_start_on: terms.planned_start_on,
    actual_start_on: terms.actual_start_on,
    fixed_term_end_on: terms.fixed_term_end_on,
    weekly_hours: terms.weekly_hours,
    leave_entitlement_weeks_override: terms.leave_entitlement_weeks_override ?? null,
    workload_basis_points: terms.workload_basis_points,
    work_place: terms.work_place,
    regular_workplace: terms.regular_workplace,
    jmhz_workplace_municipality_code: terms.jmhz_workplace_municipality_code,
    jmhz_workplace_country_code: terms.jmhz_workplace_country_code,
    jmhz_apz_contribution_status: terms.jmhz_apz_contribution_status,
    jmhz_apz_instrument_code: terms.jmhz_apz_instrument_code,
    jmhz_functional_benefits_status: terms.jmhz_functional_benefits_status,
    jmhz_temporary_assignment_status: terms.jmhz_temporary_assignment_status,
    jmhz_orchard_discount_eligible: terms.jmhz_orchard_discount_eligible ?? false,
    jmhz_specific_legal_fact_applies: terms.jmhz_specific_legal_fact_applies ?? false,
    jmhz_ozp_employment_support_applies: terms.jmhz_ozp_employment_support_applies ?? false,
    jmhz_deep_mining_work_applies: terms.jmhz_deep_mining_work_applies ?? false,
    cz_isco_code: terms.cz_isco_code,
    activity_code: terms.activity_code,
    jmhz_relationship_detail_code: terms.jmhz_relationship_detail_code,
    social_insurance_participation: terms.social_insurance_participation,
    health_insurance_participation: terms.health_insurance_participation,
    tax_regime: terms.tax_regime,
    other_withholding_eligibility: terms.other_withholding_eligibility ?? 'unverified',
    foreign_legislation_country_code: terms.foreign_legislation_country_code,
    a1_certificate_until: terms.a1_certificate_until,
    social_employer_rate_category: terms.social_employer_rate_category ?? 'ordinary',
    social_employer_rate_category_evidence: terms.social_employer_rate_category_evidence ?? null,
    social_part_time_discount_reason: terms.social_part_time_discount_reason ?? 'none',
    social_part_time_discount_evidence: terms.social_part_time_discount_evidence ?? null,
    social_part_time_discount_notified_on: terms.social_part_time_discount_notified_on ?? null,
    // Server hodnotu z těla ignoruje a odvodí ji ze zákonné evidence osoby
    // (viz `PayrollEmploymentRepository::taxDeclarationSigned()`); posílá se
    // dál jen proto, že ji tvar payloadu vyžaduje.
    tax_declaration_signed: terms.tax_declaration_signed,
    is_primary: terms.is_primary,
    // Důvod změny je poznámka k TOMUTO zápisu, ne uložený údaj — do formuláře
    // se historická hodnota netahá, jinak by se zopakovala u další změny.
    change_reason: null,
  }
  grossInput.value = minorToInput(employment.monthly_gross_minor)
  mealBasis.value = employment.meal_entitlement_basis
  versionEffectiveFrom.value = minimumNewTermsDate.value
  saveMode.value = 'correct'
  saveError.value = ''
  baseline.value = fingerprint()
}

/*
 * Znovunačtení jen tehdy, když uživatel nemá rozdělanou práci: kartu překreslí
 * i akce, které s podmínkami nesouvisejí (odklepnutá povinnost, změna stavu),
 * a bezpodmínečná rehydratace by rozepsanou mzdu smazala. `row_version` se
 * bere z propu až v okamžiku uložení, takže formulář nikdy nedrží zastaralou verzi.
 */
watch(() => props.employment, (employment, previous) => {
  if (previous !== undefined && employment.id === previous.id && dirty.value) return
  hydrate(employment)
}, { immediate: true })

void loadPayrollOffices().then((loaded) => {
  offices.value = loaded
})
/*
 * Číselníky JMHZ se čtou i pro „Druh činnosti", které je mezi základními údaji —
 * proto se načítají rovnou, ne až po rozbalení podrobností. Composable je
 * cachuje na celý běh aplikace, takže tři vztahy jednoho člověka = jeden dotaz.
 */
void loadPayrollJmhzOptions().then((loaded) => {
  jmhzOptions.value = loaded
  jmhzOptionsFailed.value = loaded === null
  if (loaded === null) return
  onActivityCodeChange()
  /*
   * Srovnání historického 10502 podle číselníku není změna, kterou by udělal
   * uživatel — kdyby se počítalo do „je co uložit", vyskočila by u takového
   * vztahu lišta s Uložit hned po otevření karty, bez jediného kliknutí.
   * Neplatný kód se srovná při nejbližším skutečném zápisu; do té doby se
   * o něj karta neuklání. Přebaseluje se bez podmínky: číselník dorazí dřív,
   * než se stihne kdokoli dotknout klávesnice.
   */
  baseline.value = fingerprint()
})

function onApzStatusChange() {
  if (termsForm.value?.jmhz_apz_contribution_status !== 'yes' && termsForm.value) {
    termsForm.value.jmhz_apz_instrument_code = null
  }
}

function onActivityCodeChange() {
  if (!termsForm.value) return
  const mode = selectedRelationshipDetailMode.value
  if (mode === 'forbidden') {
    termsForm.value.jmhz_relationship_detail_code = null
  } else if (mode === 'fixed_none') {
    termsForm.value.jmhz_relationship_detail_code = '1'
  }
}

const selectedRelationshipDetailMode = computed(() => {
  const activityCode = termsForm.value?.activity_code
  return jmhzOptions.value?.activity_codes.find(option => option.code === activityCode)
    ?.relationship_detail_mode ?? 'forbidden'
})

const selectedMunicipality = computed(() => {
  const code = termsForm.value?.jmhz_workplace_municipality_code
  const label = termsForm.value?.work_place
  return code && label ? { value: code, label, secondary: code } : null
})

async function searchMunicipalities(query: string) {
  if (!termsForm.value || query.trim().length < 2) {
    municipalityOptions.value = []
    return
  }
  municipalitiesLoading.value = true
  try {
    municipalityOptions.value = await payrollApi.searchJmhzMunicipalities(query)
  } catch {
    municipalityOptions.value = []
  } finally {
    municipalitiesLoading.value = false
  }
}

function selectMunicipality(code: string | null) {
  if (!termsForm.value) return
  const selected = municipalityOptions.value.find(option => option.code === code)
  termsForm.value.jmhz_workplace_municipality_code = selected?.code ?? null
  termsForm.value.work_place = selected?.label ?? null
  if (!selected) {
    termsForm.value.jmhz_workplace_country_code = null
    return
  }
  if (selected && !termsForm.value.jmhz_workplace_country_code) {
    termsForm.value.jmhz_workplace_country_code = 'CZ'
  }
}

function discardChanges() {
  hydrate(props.employment)
}

/**
 * Jedno společné Uložit pro celou kartu.
 *
 * Režim stravování má vlastní endpoint (není součástí verze podmínek), ale
 * uživateli je to jedno — je to jedno pole mezi ostatními. Ukládá se proto
 * jako první a `row_version` pro podmínky se bere z odpovědi, ne z propu:
 * dva zápisy za sebou by jinak druhý shodily na konflikt verzí.
 */
async function save() {
  const form = termsForm.value
  if (!form || busy.value || !dirty.value) return
  const gross = inputToMinor(grossInput.value)
  if (Number.isNaN(gross)) {
    saveError.value = t('payroll.people.gross_invalid')
    return
  }
  if (saveMode.value === 'version' && versionEffectiveFrom.value === '') {
    saveError.value = t('payroll.people.new_terms_date_required')
    return
  }
  saveError.value = ''
  busy.value = true
  try {
    let rowVersion = props.employment.row_version
    if (mealBasis.value !== props.employment.meal_entitlement_basis) {
      const updated = await payrollApi.setEmploymentMealEntitlementBasis(
        props.employment.id,
        rowVersion,
        mealBasis.value,
      )
      rowVersion = updated.row_version
      emit('updated', updated)
    }

    const { effective_from: _ignored, ...rest } = form
    const updated = saveMode.value === 'version'
      ? await payrollApi.addEmploymentTerms(props.employment.id, rowVersion, {
        ...rest,
        effective_from: versionEffectiveFrom.value,
        monthly_gross_minor: gross,
      })
      : await payrollApi.correctEmploymentTerms(props.employment.id, rowVersion, {
        ...rest,
        monthly_gross_minor: gross,
      })
    emit('updated', updated)
    hydrate(updated)
    toast.success(saveMode.value === 'version'
      ? t('payroll.people.terms_saved')
      : t('payroll.people.terms_corrected'))
  } catch (error) {
    const detail = (error as {
      response?: { data?: { error?: { code?: string } } }
    })?.response?.data?.error
    /*
     * „Z tohohle období je už zúčtováno." Oprava na místě by přepsala podklad
     * hotové mzdy — server ji odmítne a karta rovnou přepne na novou verzi,
     * aby uživatel nehledal, co má udělat jinak.
     */
    if (detail?.code === 'payroll_terms_settled') {
      saveMode.value = 'version'
      versionEffectiveFrom.value = minimumNewTermsDate.value
    }
    if (detail?.code === 'meal_entitlement_basis_locked') {
      saveError.value = t('payroll.people.meal_entitlement_basis.locked')
    } else {
      // Server jmenuje konkrétní pole („Nástroj APZ je povinný", „Nová smluvní
      // verze musí začínat později…"); obecné „nepovedlo se" ho jen zakrylo
      // a uživatel neměl podle čeho jednat.
      saveError.value = apiErrorMessage(error, t('payroll.people.mutation_failed'))
    }
  } finally {
    busy.value = false
  }
}

/**
 * Nástup, který se prostě stal, se potvrdí jedním krokem.
 *
 * Předregistrace odpovídá akci 9 – Předpokládaný nástup a dává smysl u nástupu
 * v BUDOUCNU. Jako povinná mezizastávka pro nástup starý rok a půl znamenala,
 * že vztah zůstal „plánovaný", nedostal skutečné datum nástupu, a tím vypadl
 * i z výplatní listiny — aniž by kdokoli řekl proč.
 */
const startDate = computed(() => props.employment.start_date)
const startAlreadyHappened = computed(
  () => props.employment.status === 'planned'
    && startDate.value !== null
    && startDate.value <= todayIso(),
)

/**
 * Registrační povinnost u ČSSZ. Dokud visí, má smysl varovat před dvojí
 * přihláškou; jakmile ji někdo vyřídí, je varování jen šum.
 */
const registrationItem = computed(
  () => props.employment.checklist.find(item => item.item_key === 'social_jmhz_registration') ?? null,
)
const registrationPending = computed(() => registrationItem.value?.status === 'pending')

/**
 * „Přihlášený je, jen ne přes nás." Konkurence to řeší stavem registrace na
 * poměru, ne zákazem — a MyÚčto na to stav `not_applicable` má, jen ho z tohohle
 * místa nešlo nastavit.
 */
async function markRegisteredElsewhere() {
  const item = registrationItem.value
  if (item === null) return
  await setChecklist(item.item_key, item.row_version, 'not_applicable')
}

/**
 * Zaměstnanec nastoupil dřív, než firma začala vést mzdy v MyÚčtu.
 *
 * Nejde o kosmetiku: bez počátečních stavů vypadne osoba z dávky zákonného
 * výpočtu, celý běh spadne do `manual_review` a přebít se to nedá — override
 * pracuje nad řádky validací, kdežto tohle je issue statutory bundlu.
 */
// Období se ořezává na YYYY-MM: API ho posílá tak, databáze drží celé datum.
const payrollStartMonth = computed(() => props.payrollStartPeriod?.slice(0, 7) ?? null)
// „2026-07" je tvar pro stroj; ve větě má stát „7/2026".
const payrollStartLabel = computed(() => {
  const period = payrollStartMonth.value
  return period === null ? '' : `${Number(period.slice(5, 7))}/${period.slice(0, 4)}`
})
const startsBeforePayroll = computed(() => {
  const period = payrollStartMonth.value
  const start = props.employment.start_date
  return period != null && start !== null && start.slice(0, 7) < period
})
const openingStartPeriod = computed(() => startsBeforePayroll.value
  ? payrollStartMonth.value
  : props.employment.start_date?.slice(0, 7) ?? payrollStartMonth.value)
const openingFirstIncludedMonth = computed(() => {
  if (!startsBeforePayroll.value) return null
  const period = payrollStartMonth.value
  const start = props.employment.start_date
  if (period === null || start === null) return null
  return start.slice(0, 4) === period.slice(0, 4)
    ? Number(start.slice(5, 7))
    : 1
})
const showOpeningBalances = computed(() => props.employment.is_primary
  && payrollStartMonth.value !== null
  && openingStartPeriod.value !== null)
/*
 * Jakmile úhrny někdo doplní, nesmí nad nimi dál viset výzva k jejich doplnění —
 * karta by úkolovala tím, co je hotové. Stav hlásí panel, který stavy načítá;
 * karta se na to sama neptá, aby kvůli hlášce nevznikl druhý požadavek.
 */
const openingsFilled = ref(false)

const renaming = ref(false)
const codeDraft = ref('')

function startRename() {
  codeDraft.value = props.employment.code
  renaming.value = true
}

async function saveCode() {
  const code = codeDraft.value.trim()
  if (busy.value || code === '' || code === props.employment.code) {
    renaming.value = false
    return
  }
  busy.value = true
  try {
    emit('updated', await payrollApi.renameEmployment(
      props.employment.id,
      props.employment.row_version,
      code,
    ))
    renaming.value = false
  } catch (error) {
    const message = (error as { response?: { data?: { error?: { message?: string } } } })
      ?.response?.data?.error?.message
    toast.error(message ?? t('payroll.people.mutation_failed'))
  } finally {
    busy.value = false
  }
}

/**
 * Potvrzení nástupu použije datum nástupu, ne dnešek — jinak by se do evidence
 * zapsalo, že člověk nastoupil ve chvíli, kdy si toho někdo všiml.
 */
async function confirmStart() {
  const on = startDate.value
  if (on === null) return
  transitionDate.value = on
  await transition('active')
}

function relationLabel(): string {
  return t(`payroll.people.relations.${props.employment.relation_type}`)
}

function statusLabel(status: PayrollEmploymentStatus): string {
  return t(`payroll.people.employment_status.${status}`)
}

function diffValueLabel(field: string, value: unknown): string {
  const resolved = employmentDiffValue(field, value)
  switch (resolved.kind) {
    case 'empty': return '—'
    case 'date': return formatDate(resolved.iso)
    case 'money': return formatMoneyMinor(resolved.minor)
    case 'key': return t(resolved.key)
    default: return resolved.text
  }
}

async function transition(target: PayrollEmploymentStatus) {
  if (!transitionDate.value || busy.value) return
  // Návrat z archivu míří na `ended`/`no_show`, ale nic neukončuje — ptát se
  // „Ukončit pracovní vztah?" by uživateli tvrdilo opak toho, co dělá.
  // Dialog zůstává: ukončení vztahu zapíše datum do evidence a rozjede
  // návazné povinnosti (ELDP, odhláška), takže „vzít zpět" jedním kliknutím
  // aplikace neumí. Musí ale říct, KTERÉHO vztahu se to týká — na kartě osoby
  // jich stojí pod sebou víc a liší se jen kódem.
  if (props.employment.status !== 'archived'
      && ['ended', 'archived', 'no_show'].includes(target)
      && !window.confirm(t('payroll.people.transition_confirm_for', {
        question: t(`payroll.people.transition_confirm.${target}`),
        code: props.employment.code,
        date: formatDate(transitionDate.value),
      }))) return

  busy.value = true
  try {
    const updated = await payrollApi.transitionEmployment(props.employment.id, target, {
      row_version: props.employment.row_version,
      effective_on: transitionDate.value,
    })
    emit('updated', updated)
    toast.success(t('payroll.people.transition_saved'))
  } catch {
    toast.error(t('payroll.people.mutation_failed'))
  } finally {
    busy.value = false
  }
}

async function setChecklist(itemKey: string, rowVersion: number, status: PayrollChecklistStatus) {
  if (busy.value) return
  busy.value = true
  try {
    const updated = await payrollApi.updateEmploymentChecklist(props.employment.id, itemKey, {
      row_version: rowVersion,
      status,
    })
    emit('updated', updated)
  } catch {
    toast.error(t('payroll.people.mutation_failed'))
  } finally {
    busy.value = false
  }
}

/**
 * Věta „Smaže se … Tuhle akci nelze vzít zpět." musí JMENOVAT, co přesně zmizí —
 * jinak uživatel potvrzuje naslepo. Vypisují se jen nenulové položky.
 */
const cascadeSummary = computed<string>(() => {
  const parts = Object.entries(props.employment.delete_cascade ?? {})
    .filter(([, count]) => count > 0)
    .map(([key, count]) => t(`payroll.people.delete.cascade.${key}`, { count }, count))
  return parts.join(', ')
})

const deleteBlockerMessage = computed<string>(
  () => props.employment.delete_blocker?.message ?? '',
)

/**
 * Smazání vztahu se potvrzuje dál — vratné to není. Server maže i navázané
 * záznamy (proto `delete_cascade`) a znovu je založit ručně nejde. Dialog už
 * vypisoval, CO smazání vezme s sebou; chyběl kód vztahu, tedy KTERÝ z několika
 * vztahů téže osoby se maže.
 */
async function removeEmployment() {
  if (busy.value || !props.employment.can_delete) return
  const summary = cascadeSummary.value
  const question = summary === ''
    ? t('payroll.people.delete.confirm_empty', { code: props.employment.code })
    : t('payroll.people.delete.confirm', { summary, code: props.employment.code })
  if (!window.confirm(question)) return

  busy.value = true
  try {
    await payrollApi.deleteEmployment(props.employment.id, props.employment.row_version)
    emit('deleted', props.employment.id)
    toast.success(t('payroll.people.delete.done'))
  } catch (error) {
    // Blokace nese větu, podle které se dá jednat — ukaž ji, ne obecné „nepovedlo se".
    const message = (error as { response?: { data?: { error?: { message?: string } } } })
      ?.response?.data?.error?.message
    toast.error(message ?? t('payroll.people.mutation_failed'))
  } finally {
    busy.value = false
  }
}

/*
 * V liště zůstal jen ŽIVOTNÍ CYKLUS vztahu (potvrzení nástupu, změny stavu,
 * přejmenování, smazání). Akce „Nová verze podmínek" odsud zmizela: úprava
 * údajů se už nezahajuje tlačítkem, pole jsou editovatelná rovnou a volba
 * oprava / nová verze padá až u Uložit.
 */
const actions = computed<ActionItem[]>(() => [
  {
    key: 'confirm-start',
    label: t('payroll.people.confirm_start', { date: formatDate(startDate.value) }),
    icon: 'check',
    tier: 'primary',
    variant: 'success',
    disabled: busy.value,
    show: props.canWrite && startAlreadyHappened.value,
    run: () => void confirmStart(),
  },
  ...transitionPresentation(
    props.employment.allowed_transitions,
    props.employment.status,
  ).map(presentation => ({
    key: `transition-${presentation.target}`,
    label: props.employment.status === 'archived'
      ? t('payroll.people.transition.unarchive')
      : t(`payroll.people.transition.${presentation.target}`),
    icon: presentation.icon,
    // Dokud nástup nenastal, hlavní krok je předregistrace (akce 9). Jakmile
    // nastal, hlavním krokem je „Potvrdit nástup" — ustoupí tedy jen ta akce,
    // která byla hlavní.
    tier: startAlreadyHappened.value && presentation.tier === 'primary'
      ? 'secondary'
      : presentation.tier,
    variant: startAlreadyHappened.value && presentation.tier === 'primary'
      ? 'neutral'
      : presentation.variant,
    disabled: busy.value || !transitionDate.value,
    show: props.canWrite
      // „Zahájit" se vedle „Potvrdit nástup" nenabízí dvakrát.
      && !(startAlreadyHappened.value && presentation.target === 'active'),
    run: () => void transition(presentation.target),
  } satisfies ActionItem)),
  {
    key: 'rename-employment',
    label: t('payroll.people.rename_action'),
    icon: 'edit',
    tier: 'advanced',
    variant: 'neutral',
    disabled: busy.value,
    show: props.canWrite,
    run: () => startRename(),
  },
  {
    // Patří do „…", ne mezi hlavní tlačítka: je to výjimečná a nevratná akce.
    // Vedle „Označit nenástup" (tier 'advanced'), protože řeší jiný případ —
    // nenástup je záznam o tom, že něco nastalo, tohle je oprava omylu.
    //
    // Když smazat nejde, důvod se řekne až při pokusu. Trvalý odstavec pod
    // kartou vysvětloval něco, co uživatel zrovna nedělá.
    key: 'delete-employment',
    label: t('payroll.people.delete.action'),
    icon: 'trash',
    tier: 'advanced',
    variant: 'danger',
    disabled: busy.value,
    show: props.canWrite,
    run: () => {
      if (!props.employment.can_delete) {
        toast.error(deleteBlockerMessage.value || t('payroll.people.delete.blocked_title'))
        return
      }
      void removeEmployment()
    },
  },
])

/*
 * Jednotné třídy vstupů. Dřív byl každý `<input>` na kartě nalepený inline
 * a lišily se výškou i odsazením — u vedle sebe stojících polí to bylo vidět.
 */
const FIELD = 'block min-w-0 text-xs font-medium text-neutral-600'
const INPUT = 'mt-1 h-9 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20 disabled:cursor-not-allowed disabled:bg-neutral-100 disabled:text-neutral-500'
const TEXTAREA = 'mt-1 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm text-neutral-900 focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20 disabled:cursor-not-allowed disabled:bg-neutral-100'
const HINT = 'mt-1 block text-xs font-normal text-neutral-500'
const READ_LABEL = 'text-xs font-medium text-neutral-500'
const READ_VALUE = 'mt-0.5 text-sm text-neutral-900'
const GROUP = 'rounded-md border border-neutral-200 bg-surface p-3'
const GROUP_TITLE = 'text-xs font-semibold uppercase tracking-wide text-neutral-500'
/*
 * Tři sloupce jsou strop, ne čtyři: od `2xl` ubere postranní pruh hlavnímu
 * sloupci 20 rem, takže čtvrtý sloupec by tam vycházel pod 200 px a popisky
 * jako „Výjimka z výměry dovolené (týdny)" by se lámaly na tři řádky.
 */
const GRID = 'mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3'
</script>

<template>
  <article class="rounded-lg border border-l-4 border-neutral-200 bg-surface p-3 sm:p-4" :class="accentClass">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="min-w-0">
        <div class="flex flex-wrap items-center gap-2">
          <h3 class="font-semibold text-neutral-900">{{ relationLabel() }}</h3>
          <span class="rounded-full bg-payroll-50 px-2 py-1 text-xs font-medium text-payroll-700">
            {{ statusLabel(employment.status) }}
          </span>
          <span v-if="employment.is_primary" class="rounded-full bg-success-50 px-2 py-1 text-xs font-medium text-success-700">
            {{ t('payroll.people.primary') }}
          </span>
          <!-- Skončený vztah nese datum v hlavičce, jinak by po sbalení nešel rozlišit. -->
          <span v-if="isClosed && employment.end_date" class="text-xs text-neutral-500">
            {{ t('payroll.people.end_date') }} {{ formatDate(employment.end_date) }}
          </span>
        </div>
        <p v-if="employmentCodeLabel(employment.code) || employment.office_name" data-test="employment-code" class="mt-1 text-xs text-neutral-500">{{ employmentCodeLabel(employment.code) }}<template v-if="employment.office_name"><template v-if="employmentCodeLabel(employment.code)"> · </template>{{ employment.office_name }}</template></p>
      </div>
      <div class="flex items-center gap-2">
        <!--
          Datum NENÍ údaj vztahu, který by se ukládal — je to účinnost pro
          tlačítka pod ním. Bez viditelného popisku vypadalo jako políčko
          k vyplnění a uživatel k němu marně hledal „Uložit".
        -->
        <label v-if="canWrite && employment.allowed_transitions.length && expanded" class="flex items-center gap-1.5 text-xs text-neutral-500">
          {{ t('payroll.people.transition_date') }}
          <input v-model="transitionDate" type="date" class="h-9 rounded-md border border-neutral-300 bg-surface px-2 text-sm text-neutral-800">
        </label>
        <button
          v-if="isClosed"
          type="button"
          :class="btnOutlineSm('neutral')"
          :aria-expanded="expanded"
          data-test="employment-toggle"
          @click="expanded = !expanded"
        >{{ expanded ? t('payroll.people.hide_detail') : t('payroll.people.show_detail') }}</button>
      </div>
    </div>

    <template v-if="expanded">
    <!--
      ROZVRŽENÍ KARTY: od `2xl` postranní pruh vlevo, pod ním jeden sloupec.
      Proč `2xl` (1536 px) a ne dřív: pruh si bere 20 rem. Na `xl` (1280 px)
      zbude po odečtení menu aplikace na hlavní sloupec kolem 620 px, do kterých
      se mřížka polí vejde nanejvýš dvousloupcově — editace by se tím zúžila,
      aby se uvolnilo místo na navigaci, což je špatný obchod. Teprve od 1536 px
      zbývá přes 900 px, tedy pořád tři sloupce polí, a pruh nic nebere.
      Pod zlomem je mřížka jednosloupcová, takže pruh spadne zpátky do toku
      přesně tam, kde rozcestník stál dosud — žádné dvojí vykreslení, jen jiný
      tvar mřížky.

      `items-start`: bez něj by se buňka pruhu roztáhla na výšku celé karty
      a `sticky` by nemělo kam ujíždět.

      Strop šířky: na ultraširokém monitoru (3440 px) by se karta roztáhla
      na celou plochu a čtení řádku i cesta myší mezi krajními sloupci by byly
      horší, ne lepší. 1800 px je maximum, na 2000px monitoru se ještě neprojeví.
    -->
    <div class="mx-auto grid w-full grid-cols-1 items-start gap-3 xl:grid-cols-[16rem_minmax(0,1fr)] 2xl:max-w-[1800px] 2xl:grid-cols-[20rem_minmax(0,1fr)]">
      <!--
        POSTRANNÍ PRUH — kam s tímhle člověkem jít dál a co o vztahu platí.
        Patří ke KONKRÉTNÍMU vztahu, ne k osobě: u člověka se souběhy má každá
        karta svůj pruh se svými počty. Sticky proto drží jen v rámci své karty
        a při rolování na další vztah se vystřídá — což je správně, čísla
        v něm platí pro ten vztah nad ním.

        `sticky` + vlastní scroll: u vztahu s třinácti agendami je pruh vyšší
        než okno. Bez `max-h`/`overflow` by se ukotvil vršek a spodek dlaždic
        by nešel zobrazit vůbec.
      -->
      <aside
        class="space-y-3 xl:sticky xl:top-4 xl:max-h-[calc(100vh-2rem)] xl:overflow-y-auto xl:pr-1"
        data-test="employment-rail"
      >
        <EmploymentAgendaPanel
          :employment-id="employment.id"
          :employee-id="employment.employee_id"
          :compact="wideRail"
        />

        <!--
          ZE ZÁKONNÉ EVIDENCE OSOBY — zrcadlo, ne druhé zadávací místo.
          Prohlášení k dani rozhoduje o měsíční slevě na poplatníka a zdravotní
          pojišťovna o odvodu; na kartě je uživatel hledal a nenašel, protože se
          oba nastavují u osoby. V pruhu stojí schválně: je to údaj, který se
          čte a ověřuje, ne mění — v hlavním sloupci mezi vstupními poli budil
          dojem, že se odsud dá přepsat.
        -->
        <section :class="GROUP" data-test="employment-person-evidence">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h4 :class="GROUP_TITLE">{{ t('payroll.people.person_evidence_title') }}</h4>
            <button
              type="button"
              class="text-xs font-medium text-payroll-700 underline underline-offset-2"
              data-test="employment-tax-declaration-link"
              @click="emit('focusStatutoryEvidence')"
            >{{ t('payroll.people.tax_declaration_edit') }}</button>
          </div>
          <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-1">
            <div data-test="employment-tax-declaration">
              <dt :class="READ_LABEL">{{ t('payroll.people.tax_declaration') }}</dt>
              <dd class="mt-1">
                <span
                  class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="taxDeclarationSigned
                    ? 'bg-success-50 text-success-800'
                    : 'bg-warning-100 text-warning-800'"
                >{{ taxDeclarationLabel }}</span>
                <span :class="HINT">{{ t('payroll.people.tax_declaration_hint') }}</span>
              </dd>
            </div>
            <div data-test="employment-health-insurer">
              <dt :class="READ_LABEL">{{ t('payroll.people.health_insurer') }}</dt>
              <dd class="mt-1">
                <span
                  class="inline-block rounded-full px-2 py-0.5 text-xs font-medium"
                  :class="healthInsurerVerified
                    ? 'bg-success-50 text-success-800'
                    : 'bg-warning-100 text-warning-800'"
                >{{ healthInsurerLabel }}</span>
                <span :class="HINT">{{ t('payroll.people.health_insurer_hint') }}</span>
              </dd>
            </div>
            <div>
              <dt :class="READ_LABEL">{{ t('payroll.people.accounting') }}</dt>
              <dd :class="READ_VALUE">{{ employment.accounting.gross_debit }}/{{ employment.accounting.gross_credit }} · {{ employment.accounting.employer_insurance_debit }}/{{ employment.accounting.employer_insurance_credit }}</dd>
            </div>
          </dl>
        </section>
      </aside>

      <!-- HLAVNÍ SLOUPEC — to, co účetní edituje. -->
      <div class="min-w-0">
    <form v-if="termsForm" data-test="employment-terms" @submit.prevent="save">
      <!--
        ZÁKLADNÍ ÚDAJE — editovatelné rovnou, bez vstupu do formuláře.
        Výběr polí: to, co mzdová účetní mění v běžném provozu a co rozhoduje
        o výpočtu (mzda, úvazek, dovolená, účtárna, hlavní vztah, stravné,
        druh činnosti pro ČSSZ) plus termíny vztahu. Zbytek je evidence pro
        podání a výjimečné režimy — ten je níž v „Dalších údajích".
      -->
      <section :class="GROUP" data-test="employment-essentials">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
          <h4 class="text-sm font-semibold text-neutral-900">{{ t('payroll.people.essentials_title') }}</h4>
          <p class="text-xs text-neutral-500">{{ t('payroll.people.essentials_hint') }}</p>
        </div>

        <div :class="GRID">
          <label :class="FIELD">
            {{ t('payroll.people.monthly_gross') }}
            <div class="relative">
              <input
                v-model="grossInput"
                inputmode="decimal"
                :disabled="!canEditTerms || busy"
                :class="[INPUT, 'pr-10']"
                data-test="terms-monthly-gross"
              >
              <span class="pointer-events-none absolute right-3 top-1/2 -translate-y-1/2 text-sm text-neutral-500">Kč</span>
            </div>
          </label>

          <label :class="FIELD">
            {{ t('payroll.people.weekly_hours') }}
            <input
              v-model="termsForm.weekly_hours"
              inputmode="decimal"
              :disabled="!canEditTerms || busy"
              :class="INPUT"
              data-test="terms-weekly-hours"
            >
          </label>

          <label :class="FIELD">
            {{ t('payroll.people.workload_bps') }}
            <input
              v-model.number="termsForm.workload_basis_points"
              type="number"
              min="1"
              max="10000"
              :disabled="!canEditTerms || busy"
              :class="INPUT"
              data-test="terms-workload"
            >
          </label>

          <label :class="FIELD">
            {{ t('payroll.people.leave_entitlement_weeks_override') }}
            <input
              v-model.number="termsForm.leave_entitlement_weeks_override"
              type="number"
              min="4"
              max="12"
              step="1"
              :disabled="!canEditTerms || busy"
              :class="INPUT"
              data-test="terms-leave-weeks"
            >
          </label>

          <!--
            Účtárna se dosud NEDALA VYBRAT nikde ve frontendu — karta ji jen
            vypisovala. Bez ní není čím vykázat odvod sociálního pojistného.
          -->
          <label :class="FIELD">
            {{ t('payroll.people.office_label') }}
            <SearchableSelect
              :model-value="termsForm.office_id"
              :options="officeOptions"
              :selected-option="selectedOfficeOption"
              :clearable="false"
              :disabled="!canEditTerms || busy"
              :placeholder="t('payroll.people.office_select')"
              :no-results-label="t('payroll.people.office_empty')"
              accent="payroll"
              class="mt-1"
              data-test="terms-office"
              @update:model-value="termsForm.office_id = $event === null ? null : Number($event)"
            />
            <span v-if="officeOptions.length === 0" :class="HINT">{{ t('payroll.people.office_empty') }}</span>
          </label>

          <!--
            Druh činnosti je kód pro ČSSZ, ale účetní ho zadává u každého
            nového člověka — patří proto mezi základní údaje, ne mezi
            podrobnosti, kam se dřív schoval spolu s kódy 10502.
          -->
          <label :class="FIELD">
            {{ t('payroll.people.activity_code') }}
            <select
              v-model="termsForm.activity_code"
              :disabled="!canEditTerms || busy"
              :class="INPUT"
              data-test="jmhz-activity-code"
              @change="onActivityCodeChange"
            >
              <option :value="null">—</option>
              <option v-for="option in jmhzOptions?.activity_codes ?? []" :key="option.code" :value="option.code">{{ option.code }} · {{ option.label }}</option>
            </select>
            <span v-if="jmhzOptionsFailed" :class="[HINT, 'text-danger-700']">{{ t('payroll.people.jmhz_evidence.options_failed') }}</span>
          </label>

          <label
            v-if="selectedRelationshipDetailMode !== 'forbidden'"
            :class="FIELD"
          >
            {{ t('payroll.people.jmhz_evidence.relationship_detail') }}
            <select
              v-model="termsForm.jmhz_relationship_detail_code"
              :disabled="!canEditTerms || busy || selectedRelationshipDetailMode === 'fixed_none'"
              :class="INPUT"
              data-test="jmhz-relationship-detail"
            >
              <option :value="null">—</option>
              <option v-for="option in jmhzOptions?.relationship_detail_codes ?? []" :key="option.code" :value="option.code">{{ option.code }} · {{ option.label }}</option>
            </select>
          </label>

          <label :class="FIELD">
            {{ t('payroll.people.meal_entitlement_basis.label') }}
            <select
              v-model="mealBasis"
              :disabled="!canEditTerms || busy"
              :class="INPUT"
              data-test="employment-meal-entitlement-basis"
            >
              <option value="shift">{{ t('payroll.people.meal_entitlement_basis.shift') }}</option>
              <option value="calendar_day">{{ t('payroll.people.meal_entitlement_basis.calendar_day') }}</option>
            </select>
            <span :class="HINT">{{ t('payroll.people.meal_entitlement_basis.hint') }}</span>
          </label>

          <label class="flex items-center gap-2 self-end text-sm text-neutral-700">
            <input
              v-model="termsForm.is_primary"
              type="checkbox"
              :disabled="!canEditTerms || busy"
              class="rounded border-neutral-300 text-payroll-600"
              data-test="terms-is-primary"
            >
            {{ t('payroll.people.primary') }}
          </label>
        </div>

        <!--
          Chybějící účtárna NEBLOKUJE uložení ani nic na kartě — jen říká, co
          kvůli ní nepůjde. Blokátorem se to stane až při uzamčení vstupů
          mzdového běhu (`employment_without_office`), a stojí hned u pole,
          kterým se to spraví.
        -->
        <p
          v-if="officeMissing"
          class="mt-3 rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-800"
          data-test="employment-office-missing"
        >{{ t('payroll.people.office_missing_warning') }}</p>
      </section>

      <!-- TERMÍNY — data vztahu. Skutečný nástup se novou verzí měnit nedá, proto jen k přečtení. -->
      <section :class="[GROUP, 'mt-3']" data-test="employment-dates">
        <h4 :class="GROUP_TITLE">{{ t('payroll.people.terms_dates_title') }}</h4>
        <div :class="GRID">
          <label :class="FIELD">
            {{ t('payroll.people.planned_start') }} <RequiredMark />
            <input
              v-model="termsForm.planned_start_on"
              required
              type="date"
              :disabled="!canEditTerms || busy"
              :class="INPUT"
              data-test="terms-planned-start"
            >
          </label>
          <label :class="FIELD">
            {{ t('payroll.people.contract_signed') }}
            <input v-model="termsForm.contract_signed_on" type="date" :disabled="!canEditTerms || busy" :class="INPUT" data-test="terms-contract-signed">
          </label>
          <label :class="FIELD">
            {{ t('payroll.people.fixed_end') }}
            <input v-model="termsForm.fixed_term_end_on" type="date" :disabled="!canEditTerms || busy" :class="INPUT" data-test="terms-fixed-end">
          </label>
          <div>
            <dt :class="READ_LABEL">{{ t('payroll.people.actual_start') }}</dt>
            <dd :class="READ_VALUE">{{ formatDate(employment.actual_start_date) }}</dd>
          </div>
          <div v-if="employment.end_date">
            <dt :class="READ_LABEL">{{ t('payroll.people.end_date') }}</dt>
            <dd :class="READ_VALUE">{{ formatDate(employment.end_date) }}</dd>
          </div>
        </div>
      </section>

      <!--
        DALŠÍ ÚDAJE — evidence pro podání a výjimečné režimy.
        Dřív to byla jedna mřížka dvaceti polí bez hierarchie a „neobvyklé
        situace" (§ 5a, § 7a, zvláštní příznaky JMHZ) v ní stály hned nahoře.
        Teď je to čtyři pojmenované skupiny a výjimky jsou až úplně na konci,
        kam patří — u běžného pracovního poměru se do nich nikdo nepodívá.
      -->
      <details class="group mt-3 rounded-md border border-neutral-200 bg-surface" :open="advancedTermsPrefilled" data-test="terms-advanced">
        <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2">
          <svg class="h-4 w-4 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
          <span class="min-w-0">
            <span class="block text-xs font-semibold text-neutral-900">{{ t('payroll.people.terms_advanced_title') }}</span>
            <span class="mt-0.5 block text-xs text-neutral-500">{{ t('payroll.people.terms_advanced_hint') }}</span>
          </span>
        </summary>

        <div class="space-y-3 border-t border-neutral-200 p-3">
          <!-- 1. Místo výkonu práce -->
          <section :class="GROUP" data-test="terms-workplace">
            <h5 :class="GROUP_TITLE">{{ t('payroll.people.terms_group.workplace') }}</h5>
            <div :class="GRID">
              <label :class="[FIELD, 'sm:col-span-2']">
                {{ t('payroll.people.jmhz_evidence.municipality_name') }}
                <SearchableSelect
                  :model-value="termsForm.jmhz_workplace_municipality_code"
                  :options="municipalityOptions.map(option => ({ value: option.code, label: option.label, secondary: option.code }))"
                  :selected-option="selectedMunicipality"
                  :remote="true"
                  :disabled="!canEditTerms || busy"
                  :loading="municipalitiesLoading"
                  :loading-label="t('payroll.people.jmhz_evidence.searching_municipality')"
                  :no-results-label="t('payroll.people.jmhz_evidence.no_municipality')"
                  :placeholder="t('payroll.people.jmhz_evidence.search_municipality')"
                  accent="payroll"
                  class="mt-1"
                  data-test="jmhz-municipality"
                  @search="searchMunicipalities"
                  @update:model-value="selectMunicipality"
                />
              </label>
              <label :class="FIELD">
                {{ t('payroll.people.jmhz_evidence.country_code') }}
                <select v-model="termsForm.jmhz_workplace_country_code" :disabled="!canEditTerms || busy" :class="INPUT">
                  <option :value="null">—</option>
                  <option v-for="country in jmhzOptions?.countries ?? []" :key="country.code" :value="country.code">{{ country.code }} · {{ country.label }}</option>
                </select>
              </label>
              <label :class="[FIELD, 'sm:col-span-2']">
                {{ t('payroll.people.regular_workplace') }}
                <input v-model="termsForm.regular_workplace" :disabled="!canEditTerms || busy" :class="INPUT">
              </label>
              <div :class="[FIELD, 'sm:col-span-2']">
                {{ t('payroll.people.cz_isco_code') }}
                <CzIscoPicker v-model="termsForm.cz_isco_code" class="mt-1" />
              </div>
            </div>
            <p v-if="jmhzOptions" class="mt-2 text-xs text-success-700">{{ t('payroll.people.jmhz_evidence.external_codebook_verified', { date: jmhzOptions.external_codebooks.verified_through }) }}</p>
          </section>

          <!-- 2. Evidence pro ČSSZ -->
          <fieldset :class="GROUP" data-test="jmhz-evidence">
            <legend :class="GROUP_TITLE">{{ t('payroll.people.jmhz_evidence.title') }}</legend>
            <div :class="GRID">
              <label :class="FIELD">
                {{ t('payroll.people.jmhz_evidence.apz_status') }}
                <select v-model="termsForm.jmhz_apz_contribution_status" :disabled="!canEditTerms || busy" :class="INPUT" data-test="jmhz-apz-status" @change="onApzStatusChange">
                  <option v-for="state in ['unverified','no','yes']" :key="state" :value="state">{{ t(`payroll.people.jmhz_evidence.state.${state}`) }}</option>
                </select>
              </label>
              <label v-if="termsForm.jmhz_apz_contribution_status === 'yes'" :class="FIELD">
                {{ t('payroll.people.jmhz_evidence.apz_instrument') }}
                <select v-model="termsForm.jmhz_apz_instrument_code" required :disabled="!canEditTerms || busy" :class="INPUT" data-test="jmhz-apz-instrument">
                  <option :value="null" disabled>{{ t('payroll.people.jmhz_evidence.select_apz') }}</option>
                  <option v-for="option in jmhzOptions?.apz_instruments ?? []" :key="option.code" :value="option.code">{{ option.code }} · {{ option.label }}</option>
                </select>
              </label>
              <label :class="FIELD">
                {{ t('payroll.people.jmhz_evidence.functional_benefits') }}
                <select v-model="termsForm.jmhz_functional_benefits_status" :disabled="!canEditTerms || busy" :class="INPUT">
                  <option v-for="state in ['unverified','no','yes']" :key="state" :value="state">{{ t(`payroll.people.jmhz_evidence.state.${state}`) }}</option>
                </select>
              </label>
              <label :class="FIELD">
                {{ t('payroll.people.jmhz_evidence.temporary_assignment') }}
                <select v-model="termsForm.jmhz_temporary_assignment_status" :disabled="!canEditTerms || busy" :class="INPUT">
                  <option v-for="state in ['unverified','no','yes']" :key="state" :value="state">{{ t(`payroll.people.jmhz_evidence.state.${state}`) }}</option>
                </select>
              </label>
            </div>
            <p v-if="termsForm.jmhz_temporary_assignment_status === 'yes'" class="mt-2 text-xs text-warning-700">{{ t('payroll.people.jmhz_evidence.temporary_assignment_blocker') }}</p>
          </fieldset>

          <!-- 3. Pojištění a daň -->
          <section :class="GROUP" data-test="terms-insurance">
            <h5 :class="GROUP_TITLE">{{ t('payroll.people.terms_group.insurance') }}</h5>
            <div :class="GRID">
              <label :class="FIELD">
                {{ t('payroll.people.social_mode') }}
                <select v-model="termsForm.social_insurance_participation" :disabled="!canEditTerms || busy" :class="INPUT">
                  <option v-for="mode in ['automatic','included','excluded','foreign']" :key="mode" :value="mode">{{ t(`payroll.people.insurance_mode.${mode}`) }}</option>
                </select>
              </label>
              <label :class="FIELD">
                {{ t('payroll.people.health_mode') }}
                <select v-model="termsForm.health_insurance_participation" :disabled="!canEditTerms || busy" :class="INPUT">
                  <option v-for="mode in ['automatic','included','excluded','foreign']" :key="mode" :value="mode">{{ t(`payroll.people.insurance_mode.${mode}`) }}</option>
                </select>
              </label>
              <label :class="FIELD">
                {{ t('payroll.people.tax_regime_label') }}
                <select v-model="termsForm.tax_regime" :disabled="!canEditTerms || busy" :class="INPUT">
                  <option v-for="mode in ['advance','withholding','foreign','manual_review']" :key="mode" :value="mode">{{ t(`payroll.people.tax_regime.${mode}`) }}</option>
                </select>
              </label>
              <label v-if="needsOtherWithholdingStatement" :class="FIELD">
                {{ t('payroll.people.other_withholding_eligibility_label') }}
                <select v-model="termsForm.other_withholding_eligibility" :disabled="!canEditTerms || busy" :class="INPUT" data-test="other-withholding-eligibility">
                  <option v-for="state in ['unverified','eligible','ineligible']" :key="state" :value="state">{{ t(`payroll.people.other_withholding_eligibility.${state}`) }}</option>
                </select>
                <span :class="HINT">{{ t('payroll.people.other_withholding_eligibility_hint') }}</span>
              </label>
              <label :class="FIELD">
                {{ t('payroll.people.foreign_country') }}
                <input v-model="termsForm.foreign_legislation_country_code" maxlength="2" :disabled="!canEditTerms || busy" :class="[INPUT, 'uppercase']">
              </label>
              <label :class="FIELD">
                {{ t('payroll.people.a1_certificate_until') }}
                <input v-model="termsForm.a1_certificate_until" type="date" :disabled="!canEditTerms || busy" :class="INPUT">
              </label>
            </div>
          </section>

          <!--
            4. Výjimečné situace až na KONCI. Stály nahoře nad běžnými poli,
            přestože se týkají hrstky vztahů — zvýšená sazba § 5a, sleva § 7a
            a zvláštní příznaky JMHZ. Doplňující pole (podklad, datum oznámení)
            se ptají teprve tehdy, když si někdo výjimku vybral.
          -->
          <section :class="[GROUP, 'border-warning-500/30 bg-warning-50']" data-test="terms-exceptions">
            <h5 class="text-xs font-semibold uppercase tracking-wide text-warning-800">{{ t('payroll.people.terms_group.exceptions') }}</h5>
            <p class="mt-1 text-xs text-neutral-600">{{ t('payroll.people.terms_group.exceptions_hint') }}</p>

            <div :class="GRID">
              <label :class="FIELD">
                {{ t('payroll.people.social_employer_rate_category_label') }}
                <select v-model="termsForm.social_employer_rate_category" :disabled="!canEditTerms || busy" :class="INPUT" data-test="social-employer-rate-category">
                  <option v-for="category in ['ordinary','rescue_and_company_fire_service','risk_employment']" :key="category" :value="category">{{ t(`payroll.people.social_employer_rate_category.${category}`) }}</option>
                </select>
              </label>
              <label v-if="termsForm.social_employer_rate_category !== 'ordinary'" :class="[FIELD, 'sm:col-span-2']">
                {{ t('payroll.people.social_employer_rate_category_evidence') }}
                <input v-model="termsForm.social_employer_rate_category_evidence" maxlength="190" :disabled="!canEditTerms || busy" :class="INPUT" data-test="social-employer-rate-category-evidence">
                <span :class="HINT">{{ t('payroll.people.social_employer_rate_category_evidence_hint') }}</span>
              </label>
            </div>

            <div :class="GRID">
              <label :class="FIELD">
                {{ t('payroll.people.social_part_time_discount_label') }}
                <select v-model="termsForm.social_part_time_discount_reason" :disabled="!canEditTerms || busy" :class="INPUT" data-test="social-part-time-discount-reason">
                  <option v-for="reason in ['none','age_55_plus','child_care_under_10','dependent_close_person_care','study_under_26','retraining_jobseeker','disabled_person','under_21']" :key="reason" :value="reason">{{ t(`payroll.people.social_part_time_discount_reason.${reason}`) }}</option>
                </select>
              </label>
              <label v-if="termsForm.social_part_time_discount_reason !== 'none'" :class="FIELD">
                {{ t('payroll.people.social_part_time_discount_notified_on') }}
                <input v-model="termsForm.social_part_time_discount_notified_on" type="date" :disabled="!canEditTerms || busy" :class="INPUT" data-test="social-part-time-discount-notified-on">
                <span :class="HINT">{{ t('payroll.people.social_part_time_discount_notified_on_hint') }}</span>
              </label>
              <label v-if="termsForm.social_part_time_discount_reason !== 'none'" :class="[FIELD, 'sm:col-span-2']">
                {{ t('payroll.people.social_part_time_discount_evidence') }}
                <input v-model="termsForm.social_part_time_discount_evidence" maxlength="190" :disabled="!canEditTerms || busy" :class="INPUT" data-test="social-part-time-discount-evidence">
                <span :class="HINT">{{ t('payroll.people.social_part_time_discount_evidence_hint') }}</span>
              </label>
            </div>

            <fieldset class="mt-3" data-test="jmhz-ordinary-profile">
              <legend class="text-xs font-medium text-neutral-700">{{ t('payroll.people.jmhz_ordinary_profile.title') }}</legend>
              <p class="mt-1 text-xs text-neutral-600">{{ t('payroll.people.jmhz_ordinary_profile.hint') }}</p>
              <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <label v-for="field in ordinaryProfileFields" :key="field.key" class="flex items-start gap-2 text-sm text-neutral-700">
                  <input v-model="termsForm[field.key]" type="checkbox" :disabled="!canEditTerms || busy" class="mt-0.5 rounded border-neutral-300 text-warning-600 focus:ring-warning-500">
                  <span class="min-w-0">{{ t(`payroll.people.jmhz_ordinary_profile.${field.label}`) }}</span>
                </label>
              </div>
              <p class="mt-2 text-xs text-neutral-500">{{ t('payroll.people.jmhz_ordinary_profile.monthly_hint') }}</p>
            </fieldset>
          </section>
        </div>
      </details>

      <!--
        JEDNO SPOLEČNÉ ULOŽIT — a jediné místo, kde se rozhoduje mezi opravou
        a novou verzí. Lišta se ukáže teprve tehdy, když je co uložit; do té
        doby karta nevypadá jako rozdělaný formulář.
      -->
      <!--
        Bez záporných okrajů: lišta bydlí v hlavním sloupci mřížky, kde by
        přetáhla do mezery vedle postranního pruhu. Neprůsvitné pozadí drží
        text čitelný nad poli, která pod ní při rolování projíždějí.
      -->
      <div
        v-if="canEditTerms && dirty"
        class="sticky bottom-0 z-10 mt-3 rounded-md border border-neutral-200 bg-surface px-3 py-3 shadow-[0_-2px_10px_rgba(21,19,29,0.08)]"
        data-test="terms-save-bar"
      >
        <p
          v-if="saveError"
          class="mb-3 rounded-md border border-danger-200 bg-danger-50 px-3 py-2 text-xs text-danger-700"
          data-test="terms-save-error"
          role="alert"
        >{{ saveError }}</p>

        <div class="flex flex-wrap items-end justify-between gap-3">
          <fieldset class="min-w-0">
            <legend class="text-xs font-medium text-neutral-600">{{ t('payroll.people.save_mode.title') }}</legend>
            <div class="mt-1 flex flex-wrap items-start gap-x-4 gap-y-2">
              <label class="flex max-w-xs items-start gap-2 text-xs text-neutral-700">
                <input v-model="saveMode" type="radio" value="correct" class="mt-0.5 border-neutral-300 text-payroll-600" data-test="save-mode-correct">
                <span class="min-w-0">
                  <span class="block font-medium text-neutral-900">{{ t('payroll.people.save_mode.correct') }}</span>
                  <span class="block text-neutral-500">{{ t('payroll.people.save_mode.correct_hint', { date: formatDate(currentTerms?.effective_from ?? '') }) }}</span>
                </span>
              </label>
              <label class="flex max-w-xs items-start gap-2 text-xs text-neutral-700">
                <input v-model="saveMode" type="radio" value="version" class="mt-0.5 border-neutral-300 text-payroll-600" data-test="save-mode-version">
                <span class="min-w-0">
                  <span class="block font-medium text-neutral-900">{{ t('payroll.people.save_mode.version') }}</span>
                  <span class="block text-neutral-500">{{ t('payroll.people.save_mode.version_hint') }}</span>
                </span>
              </label>
            </div>
          </fieldset>

          <div class="flex flex-wrap items-end justify-end gap-2">
            <!-- Datum účinnosti se ptá jen ta cesta, které na něm záleží. -->
            <label v-if="saveMode === 'version'" :class="FIELD" data-test="terms-effective-from-field">
              {{ t('payroll.people.effective_from') }} <RequiredMark />
              <input
                v-model="versionEffectiveFrom"
                required
                type="date"
                :min="minimumNewTermsDate"
                :class="[INPUT, 'w-44']"
                data-test="terms-effective-from"
              >
            </label>
            <button type="button" :class="btnOutlineSm('neutral')" :disabled="busy" data-test="terms-discard" @click="discardChanges">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
            <button type="submit" :class="btnFilledSm('primary')" :disabled="busy" data-test="terms-save">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
              {{ busy ? t('common.saving') : t('common.save') }}
            </button>
          </div>
        </div>

        <!--
          Důvod změny bere server jako VOLITELNÝ text (`optionalText`, 500 znaků);
          formulář ho měl `required`, takže kdo si přišel opravit úvazek, musel
          napřed vymyslet větu do časové osy. Je až tady, u ukládání, protože se
          týká tohohle zápisu, ne žádného konkrétního pole.
        -->
        <label :class="[FIELD, 'mt-3 block']">
          {{ t('payroll.people.change_reason') }}
          <textarea v-model="termsForm.change_reason" rows="2" :disabled="busy" :class="TEXTAREA" data-test="terms-change-reason"></textarea>
        </label>
      </div>
    </form>

    <div
      v-if="showOpeningBalances"
      class="mt-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-3 text-xs text-neutral-700"
      data-test="opening-balances-needed"
    >
      <p class="font-medium text-neutral-900">
        {{ t(startsBeforePayroll
          ? 'payroll.people.openings.title'
          : 'payroll.people.openings.title_new_hire') }}
      </p>
      <p class="mt-1">
        {{ openingsFilled
          ? t('payroll.people.openings.done')
          : (startsBeforePayroll
            ? t('payroll.people.openings.hint', {
              start: formatDate(employment.start_date),
              period: payrollStartLabel,
            })
            : t('payroll.people.openings.hint_new_hire')) }}
      </p>
      <PayrollOpeningBalancesPanel
        class="mt-3"
        :person-id="employment.employee_id"
        :start-period="openingStartPeriod!"
        :include-prior-months="startsBeforePayroll"
        :first-included-month="openingFirstIncludedMonth"
        :can-write="canWrite"
        @loaded="openingsFilled = $event"
      />
    </div>

    <form
      v-if="renaming"
      class="mt-3 flex flex-wrap items-end gap-2 rounded-lg border border-payroll-500/30 bg-payroll-50 p-3"
      data-test="employment-rename"
      @submit.prevent="saveCode"
    >
      <label class="min-w-0 flex-1 text-xs text-neutral-600">
        {{ t('payroll.people.rename_label') }}
        <input v-model="codeDraft" :class="INPUT" data-test="employment-code-input">
        <span :class="HINT">{{ t('payroll.people.rename_hint') }}</span>
      </label>
      <div class="flex gap-2 pb-6">
        <button type="button" :class="btnOutlineSm('neutral')" @click="renaming = false">{{ t('common.cancel') }}</button>
        <button type="submit" :class="btnOutlineSm('accent')" :disabled="busy">{{ t('common.save') }}</button>
      </div>
    </form>

    <ActionBar v-if="actions.some(action => action.show)" :actions="actions" class="mt-4" />

    <!--
      Povinnosti i časová osa byly vždycky rozbalené, takže jeden člověk se dvěma
      vztahy zabral přes čtyřicet řádků evidence, než se dalo něco udělat. Obojí
      se sbalí; povinnosti se samy otevřou, jen když je co plnit.
    -->
    <!-- `items-start`: bez něj se sbalená časová osa roztáhne na výšku otevřených povinností. -->
    <div class="mt-4 grid grid-cols-1 items-start gap-3 lg:grid-cols-2">
      <details class="group rounded-lg border border-neutral-200 bg-surface" :open="openChecklist.length > 0" data-test="employment-checklist">
        <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 px-3 py-2">
          <span class="flex items-center gap-2 text-sm font-semibold text-neutral-900">
            <svg class="h-4 w-4 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
            {{ t('payroll.people.checklist_title') }}
          </span>
          <span
            class="rounded-full px-2 py-0.5 text-xs font-medium"
            :class="openChecklist.length > 0 ? 'bg-warning-50 text-warning-700' : 'bg-success-50 text-success-700'"
          >{{ openChecklist.length > 0
            ? t('payroll.people.checklist_open', { count: openChecklist.length })
            : t('payroll.people.checklist_all_done') }}</span>
        </summary>
        <div class="space-y-2 border-t border-neutral-200 p-3">
          <div v-for="item in sortedChecklist" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-neutral-50 px-3 py-2 text-xs">
            <div class="min-w-0">
              <p class="font-medium text-neutral-800">{{ t(`payroll.people.checklist.${item.item_key}`) }}</p>
              <!-- Povinnost bez zákonné lhůty (interní kontrola, potvrzení na
                   žádost) nemá `due_date` — pak se datum vůbec nepíše, ať tam
                   nesvítí pomlčka bez významu. -->
              <p class="text-neutral-500"><template v-if="item.due_date">{{ formatDate(item.due_date) }} · </template>{{ t(`payroll.people.checklist_status.${checklistStatus(item)}`) }}</p>
            </div>
            <!--
              „Netýká se" model uměl od začátku, ale karta nabízela jen splnit
              a vrátit — nešlo tedy říct, že povinnost na tenhle vztah nesedí
              (prohlášení k dani u někoho, kdo ho podepsal u jiného plátce).
            -->
            <div v-if="canWrite" class="flex flex-wrap gap-1">
              <template v-if="item.status === 'pending'">
                <button type="button" :class="btnOutlineSm('success')" :disabled="busy" @click="setChecklist(item.item_key, item.row_version, 'completed')">{{ t('payroll.people.complete') }}</button>
                <button type="button" :class="btnOutlineSm('neutral')" :disabled="busy" :data-test="`checklist-na-${item.item_key}`" @click="setChecklist(item.item_key, item.row_version, 'not_applicable')">{{ t('payroll.people.not_applicable') }}</button>
              </template>
              <button v-else type="button" :class="btnOutlineSm('neutral')" :disabled="busy" @click="setChecklist(item.item_key, item.row_version, 'pending')">{{ t('payroll.people.reopen') }}</button>
            </div>
          </div>
        </div>
      </details>

      <details class="group rounded-lg border border-neutral-200 bg-surface" data-test="employment-timeline">
        <summary class="flex cursor-pointer list-none flex-wrap items-center justify-between gap-2 px-3 py-2">
          <span class="flex items-center gap-2 text-sm font-semibold text-neutral-900">
            <svg class="h-4 w-4 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
            {{ t('payroll.people.timeline_title') }}
          </span>
          <span class="text-xs text-neutral-500">{{ employment.timeline.length }}</span>
        </summary>
        <ol class="m-3 space-y-3 border-l border-payroll-500/30 pl-4">
          <li v-for="event in employment.timeline" :key="event.id" class="relative text-xs">
            <span class="absolute -left-[1.18rem] top-1 h-2 w-2 rounded-full bg-payroll-500"></span>
            <p class="font-medium text-neutral-800">{{ t(`payroll.people.event.${event.event_type}`) }}</p>
            <p class="text-neutral-500">{{ formatDate(event.effective_on) }}<template v-if="event.from_status && event.to_status"> · {{ statusLabel(event.from_status) }} → {{ statusLabel(event.to_status) }}</template></p>
            <ul class="mt-1 space-y-0.5 text-neutral-600">
              <li
                v-for="key in employmentDiffFields(event.diff, Boolean(event.from_status && event.to_status))"
                :key="key"
              >{{ t(`payroll.people.term_field.${key}`) }}: {{ diffValueLabel(key, event.diff?.[key]?.from) }} → {{ diffValueLabel(key, event.diff?.[key]?.to) }}</li>
            </ul>
            <p v-if="employmentEventNote(event.note)" class="mt-1 text-neutral-600">{{ employmentEventNote(event.note) }}</p>
          </li>
        </ol>
      </details>
    </div>

    <!--
      Registrace patří ke KONKRÉTNÍMU pracovnímu vztahu, ne k osobě: jedna
      osoba může mít víc souběžných vztahů a každý se u ČSSZ přihlašuje zvlášť.
    -->
    <!--
      Varování mizí, jakmile je registrační povinnost vyřízená — ať už splněním,
      nebo „Netýká se" u někoho, kdo je přihlášený mimo MyÚčto.
    -->
    <p
      v-if="employment.is_legacy_projection && registrationPending"
      data-test="legacy-registration-warning"
      class="mt-4 rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-800"
    >
      {{ t('payroll.people.registration_legacy_warning') }}
      <button
        v-if="canWrite && registrationPending"
        type="button"
        class="ml-1 font-medium underline underline-offset-2"
        :disabled="busy"
        data-test="registration-already-done"
        @click="markRegisteredElsewhere"
      >{{ t('payroll.people.registered_elsewhere') }}</button>
    </p>
    <EmploymentRegistrationPanel
      :employment-id="employment.id"
      :can-write="canWrite"
    />

    <EmploymentJmhzIdentityPanel
      :employment-id="employment.id"
      :start-date="employment.start_date"
      :end-date="employment.end_date"
      :can-write-employment="canWrite"
      :can-write-person="canWritePerson === true"
    />

    <EmploymentDimensionsPanel
      :employment-id="employment.id"
      :can-write="canWrite"
    />

    <EmploymentSurchargePolicyPanel
      :employment-id="employment.id"
      :can-write="canWrite"
    />

    <EmploymentExitDocumentsPanel
      v-if="employment.end_date && canReadDocuments"
      :employment="employment"
      :can-write="canWriteDocuments === true"
    />
      </div>
    </div>
    </template>
  </article>
</template>
