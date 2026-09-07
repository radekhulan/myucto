<script setup lang="ts">
import { ref, reactive, onMounted, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { formatDate, formatMonth, formatMoney } from '@/composables/useFormat'
import {
  logbookApi, type Car, type Fueling, type FuelingPayload,
  type FuelInvoice, type FuelInvoiceItem,
} from '@/api/logbook'
import { useAuthStore } from '@/stores/auth'
import FilterBar, { type FilterChip } from '@/components/ui/FilterBar.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'

const { t, locale } = useI18n()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()
const auth = useAuthStore()
const props = defineProps<{ resetToken?: number; openNewToken?: number }>()

// Počet aktivních filtrů pro odznáček na mobilním tlačítku „Filtry"
const activeFilterCount = computed(() => {
  let n = 0
  if (filterCar.value !== '') n++
  if (yearFilter.value !== '') n++
  if (monthFilter.value !== '') n++
  return n
})

const fuelings = ref<Fueling[]>([])
const cars = ref<Car[]>([])
const loading = ref(false)
const filterCar = ref<number | ''>('')

// Filtr rok/měsíc + serverové stránkování (číselný pager — page NAHRAZUJE seznam, vzor StatementList.vue)
const yearFilter = ref<number | ''>('')
const monthFilter = ref<number | ''>('')
const page = ref(1)
const pages = ref(1)
const total = ref(0)
const perPage = 25
const years = ref<number[]>([])

const monthOptions = computed(() => {
  const loc = locale.value === 'en' ? 'en-US' : 'cs-CZ'
  return Array.from({ length: 12 }, (_, i) => new Date(2000, i, 1).toLocaleDateString(loc, { month: 'long' }))
})
// Součet částky — jen z aktuálně zobrazené stránky (počet tankování v patičce bere meta.total);
// autoritativní souhrn za období je v SummariesTab / /logbook/summary.
const totalAmount = computed(() => fuelings.value.reduce((s, f) => s + f.amount_with_vat, 0))
const groups = computed(() => {
  const map = new Map<string, { month: string; rows: Fueling[]; amount: number }>()
  for (const f of fuelings.value) {
    const m = f.fueled_date.slice(0, 7)
    if (!map.has(m)) map.set(m, { month: m, rows: [], amount: 0 })
    const g = map.get(m)!
    g.rows.push(f); g.amount += f.amount_with_vat
  }
  return [...map.values()]
})

watch(yearFilter, (y) => { if (!y) monthFilter.value = '' })
watch([yearFilter, monthFilter, filterCar], () => { reload() })

const filterChips = computed<FilterChip[]>(() => {
  const chips: FilterChip[] = []
  if (filterCar.value !== '') {
    const c = cars.value.find(x => x.id === filterCar.value)
    if (c) chips.push({ key: 'car', label: t('logbook.car'), value: c.registration + (c.name ? ` — ${c.name}` : '') })
  }
  if (yearFilter.value !== '') chips.push({ key: 'year', label: t('common.year'), value: String(yearFilter.value) })
  if (monthFilter.value !== '') chips.push({ key: 'month', label: t('common.month'), value: monthOptions.value[monthFilter.value - 1] })
  return chips
})
function clearFilter(key: string) {
  if (key === 'car') filterCar.value = ''
  if (key === 'year') yearFilter.value = ''
  if (key === 'month') monthFilter.value = ''
}
function resetFilters() {
  filterCar.value = ''
  yearFilter.value = ''
  monthFilter.value = ''
}

async function load() {
  loading.value = true
  try {
    const params: Record<string, string | number> = { page: page.value, per_page: perPage }
    if (filterCar.value) params.car_id = filterCar.value
    if (yearFilter.value) params.year = yearFilter.value
    if (monthFilter.value) params.month = monthFilter.value
    const [fuelingsRes, carsRes] = await Promise.all([
      logbookApi.listFuelings(params),
      cars.value.length ? Promise.resolve(cars.value) : logbookApi.listCars(false),
    ])
    fuelings.value = fuelingsRes.data
    total.value = fuelingsRes.meta.total
    pages.value = fuelingsRes.meta.pages
    years.value = fuelingsRes.years
    cars.value = carsRes
  } finally { loading.value = false; maybeOpenNew() }
}
// Reset na 1. stranu (změna filtru / po uložení). goToPage = navigace v rámci pageru.
function reload() { page.value = 1; load() }
function goToPage(p: number) {
  const np = Math.min(Math.max(1, p), pages.value)
  if (np !== page.value) { page.value = np; load() }
}
onMounted(load)

// Otevření modalu „nové tankování" z rychlé akce (LogbookPage bumpne token).
// Počkáme na dokončení load(), aby bylo auto k dispozici pro předvyplnění jednotky.
const wantNew = ref(false)
watch(() => props.openNewToken, () => { wantNew.value = true; maybeOpenNew() })
function maybeOpenNew() {
  if (!wantNew.value || loading.value) return
  wantNew.value = false
  newFueling()
}

watch(() => props.resetToken, () => { resetFilters() })

// ── Export XLSX / PDF ───────────────────────────────────────────
const exportOpen = ref(false)
const exporting = ref(false)
const _y = new Date().getFullYear()
const exportFrom = ref(`${_y}-01-01`)
const exportTo = ref(`${_y}-12-31`)
const exportCar = ref<number | ''>('')

function openExport() { exportCar.value = filterCar.value; exportOpen.value = true }

async function downloadExport(format: 'xlsx' | 'pdf') {
  exporting.value = true
  try {
    const params: Record<string, string | number> = {}
    if (exportFrom.value) params.date_from = exportFrom.value
    if (exportTo.value) params.date_to = exportTo.value
    if (exportCar.value) params.car_id = exportCar.value
    const r = await logbookApi.exportFuelings(format, params)
    const cd = (r.headers['content-disposition'] as string) || ''
    const m = /filename="?([^"]+)"?/.exec(cd)
    const filename = m ? m[1] : `tankovani.${format}`
    const url = URL.createObjectURL(r.data as Blob)
    const a = document.createElement('a')
    a.href = url
    a.download = filename
    a.click()
    URL.revokeObjectURL(url)
  } catch {
    toast.error(t('logbook.export_failed'))
  } finally { exporting.value = false }
}

