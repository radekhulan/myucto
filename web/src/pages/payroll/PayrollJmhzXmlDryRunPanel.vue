<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { personalNumberLabel } from './employmentLifecycleUi'
import type { RouteLocationRaw } from 'vue-router'
import { apiErrorMessage } from '@/api/errors'
import {
  payrollApi,
  type PayrollJmhzControlFinding,
  type PayrollJmhzControlReport,
  type PayrollJmhzDeferralContext,
  type PayrollJmhzPvpojOffice,
  type PayrollJmhzXmlDryRun,
  type PayrollJmhzXmlDryRunBlocker,
  type PayrollRun,
} from '@/api/payroll'
import {
  payrollAbsenceApi,
  type PayrollAbsenceEmployment,
} from '@/api/payrollAbsences'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'
import { formatDate, formatPeriod } from '@/composables/useFormat'
import { averageEarningsTarget } from './payrollRemediation'
import {
  jmhzBlockerLabel,
  jmhzErrorMessage,
  jmhzRemediationKind,
  jmhzRemediationTarget,
  ANNUAL_REQUEST_CODES,
  annualSettlementRequestTarget,
} from './jmhzBlockerRemediation'
import PayrollWorkplaceBulkFillDialog from '@/components/payroll/PayrollWorkplaceBulkFillDialog.vue'
import PayrollJmhzDeferralList from './PayrollJmhzDeferralList.vue'
import JmhzBulkDefer from './JmhzBulkDefer.vue'

const workplaceBulkDialogRun = ref<PayrollRun | null>(null)

const props = withDefaults(defineProps<{
  runs: PayrollRun[]
  /** Prostředí, ve kterém se odložené vztahy doplňují opravným hlášením. */
  environment?: 'test' | 'production'
}>(), { environment: 'production' })

interface DryRunState {
  running: boolean
  error: string
  result: PayrollJmhzXmlDryRun | null
  showXml: boolean
}

const { t, te, locale } = useI18n()
const auth = useAuthStore()
const canWrite = computed(() => auth.canWrite('payroll.submissions'))
const states = ref<Record<number, DryRunState>>({})
/**
 * Registrace u OSSZ, za které se z revize podává. Měsíční hlášení je podání
 * ZA REGISTRACI, takže test nad během přes víc mzdových účtáren musí vědět,
 * kterou z nich zkouší — jinak by se lidé jedné účtárny vykázali pod cizím
 * variabilním symbolem.
 */
const offices = ref<Record<number, PayrollJmhzPvpojOffice[]>>({})
const selectedOffice = ref<Record<number, number | null>>({})
const remediationEmployments = ref<PayrollAbsenceEmployment[]>([])
/**
 * Mzdové složky se načítají kvůli JEDINÉ věci: aby nález uměl složku
 * pojmenovat. „Mzdová složka nemá určené zařazení do JMHZ" bez názvu posílá
 * účetní hádat mezi patnácti definicemi — a když se u jiné složky v seznamu
 * zařazení ukazuje, vypadá to, že aplikace lže.
 */
const remediationComponents = ref<Record<number, string>>({})
const remediationContextRequested = ref(false)

// Hlídá se výčet revizí, ne celé běhy: `deep` procházel při každé změně
// i výsledek mzdy všech lidí, přitom registrace závisí jen na revizi.
watch(() => [props.runs, props.runs.map(item => revisionId(item) ?? `run-${item.id}`).join(',')] as const, async ([runs]) => {
  const loaded: Record<number, PayrollJmhzPvpojOffice[]> = {}
  const selected: Record<number, number | null> = {}
  const ids = runs
    .map(revisionId)
    .filter((id): id is number => id !== null)
  const responses = await Promise.allSettled(
    ids.map(id => payrollApi.jmhzPvpojOffices(id)),
  )
  responses.forEach((response, index) => {
    const id = ids[index]!
    const rows = response.status === 'fulfilled' ? response.value : []
    loaded[id] = rows
    const submittable = rows.filter(office => office.submittable)
    selected[id] = submittable.length === 1
      ? submittable[0]!.office_id
      : selectedOffice.value[id] ?? null
  })
  offices.value = loaded
  selectedOffice.value = selected
}, { immediate: true })

function revisionId(run: PayrollRun): number | null {
  return run.revision_id && run.revision_id > 0 ? run.revision_id : null
}

function officeOptions(run: PayrollRun): PayrollJmhzPvpojOffice[] {
  const id = revisionId(run)
  return id === null ? [] : offices.value[id] ?? []
}

function submittableOffices(revision: number): PayrollJmhzPvpojOffice[] {
  return (offices.value[revision] ?? []).filter(office => office.submittable)
}

async function ensureOffices(revision: number): Promise<void> {
  if (offices.value[revision] !== undefined) return
  const rows = await payrollApi.jmhzPvpojOffices(revision).catch(() => [])
  offices.value = { ...offices.value, [revision]: rows }
  const submittable = rows.filter(office => office.submittable)
  if (selectedOffice.value[revision] == null && submittable.length === 1) {
    selectedOffice.value = {
      ...selectedOffice.value,
      [revision]: submittable[0]!.office_id,
    }
  }
}

async function ensureRemediationContext(): Promise<void> {
  if (remediationContextRequested.value) return
  remediationContextRequested.value = true
  const [employments, components] = await Promise.all([
    payrollAbsenceApi.context().catch(() => []),
    payrollApi.components().catch(() => []),
  ])
  remediationEmployments.value = employments
  const named: Record<number, string> = {}
  for (const component of components) {
    named[component.id] = `${component.name} (${component.code})`
  }
  remediationComponents.value = named
}

function state(run: PayrollRun): DryRunState | null {
  const id = revisionId(run)
  return id === null ? null : states.value[id] ?? null
}

async function run(payrollRun: PayrollRun) {
  const id = revisionId(payrollRun)
  if (id === null || !canWrite.value) return
  const current: DryRunState = {
    running: true,
    error: '',
    result: null,
    showXml: false,
  }
  states.value = { ...states.value, [id]: current }
  try {
    // Registrace se mohou ještě načítat — spustit test dřív, než je známý
    // seznam účtáren, by u víceúčtárenské revize znamenalo nacvičit naslepo.
    await ensureOffices(id)
    if (submittableOffices(id).length > 1 && selectedOffice.value[id] == null) {
      states.value[id].error = t(
        'payroll.submissions.overview.jmhz_social_multiple_offices',
      )
      return
    }
    const preparation = await payrollApi.freezeJmhzPreparation(
      id,
      crypto.randomUUID(),
      'test',
    )
    const result = await payrollApi.jmhzXmlDryRun(
      preparation.id,
      'test',
      selectedOffice.value[id] ?? null,
    )
    states.value[id].result = result
    if ((result.status === 'blocked'
      && result.blockers.some(blocker => blocker.entity_id !== null))
      || (result.deferred?.employment_ids.length ?? 0) > 0
    ) {
      await ensureRemediationContext()
    }
  } catch (exception) {
    states.value[id].error = apiErrorMessage(
      exception,
      t('payroll.submissions.overview.jmhz_dry_run_failed'),
    )
  } finally {
    states.value[id].running = false
  }
}

