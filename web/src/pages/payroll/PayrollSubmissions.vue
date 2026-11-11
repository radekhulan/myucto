<script setup lang="ts">
import { formatDateTime } from '@/composables/useFormat'
import { computed, onMounted, ref, watch } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import {
  payrollApi,
  type PayrollEmployerSettings,
  type PayrollRegzelEnvironment,
  type PayrollRegzelProfile,
  type PayrollRegzelSnapshot,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import EnvironmentSwitch from '@/components/ui/EnvironmentSwitch.vue'
import { useSubmissionEnvironment } from '@/composables/useSubmissionEnvironment'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import PayrollEldpPanel from './PayrollEldpPanel.vue'
import PayrollDiscountIntentsPanel from './PayrollDiscountIntentsPanel.vue'
import PayrollSicknessCasesPanel from './PayrollSicknessCasesPanel.vue'
import PayrollHealthNotificationPanel from './PayrollHealthNotificationPanel.vue'
import PayrollSubmissionInboxPanel from './PayrollSubmissionInboxPanel.vue'
import PayrollSubmissionOverviewPanel from './PayrollSubmissionOverviewPanel.vue'
import PayrollMonthlyChecklistPanel from './PayrollMonthlyChecklistPanel.vue'
import PayrollStatutoryObligationsPanel from './PayrollStatutoryObligationsPanel.vue'
import PayrollSigningCertificatePanel from './PayrollSigningCertificatePanel.vue'
import PayrollTransportHistoryPanel from './PayrollTransportHistoryPanel.vue'
import PayrollExternalJmhzSubmissionsPanel from './PayrollExternalJmhzSubmissionsPanel.vue'
import PayrollSubmissionQueuePanel from './PayrollSubmissionQueuePanel.vue'
import PayrollRegistrationCompletionPanel from './PayrollRegistrationCompletionPanel.vue'
import PayrollRegistrationA1BatchPanel from './PayrollRegistrationA1BatchPanel.vue'
import TaxSubmissions from '@/pages/reports/TaxSubmissions.vue'
import { payrollWorkingPeriod } from './payrollComponentsUi'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'

type SubmissionTab =
  'monthly' | 'queue' | 'transport' | 'regzel' | 'registration_a1' | 'registration_completion' | 'jmhz'
  | 'discount_intents' | 'sickness' | 'eldp'
  | 'health' | 'tax_statements' | 'statutory' | 'other' | 'inbox' | 'certificate'

const { t } = useI18n()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
/*
 * JEDNO období pro celou stránku Podání — všechny záložky i sekce (Měsíc,
 * JMHZ, zdravotní pojišťovny včetně HOZ, nemocenské, ELDP). Drží se v adrese
 * (`?period=RRRR-MM`), takže odkaz z karty osoby i obnovení stránky ho zachová.
 * Bez období v adrese se otevře nejstarší měsíc s nesplněným měsíčním hlášením
 * (`suggested_period` Měsíčního přehledu), jinak předchozí měsíc — mzdy se
 * podávají zpětně. Oznámení HOZ s lhůtou mimo zvolené období ukáže akční
 * karta jako samostatné řádky, ne druhým výběrem období.
 */
const routedQueryPeriod = route.query.period
const routedPeriod = typeof routedQueryPeriod === 'string' && /^\d{4}-(0[1-9]|1[0-2])$/.test(routedQueryPeriod)
  ? routedQueryPeriod
  : null
const overviewPeriod = ref(routedPeriod ?? payrollWorkingPeriod())
/*
 * „Co mám tenhle měsíc udělat" je ta úplně první otázka, se kterou účetní na
 * stránku přichází — proto je Měsíční přehled výchozí záložka. Dřív tu byl
 * „Stav odeslání" (co jsem odeslal a jak to dopadlo), ale to zodpovídá jen
 * ČÁST otázky (agendy s podáním) a nechává účetní hledat zbytek (odvody,
 * lhůty u lidí, ruční agendy) po deseti dalších záložkách. Ten panel dál
 * existuje — jde na něj `transport` — jen už neotvírá stránku jako první.
 */
const activeTab = ref<SubmissionTab>('monthly')
// Certifikát je poslední záložka, ale vlastní: podepisuje se jím REGZEL i JMHZ,
// takže nepatří pod žádné jednotlivé hlášení.
// ELDP stojí hned za JMHZ: od roku 2026 ho ČSSZ sestavuje z měsíčního
// hlášení sama, takže samostatný evidenční list je navazující a přechodná
// agenda, ne konkurenční hlášení.
// Zdravotní agenda je v jedné záložce, ale panel odděluje měsíční přehled
// o platbě od oznamovací povinnosti z § 10, která běží na osm dnů od
// skutečnosti. Uživatel tak nemá dvě konkurenční obrazovky nad stejnými daty.
// Záměr uplatňovat slevu stojí hned za JMHZ, protože je jeho podmínkou: sleva
// se sice vykazuje v měsíčním hlášení, ale nárok na ni zakládá tohle podání.
// „Ostatní" je záchytná záložka pro skupinu `other`: `agenda_code` povinnosti
// je volný text, takže se do přehledu může dostat kód, který server neumí
// zařadit. Bez téhle záložky by taková povinnost nebyla vidět NIKDE — panely
// filtrují skupinu na serveru, takže by ji ani jeden z nich nenačetl.
// „K odeslání" stojí hned za měsíčním přehledem: přehled říká, CO se má
// tenhle měsíc udělat, fronta odpovídá na navazující „a co z toho mám
// připravené a ještě to neodešlo" — napříč agendami i zaměstnanci. Bez ní
// se ta odpověď skládala z pěti různých obrazovek.
// Přihlášky (A1) a dohlášení údajů (A3) stojí hned za registrací
// zaměstnavatele: všechno je registrační agenda ČSSZ mimo měsíční hlášení.
const tabs: SubmissionTab[] = [
  'monthly', 'queue', 'transport', 'regzel', 'registration_a1', 'registration_completion', 'jmhz',
  'discount_intents', 'sickness', 'eldp',
  'health', 'tax_statements', 'statutory', 'other', 'inbox', 'certificate',
]
/*
 * `null` = počet neznáme (načtení odznaku selhalo), ne „nula nevyřízených".
 * Číslo 0 tu dřív zastupovalo obojí, takže po výpadku odznak tiše zmizel
 * a záložka Inbox vypadala vyřízeně. Typ to teď nedovolí splést.
 */
const inboxOpenCount = ref<number | null>(null)
const loading = ref(true)
const preparing = ref(false)
const downloadingId = ref<number | null>(null)
const settings = ref<PayrollEmployerSettings | null>(null)
const profile = ref<PayrollRegzelProfile | null>(null)
const snapshots = ref<PayrollRegzelSnapshot[]>([])
const snapshotsPageSize = 25
const snapshotsTotal = ref(0)
const snapshotsOffset = ref(0)
const snapshotsPage = computed(() =>
  Math.floor(snapshotsOffset.value / snapshotsPageSize) + 1)
// Prostředí je jedna volba pro celou stránku. Kdyby si je držely jednotlivé
// záložky samy, přepnutí z TESTU na jinou agendu by uživatele bez upozornění
// vrátilo do produkce.
//
// Volba přežije načtení stránky (Q8-49): na vývojové instalaci se přepínač
// po každém obnovení vracel na ostrý provoz. Pamatuje si ji jen tahle
// záložka prohlížeče; výchozí zůstává produkce. Mimo vývoj se uložený `test`
// vůbec nepoužije a z úložiště se smaže: kdyby se jím otevřela první záložka,
// odešel by dotaz na test, server ho odmítne a seznam podání zůstane prázdný.
const ENVIRONMENT_STORAGE_KEY = 'myinvoice.payroll.submissionEnvironment'
function storedEnvironment(): PayrollRegzelEnvironment {
  try {
    if (!auth.submissionTestEnvironmentAllowed) {
      sessionStorage.removeItem(ENVIRONMENT_STORAGE_KEY)
      return 'production'
    }
    return sessionStorage.getItem(ENVIRONMENT_STORAGE_KEY) === 'test' ? 'test' : 'production'
  } catch {
    return 'production'
  }
}
const environment = ref<PayrollRegzelEnvironment>(storedEnvironment())
const { testAllowed: submissionTestAllowed } = useSubmissionEnvironment(environment)
watch(environment, value => {
  try {
    if (submissionTestAllowed.value) sessionStorage.setItem(ENVIRONMENT_STORAGE_KEY, value)
    else sessionStorage.removeItem(ENVIRONMENT_STORAGE_KEY)
  } catch {
    // Bez úložiště (soukromé okno) platí volba jen do obnovení stránky.
  }
})
const officeId = ref<number | null>(null)
const error = ref('')
const success = ref('')

const SNAPSHOT_COLUMNS: ColumnDef[] = [
  { key: 'created_at', labelKey: 'payroll.regzel.history.created_at', required: true },
  { key: 'office', labelKey: 'payroll.regzel.history.office' },
  { key: 'version', labelKey: 'payroll.regzel.history.version' },
  { key: 'size', labelKey: 'payroll.regzel.history.size' },
  { key: 'actions', labelKey: 'common.actions', required: true },
]
const snapshotsTbl = useTablePrefs('payroll-submissions', SNAPSHOT_COLUMNS)

const canWrite = computed(() => auth.canWrite('payroll.submissions'))
const officeOptions = computed(() =>
  (settings.value?.offices ?? [])
    .filter(office => office.is_active)
    .map(office => ({
      value: office.id,
      label: `${office.code} - ${office.name}`,
      secondary: office.social_security_variable_symbol
        ? t('payroll.regzel.office_vs', { vs: office.social_security_variable_symbol })
        : t('payroll.regzel.office_vs_missing'),
    })),
)
const selectedOffice = computed(() =>
  officeOptions.value.find(option => option.value === officeId.value) ?? null,
)

function officeLabel(id: number): string {
  const office = settings.value?.offices.find(item => item.id === id)
  return office ? `${office.code} - ${office.name}` : `#${id}`
}

function apiMessage(exception: unknown, fallback: string): string {
  if (isAxiosError<{ error?: { message?: string } }>(exception)) {
    return exception.response?.data?.error?.message || fallback
  }
  const response = (exception as { response?: { data?: { error?: { message?: string } } } })
    ?.response
  return response?.data?.error?.message || fallback
}

async function loadSnapshots() {
  error.value = ''
  try {
    const page = await payrollApi.regzelSnapshots(environment.value, {
      limit: snapshotsPageSize,
      offset: snapshotsOffset.value,
    })
    snapshots.value = page.items
    snapshotsTotal.value = page.total
  } catch (exception: unknown) {
    snapshots.value = []
    snapshotsTotal.value = 0
    error.value = apiMessage(exception, t('payroll.regzel.history.load_failed'))
  }
}

function goToSnapshotsPage(nextPage: number) {
  snapshotsOffset.value = Math.max(0, (nextPage - 1) * snapshotsPageSize)
  void loadSnapshots()
}

async function load() {
  loading.value = true
  error.value = ''
  success.value = ''
  try {
    const [employerSettings, regzelProfileResponse] = await Promise.all([
      payrollApi.employerSettings(),
      payrollApi.regzelProfile(),
    ])
    settings.value = employerSettings
    profile.value = regzelProfileResponse.profile
    officeId.value = employerSettings.offices.find(office => office.is_active)?.id ?? null
    await loadSnapshots()
  } catch (exception: unknown) {
    error.value = apiMessage(exception, t('payroll.regzel.load_failed'))
  } finally {
    loading.value = false
  }
}

function newIdempotencyKey(): string {
  if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
    return crypto.randomUUID()
  }
  return `regzel-${Date.now()}-${Math.random().toString(16).slice(2)}`
}

