<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useSupplierStore } from '@/stores/supplier'
import { propertyPaymentsApi, type BankProposalOverrides, type BankProposalPlan, type CreditThirdPartyBasis, type PaymentAllocationPart, type PaymentCandidate, type PaymentCandidateKind, type PaymentSourceDetails, type PaymentWorkflowContext, type PaymentWorkflowHistory, type PaymentWorkflowPreview, type PaymentWorkflowRequest } from '@/api/propertyPayments'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import PropertyPartyPicker from '@/components/property/PropertyPartyPicker.vue'
import PaymentSourceRevisionEditor from '@/components/property/payments/PaymentSourceRevisionEditor.vue'
import PaymentSourcePicker from '@/components/property/payments/PaymentSourcePicker.vue'
import BankProposalPanel from '@/components/property/payments/BankProposalPanel.vue'
import BankProposalQueue from '@/components/property/payments/BankProposalQueue.vue'
import { paymentCents, paymentDecimal } from '@/components/property/payments/paymentWorkflowForm'
import OpsTabBar from '@/components/property/payments/operations/OpsTabBar.vue'
import AllocationPartForm from '@/components/property/payments/operations/AllocationPartForm.vue'
import RefundPartForm from '@/components/property/payments/operations/RefundPartForm.vue'
import DebtsCreditsTab from '@/components/property/payments/operations/DebtsCreditsTab.vue'
import CorrectionsHistoryTab from '@/components/property/payments/operations/CorrectionsHistoryTab.vue'
import CardPanel from '@/components/property/payments/operations/CardPanel.vue'
import SaldoPanel from '@/components/property/payments/receivables/SaldoPanel.vue'
import PartySaldoPanel from '@/components/property/payments/receivables/PartySaldoPanel.vue'
import SnapshotView from '@/components/property/payments/operations/SnapshotView.vue'
import { provideBusyScope } from '@/components/property/payments/operations/busy'
import { failureMessage, formErrorKey } from '@/components/property/payments/operations/format'
import { buildAllocationPart, buildRefundPart, emptyAllocation, emptyRefund, type AllocationPartState, type RefundPartState } from '@/components/property/payments/operations/partForms'
import { propertyDateInput as dateInput, propertyInput as inputClass } from '@/components/property/propertyFormStyles'
const { t } = useI18n()
const supplierStore = useSupplierStore()
const supplier = computed(() => supplierStore.currentSupplierId)
const scope = provideBusyScope()
// Záložka a pohyb z banky se drží v URL: odkaz z výpisu i obnovení stránky vede na totéž místo.
// Bez routeru (izolované testy komponenty) zůstává stav jen v komponentě.
const route = useRoute() as ReturnType<typeof useRoute> | undefined
const router = useRouter() as ReturnType<typeof useRouter> | undefined
const TABS = ['receive', 'proposals', 'debts', 'corrections', 'card', 'saldo', 'parties']
const queryText = (name: string) => { const value = route?.query[name]; return typeof value === 'string' ? value : '' }
const tab = ref(TABS.includes(queryText('tab')) ? queryText('tab') : 'receive')
watch(tab, value => { if (route && router && route.query.tab !== value) void router.replace({ query: { ...route.query, tab: value } }) })
watch(() => queryText('tab'), value => { if (TABS.includes(value) && value !== tab.value) tab.value = value })
const requestedTransaction = computed(() => { const id = Number(queryText('bank_transaction')); return Number.isInteger(id) && id > 0 ? id : null })
const proposal = ref<BankProposalPlan | null>(null)
const proposalBusy = ref(false)
let proposalEpoch = 0
const context = ref<PaymentWorkflowContext | null>(null)
const history = ref<PaymentWorkflowHistory[]>([])
const historyNextCursor = ref<number | null>(null)
const historyLoadingMore = ref(false)
const sourceDetails = ref<PaymentSourceDetails | null>(null)
const sourceLoading = ref(false)
const revisionBusy = ref(false)
const asOf = ref(new Date().toISOString())
const mode = ref<'existing' | 'new_cash'>('existing')
const selected = ref('')
const cash = reactive({ register_id: 0, doc_type: 'in' as 'in' | 'out', issue_date: '', partner_name: '', description: '', total_amount: '' })
const date = ref('')
const busy = ref(false)
const loading = ref(false)
const error = ref('')
const done = ref(false)
const form = ref<HTMLFormElement | null>(null)
const preview = ref<PaymentWorkflowPreview | null>(null)
let submitted: PaymentWorkflowRequest | null = null
let token = crypto.randomUUID()
let epoch = 0
let sourceEpoch = 0
onBeforeUnmount(() => epoch++)
const cells = ref<Array<{ key: string; amount: string; dimensions: Record<number, number> }>>([])
const credits = ref([{ key: `part.${crypto.randomUUID()}`, amount: '', unit_id: 0, parties: [null] as Array<number | null>, evidence: '', due_date: '', reason: '', third_basis: '' as '' | CreditThirdPartyBasis, third_evidence: '', selection: {} as Record<string, string> }])
const THIRD_BASES: CreditThirdPartyBasis[] = ['assignment', 'inheritance', 'mistaken_payment', 'settlement_title', 'other_documented']
const extraCandidates = ref<PaymentCandidate[]>([])
const cursors = reactive<Record<PaymentCandidateKind, number | null>>({ bank: 0, cash: 0 })
const moreLoading = ref(false)
let moreEpoch = 0
const candidates = computed(() => {
  const seen = new Set((context.value?.candidates ?? []).map(c => `${c.kind}:${c.id}`))
  return [...(context.value?.candidates ?? []), ...extraCandidates.value.filter(c => !seen.has(`${c.kind}:${c.id}`))]
})
const moreKinds = computed(() => context.value?.candidate_coverage.truncated ? (['bank', 'cash'] as PaymentCandidateKind[]).filter(kind => cursors[kind] !== null) : [])
const candidate = computed(() => candidates.value.find(c => `${c.kind}:${c.id}` === selected.value) ?? null)
function resetMore() { moreEpoch++; moreLoading.value = false; extraCandidates.value = []; cursors.bank = 0; cursors.cash = 0 }
async function loadMore(kind: PaymentCandidateKind) {
  const id = supplier.value
  if (!id || !context.value || anyBusy.value || moreLoading.value || cursors[kind] === null) return
  const ticket = epoch; const moreTicket = ++moreEpoch; const stale = () => ticket !== epoch || moreTicket !== moreEpoch || supplier.value !== id
  moreLoading.value = true; error.value = ''
  try {
    let added = 0
    for (let pages = 0; cursors[kind] !== null && added === 0 && pages < 100; pages++) {
      const result = await propertyPaymentsApi.candidates(id, kind, cursors[kind]!, 100)
      if (stale()) return
      const known = new Set(candidates.value.map(c => `${c.kind}:${c.id}`))
      const fresh = result.items.filter(c => c.kind === kind && !known.has(`${c.kind}:${c.id}`))
      extraCandidates.value = [...extraCandidates.value, ...fresh]; added += fresh.length
      cursors[kind] = result.next_cursor
    }
  } catch (e) { if (!stale()) error.value = failure(e) } finally { if (moreTicket === moreEpoch) moreLoading.value = false }
}
const dimensionTypes = computed(() => [...new Map((context.value?.dimensions ?? []).map(v => [v.type_id, { id: v.type_id, name: v.type_name }])).values()])
const failure = (e: unknown) => failureMessage(e, t('propertyPayments.failed'))
const allocations = ref<AllocationPartState[]>([])
const refunds = ref<RefundPartState[]>([])
const sourceDate = computed(() => mode.value === 'new_cash' ? cash.issue_date || null : candidate.value?.date ?? null)
const anyBusy = computed(() => busy.value || revisionBusy.value || moreLoading.value || proposalBusy.value || scope.busy.value)
const partCount = computed(() => credits.value.length + allocations.value.length + refunds.value.length)
function invalidate() { preview.value = null; submitted = null; token = crypto.randomUUID(); done.value = false }
watch([selected, mode, date, cash, cells, credits, allocations, refunds], invalidate, { deep: true, flush: 'sync' })
watch([candidate, mode], ([value, sourceMode]) => {
  if (busy.value) return
  sourceEpoch++
  sourceLoading.value = false
  sourceDetails.value = null
  invalidate()
  if (sourceMode === 'new_cash') {
    date.value = cash.issue_date
    cells.value = cash.total_amount ? [{ key: 'unresolved', amount: cash.total_amount, dimensions: {} }] : []
    return
  }
  date.value = value?.date ?? ''
  cells.value = value && !value.existing_owner && value.currency === 'CZK' ? [{ key: 'unresolved', amount: value.amount.replace(/^-/, ''), dimensions: {} }] : []
  if (value && (value.existing_owner || value.currency === 'EUR')) void loadSource()
}, { flush: 'sync' })
async function loadSource() {
  const chosen = candidate.value; const id = supplier.value; const ticket = epoch; const sourceTicket = ++sourceEpoch
  if (mode.value !== 'existing' || !chosen || (!chosen.existing_owner && chosen.currency !== 'EUR') || !id) return
  sourceLoading.value = true; sourceDetails.value = null; invalidate()
  try {
    const detail = await propertyPaymentsApi.source(id, chosen.kind, chosen.id)
    if (ticket !== epoch || sourceTicket !== sourceEpoch || supplier.value !== id || mode.value !== 'existing' || candidate.value?.kind !== chosen.kind || candidate.value?.id !== chosen.id) return
    sourceDetails.value = detail
    cells.value = detail.cells.filter(c => c.amount_cents !== '0').map(c => ({ key: c.key, amount: paymentDecimal(c.amount_cents), dimensions: { ...c.dimensions } }))
  } catch (e) { if (ticket === epoch && sourceTicket === sourceEpoch) error.value = failure(e) } finally { if (ticket === epoch && sourceTicket === sourceEpoch) sourceLoading.value = false }
}
watch(() => cash.total_amount, amount => { if (mode.value === 'new_cash' && !busy.value && cells.value.length <= 1) cells.value = [{ key: 'unresolved', amount, dimensions: {} }] })
watch(() => cash.issue_date, value => { if (mode.value === 'new_cash') date.value = value })
function changeAsOf(value: string) { if (!anyBusy.value) asOf.value = value }
function resetParts() { allocations.value = []; refunds.value = [] }
watch(asOf, () => { epoch++; loading.value = false; resetMore(); context.value = null; history.value = []; historyNextCursor.value = null; historyLoadingMore.value = false; selected.value = ''; resetParts(); invalidate() })
async function load() {
  if (busy.value || revisionBusy.value) return
  const id = supplier.value; const ticket = ++epoch; loading.value = false; resetMore(); context.value = null; history.value = []; historyNextCursor.value = null; historyLoadingMore.value = false; selected.value = ''; resetParts(); invalidate(); error.value = ''
  proposal.value = null; proposalEpoch++; proposalBusy.value = false
  if (!id) return
  loading.value = true
  try {
    const [data, historyPage] = await Promise.all([propertyPaymentsApi.context(id, asOf.value), propertyPaymentsApi.history(id)])
    if (ticket !== epoch || id !== supplier.value) return
    context.value = data; history.value = historyPage.items; historyNextCursor.value = historyPage.next_cursor
  } catch (e) { if (ticket === epoch) error.value = failure(e) } finally { if (ticket === epoch) loading.value = false }
  if (ticket === epoch && context.value && requestedTransaction.value) void openTransaction(requestedTransaction.value)
}
/**
 * Pohyb z banky (odkaz „Spárovat na jednotku SVJ" nebo návrh z fronty): vybere ho jako
 * zdroj a předvyplní úhradu podle rozpoznané jednotky. Plán počítá stejný náhled jako
 * ruční úhrada a potvrzuje se stejným tlačítkem a tokenem; nic se nepotvrdí samo.
 */
