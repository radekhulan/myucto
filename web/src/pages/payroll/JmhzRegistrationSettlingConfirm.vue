<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { PayrollJmhzBulkDeferral, PayrollJmhzDeferralContext } from '@/api/payroll'
import { btnFilled, btnOutline, ICONS } from '@/components/ui/buttonStyles'
import JmhzBulkDefer from './JmhzBulkDefer.vue'

defineProps<{
  message: string
  busy?: boolean
  testId?: string
  deferral?: PayrollJmhzDeferralContext | null
  canWrite: boolean
}>()

const emit = defineEmits<{ confirm: [], cancel: [], deferred: [result: PayrollJmhzBulkDeferral] }>()
const { t } = useI18n()
</script>

<template>
  <div
    class="space-y-2 rounded-lg border border-warning-300 bg-warning-50 p-3 text-sm text-warning-900"
    :data-test="testId ?? 'jmhz-settling-confirm'"
    role="alert"
  >
    <p class="font-semibold">{{ t('payroll.jmhz_gate.settling.title') }}</p>
    <p>{{ message }}</p>
    <p class="text-xs text-warning-800">{{ t('payroll.jmhz_gate.settling.hint') }}</p>
    <div v-if="canWrite" class="flex flex-wrap items-center gap-2">
      <button
        type="button"
        :class="btnFilled('warning')"
        :disabled="busy"
        :data-test="`${testId ?? 'jmhz-settling-confirm'}-yes`"
        @click="emit('confirm')"
      >
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.check" /></svg>
        {{ busy ? t('common.loading') : t('payroll.jmhz_gate.settling.confirm') }}
      </button>
      <button type="button" :class="btnOutline('neutral')" @click="emit('cancel')">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path :d="ICONS.x" /></svg>
        {{ t('common.cancel') }}
      </button>
    </div>
    <JmhzBulkDefer
      v-if="deferral"
      :context="deferral"
      :can-write="canWrite"
      @deferred="emit('deferred', $event)"
    />
  </div>
</template>
