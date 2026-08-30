<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollRegzelProfile,
} from '@/api/payroll'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

defineProps<{ canWrite: boolean }>()

const { t } = useI18n()
const loading = ref(true)
const saving = ref(false)
const profile = ref<PayrollRegzelProfile | null>(null)
const workplaceSuggestion = ref<string | null>(null)
const error = ref('')
const success = ref('')
const taxOfficeCodes = new Set([
  '2000', '2100', '2200', '2300', '2400', '2500', '2600', '2700',
  '2800', '2900', '3000', '3100', '3200', '3300', '4000',
])
const form = reactive({
  row_version: 0,
  social_enterprise: false,
  employment_agency: false,
  protected_labor_market: false,
  tax_office_code: '',
  tax_office_workplace_code: '',
  payer_reference_number: '',
  evidence_confirmed: false,
})
const workplaceRequired = computed(() => form.tax_office_code.trim() !== '4000')
const compatibleWorkplaceSuggestion = computed(() => {
  const officeCode = form.tax_office_code.trim()
  const suggestion = workplaceSuggestion.value
  return suggestion
    && workplaceRequired.value
    && suggestion.slice(0, 2) === officeCode.slice(0, 2)
    ? suggestion
    : null
})

function fill(value: PayrollRegzelProfile | null) {
  profile.value = value
  form.row_version = value?.row_version ?? 0
  form.social_enterprise = value?.social_enterprise ?? false
  form.employment_agency = value?.employment_agency ?? false
  form.protected_labor_market = value?.protected_labor_market ?? false
  form.tax_office_code = value?.tax_office_code ?? ''
  form.tax_office_workplace_code = value?.tax_office_workplace_code ?? ''
  form.payer_reference_number = value?.payer_reference_number ?? ''
  form.evidence_confirmed = false
}

function apiMessage(exception: unknown, fallback: string): string {
  if (isAxiosError<{ error?: { message?: string } }>(exception)) {
    return exception.response?.data?.error?.message || fallback
  }
  const response = (exception as { response?: { data?: { error?: { message?: string } } } })
    ?.response
  return response?.data?.error?.message || fallback
}

async function load() {
  loading.value = true
  error.value = ''
  success.value = ''
  try {
    const response = await payrollApi.regzelProfile()
    workplaceSuggestion.value = response.suggested_tax_office_workplace_code
    fill(response.profile)
  } catch (exception: unknown) {
    error.value = apiMessage(exception, t('payroll.regzel.profile.load_failed'))
  } finally {
    loading.value = false
  }
}

