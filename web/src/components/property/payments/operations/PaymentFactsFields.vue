<script setup lang="ts">
import { watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { DebtDetail } from '@/api/propertyPaymentOperations'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PropertyInstantInput from '@/components/property/PropertyInstantInput.vue'
import PropertyPartyPicker from '@/components/property/PropertyPartyPicker.vue'
import PartyListInput from './PartyListInput.vue'
import { COMPONENTS, type PaymentFactsState } from './partForms'
import { debtLabel, moneyText } from './format'
import { inputClass, subBoxClass } from './ui'
const props = defineProps<{ supplier: number; state: PaymentFactsState; debts: number[]; details: Record<number, DebtDetail>; sourceDate?: string | null }>()
const { t } = useI18n()
const label = (id: number) => debtLabel(props.details[id], t('propertyPayments.debtLoading'))
watch(() => props.debts, debts => {
  const s = props.state
  s.choice_order = [...s.choice_order.filter(id => debts.includes(id)), ...debts.filter(id => !s.choice_order.includes(id))]
  s.reminders = s.reminders.filter(r => debts.includes(r.debt_id))
  for (const id of debts) {
    s.principal[id] ??= { on: false, evidence: '', amounts: {} }
    const per = s.accessory[id] ??= {}
    for (const component of COMPONENTS) per[component] ??= { on: false, evidence: '', amounts: {} }
  }
}, { immediate: true, deep: true })
function move(index: number, by: number) {
  const order = [...props.state.choice_order]
  const target = index + by
  if (target < 0 || target >= order.length) return
  ;[order[index], order[target]] = [order[target]!, order[index]!]
  props.state.choice_order = order
}
function addReminder() { props.state.reminders.push({ key: `reminder.${crypto.randomUUID()}`, debt_id: props.debts[0] ?? 0, evidence: '', recipients: [null], delivered_at: '' }) }
function distribution(kind: 'principal' | 'accessory', id: number, component?: (typeof COMPONENTS)[number]) {
  return kind === 'principal' ? props.state.principal[id]! : props.state.accessory[id]![component!]!
}
const componentLabel = (c: string) => t(`propertyPayments.component.${c}`)
</script>

<template>
  <div class="space-y-3" data-section="payment-facts">
    <div class="grid gap-3 sm:grid-cols-2">
      <div class="space-y-1" data-field="payer"><span class="text-sm font-medium">{{ t('propertyPayments.facts.payer') }}</span><PropertyPartyPicker :supplier-id="supplier" :model-value="state.payer_id" @update:model-value="state.payer_id = $event" /></div>
      <div class="space-y-1" data-field="debtor"><span class="text-sm font-medium">{{ t('propertyPayments.facts.debtor') }}</span><PropertyPartyPicker :supplier-id="supplier" :model-value="state.debtor_id" @update:model-value="state.debtor_id = $event" /></div>
      <div class="sm:col-span-2" data-field="received-at"><PropertyInstantInput v-model="state.received_at" :label="t('propertyPayments.facts.receivedAt')" required /><p class="text-xs text-neutral-500">{{ t('propertyPayments.facts.receivedHelp') }}</p></div>
    </div>
    <div v-if="state.payer_id && state.debtor_id && state.payer_id !== state.debtor_id" :class="subBoxClass" data-section="third-party">
      <p class="text-sm font-medium">{{ t('propertyPayments.facts.thirdTitle') }}</p><p class="text-xs text-neutral-500">{{ t('propertyPayments.facts.thirdHelp') }}</p>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.facts.thirdBasis') }}<select v-model="state.third_basis" required :class="inputClass" data-field="third-basis"><option value="">{{ t('propertyPayments.choose') }}</option><option v-for="b in ['debtor_consent', 'guarantor', 'security_provider']" :key="b" :value="b">{{ t(`propertyPayments.facts.basis.${b}`) }}</option></select></label>
      <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.evidence') }}<input v-model="state.third_evidence" required :class="inputClass" data-field="third-evidence"></label>
    </div>
    <div v-if="debts.length > 1" :class="subBoxClass" data-section="choice">
      <label class="flex flex-wrap items-center gap-2"><input v-model="state.choice_on" type="checkbox" data-field="choice-on">{{ t('propertyPayments.facts.choiceOn') }}</label>
      <template v-if="state.choice_on">
        <p class="text-xs text-neutral-500">{{ t('propertyPayments.facts.choiceHelp') }}</p>
        <div v-for="(id, index) in state.choice_order" :key="id" class="flex flex-wrap items-center gap-2 text-sm"><span class="w-6">{{ index + 1 }}.</span><span class="min-w-0 flex-1">{{ label(id) }}</span><PropertyButton icon="chevron" :disabled="index === 0" @click="move(index, -1)">{{ t('propertyPayments.facts.earlier') }}</PropertyButton><PropertyButton icon="chevron" :disabled="index === state.choice_order.length - 1" @click="move(index, 1)">{{ t('propertyPayments.facts.later') }}</PropertyButton></div>
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.evidence') }}<input v-model="state.choice_evidence" required :class="inputClass" data-field="choice-evidence"></label>
      </template>
    </div>
    <div v-if="debts.length" :class="subBoxClass" data-section="security">
      <label class="flex flex-wrap items-center gap-2"><input v-model="state.security_on" type="checkbox" data-field="security-on">{{ t('propertyPayments.facts.securityOn') }}</label>
      <template v-if="state.security_on">
        <p class="text-xs text-neutral-500">{{ t('propertyPayments.facts.securityHelp') }}</p>
        <div v-for="id in debts" :key="id" class="flex flex-wrap items-end gap-2 border-t border-neutral-200 pt-2">
          <span class="min-w-60 flex-1 text-sm">{{ label(id) }}</span>
          <label class="w-28 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.facts.tier') }}<input v-model="state.tier[id]" required inputmode="numeric" :class="inputClass" data-field="security-tier"></label>
          <label class="w-full sm:w-40 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.facts.securityKind') }}<select v-model="state.fact_kind[id]" required :class="inputClass" data-field="security-kind"><option value="">{{ t('propertyPayments.choose') }}</option><option value="secured">{{ t('propertyPayments.security.secured') }}</option><option value="unsecured">{{ t('propertyPayments.security.unsecured') }}</option></select></label>
          <label class="min-w-48 flex-1 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.evidence') }}<input v-model="state.fact_evidence[id]" required :class="inputClass" data-field="security-fact-evidence"></label>
        </div>
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.facts.securityEvidence') }}<input v-model="state.security_evidence" required :class="inputClass" data-field="security-evidence"></label>
      </template>
    </div>
    <div v-if="debts.length" :class="subBoxClass" data-section="reminders">
      <p class="text-sm font-medium">{{ t('propertyPayments.facts.remindersTitle') }}</p>
      <div v-for="(reminder, index) in state.reminders" :key="reminder.key" class="space-y-2 border-t border-neutral-200 pt-2">
        <div class="flex flex-wrap items-end gap-2">
          <label class="min-w-60 flex-1 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.facts.reminderDebt') }}<select v-model="reminder.debt_id" required :class="inputClass"><option v-for="id in debts" :key="id" :value="id">{{ label(id) }}</option></select></label>
          <label class="min-w-48 flex-1 flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.evidence') }}<input v-model="reminder.evidence" required :class="inputClass" data-field="reminder-evidence"></label>
          <PropertyButton icon="trash" variant="danger" @click="state.reminders.splice(index, 1)">{{ t('propertyPayments.remove') }}</PropertyButton>
        </div>
        <PropertyInstantInput v-model="reminder.delivered_at" :label="t('propertyPayments.facts.reminderDelivered')" required />
        <div class="space-y-1"><span class="text-sm font-medium">{{ t('propertyPayments.facts.reminderRecipients') }}</span><PartyListInput v-model="reminder.recipients" :supplier="supplier" /></div>
      </div>
      <PropertyButton icon="plus" @click="addReminder">{{ t('propertyPayments.facts.addReminder') }}</PropertyButton>
    </div>
    <div v-for="id in debts" :key="`dist.${id}`" :class="subBoxClass" data-section="distribution">
      <p class="text-sm font-medium">{{ label(id) }}</p>
      <template v-if="details[id]">
        <div v-if="details[id]!.lines.length" class="space-y-2">
          <label class="flex flex-wrap items-center gap-2"><input v-model="distribution('principal', id).on" type="checkbox" data-field="principal-on">{{ t('propertyPayments.facts.principalOn') }}</label>
          <template v-if="distribution('principal', id).on">
            <label v-for="line in details[id]!.lines" :key="line.id" class="flex min-w-0 flex-wrap items-center gap-2 text-sm"><span class="min-w-60 flex-1">{{ line.label }} ({{ t('propertyPayments.remainingDebt') }}: {{ moneyText(line.remaining_cents, t('propertyPayments.unknown')) }})</span><input v-model="distribution('principal', id).amounts[line.id]" inputmode="decimal" class="h-9 w-36 rounded-md border border-neutral-300 bg-surface px-2 text-sm" data-field="principal-amount"></label>
            <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.evidence') }}<input v-model="distribution('principal', id).evidence" required :class="inputClass" data-field="principal-evidence"></label>
          </template>
        </div>
        <template v-for="component in COMPONENTS.filter(c => details[id]!.accessories.some(a => a.component === c))" :key="component">
          <div class="space-y-2">
            <label class="flex flex-wrap items-center gap-2"><input v-model="distribution('accessory', id, component).on" type="checkbox" data-field="accessory-on">{{ t('propertyPayments.facts.accessoryOn', { component: componentLabel(component) }) }}</label>
            <template v-if="distribution('accessory', id, component).on">
              <label v-for="part in details[id]!.accessories.filter(a => a.component === component)" :key="part.id" class="flex min-w-0 flex-wrap items-center gap-2 text-sm"><span class="min-w-60 flex-1">{{ part.label }} ({{ t('propertyPayments.remainingDebt') }}: {{ moneyText(part.remaining_cents, t('propertyPayments.unknown')) }})</span><input v-model="distribution('accessory', id, component).amounts[part.id]" inputmode="decimal" class="h-9 w-36 rounded-md border border-neutral-300 bg-surface px-2 text-sm" data-field="accessory-amount"></label>
              <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.evidence') }}<input v-model="distribution('accessory', id, component).evidence" required :class="inputClass" data-field="accessory-evidence"></label>
            </template>
          </div>
        </template>
      </template>
    </div>
  </div>
</template>
