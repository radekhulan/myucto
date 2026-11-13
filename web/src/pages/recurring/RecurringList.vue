<script setup lang="ts">
import { ref, onMounted, onUnmounted, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { recurringApi, type RecurringTemplate, type RecurringStatus, type RecurringSort, type RecurringSummary } from '@/api/recurring'
import { useToast } from '@/composables/useToast'
import { useRowLink } from '@/composables/useRowLink'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'

const { t } = useI18n()
const toast = useToast()
const router = useRouter()
const route = useRoute()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const priceListEnabled = computed(() => !auth.hasCommercialFeatures || supplierStore.currentSupplier?.stock_enabled !== true)

const templates = ref<RecurringTemplate[]>([])
const loading = ref(false)
const loadingMore = ref(false)
const statusFilter = ref<RecurringStatus | ''>('active')
const sortBy = ref<RecurringSort>('next_run')
// Telefon: stav a řazení za tlačítkem „Filtry" (vzor CashRegister), hledání zůstává vidět.
const filtersOpen = ref(false)
const activeFilterCount = computed(() => (statusFilter.value !== 'active' ? 1 : 0) + (sortBy.value !== 'next_run' ? 1 : 0))
// Dotaz drží URL (?q=) a sessionStorage — odkaz „Zpět na seznam" z detailu vede
// na holé /recurring, tak ať se hledání po návratu neztratí.
const SEARCH_KEY = 'recurring.list.q'
function storedSearch(): string {
  try { return sessionStorage.getItem(SEARCH_KEY) ?? '' } catch { return '' }
}
const search = ref(typeof route.query.q === 'string' ? route.query.q : storedSearch())
let searchTimeout: ReturnType<typeof setTimeout> | null = null
const busy = ref<number | null>(null)

const total = ref(0)
const page = ref(1)
const pages = ref(1)
const statusCounts = ref<{ all: number; active: number; paused: number; expired: number }>({
  all: 0, active: 0, paused: 0, expired: 0,
})
const summary = ref<RecurringSummary | null>(null)

// Server-side filtering — frontend žádný extra filter neaplikuje.
const filtered = computed(() => templates.value)

async function load(reset = true) {
  if (reset) {
    loading.value = true
    page.value = 1
  } else {
    loadingMore.value = true
    page.value++
  }
  try {
    const r = await recurringApi.list({
      status: statusFilter.value || undefined,
      q: search.value.trim() || undefined,
      sort: sortBy.value,
      page: page.value,
    })
    if (reset) {
      templates.value = r.data
    } else {
      templates.value.push(...r.data)
    }
    total.value = r.meta.total
    pages.value = r.meta.pages
    if (r.meta.status_counts) statusCounts.value = r.meta.status_counts
    summary.value = r.meta.summary ?? null
  } finally {
    loading.value = false
    loadingMore.value = false
  }
}

onMounted(() => load(true))
watch([statusFilter, sortBy], () => load(true))
watch(search, (q) => {
  try { sessionStorage.setItem(SEARCH_KEY, q) } catch { /* private mode */ }
  if (searchTimeout) clearTimeout(searchTimeout)
  searchTimeout = setTimeout(() => {
    router.replace({ query: { ...route.query, q: q.trim() || undefined } })
    load(true)
  }, 300)
})
onUnmounted(() => { if (searchTimeout) clearTimeout(searchTimeout) })

function statusBadgeClass(s: RecurringStatus) {
  return {
    active:  'bg-success-50 text-success-700 border-success-200',
    paused:  'bg-warning-50 text-warning-700 border-warning-200',
    expired: 'bg-neutral-100 text-neutral-500 border-neutral-200',
  }[s]
}

function freqLabel(f: string): string {
  return t(`recurring.frequency_${f}`)
}

function formatDate(d: string | null): string {
  if (!d) return '—'
  return new Date(d).toLocaleDateString('cs-CZ')
}

function formatMoney(n: number, ccy: string): string {
  return n.toLocaleString('cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + ' ' + ccy
}

// Souhrn částek do hlavičky: jedna měna → v ní; více měn → přepočet na CZK.
const grandTotalLabel = computed<string>(() => {
  const s = summary.value
  if (!s || s.by_currency.length === 0) return ''
  if (s.multi_currency) return formatMoney(s.total_czk, 'CZK')
  return formatMoney(s.by_currency[0].total, s.by_currency[0].currency)
})
const grandTotalIsCzkConversion = computed(() => !!summary.value?.multi_currency)

async function pause(tpl: RecurringTemplate) {
  if (!confirm(t('recurring.pause_confirm', { name: tpl.name }))) return
  busy.value = tpl.id
  try {
    const updated = await recurringApi.pause(tpl.id)
    const idx = templates.value.findIndex(x => x.id === tpl.id)
    if (idx >= 0) templates.value[idx] = { ...templates.value[idx], ...updated }
    toast.success(t('recurring.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Error')
  } finally { busy.value = null }
}

async function resume(tpl: RecurringTemplate) {
  if (!confirm(t('recurring.resume_confirm', { name: tpl.name, date: formatDate(tpl.next_run_date) }))) return
  busy.value = tpl.id
  try {
    const updated = await recurringApi.resume(tpl.id)
    const idx = templates.value.findIndex(x => x.id === tpl.id)
    if (idx >= 0) templates.value[idx] = { ...templates.value[idx], ...updated }
    toast.success(t('recurring.saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Error')
  } finally { busy.value = null }
}

async function remove(tpl: RecurringTemplate) {
  if (!confirm(t('recurring.delete_confirm', { name: tpl.name }))) return
  busy.value = tpl.id
  try {
    await recurringApi.delete(tpl.id)
    templates.value = templates.value.filter(x => x.id !== tpl.id)
    toast.success(t('recurring.deleted'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || 'Error')
  } finally { busy.value = null }
}

const navigateRow = useRowLink()
function gotoNew() {
  router.push({ name: 'recurring-new' })
}
function gotoDetail(id: number, e?: MouseEvent) {
  navigateRow({ name: 'recurring-detail', params: { id } }, e)
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('recurring.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('recurring.subtitle') }}</p>
      </div>
      <button v-if="auth.canWrite('recurring.create')" @click="gotoNew" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
        {{ t('recurring.new') }}
      </button>
    </div>

    <!-- Filtry v boxu (sjednoceno s /invoices a /purchase-invoices) -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-3">
      <div class="flex flex-wrap items-center gap-2">
        <div class="relative flex-1 min-w-56">
          <svg class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-neutral-400"
            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M17 11a6 6 0 1 1-12 0 6 6 0 0 1 12 0z" />
          </svg>
          <input
            v-model="search"
            type="search"
            :placeholder="t('recurring.search_placeholder')"
            class="w-full h-9 pl-9 pr-3 border border-neutral-300 rounded-md text-sm focus:ring-2 focus:ring-primary-500/20 focus:border-primary-500 outline-none"
          />
        </div>
        <button type="button" @click="filtersOpen = !filtersOpen" :aria-expanded="filtersOpen" data-test="recurring-filters-toggle"
          class="hidden max-md:inline-flex cursor-pointer h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm items-center gap-1.5 text-neutral-700 hover:bg-neutral-50 transition-colors"
          :class="filtersOpen ? 'bg-neutral-50 border-neutral-400' : ''">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M3 4h18M6 12h12M10 20h4" />
          </svg>
          {{ t('common.filters') }}
          <span v-if="activeFilterCount"
            class="inline-flex items-center justify-center min-w-5 h-5 px-1 rounded-full bg-primary-600 text-white text-xs font-medium tabular-nums">{{ activeFilterCount }}</span>
          <svg class="w-3.5 h-3.5 transition-transform" :class="filtersOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" />
          </svg>
        </button>
        <select v-model="statusFilter" data-test="recurring-status-filter" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" :class="filtersOpen ? '' : 'max-md:hidden'">
          <option value="">{{ t('common.all') ?? 'Vše' }} (status)</option>
          <option value="active">{{ t('recurring.status.active') }}</option>
          <option value="paused">{{ t('recurring.status.paused') }}</option>
          <option value="expired">{{ t('recurring.status.expired') }}</option>
        </select>
        <div class="ml-auto flex flex-wrap items-center gap-x-3 gap-y-2 w-full sm:w-auto">
          <div v-if="grandTotalLabel" class="flex items-baseline gap-2">
            <span class="text-sm text-neutral-500">{{ t('recurring.amount_total') }}</span>
            <span class="font-mono text-base font-semibold text-neutral-900"
              :title="grandTotalIsCzkConversion ? t('recurring.amount_czk_hint') : ''">
              {{ grandTotalIsCzkConversion ? '≈ ' : '' }}{{ grandTotalLabel }}
            </span>
          </div>
          <select v-model="sortBy" :title="t('recurring.sort.label')" data-test="recurring-sort"
            class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm flex-1 sm:flex-none min-w-0 max-w-full" :class="filtersOpen ? '' : 'max-md:hidden'">
            <option value="next_run">{{ t('recurring.sort.label') }}: {{ t('recurring.sort.next_run') }}</option>
            <option value="client">{{ t('recurring.sort.label') }}: {{ t('recurring.sort.client') }}</option>
            <option value="amount_czk">{{ t('recurring.sort.label') }}: {{ t('recurring.sort.amount_czk') }}</option>
          </select>
        </div>
      </div>
    </div>

    <div v-if="loading" class="text-center py-12 text-neutral-400">…</div>
    <EmptyState v-else-if="filtered.length === 0 && search.trim()" boxed icon="cycle"
      :title="t('recurring.search_empty')" />
    <EmptyState v-else-if="filtered.length === 0" boxed icon="cycle"
      :title="t('recurring.empty')"
      :cta="auth.canWrite('recurring.create') ? t('recurring.create_first') : undefined"
      @action="gotoNew" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Desktop: tabulka -->
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
            <tr>
              <th class="px-4 py-2.5 text-left font-medium">{{ t('recurring.name') }}</th>
              <th class="px-4 py-2.5 text-left font-medium">{{ t('recurring.client') }}</th>
              <th class="px-4 py-2.5 text-left font-medium">{{ t('recurring.frequency') }}</th>
              <th class="px-4 py-2.5 text-left font-medium">{{ t('recurring.next_run_date') }}</th>
              <th class="px-4 py-2.5 text-left font-medium">Status</th>
              <th class="px-4 py-2.5 text-right font-medium">{{ t('recurring.generated_invoices') }}</th>
              <th class="px-4 py-2.5 text-right font-medium">{{ t('recurring.amount') }}</th>
              <th class="px-4 py-2.5 text-right font-medium">{{ t('recurring.actions_col') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="tpl in filtered" :key="tpl.id" class="hover:bg-neutral-50/50">
              <td class="px-4 py-3 align-top">
                <RouterLink :to="{ name: 'recurring-detail', params: { id: tpl.id } }"
                  class="row-link cursor-pointer block text-left text-primary-700 font-medium">
                  {{ tpl.name }}
                </RouterLink>
                <span v-if="tpl.last_error" :title="tpl.last_error"
                  class="mt-0.5 inline-block text-xs px-1.5 py-0.5 rounded bg-danger-50 text-danger-700 border border-danger-200 whitespace-nowrap">
                  ⚠ {{ t('recurring.last_error_badge') }}
                </span>
                <span v-if="tpl.draft_open_mode === 'period_start'"
                  :title="t('recurring.draft_open_mode_period_start')"
                  class="mt-0.5 inline-block text-xs px-1.5 py-0.5 rounded bg-primary-50 text-primary-700 border border-primary-200 whitespace-nowrap">
                  ↻ {{ t('recurring.draft_open_mode_period_start_badge') }}
                </span>
                <span v-if="priceListEnabled && tpl.catalog_state && tpl.catalog_state !== 'ok'" :title="tpl.catalog_error ?? ''"
                  class="mt-0.5 ml-1 inline-block text-xs px-1.5 py-0.5 rounded bg-warning-50 text-warning-700 border border-warning-200 whitespace-nowrap">
                  {{ t('recurring.catalog_state_' + tpl.catalog_state) }}
                </span>
              </td>
              <td class="px-4 py-3 align-top">
                <RouterLink :to="{ name: 'client-detail', params: { id: tpl.client_id } }"
                  class="row-link cursor-pointer block text-left text-neutral-700">
                  {{ tpl.client_company_name }}
                </RouterLink>
                <span v-if="tpl.project_name" class="block text-xs text-neutral-500">{{ tpl.project_name }}</span>
              </td>
              <td class="px-4 py-3">
                {{ freqLabel(tpl.frequency) }}<span v-if="tpl.end_of_month" class="text-neutral-500"> · {{ t('recurring.end_of_month') }}</span>
              </td>
              <td class="px-4 py-3 font-mono text-xs">
                {{ formatDate(tpl.next_run_date) }}
                <span v-if="tpl.last_run_date" class="block text-neutral-400">
                  {{ t('recurring.last_run_date') }}: {{ formatDate(tpl.last_run_date) }}
                </span>
              </td>
              <td class="px-4 py-3">
                <span class="inline-block px-2 py-0.5 text-xs rounded border" :class="statusBadgeClass(tpl.status)">
                  {{ t('recurring.status.' + tpl.status) }}
                </span>
              </td>
              <td class="px-4 py-3 text-right text-neutral-700 align-top">{{ tpl.invoices_generated_count ?? 0 }}</td>
              <td class="px-4 py-3 text-right font-mono text-neutral-800 align-top whitespace-nowrap">
                {{ tpl.total_with_vat != null ? formatMoney(tpl.total_with_vat, tpl.currency ?? 'CZK') : '—' }}
                <span v-if="priceListEnabled && tpl.catalog_total_is_estimate" class="block text-xs font-sans font-normal text-neutral-500">
                  {{ t('recurring.catalog_estimate', { date: formatDate(tpl.catalog_estimate_rate_date ?? null) }) }}
                </span>
              </td>
              <td class="px-4 py-3 text-right whitespace-nowrap align-top">
                <RouterLink :to="{ name: 'recurring-detail', params: { id: tpl.id } }"
                  class="cursor-pointer inline-flex items-center gap-1 px-2.5 h-7 text-xs border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded mr-1.5">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                  {{ t('recurring.actions.detail') }}
                </RouterLink>
                <button v-if="tpl.status === 'active' && auth.canWrite('recurring.pause')" @click="pause(tpl)" :disabled="busy === tpl.id"
                  :title="t('recurring.actions.pause')"
                  class="cursor-pointer inline-flex items-center justify-center w-7 h-7 text-xs border border-warning-500/40 text-warning-700 hover:bg-warning-50 rounded mr-1.5">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
                </button>
                <button v-if="tpl.status === 'paused' && auth.canWrite('recurring.pause')" @click="resume(tpl)" :disabled="busy === tpl.id"
                  :title="t('recurring.actions.resume')"
                  class="cursor-pointer inline-flex items-center justify-center w-7 h-7 text-xs border border-success-500/40 text-success-700 hover:bg-success-50 rounded mr-1.5">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0 0 10 9.87v4.263a1 1 0 0 0 1.555.832l3.197-2.132a1 1 0 0 0 0-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
                </button>
                <button v-if="auth.canWrite('recurring.delete')" @click="remove(tpl)" :disabled="busy === tpl.id"
                  :title="t('recurring.actions.delete')"
                  class="cursor-pointer inline-flex items-center justify-center w-7 h-7 text-xs border border-danger-500/40 text-danger-700 hover:bg-danger-50 rounded">
                  <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></svg>
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobile: karty -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div
          v-for="tpl in filtered"
          :key="`m-${tpl.id}`"
          @click="gotoDetail(tpl.id, $event)" @auxclick.prevent="gotoDetail(tpl.id, $event)"
          class="cursor-pointer hover:bg-neutral-50 transition px-4 py-3"
        >
          <div class="flex items-baseline justify-between gap-2">
            <RouterLink class="row-link font-medium text-neutral-900 truncate"
              :to="{ name: 'recurring-detail', params: { id: tpl.id } }" @click.stop @auxclick.stop>{{ tpl.name }}</RouterLink>
            <span class="text-xs px-2 py-0.5 rounded border whitespace-nowrap" :class="statusBadgeClass(tpl.status)">
              {{ t('recurring.status.' + tpl.status) }}
            </span>
          </div>
          <div class="text-xs text-neutral-500 truncate mt-0.5">
            <RouterLink :to="{ name: 'client-detail', params: { id: tpl.client_id } }" @click.stop @auxclick.stop
              class="row-link cursor-pointer text-neutral-700">
              {{ tpl.client_company_name }}
            </RouterLink>
            <span v-if="tpl.project_name"> · {{ tpl.project_name }}</span>
          </div>
          <div v-if="tpl.last_error" class="mt-1 text-xs text-danger-700 truncate" :title="tpl.last_error">
            ⚠ {{ t('recurring.last_error_badge') }}
          </div>
          <div v-if="priceListEnabled && tpl.catalog_state && tpl.catalog_state !== 'ok'" class="mt-1 text-xs text-warning-700 truncate" :title="tpl.catalog_error ?? ''">
            {{ t('recurring.catalog_state_' + tpl.catalog_state) }}
          </div>
          <div class="flex items-baseline justify-between gap-2 mt-1.5 text-xs">
            <span class="text-neutral-600">
              {{ freqLabel(tpl.frequency) }}<span v-if="tpl.end_of_month" class="text-neutral-400"> · EOM</span>
            </span>
            <span class="font-mono text-neutral-600">{{ formatDate(tpl.next_run_date) }}</span>
          </div>
          <div v-if="tpl.draft_open_mode === 'period_start'" class="mt-1">
            <span class="inline-block text-xs px-1.5 py-0.5 rounded bg-primary-50 text-primary-700 border border-primary-200">
              ↻ {{ t('recurring.draft_open_mode_period_start_badge') }}
            </span>
          </div>
          <div class="flex items-center justify-between gap-2 mt-1 text-xs text-neutral-500">
            <span v-if="tpl.last_run_date">
              {{ t('recurring.last_run_date') }}: <span class="font-mono">{{ formatDate(tpl.last_run_date) }}</span>
            </span>
            <span v-else class="text-neutral-400">—</span>
            <span class="text-neutral-600">{{ tpl.invoices_generated_count ?? 0 }} faktur</span>
          </div>
          <div class="flex items-baseline justify-between gap-2 mt-1 text-xs">
            <span class="text-neutral-500">{{ t('recurring.amount') }}</span>
            <span class="font-mono text-neutral-800 font-medium">
              {{ tpl.total_with_vat != null ? formatMoney(tpl.total_with_vat, tpl.currency ?? 'CZK') : '—' }}
            </span>
          </div>
          <div v-if="priceListEnabled && tpl.catalog_total_is_estimate" class="text-right text-xs text-neutral-500">
            {{ t('recurring.catalog_estimate', { date: formatDate(tpl.catalog_estimate_rate_date ?? null) }) }}
          </div>
          <div class="flex items-center gap-1.5 mt-2.5">
            <RouterLink :to="{ name: 'recurring-detail', params: { id: tpl.id } }" @click.stop @auxclick.stop
              class="cursor-pointer inline-flex items-center gap-1 px-2.5 h-7 text-xs border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 1 1-6 0 3 3 0 0 1 6 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
              {{ t('recurring.actions.detail') }}
            </RouterLink>
            <button v-if="tpl.status === 'active' && auth.canWrite('recurring.pause')" @click.stop="pause(tpl)" :disabled="busy === tpl.id"
              :title="t('recurring.actions.pause')"
              class="cursor-pointer inline-flex items-center justify-center w-7 h-7 text-xs border border-warning-500/40 text-warning-700 hover:bg-warning-50 rounded">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6m7-3a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
            </button>
            <button v-if="tpl.status === 'paused' && auth.canWrite('recurring.pause')" @click.stop="resume(tpl)" :disabled="busy === tpl.id"
              :title="t('recurring.actions.resume')"
              class="cursor-pointer inline-flex items-center justify-center w-7 h-7 text-xs border border-success-500/40 text-success-700 hover:bg-success-50 rounded">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0 0 10 9.87v4.263a1 1 0 0 0 1.555.832l3.197-2.132a1 1 0 0 0 0-1.664z"/><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 1 1-18 0 9 9 0 0 1 18 0z"/></svg>
            </button>
            <button v-if="auth.canWrite('recurring.delete')" @click.stop="remove(tpl)" :disabled="busy === tpl.id"
              :title="t('recurring.actions.delete')"
              class="cursor-pointer inline-flex items-center justify-center w-7 h-7 text-xs border border-danger-500/40 text-danger-700 hover:bg-danger-50 rounded ml-auto">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0 1 16.138 21H7.862a2 2 0 0 1-1.995-1.858L5 7m5 4v6m4-6v6M1 7h22M9 7V4a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v3"/></svg>
            </button>
          </div>
        </div>
      </div>

      <div v-if="templates.length" class="px-4 py-3 border-t border-neutral-200 flex items-center justify-between text-sm">
        <span class="text-neutral-500">{{ t('common.loaded_count', { loaded: templates.length, total: total }) }}</span>
        <button v-if="page < pages" @click="load(false)" :disabled="loadingMore"
          class="cursor-pointer h-9 px-4 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium disabled:opacity-50 rounded-md inline-flex items-center gap-1.5">
          {{ loadingMore ? t('common.loading_more') : t('common.load_more') }}
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19 14l-7 7m0 0l-7-7m7 7V3"/></svg>
        </button>
      </div>
    </div>
  </div>
</template>
