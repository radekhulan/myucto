<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { salesOrdersApi, type SalesOrder, type SalesOrderPaymentStatus, type SalesOrderPayload } from '@/api/salesOrders'
import { clientsApi, type Client } from '@/api/clients'
import { stockApi, type StockItemSearchResult, type Warehouse } from '@/api/stock'
import { codebooksApi, type Currency, type VatRate } from '@/api/codebooks'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { useAuthStore } from '@/stores/auth'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const auth = useAuthStore()
const isNew = computed(() => route.name === 'stock-sales-order-new')
const id = computed(() => isNew.value ? null : Number(route.params.id))
const order = ref<SalesOrder | null>(null)
const clients = ref<Client[]>([])
const warehouses = ref<Warehouse[]>([])
const currencies = ref<Currency[]>([])
const vatRates = ref<VatRate[]>([])
const items = ref<StockItemSearchResult[]>([])
const loading = ref(false)
const acting = ref(false)
const error = ref('')

interface LineForm { line_uuid?: string; stock_item_id: number | null; warehouse_id: number | null; description: string; unit: string; quantity: string; unit_price: string; discount_percent: string; vat_rate_id: number | null; set_selections?: Record<string, unknown> | unknown[] }
const form = reactive({ client_id: null as number | null, currency_id: null as number | null, order_number: '', allocation_policy: 'all_or_nothing' as 'all_or_nothing' | 'partial', prices_include_vat: false, exchange_rate: '', reservation_expires_at: '' })
const lines = ref<LineForm[]>([blankLine()])
const editable = computed(() => !order.value || order.value.commercial_status === 'draft')
const hasReservationShortage = computed(() => {
  if (!order.value || order.value.commercial_status !== 'confirmed') return false
  const reserved = new Map((order.value.reservations ?? []).map(row => [row.source_line_id, Number(row.qty_reserved)]))
  return (order.value.lines ?? []).some(line => line.component_snapshot.some((component, componentNo) => {
    const required = Number(component.quantity ?? 0)
    return required > (reserved.get(`${line.line_uuid}#${componentNo}`) ?? 0) + 0.000_001
  }))
})

function blankLine(): LineForm { return { stock_item_id: null, warehouse_id: null, description: '', unit: 'ks', quantity: '1.000', unit_price: '0.000000', discount_percent: '0.00', vat_rate_id: null } }
function selectItem(line: LineForm) {
  const item = items.value.find(row => row.id === line.stock_item_id)
  if (!item) return
  line.description ||= item.name
  line.unit = item.unit
  line.vat_rate_id ||= item.vat_rate_id
}

async function loadReferences() {
  const [clientList, warehouseList, currencyList, vatList, itemList] = await Promise.all([
    clientsApi.list({ per_page: 500, role: 'customers' }), stockApi.listWarehouses(true),
    codebooksApi.currencies(), codebooksApi.vatRates(), stockApi.searchItems('', 500),
  ])
  clients.value = clientList.data
  warehouses.value = warehouseList
  currencies.value = currencyList
  vatRates.value = vatList
  items.value = itemList
}

async function loadOrder() {
  if (!id.value) return
  order.value = await salesOrdersApi.get(id.value)
  const current = order.value
  form.client_id = current.client_id
  form.currency_id = current.currency_id
  form.order_number = current.order_number
  form.allocation_policy = current.allocation_policy
  form.prices_include_vat = current.prices_include_vat
  form.exchange_rate = current.exchange_rate ?? ''
  form.reservation_expires_at = current.reservation_expires_at?.slice(0, 16) ?? ''
  lines.value = (current.lines ?? []).map(line => {
    const selections = line.product_snapshot.selections
    return { line_uuid: line.line_uuid, stock_item_id: line.stock_item_id, warehouse_id: line.warehouse_id, description: line.description, unit: line.unit, quantity: line.quantity, unit_price: line.unit_price, discount_percent: line.discount_percent, vat_rate_id: line.vat_rate_id, set_selections: selections !== null && typeof selections === 'object' ? selections as Record<string, unknown> | unknown[] : undefined }
  })
}

function payload(): SalesOrderPayload {
  if (!form.client_id || !form.currency_id || lines.value.length === 0 || lines.value.some(line => !line.vat_rate_id || Number(line.quantity) <= 0)) throw new Error(t('sales_orders.validation'))
  return { ...form, client_id: form.client_id, currency_id: form.currency_id, exchange_rate: form.exchange_rate || null, reservation_expires_at: form.reservation_expires_at || null, row_version: order.value?.row_version, lines: lines.value.map(line => ({ ...line, vat_rate_id: line.vat_rate_id!, set_selections: line.set_selections })) }
}

