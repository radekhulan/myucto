<script setup lang="ts">
import { ref, reactive, computed, onMounted, useId } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  purchaseOrdersApi, type PurchaseOrderDetail, type PurchaseOrderState,
  type PurchaseOrderPayload, type PurchaseOrderLine,
} from '@/api/purchaseOrders'
import { stockApi, type Warehouse, type StockItemSearchResult } from '@/api/stock'
import { codebooksApi, type Currency, type VatRate, type Unit } from '@/api/codebooks'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { formatMoney, formatDate } from '@/composables/useFormat'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import VendorPicker from '@/components/purchase/VendorPicker.vue'
import ExchangeRateInput from '@/components/purchase/ExchangeRateInput.vue'
import PurchaseOrderReceiptModal from '@/components/stock/PurchaseOrderReceiptModal.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()
const pageId = useId()

const isNew = computed(() => route.name === 'stock-purchase-order-new')
const orderId = computed<number | null>(() => (isNew.value ? null : Number(route.params.id)))

const order = ref<PurchaseOrderDetail | null>(null)
const loading = ref(false)
const error = ref('')
const saving = ref(false)
const acting = ref(false)

const warehouses = ref<Warehouse[]>([])
const currencies = ref<Currency[]>([])
const vatRates = ref<VatRate[]>([])
const units = ref<Unit[]>([])

const form = reactive({
  vendor_id: null as number | null,
  warehouse_id: null as number | null,
  order_date: appIsoDate(),
  expected_date: '' as string,
  currency_id: null as number | null,
  exchange_rate: '' as string,
  vendor_reference: '',
  note: '',
  internal_note: '',
})

interface LineRow {
  id: number | null
  stock_item_id: number | null
  option: { value: number; label: string; secondary?: string } | null
  vendor_sku: string
  description: string
  unit: string
  qty_ordered: string
  unit_price: string
  vat_rate_id: number | null
  expected_date: string
  note: string
  // read-only fulfilment (jen u načtené/existující objednávky)
  qty_confirmed: string | null
  qty_received: string
  qty_effective: string
  qty_remaining: string
  has_over_delivery: boolean
}
function blankLine(): LineRow {
  return {
    id: null, stock_item_id: null, option: null, vendor_sku: '', description: '', unit: 'ks',
    qty_ordered: '1', unit_price: '', vat_rate_id: null, expected_date: '', note: '',
    qty_confirmed: null, qty_received: '0', qty_effective: '0', qty_remaining: '0', has_over_delivery: false,
  }
}
const lines = ref<LineRow[]>([blankLine()])

// Per-řádek stav SearchableSelect (remote), stejný vzor jako DocumentEditor.
const rowOptions = reactive<Record<number, { value: number; label: string; secondary?: string }[]>>({})
const rowLoading = reactive<Record<number, boolean>>({})
const itemsCache = new Map<number, StockItemSearchResult>()

const isDraftMode = computed(() => !order.value || order.value.state === 'draft')
const readOnly = computed(() => !isDraftMode.value)

const currencyCode = computed(() => currencies.value.find(c => c.id === form.currency_id)?.code ?? 'CZK')

async function loadRefData() {
  try {
    const [whs, curr, vat, un] = await Promise.all([
      stockApi.listWarehouses(true),
      codebooksApi.currencies(),
      codebooksApi.vatRates(),
      codebooksApi.units(),
    ])
    warehouses.value = whs
    currencies.value = curr
    vatRates.value = vat
    units.value = un
  } catch { /* ignore — selecty zůstanou prázdné */ }
}

