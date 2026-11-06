<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { formDateInput as dateInput, formInput as field, formLabel, formTextarea as textarea } from '@/components/ui/formStyles'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { accountingApi, type AccountingPeriod, type ChartAccount } from '@/api/accounting'
import type { DimensionOverview } from '@/api/dimensions'
import { nonprofitDppoApi } from '@/api/nonprofitDppo'
import type { LegalOverview } from '@/api/nonprofitDppoTypes'
import { membershipApi, type MemberRevision, type MembershipConfirmBody, type MembershipIssueRequest, type MembershipPreview, type MembershipRate } from '@/api/nonprofitMembership'
import { accountPickerOptions } from '@/utils/chartAccountOptions'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { appIsoDate } from '@/utils/date'
import { dimensionValueName } from '@/utils/dimensionValueLabel'
import { activeThroughout, amountDecimal, errorBox, formatDateRange, isIsoDate, revisionAt, titleKeyPattern, warningBox } from './membershipFormat'
import MembershipConfirmBar from './MembershipConfirmBar.vue'
import { useGuardedCommit } from './useGuardedCommit'
import MembershipRates from './MembershipRates.vue'
import { membershipRatePeriodEnd } from './membershipRatePeriod'

const props = defineProps<{
  supplier: number; canManage: boolean
  member: { id: number; member_number: string; label: string }
  history: MemberRevision[]; periods: AccountingPeriod[]
}>()
const emit = defineEmits<{ done: []; close: []; lock: [locked: boolean] }>()
const { t, te } = useI18n()

const inputForm = ref<HTMLFormElement | null>(null)

const form = reactive({
  periodId: 0, accountingDate: '', dueDate: '', periodFrom: '', periodTo: '', titleKey: '', amount: '',
  receivable: null as string | null, revenue: null as string | null,
  titleReference: '', evidence: '', reason: '', nonreciprocal: false,
  activityValueId: null as number | null, activityKindValueId: null as number | null, taxRegimeValueId: null as number | null,
})
const accounts = ref<ChartAccount[]>([])
const appliedRate = ref<MembershipRate | null>(null)
function applyRate(rate: MembershipRate | null) {
  if (commit.locked.value) return
  appliedRate.value = rate
  commit.touch()
  if (!rate) return
  form.amount = rate.amount; form.receivable = rate.receivable_account; form.revenue = rate.revenue_account
  form.titleKey = rate.title_key; form.titleReference = rate.title_reference; form.evidence = rate.evidence
  form.activityValueId = rate.classification?.activity_value_id ?? null
  form.activityKindValueId = rate.classification?.activity_kind_value_id ?? null
  form.taxRegimeValueId = rate.classification?.tax_regime_value_id ?? null
  if (rate.period_months) {
    const from = isIsoDate(form.periodFrom) ? form.periodFrom : form.accountingDate
    const to = membershipRatePeriodEnd(from, rate.period_months)
    if (to) { form.periodFrom = from; form.periodTo = to }
  }
}
const accountsError = ref(false)
const classOverview = ref<DimensionOverview | null>(null)
const classState = ref<'idle' | 'loading' | 'ready' | 'error'>('idle')
let classTicket = 0
const profile = ref<LegalOverview | null>(null)
const profileState = ref<'idle' | 'loading' | 'ready' | 'error'>('idle')
let alive = true
let loadTicket = 0

const commit = useGuardedCommit<MembershipConfirmBody, MembershipPreview>({
  supplier: () => props.supplier,
  canManage: () => props.canManage,
  send: (supplier, body) => membershipApi.confirm(supplier, body),
  onDone: () => emit('done'),
})

const openPeriods = computed(() => props.periods.filter(p => p.status === 'open'))
const chosenPeriod = computed(() => props.periods.find(p => p.id === form.periodId))
const option = (a: ChartAccount) => ({ value: a.account_code, label: `${a.account_code} ${a.name}` })
const receivableOptions = computed(() => accountPickerOptions(accounts.value, a => a.account_type === 'asset').map(option))
// Server vynucuje roli nonprofit.membership_revenue (684 a analytiky), výběr nabízí jen ty.
const revenueOptions = computed(() => accountPickerOptions(accounts.value, a => a.account_type === 'revenue' && a.account_code.startsWith('684')).map(option))
const accountName = (code: string) => accounts.value.find(a => a.account_code === code)?.name ?? ''

