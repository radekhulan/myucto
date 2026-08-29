<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollApi, type PayrollAgendaSummaryItem } from '@/api/payroll'
import { BTN_DISABLED_NOTE, ICONS, MENU_ICON, disabledTitle } from '@/components/ui/buttonStyles'
import { formatDate, formatMoneyMinor } from '@/composables/useFormat'
import { useAuthStore } from '@/stores/auth'
import {
  payrollAgendaLabelKey,
  payrollCardAgendas,
  type PayrollAgendaDefinition,
  type PayrollAgendaLinkKey,
} from './payrollAgendaLinks'

/**
 * Navazující agendy pracovního vztahu — rozcestník a souhrn v jednom.
 *
 * Why: karta zaměstnance uměla vztah založit, upravit a ukončit, ale o tom, co
 * se k němu dál pořizuje (docházka, nepřítomnosti, cesty, srážky, exekuce,
 * dokumenty), mlčela. Uživatel proto odešel do menu, otevřel agendu a v ní
 * člověka hledal znovu — a jestli v agendě vůbec něco je, se dozvěděl až tam.
 *
 * Načítá se AŽ tady, tedy jen pro rozbalený vztah otevřené osoby. Seznam lidí
 * tenhle dotaz nepouští vůbec; jinak by přehled o padesáti zaměstnancích udělal
 * padesát požadavků na data, na která se nikdo nedívá.
 */
const props = defineProps<{
  employmentId: number
  employeeId: number
}>()

const { t } = useI18n()
const auth = useAuthStore()
const loading = ref(true)
/*
 * Selhalo načtení? Pak o agendách nevíme NIC — a to je něco jiného než „nic
 * v nich není". Bez tohohle příznaku by karta tvrdila prázdno, které lže.
 */
const failed = ref(false)
const items = ref<PayrollAgendaSummaryItem[]>([])

const summaryByKey = computed(() => {
  const map = new Map<PayrollAgendaLinkKey, PayrollAgendaSummaryItem>()
  for (const item of items.value) map.set(item.key, item)
  return map
})

interface AgendaRow {
  agenda: PayrollAgendaDefinition
  summary: PayrollAgendaSummaryItem | undefined
  /** `false` = uživatel na agendu nemá právo; dlaždice zůstane, ale nevede nikam. */
  allowed: boolean
}

/**
 * JEDEN seznam, ve kterém jsou VŠECHNY agendy — i ty prázdné.
 *
 * Rozhodnutí (změna oproti první verzi): dřív se vypisovaly jen agendy, ve
 * kterých něco bylo, a prázdné se slily do věty „zatím nic: X, Y, Z". Vypadalo
 * to úsporně, ale nešlo v tom sjet očima dolů a hlavně: prázdná agenda je
 * přesně ta, do které účetní jde něco ZALOŽIT. Schovat ji do věty znamená
 * schovat nejčastější cíl kliknutí. Nula je proto řádný řádek, jen tlumený.
 *
 * Pořadí drží katalog (a ten zrcadlí pořadí na serveru), aby přehled vypadal
 * u každého člověka stejně a dal se zapamatovat.
 */
const rows = computed<AgendaRow[]>(() => payrollCardAgendas.map(agenda => ({
  agenda,
  summary: summaryByKey.value.get(agenda.key),
  allowed: auth.canRead(agenda.permission),
})))

/*
 * Agenda bez oprávnění se NESMÍ tvářit jako dostupná: server pro ni počet
 * vůbec nevrací (počet exekučních případů je sám o sobě citlivý údaj) a odkaz
 * by routa stejně zahodila na homepage. Dlaždice proto zůstane v seznamu —
 * jinak by se „nemáš právo" nedalo odlišit od „tahle agenda neexistuje" —, ale
 * nevede nikam a místo počtu má pomlčku.
 */
const deniedCount = computed(() => rows.value.filter(row => !row.allowed).length)
const deniedReason = computed(() => t('payroll.agendas.no_permission'))

/** Počet záznamů; `null` = nevíme (chybí oprávnění, nebo souhrn spadl). */
function countOf(row: AgendaRow): number | null {
  if (!row.allowed || failed.value) return null
  return row.summary?.count ?? null
}

/**
 * Co k počtu ještě přidat — nejnovější datum a částka, kde dávají smysl.
 * Prázdné agendě chybí obojí, takže druhý řádek dlaždice úplně vypadne.
 */
function detailOf(summary: PayrollAgendaSummaryItem | undefined): string {
  if (summary === undefined || summary.count === 0) return ''
  const parts: string[] = []
  if (summary.last_on !== null) {
    parts.push(t('payroll.agendas.last_on', { date: formatDate(summary.last_on) }))
  }
  if (summary.amount_minor !== null) {
    parts.push(formatMoneyMinor(summary.amount_minor))
  }
  return parts.join(' · ')
}

/** Tooltip nad číslem — samotná „3" v rohu dlaždice neřekne, čeho tři. */
function countTitle(row: AgendaRow): string | undefined {
  const count = countOf(row)
  return count === null ? undefined : t('payroll.agendas.count', { count }, count)
}