function lineFromServer(l: PurchaseOrderLine): LineRow {
  return {
    id: l.id,
    stock_item_id: l.stock_item_id,
    option: l.sku ? { value: l.stock_item_id!, label: `${l.sku} — ${l.item_name}`, secondary: l.unit } : null,
    vendor_sku: l.vendor_sku ?? '',
    description: l.description,
    unit: l.unit,
    qty_ordered: l.qty_ordered,
    unit_price: l.unit_price,
    vat_rate_id: l.vat_rate_id,
    expected_date: l.expected_date ? l.expected_date.slice(0, 10) : '',
    note: l.note ?? '',
    qty_confirmed: l.qty_confirmed,
    qty_received: l.qty_received,
    qty_effective: l.qty_effective,
    qty_remaining: l.qty_remaining,
    has_over_delivery: l.has_over_delivery,
  }
}

async function loadOrder() {
  if (!orderId.value) return
  loading.value = true
  try {
    const o = await purchaseOrdersApi.get(orderId.value)
    order.value = o
    form.vendor_id = o.vendor_id
    form.warehouse_id = o.warehouse_id
    form.order_date = o.order_date.slice(0, 10)
    form.expected_date = o.expected_date ? o.expected_date.slice(0, 10) : ''
    form.currency_id = o.currency_id
    form.exchange_rate = o.exchange_rate ?? ''
    form.vendor_reference = o.vendor_reference ?? ''
    form.note = o.note ?? ''
    form.internal_note = o.internal_note ?? ''
    lines.value = o.lines.length > 0 ? o.lines.map(lineFromServer) : [blankLine()]
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    loading.value = false
  }
}

async function onSearch(rowIndex: number, q: string) {
  rowLoading[rowIndex] = true
  try {
    const res = await stockApi.searchItems(q, 30)
    for (const r of res) itemsCache.set(r.id, r)
    rowOptions[rowIndex] = res.map(r => ({
      value: r.id,
      label: `${r.sku} — ${r.name}`,
      secondary: r.unit,
    }))
  } catch { rowOptions[rowIndex] = [] } finally { rowLoading[rowIndex] = false }
}
function onSelect(rowIndex: number, itemId: number | null) {
  const row = lines.value[rowIndex]
  if (!row) return
  row.stock_item_id = itemId
  if (itemId === null) { row.option = null; return }
  const si = itemsCache.get(itemId)
  if (si) {
    row.option = { value: si.id, label: `${si.sku} — ${si.name}`, secondary: si.unit }
    if (!row.description) row.description = si.name
    if (!row.unit || row.unit === 'ks') row.unit = si.unit
    if (!row.vat_rate_id && si.vat_rate_id) row.vat_rate_id = si.vat_rate_id
  }
}

function addLine() { lines.value.push(blankLine()) }
function removeLine(i: number) { lines.value.splice(i, 1) }

function validate(): boolean {
  error.value = ''
  if (!form.vendor_id) { error.value = t('stock.orders.field_vendor'); return false }
  if (!form.warehouse_id) { error.value = t('stock.orders.field_warehouse'); return false }
  if (!form.currency_id) { error.value = t('stock.orders.field_currency'); return false }
  if (!form.order_date) { error.value = t('stock.orders.field_order_date'); return false }
  if (lines.value.filter(l => l.description.trim() && Number(l.qty_ordered) > 0).length === 0) {
    error.value = t('stock.orders.no_lines'); return false
  }
  return true
}

function buildPayload(): PurchaseOrderPayload {
  const rows = lines.value.filter(l => l.description.trim() && Number(l.qty_ordered) > 0)
  return {
    vendor_id: form.vendor_id!,
    order_date: form.order_date,
    expected_date: form.expected_date || undefined,
    warehouse_id: form.warehouse_id!,
    currency_id: form.currency_id!,
    exchange_rate: form.exchange_rate || undefined,
    vendor_reference: form.vendor_reference.trim() || undefined,
    note: form.note.trim() || undefined,
    internal_note: form.internal_note.trim() || undefined,
    lines: rows.map(l => ({
      stock_item_id: l.stock_item_id ?? undefined,
      vendor_sku: l.vendor_sku.trim() || undefined,
      description: l.description.trim(),
      unit: l.unit.trim() || undefined,
      qty_ordered: l.qty_ordered,
      unit_price: l.unit_price || undefined,
      vat_rate_id: l.vat_rate_id ?? undefined,
      expected_date: l.expected_date || undefined,
      note: l.note.trim() || undefined,
    })),
  }
}

