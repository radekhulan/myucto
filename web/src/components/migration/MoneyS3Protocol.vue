<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MoneyS3Diff, MoneyS3PayrollMonth, MoneyS3Run, MoneyS3TaxRecordsYear } from '@/api/moneyS3'
import type { PohodaRun } from '@/api/pohoda'
import type { PremierRun } from '@/api/premier'
import type { StereoProtocolRun } from '@/api/stereoNx'

/**
 * Protokol převodu agendy (Money S3, POHODA, PREMIER) — důkaz pro účetní: kroky s počty,
 * zprávy, rekonciliace po letech (předvaha proti deníku zdrojového programu, proti
 * sestavě, doklady proti deníku), uzávěrka historických let, doklady bez zápisu a stav
 * automatiky. Texty se berou z jmenného prostoru `prefix` (`money_s3`, `pohoda`, `premier`).
 */
const props = withDefaults(defineProps<{ run: MoneyS3Run | PohodaRun | PremierRun | StereoProtocolRun; prefix?: string }>(), { prefix: 'money_s3' })
const { t, te, locale } = useI18n()

interface ReconciliationView {
  year: number
  ok: boolean
  checks: { key: string; ok: boolean }[]
  journalDiffs: MoneyS3Diff[]
  reportDiffs: MoneyS3Diff[]
  documents: { key: string; documents: number; journal: number; ok: boolean; other: string; source: string }[]
  unmapped: string
}

