<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { PayrollInput } from '@/api/payroll'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import { payrollInputListEditable } from '@/pages/payroll/payrollInputFilters'

/**
 * Akce jednoho mzdového vstupu — tatáž sada v tabulce, v mobilní kartě
 * i v rozbalené skupině, ať se tři kopie nemůžou rozejít.
 */
defineProps<{
  input: PayrollInput
  canWrite: boolean
  canApprove: boolean
  saving: boolean
}>()

const emit = defineEmits<{
  edit: [input: PayrollInput]
  cancel: [input: PayrollInput]
  approve: [input: PayrollInput]
  reverseBenefit: [input: PayrollInput]
}>()

const { t } = useI18n()
</script>

<template>
  <div class="flex flex-wrap gap-2">
    <button v-if="canWrite && payrollInputListEditable(input)" type="button" data-testid="payroll-input-edit" :class="[btnOutlineSm('neutral'), 'whitespace-nowrap']" @click="emit('edit', input)">
      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.edit" /></svg>{{ t('common.edit') }}
    </button>
    <button v-if="canWrite && (input.status === 'draft' || (canApprove && input.status === 'approved' && !input.benefit_basket))" type="button" data-testid="payroll-input-cancel" :class="[btnOutlineSm('danger'), 'whitespace-nowrap']" :disabled="saving" @click="emit('cancel', input)">
      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('payroll.components.inputs.cancel') }}
    </button>
    <button v-if="canApprove && input.status === 'draft'" type="button" data-testid="payroll-input-approve" :class="[btnOutlineSm('success'), 'whitespace-nowrap']" :disabled="saving" @click="emit('approve', input)">
      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.badgeCheck" /></svg>{{ t('payroll.components.inputs.approve') }}
    </button>
    <button v-if="canApprove && input.status === 'approved' && !!input.benefit_basket" type="button" data-testid="payroll-input-reverse-benefit" :class="[btnOutlineSm('warning'), 'whitespace-nowrap']" :disabled="saving" @click="emit('reverseBenefit', input)">
      <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.uturn" /></svg>{{ t('payroll.components.inputs.reverse_benefit') }}
    </button>
  </div>
</template>
