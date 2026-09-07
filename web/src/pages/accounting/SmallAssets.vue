<script setup lang="ts">
/**
 * Drobný majetek (§DM krok 3) — evidence dle §28/5 ZoÚ a ČÚS 013.
 *
 * Karta NIC neúčtuje: náklad na 501 vznikl už zaúčtováním dokladu podle expense_kind.
 * Tahle stránka je evidence věcí — soupis k datu je podklad, který účetní podepisuje
 * k inventarizaci.
 */
import { ref, reactive, computed, onMounted, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import {
  smallAssetsApi,
  type SmallAsset,
  type SmallAssetStatus,
  type SmallAssetPayload,
} from '@/api/smallAssets'
import { invoicesApi, type InvoiceListItem } from '@/api/invoices'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import { ICONS, btnFilled, btnOutline, btnOutlineSm, btnIconSm } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appIsoDate, appYear } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()
const pageId = useId()

const canWrite = computed(() => auth.canWrite('accounting'))

// ── seznam ──────────────────────────────────────────────────────────────────
const items = ref<SmallAsset[]>([])
const locations = ref<string[]>([])
const years = ref<number[]>([])
const loading = ref(false)
const page = ref(1)
const total = ref(0)
const perPage = ref(50)
const totalPages = computed(() => Math.max(1, Math.ceil(total.value / perPage.value)))

const filters = reactive({
  status: 'in_use' as SmallAssetStatus | '',
  q: '',
  location: '',
  year: '' as number | '',
})

