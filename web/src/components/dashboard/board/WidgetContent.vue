<script setup lang="ts">
import { computed } from 'vue'
import { RouterLink } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { formatMoney, formatDate, formatMonth } from '@/composables/useFormat'
import { regular } from '@/composables/useTaxEngine'
import PortalMonthlyChart from '@/components/charts/PortalMonthlyChart.vue'
import TopClientsTable from './TopClientsTable.vue'
import TopClientsPieChart from '@/components/charts/TopClientsPieChart.vue'
import type { PortalKpiRow } from '@/api/portal'
import type { WidgetData, WidgetInstance, WidgetItem } from '@/dashboard/types'
const props = defineProps<{ instance: WidgetInstance; data: WidgetData }>()
const { t, te } = useI18n()
const heightPx = computed(() => ({ auto: 280, compact: 180, normal: 280, tall: 420 }[props.instance.height ?? 'normal'] ?? 280))
const contentStyle = computed(() => props.instance.height && props.instance.height !== 'auto' && !['business.monthly','clients.top'].includes(props.instance.type) ? { maxHeight: `${heightPx.value}px`, overflowY: 'auto' as const } : {})
const type = computed(() => props.instance.type)
const rows = computed(() => (props.data.rows ?? []) as WidgetItem[])
const items = computed(() => (props.data.items ?? []).slice(0, Number(props.instance.options.rows ?? 20)))
const link = (item: WidgetItem) => safeLink(item.links?.detail ?? item.link)
function safeLink(value: unknown): string | undefined { return typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') ? value : undefined }
const listLink = computed(() => safeLink(props.data.links?.list ?? props.data.link))
const currency = computed(() => {
  const currencies = rows.value.map(r => String(r.currency ?? 'CZK'))
  const selected = String(props.instance.options.currency ?? 'auto')
  return selected === 'auto' ? (currencies.includes('CZK') ? 'CZK' : currencies[0] ?? 'CZK') : selected
})
const businessRows = computed(() => {
  const raw = (props.data.rows ?? []) as PortalKpiRow[]
  if (currency.value !== 'all') return raw.filter(r => r.currency === currency.value)
  if (props.instance.type === 'business.kpi') return raw
  const grouped = new Map<string, PortalKpiRow>()
  for (const row of raw) {
    const key = row.period ?? ''
    const sum = grouped.get(key) ?? { period: row.period, currency: 'CZK', invoiced: 0, costs: 0, profit: 0, invoiced_czk: 0, costs_czk: 0, profit_czk: 0, invoice_count: 0, purchase_count: 0 }
    sum.invoiced += Number(row.invoiced_czk); sum.costs += Number(row.costs_czk); sum.profit += Number(row.profit_czk)
    sum.invoice_count += Number(row.invoice_count); sum.purchase_count += Number(row.purchase_count)
    grouped.set(key, sum)
  }
  return [...grouped.values()].sort((a,b) => String(a.period).localeCompare(String(b.period)))
})
const moneyCurrency = computed(() => currency.value === 'all' ? 'CZK' : currency.value)
const net = computed(() => {
  const a = props.data.analysis
  if (!a) return null
  const income = Math.round(a.mode === 'forecast' ? (a as unknown as { predict?: { projected?: number } }).predict?.projected ?? a.ytd_income ?? 0 : a.income ?? 0)
  if (income <= 0) return null
  return { income, ...regular({ ...a.profile, is_vat_payer: a.is_vat_payer }, income, a.constants) }
})
const topClients = computed(() => items.value.map(i => ({ client_id: Number(i.client_id), company_name: String(i.company_name ?? ''), currencies: String(i.currencies ?? i.currency ?? 'CZK'), total_czk: Number(i.total_czk ?? i.revenue ?? 0), invoice_count: Number(i.invoice_count ?? 0) })))
const summary = computed(() => {
  const d = props.data
  if (type.value === 'sales.summary') return [
    { label: t('dashboard.issued_count', { year: new Date().getFullYear() }), value: d.issued_count_ytd },
    { label: t('dashboard.overdue'), value: d.overdue_count },
    { label: t('dashboard.avg_payment'), value: `${d.avg_payment_days ?? '—'} ${t('dashboard.days')}` },
  ]
  if (type.value === 'purchases.summary') return [
    { label: t('dashboard.purchase_count_ytd'), value: d.purchase_count_ytd },
    { label: t('dashboard.purchase_unpaid'), value: d.purchase_unpaid_count },
    { label: t('dashboard.purchase_unpaid'), value: formatMoney(Number(d.purchase_unpaid_total ?? 0), 'CZK') },
    { label: t('dashboard.purchase_overdue'), value: d.purchase_overdue_count },
  ]
  if (type.value === 'invoices.approvals') return [{ label: t('dashboard.pending_approvals'), value: d.requested }, { label: t('dashboard.overdue'), value: d.overdue }]
  if (type.value === 'documents.requests') return [{ label: t('dashboard.document_requests_open'), value: d.open }, { label: t('dashboard.overdue'), value: d.overdue }]
  if (type.value === 'accounting.status') return ['backfill_pending','bank_posting_pending','automation_auto_today'].filter(k => k in d).map(k => ({ label: t(`dashboardBoard.${k}`), value: d[k] }))
  return []
})
const agingLabel = (bucket: unknown) => t(`crm.aging.bucket.${String(bucket)}`)
const quickKey: Record<string, string> = { invoices: 'portal.quick_invoice', purchase_invoices: 'portal.quick_purchase', clients: 'portal.quick_contact' }
function itemLabel(item: WidgetItem): string {
  if (type.value === 'actions.quick') return t(quickKey[String(item.id)] ?? 'dashboardBoard.open')
  const key = item.title_key ?? item.titleKey
  if (typeof key === 'string') return t(key, { account: String(item.title ?? ''), ...((item.title_params ?? item.titleParams ?? {}) as Record<string, unknown>) })
  if (item.title) return item.title
  if (type.value === 'actions.todo' && te(`dashboardBoard.${String(item.type)}`)) return t(`dashboardBoard.${String(item.type)}`)
  if (item.advance_kind) return t(`taxReturn.advance_kind_${String(item.advance_kind)}`)
  return String(item.title ?? item.label ?? item.company_name ?? item.client_company_name ?? item.invoice_number ?? item.number ?? item.varsymbol ?? '—')
}
function itemHint(item: WidgetItem): string {
  const key = item.hint_key ?? item.hintKey
  return typeof key === 'string' ? t(key, (item.hint_params ?? item.hintParams ?? {}) as Record<string, unknown>) : String(item.hint ?? '')
}
const tableFirst = computed(() => ['left','above'].includes(String(props.instance.options.tablePosition ?? 'left')))
const topDisplay = computed(() => String(props.instance.options.display ?? 'both'))
const itemAmount = (item: WidgetItem) => item.amount ?? item.amount_to_pay ?? item.total_with_vat ?? item.revenue ?? item.total
</script>

<template>
  <div class="min-w-0 space-y-3" :style="contentStyle">
    <template v-if="type === 'business.kpi'">
      <p class="text-xs text-neutral-500">{{ t(`portal.period_${instance.options.period}`) }}</p>
      <div v-for="(row, i) in businessRows" :key="i" class="space-y-1 text-sm">
        <div class="text-xs text-neutral-500">{{ row.currency }}</div>
        <dl class="space-y-1">
          <div class="flex flex-wrap justify-between gap-x-2"><dt class="text-neutral-500">{{ t('portal.kpi_invoiced') }}</dt><dd class="font-mono font-semibold">{{ formatMoney(row.invoiced, row.currency) }}</dd></div>
          <div class="flex flex-wrap justify-between gap-x-2"><dt class="text-neutral-500">{{ t('portal.kpi_costs') }}</dt><dd class="font-mono">{{ formatMoney(row.costs, row.currency) }}</dd></div>
          <div class="flex flex-wrap justify-between gap-x-2 border-t border-neutral-100 pt-1"><dt class="text-neutral-500">{{ t('portal.kpi_margin') }}</dt><dd class="font-mono font-semibold" :class="row.profit < 0 ? 'text-danger-600' : 'text-success-600'">{{ formatMoney(row.profit, row.currency) }}</dd></div>
        </dl>
      </div>
      <p v-if="!businessRows.length" class="text-sm text-neutral-500">{{ t('dashboardBoard.noData') }}</p>
    </template>
    <template v-else-if="type === 'business.monthly'">
      <PortalMonthlyChart :style="{ height: `${heightPx}px`, minWidth: '0' }" :labels="businessRows.map(r => formatMonth(r.period ?? ''))" :invoiced="businessRows.map(r => r.invoiced)" :costs="businessRows.map(r => r.costs)" :currency="moneyCurrency" :invoiced-label="t('portal.kpi_invoiced')" :costs-label="t('portal.kpi_costs')" />
    </template>
    <template v-else-if="type === 'receivables.aging' || type === 'payables.aging'">
      <div v-for="(row, i) in rows.filter(r => currency === 'all' || r.currency === currency)" :key="i" class="flex flex-wrap justify-between gap-2 text-sm border-b border-neutral-100 pb-1">
        <span class="text-neutral-500">{{ agingLabel(row.bucket) }} <span class="text-xs">({{ row.count }})</span></span><span class="font-mono">{{ formatMoney(Number(row.total), String(row.currency)) }}</span>
      </div>
    </template>
    <template v-else-if="type === 'cashflow.weekly'">
      <div class="overflow-x-auto"><table class="w-full text-xs"><thead><tr class="text-neutral-500 text-left"><th>{{ t('dashboardBoard.period') }}</th><th class="text-right">{{ t('dashboardBoard.inflow') }}</th><th class="text-right">{{ t('dashboardBoard.outflow') }}</th><th class="text-right">{{ t('dashboardBoard.net') }}</th><th class="text-right">{{ t('dashboardBoard.running') }}</th></tr></thead><tbody><tr v-for="week in data.weeks" :key="week.week_start" class="border-t border-neutral-100"><td class="py-2 whitespace-nowrap">{{ formatDate(week.week_start) }} – {{ formatDate(week.week_end) }}</td><td v-for="key in (['in','out','net','running'] as const)" :key="key" class="text-right font-mono whitespace-nowrap pl-2">{{ formatMoney(week[key], data.currency ?? 'CZK') }}</td></tr></tbody></table></div>
    </template>
    <template v-else-if="type === 'cashflow.collections' || type === 'receivables.due'">
      <div v-for="(row, i) in rows.filter(r => currency === 'all' || r.currency === currency)" :key="i" class="space-y-2 text-sm">
        <div v-for="(key, j) in (type === 'cashflow.collections' ? ['30','60','90'] : ['today','week','month'])" :key="key" class="flex flex-wrap justify-between gap-2">
          <span class="text-neutral-500">{{ type === 'cashflow.collections' ? t('dashboard.cashflow_in_days', { n: key }) : t(['dashboard.due_today','dashboard.due_this_week','dashboard.due_this_month'][j]!) }} ({{ row[type === 'cashflow.collections' ? `count_${key}` : `${key}_count`] }})</span><span class="font-mono">{{ formatMoney(Number(row[type === 'cashflow.collections' ? `in_${key}` : `${key}_total`]), String(row.currency)) }}</span>
        </div>
      </div>
    </template>
    <template v-else-if="type === 'tax.net'">
      <div v-if="net" class="space-y-2"><div class="text-2xl font-mono font-semibold text-success-600">{{ formatMoney(net.net, 'CZK') }}</div><p class="text-xs text-neutral-500">{{ t('dashboard.tax_net_label') }}<span v-if="data.analysis?.mode === 'forecast'"> · {{ t('dashboard.tax_net_projected') }}</span></p><p class="text-sm">{{ t('dashboard.tax_net_income') }}: {{ formatMoney(net.income, 'CZK') }} · {{ t('dashboard.tax_net_levies') }}: {{ formatMoney(net.total, 'CZK') }} ({{ (net.eff * 100).toFixed(1) }} %)</p></div>
      <p v-else class="text-sm text-neutral-500">{{ t('dashboardBoard.noData') }}</p>
    </template>
    <template v-else-if="type === 'vat.current'">
      <p class="text-xs text-neutral-500">{{ data.period }} · {{ t('portal.vat_deadline') }}: {{ data.submission_deadline ? formatDate(String(data.submission_deadline)) : '—' }}</p>
      <dl class="space-y-2 text-sm"><div v-for="key in ['vat_output','vat_input','tax_due']" :key="key" class="flex flex-wrap justify-between gap-2"><dt class="text-neutral-500">{{ t(`reports.dph.${key}`) }}</dt><dd class="font-mono">{{ formatMoney(Number(data[key] ?? 0), 'CZK') }}</dd></div></dl>
    </template>
    <template v-else-if="type === 'clients.top'">
      <div class="top-clients" :data-table-position="instance.options.tablePosition ?? 'left'">
        <div class="clients-layout" :class="{ 'clients-side': topDisplay === 'both' && ['left','right'].includes(String(instance.options.tablePosition ?? 'left')) }">
          <TopClientsTable v-if="topDisplay !== 'chart' && tableFirst" :items="items" />
          <TopClientsPieChart v-if="topDisplay !== 'table' && topClients.length" :style="{ height: `${heightPx}px` }" :clients="topClients" />
          <TopClientsTable v-if="topDisplay !== 'chart' && !tableFirst" :items="items" />
        </div>
      </div>
      <p v-if="!items.length" class="text-sm text-neutral-500">{{ t('dashboardBoard.noData') }}</p>
    </template>
    <template v-else-if="summary.length">
      <dl class="grid grid-cols-1 sm:grid-cols-2 gap-3"><div v-for="(metric,i) in summary" :key="i"><dt class="text-xs text-neutral-500">{{ metric.label }}</dt><dd class="text-xl font-semibold font-mono">{{ metric.value ?? '—' }}</dd></div></dl>
      <div v-if="type === 'sales.summary'" class="flex flex-wrap gap-2 text-xs"><span v-for="(n, status) in (data.status_counts_ytd as Record<string, number>)" :key="status" class="rounded bg-neutral-100 px-2 py-1">{{ t(`status.${status}`) }}: {{ n }}</span></div>
      <div v-if="type === 'sales.summary'" class="text-xs space-y-1"><div v-for="(row,i) in (data.overdue_per_currency as WidgetItem[])" :key="i">{{ t('dashboard.overdue') }}: {{ formatMoney(Number(row.total), String(row.currency)) }}</div></div>
      <div v-if="type === 'accounting.status'" class="flex flex-wrap gap-2"><RouterLink v-for="(url,key) in data.links" :key="key" v-show="safeLink(url)" :to="safeLink(url) ?? ''" class="text-primary-600 text-sm hover:underline">{{ t(`dashboardBoard.${key === 'bank' ? 'bank_posting_pending' : key === 'backfill' ? 'backfill_pending' : 'automation_auto_today'}`) }} →</RouterLink></div>
    </template>
    <template v-else>
      <ul class="space-y-2">
        <li v-for="(item,i) in items" :key="String(item.id ?? i)" class="border-b border-neutral-100 last:border-0 pb-2 last:pb-0 text-sm">
          <component :is="link(item) ? RouterLink : 'div'" :to="link(item)" class="flex flex-wrap items-center justify-between gap-2" :class="link(item) ? 'text-primary-600 hover:underline' : ''">
            <span class="min-w-0 break-words"><span v-if="type === 'actions.quick'" aria-hidden="true">＋ </span>{{ itemLabel(item) }}<span v-if="item.count !== undefined" class="font-mono"> · {{ item.count }}</span></span>
            <span v-if="itemAmount(item) !== undefined" class="font-mono text-neutral-900">{{ formatMoney(Number(itemAmount(item)), String(item.currency ?? 'CZK')) }}</span>
          </component>
          <p v-if="type.startsWith('invoices.') && (item.varsymbol || item.invoice_number)" class="font-mono text-xs text-neutral-500 mt-1">{{ item.invoice_number ?? item.varsymbol }}</p>
          <p v-if="item.due_date || item.deadline || item.date" class="text-xs text-neutral-500 mt-1">{{ formatDate(String(item.due_date ?? item.deadline ?? item.date)) }}</p>
          <p v-if="itemHint(item)" class="text-xs text-neutral-500 mt-1">{{ itemHint(item) }}</p>
          <p v-if="item.project_name" class="text-xs text-neutral-500 mt-1">{{ item.project_name }}</p>
          <p v-if="item.submitted !== undefined && item.submitted !== null" class="text-xs mt-1" :class="item.submitted ? 'text-success-600' : 'text-warning-600'">{{ t(item.submitted ? 'dashboardBoard.submitted' : 'dashboardBoard.pending') }}</p>
          <p v-if="item.status === 'paid' || item.status === 'planned'" class="text-xs mt-1">{{ t(item.status === 'paid' ? 'dashboardBoard.paid' : 'dashboardBoard.pending') }}</p>
          <p v-if="item.payment && Number((item.payment as { payable?: number }).payable) > 0" class="text-xs mt-1 text-neutral-500">{{ t('crm.tax_calendar.pay_to', { amount: formatMoney(Number((item.payment as { payable?: number }).payable), 'CZK'), account: (item.payment as { account?: string }).account ?? '—' }) }} <span v-if="(item.payment as { variable_symbol?: string }).variable_symbol">· {{ t('crm.tax_calendar.vs', { vs: (item.payment as { variable_symbol?: string }).variable_symbol }) }}</span></p>
          <p v-if="item.payment && Number((item.payment as { refund?: number }).refund) > 0" class="text-xs mt-1 text-neutral-500">{{ t('crm.tax_calendar.refund', { amount: formatMoney(Number((item.payment as { refund?: number }).refund), 'CZK') }) }}</p>
          <p v-if="item.payment" class="text-xs mt-1">{{ t((item.payment as { paid?: boolean }).paid ? 'dashboardBoard.paid' : 'dashboardBoard.unpaid') }} · {{ formatMoney(Number((item.payment as { payable?: number }).payable ?? 0), 'CZK') }}</p>
        </li>
      </ul>
      <p v-if="!items.length" class="text-sm text-neutral-500">{{ t('dashboardBoard.noData') }}</p>
    </template>
    <RouterLink v-if="listLink" :to="listLink" class="inline-flex gap-1 items-center whitespace-nowrap text-primary-600 text-sm hover:underline">{{ t('dashboardBoard.open') }} <span aria-hidden="true">→</span><span v-if="data.total !== undefined">({{ data.total }})</span></RouterLink>
  </div>
</template>

<style scoped>
.top-clients { container-type: inline-size; }
.clients-layout { display: flex; flex-direction: column; gap: 1rem; }
@container (min-width: 42rem) {
  .clients-layout.clients-side { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); align-items: start; }
}
</style>
