<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyPaymentOperationsApi, type Correction, type Operation } from '@/api/propertyPaymentOperations'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import { useWriteFlow } from './flows'
import { FormError } from './format'
import { inputClass, subBoxClass } from './ui'
const props = defineProps<{ supplier: number; kind: 'operation' | 'correction'; row: Operation | Correction; canReverse: boolean }>()
const emit = defineEmits<{ changed: []; close: [] }>()
const { t } = useI18n()
const date = ref('')
const effectiveAt = ref('')
const reason = ref('')
const evidence = ref('')
const form = ref<HTMLFormElement | null>(null)
const flow = useWriteFlow({
  supplier: () => props.supplier, validate: () => form.value?.reportValidity() ?? false, canWrite: () => props.canReverse,
  build: () => {
    if (!date.value || !reason.value.trim() || (props.kind === 'correction' && (!effectiveAt.value || !evidence.value.trim()))) throw new FormError('reverse')
    return { expected_version: props.row.row_version, accounting_date: date.value, effective_at: effectiveAt.value, reason: reason.value, evidence: evidence.value }
  },
  write: (supplier, request, token) => props.kind === 'operation'
    ? propertyPaymentOperationsApi.reverseOperation(supplier, props.row.id, { expected_version: request.expected_version, accounting_date: request.accounting_date, reason: request.reason }, token)
    : propertyPaymentOperationsApi.reverseCorrection(supplier, props.row.id, request, token),
  onDone: () => { emit('changed'); emit('close') },
})
const actions = () => [{ key: 'reverse-submit', label: t('propertyPayments.reverse.submit'), icon: 'uturn', variant: 'warning', tier: 'primary', disabled: flow.busy.value || !props.canReverse, run: () => { void flow.submit() } }] as ActionItem[]
</script>

<template>
  <form ref="form" :class="subBoxClass" :data-section="`reverse-${kind}`" @submit.prevent="flow.submit">
    <fieldset :disabled="flow.busy.value || !canReverse" class="space-y-2">
      <p class="text-xs text-neutral-500">{{ t(kind === 'operation' ? 'propertyPayments.reverse.operationHelp' : 'propertyPayments.reverse.correctionHelp') }}</p>
      <div class="grid gap-3 sm:grid-cols-2">
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.accountingDate') }}<DateInput v-model="date" required :class="inputClass" data-field="reverse-date" /></label>
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.reason') }}<input v-model="reason" required :class="inputClass" data-field="reverse-reason"></label>
        <template v-if="kind === 'correction'">
          <div data-field="reverse-effective"><PropertyInstantInput v-model="effectiveAt" :label="t('propertyPayments.reverse.effectiveAt')" required /></div>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.evidence') }}<input v-model="evidence" required :class="inputClass" data-field="reverse-evidence"></label>
        </template>
      </div>
    </fieldset>
    <p v-if="flow.error.value" role="alert" class="text-danger-600">{{ flow.error.value }}</p>
    <ActionBar :actions="actions()" />
  </form>
</template>
