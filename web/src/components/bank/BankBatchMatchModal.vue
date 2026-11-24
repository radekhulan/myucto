<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { bankApi, type BankBatchMatchPreview, type BankBatchMatchResult, type BankBatchMatchTarget, type MatchCandidate } from '@/api/bank'
import { apiErrorMessage, postingPeriodErrorKey } from '@/api/errors'
import { POSTING_REASON_KEYS } from '@/composables/useBankTransactionActions'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { useSupplierStore } from '@/stores/supplier'
import Modal from '@/components/ui/Modal.vue'
import { btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { formInput } from '@/components/ui/formStyles'

const props = defineProps<{ ids: number[]; document?: BankBatchMatchTarget }>()
const emit = defineEmits<{ close: [matched: boolean]; done: [] }>()
const { t } = useI18n()
const supplier = useSupplierStore()
const supplierId = supplier.currentSupplierId
const target = ref<BankBatchMatchTarget | null>(props.document ?? null)
const preview = ref<BankBatchMatchPreview | null>(null)
const result = ref<BankBatchMatchResult | null>(null)
const resultById = computed(() => new Map(result.value?.items.map(item => [item.id, item.posting])))
const busy = ref(false)
const previewLoading = ref(false)
const error = ref('')
const failedId = ref<number | null>(null)
const search = ref('')
const searchInput = ref<HTMLInputElement | null>(null)
const summary = ref<HTMLElement | null>(null)
const matches = ref<MatchCandidate[]>([])
const searched = ref(false)
const searching = ref(false)
let searchTimer: ReturnType<typeof setTimeout> | undefined
let searchVersion = 0
let disposed = false

function active() {
  return !disposed && supplier.currentSupplierId === supplierId
}

async function findDocuments() {
  clearTimeout(searchTimer)
  const version = ++searchVersion
  const query = search.value.trim()
  if (!query || target.value) return
  searching.value = true
  try {
    const found = await bankApi.matchCandidates(props.ids[0]!, query, true)
    if (active() && version === searchVersion) {
      matches.value = found.candidates
      searched.value = true
    }
  } catch (e) {
    if (active() && version === searchVersion) error.value = apiErrorMessage(e, t('bank.batch_match.search_failed'))
  } finally {
    if (active() && version === searchVersion) searching.value = false
  }
}

async function loadPreview() {
  if (!target.value || previewLoading.value || busy.value) return
  previewLoading.value = true
  error.value = ''
  failedId.value = null
  preview.value = null
  try {
    const data = await bankApi.previewBatchMatch(target.value, props.ids)
    if (active()) {
      preview.value = data
      await nextTick()
      summary.value?.focus()
    }
  } catch (e) {
    if (active()) error.value = apiErrorMessage(e, t('bank.batch_match.preview_failed'))
  } finally {
    previewLoading.value = false
  }
}

function selectDocument(candidate: MatchCandidate) {
  if (candidate.type === 'other_item') return
  target.value = { type: candidate.type, id: candidate.id, ref: candidate.ref }
  void loadPreview()
}

function back() {
  if (busy.value || previewLoading.value) return
  if (props.document) {
    emit('close', false)
    return
  }
  target.value = null
  preview.value = null
  error.value = ''
  void nextTick(() => searchInput.value?.focus())
}

async function confirm() {
  if (!target.value || !preview.value || preview.value.blocked || busy.value || !active()) return
  busy.value = true
  error.value = ''
  try {
    const data = await bankApi.confirmBatchMatch(target.value, props.ids, preview.value.version)
    if (active()) {
      result.value = data
      emit('done')
      await nextTick()
      summary.value?.focus()
    }
  } catch (e) {
    if (active()) {
      error.value = apiErrorMessage(e, t('bank.batch_match.match_failed'))
      const id: unknown = (e as { response?: { data?: { error?: { transaction_id?: unknown } } } } | null)?.response?.data?.error?.transaction_id
      failedId.value = typeof id === 'number' ? id : null
    }
  } finally {
    busy.value = false
  }
}

function postingLabel(id: number): string {
  const posting = resultById.value.get(id)
  if (posting?.action === 'posted') return t('bank.posting.matched_and_posted')
  if (!posting || posting.reason === 'not_double_entry') return t('bank.batch_match.matched')
  const key = posting.reason && POSTING_REASON_KEYS[posting.reason]
  if (posting.action === 'suggested' && !postingPeriodErrorKey(posting.reason ?? '')) return t('bank.posting.matched_suggested')
  return key ? t('bank.posting.matched_not_posted', { reason: t(key) }) : t('bank.batch_match.posting_pending')
}

watch(search, () => {
  clearTimeout(searchTimer)
  searchVersion++
  searched.value = false
  matches.value = []
  error.value = ''
  searching.value = !!search.value.trim()
  if (searching.value) searchTimer = setTimeout(() => { void findDocuments() }, 300)
})
watch(() => supplier.currentSupplierId, () => emit('close', !!result.value))
onMounted(() => {
  if (target.value) void loadPreview()
  else void nextTick(() => searchInput.value?.focus())
})
onBeforeUnmount(() => {
  disposed = true
  clearTimeout(searchTimer)
})
</script>

<template>
  <Modal :title="t('bank.batch_match.title')" width-class="max-w-2xl"
    @close="!busy && emit('close', !!result)">
    <div v-if="!target" class="space-y-3">
      <p class="text-sm text-neutral-600">{{ t('bank.batch_match.pick_document', { count: ids.length }) }}</p>
      <form class="flex flex-wrap items-end gap-2" @submit.prevent="findDocuments">
        <label class="min-w-0 flex-1 text-sm font-medium">
          {{ t('bank.candidate_search_label') }}
          <input ref="searchInput" v-model="search" type="search" :class="formInput" class="mt-1"
            :placeholder="t('bank.candidate_search_placeholder')" />
        </label>
        <button type="submit" :disabled="!search.trim()" :class="btnOutline('primary')">{{ t('bank.invoice_match.search') }}</button>
      </form>
      <p v-if="searching" role="status" class="text-sm text-neutral-600">{{ t('common.loading') }}</p>
      <p v-else-if="searched && !matches.length" class="text-sm text-neutral-600">{{ t('bank.candidate_search_empty') }}</p>
      <ul v-if="matches.length" class="divide-y divide-neutral-200 rounded-lg border border-neutral-200">
        <li v-for="candidate in matches" :key="`${candidate.type}:${candidate.id}`">
          <button type="button" class="flex w-full flex-wrap justify-between gap-x-3 gap-y-1 p-3 text-left text-sm hover:bg-neutral-50"
            @click="selectDocument(candidate)">
            <span class="min-w-0 break-words"><strong>{{ candidate.ref || `#${candidate.id}` }}</strong><span v-if="candidate.party"> · {{ candidate.party }}</span></span>
            <span class="tabular-nums">{{ formatMoney(candidate.amount, candidate.currency) }}</span>
          </button>
        </li>
      </ul>
    </div>
    <div v-else class="space-y-3">
      <p class="text-sm font-medium">{{ t('bank.invoice_match.document', { ref: target.ref || `#${target.id}` }) }}</p>
      <p v-if="previewLoading" role="status" class="text-sm text-neutral-600">{{ t('common.loading') }}</p>
      <template v-if="preview">
        <div ref="summary" tabindex="-1" class="space-y-3">
          <p v-if="result" role="status" class="text-sm font-semibold text-success-700">{{ t('bank.batch_match.success', { count: result.items.length }) }}</p>
          <dl class="grid gap-3 rounded-lg bg-neutral-50 p-3 text-sm sm:grid-cols-3">
            <div>
              <dt>{{ t('bank.batch_match.selected_total') }}</dt>
              <dd v-if="preview.selected_cents !== null" class="font-semibold tabular-nums">{{ formatMoney(preview.selected_cents / 100, preview.currency) }}</dd>
              <dd v-else class="font-semibold">{{ t('bank.batch_match.multiple_currencies') }}</dd>
            </div>
            <div>
              <dt>{{ t('bank.batch_match.paid') }}</dt>
              <dd class="font-semibold tabular-nums">{{ formatMoney((preview.paid_cents + (result ? (preview.selected_cents ?? 0) : 0)) / 100, preview.currency) }}</dd>
            </div>
            <div>
              <dt>{{ t(result ? 'bank.batch_match.remaining' : 'bank.batch_match.remaining_before') }}</dt>
              <dd class="font-semibold tabular-nums">{{ formatMoney((preview.remaining_cents - (result ? (preview.selected_cents ?? 0) : 0)) / 100, preview.currency) }}</dd>
            </div>
          </dl>
        </div>
        <p v-if="preview.blocked" role="alert" class="text-sm text-danger-700">{{ t(`bank.batch_match.${preview.blocked}`) }}</p>
        <ul class="divide-y divide-neutral-200 rounded-lg border border-neutral-200">
          <li v-for="item in preview.items" :key="item.id" class="space-y-1 px-3 py-2 text-sm"
            :class="{ 'bg-danger-50': item.id === failedId }">
            <div class="flex flex-wrap items-baseline justify-between gap-x-3">
              <span class="min-w-0 break-words font-medium">{{ item.counterparty_name || t('bank.invoice_match.unknown_counterparty') }}</span>
              <span class="whitespace-nowrap tabular-nums">{{ formatMoney(item.amount, item.currency) }}</span>
            </div>
            <p class="break-words text-xs text-neutral-600">
              {{ formatDate(item.posted_at) }} · {{ item.account_number }}<span v-if="item.bank_code">/{{ item.bank_code }}</span>
              <span v-if="item.variable_symbol"> · {{ t('bank.invoice_match.variable_symbol', { symbol: item.variable_symbol }) }}</span>
            </p>
            <p v-if="item.description" class="break-words text-xs text-neutral-600">{{ item.description }}</p>
            <p v-if="result" class="text-xs text-neutral-700">{{ postingLabel(item.id) }}</p>
          </li>
        </ul>
      </template>
    </div>
    <p v-if="error" role="alert" class="mt-3 rounded-md bg-danger-50 p-3 text-sm text-danger-700">{{ error }}</p>
    <template #footer>
      <div class="flex flex-wrap justify-end gap-2">
        <button v-if="result || !target" type="button" :class="btnOutline('neutral')" @click="emit('close', !!result)">
          {{ t('bank.invoice_match.close') }}
        </button>
        <template v-else>
          <button type="button" :class="btnOutline('neutral')" :disabled="busy || previewLoading" @click="back">{{ t('common.back') }}</button>
          <button v-if="error" type="button" :class="btnOutline('primary')" :disabled="busy || previewLoading" @click="loadPreview">
            {{ t('bank.batch_match.reload_preview') }}
          </button>
          <button v-else type="button" :class="btnFilled('success')" data-test="confirm-bank-batch"
            :disabled="!preview || !!preview.blocked || busy" @click="confirm">
            {{ t(busy ? 'bank.invoice_match.matching' : 'bank.batch_match.confirm', { count: ids.length }) }}
          </button>
        </template>
      </div>
    </template>
  </Modal>
</template>
