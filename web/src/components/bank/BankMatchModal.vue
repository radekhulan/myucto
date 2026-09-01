<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { formatMoney, formatDate } from '@/composables/useFormat'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import MatchSuggestionPanel from './MatchSuggestionPanel.vue'
import type { BankTransactionActions } from '@/composables/useBankTransactionActions'

const props = defineProps<{
  actions: BankTransactionActions
  /** Fallback měna, když transakce sama měnu nenese (statement.currency u detailu, 'CZK' jinde). */
  fallbackCurrency?: string | null
}>()

const { t } = useI18n()
const auth = useAuthStore()

// Destrukturace refů z composable — jsou to skutečné Ref objekty (ne reactive()
// proxy), takže se tím reaktivita neztrácí a šablona je navíc auto-unwrapne
// (a správně zúží v v-if větvích), stejně jako běžné top-level refy v <script setup>.
const {
  matchingTx, matchCtx, matchVarsymbol, matchCandidates, loadingCandidates, candidatesFallback,
  gopayCandidate, loadingGoPayCandidate, matchingGoPay,
  splitSuggestions, loadingSplit, splitWindow,
  anchorInvoiceId, anchorOptions, anchorSelected, anchorLoading,
  currentSuggestion, matchError, reviewingSuggestion,
  widenSplitWindow, onAnchorSearch, onAnchorSelect,
  confirmSuggestion, confirmCandidate, confirmMatch, confirmGoPayCandidate, closeMatch,
  acceptTxSuggestion, rejectTxSuggestion,
} = props.actions
</script>

