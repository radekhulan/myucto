<script setup lang="ts">
import { useWindowRefresh } from '@/composables/useWindowRefresh'
import { ref, reactive, computed, onMounted, watch, useId } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { accountingApi, type ChartAccount } from '@/api/accounting'
import ColumnPicker from '@/components/ui/ColumnPicker.vue'
import DensityToggle from '@/components/ui/DensityToggle.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import { useTablePrefs, type ColumnDef } from '@/composables/useTablePrefs'
import {
  bankPostingApi, bankPostingErrorMessage, bankPostingErrorKey,
  type PostingSuggestion, type SuggestionStatus,
} from '@/api/bankPosting'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import BulkActionBar from '@/components/ui/BulkActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import WhyChip from '@/components/automation/WhyChip.vue'
import ConfidenceLabel from '@/components/automation/ConfidenceLabel.vue'
import {
  AI_SOURCES,
  AUTOMATION_NOTE_CODES,
  normalizeAutomationSource,
  type AutomationNoteCode,
  type AutomationProvenance,
} from '@/api/automation'

const emit = defineEmits<{ 'counts-changed': [] }>()

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const pageId = useId()

const TABS: SuggestionStatus[] = ['pending', 'needs_input', 'auto_posted', 'approved', 'rejected']
const tab = ref<SuggestionStatus>('pending')
const tabCounts = reactive<Partial<Record<SuggestionStatus, number>>>({})

const COLUMNS: ColumnDef[] = [
  { key: 'date', labelKey: 'bank.date', required: true },
  { key: 'amount', labelKey: 'bank.amount', required: true },
  { key: 'counterparty', labelKey: 'bank.counterparty' },
  { key: 'counterparty_account', labelKey: 'bank.posting.rule_counterparty', defaultHidden: true },
  { key: 'vs', labelKey: 'bank.posting.col_vs', defaultHidden: true },
  { key: 'symbols', labelKey: 'bank.posting.col_ks_ss', defaultHidden: true },
  { key: 'rule', labelKey: 'bank.posting.col_rule' },
  { key: 'accounts', labelKey: 'bank.posting.col_accounts', required: true },
  { key: 'confidence', labelKey: 'automation.confidence_heading' },
  { key: 'note', labelKey: 'bank.posting.col_note', defaultHidden: true },
]
const tbl = useTablePrefs('bank_posting_suggestions', COLUMNS)

const items = ref<PostingSuggestion[]>([])
const page = ref(1)
const perPage = ref(50)
const total = ref(0)
const loading = ref(false)
const selected = reactive<Set<number>>(new Set())
const rowErrors = reactive<Record<number, string>>({})

const isPending = computed(() => tab.value === 'pending')
const isNeedsInput = computed(() => tab.value === 'needs_input')
const isActionable = computed(() => isPending.value || isNeedsInput.value)
const isAutoPosted = computed(() => tab.value === 'auto_posted')

async function load() {
  loading.value = true
  selected.clear()
  for (const k of Object.keys(rowErrors)) delete rowErrors[Number(k)]
  try {
    const res = await bankPostingApi.listSuggestions({ status: tab.value, page: page.value, per_page: perPage.value })
    if (res.items.length === 0 && res.total > 0 && page.value > 1) {
      page.value = Math.max(1, Math.ceil(res.total / res.per_page))
      return
    }
    items.value = res.items
    total.value = res.total
    perPage.value = res.per_page
    tabCounts[tab.value] = res.total
  } finally {
    loading.value = false
  }
}
async function loadCounts() {
  await Promise.all(TABS.map(async status => {
    try {
      const result = await bankPostingApi.listSuggestions({ status, page: 1 })
      tabCounts[status] = result.total
    } catch { /* počty jsou doplňkové */ }
  }))
}
onMounted(async () => { await Promise.all([load(), loadCounts()]) })
watch(page, load)

function switchTab(v: SuggestionStatus) {
  if (tab.value === v) return
  tab.value = v
  if (page.value !== 1) page.value = 1
  else void load()
}

