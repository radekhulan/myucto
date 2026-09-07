<script setup lang="ts">
/**
 * Přechodový můstek § 7b ↔ § 24 ZDP (přílohy č. 2 a 3 zákona o daních z příjmů) —
 * podklad pro úpravu základu daně při změně účetního režimu (daňová evidence ↔
 * účetnictví): neuhrazené pohledávky a závazky k datu přechodu, zálohy, orientační
 * hodnota skladu. READ-ONLY — vlastní úpravu základu daně zanáší účetní do přiznání
 * ručně, systém nic nezaúčtovává.
 */
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { taxEvidenceApi, type TransitionReport, type TransitionDirection } from '@/api/taxEvidence'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'

const { t, locale } = useI18n()
const auth = useAuthStore()

const asOf = ref(new Date().getFullYear() + '-01-01')
const direction = ref<TransitionDirection>('tax_to_accounting')
const report = ref<TransitionReport | null>(null)
const loading = ref(false)
const error = ref('')
const notApplicable = ref(false)

async function load() {
  loading.value = true
  error.value = ''
  notApplicable.value = false
  try {
    report.value = await taxEvidenceApi.transitionReport(asOf.value, direction.value)
  } catch (e) {
    const code = (e as any)?.response?.data?.error?.code
    if (code === 'transition_not_applicable') {
      notApplicable.value = true
      error.value = ''
    } else {
      error.value = apiErrorMessage(e)
    }
    report.value = null
  } finally {
    loading.value = false
  }
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'reload', label: t('common.refresh'), icon: 'chart',
    tier: 'primary', variant: 'primary',
    show: auth.canRead('tax_evidence'), disabled: loading.value,
    loading: loading.value, run: load,
  },
])

function fmtMoney(v: number | null | undefined): string {
  if (v === null || v === undefined) return '—'
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 2, maximumFractionDigits: 2,
  }).format(Number(v) || 0)
}

