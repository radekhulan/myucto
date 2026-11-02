<script setup lang="ts">
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { api } from '@/api/client'
import type { DimensionOverview } from '@/api/dimensions'
import { jointAllocationsApi as service, jointAxes, type JointBatch, type JointCandidate, type JointCell, type JointDetail, type JointPreview, type JointPreviewInput, type JointVersion, type JointVersionInput, type ExactSourceCells } from '@/api/jointAllocations'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { journalEntryLink, journalSourceLink } from '@/utils/journalSourceLink'
import DateInput from '@/components/ui/DateInput.vue'
import JointAllocationKeyEditor from '@/components/accounting/JointAllocationKeyEditor.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { dimensionValueName } from '@/utils/dimensionValueLabel'

const { t, te } = useI18n()
const auth = useAuthStore()
const supplier = useSupplierStore()
const canWrite = computed(() => auth.canWrite('accounting'))
const versions = ref<JointVersion[]>([])
const catalog = ref<DimensionOverview | null>(null)
const rows = ref<JointCandidate[]>([])
const selected = ref<number[]>([])
const batches = ref<JointBatch[]>([])
const detail = ref<JointDetail | null>(null)
const keyVersionId = ref(0)
const targetDate = ref('')
const filter = reactive({ from: '', to: '', search: '' })
const cursor = ref<number | null>(null)
const preview = ref<JointPreview | null>(null)
const exact = ref<ExactSourceCells[]>([])
const reason = ref('')
const busy = ref(false)
const sourcesForm = ref<HTMLFormElement | null>(null)
const cancellationForm = ref<HTMLFormElement | null>(null)
const error = ref('')
const errorDetails = ref<{ type_ids?: number[]; type_id?: number; original?: string | Record<number, string>; preview?: string | Record<number, string> }>({})
const editorOpen = ref(false)
const editor = ref<InstanceType<typeof JointAllocationKeyEditor> | null>(null)
const cancelDate = ref('')
const cancelReason = ref('')
let epoch = 0
let operation = 0
let applyToken = crypto.randomUUID()
let cancelToken = crypto.randomUUID()
const chosen = computed(() => versions.value.find(v => Number(v.id) === keyVersionId.value))
const selectedRows = computed(() => rows.value.filter(row => selected.value.includes(row.id)))
const effective = computed(() => !!chosen.value && targetDate.value >= chosen.value.valid_from && targetDate.value < chosen.value.valid_to)
const canPreview = computed(() => !busy.value && effective.value && selected.value.length > 0)
const valueName = (id: number) => {
  const value = catalog.value?.values.find(row => row.id === id)
  return value
    ? dimensionValueName({ t, te }, value, catalog.value?.types.find(type => type.id === value.type_id)?.system_key, catalog.value?.values.filter(row => row.type_id === value.type_id))
    : t('jointAllocations.missingValue')
}
const typeName = (id: number) => catalog.value?.types.find(type => type.id === id)?.name ?? t('jointAllocations.additionalAxes')
const tuple = (cell: JointCell) => [...jointAxes.map(axis => valueName(cell[axis])), ...Object.values(cell.additional_dimensions ?? {}).map(valueName)].join(' · ')
const dimensionsLabel = (dimensions: Record<number, number> | [], frozen?: JointPreview) => Object.values(dimensions).map(id => {
  const value = frozen?.semantic_values[id]
  return value ? dimensionValueName({ t, te }, value, value.system_key) : valueName(id)
}).join(' · ')
const sourceLabel = (source: JointCandidate) => [source.document_no || source.entry_description || t('jointAllocations.journalEntry'), source.account_code, source.account_name].join(' · ')
const money = (amount: string, currency = 'CZK', red: boolean | 0 | 1 = false) => formatMoney(Number(amount) * (red ? -1 : 1), currency)

