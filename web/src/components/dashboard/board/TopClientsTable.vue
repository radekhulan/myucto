<script setup lang="ts">
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { formatMoney } from '@/composables/useFormat'
import type { WidgetItem } from '@/dashboard/types'
defineProps<{ items: WidgetItem[] }>()
const { t } = useI18n()
function link(item: WidgetItem) {
  const value = item.links?.client ?? item.links?.detail ?? item.link
  return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') ? value : undefined
}
</script>
<template>
  <div class="min-w-0 overflow-x-auto" data-top-clients-table>
    <table class="w-full text-sm"><thead><tr class="text-xs text-neutral-500 text-left border-b border-neutral-200"><th scope="col" class="py-2 pr-3 font-medium">{{ t('client.company_name') }}</th><th scope="col" class="py-2 px-2 text-right font-medium">{{ t('dashboardBoard.count') }}</th><th scope="col" class="py-2 pl-2 text-right font-medium">{{ t('dashboardBoard.amount') }} (CZK)</th></tr></thead>
      <tbody><tr v-for="(item,index) in items" :key="String(item.client_id ?? index)" class="border-b border-neutral-100 last:border-0"><td class="py-2 pr-3"><RouterLink v-if="link(item)" :to="link(item)!" class="text-primary-600 hover:underline">{{ item.company_name }}</RouterLink><span v-else>{{ item.company_name }}</span></td><td class="py-2 px-2 font-mono text-right">{{ item.invoice_count }}</td><td class="py-2 pl-2 font-mono text-right whitespace-nowrap">{{ formatMoney(Number(item.total_czk ?? item.revenue ?? 0), 'CZK') }}</td></tr></tbody>
    </table>
  </div>
</template>
