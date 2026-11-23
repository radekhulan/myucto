<script setup lang="ts">
/**
 * Výběr položek vydaných nebo přijatých faktur pro hromadnou akci (agenda Položky dokladů).
 *
 * Filtr jde na server (`GET /bulk-actions/document-items`), stránkuje se klíčem po dávkách
 * („Načíst další"). Výběr pro akci jsou buď zaškrtnuté položky, nebo všechny položky podle
 * filtru; ten jde zvolit jen tehdy, když jich není víc, než kolik jedna akce zpracuje.
 * Textová pole se posílají se zpožděním, aby každý úhoz neposlal dotaz.
 */
import { computed, onMounted, onUnmounted, reactive, ref, watch, watchEffect } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  bulkActionsApi,
  type BulkDocumentItem,
  type BulkDocumentItemFilters,
  type BulkItemTarget,
  type BulkSelection,
} from '@/api/bulkActions'
import { clientsApi, type Client } from '@/api/clients'
import { vatClassificationsApi, type VatClassification } from '@/api/vatClassifications'
import { codebooksApi, type VatRate } from '@/api/codebooks'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatMoney } from '@/composables/useFormat'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

const TEXT_DEBOUNCE_MS = 350

const props = withDefaults(defineProps<{
  target: BulkItemTarget
  disabled?: boolean
}>(), { disabled: false })

/** Výběr pro akci (null = zatím nic k provedení) a počet položek v něm. */
const selection = defineModel<BulkSelection | null>('selection', { default: null })
const count = defineModel<number>('count', { default: 0 })

const { t, locale } = useI18n()

const isPurchase = computed(() => props.target === 'purchase_invoice_item')
const year = new Date().getFullYear()

const form = reactive({
  q: '',
  partnerId: null as number | null,
  dateField: (props.target === 'purchase_invoice_item' ? 'issue' : 'tax') as 'tax' | 'issue',
  dateFrom: `${year}-01-01`,
  dateTo: `${year}-12-31`,
  vatCode: '',
  vatRateId: '' as number | '',
  account: '',
  productCode: '',
  docNumber: '',
})

const filters = computed<BulkDocumentItemFilters>(() => {
  const f: BulkDocumentItemFilters = { date_field: form.dateField }
  const text = (v: string) => v.trim()
  if (text(form.q)) f.q = text(form.q)
  if (form.partnerId) f.partner_id = form.partnerId
  if (form.dateFrom) f.date_from = form.dateFrom
  if (form.dateTo) f.date_to = form.dateTo
  if (form.vatCode) f.vat_code = form.vatCode
  if (form.vatRateId !== '') f.vat_rate_id = Number(form.vatRateId)
  if (text(form.account)) f.account = text(form.account)
  if (text(form.productCode)) f.product_code = text(form.productCode)
  if (text(form.docNumber)) f.doc_number = text(form.docNumber)
  return f
})

// ── Načítání ────────────────────────────────────────────────────────────────
const rows = ref<BulkDocumentItem[]>([])
const nextCursor = ref<string | null>(null)
const matchCount = ref<number | null>(null)
const capped = ref(false)
const limit = ref(500)
const loading = ref(false)
const loadingMore = ref(false)
const pending = ref(false)
const error = ref('')
/** Filtr, ke kterému patří načtené řádky a počet (výběr podle filtru jde přesně s ním). */
const appliedFilters = ref<BulkDocumentItemFilters | null>(null)
let seq = 0
let timer: ReturnType<typeof setTimeout> | null = null

async function reload() {
  timer = null
  pending.value = false
  const current = { ...filters.value }
  const mine = ++seq
  loading.value = true
  error.value = ''
  checked.value = new Set()
  scope.value = 'filter'
  try {
    const page = await bulkActionsApi.documentItems(props.target, current, null)
    if (mine !== seq) return
    rows.value = page.items
    nextCursor.value = page.next_cursor
    matchCount.value = page.count
    capped.value = !!page.count_capped
    limit.value = page.limit
    appliedFilters.value = current
  } catch (e) {
    if (mine !== seq) return
    rows.value = []
    nextCursor.value = null
    matchCount.value = null
    capped.value = false
    appliedFilters.value = null
    error.value = apiErrorMessage(e)
  } finally {
    if (mine === seq) loading.value = false
  }
}

