<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { stockApi, type StockItemSearchResult } from '@/api/stock'
import { eshopApi, type AttributeOption, type ProductPrice } from '@/api/eshop'
import { purchaseOrdersApi } from '@/api/purchaseOrders'
import { productMastersApi, type ProductDetachPreview, type ProductMaster, type ProductMasterVariant, type ProductVariantInheritance } from '@/api/productMasters'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'

const props = defineProps<{
  master: ProductMaster
  options: Record<number, AttributeOption[]>
  canWrite: boolean
}>()
const emit = defineEmits<{ (event: 'updated', value: ProductMaster): void }>()
const { t } = useI18n()
const toast = useToast()

const query = ref('')
const candidates = ref<StockItemSearchResult[]>([])
const searching = ref(false)
const selected = ref<StockItemSearchResult | null>(null)
const optionValues = ref<Record<number, number | null>>({})
const editing = ref<ProductMasterVariant | null>(null)
const detaching = ref<ProductMasterVariant | null>(null)
const detachPreview = ref<ProductDetachPreview | null>(null)
const saving = ref(false)
const error = ref('')
const prices = ref<Record<number, ProductPrice[]>>({})
const quantities = ref<Record<number, string>>({})
let searchGeneration = 0
let detailsGeneration = 0
let searchTimer: ReturnType<typeof setTimeout> | null = null

const allOptionsSelected = computed(() => props.master.axes.every(axis => Number(optionValues.value[axis.attribute_id]) > 0))

function resetOptions(variant?: ProductMasterVariant) {
  optionValues.value = Object.fromEntries(props.master.axes.map(axis => [axis.attribute_id, variant?.options.find(o => o.attribute_id === axis.attribute_id)?.option_id ?? null]))
}

function defaultInheritance(): ProductVariantInheritance {
  return {
    manufacturer: true,
    i18n: Object.fromEntries(props.master.i18n.map(row => [row.locale, {
      name: true,
      short_desc: true,
      description: true,
      seo_title: true,
      seo_description: true,
    }])),
  }
}

async function search() {
  const generation = ++searchGeneration
  const value = query.value.trim()
  if (value.length < 2) { candidates.value = []; return }
  searching.value = true
  try {
    const rows = await stockApi.searchItems(value, 30)
    if (generation !== searchGeneration) return
    const linked = new Set(props.master.variants.map(v => v.stock_item_id))
    candidates.value = rows.filter(row => !linked.has(row.id))
  } catch (err: any) {
    if (generation === searchGeneration) toast.error(apiErrorMessage(err, t('common.error')))
  } finally {
    if (generation === searchGeneration) searching.value = false
  }
}

function onQuery() {
  if (searchTimer) clearTimeout(searchTimer)
  searchTimer = setTimeout(search, 250)
}

function choose(row: StockItemSearchResult) {
  selected.value = row
  candidates.value = []
  query.value = `${row.sku} - ${row.name}`
  resetOptions()
  error.value = ''
}

async function attach() {
  if (!props.canWrite || !selected.value || !allOptionsSelected.value || saving.value) return
  saving.value = true
  error.value = ''
  try {
    const item = await stockApi.getItem(selected.value.id)
    const updated = await productMastersApi.attachVariants(props.master.id, {
      master_row_version: props.master.row_version,
      variants: [{
        stock_item_id: item.id,
        row_version: item.row_version,
        options: props.master.axes.map(axis => ({ attribute_id: axis.attribute_id, option_id: Number(optionValues.value[axis.attribute_id]) })),
        inheritance: defaultInheritance(),
      }],
    })
    emit('updated', updated)
    selected.value = null
    query.value = ''
    resetOptions()
    toast.success(t('eshop.masters.variant_attached'))
  } catch (err: any) {
    error.value = apiErrorMessage(err, t('common.error'))
  } finally {
    saving.value = false
  }
}

function openOptions(variant: ProductMasterVariant) {
  editing.value = variant
  resetOptions(variant)
  error.value = ''
}

async function saveOptions() {
  if (!editing.value || !allOptionsSelected.value || saving.value || !props.canWrite) return
  saving.value = true
  error.value = ''
  try {
    const updated = await productMastersApi.updateVariant(props.master.id, editing.value.stock_item_id, {
      link_row_version: editing.value.link_row_version,
      row_version: editing.value.row_version,
      options: props.master.axes.map(axis => ({ attribute_id: axis.attribute_id, option_id: Number(optionValues.value[axis.attribute_id]) })),
    })
    emit('updated', updated)
    editing.value = null
    toast.success(t('common.saved'))
  } catch (err: any) {
    error.value = apiErrorMessage(err, t('common.error'))
  } finally {
    saving.value = false
  }
}

async function openDetach(variant: ProductMasterVariant) {
  if (!props.canWrite || saving.value) return
  detaching.value = variant
  detachPreview.value = null
  saving.value = true
  error.value = ''
  try {
    detachPreview.value = await productMastersApi.previewDetach(props.master.id, variant.stock_item_id)
  } catch (err: any) {
    error.value = apiErrorMessage(err, t('common.error'))
  } finally {
    saving.value = false
  }
}

