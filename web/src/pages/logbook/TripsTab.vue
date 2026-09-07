<script setup lang="ts">
import { ref, reactive, onMounted, computed, watch, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMonth } from '@/composables/useFormat'
import {
  logbookApi, type Car, type Trip, type TripPayload,
  type TripCategory, type TripImportReport,
} from '@/api/logbook'
import { useAuthStore } from '@/stores/auth'
import FilterBar, { type FilterChip } from '@/components/ui/FilterBar.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import { appIsoDate } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'

const { t, locale } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const pageId = useId()
const props = defineProps<{ resetToken?: number; openNewToken?: number }>()

// Počet aktivních filtrů pro odznáček na mobilním tlačítku „Filtry"
const activeFilterCount = computed(() => {
  let n = 0
  if (filterCar.value !== '') n++
  if (yearFilter.value !== '') n++
  if (monthFilter.value !== '') n++
  return n
})

const trips = ref<Trip[]>([])
const cars = ref<Car[]>([])
const categories = ref<TripCategory[]>([])
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
const purposes = ref<string[]>([])
const places = ref<string[]>([])

const monthOptions = computed(() => {
  const loc = locale.value === 'en' ? 'en-US' : 'cs-CZ'
  return Array.from({ length: 12 }, (_, i) => new Date(2000, i, 1).toLocaleDateString(loc, { month: 'long' }))
})

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
// Součet ujetých km — jen z aktuálně zobrazené stránky (počet jízd v patičce bere meta.total);
// autoritativní součet km za období je v SummariesTab / /logbook/summary.
const totalKm = computed(() => trips.value.reduce((s, tr) => s + tr.distance_km, 0))
const groups = computed(() => {
  const map = new Map<string, { month: string; trips: Trip[]; km: number }>()
  for (const tr of trips.value) {
    const m = tr.trip_date.slice(0, 7)
    if (!map.has(m)) map.set(m, { month: m, trips: [], km: 0 })
    const g = map.get(m)!
    g.trips.push(tr); g.km += tr.distance_km
  }
  return [...map.values()]
})

watch(yearFilter, (y) => { if (!y) monthFilter.value = '' })
watch([yearFilter, monthFilter, filterCar], () => { reload() })

const open = ref(false)
const saving = ref(false)
const draft = reactive<TripPayload & { id: number }>({
  id: 0, car_id: 0, trip_date: appIsoDate(), time_start: '', time_end: '',
  odometer_start: null, odometer_end: null, distance_km: null, category_id: null,
  purpose: '', origin: '', destination: '', note: '',
})

const computedDistance = computed(() => {
  const s = draft.odometer_start, e = draft.odometer_end
  if (s != null && e != null && Number(e) >= Number(s)) return Number(e) - Number(s)
  return null
})

async function load() {
  loading.value = true
  try {
    const params: Record<string, string | number> = { page: page.value, per_page: perPage }
    if (filterCar.value) params.car_id = filterCar.value
    if (yearFilter.value) params.year = yearFilter.value
    if (monthFilter.value) params.month = monthFilter.value
    const [tripsRes, carsRes, categoriesRes] = await Promise.all([
      logbookApi.listTrips(params),
      logbookApi.listCars(false), // vždy fresh kvůli last_odometer
      categories.value.length ? Promise.resolve(categories.value) : logbookApi.listCategories(false),
    ])
    trips.value = tripsRes.data
    total.value = tripsRes.meta.total
    pages.value = tripsRes.meta.pages
    years.value = tripsRes.years
    cars.value = carsRes
    categories.value = categoriesRes
    logbookApi.tripPurposes().then(p => { purposes.value = p }).catch(() => {})
    logbookApi.tripPlaces().then(p => { places.value = p }).catch(() => {})
  } finally { loading.value = false; maybeOpenNew() }
}
// Reset na 1. stranu (změna filtru / po uložení). goToPage = navigace v rámci pageru.
function reload() { page.value = 1; load() }
function goToPage(p: number) {
  const np = Math.min(Math.max(1, p), pages.value)
  if (np !== page.value) { page.value = np; load() }
}
onMounted(load)

// Otevření modalu „nová jízda" z rychlé akce / „+" v menu (LogbookPage bumpne token).
// Čekáme na dokončení load(), aby byl seznam aut k dispozici pro předvyplnění.
const wantNew = ref(false)
watch(() => props.openNewToken, () => { wantNew.value = true; maybeOpenNew() })
function maybeOpenNew() {
  if (!wantNew.value || loading.value) return
  wantNew.value = false
  if (cars.value.length) newTrip()
  else toast.error(t('logbook.no_cars_hint'))
}

