<script setup lang="ts">
import { computed, ref, onMounted, reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute } from 'vue-router'
import { accountingApi } from '@/api/accounting'
import {
  closingApi,
  closingSettingsApi,
  type ClosingPeriod,
  type ClosingPeriodStatus,
  type AccountingClosingSettings,
} from '@/api/closing'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { useHotkey } from '@/composables/useHotkey'
import { formatDate } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'

// embedded = vykresleno jako záložka uvnitř ToolsPage.vue (Nástroje); hlavičku dodává obálka.
defineProps<{ embedded?: boolean }>()

const { t, te } = useI18n()
const auth = useAuthStore()
const supplierStore = useSupplierStore()
const toast = useToast()

const periods = ref<ClosingPeriod[]>([])
const loading = ref(false)
const error = ref('')

async function load() {
  loading.value = true
  try { periods.value = await accountingApi.listPeriods() as unknown as ClosingPeriod[] }
  finally { loading.value = false }
}

/*
 * `?fiscal_year=YYYY` — proklik z hlášky „pro tohle datum neexistuje účetní
 * období" (toast u zaúčtování, karta „Akce pro tebe"). Rovnou otevře formulář
 * s tím rokem, aby náprava byla jedno kliknutí, ne hledání tlačítka na stránce,
 * kterou uživatel vidí poprvé. Existuje-li rok už teď, formulář se neotevírá —
 * uživatel se sem dostal z zastaralé hlášky a má vidět seznam.
 */
const route = useRoute()
onMounted(async () => {
  await load()
  const requested = Number(route.query.fiscal_year)
  if (!Number.isInteger(requested) || requested <= 0) return
  if (periods.value.some(p => p.fiscal_year === requested)) return
  openCreate(requested)
})

const showForm = ref(false)
useHotkey('escape', () => {
  if (showForm.value) showForm.value = false
  if (statusDialog.period) statusDialog.period = null
  if (showSettings.value) showSettings.value = false
  if (startDialog.period) startDialog.period = null
})

const currentYear = new Date().getFullYear()
const form = reactive({
  fiscal_year: currentYear,
  starts_on: `${currentYear}-01-01`,
  ends_on: `${currentYear}-12-31`,
})

function openCreate(year: number = currentYear) {
  error.value = ''
  Object.assign(form, defaultBounds(year))
  showForm.value = true
}

/*
 * Předvyplněné hranice nového období. Kalendářní rok je default, ale firma
 * s hospodářským rokem (§21a ZDP) by dostala špatné datum — proto se tvar dědí
 * z NAVAZUJÍCÍHO existujícího období, když nějaké je: starts_on = konec
 * předchozího + 1 den, délka rok. Stejné pravidlo jako na serveru
 * (AccountingPeriodRepository::nextPeriodBounds).
 */
function defaultBounds(year: number): { fiscal_year: number, starts_on: string, ends_on: string } {
  const previous = periods.value
    .filter(p => p.ends_on && p.ends_on < `${year + 1}-01-01`)
    .sort((a, b) => String(b.ends_on).localeCompare(String(a.ends_on)))[0]
  if (previous?.ends_on) {
    const start = new Date(`${previous.ends_on}T00:00:00Z`)
    start.setUTCDate(start.getUTCDate() + 1)
    const end = new Date(start)
    end.setUTCFullYear(end.getUTCFullYear() + 1)
    end.setUTCDate(end.getUTCDate() - 1)
    const iso = (d: Date) => d.toISOString().slice(0, 10)
    if (start.getUTCFullYear() === year) {
      return { fiscal_year: year, starts_on: iso(start), ends_on: iso(end) }
    }
  }
  // Bez navazujícího období rozhoduje nastavení firmy „Účetní rok začíná" (1 = kalendářní).
  const month = supplierStore.currentSupplier?.fiscal_year_start_month ?? 1
  if (month > 1 && month <= 12) {
    const mm = String(month).padStart(2, '0')
    const end = new Date(Date.UTC(year + 1, month - 1, 1))
    end.setUTCDate(end.getUTCDate() - 1)
    return { fiscal_year: year, starts_on: `${year}-${mm}-01`, ends_on: end.toISOString().slice(0, 10) }
  }
  return { fiscal_year: year, starts_on: `${year}-01-01`, ends_on: `${year}-12-31` }
}