async function run(work: (sid: number, current: () => boolean) => Promise<void>) {
  const stamp = epoch
  const ticket = ++operation
  const sid = supplier.currentSupplierId
  if (!sid) return
  busy.value = true
  error.value = ''
  errorDetails.value = {}
  const current = () => stamp === epoch && ticket === operation && sid === supplier.currentSupplierId
  try { await work(sid, current) }
  catch (e: unknown) {
    if (current()) {
      const failure = (e as { response?: { data?: { error?: { message?: string } } } }).response?.data?.error
      error.value = failure?.message ?? t('jointAllocations.requestFailed')
      errorDetails.value = (failure ?? {}) as typeof errorDetails.value
    }
  } finally { if (current()) busy.value = false }
}
async function load() {
  await run(async (sid, current) => {
    const [keys, overview, history] = await Promise.all([service.keys(sid), api.get<DimensionOverview>('/accounting/dimensions', { headers: { 'X-Supplier-Id': String(sid) } }), service.batches(sid)])
    if (current()) { versions.value = keys; catalog.value = overview.data; batches.value = history }
  })
}
function invalidate() { preview.value = null; applyToken = crypto.randomUUID() }
watch([keyVersionId, targetDate, exact, selected, reason], invalidate, { deep: true, flush: 'sync' })
watch([keyVersionId, selected], ([versionId], [previousVersionId]) => {
  const retained = versionId === previousVersionId ? new Map(exact.value.map(source => [source.source_line_id, source])) : new Map<number, ExactSourceCells>()
  exact.value = chosen.value?.allocation_mode === 'explicit_amounts' ? selectedRows.value.map(source => retained.get(source.id) ?? ({ source_line_id: source.id, evidence: '',
    cells: chosen.value!.cell_ids.map(cell_id => ({ cell_id, amount: '', amount_foreign: source.amount_foreign === null ? null : '' })) })) : []
}, { deep: true })
watch(() => supplier.currentSupplierId, () => {
  epoch++; operation++; busy.value = false
  versions.value = []; catalog.value = null; rows.value = []; selected.value = []; batches.value = []; detail.value = null
  keyVersionId.value = 0; targetDate.value = ''; filter.from = ''; filter.to = ''; filter.search = ''
  exact.value = []; reason.value = ''; preview.value = null; error.value = ''; editorOpen.value = false
  cancelDate.value = ''; cancelReason.value = ''; cursor.value = null
  void load()
}, { immediate: true })
watch([cancelDate, cancelReason], () => { cancelToken = crypto.randomUUID() })
async function findSources(more = false) {
  if (busy.value || !sourcesForm.value?.reportValidity()) return
  await run(async (sid, current) => {
    const result = await service.candidates(sid, { target_date: targetDate.value, from: filter.from || '1900-01-01', to: filter.to || targetDate.value,
      search: filter.search, ...(more && cursor.value ? { before_id: cursor.value } : {}) })
    if (current()) {
      rows.value = more ? [...rows.value, ...result.items] : result.items
      if (!more) selected.value = []
      cursor.value = result.next_before_id
    }
  })
}
function input(): JointPreviewInput {
  return { key_version_id: keyVersionId.value, target_date: targetDate.value, source_line_ids: [...selected.value], exact_cells: JSON.parse(JSON.stringify(exact.value)) as ExactSourceCells[] }
}
async function makePreview() {
  if (!canPreview.value || !sourcesForm.value?.reportValidity()) return
  invalidate()
  const body = input()
  await run(async (sid, current) => { const result = await service.preview(sid, body); if (current()) preview.value = result })
}
async function apply() {
  if (busy.value || !preview.value || !canWrite.value || !sourcesForm.value?.reportValidity()) return
  const body = { ...input(), preview_hash: preview.value.preview_hash, reason: reason.value, idempotency_key: applyToken }
  await run(async (sid, current) => {
    const result = await service.apply(sid, body)
    const saved = await service.detail(sid, result.id)
    if (current()) { preview.value = null; selected.value = []; rows.value = []; detail.value = saved }
    const history = await service.batches(sid)
    if (current()) batches.value = history
  })
}
async function saveKey(data: { keyId: number; code: string; name: string; version: JointVersionInput }) {
  if (busy.value || !canWrite.value || !editor.value?.validate()) return
  await run(async (sid, current) => {
    if (!canWrite.value) return
    if (data.keyId) await service.createVersion(sid, data.keyId, data.version)
    else await service.createKey(sid, data.code, data.name, data.version)
    const keys = await service.keys(sid)
    if (current()) { versions.value = keys; editorOpen.value = false; invalidate() }
  })
}
async function copyKey() { editorOpen.value = true; await nextTick(); if (chosen.value) editor.value?.copy(chosen.value) }
async function openBatch(batch: JointBatch) {
  await run(async (sid, current) => { const result = await service.detail(sid, batch.id); if (current()) { detail.value = result; cancelDate.value = ''; cancelReason.value = '' } })
}
async function cancel() {
  if (busy.value || !detail.value || !canWrite.value || !cancellationForm.value?.reportValidity()) return
  const id = detail.value.id
  const body = { target_date: cancelDate.value, reason: cancelReason.value, idempotency_key: cancelToken }
  await run(async (sid, current) => {
    await service.cancel(sid, id, body)
    const [result, history] = await Promise.all([service.detail(sid, id), service.batches(sid)])
    if (current()) { detail.value = result; batches.value = history; rows.value = []; selected.value = []; invalidate() }
  })
}
async function moreHistory() {
  const last = batches.value.at(-1)
  if (!last) return
  await run(async (sid, current) => { const result = await service.batches(sid, last.id); if (current()) batches.value.push(...result) })
}
</script>

