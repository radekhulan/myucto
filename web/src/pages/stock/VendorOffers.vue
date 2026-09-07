<script setup lang="ts">
/**
 * „U dodavatele" — zboží × dodavatel (Epic SKLAD, fáze 3).
 *
 * Odpovídá na otázku „kdo to má, za kolik a kolik kusů" ještě předtím, než se
 * cokoli objedná: karta zboží nemusí mít jediný skladový pohyb, sloupec „skladem"
 * pak ukazuje 0, ne prázdno.
 *
 * ŽÁDNÝ příznak stáří dat. Množství, které dodavatel hlásí, platí, dokud ho
 * nezmění (rozhodnutí #7 plánu) — `stock_qty_updated_at` je jen informace, kdy
 * hodnota naposled přišla, ne důvod ji znehodnotit.
 *
 * Editace je inline v řádku: PATCH posílá jen skutečně změněná pole, takže dvě
 * současné úpravy různých sloupců si navzájem nepřepíšou data.
 */
import { ref, reactive, computed, onMounted, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  stockApi,
  type VendorOffer,
  type VendorOfferPatch,
  type VendorOfferPayload,
  type VendorOfferFilters,
  type VendorAvailabilityState,
  type VendorOfferImportReport,
  type VendorOfferImportRow,
  type StockItemSearchResult,
} from '@/api/stock'
import { clientsApi } from '@/api/clients'
import { codebooksApi, type Currency } from '@/api/codebooks'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate } from '@/composables/useFormat'
import { apiErrorMessage } from '@/api/errors'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import Modal from '@/components/ui/Modal.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const AVAILABILITY: VendorAvailabilityState[] = ['in_stock', 'on_order', 'unavailable', 'unknown']

// Měna z číselníku firmy, ne volný text — překlep („Kč", malá písmena) jinak
// projde až do nabídky a rozbije porovnání cen mezi dodavateli.
const currencies = ref<Currency[]>([])
const defaultCurrency = computed(() =>
  currencies.value.find(c => c.is_default)?.code ?? currencies.value[0]?.code ?? '')
/** Doplní uloženou měnu, která v číselníku není — jinak by select vypadal prázdně. */
function currencyOptions(current: string | boolean | undefined): string[] {
  const codes = currencies.value.map(c => c.code)
  const cur = typeof current === 'string' ? current : ''
  return cur && !codes.includes(cur) ? [cur, ...codes] : codes
}

const PER_PAGE = 50
const MAX_IMPORT_SIZE = 2 * 1024 * 1024
const IMPORT_COLUMNS = [
  'sku', 'dodavatel', 'ico', 'kod_dodavatele', 'nakupni_cena', 'mena',
  'dodaci_lhuta_dny', 'skladem_u_dodavatele', 'dostupnost', 'min_objednavka',
  'baleni', 'cena_plati_do', 'hlavni_dodavatel', 'aktivni', 'poznamka',
]

const canWrite = computed(() => auth.canWrite('stock.vendors.write'))

// ── Seznam ──────────────────────────────────────────────────────────────────
const offers = ref<VendorOffer[]>([])
const total = ref(0)
const page = ref(1)
const loading = ref(false)
const error = ref('')

const filters = reactive<{ q: string; availability_state: VendorAvailabilityState | ''; onlyActive: boolean }>({
  q: '',
  availability_state: '',
  onlyActive: false,
})

async function load() {
  loading.value = true
  error.value = ''
  try {
    const params: VendorOfferFilters = {
      q: filters.q.trim() || undefined,
      availability_state: filters.availability_state || undefined,
      active: filters.onlyActive ? true : undefined,
      limit: PER_PAGE,
      offset: (page.value - 1) * PER_PAGE,
    }
    const res = await stockApi.listVendorOffers(params)
    offers.value = res.items
    total.value = res.total
  } catch (e) {
    error.value = apiErrorMessage(e, t('common.error'))
  } finally {
    loading.value = false
  }
}
onMounted(load)
onMounted(async () => {
  currencies.value = await codebooksApi.currencies().catch(() => [] as Currency[])
})
watch(page, load)

let searchTimer: ReturnType<typeof setTimeout> | undefined
function onFilterChange() {
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    if (page.value !== 1) page.value = 1
    else void load()
  }, 250)
}

// ── Inline editace řádku ────────────────────────────────────────────────────
const editingId = ref<number | null>(null)
const savingId = ref<number | null>(null)
const draft = reactive<Record<string, string | boolean>>({})
let original: VendorOffer | null = null

