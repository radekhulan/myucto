<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { KhEvidenceReport, KhEvidenceRow, KhEvidenceStatus } from '@/api/reports'
import EmptyState from '@/components/ui/EmptyState.vue'

// Soupis dokladů oddílů KH (issue #142) — sdílené pro aktuální data i podané KH.
const props = defineProps<{ report: KhEvidenceReport }>()
const { t, locale } = useI18n()

const comparison = computed(() => !!props.report.comparison)
const sectionKeys = computed(() => Object.keys(props.report.sections))

function fmtMoney(v: number | null | undefined): string {
  if (v === null || v === undefined) return ''
  return new Intl.NumberFormat(locale.value === 'en' ? 'en-US' : 'cs-CZ', {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(Number(v) || 0)
}

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return ''
  const d = new Date(iso)
  return isNaN(d.getTime()) ? '' : d.toLocaleDateString(locale.value === 'en' ? 'en-US' : 'cs-CZ')
}

function sectionTitle(key: string): string {
  return key === 'none'
    ? t('reports.kh_evidence.outside')
    : `${t('reports.kh_evidence.section_prefix')} ${key}`
}

function statusClass(s: KhEvidenceStatus | undefined): string {
  switch (s) {
    case 'match': return 'bg-success-100 text-success-700'
    case 'amount_diff': return 'bg-warning-100 text-warning-700'
    case 'only_submitted':
    case 'only_current': return 'bg-danger-50 text-danger-500'
    default: return 'bg-neutral-100 text-neutral-600'
  }
}

function rowKey(row: KhEvidenceRow, idx: number): string {
  return `${row.source}:${row.doc_number}:${idx}`
}
</script>

<template>
  <div class="space-y-4">
    <EmptyState v-if="sectionKeys.length === 0" boxed accent="neutral" icon="doc" :title="t('reports.kh_evidence.no_data')" />

    <div v-for="key in sectionKeys" :key="key"
      class="bg-surface border border-neutral-200 rounded-lg shadow-sm overflow-hidden">
      <header class="px-5 py-3 border-b border-neutral-200 bg-neutral-50 flex items-center justify-between gap-2 flex-wrap">
        <h3 class="text-sm font-semibold text-neutral-800">
          <span class="font-mono">{{ sectionTitle(key) }}</span>
          - <span class="text-neutral-600">{{ t(`reports.kh_evidence.section_label.${key === 'none' ? 'none' : key.replace('.', '')}`) }}</span>
        </h3>
        <span class="text-xs text-neutral-500">{{ t('reports.kh_evidence.doc_count', { n: report.sections[key].totals.count }) }}</span>
      </header>

      <p v-if="report.sections[key].rows.length === 0" class="px-5 py-4 text-sm text-neutral-400">
        {{ t('reports.kh_evidence.section_empty') }}
      </p>
      <template v-else>
        <!-- Desktop -->
        <div class="hidden md:block overflow-x-auto">
          <table class="w-full text-xs">
            <thead class="bg-neutral-50 text-neutral-500">
              <tr>
                <th v-if="comparison" class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.kh_evidence.col.status') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.kh_evidence.col.doc_number') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.kh_evidence.col.internal_number') }}</th>
                <th class="px-2 py-2 text-left font-medium">{{ t('reports.kh_evidence.col.partner') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.kh_evidence.col.dic') }}</th>
                <th class="px-2 py-2 text-left font-medium whitespace-nowrap">{{ t('reports.kh_evidence.col.tax_date') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.kh_evidence.col.base21') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.kh_evidence.col.vat21') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.kh_evidence.col.base12') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.kh_evidence.col.vat12') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.kh_evidence.col.base_total') }}</th>
                <th class="px-2 py-2 text-right font-medium whitespace-nowrap">{{ t('reports.kh_evidence.col.vat_total') }}</th>
              </tr>
            </thead>
            <tbody class="divide-y divide-neutral-100">
              <tr v-for="(row, idx) in report.sections[key].rows" :key="rowKey(row, idx)">
                <td v-if="comparison" class="px-2 py-1.5 whitespace-nowrap">
                  <span class="inline-block text-[10px] font-bold px-1.5 py-px rounded" :class="statusClass(row.status)">
                    {{ t(`reports.kh_evidence.status.${row.status}`) }}
                  </span>
                </td>
                <td class="px-2 py-1.5 whitespace-nowrap font-mono">
                  {{ row.doc_number }}
                  <span v-if="row.is_correction" class="text-[10px] text-warning-700">({{ t('reports.kh_evidence.correction') }})</span>
                </td>
                <td class="px-2 py-1.5 whitespace-nowrap font-mono text-neutral-500">{{ row.internal_number }}</td>
                <td class="px-2 py-1.5">{{ row.counterparty_name }}</td>
                <td class="px-2 py-1.5 whitespace-nowrap font-mono">{{ row.counterparty_dic }}</td>
                <td class="px-2 py-1.5 whitespace-nowrap font-mono">{{ fmtDate(row.tax_date) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.base21) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.vat21) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.base12) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.vat12) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.base_total) }}</td>
                <td class="px-2 py-1.5 text-right font-mono whitespace-nowrap">{{ fmtMoney(row.vat_total) }}</td>
              </tr>
            </tbody>
            <tfoot class="bg-neutral-50 font-semibold">
              <tr>
                <td :colspan="comparison ? 6 : 5" class="px-2 py-2 text-xs">{{ t('reports.kh_evidence.total') }}</td>
                <td class="px-2 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(report.sections[key].totals.base21) }}</td>
                <td class="px-2 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(report.sections[key].totals.vat21) }}</td>
                <td class="px-2 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(report.sections[key].totals.base12) }}</td>
                <td class="px-2 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(report.sections[key].totals.vat12) }}</td>
                <td class="px-2 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(report.sections[key].totals.base_total) }}</td>
                <td class="px-2 py-2 text-right font-mono whitespace-nowrap">{{ fmtMoney(report.sections[key].totals.vat_total) }}</td>
              </tr>
            </tfoot>
          </table>
        </div>

        <!-- Mobil -->
        <div class="md:hidden divide-y divide-neutral-100">
          <div v-for="(row, idx) in report.sections[key].rows" :key="rowKey(row, idx)" class="px-4 py-3 text-sm space-y-1">
            <div class="flex items-center justify-between gap-2">
              <span class="font-mono font-medium">{{ row.doc_number }}</span>
              <span v-if="comparison" class="text-[10px] font-bold px-1.5 py-px rounded" :class="statusClass(row.status)">
                {{ t(`reports.kh_evidence.status.${row.status}`) }}
              </span>
            </div>
            <div class="text-neutral-600">{{ row.counterparty_name }} <span class="font-mono text-xs">{{ row.counterparty_dic }}</span></div>
            <div class="text-xs text-neutral-500">{{ t('reports.kh_evidence.col.tax_date') }}: {{ fmtDate(row.tax_date) }}</div>
            <div class="flex justify-between text-xs">
              <span>{{ t('reports.kh_evidence.col.base_total') }}</span>
              <span class="font-mono">{{ fmtMoney(row.base_total) }}</span>
            </div>
            <div class="flex justify-between text-xs">
              <span>{{ t('reports.kh_evidence.col.vat_total') }}</span>
              <span class="font-mono">{{ fmtMoney(row.vat_total) }}</span>
            </div>
          </div>
          <div class="px-4 py-3 bg-neutral-50 text-sm font-semibold space-y-1">
            <div class="flex justify-between"><span>{{ t('reports.kh_evidence.col.base_total') }}</span><span class="font-mono">{{ fmtMoney(report.sections[key].totals.base_total) }}</span></div>
            <div class="flex justify-between"><span>{{ t('reports.kh_evidence.col.vat_total') }}</span><span class="font-mono">{{ fmtMoney(report.sections[key].totals.vat_total) }}</span></div>
          </div>
        </div>

        <p class="px-5 py-2 text-xs text-neutral-500 border-t border-neutral-100">
          {{ t('reports.kh_evidence.whole_crowns', {
            base: fmtMoney(report.sections[key].totals.base_total_whole),
            vat: fmtMoney(report.sections[key].totals.vat_total_whole),
          }) }}
          <template v-if="report.sections[key].totals.rounding_difference !== 0">
            {{ t('reports.kh_evidence.rounding_difference', { amount: fmtMoney(report.sections[key].totals.rounding_difference) }) }}
          </template>
        </p>
      </template>
    </div>

    <div v-if="report.excluded.length > 0" class="bg-warning-50 border border-warning-200 rounded-lg p-4 text-sm">
      <div class="font-semibold text-warning-700 mb-1">{{ t('reports.kh_evidence.excluded_title') }}</div>
      <ul class="list-disc pl-5 text-warning-700 space-y-0.5">
        <li v-for="(row, idx) in report.excluded" :key="idx">
          {{ row.section }}: <span class="font-mono">{{ row.doc_number }}</span> {{ row.counterparty_name }} -
          {{ t(`reports.kh_evidence.excluded_reason.${row.reason}`) }}
        </li>
      </ul>
    </div>

    <div v-if="report.warnings.length > 0" class="bg-neutral-50 border border-neutral-200 rounded-lg p-4 text-xs text-neutral-600 space-y-1">
      <p v-for="(w, idx) in report.warnings" :key="idx">{{ w }}</p>
    </div>
  </div>
</template>
