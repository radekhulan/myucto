<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  stockApi, type StockDocument, type StockDocType, type StockDocumentPayload, type Warehouse,
  type StockItemSearchResult, type LandedCostAllocation,
} from '@/api/stock'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const route = useRoute()
const router = useRouter()

const isEdit = computed(() => route.params.id !== undefined)
const docId = computed(() => (isEdit.value ? Number(route.params.id) : null))
const doc = ref<StockDocument | null>(null)
const loading = ref(false)
const error = ref('')

const warehouses = ref<Warehouse[]>([])

interface LineRow {
  stock_item_id: number | null
  qty: string
  unit_cost: string
  extra_cost: string
  note: string
  option: { value: number; label: string; secondary?: string } | null
  // H2: vazba na PF řádek (načtená příjemka) — MUSÍ se přenášet zpět, jinak update
  // vynuluje remaining-qty / over-receipt / „Přijato X/Y" / PF-changed warning.
  purchase_invoice_item_id: number | null
  source_description: string | null
  source_qty: string | null
}
function blankLine(): LineRow {
  return {
    stock_item_id: null, qty: '1', unit_cost: '', extra_cost: '', note: '', option: null,
    purchase_invoice_item_id: null, source_description: null, source_qty: null,
  }
}

const form = reactive({
  doc_type: 'receipt' as StockDocType,
  warehouse_id: null as number | null,
  warehouse_to_id: null as number | null,
  doc_date: appIsoDate(),
  description: '',
  partner_name: '',
})
const lines = ref<LineRow[]>([blankLine()])

// L3: částky drž jako string (DECIMAL konvence) — přepočet do haléřů až v alokátoru.
interface LandedCostRow { description: string; amount: string; allocation: LandedCostAllocation }
const landedCosts = ref<LandedCostRow[]>([])

/**
 * Klientský port PHP `LandedCostAllocator` (deterministický, shodný výsledek):
 * FLOOR podíly podle báze (by_value = qty×unit_cost, by_qty = qty), počítáno v haléřích
 * (int); haléřový zbytek (vždy ≥0) celý na řádek s NEJVYŠŠÍ hodnotou (value).
 * @param lines value v haléřích (int), qty v tisícinách (int)
 * @param costs amount v haléřích (int)
 * @returns extra_cost per index v haléřích (int); Σ = Σ zadaných částek
 */
function allocateLandedCosts(
  lines: Array<{ value: number; qty: number }>,
  costs: Array<{ amount: number; allocation: LandedCostAllocation }>,
): number[] {
  const n = lines.length
  const result = new Array<number>(n).fill(0)
  if (n === 0) return result
  // Řádek s nejvyšší hodnotou = kam padne haléřový zbytek (i pro by_qty).
  let maxIdx = 0
  for (let i = 1; i < n; i++) if (lines[i].value > lines[maxIdx].value) maxIdx = i
  for (const cost of costs) {
    const bases = lines.map(l => (cost.allocation === 'by_qty' ? l.qty : l.value))
    const totalBase = bases.reduce((s, b) => s + b, 0)
    if (totalBase <= 0) {
      result[maxIdx] += cost.amount
      continue
    }
    let allocated = 0
    for (let i = 0; i < n; i++) {
      const share = Math.floor((cost.amount * bases[i]) / totalBase)
      result[i] += share
      allocated += share
    }
    result[maxIdx] += cost.amount - allocated // zbytek ≥ 0
  }
  return result
}

// Per-řádek stav SearchableSelect (remote), stejný vzor jako InvoiceEditor B5.
const rowOptions = reactive<Record<number, { value: number; label: string; secondary?: string }[]>>({})
const rowLoading = reactive<Record<number, boolean>>({})
const itemsCache = new Map<number, StockItemSearchResult>()

const isPosted = computed(() => doc.value?.status === 'posted')
const isReversed = computed(() => doc.value?.status === 'reversed')
const readOnly = computed(() => isPosted.value || isReversed.value)
const isTransfer = computed(() => form.doc_type === 'transfer')
const isReceipt = computed(() => form.doc_type === 'receipt')

async function loadRefData() {
  try { warehouses.value = await stockApi.listWarehouses(true) } catch { warehouses.value = [] }
}

