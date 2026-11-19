<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { closingApi, type ClosingStep, type DeferredTaxPreview } from '@/api/closing'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

/**
 * Odložená daň u ostatních firem (vyhláška 500, ČÚS 003). Volitelný krok: § 59 ji ukládá
 * jen účetním jednotkám s povinným auditem a konsolidačním celkům, ostatní o ní účtují
 * dobrovolně, proto uzavření knih neblokuje. Panel jen načte náhled a sestaví částku,
 * zaúčtování, přeskočení i vrácení provádí stránka průvodce (`book`, `skip`, `revert`).
 */
const props = defineProps<{
  periodId: number
  rowVersion: number
  step: ClosingStep | undefined
  isClosing: boolean
  canWrite: boolean
  busy: boolean
}>()
const emit = defineEmits<{
  book: [payload: { amount: number; reason?: string; prudence_confirmed?: boolean }]
  skip: []
  revert: []
}>()
const { t } = useI18n()

const preview = ref<DeferredTaxPreview | null>(null)
const loading = ref(false)
const error = ref('')
const amount = ref<number | null>(null)
const reason = ref('')
const prudence = ref(false)
let scope = 0

/**
 * Návrh zápisu. Odložená daň se po letech nekumuluje, zápis dorovnává zůstatek 481 na
 * vypočtenou hodnotu (`movement`). Při opakovaném zaúčtování je v zůstatku 481 už i zápis
 * tohoto kroku, proto se jeho částka přičte zpět, jinak by návrh vyšel nulový.
 */
const suggested = computed<number | null>(() => {
  const p = preview.value
  if (!p || !p.applicable || !p.computation) return null
  return Math.round((p.movement + Number(p.existing_amount ?? 0)) * 100) / 100
})
const isAsset = computed(() => (amount.value ?? 0) < 0)
const status = computed(() => props.step?.status ?? 'pending')
const payload = computed(() => (props.step?.payload ?? null) as { amount?: number; document_no?: string; kind?: string } | null)

async function load() {
  const current = ++scope
  loading.value = true
  error.value = ''
  try {
    const result = await closingApi.deferredTaxPreview(props.periodId)
    if (current !== scope) return
    preview.value = result
    amount.value = suggested.value
  } catch (e) {
    if (current === scope) error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    if (current === scope) loading.value = false
  }
}

function book() {
  if (amount.value === null || !Number.isFinite(amount.value) || amount.value === 0) {
    error.value = t('accounting.closing.deferred_tax500.amount_required')
    return
  }
  error.value = ''
  const text = reason.value.trim()
  emit('book', {
    amount: amount.value,
    ...(text ? { reason: text } : {}),
    ...(isAsset.value ? { prudence_confirmed: prudence.value } : {}),
  })
}

watch(() => [props.periodId, props.rowVersion], () => { void load() }, { immediate: true })
</script>

