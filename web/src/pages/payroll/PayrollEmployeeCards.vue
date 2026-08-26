<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  payrollApi,
  type PayrollEmployeeCardAbsence,
  type PayrollEmployeeCardRow,
  type PayrollEmployeeCardStatusFilter,
} from '@/api/payroll'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatMoneyMinor } from '@/composables/useFormat'
import { employmentCodeLabel } from './employmentLifecycleUi'

/**
 * Karty zaměstnanců na přehledu mezd.
 *
 * Why: „kolik kdo bere" bylo dosud jen v rychlých vstupech a v rozkliknutém
 * detailu člověka — na přehledu nebylo vidět vůbec nic. Karta proto spojuje
 * tři věci, kvůli kterým se do sekce chodí: kdo to je, kolik má tenhle měsíc
 * dostat a jestli není pryč.
 *
 * Interní kartový pohled rychlých vstupů vrací nejvýše 25 vztahů na stránku,
 * souhrny za celý měsíc a absence jen pro právě viditelné vztahy. Hledání a
 * stavový filtr proto probíhají na serveru a fungují i pro stovky zaměstnanců.
 *
 * Zůstatek dovolené karta neukazuje záměrně: `leaveLedger` je per-vztah, takže
 * by to znamenalo jeden request na zaměstnance (viz private/Mzdy/18-UX-PAYROLL.md).
 */

const props = defineProps<{
  /** Mzdové období ve tvaru YYYY-MM. */
  period: string
}>()

const { t } = useI18n()
const pageSize = 25
const loading = ref(true)
const failed = ref(false)
const rows = ref<PayrollEmployeeCardRow[]>([])
const total = ref(0)
const offset = ref(0)
const companyHeadcount = ref(0)
const summary = ref({ people: 0, gross_preview_minor: 0, away: 0, attention: 0 })
const search = ref('')
const statusFilter = ref<PayrollEmployeeCardStatusFilter>('active')
const currentPage = computed(() => Math.floor(offset.value / pageSize) + 1)
let searchTimer: ReturnType<typeof setTimeout> | null = null
let requestSequence = 0

const filterOptions = computed(() => ([
  { value: 'active' as const, label: t('payroll.employee_cards.filters.active') },
  { value: 'away' as const, label: t('payroll.employee_cards.filters.away') },
  { value: 'attention' as const, label: t('payroll.employee_cards.filters.attention') },
  { value: 'all' as const, label: t('payroll.employee_cards.filters.all') },
]))

function absencesOf(row: PayrollEmployeeCardRow): PayrollEmployeeCardAbsence[] {
  return row.absences
}

function money(minor: number): string {
  return formatMoneyMinor(minor)
}

function relationLabel(row: PayrollEmployeeCardRow): string {
  return t(`payroll.people.relations.${row.relation_type}`)
}

/** Pravidlo žije v `employmentLifecycleUi.ts` — karta zaměstnance ho sdílí. */
function employmentCodeLabelOf(row: PayrollEmployeeCardRow): string {
  return employmentCodeLabel(row.employment_code)
}

function statusLabel(row: PayrollEmployeeCardRow): string {
  if (row.suspended_in_month) return t('payroll.quick_inputs.suspended_in_month')
  return t(`payroll.people.employment_status.${row.effective_status}`)
}

function statusClass(row: PayrollEmployeeCardRow): string {
  if (row.suspended_in_month || row.effective_status === 'suspended') {
    return 'bg-warning-50 text-warning-700'
  }
  if (row.effective_status === 'active') return 'bg-success-50 text-success-700'
  if (row.effective_status === 'ended' || row.effective_status === 'archived'
    || row.effective_status === 'no_show') {
    return 'bg-neutral-100 text-neutral-600'
  }
  return 'bg-payroll-50 text-payroll-700'
}

/** „5. 8. – 9. 8." — den v období stačí, měsíc je v hlavičce stránky. */
function absenceRange(item: PayrollEmployeeCardAbsence): string {
  const day = (value: string) => value.slice(8).replace(/^0/, '')
  const month = (value: string) => value.slice(5, 7).replace(/^0/, '')
  const from = `${day(item.date_from)}. ${month(item.date_from)}.`
  const to = `${day(item.date_to)}. ${month(item.date_to)}.`
  return from === to ? from : `${from} – ${to}`
}

function absenceLabel(item: PayrollEmployeeCardAbsence): string {
  return `${t(`payroll_absence.types.${item.absence_type}`)} ${absenceRange(item)}`
}

function vacationLink(row: PayrollEmployeeCardRow) {
  return {
    name: 'payroll-absences',
    query: { employment: String(row.employment_id), type: 'vacation' },
  }
}

function absenceLink(row: PayrollEmployeeCardRow) {
  return {
    name: 'payroll-absences',
    query: { employment: String(row.employment_id) },
  }
}

function personLink(row: PayrollEmployeeCardRow) {
  return { name: 'payroll-people', query: { person: String(row.employee_id) } }
}

