<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { propertyPaymentOperationsApi, type Charge, type ChargeDetail, type Debt } from '@/api/propertyPaymentOperations'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import DateInput from '@/components/ui/DateInput.vue'
import PagedCollection from './PagedCollection.vue'
import { useWriteFlow } from './flows'
import { COMPONENTS, buildDebtRegistration, emptyRegistration } from './partForms'
import { failureMessage, moneyText, partyNames } from './format'
import { boxClass, inputClass } from './ui'
const props = defineProps<{ supplier: number; unitId: number; canManage: boolean }>()
const emit = defineEmits<{ changed: [] }>()
const { t } = useI18n()
const state = ref(emptyRegistration())
const form = ref<HTMLFormElement | null>(null)
const loadError = ref('')
const reloadKey = ref(0)
let epoch = 0
const flow = useWriteFlow({
  supplier: () => props.supplier, validate: () => form.value?.reportValidity() ?? false, canWrite: () => props.canManage,
  build: () => buildDebtRegistration(state.value),
  write: (supplier, request, token) => propertyPaymentOperationsApi.registerDebt(supplier, request, token),
  onDone: () => { state.value = emptyRegistration(); reloadKey.value++; emit('changed') },
})
watch(() => [props.supplier, props.unitId], () => { epoch++; state.value = emptyRegistration(); flow.reset() })
async function choose(charge: Charge) {
  const ticket = ++epoch; const supplier = props.supplier
  state.value = emptyRegistration(); loadError.value = ''
  try {
    const detail = await propertyPaymentOperationsApi.detail<ChargeDetail>(supplier, 'charges', charge.id)
    if (ticket === epoch && supplier === props.supplier) state.value.charge = detail
  } catch (e) { if (ticket === epoch) loadError.value = failureMessage(e, t('propertyPayments.failed')) }
}
const registrable = (line: ChargeDetail['lines'][number]) => line.registered_debt_id === null && !line.amount_cents.startsWith('-') && line.amount_cents !== '0'
const actions = () => [{ key: 'register', label: t('propertyPayments.debtRegistration.submit'), icon: 'check', variant: 'primary', tier: 'primary', disabled: flow.busy.value || !props.canManage || !state.value.charge, run: () => { void flow.submit() } }] as ActionItem[]
</script>