// Výběr (jen pending, jen desktop)
const selectableIds = computed(() => items.value
  .filter(i => !i.period_closed && !(AI_SOURCES as readonly string[]).includes(i.source))
  .map(i => i.id))
const allSelected = computed(() => selectableIds.value.length > 0 && selectableIds.value.every(id => selected.has(id)))
function toggleAll() {
  if (allSelected.value) selected.clear()
  else selectableIds.value.forEach(id => selected.add(id))
}
function toggleOne(id: number) {
  if (selected.has(id)) selected.delete(id)
  else selected.add(id)
}

// Inline override (per řádek)
const overrideId = ref<number | null>(null)
const overrideDebit = ref('')
const overrideCredit = ref('')
const accounts = ref<ChartAccount[]>([])
const activeAccounts = computed(() =>
  accounts.value.filter(a => a.is_active).sort((a, b) => a.account_code.localeCompare(b.account_code)),
)
async function openOverride(it: PostingSuggestion) {
  if (overrideId.value === it.id) { overrideId.value = null; return }
  overrideId.value = it.id
  overrideDebit.value = it.debit_account_code
  overrideCredit.value = it.credit_account_code
  if (accounts.value.length === 0) {
    try { accounts.value = await accountingApi.listAccounts() } catch { /* datalist jen našeptává */ }
  }
}

const busyId = ref<number | null>(null)

async function approve(it: PostingSuggestion) {
  if (busyId.value) return
  busyId.value = it.id
  delete rowErrors[it.id]
  try {
    const overrides = overrideId.value === it.id && overrideDebit.value && overrideCredit.value
      ? { debit_account_code: overrideDebit.value, credit_account_code: overrideCredit.value }
      : undefined
    await bankPostingApi.approveSuggestion(it.id, overrides)
    toast.success(t('bank.posting.posted_done'))
    overrideId.value = null
    emit('counts-changed')
    await load()
    void loadCounts()
  } catch (e) {
    rowErrors[it.id] = bankPostingErrorMessage(e, t)
    await load()
    void loadCounts()
  } finally {
    busyId.value = null
  }
}

async function reject(it: PostingSuggestion) {
  if (busyId.value) return
  busyId.value = it.id
  delete rowErrors[it.id]
  try {
    const res = await bankPostingApi.rejectSuggestion(it.id)
    if (res.rule_disabled) toast.warning(t('bank.posting.rule_disabled_toast'))
    emit('counts-changed')
    await load()
    void loadCounts()
  } catch (e) {
    rowErrors[it.id] = bankPostingErrorMessage(e, t)
    await load()
  } finally {
    busyId.value = null
  }
}

async function bulkApprove() {
  const ids = [...selected]
  if (ids.length === 0 || busyId.value) return
  busyId.value = -1
  try {
    const res = await bankPostingApi.bulkApprove(ids)
    toast.success(t('bank.posting.bulk_result', { ok: res.approved, failed: res.failed.length }))
    for (const f of res.failed) {
      const key = bankPostingErrorKey(f.code)
      rowErrors[f.id] = key ? t(key) : t('bank.posting.err_generic')
    }
    emit('counts-changed')
    await load()
    void loadCounts()
  } catch (e) {
    toast.error(bankPostingErrorMessage(e, t))
  } finally {
    busyId.value = null
  }
}

async function unpost(it: PostingSuggestion) {
  if (busyId.value) return
  if (!confirm(t('bank.posting.unpost_confirm'))) return
  busyId.value = it.id
  try {
    await bankPostingApi.unpost(it.transaction.id)
    toast.success(t('bank.posting.unpost_done'))
    emit('counts-changed')
    await load()
    void loadCounts()
  } catch (e) {
    toast.error(bankPostingErrorMessage(e, t))
  } finally {
    busyId.value = null
  }
}

function counterpartyAccount(it: PostingSuggestion): string {
  const acc = it.transaction.counterparty_account
  if (!acc) return '—'
  return it.transaction.counterparty_bank ? `${acc}/${it.transaction.counterparty_bank}` : acc
}