async function saveDraft() {
  if (!validate()) return
  saving.value = true
  error.value = ''
  try {
    const payload = buildPayload()
    if (orderId.value) {
      order.value = await purchaseOrdersApi.update(orderId.value, payload)
    } else {
      order.value = await purchaseOrdersApi.create(payload)
      router.replace(`/stock/purchase-orders/${order.value.id}`)
    }
    toast.success(t('common.saved'))
    await loadOrder()
  } catch (e) {
    error.value = apiErrorMessage(e)
  } finally {
    saving.value = false
  }
}

async function send() {
  if (!validate()) return
  acting.value = true
  error.value = ''
  try {
    let id = orderId.value
    if (isDraftMode.value) {
      const payload = buildPayload()
      if (id) {
        order.value = await purchaseOrdersApi.update(id, payload)
      } else {
        order.value = await purchaseOrdersApi.create(payload)
        id = order.value.id
        router.replace(`/stock/purchase-orders/${id}`)
      }
    }
    order.value = await purchaseOrdersApi.send(id!)
    toast.success(t('common.saved'))
    await loadOrder()
  } catch (e) {
    const msg = apiErrorMessage(e)
    error.value = msg
    toast.error(msg)
  } finally {
    acting.value = false
  }
}

// ── Potvrzení objednávky (modal s per-řádkovým potvrzeným množstvím) ──────
const confirmModalOpen = ref(false)
const confirmExpectedDate = ref('')
interface ConfirmLine { id: number; description: string; qty_ordered: string; qty_confirmed: string }
const confirmLines = ref<ConfirmLine[]>([])
function openConfirmModal() {
  if (!order.value) return
  confirmExpectedDate.value = order.value.expected_date ? order.value.expected_date.slice(0, 10) : ''
  confirmLines.value = order.value.lines.map(l => ({
    id: l.id, description: l.description, qty_ordered: l.qty_ordered,
    qty_confirmed: l.qty_confirmed ?? l.qty_ordered,
  }))
  confirmModalOpen.value = true
}
async function submitConfirm() {
  if (!orderId.value) return
  acting.value = true
  try {
    order.value = await purchaseOrdersApi.confirm(orderId.value, {
      expected_date: confirmExpectedDate.value || undefined,
      lines: confirmLines.value.map(l => ({ id: l.id, qty_confirmed: l.qty_confirmed || undefined })),
    })
    toast.success(t('common.saved'))
    confirmModalOpen.value = false
    await loadOrder()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    acting.value = false
  }
}

// ── Storno / Zavřít zbytek — sdílený modal s důvodem ──────────────────────
const reasonModalOpen = ref(false)
const reasonModalMode = ref<'cancel' | 'close'>('cancel')
const reasonText = ref('')
function openReasonModal(mode: 'cancel' | 'close') {
  reasonModalMode.value = mode
  reasonText.value = ''
  reasonModalOpen.value = true
}
async function submitReason() {
  if (!orderId.value) return
  acting.value = true
  try {
    if (reasonModalMode.value === 'cancel') {
      order.value = await purchaseOrdersApi.cancel(orderId.value, reasonText.value.trim())
    } else {
      order.value = await purchaseOrdersApi.close(orderId.value, reasonText.value.trim())
    }
    toast.success(t('common.saved'))
    reasonModalOpen.value = false
    await loadOrder()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    acting.value = false
  }
}

async function reopen() {
  if (!orderId.value) return
  acting.value = true
  try {
    order.value = await purchaseOrdersApi.reopen(orderId.value)
    toast.success(t('common.saved'))
    await loadOrder()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    acting.value = false
  }
}

async function remove() {
  if (!orderId.value) return
  if (!confirm(t('stock.orders.delete_confirm'))) return
  try {
    await purchaseOrdersApi.delete(orderId.value)
    toast.success(t('common.deleted'))
    router.push('/stock/purchase-orders')
  } catch (e) {
    toast.error(apiErrorMessage(e))
  }
}

