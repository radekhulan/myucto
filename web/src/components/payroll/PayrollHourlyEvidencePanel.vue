<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollApi, type PayrollHourlyAutomaticKey, type PayrollHourlyEvidenceResponse, type PayrollHourlyPremiumSegment, type PayrollHourlyHolidayEvidence } from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { formatPayrollDuration, parsePayrollDurationDraft } from '@/pages/payroll/payrollDuration'
import { formatDate, formatDateTime } from '@/composables/useFormat'
import { btnFilledSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import DateInput from '@/components/ui/DateInput.vue'
const props = defineProps<{ employmentId: number; period: string; canWrite: boolean }>()
const emit = defineEmits<{ updated: [] }>()
const { t } = useI18n()
interface SegmentDraft {
  from: string; to: string; night: string; weekend: string; holiday: string
  holidayDays: Array<{ date: string; hours: string; original: number | null }>
  overtimeDays: Array<{ date: string; hours: string; original: number | null }>
  overtimeRequired: boolean
  overtimeUnits: number | null
  difficultyConfirmed: boolean
  exposures: Array<{ hours: string; factors: string; original: number | null }>
  original: PayrollHourlyPremiumSegment | null
}
const state = ref<PayrollHourlyEvidenceResponse | null>(null)
const segments = ref<SegmentDraft[]>([])
const editable = computed(() => props.canWrite && state.value?.can_write !== false)
// Server posoudil výslovnou nulu sám: nárok v měsíci objektivně vzniknout nemůže.
const automatic = computed<Partial<Record<PayrollHourlyAutomaticKey, string>>>(() => {
  const value = state.value?.automatic_assessment
  return !value || Array.isArray(value) ? {} : value
})
function isAutomatic(key: PayrollHourlyAutomaticKey): boolean {
  const rows = key === 'nonworked_holidays' ? holidays.value : key === 'holiday_time_off_grants' ? grants.value : overtimeAllocations.value
  return automatic.value[key] !== undefined && rows.length === 0
}
function automaticText(key: PayrollHourlyAutomaticKey): string {
  return t('payroll.hourly_evidence.automatic', { rule: t(`payroll.hourly_evidence.automatic_rules.${automatic.value[key]}`) })
}
const reasonRequired = computed(() => (state.value?.row_version ?? 0) > 0)
const reason = ref('')
const segmentationChanged = ref(false)
const holidaysConfirmed = ref(false)
const grantsConfirmed = ref(false)
const overtimeConfirmed = ref(false)
const holidays = ref<Array<{ date: string; hours: string; original: number | null }>>([])
const grants = ref<Array<{ entitlement: string; absence: string; hours: string; original: number | null }>>([])
const overtimeAllocations = ref<Array<{ workDate: string; absence: string; hours: string; reference: string; original: number | null }>>([])
function absenceKey(id: number, date: string): string { return `${id}:${date}` }
function absenceOptions(kind: 'holiday' | 'overtime') { return (state.value?.options.time_off_absences ?? []).filter(item => item.kind === kind) }
function requiredUnits(raw: string, original: number | null, available?: number): number {
  const units = optionalDuration(raw, original)
  if (units == null || units <= 0 || available == null || units > available) throw new Error(t('payroll.hourly_evidence.allocation_invalid'))
  return units
}
const error = ref('')
const loading = ref(false)
const saving = ref(false)
const historyOpen = ref(false)
let requestId = 0
const inputClass = 'mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm disabled:bg-neutral-100'
function hydrate(response: PayrollHourlyEvidenceResponse) {
  const evidence = response.evidence
  holidaysConfirmed.value = Array.isArray(evidence?.nonworked_holidays)
  grantsConfirmed.value = Array.isArray(evidence?.holiday_time_off_grants)
  overtimeConfirmed.value = Array.isArray(evidence?.overtime_time_off_allocations)
  holidays.value = (evidence?.nonworked_holidays ?? []).map(item => ({ date: item.date, hours: formatPayrollDuration(item.duration_units), original: item.duration_units }))
  grants.value = (evidence?.holiday_time_off_grants ?? []).map(item => ({ entitlement: String(item.entitlement_id), absence: absenceKey(item.absence_id, item.granted_on), hours: formatPayrollDuration(item.duration_units), original: item.duration_units }))
  overtimeAllocations.value = (evidence?.overtime_time_off_allocations ?? []).map(item => ({ workDate: item.work_date, absence: absenceKey(item.absence_id, item.granted_on), hours: formatPayrollDuration(item.duration_units), reference: item.reference, original: item.duration_units }))
  const saved = response.evidence?.premium_segments ?? []
  // Uložené jemnější členění se zachovává; server znovu ověří hranice zásad.
  segmentationChanged.value = saved.length > 0 && !saved.every(item => response.segments_suggested.some(range => item.date_from >= range.date_from && item.date_to <= range.date_to))
  const ranges = saved.length && !segmentationChanged.value ? saved : response.segments_suggested
  segments.value = ranges.map(range => {
    const item = saved.find(item => item.date_from === range.date_from && item.date_to === range.date_to) ?? null
    const scope = response.segments_suggested.find(scope => range.date_from >= scope.date_from && range.date_to <= scope.date_to)
    return { overtimeUnits: scope?.date_from === range.date_from && scope?.date_to === range.date_to ? scope.overtime_duration_units : null, overtimeRequired: scope?.overtime_mode === 'compensatory_time_off', overtimeDays: (item?.overtime_work_days ?? []).map(day => ({ date: day.date, hours: formatPayrollDuration(day.duration_units), original: day.duration_units })), from: range.date_from, to: range.date_to, night: formatPayrollDuration(item?.night_duration_units ?? null), weekend: formatPayrollDuration(item?.weekend_duration_units ?? null), holiday: formatPayrollDuration(item?.holiday_work_duration_units ?? null), holidayDays: (item?.holiday_work_days ?? []).map(day => ({ date: day.date, hours: formatPayrollDuration(day.duration_units), original: day.duration_units })), difficultyConfirmed: item?.difficult_exposures != null, exposures: (item?.difficult_exposures ?? []).map(exposure => ({ hours: formatPayrollDuration(exposure.duration_units), factors: String(exposure.factors), original: exposure.duration_units })), original: item }
  })
}
async function load(offset = 0, historyOnly = false) {
  const request = ++requestId
  loading.value = true; error.value = ''
  try {
    const response = await payrollApi.hourlyEvidence(props.employmentId, props.period, offset)
    if (request !== requestId) return
    if (historyOnly && state.value) {
      state.value = { ...state.value, history: response.history, history_offset: response.history_offset, history_has_more: response.history_has_more }
    } else { state.value = response; hydrate(response) }
  } catch (failure) { if (request === requestId) error.value = apiErrorMessage(failure, t('payroll.hourly_evidence.load_failed')) }
  finally { if (request === requestId) loading.value = false }
}
onMounted(() => load())
onBeforeUnmount(() => { ++requestId })
function optionalDuration(raw: string, original?: number | null): number | null {
  if (!raw.trim()) return null
  const result = parsePayrollDurationDraft(raw, original)
  if (result == null) throw new Error(t('payroll.worked_hours.duration_invalid'))
  return result
}
async function save() {
  if (!editable.value || !state.value || saving.value) return
  error.value = ''
  try {
    if (reasonRequired.value && !reason.value.trim()) throw new Error(t('payroll.worked_hours.reason_required'))
    if ((!holidaysConfirmed.value && !isAutomatic('nonworked_holidays')) || (!grantsConfirmed.value && !isAutomatic('holiday_time_off_grants')) || (!overtimeConfirmed.value && !isAutomatic('overtime_time_off_allocations'))) throw new Error(t('payroll.hourly_evidence.confirm_holidays'))
    const payload: PayrollHourlyPremiumSegment[] = state.value.work_source !== 'manual_summary' ? [] : segments.value.map(segment => {
      const holiday = optionalDuration(segment.holiday, segment.original?.holiday_work_duration_units)
      const days = holiday != null && holiday > 0 ? segment.holidayDays.map(day => {
        const units = optionalDuration(day.hours, day.original)
        if (!day.date || units == null || units <= 0) throw new Error(t('payroll.hourly_evidence.holiday_days_required'))
        return { date: day.date, duration_units: units }
      }) : []
      if (holiday != null && holiday > 0 && (days.length === 0 || days.reduce((sum, day) => sum + day.duration_units, 0) !== holiday)) throw new Error(t('payroll.hourly_evidence.holiday_days_required'))
      const overtimeDays = segment.overtimeRequired ? segment.overtimeDays.map(day => {
        const units = optionalDuration(day.hours, day.original)
        if (!day.date || units == null || units <= 0) throw new Error(t('payroll.hourly_evidence.overtime_days_required'))
        return { date: day.date, duration_units: units }
      }) : []
      if (segment.overtimeRequired && segment.overtimeUnits != null && segment.overtimeUnits > 0 && (overtimeDays.length === 0 || overtimeDays.reduce((sum, day) => sum + day.duration_units, 0) !== segment.overtimeUnits)) throw new Error(t('payroll.hourly_evidence.overtime_days_required'))
      const exposures = segment.difficultyConfirmed ? segment.exposures.map(exposure => {
        const units = optionalDuration(exposure.hours, exposure.original)
        const factors = Number(exposure.factors)
        if (units == null || units <= 0 || !/^\d+$/.test(exposure.factors) || !Number.isSafeInteger(factors) || factors <= 0) throw new Error(t('payroll.hourly_evidence.exposure_invalid'))
        return { duration_units: units, factors }
      }) : null
      return { date_from: segment.from, date_to: segment.to, night_duration_units: optionalDuration(segment.night, segment.original?.night_duration_units), weekend_duration_units: optionalDuration(segment.weekend, segment.original?.weekend_duration_units), holiday_work_duration_units: holiday, holiday_work_days: days, overtime_work_days: overtimeDays, difficult_exposures: exposures }
    })
    // Automaticky posouzená skupina se neukládá jako nula, aby ji server vyhodnotil při každém výpočtu.
    const holidayPayload: PayrollHourlyHolidayEvidence = {
      nonworked_holidays: isAutomatic('nonworked_holidays') ? null : holidays.value.map(item => {
        const option = state.value!.options.nonworked_holidays.find(option => option.date === item.date)
        if (option?.valid === false) throw new Error(option.reason || t('payroll.hourly_evidence.allocation_invalid'))
        return { date: item.date, duration_units: requiredUnits(item.hours, item.original, option?.available_duration_units) }
      }),
      holiday_time_off_grants: isAutomatic('holiday_time_off_grants') ? null : grants.value.map(item => {
        const option = state.value!.options.holiday_time_off_entitlements.find(option => String(option.entitlement_id) === item.entitlement)
        const absence = absenceOptions('holiday').find(absence => absenceKey(absence.absence_id, absence.date) === item.absence)
        if (!option || !absence || option.valid === false || absence.valid === false) throw new Error(t('payroll.hourly_evidence.allocation_invalid'))
        return { entitlement_id: option.entitlement_id, entitlement_revision: option.entitlement_revision, granted_on: absence.date, absence_id: absence.absence_id, duration_units: requiredUnits(item.hours, item.original, Math.min(option.available_duration_units, absence.available_duration_units)) }
      }),
      overtime_time_off_allocations: isAutomatic('overtime_time_off_allocations') ? null : overtimeAllocations.value.map(item => {
        const option = state.value!.options.overtime_time_off_entitlements?.find(option => option.work_date === item.workDate)
        const absence = absenceOptions('overtime').find(absence => absenceKey(absence.absence_id, absence.date) === item.absence)
        if (!option || !absence || option.valid === false || absence.valid === false || !item.reference.trim()) throw new Error(t('payroll.hourly_evidence.allocation_invalid'))
        return { work_date: option.work_date, granted_on: absence.date, absence_id: absence.absence_id, reference: item.reference.trim(), duration_units: requiredUnits(item.hours, item.original, Math.min(option.available_duration_units, absence.available_duration_units)) }
      }),
    }
    saving.value = true
    await payrollApi.saveHourlyEvidence(props.employmentId, { period: props.period, row_version: state.value.row_version, work_source_hash: state.value.work_source_hash, ...(reason.value.trim() ? { reason: reason.value.trim() } : {}), premium_segments: payload, ...holidayPayload })
    reason.value = ''
    emit('updated')
    await load()
  } catch (failure) { error.value = apiErrorMessage(failure, failure instanceof Error ? failure.message : t('payroll.hourly_evidence.save_failed')) }
  finally { saving.value = false }
}
</script>

<template>
  <section class="mt-3 rounded-md border border-neutral-200 bg-surface p-3 text-xs" data-test="hourly-evidence-panel">
    <h4 class="font-semibold">{{ t('payroll.hourly_evidence.title') }}</h4>
    <p class="mt-1 text-neutral-500">{{ t('payroll.hourly_evidence.hint') }}</p>
    <p v-if="loading" class="mt-2 text-neutral-500">{{ t('common.loading') }}</p>
    <p v-if="error" class="mt-2 text-danger-700" role="alert" data-test="hourly-evidence-error">{{ error }}</p>
    <form v-if="state" class="mt-3 space-y-3" @submit.prevent="save">
      <div v-if="segmentationChanged" class="rounded-md bg-warning-50 p-3 text-warning-800" data-test="hourly-evidence-boundaries-changed">
        <p>{{ t('payroll.hourly_evidence.boundaries_changed') }}</p>
        <p v-for="(item, index) in state.evidence?.premium_segments ?? []" :key="index" class="mt-1">{{ formatDate(item.date_from) }} – {{ formatDate(item.date_to) }} · {{ t('payroll.people.surcharge_policy.kinds.night') }}: {{ item.night_duration_units === null ? t('payroll.worked_hours.unknown') : formatPayrollDuration(item.night_duration_units) }} · {{ t('payroll.people.surcharge_policy.kinds.weekend') }}: {{ item.weekend_duration_units === null ? t('payroll.worked_hours.unknown') : formatPayrollDuration(item.weekend_duration_units) }} · {{ t('payroll.hourly_evidence.holiday_work') }}: {{ item.holiday_work_duration_units === null ? t('payroll.worked_hours.unknown') : formatPayrollDuration(item.holiday_work_duration_units) }}</p>
      </div>
      <p v-if="!editable" class="text-neutral-500">{{ t('payroll.hourly_evidence.readonly') }}</p>
      <fieldset :disabled="!editable || saving || loading" class="space-y-3">
        <div v-for="(segment, index) in state.work_source === 'manual_summary' ? segments : []" :key="index" class="rounded-md border border-neutral-200 p-3" data-test="hourly-evidence-segment">
          <p class="font-medium">{{ formatDate(segment.from) }} – {{ formatDate(segment.to) }}</p>
          <div class="mt-2 grid gap-3 sm:grid-cols-3">
            <label>{{ t('payroll.people.surcharge_policy.kinds.night') }}<input v-model="segment.night" :class="inputClass" :data-test="`hourly-evidence-night-${index}`"></label>
            <label>{{ t('payroll.people.surcharge_policy.kinds.weekend') }}<input v-model="segment.weekend" :class="inputClass" :data-test="`hourly-evidence-weekend-${index}`"></label>
            <label>{{ t('payroll.hourly_evidence.holiday_work') }}<input v-model="segment.holiday" :class="inputClass" :data-test="`hourly-evidence-holiday-${index}`"></label>
          </div>
          <div v-if="(parsePayrollDurationDraft(segment.holiday, segment.original?.holiday_work_duration_units) ?? 0) > 0" class="mt-3 space-y-2">
            <p>{{ t('payroll.hourly_evidence.holiday_days_hint') }}</p>
            <div v-for="(day, dayIndex) in segment.holidayDays" :key="dayIndex" class="flex flex-wrap items-end gap-2">
              <label>{{ t('common.date') }}<DateInput v-model="day.date" :class="inputClass" /></label>
              <label>{{ t('payroll.hourly_evidence.hours') }}<input v-model="day.hours" :class="inputClass"></label>
              <button type="button" :class="btnOutlineSm('danger')" @click="segment.holidayDays.splice(dayIndex, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('common.remove') }}</button>
            </div>
            <button type="button" :class="btnOutlineSm('neutral')" @click="segment.holidayDays.push({ date: '', hours: '', original: null })"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.hourly_evidence.add_holiday_day') }}</button>
          </div>
          <div v-if="segment.overtimeRequired" class="mt-3 space-y-2">
            <p>{{ t('payroll.hourly_evidence.overtime_days_hint') }}</p>
            <div v-for="(day, dayIndex) in segment.overtimeDays" :key="dayIndex" class="flex flex-wrap items-end gap-2">
              <label>{{ t('common.date') }}<DateInput v-model="day.date" :class="inputClass" /></label>
              <label>{{ t('payroll.hourly_evidence.hours') }}<input v-model="day.hours" :class="inputClass"></label>
              <button type="button" :class="btnOutlineSm('danger')" @click="segment.overtimeDays.splice(dayIndex, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('common.remove') }}</button>
            </div>
            <button type="button" :class="btnOutlineSm('neutral')" @click="segment.overtimeDays.push({ date: '', hours: '', original: null })"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.hourly_evidence.add_overtime_day') }}</button>
          </div>
          <label class="mt-3 flex items-center gap-2"><input v-model="segment.difficultyConfirmed" type="checkbox" :data-test="`hourly-evidence-difficulty-${index}`">{{ t('payroll.hourly_evidence.difficulty_confirmed') }}</label>
          <div v-if="segment.difficultyConfirmed" class="mt-2 space-y-2">
            <div v-for="(exposure, exposureIndex) in segment.exposures" :key="exposureIndex" class="flex flex-wrap items-end gap-2">
              <label>{{ t('payroll.hourly_evidence.hours') }}<input v-model="exposure.hours" :class="inputClass"></label>
              <label>{{ t('payroll.people.surcharge_policy.factors') }}<input v-model="exposure.factors" :class="inputClass" inputmode="numeric"></label>
              <button type="button" :class="btnOutlineSm('danger')" @click="segment.exposures.splice(exposureIndex, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('common.remove') }}</button>
            </div>
            <button type="button" :class="btnOutlineSm('neutral')" @click="segment.exposures.push({ hours: '', factors: '', original: null })"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.hourly_evidence.add_exposure') }}</button>
          </div>
        </div>
        <section class="space-y-2 rounded-md border border-neutral-200 p-3" data-test="hourly-evidence-holidays">
          <p v-if="isAutomatic('nonworked_holidays')" class="text-neutral-600" data-test="hourly-evidence-automatic-holidays">{{ automaticText('nonworked_holidays') }}</p>
          <label v-else class="flex items-center gap-2"><input v-model="holidaysConfirmed" type="checkbox" data-test="hourly-evidence-confirm-holidays">{{ t('payroll.hourly_evidence.confirm_nonworked') }}</label>
          <template v-if="holidaysConfirmed && !isAutomatic('nonworked_holidays')">
            <div v-for="(item, index) in holidays" :key="index" class="flex flex-wrap items-end gap-2" data-test="hourly-evidence-nonworked-row">
              <label>{{ t('common.date') }}<select v-model="item.date" :class="inputClass"><option value="">{{ t('payroll.hourly_evidence.choose_evidence') }}</option><option v-for="option in state.options.nonworked_holidays" :key="option.date" :value="option.date" :disabled="option.valid === false">{{ formatDate(option.date) }} · {{ formatPayrollDuration(option.available_duration_units) }}<template v-if="option.reason"> · {{ option.reason }}</template></option></select></label>
              <label>{{ t('payroll.hourly_evidence.hours') }}<input v-model="item.hours" :class="inputClass"></label>
              <button type="button" :class="btnOutlineSm('danger')" @click="holidays.splice(index, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('common.remove') }}</button>
            </div>
            <button type="button" :class="btnOutlineSm('neutral')" data-test="hourly-evidence-add-nonworked" @click="holidays.push({ date: '', hours: '', original: null })"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.hourly_evidence.add_nonworked') }}</button>
          </template>
        </section>
        <section class="space-y-2 rounded-md border border-neutral-200 p-3" data-test="hourly-evidence-holiday-grants">
          <p v-if="isAutomatic('holiday_time_off_grants')" class="text-neutral-600" data-test="hourly-evidence-automatic-grants">{{ automaticText('holiday_time_off_grants') }}</p>
          <label v-else class="flex items-center gap-2"><input v-model="grantsConfirmed" type="checkbox" data-test="hourly-evidence-confirm-grants">{{ t('payroll.hourly_evidence.confirm_grants') }}</label>
          <template v-if="grantsConfirmed && !isAutomatic('holiday_time_off_grants')">
            <div v-for="(item, index) in grants" :key="index" class="grid gap-2 rounded-md border border-neutral-200 p-2 sm:grid-cols-3" data-test="hourly-evidence-grant-row">
              <label>{{ t('payroll.hourly_evidence.entitlement') }}<select v-model="item.entitlement" :class="inputClass"><option value="">{{ t('payroll.hourly_evidence.choose_evidence') }}</option><option v-for="option in state.options.holiday_time_off_entitlements" :key="option.entitlement_id" :value="String(option.entitlement_id)" :disabled="option.valid === false">{{ option.work_date ? formatDate(option.work_date) : t('payroll.worked_hours.unknown') }} · {{ formatPayrollDuration(option.available_duration_units) }}<template v-if="option.due_on"> · {{ t('payroll.hourly_evidence.due_on', { date: formatDate(option.due_on) }) }}</template><template v-if="option.reason"> · {{ option.reason }}</template></option></select></label>
              <label>{{ t('payroll.hourly_evidence.absence') }}<select v-model="item.absence" :class="inputClass"><option value="">{{ t('payroll.hourly_evidence.choose_evidence') }}</option><option v-for="option in absenceOptions('holiday')" :key="absenceKey(option.absence_id, option.date)" :value="absenceKey(option.absence_id, option.date)" :disabled="option.valid === false">{{ formatDate(option.date) }} · {{ formatPayrollDuration(option.available_duration_units) }}<template v-if="option.reason"> · {{ option.reason }}</template></option></select></label>
              <label>{{ t('payroll.hourly_evidence.hours') }}<input v-model="item.hours" :class="inputClass"></label>
              <div class="flex flex-wrap gap-2"><button type="button" :class="btnOutlineSm('danger')" @click="grants.splice(index, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('common.remove') }}</button></div>
            </div>
            <button type="button" :class="btnOutlineSm('neutral')" data-test="hourly-evidence-add-grant" @click="grants.push({ entitlement: '', absence: '', hours: '', original: null })"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.hourly_evidence.add_grant') }}</button>
          </template>
        </section>
        <section class="space-y-2 rounded-md border border-neutral-200 p-3" data-test="hourly-evidence-overtime-grants">
          <p v-if="isAutomatic('overtime_time_off_allocations')" class="text-neutral-600" data-test="hourly-evidence-automatic-overtime">{{ automaticText('overtime_time_off_allocations') }}</p>
          <label v-else class="flex items-center gap-2"><input v-model="overtimeConfirmed" type="checkbox" data-test="hourly-evidence-confirm-overtime">{{ t('payroll.hourly_evidence.confirm_overtime') }}</label>
          <template v-if="overtimeConfirmed && !isAutomatic('overtime_time_off_allocations')">
            <div v-for="(item, index) in overtimeAllocations" :key="index" class="grid gap-2 rounded-md border border-neutral-200 p-2 sm:grid-cols-2" data-test="hourly-evidence-overtime-row">
              <label>{{ t('payroll.hourly_evidence.entitlement') }}<select v-model="item.workDate" :class="inputClass"><option value="">{{ t('payroll.hourly_evidence.choose_evidence') }}</option><option v-for="option in state.options.overtime_time_off_entitlements ?? []" :key="option.work_date" :value="option.work_date" :disabled="option.valid === false">{{ formatDate(option.work_date) }} · {{ formatPayrollDuration(option.available_duration_units) }}<template v-if="option.due_on"> · {{ t('payroll.hourly_evidence.due_on', { date: formatDate(option.due_on) }) }}</template><template v-if="option.reason"> · {{ option.reason }}</template></option></select></label>
              <label>{{ t('payroll.hourly_evidence.absence') }}<select v-model="item.absence" :class="inputClass"><option value="">{{ t('payroll.hourly_evidence.choose_evidence') }}</option><option v-for="option in absenceOptions('overtime')" :key="absenceKey(option.absence_id, option.date)" :value="absenceKey(option.absence_id, option.date)" :disabled="option.valid === false">{{ formatDate(option.date) }} · {{ formatPayrollDuration(option.available_duration_units) }}<template v-if="option.reason"> · {{ option.reason }}</template></option></select></label>
              <label>{{ t('payroll.hourly_evidence.hours') }}<input v-model="item.hours" :class="inputClass"></label>
              <label>{{ t('payroll.hourly_policy.agreement_reference') }}<input v-model="item.reference" :class="inputClass" maxlength="255"></label>
              <div class="flex flex-wrap gap-2"><button type="button" :class="btnOutlineSm('danger')" @click="overtimeAllocations.splice(index, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('common.remove') }}</button></div>
            </div>
            <button type="button" :class="btnOutlineSm('neutral')" data-test="hourly-evidence-add-overtime" @click="overtimeAllocations.push({ workDate: '', absence: '', hours: '', reference: '', original: null })"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.hourly_evidence.add_overtime_grant') }}</button>
          </template>
        </section>
        <label class="block">{{ t('payroll.worked_hours.reason') }}<input v-model="reason" :class="inputClass" data-test="hourly-evidence-reason" maxlength="500"></label>
        <p v-if="!reasonRequired" class="text-neutral-500" data-test="hourly-evidence-reason-optional">{{ t('payroll.hourly_evidence.reason_optional') }}</p>
        <div v-if="editable" class="flex flex-wrap gap-2"><button type="submit" :class="btnFilledSm('primary')" :disabled="saving || loading || !state.work_source_hash" data-test="hourly-evidence-save"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>{{ t('common.save') }}</button></div>
      </fieldset>
    </form>
    <button v-if="state" type="button" :class="[btnOutlineSm('neutral'), 'mt-3']" @click="historyOpen = !historyOpen"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.table" /></svg>{{ t('payroll.hourly_evidence.history') }}</button>
    <div v-if="historyOpen && state" class="mt-2 space-y-2">
      <p v-for="item in state.history" :key="item.id">{{ t('payroll.worked_hours.revision', { number: item.revision_no }) }} · {{ item.created_by_name ?? t('payroll.worked_hours.unknown') }} · {{ formatDateTime(item.created_at) }} · {{ item.reason ?? t('payroll.hourly_evidence.initial_revision') }}</p>
      <div class="flex flex-wrap gap-2"><button type="button" :class="btnOutlineSm('neutral')" :disabled="loading || state.history_offset === 0" @click="load(Math.max(0, state.history_offset - 20), true)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.chevronLeft" /></svg>{{ t('common.previous') }}</button><button type="button" :class="btnOutlineSm('neutral')" :disabled="loading || !state.history_has_more" @click="load(state.history_offset + 20, true)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.chevronRight" /></svg>{{ t('common.next') }}</button></div>
    </div>
  </section>
</template>
