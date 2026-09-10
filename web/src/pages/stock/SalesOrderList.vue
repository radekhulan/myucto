<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { salesOrdersApi, type SalesOrder } from '@/api/salesOrders'
import type { CatalogJob } from '@/api/catalogJobs'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const toast = useToast()
const rows = ref<SalesOrder[]>([])
const loading = ref(false)
const error = ref('')
const q = ref('')
const shortageOnly = ref(false)
const total = ref(0)
const expiry = ref<CatalogJob | null>(null)
const expiryRunning = ref(false)
const progress = computed(() => {
  if (!expiry.value?.total) return 0
  return Math.min(100, Math.round(((expiry.value.checkpoint ?? 0) / expiry.value.total) * 100))
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    const data = await salesOrdersApi.list({ q: q.value, shortage: shortageOnly.value ? 1 : 0 })
    rows.value = data.items
    total.value = data.total
  } catch (e) { error.value = apiErrorMessage(e) } finally { loading.value = false }
}

async function expireReservations() {
  expiryRunning.value = true
  try {
    expiry.value = await salesOrdersApi.enqueueExpiry()
    while (expiry.value.status === 'queued' || expiry.value.status === 'running') {
      await salesOrdersApi.runExpiryBatch()
      expiry.value = await salesOrdersApi.expiryJob(expiry.value.id)
    }
    if (expiry.value.status === 'failed') throw new Error(t('sales_orders.expiry_failed'))
    toast.success(t('sales_orders.expiry_done', { count: Number(expiry.value.report?.expired ?? 0) }))
    await load()
  } catch (e) { toast.error(apiErrorMessage(e)) } finally { expiryRunning.value = false }
}

const commercialBadge: Record<string, string> = {
  draft: 'bg-neutral-100 text-neutral-700', confirmed: 'bg-primary-50 text-primary-700',
  cancelled: 'bg-danger-50 text-danger-500', completed: 'bg-success-50 text-success-600',
}

onMounted(load)
</script>

<template>
  <div>
    <div class="flex flex-wrap items-start justify-between gap-3 mb-5">
      <div><h1 class="text-2xl font-semibold">{{ t('sales_orders.title') }}</h1><p class="text-sm text-neutral-500 mt-1">{{ t('sales_orders.subtitle') }}</p></div>
      <div class="flex flex-wrap gap-2">
        <button type="button" :disabled="expiryRunning" :class="[btnFilled('warning'), 'whitespace-nowrap']" @click="expireReservations">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.cycle" stroke-width="1.8" /></svg>
          {{ t('sales_orders.expire') }}
        </button>
        <RouterLink to="/stock/sales-orders/new" :class="[btnFilled('primary'), 'whitespace-nowrap']">
          <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" stroke-width="1.8" /></svg>
          {{ t('sales_orders.new') }}
        </RouterLink>
      </div>
    </div>

    <div v-if="expiry" class="bg-surface border border-neutral-200 rounded-lg p-3 mb-4 text-sm">
      <div class="flex justify-between gap-3"><span>{{ t('sales_orders.expiry_progress') }}</span><span>{{ expiry.checkpoint ?? 0 }} / {{ expiry.total ?? 0 }}</span></div>
      <div class="h-2 bg-neutral-100 rounded-full mt-2 overflow-hidden"><div class="h-full bg-warning-500 transition-all" :style="{ width: `${progress}%` }" /></div>
    </div>

    <form class="bg-surface border border-neutral-200 rounded-lg p-3 mb-4 flex flex-wrap gap-3" @submit.prevent="load">
      <input v-model="q" :placeholder="t('sales_orders.search')" class="h-10 min-w-56 flex-1 px-3 border border-neutral-300 rounded-md bg-surface" />
      <label class="h-10 flex items-center gap-2 text-sm"><input v-model="shortageOnly" type="checkbox" />{{ t('sales_orders.shortages_only') }}</label>
      <button type="submit" :class="btnFilled('neutral')"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.search" stroke-width="1.8" /></svg>{{ t('common.search') }}</button>
    </form>
    <div v-if="error" class="bg-danger-50 text-danger-500 border border-danger-500/30 rounded-md p-3 mb-4">{{ error }}</div>
    <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>
    <template v-else>
      <div class="hidden md:block bg-surface border border-neutral-200 rounded-lg overflow-x-auto">
        <table class="w-full text-sm"><thead class="bg-neutral-50 text-neutral-600"><tr><th class="text-left p-3">{{ t('sales_orders.number') }}</th><th class="text-left p-3">{{ t('sales_orders.customer') }}</th><th class="text-left p-3">{{ t('sales_orders.states') }}</th><th class="text-right p-3">{{ t('sales_orders.total') }}</th><th class="text-left p-3">{{ t('sales_orders.created') }}</th></tr></thead>
          <tbody class="divide-y divide-neutral-100"><tr v-for="order in rows" :key="order.id" class="hover:bg-neutral-50"><td class="p-3"><RouterLink :to="`/stock/sales-orders/${order.id}`" class="text-primary-700 font-medium hover:underline">{{ order.order_number }}</RouterLink></td><td class="p-3">{{ order.client_name }}</td><td class="p-3 space-x-1"><span class="px-2 py-0.5 rounded text-xs" :class="commercialBadge[order.commercial_status]">{{ t(`sales_orders.commercial.${order.commercial_status}`) }}</span><span class="px-2 py-0.5 rounded text-xs bg-accent-50 text-accent-700">{{ t(`sales_orders.fulfillment.${order.fulfillment_status}`) }}</span></td><td class="p-3 text-right tabular-nums">{{ formatMoney(Number(order.total_with_vat), order.currency_code) }}</td><td class="p-3">{{ formatDate(order.created_at) }}</td></tr></tbody>
        </table>
      </div>
      <div class="md:hidden space-y-3"><RouterLink v-for="order in rows" :key="order.id" :to="`/stock/sales-orders/${order.id}`" class="block bg-surface border border-neutral-200 rounded-lg p-4"><div class="flex justify-between gap-3"><strong>{{ order.order_number }}</strong><span>{{ formatMoney(Number(order.total_with_vat), order.currency_code) }}</span></div><div class="text-sm text-neutral-600 mt-1">{{ order.client_name }}</div><div class="text-xs text-neutral-500 mt-2">{{ t(`sales_orders.commercial.${order.commercial_status}`) }} · {{ t(`sales_orders.fulfillment.${order.fulfillment_status}`) }}</div></RouterLink></div>
      <p v-if="rows.length === 0" class="text-center text-neutral-500 py-12">{{ t('sales_orders.empty') }}</p>
      <p class="text-xs text-neutral-500 mt-3">{{ t('sales_orders.count', { count: total }) }}</p>
    </template>
  </div>
</template>
