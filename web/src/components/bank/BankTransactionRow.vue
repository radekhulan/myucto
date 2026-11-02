<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { formatAccountNumber } from '@/utils/bankAccount'
import type { BankTransaction, BankTransactionJournalNote } from '@/api/bank'
import type { JournalNote } from '@/api/accounting'
import BankTransactionNoteModal from './BankTransactionNoteModal.vue'
import type { AutomationProvenance } from '@/api/automation'
import type { BankPostingRule, BankPostingRulePayload, PostResult, RuleCreateResult } from '@/api/bankPosting'
import PostingRowActions from './PostingRowActions.vue'
import RuleFormModal from './RuleFormModal.vue'
import RepostModal from '@/components/accounting/RepostModal.vue'
import PostingStatusBadge from './PostingStatusBadge.vue'
import BankTransactionJournalLink from './BankTransactionJournalLink.vue'
import { useBankJournalNavigation } from './useBankJournalNavigation'
import MatchSuggestionPanel from './MatchSuggestionPanel.vue'
import WhyChip from '@/components/automation/WhyChip.vue'
import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'
import DocumentDimensionsPanel from '@/components/dimensions/DocumentDimensionsPanel.vue'
import DimensionChips from '@/components/dimensions/DimensionChips.vue'
import { useDimensions } from '@/composables/useDimensions'
import type { DimensionMap } from '@/api/dimensions'
import RowActionsMenu, { type RowAction } from '@/components/ui/RowActionsMenu.vue'
import WorkspaceTeleport from '@/components/workspace/WorkspaceTeleport.vue'
import { BTN_ICON_SM_BASE, OUTLINE, ICONS } from '@/components/ui/buttonStyles'
import type { BankTransactionActions } from '@/composables/useBankTransactionActions'
import { useSupplierStore } from '@/stores/supplier'
import { hasFrameworkScope } from '@/security/frameworkScope'
import { usePropertyBankLinks } from './usePropertyBankLinks'

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
  showCounterAccount?: boolean
  /** Odkaz zpět na výpis (#N) — u detailu zbytečný (jsme už v něm), u „Všechny pohyby" navigační pomůcka. */
  showStatementLink?: boolean
  /** Počet sloupců tabulky (desktop) pro colspan expand řádků. */
  colspan?: number
  actions: BankTransactionActions
}>(), {
  fallbackCurrency: null,
  showAccount: false,
  showCounterAccount: false,
  showStatementLink: false,
  colspan: 7,
})

const emit = defineEmits<{
  posted: [payload: { result: PostResult; debit: string; credit: string }, tx: BankTransaction]
  changed: []
}>()

const { t } = useI18n()
const auth = useAuthStore()
const journalNavigation = useBankJournalNavigation()

/** Spárovaná faktura → návrh dimenzí pohybu (její dimenze, jinak výchozí zakázky/klienta). */
const dimensionPrefill = computed(() => props.tx.matched_invoice_id
  ? { invoice_id: props.tx.matched_invoice_id }
  : props.tx.matched_purchase_invoice_id
    ? { purchase_invoice_id: props.tx.matched_purchase_invoice_id }
    : null)

const ruleTemplateOpen = ref(false)

// Dimenze pohybu (Firma → Dimenze): štítky v řádku a vlastní rozbalovací editor.
// Dřív byl editor jen v panelu „Dokumenty" a v řádku nebylo nic vidět.
const dims = useDimensions()
const dimensionsAvailable = computed(() => !auth.isClientRole && dims.enabled.value && dims.documentTypes.value.length > 0)
const dimensionsOpen = ref(false)
const savedDimensions = ref<DimensionMap | null>(null)
const txDimensions = computed<DimensionMap>(() => savedDimensions.value ?? props.tx.dimensions ?? {})
const hasDimensions = computed(() => Object.values(txDimensions.value).some(v => !!v))
// Číselník je sdílený a načte se jednou za firmu, ne za každý řádek.
onMounted(() => { if (!auth.isClientRole) dims.load().catch(() => { /* bez číselníku se dimenze jen neukážou */ }) })
function toggleDimensions() {
  dimensionsOpen.value = !dimensionsOpen.value
}
// Poznámka pohybu = poznámka jeho zápisu v deníku (jediná pravda, žádné vlastní
// úložiště). Bez zápisu ji není kam uložit, proto akce jen s vysvětlením zakázaná.
const journalEntryId = computed(() => props.tx.posting?.journal_entry_id ?? null)
const canWriteNote = computed(() => props.isDoubleEntry && auth.canWrite('accounting'))
const noteOpen = ref(false)
const savedNotes = ref<BankTransactionJournalNote[] | null>(null)
const journalNotes = computed(() => savedNotes.value ?? props.tx.posting?.journal_notes ?? [])
watch(() => props.tx, () => { savedNotes.value = null })
function openNote() {
  if (journalEntryId.value && canWriteNote.value) noteOpen.value = true
}
function onNotesChanged(notes: JournalNote[]) {
  savedNotes.value = notes.map(n => ({ id: n.id, body: n.body, pinned: n.pinned }))
}

