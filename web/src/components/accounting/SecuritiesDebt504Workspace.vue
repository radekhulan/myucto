<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import SecuritiesDebtProfile from './SecuritiesDebtProfile.vue'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { appIsoDate } from '@/utils/date'
import { securitiesDebt504Api as api, type DebtCard, type DebtCommand, type DebtOperation, type DebtPreview, type DebtQuote, type DebtSource, type DebtSourceCursor, type DebtState, type DebtTerms } from '@/api/securitiesDebt504'
import type { SecuritiesProfile } from '@/api/securities504'

const props = defineProps<{ supplier: number }>()
const { t, te } = useI18n()
const input = 'block w-full rounded border bg-surface p-2'
const today = appIsoDate()
const date = ref(today), rows = ref<DebtCard[]>([]), next = ref<number | null>(null), detail = ref<DebtState | null>(null), creating = ref(false)
const enabled = ref(false), canWrite = ref(false), frameworkSupported = ref(true), loaded = ref(false)
const busy = ref(false), error = ref(''), notice = ref(''), sources = ref<DebtSource[]>([]), sourceKey = ref(''), operation = ref<Exclude<DebtOperation, 'register'>>('accrual'), evidence = ref(''), nominal = ref(''), reverseEvent = ref(0)
const form = ref<HTMLFormElement | null>(null), preview = ref<DebtPreview | null>(null), reviewed = ref<DebtCommand | null>(null), token = ref(''), ack = ref(false), uncertain = ref(false)
const terms = ref<DebtTerms>({ lot_reference: '', instrument_identity: '', contract_reference: '', classification_reference: '', debt_type: 'coupon', classification: 'trading', acquired_on: today, maturity_on: '', nominal: '', clean_cost: '', acquired_interest: '', clean_cost_czk: '', acquired_interest_czk: '', coupon_periods: [] })
const sourceNext = ref<DebtSourceCursor | null>(null)
const amortisationReference = ref('')
const profile = ref<SecuritiesProfile>({ scope: '', category: '', micro_exception: '', as_of: today, reference: '', scope_reference: '', inception_reference: '', first_period_start: '', category_facts: [] })
const quote = ref<DebtQuote>({ quote_type: 'clean', currency: '', as_of: today, clean_value: '', reference: '', market: '', market_type: 'regulated', market_reference: '', market_calendar_reference: '', market_calendar_as_of: today, last_trading_date: today, valuation_date: today })
const frequencies = [1, 2, 4, 12]
let alive = true, generation = 0, sourceGeneration = 0
onBeforeUnmount(() => { alive = false; generation++ })

function invalidate() { preview.value = null; reviewed.value = null; token.value = ''; ack.value = false; uncertain.value = false }
function money(value: string | null | undefined, currency = 'CZK') { return value === null || value === undefined || value === '' ? '—' : formatMoney(Number(value), currency) }

