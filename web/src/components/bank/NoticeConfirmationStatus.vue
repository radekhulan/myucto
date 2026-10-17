<script setup lang="ts">
import { computed, ref } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { bankApi, type BankTransaction } from '@/api/bank'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { formatDate, formatMoney } from '@/composables/useFormat'
import Modal from '@/components/ui/Modal.vue'
import { btnOutline, btnFilled, ICONS } from '@/components/ui/buttonStyles'
const props = defineProps<{ tx: BankTransaction }>()
const emit = defineEmits<{ changed: [] }>()
const { t, te } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const open = ref(false)
const selected = ref<number | null>(null)
const reason = ref('')
const busy = ref(false)
const state = computed(() => props.tx.confirmation_state ?? 'pending')
const statementIds = computed(() => [...new Set([props.tx.authoritative_statement_id, ...(props.tx.authoritative_statement_ids ?? [])].filter((id): id is number => !!id))])
const explanation = computed(() => {
  const key = `bank.notice_confirmation.reasons.${props.tx.confirmation_reason}`
  return te(key) ? t(key) : t('bank.notice_confirmation.reason_fallback')
})
async function confirm() {
  if (auth.isClientRole || !auth.canWrite('bank.match') || !selected.value || !reason.value.trim() || busy.value) return
  busy.value = true
  try {
    await bankApi.confirmNotice(props.tx.id, selected.value, reason.value.trim())
    open.value = false
    emit('changed')
  } catch (error) { toast.error(apiErrorMessage(error)); emit('changed') }
  finally { busy.value = false }
}
</script>
<template>
  <div v-if="!auth.isClientRole && tx.source === 'email_notice'" class="space-y-1 text-xs mt-1" data-testid="notice-confirmation-status">
    <span class="inline-flex rounded px-2 py-0.5 font-medium" :class="state === 'confirmed' ? 'bg-success-50 text-success-700' : state === 'review' ? 'bg-danger-50 text-danger-600' : 'bg-warning-50 text-warning-700'">{{ t(`bank.notice_confirmation.states.${state}`) }}</span>
    <p v-if="state !== 'confirmed'" class="text-neutral-500 max-w-xs">{{ explanation }}</p>
    <p v-else class="text-neutral-500">{{ t('bank.notice_confirmation.history_readonly') }}</p>
    <div class="flex flex-wrap gap-2">
      <RouterLink v-for="id in statementIds" :key="id" :to="{ path: `/bank/${id}`, query: { tx: tx.authoritative_transaction_id } }" class="text-primary-600 underline">{{ t('bank.notice_confirmation.statement_link', { id }) }}</RouterLink>
      <button v-if="state !== 'confirmed' && tx.candidates?.length && auth.canWrite('bank.match')" type="button" :class="btnOutline('warning')" @click="open = true">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path :d="ICONS.eye" /></svg>{{ t('bank.notice_confirmation.compare') }}
      </button>
    </div>
    <Modal v-if="open" :title="t('bank.notice_confirmation.compare')" @close="!busy && (open = false)">
      <p class="text-sm mb-3">{{ t('bank.notice_confirmation.manual_impact') }}</p>
      <div class="rounded border border-neutral-200 p-3 mb-3 text-sm">
        <strong>{{ t('bank.email_notice_badge') }}</strong>
        <p>{{ formatDate(tx.posted_at) }} · {{ formatMoney(tx.amount, tx.currency ?? 'CZK') }}</p>
        <p>{{ tx.variable_symbol }} · {{ tx.counterparty_name }} · {{ tx.counterparty_account }}</p>
      </div>
      <label v-for="candidate in tx.candidates" :key="candidate.id" class="flex gap-3 border border-neutral-200 rounded p-3 mb-2 text-sm cursor-pointer">
        <input v-model="selected" type="radio" :value="candidate.id" name="notice-candidate" />
        <span>
          <span class="block">{{ formatDate(candidate.posted_at) }} · {{ formatMoney(candidate.amount, candidate.currency) }}</span>
          <span class="block">{{ candidate.variable_symbol }} · {{ candidate.counterparty_name }}</span>
          <span class="block text-warning-700">{{ t('bank.notice_confirmation.differences', { days: Math.round((Date.parse(candidate.posted_at) - Date.parse(tx.posted_at)) / 86400000), amount: formatMoney(candidate.amount - tx.amount, candidate.currency) }) }}</span>
          <RouterLink :to="`/bank/${candidate.statement_id}?tx=${candidate.id}`" class="text-primary-600 underline">{{ t('bank.notice_confirmation.statement_link', { id: candidate.statement_id }) }}</RouterLink>
        </span>
      </label>
      <label class="block text-sm mt-4">{{ t('bank.notice_confirmation.manual_reason') }}
        <textarea v-model="reason" maxlength="500" rows="3" class="block w-full border border-neutral-300 rounded p-2 mt-1 bg-surface" />
      </label>
      <template #footer><div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="btnOutline('neutral')" :disabled="busy" @click="open = false"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path :d="ICONS.x" /></svg>{{ t('common.cancel') }}</button>
        <button type="button" :class="btnFilled('success')" :disabled="busy || !selected || !reason.trim()" @click="confirm"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path :d="ICONS.check" /></svg>{{ t('bank.notice_confirmation.confirm') }}</button>
      </div></template>
    </Modal>
  </div>
</template>