const protocol = computed(() => props.run.protocol ?? null)
const money = computed(() => new Intl.NumberFormat(locale.value === 'en' ? 'en-GB' : 'cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))
const messages = computed(() => (protocol.value?.steps ?? []).flatMap(step =>
  step.messages.map(message => ({ ...message, step: step.key }))))
const agendaLabel = computed(() => {
  const run = props.run
  if ('agenda_name' in run) return run.agenda_name ?? ''
  return [run.agenda_ico, run.agenda_year].filter(v => v !== null && v !== '').join(' · ')
})
const reconciliation = computed<ReconciliationView[]>(() => (protocol.value?.reconciliation ?? []).map(year => ({
  year: year.year,
  ok: year.ok,
  checks: year.checks,
  journalDiffs: year.journal_diffs ?? [],
  reportDiffs: 'money_report' in year ? year.money_report?.diffs ?? [] : [],
  documents: year.documents.map(d => ({
    ...d,
    other: 'other_accounts' in d ? listed(d.other_accounts) : '',
    source: ('source_differences' in d ? d.source_differences ?? [] : []).map(s => `${s.document_no} (${money.value.format(s.difference)})`).join(', '),
  })),
  unmapped: 'unmapped_accounts' in year ? listed(year.unmapped_accounts) : '',
})))
const closing = computed(() => {
  const data = protocol.value
  return data && 'closing' in data ? data.closing ?? [] : []
})
const orphans = computed<{ type: string; document_no: string; id: number; year?: number }[]>(() => protocol.value?.orphans ?? [])
/** Rekonciliace převedené daňové evidence (Money S3): peněžní deník MyÚčta proti Money po letech. */
const taxRecords = computed<MoneyS3TaxRecordsYear[]>(() => {
  const data = protocol.value
  return data && 'tax_records_reconciliation' in data ? data.tax_records_reconciliation ?? [] : []
})
const payrollTotals = computed<MoneyS3PayrollMonth[]>(() => {
  const data = protocol.value
  return data && 'payroll_totals' in data ? data.payroll_totals ?? [] : []
})

function pair(a: number, b: number): string {
  return `${money.value.format(a)} / ${money.value.format(b)}`
}

/** Seznam účtů (`{account, name}` nebo text) či počet z backendu jako krátký text; prázdný, když není co ukázat. */
function listed(value: unknown): string {
  if (Array.isArray(value)) {
    return value.map(item => {
      if (!item || typeof item !== 'object') return String(item ?? '')
      const { account, name } = item as { account?: unknown; name?: unknown }
      return name ? `${account ?? ''} ${name}`.trim() : String(account ?? '')
    }).filter(Boolean).join(', ')
  }
  if (typeof value === 'number') return value > 0 ? String(value) : ''
  return typeof value === 'string' ? value : ''
}

function k(key: string): string {
  return `${props.prefix}.${key}`
}

function label(group: string, key: string): string {
  const full = k(`${group}.${key}`)
  if (te(full)) return t(full)
  // Čítače sdílené vrstvy převzatých mezd (PayrollTakeover*) hlásí převod
  // PAMICA/POHODA i PREMIER stejnými klíči. Popisky k nim vede prostor
  // `premier` — bez tohoto záložního kroku protokol PAMICA ukazoval surové
  // klíče typu `leave_existing`.
  const shared = `premier.${group}.${key}`
  return te(shared) ? t(shared) : key
}

function triple(v: [number, number, number]): string {
  return v.map(n => money.value.format(n)).join(' / ')
}

function statusClass(status: string): string {
  if (status === 'ok' || status === 'completed' || status === 'closed') return 'bg-success-50 text-success-600'
  if (status === 'warning' || status === 'completed_with_warnings' || status === 'verified' || status === 'open' || status === 'already_closed') return 'bg-warning-50 text-warning-700'
  if (status === 'error' || status === 'failed' || status === 'mismatch') return 'bg-danger-50 text-danger-600'
  return 'bg-neutral-100 text-neutral-600'
}

function levelClass(level: string): string {
  if (level === 'error') return 'border-danger-500/30 bg-danger-50 text-danger-600'
  if (level === 'warning') return 'border-warning-500/30 bg-warning-50 text-warning-700'
  return 'border-primary-500/30 bg-primary-50 text-primary-700'
}
</script>

<template>
  <div v-if="protocol" class="space-y-5">
    <div class="flex flex-wrap items-center gap-3">
      <h3 class="text-lg font-semibold">{{ run.id === null ? t(k('protocol.title')) : t(k('protocol.title'), { id: run.id }) }}</h3>
      <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(run.status)">{{ label('status', run.status) }}</span>
      <span class="text-sm text-neutral-500">{{ [label('mode', run.mode), agendaLabel, run.created_at].filter(Boolean).join(' · ') }}</span>
    </div>

    <div v-if="protocol.failure" class="rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-600">
      {{ t(k('protocol.failure'), { step: label('steps', protocol.failure) }) }}
      <span v-if="protocol.error"> - {{ protocol.error }}</span>
    </div>

    <section>
      <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t(k('protocol.steps_title')) }}</h4>
      <div class="overflow-x-auto rounded-lg border border-neutral-200">
        <table class="min-w-full text-sm">
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="step in protocol.steps" :key="step.key">
              <td class="px-3 py-2 font-medium whitespace-nowrap">{{ label('steps', step.key) }}</td>
              <td class="px-3 py-2"><span class="rounded-full px-2 py-0.5 text-xs font-medium whitespace-nowrap" :class="statusClass(step.status)">{{ label('step_status', step.status) }}</span></td>
              <td class="px-3 py-2 text-neutral-600">
                <span v-for="(value, key) in step.counts" :key="key" class="mr-3 inline-block whitespace-nowrap">{{ label('counts', String(key)) }}: <strong>{{ value }}</strong></span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="messages.length">
      <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t(k('protocol.messages')) }}</h4>
      <ul class="space-y-2">
        <li v-for="(m, i) in messages" :key="i" class="rounded-lg border px-3 py-2 text-sm whitespace-pre-line" :class="levelClass(m.level)">
          <span class="font-medium">{{ label('level', m.acceptable ? 'difference' : m.level) }} · {{ label('steps', m.step) }}:</span> {{ m.text }}
        </li>
      </ul>
    </section>

    <section v-if="taxRecords.length" data-testid="tax-records-reconciliation">
      <h4 class="mb-1 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t(k('protocol.tax_records_title')) }}</h4>
      <p class="mb-2 text-sm text-neutral-500">{{ t(k('protocol.tax_records_hint')) }}</p>
      <div class="overflow-x-auto rounded-lg border border-neutral-200">
        <table class="min-w-full text-sm">
          <thead class="bg-neutral-50 text-left text-xs text-neutral-500">
            <tr>
              <th class="px-3 py-2">{{ t(k('col_year')) }}</th>
              <th class="px-3 py-2 text-right">{{ t(k('protocol.col_tax_income')) }}</th>
              <th class="px-3 py-2 text-right">{{ t(k('protocol.col_tax_expense')) }}</th>
              <th class="px-3 py-2 text-right">{{ t(k('protocol.col_unclassified')) }}</th>
              <th class="px-3 py-2"></th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="year in taxRecords" :key="year.year" :data-testid="`tax-records-${year.year}`">
              <td class="px-3 py-2 font-medium">{{ year.year }}</td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ pair(year.myucto.income_taxable, year.money.income_taxable) }}</td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ pair(year.myucto.expense_taxable, year.money.expense_taxable) }}</td>
              <td class="px-3 py-2 text-right font-mono whitespace-nowrap">{{ money.format(year.myucto.unclassified) }}</td>
              <td class="px-3 py-2"><span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="year.ok ? statusClass('ok') : statusClass('error')">{{ year.ok ? t(k('protocol.ok')) : t(k('protocol.not_ok')) }}</span></td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="reconciliation.length">
      <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t(k('protocol.reconciliation_title')) }}</h4>
      <div class="space-y-3">
        <div v-for="year in reconciliation" :key="year.year" class="rounded-lg border border-neutral-200 p-4" :data-testid="`reconciliation-${year.year}`">
          <div class="mb-3 flex flex-wrap items-center gap-3">
            <strong>{{ t(k('protocol.year'), { year: year.year }) }}</strong>
            <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="year.ok ? statusClass('ok') : statusClass('error')">{{ year.ok ? t(k('protocol.ok')) : t(k('protocol.not_ok')) }}</span>
          </div>
          <div class="mb-3 flex flex-wrap gap-2">
            <span v-for="check in year.checks" :key="check.key" class="rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap" :class="check.ok ? statusClass('ok') : statusClass('error')">{{ label('checks', check.key) }}</span>
          </div>
          <template v-for="block in [
            { key: 'journal', title: t(k('protocol.diffs_title')), rows: year.journalDiffs },
            { key: 'report', title: te(k('protocol.report_diffs_title')) ? t(k('protocol.report_diffs_title')) : '', rows: year.reportDiffs },
          ]" :key="block.key">
            <div v-if="block.rows.length" class="mb-3 overflow-x-auto">
              <p class="mb-1 text-sm font-medium">{{ block.title }}</p>
              <table class="min-w-full text-sm">
                <thead class="text-left text-xs text-neutral-500">
                  <tr><th class="px-2 py-1">{{ t(k('protocol.col_account')) }}</th><th class="px-2 py-1 text-right">{{ t(k('protocol.col_myucto')) }}</th><th class="px-2 py-1 text-right">{{ t(k('protocol.col_money')) }}</th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-100">
                  <tr v-for="row in block.rows" :key="row.account">
                    <td class="px-2 py-1 font-mono">{{ row.account }}</td>
                    <td class="px-2 py-1 text-right font-mono whitespace-nowrap">{{ triple(row.myucto) }}</td>
                    <td class="px-2 py-1 text-right font-mono whitespace-nowrap">{{ triple(row.money) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </template>
          <p v-if="year.unmapped" class="mb-3 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700" data-testid="unmapped-accounts">
            {{ t(k('protocol.unmapped_accounts'), { accounts: year.unmapped }) }}
          </p>
          <div class="overflow-x-auto">
            <p class="mb-1 text-sm font-medium">{{ t(k('protocol.documents_title')) }}</p>
            <table class="min-w-full text-sm">
              <thead class="text-left text-xs text-neutral-500">
                <tr><th class="px-2 py-1"></th><th class="px-2 py-1 text-right">{{ t(k('protocol.col_documents')) }}</th><th class="px-2 py-1 text-right">{{ t(k('protocol.col_journal')) }}</th><th class="px-2 py-1"></th></tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="d in year.documents" :key="d.key">
                  <td class="px-2 py-1">{{ label('checks', `documents_${d.key}`) }}</td>
                  <td class="px-2 py-1 text-right font-mono whitespace-nowrap">{{ money.format(d.documents) }}</td>
                  <td class="px-2 py-1 text-right font-mono whitespace-nowrap">{{ money.format(d.journal) }}</td>
                  <td class="px-2 py-1">
                    <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="d.ok ? statusClass('ok') : statusClass('error')">{{ d.ok ? t(k('protocol.ok')) : t(k('protocol.not_ok')) }}</span>
                    <span v-if="d.other" class="ml-2 text-xs text-neutral-500">{{ t(k('protocol.other_accounts'), { accounts: d.other }) }}</span>
                    <p v-if="d.source && te(k('protocol.source_differences'))" class="mt-1 text-xs text-neutral-500" data-testid="source-differences">{{ t(k('protocol.source_differences'), { documents: d.source }) }}</p>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>

    <section v-if="closing.length">
      <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t(k('protocol.closing_title')) }}</h4>
      <ul class="space-y-1 text-sm">
        <li v-for="c in closing" :key="c.year" class="flex flex-wrap items-center gap-2">
          <strong>{{ c.year }}</strong>
          <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(c.status)">{{ label('closing_status', c.status) }}</span>
          <span v-if="c.error" class="text-neutral-500">{{ c.error }}</span>
        </li>
      </ul>
    </section>

    <section v-if="orphans.length">
      <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t(k('protocol.orphans_title')) }}</h4>
      <ul class="space-y-1 text-sm">
        <li v-for="o in orphans" :key="`${o.type}-${o.id}`">{{ label('doc_type', o.type) }} {{ o.document_no }}<template v-if="o.year"> ({{ o.year }})</template></li>
      </ul>
    </section>

    <section v-if="payrollTotals.length" data-testid="payroll-totals">
      <h4 class="mb-1 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t(k('protocol.payroll_totals_title')) }}</h4>
      <p class="mb-2 text-sm text-neutral-500">{{ t(k('protocol.payroll_totals_hint')) }}</p>
      <div class="overflow-x-auto rounded-lg border border-neutral-200">
        <table class="min-w-full text-sm">
          <thead class="text-left text-xs text-neutral-500">
            <tr>
              <th class="px-2 py-1">{{ t(k('protocol.col_period')) }}</th>
              <th class="px-2 py-1 text-right">{{ t(k('protocol.col_gross')) }}</th>
              <th class="px-2 py-1 text-right">{{ t(k('protocol.col_employee_insurance')) }}</th>
              <th class="px-2 py-1 text-right">{{ t(k('protocol.col_employer_insurance')) }}</th>
              <th class="px-2 py-1 text-right">{{ t(k('protocol.col_tax')) }}</th>
              <th class="px-2 py-1 text-right">{{ t(k('protocol.col_deductions')) }}</th>
              <th class="px-2 py-1 text-right">{{ t(k('protocol.col_net_payable')) }}</th>
              <th class="px-2 py-1 text-right">{{ t(k('protocol.col_dpfo')) }}</th>
            </tr>
          </thead>
          <tbody class="divide-y divide-neutral-100">
            <tr v-for="m in payrollTotals" :key="m.period">
              <td class="px-2 py-1 font-mono whitespace-nowrap">{{ m.period }}</td>
              <td class="px-2 py-1 text-right font-mono whitespace-nowrap">{{ money.format(m.gross) }}</td>
              <td class="px-2 py-1 text-right font-mono whitespace-nowrap">{{ pair(m.employee_social, m.employee_health) }}</td>
              <td class="px-2 py-1 text-right font-mono whitespace-nowrap">{{ pair(m.employer_social, m.employer_health) }}</td>
              <td class="px-2 py-1 text-right font-mono whitespace-nowrap">{{ pair(m.advance_tax, m.withholding_tax) }}</td>
              <td class="px-2 py-1 text-right font-mono whitespace-nowrap">{{ money.format(m.deductions) }}</td>
              <td class="px-2 py-1 text-right font-mono whitespace-nowrap">{{ money.format(m.net_payable) }}</td>
              <td class="px-2 py-1 text-right whitespace-nowrap">
                <span v-if="m.dpfo_net === null" class="text-xs text-neutral-500">{{ t(k('protocol.tax_missing')) }}</span>
                <span v-else class="rounded-full px-2 py-0.5 font-mono text-xs font-medium" :class="m.tax_ok ? statusClass('ok') : statusClass('error')">{{ money.format(m.dpfo_net) }}</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section v-if="protocol.automation">
      <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t(k('protocol.automation_title')) }}</h4>
      <p class="text-sm">{{ t(k('protocol.automation_during'), { level: label('automation_level', protocol.automation.during) }) }}</p>
      <p v-if="protocol.automation.restored && protocol.automation.after" class="text-sm">{{ t(k('protocol.automation_after'), { level: label('automation_level', protocol.automation.after) }) }}</p>
      <p v-else-if="run.mode === 'import'" class="mt-1 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700">{{ t(k('protocol.automation_left_off')) }}</p>
    </section>
  </div>
</template>
