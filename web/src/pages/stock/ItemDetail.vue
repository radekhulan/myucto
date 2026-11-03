<script setup lang="ts">
import ClientItems from './ClientItems.vue'
import { ref, computed, nextTick, onMounted, onBeforeUnmount, watch } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { stockApi, type StockItem, type StockItemPackaging, type StockLedgerRow, type StockLevelRow, type StockTrackingOverview } from '@/api/stock'
import { codebooksApi, type VatRate } from '@/api/codebooks'
import { eshopApi, type Attribute, type AttributeOption } from '@/api/eshop'
import { purchaseOrdersApi, type StockQuantityRow } from '@/api/purchaseOrders'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate, formatUnitPrice } from '@/composables/useFormat'
import { hasSubCentPrice, roundUnitPrice } from '@/utils/unitPrice'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { btnOutline } from '@/components/ui/buttonStyles'
import ItemDuplicateDialog from '@/components/stock/ItemDuplicateDialog.vue'
import ItemTemplatesPanel from '@/components/stock/ItemTemplatesPanel.vue'
import ProductRelationsPanel from '@/components/stock/ProductRelationsPanel.vue'
import { productMastersApi, type ProductWithMasterContext } from '@/api/productMasters'
import { useSupplierStore } from '@/stores/supplier'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const supplier = useSupplierStore()

const id = computed(() => Number(route.params.id))
type DetailTab = 'overview' | 'intrastat'
const detailTabs: DetailTab[] = ['overview', 'intrastat']
const detailTab = ref<DetailTab>(route.query.tab === 'intrastat' ? 'intrastat' : 'overview')
const detailTabList = ref<HTMLElement | null>(null)
const item = ref<StockItem | null>(null)
const loading = ref(false)
const duplicateOpen = ref(false)
const canManageLifecycle = computed(() => auth.canWrite('stock.items.write') && auth.canWrite('eshop.write'))
const productContext = ref<ProductWithMasterContext | null>(null)
let contextGeneration = 0

function onDetailTabKey(event: KeyboardEvent, current: DetailTab) {
  const index = detailTabs.indexOf(current)
  let next = -1
  if (event.key === 'ArrowRight') {
    next = (index + 1) % detailTabs.length
  } else if (event.key === 'ArrowLeft') {
    next = (index + detailTabs.length - 1) % detailTabs.length
  } else if (event.key === 'Home') {
    next = 0
  } else if (event.key === 'End') {
    next = detailTabs.length - 1
  }

  if (next < 0) return

  event.preventDefault()
  detailTab.value = detailTabs[next]!
  void nextTick(() => {
    detailTabList.value?.querySelector<HTMLElement>('[aria-selected="true"]')?.focus()
  })
}

async function loadProductContext() {
  if (auth.isClientRole) return
  const current = ++contextGeneration
  try {
    const value = await productMastersApi.getProductContext(id.value)
    if (current === contextGeneration) productContext.value = value
  } catch {
    if (current === contextGeneration) productContext.value = null
  }
}

// Parametry karty (e-shopové atributy) jen ke čtení: hodnoty z kontextu produktu,
// názvy, jednotky a popisky voleb z číselníku. Bez práva k e-shopu kontext nepřijde.
const attributeDefs = ref<Attribute[]>([])
const attributeOptions = ref<Record<number, AttributeOption[]>>({})
async function loadAttributeDefs() {
  if (!auth.canRead('eshop')) return
  try {
    attributeDefs.value = await eshopApi.listAttributes()
  } catch {
    attributeDefs.value = []
  }
}
watch(() => productContext.value?.attributes, async (rows) => {
  const enumIds = [...new Set((rows ?? []).filter(r => r.option_id != null).map(r => r.attribute_id))]
    .filter(attributeId => !attributeOptions.value[attributeId])
  for (const attributeId of enumIds) {
    try {
      attributeOptions.value[attributeId] = await eshopApi.listAttributeOptions(attributeId)
    } catch {
      attributeOptions.value[attributeId] = []
    }
  }
})
interface ParameterRow { attributeId: number; name: string; value: string }
const parameterRows = computed<ParameterRow[]>(() => {
  const grouped = new Map<number, string[]>()
  for (const row of productContext.value?.attributes ?? []) {
    const def = attributeDefs.value.find(a => a.id === row.attribute_id)
    let value: string | null = null
    if (row.option_id != null) {
      value = attributeOptions.value[row.attribute_id]?.find(o => o.id === row.option_id)?.label ?? null
    } else if (row.value_bool != null) {
      value = row.value_bool ? t('common.yes') : t('common.no')
    } else if (row.value_num != null && row.value_num !== '') {
      value = String(Number(row.value_num)) + (def?.unit ? ` ${def.unit}` : '')
    } else if (row.value_text != null && row.value_text !== '') {
      value = row.value_text
    }
    if (value === null) continue
    grouped.set(row.attribute_id, [...(grouped.get(row.attribute_id) ?? []), value])
  }
  return [...grouped.entries()]
    .map(([attributeId, values]) => {
      const def = attributeDefs.value.find(a => a.id === attributeId)
      return { attributeId, name: def?.name ?? `#${attributeId}`, value: values.join(', '), order: def?.display_order ?? 0 }
    })
    .sort((a, b) => a.order - b.order || a.name.localeCompare(b.name))
    .map(({ attributeId, name, value }) => ({ attributeId, name, value }))
})