const payrollMatched = computed(() => props.tx.resolution === 'payroll' || props.tx.posting?.payroll_matched === true)
// Vlastní převod fakturu nečeká: jeho protějškem je druhá noha na jiném vlastním účtu.
const ownTransfer = computed(() => props.tx.match_status === 'unmatched' && (props.tx.resolution === 'transfer' || props.tx.posting?.transfer != null))
// Zaúčtováno mimo saldokontní účty (daň, odvod, poplatek, splátka) — doklad k tomu
// neexistuje a nikdy nebude, takže „Nespárováno" je tu jen falešný poplach.
const outsideSaldo = computed(() => props.tx.match_status === 'unmatched'
  && (props.tx.resolution === 'outside_saldo' || props.tx.posting?.outside_saldo === true))
// Avízo, jehož pohyb převzal oficiální výpis: je vyřešené, úhradu nese výpis.
const supersededNotice = computed(() => props.tx.notice_superseded === true)
// Pohyb přiřazený ostatní pohledávce či závazku: doklad má, jen to není faktura.
const otherItems = computed(() => props.tx.match_status === 'unmatched' ? props.tx.matched_other_items ?? [] : [])
const otherItemMatched = computed(() => otherItems.value.length > 0)
const otherItemLinks = computed(() => auth.canRead('other_items') ? otherItems.value : [])
const noInvoiceExpected = computed(() => payrollMatched.value || ownTransfer.value || outsideSaldo.value || supersededNotice.value || otherItemMatched.value)
// Úhrady jednotek SVJ: řádek se jen přihlásí o vazbu, dotaz jde jednou za dávku řádků.
// Firma bez profilu SVJ se neptá vůbec, SVJ bez povolených vazeb jen jednou (usePropertyBankLinks).
const supplierStore = useSupplierStore()
const propertyLinks = usePropertyBankLinks()
const propertyReadable = computed(() => !auth.isClientRole && auth.canRead('property') && props.tx.amount > 0
  && hasFrameworkScope(supplierStore.currentSupplier, 'svj'))
watch(() => props.tx.id, id => { if (propertyReadable.value) propertyLinks.track(supplierStore.currentSupplierId, id) }, { immediate: true })
const propertyLink = computed(() => propertyReadable.value ? propertyLinks.link(supplierStore.currentSupplierId, props.tx.id) : null)
const propertyUsed = computed(() => !!propertyLink.value?.source)
const propertyMatchable = computed(() => propertyReadable.value && propertyLinks.enabled(supplierStore.currentSupplierId)
  && !propertyUsed.value && props.tx.match_status === 'unmatched' && !noInvoiceExpected.value && props.tx.source !== 'email_notice')
const propertyProposal = computed(() => propertyMatchable.value ? propertyLink.value?.proposal ?? null : null)
const propertyPaymentTo = computed<RouteLocationRaw>(() => ({ path: '/property/payments', query: { tab: 'receive', bank_transaction: String(props.tx.id) } }))
const statusText = computed(() => supersededNotice.value
  ? t('bank.match_status.superseded')
  : payrollMatched.value ? t('bank.match_status.payroll')
    : ownTransfer.value ? t('bank.match_status.transfer')
      : otherItemMatched.value ? t('bank.match_status.other_item')
        : outsideSaldo.value ? t('bank.match_status.outside_saldo') : statusLabel(props.tx.match_status))
const statusBadgeClass = computed(() => statusBadge(otherItemMatched.value
  ? 'manual'
  : supersededNotice.value || outsideSaldo.value
  ? 'ignored'
  : noInvoiceExpected.value ? 'auto_exact' : props.tx.match_status))
// Štítek „Nespárováno" je zkratka k párování — stejná podmínka jako akce „Spárovat" v menu.
const badgeMatchable = computed(() => !noInvoiceExpected.value && props.actions.canRunDetailAction(props.tx, 'match'))
const statusTitle = computed(() => supersededNotice.value
  ? t('bank.notice_superseded_hint')
  : otherItemMatched.value ? t('bank.other_item_matched_hint')
  : outsideSaldo.value ? t('bank.outside_saldo_hint')
    : badgeMatchable.value ? t('bank.match_badge_hint') : undefined)
