<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import {
  payrollApi,
  type PayrollDeadlineItem,
  type PayrollDeadlineOverview,
  type PayrollDeadlinePhase,
  type PayrollDeadlineSource,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatDate, formatMoneyMinor, formatPeriod } from '@/composables/useFormat'

/**
 * Co je po termínu a co hoří — jediný panel nad všemi třemi prameny.
 *
 * Why: `GET /api/payroll/deadlines` skládá podání, odvody i položky checklistu
 * do jednoho seznamu s fázemi, ale dosud ho nikdo nevolal. Zmeškaný odvod se
 * tedy poznal až od ČSSZ. Panel je čtecí — nic neodklikává, jen ukazuje a
 * proklikne tam, kde se to řeší.
 *
 * ## Proč zrovna takhle
 *
 * **Seskupeno podle fáze, ne podle pramene.** Účetní neřeší „podání vs. odvod",
 * řeší „co je pozdě". Pramen je uvnitř fáze skoro vždy jeden a týž, takže se
 * píše JEDNOU nad skupinu; do dlaždic klesne jen tehdy, když se ve fázi liší.
 * Třináct stejných štítků „Lhůta u člověka" pod sebou nenese žádnou informaci,
 * jen zabírá řádek na každé dlaždici.
 *
 * **Dlaždice místo celošířkových karet.** Prodlení bývá tucet položek naráz;
 * jako seznam to je nekonečné rolování a na širokém monitoru zůstane vpravo
 * prázdno. Mřížka srovná stejný obsah do čtvrtiny výšky.
 *
 * **Celá dlaždice je odkaz** (`RouterLink`, tedy skutečné `<a href>`), ne
 * samostatné „Vyřešit" v patičce: cíl je několikanásobně větší, ubyde řádek a
 * prostřední klik i klávesnice fungují jako u každého jiného odkazu.
 *
 * **`overdue` má vlastní rám a jde první**, i když je termín starý — jinak by
 * zapadl mezi desítkami otevřených lhůt v horizontu 45 dnů.
 *
 * **`open` je sbalené.** Otevřených lhůt bývá nejvíc a je to zároveň jediná
 * skupina, kde se nic nestalo; rozbalené by zavalila to podstatné.
 *
 * **Prázdno neřve.** Firma bez zmeškaného termínu dostane jednu klidnou větu,
 * ne prázdnou tabulku ani varovný rámeček.
 */

const { t, te } = useI18n()
const auth = useAuthStore()

const loading = ref(true)
const loadError = ref('')
const overview = ref<PayrollDeadlineOverview | null>(null)
const openExpanded = ref(false)

/** Endpoint jede na `payroll.submissions`; bez práva se panel nezobrazí vůbec. */
const allowed = computed(() => auth.canRead('payroll.submissions'))

/**
 * Pořadí je pořadím naléhavosti. `awaiting_result` a `action_required` posílá
 * posuzovač podání navíc — nespadnou do žádné z hlavních skupin, ale ztratit se
 * nesmí, takže mají vlastní místo na konci.
 */
const PHASE_ORDER: PayrollDeadlinePhase[] = [
  'overdue',
  'due_today',
  'due_soon',
  'action_required',
  'awaiting_result',
  'open',
]

const PHASE_TONE: Record<PayrollDeadlinePhase, string> = {
  overdue: 'border-danger-500/40 bg-danger-50',
  due_today: 'border-warning-500/40 bg-warning-50',
  due_soon: 'border-warning-500/25 bg-warning-50/50',
  action_required: 'border-warning-500/25 bg-warning-50/50',
  awaiting_result: 'border-neutral-200 bg-neutral-50',
  open: 'border-neutral-200 bg-neutral-50',
}

const PHASE_BADGE: Record<PayrollDeadlinePhase, string> = {
  overdue: 'bg-danger-50 text-danger-700',
  due_today: 'bg-warning-50 text-warning-800',
  due_soon: 'bg-warning-50 text-warning-700',
  action_required: 'bg-warning-50 text-warning-700',
  awaiting_result: 'bg-neutral-100 text-neutral-600',
  open: 'bg-neutral-100 text-neutral-600',
}

interface PhaseGroup {
  phase: PayrollDeadlinePhase
  items: PayrollDeadlineItem[]
}

