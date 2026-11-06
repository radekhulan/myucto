<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyPaymentsApi, type ClearingRevisionPreview, type ClearingRevisionRequest, type PaymentSourceDetails, type PaymentWorkflowContext } from '@/api/propertyPayments'
import DateInput from '@/components/ui/DateInput.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import PropertyButton from '@/components/property/PropertyButton.vue'
import { paymentCents, paymentDecimal } from './paymentWorkflowForm'
import { propertyDateInput as dateInput, propertyInput as inputClass } from '@/components/property/propertyFormStyles'
const props = defineProps<{ supplier: number; source: PaymentSourceDetails; dimensions: PaymentWorkflowContext['dimensions']; canManage: boolean; canPost: boolean }>()
const emit = defineEmits<{ changed: []; busy: [value: boolean] }>()
const { t } = useI18n()
const open = ref(false), busy = ref(false), error = ref('')
const date = ref(''), at = ref(''), reason = ref(''), evidence = ref('')
const moves = ref<Array<{ from: string; amount: string; key: string; dimensions: Record<number, number> }>>([])
const preview = ref<ClearingRevisionPreview | null>(null)
const form = ref<HTMLFormElement | null>(null)
const axes = computed(() => [...new Map(props.dimensions.map(v => [v.type_id, { id: v.type_id, name: v.type_name }])).values()])
const available = computed(() => props.source.cells.filter(c => BigInt(c.available_cents) > 0n))
let epoch = 0, token = crypto.randomUUID()
let submitted: ClearingRevisionRequest | null = null
onBeforeUnmount(() => { epoch++; emit('busy', false) })
function invalidate() { preview.value = null; submitted = null; token = crypto.randomUUID() }
watch([date, at, reason, evidence, moves], invalidate, { deep: true, flush: 'sync' })
watch(() => [props.supplier, props.source], () => { epoch++; open.value = false; moves.value = []; busy.value = false; emit('busy', false); invalidate() }, { deep: true })
function add() { moves.value.push({ from: '', amount: '', key: `revision.${crypto.randomUUID()}`, dimensions: {} }) }
function begin() { if (busy.value || !props.canManage) return; open.value = true; if (!moves.value.length) add() }
function request(): ClearingRevisionRequest {
  if (props.source.source_id === null || props.source.native) throw new Error(t('propertyPayments.failed'))
  if (!date.value || !at.value || !reason.value.trim() || !evidence.value.trim() || !moves.value.length) throw new Error('required')
  const used = new Map<string, bigint>()
  const normalized = moves.value.map(move => {
    const cell = available.value.find(c => c.key === move.from)
    const cents = paymentCents(move.amount)
    const sum = (used.get(move.from) ?? 0n) + BigInt(cents); used.set(move.from, sum)
    if (!cell || sum > BigInt(cell.available_cents)) throw new Error('capacity')
    return { from_key: cell.key, amount_cents: cents, to: { key: move.key, dimensions: Object.fromEntries(Object.entries(move.dimensions).filter(([, value]) => value > 0)) } }
  })
  return { source_id: props.source.source_id, expected_source_version: props.source.source_version, expected_revision_id: props.source.revision_id,
    accounting_date: date.value, effective_at: at.value, reason: reason.value, evidence: evidence.value, moves: normalized }
}
function failure(e: unknown) { return (e as { response?: { data?: { error?: { message?: string } } } }).response?.data?.error?.message ?? t('propertyPayments.failed') }
async function showPreview() {
  if (busy.value || !props.canManage || !form.value?.reportValidity()) return
  let payload: ClearingRevisionRequest
  try { payload = request() } catch { error.value = t('propertyPayments.revisionInvalid'); return }
  const ticket = epoch; busy.value = true; emit('busy', true); error.value = ''
  try { const result = await propertyPaymentsApi.revisionPreview(props.supplier, payload); if (ticket === epoch) { preview.value = result; submitted = payload } }
  catch (e) { if (ticket === epoch) error.value = failure(e) } finally { if (ticket === epoch) { busy.value = false; emit('busy', false) } }
}
async function confirm() {
  if (busy.value || !props.canPost || !submitted || !preview.value || !form.value?.reportValidity()) return
  const ticket = epoch; const payload = submitted; busy.value = true; emit('busy', true); error.value = ''
  try {
    await propertyPaymentsApi.revisionConfirm(props.supplier, payload, preview.value.preview_hash, token)
    if (ticket === epoch) { open.value = false; invalidate(); emit('changed') }
  } catch (e) { if (ticket === epoch) error.value = failure(e) } finally { if (ticket === epoch) { busy.value = false; emit('busy', false) } }
}
const actions = computed<ActionItem[]>(() => [preview.value
  ? { key: 'revision-confirm', label: t('propertyPayments.revisionConfirm'), icon: 'check', variant: 'success', tier: 'primary', disabled: busy.value || !props.canPost, run: () => { void confirm() } }
  : { key: 'revision-preview', label: t('propertyPayments.preview'), icon: 'eye', variant: 'primary', tier: 'primary', disabled: busy.value || !props.canManage, run: () => { void showPreview() } }])
