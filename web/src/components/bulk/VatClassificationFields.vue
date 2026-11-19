<script setup lang="ts">
/**
 * Parametry hromadné změny členění DPH: kód z číselníku členění podle směru dokladu,
 * u položek volitelně nová sazba a předvolba „Mimo předmět DPH".
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { vatClassificationsApi, type VatClassification } from '@/api/vatClassifications'
import { codebooksApi, type VatRate } from '@/api/codebooks'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'

/** Kód členění pro plnění mimo předmět DPH a k němu nulová tuzemská sazba bez přenesení. */
const OUTSIDE_VAT_CODE = 'mimo'
const OUTSIDE_VAT_RATE_CODE = 'CZ-0'

const props = withDefaults(defineProps<{
  direction: 'sale' | 'purchase'
  /** Nabídnout i změnu sazby (jen u položek). */
  withRate?: boolean
  disabled?: boolean
}>(), { withRate: false, disabled: false })

const code = defineModel<string>('code', { required: true })
const rateId = defineModel<number | null>('rateId', { default: null })

const { t, locale } = useI18n()

const classifications = ref<VatClassification[]>([])
const rates = ref<VatRate[]>([])

const codeOptions = computed(() => classifications.value.filter(c => !c.archived))
const rateOptions = computed(() => [...rates.value].sort((a, b) => a.display_order - b.display_order))

function rateLabel(r: VatRate): string {
  const label = locale.value === 'en' ? r.label_en : r.label_cs
  return label ? `${label} (${r.code})` : r.code
}

function outsideVatRate(): VatRate | undefined {
  return rates.value.find(r => r.code === OUTSIDE_VAT_RATE_CODE)
    ?? rates.value.find(r => Number(r.rate_percent) === 0 && !r.is_reverse_charge && r.country === 'CZ')
}

function applyOutsideVat() {
  code.value = OUTSIDE_VAT_CODE
  if (props.withRate) rateId.value = outsideVatRate()?.id ?? rateId.value
}

function onRateChange(e: Event) {
  const v = (e.target as HTMLSelectElement).value
  rateId.value = v === '' ? null : Number(v)
}

onMounted(() => {
  vatClassificationsApi.list(props.direction).then(v => { classifications.value = v }).catch(() => { classifications.value = [] })
})
watch(() => props.withRate, on => {
  if (on && rates.value.length === 0) codebooksApi.vatRates('CZ').then(v => { rates.value = v }).catch(() => { rates.value = [] })
}, { immediate: true })
</script>

<template>
  <div class="space-y-3" data-test="bulk-params-vat">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div>
        <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-vat-code">{{ t('bulk_actions.vat_code_label') }}</label>
        <select id="bulk-vat-code" v-model="code" :disabled="disabled"
          class="w-full h-10 px-2 border border-neutral-300 rounded-md bg-surface" data-test="bulk-vat-code">
          <option value="">{{ t('bulk_actions.vat_code_placeholder') }}</option>
          <option v-for="c in codeOptions" :key="c.id" :value="c.code">{{ c.code }} · {{ c.label }}</option>
        </select>
      </div>
      <div v-if="withRate">
        <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-vat-rate">{{ t('bulk_actions.vat_rate_label') }}</label>
        <select id="bulk-vat-rate" :value="rateId ?? ''" :disabled="disabled"
          class="w-full h-10 px-2 border border-neutral-300 rounded-md bg-surface" data-test="bulk-vat-rate" @change="onRateChange">
          <option value="">{{ t('bulk_actions.vat_rate_keep') }}</option>
          <option v-for="r in rateOptions" :key="r.id" :value="r.id">{{ rateLabel(r) }}</option>
        </select>
      </div>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <button type="button" :class="btnOutline('primary')" class="whitespace-nowrap" :disabled="disabled"
        data-test="bulk-vat-outside" @click="applyOutsideVat">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.tag" /></svg>
        {{ t('bulk_actions.vat_outside_preset') }}
      </button>
    </div>
    <p class="text-xs text-neutral-500" data-test="bulk-vat-hint">
      <template v-if="withRate">{{ t('bulk_actions.vat_rate_hint') }} </template>{{ t('bulk_actions.vat_filed_hint') }}
    </p>
  </div>
</template>
