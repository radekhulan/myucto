<script setup lang="ts">
/**
 * Tři otázky pro měsíční hlášení ČSSZ (JMHZ) při zakládání pracovního vztahu.
 *
 * Why: sloupce `jmhz_apz_contribution_status`, `jmhz_functional_benefits_status`
 * a `jmhz_temporary_assignment_status` zůstávaly na výchozím „nevyplněno" a nic
 * na ně účetní neupozornilo — poznala je až u zmrazení hlášení, kde jí tři údaje
 * krát čtyři zaměstnanci nepustily podání. Odpověď přitom zná ten, kdo vztah
 * zakládá, a je téměř vždy „ne".
 *
 * Proto: celé věty, ne zkratka „JMHZ příspěvek APZ"; předvybrané „ne";
 * sbalitelná sekce, aby zakládací formulář nenarostl o tři technické otázky;
 * a nic z toho není povinné — nevyplnění hlášení vyloží jako „ne".
 */
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type {
  PayrollEmploymentJmhzEvidenceOptions,
  PayrollVerifiedTriState,
} from '@/api/payroll'
import { loadPayrollJmhzOptions } from '@/composables/usePayrollJmhzOptions'

withDefaults(defineProps<{ disabled?: boolean; open?: boolean }>(), {
  disabled: false,
  open: false,
})

const apzStatus = defineModel<PayrollVerifiedTriState>('apzStatus', { required: true })
const apzInstrumentCode = defineModel<string | null>('apzInstrumentCode', { required: true })
const functionalBenefits = defineModel<PayrollVerifiedTriState>('functionalBenefits', { required: true })
const temporaryAssignment = defineModel<PayrollVerifiedTriState>('temporaryAssignment', { required: true })

const { t } = useI18n()

const jmhzOptions = ref<PayrollEmploymentJmhzEvidenceOptions | null>(null)

// Nástroj APZ se ptá jen ten zlomek firem, který příspěvek skutečně dostává;
// číselník je ale cachovaný na celý běh aplikace, takže načtení nic nestojí.
onMounted(() => {
  void loadPayrollJmhzOptions().then((loaded) => { jmhzOptions.value = loaded })
})

function onApzStatusChange() {
  if (apzStatus.value !== 'yes') apzInstrumentCode.value = null
}

const SELECT = 'mt-1 w-full min-w-0 rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm'
const LABEL = 'min-w-0 text-xs text-neutral-600'
</script>

<template>
  <details
    class="group mt-4 rounded-lg border border-neutral-200"
    :open="open"
    data-test="jmhz-relation-questions"
  >
    <summary class="flex cursor-pointer list-none items-center gap-2 px-3 py-2">
      <svg class="h-4 w-4 shrink-0 text-neutral-500 transition-transform group-open:rotate-180" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
      <span class="min-w-0">
        <span class="block text-xs font-semibold text-neutral-900">{{ t('payroll.people.jmhz_questions.title') }}</span>
        <span class="mt-0.5 block text-xs text-neutral-500">{{ t('payroll.people.jmhz_questions.hint') }}</span>
      </span>
    </summary>
    <div class="grid min-w-0 grid-cols-1 gap-3 border-t border-neutral-200 p-3 sm:grid-cols-2">
      <label :class="[LABEL, 'sm:col-span-2']">
        {{ t('payroll.people.jmhz_questions.apz') }}
        <select
          v-model="apzStatus"
          :disabled="disabled"
          :class="SELECT"
          data-test="jmhz-question-apz"
          @change="onApzStatusChange"
        >
          <option value="no">{{ t('common.no') }}</option>
          <option value="yes">{{ t('common.yes') }}</option>
        </select>
      </label>
      <label v-if="apzStatus === 'yes'" :class="[LABEL, 'sm:col-span-2']">
        {{ t('payroll.people.jmhz_questions.apz_instrument') }}
        <select
          v-model="apzInstrumentCode"
          :disabled="disabled"
          :class="SELECT"
          data-test="jmhz-question-apz-instrument"
        >
          <option :value="null" disabled>{{ t('payroll.people.jmhz_evidence.select_apz') }}</option>
          <option v-for="option in jmhzOptions?.apz_instruments ?? []" :key="option.code" :value="option.code">{{ option.code }} · {{ option.label }}</option>
        </select>
      </label>
      <label :class="[LABEL, 'sm:col-span-2']">
        {{ t('payroll.people.jmhz_questions.functional_benefits') }}
        <select
          v-model="functionalBenefits"
          :disabled="disabled"
          :class="SELECT"
          data-test="jmhz-question-functional-benefits"
        >
          <option value="no">{{ t('common.no') }}</option>
          <option value="yes">{{ t('common.yes') }}</option>
        </select>
      </label>
      <label :class="[LABEL, 'sm:col-span-2']">
        {{ t('payroll.people.jmhz_questions.temporary_assignment') }}
        <select
          v-model="temporaryAssignment"
          :disabled="disabled"
          :class="SELECT"
          data-test="jmhz-question-temporary-assignment"
        >
          <option value="no">{{ t('common.no') }}</option>
          <option value="yes">{{ t('common.yes') }}</option>
        </select>
      </label>
      <p v-if="temporaryAssignment === 'yes'" class="min-w-0 text-xs text-warning-700 sm:col-span-2">
        {{ t('payroll.people.jmhz_evidence.temporary_assignment_blocker') }}
      </p>
      <p class="min-w-0 text-xs text-neutral-500 sm:col-span-2">
        {{ t('payroll.people.jmhz_questions.default_no_hint') }}
      </p>
    </div>
  </details>
</template>