const standardSemantic: Record<'activity' | 'activity_kind' | 'tax_regime', readonly string[] | null> = {
  activity: ['main', 'economic'], activity_kind: null, tax_regime: ['subject', 'out_of_scope', 'exempt'],
}
/** Hodnoty jedné systémové osy této firmy; `null`, když osa chybí, není jednoznačná nebo nemá platnou hodnotu. */
function axisOptions(key: keyof typeof standardSemantic) {
  const overview = classOverview.value
  if (!overview) return null
  const types = overview.types.filter(x => x.system_key === key && x.is_active && x.supplier_id === props.supplier)
  if (types.length !== 1) return null
  const allowed = standardSemantic[key]
  const values = overview.values
    .filter(v => v.type_id === types[0]!.id && v.is_active && v.supplier_id === props.supplier && (!allowed || allowed.includes(v.semantic_key ?? '')))
    .map(v => ({ value: v.id, label: dimensionValueName({ t, te }, v, key) }))
  return values.length ? values : null
}
const activityOptions = computed(() => axisOptions('activity') ?? [])
const kindOptions = computed(() => axisOptions('activity_kind') ?? [])
const taxRegimeOptions = computed(() => axisOptions('tax_regime') ?? [])
const classificationAxesOk = computed(() => activityOptions.value.length > 0 && kindOptions.value.length > 0 && taxRegimeOptions.value.length > 0)
const classificationChosen = computed(() =>
  activityOptions.value.some(o => o.value === form.activityValueId)
  && kindOptions.value.some(o => o.value === form.activityKindValueId)
  && taxRegimeOptions.value.some(o => o.value === form.taxRegimeValueId))
const classificationBusy = computed(() => classState.value !== 'ready')

const latestProfile = computed(() => profile.value?.latest ?? null)
const legalOk = computed(() => latestProfile.value?.profile.entity_type === 'association' && latestProfile.value.profile.main_activity_nonbusiness === true)
const revisionAtDate = computed(() => (isIsoDate(form.accountingDate) ? revisionAt(props.history, form.accountingDate) : null))

/** Všechny důvody, proč náhled zatím nejde vyžádat; formulář je ukáže najednou, tlačítko uvede první. */
const problems = computed(() => {
  const list: { key: string; text: string }[] = []
  const add = (key: string, params: Record<string, unknown> = {}) => list.push({ key, text: t(`nonprofitMembership.${key}`, params) })
  const period = chosenPeriod.value
  if (!period || period.status !== 'open') add('issueNeedPeriod')
  const dates = [form.accountingDate, form.dueDate, form.periodFrom, form.periodTo]
  const datesOk = dates.every(isIsoDate)
  if (!datesOk) add('dateInvalid')
  if (datesOk && period && (form.accountingDate < period.starts_on || form.accountingDate > period.ends_on)) add('issueDateOutsidePeriod')
  if (datesOk && form.periodFrom > form.periodTo) add('issuePeriodReversed')
  if (datesOk && form.dueDate < form.accountingDate) add('issueDueBeforeDate')
  if (!titleKeyPattern.test(form.titleKey)) add('issueTitleKeyInvalid')
  if (amountDecimal(form.amount) === null) add('issueAmountInvalid')
  if (!form.receivable || !form.revenue || form.receivable === form.revenue) add('issueAccountsInvalid')
  if (classState.value === 'idle' || classState.value === 'loading') add('classificationLoading')
  else if (classState.value === 'error') add('classificationError')
  else if (!classificationAxesOk.value) add('classificationMissing')
  else if (!classificationChosen.value) add('classificationRequired')
  if ([form.titleReference, form.evidence, form.reason].some(text => !text.trim() || text.trim().length > 2000)) add('issueEvidenceRequired')
  if (!form.nonreciprocal) add('issueNonreciprocalRequired')
  if (profileState.value === 'loading' || profileState.value === 'idle') add('profileLoading')
  else if (profileState.value === 'error') add('profileError')
  else if (!legalOk.value) add(latestProfile.value ? 'profileNotAssociation' : 'profileMissing')
  return list
})
const problem = computed(() => problems.value[0]?.text ?? '')

