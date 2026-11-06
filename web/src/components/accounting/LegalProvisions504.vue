<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { legalClosing504Api as api, type LegalCell, type LegalProvisionsPreview, type LegalDecision, type ReserveWorkspace } from '@/api/legalClosing504'
import type { DimensionMap } from '@/api/dimensions'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney } from '@/composables/useFormat'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DimensionFields from '@/components/dimensions/DimensionFields.vue'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
const props = defineProps<{ periodId: number; rowVersion: number; canWrite: boolean; isClosing: boolean }>()
const emit = defineEmits<{ updated: [] }>()
const { t } = useI18n()
const data = ref<LegalProvisionsPreview | null>(null)
const workspace = ref<ReserveWorkspace | null>(null)
const mode = ref<'provision' | 'release'>('provision')
const selected = ref(0)
const allocationBasis = ref<'source_joint' | 'new_decision'>('source_joint')
const rows = ref<LegalCell[]>([])
const reference = ref('')
const basis = ref('')
const evidence = ref('')
const taxable = ref(false)
const confirmed = ref(false)
const reviewed = ref(false)
const busy = ref(false)
const error = ref('')
let epoch = 0
const invoice = computed(() => data.value?.items.find(row => row.invoice_id === selected.value))
const reserve = computed(() => data.value?.reserves.find(row => row.entry_id === selected.value))
function resetRows() {
  reviewed.value = false
  const cells = mode.value === 'release' ? reserve.value?.cells : allocationBasis.value === 'source_joint' ? invoice.value?.source_joint.cells : invoice.value?.existing?.allocations
  rows.value = (cells ?? []).map(row => ({ ...row, dimensions: { ...row.dimensions }, amount: mode.value === 'provision' && allocationBasis.value === 'new_decision' ? row.amount : '0.00' }))
}
watch([selected, allocationBasis], resetRows)
watch(mode, () => { selected.value = 0; resetRows() })
const signature = computed(() => JSON.stringify([props.periodId, props.rowVersion, mode.value, selected.value, allocationBasis.value, rows.value, reference.value, basis.value, evidence.value, taxable.value, confirmed.value]))
watch(signature, () => { reviewed.value = false })
watch(() => props.periodId, () => { selected.value = 0; rows.value = []; reviewed.value = false; void load(1) })
watch(() => props.rowVersion, () => { void load() })
async function load(page = data.value?.pagination.page ?? 1) {
  const scope = ++epoch
  busy.value = true; error.value = ''
  try { const [result, catalogue] = await Promise.all([api.provisions(props.periodId, page), api.ledgerWorkspace(props.periodId)]); if (scope !== epoch) return; data.value = result; workspace.value = catalogue; resetRows() }
  catch (e) { if (scope === epoch) error.value = apiErrorMessage(e, t('accounting.legal504.failed')) }
  finally { if (scope === epoch) busy.value = false }
}
void load()
const amount = computed(() => (rows.value.reduce((sum, row) => sum + Math.round(Number(row.amount || 0) * 100), 0) / 100).toFixed(2))
const decision = computed<LegalDecision>(() => ({ reference: reference.value, legal_basis: basis.value, evidence: evidence.value, taxable_activity: taxable.value, conditions_confirmed: confirmed.value,
  allocations: rows.value.filter(row => Number(row.amount) > 0).map(({ available_cents: _available, ...row }) => row),
  ...(mode.value === 'release' ? { source_entry_id: selected.value } : { allocation_basis: allocationBasis.value, ...(allocationBasis.value === 'source_joint' ? { payment_allocation: 'proportional' as const } : {}) }) }))
const valid = computed(() => selected.value > 0 && (mode.value === 'provision' ? !!invoice.value : !!reserve.value) && reference.value.trim() && basis.value.trim() && evidence.value.trim() && taxable.value && confirmed.value
  && Number.isFinite(Number(amount.value)) && rows.value.every(row => Number.isFinite(Number(row.amount)) && Number(row.amount) >= 0 && (Number(row.amount) === 0 || Object.keys(row.dimensions).length > 0)
    && ((mode.value === 'provision' && allocationBasis.value === 'new_decision') || Math.round(Number(row.amount) * 100) <= (row.available_cents ?? 0)))
  && (mode.value === 'release' ? Number(amount.value) > 0 : Number(amount.value) <= (invoice.value?.remaining ?? 0))
  && (mode.value !== 'provision' || allocationBasis.value !== 'source_joint' || invoice.value?.source_joint.available || Number(amount.value) === 0))
