<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { payrollQueryValue } from '@/pages/payroll/payrollAgendaLinks'
import RegistrationImportPanel from '@/components/payroll/imports/RegistrationImportPanel.vue'
import AttendanceImportPanel from '@/components/payroll/imports/AttendanceImportPanel.vue'
import AgreementImportPanel from '@/components/payroll/imports/AgreementImportPanel.vue'
import AttendanceMappingPanel from '@/components/payroll/imports/AttendanceMappingPanel.vue'
import PohodaOicImportPanel from '@/components/payroll/imports/PohodaOicImportPanel.vue'
import TakeoverWagesPanel from '@/components/payroll/imports/TakeoverWagesPanel.vue'
import MigrationReconciliationPanel from '@/components/payroll/imports/MigrationReconciliationPanel.vue'
import PostingMapPanel from '@/components/payroll/imports/PostingMapPanel.vue'
import { createAttendanceWorkspace, provideAttendanceWorkspace } from '@/components/payroll/imports/attendanceWorkspace'
import { createMigrationWorkspace, provideMigrationWorkspace } from '@/components/payroll/imports/migrationWorkspace'
import EmptyState from '@/components/ui/EmptyState.vue'

type Tab = 'registration' | 'agreements' | 'attendance' | 'mapping' | 'pohoda_oic' | 'takeover' | 'reconciliation' | 'posting_map'

/**
 * Pořadí = dvě skupiny, oddělené v liště čárou.
 *
 * Vlevo měsíční import: registrace, docházka, její mapování a OIČ z POHODY.
 * Vpravo jednorázový přechod z jiného mzdového programu — nahrání převzatých
 * mezd, kontrola našeho přepočtu proti nim a kontace z převzatého zaúčtování.
 * Ty tři stály dřív každá samostatně v menu, i firmě, která nikdy nic
 * nepřevzala; agenda je jednorázová, takže se nabízí, jen když je co převzatého
 * (Kontrola a Kontace), případně když se teprve převádí (Převzaté mzdy).
 */
const TABS: readonly Tab[] = [
  'registration', 'agreements', 'attendance', 'mapping', 'pohoda_oic',
  'takeover', 'reconciliation', 'posting_map',
]
const MIGRATION_TABS: readonly Tab[] = ['takeover', 'reconciliation', 'posting_map']

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()

const canWriteInputs = computed(() => auth.canWrite('payroll.inputs.write'))
const canWritePersons = computed(() => auth.canWrite('payroll.person.write'))
const canManageProfiles = computed(() => auth.canWrite('payroll.settings'))
const canApproveTime = computed(() => auth.canWrite('payroll.approve'))
// OIČ zapisuje stejná cesta jako karta vztahu, proto obě práva jako tam.
const canWriteIdentity = computed(() => auth.canWrite('payroll.person.write') && auth.canWrite('payroll.employment.write'))
// Měsíční import otevře ten, kdo smí na mzdové vstupy — tak to bylo, než sem
// přibyly záložky přechodu s vlastními právy.
const canSeeImports = computed(() => auth.canRead('payroll.inputs.write'))
const canSeeReports = computed(() => auth.canRead('payroll.reports'))
const canSeeSettings = computed(() => auth.canRead('payroll.settings'))

// Mapování se nastavuje jednou, import běží měsíčně — obě záložky ale pracují
// se stejnými soubory a profily, proto sdílený stav místo dvojího nahrávání.
const workspace = createAttendanceWorkspace({
  onOpenMapping: () => {
    activeTab.value = 'mapping'
    window.scrollTo({ top: 0, behavior: 'smooth' })
  },
  errorMessage: error => apiErrorMessage(error, t('payroll_imports.profiles_load_failed')),
})
provideAttendanceWorkspace(workspace)

const migration = createMigrationWorkspace()
provideMigrationWorkspace(migration)

function isTabVisible(tab: Tab): boolean {
  switch (tab) {
    case 'takeover':
      return canSeeReports.value
    case 'reconciliation':
      return canSeeReports.value && migration.state.value?.has_takeover_wages === true
    case 'posting_map':
      return canSeeSettings.value && migration.state.value?.has_posting_map === true
    default:
      return canSeeImports.value
  }
}

