<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Reservation } from '@/api/propertyPaymentOperations'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PagedCollection from './PagedCollection.vue'
import ReservationCancelForm from './ReservationCancelForm.vue'
import AboExport from './AboExport.vue'
import ExistingAboExport from './ExistingAboExport.vue'
import { moneyText, partyNames } from './format'
const props = defineProps<{ supplier: number; unitId: number; reloadKey: number; canManage: boolean; canPost: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const loaded = ref<Reservation[]>([])
const selected = ref<number[]>([])
const cancelling = ref<{ id: number; mode: 'cancel' | 'submission' | 'release' } | null>(null)
watch(() => [props.supplier, props.unitId], () => { selected.value = []; cancelling.value = null; loaded.value = [] })
const unknown = () => t('propertyPayments.unknown')
const abo = (r: Reservation) => r.status === 'reserved' && r.request.delivery_method === 'bank'
function changed() { selected.value = []; emit('changed') }
function toggle(id: number, on: boolean) { selected.value = on ? [...selected.value, id] : selected.value.filter(v => v !== id) }
</script>

<template>
  <div class="space-y-3">
    <PagedCollection :supplier="supplier" collection="reservations" :unit-id="unitId" :reload-key="reloadKey" @loaded="loaded = $event as Reservation[]">
      <template #row="{ item }">
        <article class="space-y-2 border-t border-neutral-200 py-2 text-sm" data-row="reservation">
          <div class="flex flex-wrap items-center gap-3">
            <input v-if="canManage && abo(item as Reservation)" type="checkbox" data-field="abo-select" :aria-label="t('propertyPayments.abo.select')" :checked="selected.includes(item.id)" @change="toggle(item.id, ($event.target as HTMLInputElement).checked)">
            <span class="min-w-0 flex-1">{{ item.unit_label }} · {{ partyNames((item as Reservation).parties) }} · {{ t(`propertyPayments.deliveryMethod.${(item as Reservation).request.delivery_method}`) }}</span>
            <span class="rounded border border-neutral-300 px-2" data-status>{{ (item as Reservation).cancel_requested ? t('propertyPayments.reservation.status.cancel_requested') : t(`propertyPayments.reservation.status.${(item as Reservation).status}`) }}</span>
            <span>{{ t('propertyPayments.reservation.amount') }}: {{ moneyText((item as Reservation).amount_cents, unknown()) }}</span>
            <span>{{ t('propertyPayments.reservation.paid') }}: {{ moneyText((item as Reservation).paid_cents, unknown()) }}</span>
            <span>{{ t('propertyPayments.reservation.remaining') }}: {{ moneyText((item as Reservation).remaining_cents, unknown()) }}</span>
          </div>
          <p class="text-xs text-neutral-500">{{ (item as Reservation).request.disposition }}<template v-if="(item as Reservation).request.recipient_account"> · {{ (item as Reservation).request.recipient_account }}</template></p>
          <div class="flex flex-wrap gap-2">
            <PropertyButton v-if="canManage && (item as Reservation).status === 'reserved'" icon="x" variant="danger" data-action="reservation-cancel" @click="cancelling = { id: item.id, mode: 'cancel' }">{{ (item as Reservation).paid_cents !== '0' ? t('propertyPayments.reservation.releaseRemainder') : t('propertyPayments.reservation.cancel') }}</PropertyButton>
            <PropertyButton v-if="canManage && (item as Reservation).status === 'submitted' && !(item as Reservation).cancel_requested" icon="x" variant="warning" data-action="submission-cancel" @click="cancelling = { id: item.id, mode: 'submission' }">{{ t('propertyPayments.reservation.cancelSubmission') }}</PropertyButton>
            <PropertyButton v-if="canManage && (item as Reservation).status === 'submitted'" icon="uturn" variant="warning" data-action="submission-release" @click="cancelling = { id: item.id, mode: 'release' }">{{ t('propertyPayments.reservation.releaseUnexecuted') }}</PropertyButton>
          </div>
          <ExistingAboExport v-if="(item as Reservation).request.delivery_method === 'bank'" :supplier="supplier" :reservation="item as Reservation" :can-manage="canManage" :reload-key="reloadKey" />
          <ReservationCancelForm v-if="cancelling?.id === item.id" :supplier="supplier" :reservation="item as Reservation" :mode="cancelling.mode" :can-manage="canManage" @changed="changed" @close="cancelling = null" />
        </article>
      </template>
    </PagedCollection>
    <AboExport v-if="canManage" :supplier="supplier" :reservations="loaded" :selected="selected" :can-manage="canManage" :can-post="canPost" @changed="changed" />
  </div>
</template>
