<script setup lang="ts">
/**
 * Přehled osob ve spočítaném mzdovém běhu.
 *
 * Běh měl místo tabulky jen detail jedné osoby ve výběru (zvlášť pro daň a pro
 * pojistné) a kvůli němu stahoval výsledky všech osob najednou, u 150 lidí
 * 2,8 MB. Tabulka proto stránkuje, řadí i hledá na serveru
 * (`GET /payroll/runs/{id}/people`) a celý výsledek stáhne až pro osobu, jejíž
 * výpočet si účetní rozklikne. Rozpady daně, pojistného a čisté mzdy zůstávají
 * tytéž komponenty, jen dostávají jednu osobu.
 */
import { computed, onBeforeUnmount, ref, watch } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollRunPeopleSortKey,
  type PayrollRunPeopleTotals,
  type PayrollRunPersonDetail,
  type PayrollRunPersonRow,
} from '@/api/payroll'
import type { SortPref } from '@/api/preferences'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import SortableTh from '@/components/ui/SortableTh.vue'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import PayrollIncomeTaxBreakdown from '@/components/payroll/PayrollIncomeTaxBreakdown.vue'
import PayrollInsuranceBreakdown from '@/components/payroll/PayrollInsuranceBreakdown.vue'
import PayrollNetPayBreakdown from '@/components/payroll/PayrollNetPayBreakdown.vue'
import PayrollPayslipHourlyBreakdown from '@/components/payroll/PayrollPayslipHourlyBreakdown.vue'

const props = defineProps<{
  runId: number
  revisionId: number | null
  /** Období běhu `YYYY-MM`, pro odkaz na výplatní pásku. */
  period: string
  approved: boolean
  /**
   * Mění se s každým příkazem běhu (`row_version`): přepočet téže revize dá
   * jiné výsledky pod stejným číslem revize.
   */
  refreshKey?: number
  canExclude?: boolean
}>()

const emit = defineEmits<{
  exclude: [person: { employee_id: number, full_name: string }]
}>()

const PAGE_SIZE = 25
const SEARCH_DEBOUNCE_MS = 300
const AMOUNT_COLUMNS: PayrollRunPeopleSortKey[] = [
  'gross_minor',
  'insurance_minor',
  'tax_minor',
  'net_minor',
  'payable_minor',
]

const { t, te, locale } = useI18n()

const items = ref<PayrollRunPersonRow[]>([])
const total = ref(0)
const totals = ref<PayrollRunPeopleTotals | null>(null)
const loading = ref(true)
const loadFailed = ref(false)
const page = ref(1)
const sort = ref<SortPref | null>(null)
const search = ref('')
const onlyFindings = ref(false)
let searchTimer: ReturnType<typeof setTimeout> | undefined
let requestSequence = 0

const selectedId = ref<number | null>(null)
const detail = ref<PayrollRunPersonDetail | null>(null)
const detailLoading = ref(false)
const detailFailed = ref(false)
let detailSequence = 0

const narrowed = computed(() => search.value.trim() !== '' || onlyFindings.value)
const detailPeople = computed(() => (detail.value === null ? [] : [detail.value.result]))
const detailNames = computed<Record<number, string>>(() => (detail.value === null
  ? {}
  : { [detail.value.person.employee_id]: detail.value.person.full_name }))

function money(value: number | null | undefined): string {
  if (value === null || value === undefined) return '—'
  return new Intl.NumberFormat(locale.value, {
    style: 'currency',
    currency: 'CZK',
  }).format(value / 100)
}

function amount(person: PayrollRunPersonRow, key: PayrollRunPeopleSortKey): number | null {
  return key === 'name' ? null : person[key]
}

function employmentLabel(person: PayrollRunPersonRow): string {
  return person.employments.map((employment) => {
    const key = `payroll.people.relations.${employment.relation_type}`
    const relation = te(key) ? t(key) : employment.relation_type
    return employment.code === '' ? relation : `${employment.code} · ${relation}`
  }).join(', ')
}

