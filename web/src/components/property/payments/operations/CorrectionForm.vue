<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyPaymentOperationsApi, type Charge, type CorrectionOptions } from '@/api/propertyPaymentOperations'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import PagedCollection from './PagedCollection.vue'
import SnapshotView from './SnapshotView.vue'
import { usePreviewFlow } from './flows'
import { absCents, buildCorrection, emptyCorrection } from './partForms'
import { failureMessage, moneyText } from './format'
import { paymentDecimal } from '../paymentWorkflowForm'
import { boxClass, inputClass, subBoxClass } from './ui'
const props = defineProps<{ supplier: number; unitId: number; canManage: boolean; canPost: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const options = ref<CorrectionOptions | null>(null)
const state = ref(emptyCorrection())
const loadError = ref('')
const form = ref<HTMLFormElement | null>(null)
let epoch = 0
const unknown = () => t('propertyPayments.unknown')
const negative = (c: Charge) => c.amount_cents.startsWith('-')
const rows = computed(() => (options.value?.charge.lines ?? []).filter(l => l.amount_cents.startsWith('-') && state.value.anchors[l.id]).map(line => ({ line, anchor: state.value.anchors[line.id]! })))
const flow = usePreviewFlow({
  supplier: () => props.supplier, validate: () => form.value?.reportValidity() ?? false,
  canPreview: () => props.canManage, canConfirm: () => props.canPost,
  build: () => buildCorrection(state.value, options.value!),
  preview: (supplier, request) => propertyPaymentOperationsApi.correctionPreview(supplier, request),
  confirm: (supplier, request, hash, token) => propertyPaymentOperationsApi.correctionConfirm(supplier, request, hash, token),
  onDone: () => { emit('changed') },
})
watch(state, () => flow.invalidate(), { deep: true, flush: 'sync' })
watch(() => [props.supplier, props.unitId], () => { epoch++; options.value = null; state.value = emptyCorrection(); flow.reset() })
async function choose(charge: Charge) {
  const ticket = ++epoch; const supplier = props.supplier
  options.value = null; loadError.value = ''; flow.reset()
  try {
    const result = await propertyPaymentOperationsApi.correctionOptions(supplier, charge.id)
    if (ticket !== epoch || supplier !== props.supplier) return
    const next = emptyCorrection()
    for (const line of result.charge.lines.filter(l => l.amount_cents.startsWith('-'))) next.anchors[line.id] = { original: 0, amount: paymentDecimal(absCents(line.amount_cents)), evidence: '' }
    state.value = next
    options.value = result
  } catch (e) { if (ticket === epoch) loadError.value = failureMessage(e, t('propertyPayments.failed')) }
}
const actions = () => [flow.preview.value
  ? { key: 'correction-confirm', label: t('propertyPayments.confirm'), icon: 'check', variant: 'success', tier: 'primary', disabled: flow.busy.value || !props.canPost, run: () => { void flow.confirm() } }
  : { key: 'correction-preview', label: t('propertyPayments.preview'), icon: 'eye', variant: 'primary', tier: 'primary', disabled: flow.busy.value || !props.canManage || !options.value, run: () => { void flow.showPreview() } }] as ActionItem[]
</script>

<template>
  <section :class="boxClass" data-section="correction-form">
    <h3 class="font-semibold">{{ t('propertyPayments.correction.title') }}</h3>
    <p class="text-sm text-neutral-500">{{ t('propertyPayments.correction.help') }}</p>
    <PagedCollection :supplier="supplier" collection="charges" :unit-id="unitId" :filter="negative">
      <template #row="{ item }">
        <label class="flex flex-wrap items-center gap-2 border-t border-neutral-200 py-1 text-sm">
          <input type="radio" name="adjustment" data-field="adjustment-select" :checked="options?.charge.id === item.id" :disabled="flow.busy.value" @change="choose(item as Charge)">
          <span>{{ item.unit_label }} · {{ (item as Charge).accounting_date }} · {{ (item as Charge).status }}</span><span class="ml-auto">{{ moneyText((item as Charge).amount_cents, unknown()) }}</span>
        </label>
      </template>
    </PagedCollection>
    <p v-if="loadError" role="alert" class="text-danger-600">{{ loadError }}</p>
    <form v-if="options" ref="form" class="space-y-3" @submit.prevent="flow.showPreview">
      <fieldset :disabled="flow.busy.value || !canManage" class="space-y-3">
        <div class="space-y-2">
          <p class="text-sm font-medium">{{ t('propertyPayments.correction.anchors') }}</p><p class="text-xs text-neutral-500">{{ t('propertyPayments.correction.anchorsHelp') }}</p>
          <div v-for="{ line, anchor } in rows" :key="line.id" :class="subBoxClass" data-section="anchor">
            <p class="text-sm">{{ line.label }}: {{ moneyText(line.amount_cents, unknown()) }}</p>
            <div class="flex flex-wrap items-end gap-3">
              <label class="min-w-60 flex-1 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.correction.original') }}<select v-model="anchor.original" :class="inputClass" data-field="anchor-original"><option :value="0">{{ t('propertyPayments.correction.noAnchor') }}</option><option v-for="o in options.original_lines" :key="o.id" :value="o.id">{{ o.label }} ({{ moneyText(o.amount_cents, unknown()) }})</option></select></label>
              <label class="w-full sm:w-40 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.amount') }}<input v-model="anchor.amount" :required="anchor.original > 0" inputmode="decimal" :class="inputClass" data-field="anchor-amount"></label>
              <label class="min-w-60 flex-1 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.evidence') }}<input v-model="anchor.evidence" :required="anchor.original > 0" :class="inputClass" data-field="anchor-evidence"></label>
            </div>
          </div>
        </div>
        <div class="space-y-2">
          <p class="text-sm font-medium">{{ t('propertyPayments.correction.reliefs') }}</p><p class="text-xs text-neutral-500">{{ t('propertyPayments.correction.reliefsHelp') }}</p>
          <label v-for="a in options.allocations" :key="a.id" class="flex min-w-0 flex-wrap items-center gap-2 text-sm"><span class="min-w-60 flex-1">{{ a.label }} · {{ moneyText(a.amount_cents, unknown()) }} · {{ t('propertyPayments.correction.availableRelief') }}: {{ moneyText(a.available_relief_cents, unknown()) }}</span><input v-model="state.reliefs[a.id]" inputmode="decimal" class="h-9 w-36 rounded-md border border-neutral-300 bg-surface px-2 text-sm" data-field="relief-amount"></label>
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.accountingDate') }}<DateInput v-model="state.date" required :class="inputClass" data-field="correction-date" /></label>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.reason') }}<input v-model="state.reason" required :class="inputClass" data-field="correction-reason"></label>
        </div>
        <div :class="subBoxClass" data-section="correction-credit">
          <label class="flex flex-wrap items-center gap-2"><input v-model="state.credit_on" type="checkbox" data-field="correction-credit-on">{{ t('propertyPayments.correction.creditOn') }}</label>
          <template v-if="state.credit_on">
            <p class="text-xs text-neutral-500">{{ t('propertyPayments.correction.creditHelp') }}</p>
            <label v-for="p in options.parties" :key="p.id" class="flex flex-wrap items-center gap-2 text-sm"><input v-model="state.credit_parties" type="checkbox" :value="p.id" data-field="correction-party">{{ p.label }}</label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.evidence') }}<input v-model="state.credit_evidence" required :class="inputClass" data-field="correction-credit-evidence"></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.dueDate') }}<DateInput v-model="state.credit_due" required :class="inputClass" data-field="correction-credit-due" /></label>
          </template>
        </div>
      </fieldset>
      <section v-if="flow.preview.value" class="space-y-2 rounded border border-success-300 p-3" aria-live="polite"><h4 class="font-semibold">{{ t('propertyPayments.previewTitle') }}</h4><SnapshotView :snapshot="flow.preview.value.snapshot" /></section>
      <p v-if="flow.error.value" role="alert" class="text-danger-600">{{ flow.error.value }}</p>
      <p v-if="flow.done.value" role="status" class="text-success-600">{{ t('propertyPayments.correction.done') }}</p>
      <ActionBar :actions="actions()" />
    </form>
  </section>
</template>
