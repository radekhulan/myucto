<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { legalClosing504Api as api, type ReserveSource, type ReserveWorkspace, type ReservePreview, type CorrectionRequest } from '@/api/legalClosing504'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney, formatDate } from '@/composables/useFormat'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
const props = defineProps<{ periodId: number; rowVersion: number; canWrite: boolean; isClosing: boolean }>()
const emit = defineEmits<{ updated: [] }>()
const { t } = useI18n()
const workspace = ref<ReserveWorkspace | null>(null)
const assets = ref<ReserveWorkspace['assets']>([])
const assetId = ref(0)
const search = ref('')
const lineSearch = ref('')
const sourceMode = ref<ReserveSource['mode']>('capitalization')
const operation = ref<'create' | 'same_period' | 'prospective_closed_history'>('create')
const continued = ref('')
const associationReference = ref('')
const associationEvidence = ref('')
const contributions = ref<Record<number, string>>({})
const reference = ref('')
const basis = ref('')
const evidence = ref('')
const confirmed = ref(false)
const taxable = ref(false)
const amount = ref('')
const description = ref('')
const reason = ref('')
const originalDate = ref('')
const originalReference = ref('')
const originalEvidence = ref('')
const originalConfirmed = ref(false)
const preview = ref<ReservePreview | null>(null)
const busy = ref(false)
const error = ref('')
let generation = 0
const source = computed<ReserveSource>(() => ({ asset_id: assetId.value, mode: sourceMode.value, allocation_method: 'capitalization_joint_proportional', continued_use_evidence: continued.value,
  ...(sourceMode.value === 'documented_association' ? { association: { reference: associationReference.value, evidence: associationEvidence.value,
    contributions: Object.entries(contributions.value).filter(([, value]) => Number(value) > 0).map(([id, value]) => ({ line_id: Number(id), contribution_cents: Math.round(Number(value) * 100) })) } } : {}) }))
const correction = computed<CorrectionRequest>(() => ({ mode: operation.value === 'prospective_closed_history' ? 'prospective_closed_history' : 'same_period', previous_hash: workspace.value?.state.current?.source_hash ?? '', reason: reason.value, reserve_source: source.value,
  ...(operation.value === 'prospective_closed_history' ? { original_purpose: { same_original_asset_and_purpose: originalConfirmed.value, document_date: originalDate.value, reference: originalReference.value, evidence: originalEvidence.value } } : {}) }))
const fingerprint = computed(() => JSON.stringify([props.periodId, props.rowVersion, operation.value, source.value, correction.value, amount.value, reference.value, basis.value, evidence.value, confirmed.value, taxable.value, description.value]))
watch(fingerprint, () => { preview.value = null })
watch(() => props.periodId, () => { ++generation; assetId.value = 0; assets.value = []; workspace.value = null; contributions.value = {}; preview.value = null; void searchAssets() })
watch(assetId, () => { contributions.value = {}; preview.value = null; if (assetId.value) void loadWorkspace(); else workspace.value = null })
watch(() => props.rowVersion, () => { preview.value = null; void loadWorkspace() })
async function act(fn: () => Promise<void>) {
  if (busy.value) return
  const scope = generation
  busy.value = true; error.value = ''
  try { await fn() } catch (e) { if (scope === generation) { preview.value = null; error.value = apiErrorMessage(e, t('accounting.legal504.failed')) } }
  finally { if (scope === generation) busy.value = false }
}
async function searchAssets() {
  const scope = ++generation
  busy.value = false
  await act(async () => { const result = await api.workspace(props.periodId, { q: search.value }); if (scope !== generation) return; assets.value = result.assets; if (!assetId.value) workspace.value = result })
}
async function loadWorkspace(page = 1) {
  const scope = ++generation
  busy.value = false
  await act(async () => { const result = await api.workspace(props.periodId, { asset_id: assetId.value || undefined, line_q: lineSearch.value, page }); if (scope !== generation) return; workspace.value = result })
}
void searchAssets()
const validSource = computed(() => assetId.value > 0 && continued.value.trim() && (sourceMode.value === 'capitalization' || (associationReference.value.trim() && associationEvidence.value.trim() && source.value.association!.contributions.length > 0)))
const valid = computed(() => validSource.value && (operation.value === 'create'
  ? Number(amount.value) > 0 && reference.value.trim() && basis.value.trim() && evidence.value.trim() && description.value.trim() && confirmed.value && taxable.value
  : workspace.value?.state.current && reason.value.trim() && (operation.value === 'same_period' || (originalDate.value && originalReference.value.trim() && originalEvidence.value.trim() && originalConfirmed.value))))
