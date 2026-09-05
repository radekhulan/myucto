<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { formatAccountNumber } from '@/utils/bankAccount'
import type { BankTransaction } from '@/api/bank'
import type { AutomationProvenance } from '@/api/automation'
import type { BankPostingRulePayload, PostResult } from '@/api/bankPosting'
import PostingRowActions from './PostingRowActions.vue'
import RuleFormModal from './RuleFormModal.vue'
import PostingStatusBadge from './PostingStatusBadge.vue'
import MatchSuggestionPanel from './MatchSuggestionPanel.vue'
import WhyChip from '@/components/automation/WhyChip.vue'
import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'
import RowActionsMenu, { type RowAction } from '@/components/ui/RowActionsMenu.vue'
import type { BankTransactionActions } from '@/composables/useBankTransactionActions'

/** Náš zdrojový účet (jen scope='all' u „Všechny pohyby" — viz UnpostedBankTransaction). */
type RowTx = BankTransaction & {
  account_number?: string
  bank_code?: string | null
  account_label?: string | null
}

const props = withDefaults(defineProps<{
  tx: RowTx
  /** 'desktop' = <tr> fragmenty do <tbody>, 'mobile' = jedna karta <div>. */
  layout: 'desktop' | 'mobile'
  isDoubleEntry: boolean
  /** Měna, když ji transakce sama nenese (statement.currency u detailu, 'CZK' u „Všechny pohyby"). */
  fallbackCurrency?: string | null
  /** Sloupec „Náš účet" — jen záložka „Všechny pohyby" (napříč účty, viz #51). */
  showAccount?: boolean
  /** Odkaz zpět na výpis (#N) — u detailu zbytečný (jsme už v něm), u „Všechny pohyby" navigační pomůcka. */
  showStatementLink?: boolean
  /** Počet sloupců tabulky (desktop) pro colspan expand řádků. */
  colspan?: number
  actions: BankTransactionActions
}>(), {
  fallbackCurrency: null,
  showAccount: false,
  showStatementLink: false,
  colspan: 7,
})

const emit = defineEmits<{
  posted: [payload: { result: PostResult; debit: string; credit: string }, tx: BankTransaction]
  changed: []
}>()

const { t } = useI18n()
const auth = useAuthStore()
const ruleTemplateOpen = ref(false)
const rulePrefill = computed<BankPostingRulePayload>(() => ({
  name: props.tx.counterparty_name || '',
  is_active: true,
  direction: props.tx.amount > 0 ? 'incoming' : 'outgoing',
  counterparty_account: props.tx.counterparty_account,
  counterparty_bank: props.tx.counterparty_bank,
  counterparty_prefix: null,
  variable_symbol: props.tx.variable_symbol,
  message_contains: props.tx.description || null,
  amount_min: null,
  amount_max: null,
  priority: 100,
  operation_type: null,
  auto_amount_cap: null,
  applies_currency: currency(),
  debit_account_code: props.tx.posting?.debit_account_code || (props.tx.amount > 0 ? '221' : ''),
  credit_account_code: props.tx.posting?.credit_account_code || (props.tx.amount > 0 ? '' : '221'),
  description: null,
  mode: 'suggest',
}))

const {
  expandedSuggestions, expandedDocs, toggleSuggestion, toggleDocs, suggestionFor,
  reviewingSuggestion, acceptTxSuggestion, rejectTxSuggestion,
  startMatch, openCreate, openRequestDoc, ignoreTx, unmatchTx,
} = props.actions

function currency(): string {
  return props.tx.currency ?? props.fallbackCurrency ?? 'CZK'
}

function statusBadge(s: string): string {
  if (s === 'auto_exact') return 'bg-success-50 text-success-600'
  if (s === 'auto_partial') return 'bg-warning-50 text-warning-600'
  if (s === 'manual') return 'bg-primary-100 text-primary-700'
  if (s === 'ignored') return 'bg-neutral-100 text-neutral-500'
  return 'bg-danger-50 text-danger-500'
}

function statusLabel(s: string): string {
  const key = `bank.match_status.${s}`
  const label = t(key)
  return label === key ? s : label
}

/**
 * Odkaz na druhou nohu vlastního převodu.
 *
 * Míří na konkrétní pohyb, ne jen na výpis: všechny převody mezi dvěma účty
 * končí na tomtéž výpisu, takže bez `tx` uživatel přistál na stránce, kde nebylo
 * poznat, který z řádků je protějšek — a proklik působil, že nedělá nic.
 */
