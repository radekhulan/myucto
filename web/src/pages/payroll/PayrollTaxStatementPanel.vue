<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import {
  payrollApi,
  type PayrollTaxStatementForm,
  type PayrollTaxStatementPreview,
  type PayrollTaxStatementVariant,
} from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { downloadApiFile } from '@/utils/downloadFile'

/**
 * Roční vyúčtování daně ze závislé činnosti (DPZVD6) a daně vybírané srážkou
 * (DPSVD2).
 *
 * Jsou to dvě samostatná podání s vlastní lhůtou — DPZVD6 do dvou měsíců po
 * konci roku (elektronicky do 20. března), DPSVD2 do tří měsíců — proto dvě
 * tlačítka, ne jedno „stáhnout vyúčtování".
 */
const props = defineProps<{ initialYear: number }>()

const { t, locale } = useI18n()
const auth = useAuthStore()
const toast = useToast()

/**
 * Panel se montuje i mimo router (testy, náhledy), kde `useRoute()` vrací
 * `undefined` — čtení `.query` by ho v takovém případě shodilo. Rok z adresy
 * je pohodlí navíc, ne podmínka funkčnosti, takže se bez routeru mlčky
 * přeskočí.
 */
const route = useRoute() as ReturnType<typeof useRoute> | undefined

/**
 * Rok z prokliku hlídače termínů. Přehled lhůt sem posílá přesně ten rok,
 * jehož vyúčtování je nepodané; bez toho by panel nabídl svůj výchozí a účetní
 * by stáhla XML za jiné období, než na které klikla. Neplatná hodnota se
 * ignoruje — dotaz v adrese může přepsat kdokoli.
 */
function yearFromRoute(): number | null {
  const raw = route?.query.taxStatementYear
  const value = Number(Array.isArray(raw) ? raw[0] : raw)
  return Number.isInteger(value) && value >= 2010 && value <= 2199 ? value : null
}

// Vyúčtování se podává po skončení roku, takže výchozí je rok minulý.
const year = ref(yearFromRoute() ?? Math.max(2010, props.initialYear - 1))
const variant = ref<PayrollTaxStatementVariant>('B')
const discoveredOn = ref('')
const data = ref<PayrollTaxStatementPreview | null>(null)
const loading = ref(false)
const downloading = ref<PayrollTaxStatementForm | null>(null)
const loadError = ref<string | null>(null)

const canRead = computed(() => auth.canRead('payroll.reports'))
const canExport = computed(() => auth.canRead('reports.export'))
const isAdditional = computed(() => variant.value === 'D' || variant.value === 'E')

const dpz = computed(() => data.value?.statements.dpzvd6 ?? null)
const dps = computed(() => data.value?.statements.dpsvd2 ?? null)

const warnings = computed(() => {
  const all = [...(dpz.value?.warnings ?? []), ...(dps.value?.warnings ?? [])]
  return [...new Set(all)]
})

const formatter = computed(() => new Intl.NumberFormat(locale.value, {
  style: 'currency', currency: 'CZK', minimumFractionDigits: 0, maximumFractionDigits: 0,
}))
const minorFormatter = computed(() => new Intl.NumberFormat(locale.value, {
  style: 'currency', currency: 'CZK', minimumFractionDigits: 2,
}))

function czk(amount: number): string {
  return formatter.value.format(amount)
}

function minor(amount: number): string {
  return minorFormatter.value.format(amount / 100)
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'dpzvd6',
    label: t('payroll.tax_statement.download_dpzvd6'),
    icon: 'download',
    tier: 'primary',
    variant: 'primary',
    show: canExport.value,
    disabled: dpz.value === null || downloading.value !== null,
    disabledReason: dpz.value === null
      ? t('payroll.tax_statement.download_blocked')
      : undefined,
    loading: downloading.value === 'dpzvd6',
    run: () => void download('dpzvd6'),
  },
  {
    key: 'dpsvd2',
    label: t('payroll.tax_statement.download_dpsvd2'),
    icon: 'download',
    tier: 'secondary',
    variant: 'neutral',
    show: canExport.value,
    disabled: dps.value === null || downloading.value !== null,
    disabledReason: dps.value === null
      ? t('payroll.tax_statement.download_blocked')
      : undefined,
    loading: downloading.value === 'dpsvd2',
    run: () => void download('dpsvd2'),
  },
])