/** Druh daně pod částkou: srážková, záloha, nebo obojí u souběhu vztahů. */
function taxNote(person: PayrollRunPersonRow): string {
  const advance = person.advance_tax_minor ?? 0
  const withholding = person.withholding_tax_minor ?? 0
  const parts: string[] = []
  if (advance > 0 && withholding > 0) parts.push(t('payroll.runs.people_table.tax_kind.mixed'))
  else if (withholding > 0) parts.push(t('payroll.runs.people_table.tax_kind.withholding'))
  else if (advance > 0) parts.push(t('payroll.runs.people_table.tax_kind.advance'))
  if ((person.tax_bonus_minor ?? 0) > 0) {
    parts.push(t('payroll.runs.people_table.tax_bonus', { amount: money(person.tax_bonus_minor) }))
  }
  return parts.join(', ')
}

type Finding = { tone: 'danger' | 'warning', label: string }

function finding(person: PayrollRunPersonRow): Finding | null {
  if (person.result_status === 'error') {
    return { tone: 'danger', label: t('payroll.runs.people_table.finding.error') }
  }
  if (person.findings.blockers > 0) {
    return { tone: 'danger', label: t('payroll.runs.people_table.finding.blockers', person.findings.blockers) }
  }
  if (person.result_status === 'manual_review') {
    return { tone: 'warning', label: t('payroll.runs.people_table.finding.manual_review') }
  }
  if (person.findings.warnings > 0) {
    return { tone: 'warning', label: t('payroll.runs.people_table.finding.warnings', person.findings.warnings) }
  }
  return null
}

function findingClass(tone: Finding['tone']): string {
  return tone === 'danger' ? 'bg-danger-50 text-danger-700' : 'bg-warning-50 text-warning-700'
}

function payslipLink(person: PayrollRunPersonRow) {
  return {
    name: 'payroll-documents',
    query: { period: props.period, tab: 'monthly', person: String(person.employee_id) },
  }
}

