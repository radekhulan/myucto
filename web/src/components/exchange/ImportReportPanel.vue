<script setup lang="ts">
/**
 * Report importu — sdílený vystavenými i přijatými fakturami.
 *
 * Backend posílá u každého dokladu `notes` a `warnings` a k celému běhu souhrn.
 * Bez téhle obrazovky uvidí uživatel u 850 dokladů 850× zelené „vytvořeno" a ani
 * jedno varování, protože nejzrádnější případ je doklad, který se VYTVOŘIL a
 * přesto v něm něco k ověření je (OSS řádek bez typu sazby, dobropis bez
 * původního období, dosazený variabilní symbol).
 */
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ImportReport, ImportResultRow } from '@/api/imports'
import { btnFilledSm, btnOutlineSm } from '@/components/ui/buttonStyles'

const props = defineProps<{ report: ImportReport }>()
const { t } = useI18n()

const rows = computed<ImportResultRow[]>(() => props.report.results ?? [])

const statusBadge = (s: string) => {
  if (s === 'created') return 'bg-success-50 text-success-600 border-success-500/40'
  if (s === 'skipped') return 'bg-warning-50 text-warning-600 border-warning-500/40'
  return 'bg-danger-50 text-danger-500 border-danger-500/40'
}

function warningsOf(r: ImportResultRow): string[] {
  return r.warnings ?? []
}
function notesOf(r: ImportResultRow): string[] {
  return r.notes ?? []
}
function needsAttention(r: ImportResultRow): boolean {
  return r.status !== 'created' || warningsOf(r).length > 0
}
function hasDetail(r: ImportResultRow): boolean {
  return warningsOf(r).length > 0 || notesOf(r).length > 0
}

const problemRows = computed(() => rows.value.filter(needsAttention))

/**
 * Filtr se přepne na problémové sám. Seznam všeho je u velkého importu k ničemu
 * — pár řádků k ověření v něm zapadne mezi stovkami zelených.
 */
const onlyProblems = ref(false)
watch(rows, () => { onlyProblems.value = problemRows.value.length > 0 }, { immediate: true })

const visibleRows = computed(() => (onlyProblems.value ? problemRows.value : rows.value))

/** Čítač → popisek. Nula se nezobrazuje, ať souhrn nešumí prázdnými řádky. */
const COUNTERS: Array<{ key: keyof ImportReport['summary']; label: string; actionable: boolean }> = [
  { key: 'oss_items',                       label: 'imports.sum_oss_items',                 actionable: false },
  { key: 'oss_rate_type_unknown',           label: 'imports.sum_oss_rate_type_unknown',     actionable: true  },
  { key: 'oss_manual_review',               label: 'imports.sum_oss_manual_review',         actionable: true  },
  { key: 'oss_credit_notes_pending_period', label: 'imports.sum_oss_credit_notes_period',   actionable: true  },
  { key: 'varsymbol_substituted',           label: 'imports.sum_varsymbol_substituted',     actionable: true  },
  { key: 'with_warnings',                   label: 'imports.sum_with_warnings',             actionable: true  },
]

const counters = computed(() =>
  COUNTERS
    .map(c => ({ ...c, count: Number(props.report.summary[c.key] ?? 0) }))
    .filter(c => c.count > 0),
)
const hasActionable = computed(() => counters.value.some(c => c.actionable))

/** Číslo ze zdrojového systému ukazujeme jen tam, kde se liší od var. symbolu. */
function foreignDocNumber(r: ImportResultRow): string | null {
  const n = (r.document_number ?? '').trim()
  return n !== '' && n !== (r.varsymbol ?? '') ? n : null
}
</script>