<template>
  <div class="space-y-3 text-sm" data-test="deferred-tax-500">
    <p class="rounded-md border border-neutral-200 bg-neutral-50 px-3 py-2 text-neutral-600">
      {{ t('accounting.closing.deferred_tax500.hint') }}
    </p>

    <div v-if="loading && !preview" class="text-neutral-500">{{ t('common.loading') }}</div>
    <p v-if="error" class="rounded-md bg-danger-50 px-3 py-2 text-danger-700" data-test="deferred-tax-500-error">{{ error }}</p>

    <template v-if="preview">
      <div v-if="!preview.applicable" class="rounded-md bg-warning-50 px-3 py-2 text-warning-800">
        {{ t('accounting.closing.deferred_tax500.not_applicable') }}
      </div>
      <template v-else-if="preview.computation">
        <div class="text-xs text-neutral-500">
          {{ t('accounting.closing.deferred_tax500.rate', { rate: preview.computation.rate * 100, year: preview.computation.rate_year }) }}
        </div>
        <table v-if="preview.computation.titles.length" class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.closing.deferred_tax500.title_col') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('accounting.closing.deferred_tax500.difference_col') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in preview.computation.titles" :key="row.key">
              <td class="px-3 py-1.5">{{ row.label }}</td>
              <td class="px-3 py-1.5 text-right font-mono">{{ formatMoney(row.difference) }}</td>
            </tr>
          </tbody>
        </table>
        <p v-else class="text-neutral-500">{{ t('accounting.closing.deferred_tax500.no_titles') }}</p>
        <ul v-if="preview.computation.warnings.length" class="list-disc pl-5 text-warning-700">
          <li v-for="(w, i) in preview.computation.warnings" :key="i">{{ w }}</li>
        </ul>
        <div class="space-y-0.5">
          <div>{{ t('accounting.closing.deferred_tax500.computed') }}: <strong class="font-mono">{{ formatMoney(preview.computation.deferred_tax) }}</strong></div>
          <div class="text-xs text-neutral-500">
            481: <span class="font-mono">{{ formatMoney(preview.balance_481) }}</span> ·
            592: <span class="font-mono">{{ formatMoney(preview.balance_592) }}</span>
          </div>
          <div v-if="suggested !== null">{{ t('accounting.closing.deferred_tax500.suggested') }}: <strong class="font-mono">{{ formatMoney(suggested) }}</strong></div>
        </div>

        <div class="max-w-xs">
          <label class="block text-sm font-medium text-neutral-700 mb-1" :for="`deferred-tax-500-amount-${periodId}`">{{ t('accounting.closing.deferred_tax500.amount') }}</label>
          <input :id="`deferred-tax-500-amount-${periodId}`" v-model.number="amount" type="number" step="0.01" :disabled="!isClosing"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm text-right font-mono" />
          <p class="text-xs text-neutral-500 mt-1">{{ t('accounting.closing.deferred_tax500.amount_hint') }}</p>
        </div>
        <div class="max-w-md">
          <label class="block text-sm font-medium text-neutral-700 mb-1" :for="`deferred-tax-500-reason-${periodId}`">{{ t('accounting.closing.deferred_tax500.reason') }}</label>
          <input :id="`deferred-tax-500-reason-${periodId}`" v-model="reason" type="text" maxlength="500" :disabled="!isClosing"
            class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
        </div>
        <label v-if="isAsset" class="flex items-start gap-2 text-warning-800" data-test="deferred-tax-500-prudence">
          <input v-model="prudence" type="checkbox" :disabled="!isClosing" class="mt-0.5 border-neutral-300 text-primary-600" />
          <span>{{ t('accounting.closing.deferred_tax500.prudence') }}</span>
        </label>
      </template>
    </template>

    <div class="flex flex-wrap gap-2 border-t border-neutral-200 pt-3">
      <button v-if="isClosing && canWrite && preview?.applicable && preview?.computation" @click="book" :disabled="busy" :class="btnFilled('primary')" data-test="deferred-tax-500-book">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
        {{ t('accounting.closing.deferred_tax500.book') }}
      </button>
      <button v-if="isClosing && canWrite && status === 'pending'" @click="emit('skip')" :disabled="busy" :class="btnOutline('neutral')" data-test="deferred-tax-500-skip">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
        {{ t('accounting.closing.step_skip') }}
      </button>
      <button v-if="isClosing && canWrite && status !== 'pending'" @click="emit('revert')" :disabled="busy" :class="btnOutline('danger')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
        {{ t('accounting.closing.revert.button') }}
      </button>
    </div>
    <div v-if="status === 'done'" class="text-success-600">
      {{ t('accounting.closing.step_done_at', { date: formatDate(step?.done_at) }) }}
      <span v-if="payload?.document_no"> · {{ payload.document_no }} · <span class="font-mono">{{ formatMoney(Number(payload.amount ?? 0)) }}</span></span>
    </div>
  </div>
</template>