async function save() {
  error.value = ''
  success.value = ''
  if (!form.evidence_confirmed) {
    error.value = t('payroll.regzel.profile.confirmation_required')
    return
  }
  if (!taxOfficeCodes.has(form.tax_office_code.trim())) {
    error.value = t('payroll.regzel.profile.tax_office_code_invalid')
    return
  }
  if (workplaceRequired.value && form.tax_office_workplace_code.trim() === '') {
    error.value = t('payroll.regzel.profile.tax_office_workplace_code_required')
    return
  }
  const workplaceCode = form.tax_office_workplace_code.trim()
  if (workplaceCode !== '' && !/^\d{4}$/.test(workplaceCode)) {
    error.value = t('payroll.regzel.profile.tax_office_workplace_code_invalid')
    return
  }
  if (!workplaceRequired.value && workplaceCode !== '') {
    error.value = t('payroll.regzel.profile.tax_office_workplace_code_forbidden')
    return
  }
  if (workplaceRequired.value
    && workplaceCode.slice(0, 2) !== form.tax_office_code.trim().slice(0, 2)) {
    error.value = t('payroll.regzel.profile.tax_office_workplace_code_mismatch')
    return
  }
  if (form.payer_reference_number.trim() !== ''
    && !/^6\d{8}$/.test(form.payer_reference_number.trim())) {
    error.value = t('payroll.regzel.profile.payer_reference_number_invalid')
    return
  }
  saving.value = true
  try {
    fill(await payrollApi.saveRegzelProfile({
      ...form,
      tax_office_code: form.tax_office_code.trim(),
      tax_office_workplace_code: workplaceCode || null,
      payer_reference_number: form.payer_reference_number.trim() || null,
    }))
    success.value = t('payroll.regzel.profile.saved')
  } catch (exception: unknown) {
    error.value = apiMessage(exception, t('payroll.regzel.profile.save_failed'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div class="max-w-3xl">
        <h2 class="text-lg font-semibold text-neutral-900">
          {{ t('payroll.regzel.profile.title') }}
        </h2>
        <p class="mt-1 text-sm text-neutral-500">
          {{ t('payroll.regzel.profile.description') }}
        </p>
      </div>
      <span
        class="rounded-full px-2.5 py-1 text-xs font-medium"
        :class="profile?.is_complete
          ? 'bg-success-50 text-success-700'
          : 'bg-warning-50 text-warning-700'"
      >
        {{ t(profile?.is_complete
          ? 'payroll.regzel.profile.confirmed'
          : 'payroll.regzel.profile.not_confirmed') }}
      </span>
    </div>

    <div v-if="loading" class="mt-5 h-40 animate-pulse rounded-lg bg-neutral-100" />

    <template v-else>
      <div
        v-if="error"
        class="mt-5 rounded-lg border border-danger-500/30 bg-danger-50 p-4 text-sm text-danger-700"
        role="alert"
      >
        {{ error }}
      </div>
      <div
        v-if="success"
        class="mt-5 rounded-lg border border-success-500/30 bg-success-50 p-4 text-sm text-success-700"
        role="status"
      >
        {{ success }}
      </div>

      <fieldset class="mt-5 space-y-3" :disabled="!canWrite || saving">
        <legend class="text-sm font-medium text-neutral-700">
          {{ t('payroll.regzel.profile.flags_legend') }}
        </legend>
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">
              {{ t('payroll.regzel.profile.tax_office_code') }}
            </span>
            <input
              v-model="form.tax_office_code"
              data-test="regzel-tax-office-code"
              type="text"
              inputmode="numeric"
              maxlength="4"
              autocomplete="off"
              class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
            >
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('payroll.regzel.profile.tax_office_code_hint') }}
            </span>
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">
              {{ t('payroll.regzel.profile.tax_office_workplace_code') }}
              <span v-if="workplaceRequired" class="text-danger-600" aria-hidden="true">*</span>
            </span>
            <input
              v-model="form.tax_office_workplace_code"
              data-test="regzel-tax-office-workplace-code"
              type="text"
              inputmode="numeric"
              maxlength="4"
              autocomplete="off"
              class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
            >
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('payroll.regzel.profile.tax_office_workplace_code_hint') }}
            </span>
            <button
              v-if="compatibleWorkplaceSuggestion && !form.tax_office_workplace_code"
              type="button"
              data-test="regzel-use-workplace-suggestion"
              class="cursor-pointer mt-2 text-xs font-medium text-payroll-700 underline decoration-payroll-400 underline-offset-2"
              @click="form.tax_office_workplace_code = compatibleWorkplaceSuggestion ?? ''"
            >
              {{ t('payroll.regzel.profile.use_workplace_suggestion', { code: compatibleWorkplaceSuggestion }) }}
            </button>
          </label>
          <label class="block">
            <span class="mb-1 block text-sm font-medium text-neutral-700">
              {{ t('payroll.regzel.profile.payer_reference_number') }}
            </span>
            <input
              v-model="form.payer_reference_number"
              data-test="regzel-payer-reference-number"
              type="text"
              inputmode="numeric"
              maxlength="9"
              autocomplete="off"
              class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
            >
            <span class="mt-1 block text-xs text-neutral-500">
              {{ t('payroll.regzel.profile.payer_reference_number_hint') }}
            </span>
          </label>
        </div>

        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-neutral-200 p-3">
          <input
            v-model="form.social_enterprise"
            data-test="social-enterprise"
            type="checkbox"
            class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500"
          >
          <span>
            <span class="block text-sm font-medium text-neutral-900">
              {{ t('payroll.regzel.profile.social_enterprise') }}
            </span>
            <span class="mt-0.5 block text-xs text-neutral-500">
              {{ t('payroll.regzel.profile.social_enterprise_hint') }}
            </span>
          </span>
        </label>
        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-neutral-200 p-3">
          <input
            v-model="form.employment_agency"
            data-test="employment-agency"
            type="checkbox"
            class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500"
          >
          <span>
            <span class="block text-sm font-medium text-neutral-900">
              {{ t('payroll.regzel.profile.employment_agency') }}
            </span>
            <span class="mt-0.5 block text-xs text-neutral-500">
              {{ t('payroll.regzel.profile.employment_agency_hint') }}
            </span>
          </span>
        </label>
        <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-neutral-200 p-3">
          <input
            v-model="form.protected_labor_market"
            data-test="protected-labor-market"
            type="checkbox"
            class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500"
          >
          <span>
            <span class="block text-sm font-medium text-neutral-900">
              {{ t('payroll.regzel.profile.protected_labor_market') }}
            </span>
            <span class="mt-0.5 block text-xs text-neutral-500">
              {{ t('payroll.regzel.profile.protected_labor_market_hint') }}
            </span>
          </span>
        </label>
      </fieldset>

      <label
        v-if="canWrite"
        class="mt-5 flex cursor-pointer items-start gap-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-4"
      >
        <input
          v-model="form.evidence_confirmed"
          data-test="regzel-profile-confirmation"
          type="checkbox"
          class="mt-0.5 h-4 w-4 rounded border-neutral-300 text-payroll-600 focus:ring-payroll-500"
        >
        <span class="text-sm text-neutral-700">
          {{ t('payroll.regzel.profile.confirmation') }}
        </span>
      </label>

      <dl v-if="profile" class="mt-5 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2">
        <div class="rounded-lg bg-neutral-50 p-3">
          <dt class="text-xs text-neutral-500">
            {{ t('payroll.regzel.profile.confirmed_at') }}
          </dt>
          <dd class="mt-1 font-medium text-neutral-900">
            {{ profile.evidence_confirmed_at }}
          </dd>
        </div>
        <div class="rounded-lg bg-neutral-50 p-3">
          <dt class="text-xs text-neutral-500">
            {{ t('payroll.regzel.profile.version') }}
          </dt>
          <dd class="mt-1 font-medium text-neutral-900">
            {{ profile.row_version }}
          </dd>
        </div>
      </dl>

      <div class="mt-5 flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" :disabled="loading || saving" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('common.refresh') }}
        </button>
        <button
          v-if="canWrite"
          type="button"
          data-test="regzel-profile-save"
          :class="btnFilled('primary')"
          :disabled="saving"
          @click="save"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.check" />
          </svg>
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>

      <p v-if="!canWrite" class="mt-5 text-sm text-neutral-500">
        {{ t('payroll.regzel.profile.read_only') }}
      </p>
    </template>
  </section>
</template>
