<script setup lang="ts">
import { computed, ref, watch, onActivated, onBeforeUnmount } from 'vue'
import { codebooksApi, type Currency } from '@/api/codebooks'
import { useSupplierStore } from '@/stores/supplier'
import { apiErrorMessage } from '@/api/errors'
import { useI18n } from 'vue-i18n'
import type { WorkReportBillingSnapshot } from '@/api/workReportBilling'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
const props = defineProps<{ readOnly?: boolean; currencies?: Currency[] }>()
const model = defineModel<WorkReportBillingSnapshot>({ required: true })
const { t } = useI18n()
const supplier = useSupplierStore()
const loadedCurrencies = ref<Currency[]>([])
const error = ref('')
let generation = 0
async function loadCurrencies() {
  const request = ++generation
  error.value = ''
  loadedCurrencies.value = []
  if (props.currencies != null) return
  try {
    const result = await codebooksApi.currencies()
    if (request === generation) loadedCurrencies.value = result
  } catch (e) { if (request === generation) error.value = apiErrorMessage(e, t('common.error')) }
}
watch(() => [supplier.currentSupplierId, props.currencies], loadCurrencies, { immediate: true })
let activatedOnce = false
onActivated(() => { if (activatedOnce) void loadCurrencies(); activatedOnce = true })
onBeforeUnmount(() => { generation++ })
const currencyOptions = computed(() => (props.currencies ?? loadedCurrencies.value).filter(currency => currency.is_active !== false))
function addBand() {
  const bands = model.value.bands
  const last = bands.at(-1)
  if (last && last.up_to_hours == null) last.up_to_hours = Math.max(Number(model.value.included_hours), Number(bands.at(-2)?.up_to_hours) || 0) + 10
  bands.push({ up_to_hours: null, rate: last?.rate ?? (Number(supplier.currentSupplier?.default_hourly_rate) || 0), description: last?.description ?? '{nazev} – {obdobi}' })
}
</script>
<template>
  <fieldset :disabled="readOnly" class="space-y-3">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <label class="text-sm text-neutral-700">{{ t('work_report_billing.name') }}<input v-model="model.name" maxlength="100" required class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface" /></label>
      <label class="text-sm text-neutral-700">{{ t('work_report_billing.currency') }}<select v-model="model.currency" data-test="billing-currency" class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface">
        <option v-if="!currencyOptions.some(currency => currency.code === model.currency)" :value="model.currency">{{ model.currency }}</option>
        <option v-for="currency in currencyOptions" :key="currency.id" :value="currency.code">{{ currency.code }}</option>
      </select></label>
      <label class="text-sm text-neutral-700">{{ t('work_report_billing.fixed_fee') }}<input :value="model.fixed_fee ?? ''" @input="model.fixed_fee = ($event.target as HTMLInputElement).value === '' ? null : Number(($event.target as HTMLInputElement).value)" type="number" min="0" step="0.01" class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface" /></label>
      <label class="text-sm text-neutral-700">{{ t('work_report_billing.included_hours') }}<input v-model.number="model.included_hours" type="number" min="0" step="0.01" class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface" /></label>
    </div>
    <label v-if="model.fixed_fee !== null" class="block text-sm text-neutral-700">{{ t('work_report_billing.fixed_description') }}<input v-model="model.fixed_description" maxlength="500" class="mt-1 w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface" /></label>
    <p class="text-xs text-neutral-500">{{ t('work_report_billing.rules') }}</p>
    <div v-for="(band, i) in model.bands" :key="i" class="border border-neutral-200 rounded-md p-3 space-y-2">
      <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm text-neutral-700 flex-1 min-w-36">{{ t('work_report_billing.up_to_hours') }}<input v-if="i < model.bands.length - 1" v-model.number="band.up_to_hours" type="number" min="0" step="0.01" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded bg-surface" /><span v-else class="block mt-1 h-9 py-2 text-neutral-500">{{ t('work_report_billing.unlimited') }}</span></label>
        <label class="text-sm text-neutral-700 flex-1 min-w-36">{{ t('invoice.wr_rate') }}<input v-model.number="band.rate" type="number" min="0" step="0.000001" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded bg-surface" /></label>
        <button v-if="!readOnly && (model.bands.length > 1 || model.fixed_fee !== null)" type="button" :class="btnOutline('danger')" :title="t('common.delete')" @click="model.bands.splice(i, 1); model.bands.length && (model.bands[model.bands.length - 1]!.up_to_hours = null)"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('common.delete') }}</button>
      </div>
      <label class="block text-sm text-neutral-700">{{ t('work_report_billing.description') }}<input v-model="band.description" maxlength="500" class="mt-1 w-full h-9 px-2 border border-neutral-300 rounded bg-surface" /></label>
    </div>
    <button v-if="!readOnly" type="button" :class="btnOutline('primary')" @click="addBand"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('work_report_billing.add_band') }}</button>
    <p class="text-xs text-neutral-500">{{ t('work_report_billing.placeholders') }}</p>
    <p v-if="error" class="text-sm text-danger-600">{{ error }}</p>
  </fieldset>
</template>
