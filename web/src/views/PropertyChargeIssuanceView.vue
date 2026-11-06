<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useSupplierStore } from '@/stores/supplier'
import { propertyRegistryApi, type PropertyObject, type HouseState, type UnitState } from '@/api/propertyRegistry'
import { propertyChargesApi, type ChargeVersion } from '@/api/propertyCharges'
import { propertyChargeIssuanceApi as api, type CaseCandidate, type IssueContext, type IssueRequest, type IssuePreview, type IssuedCharge, type ReversalRequest, type IssueEvidence } from '@/api/propertyChargeIssuance'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import DateInput from '@/components/ui/DateInput.vue'
import IssueLegalEditor from '@/components/property/issuance/IssueLegalEditor.vue'
import IssueEvidencePanel from '@/components/property/issuance/IssueEvidencePanel.vue'
import CaseFamilyPicker from '@/components/property/issuance/CaseFamilyPicker.vue'
import CaseIdentityEditor from '@/components/property/issuance/CaseIdentityEditor.vue'
import CaseDistinctionEditor from '@/components/property/issuance/CaseDistinctionEditor.vue'
import CaseIdentitySummary from '@/components/property/issuance/CaseIdentitySummary.vue'
import CaseCandidateList from '@/components/property/issuance/CaseCandidateList.vue'
import { candidatesOf, caseErrorOf, caseRequestReady, continuationCase, emptyCaseDistinction, emptyCaseIdentity, emptyIssue, emptyIssueUnit, familyKey, issueMoney, normalizeCaseRequest } from '@/components/property/issuance/issuanceModel'
import { propertyError } from '@/components/property/propertyError'
import { propertyDateInput as dateInput, propertyInput as input, propertyTextarea as textarea } from '@/components/property/propertyFormStyles'
import { formatPropertyInstant, propertyNow } from '@/components/property/propertyInstant'
import PropertySetupGuide, { type SetupStep } from '@/components/property/PropertySetupGuide.vue'
const { t, locale } = useI18n()
const route = useRoute()
const supplier = computed(() => useSupplierStore().currentSupplierId)
const asOf = ref(propertyNow())
const houses = ref<PropertyObject[]>([])
const houseId = ref(0)
const versions = ref<ChargeVersion[]>([])
const versionId = ref(0)
const context = ref<IssueContext | null>(null)
const draft = ref<IssueRequest | null>(null)
const preview = ref<IssuePreview | null>(null)
const history = ref<IssuedCharge[]>([])
const detail = ref<{ charge: IssuedCharge; evidence: IssueEvidence[] } | null>(null)
const reverseTarget = ref<IssuedCharge | null>(null)
const reversal = reactive<ReversalRequest>({ row_version: 0, accounting_date: '', effective_at: '', reason: '', evidence_ref: '', idempotency_key: '' })
const capabilities = reactive({ issue: false, post: false, reverse: false })
const busy = ref(false)
const error = ref('')
let generation = 0
let selection = 0
let detailRequest = 0
const caseChoice = ref('')
const caseNotice = ref('')
const candidates = ref<Record<number, CaseCandidate[] | undefined>>({})
const existsOffer = ref<{ unit_id: number; source_key: string } | null>(null)
const historicalContext = ref(false)
const versionsLoaded = ref(false)
const steps = computed<SetupStep[]>(() => [
  { key: 'registry', title: t('propertySetup.registry'), help: houses.value.length ? t('propertySetup.registryDone', { count: houses.value.length }) : t('propertySetup.registryHelp'), done: houses.value.length > 0, action: t('propertySetup.registryAction'), icon: 'link', to: '/property/registry' },
  { key: 'version', title: t('propertySetup.version'), help: versions.value.length ? t('propertySetup.versionDone', { count: versions.value.length }) : t('propertySetup.versionHelp'), done: versions.value.length > 0, action: t('propertySetup.versionAction'), icon: 'coin', to: '/property/charges' },
  { key: 'issue', title: t('propertySetup.issue'), help: t('propertySetup.issueHere'), done: history.value.length > 0 },
])
const showGuide = computed(() => !busy.value && !history.value.length && (!houses.value.length || (versionsLoaded.value && !versions.value.length)))
let token = ''
const money = (value: string) => issueMoney(value, locale.value)
const unitNo = (id: number) => (context.value?.units.find(u => u.id === id)?.revision?.state as UnitState | undefined)?.unit_no || String(id)
const caseReady = computed(() => !!draft.value && caseRequestReady(draft.value, candidates.value))
function clearCase() { caseChoice.value = ''; caseNotice.value = ''; candidates.value = {}; existsOffer.value = null }
const unitName = (charge: IssuedCharge) => (charge.snapshot.registry.revision.state as UnitState).unit_no
const components = computed(() => context.value?.version.preview.calculation.rates.map(r => r.component) ?? [])
function invalidate() { preview.value = null; token = '' }
function clearSelection() { selection++; detailRequest++; context.value = null; draft.value = null; invalidate(); clearCase(); historicalContext.value = false; detail.value = null; reverseTarget.value = null }
function reset() {
  generation++; clearSelection(); busy.value = false; houses.value = []; houseId.value = 0; versionId.value = 0; versions.value = []; error.value = ''
}
async function load() {
  const id = supplier.value; reset(); const ticket = generation; history.value = []; Object.assign(capabilities, { issue: false, post: false, reverse: false })
  if (!id) return
  busy.value = true
  try { const [h, result] = await Promise.all([propertyRegistryApi.list(id, 'house', asOf.value), api.list(id)]); if (ticket === generation && supplier.value === id) { houses.value = h; history.value = result.items; Object.assign(capabilities, result.capabilities); if (h.length === 1 && !houseId.value) houseId.value = h[0]!.id; await showRequested() } }
  catch (e) { if (ticket === generation) error.value = propertyError(e, t) } finally { if (ticket === generation) busy.value = false }
}
async function loadVersions() {
  const id = supplier.value; const house = houseId.value; const ticket = ++selection; context.value = null; draft.value = null; invalidate(); clearCase(); versions.value = []; versionId.value = 0; versionsLoaded.value = false
  if (!id || !house) return
  try { const result = await propertyChargesApi.versions(id, house, asOf.value); if (ticket === selection && supplier.value === id) { versions.value = result.items; versionsLoaded.value = true; if (result.items.length === 1) versionId.value = result.items[0]!.id } }
  catch (e) { if (ticket === selection) error.value = propertyError(e, t) }
}
async function loadContext() {
  const id = supplier.value; const version = versionId.value; const ticket = ++selection; context.value = null; draft.value = null; invalidate(); clearCase(); historicalContext.value = false
  if (!id || !version) return
  try { const result = await api.context(id, version, asOf.value); if (ticket === selection && supplier.value === id) context.value = result }
  catch (e) { if (ticket === selection) error.value = propertyError(e, t) }
}
watch(supplier, () => void load(), { immediate: true })
watch(asOf, reset)
watch(() => route.query.charge_id, () => { detailRequest++; detail.value = null; void showRequested() })
watch(houseId, () => void loadVersions())
watch(versionId, () => void loadContext())
watch(draft, invalidate, { deep: true })
onBeforeUnmount(() => { generation++; selection++ })
function selectUnit(id: number, selected: boolean) {
  if (!draft.value || !context.value) return
  if (selected && !draft.value.units.some(u => u.unit_id === id)) draft.value.units.push(emptyIssueUnit(context.value, id))
  if (!selected) { draft.value.units = draft.value.units.filter(u => u.unit_id !== id); delete candidates.value[id] }
}
async function correct(charge: IssuedCharge, mode: 'difference' | 'replacement') {
  const id = supplier.value; const version = versionId.value
  if (!id || !version || busy.value || !capabilities.issue) return
  const ticket = generation; const pick = ++selection
  busy.value = true; error.value = ''; context.value = null; draft.value = null; invalidate(); clearCase()
  try {
    const result = await api.context(id, version, charge.snapshot.claim_origin_at)
    if (ticket !== generation || pick !== selection || supplier.value !== id) return
    context.value = result; historicalContext.value = true
    const family = result.case_families?.find(f => f.unit_id === charge.unit_id && f.source_key === charge.source_key)
    if (family && (family.latest_charge_id !== charge.id || family.row_version !== charge.row_version || family.latest_revision !== charge.revision_no)) { error.value = t('propertyIssuance.case.staleFamily'); return }
    const carried = continuationCase(family, charge.snapshot)
    if (!carried) { error.value = t('propertyIssuance.case.identityConflict'); return }
    caseChoice.value = familyKey(charge)
    const unit = emptyIssueUnit(result, charge.unit_id)
    unit.expected_revision = charge.revision_no; unit.previous_charge_id = charge.id; unit.liability_groups = JSON.parse(JSON.stringify(charge.snapshot.liability_groups))
    if (carried.distinction) unit.case_distinction = carried.distinction
    draft.value = { ...emptyIssue(result), ...(carried.identity ? { case_identity: carried.identity } : {}), source_key: charge.source_key, title: charge.snapshot.title, issue_mode: mode,
      period_from: charge.period_from, period_to: charge.period_to, claim_origin_at: charge.snapshot.claim_origin_at, issued_at: '', accounting_date: '', due_date: charge.due_date, reason: '', units: [unit],
      accounts: result.version.preview.calculation.rates.map(rate => JSON.parse(JSON.stringify(charge.snapshot.lines.find(l => l.component_id === rate.component.id)?.account ?? { component_id: rate.component.id, receivable_account: '', counter_account: '', basis_ref: '', settlement_due_on: null, dimensions: {} }))) }
  } catch (e) { if (ticket === generation && pick === selection && supplier.value === id) error.value = propertyError(e, t) }
  finally { if (ticket === generation) busy.value = false }
}
async function makePreview() {
  const id = supplier.value; if (!id || !draft.value || busy.value) return
  if (!caseReady.value) return
  const ticket = generation; const pick = selection; const request = normalizeCaseRequest(draft.value); const signature = JSON.stringify(draft.value)
  busy.value = true; error.value = ''; invalidate()
  try { const result = await api.preview(id, request); if (ticket === generation && pick === selection && supplier.value === id && signature === JSON.stringify(draft.value)) { preview.value = result; token = crypto.randomUUID(); existsOffer.value = null
    candidates.value = request.issue_mode !== 'initial' ? {} : Object.fromEntries(result.snapshot.charges.filter(c => c.case_candidates?.length).map(c => [c.unit_id, c.case_candidates])) } }
  catch (e) { if (ticket === generation && pick === selection) { error.value = propertyError(e, t); if (signature === JSON.stringify(draft.value)) handleCaseError(e) } } finally { if (ticket === generation) busy.value = false }
}
function handleCaseError(e: unknown) {
  const info = caseErrorOf(e)
  if (info.code === 'property_charge_case_exists' && Number.isSafeInteger(info.details.unit_id) && typeof info.details.source_key === 'string') existsOffer.value = { unit_id: info.details.unit_id as number, source_key: info.details.source_key }
  if (info.code !== 'property_charge_case_distinction_required' || !draft.value) return
  const found = candidatesOf(info.details); const unit = found && draft.value.units.find(u => u.unit_id === found.unit_id)
  if (!found || !unit) return
  if (!found.candidates.length) { delete candidates.value[found.unit_id]; delete unit.case_distinction; return }
  const allowed = new Set(found.candidates.map(c => c.source_key))
  candidates.value[found.unit_id] = found.candidates
  unit.case_distinction = unit.case_distinction ? { ...unit.case_distinction, against_source_keys: unit.case_distinction.against_source_keys.filter(k => allowed.has(k)) } : emptyCaseDistinction()
}
async function refreshFamilies() {
  const id = supplier.value; const version = versionId.value; const current = context.value; if (!id || !version || !current) return
  const ticket = generation; const pick = selection
  try { const result = await api.context(id, version, current.as_of); if (ticket === generation && pick === selection && supplier.value === id && context.value === current) current.case_families = result.case_families }
  catch { /* the preview stays invalid; the next explicit preview reports the real state */ }
}
function rejectedIssue(e: unknown): boolean {
  const info = caseErrorOf(e)
  if (info.status !== 409 && info.status !== 422) return false
  invalidate(); error.value = propertyError(e, t); handleCaseError(e); void refreshFamilies(); return true
}
async function issue() {
  const id = supplier.value; const ready = preview.value; if (!id || !ready || !token || busy.value || !capabilities.issue) return
  await mutate(async () => { await api.issue(id, ready, token) }, rejectedIssue)
}
async function chooseCase(value: string) {
  const id = supplier.value; const version = versionId.value
  if (!id || !version || busy.value || !capabilities.issue || !context.value) return
  existsOffer.value = null; caseNotice.value = ''; error.value = ''
  if (value === 'new') {
    if (historicalContext.value) { await loadContext(); if (!context.value) return }
    candidates.value = {}; invalidate(); caseChoice.value = 'new'
    draft.value = { ...emptyIssue(context.value!), case_identity: emptyCaseIdentity() }; return
  }
  const ticket = generation; const pick = ++selection
  busy.value = true; draft.value = null; invalidate(); candidates.value = {}
  let found: IssuedCharge | null = null
  try {
    const fresh = await api.context(id, version, asOf.value)
    if (ticket !== generation || pick !== selection || supplier.value !== id) return
    context.value = fresh; historicalContext.value = false; caseChoice.value = ''
    const family = fresh.case_families?.find(f => familyKey(f) === value)
    if (!family) { error.value = t('propertyIssuance.case.staleFamily'); return }
    const latest = await api.detail(id, family.latest_charge_id)
    if (ticket !== generation || pick !== selection || supplier.value !== id) return
    const c = latest.charge
    if (c.id !== family.latest_charge_id || c.unit_id !== family.unit_id || c.source_key !== family.source_key || c.revision_no !== family.latest_revision || c.row_version !== family.row_version || c.status !== family.status) { error.value = t('propertyIssuance.case.staleFamily'); return }
    caseChoice.value = value
    if (c.status === 'draft') { detailRequest++; detail.value = latest; caseNotice.value = 'draftFamily'; return }
    found = c
  } catch (e) { if (ticket === generation && pick === selection && supplier.value === id) error.value = propertyError(e, t); return }
  finally { if (ticket === generation) busy.value = false }
  if (found && ticket === generation && pick === selection) await correct(found, found.status === 'posted' ? 'difference' : 'replacement')
}
async function post(charge: IssuedCharge) {
  const id = supplier.value; if (!id || !capabilities.post) return
  await mutate(async () => { const batch = await api.batch(id, charge.batch_id); await api.post(id, batch) })
}
function beginReverse(charge: IssuedCharge) {
  reverseTarget.value = charge; Object.assign(reversal, { row_version: charge.row_version, accounting_date: '', effective_at: '', reason: '', evidence_ref: '', idempotency_key: crypto.randomUUID() })
}
async function reverse() {
  const id = supplier.value; const target = reverseTarget.value; if (!id || !target || !capabilities.reverse) return
  await mutate(async () => { await api.reverse(id, target.id, { ...reversal }) })
}
async function showRequested() {
  const value = route.query.charge_id
  if (typeof value === 'string' && /^[1-9]\d*$/.test(value) && Number.isSafeInteger(Number(value))) await showDetail(Number(value))
}
async function show(charge: IssuedCharge) { await showDetail(charge.id) }
async function showDetail(chargeId: number) {
  const id = supplier.value; const ticket = generation; const pick = ++detailRequest; if (!id) return
  try { const result = await api.detail(id, chargeId); if (ticket === generation && pick === detailRequest && supplier.value === id) detail.value = result }
  catch (e) { if (ticket === generation && pick === detailRequest && supplier.value === id) error.value = propertyError(e, t) }
}
async function mutate(work: () => Promise<void>, rejected?: (e: unknown) => boolean) {
  if (busy.value) return; const ticket = generation; const id = supplier.value; if (!id) return
  busy.value = true; error.value = ''
  try { await work(); if (ticket !== generation || supplier.value !== id) return; invalidate(); reverseTarget.value = null; detailRequest++; detail.value = null; const result = await api.list(id); if (ticket === generation && supplier.value === id) { history.value = result.items; Object.assign(capabilities, result.capabilities) } }
  catch (e) { if (ticket === generation && !(supplier.value === id && rejected?.(e))) error.value = propertyError(e, t) } finally { if (ticket === generation) busy.value = false }
}
async function useExisting() {
  const offer = existsOffer.value; if (!offer) return
  const id = supplier.value; const version = versionId.value; if (!id || !version) return
  const ticket = generation; const pick = selection
  try {
    const fresh = await api.context(id, version, asOf.value)
    if (ticket !== generation || pick !== selection || supplier.value !== id || !context.value) return
    context.value.case_families = fresh.case_families; historicalContext.value = false
    const family = fresh.case_families?.find(f => f.unit_id === offer.unit_id && f.source_key === offer.source_key)
    if (!family) { error.value = t('propertyIssuance.case.staleFamily'); return }
    await chooseCase(familyKey(family))
  } catch (e) { if (ticket === generation && pick === selection) error.value = propertyError(e, t) }
}
</script>
<template>
  <div class="space-y-5">
    <div><h1 class="text-2xl font-semibold">{{ t('propertyIssuance.title') }}</h1><p class="text-sm text-neutral-500 mt-0.5">{{ t('propertyIssuance.scopeHelp') }}</p></div>
    <p v-if="error" role="alert" class="rounded border border-danger-300 p-3 text-danger-700">{{ error }}</p>
    <details class="text-sm" data-other-moment><summary class="cursor-pointer text-neutral-600">{{ t('propertyCharges.otherMoment') }}</summary><div class="flex flex-wrap items-end gap-3 pt-2"><PropertyInstantInput v-model="asOf" :label="t('propertyIssuance.registryAsOf')" /><PropertyButton icon="uturn" variant="primary" filled :disabled="busy" @click="load">{{ t('propertyIssuance.refresh') }}</PropertyButton></div></details>
    <PropertySetupGuide v-if="showGuide" :title="t('propertySetup.issueTitle')" :steps="steps" />
    <section v-if="capabilities.issue" class="rounded-lg border border-neutral-200 p-4 space-y-4">
      <p v-if="!busy && !houses.length" class="text-sm text-neutral-500" data-testid="houses-empty">{{ t('propertyIssuance.housesEmpty') }}</p>
      <div class="flex flex-wrap gap-3"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.house') }}<select data-house v-model="houseId" :class="input"><option :value="0">{{ t('propertyIssuance.choose') }}</option><option v-for="house in houses" :key="house.id" :value="house.id">{{ (house.revision?.state as HouseState | undefined)?.name || house.code }}</option></select></label>
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.version') }}<select data-version v-model="versionId" :class="input"><option :value="0">{{ t('propertyIssuance.choose') }}</option><option v-for="version in versions" :key="version.id" :value="version.id">{{ version.request.decision_ref }} · {{ formatPropertyInstant(version.effective_from) }} · {{ t(`propertyCharges.frequencies.${version.request.frequency}`) }}</option></select></label></div>
      <CaseFamilyPicker v-if="context" :context="context" :choice="caseChoice" :disabled="busy" @choose="chooseCase" />
      <p v-if="caseNotice" role="status" class="rounded border border-warning-300 p-3 text-warning-700" data-testid="case-notice">{{ t(`propertyIssuance.case.${caseNotice}`) }}</p>
      <div v-if="existsOffer" class="flex flex-wrap items-center gap-3 rounded border border-warning-300 p-3" data-testid="case-exists"><span>{{ t('propertyIssuance.case.existsHelp') }}</span><PropertyButton icon="check" variant="primary" :disabled="busy" @click="useExisting">{{ t('propertyIssuance.case.useExisting') }}</PropertyButton></div>
      <fieldset v-if="draft && context" :disabled="busy" class="space-y-4">
        <p>{{ t(`propertyIssuance.issueModes.${draft.issue_mode}`) }} · {{ t('propertyIssuance.noProration') }}</p>
        <CaseIdentityEditor v-if="draft.case_identity" :identity="draft.case_identity" :readonly="draft.issue_mode !== 'initial'" />
        <p v-else class="text-sm text-neutral-600" data-testid="case-legacy-continuation">{{ t('propertyIssuance.case.legacyContinuation') }}</p>
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.claimTitle') }}<select v-model="draft.title" :class="input"><option value="">{{ t('propertyIssuance.choose') }}</option><option v-for="title in ['prescription', 'extra_contribution']" :key="title" :value="title">{{ t(`propertyIssuance.titles.${title}`) }}</option></select></label>
        <div class="flex flex-wrap gap-3"><label class="flex min-w-0 flex-col gap-1 text-sm" v-for="field in (['period_from', 'period_to', 'accounting_date', 'due_date'] as const)" :key="field">{{ t(`propertyIssuance.dates.${field}`) }}<DateInput :data-date="field" v-model="draft[field]" :class="[dateInput, 'sm:w-40']" /></label></div>
        <div class="grid gap-3 md:grid-cols-2"><PropertyInstantInput v-model="draft.claim_origin_at" :label="t('propertyIssuance.originAt')" /><PropertyInstantInput v-model="draft.issued_at" :label="t('propertyIssuance.issuedAt')" /></div>
        <p v-if="draft.claim_origin_at !== context.as_of" class="text-sm text-warning-700">{{ t('propertyIssuance.snapshotDateHelp') }}</p>
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.reason') }}<textarea data-issue-reason v-model="draft.reason" :class="textarea" /></label>
        <fieldset v-if="draft.issue_mode === 'initial'"><legend>{{ t('propertyIssuance.selectUnits') }}</legend><div class="flex flex-wrap gap-3"><label v-for="unit in context.units" :key="unit.id" class="inline-flex items-center gap-2"><input type="checkbox" :checked="draft.units.some(u => u.unit_id === unit.id)" :disabled="!unit.revision || unit.ownership_review_required" @change="selectUnit(unit.id, ($event.target as HTMLInputElement).checked)">{{ (unit.revision?.state as UnitState | undefined)?.unit_no || unit.code }}</label></div></fieldset>
        <fieldset v-for="account in draft.accounts" :key="account.component_id" class="rounded border border-neutral-200 p-3 space-y-2"><legend>{{ components.find(c => c.id === account.component_id)?.name }}</legend>
          <div class="flex flex-wrap gap-3"><label class="flex min-w-0 flex-col gap-1 text-sm" v-for="field in (['receivable_account', 'counter_account'] as const)" :key="field">{{ t(`propertyIssuance.${field}`) }}<select v-model="account[field]" :class="input"><option value="">{{ t('propertyIssuance.choose') }}</option><option v-for="a in context.accounts" :key="a.account_code" :value="a.account_code">{{ a.account_code }} · {{ a.name }}</option></select></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.settlementDue') }}<DateInput :model-value="account.settlement_due_on ?? ''" :class="[dateInput, 'sm:w-40']" @update:model-value="account.settlement_due_on = $event || null" /></label></div>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.accountEvidence') }}<input v-model="account.basis_ref" :class="input"></label>
          <div class="flex flex-wrap gap-3"><label class="flex min-w-0 flex-col gap-1 text-sm" v-for="dimension in context.dimensions" :key="dimension.id">{{ dimension.name }}<select :value="account.dimensions[dimension.id] ?? ''" :class="input" @change="($event.target as HTMLSelectElement).value ? account.dimensions[dimension.id] = Number(($event.target as HTMLSelectElement).value) : delete account.dimensions[dimension.id]"><option value="">{{ t('propertyIssuance.unassigned') }}</option><option v-for="value in dimension.values" :key="value.id" :value="value.id">{{ value.name }}</option></select></label></div>
        </fieldset>
        <IssueLegalEditor v-for="unit in draft.units" :key="unit.unit_id" :unit="unit" :context="context" :supplier-id="supplier!" :at="draft.claim_origin_at" :readonly="draft.issue_mode === 'difference'" />
        <CaseDistinctionEditor v-for="unit in draft.units" :key="`case-${unit.unit_id}`" :unit="unit" :context="context" :candidates="candidates[unit.unit_id]" :readonly="draft.issue_mode !== 'initial'" />
        <p v-if="!caseReady" class="text-sm text-warning-700" data-testid="case-incomplete">{{ t('propertyIssuance.case.incomplete') }}</p>
        <div class="flex flex-wrap gap-2"><PropertyButton icon="eye" variant="primary" filled :disabled="busy || !draft.units.length || !caseReady || draft.claim_origin_at !== context.as_of" @click="makePreview">{{ t('propertyIssuance.preview') }}</PropertyButton></div>
      </fieldset>
      <section v-if="preview" class="space-y-3" data-testid="issue-preview"><h2 class="font-semibold">{{ t('propertyIssuance.preview') }} · {{ money(preview.snapshot.total_cents) }} {{ t('propertyIssuance.currency') }}</h2><p>{{ t('propertyIssuance.oneClaimHelp') }}</p>
        <article v-for="charge in preview.snapshot.charges" :key="charge.unit_id" class="rounded border p-3"><h3>{{ (charge.registry.revision.state as UnitState).unit_no }} · {{ money(charge.amount_cents) }} {{ t('propertyIssuance.currency') }}</h3><p v-for="line in charge.lines" :key="line.component_id">{{ line.component.name }} · {{ money(line.amount_cents) }} {{ t('propertyIssuance.currency') }} · {{ line.account.receivable_account }} / {{ line.account.counter_account }}</p><CaseCandidateList v-if="charge.case_candidates?.length" :candidates="charge.case_candidates" :unit-name="unitNo" /></article>
        <div class="flex flex-wrap gap-2"><PropertyButton icon="check" variant="success" filled :disabled="busy" @click="issue">{{ t('propertyIssuance.issue') }}</PropertyButton></div>
      </section>
    </section>
    <section class="space-y-3"><h2 class="font-semibold">{{ t('propertyIssuance.history') }}</h2>
      <p v-if="busy && !history.length" role="status" class="text-sm text-neutral-500" data-testid="history-loading">{{ t('propertyIssuance.loading') }}</p>
      <p v-else-if="!history.length" class="rounded-lg border border-dashed border-neutral-200 p-4 text-sm text-neutral-500" data-testid="history-empty">{{ t('propertyIssuance.historyEmpty') }}</p>
      <article v-for="charge in history" :key="charge.id" class="rounded-lg border border-neutral-200 p-3 space-y-2"><div class="flex flex-wrap justify-between gap-2"><strong>{{ unitName(charge) }} · {{ charge.period_from }} - {{ charge.period_to }}</strong><span>{{ money(charge.amount_cents) }} {{ t('propertyIssuance.currency') }} · {{ t(`propertyIssuance.statuses.${charge.status}`) }}</span></div>
        <p v-if="charge.snapshot.case_identity" class="text-sm text-neutral-600" data-testid="history-case">{{ charge.snapshot.case_identity.case_ref }} · {{ charge.snapshot.case_identity.occurrence_ref }} · {{ charge.snapshot.case_identity.title_ref }}</p>
        <div class="flex flex-wrap gap-2"><PropertyButton icon="eye" @click="show(charge)">{{ t('propertyIssuance.detail') }}</PropertyButton><PropertyButton v-if="charge.status === 'draft' && capabilities.post" icon="check" variant="success" :disabled="busy" @click="post(charge)">{{ t('propertyIssuance.postBatch') }}</PropertyButton>
          <PropertyButton v-if="['draft', 'posted'].includes(charge.status) && capabilities.reverse" icon="uturn" variant="danger" :disabled="busy" @click="beginReverse(charge)">{{ t(charge.status === 'draft' ? 'propertyIssuance.cancelDraft' : 'propertyIssuance.reverse') }}</PropertyButton>
          <PropertyButton v-if="context && capabilities.issue && charge.status === 'posted'" icon="edit" variant="warning" @click="correct(charge, 'difference')">{{ t('propertyIssuance.difference') }}</PropertyButton><PropertyButton v-if="context && capabilities.issue && ['reversed', 'cancelled'].includes(charge.status)" icon="plus" variant="primary" @click="correct(charge, 'replacement')">{{ t('propertyIssuance.replacement') }}</PropertyButton></div>
      </article>
    </section>
    <section v-if="reverseTarget" class="rounded border border-danger-300 p-4 space-y-3"><h2>{{ t('propertyIssuance.reverse') }} · {{ unitName(reverseTarget) }}</h2><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.dates.accounting_date') }}<DateInput v-model="reversal.accounting_date" :class="[dateInput, 'sm:w-40']" /></label><PropertyInstantInput v-model="reversal.effective_at" :label="t('propertyIssuance.effectiveAt')" /><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.reason') }}<textarea v-model="reversal.reason" :class="textarea" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyIssuance.evidence') }}<input v-model="reversal.evidence_ref" :class="input"></label><div class="flex flex-wrap gap-2"><PropertyButton icon="uturn" variant="danger" filled :disabled="busy" @click="reverse">{{ t('propertyIssuance.confirmReverse') }}</PropertyButton><PropertyButton icon="x" @click="reverseTarget = null">{{ t('propertyIssuance.close') }}</PropertyButton></div></section>
    <section v-if="detail && supplier" class="rounded border p-4 space-y-3"><h2>{{ t('propertyIssuance.detail') }} · {{ unitName(detail.charge) }}</h2><p v-for="group in detail.charge.snapshot.liability_groups" :key="group.group_key">{{ t(`propertyIssuance.roles.${group.role}`) }} · {{ group.members.map(id => detail!.charge.snapshot.parties.find(p => p.id === id)?.company_name || t('propertyIssuance.unknownPerson')).join(', ') }} · {{ group.extent.num }}/{{ group.extent.den }} · {{ group.evidence_ref }}</p><CaseIdentitySummary :identity="detail.charge.snapshot.case_identity" :distinction="detail.charge.snapshot.case_distinction" :candidates="detail.charge.snapshot.case_candidates" :unit-name="unitNo" /><p v-for="line in detail.charge.snapshot.lines" :key="line.component_id">{{ line.component.name }} · {{ money(line.amount_cents) }} {{ t('propertyIssuance.currency') }}</p><IssueEvidencePanel :supplier-id="supplier" :charge="detail.charge" :events="detail.evidence" :can-edit="capabilities.issue" @saved="show(detail!.charge)" /></section>
  </div>
</template>
