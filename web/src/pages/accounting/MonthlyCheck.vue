<script setup lang="ts">
import { ref, computed, reactive, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, type RouteLocationRaw } from 'vue-router'
import { accountingApi, type AccountingPeriod, type ChartAccount } from '@/api/accounting'
import { closingApi, periodLockApi, type MonthlyCheckResult, type PrecheckItem } from '@/api/closing'
import { useAuthStore } from '@/stores/auth'
import { useToast } from '@/composables/useToast'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import CheckFindings from '@/components/accounting/CheckFindings.vue'
import EmptyState from '@/components/ui/EmptyState.vue'
import DateInput from '@/components/ui/DateInput.vue'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()

const periods = ref<AccountingPeriod[]>([])
const accounts = ref<ChartAccount[]>([])
const loadingPeriods = ref(false)
const running = ref(false)
const result = ref<MonthlyCheckResult | null>(null)

const runnablePeriods = computed(() => periods.value.filter(p => p.status === 'open' || p.status === 'closing'))

const filters = reactive({
  period_id: '' as number | '',
  granularity: 'month' as 'month' | 'quarter' | 'custom',
  month_key: '',
  quarter_key: '',
  from: '',
  to: '',
})

const selectedPeriod = computed(() => periods.value.find(p => p.id === Number(filters.period_id)) ?? null)

function pad(n: number): string { return String(n).padStart(2, '0') }
function monthRange(year: number, month: number): { from: string; to: string } {
  const lastDay = new Date(year, month, 0).getDate()
  return { from: `${year}-${pad(month)}-01`, to: `${year}-${pad(month)}-${pad(lastDay)}` }
}

interface RangeOption { key: string; label: string; from: string; to: string }

const monthOptions = computed<RangeOption[]>(() => {
  const p = selectedPeriod.value
  if (!p) return []
  const [sy, sm] = p.starts_on.split('-').map(Number)
  const [ey, em] = p.ends_on.split('-').map(Number)
  const opts: RangeOption[] = []
  let y = sy, m = sm
  while (y < ey || (y === ey && m <= em)) {
    const r = monthRange(y, m)
    opts.push({ key: `${y}-${pad(m)}`, label: `${pad(m)}/${y}`, from: r.from, to: r.to > p.ends_on ? p.ends_on : r.to })
    m++
    if (m > 12) { m = 1; y++ }
  }
  return opts
})

const quarterOptions = computed<RangeOption[]>(() => {
  const p = selectedPeriod.value
  if (!p) return []
  const [sy, sm] = p.starts_on.split('-').map(Number)
  const opts: RangeOption[] = []
  for (let q = 0; q < 4; q++) {
    let m1 = sm + q * 3, y1 = sy
    while (m1 > 12) { m1 -= 12; y1++ }
    let m2 = m1 + 2, y2 = y1
    while (m2 > 12) { m2 -= 12; y2++ }
    const from = monthRange(y1, m1).from
    if (from > p.ends_on) break
    const toRaw = monthRange(y2, m2).to
    opts.push({ key: `Q${q + 1}`, label: `Q${q + 1} ${y1}`, from, to: toRaw > p.ends_on ? p.ends_on : toRaw })
  }
  return opts
})

async function loadPeriods() {
  loadingPeriods.value = true
  try {
    periods.value = await accountingApi.listPeriods()
    if (!filters.period_id && runnablePeriods.value.length) {
      filters.period_id = runnablePeriods.value[runnablePeriods.value.length - 1].id
    }
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    loadingPeriods.value = false
  }
}

onMounted(async () => {
  await loadPeriods()
  try { accounts.value = await accountingApi.listAccounts() } catch { accounts.value = [] }
  await loadLock()
  if (selectedPeriod.value) {
    initRangeDefaults()
    run()
  }
})

function initRangeDefaults() {
  if (monthOptions.value.length) filters.month_key = monthOptions.value[monthOptions.value.length - 1].key
  if (quarterOptions.value.length) filters.quarter_key = quarterOptions.value[quarterOptions.value.length - 1].key
  const p = selectedPeriod.value
  if (p) { filters.from = p.starts_on; filters.to = p.ends_on }
}