async function confirmDetach() {
  if (!detaching.value || !detachPreview.value || saving.value || !props.canWrite) return
  saving.value = true
  error.value = ''
  try {
    await productMastersApi.detachVariant(props.master.id, detaching.value.stock_item_id, {
      link_row_version: detachPreview.value.link_row_version,
      row_version: detachPreview.value.row_version,
    })
    emit('updated', await productMastersApi.get(props.master.id))
    detaching.value = null
    detachPreview.value = null
    toast.success(t('eshop.masters.variant_detached'))
  } catch (err: any) {
    error.value = apiErrorMessage(err, t('common.error'))
  } finally {
    saving.value = false
  }
}

function optionLabel(variant: ProductMasterVariant, attributeId: number): string {
  const id = variant.options.find(o => o.attribute_id === attributeId)?.option_id
  return props.options[attributeId]?.find(o => o.id === id)?.label ?? '-'
}

function priceLabel(itemId: number): string {
  const rows = prices.value[itemId] ?? []
  if (!rows.length) return '-'
  return rows.map(row => `${row.computed_price ?? row.fixed_price ?? '-'} ${row.currency_code}`).join(', ')
}

async function loadEconomics() {
  const ids = props.master.variants.map(v => v.stock_item_id)
  const generation = ++detailsGeneration
  if (!ids.length) { prices.value = {}; quantities.value = {}; return }
  const [priceRows, qtyRows] = await Promise.all([
    Promise.all(ids.map(async id => [id, await eshopApi.getPrices(id).catch(() => [])] as const)),
    purchaseOrdersApi.quantities(ids).catch(() => ({ items: [] })),
  ])
  if (generation !== detailsGeneration) return
  prices.value = Object.fromEntries(priceRows)
  quantities.value = Object.fromEntries(qtyRows.items.map(row => [row.stock_item_id, row.sellable]))
}

watch(() => props.master.variants.map(v => `${v.stock_item_id}:${v.row_version}:${v.link_row_version}`).join(','), loadEconomics, { immediate: true })
onBeforeUnmount(() => { searchGeneration++; detailsGeneration++; if (searchTimer) clearTimeout(searchTimer) })
</script>

