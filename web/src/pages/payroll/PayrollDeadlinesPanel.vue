<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  payrollApi,
  type PayrollDeadlineGroup,
  type PayrollDeadlineGroupedOverview,
  type PayrollDeadlineSource,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatDate, formatPeriod } from '@/composables/useFormat'
import PayrollDeadlineGroupPeople from '@/pages/payroll/PayrollDeadlineGroupPeople.vue'
import {
  PANEL_PHASE_ORDER,
  PHASE_BADGE,
  PHASE_TONE,
  UNDATED_PHASE,
  usePayrollDeadlineLabels,
  type PayrollDeadlinePanelPhase,
} from '@/pages/payroll/payrollDeadlineLabels'

/**
 * Co je po termínu a co hoří: jeden panel nad všemi prameny lhůt.
 *
 * ## Proč zrovna takhle
 *
 * **Fáze, pod ní skupiny.** Účetní řeší „co je pozdě", ne „podání vs. odvod".
 * Uvnitř fáze je jeden řádek na DRUH povinnosti: import docházky umí založit
 * nástupní checklist dvěma stům lidí naráz a plochý seznam z toho dělal stovky
 * skoro stejných dlaždic. Skupina „Pracovní smlouva, 225 osob, nejstarší po
 * termínu o 104 dnů" říká totéž na jednom řádku a vedle ní je vidět i to, co
 * je skutečně nové.
 *
 * **Lidé se dotahují až po rozbalení**, po stránkách a s hledáním
 * ({@link PayrollDeadlineGroupPeople}); tam jde vybrané nebo celou skupinu
 * odškrtnout jako vyřízené. Podání a odvody jsou za firmu, je jich pár, takže
 * jedou celé a jednočlenná skupina je rovnou odkaz.
 *
 * **`overdue` má vlastní rám a jde první**, i když je termín starý — jinak by
 * zapadl mezi otevřenými lhůtami v horizontu 45 dnů.
 *
 * **Prázdno neřve.** Firma bez zmeškaného termínu dostane jednu klidnou větu.
 *
 * **Bez termínu až na konci.** Nevyřízené položky checklistu, u kterých lhůta
 * odvodit nejde (přihláška ČSSZ u nástupu před 1. 7. 2026), mají vlastní
 * sekci za všemi lhůtami a stejné hromadné odškrtnutí. Mzdový běh je dál
 * hlásí jako chybějící; bez téhle sekce by šly odškrtnout jen po jedné na
 * kartě vztahu. Do „Po termínu" se nepočítají.
 */

const { t } = useI18n()
const auth = useAuthStore()
const {
  titleFor,
  itemTitle,
  itemSubject,
  itemLink,
  dueLabel,
  amountLabel,
  groupDueLabel,
  groupCountLabel,
} = usePayrollDeadlineLabels()

const loading = ref(true)
const loadError = ref('')
const overview = ref<PayrollDeadlineGroupedOverview | null>(null)
const openExpanded = ref(false)
const expanded = ref<Record<string, boolean>>({})
const bulkNotice = ref('')

/** Endpoint jede na `payroll.submissions`; bez práva se panel nezobrazí vůbec. */
const allowed = computed(() => auth.canRead('payroll.submissions'))

interface PhaseSection {
  phase: PayrollDeadlinePanelPhase
  count: number
  groups: PayrollDeadlineGroup[]
}

const sections = computed<PhaseSection[]>(() => {
  const groups = overview.value?.groups ?? []
  return PANEL_PHASE_ORDER
    .map(phase => {
      const inPhase = groups.filter(group => group.phase === phase)
      return {
        phase,
        count: inPhase.reduce((sum, group) => sum + group.count, 0),
        groups: inPhase,
      }
    })
    .filter(section => section.groups.length > 0)
})

const overdueCount = computed(
  () => sections.value.find(section => section.phase === 'overdue')?.count ?? 0,
)

const isEmpty = computed(() => overview.value !== null && sections.value.length === 0)

async function load(): Promise<void> {
  if (!allowed.value) {
    loading.value = false
    return
  }
  loading.value = true
  loadError.value = ''
  try {
    overview.value = await payrollApi.deadlineGroups()
  } catch (error: unknown) {
    // Poslední načtená data jsou lepší informace než prázdno.
    loadError.value = apiErrorMessage(error, t('payroll.dashboard.deadlines.load_failed'))
  } finally {
    loading.value = false
  }
}

