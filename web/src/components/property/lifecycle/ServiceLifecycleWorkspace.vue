<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { appIsoDate } from '@/utils/date'
import { chargeMoney as money } from '@/components/property/charges/chargeMoney'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { serviceLifecycleApi as api, type LifecycleCard, type LifecycleChoices, type LifecycleCommand, type LifecycleDetail, type LifecyclePreview, type LifecycleSource, type LifecycleCapabilities, type ServicePolicy, type ServicePolicyVersion } from '@/api/serviceLifecycle'
import { propertyDateInput as dateInput, propertyInput as input, propertyTextarea as textarea } from '@/components/property/propertyFormStyles'
import { formatDate, formatUtcDateTime } from '@/composables/useFormat'
const props = defineProps<{ supplier: number }>()
const { t } = useI18n()
const today = appIsoDate()
const year = today.slice(0, 4)
const items = ref<LifecycleCard[]>([]), next = ref<number | null>(null)
const capabilities = ref<LifecycleCapabilities>({ manage: false, post: false, reverse: false, unwind: false })
const step = ref<'finalize' | 'unwind'>('finalize')
const policyVersions = ref<ServicePolicyVersion[]>([]), policyService = ref(0), policyFrom = ref(`${year}-01-01`), policyValue = ref<ServicePolicy>('service_balance_sheet'), policyEvidence = ref('')
const governing = computed(() => {
 const scopeService = creating.value ? service.value : detail.value?.case.service_id || 0
 const start = creating.value ? from.value : detail.value?.case.period_from || ''
 return policyVersions.value.filter(v => v.service_id === scopeService && v.valid_from <= start).sort((a, b) => b.valid_from.localeCompare(a.valid_from))[0] ?? null
})
const finalizing = computed(() => detail.value?.phase === 'unwound' || (detail.value?.phase === 'estimated' && step.value === 'finalize'))
const choices = ref<LifecycleChoices>({ as_of: '', units: [], services: [], accounts: [] })
const detail = ref<LifecycleDetail | null>(null), creating = ref(false), busy = ref(false), error = ref(''), notice = ref('')
const unit = ref(0), service = ref(0), from = ref(`${year}-01-01`), to = ref(`${year}-12-31`), policy = ref<ServicePolicy>('service_balance_sheet'), evidence = ref(''), account = ref('')
const sourceKind = ref<'native' | 'opening'>('native'), source0 = ref(0), source1 = ref(0), opening = ref(0), coverageTitle = ref(''), date = ref(today), finalTitle = ref(''), invoiceKey = ref('')
const sources = ref<LifecycleSource[]>([]), sourceNext = ref<number | null>(null), sourceFrom = ref(`${year}-01-01`), sourceTo = ref(`${year}-12-31`)
const preview = ref<LifecyclePreview | null>(null), reviewedCommand = ref<LifecycleCommand | null>(null), token = ref(''), acknowledged = ref(false), uncertain = ref(false)
let alive = true, generation = 0, sourceGeneration = 0
onBeforeUnmount(() => { alive = false; generation++ })
function invalidate() { preview.value = null; reviewedCommand.value = null; token.value = ''; acknowledged.value = false; uncertain.value = false }
const command = computed<LifecycleCommand | null>(() => {
 if (creating.value) {
  if (!unit.value || !service.value || !account.value || !evidence.value.trim() || !from.value || !to.value || from.value > to.value) return null
  const scope = { unit_id: unit.value, service_id: service.value, period_from: from.value, period_to: to.value, policy: policy.value, evidence: evidence.value, service_cost_account: account.value }
  if (sourceKind.value === 'opening') return opening.value ? { ...scope, operation: 'start_opening', opening_item_id: opening.value } : null
  const ids = policy.value === 'service_balance_sheet' ? [source0.value] : [source0.value, source1.value]
  return ids.every(Boolean) && new Set(ids).size === ids.length && coverageTitle.value.trim() ? { ...scope, operation: 'start', entry_ids: ids, coverage_title: coverageTitle.value } : null
 }
 if (!date.value) return null
 if (detail.value?.phase === 'estimated' && step.value === 'unwind') return { operation: 'unwind', case_id: detail.value.id, accounting_date: date.value }
 if (detail.value && finalizing.value) {
  const [invoice, line] = invoiceKey.value.split(':').map(Number)
  return invoice && line && finalTitle.value.trim() ? { operation: 'finalize', case_id: detail.value.id, accounting_date: date.value, invoice_id: invoice, line_id: line, title: finalTitle.value } : null
 }
 return null
})
watch(command, invalidate, { deep: true })
watch([unit, service, from, to, sourceKind, policy, sourceFrom, sourceTo], () => { sourceGeneration++; sources.value = []; sourceNext.value = null; source0.value = source1.value = opening.value = 0; invoiceKey.value = ''; invalidate() })
watch(step, () => { invoiceKey.value = ''; invalidate() })
const allowed = computed(() => creating.value ? capabilities.value.manage : detail.value?.phase === 'estimated' && step.value === 'unwind' ? capabilities.value.unwind : finalizing.value ? capabilities.value.post : false)
const finalOptions = computed(() => sources.value.flatMap(s => (s.lines || []).filter(l => l.side === 'debit' && (detail.value?.case.policy === 'service_balance_sheet' ? /^315\.2/.test(l.account_code) : /^5/.test(l.account_code))).map(l => ({ key: `${s.invoice_id}:${l.id}`, label: `${s.label} · ${l.account_code} · ${l.amount} CZK` }))))
function label(source: LifecycleSource) { return `${source.label} ${(source.lines || []).map(l => `${l.account_code} ${l.side === 'debit' ? t('propertyServiceLifecycle.debit') : t('propertyServiceLifecycle.credit')} ${l.amount}`).join(' / ')}` }
function message(e: unknown) {
 const response = (e as { response?: { status?: number; data?: { error?: { code?: string; message?: string } } } }).response
 const code = response?.data?.error?.code || ''
 const reasons: Record<string, string> = { settlement_lifecycle_opening_policy_required: 'openingPolicyMissing', settlement_lifecycle_case_overlap: 'caseOverlap', settlement_lifecycle_source_capacity: 'sourceUsed', settlement_lifecycle_source_role: 'sourceMismatch', settlement_lifecycle_source_tuple: 'sourceMismatch', settlement_lifecycle_source_partition: 'sourceMismatch', settlement_lifecycle_estimate_coverage: 'sourceMismatch', settlement_lifecycle_estimate_not_unwound: 'estimateNotUnwound', settlement_lifecycle_event_exists: 'finalExists', settlement_lifecycle_source_reversed: 'sourceReversed', settlement_lifecycle_unwind_with_final_required: 'unwindWithFinalRequired', settlement_lifecycle_unwind_period: 'unwindPeriod', settlement_lifecycle_policy_mismatch: 'policyMismatch', settlement_lifecycle_policy_period_split: 'policyPeriodSplit', settlement_lifecycle_policy_conflict: 'policyConflict', settlement_lifecycle_policy_version_exists: 'policyVersionExists', settlement_lifecycle_period_missing: 'periodMissing' }
 if (reasons[code]) return t(`propertyServiceLifecycle.${reasons[code]}`)
 if (code === 'settlement_preview_stale' || response?.status === 409 && !code) return t('propertyServiceLifecycle.stale')
 return response?.data?.error?.message || t('propertyServiceLifecycle.error')
}
async function refresh(append = false) {
 const ticket = ++generation; busy.value = true; error.value = ''; invalidate()
 try {
  const page = await api.list(props.supplier, append ? next.value || 0 : 0)
  const catalog = await api.choices(props.supplier, date.value)
  const policies = await api.policies(props.supplier)
  const found = detail.value ? await api.detail(props.supplier, detail.value.id) : null
  if (!alive || ticket !== generation) return
  items.value = append ? [...items.value, ...page.items] : page.items; next.value = page.next_cursor; capabilities.value = page.capabilities; choices.value = catalog; policyVersions.value = policies.items
  if (found) detail.value = found
 }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function declarePolicy() {
 if (busy.value || !capabilities.value.manage || !policyService.value || !policyFrom.value || !policyEvidence.value.trim()) return
 const ticket = ++generation; busy.value = true; error.value = ''; notice.value = ''
 try {
  await api.declarePolicy(props.supplier, { service_id: policyService.value, valid_from: policyFrom.value, policy: policyValue.value, evidence: policyEvidence.value })
  if (!alive || ticket !== generation) return
  policyEvidence.value = ''; notice.value = t('propertyServiceLifecycle.policyDeclared'); busy.value = false
  await refresh()
 }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
watch(governing, version => { if (creating.value && version) policy.value = version.policy })
function eventLabel(key: string) { return key === 'estimate' ? t('propertyServiceLifecycle.estimate') : key === 'final' ? t('propertyServiceLifecycle.finalize') : t('propertyServiceLifecycle.unwind') }
const policyActions = computed<ActionItem[]>(() => [{ key: 'declare-policy', label: t('propertyServiceLifecycle.declarePolicy'), icon: 'check', variant: 'primary', tier: 'primary', disabled: busy.value || !capabilities.value.manage || !policyService.value || !policyFrom.value || !policyEvidence.value.trim(), run: () => { void declarePolicy() } }])
async function selectCase(id: number) {
 const ticket = ++generation; busy.value = true; error.value = ''; invalidate(); creating.value = false; sources.value = []; invoiceKey.value = ''
 try { const found = await api.detail(props.supplier, id); if (!alive || ticket !== generation) return; detail.value = found; capabilities.value = found.capabilities; sourceFrom.value = `${date.value.slice(0, 4)}-01-01`; sourceTo.value = `${date.value.slice(0, 4)}-12-31` }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
function create() { generation++; detail.value = null; creating.value = true; sources.value = []; source0.value = source1.value = opening.value = 0; invalidate(); notice.value = '' }
async function loadSources(append = false) {
 const sourceTicket = sourceGeneration
 const ticket = ++generation; busy.value = true; error.value = ''; invalidate()
 const scope = creating.value ? { unit: unit.value, service: service.value } : { unit: detail.value?.case.unit_id || 0, service: detail.value?.case.service_id || 0 }
 const kind = creating.value ? sourceKind.value === 'opening' ? 'openings' : 'estimates' : 'invoices'
 try { const page = await api.sources(props.supplier, kind, { unit_id: scope.unit, service_id: scope.service, from: creating.value ? from.value : sourceFrom.value, to: creating.value ? to.value : sourceTo.value, after_id: append ? sourceNext.value || 0 : 0 }); if (!alive || ticket !== generation || sourceTicket !== sourceGeneration) return; sources.value = append ? [...sources.value, ...page.items] : page.items; sourceNext.value = page.next_cursor }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function review() {
 if (!command.value || !allowed.value || busy.value) return
 const candidate = structuredClone(command.value), ticket = ++generation; busy.value = true; error.value = ''; invalidate()
 try { const result = await api.preview(props.supplier, candidate); if (!alive || ticket !== generation) return; reviewedCommand.value = candidate; preview.value = result; token.value = crypto.randomUUID() }
 catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function confirm() {
 if (!preview.value || !reviewedCommand.value || !acknowledged.value || !allowed.value || busy.value) return
 const ticket = ++generation; busy.value = true; error.value = ''
 try { const result = await api.execute(props.supplier, reviewedCommand.value, preview.value, token.value); if (!alive || ticket !== generation) return; invalidate(); notice.value = t('propertyServiceLifecycle.done'); busy.value = false; await selectCase(result.case_id); await refresh() }
 catch (e) { if (!alive || ticket !== generation) return; if ([400, 401, 403, 404, 409, 422].includes((e as { response?: { status?: number } }).response?.status || 0)) { invalidate(); error.value = message(e) } else { uncertain.value = true; error.value = t('propertyServiceLifecycle.uncertain') } }
 finally { if (alive && ticket === generation) busy.value = false }
}
const actions = computed<ActionItem[]>(() => [{ key: 'new', label: t('propertyServiceLifecycle.newCase'), icon: 'plus', variant: 'primary', tier: 'primary', disabled: busy.value || !capabilities.value.manage, run: create }, { key: 'refresh', label: t('propertyServiceLifecycle.refresh'), icon: 'cycle', disabled: busy.value, run: () => { void refresh() } }])
const sourceActions = computed<ActionItem[]>(() => [{ key: 'sources', label: t('propertyServiceLifecycle.loadSources'), icon: 'search', disabled: busy.value, run: () => { void loadSources() } }, { key: 'source-more', label: t('propertyServiceLifecycle.sourceMore'), icon: 'plus', show: sourceNext.value !== null, disabled: busy.value, run: () => { void loadSources(true) } }])
const reviewActions = computed<ActionItem[]>(() => [{ key: 'preview', label: t('propertyServiceLifecycle.preview'), icon: 'eye', variant: 'primary', tier: 'primary', disabled: busy.value || !command.value || !allowed.value || !!preview.value, run: () => { void review() } }])
const confirmActions = computed<ActionItem[]>(() => [{ key: 'confirm', label: t('propertyServiceLifecycle.confirm'), icon: 'check', variant: reviewedCommand.value?.operation === 'unwind' ? 'warning' : 'success', tier: 'primary', disabled: busy.value || !acknowledged.value || !allowed.value, run: () => { void confirm() } }, { key: 'cancel', label: t('propertyServiceLifecycle.cancel'), icon: 'x', disabled: busy.value, run: invalidate }])
onMounted(() => { void refresh() })
</script>
<template>
 <div class="space-y-5"><div><h1 class="text-2xl font-semibold">{{ t('propertyServiceLifecycle.title') }}</h1><p class="text-sm text-neutral-500 mt-0.5">{{ t('propertyServiceLifecycle.help') }}</p></div><p v-if="error" role="alert" class="rounded border border-danger-300 p-3">{{ error }}</p><p v-if="notice" role="status">{{ notice }}</p><ActionBar :actions="actions" />
 <section class="space-y-2"><p v-if="!items.length && !busy">{{ t('propertyServiceLifecycle.empty') }}</p><div class="flex flex-wrap gap-2"><button v-for="item in items" :key="item.id" :class="btnOutline('neutral')" :disabled="busy" @click="selectCase(item.id)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.eye" /></svg>{{ item.unit_label }} · {{ item.service_label }} · {{ formatDate(item.period_from) }} - {{ formatDate(item.period_to) }} · {{ t(`propertyServiceLifecycle.${item.policy}`) }}</button><button v-if="next !== null" :class="btnOutline('neutral')" :disabled="busy" @click="refresh(true)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('propertyServiceLifecycle.more') }}</button></div></section>
 <section class="space-y-3 rounded-lg border border-neutral-200 bg-surface p-4" data-section="policies"><h2 class="font-semibold">{{ t('propertyServiceLifecycle.policies') }}</h2><p class="text-sm text-neutral-500">{{ t('propertyServiceLifecycle.policiesHelp') }}</p>
  <p v-if="!policyVersions.length && !busy" class="text-sm text-neutral-500">{{ t('propertyServiceLifecycle.policiesEmpty') }}</p>
  <ul v-else class="space-y-1 text-sm"><li v-for="v in policyVersions" :key="v.id" class="flex flex-wrap gap-2"><span class="font-medium">{{ v.service_label }}</span><span>{{ t('propertyServiceLifecycle.validFrom') }} {{ formatDate(v.valid_from) }}</span><span>{{ t(`propertyServiceLifecycle.${v.policy}`) }}</span><span class="text-neutral-500">{{ t(`propertyServiceLifecycle.origin.${v.origin}`) }}</span></li></ul>
  <fieldset v-if="capabilities.manage" :disabled="busy" class="grid gap-3 sm:grid-cols-2"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.service') }}<select v-model.number="policyService" :class="input" data-field="policy-service"><option :value="0">{{ t('propertyServiceLifecycle.choose') }}</option><option v-for="v in choices.services" :key="v.id" :value="v.id">{{ v.label }}</option></select></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.validFrom') }}<DateInput :class="dateInput" v-model="policyFrom" required data-field="policy-from" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.policy') }}<select v-model="policyValue" :class="input" data-field="policy-value"><option value="service_balance_sheet">{{ t('propertyServiceLifecycle.service_balance_sheet') }}</option><option value="service_profit_and_loss">{{ t('propertyServiceLifecycle.service_profit_and_loss') }}</option></select></label><label class="sm:col-span-2 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.policyEvidence') }}<textarea v-model="policyEvidence" :class="textarea" data-field="policy-evidence" /></label></fieldset>
  <ActionBar v-if="capabilities.manage" :actions="policyActions" />
 </section>
 <section v-if="creating || detail" class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-4">
  <fieldset :disabled="busy || !!preview" class="grid gap-3 sm:grid-cols-2">
   <template v-if="creating"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.unit') }}<select v-model.number="unit" :class="input" data-field="unit"><option :value="0">{{ t('propertyServiceLifecycle.choose') }}</option><option v-for="v in choices.units" :key="v.id" :value="v.id">{{ v.label }}</option></select></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.service') }}<select v-model.number="service" :class="input" data-field="service"><option :value="0">{{ t('propertyServiceLifecycle.choose') }}</option><option v-for="v in choices.services" :key="v.id" :value="v.id">{{ v.label }}</option></select></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.from') }}<DateInput :class="dateInput" v-model="from" required data-field="from" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.to') }}<DateInput :class="dateInput" v-model="to" required data-field="to" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.policy') }}<select v-model="policy" :class="input" data-field="policy" :disabled="!!governing"><option value="service_balance_sheet">{{ t('propertyServiceLifecycle.service_balance_sheet') }}</option><option value="service_profit_and_loss">{{ t('propertyServiceLifecycle.service_profit_and_loss') }}</option></select></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.account') }}<select v-model="account" :class="input" data-field="account"><option value="">{{ t('propertyServiceLifecycle.choose') }}</option><option v-for="a in choices.accounts" :key="a.id" :value="a.code">{{ a.code }} · {{ a.label }}</option></select></label><p v-if="governing" class="sm:col-span-2 text-sm text-neutral-500" data-field="governing">{{ t('propertyServiceLifecycle.governingPolicy', { from: formatDate(governing.valid_from) }) }}</p><label class="sm:col-span-2 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.evidence') }}<textarea v-model="evidence" :class="textarea" data-field="evidence" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.sourceKind') }}<select v-model="sourceKind" :class="input" data-field="source-kind"><option value="native">{{ t('propertyServiceLifecycle.native') }}</option><option value="opening">{{ t('propertyServiceLifecycle.opening') }}</option></select></label></template>
   <template v-else-if="detail"><p>{{ t(`propertyServiceLifecycle.${detail.case.policy}`) }} · {{ formatDate(detail.case.period_from) }} - {{ formatDate(detail.case.period_to) }}</p><p>{{ t(`propertyServiceLifecycle.${detail.phase}`) }}</p><label v-if="detail.phase === 'estimated'" class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.nextStep') }}<select v-model="step" :class="input" data-field="step"><option value="finalize">{{ t('propertyServiceLifecycle.stepFinalize') }}</option><option value="unwind">{{ t('propertyServiceLifecycle.stepUnwind') }}</option></select></label><p>{{ t('propertyServiceLifecycle.estimate') }}: {{ money(detail.estimate_cents) }} CZK</p><p v-if="detail.final_cents">{{ t('propertyServiceLifecycle.final') }}: {{ money(detail.final_cents) }} CZK</p><label class="flex min-w-0 flex-col gap-1 text-sm" v-if="detail.phase !== 'finalized'">{{ t('propertyServiceLifecycle.date') }}<DateInput :class="dateInput" v-model="date" required data-field="date" /></label><template v-if="finalizing"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.from') }}<DateInput :class="dateInput" v-model="sourceFrom" data-field="source-from" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.to') }}<DateInput :class="dateInput" v-model="sourceTo" data-field="source-to" /></label></template></template>
  </fieldset>
  <template v-if="creating || finalizing"><ActionBar v-if="!preview" :actions="sourceActions" /><p v-if="!sources.length && !busy" class="text-sm text-neutral-500">{{ t('propertyServiceLifecycle.noSources') }}</p><fieldset :disabled="busy || !!preview" class="grid gap-3 sm:grid-cols-2"><template v-if="creating && sourceKind === 'native'"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t(policy === 'service_profit_and_loss' ? 'propertyServiceLifecycle.expenseEstimate' : 'propertyServiceLifecycle.source') }}<select v-model.number="source0" :class="input" data-field="source0"><option :value="0">{{ t('propertyServiceLifecycle.choose') }}</option><option v-for="s in sources" :key="s.id" :value="s.id">{{ label(s) }}</option></select></label><label class="flex min-w-0 flex-col gap-1 text-sm" v-if="policy === 'service_profit_and_loss'">{{ t('propertyServiceLifecycle.coverageEstimate') }}<select v-model.number="source1" :class="input" data-field="source1"><option :value="0">{{ t('propertyServiceLifecycle.choose') }}</option><option v-for="s in sources" :key="s.id" :value="s.id">{{ label(s) }}</option></select></label><label class="sm:col-span-2 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.coverageTitle') }}<textarea v-model="coverageTitle" :class="textarea" data-field="coverage-title" /></label></template><label class="flex min-w-0 flex-col gap-1 text-sm" v-else-if="creating">{{ t('propertyServiceLifecycle.opening') }}<select v-model.number="opening" :class="input" data-field="opening"><option :value="0">{{ t('propertyServiceLifecycle.choose') }}</option><option v-for="s in sources" :key="s.id" :value="s.id">{{ s.label }}</option></select></label><template v-else><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.invoice') }}<select v-model="invoiceKey" :class="input" data-field="invoice"><option value="">{{ t('propertyServiceLifecycle.choose') }}</option><option v-for="o in finalOptions" :key="o.key" :value="o.key">{{ o.label }}</option></select></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyServiceLifecycle.finalTitle') }}<textarea v-model="finalTitle" :class="textarea" data-field="final-title" /></label></template></fieldset></template>
  <p v-if="!allowed && detail?.phase !== 'finalized'" class="text-sm">{{ t('propertyServiceLifecycle.readonly') }}</p><ActionBar v-if="!preview" :actions="reviewActions" />
  <section v-if="preview" data-section="preview" class="space-y-3 rounded border border-neutral-200 bg-surface p-3"><h2 class="font-semibold">{{ t(`propertyServiceLifecycle.${preview.plan.operation}`) }}</h2><p>{{ t('propertyServiceLifecycle.previewOnly') }}</p><p>{{ t('propertyServiceLifecycle.estimate') }}: {{ money(preview.plan.estimate_cents) }} CZK · {{ t('propertyServiceLifecycle.final') }}: {{ preview.plan.final_cents ? money(preview.plan.final_cents) : '-' }} CZK</p><div v-for="(j, i) in preview.plan.journals" :key="i"><p>{{ formatDate(j.accounting_date) }}</p><p v-for="(line, n) in j.lines" :key="n">{{ line.account_code }} · {{ t(`propertyServiceLifecycle.${line.side}`) }} · {{ line.amount }} CZK</p></div><label class="flex flex-wrap items-center gap-2"><input v-model="acknowledged" type="checkbox" :disabled="busy" data-field="ack" />{{ t('propertyServiceLifecycle.ack') }}</label><p v-if="uncertain">{{ t('propertyServiceLifecycle.uncertain') }}</p><ActionBar :actions="confirmActions" /></section>
  <p v-if="detail?.phase === 'finalized'">{{ t('propertyServiceLifecycle.readyForSettlement') }}</p><section v-if="detail"><h2>{{ t('propertyServiceLifecycle.history') }}</h2><p v-if="!detail.events.length" class="text-sm text-neutral-500">{{ t('propertyServiceLifecycle.noHistory') }}</p><p v-for="e in detail.events" :key="e.event_key">{{ formatUtcDateTime(e.recorded_at) }} · {{ eventLabel(e.event_key) }}</p></section>
 </section></div>
</template>