<template>
  <section class="space-y-4">
    <div>
      <h2 class="text-lg font-semibold">{{ t('eshop.masters.variants') }}</h2>
      <p class="text-sm text-neutral-500">{{ t('eshop.masters.variants_hint') }}</p>
    </div>

    <div v-if="canWrite" class="rounded-lg border border-neutral-200 bg-neutral-50 p-4">
      <label class="block text-sm font-medium text-neutral-700">
        {{ t('eshop.masters.attach_existing') }}
        <input v-model="query" type="search" @input="onQuery" :placeholder="t('eshop.masters.search_product')" class="mt-1 h-10 w-full rounded-md border border-neutral-300 bg-surface px-3" />
      </label>
      <p v-if="searching" class="mt-2 text-xs text-neutral-500">{{ t('common.loading') }}</p>
      <div v-else-if="candidates.length" class="mt-1 max-h-52 overflow-y-auto rounded-md border border-neutral-200 bg-surface shadow-lg">
        <button v-for="candidate in candidates" :key="candidate.id" type="button" @click="choose(candidate)" class="flex w-full cursor-pointer items-center gap-3 border-b border-neutral-100 px-3 py-2 text-left text-sm last:border-0 hover:bg-primary-50"><span class="font-mono text-xs text-neutral-500">{{ candidate.sku }}</span><span>{{ candidate.name }}</span></button>
      </div>
      <div v-if="selected" class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-3">
        <label v-for="axis in master.axes" :key="axis.attribute_id" class="block text-sm"><span class="mb-1 block text-xs font-medium text-neutral-500">{{ axis.name }}</span><select v-model="optionValues[axis.attribute_id]" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-2"><option :value="null">{{ t('eshop.item.none') }}</option><option v-for="option in options[axis.attribute_id] ?? []" :key="option.id" :value="option.id">{{ option.label }}</option></select></label>
        <div class="flex items-end"><button type="button" @click="attach" :disabled="saving || !allOptionsSelected" :class="btnFilled('success')"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>{{ t('eshop.masters.attach') }}</button></div>
      </div>
      <p v-if="selected && !allOptionsSelected" class="mt-2 text-xs text-warning-700">{{ t('eshop.masters.options_required') }}</p>
      <p v-if="error" class="mt-2 text-sm text-danger-600">{{ error }}</p>
    </div>

    <EmptyState v-if="master.variants.length === 0" boxed icon="box" :title="t('eshop.masters.no_variants')" :message="t('eshop.masters.no_variants_hint')" />
    <div v-else class="overflow-hidden rounded-lg border border-neutral-200 bg-surface">
      <div class="overflow-x-auto"><table class="w-full text-sm"><thead class="bg-neutral-50 text-xs uppercase text-neutral-500"><tr><th class="px-3 py-2 text-left">{{ t('stock.items.field_sku') }}</th><th class="px-3 py-2 text-left">{{ t('stock.items.field_name') }}</th><th v-for="axis in master.axes" :key="axis.attribute_id" class="px-3 py-2 text-left">{{ axis.name }}</th><th class="px-3 py-2 text-left">{{ t('eshop.masters.price') }}</th><th class="px-3 py-2 text-right">{{ t('eshop.masters.stock') }}</th><th class="w-52 px-3 py-2"></th></tr></thead>
        <tbody class="divide-y divide-neutral-100"><tr v-for="variant in master.variants" :key="variant.stock_item_id"><td class="px-3 py-2 font-mono text-xs">{{ variant.sku }}</td><td class="px-3 py-2"><div class="font-medium">{{ variant.effective_name }}</div><div v-if="variant.effective_name !== variant.own_name" class="text-xs text-neutral-500">{{ t('eshop.masters.own_value') }}: {{ variant.own_name }}</div></td><td v-for="axis in master.axes" :key="axis.attribute_id" class="px-3 py-2">{{ optionLabel(variant, axis.attribute_id) }}</td><td class="px-3 py-2 font-mono text-xs">{{ priceLabel(variant.stock_item_id) }}</td><td class="px-3 py-2 text-right font-mono">{{ quantities[variant.stock_item_id] ?? '-' }}</td><td class="px-3 py-2"><div class="flex flex-wrap justify-end gap-2"><RouterLink :to="`/stock/items/${variant.stock_item_id}/edit?tab=master`" :class="btnOutlineSm('primary')"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="canWrite ? ICONS.edit : ICONS.eye" /></svg>{{ t('common.detail') }}</RouterLink><button v-if="canWrite" type="button" @click="openOptions(variant)" :class="btnOutlineSm('neutral')"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.tag" /></svg>{{ t('eshop.masters.options') }}</button><button v-if="canWrite" type="button" @click="openDetach(variant)" :class="btnOutlineSm('danger')"><svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>{{ t('eshop.masters.detach') }}</button></div></td></tr></tbody></table></div>
    </div>

    <Modal v-if="editing" :title="t('eshop.masters.edit_options')" width-class="max-w-lg" @close="editing = null">
      <div class="space-y-3"><label v-for="axis in master.axes" :key="axis.attribute_id" class="block text-sm"><span class="mb-1 block font-medium text-neutral-700">{{ axis.name }}</span><select v-model="optionValues[axis.attribute_id]" class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3"><option :value="null">{{ t('eshop.item.none') }}</option><option v-for="option in options[axis.attribute_id] ?? []" :key="option.id" :value="option.id">{{ option.label }}</option></select></label><p v-if="error" class="text-sm text-danger-600">{{ error }}</p></div>
      <template #footer><div class="flex flex-wrap justify-end gap-2"><button type="button" @click="editing = null" :class="btnOutline('neutral')"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button><button type="button" @click="saveOptions" :disabled="saving || !allOptionsSelected" :class="btnFilled('success')"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>{{ t('common.save') }}</button></div></template>
    </Modal>
    <Modal v-if="detaching" :title="t('eshop.masters.detach_title')" width-class="max-w-2xl" @close="detaching = null; detachPreview = null">
      <div class="space-y-4">
        <p class="text-sm">{{ t('eshop.masters.detach_confirm', { name: detaching.effective_name }) }}</p>
        <div v-if="saving && !detachPreview" class="py-6 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
        <div v-else-if="detachPreview" class="rounded-md border border-warning-200 bg-warning-50 p-3">
          <h3 class="mb-2 text-sm font-semibold text-warning-800">{{ t('eshop.masters.detach_materializes') }}</h3>
          <p class="text-sm">{{ t('eshop.masters.field_manufacturer') }}: <strong>{{ detachPreview.materialized.manufacturer_id ?? '-' }}</strong></p>
          <div v-for="row in detachPreview.materialized.i18n" :key="row.locale" class="mt-2 text-sm">
            <strong>{{ row.locale.toUpperCase() }}</strong>
            <dl class="mt-1 grid grid-cols-[auto_1fr] gap-x-3 text-xs">
              <template v-for="field in ['name', 'short_desc', 'description', 'seo_title', 'seo_description'] as const" :key="field"><dt class="text-neutral-500">{{ t(`eshop.masters.content_field.${field}`) }}</dt><dd class="min-w-0 truncate">{{ row[field] || '-' }}</dd></template>
            </dl>
          </div>
        </div>
        <p v-if="error" class="text-sm text-danger-600">{{ error }}</p>
      </div>
      <template #footer><div class="flex flex-wrap justify-end gap-2"><button type="button" @click="detaching = null; detachPreview = null" :class="btnOutline('neutral')"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button><button type="button" @click="confirmDetach" :disabled="saving || !detachPreview" :class="btnFilled('danger')"><svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>{{ t('eshop.masters.detach') }}</button></div></template>
    </Modal>
  </section>
</template>