function currentRange(): { from?: string; to?: string } {
  if (filters.granularity === 'month') {
    const o = monthOptions.value.find(o => o.key === filters.month_key)
    return o ? { from: o.from, to: o.to } : {}
  }
  if (filters.granularity === 'quarter') {
    const o = quarterOptions.value.find(o => o.key === filters.quarter_key)
    return o ? { from: o.from, to: o.to } : {}
  }
  return { from: filters.from || undefined, to: filters.to || undefined }
}

async function run() {
  if (!filters.period_id) return
  running.value = true
  try {
    const { from, to } = currentRange()
    result.value = await closingApi.monthlyCheck(Number(filters.period_id), from, to)
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  } finally {
    running.value = false
  }
}

function onPeriodChange() {
  initRangeDefaults()
  run()
}

// ── proklikatelnost výsledků ────────────────────────────────────────────────
const DOC_CHECK_LIST: Record<string, RouteLocationRaw> = {
  unposted_invoices: { path: '/invoices', query: { booked: '0', year: 'all' } },
  unposted_purchases: { path: '/purchase-invoices', query: { booked: '0' } },
  attachment_vat_period: { name: 'scan-attach', hash: '#attachment-discrepancies' },
  attachment_mismatch: { name: 'scan-attach', hash: '#attachment-discrepancies' },
}

const BALANCE_CHECK_CODES: Record<string, string[]> = {
  transit_261_open: ['261'],
  vh_431_undistributed: ['431'],
  internal_395_open: ['395'],
  acquisition_04x_open: ['041', '042'],
  procurement_111_131_open: ['111', '131'],
  estimates_balances: ['388', '389'],
  deferrals_balances: ['381', '382', '383', '384', '385'],
}
function balanceEntries(key: string, value: unknown): { code: string; amount: number }[] {
  const codes = BALANCE_CHECK_CODES[key]
  if (!codes || !value || typeof value !== 'object') return []
  const v = value as Record<string, unknown>
  if ('account' in v && 'balance' in v) {
    return [{ code: String(v.account), amount: Number(v.balance) }]
  }
  return codes.filter(c => c in v).map(c => ({ code: c, amount: Number(v[c]) }))
}

function accountIdForCode(code: string): number | null {
  const a = accounts.value.find(a => a.account_code === code)
  return a ? a.id : null
}
function accountLink(code: string): RouteLocationRaw | null {
  const id = accountIdForCode(code)
  if (!id || !result.value) return null
  return { name: 'accounting-account-statement', params: { accountId: id }, query: { from: result.value.range_from, to: result.value.range_to } }
}


function checkLabel(key: string): string {
  const k = `accounting.closing.checks.${key}`
  const v = t(k)
  return v === k ? key : v
}

/**
 * CSV export nálezů jedné kontroly. Nese stejný rozsah jako zobrazená kontrola, ať
 * export odpovídá tomu, co uživatel vidí; strop ale nemá — náhled je capnutý na 50,
 * stažený seznam musí být úplný.
 */
function checkExportUrl(key: string): string | undefined {
  const p = selectedPeriod.value
  if (!p) return undefined
  const q = new URLSearchParams()
  if (filters.from) q.set('date_from', filters.from)
  if (filters.to) q.set('date_to', filters.to)
  const sid = localStorage.getItem('myinvoice.current_supplier_id')
  if (sid) q.set('supplier_id', sid)
  return `/api/accounting/periods/${p.id}/checks/${key}/export?${q.toString()}`
}

function findingsCount(c: PrecheckItem): number {
  if (c.ok) return 0
  const v = c.value
  if (v && typeof v === 'object' && 'count' in (v as Record<string, unknown>)) {
    return Number((v as Record<string, unknown>).count)
  }
  return 1
}

function documentedTransit(value: unknown): { count: number; amount: number } | null {
  if (!value || typeof value !== 'object') return null
  const rows = (value as { documented?: unknown }).documented
  if (!Array.isArray(rows) || rows.length === 0) return null
  return {
    count: rows.length,
    amount: rows.reduce((sum, row) => sum + Number((row as { amount?: unknown }).amount ?? 0), 0),
  }
}

// ── zámek k datu (B8) ────────────────────────────────────────────────────
const lockedUntil = ref<string | null>(null)
async function loadLock() {
  try { lockedUntil.value = (await periodLockApi.get()).locked_until } catch { lockedUntil.value = null }
}