// ── Ruční tankování ─────────────────────────────────────────────
const open = ref(false)
const saving = ref(false)
const odometerHint = ref<number | null>(null) // orientační tachometr z knihy jízd
const draft = reactive<FuelingPayload & { id: number }>({
  id: 0, car_id: null, fueled_date: appIsoDate(), fueled_time: '',
  fuel_type: '', quantity: null, unit: 'l', unit_price: null, amount_with_vat: 0, currency: 'CZK',
  odometer: null, station: '', note: '',
})

// Výchozí jednotka dle auta: elektromobil nabíjí v kWh, ostatní tankují v litrech.
function unitForCar(carId: number | null): string {
  const c = cars.value.find(x => x.id === carId)
  return c?.fuel_type === 'electric' ? 'kWh' : 'l'
}

function newFueling() {
  const defCar = cars.value.find(c => c.is_default) ?? (cars.value.length === 1 ? cars.value[0] : null)
  Object.assign(draft, {
    id: 0, car_id: defCar?.id ?? null, fueled_date: appIsoDate(), fueled_time: '',
    fuel_type: '', quantity: null, unit: unitForCar(defCar?.id ?? null), unit_price: null, amount_with_vat: 0, currency: 'CZK',
    odometer: null, station: '', note: '',
  })
  odometerHint.value = null
  open.value = true
}

// U nového záznamu přepni jednotku dle vybraného auta (PHEV i tak může uživatel přepnout ručně).
watch(() => draft.car_id, (cid) => { if (draft.id === 0) draft.unit = unitForCar(cid ?? null) })