const groups = computed<PhaseGroup[]>(() => {
  const items = overview.value?.items ?? []
  return PHASE_ORDER
    .map(phase => ({ phase, items: items.filter(item => item.phase === phase) }))
    .filter(group => group.items.length > 0)
})

const overdueCount = computed(
  () => groups.value.find(group => group.phase === 'overdue')?.items.length ?? 0,
)

/** Souhrnné dlaždice jen za fáze, které opravdu něco obsahují. */
const summaryChips = computed(() =>
  groups.value.map(group => ({ phase: group.phase, count: group.items.length })),
)

const isEmpty = computed(() => overview.value !== null && groups.value.length === 0)

async function load(): Promise<void> {
  if (!allowed.value) {
    loading.value = false
    return
  }
  loading.value = true
  loadError.value = ''
  try {
    overview.value = await payrollApi.deadlines()
  } catch (error: unknown) {
    // Poslední načtená data jsou lepší informace než prázdno, proto se
    // `overview` nevynuluje; chyba se řekne větou, ne mizícím toastem.
    loadError.value = apiErrorMessage(error, t('payroll.dashboard.deadlines.load_failed'))
  } finally {
    loading.value = false
  }
}

/**
 * Název řádku. Prameny mají tři různé číselníky a žádný z nich není v i18n
 * úplný (agendy podání přibývají), takže se nepřeložený kód ukáže tak, jak je —
 * to je pořád srozumitelnější než prázdno nebo cesta k překladovému klíči.
 */
function itemTitle(item: PayrollDeadlineItem): string {
  const path = item.source === 'submission'
    ? `payroll.submissions.statutory.agenda.${item.title}`
    : item.source === 'levy'
      ? `payroll.payments.kind.${item.title}`
      : item.source === 'registration_change'
        ? `payroll.people.registration.changes.duty_short.${item.title}`
        : item.source === 'tax_statement'
          ? `payroll.dashboard.deadlines.tax_statement.form.${item.title}`
          : `payroll.people.checklist.${item.title}`
  return te(path) ? t(path) : item.title
}

/**
 * Druhý řádek dlaždice. U ostatních pramenů je to jméno člověka nebo příjemce
 * odvodu; roční vyúčtování žádné nemá, zato má zdaňovací období — a bez něj by
 * dvě po sobě jdoucí lhůty za dva různé roky vypadaly stejně. `period` z BE se
 * použít nedá: přehled ho formátuje jako MĚSÍC.
 */
function itemSubject(item: PayrollDeadlineItem): string {
  if (item.source === 'tax_statement' && item.statement_year !== undefined) {
    return t('payroll.dashboard.deadlines.tax_statement.subject', {
      year: item.statement_year,
    })
  }
  return item.subject
}

/**
 * Kam se to řeší. Vědomě se NEpoužívá `item.path` ze serveru — pojmenované routy
 * přežijí přesun cesty a stránka lidí umí rovnou konečný tvar s dotazem, takže
 * odkaz nemusí projít přesměrováním. Serverová `path` už ale míří na existující
 * `/payroll/people/{id}` (dřív vracela neexistující `/payroll/employees/{id}`),
 * takže na ni může navázat i jiný konzument.
 */
function itemLink(item: PayrollDeadlineItem): RouteLocationRaw {
  if (item.source === 'levy') return { name: 'payroll-payments' }
  if (item.source === 'checklist' && item.employee_id !== undefined) {
    return { name: 'payroll-people', query: { person: String(item.employee_id) } }
  }
  if (item.source === 'checklist') return { name: 'payroll-people' }
  // Registrační povinnost z detekce se plní na kartě člověka, kde je
  // tlačítko „Ohlásit změnu" — proklik na přehled podání by účetní poslal
  // o obrazovku vedle.
  if (item.source === 'registration_change' && item.employee_id !== undefined) {
    return { name: 'payroll-people', query: { person: String(item.employee_id) } }
  }
  // Vyúčtování se sestavuje v panelu na mzdovém rozcestníku, ne na vlastní
  // routě; kotva doveze účetní rovnou k němu, `year` mu rovnou nastaví ten
  // rok, jehož lhůta hoří — jinak by panel nabídl svůj výchozí a účetní by
  // stáhla XML za jiné období, než na které klikla.
  if (item.source === 'tax_statement') {
    return {
      name: 'payroll-dashboard',
      query: item.statement_year === undefined
        ? {}
        : { taxStatementYear: String(item.statement_year) },
      hash: '#payroll-tax-statement',
    }
  }
  return { name: 'payroll-submissions' }
}