/**
 * Členství neaktivní po celé období příspěvku: jen varování jako u stavu k účetnímu datu, o předpisu rozhoduje server.
 * Revize se mohla zapsat v jiném okně a `history` tu ještě nemusí být.
 */
const coverageWarning = computed(() => [form.periodFrom, form.periodTo].every(isIsoDate) && form.periodFrom <= form.periodTo
  && !activeThroughout(props.history, form.periodFrom, form.periodTo))

/** Nesplněné předpoklady firmy a období s odkazem na místo, kde se doplní. Ukazují se hned po otevření formuláře. */
const missingPrerequisites = computed(() => {
  /** `covers` = klíče hlášek ze seznamu pod formulářem, které položka nahrazuje. */
  const list: { key: string; text: string; link: string; to: RouteLocationRaw; covers: string[] }[] = []
  if (!openPeriods.value.length) {
    list.push({ key: 'period', text: t('nonprofitMembership.prerequisiteNoOpenPeriod'), link: t('nonprofitMembership.prerequisitePeriodsLink'),
      to: { name: 'accounting-periods' }, covers: ['issueNeedPeriod'] })
  }
  if (profileState.value === 'ready' && !legalOk.value && form.periodId) {
    list.push({ key: 'profile', text: t(latestProfile.value ? 'nonprofitMembership.prerequisiteProfileNotAssociation' : 'nonprofitMembership.profileMissing'),
      link: t('nonprofitMembership.prerequisiteProfileLink'), to: { name: 'accounting-nonprofit-tax', params: { id: form.periodId } },
      covers: ['profileNotAssociation', 'profileMissing'] })
  }
  if (classState.value === 'ready' && !classificationAxesOk.value) {
    list.push({ key: 'dimensions', text: t('nonprofitMembership.classificationMissing'), link: t('nonprofitMembership.prerequisiteDimensionsLink'),
      to: { name: 'company-dimensions' }, covers: ['classificationMissing'] })
  }
  if (accounts.value.length && !revenueOptions.value.length) {
    list.push({ key: 'revenue-account', text: t('nonprofitMembership.prerequisiteRevenueAccount'), link: t('nonprofitMembership.prerequisiteAccountsLink'),
      to: { name: 'accounting-accounts' }, covers: [] })
  }
  return list
})
/** Hlášky, které formulář ukazuje vlastním odstavcem nebo chybovým rámečkem (načítání a chyby profilu a členění). */
const shownElsewhere = ['profileLoading', 'profileError', 'classificationLoading', 'classificationError']
/** Seznam pod formulářem neopakuje, co už vypisují nesplněné předpoklady nahoře nebo vlastní hláška u pole. */
const visibleProblems = computed(() => {
  const covered = new Set([...shownElsewhere, ...missingPrerequisites.value.flatMap(item => item.covers)])
  return problems.value.filter(item => !covered.has(item.key)).map(item => item.text)
})
/** Chyby se ukážou, jakmile uživatel začne vyplňovat vlastní údaje; předvyplněné období a datum se nepočítají. */
const started = computed(() => [form.titleKey, form.amount, form.reason, form.titleReference, form.evidence, form.periodFrom, form.periodTo, form.dueDate]
  .some(value => value.trim() !== '') || form.receivable !== null || form.revenue !== null)

function buildRequest(): MembershipIssueRequest {
  return {
    ...(appliedRate.value ? { rate_id: appliedRate.value.id } : {}),
    member_id: props.member.id, title_key: form.titleKey, period_from: form.periodFrom, period_to: form.periodTo,
    accounting_date: form.accountingDate, due_date: form.dueDate, amount: amountDecimal(form.amount) ?? '',
    receivable_account: form.receivable ?? '', revenue_account: form.revenue ?? '',
    title_reference: form.titleReference.trim(), evidence: form.evidence.trim(), nonreciprocal: true, reason: form.reason.trim(),
    classification: {
      activity_value_id: form.activityValueId ?? 0, activity_kind_value_id: form.activityKindValueId ?? 0, tax_regime_value_id: form.taxRegimeValueId ?? 0,
    },
  }
}