/**
 * Příprava XML NEPOTVRZUJE evidenci podruhé.
 *
 * Správnost údajů se stvrzuje jednou — při uložení profilu, kde se zapíše
 * `evidence_confirmed_at`. Příprava jen čte tentýž profil, takže zaškrtávací
 * box tady stvrzoval už jednou stvrzený fakt a přidával krok navíc před každým
 * odesláním. Nahradila ho pasivní věta „Profil potvrzen dne …" nad formulářem.
 */
async function prepare() {
  error.value = ''
  success.value = ''
  if (!profile.value?.is_complete) {
    error.value = t('payroll.regzel.prepare.profile_required')
    return
  }
  if (officeId.value === null) {
    error.value = t('payroll.regzel.prepare.office_required')
    return
  }

  preparing.value = true
  try {
    const snapshot = await payrollApi.prepareRegzel({
      office_id: officeId.value,
      environment: environment.value,
      idempotency_key: newIdempotencyKey(),
    })
    success.value = snapshot.created
      ? t('payroll.regzel.prepare.created')
      : t('payroll.regzel.prepare.replayed')
    await loadSnapshots()
  } catch (exception: unknown) {
    error.value = apiMessage(exception, t('payroll.regzel.prepare.failed'))
  } finally {
    preparing.value = false
  }
}

