<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { automationApi, type AutomationFeedItem, type AutomationFeedTab } from '@/api/automation'
import { bankPostingErrorMessage } from '@/api/bankPosting'
import { autoPostingApi } from '@/api/autoPosting'
import { useSupplierStore } from '@/stores/supplier'
import { useAuthStore } from '@/stores/auth'
import { useAutomationStore } from '@/stores/automation'
import { useToast } from '@/composables/useToast'
import { useHotkey } from '@/composables/useHotkey'
import { useListCursor } from '@/composables/useListCursor'
import FeedTable from './FeedTable.vue'
import AutomationRules from './AutomationRules.vue'
import AutomationChecklist from './AutomationChecklist.vue'
import AutomationHistory from './AutomationHistory.vue'
import AutomationWizard from './AutomationWizard.vue'
import AutomationRecommendations from './AutomationRecommendations.vue'
import PaginationBar from '@/components/ui/PaginationBar.vue'
import BulkActionBar from '@/components/ui/BulkActionBar.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import BulkImpactDialog from '@/components/automation/BulkImpactDialog.vue'
import RejectReasonDialog from '@/components/automation/RejectReasonDialog.vue'
import SourceDetailDrawer from '@/components/automation/SourceDetailDrawer.vue'

type CockpitTab = AutomationFeedTab | 'recommendations' | 'rules' | 'checklist' | 'history'
const route = useRoute(); const router = useRouter(); const { t } = useI18n()
const suppliers = useSupplierStore(); const auth = useAuthStore(); const store = useAutomationStore(); const toast = useToast()
const tabs: CockpitTab[] = ['recommendations','auto','pending','needs_input','rules','checklist','history']
const sourceOptions = [
  { value: 'rule', label: 'rule' },
  { value: 'learned', label: 'learned' },
  { value: 'payment_match', label: 'matched' },
  { value: 'transfer', label: 'transfer' },
  { value: 'detector', label: 'detector' },
  { value: 'schedule', label: 'schedule' },
  { value: 'document', label: 'document' },
  { value: 'ai', label: 'ai' },
] as const
const tab = computed<CockpitTab>(() => tabs.includes(String(route.query.tab) as CockpitTab) ? String(route.query.tab) as CockpitTab : 'recommendations')
const available = computed(() => suppliers.availableSuppliers.filter(s => s.accounting_mode === 'double_entry'))
const selectedSupplier = computed(() => String(suppliers.currentSupplierId))
const source = ref('')
const operationType = ref('')
const minConfidence = ref('')
const minAmount = ref('')
const maxAmount = ref('')
const sort = ref('default')
const direction = ref('desc')
const from = ref('')
const to = ref('')
const items = ref<AutomationFeedItem[]>([])
const selectedIds = ref<string[]>([])
const stats = ref<{ automation_rate: number; saved_seconds: number } | null>(null)
const topReasons = ref<Array<{ code: string; count: number }>>([])
const preview = ref<{ count: number; supplierCount: number; accounts: Array<{ currency: string; account_code: string; debit: number; credit: number }>; failed: number } | null>(null)
const bulkSelection = ref<AutomationFeedItem[]>([])
const rejectTarget = ref<AutomationFeedItem | 'bulk' | null>(null)
const sourceDetail = ref<AutomationFeedItem | null>(null)
const rejectCount = computed(() => rejectTarget.value === 'bulk' ? selectedIds.value.length : 1)
const page = ref(1); const perPage = 50; const total = ref(0)
const loading = ref(false); const error = ref(''); const busy = ref(false); const wizardOpen = ref(route.query.wizard === '1')
let loadSequence = 0
const feedRef = ref<InstanceType<typeof FeedTable> | null>(null)
const feedTab = computed(() => ['auto','pending','needs_input'].includes(tab.value) ? tab.value as AutomationFeedTab : null)
const activeSupplierIds = computed(() => available.value.filter(s => s.id === suppliers.currentSupplierId).map(s => s.id))
const { index: cursor, move, reset } = useListCursor(computed(() => items.value.length))
const rate = computed(() => Math.round((stats.value?.automation_rate ?? 0) * 100))
const currentSupplier = computed(() => available.value.find(s => s.id === suppliers.currentSupplierId) ?? null)
/**
 * Typy operací se NEvypisují ručně. Ruční kopie výčtu `OperationType` tady už jednou
 * zaostala (chyběl mimo jiné `bank.remittance.oss`) a filtr pak tiše neuměl nabídnout
 * operaci, která ve feedu existovala. Zdroj je backend: politika automatického účtování
 * vrací řádek pro každý typ (`OperationType::all()`), popisky jsou v i18n.
 *
 * Politika visí na právu `bank.rules`, kdežto feed na `accounting` — uživatel bez
 * `bank.rules` tedy seznam nedostane. Aby mu filtr nezmizel, doplňujeme typy viděné
 * ve feedu; posbírané se nezahazují při stránkování, jinak by nabídka poblikávala.
 */
