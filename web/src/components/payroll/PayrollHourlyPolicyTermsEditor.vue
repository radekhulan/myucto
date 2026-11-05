<script setup lang="ts">
import { reactive, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { PayrollHourlyPolicyTerms } from '@/api/payroll'
import { formatPayrollDuration, parsePayrollDurationDraft } from '@/pages/payroll/payrollDuration'
import { btnOutlineSm, ICONS } from '@/components/ui/buttonStyles'
import DateInput from '@/components/ui/DateInput.vue'

const props = withDefaults(defineProps<{ modelValue: PayrollHourlyPolicyTerms | null; disabled: boolean; allowOvertime?: boolean; allowPremiums?: boolean }>(), { allowOvertime: true, allowPremiums: true })
const emit = defineEmits<{ 'update:modelValue': [PayrollHourlyPolicyTerms | null]; valid: [boolean] }>()
const { t } = useI18n()
const initial = props.modelValue
const overtime = initial?.included_overtime
const draft = reactive({
  enabled: overtime != null,
  scope: overtime?.scope_kind ?? 'annual_hours',
  reference: overtime?.agreement_reference ?? '',
  year: String(overtime?.calendar_year ?? ''),
  limit: formatPayrollDuration(overtime?.annual_limit_duration_units ?? null),
  manager: overtime?.manager_proof_reference ?? '',
  opening: formatPayrollDuration(overtime?.opening_used_duration_units ?? null),
  asOf: overtime?.opening_as_of ?? '',
  openingReference: overtime?.opening_reference ?? '',
  premiums: (initial?.included_agreement_premiums ?? []).map(item => ({
    kind: item.kind, from: item.date_from, to: item.date_to,
    hours: formatPayrollDuration(item.scope_duration_units), rate: String(item.included_hourly_minor / 100),
    reference: item.agreement_reference, opening: formatPayrollDuration(item.opening_used_duration_units),
    openingReference: item.opening_reference, asOf: item.opening_as_of, original: item as PayrollHourlyPolicyTerms['included_agreement_premiums'][number] | null,
  })),
})
const inputClass = 'mt-1 w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm disabled:bg-neutral-100'
const premiumKinds = ['night', 'weekend', 'difficult_environment'] as const
function addPremium() {
  draft.premiums.push({ kind: 'night', from: '', to: '', hours: '', rate: '', reference: '', opening: '', openingReference: '', asOf: '', original: null })
}
function publish() {
  const included: PayrollHourlyPolicyTerms['included_agreement_premiums'] = []
  for (const item of draft.premiums) {
    const hours = parsePayrollDurationDraft(item.hours, item.original?.scope_duration_units)
    const opening = parsePayrollDurationDraft(item.opening, item.original?.opening_used_duration_units)
    const amount = /^(\d+)(?:[.,](\d{1,2}))?$/.exec(item.rate.trim())
    const rate = amount ? Number(amount[1]) * 100 + Number((amount[2] ?? '').padEnd(2, '0')) : null
    if (hours == null || hours <= 0 || opening == null || opening > hours || rate == null || !Number.isSafeInteger(rate) || rate <= 0 || !item.reference.trim() || !item.openingReference.trim() || !item.asOf || !item.from || !item.to || item.from > item.to) {
      emit('valid', false); return
    }
    included.push({ kind: item.kind, date_from: item.from, date_to: item.to, scope_duration_units: hours, included_hourly_minor: rate, agreement_reference: item.reference.trim(), opening_used_duration_units: opening, opening_reference: item.openingReference.trim(), opening_as_of: item.asOf })
  }
  let ot: PayrollHourlyPolicyTerms['included_overtime'] = null
  if (draft.enabled) {
    const limit = parsePayrollDurationDraft(draft.limit, overtime?.annual_limit_duration_units)
    const opening = parsePayrollDurationDraft(draft.opening, overtime?.opening_used_duration_units)
    const year = Number(draft.year)
    if (limit == null || limit <= 0 || opening == null || opening > limit || !/^\d{4}$/.test(draft.year) || !Number.isSafeInteger(year) || !draft.reference.trim() || !draft.openingReference.trim() || !draft.asOf || (draft.scope === 'manager_statutory' && !draft.manager.trim())) {
      emit('valid', false); return
    }
    ot = { agreement_kind: 'contract', agreement_reference: draft.reference.trim(), scope_kind: draft.scope, calendar_year: year, annual_limit_duration_units: limit, manager_proof_reference: draft.manager.trim() || null, opening_used_duration_units: opening, opening_as_of: draft.asOf, opening_reference: draft.openingReference.trim() }
  }
  emit('valid', true)
  emit('update:modelValue', ot == null && included.length === 0 ? null : { included_overtime: ot, included_agreement_premiums: included })
}
watch(draft, publish, { deep: true })
</script>

<template>
  <fieldset class="mt-3 space-y-3 rounded-md border border-neutral-200 p-3 text-xs" :disabled="disabled" data-test="hourly-policy-editor">
    <legend class="px-1 font-semibold">{{ t('payroll.hourly_policy.title') }}</legend>
    <p class="text-neutral-500">{{ t('payroll.hourly_policy.hint') }}</p>
    <label v-if="allowOvertime" class="flex items-center gap-2"><input v-model="draft.enabled" type="checkbox" data-test="hourly-policy-enabled">{{ t('payroll.hourly_policy.included_overtime') }}</label>
    <div v-if="draft.enabled" class="grid gap-3 sm:grid-cols-2">
      <label>{{ t('payroll.hourly_policy.contract_reference') }}<input v-model="draft.reference" :class="inputClass" data-test="hourly-policy-reference" maxlength="255"></label>
      <label>{{ t('payroll.hourly_policy.scope') }}<select v-model="draft.scope" :class="inputClass" data-test="hourly-policy-scope"><option value="annual_hours">{{ t('payroll.hourly_policy.annual_hours') }}</option><option value="manager_statutory">{{ t('payroll.hourly_policy.manager_statutory') }}</option></select></label>
      <label>{{ t('payroll.hourly_policy.year') }}<input v-model="draft.year" :class="inputClass" inputmode="numeric" data-test="hourly-policy-year"></label>
      <label>{{ t('payroll.hourly_policy.limit') }}<input v-model="draft.limit" :class="inputClass" data-test="hourly-policy-limit"></label>
      <label>{{ t('payroll.hourly_policy.manager_proof') }}<input v-model="draft.manager" :class="inputClass" data-test="hourly-policy-manager" maxlength="255"></label>
      <label>{{ t('payroll.hourly_policy.opening') }}<input v-model="draft.opening" :class="inputClass" data-test="hourly-policy-opening"></label>
      <label>{{ t('payroll.hourly_policy.opening_as_of') }}<DateInput v-model="draft.asOf" :class="inputClass" data-test="hourly-policy-as-of" /></label>
      <label>{{ t('payroll.hourly_policy.opening_reference') }}<input v-model="draft.openingReference" :class="inputClass" data-test="hourly-policy-opening-reference" maxlength="255"></label>
    </div>
    <div v-for="(item, index) in draft.premiums" :key="index" class="grid gap-3 rounded-md border border-neutral-200 p-3 sm:grid-cols-2" data-test="hourly-policy-premium">
      <label>{{ t('payroll.hourly_policy.premium_kind') }}<select v-model="item.kind" :class="inputClass"><option v-for="kind in premiumKinds" :key="kind" :value="kind">{{ t(`payroll.people.surcharge_policy.kinds.${kind}`) }}</option></select></label>
      <label>{{ t('payroll.hourly_policy.agreement_reference') }}<input v-model="item.reference" :class="inputClass" maxlength="255"></label>
      <label>{{ t('common.from') }}<DateInput v-model="item.from" :class="inputClass" /></label>
      <label>{{ t('common.to') }}<DateInput v-model="item.to" :class="inputClass" /></label>
      <label>{{ t('payroll.hourly_policy.limit') }}<input v-model="item.hours" :class="inputClass"></label>
      <label>{{ t('payroll.hourly_policy.included_rate') }}<input v-model="item.rate" :class="inputClass" inputmode="decimal"></label>
      <label>{{ t('payroll.hourly_policy.opening') }}<input v-model="item.opening" :class="inputClass"></label>
      <label>{{ t('payroll.hourly_policy.opening_reference') }}<input v-model="item.openingReference" :class="inputClass" maxlength="255"></label>
      <label>{{ t('payroll.hourly_policy.opening_as_of') }}<DateInput v-model="item.asOf" :class="inputClass" /></label>
      <div class="flex flex-wrap gap-2"><button type="button" :class="btnOutlineSm('danger')" @click="draft.premiums.splice(index, 1)"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.trash" /></svg>{{ t('common.remove') }}</button></div>
    </div>
    <div v-if="allowPremiums" class="flex flex-wrap gap-2"><button type="button" :class="btnOutlineSm('neutral')" data-test="hourly-policy-add-premium" @click="addPremium"><svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.plus" /></svg>{{ t('payroll.hourly_policy.add_premium') }}</button></div>
  </fieldset>
</template>
