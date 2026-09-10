<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { eshopApi, type ProductSetCard, type ProductSetDefinition } from '@/api/eshop'
import {
  stockApi,
  type ProductAssembly,
  type StockItem,
  type StockItemSearchResult,
  type StockTrackingOverview,
  type TrackingAllocationInput,
  type Warehouse,
  type WarehouseLocation,
} from '@/api/stock'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { remapRootProductSetSelections, resolveActiveProductSet, type ProductSetSelections } from '@/utils/productSetSelections'

interface LoadedRecipe {
  id: number
  definition: ProductSetDefinition
  definitions: Record<string, ProductSetDefinition>
  cards: ProductSetCard[]
}

interface ProductTrackingRow {
  serial_number: string
  lot_code: string
  expires_on: string
  location_id: number | null
}

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const canWrite = computed(() => auth.canWrite('stock.documents.write'))
const FIELD = 'mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20'

const assemblies = ref<ProductAssembly[]>([])
const page = ref(1)
const pages = ref(1)
const total = ref(0)
const loading = ref(true)
const error = ref('')
const warehouses = ref<Warehouse[]>([])
const results = ref<StockItemSearchResult[]>([])
const searchLoading = ref(false)
const targetPick = ref<number | null>(null)
const recipePick = ref<number | null>(null)
const target = ref<StockItem | null>(null)
const recipe = ref<LoadedRecipe | null>(null)
const recipeItem = ref<StockItem | null>(null)
const warehouseId = ref<number | null>(null)
const docDate = ref(new Date().toISOString().slice(0, 10))
const quantity = ref('1')
const selections = ref<ProductSetSelections>({})
const availability = ref<Record<string, string>>({})
const componentTracking = ref<Record<string, StockTrackingOverview>>({})
const componentTrackingQuantities = ref<Record<string, Record<string, string>>>({})
const productTracking = ref<StockTrackingOverview | null>(null)
const productTrackingRows = ref<ProductTrackingRow[]>([])
const locations = ref<WarehouseLocation[]>([])
const trackingLoading = ref(false)
const creating = ref(false)
const reversing = ref<number | null>(null)
const operationKey = ref(newOperationKey())
let targetGeneration = 0
let recipeGeneration = 0
let availabilityGeneration = 0
let locationGeneration = 0
let searchGeneration = 0

function newOperationKey() {
  return typeof crypto?.randomUUID === 'function'
    ? crypto.randomUUID()
    : `${Date.now()}-${Math.random().toString(36).slice(2)}`
}

function selectionOptions(selected: StockItem | null) {
  const rows = new Map(results.value.map(row => [row.id, { value: row.id, label: row.name, secondary: `${row.sku} · ${row.unit}` }]))
  if (selected) rows.set(selected.id, { value: selected.id, label: selected.name, secondary: `${selected.sku} · ${selected.unit}` })
  return [...rows.values()]
}
const targetOptions = computed(() => selectionOptions(target.value))
const recipeOptions = computed(() => selectionOptions(recipeItem.value))
const cardMap = computed(() => new Map(recipe.value?.cards.map(card => [card.id, card]) ?? []))
const definitionMap = computed(() => recipe.value
  ? { ...recipe.value.definitions, [String(recipe.value.id)]: recipe.value.definition }
  : {})
const activeSet = computed(() => recipe.value
  ? resolveActiveProductSet(recipe.value.id, definitionMap.value, selections.value)
  : null)