async function load() {
  const sequence = ++requestSequence
  loading.value = true
  failed.value = false
  try {
    const month = await payrollApi.employeeCards(
      props.period,
      { limit: pageSize, offset: offset.value },
      { search: search.value.trim(), status: statusFilter.value },
    )
    if (sequence !== requestSequence) return
    rows.value = month.items
    total.value = month.total
    companyHeadcount.value = month.company_headcount
    summary.value = month.summary
  } catch {
    if (sequence !== requestSequence) return
    failed.value = true
    rows.value = []
    total.value = 0
  } finally {
    if (sequence === requestSequence) loading.value = false
  }
}

function setStatus(value: PayrollEmployeeCardStatusFilter) {
  if (statusFilter.value === value) return
  statusFilter.value = value
  offset.value = 0
  void load()
}

function goToPage(page: number) {
  offset.value = Math.max(0, (page - 1) * pageSize)
  void load()
}

watch(() => props.period, () => {
  offset.value = 0
  void load()
})
watch(search, () => {
  if (searchTimer !== null) clearTimeout(searchTimer)
  searchTimer = setTimeout(() => {
    offset.value = 0
    void load()
  }, 250)
})
onMounted(load)
onBeforeUnmount(() => {
  if (searchTimer !== null) clearTimeout(searchTimer)
})
</script>