// Stav a skladová (průměrná nákupní) cena po jednotlivých skladech. Stejná čísla
// jako sloupce Stav, Hodnota a Prům. cena v seznamu karet, jen rozepsaná.
const warehouseLevels = ref<StockLevelRow[]>([])
async function loadWarehouseLevels() {
  try {
    const result = await stockApi.levels({ item_ids: [id.value], per_page: 200 })
    // Sklad, kde karta kdysi byla a dnes má nulu, jen přidává šum.
    warehouseLevels.value = result.data.filter(row => Number(row.qty) !== 0 || Number(row.value_total) !== 0)
  } catch {
    warehouseLevels.value = []
  }
}
const warehouseTotals = computed(() => {
  const qty = warehouseLevels.value.reduce((sum, row) => sum + Number(row.qty), 0)
  const value = warehouseLevels.value.reduce((sum, row) => sum + Number(row.value_total), 0)
  return { qty: Math.round(qty * 1000) / 1000, value: Math.round(value * 100) / 100, avg: qty === 0 ? null : value / qty }
})

// Prodejní cena s DPH podle sazby karty, jen u plátce. Zaokrouhlení stejně jako
// řádek faktury (InvoiceMath): DPH = round2(základ × sazba / 100), cena = základ + DPH.
// Cena pod haléř (0,004 Kč/ks) by se tak vynulovala, ta se počítá na šest míst.
const vatRates = ref<VatRate[]>([])
async function loadVatRates() {
  try {
    vatRates.value = await codebooksApi.vatRates('CZ')
  } catch {
    vatRates.value = []
  }
}
const round2 = (n: number) => Math.round(n * 100) / 100
// U přenesené daňové povinnosti DPH odvádí odběratel, cena „s DPH“ by klamala.
const salePriceWithVat = computed<number | null>(() => {
  const current = item.value
  if (!current || !(supplier.currentSupplier?.is_vat_payer ?? true) || current.vat_rate_id == null) return null
  const net = current.effective_price ?? current.sale_price_without_vat
  if (net == null || net === '') return null
  const vatRate = vatRates.value.find(r => r.id === current.vat_rate_id)
  if (!vatRate || vatRate.is_reverse_charge) return null
  const rate = vatRate.rate_percent
  if (hasSubCentPrice(net)) return roundUnitPrice(Number(net) * (1 + Number(rate) / 100))
  const base = round2(Number(net))
  return round2(base + round2(base * Number(rate) / 100))
})

// Odvozené kvantity (Epic SKLAD, fáze 4) — skladem/rezervováno/na cestě/u dodavatele.
// BE vrací řádek se samými nulami i pro kartu bez jediného pohybu/objednávky — nikdy
// se nespoléhat na to, že `items` bude prázdné, ale i tak drž `quantities` nullable
// pro dobu, než se odpověď vrátí.
const quantities = ref<StockQuantityRow | null>(null)
async function loadQuantities() {
  if (auth.isClientRole) return
  try {
    const r = await purchaseOrdersApi.quantities([id.value])
    quantities.value = r.items[0] ?? null
  } catch { quantities.value = null }
}

