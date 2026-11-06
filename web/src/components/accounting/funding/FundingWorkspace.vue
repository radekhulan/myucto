<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, toRaw, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import MultiSelectFilter from '@/components/ui/MultiSelectFilter.vue'
import { formDateInput as dateInput, formInput as input, formLabel, formTextarea as textarea } from '@/components/ui/formStyles'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { appIsoDate } from '@/utils/date'
import { formatDate } from '@/composables/useFormat'
import { fundingApi as api, type FundingChoices, type FundingConfirmation, type FundingOverview, type FundingPolicy, type FundingPreview, type FundingSource } from '@/api/nonprofitFunding'
import type { AccountingPeriod } from '@/api/accounting'
import { fundingCents } from './fundingMoney'
const props = defineProps<{ supplier: number; canManage: boolean }>()
const { t, locale } = useI18n()
const overview = ref<FundingOverview | null>(null), periods = ref<AccountingPeriod[]>([]), period = ref(0), choices = ref<FundingChoices | null>(null)
const draft = ref<FundingPolicy | null>(null), sourceKey = ref(''), editing = ref(false), providerLabel = ref(''), search = ref(''), budget = ref(''), reason = ref('')
const membershipUpdated = ref(false)
const busy = ref(false), error = ref(''), notice = ref(''), preview = ref<FundingPreview | null>(null), confirmation = ref<FundingConfirmation | null>(null), acknowledged = ref(false), uncertain = ref(false)

