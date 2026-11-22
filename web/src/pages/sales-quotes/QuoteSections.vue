<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { quoteItemKinds, quoteSectionKinds, type QuoteItem, type QuoteSection } from '@/api/salesQuotes'
import type { VatRate } from '@/api/codebooks'
import { stockApi, type StockItemSearchResult, type Warehouse } from '@/api/stock'
import { priceListApi, type PriceListItem } from '@/api/priceList'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney, formatNumber } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { appIsoDate } from '@/utils/date'
import { isTimeItem } from '@/utils/timeBilling'
import QuoteMarkdownEditor from './QuoteMarkdownEditor.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import StockDescriptionField from '@/components/ui/StockDescriptionField.vue'
import DurationInput from '@/components/ui/DurationInput.vue'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { addTotals, lineTotals, sectionTotals } from './quotePresentation'

const sections = defineModel<QuoteSection[]>({ required: true })
const props = defineProps<{
  editable: boolean
  images: Array<{ id: number; name: string; url: string }>
  stockEnabled: boolean
  canUsePriceList: boolean
  stockWarehouses: Warehouse[]
  vatRates: VatRate[]
  isVatPayer: boolean
  clientId: number | null
  currencyId: number | null
  currency: string
  pricesIncludeVat: boolean
  reverseCharge: boolean
  accepted: boolean
  acceptedSections: string[]
}>()

const { t, locale } = useI18n()
const toast = useToast()
const stockOptions = ref<StockItemSearchResult[]>([])
const stockLookup = ref(false)
const knownStock = new Map<number, StockItemSearchResult>()
const stockSelections = ref(new Map<string, string>())
const priceList = ref<PriceListItem[]>([])
const pricesLoading = ref(false)
let searchingStock = 0
let searchingPrices = 0
const inputClass = 'h-9 w-full rounded-md border border-neutral-300 bg-surface px-2 text-sm'
const totals = computed(() => addTotals(sections.value
  .filter(section => section.kind === 'required' || (props.accepted && props.acceptedSections.includes(section.id)))
  .map(section => sectionTotals(section, props.pricesIncludeVat, !props.editable, props.reverseCharge))))
const stockPickerOptions = computed(() => stockOptions.value.map(card => ({
  value: card.id,
  label: `${card.sku} — ${card.name}`,
  secondary: card.item_type ? t(`sales_quotes.card_kind.${card.item_type}`) : undefined,
})))
const pricePickerOptions = computed(() => priceList.value.map(item => ({ value: item.id, label: item.name, secondary: item.code })))

function blankItem(): QuoteItem {
  const rate = props.vatRates.find(row => row.is_default && !row.is_reverse_charge)
  return {
    id: crypto.randomUUID(), kind: 'work', description: '', quantity: '1', unit: 'ks',
    unit_price: '0', discount_percent: '0', vat_rate: props.isVatPayer ? rate?.rate_percent ?? 0 : 0,
    vat_rate_id: props.isVatPayer ? rate?.id ?? null : null, stock_item_id: null, warehouse_id: null,
  }
}

function addSection() {
  sections.value.push({ id: crypto.randomUUID(), title: '', kind: 'required', markdown: '', items: [] })
}

function moveSection(index: number, offset: number) {
  const neighbor = sections.value[index + offset]
  const selected = sections.value[index]
  if (neighbor && selected) {
    sections.value.splice(index + offset, 1, selected)
    sections.value.splice(index, 1, neighbor)
  }
}

function selectedStock(item: QuoteItem) {
  const card = item.stock_item_id != null ? knownStock.get(item.stock_item_id) : undefined
  if (card) return { value: card.id, label: `${card.sku} — ${card.name}` }
  return item.stock_item_id == null ? null : { value: item.stock_item_id, label: `#${item.stock_item_id} — ${item.description}` }
}

async function searchStock(q: string) {
  const current = ++searchingStock
  stockLookup.value = true
  try {
    const results = await stockApi.searchItems(q, 30)
    if (current !== searchingStock) return
    stockOptions.value = results
    for (const card of results) knownStock.set(card.id, card)
  } catch (e) {
    if (current === searchingStock) toast.error(apiErrorMessage(e))
  } finally {
    if (current === searchingStock) stockLookup.value = false
  }
}