<template>
  <div class="p-4 sm:p-6 space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('jointAllocations.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('jointAllocations.intro') }}</p>
      </div>
      <button v-if="canWrite" :class="btnOutline('primary')" :disabled="busy" @click="editorOpen = !editorOpen"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('jointAllocations.keyEditor') }}</button>
    </div>
    <div v-if="error" role="alert" class="rounded border border-danger-200 bg-danger-50 p-3 space-y-2">
      <p>{{ error }}</p>
      <p v-if="errorDetails.type_ids">{{ errorDetails.type_ids.map(typeName).join(', ') }}</p>
      <p v-if="errorDetails.type_id">{{ typeName(errorDetails.type_id) }}</p>
      <p v-if="typeof errorDetails.original === 'string'" class="text-sm">{{ errorDetails.original }} → {{ errorDetails.preview }}</p>
      <div v-else-if="errorDetails.original" class="text-sm"><p v-for="(amount, id) in errorDetails.original" :key="id">{{ valueName(Number(id)) }}: {{ amount }} → {{ typeof errorDetails.preview === 'object' ? errorDetails.preview[Number(id)] : '' }}</p></div>
      <button v-if="canWrite && chosen" :class="btnOutline('warning')" @click="copyKey"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.copy" /></svg>{{ t('jointAllocations.resolveWithKey') }}</button>
    </div>
    <p v-if="busy" role="status" class="text-sm text-neutral-500">{{ t('jointAllocations.loading') }}</p>
    <JointAllocationKeyEditor v-if="editorOpen && canWrite" ref="editor" :key="supplier.currentSupplierId" :types="catalog?.types ?? []" :values="catalog?.values ?? []" :versions="versions" :busy="busy" :supplier-id="supplier.currentSupplierId" @save="saveKey" @close="editorOpen = false" />
    <section class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-4">
      <h2 class="font-semibold">{{ t('jointAllocations.sources') }}</h2>
      <form ref="sourcesForm" class="flex flex-wrap items-end gap-3" @submit.prevent="findSources()">
        <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.version') }}<select v-model="keyVersionId" :disabled="busy" class="h-9 max-w-full rounded border border-neutral-300 bg-surface px-2"><option :value="0">{{ t('jointAllocations.choose') }}</option><option v-for="version in versions" :key="version.id" :value="Number(version.id)">{{ version.name }} · {{ version.version_no }} · {{ formatDate(version.valid_from) }} / {{ formatDate(version.valid_to) }}</option></select></label>
        <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.targetDate') }}<DateInput v-model="targetDate" required :disabled="busy" class="h-9 rounded border border-neutral-300 px-2" /></label>
        <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.from') }}<DateInput v-model="filter.from" :disabled="busy" class="h-9 rounded border border-neutral-300 px-2" /></label>
        <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.to') }}<DateInput v-model="filter.to" :disabled="busy" class="h-9 rounded border border-neutral-300 px-2" /></label>
        <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.search') }}<input v-model="filter.search" :disabled="busy" class="h-9 rounded border border-neutral-300 bg-surface px-2" /></label>
        <button type="submit" :class="btnOutline('primary')" :disabled="busy || !targetDate"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.search" /></svg>{{ t('jointAllocations.findSources') }}</button>
      </form>
      <p v-if="!targetDate" class="text-xs text-warning-700">{{ t('jointAllocations.chooseDate') }}</p>
      <p v-if="chosen" class="text-sm text-neutral-500">{{ chosen.explanation }} · {{ t(chosen.allocation_mode === 'weighted' ? 'jointAllocations.weighted' : 'jointAllocations.explicit') }}</p>
      <p v-if="!rows.length" class="text-sm text-neutral-500">{{ t('jointAllocations.noSources') }}</p>
      <label v-for="row in rows" :key="row.id" class="flex items-start gap-3 rounded border border-neutral-200 p-3">
        <input v-model="selected" type="checkbox" :value="row.id" :disabled="busy || (!selected.includes(row.id) && selected.length >= 200)" class="mt-1" />
        <span class="min-w-0 flex-1 space-y-1"><span class="block font-medium">{{ sourceLabel(row) }}</span>
          <span class="block text-sm">{{ formatDate(row.source_date) }} · {{ money(row.amount, 'CZK', !!row.is_red_storno) }}<template v-if="row.amount_foreign !== null"> · {{ money(row.amount_foreign, row.currency_code ?? 'CZK', !!row.is_red_storno) }}</template></span>
          <span v-for="(shares, typeId) in row.dimension_splits" :key="typeId" class="block text-xs text-neutral-500">{{ typeName(Number(typeId)) }}: {{ Object.entries(shares).map(([id, share]) => `${valueName(Number(id))} ${Number(share) * 100}%`).join(' · ') }}</span>
          <RouterLink :to="journalSourceLink(row) ?? journalEntryLink(row.entry_id)" class="text-sm text-primary-600 underline" @click.stop>{{ t('jointAllocations.openSource') }}</RouterLink>
        </span>
      </label>
      <button v-if="cursor" :class="btnOutline('neutral')" :disabled="busy" @click="findSources(true)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('jointAllocations.more') }}</button>
    </section>
    <section v-if="exact.length && chosen" class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-4">
      <h2 class="font-semibold">{{ t('jointAllocations.exactCells') }}</h2><p class="text-sm text-neutral-500">{{ t('jointAllocations.exactHelp') }}</p>
      <div v-for="source in exact" :key="source.source_line_id" class="space-y-3 border-t border-neutral-200 pt-3">
        <h3 class="font-medium">{{ selectedRows.find(row => row.id === source.source_line_id) ? sourceLabel(selectedRows.find(row => row.id === source.source_line_id)!) : '' }}</h3>
        <label class="block text-sm">{{ t('jointAllocations.evidence') }}<textarea v-model="source.evidence" :disabled="busy" maxlength="4000" class="mt-1 w-full rounded border border-neutral-300 bg-surface p-2" /></label>
        <div v-for="(cell, index) in source.cells" :key="cell.cell_id" class="flex flex-wrap items-end gap-3">
          <p class="min-w-48 flex-1 text-sm">{{ chosen.cells[index] ? tuple(chosen.cells[index]) : '' }}</p>
          <label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.amountCzk') }}<input v-model="cell.amount" :disabled="busy" inputmode="decimal" class="h-9 w-32 rounded border border-neutral-300 bg-surface px-2" /></label>
          <label v-if="cell.amount_foreign !== null" class="flex flex-col gap-1 text-sm">{{ selectedRows.find(row => row.id === source.source_line_id)?.currency_code }}<input v-model="cell.amount_foreign" :disabled="busy" inputmode="decimal" class="h-9 w-32 rounded border border-neutral-300 bg-surface px-2" /></label>
        </div>
      </div>
    </section>
    <section v-if="selected.length" class="space-y-3">
      <label class="block text-sm">{{ t('jointAllocations.reason') }}<textarea v-model="reason" :disabled="busy" maxlength="4000" class="mt-1 w-full rounded border border-neutral-300 bg-surface p-2" /></label>
      <button :class="btnFilled('primary')" :disabled="!canPreview" @click="makePreview"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.eye" /></svg>{{ t('jointAllocations.preview') }}</button>
      <p v-if="!effective" class="text-xs text-warning-700">{{ t('jointAllocations.keyDateRequired') }}</p>
    </section>
    <section v-if="preview" class="rounded-lg border border-primary-200 bg-surface p-4 space-y-3">
      <h2 class="font-semibold">{{ t('jointAllocations.preview') }} · {{ formatDate(preview.target_date) }}</h2>
      <p class="text-sm text-neutral-500">{{ t('jointAllocations.previewHelp') }}</p>
      <div v-for="source in preview.plan.sources" :key="source.source_line_id" class="space-y-2 border-t border-neutral-200 pt-3">
        <p class="font-medium">{{ preview.source_lines.find(row => row.id === source.source_line_id) ? sourceLabel(preview.source_lines.find(row => row.id === source.source_line_id)!) : '' }}</p>
        <p class="text-sm">{{ t('jointAllocations.compensation') }}: {{ money(source.compensation.amount, 'CZK', source.compensation.is_red_storno) }} · {{ t(`jointAllocations.sides.${source.compensation.side}`) }}</p>
        <p v-for="(target, index) in source.targets" :key="index" class="text-sm">{{ dimensionsLabel(target.dimensions, preview) }}: {{ money(target.amount, 'CZK', target.is_red_storno) }}<template v-if="target.amount_foreign !== null"> · {{ money(target.amount_foreign, target.currency_code ?? 'CZK', target.is_red_storno) }}</template></p>
      </div>
      <button v-if="canWrite" :class="btnFilled('success')" :disabled="busy || !reason.trim()" @click="apply"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.check" /></svg>{{ t('jointAllocations.apply') }}</button>
      <p v-if="!reason.trim()" class="text-xs text-warning-700">{{ t('jointAllocations.reasonRequired') }}</p>
    </section>
    <section class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-3">
      <h2 class="font-semibold">{{ t('jointAllocations.history') }}</h2>
      <p v-if="!batches.length" class="text-sm text-neutral-500">{{ t('jointAllocations.noHistory') }}</p>
      <div v-for="batch in batches" :key="batch.id" class="flex flex-wrap items-center gap-3 border-t border-neutral-100 pt-3"><span class="flex-1">{{ formatDate(batch.target_date) }} · {{ batch.reason }} · {{ t(`jointAllocations.status.${batch.status}`) }}</span><button :class="btnOutline('neutral')" :disabled="busy" @click="openBatch(batch)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.eye" /></svg>{{ t('jointAllocations.detail') }}</button></div>
      <button v-if="batches.length >= 100" :class="btnOutline('neutral')" :disabled="busy" @click="moreHistory"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" /></svg>{{ t('jointAllocations.more') }}</button>
    </section>
    <section v-if="detail" class="rounded-lg border border-neutral-200 bg-surface p-4 space-y-3">
      <h2 class="font-semibold">{{ t('jointAllocations.detail') }} · {{ formatDate(detail.target_date) }}</h2><p>{{ detail.reason }}</p>
      <p class="text-sm">{{ detail.snapshot.key.name }} · {{ detail.snapshot.key.version_no }} · {{ detail.snapshot.key.explanation }}</p>
      <RouterLink :to="journalEntryLink(detail.journal_entry_id)" class="text-primary-600 underline">{{ t('jointAllocations.openJournal') }}</RouterLink>
      <div v-for="source in detail.snapshot.source_lines" :key="source.id" class="space-y-1 text-sm">
        <RouterLink :to="journalEntryLink(source.entry_id)" class="text-primary-600 underline">{{ sourceLabel(source) }} · {{ formatDate(source.source_date) }}</RouterLink>
        <p v-if="detail.snapshot.exact_cells?.[source.id]">{{ detail.snapshot.exact_cells[source.id]?.evidence }}</p>
      </div>
      <div v-for="source in detail.snapshot.plan.sources" :key="source.source_line_id" class="border-t border-neutral-200 pt-3 space-y-1"><p v-for="(target, index) in source.targets" :key="index" class="text-sm">{{ dimensionsLabel(target.dimensions, detail.snapshot) }}: {{ money(target.amount, 'CZK', target.is_red_storno) }}<template v-if="target.amount_foreign !== null"> · {{ money(target.amount_foreign, target.currency_code ?? 'CZK', target.is_red_storno) }}</template></p></div>
      <p v-if="detail.cancellation" class="text-sm">{{ t('jointAllocations.cancelledOn') }} {{ formatDate(detail.cancellation.target_date) }} · {{ detail.cancellation.reason }}</p>
      <p v-for="(event, index) in detail.audit" :key="index" class="text-xs text-neutral-500">{{ formatDate(event.created_at) }} · {{ t(`jointAllocations.actions.${event.action}`) }} · {{ t(`jointAllocations.actors.${event.actor_source}`) }}</p>
      <form v-if="canWrite && !detail.cancellation" ref="cancellationForm" class="space-y-3 border-t border-neutral-200 pt-3" @submit.prevent="cancel">
        <p class="text-sm text-warning-700">{{ t('jointAllocations.cancelHelp') }}</p>
        <div class="flex flex-wrap items-end gap-3"><label class="flex flex-col gap-1 text-sm">{{ t('jointAllocations.cancelDate') }}<DateInput v-model="cancelDate" required :disabled="busy" :min="detail.target_date" class="h-9 rounded border border-neutral-300 px-2" /></label>
          <label class="flex flex-1 flex-col gap-1 text-sm">{{ t('jointAllocations.reason') }}<input v-model="cancelReason" required :disabled="busy" maxlength="4000" class="h-9 rounded border border-neutral-300 bg-surface px-2" /></label>
          <button type="submit" :class="btnFilled('danger')" :disabled="busy || !cancelDate || !cancelReason.trim()"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.x" /></svg>{{ t('jointAllocations.cancel') }}</button></div>
      </form>
    </section>
  </div>
</template>