function onCompleted(count: number): void {
  bulkNotice.value = t('payroll.dashboard.deadlines.bulk_done', { n: count })
  void load()
}

/** Pramen jednou nad fází, ne u každého řádku — dokud se ve fázi neliší. */
function sectionSource(section: PhaseSection): PayrollDeadlineSource | null {
  const first = section.groups[0]?.source
  if (first === undefined) return null
  return section.groups.every(group => group.source === first) ? first : null
}

/** Skupina, kterou jde rozbalit; jednočlenná je rovnou odkaz. */
function isSingle(group: PayrollDeadlineGroup): boolean {
  return group.count === 1 && group.items.length === 1
}

function toggle(group: PayrollDeadlineGroup): void {
  expanded.value = { ...expanded.value, [group.key]: !expanded.value[group.key] }
}

/**
 * Otevřených lhůt bývá nejvíc a nic se u nich nestalo; osm skupin je vidět,
 * zbytek po rozbalení.
 */
const COLLAPSED_OPEN_GROUPS = 8

function visibleGroups(section: PhaseSection): PayrollDeadlineGroup[] {
  return section.phase === 'open' && !openExpanded.value
    ? section.groups.slice(0, COLLAPSED_OPEN_GROUPS)
    : section.groups
}

onMounted(load)

defineExpose({ reload: load })
</script>

