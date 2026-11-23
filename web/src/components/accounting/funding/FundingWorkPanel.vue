<script setup lang="ts">
import { computed, onActivated, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DialogActions, { type DialogAction } from '@/components/ui/DialogActions.vue'
import DateInput from '@/components/ui/DateInput.vue'
import Modal from '@/components/ui/Modal.vue'
import MultiSelectFilter from '@/components/ui/MultiSelectFilter.vue'
import { formDateInput as dateInput, formInput as input, formLabel, formTextarea as textarea } from '@/components/ui/formStyles'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatDate, formatDateTime } from '@/composables/useFormat'
import { appIsoDate } from '@/utils/date'
import { fundingApi } from '@/api/nonprofitFunding'
import type { AccountingPeriod } from '@/api/accounting'
import type { FundingKind } from '@/api/nonprofitFunding'
import {
 fundingWorkApi as api, type FundingWorkAllocation, type FundingWorkCandidate, type FundingWorkChoices, type FundingWorkDetail,
 type FundingWorkIncome, type FundingWorkLine, type FundingWorkOverview, type FundingWorkSourceInput, type FundingWorkState,
} from '@/api/nonprofitFundingWork'
import { fundingCents } from './fundingMoney'

/** Pracovní evidence čerpání (#403): zdroje období, přiřazené výnosy a náklady se stavem vazby. */
const props = defineProps<{ supplier: number; canManage: boolean }>()
const { t, locale } = useI18n()
const periods = ref<AccountingPeriod[]>([]), period = ref(0)
const overview = ref<FundingWorkOverview | null>(null), detail = ref<FundingWorkDetail | null>(null), selected = ref(0)
const busy = ref(false), error = ref(''), notice = ref('')
let alive = true, generation = 0, mounted = false

const editable = computed(() => props.canManage && !!overview.value?.editable)
const detailEditable = computed(() => props.canManage && !!detail.value?.editable)
function money(cents: number) { return new Intl.NumberFormat(locale.value, { style: 'currency', currency: 'CZK' }).format(cents / 100) }
function amountText(cents: number) { return (cents / 100).toFixed(2) }
function range(from: string, to: string) { return `${formatDate(from)} – ${formatDate(to)}` }
function lineLabel(line: FundingWorkLine) { return [formatDate(line.date), line.document_no, line.account_code, money(line.amount_cents)].filter(Boolean).join(' · ') }
function stateClass(state: FundingWorkState) { return state === 'ok' ? 'bg-success-50 text-success-700' : 'bg-warning-50 text-warning-700' }
function message(e: unknown) {
 const response = (e as { response?: { status?: number; data?: { error?: { code?: string; message?: string } } } }).response
 const code = response?.data?.error?.code
 if (code === 'funding_work_version_conflict') return t('nonprofitFunding.work.stale')
 if (code === 'funding_work_busy') return t('nonprofitFunding.work.busy')
 return response?.data?.error?.message || t('nonprofitFunding.error')
}

async function run<T>(work: () => Promise<T>, done?: (result: T) => void): Promise<boolean> {
 const ticket = ++generation; busy.value = true; error.value = ''
 try {
  const result = await work()
  if (!alive || ticket !== generation) return false
  done?.(result)
  return true
 } catch (e) {
  if (alive && ticket === generation) error.value = message(e)
  return false
 } finally { if (alive && ticket === generation) busy.value = false }
}

async function loadPeriods() {
 await run(() => fundingApi.periods(props.supplier), list => {
  periods.value = list
  if (!list.some(p => p.id === period.value)) period.value = list.find(p => p.starts_on <= appIsoDate() && p.ends_on >= appIsoDate())?.id || list.find(p => p.status === 'open' || p.status === 'closing')?.id || list[0]?.id || 0
 })
}
async function loadOverview() {
 if (!period.value) { overview.value = null; return }
 const target = period.value
 await run(() => api.overview(props.supplier, target), data => { if (period.value === target) overview.value = data })
}
async function loadDetail() {
 if (!selected.value || !period.value) { detail.value = null; return }
 const target = selected.value
 await run(() => api.detail(props.supplier, period.value, target), data => { if (selected.value === target) detail.value = data })
}
async function reload() {
 const before = period.value
 await loadPeriods()
 // Změnu období obslouží watch(period); jinak by se přehled stahoval dvakrát.
 if (period.value !== before) return
 await loadOverview()
 if (selected.value) await loadDetail()
}
function open(id: number) { selected.value = id; notice.value = ''; void loadDetail() }
function back() { selected.value = 0; detail.value = null; void loadOverview() }
function applied(data: FundingWorkDetail, text: string) { detail.value = data; selected.value = data.source.id; notice.value = text; dialog.value = null; void loadOverview() }
/**
 * Konflikt verze: dialog se zavře (drží starou verzi i rozepsané hodnoty nad starým stavem) a načte se
 * aktuální detail. Uživatel akci otevře znovu nad novými daty, nic tiše nepřepíše.
 */