/** „Po termínu o 3 dny" / „Zbývají 3 dny" — počet dnů má vždy znaménko od BE. */
function dueLabel(item: PayrollDeadlineItem): string {
  const days = Math.abs(item.days_to_due)
  if (item.days_to_due < 0) return t('payroll.dashboard.deadlines.overdue_by', days)
  if (item.days_to_due === 0) return t('payroll.dashboard.deadlines.due_today_label')
  return t('payroll.dashboard.deadlines.due_in', days)
}

function amountLabel(item: PayrollDeadlineItem): string {
  if (item.remaining_minor === undefined) return ''
  return t('payroll.dashboard.deadlines.remaining', {
    amount: formatMoneyMinor(item.remaining_minor),
  })
}

/**
 * Pramen společný celé fázi, nebo `null`, když se ve fázi liší.
 *
 * Posuzuje se nad CELOU skupinou, ne nad viditelným výřezem: nadpis popisuje
 * skupinu, takže sbalení „Otevřených" nesmí změnit, co je nad nimi napsané.
 */
function groupSource(group: PhaseGroup): PayrollDeadlineSource | null {
  const first = group.items[0]?.source
  if (first === undefined) return null

  return group.items.every(item => item.source === first) ? first : null
}

/** Štítek pramene v dlaždici má smysl jen tam, kde ho nenese už nadpis fáze. */
function showsItemSource(group: PhaseGroup): boolean {
  return groupSource(group) === null
}

/**
 * Kolik otevřených lhůt je vidět před rozbalením. Osm = dvě plné řady mřížky
 * na širokém monitoru; tři (původní počet u celošířkových karet) by ve
 * čtyřsloupcové mřížce nechávaly v řadě díru.
 */
const COLLAPSED_OPEN_ITEMS = 8

function isCollapsed(phase: PayrollDeadlinePhase): boolean {
  return phase === 'open' && !openExpanded.value
}

function visibleItems(group: PhaseGroup): PayrollDeadlineItem[] {
  return isCollapsed(group.phase)
    ? group.items.slice(0, COLLAPSED_OPEN_ITEMS)
    : group.items
}

onMounted(load)

defineExpose({ reload: load })
</script>

