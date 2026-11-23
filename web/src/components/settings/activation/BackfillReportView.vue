<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { BackfillBankIssue, BackfillDocumentIssue, BackfillReport } from '@/api/activation'
import { formatDate, formatDateTime, formatMoney } from '@/composables/useFormat'

const props = defineProps<{ report: BackfillReport }>()
const { t } = useI18n()

const phaseRows = computed(() => {
  const phases = props.report.phases || {}
  const rows: Array<{ key: string; posted: number; skipped: number; failed: number }> = []
  const documents = phases.documents || {}
  if (documents.invoice) rows.push({ key: 'invoices', posted: Number(documents.invoice.posted || 0) + Number(documents.invoice.updated || 0), skipped: Number(documents.invoice.skipped || 0), failed: Number(documents.invoice.failed || 0) })
  if (documents.purchase_invoice) rows.push({ key: 'purchase_invoices', posted: Number(documents.purchase_invoice.posted || 0) + Number(documents.purchase_invoice.updated || 0), skipped: Number(documents.purchase_invoice.skipped || 0), failed: Number(documents.purchase_invoice.failed || 0) })
  if (phases.cash) rows.push({ key: 'cash', posted: Number(phases.cash.posted || 0), skipped: Number(phases.cash.skipped || 0), failed: Number(phases.cash.failed || 0) })
  if (phases.bank) rows.push({ key: 'bank', posted: Number(phases.bank.posted || 0), skipped: Number(phases.bank.skipped || 0), failed: 0 })
  return rows
})
const fatalMessage = computed(() => {
  if (!props.report.fatal_error) return ''
  const key = `activation.error.${props.report.fatal_error}`
  const translated = t(key)
  return translated === key ? t('activation.error.activation_failed') : translated
})
const documentIssues = computed(() => props.report.document_issues || [])
/** Položky, kvůli kterým aktivace neprošla - uživatel je musí opravit (nebo vědomě vynechat). */
const failedDocuments = computed(() => documentIssues.value.filter(issue => issue.severity === 'failed'))
/** Přeskočené doklady (zamčené období, nic k zaúčtování) - aktivaci neblokují. */
const skippedDocuments = computed(() => documentIssues.value.filter(issue => issue.severity === 'skipped'))
const bankIssues = computed(() => props.report.bank_issues || [])
const settlementErrors = computed<string[]>(() => {
  const errors = props.report.phases?.account_settlements?.errors
  return Array.isArray(errors) ? errors.map(String) : []
})
const coverageRows = computed(() => Object.entries(props.report.document_coverage || {}))
const coverageComplete = computed(() => coverageRows.value.every(([, row]) => row.complete))

function documentLink(issue: BackfillDocumentIssue) {
  if (issue.source_type === 'invoice') return { name: 'invoice-detail', params: { id: issue.source_id } }
  if (issue.source_type === 'purchase_invoice') return { name: 'purchase-invoice-detail', params: { id: issue.source_id } }
  return null
}

function documentNumber(issue: BackfillDocumentIssue): string {
  return issue.document_no || `#${issue.source_id}`
}

function bankLink(issue: BackfillBankIssue) {
  return { name: 'bank-detail', params: { id: issue.statement_id }, query: { tx: String(issue.tx_id), detail: '1' } }
}

function skippedNote(issue: BackfillDocumentIssue): string {
  return issue.had_posting ? t('activation.issue_kept_posting') : t('activation.issue_left_unposted')
}

function reasonKey(code: string): string {
  const prefix = code.split(':')[0]
  const known: Record<string, string> = {
    document_not_postable: 'document_not_postable', advance_payment_only: 'advance_payment_only', date_locked: 'date_locked', period_closed: 'period_closed',
    document_not_posted: 'document_not_posted', allocation_mismatch: 'allocation_mismatch',
    period_not_open: 'period_closed', no_period: 'no_period', no_accounting_period: 'no_period',
    not_matched: 'not_matched', rules_disabled: 'rules_disabled', already_posted: 'already_posted',
    no_rule: 'no_rule', zero_amount: 'zero_amount',
    fx_not_supported: 'fx_not_supported', email_notice_provisional: 'ignored', ignored: 'ignored',
    reconcile_not_posted: 'not_matched', reconcile_error: 'processing_error', transaction_not_found: 'processing_error',
    unknown_supplier: 'unknown_supplier', ambiguous_supplier: 'ambiguous_supplier', not_double_entry: 'processing_error',
    error: 'processing_error',
  }
  return `activation.skip.${known[prefix] || 'other'}`
}
</script>