const allowed = computed(() => enabled.value && canWrite.value && frameworkSupported.value)
const payment = computed(() => !creating.value && ['coupon_receipt', 'principal_redemption', 'sale'].includes(operation.value))
const sourceKind = computed(() => creating.value ? 'acquisitions' : operation.value === 'sale' ? 'proceeds' : 'receipts')
const currency = computed(() => detail.value?.facts.currency || 'CZK')
const command = computed<DebtCommand | null>(() => {
 const selected = sources.value.find(s => `${s.id}:${s.line_id}` === sourceKey.value)
 if (creating.value) {
  if (!selected) return null
  const copy = JSON.parse(JSON.stringify(terms.value)) as DebtTerms
  if (copy.classification === 'held_to_maturity') copy.amortisation = { method: 'actual_days_linear', policy_reference: amortisationReference.value, rounding: 'half_away_from_zero_2_cumulative' }
  if (copy.debt_type === 'discount') { copy.coupon_periods = []; copy.acquired_interest = copy.acquired_interest_czk = '0.00' }
  copy.coupon_periods = copy.coupon_periods.map(period => {
   const { frequency, ...rest } = period
   return period.day_count === 'ACT/ACT-ICMA' ? { ...rest, frequency: Number(frequency) } : rest
  })
  return { operation: 'register', terms: copy, entry_id: Number(selected.id), line_id: Number(selected.line_id) }
 }
 if (!detail.value || !date.value || !evidence.value.trim()) return null
 const base: DebtCommand = { operation: operation.value, lot_id: detail.value.id, accounting_date: date.value, evidence: evidence.value }
 if (payment.value) {
  if (!selected) return null
  Object.assign(base, { entry_id: Number(selected.id), line_id: Number(selected.line_id) })
  if (operation.value === 'principal_redemption' || operation.value === 'sale') base.nominal_amount = nominal.value
 }
 if (operation.value === 'reverse') { if (!reverseEvent.value) return null; base.event_id = reverseEvent.value }
 if (operation.value === 'valuation') { base.profile = profile.value; base.quote = quote.value }
 return base
})
watch(command, invalidate, { deep: true })
watch([date, operation, () => terms.value.acquired_on], () => { generation++; sourceGeneration++; sources.value = []; sourceNext.value = null; sourceKey.value = ''; invalidate(); busy.value = false }, { flush: 'sync' })
watch(() => terms.value.debt_type, kind => { if (kind === 'discount') { terms.value.acquired_interest = terms.value.acquired_interest_czk = '0.00'; terms.value.coupon_periods = [] } })