// Označení období = účetní rok firmy, do kterého patří počátek (server to vyžaduje).
// U zkráceného prvního období hospodářského roku (vznik 1. 3., rok od srpna) je to
// předchozí kalendářní rok.
watch(() => form.starts_on, (startsOn) => {
  const m = /^(\d{4})-(\d{2})-\d{2}$/.exec(startsOn ?? '')
  if (!m) return
  const startMonth = supplierStore.currentSupplier?.fiscal_year_start_month ?? 1
  const year = Number(m[1])
  form.fiscal_year = startMonth > 1 && Number(m[2]) < startMonth ? year - 1 : year
})

async function save() {
  error.value = ''
  try {
    await accountingApi.createPeriod({
      fiscal_year: Number(form.fiscal_year),
      starts_on: form.starts_on,
      ends_on: form.ends_on,
    })
    showForm.value = false
    toast.success(t('common.created'))
    await load()
  } catch (e: any) {
    error.value = localizedError(e) || t('common.error')
  }
}

// ── Začátek prvního období (firma vzniklá v průběhu roku, § 3 ZoÚ) ─────────
// Server dovolí posunout jen začátek nejstaršího otevřeného období; tlačítko se
// proto ukazuje jen u něj.
const firstPeriodId = computed(() => [...periods.value]
  .sort((a, b) => String(a.starts_on).localeCompare(String(b.starts_on)))[0]?.id ?? null)

function canChangeStart(p: ClosingPeriod): boolean {
  return auth.canWrite('accounting.periods.manage') && p.id === firstPeriodId.value && p.status === 'open'
}

const startDialog = reactive({
  period: null as ClosingPeriod | null,
  starts_on: '',
  saving: false,
  error: '',
})

function openStartDialog(p: ClosingPeriod) {
  startDialog.period = p
  startDialog.starts_on = p.starts_on
  startDialog.saving = false
  startDialog.error = ''
}

function startError(e: any): string {
  const err = e?.response?.data?.error
  const key = `accounting.periods.start_errors.${err?.code}`
  if (err?.code && te(key)) return t(key, { count: err.entries_count ?? 0, date: formatDate(err.first_entry_date ?? '') })
  return localizedError(e) || t('common.error')
}

async function submitStartDialog() {
  const p = startDialog.period
  if (!p || startDialog.saving) return
  startDialog.saving = true
  startDialog.error = ''
  try {
    await accountingApi.updatePeriodStart(p.id, startDialog.starts_on, p.row_version)
    startDialog.period = null
    toast.success(t('common.saved'))
    await load()
  } catch (e: any) {
    startDialog.error = startError(e)
    if (e?.response?.data?.error?.code === 'version_conflict') await load()
  } finally {
    startDialog.saving = false
  }
}

// ── Stavové přechody (F4 R2: schválit / znovuotevřít; schválení je konečné, API vrací approval_is_final) ────
type StatusAction = 'approve' | 'reopen'

const statusDialog = reactive({
  period: null as ClosingPeriod | null,
  action: 'approve' as StatusAction,
  reason: '',
  saving: false,
})

function openStatusDialog(p: ClosingPeriod, action: StatusAction) {
  statusDialog.period = p
  statusDialog.action = action
  statusDialog.reason = ''
  statusDialog.saving = false
}

function localizedError(e: any): string {
  const code = e?.response?.data?.error?.code
  const key = `accounting.closing.errors.${code}`
  const v = code ? t(key) : ''
  if (v && v !== key) return v
  return e?.response?.data?.error?.message || ''
}

async function submitStatusDialog() {
  const p = statusDialog.period
  if (!p) return
  const needsReason = statusDialog.action !== 'approve'
  if (needsReason && statusDialog.reason.trim().length < 10) {
    toast.warning(t('accounting.closing.reopen.reason_required'))
    return
  }
  const target: ClosingPeriodStatus =
    statusDialog.action === 'approve' ? 'approved'
    : 'open'
  statusDialog.saving = true
  try {
    await closingApi.setPeriodStatus(p.id, {
      status: target,
      row_version: p.row_version,
      ...(needsReason ? { reason: statusDialog.reason.trim() } : {}),
      ...(statusDialog.action !== 'reopen' ? { confirm: true } : {}),
    })
    statusDialog.period = null
    toast.success(t('common.saved'))
  } catch (e: any) {
    if (e?.response?.status === 409) {
      toast.error(t('accounting.closing.errors.version_conflict'))
      statusDialog.period = null
    } else {
      toast.error(localizedError(e) || t('common.error'))
    }
  } finally {
    statusDialog.saving = false
    await load()
  }
}