/**
 * Co je špatně. Popisek z katalogu kódů; bez něj věta serveru, nikdy
 * „neznámá blokace" s odkazem na podporu, když server příčinu zná.
 */
function blockerLabel(blocker: PayrollJmhzXmlDryRunBlocker): string {
  return jmhzBlockerLabel(t, te, blocker, locale.value)
}

/**
 * Kde a jak to opravit. Česky nese podrobný krok server; v jiném jazyce se
 * použije přeložený krok podle druhu nápravy.
 */
function blockerStep(blocker: PayrollJmhzXmlDryRunBlocker): string {
  if (locale.value === 'cs' && blocker.action) return blocker.action
  // Nález, u kterého obecný krok druhu nápravy radí špatně (např. nevyhlášený
  // číselník obcí: „podejte ručně přes ePortál" by bylo zavádějící).
  const codeStep = `payroll.jmhz_gate.code_steps.${blocker.code}`
  if (te(codeStep)) return t(codeStep)
  return t(`payroll.jmhz_gate.steps.${jmhzRemediationKind(blocker)}`)
}

interface BlockerGroup {
  key: string
  blocker: PayrollJmhzXmlDryRunBlocker
  count: number
  entityIds: number[]
}

function groupedBlockers(blockers: PayrollJmhzXmlDryRunBlocker[]): BlockerGroup[] {
  const groups = new Map<string, BlockerGroup>()
  for (const blocker of blockers) {
    const key = JSON.stringify([
      blocker.code,
      blocker.entity_type,
      [...blocker.attribute_ids].sort(),
    ])
    const current = groups.get(key)
    if (current) {
      current.count++
      if (blocker.entity_id !== null
        && !current.entityIds.includes(blocker.entity_id)
      ) {
        current.entityIds.push(blocker.entity_id)
      }
    } else {
      groups.set(key, {
        key,
        blocker,
        count: 1,
        entityIds: blocker.entity_id === null ? [] : [blocker.entity_id],
      })
    }
  }
  return [...groups.values()]
}

/**
 * Nálezy, které se řeší v panelu „Identifikátory přidělené ČSSZ pro JMHZ".
 *
 * Obecný doskok na pracovní vztah mířil do sjednaných podmínek, kde tahle dvě
 * pole nejsou — účetní tam přišla a nenašla nic. Povel `?panel=&field=`
 * doskočí přímo na vstup (viz `web/src/utils/revealField.ts`).
 */
const JMHZ_IDENTITY_CODES = [
  'jmhz_identity_incomplete',
  'jmhz_identity_oic_missing',
  'jmhz_identity_id_ppv_missing',
] as const

function identityTarget(
  blocker: PayrollJmhzXmlDryRunBlocker,
  entityId: number | null,
): RouteLocationRaw | null {
  if (!(JMHZ_IDENTITY_CODES as readonly string[]).includes(blocker.code)) {
    return null
  }
  const employment = entityId === null
    ? undefined
    : remediationEmployments.value.find(item => item.id === entityId)
  const field = blocker.code === 'jmhz_identity_id_ppv_missing'
    ? 'jmhz.employment_external_identifier'
    : 'jmhz.person_external_identifier'

  return {
    name: 'payroll-people',
    query: {
      ...(entityId === null ? {} : { employment: String(entityId) }),
      ...(employment === undefined
        ? {}
        : { person: String(employment.employee_id) }),
      panel: 'jmhz_identity',
      field,
    },
  }
}

function blockerTarget(
  blocker: PayrollJmhzXmlDryRunBlocker,
  entityId: number | null = blocker.entity_id,
  payrollRun?: PayrollRun,
): RouteLocationRaw | null {
  const periodQuery = payrollRun ? { period: payrollRun.period_start.slice(0, 7) } : {}
  const identity = identityTarget(blocker, entityId)
  if (identity !== null) return identity
  if (blocker.code === 'jmhz_primary_employment_unresolved') {
    // Hlavní vztah se volí na kartě vztahu. Nález míří na osobu, a u souběhu
    // by bez konkrétního vztahu doskok nevěděl, kterou kartu otevřít.
    const employment = entityId === null
      ? undefined
      : blocker.entity_type === 'employment'
        ? remediationEmployments.value.find(item => item.id === entityId)
        : remediationEmployments.value.find(item => item.employee_id === entityId)
    const scope = employment !== undefined
      ? { person: String(employment.employee_id), employment: String(employment.id) }
      : entityId === null
        ? {}
        : blocker.entity_type === 'employment'
          ? { employment: String(entityId) }
          : { person: String(entityId) }
    return { name: 'payroll-people', query: { ...scope, panel: 'employment_terms', field: 'is_primary' } }
  }
  if (['effective_term_missing', 'jmhz_scenario_activity_code_missing', 'jmhz_employer_part_time_discount_reason_missing', 'jmhz_employer_part_time_discount_working_time_missing', 'jmhz_temporary_assignment_user_missing', 'jmhz_risk_categorization_missing'].includes(blocker.code)) {
    const field = blocker.code === 'jmhz_scenario_activity_code_missing' ? 'activity_code'
      : blocker.code === 'jmhz_employer_part_time_discount_reason_missing' ? 'social_part_time_discount_reason'
        : blocker.code === 'jmhz_employer_part_time_discount_working_time_missing' ? 'weekly_hours'
          : blocker.code === 'jmhz_temporary_assignment_user_missing' ? 'jmhz_assignment_user_kind'
            : blocker.code === 'jmhz_risk_categorization_missing' ? 'jmhz_risk_categorization_code' : undefined
    return { name: 'payroll-people', query: { ...(entityId === null ? {} : { employment: String(entityId) }), panel: 'employment_terms', ...(field ? { field } : {}) } }
  }
  if ([
    'jmhz_attribute_10116_unresolved',
    'jmhz_attribute_10546_unresolved',
    'jmhz_interaction_in13_unresolved',
    'jmhz_interaction_in28_unresolved',
    'jmhz_interaction_in30_unresolved',
    'jmhz_ordinary_evidence_missing',
  ].includes(blocker.code)) {
    return { name: 'payroll-submissions', query: periodQuery, hash: '#jmhz-ordinary-evidence' }
  }
  if (blocker.code === 'jmhz_average_hourly_earning_missing') {
    if (entityId !== null && payrollRun) return averageEarningsTarget(entityId, Number(payrollRun.period_start.slice(0, 4)), Math.ceil(Number(payrollRun.period_start.slice(5, 7)) / 3))
    if (entityId === null) {
      // Přes 10 vztahů = agendový doskok bez konkrétní osoby; rovnou na
      // hromadnou sekci pro dané čtvrtletí, ne na jednotlivý formulář.
      const quarterQuery = payrollRun
        ? {
            year: payrollRun.period_start.slice(0, 4),
            quarter: String(Math.ceil(Number(payrollRun.period_start.slice(5, 7)) / 3)),
          }
        : {}
      return { name: 'payroll-absences', query: { tab: 'averages', ...quarterQuery } }
    }
    return {
      name: 'payroll-absences',
      query: { employment: String(entityId), tab: 'averages' },
    }
  }
  if (blocker.code === 'jmhz_work_month_not_approved') {
    return entityId === null
      ? { name: 'payroll-time', query: periodQuery }
      : { name: 'payroll-time', query: { employment: String(entityId), ...periodQuery } }
  }
  if (blocker.code === 'jmhz_scenario1_earnings_vector_incomplete') {
    return { name: 'payroll-components' }
  }
  if (blocker.code === 'jmhz_scenario1_whole_czk_required') {
    // Haléřová částka vzniká ve výsledku běhu, ne v identitě osoby — proklik
    // musí mířit na Mzdové běhy, ne na kartu zaměstnance.
    return { name: 'payroll-runs', query: periodQuery }
  }
  // Ostatní kódy nese server s druhem nápravy (JmhzBlockerCatalog).
  return jmhzRemediationTarget(blocker, entityId, {
    period: payrollRun ? payrollRun.period_start.slice(0, 7) : null,
    employments: remediationEmployments.value,
  })
}

