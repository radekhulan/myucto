<script setup lang="ts">
/*
 * PAM-16 - návrh mzdových předkontací z převzatého zaúčtování.
 *
 * ⚠ Nejsou to účetní zápisy. Mzdy zaúčtuje MyÚčto vlastní cestou podle svého
 * nastavení; z původního programu se bere jen podklad pro to nastavení, jinak
 * by proti převedeným dokladům vznikla duplicita.
 *
 * Obrazovka stojí na jediné zásadě: ROZPOR SE NEZAKRÝVÁ. Když na jeden význam
 * vyšly dva účty, nabídka zůstane prázdná a oba se ukážou s počtem řádků a
 * objemem peněz - rozhodnout to může jen účetní. Uloží se výhradně to, co je
 * v nabídce vybrané; ostatní významy zůstanou na dosavadní hodnotě.
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { isAxiosError } from 'axios'
import {
  payrollPostingMapApi,
  type PayrollPostingMapEntry,
  type PayrollPostingMapKey,
  type PayrollPostingMapProposal,
  type PayrollPostingMapSource,
  type PayrollPostingMapStatus,
} from '@/api/payrollPostingMap'
import { payrollApi, type PayrollAccountOption } from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { formatMoneyMinor } from '@/composables/useFormat'
import EmptyState from '@/components/ui/EmptyState.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { payrollAccountOptions, type PayrollAccountKey } from '@/pages/payroll/payrollEmployerAccounts'
import { useMigrationWorkspace } from './migrationWorkspace'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const workspace = useMigrationWorkspace()
const canRead = computed(() => auth.canRead('payroll.settings'))
const canWrite = computed(() => auth.canWrite('payroll.settings'))

/** Předkontace => skupina v popiscích Nastavení mezd; popisky se nezdvojují. */
const ACCOUNT_GROUPS: Record<PayrollPostingMapKey, string> = {
  employment_gross_debit: 'employment_gross',
  employment_gross_credit: 'employment_gross',
  partner_gross_debit: 'partner_gross',
  partner_gross_credit: 'partner_gross',
  statutory_gross_debit: 'statutory_gross',
  statutory_gross_credit: 'statutory_gross',
  statutory_nonpartner_gross_credit: 'statutory_nonpartner_gross',
  employer_insurance_debit: 'employer_insurance',
  social_insurance_credit: 'social_insurance',
  health_insurance_credit: 'health_insurance',
  income_tax_credit: 'income_tax',
  withholding_tax_credit: 'withholding_tax',
  other_deductions_credit: 'other_deductions',
  enforcement_deductions_credit: 'enforcement_deductions',
  enforcement_fee_revenue_credit: 'enforcement_fee_revenue',
  partner_settlement_credit: 'partner_settlement',
  risky_savings_debit: 'risky_savings',
  risky_savings_credit: 'risky_savings',
  employee_receivable_debit: 'employee_receivable',
  non_deductible_benefit_debit: 'non_deductible_benefit',
  travel_expense_debit: 'travel_expense',
  accident_insurance_debit: 'accident_insurance',
  accident_insurance_credit: 'accident_insurance',
}

const loading = ref(false)
const saving = ref(false)
const loadError = ref('')
const proposal = ref<PayrollPostingMapProposal | null>(null)
const chartAccounts = ref<PayrollAccountOption[]>([])
const rowVersion = ref(0)
const choices = ref<Record<string, string>>({})
const hideSettled = ref(false)

const entries = computed<PayrollPostingMapEntry[]>(() => proposal.value?.proposal.keys ?? [])
const unmapped = computed(() => proposal.value?.proposal.unmapped ?? [])
const summary = computed(() => proposal.value?.proposal.summary ?? null)

const visibleEntries = computed(() =>
  hideSettled.value
    ? entries.value.filter(entry => entry.status !== 'missing' || choices.value[entry.key])
    : entries.value,
)

/** Co se reálně uloží. Prázdná nabídka = účetní nic nepotvrdila. */
const confirmations = computed<Record<string, string>>(() => {
  const out: Record<string, string> = {}
  for (const entry of entries.value) {
    const code = (choices.value[entry.key] ?? '').trim()
    if (code !== '' && code !== entry.current_code) out[entry.key] = code
  }
  return out
})

