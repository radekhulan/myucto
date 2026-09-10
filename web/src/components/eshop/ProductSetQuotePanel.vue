<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { eshopApi, type ProductSetCard, type ProductSetDefinition, type ProductSetQuoteResponse } from '@/api/eshop'
import { btnFilled } from '@/components/ui/buttonStyles'
import { resolveActiveProductSet, type ProductSetSelections } from '@/utils/productSetSelections'

defineOptions({ name: 'ProductSetQuotePanel' })

const props = defineProps<{
  itemId: number
  definition: ProductSetDefinition
  definitions: Record<string, ProductSetDefinition>
  cards: ProductSetCard[]
  currencies: string[]
}>()

const { t } = useI18n()
const selections = ref<ProductSetSelections>({})
const currencyCode = ref('')
const quantity = ref('1')
const loading = ref(false)
const error = ref('')
const result = ref<ProductSetQuoteResponse | null>(null)
const quoteGeneration = ref(0)

const definitionMap = computed(() => ({ ...props.definitions, [String(props.itemId)]: props.definition }))
const activeSet = computed(() => resolveActiveProductSet(props.itemId, definitionMap.value, selections.value))
const activeDefinitions = computed(() => activeSet.value.definitions)
const cardMap = computed(() => new Map(props.cards.map(card => [card.id, card])))
const selectionsValid = computed(() => activeDefinitions.value.every(({ itemId, definition }) =>
  definition.groups.every(group => groupComplete(itemId, group)),
))

function invalidateQuote() {
  quoteGeneration.value++
  loading.value = false
  result.value = null
  error.value = ''
}

watch([currencyCode, quantity], invalidateQuote)
watch([() => props.itemId, () => props.definition, () => props.definitions], () => {
  selections.value = {}
  invalidateQuote()
}, { deep: true })

function picked(itemId: number, groupCode: string): string[] {
  return selections.value[String(itemId)]?.[groupCode] ?? []
}

function toggle(itemId: number, groupCode: string, optionCode: string, max: number) {
  const perSet = selections.value[String(itemId)] ?? (selections.value[String(itemId)] = {})
  const current = perSet[groupCode] ?? []
  if (max === 1) {
    perSet[groupCode] = current[0] === optionCode ? [] : [optionCode]
  } else if (current.includes(optionCode)) {
    perSet[groupCode] = current.filter(code => code !== optionCode)
  } else if (current.length < max) {
    perSet[groupCode] = [...current, optionCode]
  }
  invalidateQuote()
}

function optionLabel(itemId: number) {
  const card = cardMap.value.get(itemId)
  return card ? `${card.sku} - ${card.name}` : `#${itemId}`
}

function groupComplete(itemId: number, group: ProductSetDefinition['groups'][number]) {
  const count = picked(itemId, group.code).length
  return count >= group.min && count <= group.max
}

async function quote() {
  invalidateQuote()
  if (!currencyCode.value || !/^\d{1,11}(?:\.\d{1,3})?$/.test(quantity.value) || Number(quantity.value) <= 0 || !selectionsValid.value) {
    error.value = t('eshop.sets.quote_invalid')
    return
  }

  const requestGeneration = quoteGeneration.value
  const requestItemId = props.itemId
  loading.value = true
  try {
    const response = await eshopApi.quoteProductSet(requestItemId, {
      currency_code: currencyCode.value,
      quantity: quantity.value,
      selections: activeSet.value.selections,
    })
    if (requestGeneration === quoteGeneration.value && requestItemId === props.itemId) result.value = response
  } catch (e: any) {
    if (requestGeneration === quoteGeneration.value && requestItemId === props.itemId) {
      error.value = e?.response?.data?.error?.message || t('eshop.sets.quote_failed')
    }
  } finally {
    if (requestGeneration === quoteGeneration.value && requestItemId === props.itemId) loading.value = false
  }
}
</script>