function startEdit(o: VendorOffer) {
  editingId.value = o.id
  original = o
  draft.vendor_sku = o.vendor_sku ?? ''
  draft.purchase_price = o.purchase_price ?? ''
  draft.currency_code = o.currency_code
  draft.delivery_days = o.delivery_days === null ? '' : String(o.delivery_days)
  draft.stock_qty = o.stock_qty ?? ''
  draft.availability_state = o.availability_state
  draft.min_order_qty = o.min_order_qty ?? ''
  draft.package_qty = o.package_qty ?? ''
  draft.price_valid_to = o.price_valid_to ?? ''
  draft.is_active = o.is_active
  draft.is_preferred = o.is_preferred
  draft.note = o.note ?? ''
}

function cancelEdit() {
  editingId.value = null
  original = null
}

/** Jen skutečně změněná pole — PATCH nesmí přepsat, co uživatel nesahal. */
function diff(): VendorOfferPatch {
  if (!original) return {}
  const o = original
  const out: Record<string, unknown> = {}
  const text = (key: 'vendor_sku' | 'purchase_price' | 'stock_qty' | 'min_order_qty' | 'package_qty' | 'price_valid_to' | 'note') => {
    const next = String(draft[key] ?? '').trim()
    const prev = o[key] ?? ''
    if (next !== String(prev)) out[key] = next === '' ? null : next
  }
  text('vendor_sku'); text('purchase_price'); text('stock_qty')
  text('min_order_qty'); text('package_qty'); text('price_valid_to'); text('note')

  const currency = String(draft.currency_code ?? '').trim().toUpperCase()
  if (currency && currency !== o.currency_code) out.currency_code = currency

  const days = String(draft.delivery_days ?? '').trim()
  const prevDays = o.delivery_days === null ? '' : String(o.delivery_days)
  if (days !== prevDays) out.delivery_days = days === '' ? null : Number(days)

  if (draft.availability_state !== o.availability_state) out.availability_state = draft.availability_state
  if (draft.is_active !== o.is_active) out.is_active = draft.is_active
  if (draft.is_preferred !== o.is_preferred) out.is_preferred = draft.is_preferred

  return out as VendorOfferPatch
}

async function saveEdit() {
  if (editingId.value === null) return
  const payload = diff()
  if (Object.keys(payload).length === 0) { cancelEdit(); return }
  savingId.value = editingId.value
  try {
    await stockApi.patchVendorOffer(editingId.value, payload)
    toast.success(t('common.saved'))
    cancelEdit()
    await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  } finally {
    savingId.value = null
  }
}

async function remove(o: VendorOffer) {
  if (!confirm(t('stock.vendor_offers.delete_confirm', { vendor: o.client_name, sku: o.sku }))) return
  try {
    await stockApi.deleteVendorOffer(o.id)
    toast.success(t('common.saved'))
    if (offers.value.length === 1 && page.value > 1) page.value -= 1
    else await load()
  } catch (e) {
    toast.error(apiErrorMessage(e, t('common.error')))
  }
}

// ── Nová nabídka ────────────────────────────────────────────────────────────
const createOpen = ref(false)
const creating = ref(false)
const createError = ref('')
const form = reactive<VendorOfferPayload>({
  stock_item_id: 0,
  client_id: 0,
  vendor_sku: '',
  purchase_price: '',
  currency_code: 'CZK',
  delivery_days: null,
  stock_qty: '',
  availability_state: 'unknown',
  min_order_qty: '',
  package_qty: '',
  price_valid_to: '',
  is_preferred: false,
  is_active: true,
  note: '',
})

const itemOptions = ref<Array<{ value: number; label: string; secondary?: string }>>([])
const itemLoading = ref(false)
const selectedItem = ref<{ value: number; label: string } | null>(null)
const vendorOptions = ref<Array<{ value: number; label: string; secondary?: string }>>([])
const vendorLoading = ref(false)
const selectedVendor = ref<{ value: number; label: string } | null>(null)

async function searchItems(q: string) {
  itemLoading.value = true
  try {
    const rows: StockItemSearchResult[] = await stockApi.searchItems(q, 25)
    itemOptions.value = rows.map(r => ({ value: r.id, label: `${r.sku} — ${r.name}`, secondary: r.unit }))
  } catch { itemOptions.value = [] } finally { itemLoading.value = false }
}
async function searchVendors(q: string) {
  vendorLoading.value = true
  try {
    const res = await clientsApi.list({ q, role: 'vendors', per_page: 25 })
    vendorOptions.value = res.data.map(c => ({ value: c.id, label: c.company_name, secondary: c.ic || undefined }))
  } catch { vendorOptions.value = [] } finally { vendorLoading.value = false }
}