function blockerActionLabel(blocker: PayrollJmhzXmlDryRunBlocker): string {
  const codeKey = `payroll.submissions.overview.jmhz_dry_run_action_codes.${blocker.code}`
  if (te(codeKey)) return t(codeKey)
  return t(`payroll.jmhz_gate.remediation.${jmhzRemediationKind(blocker)}`)
}

function blockerUsesSharedTarget(code: string): boolean {
  return [
    'jmhz_attribute_10116_unresolved',
    'jmhz_attribute_10546_unresolved',
    'jmhz_interaction_in13_unresolved',
    'jmhz_interaction_in28_unresolved',
    'jmhz_interaction_in30_unresolved',
    'jmhz_ordinary_evidence_missing',
    'jmhz_scenario1_earnings_vector_incomplete',
  ].includes(code)
}

function blockerUsesAgendaTarget(group: BlockerGroup): boolean {
  return blockerUsesSharedTarget(group.blocker.code)
    || ANNUAL_REQUEST_CODES.includes(group.blocker.code)
    || group.entityIds.length > 10
}

/**
 * Nerozhodnutá žádost o roční zúčtování se řeší hromadně: Roční zúčtování
 * za minulý rok zúžené na nerozhodnuté osoby tohoto hlášení, všechny vybrané.
 * Dřív odkaz otevřel stránku bez filtru a dotčené osoby se hledaly ručně.
 */
function annualRequestGroupTarget(group: BlockerGroup, payrollRun: PayrollRun): RouteLocationRaw {
  const employeeIds = group.blocker.entity_type === 'employment'
    ? group.entityIds
      .map(id => remediationEmployments.value.find(item => item.id === id)?.employee_id ?? null)
      .filter((id): id is number => id !== null)
    : group.entityIds
  const here = typeof window === 'undefined' ? null : `${window.location.pathname}${window.location.search}`
  return annualSettlementRequestTarget(payrollRun.period_start.slice(0, 7), employeeIds, true, here)
}

function blockerGroupTarget(group: BlockerGroup, payrollRun: PayrollRun): RouteLocationRaw | null {
  if (ANNUAL_REQUEST_CODES.includes(group.blocker.code)) return annualRequestGroupTarget(group, payrollRun)
  return blockerUsesAgendaTarget(group)
    ? blockerTarget(group.blocker, null, payrollRun)
    : blockerTarget(group.blocker, group.blocker.entity_id, payrollRun)
}

/**
 * Jména dotčených záznamů — JMENOVITĚ, i když je dotčený jediný.
 *
 * Nález, který nejmenuje konkrétní věc, je k nepoužití: „Mzdová složka nemá
 * zařazení" pošle účetní na seznam patnácti složek hádat, která to je, a
 * „Chybí identifikační údaj" neřekne, o kterého zaměstnance jde. Dřív se jména
 * ukazovala jen u skupin s víc než jedním záznamem, a to je přesně naopak, než
 * jak to člověk potřebuje.
 */
function groupLabels(group: BlockerGroup): string[] {
  return group.entityIds
    .slice(0, 10)
    .map((entityId, index) => remediationLabel(group.blocker, entityId, index))
}

/**
 * Lidský popis čísla pole ČSSZ, když ho známe.
 *
 * Klíč `payroll.submissions.overview.jmhz_attribute_glossary.<id>`; co v něm
 * není, zůstane holým číslem — vymyslet popis by bylo horší než ho nemít.
 */
function attributeGloss(attributeId: string): string {
  const key = `payroll.submissions.overview.jmhz_attribute_glossary.${attributeId}`
  const translated = t(key)

  return translated === key ? '' : translated
}

function entityIdPreview(entityIds: number[]): string {
  const visible = entityIds.slice(0, 10).join(', ')
  return entityIds.length > 10 ? `${visible}, …` : visible
}

function remediationLabel(
  blocker: PayrollJmhzXmlDryRunBlocker,
  entityId: number,
  index: number,
): string {
  if (blocker.entity_type === 'component') {
    return remediationComponents.value[entityId]
      ?? t('payroll.submissions.overview.jmhz_dry_run_actions.record', {
        number: index + 1,
      })
  }
  const employment = blocker.entity_type === 'employment'
    ? remediationEmployments.value.find(item => item.id === entityId)
    : ['person', 'employee'].includes(blocker.entity_type)
      ? remediationEmployments.value.find(item => item.employee_id === entityId)
      : undefined
  if (employment !== undefined) {
    return blocker.entity_type === 'employment'
      ? [employment.full_name, personalNumberLabel(t, employment.code)].filter(part => part !== '').join(' · ')
      : employment.full_name
  }
  return t('payroll.submissions.overview.jmhz_dry_run_actions.record', {
    number: index + 1,
  })
}

interface ControlGroup {
  key: string
  title: string
  tone: string
  findings: PayrollJmhzControlFinding[]
}

/**
 * Tři skupiny nálezů se liší dopadem, ne závažností textu: nepropustná vada
 * podání zneplatní, propustná ho nechá projít s chybnými daty a mezera
 * v pokrytí znamená, že jsme kontrolu vůbec nevykonali.
 */
function controlGroups(report: PayrollJmhzControlReport, result?: PayrollJmhzXmlDryRun): ControlGroup[] {
  // Před koncem měsíce selžou kontroly 43, 44 a 90 jen kvůli dnešnímu datu.
  // Říká to jedna věta závory; vypsat je jako „nepropustné vady" by účetní
  // poslalo hledat chybu v údajích, které jsou v pořádku.
  const dateOnly = periodNotFinished(result) ? (result?.date_only_control_ids ?? []) : []
  return ([
    {
      key: 'blocking',
      title: 'payroll.submissions.overview.jmhz_controls_blocking',
      tone: 'border-danger-500/30 bg-danger-50 text-danger-700',
      findings: report.blocking.filter(finding => !dateOnly.includes(finding.control_id)),
    },
    {
      key: 'gaps',
      title: 'payroll.submissions.overview.jmhz_controls_gaps',
      tone: 'border-warning-500/30 bg-warning-50 text-warning-700',
      findings: report.coverage_gaps,
    },
    {
      key: 'warnings',
      title: 'payroll.submissions.overview.jmhz_controls_warnings',
      tone: 'border-neutral-300 bg-neutral-50 text-neutral-700',
      findings: report.warnings,
    },
  ] satisfies ControlGroup[]).filter(group => group.findings.length > 0)
}