async function guarded(work: () => Promise<FundingWorkDetail>, text: string) {
 const ok = await run(work, data => applied(data, text))
 if (!ok && error.value === t('nonprofitFunding.work.stale')) { const keep = error.value; dialog.value = null; await loadDetail(); error.value = keep }
}

// ------------------------------------------------------------------ dialogy
type Dialog = { kind: 'source' } | { kind: 'costs' } | { kind: 'incomes' } | { kind: 'cancel' }
 | { kind: 'amount'; allocation: FundingWorkAllocation } | { kind: 'remove'; link: FundingWorkAllocation | FundingWorkIncome; income: boolean }
 | { kind: 'relink'; link: FundingWorkAllocation | FundingWorkIncome; income: boolean }
const dialog = ref<Dialog | null>(null)
const reason = ref(''), amount = ref(''), note = ref(''), relinkLine = ref(0)

// Formulář zdroje
const form = ref<FundingWorkSourceInput | null>(null), budget = ref(''), providerSearch = ref(''), choices = ref<FundingWorkChoices | null>(null)
const activityOptions = computed(() => (choices.value?.activities || []).map(a => ({ value: String(a.id), label: a.label })))
const accountOptions = computed(() => (choices.value?.accounts || []).map(a => ({ value: a.code, label: a.label })))
const formValid = computed(() => {
 const f = form.value
 return !!f && !!f.provider_id && !!f.title.trim() && !!fundingCents(budget.value) && !!f.valid_from && !!f.valid_to && f.valid_from <= f.valid_to
})
const editingSource = ref(false)
async function loadChoices() { await run(() => api.choices(props.supplier, period.value, providerSearch.value), data => { choices.value = data }) }
async function newSource() {
 const p = periods.value.find(x => x.id === period.value)
 editingSource.value = false; budget.value = ''; providerSearch.value = ''; reason.value = ''
 form.value = { provider_id: 0, kind: 'operating_grant', title: '', contract_ref: null, budget_cents: 0, valid_from: p?.starts_on || appIsoDate(), valid_to: p?.ends_on || appIsoDate(), activity_kind_ids: [], account_prefixes: [], legacy_source_key: null }
 dialog.value = { kind: 'source' }
 await loadChoices()
}
async function editSource() {
 if (!detail.value) return
 const s = detail.value.source
 editingSource.value = true; budget.value = amountText(s.budget_cents); providerSearch.value = s.provider_label; reason.value = ''
 form.value = { provider_id: s.provider_id, kind: s.kind, title: s.title, contract_ref: s.contract_ref, budget_cents: s.budget_cents, valid_from: s.valid_from, valid_to: s.valid_to, activity_kind_ids: [...s.activity_kind_ids], account_prefixes: [...s.account_prefixes], legacy_source_key: s.legacy_source_key }
 dialog.value = { kind: 'source' }
 await loadChoices()
}
async function saveSource() {
 const f = form.value, cents = fundingCents(budget.value)
 if (!f || !cents || !formValid.value) return
 const body: FundingWorkSourceInput = { ...f, budget_cents: cents, contract_ref: f.contract_ref?.trim() || null }
 if (editingSource.value && detail.value) {
  const d = detail.value
  await guarded(() => api.update(props.supplier, period.value, d.source.id, { ...body, expected_version: d.source.row_version, reason: reason.value }), t('nonprofitFunding.work.saved'))
 } else {
  await run(() => api.create(props.supplier, period.value, body), data => applied(data, t('nonprofitFunding.work.saved')))
 }
}

// Výběr řádků deníku (náklady nebo výnosy)
const candidates = ref<FundingWorkCandidate[]>([]), candidateMore = ref(false), candidatePage = ref(1), candidateSearch = ref('')
const picked = ref<Record<number, string>>({})
const pickedCount = computed(() => Object.keys(picked.value).length)
async function loadCandidates() {
 if (!detail.value || !dialog.value) return
 const source = detail.value.source.id, kind = dialog.value.kind, page = candidatePage.value, search = candidateSearch.value
 await run(() => kind === 'costs' ? api.costCandidates(props.supplier, period.value, source, search, page) : api.incomeCandidates(props.supplier, period.value, search, page),
  data => { candidates.value = data.rows; candidateMore.value = data.more })
}
function openCandidates(kind: 'costs' | 'incomes') { picked.value = {}; candidatePage.value = 1; candidateSearch.value = ''; candidates.value = []; dialog.value = { kind }; void loadCandidates() }
function togglePick(row: FundingWorkCandidate) {
 const next = { ...picked.value }
 if (row.id in next) delete next[row.id]
 else {
  // Předvyplní volnou část řádku, nejvýše zbývající rozpočet zdroje.
  const free = row.capacity_cents ?? row.amount_cents
  const remaining = (detail.value?.totals.remaining_cents ?? 0) - Object.values(next).reduce((sum, v) => sum + (fundingCents(v) ?? 0), 0)
  next[row.id] = amountText(remaining > 0 ? Math.min(free, remaining) : free)
 }
 picked.value = next
}
function page(delta: number) { candidatePage.value = Math.max(1, candidatePage.value + delta); void loadCandidates() }
const pickedValid = computed(() => pickedCount.value > 0 && (dialog.value?.kind !== 'costs' || Object.values(picked.value).every(v => fundingCents(v) !== null)))
async function savePicked() {
 if (!detail.value || !pickedValid.value) return
 const source = detail.value.source.id
 if (dialog.value?.kind === 'costs') {
  const items = Object.entries(picked.value).map(([line, value]) => ({ line_id: Number(line), amount_cents: fundingCents(value)! }))
  await run(() => api.allocate(props.supplier, period.value, source, items), data => applied(data, t('nonprofitFunding.work.allocatedDone')))
 } else {
  await run(() => api.addIncomes(props.supplier, period.value, source, Object.keys(picked.value).map(Number)), data => applied(data, t('nonprofitFunding.work.saved')))
 }
}