function editFueling(f: Fueling) {
  Object.assign(draft, {
    id: f.id, car_id: f.car_id, fueled_date: f.fueled_date, fueled_time: f.fueled_time ?? '',
    fuel_type: f.fuel_type ?? '', quantity: f.quantity, unit: f.unit, unit_price: f.unit_price,
    amount_with_vat: f.amount_with_vat, currency: f.currency, odometer: f.odometer, station: f.station ?? '', note: f.note ?? '',
  })
  odometerHint.value = f.odometer_estimated ?? null
  open.value = true
}

async function save() {
  if (blockDemoMutation()) return
  if (Number(draft.amount_with_vat) <= 0) { toast.error(t('logbook.amount_required')); return }
  saving.value = true
  try {
    const payload: FuelingPayload = {
      car_id: draft.car_id ? Number(draft.car_id) : null, fueled_date: draft.fueled_date,
      fueled_time: draft.fueled_time || null, fuel_type: draft.fuel_type || null,
      quantity: draft.quantity != null && draft.quantity !== ('' as any) ? Number(draft.quantity) : null,
      unit: draft.unit || 'l',
      unit_price: draft.unit_price != null && draft.unit_price !== ('' as any) ? Number(draft.unit_price) : null,
      amount_with_vat: Number(draft.amount_with_vat), currency: draft.currency || 'CZK',
      odometer: draft.odometer != null && draft.odometer !== ('' as any) ? Number(draft.odometer) : null,
      station: draft.station || null, note: draft.note || null,
    }
    const wasNew = !draft.id
    if (draft.id) await logbookApi.updateFueling(draft.id, payload)
    else await logbookApi.createFueling(payload)
    open.value = false
    toast.success(t('common.saved'))
    if (wasNew) page.value = 1 // nové tankování je nejnovější → skoč na 1. stranu (řazení date DESC)
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message ?? t('common.error'))
  } finally { saving.value = false }
}

async function removeFueling(f: Fueling) {
  if (!confirm(t('logbook.confirm_delete_fueling'))) return
  try {
    await logbookApi.deleteFueling(f.id)
    toast.success(t('common.deleted'))
    await load()
    // Smazání poslední položky na poslední straně → posuň se o stranu zpět.
    if (fuelings.value.length === 0 && page.value > 1) goToPage(page.value - 1)
  } catch (e: any) { toast.error(e?.response?.data?.error?.message ?? t('common.error')) }
}

// ── Načíst z faktur (benzínky) ──────────────────────────────────
const invOpen = ref(false)
const invLoading = ref(false)
const invoices = ref<FuelInvoice[]>([])
const invCars = ref<Car[]>([])
const hasCars = ref(false)
const assignCar = reactive<Record<number, number | ''>>({})
const assigning = ref<number | null>(null)
const backfilling = ref(false)
const expandedInvoice = ref<number | null>(null)
const invItems = reactive<Record<number, FuelInvoiceItem[]>>({})
const invItemsLoading = ref<number | null>(null)

async function toggleItems(inv: FuelInvoice) {
  if (expandedInvoice.value === inv.id) { expandedInvoice.value = null; return }
  expandedInvoice.value = inv.id
  if (!invItems[inv.id]) {
    invItemsLoading.value = inv.id
    try { invItems[inv.id] = (await logbookApi.fuelInvoiceItems(inv.id)).items }
    catch { invItems[inv.id] = [] }
    finally { invItemsLoading.value = null }
  }
}

async function loadInvoices() {
  invLoading.value = true
  try {
    const data = await logbookApi.listFuelInvoices()
    invoices.value = data.invoices
    invCars.value = data.cars
    hasCars.value = data.has_cars
    const def = data.cars.find(c => c.is_default) ?? (data.cars.length === 1 ? data.cars[0] : null)
    for (const inv of data.invoices) if (!(inv.id in assignCar)) assignCar[inv.id] = def?.id ?? ''
  } finally { invLoading.value = false }
}

function openInvoices() { invOpen.value = true; loadInvoices() }