const movements = ref<StockLedgerRow[]>([])
const tracking = ref<StockTrackingOverview | null>(null)
// Balení (issue #17): detail je jen přehled, upravuje se v editoru karty
// společně s ostatními údaji (jedno Uložit).
const packaging = ref<StockItemPackaging | null>(null)
const openingBalance = ref('0')
const movLoading = ref(false)
const movOffset = ref(0)
const movLimit = 50
const movHasMore = ref(true)

async function loadItem() {
  if (auth.isClientRole) return
  loading.value = true
  try {
    item.value = await stockApi.getItem(id.value)
    tracking.value = item.value.tracking_mode === 'none' ? null : await stockApi.itemTracking(id.value)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

async function loadPackaging() {
  if (auth.isClientRole) return
  try {
    packaging.value = await stockApi.getItemPackaging(id.value)
  } catch {
    packaging.value = null
  }
}

async function loadMovements(reset = false) {
  if (auth.isClientRole) return
  if (reset) { movements.value = []; movOffset.value = 0; movHasMore.value = true }
  if (!movHasMore.value) return
  movLoading.value = true
  try {
    const r = await stockApi.itemMovements(id.value, { limit: movLimit, offset: movOffset.value })
    if (movOffset.value === 0) openingBalance.value = r.opening_balance
    movements.value = movements.value.concat(r.items)
    movOffset.value += r.items.length
    movHasMore.value = r.items.length === movLimit
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    movLoading.value = false
  }
}

onMounted(async () => {
  if (auth.isClientRole) return
  await loadItem()
  void loadPackaging()
  void loadWarehouseLevels()
  void loadVatRates()
  void loadAttributeDefs()
  await loadMovements(true)
  loadQuantities()
  void loadProductContext()
})
watch(id, () => { contextGeneration++; productContext.value = null; void loadProductContext() })
watch(() => supplier.currentSupplierId, () => { contextGeneration++; productContext.value = null; void loadProductContext() })
onBeforeUnmount(() => { contextGeneration++ })

function exportFile(format: 'pdf' | 'xlsx') {
  window.open(stockApi.itemMovementsExportUrl(id.value, format), '_blank', 'noopener')
}

async function deactivate() {
  if (!item.value) return
  if (!confirm(t('stock.items.deactivate_confirm', { name: item.value.name }))) return
  try {
    await stockApi.updateItem(item.value.id, { ...toPayload(item.value), is_active: false })
    toast.success(t('common.saved'))
    await loadItem()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
async function changeLifecycle(status: 'draft' | 'ready' | 'retired') {
  if (!item.value) return
  try {
    item.value = await stockApi.updateLifecycle(item.value.id, status, item.value.row_version)
    toast.success(t('common.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
function duplicated(created: StockItem) {
  duplicateOpen.value = false
  void router.push(`/stock/items/${created.id}/edit`)
}
function toPayload(i: StockItem) {
  return {
    sku: i.sku, name: i.name, item_type: i.item_type, unit: i.unit, tracking_mode: i.tracking_mode, ean: i.ean,
    vat_rate_id: i.vat_rate_id, sale_price_without_vat: i.sale_price_without_vat,
    min_qty: i.min_qty,
    intrastat_cn8_code: i.intrastat_cn8_code,
    intrastat_country_of_origin: i.intrastat_country_of_origin,
    intrastat_net_mass_kg: i.intrastat_net_mass_kg,
    intrastat_supplementary_unit: i.intrastat_supplementary_unit,
    intrastat_supplementary_unit_coefficient: i.intrastat_supplementary_unit_coefficient,
    is_active: i.is_active, note: i.note,
  }
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'new-issue', label: t('stock.item_detail.new_issue'), icon: 'send', tier: 'primary', variant: 'primary',
    show: auth.canWrite('stock'),
    to: { path: '/stock/documents/new', query: { doc_type: 'issue', stock_item_id: id.value } },
  },
  {
    key: 'new-receipt', label: t('stock.item_detail.new_receipt'), icon: 'inbox', tier: 'secondary', variant: 'success',
    show: auth.canWrite('stock.documents.write'),
    to: { path: '/stock/documents/new', query: { doc_type: 'receipt', stock_item_id: id.value } },
  },
  {
    key: 'new-transfer', label: t('stock.item_detail.new_transfer'), icon: 'swap', tier: 'secondary', variant: 'primary',
    show: auth.canWrite('stock.documents.write'),
    to: { path: '/stock/documents/new', query: { doc_type: 'transfer', stock_item_id: id.value } },
  },
  {
    key: 'edit', label: t('stock.item_detail.edit'), icon: 'edit', tier: 'secondary', variant: 'warning',
    show: auth.canWrite('stock'), to: `/stock/items/${id.value}/edit`,
  },
  {
    key: 'set-config', label: t('eshop.sets.open'), icon: 'tag', tier: 'secondary', variant: 'primary',
    show: auth.canRead('eshop') && item.value?.is_stocked === false, to: `/eshop/sets/${id.value}`,
  },
  {
    key: 'duplicate', label: t('stock.lifecycle.duplicate'), icon: 'copy', tier: 'secondary', variant: 'neutral',
    show: canManageLifecycle.value, run: () => { duplicateOpen.value = true },
  },
  {
    key: 'retire', label: t('stock.lifecycle.retire'), icon: 'archive', tier: 'overflow', variant: 'danger',
    show: canManageLifecycle.value && item.value?.lifecycle_status !== 'retired', run: () => void changeLifecycle('retired'),
  },
  {
    key: 'ready', label: t('stock.lifecycle.ready'), icon: 'check', tier: 'overflow', variant: 'success',
    show: canManageLifecycle.value && item.value?.lifecycle_status !== 'ready', run: () => void changeLifecycle('ready'),
  },
  {
    key: 'sales', label: t('stock.item_detail.sales'), icon: 'chart', tier: 'secondary', variant: 'neutral',
    show: auth.canRead('invoices'), to: { path: '/stock/reports', query: { tab: 'sales', stock_item_id: id.value } },
  },
  {
    key: 'export-pdf', label: t('stock.item_detail.export_pdf'), icon: 'download', tier: 'secondary', variant: 'neutral',
    run: () => exportFile('pdf'),
  },
  {
    key: 'export-xlsx', label: t('stock.item_detail.export_xlsx'), icon: 'download', tier: 'secondary', variant: 'neutral',
    run: () => exportFile('xlsx'),
  },
  {
    key: 'deactivate', label: t('stock.item_detail.deactivate'), icon: 'trash', tier: 'overflow', variant: 'danger',
    show: auth.canWrite('stock') && item.value?.is_active, run: deactivate,
  },
])

const num = (v: string) => Number(v)
const openingBalanceNum = computed(() => Number(openingBalance.value))
// L1: běžnou bilanci NEPOČÍTÁME ve floatu — backend vrací money-safe `balance_after`
// per řádek (StockValuation, tisíciny), jen ho renderujeme.
</script>

<template>
  <ClientItems v-if="auth.isClientRole" mode="detail" />
  <div v-else>
    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <template v-else-if="item">
      <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
          <div class="flex items-center gap-2">
            <h1 class="text-2xl font-semibold">{{ item.name }}</h1>
            <span class="text-xs px-2 py-0.5 rounded font-medium bg-neutral-100 text-neutral-600 dark:bg-neutral-700 dark:text-neutral-200">{{ t(`stock.lifecycle.status.${item.lifecycle_status ?? 'ready'}`) }}</span>
            <span v-if="!item.is_active" class="text-xs px-2 py-0.5 rounded font-medium bg-neutral-100 text-neutral-500">{{ t('common.no') }}</span>
          </div>
          <p class="text-sm text-neutral-500 mt-0.5">
            <span class="font-mono">{{ item.sku }}</span> · {{ t(`stock.item_type.${item.item_type}`) }} · {{ item.unit }}
          </p>
        </div>
        <ActionBar :actions="actions" :desktop-inline="4" />
      </div>
      <div ref="detailTabList" role="tablist" :aria-label="t('stock.item_detail.tabs_label')" class="mb-4 flex gap-1 overflow-x-auto border-b border-neutral-200">
        <button type="button" role="tab" :aria-selected="detailTab === 'overview'" :tabindex="detailTab === 'overview' ? 0 : -1" class="cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm transition" :class="detailTab === 'overview' ? 'border-primary-600 font-medium text-primary-700' : 'border-transparent text-neutral-600 hover:text-neutral-900'" @click="detailTab = 'overview'" @keydown="onDetailTabKey($event, 'overview')">{{ t('stock.item_detail.tab_overview') }}</button>
        <button type="button" role="tab" :aria-selected="detailTab === 'intrastat'" :tabindex="detailTab === 'intrastat' ? 0 : -1" class="cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm transition" :class="detailTab === 'intrastat' ? 'border-primary-600 font-medium text-primary-700' : 'border-transparent text-neutral-600 hover:text-neutral-900'" @click="detailTab = 'intrastat'" @keydown="onDetailTabKey($event, 'intrastat')">{{ t('stock.items.intrastat.tab') }}</button>
      </div>
      <div v-show="detailTab === 'overview'" role="tabpanel">
      <p v-if="item.lifecycle_status === 'retired'" class="mb-4 rounded-lg border border-warning-200 bg-warning-50 px-3 py-2 text-sm text-warning-800 dark:bg-warning-950/30 dark:text-warning-200">{{ t('stock.lifecycle.retired_hint') }}</p>
      <div v-if="productContext?.master" class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-primary-200 bg-primary-50 px-4 py-3 text-sm">
        <div><span class="text-primary-700">{{ t('eshop.inheritance.variant_of') }}</span> <strong>{{ productContext.master.name }}</strong><span v-if="productContext.variant" class="ml-2 text-xs text-neutral-500">{{ t('eshop.inheritance.effective_values') }}</span></div>
        <RouterLink :to="`/eshop/product-masters/${productContext.master.id}`" class="inline-flex items-center gap-1 font-medium text-primary-700 hover:underline">{{ t('eshop.inheritance.open_master') }}</RouterLink>
      </div>

      <!-- Odvozené kvantity (Epic SKLAD, fáze 4) — musí vykreslit 0, i když karta
           nemá jediný pohyb ani objednávku (BE vrací nulový řádek, ne prázdno). -->
      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-3">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('stock.quantities.on_hand') }}</div>
          <div class="text-lg font-semibold font-mono">{{ quantities?.on_hand ?? '0' }}</div>
          <div class="text-xs text-neutral-400 mt-0.5">{{ t('stock.quantities.available_to_promise') }}: {{ quantities?.available_to_promise ?? '0' }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('stock.quantities.reserved') }}</div>
          <div class="text-lg font-semibold font-mono">{{ quantities?.reserved ?? '0' }}</div>
        </div>
        <RouterLink :to="`/stock/purchase-orders?stock_item_id=${id}`"
          class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 hover:border-primary-300 hover:bg-primary-50/40 transition-colors">
          <div class="text-xs text-neutral-500">{{ t('stock.quantities.in_transit') }}</div>
          <div class="text-lg font-semibold font-mono text-primary-700">{{ quantities?.in_transit ?? '0' }}</div>
          <div v-if="quantities?.earliest_expected_date" class="text-xs text-neutral-400 mt-0.5">
            {{ t('stock.quantities.earliest_expected', { date: formatDate(quantities.earliest_expected_date) }) }}
          </div>
        </RouterLink>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('stock.quantities.at_vendor') }}</div>
          <div class="text-lg font-semibold font-mono">{{ quantities?.at_vendor ?? '0' }}</div>
        </div>
      </div>

      <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-5">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('stock.item_detail.opening_balance') }}</div>
          <div class="text-lg font-semibold font-mono">{{ openingBalanceNum }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('stock.items.col_sale_price') }}</div>
          <!-- Platná cena = effective_price; při akci je původní hladina přeškrtnutá. -->
          <div class="text-lg font-semibold font-mono" :class="item.promo_price != null ? 'text-success-600' : ''">
            {{ item.effective_price != null ? formatUnitPrice(Number(item.effective_price)) : (item.sale_price_without_vat != null ? formatUnitPrice(Number(item.sale_price_without_vat)) : '—') }}
          </div>
          <div v-if="item.promo_price != null" class="text-xs text-neutral-500 mt-0.5">
            <span class="line-through">{{ item.sale_price_without_vat != null ? formatUnitPrice(Number(item.sale_price_without_vat)) : '—' }}</span>
            <span class="ml-1">{{ item.promo_label ?? t('eshop.promo.title') }}</span>
          </div>
          <div v-if="salePriceWithVat != null" data-test="sale-price-with-vat" class="text-xs text-neutral-500 mt-0.5">
            {{ t('stock.item_detail.price_with_vat', { price: formatUnitPrice(salePriceWithVat) }) }}
          </div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">{{ t('stock.items.col_min_qty') }}</div>
          <div class="text-lg font-semibold font-mono">{{ item.min_qty ?? '—' }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500">EAN</div>
          <div class="text-lg font-semibold font-mono">{{ item.ean ?? '—' }}</div>
        </div>
      </div>

      <!-- Stav a skladová cena po skladech -->
      <div v-if="warehouseLevels.length" data-test="warehouse-levels" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <div class="px-5 py-3 border-b border-neutral-200">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stock.item_detail.levels_title') }}</h3>
          <p class="text-xs text-neutral-500 mt-0.5">{{ t('stock.item_detail.levels_hint') }}</p>
        </div>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.item_detail.col_warehouse') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.item_detail.col_qty') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.item_detail.col_avg_cost') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.item_detail.col_value') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in warehouseLevels" :key="row.warehouse_id">
                <td class="px-3 py-2">{{ row.warehouse_name }} <span class="font-mono text-xs text-neutral-400">{{ row.warehouse_code }}</span></td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ row.qty }} {{ item.unit }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(Number(row.avg_unit_cost)) }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(Number(row.value_total)) }}</td>
              </tr>
            </tbody>
            <tfoot v-if="warehouseLevels.length > 1">
              <tr class="border-t-2 border-neutral-300 font-semibold bg-neutral-50">
                <td class="px-3 py-2">{{ t('stock.item_detail.levels_total') }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ warehouseTotals.qty }} {{ item.unit }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ warehouseTotals.avg == null ? '—' : formatMoney(warehouseTotals.avg) }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(warehouseTotals.value) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>
      </div>

      <div v-if="parameterRows.length || item.note" class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
        <div v-if="parameterRows.length" data-test="item-parameters" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <div class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stock.item_detail.parameters_title') }}</h3>
          </div>
          <dl class="divide-y divide-neutral-100">
            <div v-for="row in parameterRows" :key="row.attributeId" class="px-5 py-2 flex flex-wrap justify-between gap-2 text-sm">
              <dt class="text-neutral-500">{{ row.name }}</dt>
              <dd class="font-medium text-neutral-900 text-right">{{ row.value }}</dd>
            </div>
          </dl>
        </div>
        <div v-if="item.note" data-test="item-note" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
          <div class="px-5 py-3 border-b border-neutral-200">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stock.item_detail.note_title') }}</h3>
          </div>
          <p class="px-5 py-3 text-sm text-neutral-800 whitespace-pre-line">{{ item.note }}</p>
        </div>
      </div>

      <ItemTemplatesPanel v-if="canManageLifecycle" :item="item" @created="duplicated" />

      <ProductRelationsPanel :item-id="id" :can-write="canManageLifecycle" class="mb-5" />

      <!-- Balení (issue #17): přehled, úpravy v editoru karty -->
      <div v-if="packaging" data-test="packaging-overview" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <div class="px-5 py-3 border-b border-neutral-200 flex flex-wrap items-center justify-between gap-2">
          <div class="min-w-0">
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stock.packaging.title') }}</h3>
            <p class="text-xs text-neutral-500 mt-0.5">{{ t('stock.packaging.overview_hint', { unit: packaging.base_unit }) }}</p>
          </div>
          <RouterLink v-if="auth.canWrite('stock.items.write')" :to="`/stock/items/${id}/edit`" :class="btnOutline('warning')" class="whitespace-nowrap">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 3.487a2.25 2.25 0 0 1 3.182 3.182L8.25 18.463 3.75 19.5l1.037-4.5L16.862 3.487z" /></svg>
            {{ t('stock.packaging.edit') }}
          </RouterLink>
        </div>
        <p v-if="packaging.units.length === 0" class="px-5 py-4 text-sm text-neutral-500">{{ t('stock.packaging.empty') }}</p>
        <div v-else class="divide-y divide-neutral-100">
          <div v-for="unit in packaging.units" :key="unit.unit_code" class="px-5 py-3 flex flex-wrap items-center justify-between gap-2 text-sm">
            <div class="flex flex-wrap items-center gap-2">
              <span class="font-mono font-medium">{{ t('stock.packaging.ratio', { code: unit.unit_code, factor: unit.factor, unit: packaging.base_unit }) }}</span>
              <span v-if="unit.name" class="text-neutral-500">{{ unit.name }}</span>
              <span v-if="packaging.default_sale_unit && unit.unit_code.toLowerCase() === packaging.default_sale_unit.toLowerCase()"
                class="text-xs px-2 py-0.5 rounded-full border border-primary-500/40 bg-primary-50 text-primary-600">{{ t('stock.packaging.default_badge') }}</span>
            </div>
            <span class="font-mono text-neutral-500">{{ unit.ean ? `EAN ${unit.ean}` : '—' }}</span>
          </div>
        </div>
      </div>

      <div v-if="tracking" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <div class="px-5 py-3 border-b border-neutral-200">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stock.tracking.title') }}</h3>
        </div>
        <div v-if="tracking.inventory.length === 0" class="px-5 py-6 text-sm text-neutral-500">{{ t('stock.tracking.empty') }}</div>
        <div v-else class="divide-y divide-neutral-100">
          <div v-for="row in tracking.inventory" :key="`${row.stock_tracking_unit_id}-${row.warehouse_id}-${row.location_id ?? 0}`" class="px-5 py-3 flex flex-wrap items-center justify-between gap-2 text-sm">
            <div>
              <span class="font-mono font-medium">{{ row.serial_number ?? row.lot_code }}</span>
              <span v-if="row.expires_on" class="ml-2 text-neutral-500">{{ t('stock.tracking.expires') }} {{ formatDate(row.expires_on) }}</span>
            </div>
            <div class="text-right">
              <span class="font-mono font-semibold">{{ row.quantity }} {{ tracking.base_unit }}</span>
              <span class="ml-2 text-neutral-500">{{ row.warehouse_code }}<template v-if="row.location_code"> / {{ row.location_code }}</template></span>
            </div>
          </div>
        </div>
      </div>

      <!-- Tab Pohyby -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-5 py-3 border-b border-neutral-200">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stock.item_detail.tab_movements') }}</h3>
        </div>
        <EmptyState v-if="movements.length === 0 && !movLoading" dense accent="neutral" icon="swap"
          :title="t('stock.item_detail.empty_movements')"
          :message="t('stock.item_detail.empty_movements_hint')" />
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.item_detail.col_date') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.item_detail.col_doc') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.item_detail.col_partner') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.item_detail.col_warehouse') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.item_detail.col_qty') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.item_detail.col_sale_price') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.item_detail.col_unit_cost') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.item_detail.col_value') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.item_detail.col_balance') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="m in movements" :key="m.line_id" class="hover:bg-neutral-50" :class="{ 'opacity-50': m.status === 'reversed' }">
                <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(m.doc_date) }}</td>
                <td class="px-3 py-2">
                  <RouterLink v-if="m.document_id" :to="`/stock/documents/${m.document_id}`" class="font-mono text-xs text-primary-600 hover:text-primary-700">
                    {{ m.doc_number || `#${m.document_id}` }}
                  </RouterLink>
                  <span class="text-xs text-neutral-400 ml-1">{{ t(`stock.doc_type.${m.doc_type}`) }}</span>
                  <span v-if="m.imported" class="ml-1 rounded bg-neutral-100 px-1.5 py-0.5 text-[10px] text-neutral-600" :title="t('stock.history_import.imported_hint')">{{ t('stock.history_import.imported_badge') }}</span>
                </td>
                <!-- Faktura a protistrana, která pohyb vyvolala, s proklikem rovnou na doklad. -->
                <td class="px-3 py-2 min-w-[10rem]">
                  <div v-if="m.invoice_id || m.purchase_invoice_id" class="flex flex-wrap items-center gap-1">
                    <RouterLink v-if="m.invoice_id" :to="`/invoices/${m.invoice_id}`" class="font-mono text-xs text-primary-600 hover:text-primary-700">
                      {{ m.invoice_number || `#${m.invoice_id}` }}
                    </RouterLink>
                    <RouterLink v-else :to="`/purchase-invoices/${m.purchase_invoice_id}`" class="font-mono text-xs text-primary-600 hover:text-primary-700">
                      {{ m.purchase_invoice_number || `#${m.purchase_invoice_id}` }}
                    </RouterLink>
                    <span v-if="m.invoice_type === 'credit_note'" class="text-xs px-1.5 py-0.5 rounded bg-warning-50 text-warning-700">{{ t('stock.item_detail.credit_note') }}</span>
                  </div>
                  <template v-if="m.partner">
                    <RouterLink v-if="m.partner.id" :to="`/clients/${m.partner.id}`" class="block truncate text-xs text-neutral-600 hover:text-primary-700">{{ m.partner.name }}</RouterLink>
                    <span v-else class="block truncate text-xs text-neutral-600">{{ m.partner.name }}</span>
                  </template>
                  <span v-if="!m.invoice_id && !m.purchase_invoice_id && !m.partner" class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2">{{ m.warehouse_code }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap" :class="num(m.qty_signed) < 0 ? 'text-danger-500' : 'text-success-600'">
                  {{ num(m.qty_signed) > 0 ? '+' : '' }}{{ m.qty_signed }}
                </td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">
                  <template v-if="m.sale_unit_price !== null">
                    {{ formatMoney(Number(m.sale_unit_price), m.sale_currency || 'CZK') }}<span v-if="m.sale_unit" class="text-xs text-neutral-400"> / {{ m.sale_unit }}</span>
                  </template>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(Number(m.unit_cost)) }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(Number(m.value_total)) }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ m.balance_after ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div v-if="movHasMore" class="px-5 py-3 border-t border-neutral-100 text-center">
          <button type="button" @click="loadMovements()" :disabled="movLoading" :class="btnOutline('neutral')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
            {{ movLoading ? t('common.loading') : t('stock.item_detail.load_more') }}
          </button>
        </div>
      </div>
      </div>
      <div v-show="detailTab === 'intrastat'" role="tabpanel" class="rounded-lg border border-neutral-200 bg-surface p-5 shadow-sm">
        <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-base font-semibold text-neutral-900">{{ t('stock.items.intrastat.title') }}</h2>
            <p class="mt-1 text-sm text-neutral-500">{{ t('stock.items.intrastat.detail_hint') }}</p>
          </div>
          <RouterLink v-if="auth.canWrite('stock.items.write')" :to="{ path: `/stock/items/${id}/edit`, query: { tab: 'intrastat' } }" :class="btnOutline('warning')">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 3.487a2.25 2.25 0 0 1 3.182 3.182L8.25 18.463 3.75 19.5l1.037-4.5L16.862 3.487z" /></svg>
            {{ t('stock.item_detail.edit') }}
          </RouterLink>
        </div>
        <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <div><dt class="text-xs font-medium text-neutral-500">{{ t('stock.items.intrastat.cn8_code') }}</dt><dd class="mt-1 font-mono text-sm text-neutral-900">{{ item.intrastat_cn8_code || '-' }}</dd></div>
          <div><dt class="text-xs font-medium text-neutral-500">{{ t('stock.items.intrastat.country_of_origin') }}</dt><dd class="mt-1 font-mono text-sm text-neutral-900">{{ item.intrastat_country_of_origin || '-' }}</dd></div>
          <div><dt class="text-xs font-medium text-neutral-500">{{ t('stock.items.intrastat.net_mass_kg') }}</dt><dd class="mt-1 font-mono text-sm text-neutral-900">{{ item.intrastat_net_mass_kg ?? '-' }}</dd></div>
          <div><dt class="text-xs font-medium text-neutral-500">{{ t('stock.items.intrastat.supplementary_unit') }}</dt><dd class="mt-1 font-mono text-sm text-neutral-900">{{ item.intrastat_supplementary_unit || '-' }}</dd></div>
          <div><dt class="text-xs font-medium text-neutral-500">{{ t('stock.items.intrastat.coefficient') }}</dt><dd class="mt-1 font-mono text-sm text-neutral-900">{{ item.intrastat_supplementary_unit_coefficient ?? '-' }}</dd></div>
        </dl>
      </div>
    </template>
    <ItemDuplicateDialog v-if="duplicateOpen && item" :item="item" @close="duplicateOpen = false" @created="duplicated" />
  </div>
</template>
