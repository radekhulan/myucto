<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import {
  accrualsApi, accrualErrorCode,
  type AccrualActivity, type AccrualDetail, type AccrualLink, type AccrualRowPreview, type AccrualScheduleRow,
  type AccrualTerminationPreview,
} from '@/api/accruals'
import { useAuthStore } from '@/stores/auth'
import { useSupplierStore } from '@/stores/supplier'
import { useToast } from '@/composables/useToast'
import { useDemoMode } from '@/composables/useDemoMode'
import { formatDate, formatMoney } from '@/composables/useFormat'
import { useListNavigation } from '@/composables/useListNavigation'
import { appIsoDate } from '@/utils/date'
import { accrualRowStatusClass, accrualStatusClass, isAccrualRowDue } from '@/utils/accrualStatus'
import { accrualSourceRoute } from '@/utils/accrualSource'
import ActionBar, { type ActionItem } from '@/components/ui/ActionBar.vue'
import ListNavArrows from '@/components/ui/ListNavArrows.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import Modal from '@/components/ui/Modal.vue'
import DateInput from '@/components/ui/DateInput.vue'
import ChartAccountSelect from '@/components/accounting/ChartAccountSelect.vue'
import { ICONS, btnFilled, btnOutline, btnOutlineSm } from '@/components/ui/buttonStyles'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const auth = useAuthStore()
const supplier = useSupplierStore()
const toast = useToast()
const { blockDemoMutation } = useDemoMode()

const id = computed(() => Number(route.params.id))
const item = ref<AccrualDetail | null>(null)
const activity = ref<AccrualActivity[]>([])
const activityOpen = ref(false)
const accounts = ref<ChartAccount[]>([])
const loading = ref(false)
const notFound = ref(false)
const busy = ref(false)
const asOf = ref('')
const today = appIsoDate()

const canWrite = computed(() => auth.canWrite('accounting.accruals.write'))
/** Zaúčtování a ukončení: správa agendy a zároveň právo účtovat do deníku (stejně jako server). */
const canPost = computed(() => canWrite.value && auth.canWrite('accounting.journal.post'))
const accountNames = computed(() => Object.fromEntries(accounts.value.map(a => [a.account_code, a.name])))
const accountChoices = computed(() => accounts.value.filter(a => a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)))
const sourceRoute = computed(() => item.value ? accrualSourceRoute(item.value.source_type, item.value.source_id) : null)
const nextReleaseDue = computed(() => {
  const row = item.value?.next_release
  return !!row && item.value?.status === 'active' && isAccrualRowDue(row, today)
})

function errorText(error: any): string {
  const code = accrualErrorCode(error)
  if (code) {
    const key = `accruals.error.${code}`
    const localized = t(key, (error?.response?.data?.error ?? {}) as Record<string, unknown>)
    if (localized !== key) return localized
  }
  return error?.response?.data?.error?.message || t('common.error')
}

const listNav = useListNavigation({
  kind: 'accruals',
  currentId: () => id.value || 0,
  detailRoute: itemId => ({ name: 'accounting-accrual-detail', params: { id: itemId } }),
  fetchPage: async (params, page) => {
    const result = await accrualsApi.list({ ...(params as Parameters<typeof accrualsApi.list>[0]), page })
    return { ids: result.items.map(row => row.id), pages: Math.max(1, Math.ceil(result.total / (result.per_page || 50))) }
  },
  scope: () => supplier.currentSupplierId,
  hotkeysEnabled: () => !rowPreviewOpen.value && !terminateOpen.value,
})
const backToList = computed(() => listNav.listPath.value ?? { name: 'accounting-accruals' })

let loadRequest = 0
async function load() {
  const request = ++loadRequest
  loading.value = true
  notFound.value = false
  try {
    const [detail] = await Promise.all([
      accrualsApi.get(id.value, asOf.value || undefined),
      accounts.value.length ? Promise.resolve() : accountingApi.listAccounts().then(value => { accounts.value = value }).catch(() => undefined),
    ])
    if (request !== loadRequest) return
    item.value = detail
    if (!asOf.value) asOf.value = detail.as_of
  } catch (error: any) {
    if (request !== loadRequest) return
    item.value = null
    notFound.value = error?.response?.status === 404
    if (!notFound.value) toast.error(errorText(error))
  } finally {
    if (request === loadRequest) loading.value = false
  }
  void loadActivity()
}
async function loadActivity() {
  try {
    activity.value = await accrualsApi.activity(id.value)
  } catch {
    activity.value = []
  }
}
function applyDetail(detail: AccrualDetail) {
  item.value = detail
  void loadActivity()
}
async function mutate(call: () => Promise<AccrualDetail>, success: string): Promise<boolean> {
  if (busy.value || blockDemoMutation()) return false
  busy.value = true
  try {
    applyDetail(await call())
    toast.success(success)
    return true
  } catch (error: any) {
    toast.error(errorText(error))
    // Konflikt (409): položka se mezitím změnila, detail se načte znovu.
    if (error?.response?.status === 409) void load()
    return false
  } finally {
    busy.value = false
  }
}