<template>
  <section
    class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6"
    data-test="employee-cards"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-lg font-semibold text-neutral-900">
          {{ t('payroll.employee_cards.title') }}
        </h2>
        <p class="mt-1 text-sm text-neutral-500">
          {{ t('payroll.employee_cards.subtitle', { period: props.period }) }}
        </p>
      </div>
      <RouterLink :to="{ name: 'payroll-people' }" :class="btnOutline('primary')">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.user" /></svg>
        {{ t('payroll.employee_cards.manage') }}
      </RouterLink>
    </div>

    <div v-if="loading" class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
      <div v-for="index in 3" :key="index" class="h-44 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <p
      v-else-if="failed"
      class="mt-4 rounded-lg border border-warning-500/30 bg-warning-50 p-4 text-sm text-warning-800"
      data-test="employee-cards-failed"
    >
      {{ t('payroll.employee_cards.load_failed') }}
    </p>

    <!--
      Dva různé prázdné stavy. „Firma nemá nikoho" a „nikdo není v tomhle měsíci
      na listině" vypadaly stejně, takže přehled tvrdil, že zaměstnanci nejsou,
      i když byli — jen měli vztah ve stavu plánovaný nebo archivovaný.
    -->
    <div v-else-if="summary.people === 0" class="mt-4 rounded-lg border border-dashed border-neutral-300 p-8 text-center" data-test="employee-cards-empty">
      <h3 class="text-base font-semibold text-neutral-900">
        {{ companyHeadcount === 0
          ? t('payroll.employee_cards.empty_title')
          : t('payroll.employee_cards.none_active_title') }}
      </h3>
      <p class="mt-1 text-sm text-neutral-500">
        {{ companyHeadcount === 0
          ? t('payroll.employee_cards.empty_hint')
          : t('payroll.employee_cards.none_active_hint') }}
      </p>
      <RouterLink :to="{ name: 'payroll-people' }" :class="[btnOutline('primary'), 'mt-4']">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="companyHeadcount === 0 ? ICONS.plus : ICONS.user" /></svg>
        {{ companyHeadcount === 0
          ? t('payroll.employee_cards.empty_action')
          : t('payroll.employee_cards.none_active_action') }}
      </RouterLink>
    </div>

    <template v-else>
      <dl class="mt-4 grid grid-cols-2 gap-3 text-sm lg:grid-cols-4">
        <div class="rounded-lg bg-neutral-50 p-3">
          <dt class="text-xs text-neutral-500">{{ t('payroll.employee_cards.summary.people') }}</dt>
          <dd class="mt-1 font-semibold text-neutral-900" data-test="employee-count">{{ summary.people }}</dd>
        </div>
        <div class="rounded-lg bg-neutral-50 p-3">
          <dt class="text-xs text-neutral-500">{{ t('payroll.employee_cards.summary.gross') }}</dt>
          <dd class="mt-1 font-semibold text-neutral-900" data-test="employee-total-gross">{{ money(summary.gross_preview_minor) }}</dd>
        </div>
        <div class="rounded-lg bg-neutral-50 p-3">
          <dt class="text-xs text-neutral-500">{{ t('payroll.employee_cards.summary.away') }}</dt>
          <dd class="mt-1 font-semibold text-neutral-900">{{ summary.away }}</dd>
        </div>
        <div class="rounded-lg bg-neutral-50 p-3">
          <dt class="text-xs text-neutral-500">{{ t('payroll.employee_cards.summary.attention') }}</dt>
          <dd class="mt-1 font-semibold" :class="summary.attention > 0 ? 'text-warning-700' : 'text-neutral-900'">
            {{ summary.attention }}
          </dd>
        </div>
      </dl>

      <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-[minmax(0,1fr)_auto]">
        <label class="min-w-0 text-xs font-medium text-neutral-600">
          {{ t('payroll.employee_cards.search') }}
          <div class="relative mt-1">
            <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-neutral-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.search" /></svg>
            <input
              v-model="search"
              type="search"
              data-test="employee-search"
              class="h-9 w-full min-w-0 rounded-md border border-neutral-300 bg-surface pl-9 pr-3 text-sm"
              :placeholder="t('payroll.employee_cards.search_placeholder')"
            >
          </div>
        </label>
        <div class="flex flex-wrap items-end gap-1.5">
          <button
            v-for="option in filterOptions"
            :key="option.value"
            type="button"
            :data-test="`employee-filter-${option.value}`"
            :aria-pressed="statusFilter === option.value"
            class="h-9 cursor-pointer whitespace-nowrap rounded-md border px-3 text-sm transition-colors"
            :class="statusFilter === option.value
              ? 'border-payroll-500 bg-payroll-50 text-payroll-700'
              : 'border-neutral-300 text-neutral-600 hover:bg-neutral-50'"
            @click="setStatus(option.value)"
          >
            {{ option.label }}
          </button>
        </div>
      </div>

      <p
        v-if="total === 0"
        class="mt-4 rounded-lg border border-dashed border-neutral-300 p-8 text-center text-sm text-neutral-500"
      >
        {{ t('payroll.employee_cards.no_results') }}
      </p>

      <div v-else class="mt-4 grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-3">
        <article
          v-for="row in rows"
          :key="row.employment_id"
          class="flex min-w-0 flex-col rounded-xl border border-neutral-200 p-4 transition hover:border-payroll-500/50 hover:shadow-sm"
          :data-test="`employee-card-${row.employment_id}`"
        >
          <div class="flex min-w-0 items-start justify-between gap-2">
            <div class="min-w-0">
              <h3 class="truncate font-semibold text-neutral-900">{{ row.full_name }}</h3>
              <p class="mt-0.5 truncate text-xs text-neutral-500">
                {{ relationLabel(row) }}<template v-if="employmentCodeLabelOf(row)"> · {{ employmentCodeLabelOf(row) }}</template>
              </p>
            </div>
            <span class="shrink-0 rounded-full px-2 py-1 text-xs font-medium" :class="statusClass(row)">
              {{ statusLabel(row) }}
            </span>
          </div>

          <div class="mt-4">
            <p class="text-xs text-neutral-500">{{ t('payroll.employee_cards.base') }}</p>
            <p class="mt-0.5 text-2xl font-semibold text-neutral-900" :data-test="`employee-gross-${row.employment_id}`">
              {{ row.base_requires_entry ? t('payroll.employee_cards.base_missing') : money(row.base_amount_minor) }}
            </p>
            <p
              v-if="row.gross_preview_minor !== row.base_amount_minor"
              class="mt-0.5 text-xs text-neutral-500"
            >
              {{ t('payroll.employee_cards.gross_preview', { amount: money(row.gross_preview_minor) }) }}
            </p>
          </div>

          <div v-if="absencesOf(row).length > 0" class="mt-3 flex flex-wrap gap-1.5">
            <span
              v-for="item in absencesOf(row)"
              :key="item.id"
              class="rounded-full bg-payroll-50 px-2 py-1 text-xs font-medium text-payroll-700"
            >
              {{ absenceLabel(item) }}
            </span>
          </div>

          <ul v-if="row.blockers.length > 0" class="mt-3 space-y-1">
            <li v-for="blocker in row.blockers" :key="blocker" class="text-xs text-warning-700">
              {{ t(`payroll.quick_inputs.blockers.${blocker}`) }}
            </li>
          </ul>

          <div class="mt-4 flex flex-1 flex-wrap items-end gap-2">
            <RouterLink
              :to="vacationLink(row)"
              :class="btnOutline('success')"
              class="whitespace-nowrap"
              :data-test="`employee-vacation-${row.employment_id}`"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.calendar" /></svg>
              {{ t('payroll.employee_cards.actions.vacation') }}
            </RouterLink>
            <RouterLink
              :to="absenceLink(row)"
              :class="btnOutline('warning')"
              class="whitespace-nowrap"
              :data-test="`employee-absence-${row.employment_id}`"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.bell" /></svg>
              {{ t('payroll.employee_cards.actions.absence') }}
            </RouterLink>
            <RouterLink
              :to="personLink(row)"
              :class="btnOutline('neutral')"
              class="whitespace-nowrap"
              :data-test="`employee-detail-${row.employment_id}`"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.user" /></svg>
              {{ t('payroll.employee_cards.actions.detail') }}
            </RouterLink>
          </div>
        </article>
      </div>
      <PaginationBar
        class="mt-4"
        :page="currentPage"
        :per-page="pageSize"
        :total="total"
        @update:page="goToPage"
      />
    </template>
  </section>
</template>