function fmtDate(iso: string | null): string {
  if (!iso) return '—'
  const d = new Date(iso)
  return isNaN(d.getTime()) ? '—' : d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

watch([asOf, direction], load)
onMounted(load)
</script>

<template>
  <div class="max-w-4xl">
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.transition.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.transition.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <select v-model="direction" class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm">
          <option value="tax_to_accounting">{{ t('accounting.transition.direction.tax_to_accounting') }}</option>
          <option value="accounting_to_tax">{{ t('accounting.transition.direction.accounting_to_tax') }}</option>
        </select>
        <label class="flex items-center gap-2 text-sm text-neutral-600">
          {{ t('accounting.transition.as_of') }}
          <DateInput v-model="asOf" class="h-9 px-2 border border-neutral-300 rounded-md bg-surface text-sm" />
        </label>
      </div>
    </div>

    <ActionBar :actions="actions" class="mb-4" />

    <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 mb-4 text-sm text-neutral-700">
      <p class="font-medium text-primary-800 mb-1">{{ t('accounting.transition.explainer_title') }}</p>
      <p>{{ t('accounting.transition.explainer_body') }}</p>
    </div>

    <div v-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-4">
      {{ error }}
    </div>
    <div v-if="notApplicable" class="bg-warning-50 border border-warning-500/30 text-warning-700 rounded-md p-3 text-sm mb-4">
      {{ t('accounting.transition.not_applicable') }}
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>

    <div v-else-if="report" class="space-y-4">
      <!-- Souhrn -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <h2 class="text-sm font-semibold mb-3">{{ t('accounting.transition.totals_title') }}</h2>
        <table class="text-sm">
          <tbody>
            <tr>
              <td class="pr-8 py-1 text-neutral-500">{{ t('accounting.transition.receivables_total') }}</td>
              <td class="py-1 text-right font-mono">{{ fmtMoney(report.totals.receivables_czk) }} Kč</td>
            </tr>
            <tr>
              <td class="pr-8 py-1 text-neutral-500">{{ t('accounting.transition.payables_total') }}</td>
              <td class="py-1 text-right font-mono">{{ fmtMoney(report.totals.payables_czk) }} Kč</td>
            </tr>
            <tr v-if="report.totals.advances_paid_czk !== undefined">
              <td class="pr-8 py-1 text-neutral-500">{{ t('accounting.transition.advances_paid') }}</td>
              <td class="py-1 text-right font-mono">{{ fmtMoney(report.totals.advances_paid_czk) }} Kč</td>
            </tr>
            <tr v-if="report.totals.advances_received_czk !== undefined">
              <td class="pr-8 py-1 text-neutral-500">{{ t('accounting.transition.advances_received') }}</td>
              <td class="py-1 text-right font-mono">{{ fmtMoney(report.totals.advances_received_czk) }} Kč</td>
            </tr>
            <tr v-if="report.inventory.enabled">
              <td class="pr-8 py-1 text-neutral-500">{{ t('accounting.transition.inventory') }}</td>
              <td class="py-1 text-right font-mono">{{ fmtMoney(report.inventory.value_czk) }} Kč</td>
            </tr>
            <tr class="border-t border-neutral-200">
              <td class="pr-8 py-2 font-medium">{{ t('accounting.transition.net_adjustment') }}</td>
              <td class="py-2 text-right font-mono font-bold text-lg">{{ fmtMoney(report.totals.net_adjustment_czk) }} Kč</td>
            </tr>
          </tbody>
        </table>
        <p v-if="report.receivables_spread" class="text-xs text-neutral-500 mt-2">
          {{ report.receivables_spread.note }}
          ({{ t('accounting.transition.spread_annual', { years: report.receivables_spread.max_years, amount: fmtMoney(report.receivables_spread.annual_czk) }) }})
        </p>
        <p v-if="!report.inventory.enabled" class="text-xs text-neutral-400 mt-1">{{ report.inventory.note }}</p>
        <p v-if="report.valuables" class="text-xs text-neutral-400 mt-1">{{ report.valuables.note }}</p>
      </div>

      <!-- Pohledávky -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-4 py-2 bg-neutral-50 border-b border-neutral-200 flex items-center justify-between">
          <h2 class="text-sm font-semibold">{{ t('accounting.transition.receivables_title') }}</h2>
          <span class="text-sm font-mono font-semibold">{{ fmtMoney(report.totals.receivables_czk) }} Kč</span>
        </div>
        <EmptyState v-if="report.receivables.length === 0" dense accent="neutral" icon="doc" :title="t('accounting.transition.no_rows')" />
        <div v-else class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-2 py-2 text-left font-medium">{{ t('accounting.transition.col.doc') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('accounting.transition.col.partner') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('accounting.transition.col.issue') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('accounting.transition.col.due') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('accounting.transition.col.amount') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('accounting.transition.col.amount_czk') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in report.receivables" :key="r.id">
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ r.doc_no }}</td>
                <td class="px-2 py-1.5">{{ r.partner }}</td>
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ fmtDate(r.issue_date) }}</td>
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ fmtDate(r.due_date) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(r.amount) }} {{ r.currency }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(r.amount_czk) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Závazky -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="px-4 py-2 bg-neutral-50 border-b border-neutral-200 flex items-center justify-between">
          <h2 class="text-sm font-semibold">{{ t('accounting.transition.payables_title') }}</h2>
          <span class="text-sm font-mono font-semibold">{{ fmtMoney(report.totals.payables_czk) }} Kč</span>
        </div>
        <EmptyState v-if="report.payables.length === 0" dense accent="neutral" icon="doc" :title="t('accounting.transition.no_rows')" />
        <div v-else class="overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th class="px-2 py-2 text-left font-medium">{{ t('accounting.transition.col.doc') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('accounting.transition.col.partner') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('accounting.transition.col.issue') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('accounting.transition.col.due') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('accounting.transition.col.amount') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('accounting.transition.col.amount_czk') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="r in report.payables" :key="r.id">
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ r.doc_no }}</td>
                <td class="px-2 py-1.5">{{ r.partner }}</td>
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ fmtDate(r.issue_date) }}</td>
                <td class="px-2 py-1.5 font-mono whitespace-nowrap">{{ fmtDate(r.due_date) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(r.amount) }} {{ r.currency }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(r.amount_czk) }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>
</template>
