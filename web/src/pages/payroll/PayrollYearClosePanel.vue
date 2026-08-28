<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollApi, type PayrollYearCloseBlocker, type PayrollYearCloseStatusResponse } from '@/api/payroll'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { formatDateTime } from '@/composables/useFormat'

const props = defineProps<{ initialYear: number }>()
const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const year = ref(props.initialYear)
const data = ref<PayrollYearCloseStatusResponse | null>(null)
const loading = ref(false)
const saving = ref(false)
const reason = ref('')
const loadError = ref('')
let loadSequence = 0

const canApprove = computed(() => auth.canWrite('payroll.approve'))
const canReopen = computed(() => auth.canWrite('payroll.reopen'))
const closed = computed(() => data.value?.closure.status === 'closed')
const blockers = computed(() => data.value?.blockers ?? [])

function blockerText(blocker: PayrollYearCloseBlocker): string {
  if (blocker.code === 'missing_months') {
    return t('payroll.year_close.blocker.missing_months', { months: blocker.months?.join(', ') ?? '' })
  }
  if (blocker.code === 'schema_unavailable') {
    return t('payroll.year_close.blocker.schema_unavailable')
  }
  return t(`payroll.year_close.blocker.${blocker.code}`, { count: blocker.count ?? 0 })
}

async function load(): Promise<void> {
  const sequence = ++loadSequence
  loading.value = true
  loadError.value = ''
  try {
    const response = await payrollApi.yearCloseStatus(year.value)
    if (sequence === loadSequence) data.value = response
  } catch {
    if (sequence === loadSequence) {
      data.value = null
      loadError.value = t('payroll.year_close.load_failed')
    }
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}

async function close(): Promise<void> {
  if (!data.value || blockers.value.length > 0 || !window.confirm(t('payroll.year_close.close_confirm', { year: year.value }))) return
  saving.value = true
  try {
    data.value.closure = await payrollApi.closeYear(year.value, data.value.closure.row_version)
    data.value.blockers = []
    toast.success(t('payroll.year_close.closed'))
  } catch (error: any) {
    if (error?.response?.data?.error?.code === 'year_close_blocked') {
      data.value.blockers = error.response.data.error.blockers ?? []
    }
    toast.error(error?.response?.data?.error?.message || t('payroll.year_close.save_failed'))
    await load()
  } finally {
    saving.value = false
  }
}

async function reopen(): Promise<void> {
  if (!data.value || reason.value.trim().length < 10) return
  saving.value = true
  try {
    data.value.closure = await payrollApi.reopenYear(year.value, data.value.closure.row_version, reason.value.trim())
    reason.value = ''
    toast.success(t('payroll.year_close.reopened'))
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('payroll.year_close.save_failed'))
    await load()
  } finally {
    saving.value = false
  }
}

watch(year, () => void load())
onMounted(load)
</script>

<template>
  <section class="rounded-xl border border-neutral-200 bg-surface p-4 shadow-sm sm:p-6" data-test="payroll-year-close">
    <div class="flex flex-wrap items-start justify-between gap-3">
      <div>
        <h2 class="text-lg font-semibold text-neutral-900">{{ t('payroll.year_close.title') }}</h2>
        <p class="mt-1 max-w-3xl text-sm text-neutral-500">{{ t('payroll.year_close.description') }}</p>
      </div>
      <label class="text-sm text-neutral-600">
        <span class="mb-1 block text-xs font-medium">{{ t('payroll.year_close.year') }}</span>
        <input v-model.number="year" type="number" min="2000" max="2200" class="h-9 w-28 rounded-md border border-neutral-300 bg-surface px-3 text-sm text-neutral-900">
      </label>
    </div>

    <div v-if="loading" class="mt-4 h-16 animate-pulse rounded-lg bg-neutral-100" />
    <p v-else-if="loadError" class="mt-4 rounded-lg border border-danger-500/30 bg-danger-50 p-3 text-sm text-danger-700" role="alert">{{ loadError }}</p>
    <template v-else-if="data">
      <div class="mt-4 flex flex-wrap items-center gap-3">
        <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="closed ? 'bg-success-50 text-success-700' : 'bg-warning-50 text-warning-800'">
          {{ t(`payroll.year_close.status.${data.closure.status}`) }}
        </span>
        <span v-if="closed && data.closure.closed_at" class="text-xs text-neutral-500">
          {{ t('payroll.year_close.closed_at', { date: formatDateTime(data.closure.closed_at) }) }}
        </span>
      </div>

      <div v-if="!closed && blockers.length > 0" class="mt-4 rounded-lg border border-warning-500/40 bg-warning-50 p-3">
        <p class="text-sm font-medium text-warning-900">{{ t('payroll.year_close.blockers_title') }}</p>
        <ul class="mt-2 space-y-1 text-sm text-warning-800">
          <li v-for="blocker in blockers" :key="blocker.code">{{ blockerText(blocker) }}</li>
        </ul>
      </div>

      <div v-if="(!closed && canApprove) || (closed && canReopen)" class="mt-4 flex flex-wrap items-end gap-3">
        <template v-if="!closed">
          <button :class="btnFilled('success')" :disabled="saving || blockers.length > 0" @click="close">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.checkCircle" /></svg>
            {{ saving ? t('common.saving') : t('payroll.year_close.close') }}
          </button>
          <span v-if="blockers.length > 0" class="text-xs text-neutral-500">{{ t('payroll.year_close.blocked_hint') }}</span>
        </template>
        <template v-else>
          <label class="min-w-64 flex-1 text-sm text-neutral-700">
            <span class="mb-1 block text-xs font-medium">{{ t('payroll.year_close.reopen_reason') }}</span>
            <input v-model="reason" type="text" :placeholder="t('payroll.year_close.reopen_reason_placeholder')" class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" maxlength="1000">
          </label>
          <button :class="btnOutline('warning')" :disabled="saving || reason.trim().length < 10" @click="reopen">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.uturn" /></svg>
            {{ saving ? t('common.saving') : t('payroll.year_close.reopen') }}
          </button>
        </template>
      </div>
    </template>
  </section>
</template>