async function selectStock(item: QuoteItem, id: number | null) {
  item.stock_item_id = id
  item.warehouse_id = null
  if (id === null) {
    stockSelections.value.delete(item.id)
    return
  }
  const card = knownStock.get(id)
  if (!card) return
  item.description = card.name
  item.kind = card.item_type === 'service' ? 'work' : card.item_type === 'material' ? 'material' : 'product'
  item.unit = card.unit
  item.duration_minutes = null
  item.vat_rate_id = props.isVatPayer ? card.vat_rate_id : null
  item.vat_rate = props.vatRates.find(rate => rate.id === item.vat_rate_id)?.rate_percent ?? 0
  item.discount_percent = '0'
  item.warehouse_id = card.is_stocked ? props.stockWarehouses[0]?.id ?? null : null
  item.unit_price = ''
  const initialPrice = item.unit_price
  const key = crypto.randomUUID()
  stockSelections.value.set(item.id, key)
  try {
    const resolved = await stockApi.quoteItems({
      client_id: props.clientId, currency: props.currency, date: appIsoDate(),
      lines: [{ key, stock_item_id: id, unit: card.unit, quantity: String(item.quantity) }],
    })
    if (stockSelections.value.get(item.id) !== key || item.unit_price !== initialPrice) return
    const price = resolved.lines[0]?.unit_price
    if (price == null) {
      item.unit_price = '0'
      toast.warning(t('sales_quotes.catalog_price_missing'))
      return
    }
    const multiplier = props.pricesIncludeVat ? 1 + Number(item.vat_rate) / 100 : 1
    item.unit_price = (Number(price) * multiplier).toFixed(6)
  } catch (e) {
    if (stockSelections.value.get(item.id) === key) toast.error(apiErrorMessage(e))
  } finally {
    if (stockSelections.value.get(item.id) === key) stockSelections.value.delete(item.id)
  }
}

async function searchPrices(query: string) {
  const current = ++searchingPrices
  pricesLoading.value = true
  try {
    const result = await priceListApi.list({
      q: query, currency: props.currency, client_id: props.clientId ?? undefined,
      rate_date: appIsoDate(), prices_include_vat: props.pricesIncludeVat, per_page: 30,
    })
    if (current === searchingPrices) priceList.value = result.data
  } catch (e) {
    if (current === searchingPrices) toast.error(apiErrorMessage(e))
  } finally {
    if (current === searchingPrices) pricesLoading.value = false
  }
}

