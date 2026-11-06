<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { closingApi, type ProfitDistribution504Preview, type ResultDecision504Allocation } from '@/api/closing'
import { apiErrorMessage } from '@/api/errors'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import DateInput from '@/components/ui/DateInput.vue'
import Closing504AllocationInput from './Closing504AllocationInput.vue'
import { resultDecisionResourceSelected } from '@/utils/resultDecision504'

const props = defineProps<{ periodId: number; initial: ProfitDistribution504Preview; canWrite: boolean }>()
const emit = defineEmits<{ updated: [] }>()
const { t } = useI18n()
const preview = ref(props.initial)
const date = ref(props.initial.decision?.decision_date ?? '')
const reference = ref(props.initial.decision?.decision_reference ?? '')
const authority = ref(props.initial.decision?.decision_authority ?? '')
const allocations = ref<ResultDecision504Allocation[]>([])
const busy = ref(false)
const error = ref('')
let scopeVersion = 0
function resetRows() {
  allocations.value = preview.value.decision?.allocations.map(row => ({ ...row })) ?? preview.value.cells.map(cell => ({ cell_key: cell.key, role: 'result.retained_profit', amount: Math.abs(cell.available) }))
}
resetRows()
watch(() => [props.periodId, props.initial] as const, () => {
  ++scopeVersion
  preview.value = props.initial
  date.value = props.initial.decision?.decision_date ?? ''
  reference.value = props.initial.decision?.decision_reference ?? ''
  authority.value = props.initial.decision?.decision_authority ?? ''
  busy.value = false
  error.value = ''
  resetRows()
})
const valid = computed(() => reference.value.trim() && authority.value.trim() && date.value === preview.value.as_of
  && preview.value.cells.length > 0 && allocations.value.every(row => Number.isFinite(Number(row.amount)) && Number(row.amount) > 0 && Math.round(Number(row.amount) * 100) / 100 === Number(row.amount))
  && allocations.value.every(row => resultDecisionResourceSelected(preview.value, row))
  && preview.value.cells.every(cell => allocations.value.filter(row => row.cell_key === cell.key).reduce((sum, row) => sum + Math.round(Number(row.amount) * 100), 0) === Math.abs(cell.available_cents)))
async function refresh() {
  const scope = scopeVersion
  const requestedPeriod = props.periodId
  const requestedDate = date.value
  const result = await closingApi.profitDistributionPreview(requestedPeriod, requestedDate)
  if (scope !== scopeVersion || requestedPeriod !== props.periodId || requestedDate !== date.value) return
  if (result.accounting_framework !== 'cz_504') throw new Error(t('accounting.result504.framework_changed'))
  preview.value = result
  resetRows()
}
async function act(work: () => Promise<unknown>) {
  const scope = scopeVersion
  busy.value = true
  error.value = ''
  try { await work() } catch (e) { if (scope === scopeVersion) error.value = apiErrorMessage(e, t('common.error')) } finally { if (scope === scopeVersion) busy.value = false }
}
function save() {
  if (!valid.value || !props.canWrite) return
  const scope = scopeVersion
  return act(async () => {
    await closingApi.profitDistribution(props.periodId, { decision_date: date.value, decision_reference: reference.value.trim(),
      decision_authority: authority.value.trim(), target_row_version: preview.value.target_period.row_version,
      snapshot_hash: preview.value.snapshot_hash, allocations: allocations.value })
    if (scope !== scopeVersion) return
    await refresh()
    if (scope === scopeVersion) emit('updated')
  })
}
function revert() {
  if (!props.canWrite || !confirm(t('accounting.closing.revert.confirm'))) return
  const scope = scopeVersion
  return act(async () => {
    await closingApi.profitDistributionRevert(props.periodId, preview.value.target_period.row_version)
    if (scope !== scopeVersion) return
    await refresh()
    if (scope === scopeVersion) emit('updated')
  })
}
</script>

<template>
  <div class="space-y-4" data-testid="result-decision-504">
    <p class="text-sm text-neutral-600">{{ t('accounting.result504.description') }}</p>
    <div v-if="error" role="alert" class="text-sm text-danger-600">{{ error }}</div>
    <div class="flex flex-wrap items-end gap-3">
      <label class="text-sm">{{ t('accounting.result504.date') }}<DateInput v-model="date" class="block h-9" :disabled="busy || !!preview.existing_entry_id" /></label>
      <button type="button" :disabled="busy || !date" :class="btnOutline('neutral')" @click="act(refresh)"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.eye" /></svg>{{ t('accounting.result504.refresh') }}</button>
    </div>
    <p v-if="date !== preview.as_of" class="text-sm text-warning-700">{{ t('accounting.result504.refresh_required') }}</p>
    <fieldset :disabled="busy || !canWrite || !!preview.existing_entry_id" class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <label class="text-sm">{{ t('accounting.result504.reference') }}<input v-model="reference" maxlength="500" class="block w-full h-9 border border-neutral-300 rounded-md px-2" /></label>
      <label class="text-sm">{{ t('accounting.result504.authority') }}<input v-model="authority" maxlength="500" class="block w-full h-9 border border-neutral-300 rounded-md px-2" /></label>
    </fieldset>
    <Closing504AllocationInput v-model="allocations" :preview="preview" :disabled="busy || !canWrite || !!preview.existing_entry_id" />
    <div class="flex flex-wrap gap-2">
      <button v-if="!preview.existing_entry_id && canWrite" type="button" :class="btnFilled('primary')" :disabled="busy || !valid" @click="save"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.check" /></svg>{{ t('accounting.result504.save') }}</button>
      <button v-if="preview.existing_entry_id && canWrite" type="button" :class="btnOutline('danger')" :disabled="busy" @click="revert"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor"><path :d="ICONS.trash" /></svg>{{ t('accounting.closing.revert.button') }}</button>
    </div>
  </div>
</template>