function openAmount(allocation: FundingWorkAllocation) { amount.value = amountText(allocation.amount_cents); note.value = allocation.note || ''; dialog.value = { kind: 'amount', allocation } }
async function saveAmount() {
 const d = dialog.value, cents = fundingCents(amount.value)
 if (d?.kind !== 'amount' || !cents) return
 await guarded(() => api.updateAllocation(props.supplier, period.value, d.allocation.id, cents, note.value.trim() || null, d.allocation.row_version), t('nonprofitFunding.work.saved'))
}
function openRemove(link: FundingWorkAllocation | FundingWorkIncome, income: boolean) { reason.value = ''; dialog.value = { kind: 'remove', link, income } }
async function saveRemove() {
 const d = dialog.value
 if (d?.kind !== 'remove' || !reason.value.trim()) return
 await guarded(() => d.income ? api.removeIncome(props.supplier, period.value, d.link.id, d.link.row_version, reason.value) : api.removeAllocation(props.supplier, period.value, d.link.id, d.link.row_version, reason.value), t('nonprofitFunding.work.removed'))
}
function openRelink(link: FundingWorkAllocation | FundingWorkIncome, income: boolean) {
 const first = link.relink_candidates?.[0]
 relinkLine.value = first?.id || 0
 amount.value = amountText(first && !income ? Math.min(link.amount_cents, first.amount_cents) : link.amount_cents)
 dialog.value = { kind: 'relink', link, income }
}
watch(relinkLine, line => {
 const d = dialog.value
 if (d?.kind !== 'relink' || d.income) return
 const target = d.link.relink_candidates?.find(c => c.id === line)
 if (target) amount.value = amountText(Math.min(d.link.amount_cents, target.amount_cents))
})
async function saveRelink() {
 const d = dialog.value, cents = fundingCents(amount.value)
 if (d?.kind !== 'relink' || !relinkLine.value || (!d.income && !cents)) return
 await guarded(() => d.income ? api.relinkIncome(props.supplier, period.value, d.link.id, relinkLine.value, d.link.row_version)
  : api.relinkAllocation(props.supplier, period.value, d.link.id, relinkLine.value, cents, d.link.row_version), t('nonprofitFunding.work.relinked'))
}
async function saveCancel() {
 const d = detail.value
 if (!d || !reason.value.trim()) return
 await guarded(() => api.cancel(props.supplier, period.value, d.source.id, d.source.row_version, reason.value), t('nonprofitFunding.work.cancelled'))
}
function close() { if (!busy.value) { dialog.value = null; error.value = '' } }

