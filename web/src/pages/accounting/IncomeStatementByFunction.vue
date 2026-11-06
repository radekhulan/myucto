<script setup lang="ts">
/**
 * VZZ v ÚČELOVÉM členění — vyhláška 500/2002 Sb., příloha č. 2 část II (§ 39b).
 *
 * Na rozdíl od druhového členění tenhle výkaz nejde sestavit z dat samotných: náklady se
 * člení podle FUNKCE (náklady prodeje / odbytové / správní režie) a tu číslo účtu nenese.
 * Přiřazení proto zadává účetní a bez ÚPLNÉ mapy se výkaz NESESTAVÍ — nepřiřazený náklad
 * by z výkazu tiše vypadl a nadhodnotil hrubý zisk i výsledek hospodaření.
 *
 * Stránka je proto postavená tak, aby chybějící přiřazení nebyla slepá ulička: backend
 * vrátí seznam účtů s obratem, kterým přiřazení chybí, a ty jdou přiřadit rovnou tady.
 */
import { ref, onMounted, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  accountingApi,
  type AccountingPeriod,
  type IncomeStatementReport,
  type StatementScope,
  type StatementFunctionCode,
  type StatementFunctionMap,
  type UnassignedExpenseAccount,
} from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatMoney } from '@/composables/useFormat'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import { useAuthStore } from '@/stores/auth'
import EmptyState from '@/components/ui/EmptyState.vue'
import AccrualSpreadToggle from '@/components/accounting/AccrualSpreadToggle.vue'
import AccrualSpreadNote from '@/components/accounting/AccrualSpreadNote.vue'
import { useAccrualSpread } from '@/composables/useAccrualSpread'

const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const accrualSpread = useAccrualSpread()
const accrualSpreadOn = accrualSpread.enabled

function onAccrualSpread(value: boolean) {
  accrualSpread.set(value)
  void load()
}

const periods = ref<AccountingPeriod[]>([])
const report = ref<IncomeStatementReport | null>(null)
const functionMap = ref<StatementFunctionMap | null>(null)
const loading = ref(false)
const mapError = ref('')

const filters = reactive({
  period_id: '' as number | '',
  as_of: '',
  scope: 'auto' as StatementScope,
})

const canWrite = computed(() => auth.canWrite('accounting'))
const unassigned = computed<UnassignedExpenseAccount[]>(() => functionMap.value?.unassigned ?? [])
const hasUnassigned = computed(() => unassigned.value.length > 0)

const FUNCTIONS: StatementFunctionCode[] = ['cost_of_sales', 'distribution', 'administration']

async function loadMap() {
  if (!filters.period_id) return
  functionMap.value = await accountingApi.getStatementFunctionMap(Number(filters.period_id))
}

async function load() {
  if (!filters.period_id) return
  loading.value = true
  mapError.value = ''
  try {
    await loadMap()
    report.value = await accountingApi.getIncomeStatementByFunction({
      period_id: Number(filters.period_id),
      as_of: filters.as_of || undefined,
      scope: filters.scope,
      ...accrualSpread.param(),
    })
  } catch (e: any) {
    // `function_map_incomplete` NENÍ technická chyba — je to sdělení, že chybí vstup.
    // Proto se nezobrazuje jako červený pád, ale jako výzva k doplnění mapy.
    const code = e?.response?.data?.error?.code
    if (code === 'function_map_incomplete') {
      mapError.value = e?.response?.data?.error?.message ?? ''
    } else {
      toast.error(e?.response?.data?.error?.message || t('common.error'))
    }
    report.value = null
  } finally {
    loading.value = false
  }
}

async function assign(accountPrefix: string, fn: StatementFunctionCode | '') {
  try {
    await accountingApi.setStatementFunctionMapping(accountPrefix, fn)
    await load()
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}

/**
 * Export výkazu — táž sestava, tytéž formáty jako u druhového členění.
 *
 * Chodí do ní `scope` i `as_of` stejně jako do zobrazení: kdyby export volal endpoint
 * s výchozími parametry, stáhl by se jiný výkaz, než jaký je na obrazovce.
 *
 * Bez sestaveného výkazu se neexportuje. Účelové členění se totiž bez ÚPLNÉ mapy funkcí
 * nesestaví vůbec (nepřiřazený náklad by z výkazu tiše vypadl), takže „stáhnout, i když
 * na obrazovce nic není" by dalo soubor, který nemá co obsahovat.
 */
const exporting = ref(false)
async function exportFile(format: 'pdf' | 'xlsx'): Promise<void> {
  if (!filters.period_id || !report.value) return
  exporting.value = true
  try {
    const r = await accountingApi.exportReport('/accounting/reports/income-statement-by-function/export', {
      period_id: Number(filters.period_id),
      as_of: filters.as_of || undefined,
      scope: filters.scope,
      ...accrualSpread.param(),
      format,
    })
    downloadBlob(r.data as unknown as Blob, `vysledovka-ucelova-${report.value.as_of}.${format}`)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    exporting.value = false
  }
}

function downloadBlob(blob: Blob, filename: string): void {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a); a.click(); a.remove()
  URL.revokeObjectURL(url)
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'load', label: t('accounting.statements.byFunction.action_load'), icon: 'chart',
    tier: 'primary', variant: 'primary',
    show: true, disabled: loading.value || !filters.period_id,
    loading: loading.value, run: load,
  },
  {
    key: 'export-pdf', label: t('accounting.income_statement.export_pdf'), icon: 'download',
    tier: 'secondary', variant: 'primary',
    show: true, disabled: !report.value || loading.value || exporting.value,
    run: () => { void exportFile('pdf') },
  },
  {
    key: 'export-xlsx', label: t('accounting.income_statement.export_xlsx'), icon: 'download',
    tier: 'secondary', variant: 'primary',
    show: true, disabled: !report.value || loading.value || exporting.value,
    run: () => { void exportFile('xlsx') },
  },
])