async function load(): Promise<void> {
  if (!canRead.value) return
  loading.value = true
  loadError.value = null
  try {
    data.value = await payrollApi.taxStatementPreview(year.value, variant.value)
  } catch (error) {
    data.value = null
    const message = (error as { response?: { data?: { error?: { message?: string } } } })
      .response?.data?.error?.message
    loadError.value = message ?? t('payroll.tax_statement.load_failed')
  } finally {
    loading.value = false
  }
}

async function download(form: PayrollTaxStatementForm): Promise<void> {
  downloading.value = form
  try {
    await downloadApiFile(
      payrollApi.taxStatementXmlUrl(
        year.value,
        form,
        variant.value,
        isAdditional.value && discoveredOn.value ? discoveredOn.value : undefined,
      ),
      `${form}-${year.value}.xml`,
    )
    toast.success(t('payroll.tax_statement.download_done'))
  } catch {
    toast.error(t('payroll.tax_statement.download_failed'))
  } finally {
    downloading.value = null
  }
}

watch([year, variant], () => void load())

/*
 * Proklik z hlídače termínů míří na TUTÉŽ stránku (oba panely jsou na mzdovém
 * rozcestníku), takže se panel nepřemontuje a rok z adresy by se jinak
 * projevil až po ručním obnovení stránky. Ručně přepsaný rok se nepřepisuje
 * zpátky — reaguje se jen na změnu dotazu.
 */
watch(() => route?.query.taxStatementYear, () => {
  const requested = yearFromRoute()
  if (requested !== null) {
    year.value = requested
  }
})

onMounted(load)
</script>