function pairLink(pair: { statement_id: number; tx_id: number }): RouteLocationRaw {
  return { path: `/bank/${pair.statement_id}`, query: { tx: String(pair.tx_id) } }
}

function transferProvenance(tx: BankTransaction): AutomationProvenance {
  return {
    source: 'detector',
    mode: tx.posting?.automated ? 'auto' : 'approved',
    confidence: null,
    detector: 'own_transfer',
    rule_id: null,
    rule_name: null,
    suggestion_id: tx.posting?.suggestion_id ?? null,
    decided_at: tx.posted_at,
    decided_by: null,
  }
}

function transferTooltip(tx: BankTransaction): string {
  const transfer = tx.posting?.transfer
  if (!transfer) return ''
  const key = transfer.direction === 'out' ? 'bank.transfer.tooltip_out' : 'bank.transfer.tooltip_in'
  return t(key, { account: transfer.own_account_label ?? tx.counterparty_account ?? '—' })
}

function onPosted(payload: { result: PostResult; debit: string; credit: string }) {
  emit('posted', payload, props.tx)
}

// Řádkové akce párování banky → 2 tlačítka inline, zbytek do „…" popupu (RowActionsMenu).
function matchActions(tx: BankTransaction): RowAction[] {
  const st = tx.match_status
  const canMatch = auth.canWrite('bank.match')
  return [
    {
      key: 'open-inv', label: t('bank.open'), icon: 'doc', variant: 'neutral',
      to: tx.matched_invoice_id ? `/invoices/${tx.matched_invoice_id}` : undefined,
      show: !!tx.matched_invoice_id,
    },
    {
      key: 'open-pi', label: t('bank.open'), icon: 'doc', variant: 'neutral',
      to: tx.matched_purchase_invoice_id ? `/purchase-invoices/${tx.matched_purchase_invoice_id}` : undefined,
      show: !tx.matched_invoice_id && !!tx.matched_purchase_invoice_id,
    },
    {
      key: 'match', label: t('bank.match'), icon: 'link', variant: 'primary',
      run: () => startMatch(tx),
      show: canMatch && (st === 'unmatched' || st === 'auto_partial'),
    },
    {
      key: 'create-purchase', label: t('bank.create_purchase'), icon: 'plus', variant: 'primary',
      run: () => openCreate(tx),
      show: auth.canWrite('purchase_invoices.create') && tx.amount < 0 && st === 'unmatched',
    },
    {
      key: 'request-document', label: t('bank.document_request.action'), icon: 'doc', variant: 'warning',
      run: () => openRequestDoc(tx),
      show: auth.canWrite('documents.requests') && (st === 'unmatched' || st === 'auto_partial'),
    },
    {
      key: 'documents', label: expandedDocs.value.has(tx.id) ? t('bank.documents_hide') : t('bank.documents_action'),
      icon: 'doc', variant: 'neutral',
      run: () => toggleDocs(tx.id),
    },
    {
      key: 'create-posting-rule', label: t('bank.posting.create_rule_from_movement'), icon: 'doc', variant: 'neutral',
      run: () => { ruleTemplateOpen.value = true },
      show: props.isDoubleEntry && auth.canWrite('bank.rules') && tx.source !== 'email_notice' && st !== 'ignored',
    },
    {
      key: 'ignore', label: t('bank.ignore'), icon: 'x', variant: 'neutral',
      run: () => ignoreTx(tx),
      show: canMatch && st === 'unmatched',
    },
    {
      key: 'unmatch', label: t('bank.unmatch'), icon: 'uturn', variant: 'neutral',
      run: () => unmatchTx(tx),
      show: canMatch && ['auto_exact', 'auto_partial', 'manual', 'ignored'].includes(st),
    },
  ]
}

function candidateAccept(candidateIndex: number) {
  acceptTxSuggestion(props.tx.id, candidateIndex)
}
function candidateReject() {
  rejectTxSuggestion(props.tx.id)
}
</script>