function labels(dimensions: Record<number, number>) { return Object.values(dimensions).map(id => props.dimensions.find(v => v.id === id)?.name ?? t('propertyPayments.historicalValue')).join(', ') || t('propertyPayments.unassigned') }
</script>

<template>
  <section class="rounded-lg border border-neutral-200 p-4 space-y-3">
    <h2 class="font-semibold">{{ t('propertyPayments.revisionTitle') }}</h2>
    <p class="text-sm text-neutral-500">{{ t('propertyPayments.revisionHelp') }}</p>
    <PropertyButton v-if="!open && available.length" icon="edit" variant="warning" :disabled="!canManage" data-action="revision-open" @click="begin">{{ t('propertyPayments.revisionOpen') }}</PropertyButton>
    <p v-if="error" role="alert" class="text-danger-600">{{ error }}</p>
    <form v-if="open" ref="form" class="space-y-3" @submit.prevent="showPreview">
      <fieldset :disabled="busy || !canManage" class="space-y-3">
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.accountingDate') }}<DateInput v-model="date" required :class="dateInput" data-field="revision-date" /></label>
          <PropertyInstantInput v-model="at" :label="t('propertyPayments.revisionEffective')" required />
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.reason') }}<input v-model="reason" required :class="inputClass" data-field="revision-reason" /></label>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.evidence') }}<input v-model="evidence" required :class="inputClass" data-field="revision-evidence" /></label>
        </div>
        <div v-for="(move, index) in moves" :key="move.key" class="flex flex-wrap items-end gap-3 rounded border border-neutral-200 p-3">
          <label class="min-w-60 flex-1 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.revisionFrom') }}<select v-model="move.from" required :class="inputClass" data-field="revision-from"><option value="">{{ t('propertyPayments.choose') }}</option><option v-for="(cell, cellIndex) in available" :key="cell.key" :value="cell.key">{{ t('propertyPayments.revisionCell', { number: cellIndex + 1, amount: paymentDecimal(cell.available_cents) }) }} · {{ labels(cell.dimensions) }}</option></select></label>
          <label class="w-full sm:w-40 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.amount') }}<input v-model="move.amount" required inputmode="decimal" :class="inputClass" data-field="revision-amount" /></label>
          <label v-for="axis in axes" :key="axis.id" class="min-w-40 flex-1 flex min-w-0 flex-col gap-1 text-sm">{{ axis.name }}<select v-model="move.dimensions[axis.id]" :class="inputClass"><option :value="0">{{ t('propertyPayments.unassigned') }}</option><option v-for="v in dimensions.filter(v => v.type_id === axis.id)" :key="v.id" :value="v.id">{{ v.name }}</option></select></label>
          <PropertyButton icon="trash" variant="danger" :disabled="moves.length === 1" @click="moves.splice(index, 1)">{{ t('propertyPayments.remove') }}</PropertyButton>
        </div>
        <PropertyButton icon="plus" @click="add">{{ t('propertyPayments.revisionAdd') }}</PropertyButton>
      </fieldset>
      <div v-if="preview" class="space-y-2 rounded border border-success-300 p-3" aria-live="polite"><h3 class="font-semibold">{{ t('propertyPayments.revisionAfter') }}</h3><p v-for="(cell, index) in preview.snapshot.plan.cells" :key="cell.key">{{ t('propertyPayments.cellAmount', { number: index + 1 }) }}: {{ paymentDecimal(cell.amount_cents) }} CZK · {{ labels(cell.line.dimensions) }}</p></div>
      <ActionBar :actions="actions" />
    </form>
  </section>
</template>