async function assign(inv: FuelInvoice) {
  assigning.value = inv.id
  try {
    const carId = assignCar[inv.id] ? Number(assignCar[inv.id]) : null
    const r = await logbookApi.assignFuelInvoice(inv.id, carId)
    if (r.status === 'failed') toast.error(t('logbook.scan_failed'))
    else if (r.created === 0 && (r.updated ?? 0) > 0) toast.success(t('logbook.scan_updated', { n: r.updated }))
    else toast.success(t('logbook.scan_done', { n: r.created, parser: r.parser }))
    await loadInvoices()
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message ?? t('common.error'))
  } finally { assigning.value = null }
}

async function backfillHistory() {
  backfilling.value = true
  let totalCreated = 0
  let totalUpdated = 0
  try {
    // Opakuj dokud zbývají nevytěžené / nedoplněné faktury (dávkově po 25).
    for (let guard = 0; guard < 200; guard++) {
      const r = await logbookApi.backfillFuelInvoices(25)
      totalCreated += r.created
      totalUpdated += r.updated ?? 0
      if (r.remaining <= 0 || r.processed === 0) break
    }
    toast.success(t('logbook.backfill_done', { n: totalCreated })
      + (totalUpdated > 0 ? ' ' + t('logbook.backfill_updated', { n: totalUpdated }) : ''))
    await loadInvoices()
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message ?? t('common.error'))
  } finally { backfilling.value = false }
}

function fmtMoney(n: number, ccy: string): string {
  return formatMoney(n, ccy)
}
// Pouze tokeny remapované v dark módu (neutral/primary/amber/purple) — raw barvy (sky/violet) v dark svítí.
const sourceBadge: Record<string, string> = {
  manual: 'bg-neutral-100 text-neutral-600', import: 'bg-neutral-100 text-neutral-600',
  invoice: 'bg-primary-50 text-primary-700', axigon: 'bg-purple-50 text-purple-700', axigon_ai: 'bg-amber-50 text-amber-700',
}
</script>

