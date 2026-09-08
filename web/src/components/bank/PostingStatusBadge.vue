<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import type { BankTransaction } from '@/api/bank'
import AutomationBadge from '@/components/automation/AutomationBadge.vue'

const props = defineProps<{ posting?: BankTransaction['posting']; currency?: string | null }>()
const { t } = useI18n()

// Cizí měna se odvozuje čistě z tx.currency (H4h) — není to stav z BE.
const isForeign = computed(() => !!props.currency && props.currency !== 'CZK')

const accounts = computed(() => {
  const p = props.posting
  if (!p?.debit_account_code || !p?.credit_account_code) return ''
  return `${p.debit_account_code}/${p.credit_account_code}`
})

const postedTitle = computed(() => {
  const p = props.posting
  if (!p) return ''
  const parts: string[] = []
  if (p.document_no) parts.push(p.document_no)
  if (p.rule_name) parts.push(t('bank.posting.auto_posted_by', { rule: p.rule_name }))
  return parts.join(' · ')
})
</script>

<template>
  <RouterLink v-if="posting?.note === 'payroll_partial_posting' && posting.journal_entry_id"
    :to="`/accounting/journal?entry_id=${posting.journal_entry_id}`"
    :title="t('bank.posting.payroll_partial_hint')"
    class="mt-1 inline-flex items-center text-xs px-2 py-0.5 rounded font-medium bg-warning-50 text-warning-600 hover:bg-warning-100">
    {{ t('bank.posting.badge_payroll_partial') }}
  </RouterLink>

  <span v-else-if="posting?.note === 'payroll_partial_posting'"
    :title="t('bank.posting.payroll_partial_hint')"
    class="mt-1 inline-flex items-center text-xs px-2 py-0.5 rounded font-medium bg-warning-50 text-warning-600">
    {{ t('bank.posting.badge_payroll_partial') }}
  </span>

  <RouterLink v-else-if="posting?.status === 'posted' && posting.journal_entry_id"
    :to="`/accounting/journal?entry_id=${posting.journal_entry_id}`"
    :title="postedTitle"
    class="mt-1 inline-flex items-center"
    :class="posting.automated ? '' : 'text-xs px-2 py-0.5 rounded font-medium bg-success-50 text-success-600 hover:bg-success-100'">
    <AutomationBadge v-if="posting.automated" variant="auto" />
    <span v-else>{{ t('bank.posting.badge_posted') }}</span>
  </RouterLink>

  <span v-else-if="posting?.status === 'suggested'"
    :title="posting.rule_name ?? undefined"
    class="mt-1 inline-flex items-center text-xs px-2 py-0.5 rounded font-medium bg-warning-50 text-warning-600">
    {{ accounts ? t('bank.posting.badge_suggested', { accounts }) : t('bank.posting.tab_suggestions') }}
  </span>

  <span v-else-if="isForeign"
    :title="t('bank.posting.skipped_fx_hint')"
    class="mt-1 inline-flex items-center text-xs px-2 py-0.5 rounded font-medium bg-neutral-100 text-neutral-500">
    {{ t('bank.posting.badge_fx') }}
  </span>
</template>