async function openTransaction(transactionId: number, overrides: BankProposalOverrides = {}) {
  const id = supplier.value; const ticket = epoch; const current = ++proposalEpoch
  if (!id || !context.value) return
  const stale = () => ticket !== epoch || current !== proposalEpoch || supplier.value !== id
  proposalBusy.value = true; error.value = ''
  try {
    if (!candidates.value.some(c => c.kind === 'bank' && c.id === transactionId)) {
      const found = await propertyPaymentsApi.candidates(id, 'bank', 0, 1, { id: transactionId })
      if (stale()) return
      extraCandidates.value = [...extraCandidates.value, ...found.items]
    }
    if (candidates.value.some(c => c.kind === 'bank' && c.id === transactionId)) { mode.value = 'existing'; selected.value = `bank:${transactionId}` }
    if (!context.value.capabilities.manage) return
    const plan = await propertyPaymentsApi.bankProposalPlan(id, transactionId, overrides)
    if (stale()) return
    proposal.value = plan
    if (plan.status === 'proposal' && plan.request) applyProposal(plan.request, plan.resolution.unit_id, plan.preview)
  } catch (e) { if (!stale()) error.value = failure(e) } finally { if (current === proposalEpoch) proposalBusy.value = false }
}
function applyProposal(request: PaymentWorkflowRequest, unitId: number | null, ready: PaymentWorkflowPreview | null) {
  const part = request.parts[0]
  if (!part || part.request.kind !== 'allocation') return
  const allocation = part.request as PaymentAllocationPart
  const state = emptyAllocation()
  state.key = part.key; state.amount = paymentDecimal(allocation.amount_cents); state.reason = allocation.reason; state.debts = [...allocation.debts]; state.unit_id = unitId ?? 0
  if (allocation.payment) { state.facts.payer_id = allocation.payment.payer_id; state.facts.debtor_id = allocation.payment.debtor_id; state.facts.received_at = allocation.payment.received_at }
  credits.value = []; refunds.value = []; allocations.value = [state]; date.value = allocation.accounting_date
  if (ready) { preview.value = ready; submitted = request }
}
async function openQueued(transactionId: number) {
  proposal.value = null
  if (route && router && requestedTransaction.value !== transactionId) {
    // Jediná navigace se záložkou i pohybem; samostatná změna záložky by ji přepsala.
    await router.replace({ query: { ...route.query, tab: 'receive', bank_transaction: String(transactionId) } })
    tab.value = 'receive'
    return
  }
  tab.value = 'receive'
  void openTransaction(transactionId)
}
watch(requestedTransaction, id => { if (id && context.value && !loading.value) void openTransaction(id) })
function replan(overrides: BankProposalOverrides) {
  const target = proposal.value?.bank_transaction_id
  if (target) void openTransaction(target, overrides)
}
async function loadMoreHistory() {
  const id = supplier.value; const before = historyNextCursor.value; const ticket = epoch
  if (!id || before === null || historyLoadingMore.value) return
  historyLoadingMore.value = true
  try {
    const page = await propertyPaymentsApi.history(id, before, 25)
    if (ticket === epoch && supplier.value === id) { history.value = [...history.value, ...page.items]; historyNextCursor.value = page.next_cursor }
  } catch (e) { if (ticket === epoch) error.value = failure(e) } finally { if (ticket === epoch) historyLoadingMore.value = false }
}
watch(supplier, () => { epoch++; sourceEpoch++; busy.value = false; revisionBusy.value = false; sourceLoading.value = false; sourceDetails.value = null; void load() }, { immediate: true })
function addCell() { cells.value.push({ key: `cell.${crypto.randomUUID()}`, amount: '', dimensions: {} }) }
function addAllocation() { allocations.value.push(emptyAllocation()) }
function addRefund() { refunds.value.push(emptyRefund()) }
function addCredit() { credits.value.push({ key: `part.${crypto.randomUUID()}`, amount: '', unit_id: 0, parties: [null], evidence: '', due_date: '', reason: '', third_basis: '', third_evidence: '', selection: {} }) }
function request(): PaymentWorkflowRequest {
  if (!date.value || cells.value.length === 0 || partCount.value === 0) throw new Error('required')
  const source = mode.value === 'new_cash' ? { kind: 'cash' as const, draft: { ...cash, total_amount: paymentDecimal(paymentCents(cash.total_amount)) } } : candidate.value ? { kind: candidate.value.kind, id: candidate.value.id } : null
  if (!source) throw new Error('required')
  const normalizedCells = cells.value.map(cell => ({ key: cell.key, amount_cents: paymentCents(cell.amount), dimensions: Object.fromEntries(Object.entries(cell.dimensions).filter(([, value]) => value > 0)) }))
  const cashDocumentId = candidate.value?.kind === 'cash' && mode.value === 'existing' ? candidate.value.id : null
  const creditParts = credits.value.map(credit => {
    if (!credit.unit_id || !credit.evidence.trim() || !credit.reason.trim() || !credit.due_date || credit.parties.some(p => !p) || new Set(credit.parties).size !== credit.parties.length) throw new Error('required')
    if (credit.third_basis && !credit.third_evidence.trim()) throw new Error('required')
    return { key: credit.key, request: { kind: 'allocation' as const, amount_cents: paymentCents(credit.amount), accounting_date: date.value, reason: credit.reason, debts: [],
      credit_entitlement: { unit_id: credit.unit_id, party_ids: credit.parties as number[], evidence: credit.evidence, due_date: credit.due_date,
        ...(credit.third_basis ? { third_party: { basis: credit.third_basis, evidence: credit.third_evidence } } : {}) },
      ...(cells.value.length > 1 ? { clearing_selection: cells.value.filter(c => credit.selection[c.key]?.trim()).map(c => ({ key: c.key, amount_cents: paymentCents(credit.selection[c.key]!) })) } : {}) } }
  })
  const debtParts = allocations.value.map(part => buildAllocationPart(part, date.value, normalizedCells, sourceDate.value))
  const refundParts = refunds.value.map(part => buildRefundPart(part, { cashDocumentId }, date.value, normalizedCells))
  return { source, clearing_cells: normalizedCells, parts: [...creditParts, ...debtParts, ...refundParts] }
}
async function showPreview() {
  if (anyBusy.value || sourceLoading.value || !supplier.value || !context.value?.capabilities.manage) return
  if (!form.value?.reportValidity()) return
  let payload: PaymentWorkflowRequest
  try { payload = request() } catch (e) { const key = formErrorKey(e); error.value = key ? t(key) : t('propertyPayments.required'); return }
  const id = supplier.value; const ticket = epoch; busy.value = true; error.value = ''; preview.value = null
  try { const result = await propertyPaymentsApi.preview(id, payload); if (ticket === epoch && supplier.value === id) { preview.value = result; submitted = payload } }
  catch (e) { if (ticket === epoch) error.value = failure(e) } finally { if (ticket === epoch) busy.value = false }
}
async function confirm() {
  if (anyBusy.value || sourceLoading.value || !supplier.value || !preview.value || !submitted || !context.value?.capabilities.post || !form.value?.reportValidity()) return
  const id = supplier.value; const ticket = epoch; const payload = submitted; const hash = preview.value.preview_hash; busy.value = true; error.value = ''
  try {
    await propertyPaymentsApi.confirm(id, payload, hash, token)
    if (ticket !== epoch || supplier.value !== id) return
    preview.value = null; submitted = null; done.value = true
    const historyPage = await propertyPaymentsApi.history(id)
    if (ticket === epoch && supplier.value === id) { history.value = historyPage.items; historyNextCursor.value = historyPage.next_cursor }
  } catch (e) { if (ticket === epoch) error.value = failure(e) } finally { if (ticket === epoch) busy.value = false }
}
const actions = computed<ActionItem[]>(() => [preview.value ? { key: 'confirm', label: t('propertyPayments.confirm'), icon: 'check', variant: 'success', tier: 'primary', disabled: anyBusy.value || !context.value?.capabilities.post, run: () => { void confirm() } } : { key: 'preview', label: t('propertyPayments.preview'), icon: 'eye', variant: 'primary', tier: 'primary', disabled: anyBusy.value || !context.value?.capabilities.manage || done.value, run: () => { void showPreview() } },
  { key: 'refresh', label: t('propertyPayments.refresh'), icon: 'cycle', tier: 'secondary', disabled: anyBusy.value, run: () => { void load() } }])