onMounted(async () => {
  await accrualSpread.restore()
  try {
    periods.value = await accountingApi.listPeriods()
    if (periods.value.length > 0) {
      filters.period_id = periods.value[0].id
      await load()
    }
  } catch {
    /* seznam období není kritický — stránka jde použít po ručním výběru */
  }
})
</script>

<template>
  <div class="max-w-full">
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.statements.byFunction.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.statements.byFunction.subtitle') }}</p>
      </div>
      <div class="flex items-center gap-2 flex-wrap">
        <select v-model.number="filters.period_id" class="h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm">
          <option v-for="p in periods" :key="p.id" :value="p.id">{{ p.fiscal_year }}</option>
        </select>
        <AccrualSpreadToggle :model-value="accrualSpreadOn" @update:model-value="onAccrualSpread" />
      </div>
    </div>

    <ActionBar :actions="actions" class="mb-4" />

    <div class="bg-primary-50 border border-primary-200 rounded-lg p-4 mb-4 text-sm text-neutral-700">
      <p class="font-medium text-primary-800 mb-1">{{ t('accounting.statements.byFunction.explainer_title') }}</p>
      <p>{{ t('accounting.statements.byFunction.explainer_body') }}</p>
    </div>

    <!-- Chybějící přiřazení: ne pád, ale výzva. -->
    <div v-if="mapError" class="bg-warning-50 border border-warning-300 rounded-lg p-4 mb-4 text-sm text-warning-800">
      <p class="font-medium mb-1">{{ t('accounting.statements.byFunction.incomplete_title') }}</p>
      <p>{{ mapError }}</p>
    </div>

    <!-- Mapa funkcí -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
      <div class="px-4 py-2 bg-neutral-50 border-b border-neutral-200">
        <h2 class="text-sm font-semibold">{{ t('accounting.statements.byFunction.map_title') }}</h2>
        <p class="text-xs text-neutral-500 mt-0.5">{{ t('accounting.statements.byFunction.map_hint') }}</p>
      </div>

      <div v-if="hasUnassigned" class="border-b border-warning-200 bg-warning-50/50">
        <p class="px-4 py-2 text-xs font-medium text-warning-800">
          {{ t('accounting.statements.byFunction.unassigned_title') }}
        </p>
        <div class="overflow-x-auto">
          <table class="w-full text-xs">
            <tbody class="divide-y divide-warning-100">
              <tr v-for="a in unassigned" :key="a.account_code">
                <td class="px-4 py-1.5 font-mono whitespace-nowrap">{{ a.account_code }}</td>
                <td class="px-2 py-1.5">{{ a.name }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ formatMoney(a.turnover) }}</td>
                <td class="px-4 py-1.5 text-right">
                  <select v-if="canWrite" class="h-8 px-2 border border-neutral-300 rounded-md text-xs bg-surface"
                          @change="assign(a.account_code, ($event.target as HTMLSelectElement).value as StatementFunctionCode)">
                    <option value="">{{ t('accounting.statements.byFunction.choose_function') }}</option>
                    <option v-for="fn in FUNCTIONS" :key="fn" :value="fn">
                      {{ t(`accounting.statements.byFunction.functions.${fn}`) }}
                    </option>
                  </select>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <EmptyState v-if="(functionMap?.rows.length ?? 0) === 0" dense accent="neutral" icon="tag" :title="t('accounting.statements.byFunction.map_empty')" />
      <div v-else class="overflow-x-auto">
        <table class="w-full text-xs">
          <thead class="bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-4 py-2 text-left font-medium">{{ t('accounting.statements.byFunction.col_account') }}</th>
              <th class="px-2 py-2 text-left font-medium">{{ t('accounting.statements.byFunction.col_function') }}</th>
              <th class="px-4 py-2 w-8"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="m in functionMap?.rows ?? []" :key="m.account_prefix">
              <td class="px-4 py-1.5 font-mono whitespace-nowrap">{{ m.account_prefix }}</td>
              <td class="px-2 py-1.5">{{ t(`accounting.statements.byFunction.functions.${m.function_code}`) }}</td>
              <td class="px-4 py-1.5 text-right">
                <button v-if="canWrite" type="button" class="text-danger-500 hover:underline"
                        :title="t('accounting.statements.byFunction.remove_hint')"
                        @click="assign(m.account_prefix, '')">×</button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <!-- Výkaz -->
    <AccrualSpreadNote v-if="!loading && report?.accrual_spread" :spread="report.accrual_spread" :format="formatMoney" />
    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-neutral-400">
      {{ t('common.loading') }}…
    </div>
    <div v-else-if="report" class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-neutral-500">
            <tr>
              <th class="px-3 py-2 text-left font-medium w-16">{{ t('accounting.statements.byFunction.col_code') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('accounting.statements.byFunction.col_label') }}</th>
              <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('accounting.statements.byFunction.col_current') }}</th>
              <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('accounting.statements.byFunction.col_prev') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="row in report.rows" :key="row.row_code"
                :class="row.row_type === 'computed' ? 'bg-neutral-50 font-semibold' : ''">
              <td class="px-3 py-1.5 font-mono text-neutral-500">{{ row.display_code }}</td>
              <td class="px-3 py-1.5" :style="{ paddingLeft: `${0.75 + (row.level - 1) * 1}rem` }">{{ row.label }}</td>
              <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap">{{ formatMoney(row.amount) }}</td>
              <td class="px-3 py-1.5 text-right font-mono whitespace-nowrap text-neutral-500">{{ formatMoney(row.prev_amount) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>