const activeDefinitions = computed(() => activeSet.value?.definitions ?? [])
const activeComponentIds = computed(() => activeSet.value?.componentItemIds ?? [])
const activeSelections = computed(() => activeSet.value?.selections ?? {})
const selectionsValid = computed(() => activeDefinitions.value.every(({ itemId, definition }) =>
  definition.groups.every(group => {
    const count = selected(itemId, group.code).length
    return count >= group.min && count <= group.max
  }),
))
const productTrackingMode = computed(() => productTracking.value?.tracking_mode ?? target.value?.tracking_mode ?? 'none')
const componentRequirements = computed(() => {
  const required = new Map<number, number>()
  if (!recipe.value) return required

  const expand = (itemId: number, factor: number, path: Set<number>) => {
    const definition = definitionMap.value[String(itemId)]
    if (!definition) {
      required.set(itemId, (required.get(itemId) ?? 0) + factor)
      return
    }
    if (path.has(itemId)) return
    const nextPath = new Set(path).add(itemId)
    const components = [...definition.components]
    for (const group of definition.groups) {
      const options = new Map(group.options.map(option => [option.code, option]))
      for (const code of selected(itemId, group.code)) {
        const option = options.get(code)
        if (option) components.push(option)
      }
    }
    for (const component of components) expand(component.item_id, factor * Number(component.quantity), nextPath)
  }
  expand(recipe.value.id, Number(quantity.value), new Set())
  return required
})
const componentTrackingValid = computed(() => activeComponentIds.value.every(itemId => {
  const overview = componentTracking.value[String(itemId)]
  if (!overview) return false
  if (overview.tracking_mode === 'none') return true
  const rows = trackedInventory(itemId)
  const rowsValid = rows.every(row => {
    const allocated = quantityT(componentAllocationValue(itemId, row))
    return allocated >= 0
      && allocated <= quantityT(row.quantity)
      && (overview.tracking_mode !== 'serial' || allocated === 0 || allocated === 1000)
  })
  return rowsValid && componentAllocatedT(itemId) === requiredT(itemId)
}))
const productTrackingValid = computed(() => {
  if (productTrackingMode.value === 'none') return true
  const qty = Number(quantity.value)
  if (!Number.isFinite(qty) || qty <= 0) return false
  if (productTrackingMode.value === 'lot') return productTrackingRows.value.length === 1 && productTrackingRows.value[0].lot_code.trim() !== ''
  if (!Number.isInteger(qty) || qty > 10000 || productTrackingRows.value.length !== qty) return false
  const serials = productTrackingRows.value.map(row => row.serial_number.trim())
  return serials.every(Boolean) && new Set(serials).size === serials.length
})
const trackingValid = computed(() => !trackingLoading.value && componentTrackingValid.value && productTrackingValid.value)

async function loadList() {
  loading.value = true
  try {
    const response = await stockApi.listAssemblies(page.value)
    assemblies.value = response.items
    pages.value = response.pagination.pages
    total.value = response.pagination.total
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('stock.assemblies.load_failed')
  } finally {
    loading.value = false
  }
}

async function loadReferences() {
  try {
    warehouses.value = await stockApi.listWarehouses(true)
    warehouseId.value ??= warehouses.value.find(warehouse => warehouse.is_default)?.id ?? warehouses.value[0]?.id ?? null
  } catch {
    warehouses.value = []
  }
}

async function search(query: string) {
  const generation = ++searchGeneration
  searchLoading.value = true
  try {
    const found = await stockApi.searchItems(query, 30)
    if (generation === searchGeneration) results.value = found
  } catch {
    if (generation === searchGeneration) results.value = []
  } finally {
    if (generation === searchGeneration) searchLoading.value = false
  }
}

async function pickTarget(value: number | null) {
  const requestGeneration = ++targetGeneration
  targetPick.value = value
  target.value = null
  productTracking.value = null
  productTrackingRows.value = []
  error.value = ''
  if (value === null) return

  try {
    const item = await stockApi.getItem(value)
    if (requestGeneration !== targetGeneration || targetPick.value !== value) return
    if (!item.is_active || !item.is_stocked || item.item_type !== 'product') {
      error.value = t('stock.assemblies.invalid_target')
      return
    }
    const tracking = await stockApi.itemTracking(value)
    if (requestGeneration !== targetGeneration || targetPick.value !== value) return
    target.value = item
    productTracking.value = tracking
    syncProductTrackingRows(true)
  } catch {
    if (requestGeneration === targetGeneration && targetPick.value === value) error.value = t('stock.assemblies.invalid_target')
  }
}

function clearAvailability() {
  availabilityGeneration++
  availability.value = {}
  componentTracking.value = {}
  componentTrackingQuantities.value = {}
}