const tabs = computed(() => TABS.map(key => ({ key, label: t(`propertyPayments.tabs.${key}`) })))
function foundSources(items: PaymentCandidate[]) {
  const known = new Set(candidates.value.map(c => `${c.kind}:${c.id}`))
  extraCandidates.value = [...extraCandidates.value, ...items.filter(c => !known.has(`${c.kind}:${c.id}`))]
}
function unitLabel(u: PaymentWorkflowContext['units'][number]) { return u.revision && 'unit_no' in u.revision.state ? t('propertyPayments.unitNumber', { number: u.revision.state.unit_no }) : u.code }
</script>

<template>
  <div class="space-y-5">
    <div><h1 class="text-2xl font-semibold">{{ t('propertyPayments.title') }}</h1><p class="text-sm text-neutral-500 mt-0.5">{{ t('propertyPayments.czkOnly') }}</p></div>
    <details class="text-sm" data-other-moment><summary class="cursor-pointer text-neutral-600">{{ t('propertyCharges.otherMoment') }}</summary><div class="flex flex-wrap items-end gap-3 pt-2"><fieldset :disabled="anyBusy" class="min-w-0"><PropertyInstantInput :model-value="asOf" @update:model-value="changeAsOf" :label="t('propertyPayments.asOf')" required /></fieldset><PropertyButton icon="cycle" :disabled="anyBusy || loading" @click="load">{{ t('propertyPayments.refresh') }}</PropertyButton></div></details>
    <p v-if="error" role="alert" class="text-danger-600">{{ error }}</p>
    <p v-if="done" role="status" class="text-success-600">{{ t('propertyPayments.done') }}</p>
    <p v-if="loading">{{ t('propertyPayments.loading') }}</p>
    <OpsTabBar v-if="context" v-model="tab" :tabs="tabs" :disabled="anyBusy" />
    <BankProposalPanel v-if="context && tab === 'receive' && proposal" :supplier="supplier ?? undefined" :plan="proposal" :units="context.units" :busy="anyBusy" @replan="replan" @close="proposal = null" />
    <BankProposalQueue v-if="context && supplier && tab === 'proposals'" :supplier="supplier" :units="context.units" :can-manage="context.capabilities.manage" @open="openQueued" />
    <p v-if="context && tab === 'receive' && !context.capabilities.manage" role="status" class="text-sm text-neutral-500" data-flag="read-only">{{ t('propertyPayments.readOnly') }}</p>
    <form v-if="context && tab === 'receive' && context.capabilities.manage" ref="form" class="space-y-4" @submit.prevent="showPreview">
      <p v-if="candidate?.currency === 'EUR'" class="rounded border border-neutral-200 p-3 text-sm" data-test="fx-source-help">{{ t('propertyPayments.fxSourceHelp') }}</p>
      <fieldset :disabled="busy || revisionBusy || moreLoading || sourceLoading || !context.capabilities.manage || done" class="space-y-4">
        <section class="rounded-lg border border-neutral-200 p-4 space-y-3">
          <h2 class="font-semibold">{{ t('propertyPayments.source') }}</h2>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.sourceMode') }}<select v-model="mode" :class="inputClass" data-field="source-mode"><option value="existing">{{ t('propertyPayments.existing') }}</option><option v-if="context.capabilities.cash" value="new_cash">{{ t('propertyPayments.newCash') }}</option></select></label>
          <template v-if="mode === 'existing'"><div class="space-y-1"><span class="text-sm">{{ t('propertyPayments.chooseSource') }}</span><PaymentSourcePicker v-if="supplier" v-model="selected" :supplier="supplier" :candidates="candidates" :can-bank="context.capabilities.bank" :can-cash="context.capabilities.cash" :disabled="anyBusy" @found="foundSources" /></div><div v-if="moreKinds.length" class="space-y-2" data-section="source-more"><p class="text-sm">{{ t('propertyPayments.truncated') }}</p><div class="flex flex-wrap gap-2"><PropertyButton v-for="kind in moreKinds" :key="kind" icon="chevron" :data-action="`source-more-${kind}`" :disabled="anyBusy" @click="loadMore(kind)">{{ t(`propertyPayments.loadMoreSources.${kind}`) }}</PropertyButton></div></div></template>
          <div v-else class="grid gap-3 sm:grid-cols-2">
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.direction') }}<select v-model="cash.doc_type" :class="inputClass" data-field="cash-direction"><option value="in">{{ t('propertyPayments.directionIn') }}</option><option value="out">{{ t('propertyPayments.directionOut') }}</option></select></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.register') }}<select v-model="cash.register_id" required :class="inputClass" data-field="cash-register"><option :value="0">{{ t('propertyPayments.choose') }}</option><option v-for="r in context.registers" :key="r.id" :value="r.id" :disabled="r.currency_code !== 'CZK'">{{ r.name }} ({{ r.currency_code }})</option></select></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.sourceDate') }}<DateInput v-model="cash.issue_date" required :class="dateInput" data-field="cash-date" /></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.payer') }}<input v-model="cash.partner_name" required :class="inputClass" data-field="cash-payer" /></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.amount') }}<input v-model="cash.total_amount" required inputmode="decimal" :class="inputClass" data-field="cash-amount" /></label>
            <label class="sm:col-span-2 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.description') }}<input v-model="cash.description" required :class="inputClass" data-field="cash-description" /></label>
          </div>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.accountingDate') }}<DateInput v-model="date" required :class="dateInput" data-field="accounting-date" /></label>
        </section>
        <section class="rounded-lg border border-neutral-200 p-4 space-y-3">
          <h2 class="font-semibold">{{ t('propertyPayments.cells') }}</h2><p class="text-sm text-neutral-500">{{ t('propertyPayments.cellHelp') }}</p>
          <div v-for="(cell, index) in cells" :key="cell.key" class="flex flex-wrap items-end gap-3 rounded border border-neutral-200 p-3">
            <label class="w-full sm:w-40 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.cellAmount', { number: index + 1 }) }}<input v-model="cell.amount" required inputmode="decimal" :readonly="!!sourceDetails" data-field="cell-amount" :class="inputClass" /></label>
            <label v-for="axis in dimensionTypes" :key="axis.id" class="min-w-40 flex-1 flex min-w-0 flex-col gap-1 text-sm">{{ axis.name }}<select v-model="cell.dimensions[axis.id]" :disabled="!!sourceDetails" :class="inputClass"><option :value="0">{{ t('propertyPayments.unassigned') }}</option><option v-for="v in context.dimensions.filter(v => v.type_id === axis.id)" :key="v.id" :value="v.id">{{ v.name }}</option></select></label>
            <PropertyButton v-if="!sourceDetails" icon="trash" variant="danger" @click="cells.splice(index, 1)">{{ t('propertyPayments.remove') }}</PropertyButton>
          </div><PropertyButton v-if="!sourceDetails" icon="plus" @click="addCell">{{ t('propertyPayments.addCell') }}</PropertyButton>
        </section>
        <section class="rounded-lg border border-neutral-200 p-4 space-y-3">
          <h2 class="font-semibold">{{ t('propertyPayments.credits') }}</h2><p class="text-sm text-neutral-500">{{ t('propertyPayments.entitlementHelp') }}</p>
          <div v-for="(credit, index) in credits" :key="credit.key" class="grid gap-3 rounded border border-neutral-200 p-3 sm:grid-cols-2">
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.amount') }}<input v-model="credit.amount" required inputmode="decimal" :class="inputClass" data-field="credit-amount" /></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.unit') }}<select v-model="credit.unit_id" required :class="inputClass" data-field="unit"><option :value="0">{{ t('propertyPayments.choose') }}</option><option v-for="u in context.units" :key="u.id" :value="u.id">{{ unitLabel(u) }}</option></select></label>
            <div class="sm:col-span-2 space-y-2"><label>{{ t('propertyPayments.entitledPersons') }}</label><div v-for="(_, personIndex) in credit.parties" :key="personIndex" class="flex flex-wrap items-center gap-2"><div class="min-w-60 flex-1"><PropertyPartyPicker v-if="supplier" :supplier-id="supplier" :model-value="credit.parties[personIndex] ?? null" @update:model-value="credit.parties[personIndex] = $event" /></div><PropertyButton icon="trash" variant="danger" :disabled="credit.parties.length === 1" @click="credit.parties.splice(personIndex, 1)">{{ t('propertyPayments.remove') }}</PropertyButton></div><PropertyButton icon="plus" @click="credit.parties.push(null)">{{ t('propertyPayments.addPerson') }}</PropertyButton></div>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.evidence') }}<input v-model="credit.evidence" required :class="inputClass" data-field="entitlement-evidence" /></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.dueDate') }}<DateInput v-model="credit.due_date" required :class="dateInput" data-field="due-date" /></label>
            <label class="sm:col-span-2 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.reason') }}<input v-model="credit.reason" required :class="inputClass" data-field="reason" /></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.creditThirdParty.basis') }}<select v-model="credit.third_basis" :class="inputClass" data-field="credit-third-basis"><option value="">{{ t('propertyPayments.creditThirdParty.owners') }}</option><option v-for="basis in THIRD_BASES" :key="basis" :value="basis">{{ t(`propertyPayments.creditThirdParty.bases.${basis}`) }}</option></select></label>
            <label v-if="credit.third_basis" class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.creditThirdParty.evidence') }}<input v-model="credit.third_evidence" required :class="inputClass" data-field="credit-third-evidence" /></label>
            <p class="sm:col-span-2 text-xs text-neutral-500">{{ t('propertyPayments.creditThirdParty.help') }}</p>
            <div v-if="cells.length > 1" class="sm:col-span-2 flex flex-wrap gap-3"><label v-for="(cell, cellIndex) in cells" :key="cell.key" class="w-full sm:w-40 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.consumeCell', { number: cellIndex + 1 }) }}<input v-model="credit.selection[cell.key]" inputmode="decimal" :class="inputClass" /></label></div>
            <PropertyButton icon="trash" variant="danger" :disabled="partCount === 1" @click="credits.splice(index, 1)">{{ t('propertyPayments.removePart') }}</PropertyButton>
          </div><PropertyButton icon="plus" @click="addCredit">{{ t('propertyPayments.addCredit') }}</PropertyButton>
        </section>
        <section class="rounded-lg border border-neutral-200 p-4 space-y-3" data-section="allocations">
          <h2 class="font-semibold">{{ t('propertyPayments.alloc.title') }}</h2><p class="text-sm text-neutral-500">{{ t('propertyPayments.alloc.help') }}</p>
          <div v-for="(part, index) in allocations" :key="part.key"><AllocationPartForm v-if="supplier" :supplier="supplier" :part="part" :units="context.units" :cells="cells" :source-date="sourceDate" @remove="allocations.splice(index, 1)" /></div>
          <PropertyButton icon="plus" variant="primary" data-action="add-allocation" @click="addAllocation">{{ t('propertyPayments.alloc.add') }}</PropertyButton>
        </section>
        <section class="rounded-lg border border-neutral-200 p-4 space-y-3" data-section="refunds">
          <h2 class="font-semibold">{{ t('propertyPayments.refund.title') }}</h2><p class="text-sm text-neutral-500">{{ t('propertyPayments.refund.sectionHelp') }}</p>
          <div v-for="(part, index) in refunds" :key="part.key"><RefundPartForm v-if="supplier" :supplier="supplier" :part="part" :cells="cells" @remove="refunds.splice(index, 1)" /></div>
          <PropertyButton icon="plus" variant="warning" data-action="add-refund" @click="addRefund">{{ t('propertyPayments.refund.add') }}</PropertyButton>
        </section>
      </fieldset>
      <section v-if="preview" class="rounded-lg border border-success-300 p-4 space-y-2" aria-live="polite"><h2 class="font-semibold">{{ t('propertyPayments.previewTitle') }}</h2><p>{{ t('propertyPayments.settled') }}: {{ paymentDecimal(preview.snapshot.settled_cents) }} CZK</p><p>{{ t('propertyPayments.remaining') }}: {{ paymentDecimal(preview.snapshot.available_cents) }} CZK</p><p class="text-sm">{{ t('propertyPayments.remainingHelp') }}</p><SnapshotView :snapshot="preview.snapshot as unknown as Record<string, unknown>" /></section>
      <ActionBar :actions="actions" />
    </form>
    <PaymentSourceRevisionEditor v-if="tab === 'receive' && sourceDetails?.source_id && !sourceDetails.native && supplier && context && !busy && context.capabilities.manage" :key="`${supplier}:${sourceDetails.source_id}`" :supplier="supplier" :source="sourceDetails" :dimensions="context.dimensions" :can-manage="context.capabilities.manage" :can-post="context.capabilities.post" @busy="revisionBusy = $event" @changed="loadSource" />
    <DebtsCreditsTab v-if="context && supplier && tab === 'debts'" :supplier="supplier" :units="context.units" :can-manage="context.capabilities.manage" :can-post="context.capabilities.post" />
    <CorrectionsHistoryTab v-else-if="context && supplier && tab === 'corrections'" :supplier="supplier" :units="context.units" :can-manage="context.capabilities.manage" :can-post="context.capabilities.post" :can-reverse="context.capabilities.reverse" />
    <SaldoPanel v-else-if="context && supplier && tab === 'saldo'" :supplier="supplier" :as-of="asOf" :units="context.units" />
    <PartySaldoPanel v-else-if="context && supplier && tab === 'parties'" :supplier="supplier" :units="context.units" />
    <CardPanel v-else-if="context && supplier && tab === 'card'" :supplier="supplier" :as-of="asOf" :units="context.units" />
    <section v-if="tab === 'receive'" class="rounded-lg border border-neutral-200 p-4 space-y-2"><h2 class="font-semibold">{{ t('propertyPayments.history') }}</h2><article v-for="row in history" :key="row.id" class="flex flex-wrap gap-4 border-t border-neutral-200 py-2"><span>{{ row.date }}</span><span>{{ paymentDecimal(row.amount_cents) }} CZK</span><span>{{ t('propertyPayments.settled') }}: {{ paymentDecimal(row.settled_cents) }} CZK</span><span>{{ t('propertyPayments.remainingAtConfirmation') }}: {{ paymentDecimal(row.available_cents) }} CZK</span></article><div v-if="historyNextCursor !== null" class="flex flex-wrap"><PropertyButton icon="chevron" data-action="load-more-history" :disabled="historyLoadingMore" @click="loadMoreHistory">{{ t('propertyPayments.loadMore') }}</PropertyButton></div></section>
  </div>
</template>