const confirmedCount = computed(() => Object.keys(confirmations.value).length)

function money(minor: number): string {
  return formatMoneyMinor(minor)
}

function entryLabel(entry: PayrollPostingMapEntry): string {
  const group = ACCOUNT_GROUPS[entry.key]
  const side = entry.key.endsWith('_debit') ? 'debit' : 'credit'
  return `${t(`payroll.employer.accounting.${group}`)} - ${t(`payroll.employer.${side}`)}`
}

function statusLabel(status: PayrollPostingMapStatus): string {
  return t(`payroll.posting_map.status.${status}`)
}

function statusClass(status: PayrollPostingMapStatus): string {
  return {
    unambiguous: 'bg-success-50 text-success-700',
    conflict: 'bg-danger-50 text-danger-700',
    outside_chart: 'bg-warning-50 text-warning-700',
    missing: 'bg-neutral-100 text-neutral-500',
  }[status]
}

function accountOptions(key: PayrollPostingMapKey) {
  return payrollAccountOptions(chartAccounts.value, key as PayrollAccountKey)
}

function selectedOption(key: PayrollPostingMapKey) {
  const code = choices.value[key]
  if (!code) return null
  return accountOptions(key).find(option => option.value === code) ?? { value: code, label: code }
}

function setChoice(key: PayrollPostingMapKey, value: string | null): void {
  if (value === null || value === '') {
    delete choices.value[key]
    return
  }
  choices.value[key] = value
}

/** Předvyplní jen JEDNOZNAČNÉ návrhy; rozpor ani účet mimo osnovu ne. */
function acceptUnambiguous(): void {
  for (const entry of entries.value) {
    if (entry.status === 'unambiguous' && entry.suggested_code) {
      choices.value[entry.key] = entry.suggested_code
    }
  }
}

function clearChoices(): void {
  choices.value = {}
}

async function load(source?: PayrollPostingMapSource | null): Promise<void> {
  if (!canRead.value) return
  loading.value = true
  loadError.value = ''
  try {
    const [map, accounts, settings] = await Promise.all([
      payrollPostingMapApi.show(source ?? null),
      payrollApi.accountOptions(),
      payrollApi.employerSettings(),
    ])
    proposal.value = map.proposal
    chartAccounts.value = accounts
    rowVersion.value = settings.row_version
    choices.value = {}
  } catch (error) {
    proposal.value = null
    loadError.value = apiErrorMessage(error, t('payroll.posting_map.load_failed'))
  } finally {
    loading.value = false
  }
}

async function save(): Promise<void> {
  const current = proposal.value
  if (!current || !canWrite.value || confirmedCount.value === 0 || saving.value) return
  saving.value = true
  try {
    const result = await payrollPostingMapApi.confirm({
      source: current.source,
      row_version: rowVersion.value,
      confirmations: confirmations.value,
    })
    proposal.value = result.proposal
    rowVersion.value = result.settings.row_version
    choices.value = {}
    toast.success(t('payroll.posting_map.saved'))
  } catch (error) {
    if (isAxiosError(error) && error.response?.status === 409) {
      toast.error(t('payroll.posting_map.conflict'))
      await load(current.source)
    } else {
      toast.error(apiErrorMessage(error, t('payroll.posting_map.save_failed')))
    }
  } finally {
    saving.value = false
  }
}

// Nahrání převzatých mezd může přepsat návrh; záložka zůstává namontovaná,
// takže by jinak ukazovala starý stav, dokud ji uživatel sám neobnoví.
watch(workspace.revision, () => { void load(proposal.value?.source ?? null) })
onMounted(() => void load())
</script>

