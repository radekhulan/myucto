<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { purchaseOrdersApi, type PurchaseOrderReceiptProposal } from '@/api/purchaseOrders'
import { stockApi, type Warehouse } from '@/api/stock'
import { useToast } from '@/composables/useToast'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'

/**
 * Příjem na sklad z objednávky dodavateli (Epic SKLAD, fáze 4).
 * Zobecnění `StockReceiptModal.vue` (příjem z přijaté faktury) pro řádky
 * objednávky — cena je tu ale jen ODHAD z objednávky (`cost_is_estimate`),
 * skutečná cena přijde až s fakturou. Doklad vznikne jako KONCEPT — uživatel
 * ho zaúčtuje v detailu skladového dokladu.
 */
const props = defineProps<{ orderId: number }>()
const emit = defineEmits<{ (e: 'close'): void }>()

const { t } = useI18n()
const toast = useToast()
const router = useRouter()

const loading = ref(true)
const saving = ref(false)
const error = ref('')

const proposal = ref<PurchaseOrderReceiptProposal | null>(null)
const warehouses = ref<Warehouse[]>([])
const warehouseId = ref<number | null>(null)
const docDate = ref(appIsoDate())
const description = ref('')
const allowOverDelivery = ref(false)

interface LineState {
  purchase_order_line_id: number
  stock_item_id: number | null
  sku: string | null
  description: string
  unit: string
  qty_ordered: string
  qty_received: string
  remaining: string
  quantity: string
  unit_cost: string
  cost_is_estimate: boolean
}
const lines = ref<LineState[]>([])

async function load() {
  loading.value = true
  try {
    const [prop, whs] = await Promise.all([
      purchaseOrdersApi.receiptPropose(props.orderId),
      stockApi.listWarehouses(true),
    ])
    proposal.value = prop
    warehouses.value = whs
    warehouseId.value = prop.order.warehouse_id ?? whs.find(w => w.is_default)?.id ?? whs[0]?.id ?? null
    lines.value = prop.lines.map(l => ({
      purchase_order_line_id: l.purchase_order_line_id,
      stock_item_id: l.stock_item_id,
      sku: l.sku,
      description: l.description,
      unit: l.unit,
      qty_ordered: l.qty_ordered,
      qty_received: l.qty_received,
      remaining: l.remaining_qty,
      quantity: l.remaining_qty,
      unit_cost: l.unit_cost,
      cost_is_estimate: l.cost_is_estimate,
    }))
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    loading.value = false
  }
}
onMounted(load)

const hasEstimatedCost = computed(() => proposal.value?.cost_is_estimate || lines.value.some(l => l.cost_is_estimate))

// Klientský guard proti přeplnění — stejná tolerance jako u příjmu z PF.
function lineOver(l: LineState): boolean {
  return Number(l.quantity) > Number(l.remaining) + 0.0005
}
const hasOver = computed(() => lines.value.some(lineOver))

const canSubmit = computed(() =>
  !!warehouseId.value
  && (allowOverDelivery.value || !hasOver.value)
  && lines.value.some(l => Number(l.quantity) > 0),
)

async function submit() {
  if (!canSubmit.value) return
  saving.value = true
  error.value = ''
  try {
    const doc = await purchaseOrdersApi.receiptCreate(props.orderId, {
      warehouse_id: warehouseId.value!,
      doc_date: docDate.value,
      description: description.value.trim() || undefined,
      allow_over_delivery: allowOverDelivery.value || undefined,
      lines: lines.value
        .filter(l => Number(l.quantity) > 0)
        .map(l => ({
          purchase_order_line_id: l.purchase_order_line_id,
          qty: l.quantity,
          unit_cost: l.unit_cost || undefined,
        })),
    })
    toast.success(t('common.saved'))
    emit('close')
    router.push(`/stock/documents/${doc.id}`)
  } catch (e: any) {
    const err = e?.response?.data?.error
    const code = err?.code
    if (code === 'over_receipt' || code === 'stock.error.over_receipt') {
      error.value = t('stock.orders.receipt_modal.over_receipt_error')
    } else {
      error.value = err?.message || t('common.error')
    }
  } finally {
    saving.value = false
  }
}
</script>

