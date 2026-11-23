<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { BANK_BATCH_MATCH_LIMIT, bankApi, type BankPaymentCandidate } from '@/api/bank'
import { apiErrorMessage } from '@/api/errors'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useBankTransactionActions } from '@/composables/useBankTransactionActions'
import { useHotkey } from '@/composables/useHotkey'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import BankMatchModal from './BankMatchModal.vue'
import BankBatchMatchModal from './BankBatchMatchModal.vue'

const props = defineProps<{
  docType: 'invoice' | 'purchase_invoice'
  docId: number
  docRef: string | null
  batchAvailable?: boolean
}>()
const emit = defineEmits<{ close: []; done: [] }>()
const { t } = useI18n()
const auth = useAuthStore()
const supplier = useSupplierStore()
const supplierId = supplier.currentSupplierId
const canMatch = computed(() => !auth.isClientRole && auth.canRead('bank') && auth.canWrite('bank.match'))
const search = ref('')
const searchInput = ref<HTMLInputElement | null>(null)
const candidates = ref<BankPaymentCandidate[]>([])
const selectedId = ref<number | null>(null)
const selected = computed(() => candidates.value.find(candidate => candidate.id === selectedId.value) ?? null)
const batchMode = ref(false)
const batchSelected = ref<number[]>([])
const reviewIds = ref<number[] | null>(null)
const reviewButton = ref<HTMLButtonElement | null>(null)
const loading = ref(false)
const matching = ref(false)
const completed = ref(false)
const error = ref('')
const page = ref(1)
const pages = ref(1)
const total = ref(0)
let generation = 0
let disposed = false
let searchTimer: ReturnType<typeof setTimeout> | undefined
const actions = useBankTransactionActions({
  reload: () => {
    if (!disposed && supplier.currentSupplierId === supplierId) emit('done')
  },
})
const { matchError, purchaseShortfall } = actions

function close() {
  if (!matching.value) emit('close')
}

async function load(targetPage = 1) {
  clearTimeout(searchTimer)
  const requestGeneration = ++generation
  candidates.value = []
  selectedId.value = null
  error.value = ''
  matchError.value = ''
  if (!canMatch.value || completed.value || supplier.currentSupplierId !== supplierId) return
  loading.value = true
  try {
    const result = await bankApi.paymentCandidates({
      invoiceId: props.docType === 'invoice' ? props.docId : undefined,
      purchaseInvoiceId: props.docType === 'purchase_invoice' ? props.docId : undefined,
      search: search.value.trim() || undefined,
      page: targetPage,
      ...(batchMode.value ? { batch: true } : {}),
    })
    if (disposed || requestGeneration !== generation) return
    candidates.value = result.items
    page.value = result.page
    pages.value = result.pages
    total.value = result.total
  } catch (e) {
    if (!disposed && requestGeneration === generation) error.value = apiErrorMessage(e, t('bank.invoice_match.load_failed'))
  } finally {
    if (!disposed && requestGeneration === generation) loading.value = false
  }
}

function toggleBatch() {
  batchMode.value = !batchMode.value
  batchSelected.value = []
  void load()
}

function closeReview(matched: boolean) {
  reviewIds.value = null
  if (matched) emit('close')
  else void nextTick(() => reviewButton.value?.focus())
}

async function confirmMatch() {
  if (!selected.value || matching.value || loading.value || !canMatch.value || supplier.currentSupplierId !== supplierId) return
  const transactionId = selected.value.id
  matching.value = true
  try {
    const matched = await actions.matchDocument(transactionId, { id: props.docId, type: props.docType, ref: props.docRef })
    if (!matched || disposed || supplier.currentSupplierId !== supplierId) return
    completed.value = true
    generation++
    candidates.value = []
    selectedId.value = null
    if (!purchaseShortfall.value) emit('close')
  } catch (e) {
    if (!disposed && supplier.currentSupplierId === supplierId) matchError.value = apiErrorMessage(e, t('bank.invoice_match.match_failed'))
  } finally {
    matching.value = false
  }
}