function showPreview() {
  const key = fingerprint.value
  preview.value = null
  return act(async () => { const result = operation.value === 'create' ? await api.preview(props.periodId, source.value, amount.value) : await api.correctionPreview(props.periodId, correction.value); if (key === fingerprint.value) preview.value = result })
}
function save() {
  if (!props.canWrite || !props.isClosing || !valid.value || !preview.value) return
  const scope = generation
  const receipt = preview.value.source
  const request = structuredClone(source.value)
  request.source_hash = receipt.source_hash
  return act(async () => {
    if (operation.value === 'create') await api.reserve(props.periodId, { rule_key: 'reserve.repairs.create', amount: amount.value, description: description.value, row_version: props.rowVersion,
      legal_decision: { reference: reference.value, legal_basis: basis.value, evidence: evidence.value, taxable_activity: taxable.value, conditions_confirmed: confirmed.value, reserve_source: request } })
    else await api.correct(props.periodId, { ...correction.value, reserve_source: request, row_version: props.rowVersion })
    if (scope === generation) { preview.value = null; emit('updated') }
  })
}
function reverse(entry: number) {
  if (!props.canWrite || !props.isClosing) return
  const scope = generation
  return act(async () => { await api.reverse(props.periodId, entry, props.rowVersion); if (scope === generation) { preview.value = null; emit('updated') } })
}
const actions = computed<ActionItem[]>(() => [
  { key: 'preview', label: t('accounting.legal504.preview'), icon: 'eye', tier: preview.value ? 'secondary' : 'primary', variant: 'neutral', disabled: busy.value || !valid.value, run: showPreview },
  { key: 'save', label: t('accounting.legal504.confirm'), icon: 'check', tier: preview.value ? 'primary' : 'secondary', variant: 'success', show: props.canWrite, disabled: busy.value || !props.isClosing || !preview.value || !valid.value, run: save },
])
function labels(dimensions: Record<number, number>) { return Object.values(dimensions).map(id => workspace.value?.dimension_labels[id] ?? String(id)).join(' · ') }
</script>

