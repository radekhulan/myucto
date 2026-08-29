<script setup lang="ts">
/**
 * § 38da a § 38e ZDP — písemnosti k příjmům daňových nerezidentů.
 *
 * - **DPSHL1** — oznámení o příjmech plynoucích do zahraničí. Podává se za každý
 *   příjem a druh příjmu zvlášť, ve lhůtě pro odvod sražené daně; u osvobozeného
 *   příjmu (licenční poplatky, dividendy, úroky) do 31. ledna dalšího roku.
 * - **DPSZD1** — hlášení o srážce zajištění daně. Podává se za každou srážku.
 *
 * Formulář je prázdný záměrně. Aplikace platby nerezidentům se srážkovou daní
 * ani se zajištěním daně neeviduje: ze mzdy ani jedna povinnost nevzniká
 * (§ 38e odst. 1 věta poslední, § 38da odst. 5 písm. b)) a u přijatých dokladů
 * srážková daň neexistuje. Předvyplnit se proto nedá nic než věta plátce, kterou
 * doplní server. Zbytek zadává účetní z dokladu, ke kterému se podání váže.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import {
  foreignIncomeNoticesApi,
  type ForeignAddressType,
  type ForeignIncomeCatalog,
  type ForeignIncomeForm,
  type ForeignIncomeNoticePayload,
  type ForeignIncomePaymentMode,
  type ForeignIncomeVariant,
  type ForeignPayeePayload,
  type ForeignPayeeType,
  type ForeignTaxIdType,
  type TaxSecurityNoticePayload,
  type TaxSecurityRate,
} from '@/api/foreignIncomeNotices'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const canRead = computed(() => auth.canRead('reports'))
const canExport = computed(() => auth.canWrite('reports.export'))

const form = ref<ForeignIncomeForm>('dpshl1')
const catalog = ref<ForeignIncomeCatalog | null>(null)
const loadError = ref('')
const submitting = ref(false)
const submitError = ref('')

const payee = ref({
  taxpayer_type: '02' as ForeignPayeeType,
  first_name: '',
  last_name: '',
  company_name: '',
  birth_date: '',
  tax_id: '',
  tax_id_type: '' as ForeignTaxIdType | '',
  tax_id_country: '',
  residence_country: '',
  city: '',
  postal_code: '',
  street: '',
  address_type: '02' as ForeignAddressType,
  birth_place: '',
  birth_country: '',
})

const notice = ref({
  variant: 'R' as ForeignIncomeVariant,
  discovered_on: '',
  income_kind: 0,
  rate_percent: '',
  payment_mode: 'U' as ForeignIncomePaymentMode,
  payment_date: '',
  payment_year: '',
  paid_amount: '',
  tax_base: '',
  withheld_tax_czk: '',
  withholding_due_on: '',
  remittance_due_on: '',
  payment_currency: '',
  exchange_rate: '',
  foreign_gross: '',
  foreign_gross_currency: '',
  note: '',
  remittance_paid_on: '',
  remittance_amount_czk: '',
  remittance_account: '',
})

const security = ref({
  variant: 'R' as ForeignIncomeVariant,
  income_description: '',
  rate: 'B' as TaxSecurityRate,
  income: '',
  secured_tax_czk: '',
  receivable_on: '',
  decisive_on: '',
  remitted_on: '',
  permanent_establishment_address: '',
  note: '',
})

const isIndividual = computed(() => payee.value.taxpayer_type === '01')
const isIncomeNotice = computed(() => form.value === 'dpshl1')

const selectedKind = computed(() =>
  catalog.value?.income_kinds.find(kind => kind.code === notice.value.income_kind) ?? null,
)

/** Rok úhrady místo data se vyplňuje jen u osvobozeného příjmu. */
const allowsExempt = computed(() => selectedKind.value?.allows_exempt ?? false)

/**
 * Koruny (s desetinnou čárkou nebo tečkou) → haléře. Přes řetězec, ne přes
 * `Math.round(x * 100)`: float by u některých částek utrhl haléř.
 */