<template>
  <section :class="boxClass" data-section="debt-registration">
    <h3 class="font-semibold">{{ t('propertyPayments.debtRegistration.title') }}</h3>
    <p class="text-sm text-neutral-500">{{ t('propertyPayments.debtRegistration.help') }}</p>
    <PagedCollection :supplier="supplier" collection="charges" :unit-id="unitId" :reload-key="reloadKey">
      <template #row="{ item }">
        <label class="flex flex-wrap items-center gap-2 border-t border-neutral-200 py-1 text-sm">
          <input type="radio" name="charge" data-field="charge-select" :checked="state.charge?.id === item.id" :disabled="flow.busy.value" @change="choose(item as Charge)">
          <span>{{ item.unit_label }} · {{ (item as Charge).accounting_date }} · {{ (item as Charge).status }}</span><span class="ml-auto">{{ moneyText((item as Charge).amount_cents, t('propertyPayments.unknown')) }}</span>
        </label>
      </template>
    </PagedCollection>
    <p v-if="loadError" role="alert" class="text-danger-600">{{ loadError }}</p>
    <form v-if="state.charge" ref="form" class="space-y-3" @submit.prevent="flow.submit">
      <fieldset :disabled="flow.busy.value || !canManage" class="space-y-3">
        <p class="text-sm">{{ t('propertyPayments.debtRegistration.parties') }}: {{ partyNames(state.charge.parties) }}</p>
        <div class="space-y-1"><p class="text-sm font-medium">{{ t('propertyPayments.debtRegistration.lines') }}</p><p class="text-xs text-neutral-500">{{ t('propertyPayments.debtRegistration.linesHelp') }}</p>
          <label v-for="line in state.charge.lines" :key="line.id" class="flex flex-wrap items-center gap-2 text-sm"><input v-model="state.lines" type="checkbox" :value="line.id" :disabled="!registrable(line)" data-field="principal-line"><span>{{ line.label }}</span><span class="ml-auto">{{ moneyText(line.amount_cents, t('propertyPayments.unknown')) }}</span></label></div>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.debtRegistration.titleRef') }}<input v-model="state.title_ref" required :class="inputClass" data-field="title-ref"></label>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.dueDate') }}<DateInput v-model="state.due_date" required :class="inputClass" data-field="debt-due" /></label>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.debt.basisLabel') }}<select v-model="state.basis" :class="inputClass" data-field="legal-basis"><option value="statutory">{{ t('propertyPayments.debt.basis.statutory') }}</option><option value="legal_act">{{ t('propertyPayments.debt.basis.legal_act') }}</option></select></label>
          <label class="flex min-w-0 flex-col gap-1 text-sm" v-if="state.basis === 'legal_act'">{{ t('propertyPayments.debtRegistration.legalActDate') }}<DateInput v-model="state.legal_act_date" required :class="inputClass" data-field="legal-act-date" /></label>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.debt.regimeLabel') }}<select v-model="state.regime" :class="inputClass" data-field="legal-regime"><option value="general">{{ t('propertyPayments.debt.regime.general') }}</option><option value="consumer_in_default">{{ t('propertyPayments.debt.regime.consumer_in_default') }}</option></select></label>
          <label class="sm:col-span-2 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.debtRegistration.legalEvidence') }}<input v-model="state.legal_evidence" required :class="inputClass" data-field="legal-evidence"></label>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.debt.security') }}<select v-model="state.security_kind" required :class="inputClass" data-field="security-kind"><option value="">{{ t('propertyPayments.choose') }}</option><option value="secured">{{ t('propertyPayments.security.secured') }}</option><option value="unsecured">{{ t('propertyPayments.security.unsecured') }}</option></select></label>
          <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.debtRegistration.securityEvidence') }}<input v-model="state.security_evidence" required :class="inputClass" data-field="security-evidence"></label>
        </div>
        <div class="space-y-2 rounded border border-neutral-200 p-3" data-section="damages">
          <label class="flex flex-wrap items-center gap-2"><input type="checkbox" :checked="state.damages_for !== null" data-field="damages-on" @change="state.damages_for = ($event.target as HTMLInputElement).checked ? 0 : null">{{ t('propertyPayments.debtRegistration.damagesOn') }}</label>
          <PagedCollection v-if="state.damages_for !== null" :supplier="supplier" collection="debts" :unit-id="unitId">
            <template #row="{ item }"><label class="flex flex-wrap items-center gap-2 border-t border-neutral-200 py-1 text-sm"><input type="radio" name="damages" data-field="damages-select" :checked="state.damages_for === item.id" @change="state.damages_for = item.id"><span>{{ item.unit_label }} · {{ (item as Debt).title_ref }}</span></label></template>
          </PagedCollection>
        </div>
        <div class="space-y-2 rounded border border-neutral-200 p-3" data-section="disposition">
          <label class="flex flex-wrap items-center gap-2"><input v-model="state.disposition_on" type="checkbox" data-field="disposition-on">{{ t('propertyPayments.debtRegistration.dispositionOn') }}</label>
          <template v-if="state.disposition_on">
            <p class="text-xs text-neutral-500">{{ t('propertyPayments.debtRegistration.dispositionHelp') }}</p>
            <div class="flex flex-wrap gap-3"><label v-for="c in COMPONENTS" :key="c" class="w-44 flex min-w-0 flex-col gap-1 text-sm">{{ t(`propertyPayments.component.${c}`) }}<input v-model="state.order[c]" required inputmode="numeric" :class="inputClass" data-field="component-order"></label></div>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.debtRegistration.debtorEvidence') }}<input v-model="state.debtor_evidence" required :class="inputClass" data-field="debtor-evidence"></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.debtRegistration.creditorConsent') }}<input v-model="state.creditor_consent" :class="inputClass" data-field="creditor-consent"></label>
            <label class="flex flex-wrap items-center gap-2"><input v-model="state.old_on" type="checkbox">{{ t('propertyPayments.debtRegistration.oldInterestOn') }}</label>
            <template v-if="state.old_on">
              <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.debtRegistration.oldInterestResolution') }}<select v-model="state.old_resolution" :class="inputClass"><option value="none_documented">{{ t('propertyPayments.debtRegistration.oldNone') }}</option><option value="separately_evidenced">{{ t('propertyPayments.debtRegistration.oldSeparate') }}</option></select></label>
              <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.evidence') }}<input v-model="state.old_evidence" required :class="inputClass"></label>
            </template>
          </template>
        </div>
      </fieldset>
      <p v-if="flow.error.value" role="alert" class="text-danger-600">{{ flow.error.value }}</p>
      <p v-if="flow.done.value" role="status" class="text-success-600">{{ t('propertyPayments.debtRegistration.done') }}</p>
      <ActionBar :actions="actions()" />
    </form>
  </section>
</template>