<template>
  <section class="space-y-4" data-testid="asset-reserve-504">
    <h3 class="text-lg font-semibold">{{ t('accounting.legal504.asset_title') }}</h3>
    <p class="text-sm text-neutral-600">{{ t('accounting.legal504.asset_hint') }}</p>
    <p v-if="!isClosing" class="text-sm text-warning-700">{{ t('accounting.legal504.needs_closing') }}</p>
    <p v-if="error" role="alert" class="text-sm text-danger-600">{{ error }}</p>
    <div class="flex flex-wrap items-end gap-2">
      <label class="text-sm">{{ t('accounting.legal504.search_asset') }}<input v-model="search" class="block border rounded-md px-2 h-9" /></label>
      <button :class="btnOutline('neutral')" :disabled="busy" @click="searchAssets"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.search" /></svg>{{ t('accounting.legal504.search') }}</button>
      <label class="text-sm">{{ t('accounting.legal504.asset') }}<select v-model.number="assetId" data-testid="asset-select" class="block border rounded-md h-9 px-2"><option :value="0">{{ t('accounting.legal504.choose') }}</option><option v-for="asset in assets" :key="asset.id" :value="asset.id">{{ asset.inventory_number }} · {{ asset.name }}</option></select></label>
    </div>
    <p v-if="workspace?.more_assets" class="text-sm">{{ t('accounting.legal504.narrow_search') }}</p>
    <fieldset v-if="assetId" :disabled="busy" class="space-y-3">
      <label class="block text-sm">{{ t('accounting.legal504.operation') }}<select v-model="operation" data-testid="reserve-operation" class="block border rounded-md h-9 px-2"><option value="create">{{ t('accounting.legal504.create') }}</option><option v-if="workspace?.state.current" value="same_period">{{ t('accounting.legal504.v1') }}</option><option v-if="workspace?.state.current" value="prospective_closed_history">{{ t('accounting.legal504.v2') }}</option></select></label>
      <p v-if="operation !== 'create'" class="text-sm text-neutral-600">{{ t(operation === 'same_period' ? 'accounting.legal504.v1_hint' : 'accounting.legal504.v2_hint') }}</p>
      <label class="block text-sm">{{ t('accounting.legal504.source_mode') }}<select v-model="sourceMode" data-testid="source-mode" class="block border rounded-md h-9 px-2"><option value="capitalization">{{ t('accounting.legal504.capitalization') }}</option><option value="documented_association">{{ t('accounting.legal504.association') }}</option></select></label>
      <p class="text-sm text-neutral-600">{{ t('accounting.legal504.capitalization_hint') }}</p>
      <label class="block text-sm">{{ t('accounting.legal504.continued') }}<textarea v-model="continued" data-testid="continued" maxlength="2000" class="block border rounded-md w-full p-2" /></label>
      <template v-if="sourceMode === 'documented_association'">
        <label class="block text-sm">{{ t('accounting.legal504.association_reference') }}<input v-model="associationReference" maxlength="2000" class="block border rounded-md h-9 w-full px-2" /></label>
        <label class="block text-sm">{{ t('accounting.legal504.association_evidence') }}<textarea v-model="associationEvidence" maxlength="2000" class="block border rounded-md w-full p-2" /></label>
        <div class="flex flex-wrap items-end gap-2"><label class="text-sm">{{ t('accounting.legal504.search_lines') }}<input v-model="lineSearch" class="block border rounded-md h-9 px-2" /></label><button :class="btnOutline('neutral')" @click="loadWorkspace()"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.search" /></svg>{{ t('accounting.legal504.search') }}</button></div>
        <p class="text-sm">{{ t('accounting.legal504.contribution_hint') }}</p>
        <div v-for="line in workspace?.lines" :key="line.line_id" class="flex flex-wrap items-center gap-2 border rounded-md p-2"><span class="grow text-sm">{{ line.document_no }} · {{ formatDate(line.entry_date) }} · {{ line.description }} · {{ formatMoney(Number(line.amount)) }}</span><label class="text-sm">{{ t('accounting.legal504.contribution') }}<input v-model="contributions[line.line_id]" type="number" min="0" step="0.01" :max="line.amount" class="block border rounded-md h-9 w-36 px-2" /></label></div>
        <div class="flex flex-wrap gap-2"><button v-if="workspace && workspace.line_page > 1" :class="btnOutline('neutral')" @click="loadWorkspace(workspace.line_page - 1)"><svg class="w-4 h-4 shrink-0 rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('accounting.legal504.previous') }}</button><button v-if="workspace?.more_lines" :class="btnOutline('neutral')" @click="loadWorkspace(workspace.line_page + 1)"><svg class="w-4 h-4 shrink-0 -rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('accounting.legal504.next') }}</button></div>
        <p class="text-sm">{{ t('accounting.legal504.selected_amount') }}: {{ formatMoney(Object.values(contributions).reduce((sum, value) => sum + Number(value || 0), 0)) }}</p>
      </template>
      <div v-if="operation === 'create'" class="space-y-3">
        <label class="block text-sm">{{ t('accounting.legal504.amount') }}<input v-model="amount" data-testid="reserve-amount" type="number" min="0.01" step="0.01" class="block border rounded-md h-9 px-2" /></label>
        <label class="block text-sm">{{ t('accounting.legal504.description') }}<input v-model="description" data-testid="reserve-description" class="block border rounded-md h-9 w-full px-2" /></label>
        <label class="block text-sm">{{ t('accounting.legal504.reference') }}<input v-model="reference" data-testid="legal-reference" maxlength="2000" class="block border rounded-md h-9 w-full px-2" /></label>
        <label class="block text-sm">{{ t('accounting.legal504.basis') }}<input v-model="basis" data-testid="legal-basis" maxlength="2000" class="block border rounded-md h-9 w-full px-2" /></label>
        <label class="block text-sm">{{ t('accounting.legal504.evidence') }}<textarea v-model="evidence" data-testid="legal-evidence" maxlength="2000" class="block border rounded-md w-full p-2" /></label>
        <label class="flex items-start gap-2 text-sm"><input v-model="taxable" data-testid="legal-taxable" type="checkbox" />{{ t('accounting.legal504.taxable') }}</label>
        <label class="flex items-start gap-2 text-sm"><input v-model="confirmed" data-testid="legal-confirmed" type="checkbox" />{{ t('accounting.legal504.conditions') }}</label>
      </div>
      <template v-else>
        <label class="block text-sm">{{ t('accounting.legal504.reason') }}<textarea v-model="reason" data-testid="correction-reason" maxlength="2000" class="block border rounded-md w-full p-2" /></label>
        <div v-if="operation === 'prospective_closed_history'" class="space-y-3">
          <label class="block text-sm">{{ t('accounting.legal504.original_date') }}<DateInput v-model="originalDate" /></label>
          <label class="block text-sm">{{ t('accounting.legal504.original_reference') }}<input v-model="originalReference" maxlength="2000" class="block border rounded-md h-9 w-full px-2" /></label>
          <label class="block text-sm">{{ t('accounting.legal504.original_evidence') }}<textarea v-model="originalEvidence" maxlength="2000" class="block border rounded-md w-full p-2" /></label>
          <label class="flex items-start gap-2 text-sm"><input v-model="originalConfirmed" type="checkbox" />{{ t('accounting.legal504.original_confirmed') }}</label>
        </div>
      </template>
    </fieldset>
    <div v-if="preview" class="space-y-2 rounded-lg border border-success-300 p-3" data-testid="reserve-preview">
      <h4 class="font-semibold">{{ t('accounting.legal504.preview') }}</h4>
      <p class="text-sm font-medium">{{ t('accounting.legal504.source_cells') }}</p>
      <p v-for="(cell, index) in preview.source.cells" :key="`source-${index}`" class="text-sm">{{ labels(cell.dimensions) }} · {{ formatMoney(cell.weight_cents / 100) }} · {{ cell.cost_center }} <span v-if="cell.project_id">{{ t('accounting.legal504.project', { id: cell.project_id }) }}</span></p>
      <p v-if="preview.remaining_cents !== undefined">{{ t('accounting.legal504.remaining') }}: {{ formatMoney(preview.remaining_cents / 100) }}</p>
      <p v-for="(cell, index) in preview.allocations ?? preview.lines ?? []" :key="index" class="text-sm">{{ labels(cell.dimensions) }} · {{ formatMoney(Number(cell.amount)) }} · {{ cell.cost_center }} <span v-if="cell.project_id">{{ t('accounting.legal504.project', { id: cell.project_id }) }}</span><span v-if="'account_code' in cell && 'side' in cell"> · {{ cell.account_code }} {{ t(cell.side === 'debit' ? 'accounting.legal504.debit' : 'accounting.legal504.credit') }}</span></p>
    </div>
    <ActionBar v-if="assetId" :actions="actions" />
    <div v-if="workspace?.entries.length" class="space-y-2 rounded-lg border p-3">
      <h4 class="font-semibold">{{ t('accounting.legal504.current_entries') }}</h4>
      <div v-for="entry in workspace.entries.filter(row => row.rule_key.startsWith('reserve.'))" :key="entry.entry_id" class="flex flex-wrap items-center gap-2 text-sm">
        <span>{{ entry.document_no }} · {{ entry.description }} · {{ formatMoney(entry.amount ?? 0) }}</span>
        <button v-if="canWrite && isClosing" :disabled="busy" :class="btnOutline('danger')" @click="reverse(entry.entry_id)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('accounting.legal504.reverse') }}</button>
      </div>
    </div>
    <div v-if="workspace?.state.current" class="space-y-2 border rounded-lg p-3">
      <h4 class="font-semibold">{{ t('accounting.legal504.history') }}</h4>
      <p v-for="revision in workspace.state.revisions" :key="revision.revision_number" class="text-sm">{{ formatDate(revision.effective_on) }} · {{ revision.reason }}</p>
      <div v-for="entry in workspace.state.blocking_entries" :key="entry.entry_id" class="flex flex-wrap items-center gap-2 text-sm"><span>{{ t('accounting.legal504.linked_entry', { id: entry.entry_id, date: formatDate(entry.entry_date) }) }}</span><button v-if="operation === 'same_period' && entry.period_id === periodId && canWrite && isClosing" :disabled="busy" :class="btnOutline('danger')" @click="reverse(entry.entry_id)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('accounting.legal504.reverse') }}</button></div>
    </div>
  </section>
</template>