async function pickRecipe(value: number | null) {
  const requestGeneration = ++recipeGeneration
  recipePick.value = value
  recipe.value = null
  recipeItem.value = null
  selections.value = {}
  clearAvailability()
  error.value = ''
  if (value === null) return

  try {
    const [item, response] = await Promise.all([stockApi.getItem(value), eshopApi.getProductSet(value)])
    if (requestGeneration !== recipeGeneration || recipePick.value !== value) return
    if (item.is_stocked || !response.set) {
      error.value = t('stock.assemblies.invalid_recipe')
      return
    }
    recipeItem.value = item
    recipe.value = { id: value, definition: response.set.definition, definitions: response.definitions, cards: response.cards }
    void loadAvailability()
  } catch (e: any) {
    if (requestGeneration === recipeGeneration && recipePick.value === value) {
      error.value = e?.response?.data?.error?.message || t('stock.assemblies.invalid_recipe')
    }
  }
}

async function loadAvailability() {
  const requestGeneration = ++availabilityGeneration
  const recipeSnapshot = recipe.value
  const warehouseSnapshot = warehouseId.value
  const itemIds = activeComponentIds.value
  availability.value = {}
  if (!recipeSnapshot || !warehouseSnapshot || itemIds.length === 0) return

  trackingLoading.value = true
  try {
    const [response, trackingRows] = await Promise.all([
      stockApi.availability(itemIds, warehouseSnapshot),
      Promise.all(itemIds.map(async itemId => [String(itemId), await stockApi.itemTracking(itemId)] as const)),
    ])
    if (requestGeneration === availabilityGeneration && recipe.value === recipeSnapshot && warehouseId.value === warehouseSnapshot) {
      availability.value = response
      componentTracking.value = Object.fromEntries(trackingRows)
    }
  } catch {
    if (requestGeneration === availabilityGeneration && recipe.value === recipeSnapshot && warehouseId.value === warehouseSnapshot) {
      availability.value = {}
      componentTracking.value = {}
    }
  } finally {
    if (requestGeneration === availabilityGeneration) trackingLoading.value = false
  }
}

async function loadLocations() {
  const requestGeneration = ++locationGeneration
  const warehouseSnapshot = warehouseId.value
  locations.value = []
  if (!warehouseSnapshot) return
  try {
    const response = await stockApi.listLocations(warehouseSnapshot)
    if (requestGeneration === locationGeneration && warehouseId.value === warehouseSnapshot) {
      locations.value = response.filter(location => location.is_active)
    }
  } catch {
    if (requestGeneration === locationGeneration) locations.value = []
  }
}

function selected(setId: number, groupCode: string) {
  return selections.value[String(setId)]?.[groupCode] ?? []
}

function toggle(setId: number, groupCode: string, code: string, max: number) {
  const groups = selections.value[String(setId)] ?? (selections.value[String(setId)] = {})
  const current = groups[groupCode] ?? []
  groups[groupCode] = max === 1
    ? (current[0] === code ? [] : [code])
    : current.includes(code)
      ? current.filter(value => value !== code)
      : current.length < max ? [...current, code] : current
  clearAvailability()
  void loadAvailability()
}

function label(id: number) {
  const card = cardMap.value.get(id)
  return card ? `${card.sku} - ${card.name}` : `#${id}`
}

function availabilityValue(id: number) {
  return availability.value[String(id)] ?? '?'
}

function quantityT(value: string | number) {
  const parsed = Number(value)
  return Number.isFinite(parsed) ? Math.round(parsed * 1000) : 0
}

function requiredT(itemId: number) {
  return quantityT(componentRequirements.value.get(itemId) ?? 0)
}

function formatT(value: number) {
  return (value / 1000).toFixed(3)
}

function allocationKey(row: StockTrackingOverview['inventory'][number]) {
  return `${row.stock_tracking_unit_id}:${row.location_id ?? 0}`
}

function trackedInventory(itemId: number) {
  return (componentTracking.value[String(itemId)]?.inventory ?? [])
    .filter(row => row.warehouse_id === warehouseId.value && quantityT(row.quantity) > 0)
}