function toMinor(input: string): number {
  const normalized = input.replace(/\s/g, '').replace(',', '.')
  if (!/^\d+(\.\d{1,2})?$/.test(normalized)) {
    throw new Error(t('foreign_income.invalid_amount', { value: input }))
  }
  const [whole, fraction = ''] = normalized.split('.')
  return Number(whole) * 100 + Number(fraction.padEnd(2, '0'))
}

/** Procenta → desetiny procenta (15 → 150, 12,5 → 125). */
function toTenths(input: string): number {
  const normalized = input.replace(/\s/g, '').replace(',', '.')
  if (!/^\d+(\.\d)?$/.test(normalized)) {
    throw new Error(t('foreign_income.invalid_rate', { value: input }))
  }
  const [whole, fraction = '0'] = normalized.split('.')
  return Number(whole) * 10 + Number(fraction)
}

/** Kurz → tisíciny (25,5 → 25500). */
function toThousandths(input: string): number {
  const normalized = input.replace(/\s/g, '').replace(',', '.')
  if (!/^\d+(\.\d{1,3})?$/.test(normalized)) {
    throw new Error(t('foreign_income.invalid_rate', { value: input }))
  }
  const [whole, fraction = ''] = normalized.split('.')
  return Number(whole) * 1000 + Number(fraction.padEnd(3, '0'))
}

function blank(value: string): string | null {
  const trimmed = value.trim()
  return trimmed === '' ? null : trimmed
}

function payeePayload(): ForeignPayeePayload {
  return {
    taxpayer_type: payee.value.taxpayer_type,
    first_name: isIndividual.value ? blank(payee.value.first_name) : null,
    last_name: isIndividual.value ? blank(payee.value.last_name) : null,
    company_name: isIndividual.value ? null : blank(payee.value.company_name),
    birth_date: isIndividual.value ? blank(payee.value.birth_date) : null,
    tax_id: blank(payee.value.tax_id),
    tax_id_type: (blank(payee.value.tax_id_type) as ForeignTaxIdType | null),
    tax_id_country: blank(payee.value.tax_id_country),
    residence_country: payee.value.residence_country.trim().toUpperCase(),
    city: payee.value.city.trim(),
    postal_code: blank(payee.value.postal_code),
    street: blank(payee.value.street),
    address_type: payee.value.address_type,
    birth_place: isIndividual.value ? blank(payee.value.birth_place) : null,
    birth_country: isIndividual.value ? blank(payee.value.birth_country) : null,
  }
}

function incomeNoticePayload(): ForeignIncomeNoticePayload {
  const n = notice.value
  const usesYear = blank(n.payment_year) !== null
  const remittances = blank(n.remittance_paid_on) !== null
    ? [{
        paid_on: n.remittance_paid_on,
        amount_czk: Number(n.remittance_amount_czk),
        account: blank(n.remittance_account),
      }]
    : []

  return {
    variant: n.variant,
    discovered_on: n.variant === 'N' ? blank(n.discovered_on) : null,
    payee: payeePayload(),
    income_kind: n.income_kind,
    rate_tenths_of_percent: toTenths(n.rate_percent),
    payment_mode: n.payment_mode,
    payment_date: usesYear ? null : blank(n.payment_date),
    payment_year: usesYear ? Number(n.payment_year) : null,
    paid_amount_minor: toMinor(n.paid_amount),
    tax_base_minor: toMinor(n.tax_base),
    withheld_tax_czk: Number(n.withheld_tax_czk || 0),
    withholding_due_on: blank(n.withholding_due_on),
    remittance_due_on: blank(n.remittance_due_on),
    foreign_gross_minor: blank(n.foreign_gross) !== null ? toMinor(n.foreign_gross) : null,
    foreign_gross_currency: blank(n.foreign_gross_currency),
    payment_currency: blank(n.payment_currency),
    exchange_rate_thousandths:
      blank(n.exchange_rate) !== null ? toThousandths(n.exchange_rate) : null,
    note: blank(n.note),
    remittances,
  }
}