function message(e: unknown) {
 const response = (e as { response?: { status?: number; data?: { error?: { code?: string; message?: string } } } }).response
 const code = response?.data?.error?.code
 if (code === 'debt_preview_stale') return t('securitiesDebt.stale')
 if (code === 'debt_mutation_guard_unavailable') return t('securitiesDebt.guardUnavailable')
 if (code && te(`securitiesDebt.errors.${code}`)) return t(`securitiesDebt.errors.${code}`)
 return response?.data?.error?.message || t('securitiesDebt.error')
}
async function refresh(append = false) {
 const ticket = ++generation; busy.value = true; error.value = ''; invalidate()
 try {
  const page = await api.list(props.supplier, append ? next.value || 0 : 0)
  if (!alive || ticket !== generation) return
  rows.value = append ? [...rows.value, ...page.rows.slice(0, 50)] : page.rows.slice(0, 50)
  next.value = page.rows.length > 50 ? page.rows[49]!.id : null
  enabled.value = page.financial_enabled
  canWrite.value = page.can_write
  frameworkSupported.value = page.framework_supported !== false
  loaded.value = true
 }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
function create() { generation++; creating.value = true; detail.value = null; sources.value = []; sourceNext.value = null; sourceKey.value = ''; notice.value = ''; invalidate() }
async function select(id: number) {
 const ticket = ++generation; busy.value = true; error.value = ''; creating.value = false; sources.value = []; sourceNext.value = null; sourceKey.value = ''; evidence.value = ''; reverseEvent.value = 0; invalidate()
 try {
  const found = await api.detail(props.supplier, id, date.value)
  if (!alive || ticket !== generation) return
  detail.value = found
  operation.value = found.sold_on ? 'reverse' : found.facts.terms.debt_type === 'coupon' ? 'accrual' : found.facts.terms.classification === 'held_to_maturity' ? 'amortisation' : 'valuation'
  nominal.value = found.principal_balance
  quote.value.currency = found.facts.currency
 }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function loadSources(append = false) {
 const ticket = ++generation, sourceTicket = ++sourceGeneration; busy.value = true; error.value = ''; invalidate()
 if (!append) { sources.value = []; sourceNext.value = null; sourceKey.value = '' }
 try {
  const page = await api.sources(props.supplier, sourceKind.value, creating.value ? terms.value.acquired_on : date.value, append ? sourceNext.value : null)
  if (!alive || ticket !== generation || sourceTicket !== sourceGeneration) return
  sources.value = append ? [...sources.value, ...page.rows] : page.rows
  sourceNext.value = page.next_cursor || null
 }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function loadProfile() {
 const ticket = ++generation; busy.value = true; error.value = ''; invalidate()
 try {
  const choices = await api.profileChoices(props.supplier, date.value)
  if (!alive || ticket !== generation) return
  profile.value.as_of = date.value
  profile.value.first_period_start = choices.periods[0]?.starts_on || ''
  profile.value.category_facts = choices.periods.map(p => {
   const saved = choices.history.find(h => Number(h.period_id) === Number(p.id))
   return { period_id: Number(p.id), starts_on: p.starts_on, ends_on: p.ends_on, assets_net: saved?.assets_net || '', net_turnover: saved?.net_turnover || '', average_employees: saved ? String(saved.avg_employees) : '', statement_reference: '', employees_reference: '' }
  })
  quote.value.as_of = quote.value.market_calendar_as_of = date.value
 }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function review() {
 if (!allowed.value || !command.value || busy.value || !form.value?.reportValidity()) return
 const candidate = JSON.parse(JSON.stringify(command.value)) as DebtCommand, ticket = ++generation; busy.value = true; error.value = ''; invalidate()
 try {
  const result = await api.preview(props.supplier, candidate)
  if (!alive || ticket !== generation) return
  reviewed.value = candidate; preview.value = result; token.value = crypto.randomUUID()
 }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function confirm() {
 if (!allowed.value || !reviewed.value || !preview.value || !ack.value || busy.value) return
 const ticket = ++generation; busy.value = true; error.value = ''
 try {
  const result = await api.execute(props.supplier, reviewed.value, preview.value, token.value)
  if (!alive || ticket !== generation) return
  invalidate(); notice.value = t('securitiesDebt.done'); await select(result.lot_id); await refresh()
 }
 catch (e) {
  if (!alive || ticket !== generation) return
  if ([400, 401, 403, 404, 409, 422].includes((e as { response?: { status?: number } }).response?.status || 0)) { invalidate(); error.value = message(e) }
  else { uncertain.value = true; error.value = t('securitiesDebt.uncertain') }
 }
 finally { if (alive && ticket === generation) busy.value = false }
}
function addPeriod() {
 const previous = terms.value.coupon_periods.at(-1)
 terms.value.coupon_periods.push({ from: previous?.to_exclusive || terms.value.acquired_on, to_exclusive: terms.value.maturity_on, annual_rate_fraction: '', day_count: 'ACT/ACT-ICMA', frequency: 1, rounding: 'half_away_from_zero_2_cumulative', terms_reference: '' })
}

const actions = computed<ActionItem[]>(() => [
 { key: 'new', label: t('securitiesDebt.new'), icon: 'plus', variant: 'primary', tier: 'primary', disabled: busy.value || !allowed.value, disabledReason: !frameworkSupported.value ? t('securitiesDebt.frameworkUnsupported') : !allowed.value ? t('securitiesDebt.guardUnavailable') : undefined, run: create },
 { key: 'refresh', label: t('securitiesDebt.refresh'), icon: 'cycle', disabled: busy.value, run: () => { void refresh() } },
])
const sourceActions = computed<ActionItem[]>(() => [
 { key: 'sources', label: t('securitiesDebt.loadSources'), icon: 'search', disabled: busy.value, run: () => { void loadSources() } },
 ...(sourceNext.value ? [{ key: 'sources-more', label: t('securitiesDebt.more'), icon: 'plus' as const, disabled: busy.value, run: () => { void loadSources(true) } }] : []),
])
const profileActions = computed<ActionItem[]>(() => [{ key: 'profile', label: t('securitiesDebt.loadProfile'), icon: 'cycle', disabled: busy.value, run: () => { void loadProfile() } }])
const reviewActions = computed<ActionItem[]>(() => [{ key: 'preview', label: t('securitiesDebt.preview'), icon: 'eye', variant: 'primary', tier: 'primary', disabled: busy.value || !allowed.value || !command.value, run: () => { void review() } }])
const confirmActions = computed<ActionItem[]>(() => [
 { key: 'confirm', label: t('securitiesDebt.confirm'), icon: 'check', variant: reviewed.value?.operation === 'reverse' ? 'danger' : 'success', tier: 'primary', disabled: busy.value || !allowed.value || !ack.value, run: () => { void confirm() } },
 { key: 'cancel', label: t('securitiesDebt.cancel'), icon: 'x', disabled: busy.value, run: invalidate },
])
const operations = computed(() => {
 const state = detail.value
 if (!state) return []
 if (state.sold_on) return ['reverse']
 const kind = state.facts.terms
 return [
  ...(kind.debt_type === 'coupon' ? ['accrual', 'coupon_receipt'] : []),
  ...(kind.classification === 'held_to_maturity' ? ['amortisation'] : ['valuation']),
  'principal_redemption',
  'sale',
  'reverse',
 ]
})
const reversible = computed(() => {
 const last = detail.value?.events.at(-1)
 return last && (last.receipt.ledger || last.receipt.payment) ? [last] : []
})
const summary = computed(() => {
 const state = detail.value
 if (!state) return []
 return [
  { key: 'book', value: money(state.book_czk) },
  { key: 'principal_book', value: money(state.principal_book_czk) },
  { key: 'interest_book', value: money(state.interest_book_czk) },
  { key: 'principal_balance', value: money(state.principal_balance, currency.value) },
  { key: 'unpaid_interest', value: money(state.unpaid_interest, currency.value) },
  { key: 'earned', value: money(state.earned, currency.value) },
  ...(state.facts.terms.classification === 'held_to_maturity' ? [{ key: 'amortised', value: money(state.amortised, currency.value) }] : []),
 ]
})
onMounted(() => { void refresh() })
</script>

<template>
 <div class="space-y-5">
  <h1 class="text-xl font-semibold">{{ t('securitiesDebt.title') }}</h1>
  <p class="text-sm text-neutral-500">{{ t('securitiesDebt.help') }}</p>
  <p v-if="error" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-600">{{ error }}</p>
  <p v-if="notice" role="status" class="rounded-lg border border-success-500/30 bg-success-50 p-3 text-sm text-success-700">{{ notice }}</p>
  <p v-if="loaded && !frameworkSupported" role="status" data-section="framework" class="rounded-md border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-700">{{ t('securitiesDebt.frameworkUnsupported') }}</p>
  <p v-else-if="loaded && !enabled && !busy" class="rounded-md border border-warning-500/30 bg-warning-50 p-3 text-sm text-warning-700">{{ t('securitiesDebt.guardUnavailable') }}</p>
  <ActionBar :actions="actions" />

  <section class="flex flex-wrap gap-2">
   <button v-for="row in rows" :key="row.id" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="busy" :data-lot="row.id" @click="select(row.id)">
    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.eye" /></svg>
    {{ row.lot_reference }} · {{ formatDate(row.acquired_on) }} - {{ formatDate(row.maturity_on) }}
   </button>
   <button v-if="next" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="busy" @click="refresh(true)">
    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>
    {{ t('securitiesDebt.more') }}
   </button>
   <p v-if="!rows.length && !busy" class="text-sm text-neutral-500">{{ t('securitiesDebt.empty') }}</p>
  </section>

  <form v-if="creating || detail" ref="form" class="space-y-4 rounded-lg border bg-surface p-4" @submit.prevent>
   <fieldset :disabled="busy || !!preview" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
    <template v-if="creating">
     <label v-for="field in (['lot_reference','instrument_identity','contract_reference','classification_reference'] as const)" :key="field">
      {{ t(`securitiesDebt.${field}`) }}
      <input v-model="terms[field]" required maxlength="4000" :class="input" :data-field="field" />
     </label>
     <label v-for="field in (['nominal','clean_cost','clean_cost_czk'] as const)" :key="field">
      {{ t(`securitiesDebt.${field}`) }}
      <input v-model="terms[field]" required inputmode="decimal" :class="input" :data-field="field" />
     </label>
     <label>{{ t('securitiesDebt.debt_type') }}
      <select v-model="terms.debt_type" :class="input" data-field="debt_type">
       <option value="coupon">{{ t('securities504.options.coupon') }}</option>
       <option value="discount">{{ t('securities504.options.discount') }}</option>
      </select>
     </label>
     <label>{{ t('securitiesDebt.classification') }}
      <select v-model="terms.classification" :class="input" data-field="classification">
       <option value="trading">{{ t('securities504.options.trading') }}</option>
       <option value="held_to_maturity">{{ t('securities504.options.held_to_maturity') }}</option>
      </select>
     </label>
     <label>{{ t('securitiesDebt.acquired_on') }}<DateInput v-model="terms.acquired_on" required data-field="acquired_on" /></label>
     <label>{{ t('securitiesDebt.maturity_on') }}<DateInput v-model="terms.maturity_on" required data-field="maturity_on" /></label>
     <template v-if="terms.debt_type === 'coupon'">
      <label v-for="field in (['acquired_interest','acquired_interest_czk'] as const)" :key="field">
       {{ t(`securitiesDebt.${field}`) }}
       <input v-model="terms[field]" required inputmode="decimal" :class="input" :data-field="field" />
      </label>
     </template>
     <label v-if="terms.classification === 'held_to_maturity'" class="sm:col-span-2">
      {{ t('securitiesDebt.amortisation_reference') }}
      <input v-model="amortisationReference" required maxlength="4000" :class="input" data-field="amortisation-reference" />
     </label>
    </template>

    <template v-else-if="detail">
     <div class="space-y-2 sm:col-span-2">
      <h2 class="flex flex-wrap items-center gap-2 font-semibold">
       {{ detail.facts.terms.lot_reference }} · {{ detail.facts.currency }}
       <span v-if="detail.sold_on" class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-600" data-field="sold">{{ t('securitiesDebt.sold') }} · {{ formatDate(detail.sold_on) }}</span>
      </h2>
      <dl class="grid grid-cols-1 gap-x-4 gap-y-1 text-sm sm:grid-cols-2 lg:grid-cols-3" data-section="summary">
       <div v-for="item in summary" :key="item.key" class="flex flex-wrap justify-between gap-2 border-b border-neutral-200 py-1">
        <dt class="text-neutral-500">{{ t(`securitiesDebt.${item.key}`) }}</dt>
        <dd class="whitespace-nowrap font-medium tabular-nums">{{ item.value }}</dd>
       </div>
      </dl>
     </div>
     <label>{{ t('securitiesDebt.date') }}<DateInput v-model="date" required data-field="date" @change="select(detail!.id)" /></label>
     <label>{{ t('securitiesDebt.operation') }}
      <select v-model="operation" :class="input" data-field="operation">
       <option v-for="op in operations" :key="op" :value="op">{{ t(`securitiesDebt.${op}`) }}</option>
      </select>
     </label>
     <label class="sm:col-span-2">{{ t('securitiesDebt.evidence') }}<textarea v-model="evidence" required maxlength="4000" :class="input" data-field="evidence" /></label>
     <label v-if="operation === 'principal_redemption' || operation === 'sale'">{{ t(operation === 'sale' ? 'securitiesDebt.sale_nominal_amount' : 'securitiesDebt.nominal_amount') }}<input v-model="nominal" required inputmode="decimal" :class="input" data-field="nominal_amount" /></label>
     <p v-if="operation === 'sale'" class="text-sm text-neutral-500 sm:col-span-2" data-field="sale-scope">{{ t('securitiesDebt.saleScope') }}</p>
     <label v-if="operation === 'reverse'" class="sm:col-span-2">{{ t('securitiesDebt.reverse_event') }}
      <select v-model.number="reverseEvent" required :class="input" data-field="reverse_event">
       <option :value="0">{{ t('securities504.choose') }}</option>
       <option v-for="event in reversible" :key="event.id" :value="event.id">{{ t(`securitiesDebt.${event.request.operation}`) }} · {{ formatDate(event.request.accounting_date) }} · {{ money(event.receipt.book_change_czk) }}</option>
      </select>
     </label>
    </template>
   </fieldset>

   <fieldset v-if="creating && terms.debt_type === 'coupon'" :disabled="busy || !!preview" class="space-y-3" data-section="coupon-periods">
    <legend class="font-medium">{{ t('securitiesDebt.coupon_periods') }}</legend>
    <article v-for="(period, index) in terms.coupon_periods" :key="index" class="grid grid-cols-1 gap-3 rounded border p-3 sm:grid-cols-2">
     <label>{{ t('securitiesDebt.period_from') }}<DateInput v-model="period.from" required /></label>
     <label>{{ t('securitiesDebt.period_to') }}<DateInput v-model="period.to_exclusive" required /></label>
     <label>{{ t('securitiesDebt.annual_rate_fraction') }}<input v-model="period.annual_rate_fraction" required inputmode="decimal" :class="input" /></label>
     <label>{{ t('securitiesDebt.day_count') }}
      <select v-model="period.day_count" :class="input" :data-field="`day_count-${index}`">
       <option value="ACT/ACT-ICMA">{{ t('securitiesDebt.dayCountActAct') }}</option>
       <option value="ACT/365F">ACT/365F</option>
       <option value="ACT/360">ACT/360</option>
      </select>
     </label>
     <label v-if="period.day_count === 'ACT/ACT-ICMA'">{{ t('securitiesDebt.frequency') }}
      <select v-model.number="period.frequency" required :class="input" :data-field="`frequency-${index}`">
       <option v-for="f in frequencies" :key="f" :value="f">{{ t(`securitiesDebt.frequencyOptions.${f}`) }}</option>
      </select>
     </label>
     <label :class="period.day_count === 'ACT/ACT-ICMA' ? '' : 'sm:col-span-2'">{{ t('securitiesDebt.terms_reference') }}<input v-model="period.terms_reference" required maxlength="4000" :class="input" /></label>
     <div class="flex flex-wrap justify-end sm:col-span-2">
      <button type="button" :class="btnOutline('danger')" class="w-full justify-center whitespace-nowrap sm:w-auto" @click="terms.coupon_periods.splice(index, 1)">
       <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>
       {{ t('securitiesDebt.removePeriod') }}
      </button>
     </div>
    </article>
    <button type="button" :class="btnOutline('neutral')" class="w-full justify-center whitespace-nowrap sm:w-auto" data-action="period" @click="addPeriod">
     <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>
     {{ t('securitiesDebt.addPeriod') }}
    </button>
   </fieldset>

   <template v-if="(creating || payment) && !preview">
    <ActionBar :actions="sourceActions" />
    <p class="text-sm text-neutral-500">{{ operation === 'sale' && !creating ? t('securitiesDebt.proceedsHelp') : t('securitiesDebt.nativeSourceHelp') }}</p>
    <label class="block">{{ t('securitiesDebt.source') }}
     <select v-model="sourceKey" :class="input" :disabled="busy" required data-field="source">
      <option value="">{{ t('securities504.choose') }}</option>
      <option v-for="s in sources" :key="`${s.id}:${s.line_id}`" :value="`${s.id}:${s.line_id}`">
       {{ s.document_no || s.description }} · {{ formatDate(s.entry_date) }} · {{ s.account_code }} · {{ money(s.amount) }}<template v-if="s.currency_code"> · {{ money(s.amount_foreign, s.currency_code) }}</template>
      </option>
     </select>
    </label>
   </template>

   <template v-if="!creating && operation === 'valuation'">
    <ActionBar v-if="!preview" :actions="profileActions" />
    <SecuritiesDebtProfile :model="profile" :disabled="busy || !!preview" />
    <fieldset :disabled="busy || !!preview" class="grid grid-cols-1 gap-3 rounded border p-3 sm:grid-cols-2">
     <legend>{{ t('securitiesDebt.quote') }}</legend>
     <label v-for="field in ['clean_value','reference','market','market_reference','market_calendar_reference']" :key="field">{{ t(`securitiesDebt.quote_${field}`) }}<input v-model="quote[field]" required maxlength="4000" :class="input" /></label>
     <label>{{ t('securitiesDebt.last_trading_date') }}<DateInput v-model="quote.last_trading_date" required @update:model-value="quote.valuation_date = $event" /></label>
     <p class="text-sm text-neutral-500 sm:col-span-2">{{ t('securitiesDebt.cleanQuoteHelp') }}</p>
    </fieldset>
   </template>

   <ActionBar v-if="!preview" :actions="reviewActions" />

   <section v-if="preview" class="space-y-3 rounded border p-3" data-section="preview">
    <h2 class="font-semibold">{{ t(`securitiesDebt.${preview.plan.operation}`) }}</h2>
    <p class="text-sm text-neutral-500">{{ t('securitiesDebt.previewOnly') }}</p>
    <p v-if="preview.plan.amount" class="flex flex-wrap gap-x-4 gap-y-1">
     <span>{{ t('securitiesDebt.operation_amount') }}: <strong class="whitespace-nowrap tabular-nums">{{ money(preview.plan.amount, currency) }}</strong></span>
     <span>{{ t('securitiesDebt.book_change') }}: <strong class="whitespace-nowrap tabular-nums">{{ money(preview.plan.book_change_czk) }}</strong></span>
    </p>
    <p v-if="preview.plan.facts">{{ preview.plan.facts.terms.lot_reference }} · {{ money(preview.plan.facts.terms.clean_cost, preview.plan.facts.currency) }}</p>
    <table v-if="preview.plan.lines.length" class="w-full text-sm" data-section="preview-lines">
     <tbody>
      <tr v-for="(line, index) in preview.plan.lines" :key="index" class="border-b border-neutral-200">
       <td class="py-1 pr-3 font-mono">{{ line.account_code }}</td>
       <td class="py-1 pr-3">{{ t(`securitiesDebt.${line.side}`) }}</td>
       <td class="whitespace-nowrap py-1 text-right tabular-nums">{{ money(line.amount) }}</td>
      </tr>
     </tbody>
    </table>
    <p v-if="preview.plan.payment">{{ t('securitiesDebt.boundReceipt') }} · {{ formatDate(preview.plan.payment.date) }} · {{ money(preview.plan.payment.money_amount, preview.plan.payment.currency) }}</p>
    <label class="flex flex-wrap items-center gap-2"><input v-model="ack" type="checkbox" :disabled="busy" data-field="ack" />{{ t('securitiesDebt.ack') }}</label>
    <p v-if="uncertain" class="text-sm text-warning-700">{{ t('securitiesDebt.uncertain') }}</p>
    <ActionBar :actions="confirmActions" />
   </section>

   <section v-if="detail" class="space-y-2" data-section="history">
    <h2 class="font-semibold">{{ t('securitiesDebt.history') }}</h2>
    <ul class="divide-y divide-neutral-200 text-sm">
     <li v-for="event in detail.events" :key="event.id" class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1 py-1">
      <span>{{ formatDate(event.request.accounting_date) }} · {{ t(`securitiesDebt.${event.request.operation}`) }}</span>
      <span class="whitespace-nowrap tabular-nums">{{ money(event.receipt.amount, currency) }}</span>
      <span class="w-full text-neutral-500">{{ event.request.evidence }}</span>
     </li>
    </ul>
   </section>
  </form>
 </div>
</template>
