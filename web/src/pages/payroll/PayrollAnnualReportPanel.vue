<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollApi, type PayrollAnnualReport } from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'

const props = defineProps<{ initialYear: number }>()
const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const year = ref(props.initialYear)
const data = ref<PayrollAnnualReport | null>(null)
const loading = ref(false)
const canRead = computed(() => auth.canRead('payroll.reports'))

const formatter = computed(() => new Intl.NumberFormat(locale.value, {
  style: 'currency', currency: 'CZK', minimumFractionDigits: 2,
}))

function money(amount: number | null): string {
  return amount === null ? '—' : formatter.value.format(amount / 100)
}

async function load(): Promise<void> {
  if (!canRead.value) return
  loading.value = true
  try {
    data.value = await payrollApi.annualReport(year.value)
  } catch {
    toast.error(t('payroll.annual_report.load_failed'))
  } finally {
    loading.value = false
  }
}

watch(year, () => void load())
onMounted(load)
</script>

<template>
  <section v-if="canRead" class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6" data-test="payroll-annual-report">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.annual_report.title') }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.annual_report.description') }}</p>
      </div>
      <label class="text-sm text-neutral-600">
        <span class="mb-1 block text-xs font-medium">{{ t('payroll.annual_report.year') }}</span>
        <input v-model.number="year" type="number" min="2000" max="2200" class="h-9 w-28 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900">
      </label>
    </div>

    <div v-if="loading" class="mt-4 h-24 animate-pulse rounded-lg bg-neutral-100" />
    <template v-else-if="data">
      <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-lg bg-payroll-50 p-3">
          <p class="text-xs text-payroll-800">{{ t('payroll.annual_report.gross') }}</p>
          <p class="mt-1 text-lg font-semibold text-payroll-950">{{ money(data.totals.gross_minor) }}</p>
        </div>
        <div class="rounded-lg bg-neutral-50 p-3">
          <p class="text-xs text-neutral-600">{{ t('payroll.annual_report.employer_cost') }}</p>
          <p class="mt-1 text-lg font-semibold text-neutral-900">{{ money(data.totals.employer_cost_minor) }}</p>
        </div>
        <div class="rounded-lg bg-neutral-50 p-3">
          <p class="text-xs text-neutral-600">{{ t('payroll.annual_report.person_months') }}</p>
          <p class="mt-1 text-lg font-semibold text-neutral-900">{{ data.totals.headcount_person_months }}</p>
        </div>
      </div>

      <p v-if="data.months.length === 0" class="mt-4 text-sm text-neutral-500">{{ t('payroll.annual_report.empty') }}</p>
      <div v-else class="mt-4">
        <div class="grid gap-3 md:hidden" data-test="annual-report-mobile-months">
          <article
            v-for="month in data.months"
            :key="`mobile-${month.period}`"
            class="rounded-lg border border-neutral-200 bg-surface p-3"
          >
            <h3 class="font-semibold text-neutral-900">{{ month.period }}</h3>
            <dl class="mt-3 grid grid-cols-2 gap-x-3 gap-y-2 text-sm">
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.annual_report.headcount') }}</dt>
                <dd class="mt-0.5 font-medium text-neutral-900">{{ month.headcount }}</dd>
              </div>
              <div>
                <dt class="text-xs text-neutral-500">{{ t('payroll.annual_report.gross') }}</dt>
                <dd class="mt-0.5 font-medium text-neutral-900">{{ money(month.gross_minor) }}</dd>
              </div>
              <div class="col-span-2">
                <dt class="text-xs text-neutral-500">{{ t('payroll.annual_report.employer_cost') }}</dt>
                <dd class="mt-0.5 font-medium text-neutral-900">{{ money(month.employer_cost_minor) }}</dd>
              </div>
            </dl>
          </article>
        </div>
        <div class="hidden overflow-x-auto md:block" data-test="annual-report-desktop-table">
          <table class="min-w-full text-sm">
          <thead class="border-b border-neutral-200 text-left text-xs text-neutral-500">
            <tr>
              <th class="px-2 py-2 font-medium">{{ t('payroll.annual_report.period') }}</th>
              <th class="px-2 py-2 text-right font-medium">{{ t('payroll.annual_report.headcount') }}</th>
              <th class="px-2 py-2 text-right font-medium">{{ t('payroll.annual_report.gross') }}</th>
              <th class="px-2 py-2 text-right font-medium">{{ t('payroll.annual_report.employer_cost') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="month in data.months" :key="month.period" class="border-b border-neutral-100">
              <td class="px-2 py-2 text-neutral-700">{{ month.period }}</td>
              <td class="px-2 py-2 text-right text-neutral-700">{{ month.headcount }}</td>
              <td class="px-2 py-2 text-right text-neutral-700">{{ money(month.gross_minor) }}</td>
              <td class="px-2 py-2 text-right text-neutral-700">{{ money(month.employer_cost_minor) }}</td>
            </tr>
          </tbody>
          </table>
        </div>
      </div>
      <p class="mt-3 text-xs text-neutral-500">{{ t('payroll.annual_report.privacy_hint') }}</p>
    </template>
  </section>
</template>
