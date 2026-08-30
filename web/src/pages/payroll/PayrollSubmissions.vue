<script setup lang="ts">
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
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import PayrollEldpPanel from './PayrollEldpPanel.vue'
import PayrollDiscountIntentsPanel from './PayrollDiscountIntentsPanel.vue'
import PayrollSicknessCasesPanel from './PayrollSicknessCasesPanel.vue'
import PayrollHealthNotificationPanel from './PayrollHealthNotificationPanel.vue'
import PayrollSubmissionInboxPanel from './PayrollSubmissionInboxPanel.vue'
import PayrollSubmissionOverviewPanel from './PayrollSubmissionOverviewPanel.vue'
import PayrollStatutoryObligationsPanel from './PayrollStatutoryObligationsPanel.vue'
import PayrollSigningCertificatePanel from './PayrollSigningCertificatePanel.vue'
import PayrollTransportHistoryPanel from './PayrollTransportHistoryPanel.vue'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'

type SubmissionTab =
  'transport' | 'regzel' | 'jmhz' | 'discount_intents' | 'sickness' | 'eldp' | 'health'
  | 'statutory' | 'other' | 'inbox' | 'certificate'

const { t } = useI18n()
const auth = useAuthStore()
const route = useRoute()
const router = useRouter()
// „Co jsem odeslal a jak to dopadlo" je nejčastější důvod, proč se sem někdo
// podívá — proto je stav odeslání první záložka a zároveň ta výchozí. Připravit
// registraci nebo hlášení je jednorázový úkon, sledovat výsledek úkon opakovaný.
const activeTab = ref<SubmissionTab>('transport')
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
const tabs: SubmissionTab[] = [
  'transport', 'regzel', 'jmhz', 'discount_intents', 'sickness', 'eldp', 'health',
  'statutory', 'other', 'inbox', 'certificate',
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
const environment = ref<PayrollRegzelEnvironment>('production')
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
const environmentOptions = computed(() => [
  {
    value: 'production' as PayrollRegzelEnvironment,
    label: t('payroll.regzel.environment.production'),
  },
  {
    value: 'test' as PayrollRegzelEnvironment,
    label: t('payroll.regzel.environment.test'),
  },
])
const officeOptions = computed(() =>
  (settings.value?.offices ?? [])
    .filter(office => office.is_active)
    .map(office => ({
      value: office.id,
      label: `${office.code} — ${office.name}`,
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
  return office ? `${office.code} — ${office.name}` : `#${id}`
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
  if (!loading.value) {
    await loadSnapshots()
  }
})

async function loadInboxBadge() {
  try {
    const response = await payrollApi.submissionInbox('production')
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
  void router.replace({ name: 'payroll-submissions-tab', params: { tab } })
})

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
</script>

<template>
  <div class="space-y-6">
    <header class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h1 class="text-2xl font-semibold text-neutral-900">
          {{ t('payroll.submissions.title') }}
        </h1>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">
          {{ t('payroll.submissions.subtitle') }}
        </p>
      </div>
      <button type="button" :class="btnOutline('neutral')" :disabled="loading" @click="load">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.cycle" />
        </svg>
        {{ t('common.refresh') }}
      </button>
    </header>

    <nav
      class="flex flex-wrap gap-1 border-b border-neutral-200"
      role="tablist"
      :aria-label="t('payroll.submissions.tabs_label')"
    >
      <button
        v-for="tab in tabs"
        :key="tab"
        type="button"
        role="tab"
        :aria-selected="activeTab === tab"
        class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
        :class="activeTab === tab
          ? 'border-payroll-600 text-payroll-600'
          : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900'"
        @click="activeTab = tab"
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
    </nav>

    <!--
      Stav odeslání se nečeká na načtení téhle stránky: panel si svá data
      obstarává sám a schovat ho za skeleton registrace by znamenalo, že se
      odpověď na „co jsem odeslal" objeví později, než by musela.
    -->
    <PayrollTransportHistoryPanel
      v-if="activeTab === 'transport'"
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
    <PayrollSicknessCasesPanel
      v-else-if="activeTab === 'sickness'"
      v-model:environment="environment"
    />

    <PayrollEldpPanel
      v-else-if="activeTab === 'eldp'"
      v-model:environment="environment"
    />

    <!--
      Zdravotní agenda si data obstarává sama a na REGZEL profilu
      nezávisí, proto stojí mimo společný skeleton.
    -->
    <template v-else-if="activeTab === 'health'">
      <PayrollHealthNotificationPanel />
      <PayrollSubmissionOverviewPanel v-model:environment="environment" mode="health" />
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
          {{ t('payroll.regzel.prepare.profile_confirmed', {
            at: profile.evidence_confirmed_at,
            version: profile.row_version,
          }) }}
        </div>

        <div class="mt-5 grid grid-cols-1 gap-5 lg:grid-cols-2">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">
              {{ t('payroll.regzel.environment.label') }}
            </span>
            <SearchableSelect
              v-model="environment"
              data-test="regzel-environment"
              :options="environmentOptions"
              :clearable="false"
              accent="payroll"
            />
          </label>
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
            <table class="min-w-full divide-y divide-neutral-200 text-sm" :class="snapshotsTbl.densityClass.value">
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
                  <td v-if="snapshotsTbl.isVisible('created_at')" class="px-4 py-3 text-neutral-900">{{ snapshot.created_at }}</td>
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
                <p class="mt-1 text-xs text-neutral-500">{{ snapshot.created_at }}</p>
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
              class="mt-4"
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

    <PayrollSubmissionOverviewPanel
      v-else
      v-model:environment="environment"
      :mode="activeTab === 'other' ? activeTab : 'jmhz'"
    />
  </div>
</template>
