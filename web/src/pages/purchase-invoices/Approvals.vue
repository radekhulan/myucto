<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import {
  approvalErrorMessage,
  purchaseApprovalsApi,
  type PurchaseApprovalRow,
} from '@/api/purchaseApprovals'
import { purchaseInvoicesApi } from '@/api/purchaseInvoices'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { usePurchaseApprovalCount } from '@/composables/usePurchaseApprovalCount'
import { useAuthStore } from '@/stores/auth'
import { ICONS, btnFilled, btnFilledSm, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'
import ApprovalStatusBadge from '@/components/purchase/ApprovalStatusBadge.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Modal from '@/components/ui/Modal.vue'
import PdfFrame from '@/components/documents/PdfFrame.vue'

/**
 * Schvalovací schránka (F6): doklady, které čekají na rozhodnutí odpovědné osoby střediska.
 * Schvalovatel vidí jen své doklady (`scope=mine`), účetní může přepnout na všechny.
 */
const { t } = useI18n()
const toast = useToast()
const auth = useAuthStore()
const count = usePurchaseApprovalCount()

const tab = ref<'pending' | 'decided'>('pending')
const scope = ref<'mine' | 'all'>('mine')
const rows = ref<PurchaseApprovalRow[]>([])
const page = ref(1)
const pages = ref(1)
const total = ref(0)
const loading = ref(true)
const failed = ref(false)
const busyId = ref<number | null>(null)
const previewId = ref<number | null>(null)

/** Účetní (právo zápisu přijatých faktur) smí vidět všechny doklady a připomínat. */
const isAccountant = computed(() => auth.canWrite('purchase_invoices'))
const canOpenInvoice = computed(() => auth.canRead('purchase_invoices'))
const myId = computed(() => auth.user?.id ?? 0)

let seq = 0
async function load(): Promise<void> {
  const current = ++seq
  loading.value = true
  failed.value = false
  previewId.value = null
  try {
    const res = await purchaseApprovalsApi.list({
      status: tab.value,
      scope: isAccountant.value ? scope.value : 'mine',
      page: page.value,
    })
    if (current !== seq) return
    rows.value = res.data
    pages.value = res.meta.pages || 1
    total.value = res.meta.total
  } catch {
    if (current === seq) failed.value = true
  } finally {
    if (current === seq) loading.value = false
  }
}

onMounted(() => { void load() })
watch([tab, scope], () => {
  page.value = 1
  void load()
})

function goToPage(next: number): void {
  if (next < 1 || next > pages.value) return
  page.value = next
  void load()
}

function canDecide(row: PurchaseApprovalRow): boolean {
  return row.status === 'pending' && row.approver.id === myId.value
}
function canRemind(row: PurchaseApprovalRow): boolean {
  return row.status === 'pending' && isAccountant.value
}

function togglePreview(row: PurchaseApprovalRow): void {
  previewId.value = previewId.value === row.id ? null : row.id
}
function pdfSrc(row: PurchaseApprovalRow): string {
  return `${purchaseInvoicesApi.pdfUrl(row.purchase_invoice_id, true)}#view=FitH`
}

async function afterDecision(): Promise<void> {
  void count.refresh()
  await load()
}

async function approve(row: PurchaseApprovalRow): Promise<void> {
  if (busyId.value !== null) return
  busyId.value = row.id
  try {
    const res = await purchaseApprovalsApi.decide(row.id, { decision: 'approve' })
    toast.success(t(res.invoice_approval_status === 'approved' ? 'purchase_approval.toast.approved_all' : 'purchase_approval.toast.approved'))
    await afterDecision()
  } catch (e) {
    toast.error(approvalErrorMessage(e, t))
  } finally {
    busyId.value = null
  }
}

async function remind(row: PurchaseApprovalRow): Promise<void> {
  if (busyId.value !== null) return
  busyId.value = row.id
  try {
    await purchaseApprovalsApi.remind(row.id)
    toast.success(t('purchase_approval.toast.reminded'))
  } catch (e) {
    toast.error(approvalErrorMessage(e, t))
  } finally {
    busyId.value = null
  }
}

// ── Zamítnutí s povinným důvodem ─────────────────────────────────────────────
const rejectTarget = ref<PurchaseApprovalRow | null>(null)
const rejectReason = ref('')
const rejectError = ref('')

function openReject(row: PurchaseApprovalRow): void {
  rejectTarget.value = row
  rejectReason.value = ''
  rejectError.value = ''
}

async function confirmReject(): Promise<void> {
  const row = rejectTarget.value
  if (!row || busyId.value !== null) return
  const reason = rejectReason.value.trim()
  if (!reason) {
    rejectError.value = t('purchase_approval.reject_dialog.reason_required')
    return
  }
  busyId.value = row.id
  try {
    await purchaseApprovalsApi.decide(row.id, { decision: 'reject', comment: reason })
    toast.success(t('purchase_approval.toast.rejected'))
    rejectTarget.value = null
    await afterDecision()
  } catch (e) {
    rejectError.value = approvalErrorMessage(e, t)
  } finally {
    busyId.value = null
  }
}
</script>

<template>
  <div>
    <div class="flex flex-wrap items-start justify-between gap-3 mb-4">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('purchase_approval.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-1 max-w-3xl">{{ t('purchase_approval.subtitle') }}</p>
      </div>
    </div>

    <div data-test="approvals-filters" class="flex flex-wrap items-center justify-between gap-3 border-b border-neutral-200 mb-4 max-md:flex-nowrap max-md:gap-2">
      <div class="flex flex-wrap gap-2 max-md:flex-nowrap max-md:gap-1 max-md:min-w-0" role="tablist">
        <button v-for="key in (['pending', 'decided'] as const)" :key="key" type="button" role="tab"
                :aria-selected="tab === key"
                class="px-3 py-2 text-sm font-medium border-b-2 -mb-px whitespace-nowrap max-md:min-h-11 max-md:px-2"
                :class="tab === key ? 'border-primary-600 text-primary-700' : 'border-transparent text-neutral-500 hover:text-neutral-700'"
                :data-test="`approvals-tab-${key}`"
                @click="tab = key">
          {{ t(`purchase_approval.tabs.${key}`) }}
          <span v-if="key === 'pending' && count.pending.value > 0"
                class="ml-1 inline-flex items-center justify-center min-w-5 h-5 px-1.5 rounded-full bg-primary-600 text-white text-xs tabular-nums">{{ count.pending.value }}</span>
        </button>
      </div>
      <div v-if="isAccountant" class="flex flex-wrap gap-2 pb-2 max-md:flex-nowrap max-md:shrink-0 max-md:gap-1 max-md:pb-1" role="group" data-test="approvals-scope">
        <button v-for="key in (['mine', 'all'] as const)" :key="key" type="button"
                :class="scope === key ? btnFilledSm('primary') : btnOutlineSm('neutral')"
                class="max-md:h-10 max-md:w-10 max-md:justify-center max-md:px-0"
                :aria-pressed="scope === key"
                :title="t(`purchase_approval.scope.${key}`)"
                :data-test="`approvals-scope-${key}`"
                @click="scope = key">
          <svg class="w-3.5 h-3.5 max-md:w-4 max-md:h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="key === 'mine' ? ICONS.user : ICONS.table" /></svg>
          <span class="max-md:sr-only">{{ t(`purchase_approval.scope.${key}`) }}</span>
        </button>
      </div>
    </div>

    <div v-if="loading && rows.length === 0" class="py-8 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="failed" variant="failed" boxed @action="load" />
    <EmptyState v-else-if="rows.length === 0" boxed icon="checkCircle"
      :title="t(tab === 'pending' ? 'purchase_approval.empty_pending' : 'purchase_approval.empty_decided')"
      :message="tab === 'pending' ? t('purchase_approval.empty_pending_hint') : undefined" />

    <template v-else>
      <!-- Desktop: tabulka -->
      <div class="hidden md:block bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-x-auto" data-test="approvals-table">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th class="px-3 py-2 text-left font-medium">{{ t('purchase_approval.col.document') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('purchase_approval.col.supplier') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('purchase_approval.col.center') }}</th>
              <th class="px-3 py-2 text-right font-medium whitespace-nowrap">{{ t('purchase_approval.col.amount') }}</th>
              <th v-if="scope === 'all' || tab === 'decided'" class="px-3 py-2 text-left font-medium">{{ t('purchase_approval.col.approver') }}</th>
              <th class="px-3 py-2 text-left font-medium whitespace-nowrap">{{ t(tab === 'pending' ? 'purchase_approval.col.requested' : 'purchase_approval.col.decided') }}</th>
              <th class="px-3 py-2 text-left font-medium">{{ t('purchase_approval.col.status') }}</th>
              <th class="px-3 py-2 text-right font-medium">{{ t('purchase_approval.col.actions') }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <template v-for="row in rows" :key="row.id">
              <tr class="align-top" :data-test="`approval-row-${row.id}`">
                <td class="px-3 py-2.5">
                  <RouterLink v-if="canOpenInvoice" :to="`/purchase-invoices/${row.purchase_invoice_id}`" class="font-mono text-primary-700 hover:underline">
                    {{ row.invoice.document_number || `#${row.purchase_invoice_id}` }}
                  </RouterLink>
                  <span v-else class="font-mono">{{ row.invoice.document_number || `#${row.purchase_invoice_id}` }}</span>
                  <div class="text-xs text-neutral-500">{{ formatDate(row.invoice.issue_date) }}</div>
                </td>
                <td class="px-3 py-2.5">{{ row.invoice.supplier_name }}</td>
                <td class="px-3 py-2.5">
                  <div>{{ row.dimension_value.name }}</div>
                  <div v-if="row.dimension_value.type_name" class="text-xs text-neutral-500">{{ row.dimension_value.type_name }}</div>
                </td>
                <td class="px-3 py-2.5 text-right font-mono whitespace-nowrap">
                  {{ formatMoney(row.amount_czk, 'CZK') }}
                  <div class="text-xs text-neutral-500">{{ formatMoney(row.invoice.total_with_vat, row.invoice.currency) }}</div>
                </td>
                <td v-if="scope === 'all' || tab === 'decided'" class="px-3 py-2.5">{{ row.approver.name }}</td>
                <td class="px-3 py-2.5 whitespace-nowrap text-neutral-600">
                  {{ formatDate(tab === 'pending' ? row.requested_at : (row.decided_at ?? row.requested_at)) }}
                </td>
                <td class="px-3 py-2.5">
                  <ApprovalStatusBadge :status="row.status" />
                  <p v-if="row.comment" class="text-xs text-neutral-600 mt-1 max-w-xs whitespace-pre-wrap">{{ row.comment }}</p>
                </td>
                <td class="px-3 py-2.5">
                  <div class="flex flex-wrap justify-end gap-1.5">
                    <button v-if="row.invoice.has_pdf" type="button" :class="btnOutlineSm('neutral')" data-test="approval-preview" @click="togglePreview(row)">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="previewId === row.id ? ICONS.eyeOff : ICONS.eye" /></svg>
                      {{ previewId === row.id ? t('purchase_approval.action.hide_preview') : t('purchase_approval.action.preview') }}
                    </button>
                    <button v-if="canRemind(row)" type="button" :disabled="busyId !== null" :class="btnOutlineSm('neutral')" data-test="approval-remind" @click="remind(row)">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" /></svg>
                      {{ t('purchase_approval.action.remind') }}
                    </button>
                    <button v-if="canDecide(row)" type="button" :disabled="busyId !== null" :class="btnOutlineSm('danger')" data-test="approval-reject" @click="openReject(row)">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                      {{ t('purchase_approval.action.reject') }}
                    </button>
                    <button v-if="canDecide(row)" type="button" :disabled="busyId !== null" :class="btnFilledSm('success')" data-test="approval-approve" @click="approve(row)">
                      <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                      {{ t('purchase_approval.action.approve') }}
                    </button>
                  </div>
                </td>
              </tr>
              <tr v-if="previewId === row.id">
                <td :colspan="scope === 'all' || tab === 'decided' ? 8 : 7" class="p-0 bg-neutral-50">
                  <PdfFrame :src="pdfSrc(row)" :title="t('purchase_approval.action.preview')" class="block w-full h-[70vh] border-0" />
                </td>
              </tr>
            </template>
          </tbody>
        </table>
      </div>

      <!-- Mobil: karty -->
      <div class="md:hidden space-y-3" data-test="approvals-cards">
        <div v-for="row in rows" :key="row.id" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 space-y-2" :data-test="`approval-card-${row.id}`">
          <div class="flex items-start justify-between gap-2">
            <div class="min-w-0">
              <RouterLink v-if="canOpenInvoice" :to="`/purchase-invoices/${row.purchase_invoice_id}`" class="font-mono text-primary-700">
                {{ row.invoice.document_number || `#${row.purchase_invoice_id}` }}
              </RouterLink>
              <span v-else class="font-mono">{{ row.invoice.document_number || `#${row.purchase_invoice_id}` }}</span>
              <div class="text-sm text-neutral-700 truncate">{{ row.invoice.supplier_name }}</div>
            </div>
            <ApprovalStatusBadge :status="row.status" />
          </div>
          <div class="text-sm text-neutral-600">{{ row.dimension_value.name }}<span v-if="scope === 'all' || tab === 'decided'"> · {{ row.approver.name }}</span></div>
          <div class="flex items-baseline justify-between gap-2">
            <span class="font-mono font-semibold">{{ formatMoney(row.amount_czk, 'CZK') }}</span>
            <span class="text-xs text-neutral-500">{{ formatDate(tab === 'pending' ? row.requested_at : (row.decided_at ?? row.requested_at)) }}</span>
          </div>
          <p v-if="row.comment" class="text-xs text-neutral-600 whitespace-pre-wrap">{{ row.comment }}</p>
          <!-- Telefon: Schválit / Zamítnout jako první velká tlačítka (≥ 40 px), náhled a připomenutí pod nimi. -->
          <div class="flex flex-wrap gap-1.5 max-md:grid max-md:grid-cols-2 max-md:gap-2 max-md:[&>*]:h-10 max-md:[&>*]:justify-center" data-test="approval-card-actions">
            <a v-if="row.invoice.has_pdf" :href="purchaseInvoicesApi.pdfUrl(row.purchase_invoice_id, true)" target="_blank" rel="noopener" :class="btnOutlineSm('neutral')" class="max-md:order-3">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.eye" /></svg>
              {{ t('purchase_approval.action.preview') }}
            </a>
            <button v-if="canRemind(row)" type="button" :disabled="busyId !== null" :class="btnOutlineSm('neutral')" class="max-md:order-3" @click="remind(row)">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.bell" /></svg>
              {{ t('purchase_approval.action.remind') }}
            </button>
            <button v-if="canDecide(row)" type="button" :disabled="busyId !== null" :class="btnOutlineSm('danger')" class="max-md:order-2 max-md:text-sm" data-test="approval-card-reject" @click="openReject(row)">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
              {{ t('purchase_approval.action.reject') }}
            </button>
            <button v-if="canDecide(row)" type="button" :disabled="busyId !== null" :class="btnFilledSm('success')" class="max-md:order-1 max-md:text-sm" data-test="approval-card-approve" @click="approve(row)">
              <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
              {{ t('purchase_approval.action.approve') }}
            </button>
          </div>
        </div>
      </div>

      <div v-if="pages > 1" class="flex flex-wrap items-center justify-between gap-2 mt-4 text-sm text-neutral-600">
        <span>{{ page }} / {{ pages }} ({{ total }})</span>
        <div class="flex flex-wrap gap-2">
          <button type="button" :disabled="page <= 1 || loading" :class="btnOutline('neutral')" @click="goToPage(page - 1)">{{ t('common.previous') }}</button>
          <button type="button" :disabled="page >= pages || loading" :class="btnOutline('neutral')" @click="goToPage(page + 1)">{{ t('common.next') }}</button>
        </div>
      </div>
    </template>

    <!-- Zamítnutí: důvod je povinný -->
    <Modal v-if="rejectTarget" :title="t('purchase_approval.reject_dialog.title')" width-class="max-w-lg" @close="rejectTarget = null">
      <div class="space-y-3" data-test="approval-reject-dialog">
        <p class="text-sm text-neutral-600">
          {{ t('purchase_approval.reject_dialog.intro', { number: rejectTarget.invoice.document_number || `#${rejectTarget.purchase_invoice_id}`, supplier: rejectTarget.invoice.supplier_name || '' }) }}
        </p>
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('purchase_approval.reject_dialog.reason') }} *</label>
          <textarea v-model="rejectReason" rows="4" maxlength="500"
            :placeholder="t('purchase_approval.reject_dialog.reason_placeholder')"
            class="w-full px-3 py-2 border border-neutral-300 rounded-md text-sm" data-test="approval-reject-reason"></textarea>
        </div>
        <p v-if="rejectError" class="rounded-md bg-danger-50 border border-danger-500/40 px-3 py-2 text-sm text-danger-500">{{ rejectError }}</p>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="btnOutline('neutral')" @click="rejectTarget = null">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" :disabled="busyId !== null" :class="btnFilled('danger')" data-test="approval-reject-confirm" @click="confirmReject">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('purchase_approval.reject_dialog.confirm') }}
          </button>
        </div>
      </template>
    </Modal>
  </div>
</template>
