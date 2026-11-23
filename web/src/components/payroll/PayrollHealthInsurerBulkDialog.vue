<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollApi,
  type PayrollHealthInsurerAssignment,
  type PayrollHealthInsurerBulkResult,
} from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { useToast } from '@/composables/useToast'
import { formatPeriod, formatPeriodNumeric } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import { BTN_DISABLED_NOTE, btnFilled, btnOutline, btnOutlineSm, disabledTitle, ICONS } from '@/components/ui/buttonStyles'
import { healthInsurerOptions } from '@/utils/healthInsurers'

/*
 * Hromadné zadání zdravotní pojišťovny osobám, které ji v evidenci nemají.
 * Hlášení JMHZ kód pojišťovny nenese, takže po převzetí z hlášení ji nemá
 * nikdo; bez ní výpočet zdravotního pojištění skončí chybou. Zápis jde osobu
 * po osobě přes zákonnou evidenci jako na kartě osoby.
 */
const props = defineProps<{
  /** První den měsíce, za který se osoby vybírají (RRRR-MM-DD). */
  periodStart: string
}>()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'applied', result: PayrollHealthInsurerBulkResult): void
}>()

interface Row {
  employee_id: number
  full_name: string
  insurer_code: string
  from_month: string
  known_from: string | null
}

const { t } = useI18n()
const toast = useToast()
const insurerOptions = healthInsurerOptions()

const rows = ref<Row[]>([])
const loading = ref(false)
const loadError = ref('')
const saving = ref(false)
const saveError = ref('')
const fillCode = ref('')
const result = ref<PayrollHealthInsurerBulkResult | null>(null)

const assignments = computed<PayrollHealthInsurerAssignment[]>(() => rows.value
  .filter(row => row.insurer_code !== '' && /^\d{4}-\d{2}$/.test(row.from_month))
  .map(row => ({ employee_id: row.employee_id, insurer_code: row.insurer_code, effective_from: `${row.from_month}-01` })))
const missingMonth = computed(() => rows.value.some(row => row.insurer_code !== '' && !/^\d{4}-\d{2}$/.test(row.from_month)))
const blockedReason = computed(() => {
  if (missingMonth.value) return t('payroll.health_insurer_bulk.month_required')
  return assignments.value.length === 0 ? t('payroll.health_insurer_bulk.nothing_selected') : ''
})
const names = computed(() => new Map(rows.value.map(row => [row.employee_id, row.full_name])))

async function load() {
  loading.value = true
  loadError.value = ''
  try {
    const preview = await payrollApi.healthInsurerBulkPreview(props.periodStart)
    rows.value = preview.people.map(person => ({
      employee_id: person.employee_id,
      full_name: person.full_name,
      insurer_code: person.known_insurer_code ?? '',
      from_month: person.suggested_from.slice(0, 7),
      known_from: person.known_insurer_code ? (person.known_from ?? null) : null,
    }))
  } catch (error) {
    loadError.value = apiErrorMessage(error, t('payroll.health_insurer_bulk.load_failed'))
  } finally {
    loading.value = false
  }
}

function fillEmpty() {
  if (fillCode.value === '') return
  for (const row of rows.value) {
    if (row.insurer_code === '') row.insurer_code = fillCode.value
  }
}

