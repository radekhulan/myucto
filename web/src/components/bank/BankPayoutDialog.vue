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

const selected = ref<DocumentOption[]>([])
const results = ref<DocumentOption[]>([])
const options = computed(() => results.value.filter(option => !selected.value.some(document => document.value === option.value)))
const totalCents = computed(() => Math.round(props.transaction.amount * 100))
const selectedCents = computed(() => selected.value.reduce((sum, document) => sum + document.amountCents, 0))
const difference = computed(() => totalCents.value - selectedCents.value)
const atLimit = computed(() => selected.value.length >= MAX_DOCUMENTS)
const canConfirm = computed(() => selected.value.length >= 2 && difference.value === 0 && !props.saving)
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
  selected.value.push(document)
  pickerVersion.value++
  void searchDocuments('')
}

function removeDocument(id: number) {
  selected.value = selected.value.filter(document => document.value !== id)
}

function confirm() {
  if (!canConfirm.value) return
  emit('confirm', selected.value.map(document => ({
    invoice_id: document.value,
    amount_cents: document.amountCents,
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
      <li v-for="document in selected" :key="document.value" class="flex items-center gap-2 rounded-md border border-neutral-200 px-3 py-2">
        <div class="min-w-0 flex-1 text-sm">
          <p class="break-words font-medium">{{ document.label }}</p>
          <p class="break-words text-xs text-neutral-500">{{ document.secondary }}</p>
        </div>
        <button type="button" :class="[BTN_BASE, OUTLINE.neutral, 'shrink-0 !px-2']"
          :aria-label="t('bank.payout.remove_document', { document: document.label })"
          :title="t('common.remove')" :disabled="saving" @click="removeDocument(document.value)">
          <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
        </button>
      </li>
    </ul>

    <dl class="mt-4 space-y-1 rounded-md bg-neutral-50 p-3 text-sm" aria-live="polite">
      <div class="flex flex-wrap justify-between gap-x-3">
        <dt>{{ t('bank.payout.transfer_amount') }}</dt><dd class="font-mono">{{ money(totalCents) }}</dd>
      </div>
      <div class="flex flex-wrap justify-between gap-x-3">
        <dt>{{ t('bank.payout.selected_amount') }}</dt><dd class="font-mono">{{ money(selectedCents) }}</dd>
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
