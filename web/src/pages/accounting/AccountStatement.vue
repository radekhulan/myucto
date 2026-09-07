<script setup lang="ts">
import { ref, onMounted, reactive, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, RouterLink } from 'vue-router'
import {
  accountingApi,
  type AccountStatementReport,
  type AccountStatementItem,
} from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import { journalSourceLink, journalEntryLink } from '@/utils/journalSourceLink'
import { appIsoDate, appYear } from '@/utils/date'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const route = useRoute()
const toast = useToast()

const accountId = computed(() => Number(route.params.accountId))

const report = ref<AccountStatementReport | null>(null)
const loading = ref(false)

const page = ref(1)
const perPage = ref(50)
const totalPages = computed(() => {
  if (!report.value) return 1
  return Math.max(1, Math.ceil(report.value.total / (report.value.per_page || perPage.value)))
})

function defaultRange(): { from: string; to: string } {
  const today = new Date()
  return { from: `${appYear(today)}-01-01`, to: appIsoDate(today) }
}

const filters = reactive({
  from: typeof route.query.from === 'string' && route.query.from ? route.query.from : defaultRange().from,
  to: typeof route.query.to === 'string' && route.query.to ? route.query.to : defaultRange().to,
})

async function load() {
  if (!accountId.value) return
  loading.value = true
  try {
    report.value = await accountingApi.getAccountStatement(accountId.value, {
      from: filters.from,
      to: filters.to,
      page: page.value,
      per_page: perPage.value,
    })
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
    report.value = null
  } finally {
    loading.value = false
  }
}

function applyFilters() {
  page.value = 1
  load()
}

function goToPage(p: number) {
  const np = Math.min(Math.max(1, p), totalPages.value)
  if (np !== page.value) {
    page.value = np
    load()
  }
}

/**
 * Drill-down na prvotní doklad dle source_type (sdílené s deníkem a hlavní knihou,
 * viz utils/journalSourceLink.ts); bez rozpoznaného zdroje se jde do deníku na zápis.
 * Dokud tu mapování žilo lokálně, uměl opis prokliknout jen faktury — banka,
 * pokladna, majetek i zápočet končily v deníku.
 */
function itemLink(it: AccountStatementItem) {
  return journalSourceLink(it) ?? journalEntryLink(it.entry_id)
}

/** Karta účtu — kmen, analytiky a odkazy zpátky do knihy/deníku. */
const accountCardLink = computed(() => ({
  name: 'accounting-account-detail',
  params: { accountId: accountId.value },
  query: { from: filters.from, to: filters.to },
}))

/** Zobrazovat sloupec s analytikou má smysl jen u syntetiky (opis pak míchá víc účtů). */
const showLineAccount = computed(() => {
  const items = report.value?.items ?? []
  return items.some(i => i.account_id !== report.value?.account.id)
})