<template>
  <section>
    <FilterBar :active-count="activeFilterCount" :chips="filterChips" @clear="clearFilter" @clear-all="resetFilters">
        <select v-model="filterCar" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="">{{ t('logbook.all_cars') }}</option>
          <option v-for="c in cars" :key="c.id" :value="c.id">{{ c.registration }}{{ c.name ? ` — ${c.name}` : '' }}</option>
        </select>
        <select v-model="yearFilter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option :value="''">{{ t('logbook.all_years') }}</option>
          <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
        </select>
        <select v-model="monthFilter" :disabled="yearFilter === ''" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm disabled:opacity-50">
          <option :value="''">{{ t('logbook.all_months') }}</option>
          <option v-for="(label, i) in monthOptions" :key="i + 1" :value="i + 1">{{ label }}</option>
        </select>
        <button @click="openExport" :disabled="total === 0"
          :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('logbook.export') }}
        </button>
        <button v-if="auth.canWrite('logbook.write')" @click="openInvoices"
          :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
          {{ t('logbook.from_invoices') }}
        </button>
      <template #actions>
        <button v-if="auth.canWrite('logbook.write') || auth.isDemo" @click="newFueling" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('logbook.fueling_new') }}
        </button>
      </template>
    </FilterBar>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="total === 0" icon="coin" :title="t('logbook.no_fuelings')" dense boxed />

    <template v-else>
      <div class="text-xs text-neutral-500 mb-3">{{ t('logbook.fuelings_summary', { count: total, amount: fmtMoney(totalAmount, 'CZK') }) }}</div>

      <section v-for="g in groups" :key="g.month" class="mb-5">
        <header class="flex items-center justify-between bg-neutral-50 border border-neutral-200 rounded-t-lg px-4 py-2.5">
          <div class="flex items-center gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ formatMonth(g.month) }}</h2>
            <span class="text-xs text-neutral-500">{{ g.rows.length }}</span>
          </div>
          <span class="text-xs font-mono font-semibold text-neutral-700">{{ fmtMoney(g.amount, 'CZK') }}</span>
        </header>

        <!-- Desktop -->
        <div class="hidden md:block bg-surface border border-t-0 border-neutral-200 rounded-b-lg overflow-hidden">
          <table class="w-full text-sm table-fixed">
            <colgroup>
              <col class="w-32" /><col class="w-20" /><col /><col class="w-20" /><col class="w-28" /><col /><col class="w-52" />
            </colgroup>
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('logbook.date') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('logbook.car') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('logbook.fuel') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('logbook.quantity') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('logbook.amount') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('logbook.station') }}</th>
                <th class="px-3 py-2 w-px"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="f in g.rows" :key="f.id" class="hover:bg-neutral-50">
                <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(f.fueled_date) }}<span v-if="f.fueled_time" class="block text-xs text-neutral-400">{{ f.fueled_time }}</span></td>
                <td class="px-3 py-2 font-mono text-xs">
                  <span v-if="f.car_registration">{{ f.car_registration }}</span>
                  <span v-else class="text-warning-600">{{ t('logbook.no_car') }}</span>
                </td>
                <td class="px-3 py-2">
                  {{ f.fuel_type || '—' }}
                  <span class="ml-1 text-xs px-1.5 py-0.5 rounded" :class="sourceBadge[f.source] || 'bg-neutral-100 text-neutral-600'">{{ t(`logbook.source.${f.source}`) }}</span>
                  <router-link v-if="f.source_purchase_invoice_id" :to="`/purchase-invoices/${f.source_purchase_invoice_id}`"
                    class="ml-1 text-xs text-primary-600 hover:text-primary-700 hover:underline" :title="t('logbook.open_invoice')">
                    {{ f.source_invoice_number ? `č. ${f.source_invoice_number}` : t('logbook.invoice_link') }} ↗
                  </router-link>
                </td>
                <td class="px-3 py-2 text-right font-mono">{{ f.quantity != null ? `${f.quantity.toLocaleString('cs-CZ')} ${f.unit}` : '—' }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ fmtMoney(f.amount_with_vat, f.currency) }}</td>
                <td class="px-3 py-2 text-xs text-neutral-500 truncate max-w-[12rem]">{{ f.station || f.vendor_name || '—' }}</td>
                <td class="px-3 py-2">
                  <div v-if="auth.canWrite('logbook.write')" class="flex justify-end gap-1.5">
                    <button @click="editFueling(f)" class="cursor-pointer inline-flex items-center gap-1 h-7 px-2 text-xs border border-neutral-300 rounded-md hover:bg-neutral-50">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828z"/></svg>
                      {{ t('common.edit') }}
                    </button>
                    <button @click="removeFueling(f)" class="cursor-pointer inline-flex items-center gap-1 h-7 px-2 text-xs border border-neutral-300 text-danger-600 rounded-md hover:bg-danger-50">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v3M4 7h16"/></svg>
                      {{ t('common.delete') }}
                    </button>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Mobile karty -->
        <div class="md:hidden bg-surface border border-t-0 border-neutral-200 rounded-b-lg divide-y divide-neutral-100 overflow-hidden">
          <div v-for="f in g.rows" :key="`m-${f.id}`" class="px-4 py-3">
            <div class="flex items-baseline justify-between gap-2">
              <span class="font-medium text-neutral-900">{{ formatDate(f.fueled_date) }}<span v-if="f.fueled_time" class="text-neutral-400 text-xs ml-1">{{ f.fueled_time }}</span></span>
              <span class="font-mono text-sm">{{ fmtMoney(f.amount_with_vat, f.currency) }}</span>
            </div>
            <div class="flex items-baseline justify-between gap-2 mt-0.5 text-sm text-neutral-700">
              <span class="truncate">{{ f.fuel_type || '—' }}<span v-if="f.quantity != null" class="text-neutral-400"> · {{ f.quantity.toLocaleString('cs-CZ') }} {{ f.unit }}</span></span>
              <span class="text-xs px-1.5 py-0.5 rounded shrink-0" :class="sourceBadge[f.source] || 'bg-neutral-100 text-neutral-600'">{{ t(`logbook.source.${f.source}`) }}</span>
            </div>
            <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
              <span class="truncate">{{ f.station || f.vendor_name || '—' }}</span>
              <span class="font-mono shrink-0">{{ f.car_registration || t('logbook.no_car') }}</span>
            </div>
            <router-link v-if="f.source_purchase_invoice_id" :to="`/purchase-invoices/${f.source_purchase_invoice_id}`"
              class="inline-block mt-1 text-xs text-primary-600 hover:underline">
              {{ f.source_invoice_number ? `Doklad č. ${f.source_invoice_number}` : t('logbook.invoice_link') }} ↗
            </router-link>
            <div v-if="auth.canWrite('logbook.write')" class="flex gap-2 mt-2">
              <button @click="editFueling(f)" class="cursor-pointer inline-flex items-center gap-1 h-7 px-2 text-xs border border-neutral-300 rounded-md hover:bg-neutral-50">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828z"/></svg>
                {{ t('common.edit') }}
              </button>
              <button @click="removeFueling(f)" class="cursor-pointer inline-flex items-center gap-1 h-7 px-2 text-xs border border-neutral-300 text-danger-600 rounded-md hover:bg-danger-50 ml-auto">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 0 0-1-1h-4a1 1 0 0 0-1 1v3M4 7h16"/></svg>
                {{ t('common.delete') }}
              </button>
            </div>
          </div>
        </div>
      </section>

      <!-- Číselný pager (serverové stránkování — page nahrazuje seznam) -->
      <div v-if="pages > 1" class="flex items-center justify-center gap-3 mt-4">
        <button @click="goToPage(page - 1)" :disabled="page <= 1" class="cursor-pointer h-9 px-3 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 inline-flex items-center gap-1">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/></svg>
        </button>
        <span class="text-sm text-neutral-600">{{ t('logbook.page_of', { page, pages }) }}</span>
        <button @click="goToPage(page + 1)" :disabled="page >= pages" class="cursor-pointer h-9 px-3 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 inline-flex items-center gap-1">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/></svg>
        </button>
      </div>
    </template>

    <!-- Modal: manual fueling -->
    <div v-if="open" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <form @submit.prevent="save" class="p-5 space-y-4">
          <h2 class="text-lg font-semibold">{{ draft.id ? t('logbook.fueling_edit') : t('logbook.fueling_new') }}</h2>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.car') }}</label>
              <select v-model="draft.car_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option :value="null">{{ t('logbook.no_assignment') }}</option>
                <option v-for="c in cars" :key="c.id" :value="c.id">{{ c.registration }}{{ c.name ? ` — ${c.name}` : '' }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.date') }} *</label>
              <DateInput v-model="draft.fueled_date" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.time') }}</label>
              <input v-model="draft.fueled_time" type="time" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ draft.unit === 'kWh' ? t('logbook.charge_desc') : t('logbook.fuel_desc') }}</label>
              <input v-model="draft.fuel_type" type="text" maxlength="60" :placeholder="draft.unit === 'kWh' ? t('logbook.charge_desc_placeholder') : t('logbook.fuel_desc_placeholder')" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.quantity') }}</label>
              <div class="flex gap-2">
                <input v-model.number="draft.quantity" type="number" min="0" step="0.001" class="flex-1 min-w-0 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
                <select v-model="draft.unit" class="h-10 px-2 w-24 border border-neutral-300 rounded-md bg-surface text-sm">
                  <option value="l">l</option>
                  <option value="kWh">kWh</option>
                </select>
              </div>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.odometer') }}</label>
              <input v-model.number="draft.odometer" type="number" min="0"
                :placeholder="odometerHint != null ? `≈ ${odometerHint.toLocaleString('cs-CZ')}` : ''"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              <p v-if="draft.odometer == null && odometerHint != null" class="text-xs text-neutral-400 mt-0.5">{{ t('logbook.odometer_estimate_hint') }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.amount') }} *</label>
              <input v-model.number="draft.amount_with_vat" type="number" min="0" step="0.01" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div class="col-span-2">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.station') }}</label>
              <input v-model="draft.station" type="text" maxlength="150" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <div class="flex justify-end gap-2 pt-2">
            <button type="button" @click="open = false" class="cursor-pointer h-9 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 inline-flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
              {{ t('common.cancel') }}
            </button>
            <button type="submit" :disabled="saving" class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md disabled:opacity-50 inline-flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
              {{ t('common.save') }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Modal: export -->
    <div v-if="exportOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto p-5 space-y-4">
        <h2 class="text-lg font-semibold">{{ t('logbook.export_fuel_title') }}</h2>
        <p class="text-sm text-neutral-500">{{ t('logbook.export_fuel_hint') }}</p>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.car') }}</label>
          <select v-model="exportCar" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option :value="''">{{ t('logbook.all_cars') }}</option>
            <option v-for="c in cars" :key="c.id" :value="c.id">{{ c.registration }}{{ c.name ? ` — ${c.name}` : '' }}</option>
          </select>
        </div>
        <div class="grid grid-cols-2 gap-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.date_from') }}</label>
            <DateInput v-model="exportFrom" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.date_to') }}</label>
            <DateInput v-model="exportTo" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
        </div>
        <div class="flex flex-wrap justify-end gap-2 pt-2 border-t border-neutral-100">
          <button @click="exportOpen = false" class="cursor-pointer h-9 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 inline-flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            {{ t('common.cancel') }}
          </button>
          <button @click="downloadExport('xlsx')" :disabled="exporting"
            class="cursor-pointer h-9 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50 inline-flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M12 16V4m0 12l-4-4m4 4l4-4"/></svg>
            XLSX
          </button>
          <button @click="downloadExport('pdf')" :disabled="exporting"
            class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white rounded-md disabled:opacity-50 inline-flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M12 16V4m0 12l-4-4m4 4l4-4"/></svg>
            PDF
          </button>
        </div>
      </div>
    </div>

    <!-- Modal: fuel invoices -->
    <div v-if="invOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-3xl max-h-[90vh] overflow-y-auto p-5 space-y-4">
        <div class="flex items-center justify-between gap-2">
          <h2 class="text-lg font-semibold">{{ t('logbook.from_invoices_title') }}</h2>
          <div class="flex items-center gap-2">
            <button @click="backfillHistory" :disabled="backfilling || invoices.length === 0"
              class="cursor-pointer h-9 px-3 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-50 inline-flex items-center gap-1.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4v5h5M20 20v-5h-5M4 9a8 8 0 0 1 14-3M20 15a8 8 0 0 1-14 3"/></svg>
              {{ backfilling ? t('logbook.backfill_running') : t('logbook.backfill') }}
            </button>
            <button @click="invOpen = false" :title="t('common.close')" :aria-label="t('common.close')"
              class="cursor-pointer h-9 w-9 shrink-0 flex items-center justify-center text-neutral-500 hover:text-neutral-700 hover:bg-neutral-100 rounded-md">
              <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
          </div>
        </div>
        <p class="text-sm text-neutral-500">{{ t('logbook.from_invoices_hint') }}</p>
        <div class="flex items-start gap-2 text-xs text-warning-700 bg-warning-50 border border-warning-500/40 rounded-md px-3 py-2">
          <svg class="w-4 h-4 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.29 3.86l-8.48 14.7A1 1 0 0 0 2.67 20h18.66a1 1 0 0 0 .86-1.5l-8.48-14.7a1 1 0 0 0-1.74 0z"/></svg>
          <span>{{ t('logbook.ai_notice') }}</span>
        </div>

        <div v-if="invLoading" class="text-center text-neutral-500 py-8 text-sm">{{ t('common.loading') }}</div>
        <EmptyState v-else-if="invoices.length === 0" icon="doc" :title="t('logbook.no_fuel_invoices')" dense />

        <div v-else class="divide-y divide-neutral-100 border border-neutral-200 rounded-md">
          <div v-for="inv in invoices" :key="inv.id">
            <div class="px-3 py-2.5 flex flex-wrap items-center gap-2 text-sm" :class="inv.scanned ? 'bg-success-50/40' : ''">
              <div class="min-w-0 flex-1">
                <div class="font-medium text-neutral-900 truncate flex items-center gap-2">
                  <span class="truncate">{{ inv.vendor_name }}</span>
                  <span v-if="inv.scanned" class="shrink-0 text-xs px-1.5 py-0.5 rounded bg-success-50 text-success-600">{{ t('logbook.scanned_badge', { n: inv.fuelings_count }) }}</span>
                  <span v-else class="shrink-0 text-xs px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-600">{{ t('logbook.new_badge') }}</span>
                </div>
                <div class="text-xs text-neutral-500">{{ formatDate(inv.issue_date) }} · {{ fmtMoney(inv.total_with_vat, inv.currency) }}
                  <span v-if="!inv.has_pdf" class="ml-1 text-warning-600">· {{ t('logbook.no_pdf') }}</span>
                </div>
              </div>
              <button @click="toggleItems(inv)"
                class="cursor-pointer h-9 px-2.5 text-xs border border-neutral-300 rounded-md hover:bg-neutral-50 inline-flex items-center gap-1">
                <svg class="w-3.5 h-3.5 transition-transform" :class="expandedInvoice === inv.id ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7"/></svg>
                {{ t('logbook.detail') }}
              </button>
              <select v-model="assignCar[inv.id]" class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
                <option value="">{{ t('logbook.no_assignment') }}</option>
                <option v-for="c in invCars" :key="c.id" :value="c.id">{{ c.registration }}</option>
              </select>
              <button @click="assign(inv)" :disabled="assigning === inv.id"
                class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm rounded-md disabled:opacity-50">
                {{ assigning === inv.id ? t('common.loading') : (inv.scanned ? t('logbook.reassign') : t('logbook.recognize')) }}
              </button>
            </div>

            <!-- Detail položek faktury -->
            <div v-if="expandedInvoice === inv.id" class="px-3 pb-3 bg-neutral-50/60 border-t border-neutral-100">
              <div v-if="invItemsLoading === inv.id" class="text-xs text-neutral-500 py-2">{{ t('common.loading') }}</div>
              <table v-else-if="invItems[inv.id] && invItems[inv.id].length" class="w-full text-xs mt-2">
                <thead class="text-neutral-500">
                  <tr>
                    <th class="text-left font-medium py-1">{{ t('logbook.fuel_desc') }}</th>
                    <th class="text-right font-medium">{{ t('logbook.quantity') }}</th>
                    <th class="text-right font-medium">{{ t('logbook.amount') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(it, i) in invItems[inv.id]" :key="i" :class="it.is_fuel ? 'text-neutral-800' : 'text-neutral-400'">
                    <td class="py-0.5">
                      <span v-if="it.is_fuel" class="text-success-600 mr-1" :title="t('logbook.is_fuel_item')">●</span>{{ it.description }}
                    </td>
                    <td class="text-right whitespace-nowrap">{{ it.quantity != null ? `${it.quantity.toLocaleString('cs-CZ')} ${it.unit}` : '—' }}</td>
                    <td class="text-right font-mono whitespace-nowrap">{{ it.total_with_vat != null ? fmtMoney(it.total_with_vat, inv.currency) : '—' }}</td>
                  </tr>
                </tbody>
              </table>
              <EmptyState v-else icon="inbox" :title="t('logbook.no_items')" dense />
              <div class="mt-2 text-right">
                <a :href="`/purchase-invoices/${inv.id}`" target="_blank" rel="noopener"
                  class="text-xs text-primary-600 hover:text-primary-700 hover:underline inline-flex items-center gap-1">
                  {{ t('logbook.open_invoice') }}
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>
</template>
