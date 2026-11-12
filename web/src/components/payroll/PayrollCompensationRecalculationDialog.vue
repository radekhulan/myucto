<script setup lang="ts">
/*
 * „Přepočítat náhrady z nového průměru": náhled náhrad spočítaných ze starého
 * průměru a přepočet po potvrzení. Schválený běh nic nezmění, jen navede na
 * Vyžádat / Otevřít opravu.
 */
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  payrollAbsenceApi,
  type CompensationRecalculationItem,
  type CompensationRecalculationPeriod,
  type CompensationRecalculationPreview,
  type CompensationRecalculationResult,
} from '@/api/payrollAbsences'
import { apiErrorMessage } from '@/api/errors'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatMoneyMinor as money, formatPeriod } from '@/composables/useFormat'

const props = withDefaults(defineProps<{
  periods: string[]
  employmentIds?: number[] | null
}>(), {
  employmentIds: null,
})

const emit = defineEmits<{
  close: []
  done: [results: CompensationRecalculationResult[]]
}>()

const { t } = useI18n()
const preview = ref<CompensationRecalculationPreview | null>(null)
const loading = ref(true)
const saving = ref(false)
const error = ref('')
const results = ref<CompensationRecalculationResult[]>([])

const periodsWithItems = computed(() => (preview.value?.periods ?? []).filter(period => period.items.length > 0))
const readyPeriods = computed(() => periodsWithItems.value.filter(period =>
  period.availability === 'ready' && period.counts.recalculate > 0))
const recalculateCount = computed(() => readyPeriods.value.reduce((sum, period) => sum + period.counts.recalculate, 0))
const needsRefresh = computed(() => readyPeriods.value.some(period =>
  period.items.some(item => isChange(item) && item.run_id !== null
    && period.runs.some(run => run.id === item.run_id && run.refresh))))
const blockedReason = computed(() => {
  if (preview.value === null || recalculateCount.value === 0) return ''
  if (!preview.value.can_apply) return t('payroll.compensation_recalculation.forbidden')
  if (needsRefresh.value && !preview.value.can_refresh) return t('payroll.compensation_recalculation.refresh_forbidden')
  return ''
})

function isChange(item: CompensationRecalculationItem): boolean {
  return item.action === 'recalculate' || item.action === 'replace'
}

function itemAmount(item: CompensationRecalculationItem): string {
  return item.new_amount_minor === null
    ? money(item.amount_minor)
    : t('payroll.compensation_recalculation.amount_change', {
      from: money(item.amount_minor),
      to: money(item.new_amount_minor),
    })
}

function itemAverage(item: CompensationRecalculationItem): string {
  if (item.old_average_hourly_minor === null || item.new_average_hourly_minor === null) return ''
  return t('payroll.compensation_recalculation.average_change', {
    from: money(item.old_average_hourly_minor),
    to: money(item.new_average_hourly_minor),
  })
}

function itemAction(item: CompensationRecalculationItem): string {
  return t(`payroll.compensation_recalculation.action.${item.action}`)
}

function periodNote(period: CompensationRecalculationPeriod): string {
  if (period.availability !== 'ready') return period.reason ?? ''
  return t('payroll.compensation_recalculation.period_ready', {
    count: period.counts.recalculate,
    difference: money(period.difference_minor),
  })
}

async function load(): Promise<void> {
  loading.value = true
  error.value = ''
  try {
    preview.value = await payrollAbsenceApi.compensationRecalculationPreview(props.periods, props.employmentIds)
  } catch (failure) {
    error.value = apiErrorMessage(failure, t('payroll.compensation_recalculation.load_failed'))
  } finally {
    loading.value = false
  }
}

async function submit(): Promise<void> {
  if (saving.value || recalculateCount.value === 0 || blockedReason.value !== '') return
  saving.value = true
  error.value = ''
  const done: CompensationRecalculationResult[] = []
  try {
    for (const period of readyPeriods.value) {
      done.push(await payrollAbsenceApi.applyCompensationRecalculation({
        period: period.period,
        employment_ids: props.employmentIds,
        input_ids: period.items.filter(isChange).map(item => item.input_id),
      }, crypto.randomUUID()))
    }
    results.value = done
    emit('done', done)
    await load()
  } catch (failure) {
    results.value = done
    error.value = apiErrorMessage(failure, t('payroll.compensation_recalculation.apply_failed'))
    await load()
  } finally {
    saving.value = false
  }
}

onMounted(load)
</script>