<template>
  <div v-if="canRead" class="space-y-6 pb-24" data-test="payroll-posting-map">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div>
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.posting_map.title') }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.posting_map.intro') }}</p>
        <p class="mt-1 max-w-3xl text-sm text-warning-700">{{ t('payroll.posting_map.not_posted') }}</p>
      </div>
      <div class="flex flex-wrap items-center gap-2">
        <label class="flex items-center gap-2 text-sm text-neutral-600 whitespace-nowrap">
          <input v-model="hideSettled" type="checkbox" class="rounded border-neutral-300">
          {{ t('payroll.posting_map.hide_missing') }}
        </label>
        <button type="button" :class="btnOutline('neutral')" :disabled="loading" @click="load(proposal?.source ?? null)">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          <span class="whitespace-nowrap">{{ t('payroll.posting_map.reload') }}</span>
        </button>
      </div>
    </header>

    <p v-if="loadError" class="rounded-lg bg-danger-50 px-4 py-3 text-sm text-danger-700">{{ loadError }}</p>

    <EmptyState
      v-else-if="!loading && !proposal"
      variant="empty"
      accent="accent"
      :title="t('payroll.posting_map.empty_title')"
      :message="t('payroll.posting_map.empty_description')"
    />

    <template v-else-if="proposal && summary">
      <section class="grid grid-cols-1 gap-3 sm:grid-cols-4">
        <div class="rounded-lg bg-payroll-50 p-3">
          <p class="text-xs text-payroll-800">{{ t('payroll.posting_map.summary.lines') }}</p>
          <p class="mt-1 text-lg font-semibold text-payroll-950">{{ summary.line_count }}</p>
        </div>
        <div class="rounded-lg bg-success-50 p-3">
          <p class="text-xs text-success-700">{{ t('payroll.posting_map.summary.unambiguous') }}</p>
          <p class="mt-1 text-lg font-semibold text-success-700">{{ summary.unambiguous }}</p>
        </div>
        <div class="rounded-lg bg-danger-50 p-3">
          <p class="text-xs text-danger-700">{{ t('payroll.posting_map.summary.conflict') }}</p>
          <p class="mt-1 text-lg font-semibold text-danger-700">{{ summary.conflict }}</p>
        </div>
        <div class="rounded-lg bg-warning-50 p-3">
          <p class="text-xs text-warning-700">{{ t('payroll.posting_map.summary.outside_chart') }}</p>
          <p class="mt-1 text-lg font-semibold text-warning-700">{{ summary.outside_chart }}</p>
        </div>
      </section>

      <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h3 class="font-semibold text-neutral-900">{{ t('payroll.posting_map.table_title') }}</h3>
            <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.posting_map.table_hint') }}</p>
          </div>
          <div class="flex flex-wrap items-center gap-2">
            <button
              type="button"
              :class="btnOutlineSm('success')"
              :disabled="!canWrite || summary.unambiguous === 0"
              data-test="posting-map-accept-unambiguous"
              @click="acceptUnambiguous"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
              <span class="whitespace-nowrap">{{ t('payroll.posting_map.accept_unambiguous') }}</span>
            </button>
            <button type="button" :class="btnOutlineSm('neutral')" :disabled="confirmedCount === 0" @click="clearChoices">
              <span class="whitespace-nowrap">{{ t('payroll.posting_map.clear_choices') }}</span>
            </button>
          </div>
        </div>

        <div class="mt-4 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="border-b border-neutral-200 text-left text-xs text-neutral-500">
              <tr>
                <th class="px-2 py-2 font-medium">{{ t('payroll.posting_map.column.meaning') }}</th>
                <th class="px-2 py-2 font-medium">{{ t('payroll.posting_map.column.derived') }}</th>
                <th class="px-2 py-2 font-medium">{{ t('payroll.posting_map.column.current') }}</th>
                <th class="px-2 py-2 font-medium">{{ t('payroll.posting_map.column.choice') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="entry in visibleEntries" :key="entry.key" class="border-b border-neutral-100 align-top">
                <td class="px-2 py-3">
                  <p class="font-medium text-neutral-900">{{ entryLabel(entry) }}</p>
                  <span class="mt-1 inline-block rounded px-1.5 py-0.5 text-xs" :class="statusClass(entry.status)">
                    {{ statusLabel(entry.status) }}
                  </span>
                </td>
                <td class="px-2 py-3">
                  <p v-if="entry.candidates.length === 0" class="text-neutral-400">
                    {{ t('payroll.posting_map.nothing_derived') }}
                  </p>
                  <ul v-else class="space-y-1">
                    <li v-for="candidate in entry.candidates" :key="candidate.code" class="text-neutral-700">
                      <span class="font-mono font-semibold">{{ candidate.code }}</span>
                      <span v-if="candidate.source_codes.length" class="ml-1 text-xs text-neutral-400">
                        ({{ candidate.source_codes.join(', ') }})
                      </span>
                      <span class="ml-2 text-xs text-neutral-500">
                        {{ t('payroll.posting_map.evidence', { lines: candidate.line_count, amount: money(candidate.amount_minor) }) }}
                      </span>
                      <span v-if="!candidate.in_chart" class="ml-2 rounded bg-warning-50 px-1.5 py-0.5 text-xs text-warning-700">
                        {{ t('payroll.posting_map.not_in_chart') }}
                      </span>
                      <span v-if="!candidate.in_chart && candidate.synthetic_in_chart" class="ml-1 text-xs text-neutral-500">
                        {{ t('payroll.posting_map.synthetic_available', { code: candidate.synthetic_code }) }}
                      </span>
                      <span v-if="candidate.cost_centers.length" class="block text-xs text-neutral-400">
                        {{ t('payroll.posting_map.cost_centers', { list: candidate.cost_centers.join(', ') }) }}
                      </span>
                    </li>
                  </ul>
                </td>
                <td class="px-2 py-3">
                  <span class="font-mono text-neutral-700">{{ entry.current_code }}</span>
                  <span v-if="entry.current_code === entry.default_code" class="block text-xs text-neutral-400">
                    {{ t('payroll.posting_map.is_default') }}
                  </span>
                </td>
                <td class="px-2 py-3">
                  <div class="min-w-56 max-w-72" :data-posting-map-key="entry.key">
                    <SearchableSelect
                      :model-value="choices[entry.key] ?? null"
                      :options="accountOptions(entry.key)"
                      :selected-option="selectedOption(entry.key)"
                      :placeholder="t('payroll.posting_map.keep_current')"
                      :no-results-label="t('payroll.employer.account_no_results')"
                      clearable
                      :disabled="!canWrite"
                      :aria-label="entryLabel(entry)"
                      accent="payroll"
                      input-class="font-mono uppercase"
                      teleport
                      @update:model-value="setChoice(entry.key, $event)"
                    />
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <section v-if="unmapped.length" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
        <h3 class="font-semibold text-neutral-900">{{ t('payroll.posting_map.unmapped_title') }}</h3>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.posting_map.unmapped_hint') }}</p>
        <div class="mt-4 overflow-x-auto">
          <table class="min-w-full text-sm">
            <thead class="border-b border-neutral-200 text-left text-xs text-neutral-500">
              <tr>
                <th class="px-2 py-2 font-medium">{{ t('payroll.posting_map.column.legacy_label') }}</th>
                <th class="px-2 py-2 font-medium">{{ t('payroll.employer.debit') }}</th>
                <th class="px-2 py-2 font-medium">{{ t('payroll.employer.credit') }}</th>
                <th class="px-2 py-2 text-right font-medium">{{ t('payroll.posting_map.column.lines') }}</th>
                <th class="px-2 py-2 text-right font-medium">{{ t('payroll.posting_map.column.amount') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr v-for="row in unmapped" :key="row.reference" class="border-b border-neutral-100">
                <td class="px-2 py-2 text-neutral-700">{{ row.label }}</td>
                <td class="px-2 py-2 font-mono text-neutral-700">{{ row.debit_code ?? '-' }}</td>
                <td class="px-2 py-2 font-mono text-neutral-700">{{ row.credit_code ?? '-' }}</td>
                <td class="px-2 py-2 text-right text-neutral-700">{{ row.line_count }}</td>
                <td class="px-2 py-2 text-right text-neutral-700">{{ money(row.amount_minor) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>

      <div
        class="sticky bottom-[var(--app-footer-height,0px)] z-10 flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 bg-surface/95 px-4 py-3 backdrop-blur"
      >
        <p class="text-sm text-neutral-600">
          {{ t('payroll.posting_map.pending', { count: confirmedCount }) }}
        </p>
        <button
          type="button"
          :class="btnFilled('primary')"
          :disabled="!canWrite || saving || confirmedCount === 0"
          data-test="posting-map-save"
          @click="save"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
          <span class="whitespace-nowrap">{{ t('payroll.posting_map.save') }}</span>
        </button>
      </div>
    </template>
  </div>
</template>
