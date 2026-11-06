<script setup lang="ts">
import { onActivated, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyPaymentsApi, type BankProposalQueueItem, type BankProposalStatus } from '@/api/propertyPayments'
import type { PropertyObject } from '@/api/propertyRegistry'
import PropertyButton from '@/components/property/PropertyButton.vue'
import { failureMessage, propertyUnitLabel } from './operations/format'
import { propertyInput as inputClass } from '@/components/property/propertyFormStyles'

const props = defineProps<{ supplier: number; units: PropertyObject[]; canManage: boolean }>()
const emit = defineEmits<{ open: [transactionId: number] }>()
const { t } = useI18n()
const items = ref<BankProposalQueueItem[]>([])
const next = ref<number | null>(null)
const status = ref<'' | BankProposalStatus>('')
const loading = ref(false)
const error = ref('')
let epoch = 0

async function load(append = false) {
  const current = ++epoch
  loading.value = true; error.value = ''
  try {
    const page = await propertyPaymentsApi.bankProposals(props.supplier, append ? next.value ?? 0 : 0, 25, status.value || undefined)
    if (current !== epoch) return
    items.value = append ? [...items.value, ...page.items] : page.items
    next.value = page.next_cursor
  } catch (e) {
    if (current === epoch) error.value = failureMessage(e, t('propertyPayments.failed'))
  } finally {
    if (current === epoch) loading.value = false
  }
}
watch(() => [props.supplier, status.value], () => { void load() }, { immediate: true })
onActivated(() => { void load() })

const unitName = (id: number | null) => {
  const unit = id === null ? undefined : props.units.find(u => u.id === id)
  return unit ? propertyUnitLabel(unit, number => t('propertyPayments.unitNumber', { number })) : t('propertyPayments.bankProposal.noUnit')
}
const statusClass = (value: BankProposalStatus) => ({ identified: 'bg-success-50 text-success-600', review_required: 'bg-warning-50 text-warning-600', unidentified: 'bg-neutral-100 text-neutral-600' })[value]
</script>

<template>
  <section class="space-y-3 rounded-lg border border-neutral-200 p-4" data-section="bank-proposal-queue">
    <div class="flex flex-wrap items-end justify-between gap-3">
      <div><h2 class="font-semibold">{{ t('propertyPayments.bankProposal.queueTitle') }}</h2><p class="text-sm text-neutral-500">{{ t('propertyPayments.bankProposal.queueHelp') }}</p></div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.bankProposal.filter') }}
          <select v-model="status" :class="inputClass" data-field="proposal-status">
            <option value="">{{ t('propertyPayments.bankProposal.filterOpen') }}</option>
            <option value="identified">{{ t('propertyPayments.bankProposal.status.identified') }}</option>
            <option value="review_required">{{ t('propertyPayments.bankProposal.status.review_required') }}</option>
            <option value="unidentified">{{ t('propertyPayments.bankProposal.status.unidentified') }}</option>
          </select>
        </label>
        <PropertyButton icon="cycle" :disabled="loading" data-action="proposal-refresh" @click="load()">{{ t('propertyPayments.refresh') }}</PropertyButton>
      </div>
    </div>
    <p v-if="error" role="alert" class="text-danger-600">{{ error }}</p>
    <p v-if="loading && !items.length">{{ t('propertyPayments.loading') }}</p>
    <p v-else-if="!items.length" class="text-sm text-neutral-500" data-empty>{{ t('propertyPayments.bankProposal.queueEmpty') }}</p>
    <article v-for="item in items" :key="item.bank_transaction_id" class="flex flex-wrap items-center gap-3 border-t border-neutral-200 py-2 text-sm" data-row="bank-proposal">
      <span class="whitespace-nowrap">{{ item.transaction.date }}</span>
      <span class="whitespace-nowrap font-mono font-medium">{{ item.transaction.amount }} CZK</span>
      <span v-if="item.transaction.variable_symbol" class="whitespace-nowrap font-mono">{{ t('propertyPayments.sourcePicker.vs', { value: item.transaction.variable_symbol }) }}</span>
      <span class="min-w-0 flex-1 truncate">{{ item.transaction.partner_name || item.transaction.description }}</span>
      <span class="whitespace-nowrap">{{ unitName(item.unit_id) }}</span>
      <span class="rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap" :class="statusClass(item.status)" data-status>{{ t(`propertyPayments.bankProposal.status.${item.status}`) }}</span>
      <PropertyButton icon="link" variant="primary" :disabled="!canManage" data-action="proposal-open" @click="emit('open', item.bank_transaction_id)">{{ t('propertyPayments.bankProposal.open') }}</PropertyButton>
    </article>
    <div v-if="next !== null" class="flex flex-wrap"><PropertyButton icon="chevron" :disabled="loading" data-action="proposal-more" @click="load(true)">{{ t('propertyPayments.loadMore') }}</PropertyButton></div>
  </section>
</template>
