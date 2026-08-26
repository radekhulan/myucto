<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollInstitutionAccount,
  type PayrollRiskySavingsEvidencePayload,
  type PayrollRiskySavingsItem,
  type PayrollRiskySavingsRiskFactor,
} from '@/api/payroll'
import PayrollPersonSearchSelect from '@/components/payroll/PayrollPersonSearchSelect.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { formatMoneyMinor } from '@/composables/useFormat'
import { btnFilled, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

interface EmploymentOption {
  employment_id: number
  employee_id: number
  full_name: string
  code: string
  relation_type: string
}

const props = defineProps<{
  period: string
  employments: EmploymentOption[]
}>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const loading = ref(false)
const saving = ref(false)
const failed = ref(false)
const items = ref<PayrollRiskySavingsItem[]>([])
const institutionAccounts = ref<PayrollInstitutionAccount[]>([])
const minimumEighths = ref(24)
const rateBasisPoints = ref(400)
const employeeId = ref<number | null>(null)
const employmentId = ref<number | null>(null)
const sourceEvidenceId = ref<number | null>(null)
const rowVersion = ref<number | null>(null)
const riskFactor = ref<PayrollRiskySavingsRiskFactor>('vibration')
const fullEightHourShifts = ref(0)
const otherShiftStartedHours = ref(0)
const rightClaimedOn = ref('')
const employeeInformedOn = ref('')
const pensionCompany = ref('')
const institutionAccountId = ref<number | null>(null)
const productReference = ref('')
const variableSymbol = ref('')
const specificSymbol = ref('')
const paymentMessage = ref('')
const evidenceReference = ref('')

const canWrite = computed(() => auth.canWrite('payroll.inputs.write'))
const canApprove = computed(() => auth.canWrite('payroll.approve'))
const people = computed(() => Array.from(new Map(props.employments.map(item => [
  item.employee_id,
  { value: item.employee_id, label: item.full_name },
])).values()))
const employmentOptions = computed(() => props.employments
  .filter(item => item.employee_id === employeeId.value)
  .map(item => ({
    value: item.employment_id,
    label: item.code,
  })))
const paymentTargetOptions = computed(() => institutionAccounts.value
  .filter(account => account.institution_type === 'other_recipient'
    && account.currency_code === 'CZK')
  .map(account => ({
    value: account.id,
    label: `${account.institution_name} · ${account.bank_account_masked}`,
  })))
const riskFactorOptions = computed(() => ([
  'vibration', 'cold', 'heat', 'dynamic_physical_load',
] as PayrollRiskySavingsRiskFactor[]).map(value => ({
  value,
  label: t(`payroll.risky_savings.risk_factor.${value}`),
})))
const qualifyingEighths = computed(() =>
  Math.max(0, Math.trunc(fullEightHourShifts.value)) * 8
  + Math.max(0, Math.trunc(otherShiftStartedHours.value)))
const formValid = computed(() =>
  employmentId.value !== null
  && institutionAccountId.value !== null
  && rightClaimedOn.value !== ''
  && productReference.value.trim() !== '')

function selectEmployee(value: number | null): void {
  employeeId.value = value
  employmentId.value = props.employments.find(item =>
    item.employee_id === value)?.employment_id ?? null
}

function edit(item: PayrollRiskySavingsItem): void {
  const employment = props.employments.find(option =>
    option.employment_id === item.employment_id)
  employeeId.value = employment?.employee_id ?? null
  employmentId.value = item.employment_id
  sourceEvidenceId.value = item.id
  rowVersion.value = item.row_version
  riskFactor.value = item.risk_factor
  fullEightHourShifts.value = Math.floor(item.qualifying_shift_eighths / 8)
  otherShiftStartedHours.value = item.qualifying_shift_eighths % 8
  rightClaimedOn.value = item.right_claimed_on
  employeeInformedOn.value = item.employee_informed_on ?? ''
  pensionCompany.value = item.pension_company
  institutionAccountId.value = item.institution_account_id
  productReference.value = item.product_reference
  variableSymbol.value = item.variable_symbol ?? ''
  specificSymbol.value = item.specific_symbol ?? ''
  paymentMessage.value = item.payment_message ?? ''
  evidenceReference.value = item.evidence_reference ?? ''
}

function payload(approve: boolean): PayrollRiskySavingsEvidencePayload | null {
  if (!formValid.value
    || employmentId.value === null
    || institutionAccountId.value === null) return null
  return {
    employment_id: employmentId.value,
    period: props.period,
    source_evidence_id: sourceEvidenceId.value,
    row_version: rowVersion.value,
    risk_factor: riskFactor.value,
    qualifying_shift_eighths: qualifyingEighths.value,
    right_claimed_on: rightClaimedOn.value,
    employee_informed_on: employeeInformedOn.value || null,
    pension_company: pensionCompany.value.trim(),
    institution_account_id: institutionAccountId.value,
    product_reference: productReference.value.trim(),
    variable_symbol: variableSymbol.value.trim() || null,
    specific_symbol: specificSymbol.value.trim() || null,
    payment_message: paymentMessage.value.trim() || null,
    evidence_reference: evidenceReference.value.trim() || null,
    approve,
  }
}

async function load(): Promise<void> {
  loading.value = true
  failed.value = false
  try {
    const [result, accounts] = await Promise.all([
      payrollApi.riskySavings(props.period),
      payrollApi.institutionAccounts(paymentTargetEffectiveOn()),
    ])
    items.value = result.items
    institutionAccounts.value = accounts
    minimumEighths.value = result.minimum_shift_eighths
    rateBasisPoints.value = result.rate_basis_points
  } catch (error: unknown) {
    failed.value = true
    toast.error(apiErrorMessage(error, t('payroll.risky_savings.load_failed')))
  } finally {
    loading.value = false
  }
}

function paymentTargetEffectiveOn(): string {
  const [year, month] = props.period.split('-').map(Number)
  return new Date(Date.UTC(year, month + 1, 0)).toISOString().slice(0, 10)
}

function selectPaymentTarget(value: string | number | null): void {
  institutionAccountId.value = typeof value === 'number' ? value : null
  const account = institutionAccounts.value.find(item => item.id === value)
  if (account === undefined) return
  pensionCompany.value = account.institution_name
  variableSymbol.value = account.variable_symbol ?? variableSymbol.value
  specificSymbol.value = account.specific_symbol ?? specificSymbol.value
}

function selectRiskFactor(value: string | number | null): void {
  if (typeof value === 'string') {
    riskFactor.value = value as PayrollRiskySavingsRiskFactor
  }
}

async function save(approve: boolean): Promise<void> {
  const request = payload(approve)
  if (request === null) return
  saving.value = true
  try {
    await payrollApi.saveRiskySavingsEvidence(request)
    await load()
    toast.success(t(approve
      ? 'payroll.risky_savings.approved'
      : 'payroll.risky_savings.draft_saved'))
  } catch (error: unknown) {
    toast.error(apiErrorMessage(error, t('payroll.risky_savings.save_failed')))
  } finally {
    saving.value = false
  }
}

watch(() => props.period, load)
onMounted(load)
</script>

<template>
  <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6" data-testid="risky-savings-panel">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="max-w-3xl">
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.risky_savings.title') }}</h2>
        <p class="mt-1 text-sm text-neutral-500">
          {{ t('payroll.risky_savings.hint', { rate: rateBasisPoints / 100 }) }}
        </p>
      </div>
      <p class="mt-2 text-xs text-neutral-500">
        {{ t('payroll.risky_savings.payment_flow') }}
      </p>
      <span class="rounded-full bg-payroll-50 px-3 py-1 text-xs font-medium text-payroll-700">
        {{ t('payroll.risky_savings.threshold', { eighths: minimumEighths }) }}
      </span>
    </div>

    <div v-if="canWrite || canApprove" class="mt-5 grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
      <div>
        <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.risky_savings.employee') }}</span>
        <PayrollPersonSearchSelect
          :model-value="employeeId"
          :candidates="people"
          :label="t('payroll.risky_savings.employee')"
          :clearable="false"
          @update:model-value="selectEmployee"
        />
      </div>
      <label class="block">
        <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.risky_savings.risk_factor_label') }}</span>
        <SearchableSelect
          :model-value="riskFactor"
          :options="riskFactorOptions"
          :clearable="false"
          accent="payroll"
          @update:model-value="selectRiskFactor"
        />
      </label>
      <label class="block">
        <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.risky_savings.employment') }}</span>
        <SearchableSelect
          :model-value="employmentId"
          :options="employmentOptions"
          :clearable="false"
          accent="payroll"
          @update:model-value="employmentId = $event"
        />
      </label>
      <label class="block">
        <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.risky_savings.full_shifts') }}</span>
        <input v-model.number="fullEightHourShifts" data-testid="risky-full-shifts" type="number" min="0" max="310" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
      </label>
      <label class="block">
        <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.risky_savings.other_hours') }}</span>
        <input v-model.number="otherShiftStartedHours" data-testid="risky-other-hours" type="number" min="0" max="2480" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
      </label>
      <label class="block">
        <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.risky_savings.claimed_on') }}</span>
        <input v-model="rightClaimedOn" data-testid="risky-claimed-on" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
      </label>
      <label class="block">
        <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.risky_savings.informed_on') }}</span>
        <input v-model="employeeInformedOn" data-testid="risky-informed-on" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        <span v-if="employeeInformedOn === ''" class="mt-1 block text-xs text-warning-700">{{ t('payroll.risky_savings.informed_warning') }}</span>
      </label>
      <label class="block">
        <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.risky_savings.pension_company') }}</span>
        <input v-model="pensionCompany" data-testid="risky-company" readonly class="h-9 w-full rounded-md border border-neutral-300 bg-neutral-50 px-3 text-sm text-neutral-700">
      </label>
      <label class="block">
        <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.risky_savings.payment_target') }}</span>
        <SearchableSelect
          :model-value="institutionAccountId"
          :options="paymentTargetOptions"
          :clearable="false"
          accent="payroll"
          @update:model-value="selectPaymentTarget"
        />
        <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.risky_savings.payment_target_help') }}</span>
      </label>
      <label class="block">
        <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.risky_savings.product_reference') }}</span>
        <input v-model="productReference" data-testid="risky-product" maxlength="190" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
      </label>
      <label class="block">
        <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.risky_savings.variable_symbol') }}</span>
        <input v-model="variableSymbol" data-testid="risky-variable-symbol" inputmode="numeric" maxlength="10" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
      </label>
      <label class="block">
        <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.risky_savings.specific_symbol') }}</span>
        <input v-model="specificSymbol" inputmode="numeric" maxlength="10" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
      </label>
      <label class="block">
        <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.risky_savings.payment_message') }}</span>
        <input v-model="paymentMessage" maxlength="190" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
      </label>
      <label class="block sm:col-span-2 xl:col-span-4">
        <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.risky_savings.evidence_reference') }}</span>
        <input v-model="evidenceReference" maxlength="500" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
      </label>
    </div>
    <p class="mt-2 text-xs text-neutral-500">{{ t('payroll.risky_savings.shift_help', { eighths: qualifyingEighths }) }}</p>
    <div class="mt-4 flex flex-wrap justify-end gap-2">
      <button v-if="canWrite" :class="btnFilled('neutral')" :disabled="saving || !formValid" @click="save(false)">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
        {{ t('payroll.risky_savings.save_draft') }}
      </button>
      <button v-if="canApprove" data-testid="risky-approve" :class="btnFilled('success')" :disabled="saving || !formValid" @click="save(true)">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>
        {{ t('payroll.risky_savings.save_approve') }}
      </button>
    </div>

    <p v-if="failed" class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700">
      {{ t('payroll.risky_savings.load_failed') }}
    </p>
    <div v-else-if="!loading && items.length" class="mt-6 overflow-x-auto rounded-lg border border-neutral-200">
      <table class="min-w-full divide-y divide-neutral-200 text-sm">
        <thead><tr class="text-left text-xs uppercase tracking-wide text-neutral-500"><th class="px-4 py-3">{{ t('payroll.risky_savings.employee') }}</th><th class="px-4 py-3">{{ t('payroll.risky_savings.shifts') }}</th><th class="px-4 py-3">{{ t('payroll.risky_savings.contribution') }}</th><th class="px-4 py-3">{{ t('payroll.risky_savings.status_label') }}</th><th class="px-4 py-3 text-right">{{ t('payroll.risky_savings.actions') }}</th></tr></thead>
        <tbody class="divide-y divide-neutral-100">
          <tr v-for="item in items" :key="item.id">
            <td class="px-4 py-3"><p class="font-medium text-neutral-900">{{ item.full_name }}</p><p class="text-xs text-neutral-500">{{ item.employment_code }}</p></td>
            <td class="px-4 py-3">{{ item.qualifying_shift_eighths }} / 8</td>
            <td class="px-4 py-3"><p class="font-medium">{{ item.contribution_minor === null ? '—' : formatMoneyMinor(item.contribution_minor) }}</p><p v-if="item.payment_due_on" class="text-xs text-neutral-500">{{ t('payroll.risky_savings.due_on', { date: item.payment_due_on }) }}</p><p class="text-xs text-neutral-500">{{ item.payment_target_name }} · {{ item.institution_account_masked }}</p></td>
            <td class="px-4 py-3">{{ t(`payroll.risky_savings.status.${item.contribution_status ?? item.status}`) }}</td>
            <td class="px-4 py-3"><div class="flex flex-wrap justify-end gap-2"><button :class="btnOutlineSm('neutral')" @click="edit(item)"><svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}</button></div></td>
          </tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
