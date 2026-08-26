<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollChecklistStatus,
  type PayrollEmployment,
  type PayrollEmploymentStatus,
  type PayrollEmploymentJmhzEvidenceOptions,
  type PayrollJmhzMunicipalityOption,
  type PayrollEmploymentTermsPayload,
} from '@/api/payroll'
import type { PayrollOffice } from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import CzIscoPicker from '@/components/payroll/CzIscoPicker.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import RequiredMark from '@/components/ui/RequiredMark.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnOutlineSm } from '@/components/ui/buttonStyles'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatDate } from '@/composables/useFormat'
import { loadPayrollOffices } from '@/composables/usePayrollOffices'
import { useToast } from '@/composables/useToast'
import EmploymentAgendaPanel from './EmploymentAgendaPanel.vue'
import EmploymentDimensionsPanel from './EmploymentDimensionsPanel.vue'
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
}>()

const { t } = useI18n()
const toast = useToast()
const busy = ref(false)
const editingTerms = ref(false)
const transitionDate = ref(todayIso())
const termsForm = ref<PayrollEmploymentTermsPayload | null>(null)
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
/** Vztah bez účtárny — upozornění, ne zákaz: opravit se dá novou verzí podmínek. */
const officeMissing = computed(() => props.employment.office_id === null)

/**
 * Podrobnosti (JMHZ evidence, režimy pojištění a daně, sazbové kategorie § 5a
 * a slevy § 7a) se otevřou samy jen tam, kde je někdo vyplnil. Běžný pracovní
 * poměr je nechá sbalené — bez toho měl formulář nové verze podmínek přes
 * dvacet polí, ze kterých pět lidí ze šesti nepotřebuje ani jedno.
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
    || terms.cz_isco_code !== null
    || terms.activity_code !== null
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
const openChecklist = computed(() =>
  props.employment.checklist.filter(item => item.status === 'pending'),
)

/**
 * Nesplněné napřed. Karta jich ukazovala deset v pořadí, v jakém je naseedovala
 * databáze, takže „Doplnit datum nástupu" se schovalo mezi splněnými položkami.
 */
const sortedChecklist = computed(() =>
  [...props.employment.checklist].sort(
    (a, b) => Number(a.status !== 'pending') - Number(b.status !== 'pending'),
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
    case 'key': return t(resolved.key)
    default: return resolved.text
  }
}

async function startTermsEdit() {
  const terms = currentTerms.value
  if (!terms) return
  termsForm.value = {
    office_id: terms.office_id,
    effective_from: minimumNewTermsDate.value,
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
    jmhz_ozp_employment_support_applies:
      terms.jmhz_ozp_employment_support_applies ?? false,
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
    tax_declaration_signed: terms.tax_declaration_signed,
    is_primary: terms.is_primary,
    change_reason: null,
  }
  editingTerms.value = true
  // Nabídka účtáren se drží v paměti aplikace (jedno nastavení zaměstnavatele
  // na celý běh), takže tohle nestojí požadavek na každé otevření formuláře.
  offices.value = await loadPayrollOffices()
  if (jmhzOptions.value === null && !jmhzOptionsFailed.value) {
    try {
      jmhzOptions.value = await payrollApi.employmentJmhzEvidenceOptions()
    } catch {
      jmhzOptionsFailed.value = true
    }
  }
}

function onApzStatusChange() {
  if (termsForm.value?.jmhz_apz_contribution_status !== 'yes' && termsForm.value) {
    termsForm.value.jmhz_apz_instrument_code = null
  }
}

function onActivityCodeChange() {
  if (!termsForm.value) return
  if (!/^[1-9]$/.test(termsForm.value.activity_code ?? '')) {
    termsForm.value.jmhz_relationship_detail_code = null
  }
}

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

