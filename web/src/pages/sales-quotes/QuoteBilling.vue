<script setup lang="ts">
import { computed, ref, useId } from 'vue'
import { RouterLink, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { salesQuotesApi, type QuoteInvoiceType, type QuoteItem, type SalesQuote } from '@/api/salesQuotes'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney, formatNumber } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import Modal from '@/components/ui/Modal.vue'
import DurationInput from '@/components/ui/DurationInput.vue'
import { useDemoMode } from '@/composables/useDemoMode'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{
  quote: SalesQuote
  canInvoice: boolean
  canReadInvoices: boolean
  canEditInvoices: boolean
  canOrder: boolean
  canReadOrders: boolean
  historical: boolean
}>()

const emit = defineEmits<{ refresh: [] }>()
const router = useRouter()
const formId = useId()
const { t } = useI18n()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()
const invoiceType = ref<QuoteInvoiceType | null>(null)
const selected = ref<string[]>([])
const quantities = ref<Record<string, number | string>>({})
const advanceMode = ref<'whole' | 'percent' | 'amount'>('whole')
const advanceValue = ref<number | null>(null)
const busy = ref(false)
const error = ref('')
let previousKey: { signature: string; key: string } | null = null
let orderKey: string | null = null

const eligibleSections = computed(() => props.quote.sections.filter(section =>
  section.kind === 'required' || (section.kind === 'optional' && props.quote.accepted_section_ids.includes(section.id)),
))
const allItems = computed(() => eligibleSections.value.flatMap(section => section.items))
const availableItems = computed(() => allItems.value.filter(item => maxQuantity(item) > 0))
const billingLabels = computed(() => [
  { label: props.quote.prices_include_vat ? t('sales_quotes.billed_gross') : t('sales_quotes.billed_net'), value: props.quote.billing?.billed },
  { label: t('sales_quotes.in_drafts'), value: props.quote.billing?.draft },
  { label: t('sales_quotes.remaining'), value: props.quote.billing?.remaining },
  { label: t('sales_quotes.advance_requested'), value: props.quote.billing?.advance_requested },
  { label: t('sales_quotes.advance_paid'), value: props.quote.billing?.advance_paid },
])
const invalidQuantity = computed(() => availableItems.value.some(item =>
  selected.value.includes(item.id)
  && (!Number.isFinite(Number(quantities.value[item.id])) || Number(quantities.value[item.id]) <= 0 || Number(quantities.value[item.id]) > maxQuantity(item)),
))
const actions = computed<ActionItem[]>(() => [
  { key: 'invoice', label: t('sales_quotes.create_invoice'), icon: 'doc', tier: 'primary', variant: 'primary',
    show: props.canInvoice && props.canReadInvoices && !props.historical, disabled: busy.value || !availableItems.value.length,
    run: () => openInvoice('invoice') },
  { key: 'advance', label: t('sales_quotes.create_advance'), icon: 'coin', tier: 'secondary', variant: 'neutral',
    show: props.canInvoice && props.canReadInvoices && !props.historical, disabled: busy.value || !availableItems.value.length,
    run: () => openInvoice('proforma') },
  { key: 'order', label: t('sales_quotes.create_order'), icon: 'box', tier: 'secondary', variant: 'neutral',
    show: props.canOrder && !props.quote.order_id && !props.historical, disabled: busy.value, run: createOrder },
])

function maxQuantity(item: QuoteItem): number {
  return Number(item.remaining_quantity ?? item.quantity)
}

function openInvoice(type: QuoteInvoiceType) {
  invoiceType.value = type
  selected.value = availableItems.value.map(item => item.id)
  quantities.value = Object.fromEntries(availableItems.value.map(item => [item.id, maxQuantity(item)]))
  advanceMode.value = 'whole'
  advanceValue.value = null
  error.value = ''
}

function setSection(ids: string[], checked: boolean) {
  const set = new Set(selected.value)
  for (const id of ids) {
    if (checked) set.add(id)
    else set.delete(id)
  }
  selected.value = [...set]
}