/**
 * Nálezy, které má smysl ukázat jako kroky k doplnění.
 *
 * U ročníku, který aplikace ještě neumí sestavit (číselníky obcí a států pro
 * rok nejsou vyhlášené), nese důvod, radu i termín závora nahoře. Nález
 * číselníků u každého vztahu by vedle ní stál jako krok, na kterém účetní
 * nemá co udělat, a počítal by se do „N kroků k doplnění".
 */
function visibleBlockers(result: PayrollJmhzXmlDryRun): PayrollJmhzXmlDryRunBlocker[] {
  return result.preparation_gate?.code === 'jmhz_spec_version_unavailable'
    ? result.blockers.filter(blocker => blocker.code !== 'jmhz_workplace_codebooks_not_published')
    : result.blockers
}

/** Hlášené období ještě neskončilo: hlášení půjde zmrazit až od data v závoře. */
function periodNotFinished(result?: PayrollJmhzXmlDryRun | null): boolean {
  return result?.preparation_gate?.code === 'jmhz_period_not_finished'
}

/**
 * Nadpis výsledku testu, který neprošel až do „lze odeslat". Dřív tu stála
 * jediná věta o nevykonaném katalogu kontrol, i když katalog proběhl celý
 * a hlášení zastavila konkrétní kontrola, nebo jen datum.
 */
function incompleteTitle(result: PayrollJmhzXmlDryRun): string {
  if (result.waiting_for_period_end) return t('payroll.submissions.overview.jmhz_dry_run_waiting')
  if ((result.controls?.blocking.length ?? 0) > 0) return t('payroll.submissions.overview.jmhz_dry_run_controls_failed')
  return t('payroll.submissions.overview.jmhz_dry_run_incomplete')
}

/** Mzdové běhy měsíce, kde se po opravě údaje čteného ze schváleného běhu otevírá oprava. */
function revisionTarget(payrollRun: PayrollRun): RouteLocationRaw {
  return { name: 'payroll-runs', query: { period: payrollRun.period_start.slice(0, 7) } }
}

/**
 * Proč je tlačítko zhasnuté.
 *
 * Bez téhle věty se test podání nedal spustit a obrazovka mlčela: u revize
 * přes víc mzdových účtáren se čeká na volbu registrace, u čtenáře na právo
 * zapisovat — a obojí vypadá stejně jako rozbité tlačítko.
 */
function startBlockedReason(payrollRun: PayrollRun): string {
  if (!canWrite.value) {
    return t('payroll.submissions.overview.jmhz_dry_run_read_only')
  }
  const id = revisionId(payrollRun)
  if (id !== null
    && officeOptions(payrollRun).length > 1
    && selectedOffice.value[id] == null
  ) {
    return t('payroll.submissions.overview.jmhz_social_multiple_offices')
  }
  return ''
}

/*
 * ─── Odložení vztahu z řádného hlášení ─────────────────────────────────────
 *
 * Jeden zaměstnanec s neúplnými daty nesmí zablokovat hlášení za ostatní.
 * Účetní u nálezu na vztahu (nebo osobě) zvolí „Odložit z hlášení", uvede
 * důvod a řádné hlášení se sestaví bez jeho formuláře. Pojistná část zůstává
 * za všechny, formulář se doplní opravným hlášením ze seznamu odložených.
 */
interface DeferralDraft {
  revision: number
  run: PayrollRun
  employmentId: number
  label: string
  concurrent: string[]
  reason: string
  showValidation: boolean
  busy: boolean
  error: string
}

const deferralDraft = ref<DeferralDraft | null>(null)
const deferralRefresh = ref<Record<number, number>>({})

/** Vztah, za který se odkládá: u nálezu na osobě její vztah v běhu. */
function deferralEmploymentId(blocker: PayrollJmhzXmlDryRunBlocker, entityId: number): number | null {
  if (blocker.entity_type === 'employment') return entityId
  const own = remediationEmployments.value.filter(item => item.employee_id === entityId)
  return own[0]?.id ?? null
}

function canDefer(blocker: PayrollJmhzXmlDryRunBlocker, entityId: number | null): boolean {
  return canWrite.value
    && blocker.deferrable === true
    && entityId !== null
    && deferralEmploymentId(blocker, entityId) !== null
}

function bulkDeferral(payrollRun: PayrollRun): PayrollJmhzDeferralContext | null {
  const result = state(payrollRun)?.result
  const revision = revisionId(payrollRun)
  if (!result || revision === null) return null
  const people = new Set<string>()
  let firm = 0
  for (const blocker of result.blockers) {
    if (blocker.deferrable !== true || blocker.entity_id === null) {
      firm++
      continue
    }
    const employee = blocker.entity_type === 'employment'
      ? remediationEmployments.value.find(item => item.id === blocker.entity_id)?.employee_id ?? `employment:${blocker.entity_id}`
      : blocker.entity_id
    people.add(String(employee))
  }
  if (people.size === 0) return null
  return {
    preparation_id: result.preparation_id,
    environment: 'test',
    office_id: selectedOffice.value[revision] ?? null,
    employment_ids: null,
    count: people.size,
    firm_blocker_count: firm,
  }
}

async function afterBulkDefer(payrollRun: PayrollRun) {
  const revision = revisionId(payrollRun)
  if (revision === null) return
  deferralRefresh.value = { ...deferralRefresh.value, [revision]: (deferralRefresh.value[revision] ?? 0) + 1 }
  await run(payrollRun)
}

async function afterDeferralChange(payrollRun: PayrollRun) {
  if (state(payrollRun)?.result) await run(payrollRun)
}

function askDefer(payrollRun: PayrollRun, blocker: PayrollJmhzXmlDryRunBlocker, entityId: number, index: number) {
  const revision = revisionId(payrollRun)
  const employmentId = deferralEmploymentId(blocker, entityId)
  if (revision === null || employmentId === null) return
  const employment = remediationEmployments.value.find(item => item.id === employmentId)
  const concurrent = employment === undefined
    ? []
    : remediationEmployments.value
      .filter(item => item.employee_id === employment.employee_id && item.id !== employmentId)
      .map(item => [item.full_name, personalNumberLabel(t, item.code)].filter(part => part !== '').join(' · '))
  deferralDraft.value = {
    revision,
    run: payrollRun,
    employmentId,
    label: remediationLabel(blocker, entityId, index),
    concurrent,
    reason: '',
    showValidation: false,
    busy: false,
    error: '',
  }
}

const deferralReasonValid = computed(() => (deferralDraft.value?.reason.trim().length ?? 0) >= 3)

