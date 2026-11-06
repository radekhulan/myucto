<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useSupplierStore } from '@/stores/supplier'
import { propertyRegistryApi, type PropertyKind, type PropertyObject, type PropertyState, type PropertyRevision, type ProfileResponse, type RegistrySummary, type PropertyMutation, type HouseState, type UnitState, type ServiceState } from '@/api/propertyRegistry'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import PropertyStateEditor from '@/components/property/PropertyStateEditor.vue'
import PropertyImportPanel from '@/components/property/PropertyImportPanel.vue'
import PropertySetupGuide, { type SetupStep } from '@/components/property/PropertySetupGuide.vue'
import { propertyError } from '@/components/property/propertyError'
import { propertyInput as inputClass } from '@/components/property/propertyFormStyles'
import { formatPropertyInstant, propertyNow } from '@/components/property/propertyInstant'
import { laterInstant, propertyCode, propertyYearStart } from '@/components/property/propertyCode'
const { t } = useI18n()
const supplierStore = useSupplierStore()
const supplier = computed(() => supplierStore.currentSupplierId)
const asOf = ref(propertyNow())
const knownAt = ref('')
const profile = ref<ProfileResponse | null>(null)
const summary = ref<RegistrySummary | null>(null)
const objects = reactive<Record<PropertyKind, PropertyObject[]>>({ house: [], unit: [], service: [] })
const kind = ref<PropertyKind>('house')
const selected = ref<PropertyObject | null>(null)
const revision = ref<PropertyRevision | null>(null)
const editor = ref<{ id: number | null; kind: PropertyKind; input: PropertyMutation; code: string; label: string } | null>(null)
const missing = ref<string[]>([])
const notice = ref('')
const confirmRevision = ref<PropertyRevision | null>(null)
const confirmReason = ref('')
const confirmTitle = ref('')
let confirmToken = ''
const partyLabels = reactive<Record<number, string>>({})
const busy = ref(false)
const loading = ref(false)
const error = ref('')
const showImport = ref(false)
const showProfile = ref(false)
const profileForm = reactive({ effective_from: '', effective_to: '', enabled: true, reason: '', title_ref: '' })
let profileToken = crypto.randomUUID()
let epoch = 0
let detailEpoch = 0
onBeforeUnmount(() => { epoch++; detailEpoch++ })
const enabled = computed(() => !!profile.value?.profile?.enabled)
const canManage = computed(() => !!profile.value?.capabilities.manage)
const canConfirm = computed(() => !!profile.value?.capabilities.confirm)
const setupDone = computed(() => enabled.value && objects.house.length > 0 && objects.unit.length > 0 && objects.service.length > 0)
const steps = computed<SetupStep[]>(() => [
  { key: 'profile', title: t('propertySetup.profile'), help: t(enabled.value ? 'propertySetup.profileDone' : 'propertySetup.profileHelp'), done: enabled.value, ...(!enabled.value && canConfirm.value && !showProfile.value ? { action: t('propertySetup.profileAction'), icon: 'play' as const } : {}) },
  { key: 'house', title: t('propertySetup.house'), help: objects.house.length ? houseNames.value : t('propertySetup.houseHelp'), done: objects.house.length > 0, ...(enabled.value && canManage.value ? { action: t(objects.house.length ? 'propertySetup.houseMore' : 'propertySetup.houseAction'), icon: 'plus' as const } : {}) },
  { key: 'unit', title: t('propertySetup.units'), help: objects.unit.length ? t('propertySetup.unitsDone', { count: objects.unit.length }) : t('propertySetup.unitsHelp'), done: objects.unit.length > 0, ...(enabled.value && canManage.value && objects.house.length ? { action: t('propertySetup.unitsAction'), icon: 'plus' as const } : {}) },
  { key: 'service', title: t('propertySetup.services'), help: objects.service.length ? t('propertySetup.servicesDone', { count: objects.service.length }) : t('propertySetup.servicesHelp'), done: objects.service.length > 0, optional: true, ...(enabled.value && canManage.value ? { action: t('propertySetup.servicesAction'), icon: 'plus' as const } : {}) },
  { key: 'charges', title: t('propertySetup.charges'), help: t('propertySetup.chargesHelp'), done: false, ...(objects.unit.length ? { action: t('propertySetup.chargesAction'), icon: 'coin' as const, to: '/property/charges' } : {}) },
])
const houseNames = computed(() => objects.house.map(objectLabel).join(', '))
const saveLabel = computed(() => !editor.value ? '' : editor.value.id !== null ? t('propertyRegistry.saveChanges') : t(`propertyRegistry.saveNew.${editor.value.kind}`))
function message(e: unknown) { return propertyError(e, t) }
function objectLabel(object: PropertyObject) { const state = object.revision?.state; return state ? ('unit_no' in state ? t('propertyRegistry.unitLabel', { number: state.unit_no }) : state.name) : object.code }
function houseOf(object: PropertyObject) { const house = objects.house.find(h => h.id === object.parent_id); return house ? objectLabel(house) : '' }
function unitShare(object: PropertyObject) { const share = (object.revision?.state as UnitState | undefined)?.attributes?.share; return share ? `${share.num}/${share.den}` : '' }
function ownerNames(object: PropertyObject) { return ((object.revision?.state as UnitState | undefined)?.ownerships ?? []).length }
function address(object: PropertyObject) { const a = (object.revision?.state as HouseState | undefined)?.address; return a ? [a.street, [a.postal_code, a.city].filter(Boolean).join(' ')].filter(Boolean).join(', ') : '' }
function reset() { selected.value = null; revision.value = null; editor.value = null; missing.value = []; confirmRevision.value = null; showImport.value = false; showProfile.value = false; for (const id of Object.keys(partyLabels)) delete partyLabels[Number(id)]; detailEpoch++ }
async function load() {
  const id = supplier.value; const ticket = ++epoch; reset(); profile.value = null; summary.value = null; objects.house = []; objects.unit = []; objects.service = []; error.value = ''
  if (!id) return
  loading.value = true
  try {
    const [p, houses, units, services, totals] = await Promise.all([propertyRegistryApi.profile(id, asOf.value, knownAt.value), propertyRegistryApi.list(id, 'house', asOf.value, knownAt.value), propertyRegistryApi.list(id, 'unit', asOf.value, knownAt.value), propertyRegistryApi.list(id, 'service', asOf.value, knownAt.value), propertyRegistryApi.summary(id, asOf.value, knownAt.value)])
    if (ticket !== epoch || supplier.value !== id) return
    profile.value = p; objects.house = houses; objects.unit = units; objects.service = services; summary.value = totals
    if (!p.history.length && p.capabilities.confirm) beginProfile(true)
  } catch (e) { if (ticket === epoch) error.value = message(e) } finally { if (ticket === epoch) loading.value = false }
}
watch(supplier, () => { busy.value = false; notice.value = ''; void load() }, { immediate: true })
watch(kind, value => { if (editor.value?.kind === value) return; detailEpoch++; selected.value = null; revision.value = null; editor.value = null; confirmRevision.value = null; showImport.value = false })
watch([asOf, knownAt], () => { if (asOf.value) void load() })
async function detail(object: PropertyObject) {
  const id = supplier.value; if (!id) return
  const ticket = ++detailEpoch; selected.value = null; revision.value = null; editor.value = null; confirmRevision.value = null; error.value = ''
  try { const response = await propertyRegistryApi.detail(id, object.id, asOf.value, knownAt.value); if (ticket !== detailEpoch || supplier.value !== id) return; selected.value = response; revision.value = response.revision; for (const party of response.parties ?? []) partyLabels[party.id] = party.company_name || [party.first_name, party.last_name].filter(Boolean).join(' ') }
  catch (e) { if (ticket === detailEpoch) error.value = message(e) }
}
function nextUnitNo(previous: string) { const match = previous.match(/^(.*?)(\d+)$/); return match ? `${match[1]}${String(Number(match[2]) + 1).padStart(match[2]!.length, '0')}` : '' }
function initialState(type: PropertyKind, previous?: UnitState): PropertyState {
  if (type === 'house') {
    const firm = supplierStore.currentSupplier
    const street = firm?.street?.trim() ?? ''
    return { name: street || firm?.company_name || '', active: true, address: { street, city: firm?.city?.trim() ?? '', postal_code: firm?.zip?.replace(/\s+/g, ' ').trim() ?? '', country: 'CZ' }, declaration_ref: t('propertyRegistry.defaults.declaration'), share_scope: '', registry_complete: false }
  }
  if (type === 'service') return { name: '', active: true, measure: 'unit', measure_label: '' }
  const den = previous?.attributes.share?.den ?? (objects.unit.at(-1)?.revision?.state as UnitState | undefined)?.attributes?.share?.den ?? ''
  return { unit_no: previous ? nextUnitNo(previous.unit_no) : '', kind: previous?.kind ?? 'apartment', active: true, attributes: { share: { num: '', den } }, ownerships: [], roles: [], identifiers: [], services: previous ? [...previous.services] : [] }
}
function defaultFrom(type: PropertyKind, parentId: number | null): string {
  const start = profile.value?.profile?.effective_from
  if (type !== 'unit') return start || asOf.value
  const house = objects.house.find(h => h.id === parentId)
  return laterInstant(start, house?.revision?.effective_from) || asOf.value
}
function create(type: PropertyKind, previous?: { parent: number; state: UnitState }) {
  if (!canManage.value) return
  kind.value = type
  const parent = type === 'unit' ? previous?.parent ?? (selected.value?.kind === 'house' ? selected.value.id : objects.house.length === 1 ? objects.house[0]!.id : 0) : null
  selected.value = null; revision.value = null; confirmRevision.value = null; missing.value = []; showImport.value = false
  editor.value = { id: null, kind: type, code: '', label: t(`propertyRegistry.newKind.${type}`), input: { code: '', ...(type === 'unit' ? { parent_id: parent ?? 0 } : {}), expected_revision: 0, effective_from: defaultFrom(type, parent), effective_to: null, status: canConfirm.value ? 'confirmed' : 'pending', state: initialState(type, previous?.state), reason: t(`propertyRegistry.defaults.reason.${type}`), title_ref: t(`propertyRegistry.defaults.title.${type}`), idempotency_key: crypto.randomUUID() } }
}
function edit() {
  if (!canManage.value || !selected.value) return
  const source = revision.value?.state ?? selected.value.revision?.state
  if (!source) return
  const state = JSON.parse(JSON.stringify(source)) as PropertyState
  missing.value = []
  editor.value = { id: selected.value.id, kind: selected.value.kind, code: selected.value.code, label: t('propertyRegistry.editKind', { name: objectLabel(selected.value) }), input: { expected_revision: selected.value.current_revision, effective_from: asOf.value, effective_to: null, status: canConfirm.value ? 'confirmed' : 'pending', state, reason: '', title_ref: revision.value?.title_ref ?? '', idempotency_key: crypto.randomUUID() } }
  confirmRevision.value = null
}
function autoCode(): string {
  const draft = editor.value!
  const taken = objects[draft.kind].map(o => o.code)
  if (draft.kind === 'house') return propertyCode((draft.input.state as HouseState).name, taken, 'dum')
  if (draft.kind === 'service') return propertyCode((draft.input.state as ServiceState).name, taken, 'sluzba')
  const house = objects.house.find(h => h.id === draft.input.parent_id)
  return propertyCode(`${house?.code ?? ''}-${(draft.input.state as UnitState).unit_no}`, taken, 'jednotka')
}
const codePreview = computed(() => editor.value && editor.value.id === null ? autoCode() : '')
function positive(value: string) { return /^\d+$/.test(value.trim()) && BigInt(value.trim()) > 0n }
function validate(): string[] {
  const draft = editor.value!; const out: string[] = []; const s = draft.input.state
  if (!draft.input.effective_from) out.push(t('propertyRegistry.effectiveFrom'))
  if (draft.kind === 'house') {
    const h = s as HouseState
    if (!h.name.trim()) out.push(t('propertyRegistry.houseName'))
    for (const field of ['street', 'city', 'postal_code'] as const) if (!h.address[field].trim()) out.push(t(`propertyRegistry.address.${field}`))
    if (!/^[A-Z]{2}$/.test(h.address.country.trim().toUpperCase())) out.push(t('propertyRegistry.address.country'))
    if (!h.declaration_ref.trim()) out.push(t('propertyRegistry.declaration'))
    if (h.share_scope.trim() && !/^[A-Za-z0-9_.-]{1,80}$/.test(h.share_scope.trim())) out.push(t('propertyRegistry.shareScope'))
  } else if (draft.kind === 'service') {
    const v = s as ServiceState
    if (!v.name.trim()) out.push(t('propertyRegistry.serviceName'))
    if (v.measure === 'other' && !v.measure_label.trim()) out.push(t('propertyRegistry.measureLabel'))
  } else {
    const u = s as UnitState
    if (draft.id === null && !draft.input.parent_id) out.push(t('propertyRegistry.house'))
    if (!u.unit_no.trim()) out.push(t('propertyRegistry.unitNumber'))
    const share = u.attributes.share
    if (!share || !positive(share.num) || !positive(share.den) || BigInt(share.num.trim()) > BigInt(share.den.trim())) out.push(t('propertyRegistry.propertyShare'))
    if (u.ownerships.some(g => g.members.some(m => !m))) out.push(t('propertyRegistry.owner'))
    if (u.ownerships.some(g => !g.title_ref.trim())) out.push(t('propertyRegistry.ownershipTitleRef'))
    if (u.roles.some(r => !r.client_id || !r.title_ref.trim())) out.push(t('propertyRegistry.roles'))
    if (u.identifiers.some(i => !i.value.trim() || !i.title_ref.trim() || (['vs', 'ss'].includes(i.kind) && !/^\d{1,10}$/.test(i.value.trim())))) out.push(t('propertyRegistry.identifiers'))
  }
  if (draft.id === null && draft.code.trim() && !/^[A-Za-z0-9_.-]{1,80}$/.test(draft.code.trim())) out.push(t('propertyRegistry.code'))
  if (!draft.input.title_ref.trim()) out.push(t('propertyRegistry.titleRef'))
  if (!draft.input.reason.trim()) out.push(t('propertyRegistry.reason'))
  return out
}
async function save(another = false) {
  const id = supplier.value; const draft = editor.value; if (!id || !draft || busy.value) return
  missing.value = validate(); if (missing.value.length) return
  const input: PropertyMutation = JSON.parse(JSON.stringify(draft.input))
  if (draft.id === null) input.code = draft.code.trim() || autoCode()
  if (draft.kind === 'house') { const h = input.state as HouseState; h.address.country = h.address.country.trim().toUpperCase(); if (!h.share_scope.trim()) h.share_scope = draft.id === null ? input.code! : propertyCode(h.name, [], 'dum') }
  const ticket = epoch; busy.value = true; error.value = ''; notice.value = ''
  try {
    if (draft.id === null) await propertyRegistryApi.create(id, draft.kind, input); else await propertyRegistryApi.revise(id, draft.id, input)
    if (ticket !== epoch || supplier.value !== id) return
    const done = t('propertyRegistry.saved', { name: draft.kind === 'unit' ? t('propertyRegistry.unitLabel', { number: (input.state as UnitState).unit_no }) : (input.state as HouseState).name })
    const created = draft.id === null; const type = draft.kind
    await load()
    kind.value = type === 'house' && created && !objects.unit.length ? 'unit' : type
    notice.value = done
    if (another && type === 'unit') create('unit', { parent: input.parent_id ?? 0, state: input.state as UnitState })
  } catch (e) { if (ticket === epoch) error.value = message(e) } finally { if (supplier.value === id) busy.value = false }
}
function beginConfirm(row: PropertyRevision) { confirmRevision.value = row; confirmReason.value = t('propertyRegistry.defaults.confirmReason'); confirmTitle.value = row.title_ref; confirmToken = crypto.randomUUID(); editor.value = null; revision.value = row }
async function confirm() {
  const id = supplier.value; if (!id || !selected.value || !confirmRevision.value || busy.value || !confirmReason.value.trim() || !confirmTitle.value.trim()) return
  const ticket = epoch; busy.value = true; error.value = ''
  try { await propertyRegistryApi.confirm(id, selected.value.id, confirmRevision.value.id, { expected_revision: selected.value.current_revision, effective_from: confirmRevision.value.effective_from, reason: confirmReason.value, title_ref: confirmTitle.value, idempotency_key: confirmToken }); if (ticket === epoch && supplier.value === id) await load() }
  catch (e) { if (ticket === epoch) error.value = message(e) } finally { if (supplier.value === id) busy.value = false }
}
function beginProfile(first = false) {
  showProfile.value = first || !showProfile.value
  const initial = !profile.value?.history.length
  profileForm.effective_from = initial ? propertyYearStart() : asOf.value; profileForm.effective_to = ''; profileForm.enabled = true
  profileForm.reason = initial ? t('propertyRegistry.defaults.profileReason') : ''; profileForm.title_ref = initial ? t('propertyRegistry.defaults.profileTitle') : ''
  profileToken = crypto.randomUUID()
}
async function saveProfile() {
  const id = supplier.value; if (!id || busy.value || !profileForm.effective_from || !profileForm.reason.trim() || !profileForm.title_ref.trim()) return
  const ticket = epoch; busy.value = true; error.value = ''
  try { await propertyRegistryApi.profileSave(id, { ...profileForm, effective_to: profileForm.effective_to || null, expected_revision: Math.max(0, ...profile.value!.history.map(p => p.revision_no)), idempotency_key: profileToken }); if (ticket === epoch && supplier.value === id) { await load(); if (enabled.value) notice.value = t('propertyRegistry.profileSaved') } }
  catch (e) { if (ticket === epoch) error.value = message(e) } finally { if (supplier.value === id) busy.value = false }
}
async function declareComplete(ids: number[]) {
  const house = objects.house.find(h => ids.includes(h.id) && !(h.revision?.state as HouseState | undefined)?.registry_complete)
  if (!house) return
  kind.value = 'house'
  await detail(house)
  if (selected.value?.id !== house.id) return
  edit()
  if (!editor.value) return
  (editor.value.input.state as HouseState).registry_complete = true
  editor.value.input.reason = t('propertyRegistry.defaults.completeReason')
  const first = [...(selected.value.history ?? [])].sort((x, y) => Date.parse(x.effective_from) - Date.parse(y.effective_from))[0]
  if (first) editor.value.input.effective_from = first.effective_from
}
function onStep(key: string) {
  if (key === 'profile') { beginProfile(true); return }
  create(key as PropertyKind)
}
async function exportData() {
  const id = supplier.value; if (!id || busy.value) return
  const ticket = epoch; busy.value = true
  try { const data = await propertyRegistryApi.export(id, asOf.value, knownAt.value); if (ticket !== epoch || supplier.value !== id) return; const url = URL.createObjectURL(new Blob([JSON.stringify(data, null, 2)], { type: 'application/json' })); const link = document.createElement('a'); link.href = url; link.download = 'property-registry.json'; link.click(); URL.revokeObjectURL(url) }
  catch (e) { if (ticket === epoch) error.value = message(e) } finally { if (supplier.value === id) busy.value = false }
}
</script>
<template>
  <div class="space-y-5">
    <div><h1 class="text-2xl font-semibold">{{ t('propertyRegistry.title') }}</h1><p class="text-sm text-neutral-500 mt-0.5">{{ t('propertyRegistry.intro') }}</p></div>
    <p v-if="!supplier" class="text-warning-600">{{ t('propertyRegistry.chooseSupplier') }}</p>
    <p v-if="error" role="alert" class="rounded border border-danger-300 p-3 text-danger-600">{{ error }}</p>
    <p v-if="notice && !error" role="status" class="rounded border border-success-300 p-3 text-success-700">{{ notice }}</p>
    <p v-if="loading || busy" role="status" class="text-sm text-neutral-500">{{ t('propertyRegistry.working') }}</p>
    <template v-if="profile && supplier">
      <PropertySetupGuide v-if="!setupDone" :title="t('propertySetup.title')" :steps="steps" @action="onStep" />
      <div v-else class="flex flex-wrap items-center gap-2 text-sm"><span class="text-neutral-500">{{ t('propertySetup.next') }}</span><RouterLink to="/property/charges" class="text-primary-700 hover:underline">{{ t('nav.property_charges') }}</RouterLink><span aria-hidden="true" class="text-neutral-400">→</span><RouterLink to="/property/charges/issues" class="text-primary-700 hover:underline">{{ t('nav.property_issuance') }}</RouterLink></div>
      <p v-if="!enabled && !canConfirm" class="rounded border border-warning-300 p-3 text-sm text-warning-700">{{ t('propertyRegistry.profileNeedsAdmin') }}</p>
      <form v-if="showProfile && canConfirm" class="space-y-3 rounded-lg border border-primary-300 bg-surface p-4" data-profile-form @submit.prevent="saveProfile">
        <h2 class="text-lg font-semibold">{{ t(profile.history.length ? 'propertyRegistry.profileChange' : 'propertyRegistry.profileStart') }}</h2>
        <p class="text-sm text-neutral-500">{{ t('propertyRegistry.profileStartHelp') }}</p>
        <PropertyInstantInput v-model="profileForm.effective_from" required :label="t('propertyRegistry.profileFrom')" />
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.profileTitleRef') }} *<input v-model="profileForm.title_ref" data-field="profile-title" required :class="inputClass"></label>
        <details class="text-sm"><summary class="cursor-pointer text-neutral-600">{{ t('propertyRegistry.advanced') }}</summary><div class="space-y-3 pt-2">
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.reason') }} *<input v-model="profileForm.reason" data-field="profile-reason" required :class="inputClass"></label>
          <PropertyInstantInput v-model="profileForm.effective_to" :label="t('propertyRegistry.effectiveTo')" />
          <label v-if="profile.history.length" class="flex gap-2"><input v-model="profileForm.enabled" type="checkbox">{{ t('propertyRegistry.confirmSvj') }}</label>
        </div></details>
        <div class="flex flex-wrap gap-2"><PropertyButton icon="check" variant="success" filled submit :disabled="busy || !profileForm.effective_from || !profileForm.reason.trim() || !profileForm.title_ref.trim()">{{ t(profile.history.length ? 'propertyRegistry.saveProfile' : 'propertyRegistry.startProfile') }}</PropertyButton><PropertyButton v-if="profile.history.length" icon="x" :disabled="busy" @click="showProfile = false">{{ t('propertyRegistry.cancel') }}</PropertyButton></div>
      </form>
      <section v-if="summary?.scopes.length" class="grid gap-3 sm:grid-cols-2"><div v-for="scope in summary.scopes" :key="scope.scope" class="rounded-lg border border-neutral-200 bg-surface p-4"><h2 class="font-medium">{{ scope.houses.map(h => h.name).join(', ') }}</h2><p>{{ t('propertyRegistry.summaryUnits', { count: scope.units }) }} · {{ t('propertyRegistry.summaryShares', { share: `${scope.total_share.num}/${scope.total_share.den}` }) }}</p><p :class="scope.share_complete && scope.declared_complete ? 'text-success-600' : 'text-warning-600'">{{ t(scope.share_complete && scope.declared_complete ? 'propertyRegistry.coverageComplete' : scope.share_complete ? 'propertyRegistry.coverageUndeclared' : 'propertyRegistry.coverageIncomplete') }}</p><p v-if="scope.ownership_review_required" class="text-warning-600">{{ t('propertyRegistry.reviewCount', { count: scope.ownership_review_required }) }}</p><div v-if="scope.share_complete && !scope.declared_complete && canManage && enabled" class="pt-2"><PropertyButton icon="check" variant="success" :disabled="busy" data-declare-complete @click="declareComplete(scope.houses.map(h => h.id))">{{ t('propertyRegistry.declareComplete') }}</PropertyButton></div></div></section>
      <div class="flex flex-wrap items-end justify-between gap-3 border-b border-neutral-200">
        <nav class="flex flex-wrap gap-1" role="tablist"><button v-for="tab in (['house', 'unit', 'service'] as const)" :key="tab" type="button" role="tab" :aria-selected="kind === tab" :data-tab="tab" class="-mb-px whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium" :class="kind === tab ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-600 hover:text-neutral-900'" @click="kind = tab">{{ t(`propertyRegistry.kinds.${tab}`) }} <span class="ml-1 rounded-full bg-neutral-100 px-2 text-xs text-neutral-600">{{ objects[tab].length }}</span></button></nav>
        <div v-if="canManage && enabled" class="flex flex-wrap gap-2 pb-2"><PropertyButton icon="plus" variant="primary" filled :disabled="busy || (kind === 'unit' && !objects.house.length)" :data-create="kind" @click="create(kind)">{{ t(`propertyRegistry.add.${kind}`) }}</PropertyButton><PropertyButton v-if="kind === 'unit' && objects.house.length" icon="upload" :disabled="busy" @click="showImport = !showImport">{{ t('propertyRegistry.importTitle') }}</PropertyButton></div>
      </div>
      <PropertyImportPanel v-if="showImport && canManage && kind === 'unit'" :key="supplier" :supplier-id="supplier" :houses="objects.house" :services="objects.service" :columns="profile.import_columns" :as-of="asOf" :can-confirm="canConfirm" @applied="load" />
      <section v-if="editor" class="space-y-4 rounded-lg border border-primary-300 bg-surface p-4" data-editor>
        <h2 class="text-lg font-semibold">{{ editor.label }}</h2>
        <p v-if="editor.id === null && editor.kind === 'house'" class="text-sm text-neutral-500">{{ t('propertyRegistry.housePrefilled') }}</p>
        <form class="space-y-4" novalidate @submit.prevent="save()"><fieldset :disabled="busy" class="space-y-4">
          <label v-if="editor.id === null && editor.kind === 'unit'" class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.house') }} *<select v-model="editor.input.parent_id" data-field="parent_id" :class="inputClass"><option :value="0">{{ t('propertyRegistry.chooseHouse') }}</option><option v-for="house in objects.house" :key="house.id" :value="house.id">{{ objectLabel(house) }}</option></select></label>
          <PropertyStateEditor v-model="editor.input.state" :kind="editor.kind" :create="editor.id === null" :supplier-id="supplier" :services="objects.service" :party-labels="partyLabels" @party="partyLabels[$event.id] = $event.name" />
          <div class="space-y-3 rounded-lg border border-neutral-200 p-3">
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.titleRef') }} *<input v-model="editor.input.title_ref" data-field="title_ref" :class="inputClass"><span class="text-xs text-neutral-500">{{ t('propertyRegistry.titleRefHelp') }}</span></label>
            <label v-if="editor.id !== null" class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.reason') }} *<input v-model="editor.input.reason" data-field="reason" :class="inputClass" :placeholder="t('propertyRegistry.reasonPlaceholder')"></label>
            <details class="text-sm"><summary class="cursor-pointer text-neutral-600">{{ t('propertyRegistry.advanced') }}</summary><div class="space-y-3 pt-2">
              <p class="text-xs text-neutral-500">{{ t('propertyRegistry.advancedHelp') }}</p>
              <PropertyInstantInput v-model="editor.input.effective_from" required :label="t('propertyRegistry.effectiveFrom')" />
              <PropertyInstantInput :model-value="editor.input.effective_to ?? ''" :label="t('propertyRegistry.effectiveTo')" @update:model-value="editor.input.effective_to = $event || null" />
              <label v-if="editor.id === null" class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.code') }}<input v-model="editor.code" :class="inputClass" maxlength="80" :placeholder="codePreview"><span class="text-xs text-neutral-500">{{ t('propertyRegistry.codeHelp') }}</span></label>
              <label v-if="editor.id === null" class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.reason') }}<input v-model="editor.input.reason" :class="inputClass"></label>
              <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.status') }}<select v-model="editor.input.status" :class="inputClass"><option value="pending">{{ t('propertyRegistry.statuses.pending') }}</option><option v-if="canConfirm" value="confirmed">{{ t('propertyRegistry.statuses.confirmed') }}</option></select></label>
            </div></details>
          </div></fieldset>
          <div v-if="missing.length" role="alert" class="rounded border border-warning-300 p-3 text-sm text-warning-700" data-missing>{{ t('propertyRegistry.missing') }} {{ missing.join(', ') }}</div>
          <div class="flex flex-wrap gap-2"><PropertyButton icon="check" variant="success" filled submit :disabled="busy">{{ saveLabel }}</PropertyButton><PropertyButton v-if="editor.id === null && editor.kind === 'unit'" icon="plus" variant="success" :disabled="busy" data-save-next @click="save(true)">{{ t('propertyRegistry.saveAndNext') }}</PropertyButton><PropertyButton icon="x" :disabled="busy" @click="editor = null; missing = []">{{ t('propertyRegistry.cancelEdit') }}</PropertyButton></div>
        </form>
      </section>
      <div v-if="objects[kind].length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <article v-for="object in objects[kind]" :key="object.id" class="space-y-1 rounded-lg border bg-surface p-3" :class="selected?.id === object.id ? 'border-primary-400' : 'border-neutral-200'">
          <div class="flex flex-wrap items-start justify-between gap-2"><h2 class="font-medium">{{ objectLabel(object) }}</h2>
            <span v-if="!object.revision" class="rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-700">{{ t('propertyRegistry.noConfirmedState') }}</span>
            <span v-else-if="object.pending_revision_ids.length" class="rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-700" data-badge="pending">{{ t('propertyRegistry.pendingBadge') }}</span>
            <span v-else-if="object.kind === 'unit' && !ownerNames(object)" class="rounded-full bg-warning-50 px-2 py-0.5 text-xs text-warning-700" data-badge="owner">{{ t('propertyRegistry.ownerMissingBadge') }}</span>
            <span v-else-if="!object.revision.state.active" class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600">{{ t('propertyRegistry.inactive') }}</span></div>
          <p v-if="kind === 'house'" class="text-sm text-neutral-500">{{ address(object) }}</p>
          <p v-else-if="kind === 'unit'" class="text-sm text-neutral-500">{{ houseOf(object) }}<template v-if="unitShare(object)"> · {{ t('propertyRegistry.shareShort', { share: unitShare(object) }) }}</template><template v-if="ownerNames(object)"> · {{ t('propertyRegistry.ownersCount', { count: ownerNames(object) }) }}</template></p>
          <div class="flex flex-wrap gap-2 pt-1"><PropertyButton icon="eye" @click="detail(object)">{{ t('propertyRegistry.detail') }}</PropertyButton><PropertyButton v-if="kind === 'house' && canManage && enabled" icon="plus" variant="primary" @click="selected = object; create('unit')">{{ t('propertyRegistry.add.unit') }}</PropertyButton></div>
        </article>
      </div>
      <div v-else-if="!editor" class="rounded-lg border border-dashed border-neutral-300 p-6 text-center text-sm text-neutral-500" data-empty>
        <p>{{ t(`propertyRegistry.emptyKind.${kind}`) }}</p>
        <div v-if="canManage && enabled && (kind !== 'unit' || objects.house.length)" class="mt-3 flex justify-center"><PropertyButton icon="plus" variant="primary" filled @click="create(kind)">{{ t(`propertyRegistry.add.${kind}`) }}</PropertyButton></div>
        <p v-else-if="!enabled" class="mt-2">{{ t('propertyRegistry.enableFirst') }}</p>
      </div>
      <section v-if="selected && !editor" class="space-y-4 rounded-lg border border-neutral-200 bg-surface p-4" data-detail>
        <div class="flex flex-wrap items-start justify-between gap-2"><h2 class="text-lg font-semibold">{{ objectLabel(selected) }}</h2>
          <div class="flex flex-wrap gap-2"><PropertyButton v-if="revision && canManage && enabled" icon="edit" variant="primary" filled :disabled="busy" @click="edit()">{{ t('propertyRegistry.edit') }}</PropertyButton><PropertyButton v-if="selected.kind === 'house' && canManage && enabled" icon="plus" variant="primary" :disabled="busy" @click="create('unit')">{{ t('propertyRegistry.add.unit') }}</PropertyButton><PropertyButton icon="x" @click="selected = null; revision = null; confirmRevision = null">{{ t('propertyRegistry.close') }}</PropertyButton></div></div>
        <PropertyStateEditor v-if="revision" :key="revision.id" :model-value="revision.state" :kind="selected.kind" :supplier-id="supplier" :services="objects.service" :party-labels="partyLabels" readonly />
        <details :open="!!selected.history?.some(r => r.status === 'pending')"><summary class="cursor-pointer font-medium">{{ t('propertyRegistry.historyCount', { count: selected.history?.length ?? 0 }) }}</summary><div class="space-y-3 pt-3"><article v-for="row in selected.history" :key="row.id" class="space-y-2 border-b border-neutral-200 pb-3"><p class="text-sm">{{ t('propertyRegistry.revision', { n: row.revision }) }} · {{ t(`propertyRegistry.statuses.${row.status}`) }} · {{ t('propertyRegistry.validFrom', { at: formatPropertyInstant(row.effective_from) }) }}<template v-if="row.effective_to"> → {{ formatPropertyInstant(row.effective_to) }}</template></p><p class="text-sm">{{ row.reason }} · {{ row.title_ref }}</p><p class="text-xs text-neutral-500">{{ t('propertyRegistry.recordedAt') }}: {{ formatPropertyInstant(row.recorded_at) }}</p><div class="flex flex-wrap gap-2"><PropertyButton icon="eye" @click="revision = row; confirmRevision = null">{{ t('propertyRegistry.showState') }}</PropertyButton><PropertyButton v-if="row.status === 'pending' && !selected.history?.some(r => r.resolves_revision_id === row.id) && canConfirm" icon="check" variant="success" :disabled="busy" @click="beginConfirm(row)">{{ t('propertyRegistry.confirm') }}</PropertyButton></div></article></div></details>
        <form v-if="confirmRevision" class="space-y-3 rounded border border-success-300 p-3" @submit.prevent="confirm"><p>{{ t('propertyRegistry.confirmHelp') }}</p><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.reason') }} *<input v-model="confirmReason" :class="inputClass"></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyRegistry.titleRef') }} *<input v-model="confirmTitle" :class="inputClass"></label><PropertyButton icon="check" variant="success" filled submit :disabled="busy || !confirmReason.trim() || !confirmTitle.trim()">{{ t('propertyRegistry.confirm') }}</PropertyButton></form>
      </section>
      <details class="rounded-lg border border-neutral-200 bg-surface p-4" data-settings><summary class="cursor-pointer font-medium">{{ t('propertyRegistry.settings') }}</summary>
        <div class="space-y-4 pt-3">
          <p class="text-sm">{{ t(enabled ? 'propertyRegistry.profileEnabled' : 'propertyRegistry.profileDisabled') }}<template v-if="profile.profile"> {{ t('propertyRegistry.validFrom', { at: formatPropertyInstant(profile.profile.effective_from) }) }}</template></p>
          <p class="text-sm text-neutral-500">{{ t('propertyRegistry.profileHelp') }}</p>
          <div class="flex flex-wrap gap-2"><PropertyButton v-if="canConfirm && profile.history.length" icon="edit" variant="warning" :disabled="busy" @click="beginProfile()">{{ t('propertyRegistry.profileChange') }}</PropertyButton><PropertyButton v-if="profile.capabilities.export" icon="download" :disabled="busy" @click="exportData">{{ t('propertyRegistry.export') }}</PropertyButton></div>
          <ul v-if="profile.history.length" class="space-y-1 text-sm text-neutral-600"><li v-for="row in profile.history" :key="row.id">{{ formatPropertyInstant(row.effective_from) }} · {{ t(row.enabled ? 'propertyRegistry.profileEnabled' : 'propertyRegistry.profileDisabled') }} · {{ row.reason }} · {{ row.title_ref }}</li></ul>
          <div class="space-y-3 border-t border-neutral-200 pt-3"><p class="text-sm text-neutral-500">{{ t('propertyRegistry.asOfHelp') }}</p>
            <div class="flex flex-wrap items-end gap-3"><PropertyInstantInput v-model="asOf" :label="t('propertyRegistry.asOf')" /><PropertyInstantInput v-model="knownAt" :label="t('propertyRegistry.knownAt')" /><PropertyButton icon="x" @click="asOf = propertyNow(); knownAt = ''">{{ t('propertyRegistry.currentKnowledge') }}</PropertyButton></div></div>
        </div>
      </details>
    </template>
  </div>
</template>