const visibleTabs = computed<Tab[]>(() => TABS.filter(isTabVisible))
/** Čára v liště odděluje měsíční import od jednorázového přechodu. */
const firstMigrationTab = computed<Tab | null>(
  () => visibleTabs.value.find(tab => MIGRATION_TABS.includes(tab)) ?? null,
)

function tabFromQuery(): Tab {
  const requested = payrollQueryValue(route.query, 'tab')
  // Starší odkazy nesly množné číslo.
  if (requested === 'registrations') return 'registration'
  return requested !== null && (TABS as readonly string[]).includes(requested) ? requested as Tab : 'registration'
}

const activeTab = ref<Tab>(tabFromQuery())

/**
 * Záložka, která se reálně vykreslí. Přímý odkaz na skrytou záložku nepadá do
 * prázdna, ale na první dostupnou — schovaná je kvůli datům nebo právům, což
 * není chyba adresy.
 */
const renderedTab = computed<Tab>(() => {
  if (isTabVisible(activeTab.value)) return activeTab.value
  return visibleTabs.value[0] ?? activeTab.value
})

// Záložka v adrese, ať jde na import odkázat a po obnovení stránky se neztratí.
watch(activeTab, tab => {
  if (payrollQueryValue(route.query, 'tab') === tab) return
  void router.replace({ query: { ...route.query, tab } })
})
watch(() => route.query.tab, () => {
  const tab = tabFromQuery()
  if (tab !== activeTab.value) activeTab.value = tab
})

onMounted(() => {
  void workspace.loadProfiles()
  void migration.loadState()
})
</script>

<template>
  <div class="space-y-6">
    <header>
      <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll_imports.title') }}</h1>
      <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll_imports.subtitle') }}</p>
    </header>

    <nav class="flex flex-wrap items-center gap-1 border-b border-neutral-200" :aria-label="t('payroll_imports.tabs.label')">
      <template v-for="tab in visibleTabs" :key="tab">
        <span
          v-if="tab === firstMigrationTab && tab !== visibleTabs[0]"
          class="mx-2 hidden h-5 w-px bg-neutral-200 sm:block"
          aria-hidden="true"
        />
        <button
          type="button"
          :data-testid="`payroll-imports-tab-${tab}`"
          class="-mb-px cursor-pointer whitespace-nowrap border-b-2 px-4 py-2 text-sm font-medium transition-colors"
          :class="renderedTab === tab
            ? 'border-payroll-600 text-payroll-600'
            : 'border-transparent text-neutral-600 hover:border-neutral-300 hover:text-neutral-900'"
          :aria-current="renderedTab === tab ? 'page' : undefined"
          @click="activeTab = tab"
        >
          {{ t(`payroll_imports.tabs.${tab}`) }}
        </button>
      </template>
    </nav>

    <EmptyState
      v-if="visibleTabs.length === 0"
      variant="empty"
      accent="accent"
      :title="t('payroll_imports.no_access_title')"
      :message="t('payroll_imports.no_access_description')"
    />

    <!-- v-show: rozpracovaný průvodce ani neuložený profil nesmí zmizet jen kvůli přepnutí záložky. -->
    <template v-if="canSeeImports">
      <RegistrationImportPanel v-show="renderedTab === 'registration'" :can-write="canWritePersons" />
      <AgreementImportPanel v-show="renderedTab === 'agreements'" :can-write="canWritePersons" />
      <AttendanceImportPanel
        v-show="renderedTab === 'attendance'"
        :can-write="canWriteInputs"
        :can-create-persons="canWritePersons"
        :can-approve-time="canApproveTime"
      />
      <AttendanceMappingPanel
        v-show="renderedTab === 'mapping'"
        :can-manage="canManageProfiles"
        :can-preview="canWriteInputs"
      />
      <PohodaOicImportPanel v-show="renderedTab === 'pohoda_oic'" :can-write="canWriteIdentity" />
    </template>

    <!-- Záložky přechodu se montují až po otevření: kontrolní sestava tahá celý
         rok mzdových řádků a nikdo ji nechce čekat při nahrávání docházky. -->
    <TakeoverWagesPanel v-if="renderedTab === 'takeover' && isTabVisible('takeover')" />
    <MigrationReconciliationPanel v-if="renderedTab === 'reconciliation' && isTabVisible('reconciliation')" />
    <PostingMapPanel v-if="renderedTab === 'posting_map' && isTabVisible('posting_map')" />
  </div>
</template>