const receiptModalOpen = ref(false)

const STATE_BADGE: Record<PurchaseOrderState, string> = {
  draft:               'bg-neutral-100 text-neutral-600',
  sent:                'bg-primary-50 text-primary-700',
  confirmed:           'bg-accent-50 text-accent-700',
  partially_received:  'bg-warning-50 text-warning-600',
  received:            'bg-success-50 text-success-600',
  closed:              'bg-neutral-100 text-neutral-500',
  cancelled:           'bg-danger-50 text-danger-500',
}

const actions = computed<ActionItem[]>(() => {
  const w = auth.canWrite('stock.orders.write')
  const st = order.value?.state
  const items: ActionItem[] = []

  items.push({ key: 'send', label: t('stock.orders.send'), icon: 'send', tier: 'primary', variant: 'primary',
    show: isDraftMode.value && w, loading: acting.value, run: send })

  items.push({ key: 'confirm', label: t('stock.orders.confirm'), icon: 'check', tier: 'primary', variant: 'primary',
    show: st === 'sent' && w, run: openConfirmModal })

  items.push({ key: 'receive', label: t('stock.orders.receive'), icon: 'box', tier: 'primary', variant: 'success',
    show: (st === 'confirmed' || st === 'partially_received') && w, run: () => { receiptModalOpen.value = true } })

  items.push({ key: 'pdf', label: t('stock.orders.pdf'), icon: 'download', tier: 'secondary', variant: 'neutral',
    show: !!orderId.value, href: purchaseOrdersApi.pdfUrl(orderId.value ?? 0) })

  items.push({ key: 'close-remainder', label: t('stock.orders.close_remainder'), icon: 'archive', tier: 'overflow', variant: 'warning',
    show: (st === 'sent' || st === 'confirmed' || st === 'partially_received' || st === 'received') && w,
    run: () => openReasonModal('close') })

  items.push({ key: 'cancel', label: t('stock.orders.cancel'), icon: 'x', tier: 'overflow', variant: 'danger',
    show: (st === 'draft' || st === 'sent' || st === 'confirmed') && w, run: () => openReasonModal('cancel') })

  items.push({ key: 'reopen', label: t('stock.orders.reopen'), icon: 'uturn', tier: 'overflow', variant: 'neutral',
    show: st === 'closed' && w, disabled: acting.value, run: reopen })

  items.push({ key: 'delete', label: t('common.delete'), icon: 'trash', tier: 'overflow', variant: 'danger',
    show: st === 'draft' && w, run: remove })

  return items
})

onMounted(async () => {
  await loadRefData()
  if (isNew.value) {
    form.warehouse_id = warehouses.value.find(w => w.is_default)?.id ?? warehouses.value[0]?.id ?? null
    form.currency_id = currencies.value.find(c => c.code === 'CZK')?.id ?? currencies.value[0]?.id ?? null
    const qVendor = route.query.vendor_id
    if (typeof qVendor === 'string' && /^\d+$/.test(qVendor)) form.vendor_id = Number(qVendor)
  } else {
    await loadOrder()
  }
})
</script>

