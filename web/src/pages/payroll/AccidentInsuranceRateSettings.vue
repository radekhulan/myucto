<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import { payrollApi, type PayrollAccidentInsuranceRate } from '@/api/payroll'
import { useToast } from '@/composables/useToast'
import { btnFilled, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ canWrite: boolean }>()

const { t } = useI18n()
const toast = useToast()
const loading = ref(true)
const saving = ref(false)
const showValidation = ref(false)
const rates = ref<PayrollAccidentInsuranceRate[]>([])

function localDate(): string {
  const now = new Date()
  const year = now.getFullYear()
  const month = String(now.getMonth() + 1).padStart(2, '0')
  const day = String(now.getDate()).padStart(2, '0')
  return `${year}-${month}-${day}`
}

const form = reactive({
  institution_code: '',
  rate_per_mille: '',
  effective_from: localDate(),
})

const current = computed(() => rates.value[0] ?? null)
const institutionCodeValid = computed(() => /^[A-Z0-9][A-Z0-9._-]{0,31}$/.test(form.institution_code.trim().toUpperCase()))
const rateValid = computed(() => {
  const value = form.rate_per_mille.trim().replace(',', '.')
  return /^[0-9]{1,3}(\.[0-9]{1,2})?$/.test(value) && Number(value) > 0 && Number(value) <= 1000
})
const dateValid = computed(() => form.effective_from !== '')
const formValid = computed(() => institutionCodeValid.value && rateValid.value && dateValid.value)

async function load() {
  loading.value = true
  try {
    rates.value = await payrollApi.accidentInsuranceRates()
  } catch {
    toast.error(t('payroll.employer.accident_insurance.load_failed'))
  } finally {
    loading.value = false
  }
}

async function addRate() {
  showValidation.value = true
  if (!props.canWrite || !formValid.value) return
  saving.value = true
  try {
    await payrollApi.createAccidentInsuranceRate({
      institution_code: form.institution_code.trim().toUpperCase(),
      rate_per_mille: form.rate_per_mille.trim().replace(',', '.'),
      effective_from: form.effective_from,
    })
    await load()
    form.rate_per_mille = ''
    showValidation.value = false
    toast.success(t('payroll.employer.accident_insurance.saved'))
  } catch (error: unknown) {
    const message = isAxiosError<{ error?: { message?: string } }>(error)
      ? error.response?.data?.error?.message : null
    toast.error(message || t('payroll.employer.accident_insurance.save_failed'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6">
    <div class="mb-5">
      <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.employer.accident_insurance.title') }}</h2>
      <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.employer.accident_insurance.subtitle') }}</p>
      <p class="mt-1 max-w-3xl text-xs text-neutral-500">{{ t('payroll.employer.accident_insurance.rate_source_hint') }}</p>
    </div>

    <div v-if="loading" class="h-24 animate-pulse rounded-lg bg-neutral-100" />

    <template v-else>
      <p v-if="current" class="mb-4 text-sm text-neutral-700">
        {{ t('payroll.employer.accident_insurance.current', {
          rate: current.rate_per_mille,
          institution: current.institution_code,
          date: current.effective_from,
        }) }}
      </p>
      <p v-else class="mb-4 text-sm text-warning-700">{{ t('payroll.employer.accident_insurance.not_set') }}</p>

      <div v-if="rates.length > 0" class="mb-5 overflow-x-auto">
        <table class="min-w-full divide-y divide-neutral-200 text-sm">
          <thead>
            <tr class="text-left text-xs uppercase tracking-wide text-neutral-500">
              <th class="px-3 py-2">{{ t('payroll.employer.accident_insurance.effective_from') }}</th>
              <th class="px-3 py-2">{{ t('payroll.employer.accident_insurance.institution_code') }}</th>
              <th class="px-3 py-2">{{ t('payroll.employer.accident_insurance.rate_per_mille') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="rate in rates" :key="rate.id">
              <td class="px-3 py-2 font-mono">{{ rate.effective_from }}</td>
              <td class="px-3 py-2 font-mono">{{ rate.institution_code }}</td>
              <td class="px-3 py-2">{{ rate.rate_per_mille }} ‰</td>
            </tr>
          </tbody>
        </table>
      </div>

      <!-- `items-start`, ne `items-end`: pod prvním polem visí vysvětlivka, takže
           zarovnání na spodní hranu posunulo jeho input nahoru a popisky sloupců
           se rozešly. Zarovnáním na horní hranu sedí popisky i vstupy v řadě. -->
      <div v-if="canWrite" class="grid grid-cols-1 gap-4 sm:grid-cols-3 sm:items-start">
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.accident_insurance.institution_code') }}</span>
          <input
            v-model="form.institution_code"
            type="text"
            maxlength="32"
            autocomplete="off"
            :aria-invalid="showValidation && !institutionCodeValid"
            class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 font-mono text-sm uppercase text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
          >
          <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.employer.accident_insurance.institution_code_hint') }}</span>
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.accident_insurance.rate_per_mille') }}</span>
          <input
            v-model="form.rate_per_mille"
            type="text"
            inputmode="decimal"
            maxlength="6"
            :aria-invalid="showValidation && !rateValid"
            class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900 outline-none focus:border-payroll-500 focus:ring-2 focus:ring-payroll-500/20"
          >
        </label>
        <label class="block">
          <span class="mb-1 block text-sm font-medium text-neutral-700">{{ t('payroll.employer.accident_insurance.effective_from') }}</span>
          <input
            v-model="form.effective_from"
            type="date"
            class="h-10 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm"
          >
        </label>
        <div class="sm:col-span-3">
          <button type="button" :class="btnFilled('primary')" :disabled="saving" @click="addRate">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.plus" /></svg>
            {{ saving ? t('common.saving') : t('payroll.employer.accident_insurance.add_rate') }}
          </button>
        </div>
      </div>
    </template>
  </section>
</template>
