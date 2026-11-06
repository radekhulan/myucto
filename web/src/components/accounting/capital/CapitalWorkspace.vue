<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, toRaw, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import MultiSelectFilter from '@/components/ui/MultiSelectFilter.vue'
import { formDateInput as dateInput, formInput as input, formLabel, formTextarea as textarea } from '@/components/ui/formStyles'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { appIsoDate } from '@/utils/date'
import { formatDate } from '@/composables/useFormat'
import { capitalApi as api, type CapitalChoices, type CapitalConfirmation, type CapitalEvent, type CapitalOperation, type CapitalOverview, type CapitalPolicy, type CapitalPreview, type CapitalSource } from '@/api/nonprofitCapital'
import type { AccountingPeriod } from '@/api/accounting'
import { fundingCents } from '../funding/fundingMoney'
const props = defineProps<{ supplier: number; canManage: boolean }>()
const { t, locale } = useI18n()
const overview = ref<CapitalOverview | null>(null), periods = ref<AccountingPeriod[]>([]), period = ref(0), choices = ref<CapitalChoices | null>(null)
const mode = ref<'register' | 'fund' | 'reverse' | null>(null), policy = ref<CapitalPolicy | null>(null), source = ref<CapitalSource | null>(null), event = ref<CapitalEvent | null>(null)
const cost = ref(0), amount = ref(''), date = ref(appIsoDate()), reason = ref(''), search = ref({ source_search: '', cost_search: '', provider_search: '', asset_search: '' })
const preview = ref<CapitalPreview | null>(null), confirmation = ref<CapitalConfirmation | null>(null), acknowledged = ref(false), uncertain = ref(false), busy = ref(false), error = ref(''), notice = ref('')