function dimensions(row: LegalCell, value: DimensionMap) { row.dimensions = Object.fromEntries(Object.entries(value).filter((entry): entry is [string, number] => entry[1] !== null)) }
function labels(row: LegalCell) { return Object.values(row.dimensions).map(id => workspace.value?.dimension_labels[id] ?? String(id)).join(' · ') }
async function save() {
  if (!props.canWrite || !props.isClosing || !valid.value || !reviewed.value || busy.value) return
  const scope = epoch
  busy.value = true; error.value = ''
  try {
    if (mode.value === 'release') await api.reserve(props.periodId, { rule_key: 'reserve.repairs.release', row_version: props.rowVersion, amount: amount.value, description: reference.value, legal_decision: decision.value })
    else await api.provision(props.periodId, props.rowVersion, { invoice_id: selected.value, legal_amount: amount.value, acct_amount: 0, legal_decision: decision.value })
    if (scope === epoch) { reviewed.value = false; emit('updated') }
  } catch (e) { if (scope === epoch) { reviewed.value = false; error.value = apiErrorMessage(e, t('accounting.legal504.failed')) } }
  finally { if (scope === epoch) busy.value = false }
}
async function reverse(entry: number) {
  if (!props.canWrite || !props.isClosing || busy.value) return
  const scope = epoch
  busy.value = true; error.value = ''
  try { await api.reverse(props.periodId, entry, props.rowVersion); if (scope === epoch) emit('updated') }
  catch (e) { if (scope === epoch) error.value = apiErrorMessage(e, t('accounting.legal504.failed')) }
  finally { if (scope === epoch) busy.value = false }
}
const actions = computed<ActionItem[]>(() => [
  { key: 'preview', label: t('accounting.legal504.review'), icon: 'eye', variant: 'neutral', tier: reviewed.value ? 'secondary' : 'primary', disabled: busy.value || !valid.value, run: () => { reviewed.value = true } },
  { key: 'save', label: t('accounting.legal504.confirm'), icon: 'check', variant: 'success', tier: reviewed.value ? 'primary' : 'secondary', show: props.canWrite, disabled: busy.value || !props.isClosing || !valid.value || !reviewed.value, run: save },
])
</script>