async function confirmDefer() {
  const draft = deferralDraft.value
  if (draft === null || draft.busy || !canWrite.value) return
  if (!deferralReasonValid.value) {
    draft.showValidation = true
    return
  }
  const result = states.value[draft.revision]?.result
  if (!result) return
  draft.busy = true
  draft.error = ''
  try {
    await payrollApi.deferJmhzEmployment({
      environment: 'test',
      preparationId: result.preparation_id,
      employmentId: draft.employmentId,
      officeId: selectedOffice.value[draft.revision] ?? null,
      reason: draft.reason.trim(),
    })
    deferralDraft.value = null
    deferralRefresh.value = {
      ...deferralRefresh.value,
      [draft.revision]: (deferralRefresh.value[draft.revision] ?? 0) + 1,
    }
    await run(draft.run)
  } catch (exception) {
    draft.error = jmhzErrorMessage(t, te, locale.value, exception, 'payroll.jmhz_gate.deferral.defer_failed')
  } finally {
    draft.busy = false
  }
}

/** Jména odložených vztahů v hotovém testu. */
function deferredNames(result: PayrollJmhzXmlDryRun): string[] {
  return (result.deferred?.employment_ids ?? []).map((employmentId) => {
    const employment = remediationEmployments.value.find(item => item.id === employmentId)
    return employment === undefined
      ? t('payroll.jmhz_gate.deferral.unnamed_employment', { id: employmentId })
      : [employment.full_name, personalNumberLabel(t, employment.code)].filter(part => part !== '').join(' · ')
  })
}

function expectedWarning(result: PayrollJmhzXmlDryRun, finding: PayrollJmhzControlFinding): boolean {
  return result.deferred?.expected_warning_control_ids.includes(finding.control_id) ?? false
}

/** XML jednoho dílčího balíku do schránky (pro ruční nahrání na ePortál). */
async function copyPackageXml(xml: string) {
  try {
    await navigator.clipboard.writeText(xml)
  } catch {
    // Schránku může prohlížeč zakázat; XML zůstává dostupné pod „Zobrazit XML".
  }
}

/** Kopírovat se nemusí podařit — schránku umí prohlížeč zakázat politikou. */
const copiedRevision = ref<number | null>(null)
const copyFailedRevision = ref<number | null>(null)

async function copyXml(payrollRun: PayrollRun) {
  const id = revisionId(payrollRun)
  const xml = state(payrollRun)?.result?.xml
  if (id === null || !xml) return
  copiedRevision.value = null
  copyFailedRevision.value = null
  try {
    await navigator.clipboard.writeText(xml)
    copiedRevision.value = id
  } catch {
    // Tlačítko, po kterém se mlčky nic nestane, vypadá jako rozbitá aplikace.
    // XML je vidět pod „Zobrazit XML", takže cesta ven existuje — musí se ale
    // říct nahlas, jinak ji nikdo nehledá.
    copyFailedRevision.value = id
  }
}
</script>

