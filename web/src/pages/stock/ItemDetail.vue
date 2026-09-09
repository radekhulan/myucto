<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { stockApi, type StockItem, type StockLedgerRow } from '@/api/stock'
import { purchaseOrdersApi, type StockQuantityRow } from '@/api/purchaseOrders'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate } from '@/composables/useFormat'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import { btnOutline } from '@/components/ui/buttonStyles'
import ItemDuplicateDialog from '@/components/stock/ItemDuplicateDialog.vue'
import ItemTemplatesPanel from '@/components/stock/ItemTemplatesPanel.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()

const id = computed(() => Number(route.params.id))
const item = ref<StockItem | null>(null)
const loading = ref(false)
const duplicateOpen = ref(false)
const canManageLifecycle = computed(() => auth.canWrite('stock.items.write') && auth.canWrite('eshop.write'))

// Odvozené kvantity (Epic SKLAD, fáze 4) — skladem/rezervováno/na cestě/u dodavatele.
// BE vrací řádek se samými nulami i pro kartu bez jediného pohybu/objednávky — nikdy
// se nespoléhat na to, že `items` bude prázdné, ale i tak drž `quantities` nullable
// pro dobu, než se odpověď vrátí.
const quantities = ref<StockQuantityRow | null>(null)
async function loadQuantities() {
  try {
    const r = await purchaseOrdersApi.quantities([id.value])
    quantities.value = r.items[0] ?? null
  } catch { quantities.value = null }
}

const movements = ref<StockLedgerRow[]>([])
const openingBalance = ref('0')
const movLoading = ref(false)
const movOffset = ref(0)
const movLimit = 50
const movHasMore = ref(true)

async function loadItem() {
  loading.value = true
  try {
    item.value = await stockApi.getItem(id.value)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

async function loadMovements(reset = false) {
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
  await loadItem()
  await loadMovements(true)
  loadQuantities()
})

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
    sku: i.sku, name: i.name, item_type: i.item_type, unit: i.unit, ean: i.ean,
    vat_rate_id: i.vat_rate_id, sale_price_without_vat: i.sale_price_without_vat,
    min_qty: i.min_qty, is_active: i.is_active, note: i.note,
  }
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'new-issue', label: t('stock.item_detail.new_issue'), icon: 'send', tier: 'primary', variant: 'primary',
    show: auth.canWrite('stock'),
    to: { path: '/stock/documents/new', query: { doc_type: 'issue', stock_item_id: id.value } },
  },
  {
    key: 'edit', label: t('stock.item_detail.edit'), icon: 'edit', tier: 'secondary', variant: 'warning',
    show: auth.canWrite('stock'), to: `/stock/items/${id.value}/edit`,
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
  <div>
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
        <ActionBar :actions="actions" />
      </div>
      <p v-if="item.lifecycle_status === 'retired'" class="mb-4 rounded-lg border border-warning-200 bg-warning-50 px-3 py-2 text-sm text-warning-800 dark:bg-warning-950/30 dark:text-warning-200">{{ t('stock.lifecycle.retired_hint') }}</p>

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
            {{ item.effective_price != null ? formatMoney(Number(item.effective_price)) : (item.sale_price_without_vat != null ? formatMoney(Number(item.sale_price_without_vat)) : '—') }}
          </div>
          <div v-if="item.promo_price != null" class="text-xs text-neutral-500 mt-0.5">
            <span class="line-through">{{ item.sale_price_without_vat != null ? formatMoney(Number(item.sale_price_without_vat)) : '—' }}</span>
            <span class="ml-1">{{ item.promo_label ?? t('eshop.promo.title') }}</span>
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

      <ItemTemplatesPanel v-if="canManageLifecycle" :item="item" @created="duplicated" />

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
                <th class="px-3 py-2 text-left font-medium">{{ t('stock.item_detail.col_warehouse') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('stock.item_detail.col_qty') }}</th>
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
                </td>
                <td class="px-3 py-2">{{ m.warehouse_code }}</td>
                <td class="px-3 py-2 text-right font-mono whitespace-nowrap" :class="num(m.qty_signed) < 0 ? 'text-danger-500' : 'text-success-600'">
                  {{ num(m.qty_signed) > 0 ? '+' : '' }}{{ m.qty_signed }}
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
    </template>
    <ItemDuplicateDialog v-if="duplicateOpen && item" :item="item" @close="duplicateOpen = false" @created="duplicated" />
  </div>
</template>