// Předvyplnění tachometru zahájení posledním známým konečným stavem auta (jen nový záznam).
watch(() => draft.car_id, (carId) => {
  if (draft.id) return
  const car = cars.value.find(c => c.id === Number(carId))
  if (car) draft.odometer_start = car.last_odometer ?? null
})

// Provázání tachometr ↔ ujeto obousměrně: pole, které uživatel naposledy edituje
// (konec / ujeto), dopočítá to druhé ze začátku. Bez smyčky — dopočet je programový
// (nespouští @input), a změnu začátku řešíme zvlášť watchem.
function numOrNull(v: unknown): number | null {
  return v != null && (v as string) !== '' && !Number.isNaN(Number(v)) ? Number(v) : null
}
// e (z @input) má aktuálně zadanou hodnotu — čteme ji přímo, ať nezáleží na pořadí
// vůči v-model handleru (jinak by se dopočet opožďoval o znak). Bez e (z watche) čteme draft.
function recomputeEndFromDistance(e?: Event) {
  const start = numOrNull(draft.odometer_start)
  const dist = numOrNull(e ? (e.target as HTMLInputElement).value : draft.distance_km)
  if (start !== null && dist !== null && dist > 0) draft.odometer_end = start + Math.round(dist)
}
function recomputeDistanceFromEnd(e?: Event) {
  const start = numOrNull(draft.odometer_start)
  const end = numOrNull(e ? (e.target as HTMLInputElement).value : draft.odometer_end)
  if (start !== null && end !== null && end >= start) draft.distance_km = end - start
}
// Změna začátku (předvyplnění / přepnutí auta): dopočítej dle toho, co je vyplněné.
watch(() => draft.odometer_start, () => {
  if (numOrNull(draft.distance_km) !== null) recomputeEndFromDistance()
  else if (numOrNull(draft.odometer_end) !== null) recomputeDistanceFromEnd()
})

// Reset filtrů na default při kliku na menu (LogbookPage bumpne resetToken).
watch(() => props.resetToken, () => { resetFilters() })

function newTrip() {
  const defCar = cars.value.find(c => c.is_default) ?? cars.value[0]
  Object.assign(draft, {
    id: 0, car_id: defCar?.id ?? 0, trip_date: appIsoDate(),
    time_start: '', time_end: '', odometer_start: defCar?.last_odometer ?? null, odometer_end: null, distance_km: null,
    category_id: (categories.value.find(c => c.is_default) ?? categories.value[0])?.id ?? null, purpose: '', origin: '', destination: '', note: '',
  })
  open.value = true
}

function editTrip(tr: Trip) {
  Object.assign(draft, {
    id: tr.id, car_id: tr.car_id, trip_date: tr.trip_date, time_start: tr.time_start ?? '', time_end: tr.time_end ?? '',
    odometer_start: tr.odometer_start, odometer_end: tr.odometer_end, distance_km: tr.distance_km,
    category_id: tr.category_id, purpose: tr.purpose ?? '', origin: tr.origin ?? '', destination: tr.destination ?? '', note: tr.note ?? '',
  })
  open.value = true
}

async function save() {
  if (!draft.car_id) { toast.error(t('logbook.trip_car_required')); return }
  saving.value = true
  try {
    const payload: TripPayload = {
      car_id: Number(draft.car_id), trip_date: draft.trip_date,
      time_start: draft.time_start || null, time_end: draft.time_end || null,
      odometer_start: draft.odometer_start != null && draft.odometer_start !== ('' as any) ? Number(draft.odometer_start) : null,
      odometer_end: draft.odometer_end != null && draft.odometer_end !== ('' as any) ? Number(draft.odometer_end) : null,
      distance_km: draft.distance_km != null && draft.distance_km !== ('' as any) ? Number(draft.distance_km) : null,
      category_id: draft.category_id ? Number(draft.category_id) : null,
      purpose: draft.purpose || null, origin: draft.origin || null, destination: draft.destination || null, note: draft.note || null,
    }
    const wasNew = !draft.id
    if (draft.id) await logbookApi.updateTrip(draft.id, payload)
    else await logbookApi.createTrip(payload)
    open.value = false
    toast.success(t('common.saved'))
    if (wasNew) page.value = 1 // nová jízda je nejnovější → skoč na 1. stranu (řazení date DESC)
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message ?? t('common.error'))
  } finally { saving.value = false }
}

