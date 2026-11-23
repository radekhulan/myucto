<script setup lang="ts">
import DateInput from '@/components/ui/DateInput.vue'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import EmptyState from '@/components/ui/EmptyState.vue'
import { BTN_BASE, OUTLINE, ICONS } from '@/components/ui/buttonStyles'
import {
  paymentCardsApi, type CardPaymentGroup, type CardPaymentRow, type CardWriteOffTarget, type UnmatchedCardPayments,
} from '@/api/paymentCards'
import { apiErrorMessage } from '@/api/errors'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { accountPickerOptions } from '@/utils/chartAccountOptions'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import BankMatchModal from '@/components/bank/BankMatchModal.vue'
import { useBankTransactionActions } from '@/composables/useBankTransactionActions'
import type { BankTransaction } from '@/api/bank'

/** Detail kreditní karty: jen nákupy tohoto úvěrového účtu. Bez něj jen platební karty. */
const props = defineProps<{ creditCardAccountId?: number | null }>()

const { t } = useI18n()
const router = useRouter()
const auth = useAuthStore()
const toast = useToast()

function localIso(d: Date): string {
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`
}
const today = new Date()
const to = ref(localIso(today))
const from = ref(localIso(new Date(today.getFullYear(), today.getMonth() - 3, today.getDate())))

const data = ref<UnmatchedCardPayments | null>(null)
const loading = ref(false)
const busyTx = ref<number | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)
const uploadTarget = ref<CardPaymentRow | null>(null)

const canUpload = computed(() => auth.canWrite('purchase_invoices.scan'))
const canMatch = computed(() => auth.canRead('bank') && auth.canWrite('bank.match'))
const canManageCards = computed(() => auth.canWrite('settings.bank_accounts'))
const canPost = computed(() => auth.canWrite('bank.post'))
const bankActions = useBankTransactionActions({ reload: load, refresh: load })

function startManualMatch(tx: CardPaymentRow) {
  if (!canMatch.value || busyTx.value !== null || loading.value) return
  const transaction: BankTransaction = {
    ...tx,
    variable_symbol: null,
    constant_symbol: null,
    specific_symbol: null,
    counterparty_account: null,
    counterparty_bank: null,
    bank_ref: null,
    matched_invoice_id: null,
    match_status: 'unmatched',
    matched_at: null,
  }
  bankActions.startMatch(transaction)
}

/**
 * Uzavření platby bez dokladu: nedaňový / daňový náklad, nebo k tíži držitele karty.
 * Účet je výchozí z nastavení, dialog ale dovolí zvolit jiný (backend ho ověří: náklad
 * třídy 5, u držitele 335/355/378, nikdy samotný mezičlen).
 */
const TARGET_PREFIXES: Record<CardWriteOffTarget, string[]> = {
  expense: ['5'], expense_tax: ['5'], holder: ['335', '355', '378'],
}
const writeOffDialog = ref<{ tx: CardPaymentRow; target: CardWriteOffTarget; accountId: number | null } | null>(null)
const accounts = ref<ChartAccount[]>([])
const writeOffOptions = computed(() => {
  const d = writeOffDialog.value
  if (!d) return []
  return accountPickerOptions(accounts.value, a => !a.is_synthetic || !accounts.value.some(c => c.parent_id === a.id))
    .filter(a => TARGET_PREFIXES[d.target].some(p => a.account_code.startsWith(p)) && a.account_code !== d.tx.clearing_account)
})

async function openWriteOff(tx: CardPaymentRow, target: CardWriteOffTarget) {
  writeOffDialog.value = { tx, target, accountId: null }
  if (accounts.value.length === 0) {
    try { accounts.value = await accountingApi.listAccounts() } catch { /* zůstane jen výchozí účet */ }
  }
}

async function confirmWriteOff() {
  const d = writeOffDialog.value
  if (!d) return
  busyTx.value = d.tx.id
  try {
    const r = await paymentCardsApi.writeOff(d.tx.id, d.target, d.accountId)
    toast.success(t('payment_cards.unmatched.written_off', { account: r.account_code }))
    writeOffDialog.value = null
    await load()
  } catch (err) {
    toast.error(apiErrorMessage(err, t('payment_cards.unmatched.write_off_failed')))
  } finally {
    busyTx.value = null
  }
}

async function load() {
  loading.value = true
  try {
    data.value = await paymentCardsApi.unmatchedPayments({
      from: from.value, to: to.value,
      ...(props.creditCardAccountId ? { credit_card_account_id: props.creditCardAccountId } : {}),
    })
  } catch (e) {
    toast.error(apiErrorMessage(e, t('payment_cards.load_failed')))
  } finally {
    loading.value = false
  }
}
onMounted(load)

function groupTitle(g: CardPaymentGroup): string {
  if (g.credit_card) return t('payment_cards.unmatched.credit_card_group', { label: g.credit_card.label })
  return g.holder || g.card?.label || t('payment_cards.unmatched.unknown_holder')
}
function totals(g: CardPaymentGroup): string {
  return Object.entries(g.totals).map(([ccy, sum]) => formatMoney(sum, ccy)).join(' + ')
}

function vehicleHint(tx: CardPaymentRow): string {
  const h = tx.vehicle_hint
  if (!h) return ''
  if (h.reason !== 'ok' || !h.registration) return t('payment_cards.unmatched.vehicle_ambiguous')
  return t('payment_cards.unmatched.vehicle_hint', { vehicle: h.registration + (h.car_name ? ` (${h.car_name})` : '') })
}

function pickReceipt(tx: CardPaymentRow) {
  uploadTarget.value = tx
  fileInput.value?.click()
}

async function onFile(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  const tx = uploadTarget.value
  if (!file || !tx) return
  busyTx.value = tx.id
  try {
    const r = await paymentCardsApi.uploadReceipt(tx.id, file)
    if ('stored' in r) {
      // Bez AI: účtenka čeká v Příchozích dokladech (Dokumenty / Příchozí doklady / rok / měsíc).
      const incoming = {
        label: t('payment_cards.unmatched.open_incoming'),
        handler: () => { void router.push({ name: 'purchase-invoice-submissions' }) },
      }
      toast.success(t(r.duplicate ? 'payment_cards.unmatched.stored_duplicate' : 'payment_cards.unmatched.stored', { folder: r.folder }), incoming)
      return
    }
    const open = {
      label: t('payment_cards.unmatched.open_document'),
      handler: () => { void router.push(`/purchase-invoices/${r.purchase_invoice_id}`) },
    }
    if (r.duplicate) toast.warning(t('payment_cards.unmatched.uploaded_duplicate'), open)
    else toast.success(t('payment_cards.unmatched.uploaded'), open)
  } catch (err) {
    toast.error(apiErrorMessage(err, t('payment_cards.unmatched.upload_failed')))
  } finally {
    busyTx.value = null
  }
}

async function rematch(tx: CardPaymentRow) {
  busyTx.value = tx.id
  try {
    const r = await paymentCardsApi.rematch(tx.id)
    if (['auto_exact', 'auto_partial', 'manual'].includes(r.result.status)) {
      toast.success(t('payment_cards.unmatched.rematched'))
      await load()
    } else if (r.result.requires_review) {
      toast.info(t('payment_cards.unmatched.rematch_review'))
    } else {
      toast.info(t('payment_cards.unmatched.rematch_none'))
    }
  } catch (err) {
    toast.error(apiErrorMessage(err, t('payment_cards.unmatched.rematch_failed')))
  } finally {
    busyTx.value = null
  }
}

const INPUT = 'h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface'
</script>

<template>
  <div>
    <p class="text-sm text-neutral-500 mb-3">{{ t('payment_cards.unmatched.hint') }}</p>

    <form class="flex flex-wrap items-end gap-2 mb-4" @submit.prevent="load">
      <label class="flex flex-col text-xs text-neutral-600">
        {{ t('payment_cards.unmatched.from') }}
        <DateInput v-model="from" :class="INPUT" required />
      </label>
      <label class="flex flex-col text-xs text-neutral-600">
        {{ t('payment_cards.unmatched.to') }}
        <DateInput v-model="to" :class="INPUT" required />
      </label>
      <button type="submit" :class="[BTN_BASE, OUTLINE.primary]" class="whitespace-nowrap" :disabled="loading">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.search" />
        </svg>
        {{ t('payment_cards.unmatched.refresh') }}
      </button>
    </form>

    <input ref="fileInput" type="file" accept=".pdf,application/pdf,image/*" class="hidden" @change="onFile" />

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="data && data.groups.length === 0" boxed icon="checkCircle" accent="success"
      :title="t('payment_cards.unmatched.empty')" />
    <template v-else-if="data">
      <p v-if="data.truncated" class="mb-3 text-xs text-warning-700">{{ t('payment_cards.unmatched.truncated', { n: data.count }) }}</p>

      <section v-for="g in data.groups" :key="g.key"
        class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden mb-4">
        <header class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 border-b border-neutral-100 bg-neutral-50">
          <div class="min-w-0">
            <div class="font-medium text-neutral-800 truncate">{{ groupTitle(g) }}</div>
            <div class="text-xs text-neutral-500">
              <RouterLink v-if="g.credit_card" :to="{ name: 'credit-card-detail', params: { id: g.credit_card.id } }"
                class="text-primary-700 hover:underline">{{ t('payment_cards.unmatched.credit_card_open') }}</RouterLink>
              <template v-else>
                <span v-if="g.card && g.holder">{{ g.card.label }} · </span>
                <span class="font-mono">{{ t('payment_cards.masked', { last4: g.last4 }) }}</span>
              </template>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-3 text-sm">
            <span class="text-neutral-500 whitespace-nowrap">{{ t('payment_cards.unmatched.count', { n: g.count }) }}</span>
            <span class="font-semibold whitespace-nowrap">{{ totals(g) }}</span>
            <RouterLink v-if="!g.card && !g.credit_card && canManageCards" :to="{ name: 'payment-card-new', query: { last4: g.last4 } }"
              :class="[BTN_BASE, OUTLINE.primary]" class="whitespace-nowrap">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" />
              </svg>
              {{ t('payment_cards.unmatched.add_card') }}
            </RouterLink>
          </div>
        </header>

        <div class="hidden md:block overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium">{{ t('payment_cards.unmatched.col_date') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('payment_cards.unmatched.col_amount') }}</th>
                <th class="px-3 py-2 text-left font-medium">{{ t('payment_cards.unmatched.col_merchant') }}</th>
                <th class="px-3 py-2"></th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="tx in g.transactions" :key="tx.id">
                <td class="px-3 py-2 whitespace-nowrap">{{ formatDate(tx.posted_at) }}</td>
                <td class="px-3 py-2 text-right whitespace-nowrap font-medium text-danger-600">{{ formatMoney(tx.amount, tx.currency) }}</td>
                <td class="px-3 py-2 text-xs">
                  <div class="text-neutral-700">{{ tx.counterparty_name || '—' }}</div>
                  <div v-if="tx.description" class="text-neutral-500 truncate max-w-md">{{ tx.description }}</div>
                  <div v-if="tx.vehicle_hint" class="mt-0.5" :class="tx.vehicle_hint.reason === 'ok' ? 'text-primary-700' : 'text-warning-700'"
                    :title="t('payment_cards.unmatched.vehicle_hint_title')" data-testid="vehicle-hint">{{ vehicleHint(tx) }}</div>
                  <div v-if="tx.clearing_account" class="mt-0.5 font-mono text-neutral-500" data-testid="clearing-account">
                    {{ t('payment_cards.unmatched.clearing', { code: tx.clearing_account }) }}
                  </div>
                  <button v-if="canMatch" type="button" class="mt-1 inline-flex items-center gap-1 rounded bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700 hover:bg-warning-100 disabled:opacity-50"
                    :disabled="busyTx !== null || loading" :title="t('bank.match_badge_hint')" data-testid="manual-match-desktop" @click="startManualMatch(tx)">
                    <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
                    {{ t('bank.match_status.unmatched') }}
                  </button>
                  <span v-else class="mt-1 inline-block rounded bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700">{{ t('bank.match_status.unmatched') }}</span>
                </td>
                <td class="px-3 py-2">
                  <div class="flex flex-wrap justify-end gap-2">
                    <button v-if="canUpload" type="button" :class="[BTN_BASE, OUTLINE.primary]" class="whitespace-nowrap"
                      :disabled="busyTx !== null" @click="pickReceipt(tx)">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" />
                      </svg>
                      {{ busyTx === tx.id ? '…' : t('payment_cards.unmatched.upload') }}
                    </button>
                    <button v-if="canMatch" type="button" :class="[BTN_BASE, OUTLINE.success]" class="whitespace-nowrap"
                      :disabled="busyTx !== null" @click="rematch(tx)">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" />
                      </svg>
                      {{ t('payment_cards.unmatched.rematch') }}
                    </button>
                    <template v-if="canPost && tx.clearing_account">
                      <button type="button" :class="[BTN_BASE, OUTLINE.warning]" class="whitespace-nowrap"
                        :disabled="busyTx !== null" data-testid="write-off-expense" @click="openWriteOff(tx, 'expense')">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" />
                        </svg>
                        {{ t('payment_cards.unmatched.write_off_expense') }}
                      </button>
                      <button type="button" :class="[BTN_BASE, OUTLINE.warning]" class="whitespace-nowrap"
                        :disabled="busyTx !== null" data-testid="write-off-expense-tax" @click="openWriteOff(tx, 'expense_tax')">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" />
                        </svg>
                        {{ t('payment_cards.unmatched.write_off_expense_tax') }}
                      </button>
                      <button type="button" :class="[BTN_BASE, OUTLINE.warning]" class="whitespace-nowrap"
                        :disabled="busyTx !== null" @click="openWriteOff(tx, 'holder')">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.user" />
                        </svg>
                        {{ t('payment_cards.unmatched.write_off_holder') }}
                      </button>
                    </template>
                    <RouterLink :to="{ name: 'bank-detail', params: { id: tx.statement_id }, query: { tx: String(tx.id) } }"
                      :class="[BTN_BASE, OUTLINE.neutral]" class="whitespace-nowrap">
                      <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" />
                      </svg>
                      {{ t('payment_cards.unmatched.open_statement') }}
                    </RouterLink>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="tx in g.transactions" :key="`m-${tx.id}`" class="px-4 py-3 space-y-2">
            <div class="flex items-center justify-between gap-2">
              <span class="text-sm text-neutral-600">{{ formatDate(tx.posted_at) }}</span>
              <span class="font-medium text-danger-600 whitespace-nowrap">{{ formatMoney(tx.amount, tx.currency) }}</span>
            </div>
            <div class="text-xs text-neutral-700 truncate">{{ tx.counterparty_name || tx.description || '—' }}</div>
            <div v-if="tx.vehicle_hint" class="text-xs" :class="tx.vehicle_hint.reason === 'ok' ? 'text-primary-700' : 'text-warning-700'"
              :title="t('payment_cards.unmatched.vehicle_hint_title')">{{ vehicleHint(tx) }}</div>
            <button v-if="canMatch" type="button" class="inline-flex items-center gap-1 rounded bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700 hover:bg-warning-100 disabled:opacity-50"
              :disabled="busyTx !== null || loading" :title="t('bank.match_badge_hint')" data-testid="manual-match-mobile" @click="startManualMatch(tx)">
              <svg class="h-3 w-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
              {{ t('bank.match_status.unmatched') }}
            </button>
            <span v-else class="inline-block rounded bg-warning-50 px-2 py-0.5 text-xs font-medium text-warning-700">{{ t('bank.match_status.unmatched') }}</span>
            <div class="flex flex-wrap gap-2">
              <button v-if="canUpload" type="button" :class="[BTN_BASE, OUTLINE.primary]" class="whitespace-nowrap"
                :disabled="busyTx !== null" @click="pickReceipt(tx)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.upload" />
                </svg>
                {{ busyTx === tx.id ? '…' : t('payment_cards.unmatched.upload') }}
              </button>
              <button v-if="canMatch" type="button" :class="[BTN_BASE, OUTLINE.success]" class="whitespace-nowrap"
                :disabled="busyTx !== null" @click="rematch(tx)">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                  <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" />
                </svg>
                {{ t('payment_cards.unmatched.rematch') }}
              </button>
              <template v-if="canPost && tx.clearing_account">
                <button type="button" :class="[BTN_BASE, OUTLINE.warning]" class="whitespace-nowrap"
                  :disabled="busyTx !== null" @click="openWriteOff(tx, 'expense')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" />
                  </svg>
                  {{ t('payment_cards.unmatched.write_off_expense') }}
                </button>
                <button type="button" :class="[BTN_BASE, OUTLINE.warning]" class="whitespace-nowrap"
                  :disabled="busyTx !== null" @click="openWriteOff(tx, 'expense_tax')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" />
                  </svg>
                  {{ t('payment_cards.unmatched.write_off_expense_tax') }}
                </button>
                <button type="button" :class="[BTN_BASE, OUTLINE.warning]" class="whitespace-nowrap"
                  :disabled="busyTx !== null" @click="openWriteOff(tx, 'holder')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.user" />
                  </svg>
                  {{ t('payment_cards.unmatched.write_off_holder') }}
                </button>
              </template>
            </div>
          </div>
        </div>
      </section>
    </template>

    <BankMatchModal :actions="bankActions" />

    <Modal v-if="writeOffDialog" :title="t(`payment_cards.unmatched.write_off_${writeOffDialog.target}`)" width-class="max-w-lg"
      data-testid="write-off-dialog" @close="writeOffDialog = null">
      <div class="space-y-3 text-sm">
        <p class="text-neutral-700">{{ t(`payment_cards.unmatched.write_off_confirm_${writeOffDialog.target}`) }}</p>
        <p class="text-neutral-600">
          {{ formatDate(writeOffDialog.tx.posted_at) }} · {{ writeOffDialog.tx.counterparty_name || '—' }} ·
          <span class="font-medium">{{ formatMoney(writeOffDialog.tx.amount, writeOffDialog.tx.currency) }}</span>
        </p>
        <label class="block">
          <span class="block text-neutral-700 mb-1">{{ t('payment_cards.unmatched.write_off_account') }}</span>
          <select v-model="writeOffDialog.accountId" :class="INPUT" class="w-full" data-testid="write-off-account">
            <option :value="null">{{ t('payment_cards.unmatched.write_off_account_default') }}</option>
            <option v-for="a in writeOffOptions" :key="a.id" :value="a.id">{{ a.account_code }} - {{ a.name }}</option>
          </select>
        </label>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="writeOffDialog = null">{{ t('common.cancel') }}</button>
          <button type="button" :class="btnFilled('warning')" class="whitespace-nowrap" :disabled="busyTx !== null"
            data-testid="write-off-confirm" @click="confirmWriteOff">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
              <path stroke-linecap="round" stroke-linejoin="round" :d="writeOffDialog.target === 'holder' ? ICONS.user : ICONS.archive" />
            </svg>
            {{ busyTx !== null ? t('common.saving') : t(`payment_cards.unmatched.write_off_${writeOffDialog.target}`) }}
          </button>
        </div>
      </template>
    </Modal>
  </div>
</template>