async function load() {
  const sequence = ++requestSequence
  loading.value = true
  loadFailed.value = false
  try {
    const result = await payrollApi.runPeople(props.runId, {
      limit: PAGE_SIZE,
      offset: (page.value - 1) * PAGE_SIZE,
      ...(sort.value === null
        ? {}
        : { sort: sort.value.key as PayrollRunPeopleSortKey, dir: sort.value.dir }),
      q: search.value.trim(),
      findings: onlyFindings.value,
    })
    if (sequence !== requestSequence) return
    items.value = result.items
    total.value = result.total
    totals.value = result.totals
  } catch {
    if (sequence !== requestSequence) return
    items.value = []
    total.value = 0
    totals.value = null
    loadFailed.value = true
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}

function reloadFromFirstPage() {
  page.value = 1
  void load()
}

function goToPage(nextPage: number) {
  page.value = nextPage
  void load()
}

/** Jméno vzestupně a částky sestupně jako první klik; třetí klik řazení zruší. */
function toggleSort(key: string) {
  const first = key === 'name' ? 'asc' : 'desc'
  if (sort.value?.key !== key) sort.value = { key, dir: first }
  else if (sort.value.dir === first) sort.value = { key, dir: first === 'asc' ? 'desc' : 'asc' }
  else sort.value = null
  reloadFromFirstPage()
}

function closeDetail() {
  detailSequence++
  selectedId.value = null
  detail.value = null
  detailLoading.value = false
  detailFailed.value = false
}

async function loadDetail(employeeId: number) {
  const sequence = ++detailSequence
  detail.value = null
  detailFailed.value = false
  detailLoading.value = true
  try {
    const loaded = await payrollApi.runPerson(props.runId, employeeId)
    if (sequence !== detailSequence) return
    detail.value = loaded
  } catch {
    if (sequence !== detailSequence) return
    detailFailed.value = true
  } finally {
    if (sequence === detailSequence) detailLoading.value = false
  }
}

function toggleDetail(person: PayrollRunPersonRow) {
  if (selectedId.value === person.employee_id) {
    closeDetail()
    return
  }
  selectedId.value = person.employee_id
  void loadDetail(person.employee_id)
}

watch(search, () => {
  if (searchTimer !== undefined) clearTimeout(searchTimer)
  searchTimer = setTimeout(reloadFromFirstPage, SEARCH_DEBOUNCE_MS)
})

watch(onlyFindings, reloadFromFirstPage)

// Jiná revize téhož běhu (přepočet, oprava) má jiné výsledky i jiné osoby.
watch(() => [props.runId, props.revisionId, props.refreshKey], () => {
  closeDetail()
  reloadFromFirstPage()
}, { immediate: true })

onBeforeUnmount(() => {
  if (searchTimer !== undefined) clearTimeout(searchTimer)
})
</script>

<template>
  <section
    class="mt-4 rounded-xl border border-neutral-200 bg-surface"
    :data-testid="`payroll-run-${runId}-people`"
  >
    <div class="flex flex-wrap items-end justify-between gap-3 border-b border-neutral-200 px-4 py-3 sm:px-5">
      <div class="min-w-0">
        <h3 class="font-semibold text-neutral-900">{{ t('payroll.runs.people_table.title') }}</h3>
        <p class="mt-1 text-xs leading-relaxed text-neutral-500">{{ t('payroll.runs.people_table.subtitle') }}</p>
      </div>
      <div class="flex min-w-0 flex-wrap items-center gap-3">
        <label class="relative min-w-0">
          <span class="sr-only">{{ t('payroll.runs.people_table.search') }}</span>
          <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.search" /></svg>
          <input
            v-model="search"
            type="search"
            class="h-9 w-full min-w-0 rounded-md border border-neutral-300 bg-surface pl-9 pr-3 text-sm sm:w-64"
            :placeholder="t('payroll.runs.people_table.search')"
            data-testid="payroll-run-people-search"
          >
        </label>
        <label class="inline-flex cursor-pointer items-center gap-2 whitespace-nowrap text-sm text-neutral-700">
          <input
            v-model="onlyFindings"
            type="checkbox"
            class="h-4 w-4 rounded border-neutral-300"
            data-testid="payroll-run-people-findings"
          >
          {{ t('payroll.runs.people_table.only_findings') }}
        </label>
      </div>
    </div>

    <div
      v-if="loadFailed"
      class="m-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
      data-testid="payroll-run-people-failed"
    >
      <p>{{ t('payroll.runs.breakdown_failed') }}</p>
      <button type="button" :class="[btnOutlineSm('danger'), 'mt-2']" :disabled="loading" @click="load">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
        {{ t('payroll.runs.people_table.retry') }}
      </button>
    </div>

    <p
      v-else-if="loading && items.length === 0"
      class="px-4 py-6 text-sm text-neutral-500 sm:px-5"
      data-testid="payroll-run-people-loading"
    >
      {{ t('common.loading') }}
    </p>

    <p
      v-else-if="items.length === 0"
      class="px-4 py-6 text-sm text-neutral-500 sm:px-5"
      data-testid="payroll-run-people-empty"
    >
      {{ t(narrowed ? 'payroll.runs.people_table.no_results' : 'payroll.runs.people_table.empty') }}
    </p>

    <template v-else>
      <p class="px-4 pt-3 text-xs text-neutral-500 sm:px-5" data-testid="payroll-run-people-count" aria-live="polite">
        {{ t('payroll.runs.people_table.count', total) }}
      </p>

      <div class="hidden overflow-x-auto md:block" :class="loading ? 'opacity-60' : ''">
        <table class="min-w-full divide-y divide-neutral-200 text-sm">
          <thead>
            <tr>
              <SortableTh
                class="px-4 py-3"
                :label="t('payroll.runs.people_table.columns.name')"
                sort-key="name"
                :sort="sort"
                data-testid="payroll-run-people-sort-name"
                @toggle="toggleSort"
              />
              <SortableTh
                v-for="column in AMOUNT_COLUMNS"
                :key="column"
                class="px-4 py-3"
                :label="t(`payroll.runs.people_table.columns.${column}`)"
                :sort-key="column"
                :sort="sort"
                align="right"
                :data-testid="`payroll-run-people-sort-${column}`"
                @toggle="toggleSort"
              />
              <th scope="col" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-neutral-500">
                {{ t('payroll.runs.people_table.columns.finding') }}
              </th>
              <th scope="col" class="px-4 py-3 text-right">
                <span class="sr-only">{{ t('payroll.runs.people_table.columns.actions') }}</span>
              </th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr
              v-for="person in items"
              :key="person.employee_id"
              class="align-top transition-colors hover:bg-neutral-50"
              :class="selectedId === person.employee_id ? 'bg-payroll-50/60' : ''"
              :data-testid="`payroll-run-person-${person.employee_id}`"
            >
              <td class="px-4 py-3">
                <span class="font-medium text-neutral-900">{{ person.full_name }}</span>
                <span v-if="person.employments.length" class="mt-0.5 block text-xs text-neutral-500">{{ employmentLabel(person) }}</span>
              </td>
              <td
                v-for="column in AMOUNT_COLUMNS"
                :key="column"
                class="whitespace-nowrap px-4 py-3 text-right tabular-nums"
                :class="column === 'payable_minor' ? 'font-semibold text-neutral-900' : 'text-neutral-800'"
              >
                {{ money(amount(person, column)) }}
                <span v-if="column === 'tax_minor' && taxNote(person) !== ''" class="mt-0.5 block text-xs font-normal text-neutral-500">{{ taxNote(person) }}</span>
              </td>
              <td class="px-4 py-3">
                <span
                  v-if="finding(person)"
                  class="inline-block rounded-full px-2 py-1 text-xs font-medium"
                  :class="findingClass(finding(person)!.tone)"
                  :data-testid="`payroll-run-person-${person.employee_id}-finding`"
                >{{ finding(person)!.label }}</span>
                <span v-else class="text-neutral-400" :aria-label="t('payroll.runs.people_table.finding.none')">—</span>
              </td>
              <td class="px-4 py-3">
                <div class="flex flex-wrap justify-end gap-2">
                  <button
                    type="button"
                    :class="btnOutlineSm('primary')"
                    :aria-expanded="selectedId === person.employee_id"
                    :data-testid="`payroll-run-person-${person.employee_id}-detail`"
                    @click="toggleDetail(person)"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chart" /></svg>
                    {{ t(selectedId === person.employee_id ? 'payroll.runs.people_table.actions.detail_hide' : 'payroll.runs.people_table.actions.detail') }}
                  </button>
                  <RouterLink
                    v-if="approved"
                    :to="payslipLink(person)"
                    :class="btnOutlineSm('neutral')"
                    :data-testid="`payroll-run-person-${person.employee_id}-payslip`"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
                    {{ t('payroll.runs.people_table.actions.payslip') }}
                  </RouterLink>
                  <button
                    v-if="canExclude"
                    type="button"
                    :class="btnOutlineSm('warning')"
                    :data-testid="`payroll-run-person-${person.employee_id}-exclude`"
                    @click="emit('exclude', { employee_id: person.employee_id, full_name: person.full_name })"
                  >
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
                    {{ t('payroll.runs.exclusion.exclude_short') }}
                  </button>
                </div>
              </td>
            </tr>
          </tbody>
          <tfoot v-if="totals" class="border-t border-neutral-200 bg-neutral-50">
            <tr data-testid="payroll-run-people-totals">
              <th scope="row" class="px-4 py-3 text-left text-xs font-medium uppercase tracking-wide text-neutral-500">
                {{ t(narrowed ? 'payroll.runs.people_table.totals_narrowed' : 'payroll.runs.people_table.totals', total) }}
              </th>
              <td
                v-for="column in AMOUNT_COLUMNS"
                :key="column"
                class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums text-neutral-900"
              >{{ money(totals[column as keyof PayrollRunPeopleTotals]) }}</td>
              <td colspan="2" />
            </tr>
          </tfoot>
        </table>
      </div>

      <div class="space-y-3 p-4 md:hidden" :class="loading ? 'opacity-60' : ''">
        <article
          v-for="person in items"
          :key="person.employee_id"
          class="min-w-0 overflow-hidden rounded-lg border border-neutral-200 p-4"
          :class="selectedId === person.employee_id ? 'border-payroll-300 bg-payroll-50/60' : ''"
          :data-testid="`payroll-run-person-mobile-${person.employee_id}`"
        >
          <div class="flex flex-wrap items-start justify-between gap-2">
            <h4 class="min-w-0 font-semibold text-neutral-900">
              {{ person.full_name }}
              <span v-if="person.employments.length" class="mt-0.5 block text-xs font-normal text-neutral-500">{{ employmentLabel(person) }}</span>
            </h4>
            <span
              v-if="finding(person)"
              class="rounded-full px-2 py-1 text-xs font-medium"
              :class="findingClass(finding(person)!.tone)"
            >{{ finding(person)!.label }}</span>
          </div>
          <dl class="mt-3 grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
            <div v-for="column in AMOUNT_COLUMNS" :key="column">
              <dt class="text-xs text-neutral-500">{{ t(`payroll.runs.people_table.columns.${column}`) }}</dt>
              <dd class="mt-0.5 tabular-nums" :class="column === 'payable_minor' ? 'font-semibold text-neutral-900' : 'text-neutral-800'">
                {{ money(amount(person, column)) }}
                <span v-if="column === 'tax_minor' && taxNote(person) !== ''" class="block text-xs font-normal text-neutral-500">{{ taxNote(person) }}</span>
              </dd>
            </div>
          </dl>
          <div class="mt-4 flex flex-wrap items-center gap-2">
            <button
              type="button"
              :class="btnOutlineSm('primary')"
              :aria-expanded="selectedId === person.employee_id"
              @click="toggleDetail(person)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chart" /></svg>
              {{ t(selectedId === person.employee_id ? 'payroll.runs.people_table.actions.detail_hide' : 'payroll.runs.people_table.actions.detail') }}
            </button>
            <RouterLink v-if="approved" :to="payslipLink(person)" :class="btnOutlineSm('neutral')">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.download" /></svg>
              {{ t('payroll.runs.people_table.actions.payslip') }}
            </RouterLink>
            <button
              v-if="canExclude"
              type="button"
              :class="btnOutlineSm('warning')"
              :data-testid="`payroll-run-person-mobile-${person.employee_id}-exclude`"
              @click="emit('exclude', { employee_id: person.employee_id, full_name: person.full_name })"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
              {{ t('payroll.runs.exclusion.exclude_short') }}
            </button>
          </div>
        </article>
        <dl
          v-if="totals"
          class="grid grid-cols-2 gap-x-4 gap-y-2 rounded-lg bg-neutral-50 p-4 text-sm"
        >
          <div class="col-span-2 text-xs font-medium uppercase tracking-wide text-neutral-500">
            {{ t(narrowed ? 'payroll.runs.people_table.totals_narrowed' : 'payroll.runs.people_table.totals', total) }}
          </div>
          <div v-for="column in AMOUNT_COLUMNS" :key="column">
            <dt class="text-xs text-neutral-500">{{ t(`payroll.runs.people_table.columns.${column}`) }}</dt>
            <dd class="mt-0.5 font-semibold tabular-nums text-neutral-900">{{ money(totals[column as keyof PayrollRunPeopleTotals]) }}</dd>
          </div>
        </dl>
      </div>

      <PaginationBar
        :data-testid="`payroll-run-${runId}-people-pagination`"
        embedded
        :page="page"
        :per-page="PAGE_SIZE"
        :total="total"
        @update:page="goToPage"
      />
    </template>

    <div
      v-if="selectedId !== null"
      class="border-t border-neutral-200 bg-neutral-50 px-4 py-4 sm:px-5"
      :data-testid="`payroll-run-${runId}-person-detail`"
    >
      <div class="flex flex-wrap items-start justify-between gap-2">
        <h3 class="min-w-0 font-semibold text-neutral-900">
          {{ t('payroll.runs.people_table.detail_title', { name: detail?.person.full_name ?? items.find(person => person.employee_id === selectedId)?.full_name ?? '' }) }}
        </h3>
        <button type="button" :class="btnOutlineSm('neutral')" data-testid="payroll-run-person-detail-close" @click="closeDetail">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('payroll.runs.people_table.actions.detail_hide') }}
        </button>
      </div>
      <p v-if="detailLoading" class="mt-3 text-sm text-neutral-500">{{ t('common.loading') }}</p>
      <div
        v-else-if="detailFailed"
        class="mt-3 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
        role="alert"
        data-testid="payroll-run-person-detail-failed"
      >
        <p>{{ t('payroll.runs.people_table.detail_load_failed') }}</p>
        <button type="button" :class="[btnOutlineSm('danger'), 'mt-2']" @click="loadDetail(selectedId)">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.runs.people_table.retry') }}
        </button>
      </div>
      <template v-else-if="detail">
        <PayrollPayslipHourlyBreakdown :people="detailPeople" :person-names="detailNames" />
        <PayrollIncomeTaxBreakdown :people="detailPeople" :person-names="detailNames" />
        <PayrollInsuranceBreakdown :revision-id="revisionId" :people="detailPeople" :person-names="detailNames" />
        <PayrollNetPayBreakdown :revision-id="revisionId" :approved="approved" :people="detailPeople" :person-names="detailNames" />
      </template>
    </div>
  </section>
</template>