async function download(snapshot: PayrollRegzelSnapshot) {
  error.value = ''
  downloadingId.value = snapshot.id
  try {
    await payrollApi.downloadRegzelSnapshot(snapshot)
  } catch (exception: unknown) {
    error.value = apiMessage(exception, t('payroll.regzel.download_failed'))
  } finally {
    downloadingId.value = null
  }
}

function readableBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  return `${(bytes / 1024).toFixed(1)} kB`
}

watch(environment, async () => {
  success.value = ''
  // Jiné prostředí = jiný seznam, takže stránka musí zpět na začátek.
  snapshotsOffset.value = 0
  void loadInboxBadge()
  if (!loading.value) {
    await loadSnapshots()
  }
})

// Odznak musí ukazovat TÉŽ prostředí, jaké je zvolené nahoře. Natvrdo zadaná
// produkce znamenala, že si účetní v testu přečte produkční počet a naopak -
// číslo u záložky pak tvrdí něco jiného než obsah, který se pod ní otevře.
async function loadInboxBadge() {
  try {
    const response = await payrollApi.submissionInbox(environment.value)
    inboxOpenCount.value = response.summary.total
  } catch {
    // Odznak je jen orientační — chybu zobrazí až samotná záložka Inbox.
    // Nesmí ale tvrdit „nic nevyřízeného": bez počtu se prostě nevykreslí.
    inboxOpenCount.value = null
  }
}

/**
 * Záložka je součást adresy (`/payroll/submissions/:tab`).
 *
 * Deset záložek jsou fakticky deset obrazovek: bez adresy na ně nešlo odkázat
 * ani je uložit do záložek a refresh vracel uživatele na Transport. Neznámý
 * `:tab` (zastaralý odkaz, překlep) se překlopí na výchozí záložku místo
 * prázdné stránky.
 *
 * Používá se `replace`, ne `push`: přepínání záložek není navigace mezi
 * stránkami a nemá zaplevelit tlačítko Zpět.
 */