<template>
  <section
    class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm"
    data-test="jmhz-xml-dry-run"
  >
    <div class="border-b border-neutral-200 p-4 sm:p-6">
      <h2 class="text-lg font-semibold text-neutral-900">
        {{ t('payroll.submissions.overview.jmhz_dry_run_title') }}
      </h2>
      <p class="mt-1 text-sm text-neutral-500">
        {{ t('payroll.submissions.overview.jmhz_dry_run_description') }}
      </p>
    </div>
    <p v-if="runs.length === 0" class="p-6 text-sm text-neutral-500">
      {{ t('payroll.submissions.overview.jmhz_dry_run_empty') }}
    </p>
    <div v-else class="space-y-4 p-4">
      <article
        v-for="payrollRun in runs"
        :key="payrollRun.revision_id ?? payrollRun.id"
        class="rounded-lg border border-neutral-200 p-4"
      >
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h3 class="font-semibold text-neutral-900">
            {{ t('payroll.submissions.overview.jmhz_dry_run_card', {
              period: formatPeriod(payrollRun.period_start.slice(0, 7)),
              revision: payrollRun.revision_no,
            }) }}
          </h3>
          <label
            v-if="officeOptions(payrollRun).length > 1"
            class="flex flex-wrap items-center gap-2 text-sm text-neutral-600"
            :data-test="`jmhz-dry-run-office-${payrollRun.revision_id}`"
          >
            <span class="whitespace-nowrap">
              {{ t('payroll.submissions.overview.jmhz_dry_run_office') }}
            </span>
            <select
              v-model="selectedOffice[payrollRun.revision_id!]"
              class="rounded-lg border border-neutral-300 bg-surface px-2 py-1 text-sm"
            >
              <option :value="null">
                {{ t('payroll.submissions.overview.jmhz_dry_run_office_choose') }}
              </option>
              <option
                v-for="office in officeOptions(payrollRun)"
                :key="office.office_id"
                :value="office.office_id"
                :disabled="!office.submittable"
              >
                {{ office.code }} · {{ office.name }}
                {{ office.submittable
                  ? ''
                  : t('payroll.submissions.overview.jmhz_office_variable_symbol_missing') }}
              </option>
            </select>
          </label>
          <button
            type="button"
            :class="btnFilled('primary')"
            :disabled="!canWrite || state(payrollRun)?.running
              || (officeOptions(payrollRun).length > 1
                && selectedOffice[payrollRun.revision_id!] == null)"
            :data-test="`jmhz-dry-run-start-${payrollRun.revision_id}`"
            @click="run(payrollRun)"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.check" />
            </svg>
            {{ state(payrollRun)?.running
              ? t('common.loading')
              : t('payroll.submissions.overview.jmhz_dry_run_start') }}
          </button>
        </div>
        <p
          v-if="startBlockedReason(payrollRun) && !state(payrollRun)?.running"
          class="mt-2 text-right text-xs text-neutral-600"
          :data-test="`jmhz-dry-run-blocked-${payrollRun.revision_id}`"
        >
          {{ startBlockedReason(payrollRun) }}
        </p>

        <template v-if="state(payrollRun)?.result">
          <!--
            Závora přípravy: období ještě neskončilo, nebo ročník hlášení
            aplikace neumí sestavit. Účetní to vlastními údaji nezmění, proto
            to stojí nahoře jednou větou s datem, ne jako další krok k doplnění.
          -->
          <p
            v-if="state(payrollRun)!.result!.preparation_gate"
            class="mt-3 rounded-lg border border-primary-500/30 bg-primary-50 p-3 text-sm text-primary-900"
            :data-test="`jmhz-dry-run-gate-${payrollRun.revision_id}`"
          >
            {{ state(payrollRun)!.result!.preparation_gate!.message }}
          </p>
          <div
            v-if="state(payrollRun)!.result!.status !== 'blocked'"
            class="mt-3 rounded-lg p-3"
            :class="state(payrollRun)!.result!.status === 'dry_run_valid' || state(payrollRun)!.result!.waiting_for_period_end
              ? 'border border-success-500/30 bg-success-50'
              : 'border border-warning-500/30 bg-warning-50'"
          >
            <p
              class="text-sm font-medium"
              :class="state(payrollRun)!.result!.status === 'dry_run_valid' || state(payrollRun)!.result!.waiting_for_period_end
                ? 'text-success-700'
                : 'text-warning-700'"
              data-test="jmhz-dry-run-status"
            >
              {{ state(payrollRun)!.result!.status === 'dry_run_valid'
                ? t('payroll.submissions.overview.jmhz_dry_run_valid', {
                  version: state(payrollRun)!.result!.schema?.data_version ?? '',
                })
                : incompleteTitle(state(payrollRun)!.result!) }}
            </p>
            <p
              class="mt-1 break-all text-xs"
              :class="state(payrollRun)!.result!.status === 'dry_run_valid'
                ? 'text-success-700'
                : 'text-warning-700'"
            >
              {{ state(payrollRun)!.result!.xml_sha256?.slice(0, 16) }}…
            </p>

            <p
              v-if="state(payrollRun)!.result!.deadline"
              class="mt-2 text-xs text-neutral-600"
              data-test="jmhz-dry-run-deadline"
            >
              {{ t('payroll.submissions.overview.jmhz_deadline', {
                from: formatDate(state(payrollRun)!.result!.deadline!.earliest_submission_on),
                to: formatDate(state(payrollRun)!.result!.deadline!.due_on),
              }) }}
            </p>

            <div
              v-if="state(payrollRun)!.result!.controls"
              class="mt-3 space-y-3"
              data-test="jmhz-dry-run-controls"
            >
              <p class="text-xs text-neutral-600">
                {{ t('payroll.submissions.overview.jmhz_controls_summary', {
                  passed: state(payrollRun)!.result!.controls!.counts.passed,
                  failed: state(payrollRun)!.result!.controls!.counts.failed,
                  remote: state(payrollRun)!.result!.controls!.counts.not_evaluable,
                  gaps: state(payrollRun)!.result!.controls!.coverage_gaps.length,
                }) }}
              </p>
              <details
                v-if="state(payrollRun)!.result!.controls!.deviations?.length"
                class="rounded-lg border border-neutral-300 bg-neutral-50 p-3 text-neutral-700"
                data-test="jmhz-controls-deviations"
              >
                <summary class="cursor-pointer text-sm font-medium">
                  {{ t('payroll.submissions.overview.jmhz_controls_deviations', {
                    count: state(payrollRun)!.result!.controls!.deviations.length,
                  }) }}
                </summary>
                <ul class="mt-2 space-y-1 text-sm">
                  <li
                    v-for="deviation in state(payrollRun)!.result!.controls!.deviations"
                    :key="deviation.control_id"
                  >
                    <span class="font-mono text-xs opacity-75">{{ deviation.control_id }}</span>
                    {{ deviation.reason }}
                  </li>
                </ul>
              </details>
              <div
                v-for="group in controlGroups(state(payrollRun)!.result!.controls!, state(payrollRun)!.result!)"
                :key="group.key"
                class="rounded-lg border p-3"
                :class="group.tone"
                :data-test="`jmhz-controls-${group.key}`"
              >
                <p class="text-sm font-medium">
                  {{ t(group.title, { count: group.findings.length }) }}
                </p>
                <ul class="mt-2 space-y-1 text-sm">
                  <!--
                    Kód kontroly stojí AŽ ZA větou. Účetní čte, co je špatně;
                    „291" je údaj pro podporu a napřed jen odsouvá smysl řádku
                    o dva sloupce doprava.
                  -->
                  <li v-for="finding in group.findings" :key="`${group.key}-${finding.control_id}-${finding.form_ordinal ?? ''}`">
                    <span
                      v-if="group.key === 'warnings' && expectedWarning(state(payrollRun)!.result!, finding)"
                      class="mr-1 inline-flex whitespace-nowrap rounded-full border border-primary-500/30 bg-primary-50 px-1.5 text-xs font-medium text-primary-700"
                      :data-test="`jmhz-controls-expected-${finding.control_id}`"
                    >{{ t('payroll.jmhz_gate.deferral.expected_badge') }}</span>
                    {{ finding.message }}
                    <span class="font-mono text-xs opacity-75">
                      {{ finding.error_code ?? finding.control_id }}
                    </span>
                    <span
                      v-if="finding.form_ordinal !== null"
                      class="text-xs opacity-75"
                    >
                      ({{ t('payroll.submissions.overview.jmhz_controls_form', {
                        ordinal: finding.form_ordinal + 1,
                      }) }})
                    </span>
                  </li>
                </ul>
              </div>
            </div>

            <div
              v-if="(state(payrollRun)!.result!.packages?.length ?? 0) > 1"
              class="mt-3 rounded-lg border border-primary-500/30 bg-surface p-3 text-sm text-neutral-800"
              data-test="jmhz-dry-run-packages"
            >
              <p class="font-medium">
                {{ t('payroll.jmhz_gate.packages.title', { count: state(payrollRun)!.result!.packages!.length }) }}
              </p>
              <p class="mt-1 text-xs text-neutral-600">{{ t('payroll.jmhz_gate.packages.hint') }}</p>
              <ul class="mt-2 space-y-2">
                <li
                  v-for="pkg in state(payrollRun)!.result!.packages!"
                  :key="pkg.ordinal"
                  class="flex flex-wrap items-center justify-between gap-2"
                  :data-test="`jmhz-dry-run-package-${pkg.ordinal}`"
                >
                  <span>
                    {{ t('payroll.jmhz_gate.packages.package', { ordinal: pkg.ordinal }) }}
                    <span class="ml-1 text-xs" :class="pkg.submittable ? 'text-success-700' : 'text-warning-700'">
                      {{ pkg.submittable ? t('payroll.jmhz_gate.packages.valid') : t('payroll.jmhz_gate.packages.invalid', { count: pkg.blocking.length }) }}
                    </span>
                  </span>
                  <button type="button" :class="btnOutline('neutral')" @click="copyPackageXml(pkg.xml)">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.copy" />
                    </svg>
                    {{ t('payroll.jmhz_gate.packages.copy') }}
                  </button>
                </li>
              </ul>
            </div>

            <div class="mt-3 flex flex-wrap gap-2">
              <button
                type="button"
                :class="btnOutline('neutral')"
                @click="state(payrollRun)!.showXml = !state(payrollRun)!.showXml"
              >
                {{ state(payrollRun)!.showXml
                  ? t('payroll.submissions.overview.jmhz_dry_run_hide_xml')
                  : t('payroll.submissions.overview.jmhz_dry_run_show_xml') }}
              </button>
              <button
                type="button"
                :class="btnOutline('neutral')"
                @click="copyXml(payrollRun)"
              >
                {{ copiedRevision === payrollRun.revision_id
                  ? t('payroll.submissions.transport.copied')
                  : t('payroll.submissions.overview.jmhz_dry_run_copy_xml') }}
              </button>
              <span
                v-if="copyFailedRevision === payrollRun.revision_id"
                class="self-center text-xs text-warning-700"
                :data-test="`jmhz-dry-run-copy-failed-${payrollRun.revision_id}`"
                role="status"
              >
                {{ t('payroll.submissions.overview.jmhz_dry_run_copy_failed') }}
              </span>
            </div>
            <pre
              v-if="state(payrollRun)!.showXml"
              class="mt-3 max-h-96 overflow-auto rounded-lg bg-neutral-900 p-3 text-xs leading-relaxed text-neutral-100"
            >{{ state(payrollRun)!.result!.xml }}</pre>
          </div>
          <div
            v-else
            class="mt-3 rounded-lg border border-warning-500/30 bg-warning-50 p-3"
          >
            <p class="text-sm font-medium text-warning-700" data-test="jmhz-dry-run-blocked-title">
              {{ visibleBlockers(state(payrollRun)!.result!).length === 0
                ? t('payroll.submissions.overview.jmhz_dry_run_blocked_nothing_to_fix')
                : t('payroll.submissions.overview.jmhz_dry_run_blocked', {
                  steps: t('payroll.submissions.overview.jmhz_dry_run_blocked_steps', {
                    count: groupedBlockers(visibleBlockers(state(payrollRun)!.result!)).length,
                  }, groupedBlockers(visibleBlockers(state(payrollRun)!.result!)).length),
                  records: t('payroll.submissions.overview.jmhz_dry_run_blocked_records', {
                    count: visibleBlockers(state(payrollRun)!.result!).length,
                  }, visibleBlockers(state(payrollRun)!.result!).length),
                }) }}
            </p>
            <JmhzBulkDefer
              v-if="canWrite && bulkDeferral(payrollRun)"
              class="mt-3"
              :context="bulkDeferral(payrollRun)!"
              :can-write="canWrite"
              @deferred="afterBulkDefer(payrollRun)"
            />
            <ul class="mt-3 space-y-3 text-sm text-warning-700">
              <li
                v-for="group in groupedBlockers(visibleBlockers(state(payrollRun)!.result!))"
                :key="group.key"
                class="rounded-lg border border-warning-500/20 bg-surface/60 p-3"
                data-test="jmhz-dry-run-blocker"
              >
                <div class="flex flex-wrap items-start justify-between gap-2">
                  <div class="min-w-0 flex-1">
                    <p class="font-medium" data-test="jmhz-dry-run-blocker-label">{{ blockerLabel(group.blocker) }}</p>
                    <p class="mt-1 text-xs" data-test="jmhz-dry-run-blocker-step">{{ blockerStep(group.blocker) }}</p>
                    <p
                      v-if="groupLabels(group).length"
                      class="mt-1 text-xs font-semibold"
                      data-test="jmhz-dry-run-affected"
                    >
                      {{ t('payroll.submissions.overview.jmhz_dry_run_affected', {
                        names: groupLabels(group).join(', '),
                      }) }}<span v-if="group.entityIds.length > 10">, …</span>
                    </p>
                    <p v-if="group.count > 1" class="mt-1 text-xs opacity-80">
                      {{ t('payroll.submissions.overview.jmhz_dry_run_blocker_occurrences', {
                        count: group.count,
                      }) }}
                    </p>
                    <!--
                      Údaj čtený ze schváleného běhu: samotná oprava nález
                      neodstraní, test by dál ukazoval totéž.
                    -->
                    <p
                      v-if="group.blocker.needs_revision"
                      class="mt-2 text-xs"
                      data-test="jmhz-dry-run-revision-hint"
                    >
                      {{ t('payroll.jmhz_gate.freeze_blockers.revision_hint') }}
                      <RouterLink
                        :to="revisionTarget(payrollRun)"
                        class="font-medium underline decoration-dotted underline-offset-4"
                        data-test="jmhz-dry-run-revision-link"
                      >
                        {{ t('payroll.jmhz_gate.freeze_blockers.open_run') }}
                      </RouterLink>
                    </p>
                  </div>
                  <button
                    v-if="group.blocker.code === 'jmhz_workplace_codebooks_unverified'"
                    type="button"
                    :class="btnOutline('warning')"
                    data-test="jmhz-dry-run-workplace-bulk"
                    @click="workplaceBulkDialogRun = payrollRun"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.pin" />
                    </svg>
                    {{ blockerActionLabel(group.blocker) }}
                  </button>
                  <RouterLink
                    v-else-if="blockerGroupTarget(group, payrollRun)
                      && (group.entityIds.length <= 1
                        || blockerUsesAgendaTarget(group))"
                    :to="blockerGroupTarget(group, payrollRun)!"
                    :class="btnOutline('warning')"
                    data-test="jmhz-dry-run-remediation"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.edit" />
                    </svg>
                    {{ blockerActionLabel(group.blocker) }}
                  </RouterLink>
                  <button
                    v-else-if="jmhzRemediationKind(group.blocker) === 'retry' && canWrite"
                    type="button"
                    :class="btnOutline('warning')"
                    data-test="jmhz-dry-run-retry"
                    :disabled="state(payrollRun)?.running"
                    @click="run(payrollRun)"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.cycle" />
                    </svg>
                    {{ t('payroll.jmhz_gate.remediation.retry') }}
                  </button>
                  <button
                    v-if="group.entityIds.length === 1 && canDefer(group.blocker, group.entityIds[0]!)"
                    type="button"
                    :class="btnOutline('neutral')"
                    :data-test="`jmhz-dry-run-defer-${group.entityIds[0]}`"
                    @click="askDefer(payrollRun, group.blocker, group.entityIds[0]!, 0)"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                      <path :d="ICONS.pause" />
                    </svg>
                    {{ t('payroll.jmhz_gate.deferral.defer') }}
                  </button>
                </div>
                <details
                  v-if="group.entityIds.length > 1 && group.entityIds.length <= 10
                    && canDefer(group.blocker, group.entityIds[0]!)"
                  class="mt-2"
                  data-test="jmhz-dry-run-defer-list"
                >
                  <summary class="cursor-pointer font-medium underline decoration-dotted underline-offset-4">
                    {{ t('payroll.jmhz_gate.deferral.defer_multiple', { count: group.entityIds.length }) }}
                  </summary>
                  <div class="mt-2 flex flex-wrap gap-2">
                    <button
                      v-for="(entityId, index) in group.entityIds"
                      :key="entityId"
                      type="button"
                      :class="btnOutline('neutral')"
                      :data-test="`jmhz-dry-run-defer-${entityId}`"
                      @click="askDefer(payrollRun, group.blocker, entityId, index)"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="ICONS.pause" />
                      </svg>
                      {{ remediationLabel(group.blocker, entityId, index) }}
                    </button>
                  </div>
                </details>
                <details
                  v-if="group.blocker.code !== 'jmhz_workplace_codebooks_unverified'
                    && blockerTarget(group.blocker) && group.entityIds.length > 1
                    && group.entityIds.length <= 10
                    && !blockerUsesAgendaTarget(group)"
                  class="mt-2"
                  data-test="jmhz-dry-run-remediation-list"
                >
                  <summary class="cursor-pointer font-medium underline decoration-dotted underline-offset-4">
                    {{ t('payroll.submissions.overview.jmhz_dry_run_actions.multiple', {
                      count: group.entityIds.length,
                    }) }}
                  </summary>
                  <div class="mt-2 flex flex-wrap gap-2">
                    <RouterLink
                      v-for="(entityId, index) in group.entityIds"
                      :key="entityId"
                      :to="blockerTarget(group.blocker, entityId, payrollRun)!"
                      :class="btnOutline('warning')"
                    >
                      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="ICONS.edit" />
                      </svg>
                      {{ remediationLabel(group.blocker, entityId, index) }}
                    </RouterLink>
                  </div>
                </details>
                <details
                  v-if="group.blocker.attribute_ids.length || group.entityIds.length"
                  class="mt-2 text-xs opacity-80"
                  data-test="jmhz-dry-run-technical-detail"
                >
                  <summary class="cursor-pointer">
                    {{ t('payroll.submissions.overview.jmhz_dry_run_technical_detail') }}
                  </summary>
                  <!--
                    Syrové číslo atributu je pro podporu, ne pro účetní — ale
                    když už tu je, ať u něj stojí, co znamená. „10051, 10228"
                    samo o sobě nikomu nic neřekne.
                  -->
                  <ul v-if="group.blocker.attribute_ids.length" class="mt-1 space-y-0.5">
                    <li
                      v-for="attributeId in group.blocker.attribute_ids"
                      :key="attributeId"
                      class="font-mono"
                    >
                      {{ attributeId }}<template v-if="attributeGloss(attributeId)"> - {{ attributeGloss(attributeId) }}</template>
                    </li>
                  </ul>
                  <p v-if="group.entityIds.length" class="mt-1 font-mono">
                    {{ t('payroll.submissions.overview.jmhz_dry_run_entity_ids', {
                      ids: entityIdPreview(group.entityIds),
                    }) }}
                  </p>
                </details>
              </li>
            </ul>
          </div>
          <div
            v-if="state(payrollRun)!.result!.deferred"
            class="mt-3 rounded-lg border border-primary-500/30 bg-primary-50 p-3 text-sm text-primary-900"
            data-test="jmhz-dry-run-deferred"
          >
            <p class="font-medium">
              {{ t('payroll.jmhz_gate.deferral.in_report', {
                count: state(payrollRun)!.result!.deferred!.employment_ids.length,
                names: deferredNames(state(payrollRun)!.result!).join(', '),
              }) }}
            </p>
            <p class="mt-1 text-xs">{{ t('payroll.jmhz_gate.deferral.in_report_hint') }}</p>
            <p
              v-if="state(payrollRun)!.result!.deferred!.summary_excluded_employee_ids.length"
              class="mt-1 text-xs text-warning-700"
              data-test="jmhz-dry-run-deferred-summary"
            >
              {{ t('payroll.jmhz_gate.deferral.summary_excluded', {
                count: state(payrollRun)!.result!.deferred!.summary_excluded_employee_ids.length,
              }) }}
            </p>
            <p
              v-if="state(payrollRun)!.result!.deferred!.expected_warning_control_ids.length"
              class="mt-1 text-xs"
              data-test="jmhz-dry-run-deferred-expected"
            >
              {{ t('payroll.jmhz_gate.deferral.expected_warnings', {
                controls: state(payrollRun)!.result!.deferred!.expected_warning_control_ids.join(', '),
              }) }}
            </p>
          </div>
          <p class="mt-3 text-xs text-neutral-500">
            {{ state(payrollRun)!.result!.official_submission.reason }}
          </p>
        </template>

        <div
          v-if="deferralDraft && deferralDraft.revision === payrollRun.revision_id"
          class="mt-3 rounded-lg border border-warning-500/40 bg-warning-50 p-3 text-sm text-warning-900"
          data-test="jmhz-defer-form"
        >
          <p class="font-medium">
            {{ t('payroll.jmhz_gate.deferral.form_title', { name: deferralDraft.label }) }}
          </p>
          <ul class="mt-1 list-disc space-y-0.5 pl-4 text-xs">
            <li>{{ t('payroll.jmhz_gate.deferral.form_obligation') }}</li>
            <li>{{ t('payroll.jmhz_gate.deferral.form_pvpoj') }}</li>
            <li v-if="deferralDraft.concurrent.length" data-test="jmhz-defer-form-concurrent">
              {{ t('payroll.jmhz_gate.deferral.form_concurrent', { names: deferralDraft.concurrent.join(', ') }) }}
            </li>
          </ul>
          <label class="mt-2 block text-xs font-medium" for="jmhz-defer-reason">
            {{ t('payroll.jmhz_gate.deferral.reason_label') }}
          </label>
          <textarea
            id="jmhz-defer-reason"
            v-model="deferralDraft.reason"
            rows="2"
            maxlength="500"
            class="mt-1 w-full rounded-lg border border-neutral-300 bg-surface px-2 py-1 text-sm text-neutral-900"
            :placeholder="t('payroll.jmhz_gate.deferral.reason_placeholder')"
            data-test="jmhz-defer-reason"
          />
          <p v-if="deferralDraft.showValidation && !deferralReasonValid" class="mt-1 text-xs text-danger-700">
            {{ t('payroll.jmhz_gate.deferral.reason_required') }}
          </p>
          <p v-if="deferralDraft.error" class="mt-2 rounded-lg border border-danger-500/30 bg-danger-50 p-2 text-xs text-danger-700" role="alert">
            {{ deferralDraft.error }}
          </p>
          <div class="mt-2 flex flex-wrap gap-2">
            <button
              type="button"
              :class="btnFilled('warning')"
              :disabled="deferralDraft.busy"
              data-test="jmhz-defer-confirm"
              @click="confirmDefer"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.pause" />
              </svg>
              {{ deferralDraft.busy ? t('common.loading') : t('payroll.jmhz_gate.deferral.confirm') }}
            </button>
            <button type="button" :class="btnOutline('neutral')" @click="deferralDraft = null">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.x" />
              </svg>
              {{ t('common.cancel') }}
            </button>
          </div>
        </div>

        <PayrollJmhzDeferralList
          v-if="payrollRun.revision_id"
          :revision-id="payrollRun.revision_id"
          :environment="environment"
          :can-write="canWrite"
          :refresh-key="deferralRefresh[payrollRun.revision_id] ?? 0"
          :preparation-id="state(payrollRun)?.result?.preparation_id ?? null"
          :office-id="selectedOffice[payrollRun.revision_id] ?? null"
          @changed="afterDeferralChange(payrollRun)"
        />

        <p
          v-if="state(payrollRun)?.error"
          class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
          role="alert"
        >
          {{ state(payrollRun)?.error }}
        </p>
      </article>
    </div>
  </section>

  <PayrollWorkplaceBulkFillDialog
    v-if="workplaceBulkDialogRun"
    :period-start="workplaceBulkDialogRun.period_start"
    :employment-ids="null"
    @close="workplaceBulkDialogRun = null"
  >
    <template #after-apply>
      <p class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-xs text-warning-800">
        {{ t('payroll.workplace_bulk.recalculate_notice') }}
      </p>
    </template>
  </PayrollWorkplaceBulkFillDialog>
</template>