async function loadMore() {
  const cursor = nextCursor.value
  const current = appliedFilters.value
  if (!cursor || !current || loadingMore.value) return
  const mine = seq
  loadingMore.value = true
  try {
    const page = await bulkActionsApi.documentItems(props.target, current, cursor)
    if (mine !== seq) return
    rows.value = [...rows.value, ...page.items]
    nextCursor.value = page.next_cursor
  } catch (e) {
    if (mine === seq) error.value = apiErrorMessage(e)
  } finally {
    loadingMore.value = false
  }
}

function schedule(delay: number) {
  if (timer !== null) clearTimeout(timer)
  pending.value = true
  timer = setTimeout(() => { void reload() }, delay)
}

const textKey = computed(() => [form.q, form.account, form.productCode, form.docNumber].map(v => v.trim()).join('\u0001'))
const otherKey = computed(() => [form.partnerId, form.dateField, form.dateFrom, form.dateTo, form.vatCode, form.vatRateId].join('\u0001'))
watch(textKey, () => schedule(TEXT_DEBOUNCE_MS))
watch(otherKey, () => schedule(0))

// ── Výběr ───────────────────────────────────────────────────────────────────
const checked = ref<Set<number>>(new Set())
const scope = ref<'selected' | 'filter'>('filter')

function isChecked(row: BulkDocumentItem): boolean {
  return checked.value.has(row.item_id)
}

function toggleRow(row: BulkDocumentItem) {
  const next = new Set(checked.value)
  if (next.has(row.item_id)) next.delete(row.item_id)
  else next.add(row.item_id)
  setChecked(next)
}

const allLoadedChecked = computed(() => rows.value.length > 0 && rows.value.every(isChecked))

function toggleAllLoaded() {
  const next = new Set(checked.value)
  const check = !allLoadedChecked.value
  for (const row of rows.value) {
    if (check) next.add(row.item_id)
    else next.delete(row.item_id)
  }
  setChecked(next)
}

function setChecked(next: Set<number>) {
  const wasEmpty = checked.value.size === 0
  checked.value = next
  if (next.size === 0) scope.value = 'filter'
  else if (wasEmpty) scope.value = 'selected'
}

const canUseFilter = computed(() => !capped.value && (matchCount.value ?? 0) > 0 && !!appliedFilters.value)

watchEffect(() => {
  if (pending.value || loading.value) {
    selection.value = null
    count.value = 0
  } else if (scope.value === 'selected' && checked.value.size > 0) {
    selection.value = { ids: [...checked.value] }
    count.value = checked.value.size
  } else if (scope.value === 'filter' && canUseFilter.value) {
    selection.value = { list_query: { filter: { ...appliedFilters.value } } }
    count.value = matchCount.value ?? 0
  } else {
    selection.value = null
    count.value = 0
  }
})

// ── Číselníky ───────────────────────────────────────────────────────────────
const partners = ref<Client[]>([])
const classifications = ref<VatClassification[]>([])
const rates = ref<VatRate[]>([])

const partnerOptions = computed(() => partners.value.map(c => ({ value: c.id, label: c.company_name, secondary: c.ic ?? undefined })))
const rateOptions = computed(() => [...rates.value].sort((a, b) => a.display_order - b.display_order))

function rateLabel(r: VatRate): string {
  const label = locale.value === 'en' ? r.label_en : r.label_cs
  return label ? `${label} (${r.code})` : r.code
}

function formatRate(rate: number): string {
  return `${Math.round(Number(rate) * 100) / 100} %`
}

function rowDate(row: BulkDocumentItem): string {
  return formatDate(form.dateField === 'tax' ? (row.document_date ?? row.issue_date) : row.issue_date)
}