<template>
  <template v-if="layout === 'desktop'">
    <tr :data-tx-id="tx.id" :class="{ 'opacity-50': tx.match_status === 'ignored' }">
      <td class="px-3 py-2 text-xs">
        {{ formatDate(tx.posted_at) }}
        <RouterLink v-if="showStatementLink" :to="`/bank/${tx.statement_id}?posting_status=unposted`"
          class="block text-neutral-400 hover:text-primary-600 hover:underline whitespace-nowrap">
          {{ t('bank.statement') }} #{{ tx.statement_id }}
        </RouterLink>
      </td>
      <td class="px-3 py-2 text-right font-mono text-xs"
        :class="tx.amount > 0 ? 'text-success-600' : 'text-danger-500'">
        {{ tx.amount > 0 ? '+' : '' }}{{ formatMoney(tx.amount, currency()) }}
      </td>
      <td v-if="showAccount" class="px-3 py-2 text-xs">
        <div class="font-mono text-neutral-700 whitespace-nowrap">{{ formatAccountNumber(tx.account_number, tx.bank_code) }}</div>
        <div v-if="tx.account_label" class="text-neutral-500 truncate">{{ tx.account_label }}</div>
      </td>
      <td class="px-3 py-2 font-mono text-xs">
        <span v-if="tx.variable_symbol">{{ tx.variable_symbol }}</span>
        <span v-else class="text-neutral-400">—</span>
        <span v-if="tx.constant_symbol" class="text-neutral-400 ml-1">/ {{ tx.constant_symbol }}</span>
      </td>
      <td class="px-3 py-2 text-xs">
        <div class="font-mono text-neutral-600">{{ tx.counterparty_account }}<span v-if="tx.counterparty_bank">/{{ tx.counterparty_bank }}</span></div>
        <div v-if="tx.counterparty_name" class="text-neutral-600">{{ tx.counterparty_name }}</div>
        <div v-if="tx.description" class="text-neutral-500 truncate max-w-xs">{{ tx.description }}</div>
      </td>
      <td class="px-3 py-2 text-xs">
        <template v-if="(tx.matched_invoices?.length ?? 0) > 1">
          <RouterLink v-for="mi in tx.matched_invoices" :key="mi.invoice_id" :to="`/invoices/${mi.invoice_id}`"
            class="text-primary-600 hover:underline block">
            {{ mi.varsymbol || `#${mi.invoice_id}` }}
          </RouterLink>
          <div v-if="tx.matched_invoices?.[0]?.client_name" class="text-neutral-500 text-xs">{{ tx.matched_invoices[0].client_name }}</div>
        </template>
        <template v-else>
          <RouterLink v-if="tx.matched_invoice_id" :to="`/invoices/${tx.matched_invoice_id}`"
            class="text-primary-600 hover:underline">
            {{ tx.matched_varsymbol || `#${tx.matched_invoice_id}` }}
          </RouterLink>
          <RouterLink v-else-if="tx.matched_purchase_invoice_id" :to="`/purchase-invoices/${tx.matched_purchase_invoice_id}`"
            class="text-primary-600 hover:underline">
            {{ tx.matched_purchase_ref || `#${tx.matched_purchase_invoice_id}` }}
          </RouterLink>
          <span v-else class="text-neutral-400">—</span>
          <div v-if="tx.matched_client_name" class="text-neutral-500 text-xs">{{ tx.matched_client_name }}</div>
          <div v-else-if="tx.matched_vendor_name" class="text-neutral-500 text-xs">{{ tx.matched_vendor_name }}</div>
        </template>
      </td>
      <td class="px-3 py-2 text-center">
        <span class="text-xs px-2 py-0.5 rounded font-medium" :class="statusBadge(tx.match_status)">
          {{ statusLabel(tx.match_status) }}
        </span>
        <button v-if="tx.match_status === 'unmatched' && suggestionFor(tx.id)" type="button"
          class="mt-1 mx-auto inline-flex items-center rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap bg-warning-50 text-warning-600"
          :aria-expanded="expandedSuggestions.has(tx.id)" :title="t('bank.match_v2.title')"
          @click="toggleSuggestion(tx.id)">
          ⏳ {{ t('bank.match_v2.badge') }}
        </button>
        <PostingStatusBadge v-if="isDoubleEntry" class="block"
          :posting="tx.posting" :currency="currency()" />
        <span v-if="isDoubleEntry && tx.period_closed" class="mt-1 inline-flex text-xs px-2 py-0.5 rounded bg-neutral-100 text-neutral-500">
          {{ t('bank.posting.period_closed_badge') }}
        </span>
        <div v-if="isDoubleEntry && tx.posting?.transfer" class="mt-1 flex flex-col items-center gap-0.5">
          <WhyChip :provenance="transferProvenance(tx)" :title="transferTooltip(tx)" />
          <RouterLink v-if="tx.posting.transfer.pair" :to="pairLink(tx.posting.transfer.pair)"
            class="text-[11px] text-primary-600 hover:underline whitespace-nowrap">
            {{ t('bank.transfer.pair_link', { date: formatDate(tx.posting.transfer.pair.posted_at) }) }}
          </RouterLink>
          <span v-else class="text-[11px] text-neutral-400 whitespace-nowrap">{{ t('bank.transfer.pair_missing') }}</span>
        </div>
      </td>
      <td class="px-3 py-2 text-right text-xs whitespace-nowrap">
        <div class="flex items-center justify-end gap-1">
          <PostingRowActions v-if="isDoubleEntry"
            :tx="tx" :currency="currency()"
            @changed="emit('changed')" @posted="onPosted" />
          <RowActionsMenu :actions="matchActions(tx)" :inline-count="0" />
        </div>
      </td>
    </tr>
    <tr v-if="expandedSuggestions.has(tx.id) && suggestionFor(tx.id)">
      <td :colspan="colspan" class="bg-warning-50/40 px-4 py-3">
        <MatchSuggestionPanel variant="panel" :suggestion="suggestionFor(tx.id)!" :reviewing="reviewingSuggestion"
          :can-review="auth.canWrite('bank.match')" @accept="candidateAccept" @reject="candidateReject" />
      </td>
    </tr>
    <tr v-if="expandedDocs.has(tx.id)">
      <td :colspan="colspan" class="bg-neutral-50 px-4 py-3">
        <LinkedDocumentsPanel entity-type="bank_transaction" :entity-id="tx.id" />
      </td>
    </tr>
  </template>

  <div v-else class="p-3 space-y-2" :class="{ 'opacity-50': tx.match_status === 'ignored' }">
    <div class="flex items-baseline justify-between gap-2">
      <div class="font-mono text-base font-semibold whitespace-nowrap"
        :class="tx.amount > 0 ? 'text-success-600' : 'text-danger-500'">
        {{ tx.amount > 0 ? '+' : '' }}{{ formatMoney(tx.amount, currency()) }}
      </div>
      <div class="flex flex-col items-end gap-1">
        <span class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap" :class="statusBadge(tx.match_status)">
          {{ statusLabel(tx.match_status) }}
        </span>
        <button v-if="tx.match_status === 'unmatched' && suggestionFor(tx.id)" type="button"
          class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap bg-warning-50 text-warning-600"
          :aria-expanded="expandedSuggestions.has(tx.id)" :title="t('bank.match_v2.title')"
          @click="toggleSuggestion(tx.id)">
          ⏳ {{ t('bank.match_v2.badge') }}
        </button>
        <PostingStatusBadge v-if="isDoubleEntry" :posting="tx.posting" :currency="currency()" />
        <span v-if="isDoubleEntry && tx.period_closed" class="inline-flex text-xs px-2 py-0.5 rounded bg-neutral-100 text-neutral-500">
          {{ t('bank.posting.period_closed_badge') }}
        </span>
        <WhyChip v-if="isDoubleEntry && tx.posting?.transfer"
          :provenance="transferProvenance(tx)" :title="transferTooltip(tx)" />
        <RouterLink v-if="isDoubleEntry && tx.posting?.transfer?.pair"
          :to="pairLink(tx.posting.transfer.pair)"
          class="text-[11px] text-primary-600 hover:underline whitespace-nowrap">
          {{ t('bank.transfer.pair_link', { date: formatDate(tx.posting.transfer.pair.posted_at) }) }}
        </RouterLink>
        <span v-else-if="isDoubleEntry && tx.posting?.transfer" class="text-[11px] text-neutral-400 whitespace-nowrap">
          {{ t('bank.transfer.pair_missing') }}
        </span>
      </div>
    </div>
    <div class="flex items-baseline justify-between text-xs text-neutral-500">
      <span class="font-mono">{{ formatDate(tx.posted_at) }}</span>
      <span class="font-mono">
        <span v-if="tx.variable_symbol">VS {{ tx.variable_symbol }}</span>
        <span v-else class="text-neutral-400">—</span>
        <span v-if="tx.constant_symbol" class="text-neutral-400 ml-1">/ {{ tx.constant_symbol }}</span>
      </span>
    </div>
    <div v-if="showAccount" class="text-xs text-neutral-500 font-mono">
      {{ formatAccountNumber(tx.account_number, tx.bank_code) }}<span v-if="tx.account_label"> — {{ tx.account_label }}</span>
    </div>
    <RouterLink v-if="showStatementLink" :to="`/bank/${tx.statement_id}?posting_status=unposted`"
      class="text-xs text-primary-600 hover:underline">
      {{ t('bank.statement') }} #{{ tx.statement_id }}
    </RouterLink>
    <div class="text-xs">
      <div class="font-mono text-neutral-600 truncate">{{ tx.counterparty_account }}<span v-if="tx.counterparty_bank">/{{ tx.counterparty_bank }}</span></div>
      <div v-if="tx.counterparty_name" class="text-neutral-600 truncate">{{ tx.counterparty_name }}</div>
      <div v-if="tx.description" class="text-neutral-500 truncate">{{ tx.description }}</div>
    </div>
    <div v-if="(tx.matched_invoices?.length ?? 0) > 1" class="text-xs">
      <RouterLink v-for="mi in tx.matched_invoices" :key="mi.invoice_id" :to="`/invoices/${mi.invoice_id}`"
        class="text-primary-600 hover:underline font-mono mr-2">
        {{ mi.varsymbol || `#${mi.invoice_id}` }}
      </RouterLink>
      <span v-if="tx.matched_invoices?.[0]?.client_name" class="text-neutral-500">{{ tx.matched_invoices[0].client_name }}</span>
    </div>
    <div v-else-if="tx.matched_invoice_id" class="text-xs">
      <RouterLink :to="`/invoices/${tx.matched_invoice_id}`"
        class="text-primary-600 hover:underline font-mono">
        {{ tx.matched_varsymbol || `#${tx.matched_invoice_id}` }}
      </RouterLink>
      <span v-if="tx.matched_client_name" class="text-neutral-500 ml-2">{{ tx.matched_client_name }}</span>
    </div>
    <div v-else-if="tx.matched_purchase_invoice_id" class="text-xs">
      <RouterLink :to="`/purchase-invoices/${tx.matched_purchase_invoice_id}`"
        class="text-primary-600 hover:underline font-mono">
        {{ tx.matched_purchase_ref || `#${tx.matched_purchase_invoice_id}` }}
      </RouterLink>
      <span v-if="tx.matched_vendor_name" class="text-neutral-500 ml-2">{{ tx.matched_vendor_name }}</span>
    </div>
    <MatchSuggestionPanel v-if="expandedSuggestions.has(tx.id) && suggestionFor(tx.id)" variant="inline"
      :suggestion="suggestionFor(tx.id)!" :reviewing="reviewingSuggestion"
      :can-review="auth.canWrite('bank.match')" @accept="candidateAccept" @reject="candidateReject" />
    <div v-if="expandedDocs.has(tx.id)" class="pt-1">
      <LinkedDocumentsPanel entity-type="bank_transaction" :entity-id="tx.id" />
    </div>
    <!-- Akce mobilní karty jedou ze stejného `matchActions(tx)` jako desktopový
         řádek. Dřív tu byla ručně psaná mřížka tlačítek se stejným obsahem:
         duplicitní, bez ikon a hlavně s `flex-1`, které třem tlačítkům přidělilo
         po třetině šířky — delší popisek („Zrušit spárování") se pak lámal
         doprostřed tlačítka. RowActionsMenu drží popisky vcelku a zbytek schová
         do „…", takže karta má jeden úhledný pruh akcí místo dvou rozsypaných.

         `inline-count` je 1 schválně: vedle akce zaúčtování (~132 px) se do
         308 px široké karty na 390px displeji vejde právě jedno tlačítko a „…".
         Při dvou se pruh zalomil. První akce v `matchActions` je vždycky ta
         stavově relevantní — Otevřít u spárovaného pohybu, Spárovat u ostatních
         — takže na očích zůstane to, co uživatel na daném řádku opravdu chce. -->
    <div class="flex flex-wrap items-center justify-end gap-1.5 pt-1">
      <PostingRowActions v-if="isDoubleEntry"
        :tx="tx" :currency="currency()"
        @changed="emit('changed')" @posted="onPosted" />
      <RowActionsMenu :actions="matchActions(tx)" :inline-count="1" />
    </div>
  </div>
  <Teleport to="body">
    <RuleFormModal v-if="ruleTemplateOpen" :prefill="rulePrefill" :base-amount="Math.abs(tx.amount)"
      @saved="ruleTemplateOpen = false; emit('changed')" @close="ruleTemplateOpen = false" />
  </Teleport>
</template>
