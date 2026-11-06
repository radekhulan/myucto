<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import {
  monthlyReportApi,
  type MonthlyReportData,
  type MonthlyReportSendHistoryItem,
} from '@/api/monthlyReport'
import { formatMoney, formatDate } from '@/composables/useFormat'
import { useToast } from '@/composables/useToast'
import { apiErrorMessage } from '@/api/errors'
import { ICONS, btnFilled, btnOutline } from '@/components/ui/buttonStyles'
import EmptyState from '@/components/ui/EmptyState.vue'
import Statement504Table from '@/components/accounting/Statement504Table.vue'
import AccrualSpreadToggle from '@/components/accounting/AccrualSpreadToggle.vue'
import AccrualSpreadNote from '@/components/accounting/AccrualSpreadNote.vue'
import { useAccrualSpread } from '@/composables/useAccrualSpread'

const { t } = useI18n()
const auth = useAuthStore()
const toast = useToast()
const accrualSpread = useAccrualSpread()
const accrualSpreadOn = accrualSpread.enabled

const today = new Date()
const period = ref(`${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, '0')}`)
const comment = ref('')

const report = ref<MonthlyReportData | null>(null)
const format504 = (value: number | null | undefined) => formatMoney(value, 'CZK')
const loading = ref(false)
const error = ref('')

const history = ref<MonthlyReportSendHistoryItem[]>([])
const historyLoading = ref(false)

function periodParts(): { year: number; month: number } {
  const [y, m] = period.value.split('-').map(Number)
  return { year: y, month: m }
}

async function loadPreview() {
  const { year, month } = periodParts()
  if (!year || !month) return
  loading.value = true
  error.value = ''
  try {
    report.value = await monthlyReportApi.preview(year, month, comment.value || undefined, accrualSpread.param())
  } catch (e) {
    error.value = apiErrorMessage(e)
    report.value = null
  } finally {
    loading.value = false
  }
}

async function loadHistory() {
  historyLoading.value = true
  try {
    history.value = await monthlyReportApi.history()
  } catch {
    // historie není kritická — tichý fail
  } finally {
    historyLoading.value = false
  }
}

function onAccrualSpread(value: boolean) {
  accrualSpread.set(value)
  void loadPreview()
}

// Rámec 504 volbu neuplatní, stejně jako na samostatných výkazech.
const accrualSpreadAvailable = computed(() => report.value?.accounting_framework !== 'cz_504')
const accrualSpreadNote = computed(() => {
  const r = report.value
  return r && r.accounting_framework !== 'cz_504' ? r.income_statement_ytd.accrual_spread : undefined
})

onMounted(async () => {
  await accrualSpread.restore()
  await loadPreview()
  loadHistory()
})

const downloading = ref(false)
async function downloadPdf() {
  const { year, month } = periodParts()
  if (!year || !month) return
  downloading.value = true
  try {
    const r = await monthlyReportApi.exportPdf(year, month, comment.value || undefined, accrualSpread.param())
    downloadBlob(r.data as unknown as Blob, `mesicni-prehled-${period.value}.pdf`)
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    downloading.value = false
  }
}

function downloadBlob(blob: Blob, filename: string) {
  const url = URL.createObjectURL(blob)
  const a = document.createElement('a')
  a.href = url
  a.download = filename
  document.body.appendChild(a); a.click(); a.remove()
  URL.revokeObjectURL(url)
}

// ── Odeslání klientovi ──────────────────────────────────────────────
const sendPanelOpen = ref(false)
const sendTo = ref('')
const sendCc = ref('')
const sending = ref(false)

function toggleSendPanel() {
  sendPanelOpen.value = !sendPanelOpen.value
}

function splitEmails(raw: string): string[] {
  return raw.split(/[,;\s]+/).map(s => s.trim()).filter(Boolean)
}

async function sendToClient() {
  const { year, month } = periodParts()
  const to = splitEmails(sendTo.value)
  if (to.length === 0) {
    toast.error(t('monthly_report.no_recipients'))
    return
  }
  sending.value = true
  try {
    const res = await monthlyReportApi.send({
      year, month,
      comment: comment.value || undefined,
      to,
      cc: splitEmails(sendCc.value),
      ...accrualSpread.param(),
    })
    toast.success(t('monthly_report.sent', { n: res.sent_to.length }))
    sendPanelOpen.value = false
    sendTo.value = ''
    sendCc.value = ''
    await loadHistory()
  } catch (e) {
    toast.error(apiErrorMessage(e))
  } finally {
    sending.value = false
  }
}

const monthLabel = computed(() => {
  if (!report.value) return ''
  const d = new Date(report.value.period.year, report.value.period.month - 1, 1)
  return d.toLocaleDateString('cs-CZ', { month: 'long', year: 'numeric' })
})

function historyPeriodLabel(item: MonthlyReportSendHistoryItem): string {
  return `${String(item.report_month).padStart(2, '0')}/${item.report_year}`
}
</script>

