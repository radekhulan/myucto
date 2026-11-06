<script setup lang="ts">
import { onBeforeUnmount, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyPaymentOperationsApi, type Debt, type DebtDetail } from '@/api/propertyPaymentOperations'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PagedCollection from './PagedCollection.vue'
import DebtDetailView from './DebtDetailView.vue'
import { failureMessage, moneyText } from './format'
const props = defineProps<{ supplier: number; unitId: number; reloadKey: number; canManage: boolean }>()
const { t } = useI18n()
const detail = ref<DebtDetail | null>(null)
const error = ref('')
const opened = ref(0)
let epoch = 0
onBeforeUnmount(() => epoch++)
watch(() => [props.supplier, props.unitId, props.reloadKey], () => { epoch++; detail.value = null; opened.value = 0 })
async function open(id: number, keep = false) {
  const ticket = ++epoch; const supplier = props.supplier
  opened.value = id; if (!keep) detail.value = null; error.value = ''
  try { const result = await propertyPaymentOperationsApi.detail<DebtDetail>(supplier, 'debts', id); if (ticket === epoch && supplier === props.supplier) detail.value = result }
  catch (e) { if (ticket === epoch) error.value = failureMessage(e, t('propertyPayments.failed')) }
}
</script>

<template>
  <div class="space-y-3">
    <PagedCollection :supplier="supplier" collection="debts" :unit-id="unitId" :reload-key="reloadKey">
      <template #row="{ item }">
        <article class="flex flex-wrap items-center gap-3 border-t border-neutral-200 py-2 text-sm" data-row="debt">
          <span class="min-w-0 flex-1">{{ item.unit_label }} · {{ item.title_ref }}</span><span>{{ t('propertyPayments.dueShort') }} {{ (item as Debt).due_date }}</span>
          <span>{{ moneyText((item as Debt).amount_cents, t('propertyPayments.unknown')) }}</span><span>{{ t('propertyPayments.remainingDebt') }}: {{ moneyText((item as Debt).remaining_cents, t('propertyPayments.unknown')) }}</span>
          <PropertyButton icon="eye" data-action="debt-open" :disabled="opened === item.id && !detail && !error" @click="open(item.id)">{{ t('propertyPayments.detail') }}</PropertyButton>
        </article>
      </template>
    </PagedCollection>
    <p v-if="error" role="alert" class="text-danger-600">{{ error }}</p>
    <DebtDetailView v-if="detail" :supplier="supplier" :detail="detail" :can-manage="canManage" @changed="open(detail.id, true)" />
  </div>
</template>