// ------------------------------------------------------------------ akce
const listActions = computed<ActionItem[]>(() => [
 { key: 'new', label: t('nonprofitFunding.work.newSource'), icon: 'plus', tier: 'primary', variant: 'primary', show: props.canManage, disabled: busy.value || !editable.value, disabledReason: overview.value && !overview.value.editable ? t('nonprofitFunding.work.periodClosed') : undefined, run: () => { void newSource() } },
 { key: 'reload', label: t('nonprofitFunding.reload'), icon: 'cycle', disabled: busy.value, run: () => { void reload() } },
])
const detailActions = computed<ActionItem[]>(() => [
 { key: 'add-costs', label: t('nonprofitFunding.work.addCosts'), icon: 'plus', tier: 'primary', variant: 'primary', show: props.canManage, disabled: busy.value || !detailEditable.value, run: () => openCandidates('costs') },
 { key: 'add-income', label: t('nonprofitFunding.work.addIncome'), icon: 'coin', variant: 'neutral', show: props.canManage, disabled: busy.value || !detailEditable.value, run: () => openCandidates('incomes') },
 { key: 'edit', label: t('nonprofitFunding.edit'), icon: 'edit', variant: 'neutral', show: props.canManage, disabled: busy.value || !detailEditable.value, run: () => { void editSource() } },
 { key: 'back', label: t('nonprofitFunding.work.back'), icon: 'chevronLeft', variant: 'neutral', disabled: busy.value, run: back },
 { key: 'cancel-source', label: t('nonprofitFunding.work.cancelSource'), icon: 'trash', tier: 'overflow', variant: 'danger', show: props.canManage, disabled: busy.value || !detailEditable.value || !!detail.value?.totals.allocation_count, disabledReason: detail.value?.totals.allocation_count ? t('nonprofitFunding.work.cancelBlocked') : undefined, run: () => { reason.value = ''; dialog.value = { kind: 'cancel' } } },
])
const dialogActions = computed<DialogAction[]>(() => {
 const d = dialog.value
 const closeAction: DialogAction = { key: 'close', label: t('nonprofitFunding.cancel'), icon: 'x', disabled: busy.value, run: close }
 if (!d) return []
 const primary = (label: string, enabled: boolean, go: () => void, variant: DialogAction['variant'] = 'primary', icon: DialogAction['icon'] = 'check'): DialogAction => ({ key: 'save', label, icon, tier: 'primary', variant, disabled: busy.value || !enabled, run: go })
 switch (d.kind) {
  case 'source': return [closeAction, primary(t('nonprofitFunding.work.save'), formValid.value, () => { void saveSource() })]
  case 'costs': case 'incomes': return [closeAction, primary(t('nonprofitFunding.work.assign', { count: pickedCount.value }), pickedValid.value, () => { void savePicked() })]
  case 'amount': return [closeAction, primary(t('nonprofitFunding.work.save'), fundingCents(amount.value) !== null, () => { void saveAmount() })]
  case 'remove': return [closeAction, primary(t('nonprofitFunding.work.remove'), !!reason.value.trim(), () => { void saveRemove() }, 'danger', 'trash')]
  case 'relink': return [closeAction, primary(t('nonprofitFunding.work.relink'), !!relinkLine.value && (d.income || fundingCents(amount.value) !== null), () => { void saveRelink() }, 'primary', 'link')]
  case 'cancel': return [closeAction, primary(t('nonprofitFunding.work.cancelSource'), !!reason.value.trim(), () => { void saveCancel() }, 'danger', 'trash')]
 }
 return [closeAction]
})
const dialogTitle = computed(() => {
 const d = dialog.value
 if (!d) return ''
 return {
  source: t(editingSource.value ? 'nonprofitFunding.work.editSource' : 'nonprofitFunding.work.newSource'), costs: t('nonprofitFunding.work.addCosts'),
  incomes: t('nonprofitFunding.work.addIncome'), amount: t('nonprofitFunding.work.editAmount'), remove: t('nonprofitFunding.work.remove'),
  relink: t('nonprofitFunding.work.relink'), cancel: t('nonprofitFunding.work.cancelSource'),
 }[d.kind]
})
const kinds: FundingKind[] = ['operating_grant', 'investment_grant', 'gift']

watch(period, () => { selected.value = 0; detail.value = null; overview.value = null; void loadOverview() })
watch(() => props.supplier, () => { generation++; selected.value = 0; detail.value = null; void reload() })
onMounted(() => { void reload().then(() => { mounted = true }) })
// Po návratu na stránku (KeepAlive) načíst znovu: řádky deníku mohl mezitím přeúčtovat nebo stornovat jiný doklad.
onActivated(() => { if (mounted) void reload() })
onBeforeUnmount(() => { alive = false; generation++ })
</script>