function tabFromRoute(value: unknown): SubmissionTab | null {
  const raw = Array.isArray(value) ? value[0] : value
  return tabs.includes(raw as SubmissionTab) ? raw as SubmissionTab : null
}

const routedTab = tabFromRoute(route.params.tab)
if (routedTab !== null) activeTab.value = routedTab

watch(activeTab, (tab) => {
  if (tabFromRoute(route.params.tab) === tab) return
  void router.replace({ name: 'payroll-submissions-tab', params: { tab }, query: route.query })
})

watch(overviewPeriod, (period) => {
  if (route.query.period === period) return
  void router.replace({ query: { ...route.query, period } })
})

async function applySuggestedPeriod() {
  if (routedPeriod !== null) return
  try {
    const checklist = await payrollApi.monthlyChecklist(environment.value, overviewPeriod.value)
    if (checklist.suggested_period) overviewPeriod.value = checklist.suggested_period
  } catch {
    // Bez návrhu zůstává předchozí měsíc.
  }
}
onMounted(applySuggestedPeriod)

watch(() => route.params.tab, (value) => {
  const tab = tabFromRoute(value)
  if (tab !== null && tab !== activeTab.value) activeTab.value = tab
})

onMounted(() => {
  // Zastaralý odkaz na neexistující záložku se srovná hned po připojení, aby
  // adresa neukazovala na něco jiného, než co je vidět.
  if (route.name === 'payroll-submissions-tab' && tabFromRoute(route.params.tab) === null) {
    void router.replace({ name: 'payroll-submissions-tab', params: { tab: activeTab.value } })
  }
})
onMounted(load)
onMounted(loadInboxBadge)

/*
 * Viditelné záložky jsou rutina každého měsíce: Měsíc, JMHZ, Zdravotní
 * pojišťovny, K odeslání a Odesláno. Schovat je pod „Další" znamenalo, že
 * měsíční hlášení nikdo nenašel. Podání mimo měsíční cyklus jsou pohromadě
 * pod „Mimořádná podání", „Další" drží jen správu (Inbox, Certifikát).
 * Každá záložka má dál vlastní adresu, takže staré odkazy fungují. Na úzkém
 * displeji se záložky zalamují, nic se automaticky nepřesouvá.
 */
// Roční vyúčtování daně sdílí archiv a odesílání do EPO s daňovými podáními,
// takže záložka stojí na jejich oprávnění; bez něj by skončila chybou 403.
const primaryTabs = computed<SubmissionTab[]>(() => [
  'monthly', 'jmhz', 'health',
  ...(auth.canRead('reports') ? ['tax_statements' as const] : []),
  'queue', 'transport',
])
const tabGroups: { key: 'extraordinary' | 'more'; labelKey: string; tabs: SubmissionTab[] }[] = [
  {
    key: 'extraordinary',
    labelKey: 'payroll.submissions.tabs_extraordinary',
    tabs: ['statutory', 'regzel', 'registration_a1', 'registration_completion', 'discount_intents', 'sickness', 'eldp', 'other'],
  },
  { key: 'more', labelKey: 'payroll.submissions.tabs_more', tabs: ['inbox', 'certificate'] },
]
const openGroup = ref<'extraordinary' | 'more' | null>(null)

function toggleGroup(key: 'extraordinary' | 'more') {
  openGroup.value = openGroup.value === key ? null : key
}

function selectTab(tab: SubmissionTab) {
  activeTab.value = tab
  openGroup.value = null
}

/*
 * „Co odesílám, mám vidět hned": záložky agend začínají akční kartou za
 * období (co, komu, stav, lhůta, jedno tlačítko) a zbytek — podání
 * předchozím programem, náhledy, ruční sestavení, výpisy — je pod
 * „Podrobnosti". Sbalení si pamatuje prohlížeč uživatele.
 */
const DETAILS_STORAGE_KEY = 'myucto.payroll.submissions.details.v1'

function readDetailsState(): Record<string, boolean> {
  try {
    const parsed: unknown = JSON.parse(window.localStorage.getItem(DETAILS_STORAGE_KEY) ?? '{}')
    return parsed !== null && typeof parsed === 'object' ? parsed as Record<string, boolean> : {}
  } catch {
    return {}
  }
}

const detailsOpen = ref<Record<string, boolean>>(readDetailsState())