<template>
  <div class="fixed inset-0 bg-black/40 z-50 flex items-start justify-center p-4 overflow-y-auto" @click.self="emit('close')">
    <div class="bg-surface rounded-xl shadow-lg max-w-3xl w-full my-8">
      <header class="px-5 py-4 border-b border-neutral-200 flex items-center justify-between gap-3">
        <h3 class="text-lg font-semibold">{{ t('stock.orders.receipt_modal.title') }}</h3>
        <button @click="emit('close')" class="cursor-pointer text-neutral-400 hover:text-neutral-700 text-2xl leading-none">&times;</button>
      </header>

      <div class="p-5 space-y-4">
        <div v-if="loading" class="text-center text-neutral-500 py-8 text-sm">{{ t('common.loading') }}</div>
        <template v-else-if="proposal">
          <div v-if="hasEstimatedCost" class="px-3 py-2.5 rounded-md bg-warning-50 border border-warning-500/30 text-warning-700 text-sm flex items-start gap-2">
            <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
            </svg>
            <span>{{ t('stock.orders.receipt_modal.cost_estimate_warning') }}</span>
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.orders.receipt_modal.field_warehouse') }}</label>
              <select v-model="warehouseId" class="w-full h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
                <option :value="null">—</option>
                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.orders.receipt_modal.field_date') }}</label>
              <DateInput v-model="docDate" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.orders.receipt_modal.field_description') }}</label>
              <input v-model="description" type="text" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>

          <div class="border border-neutral-200 rounded-lg overflow-hidden">
            <div v-for="(l, i) in lines" :key="l.purchase_order_line_id" class="p-3 border-b border-neutral-100 last:border-b-0 space-y-2">
              <div class="text-sm font-medium flex items-center gap-2">
                <span class="font-mono text-xs text-neutral-400" v-if="l.sku">{{ l.sku }}</span>
                <span>{{ l.description }}</span>
                <span v-if="l.cost_is_estimate" class="text-[10px] px-1.5 py-0.5 rounded bg-warning-50 text-warning-600 font-medium">
                  {{ t('stock.orders.receipt_modal.estimate_badge') }}
                </span>
              </div>
              <div class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-start">
                <div class="sm:col-span-4 text-xs text-neutral-400">
                  {{ t('stock.orders.receipt_modal.col_ordered') }}: {{ l.qty_ordered }} {{ l.unit }}
                  · {{ t('stock.orders.receipt_modal.col_received') }}: {{ l.qty_received }} {{ l.unit }}
                </div>
                <div class="sm:col-span-4">
                  <label class="block text-[10px] text-neutral-400">{{ t('stock.orders.receipt_modal.col_remaining') }}: {{ l.remaining }}</label>
                  <input v-model="lines[i].quantity" type="number" step="0.001" min="0"
                    :class="['w-full h-9 px-2 border rounded-md text-right font-mono text-sm', lineOver(l) ? 'border-danger-400 bg-danger-50' : 'border-neutral-300']" />
                  <p v-if="lineOver(l)" class="text-[10px] text-danger-500 mt-0.5">{{ t('stock.orders.receipt_modal.over_receipt_error') }}</p>
                </div>
                <div class="sm:col-span-4">
                  <label class="block text-[10px] text-neutral-400">{{ t('stock.orders.receipt_modal.col_unit_cost') }}</label>
                  <input v-model="lines[i].unit_cost" type="number" step="0.000001" min="0"
                    class="w-full h-9 px-2 border border-neutral-300 rounded-md text-right font-mono text-sm" />
                </div>
              </div>
            </div>
          </div>

          <label v-if="hasOver" class="inline-flex items-center gap-2 text-sm cursor-pointer text-warning-700">
            <input v-model="allowOverDelivery" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            {{ t('stock.orders.receipt_modal.allow_over_delivery') }}
          </label>

          <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
        </template>
      </div>

      <footer class="px-5 py-4 border-t border-neutral-200 flex justify-end gap-2">
        <button @click="emit('close')" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
        <button @click="submit" :disabled="!canSubmit || saving" :class="btnFilled('success')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
          {{ saving ? t('common.saving') : t('stock.orders.receipt_modal.submit') }}
        </button>
      </footer>
    </div>
  </div>
</template>
