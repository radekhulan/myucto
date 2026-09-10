<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { MoneyS3Diff, MoneyS3Run } from '@/api/moneyS3'

/**
 * Protokol převodu z Money S3 — důkaz pro účetní: kroky s počty, zprávy, rekonciliace
 * po letech (předvaha proti deníku Money, proti sestavě z Money, doklady proti deníku),
 * uzávěrka historických let, doklady bez zápisu a stav automatiky.
 */
const props = defineProps<{ run: MoneyS3Run }>()
const { t, te, locale } = useI18n()

const protocol = computed(() => props.run.protocol ?? null)
const money = computed(() => new Intl.NumberFormat(locale.value === 'en' ? 'en-GB' : 'cs-CZ', { minimumFractionDigits: 2, maximumFractionDigits: 2 }))
const messages = computed(() => (protocol.value?.steps ?? []).flatMap(step =>
  step.messages.map(message => ({ ...message, step: step.key }))))

function label(prefix: string, key: string): string {
  const full = `money_s3.${prefix}.${key}`
  return te(full) ? t(full) : key
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

function diffRows(diffs: MoneyS3Diff[] | undefined): MoneyS3Diff[] {
  return diffs ?? []
}
</script>

<template>
  <div v-if="protocol" class="space-y-5">
    <div class="flex flex-wrap items-center gap-3">
      <h3 class="text-lg font-semibold">{{ t('money_s3.protocol.title', { id: run.id }) }}</h3>
      <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="statusClass(run.status)">{{ label('status', run.status) }}</span>
      <span class="text-sm text-neutral-500">{{ label('mode', run.mode) }} · {{ run.agenda_name }} · {{ run.created_at }}</span>
    </div>

    <div v-if="protocol.failure" class="rounded-lg border border-danger-500/30 bg-danger-50 px-4 py-3 text-sm text-danger-600">
      {{ t('money_s3.protocol.failure', { step: label('steps', protocol.failure) }) }}
      <span v-if="protocol.error"> — {{ protocol.error }}</span>
    </div>

    <section>
      <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('money_s3.protocol.steps_title') }}</h4>
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
      <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('money_s3.protocol.messages') }}</h4>
      <ul class="space-y-2">
        <li v-for="(m, i) in messages" :key="i" class="rounded-lg border px-3 py-2 text-sm" :class="levelClass(m.level)">
          <span class="font-medium">{{ label('level', m.level) }} · {{ label('steps', m.step) }}:</span> {{ m.text }}
        </li>
      </ul>
    </section>

    <section v-if="protocol.reconciliation?.length">
      <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('money_s3.protocol.reconciliation_title') }}</h4>
      <div class="space-y-3">
        <div v-for="year in protocol.reconciliation" :key="year.year" class="rounded-lg border border-neutral-200 p-4" :data-testid="`reconciliation-${year.year}`">
          <div class="mb-3 flex flex-wrap items-center gap-3">
            <strong>{{ t('money_s3.protocol.year', { year: year.year }) }}</strong>
            <span class="rounded-full px-2.5 py-1 text-xs font-medium" :class="year.ok ? statusClass('ok') : statusClass('error')">{{ year.ok ? t('money_s3.protocol.ok') : t('money_s3.protocol.not_ok') }}</span>
          </div>
          <div class="mb-3 flex flex-wrap gap-2">
            <span v-for="check in year.checks" :key="check.key" class="rounded-full px-2.5 py-1 text-xs font-medium whitespace-nowrap" :class="check.ok ? statusClass('ok') : statusClass('error')">{{ label('checks', check.key) }}</span>
          </div>
          <template v-for="block in [
            { key: 'journal', title: t('money_s3.protocol.diffs_title'), rows: diffRows(year.journal_diffs) },
            { key: 'report', title: t('money_s3.protocol.report_diffs_title'), rows: diffRows(year.money_report?.diffs) },
          ]" :key="block.key">
            <div v-if="block.rows.length" class="mb-3 overflow-x-auto">
              <p class="mb-1 text-sm font-medium">{{ block.title }}</p>
              <table class="min-w-full text-sm">
                <thead class="text-left text-xs text-neutral-500">
                  <tr><th class="px-2 py-1">{{ t('money_s3.protocol.col_account') }}</th><th class="px-2 py-1 text-right">{{ t('money_s3.protocol.col_myucto') }}</th><th class="px-2 py-1 text-right">{{ t('money_s3.protocol.col_money') }}</th></tr>
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
          <div class="overflow-x-auto">
            <p class="mb-1 text-sm font-medium">{{ t('money_s3.protocol.documents_title') }}</p>
            <table class="min-w-full text-sm">
              <thead class="text-left text-xs text-neutral-500">
                <tr><th class="px-2 py-1"></th><th class="px-2 py-1 text-right">{{ t('money_s3.protocol.col_documents') }}</th><th class="px-2 py-1 text-right">{{ t('money_s3.protocol.col_journal') }}</th><th class="px-2 py-1"></th></tr>
              </thead>
              <tbody class="divide-y divide-neutral-100">
                <tr v-for="d in year.documents" :key="d.key">
                  <td class="px-2 py-1">{{ label('checks', `documents_${d.key}`) }}</td>
                  <td class="px-2 py-1 text-right font-mono whitespace-nowrap">{{ money.format(d.documents) }}</td>
                  <td class="px-2 py-1 text-right font-mono whitespace-nowrap">{{ money.format(d.journal) }}</td>
                  <td class="px-2 py-1"><span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="d.ok ? statusClass('ok') : statusClass('error')">{{ d.ok ? t('money_s3.protocol.ok') : t('money_s3.protocol.not_ok') }}</span></td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>
      </div>
    </section>

    <section v-if="protocol.closing?.length">
      <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('money_s3.protocol.closing_title') }}</h4>
      <ul class="space-y-1 text-sm">
        <li v-for="c in protocol.closing" :key="c.year" class="flex flex-wrap items-center gap-2">
          <strong>{{ c.year }}</strong>
          <span class="rounded-full px-2 py-0.5 text-xs font-medium" :class="statusClass(c.status)">{{ label('closing_status', c.status) }}</span>
          <span v-if="c.error" class="text-neutral-500">{{ c.error }}</span>
        </li>
      </ul>
    </section>

    <section v-if="protocol.orphans?.length">
      <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('money_s3.protocol.orphans_title') }}</h4>
      <ul class="space-y-1 text-sm">
        <li v-for="o in protocol.orphans" :key="`${o.type}-${o.id}`">{{ label('doc_type', o.type) }} {{ o.document_no }} ({{ o.year }})</li>
      </ul>
    </section>

    <section v-if="protocol.automation">
      <h4 class="mb-2 text-sm font-semibold uppercase tracking-wide text-neutral-500">{{ t('money_s3.protocol.automation_title') }}</h4>
      <p class="text-sm">{{ t('money_s3.protocol.automation_during', { level: label('automation_level', protocol.automation.during) }) }}</p>
      <p v-if="protocol.automation.restored && protocol.automation.after" class="text-sm">{{ t('money_s3.protocol.automation_after', { level: label('automation_level', protocol.automation.after) }) }}</p>
      <p v-else-if="run.mode === 'import'" class="mt-1 rounded-lg border border-warning-500/30 bg-warning-50 px-3 py-2 text-sm text-warning-700">{{ t('money_s3.protocol.automation_left_off') }}</p>
    </section>
  </div>
</template>
