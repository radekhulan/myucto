<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollCapabilitiesResponse,
  type PayrollCompanyCapabilityBlocker,
  type PayrollRun,
  type PayrollSetupCheck,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatPeriod } from '@/composables/useFormat'
import { localPayrollPeriod } from '@/pages/payroll/payrollComponentsUi'
import PayrollEmployeeCards from '@/pages/payroll/PayrollEmployeeCards.vue'
import PayrollGuide from '@/pages/payroll/PayrollGuide.vue'
import PayrollProductionQualificationPanel from '@/pages/payroll/PayrollProductionQualificationPanel.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const loading = ref(true)
const saving = ref(false)
const capabilities = ref<PayrollCapabilitiesResponse | null>(null)
const currentPeriod = localPayrollPeriod()
const startPeriod = ref(currentPeriod)
const currentRun = ref<PayrollRun | null>(null)
const setupCheck = ref<PayrollSetupCheck | null>(null)
const guide = ref<InstanceType<typeof PayrollGuide> | null>(null)

const state = computed(() => capabilities.value?.state ?? null)
const canConfigure = computed(() => auth.canWrite('payroll.settings'))
const isEnabled = computed(() => state.value?.status !== 'disabled')
const needsProductionQualification = computed(() => state.value?.status === 'qualification_required')
const availableFeatures = computed(() =>
  capabilities.value?.support_matrix.features.filter(feature => feature.available) ?? [],
)
const plannedFeatures = computed(() =>
  capabilities.value?.support_matrix.features.filter(feature => !feature.available) ?? [],
)
const companyCapabilityBlockers = computed(() => capabilities.value?.company_capability.blockers ?? [])
const setupBlockers = computed(() =>
  setupCheck.value !== null && !setupCheck.value.ready
    ? setupCheck.value.checks.filter(item => item.status === 'blocked')
    : [],
)

function companyCapabilityMessage(blocker: PayrollCompanyCapabilityBlocker): string {
  const known = new Set([
    'unsupported_relation_type',
    'foreign_employment_regime',
    'unsupported_jmhz_scenario',
    'foreign_social_jurisdiction',
    'foreign_health_jurisdiction',
  ])
  return known.has(blocker.code)
    ? t(`payroll.activation.qualification.company_blockers.${blocker.code}`, blocker.parameters)
    : blocker.message
}

/**
 * Hlavička sekce používá sdílený `ActionBar` (AGENTS.md §Frontend): jedna plná
 * primární akce = další logický krok měsíce, zbytek outline / v „…".
 */
const actions = computed<ActionItem[]>(() => [
  {
    key: 'quick-inputs',
    label: t('payroll.dashboard.month.quick_inputs'),
    icon: 'coin',
    tier: 'primary',
    variant: 'primary',
    to: { name: 'payroll-quick-inputs' },
  },
  {
    key: 'runs',
    label: t('payroll.dashboard.month.runs'),
    icon: 'cycle',
    tier: 'secondary',
    variant: 'primary',
    to: { name: 'payroll-runs' },
  },
  {
    key: 'people',
    label: t('payroll.dashboard.month.people'),
    icon: 'user',
    tier: 'secondary',
    variant: 'neutral',
    to: { name: 'payroll-people' },
  },
  {
    key: 'guide',
    label: t('payroll.guide.reopen'),
    icon: 'help',
    tier: 'overflow',
    variant: 'neutral',
    run: () => guide.value?.reopen(),
  },
  {
    key: 'settings',
    label: t('payroll.dashboard.settings'),
    icon: 'lock',
    tier: 'overflow',
    variant: 'neutral',
    show: canConfigure.value,
    to: { name: 'payroll-settings' },
  },
  // Zrušení rozdělaného nastavení není nic, k čemu by měl být uživatel vyzýván
  // — patří mezi pokročilé akce v „…", ne vedle hlavních tlačítek. Ve stavu
  // `active` mizí úplně: aktivovaný modul už vypnout nejde.
  {
    key: 'disable-setup',
    label: t('payroll.activation.disable'),
    icon: 'uturn',
    tier: 'advanced',
    variant: 'warning',
    show: canConfigure.value && state.value?.status === 'setup',
    disabled: saving.value,
    run: () => void disableSetup(),
  },
])