function componentAllocationValue(itemId: number, row: StockTrackingOverview['inventory'][number]) {
  return componentTrackingQuantities.value[String(itemId)]?.[allocationKey(row)] ?? ''
}

function setComponentAllocation(itemId: number, row: StockTrackingOverview['inventory'][number], value: string) {
  const entries = componentTrackingQuantities.value[String(itemId)]
    ?? (componentTrackingQuantities.value[String(itemId)] = {})
  entries[allocationKey(row)] = value
}

function toggleSerialAllocation(itemId: number, row: StockTrackingOverview['inventory'][number], checked: boolean) {
  setComponentAllocation(itemId, row, checked ? '1' : '')
}

function componentAllocatedT(itemId: number) {
  return trackedInventory(itemId).reduce((sum, row) => sum + quantityT(componentAllocationValue(itemId, row)), 0)
}

function syncProductTrackingRows(reset = false) {
  const mode = productTrackingMode.value
  if (mode === 'none') {
    productTrackingRows.value = []
    return
  }
  if (mode === 'lot') {
    const current = reset ? undefined : productTrackingRows.value[0]
    productTrackingRows.value = [current ?? { serial_number: '', lot_code: '', expires_on: '', location_id: null }]
    return
  }
  const count = Number(quantity.value)
  if (!Number.isInteger(count) || count < 1 || count > 10000) {
    productTrackingRows.value = []
    return
  }
  const current = reset ? [] : productTrackingRows.value
  productTrackingRows.value = Array.from({ length: count }, (_, index) => current[index]
    ?? { serial_number: '', lot_code: '', expires_on: '', location_id: null })
}

function componentTrackingPayload() {
  const payload: Record<string, TrackingAllocationInput[]> = {}
  for (const itemId of activeComponentIds.value) {
    const overview = componentTracking.value[String(itemId)]
    if (!overview || overview.tracking_mode === 'none') continue
    payload[String(itemId)] = trackedInventory(itemId).flatMap(row => {
      const allocationQuantity = componentAllocationValue(itemId, row)
      if (quantityT(allocationQuantity) <= 0) return []
      return [{
        stock_tracking_unit_id: row.stock_tracking_unit_id,
        quantity: allocationQuantity,
        serial_number: row.serial_number,
        lot_code: row.lot_code,
        expires_on: row.expires_on,
        location_id: row.location_id,
      }]
    })
  }
  return payload
}

function productTrackingPayload(): TrackingAllocationInput[] {
  if (productTrackingMode.value === 'none') return []
  return productTrackingRows.value.map(row => ({
    quantity: productTrackingMode.value === 'serial' ? '1' : quantity.value,
    serial_number: productTrackingMode.value === 'serial' ? row.serial_number.trim() : null,
    lot_code: productTrackingMode.value === 'lot' ? row.lot_code.trim() : null,
    expires_on: row.expires_on || null,
    location_id: row.location_id,
  }))
}

async function create() {
  if (!target.value || !recipe.value || !warehouseId.value || creating.value || !canWrite.value || !selectionsValid.value || !trackingValid.value) return

  creating.value = true
  error.value = ''
  try {
    const trackedComponents = componentTrackingPayload()
    const saved = await stockApi.createAssembly({
      operation_key: operationKey.value,
      stock_item_id: target.value.id,
      warehouse_id: warehouseId.value,
      doc_date: docDate.value,
      quantity: quantity.value,
      definition: recipe.value.definition,
      selections: remapRootProductSetSelections(activeSelections.value, recipe.value.id, target.value.id),
      ...(Object.keys(trackedComponents).length > 0 ? { component_tracking_allocations: trackedComponents } : {}),
      ...(productTrackingMode.value !== 'none' ? { product_tracking_allocations: productTrackingPayload() } : {}),
    })
    toast.success(t('stock.assemblies.created'))
    operationKey.value = newOperationKey()
    assemblies.value = [saved, ...assemblies.value]
    total.value++
    syncProductTrackingRows(true)
    void loadAvailability()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('stock.assemblies.create_failed')
  } finally {
    creating.value = false
  }
}

