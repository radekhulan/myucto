<script setup lang="ts">
/**
 * Odložený příjem (JMHZ scénář 8) na kartě skončeného pracovního vztahu.
 *
 * Příjem zúčtovaný v měsíci po skončení vztahu (typicky doplatek odměny) se
 * v měsíčním hlášení vykazuje samostatným formulářem „Odložený příjem". Druh
 * situace (typ 10548) aplikace neodhaduje: účetní ho tu potvrdí za měsíc
 * zúčtování a mzdový běh si potvrzení zmrazí. Bez něj výpočet pojistného
 * příjem odmítne, protože neví, ke kterému měsíci patří.
 */
import { computed, onMounted, ref } from 'vue'
import { isAxiosError } from 'axios'
import { useI18n } from 'vue-i18n'
import {
  payrollDeferredIncomeApi,
  type PayrollDeferredIncome,
  type PayrollDeferredIncomeType,
} from '@/api/payrollDeferredIncome'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnIconSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{
  employmentId: number
  /** Den skončení vztahu `YYYY-MM-DD`; odložený příjem patří až do dalších měsíců. */
  endDate: string
  canWrite: boolean
}>()

const { t } = useI18n()
const toast = useToast()
const loading = ref(true)
const saving = ref(false)
const loadError = ref('')
const saveError = ref('')
const items = ref<PayrollDeferredIncome[]>([])
/** Typy, které server zpracuje sama (`PayrollDeferredIncomeRepository::SUPPORTED_TYPES`). */
const supportedTypes = ref<PayrollDeferredIncomeType[]>(['1'])
const editorOpen = ref(false)
const showValidation = ref(false)
const period = ref('')
const deferredType = ref<PayrollDeferredIncomeType>('1')
const note = ref('')

/** První měsíc, za který se odložený příjem smí potvrdit: měsíc po skončení. */
const firstPeriod = computed(() => {
  const [year, month] = props.endDate.slice(0, 7).split('-').map(Number)
  const next = month === 12 ? [year + 1, 1] : [year, month + 1]
  return `${next[0]}-${String(next[1]).padStart(2, '0')}`
})

/**
 * Roční zúčtování po skončení (typ 4, i v typu 5) se hlásí jen za leden až
 * březen (Pravidla podání JMHZ 1.4.5, kap. 6 bod 3 b); server to hlídá stejně.
 */
const ANNUAL_SETTLEMENT_MONTHS = ['01', '02', '03']
const ANNUAL_SETTLEMENT_TYPES: PayrollDeferredIncomeType[] = ['4', '5']

const periodValid = computed(() => /^\d{4}-\d{2}$/.test(period.value) && period.value >= firstPeriod.value)
const periodFitsType = computed(() => !ANNUAL_SETTLEMENT_TYPES.includes(deferredType.value)
  || ANNUAL_SETTLEMENT_MONTHS.includes(period.value.slice(5, 7)))
const invalidReason = computed(() => {
  if (!periodValid.value) return t('payroll.people.deferred_income.period_invalid', { first: firstPeriod.value })
  if (!periodFitsType.value) return t('payroll.people.deferred_income.period_annual_invalid')

  return ''
})

function openNew() {
  period.value = firstPeriod.value
  deferredType.value = '1'
  note.value = ''
  saveError.value = ''
  showValidation.value = false
  editorOpen.value = true
}

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const list = await payrollDeferredIncomeApi.list(props.employmentId)
    items.value = list.items
    if (list.supported_types.length > 0) supportedTypes.value = list.supported_types
  } catch (error: unknown) {
    loadError.value = apiMessage(error) || t('payroll.people.deferred_income.load_failed')
  } finally {
    loading.value = false
  }
}

async function save() {
  showValidation.value = true
  saveError.value = ''
  if (!props.canWrite || invalidReason.value !== '') return
  saving.value = true
  try {
    const saved = await payrollDeferredIncomeApi.save(props.employmentId, period.value, {
      deferred_type: deferredType.value,
      note: note.value.trim() === '' ? null : note.value.trim(),
    })
    const index = items.value.findIndex(item => item.period_start === saved.period_start)
    if (index === -1) items.value.unshift(saved)
    else items.value.splice(index, 1, saved)
    editorOpen.value = false
    toast.success(t('payroll.people.deferred_income.saved'))
  } catch (error: unknown) {
    saveError.value = apiMessage(error) || t('payroll.people.deferred_income.save_failed')
  } finally {
    saving.value = false
  }
}

async function remove(item: PayrollDeferredIncome) {
  if (!props.canWrite) return
  try {
    await payrollDeferredIncomeApi.remove(props.employmentId, item.period_start.slice(0, 7))
    items.value = items.value.filter(candidate => candidate.id !== item.id)
    toast.success(t('payroll.people.deferred_income.removed'))
  } catch (error: unknown) {
    toast.error(apiMessage(error) || t('payroll.people.deferred_income.save_failed'))
  }
}