let alive = true, generation = 0, revision = 0
const openPeriods = computed(() => periods.value.filter(p => p.status === 'open'))
const chosenPeriod = computed(() => periods.value.find(p => p.id === period.value))
const filteredSources = computed(() => choices.value?.sources.filter(s => s.account_code.startsWith(policy.value?.kind === 'investment' ? '901' : '911')) || [])
function invalidate() { preview.value = null; confirmation.value = null; acknowledged.value = false; uncertain.value = false }
watch([policy, cost, amount, date, reason, mode, period], () => { revision++; invalidate() }, { deep: true, flush: 'sync' })
watch(() => props.supplier, () => { overview.value = null; void reload() })
watch(() => props.canManage, () => { generation++; invalidate(); busy.value = false })
onBeforeUnmount(() => { alive = false; generation++ })
const loading = computed(() => busy.value && overview.value === null)
const activityOptions = computed(() => (choices.value?.activities || []).map(a => ({ value: String(a.id), label: a.label })))
const accountOptions = computed(() => (choices.value?.accounts || []).map(a => ({ value: a.code, label: `${a.code} · ${a.label}` })))
function setActivities(values: string[]) { if (policy.value) policy.value.activity_kind_ids = values.map(Number) }
function setAccounts(values: string[]) { if (policy.value) policy.value.account_prefixes = [...values] }
function range(from: string, to: string) { return `${formatDate(from)} – ${formatDate(to)}` }
function money(cents: number) { return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'CZK' }).format(cents / 100) }
function failure(e: unknown) { const response = (e as { response?: { status?: number; data?: { error?: { message?: string } } } }).response; return response?.status === 409 ? t('nonprofitCapital.stale') : response?.data?.error?.message || t('nonprofitCapital.error') }
async function reload() {
 const ticket = ++generation; busy.value = true; error.value = ''; invalidate(); mode.value = null; choices.value = null
 try { const [data, available] = await Promise.all([api.read(props.supplier), api.periods(props.supplier)]); if (!alive || ticket !== generation) return; overview.value = data; periods.value = available; period.value = available.find(p => p.status === 'open' && p.starts_on <= appIsoDate() && p.ends_on >= appIsoDate())?.id || available.find(p => p.status === 'open')?.id || 0 }
 catch (e) { if (alive && ticket === generation) error.value = failure(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function loadChoices() {
 if (!period.value) return
 const ticket = ++generation; busy.value = true; error.value = ''; invalidate()
 try { const data = await api.choices(props.supplier, period.value, { ...search.value }); if (!alive || ticket !== generation) return; choices.value = data }
 catch (e) { if (alive && ticket === generation) { choices.value = null; error.value = failure(e) } }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function start(kind: 'register' | 'fund' | 'reverse', selected: CapitalSource | null = null, original: CapitalEvent | null = null) {
 if (!props.canManage || busy.value) return
 mode.value = kind; source.value = selected; event.value = original; cost.value = 0; amount.value = ''; reason.value = ''; notice.value = ''; search.value = { source_search: '', cost_search: '', provider_search: '', asset_search: '' }
 if (original) period.value = original.receipt.entry.period_id
 const p = chosenPeriod.value; date.value = original?.receipt.entry.entry_date || p?.ends_on || appIsoDate()
 policy.value = kind === 'register' ? { kind: 'investment', source_line_id: 0, asset_id: null, provider_id: null, revenue_tax_regime_id: 0, title: '', purpose: '', evidence: '', decision_authority: '', valid_from: p?.starts_on || appIsoDate(), valid_to: p?.ends_on || appIsoDate(), activity_kind_ids: [], account_prefixes: [] } : null
 await loadChoices()
}
const operation = computed<CapitalOperation | null>(() => {
 if (!choices.value || choices.value.period.id !== period.value || !openPeriods.value.some(p => p.id === period.value) || !reason.value.trim()) return null
 if (mode.value === 'register') { const p = policy.value; if (!p || !p.source_line_id || !filteredSources.value.some(s => s.id === p.source_line_id) || !p.title.trim() || !p.purpose.trim() || !p.evidence.trim() || !p.decision_authority.trim() || !p.revenue_tax_regime_id || !p.activity_kind_ids.length || !p.account_prefixes.length || !p.valid_from || !p.valid_to || p.valid_from > p.valid_to || p.kind === 'investment' && (!p.asset_id || !p.provider_id)) return null; return { operation: 'register', policy: { ...p, asset_id: p.kind === 'investment' ? p.asset_id : null, activity_kind_ids: [...p.activity_kind_ids], account_prefixes: [...p.account_prefixes] } } }
 if (!date.value) return null
 if (mode.value === 'fund' && source.value && cost.value && choices.value.costs.some(c => c.id === cost.value)) { const cents = fundingCents(amount.value); return cents ? { operation: 'fund', source_id: source.value.id, cost_line_id: cost.value, amount_cents: cents, date: date.value } : null }
 return mode.value === 'reverse' && event.value ? { operation: 'reverse', event_id: event.value.id, date: date.value } : null
})
async function review() {
 if (!operation.value || !props.canManage || busy.value) return
 const body = structuredClone(operation.value), supplier = props.supplier, form = revision, ticket = ++generation; busy.value = true; error.value = ''; invalidate()
 try { const result = await api.preview(supplier, body); if (!alive || ticket !== generation || form !== revision || supplier !== props.supplier) return; preview.value = result; confirmation.value = { ...body, input_hash: result.input_hash, reason: reason.value, idempotency_key: crypto.randomUUID() } }
 catch (e) { if (alive && ticket === generation) error.value = failure(e) }
 finally { if (alive && ticket === generation) busy.value = false }
}
async function confirm() {
 if (!confirmation.value || !preview.value || !acknowledged.value || busy.value || !props.canManage) return
 const body = structuredClone(toRaw(confirmation.value)), supplier = props.supplier, ticket = ++generation; busy.value = true; error.value = ''
 try { await api.confirm(supplier, body); if (!alive || ticket !== generation || supplier !== props.supplier) return; notice.value = t('nonprofitCapital.done'); busy.value = false; await reload() }
 catch (e) { if (!alive || ticket !== generation) return; const status = (e as { response?: { status?: number } }).response?.status || 0; if ([400, 401, 403, 404, 409, 422].includes(status)) { invalidate(); error.value = failure(e) } else { uncertain.value = true; error.value = t('nonprofitCapital.uncertain') } }
 finally { if (alive && ticket === generation) busy.value = false }
}
const actions = computed<ActionItem[]>(() => [{ key: 'new', label: t('nonprofitCapital.new'), icon: 'plus', tier: 'primary', variant: 'primary', disabled: busy.value || !openPeriods.value.length, show: props.canManage, run: () => start('register') }, { key: 'refresh', label: t('nonprofitCapital.refresh'), icon: 'cycle', variant: 'neutral', disabled: busy.value, run: reload }])
const formActions = computed<ActionItem[]>(() => [{ key: 'preview', label: t('nonprofitCapital.preview'), icon: 'search', tier: 'primary', variant: 'primary', disabled: busy.value || !operation.value || uncertain.value, run: review }, { key: 'confirm', label: t(uncertain.value ? 'nonprofitCapital.retry' : 'nonprofitCapital.confirm'), icon: 'check', variant: mode.value === 'reverse' ? 'danger' : 'success', disabled: busy.value || !preview.value || !acknowledged.value, run: confirm }])
onMounted(reload)
</script>
<template>
 <div class="space-y-5">
  <h1 class="text-xl font-semibold">{{ t('nonprofitCapital.title') }}</h1>
  <p class="text-sm text-neutral-500">{{ t('nonprofitCapital.subtitle') }}</p>
  <p class="rounded border bg-surface p-4 text-sm">{{ t('nonprofitCapital.explanation') }}</p>
  <p v-if="!canManage" role="status" class="text-sm text-neutral-500" data-section="readonly">{{ t('nonprofitCapital.readOnly') }}</p>
  <p v-if="error" role="alert" class="rounded border border-danger-300 p-3" data-section="workspace-error">{{ error }}</p>
  <p v-if="notice" role="status" class="text-sm text-success-700" data-section="workspace-notice">{{ notice }}</p>
  <ActionBar :actions="actions" />
  <section v-if="mode && canManage" class="space-y-4 rounded border bg-surface p-4">
   <h2 class="font-semibold">{{ t(`nonprofitCapital.mode.${mode}`) }}</h2>
   <fieldset :disabled="busy || uncertain" class="space-y-4">
    <label :class="formLabel">{{ t('nonprofitCapital.period') }}<select v-model.number="period" data-field="period" :class="input" @change="loadChoices"><option :value="0">{{ t('nonprofitCapital.choose') }}</option><option v-for="p in openPeriods" :key="p.id" :value="p.id">{{ range(p.starts_on, p.ends_on) }}</option></select></label>
    <template v-if="mode === 'register' && policy">
     <label :class="formLabel">{{ t('nonprofitCapital.kind') }}<select v-model="policy.kind" data-field="kind" :class="input" @change="policy.source_line_id = 0"><option value="investment">{{ t('nonprofitCapital.investment') }}</option><option value="fund">{{ t('nonprofitCapital.fund') }}</option></select></label>
     <div class="grid gap-3 md:grid-cols-2"><label :class="formLabel">{{ t('nonprofitCapital.sourceSearch') }}<input v-model="search.source_search" data-field="search_source_search" :class="input" maxlength="200" /></label><label :class="formLabel">{{ t('nonprofitCapital.providerSearch') }}<input v-model="search.provider_search" data-field="search_provider_search" :class="input" maxlength="200" /></label><label v-if="policy.kind === 'investment'" :class="formLabel">{{ t('nonprofitCapital.assetSearch') }}<input v-model="search.asset_search" data-field="search_asset_search" :class="input" maxlength="200" /></label></div>
     <button type="button" :class="btnOutline('neutral')" @click="loadChoices"><svg aria-hidden="true" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.search" /></svg>{{ t('nonprofitCapital.search') }}</button>
     <label :class="formLabel">{{ t('nonprofitCapital.source') }}<select v-model.number="policy.source_line_id" data-field="source_line_id" :class="input"><option :value="0">{{ t('nonprofitCapital.choose') }}</option><option v-for="s in filteredSources" :key="s.id" :value="s.id">{{ formatDate(s.date) }} · {{ s.account_code }} · {{ money(s.amount_cents) }} · {{ s.description }}</option></select></label>
     <div class="grid gap-3 md:grid-cols-2"><label :class="formLabel">{{ t('nonprofitCapital.provider') }}<select v-model="policy.provider_id" data-field="provider_id" :class="input"><option :value="null">{{ t('nonprofitCapital.none') }}</option><option v-for="p in choices?.providers" :key="p.id" :value="p.id">{{ p.label }}</option></select></label><label v-if="policy.kind === 'investment'" :class="formLabel">{{ t('nonprofitCapital.asset') }}<select v-model="policy.asset_id" data-field="asset_id" :class="input"><option :value="null">{{ t('nonprofitCapital.choose') }}</option><option v-for="a in choices?.assets" :key="a.id" :value="a.id">{{ a.label }}</option></select></label></div>
     <label :class="formLabel">{{ t('nonprofitCapital.sourceTitle') }}<input v-model="policy.title" data-field="title" :class="input" maxlength="2000" /></label><label :class="formLabel">{{ t('nonprofitCapital.purpose') }}<textarea v-model="policy.purpose" data-field="purpose" :class="textarea" maxlength="2000" /></label><label :class="formLabel">{{ t('nonprofitCapital.evidence') }}<textarea v-model="policy.evidence" data-field="evidence" :class="textarea" maxlength="2000" /></label><label :class="formLabel">{{ t('nonprofitCapital.authority') }}<input v-model="policy.decision_authority" data-field="decision_authority" :class="input" maxlength="2000" /></label>
     <div class="grid gap-3 md:grid-cols-2"><label :class="formLabel">{{ t('nonprofitCapital.validFrom') }}<DateInput :class="dateInput" v-model="policy.valid_from" data-field="valid_from" /></label><label :class="formLabel">{{ t('nonprofitCapital.validTo') }}<DateInput :class="dateInput" v-model="policy.valid_to" data-field="valid_to" /></label></div>
     <div class="flex flex-wrap gap-3">
      <div :class="formLabel">{{ t('nonprofitCapital.activities') }}<MultiSelectFilter data-field="activity_kind_ids" :model-value="policy.activity_kind_ids.map(String)" :options="activityOptions" :label="t('nonprofitCapital.choose')" :active-label="t('nonprofitCapital.activities')" @update:model-value="setActivities" /></div>
      <div :class="formLabel">{{ t('nonprofitCapital.accounts') }}<MultiSelectFilter data-field="account_prefixes" :model-value="policy.account_prefixes" :options="accountOptions" :label="t('nonprofitCapital.choose')" :active-label="t('nonprofitCapital.accounts')" @update:model-value="setAccounts" /></div>
     </div><label :class="formLabel">{{ t('nonprofitCapital.taxRegime') }}<select v-model.number="policy.revenue_tax_regime_id" data-field="revenue_tax_regime_id" :class="input"><option :value="0">{{ t('nonprofitCapital.choose') }}</option><option v-for="a in choices?.tax_regimes" :key="a.id" :value="a.id">{{ a.label }}</option></select></label>
    </template>
    <template v-else-if="mode === 'fund'"><p>{{ source?.policy.title }}</p><label :class="formLabel">{{ t('nonprofitCapital.costSearch') }}<input v-model="search.cost_search" data-field="search_cost_search" :class="input" maxlength="200" /></label><button type="button" :class="btnOutline('neutral')" @click="loadChoices"><svg aria-hidden="true" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.search" /></svg>{{ t('nonprofitCapital.search') }}</button><label :class="formLabel">{{ t('nonprofitCapital.cost') }}<select v-model.number="cost" data-field="cost" :class="input"><option :value="0">{{ t('nonprofitCapital.choose') }}</option><option v-for="c in choices?.costs" :key="c.id" :value="c.id">{{ formatDate(c.date) }} · {{ c.account_code }} · {{ money(c.amount_cents) }} · {{ c.description }}</option></select></label><label :class="formLabel">{{ t('nonprofitCapital.amount') }}<input v-model="amount" data-field="amount" inputmode="decimal" :class="input" /></label></template>
    <p v-else>{{ t('nonprofitCapital.reverseExplanation') }} {{ event ? money(event.amount_cents) : '' }}</p>
    <p v-if="choices && (choices.sources_more || choices.costs_more || choices.providers_more || choices.assets_more)" class="text-sm text-warning-700">{{ t('nonprofitCapital.more') }}</p>
    <label v-if="mode !== 'register'" :class="formLabel">{{ t('nonprofitCapital.date') }}<DateInput :class="dateInput" v-model="date" data-field="date" /></label><label :class="formLabel">{{ t('nonprofitCapital.reason') }}<textarea v-model="reason" data-field="reason" :class="textarea" maxlength="2000" /></label>
   </fieldset>
   <section v-if="preview" class="space-y-3 rounded border p-3" data-testid="capital-preview"><h3 class="font-semibold">{{ t('nonprofitCapital.reviewTitle') }}</h3><p v-if="preview.basis">{{ t('nonprofitCapital.registerReview', { amount: money(preview.basis.source.amount_cents) }) }}</p><p v-if="preview.basis?.asset">{{ t('nonprofitCapital.assetBasis', { amount: money(preview.basis.asset.valuation_cents) }) }}</p><p v-if="preview.plan?.cost_capacity">{{ t('nonprofitCapital.costAvailable', { amount: money(preview.plan.cost_capacity.available_cents) }) }}</p><div v-for="(line, index) in preview.plan?.journal.lines" :key="index" class="flex flex-wrap gap-3"><span>{{ line.account_code }}</span><span>{{ t(`nonprofitCapital.side.${line.side}`) }}</span><span>{{ money(Number(line.amount) * 100) }}</span></div><label class="flex items-start gap-2"><input v-model="acknowledged" data-field="acknowledged" type="checkbox" :disabled="busy" />{{ t('nonprofitCapital.acknowledge') }}</label></section>
   <ActionBar :actions="formActions" />
  </section>
  <p v-if="loading" role="status" class="text-sm text-neutral-500" data-section="loading">{{ t('common.loading') }}</p>
  <p v-else-if="overview && !overview.sources.length && !busy" class="rounded border p-5 text-neutral-500" data-section="empty">{{ t('nonprofitCapital.empty') }}</p>
  <article v-for="row in overview?.sources" :key="row.source.id" class="space-y-3 rounded border bg-surface p-4"><div class="flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-semibold">{{ row.source.policy.title }}</h2><p class="text-sm text-neutral-500">{{ t(`nonprofitCapital.${row.source.kind === 'investment' ? 'investment' : 'fund'}`) }} · {{ row.source.basis.source.account_code }} · {{ formatDate(row.source.basis.source.date) }}</p></div><button v-if="canManage && row.source.kind === 'fund'" type="button" :class="btnOutline('success')" :disabled="busy || !openPeriods.length || row.available_cents <= 0" data-action="draw" @click="start('fund', row.source)"><svg aria-hidden="true" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('nonprofitCapital.draw') }}</button></div><p>{{ row.source.policy.purpose }}</p><p class="text-sm">{{ row.source.basis.provider?.company_name || [row.source.basis.provider?.first_name, row.source.basis.provider?.last_name].filter(Boolean).join(' ') }}</p><dl class="grid gap-3 sm:grid-cols-3"><div><dt class="text-sm text-neutral-500">{{ t('nonprofitCapital.total') }}</dt><dd>{{ money(row.source.amount_cents) }}</dd></div><div><dt class="text-sm text-neutral-500">{{ t('nonprofitCapital.used') }}</dt><dd>{{ money(row.used_cents) }}</dd></div><div><dt class="text-sm text-neutral-500">{{ t('nonprofitCapital.available') }}</dt><dd>{{ money(row.available_cents) }}</dd></div></dl><details><summary class="cursor-pointer">{{ t('nonprofitCapital.details') }}</summary><div class="space-y-2 pt-3"><p>{{ row.source.policy.evidence }}</p><p>{{ row.source.policy.decision_authority }}</p><p>{{ range(row.source.policy.valid_from, row.source.policy.valid_to) }}</p><p>{{ row.source.policy.account_prefixes.join(', ') }}</p><p v-if="row.source.kind === 'investment'">{{ t('nonprofitCapital.automatic') }}</p><div v-for="e in row.events" :key="e.id" class="flex flex-wrap items-center justify-between gap-3 border-t pt-2"><span>{{ formatDate(e.receipt.entry.entry_date) }} · {{ t(`nonprofitCapital.event.${e.operation}`) }} · {{ money(e.amount_cents) }}</span><button v-if="canManage && e.active" type="button" :class="btnOutline('danger')" :disabled="busy" data-action="reverse" @click="start('reverse', row.source, e)"><svg aria-hidden="true" class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('nonprofitCapital.reverse') }}</button></div></div></details></article>
 </div>
</template>
