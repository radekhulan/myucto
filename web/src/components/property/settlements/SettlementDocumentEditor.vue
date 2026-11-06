<script setup lang="ts">
import SettlementDossierForm from './SettlementDossierForm.vue'
import { blankDossier, dossierRecipients, dossierCopy } from './dossierForm'
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertySettlementsApi as api, type SettlementCapabilities, type SettlementDetail, type SettlementCatalogItem, type SettlementDocumentRequest, type SettlementFacts } from '@/api/propertySettlements'
import { propertyRegistryApi, type PropertyObject, type UnitState, type ServiceState } from '@/api/propertyRegistry'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import PropertyPartyPicker from '@/components/property/PropertyPartyPicker.vue'
import PropertyButton from '@/components/property/PropertyButton.vue'
import { propertyError } from '@/components/property/propertyError'
import { formatDate } from '@/composables/useFormat'
import { settlementInput as input, settlementBox as box, settlementMoney as money } from './settlementForm'
const props = defineProps<{ supplier: number; capabilities: SettlementCapabilities; revision?: SettlementDetail | null }>()
const emit = defineEmits<{ saved: [id: number]; cancel: [] }>()
const { t } = useI18n()
const blank = (): SettlementDocumentRequest => ({ unit_id: 0, service_id: 0, segment_key: '', accounting_date: '', period_from: '', period_to: '', cutoff_at: '', known_at: '', recipient_ids: [], evidence: '', selectors: [], cost_source_id: 0 })
const externalDossier = ref(blankDossier()), attachDossier = ref(false)
const form = reactive(blank()), units = ref<PropertyObject[]>([]), services = ref<PropertyObject[]>([])
const sources = ref<SettlementCatalogItem[]>([]), costs = ref<SettlementCatalogItem[]>([]), selected = ref<SettlementCatalogItem[]>([])
const kind = ref<'s3' | 'opening'>('s3'), cost = ref<SettlementCatalogItem | null>(null), cursor = ref<{ after_id: number; after_service_id: number } | null>(null), costCursor = ref<{ after_id: number; after_service_id: number } | null>(null)
const busy = ref(false), error = ref(''), person = ref<number | null>(null), names = ref<Record<number, string>>({}), html = ref<HTMLFormElement | null>(null)
const preview = ref<{ document: SettlementFacts; preview_hash: string } | null>(null)
let registryEpoch = 0
let epoch = 0, submitted: SettlementDocumentRequest | null = null, token = crypto.randomUUID()
const expected = computed(() => props.revision?.revision_no ?? 0)
const key = (v: SettlementCatalogItem) => JSON.stringify(v.selector)
const label = (v: PropertyObject) => v.kind === 'unit' ? (v.revision?.state as UnitState | undefined)?.unit_no || v.code : (v.revision?.state as ServiceState | undefined)?.name || v.code
function invalidate() { epoch++; preview.value = null; submitted = null; token = crypto.randomUUID() }
watch([form, selected, cost, externalDossier, attachDossier], invalidate, { deep: true, flush: 'sync' })
onBeforeUnmount(() => { epoch++; registryEpoch++ })
watch(() => [props.supplier, props.revision?.id], () => {
 invalidate(); externalDossier.value = blankDossier(); attachDossier.value = false; Object.assign(form, blank()); sources.value = []; selected.value = []; costs.value = []; cost.value = null; error.value = ''; busy.value = false
 const old = props.revision
 if (old) {
  const priorDossier = old.dossier?.dossier || old.document.external_dossier; if (priorDossier) { externalDossier.value = dossierCopy(priorDossier); attachDossier.value = true }
  Object.assign(form, old.document, { selectors: old.origin_receipt.request.selectors, accounting_date: old.origin_receipt.request.accounting_date, cost_source_id: old.document.cost.source_id })
  selected.value = old.document.origins.map((origin, i) => ({ id: origin.id, selector: old.origin_receipt.request.selectors[i]!, unit: old.unit, service: old.service, description: `${old.unit.label} / ${old.service.label}`, document_no: null, accounting_date: form.accounting_date, period_from: form.period_from, period_to: form.period_to, prescribed_cents: origin.prescribed_cents, carried_paid_cents: origin.carried_paid_cents, cost_source_id: null, cost_cents: null }))
  cost.value = { ...selected.value[0]!, id: old.document.cost.source_id, description: `${old.unit.label} / ${old.service.label}`, cost_source_id: old.document.cost.source_id, cost_cents: old.document.actual_cents }
 }
 void registry()
}, { immediate: true })
async function registry() {
 const supplier = props.supplier, ticket = ++registryEpoch, at = form.cutoff_at || new Date().toISOString()
 try { const values = await Promise.all([propertyRegistryApi.list(supplier, 'unit', at, form.known_at || undefined), propertyRegistryApi.list(supplier, 'service', at, form.known_at || undefined)]); if (ticket === registryEpoch && supplier === props.supplier) [units.value, services.value] = values }
 catch (e) { if (ticket === registryEpoch) error.value = propertyError(e, t) }
}
function scopeChanged() { invalidate(); selected.value = []; sources.value = []; costs.value = []; cost.value = null; cursor.value = null; costCursor.value = null }
function addPerson(p: { id: number; name: string }) { if (!form.recipient_ids.includes(p.id)) form.recipient_ids.push(p.id); names.value[p.id] = p.name; person.value = null }
function toggle(source: SettlementCatalogItem) { const index = selected.value.findIndex(v => key(v) === key(source)); if (index < 0) selected.value.push(source); else selected.value.splice(index, 1); cost.value = null; costs.value = []; costCursor.value = null }
async function catalog(more = false, forCosts = false) {
 if (busy.value || !form.unit_id || !form.service_id || !form.accounting_date) return
 invalidate(); const ticket = epoch, supplier = props.supplier; busy.value = true; error.value = ''
 try {
  const s3 = selected.value.find(v => v.selector.kind === 's3_line')?.selector
  if (forCosts && s3?.kind !== 's3_line') throw new Error('source_required')
  const result = await api.catalog(supplier, forCosts ? 'costs' : kind.value, { unit_id: form.unit_id, service_id: form.service_id, date: form.accounting_date, ...(forCosts && s3?.kind === 's3_line' ? { s3_line_id: s3.id } : {}), ...(more ? (forCosts ? costCursor.value : cursor.value) ?? {} : {}) })
  if (ticket !== epoch || supplier !== props.supplier) return
  if (forCosts) { costs.value = more ? [...costs.value, ...result.items] : result.items; costCursor.value = result.next_cursor } else { sources.value = more ? [...sources.value, ...result.items] : result.items; cursor.value = result.next_cursor }
 } catch (e) { if (ticket === epoch) error.value = propertyError(e, t) } finally { if (ticket === epoch) busy.value = false }
}
function request(): SettlementDocumentRequest {
 const selectedCost = cost.value
 if (!selected.value.length || !selectedCost?.cost_source_id || !(attachDossier.value ? dossierRecipients(externalDossier.value).length : form.recipient_ids.length)) throw new Error('selection_required')
 let attached = false
 const selectors = selected.value.map(v => { const s = { ...v.selector }; if (s.kind === 's3_line' && !attached) { delete s.cost_line_id; delete s.cost_sources; if (selectedCost.selector.cost_sources) s.cost_sources = dossierCopy(selectedCost.selector.cost_sources); else s.cost_line_id = selectedCost.cost_source_id!; attached = true }; return s })
 if (!attached && !selected.value.some(v => v.cost_source_id === cost.value!.cost_source_id) && !props.revision) throw new Error('cost_scope')
 return { unit_id: form.unit_id, service_id: form.service_id, segment_key: form.segment_key, accounting_date: form.accounting_date, period_from: form.period_from, period_to: form.period_to, cutoff_at: form.cutoff_at, known_at: form.known_at, recipient_ids: attachDossier.value ? dossierRecipients(externalDossier.value) : [...form.recipient_ids], evidence: form.evidence, selectors, cost_source_id: selectedCost.cost_source_id, ...(attachDossier.value ? { external_dossier: dossierCopy(externalDossier.value) } : {}) }
}
async function prepare() {
 if (busy.value || !props.capabilities.manage || !html.value?.reportValidity()) return
 error.value = ''; let payload: SettlementDocumentRequest
 try { payload = request() } catch { error.value = t('propertySettlements.selectRequired'); return }
 const ticket = epoch, supplier = props.supplier; busy.value = true
 try { const result = await api.prepare(supplier, payload, expected.value); if (ticket === epoch && supplier === props.supplier) { submitted = payload; preview.value = result } }
 catch (e) { if (ticket === epoch) { invalidate(); error.value = propertyError(e, t) + ' ' + t('propertySettlements.newPreview') } } finally { if (supplier === props.supplier) busy.value = false }
}
async function save() {
 if (busy.value || !props.capabilities.manage || !preview.value || !submitted) return
 const ticket = epoch, supplier = props.supplier, payload = submitted, hash = preview.value.preview_hash; busy.value = true; error.value = ''
 try { const result = await api.save(supplier, payload, expected.value, hash, token); if (ticket === epoch && supplier === props.supplier) emit('saved', result.id) }
 catch (e) { if (ticket === epoch) { invalidate(); error.value = propertyError(e, t) + ' ' + t('propertySettlements.newPreview') } } finally { if (supplier === props.supplier) busy.value = false }
}
const actions = computed<ActionItem[]>(() => [{ key: preview.value ? 'save-document' : 'prepare-document', label: t(preview.value ? 'propertySettlements.save' : 'propertySettlements.prepare'), icon: preview.value ? 'check' : 'eye', variant: preview.value ? 'success' : 'primary', tier: 'primary', disabled: busy.value || !props.capabilities.manage, run: () => { void (preview.value ? save() : prepare()) } }, { key: 'cancel-document', label: t('propertySettlements.cancel'), icon: 'x', tier: 'secondary', disabled: busy.value, run: () => emit('cancel') }])
</script>
<template>
 <section :class="box" data-section="document-editor"><h2 class="font-semibold">{{ t(revision ? 'propertySettlements.newRevision' : 'propertySettlements.newDocument') }}</h2><p class="text-sm text-neutral-500">{{ t('propertySettlements.documentHelp') }}</p><p v-if="revision" class="text-sm">{{ t('propertySettlements.revisionFrom', { revision: expected }) }}</p>
 <p v-if="error" role="alert" class="text-danger-700">{{ error }}</p>
 <form ref="html" class="space-y-4" @submit.prevent="prepare"><fieldset :disabled="busy || !capabilities.manage" class="space-y-4">
 <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
 <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.unit') }}<select v-model.number="form.unit_id" required :class="input" :disabled="!!revision" data-field="unit" @change="scopeChanged"><option :value="0">{{ t('propertySettlements.choose') }}</option><option v-for="v in units" :key="v.id" :value="v.id">{{ v.code }} · {{ label(v) }}</option></select></label>
 <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.service') }}<select v-model.number="form.service_id" required :class="input" :disabled="!!revision" data-field="service" @change="scopeChanged"><option :value="0">{{ t('propertySettlements.choose') }}</option><option v-for="v in services" :key="v.id" :value="v.id">{{ v.code }} · {{ label(v) }}</option></select></label>
 <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.segment') }}<input v-model="form.segment_key" required :readonly="!!revision" :class="input" data-field="segment" /></label>
 <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.periodFrom') }}<DateInput :class="input" v-model="form.period_from" required data-field="period-from" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.periodTo') }}<DateInput :class="input" v-model="form.period_to" required data-field="period-to" /></label><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.accountingDate') }}<DateInput :class="input" v-model="form.accounting_date" required data-field="document-date" @update:model-value="scopeChanged" /></label>
 <PropertyInstantInput v-model="form.cutoff_at" :label="t('propertySettlements.cutoff')" required data-field="cutoff" /><PropertyInstantInput v-model="form.known_at" :label="t('propertySettlements.knownAt')" required data-field="known" /><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.evidence') }}<input v-model="form.evidence" required :class="input" data-field="document-evidence" /></label>
 </div><PropertyButton icon="cycle" @click="registry">{{ t('propertySettlements.refreshRegistry') }}</PropertyButton>
 <label class="flex flex-wrap items-center gap-2"><input v-model="attachDossier" type="checkbox" data-field="attach-dossier" />{{ t('propertySettlements.dossier.attach') }}</label><SettlementDossierForm v-if="attachDossier" v-model="externalDossier" :supplier="supplier" :labels="Object.fromEntries(revision?.dossier?.parties.map(p => [p.id, p.name]) ?? [])" :period-from="form.period_from" :period-to="form.period_to" /><fieldset v-else class="space-y-2"><legend>{{ t('propertySettlements.recipients') }}</legend><div class="flex flex-wrap gap-2"><span v-for="id in form.recipient_ids" :key="id" class="flex items-center gap-2 rounded border p-2 text-sm">{{ names[id] || t('propertySettlements.selectedPerson') }}<PropertyButton icon="trash" variant="danger" @click="form.recipient_ids.splice(form.recipient_ids.indexOf(id), 1)">{{ t('propertySettlements.remove') }}</PropertyButton></span></div><PropertyPartyPicker v-model="person" :supplier-id="supplier" @selected="addPerson" /></fieldset>
 <div class="space-y-2"><h3 class="font-medium">{{ t('propertySettlements.sources') }}</h3><div class="flex flex-wrap items-end gap-3"><label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertySettlements.sourceKind') }}<select v-model="kind" :class="input"><option value="s3">{{ t('propertySettlements.s3') }}</option><option value="opening">{{ t('propertySettlements.opening') }}</option></select></label><PropertyButton icon="search" data-action="load-sources" @click="catalog()">{{ t('propertySettlements.loadSources') }}</PropertyButton></div>
 <label v-for="v in sources" :key="key(v)" class="flex flex-wrap items-center gap-3 rounded border p-3"><input type="checkbox" :checked="selected.some(s => key(s) === key(v))" data-field="source-select" @change="toggle(v)" /><span>{{ v.description }} · {{ v.document_no }} · {{ formatDate(v.accounting_date) }} · {{ v.period_from }} - {{ v.period_to }}</span><span>{{ money(v.prescribed_cents) }}</span></label><PropertyButton v-if="cursor" icon="plus" @click="catalog(true)">{{ t('propertySettlements.more') }}</PropertyButton>
 <p v-for="v in selected" :key="key(v)" class="flex flex-wrap items-center gap-2 text-sm">{{ t('propertySettlements.selectedSource') }}: {{ v.description }} · {{ formatDate(v.accounting_date) }} · {{ money(v.prescribed_cents) }}<PropertyButton icon="trash" variant="danger" @click="toggle(v)">{{ t('propertySettlements.remove') }}</PropertyButton></p></div>
 <div class="space-y-2"><h3 class="font-medium">{{ t('propertySettlements.costs') }}</h3><PropertyButton v-if="selected.some(v => v.selector.kind === 's3_line')" icon="search" data-action="load-costs" @click="catalog(false, true)">{{ t('propertySettlements.loadCosts') }}</PropertyButton><label v-for="v in [...selected.filter(s => s.cost_source_id), ...costs]" :key="`${v.id}:${v.cost_source_id}`" class="flex flex-wrap items-center gap-3 rounded border p-3"><input type="radio" name="settlement-cost" :checked="cost?.cost_source_id === v.cost_source_id" data-field="cost-select" @change="cost = v" /><span>{{ v.description }} · {{ v.document_no }} · {{ formatDate(v.accounting_date) }}</span><span>{{ money(v.cost_cents || '0') }}</span><span v-if="v.cost_parts" class="flex flex-wrap gap-2 text-sm text-neutral-500" data-section="cost-parts"><span v-for="(part, index) in v.cost_parts" :key="index">{{ money(part.amount_cents) }} · {{ formatDate(part.accounting_date) }}</span></span></label><PropertyButton v-if="costCursor" icon="plus" @click="catalog(true, true)">{{ t('propertySettlements.more') }}</PropertyButton><p v-if="cost" class="text-sm">{{ t('propertySettlements.selectedCost') }}: {{ cost.description }} · {{ money(cost.cost_cents || '0') }}</p></div>
 </fieldset></form>
 <section v-if="preview" class="rounded border border-success-300 p-3" data-section="document-preview"><h3>{{ t('propertySettlements.documentPreview') }}</h3><dl class="grid gap-3 sm:grid-cols-3"><div v-for="key in ['prescribed_cents', 'paid_cents', 'actual_cents'] as const" :key="key"><dt>{{ t(`propertySettlements.${key}`) }}</dt><dd>{{ money(preview.document[key]) }}</dd></div></dl><p>{{ t('propertySettlements.immutableNotice') }}</p></section><ActionBar :actions="actions" />
 </section>
</template>