async function runPreview() {
  if (!props.canManage || problem.value || !inputForm.value?.reportValidity()) return
  const op = commit.begin()
  if (!op) return
  const request = buildRequest()
  try {
    const result = await membershipApi.preview(op.supplier, { operation: 'issue', request })
    if (!op.current()) return
    commit.arm(result, { operation: 'issue', request, expected_case_version: result.expected_case_version, preview_hash: result.preview_hash, idempotency_key: crypto.randomUUID() }, op.supplier)
  } catch (e) {
    if (op.current()) commit.fail(e)
  } finally {
    op.end()
  }
}

async function loadAccounts() {
  const ticket = ++loadTicket, supplier = props.supplier
  accountsError.value = false
  try {
    const rows = await accountingApi.listAccounts({ supplierId: supplier })
    if (!alive || ticket !== loadTicket || supplier !== props.supplier) return
    accounts.value = rows
  } catch {
    if (alive && ticket === loadTicket) accountsError.value = true
  }
}

async function loadClassification() {
  const ticket = ++classTicket, supplier = props.supplier
  classOverview.value = null
  form.activityValueId = null
  form.activityKindValueId = null
  form.taxRegimeValueId = null
  classState.value = 'loading'
  try {
    const overview = await membershipApi.classificationOptions(supplier)
    if (!alive || ticket !== classTicket || supplier !== props.supplier) return
    classOverview.value = overview
    classState.value = 'ready'
  } catch {
    if (alive && ticket === classTicket && supplier === props.supplier) classState.value = 'error'
  }
}

let profileTicket = 0
async function loadProfile() {
  const ticket = ++profileTicket, supplier = props.supplier, period = form.periodId
  profile.value = null
  if (!period) { profileState.value = 'idle'; return }
  profileState.value = 'loading'
  try {
    const overview = await nonprofitDppoApi.profile(supplier, period)
    if (!alive || ticket !== profileTicket || supplier !== props.supplier || period !== form.periodId) return
    profile.value = overview
    profileState.value = 'ready'
  } catch {
    if (alive && ticket === profileTicket) profileState.value = 'error'
  }
}

function clampDates() {
  const period = chosenPeriod.value
  if (!period) return
  if (!isIsoDate(form.accountingDate) || form.accountingDate < period.starts_on || form.accountingDate > period.ends_on) {
    const today = appIsoDate()
    form.accountingDate = today >= period.starts_on && today <= period.ends_on ? today : period.starts_on
  }
}

watch(() => form.periodId, () => { clampDates(); void loadProfile() })
watch([form, () => latestProfile.value?.id ?? 0, () => props.member.id], () => commit.touch(), { deep: true, flush: 'sync' })
watch(() => props.supplier, () => {
  appliedRate.value = null
  commit.reset()
  accounts.value = []
  form.receivable = null
  form.revenue = null
  void loadAccounts()
  void loadClassification()
  void loadProfile()
})
watch(commit.locked, locked => emit('lock', locked))
onBeforeUnmount(() => { alive = false })
onMounted(() => {
  const today = appIsoDate()
  form.periodId = (openPeriods.value.find(p => p.starts_on <= today && p.ends_on >= today) ?? openPeriods.value[0])?.id ?? 0
  void loadAccounts()
  void loadClassification()
})

const frozenRequest = computed(() => {
  const payload = commit.frozen.value?.payload
  return payload && payload.operation === 'issue' ? payload.request : null
})
const profileMismatch = computed(() => {
  const proof = commit.review.value?.proof?.legal_profile?.id
  return proof !== undefined && latestProfile.value !== null && proof !== latestProfile.value.id
})