async function removeTrip(tr: Trip) {
  if (!confirm(t('logbook.confirm_delete_trip'))) return
  try {
    await logbookApi.deleteTrip(tr.id)
    toast.success(t('common.deleted'))
    await load()
    // Smazání poslední položky na poslední straně → posuň se o stranu zpět.
    if (trips.value.length === 0 && page.value > 1) goToPage(page.value - 1)
  } catch (e: any) { toast.error(e?.response?.data?.error?.message ?? t('common.error')) }
}

// ── Import CSV/XLSX ─────────────────────────────────────────────
const importOpen = ref(false)
const importing = ref(false)
const importReport = ref<TripImportReport | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

async function onImportFile(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  importing.value = true
  importReport.value = null
  try {
    importReport.value = await logbookApi.importTrips(file)
    if (importReport.value.created > 0) reload()
    toast.success(t('logbook.import_done', { n: importReport.value.created }))
  } catch (err: any) {
    toast.error(err?.response?.data?.error?.message ?? t('logbook.import_failed'))
  } finally {
    importing.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

function downloadTemplate() {
  const header = 'datum;cas;auto;km_zacatek;km_konec;ujeto;ucel;odkud;kam;kategorie'
  const example = '01.06.2026;08:30;;100000;100150;;Schůzka s klientem;Praha;Brno;business'
  const blob = new Blob(['﻿' + header + '\n' + example + '\n'], { type: 'text/csv;charset=utf-8' })
  const a = document.createElement('a')
  a.href = URL.createObjectURL(blob)
  a.download = 'kniha-jizd-vzor.csv'
  a.click()
  URL.revokeObjectURL(a.href)
}

// ── Export XLSX / PDF ───────────────────────────────────────────
const exportOpen = ref(false)
const exporting = ref(false)
const _year = new Date().getFullYear()
const exportFrom = ref(`${_year}-01-01`)
const exportTo = ref(`${_year}-12-31`)
const exportCar = ref<number | ''>('')

function openExport() { exportCar.value = filterCar.value; exportOpen.value = true }

async function downloadExport(format: 'xlsx' | 'pdf') {
  exporting.value = true
  try {
    const params: Record<string, string | number> = {}
    if (exportFrom.value) params.date_from = exportFrom.value
    if (exportTo.value) params.date_to = exportTo.value
    if (exportCar.value) params.car_id = exportCar.value
    const r = await logbookApi.exportTrips(format, params)
    const cd = (r.headers['content-disposition'] as string) || ''
    const m = /filename="?([^"]+)"?/.exec(cd)
    const filename = m ? m[1] : `kniha-jizd.${format}`
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

function fmtKm(n: number | null): string { return n == null ? '—' : n.toLocaleString('cs-CZ', { maximumFractionDigits: 1 }) }
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
        <button v-if="auth.canWrite('logbook.write')" @click="importOpen = true; importReport = null"
          :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" /></svg>
          {{ t('logbook.import') }}
        </button>
      <template #actions>
        <button v-if="auth.canWrite('logbook.write')" @click="newTrip" :disabled="cars.length === 0"
          :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('logbook.trip_new') }}
        </button>
      </template>
    </FilterBar>

    <EmptyState v-if="cars.length === 0 && !loading" icon="box" :title="t('logbook.no_cars_hint')" dense boxed />
    <div v-else-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="total === 0" icon="cycle" :title="t('logbook.no_trips')" dense boxed />

    <template v-else>
      <div class="text-xs text-neutral-500 mb-3">{{ t('logbook.trips_summary', { count: total, km: fmtKm(totalKm) }) }}</div>

      <section v-for="g in groups" :key="g.month" class="mb-5">
        <header class="flex items-center justify-between bg-neutral-50 border border-neutral-200 rounded-t-lg px-4 py-2.5">
          <div class="flex items-center gap-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-neutral-700">{{ formatMonth(g.month) }}</h2>
            <span class="text-xs text-neutral-500">{{ g.trips.length }}</span>
          </div>
          <span class="text-xs font-mono font-semibold text-neutral-700">{{ fmtKm(g.km) }} km</span>
        </header>

        <!-- Desktop -->
        <div class="hidden md:block bg-surface border border-t-0 border-neutral-200 rounded-b-lg overflow-hidden">
          <table class="w-full text-sm table-fixed">
            <colgroup>
              <col class="w-32" /><col class="w-20" /><col /><col /><col class="w-24" /><col class="w-20" /><col class="w-20" /><col class="w-52" />
            </colgroup>
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('logbook.date') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('logbook.car') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('logbook.route') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('logbook.purpose') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('logbook.category') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('logbook.odometer_start_short') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('logbook.distance_km') }}</th>
                <th class="px-3 py-2 w-px"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="tr in g.trips" :key="tr.id" class="hover:bg-neutral-50">
                <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(tr.trip_date) }}<span v-if="tr.time_start" class="block text-xs text-neutral-400">{{ tr.time_start }}</span></td>
                <td class="px-3 py-2 font-mono text-xs">{{ tr.car_registration }}</td>
                <td class="px-3 py-2">{{ [tr.origin, tr.destination].filter(Boolean).join(' → ') || '—' }}</td>
                <td class="px-3 py-2">{{ tr.purpose || '—' }}</td>
                <td class="px-3 py-2">
                  <span v-if="tr.category_label" class="text-xs px-1.5 py-0.5 rounded" :class="tr.category_is_private ? 'bg-warning-50 text-warning-600' : 'bg-success-50 text-success-600'">{{ tr.category_label }}</span>
                  <span v-else class="text-neutral-400">—</span>
                </td>
                <td class="px-3 py-2 text-right font-mono">{{ fmtKm(tr.odometer_start) }}</td>
                <td class="px-3 py-2 text-right font-mono">{{ fmtKm(tr.distance_km) }}</td>
                <td class="px-3 py-2">
                  <div v-if="auth.canWrite('logbook.write')" class="flex justify-end gap-1.5">
                    <button @click="editTrip(tr)" class="cursor-pointer inline-flex items-center gap-1 h-7 px-2 text-xs border border-neutral-300 rounded-md hover:bg-neutral-50">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828z"/></svg>
                      {{ t('common.edit') }}
                    </button>
                    <button @click="removeTrip(tr)" class="cursor-pointer inline-flex items-center gap-1 h-7 px-2 text-xs border border-neutral-300 text-danger-600 rounded-md hover:bg-danger-50">
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
          <div v-for="tr in g.trips" :key="`m-${tr.id}`" class="px-4 py-3">
            <div class="flex items-baseline justify-between gap-2">
              <span class="font-medium text-neutral-900">{{ formatDate(tr.trip_date) }}<span v-if="tr.time_start" class="text-neutral-400 text-xs ml-1">{{ tr.time_start }}</span></span>
              <span class="font-mono text-sm">{{ fmtKm(tr.distance_km) }} km</span>
            </div>
            <div class="text-sm text-neutral-700 mt-0.5">{{ [tr.origin, tr.destination].filter(Boolean).join(' → ') || '—' }}</div>
            <div class="flex items-baseline justify-between gap-2 mt-1 text-xs text-neutral-500">
              <span class="truncate">{{ tr.purpose || '—' }} · {{ tr.car_registration }}</span>
              <span v-if="tr.category_label" class="shrink-0 px-1.5 py-0.5 rounded" :class="tr.category_is_private ? 'bg-warning-50 text-warning-600' : 'bg-success-50 text-success-600'">{{ tr.category_label }}</span>
            </div>
            <div v-if="auth.canWrite('logbook.write')" class="flex gap-2 mt-2">
              <button @click="editTrip(tr)" class="cursor-pointer inline-flex items-center gap-1 h-7 px-2 text-xs border border-neutral-300 rounded-md hover:bg-neutral-50">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 0 0-2 2v11a2 2 0 0 0 2 2h11a2 2 0 0 0 2-2v-5m-1.414-9.414a2 2 0 1 1 2.828 2.828L11.828 15H9v-2.828z"/></svg>
                {{ t('common.edit') }}
              </button>
              <button @click="removeTrip(tr)" class="cursor-pointer inline-flex items-center gap-1 h-7 px-2 text-xs border border-neutral-300 text-danger-600 rounded-md hover:bg-danger-50 ml-auto">
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

    <!-- Modal: trip -->
    <div v-if="open" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto">
        <form @submit.prevent="save" class="p-5 space-y-4">
          <h2 class="text-lg font-semibold">{{ draft.id ? t('logbook.trip_edit') : t('logbook.trip_new') }}</h2>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.car') }} *</label>
              <select v-model.number="draft.car_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option v-for="c in cars" :key="c.id" :value="c.id">{{ c.registration }}{{ c.name ? ` — ${c.name}` : '' }}</option>
              </select>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.date') }} *</label>
              <DateInput v-model="draft.trip_date" required class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.time_start') }}</label>
              <input v-model="draft.time_start" type="time" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.time_end') }}</label>
              <input v-model="draft.time_end" type="time" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.odometer_start') }}</label>
              <input v-model.number="draft.odometer_start" type="number" min="0" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.odometer_end') }}</label>
              <input v-model.number="draft.odometer_end" type="number" min="0" @input="recomputeDistanceFromEnd" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.distance_km') }}</label>
              <input v-model.number="draft.distance_km" type="number" min="0" step="0.1" @input="recomputeEndFromDistance"
                :placeholder="computedDistance != null ? String(computedDistance) : ''"
                class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
              <p class="text-xs text-neutral-400 mt-0.5">{{ t('logbook.distance_hint') }}</p>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.category') }}</label>
              <select v-model="draft.category_id" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
                <option :value="null">—</option>
                <option v-for="c in categories" :key="c.id" :value="c.id">{{ c.label }}</option>
              </select>
            </div>
            <div class="col-span-2">
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.purpose') }}</label>
              <input v-model="draft.purpose" type="text" maxlength="255" :list="`${pageId}-trip-purposes`" autocomplete="off"
                :placeholder="t('logbook.purpose_placeholder')" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
              <datalist :id="`${pageId}-trip-purposes`">
                <option v-for="p in purposes" :key="p" :value="p" />
              </datalist>
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.origin') }}</label>
              <input v-model="draft.origin" type="text" maxlength="255" :list="`${pageId}-trip-places`" autocomplete="off" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.destination') }}</label>
              <input v-model="draft.destination" type="text" maxlength="255" :list="`${pageId}-trip-places`" autocomplete="off" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <datalist :id="`${pageId}-trip-places`">
              <option v-for="p in places" :key="p" :value="p" />
            </datalist>
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

    <!-- Modal: import -->
    <div v-if="importOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-lg max-h-[90vh] overflow-y-auto p-5 space-y-4">
        <h2 class="text-lg font-semibold">{{ t('logbook.import_title') }}</h2>
        <p class="text-sm text-neutral-500">{{ t('logbook.import_hint') }}</p>
        <div class="flex items-center gap-3">
          <button @click="fileInput?.click()" :disabled="importing"
            class="cursor-pointer h-9 px-3 bg-primary-600 hover:bg-primary-700 text-white text-sm rounded-md disabled:opacity-50 inline-flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M12 4v12m0-12l-4 4m4-4l4 4"/></svg>
            {{ importing ? t('common.loading') : t('logbook.choose_file') }}
          </button>
          <button @click="downloadTemplate"
            class="cursor-pointer h-9 px-3 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 inline-flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4 16v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2M12 16V4m0 12l-4-4m4 4l4-4"/></svg>
            {{ t('logbook.download_template') }}
          </button>
          <input ref="fileInput" type="file" accept=".csv,.xlsx,.xls" class="hidden" @change="onImportFile" />
        </div>
        <div v-if="importReport" class="text-sm border border-neutral-200 rounded-md p-3 max-h-60 overflow-y-auto">
          <p class="font-medium mb-1">{{ t('logbook.import_result', { created: importReport.created, failed: importReport.failed }) }}</p>
          <p v-if="importReport.new_categories && importReport.new_categories.length" class="text-xs text-emerald-700 mt-1">
            {{ t('logbook.import_new_categories', { list: importReport.new_categories.join(', ') }) }}
          </p>
          <ul v-if="importReport.failed > 0" class="text-xs text-danger-600 space-y-0.5 mt-1">
            <li v-for="r in importReport.rows.filter(r => r.status === 'failed')" :key="r.line">{{ t('logbook.row') }} {{ r.line }}: {{ r.reason }}</li>
          </ul>
        </div>
        <div class="flex justify-end">
          <button @click="importOpen = false" class="cursor-pointer h-9 px-4 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50 inline-flex items-center gap-1.5">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
            {{ t('common.close') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Modal: export -->
    <div v-if="exportOpen" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-lg shadow-xl w-full max-w-md max-h-[90vh] overflow-y-auto p-5 space-y-4">
        <h2 class="text-lg font-semibold">{{ t('logbook.export_title') }}</h2>
        <p class="text-sm text-neutral-500">{{ t('logbook.export_hint') }}</p>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('logbook.car') }}</label>
          <select v-model="exportCar" class="w-full h-10 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
            <option :value="''">{{ t('logbook.all_cars_grouped') }}</option>
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
  </section>
</template>