let alive = true, generation = 0, formRevision = 0
const openPeriods = computed(() => periods.value.filter(p => p.status === 'open' || p.status === 'closing'))
const chosenPeriod = computed(() => periods.value.find(p => p.id === period.value))
const selectedSource = computed(() => choices.value?.sources.find(s => s.anchor === draft.value?.source_anchor))
const policy = computed<FundingPolicy | null>(() => {
 const d = draft.value, amount = fundingCents(budget.value)
 if (!d || !amount || !d.title.trim() || !d.purpose.trim() || !d.evidence.trim() || !d.provider_id || !d.valid_from || !d.valid_to || d.valid_from > d.valid_to || !d.activity_kind_ids.length || !d.account_prefixes.length || !d.source_anchor || !d.source_members.length || (!editing.value && !selectedSource.value)) return null
 return { ...d, budget_cents: amount, activity_kind_ids: [...d.activity_kind_ids], account_prefixes: [...d.account_prefixes], source_members: [...d.source_members] }
})
function invalidate() { preview.value = null; confirmation.value = null; acknowledged.value = false; uncertain.value = false }
watch([draft, budget, reason, period], () => { formRevision++; invalidate() }, { deep: true, flush: 'sync' })
onBeforeUnmount(() => { alive = false; generation++ })
const loading = computed(() => busy.value && overview.value === null)
const activityOptions = computed(() => (choices.value?.activities || []).map(a => ({ value: String(a.id), label: a.label })))
const accountOptions = computed(() => (choices.value?.accounts || []).map(a => ({ value: a.code, label: a.label })))
function setActivities(values: string[]) { if (draft.value) draft.value.activity_kind_ids = values.map(Number) }
function setAccounts(values: string[]) { if (draft.value) draft.value.account_prefixes = [...values] }
function range(from: string, to: string) { return `${formatDate(from)} – ${formatDate(to)}` }
function money(cents: number | null) { return cents === null ? t('nonprofitFunding.unknown') : new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'CZK' }).format(cents / 100) }
function message(e: unknown) {
 const response = (e as { response?: { status?: number; data?: { error?: { message?: string } } } }).response
 return response?.status === 409 ? t('nonprofitFunding.stale') : response?.data?.error?.message || t('nonprofitFunding.error')
}
async function reload() {
 const ticket = ++generation; busy.value = true; error.value = ''; invalidate(); draft.value = null; choices.value = null
 try {
  const [data, available] = await Promise.all([api.read(props.supplier), api.periods(props.supplier)])
  if (!alive || ticket !== generation) return
  overview.value = data; periods.value = available
  if (!available.some(p => p.id === period.value)) period.value = available.find(p => p.starts_on <= appIsoDate() && p.ends_on >= appIsoDate())?.id || available.find(p => p.status === 'open' || p.status === 'closing')?.id || available[0]?.id || 0
 } catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function loadChoices() {
 if (!period.value) return
 const ticket = ++generation, requestedPeriod = period.value; busy.value = true; error.value = ''; invalidate()
 try {
  const data = await api.choices(props.supplier, requestedPeriod, search.value)
  if (!alive || ticket !== generation || period.value !== requestedPeriod) return
  choices.value = data
  const corrected = editing.value && draft.value ? data.sources.find(s => s.anchor === draft.value!.source_anchor) : null
  if (corrected && draft.value && JSON.stringify([...corrected.members].sort()) !== JSON.stringify([...draft.value.source_members].sort())) { draft.value.source_members = [...corrected.members]; membershipUpdated.value = true }
 }
 catch (e) { if (alive && ticket === generation) { choices.value = null; error.value = message(e) } }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function create() {
 membershipUpdated.value = false; editing.value = false; sourceKey.value = `funding-${crypto.randomUUID()}`; budget.value = ''; reason.value = ''; search.value = ''; notice.value = ''; providerLabel.value = ''
 const p = chosenPeriod.value
 draft.value = { kind: 'gift', title: '', purpose: '', evidence: '', provider_id: 0, budget_cents: 0, valid_from: p?.starts_on || appIsoDate(), valid_to: p?.ends_on || appIsoDate(), activity_kind_ids: [], account_prefixes: [], source_anchor: '', source_members: [] }
 await loadChoices()
}
async function edit(source: FundingSource) {
 membershipUpdated.value = false; editing.value = true; sourceKey.value = source.source_key; draft.value = structuredClone(toRaw(source.revision.policy)); budget.value = (source.revision.policy.budget_cents / 100).toFixed(2); reason.value = ''; search.value = ''; notice.value = ''
 const p = source.revision.basis.provider; providerLabel.value = p.company_name || [p.first_name, p.last_name].filter(Boolean).join(' ')
 if (!openPeriods.value.some(p => p.id === period.value)) period.value = openPeriods.value[0]?.id || 0
 await loadChoices()
}
function sourceChanged() {
 if (!draft.value || !selectedSource.value) return
 draft.value.kind = selectedSource.value.kind; draft.value.source_members = [...selectedSource.value.members]
 if (!budget.value) budget.value = (selectedSource.value.amount_cents / 100).toFixed(2)
}
async function review() {
 if (!policy.value || busy.value || !props.canManage || !reason.value.trim() || !openPeriods.value.some(p => p.id === period.value)) return
 const candidate = structuredClone(policy.value), revision = formRevision, targetPeriod = period.value, key = sourceKey.value, supplier = props.supplier
 const ticket = ++generation; busy.value = true; error.value = ''; invalidate()
 try {
  const result = await api.preview(supplier, targetPeriod, key, candidate)
  if (!alive || ticket !== generation || revision !== formRevision || supplier !== props.supplier) return
  preview.value = result; confirmation.value = { source_key: key, policy: candidate, input_hash: result.input_hash, expected_revision: result.expected_revision, reason: reason.value, idempotency_key: crypto.randomUUID() }
 } catch (e) { if (alive && ticket === generation) error.value = message(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function confirm() {
 if (!confirmation.value || !preview.value || !acknowledged.value || busy.value || !props.canManage) return
 const body = structuredClone(toRaw(confirmation.value)), supplier = props.supplier, targetPeriod = period.value, ticket = ++generation; busy.value = true; error.value = ''
 try { await api.confirm(supplier, targetPeriod, body); if (!alive || ticket !== generation || supplier !== props.supplier) return; notice.value = t('nonprofitFunding.done'); busy.value = false; await reload() }
 catch (e) {
  if (!alive || ticket !== generation) return
  const status = (e as { response?: { status?: number } }).response?.status || 0
  if ([400, 401, 403, 404, 409, 422].includes(status)) { invalidate(); error.value = message(e) }
  else { uncertain.value = true; error.value = t('nonprofitFunding.uncertain') }
 } finally { if (alive && ticket === generation) busy.value = false }
}
const actions = computed<ActionItem[]>(() => [{ key: 'new', label: t('nonprofitFunding.new'), icon: 'plus', tier: 'primary', variant: 'primary', show: props.canManage, disabled: busy.value || !openPeriods.value.length, run: () => { void create() } }, { key: 'reload', label: t('nonprofitFunding.reload'), icon: 'cycle', disabled: busy.value, run: () => { void reload() } }])
const reviewActions = computed<ActionItem[]>(() => [{ key: 'preview', label: t('nonprofitFunding.preview'), icon: 'eye', tier: 'primary', variant: 'primary', disabled: busy.value || !policy.value || !reason.value.trim() || !props.canManage || !openPeriods.value.some(p => p.id === period.value), run: () => { void review() } }])
const confirmActions = computed<ActionItem[]>(() => [{ key: 'confirm', label: t('nonprofitFunding.confirm'), icon: 'check', tier: 'primary', variant: 'success', disabled: busy.value || !acknowledged.value || !props.canManage, run: () => { void confirm() } }, { key: 'cancel', label: t('nonprofitFunding.cancel'), icon: 'x', disabled: busy.value, run: invalidate }])
watch(() => props.supplier, () => { generation++; void reload() })
onMounted(() => { void reload() })
</script>
<template>
 <div class="space-y-5">
  <h1 class="text-xl font-semibold">{{ t('nonprofitFunding.title') }}</h1>
  <p class="text-sm text-neutral-500">{{ t('nonprofitFunding.help') }}</p>
  <p v-if="!canManage" role="status" class="text-sm text-neutral-500" data-section="readonly">{{ t('nonprofitFunding.readOnly') }}</p>
  <p v-if="error" role="alert" class="rounded border border-danger-300 p-3" data-section="workspace-error">{{ error }}</p>
  <p v-if="notice" role="status" class="text-sm text-success-700" data-section="workspace-notice">{{ notice }}</p>
  <ActionBar :actions="actions" />
  <p v-if="overview && !overview.complete" role="status" class="rounded border border-warning-300 p-3">{{ t('nonprofitFunding.incomplete') }}</p>
  <label :class="[formLabel, 'max-w-lg']">{{ t('nonprofitFunding.period') }}<select v-model.number="period" :class="input" :disabled="busy || !!preview" data-field="period" @change="draft && loadChoices()"><option :value="0">{{ t('nonprofitFunding.choose') }}</option><option v-for="p in periods" :key="p.id" :value="p.id">{{ range(p.starts_on, p.ends_on) }} · {{ t(`nonprofitFunding.period_${p.status}`) }}</option></select></label>
  <RouterLink v-if="period" :to="{ name: 'accounting-nonprofit-tax', params: { id: period } }" :class="btnOutline('neutral')"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.doc" /></svg>{{ t('nonprofitFunding.taxReview') }}</RouterLink>
  <section v-if="draft" class="space-y-4 rounded-lg border bg-surface p-4">
   <h2 class="font-semibold">{{ t(editing ? 'nonprofitFunding.edit' : 'nonprofitFunding.new') }}</h2>
   <p v-if="choices && !choices.assessment_current && !editing" class="text-warning-700">{{ t('nonprofitFunding.assessmentRequired') }}</p>
   <p v-if="membershipUpdated" class="text-warning-700">{{ t('nonprofitFunding.correctedSource') }}</p>
   <fieldset :disabled="busy || !!preview" class="space-y-4">
    <div class="grid gap-3 sm:grid-cols-2">
     <label v-if="!editing" :class="[formLabel, 'sm:col-span-2']">{{ t('nonprofitFunding.source') }}<select v-model="draft.source_anchor" :class="input" data-field="source" @change="sourceChanged"><option value="">{{ t('nonprofitFunding.choose') }}</option><option v-for="source in choices?.sources || []" :key="source.anchor" :value="source.anchor">{{ t(`nonprofitFunding.kind_${source.kind}`) }} · {{ formatDate(source.date) }} · {{ source.account_code }} · {{ money(source.amount_cents) }} · {{ source.evidence }}</option></select></label>
     <template v-if="!editing"><label :class="formLabel">{{ t('nonprofitFunding.providerSearch') }}<input v-model="search" :class="input" data-field="provider-search" /></label><div class="flex flex-wrap items-end gap-2"><button type="button" :class="btnOutline('neutral')" data-action="search-provider" @click="loadChoices"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.search" /></svg>{{ t('nonprofitFunding.search') }}</button></div><label :class="[formLabel, 'sm:col-span-2']">{{ t('nonprofitFunding.provider') }}<select v-model.number="draft.provider_id" :class="input" data-field="provider"><option :value="0">{{ t('nonprofitFunding.choose') }}</option><option v-for="provider in choices?.providers || []" :key="provider.id" :value="provider.id">{{ provider.label }}</option></select><span v-if="choices?.providers_more" class="text-sm text-neutral-500">{{ t('nonprofitFunding.narrowSearch') }}</span></label></template>
     <p v-else class="sm:col-span-2">{{ t('nonprofitFunding.provider') }}: {{ providerLabel }} · {{ t(`nonprofitFunding.kind_${draft.kind}`) }}</p>
     <label :class="formLabel">{{ t('nonprofitFunding.name') }}<input v-model="draft.title" :class="input" data-field="title" /></label><label :class="formLabel">{{ t('nonprofitFunding.budget') }}<input v-model="budget" inputmode="decimal" :class="input" data-field="budget" /></label>
     <label :class="formLabel">{{ t('nonprofitFunding.validFrom') }}<DateInput :class="dateInput" v-model="draft.valid_from" required data-field="from" /></label><label :class="formLabel">{{ t('nonprofitFunding.validTo') }}<DateInput :class="dateInput" v-model="draft.valid_to" required data-field="to" /></label>
     <label :class="[formLabel, 'sm:col-span-2']">{{ t('nonprofitFunding.purpose') }}<textarea v-model="draft.purpose" :class="textarea" data-field="purpose" /></label><label :class="[formLabel, 'sm:col-span-2']">{{ t('nonprofitFunding.evidence') }}<textarea v-model="draft.evidence" :class="textarea" data-field="evidence" /></label>
     <div :class="formLabel">{{ t('nonprofitFunding.activities') }}<MultiSelectFilter data-field="activities" :model-value="draft.activity_kind_ids.map(String)" :options="activityOptions" :label="t('nonprofitFunding.choose')" :active-label="t('nonprofitFunding.activities')" @update:model-value="setActivities" /></div>
     <div :class="formLabel">{{ t('nonprofitFunding.accounts') }}<MultiSelectFilter data-field="accounts" :model-value="draft.account_prefixes" :options="accountOptions" :label="t('nonprofitFunding.choose')" :active-label="t('nonprofitFunding.accounts')" @update:model-value="setAccounts" /></div>
     <label :class="[formLabel, 'sm:col-span-2']">{{ t('nonprofitFunding.reason') }}<textarea v-model="reason" :class="textarea" data-field="reason" /></label>
    </div>
   </fieldset>
   <ActionBar v-if="!preview" :actions="reviewActions" />
   <div v-if="preview" class="space-y-3 rounded border p-3" data-section="preview">
    <h3 class="font-semibold">{{ t('nonprofitFunding.reviewTitle') }}</h3><p>{{ preview.policy.title }} · {{ preview.policy.purpose }}</p>
    <dl class="grid gap-3 sm:grid-cols-4"><div><dt>{{ t('nonprofitFunding.recognized') }}</dt><dd>{{ money(preview.report.recognized_cents) }}</dd></div><div><dt>{{ t('nonprofitFunding.budget') }}</dt><dd>{{ money(preview.report.budget_cents) }}</dd></div><div><dt>{{ t('nonprofitFunding.used') }}</dt><dd>{{ money(preview.report.drawdown_cents) }}</dd></div><div><dt>{{ t('nonprofitFunding.remaining') }}</dt><dd>{{ money(preview.report.budget_remaining_cents) }}</dd></div></dl>
    <p v-if="uncertain" role="status">{{ t('nonprofitFunding.uncertain') }}</p><label class="flex items-start gap-2"><input v-model="acknowledged" type="checkbox" :disabled="busy" data-field="ack" />{{ t('nonprofitFunding.ack') }}</label><ActionBar :actions="confirmActions" />
   </div>
  </section>
  <p v-if="loading" role="status" class="text-sm text-neutral-500" data-section="loading">{{ t('common.loading') }}</p>
  <p v-else-if="overview && !overview.sources.length && !busy" class="rounded border p-5 text-neutral-500" data-section="empty">{{ t('nonprofitFunding.empty') }}</p>
  <article v-for="source in overview?.sources || []" :key="source.source_key" class="space-y-3 rounded-lg border bg-surface p-4" data-section="report">
   <div class="flex flex-wrap items-center justify-between gap-3"><h2 class="font-semibold">{{ source.revision.policy.title }}</h2><span class="rounded px-2 py-1 text-sm" :class="source.source_current ? 'bg-success-50 text-success-700' : 'bg-warning-50 text-warning-700'">{{ t(source.source_current ? 'nonprofitFunding.current' : 'nonprofitFunding.reviewRequired') }}</span><button v-if="canManage" :class="btnOutline('neutral')" :disabled="busy || !!preview || !openPeriods.length" data-action="edit" @click="edit(source)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.edit" /></svg>{{ t('nonprofitFunding.edit') }}</button></div>
   <p>{{ t(`nonprofitFunding.kind_${source.revision.policy.kind}`) }} · {{ source.revision.basis.provider.company_name || [source.revision.basis.provider.first_name, source.revision.basis.provider.last_name].filter(Boolean).join(' ') }}</p><p v-if="source.revision.basis.provider.ic" class="text-sm">{{ t('nonprofitFunding.providerId') }}: {{ source.revision.basis.provider.ic }}</p><p class="text-sm text-neutral-500">{{ [source.revision.basis.provider.street, source.revision.basis.provider.zip, source.revision.basis.provider.city].filter(Boolean).join(', ') }}</p><p>{{ source.revision.policy.purpose }}</p><p class="text-sm text-neutral-500">{{ range(source.revision.policy.valid_from, source.revision.policy.valid_to) }} · {{ source.revision.policy.account_prefixes.join(', ') }}</p>
   <dl class="grid gap-3 sm:grid-cols-4"><div><dt>{{ t('nonprofitFunding.recognized') }}</dt><dd>{{ money(source.recognized_cents) }}</dd></div><div><dt>{{ t('nonprofitFunding.budget') }}</dt><dd>{{ money(source.budget_cents) }}</dd></div><div><dt>{{ t('nonprofitFunding.used') }}</dt><dd>{{ money(source.drawdown_cents) }}</dd></div><div><dt>{{ t('nonprofitFunding.remaining') }}</dt><dd>{{ money(source.budget_remaining_cents) }}</dd></div></dl>
   <p v-if="source.revision.policy.kind === 'gift'" class="text-sm">{{ t(Object.values(source.revision.basis.source_rows).find(r => r.id === source.revision.policy.source_anchor)?.decision.facts?.claim_exemption ? 'nonprofitFunding.giftExempt' : 'nonprofitFunding.giftTaxable') }}</p>
   <p v-for="row in source.revision.policy.kind === 'gift' ? Object.values(source.revision.basis.source_rows) : []" :key="row.id" class="text-sm">{{ t('nonprofitFunding.giftSource') }}: {{ formatDate(row.date) }} · {{ row.account_code }} · {{ money(row.amount_cents) }}</p>
   <details><summary class="cursor-pointer">{{ t('nonprofitFunding.useDetails') }}</summary><p v-if="!source.uses.length" class="py-2">{{ t('nonprofitFunding.noUse') }}</p><div v-for="use in source.uses" :key="`${use.assessment_id}:${use.cost.id}`" class="space-y-1 border-t py-3"><p>{{ formatDate(use.cost.date) }} · {{ use.cost.account_code }} · {{ money(use.amount_cents) }}</p><p>{{ use.evidence }}</p><p v-if="!use.current" class="text-warning-700">{{ t('nonprofitFunding.useReview') }}</p></div></details>
  </article>
 </div>
</template>
