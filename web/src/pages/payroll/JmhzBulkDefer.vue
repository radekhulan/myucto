<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { payrollApi, type PayrollJmhzBulkDeferral, type PayrollJmhzDeferralContext } from '@/api/payroll'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import { jmhzErrorMessage } from './jmhzBlockerRemediation'

const props = defineProps<{
  context: PayrollJmhzDeferralContext
  canWrite: boolean
}>()

const emit = defineEmits<{ deferred: [result: PayrollJmhzBulkDeferral] }>()
const { t, te, locale } = useI18n()

const open = ref(false)
const reason = ref('')
const showValidation = ref(false)
const busy = ref(false)
const error = ref('')
const done = ref<PayrollJmhzBulkDeferral | null>(null)

const reasonValid = computed(() => reason.value.trim().length >= 3)

async function confirm() {
  if (!props.canWrite || busy.value) return
  if (!reasonValid.value) {
    showValidation.value = true
    return
  }
  busy.value = true
  error.value = ''
  try {
    const result = await payrollApi.deferJmhzEmployments({
      environment: props.context.environment,
      preparationId: props.context.preparation_id,
      officeId: props.context.office_id,
      employmentIds: props.context.employment_ids,
      reason: reason.value.trim(),
    })
    done.value = result
    open.value = false
    emit('deferred', result)
  } catch (exception) {
    error.value = jmhzErrorMessage(t, te, locale.value, exception, 'payroll.jmhz_gate.deferral.defer_failed')
  } finally {
    busy.value = false
  }
}
</script>

<template>
  <div class="rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-900" data-test="jmhz-bulk-defer">
    <p>{{ t('payroll.jmhz_gate.deferral.bulk_hint', { count: context.count }) }}</p>
    <p v-if="(context.firm_blocker_count ?? 0) > 0" class="mt-1 text-xs text-warning-800">
      {{ t('payroll.jmhz_gate.deferral.bulk_firm_left', { count: context.firm_blocker_count }) }}
    </p>
    <p
      v-if="done"
      class="mt-2 rounded-lg border border-success-500/30 bg-success-50 p-2 text-success-700"
      role="status"
      data-test="jmhz-bulk-defer-done"
    >
      {{ t('payroll.jmhz_gate.deferral.bulk_done', { count: done.people.length }) }}
    </p>
    <button
      v-if="canWrite && !open && !done"
      type="button"
      :class="[btnOutline('warning'), 'mt-2']"
      data-test="jmhz-bulk-defer-open"
      @click="open = true"
    >
      <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
        <path :d="ICONS.pause" />
      </svg>
      {{ t('payroll.jmhz_gate.deferral.bulk', { count: context.count }) }}
    </button>
    <div v-if="open" class="mt-2" data-test="jmhz-bulk-defer-form">
      <p class="text-xs">{{ t('payroll.jmhz_gate.deferral.form_obligation') }}</p>
      <label class="mt-2 block text-xs font-medium" for="jmhz-bulk-defer-reason">
        {{ t('payroll.jmhz_gate.deferral.reason_label') }}
      </label>
      <textarea
        id="jmhz-bulk-defer-reason"
        v-model="reason"
        rows="2"
        maxlength="500"
        class="mt-1 w-full rounded-lg border border-neutral-300 bg-surface px-2 py-1 text-sm text-neutral-900"
        :placeholder="t('payroll.jmhz_gate.deferral.reason_placeholder')"
        data-test="jmhz-bulk-defer-reason"
      />
      <p v-if="showValidation && !reasonValid" class="mt-1 text-xs text-danger-700">
        {{ t('payroll.jmhz_gate.deferral.reason_required') }}
      </p>
      <div class="mt-2 flex flex-wrap gap-2">
        <button
          type="button"
          :class="btnFilled('warning')"
          :disabled="busy"
          data-test="jmhz-bulk-defer-confirm"
          @click="confirm"
        >
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.check" />
          </svg>
          {{ busy ? t('common.loading') : t('payroll.jmhz_gate.deferral.bulk_confirm', { count: context.count }) }}
        </button>
        <button type="button" :class="btnOutline('neutral')" @click="open = false">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path :d="ICONS.x" />
          </svg>
          {{ t('common.cancel') }}
        </button>
      </div>
    </div>
    <p v-if="error" class="mt-2 rounded-lg border border-danger-500/30 bg-danger-50 p-2 text-danger-700" role="alert">
      {{ error }}
    </p>
  </div>
</template>
