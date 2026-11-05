<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { payrollApi, type PayrollManualTimeSegment, type PayrollManualTimeSummaryRevision, type PayrollWorkedTime } from '@/api/payroll'
import { apiErrorMessage } from '@/api/errors'
import { formatPayrollDuration, parsePayrollDuration } from '@/pages/payroll/payrollDuration'
import { formatDate, formatDateTime } from '@/composables/useFormat'
import { btnFilledSm, btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'

const props = defineProps<{
  employmentId: number
  fullName: string
  period: string
  value: PayrollWorkedTime
  closed: boolean
  canWrite: boolean
  canApprove: boolean
  canReopen: boolean
}>()
const emit = defineEmits<{ updated: []; close: [] }>()
const { t } = useI18n()
interface SegmentDraft {
  date_from: string
  date_to: string
  worked: string
  overtime: string
  days: string
  original: PayrollManualTimeSegment | null
}
const current = ref<PayrollWorkedTime>(props.value)
const segments = ref<SegmentDraft[]>([])
const segmentationChanged = ref(false)
const reason = ref('')
const replaceSource = ref(false)
const reopenReason = ref('')
const restoreReason = ref('')
const restoreSource = ref<'entries' | 'import_summary'>('entries')
const restoreConfirmed = ref(false)
const busy = ref(false)
const error = ref('')
const baseline = ref('')
const historyOpen = ref(false)
const historyLoading = ref(false)
const history = ref<PayrollManualTimeSummaryRevision[] | null>(null)
const historyTruncated = ref(false)
const historyOffset = ref(0)
const historyError = ref('')
const inputClass = 'mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm disabled:bg-neutral-100 disabled:text-neutral-500'
const editable = computed(() => props.canWrite && !props.closed && current.value.month?.status !== 'approved')
const replacementRequired = computed(() => current.value.month?.id != null && current.value.month.work_source !== 'manual_summary')
const approvalAvailable = computed(() => props.canApprove && !props.closed && current.value.month?.status === 'open' && current.value.month.work_source === 'manual_summary' && current.value.manual_summary !== null)
const reopenAvailable = computed(() => props.canReopen && !props.closed && current.value.month?.status === 'approved')
const dirty = computed(() => JSON.stringify([segments.value, reason.value, replaceSource.value]) !== baseline.value)
const attendanceLink = computed(() => ({ name: 'payroll-time', query: { period: props.period, employment: String(props.employmentId), confirm_jmhz: '1' } }))

function hydrate(value: PayrollWorkedTime): void {
  current.value = value
  const savedSegments = value.manual_summary?.segments ?? []
  const savedRangesValid = savedSegments.length > 0 && savedSegments.every(saved =>
    value.suggested_segments.some(range => saved.date_from >= range.date_from && saved.date_to <= range.date_to),
  )
  // Jemnější, stále platné členění se nesmí při otevření panelu slít ani
  // vyprázdnit. Změněné hranice podmínek vyžadují nové lidské rozdělení.
  segmentationChanged.value = savedSegments.length > 0 && !savedRangesValid && value.suggested_segments.length > 0
  const ranges = savedRangesValid || value.suggested_segments.length === 0 ? savedSegments : value.suggested_segments
  segments.value = ranges.map(range => {
    const saved = value.manual_summary?.segments.find(segment => segment.date_from === range.date_from && segment.date_to === range.date_to) ?? null
    return { ...range, worked: formatPayrollDuration(saved?.worked_duration_units ?? null), overtime: formatPayrollDuration(saved?.overtime_duration_units ?? null), days: saved?.worked_days == null ? '' : String(saved.worked_days), original: saved }
  })
  reason.value = ''
  replaceSource.value = false
  reopenReason.value = ''
  restoreReason.value = ''
  restoreConfirmed.value = false
  error.value = ''
  history.value = null
  historyOpen.value = false
  historyError.value = ''
  baseline.value = JSON.stringify([segments.value, reason.value, replaceSource.value])
}
hydrate(props.value)
watch(() => props.value, value => { if (!dirty.value && !busy.value) hydrate(value) })

// Cizí API může mít přesný smíšený zlomek, který nelze vyjádřit samotným
// H:MM ani třemi desetinnými místy. Beze změny zachováme původní jednotky.
function duration(raw: string, original: number | null | undefined): number | null {
  return original != null && raw === formatPayrollDuration(original) ? original : parsePayrollDuration(raw)
}

async function loadHistory(offset = 0): Promise<void> {
  if (historyLoading.value) return
  historyLoading.value = true
  historyError.value = ''
  try {
    const response = await payrollApi.timeHistory(props.employmentId, { from: props.period, to: props.period, limit: 1, manual_summary_offset: offset })
    const month = response.items.find(item => item.period === props.period)
    history.value = month?.manual_summary_history ?? []
    historyTruncated.value = month?.manual_summary_history_truncated === true
    historyOffset.value = month?.manual_summary_history_offset ?? offset
  } catch (failure) { historyError.value = apiErrorMessage(failure, t('payroll.worked_hours.history_failed')) }
  finally { historyLoading.value = false }
}
function toggleHistory(): void {
  historyOpen.value = !historyOpen.value
  if (historyOpen.value && history.value === null) void loadHistory()
}

async function save(): Promise<void> {
  if (!editable.value || busy.value) return
  error.value = ''
  if (reason.value.trim() === '') { error.value = t('payroll.worked_hours.reason_required'); return }
  if (replacementRequired.value && !replaceSource.value) { error.value = t('payroll.worked_hours.replace_required'); return }
  const payload: PayrollManualTimeSegment[] = []
  for (const segment of segments.value) {
    const worked = duration(segment.worked, segment.original?.worked_duration_units)
    const overtime = segment.overtime.trim() === '' ? null : duration(segment.overtime, segment.original?.overtime_duration_units)
    const days = segment.days.trim() === '' ? null : Number(segment.days)
    const span = (Date.parse(`${segment.date_to}T00:00:00Z`) - Date.parse(`${segment.date_from}T00:00:00Z`)) / 86400000 + 1
    if (worked === null || worked > span * 24 * 3000 || (segment.overtime.trim() !== '' && overtime === null) || (overtime !== null && worked !== null && overtime > worked)) { error.value = t('payroll.worked_hours.duration_invalid'); return }
    if (days !== null && (!/^\d+$/.test(segment.days.trim()) || !Number.isSafeInteger(days) || days > span || ((worked === 0) !== (days === 0)))) { error.value = t('payroll.worked_hours.days_invalid'); return }
    payload.push({ date_from: segment.date_from, date_to: segment.date_to, worked_duration_units: worked, overtime_duration_units: overtime, worked_days: days })
  }
  if (payload.length === 0) { error.value = t('payroll.worked_hours.segments_missing'); return }
  busy.value = true
  try {
    const result = await payrollApi.saveManualTimeSummary(props.period, {
      employment_id: props.employmentId, row_version: current.value.month?.row_version ?? 0,
      reason: reason.value.trim(), replace_source: replacementRequired.value && replaceSource.value, segments: payload,
    })
    hydrate({ ...current.value, month: { ...result.month, work_source: 'manual_summary' }, manual_summary: result.manual_summary, jmhz_confirmed: false,
      worked_duration_units: payload.reduce((sum, segment) => sum + segment.worked_duration_units, 0),
      overtime_duration_units: payload.some(segment => segment.overtime_duration_units === null) ? null : payload.reduce((sum, segment) => sum + (segment.overtime_duration_units ?? 0), 0),
      worked_days: payload.some(segment => segment.worked_days === null) ? null : payload.reduce((sum, segment) => sum + (segment.worked_days ?? 0), 0),
    })
    emit('updated')
  } catch (failure) { error.value = apiErrorMessage(failure, t('payroll.worked_hours.save_failed')) }
  finally { busy.value = false }
}

async function approve(): Promise<void> {
  if (!approvalAvailable.value || busy.value || dirty.value) return
  busy.value = true
  error.value = ''
  try {
    const month = await payrollApi.approveTimeMonth(props.period, { employment_id: props.employmentId, row_version: current.value.month!.row_version })
    hydrate({ ...current.value, month: { ...month, work_source: 'manual_summary' }, jmhz_confirmed: false })
    emit('updated')
  } catch (failure) { error.value = apiErrorMessage(failure, t('payroll.worked_hours.approve_failed')) }
  finally { busy.value = false }
}

async function restore(): Promise<void> {
  if (!editable.value || busy.value || !current.value.month || current.value.month.work_source === 'entries') return
  if (!restoreReason.value.trim()) { error.value = t('payroll.worked_hours.reason_required'); return }
  if (!restoreConfirmed.value) { error.value = t('payroll.worked_hours.replace_required'); return }
  busy.value = true
  error.value = ''
  try {
    const result = await payrollApi.restoreTimeWorkSource(props.period, {
      employment_id: props.employmentId, row_version: current.value.month.row_version,
      reason: restoreReason.value.trim(), replace_source: true, source: restoreSource.value,
    })
    hydrate({ ...current.value, month: { ...result.month, work_source: restoreSource.value }, manual_summary: null, worked_duration_units: null, overtime_duration_units: null, worked_days: null, jmhz_confirmed: false })
    emit('updated')
  } catch (failure) { error.value = apiErrorMessage(failure, t('payroll.worked_hours.save_failed')) }
  finally { busy.value = false }
}

async function reopen(): Promise<void> {
  if (!reopenAvailable.value || busy.value) return
  if (reopenReason.value.trim() === '') { error.value = t('payroll.worked_hours.reason_required'); return }
  busy.value = true
  error.value = ''
  try {
    const month = await payrollApi.reopenTimeMonth(props.period, { employment_id: props.employmentId, row_version: current.value.month!.row_version, reason: reopenReason.value.trim() })
    hydrate({ ...current.value, month: { ...month, work_source: current.value.month!.work_source }, jmhz_confirmed: false })
    emit('updated')
  } catch (failure) { error.value = apiErrorMessage(failure, t('payroll.worked_hours.reopen_failed')) }
  finally { busy.value = false }
}
</script>

<template>
  <section class="border-b border-neutral-200 bg-neutral-50 p-4" data-test="worked-hours-panel">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h3 class="font-semibold text-neutral-900">{{ t('payroll.worked_hours.title', { name: fullName }) }}</h3>
      <button type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" :disabled="busy" @click="emit('close')"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>{{ t('common.close') }}</button>
    </div>
    <p class="mt-2 text-xs text-neutral-600">{{ t('payroll.worked_hours.scope_hint') }}</p>
    <p class="mt-2 text-xs font-medium text-neutral-700" data-test="worked-hours-current">
      {{ t(!current.month?.id ? 'payroll.worked_hours.source_none' : current.month.work_source === 'manual_summary' ? 'payroll.worked_hours.source_manual' : current.month.work_source === 'import_summary' ? 'payroll.worked_hours.source_import' : 'payroll.worked_hours.source_attendance') }} ·
      {{ t(current.month?.status === 'approved' ? 'payroll.worked_hours.approved' : 'payroll.worked_hours.unapproved') }} ·
      {{ t('payroll.worked_hours.worked') }}: {{ current.worked_duration_units === null ? t('payroll.worked_hours.unknown') : formatPayrollDuration(current.worked_duration_units) }}
      <span v-if="current.month"> · {{ t('payroll.worked_hours.revision', { number: current.month.revision_no }) }}</span>
    </p>
    <p v-if="!current.jmhz_confirmed" class="mt-2 text-xs text-warning-800" data-test="worked-hours-jmhz-pending">{{ t('payroll.worked_hours.jmhz_pending_hint') }}</p>
    <RouterLink :to="attendanceLink" class="mt-1 inline-block text-xs font-medium text-payroll-700 underline" data-test="worked-hours-jmhz-link">{{ t('payroll.worked_hours.jmhz_link') }}</RouterLink>
    <p v-if="closed" class="mt-2 text-xs text-warning-800">{{ t('payroll.worked_hours.closed') }}</p>
    <p v-else-if="current.month?.status === 'approved'" class="mt-2 text-xs text-neutral-600">{{ t('payroll.worked_hours.reopen_hint') }}</p>
    <div v-if="!editable && current.manual_summary" class="mt-3 space-y-2" data-test="worked-hours-saved-segments">
      <div v-for="segment in current.manual_summary.segments" :key="`${segment.date_from}:${segment.date_to}`" class="rounded-lg border border-neutral-200 bg-surface p-3 text-xs text-neutral-700">
        <p class="font-medium">{{ formatDate(segment.date_from) }} — {{ formatDate(segment.date_to) }}</p>
        <p>{{ t('payroll.worked_hours.worked') }}: {{ formatPayrollDuration(segment.worked_duration_units) }}</p>
        <p>{{ t('payroll.worked_hours.overtime') }}: {{ segment.overtime_duration_units === null ? t('payroll.worked_hours.unknown') : formatPayrollDuration(segment.overtime_duration_units) }}</p>
        <p>{{ t('payroll.worked_hours.days') }}: {{ segment.worked_days ?? t('payroll.worked_hours.unknown') }}</p>
      </div>
      <p class="break-words">{{ t('payroll.worked_hours.reason') }}: {{ current.manual_summary.reason }}</p>
    </div>
    <div v-if="editable" class="mt-3 space-y-3">
      <p class="text-xs text-neutral-600">{{ t('payroll.worked_hours.input_hint') }}</p>
      <div v-if="segmentationChanged" class="rounded-lg border border-warning-200 bg-warning-50 p-3 text-xs text-warning-800" data-test="worked-hours-segmentation-warning">
        <p>{{ t('payroll.worked_hours.segmentation_changed') }}</p>
        <ul class="mt-2 space-y-1">
          <li v-for="segment in current.manual_summary?.segments ?? []" :key="`${segment.date_from}:${segment.date_to}`">{{ formatDate(segment.date_from) }} — {{ formatDate(segment.date_to) }}: {{ formatPayrollDuration(segment.worked_duration_units) }}</li>
        </ul>
      </div>

      <div v-for="(segment, index) in segments" :key="`${segment.date_from}:${segment.date_to}`" class="rounded-lg border border-neutral-200 bg-surface p-3" data-test="worked-hours-segment">
        <p class="text-xs font-medium text-neutral-700">{{ formatDate(segment.date_from) }} — {{ formatDate(segment.date_to) }}</p>
        <div class="mt-2 grid gap-3 sm:grid-cols-3">
          <label class="text-xs text-neutral-600">{{ t('payroll.worked_hours.worked') }}
            <input v-model="segment.worked" inputmode="decimal" :class="inputClass" :disabled="busy" :data-test="`worked-hours-duration-${index}`">
          </label>
          <label class="text-xs text-neutral-600">{{ t('payroll.worked_hours.overtime') }}
            <input v-model="segment.overtime" inputmode="decimal" :class="inputClass" :disabled="busy" :data-test="`worked-hours-overtime-${index}`">
          </label>
          <label class="text-xs text-neutral-600">{{ t('payroll.worked_hours.days') }}
            <input v-model="segment.days" inputmode="numeric" :class="inputClass" :disabled="busy" :data-test="`worked-hours-days-${index}`">
          </label>
        </div>
      </div>
      <label v-if="replacementRequired" class="flex items-start gap-2 text-xs text-warning-800"><input v-model="replaceSource" type="checkbox" class="mt-0.5 rounded border-neutral-300 text-warning-600" :disabled="busy" data-test="worked-hours-replace">{{ t('payroll.worked_hours.replace_hint') }}</label>
      <label class="block text-xs text-neutral-600">{{ t('payroll.worked_hours.reason') }}
        <input v-model="reason" maxlength="500" :class="inputClass" :disabled="busy" data-test="worked-hours-reason">
      </label>
      <button type="button" :class="[btnFilledSm('primary'), 'whitespace-nowrap']" :disabled="busy" data-test="worked-hours-save" @click="save"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>{{ t('payroll.worked_hours.save') }}</button>
    </div>
    <details v-if="editable && current.month && current.month.work_source !== 'entries'" class="mt-3 rounded-lg border border-warning-200 p-3" data-test="worked-hours-restore-panel">
      <summary class="cursor-pointer text-xs font-medium text-warning-800">{{ t('payroll.worked_hours.restore_title') }}</summary>
      <div class="mt-2 grid gap-3 sm:grid-cols-2">
        <label class="text-xs text-neutral-600">{{ t('payroll.worked_hours.restore_source') }}
          <select v-model="restoreSource" :class="inputClass" :disabled="busy" data-test="worked-hours-restore-source">
            <option v-for="source in (current.available_sources ?? ['entries']).filter(source => source !== current.month?.work_source)" :key="source" :value="source">{{ t(source === 'import_summary' ? 'payroll.worked_hours.source_import' : 'payroll.worked_hours.source_attendance') }}</option>
          </select>
        </label>
        <label class="text-xs text-neutral-600">{{ t('payroll.worked_hours.reason') }}<input v-model="restoreReason" maxlength="500" :class="inputClass" :disabled="busy" data-test="worked-hours-restore-reason"></label>
      </div>
      <label class="mt-2 flex items-start gap-2 text-xs text-warning-800"><input v-model="restoreConfirmed" type="checkbox" :disabled="busy" class="mt-0.5 rounded border-neutral-300 text-warning-600" data-test="worked-hours-restore-confirm">{{ t('payroll.worked_hours.restore_hint') }}</label>
      <button type="button" :class="[btnOutlineSm('warning'), 'mt-2 whitespace-nowrap']" :disabled="busy" data-test="worked-hours-restore" @click="restore"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.uturn" /></svg>{{ t('payroll.worked_hours.restore') }}</button>
    </details>
    <div v-if="approvalAvailable" class="mt-3 flex flex-wrap items-center gap-2">
      <button type="button" :class="[btnFilledSm('success'), 'whitespace-nowrap']" :disabled="busy || dirty" data-test="worked-hours-approve" @click="approve"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>{{ t('payroll.worked_hours.approve') }}</button>
      <p class="text-xs text-neutral-500">{{ t('payroll.worked_hours.approve_hint') }}</p>
    </div>
    <div v-if="reopenAvailable" class="mt-3 flex flex-wrap items-end gap-2">
      <label class="min-w-48 flex-1 text-xs text-neutral-600">{{ t('payroll.worked_hours.reopen_reason') }}<input v-model="reopenReason" maxlength="500" :class="inputClass" :disabled="busy" data-test="worked-hours-reopen-reason"></label>
      <button type="button" :class="[btnOutlineSm('warning'), 'whitespace-nowrap']" :disabled="busy" data-test="worked-hours-reopen" @click="reopen"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.uturn" /></svg>{{ t('payroll.worked_hours.reopen') }}</button>
    </div>
    <div class="mt-3 border-t border-neutral-200 pt-3">
      <button type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" :disabled="historyLoading" data-test="worked-hours-history-open" @click="toggleHistory"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.doc" /></svg>{{ t('payroll.worked_hours.history') }}</button>
      <div v-if="historyOpen" class="mt-2 space-y-2" data-test="worked-hours-history">
        <p v-if="historyLoading" class="text-xs text-neutral-500">{{ t('common.loading') }}</p>
        <p v-if="historyError" role="alert" class="text-xs text-danger-700">{{ historyError }} <button type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" @click="loadHistory(historyOffset)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.cycle" /></svg>{{ t('payroll.worked_hours.history_retry') }}</button></p>
        <p v-if="history?.length === 0" class="text-xs text-neutral-500">{{ t('payroll.worked_hours.history_empty') }}</p>
        <p v-if="historyTruncated" class="text-xs text-warning-800">{{ t('payroll.worked_hours.history_truncated') }}</p>
        <details v-for="revision in history ?? []" :key="revision.id" class="rounded-lg border border-neutral-200 bg-surface p-3 text-xs" data-test="worked-hours-history-revision">
          <summary class="cursor-pointer font-medium text-neutral-700">{{ t('payroll.worked_hours.revision', { number: revision.time_month_revision_no }) }} · {{ formatDateTime(revision.created_at) }} · {{ revision.created_by_name ?? t('payroll.worked_hours.unknown') }}</summary>
          <p class="mt-2 break-words">{{ t('payroll.worked_hours.reason') }}: {{ revision.reason }}</p>
          <div v-for="segment in revision.segments" :key="`${segment.date_from}:${segment.date_to}`" class="mt-2 border-t border-neutral-100 pt-2">
            <p>{{ formatDate(segment.date_from) }} — {{ formatDate(segment.date_to) }}</p>
            <p>{{ t('payroll.worked_hours.worked') }}: {{ formatPayrollDuration(segment.worked_duration_units) }}</p>
            <p>{{ t('payroll.worked_hours.overtime') }}: {{ segment.overtime_duration_units === null ? t('payroll.worked_hours.unknown') : formatPayrollDuration(segment.overtime_duration_units) }}</p>
            <p>{{ t('payroll.worked_hours.days') }}: {{ segment.worked_days ?? t('payroll.worked_hours.unknown') }}</p>
          </div>
        </details>
        <div v-if="historyOffset > 0 || historyTruncated" class="flex flex-wrap gap-2">
          <button v-if="historyOffset > 0" type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" :disabled="historyLoading" data-test="worked-hours-history-newer" @click="loadHistory(Math.max(0, historyOffset - 20))"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevronLeft" /></svg>{{ t('payroll.worked_hours.history_newer') }}</button>
          <button v-if="historyTruncated" type="button" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" :disabled="historyLoading" data-test="worked-hours-history-older" @click="loadHistory(historyOffset + 20)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.chevronRight" /></svg>{{ t('payroll.worked_hours.history_older') }}</button>
        </div>
      </div>
    </div>
    <p v-if="error" role="alert" class="mt-3 rounded-md border border-danger-200 bg-danger-50 p-3 text-xs text-danger-700" data-test="worked-hours-error">{{ error }}</p>
  </section>
</template>
