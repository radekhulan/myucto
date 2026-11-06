<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { BankProposalOverrides, BankProposalPlan, BankProposalSignal } from '@/api/propertyPayments'
import type { PropertyObject } from '@/api/propertyRegistry'
import PropertyButton from '@/components/property/PropertyButton.vue'
import PropertyPartyPicker from '@/components/property/PropertyPartyPicker.vue'
import { paymentDecimal } from './paymentWorkflowForm'
import { propertyUnitLabel } from './operations/format'
import { propertyInput as inputClass } from '@/components/property/propertyFormStyles'

const props = defineProps<{ plan: BankProposalPlan; units: PropertyObject[]; busy: boolean; supplier?: number }>()
const emit = defineEmits<{ replan: [overrides: BankProposalOverrides]; close: [] }>()
const { t } = useI18n()

const REASONS: Record<string, string> = {
  no_identifier_match: 'propertyPayments.bankProposal.reasons.no_identifier_match',
  multiple_units: 'propertyPayments.bankProposal.reasons.multiple_units',
  weak_signal: 'propertyPayments.bankProposal.reasons.weak_signal',
  ownership_review_required: 'propertyPayments.bankProposal.reasons.ownership_review_required',
  debtor_choice_required: 'propertyPayments.bankProposal.reasons.debtor_choice_required',
  payer_not_proven: 'propertyPayments.bankProposal.reasons.payer_not_proven',
  unit_chosen_manually: 'propertyPayments.bankProposal.reasons.unit_chosen_manually',
}
const SIGNALS: Record<BankProposalSignal['signal'], string> = {
  vs: 'propertyPayments.bankProposal.signals.vs',
  ss: 'propertyPayments.bankProposal.signals.ss',
  sipo_link: 'propertyPayments.bankProposal.signals.sipo_link',
  account: 'propertyPayments.bankProposal.signals.account',
  payer_account: 'propertyPayments.bankProposal.signals.payer_account',
  text: 'propertyPayments.bankProposal.signals.text',
}
const REVIEWS: Record<string, string> = {
  unit_required: 'propertyPayments.bankProposal.review.unit_required',
  debtor_required: 'propertyPayments.bankProposal.review.debtor_required',
  no_open_debts: 'propertyPayments.bankProposal.review.no_open_debts',
  legal_review: 'propertyPayments.bankProposal.review.legal_review',
  principal_distribution_required: 'propertyPayments.bankProposal.review.principal_distribution_required',
  debt_inventory_incomplete: 'propertyPayments.bankProposal.review.debt_inventory_incomplete',
  liability_review: 'propertyPayments.bankProposal.review.liability_review',
}
const LEGAL: Record<string, string> = {
  ambiguous_priority: 'propertyPayments.bankProposal.legal.ambiguous_priority',
  security_comparison_required: 'propertyPayments.bankProposal.legal.security_comparison_required',
  third_party_unproven: 'propertyPayments.bankProposal.legal.third_party_unproven',
  debtor_unproven: 'propertyPayments.bankProposal.legal.debtor_unproven',
  underlying_claim_unresolved: 'propertyPayments.bankProposal.legal.underlying_claim_unresolved',
}

const proposal = computed(() => props.plan.status === 'proposal' ? props.plan : null)
const resolution = computed(() => proposal.value?.resolution ?? null)
const unitName = (id: number | null) => {
  const unit = id === null ? undefined : props.units.find(u => u.id === id)
  return unit ? propertyUnitLabel(unit, number => t('propertyPayments.unitNumber', { number })) : t('propertyPayments.bankProposal.noUnit')
}
const statusClass = computed(() => ({ identified: 'bg-success-50 text-success-600', review_required: 'bg-warning-50 text-warning-600', unidentified: 'bg-neutral-100 text-neutral-600' })[resolution.value?.status ?? 'unidentified'])
const reviewText = computed(() => {
  const review = proposal.value?.review
  if (!review) return ''
  const code = review.code.replace(/^property_payment_/, '')
  if (code === 'no_open_debts') return t(REVIEWS.no_open_debts!, { amount: paymentDecimal(review.amount_cents ?? '0') })
  if (code === 'legal_review') {
    const reason = String(review.details?.reason ?? '')
    if (reason === 'credit_entitlement_required') return t('propertyPayments.bankProposal.legal.credit_entitlement_required')
    return t(REVIEWS.legal_review!, { reason: LEGAL[reason] ? t(LEGAL[reason]!) : t('propertyPayments.bankProposal.legal.other') })
  }
  return t(REVIEWS[code] ?? 'propertyPayments.bankProposal.review.other')
})
const needsUnit = computed(() => !!resolution.value && (resolution.value.unit_id === null || resolution.value.status !== 'identified'))
const needsDebtor = computed(() => !!resolution.value && (resolution.value.debtor_id === null || resolution.value.reasons.includes('debtor_choice_required')))
const unitChoice = ref(0)
const debtorChoice = ref<number | null>(null)
watch(resolution, value => { unitChoice.value = value?.unit_id ?? value?.candidates[0]?.unit_id ?? 0; debtorChoice.value = value?.debtor_id ?? null }, { immediate: true })
const candidateIds = computed(() => new Set(resolution.value?.candidates.map(c => c.unit_id) ?? []))
const otherUnits = computed(() => props.units.filter(u => !candidateIds.value.has(u.id)))
</script>

