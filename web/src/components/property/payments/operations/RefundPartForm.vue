<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { Reservation } from '@/api/propertyPaymentOperations'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PropertyPartyPicker from '@/components/property/PropertyPartyPicker.vue'
import PagedCollection from './PagedCollection.vue'
import type { RefundPartState } from './partForms'
import { moneyText, partyNames } from './format'
import { inputClass } from './ui'
defineProps<{ supplier: number; part: RefundPartState; cells: Array<{ key: string }> }>()
const emit = defineEmits<{ remove: [] }>()
const { t } = useI18n()
const open = (r: Reservation) => r.status === 'reserved' || r.status === 'submitted'
</script>

<template>
  <div class="space-y-3 rounded border border-neutral-200 p-3" data-part="refund">
    <p class="text-xs text-neutral-500">{{ t('propertyPayments.refund.help') }}</p>
    <PagedCollection :supplier="supplier" collection="reservations" :filter="open">
      <template #row="{ item }">
        <label class="flex flex-wrap items-center gap-2 border-t border-neutral-200 py-1 text-sm">
          <input type="radio" :name="part.key" data-field="reservation-select" :checked="part.reservation?.id === item.id" @change="part.reservation = item as Reservation">
          <span>{{ (item as Reservation).unit_label }} · {{ partyNames((item as Reservation).parties) }} · {{ t(`propertyPayments.deliveryMethod.${(item as Reservation).request.delivery_method}`) }}</span>
          <span class="ml-auto">{{ t('propertyPayments.reservation.remaining') }}: {{ moneyText((item as Reservation).remaining_cents, t('propertyPayments.unknown')) }}</span>
        </label>
      </template>
    </PagedCollection>
    <div class="grid gap-3 sm:grid-cols-2">
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.amount') }}<input v-model="part.amount" required inputmode="decimal" :class="inputClass" data-field="refund-amount"></label>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.reason') }}<input v-model="part.reason" required :class="inputClass" data-field="refund-reason"></label>
    </div>
    <div v-if="part.reservation?.request.delivery_method === 'cash'" class="space-y-2 rounded border border-neutral-200 p-3" data-section="cash-receipt">
      <p class="text-sm font-medium">{{ t('propertyPayments.refund.receiptTitle') }}</p><p class="text-xs text-neutral-500">{{ t('propertyPayments.refund.receiptHelp') }}</p>
      <div class="space-y-1" data-field="receipt-person"><span class="text-sm font-medium">{{ t('propertyPayments.refund.receiptPerson') }}</span><PropertyPartyPicker :supplier-id="supplier" :model-value="part.recipient_id" @update:model-value="part.recipient_id = $event" /></div>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.refund.receiptName') }}<input v-model="part.recipient_name" required :class="inputClass" data-field="receipt-name"></label>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.evidence') }}<input v-model="part.receipt_evidence" required :class="inputClass" data-field="receipt-evidence"></label>
    </div>
    <div v-if="cells.length > 1" class="flex flex-wrap gap-3"><label v-for="(cell, cellIndex) in cells" :key="cell.key" class="w-full sm:w-40 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.consumeCell', { number: cellIndex + 1 }) }}<input v-model="part.selection[cell.key]" inputmode="decimal" :class="inputClass"></label></div>
    <PropertyButton icon="trash" variant="danger" @click="emit('remove')">{{ t('propertyPayments.removePart') }}</PropertyButton>
  </div>
</template>