function openCreate() {
  Object.assign(form, {
    stock_item_id: 0, client_id: 0, vendor_sku: '', purchase_price: '', currency_code: defaultCurrency.value,
    delivery_days: null, stock_qty: '', availability_state: 'unknown', min_order_qty: '',
    package_qty: '', price_valid_to: '', is_preferred: false, is_active: true, note: '',
  })
  selectedItem.value = null
  selectedVendor.value = null
  createError.value = ''
  createOpen.value = true
  void searchItems('')
  void searchVendors('')
}

function onItemPicked(id: number | null) {
  form.stock_item_id = id ?? 0
  selectedItem.value = itemOptions.value.find(o => o.value === id) ?? null
}
function onVendorPicked(id: number | null) {
  form.client_id = id ?? 0
  selectedVendor.value = vendorOptions.value.find(o => o.value === id) ?? null
}

/** Prázdné řetězce ven — server je bere jako „vynulovat", tady jsou „nezadáno". */
function createPayload(): VendorOfferPayload {
  const out: Record<string, unknown> = {
    stock_item_id: form.stock_item_id,
    client_id: form.client_id,
    currency_code: form.currency_code,
    availability_state: form.availability_state,
    is_preferred: form.is_preferred,
    is_active: form.is_active,
  }
  for (const key of ['vendor_sku', 'purchase_price', 'stock_qty', 'min_order_qty', 'package_qty', 'price_valid_to', 'note'] as const) {
    const v = String(form[key] ?? '').trim()
    if (v !== '') out[key] = v
  }
  if (form.delivery_days !== null && String(form.delivery_days) !== '') out.delivery_days = Number(form.delivery_days)
  return out as unknown as VendorOfferPayload
}

async function saveCreate() {
  createError.value = ''
  if (!form.stock_item_id || !form.client_id) {
    createError.value = t('stock.vendor_offers.pick_item_and_vendor')
    return
  }
  creating.value = true
  try {
    await stockApi.createVendorOffer(createPayload())
    toast.success(t('common.saved'))
    createOpen.value = false
    page.value = 1
    await load()
  } catch (e) {
    createError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    creating.value = false
  }
}

// ── Import ceníku ───────────────────────────────────────────────────────────
const importOpen = ref(false)
const importFile = ref<File | null>(null)
const importDryRun = ref(true)
const importBusy = ref(false)
const importError = ref('')
const importReport = ref<VendorOfferImportReport | null>(null)

function openImport() {
  importFile.value = null
  importDryRun.value = true
  importError.value = ''
  importReport.value = null
  importOpen.value = true
}

function pickImportFile(f: File | null | undefined) {
  if (!f) return
  importError.value = ''
  importReport.value = null
  const ext = f.name.toLowerCase().split('.').pop() ?? ''
  if ((ext !== 'xlsx' && ext !== 'csv') || f.size > MAX_IMPORT_SIZE) {
    importError.value = t('stock.vendor_offers.import.bad_file')
    return
  }
  importFile.value = f
}
function onImportPick(e: Event) {
  pickImportFile((e.target as HTMLInputElement).files?.[0])
}
function onImportDrop(e: DragEvent) {
  e.preventDefault()
  pickImportFile(e.dataTransfer?.files?.[0])
}

const importHasErrors = computed(() => (importReport.value?.failed ?? 0) > 0)
const canCommitImport = computed(() => !!importReport.value && importReport.value.dry_run && !importHasErrors.value)

async function runImport(forceReal = false) {
  if (!importFile.value) return
  const dry = forceReal ? false : importDryRun.value
  importBusy.value = true
  importError.value = ''
  try {
    importReport.value = await stockApi.importVendorOffers(importFile.value, dry)
    if (!dry) {
      if (importReport.value.failed > 0) toast.warning(t('stock.vendor_offers.import.done_with_errors'))
      else {
        toast.success(t('stock.vendor_offers.import.done'))
        page.value = 1
        await load()
      }
    }
  } catch (e) {
    importError.value = apiErrorMessage(e, t('common.error'))
  } finally {
    importBusy.value = false
  }
}

function importChanges(r: VendorOfferImportRow): Array<{ field: string; from: unknown; to: unknown }> {
  if (!r.changes) return []
  return Object.entries(r.changes).map(([field, v]) => ({ field, from: v.from, to: v.to }))
}