<template>
  <div>
    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <template v-else>
      <RouterLink to="/stock/purchase-orders" class="text-sm text-neutral-600 hover:text-neutral-900 mb-3 inline-block">
        {{ t('stock.orders.back_to_list') }}
      </RouterLink>

      <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
          <h1 class="text-2xl font-semibold flex items-center gap-2 flex-wrap">
            <span>{{ order?.order_number || t('stock.orders.new_title') }}</span>
            <span v-if="order" class="text-xs px-2 py-0.5 rounded font-medium" :class="STATE_BADGE[order.state]">
              {{ t(`stock.order_state.${order.state}`) }}
            </span>
          </h1>
          <p v-if="!order" class="text-sm text-neutral-500 mt-0.5">{{ t('stock.orders.draft_number') }}</p>
        </div>
        <ActionBar :actions="actions" />
      </div>

      <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500 mb-4">{{ error }}</div>

      <!-- Hlavička -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4 mb-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div v-if="isDraftMode">
            <VendorPicker v-model="form.vendor_id" />
          </div>
          <div v-else>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.orders.field_vendor') }}</label>
            <RouterLink v-if="order?.vendor_id" :to="`/clients/${order.vendor_id}`" class="text-primary-700 hover:underline text-sm">
              {{ order?.vendor_name || '—' }}
            </RouterLink>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.orders.field_warehouse') }}</label>
            <select v-model="form.warehouse_id" :disabled="readOnly" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface disabled:bg-neutral-50 text-sm">
              <option :value="null">—</option>
              <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
            </select>
          </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.orders.field_order_date') }}</label>
            <DateInput v-model="form.order_date" :disabled="readOnly" class="w-full h-10 px-3 border border-neutral-300 rounded-md disabled:bg-neutral-50 text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.orders.field_expected_date') }}</label>
            <DateInput v-model="form.expected_date" :disabled="readOnly" class="w-full h-10 px-3 border border-neutral-300 rounded-md disabled:bg-neutral-50 text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.orders.field_currency') }}</label>
            <select v-model="form.currency_id" :disabled="readOnly" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface disabled:bg-neutral-50 text-sm">
              <option :value="null">—</option>
              <option v-for="c in currencies" :key="c.id" :value="c.id">{{ c.code }}</option>
            </select>
          </div>
        </div>
        <div v-if="currencyCode !== 'CZK'" class="grid grid-cols-1 sm:grid-cols-3 gap-4">
          <ExchangeRateInput
            :model-value="form.exchange_rate === '' ? null : Number(form.exchange_rate)"
            :currency="currencyCode"
            :rate-date="form.order_date"
            :editable="!readOnly"
            @update:model-value="(v) => { form.exchange_rate = v === null ? '' : String(v) }"
          />
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.orders.field_vendor_reference') }}</label>
            <input v-model="form.vendor_reference" type="text" :disabled="readOnly" class="w-full h-10 px-3 border border-neutral-300 rounded-md disabled:bg-neutral-50 text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.orders.field_note') }}</label>
            <input v-model="form.note" type="text" :disabled="readOnly" class="w-full h-10 px-3 border border-neutral-300 rounded-md disabled:bg-neutral-50 text-sm" />
          </div>
        </div>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.orders.field_internal_note') }}</label>
          <textarea v-model="form.internal_note" :disabled="readOnly" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md disabled:bg-neutral-50 text-sm"></textarea>
        </div>
      </div>

      <!-- Řádky -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4">
        <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stock.orders.lines_title') }}</h3>
          <button v-if="isDraftMode" type="button" @click="addLine" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('stock.orders.add_line') }}
          </button>
        </div>

        <div v-if="isDraftMode" class="divide-y divide-neutral-100">
          <div v-if="lines.length === 0" class="px-5 py-6 text-center text-sm text-neutral-400">{{ t('stock.orders.no_lines') }}</div>
          <div v-for="(row, i) in lines" :key="i" class="p-3 sm:p-4 grid grid-cols-1 sm:grid-cols-12 gap-2 sm:gap-3 items-start">
            <div class="sm:col-span-4">
              <label class="block text-xs font-medium text-neutral-500 mb-1 sm:hidden">{{ t('stock.orders.col_item') }}</label>
              <SearchableSelect
                :model-value="row.stock_item_id"
                :options="rowOptions[i] ?? []"
                remote
                :loading="rowLoading[i]"
                :selected-option="row.option"
                :placeholder="t('stock.orders.col_item')"
                :no-results-label="t('common.no_results')"
                @search="(q: string) => onSearch(i, q)"
                @update:model-value="(v: number | null) => onSelect(i, v)"
              />
              <input v-model="row.description" type="text" :placeholder="t('stock.orders.col_description')"
                class="mt-1 w-full h-8 px-2 border border-neutral-300 rounded-md text-xs" />
            </div>
            <div class="sm:col-span-2">
              <label class="block text-xs font-medium text-neutral-500 mb-1 sm:hidden">{{ t('stock.orders.col_qty') }}</label>
              <input v-model="row.qty_ordered" type="number" step="0.001" min="0"
                class="w-full h-10 px-2 border border-neutral-300 rounded-md text-right font-mono text-sm" />
              <input v-model="row.unit" type="text" :placeholder="t('stock.orders.col_unit')" :list="`${pageId}-po-line-units`"
                class="mt-1 w-full h-8 px-2 border border-neutral-300 rounded-md text-xs text-right" />
            </div>
            <div class="sm:col-span-2">
              <label class="block text-xs font-medium text-neutral-500 mb-1 sm:hidden">{{ t('stock.orders.col_unit_price') }}</label>
              <input v-model="row.unit_price" type="number" step="0.000001" min="0"
                class="w-full h-10 px-2 border border-neutral-300 rounded-md text-right font-mono text-sm" />
              <select v-model="row.vat_rate_id" class="mt-1 w-full h-8 px-1 border border-neutral-300 rounded-md bg-surface text-xs">
                <option :value="null">{{ t('stock.orders.col_vat_rate') }}</option>
                <option v-for="v in vatRates" :key="v.id" :value="v.id">{{ v.rate_percent }}%</option>
              </select>
            </div>
            <div class="sm:col-span-3">
              <label class="block text-xs font-medium text-neutral-500 mb-1 sm:hidden">{{ t('stock.orders.col_vendor_sku') }}</label>
              <input v-model="row.vendor_sku" type="text" :placeholder="t('stock.orders.col_vendor_sku')"
                class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
              <DateInput v-model="row.expected_date" :title="t('stock.orders.col_line_expected')"
                class="mt-1 w-full h-8 px-2 border border-neutral-300 rounded-md text-xs" />
            </div>
            <div class="sm:col-span-1 flex sm:justify-end">
              <button type="button" @click="removeLine(i)" :title="t('common.delete')"
                class="cursor-pointer w-9 h-9 inline-flex items-center justify-center text-danger-500 hover:bg-danger-50 rounded-md">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              </button>
            </div>
          </div>
          <datalist :id="`${pageId}-po-line-units`">
            <option v-for="u in units" :key="u.id" :value="u.code" />
          </datalist>
        </div>

        <!-- Read-only zobrazení s plněním -->
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.orders.col_item') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.orders.fulfilment_ordered') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.orders.fulfilment_confirmed') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.orders.fulfilment_received') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.orders.fulfilment_remaining') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.orders.col_unit_price') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(row, i) in lines" :key="i">
                <td class="px-3 py-2">
                  <div class="flex items-center gap-1.5">
                    <span>{{ row.description }}</span>
                    <span v-if="row.has_over_delivery" class="text-[10px] px-1.5 py-0.5 rounded bg-warning-50 text-warning-600 font-medium">
                      {{ t('stock.orders.over_delivery_badge') }}
                    </span>
                  </div>
                  <div v-if="row.option" class="text-xs text-neutral-400 font-mono">{{ row.option.label }}</div>
                </td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ row.qty_ordered }} {{ row.unit }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ row.qty_confirmed ?? '—' }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ row.qty_received }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ row.qty_remaining }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(Number(row.unit_price), currencyCode) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Vzniklé příjemky -->
      <div v-if="order && order.receipts.length > 0" class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4">
        <div class="px-5 py-3 border-b border-neutral-200">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stock.orders.receipts_title') }}</h3>
        </div>
        <ul class="divide-y divide-neutral-100">
          <li v-for="r in order.receipts" :key="r.id" class="px-5 py-2.5 flex items-center justify-between gap-3 text-sm">
            <RouterLink :to="`/stock/documents/${r.id}`" class="font-mono text-primary-600 hover:text-primary-700">
              {{ r.doc_number || `#${r.id}` }}
            </RouterLink>
            <span class="text-neutral-500">{{ formatDate(r.doc_date) }}</span>
            <span class="text-neutral-500 truncate flex-1">{{ r.description }}</span>
          </li>
        </ul>
      </div>
      <div v-else-if="order" class="text-sm text-neutral-400 mb-4">{{ t('stock.orders.no_receipts') }}</div>

      <!-- Jediné sdílené „Uložit" — jen v konceptu -->
      <div v-if="isDraftMode" class="sticky bottom-0 mt-2 flex flex-wrap justify-end gap-2 border-t border-neutral-200 bg-surface py-3">
        <RouterLink to="/stock/purchase-orders" :class="btnOutline('neutral')">{{ t('common.cancel') }}</RouterLink>
        <button type="button" @click="saveDraft" :disabled="saving" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
          {{ saving ? t('common.saving') : t('stock.orders.save') }}
        </button>
      </div>

      <!-- Modal: potvrzení objednávky -->
      <div v-if="confirmModalOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="confirmModalOpen = false">
        <div class="bg-surface rounded-xl shadow-lg max-w-lg w-full p-5">
          <h3 class="text-lg font-semibold mb-1">{{ t('stock.orders.confirm_modal_title') }}</h3>
          <p class="text-sm text-neutral-500 mb-3">{{ t('stock.orders.confirm_modal_hint') }}</p>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.orders.confirm_expected_date') }}</label>
          <DateInput v-model="confirmExpectedDate" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm mb-3" />
          <div class="border border-neutral-200 rounded-lg overflow-hidden mb-3">
            <div v-for="l in confirmLines" :key="l.id" class="p-2.5 border-b border-neutral-100 last:border-b-0 flex items-center gap-2">
              <span class="flex-1 text-sm truncate">{{ l.description }}</span>
              <span class="text-xs text-neutral-400">{{ t('stock.orders.fulfilment_ordered') }}: {{ l.qty_ordered }}</span>
              <input v-model="l.qty_confirmed" type="number" step="0.001" min="0"
                class="w-24 h-8 px-2 border border-neutral-300 rounded-md text-right font-mono text-xs" />
            </div>
          </div>
          <div class="flex flex-wrap justify-end gap-2">
            <button @click="confirmModalOpen = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
            <button @click="submitConfirm" :disabled="acting" :class="btnFilled('primary')">
              {{ acting ? t('common.saving') : t('stock.orders.confirm') }}
            </button>
          </div>
        </div>
      </div>

      <!-- Modal: storno / zavřít zbytek (sdílený, liší se jen textem) -->
      <div v-if="reasonModalOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="reasonModalOpen = false">
        <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
          <h3 class="text-lg font-semibold mb-1">
            {{ reasonModalMode === 'cancel' ? t('stock.orders.cancel_confirm_title') : t('stock.orders.close_confirm_title') }}
          </h3>
          <p class="text-sm text-neutral-500 mb-3">{{ order?.order_number }}</p>
          <label class="block text-sm font-medium text-neutral-700 mb-1">
            {{ reasonModalMode === 'cancel' ? t('stock.orders.cancel_reason') : t('stock.orders.close_reason') }}
          </label>
          <textarea v-model="reasonText" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm mb-3"></textarea>
          <div class="flex flex-wrap justify-end gap-2">
            <button @click="reasonModalOpen = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
            <button @click="submitReason" :disabled="acting" :class="btnFilled(reasonModalMode === 'cancel' ? 'danger' : 'warning')">
              {{ acting ? t('common.saving') : (reasonModalMode === 'cancel' ? t('stock.orders.cancel') : t('stock.orders.close_remainder')) }}
            </button>
          </div>
        </div>
      </div>

      <PurchaseOrderReceiptModal v-if="receiptModalOpen && orderId" :order-id="orderId" @close="receiptModalOpen = false" />
    </template>
  </div>
</template>