function activate() {
  if (!window.confirm(t('accruals.detail.confirm_activate'))) return
  void mutate(() => accrualsApi.activate(id.value), t('accruals.detail.activated'))
}
function cancelItem() {
  if (!window.confirm(t('accruals.detail.confirm_cancel'))) return
  void mutate(() => accrualsApi.cancel(id.value), t('accruals.detail.cancelled'))
}
function cancelTermination() {
  if (!window.confirm(t('accruals.detail.confirm_cancel_termination'))) return
  void mutate(() => accrualsApi.cancelTermination(id.value), t('accruals.detail.termination_cancelled'))
}
async function remove() {
  if (busy.value || blockDemoMutation() || !window.confirm(t('accruals.detail.confirm_delete'))) return
  busy.value = true
  try {
    await accrualsApi.remove(id.value)
    toast.success(t('common.deleted'))
    await router.push({ name: 'accounting-accruals' })
  } catch (error: any) {
    toast.error(errorText(error))
  } finally {
    busy.value = false
  }
}
function unpost(row: AccrualScheduleRow) {
  if (!window.confirm(t('accruals.detail.confirm_unpost', { date: formatDate(row.due_on) }))) return
  void mutate(() => accrualsApi.unpostRow(id.value, row.id), t('accruals.detail.unposted'))
}
function removeLink(link: AccrualLink) {
  if (!window.confirm(t('accruals.detail.confirm_unlink'))) return
  void mutate(() => accrualsApi.removeLink(id.value, link.id), t('accruals.detail.unlinked'))
}

// ─── Zaúčtování řádku plánu s náhledem zápisu ───
const rowPreviewOpen = ref(false)
const rowPreview = ref<AccrualRowPreview | null>(null)
const rowPreviewLoading = ref(false)
async function openRowPost(row: AccrualScheduleRow) {
  rowPreviewOpen.value = true
  rowPreview.value = null
  rowPreviewLoading.value = true
  try {
    rowPreview.value = await accrualsApi.rowPreview(id.value, row.id)
  } catch (error: any) {
    rowPreviewOpen.value = false
    toast.error(errorText(error))
  } finally {
    rowPreviewLoading.value = false
  }
}
async function confirmRowPost() {
  const preview = rowPreview.value
  if (!preview || !preview.lock.postable) return
  const ok = await mutate(() => accrualsApi.postRow(id.value, preview.row.id), t('accruals.detail.posted'))
  if (ok) rowPreviewOpen.value = false
}

// ─── Předčasné ukončení s živým náhledem ───
const terminateOpen = ref(false)
const termDate = ref(today)
const termAccount = ref('')
const termReason = ref('')
const reverseFuture = ref(false)
const confirmLinked = ref(false)
const termPreview = ref<AccrualTerminationPreview | null>(null)
const termPreviewLoading = ref(false)
const termError = ref('')
let termTimer: ReturnType<typeof setTimeout> | null = null
let termRequest = 0
function openTerminate() {
  const current = item.value
  if (!current) return
  termDate.value = today
  termAccount.value = current.termination_account_code || current.counter_account_code || ''
  termReason.value = ''
  reverseFuture.value = false
  confirmLinked.value = false
  termPreview.value = null
  termError.value = ''
  terminateOpen.value = true
  void loadTermPreview()
}
let termKey = ''
async function loadTermPreview() {
  const request = ++termRequest
  termKey = `${termDate.value}|${termAccount.value.trim()}`
  if (!/^\d{4}-\d{2}-\d{2}$/.test(termDate.value)) return
  termPreviewLoading.value = true
  termError.value = ''
  try {
    const preview = await accrualsApi.terminationPreview(id.value, termDate.value, termAccount.value.trim() || null)
    if (request !== termRequest) return
    termPreview.value = preview
  } catch (error: any) {
    if (request !== termRequest) return
    termPreview.value = null
    termError.value = errorText(error)
  } finally {
    if (request === termRequest) termPreviewLoading.value = false
  }
}
watch([termDate, termAccount], () => {
  if (!terminateOpen.value || `${termDate.value}|${termAccount.value.trim()}` === termKey) return
  // Starý náhled nesmí jít potvrdit: zahodí se hned a tlačítko zůstane zamčené, než dorazí nový.
  termPreview.value = null
  termRequest++
  termPreviewLoading.value = true
  if (termTimer) clearTimeout(termTimer)
  termTimer = setTimeout(() => void loadTermPreview(), 300)
})
const hasFutureRows = computed(() => (termPreview.value?.future_rows.length ?? 0) > 0)
const hasImportedFuture = computed(() => !!termPreview.value?.requires_linked_confirmation
  || !!termPreview.value?.future_rows.some(row => row.imported))
