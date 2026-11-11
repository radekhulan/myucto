<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import {
  payrollApi,
  type PayrollRun,
  type PayrollRunCommand,
  type PayrollRunHistory,
  type PayrollRunHistoryEvent,
  type PayrollRunHistoryTotalDiff,
  type PayrollRunHistoryTotalKey,
  type PayrollRunAverageChangeCompensation,
  type PayrollRunAverageChangeEmployment,
  type PayrollRunMissingEmployment,
  type PayrollRunReadiness,
  type PayrollRunReadinessBulkKind,
  type PayrollRunReadinessFinding,
  type PayrollRunRevisionHistory,
  type PayrollRunValidation,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { payrollAbsenceApi } from '@/api/payrollAbsences'
import { usePayrollYearClosedToast } from '@/composables/usePayrollYearClosedToast'
import PayrollRunPeopleTable from '@/components/payroll/PayrollRunPeopleTable.vue'
import { btnFilled, btnFilledSm, btnOutline, btnOutlineSm, btnOutlineSmWrap, disabledTitle, BTN_DISABLED_NOTE, ICONS } from '@/components/ui/buttonStyles'
// Formátování je sdílené (useFormat) — místní kopie se rozcházely v locale i tvaru.
import { formatDate, formatDateTime, formatMoneyMinor as money, formatPeriod } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import ExpandableList from '@/components/ui/ExpandableList.vue'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import { appIsoDate } from '@/utils/date'
import { payrollQueryPeriod } from '@/pages/payroll/payrollComponentsUi'
import { runPayrollInputBatch } from '@/pages/payroll/payrollInputFilters'
import PayrollMonthlyChecklistPanel from '@/pages/payroll/PayrollMonthlyChecklistPanel.vue'
import PayrollTakeoverRunsPanel from '@/pages/payroll/PayrollTakeoverRunsPanel.vue'
import type { PayrollRegzelEnvironment, PayrollStatutoryBulkResult } from '@/api/payroll'
import DateInput from '@/components/ui/DateInput.vue'
import PayrollStatutoryBulkDefaultsDialog from '@/components/payroll/PayrollStatutoryBulkDefaultsDialog.vue'
import PayrollHideWarningDialog from '@/components/payroll/PayrollHideWarningDialog.vue'
import {
  evidenceRefreshCommand,
  firstStatutoryReviewId,
  statutoryReviewEmployeeIds,
} from '@/components/payroll/statutoryBulkDefaults'
import { runRemediationHref } from './payrollRemediationQuery'

const { t } = useI18n()
const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const toast = useToast()
/* Uzavřený mzdový rok blokuje i zdejší zápis — hláška musí vést na uzávěrku. */
const showPayrollError = usePayrollYearClosedToast()
const loading = ref(false)
/*
 * Selhalo načtení? Pak o obsahu nevíme NIC — a to je něco jiného než „nic tu
 * není". Toast s chybou za pár vteřin zmizí a bez tohohle příznaku by na
 * obrazovce zůstal prázdný stav, který lže.
 */
const loadFailed = ref(false)
const saving = ref(false)
/**
 * Příkaz běhu, na jehož odpověď se čeká. Výpočet, schválení i oprava jsou jeden
 * synchronní požadavek, u větší firmy na desítky sekund; samotné zašedlé
 * tlačítko vypadalo jako zamrzlá stránka.
 */
const runningCommand = ref<{ runId: number, command: PayrollRunCommand } | null>(null)
const COMMANDS_WITH_PROGRESS_TEXT = new Set<PayrollRunCommand>([
  'lock_and_calculate', 'calculate', 'refresh_inputs', 'approve', 'reopen', 'post', 'prepare_payments',
])

function commandProgressLabel(command: PayrollRunCommand): string {
  return t(`payroll.runs.command_progress.${COMMANDS_WITH_PROGRESS_TEXT.has(command) ? command : 'other'}`)
}
/*
 * Období drží URL, ne jen stav komponenty. Účetní zpracovává mzdy zpětně — v září
 * dělá srpen — a bez toho ji každé obnovení stránky, návrat z detailu i sdílený
 * odkaz vrátily do dnešního měsíce, kde žádný běh není. Vypadá to, jako by běh
 * zmizel, a měsíc se musí pokaždé přepínat ručně.
 */
const period = ref(payrollQueryPeriod(route.query))
const paymentDate = ref(fallbackPaymentDate(period.value))
/**
 * Návrh výplatního termínu ze sjednané mzdové politiky, jak ho spočítal server
 * (zná státní svátky, prohlížeč ne). Do prvního načtení je `null` a platí
 * nouzový termín.
 */
const suggestedPaymentDate = ref<string | null>(null)
/*
 * Měsíce od začátku vedení mezd před zvoleným obdobím, za které nemá firma
 * žádný běh. Běh staví na předchozích měsících (roční kumulace, průměry,
 * zálohová daň); díra po převodu z jiného programu se dřív nikde neukázala.
 */
const missingPreviousPeriods = ref<string[]>([])

function openMissingPeriod(next: string) {
  period.value = next
  offset.value = 0
  void load()
}

/*
 * Chybějící měsíce zpracoval předchozí program: jedním krokem se začátek
 * vedení mezd posune za ně a panel převzatých měsíců je nabídne k převzetí.
 */
const advanceStartTo = ref<string | null>(null)
const canSettings = computed(() => auth.canWrite('payroll.settings'))
const advancingStart = ref(false)
const takeoverPanelKey = ref(0)

async function advanceStart() {
  const target = advanceStartTo.value
  if (target === null || advancingStart.value) return
  advancingStart.value = true
  try {
    const result = await payrollApi.advancePayrollStart(target)
    toast.success(t('payroll.runs.missing_previous.advanced', {
      from: formatPeriod(result.moved.from),
      to: formatPeriod(result.moved.to),
    }))
    takeoverPanelKey.value++
    await load()
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.runs.missing_previous.advance_failed')))
  } finally {
    advancingStart.value = false
  }
}
/** Ručně přepsané datum se návrhem ze serveru nepřepisuje zpátky. */
const paymentDateTouched = ref(false)
const runs = ref<PayrollRun[]>([])
const personNames = ref<Record<number, string>>({})
/**
 * U kterých běhů je rozbalený přehled osob. Výsledky osob seznam běhů neposílá;
 * stránkuje a dotahuje si je `PayrollRunPeopleTable` sama.
 */
const breakdownOpen = ref<Record<number, boolean>>({})
/** Číselník jmen se stahuje jednou, ne při každém obnovení seznamu běhů. */
let personNamesLoaded = false
const histories = ref<Record<number, PayrollRunHistory>>({})
const historyOpen = ref<Record<number, boolean>>({})
const historyLoading = ref<Record<number, boolean>>({})
const historyFailed = ref<Record<number, boolean>>({})
const total = ref(0)
const pageSize = 12
const offset = ref(0)
const currentPage = computed(() => Math.floor(offset.value / pageSize) + 1)
const pendingCommand = ref<{ run: PayrollRun, command: PayrollRunCommand } | null>(null)
const pendingDelete = ref<PayrollRun | null>(null)
/** Běh čekající na potvrzení schválení: je to závazný krok, zpět vede jen oprava běhu. */
const pendingApprove = ref<PayrollRun | null>(null)
const commandReason = ref('')
const averageCorrectionRunId = ref<number | null>(null)
const commandError = ref('')
const commandBlockers = ref<Record<number, string>>({})
/** Proč se koncepty vstupů nepodařilo schválit — seskupené po větě, u běhu. */
type DraftInputFailure = { message: string, count: number }
const draftInputFailures = ref<Record<number, DraftInputFailure[]>>({})
/** `group` = hromadné schválení celé skupiny kontrol jednoho kódu. */
const pendingOverride = ref<{ run: PayrollRun, validation: PayrollRunValidation, group?: DisplayValidation } | null>(null)
const overrideReason = ref('')
const overrideError = ref('')
/**
 * KONTROLA PŘED ZAHÁJENÍM. Server ji počítá nasucho k období — nic nezmrazí,
 * nic neuloží, jen řekne, co uvidí, až se běh spustí. `null` = období není
 * zvolené, běh je už za zámkem (tam nálezy visí na revizi), nebo se kontrola
 * nepovedla; v žádném z těch případů se nesmí nic zablokovat.
 */
const readiness = ref<PayrollRunReadiness | null>(null)
/** Běh a příkaz čekající na potvrzení „opravdu zahájit?" po předběžné kontrole. */
const pendingStart = ref<{ run: PayrollRun, command: PayrollRunCommand } | null>(null)

const readinessFindings = computed(() => readiness.value?.findings ?? [])

/*
 * Chybějící průměrný výdělek hodinového vztahu (příplatek za noc a víkend,
 * atribut JMHZ). Rozhodnutí maintainera: doplní se pravděpodobný výdělek ze
 * sjednané sazby hromadně jedním tlačítkem; jde opravit novou revizí.
 */
const PROBABLE_AVERAGE_CODES = ['hourly_average_earning_missing', 'jmhz_average_hourly_earning_missing']
const canFillAverages = computed(() => auth.canWrite('payroll.time.write'))
const fillingAverages = ref(false)
async function fillProbableAverages(): Promise<void> {
  if (fillingAverages.value) return
  fillingAverages.value = true
  try {
    const result = await payrollAbsenceApi.fillProbableFromRate(period.value)
    toast.success(t('payroll.runs.readiness.fill_probable_average_done', { count: result.created.length }))
    await load()
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.runs.readiness.fill_probable_average_failed')))
  } finally {
    fillingAverages.value = false
  }
}

const canWrite = computed(() => auth.canWrite('payroll.inputs.write'))
const checklistEnvironment = ref<PayrollRegzelEnvironment>('production')
const preparationOpen = ref(true)

/**
 * Běh zvoleného období, pokud existuje. Seznam je stránkovaný přes všechna
 * období, takže se hledá podle měsíce, ne podle pozice.
 */
const periodRun = computed(
  () => runs.value.find(run => run.period_start.slice(0, 7) === period.value) ?? null,
)

/** Rok zvoleného období — panel převzatých měsíců pracuje po rocích. */
const takeoverYear = computed(() => Number(period.value.slice(0, 4)))

/**
 * Příprava vstupů svítí, jen dokud jsou vstupy měnitelné — tedy když za období
 * ještě není běh, nebo je v konceptu.
 *
 * Why: „Uzamknout vstupy" bylo na téhle obrazovce primární tlačítko, ale nic
 * neříkalo, CO se má před zamknutím vyplnit ani kde. Zámek přitom zmrazí
 * snímek vstupů; co se zapíše potom, se do výpočtu ani do hlášení nedostane
 * bez znovuotevření běhu. Rozcestník i přehled odvodů proto patří sem, před
 * zámek, ne až na mzdovou nástěnku.
 *
 * Po zamknutí blok mizí: odkazy na pořizování by v tu chvíli lhaly.
 */
const showPreparation = computed(
  () => periodRun.value === null || periodRun.value.status === 'draft',
)
/*
 * Schválení výjimky je věcně část schválení mzdy („vím o vadě a přesto se
 * vyplácí"), proto stejné právo jako u příkazu `approve` — server to vynucuje
 * stejně, tohle je jen to, aby se nenabízelo tlačítko, které skončí 403.
 */
const canOverride = computed(() => auth.canWrite('payroll.approve'))

type DisplayValidation = PayrollRunValidation & {
  group_key: string
  display_message: string
  entity_labels: string[]
  remediation_links: { path: string, label: string }[]
  /**
   * Členové skupiny varování s `requires_override` (jeden kód, jeden stav).
   * Prázdné u ostatních nálezů a u osamoceného varování, které se kreslí
   * po staru.
   */
  override_items: OverrideItem[]
  /** Osoby (vztahy), u kterých jde varování skupiny trvale skrýt. */
  hide_subjects: HideSubject[]
}

type HideSubject = { id: number, label: string }

type OverrideItem = {
  validation: PayrollRunValidation
  label: string
  remediation: string | null
}

const GROUPED_VALIDATION_CODES = new Set([
  'draft_inputs_present',
  'enforcement_manual_review',
])
const ENFORCEMENT_NET_PAY_ISSUE = 'income:net_pay_result_missing_or_unverified'

/** Starší revize mohou nést technický kód z doby před serverovým překladačem. */
function containsInternalIssueCode(message: string): boolean {
  return /(?:[a-z][a-z0-9]*[_-]){2,}[a-z0-9_-]+|(?:employee|employment):\d+|[a-z_]+:[a-z0-9_-]+:/iu.test(message)
}

function legacyEnforcementIssues(message: string): { netPay: boolean, other: boolean } {
  const netPay = message.includes(ENFORCEMENT_NET_PAY_ISSUE)
  const remainder = message.replaceAll(ENFORCEMENT_NET_PAY_ISSUE, '')
  return { netPay, other: containsInternalIssueCode(remainder) }
}

function validationGroupingKey(validation: PayrollRunValidation): string {
  if (validation.code === 'draft_inputs_present') return validation.code
  if (validation.code !== 'enforcement_manual_review') return `validation-${validation.id}`
  if (!containsInternalIssueCode(validation.message)) {
    return `${validation.code}:message:${validation.message}`
  }
  const issues = legacyEnforcementIssues(validation.message)
  return `${validation.code}:legacy:${issues.netPay ? 'net-pay' : 'no-net-pay'}:${issues.other ? 'other' : 'only'}`
}

function validationDisplayMessage(validation: PayrollRunValidation, count = 1): string {
  if (validation.code === 'draft_inputs_present') {
    return t(
      `payroll.runs.validation.draft_inputs_${count === 1 ? 'one' : 'many'}`,
      { count },
    )
  }
  if (validation.code === 'enforcement_manual_review') {
    if (!containsInternalIssueCode(validation.message)) return validation.message
    const issues = legacyEnforcementIssues(validation.message)
    const parts: string[] = []
    if (issues.netPay) {
      parts.push(t(
        `payroll.runs.validation.enforcement_net_pay_${count === 1 ? 'one' : 'many'}`,
        { count },
      ))
    }
    if (issues.other || !issues.netPay) {
      parts.push(t('payroll.runs.validation.requires_attention'))
    }
    return parts.join(' ')
  }
  if (validation.code === 'statutory_calculation_manual_review'
    && containsInternalIssueCode(validation.message)) {
    return t('payroll.runs.validation.statutory_incomplete')
  }
  return containsInternalIssueCode(validation.message)
    ? t('payroll.runs.validation.requires_attention')
    : validation.message
}

/** Odkaz k nápravě doplněný o období běhu tam, kde cílová stránka období zná. */
function remediationHref(remediationPath: string, runPeriod: string): string {
  return runRemediationHref(remediationPath, runPeriod)
}

/** Odkaz nálezu předběžné kontroly vede na měsíc kontroly, ne na zpracovávaný. */
function readinessHref(remediationPath: string): string {
  return runRemediationHref(remediationPath, readiness.value?.period_start)
}

/*
 * Varování vyžadující výjimku se seskupují podle kódu a stavu (čeká /
 * schválené). U 225 lidí bez přihlášky to dřív bylo 225 karet a 225 dialogů.
 */
function overrideGroupKey(validation: PayrollRunValidation): string {
  return `override-${validation.code}-${validation.overridden_at === null ? 'pending' : 'granted'}`
}

/** „Jana Nováková: k pracovnímu vztahu chybí …" → jméno a společný zbytek. */
function splitPersonPrefix(message: string): { label: string, rest: string } | null {
  const match = message.match(/^([^:\n]{1,120}):\s+(.+)$/su)
  return match?.[1] && match[2] ? { label: match[1].trim(), rest: match[2] } : null
}

function overrideGroupParts(items: PayrollRunValidation[]): Array<{ label: string, rest: string }> | null {
  const parts = items.map(item => splitPersonPrefix(item.message))
  if (parts.some(part => part === null)) return null
  const rests = new Set(parts.map(part => part!.rest))
  return rests.size === 1 ? parts as Array<{ label: string, rest: string }> : null
}

function overrideGroupMessage(items: PayrollRunValidation[]): string {
  const parts = overrideGroupParts(items)
  if (parts === null) return validationDisplayMessage(items[0]!, items.length)
  const rest = parts[0]!.rest
  const text = rest.charAt(0).toLocaleUpperCase('cs') + rest.slice(1)
  return containsInternalIssueCode(text) ? t('payroll.runs.validation.requires_attention') : text
}

function overrideItems(items: PayrollRunValidation[], runPeriod: string): OverrideItem[] {
  const parts = overrideGroupParts(items)
  return items.map((item, index) => ({
    validation: item,
    label: (item.entity_type === 'employee' && item.entity_id !== null
      ? personNames.value[item.entity_id]
      : undefined) ?? parts?.[index]?.label ?? item.message,
    remediation: item.remediation_path ? remediationHref(item.remediation_path, runPeriod) : null,
  }))
}

function validationGroups(validations: PayrollRunValidation[], runPeriod: string): DisplayValidation[] {
  const groups: Array<{ primary: PayrollRunValidation, items: PayrollRunValidation[] }> = []
  const grouped = new Map<string, { primary: PayrollRunValidation, items: PayrollRunValidation[] }>()

  for (const validation of validations) {
    const canGroup = GROUPED_VALIDATION_CODES.has(validation.code)
    // Q15-8: samostatná varování jednoho kódu (187× „nemá schválenou
    // pracovní dobu") jsou jedna skupina se jmény, ne osm stránek karet,
    // pod kterými se ztratí to, co blokuje.
    const key = validation.requires_override
      ? overrideGroupKey(validation)
      : canGroup ? validationGroupingKey(validation)
        : validation.severity !== 'blocker' ? warningGroupKey(validation)
          : `validation-${validation.id}`
    let group = grouped.get(key)
    if (!group) {
      group = { primary: validation, items: [] }
      grouped.set(key, group)
      groups.push(group)
    }
    group.items.push(validation)
  }

  const display = groups.map(({ primary, items }): DisplayValidation => {
    if (primary.requires_override && items.length > 1) {
      return {
        ...primary,
        group_key: overrideGroupKey(primary),
        display_message: overrideGroupMessage(items),
        entity_labels: [],
        remediation_links: [],
        override_items: overrideItems(items, runPeriod),
        hide_subjects: [],
      }
    }
    const warningGroup = !GROUPED_VALIDATION_CODES.has(primary.code) && primary.severity !== 'blocker'
    const personLabel = (item: PayrollRunValidation): string | undefined => {
      if (item.entity_type === 'employee' && item.entity_id !== null && personNames.value[item.entity_id]) {
        return personNames.value[item.entity_id]
      }
      const namedEmployment = item.message.match(/^(.+?): pracovní vztah/u)
      if (namedEmployment?.[1]) return namedEmployment[1]
      return warningGroup && items.length > 1 ? splitPersonPrefix(item.message)?.label : undefined
    }
    const entityLabels = Array.from(new Set(items.flatMap((item) => {
      const label = personLabel(item)
      return label ? [label] : []
    })))

    const displayMessage = warningGroup && items.length > 1
      ? overrideGroupMessage(items)
      : validationDisplayMessage(primary, items.length)
    const links = new Map<string, string[]>()
    for (const item of items) {
      if (!item.remediation_path) continue
      const path = remediationHref(item.remediation_path, runPeriod)
      const labels = links.get(path) ?? []
      const label = personLabel(item)
      if (label && !labels.includes(label)) labels.push(label)
      links.set(path, labels)
    }

    return {
      ...primary,
      group_key: GROUPED_VALIDATION_CODES.has(primary.code)
        ? primary.code
        : warningGroup ? warningGroupKey(primary) : `validation-${primary.id}`,
      display_message: displayMessage,
      entity_labels: entityLabels,
      remediation_links: Array.from(links, ([path, labels]) => ({ path, label: labels.join(', ') })),
      override_items: [],
      hide_subjects: primary.hideable ? hideSubjects(items) : [],
    }
  })

  // Co drží schválení, jde nahoru (Q15-8). Řazení je stabilní, takže pořadí
  // uvnitř obou částí zůstává, jak ho poslal server.
  return [
    ...display.filter(group => blocksApproval(group)),
    ...display.filter(group => !blocksApproval(group)),
  ]
}

function warningGroupKey(validation: PayrollRunValidation): string {
  return `warning-${validation.code}`
}

/*
 * Trvalé skrytí varování (po osobě nebo celý typ ve firmě). Server posílá
 * u skrytelných kontrol `subject_ids` — osoby souhrnu „N osob…", nebo
 * pracovní vztah samostatného varování. Jméno vztahu nese začátek zprávy.
 */
function subjectLabel(type: PayrollRunValidation['subject_type'], id: number, message?: string): string {
  if (type === 'employee' && personNames.value[id]) return personNames.value[id]!
  if (type === 'employment' && message) {
    const label = splitPersonPrefix(message)?.label
    if (label) return label
  }
  return t('payroll.warning_suppressions.subject_unknown', { id })
}

function hideSubjects(items: PayrollRunValidation[]): HideSubject[] {
  const subjects = new Map<number, HideSubject>()
  for (const item of items) {
    if (!item.hideable) continue
    for (const id of item.subject_ids ?? []) {
      if (!subjects.has(id)) subjects.set(id, { id, label: subjectLabel(item.subject_type ?? null, id, item.message) })
    }
  }
  return Array.from(subjects.values())
}

function readinessHideSubjects(finding: PayrollRunReadinessFinding): HideSubject[] {
  const labels = new Map<number, string>()
  for (const entity of finding.entities) {
    if (entity.entity_id !== null && entity.label) labels.set(entity.entity_id, entity.label)
  }
  return (finding.subject_ids ?? []).map(id => ({
    id,
    label: labels.get(id) ?? subjectLabel(finding.subject_type ?? null, id),
  }))
}

const canHideWarnings = computed(() => auth.canWrite('payroll.approve'))
const pendingHide = ref<{ code: string, message: string, scope: 'people' | 'type', subjects: HideSubject[] } | null>(null)

function askHide(code: string, message: string, scope: 'people' | 'type', subjects: HideSubject[]): void {
  pendingHide.value = { code, message, scope, subjects }
}

async function onWarningHidden(): Promise<void> {
  pendingHide.value = null
  toast.success(t('payroll.runs.hide_warning_done'))
  await load()
}

/*
 * Po schválení jsou varování odsouhlasená: účetní je viděla při výpočtu a
 * běh schválila. V dalších krocích (zaúčtování, platby, uzavření) se proto
 * neukazují, jen nenápadný odkaz je rozbalí. Blokující chyby zůstávají.
 */
const WARNINGS_ACCEPTED_STATUSES = new Set(['approved', 'posted', 'payment_ready', 'paid', 'closed', 'cancelled'])
const calculationWarningsOpen = ref<Record<number, boolean>>({})

function isBlockingValidation(validation: PayrollRunValidation): boolean {
  return validation.severity === 'blocker'
    || (validation.requires_override && !validation.overridden_at)
}

function warningsAccepted(run: PayrollRun): boolean {
  return WARNINGS_ACCEPTED_STATUSES.has(run.status)
}

function calculationWarningCount(run: PayrollRun): number {
  return warningsAccepted(run) ? run.validations.filter(validation => !isBlockingValidation(validation)).length : 0
}

function sectionValidations(run: PayrollRun): PayrollRunValidation[] {
  return warningsAccepted(run) && !calculationWarningsOpen.value[run.id]
    ? run.validations.filter(isBlockingValidation)
    : run.validations
}

function toggleCalculationWarnings(run: PayrollRun): void {
  calculationWarningsOpen.value = { ...calculationWarningsOpen.value, [run.id]: !calculationWarningsOpen.value[run.id] }
}

/** Blokátor, nebo varování, které ještě čeká na výjimku. */
function blocksApproval(validation: DisplayValidation): boolean {
  if (validation.override_items.length) {
    return validation.override_items.some(item => item.validation.requires_override && !item.validation.overridden_at)
  }
  return validation.severity === 'blocker'
    || (validation.requires_override && !validation.overridden_at)
}

/**
 * `notListed` = kolik dotčených server do výčtu vůbec neposlal. Bez něj by
 * „a dalších" počítalo jen z ořezaného seznamu a u 225 lidí hlásilo 20.
 */
function entityLabelSummary(labels: string[], notListed = 0): string {
  const visible = labels.slice(0, 5).join(', ')
  const hidden = Math.max(0, labels.length - 5) + notListed
  if (hidden === 0) return visible
  return `${visible} · ${t('payroll.runs.validation.and_more', { count: hidden })}`
}

function validationKey(validation: DisplayValidation): number {
  return validation.id
}

function validationSearchText(validation: DisplayValidation): string {
  return [
    validation.display_message,
    ...validation.entity_labels,
    ...validation.remediation_links.map(link => link.label),
    ...validation.override_items.map(item => item.label),
  ].join(' ')
}

function overrideItemKey(item: OverrideItem): number {
  return item.validation.id
}

function overrideItemSearchText(item: OverrideItem): string {
  return `${item.label} ${item.validation.message}`
}

type RemediationLink = DisplayValidation['remediation_links'][number]

function remediationLinkKey(link: RemediationLink): string {
  return link.path
}

function remediationLinkSearchText(link: RemediationLink): string {
  return `${link.label} ${link.path}`
}

/** Stavy, ve kterých se s výjimkou ještě smí hýbat — po schválení běhu už ne. */
const OVERRIDE_EDITABLE_STATUSES: PayrollRun['status'][] = [
  'inputs_locked',
  'calculated',
  'reviewed',
  'reopened',
]

function overrideEditable(run: PayrollRun): boolean {
  return OVERRIDE_EDITABLE_STATUSES.includes(run.status)
}

/**
 * Stavy, od kterých má smysl ukazovat, co po běhu následuje.
 *
 * Zaúčtováním se čísla přestávají hýbat, takže od té chvíle je podání reálný
 * další krok. Dřív by rozcestník byl šum — účetní ještě počítá.
 */
const NEXT_STEP_STATUSES: PayrollRun['status'][] = [
  'posted',
  'payment_ready',
  'paid',
  'closed',
]

function showsNextSteps(run: PayrollRun): boolean {
  return NEXT_STEP_STATUSES.includes(run.status)
}

/**
 * Schválený běh a vše za ním: čísla jsou závazná, takže odkazy na pásky,
 * výplaty a podání za TOTÉŽ období mají smysl. Dřív schválený běh nabídl jen
 * „Zaúčtovat" a „Vyžádat opravu" a o páskách ani podáních mlčel.
 */
const AFTER_APPROVAL_STATUSES: PayrollRun['status'][] = [
  'approved',
  ...NEXT_STEP_STATUSES,
]

function showsAfterApprovalLinks(run: PayrollRun): boolean {
  return run.run_kind !== 'takeover' && AFTER_APPROVAL_STATUSES.includes(run.status)
}

/** Varování, na které se čeká: bez rozhodnutí člověka běh dál nepostoupí. */
function awaitsOverride(validation: PayrollRunValidation): boolean {
  return validation.requires_override && validation.overridden_at === null
}

function validationClass(validation: PayrollRunValidation): string {
  if (validation.severity === 'blocker') {
    return 'border-danger-500/30 bg-danger-50 text-danger-700'
  }
  if (validation.severity === 'info') {
    return 'border-neutral-200 bg-neutral-50 text-neutral-700'
  }
  return 'border-warning-200 bg-warning-50 text-warning-800'
}

function overrideAuthorLabel(validation: PayrollRunValidation): string {
  return t('payroll.runs.override.granted_by', {
    name: validation.overridden_by_name ?? t('payroll.runs.override.unknown_author'),
    at: formatDateTime(validation.overridden_at),
  })
}

/*
 * Nouzový termín, dokud server nepošle návrh ze sjednané mzdové politiky.
 *
 * Do W-fixu bylo tohle jediné, co formulář uměl: patnáctého následujícího
 * měsíce, natvrdo, bez ohledu na `payroll_employer_policies`. Firma se
 * sjednanou desátou výplatou tak zakládala běhy s termínem, který u ní
 * neplatí — a datum výplaty není kosmetika, visí na něm splatnost odvodů,
 * lhůty hlášení i mez podle § 141 odst. 1 zákoníku práce.
 */
function fallbackPaymentDate(value: string): string {
  const [year, month] = value.split('-').map(Number)
  const date = new Date(Date.UTC(year, month, 15))
  return date.toISOString().slice(0, 10)
}

function statusClass(status: PayrollRun['status']): string {
  if (status === 'approved' || status === 'closed' || status === 'paid') {
    return 'bg-success-50 text-success-600'
  }
  if (status === 'cancelled' || status === 'correction_pending') {
    return 'bg-warning-50 text-warning-600'
  }
  if (status === 'calculated' || status === 'reviewed') {
    return 'bg-payroll-50 text-payroll-600'
  }
  return 'bg-neutral-100 text-neutral-600'
}

/**
 * Jediná plná (primární) akce podle stavu — zbytek běhu je odbočka, ne
 * rovnocenná volba. Uživatel má v každém stavu vidět jedno „co teď".
 *
 * Cesta je záměrně čtyřkroková: Spočítat mzdy → (podívá se na čísla) →
 * Schválit → Zaúčtovat → Připravit platby. „Uzamknout vstupy" zmizelo do
 * prvního kroku (mezi zámkem a výpočtem se nic lidského nedělo) a
 * „Zkontrolovat" zmizelo do schválení — u jedné účetní to byl druhý podpis
 * téhož člověka. Oba příkazy na serveru dál existují.
 */
const PRIMARY_COMMAND: Partial<Record<PayrollRun['status'], PayrollRunCommand>> = {
  draft: 'lock_and_calculate',
  inputs_locked: 'calculate',
  reopened: 'calculate',
  calculated: 'approve',
  reviewed: 'approve',
  approved: 'post',
  posted: 'prepare_payments',
  payment_ready: 'close',
  paid: 'close',
  correction_pending: 'reopen',
  cancelled: 'reopen',
}

/**
 * Příkazy, které obrazovka umí nabídnout. `review` tu schválně NENÍ: stav
 * `reviewed` i příkaz zůstávají v datech a v API, jen se nenabízejí jako
 * samostatné tlačítko.
 *
 * `mark_paid` tu NENÍ ze stejného důvodu, ale ostřejšího: úhrada není
 * rozhodnutí účetní, je to fakt. Buď peníze odešly, nebo ne — a to server
 * pozná z platebního ledgeru sám a běh do stavu „Uhrazeno" překlopí bez
 * kliknutí. Server ho v `available_commands` už neposílá; seznam ho vynechává
 * i pro jistotu, aby se tlačítko nevrátilo starým payloadem.
 */
const KNOWN_COMMANDS: PayrollRunCommand[] = [
  'lock_and_calculate',
  'lock_inputs',
  'calculate',
  'refresh_inputs',
  'approve',
  'post',
  'prepare_payments',
  'request_correction',
  'reopen',
  'cancel',
  'close',
]

function commandLabel(command: PayrollRunCommand, run?: PayrollRun): string {
  if (command === 'reopen' && run?.status === 'cancelled') {
    return t('payroll.runs.commands.reopen_cancelled')
  }
  return t(`payroll.runs.commands.${command}`)
}

/**
 * Změnily-li se od zmrazení snímku podklady otevřené revize, je jediný
 * smysluplný další krok obnovit je: schválení by server odmítl a přepočet by
 * počítal ze starého snímku.
 */
function hasSourceDrift(run: PayrollRun): boolean {
  return (run.source_drift?.total ?? 0) > 0
}

function missingEmploymentKey(item: PayrollRunMissingEmployment): number {
  return item.employment_id
}

function missingEmploymentSearchText(item: PayrollRunMissingEmployment): string {
  return `${item.full_name} ${item.employment_code}`
}

function averageChangeKey(item: PayrollRunAverageChangeEmployment): number {
  return item.employment_id
}

function averageChangeSearchText(item: PayrollRunAverageChangeEmployment): string {
  return item.full_name
}

function averageChangeAverage(item: PayrollRunAverageChangeEmployment): string {
  if (!item.average_changed) return ''
  return item.current_average_hourly_minor === null
    ? t('payroll.runs.average_change.average_removed', { from: money(item.frozen_average_hourly_minor) })
    : t('payroll.runs.average_change.average', {
      from: money(item.frozen_average_hourly_minor),
      to: money(item.current_average_hourly_minor),
    })
}

function averageChangeCompensation(item: PayrollRunAverageChangeCompensation): string {
  return item.expected_amount_minor === null
    ? t('payroll.runs.average_change.compensation_unknown', { component: item.component_name, amount: money(item.amount_minor) })
    : t('payroll.runs.average_change.compensation', {
      component: item.component_name,
      amount: money(item.amount_minor),
      expected: money(item.expected_amount_minor),
    })
}

function averageChangeCommand(run: PayrollRun): PayrollRunCommand | null {
  const action = run.average_change?.action ?? null
  return action !== null && visibleCommands(run).includes(action) ? action : null
}

async function fixAverageChange(run: PayrollRun) {
  const command = averageChangeCommand(run)
  if (command === null) return
  if (command === 'request_correction') {
    pendingCommand.value = { run, command }
    commandReason.value = t('payroll.runs.average_change.reason')
    commandError.value = ''
    averageCorrectionRunId.value = run.id
    return
  }
  await submitCommand(run, command)
}

function primaryCommand(run: PayrollRun): PayrollRunCommand | undefined {
  if ((hasSourceDrift(run) || sourceBlockerSteps(run).length > 0)
    && run.available_commands.includes('refresh_inputs')
  ) {
    return 'refresh_inputs'
  }
  return PRIMARY_COMMAND[run.status]
}

function commandClass(run: PayrollRun, command: PayrollRunCommand): string {
  if (primaryCommand(run) === command) {
    return btnFilled(command === 'approve' ? 'success' : 'primary')
  }
  if (command === 'cancel') return btnOutline('danger')
  if (command === 'request_correction' || command === 'reopen') {
    return btnOutline('warning')
  }
  return btnOutline('neutral')
}

function commandIcon(command: PayrollRunCommand): string {
  if (command === 'lock_inputs' || command === 'refresh_inputs') return ICONS.lock
  if (command === 'calculate' || command === 'lock_and_calculate') return ICONS.cycle
  if (command === 'post') return ICONS.doc
  if (command === 'prepare_payments') return ICONS.coin
  if (command === 'review' || command === 'approve' || command === 'close') {
    return ICONS.check
  }
  if (command === 'cancel') return ICONS.x
  return ICONS.uturn
}

/**
 * Věta „co se teď stane" k primární akci. Dřív visela VEDLE tlačítek a řádek
 * s akcemi kvůli ní přetékal; patří pod ně, kde ji jde přečíst.
 */
const COMMAND_HINTS: PayrollRunCommand[] = [
  'lock_and_calculate',
  'calculate',
  'refresh_inputs',
  'approve',
  'post',
  'prepare_payments',
  'close',
  'reopen',
]

/**
 * Věta o stavu úhrady. Není to výzva k akci — jen fakt: kolik závazků je
 * doložených bankou nebo pokladnou a kolik čeká na výpis. Běh se do stavu
 * „Uhrazeno" překlopí sám, jakmile poslední závazek dosedne.
 */
function coverageLabel(run: PayrollRun): string {
  const coverage = run.payment_coverage
  if (!coverage) return ''
  if (coverage.uncovered_count === 0) {
    return t('payroll.runs.coverage.settled', {
      count: coverage.liability_count,
    })
  }
  return t('payroll.runs.coverage.waiting', {
    settled: coverage.settled_count,
    total: coverage.liability_count,
    amount: money(coverage.uncovered_minor),
  })
}

function commandHint(run: PayrollRun): string {
  const primary = primaryCommand(run)
  if (!primary || !COMMAND_HINTS.includes(primary)) return ''
  if (!visibleCommands(run).includes(primary)) return ''
  return t(`payroll.runs.command_hint.${primary}`)
}

const SOURCE_DRIFT_KEYS = [
  'worked_time_changed',
  'remuneration_changed',
  'inputs_added',
  'inputs_changed',
  'inputs_removed',
  'absences_added',
  'absences_changed',
  'absences_removed',
  'employments_added',
  'employments_removed',
  'statutory_evidence_changed',
  'employment_terms_changed',
  'average_earnings_changed',
] as const

/** Jen nenulové druhy změn — u 500 lidí nechceme číst sloupec nul. */
function sourceDriftLines(run: PayrollRun): { key: string, text: string }[] {
  const drift = run.source_drift
  if (!drift) return []
  return SOURCE_DRIFT_KEYS
    .filter(key => (drift[key] ?? 0) > 0)
    .map(key => ({ key, text: t(`payroll.runs.source_drift.${key}`, { count: drift[key] }) }))
}

function visibleCommands(run: PayrollRun): PayrollRunCommand[] {
  // Sloučený krok a samostatné „Uzamknout vstupy" jsou tatáž práce. Nabízet
  // obojí vedle sebe by účetní jen postavilo před volbu, kterou nemá jak
  // rozhodnout — samostatný zámek zůstává v API pro opravné revize.
  const combined = run.available_commands.includes('lock_and_calculate')
  return run.available_commands.filter(command => {
    if (!KNOWN_COMMANDS.includes(command)) return false
    if (combined && command === 'lock_inputs') return false
    if (command === 'lock_and_calculate') {
      return canWrite.value && auth.canWrite('payroll.calculate')
    }
    if (command === 'calculate') return auth.canWrite('payroll.calculate')
    // Obnova podkladů zamyká nově schválené vstupy — stejné dvě brány jako
    // sloučený krok „Spočítat mzdy".
    if (command === 'refresh_inputs') {
      return canWrite.value && auth.canWrite('payroll.calculate')
    }
    if (command === 'request_correction') return auth.canWrite('payroll.review')
    if (command === 'approve') return auth.canWrite('payroll.approve')
    if (command === 'reopen') return auth.canWrite('payroll.reopen')
    if (command === 'post') return auth.canWrite('payroll.post')
    if (command === 'prepare_payments') return auth.canWrite('payroll.payments')
    return canWrite.value
  }).sort((a, b) => commandWeight(run, a) - commandWeight(run, b))
}

/*
 * ─── Jak pokračovat, když běh drží neschválené podklady ──────────────────────
 *
 * Neschválená docházka, rozpracované vstupy nebo chybějící mzdová složka se
 * opravují MIMO běh a do běhu se dostanou obnovením podkladů. Dřív karta
 * ukázala jen červený seznam a text „otevřete novou revizi"; účetní běh
 * zrušila, znovu otevřela (dvakrát psala důvod) a stála tam, kde předtím.
 * Každý krok tu má proto vlastní tlačítko a poslední krok běh přepočítá.
 */
type SourceStep = 'time' | 'inputs' | 'components' | 'statutory'

const SOURCE_STEP_ORDER: SourceStep[] = ['time', 'inputs', 'components', 'statutory']

const SOURCE_STEP_CODES: Record<string, SourceStep> = {
  time_month_not_approved: 'time',
  time_month_missing: 'time',
  draft_inputs_present: 'inputs',
  employment_without_inputs: 'components',
}

/*
 * Ruční kontrolu zákonného výpočtu způsobí chybějící mzdová složka i chybějící
 * zákonná evidence (pojišťovna, rezidence). Krok se proto bere z místa opravy
 * nálezu: mzdové vstupy vedou na „Doplňte mzdu", ostatní na zákonnou evidenci.
 * Dřív radila karta „zadejte 0 Kč" i lidem, kterým chyběla jen pojišťovna.
 */
function sourceStepFor(code: string, remediationPaths: Array<string | null | undefined>): SourceStep[] {
  if (code !== 'statutory_calculation_manual_review') {
    const step = SOURCE_STEP_CODES[code]
    return step ? [step] : []
  }
  const steps = new Set<SourceStep>()
  for (const path of remediationPaths) {
    steps.add(path?.startsWith('/payroll/quick-inputs') ? 'components' : 'statutory')
  }
  return steps.size === 0 ? ['statutory'] : [...steps]
}

function orderedSourceSteps(steps: Set<SourceStep>): SourceStep[] {
  return SOURCE_STEP_ORDER.filter(step => steps.has(step))
}

const SOURCE_STEP_STATUSES = new Set<PayrollRun['status']>([
  'inputs_locked',
  'reopened',
  'calculated',
  'reviewed',
  'cancelled',
])

function sourceBlockerSteps(run: PayrollRun): SourceStep[] {
  if (!SOURCE_STEP_STATUSES.has(run.status)) return []
  const steps = new Set<SourceStep>()
  for (const validation of run.validations) {
    if (validation.severity !== 'blocker') continue
    for (const step of sourceStepFor(validation.code, [validation.remediation_path])) steps.add(step)
  }
  return orderedSourceSteps(steps)
}

/** Krok, který opravené podklady do běhu přenese — a hned přepočítá. */
function sourceRefreshCommand(run: PayrollRun): PayrollRunCommand | null {
  if (run.available_commands.includes('refresh_inputs')) return 'refresh_inputs'
  if (run.status === 'cancelled' && run.available_commands.includes('reopen')) return 'reopen'
  return null
}

function runPeriod(run: PayrollRun): string {
  return run.period_start.slice(0, 7)
}

/*
 * Co brání schválení běhu, spočítané z validací, které karta už má.
 *
 * Schválení s blokátorem server odmítne — jenže až po půlminutě práce a jen
 * větou „obsahuje blokující validace", bez seznamu. Tlačítko proto čeká,
 * dokud jsou blokátory nebo varování bez výjimky, a místo zbytečného pokusu
 * říká kolik jich je a posune na jejich seznam.
 */
function approveBlockerCount(run: PayrollRun): number {
  return run.validations.filter(validation => validation.severity === 'blocker'
    || (validation.requires_override && !validation.overridden_at)).length
}

function commandDisabled(run: PayrollRun, command: PayrollRunCommand): boolean {
  return command === 'approve' && approveBlockerCount(run) > 0
}

/*
 * Kontroly běhu: blokující zvlášť od varování (Q15-8). Při blokátorech se
 * karta otevírá jen s nimi — 19 blokujících se jinak ztratilo pod 187
 * varováními na osmi stránkách. Varování jsou o klik dál, seskupená po kódu.
 */
const validationScope = ref<Record<number, 'blocking' | 'all'>>({})

function blockingGroupCount(run: PayrollRun): number {
  return validationGroups(sectionValidations(run), run.period_start).filter(blocksApproval).length
}

function currentValidationScope(run: PayrollRun): 'blocking' | 'all' {
  return validationScope.value[run.id]
    ?? (approveBlockerCount(run) > 0 ? 'blocking' : 'all')
}

function visibleValidationGroups(run: PayrollRun): DisplayValidation[] {
  const groups = validationGroups(sectionValidations(run), run.period_start)
  return currentValidationScope(run) === 'blocking' ? groups.filter(blocksApproval) : groups
}

function setValidationScope(run: PayrollRun, scope: 'blocking' | 'all'): void {
  validationScope.value = { ...validationScope.value, [run.id]: scope }
}

function showValidations(run: PayrollRun): void {
  setValidationScope(run, 'blocking')
  document.querySelector(`[data-testid="payroll-run-${run.id}-validations-section"]`)
    ?.scrollIntoView({ behavior: 'smooth', block: 'start' })
}

/** Primární akce vlevo, zrušení běhu vždy až úplně vpravo. */
function commandWeight(run: PayrollRun, command: PayrollRunCommand): number {
  if (primaryCommand(run) === command) return 0
  if (command === 'cancel') return 2
  return 1
}

/*
 * Proč nejde založit běh. Období i datum výplaty jsou povinné vstupy formuláře
 * hned vedle tlačítka — bez věty ale nebylo poznat, které z nich chybí.
 */
const createBlockedReason = computed<string | null>(() => {
  if (!period.value) return t('payroll.runs.create_blocked_period')
  if (!paymentDate.value) return t('payroll.runs.create_blocked_payment_date')
  /*
   * Za období smí být jeden běh. Server to hlídá (`createOrGet`), ale s JINÝM
   * datem výplaty vrátí 422 do toastu — a obrazovka přitom existující běh zná
   * a jeho datum má po ruce. Tlačítko tedy říká rovnou, že běh už je, místo aby
   * poslalo požadavek, o kterém se ví, že neprojde.
   */
  if (periodRun.value !== null) {
    return t('payroll.runs.create_blocked_exists', {
      status: t(`payroll.runs.status.${periodRun.value.status}`),
    })
  }
  return null
})

/*
 * Datum výplaty se drží běhu, který za období existuje. Bez toho ukazovalo
 * výchozí patnáctého, i když běh měl jiné, a účetní z pole četla něco, co
 * neplatí.
 */
watch(periodRun, (run) => {
  if (run !== null) paymentDate.value = run.payment_date
})

/*
 * Pořadí načtení. Kontrola před během počítá u větší firmy desítky sekund,
 * takže odpověď za dříve zvolené období (výchozí měsíc při otevření stránky)
 * mohla dorazit až po přepnutí měsíce a po příkazu nad během a přepsat ho:
 * pod lednem pak visela kontrola a karta února a chybělo „Otevřít opravu".
 * Odpověď, kterou předběhlo novější načtení, se proto zahodí.
 */
let loadSequence = 0

/**
 * `silent` = tichá obnova okna po změně jinde (hromadná akce v jiném okně):
 * bez skeletonu a bez toastu, rozbalené osoby a historie zůstanou otevřené.
 * Chyba se vyhodí, obnova pak počká na další příležitost.
 */
async function load(options?: { silent?: boolean }) {
  const silent = options?.silent === true
  const sequence = ++loadSequence
  const requestedPeriod = period.value
  if (!silent) {
    loading.value = true
    loadFailed.value = false
  }
  const openHistories = silent
    ? Object.keys(historyOpen.value).map(Number).filter(id => historyOpen.value[id])
    : []
  try {
    const [page, people] = await Promise.all([
      payrollApi.runsPage(requestedPeriod, { limit: pageSize, offset: offset.value }),
      personNamesLoaded ? Promise.resolve(null) : payrollApi.peopleOptions().catch(() => null),
    ])
    if (sequence !== loadSequence || requestedPeriod !== period.value) return
    loadFailed.value = false
    runs.value = page.runs
    total.value = page.total
    suggestedPaymentDate.value = page.suggested_payment_date ?? null
    readiness.value = page.readiness ?? null
    missingPreviousPeriods.value = page.missing_previous_periods ?? []
    advanceStartTo.value = page.advance_start_to ?? null
    // Termín ze sjednané politiky se do formuláře propíše, jen dokud za období
    // žádný běh není a uživatel datum sám nepřepsal — existující běh si svoje
    // datum drží (viz `watch(periodRun)`).
    if (
      suggestedPaymentDate.value !== null
      && page.runs.length === 0
      && !paymentDateTouched.value
    ) {
      paymentDate.value = suggestedPaymentDate.value
    }
    // Otevřený přehled osob zůstává; při změně revize se přenačte sám.
    breakdownOpen.value = Object.fromEntries(
      Object.entries(breakdownOpen.value)
        .filter(([id]) => page.runs.some(run => String(run.id) === id && run.result_snapshot)),
    )
    histories.value = {}
    historyOpen.value = {}
    historyFailed.value = {}
    if (people !== null) {
      personNames.value = Object.fromEntries(
        people.map(person => [person.id, person.full_name]),
      )
      personNamesLoaded = true
    }
    // Nález jmenuje osobu, kterou číselník ještě nezná (založená mezitím
    // jinde): příští načtení si jména stáhne znovu.
    if (personNamesLoaded && page.runs.some(run => run.validations.some(validation =>
      validation.entity_type === 'employee'
      && validation.entity_id !== null
      && personNames.value[validation.entity_id] === undefined))
    ) {
      personNamesLoaded = false
    }
    const reopened = openHistories.filter(id => page.runs.some(item => item.id === id))
    if (reopened.length > 0) {
      historyOpen.value = Object.fromEntries(reopened.map(id => [id, true]))
      await Promise.all(reopened.map(id => loadHistory(id)))
    }
  } catch (error) {
    if (silent) throw error
    if (sequence !== loadSequence) return
    // Seznam běhů se nechává být: „za období nebyl spuštěn žádný běh" je
    // závěr, na který po výpadku sítě nemáme právo.
    readiness.value = null
    loadFailed.value = true
    toast.error(t('payroll.runs.load_failed'))
  } finally {
    if (!silent && sequence === loadSequence) loading.value = false
  }
}

// Stránkuje sdílená `PaginationBar` (číslo stránky od jedné); server zná offset.
function goToPage(nextPage: number) {
  offset.value = Math.max(0, (nextPage - 1) * pageSize)
  void load()
}

/** Rozbalí nebo schová přehled osob jednoho běhu. */
function toggleBreakdown(run: PayrollRun) {
  if (breakdownOpen.value[run.id]) {
    const { [run.id]: _removed, ...rest } = breakdownOpen.value
    breakdownOpen.value = rest
    return
  }
  breakdownOpen.value = { ...breakdownOpen.value, [run.id]: true }
}

const HISTORY_TOTAL_KEYS: PayrollRunHistoryTotalKey[] = [
  'cash_payable_minor',
  'enforcement_withheld_minor',
  'payable_after_enforcement_minor',
]

function historyTotalDiffs(revision: PayrollRunRevisionHistory): Array<{
  key: PayrollRunHistoryTotalKey
  diff: PayrollRunHistoryTotalDiff
}> {
  if (revision.diff_from_previous === null) return []
  return HISTORY_TOTAL_KEYS.flatMap((key) => {
    const diff = revision.diff_from_previous?.totals[key]
    return diff === undefined ? [] : [{ key, diff }]
  })
}

function historyEventLabel(event: PayrollRunHistoryEvent): string {
  const known = new Set([
    'created',
    ...KNOWN_COMMANDS,
    'validation_override',
    'validation_override_revoked',
  ])
  return known.has(event.event_type)
    ? t(`payroll.runs.history.event.${event.event_type}`)
    : t('payroll.runs.history.event.unknown')
}

async function loadHistory(runId: number) {
  historyLoading.value = { ...historyLoading.value, [runId]: true }
  historyFailed.value = { ...historyFailed.value, [runId]: false }
  try {
    const history = await payrollApi.runHistory(runId)
    histories.value = { ...histories.value, [runId]: history }
  } catch {
    historyFailed.value = { ...historyFailed.value, [runId]: true }
  } finally {
    const { [runId]: _pending, ...rest } = historyLoading.value
    historyLoading.value = rest
  }
}

async function toggleHistory(run: PayrollRun) {
  const opening = !historyOpen.value[run.id]
  historyOpen.value = { ...historyOpen.value, [run.id]: opening }
  if (opening && histories.value[run.id] === undefined) {
    await loadHistory(run.id)
  }
}

async function createRun() {
  if (!canWrite.value) return
  saving.value = true
  try {
    const created = await payrollApi.createRun({
      period_start: `${period.value}-01`,
      payment_date: paymentDate.value,
      office_id: null,
    })
    toast.success(t('payroll.runs.created'))
    // Varování ze serveru se ukazuje po úspěchu, ne místo něj: běh vznikl,
    // jen o něm účetní musí něco vědět. Mlčky ho spolknout by znamenalo, že
    // se na duplicitní měsíc přijde až u roční uzávěrky.
    for (const warning of created.warnings ?? []) toast.warning(warning.message)
    await load()
  } catch (error: any) {
    showPayrollError(error, t('payroll.runs.save_failed'))
  } finally {
    saving.value = false
  }
}

/**
 * Po konfliktu verzí přepnout otevřený dialog na čerstvý běh.
 *
 * `load()` sice natáhne nové `row_version`, ale dialog si drží běh zachycený
 * při otevření. Uživatel proto po hlášce „mezitím to někdo změnil" tiskl totéž
 * tlačítko se STEJNOU starou verzí a dostával tutéž chybu donekonečna. Napsaný
 * důvod přitom zůstává — přenačte se jen zámek, ne rozdělaná práce.
 *
 * Vrací `null`, když běh mezitím zmizel: pak už dialog nemá co odeslat.
 */
function reloadedRun(run: PayrollRun): PayrollRun | null {
  return runs.value.find(candidate => candidate.id === run.id) ?? null
}

/**
 * Důvod se píše jen u opravy schváleného běhu: u vyžádání opravy a u zrušení
 * opravné revize, protože ta stojí nad schváleným (zaúčtovaným, uzavřeným)
 * výsledkem. Server to hlídá stejně. Zrušení neschváleného běhu vrací
 * „Začít znovu", takže stačí jedno potvrzení bez psaní; samotné „Začít
 * znovu" je návrat a dialog nemá. Dřív se obrazovka ptala na důvod dvakrát
 * za sebou a účetní psala „xxx", aby mohla pokračovat.
 */
function commandNeedsReason(run: PayrollRun, command: PayrollRunCommand): boolean {
  return command === 'request_correction'
    || (command === 'cancel' && run.revision_kind === 'correction')
}

async function runCommand(run: PayrollRun, command: PayrollRunCommand) {
  averageCorrectionRunId.value = null
  if (command === 'request_correction' || command === 'cancel') {
    pendingCommand.value = { run, command }
    commandReason.value = ''
    commandError.value = ''
    return
  }
  /*
   * Kontrola PŘED zahájením. Zámek vstupů je jediný nevratný krok, po kterém
   * se zmrazí snímek — právě tam má účetní vidět, co kontrola našla, a teprve
   * pak se rozhodnout. NEBLOKUJEME: dialog má tlačítko „Přesto zahájit",
   * protože nálezy jsou často věci, o kterých se ví a přesto se počítá.
   * Prázdný nález = žádný dialog, klik projde rovnou.
   */
  if (startsTheRun(command) && readinessFindings.value.length > 0) {
    pendingStart.value = { run, command }
    return
  }
  /*
   * Schválení je závazné a nevratné jedním kliknutím: uzamkne výsledek, založí
   * pásky a uzavře historii evidence. Dialog řekne, co se uzamkne, dřív než
   * se to stane; zpět pak vede jen oprava běhu.
   */
  if (command === 'approve') {
    pendingApprove.value = run
    return
  }
  await submitCommand(run, command)
}

/** Co schválení uzamkne; pořadí je pořadí vět v potvrzení. */
const APPROVE_LOCK_KEYS = ['result', 'evidence', 'changes', 'followup'] as const

async function confirmApprove() {
  const run = pendingApprove.value
  if (run === null) return
  pendingApprove.value = null
  await submitCommand(run, 'approve')
}

/**
 * Stojí období běhu vůči dnešku: `upcoming` ještě nezačalo, `running` běží.
 * Jen kalendář pro text potvrzení; nález s důsledky posílá kontrola před během.
 */
function periodTiming(run: PayrollRun): 'upcoming' | 'running' | 'finished' {
  const today = appIsoDate()
  if (run.period_start > today) return 'upcoming'
  return periodEndDate(run) >= today ? 'running' : 'finished'
}

function periodEndDate(run: PayrollRun): string {
  const [year, month] = run.period_start.split('-').map(Number)
  const lastDay = new Date(Date.UTC(year, month, 0)).getUTCDate()
  return `${run.period_start.slice(0, 8)}${String(lastDay).padStart(2, '0')}`
}

/** Kroky, po kterých se snímek vstupů zmrazí — jen před nimi má kontrola smysl. */
function startsTheRun(command: PayrollRunCommand): boolean {
  return command === 'lock_and_calculate' || command === 'lock_inputs'
}

async function confirmStart() {
  const pending = pendingStart.value
  if (pending === null) return
  pendingStart.value = null
  await submitCommand(pending.run, pending.command)
}

/**
 * Znovu se zeptat serveru, jestli nálezy pořád platí. Účetní typicky odejde
 * vadu opravit do jiné agendy a vrátí se; bez tohohle by musela přenačíst
 * celou stránku, aby se dialog přestal ptát na něco, co už vyřešila.
 */
async function recheckReadiness() {
  const pending = pendingStart.value
  await load()
  if (pending === null) return
  if (readinessFindings.value.length === 0) {
    pendingStart.value = null
    toast.success(t('payroll.runs.readiness.all_clear'))
    return
  }
  const fresh = reloadedRun(pending.run)
  pendingStart.value = fresh === null ? null : { run: fresh, command: pending.command }
}

/**
 * Jména konkrétních věcí, kterých se nález týká.
 *
 * Server posílá `label` u všech typů (mzdová složka s kódem, zaměstnanec,
 * instituce) — nález MUSÍ jmenovat, čeho se týká, jinak posílá účetní hádat.
 * Číselník osob je záloha pro nálezy, které label nenesou; jen u `employee`,
 * protože ID pracovního vztahu do něj nepatří a náhodná shoda čísel by
 * k nálezu přilepila cizí jméno.
 */
function findingEntityLabels(finding: PayrollRunReadinessFinding): string[] {
  return Array.from(new Set(finding.entities.flatMap((entity) => {
    if (entity.label) return [entity.label]
    if (entity.entity_type !== 'employee' || entity.entity_id === null) return []
    const name = personNames.value[entity.entity_id]
    return name ? [name] : []
  })))
}

type ReadinessEntity = PayrollRunReadinessFinding['entities'][number]

/*
 * Hromadná oprava z nálezu otevře TENTÝŽ dialog jako Mzdy → Zaměstnanci →
 * Další funkce (`?bulk=`), s obdobím kontrolovaného běhu. Nic se tu
 * nepřepočítává ani nezapisuje.
 */
const READINESS_BULK_ICONS: Record<PayrollRunReadinessBulkKind, string> = {
  statutory_defaults: ICONS.clipboardCheck,
  declaration_signed: ICONS.check,
  health_insurer: ICONS.clipboardCheck,
  workplace: ICONS.pin,
  closure: ICONS.archive,
  annual_settlement_request: ICONS.check,
}

function readinessBulkPeriod(): string {
  return readiness.value?.period_start?.slice(0, 7) ?? period.value
}

function readinessBulkTarget(action: { kind: PayrollRunReadinessBulkKind, employee_ids?: number[] }) {
  const kind = action.kind
  const bulkPeriod = readinessBulkPeriod()
  if (kind === 'annual_settlement_request') {
    // Hlášení za leden a únor se ptá na žádost za MINULÝ rok. Hromadné
    // „nepožádal" je výběr na stránce Ročního zúčtování, zúžený na nerozhodnuté.
    const taxYear = Number(bulkPeriod.slice(0, 4)) - 1
    return { name: 'payroll-annual-settlement', query: { year: String(taxYear), state: 'request_undecided', select: 'all' } }
  }
  // Nález nese osoby, kterých se týká — dialog se jimi zúží, ne na celý rok.
  const employees = action.employee_ids?.length ? { employees: action.employee_ids.join(',') } : {}
  return { name: 'payroll-people', query: { bulk: kind, period: bulkPeriod, ...employees } }
}

function readinessEntityKey(entity: ReadinessEntity, index: number): string {
  return `${entity.entity_type}-${entity.entity_id}-${index}`
}

function readinessEntitySearchText(entity: ReadinessEntity): string {
  const name = entity.entity_type === 'employee' && entity.entity_id !== null
    ? personNames.value[entity.entity_id] ?? ''
    : ''
  return [entity.label ?? '', name, entity.message ?? ''].join(' ')
}

function readinessFindingMessage(finding: PayrollRunReadinessFinding): string {
  return finding.code === 'payout_rule_missing'
    ? t('payroll.runs.readiness.payout_rule_missing')
    : finding.message
}

/** Skutečný počet dotčených — `entities` server ořezává, `entity_total` ne. */
function findingCount(finding: PayrollRunReadinessFinding): number {
  return Math.max(finding.count, finding.entity_total ?? 0)
}

/** Kolik dotčených se do ořezaného výčtu ze serveru nevešlo. */
function findingNotListed(finding: PayrollRunReadinessFinding): number {
  return Math.max(0, (finding.entity_total ?? finding.entities.length) - finding.entities.length)
}

function findingClass(finding: PayrollRunReadinessFinding): string {
  if (finding.impact === 'blocking' || finding.impact === 'approval') {
    return 'border-danger-500/30 bg-danger-50 text-danger-700'
  }

  return finding.impact === 'revision'
    ? 'border-warning-200 bg-warning-50 text-warning-800'
    : 'border-neutral-200 bg-neutral-50 text-neutral-700'
}

/**
 * Nálezy rozdělené podle toho, CO ZNAMENAJÍ — ne podle toho, jak vážně znějí.
 *
 * Účetní potřebuje odlišit tři věci: co ji zastaví, co půjde opravit jen za
 * cenu opravné revize, a co se doplní kdykoli. Bez toho vypadá chybějící
 * identifikátor od ČSSZ stejně naléhavě jako chybějící mzdová politika.
 */
const readinessGroups = computed(() => ([
  { impact: 'blocking' as const, findings: readinessFindings.value.filter(f => f.impact === 'blocking') },
  { impact: 'approval' as const, findings: readinessFindings.value.filter(f => f.impact === 'approval') },
  { impact: 'revision' as const, findings: readinessFindings.value.filter(f => f.impact === 'revision') },
  { impact: 'anytime' as const, findings: readinessFindings.value.filter(f => f.impact === 'anytime') },
]).filter(group => group.findings.length > 0))

/**
 * Co z předběžné kontroly zablokuje schválení — stejné kroky, jaké karta
 * běhu nabízí po výpočtu. Vyřešit je PŘED zahájením je levnější: odpadne
 * obnova podkladů i přepočet.
 */
const readinessSourceSteps = computed<SourceStep[]>(() => {
  const steps = new Set<SourceStep>()
  for (const finding of readinessFindings.value) {
    if (finding.impact !== 'approval') continue
    const paths = finding.entities.length > 0
      ? finding.entities.map(entity => entity.remediation_path)
      : [finding.remediation_path]
    for (const step of sourceStepFor(finding.code, paths)) steps.add(step)
  }
  return orderedSourceSteps(steps)
})

async function approveDraftInputsBeforeStart(run: PayrollRun) {
  await approveDraftInputs(run)
  await recheckReadiness()
}

async function submitCommand(
  run: PayrollRun,
  command: PayrollRunCommand,
  reason?: string,
) {
  saving.value = true
  runningCommand.value = { runId: run.id, command }
  delete commandBlockers.value[run.id]
  try {
    const response = await payrollApi.commandRun(
      run.id,
      command,
      { row_version: run.row_version, ...(reason ? { reason } : {}) },
      crypto.randomUUID(),
    )
    const outcome = response.outcome?.outcome ?? null
    toast.success(
      outcome === null
        ? t('payroll.runs.command_done')
        : t(`payroll.runs.outcome.${outcome}`),
    )
    pendingCommand.value = null
    commandReason.value = ''
    commandError.value = ''
    await load()
    if (command === 'request_correction' && averageCorrectionRunId.value === run.id) {
      averageCorrectionRunId.value = null
      const fresh = reloadedRun(run)
      if (fresh !== null && fresh.available_commands.includes('reopen') && auth.canWrite('payroll.reopen')) {
        await submitCommand(fresh, 'reopen')
        return
      }
    }
    /*
     * Obnovené podklady nebo nová revize nemají výsledek a jediný smysluplný
     * další krok je přepočet. Udělá se rovnou, ať účetní nemačká dvě tlačítka
     * za sebou a neplete si „obnoveno" se „spočítáno".
     */
    if (command === 'refresh_inputs' || command === 'reopen') {
      const fresh = reloadedRun(run)
      if (fresh !== null && fresh.available_commands.includes('calculate')
        && auth.canWrite('payroll.calculate')
      ) {
        await submitCommand(fresh, 'calculate')
        return
      }
    }
    if (command === 'prepare_payments'
      && outcome !== 'payments_not_applicable'
    ) {
      void router.push({
        name: 'payroll-payments',
        query: {
          period: run.period_start.slice(0, 7),
          run: String(run.id),
          focus: 'bank-order',
        },
      })
    }
  } catch (error: any) {
    const failure = error?.response?.data?.error
    const message = failure?.message || t('payroll.runs.command_failed')
    if (pendingCommand.value) commandError.value = message
    // Blokující důvod u zaúčtování a plateb je celá věta („komu chybí výplatní
    // pravidlo", „kolik zbývá uhradit"). V toastu se ztratí dřív, než se podle
    // ní dá jednat — proto zůstane viset u konkrétního běhu.
    else if (['post', 'prepare_payments'].includes(command)) {
      commandBlockers.value = { ...commandBlockers.value, [run.id]: message }
    } else toast.error(message)
    if (error?.response?.status === 409) {
      await load()
      const pending = pendingCommand.value
      if (pending !== null) {
        const fresh = reloadedRun(pending.run)
        if (fresh === null) {
          toast.error(message)
          pendingCommand.value = null
        } else {
          pendingCommand.value = { run: fresh, command: pending.command }
        }
      }
    }
  } finally {
    saving.value = false
    runningCommand.value = null
  }
}

function dismissBlocker(runId: number) {
  const next = { ...commandBlockers.value }
  delete next[runId]
  commandBlockers.value = next
}

async function confirmCommand() {
  if (!pendingCommand.value) return
  const reason = commandReason.value.trim()
  if (!reason && commandNeedsReason(pendingCommand.value.run, pendingCommand.value.command)) {
    commandError.value = t('payroll.runs.reason_required')
    return
  }
  await submitCommand(
    pendingCommand.value.run,
    pendingCommand.value.command,
    reason || undefined,
  )
}

function groupedFailures(
  failures: Array<{ id: number, code: string, message: string }>,
): DraftInputFailure[] {
  const counts = new Map<string, number>()
  for (const failure of failures) {
    counts.set(failure.message, (counts.get(failure.message) ?? 0) + 1)
  }
  return Array.from(counts, ([message, count]) => ({ message, count }))
}

function dismissDraftInputFailures(runId: number): void {
  const next = { ...draftInputFailures.value }
  delete next[runId]
  draftInputFailures.value = next
}

/**
 * Schválit rovnou z obrazovky běhu všechny mzdové vstupy, které ho drží.
 *
 * Blokátor `draft_inputs_present` dosud jen odkázal na jinou stránku, kde se
 * schvalovalo řádek po řádku — u 500 zaměstnanců zhruba tisíc kliknutí. Odkaz
 * zůstává (koncept může být potřeba nejdřív opravit), tohle je zkratka pro
 * případ, kdy je vstupů jen moc.
 */
async function approveDraftInputs(run: PayrollRun) {
  if (!canOverride.value) return
  saving.value = true
  try {
    // Filtrem, ne výčtem: server projde VŠECHNY koncepty měsíce po dávkách.
    // Dřív schválil nejvýš 500 a zbytek zůstal viset bez hlášky.
    const period = run.period_start.slice(0, 7)
    const result = await runPayrollInputBatch(async (afterId) => {
      const pass = await payrollApi.approveInputsBatch({
        period,
        filter: { status: 'draft' },
        after_id: afterId,
      })
      return { ...pass, done: pass.approved }
    })
    if (result.failed.length > 0) {
      // Toast nesl důvod PRVNÍHO neúspěchu a za pár vteřin zmizel; zbylých
      // devatenáct se uživatel nedozvěděl vůbec. Důvody proto zůstávají u běhu,
      // seskupené po větě — u pěti set vstupů jich bývá pár, ne pět set.
      draftInputFailures.value = {
        ...draftInputFailures.value,
        [run.id]: groupedFailures(result.failed),
      }
      toast.error(t('payroll.runs.validation.draft_inputs_approve_partial', {
        approved: result.done,
        failed: result.failed.length,
      }))
    } else {
      dismissDraftInputFailures(run.id)
      toast.success(t('payroll.runs.validation.draft_inputs_approved', {
        count: result.done,
      }))
    }
    await load()
  } catch (error: any) {
    toast.error(apiErrorMessage(
      error,
      t('payroll.runs.validation.draft_inputs_approve_failed'),
    ))
  } finally {
    saving.value = false
  }
}

/*
 * Hromadné doplnění výchozí zákonné evidence přímo z běhu. Blokace
 * `statutory_calculation_manual_review` u běhu z importu obvykle znamená, že
 * stovky lidí nemají rezidenci, příslušnost ani slevu důchodce — a jediná
 * cesta byla karta osoby, jedna po druhé.
 */
const canBulkDefaults = computed(() => auth.canWrite('payroll.person.write'))
const bulkDefaultsRun = ref<PayrollRun | null>(null)
const bulkDefaultsApplied = ref(false)

function openBulkDefaults(run: PayrollRun) {
  if (!canBulkDefaults.value) return
  bulkDefaultsApplied.value = false
  bulkDefaultsRun.value = run
}

function bulkDefaultsLabel(run: PayrollRun): string {
  const count = statutoryReviewEmployeeIds(run.validations).length
  return count > 0
    ? t('payroll.runs.validation.statutory_bulk_action', { count })
    : t('payroll.runs.validation.statutory_bulk_action_plain')
}

async function onBulkDefaultsApplied(result: PayrollStatutoryBulkResult) {
  bulkDefaultsApplied.value = result.counts.applied > 0
  await load()
  const current = bulkDefaultsRun.value
  if (current !== null) bulkDefaultsRun.value = reloadedRun(current) ?? current
}

/**
 * Jediný další krok, který čerstvou evidenci do běhu opravdu dostane (nový
 * snímek vstupů) — jen existující příkaz, na který má uživatel právo.
 */
const bulkDefaultsRefreshCommand = computed<PayrollRunCommand | null>(() => {
  const run = bulkDefaultsRun.value
  if (run === null || !bulkDefaultsApplied.value) return null
  const command = evidenceRefreshCommand(run)
  return command !== null && visibleCommands(run).includes(command) ? command : null
})

function continueAfterBulkDefaults() {
  const run = bulkDefaultsRun.value
  const command = bulkDefaultsRefreshCommand.value
  bulkDefaultsRun.value = null
  if (run !== null && command !== null) void runCommand(run, command)
}

function askOverride(run: PayrollRun, validation: PayrollRunValidation) {
  if (!canOverride.value || !overrideEditable(run)) return
  pendingOverride.value = { run, validation }
  overrideReason.value = ''
  overrideError.value = ''
}

/** Jeden dialog s důvodem pro celou skupinu místo stovek jednotlivých. */
function askBulkOverride(run: PayrollRun, group: DisplayValidation) {
  if (!canOverride.value || !overrideEditable(run)) return
  pendingOverride.value = { run, validation: group, group }
  overrideReason.value = ''
  overrideError.value = ''
}

function pendingGroupIds(group: DisplayValidation): number[] {
  return group.override_items
    .filter(item => awaitsOverride(item.validation))
    .map(item => item.validation.id)
}

async function confirmOverride() {
  const pending = pendingOverride.value
  if (!pending) return
  const reason = overrideReason.value.trim()
  if (!reason) {
    overrideError.value = t('payroll.runs.override.reason_required')
    return
  }
  saving.value = true
  try {
    if (pending.group) {
      const result = await payrollApi.overrideRunValidationsBulk(
        pending.run.id,
        {
          row_version: pending.run.row_version,
          code: pending.group.code,
          validation_ids: pendingGroupIds(pending.group),
          reason,
        },
        crypto.randomUUID(),
      )
      toast.success(t('payroll.runs.override.granted_all', { count: result.granted_count }))
    } else {
      await payrollApi.overrideRunValidation(
        pending.run.id,
        pending.validation.id,
        { row_version: pending.run.row_version, reason },
        crypto.randomUUID(),
      )
      toast.success(t('payroll.runs.override.granted'))
    }
    pendingOverride.value = null
    overrideReason.value = ''
    await load()
  } catch (error: any) {
    // Server má na odůvodnění vlastní minimum a jeho věta je konkrétnější než
    // cokoli, co bychom si tady vymysleli — proto se ukazuje v dialogu.
    overrideError.value = error?.response?.data?.error?.message
      || t('payroll.runs.override.failed')
    if (error?.response?.status === 409) {
      await load()
      const fresh = reloadedRun(pending.run)
      if (fresh === null) {
        toast.error(overrideError.value)
        pendingOverride.value = null
      } else {
        pendingOverride.value = { ...pending, run: fresh }
      }
    }
  } finally {
    saving.value = false
  }
}

async function revokeOverride(run: PayrollRun, validation: PayrollRunValidation) {
  if (!canOverride.value || !overrideEditable(run)) return
  saving.value = true
  try {
    await payrollApi.revokeRunValidationOverride(
      run.id,
      validation.id,
      { row_version: run.row_version },
      crypto.randomUUID(),
    )
    toast.success(t('payroll.runs.override.revoked'))
    await load()
  } catch (error: any) {
    toast.error(
      error?.response?.data?.error?.message
        || t('payroll.runs.override.revoke_failed'),
    )
    if (error?.response?.status === 409) await load()
  } finally {
    saving.value = false
  }
}

function askDeleteRun(run: PayrollRun) {
  if (!canWrite.value || !run.can_delete) return
  pendingDelete.value = run
}

async function deleteRun() {
  const run = pendingDelete.value
  if (!run) return
  saving.value = true
  try {
    await payrollApi.deleteRun(run.id, run.row_version)
    toast.success(t('payroll.runs.deleted'))
    pendingDelete.value = null
    await load()
  } catch (error: any) {
    showPayrollError(error, t('payroll.runs.delete_failed'))
    if (error?.response?.status === 409) {
      await load()
      pendingDelete.value = reloadedRun(run)
    }
  } finally {
    saving.value = false
  }
}

function changePeriod() {
  // Jiné období = jiný termín; ruční přepsání se váže na období, které se
  // opouští, takže padá s ním. Přesnější návrh dorazí z `load()`.
  paymentDateTouched.value = false
  paymentDate.value = fallbackPaymentDate(period.value)
  void router.replace({ query: { ...route.query, period: period.value } })
  // Jiné období = jiná množina běhů; zůstat na třetí stránce by ukázalo prázdno.
  offset.value = 0
  void load()
}

/*
 * Kontrola před během se mění akcemi jinde (hromadné ukončení dohod
 * v Zaměstnancích, schválení docházky, roční zúčtování). Okno v pozadí se po
 * změně mezd tiše obnoví, jakmile je vidět a nic rozpracovaného (dialog,
 * rozepsaný důvod, běžící příkaz) tomu nebrání. Vlastní obnova zároveň brání
 * tomu, aby okno stránku po vlastním příkazu vytvořilo znovu.
 */
useWindowRefresh(() => load({ silent: true }), () => !loading.value && !saving.value
  && !advancingStart.value && !fillingAverages.value
  && pendingCommand.value === null && pendingDelete.value === null && pendingApprove.value === null
  && pendingOverride.value === null && pendingStart.value === null && pendingHide.value === null
  && bulkDefaultsRun.value === null)

onMounted(() => { void load() })
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-end justify-between gap-4">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.runs.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.runs.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-end gap-3">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.runs.period') }}</span>
          <input
            v-model="period"
            type="month"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            @change="changePeriod"
          >
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.runs.payment_date') }}</span>
          <DateInput
            v-model="paymentDate"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            @input="paymentDateTouched = true" />
        </label>
        <RouterLink
          :to="{ name: 'payroll-quick-inputs', query: { period } }"
          :class="btnOutline('primary')"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.coin" />
          </svg>
          {{ t('payroll.runs.quick_inputs') }}
        </RouterLink>
        <!--
          Když za období běh UŽ JE, tlačítko se nekreslí vůbec — ani zašedlé,
          ani s vysvětlivkou vedle. Zakázané tlačítko s poznámkou „za tohle
          období už mzdový běh existuje" zabíralo nejvýraznější místo obrazovky
          a nešlo s ním nic dělat; ten běh je přitom vidět hned pod tím, takže
          i ta informace byla zbytečná. Hlavní akce patří ke konkrétnímu běhu,
          ne do hlavičky.
        -->
        <div v-if="canWrite && periodRun === null" class="flex flex-col items-start gap-1.5">
          <button
            :class="btnFilled('primary')"
            :disabled="saving || createBlockedReason !== null"
            :title="disabledTitle(createBlockedReason !== null, createBlockedReason)"
            data-test="run-create"
            @click="createRun"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path :d="ICONS.plus" />
            </svg>
            {{ t('payroll.runs.create') }}
          </button>
          <p v-if="createBlockedReason" :class="BTN_DISABLED_NOTE" data-test="run-create-blocked">
            {{ createBlockedReason }}
          </p>
        </div>
      </div>
    </header>

    <!--
      Příprava vstupů. Stojí NAD seznamem běhů schválně: je to práce, která
      musí být hotová dřív, než se zamkne, a účetní ji nemá hledat v menu.
    -->
    <section
      v-if="!loading && !loadFailed && showPreparation"
      class="rounded-xl border border-payroll-500/30 bg-payroll-50/40 p-4 shadow-sm sm:p-5"
      data-test="run-preparation"
    >
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="max-w-3xl">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.runs.preparation.title', { period: formatPeriod(period) }) }}
          </h2>
          <p class="mt-1 text-sm text-neutral-700">
            {{ periodRun
              ? t('payroll.runs.preparation.description_draft')
              : t('payroll.runs.preparation.description_missing') }}
          </p>
        </div>
        <button
          type="button"
          :class="btnOutlineSm('neutral')"
          data-test="run-preparation-toggle"
          @click="preparationOpen = !preparationOpen"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ preparationOpen ? t('payroll.runs.preparation.checklist_hide') : t('payroll.runs.preparation.checklist_show') }}
        </button>
      </div>

      <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <RouterLink
          :to="{ name: 'payroll-quick-inputs', query: { period } }"
          class="group rounded-lg border border-payroll-500/40 bg-surface p-4 transition hover:border-payroll-500 hover:shadow-sm"
          data-test="prepare-quick-inputs"
        >
          <svg class="h-5 w-5 text-payroll-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.coin" /></svg>
          <h3 class="mt-3 font-semibold text-neutral-900">{{ t('payroll.runs.preparation.quick_inputs') }}</h3>
          <p class="mt-1 text-xs text-neutral-600">{{ t('payroll.runs.preparation.quick_inputs_hint') }}</p>
        </RouterLink>
        <RouterLink
          :to="{ name: 'payroll-time', query: { period } }"
          class="group rounded-lg border border-neutral-200 bg-surface p-4 transition hover:border-payroll-500/60 hover:shadow-sm"
          data-test="prepare-time"
        >
          <svg class="h-5 w-5 text-payroll-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.clipboardCheck" /></svg>
          <h3 class="mt-3 font-semibold text-neutral-900">{{ t('payroll.runs.preparation.time') }}</h3>
          <p class="mt-1 text-xs text-neutral-600">{{ t('payroll.runs.preparation.time_hint') }}</p>
        </RouterLink>
        <RouterLink
          :to="{ name: 'payroll-absences', query: { period } }"
          class="group rounded-lg border border-neutral-200 bg-surface p-4 transition hover:border-payroll-500/60 hover:shadow-sm"
          data-test="prepare-absences"
        >
          <svg class="h-5 w-5 text-payroll-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.calendar" /></svg>
          <h3 class="mt-3 font-semibold text-neutral-900">{{ t('payroll.runs.preparation.absences') }}</h3>
          <p class="mt-1 text-xs text-neutral-600">{{ t('payroll.runs.preparation.absences_hint') }}</p>
        </RouterLink>
        <RouterLink
          :to="{ name: 'payroll-components' }"
          class="group rounded-lg border border-neutral-200 bg-surface p-4 transition hover:border-payroll-500/60 hover:shadow-sm"
          data-test="prepare-components"
        >
          <svg class="h-5 w-5 text-payroll-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.tag" /></svg>
          <h3 class="mt-3 font-semibold text-neutral-900">{{ t('payroll.runs.preparation.components') }}</h3>
          <p class="mt-1 text-xs text-neutral-600">{{ t('payroll.runs.preparation.components_hint') }}</p>
        </RouterLink>
        <RouterLink
          :to="{ name: 'payroll-people' }"
          class="group rounded-lg border border-neutral-200 bg-surface p-4 transition hover:border-payroll-500/60 hover:shadow-sm"
          data-test="prepare-people"
        >
          <svg class="h-5 w-5 text-payroll-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.user" /></svg>
          <h3 class="mt-3 font-semibold text-neutral-900">{{ t('payroll.runs.preparation.people') }}</h3>
          <p class="mt-1 text-xs text-neutral-600">{{ t('payroll.runs.preparation.people_hint') }}</p>
        </RouterLink>
      </div>

      <p class="mt-4 flex items-start gap-2 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700">
        <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.lock" />
        </svg>
        <span data-test="run-preparation-freeze">{{ t('payroll.runs.preparation.freeze_warning') }}</span>
      </p>

      <!--
        KONTROLA PŘED ZAHÁJENÍM. Tytéž kontroly, které běh hlásil až po
        zamknutí, jen puštěné nasucho a předem. Nic tím není zablokované —
        je to seznam k prohlédnutí, ne brána.
      -->
      <div v-if="readiness" class="mt-4" data-test="run-readiness">
        <div
          v-if="readinessFindings.length === 0"
          class="flex items-start gap-2 rounded-lg border border-success-500/30 bg-success-50 p-3 text-sm text-success-700"
          data-test="run-readiness-clear"
        >
          <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.checkCircle" />
          </svg>
          <span>{{ t('payroll.runs.readiness.clear') }}</span>
        </div>
        <template v-else>
          <h3 class="text-sm font-semibold text-neutral-900">
            {{ t('payroll.runs.readiness.title') }}
          </h3>
          <p class="mt-1 max-w-3xl text-sm text-neutral-600">
            {{ t('payroll.runs.readiness.subtitle') }}
          </p>
          <div
            v-if="readinessSourceSteps.length"
            class="mt-3 flex flex-wrap gap-2"
            data-test="run-readiness-approval-links"
          >
            <RouterLink
              v-if="readinessSourceSteps.includes('time')"
              :to="{ name: 'payroll-time', query: { period, approve: 'all' } }"
              :class="[btnOutlineSm('success'), 'whitespace-nowrap']"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.badgeCheck" /></svg>
              {{ t('payroll.runs.continue_steps.time_action') }}
            </RouterLink>
            <RouterLink
              v-if="readinessSourceSteps.includes('inputs')"
              :to="{ name: 'payroll-components', query: { tab: 'inputs', status: 'draft', period } }"
              :class="[btnOutlineSm('success'), 'whitespace-nowrap']"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.badgeCheck" /></svg>
              {{ t('payroll.runs.continue_steps.inputs_action') }}
            </RouterLink>
            <RouterLink
              v-if="readinessSourceSteps.includes('components')"
              :to="{ name: 'payroll-quick-inputs', query: { period } }"
              :class="[btnOutlineSm('primary'), 'whitespace-nowrap']"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
              {{ t('payroll.runs.continue_steps.components_action') }}
            </RouterLink>
            <RouterLink
              v-if="readinessSourceSteps.includes('statutory')"
              :to="{ name: 'payroll-people', query: { filter: 'blocking_data', period } }"
              :class="[btnOutlineSm('primary'), 'whitespace-nowrap']"
              data-test="run-readiness-step-statutory"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.clipboardCheck" /></svg>
              {{ t('payroll.runs.continue_steps.statutory_people_action') }}
            </RouterLink>
          </div>
          <div
            v-for="group in readinessGroups"
            :key="group.impact"
            class="mt-3"
            :data-test="`run-readiness-group-${group.impact}`"
          >
            <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">
              {{ t(`payroll.runs.readiness.impact.${group.impact}`) }}
            </h4>
            <p class="mt-0.5 text-xs text-neutral-500">
              {{ t(`payroll.runs.readiness.impact_hint.${group.impact}`) }}
            </p>
            <ul class="mt-2 space-y-2">
              <li
                v-for="finding in group.findings"
                :key="finding.code"
                class="rounded-lg border p-3 text-sm"
                :class="findingClass(finding)"
                :data-testid="`run-readiness-${finding.code}`"
              >
                <p>
                  <span v-if="findingCount(finding) > 1" class="font-semibold" :data-test="`run-readiness-count-${finding.code}`">{{ findingCount(finding) }}× </span>{{ readinessFindingMessage(finding) }}
                </p>
                <p v-if="finding.requires_override" class="mt-1 text-xs" :data-test="`run-readiness-override-${finding.code}`">
                  {{ t('payroll.runs.override.awaiting') }}
                </p>
                <p
                  v-if="findingEntityLabels(finding).length"
                  class="mt-1 text-xs font-semibold"
                  :data-test="`run-readiness-affected-${finding.code}`"
                >
                  {{ t('payroll.runs.readiness.affected', {
                    names: entityLabelSummary(findingEntityLabels(finding), findingNotListed(finding)),
                  }) }}
                </p>
                <p class="mt-1 text-xs opacity-70">
                  {{ t(`payroll.runs.readiness.scope.${finding.scope}`) }}
                </p>
                <ExpandableList
                  v-if="finding.entities.some(entity => entity.message || entity.remediation_path)"
                  class="mt-2"
                  :items="finding.entities"
                  :item-key="readinessEntityKey"
                  :search-text="readinessEntitySearchText"
                  :total="finding.entity_total ?? null"
                  :test-id="`run-readiness-entities-${finding.code}`"
                >
                  <template #item="{ item: entity }">
                    <p v-if="entity.message && entity.message !== finding.message" class="text-sm">{{ entity.message }}</p>
                    <a v-if="entity.remediation_path" :href="readinessHref(entity.remediation_path)" :class="[btnOutlineSmWrap('neutral'), 'mt-1 max-w-full text-left']" data-test="run-readiness-entity-link">
                      <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.link" /></svg>
                      {{ t('payroll.runs.validation.open_remediation') }}<span v-if="entity.label">: {{ entity.label }}</span>
                    </a>
                  </template>
                </ExpandableList>
                <!-- Výčet je oříznutý: zbytek osob nálezu ukáže seznam zaměstnanců zúžený týmž pravidlem. -->
                <a
                  v-if="finding.people_list_path"
                  :href="finding.people_list_path"
                  :class="[btnOutlineSm('neutral'), 'mt-2 inline-flex']"
                  :data-test="`run-readiness-people-list-${finding.code}`"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.link" /></svg>
                  {{ t('payroll.runs.readiness.people_list', { count: finding.people_total ?? 0 }, finding.people_total ?? 0) }}
                </a>
                <a
                  v-if="finding.remediation_path && !finding.entities.some(entity => entity.remediation_path)"
                  :href="readinessHref(finding.remediation_path)"
                  :class="[btnOutlineSm('neutral'), 'mt-2 inline-flex']"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.link" />
                  </svg>
                  {{ t('payroll.runs.validation.open_remediation') }}
                </a>
                <!--
                  Víc dotčených vztahů: odkazy u záznamů otevřou vždy jednu osobu.
                  Společný odkaz vede na tutéž agendu bez zúžení, kde je hromadná
                  akce (průměry za čtvrtletí, schválení docházky, zaokrouhlení).
                -->
                <a
                  v-else-if="finding.remediation_path && findingCount(finding) > 1"
                  :href="readinessHref(finding.remediation_path)"
                  :class="[btnOutlineSm('neutral'), 'mt-2 inline-flex']"
                  :data-test="`run-readiness-open-all-${finding.code}`"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.link" />
                  </svg>
                  {{ t('payroll.runs.readiness.open_all', { count: findingCount(finding) }) }}
                </a>
                <button
                  v-if="PROBABLE_AVERAGE_CODES.includes(finding.code) && canFillAverages"
                  type="button"
                  :class="[btnOutlineSm('success'), 'mt-2 whitespace-nowrap']"
                  :disabled="fillingAverages"
                  :data-test="`run-readiness-fill-probable-${finding.code}`"
                  @click="fillProbableAverages"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
                  {{ t('payroll.runs.readiness.fill_probable_average') }}
                </button>
                <div
                  v-if="finding.bulk_actions?.length"
                  class="mt-2 flex flex-wrap gap-2"
                  :data-test="`run-readiness-bulk-${finding.code}`"
                >
                  <RouterLink
                    v-for="action in finding.bulk_actions"
                    :key="action.kind"
                    :to="readinessBulkTarget(action)"
                    :class="[btnOutlineSm(action.kind === 'declaration_signed' ? 'success' : 'warning'), 'whitespace-nowrap']"
                    :data-test="`run-readiness-bulk-action-${action.kind}`"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="READINESS_BULK_ICONS[action.kind]" /></svg>
                    {{ t(`payroll.runs.readiness.bulk.${action.kind}`, { count: action.count, period: formatPeriod(readinessBulkPeriod()) }) }}
                  </RouterLink>
                </div>
                <div
                  v-if="finding.hideable && canHideWarnings"
                  class="mt-2 flex flex-wrap gap-2"
                  :data-test="`run-readiness-hide-${finding.code}`"
                >
                  <button
                    v-if="finding.subject_ids?.length"
                    type="button"
                    :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
                    :data-test="`run-readiness-hide-people-${finding.code}`"
                    @click="askHide(finding.code, finding.message, 'people', readinessHideSubjects(finding))"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.eyeOff" /></svg>
                    {{ t('payroll.runs.hide_warning_people', { count: finding.subject_ids.length }, finding.subject_ids.length) }}
                  </button>
                  <button
                    type="button"
                    :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
                    :data-test="`run-readiness-hide-type-${finding.code}`"
                    @click="askHide(finding.code, finding.message, 'type', [])"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.eyeOff" /></svg>
                    {{ t('payroll.runs.hide_warning_type') }}
                  </button>
                </div>
              </li>
            </ul>
          </div>
        </template>
      </div>

      <!--
        Přehled toho, co se za měsíc odvede a odešle. Je to TÝŽ panel jako
        v Podáních, jen řízený obdobím téhle stránky — druhá kopie by se s ním
        dřív nebo později rozešla.
      -->
      <div v-if="preparationOpen" class="mt-5">
        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">
          {{ t('payroll.runs.preparation.checklist_title') }}
        </h3>
        <PayrollMonthlyChecklistPanel
          v-model:environment="checklistEnvironment"
          :period="period"
        />
      </div>
    </section>

    <div
      v-if="missingPreviousPeriods.length > 0"
      data-testid="payroll-runs-missing-previous"
      class="flex items-start gap-3 rounded-xl border border-warning-200 bg-warning-50 p-4 text-sm text-warning-800"
      role="alert"
    >
      <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path :d="ICONS.bell" />
      </svg>
      <div class="flex-1 space-y-2">
        <p class="font-medium">
          {{ t('payroll.runs.missing_previous.title', { count: missingPreviousPeriods.length, period: formatPeriod(period) }, missingPreviousPeriods.length) }}
        </p>
        <p>{{ t(advanceStartTo !== null ? 'payroll.runs.missing_previous.hint_takeover' : 'payroll.runs.missing_previous.hint') }}</p>
        <div class="flex flex-wrap gap-2">
          <button
            v-if="advanceStartTo !== null && canSettings"
            type="button"
            :class="[btnFilled('primary'), 'whitespace-nowrap']"
            :disabled="advancingStart || loading"
            data-testid="payroll-runs-advance-start"
            @click="advanceStart"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
            {{ t('payroll.runs.missing_previous.advance', { period: formatPeriod(advanceStartTo) }) }}
          </button>
          <button
            v-for="missing in missingPreviousPeriods"
            :key="missing"
            type="button"
            :class="[btnOutlineSm('warning'), 'whitespace-nowrap']"
            :data-testid="`payroll-runs-missing-${missing}`"
            @click="openMissingPeriod(missing)"
          >
            {{ formatPeriod(missing) }}
          </button>
          <RouterLink
            :to="{ name: 'payroll-settings' }"
            :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.link" /></svg>
            {{ t('payroll.runs.missing_previous.settings') }}
          </RouterLink>
        </div>
      </div>
    </div>

    <!--
      Rok přechodu z jiného mzdového programu. Panel se sám schová u firmy,
      která žádné převzaté historické měsíce nemá.
    -->
    <PayrollTakeoverRunsPanel
      :key="takeoverPanelKey"
      :year="takeoverYear"
      :can-write="canWrite"
      @changed="load()"
    />

    <div v-if="loading" class="space-y-3">
      <div v-for="index in 2" :key="index" class="h-40 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <EmptyState
      v-else-if="loadFailed"
      variant="failed"
      boxed
      data-test="load-failed"
      :message="t('payroll.runs.load_failed_hint')"
      @action="load()"
    />

    <section
      v-else-if="runs.length === 0"
      class="rounded-xl border border-dashed border-neutral-300 bg-surface p-8 text-center"
    >
      <h2 class="font-semibold text-neutral-900">{{ t('payroll.runs.empty') }}</h2>
      <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.runs.empty_hint') }}</p>
      <RouterLink
        :to="{ name: 'payroll-quick-inputs', query: { period } }"
        :class="[btnOutline('primary'), 'mt-4']"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.coin" />
        </svg>
        {{ t('payroll.runs.quick_inputs') }}
      </RouterLink>
    </section>

    <section v-else class="space-y-4">
      <article
        v-for="run in runs"
        :key="run.id"
        class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-5"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <div class="flex flex-wrap items-center gap-2">
              <h2 class="text-lg font-semibold text-neutral-900">
                {{ t('payroll.runs.run_label', { period: formatPeriod(run.period_start.slice(0, 7)) }) }}
              </h2>
              <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(run.status)">
                {{ t(`payroll.runs.status.${run.status}`) }}
              </span>
              <!--
                Převzatý běh musí jít odlišit na první pohled. Je to zrcadlo
                cizího výpočtu: žádná revize, žádné zaúčtování, žádné doklady.
              -->
              <span
                v-if="run.run_kind === 'takeover'"
                class="rounded-full bg-payroll-50 px-2.5 py-1 text-xs font-medium text-payroll-600"
                :title="t('payroll.runs.takeover.badge_hint')"
              >
                {{ t('payroll.runs.takeover.badge') }}
              </span>
            </div>
            <p class="mt-1 text-sm text-neutral-500">
              {{ t('payroll.runs.payment_date_value', { date: formatDate(run.payment_date) }) }}
              · {{ t('payroll.runs.revision', { revision: run.revision_no ?? 0 }) }}
            </p>
          </div>
          <div
            v-if="visibleCommands(run).length || (canWrite && run.can_delete)"
            class="flex flex-wrap items-center justify-end gap-2"
          >
            <button
              v-for="command in visibleCommands(run)"
              :key="command"
              :data-testid="`payroll-run-${run.id}-${command}`"
              class="cursor-pointer"
              :class="commandClass(run, command)"
              :disabled="saving || commandDisabled(run, command)"
              :title="commandDisabled(run, command) ? t('payroll.runs.approve_blocked.title', { count: approveBlockerCount(run) }) : undefined"
              @click="runCommand(run, command)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="commandIcon(command)" />
              </svg>
              {{ commandLabel(command, run) }}
              <span
                v-if="commandDisabled(run, command)"
                :data-testid="`payroll-run-${run.id}-approve-blocker-count`"
              >({{ approveBlockerCount(run) }})</span>
            </button>
            <button
              v-if="canWrite && run.can_delete"
              :data-testid="`delete-payroll-run-${run.id}`"
              :class="btnOutline('danger')"
              :disabled="saving"
              @click="askDeleteRun(run)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.trash" />
              </svg>
              {{ t('payroll.runs.delete') }}
            </button>
          </div>
        </div>

        <!--
          Vysvětlení patří POD tlačítka, ne vedle nich. Vedle nich přetékalo
          přes celou šířku karty a řádek s akcemi se kvůli němu nedal přečíst.
        -->
        <p
          v-if="commandHint(run)"
          :data-testid="`payroll-run-${run.id}-hint`"
          class="mt-3 max-w-3xl text-sm text-neutral-600"
        >
          {{ commandHint(run) }}
        </p>
        <p
          v-if="visibleCommands(run).includes('approve') && commandDisabled(run, 'approve')"
          :data-testid="`payroll-run-${run.id}-approve-blocked`"
          class="mt-2 flex max-w-3xl flex-wrap items-center gap-x-2 gap-y-1 text-sm text-warning-800"
        >
          <span>{{ t('payroll.runs.approve_blocked.hint', { count: approveBlockerCount(run) }) }}</span>
          <button
            type="button"
            class="cursor-pointer whitespace-nowrap font-medium text-primary-600 hover:underline"
            :data-testid="`payroll-run-${run.id}-approve-blocked-show`"
            @click="showValidations(run)"
          >
            {{ t('payroll.runs.approve_blocked.show') }}
          </button>
        </p>
        <!--
          Otevřená revize počítá ze snímku, který mezitím zastaral. Bez tohohle
          upozornění se doplatek schválený po zámku do revize nedostal a nikdo
          si toho nevšiml; schválení teď server zastaví, tak ať účetní ví proč
          dřív, než na něj klikne.
        -->
        <div
          v-if="hasSourceDrift(run)"
          :data-testid="`payroll-run-${run.id}-source-drift`"
          class="mt-3 flex max-w-3xl items-start gap-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800"
        >
          <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path :d="ICONS.bell" />
          </svg>
          <div class="flex-1 space-y-1">
            <p class="font-medium">
              {{ t('payroll.runs.source_drift.title', { count: run.source_drift?.total ?? 0, date: formatDateTime(run.source_drift?.snapshot_created_at ?? '') }) }}
            </p>
            <ul class="list-disc pl-5">
              <li
                v-for="line in sourceDriftLines(run)"
                :key="line.key"
                :data-test="`payroll-run-source-drift-${line.key}`"
              >{{ line.text }}</li>
            </ul>
            <p>
              {{ t(visibleCommands(run).includes('refresh_inputs') ? 'payroll.runs.source_drift.action' : 'payroll.runs.source_drift.action_forbidden') }}
            </p>
          </div>
        </div>
        <div
          v-if="run.average_change"
          :data-testid="`payroll-run-${run.id}-average-change`"
          class="mt-3 flex max-w-3xl items-start gap-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800"
          role="alert"
        >
          <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.bell" />
          </svg>
          <div class="min-w-0 flex-1 space-y-2">
            <p class="font-medium">
              {{ t(run.average_change.approved ? 'payroll.runs.average_change.title_approved' : 'payroll.runs.average_change.title_open') }}
            </p>
            <ExpandableList
              :items="run.average_change.employments"
              :item-key="averageChangeKey"
              :search-text="averageChangeSearchText"
              :test-id="`payroll-run-${run.id}-average-change-list`"
              list-class="list-disc space-y-0.5 pl-5"
            >
              <template #item="{ item }">
                <RouterLink
                  :to="{ name: 'payroll-people', query: { person: String(item.employee_id), employment: String(item.employment_id) } }"
                  class="font-medium text-primary-600 hover:underline"
                >{{ item.full_name }}</RouterLink>
                <span v-if="item.average_changed"> · {{ averageChangeAverage(item) }}</span>
                <span
                  v-for="compensation in item.compensations"
                  :key="compensation.input_id"
                  data-test="payroll-run-average-change-compensation"
                > · {{ averageChangeCompensation(compensation) }}</span>
              </template>
            </ExpandableList>
            <p v-if="run.average_change.compensation_count > 0">
              {{ t('payroll.runs.average_change.compensation_body', { difference: money(run.average_change.compensation_difference_minor) }) }}
            </p>
            <p>{{ t(`payroll.runs.average_change.body_${run.average_change.action ?? 'none'}`) }}</p>
            <button
              v-if="averageChangeCommand(run)"
              type="button"
              class="cursor-pointer whitespace-nowrap"
              :class="btnOutlineSm('warning')"
              :disabled="saving"
              :data-testid="`payroll-run-${run.id}-average-change-fix`"
              @click="fixAverageChange(run)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="run.average_change.action === 'refresh_inputs' ? ICONS.lock : ICONS.uturn" /></svg>
              {{ t(`payroll.runs.average_change.action_${run.average_change.action}`) }}
            </button>
            <p v-else-if="run.average_change.action !== null" data-test="payroll-run-average-change-forbidden">
              {{ t('payroll.runs.average_change.action_forbidden') }}
            </p>
          </div>
        </div>
        <!--
          Schválený běh nezná vztah, který do jeho období patří (zaměstnanec
          založený po schválení, nástup posunutý dozadu). Bez tohohle výčtu
          osoba tiše zůstala bez mzdy i bez měsíčního hlášení za měsíc nástupu.
        -->
        <div
          v-if="(run.missing_employments ?? []).length > 0"
          :data-testid="`payroll-run-${run.id}-missing-employments`"
          class="mt-3 flex max-w-3xl items-start gap-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800"
          role="alert"
        >
          <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.bell" />
          </svg>
          <div class="min-w-0 flex-1 space-y-2">
            <p class="font-medium">
              {{ t('payroll.runs.missing_employments.title', { count: (run.missing_employments ?? []).length }, (run.missing_employments ?? []).length) }}
            </p>
            <ExpandableList
              :items="run.missing_employments ?? []"
              :item-key="missingEmploymentKey"
              :search-text="missingEmploymentSearchText"
              :test-id="`payroll-run-${run.id}-missing-employments-list`"
              list-class="list-disc space-y-0.5 pl-5"
            >
              <template #item="{ item }">
                <RouterLink
                  :to="{ name: 'payroll-people', query: { person: String(item.employee_id), employment: String(item.employment_id) } }"
                  class="font-medium text-primary-600 hover:underline"
                >{{ item.full_name }}</RouterLink>
                <span v-if="item.start_date"> · {{ t('payroll.runs.missing_employments.start', { date: formatDate(item.start_date) }) }}</span>
              </template>
            </ExpandableList>
            <p>{{ t('payroll.runs.missing_employments.body') }}</p>
            <button
              v-if="visibleCommands(run).includes('request_correction')"
              type="button"
              class="cursor-pointer"
              :class="btnOutlineSm('warning')"
              :disabled="saving"
              :data-testid="`payroll-run-${run.id}-missing-employments-correct`"
              @click="runCommand(run, 'request_correction')"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.uturn" /></svg>
              {{ commandLabel('request_correction', run) }}
            </button>
            <p v-else data-test="payroll-run-missing-employments-forbidden">
              {{ t('payroll.runs.missing_employments.action_forbidden') }}
            </p>
          </div>
        </div>
        <!--
          Stav úhrady, ne úkol. Účetní tu nemá co potvrzovat — příkaz do banky
          poslala a výpis dorazí, až dorazí. Vidí jen, kolik závazků je
          doložených a kolik ještě čeká, s prokliknutím do plateb.
        -->
        <p
          v-if="run.payment_coverage"
          :data-testid="`payroll-run-${run.id}-coverage`"
          class="mt-3 flex max-w-3xl flex-wrap items-center gap-x-2 gap-y-1 text-sm"
          :class="run.payment_coverage.uncovered_count > 0 ? 'text-neutral-600' : 'text-success-700'"
        >
          <span>{{ coverageLabel(run) }}</span>
          <RouterLink
            :to="{ name: 'payroll-payments', query: { period: run.period_start.slice(0, 7), run: String(run.id) } }"
            class="font-medium text-primary-600 hover:underline"
          >
            {{ t('payroll.runs.coverage.open_payments') }}
          </RouterLink>
        </p>
        <!--
          Když smazat nejde, tlačítko dřív jen zmizelo a účetní nevěděla
          proč. Důvod rozhodnutí zná, tak ho ukaž — i kdyby to mělo být
          jen „běh má účetní stopu".
        -->
        <p
          v-if="canWrite && !run.can_delete && run.delete_blocker"
          :data-testid="`payroll-run-${run.id}-delete-blocker`"
          class="mt-2 max-w-3xl text-xs text-neutral-500"
        >
          {{ run.delete_blocker }}
        </p>

        <p
          v-if="runningCommand?.runId === run.id"
          :data-testid="`payroll-run-${run.id}-command-progress`"
          class="mt-4 flex items-start gap-3 rounded-lg border border-payroll-200 bg-payroll-50 p-3 text-sm text-payroll-800"
          role="status"
          aria-live="polite"
        >
          <svg class="mt-0.5 h-4 w-4 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle class="opacity-20" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" />
            <path d="M22 12a10 10 0 0 0-10-10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
          </svg>
          <span>
            <span class="font-medium">{{ commandProgressLabel(runningCommand.command) }}</span>
            {{ t('payroll.runs.command_progress.hint') }}
          </span>
        </p>

        <div
          v-if="commandBlockers[run.id]"
          :data-testid="`payroll-run-${run.id}-blocker`"
          class="mt-4 flex items-start gap-3 rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-700"
        >
          <svg class="mt-0.5 h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path :d="ICONS.bell" />
          </svg>
          <p class="flex-1">{{ commandBlockers[run.id] }}</p>
          <button
            type="button"
            class="cursor-pointer shrink-0 rounded p-1 text-warning-600 hover:bg-warning-100"
            :aria-label="t('common.close')"
            @click="dismissBlocker(run.id)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <path :d="ICONS.x" />
            </svg>
          </button>
        </div>

        <dl v-if="run.result_snapshot?.totals" class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
          <div class="rounded-lg bg-neutral-50 p-3">
            <dt class="text-xs text-neutral-500">{{ t('payroll.runs.cash_before') }}</dt>
            <dd class="mt-1 font-semibold text-neutral-900">
              {{ money(run.result_snapshot.totals.cash_payable_minor) }}
            </dd>
          </div>
          <div v-if="run.result_snapshot.totals.enforcement_withheld_minor != null" class="rounded-lg bg-payroll-50 p-3" data-test="run-enforcement-withheld">
            <dt class="text-xs text-payroll-700">{{ t('payroll.runs.enforcement_withheld') }}</dt>
            <dd class="mt-1 font-semibold text-payroll-700">
              {{ money(run.result_snapshot.totals.enforcement_withheld_minor) }}
            </dd>
          </div>
          <div class="rounded-lg bg-success-50 p-3">
            <dt class="text-xs text-success-700">{{ t('payroll.runs.payable_after') }}</dt>
            <dd class="mt-1 font-semibold text-success-700">
              {{ money(run.result_snapshot.totals.payable_after_enforcement_minor) }}
            </dd>
          </div>
        </dl>

        <!--
          „Co následuje" — rozcestník z běhu do podání.

          Mzdový běh doběhne, obrazovka vypadá hotově, a přitom tou chvílí
          teprve začíná to podstatné: měsíční hlášení ČSSZ a přehledy pro
          zdravotní pojišťovny. Účetní si dosud musela sama pamatovat, že má
          jít jinam — a hlavně DO KDY.

          Není to druhá fronta „K odeslání": je to TÝŽ panel, jaký běží
          v Podáních a nad přípravou vstupů, jen řízený obdobím tohohle běhu.
          Druhá kopie by se s ním dřív nebo později rozešla v termínech
          i ve stavech. Panel sám ukazuje lhůtu konkrétním datem, stav podání
          z evidence, po lhůtě zvýrazní a u každé položky vede odkazem tam,
          kde se to reálně dělá; co je hotové, se kreslí jako hotové.
        -->
        <div
          v-if="showsAfterApprovalLinks(run)"
          class="mt-5"
          :data-test="`payroll-run-${run.id}-after-approval`"
        >
          <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">
            {{ t('payroll.runs.after_approval.title') }}
          </h4>
          <p class="mb-3 max-w-3xl text-sm text-neutral-600">
            {{ t(run.status === 'approved' ? 'payroll.runs.after_approval.subtitle_approved' : 'payroll.runs.after_approval.subtitle') }}
          </p>
          <div class="flex flex-wrap gap-2">
            <RouterLink
              :to="{ name: 'payroll-documents', query: { period: run.period_start.slice(0, 7) } }"
              :class="[btnOutline('neutral'), 'whitespace-nowrap']"
              :data-test="`payroll-run-${run.id}-after-approval-documents`"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg>
              {{ t('payroll.runs.after_approval.documents') }}
            </RouterLink>
            <RouterLink
              :to="{ name: 'payroll-payments', query: { period: run.period_start.slice(0, 7), run: String(run.id) } }"
              :class="[btnOutline('neutral'), 'whitespace-nowrap']"
              :data-test="`payroll-run-${run.id}-after-approval-payments`"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.coin" /></svg>
              {{ t('payroll.runs.after_approval.payments') }}
            </RouterLink>
            <RouterLink
              :to="{ name: 'payroll-submissions-tab', params: { tab: 'monthly' }, query: { period: run.period_start.slice(0, 7) } }"
              :class="[btnOutline('neutral'), 'whitespace-nowrap']"
              :data-test="`payroll-run-${run.id}-after-approval-submissions`"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.link" /></svg>
              {{ t('payroll.runs.after_approval.submissions') }}
            </RouterLink>
          </div>
        </div>

        <div v-if="showsNextSteps(run)" class="mt-5" :data-test="`payroll-run-${run.id}-next-steps`">
          <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">
            {{ t('payroll.runs.next_steps.title') }}
          </h4>
          <p class="mb-3 max-w-3xl text-sm text-neutral-600">
            {{ t('payroll.runs.next_steps.subtitle') }}
          </p>
          <PayrollMonthlyChecklistPanel
            v-model:environment="checklistEnvironment"
            :period="run.period_start.slice(0, 7)"
          />
        </div>

        <div class="mt-4 flex flex-wrap gap-2">
          <button
            v-if="run.result_snapshot"
            type="button"
            :data-testid="`payroll-run-${run.id}-breakdown-toggle`"
            :class="btnOutline('neutral')"
            :aria-expanded="!!breakdownOpen[run.id]"
            @click="toggleBreakdown(run)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.chart" />
            </svg>
            {{ breakdownOpen[run.id] ? t('payroll.runs.breakdown_hide') : t('payroll.runs.breakdown_show') }}
          </button>
          <button
            type="button"
            :data-testid="`payroll-run-${run.id}-history-toggle`"
            :class="btnOutline('neutral')"
            :disabled="historyLoading[run.id]"
            @click="toggleHistory(run)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.cycle" />
            </svg>
            {{
              historyLoading[run.id]
                ? t('common.loading')
                : historyOpen[run.id]
                  ? t('payroll.runs.history.hide')
                  : t('payroll.runs.history.show')
            }}
          </button>
          <RouterLink
            :to="{ name: 'payroll-components', query: { tab: 'inputs', period: run.period_start.slice(0, 7) } }"
            :data-testid="`payroll-run-${run.id}-inputs-link`"
            :class="btnOutline('neutral')"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.download" />
            </svg>
            {{ t('payroll.runs.inputs_link') }}
          </RouterLink>
        </div>

        <section
          v-if="historyOpen[run.id]"
          :data-testid="`payroll-run-${run.id}-history`"
          class="mt-4 rounded-xl border border-neutral-200 bg-neutral-50 p-4"
        >
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
              <h3 class="font-semibold text-neutral-900">{{ t('payroll.runs.history.title') }}</h3>
              <p class="mt-1 text-xs leading-relaxed text-neutral-500">
                {{ t('payroll.runs.history.hint') }}
              </p>
            </div>
          </div>

          <div
            v-if="historyFailed[run.id]"
            :data-testid="`payroll-run-${run.id}-history-failed`"
            class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
            role="alert"
          >
            <p>{{ t('payroll.runs.history.load_failed') }}</p>
            <button
              type="button"
              :data-testid="`payroll-run-${run.id}-history-retry`"
              :class="[btnOutlineSm('danger'), 'mt-2']"
              :disabled="historyLoading[run.id]"
              @click="loadHistory(run.id)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.cycle" />
              </svg>
              {{ t('payroll.runs.history.retry') }}
            </button>
          </div>

          <p
            v-else-if="histories[run.id] && histories[run.id].revisions.length === 0 && histories[run.id].events.length === 0"
            class="mt-3 text-sm text-neutral-500"
          >
            {{ t('payroll.runs.history.empty') }}
          </p>

          <div v-else-if="histories[run.id]" class="mt-4 grid gap-5 xl:grid-cols-2">
            <div>
              <h4 class="text-sm font-semibold text-neutral-800">{{ t('payroll.runs.history.revisions') }}</h4>
              <ol class="mt-3 space-y-3">
                <li
                  v-for="revision in [...histories[run.id].revisions].reverse()"
                  :key="revision.id"
                  class="rounded-lg border border-neutral-200 bg-surface p-3"
                >
                  <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="flex flex-wrap items-center gap-2">
                      <span class="font-medium text-neutral-900">
                        {{ t('payroll.runs.history.revision_label', { revision: revision.revision_no }) }}
                      </span>
                      <span class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">
                        {{ t(`payroll.runs.history.kind.${revision.revision_kind}`) }}
                      </span>
                    </div>
                    <time class="text-xs text-neutral-500">{{ formatDateTime(revision.created_at) }}</time>
                  </div>

                  <div v-if="revision.diff_from_previous" class="mt-3 space-y-2">
                    <div class="flex flex-wrap gap-1.5 text-xs">
                      <span class="rounded-full bg-neutral-100 px-2 py-1 text-neutral-700">
                        {{ t(`payroll.runs.history.${revision.diff_from_previous.input_changed ? 'input_changed' : 'input_unchanged'}`) }}
                      </span>
                      <span class="rounded-full bg-neutral-100 px-2 py-1 text-neutral-700">
                        {{ t(`payroll.runs.history.${revision.diff_from_previous.ruleset_changed ? 'ruleset_changed' : 'ruleset_unchanged'}`) }}
                      </span>
                      <span class="rounded-full bg-neutral-100 px-2 py-1 text-neutral-700">
                        {{ t(`payroll.runs.history.${revision.diff_from_previous.result_changed ? 'result_changed' : 'result_unchanged'}`) }}
                      </span>
                    </div>
                    <dl v-if="historyTotalDiffs(revision).length" class="space-y-1.5">
                      <div
                        v-for="item in historyTotalDiffs(revision)"
                        :key="item.key"
                        class="flex flex-wrap items-baseline justify-between gap-2 text-xs"
                      >
                        <dt class="text-neutral-600">{{ t(`payroll.runs.history.total.${item.key}`) }}</dt>
                        <dd class="font-medium text-neutral-800">
                          {{ money(item.diff.before) }} → {{ money(item.diff.after) }}
                          <span class="ml-1 text-primary-700">
                            {{ t('payroll.runs.history.delta', { value: money(item.diff.delta) }) }}
                          </span>
                        </dd>
                      </div>
                    </dl>
                  </div>
                  <p v-else class="mt-2 text-xs text-neutral-500">
                    {{ t('payroll.runs.history.first_revision') }}
                  </p>
                </li>
              </ol>
            </div>

            <div>
              <h4 class="text-sm font-semibold text-neutral-800">{{ t('payroll.runs.history.events') }}</h4>
              <ol class="mt-3 border-l border-neutral-300 pl-4">
                <li
                  v-for="event in [...histories[run.id].events].reverse()"
                  :key="event.id"
                  class="relative pb-4 last:pb-0"
                >
                  <span class="absolute -left-[1.18rem] top-1.5 h-2 w-2 rounded-full bg-primary-500" aria-hidden="true" />
                  <div class="flex flex-wrap items-baseline justify-between gap-2">
                    <p class="text-sm font-medium text-neutral-800">{{ historyEventLabel(event) }}</p>
                    <time class="text-xs text-neutral-500">{{ formatDateTime(event.created_at) }}</time>
                  </div>
                  <p v-if="event.from_status && event.to_status" class="mt-0.5 text-xs text-neutral-500">
                    {{ t(`payroll.runs.status.${event.from_status}`) }} → {{ t(`payroll.runs.status.${event.to_status}`) }}
                  </p>
                  <p v-if="event.actor_name" class="mt-0.5 text-xs text-neutral-500">
                    {{ t('payroll.runs.history.actor', { name: event.actor_name }) }}
                  </p>
                  <p v-if="event.reason" class="mt-1 text-sm leading-relaxed text-neutral-700">
                    {{ event.reason }}
                  </p>
                </li>
              </ol>
            </div>
          </div>
        </section>

        <PayrollRunPeopleTable
          v-if="breakdownOpen[run.id] && run.result_snapshot"
          :run-id="run.id"
          :revision-id="run.revision_id"
          :period="run.period_start.slice(0, 7)"
          :approved="run.revision_status === 'approved'"
          :refresh-key="run.row_version"
        />

        <!--
          Po schválení jsou varování odsouhlasená — zůstává jen nenápadný
          odkaz, který je rozbalí. Skrytá varování mají odkaz do nastavení.
        -->
        <div
          v-if="calculationWarningCount(run) || run.hidden_validation_count"
          class="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs"
          :data-testid="`payroll-run-${run.id}-warning-links`"
        >
          <button
            v-if="calculationWarningCount(run)"
            type="button"
            class="inline-flex items-center gap-1 whitespace-nowrap text-neutral-500 underline decoration-dotted underline-offset-2 hover:text-neutral-700"
            :aria-expanded="!!calculationWarningsOpen[run.id]"
            :data-testid="`payroll-run-${run.id}-calculation-warnings`"
            @click="toggleCalculationWarnings(run)"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="calculationWarningsOpen[run.id] ? ICONS.eyeOff : ICONS.eye" /></svg>
            {{ calculationWarningsOpen[run.id]
              ? t('payroll.runs.calculation_warnings_hide')
              : t('payroll.runs.calculation_warnings_link', { count: calculationWarningCount(run) }) }}
          </button>
          <RouterLink
            v-if="run.hidden_validation_count"
            :to="{ name: 'payroll-settings', query: { tab: 'warnings' } }"
            class="inline-flex items-center gap-1 whitespace-nowrap text-neutral-500 underline decoration-dotted underline-offset-2 hover:text-neutral-700"
            :data-testid="`payroll-run-${run.id}-hidden-warnings`"
          >
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.eyeOff" /></svg>
            {{ t('payroll.runs.hidden_warnings_link', { count: run.hidden_validation_count }) }}
          </RouterLink>
        </div>

        <section
          v-if="sourceBlockerSteps(run).length"
          class="mt-4 rounded-lg border border-primary-200 bg-primary-50 p-4 text-sm text-neutral-800"
          :data-testid="`payroll-run-${run.id}-next-steps`"
        >
          <p class="font-semibold text-primary-800">{{ t('payroll.runs.continue_steps.title') }}</p>
          <p class="mt-1 max-w-prose text-neutral-700">{{ t(run.status === 'cancelled' ? 'payroll.runs.continue_steps.intro_cancelled' : 'payroll.runs.continue_steps.intro') }}</p>
          <ol class="mt-3 space-y-3">
            <li
              v-for="(step, index) in sourceBlockerSteps(run)"
              :key="step"
              class="flex flex-wrap items-center gap-x-3 gap-y-2"
              :data-testid="`payroll-run-${run.id}-next-step-${step}`"
            >
              <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-600 text-xs font-semibold text-white">{{ index + 1 }}</span>
              <span class="min-w-0 flex-1 basis-64">{{ t(`payroll.runs.continue_steps.${step}`) }}</span>
              <RouterLink
                v-if="step === 'time'"
                :to="{ name: 'payroll-time', query: { period: runPeriod(run), approve: 'all' } }"
                :class="[btnOutlineSm('success'), 'whitespace-nowrap']"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.badgeCheck" /></svg>
                {{ t('payroll.runs.continue_steps.time_action') }}
              </RouterLink>
              <button
                v-else-if="step === 'inputs' && canOverride"
                type="button"
                :class="[btnOutlineSm('success'), 'whitespace-nowrap']"
                :disabled="saving"
                @click="approveDraftInputs(run)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.badgeCheck" /></svg>
                {{ t('payroll.runs.continue_steps.inputs_action') }}
              </button>
              <RouterLink
                v-else-if="step === 'components'"
                :to="{ name: 'payroll-quick-inputs', query: { period: runPeriod(run) } }"
                :class="[btnOutlineSm('primary'), 'whitespace-nowrap']"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
                {{ t('payroll.runs.continue_steps.components_action') }}
              </RouterLink>
              <button
                v-else-if="step === 'statutory'"
                type="button"
                :class="[btnOutlineSm('primary'), 'whitespace-nowrap']"
                :data-testid="`payroll-run-${run.id}-next-step-statutory-action`"
                @click="showValidations(run)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.clipboardCheck" /></svg>
                {{ t('payroll.runs.continue_steps.statutory_action') }}
              </button>
            </li>
            <li
              v-if="sourceRefreshCommand(run)"
              class="flex flex-wrap items-center gap-x-3 gap-y-2"
              :data-testid="`payroll-run-${run.id}-next-step-refresh`"
            >
              <span class="inline-flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-primary-600 text-xs font-semibold text-white">{{ sourceBlockerSteps(run).length + 1 }}</span>
              <span class="min-w-0 flex-1 basis-64">{{ t('payroll.runs.continue_steps.refresh') }}</span>
              <button
                type="button"
                :class="[btnFilledSm('primary'), 'whitespace-nowrap']"
                :disabled="saving"
                @click="runCommand(run, sourceRefreshCommand(run)!)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
                {{ t(run.status === 'cancelled' ? 'payroll.runs.continue_steps.restart_action' : 'payroll.runs.continue_steps.refresh_action') }}
              </button>
            </li>
          </ol>
        </section>

        <div
          v-if="sectionValidations(run).length"
          class="mt-4 scroll-mt-24 space-y-2"
          :data-testid="`payroll-run-${run.id}-validations-section`"
        >
          <p
            v-if="calculationWarningsOpen[run.id] && calculationWarningCount(run)"
            class="text-xs text-neutral-500"
            :data-testid="`payroll-run-${run.id}-calculation-warnings-hint`"
          >
            {{ t('payroll.runs.calculation_warnings_hint') }}
          </p>
          <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="text-sm font-medium text-warning-700">{{ t('payroll.runs.validations') }}</p>
            <div
              v-if="blockingGroupCount(run) > 0"
              class="flex flex-wrap gap-2"
              role="group"
              :data-testid="`payroll-run-${run.id}-validation-scope`"
            >
              <button
                type="button"
                :class="[currentValidationScope(run) === 'blocking' ? btnFilledSm('danger') : btnOutlineSm('danger'), 'whitespace-nowrap']"
                :aria-pressed="currentValidationScope(run) === 'blocking'"
                :data-testid="`payroll-run-${run.id}-validation-scope-blocking`"
                @click="setValidationScope(run, 'blocking')"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.lock" />
                </svg>
                {{ t('payroll.runs.validation_scope.blocking', { count: approveBlockerCount(run) }) }}
              </button>
              <button
                type="button"
                :class="[currentValidationScope(run) === 'all' ? btnFilledSm('neutral') : btnOutlineSm('neutral'), 'whitespace-nowrap']"
                :aria-pressed="currentValidationScope(run) === 'all'"
                :data-testid="`payroll-run-${run.id}-validation-scope-all`"
                @click="setValidationScope(run, 'all')"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.table" />
                </svg>
                {{ t('payroll.runs.validation_scope.all', { count: sectionValidations(run).length }) }}
              </button>
            </div>
          </div>
          <p
            v-if="currentValidationScope(run) === 'blocking' && sectionValidations(run).length > approveBlockerCount(run)"
            class="text-xs text-neutral-500"
            :data-testid="`payroll-run-${run.id}-validation-scope-hint`"
          >
            {{ t('payroll.runs.validation_scope.hint', { count: sectionValidations(run).length - approveBlockerCount(run) }) }}
          </p>
          <!--
            Nesloučené validace chodí po osobách; u 226 lidí by jich tu viselo
            stovky. Sbalený seznam ukáže prvních pár, zbytek se stránkuje.
          -->
          <ExpandableList
            :items="visibleValidationGroups(run)"
            :item-key="validationKey"
            :search-text="validationSearchText"
            list-tag="div"
            :test-id="`payroll-run-${run.id}-validations`"
          >
          <template #item="{ item: validation }">
          <div
            :data-testid="`payroll-validation-${validation.id}`"
            :data-test="`payroll-validation-group-${validation.group_key}`"
            class="rounded-lg border px-3 py-2 text-sm"
            :class="validationClass(validation)"
          >
            <p class="flex flex-wrap items-baseline gap-x-2">
              <span>{{ validation.display_message }}</span>
              <span
                v-if="validation.override_items.length"
                class="whitespace-nowrap text-xs font-semibold"
                :data-test="`payroll-validation-${validation.id}-count`"
              >{{ validation.override_items.length }}×</span>
            </p>
            <p
              v-if="validation.entity_labels.length"
              class="mt-1 text-xs font-medium"
            >
              {{ entityLabelSummary(validation.entity_labels) }}
            </p>
            <ExpandableList
              v-if="validation.remediation_links.length"
              :items="validation.remediation_links"
              :item-key="remediationLinkKey"
              :search-text="remediationLinkSearchText"
              list-tag="div"
              list-class="mt-2 flex flex-wrap gap-2"
              :test-id="`payroll-validation-${validation.id}-links`"
            >
              <template #item="{ item: link }">
                <a
                  :href="link.path"
                  data-test="payroll-validation-remediation"
                  :class="[btnOutlineSm('neutral'), 'inline-flex']"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.link" />
                  </svg>
                  {{ t('payroll.runs.validation.open_remediation') }}
                  <span v-if="validation.remediation_links.length > 1 && link.label">: {{ link.label }}</span>
                </a>
              </template>
            </ExpandableList>
            <!--
              Trvalé skrytí varování: u osob skupiny jedním klikem, nebo celý
              typ ve firmě. Blokující chyby a výjimky sem nikdy nepatří.
            -->
            <div
              v-if="validation.hideable && canHideWarnings && !blocksApproval(validation)"
              class="mt-2 flex flex-wrap gap-2"
              :data-testid="`payroll-validation-${validation.id}-hide`"
            >
              <button
                v-if="validation.hide_subjects.length"
                type="button"
                :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
                :data-testid="`payroll-validation-${validation.id}-hide-people`"
                @click="askHide(validation.code, validation.display_message, 'people', validation.hide_subjects)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.eyeOff" /></svg>
                {{ t('payroll.runs.hide_warning_people', { count: validation.hide_subjects.length }, validation.hide_subjects.length) }}
              </button>
              <button
                type="button"
                :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
                :data-testid="`payroll-validation-${validation.id}-hide-type`"
                @click="askHide(validation.code, validation.display_message, 'type', [])"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.eyeOff" /></svg>
                {{ t('payroll.runs.hide_warning_type') }}
              </button>
            </div>
            <!--
              Zkratka přímo z běhu: odkaz výš vede tam, kde se koncepty
              schvalují po jednom, a to je u větší firmy stovky kliknutí.
            -->
            <button
              v-if="validation.code === 'draft_inputs_present' && canOverride"
              type="button"
              :data-testid="`payroll-validation-${validation.id}-approve-inputs`"
              :class="[btnOutlineSm('success'), 'mt-2 ml-2 inline-flex']"
              :disabled="saving"
              @click="approveDraftInputs(run)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.badgeCheck" />
              </svg>
              {{ t('payroll.runs.validation.draft_inputs_approve_all') }}
            </button>
            <!--
              Hromadné doplnění výchozí zákonné evidence. Kreslí se jednou
              u běhu (u první validace skupiny), ne u každé osoby zvlášť.
            -->
            <button
              v-if="canBulkDefaults && validation.id === firstStatutoryReviewId(run.validations)"
              type="button"
              :data-testid="`payroll-run-${run.id}-statutory-bulk`"
              class="whitespace-nowrap"
              :class="[btnOutlineSm('warning'), 'mt-2 mr-2 inline-flex']"
              :disabled="saving"
              @click="openBulkDefaults(run)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.clipboardCheck" />
              </svg>
              {{ bulkDefaultsLabel(run) }}
            </button>
            <!--
              Co dávka neschválila, zůstává na obrazovce. V toastu se to ztratilo
              dřív, než se podle toho dalo jednat, a znal se jen první důvod.
            -->
            <div
              v-if="validation.code === 'draft_inputs_present' && draftInputFailures[run.id]?.length"
              :data-test="`draft-inputs-failures-${run.id}`"
              class="mt-2 rounded-lg border border-danger-200 bg-danger-50 p-3 text-xs text-danger-700"
            >
              <p class="font-medium">{{ t('payroll.runs.validation.draft_inputs_failed_title') }}</p>
              <ul class="mt-1 space-y-0.5">
                <li
                  v-for="failure in draftInputFailures[run.id]"
                  :key="failure.message"
                  data-test="draft-inputs-failure-row"
                >
                  {{ failure.count > 1
                    ? t('payroll.runs.validation.draft_inputs_failed_row', {
                      count: failure.count,
                      reason: failure.message,
                    })
                    : failure.message }}
                </li>
              </ul>
              <button
                type="button"
                :class="[btnOutlineSm('neutral'), 'mt-2 inline-flex']"
                :data-test="`draft-inputs-failures-dismiss-${run.id}`"
                @click="dismissDraftInputFailures(run.id)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.x" />
                </svg>
                {{ t('common.close') }}
              </button>
            </div>

            <!--
              Skupina varování jednoho kódu: jedna věta, jedno tlačítko pro
              všechny a pod tím lidé. Jednotlivé schválení i odvolání zůstává
              u každé osoby v rozbaleném seznamu.
            -->
            <template v-if="validation.override_items.length">
              <div
                v-if="awaitsOverride(validation)"
                class="mt-2 flex flex-wrap items-center gap-2"
                :data-testid="`payroll-validation-${validation.id}-awaiting`"
              >
                <p class="flex-1 text-xs leading-snug">
                  {{ t('payroll.runs.override.awaiting_group') }}
                </p>
                <button
                  v-if="canOverride && overrideEditable(run)"
                  type="button"
                  :data-testid="`payroll-validation-${validation.id}-override-all`"
                  :class="[btnOutlineSm('warning'), 'whitespace-nowrap']"
                  :disabled="saving"
                  @click="askBulkOverride(run, validation)"
                >
                  <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path :d="ICONS.badgeCheck" />
                  </svg>
                  {{ t('payroll.runs.override.grant_all', { count: validation.override_items.length }) }}
                </button>
                <p
                  v-else-if="!canOverride"
                  :class="BTN_DISABLED_NOTE"
                  :data-testid="`payroll-validation-${validation.id}-no-permission`"
                >
                  {{ t('payroll.runs.override.no_permission') }}
                </p>
              </div>
              <div
                v-else
                class="mt-2 flex flex-wrap items-center gap-2"
                :data-testid="`payroll-validation-${validation.id}-resolved`"
              >
                <p class="flex-1 text-xs font-medium">
                  {{ t('payroll.runs.override.granted_group', { count: validation.override_items.length }) }}
                </p>
                <p
                  v-if="canOverride && !overrideEditable(run)"
                  :class="BTN_DISABLED_NOTE"
                  :data-testid="`payroll-validation-${validation.id}-locked`"
                >
                  {{ t('payroll.runs.override.locked_after_approval') }}
                </p>
              </div>
              <ExpandableList
                :items="validation.override_items"
                :item-key="overrideItemKey"
                :search-text="overrideItemSearchText"
                list-class="mt-2 space-y-1"
                :test-id="`payroll-validation-${validation.id}-people`"
              >
                <template #item="{ item }">
                  <div
                    class="flex flex-wrap items-center gap-2 rounded-md bg-surface/70 px-2.5 py-1.5"
                    :data-testid="`payroll-validation-${item.validation.id}-person`"
                  >
                    <span class="min-w-0 flex-1 text-xs font-medium">{{ item.label }}</span>
                    <a
                      v-if="item.remediation"
                      :href="item.remediation"
                      data-test="payroll-validation-remediation"
                      :class="[btnOutlineSm('neutral'), 'inline-flex whitespace-nowrap']"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="ICONS.link" />
                      </svg>
                      {{ t('payroll.runs.validation.open_remediation') }}
                    </a>
                    <button
                      v-if="canOverride && overrideEditable(run) && awaitsOverride(item.validation)"
                      type="button"
                      :data-testid="`payroll-validation-${item.validation.id}-override`"
                      :class="[btnOutlineSm('warning'), 'whitespace-nowrap']"
                      :disabled="saving"
                      @click="askOverride(run, item.validation)"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="ICONS.badgeCheck" />
                      </svg>
                      {{ t('payroll.runs.override.grant') }}
                    </button>
                    <button
                      v-else-if="canOverride && overrideEditable(run) && item.validation.overridden_at"
                      type="button"
                      :data-testid="`payroll-validation-${item.validation.id}-revoke`"
                      :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']"
                      :disabled="saving"
                      @click="revokeOverride(run, item.validation)"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="ICONS.uturn" />
                      </svg>
                      {{ t('payroll.runs.override.revoke') }}
                    </button>
                    <p v-if="item.validation.overridden_at" class="basis-full text-xs leading-snug">
                      {{ overrideAuthorLabel(item.validation) }}
                      {{ t('payroll.runs.override.reason_label', { reason: item.validation.override_reason }) }}
                    </p>
                  </div>
                </template>
              </ExpandableList>
            </template>

            <!--
              Varování, které čeká na člověka. Bez téhle věty uživatel vidí jen
              nálepku a netuší, že právě ona drží celý běh.
            -->
            <div
              v-else-if="awaitsOverride(validation)"
              class="mt-2 flex flex-wrap items-center gap-2"
              :data-testid="`payroll-validation-${validation.id}-awaiting`"
            >
              <p class="flex-1 text-xs leading-snug">
                {{ t('payroll.runs.override.awaiting') }}
              </p>
              <button
                v-if="canOverride && overrideEditable(run)"
                type="button"
                :data-testid="`payroll-validation-${validation.id}-override`"
                :class="btnOutlineSm('warning')"
                :disabled="saving"
                @click="askOverride(run, validation)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.badgeCheck" />
                </svg>
                {{ t('payroll.runs.override.grant') }}
              </button>
              <p
                v-else-if="!canOverride"
                :class="BTN_DISABLED_NOTE"
                :data-testid="`payroll-validation-${validation.id}-no-permission`"
              >
                {{ t('payroll.runs.override.no_permission') }}
              </p>
            </div>

            <!-- Vyřešené varování: kdo a s jakým odůvodněním. -->
            <div
              v-else-if="validation.overridden_at"
              class="mt-2 flex flex-wrap items-start gap-2 rounded-md bg-surface/70 px-2.5 py-2"
              :data-testid="`payroll-validation-${validation.id}-resolved`"
            >
              <div class="flex-1 space-y-0.5">
                <p class="text-xs font-medium">{{ overrideAuthorLabel(validation) }}</p>
                <p class="text-xs leading-snug">
                  {{ t('payroll.runs.override.reason_label', { reason: validation.override_reason }) }}
                </p>
              </div>
              <button
                v-if="canOverride && overrideEditable(run)"
                type="button"
                :data-testid="`payroll-validation-${validation.id}-revoke`"
                :class="btnOutlineSm('neutral')"
                :disabled="saving"
                @click="revokeOverride(run, validation)"
              >
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                  <path :d="ICONS.uturn" />
                </svg>
                {{ t('payroll.runs.override.revoke') }}
              </button>
              <p
                v-else-if="canOverride"
                :class="BTN_DISABLED_NOTE"
                :data-testid="`payroll-validation-${validation.id}-locked`"
              >
                {{ t('payroll.runs.override.locked_after_approval') }}
              </p>
            </div>
          </div>
          </template>
          </ExpandableList>
        </div>
      </article>

      <PaginationBar
        data-testid="payroll-runs-pagination"
        :page="currentPage"
        :per-page="pageSize"
        :total="total"
        @update:page="goToPage"
      />
    </section>

    <!--
      „Opravdu zahájit?" — poslední místo, kde jde couvnout, než se snímek
      vstupů zmrazí. Dialog NENÍ brána: nálezy jsou často věci, o kterých
      účetní ví a přesto chce počítat, tak má „Přesto zahájit" jako plnou
      akci. „Zkontrolovat znovu" je tu pro případ, že mezitím vadu opravila.
    -->
    <Modal
      v-if="pendingStart"
      :title="t('payroll.runs.readiness.confirm_title')"
      width-class="max-w-2xl"
      @close="pendingStart = null"
    >
      <div class="space-y-4" data-test="run-readiness-dialog">
        <p class="text-sm text-neutral-700">
          {{ t('payroll.runs.readiness.confirm_intro') }}
        </p>
        <div
          v-if="readinessSourceSteps.length"
          class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          data-test="run-readiness-approval-steps"
        >
          <p class="font-semibold">{{ t('payroll.runs.readiness.approval_first') }}</p>
          <div class="mt-2 flex flex-wrap gap-2">
            <RouterLink
              v-if="readinessSourceSteps.includes('time')"
              :to="{ name: 'payroll-time', query: { period: runPeriod(pendingStart.run), approve: 'all' } }"
              :class="[btnOutlineSm('success'), 'whitespace-nowrap']"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.badgeCheck" /></svg>
              {{ t('payroll.runs.continue_steps.time_action') }}
            </RouterLink>
            <button
              v-if="readinessSourceSteps.includes('inputs') && canOverride"
              type="button"
              :class="[btnOutlineSm('success'), 'whitespace-nowrap']"
              :disabled="saving"
              @click="approveDraftInputsBeforeStart(pendingStart.run)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.badgeCheck" /></svg>
              {{ t('payroll.runs.continue_steps.inputs_action') }}
            </button>
            <RouterLink
              v-if="readinessSourceSteps.includes('components')"
              :to="{ name: 'payroll-quick-inputs', query: { period: runPeriod(pendingStart.run) } }"
              :class="[btnOutlineSm('primary'), 'whitespace-nowrap']"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
              {{ t('payroll.runs.continue_steps.components_action') }}
            </RouterLink>
            <RouterLink
              v-if="readinessSourceSteps.includes('statutory')"
              :to="{ name: 'payroll-people', query: { filter: 'blocking_data', period: runPeriod(pendingStart.run) } }"
              :class="[btnOutlineSm('primary'), 'whitespace-nowrap']"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.clipboardCheck" /></svg>
              {{ t('payroll.runs.continue_steps.statutory_people_action') }}
            </RouterLink>
          </div>
        </div>
        <!--
          Dialog není seznam chyb, ale SOUPIS TOHO, CO JEŠTĚ ČEKÁ. Napřed to,
          co po zamknutí vstupů půjde opravit jen přes opravnou revizi — jen
          u toho má rozhodnutí „zahájit teď" následek.
        -->
        <div v-for="group in readinessGroups" :key="group.impact" class="space-y-2">
          <h4 class="text-xs font-semibold uppercase tracking-wide text-neutral-500">
            {{ t(`payroll.runs.readiness.impact.${group.impact}`) }}
          </h4>
          <ul class="space-y-2">
            <li
              v-for="finding in group.findings"
              :key="finding.code"
              class="rounded-lg border p-3 text-sm"
              :class="findingClass(finding)"
            >
              <p>
                <span v-if="findingCount(finding) > 1" class="font-semibold">{{ findingCount(finding) }}× </span>{{ readinessFindingMessage(finding) }}
              </p>
              <p v-if="finding.requires_override" class="mt-1 text-xs">
                {{ t('payroll.runs.override.awaiting') }}
              </p>
              <p v-if="findingEntityLabels(finding).length" class="mt-1 text-xs font-semibold">
                {{ t('payroll.runs.readiness.affected', {
                  names: entityLabelSummary(findingEntityLabels(finding), findingNotListed(finding)),
                }) }}
              </p>
              <ExpandableList
                v-if="finding.entities.some(entity => entity.message || entity.remediation_path)"
                class="mt-2"
                :items="finding.entities"
                :item-key="readinessEntityKey"
                :search-text="readinessEntitySearchText"
                :total="finding.entity_total ?? null"
                :test-id="`run-readiness-dialog-entities-${finding.code}`"
              >
                <template #item="{ item: entity }">
                  <p v-if="entity.message && entity.message !== finding.message">{{ entity.message }}</p>
                  <a v-if="entity.remediation_path" :href="readinessHref(entity.remediation_path)" :class="[btnOutlineSmWrap('neutral'), 'mt-1 max-w-full text-left']" data-test="run-readiness-entity-link">
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.link" /></svg>
                    {{ t('payroll.runs.validation.open_remediation') }}<span v-if="entity.label">: {{ entity.label }}</span>
                  </a>
                </template>
              </ExpandableList>
              <a v-if="finding.remediation_path && !finding.entities.some(entity => entity.remediation_path)" :href="readinessHref(finding.remediation_path)" :class="[btnOutlineSm('neutral'), 'mt-2 inline-flex']">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.link" /></svg>
                {{ t('payroll.runs.validation.open_remediation') }}
              </a>
            </li>
          </ul>
        </div>
        <p class="text-sm text-neutral-500">
          {{ t('payroll.runs.readiness.confirm_note') }}
        </p>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="pendingStart = null">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button
            type="button"
            class="cursor-pointer"
            :class="btnOutline('neutral')"
            :disabled="saving || loading"
            data-test="run-readiness-recheck"
            @click="recheckReadiness()"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
            {{ t('payroll.runs.readiness.recheck') }}
          </button>
          <button
            type="button"
            class="cursor-pointer"
            :class="readinessSourceSteps.length ? btnOutline('primary') : btnFilled('primary')"
            :disabled="saving"
            data-test="confirm-run-readiness"
            @click="confirmStart()"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
            {{ t('payroll.runs.readiness.start_anyway') }}
          </button>
        </div>
      </div>
    </Modal>

    <Modal
      v-if="pendingCommand"
      :title="commandLabel(pendingCommand.command, pendingCommand.run)"
      width-class="max-w-lg"
      @close="pendingCommand = null"
    >
      <form class="space-y-4" data-test="run-command-dialog" @submit.prevent="confirmCommand">
        <p
          v-if="pendingCommand.command === 'cancel'"
          class="text-sm text-neutral-700"
          data-test="run-cancel-hint"
        >
          {{ pendingCommand.run.revision_kind === 'correction'
            ? t('payroll.runs.cancel_correction_hint')
            : t('payroll.runs.cancel_hint') }}
        </p>
        <label class="block text-sm font-medium text-neutral-700">
          {{ commandNeedsReason(pendingCommand.run, pendingCommand.command)
            ? t('payroll.runs.reason_prompt')
            : t('payroll.runs.reason_prompt_optional') }}
          <textarea
            v-model="commandReason"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
            :class="commandNeedsReason(pendingCommand.run, pendingCommand.command) ? 'min-h-24' : 'min-h-12'"
            :required="commandNeedsReason(pendingCommand.run, pendingCommand.command)"
            autofocus
            data-test="run-command-reason"
          />
        </label>
        <p
          v-if="commandError"
          class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
          data-test="run-command-error"
        >
          {{ commandError }}
        </p>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="pendingCommand = null">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevronLeft" /></svg>
            {{ t('common.close') }}
          </button>
          <button
            type="submit"
            class="cursor-pointer"
            :class="commandClass(pendingCommand.run, pendingCommand.command)"
            :disabled="saving"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="commandIcon(pendingCommand.command)" /></svg>
            {{ commandLabel(pendingCommand.command, pendingCommand.run) }}
          </button>
        </div>
      </form>
    </Modal>

    <Modal
      v-if="pendingOverride"
      :title="pendingOverride.group
        ? t('payroll.runs.override.grant_all', { count: pendingGroupIds(pendingOverride.group).length })
        : t('payroll.runs.override.grant')"
      width-class="max-w-xl"
      @close="pendingOverride = null"
    >
      <form class="space-y-4" data-test="run-override-dialog" @submit.prevent="confirmOverride">
        <p class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-sm text-warning-800">
          {{ pendingOverride.group
            ? pendingOverride.group.display_message
            : validationDisplayMessage(pendingOverride.validation) }}
        </p>
        <p
          v-if="pendingOverride.group"
          class="text-xs leading-snug text-neutral-600"
          data-test="run-override-bulk-scope"
        >
          {{ t('payroll.runs.override.bulk_scope', { count: pendingGroupIds(pendingOverride.group).length }) }}
        </p>
        <label class="block text-sm font-medium text-neutral-700">
          {{ t('payroll.runs.override.reason_prompt') }}
          <textarea
            v-model="overrideReason"
            class="mt-1 min-h-24 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
            required
            autofocus
            data-test="run-override-reason"
          />
        </label>
        <p class="text-xs leading-snug text-neutral-500" data-test="run-override-hint">
          {{ t('payroll.runs.override.reason_hint') }}
        </p>
        <p
          v-if="overrideError"
          class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
          data-test="run-override-error"
        >
          {{ overrideError }}
        </p>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="pendingOverride = null">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button
            type="submit"
            :class="btnFilled('warning')"
            :disabled="saving"
            data-test="confirm-run-override"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.badgeCheck" /></svg>
            {{ pendingOverride.group
              ? t('payroll.runs.override.grant_all', { count: pendingGroupIds(pendingOverride.group).length })
              : t('payroll.runs.override.grant') }}
          </button>
        </div>
      </form>
    </Modal>

    <PayrollHideWarningDialog
      v-if="pendingHide"
      :code="pendingHide.code"
      :message="pendingHide.message"
      :scope="pendingHide.scope"
      :subjects="pendingHide.subjects"
      @close="pendingHide = null"
      @hidden="onWarningHidden"
    />

    <PayrollStatutoryBulkDefaultsDialog
      v-if="bulkDefaultsRun"
      :effective-on="bulkDefaultsRun.period_start"
      :employee-ids="null"
      @close="bulkDefaultsRun = null"
      @applied="onBulkDefaultsApplied"
    >
      <template #after-apply>
        <div
          v-if="bulkDefaultsApplied"
          class="rounded-lg border border-primary-500/30 bg-primary-50 p-3 text-sm text-primary-800"
          data-test="statutory-bulk-refresh"
        >
          <p>{{ t(`payroll.runs.validation.statutory_bulk_refresh.${bulkDefaultsRefreshCommand ?? 'none'}`) }}</p>
          <button
            v-if="bulkDefaultsRefreshCommand"
            type="button"
            class="mt-2 cursor-pointer"
            :class="commandClass(bulkDefaultsRun, bulkDefaultsRefreshCommand)"
            :disabled="saving"
            data-test="statutory-bulk-refresh-command"
            @click="continueAfterBulkDefaults"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="commandIcon(bulkDefaultsRefreshCommand)" /></svg>
            {{ commandLabel(bulkDefaultsRefreshCommand, bulkDefaultsRun) }}
          </button>
        </div>
      </template>
    </PayrollStatutoryBulkDefaultsDialog>

    <Modal
      v-if="pendingApprove"
      :title="t('payroll.runs.approve_confirm.title', { period: formatPeriod(pendingApprove.period_start.slice(0, 7)) })"
      width-class="max-w-xl"
      @close="pendingApprove = null"
    >
      <div class="space-y-3 text-sm text-neutral-700" data-test="run-approve-dialog">
        <p>{{ t('payroll.runs.approve_confirm.intro') }}</p>
        <ul class="list-disc space-y-1 pl-5" data-test="run-approve-locks">
          <li v-for="key in APPROVE_LOCK_KEYS" :key="key">{{ t(`payroll.runs.approve_confirm.locks.${key}`) }}</li>
        </ul>
        <p
          v-if="periodTiming(pendingApprove) !== 'finished'"
          class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-warning-800"
          data-test="run-approve-period-timing"
        >
          {{ t(`payroll.runs.approve_confirm.period_${periodTiming(pendingApprove)}`, {
            start: formatDate(pendingApprove.period_start),
            end: formatDate(periodEndDate(pendingApprove)),
          }) }}
        </p>
        <p class="text-xs text-neutral-500">{{ t('payroll.runs.approve_confirm.undo') }}</p>
      </div>
      <div class="mt-5 flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" @click="pendingApprove = null">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button
          type="button"
          :class="btnFilled('success')"
          :disabled="saving"
          data-test="confirm-run-approve"
          @click="confirmApprove"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
          {{ commandLabel('approve', pendingApprove) }}
        </button>
      </div>
    </Modal>

    <Modal
      v-if="pendingDelete"
      :title="t('payroll.runs.delete')"
      width-class="max-w-lg"
      @close="pendingDelete = null"
    >
      <p class="text-sm text-neutral-700">{{ t('payroll.runs.delete_confirm') }}</p>
      <div class="mt-5 flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" @click="pendingDelete = null">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button
          type="button"
          :class="btnFilled('danger')"
          :disabled="saving"
          data-test="confirm-delete-run"
          @click="deleteRun"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
          {{ t('payroll.runs.delete') }}
        </button>
      </div>
    </Modal>
  </div>
</template>