async function load() {
  loading.value = true
  failed.value = false
  try {
    const summary = await payrollApi.employmentAgendaSummary(props.employmentId)
    items.value = summary.agendas
  } catch {
    failed.value = true
    items.value = []
  } finally {
    loading.value = false
  }
}

watch(() => props.employmentId, load)
onMounted(load)

const TILE = 'flex h-full min-w-0 flex-col gap-0.5 rounded-md border px-3 py-2 text-xs'
</script>

<template>
  <!--
    Panel nese mzdový akcent, ne neutrální rámeček. Sedí na kartě NAHOŘE mezi
    ostatními šedými sekcemi a bez odlišení splynul s „historií podmínek" —
    přitom je to jediné místo, odkud se u člověka někam jde.
  -->
  <section class="rounded-lg border border-payroll-500/30 bg-payroll-50/60 p-3" data-test="employment-agendas">
    <div class="min-w-0">
      <h4 class="text-sm font-semibold text-neutral-900">{{ t('payroll.agendas.title') }}</h4>
      <p class="mt-0.5 text-xs text-neutral-500">{{ t('payroll.agendas.subtitle') }}</p>
    </div>

    <!--
      Načítání má vlastní stav, protože prázdná mřížka a mřížka se samými
      nulami vypadají stejně — a jedna z nich lže.
    -->
    <div v-if="loading" class="mt-3 grid grid-cols-1 gap-2 md:grid-cols-2 xl:grid-cols-3">
      <div v-for="index in 6" :key="index" class="h-12 animate-pulse rounded-md bg-neutral-100" />
    </div>

    <template v-else>
      <p
        v-if="failed"
        class="mt-3 rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-800"
        data-test="employment-agendas-failed"
      >
        {{ t('payroll.agendas.load_failed') }}
      </p>

      <!--
        Mřížka, ne sloupec: agend je třináct a pod sebou by z přehledu udělaly
        stránku, kterou je nutné odscrollovat. Tři sloupce na širokém monitoru
        (xl ≈ 425 px na dlaždici), dva od tabletu, jeden na mobilu.
        Zlom na `md`, ne `sm`: druhý řádek dlaždice nese datum a částku
        („poslední 01. 08. 2026 · 4 500,00 Kč"), což je přes 200 px — při dvou
        sloupcích už od 640 px by se popisek agendy začal ořezávat. Ověřeno na
        334 px (jeden sloupec, mobil): nic se neořezává ani nescrolluje vodorovně.
        Odkazy jsou <a>, takže se seznam projde tabulátorem bez myši.
      -->
      <ul
        class="mt-3 grid grid-cols-1 items-stretch gap-2 md:grid-cols-2 xl:grid-cols-3"
        data-test="employment-agenda-summary"
      >
        <li v-for="row in rows" :key="row.agenda.key" :data-test="`employment-agenda-${row.agenda.key}`">
          <component
            :is="row.allowed ? 'RouterLink' : 'div'"
            v-bind="row.allowed ? { to: row.agenda.to(props.employmentId, props.employeeId) } : {
              'aria-disabled': 'true',
              title: disabledTitle(true, deniedReason),
            }"
            :class="[TILE, row.allowed
              ? 'border-neutral-200 bg-surface transition-colors hover:border-payroll-400 hover:bg-payroll-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-payroll-600'
              : 'cursor-not-allowed border-dashed border-neutral-200 bg-neutral-50 opacity-70']"
          >
            <span class="flex items-center gap-2">
              <svg
                :class="['h-4 w-4 shrink-0', row.allowed ? MENU_ICON[row.agenda.variant] : 'text-neutral-400']"
                fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8" aria-hidden="true"
              >
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS[row.agenda.icon]" />
              </svg>
              <span class="min-w-0 flex-1 truncate font-medium text-neutral-800">
                {{ t(payrollAgendaLabelKey(row.agenda.key)) }}
              </span>
              <!--
                Nula zůstává číslem, jen tlumeným — pomlčka je vyhrazená pro
                „nevíme" (chybí právo nebo spadl souhrn). Splést to dohromady by
                znamenalo tvrdit prázdno tam, kde jsme se nezeptali.
              -->
              <span
                :class="['shrink-0 tabular-nums', countOf(row) === null ? 'text-neutral-300'
                  : countOf(row) === 0 ? 'text-neutral-400' : 'font-semibold text-neutral-900']"
                :title="countTitle(row)"
                :data-test="`employment-agenda-count-${row.agenda.key}`"
              >{{ countOf(row) === null ? '–' : countOf(row) }}</span>
            </span>
            <span v-if="row.allowed && detailOf(row.summary) !== ''" class="truncate pl-6 text-neutral-500">
              {{ detailOf(row.summary) }}
            </span>
          </component>
        </li>
      </ul>

      <!--
        Viditelná věta, ne jen `title`: tooltip se na dotykovém displeji nedá
        vyvolat vůbec a u prvku s `aria-disabled` ho přeskočí i čtečka.
      -->
      <p
        v-if="deniedCount > 0"
        :class="['mt-2', BTN_DISABLED_NOTE]"
        data-test="employment-agendas-denied"
      >
        {{ deniedReason }}
      </p>
    </template>
  </section>
</template>
