<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { otherItemsApi, type OtherItem, type OtherItemAllocation, type OtherItemPaymentCandidate, type OtherItemPostingLine } from '@/api/otherItems'
import { otherItemKindLabel } from '@/utils/otherItemKind'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { formatDate, formatMoney } from '@/composables/useFormat'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import LinkedDocumentsPanel from '@/components/documents/LinkedDocumentsPanel.vue'
import OtherItemPlans from '@/components/accounting/OtherItemPlans.vue'
import OtherItemJournalContext from '@/components/accounting/OtherItemJournalContext.vue'
import Modal from '@/components/ui/Modal.vue'
import DateInput from '@/components/ui/DateInput.vue'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import OtherItemPostingLines from '@/components/accounting/OtherItemPostingLines.vue'
import DocumentDimensionsPanel from '@/components/dimensions/DocumentDimensionsPanel.vue'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import { appIsoDate } from '@/utils/date'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const supplier = useSupplierStore()
const toast = useToast()
const id = computed(() => Number(route.params.id))
const item = ref<OtherItem | null>(null)
const allocations = ref<OtherItemAllocation[]>([])
const loading = ref(false)
const busy = ref(false)
const reverseOpen = ref(false)
const reverseReason = ref('')
const reverseDate = ref(appIsoDate())
const repostOpen = ref(false)
const repostLoading = ref(false)
const repostAccounts = ref<ChartAccount[]>([])
const repostAccountCode = ref('')
const repostCounterAccountCode = ref('')
const repostSplit = ref(false)
const repostLines = ref<OtherItemPostingLine[]>([])
const repostDate = ref(appIsoDate())
const repostReason = ref('')
const isDoubleEntry = computed(() => supplier.currentSupplier?.accounting_mode === 'double_entry')
const isTaxEvidence = computed(() => supplier.currentSupplier?.accounting_mode === 'tax_evidence')
const canEdit = computed(() => item.value?.status === 'draft' && auth.canWrite('other_items'))
const canPost = computed(() => auth.canWrite('other_items') && (!isDoubleEntry.value || auth.canWrite('accounting.journal.post')))
const canManageBankPayment = computed(() => auth.canRead('bank') && auth.canWrite('bank.match'))
const canManageCashPayment = computed(() => auth.canRead('cash') && auth.canWrite('cash.document.write'))
const canAllocate = computed(() => auth.canWrite('other_items') && (canManageBankPayment.value || canManageCashPayment.value) && item.value?.currency === 'CZK'
  && (item.value?.status === 'posted' || item.value?.status === 'confirmed') && Number(item.value?.remaining_amount || 0) > 0)
const canRepost = computed(() => isDoubleEntry.value && item.value?.status === 'posted' && !!item.value?.journal_entry_id
  && auth.canWrite('other_items') && auth.canWrite('accounting.journal.post'))
const documentFolderPath = computed(() => {
  const issuedOn = item.value?.issued_on || ''
  return /^\d{4}-\d{2}-\d{2}$/.test(issuedOn)
    ? `Ostatní pohledávky a závazky/${issuedOn.slice(0, 4)}/${issuedOn.slice(5, 7)}`
    : undefined
})
const repostAccountChoices = computed(() => repostAccounts.value.filter(account => account.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)))
const repostBalanceAccounts = computed(() => repostAccountChoices.value.filter(account =>
  account.account_type === (item.value?.side === 'receivable' ? 'asset' : 'liability'),
))
const paymentQuery = ref('')
const paymentCandidates = ref<OtherItemPaymentCandidate[]>([])
const candidatesLoading = ref(false)
const selectedPayment = ref<OtherItemPaymentCandidate | null>(null)
const allocationAmount = ref<number | null>(null)
let paymentTimer: ReturnType<typeof setTimeout> | null = null
let paymentRequest = 0

function statusLabel(value: string): string {
  const key = `other_items.status.${value}`
  return t(key) === key ? value : t(key)
}