// ── Nastavení uzávěrky ──────────────────────────────────────────────────────
// Číselné řady deníku se přesunuly do Nástrojů (/utilities?section=document-series).
const showSettings = ref(false)
const settings = ref<AccountingClosingSettings | null>(null)
const settingsLoading = ref(false)
const settingsSaving = ref(false)

async function openSettings() {
  showSettings.value = true
  settingsLoading.value = true
  try { settings.value = await closingSettingsApi.get() }
  catch (e: any) {
    settings.value = null
    toast.error(localizedError(e) || t('common.error'))
  } finally {
    settingsLoading.value = false
  }
}

// Task 14: přepnutí na paušál předvyplní 50 %, ať uživatel nevidí prázdné pole (a nespadne na 422).
function onAccrualModeChange() {
  if (settings.value && settings.value.small_asset_accrual_mode === 'flat_pct'
      && (settings.value.small_asset_accrual_pct === null || settings.value.small_asset_accrual_pct === undefined)) {
    settings.value.small_asset_accrual_pct = 50
  }
}

async function saveSettings() {
  if (!settings.value) return
  settingsSaving.value = true
  try {
    await closingSettingsApi.update({
      ...settings.value,
      // Prázdné pole (v-model.number ho drží jako '') → null, ať BE nespadne na
      // "musí být celé číslo ≥ 0, nebo null" (ReportingSettingsAction::update).
      avg_employees: settings.value.avg_employees === null || (settings.value.avg_employees as unknown) === ''
        ? null : Number(settings.value.avg_employees),
      statutory_audit: settings.value.statutory_audit ? 1 : 0,
      manual_doc_series: settings.value.manual_doc_series ? 1 : 0,
      fx_reversal_at_open: settings.value.fx_reversal_at_open ? 1 : 0,
      comparative_from_prior_year: settings.value.comparative_from_prior_year ? 1 : 0,
      tax_authority_offset: settings.value.tax_authority_offset ? 1 : 0,
      tax_authority_offset_from_year: settings.value.tax_authority_offset_from_year === null
        || (settings.value.tax_authority_offset_from_year as unknown) === ''
        || settings.value.tax_authority_offset_from_year === undefined
        ? null : Number(settings.value.tax_authority_offset_from_year),
    })
    toast.success(t('common.saved'))
  } catch (e: any) {
    toast.error(localizedError(e) || t('common.error'))
  } finally {
    settingsSaving.value = false
  }
}