const canConfirmTerminate = computed(() => {
  const preview = termPreview.value
  if (!preview || termPreviewLoading.value || busy.value || !preview.lock.postable) return false
  if (preview.date !== termDate.value) return false
  if (hasFutureRows.value && !reverseFuture.value) return false
  if (hasImportedFuture.value && !confirmLinked.value) return false
  return true
})
async function confirmTerminate() {
  if (!canConfirmTerminate.value || blockDemoMutation()) return
  busy.value = true
  termError.value = ''
  try {
    applyDetail(await accrualsApi.terminate(id.value, {
      date: termDate.value,
      termination_account_code: termAccount.value.trim() || null,
      reason: termReason.value.trim() || null,
      reverse_future: reverseFuture.value,
      confirm_linked: confirmLinked.value,
    }))
    terminateOpen.value = false
    toast.success(t('accruals.detail.terminated'))
  } catch (error: any) {
    termError.value = errorText(error)
    if (error?.response?.status === 409) {
      void load()
      void loadTermPreview()
    }
  } finally {
    busy.value = false
  }
}
onBeforeUnmount(() => { if (termTimer) clearTimeout(termTimer) })

// ─── Akce ───
const actions = computed<ActionItem[]>(() => {
  const current = item.value
  if (!current) return []
  const can = current.can
  return [
    { key: 'post_release', label: t('accruals.detail.post_release'), icon: 'check', tier: 'primary', variant: 'primary',
      show: canPost.value && nextReleaseDue.value, disabled: busy.value, run: () => { if (current.next_release) void openRowPost(current.next_release) } },
    { key: 'activate', label: t('accruals.detail.activate'), icon: 'play', tier: current.status === 'draft' ? 'primary' : 'secondary', variant: 'success',
      show: can.activate && canWrite.value, disabled: busy.value, run: activate },
    { key: 'terminate', label: t('accruals.detail.terminate'), icon: 'pause', tier: 'secondary', variant: 'warning',
      show: can.terminate && canPost.value, disabled: busy.value, run: openTerminate },
    { key: 'edit', label: t('common.edit'), icon: 'edit', tier: 'secondary', variant: 'neutral',
      show: can.edit && canWrite.value, to: { name: 'accounting-accrual-edit', params: { id: current.id } } },
    { key: 'cancel_termination', label: t('accruals.detail.cancel_termination'), icon: 'uturn', tier: 'overflow', variant: 'warning',
      show: can.cancel_termination && canPost.value, disabled: busy.value, run: cancelTermination },
    { key: 'export', label: t('accruals.detail.export'), icon: 'download', tier: 'overflow', variant: 'neutral',
      href: accrualsApi.bookUrl({ item_id: current.id, ...(asOf.value ? { as_of: asOf.value } : {}) }, 'pdf') },
    { key: 'cancel', label: t('accruals.detail.cancel'), icon: 'x', tier: 'overflow', variant: 'danger',
      show: can.cancel && canWrite.value, disabled: busy.value, run: cancelItem },
    { key: 'delete', label: t('common.delete'), icon: 'trash', tier: 'overflow', variant: 'danger',
      show: can.delete && canWrite.value, disabled: busy.value, run: () => void remove() },
  ]
})

function kindLabel(value: string): string {
  const key = `accruals.kind.${value}`
  return t(key) === key ? value : t(key)
}
function accountText(code: string | null): string {
  if (!code) return '—'
  return accountNames.value[code] ? `${code} ${accountNames.value[code]}` : code
}
function linkRoleLabel(role: string): string {
  const key = `accruals.link_role.${role}`
  return t(key) === key ? role : t(key)
}
function matchedByLabel(value: string): string {
  const key = `accruals.matched_by.${value}`
  return t(key) === key ? value : t(key)
}
function activityLabel(action: string): string {
  const key = `accruals.activity.${action.replace(/^accrual_item\./, '')}`
  return t(key) === key ? action : t(key)
}
function canUnpost(row: AccrualScheduleRow): boolean {
  return canPost.value && row.status === 'posted' && row.entry_source_type === 'accrual_release'
}
function lineSum(lines: { side: string; amount: number }[], side: string): number {
  return lines.filter(line => line.side === side).reduce((sum, line) => sum + Number(line.amount), 0)
}