async function save() {
  if (blockedReason.value !== '' || saving.value) return
  saving.value = true
  saveError.value = ''
  try {
    const response = await payrollApi.healthInsurerBulkApply(assignments.value)
    result.value = response
    if (response.counts.applied === 0 && response.counts.failed > 0) toast.warning(t('payroll.health_insurer_bulk.failed_title', { count: response.counts.failed }))
    else toast.success(t('payroll.health_insurer_bulk.applied_toast', { count: response.counts.applied }))
    emit('applied', response)
  } catch (error) {
    saveError.value = apiErrorMessage(error, t('payroll.health_insurer_bulk.apply_failed'))
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <Modal :title="t('payroll.health_insurer_bulk.title')" width-class="max-w-3xl" @close="emit('close')">
    <div class="space-y-4" data-test="health-insurer-bulk-dialog">
      <p class="text-sm text-neutral-700">{{ t('payroll.health_insurer_bulk.intro', { period: formatPeriod(periodStart.slice(0, 7)) }) }}</p>

      <div v-if="loading" class="space-y-2">
        <div v-for="index in 3" :key="index" class="h-10 animate-pulse rounded-lg bg-neutral-100" />
      </div>

      <div v-else-if="loadError" role="alert" class="space-y-2 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700">
        <p>{{ loadError }}</p>
        <button type="button" :class="btnOutlineSm('danger')" @click="load">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.health_insurer_bulk.retry') }}
        </button>
      </div>

      <p v-else-if="rows.length === 0 && !result" class="rounded-lg bg-success-50 p-3 text-sm text-success-700" data-test="health-insurer-bulk-empty">
        {{ t('payroll.health_insurer_bulk.none_missing') }}
      </p>

      <template v-else-if="!result">
        <div class="flex flex-wrap items-end gap-2">
          <label class="block">
            <span class="mb-1 block text-xs font-medium text-neutral-600">{{ t('payroll.health_insurer_bulk.fill_label') }}</span>
            <select v-model="fillCode" class="h-9 rounded-md border border-neutral-300 bg-surface px-3 text-sm" data-test="health-insurer-bulk-fill-code">
              <option value="">{{ t('payroll.health_insurer_bulk.choose') }}</option>
              <option v-for="insurer in insurerOptions" :key="insurer.value" :value="insurer.value">{{ insurer.label }}</option>
            </select>
          </label>
          <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" :disabled="fillCode === ''" data-test="health-insurer-bulk-fill" @click="fillEmpty">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.copy" /></svg>
            {{ t('payroll.health_insurer_bulk.fill_action') }}
          </button>
        </div>
        <p class="text-xs text-neutral-500">{{ t('payroll.health_insurer_bulk.hint') }}</p>

        <ul class="max-h-96 divide-y divide-neutral-100 overflow-y-auto rounded-lg border border-neutral-200">
          <li
            v-for="row in rows"
            :key="row.employee_id"
            class="flex flex-wrap items-center gap-2 px-3 py-2 text-sm"
            :data-test="`health-insurer-bulk-row-${row.employee_id}`"
          >
            <span class="min-w-40 flex-1">
              <span class="block font-medium text-neutral-900">{{ row.full_name }}</span>
              <span
                v-if="row.known_from"
                class="block text-xs text-neutral-500"
                :data-test="`health-insurer-bulk-known-${row.employee_id}`"
              >{{ t('payroll.health_insurer_bulk.known_later', { period: formatPeriodNumeric(row.known_from.slice(0, 7)) }) }}</span>
            </span>
            <select
              v-model="row.insurer_code"
              class="h-9 min-w-0 rounded-md border border-neutral-300 bg-surface px-2 text-sm"
              :aria-label="t('payroll.health_insurer_bulk.insurer')"
              :data-test="`health-insurer-bulk-code-${row.employee_id}`"
            >
              <option value="">{{ t('payroll.health_insurer_bulk.choose') }}</option>
              <option v-for="insurer in insurerOptions" :key="insurer.value" :value="insurer.value">{{ insurer.label }}</option>
            </select>
            <label class="flex items-center gap-1 text-xs text-neutral-600">
              {{ t('payroll.health_insurer_bulk.from') }}
              <input
                v-model="row.from_month"
                type="month"
                class="h-9 rounded-md border border-neutral-300 bg-surface px-2 text-sm"
                :data-test="`health-insurer-bulk-from-${row.employee_id}`"
              >
            </label>
          </li>
        </ul>

        <p v-if="saveError" role="alert" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700">{{ saveError }}</p>

        <div class="flex flex-wrap items-start justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="emit('close')">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <div class="flex flex-col items-end gap-1.5">
            <button
              type="button"
              class="whitespace-nowrap"
              :class="btnFilled('primary')"
              :disabled="saving || blockedReason !== ''"
              :title="disabledTitle(blockedReason !== '', blockedReason)"
              data-test="health-insurer-bulk-apply"
              @click="save"
            >
              <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
              {{ saving ? t('common.saving') : t('payroll.health_insurer_bulk.apply', { count: assignments.length }) }}
            </button>
            <p v-if="blockedReason" :class="BTN_DISABLED_NOTE">{{ blockedReason }}</p>
          </div>
        </div>
      </template>

      <section v-if="result" class="space-y-3" data-test="health-insurer-bulk-result">
        <p v-if="result.counts.applied > 0" class="text-sm text-success-700">{{ t('payroll.health_insurer_bulk.applied_toast', { count: result.counts.applied }) }}</p>
        <div v-if="result.failed.length" class="rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-xs text-danger-700">
          <p class="font-medium">{{ t('payroll.health_insurer_bulk.failed_title', { count: result.failed.length }) }}</p>
          <ul class="mt-1 space-y-0.5">
            <li v-for="item in result.failed" :key="item.employee_id">
              <span class="font-medium">{{ names.get(item.employee_id) ?? item.employee_id }}:</span> {{ item.message }}
            </li>
          </ul>
        </div>
        <div class="flex justify-end">
          <button type="button" :class="btnOutline('neutral')" @click="emit('close')">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
            {{ t('common.close') }}
          </button>
        </div>
      </section>
    </div>
  </Modal>
</template>