function statusLabel(status: string): string {
  return t(`accounting.periods.status.${status}`)
}
function statusBadge(status: string): string {
  if (status === 'open') return 'bg-success-50 text-success-600'
  if (status === 'closing') return 'bg-warning-50 text-warning-600'
  if (status === 'approved') return 'bg-purple-50 text-purple-700'
  return 'bg-neutral-100 text-neutral-500'
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-center justify-between gap-2 mb-4">
      <div v-if="!embedded">
        <h1 class="text-2xl font-semibold">{{ t('accounting.periods.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.periods.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2">
        <button v-if="auth.canWrite('accounting.periods.manage')" @click="openSettings" :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
          {{ t('accounting.closing.settings.title') }}
        </button>
        <button v-if="auth.canWrite('accounting.periods.manage')" @click="openCreate()" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('accounting.periods.new') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="periods.length === 0" boxed icon="clipboardCheck"
      :title="t('accounting.periods.empty')"
      :cta="auth.canWrite('accounting.periods.manage') ? t('accounting.periods.new') : undefined"
      @action="openCreate()" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Desktop -->
      <div class="hidden md:block overflow-x-auto">
        <table class="w-full text-sm table-sticky-first">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.periods.fiscal_year') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.periods.starts_on') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.periods.ends_on') }}</th>
              <th class="px-3 py-2 text-center font-medium">{{ t('accounting.periods.status_col') }}</th>
              <th class="px-3 py-2 text-left font-medium w-96">{{ t('common.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="p in periods" :key="p.id">
              <td class="px-3 py-2 font-semibold">
                {{ p.fiscal_year }}
                <!-- Období, které nezaložila účetní, ale automat (import historie,
                     přelom roku) — jinak by v seznamu svítil rok bez vysvětlení. -->
                <span v-if="p.created_reason" class="ml-1.5 align-middle text-[10px] font-medium px-1.5 py-0.5 rounded bg-neutral-100 text-neutral-500"
                  :title="t('accounting.periods.auto_created_hint_' + p.created_reason)">
                  {{ t('accounting.periods.auto_created') }}
                </span>
              </td>
              <td class="px-3 py-2">{{ formatDate(p.starts_on) }}</td>
              <td class="px-3 py-2">{{ formatDate(p.ends_on) }}</td>
              <td class="px-3 py-2 text-center">
                <span class="text-xs px-2 py-0.5 rounded font-medium" :class="statusBadge(p.status)">{{ statusLabel(p.status) }}</span>
              </td>
              <td class="px-3 py-2">
                <div class="flex flex-wrap items-center gap-1.5">
                  <RouterLink v-if="auth.canWrite('accounting.periods.manage')"
                    :to="`/accounting/periods/${p.id}/closing`"
                    class="text-xs px-2 py-1 rounded-md border border-primary-200 text-primary-600 hover:bg-primary-50 font-medium">
                    {{ p.status === 'approved' ? t('accounting.closing.detail_link') : t('accounting.closing.wizard_link') }}
                  </RouterLink>
                  <button v-if="canChangeStart(p)" @click="openStartDialog(p)" data-test="period-change-start"
                    class="cursor-pointer text-xs px-2 py-1 rounded-md border border-neutral-300 text-neutral-700 hover:bg-neutral-50 font-medium">
                    {{ t('accounting.periods.change_start') }}
                  </button>
                  <template v-if="auth.canWrite('accounting.periods.close')">
                    <button v-if="p.status === 'closed'" @click="openStatusDialog(p, 'approve')"
                      class="cursor-pointer text-xs px-2 py-1 rounded-md border border-warning-500/50 text-warning-600 hover:bg-warning-50 font-medium">
                      {{ t('accounting.closing.approve.button') }}
                    </button>
                    <button v-if="p.status === 'closed'" @click="openStatusDialog(p, 'reopen')"
                      class="cursor-pointer text-xs px-2 py-1 rounded-md border border-warning-300 text-warning-600 hover:bg-warning-50 font-medium">
                      {{ t('accounting.closing.reopen.button') }}
                    </button>
                  </template>
                  <span v-if="p.status === 'approved'" class="text-xs text-neutral-400"
                    :title="t('accounting.closing.approve.locked_hint')">🔒</span>
                  <RouterLink v-if="auth.can('reports.export')"
                    :to="{ name: 'accounting-closing-package', params: { id: p.id } }"
                    class="text-xs px-2 py-1 rounded-md border border-success-200 text-success-700 hover:bg-success-50 font-medium">
                    {{ t('accounting.closing_package.title') }}
                  </RouterLink>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <!-- Mobile -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="p in periods" :key="`m-${p.id}`" class="p-3 space-y-2">
          <div class="flex items-baseline justify-between gap-2">
            <span class="font-semibold text-lg">{{ p.fiscal_year }}</span>
            <span class="text-xs px-2 py-0.5 rounded font-medium" :class="statusBadge(p.status)">{{ statusLabel(p.status) }}</span>
          </div>
          <div class="text-xs text-neutral-500">{{ formatDate(p.starts_on) }} – {{ formatDate(p.ends_on) }}</div>
          <div class="flex flex-wrap items-center gap-1.5 pt-1">
            <RouterLink v-if="auth.canWrite('accounting.periods.manage')"
              :to="`/accounting/periods/${p.id}/closing`"
              class="text-xs px-2 py-1 rounded-md border border-primary-200 text-primary-600 hover:bg-primary-50 font-medium">
              {{ p.status === 'approved' ? t('accounting.closing.detail_link') : t('accounting.closing.wizard_link') }}
            </RouterLink>
            <button v-if="canChangeStart(p)" @click="openStartDialog(p)"
              class="cursor-pointer text-xs px-2 py-1 rounded-md border border-neutral-300 text-neutral-700 hover:bg-neutral-50 font-medium">
              {{ t('accounting.periods.change_start') }}
            </button>
            <template v-if="auth.canWrite('accounting.periods.close')">
              <button v-if="p.status === 'closed'" @click="openStatusDialog(p, 'approve')"
                class="cursor-pointer text-xs px-2 py-1 rounded-md border border-warning-500/50 text-warning-600 hover:bg-warning-50 font-medium">
                {{ t('accounting.closing.approve.button') }}
              </button>
              <button v-if="p.status === 'closed'" @click="openStatusDialog(p, 'reopen')"
                class="cursor-pointer text-xs px-2 py-1 rounded-md border border-warning-300 text-warning-600 hover:bg-warning-50 font-medium">
                {{ t('accounting.closing.reopen.button') }}
              </button>
            </template>
            <RouterLink v-if="auth.can('reports.export')"
              :to="{ name: 'accounting-closing-package', params: { id: p.id } }"
              class="text-xs px-2 py-1 rounded-md border border-success-200 text-success-700 hover:bg-success-50 font-medium">
              {{ t('accounting.closing_package.title') }}
            </RouterLink>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: nové období -->
    <div v-if="showForm" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">{{ t('accounting.periods.new_title') }}</h3>
        <div class="space-y-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.periods.fiscal_year') }}</label>
            <input v-model.number="form.fiscal_year" type="number" min="2000" max="2200"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div class="grid grid-cols-2 gap-3">
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.periods.starts_on') }}</label>
              <DateInput v-model="form.starts_on" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
            <div>
              <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.periods.ends_on') }}</label>
              <DateInput v-model="form.ends_on" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
            </div>
          </div>
          <div v-if="error" class="text-sm text-danger-500">{{ error }}</div>
          <div class="flex justify-end gap-2 pt-2">
            <button @click="showForm = false" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
            <button @click="save" :class="btnFilled('primary')">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
              {{ t('common.create') }}
            </button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: začátek prvního období -->
    <div v-if="startDialog.period" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5" data-test="period-start-dialog">
        <h3 class="text-lg font-semibold mb-3">{{ t('accounting.periods.change_start_title', { year: startDialog.period.fiscal_year }) }}</h3>
        <p class="text-sm text-neutral-600 mb-3">{{ t('accounting.periods.change_start_hint') }}</p>
        <div class="grid grid-cols-2 gap-3 mb-3">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.periods.starts_on') }}</label>
            <DateInput v-model="startDialog.starts_on" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.periods.ends_on') }}</label>
            <div class="h-10 px-3 flex items-center text-sm text-neutral-500">{{ formatDate(startDialog.period.ends_on) }}</div>
          </div>
        </div>
        <div v-if="startDialog.error" class="text-sm text-danger-500 mb-3">{{ startDialog.error }}</div>
        <div class="flex flex-wrap justify-end gap-2">
          <button @click="startDialog.period = null" :class="btnOutline('neutral')">{{ t('common.cancel') }}</button>
          <button @click="submitStartDialog" :disabled="startDialog.saving || !startDialog.starts_on" :class="btnFilled('primary')" data-test="period-start-save">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('common.save') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Modal: schválení / znovuotevření -->
    <div v-if="statusDialog.period" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-md w-full p-5">
        <h3 class="text-lg font-semibold mb-3">
          {{ t(`accounting.closing.${statusDialog.action === 'reopen' ? 'reopen' : 'approve'}.${statusDialog.action === 'approve' ? 'confirm_title' : 'title'}`, { year: statusDialog.period.fiscal_year }) }}
        </h3>
        <p class="text-sm text-neutral-600 mb-3">
          <template v-if="statusDialog.action === 'approve'">{{ t('accounting.closing.approve.confirm_text_17_7') }}</template>
          <template v-else>{{ t('accounting.closing.reopen.text') }}</template>
        </p>
        <div v-if="statusDialog.action !== 'approve'" class="mb-3">
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.closing.reopen.reason') }}</label>
          <textarea v-model="statusDialog.reason" rows="3"
            :placeholder="t('accounting.closing.reopen.reason_placeholder')"
            class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm"></textarea>
          <p class="text-xs text-neutral-400 mt-1">{{ t('accounting.closing.reopen.reason_required') }}</p>
        </div>
        <div class="flex justify-end gap-2">
          <button @click="statusDialog.period = null" :class="btnOutline('neutral')">
            {{ t('common.cancel') }}
          </button>
          <button @click="submitStatusDialog" :disabled="statusDialog.saving" :class="btnFilled('warning')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('common.confirm') }}
          </button>
        </div>
      </div>
    </div>

    <!-- Modal: nastavení uzávěrky -->
    <div v-if="showSettings" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
      <div class="bg-surface rounded-xl shadow-lg max-w-xl w-full p-5 max-h-[85vh] overflow-y-auto">
        <h3 class="text-lg font-semibold mb-3">{{ t('accounting.closing.settings.title') }}</h3>
        <div v-if="settingsLoading" class="text-sm text-neutral-500 py-4">{{ t('common.loading') }}</div>
        <template v-else-if="settings">
          <div class="space-y-2 mb-3">
            <label class="block text-sm">
              <span class="font-medium">{{ t('accounting.closing.settings.avg_employees') }}:</span>
              <input type="number" min="0" step="1" v-model.number="settings.avg_employees"
                class="mt-1 ml-2 w-32 h-9 px-2 border border-neutral-300 rounded-md text-sm" />
              <span class="block text-xs text-neutral-500 mt-0.5">{{ t('accounting.closing.settings.avg_employees_hint') }}</span>
            </label>
            <label class="flex items-start gap-2 text-sm cursor-pointer">
              <input type="checkbox" v-model="settings.statutory_audit" class="mt-0.5" />
              <span>{{ t('accounting.closing.settings.statutory_audit') }}
                <span class="block text-xs text-neutral-500">{{ t('accounting.closing.settings.statutory_audit_hint') }}</span></span>
            </label>
            <label class="flex items-start gap-2 text-sm cursor-pointer">
              <input type="checkbox" v-model="settings.manual_doc_series" class="mt-0.5" />
              <span>{{ t('accounting.closing.settings.manual_doc_series') }}
                <span class="block text-xs text-neutral-500">{{ t('accounting.closing.settings.manual_doc_series_hint') }}</span></span>
            </label>
            <label class="flex items-start gap-2 text-sm cursor-pointer">
              <input type="checkbox" v-model="settings.fx_reversal_at_open" class="mt-0.5" />
              <span>{{ t('accounting.closing.settings.fx_reversal_at_open') }}
                <span class="block text-xs text-neutral-500">{{ t('accounting.closing.settings.fx_reversal_at_open_hint') }}</span></span>
            </label>
            <label class="flex items-start gap-2 text-sm cursor-pointer">
              <input type="checkbox" v-model="settings.comparative_from_prior_year" class="mt-0.5" data-test="comparative-from-prior-year" />
              <span>{{ t('accounting.closing.settings.comparative_from_prior_year') }}
                <span class="block text-xs text-neutral-500">{{ t('accounting.closing.settings.comparative_from_prior_year_hint') }}</span></span>
            </label>
            <label class="flex items-start gap-2 text-sm cursor-pointer">
              <input type="checkbox" v-model="settings.tax_authority_offset" class="mt-0.5" data-test="tax-authority-offset" />
              <span>{{ t('accounting.closing.settings.tax_authority_offset') }}
                <span class="block text-xs text-neutral-500">{{ t('accounting.closing.settings.tax_authority_offset_hint') }}</span></span>
            </label>
            <label v-if="settings.tax_authority_offset" class="flex flex-wrap items-center gap-2 text-sm pl-6">
              {{ t('accounting.closing.settings.tax_authority_offset_from_year') }}
              <input type="number" min="1900" max="2999" step="1" v-model.number="settings.tax_authority_offset_from_year"
                :placeholder="t('accounting.closing.settings.tax_authority_offset_from_year_placeholder')"
                class="w-28 h-9 px-2 border border-neutral-300 rounded-md text-sm" data-test="tax-authority-offset-from" />
            </label>
            <div class="text-sm pt-1 border-t border-neutral-100">
              <span class="block">{{ t('accounting.closing.settings.small_asset_accrual') }}
                <span class="block text-xs text-neutral-500">{{ t('accounting.closing.settings.small_asset_accrual_hint') }}</span></span>
              <div class="flex flex-wrap items-center gap-2 mt-1.5">
                <select v-model="settings.small_asset_accrual_mode" @change="onAccrualModeChange" class="h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
                  <option value="none">{{ t('accounting.closing.small_asset.mode_none') }}</option>
                  <option value="pro_rata">{{ t('accounting.closing.small_asset.mode_pro_rata') }}</option>
                  <option value="flat_pct">{{ t('accounting.closing.small_asset.mode_flat_pct') }}</option>
                </select>
                <input v-if="settings.small_asset_accrual_mode === 'flat_pct'" v-model.number="settings.small_asset_accrual_pct"
                  type="number" step="0.01" min="0" max="100"
                  class="h-9 w-20 px-2 border border-neutral-300 rounded-md text-sm text-right" />
                <span v-if="settings.small_asset_accrual_mode === 'flat_pct'" class="text-sm text-neutral-500">%</span>
              </div>
            </div>
          </div>
          <button @click="saveSettings" :disabled="settingsSaving" :class="btnFilled('primary')">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ settingsSaving ? t('common.saving') : t('common.save') }}
          </button>
        </template>

        <div class="flex justify-end pt-3">
          <button @click="showSettings = false" :class="btnOutline('neutral')">
            {{ t('common.close') }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>