<template>
  <!--
    `id` je cíl kotvy z přehledu mzdových termínů. Panel nemá vlastní routu,
    takže bez ní by proklik z hlídače skončil na začátku dlouhého rozcestníku.
  -->
  <section
    v-if="canRead"
    id="payroll-tax-statement"
    class="scroll-mt-4 rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6"
    data-test="payroll-tax-statement"
  >
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-lg font-semibold text-neutral-900">
          {{ t('payroll.tax_statement.title') }}
        </h2>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">
          {{ t('payroll.tax_statement.description') }}
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-3">
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('payroll.tax_statement.year') }}</span>
          <input
            v-model.number="year"
            type="number"
            min="2010"
            max="2199"
            class="h-9 w-28 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
            data-test="tax-statement-year"
          >
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('payroll.tax_statement.variant') }}</span>
          <select
            v-model="variant"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
            data-test="tax-statement-variant"
          >
            <option value="B">{{ t('payroll.tax_statement.variant_b') }}</option>
            <option value="O">{{ t('payroll.tax_statement.variant_o') }}</option>
            <option value="D">{{ t('payroll.tax_statement.variant_d') }}</option>
            <option value="E">{{ t('payroll.tax_statement.variant_e') }}</option>
          </select>
        </label>
        <label v-if="isAdditional" class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">
            {{ t('payroll.tax_statement.discovered_on') }}
          </span>
          <input
            v-model="discoveredOn"
            type="date"
            class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
            data-test="tax-statement-discovered-on"
          >
        </label>
      </div>
    </div>

    <div class="mt-4">
      <ActionBar :actions="actions" />
    </div>

    <p class="mt-3 text-xs text-neutral-500">{{ t('payroll.tax_statement.deadlines') }}</p>

    <div v-if="loading" class="mt-4 h-24 animate-pulse rounded-lg bg-neutral-100" />
    <p
      v-else-if="loadError"
      class="mt-4 rounded-lg bg-warning-50 p-3 text-sm text-warning-800"
      data-test="tax-statement-error"
    >
      {{ loadError }}
    </p>
    <template v-else-if="dpz && dps">
      <ul
        v-if="warnings.length"
        class="mt-4 space-y-1 rounded-lg bg-warning-50 p-3 text-sm text-warning-800"
        data-test="tax-statement-warnings"
      >
        <li v-for="warning in warnings" :key="warning">{{ warning }}</li>
      </ul>

      <div class="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <div class="rounded-lg bg-payroll-50 p-3">
          <p class="text-xs text-payroll-800">{{ t('payroll.tax_statement.advance_due') }}</p>
          <p class="mt-1 text-lg font-semibold text-payroll-950">
            {{ czk(dpz.total.advance_due) }}
          </p>
        </div>
        <div class="rounded-lg bg-neutral-50 p-3">
          <p class="text-xs text-neutral-600">{{ t('payroll.tax_statement.remitted') }}</p>
          <p class="mt-1 text-lg font-semibold text-neutral-900">{{ czk(dpz.total.remitted) }}</p>
        </div>
        <div class="rounded-lg bg-neutral-50 p-3">
          <p class="text-xs text-neutral-600">{{ t('payroll.tax_statement.withholding_total') }}</p>
          <p class="mt-1 text-lg font-semibold text-neutral-900">
            {{ minor(dps.total.tax_due_minor) }}
          </p>
        </div>
      </div>

      <h3 class="mt-6 text-sm font-semibold text-neutral-900">
        {{ t('payroll.tax_statement.part_one') }}
      </h3>
      <div class="mt-2 overflow-x-auto" data-test="tax-statement-months">
        <table class="min-w-full text-sm">
          <thead class="border-b border-neutral-200 text-left text-xs text-neutral-500">
            <tr>
              <th class="px-2 py-2 font-medium">{{ t('payroll.tax_statement.month') }}</th>
              <th class="px-2 py-2 text-right font-medium">{{ t('payroll.tax_statement.headcount') }}</th>
              <th class="px-2 py-2 text-right font-medium">{{ t('payroll.tax_statement.col_1') }}</th>
              <th class="px-2 py-2 text-right font-medium">{{ t('payroll.tax_statement.col_4') }}</th>
              <th class="px-2 py-2 text-right font-medium">{{ t('payroll.tax_statement.col_5') }}</th>
              <th class="px-2 py-2 text-right font-medium">{{ t('payroll.tax_statement.col_9') }}</th>
              <th class="px-2 py-2 text-right font-medium">{{ t('payroll.tax_statement.col_11') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="row in dpz.months" :key="row.month" class="border-b border-neutral-100">
              <td class="px-2 py-2 text-neutral-700">{{ row.month }}</td>
              <td class="px-2 py-2 text-right text-neutral-700">{{ row.headcount }}</td>
              <td class="px-2 py-2 text-right text-neutral-700">{{ czk(row.advance_due) }}</td>
              <td class="px-2 py-2 text-right text-neutral-700">{{ czk(row.annual_overpayment) }}</td>
              <td class="px-2 py-2 text-right text-neutral-700">{{ czk(row.bonus_paid) }}</td>
              <td class="px-2 py-2 text-right text-neutral-700">{{ czk(row.settled_amount) }}</td>
              <td class="px-2 py-2 text-right text-neutral-700">{{ czk(row.remitted) }}</td>
            </tr>
          </tbody>
          <tfoot>
            <tr class="font-semibold text-neutral-900">
              <td class="px-2 py-2">{{ t('payroll.tax_statement.total') }}</td>
              <td class="px-2 py-2" />
              <td class="px-2 py-2 text-right">{{ czk(dpz.total.advance_due) }}</td>
              <td class="px-2 py-2 text-right">{{ czk(dpz.total.annual_overpayment) }}</td>
              <td class="px-2 py-2 text-right">{{ czk(dpz.total.bonus_paid) }}</td>
              <td class="px-2 py-2 text-right">{{ czk(dpz.total.settled_amount) }}</td>
              <td class="px-2 py-2 text-right">{{ czk(dpz.total.remitted) }}</td>
            </tr>
          </tfoot>
        </table>
      </div>

      <h3 class="mt-6 text-sm font-semibold text-neutral-900">
        {{ t('payroll.tax_statement.annex_one') }}
      </h3>
      <p v-if="!dpz.workplaces.length" class="mt-2 text-sm text-neutral-500">
        {{ t('payroll.tax_statement.annex_one_empty') }}
      </p>
      <ul v-else class="mt-2 space-y-1 text-sm text-neutral-700" data-test="tax-statement-workplaces">
        <li v-for="place in dpz.workplaces" :key="place.municipality_code ?? ''">
          {{ place.municipality_name ?? place.municipality_code }}
          <span v-if="place.district_name" class="text-neutral-500">
            ({{ place.district_name }})
          </span>
          — {{ place.headcount }}
        </li>
      </ul>

      <h3 class="mt-6 text-sm font-semibold text-neutral-900">
        {{ t('payroll.tax_statement.withholding') }}
      </h3>
      <p class="mt-2 text-sm text-neutral-700" data-test="tax-statement-withholding-balance">
        {{ t('payroll.tax_statement.withholding_balance') }}: {{ minor(dps.balance_minor) }}
      </p>

      <p class="mt-4 text-xs text-neutral-500">{{ t('payroll.tax_statement.privacy_hint') }}</p>
    </template>
  </section>
</template>