function securityNoticePayload(): TaxSecurityNoticePayload {
  const s = security.value
  return {
    variant: s.variant,
    payee: payeePayload(),
    income_description: s.income_description.trim(),
    rate: s.rate,
    income_minor: toMinor(s.income),
    secured_tax_czk: Number(s.secured_tax_czk || 0),
    receivable_on: s.receivable_on,
    decisive_on: s.decisive_on,
    remitted_on: blank(s.remitted_on),
    permanent_establishment_address: blank(s.permanent_establishment_address),
    note: blank(s.note),
  }
}

const actions = computed<ActionItem[]>(() => [
  {
    key: 'download',
    label: t('foreign_income.download'),
    icon: 'download',
    tier: 'primary',
    variant: 'primary',
    show: canExport.value,
    disabled: submitting.value,
    loading: submitting.value,
    run: () => void submit(),
  },
])

async function submit(): Promise<void> {
  submitting.value = true
  submitError.value = ''
  try {
    const payload = isIncomeNotice.value ? incomeNoticePayload() : securityNoticePayload()
    await foreignIncomeNoticesApi.downloadXml(form.value, payload)
    toast.success(t('foreign_income.download_done'))
  } catch (error) {
    submitError.value = error instanceof Error && !('response' in error)
      ? error.message
      : apiErrorMessage(error, t('foreign_income.download_failed'))
  } finally {
    submitting.value = false
  }
}

onMounted(async () => {
  if (!canRead.value) return
  try {
    catalog.value = await foreignIncomeNoticesApi.catalog()
  } catch (error) {
    loadError.value = apiErrorMessage(error, t('foreign_income.load_failed'))
  }
})
</script>