<template>
  <div>
    <div class="flex items-center justify-between mb-4 gap-3 flex-wrap">
      <div>
        <h1 class="text-2xl font-semibold">{{ t('monthly_report.title') }}</h1>
        <p class="text-sm text-neutral-500 mt-0.5">{{ t('monthly_report.subtitle') }}</p>
      </div>
    </div>

    <!-- Ovládací box -->
    <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm mb-4 p-4 space-y-3">
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3">
        <div>
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('monthly_report.period') }}</label>
          <input v-model="period" type="month" @change="loadPreview"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
        </div>
        <div class="lg:col-span-2">
          <label class="block text-sm font-medium text-neutral-700 mb-1">{{ t('monthly_report.comment') }}</label>
          <input v-model="comment" type="text" :placeholder="t('monthly_report.comment_placeholder')"
            class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
        </div>
      </div>

      <AccrualSpreadToggle v-if="accrualSpreadAvailable" :model-value="accrualSpreadOn" @update:model-value="onAccrualSpread" />

      <div class="flex items-center gap-2 flex-wrap">
        <button type="button" @click="loadPreview" :disabled="loading" :class="btnOutline('neutral')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12a7.5 7.5 0 0113.5-4.5M19.5 12a7.5 7.5 0 01-13.5 4.5M4.5 4.5v4.5h4.5M19.5 19.5v-4.5H15" /></svg>
          {{ t('monthly_report.refresh') }}
        </button>
        <button type="button" @click="downloadPdf" :disabled="downloading || !report" :class="btnOutline('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.download" /></svg>
          {{ downloading ? '…' : t('monthly_report.download_pdf') }}
        </button>
        <button v-if="auth.canWrite('accounting')" type="button" @click="toggleSendPanel" :disabled="!report" :class="btnFilled('primary')">
          <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" :d="ICONS.send" /></svg>
          {{ t('monthly_report.send_to_client') }}
        </button>
      </div>

      <div v-if="!auth.canWrite('accounting')" class="rounded-md bg-neutral-100 border border-neutral-200 px-3 py-2 text-sm text-neutral-500">
        {{ t('monthly_report.readonly_hint') }}
      </div>

      <!-- Panel odeslání -->
      <div v-if="sendPanelOpen" class="rounded-md border border-primary-500/30 bg-primary-50/40 p-3 space-y-2">
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('monthly_report.send_to') }}</label>
            <input v-model="sendTo" type="text" :placeholder="t('monthly_report.send_to_placeholder')"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
          </div>
          <div>
            <label class="block text-xs font-medium text-neutral-700 mb-1">{{ t('monthly_report.send_cc') }}</label>
            <input v-model="sendCc" type="text" :placeholder="t('monthly_report.send_cc_placeholder')"
              class="w-full h-9 px-3 border border-neutral-300 rounded-md bg-surface text-sm" />
          </div>
        </div>
        <div class="flex items-center gap-2">
          <button type="button" @click="sendToClient" :disabled="sending" :class="btnFilled('primary')">
            {{ sending ? '…' : t('monthly_report.send_confirm') }}
          </button>
          <button type="button" @click="sendPanelOpen = false" :class="btnOutline('neutral')">
            {{ t('common.cancel') }}
          </button>
        </div>
      </div>
    </div>

    <div v-if="loading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-8 text-center text-sm text-neutral-500">
      {{ t('common.loading') }}
    </div>
    <div v-else-if="error" class="bg-danger-50 border border-danger-500/40 text-danger-500 rounded-md p-3 text-sm mb-4">
      {{ error }}
    </div>

    <template v-else-if="report">
      <AccrualSpreadNote v-if="accrualSpreadNote" :spread="accrualSpreadNote" :format="format504" />

      <!-- KPI dlaždice -->
      <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3 mb-4">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500 uppercase">{{ t('monthly_report.kpi_profit_ytd') }}</div>
          <div class="text-lg font-semibold font-mono mt-1">{{ formatMoney(report.income_statement_ytd.checks.profit_current, 'CZK') }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500 uppercase">{{ report.vat && report.vat.is_excess_deduction ? t('monthly_report.kpi_vat_excess') : t('monthly_report.kpi_vat_due') }}</div>
          <template v-if="report.vat">
            <div class="text-lg font-semibold font-mono mt-1">{{ formatMoney(report.vat.tax_due, 'CZK') }}</div>
            <div class="text-xs text-neutral-500 mt-0.5">{{ t('monthly_report.kpi_vat_deadline') }}: {{ formatDate(report.vat.submission_deadline) }}</div>
          </template>
          <div v-else class="text-sm text-neutral-400 mt-1">{{ t('monthly_report.not_vat_payer') }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500 uppercase">{{ t('monthly_report.kpi_receivables_overdue') }}</div>
          <div class="text-lg font-semibold font-mono mt-1">{{ report.receivables_overdue.length }}</div>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-3">
          <div class="text-xs text-neutral-500 uppercase">{{ t('monthly_report.kpi_payables_overdue') }}</div>
          <div class="text-lg font-semibold font-mono mt-1">{{ report.payables_overdue.length }}</div>
        </div>
      </div>

      <div v-if="report.accounting_framework === 'cz_504'" class="space-y-6 mb-4">
        <h2 class="text-sm font-semibold">{{ t('monthly_report.section_income_statement') }} - {{ monthLabel }}</h2>
        <Statement504Table :report="{ ...report.income_statement_ytd, rows: report.income_statement_month }" :format="format504" />
        <h2 class="text-sm font-semibold">{{ t('monthly_report.section_income_ytd') }}</h2>
        <Statement504Table :report="report.income_statement_ytd" :format="format504" />
        <Statement504Table :report="report.balance_sheet" :format="format504" />
      </div>
      <div v-else class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 mb-4">
        <h2 class="text-sm font-semibold mb-2">{{ t('monthly_report.section_income_statement') }} - {{ monthLabel }}</h2>
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="row in report.income_statement_month" :key="row.row_code"
                :class="row.row_type !== 'detail' ? 'font-semibold' : ''">
                <td class="py-1 pr-2" :style="{ paddingLeft: (row.level * 12) + 'px' }">{{ row.label }}</td>
                <td class="py-1 text-right font-mono whitespace-nowrap">{{ formatMoney(row.amount ?? 0, 'CZK') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <h2 class="text-sm font-semibold mb-2">{{ t('monthly_report.section_receivables_overdue') }}</h2>
          <div v-if="!report.receivables_overdue.length" class="text-sm text-neutral-400">{{ t('monthly_report.empty_overdue') }}</div>
          <table v-else class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(it, i) in report.receivables_overdue" :key="i">
                <td class="py-1 pr-2 truncate max-w-[40%]">{{ it.partner_name }}</td>
                <td class="py-1 pr-2 text-xs text-neutral-500 whitespace-nowrap">{{ it.days_overdue }} {{ t('monthly_report.days') }}</td>
                <td class="py-1 text-right font-mono whitespace-nowrap">{{ formatMoney(it.remaining_czk, 'CZK') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4">
          <h2 class="text-sm font-semibold mb-2">{{ t('monthly_report.section_payables_overdue') }}</h2>
          <div v-if="!report.payables_overdue.length" class="text-sm text-neutral-400">{{ t('monthly_report.empty_overdue') }}</div>
          <table v-else class="w-full text-sm">
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(it, i) in report.payables_overdue" :key="i">
                <td class="py-1 pr-2 truncate max-w-[40%]">{{ it.partner_name }}</td>
                <td class="py-1 pr-2 text-xs text-neutral-500 whitespace-nowrap">{{ it.days_overdue }} {{ t('monthly_report.days') }}</td>
                <td class="py-1 text-right font-mono whitespace-nowrap">{{ formatMoney(it.remaining_czk, 'CZK') }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </template>

    <!-- Historie odeslání -->
    <section class="mt-8">
      <h2 class="text-lg font-semibold mb-3">{{ t('monthly_report.history_title') }}</h2>
      <div v-if="historyLoading" class="bg-surface border border-neutral-200 rounded-lg shadow-sm p-4 text-sm text-neutral-500">
        {{ t('common.loading') }}
      </div>
      <EmptyState v-else-if="!history.length" boxed accent="neutral" icon="send" :title="t('monthly_report.history_empty')" />
      <div v-else class="bg-surface border border-neutral-200 rounded-lg overflow-hidden shadow-sm">
        <div class="overflow-x-auto">
          <table class="w-full text-sm">
            <thead class="bg-neutral-50 text-neutral-500 text-xs uppercase tracking-wide">
              <tr>
                <th class="text-center px-4 py-2 font-medium">{{ t('monthly_report.col_period') }}</th>
                <th class="text-left px-4 py-2 font-medium">{{ t('monthly_report.col_sent_to') }}</th>
                <th class="text-left px-4 py-2 font-medium">{{ t('monthly_report.col_sent_by') }}</th>
                <th class="text-center px-4 py-2 font-medium">{{ t('monthly_report.col_sent_at') }}</th>
                <th class="text-center px-4 py-2 font-medium">{{ t('monthly_report.col_document') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="item in history" :key="item.id" class="hover:bg-neutral-50 transition">
                <td class="px-4 py-2.5 text-center text-xs font-mono">{{ historyPeriodLabel(item) }}</td>
                <td class="px-4 py-2.5 text-xs text-neutral-600">{{ item.sent_to.join(', ') }}</td>
                <td class="px-4 py-2.5 text-xs text-neutral-600">{{ item.sent_by_name || '—' }}</td>
                <td class="px-4 py-2.5 text-center text-xs">{{ formatDate(item.created_at) }}</td>
                <td class="px-4 py-2.5 text-center">
                  <RouterLink v-if="item.document_id" :to="{ name: 'document-detail', params: { id: item.document_id } }"
                    class="text-xs px-2 py-1 border border-primary-300 rounded hover:bg-primary-50 text-primary-700">
                    {{ t('monthly_report.view_document') }}
                  </RouterLink>
                  <span v-else class="text-neutral-300">—</span>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </section>
  </div>
</template>