async function load() {
  loading.value = true
  try {
    const [detail, linked] = await Promise.all([otherItemsApi.get(id.value), otherItemsApi.allocations(id.value)])
    item.value = detail
    allocations.value = linked
  } catch (error: any) {
    item.value = null
    allocations.value = []
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    loading.value = false
  }
}

async function searchPayments(query: string) {
  const request = ++paymentRequest
  candidatesLoading.value = true
  try {
    const rows = await otherItemsApi.paymentCandidates(id.value, query.trim())
    if (request === paymentRequest) paymentCandidates.value = rows
  } catch (error: any) {
    if (request === paymentRequest) toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    if (request === paymentRequest) candidatesLoading.value = false
  }
}

watch(paymentQuery, query => {
  if (paymentTimer) clearTimeout(paymentTimer)
  paymentTimer = setTimeout(() => void searchPayments(query), 250)
})
onBeforeUnmount(() => { if (paymentTimer) clearTimeout(paymentTimer) })

function selectPayment(candidate: OtherItemPaymentCandidate) {
  selectedPayment.value = candidate
  allocationAmount.value = Math.min(Math.abs(Number(candidate.amount)), Number(item.value?.remaining_amount || 0))
  paymentCandidates.value = []
}

async function allocate() {
  const candidate = selectedPayment.value
  if (!candidate || !allocationAmount.value || allocationAmount.value <= 0 || busy.value) return
  busy.value = true
  try {
    await otherItemsApi.allocate(id.value, {
      ...(candidate.source === 'bank' ? { bank_transaction_id: candidate.id } : { cash_document_id: candidate.id }),
      amount: Number(allocationAmount.value),
    })
    toast.success(t('other_items.payment_linked'))
    selectedPayment.value = null
    allocationAmount.value = null
    paymentQuery.value = ''
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function unallocate(allocation: OtherItemAllocation) {
  if (busy.value || !window.confirm(t('other_items.confirm_unlink_payment'))) return
  busy.value = true
  try {
    await otherItemsApi.unallocate(id.value, allocation.id)
    toast.success(t('other_items.payment_unlinked'))
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function post() {
  if (!item.value || busy.value) return
  if (!window.confirm(t(isDoubleEntry.value ? 'other_items.confirm_post' : 'other_items.confirm_confirm'))) return
  busy.value = true
  try {
    await otherItemsApi.post(id.value)
    toast.success(t(isDoubleEntry.value ? 'other_items.posted' : 'other_items.confirmed'))
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function reverse() {
  if (!item.value || busy.value) return
  if (reverseReason.value.trim().length < 3) {
    toast.error(t('other_items.reason_required'))
    return
  }
  busy.value = true
  try {
    await otherItemsApi.reverse(id.value, { reason: reverseReason.value.trim(), ...(isDoubleEntry.value ? { entry_date: reverseDate.value } : {}) })
    toast.success(t(isDoubleEntry.value ? 'other_items.reversed' : 'other_items.cancelled'))
    reverseOpen.value = false
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function openRepost() {
  if (!canRepost.value || !item.value || Number(item.value.paid_amount) > 0 || repostLoading.value) return
  repostLoading.value = true
  try {
    repostAccounts.value = await accountingApi.listAccounts()
    repostAccountCode.value = item.value.account_code || (item.value.side === 'receivable' ? '315' : '325')
    repostCounterAccountCode.value = item.value.counter_account_code || ''
    const lines = item.value.posting_lines || []
    repostSplit.value = lines.length > 1
    repostLines.value = repostSplit.value ? lines.map(line => ({ account_code: line.account_code, amount: Number(line.amount) })) : []
    if (!repostSplit.value && lines.length === 1) repostCounterAccountCode.value = lines[0].account_code
    repostDate.value = appIsoDate()
    repostReason.value = ''
    repostOpen.value = true
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    repostLoading.value = false
  }
}

function startRepostSplit() {
  repostLines.value = [
    { account_code: repostCounterAccountCode.value, amount: Number(item.value?.amount || 0) },
    { account_code: '', amount: 0 },
  ]
  repostSplit.value = true
}

function stopRepostSplit() {
  repostCounterAccountCode.value = repostLines.value[0]?.account_code || ''
  repostLines.value = []
  repostSplit.value = false
}

async function repost() {
  if (!canRepost.value || !item.value || busy.value) return
  if (!repostAccountCode.value || (!repostSplit.value && !repostCounterAccountCode.value)
    || !repostDate.value || repostReason.value.trim().length < 3) {
    toast.error(t('other_items.repost_required'))
    return
  }
  if (repostSplit.value && (repostLines.value.some(line => !line.account_code || !Number.isFinite(line.amount) || line.amount <= 0)
    || repostLines.value.reduce((sum, line) => sum + Math.round(line.amount * 100), 0) !== Math.round(Number(item.value.amount) * 100))) {
    toast.error(t('other_items.posting_lines_invalid'))
    return
  }
  const currentLines = item.value.posting_lines || (item.value.counter_account_code
    ? [{ account_code: item.value.counter_account_code, amount: Number(item.value.amount) }] : [])
  const nextLines = repostSplit.value ? repostLines.value : [{ account_code: repostCounterAccountCode.value, amount: Number(item.value.amount) }]
  if (repostAccountCode.value === (item.value.account_code || (item.value.side === 'receivable' ? '315' : '325'))
    && JSON.stringify(nextLines.map(line => [line.account_code, Math.round(line.amount * 100)]))
      === JSON.stringify(currentLines.map(line => [line.account_code, Math.round(Number(line.amount) * 100)]))) {
    toast.error(t('other_items.repost_unchanged'))
    return
  }
  busy.value = true
  try {
    await otherItemsApi.repost(id.value, {
      account_code: repostAccountCode.value,
      ...(repostSplit.value ? { posting_lines: repostLines.value } : { counter_account_code: repostCounterAccountCode.value }),
      entry_date: repostDate.value,
      reason: repostReason.value.trim(),
    })
    repostOpen.value = false
    toast.success(t('other_items.reposted'))
    await load()
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

async function remove() {
  if (!item.value || busy.value || !window.confirm(t('other_items.confirm_delete'))) return
  busy.value = true
  try {
    await otherItemsApi.remove(id.value)
    toast.success(t('common.deleted'))
    await router.push({ name: 'other-items' })
  } catch (error: any) {
    toast.error(error?.response?.data?.error?.message || t('common.error'))
  } finally {
    busy.value = false
  }
}

const actions = computed<ActionItem[]>(() => {
  const current = item.value
  if (!current) return []
  return [
    { key: 'post', label: t(isDoubleEntry.value ? 'other_items.post' : 'other_items.confirm'), icon: 'check', tier: 'primary', variant: 'success',
      show: current.status === 'draft' && canPost.value, disabled: busy.value, run: () => void post() },
    { key: 'edit', label: t('common.edit'), icon: 'edit', tier: 'secondary', variant: 'neutral',
      show: canEdit.value, to: { name: 'other-item-edit', params: { id: id.value } } },
    { key: 'reverse', label: t(isDoubleEntry.value ? 'other_items.reverse' : 'other_items.cancel'), icon: 'uturn', tier: 'overflow', variant: 'warning',
      show: (current.status === 'posted' || current.status === 'confirmed') && canPost.value,
      disabled: busy.value || Number(current.paid_amount) > 0,
      disabledReason: Number(current.paid_amount) > 0 ? t('other_items.reverse_paid') : undefined,
      run: () => { reverseReason.value = ''; reverseDate.value = appIsoDate(); reverseOpen.value = true } },
    { key: 'delete', label: t('common.delete'), icon: 'trash', tier: 'overflow', variant: 'danger',
      show: current.status === 'draft' && auth.canWrite('other_items'), disabled: busy.value, run: () => void remove() },
  ]
})

const postingActions = computed<ActionItem[]>(() => [
  { key: 'repost', label: t('other_items.repost'), icon: 'edit', tier: 'secondary', variant: 'warning',
    show: canRepost.value, disabled: busy.value || repostLoading.value || Number(item.value?.paid_amount || 0) > 0,
    disabledReason: Number(item.value?.paid_amount || 0) > 0 ? t('other_items.repost_paid') : undefined,
    run: () => void openRepost() },
])

watch(id, () => void load())
onMounted(load)
</script>

<template>
  <div>
    <div v-if="loading" class="py-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <div v-else-if="!item" class="py-12 text-center text-sm text-neutral-500">{{ t('other_items.not_found') }}</div>
    <template v-else>
      <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
        <div>
          <RouterLink :to="{ name: 'other-items' }" class="text-sm text-primary-700 hover:underline">← {{ t('other_items.back') }}</RouterLink>
          <div class="mt-1 flex flex-wrap items-center gap-2">
            <h1 class="text-2xl font-semibold">{{ item.title }}</h1>
            <span class="rounded bg-primary-50 px-2 py-1 text-xs font-medium text-primary-700">{{ statusLabel(item.status) }}</span>
          </div>
          <p class="mt-0.5 text-sm text-neutral-500">{{ t(`other_items.side.${item.side}`) }} · {{ otherItemKindLabel(t, item) }}<span v-if="item.partner_name"> · {{ item.partner_name }}</span></p>
          <a href="#other-item-plans" class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-primary-700 hover:underline">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.calendar" /></svg>
            {{ t('other_items.plans.schedule_title') }} · {{ t('other_items.plans.installments_title') }}
          </a>
        </div>
        <ActionBar :actions="actions" />
      </div>

      <div class="grid gap-4 lg:grid-cols-3">
        <section class="rounded-lg border border-neutral-200 bg-surface p-4 lg:col-span-2">
          <h2 class="mb-3 font-semibold">{{ t('other_items.details') }}</h2>
          <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
            <div><dt class="text-neutral-500">{{ t('other_items.partner') }}</dt><dd>{{ item.partner_name || t('other_items.no_partner') }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('other_items.kind_label') }}</dt><dd>{{ otherItemKindLabel(t, item) }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('other_items.issued_on') }}</dt><dd>{{ item.issued_on ? formatDate(item.issued_on) : '–' }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('other_items.due_on') }}</dt><dd>{{ item.due_on ? formatDate(item.due_on) : '–' }}</dd></div>
            <div v-if="isDoubleEntry"><dt class="text-neutral-500">{{ t('other_items.accounting_on') }}</dt><dd>{{ item.accounting_on ? formatDate(item.accounting_on) : '–' }}</dd></div>
            <div><dt class="text-neutral-500">{{ t('other_items.variable_symbol') }}</dt><dd>{{ item.variable_symbol || '–' }}</dd></div>
            <div v-if="item.currency !== 'CZK'"><dt class="text-neutral-500">{{ t('other_items.exchange_rate') }}</dt><dd>{{ item.exchange_rate || '–' }}</dd></div>
            <div class="sm:col-span-2"><dt class="text-neutral-500">{{ t('other_items.note') }}</dt><dd class="whitespace-pre-wrap">{{ item.note || '–' }}</dd></div>
          </dl>
        </section>
        <section class="rounded-lg border border-neutral-200 bg-surface p-4">
          <h2 class="mb-3 font-semibold">{{ t('other_items.payment') }}</h2>
          <dl class="space-y-3 text-sm">
            <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('other_items.amount') }}</dt><dd class="tabular-nums">{{ formatMoney(item.amount, item.currency) }}</dd></div>
            <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('other_items.paid_amount') }}</dt><dd class="tabular-nums">{{ formatMoney(item.paid_amount, item.currency) }}</dd></div>
            <div v-if="Number(item.settled_outside_amount || 0) > 0" class="flex justify-between gap-3 pl-3" :title="t('other_items.settled_outside_hint')"><dt class="text-neutral-500">{{ t('other_items.settled_outside') }}</dt><dd class="tabular-nums text-neutral-500">{{ formatMoney(item.settled_outside_amount ?? 0, item.currency) }}</dd></div>
            <div class="flex justify-between gap-3 border-t border-neutral-200 pt-3 font-semibold"><dt>{{ t('other_items.remaining') }}</dt><dd class="tabular-nums">{{ formatMoney(item.remaining_amount, item.currency) }}</dd></div>
          </dl>
        </section>
      </div>
      <DocumentDimensionsPanel class="mt-4" doc-type="other-items" :doc-id="id" :readonly="item.status === 'cancelled'"
                               :prefill="{ client_id: item.partner_id ?? null }" />
      <section v-if="isDoubleEntry && item.journal_entry_id" class="mt-4 rounded-lg border border-neutral-200 bg-surface p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 class="font-semibold">{{ t('other_items.accounting') }}</h2>
            <p class="mt-1 text-sm text-neutral-500">{{ item.account_code || '–' }} / {{ item.posting_lines?.length && item.posting_lines.length > 1 ? t('other_items.posting_line_count', { count: item.posting_lines.length }) : item.counter_account_code || item.posting_lines?.[0]?.account_code || '–' }} · {{ item.accounting_on ? formatDate(item.accounting_on) : '–' }}</p>
            <ul v-if="item.posting_lines && item.posting_lines.length > 1" class="mt-2 space-y-1 text-sm">
              <li v-for="(line, index) in item.posting_lines" :key="index" class="flex justify-between gap-3"><span>{{ line.account_code }}</span><span class="tabular-nums">{{ formatMoney(line.amount, item.currency) }}</span></li>
            </ul>
            <RouterLink :to="{ name: 'accounting-journal', query: { entry_id: String(item.journal_entry_id) } }" :class="[btnOutline('primary'), 'mt-3']">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.doc" /></svg>
              {{ t('other_items.open_journal') }}
            </RouterLink>
          </div>
          <ActionBar :actions="postingActions" />
        </div>
      </section>
      <OtherItemJournalContext v-if="isDoubleEntry && item.journal_entry_id" :entry-id="Number(item.journal_entry_id)" />
      <LinkedDocumentsPanel v-if="auth.canRead('documents')" class="mt-4" entity-type="other_item" :entity-id="id" uploadable :upload-folder-path="documentFolderPath" :title="t('linked_documents.title')" />
      <OtherItemPlans :item="item" />
      <section class="mt-4 rounded-lg border border-neutral-200 bg-surface p-4">
        <h2 class="mb-3 font-semibold">{{ t('other_items.payments') }}</h2>
        <p v-if="isTaxEvidence && allocations.some(allocation => !allocation.reversed_on)" class="mb-3 text-sm text-warning-700">
          {{ t('other_items.tax_classification_hint') }}
          <RouterLink v-if="auth.canRead('tax_evidence')" :to="{ name: 'tax-evidence-cash-journal' }" class="font-medium underline">{{ t('other_items.open_cash_journal') }}</RouterLink>
        </p>
        <div v-if="allocations.length" class="divide-y divide-neutral-100 text-sm">
          <div v-for="allocation in allocations" :key="allocation.id" class="flex flex-wrap items-center justify-between gap-2 py-2">
            <div>
              <span class="font-medium">{{ t(allocation.bank_transaction_id ? 'other_items.bank_payment' : 'other_items.cash_payment') }} #{{ allocation.bank_transaction_id || allocation.cash_document_id }}</span>
              <span class="ml-2 text-neutral-500">{{ formatDate(allocation.payment_on) }}</span>
              <span v-if="allocation.reversed_on" class="ml-2 text-warning-700">{{ t('other_items.payment_reversed_on', { date: formatDate(allocation.reversed_on) }) }}</span>
            </div>
            <div class="flex flex-wrap items-center gap-3">
              <strong class="tabular-nums" :class="allocation.reversed_on ? 'line-through text-neutral-500' : ''">{{ formatMoney(allocation.amount, item.currency) }}</strong>
              <button v-if="auth.canWrite('other_items') && (allocation.bank_transaction_id ? canManageBankPayment : canManageCashPayment)" type="button" :disabled="busy" class="text-warning-700 hover:underline disabled:opacity-50" @click="unallocate(allocation)">{{ t('other_items.unlink_payment') }}</button>
            </div>
          </div>
        </div>
        <p v-else class="text-sm text-neutral-500">{{ t('other_items.no_payments') }}</p>
        <form v-if="canAllocate" class="mt-4 border-t border-neutral-200 pt-4" @submit.prevent="allocate">
          <h3 class="mb-2 text-sm font-semibold">{{ t('other_items.link_payment') }}</h3>
          <div v-if="!selectedPayment" class="relative max-w-xl">
            <label class="block text-sm font-medium">{{ t('other_items.search_payment') }}
              <input v-model="paymentQuery" type="search" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" @focus="searchPayments(paymentQuery)" />
            </label>
            <div v-if="candidatesLoading" class="mt-2 text-xs text-neutral-500">{{ t('common.loading') }}</div>
            <div v-if="paymentCandidates.length" class="mt-2 max-h-52 overflow-y-auto rounded-md border border-neutral-200">
              <button v-for="candidate in paymentCandidates" :key="`${candidate.source}:${candidate.id}`" type="button" class="flex w-full flex-wrap items-center justify-between gap-2 border-b border-neutral-100 px-3 py-2 text-left text-sm hover:bg-neutral-50" @click="selectPayment(candidate)">
                <span><span class="font-medium">{{ t(candidate.source === 'bank' ? 'other_items.bank_payment' : 'other_items.cash_payment') }} #{{ candidate.id }}</span><span class="ml-2 text-neutral-500">{{ formatDate(candidate.payment_on) }} · {{ candidate.description }}</span></span>
                <strong class="tabular-nums">{{ formatMoney(Math.abs(candidate.amount), candidate.currency) }}</strong>
              </button>
            </div>
          </div>
          <div v-else class="flex flex-wrap items-end gap-3">
            <div class="text-sm"><span class="font-medium">{{ t(selectedPayment.source === 'bank' ? 'other_items.bank_payment' : 'other_items.cash_payment') }} #{{ selectedPayment.id }}</span><div class="text-neutral-500">{{ formatDate(selectedPayment.payment_on) }} · {{ selectedPayment.description }}</div></div>
            <label class="text-sm font-medium">{{ t('other_items.allocation_amount') }}
              <input v-model.number="allocationAmount" required type="number" min="0.01" step="0.01" :max="Math.min(Math.abs(selectedPayment.amount), Number(item.remaining_amount))" class="mt-1 block h-9 w-36 rounded-md border border-neutral-300 bg-surface px-2 text-right" />
            </label>
            <button type="submit" :disabled="busy" :class="btnFilled('success')">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.link" /></svg>
              {{ t('other_items.link_payment') }}
            </button>
            <button type="button" :class="btnOutline('neutral')" @click="selectedPayment = null">
              <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
              {{ t('common.cancel') }}
            </button>
          </div>
        </form>
        <p v-else-if="item.currency !== 'CZK'" class="mt-3 text-sm text-neutral-500">{{ t('other_items.foreign_payment_hint') }}</p>
      </section>
    </template>
    <Modal v-if="reverseOpen" :title="t(isDoubleEntry ? 'other_items.reverse' : 'other_items.cancel')" width-class="max-w-lg" @close="reverseOpen = false">
      <form id="other-item-reverse" class="space-y-4" @submit.prevent="reverse">
        <p class="text-sm text-neutral-600">{{ t(isDoubleEntry ? 'other_items.confirm_reverse' : 'other_items.confirm_cancel') }}</p>
        <label class="block text-sm font-medium">{{ t('other_items.reverse_reason') }}
          <textarea v-model="reverseReason" required minlength="3" maxlength="500" rows="3" class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface p-2" />
        </label>
        <label v-if="isDoubleEntry" class="block text-sm font-medium">{{ t('other_items.reverse_date') }}
          <DateInput v-model="reverseDate" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2" />
        </label>
      </form>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="reverseOpen = false">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="submit" form="other-item-reverse" :disabled="busy" :class="btnFilled('warning')">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
            {{ t(isDoubleEntry ? 'other_items.reverse' : 'other_items.cancel') }}
          </button>
        </div>
      </template>
    </Modal>
    <Modal v-if="repostOpen" :title="t('other_items.repost')" width-class="max-w-xl" @close="repostOpen = false">
      <form id="other-item-repost" class="space-y-4" @submit.prevent="repost">
        <p class="text-sm text-neutral-600">{{ t('other_items.repost_hint') }}</p>
        <div class="rounded-md border border-neutral-200 bg-neutral-50 p-3 text-sm">
          <div class="text-xs font-medium text-neutral-500">{{ t('other_items.repost_current') }}</div>
          <div class="mt-1 font-medium">{{ item?.account_code || '–' }} / {{ item?.posting_lines?.length && item.posting_lines.length > 1 ? t('other_items.posting_line_count', { count: item.posting_lines.length }) : item?.counter_account_code || item?.posting_lines?.[0]?.account_code || '–' }} · {{ item ? formatMoney(item.amount, item.currency) : '' }}</div>
          <ul v-if="item?.posting_lines && item.posting_lines.length > 1" class="mt-2 space-y-1">
            <li v-for="(line, index) in item.posting_lines" :key="index" class="flex justify-between gap-3"><span>{{ line.account_code }}</span><span class="tabular-nums">{{ formatMoney(line.amount, item.currency) }}</span></li>
          </ul>
        </div>
        <label class="block text-sm font-medium">{{ t('other_items.account_code') }}
          <ChartAccountSelect v-model="repostAccountCode" :accounts="repostBalanceAccounts" :placeholder="t('other_items.choose_account')" class="mt-1 block" />
        </label>
        <label v-if="!repostSplit" class="block text-sm font-medium">{{ t('other_items.counter_account_code') }}
          <ChartAccountSelect v-model="repostCounterAccountCode" :accounts="repostAccountChoices" :placeholder="t('other_items.choose_account')" class="mt-1 block" />
        </label>
        <OtherItemPostingLines v-else v-model="repostLines" :accounts="repostAccountChoices" :total="Number(item?.amount || 0)" />
        <button v-if="!repostSplit" type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="startRepostSplit">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.plus" /></svg>
          {{ t('other_items.split_posting') }}
        </button>
        <button v-else type="button" :class="btnOutline('neutral')" class="whitespace-nowrap" @click="stopRepostSplit">
          <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
          {{ t('other_items.single_posting') }}
        </button>
        <label class="block text-sm font-medium">{{ t('other_items.repost_date') }}
          <DateInput v-model="repostDate" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2" />
        </label>
        <label class="block text-sm font-medium">{{ t('other_items.repost_reason') }}
          <textarea v-model="repostReason" required minlength="3" maxlength="500" rows="3" class="mt-1 block w-full rounded-md border border-neutral-300 bg-surface p-2" />
        </label>
      </form>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="repostOpen = false">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="submit" form="other-item-repost" :disabled="busy" :class="btnFilled('warning')">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.edit" /></svg>
            {{ t('other_items.repost') }}
          </button>
        </div>
      </template>
    </Modal>
  </div>
</template>