// ── Zobrazení ───────────────────────────────────────────────────────────────
function qty(v: string | null): string {
  if (v === null || v === '') return '—'
  const n = Number(v)
  return Number.isFinite(n) ? n.toLocaleString('cs-CZ', { maximumFractionDigits: 3 }) : v
}
function price(o: VendorOffer): string {
  if (o.purchase_price === null || o.purchase_price === '') return '—'
  const n = Number(o.purchase_price)
  return `${Number.isFinite(n) ? n.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) : o.purchase_price} ${o.currency_code}`
}
function availabilityClass(s: VendorAvailabilityState): string {
  switch (s) {
    case 'in_stock':    return 'bg-success-50 text-success-600 border-success-500/40'
    case 'on_order':    return 'bg-primary-50 text-primary-700 border-primary-500/40'
    case 'unavailable': return 'bg-danger-50 text-danger-500 border-danger-500/40'
    default:            return 'bg-neutral-100 text-neutral-600 border-neutral-200'
  }
}
function importStatusClass(s: VendorOfferImportRow['status']): string {
  switch (s) {
    case 'create': return 'bg-success-50 text-success-600 border-success-500/40'
    case 'update': return 'bg-primary-50 text-primary-700 border-primary-500/40'
    case 'error':  return 'bg-danger-50 text-danger-500 border-danger-500/40'
    default:       return 'bg-neutral-100 text-neutral-600 border-neutral-200'
  }
}
function fmt(v: unknown): string {
  if (v === null || v === undefined || v === '') return '—'
  if (typeof v === 'boolean') return v ? t('common.yes') : t('common.no')
  return String(v)
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'new',
    label: t('stock.vendor_offers.new'),
    icon: 'plus',
    tier: 'primary',
    variant: 'primary',
    show: canWrite.value,
    run: openCreate,
  },
  {
    key: 'import',
    label: t('stock.vendor_offers.import.action'),
    icon: 'upload',
    tier: 'secondary',
    variant: 'neutral',
    show: canWrite.value,
    run: openImport,
  },
  {
    key: 'items',
    label: t('stock.vendor_offers.go_items'),
    icon: 'stock_items',
    tier: 'overflow',
    variant: 'neutral',
    to: '/stock/items',
  },
])
</script>

