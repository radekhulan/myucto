<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollJmhzEmployerAnnualEvidenceView,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{ canWrite: boolean }>()
const { t, locale } = useI18n()
const toast = useToast()
const reportYear = ref(Math.max(2026, new Date().getFullYear()))
const view = ref<PayrollJmhzEmployerAnnualEvidenceView | null>(null)
const loading = ref(false)
const saving = ref(false)
const collectiveTypes = ref<string[]>([])
const ownershipForm = ref('')
const averageHeadcount = ref('')
const averageDisabledHeadcount = ref('')
const officeId = ref<number | null>(null)
const evidenceReference = ref('')

const valid = computed(() => collectiveTypes.value.length > 0
  && ownershipForm.value !== ''
  && /^\d{1,7}(?:[.,]\d{1,2})?$/.test(averageHeadcount.value.trim())
  && /^\d{1,7}(?:[.,]\d{1,2})?$/.test(averageDisabledHeadcount.value.trim()))

function decimal(hundredths: number): string {
  return (hundredths / 100).toFixed(2)
}

function fill(response: PayrollJmhzEmployerAnnualEvidenceView): void {
  view.value = response
  const evidence = response.evidence
  collectiveTypes.value = evidence?.collective_agreement_types ?? []
  ownershipForm.value = evidence?.ownership_form ?? ''
  averageHeadcount.value = evidence ? decimal(evidence.average_headcount_hundredths) : ''
  averageDisabledHeadcount.value = evidence
    ? decimal(evidence.average_disabled_headcount_hundredths)
    : ''
  officeId.value = evidence?.ozp_reporting_office_id ?? null
  evidenceReference.value = evidence?.evidence_reference ?? ''
}

async function load(): Promise<void> {
  loading.value = true
  try {
    fill(await payrollApi.jmhzEmployerAnnualEvidence(reportYear.value))
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.employer.jmhz_annual.load_failed')))
  } finally {
    loading.value = false
  }
}

function toggleCollective(code: string): void {
  if (collectiveTypes.value.includes(code)) {
    collectiveTypes.value = collectiveTypes.value.filter(value => value !== code)
    return
  }
  collectiveTypes.value = code === '0'
    ? ['0']
    : [...collectiveTypes.value.filter(value => value !== '0'), code]
}

async function save(): Promise<void> {
  if (!props.canWrite || !valid.value || saving.value) return
  saving.value = true
  try {
    fill(await payrollApi.saveJmhzEmployerAnnualEvidence(reportYear.value, {
      expected_revision_id: view.value?.evidence?.id ?? null,
      collective_agreement_types: collectiveTypes.value,
      ownership_form: ownershipForm.value,
      average_headcount: averageHeadcount.value.trim(),
      average_disabled_headcount: averageDisabledHeadcount.value.trim(),
      ozp_reporting_office_id: officeId.value,
      evidence_reference: evidenceReference.value.trim() || null,
    }))
    toast.success(t('payroll.employer.jmhz_annual.saved'))
  } catch (error) {
    toast.error(apiErrorMessage(error, t('payroll.employer.jmhz_annual.save_failed')))
  } finally {
    saving.value = false
  }
}

function createdAt(value: string): string {
  const date = new Date(value)
  return Number.isNaN(date.getTime())
    ? value
    : new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium', timeStyle: 'short' })
        .format(date)
}

onMounted(load)
</script>