async function load() {
  loading.value = true
  try {
    const r = await smallAssetsApi.list({
      status: filters.status || undefined,
      q: filters.q || undefined,
      location: filters.location || undefined,
      year: filters.year || undefined,
      page: page.value,
    })
    items.value = r.items
    total.value = r.total
    perPage.value = r.per_page
    locations.value = r.locations
    years.value = r.years
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  page.value = 1
  load()
}

function goPage(p: number) {
  page.value = Math.min(Math.max(1, p), totalPages.value)
  load()
}

const STATUS_BADGE: Record<SmallAssetStatus, string> = {
  in_use: 'bg-success-50 text-success-600',
  disposed: 'bg-neutral-100 text-neutral-400',
  sold: 'bg-primary-50 text-primary-600',
}

function statusLabel(status: SmallAssetStatus): string {
  return t(`accounting.small_assets.status_${status}`)
}

/** Součet zobrazené stránky — orientační, celkový součet dává soupis k datu. */
const pageTotal = computed(() => items.value.reduce((sum, c) => sum + Number(c.price || 0), 0))

// ── karta: založení / editace ───────────────────────────────────────────────
const showCard = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
/** Editovaná karta v původní podobě — zdroj read-only shrnutí stavu v modalu. */
const editingCard = ref<SmallAsset | null>(null)
const form = reactive<SmallAssetPayload & { quantity: number; unit_price: number }>({
  name: '',
  acquisition_date: appIsoDate(),
  price: 0,
  quantity: 1,
  unit_price: 0,
  inventory_number: '',
  put_into_use_date: '',
  location: '',
  responsible_person: '',
  document_ref: '',
  vendor_name: '',
  notes: '',
})

function resetForm() {
  form.name = ''
  form.acquisition_date = appIsoDate()
  form.price = 0
  form.quantity = 1
  form.unit_price = 0
  form.inventory_number = ''
  form.put_into_use_date = ''
  form.location = ''
  form.responsible_person = ''
  form.document_ref = ''
  form.vendor_name = ''
  form.notes = ''
}

function openNew() {
  editingId.value = null
  editingCard.value = null
  resetForm()
  showCard.value = true
}

function openEdit(card: SmallAsset) {
  editingId.value = card.id
  // Celá karta kvůli read-only shrnutí stavu v modalu. Vyřazení ani prodej se tu
  // needituje (stav a jeho datum spolu drží DB CHECK chk_sma_disposal, mění je jen
  // příslušná akce) — ale účetní potřebuje vidět, na čem je, aniž by modal zavírala.
  editingCard.value = card
  form.name = card.name
  form.acquisition_date = card.acquisition_date
  form.price = card.price
  form.quantity = card.quantity
  form.unit_price = card.unit_price
  form.inventory_number = card.inventory_number ?? ''
  form.put_into_use_date = card.put_into_use_date ?? ''
  form.location = card.location ?? ''
  form.responsible_person = card.responsible_person ?? ''
  form.document_ref = card.document_ref ?? ''
  form.vendor_name = card.vendor_name ?? ''
  form.notes = card.notes ?? ''
  showCard.value = true
}

async function saveCard() {
  if (!form.name.trim()) {
    toast.error(t('accounting.small_assets.name_required'))
    return
  }
  saving.value = true
  try {
    const payload: SmallAssetPayload = {
      name: form.name.trim(),
      acquisition_date: form.acquisition_date,
      price: Number(form.price),
      quantity: Number(form.quantity),
      unit_price: Number(form.unit_price),
      inventory_number: form.inventory_number || null,
      put_into_use_date: form.put_into_use_date || null,
      location: form.location || null,
      responsible_person: form.responsible_person || null,
      document_ref: form.document_ref || null,
      vendor_name: form.vendor_name || null,
      notes: form.notes || null,
    }
    if (editingId.value === null) {
      await smallAssetsApi.create(payload)
      toast.success(t('accounting.small_assets.created'))
    } else {
      await smallAssetsApi.update(editingId.value, payload)
      toast.success(t('accounting.small_assets.updated'))
    }
    showCard.value = false
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    saving.value = false
  }
}

async function removeCard(card: SmallAsset) {
  if (!window.confirm(t('accounting.small_assets.confirm_delete', { name: card.name }))) return
  try {
    await smallAssetsApi.remove(card.id)
    toast.success(t('accounting.small_assets.deleted'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ── vyřazení ────────────────────────────────────────────────────────────────
const showDispose = ref(false)
const disposing = ref(false)
const disposeTarget = ref<SmallAsset | null>(null)
const disposeForm = reactive({ disposed_at: appIsoDate(), disposal_reason: '' })

function openDispose(card: SmallAsset) {
  disposeTarget.value = card
  disposeForm.disposed_at = appIsoDate()
  disposeForm.disposal_reason = ''
  showDispose.value = true
}

async function runDispose() {
  if (!disposeTarget.value) return
  disposing.value = true
  try {
    await smallAssetsApi.dispose(disposeTarget.value.id, {
      disposed_at: disposeForm.disposed_at,
      disposal_reason: disposeForm.disposal_reason || null,
    })
    toast.success(t('accounting.small_assets.disposed'))
    showDispose.value = false
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    disposing.value = false
  }
}

async function restoreCard(card: SmallAsset) {
  try {
    await smallAssetsApi.restore(card.id)
    toast.success(t('accounting.small_assets.restored'))
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

// ── prodej ────────────────────────────────────────────────────────────────────
const showSell = ref(false)
const selling = ref(false)
const sellTarget = ref<SmallAsset | null>(null)
const sellForm = reactive({ sale_invoice_id: null as number | null, sold_at: appIsoDate(), sale_price: null as number | null })
const invoiceQuery = ref('')
const invoiceResults = ref<InvoiceListItem[]>([])
const invoiceSearching = ref(false)
const selectedInvoiceLabel = ref('')

function openSell(card: SmallAsset) {
  sellTarget.value = card
  sellForm.sale_invoice_id = null
  sellForm.sold_at = appIsoDate()
  sellForm.sale_price = card.price
  invoiceQuery.value = ''
  invoiceResults.value = []
  selectedInvoiceLabel.value = ''
  showSell.value = true
}

async function searchInvoices() {
  if (!invoiceQuery.value.trim()) { invoiceResults.value = []; return }
  invoiceSearching.value = true
  try {
    invoiceResults.value = await invoicesApi.searchMatchable(invoiceQuery.value.trim(), 15)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    invoiceSearching.value = false
  }
}

function pickInvoice(inv: InvoiceListItem) {
  sellForm.sale_invoice_id = inv.id
  selectedInvoiceLabel.value = `${inv.varsymbol || '#' + inv.id} — ${inv.client_company_name}`
  invoiceResults.value = []
}

async function runSell() {
  if (!sellTarget.value) return
  if (!sellForm.sale_invoice_id) {
    toast.error(t('accounting.small_assets.sale_invoice_required'))
    return
  }
  selling.value = true
  try {
    await smallAssetsApi.sell(sellTarget.value.id, {
      sale_invoice_id: sellForm.sale_invoice_id,
      sold_at: sellForm.sold_at,
      sale_price: sellForm.sale_price ?? null,
    })
    toast.success(t('accounting.small_assets.sold'))
    showSell.value = false
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    selling.value = false
  }
}

// ── sestavy ─────────────────────────────────────────────────────────────────
const showReports = ref(false)
const exporting = ref(false)
const reportForm = reactive({
  as_of: appIsoDate(),
  from: `${appYear()}-01-01`,
  to: `${appYear()}-12-31`,
})

type ReportKey = 'inventory' | 'movements' | 'expense-breakdown'

const REPORT_PATHS: Record<ReportKey, string> = {
  inventory: '/accounting/reports/small-assets/inventory/export',
  movements: '/accounting/reports/small-assets/movements/export',
  'expense-breakdown': '/accounting/reports/small-assets/expense-breakdown/export',
}

function reportParams(report: ReportKey): Record<string, string> {
  return report === 'inventory'
    ? { as_of: reportForm.as_of }
    : { from: reportForm.from, to: reportForm.to }
}

function reportFilename(report: ReportKey, format: string): string {
  if (report === 'inventory') return `soupis-drobneho-majetku-${reportForm.as_of}.${format}`
  const range = `${reportForm.from}_${reportForm.to}`
  return report === 'movements'
    ? `drobny-majetek-pohyby-${range}.${format}`
    : `rozpis-501-${range}.${format}`
}

async function exportReport(report: ReportKey, format: 'pdf' | 'xlsx') {
  exporting.value = true
  try {
    const r = await smallAssetsApi.exportReport(REPORT_PATHS[report], { ...reportParams(report), format })
    downloadBlob(r.data as unknown as Blob, reportFilename(report, format))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    exporting.value = false
  }
}

function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a); a.click(); a.remove()
  URL.revokeObjectURL(url)
}

// Proklik z detailu přijaté faktury ("Vyřadit prodejem" u položky s kartou drobného
// majetku, viz InvoiceDetail.vue) — otevře rovnou modal prodeje pro danou kartu, ať ji
// uživatel nemusí dohledávat ručně v seznamu (task #17). `status=''` obchází výchozí
// filtr „v užívání", kdyby karta z nějakého důvodu nebyla na první stránce defaultu.
onMounted(async () => {
  const sellId = route.query.sell ? Number(route.query.sell) : null
  if (sellId) filters.status = ''
  await load()
  if (sellId) {
    const card = items.value.find(c => c.id === sellId)
    if (card && card.status === 'in_use') {
      openSell(card)
    } else if (card) {
      toast.error(t('accounting.small_assets.already_settled'))
    }
    router.replace({ query: {} })
  }
})
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.small_assets.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.small_assets.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <button @click="showReports = true" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chart" /></svg>
          {{ t('accounting.small_assets.reports') }}
        </button>
        <button v-if="canWrite" @click="openNew" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('accounting.small_assets.new') }}
        </button>
      </div>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.filter_status') }}</label>
          <select v-model="filters.status" @change="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('accounting.small_assets.status_all') }}</option>
            <option value="in_use">{{ t('accounting.small_assets.status_in_use') }}</option>
            <option value="disposed">{{ t('accounting.small_assets.status_disposed') }}</option>
            <option value="sold">{{ t('accounting.small_assets.status_sold') }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.filter_year') }}</label>
          <select v-model="filters.year" @change="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('accounting.small_assets.year_all') }}</option>
            <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.filter_location') }}</label>
          <select v-model="filters.location" @change="applyFilters" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="">{{ t('accounting.small_assets.location_all') }}</option>
            <option v-for="loc in locations" :key="loc" :value="loc">{{ loc }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.filter_q') }}</label>
          <input v-model="filters.q" @keyup.enter="applyFilters" type="search"
                 :placeholder="t('accounting.small_assets.filter_q_placeholder')"
                 class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
      </div>
    </div>

    <!-- Seznam -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div v-if="loading" class="p-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
      <EmptyState v-else-if="items.length === 0" icon="box"
        :title="t('accounting.small_assets.empty')"
        :cta="canWrite ? t('accounting.small_assets.new') : undefined"
        @action="openNew" />
      <!-- Desktop tabulka + mobilní karty pod jedním `v-else`: samostatný
           `v-else` na druhém bloku by se neměl čeho chytit, řetěz už spotřeboval
           EmptyState. Devět sloupců se na telefon nevejde a za okrajem zůstávala
           cena i stav. -->
      <template v-else>
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500">
            <tr>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.small_assets.col_name') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.small_assets.col_document') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.small_assets.col_vendor') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.small_assets.col_acquired') }}</th>
              <th class="text-right font-medium px-3 py-2">{{ t('accounting.small_assets.col_price') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.small_assets.col_location') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.small_assets.col_responsible') }}</th>
              <th class="text-left font-medium px-3 py-2">{{ t('accounting.small_assets.col_status') }}</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="card in items" :key="card.id" class="border-t border-neutral-100">
              <td class="px-3 py-2">
                <div class="font-medium">{{ card.name }}</div>
                <div v-if="card.inventory_number" class="text-xs text-neutral-400">{{ card.inventory_number }}</div>
              </td>
              <td class="px-3 py-2">
                <RouterLink v-if="card.purchase_invoice_id"
                            :to="{ name: 'purchase-invoice-detail', params: { id: card.purchase_invoice_id } }"
                            class="text-primary-600 hover:underline">
                  {{ card.document_ref || t('accounting.small_assets.source_invoice') }}
                </RouterLink>
                <span v-else-if="card.cash_document_id">{{ card.document_ref || t('accounting.small_assets.source_cash') }}</span>
                <span v-else class="text-neutral-400">{{ card.document_ref || t('accounting.small_assets.source_manual') }}</span>
              </td>
              <td class="px-3 py-2">{{ card.vendor_client_name || card.vendor_name || '—' }}</td>
              <td class="px-3 py-2">{{ formatDate(card.acquisition_date) }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ formatMoney(card.price) }}</td>
              <td class="px-3 py-2">{{ card.location || '—' }}</td>
              <td class="px-3 py-2">{{ card.responsible_person || '—' }}</td>
              <td class="px-3 py-2 whitespace-nowrap">
                <span class="inline-block px-2 py-0.5 rounded-full text-xs whitespace-nowrap" :class="STATUS_BADGE[card.status]">
                  {{ statusLabel(card.status) }}
                </span>
                <div v-if="card.disposed_at" class="text-xs text-neutral-400 mt-0.5">{{ formatDate(card.disposed_at) }}</div>
                <div v-if="card.status === 'sold' && card.sale_invoice_id" class="text-xs mt-0.5">
                  <RouterLink :to="{ name: 'invoice-detail', params: { id: card.sale_invoice_id } }" class="text-primary-600 hover:underline">
                    {{ t('accounting.small_assets.sale_invoice_link') }}<span v-if="card.sold_at"> · {{ formatDate(card.sold_at) }}</span>
                  </RouterLink>
                </div>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">
                <div v-if="canWrite" class="flex flex-nowrap items-center justify-end gap-1">
                  <button @click="openEdit(card)" :class="btnOutlineSm('neutral')" :title="t('common.edit')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
                  </button>
                  <button v-if="card.status === 'in_use'" @click="openSell(card)" :class="btnOutlineSm('primary')" :title="t('accounting.small_assets.sell')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
                  </button>
                  <button v-if="card.status === 'in_use'" @click="openDispose(card)" :class="btnOutlineSm('warning')" :title="t('accounting.small_assets.dispose')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" /></svg>
                  </button>
                  <button v-else @click="restoreCard(card)" :class="btnOutlineSm('success')" :title="t('accounting.small_assets.restore')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
                  </button>
                  <button @click="removeCard(card)" :class="btnOutlineSm('danger')" :title="t('common.delete')">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
          <tfoot class="border-t-2 border-neutral-200 font-medium">
            <tr>
              <td class="px-3 py-2" colspan="4">{{ t('accounting.small_assets.page_total') }}</td>
              <td class="px-3 py-2 text-right whitespace-nowrap">{{ formatMoney(pageTotal) }}</td>
              <td colspan="4"></td>
            </tr>
          </tfoot>
        </table>
      </div>

      <!-- Mobil: karty. Akce jsou ikonové v jednom pruhu, stejně jako u banky
           a plánu odpisů — pět textových tlačítek by kartu roztrhalo. -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="card in items" :key="`m-${card.id}`" class="p-3 space-y-2">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <div class="font-medium truncate">{{ card.name }}</div>
              <div v-if="card.inventory_number" class="text-xs text-neutral-400 font-mono">{{ card.inventory_number }}</div>
            </div>
            <div class="text-right shrink-0">
              <div class="font-mono text-sm whitespace-nowrap">{{ formatMoney(card.price) }}</div>
              <span class="inline-block px-2 py-0.5 rounded-full text-xs whitespace-nowrap mt-0.5" :class="STATUS_BADGE[card.status]">
                {{ statusLabel(card.status) }}
              </span>
            </div>
          </div>

          <div class="text-xs text-neutral-500 space-y-0.5">
            <div>{{ formatDate(card.acquisition_date) }} · {{ card.vendor_client_name || card.vendor_name || '—' }}</div>
            <div v-if="card.location || card.responsible_person">
              {{ card.location || '—' }}<span v-if="card.responsible_person"> · {{ card.responsible_person }}</span>
            </div>
            <div>
              <RouterLink v-if="card.purchase_invoice_id"
                :to="{ name: 'purchase-invoice-detail', params: { id: card.purchase_invoice_id } }"
                class="text-primary-600 hover:underline">
                {{ card.document_ref || t('accounting.small_assets.source_invoice') }}
              </RouterLink>
              <span v-else-if="card.cash_document_id">{{ card.document_ref || t('accounting.small_assets.source_cash') }}</span>
              <span v-else class="text-neutral-400">{{ card.document_ref || t('accounting.small_assets.source_manual') }}</span>
            </div>
            <div v-if="card.status === 'sold' && card.sale_invoice_id">
              <RouterLink :to="{ name: 'invoice-detail', params: { id: card.sale_invoice_id } }" class="text-primary-600 hover:underline">
                {{ t('accounting.small_assets.sale_invoice_link') }}<span v-if="card.sold_at"> · {{ formatDate(card.sold_at) }}</span>
              </RouterLink>
            </div>
          </div>

          <div v-if="canWrite" class="flex items-center justify-end gap-1.5 pt-1">
            <button @click="openEdit(card)" :class="btnIconSm('neutral')" :title="t('common.edit')" :aria-label="t('common.edit')">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
            </button>
            <button v-if="card.status === 'in_use'" @click="openSell(card)" :class="btnIconSm('primary')" :title="t('accounting.small_assets.sell')" :aria-label="t('accounting.small_assets.sell')">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
            </button>
            <button v-if="card.status === 'in_use'" @click="openDispose(card)" :class="btnIconSm('warning')" :title="t('accounting.small_assets.dispose')" :aria-label="t('accounting.small_assets.dispose')">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" /></svg>
            </button>
            <button v-else @click="restoreCard(card)" :class="btnIconSm('success')" :title="t('accounting.small_assets.restore')" :aria-label="t('accounting.small_assets.restore')">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
            </button>
            <button @click="removeCard(card)" :class="btnIconSm('danger')" :title="t('common.delete')" :aria-label="t('common.delete')">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
            </button>
          </div>
        </div>

        <div class="p-3 flex items-center justify-between text-sm font-medium border-t border-neutral-200">
          <span>{{ t('accounting.small_assets.page_total') }}</span>
          <span class="font-mono whitespace-nowrap">{{ formatMoney(pageTotal) }}</span>
        </div>
      </div>
      </template>

      <div v-if="totalPages > 1" class="flex flex-wrap items-center justify-between gap-2 px-3 py-2 border-t border-neutral-100">
        <span class="text-xs text-neutral-500">{{ t('accounting.small_assets.count', { total }) }}</span>
        <div class="flex items-center gap-2">
          <button :disabled="page <= 1" @click="goPage(page - 1)" :class="btnOutlineSm('neutral')">{{ t('common.previous') }}</button>
          <span class="text-xs text-neutral-500">{{ page }} / {{ totalPages }}</span>
          <button :disabled="page >= totalPages" @click="goPage(page + 1)" :class="btnOutlineSm('neutral')">{{ t('common.next') }}</button>
        </div>
      </div>
    </div>

    <!-- Modal: karta -->
    <Modal v-if="showCard" :title="editingId === null ? t('accounting.small_assets.new') : t('accounting.small_assets.edit')" @close="showCard = false">
      <!--
        Read-only shrnutí vyřazení/prodeje. Needituje se tu: stav a jeho datum jsou svázané
        DB CHECKem (chk_sma_disposal) a mění je jen akce Vyřadit / Prodat / Obnovit — kdyby
        šlo datum přepsat v editaci, vznikla by karta „v užívání" s datem vyřazení a soupis
        k inventarizaci by lhal. Účetní ale potřebuje vidět, na čem karta je.
      -->
      <div
        v-if="editingCard && editingCard.status !== 'in_use'"
        class="mb-3 flex flex-wrap items-center gap-x-3 gap-y-1 rounded-md px-3 py-2 text-xs"
        :class="editingCard.status === 'sold' ? 'bg-primary-50 text-primary-700' : 'bg-neutral-100 text-neutral-600'"
      >
        <span class="font-semibold">
          {{ editingCard.status === 'sold' ? t('accounting.small_assets.status_sold') : t('accounting.small_assets.status_disposed') }}
        </span>
        <span v-if="editingCard.status === 'sold' ? editingCard.sold_at : editingCard.disposed_at">
          {{ editingCard.status === 'sold' ? t('accounting.small_assets.sold_at') : t('accounting.small_assets.disposed_at') }}:
          {{ formatDate((editingCard.status === 'sold' ? editingCard.sold_at : editingCard.disposed_at) as string) }}
        </span>
        <span v-if="editingCard.status === 'sold' && editingCard.sale_price !== null">
          {{ formatMoney(editingCard.sale_price, 'CZK') }}
        </span>
        <span v-if="editingCard.status === 'disposed' && editingCard.disposal_reason" class="min-w-0 truncate">
          {{ editingCard.disposal_reason }}
        </span>
        <RouterLink
          v-if="editingCard.status === 'sold' && editingCard.sale_invoice_id"
          :to="{ name: 'invoice-detail', params: { id: editingCard.sale_invoice_id } }"
          class="underline hover:no-underline"
        >{{ t('accounting.small_assets.sale_invoice_link') }}</RouterLink>
      </div>

      <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.col_name') }} *</label>
          <input v-model="form.name" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.col_inventory_number') }}</label>
          <input v-model="form.inventory_number" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.col_document') }}</label>
          <input v-model="form.document_ref" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.col_vendor') }}</label>
          <input v-model="form.vendor_name" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.col_acquired') }} *</label>
          <DateInput v-model="form.acquisition_date" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.col_put_into_use') }}</label>
          <DateInput v-model="form.put_into_use_date" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.col_quantity') }}</label>
          <input v-model.number="form.quantity" type="number" step="0.001" min="0.001" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.col_unit_price') }}</label>
          <input v-model.number="form.unit_price" type="number" step="0.01" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          <p class="text-xs text-neutral-400 mt-1">{{ t('accounting.small_assets.unit_price_hint') }}</p>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.col_price') }} *</label>
          <input v-model.number="form.price" type="number" step="0.01" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.col_location') }}</label>
          <input v-model="form.location" :list="`${pageId}-sa-locations`" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          <datalist :id="`${pageId}-sa-locations`">
            <option v-for="loc in locations" :key="loc" :value="loc" />
          </datalist>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.col_responsible') }}</label>
          <input v-model="form.responsible_person" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <div class="sm:col-span-2">
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.col_notes') }}</label>
          <textarea v-model="form.notes" rows="2" class="w-full px-2 py-1.5 border border-neutral-300 rounded-md text-sm bg-surface"></textarea>
        </div>
      </div>
      <div class="flex justify-end gap-2 mt-4">
        <button @click="showCard = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
        <button :disabled="saving" @click="saveCard" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('common.save') }}
        </button>
      </div>
    </Modal>

    <!-- Modal: vyřazení -->
    <Modal v-if="showDispose" :title="t('accounting.small_assets.dispose')" widthClass="max-w-lg" @close="showDispose = false">
      <p class="text-sm text-neutral-600 mb-3">{{ t('accounting.small_assets.dispose_hint', { name: disposeTarget?.name ?? '' }) }}</p>
      <div class="grid grid-cols-1 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.disposed_at') }} *</label>
          <DateInput v-model="disposeForm.disposed_at" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.disposal_reason') }}</label>
          <input v-model="disposeForm.disposal_reason" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
        </div>
      </div>
      <div class="flex justify-end gap-2 mt-4">
        <button @click="showDispose = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
        <button :disabled="disposing" @click="runDispose" :class="btnFilled('warning')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" /></svg>
          {{ t('accounting.small_assets.dispose') }}
        </button>
      </div>
    </Modal>

    <!-- Modal: prodej -->
    <Modal v-if="showSell" :title="t('accounting.small_assets.sell')" widthClass="max-w-lg" @close="showSell = false">
      <p class="text-sm text-neutral-600 mb-3">{{ t('accounting.small_assets.sell_hint', { name: sellTarget?.name ?? '' }) }}</p>
      <div class="grid grid-cols-1 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.sale_invoice') }} *</label>
          <div v-if="sellForm.sale_invoice_id" class="flex items-center justify-between gap-2 h-9 px-2 border border-success-300 bg-success-50 rounded-md text-sm">
            <span class="truncate">{{ selectedInvoiceLabel }}</span>
            <button @click="sellForm.sale_invoice_id = null; selectedInvoiceLabel = ''" class="text-neutral-400 hover:text-neutral-600">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            </button>
          </div>
          <template v-else>
            <div class="flex gap-2">
              <input v-model="invoiceQuery" @keyup.enter="searchInvoices" type="search"
                     :placeholder="t('accounting.small_assets.sale_invoice_search')"
                     class="flex-1 h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
              <button @click="searchInvoices" :disabled="invoiceSearching" :class="btnOutline('neutral')">{{ t('common.search') }}</button>
            </div>
            <ul v-if="invoiceResults.length" class="mt-1 border border-neutral-200 rounded-md divide-y divide-neutral-100 max-h-48 overflow-y-auto">
              <li v-for="inv in invoiceResults" :key="inv.id">
                <button @click="pickInvoice(inv)" class="w-full text-left px-2 py-1.5 text-sm hover:bg-neutral-50">
                  <span class="font-medium">{{ inv.varsymbol || '#' + inv.id }}</span>
                  <span class="text-neutral-500"> — {{ inv.client_company_name }}</span>
                  <span class="text-neutral-400"> · {{ formatMoney(inv.total_with_vat) }}</span>
                </button>
              </li>
            </ul>
            <p class="text-xs text-neutral-400 mt-1">
              {{ t('accounting.small_assets.sale_invoice_create_hint') }}
              <RouterLink :to="{ name: 'invoice-new' }" target="_blank" class="text-primary-600 hover:underline">{{ t('accounting.small_assets.sale_invoice_create') }}</RouterLink>
            </p>
          </template>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.sold_at') }} *</label>
            <DateInput v-model="sellForm.sold_at" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.sale_price') }}</label>
            <input v-model.number="sellForm.sale_price" type="number" step="0.01" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
        </div>
        <p class="text-xs text-neutral-400">{{ t('accounting.small_assets.sell_accounting_note') }}</p>
      </div>
      <div class="flex justify-end gap-2 mt-4">
        <button @click="showSell = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
        <button :disabled="selling" @click="runSell" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
          {{ t('accounting.small_assets.sell') }}
        </button>
      </div>
    </Modal>

    <!-- Modal: sestavy -->
    <Modal v-if="showReports" :title="t('accounting.small_assets.reports')" @close="showReports = false">
      <p class="text-sm text-neutral-600 mb-4">{{ t('accounting.small_assets.reports_hint') }}</p>

      <div class="border border-neutral-200 rounded-lg p-3 mb-3">
        <h3 class="text-sm font-medium mb-1">{{ t('accounting.small_assets.report_inventory') }}</h3>
        <p class="text-xs text-neutral-500 mb-2">{{ t('accounting.small_assets.report_inventory_hint') }}</p>
        <div class="flex flex-wrap items-end gap-2">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.as_of') }}</label>
            <DateInput v-model="reportForm.as_of" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <button :disabled="exporting" @click="exportReport('inventory', 'pdf')" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('accounting.small_assets.export_pdf') }}
          </button>
          <button :disabled="exporting" @click="exportReport('inventory', 'xlsx')" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('accounting.small_assets.export_xlsx') }}
          </button>
        </div>
      </div>

      <div class="border border-neutral-200 rounded-lg p-3 mb-3">
        <h3 class="text-sm font-medium mb-1">{{ t('accounting.small_assets.report_movements') }}</h3>
        <p class="text-xs text-neutral-500 mb-2">{{ t('accounting.small_assets.report_movements_hint') }}</p>
        <div class="flex flex-wrap items-end gap-2">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.from') }}</label>
            <DateInput v-model="reportForm.from" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.to') }}</label>
            <DateInput v-model="reportForm.to" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <button :disabled="exporting" @click="exportReport('movements', 'pdf')" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('accounting.small_assets.export_pdf') }}
          </button>
          <button :disabled="exporting" @click="exportReport('movements', 'xlsx')" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('accounting.small_assets.export_xlsx') }}
          </button>
        </div>
      </div>

      <div class="border border-neutral-200 rounded-lg p-3">
        <h3 class="text-sm font-medium mb-1">{{ t('accounting.small_assets.report_breakdown') }}</h3>
        <p class="text-xs text-neutral-500 mb-2">{{ t('accounting.small_assets.report_breakdown_hint') }}</p>
        <div class="flex flex-wrap items-end gap-2">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.from') }}</label>
            <DateInput v-model="reportForm.from" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.small_assets.to') }}</label>
            <DateInput v-model="reportForm.to" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <button :disabled="exporting" @click="exportReport('expense-breakdown', 'pdf')" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('accounting.small_assets.export_pdf') }}
          </button>
          <button :disabled="exporting" @click="exportReport('expense-breakdown', 'xlsx')" :class="btnOutline('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
            {{ t('accounting.small_assets.export_xlsx') }}
          </button>
        </div>
      </div>

      <div class="flex justify-end gap-2 mt-4">
        <button @click="showReports = false" :class="btnOutline('neutral')">{{ t('common.close') }}</button>
      </div>
    </Modal>
  </div>
</template>
