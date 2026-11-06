<script setup lang="ts">
import { reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyPaymentOperationsApi, type Credit, type DebtDetail } from '@/api/propertyPaymentOperations'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import DebtPicker from './DebtPicker.vue'
import PaymentFactsFields from './PaymentFactsFields.vue'
import SnapshotView from './SnapshotView.vue'
import { usePreviewFlow } from './flows'
import { buildSetoff, emptySetoff } from './partForms'
import { moneyText, partyNames } from './format'
import { boxClass, inputClass } from './ui'
const props = defineProps<{ supplier: number; credit: Credit; canManage: boolean; canPost: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const state = ref(emptySetoff())
const details = reactive<Record<number, DebtDetail>>({})
const form = ref<HTMLFormElement | null>(null)
const flow = usePreviewFlow({
  supplier: () => props.supplier, validate: () => form.value?.reportValidity() ?? false,
  canPreview: () => props.canManage, canConfirm: () => props.canPost,
  build: () => buildSetoff(state.value, props.credit),
  preview: (supplier, request) => propertyPaymentOperationsApi.setoffPreview(supplier, request),
  confirm: (supplier, request, hash, token) => propertyPaymentOperationsApi.setoffConfirm(supplier, request, hash, token),
  onDone: () => emit('changed'),
})
state.value.unit_id = props.credit.unit_id
watch(state, () => flow.invalidate(), { deep: true, flush: 'sync' })
watch(() => [props.credit.id, props.credit.row_version], () => { state.value = emptySetoff(); state.value.unit_id = props.credit.unit_id; flow.reset() })
const actions = () => [flow.preview.value
  ? { key: 'setoff-confirm', label: t('propertyPayments.confirm'), icon: 'check', variant: 'success', tier: 'primary', disabled: flow.busy.value || !props.canPost, run: () => { void flow.confirm() } }
  : { key: 'setoff-preview', label: t('propertyPayments.preview'), icon: 'eye', variant: 'primary', tier: 'primary', disabled: flow.busy.value || !props.canManage, run: () => { void flow.showPreview() } }] as ActionItem[]
</script>

<template>
  <section :class="boxClass" data-section="setoff-form">
    <h3 class="font-semibold">{{ t('propertyPayments.setoff.title') }}</h3>
    <p class="text-sm">{{ credit.unit_label }} · {{ partyNames(credit.parties) }} · {{ t('propertyPayments.credit.available') }}: {{ moneyText(credit.available_cents, t('propertyPayments.unknown')) }}</p>
    <p class="text-xs text-neutral-500">{{ t('propertyPayments.setoff.help') }}</p>
    <form ref="form" class="space-y-3" @submit.prevent="flow.showPreview">
      <fieldset :disabled="flow.busy.value || !canManage" class="space-y-3">
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.amount') }}<input v-model="state.amount" required inputmode="decimal" :class="inputClass" data-field="setoff-amount"></label>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.accountingDate') }}<DateInput v-model="state.date" required :class="inputClass" data-field="setoff-date" /></label>
          <label class="sm:col-span-2 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.reason') }}<input v-model="state.reason" required :class="inputClass" data-field="setoff-reason"></label>
        </div>
        <div class="space-y-1"><p class="text-sm font-medium">{{ t('propertyPayments.alloc.debts') }}</p><p class="text-xs text-neutral-500">{{ t('propertyPayments.setoff.debtsHelp') }}</p>
          <DebtPicker :supplier="supplier" :unit-id="credit.unit_id" :model-value="state.debts" @update:model-value="state.debts = $event" @detail="details[$event.id] = $event" /></div>
        <PaymentFactsFields :supplier="supplier" :state="state.facts" :debts="state.debts" :details="details" />
        <div class="space-y-3 rounded border border-neutral-200 p-3" data-section="setoff-legal">
          <p class="text-sm font-medium">{{ t('propertyPayments.setoff.legalTitle') }}</p>
          <label class="flex flex-wrap items-center gap-2"><input v-model="state.certain" type="checkbox" required data-field="setoff-certain">{{ t('propertyPayments.setoff.certain') }}</label>
          <label class="flex flex-wrap items-center gap-2"><input v-model="state.unconditional" type="checkbox" required data-field="setoff-unconditional">{{ t('propertyPayments.setoff.unconditional') }}</label>
          <div class="grid gap-3 sm:grid-cols-2">
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.setoff.scope') }}<input v-model="state.scope" required inputmode="decimal" :class="inputClass" data-field="setoff-scope"></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.setoff.mutuality') }}<input v-model="state.mutuality" required :class="inputClass" data-field="setoff-mutuality"></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.setoff.eligibility') }}<input v-model="state.eligibility" required :class="inputClass" data-field="setoff-eligibility"></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.setoff.declaration') }}<input v-model="state.declaration" required :class="inputClass" data-field="setoff-declaration"></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.setoff.delivery') }}<input v-model="state.delivery" required :class="inputClass" data-field="setoff-delivery"></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.setoff.effect') }}<input v-model="state.effect" required :class="inputClass" data-field="setoff-effect"></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.setoff.early') }}<input v-model="state.early" :class="inputClass" data-field="setoff-early"></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.setoff.scope1984') }}<input v-model="state.scope1984" :class="inputClass" data-field="setoff-1984"></label>
          </div>
          <div data-field="setoff-delivered-at"><PropertyInstantInput v-model="state.delivered_at" :label="t('propertyPayments.setoff.deliveredAt')" required /></div>
        </div>
      </fieldset>
      <section v-if="flow.preview.value" class="space-y-2 rounded border border-success-300 p-3" aria-live="polite"><h4 class="font-semibold">{{ t('propertyPayments.previewTitle') }}</h4><SnapshotView :snapshot="flow.preview.value.snapshot" /></section>
      <p v-if="flow.error.value" role="alert" class="text-danger-600">{{ flow.error.value }}</p>
      <p v-if="flow.done.value" role="status" class="text-success-600">{{ t('propertyPayments.setoff.done') }}</p>
      <ActionBar :actions="actions()" />
    </form>
  </section>
</template>
