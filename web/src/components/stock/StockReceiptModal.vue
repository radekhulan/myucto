<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { stockApi, type StockDocument, type StockReceiptProposal, type StockItemSearchResult, type Warehouse, type LandedCostAllocation } from '@/api/stock'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'

const props = defineProps<{ purchaseInvoiceId: number }>()
const emit = defineEmits<{ (e: 'close'): void; (e: 'created', document: StockDocument): void }>()

const { t } = useI18n()
const toast = useToast()

const loading = ref(true)
const saving = ref(false)
const error = ref('')

const proposal = ref<StockReceiptProposal | null>(null)
const warehouses = ref<Warehouse[]>([])

const warehouseId = ref<number | null>(null)
const docDate = ref(appIsoDate())
const includeLandedCosts = ref(false)

interface LineState {
  purchase_invoice_item_id: number
  description: string
  remaining: string
  quantity: string
  unit_cost: string
  stock_item_id: number | null
  option: { value: number; label: string; secondary?: string } | null
  creatingNew: boolean
  newName: string
}
const lines = ref<LineState[]>([])
const rowOptions = reactive<Record<number, { value: number; label: string; secondary?: string }[]>>({})
const rowLoading = reactive<Record<number, boolean>>({})
const itemsCache = new Map<number, StockItemSearchResult>()

// L3: částky drž jako string (DECIMAL konvence).
interface CostState { purchase_invoice_item_id?: number; description: string; amount: string; allocation: LandedCostAllocation; include: boolean }
const costs = ref<CostState[]>([])

async function load() {
  loading.value = true
  try {
    const [prop, whs] = await Promise.all([
      stockApi.receiptPropose(props.purchaseInvoiceId),
      stockApi.listWarehouses(true),
    ])
    proposal.value = prop
    warehouses.value = whs
    warehouseId.value = whs.find(w => w.is_default)?.id ?? whs[0]?.id ?? null
    lines.value = prop.lines.map(l => ({
      purchase_invoice_item_id: l.purchase_invoice_item_id,
      description: l.description,
      remaining: l.remaining_qty,
      quantity: l.remaining_qty,
      unit_cost: l.unit_cost,
      stock_item_id: l.stock_item_id,
      option: null,
      creatingNew: false,
      newName: l.description,
    }))
    costs.value = prop.cost_candidates.map(c => ({
      purchase_invoice_item_id: c.purchase_invoice_item_id,
      description: c.description,
      amount: String(c.amount),
      allocation: 'by_value',
      include: false,
    }))
    // Dotáhni labely pro už namapované karty.
    await Promise.all(lines.value.filter(l => l.stock_item_id != null).map(async (l) => {
      try {
        const si = await stockApi.getItem(l.stock_item_id!)
        itemsCache.set(si.id, { id: si.id, sku: si.sku, name: si.name, unit: si.unit, vat_rate_id: si.vat_rate_id, sale_price_without_vat: si.sale_price_without_vat })
        l.option = { value: si.id, label: `${si.sku} — ${si.name}`, secondary: si.unit }
      } catch { /* karta smazána */ }
    }))
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    loading.value = false
  }
}
onMounted(load)

async function onSearch(rowIndex: number, q: string) {
  rowLoading[rowIndex] = true
  try {
    const res = await stockApi.searchItems(q, 30)
    for (const r of res) itemsCache.set(r.id, r)
    rowOptions[rowIndex] = res.map(r => ({ value: r.id, label: `${r.sku} — ${r.name}`, secondary: r.unit }))
  } catch { rowOptions[rowIndex] = [] } finally { rowLoading[rowIndex] = false }
}
function onSelect(rowIndex: number, itemId: number | null) {
  const row = lines.value[rowIndex]
  if (!row) return
  row.stock_item_id = itemId
  const si = itemId != null ? itemsCache.get(itemId) : null
  row.option = si ? { value: si.id, label: `${si.sku} — ${si.name}`, secondary: si.unit } : null
}

