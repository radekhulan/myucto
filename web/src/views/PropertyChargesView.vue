<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSupplierStore } from '@/stores/supplier'
import { propertyRegistryApi, type PropertyObject, type HouseState } from '@/api/propertyRegistry'
import { propertyChargesApi, chargeRounding, type ChargeComponent, type ChargeRate, type ChargeRequest, type ChargeRegistry, type ChargePreviewResponse, type ChargeVersion } from '@/api/propertyCharges'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import PropertySetupGuide, { type SetupStep } from '@/components/property/PropertySetupGuide.vue'
import ChargeRateEditor from '@/components/property/charges/ChargeRateEditor.vue'
import ChargePreviewPanel from '@/components/property/charges/ChargePreviewPanel.vue'
import { propertyError } from '@/components/property/propertyError'
import { propertyInput as input, propertyTextarea as textarea } from '@/components/property/propertyFormStyles'
import { formatPropertyInstant, fromLocalInstant, propertyNow, toLocalInstant } from '@/components/property/propertyInstant'
import { laterInstant, propertyCode } from '@/components/property/propertyCode'
const { t } = useI18n()
const store = useSupplierStore()
const supplier = computed(() => store.currentSupplierId)
const asOf = ref(propertyNow())
const houseId = ref(0)
const houses = ref<PropertyObject[]>([])
const components = ref<ChargeComponent[]>([])
const versions = ref<ChargeVersion[]>([])
const registry = ref<ChargeRegistry | null>(null)
const capabilities = reactive({ manage: false, confirm: false })
const draft = ref<ChargeRequest | null>(null)
const preview = ref<ChargePreviewResponse | null>(null)
const historical = ref<ChargeVersion | null>(null)
const error = ref('')
const notice = ref('')
const busy = ref(false)
const loading = ref(false)
const componentForm = ref(false)
const component = reactive({ code: '', name: '', kind: '', service_id: null as number | null, account_code: '', effective_at: '', idempotency_key: '' })
const kinds = ['long_term_advance', 'admin_advance', 'service_advance', 'contribution', 'other'] as const
const presets = ['long_term_advance', 'admin_advance', 'service_advance'] as const
let epoch = 0
let detailEpoch = 0
let token = ''
const house = computed(() => houses.value.find(h => h.id === houseId.value) ?? null)
const registryIncomplete = computed(() => !!registry.value && registry.value.house.registry_complete === false)
const steps = computed<SetupStep[]>(() => [
  { key: 'registry', title: t('propertySetup.registry'), help: registryIncomplete.value ? t('propertySetup.registryIncomplete') : houses.value.length ? t('propertySetup.registryDone', { count: houses.value.length }) : t('propertySetup.registryHelp'), done: houses.value.length > 0 && !registryIncomplete.value, action: t('propertySetup.registryAction'), icon: 'link', to: '/property/registry' },
  { key: 'components', title: t('propertySetup.components'), help: components.value.length ? components.value.map(c => c.name).join(', ') : t('propertySetup.componentsHelp'), done: components.value.length > 0, ...(capabilities.manage && registry.value ? { action: t('propertyCharges.newComponent'), icon: 'plus' as const } : {}) },
  { key: 'version', title: t('propertySetup.version'), help: versions.value.length ? t('propertySetup.versionDone', { count: versions.value.length }) : t('propertySetup.versionHelp'), done: versions.value.length > 0, ...(capabilities.manage && registry.value && components.value.length ? { action: t('propertyCharges.newVersion'), icon: 'plus' as const } : {}) },
  { key: 'issue', title: t('propertySetup.issue'), help: t('propertySetup.issueHelp'), done: false, ...(versions.value.length ? { action: t('propertySetup.issueAction'), icon: 'send' as const, to: '/property/charges/issues' } : {}) },
])
const setupDone = computed(() => houses.value.length > 0 && components.value.length > 0 && versions.value.length > 0)
function invalidate() { preview.value = null; token = '' }
function reset() { draft.value = null; preview.value = null; historical.value = null; registry.value = null; versions.value = []; componentForm.value = false; busy.value = false; detailEpoch++ }
async function load() {
  const id = supplier.value; const ticket = ++epoch; reset(); houses.value = []; components.value = []; capabilities.manage = capabilities.confirm = false; error.value = ''; houseId.value = 0
  if (!id) return
  loading.value = true
  try {
    const [h, c] = await Promise.all([propertyRegistryApi.list(id, 'house', asOf.value), propertyChargesApi.components(id)])
    if (ticket !== epoch || supplier.value !== id) return
    houses.value = h; components.value = c.items; Object.assign(capabilities, c.capabilities)
    if (h.length === 1) houseId.value = h[0]!.id
  } catch (e) { if (ticket === epoch) error.value = propertyError(e, t) } finally { if (ticket === epoch) loading.value = false }
}
async function loadHouse() {
  const id = supplier.value; const house = houseId.value; const ticket = ++detailEpoch; draft.value = null; preview.value = null; historical.value = null; registry.value = null; versions.value = []; error.value = ''
  if (!id || !house) return
  try {
    const history = await propertyChargesApi.versions(id, house, asOf.value)
    if (ticket !== detailEpoch || supplier.value !== id) return
    versions.value = history.items; historical.value = history.selected
    try { const context = await propertyChargesApi.context(id, house, asOf.value); if (ticket === detailEpoch && supplier.value === id) registry.value = context } catch (e) { if (ticket === detailEpoch) error.value = propertyError(e, t) }
  } catch (e) { if (ticket === detailEpoch) error.value = propertyError(e, t) }
}
watch(supplier, () => { busy.value = false; notice.value = ''; void load() }, { immediate: true })
watch(asOf, () => { epoch++; reset(); houses.value = []; houseId.value = 0; loading.value = false })
watch(houseId, () => void loadHouse())
watch(draft, invalidate, { deep: true })
watch(() => draft.value?.effective_from, async at => {
  if (!at || !supplier.value || !draft.value) return
  const id = supplier.value; const house = draft.value.house_id; const ticket = ++detailEpoch
  registry.value = null
  try { const value = await propertyChargesApi.context(id, house, at); if (ticket === detailEpoch && supplier.value === id) registry.value = value }
  catch (e) { if (ticket === detailEpoch) error.value = propertyError(e, t) }
})
function monthStart(at: string) { const local = toLocalInstant(at); return local.date ? fromLocalInstant(`${local.date.slice(0, 8)}01`, '00:00') : at }
function rateFor(c: ChargeComponent): ChargeRate {
  const service = c.kind === 'service_advance'
  return { component_id: c.id, unit_ids: (registry.value?.units ?? []).filter(u => !service || !c.service_id || u.services.includes(c.service_id)).map(u => u.id), method: service ? 'unit' : 'share', mode: service ? 'rate_per_basis' : 'total_budget', amount: '', rounding: chargeRounding, legal_basis: service ? 'service_decision' : 'management_share', scope_ref: t('propertyCharges.defaults.scopeRef'), correction: false, correction_reason: '' }
}
function beginVersion() {
  if (!registry.value) return
  historical.value = null; componentForm.value = false; notice.value = ''
  const from = laterInstant(monthStart(asOf.value), house.value?.revision?.effective_from)
  const previous = versions.value[0]?.request
  draft.value = { house_id: houseId.value, expected_revision: versions.value[0]?.revision_no ?? 0, effective_from: from, effective_to: null, basis_at: from, basis_policy: 'fixed_registry_snapshot', period_policy: 'no_automatic_proration', frequency: previous?.frequency || 'monthly', decision_ref: previous?.decision_ref ?? '', decided_at: previous?.decided_at || laterInstant(propertyNow(), from), reason: t(previous ? 'propertyCharges.defaults.reasonChange' : 'propertyCharges.defaults.reason'), rates: previous ? JSON.parse(JSON.stringify(previous.rates)) : [] }
}
function addRate() { draft.value?.rates.push({ component_id: 0, unit_ids: [], method: 'unit', mode: 'rate_per_basis', amount: '', rounding: chargeRounding, legal_basis: '', scope_ref: t('propertyCharges.defaults.scopeRef'), correction: false, correction_reason: '' }) }
function addAllRates() { if (!draft.value) return; for (const c of components.value) if (!draft.value.rates.some(r => r.component_id === c.id)) draft.value.rates.push(rateFor(c)) }
const unusedComponents = computed(() => components.value.filter(c => !draft.value?.rates.some(r => r.component_id === c.id)))
const draftMissing = computed(() => {
  const d = draft.value; if (!d) return []
  const out: string[] = []
  if (!d.effective_from) out.push(t('propertyCharges.effectiveFrom'))
  if (!d.frequency) out.push(t('propertyCharges.frequency'))
  if (!d.decision_ref.trim()) out.push(t('propertyCharges.decision'))
  if (!d.decided_at) out.push(t('propertyCharges.decidedAt'))
  if (!d.reason.trim()) out.push(t('propertyCharges.reason'))
  if (!d.rates.length) out.push(t('propertyCharges.rates'))
  if (d.rates.some(r => !r.component_id)) out.push(t('propertyCharges.component'))
  if (d.rates.some(r => !r.amount.trim())) out.push(t('propertyCharges.amount'))
  if (d.rates.some(r => !r.legal_basis)) out.push(t('propertyCharges.legalBasis'))
  if (d.rates.some(r => !r.scope_ref.trim())) out.push(t('propertyCharges.scopeRef'))
  if (d.rates.some(r => !r.unit_ids.length)) out.push(t('propertyCharges.unitsChoice'))
  return out
})
async function makePreview() {
  const id = supplier.value; if (!id || !draft.value || busy.value) return
  const request = JSON.parse(JSON.stringify(draft.value)) as ChargeRequest; const ticket = epoch; const detailTicket = detailEpoch; const signature = JSON.stringify(draft.value)
  busy.value = true; error.value = ''; preview.value = null
  try {
    const result = await propertyChargesApi.preview(id, request)
    if (ticket === epoch && detailTicket === detailEpoch && supplier.value === id && JSON.stringify(draft.value) === signature) { preview.value = result; token = crypto.randomUUID() }
  } catch (e) { if (ticket === epoch && detailTicket === detailEpoch) error.value = propertyError(e, t) } finally { if (ticket === epoch) busy.value = false }
}
async function saveVersion() {
  const id = supplier.value; const p = preview.value; if (!id || !p || !token || busy.value || !capabilities.confirm) return
  const ticket = epoch; const detailTicket = detailEpoch; busy.value = true; error.value = ''
  try { await propertyChargesApi.save(id, p.preview.request, p.preview_hash, token); if (ticket === epoch && detailTicket === detailEpoch && supplier.value === id) { await loadHouse(); notice.value = t('propertyCharges.versionSaved') } }
  catch (e) { if (ticket === epoch && detailTicket === detailEpoch) error.value = propertyError(e, t) } finally { if (ticket === epoch) busy.value = false }
}
function beginComponent(kind = '') {
  draft.value = null; invalidate(); notice.value = ''
  const name = kind ? t(`propertyCharges.presets.${kind}`) : ''
  Object.assign(component, { code: '', name, kind, service_id: kind === 'service_advance' && registry.value?.services.length === 1 ? registry.value.services[0]!.id : null, account_code: '', effective_at: laterInstant(house.value?.revision?.effective_from) || asOf.value, idempotency_key: crypto.randomUUID() })
  componentForm.value = true
}
const componentCode = computed(() => propertyCode(component.name, components.value.map(c => c.code), 'slozka'))
const componentMissing = computed(() => [
  ...(!component.name.trim() ? [t('propertyCharges.name')] : []),
  ...(!component.kind ? [t('propertyCharges.kind')] : []),
  ...(component.kind === 'service_advance' && !component.service_id ? [t('propertyCharges.service')] : []),
  ...(component.code.trim() && !/^[A-Za-z0-9_.-]{1,80}$/.test(component.code.trim()) ? [t('propertyCharges.code')] : []),
])
async function saveComponent() {
  const id = supplier.value; if (!id || busy.value || componentMissing.value.length) return
  const ticket = epoch; busy.value = true; error.value = ''
  try {
    await propertyChargesApi.createComponent(id, { ...component, code: component.code.trim() || componentCode.value, account_code: component.account_code.trim(), service_id: component.kind === 'service_advance' ? component.service_id : null })
    if (ticket === epoch && supplier.value === id) { const c = await propertyChargesApi.components(id); if (ticket === epoch) { components.value = c.items; componentForm.value = false; notice.value = t('propertyCharges.componentSaved', { name: component.name }) } }
  } catch (e) { if (ticket === epoch) error.value = propertyError(e, t) } finally { if (ticket === epoch) busy.value = false }
}
function onStep(key: string) { if (key === 'components') beginComponent(); else if (key === 'version') beginVersion() }
function houseName(h: PropertyObject) { return (h.revision?.state as HouseState | undefined)?.name ?? h.code }
onBeforeUnmount(() => { epoch++; detailEpoch++ })
</script>
<template>
  <div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3"><div><h1 class="text-2xl font-semibold">{{ t('propertyCharges.title') }}</h1><p class="text-sm text-neutral-500 mt-0.5">{{ t('propertyCharges.introduction') }}</p></div>
      <details class="text-sm"><summary class="cursor-pointer text-neutral-600">{{ t('propertyCharges.otherMoment') }}</summary><div class="flex flex-wrap items-end gap-3 pt-2"><div><PropertyInstantInput :label="t('propertyCharges.asOf')" v-model="asOf" /></div><PropertyButton icon="cycle" :disabled="loading" @click="load">{{ t('propertyCharges.load') }}</PropertyButton></div></details></div>
    <p v-if="error" role="alert" class="rounded bg-danger-50 text-danger-600 p-3">{{ error }}</p>
    <p v-if="notice && !error" role="status" class="rounded border border-success-300 p-3 text-success-700">{{ notice }}</p>
    <PropertySetupGuide v-if="!loading && !setupDone" :title="t('propertySetup.chargesTitle')" :steps="steps" @action="onStep" />
    <div v-if="houses.length" class="flex flex-wrap items-end gap-3">
      <label class="min-w-48 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.house') }}<select v-model.number="houseId" data-house :class="input"><option :value="0">{{ t('propertyCharges.choose') }}</option><option v-for="h in houses" :key="h.id" :value="h.id">{{ houseName(h) }}</option></select></label>
      <div class="flex flex-wrap gap-2"><PropertyButton v-if="capabilities.manage && registry" icon="plus" :disabled="busy" @click="beginComponent()">{{ t('propertyCharges.newComponent') }}</PropertyButton><PropertyButton v-if="capabilities.manage && registry" icon="plus" variant="primary" filled :disabled="busy || !components.length" @click="beginVersion">{{ t('propertyCharges.newVersion') }}</PropertyButton></div>
    </div>
    <p v-if="houseId && registry && registry.units.length && registry.house.registry_complete === false" class="rounded border border-warning-300 p-3 text-sm text-warning-700" data-incomplete-house>{{ t('propertyCharges.houseIncomplete') }} <RouterLink to="/property/registry" class="underline">{{ t('nav.property_registry') }}</RouterLink></p>
    <p v-if="houseId && registry && !registry.units.length" class="rounded border border-warning-300 p-3 text-sm text-warning-700">{{ t('propertyCharges.noUnits') }} <RouterLink to="/property/registry" class="underline">{{ t('nav.property_registry') }}</RouterLink></p>
    <form v-if="componentForm" class="space-y-3 rounded-lg border border-primary-300 bg-surface p-4" novalidate @submit.prevent="saveComponent">
      <h2 class="text-lg font-semibold">{{ t('propertyCharges.newComponent') }}</h2>
      <div class="flex flex-wrap items-center gap-2 text-sm"><span class="text-neutral-500">{{ t('propertyCharges.presetsTitle') }}</span><PropertyButton v-for="p in presets" :key="p" icon="tag" @click="beginComponent(p)">{{ t(`propertyCharges.presets.${p}`) }}</PropertyButton></div>
      <div class="grid gap-3 sm:grid-cols-2"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.name') }} *<input v-model="component.name" data-component-name :class="input" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.kind') }} *<select v-model="component.kind" :class="input"><option value="">{{ t('propertyCharges.choose') }}</option><option v-for="kind in kinds" :key="kind" :value="kind">{{ t(`propertyCharges.kinds.${kind}`) }}</option></select></label></div>
      <label v-if="component.kind === 'service_advance'" class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.service') }} *<select v-model.number="component.service_id" :class="input"><option :value="null">{{ t('propertyCharges.choose') }}</option><option v-for="s in registry?.services ?? []" :key="s.id" :value="s.id">{{ s.name }}</option></select><span v-if="!registry?.services.length" class="text-xs text-warning-700">{{ t('propertyCharges.noServices') }} <RouterLink to="/property/registry" class="underline">{{ t('nav.property_registry') }}</RouterLink></span></label>
      <details class="text-sm"><summary class="cursor-pointer text-neutral-600">{{ t('propertyCharges.componentAdvanced') }}</summary><div class="space-y-3 pt-2">
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.account') }}<input v-model="component.account_code" :class="input" /></label>
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.code') }}<input v-model="component.code" :class="input" :placeholder="componentCode" /></label>
        <div><PropertyInstantInput :label="t('propertyCharges.componentFrom')" v-model="component.effective_at" /></div>
      </div></details>
      <p v-if="componentMissing.length" class="text-sm text-warning-700">{{ t('propertyRegistry.missing') }} {{ componentMissing.join(', ') }}</p>
      <div class="flex flex-wrap gap-2"><PropertyButton icon="check" variant="success" filled submit :disabled="busy || componentMissing.length > 0">{{ t('propertyCharges.saveComponent') }}</PropertyButton><PropertyButton icon="x" @click="componentForm = false">{{ t('propertyCharges.cancel') }}</PropertyButton></div>
    </form>
    <section v-if="components.length" class="rounded-lg border border-neutral-200 p-4"><h2 class="font-semibold">{{ t('propertyCharges.components') }}</h2><div v-for="c in components" :key="c.id" class="flex flex-wrap justify-between gap-2 py-1 text-sm"><span>{{ c.name }}</span><span class="text-neutral-500">{{ t(`propertyCharges.kinds.${c.kind}`) }}</span></div></section>
    <section v-if="draft" class="space-y-3 rounded-lg border border-primary-300 bg-surface p-4" data-version-draft>
      <h2 class="text-lg font-semibold">{{ t('propertyCharges.newVersion') }}<template v-if="house"> · {{ houseName(house) }}</template></h2>
      <div class="grid gap-3 md:grid-cols-2"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.decision') }} *<input v-model="draft.decision_ref" data-decision :class="input" :placeholder="t('propertyCharges.decisionPlaceholder')" /></label><div><PropertyInstantInput :label="`${t('propertyCharges.decidedAt')} *`" v-model="draft.decided_at" /></div>
        <div><PropertyInstantInput :label="`${t('propertyCharges.effectiveFrom')} *`" :model-value="draft.effective_from" @update:model-value="draft.effective_from = $event; draft.basis_at = $event" /></div><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.frequency') }} *<select v-model="draft.frequency" :class="input"><option value="">{{ t('propertyCharges.choose') }}</option><option v-for="f in ['monthly', 'quarterly', 'annual', 'one_off']" :key="f" :value="f">{{ t(`propertyCharges.frequencies.${f}`) }}</option></select></label></div>
      <p class="text-xs text-neutral-500">{{ t('propertyCharges.snapshotPolicy') }}</p>
      <h3 class="font-medium pt-2">{{ t('propertyCharges.rates') }}</h3>
      <p v-if="!draft.rates.length" class="text-sm text-neutral-500">{{ t('propertyCharges.ratesEmpty') }}</p>
      <ChargeRateEditor v-for="(_, index) in draft.rates" :key="index" v-model="draft.rates[index]!" :components="components" :units="registry?.units ?? []" @remove="draft.rates.splice(index, 1)" />
      <div class="flex flex-wrap gap-2"><PropertyButton v-if="unusedComponents.length" icon="plus" variant="primary" @click="addAllRates">{{ t('propertyCharges.addAllRates') }}</PropertyButton><PropertyButton icon="plus" @click="addRate">{{ t('propertyCharges.addRate') }}</PropertyButton></div>
      <details class="text-sm"><summary class="cursor-pointer text-neutral-600">{{ t('propertyRegistry.advanced') }}</summary><div class="space-y-3 pt-2"><div><PropertyInstantInput :label="t('propertyCharges.effectiveTo')" :model-value="draft.effective_to ?? ''" @update:model-value="draft.effective_to = $event || null" /></div><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyCharges.reason') }} *<textarea v-model="draft.reason" :class="textarea" rows="2" /></label></div></details>
      <p v-if="draftMissing.length" class="text-sm text-warning-700" data-draft-missing>{{ t('propertyRegistry.missing') }} {{ draftMissing.join(', ') }}</p>
      <div class="flex flex-wrap gap-2"><PropertyButton icon="eye" variant="primary" filled :disabled="busy || !registry || !draft.rates.length" @click="makePreview">{{ t('propertyCharges.preview') }}</PropertyButton><PropertyButton icon="x" @click="draft = null; invalidate()">{{ t('propertyCharges.cancel') }}</PropertyButton></div>
    </section>
    <ChargePreviewPanel v-if="preview" :preview="preview.preview" />
    <div v-if="preview && capabilities.confirm" class="flex flex-wrap gap-2"><PropertyButton icon="check" variant="success" filled :disabled="busy" @click="saveVersion">{{ t('propertyCharges.confirmVersion') }}</PropertyButton></div>
    <section v-if="versions.length" class="space-y-2 rounded-lg border border-neutral-200 p-4"><h2 class="font-semibold">{{ t('propertyCharges.history') }}</h2><div class="flex flex-wrap gap-2"><PropertyButton v-for="v in versions" :key="v.id" icon="eye" @click="historical = v; draft = null; invalidate()">{{ t('propertyCharges.version', { number: v.revision_no }) }}: {{ v.request.decision_ref }} · {{ formatPropertyInstant(v.effective_from) }}</PropertyButton></div></section>
    <ChargePreviewPanel v-if="historical && !draft" :preview="historical.preview" />
  </div>
</template>
