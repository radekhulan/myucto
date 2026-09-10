<script setup lang="ts">
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
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'

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
const canMatch = computed(() => auth.canWrite('bank.match'))
const canManageCards = computed(() => auth.canWrite('settings.bank_accounts'))
const canPost = computed(() => auth.canWrite('bank.post'))

/** Uzavření platby bez dokladu: nedaňový náklad, nebo k tíži držitele karty. */
async function writeOff(tx: CardPaymentRow, target: CardWriteOffTarget) {
  if (!window.confirm(t(`payment_cards.unmatched.write_off_confirm_${target}`))) return
  busyTx.value = tx.id
  try {
    const r = await paymentCardsApi.writeOff(tx.id, target)
    toast.success(t('payment_cards.unmatched.written_off', { account: r.account_code }))
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
    data.value = await paymentCardsApi.unmatchedPayments({ from: from.value, to: to.value })
  } catch (e) {
    toast.error(apiErrorMessage(e, t('payment_cards.load_failed')))
  } finally {
    loading.value = false
  }
}
onMounted(load)

function groupTitle(g: CardPaymentGroup): string {
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
        <input v-model="from" type="date" :class="INPUT" required />
      </label>
      <label class="flex flex-col text-xs text-neutral-600">
        {{ t('payment_cards.unmatched.to') }}
        <input v-model="to" type="date" :class="INPUT" required />
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
              <span v-if="g.card && g.holder">{{ g.card.label }} · </span>
              <span class="font-mono">{{ t('payment_cards.masked', { last4: g.last4 }) }}</span>
            </div>
          </div>
          <div class="flex flex-wrap items-center gap-3 text-sm">
            <span class="text-neutral-500 whitespace-nowrap">{{ t('payment_cards.unmatched.count', { n: g.count }) }}</span>
            <span class="font-semibold whitespace-nowrap">{{ totals(g) }}</span>
            <RouterLink v-if="!g.card && canManageCards" :to="{ name: 'payment-card-new', query: { last4: g.last4 } }"
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
                        :disabled="busyTx !== null" data-testid="write-off-expense" @click="writeOff(tx, 'expense')">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" />
                        </svg>
                        {{ t('payment_cards.unmatched.write_off_expense') }}
                      </button>
                      <button type="button" :class="[BTN_BASE, OUTLINE.warning]" class="whitespace-nowrap"
                        :disabled="busyTx !== null" @click="writeOff(tx, 'holder')">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                          <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.user" />
                        </svg>
                        {{ t('payment_cards.unmatched.write_off_holder') }}
                      </button>
                    </template>
                    <RouterLink :to="{ name: 'bank-detail', params: { id: tx.statement_id } }"
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
                  :disabled="busyTx !== null" @click="writeOff(tx, 'expense')">
                  <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                    <path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.archive" />
                  </svg>
                  {{ t('payment_cards.unmatched.write_off_expense') }}
                </button>
                <button type="button" :class="[BTN_BASE, OUTLINE.warning]" class="whitespace-nowrap"
                  :disabled="busyTx !== null" @click="writeOff(tx, 'holder')">
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
  </div>
</template>