const policyOperations = ref<string[]>([])
const seenOperations = ref<string[]>([])
const operationOptions = computed(() => {
  const all = [...policyOperations.value]
  for (const op of [...seenOperations.value, operationType.value].sort()) {
    if (op && !all.includes(op)) all.push(op)
  }
  return all
})
async function loadOperationOptions() {
  try {
    const policy = await autoPostingApi.getPolicy()
    policyOperations.value = policy.rows.map(row => row.operation_type)
  } catch { /* bez práva bank.rules — nabídka se poskládá z toho, co je ve feedu */ }
}

function countFor(t: AutomationFeedTab) {
  const key = t === 'auto' ? 'auto_today' : t === 'pending' ? 'pending' : 'needs_input'
  const rows = store.counts?.per_supplier ?? []
  const allowed = new Set(activeSupplierIds.value)
  return rows.filter(row => allowed.has(row.supplier_id)).reduce((sum, row) => sum + row[key], 0)
}
function reasonLabel(code: string): string {
  const key = `automation.reason.${code.split(':')[0]}`
  const translated = t(key)
  return translated === key ? code : translated
}
/** Seznam typů teď plyne z backendu, takže může přijít i typ bez překladu — radši kód než klíč. */
function operationLabel(operation: string): string {
  const key = `settings.automation.operation.${operation.replaceAll('.', '_')}`
  const translated = t(key)
  return translated === key ? operation : translated
}
function countForFeedTab(value: CockpitTab): number { return countFor(value as AutomationFeedTab) }
function changeTab(value: CockpitTab) {
  void router.replace({ query: { ...route.query, tab: value, wizard: undefined } })
}
async function load() {
  const sequence = ++loadSequence
  if (!feedTab.value || activeSupplierIds.value.length !== 1) {
    items.value = []; total.value = 0; stats.value = null; topReasons.value = []; loading.value = false
    return
  }
  loading.value = true; error.value = ''; reset()
  try {
    const today = new Intl.DateTimeFormat('en-CA').format(new Date())
    const effectiveFrom = feedTab.value === 'auto' && !from.value && !to.value ? today : from.value || undefined
    const effectiveTo = feedTab.value === 'auto' && !from.value && !to.value ? today : to.value || undefined
    const result = await automationApi.feed({
      tab: feedTab.value,
      suppliers: activeSupplierIds.value.join(','),
      source: source.value || undefined,
      operation_type: operationType.value || undefined,
      min_confidence: minConfidence.value || undefined,
      min_amount: minAmount.value || undefined,
      max_amount: maxAmount.value || undefined,
      sort: sort.value,
      direction: direction.value,
      from: effectiveFrom,
      to: effectiveTo,
      page: page.value,
      per_page: perPage,
    })
    if (sequence !== loadSequence) return
    if (result.items.length === 0 && result.total > 0 && page.value > 1) {
      page.value = Math.max(1, Math.ceil(result.total / perPage))
      return
    }
    items.value = result.items
    total.value = result.total
    for (const item of result.items) {
      if (item.operation_type && !seenOperations.value.includes(item.operation_type)) {
        seenOperations.value.push(item.operation_type)
      }
    }
    const supplierStats = await Promise.all(activeSupplierIds.value.map(id => automationApi.stats(id, from.value || undefined, to.value || undefined)))
    if (sequence !== loadSequence) return
    const automated = supplierStats.reduce((sum, row) => sum + row.auto_count + row.approved_count, 0)
    const decisions = supplierStats.reduce((sum, row) => sum + row.auto_count + row.approved_count + row.rejected_count + row.manual_bank_count, 0)
    stats.value = supplierStats.length ? {
      automation_rate: decisions === 0 ? 0 : automated / decisions,
      saved_seconds: supplierStats.reduce((sum, row) => sum + row.saved_seconds, 0),
    } : null
    if (feedTab.value === 'needs_input') {
      const reasonTo = today
      const reasonFromDate = new Date()
      reasonFromDate.setDate(reasonFromDate.getDate() - 29)
      const reasonFrom = new Intl.DateTimeFormat('en-CA').format(reasonFromDate)
      const reasonStats = await Promise.all(activeSupplierIds.value.map(id => automationApi.stats(id, reasonFrom, reasonTo)))
      if (sequence !== loadSequence) return
      const merged = new Map<string, number>()
      for (const supplier of reasonStats) for (const row of supplier.top_reasons) merged.set(row.code, (merged.get(row.code) ?? 0) + row.count)
      topReasons.value = [...merged.entries()].map(([code, count]) => ({ code, count })).sort((a, b) => b.count - a.count).slice(0, 5)
    } else topReasons.value = []
  } catch {
    if (sequence === loadSequence) error.value = t('automation.load_error')
  } finally {
    if (sequence === loadSequence) loading.value = false
  }
}
async function refresh() { await Promise.all([load(), store.refresh()]) }
async function approve(item: AutomationFeedItem, overrides: Record<string, string> = {}) {
  busy.value = true
  try {
    const result = await automationApi.approve(item, overrides)
    toast.success(t('automation.undo_toast', { md: item.debit_account_code, d: item.credit_account_code, amount: new Intl.NumberFormat().format(item.amount) }), {
      label: t('automation.undo'), handler: async () => { try { await automationApi.unpost(item, t('automation.reversal_reason', { rule: item.rule_name || t('automation.source.rule') })); await refresh() } catch { toast.error(t('automation.undo_period_closed')) } },
    })
    if (result.rule_progress?.promotion_candidate) {
      toast.success(t('automation.rules.promotion_ready_named', { name: result.rule_progress.rule_name }), {
        label: t('automation.rules.open_rules'), handler: () => { void router.push({ path: '/automation', query: { tab: 'rules', rule: String(result.rule_progress?.rule_id) } }) },
      })
    }
    await refresh()
  } catch (e) {
    toast.error(item.period_closed ? t('automation.undo_period_closed') : bankPostingErrorMessage(e, t))
    await refresh()
  } finally { busy.value = false }
}
function reject(item: AutomationFeedItem) { rejectTarget.value = item }
async function confirmReject(reason: string) {
  if (!rejectTarget.value) return
  busy.value = true
  try {
    if (rejectTarget.value === 'bulk') {
      const chosen = items.value.filter(item => selectedIds.value.includes(item.id))
      const groups = groupItems(chosen)
      let rejected = 0; let failed = 0
      for (const [supplierId, rows] of groups) {
        const result = await automationApi.bulkReject(supplierId, suggestionIds(rows), reason)
        rejected += result.rejected
        failed += result.failed.length
      }
      toast.success(t('automation.bulk_reject_result', { rejected, failed }))
      selectedIds.value = []
    } else {
      await automationApi.reject(rejectTarget.value, reason)
      toast.success(t('common.saved'))
    }
    rejectTarget.value = null
    await refresh()
  } catch { toast.error(t('common.error')) } finally { busy.value = false }
}
async function unpost(item: AutomationFeedItem) {
  if (!window.confirm(t('automation.reverse_confirm'))) return
  busy.value = true
  try { await automationApi.unpost(item, t('automation.reversal_reason', { rule: item.rule_name || t('automation.source.rule') })); await refresh() }
  catch { toast.error(t('automation.undo_period_closed')) } finally { busy.value = false }
}
function groupItems(chosen: AutomationFeedItem[]) {
  const groups = new Map<number, AutomationFeedItem[]>()
  for (const item of chosen) groups.set(item.supplier_id, [...(groups.get(item.supplier_id) ?? []), item])
  return groups
}
function suggestionIds(rows: AutomationFeedItem[]) { return rows.map(item => item.refs.suggestion_id!).filter(Boolean) }
async function bulkApprove() {
  const chosen = items.value.filter(i => selectedIds.value.includes(i.id))
  if (!chosen.length) return
  busy.value = true
  try {
    const groups = groupItems(chosen)
    const results = await Promise.all([...groups].map(([supplierId, rows]) => automationApi.bulkPreview(supplierId, suggestionIds(rows))))
    const accounts = new Map<string, { currency: string; account_code: string; debit: number; credit: number }>()
    for (const result of results) for (const row of result.accounts) {
      const key = `${row.currency}:${row.account_code}`
      const current = accounts.get(key) ?? { currency: row.currency, account_code: row.account_code, debit: 0, credit: 0 }
      current.debit = Math.round((current.debit + row.debit) * 100) / 100
      current.credit = Math.round((current.credit + row.credit) * 100) / 100
      accounts.set(key, current)
    }
    bulkSelection.value = chosen
    preview.value = {
      count: results.reduce((sum, result) => sum + result.count, 0),
      supplierCount: groups.size,
      accounts: [...accounts.values()].sort((a, b) => a.currency.localeCompare(b.currency) || a.account_code.localeCompare(b.account_code)),
      failed: results.reduce((sum, result) => sum + result.failed.length, 0),
    }
  } catch { toast.error(t('common.error')) } finally { busy.value = false }
}
async function confirmBulkApprove() {
  const groups = groupItems(bulkSelection.value)
  if (!groups.size) return
  busy.value = true
  try {
    let approved = 0; let failed = 0
    const batches: Array<{ supplierId: number; batchId: string }> = []
    for (const [supplierId, rows] of groups) {
      const result = await automationApi.bulkApprove(supplierId, suggestionIds(rows))
      approved += result.approved
      failed += result.failed.length
      if (result.batch_id) batches.push({ supplierId, batchId: result.batch_id })
    }
    toast.success(t('automation.bulk_result', { approved, failed }), batches.length ? {
      label: t('automation.undo_batch', { count: approved }),
      handler: async () => {
        let reversed = 0
        try {
          for (const batch of batches) reversed += (await automationApi.undoBatch(batch.supplierId, batch.batchId)).reversed
          toast.success(t('automation.undo_batch_result', { count: reversed }))
          await refresh()
        } catch { toast.error(t('automation.undo_period_closed')) }
      },
    } : undefined)
    preview.value = null
    bulkSelection.value = []
    selectedIds.value = []
    await refresh()
  } catch { toast.error(t('common.error')) } finally { busy.value = false }
}
async function snooze(item: AutomationFeedItem) {
  if (!item.refs.suggestion_id) return
  busy.value = true
  try {
    const active = !!item.snoozed_until && item.snoozed_until >= new Date().toISOString().slice(0, 19).replace('T', ' ')
    const tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1)
    await automationApi.snooze(item, active ? null : new Intl.DateTimeFormat('en-CA').format(tomorrow), active ? null : 'later')
    toast.success(active ? t('automation.unsnoozed') : t('automation.snoozed'))
    await refresh()
  } catch { toast.error(t('common.error')) } finally { busy.value = false }
}
function editableTarget(e: KeyboardEvent) { const el=e.target as HTMLElement|null; return el instanceof HTMLInputElement || el instanceof HTMLTextAreaElement || !!el?.isContentEditable }
useHotkey('j', e=>{if(!editableTarget(e)){e.preventDefault();move(1)}}); useHotkey('k', e=>{if(!editableTarget(e)){e.preventDefault();move(-1)}})
useHotkey('enter', e=>{if(!editableTarget(e)){e.preventDefault();feedRef.value?.toggleAt(cursor.value)}}); useHotkey('a', e=>{if(!editableTarget(e)){e.preventDefault();feedRef.value?.approveAt(cursor.value)}})
useHotkey('x', e=>{if(!editableTarget(e)){e.preventDefault();feedRef.value?.rejectAt(cursor.value)}}); useHotkey('shift+a', e=>{if(!editableTarget(e)){e.preventDefault();void bulkApprove()}})
useHotkey('1', e=>{if(!editableTarget(e))changeTab('auto')}); useHotkey('2', e=>{if(!editableTarget(e))changeTab('pending')}); useHotkey('3', e=>{if(!editableTarget(e))changeTab('needs_input')})
watch([tab, selectedSupplier, source, operationType, minConfidence, minAmount, maxAmount, sort, direction, from, to], () => {
  selectedIds.value = []
  if (page.value !== 1) page.value = 1
  else void load()
})
watch(page, () => { selectedIds.value = []; void load() })
watch(selectedSupplier, () => {
  preview.value = null; bulkSelection.value = []; rejectTarget.value = null; sourceDetail.value = null; wizardOpen.value = false
  seenOperations.value = []; policyOperations.value = []; topReasons.value = []; stats.value = null
  void loadOperationOptions()
})
onUnmounted(() => { ++loadSequence })
onMounted(async () => {
  void loadOperationOptions()
  void refresh()
})
</script>

