<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { formatMoney, formatMoneyMinor, formatDate } from '@/composables/useFormat'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import MatchSuggestionPanel from './MatchSuggestionPanel.vue'
import PaymentMethodModal from '@/components/invoices/PaymentMethodModal.vue'
import type { BankTransactionActions } from '@/composables/useBankTransactionActions'
import type { MatchCandidate } from '@/api/bank'

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
  matchBusy, matchingTx, matchCtx, matchVarsymbol, matchSearch, matchCandidates, loadingCandidates, candidatesFallback, matchingCandidate,
  membershipCandidates, selectedMembershipClaims, membershipNextCursor, loadingMembershipCandidates, membershipCandidateError, membershipUnavailable,
  membershipSelectedCents, membershipSelectionValid, toggleMembershipClaim, loadMembershipCandidates, confirmMembershipMatch,
  gopayCandidate, loadingGoPayCandidate, matchingGoPay,
  splitSuggestions, loadingSplit, splitWindow,
  anchorInvoiceId, anchorOptions, anchorSelected, anchorLoading,
  currentSuggestion, matchError, reviewingSuggestion,
  widenSplitWindow, onAnchorSearch, onAnchorSelect,
  confirmSuggestion, confirmCandidate, confirmMatch, confirmGoPayCandidate, closeMatch,
  acceptTxSuggestion, rejectTxSuggestion,
  purchaseShortfall, closePurchaseShortfall, onPurchaseShortfallSettled,
} = props.actions

function candidateLabel(c: MatchCandidate): string {
  if (c.type === 'other_item') return t(c.side === 'payable' ? 'bank.candidate_other_payable' : 'bank.candidate_other_receivable')
  return c.type === 'invoice' ? t('bank.candidate_issued') : t('bank.candidate_purchase')
}

/** Důvody shody ostatní položky i členského předpisu (stejný ranker): VS, účet protistrany, splátka, celý zbytek. */
function matchReasonLabels(reasons: readonly string[] | undefined, installmentPosition?: number | null): string[] {
  return (reasons || []).map(reason => t(`bank.other_item_match.${reason}`, { number: installmentPosition ?? '' }))
}

function otherItemReasons(c: MatchCandidate): string[] {
  return c.type === 'other_item' ? matchReasonLabels(c.match_reasons, c.installment?.position) : []
}

function candidateBadgeClass(c: MatchCandidate): string {
  if (c.type === 'other_item') return 'bg-primary-50 text-primary-600'
  return c.type === 'invoice' ? 'bg-success-50 text-success-600' : 'bg-warning-50 text-warning-600'
}
</script>