<template>
  <section class="space-y-3 rounded-lg border border-warning-300 p-4" data-section="bank-proposal" aria-live="polite">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h2 class="font-semibold">{{ t('propertyPayments.bankProposal.title') }}</h2>
      <PropertyButton icon="x" :disabled="busy" data-action="bank-proposal-close" @click="emit('close')">{{ t('propertyPayments.bankProposal.close') }}</PropertyButton>
    </div>
    <p v-if="plan.status === 'used'" class="text-sm" data-flag="proposal-used">
      {{ plan.link.workflow_id ? t('propertyPayments.bankProposal.usedWorkflow', { id: plan.link.workflow_id }) : t('propertyPayments.bankProposal.used') }}
    </p>
    <template v-else-if="resolution">
      <div class="flex flex-wrap items-center gap-3 text-sm">
        <span class="rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap" :class="statusClass" data-status>{{ t(`propertyPayments.bankProposal.status.${resolution.status}`) }}</span>
        <span>{{ t('propertyPayments.unit') }}: <strong>{{ unitName(resolution.unit_id) }}</strong></span>
        <span class="text-neutral-500">{{ t('propertyPayments.bankProposal.score', { score: resolution.score }) }}</span>
      </div>
      <ul v-if="resolution.reasons.length" class="list-disc space-y-1 pl-5 text-sm" data-section="proposal-reasons">
        <li v-for="reason in resolution.reasons" :key="reason">{{ t(REASONS[reason] ?? 'propertyPayments.bankProposal.reasons.other') }}</li>
      </ul>
      <div v-if="resolution.candidates.length" class="flex flex-wrap gap-2 text-xs" data-section="proposal-candidates">
        <span v-for="candidate in resolution.candidates" :key="candidate.unit_id" class="rounded border border-neutral-200 px-2 py-0.5">
          {{ unitName(candidate.unit_id) }} · {{ candidate.signals.map(s => t(SIGNALS[s.signal])).join(', ') || t('propertyPayments.bankProposal.signals.manual') }}
        </span>
      </div>
      <p v-if="reviewText" role="status" class="text-sm text-warning-600" data-flag="proposal-review">{{ reviewText }}</p>
      <p v-if="proposal?.remaining_cents" class="text-sm" data-flag="proposal-remaining">{{ t('propertyPayments.bankProposal.remaining', { amount: paymentDecimal(proposal.remaining_cents) }) }}</p>
      <p v-if="proposal?.preview" class="text-sm text-success-600" data-flag="proposal-ready">{{ t('propertyPayments.bankProposal.ready') }}</p>
      <div v-if="needsUnit" class="flex flex-wrap items-end gap-2">
        <label class="flex min-w-0 flex-col gap-1 text-sm">{{ t('propertyPayments.bankProposal.chooseUnit') }}
          <select v-model="unitChoice" :class="inputClass" :disabled="busy" data-field="proposal-unit">
            <option :value="0">{{ t('propertyPayments.choose') }}</option>
            <option v-for="candidate in resolution.candidates" :key="`c-${candidate.unit_id}`" :value="candidate.unit_id">{{ unitName(candidate.unit_id) }}</option>
            <option v-for="unit in otherUnits" :key="`u-${unit.id}`" :value="unit.id">{{ unitName(unit.id) }}</option>
          </select>
        </label>
        <PropertyButton icon="check" variant="primary" :disabled="busy || !unitChoice" data-action="proposal-use-unit" @click="emit('replan', { unit_id: unitChoice })">{{ t('propertyPayments.bankProposal.useUnit') }}</PropertyButton>
      </div>
      <div v-if="needsDebtor && supplier && resolution.unit_id" class="flex flex-wrap items-end gap-2">
        <div class="min-w-60 flex-1 space-y-1"><span class="text-sm">{{ t('propertyPayments.bankProposal.chooseDebtor') }}</span><PropertyPartyPicker :supplier-id="supplier" :model-value="debtorChoice" @update:model-value="debtorChoice = $event" /></div>
        <PropertyButton icon="check" variant="primary" :disabled="busy || !debtorChoice" data-action="proposal-use-debtor" @click="emit('replan', { unit_id: resolution.unit_id, debtor_id: debtorChoice! })">{{ t('propertyPayments.bankProposal.useDebtor') }}</PropertyButton>
      </div>
      <p class="text-xs text-neutral-500">{{ t('propertyPayments.bankProposal.help') }}</p>
    </template>
  </section>
</template>