<template>
  <section
    v-if="allowed"
    class="rounded-xl border bg-surface p-4 shadow-sm sm:p-6"
    :class="overdueCount > 0 ? 'border-danger-500/40' : 'border-neutral-200'"
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

    <div v-else-if="loading" class="mt-4 space-y-2" data-test="payroll-deadlines-loading">
      <div v-for="index in 3" :key="index" class="h-12 animate-pulse rounded-lg bg-neutral-100" />
    </div>

    <!--
      Prázdno tu není chyba ani úspěch k oslavě — je to normální stav. Jedna
      klidná věta, žádný rámeček, žádná barva.
    -->
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
          v-for="chip in summaryChips"
          :key="`chip-${chip.phase}`"
          class="rounded-full px-2.5 py-1 text-xs font-medium"
          :class="PHASE_BADGE[chip.phase]"
          :data-test="`payroll-deadlines-chip-${chip.phase}`"
        >
          {{ t(`payroll.dashboard.deadlines.phase.${chip.phase}`) }}: {{ chip.count }}
        </li>
      </ul>

      <div class="mt-4 space-y-4">
        <section
          v-for="group in groups"
          :key="group.phase"
          class="rounded-lg border p-3"
          :class="PHASE_TONE[group.phase]"
          :data-test="`payroll-deadlines-group-${group.phase}`"
          :role="group.phase === 'overdue' ? 'alert' : undefined"
        >
          <!--
            Nadpis skupiny je lehčí než dřív (verzálky místo tučného nadpisu):
            pod ním je mřížka dlaždic a dva výrazné stupně nad sebou se perou
            o pozornost, kterou má mít obsah.
          -->
          <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
            <h3 class="text-xs font-semibold tracking-wide text-neutral-600 uppercase">
              {{ t(`payroll.dashboard.deadlines.phase.${group.phase}`) }}
              <span class="font-normal text-neutral-500">({{ group.items.length }})</span>
            </h3>
            <!--
              Pramen jednou nad skupinou, ne třináctkrát v dlaždicích. Mizí sám,
              jakmile fáze míchá podání, odvody a lhůty u lidí.
            -->
            <span
              v-if="groupSource(group)"
              class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs font-medium text-neutral-600"
              :data-test="`payroll-deadlines-phase-source-${group.phase}`"
            >
              {{ t(`payroll.dashboard.deadlines.source.${groupSource(group)}`) }}
            </span>
            <p
              v-if="group.phase === 'overdue'"
              class="ml-auto text-xs font-medium text-danger-700"
              data-test="payroll-deadlines-overdue-hint"
            >
              {{ t('payroll.dashboard.deadlines.overdue_hint') }}
            </p>
          </div>

          <!--
            Zlomy jsou odvozené od nejužšího místa dlaždice — patičky
            „datum + odznak prodlení", která potřebuje asi 17 rem, aby zůstala
            na jednom řádku. Ve sloupci obsahu dashboardu tomu vychází ~300 px
            i při čtyřech sloupcích na 2xl, takže se čtyřka vejde bez zalomení;
            pod `sm` je jeden sloupec a nikde nevzniká vodorovné rolování.
          -->
          <ul class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
            <li
              v-for="item in visibleItems(group)"
              :key="item.reference"
              class="min-w-0"
              :data-test="`payroll-deadline-${item.reference}`"
            >
              <!--
                Klikací je celá dlaždice. `RouterLink` je pravý odkaz, takže
                prostřední klik, Ctrl+klik i Tab fungují bez dalšího zařizování;
                pořadí fokusu je pořadí v mřížce.
              -->
              <RouterLink
                :to="itemLink(item)"
                class="flex h-full min-w-0 flex-col rounded-md border border-neutral-200 bg-surface p-2.5 transition hover:border-payroll-500/60 hover:shadow-sm focus-visible:ring-2 focus-visible:ring-payroll-500/40 focus-visible:outline-none"
                :data-test="`payroll-deadline-link-${item.reference}`"
              >
                <div class="flex min-w-0 items-start justify-between gap-2">
                  <p class="min-w-0 text-sm font-medium break-words text-neutral-900">
                    {{ itemTitle(item) }}
                  </p>
                  <span
                    v-if="showsItemSource(group)"
                    class="shrink-0 rounded-full bg-neutral-100 px-1.5 py-0.5 text-[0.6875rem] font-medium text-neutral-600"
                    :data-test="`payroll-deadline-source-${item.reference}`"
                  >
                    {{ t(`payroll.dashboard.deadlines.source.${item.source}`) }}
                  </span>
                </div>
                <p class="mt-0.5 text-xs break-words text-neutral-500">
                  {{ itemSubject(item) }}
                  <template v-if="item.period"> · {{ formatPeriod(item.period) }}</template>
                </p>
                <p v-if="amountLabel(item)" class="mt-0.5 font-mono text-xs text-neutral-600">
                  {{ amountLabel(item) }}
                </p>

                <!-- `mt-auto` drží patičky zarovnané i u různě dlouhých názvů. -->
                <div class="mt-auto flex flex-wrap items-center gap-x-2 gap-y-1 pt-2 text-xs">
                  <span class="font-medium text-neutral-800">{{ formatDate(item.due_on) }}</span>
                  <span
                    class="rounded-full px-1.5 py-0.5 font-medium"
                    :class="PHASE_BADGE[item.phase]"
                    :data-test="`payroll-deadline-due-${item.reference}`"
                  >
                    {{ dueLabel(item) }}
                  </span>
                </div>

                <!--
                  Viditelné „Vyřešit" zmizelo s klikací dlaždicí, ale čtečka
                  musí pořád vědět, co odkaz udělá.
                -->
                <span class="sr-only">{{ t('payroll.dashboard.deadlines.resolve') }}</span>
              </RouterLink>
            </li>
          </ul>

          <button
            v-if="group.phase === 'open' && group.items.length > COLLAPSED_OPEN_ITEMS"
            type="button"
            class="mt-2 cursor-pointer text-xs font-medium text-primary-700 underline decoration-dotted underline-offset-2"
            data-test="payroll-deadlines-toggle-open"
            @click="openExpanded = !openExpanded"
          >
            {{ openExpanded
              ? t('payroll.dashboard.deadlines.collapse')
              : t('payroll.dashboard.deadlines.expand', {
                count: group.items.length - COLLAPSED_OPEN_ITEMS,
              }) }}
          </button>
        </section>
      </div>
    </template>
  </section>
</template>