const runStatusClass = computed(() => {
  const status = currentRun.value?.status
  if (status === undefined) return 'bg-neutral-100 text-neutral-600'
  if (status === 'closed' || status === 'paid') return 'bg-success-50 text-success-700'
  if (status === 'cancelled' || status === 'correction_pending') return 'bg-warning-50 text-warning-700'
  return 'bg-payroll-50 text-payroll-700'
})

async function load() {
  loading.value = true
  try {
    const data = await payrollApi.capabilities()
    capabilities.value = data
    if (data.state.start_period) {
      startPeriod.value = data.state.start_period
    }
  } catch {
    toast.error(t('payroll.load_failed'))
  } finally {
    loading.value = false
  }
  if (!isEnabled.value) return
  // Stav měsíce a blokátory nastavení jsou doplňkové — jejich výpadek
  // (typicky chybějící oprávnění) nesmí shodit celý přehled.
  void loadMonthStatus()
}

async function loadMonthStatus() {
  const [runs, setup] = await Promise.all([
    payrollApi.runs(currentPeriod).catch(() => [] as PayrollRun[]),
    payrollApi.payrollSetupCheck(`${currentPeriod}-01`).catch(() => null),
  ])
  currentRun.value = runs.length > 0 ? runs[runs.length - 1] : null
  setupCheck.value = setup
}

async function enable() {
  if (!state.value || !startPeriod.value) return
  saving.value = true
  try {
    const updated = await payrollApi.setActivation({
      enabled: true,
      start_period: startPeriod.value,
      row_version: state.value.row_version,
    })
    if (capabilities.value) capabilities.value.state = updated
    toast.success(t('payroll.activation.enabled'))
    void loadMonthStatus()
  } catch (error: any) {
    if (error?.response?.data?.error?.code === 'row_version_conflict') {
      toast.warning(t('payroll.activation.conflict'))
      await load()
    } else {
      toast.error(error?.response?.data?.error?.message || t('payroll.activation.failed'))
    }
  } finally {
    saving.value = false
  }
}

async function disableSetup() {
  if (!state.value || state.value.status !== 'setup') return
  if (!window.confirm(t('payroll.activation.disable_confirm'))) return
  saving.value = true
  try {
    const updated = await payrollApi.setActivation({
      enabled: false,
      start_period: null,
      row_version: state.value.row_version,
    })
    if (capabilities.value) capabilities.value.state = updated
    toast.success(t('payroll.activation.disabled'))
  } catch (error: any) {
    if (error?.response?.data?.error?.code === 'row_version_conflict') {
      toast.warning(t('payroll.activation.conflict'))
      await load()
    } else {
      toast.error(error?.response?.data?.error?.message || t('payroll.activation.failed'))
    }
  } finally {
    saving.value = false
  }
}

function productionQualified(updatedState: PayrollCapabilitiesResponse['state']) {
  if (capabilities.value) capabilities.value.state = updatedState
}

onMounted(load)
</script>

