<script setup lang="ts">
import { computed, onBeforeUnmount, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import { invoicesApi } from '@/api/invoices'
import { apiErrorMessage } from '@/api/errors'
import type { BankTransaction, PayoutAllocation } from '@/api/bank'
import Modal from '@/components/ui/Modal.vue'
import SearchableSelect from '@/components/ui/SearchableSelect.vue'
import { BTN_BASE, FILLED, ICONS, OUTLINE } from '@/components/ui/buttonStyles'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { formControl } from '@/components/ui/formStyles'

const props = defineProps<{ transaction: BankTransaction; saving: boolean; error: string }>()
const emit = defineEmits<{ close: []; confirm: [allocations: PayoutAllocation[]] }>()
const { t } = useI18n()
const inputId = useId()
const MAX_DOCUMENTS = 100
const SEARCH_LIMIT = 20

type DocumentOption = {
  value: number
  label: string
  secondary: string
  amountCents: number
}

type SelectedDocument = DocumentOption & { amount: number | string }

const selected = ref<SelectedDocument[]>([])
const results = ref<DocumentOption[]>([])
const options = computed(() => results.value.filter(option => !selected.value.some(document => document.value === option.value)))
const totalCents = computed(() => Math.round(props.transaction.amount * 100))
// Zbytek dokladu do tolerance úhrady (InvoicePaymentService::TOLERANCE) by backend
// považoval za uhrazený, proto ho částečná úhrada nesmí nechat.
const SETTLED_TOLERANCE_CENTS = 5
const AMOUNT_PATTERN = /^(\d+)(?:\.(\d{1,2}))?$/

function parseCents(value: number | string): number | null {
  const match = AMOUNT_PATTERN.exec(String(value).trim().replace(',', '.'))
  if (!match) return null
  return Number(match[1]) * 100 + Number((match[2] ?? '').padEnd(2, '0'))
}
const allocatedCents = (document: SelectedDocument) => parseCents(document.amount) ?? 0
type AmountError = 'invalid_amount' | 'settle_rest' | null
const amountError = (document: SelectedDocument): AmountError => {
  const cents = parseCents(document.amount)
  if (cents === null || cents <= 0 || cents > document.amountCents) return 'invalid_amount'
  const rest = document.amountCents - cents
  return rest > 0 && rest <= SETTLED_TOLERANCE_CENTS ? 'settle_rest' : null
}
const invalidAmount = (document: SelectedDocument) => amountError(document) !== null
const openCents = computed(() => selected.value.reduce((sum, document) => sum + document.amountCents, 0))
const selectedCents = computed(() => selected.value.reduce((sum, document) => sum + allocatedCents(document), 0))
const difference = computed(() => totalCents.value - selectedCents.value)
const atLimit = computed(() => selected.value.length >= MAX_DOCUMENTS)
const hasInvalid = computed(() => selected.value.some(invalidAmount))
const lastDocument = computed(() => selected.value.at(-1) ?? null)
const partialLastCents = computed(() => {
  const last = lastDocument.value
  if (!last || selected.value.length < 2) return 0
  return totalCents.value - selected.value.slice(0, -1).reduce((sum, document) => sum + allocatedCents(document), 0)
})
const canPayLastPartially = computed(() => difference.value < 0 && lastDocument.value !== null
  && partialLastCents.value > 0 && partialLastCents.value < lastDocument.value.amountCents - SETTLED_TOLERANCE_CENTS
  && allocatedCents(lastDocument.value) !== partialLastCents.value && !props.saving)
const canConfirm = computed(() => selected.value.length >= 2 && difference.value === 0 && !hasInvalid.value && !props.saving)
const pickerVersion = ref(0)
const loading = ref(false)
const searched = ref(false)
const truncated = ref(false)
const searchError = ref('')
let searchVersion = 0
onBeforeUnmount(() => { searchVersion++ })

const money = (amountCents: number) => formatMoney(amountCents / 100, 'CZK')

async function searchDocuments(value: string) {
  const query = value.trim()
  const version = ++searchVersion
  results.value = []
  searchError.value = ''
  truncated.value = false
  searched.value = query.length >= 2
  loading.value = searched.value
  if (!searched.value) return
  try {
    const invoices = await invoicesApi.searchOpen(query, SEARCH_LIMIT, 'CZK')
    if (version !== searchVersion) return
    truncated.value = invoices.length === SEARCH_LIMIT
    results.value = invoices.flatMap(invoice => {
      const amountCents = Math.round((invoice.remaining_amount ?? (invoice.amount_to_pay - (invoice.paid_total ?? 0))) * 100)
      if (invoice.currency !== 'CZK' || !invoice.client_id || amountCents <= 0) return []
      const type = t(invoice.invoice_type === 'proforma' ? 'bank.payout.proforma' : 'bank.invoice')
      const reference = invoice.varsymbol || `#${invoice.id}`
      return [{
        value: invoice.id,
        label: invoice.client_company_name ? `${reference} · ${invoice.client_company_name}` : reference,
        secondary: `${type} · ${formatDate(invoice.due_date || invoice.issue_date)} · ${money(amountCents)}`,
        amountCents,
      }]
    })
  } catch (error) {
    if (version === searchVersion) searchError.value = apiErrorMessage(error, t('bank.payout.search_failed'))
  } finally {
    if (version === searchVersion) loading.value = false
  }
}

function addDocument(id: number | null) {
  const document = options.value.find(option => option.value === id)
  if (!document || props.saving || atLimit.value) return
  selected.value.push({ ...document, amount: document.amountCents / 100 })
  pickerVersion.value++
  void searchDocuments('')
}

function removeDocument(id: number) {
  selected.value = selected.value.filter(document => document.value !== id)
}

function payLastPartially() {
  if (!canPayLastPartially.value || !lastDocument.value) return
  lastDocument.value.amount = partialLastCents.value / 100
}

function confirm() {
  if (!canConfirm.value) return
  emit('confirm', selected.value.map(document => ({
    invoice_id: document.value,
    amount_cents: allocatedCents(document),
  })))
}
</script>

<template>
  <Modal :title="t('bank.payout.action')" width-class="max-w-xl" @close="emit('close')">
    <p class="mb-1 text-sm">{{ t('bank.payout.instructions') }}</p>
    <p class="mb-4 text-xs text-neutral-500">
      {{ formatDate(transaction.posted_at) }}
      <span v-if="transaction.counterparty_name"> · {{ transaction.counterparty_name }}</span>
    </p>

    <label :for="inputId" class="mb-1 block text-sm font-medium">{{ t('bank.payout.find_document') }}</label>
    <SearchableSelect
      :key="pickerVersion"
      :input-id="inputId"
      :model-value="null"
      :options="options"
      :placeholder="t('bank.payout.search_placeholder')"
      :no-results-label="t(searched ? 'common.no_results' : 'bank.payout.search_hint')"
      :loading-label="t('common.loading')"
      :truncated-label="t('bank.payout.search_truncated')"
      :loading="loading"
      :truncated="truncated"
      :disabled="saving || atLimit"
      remote teleport
      @search="searchDocuments"
      @update:model-value="addDocument"
    />
    <p v-if="searchError" role="alert" class="mt-2 text-sm text-danger-600">{{ searchError }}</p>
    <p v-if="atLimit" class="mt-2 text-sm text-neutral-500">{{ t('bank.payout.limit', { count: MAX_DOCUMENTS }) }}</p>

    <ul v-if="selected.length" :aria-label="t('bank.payout.selected_documents')" class="mt-4 space-y-2">
      <li v-for="document in selected" :key="document.value" class="flex flex-wrap items-center gap-2 rounded-md border border-neutral-200 px-3 py-2">
        <div class="min-w-0 flex-1 basis-full text-sm sm:basis-auto">
          <p class="break-words font-medium">{{ document.label }}</p>
          <p class="break-words text-xs text-neutral-500">{{ document.secondary }}</p>
          <p v-if="!invalidAmount(document) && allocatedCents(document) < document.amountCents" class="text-xs text-warning-700">
            {{ t('bank.payout.partial_note', { amount: money(document.amountCents - allocatedCents(document)) }) }}
          </p>
          <p v-if="amountError(document)" role="alert" class="text-xs text-danger-600">
            {{ amountError(document) === 'settle_rest'
              ? t('bank.payout.settle_rest', { amount: money(document.amountCents) })
              : t('bank.payout.invalid_amount', { amount: money(document.amountCents) }) }}
          </p>
        </div>
        <input v-model="document.amount" type="number" inputmode="decimal" step="0.01" min="0.01" :max="document.amountCents / 100"
          :aria-label="t('bank.payout.allocated_amount', { document: document.label })"
          :aria-invalid="invalidAmount(document) || undefined" :disabled="saving"
          :class="[formControl, 'ml-auto w-32 shrink-0 text-right font-mono', invalidAmount(document) ? 'border-danger-500' : '']">
        <button type="button" :class="[BTN_BASE, OUTLINE.neutral, 'shrink-0 !px-2']"
          :aria-label="t('bank.payout.remove_document', { document: document.label })"
          :title="t('common.remove')" :disabled="saving" @click="removeDocument(document.value)">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
        </button>
      </li>
    </ul>
    <div v-if="canPayLastPartially && lastDocument" class="mt-3 flex flex-wrap items-center gap-2 rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-sm">
      <p class="min-w-0 flex-1">{{ t('bank.payout.partial_hint', { document: lastDocument.label, amount: money(partialLastCents) }) }}</p>
      <button type="button" :class="[BTN_BASE, OUTLINE.warning, 'whitespace-nowrap']" @click="payLastPartially">
        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.coin" /></svg>
        {{ t('bank.payout.pay_last_partially') }}
      </button>
    </div>

    <dl class="mt-4 space-y-1 rounded-md bg-neutral-50 p-3 text-sm" aria-live="polite">
      <div class="flex flex-wrap justify-between gap-x-3">
        <dt>{{ t('bank.payout.transfer_amount') }}</dt><dd class="font-mono">{{ money(totalCents) }}</dd>
      </div>
      <div v-if="openCents !== selectedCents" class="flex flex-wrap justify-between gap-x-3 text-neutral-500">
        <dt>{{ t('bank.payout.open_amount') }}</dt><dd class="font-mono">{{ money(openCents) }}</dd>
      </div>
      <div class="flex flex-wrap justify-between gap-x-3">
        <dt>{{ t('bank.payout.allocated_summary') }}</dt>
        <dd class="font-mono">{{ t('bank.payout.allocated_of', { allocated: money(selectedCents), total: money(totalCents) }) }}</dd>
      </div>
      <div class="flex flex-wrap justify-between gap-x-3 font-medium" :class="difference < 0 ? 'text-danger-600' : difference === 0 ? 'text-success-700' : ''">
        <dt>{{ t(difference < 0 ? 'bank.payout.over_amount' : 'bank.payout.remaining_amount') }}</dt>
        <dd class="font-mono">{{ money(Math.abs(difference)) }}</dd>
      </div>
    </dl>
    <p v-if="error" role="alert" class="mt-3 text-sm text-danger-600">{{ error }}</p>

    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <button type="button" :class="[BTN_BASE, OUTLINE.neutral]" :disabled="saving" @click="emit('close')">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('common.cancel') }}
        </button>
        <button type="button" :class="[BTN_BASE, FILLED.primary]" :disabled="!canConfirm" @click="confirm">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
          {{ t(saving ? 'common.saving' : 'bank.payout.confirm') }}
        </button>
      </div>
    </template>
  </Modal>
</template>