async function save() {
  acting.value = true; error.value = ''
  try {
    const saved = id.value ? await salesOrdersApi.update(id.value, payload()) : await salesOrdersApi.create(payload())
    toast.success(t('common.saved'))
    if (!id.value) await router.replace(`/stock/sales-orders/${saved.id}`)
    await loadOrder()
  } catch (e) { error.value = apiErrorMessage(e) } finally { acting.value = false }
}
async function confirmOrder() { if (!id.value) return; acting.value = true; try { order.value = await salesOrdersApi.confirm(id.value); toast.success(t('sales_orders.confirmed')) } catch (e) { toast.error(apiErrorMessage(e)) } finally { acting.value = false } }
async function reserveRemainder() { if (!id.value) return; acting.value = true; try { order.value = await salesOrdersApi.reserveRemainder(id.value); toast.success(t('sales_orders.remainder_reserved')) } catch (e) { toast.error(apiErrorMessage(e)) } finally { acting.value = false } }
async function cancelOrder() { if (!id.value || !confirm(t('sales_orders.cancel_confirm'))) return; acting.value = true; try { order.value = await salesOrdersApi.cancel(id.value); toast.success(t('sales_orders.cancelled')) } catch (e) { toast.error(apiErrorMessage(e)) } finally { acting.value = false } }
async function createInvoice() { if (!id.value) return; acting.value = true; try { const invoice = await salesOrdersApi.invoice(id.value); await router.push(`/invoices/${invoice.id}`) } catch (e) { toast.error(apiErrorMessage(e)) } finally { acting.value = false } }
async function openFulfillment() {
  if (!order.value) return
  await router.push(order.value.fulfillment_task_id
    ? { path: '/stock/fulfillment', query: { task: String(order.value.fulfillment_task_id) } }
    : { path: '/stock/fulfillment', query: { source_type: 'sales_order', source_id: order.value.order_uuid } })
}
async function changePayment(event: Event) { if (!id.value || !order.value) return; try { order.value = await salesOrdersApi.payment(id.value, (event.target as HTMLSelectElement).value as SalesOrderPaymentStatus, order.value.row_version) } catch (e) { toast.error(apiErrorMessage(e)); await loadOrder() } }

const actions = computed<ActionItem[]>(() => [
  { key: 'save', label: t('common.save'), icon: 'check', tier: 'primary', variant: 'primary', show: editable.value, loading: acting.value, run: save },
  { key: 'confirm', label: t('sales_orders.confirm'), icon: 'check', tier: 'primary', variant: 'success', show: order.value?.commercial_status === 'draft', loading: acting.value, run: confirmOrder },
  { key: 'reserve-remainder', label: t('sales_orders.reserve_remainder'), icon: 'check', tier: 'primary', variant: 'success', show: hasReservationShortage.value, loading: acting.value, run: reserveRemainder },
  { key: 'invoice', label: t('sales_orders.create_invoice'), icon: 'box', tier: 'secondary', variant: 'neutral', show: auth.canWrite('invoices.create') && (order.value?.commercial_status === 'confirmed' || order.value?.commercial_status === 'completed'), run: createInvoice },
  { key: 'fulfillment', label: t('stock.fulfillment.title'), icon: 'box', tier: 'secondary', variant: 'neutral', show: auth.canWrite('stock.documents.write') && !!order.value?.reservations?.some(row => Number(row.remaining_qty) > 0), run: openFulfillment },
  { key: 'cancel', label: t('sales_orders.cancel'), icon: 'x', tier: 'overflow', variant: 'danger', show: !!order.value && ['draft', 'confirmed'].includes(order.value.commercial_status), run: cancelOrder },
])

onMounted(async () => { loading.value = true; try { await loadReferences(); if (id.value) await loadOrder(); else { form.currency_id = currencies.value.find(c => c.is_default)?.id ?? currencies.value[0]?.id ?? null; const wh = warehouses.value.find(w => w.is_default)?.id ?? warehouses.value[0]?.id ?? null; lines.value[0]!.warehouse_id = wh } } catch (e) { error.value = apiErrorMessage(e) } finally { loading.value = false } })
</script>