<template>
  <Modal
    :title="t('payroll.compensation_recalculation.title')"
    width-class="max-w-3xl"
    @close="emit('close')"
  >
    <div class="space-y-4" data-test="compensation-recalculation-dialog">
      <p class="max-w-prose text-sm text-neutral-600">{{ t('payroll.compensation_recalculation.hint') }}</p>
      <p v-if="loading" class="text-sm text-neutral-500" data-test="compensation-recalculation-loading">
        {{ t('payroll.compensation_recalculation.loading') }}
      </p>
      <p
        v-else-if="periodsWithItems.length === 0 && error === ''"
        class="rounded-lg border border-success-200 bg-success-50 p-3 text-sm text-success-700"
        data-test="compensation-recalculation-empty"
      >
        {{ t('payroll.compensation_recalculation.empty') }}
      </p>
      <section
        v-for="period in periodsWithItems"
        :key="period.period"
        class="space-y-2"
        :data-test="`compensation-recalculation-period-${period.period}`"
      >
        <h3 class="font-semibold text-neutral-900">{{ formatPeriod(period.period) }}</h3>
        <p
          class="rounded-lg border p-3 text-sm"
          :class="period.availability === 'ready' ? 'border-neutral-200 bg-neutral-50 text-neutral-700' : 'border-warning-200 bg-warning-50 text-warning-800'"
          :data-test="period.availability === 'ready' ? 'compensation-recalculation-ready' : 'compensation-recalculation-blocked'"
        >
          {{ periodNote(period) }}
          <RouterLink
            v-if="period.availability !== 'ready'"
            :to="{ name: 'payroll-runs', query: { period: period.period } }"
            class="font-medium text-primary-600 hover:underline"
          >{{ t('payroll.compensation_recalculation.open_runs') }}</RouterLink>
        </p>
        <div class="overflow-x-auto">
          <table class="w-full text-left text-sm">
            <thead class="text-xs text-neutral-500">
              <tr>
                <th class="py-1 pr-3 font-medium">{{ t('payroll.compensation_recalculation.columns.person') }}</th>
                <th class="py-1 pr-3 font-medium">{{ t('payroll.compensation_recalculation.columns.compensation') }}</th>
                <th class="py-1 pr-3 font-medium">{{ t('payroll.compensation_recalculation.columns.amount') }}</th>
                <th class="py-1 font-medium">{{ t('payroll.compensation_recalculation.columns.action') }}</th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="item in period.items"
                :key="item.input_id"
                class="border-t border-neutral-100 align-top"
                data-test="compensation-recalculation-item"
              >
                <td class="py-1.5 pr-3 font-medium text-neutral-900">{{ item.full_name }}</td>
                <td class="py-1.5 pr-3">
                  {{ item.component_name }}
                  <span v-if="itemAverage(item)" class="block text-xs text-neutral-500">{{ itemAverage(item) }}</span>
                </td>
                <td class="py-1.5 pr-3 whitespace-nowrap tabular-nums">{{ itemAmount(item) }}</td>
                <td class="py-1.5">
                  <span :class="item.action === 'manual' ? 'text-warning-700' : 'text-neutral-700'">{{ itemAction(item) }}</span>
                  <span v-if="item.action === 'manual' && item.reason" class="block text-xs text-neutral-500">{{ item.reason }}</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
      <section
        v-if="results.length > 0"
        class="rounded-lg border border-success-200 bg-success-50 p-3 text-sm text-success-700"
        data-test="compensation-recalculation-result"
      >
        <p v-for="result in results" :key="result.period">
          {{ t('payroll.compensation_recalculation.done', {
            period: formatPeriod(result.period),
            count: result.recalculated.length,
          }) }}
          <span v-if="result.recalculated.some(row => row.new_status === 'draft')">
            {{ t('payroll.compensation_recalculation.done_drafts') }}
          </span>
          <span v-if="result.refreshed_runs.length > 0">
            {{ t('payroll.compensation_recalculation.done_refreshed') }}
          </span>
        </p>
      </section>
      <p
        v-if="error"
        role="alert"
        class="rounded-lg border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700"
        data-test="compensation-recalculation-error"
      >
        {{ error }}
      </p>
      <p v-if="preview && recalculateCount > 0 && !preview.can_approve" class="text-xs text-neutral-500" data-test="compensation-recalculation-drafts">
        {{ t('payroll.compensation_recalculation.drafts_hint') }}
      </p>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" :disabled="saving" @click="emit('close')">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
          {{ t('common.close') }}
        </button>
        <button
          v-if="recalculateCount > 0"
          type="button"
          :class="btnFilled('primary')"
          :disabled="saving || blockedReason !== ''"
          :title="blockedReason"
          data-test="compensation-recalculation-confirm"
          @click="submit"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>
          {{ t('payroll.compensation_recalculation.confirm', { count: recalculateCount }) }}
        </button>
      </div>
      <p v-if="blockedReason" class="text-xs text-warning-700" data-test="compensation-recalculation-forbidden">{{ blockedReason }}</p>
    </div>
  </Modal>
</template>