<template>
  <div>
    <div class="mb-4">
      <h1 class="text-2xl font-semibold">{{ t('stock.vendor_offers.title') }}</h1>
      <p class="text-sm text-neutral-500 mt-0.5">{{ t('stock.vendor_offers.subtitle') }}</p>
    </div>

    <ActionBar :actions="actions" class="mb-4" />

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4 flex flex-wrap items-center gap-3">
      <input
        v-model="filters.q"
        type="search"
        :placeholder="t('stock.vendor_offers.search_placeholder')"
        class="flex-1 min-w-[14rem] rounded-md border border-neutral-300 h-9 px-3 text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500"
        @input="onFilterChange"
      />
      <select
        v-model="filters.availability_state"
        class="rounded-md border border-neutral-300 h-9 px-2 text-sm"
        @change="onFilterChange"
      >
        <option value="">{{ t('stock.vendor_offers.all_availability') }}</option>
        <option v-for="s in AVAILABILITY" :key="s" :value="s">{{ t(`stock.vendor_offers.availability.${s}`) }}</option>
      </select>
      <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
        <input v-model="filters.onlyActive" type="checkbox" class="rounded border-neutral-300 text-primary-600" @change="onFilterChange" />
        <span>{{ t('stock.vendor_offers.only_active') }}</span>
      </label>
    </div>

    <div v-if="error" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500 mb-4">{{ error }}</div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState
      v-else-if="offers.length === 0"
      boxed
      icon="factory"
      :title="t('stock.vendor_offers.empty_title')"
      :message="t('stock.vendor_offers.empty_hint')"
      :cta="canWrite ? t('stock.vendor_offers.new') : undefined"
      @action="openCreate"
    />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
            <tr>
              <th class="text-left px-3 py-2">{{ t('stock.vendor_offers.col_item') }}</th>
              <th class="text-left px-3 py-2">{{ t('stock.vendor_offers.col_vendor') }}</th>
              <th class="text-left px-3 py-2">{{ t('stock.vendor_offers.col_vendor_sku') }}</th>
              <th class="text-right px-3 py-2">{{ t('stock.vendor_offers.col_price') }}</th>
              <th class="text-right px-3 py-2">{{ t('stock.vendor_offers.col_vendor_qty') }}</th>
              <th class="text-left px-3 py-2">{{ t('stock.vendor_offers.col_availability') }}</th>
              <th class="text-right px-3 py-2">{{ t('stock.vendor_offers.col_delivery') }}</th>
              <th class="text-right px-3 py-2">{{ t('stock.vendor_offers.col_packaging') }}</th>
              <th class="text-right px-3 py-2">{{ t('stock.vendor_offers.col_on_hand') }}</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="o in offers" :key="o.id">
              <!-- Zobrazení -->
              <tr v-if="editingId !== o.id" class="hover:bg-neutral-50" :class="{ 'opacity-60': !o.is_active }">
                <td class="px-3 py-2">
                  <RouterLink :to="`/stock/items/${o.stock_item_id}`" class="text-primary-600 hover:underline font-mono text-xs">{{ o.sku }}</RouterLink>
                  <div class="text-neutral-700">{{ o.item_name }}</div>
                </td>
                <td class="px-3 py-2">
                  <RouterLink :to="`/clients/${o.client_id}`" class="text-primary-600 hover:underline">{{ o.client_name }}</RouterLink>
                  <span v-if="o.is_preferred" class="ml-1.5 inline-block px-1.5 py-0.5 text-[10px] rounded border bg-accent-50 text-accent-700 border-accent-500/40">{{ t('stock.vendor_offers.preferred_badge') }}</span>
                  <span v-if="!o.is_active" class="ml-1.5 inline-block px-1.5 py-0.5 text-[10px] rounded border bg-neutral-100 text-neutral-500 border-neutral-200">{{ t('stock.vendor_offers.inactive_badge') }}</span>
                </td>
                <td class="px-3 py-2 font-mono text-xs text-neutral-600">{{ o.vendor_sku || '—' }}</td>
                <td class="px-3 py-2 text-right tabular-nums">
                  {{ price(o) }}
                  <div v-if="o.price_valid_to" class="text-[11px] text-neutral-400">{{ t('stock.vendor_offers.valid_to_short', { date: formatDate(o.price_valid_to) }) }}</div>
                </td>
                <td class="px-3 py-2 text-right tabular-nums">
                  {{ qty(o.stock_qty) }} <span class="text-neutral-400">{{ o.unit }}</span>
                  <!-- Informativní razítko, ne varování: hlášené množství platí,
                       dokud ho dodavatel nezmění (rozhodnutí #7). -->
                  <div v-if="o.stock_qty_updated_at" class="text-[11px] text-neutral-400">{{ formatDate(o.stock_qty_updated_at) }}</div>
                </td>
                <td class="px-3 py-2">
                  <span class="inline-block px-2 py-0.5 text-xs rounded border" :class="availabilityClass(o.availability_state)">
                    {{ t(`stock.vendor_offers.availability.${o.availability_state}`) }}
                  </span>
                </td>
                <td class="px-3 py-2 text-right tabular-nums">{{ o.delivery_days === null ? '—' : t('stock.vendor_offers.days', { n: o.delivery_days }) }}</td>
                <td class="px-3 py-2 text-right tabular-nums text-xs text-neutral-600">
                  <span v-if="o.min_order_qty">{{ t('stock.vendor_offers.moq_short', { qty: qty(o.min_order_qty) }) }}</span>
                  <span v-if="o.min_order_qty && o.package_qty"> · </span>
                  <span v-if="o.package_qty">{{ t('stock.vendor_offers.pack_short', { qty: qty(o.package_qty) }) }}</span>
                  <span v-if="!o.min_order_qty && !o.package_qty">—</span>
                </td>
                <td class="px-3 py-2 text-right tabular-nums">{{ qty(o.on_hand) }}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap">
                  <button v-if="canWrite" type="button" :class="btnOutline('neutral')" class="!h-7 !px-2" @click="startEdit(o)">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                    {{ t('common.edit') }}
                  </button>
                  <button v-if="canWrite" type="button" :class="btnOutline('danger')" class="!h-7 !px-2 ml-1.5" @click="remove(o)">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  </button>
                </td>
              </tr>

              <!-- Inline editace -->
              <tr v-else class="bg-primary-50/40">
                <td class="px-3 py-2 align-top">
                  <div class="font-mono text-xs">{{ o.sku }}</div>
                  <div class="text-neutral-700">{{ o.item_name }}</div>
                </td>
                <td class="px-3 py-2 align-top">
                  <div>{{ o.client_name }}</div>
                  <label class="mt-1 inline-flex items-center gap-1.5 text-xs cursor-pointer">
                    <input v-model="draft.is_preferred" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                    <span>{{ t('stock.vendor_offers.preferred_badge') }}</span>
                  </label>
                  <label class="ml-2 inline-flex items-center gap-1.5 text-xs cursor-pointer">
                    <input v-model="draft.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
                    <span>{{ t('stock.vendor_offers.field_active') }}</span>
                  </label>
                </td>
                <td class="px-3 py-2 align-top">
                  <input v-model="draft.vendor_sku" type="text" maxlength="80" class="w-28 rounded-md border border-neutral-300 h-8 px-2 text-sm" />
                </td>
                <td class="px-3 py-2 align-top">
                  <div class="flex items-center gap-1 justify-end">
                    <input v-model="draft.purchase_price" type="text" inputmode="decimal" class="w-24 text-right rounded-md border border-neutral-300 h-8 px-2 text-sm" />
                    <select v-model="draft.currency_code" class="w-20 rounded-md border border-neutral-300 bg-surface h-8 px-1 text-sm font-mono">
                      <option v-for="c in currencyOptions(draft.currency_code)" :key="c" :value="c">{{ c }}</option>
                    </select>
                  </div>
                  <!-- `draft` je Record<string, string | boolean>, takže v-model neprojde typem; hodnota je ale vždy řetězec (viz text()). -->
                  <DateInput :model-value="String(draft.price_valid_to ?? '')" @update:model-value="draft.price_valid_to = $event"
                    class="mt-1 w-full rounded-md border border-neutral-300 h-8 px-2 text-xs" :title="t('stock.vendor_offers.field_valid_to')" />
                </td>
                <td class="px-3 py-2 align-top">
                  <input v-model="draft.stock_qty" type="text" inputmode="decimal" class="w-24 text-right rounded-md border border-neutral-300 h-8 px-2 text-sm" />
                </td>
                <td class="px-3 py-2 align-top">
                  <select v-model="draft.availability_state" class="rounded-md border border-neutral-300 h-8 px-2 text-sm">
                    <option v-for="s in AVAILABILITY" :key="s" :value="s">{{ t(`stock.vendor_offers.availability.${s}`) }}</option>
                  </select>
                </td>
                <td class="px-3 py-2 align-top">
                  <input v-model="draft.delivery_days" type="text" inputmode="numeric" class="w-16 text-right rounded-md border border-neutral-300 h-8 px-2 text-sm" />
                </td>
                <td class="px-3 py-2 align-top">
                  <input v-model="draft.min_order_qty" type="text" inputmode="decimal" class="w-20 text-right rounded-md border border-neutral-300 h-8 px-2 text-sm" :placeholder="t('stock.vendor_offers.field_min_order')" />
                  <input v-model="draft.package_qty" type="text" inputmode="decimal" class="mt-1 w-20 text-right rounded-md border border-neutral-300 h-8 px-2 text-sm" :placeholder="t('stock.vendor_offers.field_package')" />
                </td>
                <td class="px-3 py-2 align-top text-right tabular-nums">{{ qty(o.on_hand) }}</td>
                <td class="px-3 py-2 align-top text-right whitespace-nowrap">
                  <button type="button" :class="btnFilled('primary')" class="!h-7 !px-2" :disabled="savingId === o.id" @click="saveEdit">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                    {{ savingId === o.id ? t('common.loading') : t('common.save') }}
                  </button>
                  <button type="button" :class="btnOutline('neutral')" class="!h-7 !px-2 ml-1.5" @click="cancelEdit">{{ t('common.cancel') }}</button>
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>
      <PaginationBar embedded :page="page" :per-page="PER_PAGE" :total="total" @update:page="page = $event" />
    </div>

    <!-- Nová nabídka -->
    <Modal v-if="createOpen" :title="t('stock.vendor_offers.new')" width-class="max-w-3xl" @close="createOpen = false">
      <div class="space-y-3">
        <div v-if="createError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ createError }}</div>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('stock.vendor_offers.col_item') }}</label>
            <SearchableSelect
              remote
              :model-value="form.stock_item_id || null"
              :options="itemOptions"
              :selected-option="selectedItem"
              :loading="itemLoading"
              :placeholder="t('stock.vendor_offers.pick_item')"
              @search="searchItems"
              @update:model-value="onItemPicked"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('stock.vendor_offers.col_vendor') }}</label>
            <SearchableSelect
              remote
              :model-value="form.client_id || null"
              :options="vendorOptions"
              :selected-option="selectedVendor"
              :loading="vendorLoading"
              :placeholder="t('stock.vendor_offers.pick_vendor')"
              @search="searchVendors"
              @update:model-value="onVendorPicked"
            />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('stock.vendor_offers.col_vendor_sku') }}</label>
            <input v-model="form.vendor_sku" type="text" maxlength="80" class="w-full rounded-md border border-neutral-300 h-9 px-3 text-sm" />
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('stock.vendor_offers.col_price') }}</label>
              <input v-model="form.purchase_price" type="text" inputmode="decimal" class="w-full rounded-md border border-neutral-300 h-9 px-3 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('stock.vendor_offers.field_currency') }}</label>
              <select v-model="form.currency_code" class="w-full rounded-md border border-neutral-300 bg-surface h-9 px-2 text-sm font-mono">
                <option value="">{{ t('eshop.prices.select_currency') }}</option>
                <option v-for="c in currencyOptions(form.currency_code)" :key="c" :value="c">{{ c }}</option>
              </select>
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('stock.vendor_offers.col_vendor_qty') }}</label>
            <input v-model="form.stock_qty" type="text" inputmode="decimal" class="w-full rounded-md border border-neutral-300 h-9 px-3 text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('stock.vendor_offers.col_availability') }}</label>
            <select v-model="form.availability_state" class="w-full rounded-md border border-neutral-300 h-9 px-2 text-sm">
              <option v-for="s in AVAILABILITY" :key="s" :value="s">{{ t(`stock.vendor_offers.availability.${s}`) }}</option>
            </select>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('stock.vendor_offers.col_delivery') }}</label>
            <input v-model="form.delivery_days" type="number" min="0" step="1" class="w-full rounded-md border border-neutral-300 h-9 px-3 text-sm" />
          </div>
          <div class="grid grid-cols-2 gap-2">
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('stock.vendor_offers.field_min_order') }}</label>
              <input v-model="form.min_order_qty" type="text" inputmode="decimal" class="w-full rounded-md border border-neutral-300 h-9 px-3 text-sm" />
            </div>
            <div>
              <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('stock.vendor_offers.field_package') }}</label>
              <input v-model="form.package_qty" type="text" inputmode="decimal" class="w-full rounded-md border border-neutral-300 h-9 px-3 text-sm" />
            </div>
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('stock.vendor_offers.field_valid_to') }}</label>
            <DateInput v-model="form.price_valid_to" class="w-full rounded-md border border-neutral-300 h-9 px-3 text-sm" />
          </div>
          <div class="md:col-span-2">
            <label class="block text-xs font-medium text-neutral-600 mb-1">{{ t('stock.vendor_offers.field_note') }}</label>
            <input v-model="form.note" type="text" maxlength="255" class="w-full rounded-md border border-neutral-300 h-9 px-3 text-sm" />
          </div>
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="form.is_preferred" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            <span>{{ t('stock.vendor_offers.field_preferred') }}</span>
          </label>
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="form.is_active" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            <span>{{ t('stock.vendor_offers.field_active') }}</span>
          </label>
        </div>

        <div class="flex items-center justify-end gap-3 pt-2 border-t border-neutral-100">
          <button type="button" :class="btnOutline('neutral')" @click="createOpen = false">{{ t('common.cancel') }}</button>
          <button type="button" :class="btnFilled('primary')" :disabled="creating" @click="saveCreate">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ creating ? t('common.loading') : t('common.save') }}
          </button>
        </div>
      </div>
    </Modal>

    <!-- Import ceníku -->
    <Modal v-if="importOpen" :title="t('stock.vendor_offers.import.title')" width-class="max-w-5xl" @close="importOpen = false">
      <div class="space-y-4">
        <p class="text-sm text-neutral-600">{{ t('stock.vendor_offers.import.hint') }}</p>

        <div v-if="importError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ importError }}</div>

        <label
          class="block border-2 border-dashed border-neutral-300 hover:border-primary-400 hover:bg-primary-50/30 rounded-lg p-6 text-center cursor-pointer transition"
          @dragover.prevent
          @drop="onImportDrop"
        >
          <input type="file" accept=".xlsx,.csv" class="hidden" @change="onImportPick" />
          <svg class="w-7 h-7 mx-auto text-neutral-400 mb-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
          <div class="text-sm font-medium text-neutral-700">{{ t('stock.vendor_offers.import.drop_hint') }}</div>
        </label>

        <div v-if="importFile" class="border border-neutral-200 rounded-md p-3 bg-neutral-50 flex justify-between text-sm text-neutral-700">
          <span class="truncate font-mono">{{ importFile.name }}</span>
          <span class="text-neutral-400 ml-2 shrink-0">{{ Math.round(importFile.size / 1024) }} kB</span>
        </div>

        <div class="flex flex-wrap items-center justify-between gap-3">
          <label class="inline-flex items-center gap-2 text-sm cursor-pointer">
            <input v-model="importDryRun" type="checkbox" class="rounded border-neutral-300 text-primary-600" />
            <span>{{ t('stock.vendor_offers.import.dry_run') }}</span>
          </label>
          <button type="button" :class="btnFilled('primary')" :disabled="!importFile || importBusy" @click="runImport(false)">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
            {{ importBusy ? t('common.loading') : (importDryRun ? t('stock.vendor_offers.import.run_preview') : t('stock.vendor_offers.import.run')) }}
          </button>
        </div>

        <div class="text-xs text-neutral-500 border-t border-neutral-100 pt-3">
          <span class="font-medium text-neutral-600">{{ t('stock.vendor_offers.import.columns_hint') }}:</span>
          <span class="ml-1">
            <code v-for="(c, i) in IMPORT_COLUMNS" :key="c" class="font-mono">{{ c }}<span v-if="i < IMPORT_COLUMNS.length - 1" class="text-neutral-400">, </span></code>
          </span>
          <div class="mt-1">{{ t('stock.vendor_offers.import.columns_note') }}</div>
        </div>

        <div v-if="importReport" class="border border-neutral-200 rounded-lg overflow-hidden">
          <div class="flex flex-wrap items-center gap-3 text-sm px-3 py-2 border-b border-neutral-100 bg-neutral-50">
            <span class="text-xs px-2 py-0.5 rounded font-medium" :class="importReport.dry_run ? 'bg-neutral-100 text-neutral-600' : 'bg-success-50 text-success-600'">
              {{ importReport.dry_run ? t('stock.vendor_offers.import.badge_preview') : t('stock.vendor_offers.import.badge_done') }}
            </span>
            <span>{{ t('stock.vendor_offers.import.summary', {
              created: importReport.created,
              updated: importReport.updated,
              skipped: importReport.skipped,
              failed: importReport.failed,
            }) }}</span>
          </div>
          <div class="max-h-72 overflow-y-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  <th class="text-left px-3 py-2">{{ t('stock.vendor_offers.import.col_line') }}</th>
                  <th class="text-left px-3 py-2">{{ t('stock.vendor_offers.import.col_key') }}</th>
                  <th class="text-left px-3 py-2">{{ t('stock.vendor_offers.import.col_status') }}</th>
                  <th class="text-left px-3 py-2">{{ t('stock.vendor_offers.import.col_detail') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="(r, i) in importReport.rows" :key="i" class="align-top">
                  <td class="px-3 py-2 text-neutral-500 font-mono">{{ r.line }}</td>
                  <td class="px-3 py-2 font-mono text-neutral-800">{{ r.key }}</td>
                  <td class="px-3 py-2">
                    <span class="inline-block px-2 py-0.5 text-xs rounded border" :class="importStatusClass(r.status)">{{ t(`stock.vendor_offers.import.status_${r.status}`) }}</span>
                  </td>
                  <td class="px-3 py-2 text-neutral-600">
                    <ul v-if="importChanges(r).length" class="space-y-0.5">
                      <li v-for="c in importChanges(r)" :key="c.field" class="text-xs">
                        <span class="text-neutral-500">{{ c.field }}:</span>
                        <span class="line-through text-neutral-400 ml-1">{{ fmt(c.from) }}</span>
                        <span class="mx-1">→</span>
                        <span class="text-neutral-800">{{ fmt(c.to) }}</span>
                      </li>
                    </ul>
                    <div v-if="r.message" class="text-xs" :class="r.status === 'error' ? 'text-danger-500' : 'text-warning-600'">{{ r.message }}</div>
                    <span v-if="!importChanges(r).length && !r.message" class="text-neutral-400">—</span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          <div v-if="canCommitImport" class="flex items-center justify-end gap-3 px-3 py-2 border-t border-neutral-100">
            <span class="text-xs text-neutral-500 mr-auto">{{ t('stock.vendor_offers.import.commit_hint') }}</span>
            <button type="button" :class="btnFilled('success')" :disabled="importBusy" @click="runImport(true)">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ importBusy ? t('common.loading') : t('stock.vendor_offers.import.commit') }}
            </button>
          </div>
          <div v-else-if="importReport.dry_run && importHasErrors" class="px-3 py-2 border-t border-neutral-100 text-sm text-danger-500">
            {{ t('stock.vendor_offers.import.has_errors') }}
          </div>
        </div>

        <div class="flex items-center justify-end pt-2 border-t border-neutral-100">
          <button type="button" :class="btnOutline('neutral')" @click="importOpen = false">{{ t('common.close') }}</button>
        </div>
      </div>
    </Modal>
  </div>
</template>
