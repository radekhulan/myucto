<script setup lang="ts">
import { ref, reactive, computed, watch, onMounted, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { accountPickerOptions } from '@/utils/chartAccountOptions'
import { useToast } from '@/composables/useToast'
import { useHotkey } from '@/composables/useHotkey'
import { formatMoney, formatDate } from '@/composables/useFormat'
import type { BankTransaction } from '@/api/bank'
import {
  bankPostingApi, bankPostingErrorMessage,
  type AiManualPostingSuggestion, type BankPostingRule, type BankPostingRulePayload, type PostResult,
} from '@/api/bankPosting'
import AutomationBadge from '@/components/automation/AutomationBadge.vue'
import ConfidenceLabel from '@/components/automation/ConfidenceLabel.vue'
import RuleForm from './RuleForm.vue'
import RuleTemplatesModal from './RuleTemplatesModal.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'

/** Saldokonta — do PRAVIDLA nepatří (H2). Jednorázové ruční zaúčtování je smí. */
const SALDO_PREFIXES = ['311', '321', '314', '324', '325']
/** Bankovní strana zápisu je vždy 221* (R6) — back-end to vynucuje, nabídka to respektuje. */
const BANK_PREFIX = '221'

const props = defineProps<{ tx: BankTransaction; currency: string }>()
const emit = defineEmits<{ posted: [{ result: PostResult; debit: string; credit: string }]; close: [] }>()

const { t } = useI18n()
const toast = useToast()
const idPrefix = `post-transaction-${useId()}`
const debitListId = `${idPrefix}-debit`
const creditListId = `${idPrefix}-credit`
const splitListId = `${idPrefix}-split`

const isIncoming = computed(() => props.tx.amount > 0)
const absAmount = computed(() => Math.abs(props.tx.amount))

const accounts = ref<ChartAccount[]>([])
const accountByCode = computed<Record<string, ChartAccount>>(() => {
  const m: Record<string, ChartAccount> = {}
  for (const a of accounts.value) m[a.account_code] = a
  return m
})
const activeAccounts = computed(() => accountPickerOptions(accounts.value))
function isSaldo(code: string): boolean {
  return SALDO_PREFIXES.some(p => code.startsWith(p))
}
// Bankovní strana (incoming → MD, outgoing → D) smí být jen 221*. Zúžená nabídka
// je jediné místo, kde se uživatel dozví, na které analytiky 221 firma účtuje —
// v seznamu 278 účtů se ztratí.
const bankOptions = computed(() => accountPickerOptions(accounts.value, a => a.account_code.startsWith(BANK_PREFIX)))
// Protiúčet: CELÁ osnova včetně saldokont. Ruční zaúčtování je jednorázový úkon
// člověka, který doklad má — na rozdíl od pravidla (H2) saldokonto zakázané nemá,
// stejně jako ho nemá rozúčtování na víc řádků. Filtrovat ho z nabídky znamenalo,
// že po napsání „311" nepřišla ŽÁDNÁ nápověda: ani syntetika, ani 311.100.
const counterOptions = activeAccounts

const debit = ref(props.tx.posting?.debit_account_code ?? (isIncoming.value ? BANK_PREFIX : ''))
const credit = ref(props.tx.posting?.credit_account_code ?? (isIncoming.value ? '' : BANK_PREFIX))
const description = ref(props.tx.description ?? props.tx.counterparty_name ?? '')
const aiOpen = ref(false)
const aiQuery = ref('')
const aiLoading = ref(false)
const aiSuggestion = ref<AiManualPostingSuggestion | null>(null)
const aiError = ref<string | null>(null)
const aiAvailable = ref(false)

function accountName(code: string): string {
  return accountByCode.value[code]?.name ?? ''
}
const debitValid = computed(() => !!debit.value && !!accountByCode.value[debit.value])
const creditValid = computed(() => !!credit.value && !!accountByCode.value[credit.value])

/** Strana, na kterou musí padnout banka: příchozí = MD, odchozí = D (R6). */
const bankSideCode = computed(() => (isIncoming.value ? debit.value : credit.value))
const counterSideCode = computed(() => (isIncoming.value ? credit.value : debit.value))
/** Chybu bankovní strany hlásil dřív až back-end po odeslání — teď je vidět hned. */
const bankSideValid = computed(() => bankSideCode.value.startsWith(BANK_PREFIX))
/**
 * Holé „221" back-end přesměruje na analytiku vlastního účtu výpisu (221.100 …),
 * stejně jako u automatiky. Bez téhle věty vypadá předvyplněná syntetika jako
 * chyba a uživatel ji „opravuje" ručně.
 */
const bankSideRoutedToAnalytic = computed(() => bankSideCode.value === BANK_PREFIX)
/** Pravidlo se saldokontem back-end odmítne (H2) — nabízet ho u takové kontace nemá smysl. */
const counterIsSaldo = computed(() => !!counterSideCode.value && isSaldo(counterSideCode.value))

// ── Rozúčtování na víc řádků ──────────────────────────────────────────────────
// Dvojice MD/D z principu neumí případ, kde se částka řádku liší od částky pohybu: prodej cenných
// papírů (pořizovací cena ≠ tržba), kurzový rozdíl, rozpad platby na víc účtů. Obě strany tam
// dostanou tutéž částku.
//
// Rozúčtování se nabízí i u CIZÍ MĚNY: zápis je vždy v CZK a kurz uživatel počítat nemusí —
// korunový ekvivalent posílá back-end (amount_czk/fx_rate, týž kurz, jakým pohyb zaúčtuje).
// Bez toho nejde zaúčtovat cizoměnová úhrada korunové faktury (311 D v částce předpisu,
// 221 MD kurzem dne, rozdíl na 563/663) — automatika ten případ odmítá.
const isForeign = computed(() => !!props.currency && props.currency !== 'CZK')
const fxRate = computed(() => props.tx.fx_rate ?? null)
/** Částka pohybu v CZK — základ pro celé rozúčtování (u korunového pohybu = částka výpisu). */
const absAmountCzk = computed(() => {
  if (!isForeign.value) return absAmount.value
  const czk = props.tx.amount_czk
  if (czk != null) return Math.abs(czk)
  return fxRate.value != null ? Math.round(absAmount.value * fxRate.value * 100) / 100 : null
})
/** Bez kurzu neznáme korunovou částku → rozúčtování by se nedalo vyvážit na výpis. */
const canSplit = computed(() => absAmountCzk.value != null)
type SplitLine = { account_code: string; side: 'debit' | 'credit'; amount: number | null }
const splitMode = ref(false)
const splitLines = ref<SplitLine[]>([])

function toggleSplit() {
  if (!canSplit.value) return
  splitMode.value = !splitMode.value
  if (splitMode.value && splitLines.value.length === 0) {
    // Předvyplň bankovní stranu korunovou částkou pohybu — ta je daná a nemění se.
    // Protistrana zůstane prázdná: u cizí měny se její částka (předpis faktury) od
    // bankovní nohy liší o kurzový rozdíl, takže předvyplnit ji by bylo zavádějící.
    const bank = absAmountCzk.value ?? 0
    splitLines.value = [
      { account_code: '221', side: isIncoming.value ? 'debit' : 'credit', amount: bank },
      { account_code: '', side: isIncoming.value ? 'credit' : 'debit', amount: isForeign.value ? null : bank },
    ]
  }
}
function addSplitLine() {
  splitLines.value.push({ account_code: '', side: isIncoming.value ? 'credit' : 'debit', amount: null })
}
function removeSplitLine(i: number) {
  splitLines.value.splice(i, 1)
}

const splitDebitSum = computed(() =>
  splitLines.value.filter(l => l.side === 'debit').reduce((s, l) => s + (l.amount ?? 0), 0))
const splitCreditSum = computed(() =>
  splitLines.value.filter(l => l.side === 'credit').reduce((s, l) => s + (l.amount ?? 0), 0))
const splitDiff = computed(() => Math.round((splitDebitSum.value - splitCreditSum.value) * 100) / 100)
/** Pohyb na 221 musí sedět na částku výpisu — jinak by se banka rozešla s výpisem. */
const splitBankNet = computed(() =>
  Math.round(splitLines.value
    .filter(l => l.account_code.startsWith('221'))
    .reduce((s, l) => s + (l.amount ?? 0) * (l.side === 'debit' ? 1 : -1), 0) * 100) / 100)
const splitBankExpected = computed(() => {
  const czk = absAmountCzk.value ?? 0
  return isIncoming.value ? czk : -czk
})
const splitBankOk = computed(() => Math.abs(splitBankNet.value - splitBankExpected.value) < 0.005)
const splitValid = computed(() =>
  canSplit.value
  && splitLines.value.length >= 2
  && splitLines.value.every(l => !!accountByCode.value[l.account_code] && (l.amount ?? 0) > 0)
  && splitDiff.value === 0
  && splitBankOk.value)

const canSubmit = computed(() =>
  splitMode.value ? splitValid.value : debitValid.value && creditValid.value && bankSideValid.value)

// Volitelné pravidlo z této platby
const withRule = ref(false)
const rulePayload = reactive<BankPostingRulePayload>(buildRulePrefill())
function buildRulePrefill(): BankPostingRulePayload {
  const base = absAmount.value
  const frag = (props.tx.description ?? '').slice(0, 40)
  return {
    name: props.tx.counterparty_name ?? '',
    is_active: true,
    direction: isIncoming.value ? 'incoming' : 'outgoing',
    counterparty_account: props.tx.counterparty_account,
    counterparty_bank: props.tx.counterparty_bank,
    variable_symbol: props.tx.variable_symbol,
    message_contains: frag || null,
    amount_min: Math.floor(base * 0.9),
    amount_max: Math.ceil(base * 1.1),
    priority: 100,
    operation_type: null,
    auto_amount_cap: null,
    applies_currency: props.tx.currency || 'CZK',
    counterparty_prefix: null,
    debit_account_code: debit.value,
    credit_account_code: credit.value,
    description: null,
    mode: 'suggest',
  }
}
function toggleRule() {
  withRule.value = !withRule.value
  if (withRule.value) {
    // sync aktuálně zadané účty do pravidla
    rulePayload.debit_account_code = debit.value
    rulePayload.credit_account_code = credit.value
  }
}
// Přepnutí protiúčtu na saldokonto po zaškrtnutí by jinak nechalo zaškrtnuté pravidlo,
// které back-end odmítne (rule_saldo_forbidden) — a s ním se rollbackne i celý zápis.
watch(counterIsSaldo, (saldo) => { if (saldo) withRule.value = false })

const saving = ref(false)
useHotkey('escape', () => { if (!saving.value) emit('close') })

async function requestAiSuggestion() {
  const query = aiQuery.value.trim()
  if (!query || aiLoading.value) return
  aiLoading.value = true
  aiSuggestion.value = null
  aiError.value = null
  try {
    aiSuggestion.value = await bankPostingApi.aiSuggest(props.tx.id, query)
  } catch (e) {
    const response = (e as { response?: { status?: number; data?: { error?: { code?: string } } } }).response
    const errorCode = response?.data?.error?.code ?? ''
    const unavailableCodes = ['ai_disabled', 'ai_unavailable', 'dpa_required', 'dpa_not_confirmed', 'source_muted', 'daily_limit']
    aiError.value = errorCode === 'invalid_accounts'
      ? 'automation.ai.manual_query_invalid_accounts'
      : (response?.status === 404 || unavailableCodes.includes(errorCode)
          ? 'automation.ai.manual_query_unavailable'
          : 'automation.ai.manual_query_error')
  } finally {
    aiLoading.value = false
  }
}

function applyAiSuggestion() {
  if (!aiSuggestion.value) return
  debit.value = aiSuggestion.value.debit_account_code
  credit.value = aiSuggestion.value.credit_account_code
}

// Nabídka firemních šablon (bank_rule_templates) přímo v zaúčtovacím modalu - dřív byla
// jen na stránce správy pravidel (RuleTemplatesModal). Instanciace šablony založí i reálné
// pravidlo (stejně jako v BankPostingRules.vue), MD/D se navíc rovnou předvyplní pro tuto platbu.
const templatesOpen = ref(false)
function onTemplateApplied(rule: BankPostingRule) {
  templatesOpen.value = false
  debit.value = rule.debit_account_code
  credit.value = rule.credit_account_code
  toast.success(t('bank.templates.applied'))
}

async function submit() {
  if (!canSubmit.value || saving.value) return
  saving.value = true
  try {
    const payload = splitMode.value
      ? {
          lines: splitLines.value.map(l => ({
            account_code: l.account_code, side: l.side, amount: l.amount ?? 0,
          })),
          description: description.value || undefined,
        }
      : {
          debit_account_code: debit.value,
          credit_account_code: credit.value,
          description: description.value || undefined,
          // pravidlo dává smysl jen u opakovatelné dvojice MD/D, ne u jednorázového rozúčtování
          ...(withRule.value ? { create_rule: { ...rulePayload } } : {}),
        }
    const res = await bankPostingApi.postTransaction(props.tx.id, payload)
    toast.success(t('bank.posting.posted_done'))
    const primary = splitMode.value
      ? {
          debit: splitLines.value.find(l => l.side === 'debit')?.account_code ?? '',
          credit: splitLines.value.find(l => l.side === 'credit')?.account_code ?? '',
        }
      : { debit: debit.value, credit: credit.value }
    emit('posted', { result: res, ...primary })
  } catch (e) {
    toast.error(bankPostingErrorMessage(e, t))
  } finally {
    saving.value = false
  }
}

onMounted(async () => {
  const [loadedAccounts, aiAvailability] = await Promise.all([
    accountingApi.listAccounts(),
    bankPostingApi.aiAvailability().catch(() => ({ available: false })),
  ])
  accounts.value = loadedAccounts
  aiAvailable.value = aiAvailability.available
})
</script>

<template>
  <div class="fixed inset-0 bg-black/40 z-50 flex items-center justify-center p-4">
    <div class="bg-surface rounded-xl shadow-lg max-w-md w-full max-h-[85vh] overflow-y-auto overflow-x-hidden p-5">
      <h3 class="text-lg font-semibold mb-1">{{ t('bank.posting.modal_title') }}</h3>

      <!-- Kontext transakce -->
      <p class="text-xs text-neutral-500 mb-3 font-mono">
        <span :class="isIncoming ? 'text-success-600' : 'text-danger-500'">
          {{ isIncoming ? '+' : '−' }}{{ formatMoney(absAmount, currency) }}
        </span>
        · {{ formatDate(tx.posted_at) }}
        <span v-if="tx.counterparty_name" class="text-neutral-400"> · {{ tx.counterparty_name }}</span>
      </p>
      <p v-if="tx.description" class="text-xs text-neutral-500 font-mono mb-3 truncate">{{ tx.description }}</p>

      <p v-if="isForeign" class="text-xs text-neutral-500 mb-3">
        {{ t('bank.posting.foreign_hint') }}
      </p>

      <!-- Účty MD/D (jednoduchá dvojice) -->
      <div v-if="!splitMode">
        <div class="grid grid-cols-2 gap-2">
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.debit') }}</label>
            <input v-model="debit" :list="debitListId" type="text" data-test="posting-debit"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            <div v-if="debit && accountByCode[debit]" class="text-xs text-neutral-500 mt-0.5 truncate">{{ accountName(debit) }}</div>
            <div v-else-if="debit" class="text-xs text-danger-500 mt-0.5">{{ t('bank.posting.err_account_not_found') }}</div>
          </div>
          <div>
            <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('bank.posting.credit') }}</label>
            <input v-model="credit" :list="creditListId" type="text" data-test="posting-credit"
              class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm font-mono" />
            <div v-if="credit && accountByCode[credit]" class="text-xs text-neutral-500 mt-0.5 truncate">{{ accountName(credit) }}</div>
            <div v-else-if="credit" class="text-xs text-danger-500 mt-0.5">{{ t('bank.posting.err_account_not_found') }}</div>
          </div>
        </div>
        <p v-if="!bankSideValid" class="text-xs text-danger-500 mt-1">{{ t('bank.posting.err_rule_bank_side') }}</p>
        <p v-else-if="bankSideRoutedToAnalytic" class="text-xs text-neutral-500 mt-1">
          {{ t('bank.posting.bank_analytic_hint') }}
        </p>
        <button type="button" class="mt-2 text-xs text-primary-600 hover:underline" @click="templatesOpen = true">
          {{ t('bank.posting.use_template') }}
        </button>
      </div>

      <!-- Rozúčtování na víc řádků -->
      <div v-else class="space-y-2">
        <!-- U cizí měny ukaž, z čeho korunový základ vznikl — uživatel kurz nepočítá, ale musí ho vidět. -->
        <p v-if="isForeign && fxRate" class="text-xs text-neutral-500 font-mono">
          {{ t('bank.posting.split_fx_basis', {
            foreign: formatMoney(absAmount, currency),
            rate: fxRate,
            czk: formatMoney(absAmountCzk ?? 0, 'CZK'),
          }) }}
        </p>
        <div v-for="(l, i) in splitLines" :key="i" class="flex items-start gap-1.5">
          <select v-model="l.side" class="h-10 px-2 border border-neutral-300 rounded-md text-xs shrink-0">
            <option value="debit">{{ t('bank.posting.debit') }}</option>
            <option value="credit">{{ t('bank.posting.credit') }}</option>
          </select>
          <div class="flex-1 min-w-0">
            <input v-model="l.account_code" :list="splitListId" type="text" data-test="posting-split" :placeholder="t('bank.posting.split_account')"
              class="w-full h-10 px-2 border border-neutral-300 rounded-md text-sm font-mono" />
            <div v-if="l.account_code && accountByCode[l.account_code]" class="text-xs text-neutral-500 mt-0.5 truncate">
              {{ accountName(l.account_code) }}
            </div>
            <div v-else-if="l.account_code" class="text-xs text-danger-500 mt-0.5">{{ t('bank.posting.err_account_not_found') }}</div>
          </div>
          <input v-model.number="l.amount" type="number" step="0.01" min="0"
            class="w-28 h-10 px-2 border border-neutral-300 rounded-md text-sm font-mono text-right shrink-0" />
          <button type="button" class="h-10 px-2 text-neutral-400 hover:text-danger-500 shrink-0"
            :disabled="splitLines.length <= 2" @click="removeSplitLine(i)">×</button>
        </div>
        <button type="button" class="text-xs text-primary-600 hover:underline" @click="addSplitLine">
          + {{ t('bank.posting.split_add_line') }}
        </button>

        <!-- Kontroly: vyváženost a shoda banky s výpisem -->
        <div class="text-xs space-y-0.5 pt-1 border-t border-neutral-100">
          <div class="flex justify-between font-mono">
            <span class="text-neutral-500">{{ t('bank.posting.split_sums') }}</span>
            <span>{{ formatMoney(splitDebitSum, 'CZK') }} / {{ formatMoney(splitCreditSum, 'CZK') }}</span>
          </div>
          <div v-if="splitDiff !== 0" class="text-danger-500">
            {{ t('bank.posting.split_unbalanced', { diff: formatMoney(Math.abs(splitDiff), 'CZK') }) }}
          </div>
          <div v-else-if="!splitBankOk" class="text-danger-500">
            {{ t('bank.posting.split_bank_mismatch', {
              got: formatMoney(splitBankNet, 'CZK'), want: formatMoney(splitBankExpected, 'CZK') }) }}
          </div>
          <div v-else class="text-success-600">{{ t('bank.posting.split_ok') }}</div>
        </div>
      </div>

      <button v-if="canSplit" type="button" class="mt-2 text-xs text-primary-600 hover:underline" @click="toggleSplit">
        {{ splitMode ? t('bank.posting.split_off') : t('bank.posting.split_on') }}
      </button>
      <!-- Plná osnova (rozúčtování i protiúčet dvojice) — analytiky před svou syntetikou. -->
      <datalist :id="splitListId">
        <option v-for="a in activeAccounts" :key="a.id" :value="a.account_code">
          {{ a.account_code }} — {{ a.name }}
        </option>
      </datalist>
      <datalist :id="debitListId">
        <option v-for="a in (isIncoming ? bankOptions : counterOptions)" :key="a.id" :value="a.account_code">
          {{ a.account_code }} — {{ a.name }}
        </option>
      </datalist>
      <datalist :id="creditListId">
        <option v-for="a in (isIncoming ? counterOptions : bankOptions)" :key="a.id" :value="a.account_code">
          {{ a.account_code }} — {{ a.name }}
        </option>
      </datalist>
      <p v-if="!splitMode" class="text-xs text-neutral-500 mt-1">{{ t('bank.posting.saldo_manual_hint') }}</p>

      <!-- Popis -->
      <div class="mt-3">
        <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('accounting.manual.description') }}</label>
        <input v-model="description" type="text" class="w-full h-10 px-3 border border-neutral-300 rounded-md text-sm" />
      </div>

      <section v-if="aiAvailable" class="mt-3 min-w-0 max-w-full whitespace-normal break-words rounded-md border border-neutral-200">
        <button type="button" class="flex w-full cursor-pointer items-center justify-between gap-3 p-3 text-left hover:bg-neutral-50" :aria-expanded="aiOpen" @click="aiOpen = !aiOpen">
          <span class="flex min-w-0 flex-wrap items-center gap-2">
            <AutomationBadge variant="ai" />
            <span class="text-sm font-medium text-neutral-700">{{ t('automation.ai.manual_query_title') }}</span>
          </span>
          <svg class="h-4 w-4 shrink-0 text-neutral-500 transition-transform" :class="aiOpen ? 'rotate-180' : ''" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M5.23 7.21a.75.75 0 0 1 1.06.02L10 11.168l3.71-3.938a.75.75 0 1 1 1.08 1.04l-4.25 4.5a.75.75 0 0 1-1.08 0l-4.25-4.5a.75.75 0 0 1 .02-1.06Z" clip-rule="evenodd" /></svg>
        </button>
        <div v-if="aiOpen" class="min-w-0 space-y-3 border-t border-neutral-200 p-3 whitespace-normal break-words">
          <p class="whitespace-normal break-words text-xs text-neutral-600">{{ t('automation.ai.manual_query_hint') }}</p>
          <p class="whitespace-normal break-words rounded-md bg-warning-50 p-2 text-xs text-warning-700">{{ t('automation.ai.manual_query_privacy') }}</p>
          <textarea v-model="aiQuery" rows="3" :placeholder="t('automation.ai.manual_query_placeholder')" class="w-full resize-y rounded-md border border-neutral-300 px-3 py-2 text-sm" @keydown.ctrl.enter.prevent="requestAiSuggestion" />
          <button type="button" :disabled="!aiQuery.trim() || aiLoading" class="inline-flex h-9 cursor-pointer items-center gap-2 rounded-md bg-primary-600 px-3 text-sm font-medium text-white hover:bg-primary-700 disabled:cursor-not-allowed disabled:bg-neutral-300" @click="requestAiSuggestion">
            <span aria-hidden="true">✦</span>
            {{ aiLoading ? t('common.loading') : t('automation.ai.manual_query_submit') }}
          </button>
          <p v-if="aiError" class="whitespace-normal break-words rounded-md bg-danger-50 p-2 text-xs text-danger-600">{{ t(aiError) }}</p>
          <div v-if="aiSuggestion" class="rounded-md border border-warning-200 bg-warning-50/50 p-3">
            <div class="flex flex-wrap items-center gap-2">
              <AutomationBadge variant="ai" />
              <ConfidenceLabel :confidence="aiSuggestion.confidence" />
            </div>
            <div class="mt-2 flex flex-wrap items-center gap-2 text-sm font-medium text-neutral-700">
              <span class="font-mono">{{ t('bank.posting.debit') }} {{ aiSuggestion.debit_account_code }}</span>
              <span aria-hidden="true">→</span>
              <span class="font-mono">{{ t('bank.posting.credit') }} {{ aiSuggestion.credit_account_code }}</span>
            </div>
            <p v-if="aiSuggestion.reasoning" class="mt-2 whitespace-normal break-words text-xs text-neutral-600">{{ aiSuggestion.reasoning }}</p>
            <button type="button" class="mt-3 inline-flex h-9 cursor-pointer items-center gap-2 rounded-md border border-success-500 px-3 text-sm font-medium text-success-600 hover:bg-success-50" @click="applyAiSuggestion">
              <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M16.704 5.29a1 1 0 0 1 .006 1.414l-7.25 7.31a1 1 0 0 1-1.42.002l-3.75-3.75a1 1 0 1 1 1.414-1.414l3.04 3.04 6.542-6.596a1 1 0 0 1 1.418-.006Z" clip-rule="evenodd" /></svg>
              {{ t('automation.ai.manual_query_apply') }}
            </button>
          </div>
        </div>
      </section>

      <!-- Live náhled zápisu -->
      <div class="mt-3 border border-neutral-200 rounded-md p-3">
        <div class="text-xs font-medium text-neutral-500 uppercase mb-1.5">{{ t('bank.posting.preview_title') }}</div>
        <table class="w-full text-sm">
          <tbody>
            <tr>
              <td class="py-0.5 font-mono" :class="debitValid ? 'text-neutral-700' : 'text-danger-500'">
                {{ t('bank.posting.debit') }} {{ debit || '—' }}
              </td>
              <td class="py-0.5 text-right font-mono text-neutral-700">{{ formatMoney(absAmount, currency) }}</td>
            </tr>
            <tr>
              <td class="py-0.5 font-mono" :class="creditValid ? 'text-neutral-700' : 'text-danger-500'">
                {{ t('bank.posting.credit') }} {{ credit || '—' }}
              </td>
              <td class="py-0.5 text-right font-mono text-neutral-700">{{ formatMoney(absAmount, currency) }}</td>
            </tr>
          </tbody>
        </table>
        <div class="text-xs text-neutral-400 mt-1">{{ formatDate(tx.posted_at) }} · {{ description || '—' }}</div>
      </div>

      <!-- Volitelné pravidlo — jen u dvojice MD/D bez saldokonta; jinak ho back-end založit nedovolí. -->
      <label v-if="!splitMode" class="flex items-center gap-2 mt-3 text-sm cursor-pointer"
        :class="counterIsSaldo ? 'text-neutral-400 cursor-not-allowed' : 'text-neutral-700'">
        <input type="checkbox" :checked="withRule" :disabled="counterIsSaldo" @change="toggleRule" class="rounded border-neutral-300" />
        {{ t('bank.posting.create_rule_checkbox') }}
      </label>
      <p v-if="!splitMode && counterIsSaldo" class="text-xs text-neutral-500 mt-0.5">{{ t('bank.posting.saldo_hint') }}</p>
      <div v-if="withRule && !splitMode" class="mt-3 border-t border-neutral-200 pt-3">
        <RuleForm :model-value="rulePayload" :accounts="accounts" mode="create" :base-amount="absAmount" @update:model-value="Object.assign(rulePayload, $event)" />
      </div>

      <div class="flex flex-wrap justify-end gap-2 pt-4">
        <button @click="emit('close')" :disabled="saving"
          :class="btnOutline('neutral')">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button @click="submit" :disabled="!canSubmit || saving"
          :class="btnFilled('primary')">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path :d="ICONS.check" /></svg>
          {{ saving ? '…' : t('bank.posting.action_post') }}
        </button>
      </div>
    </div>

    <RuleTemplatesModal v-if="templatesOpen" @applied="onTemplateApplied" @close="templatesOpen = false" />
  </div>
</template>