async function transition(target: PayrollEmploymentStatus) {
  if (!transitionDate.value || busy.value) return
  // Návrat z archivu míří na `ended`/`no_show`, ale nic neukončuje — ptát se
  // „Ukončit pracovní vztah?" by uživateli tvrdilo opak toho, co dělá.
  if (props.employment.status !== 'archived'
      && ['ended', 'archived', 'no_show'].includes(target)
      && !window.confirm(t(`payroll.people.transition_confirm.${target}`))) return

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

async function saveTerms() {
  if (!termsForm.value || busy.value) return
  busy.value = true
  try {
    const updated = await payrollApi.addEmploymentTerms(
      props.employment.id,
      props.employment.row_version,
      termsForm.value,
    )
    emit('updated', updated)
    editingTerms.value = false
    toast.success(t('payroll.people.terms_saved'))
  } catch (error) {
    // Server jmenuje konkrétní pole („Nástroj APZ je povinný", „Nová smluvní
    // verze musí začínat později…"); obecné „nepovedlo se" ho jen zakrylo
    // a uživatel neměl podle čeho jednat.
    toast.error(apiErrorMessage(error, t('payroll.people.mutation_failed')))
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

async function removeEmployment() {
  if (busy.value || !props.employment.can_delete) return
  const summary = cascadeSummary.value
  const question = summary === ''
    ? t('payroll.people.delete.confirm_empty')
    : t('payroll.people.delete.confirm', { summary })
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
    // která byla hlavní. Kdyby ustoupily všechny, vytlačí „Označit nenástup"
    // z „…" mezi běžná tlačítka a odsune odtud „Novou verzi podmínek".
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
    key: 'new-terms',
    label: t('payroll.people.new_terms'),
    icon: 'edit',
    tier: 'secondary',
    variant: 'neutral',
    disabled: busy.value || currentTerms.value === null,
    show: props.canWrite
      && ['planned', 'preregistered', 'active', 'suspended'].includes(props.employment.status),
    run: () => void startTermsEdit(),
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
        <input v-model="codeDraft" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" data-test="employment-code-input">
        <span class="mt-1 block text-neutral-500">{{ t('payroll.people.rename_hint') }}</span>
      </label>
      <div class="flex gap-2 pb-6">
        <button type="button" :class="btnOutlineSm('neutral')" @click="renaming = false">{{ t('common.cancel') }}</button>
        <button type="submit" :class="btnOutlineSm('accent')" :disabled="busy">{{ t('common.save') }}</button>
      </div>
    </form>

    <dl class="mt-4 grid grid-cols-2 gap-3 text-xs lg:grid-cols-4">
      <div><dt class="text-neutral-500">{{ t('payroll.people.start_date') }}</dt><dd class="mt-0.5 text-neutral-800">{{ formatDate(employment.start_date) }}</dd></div>
      <div><dt class="text-neutral-500">{{ t('payroll.people.actual_start') }}</dt><dd class="mt-0.5 text-neutral-800">{{ formatDate(employment.actual_start_date) }}</dd></div>
      <div><dt class="text-neutral-500">{{ t('payroll.people.end_date') }}</dt><dd class="mt-0.5 text-neutral-800">{{ formatDate(employment.end_date) }}</dd></div>
      <div><dt class="text-neutral-500">{{ t('payroll.people.office_label') }}</dt><dd class="mt-0.5 text-neutral-800" data-test="employment-office">{{ employment.office_name ?? '—' }}</dd></div>
      <div><dt class="text-neutral-500">{{ t('payroll.people.accounting') }}</dt><dd class="mt-0.5 text-neutral-800">{{ employment.accounting.gross_debit }}/{{ employment.accounting.gross_credit }} · {{ employment.accounting.employer_insurance_debit }}/{{ employment.accounting.employer_insurance_credit }}</dd></div>
    </dl>

    <!--
      Chybějící účtárna NEBLOKUJE uložení ani nic na kartě — jen říká, co kvůli
      ní nepůjde. Blokátorem se to stane až při uzamčení vstupů mzdového běhu
      (`employment_without_office`), a do té doby se dá v klidu doplnit.
    -->
    <p
      v-if="officeMissing"
      class="mt-3 rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-800"
      data-test="employment-office-missing"
    >{{ t('payroll.people.office_missing_warning') }}</p>

    <ActionBar v-if="actions.some(action => action.show)" :actions="actions" class="mt-4" />

    <!--
      Rozcestník do navazujících agend patří nad povinnosti a časovou osu:
      „kam s tímhle člověkem jít dál" je častější potřeba než „co se s vztahem
      kdy stalo". Načítá se až tady, tedy jen pro rozbalený vztah.
    -->
    <EmploymentAgendaPanel
      :employment-id="employment.id"
      :employee-id="employment.employee_id"
    />

    <form v-if="editingTerms && termsForm" class="mt-4 rounded-lg border border-payroll-500/30 bg-payroll-50 p-3 sm:p-4" @submit.prevent="saveTerms">
      <h4 class="text-sm font-semibold text-neutral-900">{{ t('payroll.people.new_terms') }}</h4>
      <p class="mt-1 text-xs text-neutral-600">{{ t('payroll.people.terms_basics_hint') }}</p>
      <div class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.effective_from') }} <RequiredMark />
          <input v-model="termsForm.effective_from" required type="date" :min="minimumNewTermsDate" data-test="terms-effective-from" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
          <span class="mt-1 block text-neutral-500">{{ t('payroll.people.new_terms_effective_hint', {
            date: formatDate(currentTerms?.effective_from ?? ''),
            min: formatDate(minimumNewTermsDate),
          }) }}</span>
        </label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.planned_start') }} <RequiredMark /><input v-model="termsForm.planned_start_on" required type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <!--
          Účtárna se dosud NEDALA VYBRAT nikde ve frontendu — karta ji jen
          vypisovala. Bez ní není čím vykázat odvod sociálního pojistného.
          Povinná je jen tehdy, když je z čeho vybírat: firma bez nastavených
          účtáren by jinak neuložila ani opravu překlepu v úvazku.
        -->
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.office_label') }} <RequiredMark v-if="officeOptions.length > 0" />
          <SearchableSelect
            :model-value="termsForm.office_id"
            :options="officeOptions"
            :selected-option="selectedOfficeOption"
            :clearable="false"
            :required="officeOptions.length > 0"
            :placeholder="t('payroll.people.office_select')"
            :no-results-label="t('payroll.people.office_empty')"
            accent="payroll"
            class="mt-1"
            data-test="terms-office"
            @update:model-value="termsForm.office_id = $event === null ? null : Number($event)"
          />
          <span v-if="officeOptions.length === 0" class="mt-1 block text-neutral-500">{{ t('payroll.people.office_empty') }}</span>
        </label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.weekly_hours') }}<input v-model="termsForm.weekly_hours" inputmode="decimal" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.leave_entitlement_weeks_override') }}
          <input v-model.number="termsForm.leave_entitlement_weeks_override" type="number" min="4" max="12" step="1" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm">
          <span class="mt-1 block text-neutral-500">{{ t('payroll.people.leave_entitlement_weeks_override_hint') }}</span>
        </label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.workload_bps') }}<input v-model.number="termsForm.workload_basis_points" type="number" min="1" max="10000" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.contract_signed') }}<input v-model="termsForm.contract_signed_on" type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.actual_start') }}<input v-model="termsForm.actual_start_on" type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.fixed_end') }}<input v-model="termsForm.fixed_term_end_on" type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <label class="flex items-center gap-2 text-sm text-neutral-700"><input v-model="termsForm.is_primary" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.people.primary') }}</label>
        <label class="flex items-center gap-2 text-sm text-neutral-700"><input v-model="termsForm.tax_declaration_signed" type="checkbox" class="rounded border-neutral-300 text-payroll-600">{{ t('payroll.people.tax_declaration') }}</label>
        <!--
          Důvod změny server bere jako VOLITELNÝ text (`optionalText`, 500 znaků),
          jenže formulář ho měl `required` — kdo si přišel opravit úvazek, musel
          napřed vymyslet větu do časové osy. Zůstává tu jako doporučený údaj.
        -->
        <label class="text-xs text-neutral-600 sm:col-span-2 lg:col-span-4">{{ t('payroll.people.change_reason') }}<textarea v-model="termsForm.change_reason" rows="2" data-test="terms-change-reason" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></textarea><span class="mt-1 block text-neutral-500">{{ t('payroll.people.change_reason_hint') }}</span></label>
      </div>

      <!--
        Zbytek podmínek je evidence pro podání a výjimečné režimy. Sbalí se;
        otevře se sám jen u vztahu, kde je něco z toho vyplněné.
      -->
      <details class="group mt-3 rounded-md border border-payroll-200 bg-surface" :open="advancedTermsPrefilled" data-test="terms-advanced">
        <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2">
          <svg class="h-4 w-4 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
          <span class="min-w-0">
            <span class="block text-xs font-semibold text-neutral-900">{{ t('payroll.people.terms_advanced_title') }}</span>
            <span class="mt-0.5 block text-xs text-neutral-500">{{ t('payroll.people.terms_advanced_hint') }}</span>
          </span>
        </summary>
        <div class="grid grid-cols-1 gap-3 border-t border-neutral-200 p-3 sm:grid-cols-2 lg:grid-cols-4">
        <label class="text-xs text-neutral-600 sm:col-span-2">{{ t('payroll.people.regular_workplace') }}<input v-model="termsForm.regular_workplace" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <fieldset data-test="jmhz-evidence" class="grid grid-cols-1 gap-3 rounded-md border border-payroll-200 bg-surface p-3 sm:col-span-2 sm:grid-cols-2 lg:col-span-4 lg:grid-cols-4">
          <legend class="px-1 text-xs font-semibold text-payroll-800">{{ t('payroll.people.jmhz_evidence.title') }}</legend>
          <label class="text-xs text-neutral-600 lg:col-span-3">{{ t('payroll.people.jmhz_evidence.municipality_name') }}
            <SearchableSelect
              :model-value="termsForm.jmhz_workplace_municipality_code"
              :options="municipalityOptions.map(option => ({ value: option.code, label: option.label, secondary: option.code }))"
              :selected-option="selectedMunicipality"
              :remote="true"
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
          <label class="text-xs text-neutral-600">{{ t('payroll.people.jmhz_evidence.country_code') }}<select v-model="termsForm.jmhz_workplace_country_code" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option :value="null">—</option><option v-for="country in jmhzOptions?.countries ?? []" :key="country.code" :value="country.code">{{ country.code }} · {{ country.label }}</option></select></label>
          <p v-if="jmhzOptions" class="text-xs text-success-700 sm:col-span-2 lg:col-span-4">{{ t('payroll.people.jmhz_evidence.external_codebook_verified', { date: jmhzOptions.external_codebooks.verified_through }) }}</p>
          <label class="text-xs text-neutral-600">{{ t('payroll.people.jmhz_evidence.apz_status') }}<select v-model="termsForm.jmhz_apz_contribution_status" data-test="jmhz-apz-status" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" @change="onApzStatusChange"><option v-for="state in ['unverified','no','yes']" :key="state" :value="state">{{ t(`payroll.people.jmhz_evidence.state.${state}`) }}</option></select></label>
          <label v-if="termsForm.jmhz_apz_contribution_status === 'yes'" class="text-xs text-neutral-600">{{ t('payroll.people.jmhz_evidence.apz_instrument') }}<select v-model="termsForm.jmhz_apz_instrument_code" data-test="jmhz-apz-instrument" required class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option :value="null" disabled>{{ t('payroll.people.jmhz_evidence.select_apz') }}</option><option v-for="option in jmhzOptions?.apz_instruments ?? []" :key="option.code" :value="option.code">{{ option.code }} · {{ option.label }}</option></select></label>
          <label class="text-xs text-neutral-600">{{ t('payroll.people.jmhz_evidence.functional_benefits') }}<select v-model="termsForm.jmhz_functional_benefits_status" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="state in ['unverified','no','yes']" :key="state" :value="state">{{ t(`payroll.people.jmhz_evidence.state.${state}`) }}</option></select></label>
          <label class="text-xs text-neutral-600">{{ t('payroll.people.jmhz_evidence.temporary_assignment') }}<select v-model="termsForm.jmhz_temporary_assignment_status" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="state in ['unverified','no','yes']" :key="state" :value="state">{{ t(`payroll.people.jmhz_evidence.state.${state}`) }}</option></select></label>
          <p v-if="jmhzOptionsFailed" class="text-xs text-danger-700 sm:col-span-2 lg:col-span-4">{{ t('payroll.people.jmhz_evidence.options_failed') }}</p>
          <p v-if="termsForm.jmhz_temporary_assignment_status === 'yes'" class="text-xs text-warning-700 sm:col-span-2 lg:col-span-4">{{ t('payroll.people.jmhz_evidence.temporary_assignment_blocker') }}</p>
        </fieldset>
        <fieldset data-test="jmhz-ordinary-profile" class="grid grid-cols-1 gap-3 rounded-md border border-warning-500/30 bg-warning-50 p-3 sm:col-span-2 lg:col-span-4">
          <legend class="px-1 text-xs font-semibold text-warning-800">{{ t('payroll.people.jmhz_ordinary_profile.title') }}</legend>
          <p class="text-xs text-neutral-600 sm:col-span-2 lg:col-span-4">{{ t('payroll.people.jmhz_ordinary_profile.hint') }}</p>
          <label v-for="field in ordinaryProfileFields" :key="field.key" class="flex items-start gap-2 text-sm text-neutral-700">
            <input v-model="termsForm[field.key]" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-warning-600 focus:ring-warning-500">
            <span>{{ t(`payroll.people.jmhz_ordinary_profile.${field.label}`) }}</span>
          </label>
          <p class="text-xs text-neutral-500 sm:col-span-2 lg:col-span-4">{{ t('payroll.people.jmhz_ordinary_profile.monthly_hint') }}</p>
        </fieldset>
        <div class="text-xs text-neutral-600">
          <label class="block">{{ t('payroll.people.cz_isco_code') }}</label>
          <CzIscoPicker v-model="termsForm.cz_isco_code" class="mt-1" />
        </div>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.activity_code') }}<select v-model="termsForm.activity_code" data-test="jmhz-activity-code" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" @change="onActivityCodeChange"><option :value="null">—</option><option v-for="option in jmhzOptions?.activity_codes ?? []" :key="option.code" :value="option.code">{{ option.code }} · {{ option.label }}</option></select></label>
        <label v-if="/^[1-9]$/.test(termsForm.activity_code ?? '')" class="text-xs text-neutral-600">{{ t('payroll.people.jmhz_evidence.relationship_detail') }}<select v-model="termsForm.jmhz_relationship_detail_code" data-test="jmhz-relationship-detail" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option :value="null">—</option><option v-for="option in jmhzOptions?.relationship_detail_codes ?? []" :key="option.code" :value="option.code">{{ option.code }} · {{ option.label }}</option></select></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.social_mode') }}<select v-model="termsForm.social_insurance_participation" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="mode in ['automatic','included','excluded','foreign']" :key="mode" :value="mode">{{ t(`payroll.people.insurance_mode.${mode}`) }}</option></select></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.health_mode') }}<select v-model="termsForm.health_insurance_participation" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="mode in ['automatic','included','excluded','foreign']" :key="mode" :value="mode">{{ t(`payroll.people.insurance_mode.${mode}`) }}</option></select></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.tax_regime_label') }}<select v-model="termsForm.tax_regime" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="mode in ['advance','withholding','foreign','manual_review']" :key="mode" :value="mode">{{ t(`payroll.people.tax_regime.${mode}`) }}</option></select></label>
        <label v-if="needsOtherWithholdingStatement" class="text-xs text-neutral-600 sm:col-span-2 lg:col-span-4">{{ t('payroll.people.other_withholding_eligibility_label') }}<select v-model="termsForm.other_withholding_eligibility" data-test="other-withholding-eligibility" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="state in ['unverified','eligible','ineligible']" :key="state" :value="state">{{ t(`payroll.people.other_withholding_eligibility.${state}`) }}</option></select><span class="mt-1 block text-neutral-500">{{ t('payroll.people.other_withholding_eligibility_hint') }}</span></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.foreign_country') }}<input v-model="termsForm.foreign_legislation_country_code" maxlength="2" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm uppercase"></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.a1_certificate_until') }}<input v-model="termsForm.a1_certificate_until" type="date" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"></label>
        <!--
          Sazbová kategorie § 5a odst. 1 nahradila zaškrtávátko „Riziková práce":
          písmena jsou tři, ne dvě, a boolean neuměl říct, že jde o zdravotnického
          záchranáře nebo podnikového hasiče. Riziková práce je jedna z hodnot
          a server ji do starého sloupce dopočítá sám.
        -->
        <label class="text-xs text-neutral-600">{{ t('payroll.people.social_employer_rate_category_label') }}<select v-model="termsForm.social_employer_rate_category" data-test="social-employer-rate-category" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="category in ['ordinary','rescue_and_company_fire_service','risk_employment']" :key="category" :value="category">{{ t(`payroll.people.social_employer_rate_category.${category}`) }}</option></select></label>
        <label v-if="termsForm.social_employer_rate_category !== 'ordinary'" class="text-xs text-neutral-600 sm:col-span-2 lg:col-span-3">{{ t('payroll.people.social_employer_rate_category_evidence') }}<input v-model="termsForm.social_employer_rate_category_evidence" maxlength="190" data-test="social-employer-rate-category-evidence" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><span class="mt-1 block text-neutral-500">{{ t('payroll.people.social_employer_rate_category_evidence_hint') }}</span></label>
        <label class="text-xs text-neutral-600">{{ t('payroll.people.social_part_time_discount_label') }}<select v-model="termsForm.social_part_time_discount_reason" data-test="social-part-time-discount-reason" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><option v-for="reason in ['none','age_55_plus','child_care_under_10','dependent_close_person_care','study_under_26','retraining_jobseeker','disabled_person','under_21']" :key="reason" :value="reason">{{ t(`payroll.people.social_part_time_discount_reason.${reason}`) }}</option></select></label>
        <label v-if="termsForm.social_part_time_discount_reason !== 'none'" class="text-xs text-neutral-600">{{ t('payroll.people.social_part_time_discount_notified_on') }}<input v-model="termsForm.social_part_time_discount_notified_on" type="date" data-test="social-part-time-discount-notified-on" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><span class="mt-1 block text-neutral-500">{{ t('payroll.people.social_part_time_discount_notified_on_hint') }}</span></label>
        <label v-if="termsForm.social_part_time_discount_reason !== 'none'" class="text-xs text-neutral-600 sm:col-span-2 lg:col-span-3">{{ t('payroll.people.social_part_time_discount_evidence') }}<input v-model="termsForm.social_part_time_discount_evidence" maxlength="190" data-test="social-part-time-discount-evidence" class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"><span class="mt-1 block text-neutral-500">{{ t('payroll.people.social_part_time_discount_evidence_hint') }}</span></label>
        </div>
      </details>
      <div class="mt-4 flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutlineSm('neutral')" @click="editingTerms = false">{{ t('common.cancel') }}</button>
        <button type="submit" :class="btnOutlineSm('accent')" :disabled="busy">{{ t('common.save') }}</button>
      </div>
    </form>

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
            <div>
              <p class="font-medium text-neutral-800">{{ t(`payroll.people.checklist.${item.item_key}`) }}</p>
              <p class="text-neutral-500">{{ formatDate(item.due_date) }} · {{ t(`payroll.people.checklist_status.${item.status}`) }}</p>
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
      Proto je panel tady, vedle checklistu, jehož položku „Přihláška na ČSSZ"
      obsluhuje.
    -->
    <!--
      Převzatý vztah registraci NESKRÝVÁ. Skrývat zákonnou povinnost bez
      vysvětlení je horší než ji nabídnout s varováním — API ji nikdy neblokovalo,
      bylo to jen `v-if` bez zdůvodnění. Kdyby pro blokaci existoval skutečný
      důvod, patří do API jako stav s větou, ne do šablony.
    -->
    <!--
      Varování mizí, jakmile je registrační povinnost vyřízená — ať už splněním,
      nebo „Netýká se" u někoho, kdo je přihlášený mimo MyÚčto. Dřív svítilo
      natrvalo a nedalo se s ním nic udělat.
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

    <EmploymentExitDocumentsPanel
      v-if="employment.end_date && canReadDocuments"
      :employment="employment"
      :can-write="canWriteDocuments === true"
    />
    </template>
  </article>
</template>