function rememberDetails(tab: string, event: Event) {
  const open = (event.target as HTMLDetailsElement).open
  if (detailsOpen.value[tab] === open) return
  detailsOpen.value = { ...detailsOpen.value, [tab]: open }
  try {
    window.localStorage.setItem(DETAILS_STORAGE_KEY, JSON.stringify(detailsOpen.value))
  } catch {
    // Bez úložiště (soukromé okno) se sbalení jen nezapamatuje.
  }
}
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">
          {{ t('payroll.submissions.title') }}
        </h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">
          {{ submissionTestAllowed
            ? t('payroll.submissions.subtitle')
            : t('payroll.submissions.subtitle_production_only') }}
        </p>
      </div>
      <button type="button" :class="btnOutline('neutral')" :disabled="loading" @click="load">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.cycle" />
        </svg>
        {{ t('common.refresh') }}
      </button>
    </header>

    <div class="flex flex-wrap items-end gap-4" data-test="submissions-period-bar">
      <label class="block text-sm font-medium text-neutral-700">
        {{ t('payroll.submissions.overview.period') }}
        <input
          v-model="overviewPeriod"
          type="month"
          class="mt-1 block h-10 rounded-md border border-neutral-300 bg-surface px-3 text-sm focus:border-payroll-500 focus:outline-none focus:ring-2 focus:ring-payroll-500/20"
          data-test="submissions-period"
        >
      </label>
      <div v-if="submissionTestAllowed" class="block text-sm font-medium text-neutral-700">
        {{ t('payroll.submissions.overview.environment') }}
        <div class="mt-1">
          <EnvironmentSwitch
            v-model="environment"
            :aria-label="t('payroll.submissions.overview.environment')"
            data-test="submissions-environment"
          />
        </div>
      </div>
    </div>

    <nav
      class="flex flex-wrap gap-1 border-b border-neutral-200"
      role="tablist"
      :aria-label="t('payroll.submissions.tabs_label')"
    >
      <button
        v-for="tab in primaryTabs"
        :key="tab"
        type="button"
        role="tab"
        :aria-selected="activeTab === tab"
        class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === tab
          ? 'border-payroll-600 text-payroll-600'
          : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900'"
        @click="selectTab(tab)"
      >
        {{ t(`payroll.submissions.tabs.${tab}`) }}
      </button>
      <div
        v-for="group in tabGroups"
        :key="group.key"
        class="relative"
        @keydown.escape="openGroup = null"
      >
        <button
          type="button"
          class="-mb-px inline-flex cursor-pointer items-center gap-1 whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
          :class="group.tabs.includes(activeTab)
            ? 'border-payroll-600 text-payroll-600'
            : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900'"
          :aria-expanded="openGroup === group.key"
          aria-haspopup="true"
          :data-test="`submissions-${group.key}-tabs`"
          @click="toggleGroup(group.key)"
        >
          {{ group.tabs.includes(activeTab) && group.key === 'more'
            ? t(`payroll.submissions.tabs.${activeTab}`)
            : t(group.labelKey) }}
          <span
            v-if="group.key === 'more' && inboxOpenCount !== null && inboxOpenCount > 0"
            class="ml-0.5 inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-danger-600 px-1.5 py-0.5 text-xs font-semibold text-white"
          >
            {{ inboxOpenCount }}
          </span>
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.chevron" />
          </svg>
        </button>
        <div
          v-show="openGroup === group.key"
          class="absolute left-0 z-20 mt-1 w-64 rounded-lg border border-neutral-200 bg-surface py-1 shadow-lg"
          :data-test="`submissions-${group.key}-menu`"
        >
          <button
            v-for="tab in group.tabs"
            :key="tab"
            type="button"
            role="tab"
            :aria-selected="activeTab === tab"
            class="flex w-full cursor-pointer items-center justify-between px-3 py-2 text-left text-sm"
            :class="activeTab === tab
              ? 'bg-payroll-50 font-medium text-payroll-700'
              : 'text-neutral-700 hover:bg-neutral-50'"
            @click="selectTab(tab)"
          >
            {{ t(`payroll.submissions.tabs.${tab}`) }}
            <span
              v-if="tab === 'inbox' && inboxOpenCount !== null && inboxOpenCount > 0"
              class="ml-1.5 inline-flex min-w-[1.25rem] items-center justify-center rounded-full bg-danger-600 px-1.5 py-0.5 text-xs font-semibold text-white"
              data-test="submissions-inbox-badge"
            >
              {{ inboxOpenCount }}
            </span>
          </button>
        </div>
      </div>
    </nav>

    <!--
      Měsíční přehled i Stav odeslání se nečekají na načtení téhle stránky:
      oba si svá data obstarávají sami a schovat je za skeleton registrace by
      znamenalo, že se odpověď na „co mám tenhle měsíc udělat" objeví později,
      než by musela.
    -->
    <PayrollMonthlyChecklistPanel
      v-if="activeTab === 'monthly'"
      v-model:environment="environment"
      :period="overviewPeriod"
    />

    <!--
      Fronta odchozích podání si data obstarává sama a na REGZEL profilu
      nezávisí, proto stojí mimo společný skeleton.
    -->
    <PayrollSubmissionQueuePanel
      v-else-if="activeTab === 'queue'"
      v-model:environment="environment"
    />

    <PayrollTransportHistoryPanel
      v-else-if="activeTab === 'transport'"
      v-model:environment="environment"
    />

    <PayrollRegistrationA1BatchPanel
      v-else-if="activeTab === 'registration_a1'"
      v-model:environment="environment"
    />

    <PayrollRegistrationCompletionPanel
      v-else-if="activeTab === 'registration_completion'"
      v-model:environment="environment"
    />

    <!--
      Evidenční list si data obstarává sám a nepotřebuje načtení REGZEL
      profilu, proto stojí mimo společný skeleton.
    -->
    <!--
      Záměr uplatňovat slevu si data obstarává sám a na REGZEL profilu
      nezávisí, proto stojí mimo společný skeleton.
    -->
    <PayrollDiscountIntentsPanel
      v-else-if="activeTab === 'discount_intents'"
      v-model:environment="environment"
    />

    <!--
      Případy dávek nemocenského pojištění (NEMPRI, HZUPN) stojí hned za
      záměrem slevy: obojí je podání mimo měsíční hlášení, které si data
      obstarává samo a na REGZEL profilu nezávisí.
    -->
    <template v-else-if="activeTab === 'sickness'">
      <PayrollMonthlyChecklistPanel
        v-model:environment="environment"
        :period="overviewPeriod"
        :agendas="['NEMPRI', 'HZUPN']"
        compact
      />
      <details
        class="rounded-xl border border-neutral-200 bg-surface shadow-sm"
        :open="detailsOpen.sickness !== false"
        data-test="submissions-details-sickness"
        @toggle="rememberDetails('sickness', $event)"
      >
        <summary class="cursor-pointer select-none px-4 py-3 text-sm font-semibold text-neutral-800 sm:px-6">
          {{ t('payroll.submissions.details_sickness') }}
        </summary>
        <div class="border-t border-neutral-200 p-4 sm:p-6">
          <PayrollSicknessCasesPanel v-model:environment="environment" />
        </div>
      </details>
    </template>

    <template v-else-if="activeTab === 'eldp'">
      <PayrollMonthlyChecklistPanel
        v-model:environment="environment"
        :period="overviewPeriod"
        :agendas="['ELDP']"
        compact
      />
      <details
        class="rounded-xl border border-neutral-200 bg-surface shadow-sm"
        :open="detailsOpen.eldp !== false"
        data-test="submissions-details-eldp"
        @toggle="rememberDetails('eldp', $event)"
      >
        <summary class="cursor-pointer select-none px-4 py-3 text-sm font-semibold text-neutral-800 sm:px-6">
          {{ t('payroll.submissions.details_eldp') }}
        </summary>
        <div class="border-t border-neutral-200 p-4 sm:p-6">
          <PayrollEldpPanel v-model:environment="environment" />
        </div>
      </details>
    </template>

    <!--
      Zdravotní agenda si data obstarává sama a na REGZEL profilu
      nezávisí, proto stojí mimo společný skeleton.
    -->
    <template v-else-if="activeTab === 'health'">
      <section class="space-y-3" data-test="submissions-action-card">
        <PayrollMonthlyChecklistPanel
          v-model:environment="environment"
          :period="overviewPeriod"
          :agendas="['PPZ_2026', 'HOZ_2026']"
          compact
        />
      </section>
      <PayrollSubmissionOverviewPanel
        v-model:environment="environment"
        v-model:period="overviewPeriod"
        mode="health"
      />
      <details
        class="rounded-xl border border-neutral-200 bg-surface shadow-sm"
        :open="detailsOpen.health === true"
        data-test="submissions-details-health"
        @toggle="rememberDetails('health', $event)"
      >
        <summary class="cursor-pointer select-none px-4 py-3 text-sm font-semibold text-neutral-800 sm:px-6">
          {{ t('payroll.submissions.details_health') }}
        </summary>
        <div class="border-t border-neutral-200 p-4 sm:p-6">
          <PayrollHealthNotificationPanel
            v-model:period="overviewPeriod"
            v-model:environment="environment"
          />
        </div>
      </details>
    </template>

    <div v-else-if="loading" class="space-y-4">
      <div class="h-28 animate-pulse rounded-xl bg-neutral-100" />
      <div class="h-64 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <template v-else-if="activeTab === 'regzel'">
      <div
        v-if="error"
        data-test="regzel-error"
        class="rounded-xl border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
        role="alert"
      >
        {{ error }}
      </div>
      <div
        v-if="success"
        class="rounded-xl border border-success-500/30 bg-success-50 p-4 text-sm text-success-700"
        role="status"
      >
        {{ success }}
      </div>

      <section
        class="rounded-xl border p-4 sm:p-6"
        :class="environment === 'production'
          ? 'border-warning-500/40 bg-warning-50'
          : 'border-payroll-500/30 bg-payroll-50'"
      >
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div class="max-w-3xl">
            <h2 class="text-lg font-semibold text-neutral-900">
              REGZELDOPL25 1.2
            </h2>
            <p class="mt-1 text-sm text-neutral-600">
              {{ t('payroll.regzel.description') }}
            </p>
          </div>
          <span
            v-if="submissionTestAllowed"
            class="rounded-full px-2.5 py-1 text-xs font-semibold uppercase tracking-wide"
            :class="environment === 'production'
              ? 'bg-warning-100 text-warning-800'
              : 'bg-payroll-100 text-payroll-800'"
          >
            {{ t(`payroll.regzel.environment.${environment}`) }}
          </span>
        </div>
        <p class="mt-4 text-sm font-medium text-neutral-800">
          {{ t(`payroll.regzel.environment.${environment}_warning`) }}
        </p>
      </section>

      <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-semibold text-neutral-900">
              {{ t('payroll.regzel.prepare.title') }}
            </h2>
            <p class="mt-1 max-w-3xl text-sm text-neutral-500">
              {{ t('payroll.regzel.prepare.description') }}
            </p>
          </div>
          <RouterLink
            :to="{ name: 'payroll-settings', query: { tab: 'submissions' } }"
            :class="btnOutline('neutral')"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.edit" />
            </svg>
            {{ t('payroll.regzel.prepare.open_settings') }}
          </RouterLink>
        </div>

        <div
          v-if="!profile?.is_complete"
          class="mt-5 rounded-lg border border-warning-500/30 bg-warning-50 p-4 text-sm text-warning-700"
        >
          {{ t('payroll.regzel.prepare.profile_required') }}
        </div>
        <div
          v-else
          class="mt-5 flex flex-wrap items-center gap-2 text-sm text-neutral-600"
        >
          <svg class="h-5 w-5 text-success-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.checkCircle" />
          </svg>
          <!-- `row_version` je optimistický zámek, ne verze profilu; účetní
               z něj nic nevyčte. Datum je čas potvrzení, patří do lidského
               tvaru jako všude jinde. -->
          {{ t('payroll.regzel.prepare.profile_confirmed', {
            at: formatDateTime(profile.evidence_confirmed_at),
          }) }}
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
          <div v-if="submissionTestAllowed" class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">
              {{ t('payroll.regzel.environment.label') }}
            </span>
            <EnvironmentSwitch
              v-model="environment"
              data-test="regzel-environment"
              :aria-label="t('payroll.regzel.environment.label')"
            />
          </div>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">
              {{ t('payroll.regzel.office') }}
            </span>
            <SearchableSelect
              v-model="officeId"
              :options="officeOptions"
              :selected-option="selectedOffice"
              :placeholder="t('payroll.regzel.office_placeholder')"
              :no-results-label="t('payroll.regzel.office_empty')"
              :clearable="false"
              accent="payroll"
            />
          </label>
        </div>

        <div class="mt-5 flex flex-wrap justify-end gap-2">
          <button
            v-if="canWrite"
            type="button"
            data-test="regzel-prepare"
            :class="btnFilled('primary')"
            :disabled="preparing || !profile?.is_complete || officeId === null"
            @click="prepare"
          >
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
              <path :d="ICONS.doc" />
            </svg>
            {{ preparing
              ? t('payroll.regzel.prepare.preparing')
              : t('payroll.regzel.prepare.action') }}
          </button>
        </div>
        <p v-if="!canWrite" class="mt-5 text-sm text-neutral-500">
          {{ t('payroll.regzel.read_only') }}
        </p>
      </section>

      <section class="rounded-xl border border-neutral-200 bg-surface shadow-sm">
        <div class="border-b border-neutral-200 p-4 sm:p-6">
          <h2 class="text-lg font-semibold text-neutral-900">
            {{ t('payroll.regzel.history.title') }}
          </h2>
          <p class="mt-1 text-sm text-neutral-500">
            {{ t('payroll.regzel.history.description') }}
          </p>
        </div>

        <div v-if="snapshots.length === 0" class="p-6 text-sm text-neutral-500">
          {{ t('payroll.regzel.history.empty') }}
        </div>

        <template v-else>
          <div class="hidden items-center justify-end gap-2 border-b border-neutral-200 px-4 py-2 md:flex">
            <ColumnPicker :ctrl="snapshotsTbl" />
            <DensityToggle :ctrl="snapshotsTbl" />
          </div>
          <div class="hidden overflow-x-auto md:block">
            <table v-column-labels="snapshotsTbl" class="min-w-full divide-y divide-neutral-200 text-sm" :class="snapshotsTbl.densityClass.value">
              <thead>
                <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                  <th v-if="snapshotsTbl.isVisible('created_at')" class="px-4 py-3">{{ t('payroll.regzel.history.created_at') }}</th>
                  <th v-if="snapshotsTbl.isVisible('office')" class="px-4 py-3">{{ t('payroll.regzel.history.office') }}</th>
                  <th v-if="snapshotsTbl.isVisible('version')" class="px-4 py-3">{{ t('payroll.regzel.history.version') }}</th>
                  <th v-if="snapshotsTbl.isVisible('size')" class="px-4 py-3">{{ t('payroll.regzel.history.size') }}</th>
                  <th v-if="snapshotsTbl.isVisible('actions')" class="px-4 py-3 text-right">{{ t('common.actions') }}</th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="snapshot in snapshots" :key="snapshot.id">
                  <td v-if="snapshotsTbl.isVisible('created_at')" class="px-4 py-3 text-neutral-900">{{ formatDateTime(snapshot.created_at) }}</td>
                  <td v-if="snapshotsTbl.isVisible('office')" class="px-4 py-3 text-neutral-700">{{ officeLabel(snapshot.office_id) }}</td>
                  <td v-if="snapshotsTbl.isVisible('version')" class="px-4 py-3 text-neutral-700">XSD {{ snapshot.xsd_version }}</td>
                  <td v-if="snapshotsTbl.isVisible('size')" class="px-4 py-3 text-neutral-700">{{ readableBytes(snapshot.xml_byte_size) }}</td>
                  <td v-if="snapshotsTbl.isVisible('actions')" class="px-4 py-3 text-right">
                    <button
                      type="button"
                      :class="btnOutlineSm('neutral')"
                      :disabled="downloadingId === snapshot.id"
                      @click="download(snapshot)"
                    >
                      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path :d="ICONS.download" />
                      </svg>
                      {{ t('common.download') }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </template>

        <div v-if="snapshots.length" class="grid grid-cols-1 gap-3 p-4 md:hidden">
          <article
            v-for="snapshot in snapshots"
            :key="snapshot.id"
            class="rounded-lg border border-neutral-200 p-4"
          >
            <div class="flex items-start justify-between gap-3">
              <div>
                <h3 class="font-medium text-neutral-900">REGZELDOPL25</h3>
                <p class="mt-1 text-xs text-neutral-500">{{ formatDateTime(snapshot.created_at) }}</p>
              </div>
              <span class="rounded-full bg-neutral-100 px-2 py-1 text-xs text-neutral-700">
                XSD {{ snapshot.xsd_version }}
              </span>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-3 text-xs">
              <div>
                <dt class="text-neutral-500">{{ t('payroll.regzel.history.office') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ officeLabel(snapshot.office_id) }}</dd>
              </div>
              <div>
                <dt class="text-neutral-500">{{ t('payroll.regzel.history.size') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ readableBytes(snapshot.xml_byte_size) }}</dd>
              </div>
            </dl>
            <button
              type="button"
              class="cursor-pointer mt-4"
              :class="btnOutline('neutral')"
              :disabled="downloadingId === snapshot.id"
              @click="download(snapshot)"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path :d="ICONS.download" />
              </svg>
              {{ t('common.download') }}
            </button>
          </article>
        </div>

        <PaginationBar
          embedded
          :page="snapshotsPage"
          :per-page="snapshotsPageSize"
          :total="snapshotsTotal"
          @update:page="goToSnapshotsPage"
        />
      </section>
    </template>

    <PayrollSubmissionInboxPanel
      v-else-if="activeTab === 'inbox'"
      v-model:environment="environment"
      @update:open-count="inboxOpenCount = $event"
    />

    <PayrollStatutoryObligationsPanel
      v-else-if="activeTab === 'statutory'"
      v-model:environment="environment"
    />

    <PayrollSigningCertificatePanel
      v-else-if="activeTab === 'certificate'"
      v-model:environment="environment"
    />

    <TaxSubmissions
      v-else-if="activeTab === 'tax_statements'"
      embedded
      scope="payroll"
    />

    <template v-else-if="activeTab === 'jmhz'">
      <section class="space-y-3" data-test="submissions-action-card">
        <PayrollMonthlyChecklistPanel
          v-model:environment="environment"
          :period="overviewPeriod"
          :agendas="['JMHZ']"
          compact
        />
      </section>
      <details
        class="rounded-xl border border-neutral-200 bg-surface shadow-sm"
        :open="detailsOpen.jmhz === true"
        data-test="submissions-details-jmhz"
        @toggle="rememberDetails('jmhz', $event)"
      >
        <summary class="cursor-pointer select-none px-4 py-3 text-sm font-semibold text-neutral-800 sm:px-6">
          {{ t('payroll.submissions.details_jmhz') }}
        </summary>
        <div class="space-y-4 border-t border-neutral-200 p-4 sm:p-6">
          <!--
            Podání předchozím programem: měsíc, za který řádné hlášení podal
            předchozí program, se tu vysvětluje a opravuje. Firma bez převodu
            z jiného programu panel neuvidí.
          -->
          <PayrollExternalJmhzSubmissionsPanel :environment="environment" />
          <PayrollSubmissionOverviewPanel
            v-model:environment="environment"
            v-model:period="overviewPeriod"
            mode="jmhz"
          />
        </div>
      </details>
    </template>

    <template v-else>
      <PayrollSubmissionOverviewPanel
        v-model:environment="environment"
        v-model:period="overviewPeriod"
        :mode="activeTab === 'other' ? activeTab : 'jmhz'"
      />
    </template>
  </div>
</template>