const creatingItem = reactive<Record<number, boolean>>({})
async function createNewItem(rowIndex: number) {
  const row = lines.value[rowIndex]
  if (!row || !row.newName.trim()) return
  creatingItem[rowIndex] = true
  try {
    const created = await stockApi.createItem({ name: row.newName.trim(), item_type: 'goods', unit: 'ks' })
    itemsCache.set(created.id, { id: created.id, sku: created.sku, name: created.name, unit: created.unit, vat_rate_id: created.vat_rate_id, sale_price_without_vat: created.sale_price_without_vat })
    row.stock_item_id = created.id
    row.option = { value: created.id, label: `${created.sku} — ${created.name}`, secondary: created.unit }
    row.creatingNew = false
    toast.success(t('common.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    creatingItem[rowIndex] = false
  }
}

// M4: klientský guard proti přeplnění (qty > remaining), ať se to nezjistí až 409 po submitu.
// Tolerance 0.0005 zrcadlí backend (StockReceiptService).
function lineOver(l: LineState): boolean {
  return l.stock_item_id != null && Number(l.quantity) > Number(l.remaining) + 0.0005
}
const hasOver = computed(() => lines.value.some(lineOver))

const canSubmit = computed(() =>
  !!warehouseId.value
  && !hasOver.value
  && lines.value.some(l => l.stock_item_id != null && Number(l.quantity) > 0),
)

async function submit() {
  if (!canSubmit.value) return
  saving.value = true
  error.value = ''
  try {
    const document = await stockApi.receiptCreate(props.purchaseInvoiceId, {
      warehouse_id: warehouseId.value!,
      doc_date: docDate.value,
      lines: lines.value
        .filter(l => l.stock_item_id != null && Number(l.quantity) > 0)
        .map(l => ({
          purchase_invoice_item_id: l.purchase_invoice_item_id,
          stock_item_id: l.stock_item_id!,
          quantity: l.quantity,
          unit_cost: l.unit_cost || undefined,
        })),
      landed_costs: includeLandedCosts.value
        ? costs.value.filter(c => c.include && Number(c.amount) > 0).map(c => ({
            purchase_invoice_item_id: c.purchase_invoice_item_id,
            description: c.description,
            amount: Number(c.amount),
            allocation: c.allocation,
          }))
        : undefined,
    })
    toast.success(t('common.saved'))
    emit('created', document)
  } catch (e: any) {
    const err = e?.response?.data?.error
    const code = err?.code
    if (code === 'over_receipt' || code === 'stock.error.over_receipt') {
      // M4: detail je v `items` (Json::error payload), ne v `remaining` na kořeni erroru.
      const remaining = Array.isArray(err?.items) ? (err.items[0]?.remaining ?? '') : ''
      error.value = t('stock.receipt.over_receipt', { remaining })
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
        <h3 class="text-lg font-semibold">{{ t('stock.receipt.modal_title') }}</h3>
        <button @click="emit('close')" class="cursor-pointer text-neutral-400 hover:text-neutral-700 text-2xl leading-none">&times;</button>
      </header>

      <div class="p-5 space-y-4">
        <div v-if="loading" class="text-center text-neutral-500 py-8 text-sm">{{ t('common.loading') }}</div>
        <template v-else-if="proposal">
          <div v-if="proposal.pf_changed_after_receipt" class="px-3 py-2 rounded-md bg-warning-50 border border-warning-500/30 text-warning-700 text-sm">
            {{ t('stock.receipt.pf_changed_warning') }}
          </div>

          <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.receipt.field_warehouse') }}</label>
              <select v-model="warehouseId" class="w-full h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
                <option :value="null">—</option>
                <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
              </select>
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('stock.receipt.field_date') }}</label>
              <DateInput v-model="docDate" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>

          <div class="border border-neutral-200 rounded-lg overflow-hidden">
            <div v-for="(l, i) in lines" :key="l.purchase_invoice_item_id" class="p-3 border-b border-neutral-100 last:border-b-0 space-y-2">
              <div class="text-sm font-medium">{{ l.description }}</div>
              <div class="grid grid-cols-1 sm:grid-cols-12 gap-2 items-start">
                <div class="sm:col-span-6">
                  <SearchableSelect
                    :model-value="l.stock_item_id"
                    :options="rowOptions[i] ?? []"
                    remote
                    :loading="rowLoading[i]"
                    :selected-option="l.option"
                    :placeholder="t('stock.documents.col_item')"
                    :no-results-label="t('common.no_results')"
                    @search="(q: string) => onSearch(i, q)"
                    @update:model-value="(v: number | null) => onSelect(i, v)"
                  />
                  <button v-if="!l.stock_item_id && !l.creatingNew" type="button" @click="l.creatingNew = true"
                    :class="btnOutline('primary')" class="!h-7 !px-2 !text-xs mt-1">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
                    {{ t('stock.receipt.field_new_item') }}
                  </button>
                  <div v-if="l.creatingNew" class="flex items-center gap-1.5 mt-1">
                    <input v-model="l.newName" type="text" class="flex-1 h-8 px-2 border border-neutral-300 rounded-md text-xs" />
                    <button type="button" @click="createNewItem(i)" :disabled="creatingItem[i]" :class="btnFilled('primary')" class="!h-8 !px-2 !text-xs">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                      {{ t('common.create') }}
                    </button>
                  </div>
                </div>
                <div class="sm:col-span-3">
                  <label class="block text-[10px] text-neutral-400">{{ t('stock.receipt.col_remaining') }}: {{ l.remaining }}</label>
                  <input v-model="l.quantity" type="number" step="0.001" min="0"
                    :class="['w-full h-9 px-2 border rounded-md text-right font-mono text-sm', lineOver(l) ? 'border-danger-400 bg-danger-50' : 'border-neutral-300']" />
                  <p v-if="lineOver(l)" class="text-[10px] text-danger-500 mt-0.5">{{ t('stock.receipt.over_receipt', { remaining: l.remaining }) }}</p>
                </div>
                <div class="sm:col-span-3">
                  <label class="block text-[10px] text-neutral-400">{{ t('stock.receipt.col_unit_cost') }}</label>
                  <input v-model="l.unit_cost" type="number" step="0.000001" min="0"
                    class="w-full h-9 px-2 border border-neutral-300 rounded-md text-right font-mono text-sm" />
                </div>
              </div>
            </div>
          </div>

          <div v-if="proposal.cost_candidates.length > 0" class="border-t border-neutral-100 pt-3">
            <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
              <input v-model="includeLandedCosts" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
              {{ t('stock.receipt.landed_costs_checkbox') }}
            </label>
            <div v-if="includeLandedCosts" class="mt-2 space-y-2">
              <div v-for="(c, i) in costs" :key="i" class="flex items-center gap-2 text-sm">
                <input v-model="c.include" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                <span class="flex-1 truncate">{{ c.description }}</span>
                <span class="font-mono text-neutral-500">{{ formatMoney(Number(c.amount) || 0) }}</span>
                <select v-model="c.allocation" class="h-8 px-1.5 border border-neutral-300 rounded-md bg-surface text-xs">
                  <option value="by_value">{{ t('stock.documents.allocation_by_value') }}</option>
                  <option value="by_qty">{{ t('stock.documents.allocation_by_qty') }}</option>
                </select>
              </div>
            </div>
          </div>

          <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
        </template>
      </div>

      <footer class="px-5 py-4 border-t border-neutral-200 flex justify-end gap-2">
        <button @click="emit('close')" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
        <button @click="submit" :disabled="!canSubmit || saving" :class="btnFilled('success')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
          {{ saving ? t('common.saving') : t('stock.receipt.submit') }}
        </button>
      </footer>
    </div>
  </div>
</template>