// Dialog přeúčtování drží řádek, ne PostingRowActions: řádek se vykresluje ve
// dvou podobách (tabulka i karta) a dvě instance dialogu by si přebíjely stav.
const repostTx = ref<RowTx | null>(null)
/** Kontace nového pravidla, kterou přeúčtování předvyplní (pravidlo z už zaúčtovaného pohybu). */
const repostProposal = ref<{ debit: string; credit: string } | null>(null)
// Pravidlo z konkrétního pohybu: pás ±10 % (bez něj plná automatika pravidlo jen navrhuje)
// a priorita 40, aby přebilo systémová rozpoznání i obecná pravidla ze šablon (90–100).
const RULE_FROM_TX_PRIORITY = 40
const RULE_FROM_TX_BAND = 0.1
const rulePrefill = computed<BankPostingRulePayload>(() => ({
  name: props.tx.counterparty_name || '',
  is_active: true,
  direction: props.tx.amount > 0 ? 'incoming' : 'outgoing',
  counterparty_account: props.tx.counterparty_account,
  counterparty_bank: props.tx.counterparty_bank,
  counterparty_prefix: null,
  variable_symbol: props.tx.variable_symbol,
  message_contains: props.tx.description || null,
  amount_min: Math.floor(Math.abs(props.tx.amount) * (1 - RULE_FROM_TX_BAND)),
  amount_max: Math.ceil(Math.abs(props.tx.amount) * (1 + RULE_FROM_TX_BAND)),
  priority: RULE_FROM_TX_PRIORITY,
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

/** Lidský popisek, proč automat pohyb nespároval (#46); neznámý kód se nezobrazí. */
function reasonLabel(reason: string | null | undefined): string {
  if (!reason) return ''
  const key = `bank.match_reason.${reason}`
  const label = t(key)
  return label === key ? '' : label
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

function onRuleSaved(rule: BankPostingRule, result?: RuleCreateResult) {
  ruleTemplateOpen.value = false
  const src = result?.source_result
  if (src?.status === 'already_posted' && src.same_accounts === false && auth.canWrite('accounting')) {
    repostProposal.value = { debit: rule.debit_account_code, credit: rule.credit_account_code }
    repostTx.value = props.tx
  }
  emit('changed')
}

function closeRepost() {
  repostTx.value = null
  repostProposal.value = null
}

function onPosted(payload: { result: PostResult; debit: string; credit: string }) {
  emit('posted', payload, props.tx)
}

// Řádkové akce párování banky → 2 tlačítka inline, zbytek do „…" popupu (RowActionsMenu).
function matchActions(tx: BankTransaction): RowAction[] {
  const st = tx.match_status
  const cashJournal = journalNavigation.cashTarget(tx)
  const canMatch = props.actions.canRunDetailAction(tx, 'match')
  return [
    {
      key: 'open-cash-journal', label: t('bank.open_cash_journal'), icon: 'doc', variant: 'neutral',
      to: cashJournal ?? undefined, show: !!cashJournal, menuOnly: true, workspaceOpen: 'beside',
      onNavigate: event => { void journalNavigation.open(event, cashJournal) },
    },
    {
      key: 'open-inv', label: t('bank.open'), icon: 'doc', variant: 'neutral',
      to: tx.matched_invoice_id ? `/invoices/${tx.matched_invoice_id}` : undefined,
      show: !!tx.matched_invoice_id && auth.canRead('invoices'),
    },
    {
      key: 'open-pi', label: t('bank.open'), icon: 'doc', variant: 'neutral',
      to: tx.matched_purchase_invoice_id ? `/purchase-invoices/${tx.matched_purchase_invoice_id}` : undefined,
      show: !tx.matched_invoice_id && !!tx.matched_purchase_invoice_id && auth.canRead('purchase_invoices'),
    },
    {
      key: 'property-unit', label: t('bank.property_payment.match_unit'), icon: 'link', variant: 'primary',
      to: propertyPaymentTo.value, show: propertyMatchable.value,
    },
    {
      key: 'property-payment', label: t('bank.property_payment.open'), icon: 'doc', variant: 'neutral',
      to: propertyPaymentTo.value, show: propertyUsed.value,
    },
    {
      key: 'match', label: t('bank.match'), icon: 'link', variant: 'primary',
      run: () => startMatch(tx),
      show: canMatch && (st === 'unmatched' || st === 'auto_partial'),
    },
    {
      key: 'create-purchase', label: t('bank.create_purchase'), icon: 'plus', variant: 'primary',
      run: () => openCreate(tx),
      show: props.actions.canRunDetailAction(tx, 'create'),
    },
    {
      key: 'request-document', label: t('bank.document_request.action'), icon: 'doc', variant: 'warning',
      run: () => openRequestDoc(tx),
      show: props.actions.canRunDetailAction(tx, 'request'),
    },
    {
      key: 'documents', label: expandedDocs.value.has(tx.id) ? t('bank.documents_hide') : t('bank.documents_action'),
      icon: 'doc', variant: 'neutral',
      run: () => toggleDocs(tx.id),
      show: !auth.isClientRole && auth.canRead('documents'),
    },
    {
      key: 'dimensions', label: dimensionsOpen.value ? t('bank.dimensions_hide') : t('bank.dimensions_action'),
      icon: 'tag', variant: 'neutral',
      run: toggleDimensions,
      show: dimensionsAvailable.value,
    },
    {
      key: 'create-posting-rule', label: t('bank.posting.create_rule_from_movement'), icon: 'doc', variant: 'neutral',
      run: () => { ruleTemplateOpen.value = true },
      show: !tx.posting?.payroll_matched && props.isDoubleEntry && auth.canWrite('bank.rules') && tx.source !== 'email_notice' && st !== 'ignored',
    },
    {
      key: 'ignore', label: t('bank.ignore'), icon: 'x', variant: 'neutral',
      run: () => ignoreTx(tx),
      show: props.actions.canRunDetailAction(tx, 'ignore'),
    },
    {
      key: 'unmatch', label: t(tx.match_status === 'ignored' ? 'bank.unignore' : 'bank.unmatch'), icon: 'uturn', variant: 'neutral',
      run: () => unmatchTx(tx),
      show: props.actions.canRunDetailAction(tx, 'unmatch'),
    },
    /*
     * Přeúčtování je vzácný administrativní zásah do už hotového zápisu, proto
     * patří do „…", ne mezi tlačítka řádku — dvě plná tlačítka pod sebou
     * dělala z každého zaúčtovaného řádku dva pruhy. Dialog vlastní tenhle
     * řádek (ne `PostingRowActions`), protože řádek se vykresluje ve dvou
     * podobách (tabulka a karta) a dvě instance dialogu by si přebíjely stav.
     */
    {
      key: 'note', label: t('bank.note.action'), icon: 'doc', variant: 'primary',
      run: openNote,
      show: canWriteNote.value,
      disabled: !journalEntryId.value,
      disabledReason: journalEntryId.value ? undefined : t('bank.note.no_entry'),
    },
    {
      key: 'repost', label: t('accounting.repost.action'), icon: 'edit', variant: 'warning',
      run: () => { repostProposal.value = null; repostTx.value = tx },
      show: !tx.posting?.payroll_posting_blocked && props.isDoubleEntry && tx.posting?.status === 'posted' && auth.canWrite('accounting'),
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
      <td class="px-3 py-2 whitespace-nowrap">
        {{ formatDate(tx.posted_at) }}
        <RouterLink v-if="showStatementLink" :to="`/bank/${tx.statement_id}?posting_status=unposted`"
          class="block text-xs text-neutral-400 hover:text-primary-600 hover:underline whitespace-nowrap">
          {{ t('bank.statement') }} #{{ tx.statement_id }}
        </RouterLink>
      </td>
      <td class="px-3 py-2 text-right font-mono"
        :class="tx.amount > 0 ? 'text-success-700 dark:text-success-600' : 'text-danger-500'">
        {{ tx.amount > 0 ? '+' : '' }}{{ formatMoney(tx.amount, currency()) }}
      </td>
      <td v-if="showAccount" class="px-3 py-2">
        <div class="font-mono text-neutral-700 whitespace-nowrap">{{ formatAccountNumber(tx.account_number, tx.bank_code) }}</div>
        <div v-if="tx.account_label" class="text-xs text-neutral-500 truncate">{{ tx.account_label }}</div>
      </td>
      <td class="px-3 py-2 font-mono text-xs">
        <span v-if="tx.variable_symbol">{{ tx.variable_symbol }}</span>
        <span v-else class="text-neutral-400">—</span>
        <span v-if="tx.constant_symbol" class="text-neutral-400 ml-1">/ {{ tx.constant_symbol }}</span>
      </td>
      <td class="px-3 py-2">
        <div class="font-mono text-xs text-neutral-600">{{ tx.counterparty_account }}<span v-if="tx.counterparty_bank">/{{ tx.counterparty_bank }}</span></div>
        <div v-if="tx.counterparty_name" class="text-neutral-600">{{ tx.counterparty_name }}</div>
        <div v-if="tx.card_last4" class="text-xs text-neutral-600" data-test="tx-card">
          {{ t('payment_cards.tx_card', { last4: tx.card_last4 }) }}<template v-if="tx.card"> · {{ tx.card.holder || tx.card.label }}</template>
        </div>
        <div v-if="tx.match_status === 'ignored' && tx.ignore_note" class="text-xs text-neutral-600 whitespace-pre-wrap break-words max-w-xs">{{ t('bank.ignore_note_label') }}: {{ tx.ignore_note }}</div>
        <div v-if="tx.description" class="text-xs text-neutral-500 truncate max-w-xs">{{ tx.description }}</div>
        <button v-for="n in journalNotes" :key="n.id" type="button" data-test="tx-journal-note"
          class="mt-1 flex max-w-xs items-start gap-1 text-left text-xs cursor-pointer disabled:cursor-default"
          :class="n.pinned ? 'text-warning-600' : 'text-neutral-700'"
          :title="t('bank.note.from_journal')" :disabled="!canWriteNote" @click="openNote">
          <svg class="mt-0.5 h-3 w-3 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
          <span class="whitespace-pre-wrap break-words line-clamp-3">{{ n.body }}</span>
        </button>
        <button v-if="dimensionsAvailable && hasDimensions" type="button"
          class="cursor-pointer mt-1 flex text-left" :title="t('bank.dimensions_action')" data-test="tx-dimensions"
          @click="toggleDimensions">
          <DimensionChips :dimensions="txDimensions" />
        </button>
      </td>
      <td class="px-3 py-2">
        <template v-if="(tx.matched_invoices?.length ?? 0) > 1 && auth.canRead('invoices')">
          <RouterLink v-for="mi in tx.matched_invoices" :key="mi.invoice_id" :to="`/invoices/${mi.invoice_id}`"
            class="text-primary-600 hover:underline block">
            {{ mi.varsymbol || `#${mi.invoice_id}` }}
          </RouterLink>
          <div v-if="tx.matched_invoices?.[0]?.client_name" class="text-neutral-500 text-xs">{{ tx.matched_invoices[0].client_name }}</div>
        </template>
        <template v-else-if="(tx.matched_purchase_invoices?.length ?? 0) > 1 && auth.canRead('purchase_invoices')">
          <div v-for="invoice in tx.matched_purchase_invoices" :key="invoice.purchase_invoice_id">
            <RouterLink :to="`/purchase-invoices/${invoice.purchase_invoice_id}`" class="text-primary-600 hover:underline">
              {{ invoice.ref || `#${invoice.purchase_invoice_id}` }}
            </RouterLink>
            <div v-if="invoice.vendor_name" class="text-neutral-500 text-xs">{{ invoice.vendor_name }}</div>
          </div>
        </template>
        <template v-else>
          <RouterLink v-if="tx.matched_invoice_id && auth.canRead('invoices')" :to="`/invoices/${tx.matched_invoice_id}`"
            class="text-primary-600 hover:underline">
            {{ tx.matched_varsymbol || `#${tx.matched_invoice_id}` }}
          </RouterLink>
          <RouterLink v-else-if="tx.matched_purchase_invoice_id && auth.canRead('purchase_invoices')" :to="`/purchase-invoices/${tx.matched_purchase_invoice_id}`"
            class="text-primary-600 hover:underline">
            {{ tx.matched_purchase_ref || `#${tx.matched_purchase_invoice_id}` }}
          </RouterLink>
          <template v-else-if="otherItemLinks.length > 0">
            <div v-for="item in otherItemLinks" :key="item.other_item_id" data-test="tx-other-item">
              <RouterLink :to="`/other-items/${item.other_item_id}`" class="text-primary-600 hover:underline">
                {{ item.document_no || `#${item.other_item_id}` }}
              </RouterLink>
              <div v-if="item.partner_name" class="text-neutral-500 text-xs">{{ item.partner_name }}</div>
            </div>
          </template>
          <span v-else class="text-neutral-400">—</span>
          <div v-if="tx.matched_client_name" class="text-neutral-500 text-xs">{{ tx.matched_client_name }}</div>
          <div v-else-if="tx.matched_vendor_name" class="text-neutral-500 text-xs">{{ tx.matched_vendor_name }}</div>
        </template>
      </td>
      <td class="px-3 py-2 text-center">
        <component :is="badgeMatchable ? 'button' : 'span'" :type="badgeMatchable ? 'button' : undefined"
          class="text-xs px-2 py-0.5 rounded font-medium" data-testid="tx-status-badge"
          :class="[statusBadgeClass,
            badgeMatchable ? 'cursor-pointer hover:brightness-95 hover:underline' : '']"
          :title="statusTitle" @click="badgeMatchable && startMatch(tx)">
          {{ statusText }}
        </component>
        <div v-if="!noInvoiceExpected && tx.match_status === 'unmatched' && reasonLabel(tx.match_reason)"
          class="mt-1 text-[11px] leading-tight text-neutral-500" :title="t('bank.match_reason_title')">
          {{ reasonLabel(tx.match_reason) }}
        </div>
        <button v-if="!noInvoiceExpected && tx.match_status === 'unmatched' && suggestionFor(tx.id)" type="button"
          class="mt-1 mx-auto inline-flex items-center rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap bg-warning-50 text-warning-600"
          :aria-expanded="expandedSuggestions.has(tx.id)" :title="t('bank.match_v2.title')"
          @click="toggleSuggestion(tx.id)">
          ⏳ {{ t('bank.match_v2.badge') }}
        </button>
        <RouterLink v-if="propertyUsed" :to="propertyPaymentTo" data-test="tx-property-payment"
          class="mt-1 block text-[11px] text-primary-600 hover:underline whitespace-nowrap">
          {{ propertyLink?.workflow_id ? t('bank.property_payment.used_workflow', { id: propertyLink.workflow_id }) : t('bank.property_payment.used') }}
        </RouterLink>
        <RouterLink v-else-if="propertyProposal && propertyProposal.status !== 'unidentified'" :to="propertyPaymentTo" data-test="tx-property-proposal"
          class="mt-1 mx-auto inline-flex items-center rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap bg-warning-50 text-warning-600 hover:underline">
          {{ t(`bank.property_payment.proposal.${propertyProposal.status}`) }}
        </RouterLink>
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
      <td v-if="showCounterAccount" class="px-3 py-2 font-mono text-xs whitespace-nowrap">
        {{ tx.posting?.counter_account_codes?.join(', ') || '-' }}
      </td>
      <td class="px-3 py-2 text-right text-xs whitespace-nowrap">
        <!-- Šířka pro „Zrušit zaúčtování" a dvě ikony na jednom řádku: akce zaberou nejvýš dva řádky. -->
        <div class="flex flex-wrap items-center justify-end gap-1 min-w-60">
          <BankTransactionJournalLink :tx="tx" />
          <PostingRowActions v-if="isDoubleEntry"
            :tx="tx" :currency="currency()"
            @changed="emit('changed')" @posted="onPosted" />
          <RowActionsMenu :actions="matchActions(tx)" :inline-count="0" />
          <button type="button" @click="actions.textDetail.value = tx" :title="t('bank.show_transaction_text')" :aria-label="t('bank.show_transaction_text')"
            :class="[BTN_ICON_SM_BASE, OUTLINE.primary]">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
          </button>
        </div>
      </td>
    </tr>
    <tr v-if="!payrollMatched && expandedSuggestions.has(tx.id) && suggestionFor(tx.id)">
      <td :colspan="colspan" class="bg-warning-50/40 px-4 py-3">
        <MatchSuggestionPanel variant="panel" :suggestion="suggestionFor(tx.id)!" :reviewing="reviewingSuggestion"
          :can-review="actions.canReviewSuggestion(suggestionFor(tx.id)!)" :can-accept-candidate="actions.canAcceptCandidate" @accept="candidateAccept" @reject="candidateReject" />
      </td>
    </tr>
    <tr v-if="expandedDocs.has(tx.id)">
      <td :colspan="colspan" class="bg-neutral-50 px-4 py-3">
        <LinkedDocumentsPanel entity-type="bank_transaction" :entity-id="tx.id" />
        <LinkedDocumentsPanel v-if="tx.posting?.journal_entry_id" class="mt-3 block"
          entity-type="journal_entry" :entity-id="tx.posting.journal_entry_id" readonly
          :title="t('bank.journal_documents')" />
      </td>
    </tr>
    <!-- Dimenze pohybu (Firma → Dimenze) — promítnou se i do jeho zaúčtování. -->
    <tr v-if="dimensionsAvailable && dimensionsOpen">
      <td :colspan="colspan" class="bg-neutral-50 px-4 py-3">
        <DocumentDimensionsPanel doc-type="bank-transactions" :doc-id="tx.id" :prefill="dimensionPrefill" @saved="savedDimensions = $event" />
      </td>
    </tr>
  </template>

  <div v-else class="p-3 space-y-2" :class="{ 'opacity-50': tx.match_status === 'ignored' }">
    <div class="flex items-baseline justify-between gap-2">
      <div class="font-mono text-sm font-semibold whitespace-nowrap"
        :class="tx.amount > 0 ? 'text-success-700 dark:text-success-600' : 'text-danger-500'">
        {{ tx.amount > 0 ? '+' : '' }}{{ formatMoney(tx.amount, currency()) }}
      </div>
      <div class="flex flex-col items-end gap-1">
        <component :is="badgeMatchable ? 'button' : 'span'" :type="badgeMatchable ? 'button' : undefined"
          class="text-xs px-2 py-0.5 rounded font-medium whitespace-nowrap" data-testid="tx-status-badge"
          :class="[statusBadgeClass,
            badgeMatchable ? 'cursor-pointer hover:brightness-95 hover:underline' : '']"
          :title="statusTitle" @click="badgeMatchable && startMatch(tx)">
          {{ statusText }}
        </component>
        <span v-if="!noInvoiceExpected && tx.match_status === 'unmatched' && reasonLabel(tx.match_reason)"
          class="text-[11px] leading-tight text-right text-neutral-500" :title="t('bank.match_reason_title')">
          {{ reasonLabel(tx.match_reason) }}
        </span>
        <button v-if="!noInvoiceExpected && tx.match_status === 'unmatched' && suggestionFor(tx.id)" type="button"
          class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap bg-warning-50 text-warning-600"
          :aria-expanded="expandedSuggestions.has(tx.id)" :title="t('bank.match_v2.title')"
          @click="toggleSuggestion(tx.id)">
          ⏳ {{ t('bank.match_v2.badge') }}
        </button>
        <RouterLink v-if="propertyUsed" :to="propertyPaymentTo" data-test="tx-property-payment"
          class="text-[11px] text-primary-600 hover:underline whitespace-nowrap">
          {{ propertyLink?.workflow_id ? t('bank.property_payment.used_workflow', { id: propertyLink.workflow_id }) : t('bank.property_payment.used') }}
        </RouterLink>
        <RouterLink v-else-if="propertyProposal && propertyProposal.status !== 'unidentified'" :to="propertyPaymentTo" data-test="tx-property-proposal"
          class="inline-flex items-center rounded px-2 py-0.5 text-xs font-medium whitespace-nowrap bg-warning-50 text-warning-600 hover:underline">
          {{ t(`bank.property_payment.proposal.${propertyProposal.status}`) }}
        </RouterLink>
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
    <div v-if="showCounterAccount && tx.posting?.counter_account_codes?.length" class="text-xs text-neutral-500">
      {{ t('bank.counter_account') }}: <span class="font-mono">{{ tx.posting.counter_account_codes.join(', ') }}</span>
    </div>
    <div v-if="showAccount" class="text-xs text-neutral-500 font-mono">
      {{ formatAccountNumber(tx.account_number, tx.bank_code) }}<span v-if="tx.account_label"> - {{ tx.account_label }}</span>
    </div>
    <RouterLink v-if="showStatementLink" :to="`/bank/${tx.statement_id}?posting_status=unposted`"
      class="text-xs text-primary-600 hover:underline">
      {{ t('bank.statement') }} #{{ tx.statement_id }}
    </RouterLink>
    <div class="text-xs">
      <div class="font-mono text-neutral-600 truncate">{{ tx.counterparty_account }}<span v-if="tx.counterparty_bank">/{{ tx.counterparty_bank }}</span></div>
      <div v-if="tx.counterparty_name" class="text-sm font-medium text-neutral-900 truncate">{{ tx.counterparty_name }}</div>
      <div v-if="tx.card_last4" class="text-neutral-600 truncate">
        {{ t('payment_cards.tx_card', { last4: tx.card_last4 }) }}<template v-if="tx.card"> · {{ tx.card.holder || tx.card.label }}</template>
      </div>
      <div v-if="tx.match_status === 'ignored' && tx.ignore_note" class="text-neutral-600 whitespace-pre-wrap break-words max-w-xs">{{ t('bank.ignore_note_label') }}: {{ tx.ignore_note }}</div>
        <div v-if="tx.description" class="text-neutral-500 truncate">{{ tx.description }}</div>
      <button v-for="n in journalNotes" :key="n.id" type="button"
        class="mt-1 flex items-start gap-1 text-left cursor-pointer disabled:cursor-default"
        :class="n.pinned ? 'text-warning-600' : 'text-neutral-700'"
        :title="t('bank.note.from_journal')" :disabled="!canWriteNote" @click="openNote">
        <svg class="mt-0.5 h-3 w-3 shrink-0 text-neutral-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
        <span class="whitespace-pre-wrap break-words line-clamp-3">{{ n.body }}</span>
      </button>
      <button v-if="dimensionsAvailable && hasDimensions" type="button"
        class="cursor-pointer mt-1 flex text-left" :title="t('bank.dimensions_action')" @click="toggleDimensions">
        <DimensionChips :dimensions="txDimensions" />
      </button>
    </div>
    <div v-if="(tx.matched_invoices?.length ?? 0) > 1 && auth.canRead('invoices')" class="text-xs">
      <RouterLink v-for="mi in tx.matched_invoices" :key="mi.invoice_id" :to="`/invoices/${mi.invoice_id}`"
        class="text-primary-600 hover:underline font-mono mr-2">
        {{ mi.varsymbol || `#${mi.invoice_id}` }}
      </RouterLink>
      <span v-if="tx.matched_invoices?.[0]?.client_name" class="text-neutral-500">{{ tx.matched_invoices[0].client_name }}</span>
    </div>
    <div v-else-if="(tx.matched_purchase_invoices?.length ?? 0) > 1 && auth.canRead('purchase_invoices')" class="text-xs">
      <div v-for="invoice in tx.matched_purchase_invoices" :key="invoice.purchase_invoice_id">
        <RouterLink :to="`/purchase-invoices/${invoice.purchase_invoice_id}`" class="text-primary-600 hover:underline">
          {{ invoice.ref || `#${invoice.purchase_invoice_id}` }}
        </RouterLink>
        <span v-if="invoice.vendor_name" class="text-neutral-500"> · {{ invoice.vendor_name }}</span>
      </div>
    </div>
    <div v-else-if="tx.matched_invoice_id && auth.canRead('invoices')" class="text-xs">
      <RouterLink :to="`/invoices/${tx.matched_invoice_id}`"
        class="text-primary-600 hover:underline font-mono">
        {{ tx.matched_varsymbol || `#${tx.matched_invoice_id}` }}
      </RouterLink>
      <span v-if="tx.matched_client_name" class="text-neutral-500 ml-2">{{ tx.matched_client_name }}</span>
    </div>
    <div v-else-if="tx.matched_purchase_invoice_id && auth.canRead('purchase_invoices')" class="text-xs">
      <RouterLink :to="`/purchase-invoices/${tx.matched_purchase_invoice_id}`"
        class="text-primary-600 hover:underline font-mono">
        {{ tx.matched_purchase_ref || `#${tx.matched_purchase_invoice_id}` }}
      </RouterLink>
      <span v-if="tx.matched_vendor_name" class="text-neutral-500 ml-2">{{ tx.matched_vendor_name }}</span>
    </div>
    <div v-else-if="otherItemLinks.length > 0" class="text-xs">
      <div v-for="item in otherItemLinks" :key="item.other_item_id" data-test="tx-other-item">
        <RouterLink :to="`/other-items/${item.other_item_id}`" class="text-primary-600 hover:underline font-mono">
          {{ item.document_no || `#${item.other_item_id}` }}
        </RouterLink>
        <span v-if="item.partner_name" class="text-neutral-500 ml-2">{{ item.partner_name }}</span>
      </div>
    </div>
    <MatchSuggestionPanel v-if="!payrollMatched && expandedSuggestions.has(tx.id) && suggestionFor(tx.id)" variant="inline"
      :suggestion="suggestionFor(tx.id)!" :reviewing="reviewingSuggestion"
      :can-review="actions.canReviewSuggestion(suggestionFor(tx.id)!)" :can-accept-candidate="actions.canAcceptCandidate" @accept="candidateAccept" @reject="candidateReject" />
    <div v-if="expandedDocs.has(tx.id)" class="pt-1">
      <LinkedDocumentsPanel entity-type="bank_transaction" :entity-id="tx.id" />
      <!-- Dokument připojený v deníku k zápisu pohybu patří i k pohybu; připojuje se tam. -->
      <LinkedDocumentsPanel v-if="tx.posting?.journal_entry_id" class="mt-3 block"
        entity-type="journal_entry" :entity-id="tx.posting.journal_entry_id" readonly
        :title="t('bank.journal_documents')" />
    </div>
    <DocumentDimensionsPanel v-if="dimensionsAvailable && dimensionsOpen" class="mt-1"
      doc-type="bank-transactions" :doc-id="tx.id" :prefill="dimensionPrefill" @saved="savedDimensions = $event" />
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
      <BankTransactionJournalLink :tx="tx" />
      <PostingRowActions v-if="isDoubleEntry"
        :tx="tx" :currency="currency()"
        @changed="emit('changed')" @posted="onPosted" />
      <RowActionsMenu :actions="matchActions(tx)" :inline-count="1" />
          <button type="button" @click="actions.textDetail.value = tx" :title="t('bank.show_transaction_text')" :aria-label="t('bank.show_transaction_text')"
            :class="[BTN_ICON_SM_BASE, OUTLINE.primary]">
            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
          </button>
    </div>
  </div>
  <WorkspaceTeleport>
    <RepostModal v-if="repostTx" :open="true" source="bank-transactions" :doc-id="repostTx.id"
      :doc-label="repostTx.description || repostTx.variable_symbol"
      :proposed-accounts="repostProposal"
      @close="closeRepost" @reposted="closeRepost(); emit('changed')"
      @dimensions-saved="closeRepost(); emit('changed')" />
    <BankTransactionNoteModal v-if="noteOpen && journalEntryId" :entry-id="journalEntryId"
      :document-no="tx.posting?.document_no" @changed="onNotesChanged" @close="noteOpen = false" />
    <RuleFormModal v-if="ruleTemplateOpen" :prefill="rulePrefill" :base-amount="Math.abs(tx.amount)"
      :source-transaction-id="tx.id"
      @saved="onRuleSaved" @close="ruleTemplateOpen = false" />
  </WorkspaceTeleport>
</template>