async function addPriceItem(section: QuoteSection, id: number | null) {
  if (id === null || !props.currencyId) return
  try {
    const resolved = await priceListApi.resolve(id, {
      client_id: props.clientId ?? undefined, currency_id: props.currencyId,
      rate_date: appIsoDate(), prices_include_vat: props.pricesIncludeVat,
    })
    const item = blankItem()
    item.description = resolved.description || resolved.name
    item.unit = resolved.unit
    item.vat_rate = props.isVatPayer ? resolved.vat_rate_percent : 0
    item.vat_rate_id = props.isVatPayer ? resolved.vat_rate_id : null
    item.unit_price = resolved.unit_price_without_vat
    section.items.push(item)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

function changeVat(item: QuoteItem, event: Event) {
  const rate = props.vatRates.find(value => value.id === Number((event.target as HTMLSelectElement).value))
  item.vat_rate_id = rate?.id ?? null
  item.vat_rate = rate?.rate_percent ?? 0
}

function isTime(item: QuoteItem): boolean {
  return item.kind === 'work' && isTimeItem(item)
}

function warehouseAllowed(item: QuoteItem): boolean {
  const card = item.stock_item_id != null ? knownStock.get(item.stock_item_id) : undefined
  return props.stockEnabled && item.stock_item_id != null && (card?.is_stocked ?? item.kind !== 'work')
}

function linePrice(item: QuoteItem) {
  return item.totals ?? lineTotals(item, props.pricesIncludeVat, props.reverseCharge)
}
</script>

<template>
  <section class="space-y-3" :aria-label="t('sales_quotes.sections')">
    <section v-for="(section, index) in sections" :key="section.id" class="space-y-3 rounded-lg border border-neutral-200 bg-surface p-3 sm:p-4">
      <header class="flex flex-wrap items-start gap-2">
        <div v-if="editable" class="min-w-44 flex-1">
          <label class="mb-1 block text-xs font-medium text-neutral-600" :for="`quote-section-${section.id}`">{{ t('sales_quotes.section_title') }}</label>
          <input :id="`quote-section-${section.id}`" v-model="section.title" required maxlength="190" :class="inputClass" />
        </div>
        <h2 v-else class="flex-1 text-lg font-semibold text-neutral-900">{{ section.title }}</h2>
        <label v-if="editable" class="text-xs font-medium text-neutral-600">
          <span class="mb-1 block">{{ t('sales_quotes.section_kind') }}</span>
          <select v-model="section.kind" :class="inputClass">
            <option v-for="kind in quoteSectionKinds" :key="kind" :value="kind" :disabled="kind === 'info' && section.items.length > 0">{{ t(`sales_quotes.section_kinds.${kind}`) }}</option>
          </select>
        </label>
        <span v-else class="rounded bg-neutral-100 px-2 py-1 text-xs font-medium text-neutral-700">
          {{ t(`sales_quotes.section_kinds.${section.kind}`) }}
          <span v-if="accepted && section.kind === 'optional' && acceptedSections.includes(section.id)"> · {{ t('sales_quotes.status.accepted') }}</span>
        </span>
        <div v-if="editable" class="mt-4 flex flex-wrap gap-1">
          <button type="button" :class="btnOutlineSm('neutral')" :disabled="index === 0" :title="t('sales_quotes.move_up')" :aria-label="t('sales_quotes.move_up')" @click="moveSection(index, -1)">
            <svg class="size-4 rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>
          </button>
          <button type="button" :class="btnOutlineSm('neutral')" :disabled="index === sections.length - 1" :title="t('sales_quotes.move_down')" :aria-label="t('sales_quotes.move_down')" @click="moveSection(index, 1)">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>
          </button>
          <button type="button" :class="btnOutlineSm('danger')" :title="t('sales_quotes.remove_section')" :aria-label="t('sales_quotes.remove_section')" @click="sections.splice(index, 1)">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
          </button>
        </div>
      </header>

      <QuoteMarkdownEditor v-if="editable || section.markdown" v-model="section.markdown" :disabled="!editable" :images="images" :rows="3" :placeholder="t('sales_quotes.section_text')" />
      <template v-if="section.kind !== 'info'">
        <div v-for="(item, itemIndex) in section.items" :key="item.id"
          class="grid grid-cols-2 items-start gap-2 rounded-md border border-neutral-200 p-2 text-sm sm:grid-cols-4 xl:grid-cols-[minmax(8rem,1fr)_minmax(10rem,2fr)_6rem_5rem_7rem_5rem_7rem_auto]">
          <label v-if="editable" class="col-span-1 text-xs font-medium text-neutral-600">
            <span class="mb-1 block">{{ t('sales_quotes.item_kind') }}</span>
            <select v-model="item.kind" :class="inputClass" :disabled="!!item.stock_item_id" @change="!isTime(item) && (item.duration_minutes = null)">
              <option v-for="kind in quoteItemKinds" :key="kind" :value="kind">{{ t(`sales_quotes.item_kinds.${kind}`) }}</option>
            </select>
          </label>
          <span v-else class="text-xs text-neutral-600">{{ t(`sales_quotes.item_kinds.${item.kind}`) }}</span>

          <div v-if="editable" class="min-w-0">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('sales_quotes.description') }}</span>
            <StockDescriptionField v-model:description="item.description" :stock-item-id="item.stock_item_id ?? null" :stock-enabled="stockEnabled"
              :options="stockPickerOptions" :selected-option="selectedStock(item)" :loading="stockLookup" :placeholder="t('sales_quotes.description')"
              :loading-label="t('common.loading')" :no-results-label="t('sales_quotes.no_catalog_items')"
              :keep-free-text-label="t('sales_quotes.manual_item')" :unlink-label="t('sales_quotes.unlink_card')"
              @search="searchStock" @select="selectStock(item, $event)" />
            <select v-if="warehouseAllowed(item)" v-model="item.warehouse_id" class="mt-1 h-7 w-full rounded border border-neutral-300 bg-surface px-1 text-xs" :aria-label="t('sales_quotes.warehouse')">
              <option :value="null">{{ t('sales_quotes.no_warehouse') }}</option>
              <option v-for="warehouse in stockWarehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option>
            </select>
          </div>
          <div v-else class="whitespace-pre-line font-medium">{{ item.description }}</div>

          <label v-if="editable" class="text-xs font-medium text-neutral-600">
            <span class="mb-1 block">{{ t('sales_quotes.quantity') }}</span>
            <DurationInput v-if="isTime(item)" v-model="item.quantity" v-model:duration-minutes="item.duration_minutes" />
            <input v-else v-model="item.quantity" required type="number" min="0.001" step="0.001" :class="[inputClass, 'text-right']" />
          </label>
          <span v-else class="tabular-nums">{{ formatNumber(Number(item.quantity), { maximumFractionDigits: 3 }) }}</span>
          <label v-if="editable" class="text-xs font-medium text-neutral-600">
            <span class="mb-1 block">{{ t('sales_quotes.unit') }}</span>
            <input v-model="item.unit" maxlength="20" :class="inputClass" @input="!isTime(item) && (item.duration_minutes = null)" />
          </label>
          <span v-else>{{ item.unit }}</span>
          <label v-if="editable" class="text-xs font-medium text-neutral-600">
            <span class="mb-1 block">{{ t('sales_quotes.unit_price') }}</span>
            <input v-model="item.unit_price" required type="number" min="0" step="0.000001" :class="[inputClass, 'text-right']" />
          </label>
          <span v-else class="whitespace-nowrap tabular-nums">{{ formatMoney(item.unit_price, currency, undefined, locale) }}</span>
          <label v-if="editable" class="text-xs font-medium text-neutral-600">
            <span class="mb-1 block">{{ t('sales_quotes.discount') }}</span>
            <input v-model="item.discount_percent" required type="number" min="0" max="100" step="0.01" :class="[inputClass, 'text-right']" />
          </label>
          <span v-else class="text-neutral-600">{{ Number(item.discount_percent) }} %</span>
          <label v-if="editable" class="text-xs font-medium text-neutral-600">
            <span class="mb-1 block">{{ t('sales_quotes.vat') }}</span>
            <select :value="item.vat_rate_id ?? ''" :disabled="!isVatPayer" :class="inputClass" @change="changeVat(item, $event)">
              <option value="">{{ t('sales_quotes.vat_zero') }}</option>
              <option v-for="rate in vatRates.filter(rate => !rate.is_reverse_charge || rate.id === item.vat_rate_id)" :key="rate.id" :value="rate.id">{{ rate.rate_percent }} % · {{ rate.code }}</option>
            </select>
          </label>
          <span v-else>{{ Number(item.vat_rate) }} %</span>

          <div class="flex flex-wrap items-center justify-between gap-2 sm:justify-end">
            <span v-if="!editable" class="whitespace-nowrap font-medium tabular-nums">{{ formatMoney(linePrice(item).with_vat, currency) }}</span>
            <button v-else type="button" :class="[btnOutlineSm('danger'), 'mt-4']" :aria-label="t('sales_quotes.remove_item')" :title="t('sales_quotes.remove_item')" @click="section.items.splice(itemIndex, 1); stockSelections.delete(item.id)">
              <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.trash" /></svg>
            </button>
          </div>
        </div>

        <div v-if="editable" class="flex flex-wrap items-center gap-2">
          <button type="button" :class="[btnOutlineSm('primary'), 'whitespace-nowrap']" @click="section.items.push(blankItem())">
            <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
            {{ t('sales_quotes.add_item') }}
          </button>
          <SearchableSelect v-if="canUsePriceList" :model-value="null" :options="pricePickerOptions" remote :loading="pricesLoading"
            :disabled="!currencyId" :placeholder="t('sales_quotes.add_catalog_item')" :aria-label="t('sales_quotes.add_catalog_item')"
            @search="searchPrices" @update:model-value="addPriceItem(section, $event)" />
        </div>

        <dl v-if="section.items.length" class="flex flex-wrap justify-end gap-x-4 gap-y-1 border-t border-neutral-200 pt-2 text-sm">
          <div class="flex items-baseline gap-2">
            <dt class="text-neutral-600">{{ t('sales_quotes.subtotal') }}</dt>
            <dd class="whitespace-nowrap font-semibold tabular-nums">{{ formatMoney(sectionTotals(section, pricesIncludeVat, !editable, reverseCharge).with_vat, currency) }}</dd>
          </div>
        </dl>
      </template>
    </section>

    <button v-if="editable" type="button" :class="[btnOutlineSm('primary'), 'whitespace-nowrap']" @click="addSection">
      <svg class="size-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
      {{ t('sales_quotes.add_section') }}
    </button>
    <dl v-if="sections.length" class="flex flex-wrap justify-end gap-5 rounded-lg border border-neutral-200 bg-surface px-4 py-3 text-sm">
      <div><dt class="text-neutral-600">{{ t('sales_quotes.without_vat') }}</dt><dd class="font-medium tabular-nums">{{ formatMoney(totals.without_vat, currency) }}</dd></div>
      <div><dt class="text-neutral-600">{{ t('sales_quotes.vat') }}</dt><dd class="font-medium tabular-nums">{{ formatMoney(totals.vat, currency) }}</dd></div>
      <div><dt class="text-neutral-600">{{ t(accepted ? 'sales_quotes.approved_total' : 'sales_quotes.required_total') }}</dt><dd class="font-semibold tabular-nums">{{ formatMoney(totals.with_vat, currency) }}</dd></div>
    </dl>
  </section>
</template>