/** Členění z důkazu serveru: zobrazují se ověřená ID, názvy jen z již načtených hodnot os. */
const proofClassification = computed(() => {
  const c = commit.review.value?.proof?.classification
  if (!c) return null
  const axes = [c.activity, c.activity_kind, c.tax_regime]
  if (axes.some(axis => !axis || !Number.isSafeInteger(axis.type_id) || axis.type_id <= 0
    || !Number.isSafeInteger(axis.value_id) || axis.value_id <= 0
    || axis.origin?.origin !== 'explicit' || axis.origin.ref !== null)) return null
  if (!['main', 'economic'].includes(c.activity.semantic_key)
    || !['subject', 'out_of_scope', 'exempt'].includes(c.tax_regime.semantic_key)
    || typeof c.activity_kind.code !== 'string' || !c.activity_kind.code.trim()) return null
  return c
})
const classificationMatchesRequest = computed(() => {
  const c = proofClassification.value, request = frozenRequest.value?.classification
  return !!c && !!request && c.activity.value_id === request.activity_value_id
    && c.activity_kind.value_id === request.activity_kind_value_id
    && c.tax_regime.value_id === request.tax_regime_value_id
})
const valueLabel = (typeId: number, valueId: number) => {
  const value = classOverview.value?.values.find(v => v.id === valueId && v.type_id === typeId)
  return value
    ? dimensionValueName({ t, te }, value, classOverview.value?.types.find(type => type.id === typeId)?.system_key)
    : t('nonprofitMembership.classificationUnknownValue', { id: valueId })
}
const originLabel = (origin: string) => (origin === 'explicit' ? t('nonprofitMembership.classificationOriginExplicit') : origin)
const classificationRows = computed(() => {
  const c = proofClassification.value
  if (!c) return []
  return [
    { key: 'activity', label: t('nonprofitMembership.classificationActivity'), value: valueLabel(c.activity.type_id, c.activity.value_id), note: null, origin: originLabel(c.activity.origin.origin) },
    { key: 'activity-kind', label: t('nonprofitMembership.classificationKind'), value: valueLabel(c.activity_kind.type_id, c.activity_kind.value_id), note: c.activity_kind.code, origin: originLabel(c.activity_kind.origin.origin) },
    { key: 'tax-regime', label: t('nonprofitMembership.classificationTaxRegime'), value: valueLabel(c.tax_regime.type_id, c.tax_regime.value_id), note: null, origin: originLabel(c.tax_regime.origin.origin) },
  ]
})

const retryActions = computed<ActionItem[]>(() => [
  { key: 'classification-retry', label: t('nonprofitMembership.classificationRetry'), icon: 'cycle', tier: 'primary', variant: 'warning', disabled: commit.locked.value, run: () => { void loadClassification() } },
])
const actions = computed<ActionItem[]>(() => [
  { key: 'issue-preview', label: t('nonprofitMembership.preview'), icon: 'eye', tier: 'primary', variant: 'primary', loading: commit.previewing.value, disabled: !props.canManage || !!problem.value || commit.busy.value || commit.locked.value, disabledReason: problem.value || undefined, run: () => { void runPreview() } },
  { key: 'issue-close', label: t('nonprofitMembership.close'), icon: 'x', disabled: commit.locked.value, run: () => emit('close') },
])
</script>