<template>
  <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
    <header class="border-b border-neutral-200 px-4 py-3">
      <h2 class="font-semibold text-neutral-900">{{ t('eshop.sets.quote_title') }}</h2>
      <p class="mt-0.5 text-xs text-neutral-500">{{ t('eshop.sets.quote_hint') }}</p>
    </header>

    <div class="space-y-4 p-4">
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="text-sm font-medium text-neutral-700">
          {{ t('eshop.sets.currency') }}
          <select v-model="currencyCode" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            <option value="" disabled>{{ t('eshop.sets.choose_currency') }}</option>
            <option v-for="currency in currencies" :key="currency" :value="currency">{{ currency }}</option>
          </select>
        </label>
        <label class="text-sm font-medium text-neutral-700">
          {{ t('eshop.sets.quantity') }}
          <input v-model="quantity" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" inputmode="decimal" maxlength="15">
        </label>
      </div>

      <div v-for="{ itemId, definition } in activeDefinitions" :key="itemId" class="space-y-3">
        <div v-for="group in definition.groups" :key="`${itemId}-${group.code}`" class="rounded-lg border border-neutral-200 bg-neutral-50/60 p-3 dark:bg-neutral-900/20">
          <div class="mb-2 flex flex-wrap items-baseline justify-between gap-2">
            <div>
              <p class="text-sm font-medium text-neutral-900">{{ group.name }}</p>
              <p class="text-xs text-neutral-500">{{ t('eshop.sets.selection_range', { min: group.min, max: group.max }) }}</p>
            </div>
            <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="groupComplete(itemId, group) ? 'bg-success-50 text-success-700' : 'bg-warning-50 text-warning-700'">
              {{ groupComplete(itemId, group) ? t('eshop.sets.selection_ready') : t('eshop.sets.selection_required') }}
            </span>
          </div>
          <div class="space-y-2">
            <label v-for="option in group.options" :key="option.code" class="flex cursor-pointer items-start gap-2 rounded-md border border-neutral-200 bg-surface p-2 text-sm hover:border-primary-300">
              <input
                :checked="picked(itemId, group.code).includes(option.code)"
                :type="group.max === 1 ? 'radio' : 'checkbox'"
                :name="`${itemId}-${group.code}`"
                class="mt-0.5 rounded border-neutral-300 text-primary-600"
                @change="toggle(itemId, group.code, option.code, group.max)"
              >
              <span class="min-w-0 flex-1">
                <span class="block font-medium text-neutral-900">{{ option.name }}</span>
                <span class="block truncate text-xs text-neutral-500">{{ optionLabel(option.item_id) }} · {{ option.quantity }} {{ cardMap.get(option.item_id)?.unit ?? '' }}</span>
              </span>
              <span v-if="option.surcharges[currencyCode]" class="whitespace-nowrap font-mono text-xs text-primary-700">+{{ option.surcharges[currencyCode] }} {{ currencyCode }}</span>
            </label>
          </div>
        </div>
      </div>

      <p v-if="error" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-700">{{ error }}</p>
      <button type="button" data-test="set-quote" :class="btnFilled('primary')" :disabled="loading" @click="quote">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M12 6v12m6-6H6" /></svg>
        {{ loading ? t('common.loading') : t('eshop.sets.calculate_quote') }}
      </button>

      <div v-if="result" class="rounded-lg border border-success-500/30 bg-success-50/50 p-3">
        <p class="text-xs font-medium uppercase tracking-wide text-success-700">{{ t('eshop.sets.current_quote') }}</p>
        <p class="mt-1 text-xl font-semibold tabular-nums text-neutral-900">{{ result.quote.amount }} {{ result.currency_code }}</p>
        <p class="mt-1 text-xs text-neutral-600">{{ t('eshop.sets.quote_excluding_vat') }}</p>
        <ul class="mt-3 divide-y divide-success-200 text-sm">
          <li v-for="component in result.quote.components" :key="`${component.item_id}-${component.quantity}`" class="flex flex-wrap justify-between gap-2 py-1.5">
            <span>{{ optionLabel(component.item_id) }} · {{ component.quantity }}</span>
            <span class="font-mono">{{ component.amount }} {{ result.currency_code }}</span>
          </li>
        </ul>
      </div>
    </div>
  </section>
</template>
