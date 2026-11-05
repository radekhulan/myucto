<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { PublicWorkReportBillingPreview } from '@/api/workReportBilling'
import type { WorkReportDisplayOptions } from '@/api/invoices'
type RecapPreview = Pick<PublicWorkReportBillingPreview, 'included_hours' | 'extra_hours' | 'total_amount'> & { items: (Omit<PublicWorkReportBillingPreview['items'][number], 'total_amount'> & { total_amount?: number | null })[] }
const props = defineProps<{ preview: RecapPreview; currency: string; displayOptions?: WorkReportDisplayOptions; language?: 'cs' | 'en'; pricesIncludeVat?: boolean }>()
const { t, locale } = useI18n()
function number(value: number | null | undefined, digits = 3, minimum = 0) {
  return value == null ? '' : Number(value).toLocaleString((props.language || locale.value) === 'cs' ? 'cs-CZ' : 'en-US', { minimumFractionDigits: minimum, maximumFractionDigits: digits })
}
</script>
<template>
  <div class="bg-neutral-50 border border-neutral-200 rounded-md p-3 space-y-2 text-sm" data-test="billing-recap">
    <h4 class="font-semibold text-neutral-700">{{ t('work_report_billing.recap', {}, { locale: language || locale }) }}</h4>
    <p v-if="displayOptions?.hours !== false" class="text-neutral-500">{{ t('work_report_billing.hours_recap', { included: number(props.preview.included_hours), extra: number(props.preview.extra_hours) }, { locale: language || locale }) }}</p>
    <p v-if="pricesIncludeVat !== undefined && (displayOptions?.rate !== false || displayOptions?.amount !== false)" class="text-xs text-neutral-500">{{ t(pricesIncludeVat ? 'work_report_billing.prices_incl_vat' : 'work_report_billing.prices_excl_vat', {}, { locale: language || locale }) }}</p>
    <div v-for="item in props.preview.items" :key="item.billing_key" class="flex flex-wrap justify-between gap-2">
      <span class="whitespace-pre-wrap">{{ item.description }}</span>
      <span class="flex flex-wrap justify-end gap-x-3 font-mono">
        <span class="whitespace-nowrap"><span v-if="displayOptions?.hours !== false">{{ number(item.quantity) }} {{ item.unit }}</span><span v-if="displayOptions?.rate !== false"><span v-if="displayOptions?.hours !== false"> × </span>{{ number(item.unit_price_without_vat, 6) }} {{ currency }}</span></span>
        <span v-if="displayOptions?.amount !== false && item.total_amount != null" class="whitespace-nowrap font-medium">{{ number(item.total_amount, 2, 2) }} {{ currency }}</span>
      </span>
    </div>
    <div v-if="displayOptions?.amount !== false" class="font-semibold text-right font-mono">{{ number(props.preview.total_amount, 2, 2) }} {{ currency }}</div>
  </div>
</template>
