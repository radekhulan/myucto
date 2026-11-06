<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Credit } from '@/api/propertyPaymentOperations'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PagedCollection from './PagedCollection.vue'
import ReservationForm from './ReservationForm.vue'
import SetoffForm from './SetoffForm.vue'
import { moneyText, partyNames } from './format'
const props = defineProps<{ supplier: number; unitId: number; reloadKey: number; canManage: boolean; canPost: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const picked = ref<{ credit: Credit; mode: 'reserve' | 'setoff' } | null>(null)
const items = ref<Credit[]>([])
watch(() => [props.supplier, props.unitId], () => { picked.value = null })
function refreshed(rows: Credit[]) {
  items.value = rows
  if (picked.value) { const fresh = rows.find(c => c.id === picked.value!.credit.id); if (fresh) picked.value = { ...picked.value, credit: fresh } }
}
const unknown = () => t('propertyPayments.unknown')
</script>

<template>
  <div class="space-y-3">
    <PagedCollection :supplier="supplier" collection="credits" :unit-id="unitId" :reload-key="reloadKey" @loaded="refreshed($event as Credit[])">
      <template #row="{ item }">
        <article class="space-y-1 border-t border-neutral-200 py-2 text-sm" data-row="credit">
          <div class="flex flex-wrap items-center gap-3">
            <span class="min-w-0 flex-1">{{ item.unit_label }} · {{ partyNames((item as Credit).parties) }} · {{ t('propertyPayments.dueShort') }} {{ (item as Credit).due_date }}</span>
            <span>{{ t('propertyPayments.credit.balance') }}: {{ moneyText((item as Credit).balance_cents, unknown()) }}</span>
            <span>{{ t('propertyPayments.credit.reserved') }}: {{ moneyText((item as Credit).reserved_cents, unknown()) }}</span>
            <span>{{ t('propertyPayments.credit.available') }}: {{ moneyText((item as Credit).available_cents, unknown()) }}</span>
          </div>
          <div class="flex flex-wrap gap-2">
            <PropertyButton v-if="canManage" icon="coin" variant="primary" data-action="credit-reserve" @click="picked = { credit: item as Credit, mode: 'reserve' }">{{ t('propertyPayments.credit.reserve') }}</PropertyButton>
            <PropertyButton v-if="canManage" icon="swap" variant="warning" data-action="credit-setoff" @click="picked = { credit: item as Credit, mode: 'setoff' }">{{ t('propertyPayments.credit.setoff') }}</PropertyButton>
          </div>
        </article>
      </template>
    </PagedCollection>
    <ReservationForm v-if="picked?.mode === 'reserve'" :key="picked.credit.id" :supplier="supplier" :credit="picked.credit" :can-manage="canManage" @changed="emit('changed')" />
    <SetoffForm v-if="picked?.mode === 'setoff'" :key="picked.credit.id" :supplier="supplier" :credit="picked.credit" :can-manage="canManage" :can-post="canPost" @changed="emit('changed')" />
  </div>
</template>