watch(search, () => {
  generation++
  loading.value = true
  candidates.value = []
  selectedId.value = null
  clearTimeout(searchTimer)
  searchTimer = setTimeout(() => { void load() }, 300)
})
watch(() => [props.docType, props.docId], () => {
  batchMode.value = false
  batchSelected.value = []
  reviewIds.value = null
  void load()
})
watch(() => supplier.currentSupplierId, () => {
  generation++
  clearTimeout(searchTimer)
  emit('close')
})
watch(canMatch, allowed => {
  if (!allowed) {
    generation++
    clearTimeout(searchTimer)
    emit('close')
  }
})
watch(purchaseShortfall, value => {
  if (completed.value && !value) emit('close')
})
useHotkey('escape', () => {
  if (!completed.value && !reviewIds.value) close()
})
onMounted(() => {
  if (!canMatch.value) {
    emit('close')
    return
  }
  void load()
  void nextTick(() => searchInput.value?.focus())
})
onBeforeUnmount(() => {
  disposed = true
  generation++
  clearTimeout(searchTimer)
})
</script>

<template>
  <BankMatchModal :actions="actions" />
  <BankBatchMatchModal v-if="reviewIds" :ids="reviewIds" :document="{ type: docType, id: docId, ref: docRef }"
    @done="emit('done')" @close="closeReview" />
  <div v-if="!completed && !reviewIds" class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4" @click.self="close">
    <section role="dialog" aria-modal="true" :aria-label="t('bank.invoice_match.title')"
      class="bg-surface w-full max-w-2xl max-h-[90vh] overflow-y-auto rounded-xl p-5 shadow-lg">
      <div class="mb-2 flex flex-wrap items-start justify-between gap-2">
        <h3 class="text-lg font-semibold">{{ t('bank.invoice_match.title') }}</h3>
        <button type="button" :class="btnOutline('neutral')" :disabled="matching" @click="close" :aria-label="t('bank.invoice_match.close')">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('bank.invoice_match.close') }}
        </button>
      </div>
      <p class="mb-3 text-sm text-neutral-600">{{ t('bank.invoice_match.document', { ref: docRef || `#${docId}` }) }}</p>
      <p class="text-sm text-neutral-500" :class="batchAvailable ? 'mb-2' : 'mb-4'">{{ t(batchMode ? 'bank.batch_match.select_hint' : 'bank.invoice_match.hint') }}</p>
      <button v-if="batchAvailable" type="button" :class="btnOutline('neutral')" class="mb-4"
        :disabled="matching" data-test="invoice-bank-batch-mode" @click="toggleBatch">
        {{ t(batchMode ? 'bank.batch_match.single' : 'bank.batch_match.multiple') }}
      </button>
      <form class="mb-4 flex flex-wrap items-center gap-2" @submit.prevent="load()">
        <label class="min-w-0 flex-1">
          <span class="sr-only">{{ t('bank.invoice_match.search') }}</span>
          <input ref="searchInput" v-model="search" type="search" :disabled="matching"
            :placeholder="t('bank.invoice_match.search_placeholder')" class="w-full rounded-md border border-neutral-300 bg-surface px-3 py-2 text-sm" />
        </label>
        <button type="submit" :disabled="matching || !canMatch" :class="btnOutline('primary')">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.search" /></svg>
          {{ t('bank.invoice_match.search') }}
        </button>
      </form>
      <div v-if="error" role="alert" class="mb-3 rounded-md border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700">
        {{ error }}
        <button type="button" :class="btnOutline('danger')" class="mt-2" @click="load(page)">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.cycle" /></svg>
          {{ t('bank.invoice_match.retry') }}
        </button>
      </div>
      <p v-if="loading" role="status" class="py-4 text-center text-sm text-neutral-500">{{ t('bank.invoice_match.loading') }}</p>
      <p v-else-if="!error && !candidates.length" class="py-4 text-center text-sm text-neutral-500">{{ t('bank.invoice_match.empty') }}</p>
      <fieldset v-else-if="!error" class="space-y-2" :disabled="matching">
        <legend class="sr-only">{{ t(batchMode ? 'bank.batch_match.multiple' : 'bank.invoice_match.select_payment') }}</legend>
        <label v-for="candidate in candidates" :key="candidate.id" class="flex cursor-pointer items-start gap-3 rounded-lg border p-3"
          :class="(batchMode ? batchSelected.includes(candidate.id) : selectedId === candidate.id) ? 'border-primary-400 bg-primary-50' : 'border-neutral-200 hover:bg-neutral-50'">
          <input v-if="batchMode" v-model="batchSelected" type="checkbox" :value="candidate.id" class="mt-1 text-primary-600"
            :disabled="batchSelected.length >= BANK_BATCH_MATCH_LIMIT && !batchSelected.includes(candidate.id)" />
          <input v-else v-model="selectedId" type="radio" name="bank-payment-candidate" :value="candidate.id" class="mt-1 text-primary-600" />
          <span class="min-w-0 flex-1">
            <span class="flex flex-wrap justify-between gap-x-3 gap-y-1">
              <span class="font-medium">{{ candidate.counterparty_name || t('bank.invoice_match.unknown_counterparty') }}</span>
              <span class="whitespace-nowrap font-mono font-semibold">{{ formatMoney(candidate.amount, candidate.currency) }}</span>
            </span>
            <span class="mt-1 block text-xs text-neutral-500">
              {{ formatDate(candidate.posted_at) }} · {{ candidate.account_number }}<span v-if="candidate.bank_code">/{{ candidate.bank_code }}</span>
              <span v-if="candidate.variable_symbol"> · {{ t('bank.invoice_match.variable_symbol', { symbol: candidate.variable_symbol }) }}</span>
            </span>
            <span v-if="candidate.description" class="mt-1 block break-words text-sm text-neutral-600">{{ candidate.description }}</span>
            <span v-if="candidate.bank_ref" class="mt-1 block break-all font-mono text-xs text-neutral-400">{{ candidate.bank_ref }}</span>
          </span>
        </label>
      </fieldset>
      <div v-if="pages > 1 && !loading && !error" class="mt-3 flex flex-wrap items-center justify-center gap-2">
        <button type="button" :disabled="page <= 1 || matching" :class="btnOutline('neutral')" @click="load(page - 1)">
          <svg class="h-4 w-4 rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
          {{ t('bank.invoice_match.previous') }}
        </button>
        <span class="text-sm text-neutral-500">{{ t('bank.invoice_match.page', { page, pages, total }) }}</span>
        <button type="button" :disabled="page >= pages || matching" :class="btnOutline('neutral')" @click="load(page + 1)">
          {{ t('bank.invoice_match.next') }}
          <svg class="h-4 w-4 -rotate-90" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.chevron" /></svg>
        </button>
      </div>
      <p v-if="matchError" role="alert" class="mt-3 rounded-md border border-danger-200 bg-danger-50 p-3 text-sm text-danger-700">{{ matchError }}</p>
      <div class="mt-4 flex flex-wrap items-center justify-between gap-3 border-t border-neutral-200 pt-4">
        <p v-if="batchMode" class="text-sm text-neutral-600" aria-live="polite">{{ t('bank.batch_match.selection_count', { count: batchSelected.length, limit: BANK_BATCH_MATCH_LIMIT }) }}</p>
        <p v-else-if="selected" class="min-w-0 text-sm text-neutral-600">{{ t('bank.invoice_match.selected', { amount: formatMoney(selected.amount, selected.currency), date: formatDate(selected.posted_at), ref: docRef || `#${docId}` }) }}</p>
        <button v-if="batchMode" ref="reviewButton" type="button" :class="btnFilled('primary')" data-test="review-invoice-bank-batch"
          :disabled="batchSelected.length < 2 || loading || !canMatch" @click="reviewIds = [...batchSelected]">
          {{ t('bank.batch_match.review') }}
        </button>
        <button v-else type="button" data-testid="confirm-match" :disabled="!selected || loading || matching || !canMatch" :class="btnFilled('success')" @click="confirmMatch">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
          {{ t(matching ? 'bank.invoice_match.matching' : 'bank.invoice_match.confirm') }}
        </button>
      </div>
    </section>
  </div>
</template>
