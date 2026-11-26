<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter, RouterLink } from 'vue-router'
import { accountingApi, type AccountingPeriod } from '@/api/accounting'
import { taxBaseApi, type TaxBaseAdjustments } from '@/api/closing'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { ICONS, btnFilled } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()

const periods = ref<AccountingPeriod[]>([])
const fiscalYear = ref<number | ''>('')
const loading = ref(false)
const data = ref<TaxBaseAdjustments | null>(null)

const availableYears = computed(() => periods.value.map(p => p.fiscal_year).sort((a, b) => b - a))

async function load() {
  if (!fiscalYear.value) return
  loading.value = true
  try {
    data.value = await taxBaseApi.get(Number(fiscalYear.value))
    router.replace({ query: { ...route.query, fiscal_year: String(fiscalYear.value) } }).catch(() => {})
  } catch (e: any) {
    data.value = null
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

onMounted(async () => {
  try {
    periods.value = await accountingApi.listPeriods()
  } catch {
    periods.value = []
  }
  const queryYear = Number(route.query.fiscal_year)
  if (queryYear > 0) {
    fiscalYear.value = queryYear
  } else if (availableYears.value.length) {
    fiscalYear.value = availableYears.value[0]
  }
  if (fiscalYear.value) load()
})

function deductibilityLabel(d: string): string {
  return t(`accounting.tax_base_adjustments.deductibility_${d}`)
}
function deductibilityClass(d: string): string {
  if (d === 'full') return 'text-success-600'
  if (d === 'none') return 'text-danger-600'
  return 'text-warning-600'
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.tax_base_adjustments.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.tax_base_adjustments.subtitle') }}</p>
      </div>
    </div>

    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="flex items-end gap-3 flex-wrap">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.tax_base_adjustments.fiscal_year') }}</label>
          <select v-model.number="fiscalYear" @change="load" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="y in availableYears" :key="y" :value="y">{{ y }}</option>
          </select>
        </div>
        <button :class="btnFilled('primary')" :disabled="loading || !fiscalYear" @click="load">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
          {{ t('accounting.tax_base_adjustments.load') }}
        </button>
      </div>
    </div>

    <p v-if="!loading && !availableYears.length" class="text-sm text-neutral-500">{{ t('accounting.tax_base_adjustments.no_period') }}</p>

    <template v-if="data">
      <p class="text-xs text-neutral-500 mb-3">{{ data.note }}</p>

      <!-- (a) rozdíl daňových a účetních odpisů -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4">
        <h2 class="text-sm font-semibold mb-2">{{ t('accounting.tax_base_adjustments.depreciation_title') }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 text-sm mb-2">
          <div>
            <div class="text-xs text-neutral-500">{{ t('accounting.tax_base_adjustments.depreciation_tax') }}</div>
            <div class="font-mono">{{ formatMoney(data.depreciation.tax_total) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500">{{ t('accounting.tax_base_adjustments.depreciation_accounting') }}</div>
            <div class="font-mono">{{ formatMoney(data.depreciation.accounting_total) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500">{{ t('accounting.tax_base_adjustments.depreciation_difference') }}</div>
            <div class="font-mono" :class="data.depreciation.difference >= 0 ? 'text-success-600' : 'text-warning-600'">
              {{ formatMoney(data.depreciation.difference) }}
            </div>
          </div>
        </div>
        <p class="text-xs text-neutral-500">{{ data.depreciation.note }}</p>
        <ul v-if="data.depreciation.warnings?.length" class="mt-2 space-y-1 text-xs text-warning-700">
          <li v-for="(w, i) in data.depreciation.warnings" :key="i">{{ w }}</li>
        </ul>
      </div>

      <!-- (b) vyřazený majetek -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <h2 class="text-sm font-semibold px-4 pt-4">{{ t('accounting.tax_base_adjustments.disposals_title') }}</h2>
        <div v-if="!data.disposals.length" class="px-4 py-4 text-sm text-neutral-500">{{ t('accounting.tax_base_adjustments.no_disposals') }}</div>
        <div v-else class="overflow-x-auto">
          <table class="w-full text-sm mt-2">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.tax_base_adjustments.col_asset') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.tax_base_adjustments.col_disposal_date') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.tax_base_adjustments.col_disposal_type') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.tax_base_adjustments.col_tax_residual') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.tax_base_adjustments.col_accounting_residual') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.tax_base_adjustments.col_deductibility') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in data.disposals" :key="row.asset_id">
                <td class="px-3 py-2">
                  <RouterLink :to="{ name: 'accounting-asset-detail', params: { id: row.asset_id } }" class="text-primary-600 hover:underline">
                    {{ row.inventory_number }} - {{ row.name }}
                  </RouterLink>
                </td>
                <td class="px-3 py-2">{{ formatDate(row.disposal_date) }}</td>
                <td class="px-3 py-2">{{ row.disposal_type }}</td>
                <td class="px-3 py-2 text-right font-mono">
                  <span v-if="row.tax_residual_value !== null">{{ formatMoney(row.tax_residual_value) }}</span>
                  <span v-else class="text-warning-600">{{ t('taxReturn.residual_unknown') }}</span>
                  <div v-if="row.tax_residual_source" class="text-xs text-neutral-500 font-sans">{{ t(`taxReturn.residual_source.${row.tax_residual_source}`) }}</div>
                </td>
                <td class="px-3 py-2 text-right font-mono">
                  <span v-if="row.accounting_residual_value !== null">{{ formatMoney(row.accounting_residual_value) }}</span>
                  <span v-else class="text-neutral-400">—</span>
                  <div v-if="row.accounting_residual_source" class="text-xs text-neutral-500 font-sans">{{ t(`taxReturn.residual_source.${row.accounting_residual_source}`) }}</div>
                </td>
                <td class="px-3 py-2" :class="deductibilityClass(row.deductibility)" :title="row.note">
                  {{ deductibilityLabel(row.deductibility) }}
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- (b2) prodané podíly a cenné papíry, § 24/2/w -->
      <div v-if="data.securities && data.securities.accounts.length" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4">
        <h2 class="text-sm font-semibold mb-2">{{ t('taxReturn.po_securities_title') }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm mb-2">
          <div>
            <div class="text-xs text-neutral-500">{{ t('taxReturn.po_securities_shares') }}</div>
            <div class="font-mono">{{ formatMoney(data.securities.shares_cost) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500">{{ t('taxReturn.po_securities_other') }}</div>
            <div class="font-mono">{{ formatMoney(data.securities.other_cost) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500">{{ t('taxReturn.po_securities_income') }}</div>
            <div class="font-mono">{{ formatMoney(data.securities.income) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500">{{ t('taxReturn.po_securities_addback') }}</div>
            <div class="font-mono" :class="data.securities.addback > 0 ? 'text-warning-600' : ''">{{ formatMoney(data.securities.addback) }}</div>
          </div>
        </div>
        <p v-if="data.securities.review_amount > 0" class="text-sm text-warning-700 mb-1">
          {{ t('taxReturn.po_securities_review') }}: <span class="font-mono">{{ formatMoney(data.securities.review_amount) }}</span>
        </p>
        <p class="text-xs text-neutral-500">{{ t('taxReturn.po_securities_note') }}</p>
      </div>

      <!-- (c) informativní zůstatky -->
      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
        <h2 class="text-sm font-semibold mb-2">{{ t('accounting.tax_base_adjustments.info_title') }}</h2>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 text-sm">
          <div>
            <div class="text-xs text-neutral-500">388</div>
            <div class="font-mono">{{ formatMoney(data.info.estimates_388_balance) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500">389</div>
            <div class="font-mono">{{ formatMoney(data.info.estimates_389_balance) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500">563</div>
            <div class="font-mono">{{ formatMoney(data.info.fx_revaluation_loss_563) }}</div>
          </div>
          <div>
            <div class="text-xs text-neutral-500">663</div>
            <div class="font-mono">{{ formatMoney(data.info.fx_revaluation_gain_663) }}</div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>