async function createInvoice() {
  const type = invoiceType.value
  if (!type || busy.value || invalidQuantity.value || selected.value.length === 0 || blockDemoMutation()) return
  const items = availableItems.value
    .filter(item => selected.value.includes(item.id))
    .map(item => ({ id: item.id, quantity: quantities.value[item.id] ?? maxQuantity(item) }))
  const payload = {
    type, items,
    advance_percent: type === 'proforma' && advanceMode.value === 'percent' ? advanceValue.value ?? undefined : undefined,
    advance_amount: type === 'proforma' && advanceMode.value === 'amount' ? advanceValue.value ?? undefined : undefined,
  }
  const signature = JSON.stringify(payload)
  if (previousKey?.signature !== signature) previousKey = { signature, key: crypto.randomUUID() }
  busy.value = true
  error.value = ''
  try {
    const result = await salesQuotesApi.invoice(props.quote.id, { ...payload, idempotency_key: previousKey.key })
    invoiceType.value = null
    previousKey = null
    emit('refresh')
    toast.success(t('sales_quotes.invoice_created'))
    await router.push({ name: props.canEditInvoices ? 'invoice-edit' : 'invoice-detail', params: { id: result.invoice_id } })
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}

async function createOrder() {
  if (busy.value || blockDemoMutation()) return
  busy.value = true
  error.value = ''
  orderKey ??= crypto.randomUUID()
  try {
    const result = await salesQuotesApi.order(props.quote.id, orderKey)
    emit('refresh')
    toast.success(t('sales_quotes.order_created'))
    await router.push({ name: 'stock-sales-order-detail', params: { id: result.order_id } })
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <section class="space-y-3 rounded-lg border border-neutral-200 bg-surface p-3 sm:p-4">
    <header class="flex flex-wrap items-center justify-between gap-3">
      <h2 class="text-lg font-semibold">{{ t('sales_quotes.billing') }}</h2>
      <ActionBar :actions="actions" />
    </header>
    <p v-if="error && !invoiceType" role="alert" class="text-sm text-danger-700">{{ error }}</p>
    <dl v-if="quote.billing" class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
      <div v-for="entry in billingLabels" :key="entry.label" class="rounded-md bg-neutral-50 p-2 text-sm">
        <dt class="text-neutral-600">{{ entry.label }}</dt>
        <dd class="mt-1 font-semibold tabular-nums">{{ formatMoney(entry.value, quote.currency) }}</dd>
      </div>
    </dl>
    <div v-if="quote.billing && allItems.length" class="hidden overflow-x-auto md:block">
      <table class="w-full text-sm">
        <thead class="text-neutral-600">
          <tr class="[&>th]:whitespace-nowrap [&>th]:px-2 [&>th]:py-1">
            <th class="text-left">{{ t('sales_quotes.description') }}</th>
            <th class="text-right">{{ t('sales_quotes.quantity') }}</th>
            <th class="text-right">{{ t('sales_quotes.billed') }}</th>
            <th class="text-right">{{ t('sales_quotes.in_drafts') }}</th>
            <th class="text-right">{{ t('sales_quotes.remaining') }}</th>
          </tr>
        </thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="item in allItems" :key="item.id" class="[&>td]:px-2 [&>td]:py-1.5">
            <td>{{ item.description }} <span class="text-xs text-neutral-500">({{ item.unit }})</span></td>
            <td class="text-right tabular-nums">{{ formatNumber(Number(item.quantity), { maximumFractionDigits: 3 }) }}</td>
            <td class="text-right tabular-nums">{{ formatNumber(Number(item.billed_quantity ?? 0), { maximumFractionDigits: 3 }) }}</td>
            <td class="text-right tabular-nums">{{ formatNumber(Number(item.draft_quantity ?? 0), { maximumFractionDigits: 3 }) }}</td>
            <td class="text-right tabular-nums">{{ formatNumber(Number(item.remaining_quantity ?? item.quantity), { maximumFractionDigits: 3 }) }}</td>
          </tr>
        </tbody>
      </table>
    </div>
    <ul v-if="quote.billing && allItems.length" class="space-y-2 md:hidden">
      <li v-for="item in allItems" :key="item.id" class="rounded-md border border-neutral-200 p-3 text-sm">
        <p class="font-medium wrap-anywhere">{{ item.description }} <span class="text-xs text-neutral-500">({{ item.unit }})</span></p>
        <dl class="mt-2 grid grid-cols-2 gap-2 text-xs">
          <div><dt class="text-neutral-600">{{ t('sales_quotes.quantity') }}</dt>
            <dd class="font-medium tabular-nums">{{ formatNumber(Number(item.quantity), { maximumFractionDigits: 3 }) }}</dd></div>
          <div><dt class="text-neutral-600">{{ t('sales_quotes.billed') }}</dt>
            <dd class="font-medium tabular-nums">{{ formatNumber(Number(item.billed_quantity ?? 0), { maximumFractionDigits: 3 }) }}</dd></div>
          <div><dt class="text-neutral-600">{{ t('sales_quotes.in_drafts') }}</dt>
            <dd class="font-medium tabular-nums">{{ formatNumber(Number(item.draft_quantity ?? 0), { maximumFractionDigits: 3 }) }}</dd></div>
          <div><dt class="text-neutral-600">{{ t('sales_quotes.remaining') }}</dt>
            <dd class="font-medium tabular-nums">{{ formatNumber(Number(item.remaining_quantity ?? item.quantity), { maximumFractionDigits: 3 }) }}</dd></div>
        </dl>
      </li>
    </ul>
    <ul v-if="quote.documents.length" class="divide-y divide-neutral-200 border-t border-neutral-200 text-sm">
      <li v-for="doc in quote.documents" :key="doc.invoice_id" class="flex flex-wrap items-center justify-between gap-2 py-2">
        <div>
          <RouterLink v-if="canReadInvoices" :to="{ name: 'invoice-detail', params: { id: doc.invoice_id } }" class="font-medium text-primary-700 hover:underline">
            {{ t(`sales_quotes.document_types.${doc.type}`, doc.type) }} {{ doc.number || `#${doc.invoice_id}` }}
          </RouterLink>
          <span v-else class="font-medium">{{ t(`sales_quotes.document_types.${doc.type}`, doc.type) }} {{ doc.number || `#${doc.invoice_id}` }}</span>
          <span class="ml-2 text-xs text-neutral-600">{{ t(`sales_quotes.invoice_status.${doc.status}`, doc.status) }}</span>
        </div>
        <span class="font-medium tabular-nums">{{ formatMoney(doc.amount, quote.currency) }}</span>
      </li>
    </ul>
    <RouterLink v-if="quote.order_id && canReadOrders" :to="{ name: 'stock-sales-order-detail', params: { id: quote.order_id } }"
      class="inline-flex items-center gap-2 text-sm font-medium text-primary-700 hover:underline">
      <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.box" /></svg>
      {{ t('sales_quotes.linked_order') }}
    </RouterLink>
  </section>

  <Modal v-if="invoiceType" :title="t(invoiceType === 'proforma' ? 'sales_quotes.create_advance' : 'sales_quotes.create_invoice')" @close="!busy && (invoiceType = null)">
    <form :id="formId" class="space-y-3" :aria-busy="busy" @submit.prevent="createInvoice">
      <p v-if="error" role="alert" class="rounded-md bg-danger-50 p-2 text-sm text-danger-700">{{ error }}</p>
      <p class="text-sm text-neutral-600">{{ t('sales_quotes.transfer_help') }}</p>
      <fieldset v-for="section in eligibleSections.filter(entry => entry.items.some(item => maxQuantity(item) > 0))" :key="section.id"
        class="space-y-2 rounded-md border border-neutral-200 p-2" :disabled="busy">
        <legend class="px-1 text-sm font-medium">
          <label class="flex items-center gap-2">
            <input type="checkbox" :checked="section.items.filter(item => maxQuantity(item) > 0).every(item => selected.includes(item.id))"
              @change="setSection(section.items.filter(item => maxQuantity(item) > 0).map(item => item.id), ($event.target as HTMLInputElement).checked)" />
            {{ section.title }}
          </label>
        </legend>
        <div v-for="item in section.items.filter(entry => maxQuantity(entry) > 0)" :key="item.id" class="flex flex-wrap items-center justify-between gap-2 text-sm">
          <label class="flex flex-1 items-start gap-2">
            <input v-model="selected" type="checkbox" :value="item.id" class="mt-0.5" />
            {{ item.description }}
          </label>
          <label class="flex items-center gap-2">
            <DurationInput v-if="item.duration_minutes != null && selected.includes(item.id)" :model-value="quantities[item.id] ?? 0"
              :duration-minutes="Math.round(Number(quantities[item.id] ?? 0) * 60)" class="max-w-24"
              @update:model-value="quantities[item.id] = Number($event.toFixed(3))" />
            <input v-else v-model="quantities[item.id]" type="number" :min="0.001" :max="maxQuantity(item)" step="0.001" required
              class="h-8 w-24 rounded-md border border-neutral-300 bg-surface px-2 text-right"
              :aria-label="t('sales_quotes.quantity') + ' · ' + item.description" :disabled="!selected.includes(item.id)" />
            <span class="w-20 text-xs text-neutral-600">/ {{ formatNumber(maxQuantity(item), { maximumFractionDigits: 3 }) }} {{ item.unit }}</span>
          </label>
        </div>
      </fieldset>
      <div v-if="invoiceType === 'proforma'" class="grid grid-cols-1 gap-2 sm:grid-cols-2">
        <label class="text-sm">
          <span class="mb-1 block font-medium">{{ t('sales_quotes.advance_mode') }}</span>
          <select v-model="advanceMode" :disabled="busy" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2">
            <option value="whole">{{ t('sales_quotes.advance_whole') }}</option>
            <option value="percent">{{ t('sales_quotes.advance_percent') }}</option>
            <option value="amount">{{ t('sales_quotes.advance_amount') }}</option>
          </select>
        </label>
        <label v-if="advanceMode !== 'whole'" class="text-sm">
          <span class="mb-1 block font-medium">{{ t(advanceMode === 'percent' ? 'sales_quotes.advance_percent' : 'sales_quotes.advance_amount') }}</span>
          <input v-model="advanceValue" required type="number" min="0.01" step="0.01" :max="advanceMode === 'percent' ? 100 : undefined" :disabled="busy"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
        </label>
      </div>
    </form>
    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="invoiceType = null">
          <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button type="submit" :form="formId" :class="btnFilled('primary')" :disabled="busy || !selected.length || invalidQuantity">
          <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg>
          {{ t('sales_quotes.create_draft') }}
        </button>
      </div>
    </template>
  </Modal>
</template>