<template>
  <section v-if="canRead" class="space-y-4 p-4 sm:p-6" data-test="foreign-income-notices">
    <header>
      <h1 class="text-xl font-semibold text-neutral-900">{{ t('foreign_income.title') }}</h1>
      <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('foreign_income.description') }}</p>
      <p class="mt-2 max-w-3xl rounded-lg bg-warning-50 p-3 text-sm text-warning-800">
        {{ t('foreign_income.manual_entry_hint') }}
      </p>
    </header>

    <p v-if="loadError" class="rounded-lg bg-warning-50 p-3 text-sm text-warning-800">
      {{ loadError }}
    </p>

    <div class="flex flex-wrap items-end gap-3">
      <label class="text-sm text-neutral-600">
        <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.form') }}</span>
        <select
          v-model="form"
          class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
          data-test="foreign-income-form"
        >
          <option value="dpshl1">{{ t('foreign_income.form_dpshl1') }}</option>
          <option value="dpszd1">{{ t('foreign_income.form_dpszd1') }}</option>
        </select>
      </label>
      <label class="text-sm text-neutral-600">
        <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.variant') }}</span>
        <select
          v-if="isIncomeNotice"
          v-model="notice.variant"
          class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
          data-test="foreign-income-variant"
        >
          <option value="R">{{ t('foreign_income.variant_r') }}</option>
          <option value="N">{{ t('foreign_income.variant_n') }}</option>
        </select>
        <select
          v-else
          v-model="security.variant"
          class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
          data-test="foreign-income-variant"
        >
          <option value="R">{{ t('foreign_income.variant_r') }}</option>
          <option value="N">{{ t('foreign_income.variant_n') }}</option>
        </select>
      </label>
    </div>

    <fieldset class="rounded-xl border border-neutral-200 bg-surface p-4">
      <legend class="px-1 text-sm font-semibold text-neutral-900">
        {{ t('foreign_income.payee') }}
      </legend>
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.taxpayer_type') }}</span>
          <select
            v-model="payee.taxpayer_type"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            data-test="foreign-income-taxpayer-type"
          >
            <option
              v-for="type in catalog?.taxpayer_types ?? []"
              :key="type"
              :value="type"
            >
              {{ t(`foreign_income.taxpayer_type_${type}`) }}
            </option>
          </select>
        </label>
        <template v-if="isIndividual">
          <label class="text-sm text-neutral-600">
            <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.first_name') }}</span>
            <input v-model="payee.first_name" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="text-sm text-neutral-600">
            <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.last_name') }}</span>
            <input v-model="payee.last_name" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label class="text-sm text-neutral-600">
            <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.birth_date') }}</span>
            <input v-model="payee.birth_date" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label v-if="isIncomeNotice" class="text-sm text-neutral-600">
            <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.birth_place') }}</span>
            <input v-model="payee.birth_place" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          </label>
          <label v-if="isIncomeNotice" class="text-sm text-neutral-600">
            <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.birth_country') }}</span>
            <input v-model="payee.birth_country" maxlength="2" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm uppercase">
          </label>
        </template>
        <label v-else class="text-sm text-neutral-600 sm:col-span-2">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.company_name') }}</span>
          <input v-model="payee.company_name" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" data-test="foreign-income-company">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.tax_id') }}</span>
          <input v-model="payee.tax_id" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label v-if="isIncomeNotice" class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.tax_id_type') }}</span>
          <select v-model="payee.tax_id_type" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            <option value="">—</option>
            <option v-for="type in catalog?.tax_id_types ?? []" :key="type" :value="type">
              {{ t(`foreign_income.tax_id_type_${type}`) }}
            </option>
          </select>
        </label>
        <label v-if="isIncomeNotice" class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.tax_id_country') }}</span>
          <input v-model="payee.tax_id_country" maxlength="2" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm uppercase">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.residence_country') }}</span>
          <input v-model="payee.residence_country" maxlength="2" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm uppercase" data-test="foreign-income-residence-country">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.city') }}</span>
          <input v-model="payee.city" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" data-test="foreign-income-city">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.postal_code') }}</span>
          <input v-model="payee.postal_code" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.street') }}</span>
          <input v-model="payee.street" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label v-if="isIncomeNotice" class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.address_type') }}</span>
          <select v-model="payee.address_type" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            <option v-for="type in catalog?.address_types ?? []" :key="type" :value="type">
              {{ t(`foreign_income.address_type_${type}`) }}
            </option>
          </select>
        </label>
      </div>
    </fieldset>

    <fieldset v-if="isIncomeNotice" class="rounded-xl border border-neutral-200 bg-surface p-4">
      <legend class="px-1 text-sm font-semibold text-neutral-900">
        {{ t('foreign_income.income') }}
      </legend>
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <label class="text-sm text-neutral-600 sm:col-span-2">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.income_kind') }}</span>
          <select
            v-model.number="notice.income_kind"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            data-test="foreign-income-kind"
          >
            <option :value="0">—</option>
            <option v-for="kind in catalog?.income_kinds ?? []" :key="kind.code" :value="kind.code">
              {{ kind.label }} ({{ kind.paragraph }})
            </option>
          </select>
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.rate') }}</span>
          <input v-model="notice.rate_percent" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" data-test="foreign-income-rate">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.payment_mode') }}</span>
          <select v-model="notice.payment_mode" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
            <option value="U">{{ t('foreign_income.payment_mode_u') }}</option>
            <option value="Z">{{ t('foreign_income.payment_mode_z') }}</option>
          </select>
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.payment_date') }}</span>
          <input v-model="notice.payment_date" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" data-test="foreign-income-payment-date">
        </label>
        <label v-if="allowsExempt" class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.payment_year') }}</span>
          <input v-model="notice.payment_year" type="number" min="2021" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          <span class="mt-1 block text-xs text-neutral-500">{{ t('foreign_income.payment_year_hint') }}</span>
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.paid_amount') }}</span>
          <input v-model="notice.paid_amount" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" data-test="foreign-income-paid-amount">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.tax_base') }}</span>
          <input v-model="notice.tax_base" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" data-test="foreign-income-tax-base">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.withheld_tax') }}</span>
          <input v-model="notice.withheld_tax_czk" type="number" min="0" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.withholding_due_on') }}</span>
          <input v-model="notice.withholding_due_on" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.remittance_due_on') }}</span>
          <input v-model="notice.remittance_due_on" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.payment_currency') }}</span>
          <input v-model="notice.payment_currency" maxlength="3" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm uppercase">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.exchange_rate') }}</span>
          <input v-model="notice.exchange_rate" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.foreign_gross') }}</span>
          <input v-model="notice.foreign_gross" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.foreign_gross_currency') }}</span>
          <input v-model="notice.foreign_gross_currency" maxlength="3" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm uppercase">
        </label>
        <label v-if="notice.variant === 'N'" class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.discovered_on') }}</span>
          <input v-model="notice.discovered_on" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="text-sm text-neutral-600 sm:col-span-3">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.note') }}</span>
          <input v-model="notice.note" maxlength="75" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
      </div>

      <h3 class="mt-4 text-sm font-semibold text-neutral-900">
        {{ t('foreign_income.remittance') }}
      </h3>
      <p class="mt-1 text-xs text-neutral-500">{{ t('foreign_income.remittance_hint') }}</p>
      <div class="mt-2 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.remittance_paid_on') }}</span>
          <input v-model="notice.remittance_paid_on" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.remittance_amount') }}</span>
          <input v-model="notice.remittance_amount_czk" type="number" min="0" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.remittance_account') }}</span>
          <input v-model="notice.remittance_account" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
      </div>
    </fieldset>

    <fieldset v-else class="rounded-xl border border-neutral-200 bg-surface p-4">
      <legend class="px-1 text-sm font-semibold text-neutral-900">
        {{ t('foreign_income.security') }}
      </legend>
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <label class="text-sm text-neutral-600 sm:col-span-3">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.income_description') }}</span>
          <input
            v-model="security.income_description"
            maxlength="75"
            :placeholder="t('foreign_income.income_description_hint')"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"
            data-test="foreign-income-description"
          >
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.security_rate') }}</span>
          <select v-model="security.rate" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" data-test="foreign-income-security-rate">
            <option v-for="rate in catalog?.security_rates ?? []" :key="rate" :value="rate">
              {{ t(`foreign_income.security_rate_${rate}`) }}
            </option>
          </select>
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.security_income') }}</span>
          <input v-model="security.income" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" data-test="foreign-income-security-income">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.secured_tax') }}</span>
          <input v-model="security.secured_tax_czk" type="number" min="0" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
          <span class="mt-1 block text-xs text-neutral-500">{{ t('foreign_income.secured_tax_hint') }}</span>
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.receivable_on') }}</span>
          <input v-model="security.receivable_on" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" data-test="foreign-income-receivable-on">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.decisive_on') }}</span>
          <input v-model="security.decisive_on" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" data-test="foreign-income-decisive-on">
        </label>
        <label class="text-sm text-neutral-600">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.remitted_on') }}</span>
          <input v-model="security.remitted_on" type="date" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="text-sm text-neutral-600 sm:col-span-2">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.establishment') }}</span>
          <input v-model="security.permanent_establishment_address" maxlength="150" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
        <label class="text-sm text-neutral-600 sm:col-span-3">
          <span class="mb-1 block text-xs font-medium">{{ t('foreign_income.note') }}</span>
          <input v-model="security.note" maxlength="75" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm">
        </label>
      </div>
    </fieldset>

    <p
      v-if="submitError"
      class="rounded-lg bg-danger-50 p-3 text-sm text-danger-800"
      data-test="foreign-income-error"
    >
      {{ submitError }}
    </p>

    <ActionBar :actions="actions" />
    <p class="text-xs text-neutral-500">{{ t('foreign_income.deadlines') }}</p>
  </section>
</template>