async function onSearch(rowIndex: number, q: string) {
  rowLoading[rowIndex] = true
  try {
    const res = await stockApi.searchItems(q, 30)
    for (const r of res) itemsCache.set(r.id, r)
    rowOptions[rowIndex] = res.map(r => ({
      value: r.id,
      label: `${r.sku} — ${r.name}`,
      secondary: r.sale_price_without_vat != null ? `${r.unit} · ${formatMoney(Number(r.sale_price_without_vat))}` : r.unit,
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
    if (isReceipt.value && !row.unit_cost && si.sale_price_without_vat != null) {
      row.unit_cost = si.sale_price_without_vat
    }
  }
  refreshAvailability()
}

function addLine() { lines.value.push(blankLine()) }
function removeLine(i: number) { lines.value.splice(i, 1) }
function addLandedCost() { landedCosts.value.push({ description: '', amount: '', allocation: 'by_value' }) }
function removeLandedCost(i: number) { landedCosts.value.splice(i, 1) }

// Dostupnost (nezávazný náhled) pro výdejku/převodku.
const availabilityMap = ref<Record<string, string>>({})
async function refreshAvailability() {
  if (isReceipt.value) return
  const ids = [...new Set(lines.value.map(l => l.stock_item_id).filter((v): v is number => !!v))]
  if (ids.length === 0) { availabilityMap.value = {}; return }
  try { availabilityMap.value = await stockApi.availability(ids, form.warehouse_id ?? undefined) } catch { /* nezávazné */ }
}
function rowAvailability(row: LineRow): string | null {
  if (!row.stock_item_id) return null
  return availabilityMap.value[String(row.stock_item_id)] ?? '0'
}

async function loadDocument() {
  if (!docId.value) return
  loading.value = true
  try {
    const d = await stockApi.getDocument(docId.value)
    doc.value = d
    form.doc_type = d.doc_type
    form.warehouse_id = d.warehouse_id
    form.warehouse_to_id = d.warehouse_to_id
    form.doc_date = d.doc_date.slice(0, 10)
    form.description = d.description
    form.partner_name = d.partner_name ?? ''
    lines.value = (d.lines ?? []).map(l => {
      const row: LineRow = {
        stock_item_id: l.stock_item_id,
        qty: String(l.qty),
        unit_cost: l.unit_cost != null ? String(l.unit_cost) : '',
        extra_cost: l.extra_cost != null ? String(l.extra_cost) : '',
        note: l.note ?? '',
        option: l.sku ? { value: l.stock_item_id, label: `${l.sku} — ${l.name}`, secondary: l.unit } : null,
        purchase_invoice_item_id: l.purchase_invoice_item_id ?? null,
        source_description: l.source_description ?? null,
        source_qty: l.source_qty != null ? String(l.source_qty) : null,
      }
      return row
    })
    if (lines.value.length === 0) lines.value = [blankLine()]
    await refreshAvailability()
  } catch (e: any) {
    error.value = e?.response?.data?.error?.message || t('common.error')
  } finally {
    loading.value = false
  }
}

function mapError(e: any): string {
  const code = e?.response?.data?.error?.code
  if (code === 'insufficient_stock' || code === 'stock.error.insufficient_stock') {
    const items = (e?.response?.data?.error?.items ?? e?.response?.data?.error?.details ?? []) as Array<{ sku: string; name: string; requested: string; available: string }>
    const lines2 = items.map(it => t('stock.documents.insufficient_stock_line', it)).join('; ')
    return `${t('stock.documents.insufficient_stock_title')}: ${lines2}`
  }
  if (code) {
    const key = code.startsWith('stock.error.') ? code : `stock.error.${code}`
    const localized = t(key)
    if (localized !== key) return localized
  }
  return e?.response?.data?.error?.message || t('common.error')
}

function buildPayload(): StockDocumentPayload {
  const rows = lines.value.filter(l => l.stock_item_id != null && Number(l.qty) > 0)

  // H1: backend /stock/documents čte jen per-řádkový `extra_cost`, NE pole `landed_costs`.
  // Vedlejší náklady proto rozpustíme klientsky do extra_cost (deterministicky, shodně s PHP).
  let allocated: number[] = new Array<number>(rows.length).fill(0)
  if (isReceipt.value && landedCosts.value.length > 0) {
    const baseLines = rows.map(l => ({
      value: Math.round(Number(l.qty) * Number(l.unit_cost || 0) * 100),
      qty: Math.round(Number(l.qty) * 1000),
    }))
    const costs = landedCosts.value
      .filter(c => Number(c.amount) > 0)
      .map(c => ({ amount: Math.round(Number(c.amount) * 100), allocation: c.allocation }))
    if (costs.length > 0) allocated = allocateLandedCosts(baseLines, costs)
  }

  return {
    doc_type: form.doc_type,
    // H2: u NAČTENÉHO dokladu přenes hlavičkovou vazbu (jinak update utne PF/FV/inventuru).
    origin: doc.value?.origin,
    doc_date: form.doc_date,
    description: form.description.trim(),
    warehouse_id: form.warehouse_id!,
    warehouse_to_id: isTransfer.value ? (form.warehouse_to_id ?? undefined) : undefined,
    partner_name: form.partner_name.trim() || undefined,
    invoice_id: doc.value?.invoice_id ?? undefined,
    purchase_invoice_id: doc.value?.purchase_invoice_id ?? undefined,
    stock_take_id: doc.value?.stock_take_id ?? undefined,
    lines: rows.map((l, i) => {
      const manualExtraH = isReceipt.value && l.extra_cost ? Math.round(Number(l.extra_cost) * 100) : 0
      const totalExtraH = manualExtraH + (allocated[i] ?? 0)
      return {
        stock_item_id: l.stock_item_id!,
        qty: l.qty,
        unit_cost: isReceipt.value && l.unit_cost ? l.unit_cost : undefined,
        extra_cost: isReceipt.value && totalExtraH > 0 ? (totalExtraH / 100).toFixed(2) : undefined,
        // H2: per-řádek vazba na PF řádek — backend validateBody je čte, replaceLines uloží.
        purchase_invoice_item_id: l.purchase_invoice_item_id ?? undefined,
        source_description: l.source_description ?? undefined,
        source_qty: l.source_qty ?? undefined,
        note: l.note.trim() || undefined,
      }
    }),
  }
}

const saving = ref(false)
const posting = ref(false)
const reversing = ref(false)

function validate(): boolean {
  error.value = ''
  if (!form.warehouse_id) { error.value = t('stock.documents.field_warehouse'); return false }
  if (isTransfer.value && (!form.warehouse_to_id || form.warehouse_to_id === form.warehouse_id)) {
    error.value = t('stock.documents.field_warehouse_to'); return false
  }
  if (!form.description.trim()) { error.value = t('stock.documents.field_description'); return false }
  if (lines.value.filter(l => l.stock_item_id != null && Number(l.qty) > 0).length === 0) {
    error.value = t('stock.documents.no_lines'); return false
  }
  return true
}

async function saveDraft() {
  if (!validate()) return
  saving.value = true
  try {
    const payload = buildPayload()
    if (isEdit.value && docId.value) {
      doc.value = await stockApi.updateDocument(docId.value, payload)
    } else {
      doc.value = await stockApi.createDocument(payload)
      router.replace(`/stock/documents/${doc.value.id}`)
    }
    toast.success(t('common.saved'))
    await loadDocument()
  } catch (e: any) {
    error.value = mapError(e)
  } finally {
    saving.value = false
  }
}

async function post() {
  if (!validate()) return
  posting.value = true
  try {
    let id = docId.value
    const payload = buildPayload()
    if (id) {
      doc.value = await stockApi.updateDocument(id, payload)
    } else {
      doc.value = await stockApi.createDocument(payload)
      id = doc.value.id
      router.replace(`/stock/documents/${id}`)
    }
    doc.value = await stockApi.postDocument(id!)
    toast.success(t('common.saved'))
    if (doc.value.warnings?.length) {
      for (const w of doc.value.warnings) toast.warning(w)
    }
    await loadDocument()
  } catch (e: any) {
    error.value = mapError(e)
    toast.error(error.value)
  } finally {
    posting.value = false
  }
}

const reverseOpen = ref(false)
const reverseReason = ref('')
async function reverse() {
  if (!docId.value) return
  reversing.value = true
  try {
    await stockApi.reverseDocument(docId.value, reverseReason.value.trim() || undefined)
    toast.success(t('common.saved'))
    reverseOpen.value = false
    await loadDocument()
  } catch (e: any) {
    toast.error(mapError(e))
  } finally {
    reversing.value = false
  }
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'post', label: t('stock.documents.post'), icon: 'check', tier: 'primary', variant: 'primary',
    show: auth.canWrite('stock.documents.write') && !readOnly.value, loading: posting.value, run: post,
  },
  {
    key: 'save-draft', label: t('stock.documents.save_draft'), icon: 'doc', tier: 'secondary', variant: 'neutral',
    show: auth.canWrite('stock.documents.write') && !readOnly.value, loading: saving.value, run: saveDraft,
  },
  {
    key: 'print', label: t('stock.documents.print_pdf'), icon: 'download', tier: 'secondary', variant: 'neutral',
    show: !!docId.value, run: () => window.open(stockApi.documentPdfUrl(docId.value!), '_blank', 'noopener'),
  },
  {
    key: 'reverse', label: t('stock.documents.cancel'), icon: 'uturn', tier: 'overflow', variant: 'danger',
    show: auth.canWrite('stock.documents.write') && isPosted.value, run: () => { reverseOpen.value = true },
  },
])

onMounted(async () => {
  await loadRefData()
  if (isEdit.value) {
    await loadDocument()
  } else {
    const qType = route.query.doc_type
    if (typeof qType === 'string' && ['receipt', 'issue', 'transfer'].includes(qType)) form.doc_type = qType as StockDocType
    const defWh = warehouses.value.find(w => w.is_default)?.id ?? warehouses.value[0]?.id ?? null
    form.warehouse_id = route.query.warehouse_id ? Number(route.query.warehouse_id) : defWh
    const qItem = route.query.stock_item_id
    if (typeof qItem === 'string' && /^\d+$/.test(qItem)) {
      try {
        const si = await stockApi.getItem(Number(qItem))
        itemsCache.set(si.id, { id: si.id, sku: si.sku, name: si.name, unit: si.unit, vat_rate_id: si.vat_rate_id, sale_price_without_vat: si.sale_price_without_vat })
        lines.value = [{ ...blankLine(), stock_item_id: si.id, option: { value: si.id, label: `${si.sku} — ${si.name}`, secondary: si.unit } }]
      } catch { /* ignore */ }
    }
  }
})
</script>

<template>
  <div>
    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <template v-else>
      <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
        <div>
          <h1 class="text-2xl font-semibold">
            {{ doc?.doc_number || t('stock.documents.new_title') }}
          </h1>
          <p class="text-sm text-neutral-500 mt-0.5 flex items-center gap-2 flex-wrap">
            <span>{{ t(`stock.doc_type.${form.doc_type}`) }}</span>
            <span v-if="doc" class="text-xs px-2 py-0.5 rounded font-medium"
              :class="doc.status === 'posted' ? 'bg-success-50 text-success-600' : doc.status === 'reversed' ? 'bg-danger-50 text-danger-500' : 'bg-neutral-100 text-neutral-600'">
              {{ t(`stock.doc_status.${doc.status}`) }}
            </span>
            <span v-if="isPosted" class="text-xs px-2 py-0.5 rounded font-medium bg-primary-50 text-primary-700">
              {{ t('stock.documents.posted_badge') }}
            </span>
          </p>
        </div>
        <ActionBar :actions="actions" />
      </div>

      <div v-if="!readOnly && !isEdit" class="mb-4">
        <div class="inline-flex rounded-md border border-neutral-300 overflow-hidden">
          <button v-for="dt in (['receipt', 'issue', 'transfer'] as const)" :key="dt" type="button" @click="form.doc_type = dt"
            class="cursor-pointer px-3 h-9 text-sm font-medium inline-flex items-center gap-1.5 whitespace-nowrap"
            :class="form.doc_type === dt ? 'bg-primary-600 text-white' : 'bg-surface text-neutral-600 hover:bg-neutral-50'">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="dt === 'receipt' ? ICONS.download : dt === 'issue' ? ICONS.upload : ICONS.swap" />
            </svg>
            {{ t(`stock.doc_type.${dt}`) }}
          </button>
        </div>
      </div>

      <div v-if="doc?.origin && doc.origin !== 'manual'" class="mb-4 text-xs text-neutral-500">
        {{ t('stock.documents.col_origin') }}: {{ t(`stock.origin.${doc.origin}`) }}
      </div>

      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-5 space-y-4 mb-4">
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ isTransfer ? t('stock.documents.field_warehouse_from') : t('stock.documents.field_warehouse') }}</label>
            <select v-model="form.warehouse_id" :disabled="readOnly" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface disabled:bg-neutral-50 text-sm">
              <option :value="null">—</option>
              <option v-for="w in warehouses" :key="w.id" :value="w.id">{{ w.name }}</option>
            </select>
          </div>
          <div v-if="isTransfer">
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.documents.field_warehouse_to') }}</label>
            <select v-model="form.warehouse_to_id" :disabled="readOnly" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface disabled:bg-neutral-50 text-sm">
              <option :value="null">—</option>
              <option v-for="w in warehouses" :key="w.id" :value="w.id" :disabled="w.id === form.warehouse_id">{{ w.name }}</option>
            </select>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.documents.field_date') }}</label>
            <DateInput v-model="form.doc_date" :disabled="readOnly" class="w-full h-10 px-3 border border-neutral-300 rounded-md disabled:bg-neutral-50 text-sm" />
          </div>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.documents.field_description') }}</label>
            <input v-model="form.description" type="text" :disabled="readOnly" class="w-full h-10 px-3 border border-neutral-300 rounded-md disabled:bg-neutral-50 text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.documents.field_partner') }}</label>
            <input v-model="form.partner_name" type="text" :disabled="readOnly" class="w-full h-10 px-3 border border-neutral-300 rounded-md disabled:bg-neutral-50 text-sm" />
          </div>
        </div>
        <p class="text-xs text-neutral-400">{{ t('stock.documents.number_preview_generic') }}</p>
      </div>

      <!-- Řádky -->
      <!-- overflow-visible: aby našeptávač (SearchableSelect dropdown) nebyl oříznut boxem -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4">
        <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stock.documents.lines_title') }}</h3>
          <button v-if="!readOnly" type="button" @click="addLine" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('stock.documents.add_line') }}
          </button>
        </div>
        <!-- Hlavička sloupců — jen desktop (na mobilu má každé pole vlastní label uvnitř řádku) -->
        <div class="hidden sm:grid grid-cols-12 gap-3 px-4 py-2 border-b border-neutral-200 bg-neutral-50/60 text-xs font-medium uppercase tracking-wide text-neutral-500">
          <div class="col-span-4">{{ t('stock.documents.col_item') }}</div>
          <div class="col-span-2 text-right">{{ t('stock.documents.col_qty') }}</div>
          <div class="col-span-2 text-right">{{ t('stock.documents.col_unit_cost') }}</div>
          <div class="col-span-3">{{ t('stock.documents.field_description') }}</div>
          <div class="col-span-1"></div>
        </div>
        <div class="divide-y divide-neutral-100">
          <div v-if="lines.length === 0" class="px-5 py-6 text-center text-sm text-neutral-400">{{ t('stock.documents.no_lines') }}</div>
          <div v-for="(row, i) in lines" :key="i" class="p-3 sm:p-4 grid grid-cols-1 sm:grid-cols-12 gap-2 sm:gap-3 items-start">
            <div class="sm:col-span-4">
              <label class="block text-xs font-medium text-neutral-500 mb-1 sm:hidden">{{ t('stock.documents.col_item') }}</label>
              <SearchableSelect
                :model-value="row.stock_item_id"
                :options="rowOptions[i] ?? []"
                remote
                :loading="rowLoading[i]"
                :selected-option="row.option"
                :disabled="readOnly"
                :placeholder="t('stock.documents.col_item')"
                :no-results-label="t('common.no_results')"
                @search="(q: string) => onSearch(i, q)"
                @update:model-value="(v: number | null) => onSelect(i, v)"
              />
              <p v-if="!isReceipt && row.stock_item_id" class="text-xs mt-0.5 text-neutral-400">
                {{ t('stock.documents.col_available') }}: {{ rowAvailability(row) }}
              </p>
            </div>
            <div class="sm:col-span-2">
              <label class="block text-xs font-medium text-neutral-500 mb-1 sm:hidden">{{ t('stock.documents.col_qty') }}</label>
              <input v-model="row.qty" type="number" step="0.001" min="0" :readonly="readOnly"
                class="w-full h-10 px-2 border border-neutral-300 rounded-md text-right font-mono text-sm disabled:bg-neutral-50" :disabled="readOnly" />
            </div>
            <div class="sm:col-span-2">
              <label class="block text-xs font-medium text-neutral-500 mb-1 sm:hidden">{{ t('stock.documents.col_unit_cost') }}</label>
              <input v-if="isReceipt" v-model="row.unit_cost" type="number" step="0.000001" min="0" :readonly="readOnly"
                class="w-full h-10 px-2 border border-neutral-300 rounded-md text-right font-mono text-sm disabled:bg-neutral-50" :disabled="readOnly" />
              <div v-else class="h-10 flex items-center justify-end text-xs text-neutral-400 italic">{{ t('stock.documents.price_computed_hint') }}</div>
              <!-- Rozpuštěné vedlejší náklady jsou na řádku už uložené, ale sekce
                   níž se při načtení dokladu neplní. Bez tohohle řádku uživatel
                   nevidí, že tam nějaká částka je, a další náklad přičte navrch. -->
              <p v-if="isReceipt && Number(row.extra_cost) > 0" class="text-xs mt-0.5 text-right text-neutral-500 font-mono">
                {{ t('stock.documents.extra_cost_allocated') }}: {{ row.extra_cost }}
              </p>
            </div>
            <div class="sm:col-span-3">
              <label class="block text-xs font-medium text-neutral-500 mb-1 sm:hidden">{{ t('stock.documents.field_description') }}</label>
              <input v-model="row.note" type="text" :disabled="readOnly"
                class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm disabled:bg-neutral-50" />
            </div>
            <div class="sm:col-span-1 flex sm:justify-end">
              <button v-if="!readOnly" type="button" @click="removeLine(i)" :title="t('common.delete')"
                class="cursor-pointer w-9 h-9 inline-flex items-center justify-center text-danger-500 hover:bg-danger-50 rounded-md">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
              </button>
            </div>
          </div>
        </div>
      </div>

      <!-- Vedlejší pořizovací náklady — jen příjemka, jen draft -->
      <div v-if="isReceipt && !readOnly" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <div class="px-5 py-3 border-b border-neutral-200 flex items-center justify-between">
          <div>
            <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('stock.documents.landed_costs_title') }}</h3>
            <p class="text-xs text-neutral-400 mt-0.5">{{ t('stock.documents.landed_costs_hint') }}</p>
          </div>
          <button type="button" @click="addLandedCost" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
            {{ t('stock.documents.add_landed_cost') }}
          </button>
        </div>
        <div v-if="landedCosts.length" class="divide-y divide-neutral-100">
          <div v-for="(c, i) in landedCosts" :key="i" class="p-3 grid grid-cols-1 sm:grid-cols-12 gap-2 items-center">
            <input v-model="c.description" type="text" :placeholder="t('stock.documents.field_description')" class="sm:col-span-5 h-9 px-2 border border-neutral-300 rounded-md text-sm" />
            <input v-model="c.amount" type="number" step="0.01" min="0" class="sm:col-span-3 h-9 px-2 border border-neutral-300 rounded-md text-right font-mono text-sm" />
            <select v-model="c.allocation" class="sm:col-span-3 h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
              <option value="by_value">{{ t('stock.documents.allocation_by_value') }}</option>
              <option value="by_qty">{{ t('stock.documents.allocation_by_qty') }}</option>
            </select>
            <button type="button" @click="removeLandedCost(i)" :title="t('common.delete')"
              class="sm:col-span-1 justify-self-end cursor-pointer w-9 h-9 inline-flex items-center justify-center text-danger-500 hover:bg-danger-50 rounded-md">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            </button>
          </div>
        </div>
      </div>

      <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500 mb-4">{{ error }}</div>

      <!-- Storno modal -->
      <div v-if="reverseOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4" @click.self="reverseOpen = false">
        <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
          <h3 class="text-lg font-semibold mb-1">{{ t('stock.documents.cancel_confirm_title') }}</h3>
          <p class="text-sm text-neutral-500 mb-3">{{ doc?.doc_number }}</p>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('stock.documents.cancel_reason') }}</label>
          <textarea v-model="reverseReason" rows="2" class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm mb-3"></textarea>
          <div class="flex flex-wrap justify-end gap-2">
            <button @click="reverseOpen = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
            <button @click="reverse" :disabled="reversing" :class="btnFilled('danger')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
              {{ reversing ? t('common.saving') : t('stock.documents.cancel') }}
            </button>
          </div>
        </div>
      </div>

      <div v-if="!readOnly" class="flex justify-end">
        <RouterLink to="/stock/documents" class="text-sm text-neutral-500 hover:text-neutral-700">{{ t('common.cancel') }}</RouterLink>
      </div>
    </template>
  </div>
</template>