<template>
  <div v-if="matchingTx" class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-surface rounded-xl shadow-lg max-w-md w-full max-h-[90vh] overflow-y-auto p-5">
      <h3 class="text-lg font-semibold mb-1">{{ t('bank.manual_match_title') }}</h3>
      <p v-if="matchCtx" class="text-xs text-neutral-500 mb-3 font-mono">
        {{ matchCtx.amount > 0 ? '+' : '' }}{{ formatMoney(matchCtx.amount, matchCtx.currency ?? fallbackCurrency ?? 'CZK') }}
        · {{ formatDate(matchCtx.posted_at) }}
        <span v-if="matchCtx.counterparty_name" class="text-neutral-400"> · {{ matchCtx.counterparty_name }}</span>
      </p>

      <MatchSuggestionPanel v-if="currentSuggestion" class="mb-4" variant="inline"
        :suggestion="currentSuggestion" :reviewing="reviewingSuggestion"
        :can-review="auth.canWrite('bank.match')"
        @accept="(i) => acceptTxSuggestion(currentSuggestion!.bank_transaction_id, i)"
        @reject="rejectTxSuggestion(currentSuggestion!.bank_transaction_id)" />

      <div v-if="loadingGoPayCandidate || gopayCandidate" class="mb-4">
        <div class="mb-1.5 text-sm font-medium text-neutral-700">{{ t('bank.gopay_match.title') }}</div>
        <div v-if="loadingGoPayCandidate" class="py-2 text-xs text-neutral-500">{{ t('common.loading') }}</div>
        <div v-else-if="gopayCandidate" class="rounded-md border border-success-200 bg-success-50 p-3">
          <div class="flex flex-wrap items-start justify-between gap-2">
            <div>
              <div class="font-medium text-success-800">
                {{ t('bank.gopay_match.clearing', { id: gopayCandidate.clearing_id }) }}
              </div>
              <div class="mt-0.5 text-xs text-success-700">
                {{ formatDate(gopayCandidate.performed_on) }} ·
                {{ formatMoney(gopayCandidate.amount_sent, gopayCandidate.currency) }}
              </div>
            </div>
            <button type="button" @click="confirmGoPayCandidate"
              :disabled="matchingGoPay || !auth.canWrite('bank.match') || !auth.canWrite('bank.post')"
              class="inline-flex h-9 cursor-pointer items-center gap-1.5 whitespace-nowrap rounded-md bg-success-600 px-3 text-sm font-medium text-white hover:bg-success-700 disabled:cursor-not-allowed disabled:bg-neutral-300">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 0 1 0 5.656l-2 2a4 4 0 0 1-5.656-5.656l1.1-1.1m3.9 2.756a4 4 0 0 1 0-5.656l2-2a4 4 0 0 1 5.656 5.656l-1.1 1.1" />
              </svg>
              {{ matchingGoPay ? t('bank.gopay_match.matching') : t('bank.gopay_match.match') }}
            </button>
          </div>
          <p class="mt-2 text-xs text-success-700">
            {{ gopayCandidate.transaction_source === 'email_notice'
              ? t('bank.gopay_match.notice_hint')
              : t('bank.gopay_match.statement_hint') }}
          </p>
        </div>
      </div>

      <!-- Návrhy ke spárování dle částky (±14 dní, fallback ±90 dní) -->
      <div class="mb-4">
        <div class="text-sm font-medium text-neutral-700 mb-1.5">{{ t('bank.candidates_title') }}</div>
        <p v-if="!loadingCandidates && candidatesFallback && matchCandidates.length > 0"
          class="text-xs text-warning-600 bg-warning-50 border border-warning-500/30 rounded-md px-2 py-1.5 mb-1.5">
          {{ t('bank.candidates_fallback_hint') }}
        </p>
        <div v-if="loadingCandidates" class="text-xs text-neutral-500 py-2">{{ t('common.loading') }}</div>
        <div v-else-if="matchCandidates.length === 0" class="text-xs text-neutral-400 py-2">{{ t('bank.no_candidates') }}</div>
        <ul v-else class="border border-neutral-200 rounded-md divide-y divide-neutral-100 max-h-56 overflow-auto">
          <li v-for="c in matchCandidates" :key="`${c.type}-${c.id}`">
            <button type="button" @click="confirmCandidate(c)"
              class="w-full text-left px-3 py-2 hover:bg-primary-50 flex items-center justify-between gap-2">
              <span class="min-w-0">
                <span class="text-[10px] uppercase px-1.5 py-0.5 rounded font-semibold"
                  :class="c.type === 'invoice' ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-600'">
                  {{ c.type === 'invoice' ? t('bank.candidate_issued') : t('bank.candidate_purchase') }}
                </span>
                <span v-if="c.paid" class="text-[10px] uppercase px-1.5 py-0.5 rounded font-semibold bg-neutral-200 text-neutral-600 ml-1">
                  {{ t('bank.candidate_paid') }}
                </span>
                <span v-if="c.currency_mismatch" :title="t('bank.candidate_currency_mismatch_hint')"
                  class="text-[10px] uppercase px-1.5 py-0.5 rounded font-semibold bg-danger-50 text-danger-600 ml-1">
                  {{ t('bank.candidate_currency_mismatch') }}
                </span>
                <span class="font-mono text-sm ml-1">{{ c.ref || `#${c.id}` }}</span>
                <span v-if="c.party" class="text-xs text-neutral-500 block truncate">{{ c.party }}</span>
              </span>
              <span class="text-right whitespace-nowrap shrink-0">
                <span class="font-mono text-sm">{{ formatMoney(c.amount, c.currency) }}</span>
                <span v-if="c.converted_amount != null" class="text-xs text-neutral-400 block">
                  ≈ {{ formatMoney(c.converted_amount, c.converted_currency || 'CZK') }}
                </span>
                <span class="text-xs text-neutral-400 block">{{ formatDate(c.due_date || c.issue_date) }}</span>
              </span>
            </button>
          </li>
        </ul>
      </div>

      <!-- Sloučená úhrada: kombinace faktur jednoho klienta sečtené na částku platby -->
      <div v-if="matchCtx && matchCtx.amount > 0" class="mb-4">
        <div class="flex items-center justify-between gap-2 mb-1">
          <div class="text-sm font-medium text-neutral-700">{{ t('bank.split_title') }}</div>
          <button v-if="splitWindow > 0" type="button" @click="widenSplitWindow"
            class="cursor-pointer text-xs text-primary-600 hover:underline whitespace-nowrap">
            {{ splitWindow >= 60
              ? t('bank.split_widen_all')
              : t('bank.split_widen', { days: Math.min(60, splitWindow + 7) }) }}
          </button>
        </div>
        <p class="text-xs text-neutral-400 mb-1.5">
          {{ splitWindow > 0 ? t('bank.split_hint', { days: splitWindow }) : t('bank.split_hint_all') }}
        </p>

        <!-- Kotva: vyber jednu fakturu, dohledá se zbytek téhož klienta -->
        <div class="mb-2">
          <SearchableSelect
            :model-value="anchorInvoiceId"
            :options="anchorOptions"
            :selected-option="anchorSelected"
            :remote="true"
            :loading="anchorLoading"
            :placeholder="t('bank.split_anchor_placeholder')"
            :loading-label="t('common.loading')"
            :no-results-label="t('bank.no_candidates')"
            @search="onAnchorSearch"
            @update:model-value="(v) => onAnchorSelect(v as number | null)" />
          <p v-if="anchorInvoiceId" class="text-xs text-primary-600 mt-1">{{ t('bank.split_anchor_active') }}</p>
        </div>

        <div v-if="loadingSplit" class="text-xs text-neutral-500 py-2">{{ t('common.loading') }}</div>
        <div v-else-if="splitSuggestions.length === 0" class="text-xs text-neutral-400 py-2">{{ t('bank.split_none') }}</div>
        <ul v-else class="space-y-2">
          <li v-for="(s, idx) in splitSuggestions" :key="idx" class="border border-neutral-200 rounded-md p-2.5">
            <div class="flex items-center justify-between gap-2 mb-1.5">
              <span class="text-sm font-medium truncate">{{ s.client_name || t('bank.split_unknown_client') }}</span>
              <span class="font-mono text-sm"
                :class="matchCtx && Math.abs(s.total - Math.abs(matchCtx.amount)) < 1 ? 'text-success-600' : 'text-neutral-600'">
                {{ formatMoney(s.total, s.currency) }}
              </span>
            </div>
            <ul class="text-xs text-neutral-500 space-y-0.5 mb-2">
              <li v-for="inv in s.invoices" :key="inv.id" class="flex items-center justify-between gap-2">
                <span class="font-mono truncate">
                  {{ inv.ref || `#${inv.id}` }}
                  <span v-if="inv.is_paid"
                    class="font-sans text-[10px] uppercase px-1.5 py-0.5 rounded font-semibold bg-neutral-200 text-neutral-600 ml-1"
                    :title="t('bank.split_reconcile_hint')">{{ t('bank.candidate_paid') }}</span>
                  <span class="text-neutral-400 ml-1">· {{ formatDate(inv.due_date || inv.issue_date) }}</span>
                </span>
                <span class="font-mono whitespace-nowrap">
                  {{ formatMoney(inv.amount, inv.currency) }}
                  <span v-if="inv.converted != null" class="text-neutral-400"> ≈ {{ formatMoney(inv.converted, s.currency) }}</span>
                </span>
              </li>
            </ul>
            <button type="button" @click="confirmSuggestion(s)"
              class="cursor-pointer w-full h-8 text-sm bg-primary-600 hover:bg-primary-700 text-white font-medium rounded-md">
              {{ t('bank.split_match', { count: s.count }) }}
            </button>
          </li>
        </ul>
      </div>

      <!-- Druhá možnost: ruční zadání VS -->
      <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.match_by_vs') }}</label>
      <div class="flex gap-2 mb-1">
        <input v-model="matchVarsymbol" type="text" inputmode="numeric"
          placeholder="2603001"
          @keyup.enter="confirmMatch"
          class="flex-1 h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
        <button @click="confirmMatch" :disabled="!matchVarsymbol.trim()"
          class="cursor-pointer px-4 h-10 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
          {{ t('bank.match') }}
        </button>
      </div>
      <p class="text-xs text-neutral-500 mb-4">{{ t('bank.vs_hint') }}</p>

      <div v-if="matchError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500 mb-3">
        {{ matchError }}
      </div>
      <div class="flex justify-end">
        <button @click="closeMatch" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
      </div>
    </div>
  </div>
</template>