<template>
  <!-- Ruční párování s nižší platbou: doklad zůstal částečně uhrazený. Volba (a) nechat
       tak = zavřít, (b) rozdíl vyrovnat zápočtem proti účtu (321 MD / zvolený účet D). -->
  <PaymentMethodModal v-if="purchaseShortfall" doc-type="purchase_invoice" settlement-only
    :doc-id="purchaseShortfall.purchaseInvoiceId" :doc-number="purchaseShortfall.docNumber"
    :amount="purchaseShortfall.remaining" :currency="purchaseShortfall.currency"
    :default-account-code="purchaseShortfall.currency === 'CZK' ? '648' : '663'"
    :title="t('bank.purchase_shortfall.title')"
    :intro="t('bank.purchase_shortfall.intro', { amount: formatMoney(purchaseShortfall.remaining, purchaseShortfall.currency) })"
    :cancel-label="t('bank.purchase_shortfall.keep_partial')"
    :confirm-label="t('bank.purchase_shortfall.settle')"
    @close="closePurchaseShortfall" @done="onPurchaseShortfallSettled" />

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
        :can-review="actions.canReviewSuggestion(currentSuggestion)" :can-accept-candidate="actions.canAcceptCandidate"
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
        <div class="text-sm font-medium text-neutral-700 mb-1.5">{{ t(matchSearch.trim() ? 'bank.candidate_search_results' : 'bank.candidates_title') }}</div>
        <label class="mb-2 block">
          <span class="mb-1 block text-xs text-neutral-500">{{ t('bank.candidate_search_label') }}</span>
          <input v-model="matchSearch" type="search" :placeholder="t('bank.candidate_search_placeholder')"
            class="h-9 w-full rounded-md border border-neutral-300 bg-surface px-3 text-sm" />
        </label>
        <p v-if="!loadingCandidates && candidatesFallback && matchCandidates.length > 0"
          class="text-xs text-warning-600 bg-warning-50 border border-warning-500/30 rounded-md px-2 py-1.5 mb-1.5">
          {{ t('bank.candidates_fallback_hint') }}
        </p>
        <div v-if="loadingCandidates" class="text-xs text-neutral-500 py-2">{{ t('common.loading') }}</div>
        <div v-else-if="matchCandidates.length === 0" class="text-xs text-neutral-400 py-2">{{ t(matchSearch.trim() ? 'bank.candidate_search_empty' : 'bank.no_candidates') }}</div>
        <ul v-else class="border border-neutral-200 rounded-md divide-y divide-neutral-100 max-h-56 overflow-auto">
          <li v-for="c in matchCandidates" :key="`${c.type}-${c.id}`">
            <button type="button" @click="confirmCandidate(c)" :disabled="matchingCandidate || !actions.canMatchDocument(c.type)"
              class="w-full text-left px-3 py-2 hover:bg-primary-50 flex items-center justify-between gap-2 disabled:opacity-50">
              <span class="min-w-0">
                <span class="text-[10px] uppercase px-1.5 py-0.5 rounded font-semibold" :class="candidateBadgeClass(c)">
                  {{ candidateLabel(c) }}
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
                <span v-if="otherItemReasons(c).length" class="mt-0.5 flex flex-wrap gap-1" data-testid="other-item-reasons">
                  <span v-for="label in otherItemReasons(c)" :key="label"
                    class="text-[10px] uppercase px-1.5 py-0.5 rounded font-semibold bg-success-50 text-success-700">{{ label }}</span>
                </span>
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

      <section v-if="matchCtx && matchCtx.amount > 0 && actions.canSeeMembershipCandidates()" class="mb-4" data-testid="membership-bank-match">
        <h4 class="text-sm font-medium text-neutral-700">{{ t('bank.membership_candidates') }}</h4>
        <p class="mt-1 mb-2 text-xs text-neutral-500">{{ t('bank.membership_candidates_hint') }}</p>
        <p v-if="membershipCandidateError" role="alert" class="mb-2 text-sm text-danger-600">{{ membershipCandidateError }}</p>
        <ul v-if="membershipCandidates.length" class="max-h-56 overflow-auto divide-y divide-neutral-100 rounded-md border border-neutral-200">
          <li v-for="claim in membershipCandidates" :key="claim.id">
            <label class="flex items-start gap-2 px-3 py-2">
              <input type="checkbox" class="mt-1" :checked="selectedMembershipClaims.some(c => c.id === claim.id)"
                :disabled="matchBusy || !actions.canMatchMembership() || (selectedMembershipClaims.length >= 100 && !selectedMembershipClaims.some(c => c.id === claim.id))" @change="toggleMembershipClaim(claim)" />
              <span class="min-w-0 flex-1 text-sm">
                <span class="block font-medium">{{ claim.party }}</span>
                <span class="block text-xs text-neutral-500">{{ claim.ref }} · {{ formatDate(claim.period_from) }} – {{ formatDate(claim.period_to) }}</span>
                <span v-if="claim.variable_symbol" class="block text-xs text-neutral-500">{{ t('bank.membership_vs', { vs: claim.variable_symbol }) }}</span>
                <span v-if="claim.match_reasons?.length" class="mt-0.5 flex flex-wrap gap-1" data-testid="membership-match-reasons">
                  <span v-for="label in matchReasonLabels(claim.match_reasons)" :key="label"
                    class="text-[10px] uppercase px-1.5 py-0.5 rounded font-semibold bg-success-50 text-success-700">{{ label }}</span>
                </span>
              </span>
              <span class="shrink-0 whitespace-nowrap text-sm tabular-nums">{{ formatMoney(claim.amount, claim.currency) }}</span>
            </label>
          </li>
        </ul>
        <p v-else-if="membershipUnavailable" class="py-2 text-xs text-neutral-500">{{ t('bank.membership_unavailable') }}</p>
        <p v-else-if="!loadingMembershipCandidates && !membershipCandidateError && membershipNextCursor === null" class="py-2 text-xs text-neutral-500">{{ t('bank.membership_candidates_empty') }}</p>
        <p v-if="loadingMembershipCandidates" role="status" class="py-2 text-xs text-neutral-500">{{ t('common.loading') }}</p>
        <div class="mt-2 flex flex-wrap gap-2">
          <button v-if="membershipCandidateError" type="button" :disabled="loadingMembershipCandidates || matchBusy"
            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-neutral-300 px-3 py-1.5 text-sm text-neutral-700 disabled:opacity-50"
            @click="loadMembershipCandidates()">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M20 7v5h-5M4 17v-5h5M6 7a7 7 0 0 1 12-1l2 6M4 12l2 6a7 7 0 0 0 12-1" /></svg>
            {{ t('bank.membership_retry') }}
          </button>
          <button v-if="membershipNextCursor !== null" type="button" :disabled="loadingMembershipCandidates || matchBusy"
            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md border border-neutral-300 px-3 py-1.5 text-sm text-neutral-700 disabled:opacity-50"
            @click="loadMembershipCandidates(true)">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 9 6 6 6-6" /></svg>
            {{ t('bank.membership_more') }}
          </button>
        </div>
        <div v-if="selectedMembershipClaims.length" class="mt-3 rounded-md border border-primary-200 bg-primary-50 p-3">
          <p class="text-sm font-medium">{{ t('bank.membership_selected', { count: selectedMembershipClaims.length, amount: formatMoneyMinor(Number(membershipSelectedCents)) }) }}</p>
          <ul class="my-2 space-y-1">
            <li v-for="claim in selectedMembershipClaims" :key="claim.id" class="flex flex-wrap items-center justify-between gap-2 text-xs">
              <span>{{ claim.party }} · {{ claim.ref }} · {{ formatMoney(claim.amount, claim.currency) }}</span>
              <button type="button" :disabled="matchBusy" class="inline-flex items-center gap-1 whitespace-nowrap text-neutral-700 hover:underline disabled:opacity-50" @click="toggleMembershipClaim(claim)">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18" /></svg>
                {{ t('bank.membership_remove') }}
              </button>
            </li>
          </ul>
          <p v-if="!membershipSelectionValid" class="mb-2 text-xs text-warning-700">{{ t('bank.membership_sum_mismatch') }}</p>
          <button type="button" :disabled="matchBusy || !membershipSelectionValid || !actions.canMatchMembership()"
            class="inline-flex items-center gap-1.5 whitespace-nowrap rounded-md bg-success-600 px-3 py-2 text-sm font-medium text-white hover:bg-success-700 disabled:opacity-50"
            @click="confirmMembershipMatch">
            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m5 12 4 4L19 6" /></svg>
            {{ t('bank.membership_confirm') }}
          </button>
        </div>
      </section>

      <!-- Sloučená úhrada: kombinace faktur jednoho klienta sečtené na částku platby -->
      <div v-if="matchCtx && matchCtx.amount !== 0" class="mb-4">
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
          {{ splitWindow > 0
            ? t(matchCtx.amount < 0 ? 'bank.split_purchase_hint' : 'bank.split_hint', { days: splitWindow })
            : t(matchCtx.amount < 0 ? 'bank.split_purchase_hint_all' : 'bank.split_hint_all') }}
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
        <div v-else-if="splitSuggestions.length === 0" class="text-xs text-neutral-400 py-2">{{ t(matchCtx.amount < 0 ? 'bank.split_purchase_none' : 'bank.split_none') }}</div>
        <ul v-else class="space-y-2">
          <li v-for="(s, idx) in splitSuggestions" :key="idx" class="border border-neutral-200 rounded-md p-2.5">
            <div class="flex items-center justify-between gap-2 mb-1.5">
              <span class="text-sm font-medium truncate">{{ s.client_name || t(matchCtx.amount < 0 ? 'bank.split_purchase_group' : 'bank.split_unknown_client') }}</span>
              <span class="font-mono text-sm"
                :class="matchCtx && Math.abs(s.total - Math.abs(matchCtx.amount)) < 1 ? 'text-success-600' : 'text-neutral-600'">
                {{ formatMoney(s.total, s.currency) }}
              </span>
            </div>
            <ul class="text-xs text-neutral-500 space-y-0.5 mb-2">
              <li v-for="inv in s.invoices" :key="inv.id" class="flex items-center justify-between gap-2">
                <span class="font-mono truncate">
                  {{ inv.ref || `#${inv.id}` }}
                  <span v-if="inv.vendor_name" class="font-sans text-neutral-400 ml-1">{{ inv.vendor_name }}</span>
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
            <p v-if="Math.abs(Math.abs(matchCtx.amount) - s.total) >= 0.005" class="mb-2 text-xs text-warning-700" data-testid="split-difference">
              {{ t('bank.split_difference', { amount: formatMoney(Math.abs(matchCtx.amount) - s.total, s.currency) }) }}
            </p>
            <p v-if="matchCtx.amount < 0 && s.invoices.some(invoice => invoice.currency !== s.currency)" class="mb-2 text-xs text-neutral-500">
              {{ t('bank.split_fx_hint') }}
            </p>
            <button type="button" @click="confirmSuggestion(s)" :disabled="matchBusy || !actions.canMatchCurrentDocument()"
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
        <button @click="confirmMatch" :disabled="matchBusy || !matchVarsymbol.trim() || !actions.canMatchAnyDocument()"
          class="cursor-pointer px-4 h-10 text-sm bg-primary-600 hover:bg-primary-700 disabled:bg-neutral-300 text-white font-medium rounded-md">
          {{ t('bank.match') }}
        </button>
      </div>
      <p class="text-xs text-neutral-500 mb-4">{{ t('bank.vs_hint') }}</p>

      <div v-if="matchError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500 mb-3">
        {{ matchError }}
      </div>
      <div class="flex justify-end">
        <button @click="closeMatch" :disabled="matchBusy" class="cursor-pointer px-3 h-9 text-sm border border-neutral-300 rounded-md hover:bg-neutral-50">{{ t('common.cancel') }}</button>
      </div>
    </div>
  </div>
</template>
