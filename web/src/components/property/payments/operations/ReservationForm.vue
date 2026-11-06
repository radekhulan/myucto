<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyPaymentOperationsApi, type Credit } from '@/api/propertyPaymentOperations'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import PropertyPartyPicker from '@/components/property/PropertyPartyPicker.vue'
import { useWriteFlow } from './flows'
import { buildReservation, emptyReservation } from './partForms'
import { moneyText, partyNames } from './format'
import { boxClass, inputClass } from './ui'
const props = defineProps<{ supplier: number; credit: Credit; canManage: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const state = ref(emptyReservation())
const form = ref<HTMLFormElement | null>(null)
const flow = useWriteFlow({
  supplier: () => props.supplier, validate: () => form.value?.reportValidity() ?? false, canWrite: () => props.canManage,
  build: () => buildReservation(state.value, props.credit),
  write: (supplier, request, token) => propertyPaymentOperationsApi.reserveCredit(supplier, props.credit.id, props.credit.row_version, request, token),
  onDone: () => { state.value = emptyReservation(); emit('changed') },
})
watch(() => props.credit.id, () => { state.value = emptyReservation(); flow.reset() })
const actions = () => [{ key: 'reserve', label: t('propertyPayments.reservation.submit'), icon: 'coin', variant: 'primary', tier: 'primary', disabled: flow.busy.value || !props.canManage, run: () => { void flow.submit() } }] as ActionItem[]
</script>

<template>
  <section :class="boxClass" data-section="reservation-form">
    <h3 class="font-semibold">{{ t('propertyPayments.reservation.title') }}</h3>
    <p class="text-sm">{{ credit.unit_label }} · {{ partyNames(credit.parties) }} · {{ t('propertyPayments.credit.available') }}: {{ moneyText(credit.available_cents, t('propertyPayments.unknown')) }}</p>
    <p class="text-xs text-neutral-500">{{ t('propertyPayments.reservation.help') }}</p>
    <form ref="form" class="space-y-3" @submit.prevent="flow.submit">
      <fieldset :disabled="flow.busy.value || !canManage" class="space-y-3">
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.amount') }}<input v-model="state.amount" required inputmode="decimal" :class="inputClass" data-field="reserve-amount"></label>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.reservation.disposition') }}<input v-model="state.disposition" required :class="inputClass" data-field="reserve-disposition"></label>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.reservation.delivery') }}<select v-model="state.delivery" :class="inputClass" data-field="reserve-delivery"><option value="bank">{{ t('propertyPayments.deliveryMethod.bank') }}</option><option value="cash">{{ t('propertyPayments.deliveryMethod.cash') }}</option></select></label>
          <label class="flex min-w-0 flex-col gap-1 text-sm" v-if="state.delivery === 'bank'">{{ t('propertyPayments.reservation.account') }}<input v-model="state.account" required :class="inputClass" data-field="reserve-account"></label>          <p v-if="state.delivery === 'bank'" class="sm:col-span-2 text-xs text-neutral-500">{{ t('propertyPayments.reservation.accountHelp') }}</p>

        </div>
        <template v-if="state.delivery === 'cash'">
          <div class="space-y-1" data-field="reserve-recipient"><span class="text-sm font-medium">{{ t('propertyPayments.reservation.recipient') }}</span><PropertyPartyPicker :supplier-id="supplier" :model-value="state.recipient_id" @update:model-value="state.recipient_id = $event" /></div>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.reservation.recipientEvidence') }}<input v-model="state.recipient_evidence" required :class="inputClass" data-field="reserve-recipient-evidence"></label>
        </template>
      </fieldset>
      <p v-if="flow.error.value" role="alert" class="text-danger-600">{{ flow.error.value }}</p>
      <p v-if="flow.done.value" role="status" class="text-success-600">{{ t('propertyPayments.reservation.done') }}</p>
      <ActionBar :actions="actions()" />
    </form>
  </section>
</template>