<template>
  <div class="space-y-4">
    <div v-if="fatalMessage" class="rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-600">{{ fatalMessage }}</div>
    <div v-if="report.forced" data-test="forced-banner" class="rounded-lg border border-warning-500/30 bg-warning-50 px-4 py-3 text-sm text-warning-700">
      {{ t('activation.forced_banner', { at: formatDateTime(report.forced.at), n: report.forced.failed_total }) }}
      <span class="mt-1 block">{{ t('activation.forced_reason', { reason: report.forced.reason }) }}</span>
    </div>
    <div class="overflow-x-auto rounded-lg border border-neutral-200">
      <table class="w-full text-sm"><thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500"><tr><th class="px-3 py-2">{{ t('activation.report_source') }}</th><th class="px-3 py-2 text-right">{{ t('activation.report_ready') }}</th><th class="px-3 py-2 text-right">{{ t('activation.report_skipped') }}</th><th class="px-3 py-2 text-right">{{ t('activation.report_failed') }}</th></tr></thead><tbody class="divide-y divide-neutral-100"><tr v-for="row in phaseRows" :key="row.key"><td class="px-3 py-2">{{ t(`activation.source.${row.key}`) }}</td><td class="px-3 py-2 text-right font-mono">{{ row.posted }}</td><td class="px-3 py-2 text-right font-mono">{{ row.skipped }}</td><td class="px-3 py-2 text-right font-mono" :class="row.failed ? 'text-danger-600' : ''">{{ row.failed }}</td></tr></tbody></table>
    </div>

    <div v-if="failedDocuments.length || settlementErrors.length" data-test="failed-items" class="overflow-hidden rounded-lg border border-danger-500/30">
      <h3 class="border-b border-danger-500/30 bg-danger-50 px-4 py-3 font-semibold text-danger-700">{{ t('activation.failed_items_title', { n: failedDocuments.length + settlementErrors.length }) }}</h3>
      <div v-if="failedDocuments.length" class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500"><tr><th class="px-3 py-2">{{ t('activation.document_issue_document') }}</th><th class="px-3 py-2">{{ t('activation.document_issue_date') }}</th><th class="px-3 py-2">{{ t('activation.document_issue_reason') }}</th></tr></thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="issue in failedDocuments" :key="`${issue.source_type}-${issue.source_id}-${issue.error_code}`">
              <td class="px-3 py-2 whitespace-nowrap">
                <RouterLink v-if="documentLink(issue)" :to="documentLink(issue)!" class="font-medium text-primary-600 hover:underline">{{ t(`activation.document_type.${issue.source_type}`) }} {{ documentNumber(issue) }}</RouterLink>
                <span v-else class="font-medium">{{ t(`activation.document_type.${issue.source_type}`) }} {{ documentNumber(issue) }}</span>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">{{ issue.entry_date ? formatDate(issue.entry_date) : '—' }}</td>
              <td class="px-3 py-2">{{ issue.message }}</td>
            </tr>
          </tbody>
        </table>
      </div>
      <ul v-if="settlementErrors.length" class="space-y-1 px-4 py-3 text-sm text-danger-700">
        <li v-for="(message, index) in settlementErrors" :key="index">{{ message }}</li>
      </ul>
    </div>

    <div v-if="bankIssues.length" data-test="bank-items" class="overflow-hidden rounded-lg border border-warning-500/30">
      <h3 class="border-b border-warning-500/30 bg-warning-50 px-4 py-3 font-semibold text-warning-700">{{ t('activation.bank_items_title', { n: bankIssues.length }) }}</h3>
      <p class="px-4 pt-3 text-sm text-neutral-500">{{ t('activation.bank_items_hint') }}</p>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500"><tr><th class="px-3 py-2">{{ t('activation.bank_item_transaction') }}</th><th class="px-3 py-2 text-right">{{ t('activation.amount') }}</th><th class="px-3 py-2">{{ t('activation.bank_item_counterparty') }}</th><th class="px-3 py-2">{{ t('activation.document_issue_reason') }}</th></tr></thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="issue in bankIssues" :key="`${issue.tx_id}-${issue.reason}`">
              <td class="px-3 py-2 whitespace-nowrap"><RouterLink :to="bankLink(issue)" class="font-medium text-primary-600 hover:underline">{{ formatDate(issue.posted_at) }}</RouterLink><span v-if="issue.variable_symbol" class="ml-2 text-xs text-neutral-500">{{ t('activation.bank_item_vs', { vs: issue.variable_symbol }) }}</span></td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ formatMoney(issue.amount, issue.currency || 'CZK') }}</td>
              <td class="px-3 py-2">{{ issue.counterparty_name || '—' }}</td>
              <td class="px-3 py-2">{{ t(reasonKey(issue.reason)) }}<span v-if="issue.message" class="mt-0.5 block text-xs text-neutral-500">{{ issue.message }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <details v-if="skippedDocuments.length" data-test="skipped-items" class="overflow-hidden rounded-lg border border-warning-500/30" :open="skippedDocuments.length <= 10">
      <summary class="cursor-pointer border-b border-warning-500/30 bg-warning-50 px-4 py-3 font-semibold text-warning-700">{{ t('activation.skipped_items_title', { n: skippedDocuments.length }) }}</summary>
      <p class="px-4 pt-3 text-sm text-neutral-500">{{ t('activation.skipped_items_hint') }}</p>
      <div class="overflow-x-auto">
        <table class="w-full text-sm">
          <thead class="bg-neutral-50 text-left text-xs uppercase text-neutral-500"><tr><th class="px-3 py-2">{{ t('activation.document_issue_document') }}</th><th class="px-3 py-2">{{ t('activation.document_issue_date') }}</th><th class="px-3 py-2">{{ t('activation.document_issue_result') }}</th><th class="px-3 py-2">{{ t('activation.document_issue_reason') }}</th></tr></thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="issue in skippedDocuments" :key="`${issue.source_type}-${issue.source_id}-${issue.error_code}`">
              <td class="px-3 py-2 whitespace-nowrap">
                <RouterLink v-if="documentLink(issue)" :to="documentLink(issue)!" class="font-medium text-primary-600 hover:underline">{{ t(`activation.document_type.${issue.source_type}`) }} {{ documentNumber(issue) }}</RouterLink>
                <span v-else class="font-medium">{{ t(`activation.document_type.${issue.source_type}`) }} {{ documentNumber(issue) }}</span>
              </td>
              <td class="px-3 py-2 whitespace-nowrap">{{ issue.entry_date ? formatDate(issue.entry_date) : '—' }}</td>
              <td class="px-3 py-2"><span class="rounded-full px-2 py-1 text-xs font-medium whitespace-nowrap" :class="issue.had_posting ? 'bg-neutral-100 text-neutral-600' : 'bg-warning-50 text-warning-700'">{{ skippedNote(issue) }}</span></td>
              <td class="px-3 py-2">{{ t(reasonKey(issue.error_code)) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    </details>

    <div v-if="Object.keys(report.skip_reasons || {}).length" class="rounded-lg border border-neutral-200 p-4"><h3 class="mb-2 font-semibold">{{ t('activation.skipped_title') }}</h3><ul class="space-y-1 text-sm text-neutral-600"><li v-for="(count, reason) in report.skip_reasons" :key="reason">{{ count }}× {{ t(reasonKey(String(reason))) }}</li></ul></div>
    <div v-if="report.document_coverage" data-test="coverage" class="rounded-lg border p-4" :class="coverageComplete ? 'border-success-500/30 bg-success-50' : 'border-danger-500/30 bg-danger-50'">
      <h3 class="font-semibold">{{ t('activation.coverage_title') }}</h3>
      <p class="mt-1 text-sm">{{ coverageComplete ? t('activation.coverage_pass') : t('activation.coverage_fail') }}</p>
      <ul class="mt-2 space-y-1 text-sm">
        <li v-for="[key, row] in coverageRows" :key="key">
          {{ t(`activation.source.${key === 'invoice' ? 'invoices' : 'purchase_invoices'}`) }}:
          {{ t('activation.coverage_row', { expected: row.expected, handled: row.handled, missing: row.missing, unexpected: row.unexpected }) }}<template v-if="row.already_posted">{{ t('activation.coverage_already_posted', { n: row.already_posted }) }}</template>
        </li>
      </ul>
    </div>
    <div class="flex flex-wrap gap-3 text-sm"><span :class="report.failed_total === 0 ? 'text-success-600' : 'text-danger-600'">{{ report.failed_total === 0 ? t('activation.report_no_errors') : t('activation.report_has_errors', { n: report.failed_total }) }}</span><span :class="report.balance?.balanced ? 'text-success-600' : 'text-danger-600'">{{ report.balance?.balanced ? t('activation.balance_pass') : t('activation.balance_fail') }}</span></div>
  </div>
</template>