function typeLabel(type: PayrollDeferredIncomeType): string {
  switch (type) {
    case '1': return t('payroll.people.deferred_income.type_1')
    case '4': return t('payroll.people.deferred_income.type_4')
    case '5': return t('payroll.people.deferred_income.type_5')
    default: return t('payroll.people.deferred_income.type_other', { code: type })
  }
}

function apiMessage(error: unknown): string {
  if (!isAxiosError<{ error?: { message?: string } }>(error)) return ''
  return error.response?.data?.error?.message ?? ''
}

onMounted(load)
</script>

<template>
  <section class="mt-4 border-t border-neutral-200 pt-4" data-test="deferred-income" data-panel-anchor="deferred_income">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h4 class="text-sm font-semibold text-neutral-900">{{ t('payroll.people.deferred_income.title') }}</h4>
      <button v-if="canWrite" type="button" :class="btnOutlineSm('primary')" data-test="deferred-income-add" @click="openNew">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
          <path :d="ICONS.plus" />
        </svg>
        {{ t('payroll.people.deferred_income.add') }}
      </button>
    </div>
    <p class="mt-1 text-xs text-neutral-500">{{ t('payroll.people.deferred_income.hint') }}</p>

    <div v-if="loadError" class="mt-2 rounded-md bg-danger-50 px-3 py-2 text-xs text-danger-700" role="alert">
      {{ loadError }}
    </div>

    <p v-if="!loading && items.length === 0" class="mt-2 text-xs text-neutral-500" data-test="deferred-income-empty">
      {{ t('payroll.people.deferred_income.empty') }}
    </p>
    <ul v-else class="mt-2 space-y-2">
      <li
        v-for="item in items"
        :key="item.id"
        class="flex flex-wrap items-center justify-between gap-2 rounded-md bg-neutral-50 px-3 py-2 text-xs"
        data-test="deferred-income-item"
      >
        <div class="min-w-0">
          <p class="font-medium text-neutral-800">
            {{ item.period_start.slice(0, 7) }} · {{ typeLabel(item.deferred_type) }}
          </p>
          <p v-if="item.note" class="text-neutral-500">{{ item.note }}</p>
        </div>
        <button
          v-if="canWrite"
          type="button"
          :class="btnIconSm('danger')"
          :title="t('payroll.people.deferred_income.remove')"
          :aria-label="t('payroll.people.deferred_income.remove')"
          data-test="deferred-income-remove"
          @click="remove(item)"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.trash" />
          </svg>
        </button>
      </li>
    </ul>

    <form
      v-if="editorOpen"
      class="mt-3 rounded-lg border border-payroll-500/30 bg-payroll-50 p-3"
      data-test="deferred-income-form"
      @submit.prevent="save"
    >
      <div v-if="saveError" class="mb-2 rounded-md bg-danger-50 px-3 py-2 text-xs text-danger-700" role="alert">
        {{ saveError }}
      </div>
      <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.deferred_income.period') }}
          <input
            v-model="period"
            type="month"
            :min="firstPeriod"
            :disabled="!canWrite"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
            data-test="deferred-income-period"
          >
          <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.people.deferred_income.period_hint') }}</span>
        </label>
        <label class="text-xs text-neutral-600">
          {{ t('payroll.people.deferred_income.type_label') }}
          <select
            v-model="deferredType"
            :disabled="!canWrite"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
            data-test="deferred-income-type"
          >
            <option v-for="type in supportedTypes" :key="type" :value="type">{{ typeLabel(type) }}</option>
          </select>
          <span class="mt-1 block text-xs text-neutral-500">{{ t('payroll.people.deferred_income.type_hint') }}</span>
          <span
            v-if="deferredType === '4'"
            class="mt-1 block text-xs text-neutral-500"
            data-test="deferred-income-type-4-hint"
          >{{ t('payroll.people.deferred_income.type_4_hint') }}</span>
          <span
            v-if="deferredType === '5'"
            class="mt-1 block text-xs text-neutral-500"
            data-test="deferred-income-type-5-hint"
          >{{ t('payroll.people.deferred_income.type_5_hint') }}</span>
        </label>
        <label class="text-xs text-neutral-600 sm:col-span-2">
          {{ t('payroll.people.deferred_income.note') }}
          <input
            v-model="note"
            maxlength="500"
            :disabled="!canWrite"
            class="mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm"
            data-test="deferred-income-note"
          >
        </label>
      </div>
      <p
        v-if="showValidation && invalidReason"
        class="mt-3 rounded-md bg-warning-50 px-3 py-2 text-xs text-warning-800"
        role="alert"
        data-test="deferred-income-invalid"
      >
        {{ invalidReason }}
      </p>
      <div class="mt-3 flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutlineSm('neutral')" @click="editorOpen = false">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.x" />
          </svg>
          {{ t('common.cancel') }}
        </button>
        <button v-if="canWrite" type="submit" :class="btnFilled('success')" :disabled="saving" data-test="deferred-income-save">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.check" />
          </svg>
          {{ saving ? t('common.saving') : t('payroll.people.deferred_income.confirm') }}
        </button>
      </div>
    </form>
  </section>
</template>