function ksSs(it: PostingSuggestion): string {
  const ks = it.transaction.constant_symbol
  const ss = it.transaction.specific_symbol
  if (!ks && !ss) return '—'
  return `${ks ?? '—'} / ${ss ?? '—'}`
}

function tabLabel(v: SuggestionStatus): string {
  return v === 'pending' ? t('bank.posting.tab_suggestions')
    : v === 'needs_input' ? t('bank.posting.tab_needs_input')
    : v === 'auto_posted' ? t('bank.posting.tab_auto_posted')
    : v === 'approved' ? t('bank.posting.tab_approved')
    : t('bank.posting.tab_rejected')
}

function provenance(it: PostingSuggestion): AutomationProvenance {
  return {
    source: normalizeAutomationSource(it.source),
    mode: it.status === 'auto_posted' ? 'auto' : 'approved',
    confidence: it.confidence,
    detector: it.detector ?? (it.source === 'transfer' ? 'own_transfer' : null),
    rule_id: it.rule_id,
    rule_name: it.rule_name,
    suggestion_id: it.id,
    decided_at: it.created_at,
    decided_by: null,
  }
}

function noteText(item: PostingSuggestion): string {
  const note = item.note
  if (!note) return '—'
  const code = note.replace(/:#\d+$/, '')
  if ((AUTOMATION_NOTE_CODES as readonly string[]).includes(code)) {
    return t(`automation.reason.${code as AutomationNoteCode}`)
  }
  if (note === 'already_paid_verify') return t('bank.posting.reason_already_paid_verify')
  if (note.startsWith('looks_like:')) return t('automation.why.learned')
  if (note.startsWith('corrected_from:') && item.correction) {
    const to = `${item.correction.final_debit ?? '—'}/${item.correction.final_credit ?? '—'}`
    if (item.correction.suggested_debit == null || item.correction.suggested_credit == null) {
      return t('automation.manual_post_history', { date: formatDate(item.correction.created_at), to })
    }
    const from = `${item.correction.suggested_debit}/${item.correction.suggested_credit}`
    return t('automation.correction_history', { date: formatDate(item.correction.created_at), from, to })
  }
  return t('automation.reason.unknown')
}

function emptyText(): string {
  return isNeedsInput.value ? t('bank.posting.needs_input_empty') : t('bank.posting.suggestions_empty')
}

function resolveLink(it: PostingSuggestion) {
  const code = it.note?.replace(/:#\d+$/, '')
  if (code === 'period_closed') return { name: 'accounting-periods' }
  if (code === 'liability_prescription_missing' || code === 'document_not_posted') {
    return { path: '/accounting/journal' }
  }
  return null
}
useWindowRefresh(() => Promise.all([load(), loadCounts()]), () => busyId.value === null && selected.size === 0)
</script>

<template>
  <div>
    <!-- Taby stavů -->
    <div class="flex items-center justify-between gap-2 mb-3 flex-wrap">
      <div class="flex gap-1 border-b border-neutral-200 overflow-x-auto">
        <button v-for="tt in TABS" :key="tt" @click="switchTab(tt)"
          class="cursor-pointer px-3 py-2 text-sm border-b-2 transition whitespace-nowrap"
          :class="tab === tt ? 'border-primary-600 text-primary-700 font-medium' : 'border-transparent text-neutral-600 hover:text-neutral-900'">
          {{ tabLabel(tt) }}
          <span v-if="tabCounts[tt] != null" class="ml-1 text-xs text-neutral-500">({{ tabCounts[tt] }})</span>
        </button>
      </div>
      <div class="flex items-center gap-2">
        <ColumnPicker class="hidden md:block" :ctrl="tbl" />
        <DensityToggle class="hidden md:block" :ctrl="tbl" />
      </div>
    </div>

    <!-- Hromadné schválení v plovoucí liště u spodní hrany (jen pending, výběr jen na desktopu). -->
    <BulkActionBar v-if="isPending && auth.canWrite('bank.post')" :count="selected.size" @clear="selected.clear()">
      <button @click="bulkApprove" :disabled="busyId === -1" :class="btnFilled('primary')">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
        {{ t('bank.posting.bulk_approve', { count: selected.size }) }}
      </button>
    </BulkActionBar>

    <div v-if="loading" class="text-center text-neutral-500 py-12 text-sm">{{ t('common.loading') }}</div>

    <EmptyState v-else-if="items.length === 0" boxed icon="clipboardCheck" accent="success" :title="emptyText()" />

    <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <!-- Desktop -->
      <div class="hidden md:block overflow-x-auto">
        <table v-column-labels="tbl" class="w-full text-sm" :class="tbl.densityClass.value">
          <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
            <tr>
              <th v-if="isPending && auth.canWrite('bank.post')" class="px-3 py-2 w-8">
                <input type="checkbox" :checked="allSelected" @change="toggleAll" class="rounded border-neutral-300" />
              </th>
              <th v-if="tbl.isVisible('date')" class="px-3 py-2 text-left font-medium w-28">{{ t('bank.date') }}</th>
              <th v-if="tbl.isVisible('amount')" class="px-3 py-2 text-right font-medium w-32">{{ t('bank.amount') }}</th>
              <th v-if="tbl.isVisible('counterparty')" class="px-3 py-2 text-left font-medium">{{ t('bank.counterparty') }}</th>
              <th v-if="tbl.isVisible('counterparty_account')" class="px-3 py-2 text-left font-medium">{{ t('bank.posting.rule_counterparty') }}</th>
              <th v-if="tbl.isVisible('vs')" class="px-3 py-2 text-left font-medium w-24">{{ t('bank.posting.col_vs') }}</th>
              <th v-if="tbl.isVisible('symbols')" class="px-3 py-2 text-left font-medium w-24">{{ t('bank.posting.col_ks_ss') }}</th>
              <th v-if="tbl.isVisible('rule')" class="px-3 py-2 text-left font-medium">{{ t('bank.posting.col_rule') }}</th>
              <th v-if="tbl.isVisible('accounts')" class="px-3 py-2 text-left font-medium w-28">{{ t('bank.posting.col_accounts') }}</th>
              <th v-if="tbl.isVisible('confidence')" class="px-3 py-2 text-left font-medium w-40">{{ t('automation.confidence_heading') }}</th>
              <th v-if="tbl.isVisible('note')" class="px-3 py-2 text-left font-medium">{{ t('bank.posting.col_note') }}</th>
              <th class="px-3 py-2 w-64"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="it in items" :key="it.id">
              <td v-if="isPending && auth.canWrite('bank.post')" class="px-3 py-2">
                <input v-if="!it.period_closed" type="checkbox" :checked="selected.has(it.id)"
                  :disabled="(AI_SOURCES as readonly string[]).includes(it.source)"
                  :title="(AI_SOURCES as readonly string[]).includes(it.source) ? t('automation.ai_no_bulk') : ''"
                  @change="toggleOne(it.id)" class="rounded border-neutral-300 disabled:opacity-50" />
              </td>
              <td v-if="tbl.isVisible('date')" class="px-3 py-2 whitespace-nowrap">{{ formatDate(it.transaction.posted_at) }}</td>
              <td v-if="tbl.isVisible('amount')" class="px-3 py-2 text-right font-mono"
                :class="it.transaction.amount > 0 ? 'text-success-700 dark:text-success-600' : 'text-danger-500'">
                {{ it.transaction.amount > 0 ? '+' : '' }}{{ formatMoney(it.transaction.amount, it.transaction.currency) }}
              </td>
              <td v-if="tbl.isVisible('counterparty')" class="px-3 py-2">
                <div class="text-neutral-700 truncate max-w-xs">{{ it.transaction.counterparty_name || it.transaction.counterparty_account || '—' }}</div>
                <div v-if="it.transaction.description" class="text-xs text-neutral-500 truncate max-w-xs">{{ it.transaction.description }}</div>
              </td>
              <td v-if="tbl.isVisible('counterparty_account')" class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ counterpartyAccount(it) }}</td>
              <td v-if="tbl.isVisible('vs')" class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ it.transaction.variable_symbol || '—' }}</td>
              <td v-if="tbl.isVisible('symbols')" class="px-3 py-2 font-mono text-xs whitespace-nowrap">{{ ksSs(it) }}</td>
              <td v-if="tbl.isVisible('rule')" class="px-3 py-2 text-xs">
                <WhyChip :provenance="provenance(it)" />
              </td>
              <td v-if="tbl.isVisible('accounts')" class="px-3 py-2 font-mono text-xs whitespace-nowrap">
                {{ it.debit_account_code }}/{{ it.credit_account_code }}
              </td>
              <td v-if="tbl.isVisible('confidence')" class="px-3 py-2">
                <ConfidenceLabel v-if="it.confidence != null" :confidence="it.confidence" />
                <span v-else class="text-neutral-400">—</span>
              </td>
              <td v-if="tbl.isVisible('note')" class="px-3 py-2 text-xs text-neutral-500 max-w-[14rem]">
                <div class="truncate" :title="it.note ?? ''">{{ noteText(it) }}</div>
              </td>
              <td class="px-3 py-2 text-right text-xs">
                <!-- akce nad návrhem / položkou, která potřebuje zásah -->
                <template v-if="isActionable && auth.canWrite('bank.post')">
                  <RouterLink v-if="isNeedsInput && resolveLink(it)" :to="resolveLink(it)!"
                    :class="btnOutline('warning')" class="mb-1">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
                    {{ t('bank.posting.action_resolve') }}
                  </RouterLink>
                  <span v-if="it.period_closed" class="inline-flex items-center text-xs px-2 py-0.5 rounded bg-neutral-100 text-neutral-500"
                    :title="t('bank.posting.err_period_closed')">{{ t('bank.posting.period_closed_badge') }}</span>
                  <template v-else>
                    <div class="inline-flex items-center gap-2 justify-end flex-wrap">
                      <button @click="approve(it)" :disabled="busyId === it.id" :class="btnFilled('primary')">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
                        {{ t('bank.posting.action_approve') }}
                      </button>
                      <button @click="reject(it)" :disabled="busyId === it.id" :class="btnOutline('neutral')">{{ t('bank.posting.action_reject') }}</button>
                      <button v-if="it.source !== 'transfer'" @click="openOverride(it)" :disabled="busyId === it.id"
                        class="cursor-pointer text-neutral-400 hover:text-neutral-600 px-1" :title="t('bank.posting.override_accounts')">⚙</button>
                    </div>
                    <div v-if="overrideId === it.id" class="inline-flex items-center gap-1 mt-1">
                      <input v-model="overrideDebit" :list="`${pageId}-bps-coa`" type="text"
                        class="w-20 h-7 px-1.5 border border-neutral-300 rounded text-xs font-mono" />
                      <span class="text-neutral-400">/</span>
                      <input v-model="overrideCredit" :list="`${pageId}-bps-coa`" type="text"
                        class="w-20 h-7 px-1.5 border border-neutral-300 rounded text-xs font-mono" />
                    </div>
                  </template>
                </template>
                <!-- auto_posted: protokol + storno -->
                <template v-else-if="isAutoPosted">
                  <RouterLink v-if="it.journal_entry_id" :to="`/accounting/journal?entry_id=${it.journal_entry_id}`"
                    class="text-primary-600 hover:underline mr-2">{{ it.document_no || t('bank.open') }}</RouterLink>
                  <button v-if="auth.canWrite('bank.post')" @click="unpost(it)" :disabled="busyId === it.id"
                    class="cursor-pointer text-neutral-500 hover:text-danger-600 disabled:opacity-50">{{ t('bank.posting.action_reverse') }}</button>
                </template>
                <!-- approved/rejected: read-only -->
                <template v-else>
                  <RouterLink v-if="it.journal_entry_id" :to="`/accounting/journal?entry_id=${it.journal_entry_id}`"
                    class="text-primary-600 hover:underline">{{ it.document_no || t('bank.open') }}</RouterLink>
                  <span v-else class="text-neutral-400">—</span>
                </template>
                <div v-if="rowErrors[it.id]" class="text-danger-500 mt-1">{{ rowErrors[it.id] }}</div>
              </td>
            </tr>
          </tbody>
        </table>
        <datalist :id="`${pageId}-bps-coa`">
          <option v-for="a in activeAccounts" :key="a.id" :value="a.account_code">{{ a.account_code }} - {{ a.name }}</option>
        </datalist>
      </div>

      <!-- Mobile -->
      <div class="md:hidden divide-y divide-neutral-100">
        <div v-for="it in items" :key="`m-${it.id}`" class="p-3 space-y-2">
          <div class="flex items-baseline justify-between gap-2">
            <span class="font-mono text-sm font-semibold whitespace-nowrap" :class="it.transaction.amount > 0 ? 'text-success-700 dark:text-success-600' : 'text-danger-500'">
              {{ it.transaction.amount > 0 ? '+' : '' }}{{ formatMoney(it.transaction.amount, it.transaction.currency) }}
            </span>
            <span class="font-mono text-xs text-neutral-500">{{ it.debit_account_code }}/{{ it.credit_account_code }}</span>
          </div>
          <div class="text-xs text-neutral-500 flex items-center justify-between">
            <span>{{ formatDate(it.transaction.posted_at) }}</span>
            <WhyChip :provenance="provenance(it)" />
          </div>
          <ConfidenceLabel v-if="it.confidence != null" :confidence="it.confidence" />
          <div class="font-medium text-neutral-900 truncate">{{ it.transaction.counterparty_name || it.transaction.counterparty_account || '—' }}</div>
          <div v-if="it.note" class="text-xs text-neutral-500" :title="it.note">{{ noteText(it) }}</div>
          <span v-if="it.period_closed" class="inline-flex items-center text-xs px-2 py-0.5 rounded bg-neutral-100 text-neutral-500">{{ t('bank.posting.period_closed_badge') }}</span>
          <RouterLink v-if="isNeedsInput && resolveLink(it)" :to="resolveLink(it)!"
            :class="btnOutline('warning')" class="w-full justify-center">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
            {{ t('bank.posting.action_resolve') }}
          </RouterLink>
          <div v-if="isActionable && auth.canWrite('bank.post') && !it.period_closed" class="flex gap-2 pt-1">
            <button @click="approve(it)" :disabled="busyId === it.id"
              class="cursor-pointer flex-1 h-9 text-sm bg-primary-600 hover:bg-primary-700 disabled:opacity-50 text-white font-medium rounded-md inline-flex items-center justify-center gap-1.5">
              <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.checkCircle" /></svg>
              {{ t('bank.posting.action_approve') }}
            </button>
            <button @click="reject(it)" :disabled="busyId === it.id"
              class="cursor-pointer flex-1 h-9 text-sm border border-neutral-300 text-neutral-600 hover:bg-danger-50 hover:text-danger-600 rounded-md">{{ t('bank.posting.action_reject') }}</button>
          </div>
          <div v-else-if="isAutoPosted" class="flex gap-2 pt-1">
            <RouterLink v-if="it.journal_entry_id" :to="`/accounting/journal?entry_id=${it.journal_entry_id}`"
              class="flex-1 h-9 inline-flex items-center justify-center text-sm border border-primary-500/40 text-primary-700 hover:bg-primary-50 rounded-md">{{ it.document_no || t('bank.open') }}</RouterLink>
            <button v-if="auth.canWrite('bank.post')" @click="unpost(it)" :disabled="busyId === it.id"
              class="cursor-pointer flex-1 h-9 text-sm border border-neutral-300 text-neutral-600 hover:bg-danger-50 hover:text-danger-600 rounded-md">{{ t('bank.posting.action_reverse') }}</button>
          </div>
          <div v-if="rowErrors[it.id]" class="text-xs text-danger-500">{{ rowErrors[it.id] }}</div>
        </div>
      </div>
    </div>
    <PaginationBar :page="page" :per-page="perPage" :total="total" @update:page="page = $event" />
  </div>
</template>