<template>
  <section class="space-y-4 rounded-lg border bg-surface p-4" data-section="issue-flow">
    <MembershipRates :key="supplier" :supplier="supplier" :can-manage="canManage" selectable :disabled="commit.locked.value" @selected="applyRate" />
    <p v-if="appliedRate" class="text-sm text-neutral-500" data-section="applied-rate">{{ t('nonprofitMembership.rateApplied', { name: appliedRate.name }) }}</p>
    <h3 class="break-words font-semibold">{{ t('nonprofitMembership.issueTitle') }} · {{ member.member_number }} · {{ member.label }}</h3>
    <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.issueHelp') }}</p>
    <div v-if="missingPrerequisites.length" :class="warningBox" data-section="issue-prerequisites">
      <p class="font-medium">{{ t('nonprofitMembership.prerequisitesTitle') }}</p>
      <ul class="mt-2 space-y-2">
        <li v-for="item in missingPrerequisites" :key="item.key" class="flex flex-wrap items-center justify-between gap-2" :data-prerequisite="item.key">
          <span class="min-w-0">{{ item.text }}</span>
          <RouterLink :to="item.to" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" data-action="prerequisite-link">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>{{ item.link }}
          </RouterLink>
        </li>
      </ul>
    </div>
    <p v-if="commit.error.value" role="alert" :class="errorBox" data-section="issue-error">{{ commit.error.value }}</p>
    <p v-if="accountsError" role="alert" :class="errorBox" data-section="accounts-error">{{ t('nonprofitMembership.accountsError') }}</p>
    <p v-if="classState === 'loading'" class="text-sm text-neutral-500" data-section="classification-loading">{{ t('nonprofitMembership.classificationLoading') }}</p>
    <div v-else-if="classState === 'error'" class="flex flex-wrap items-center gap-3" data-section="classification-error">
      <p role="alert" :class="[errorBox, 'min-w-0 flex-1']">{{ t('nonprofitMembership.classificationError') }}</p>
      <ActionBar v-if="canManage" :actions="retryActions" />
    </div>
    <form ref="inputForm" @submit.prevent="runPreview" @input="commit.touch">
    <fieldset :disabled="commit.locked.value" class="grid gap-3 sm:grid-cols-2">
      <label :class="formLabel">{{ t('nonprofitMembership.accountingPeriod') }}
        <select v-model.number="form.periodId" :class="field" data-field="issue-period">
          <option :value="0">{{ t('nonprofitMembership.choose') }}</option>
          <option v-for="p in openPeriods" :key="p.id" :value="p.id">{{ formatDateRange(p.starts_on, p.ends_on) }}</option>
        </select>
      </label>
      <div class="text-sm" data-section="issue-profile">
        <p class="font-medium">{{ t('nonprofitMembership.legalProfile') }}</p>
        <p v-if="profileState === 'loading'">{{ t('nonprofitMembership.profileLoading') }}</p>
        <p v-else-if="profileState === 'error'" role="alert" :class="errorBox" data-section="profile-error">{{ t('nonprofitMembership.profileError') }}</p>
        <p v-else-if="profileState === 'ready' && latestProfile" :class="legalOk ? '' : 'text-warning-700'">
          {{ t('nonprofitMembership.profileRevision', { no: latestProfile.revision_no }) }}<template v-if="legalOk"> · {{ t('nonprofitMembership.profileAssociation') }}</template>
        </p>
      </div>
      <label :class="formLabel">{{ t('nonprofitMembership.accountingDate') }}<DateInput :class="dateInput" v-model="form.accountingDate" required :min="chosenPeriod?.starts_on" :max="chosenPeriod?.ends_on" data-field="accounting-date" /></label>
      <label :class="formLabel">{{ t('nonprofitMembership.dueDate') }}<DateInput :class="dateInput" v-model="form.dueDate" required data-field="due-date" /></label>
      <label :class="formLabel">{{ t('nonprofitMembership.periodFrom') }}<DateInput :class="dateInput" v-model="form.periodFrom" required data-field="period-from" /></label>
      <label :class="formLabel">{{ t('nonprofitMembership.periodTo') }}<DateInput :class="dateInput" v-model="form.periodTo" required data-field="period-to" /></label>
      <p v-if="form.accountingDate && revisionAtDate && revisionAtDate.status !== 'active'" class="text-sm text-warning-700 sm:col-span-2" data-section="issue-status-warning">{{ t('nonprofitMembership.issueMemberNotActive', { status: t(`nonprofitMembership.status_${revisionAtDate.status}`) }) }}</p>
      <p v-else-if="form.accountingDate && isIsoDate(form.accountingDate) && !revisionAtDate" class="text-sm text-warning-700 sm:col-span-2" data-section="issue-status-warning">{{ t('nonprofitMembership.issueMemberNoRevision') }}</p>
      <p v-else-if="coverageWarning" class="text-sm text-warning-700 sm:col-span-2" data-section="issue-coverage-warning">{{ t('nonprofitMembership.issueMemberNotActiveInPeriod', { range: formatDateRange(form.periodFrom, form.periodTo) }) }}</p>
      <label :class="formLabel">{{ t('nonprofitMembership.titleKey') }}<input v-model="form.titleKey" :class="field" maxlength="80" placeholder="CP-2027" aria-describedby="membership-title-key-help" data-field="title-key" /><span id="membership-title-key-help" class="mt-1 block text-xs font-normal text-neutral-500" data-field="title-key-help">{{ t('nonprofitMembership.titleKeyHelp') }}</span></label>
      <label :class="formLabel">{{ t('nonprofitMembership.amount') }}<input v-model="form.amount" inputmode="decimal" :class="field" data-field="amount" /></label>
      <label :class="formLabel">{{ t('nonprofitMembership.receivableAccount') }}
        <SearchableSelect v-model="form.receivable" :options="receivableOptions" :disabled="commit.locked.value" :placeholder="t('nonprofitMembership.chooseAccount')" :aria-label="t('nonprofitMembership.receivableAccount')" data-field="receivable" />
      </label>
      <label :class="formLabel">{{ t('nonprofitMembership.revenueAccount') }}
        <SearchableSelect v-model="form.revenue" :options="revenueOptions" :disabled="commit.locked.value" :placeholder="t('nonprofitMembership.chooseAccount')" :aria-label="t('nonprofitMembership.revenueAccount')" data-field="revenue" />
      </label>
      <label :class="formLabel">{{ t('nonprofitMembership.classificationActivity') }}
        <SearchableSelect v-model="form.activityValueId" :options="activityOptions" :disabled="commit.locked.value || classificationBusy" :placeholder="t('nonprofitMembership.classificationChoose')" :aria-label="t('nonprofitMembership.classificationActivity')" data-field="activity" />
      </label>
      <label :class="formLabel">{{ t('nonprofitMembership.classificationKind') }}
        <SearchableSelect v-model="form.activityKindValueId" :options="kindOptions" :disabled="commit.locked.value || classificationBusy" :placeholder="t('nonprofitMembership.classificationChoose')" :aria-label="t('nonprofitMembership.classificationKind')" data-field="activity-kind" />
      </label>
      <label :class="formLabel">{{ t('nonprofitMembership.classificationTaxRegime') }}
        <SearchableSelect v-model="form.taxRegimeValueId" :options="taxRegimeOptions" :disabled="commit.locked.value || classificationBusy" :placeholder="t('nonprofitMembership.classificationChoose')" :aria-label="t('nonprofitMembership.classificationTaxRegime')" data-field="tax-regime" />
      </label>
      <p class="text-sm text-neutral-500 sm:col-span-2" data-section="classification-help">{{ t('nonprofitMembership.classificationHelp') }}</p>
      <label :class="[formLabel, 'sm:col-span-2']">{{ t('nonprofitMembership.titleReference') }}<input v-model="form.titleReference" :class="field" maxlength="2000" data-field="title-reference" /></label>
      <label :class="[formLabel, 'sm:col-span-2']">{{ t('nonprofitMembership.evidence') }}<textarea v-model="form.evidence" :class="textarea" maxlength="2000" data-field="evidence" /></label>
      <label :class="[formLabel, 'sm:col-span-2']">{{ t('nonprofitMembership.reason') }}<textarea v-model="form.reason" :class="textarea" maxlength="2000" data-field="reason" /></label>
      <label class="flex items-start gap-2 sm:col-span-2"><input v-model="form.nonreciprocal" type="checkbox" data-field="nonreciprocal" /><span>{{ t('nonprofitMembership.nonreciprocal') }}</span></label>
    </fieldset>
    </form>
    <div v-if="visibleProblems.length && started" :class="warningBox" data-section="issue-problem">
      <p class="font-medium">{{ t('nonprofitMembership.issueProblemsTitle') }}</p>
      <ul class="mt-1 list-disc space-y-0.5 pl-5">
        <li v-for="item in visibleProblems" :key="item">{{ item }}</li>
      </ul>
    </div>
    <ActionBar v-if="canManage && !commit.review.value" :actions="actions" />
    <div v-if="commit.review.value && frozenRequest" class="space-y-3 rounded border p-3" data-section="issue-preview">
      <h4 class="font-semibold">{{ t('nonprofitMembership.reviewTitle') }}</h4>
      <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.advisory') }}</p>
      <dl class="grid gap-3 sm:grid-cols-3">
        <div class="min-w-0"><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.titleKey') }}</dt><dd class="break-words">{{ frozenRequest.title_key }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.periodFrom') }} – {{ t('nonprofitMembership.periodTo') }}</dt><dd data-field="review-period">{{ formatDateRange(frozenRequest.period_from, frozenRequest.period_to) }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.amount') }}</dt><dd class="whitespace-nowrap tabular-nums" data-field="review-amount">{{ formatMoney(frozenRequest.amount) }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.accountingDate') }}</dt><dd>{{ formatDate(frozenRequest.accounting_date) }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.dueDate') }}</dt><dd>{{ formatDate(frozenRequest.due_date) }}</dd></div>
        <div><dt class="text-sm text-neutral-500">{{ t('nonprofitMembership.caseVersion') }}</dt><dd data-field="review-case">{{ commit.review.value.expected_case_version === 0 ? t('nonprofitMembership.caseNew') : t('nonprofitMembership.caseExisting') }}</dd></div>
      </dl>
      <div class="overflow-x-auto">
      <table class="w-full text-sm" data-section="issue-plan">
        <thead><tr class="text-left text-neutral-500"><th class="px-3 py-2">{{ t('nonprofitMembership.side') }}</th><th class="px-3 py-2">{{ t('nonprofitMembership.account') }}</th><th class="px-3 py-2 text-right">{{ t('nonprofitMembership.amount') }}</th></tr></thead>
        <tbody class="divide-y">
          <tr v-for="(line, index) in commit.review.value.journal_plan || []" :key="index">
            <td class="whitespace-nowrap px-3 py-2">{{ line.side === 'debit' ? t('nonprofitMembership.debit') : t('nonprofitMembership.credit') }}</td>
            <td class="px-3 py-2">{{ line.account_code }} {{ accountName(line.account_code) }}</td>
            <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ formatMoney(line.amount) }}</td>
          </tr>
        </tbody>
      </table>
      </div>
      <div data-section="issue-classification">
        <h5 class="text-sm font-medium">{{ t('nonprofitMembership.classificationReviewTitle') }}</h5>
        <dl v-if="classificationRows.length" class="grid gap-3 sm:grid-cols-3">
          <div v-for="row in classificationRows" :key="row.key" :data-row="row.key">
            <dt class="text-sm text-neutral-500">{{ row.label }}</dt>
            <dd data-part="value">{{ row.value }}</dd>
            <dd class="text-sm text-neutral-500" data-part="note"><template v-if="row.note">{{ row.note }} · </template>{{ row.origin }}</dd>
          </div>
        </dl>
        <p v-else class="text-sm text-warning-700" data-section="classification-proof-missing">{{ t('nonprofitMembership.classificationProofMissing') }}</p>
        <p class="text-sm text-neutral-500" data-section="classification-tax-advisory">{{ t('nonprofitMembership.classificationTaxAdvisory') }}</p>
      </div>
      <p v-if="commit.review.value.deferral" class="text-sm text-warning-700" data-section="issue-deferral">
        {{ t('nonprofitMembership.deferralProposal', { amount: formatMoney(commit.review.value.deferral.deferred_amount), date: formatDate(commit.review.value.deferral.balance_sheet_date) }) }}
      </p>
      <p v-if="commit.review.value.proof?.member_revision" class="text-sm" data-field="proof-revision">{{ t('nonprofitMembership.proofRevision', { no: commit.review.value.proof.member_revision.revision_no, date: formatDate(commit.review.value.proof.member_revision.effective_from) }) }}</p>
      <p v-if="commit.review.value.proof?.legal_profile" class="text-sm" data-field="proof-profile">{{ t('nonprofitMembership.proofProfile') }}</p>
      <p v-if="profileMismatch" class="text-sm text-warning-700" data-section="profile-mismatch">{{ t('nonprofitMembership.profileMismatch') }}</p>
      <p class="text-sm text-neutral-500">{{ t('nonprofitMembership.taxUnclassified') }}</p>
      <MembershipConfirmBar
        v-model:acknowledged="commit.acknowledged.value"
        :busy="commit.busy.value" :uncertain="commit.uncertain.value" :can-manage="canManage"
        :ack-label="t('nonprofitMembership.issueAck')"
        :blocked-reason="classificationMatchesRequest ? undefined : t('nonprofitMembership.classificationProofMissing')"
        @confirm="classificationMatchesRequest && commit.send()" @cancel="commit.touch()" @discard="commit.discard()"
      />
    </div>
  </section>
</template>
