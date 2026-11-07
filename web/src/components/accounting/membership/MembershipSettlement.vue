<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ClaimSettlement } from '@/api/nonprofitMembership'
import { formatMoneyMinor } from '@/composables/useFormat'
import { badgeBase } from './membershipFormat'

const props = defineProps<{ settlement: ClaimSettlement }>()
const { t } = useI18n()

const known = ['settled', 'partially_settled', 'unreconciled', 'ambiguous', 'reversed']
const evidences = ['no_native_pairing', 'journal_receipt_mismatch', 'pairing_not_exact_native_payment', 'journal_line_pairing_bank_or_cash', 'membership_reversal_claim']
const status = computed(() => (known.includes(props.settlement.status) ? props.settlement.status : 'ambiguous'))
const evidence = computed(() => (evidences.includes(props.settlement.evidence) ? props.settlement.evidence : 'unknown'))
const tone = computed(() => ({
  settled: 'bg-success-50 text-success-600',
  partially_settled: 'bg-warning-50 text-warning-700',
  unreconciled: 'bg-danger-50 text-danger-600',
  ambiguous: 'bg-warning-50 text-warning-700',
  reversed: 'bg-neutral-100 text-neutral-700',
}[status.value]))
const proven = computed(() => props.settlement.paid_cents !== null && props.settlement.open_cents !== null)
</script>

<template>
  <div class="space-y-1 text-sm" data-section="settlement">
    <span :class="[badgeBase, tone]" data-field="settlement-status">{{ t(`nonprofitMembership.settlement_${status}`) }}</span>
    <p v-if="proven" data-field="settlement-amounts">
      {{ t('nonprofitMembership.paid') }}: <span class="whitespace-nowrap tabular-nums">{{ formatMoneyMinor(settlement.paid_cents) }}</span> · {{ t('nonprofitMembership.openAmount') }}: <span class="whitespace-nowrap tabular-nums">{{ formatMoneyMinor(settlement.open_cents) }}</span>
    </p>
    <p v-else-if="status !== 'reversed'" class="text-warning-700" data-field="settlement-unknown">{{ t('nonprofitMembership.paymentUnknown') }}</p>
    <p class="text-neutral-500">{{ t(`nonprofitMembership.evidence_${evidence}`) }}</p>
  </div>
</template>