const lockDialog = reactive({ open: false, date: '', reason: '' })
function openLockDialog() {
  lockDialog.date = lockedUntil.value ?? (result.value?.range_to ?? '')
  lockDialog.reason = ''
  lockDialog.open = true
}
async function saveLock() {
  const date = lockDialog.date || null
  if (lockDialog.reason.trim().length < 5) {
    toast.warning(t('accounting.monthly_check.lock_reason_label'))
    return
  }
  const msg = date ? t('accounting.monthly_check.lock_confirm', { date }) : t('accounting.monthly_check.lock_confirm_clear')
  if (!confirm(msg)) return
  try {
    const r = await periodLockApi.update(date, lockDialog.reason.trim())
    lockedUntil.value = r.locked_until
    lockDialog.open = false
    toast.success(t('accounting.monthly_check.lock_saved'))
  } catch (e: any) {
    toast.error(e?.response?.data?.error?.message || t('common.error'))
  }
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 flex-wrap gap-2">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('accounting.monthly_check.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('accounting.monthly_check.subtitle') }}</p>
      </div>
      <button v-if="auth.canWrite('accounting.periods.close')" :class="btnOutline('primary')" @click="openLockDialog">
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
        {{ t('accounting.monthly_check.lock_button') }}
      </button>
    </div>

    <!-- Stav zámku k datu (B8) -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4 text-sm flex items-center gap-2">
      <svg class="w-4 h-4 text-neutral-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.lock" /></svg>
      <span v-if="lockedUntil" class="text-warning-600 font-medium">{{ t('accounting.monthly_check.lock_current', { date: formatDate(lockedUntil) }) }}</span>
      <span v-else class="text-neutral-500">{{ t('accounting.monthly_check.lock_none') }}</span>
    </div>

    <!-- Filtry -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3 mb-4">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 items-end">
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.monthly_check.filter_period') }}</label>
          <select v-model.number="filters.period_id" @change="onPeriodChange"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option v-for="p in runnablePeriods" :key="p.id" :value="p.id">{{ p.fiscal_year }}</option>
          </select>
        </div>
        <div>
          <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.monthly_check.filter_granularity') }}</label>
          <select v-model="filters.granularity" @change="run"
            class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
            <option value="month">{{ t('accounting.monthly_check.granularity_month') }}</option>
            <option value="quarter">{{ t('accounting.monthly_check.granularity_quarter') }}</option>
            <option value="custom">{{ t('accounting.monthly_check.granularity_custom') }}</option>
          </select>
        </div>
        <template v-if="filters.granularity === 'month'">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.monthly_check.filter_month') }}</label>
            <select v-model="filters.month_key" @change="run" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option v-for="o in monthOptions" :key="o.key" :value="o.key">{{ o.label }}</option>
            </select>
          </div>
        </template>
        <template v-else-if="filters.granularity === 'quarter'">
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.monthly_check.filter_quarter') }}</label>
            <select v-model="filters.quarter_key" @change="run" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface">
              <option v-for="o in quarterOptions" :key="o.key" :value="o.key">{{ o.label }}</option>
            </select>
          </div>
        </template>
        <template v-else>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.monthly_check.filter_from') }}</label>
            <DateInput v-model="filters.from" @change="run" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.monthly_check.filter_to') }}</label>
            <DateInput v-model="filters.to" @change="run" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm bg-surface" />
          </div>
        </template>
        <div>
          <button :class="btnFilled('primary')" :disabled="running || !filters.period_id" @click="run">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.play" /></svg>
            {{ t('accounting.monthly_check.run') }}
          </button>
        </div>
      </div>
    </div>

    <p v-if="!loadingPeriods && !runnablePeriods.length" class="text-sm text-neutral-500">{{ t('accounting.monthly_check.no_period') }}</p>

    <template v-if="result">
      <p class="text-xs text-neutral-500 mb-2">
        {{ t('accounting.monthly_check.range_label', { from: formatDate(result.range_from), to: formatDate(result.range_to) }) }}
        &middot; {{ t('accounting.monthly_check.ran_at', { date: formatDate(result.ran_at) }) }}
      </p>

      <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-xs text-neutral-500 uppercase tracking-wide">
              <tr>
                <th class="px-3 py-2 text-left font-medium w-10"></th>
                <th class="px-3 py-2 text-left font-medium">{{ t('accounting.monthly_check.col_check') }}</th>
                <th class="px-3 py-2 text-right font-medium">{{ t('accounting.monthly_check.col_findings') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="c in result.checks" :key="c.key">
                <td class="px-3 py-2">
                  <svg v-if="c.ok" class="w-4 h-4 text-success-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.check" /></svg>
                  <svg v-else class="w-4 h-4 text-danger-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.x" /></svg>
                </td>
                <td class="px-3 py-2">
                  {{ checkLabel(c.key) }}
                  <span v-if="!c.ok" class="ml-1 text-xs text-danger-500">({{ findingsCount(c) }})</span>
                </td>
                <td class="px-3 py-2 text-right text-xs">

                  <!-- zůstatkové kontroly technických účtů -->
                  <template v-if="BALANCE_CHECK_CODES[c.key]">
                    <span v-if="c.key === 'transit_261_open' && c.ok && documentedTransit(c.value)" class="text-success-600">
                      {{ t('accounting.closing.checks.transit_261_documented', {
                        count: documentedTransit(c.value)!.count,
                        amount: formatMoney(documentedTransit(c.value)!.amount),
                      }) }}
                    </span>
                    <span v-else class="inline-flex flex-wrap gap-x-3 gap-y-0.5 justify-end items-center">
                      <template v-for="e in balanceEntries(c.key, c.value)" :key="e.code">
                        <RouterLink v-if="accountLink(e.code)" :to="accountLink(e.code)!"
                          class="font-mono text-primary-600 hover:underline" :class="{ 'text-neutral-400 hover:no-underline': Math.abs(e.amount) < 0.005 }">
                          {{ e.code }}: {{ formatMoney(e.amount) }}
                        </RouterLink>
                        <span v-else class="font-mono">{{ e.code }}: {{ formatMoney(e.amount) }}</span>
                      </template>
                    </span>
                  </template>


                  <!-- ostatní (info/číselné) -->
                  <template v-else>
                    <span v-if="c.value === null || c.value === undefined" class="text-neutral-400">—</span>
                    <span v-else-if="typeof c.value === 'number'" class="font-mono">{{ formatMoney(c.value) }}</span>
                    <span v-else-if="typeof c.value === 'string'">{{ c.value }}</span>
                    <!-- Sdílený renderer podle TVARU dat. Dřív tu byl JSON.stringify,
                         takže každá kontrola bez vlastní větve výš skončila jako syrový
                         JSON — a nová kontrola se do toho stavu dostala automaticky. -->
                    <CheckFindings v-else :check-key="c.key" :label="checkLabel(c.key)" :list-link="DOC_CHECK_LIST[c.key] ?? null"
                      :value="c.value" :export-url="checkExportUrl(c.key)"
                      :period-id="result?.period?.id ?? null"
                      :date-from="result?.range_from" :date-to="result?.range_to" />
                  </template>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>
    <EmptyState v-else boxed accent="neutral" icon="clipboardCheck" :title="t('accounting.monthly_check.empty')" />

    <!-- Dialog zámku k datu -->
    <div v-if="lockDialog.open" class="fixed inset-0 bg-black/40 flex items-center justify-center z-50 p-4" @click.self="lockDialog.open = false">
      <div class="bg-surface rounded-lg shadow-lg p-4 w-full max-w-sm">
        <h2 class="text-base font-semibold mb-3">{{ t('accounting.monthly_check.lock_dialog_title') }}</h2>
        <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.monthly_check.lock_date_label') }}</label>
        <DateInput v-model="lockDialog.date" class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm mb-3" />
        <label class="block text-xs font-medium text-neutral-500 mb-1">{{ t('accounting.monthly_check.lock_reason_label') }}</label>
        <input v-model="lockDialog.reason" type="text" :placeholder="t('accounting.monthly_check.lock_reason_placeholder')"
          class="w-full h-9 px-2 border border-neutral-300 rounded-md text-sm mb-4" />
        <div class="flex justify-end gap-2">
          <button :class="btnOutline('neutral')" @click="lockDialog.open = false">{{ t('common.cancel') }}</button>
          <button :class="btnFilled('primary')" @click="saveLock">{{ t('accounting.monthly_check.lock_save') }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