async function reverse(assembly: ProductAssembly) {
  if (!confirm(t('stock.assemblies.reverse_confirm'))) return

  reversing.value = assembly.id
  try {
    const saved = await stockApi.reverseAssembly(assembly.id)
    assemblies.value = assemblies.value.map(row => row.id === saved.id ? saved : row)
    toast.success(t('stock.assemblies.reversed'))
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('stock.assemblies.reverse_failed')
  } finally {
    reversing.value = null
  }
}

watch(warehouseId, () => {
  locations.value = []
  productTrackingRows.value = productTrackingRows.value.map(row => ({ ...row, location_id: null }))
  clearAvailability()
  void loadLocations()
  void loadAvailability()
})
watch(quantity, () => syncProductTrackingRows())
watch(page, () => { void loadList() })
onMounted(() => { void Promise.all([loadList(), loadReferences()]) })
</script>

<template>
  <div class="mx-auto max-w-6xl space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">{{ t('stock.assemblies.title') }}</h1>
        <p class="mt-0.5 text-sm text-neutral-500">{{ t('stock.assemblies.subtitle') }}</p>
      </div>
      <span class="rounded-full bg-neutral-100 px-2 py-1 text-sm text-neutral-600">{{ t('stock.assemblies.total', { count: total }) }}</span>
    </div>
    <p v-if="error" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 px-3 py-2 text-sm text-danger-700">{{ error }}</p>

    <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
      <header class="border-b border-neutral-200 px-4 py-3">
        <h2 class="font-semibold text-neutral-900">{{ t('stock.assemblies.new_title') }}</h2>
        <p class="mt-0.5 text-xs text-neutral-500">{{ t('stock.assemblies.new_hint') }}</p>
      </header>
      <fieldset :disabled="creating || !canWrite" class="min-w-0 space-y-4 p-4">
        <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
          <label class="min-w-0 text-sm font-medium text-neutral-700">
            {{ t('stock.assemblies.target') }}
            <SearchableSelect :model-value="targetPick" remote :options="targetOptions" :loading="searchLoading" :placeholder="t('stock.assemblies.search_target')" :no-results-label="t('common.no_results')" teleport @search="search" @update:model-value="pickTarget" />
          </label>
          <label class="min-w-0 text-sm font-medium text-neutral-700">
            {{ t('stock.assemblies.recipe') }}
            <SearchableSelect :model-value="recipePick" remote :options="recipeOptions" :loading="searchLoading" :placeholder="t('stock.assemblies.search_recipe')" :no-results-label="t('common.no_results')" teleport @search="search" @update:model-value="pickRecipe" />
          </label>
        </div>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <label class="text-sm font-medium text-neutral-700">
            {{ t('stock.assemblies.warehouse') }}
            <select v-model="warehouseId" :class="FIELD"><option :value="null" disabled>{{ t('stock.assemblies.choose_warehouse') }}</option><option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.code }} - {{ warehouse.name }}</option></select>
          </label>
          <label class="text-sm font-medium text-neutral-700">{{ t('stock.assemblies.doc_date') }}<input v-model="docDate" :class="FIELD" type="date"></label>
          <label class="text-sm font-medium text-neutral-700">{{ t('stock.assemblies.quantity') }}<input v-model="quantity" :class="FIELD" inputmode="decimal"></label>
        </div>

        <div v-if="recipe" class="space-y-3 rounded-lg border border-neutral-200 p-3">
          <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="font-medium text-neutral-900">{{ t('stock.assemblies.availability') }}</p>
            <button type="button" :class="btnOutline('neutral')" @click="loadAvailability"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>{{ t('common.refresh') }}</button>
          </div>
          <div class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <article v-for="id in activeComponentIds" :key="id" class="rounded bg-neutral-50 px-2 py-1.5 text-sm dark:bg-neutral-900/20">
              <div class="flex justify-between gap-2"><span class="truncate">{{ label(id) }}</span><span class="font-mono">{{ availabilityValue(id) }}</span></div>
              <div v-if="componentTracking[String(id)]?.tracking_mode !== 'none'" class="mt-2 space-y-2 border-t border-neutral-200 pt-2">
                <p class="text-xs text-neutral-600">
                  {{ t('stock.assemblies.tracking_required', { required: formatT(requiredT(id)), allocated: formatT(componentAllocatedT(id)) }) }}
                </p>
                <p v-if="trackedInventory(id).length === 0" class="text-xs text-warning-700">{{ t('stock.assemblies.tracking_empty') }}</p>
                <label v-for="row in trackedInventory(id)" :key="allocationKey(row)" class="flex flex-wrap items-center gap-2 rounded border border-neutral-200 bg-surface px-2 py-1.5">
                  <input
                    v-if="row.tracking_type === 'serial'"
                    type="checkbox"
                    class="rounded border-neutral-300 text-primary-600"
                    :checked="componentAllocationValue(id, row) === '1'"
                    @change="toggleSerialAllocation(id, row, ($event.target as HTMLInputElement).checked)"
                  >
                  <input
                    v-else
                    type="number"
                    min="0"
                    :max="row.quantity"
                    step="0.001"
                    class="h-8 w-24 rounded-md border border-neutral-300 bg-surface px-2 text-sm"
                    :value="componentAllocationValue(id, row)"
                    :aria-label="t('stock.assemblies.tracking_quantity')"
                    @input="setComponentAllocation(id, row, ($event.target as HTMLInputElement).value)"
                  >
                  <span class="min-w-0 flex-1 truncate font-medium">{{ row.serial_number || row.lot_code }}</span>
                  <span class="text-xs text-neutral-500">{{ row.location_code || t('stock.tracking.no_location') }} · {{ row.quantity }}</span>
                </label>
              </div>
            </article>
          </div>
          <div v-for="{ itemId, definition } in activeDefinitions" :key="itemId" class="space-y-2">
            <div v-for="group in definition.groups" :key="`${itemId}-${group.code}`" class="rounded border border-neutral-200 p-2">
              <p class="text-sm font-medium text-neutral-900">{{ group.name }} <span class="font-normal text-neutral-500">({{ group.min }} - {{ group.max }})</span></p>
              <label v-for="option in group.options" :key="option.code" class="mt-1 flex items-center gap-2 text-sm"><input :checked="selected(itemId, group.code).includes(option.code)" :type="group.max === 1 ? 'radio' : 'checkbox'" :name="`${itemId}-${group.code}`" class="rounded border-neutral-300 text-primary-600" @change="toggle(itemId, group.code, option.code, group.max)"><span>{{ option.name }} · {{ label(option.item_id) }}</span></label>
            </div>
          </div>
        </div>

        <div v-if="target && productTrackingMode !== 'none'" class="space-y-3 rounded-lg border border-neutral-200 p-3">
          <div>
            <p class="font-medium text-neutral-900">{{ t('stock.assemblies.product_tracking_title') }}</p>
            <p class="text-xs text-neutral-500">{{ t(`stock.assemblies.product_tracking_${productTrackingMode}_hint`) }}</p>
          </div>
          <div v-if="productTrackingMode === 'lot'" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <label class="text-sm font-medium text-neutral-700">
              {{ t('stock.tracking.lot_code') }}
              <input v-model="productTrackingRows[0].lot_code" :class="FIELD" autocomplete="off">
            </label>
            <label class="text-sm font-medium text-neutral-700">
              {{ t('stock.tracking.expires') }}
              <input v-model="productTrackingRows[0].expires_on" :class="FIELD" type="date">
            </label>
            <label class="text-sm font-medium text-neutral-700">
              {{ t('stock.assemblies.location') }}
              <select v-model="productTrackingRows[0].location_id" :class="FIELD">
                <option :value="null">{{ t('stock.tracking.no_location') }}</option>
                <option v-for="location in locations" :key="location.id" :value="location.id">{{ location.code }} - {{ location.name }}</option>
              </select>
            </label>
          </div>
          <p v-else-if="productTrackingRows.length === 0" class="text-sm text-warning-700">{{ t('stock.assemblies.product_tracking_serial_quantity') }}</p>
          <div v-else class="grid grid-cols-1 gap-2 sm:grid-cols-2">
            <div v-for="(row, index) in productTrackingRows" :key="index" class="grid grid-cols-1 gap-2 rounded border border-neutral-200 p-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)]">
              <label class="text-xs font-medium text-neutral-700">
                {{ t('stock.tracking.serial_number') }} {{ index + 1 }}
                <input v-model="row.serial_number" :class="FIELD" autocomplete="off">
              </label>
              <label class="text-xs font-medium text-neutral-700">
                {{ t('stock.assemblies.location') }}
                <select v-model="row.location_id" :class="FIELD">
                  <option :value="null">{{ t('stock.tracking.no_location') }}</option>
                  <option v-for="location in locations" :key="location.id" :value="location.id">{{ location.code }} - {{ location.name }}</option>
                </select>
              </label>
            </div>
          </div>
        </div>

        <p v-if="!canWrite" class="text-sm text-warning-700">{{ t('stock.assemblies.readonly_hint') }}</p>
        <p v-else-if="recipe && !selectionsValid" class="text-sm text-warning-700">{{ t('stock.assemblies.required_choices') }}</p>
        <p v-else-if="recipe && !trackingValid" class="text-sm text-warning-700">{{ t('stock.assemblies.tracking_incomplete') }}</p>
        <button type="button" :class="btnFilled('success')" :disabled="!target || !recipe || !warehouseId || !quantity || !selectionsValid || !trackingValid" @click="create"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>{{ creating ? t('common.saving') : t('stock.assemblies.create') }}</button>
      </fieldset>
    </section>

    <section class="overflow-hidden rounded-xl border border-neutral-200 bg-surface shadow-sm">
      <header class="border-b border-neutral-200 px-4 py-3"><h2 class="font-semibold text-neutral-900">{{ t('stock.assemblies.history') }}</h2></header>
      <div v-if="loading" class="py-10 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="assemblies.length === 0" dense icon="box" :title="t('stock.assemblies.empty')" :message="t('stock.assemblies.empty_hint')" />
      <div v-else class="divide-y divide-neutral-100">
        <article v-for="assembly in assemblies" :key="assembly.id" class="grid gap-3 px-4 py-3 sm:grid-cols-[minmax(0,1fr)_auto_auto] sm:items-center">
          <div>
            <p class="font-medium text-neutral-900">#{{ assembly.id }} · {{ assembly.quantity }} {{ t('stock.assemblies.units') }}</p>
            <p class="mt-0.5 text-xs text-neutral-500">{{ assembly.created_at }} · {{ assembly.value_total }}</p>
            <div class="mt-2 flex flex-wrap gap-2 text-xs"><RouterLink :to="`/stock/documents/${assembly.issue_document_id}`" class="text-primary-700 hover:underline">{{ t('stock.assemblies.issue_document') }}</RouterLink><RouterLink :to="`/stock/documents/${assembly.receipt_document_id}`" class="text-primary-700 hover:underline">{{ t('stock.assemblies.receipt_document') }}</RouterLink></div>
          </div>
          <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="assembly.status === 'posted' ? 'bg-success-50 text-success-700' : 'bg-neutral-100 text-neutral-600'">{{ t(`stock.assemblies.status.${assembly.status}`) }}</span>
          <button v-if="assembly.status === 'posted' && canWrite" type="button" :class="btnOutline('danger')" :disabled="reversing === assembly.id" @click="reverse(assembly)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.uturn" /></svg>{{ reversing === assembly.id ? t('common.saving') : t('stock.assemblies.reverse') }}</button>
        </article>
      </div>
      <div v-if="pages > 1" class="flex flex-wrap justify-end gap-2 border-t border-neutral-100 px-4 py-3"><button type="button" :class="btnOutline('neutral')" :disabled="page <= 1" @click="page--"><svg class="h-4 w-4 rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('common.previous') }}</button><button type="button" :class="btnOutline('neutral')" :disabled="page >= pages" @click="page++"><svg class="h-4 w-4 -rotate-90" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevron" /></svg>{{ t('common.next') }}</button></div>
    </section>
  </div>
</template>