<template>
  <section class="rounded-xl border border-neutral-200 bg-surface p-5" data-test="jmhz-employer-annual">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-base font-semibold text-neutral-900">
          {{ t('payroll.employer.jmhz_annual.title') }}
        </h2>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">
          {{ t('payroll.employer.jmhz_annual.subtitle') }}
        </p>
      </div>
      <div class="flex flex-wrap items-end gap-2">
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">
            {{ t('payroll.employer.jmhz_annual.year') }}
          </span>
          <input
            v-model.number="reportYear"
            type="number"
            min="2026"
            max="2100"
            class="h-9 w-28 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
          >
        </label>
        <button type="button" :class="btnOutline('neutral')" :disabled="loading" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path :d="ICONS.cycle" />
          </svg>
          {{ t('payroll.employer.jmhz_annual.load') }}
        </button>
      </div>
    </div>

    <div v-if="loading" class="mt-4 text-sm text-neutral-500">…</div>
    <template v-else-if="view">
      <div
        v-if="view.evidence"
        class="mt-4 flex flex-wrap gap-x-5 gap-y-1 rounded-md bg-neutral-50 px-3 py-2 text-xs text-neutral-600"
      >
        <span>{{ t('payroll.employer.jmhz_annual.revision', { revision: view.evidence.revision_no }) }}</span>
        <span>{{ createdAt(view.evidence.created_at) }}</span>
        <span>{{ t('payroll.employer.jmhz_annual.disabled_share', { value: decimal(view.evidence.disabled_share_hundredths) }) }}</span>
      </div>

      <fieldset class="mt-4">
        <legend class="text-sm font-medium text-neutral-800">
          {{ t('payroll.employer.jmhz_annual.collective_types') }}
        </legend>
        <div class="mt-2 grid gap-2 lg:grid-cols-2">
          <label
            v-for="entry in view.collective_agreement_types"
            :key="entry.item_code"
            class="flex items-start gap-2 rounded-md border border-neutral-200 px-3 py-2 text-sm text-neutral-700"
          >
            <input
              type="checkbox"
              class="mt-0.5"
              :checked="collectiveTypes.includes(entry.item_code)"
              :disabled="!canWrite"
              :data-test="`jmhz-annual-collective-${entry.item_code}`"
              @change="toggleCollective(entry.item_code)"
            >
            <span>{{ entry.item_code }} — {{ entry.label }}</span>
          </label>
        </div>
      </fieldset>

      <div class="mt-4 grid gap-4 sm:grid-cols-2">
        <label class="block sm:col-span-2">
          <span class="mb-1 block text-xs font-medium text-neutral-600">
            {{ t('payroll.employer.jmhz_annual.ownership') }}
          </span>
          <select
            v-model="ownershipForm"
            :disabled="!canWrite"
            data-test="jmhz-annual-ownership"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
          >
            <option value="">{{ t('payroll.employer.jmhz_annual.choose') }}</option>
            <option v-for="entry in view.ownership_forms" :key="entry.item_code" :value="entry.item_code">
              {{ entry.item_code }} — {{ entry.label }}
            </option>
          </select>
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">
            {{ t('payroll.employer.jmhz_annual.average_headcount') }}
          </span>
          <input
            v-model="averageHeadcount"
            type="text"
            inputmode="decimal"
            :disabled="!canWrite"
            data-test="jmhz-annual-headcount"
            placeholder="0,00"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-right text-sm tabular-nums text-neutral-900"
          >
        </label>
        <label class="block">
          <span class="mb-1 block text-xs font-medium text-neutral-600">
            {{ t('payroll.employer.jmhz_annual.average_disabled_headcount') }}
          </span>
          <input
            v-model="averageDisabledHeadcount"
            type="text"
            inputmode="decimal"
            :disabled="!canWrite"
            data-test="jmhz-annual-disabled-headcount"
            placeholder="0,00"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-right text-sm tabular-nums text-neutral-900"
          >
        </label>
        <label class="block sm:col-span-2">
          <span class="mb-1 block text-xs font-medium text-neutral-600">
            {{ t('payroll.employer.jmhz_annual.office') }}
          </span>
          <select
            v-model="officeId"
            :disabled="!canWrite"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
          >
            <option :value="null">{{ t('payroll.employer.jmhz_annual.all_offices') }}</option>
            <option v-for="office in view.offices" :key="office.id" :value="office.id">
              {{ office.code }} — {{ office.name }}
            </option>
          </select>
          <span class="mt-1 block text-xs text-neutral-500">
            {{ t('payroll.employer.jmhz_annual.office_hint') }}
          </span>
        </label>
        <label class="block sm:col-span-2">
          <span class="mb-1 block text-xs font-medium text-neutral-600">
            {{ t('payroll.employer.jmhz_annual.reference') }}
          </span>
          <input
            v-model="evidenceReference"
            type="text"
            maxlength="500"
            :disabled="!canWrite"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900"
          >
        </label>
      </div>

      <div class="mt-4 flex flex-wrap justify-end gap-2">
        <button
          type="button"
          :class="btnFilled('primary')"
          :disabled="!canWrite || !valid || saving"
          data-test="jmhz-employer-annual-save"
          @click="save"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <path :d="ICONS.check" />
          </svg>
          {{ saving ? t('common.saving') : t('common.save') }}
        </button>
      </div>
    </template>
  </section>
</template>