onMounted(() => {
  void reload()
  clientsApi.list({ archived: false, per_page: 200, role: isPurchase.value ? 'vendors' : 'customers' })
    .then(r => { partners.value = r.data }).catch(() => { partners.value = [] })
  vatClassificationsApi.list(isPurchase.value ? 'purchase' : 'sale')
    .then(v => { classifications.value = v.filter(c => !c.archived) }).catch(() => { classifications.value = [] })
  codebooksApi.vatRates('CZ').then(v => { rates.value = v }).catch(() => { rates.value = [] })
})
onUnmounted(() => {
  if (timer !== null) clearTimeout(timer)
  seq++
})
</script>

<template>
  <div class="border border-neutral-200 rounded-lg p-4 space-y-3" data-test="bulk-item-finder">
    <!-- Filtr -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-2">
      <div class="sm:col-span-2">
        <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-items-q">{{ t('bulk_actions.items_q') }}</label>
        <input id="bulk-items-q" v-model="form.q" type="search" :placeholder="t('bulk_actions.items_q_placeholder')" :disabled="disabled"
          class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface" data-test="bulk-items-q" />
      </div>
      <div class="sm:col-span-2">
        <span class="block text-xs font-medium text-neutral-500 mb-1">{{ isPurchase ? t('bulk_actions.items_vendor') : t('bulk_actions.items_customer') }}</span>
        <SearchableSelect v-model="form.partnerId" :options="partnerOptions" teleport clearable :disabled="disabled"
          :placeholder="isPurchase ? t('bulk_actions.items_vendor_all') : t('bulk_actions.items_customer_all')" data-test="bulk-items-partner" />
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-items-date-field">{{ t('bulk_actions.items_date_field') }}</label>
        <select id="bulk-items-date-field" v-model="form.dateField" :disabled="disabled"
          class="w-full h-9 px-2 border border-neutral-300 rounded-md bg-surface" data-test="bulk-items-date-field">
          <option value="tax">{{ t('bulk_actions.items_date_tax') }}</option>
          <option value="issue">{{ t('bulk_actions.items_date_issue') }}</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-items-from">{{ t('bulk_actions.items_date_from') }}</label>
        <input id="bulk-items-from" v-model="form.dateFrom" type="date" :disabled="disabled"
          class="w-full h-9 px-2 border border-neutral-300 rounded-md bg-surface" data-test="bulk-items-from" />
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-items-to">{{ t('bulk_actions.items_date_to') }}</label>
        <input id="bulk-items-to" v-model="form.dateTo" type="date" :disabled="disabled"
          class="w-full h-9 px-2 border border-neutral-300 rounded-md bg-surface" data-test="bulk-items-to" />
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-items-doc">{{ t('bulk_actions.items_doc_number') }}</label>
        <input id="bulk-items-doc" v-model="form.docNumber" type="text" :disabled="disabled"
          class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface font-mono" data-test="bulk-items-doc" />
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-items-vat-code">{{ t('bulk_actions.items_vat_code') }}</label>
        <select id="bulk-items-vat-code" v-model="form.vatCode" :disabled="disabled"
          class="w-full h-9 px-2 border border-neutral-300 rounded-md bg-surface" data-test="bulk-items-vat-code">
          <option value="">{{ t('bulk_actions.items_any') }}</option>
          <option value="none">{{ t('bulk_actions.items_vat_code_none') }}</option>
          <option v-for="c in classifications" :key="c.id" :value="c.code">{{ c.code }} · {{ c.label }}</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-items-vat-rate">{{ t('bulk_actions.items_vat_rate') }}</label>
        <select id="bulk-items-vat-rate" v-model="form.vatRateId" :disabled="disabled"
          class="w-full h-9 px-2 border border-neutral-300 rounded-md bg-surface" data-test="bulk-items-vat-rate">
          <option value="">{{ t('bulk_actions.items_any') }}</option>
          <option v-for="r in rateOptions" :key="r.id" :value="r.id">{{ rateLabel(r) }}</option>
        </select>
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-items-account">{{ t('bulk_actions.items_account') }}</label>
        <input id="bulk-items-account" v-model="form.account" type="text" :placeholder="t('bulk_actions.items_account_placeholder')" :disabled="disabled"
          class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface font-mono" data-test="bulk-items-account" />
      </div>
      <div>
        <label class="block text-xs font-medium text-neutral-500 mb-1" for="bulk-items-product">{{ t('bulk_actions.items_product_code') }}</label>
        <input id="bulk-items-product" v-model="form.productCode" type="text" :disabled="disabled"
          class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface font-mono" data-test="bulk-items-product" />
      </div>
    </div>

    <!-- Počet a rozsah výběru -->
    <div class="flex flex-wrap items-center justify-between gap-2">
      <p class="text-neutral-700" data-test="bulk-items-count">
        <template v-if="loading || pending">{{ t('common.loading') }}…</template>
        <template v-else-if="matchCount !== null">
          {{ capped ? t('bulk_actions.items_count_capped', { limit }) : t('bulk_actions.items_count', { n: matchCount }) }}
        </template>
      </p>
      <div class="inline-flex flex-wrap gap-1" role="radiogroup" :aria-label="t('bulk_actions.scope_label')">
        <button type="button" role="radio" :aria-checked="scope === 'selected'" class="whitespace-nowrap"
          :class="scope === 'selected' ? btnFilled('primary') : btnOutline('neutral')" data-test="bulk-items-scope-selected"
          :disabled="disabled || checked.size === 0" @click="scope = 'selected'">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
          {{ t('bulk_actions.scope_selected', { n: checked.size }) }}
        </button>
        <button type="button" role="radio" :aria-checked="scope === 'filter'" class="whitespace-nowrap"
          :class="scope === 'filter' ? btnFilled('primary') : btnOutline('neutral')" data-test="bulk-items-scope-filter"
          :disabled="disabled || !canUseFilter" @click="scope = 'filter'">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.funnel" /></svg>
          {{ capped ? t('bulk_actions.items_scope_filter_capped', { limit }) : t('bulk_actions.scope_filter', { n: matchCount ?? 0 }) }}
        </button>
      </div>
    </div>
    <p v-if="capped" class="text-xs text-warning-700" data-test="bulk-items-capped">{{ t('bulk_actions.items_capped_hint', { limit }) }}</p>

    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3" role="alert" data-test="bulk-items-error">{{ error }}</div>
    <p v-else-if="!loading && !pending && rows.length === 0" class="py-4 text-center text-neutral-400" data-test="bulk-items-empty">{{ t('bulk_actions.items_empty') }}</p>

    <template v-if="rows.length > 0">
      <!-- Desktop: tabulka -->
      <div class="hidden md:block overflow-x-auto max-h-96 overflow-y-auto" :class="loading ? 'opacity-60' : ''">
        <table class="w-full">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-100">
              <th class="py-2 pr-2 w-6">
                <input type="checkbox" class="h-4 w-4 cursor-pointer" :checked="allLoadedChecked" :disabled="disabled"
                  :aria-label="t('bulk_actions.items_check_loaded')" data-test="bulk-items-check-all" @change="toggleAllLoaded" />
              </th>
              <th class="py-2 pr-3">{{ t('bulk_actions.col_document') }}</th>
              <th class="py-2 pr-3">{{ isPurchase ? t('bulk_actions.items_vendor') : t('bulk_actions.items_customer') }}</th>
              <th class="py-2 pr-3">{{ t('bulk_actions.col_date') }}</th>
              <th class="py-2 pr-3">{{ t('bulk_actions.col_description') }}</th>
              <th class="py-2 pr-3 text-right">{{ t('bulk_actions.items_col_amount') }}</th>
              <th class="py-2">{{ t('bulk_actions.items_col_vat') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in rows" :key="row.item_id" class="border-b border-neutral-50 last:border-0 cursor-pointer hover:bg-neutral-50"
              :class="isChecked(row) ? 'bg-primary-50/60' : ''" data-test="bulk-items-row" @click="!disabled && toggleRow(row)">
              <td class="py-2 pr-2" @click.stop>
                <input type="checkbox" class="h-4 w-4 cursor-pointer" :checked="isChecked(row)" :disabled="disabled"
                  :aria-label="`${row.document_no ?? ''} ${row.description}`" data-test="bulk-items-check" @change="toggleRow(row)" />
              </td>
              <td class="py-2 pr-3 font-mono text-xs whitespace-nowrap">{{ row.document_no || '—' }}</td>
              <td class="py-2 pr-3 max-w-40 truncate" :title="row.partner_name ?? ''">{{ row.partner_name || '—' }}</td>
              <td class="py-2 pr-3 whitespace-nowrap">{{ rowDate(row) }}</td>
              <td class="py-2 pr-3 max-w-64 truncate" :title="row.description">{{ row.description }}</td>
              <td class="py-2 pr-3 text-right font-mono whitespace-nowrap">{{ formatMoney(row.total_with_vat, row.currency ?? 'CZK') }}</td>
              <td class="py-2 whitespace-nowrap" data-test="bulk-items-vat">
                <span v-if="row.vat_classification_code" class="font-mono">{{ row.vat_classification_code }}</span>
                <span v-else-if="row.document_vat_classification_code" class="font-mono text-neutral-400" :title="t('bulk_actions.items_vat_from_header')">{{ row.document_vat_classification_code }}</span>
                <span v-else class="text-neutral-400">—</span>
                <span class="text-neutral-500"> · {{ formatRate(row.vat_rate) }}</span>
                <span v-if="row.oss" class="ml-1 inline-flex items-center rounded-full bg-warning-50 border border-warning-200 px-2 py-0.5 text-[11px] font-medium text-warning-700">OSS</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- Mobil: karty -->
      <div class="md:hidden space-y-2" :class="loading ? 'opacity-60' : ''">
        <label class="flex items-center gap-2 text-xs text-neutral-500 cursor-pointer">
          <input type="checkbox" class="h-4 w-4" :checked="allLoadedChecked" :disabled="disabled" @change="toggleAllLoaded" />
          {{ t('bulk_actions.items_check_loaded') }}
        </label>
        <label v-for="row in rows" :key="row.item_id" class="flex items-start gap-3 rounded-lg border p-3 cursor-pointer"
          :class="isChecked(row) ? 'border-primary-400 bg-primary-50/60' : 'border-neutral-200'" data-test="bulk-items-card">
          <input type="checkbox" class="h-4 w-4 mt-0.5" :checked="isChecked(row)" :disabled="disabled" @change="toggleRow(row)" />
          <span class="min-w-0 flex-1">
            <span class="flex flex-wrap items-baseline justify-between gap-x-2">
              <span class="font-mono text-xs">{{ row.document_no || '—' }} · {{ rowDate(row) }}</span>
              <span class="font-mono whitespace-nowrap">{{ formatMoney(row.total_with_vat, row.currency ?? 'CZK') }}</span>
            </span>
            <span class="block truncate text-neutral-700">{{ row.description }}</span>
            <span class="block truncate text-xs text-neutral-500">{{ row.partner_name || '—' }}</span>
            <span class="block text-xs">
              <span v-if="row.vat_classification_code" class="font-mono">{{ row.vat_classification_code }}</span>
              <span v-else-if="row.document_vat_classification_code" class="font-mono text-neutral-400">{{ row.document_vat_classification_code }}</span>
              <span v-else class="text-neutral-400">—</span>
              <span class="text-neutral-500"> · {{ formatRate(row.vat_rate) }}</span>
              <span v-if="row.oss" class="ml-1 text-warning-700">OSS</span>
            </span>
          </span>
        </label>
      </div>

      <div v-if="nextCursor" class="flex flex-wrap justify-center">
        <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" :disabled="loadingMore || loading"
          data-test="bulk-items-more" @click="loadMore">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
          {{ loadingMore ? t('common.loading') : t('bulk_actions.items_load_more') }}
        </button>
      </div>
    </template>
  </div>
</template>