<template>
  <section
    v-if="allowed"
    class="rounded-xl border bg-surface p-4 shadow-sm sm:p-6"
    :class="overdueCount > 0 ? 'border-danger-500/40' : 'border-neutral-200'"
    id="payroll-deadlines"
    data-test="payroll-deadlines"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="max-w-3xl">
        <h2 class="text-lg font-semibold text-neutral-900">
          {{ t('payroll.dashboard.deadlines.title') }}
        </h2>
        <p class="mt-1 text-sm text-neutral-500">
          {{ t('payroll.dashboard.deadlines.description') }}
        </p>
        <p v-if="overview" class="mt-1 text-xs text-neutral-400" data-test="payroll-deadlines-as-of">
          {{ t('payroll.dashboard.deadlines.as_of', {
            date: formatDate(overview.as_of),
            days: overview.horizon_days,
          }) }}
        </p>
      </div>
      <button
        type="button"
        :class="btnOutline('neutral')"
        :disabled="loading"
        data-test="payroll-deadlines-reload"
        @click="load"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.cycle" />
        </svg>
        {{ t('common.refresh') }}
      </button>
    </div>

    <div
      v-if="loadError"
      class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700"
      role="alert"
      data-test="payroll-deadlines-error"
    >
      <p>{{ loadError }}</p>
      <button
        type="button"
        :class="[btnOutline('danger'), 'mt-3']"
        data-test="payroll-deadlines-retry"
        @click="load"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.cycle" />
        </svg>
        {{ t('payroll.dashboard.deadlines.retry') }}
      </button>
    </div>

    <!-- Kostra jen při prvním načtení; přenačtení po hromadné akci nesmí
         zahodit rozbalený seznam ani hlášku o tom, co se nepovedlo. -->
    <div v-else-if="loading && !overview" class="mt-4 space-y-2" data-test="payroll-deadlines-loading">
      <div v-for="index in 3" :key="index" class="h-12 animate-pulse rounded-lg bg-neutral-100" />
    </div>

    <p
      v-else-if="isEmpty"
      class="mt-4 rounded-lg border border-dashed border-neutral-300 px-4 py-6 text-center text-sm text-neutral-500"
      data-test="payroll-deadlines-empty"
    >
      {{ t('payroll.dashboard.deadlines.empty') }}
    </p>

    <template v-else-if="overview">
      <ul class="mt-4 flex flex-wrap gap-2" data-test="payroll-deadlines-summary">
        <li
          v-for="section in sections"
          :key="`chip-${section.phase}`"
          class="rounded-full px-2.5 py-1 text-xs font-medium"
          :class="PHASE_BADGE[section.phase]"
          :data-test="`payroll-deadlines-chip-${section.phase}`"
        >
          {{ t(`payroll.dashboard.deadlines.phase.${section.phase}`) }}: {{ section.count }}
        </li>
      </ul>

      <p
        v-if="bulkNotice"
        class="mt-3 rounded-lg border border-success-500/40 bg-success-50 px-3 py-2 text-sm text-success-700"
        role="status"
        data-test="payroll-deadlines-bulk-notice"
      >
        {{ bulkNotice }}
      </p>

      <div class="mt-4 space-y-4">
        <section
          v-for="section in sections"
          :key="section.phase"
          class="rounded-lg border p-3"
          :class="PHASE_TONE[section.phase]"
          :data-test="`payroll-deadlines-group-${section.phase}`"
          :role="section.phase === 'overdue' ? 'alert' : undefined"
        >
          <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h3 class="text-xs font-semibold tracking-wide text-neutral-600 uppercase">
              {{ t(`payroll.dashboard.deadlines.phase.${section.phase}`) }}
              <span class="font-normal text-neutral-500">({{ section.count }})</span>
            </h3>
            <span
              v-if="sectionSource(section)"
              class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-600"
              :data-test="`payroll-deadlines-phase-source-${section.phase}`"
            >
              {{ t(`payroll.dashboard.deadlines.source.${sectionSource(section)}`) }}
            </span>
            <p
              v-if="section.phase === 'overdue'"
              class="ml-auto text-xs font-medium text-danger-700"
              data-test="payroll-deadlines-overdue-hint"
            >
              {{ t('payroll.dashboard.deadlines.overdue_hint') }}
            </p>
          </div>
          <p
            v-if="section.phase === UNDATED_PHASE"
            class="mt-1 text-xs text-neutral-600"
            data-test="payroll-deadlines-undated-hint"
          >
            {{ t('payroll.dashboard.deadlines.undated_hint') }}
          </p>

          <ul class="mt-2 divide-y divide-neutral-200 overflow-hidden rounded-md border border-neutral-200 bg-surface">
            <template v-for="group in visibleGroups(section)" :key="group.key">
              <!-- Jednočlenná skupina: celý řádek je skutečný odkaz. -->
              <li
                v-if="isSingle(group)"
                class="min-w-0"
                :data-test="`payroll-deadline-${group.items[0].reference}`"
              >
                <RouterLink
                  :to="itemLink(group.items[0])"
                  class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 transition hover:bg-neutral-50 focus-visible:ring-2 focus-visible:ring-payroll-500/40 focus-visible:outline-none"
                  :data-test="`payroll-deadline-link-${group.items[0].reference}`"
                >
                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium break-words text-neutral-900">{{ itemTitle(group.items[0]) }}</p>
                    <p
                      v-if="itemSubject(group.items[0]) || group.items[0].period"
                      class="text-xs break-words text-neutral-500"
                    >
                      {{ itemSubject(group.items[0]) }}
                      <template v-if="group.items[0].period">
                        {{ itemSubject(group.items[0]) ? '· ' : '' }}{{ formatPeriod(group.items[0].period) }}
                      </template>
                    </p>
                    <p v-if="amountLabel(group.items[0])" class="font-mono text-xs text-neutral-600">
                      {{ amountLabel(group.items[0]) }}
                    </p>
                  </div>
                  <span
                    v-if="!sectionSource(section)"
                    class="shrink-0 rounded-full bg-neutral-100 px-1.5 py-0.5 text-[0.6875rem] font-medium text-neutral-600"
                    :data-test="`payroll-deadline-source-${group.items[0].reference}`"
                  >
                    {{ t(`payroll.dashboard.deadlines.source.${group.source}`) }}
                  </span>
                  <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                    <span class="font-medium text-neutral-800">{{ formatDate(group.items[0].due_on) }}</span>
                    <span
                      class="rounded-full px-1.5 py-0.5 font-medium whitespace-nowrap"
                      :class="PHASE_BADGE[group.items[0].phase]"
                      :data-test="`payroll-deadline-due-${group.items[0].reference}`"
                    >
                      {{ dueLabel(group.items[0]) }}
                    </span>
                  </div>
                  <span class="sr-only">{{ t('payroll.dashboard.deadlines.resolve') }}</span>
                </RouterLink>
              </li>

              <!-- Víc položek: řádek skupiny s počtem, obsah po rozbalení. -->
              <li v-else class="min-w-0" :data-test="`payroll-deadline-group-${group.key}`">
                <button
                  type="button"
                  class="flex w-full min-w-0 cursor-pointer flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2 text-left transition hover:bg-neutral-50 focus-visible:ring-2 focus-visible:ring-payroll-500/40 focus-visible:outline-none"
                  :aria-expanded="expanded[group.key] ? 'true' : 'false'"
                  :data-test="`payroll-deadline-group-toggle-${group.key}`"
                  @click="toggle(group)"
                >
                  <svg
                    class="h-4 w-4 shrink-0 text-neutral-400 transition-transform"
                    :class="expanded[group.key] ? 'rotate-180' : ''"
                    viewBox="0 0 24 24"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    aria-hidden="true"
                  >
                    <path :d="ICONS.chevron" />
                  </svg>
                  <div class="min-w-0 flex-1">
                    <p class="text-sm font-medium break-words text-neutral-900">
                      {{ titleFor(group.source, group.title) }}
                    </p>
                    <p class="text-xs text-neutral-500" :data-test="`payroll-deadline-group-count-${group.key}`">
                      {{ groupCountLabel(group) }}
                    </p>
                  </div>
                  <span
                    v-if="!sectionSource(section)"
                    class="shrink-0 rounded-full bg-neutral-100 px-1.5 py-0.5 text-[0.6875rem] font-medium text-neutral-600"
                  >
                    {{ t(`payroll.dashboard.deadlines.source.${group.source}`) }}
                  </span>
                  <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                    <span v-if="group.oldest_due_on" class="font-medium text-neutral-800">{{ formatDate(group.oldest_due_on) }}</span>
                    <span
                      class="rounded-full px-1.5 py-0.5 font-medium whitespace-nowrap"
                      :class="PHASE_BADGE[group.phase]"
                      :data-test="`payroll-deadline-group-due-${group.key}`"
                    >
                      {{ groupDueLabel(group) }}
                    </span>
                  </div>
                </button>

                <template v-if="expanded[group.key]">
                  <PayrollDeadlineGroupPeople
                    v-if="group.per_person"
                    :group="group"
                    :horizon-days="overview.horizon_days"
                    @completed="onCompleted"
                  />
                  <ul v-else class="divide-y divide-neutral-200 border-t border-neutral-200 bg-neutral-50/60">
                    <li
                      v-for="item in group.items"
                      :key="item.reference"
                      class="min-w-0"
                      :data-test="`payroll-deadline-${item.reference}`"
                    >
                      <RouterLink
                        :to="itemLink(item)"
                        class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1 py-2 pr-3 pl-10 transition hover:bg-neutral-100 focus-visible:ring-2 focus-visible:ring-payroll-500/40 focus-visible:outline-none"
                        :data-test="`payroll-deadline-link-${item.reference}`"
                      >
                        <div class="min-w-0 flex-1">
                          <p class="text-sm break-words text-neutral-900">
                            {{ itemSubject(item) || itemTitle(item) }}
                            <template v-if="item.period">
                              · {{ formatPeriod(item.period) }}
                            </template>
                          </p>
                          <p v-if="amountLabel(item)" class="font-mono text-xs text-neutral-600">
                            {{ amountLabel(item) }}
                          </p>
                        </div>
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 text-xs">
                          <span class="font-medium text-neutral-800">{{ formatDate(item.due_on) }}</span>
                          <span
                            class="rounded-full px-1.5 py-0.5 font-medium whitespace-nowrap"
                            :class="PHASE_BADGE[item.phase]"
                            :data-test="`payroll-deadline-due-${item.reference}`"
                          >
                            {{ dueLabel(item) }}
                          </span>
                        </div>
                        <span class="sr-only">{{ t('payroll.dashboard.deadlines.resolve') }}</span>
                      </RouterLink>
                    </li>
                  </ul>
                </template>
              </li>
            </template>
          </ul>

          <button
            v-if="section.phase === 'open' && section.groups.length > COLLAPSED_OPEN_GROUPS"
            type="button"
            class="mt-2 cursor-pointer text-xs font-medium text-primary-700 underline decoration-dotted underline-offset-2"
            data-test="payroll-deadlines-toggle-open"
            @click="openExpanded = !openExpanded"
          >
            {{ openExpanded
              ? t('payroll.dashboard.deadlines.collapse')
              : t('payroll.dashboard.deadlines.expand', {
                count: section.groups.length - COLLAPSED_OPEN_GROUPS,
              }) }}
          </button>
        </section>
      </div>
    </template>
  </section>
</template>