<template>
  <div class="space-y-6">
    <header>
      <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
          <h1 class="text-2xl font-semibold text-neutral-900">{{ t('payroll.title') }}</h1>
          <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.subtitle') }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
          <span
            v-if="state"
            class="inline-flex items-center rounded-full px-2.5 py-1 text-xs font-medium"
            :class="isEnabled ? 'bg-payroll-50 text-payroll-600' : 'bg-neutral-100 text-neutral-600'"
          >
            {{ t(`payroll.status.${state.status}`) }}
          </span>
          <ActionBar v-if="state && isEnabled" :actions="actions" />
        </div>
      </div>
    </header>

    <div v-if="loading" class="grid grid-cols-1 gap-4 md:grid-cols-3">
      <div v-for="index in 3" :key="index" class="h-32 animate-pulse rounded-xl bg-neutral-100" />
    </div>

    <template v-else-if="capabilities && state">
      <section
        v-if="!isEnabled"
        class="rounded-xl border border-payroll-500/30 bg-payroll-50 p-4 sm:p-6"
      >
        <div class="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
          <div class="max-w-2xl">
            <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.activation.title') }}</h2>
            <p class="mt-1 text-sm text-neutral-600">{{ t('payroll.activation.description') }}</p>
          </div>
          <div v-if="canConfigure" class="flex flex-wrap items-end gap-3">
            <label class="block">
              <span class="mb-1 block text-xs font-medium text-neutral-600">
                {{ t('payroll.activation.start_period') }}
              </span>
              <input
                v-model="startPeriod"
                type="month"
                min="2024-01"
                class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
              >
            </label>
            <button :class="btnFilled('primary')" :disabled="saving || !startPeriod" @click="enable">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path :d="ICONS.play" />
              </svg>
              {{ saving ? t('common.saving') : t('payroll.activation.enable') }}
            </button>
          </div>
        </div>
      </section>

      <div v-else class="space-y-6">
        <section
          v-if="needsProductionQualification"
          class="rounded-xl border border-warning-500/40 bg-warning-50 p-4 sm:p-6"
          data-test="production-qualification-notice"
        >
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.activation.qualification_title') }}</h2>
          <p class="mt-1 max-w-4xl text-sm text-neutral-700">{{ t('payroll.activation.qualification_description') }}</p>
          <p class="mt-2 max-w-4xl text-xs text-neutral-600">{{ t('payroll.activation.qualification_single_accountant') }}</p>
        </section>

        <section
          v-if="needsProductionQualification && companyCapabilityBlockers.length > 0"
          class="rounded-xl border border-danger-500/40 bg-danger-50 p-4 sm:p-6"
          data-test="company-capability-blockers"
        >
          <h2 class="text-lg font-semibold text-danger-800">{{ t('payroll.activation.qualification.company_blockers.title') }}</h2>
          <p class="mt-1 max-w-4xl text-sm text-danger-700">{{ t('payroll.activation.qualification.company_blockers.description') }}</p>
          <ul class="mt-3 space-y-2 text-sm text-danger-800">
            <li v-for="blocker in companyCapabilityBlockers" :key="`${blocker.code}:${blocker.source_type}:${blocker.source_id}`" class="flex gap-2">
              <span aria-hidden="true">•</span>
              <span>{{ companyCapabilityMessage(blocker) }}</span>
            </li>
          </ul>
        </section>

        <PayrollProductionQualificationPanel
          v-if="needsProductionQualification && canConfigure"
          :state="state"
          :matrix-version="capabilities.support_matrix.version"
          :production-ready="capabilities.company_capability.production_ready"
          @qualified="productionQualified"
          @refresh="load"
        />

        <section
          v-if="setupBlockers.length > 0"
          class="rounded-xl border border-warning-500/40 bg-warning-50 p-4 sm:p-6"
          data-test="setup-blockers"
        >
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="max-w-3xl">
              <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.dashboard.setup.title') }}</h2>
              <p class="mt-1 text-sm text-neutral-700">{{ t('payroll.dashboard.setup.description') }}</p>
            </div>
            <RouterLink :to="{ name: 'payroll-settings' }" :class="btnOutline('warning')">
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.edit" /></svg>
              {{ t('payroll.dashboard.setup.action') }}
            </RouterLink>
          </div>
          <ul class="mt-3 space-y-1.5 text-sm text-warning-800">
            <li v-for="item in setupBlockers" :key="item.code" class="flex gap-2">
              <span aria-hidden="true">•</span>
              <span>{{ item.message }}</span>
            </li>
          </ul>
        </section>

        <PayrollGuide ref="guide" />

        <section
          class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6"
          data-test="monthly-workspace"
        >
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.dashboard.month.title') }}</h2>
              <p class="mt-1 text-sm text-neutral-500">
                {{ t('payroll.dashboard.month.description', { period: currentPeriod }) }}
              </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
              <span
                class="rounded-full px-2.5 py-1 text-xs font-medium"
                :class="runStatusClass"
                data-test="run-status"
              >
                {{ currentRun
                  ? t('payroll.dashboard.month.run_status', { status: t(`payroll.runs.status.${currentRun.status}`) })
                  : t('payroll.dashboard.month.run_missing') }}
              </span>
              <span class="rounded-full bg-payroll-50 px-2.5 py-1 text-xs font-medium text-payroll-700">
                {{ formatPeriod(currentPeriod) }}
              </span>
            </div>
          </div>
          <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <RouterLink
              :to="{ name: 'payroll-quick-inputs' }"
              class="group rounded-lg border border-payroll-500/40 bg-payroll-50 p-4 transition hover:border-payroll-500 hover:shadow-sm"
            >
              <svg class="h-5 w-5 text-payroll-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.coin" /></svg>
              <h3 class="mt-3 font-semibold text-neutral-900">{{ t('payroll.dashboard.month.quick_inputs') }}</h3>
              <p class="mt-1 text-xs text-neutral-600">{{ t('payroll.dashboard.month.quick_inputs_hint') }}</p>
            </RouterLink>
            <RouterLink
              :to="{ name: 'payroll-runs' }"
              class="group rounded-lg border border-neutral-200 p-4 transition hover:border-payroll-500/60 hover:bg-payroll-50 hover:shadow-sm"
            >
              <svg class="h-5 w-5 text-payroll-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
              <h3 class="mt-3 font-semibold text-neutral-900">{{ t('payroll.dashboard.month.runs') }}</h3>
              <p class="mt-1 text-xs text-neutral-600">{{ t('payroll.dashboard.month.runs_hint') }}</p>
            </RouterLink>
            <RouterLink
              :to="{ name: 'payroll-people' }"
              class="group rounded-lg border border-neutral-200 p-4 transition hover:border-payroll-500/60 hover:bg-payroll-50 hover:shadow-sm"
            >
              <svg class="h-5 w-5 text-payroll-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.user" /></svg>
              <h3 class="mt-3 font-semibold text-neutral-900">{{ t('payroll.dashboard.month.people') }}</h3>
              <p class="mt-1 text-xs text-neutral-600">{{ t('payroll.dashboard.month.people_hint') }}</p>
            </RouterLink>
            <RouterLink
              :to="{ name: 'payroll-payments' }"
              class="group rounded-lg border border-neutral-200 p-4 transition hover:border-payroll-500/60 hover:bg-payroll-50 hover:shadow-sm"
            >
              <svg class="h-5 w-5 text-payroll-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.checkCircle" /></svg>
              <h3 class="mt-3 font-semibold text-neutral-900">{{ t('payroll.dashboard.month.payments') }}</h3>
              <p class="mt-1 text-xs text-neutral-600">{{ t('payroll.dashboard.month.payments_hint') }}</p>
            </RouterLink>
            <RouterLink
              :to="{ name: 'payroll-documents' }"
              class="group rounded-lg border border-neutral-200 p-4 transition hover:border-payroll-500/60 hover:bg-payroll-50 hover:shadow-sm"
            >
              <svg class="h-5 w-5 text-payroll-600" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg>
              <h3 class="mt-3 font-semibold text-neutral-900">{{ t('payroll.dashboard.month.documents') }}</h3>
              <p class="mt-1 text-xs text-neutral-600">{{ t('payroll.dashboard.month.documents_hint') }}</p>
            </RouterLink>
          </div>
        </section>

        <PayrollEmployeeCards :period="currentPeriod" />
      </div>

      <!--
        Matice podporovaných scénářů je diagnostika pro podporu, ne informace
        pro zaměstnavatele: jsou v ní interní identifikátory epiců a verze
        support matrix. Na stránce, kterou uživatel používá k práci, jen
        budí dojem nehotového produktu. Zůstává dostupná superadminovi
        a přes API; běžný uživatel ji nevidí.
      -->
      <details
        v-if="auth.isSuperadmin"
        class="rounded-xl border border-neutral-200 bg-surface shadow-sm"
        data-test="support-diagnostics"
      >
        <summary class="cursor-pointer px-4 py-4 text-sm font-medium text-neutral-700 sm:px-6">
          {{ t('payroll.capabilities.diagnostics') }}
        </summary>
        <section class="border-t border-neutral-200 p-4 sm:p-6">
          <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.capabilities.title') }}</h2>
          <p class="mt-1 text-sm text-neutral-500">{{ t('payroll.capabilities.description') }}</p>
          <dl class="mt-4 grid grid-cols-2 gap-3 text-sm lg:grid-cols-4">
            <div class="rounded-lg bg-neutral-50 p-3">
              <dt class="text-xs text-neutral-500">{{ t('payroll.dashboard.start_period') }}</dt>
              <dd class="mt-1 font-semibold text-neutral-900">{{ state.start_period }}</dd>
            </div>
            <div class="rounded-lg bg-neutral-50 p-3">
              <dt class="text-xs text-neutral-500">{{ t('payroll.dashboard.supported_years') }}</dt>
              <dd class="mt-1 font-semibold text-neutral-900">{{ capabilities.support_matrix.supported_years.join(', ') }}</dd>
            </div>
            <div class="rounded-lg bg-neutral-50 p-3">
              <dt class="text-xs text-neutral-500">{{ t('payroll.dashboard.available_features') }}</dt>
              <dd class="mt-1 font-semibold text-neutral-900">{{ availableFeatures.length }}</dd>
            </div>
            <div class="rounded-lg bg-neutral-50 p-3">
              <dt class="text-xs text-neutral-500">{{ t('payroll.dashboard.matrix_version') }}</dt>
              <dd class="mt-1 break-all font-semibold text-neutral-900">{{ capabilities.support_matrix.version }}</dd>
            </div>
          </dl>

          <div class="mt-4 hidden overflow-x-auto md:block">
          <table class="min-w-full divide-y divide-neutral-200 text-sm">
            <thead>
              <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
                <th class="px-3 py-2">{{ t('payroll.capabilities.feature') }}</th>
                <th class="px-3 py-2">{{ t('payroll.capabilities.status') }}</th>
                <th class="px-3 py-2">{{ t('payroll.capabilities.availability') }}</th>
                <th class="px-3 py-2">{{ t('payroll.capabilities.epic') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="feature in capabilities.support_matrix.features" :key="feature.key">
                <td class="px-3 py-3 font-medium text-neutral-900">
                  {{ t(`payroll.features.${feature.key}`) }}
                </td>
                <td class="px-3 py-3 text-neutral-600">
                  {{ t(`payroll.support_status.${feature.status}`) }}
                </td>
                <td class="px-3 py-3">
                  <span
                    class="rounded-full px-2 py-1 text-xs font-medium"
                    :class="feature.available ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'"
                  >
                    {{ t(feature.available ? 'payroll.capabilities.available' : 'payroll.capabilities.planned') }}
                  </span>
                </td>
                <td class="px-3 py-3 text-neutral-500">{{ feature.min_epic }}</td>
              </tr>
            </tbody>
          </table>
          </div>

          <div class="mt-4 grid grid-cols-1 gap-3 md:hidden">
          <article
            v-for="feature in capabilities.support_matrix.features"
            :key="feature.key"
            class="rounded-lg border border-neutral-200 p-3"
          >
            <div class="flex items-start justify-between gap-3">
              <h3 class="font-medium text-neutral-900">{{ t(`payroll.features.${feature.key}`) }}</h3>
              <span
                class="shrink-0 rounded-full px-2 py-1 text-xs font-medium"
                :class="feature.available ? 'bg-success-50 text-success-600' : 'bg-neutral-100 text-neutral-600'"
              >
                {{ t(feature.available ? 'payroll.capabilities.available' : 'payroll.capabilities.planned') }}
              </span>
            </div>
            <dl class="mt-3 grid grid-cols-2 gap-2 text-xs">
              <div>
                <dt class="text-neutral-500">{{ t('payroll.capabilities.status') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ t(`payroll.support_status.${feature.status}`) }}</dd>
              </div>
              <div>
                <dt class="text-neutral-500">{{ t('payroll.capabilities.epic') }}</dt>
                <dd class="mt-0.5 text-neutral-800">{{ feature.min_epic }}</dd>
              </div>
            </dl>
          </article>
          </div>

          <p v-if="plannedFeatures.length" class="mt-4 text-xs text-neutral-500">
            {{ t('payroll.capabilities.planned_hint') }}
          </p>
        </section>
      </details>
    </template>
  </div>
</template>