<template>
  <div class="mt-6 bg-surface border border-neutral-200 rounded-lg p-5 shadow-sm max-w-3xl">
    <!--
      Zastavený běh vypadá bez tohohle boxu jako hotový, jen s menšími čísly — a to je
      právě ten případ, kdy uživatel potřebuje vědět, že zbytek dávky nikdo nezpracoval.
    -->
    <div v-if="report.cancelled" class="mb-4 rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2 text-sm text-warning-700">
      <strong>{{ t('imports.job_cancelled_title') }}:</strong>
      {{ t('imports.job_cancelled_hint', { n: report.not_processed ?? 0 }) }}
    </div>

    <div class="flex flex-wrap items-center gap-4 mb-4 text-sm">
      <div><span class="font-semibold text-success-600">{{ report.summary.created }}</span> {{ t('imports.summary_created') }}</div>
      <div v-if="report.summary.duplicates"><span class="font-semibold text-neutral-600">{{ report.summary.duplicates }}</span> {{ t('imports.summary_duplicates') }}</div>
      <div><span class="font-semibold text-warning-600">{{ report.summary.skipped }}</span> {{ t('imports.summary_skipped') }}</div>
      <div><span class="font-semibold text-danger-500">{{ report.summary.failed }}</span> {{ t('imports.summary_failed') }}</div>
    </div>

    <!-- Souhrn za běh — hlavní věc, kterou si má uživatel odnést -->
    <div
      v-if="counters.length > 0"
      class="rounded-md border px-3 py-3 mb-4"
      :class="hasActionable
        ? 'bg-warning-50 border-warning-500/40'
        : 'bg-primary-50 border-primary-200'"
    >
      <div class="text-sm font-semibold mb-2" :class="hasActionable ? 'text-warning-700' : 'text-primary-700'">
        {{ hasActionable ? '⚠ ' + t('imports.attention_title') : t('imports.run_summary_title') }}
      </div>
      <ul class="grid gap-1 sm:grid-cols-2 text-sm" :class="hasActionable ? 'text-warning-700' : 'text-primary-700'">
        <li v-for="c in counters" :key="String(c.key)">
          <span class="font-semibold tabular-nums">{{ c.count }}</span>
          {{ t(c.label) }}
        </li>
      </ul>
    </div>

    <!-- Filtr — u velkého importu jediná cesta, jak se k problémovým dokladům dostat -->
    <div v-if="problemRows.length > 0" class="flex flex-wrap items-center gap-2 mb-3">
      <button
        type="button"
        @click="onlyProblems = true"
        :class="onlyProblems ? btnFilledSm('warning') : btnOutlineSm('warning')"
      >
        {{ t('imports.filter_problems', { n: problemRows.length }) }}
      </button>
      <button
        type="button"
        @click="onlyProblems = false"
        :class="!onlyProblems ? btnFilledSm('neutral') : btnOutlineSm('neutral')"
      >
        {{ t('imports.filter_all', { n: rows.length }) }}
      </button>
    </div>

    <div class="overflow-x-auto">
      <table class="w-full text-sm table-sticky-first">
        <thead>
          <tr class="text-left text-xs uppercase tracking-wide text-neutral-500 border-b border-neutral-200">
            <th class="py-2 pr-3">{{ t('imports.col_file') }}</th>
            <th class="py-2 pr-3">{{ t('imports.col_status') }}</th>
            <th class="py-2 pr-3">{{ t('imports.col_varsymbol') }}</th>
            <th class="py-2">{{ t('imports.col_detail') }}</th>
          </tr>
        </thead>
        <tbody>
          <template v-for="(r, i) in visibleRows" :key="r.file + '#' + i">
            <tr :class="[
              warningsOf(r).length > 0 ? 'bg-warning-50/40' : '',
              hasDetail(r) ? '' : 'border-b border-neutral-100',
            ]">
              <td class="py-2 pr-3 font-mono text-xs truncate max-w-xs">{{ r.file }}</td>
              <td class="py-2 pr-3 whitespace-nowrap">
                <!-- Duplicita má vlastní štítek: stav zůstává 'created' (doklad
                     v systému je), ale zelené „vytvořeno" u dokladu, který už
                     existoval, si protiřečí s vlastním popiskem řádku. -->
                <span class="inline-block px-2 py-0.5 text-xs rounded border"
                  :class="r.duplicate ? 'bg-neutral-100 text-neutral-600 border-neutral-300' : statusBadge(r.status)">
                  {{ r.duplicate ? t('imports.status_duplicate') : t('imports.status_' + r.status) }}
                </span>
                <span
                  v-if="warningsOf(r).length > 0"
                  class="ml-1 inline-block px-1.5 py-0.5 text-xs rounded border bg-warning-50 text-warning-700 border-warning-500/40"
                  :title="t('imports.warnings_label')"
                >
                  ⚠ {{ warningsOf(r).length }}
                </span>
              </td>
              <td class="py-2 pr-3 font-mono">
                {{ r.varsymbol || '—' }}
                <div v-if="foreignDocNumber(r)" class="text-[11px] text-neutral-400">
                  {{ t('imports.doc_number', { n: foreignDocNumber(r) }) }}
                </div>
              </td>
              <td class="py-2 text-neutral-600">
                <span v-if="r.status === 'created'">
                  <!-- Vystavená faktura -->
                  <a v-if="r.invoice_id" :href="`/invoices/${r.invoice_id}`" class="text-primary-700 hover:underline">#{{ r.invoice_id }}</a>
                  <!-- Přijatá faktura (purchase route) -->
                  <a v-if="r.purchase_invoice_id" :href="`/purchase-invoices/${r.purchase_invoice_id}`" class="text-primary-700 hover:underline">
                    #{{ r.purchase_invoice_id }}
                  </a>
                  <!-- Kind badge — vystavená vs přijatá -->
                  <span
                    v-if="r.kind"
                    class="ml-2 text-xs px-1.5 py-0.5 rounded border"
                    :class="r.kind === 'purchase'
                      ? 'bg-warning-50 text-warning-600 border-warning-500/40'
                      : 'bg-primary-50 text-primary-700 border-primary-500/40'"
                  >
                    {{ t('imports.kind_' + r.kind) }}
                  </span>
                  <span
                    v-if="r.imported_status"
                    class="ml-2 text-xs px-1.5 py-0.5 rounded border"
                    :class="r.imported_status === 'paid' ? 'bg-success-50 text-success-600 border-success-500/40' : 'bg-neutral-50 text-neutral-600 border-neutral-200'"
                  >
                    {{ t('imports.imported_as_' + r.imported_status) }}
                  </span>
                  <span v-if="r.client_created" class="ml-2 text-xs text-success-600">{{ t('imports.new_client') }}</span>
                  <span v-if="r.project_id" class="ml-2 text-xs text-primary-700">{{ t('imports.new_project') }}</span>
                  <span v-if="(r.oss_items ?? 0) > 0" class="ml-2 text-xs px-1.5 py-0.5 rounded border bg-accent-50 text-accent-700 border-accent-500/40">
                    {{ t('imports.badge_oss', { n: r.oss_items }) }}
                  </span>
                  <span v-if="(r.oss_rate_type_unknown ?? 0) > 0" class="ml-2 text-xs px-1.5 py-0.5 rounded border bg-warning-50 text-warning-700 border-warning-500/40">
                    {{ t('imports.badge_rate_type_unknown') }}
                  </span>
                  <span v-if="(r.oss_manual_review ?? 0) > 0" class="ml-2 text-xs px-1.5 py-0.5 rounded border bg-warning-50 text-warning-700 border-warning-500/40">
                    {{ t('imports.badge_manual_review') }}
                  </span>
                  <span v-if="(r.oss_credit_note_pending_period ?? 0) > 0" class="ml-2 text-xs px-1.5 py-0.5 rounded border bg-warning-50 text-warning-700 border-warning-500/40">
                    {{ t('imports.badge_credit_note_period') }}
                  </span>
                  <span v-if="r.varsymbol_substituted" class="ml-2 text-xs px-1.5 py-0.5 rounded border bg-neutral-50 text-neutral-600 border-neutral-200">
                    {{ t('imports.badge_varsymbol_substituted') }}
                  </span>
                </span>
                <span v-else class="text-xs">{{ r.reason || '—' }}</span>
              </td>
            </tr>
            <!-- Varování a poznámky pod řádkem: varování rozbalená, poznámky na klik -->
            <tr v-if="hasDetail(r)" class="border-b border-neutral-100">
              <td colspan="4" class="pb-3 pr-3" :class="warningsOf(r).length > 0 ? 'bg-warning-50/40' : ''">
                <div v-if="warningsOf(r).length > 0" class="rounded-md bg-warning-50 border border-warning-500/40 px-3 py-2">
                  <div class="text-xs font-semibold text-warning-700 mb-1">⚠ {{ t('imports.warnings_label') }}</div>
                  <ul class="list-disc pl-4 space-y-1 text-xs text-warning-700">
                    <li v-for="(w, wi) in warningsOf(r)" :key="wi">{{ w }}</li>
                  </ul>
                </div>
                <details v-if="notesOf(r).length > 0" class="mt-2">
                  <summary class="cursor-pointer text-xs text-neutral-500 hover:text-neutral-900">
                    {{ t('imports.notes_toggle', { n: notesOf(r).length }) }}
                  </summary>
                  <ul class="list-disc pl-4 mt-1 space-y-1 text-xs text-neutral-600">
                    <li v-for="(n, ni) in notesOf(r)" :key="ni">{{ n }}</li>
                  </ul>
                </details>
              </td>
            </tr>
          </template>
        </tbody>
      </table>
    </div>
  </div>
</template>