<template>
  <main class="space-y-5">
    <header class="flex flex-wrap items-start justify-between gap-4">
      <div><h1 class="text-2xl font-semibold">{{ t('automation.title') }}</h1><p class="text-sm text-neutral-500">{{ t('automation.subtitle') }}</p></div>
      <div class="flex flex-wrap items-center gap-3">
        <button v-if="currentSupplier && auth.canWrite('bank.rules')" class="cursor-pointer rounded bg-primary-600 whitespace-nowrap px-4 py-2 text-sm font-medium text-white" @click="wizardOpen=true">{{ t('automation.wizard.title') }}</button>
        <div v-if="stats && feedTab" class="rounded-lg bg-primary-50 px-4 py-3 text-primary-700"><strong>{{ t('automation.rate_label',{pct:rate}) }}</strong><div class="text-xs">{{ t('automation.stats.saved_time',{minutes:Math.round(stats.saved_seconds/60)}) }}</div></div>
      </div>
    </header>
    <div class="flex flex-wrap gap-2 border-b border-neutral-200 pb-2"><button v-for="name in tabs" :key="name" @click="changeTab(name)" class="cursor-pointer rounded-md px-3 py-2 text-sm font-medium whitespace-nowrap" :class="tab===name ? (name==='needs_input'?'bg-danger-50 text-danger-600':name==='pending'?'bg-warning-50 text-warning-600':'bg-primary-50 text-primary-700'):'text-neutral-600 hover:bg-neutral-100'">{{ t(`automation.tab_${name}`, ['auto','pending','needs_input'].includes(name) ? { n: countForFeedTab(name) } : {}) }}</button></div>
    <div v-if="feedTab" class="flex flex-wrap items-end gap-3 rounded-lg border border-neutral-200 bg-surface p-4">
      <template v-if="feedTab">
        <label class="text-xs text-neutral-500"><span>{{ t('automation.filter_source') }}</span><select v-model="source" class="mt-1 block rounded border border-neutral-300 px-3 py-2 text-sm"><option value="">{{ t('common.all') }}</option><option v-for="item in sourceOptions" :key="item.value" :value="item.value">{{ t(`automation.source.${item.label}`) }}</option></select></label>
        <label class="text-xs text-neutral-500"><span>{{ t('automation.filter_operation') }}</span><select v-model="operationType" class="mt-1 block max-w-48 rounded border border-neutral-300 px-3 py-2 text-sm"><option value="">{{ t('common.all') }}</option><option v-for="operation in operationOptions" :key="operation" :value="operation">{{ operationLabel(operation) }}</option></select></label>
        <label class="text-xs text-neutral-500"><span>{{ t('automation.filter_confidence') }}</span><select v-model="minConfidence" class="mt-1 block rounded border border-neutral-300 px-3 py-2 text-sm"><option value="">{{ t('common.all') }}</option><option value="0.9">≥ 90 %</option><option value="0.8">≥ 80 %</option><option value="0.7">≥ 70 %</option></select></label>
        <label class="text-xs text-neutral-500"><span>{{ t('automation.filter_amount_min') }}</span><input v-model="minAmount" type="number" min="0" step="100" class="mt-1 block w-28 rounded border border-neutral-300 px-3 py-2 text-sm"></label>
        <label class="text-xs text-neutral-500"><span>{{ t('automation.filter_amount_max') }}</span><input v-model="maxAmount" type="number" min="0" step="100" class="mt-1 block w-28 rounded border border-neutral-300 px-3 py-2 text-sm"></label>
        <label class="text-xs text-neutral-500"><span>{{ t('automation.sort_label') }}</span><select v-model="sort" class="mt-1 block rounded border border-neutral-300 px-3 py-2 text-sm"><option value="default">{{ t('automation.sort_default') }}</option><option value="date">{{ t('automation.sort_date') }}</option><option value="confidence">{{ t('automation.sort_confidence') }}</option><option value="amount">{{ t('automation.sort_amount') }}</option><option value="operation_type">{{ t('automation.sort_operation') }}</option><option value="source">{{ t('automation.sort_source') }}</option></select></label>
        <button type="button" class="h-10 whitespace-nowrap rounded border border-neutral-300 px-3 text-sm" @click="direction = direction === 'desc' ? 'asc' : 'desc'">{{ direction === 'desc' ? '↓' : '↑' }} {{ t(`automation.sort_${direction}`) }}</button>
        <label class="text-xs text-neutral-500">{{ t('common.from') }}<input v-model="from" type="date" class="mt-1 block rounded border border-neutral-300 px-3 py-2 text-sm"></label>
        <label class="text-xs text-neutral-500">{{ t('common.to') }}<input v-model="to" type="date" class="mt-1 block rounded border border-neutral-300 px-3 py-2 text-sm"></label>
      </template>
    </div>
    <!-- Hromadné schválení/zamítnutí v plovoucí liště u spodní hrany (jen tab pending). -->
    <BulkActionBar v-if="tab==='pending'" :count="selectedIds.length" @clear="selectedIds=[]">
      <button :disabled="busy" class="whitespace-nowrap rounded border border-danger-300 px-4 py-2 text-sm font-medium text-danger-600" @click="rejectTarget='bulk'">{{ t('automation.bulk_reject',{count:selectedIds.length}) }}</button>
      <button :disabled="busy" class="whitespace-nowrap rounded bg-primary-600 px-4 py-2 text-sm font-medium text-white" @click="bulkApprove">{{ t('automation.bulk_approve',{count:selectedIds.length}) }}</button>
    </BulkActionBar>
    <section v-if="feedTab === 'needs_input' && topReasons.length" class="rounded-lg border border-warning-200 bg-warning-50 p-4"><h2 class="text-sm font-semibold text-warning-800">{{ t('automation.top_reasons_title') }}</h2><div class="mt-2 flex flex-wrap gap-2"><span v-for="reason in topReasons" :key="reason.code" class="rounded-full bg-surface px-3 py-1 text-xs text-warning-700">{{ reasonLabel(reason.code) }} · {{ reason.count }}×</span></div></section>
    <AutomationRecommendations v-if="tab === 'recommendations'" :key="suppliers.currentSupplierId" :suppliers="activeSupplierIds" />
    <p v-else-if="loading" class="py-12 text-center text-neutral-500">{{ t('common.loading') }}</p><p v-else-if="error" class="rounded bg-danger-50 p-4 text-danger-500">{{ error }}</p>
    <template v-else-if="feedTab"><FeedTable v-if="items.length" ref="feedRef" :items="items" :tab="feedTab" :cursor-index="cursor" :show-supplier="false" :busy="busy" @approve="approve" @reject="reject" @snooze="snooze" @inspect="sourceDetail=$event" @unpost="unpost" @resolved="refresh" @update:selected="selectedIds=$event"/><EmptyState v-else boxed icon="checkCircle" accent="success" :title="t(`automation.empty_${tab==='needs_input'?'needs':tab}`)"/><PaginationBar :page="page" :per-page="perPage" :total="total" @update:page="page = $event"/><p class="hidden md:block text-right text-xs text-neutral-400">{{ t('automation.hotkeys_hint') }}</p></template>
    <template v-else-if="tab==='rules' || tab==='checklist'"><p v-if="currentSupplier" class="text-sm text-neutral-500">{{ t('automation.single_supplier_context',{name:currentSupplier.company_name}) }}</p><AutomationRules v-if="tab==='rules' && currentSupplier" :key="`rules-${currentSupplier.id}`"/><AutomationChecklist v-else-if="currentSupplier" :key="`checklist-${currentSupplier.id}`" :supplier-id="currentSupplier.id"/></template><AutomationHistory v-else-if="currentSupplier" :key="`history-${suppliers.currentSupplierId}`" :suppliers="activeSupplierIds"/>
    <AutomationWizard v-if="wizardOpen && currentSupplier" :supplier-id="currentSupplier.id" @close="wizardOpen=false" @done="refresh"/>
    <BulkImpactDialog v-if="preview" :count="preview.count" :supplier-count="preview.supplierCount" :accounts="preview.accounts" :failed="preview.failed" :busy="busy" @confirm="confirmBulkApprove" @close="preview=null" />
    <RejectReasonDialog v-if="rejectTarget" :count="rejectCount" :busy="busy" @confirm="confirmReject" @close="rejectTarget=null" />
    <SourceDetailDrawer v-if="sourceDetail" :item="sourceDetail" @close="sourceDetail=null" />
  </main>
</template>