<template>
  <div>
    <RouterLink to="/stock/sales-orders" class="text-sm text-primary-700 hover:underline">{{ t('sales_orders.back') }}</RouterLink>
    <div class="flex flex-wrap items-start justify-between gap-3 my-4"><div><h1 class="text-2xl font-semibold">{{ order?.order_number || t('sales_orders.new') }}</h1><p v-if="order" class="text-sm text-neutral-500 mt-1">{{ t(`sales_orders.commercial.${order.commercial_status}`) }} · {{ t(`sales_orders.fulfillment.${order.fulfillment_status}`) }}</p></div><ActionBar :actions="actions" /></div>
    <div v-if="error" class="bg-danger-50 text-danger-500 border border-danger-500/30 rounded-md p-3 mb-4">{{ error }}</div>
    <div v-if="loading" class="text-center text-neutral-500 py-12">{{ t('common.loading') }}</div>
    <template v-else>
      <section class="bg-surface border border-neutral-200 rounded-lg p-4 mb-4 grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
        <label class="text-sm"><span class="block text-neutral-600 mb-1">{{ t('sales_orders.customer') }}</span><select v-model="form.client_id" :disabled="!editable" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md"><option :value="null">-</option><option v-for="client in clients" :key="client.id" :value="client.id">{{ client.company_name }}</option></select></label>
        <label class="text-sm"><span class="block text-neutral-600 mb-1">{{ t('sales_orders.number') }}</span><input v-model="form.order_number" :disabled="!editable" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md" /></label>
        <label class="text-sm"><span class="block text-neutral-600 mb-1">{{ t('sales_orders.currency') }}</span><select v-model="form.currency_id" :disabled="!editable" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md"><option v-for="currency in currencies" :key="currency.id" :value="currency.id">{{ currency.code }}</option></select></label>
        <label class="text-sm"><span class="block text-neutral-600 mb-1">{{ t('sales_orders.policy') }}</span><select v-model="form.allocation_policy" :disabled="!editable" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md"><option value="all_or_nothing">{{ t('sales_orders.all_or_nothing') }}</option><option value="partial">{{ t('sales_orders.partial') }}</option></select></label>
        <label class="text-sm"><span class="block text-neutral-600 mb-1">{{ t('sales_orders.expiry') }}</span><input v-model="form.reservation_expires_at" :disabled="!editable" type="datetime-local" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md" /></label>
        <label class="flex items-end gap-2 h-10 mt-5 text-sm"><input v-model="form.prices_include_vat" :disabled="!editable" type="checkbox" />{{ t('sales_orders.prices_include_vat') }}</label>
        <label v-if="order" class="text-sm"><span class="block text-neutral-600 mb-1">{{ t('sales_orders.payment_state') }}</span><select :value="order.payment_status" class="w-full h-10 px-3 bg-surface border border-neutral-300 rounded-md" @change="changePayment"><option v-for="state in ['unpaid','authorized','partially_paid','paid','refunded','partially_refunded']" :key="state" :value="state">{{ t(`sales_orders.payment.${state}`) }}</option></select></label>
      </section>

      <section class="bg-surface border border-neutral-200 rounded-lg overflow-x-auto mb-4"><table class="w-full min-w-[900px] text-sm"><thead class="bg-neutral-50"><tr><th class="text-left p-3">{{ t('sales_orders.item') }}</th><th class="text-left p-3">{{ t('sales_orders.warehouse') }}</th><th class="text-right p-3">{{ t('sales_orders.quantity') }}</th><th class="text-right p-3">{{ t('sales_orders.price') }}</th><th class="text-right p-3">{{ t('sales_orders.discount') }}</th><th class="text-left p-3">{{ t('sales_orders.vat') }}</th><th class="w-12" /></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="(line, index) in lines" :key="line.line_uuid ?? index"><td class="p-2"><select v-model="line.stock_item_id" :disabled="!editable" class="w-full h-9 px-2 bg-surface border border-neutral-300 rounded" @change="selectItem(line)"><option :value="null">{{ line.description || '-' }}</option><option v-for="item in items" :key="item.id" :value="item.id">{{ item.sku }} - {{ item.name }}</option></select></td><td class="p-2"><select v-model="line.warehouse_id" :disabled="!editable" class="w-full h-9 px-2 bg-surface border border-neutral-300 rounded"><option v-for="warehouse in warehouses" :key="warehouse.id" :value="warehouse.id">{{ warehouse.name }}</option></select></td><td class="p-2"><input v-model="line.quantity" :disabled="!editable" class="w-24 h-9 px-2 text-right bg-surface border border-neutral-300 rounded" /></td><td class="p-2"><input v-model="line.unit_price" :disabled="!editable" class="w-28 h-9 px-2 text-right bg-surface border border-neutral-300 rounded" /></td><td class="p-2"><input v-model="line.discount_percent" :disabled="!editable" class="w-20 h-9 px-2 text-right bg-surface border border-neutral-300 rounded" /></td><td class="p-2"><select v-model="line.vat_rate_id" :disabled="!editable" class="h-9 px-2 bg-surface border border-neutral-300 rounded"><option v-for="vat in vatRates" :key="vat.id" :value="vat.id">{{ vat.rate_percent }} %</option></select></td><td class="p-2"><button v-if="editable && lines.length > 1" type="button" :class="btnOutlineSm('danger')" @click="lines.splice(index, 1)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" stroke-width="1.8" /></svg></button></td></tr></tbody></table></section>
      <button v-if="editable" type="button" :class="btnOutlineSm('neutral')" @click="lines.push(blankLine())"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.plus" stroke-width="1.8" /></svg>{{ t('sales_orders.add_line') }}</button>
      <div v-if="order" class="mt-4 bg-surface border border-neutral-200 rounded-lg p-4 flex flex-wrap justify-between gap-4"><div class="text-sm text-neutral-600">{{ t('sales_orders.reserved_summary', { reserved: order.reservations?.reduce((sum, row) => sum + Number(row.remaining_qty), 0).toFixed(3) ?? '0.000' }) }}</div><strong>{{ formatMoney(Number(order.total_with_vat), order.currency_code) }}</strong></div>
    </template>
  </div>
</template>