<template>
 <div class="space-y-5" data-section="funding-work">
  <p class="text-sm text-neutral-500">{{ t('nonprofitFunding.work.help') }}</p>
  <p v-if="!canManage" role="status" class="text-sm text-neutral-500" data-section="work-readonly">{{ t('nonprofitFunding.work.readOnly') }}</p>
  <p v-if="error && !dialog" role="alert" class="rounded border border-danger-300 p-3" data-section="work-error">{{ error }}</p>
  <p v-if="notice" role="status" class="text-sm text-success-700" data-section="work-notice">{{ notice }}</p>

  <template v-if="!selected">
   <div class="flex flex-wrap items-end gap-3">
    <label :class="[formLabel, 'w-full max-w-lg']">{{ t('nonprofitFunding.period') }}
     <select v-model.number="period" :class="input" :disabled="busy" data-field="work-period"><option v-for="p in periods" :key="p.id" :value="p.id">{{ range(p.starts_on, p.ends_on) }} · {{ t(`nonprofitFunding.period_${p.status}`) }}</option></select>
    </label>
   </div>
   <ActionBar :actions="listActions" />
   <p v-if="busy && !overview" role="status" class="text-sm text-neutral-500" data-section="work-loading">{{ t('common.loading') }}</p>
   <p v-else-if="overview && !overview.sources.length" class="rounded border p-5 text-neutral-500" data-section="work-empty">{{ t('nonprofitFunding.work.empty') }}</p>
   <template v-else-if="overview">
    <div class="hidden overflow-x-auto rounded-lg border bg-surface md:block">
     <table class="w-full text-sm" data-section="work-sources">
      <thead class="bg-neutral-50 text-left text-neutral-600"><tr>
       <th class="px-3 py-2">{{ t('nonprofitFunding.name') }}</th><th class="px-3 py-2">{{ t('nonprofitFunding.provider') }}</th>
       <th class="px-3 py-2 text-right">{{ t('nonprofitFunding.budget') }}</th><th class="px-3 py-2 text-right">{{ t('nonprofitFunding.work.received') }}</th>
       <th class="px-3 py-2 text-right">{{ t('nonprofitFunding.work.allocated') }}</th><th class="px-3 py-2 text-right">{{ t('nonprofitFunding.work.documented') }}</th>
       <th class="px-3 py-2 text-right">{{ t('nonprofitFunding.remaining') }}</th><th class="px-3 py-2">{{ t('nonprofitFunding.work.state') }}</th>
      </tr></thead>
      <tbody>
       <tr v-for="s in overview.sources" :key="s.id" class="cursor-pointer border-t hover:bg-neutral-50" :data-source="s.id" @click="open(s.id)">
        <td class="px-3 py-2"><button type="button" class="text-left font-medium text-primary-700 hover:underline" @click.stop="open(s.id)">{{ s.title }}</button><div class="text-xs text-neutral-500">{{ t(`nonprofitFunding.kind_${s.kind}`) }}<template v-if="s.contract_ref"> · {{ s.contract_ref }}</template></div></td>
        <td class="px-3 py-2">{{ s.provider_label }}</td>
        <td class="whitespace-nowrap px-3 py-2 text-right">{{ money(s.totals.budget_cents) }}</td><td class="whitespace-nowrap px-3 py-2 text-right">{{ money(s.totals.received_cents) }}</td>
        <td class="whitespace-nowrap px-3 py-2 text-right">{{ money(s.totals.allocated_cents) }}</td><td class="whitespace-nowrap px-3 py-2 text-right">{{ money(s.totals.documented_cents) }}</td>
        <td class="whitespace-nowrap px-3 py-2 text-right">{{ money(s.totals.remaining_cents) }}</td>
        <td class="px-3 py-2"><span v-if="s.status === 'cancelled'" class="rounded bg-neutral-100 px-2 py-1 text-xs text-neutral-600">{{ t('nonprofitFunding.work.cancelledBadge') }}</span><span v-else-if="s.totals.review_count" class="whitespace-nowrap rounded bg-warning-50 px-2 py-1 text-xs text-warning-700" data-badge="review">{{ t('nonprofitFunding.work.reviewCount', { count: s.totals.review_count }) }}</span><span v-else class="rounded bg-success-50 px-2 py-1 text-xs text-success-700">{{ t('nonprofitFunding.work.stateOk') }}</span></td>
       </tr>
      </tbody>
     </table>
    </div>
    <div class="space-y-3 md:hidden" data-section="work-cards">
     <button v-for="s in overview.sources" :key="s.id" type="button" class="block w-full rounded-lg border bg-surface p-4 text-left" :data-source="s.id" @click="open(s.id)">
      <span class="flex flex-wrap items-center justify-between gap-2"><span class="font-medium">{{ s.title }}</span><span v-if="s.status === 'cancelled'" class="rounded bg-neutral-100 px-2 py-1 text-xs text-neutral-600">{{ t('nonprofitFunding.work.cancelledBadge') }}</span><span v-else-if="s.totals.review_count" class="rounded bg-warning-50 px-2 py-1 text-xs text-warning-700">{{ t('nonprofitFunding.work.reviewCount', { count: s.totals.review_count }) }}</span><span v-else class="rounded bg-success-50 px-2 py-1 text-xs text-success-700">{{ t('nonprofitFunding.work.stateOk') }}</span></span>
      <span class="block text-sm text-neutral-500">{{ s.provider_label }}</span>
      <span class="mt-2 grid grid-cols-2 gap-2 text-sm"><span><span class="block text-neutral-500">{{ t('nonprofitFunding.budget') }}</span>{{ money(s.totals.budget_cents) }}</span><span><span class="block text-neutral-500">{{ t('nonprofitFunding.remaining') }}</span>{{ money(s.totals.remaining_cents) }}</span></span>
     </button>
    </div>
   </template>
  </template>

  <template v-else>
   <p v-if="busy && !detail" role="status" class="text-sm text-neutral-500">{{ t('common.loading') }}</p>
   <section v-if="detail" class="space-y-4" data-section="work-detail">
    <div class="flex flex-wrap items-center gap-3">
     <h2 class="text-lg font-semibold">{{ detail.source.title }}</h2>
     <span v-if="detail.source.status === 'cancelled'" class="rounded bg-neutral-100 px-2 py-1 text-sm text-neutral-600">{{ t('nonprofitFunding.work.cancelledBadge') }}</span>
    </div>
    <p class="text-sm text-neutral-500">{{ t(`nonprofitFunding.kind_${detail.source.kind}`) }} · {{ detail.source.provider_label }}<template v-if="detail.source.contract_ref"> · {{ detail.source.contract_ref }}</template> · {{ range(detail.source.valid_from, detail.source.valid_to) }}<template v-if="detail.source.account_prefixes.length"> · {{ detail.source.account_prefixes.join(', ') }}</template></p>
    <p v-if="detail.source.legacy_source_key" class="text-sm text-neutral-500">{{ t('nonprofitFunding.work.legacyNote') }}</p>
    <ActionBar :actions="detailActions" />
    <dl class="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6" data-section="work-totals">
     <div class="rounded-lg border bg-surface p-3"><dt class="text-xs text-neutral-500">{{ t('nonprofitFunding.budget') }}</dt><dd class="font-semibold">{{ money(detail.totals.budget_cents) }}</dd></div>
     <div class="rounded-lg border bg-surface p-3"><dt class="text-xs text-neutral-500">{{ t('nonprofitFunding.work.received') }}</dt><dd class="font-semibold">{{ money(detail.totals.received_cents) }}</dd></div>
     <div class="rounded-lg border bg-surface p-3"><dt class="text-xs text-neutral-500">{{ t('nonprofitFunding.work.allocated') }}</dt><dd class="font-semibold">{{ money(detail.totals.allocated_cents) }}</dd></div>
     <div class="rounded-lg border bg-surface p-3"><dt class="text-xs text-neutral-500">{{ t('nonprofitFunding.work.documented') }}</dt><dd class="font-semibold">{{ money(detail.totals.documented_cents) }}</dd></div>
     <div class="rounded-lg border bg-surface p-3"><dt class="text-xs text-neutral-500">{{ t('nonprofitFunding.remaining') }}</dt><dd class="font-semibold" data-total="remaining">{{ money(detail.totals.remaining_cents) }}</dd></div>
     <div class="rounded-lg border p-3" :class="detail.totals.review_count ? 'border-warning-300 bg-warning-50' : 'bg-surface'"><dt class="text-xs text-neutral-500">{{ t('nonprofitFunding.work.review') }}</dt><dd class="font-semibold" data-total="review">{{ detail.totals.review_count }} · {{ money(detail.totals.review_cents) }}</dd></div>
    </dl>

    <h3 class="font-semibold">{{ t('nonprofitFunding.work.incomes') }}</h3>
    <p v-if="!detail.incomes.length" class="text-sm text-neutral-500" data-section="work-no-income">{{ t('nonprofitFunding.work.noIncome') }}</p>
    <ul v-else class="divide-y rounded-lg border bg-surface" data-section="work-incomes">
     <li v-for="i in detail.incomes" :key="i.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 p-3 text-sm" :data-income="i.id">
      <span class="min-w-0 flex-1">{{ formatDate(i.entry_date) }} · {{ i.line?.document_no || '—' }} · {{ i.account_code }} · {{ money(i.amount_cents) }}<span v-if="i.confirmed_source_key" class="block text-xs text-neutral-500">{{ t('nonprofitFunding.work.inConfirmed') }}</span></span>
      <span class="rounded px-2 py-1 text-xs" :class="stateClass(i.state)" :data-state="i.state">{{ t(`nonprofitFunding.work.state_${i.state}`) }}</span>
      <div v-if="detailEditable" class="flex flex-wrap gap-2">
       <button v-if="i.relink_candidates?.length" type="button" :class="btnOutlineSm('primary')" data-action="relink-income" @click="openRelink(i, true)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.link" /></svg>{{ t('nonprofitFunding.work.relink') }}</button>
       <button type="button" :class="btnOutlineSm('danger')" data-action="remove-income" @click="openRemove(i, true)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('nonprofitFunding.work.remove') }}</button>
      </div>
     </li>
    </ul>

    <h3 class="font-semibold">{{ t('nonprofitFunding.work.costs') }}</h3>
    <p class="text-xs text-neutral-500">{{ t('nonprofitFunding.work.capacityNote') }}</p>
    <p v-if="!detail.allocations.length" class="rounded border p-4 text-sm text-neutral-500" data-section="work-no-costs">{{ t('nonprofitFunding.work.noCosts') }}</p>
    <ul v-else class="divide-y rounded-lg border bg-surface" data-section="work-allocations">
     <li v-for="a in detail.allocations" :key="a.id" class="flex flex-wrap items-center gap-x-4 gap-y-2 p-3 text-sm" :data-allocation="a.id">
      <span class="min-w-0 flex-1">
       {{ formatDate(a.entry_date) }} · {{ a.line?.document_no || '—' }} · {{ a.account_code }} · <strong>{{ money(a.amount_cents) }}</strong> <span class="text-neutral-500">/ {{ money(a.line_amount_cents) }}</span>
       <span v-if="a.line?.description" class="block truncate text-xs text-neutral-500">{{ a.line.description }}</span>
       <span v-if="a.note" class="block text-xs text-neutral-500">{{ a.note }}</span>
       <span v-if="a.payment_state" class="block text-xs text-neutral-500">{{ t(`nonprofitFunding.work.payment_${a.payment_state}`) }}</span>
      </span>
      <span class="rounded px-2 py-1 text-xs" :class="stateClass(a.state)" :data-state="a.state">{{ t(`nonprofitFunding.work.state_${a.state}`) }}</span>
      <div v-if="detailEditable" class="flex flex-wrap gap-2">
       <button v-if="a.relink_candidates?.length" type="button" :class="btnOutlineSm('primary')" data-action="relink" @click="openRelink(a, false)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.link" /></svg>{{ t('nonprofitFunding.work.relink') }}</button>
       <button v-if="a.state === 'ok'" type="button" :class="btnOutlineSm('neutral')" data-action="amount" @click="openAmount(a)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.edit" /></svg>{{ t('nonprofitFunding.work.editAmount') }}</button>
       <button type="button" :class="btnOutlineSm('danger')" data-action="remove" @click="openRemove(a, false)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('nonprofitFunding.work.remove') }}</button>
      </div>
     </li>
    </ul>

    <details class="rounded-lg border bg-surface p-3" data-section="work-history">
     <summary class="cursor-pointer text-sm font-medium">{{ t('nonprofitFunding.work.history') }}</summary>
     <ul class="mt-2 space-y-1 text-sm">
      <li v-for="h in detail.history" :key="h.id">{{ formatDateTime(h.created_at) }} · {{ h.actor || '—' }} · {{ t(`nonprofitFunding.work.action_${h.action}`) }}<template v-if="h.reason"> · {{ h.reason }}</template></li>
     </ul>
    </details>
   </section>
  </template>

  <Modal v-if="dialog" :title="dialogTitle" width-class="max-w-3xl" @close="close">
   <div class="space-y-4" :data-dialog="dialog.kind">
    <p v-if="error" role="alert" class="rounded border border-danger-300 p-3" data-section="dialog-error">{{ error }}</p>

    <div v-if="dialog.kind === 'source' && form" class="grid gap-3 sm:grid-cols-2">
     <label :class="formLabel">{{ t('nonprofitFunding.providerSearch') }}<span class="flex gap-2"><input v-model="providerSearch" :class="input" data-field="provider-search" @keydown.enter.prevent="loadChoices" /><button type="button" :class="btnOutlineSm('neutral')" @click="loadChoices"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.search" /></svg>{{ t('nonprofitFunding.search') }}</button></span></label>
     <label :class="formLabel">{{ t('nonprofitFunding.provider') }}<select v-model.number="form.provider_id" :class="input" data-field="provider"><option :value="0">{{ t('nonprofitFunding.choose') }}</option><option v-if="editingSource && !choices?.providers.some(p => p.id === form!.provider_id)" :value="form.provider_id">{{ detail?.source.provider_label }}</option><option v-for="p in choices?.providers || []" :key="p.id" :value="p.id">{{ p.label }}</option></select><span v-if="choices?.providers_more" class="text-xs text-neutral-500">{{ t('nonprofitFunding.narrowSearch') }}</span></label>
     <label :class="formLabel">{{ t('nonprofitFunding.name') }}<input v-model="form.title" :class="input" maxlength="255" data-field="title" /></label>
     <label :class="formLabel">{{ t('nonprofitFunding.work.kind') }}<select v-model="form.kind" :class="input" data-field="kind"><option v-for="k in kinds" :key="k" :value="k">{{ t(`nonprofitFunding.kind_${k}`) }}</option></select></label>
     <label :class="formLabel">{{ t('nonprofitFunding.work.contract') }}<input v-model="form.contract_ref" :class="input" maxlength="100" data-field="contract" /></label>
     <label :class="formLabel">{{ t('nonprofitFunding.budget') }}<input v-model="budget" inputmode="decimal" :class="input" data-field="budget" /></label>
     <label :class="formLabel">{{ t('nonprofitFunding.validFrom') }}<DateInput v-model="form.valid_from" :class="dateInput" required data-field="from" /></label>
     <label :class="formLabel">{{ t('nonprofitFunding.validTo') }}<DateInput v-model="form.valid_to" :class="dateInput" required data-field="to" /></label>
     <div :class="formLabel">{{ t('nonprofitFunding.activities') }}<MultiSelectFilter data-field="activities" :model-value="form.activity_kind_ids.map(String)" :options="activityOptions" :label="t('nonprofitFunding.work.anyActivity')" :active-label="t('nonprofitFunding.activities')" @update:model-value="v => { form!.activity_kind_ids = v.map(Number) }" /></div>
     <div :class="formLabel">{{ t('nonprofitFunding.accounts') }}<MultiSelectFilter data-field="accounts" :model-value="form.account_prefixes" :options="accountOptions" :label="t('nonprofitFunding.work.anyAccount')" :active-label="t('nonprofitFunding.accounts')" @update:model-value="v => { form!.account_prefixes = [...v] }" /></div>
     <label v-if="choices?.confirmed_sources.length" :class="[formLabel, 'sm:col-span-2']">{{ t('nonprofitFunding.work.legacy') }}<select v-model="form.legacy_source_key" :class="input" data-field="legacy"><option :value="null">{{ t('nonprofitFunding.work.noLegacy') }}</option><option v-for="c in choices.confirmed_sources" :key="c.key" :value="c.key">{{ c.label }}</option></select></label>
     <label v-if="editingSource" :class="[formLabel, 'sm:col-span-2']">{{ t('nonprofitFunding.reason') }}<textarea v-model="reason" :class="textarea" data-field="reason" /></label>
    </div>

    <template v-else-if="dialog.kind === 'costs' || dialog.kind === 'incomes'">
     <p class="text-sm text-neutral-500">{{ t(dialog.kind === 'costs' ? 'nonprofitFunding.work.costsHelp' : 'nonprofitFunding.work.incomeHelp') }}</p>
     <div class="flex flex-wrap items-end gap-2"><label :class="[formLabel, 'min-w-48 flex-1']">{{ t('nonprofitFunding.search') }}<input v-model="candidateSearch" :class="input" data-field="candidate-search" @keydown.enter.prevent="candidatePage = 1; loadCandidates()" /></label><button type="button" :class="btnOutlineSm('neutral')" @click="candidatePage = 1; loadCandidates()"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.search" /></svg>{{ t('nonprofitFunding.search') }}</button></div>
     <p v-if="!busy && !candidates.length" class="rounded border p-4 text-sm text-neutral-500" data-section="no-candidates">{{ t('nonprofitFunding.work.noCandidates') }}</p>
     <ul v-else class="max-h-96 divide-y overflow-y-auto rounded border" data-section="candidates">
      <li v-for="row in candidates" :key="row.id" class="flex flex-wrap items-center gap-3 p-2 text-sm" :data-line="row.id">
       <label class="flex min-w-0 flex-1 items-start gap-2"><input type="checkbox" :checked="row.id in picked" @change="togglePick(row)" /><span class="min-w-0">{{ lineLabel(row) }}<span v-if="row.description" class="block truncate text-xs text-neutral-500">{{ row.description }}</span><span v-if="dialog.kind === 'costs' && row.allocated_cents" class="block text-xs text-neutral-500">{{ t('nonprofitFunding.work.freeCapacity', { amount: money(row.capacity_cents ?? 0) }) }}</span></span></label>
       <input v-if="dialog.kind === 'costs' && row.id in picked" v-model="picked[row.id]" inputmode="decimal" :class="[input, 'w-32']" :aria-label="t('nonprofitFunding.work.amount')" data-field="pick-amount" />
      </li>
     </ul>
     <div class="flex flex-wrap gap-2"><button type="button" :class="btnOutlineSm('neutral')" :disabled="busy || candidatePage <= 1" @click="page(-1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.chevronLeft" /></svg>{{ t('nonprofitFunding.work.prev') }}</button><button type="button" :class="btnOutlineSm('neutral')" :disabled="busy || !candidateMore" @click="page(1)">{{ t('nonprofitFunding.work.next') }}<svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.chevronRight" /></svg></button></div>
    </template>

    <template v-else-if="dialog.kind === 'amount'">
     <p class="text-sm">{{ formatDate(dialog.allocation.entry_date) }} · {{ dialog.allocation.account_code }} · {{ money(dialog.allocation.line_amount_cents) }}</p>
     <label :class="formLabel">{{ t('nonprofitFunding.work.amount') }}<input v-model="amount" inputmode="decimal" :class="input" data-field="amount" /></label>
     <label :class="formLabel">{{ t('nonprofitFunding.work.note') }}<input v-model="note" :class="input" maxlength="500" data-field="note" /></label>
    </template>

    <template v-else-if="dialog.kind === 'relink'">
     <p class="text-sm text-neutral-500">{{ t('nonprofitFunding.work.relinkHelp') }}</p>
     <label :class="formLabel">{{ t('nonprofitFunding.work.line') }}<select v-model.number="relinkLine" :class="input" data-field="relink-line"><option v-for="line in dialog.link.relink_candidates || []" :key="line.id" :value="line.id">{{ lineLabel(line) }}</option></select></label>
     <label v-if="!dialog.income" :class="formLabel">{{ t('nonprofitFunding.work.amount') }}<input v-model="amount" inputmode="decimal" :class="input" data-field="amount" /></label>
    </template>

    <template v-else-if="dialog.kind === 'remove' || dialog.kind === 'cancel'">
     <p class="text-sm">{{ t(dialog.kind === 'cancel' ? 'nonprofitFunding.work.cancelHelp' : 'nonprofitFunding.work.removeHelp') }}</p>
     <label :class="formLabel">{{ t('nonprofitFunding.reason') }}<textarea v-model="reason" :class="textarea" data-field="reason" /></label>
    </template>
   </div>
   <DialogActions :actions="dialogActions" />
  </Modal>
 </div>
</template>