<template>
  <section class="space-y-4" data-testid="legal-provisions-504">
    <h3 class="text-lg font-semibold">{{ t('accounting.legal504.provisions_title') }}</h3>
    <p class="text-sm text-neutral-600">{{ t('accounting.legal504.provisions_hint') }}</p>
    <p v-if="error" role="alert" class="text-sm text-danger-600">{{ error }}</p>
    <fieldset :disabled="busy" class="space-y-3">
      <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm">{{ t('accounting.legal504.operation') }}<select v-model="mode" data-testid="legal-operation" class="block h-9 border rounded-md px-2"><option value="provision">{{ t('accounting.legal504.provision') }}</option><option value="release">{{ t('accounting.legal504.release') }}</option></select></label>
        <label class="text-sm">{{ t('accounting.legal504.document') }}<select v-model.number="selected" data-testid="legal-document" class="block h-9 border rounded-md px-2"><option :value="0">{{ t('accounting.legal504.choose') }}</option><template v-if="mode === 'provision'"><option v-for="item in data?.items" :key="item.invoice_id" :value="item.invoice_id">{{ item.document_no }} · {{ item.partner_name }} · {{ formatMoney(item.remaining) }}</option></template><template v-else><option v-for="item in data?.reserves" :key="item.entry_id" :value="item.entry_id">{{ item.document_no }} · {{ item.reference }}</option></template></select></label>
        <button :class="btnOutline('neutral')" @click="load()"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.cycle" /></svg>{{ t('accounting.legal504.reload') }}</button>
      </div>
      <div v-if="mode === 'provision' && data" class="flex flex-wrap gap-2"><button v-if="data.pagination.page > 1" :class="btnOutline('neutral')" @click="load(data.pagination.page - 1)"><svg class="w-4 h-4 shrink-0 rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('accounting.legal504.previous') }}</button><button v-if="data.pagination.page * data.pagination.per_page < data.pagination.total" :class="btnOutline('neutral')" @click="load(data.pagination.page + 1)"><svg class="w-4 h-4 shrink-0 -rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('accounting.legal504.next') }}</button></div>
      <template v-if="selected">
        <label v-if="mode === 'provision'" class="block text-sm">{{ t('accounting.legal504.allocation_basis') }}<select v-model="allocationBasis" data-testid="allocation-basis" class="block border rounded-md h-9 px-2"><option value="source_joint">{{ t('accounting.legal504.source_joint') }}</option><option value="new_decision">{{ t('accounting.legal504.new_decision') }}</option></select></label>
        <p v-if="mode === 'provision' && allocationBasis === 'source_joint' && !invoice?.source_joint.available" class="text-sm text-warning-700">{{ invoice?.source_joint.finding?.message || t('accounting.legal504.source_unavailable') }}</p>
        <p class="text-sm">{{ t('accounting.legal504.allocation_hint') }}</p>
        <div v-for="(row, index) in rows" :key="index" class="border rounded-lg p-3 space-y-2">
          <DimensionFields v-if="mode === 'provision' && allocationBasis === 'new_decision'" :model-value="row.dimensions" @update:model-value="dimensions(row, $event)" />
          <p v-else class="text-sm">{{ labels(row) }}</p>
          <p v-if="row.cost_center || row.project_id" class="text-sm">{{ row.cost_center }} <span v-if="row.project_id">{{ t('accounting.legal504.project', { id: row.project_id }) }}</span></p>
          <label class="text-sm">{{ t('accounting.legal504.cell_amount') }}<input v-model="row.amount" data-testid="cell-amount" type="number" min="0" step="0.01" class="block border rounded-md h-9 px-2" /></label>
          <button v-if="mode === 'provision' && allocationBasis === 'new_decision'" :class="btnOutline('danger')" @click="rows.splice(index, 1)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('accounting.legal504.remove') }}</button>
        </div>
        <button v-if="mode === 'provision' && allocationBasis === 'new_decision'" :class="btnOutline('neutral')" @click="rows.push({ dimensions: {}, amount: '0.00' })"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('accounting.legal504.add_cell') }}</button>
        <p class="font-semibold">{{ t(mode === 'provision' ? 'accounting.legal504.target_amount' : 'accounting.legal504.release_amount') }}: {{ formatMoney(Number(amount)) }}</p>
        <label class="block text-sm">{{ t('accounting.legal504.reference') }}<input v-model="reference" data-testid="legal-reference" maxlength="2000" class="block border rounded-md h-9 w-full px-2" /></label>
        <label class="block text-sm">{{ t('accounting.legal504.basis') }}<input v-model="basis" data-testid="legal-basis" maxlength="2000" class="block border rounded-md h-9 w-full px-2" /></label>
        <label class="block text-sm">{{ t('accounting.legal504.evidence') }}<textarea v-model="evidence" data-testid="legal-evidence" maxlength="2000" class="block border rounded-md w-full p-2" /></label>
        <label class="flex gap-2 text-sm"><input v-model="taxable" data-testid="legal-taxable" type="checkbox" />{{ t('accounting.legal504.taxable') }}</label>
        <label class="flex gap-2 text-sm"><input v-model="confirmed" data-testid="legal-confirmed" type="checkbox" />{{ t('accounting.legal504.legal_conditions') }}</label>
      </template>
    </fieldset>
    <div v-if="reviewed" class="rounded-lg border border-success-300 p-3" data-testid="legal-review"><p>{{ t(mode === 'release' ? 'accounting.legal504.release_review' : 'accounting.legal504.provision_review') }}</p><p v-for="(row, index) in decision.allocations" :key="index">{{ labels(row) }} · {{ formatMoney(Number(row.amount)) }}</p><p>{{ reference }} · {{ basis }}</p></div>
    <ActionBar v-if="selected" :actions="actions" />
    <div v-for="entry in workspace?.entries.filter(row => row.rule_key === 'allowance.receivable.legal.change')" :key="entry.entry_id" class="flex flex-wrap items-center gap-2"><span>{{ entry.document_no }}</span><button v-if="canWrite && isClosing" :disabled="busy" :class="btnOutline('danger')" @click="reverse(entry.entry_id)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('accounting.legal504.reverse') }}</button></div>
  </section>
</template>