watch(id, () => { asOf.value = ''; void load() })
watch(asOf, (value, previous) => { if (previous && value && value !== item.value?.as_of) void load() })
onMounted(load)
</script>

<template>
  <div>
    <div v-if="loading && !item" class="py-12 text-center text-sm text-neutral-500">{{ t('common.loading') }}</div>
    <EmptyState v-else-if="notFound" boxed icon="doc" :title="t('accruals.detail.not_found')" :cta="t('accruals.back_to_list')" to="/accounting/accruals" />
    <EmptyState v-else-if="!item" boxed variant="failed" @action="load" />
    <template v-else>
      <div class="max-w-6xl space-y-4" data-test="detail-layout">
        <div class="flex items-center gap-2">
          <ListNavArrows v-if="listNav.active.value" :has-previous="listNav.hasPrevious.value" :has-next="listNav.hasNext.value"
            :busy="listNav.busy.value" @previous="listNav.previous" @next="listNav.next" />
          <RouterLink :to="backToList" class="text-sm text-neutral-600 hover:text-neutral-900" data-test="back-to-list">{{ t('accruals.back_to_list') }}</RouterLink>
        </div>

        <div class="flex flex-col gap-3 md:flex-row md:items-start md:justify-between md:gap-4">
          <h1 class="flex min-w-0 flex-wrap items-center gap-3 text-2xl font-semibold" data-test="detail-heading">
            <span class="font-mono">{{ item.item_no }}</span>
            <span class="rounded px-2 py-0.5 text-xs font-normal" :class="accrualStatusClass(item.status)" data-test="detail-status">{{ t(`accruals.status.${item.status}`) }}</span>
            <span class="rounded bg-neutral-100 px-2 py-0.5 text-xs font-normal text-neutral-600">{{ kindLabel(item.kind) }}</span>
            <span v-if="item.managed_by === 'closing'" class="rounded bg-neutral-100 px-2 py-0.5 text-xs font-normal text-neutral-600">{{ t('accruals.managed_by_closing') }}</span>
          </h1>
          <ActionBar :actions="actions" />
        </div>
        <div class="text-lg font-semibold text-neutral-900" data-test="detail-title">{{ item.title }}</div>
        <div v-for="w in item.warnings ?? []" :key="w.code" class="rounded-md border border-warning-200 bg-warning-50 px-3 py-2 text-sm text-warning-800" role="status" data-test="detail-warning">
          {{ t(`accruals.warning.${w.code}`, w.message) }}
        </div>

        <p v-if="canPost && item.next_release && item.status === 'active' && !nextReleaseDue" class="text-sm text-neutral-500" data-test="next-release-hint">
          {{ t('accruals.detail.next_release_hint', { date: formatDate(item.next_release.due_on), amount: formatMoney(item.next_release.amount_planned) }) }}
        </p>

        <div class="grid gap-4 lg:grid-cols-3">
          <section class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm lg:col-span-2">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('accruals.detail.details') }}</h2>
            <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
              <div><dt class="text-neutral-500">{{ t('accruals.account') }}</dt><dd class="font-mono">{{ accountText(item.accrual_account_code) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('accruals.counter_account') }}</dt><dd class="font-mono">{{ accountText(item.counter_account_code) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('accruals.termination_account') }}</dt><dd class="font-mono">{{ accountText(item.termination_account_code) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('accruals.method_label') }}</dt><dd>{{ t(`accruals.method.${item.method}`) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('accruals.period') }}</dt><dd>{{ formatDate(item.period_from) }} – {{ formatDate(item.period_to) }}</dd></div>
              <div><dt class="text-neutral-500">{{ t('accruals.basis_amount') }}</dt><dd class="font-mono">{{ formatMoney(item.basis_amount) }}</dd></div>
              <div v-if="item.opening_balance !== null">
                <dt class="text-neutral-500">{{ t('accruals.opening_balance') }}</dt>
                <dd class="font-mono">{{ formatMoney(item.opening_balance) }}<span v-if="item.opening_as_of" class="ml-1 font-sans text-xs text-neutral-500">({{ formatDate(item.opening_as_of) }})</span></dd>
              </div>
              <div><dt class="text-neutral-500">{{ t('accruals.partner') }}</dt><dd>{{ item.partner_name || '—' }}</dd></div>
              <div v-if="item.contract_ref"><dt class="text-neutral-500">{{ t('accruals.contract_ref') }}</dt><dd>{{ item.contract_ref }}</dd></div>
              <div v-if="item.cost_center"><dt class="text-neutral-500">{{ t('accruals.cost_center') }}</dt><dd class="font-mono">{{ item.cost_center }}</dd></div>
              <div>
                <dt class="text-neutral-500">{{ t('accruals.source_label') }}</dt>
                <dd data-test="detail-source">
                  {{ t(`accruals.source.${item.source_type}`) }}
                  <RouterLink v-if="sourceRoute" :to="sourceRoute" class="ml-1 font-mono text-primary-600 hover:underline">{{ item.source?.document_no || item.source_document_no || `#${item.source_id}` }}</RouterLink>
                  <span v-else-if="item.source_document_no" class="ml-1 font-mono">{{ item.source_document_no }}</span>
                </dd>
              </div>
              <div v-if="item.status === 'terminated'" class="sm:col-span-2">
                <dt class="text-neutral-500">{{ t('accruals.detail.terminated_on') }}</dt>
                <dd>
                  {{ item.terminated_on ? formatDate(item.terminated_on) : '—' }}
                  <RouterLink v-if="item.termination_entry_id" :to="{ name: 'accounting-journal', query: { entry_id: String(item.termination_entry_id) } }" class="ml-2 text-primary-600 hover:underline">#{{ item.termination_entry_id }}</RouterLink>
                  <span v-if="item.termination_reason" class="ml-2 text-neutral-600">· {{ item.termination_reason }}</span>
                </dd>
              </div>
              <div v-if="item.note" class="sm:col-span-2"><dt class="text-neutral-500">{{ t('accruals.note') }}</dt><dd class="whitespace-pre-wrap">{{ item.note }}</dd></div>
            </dl>
          </section>
          <section class="rounded-lg border border-neutral-200 bg-surface p-4 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('accruals.detail.balance_box') }}</h2>
            <label class="mb-3 flex items-center justify-between gap-3 text-sm text-neutral-500">
              <span>{{ t('accruals.as_of') }}</span>
              <DateInput v-model="asOf" data-test="detail-as-of" class="h-8 w-36 rounded-md border border-neutral-300 px-2 text-sm" />
            </label>
            <dl class="space-y-2 text-sm">
              <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('accruals.balance') }}</dt><dd class="font-mono text-base font-semibold" data-test="detail-balance">{{ formatMoney(item.balance) }}</dd></div>
              <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('accruals.released') }}</dt><dd class="font-mono">{{ formatMoney(item.released) }}</dd></div>
              <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('accruals.detail.balance_after_all') }}</dt><dd class="font-mono">{{ formatMoney(item.balance_after_all) }}</dd></div>
              <div class="flex justify-between gap-3"><dt class="text-neutral-500">{{ t('accruals.detail.covered_through') }}</dt><dd>{{ item.covered_through ? formatDate(item.covered_through) : '—' }}</dd></div>
            </dl>
          </section>
        </div>

        <!-- Plán proti skutečnosti -->
        <section class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm" data-test="schedule">
          <h2 class="border-b border-neutral-200 px-4 py-2.5 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('accruals.detail.schedule') }}</h2>
          <p v-if="!item.schedule.length" class="px-4 py-3 text-sm text-neutral-500">{{ t(item.status === 'draft' ? 'accruals.detail.schedule_draft' : 'accruals.detail.schedule_empty') }}</p>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">{{ t('accruals.detail.due_on') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('accruals.detail.row_period') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('accruals.detail.row_kind') }}</th>
                  <th class="px-3 py-2 text-right font-medium">{{ t('accruals.detail.amount_planned') }}</th>
                  <th class="px-3 py-2 text-center font-medium">{{ t('accruals.status_label') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('accruals.detail.entry') }}</th>
                  <th class="px-3 py-2"><span class="sr-only">{{ t('common.actions') }}</span></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="row in item.schedule" :key="row.id" :class="isAccrualRowDue(row, today) ? 'bg-warning-50/40' : ''" data-test="schedule-row">
                  <td class="whitespace-nowrap px-3 py-2">{{ formatDate(row.due_on) }}</td>
                  <td class="whitespace-nowrap px-3 py-2 text-xs text-neutral-600">
                    <template v-if="row.from_on && row.to_on">{{ formatDate(row.from_on) }} – {{ formatDate(row.to_on) }}</template>
                    <span v-if="row.days" class="ml-1 text-neutral-400">({{ t('accruals.detail.days', row.days) }})</span>
                    <p v-if="row.closed_period_amount" class="mt-1 max-w-xs whitespace-normal text-warning-700" data-test="row-closed-part">
                      {{ t('accruals.detail.closed_period_part', { amount: formatMoney(row.closed_period_amount) }) }}
                    </p>
                  </td>
                  <td class="whitespace-nowrap px-3 py-2 text-xs">{{ t(`accruals.row_kind.${row.kind}`) }}</td>
                  <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ formatMoney(row.amount_planned) }}</td>
                  <td class="whitespace-nowrap px-3 py-2 text-center"><span class="rounded px-2 py-0.5 text-xs" :class="accrualRowStatusClass(row.status)">{{ t(`accruals.row_status.${row.status}`) }}</span></td>
                  <td class="whitespace-nowrap px-3 py-2 text-xs">
                    <RouterLink v-if="row.journal_entry_id" :to="{ name: 'accounting-journal', query: { entry_id: String(row.journal_entry_id) } }" class="font-mono text-primary-600 hover:underline">{{ row.entry_document_no || `#${row.journal_entry_id}` }}</RouterLink>
                    <span v-if="row.entry_date" class="ml-1 text-neutral-500">{{ formatDate(row.entry_date) }}</span>
                  </td>
                  <td class="whitespace-nowrap px-3 py-2 text-right">
                    <button v-if="canPost && item.status === 'active' && isAccrualRowDue(row, today)" type="button" data-test="row-post" :disabled="busy"
                      :class="[btnOutlineSm('primary'), 'whitespace-nowrap']" @click="openRowPost(row)">
                      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                      {{ t('accruals.detail.post_row') }}
                    </button>
                    <button v-else-if="canUnpost(row)" type="button" data-test="row-unpost" :disabled="busy"
                      :class="[btnOutlineSm('warning'), 'whitespace-nowrap']" @click="unpost(row)">
                      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.uturn" /></svg>
                      {{ t('accruals.detail.unpost_row') }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Historie: navázané zápisy deníku -->
        <section class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm" data-test="links">
          <h2 class="border-b border-neutral-200 px-4 py-2.5 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('accruals.detail.links') }}</h2>
          <p v-if="!item.links.length" class="px-4 py-3 text-sm text-neutral-500">{{ t('accruals.detail.links_empty') }}</p>
          <div v-else class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-neutral-50 text-xs uppercase tracking-wide text-neutral-500">
                <tr>
                  <th class="px-3 py-2 text-left font-medium">{{ t('accruals.detail.entry') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('accruals.detail.description') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('accruals.account') }}</th>
                  <th class="px-3 py-2 text-left font-medium">{{ t('accruals.detail.role') }}</th>
                  <th class="px-3 py-2 text-right font-medium">{{ t('accruals.detail.amount') }}</th>
                  <th class="px-3 py-2"><span class="sr-only">{{ t('common.actions') }}</span></th>
                </tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="link in item.links" :key="link.id" :class="link.reversed_by ? 'text-neutral-400' : ''" data-test="link-row">
                  <td class="whitespace-nowrap px-3 py-2">
                    <RouterLink :to="{ name: 'accounting-journal', query: { entry_id: String(link.journal_entry_id) } }" class="font-mono text-primary-600 hover:underline">{{ link.document_no || `#${link.journal_entry_id}` }}</RouterLink>
                    <span v-if="link.entry_date" class="ml-1 text-xs text-neutral-500">{{ formatDate(link.entry_date) }}</span>
                  </td>
                  <td class="px-3 py-2">
                    {{ link.description || '' }}
                    <div v-if="link.reversed_by" class="text-xs">{{ t('accruals.detail.reversed', { date: link.reversed_on ? formatDate(link.reversed_on) : '' }) }}</div>
                  </td>
                  <td class="whitespace-nowrap px-3 py-2 font-mono text-xs">{{ link.account_code }}</td>
                  <td class="whitespace-nowrap px-3 py-2 text-xs">{{ linkRoleLabel(link.role) }} <span class="text-neutral-400">· {{ matchedByLabel(link.matched_by) }}</span></td>
                  <td class="whitespace-nowrap px-3 py-2 text-right font-mono">{{ formatMoney(link.amount_normal) }}</td>
                  <td class="whitespace-nowrap px-3 py-2 text-right">
                    <button v-if="canWrite && link.matched_by !== 'schedule'" type="button" data-test="link-remove" :disabled="busy"
                      :class="[btnOutlineSm('danger'), 'whitespace-nowrap']" @click="removeLink(link)">
                      <svg class="h-3.5 w-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.trash" /></svg>
                      {{ t('accruals.detail.unlink') }}
                    </button>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- Historie změn (activity feed) -->
        <div v-if="activity.length" class="overflow-hidden rounded-lg border border-neutral-200 bg-surface shadow-sm" data-test="activity">
          <button type="button" class="flex w-full cursor-pointer items-center justify-between px-5 py-3 text-left hover:bg-neutral-50"
            :class="activityOpen ? 'border-b border-neutral-200' : ''" :aria-expanded="activityOpen" @click="activityOpen = !activityOpen">
            <span class="flex items-center gap-2">
              <h3 class="text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('accruals.detail.activity') }}</h3>
              <span class="text-xs text-neutral-400">{{ activity.length }}</span>
            </span>
            <svg class="h-4 w-4 text-neutral-400 transition-transform" :class="activityOpen ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
          </button>
          <ul v-show="activityOpen" class="divide-y divide-neutral-100">
            <li v-for="entry in activity" :key="entry.id" class="flex flex-wrap items-center justify-between gap-2 px-5 py-2 text-sm">
              <span class="font-medium">{{ activityLabel(entry.action) }}</span>
              <span class="text-xs text-neutral-500">{{ entry.user_name || '—' }} · <span class="font-mono">{{ entry.created_at.replace('T', ' ').slice(0, 19) }}</span></span>
            </li>
          </ul>
        </div>
      </div>
    </template>

    <!-- Náhled zápisu rozpuštění -->
    <Modal v-if="rowPreviewOpen" :title="t('accruals.detail.post_title')" width-class="max-w-lg" @close="rowPreviewOpen = false">
      <div class="space-y-3 text-sm" data-test="row-preview">
        <p v-if="rowPreviewLoading" class="text-neutral-500">{{ t('common.loading') }}</p>
        <template v-else-if="rowPreview">
          <p class="text-neutral-600">{{ t('accruals.detail.post_intro', { date: formatDate(rowPreview.entry_date), amount: formatMoney(rowPreview.row.amount_planned) }) }}</p>
          <table class="w-full text-sm">
            <thead class="text-xs text-neutral-500">
              <tr>
                <th class="px-2 py-1 text-left font-medium">{{ t('accruals.account') }}</th>
                <th class="px-2 py-1 text-right font-medium">{{ t('accounting.journal.side.debit') }}</th>
                <th class="px-2 py-1 text-right font-medium">{{ t('accounting.journal.side.credit') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(line, index) in rowPreview.lines" :key="index">
                <td class="px-2 py-1.5 font-mono">{{ accountText(line.account_code) }}</td>
                <td class="px-2 py-1.5 text-right font-mono">{{ line.side === 'debit' ? formatMoney(line.amount) : '' }}</td>
                <td class="px-2 py-1.5 text-right font-mono">{{ line.side === 'credit' ? formatMoney(line.amount) : '' }}</td>
              </tr>
            </tbody>
          </table>
          <p v-if="rowPreview.row.closed_period_amount" role="status" class="rounded-md border border-warning-500/40 bg-warning-50 p-3 text-warning-700" data-test="row-preview-closed-part">
            {{ t('accruals.detail.closed_period_part', { amount: formatMoney(rowPreview.row.closed_period_amount) }) }}
          </p>
          <p v-if="!rowPreview.lock.postable" role="alert" class="rounded-md border border-warning-500/40 bg-warning-50 p-3 text-warning-700" data-test="row-lock">{{ t('accruals.detail.locked') }}</p>
        </template>
      </div>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="rowPreviewOpen = false">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="button" data-test="row-post-confirm" :disabled="busy || !rowPreview || !rowPreview.lock.postable"
            :class="[btnFilled('primary'), 'whitespace-nowrap']" @click="confirmRowPost">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
            {{ t('accruals.detail.post_row') }}
          </button>
        </div>
      </template>
    </Modal>

    <!-- Předčasné ukončení -->
    <Modal v-if="terminateOpen && item" :title="t('accruals.detail.terminate_title')" width-class="max-w-2xl" @close="terminateOpen = false">
      <form id="accrual-terminate" class="space-y-4 text-sm" @submit.prevent="confirmTerminate">
        <p class="text-neutral-600">{{ t('accruals.detail.terminate_intro') }}</p>
        <div class="grid gap-3 sm:grid-cols-2">
          <label class="block font-medium">{{ t('accruals.detail.terminate_date') }}
            <DateInput v-model="termDate" data-test="terminate-date" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 px-2" />
          </label>
          <div class="block font-medium">
            <span>{{ t('accruals.termination_account') }}</span>
            <ChartAccountSelect v-model="termAccount" :accounts="accountChoices" class="mt-1" :aria-label="t('accruals.termination_account')" />
          </div>
          <label class="block font-medium sm:col-span-2">{{ t('accruals.detail.terminate_reason') }}
            <input v-model="termReason" type="text" maxlength="255" data-test="terminate-reason" class="mt-1 block h-9 w-full rounded-md border border-neutral-300 bg-surface px-2" />
          </label>
        </div>

        <p v-if="termPreviewLoading && !termPreview" class="text-neutral-500">{{ t('common.loading') }}</p>
        <div v-if="termPreview" class="space-y-3 rounded-md border border-neutral-200 p-3" data-test="terminate-preview">
          <dl class="grid gap-x-6 gap-y-1 sm:grid-cols-3">
            <div><dt class="text-xs text-neutral-500">{{ t('accruals.detail.terminate_release') }}</dt><dd class="font-mono">{{ formatMoney(termPreview.release_amount) }}</dd></div>
            <div><dt class="text-xs text-neutral-500">{{ t('accruals.detail.terminate_remainder') }}</dt><dd class="font-mono">{{ formatMoney(termPreview.remainder_amount) }}</dd></div>
            <div><dt class="text-xs text-neutral-500">{{ t('accruals.detail.terminate_total') }}</dt><dd class="font-mono font-semibold">{{ formatMoney(termPreview.total) }}</dd></div>
          </dl>
          <table v-if="termPreview.lines.length" class="w-full text-sm">
            <thead class="text-xs text-neutral-500">
              <tr>
                <th class="px-2 py-1 text-left font-medium">{{ t('accruals.account') }}</th>
                <th class="px-2 py-1 text-right font-medium">{{ t('accounting.journal.side.debit') }}</th>
                <th class="px-2 py-1 text-right font-medium">{{ t('accounting.journal.side.credit') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(line, index) in termPreview.lines" :key="index" data-test="terminate-line">
                <td class="px-2 py-1.5 font-mono">{{ accountText(line.account_code) }}</td>
                <td class="px-2 py-1.5 text-right font-mono">{{ line.side === 'debit' ? formatMoney(line.amount) : '' }}</td>
                <td class="px-2 py-1.5 text-right font-mono">{{ line.side === 'credit' ? formatMoney(line.amount) : '' }}</td>
              </tr>
            </tbody>
            <tfoot class="border-t border-neutral-200 font-semibold">
              <tr>
                <td class="px-2 py-1.5">{{ t('accruals.detail.total') }}</td>
                <td class="px-2 py-1.5 text-right font-mono">{{ formatMoney(lineSum(termPreview.lines, 'debit')) }}</td>
                <td class="px-2 py-1.5 text-right font-mono">{{ formatMoney(lineSum(termPreview.lines, 'credit')) }}</td>
              </tr>
            </tfoot>
          </table>

          <div v-if="hasFutureRows" class="space-y-2 rounded-md border border-warning-500/40 bg-warning-50 p-3 text-warning-800" data-test="terminate-future">
            <p>{{ t('accruals.detail.future_rows_intro') }}</p>
            <ul class="space-y-0.5 text-xs">
              <li v-for="row in termPreview.future_rows" :key="row.id">
                {{ formatDate(row.due_on) }} · <span class="font-mono">{{ formatMoney(row.amount) }}</span>
                <span v-if="row.document_no" class="font-mono"> · {{ row.document_no }}</span>
                <span v-if="row.imported" class="ml-1 rounded bg-warning-100 px-1.5 py-0.5">{{ t('accruals.detail.imported') }}</span>
              </li>
            </ul>
            <label class="flex items-center gap-2 font-medium">
              <input v-model="reverseFuture" type="checkbox" data-test="terminate-reverse-future" class="h-4 w-4 rounded border-neutral-300" />
              {{ t('accruals.detail.reverse_future') }}
            </label>
            <label v-if="hasImportedFuture" class="flex items-center gap-2 font-medium">
              <input v-model="confirmLinked" type="checkbox" data-test="terminate-confirm-linked" class="h-4 w-4 rounded border-neutral-300" />
              {{ t('accruals.detail.confirm_linked') }}
            </label>
          </div>

          <p v-if="!termPreview.lock.postable" role="alert" class="rounded-md border border-danger-500/40 bg-danger-50 p-3 text-danger-700" data-test="terminate-lock">
            {{ t(termPreview.lock.closed ? 'accruals.detail.locked_closed' : 'accruals.detail.locked') }}
          </p>
        </div>
        <p v-if="termError" role="alert" class="text-danger-600" data-test="terminate-error">{{ termError }}</p>
      </form>
      <template #footer>
        <div class="flex flex-wrap justify-end gap-2">
          <button type="button" :class="[btnOutline('neutral'), 'whitespace-nowrap']" @click="terminateOpen = false">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
            {{ t('common.cancel') }}
          </button>
          <button type="submit" form="accrual-terminate" data-test="terminate-confirm" :disabled="!canConfirmTerminate"
            :class="[btnFilled('warning'), 'whitespace-nowrap']">
            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.pause" /></svg>
            {{ t('accruals.detail.terminate') }}
          </button>
        </div>
      </template>
    </Modal>
  </div>
</template>