const exporting = ref(false)
async function exportFile(format: 'pdf' | 'xlsx') {
  if (!report.value) return
  exporting.value = true
  try {
    const r = await accountingApi.exportReport(`/accounting/reports/account-statement/${accountId.value}/export`, {
      from: filters.from,
      to: filters.to,
      format,
    })
    downloadBlob(r.data as unknown as Blob, `opis-uctu-${report.value.account.code}-${filters.from}-${filters.to}.${format}`)
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

watch(accountId, () => {
  page.value = 1
  load()
})

onMounted(load)
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4">
      <div>
        <!-- Kód a název jako samostatné položky flexu s gapem — mezera v textu se při
             zalomení dlouhého názvu ztratí a kód by se na název nalepil. -->
        <h1 class="text-2xl font-semibold flex flex-wrap items-baseline gap-x-2">
          <span>{{ t('accounting.account_statement.title') }}</span>
          <template v-if="report">
            <span class="text-neutral-400">—</span>
            <RouterLink :to="accountCardLink" class="font-mono text-primary-600 hover:text-primary-700 hover:underline">
              {{ report.account.code }}
            </RouterLink>
            <span>{{ report.account.name }}</span>
          </template>
        </h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.account_statement.subtitle') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <RouterLink v-if="report" :to="accountCardLink" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" /></svg>
          {{ t('accounting.account_statement.account_card') }}
        </RouterLink>
        <button :disabled="!report || exporting" @click="exportFile('pdf')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.account_statement.export_pdf') }}
        </button>
        <button :disabled="!report || exporting" @click="exportFile('xlsx')" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ t('accounting.account_statement.export_xlsx') }}
        </button>
      </div>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.account_statement.filter_from') }}</label>
          <DateInput v-model="filters.from" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.account_statement.filter_to') }}</label>
          <DateInput v-model="filters.to" @change="applyFilters"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm" />
        </div>
      </div>
    </div>

    <!-- Souhrn účtu -->
    <div v-if="report" class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
        <div class="text-xs text-neutral-500">{{ t('accounting.account_statement.opening') }}</div>
        <div class="text-lg font-semibold font-mono">{{ formatMoney(report.opening_balance) }}</div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
        <div class="text-xs text-neutral-500">{{ t('accounting.account_statement.turnover_md') }}</div>
        <div class="text-lg font-semibold font-mono">{{ formatMoney(report.turnover_md) }}</div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
        <div class="text-xs text-neutral-500">{{ t('accounting.account_statement.turnover_d') }}</div>
        <div class="text-lg font-semibold font-mono">{{ formatMoney(report.turnover_d) }}</div>
      </div>
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
        <div class="text-xs text-neutral-500">{{ t('accounting.account_statement.closing') }}</div>
        <div class="text-lg font-semibold font-mono">{{ formatMoney(report.closing_balance) }}</div>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="!report || report.items.length === 0" boxed accent="neutral" icon="doc" :title="t('accounting.account_statement.empty')" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-28">{{ t('accounting.account_statement.col_date') }}</th>
              <th class="px-3 py-2 text-left font-medium w-36">{{ t('accounting.account_statement.col_document') }}</th>
              <th v-if="showLineAccount" class="px-3 py-2 text-left font-medium w-28">{{ t('accounting.account_statement.col_line_account') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.account_statement.col_description') }}</th>
              <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.account_statement.col_md') }}</th>
              <th class="px-3 py-2 text-right font-medium w-32">{{ t('accounting.account_statement.col_d') }}</th>
              <th class="px-3 py-2 text-right font-medium w-36">{{ t('accounting.account_statement.col_balance') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="(it, idx) in report.items" :key="`${it.entry_id}-${idx}`" class="hover:bg-neutral-50">
              <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(it.entry_date) }}</td>
              <td class="px-3 py-2">
                <RouterLink :to="itemLink(it)"
                  class="font-mono text-xs text-primary-600 hover:text-primary-700 inline-flex items-center gap-1">
                  {{ it.document_no || t('accounting.account_statement.journal_link', { id: it.entry_id }) }}
                  <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M10 6H6a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                  </svg>
                </RouterLink>
              </td>
              <td v-if="showLineAccount" class="px-3 py-2">
                <RouterLink :to="{ name: 'accounting-account-detail', params: { accountId: it.account_id }, query: { from: filters.from, to: filters.to } }"
                  class="font-mono text-xs text-neutral-500 hover:text-primary-600 hover:underline" :title="it.account_name">
                  {{ it.account_code }}
                </RouterLink>
              </td>
              <td class="px-3 py-2">{{ it.description || '—' }}</td>
              <td class="px-3 py-2 text-right font-mono">
                <template v-if="it.side === 'debit'">{{ formatMoney(it.amount) }}</template>
              </td>
              <td class="px-3 py-2 text-right font-mono">
                <template v-if="it.side === 'credit'">{{ formatMoney(it.amount) }}</template>
              </td>
              <td class="px-3 py-2 text-right font-mono">{{ formatMoney(it.balance) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <nav v-if="!loading && report && report.total > report.per_page" class="mt-4 flex items-center justify-end gap-1 text-sm">
      <button type="button" :disabled="page <= 1" @click="goToPage(page - 1)"
        class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">‹</button>
      <span class="px-2 text-neutral-600">{{ page }} / {{ totalPages }}</span>
      <button type="button" :disabled="page >= totalPages" @click="goToPage(page + 1)"
        class="cursor-pointer h-8 px-3 border border-neutral-300 rounded-md hover:bg-neutral-50 disabled:opacity-40 disabled:cursor-not-allowed">›</button>
    </nav>
  </div>
</template>
