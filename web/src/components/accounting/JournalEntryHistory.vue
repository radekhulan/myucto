<script setup lang="ts">
import { journalAmount } from '@/utils/journalAmount'
/**
 * Auditní historie účetního zápisu (audit 2026-07, nález „Historie účetního zápisu
 * v UI — SYSTEM VERSIONING timeline"). Lazy-load na první rozbalení — panel je
 * schovaný pod přepínačem, ať se GET .../history nevolá při každém expandu řádku
 * deníku, kde o historii uživatel nestojí.
 */
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import {
  accountingApi, type JournalHistoryResponse, type JournalHistoryLineChange,
  type JournalHistoryLine, type JournalHistoryVersion,
} from '@/api/accounting'
import { useToast } from '@/composables/useToast'
import { formatDate, formatDateTime, formatMoney } from '@/composables/useFormat'
import { ICONS } from '@/components/ui/buttonStyles'
import CollapsibleSection from '@/components/ui/CollapsibleSection.vue'

const props = defineProps<{ entryId: number }>()

const { t } = useI18n()
const toast = useToast()

const open = ref(false)
const loading = ref(false)
const loaded = ref(false)
const history = ref<JournalHistoryResponse | null>(null)

const HEADER_FIELD_LABELS: Record<string, string> = {
  entry_date: 'accounting.journal.entry_date',
  document_date: 'accounting.journal.col_document_date',
  document_no: 'accounting.journal.document_no',
  description: 'accounting.journal.description',
  posted_at: 'accounting.journal.col_posted_at',
  posted_by_name: 'accounting.journal.col_posted_by',
  // reversed_by/source_id/period_id doplněny po adversariálním review (M-1) — storno-
  // přechod (reversed_by NULL → id protizápisu) se teď v diffu skutečně zobrazí.
  reversed_by: 'accounting.journal.reversal_entry',
}
function fieldLabel(field: string): string {
  const key = HEADER_FIELD_LABELS[field]
  return key ? t(key) : field
}
function fieldValue(field: string, v: unknown): string {
  if (field === 'reversed_by') return v ? `#${v}` : '—'
  if (v === null || v === undefined || v === '') return '—'
  if (field === 'entry_date' || field === 'document_date' || field === 'posted_at') return formatDate(String(v))
  return String(v)
}

async function toggle(isOpen: boolean) {
  open.value = isOpen
  if (open.value && !loaded.value) {
    loading.value = true
    try {
      history.value = await accountingApi.getJournalHistory(props.entryId)
      loaded.value = true
    } catch (e: any) {
      toast.error(e?.response?.data?.error?.message || t('common.error'))
    } finally {
      loading.value = false
    }
  }
}

type RowState = 'same' | 'added' | 'changed' | 'removed'
interface HistoryRow {
  key: string
  state: RowState
  line: JournalHistoryLine
  before?: JournalHistoryLine
}

/** Celý zápis verze; rozdíl proti předchozí verzi je označený stavem řádku, odebrané řádky jsou na konci. */
function versionRows(v: JournalHistoryVersion): HistoryRow[] {
  const changes = new Map<number, JournalHistoryLineChange>()
  for (const c of v.line_changes ?? []) changes.set(c.line_no, c)
  const rows: HistoryRow[] = (v.lines ?? []).map((line) => {
    const c = changes.get(line.line_no)
    const state: RowState = c?.type === 'added' ? 'added' : c?.type === 'changed' ? 'changed' : 'same'
    return { key: `l-${line.line_no}`, state, line, before: state === 'changed' ? c?.before : undefined }
  })
  for (const c of v.line_changes ?? []) {
    if (c.type === 'removed' && c.line) rows.push({ key: `r-${c.line_no}`, state: 'removed', line: c.line })
  }
  return rows
}
function rowClass(state: RowState): string {
  if (state === 'added') return 'bg-success-50'
  if (state === 'changed') return 'bg-warning-50'
  if (state === 'removed') return 'bg-danger-50 text-neutral-500'
  return ''
}
function lineSideLabel(side: string): string {
  return t(`accounting.journal.side.${side}`)
}
</script>

<template>
  <CollapsibleSection :title="t('accounting.journal.history.title')" :icon="ICONS.cycle" @toggle="toggle">
    <div>
      <div v-if="loading" class="text-xs text-neutral-400 py-2">{{ t('common.loading') }}</div>
      <template v-else-if="history">
        <p v-if="history.versions.length <= 1" class="text-xs text-neutral-400 mb-2">{{ t('accounting.journal.history.no_changes') }}</p>
        <ol class="space-y-3">
          <li v-for="v in history.versions" :key="v.version" class="border border-neutral-200 rounded-md px-3 py-2 bg-neutral-50">
            <div class="flex items-center flex-wrap gap-2 text-xs">
              <span class="font-semibold text-neutral-700">{{ t('accounting.journal.history.version', { n: v.version }) }}</span>
              <span v-if="v.is_current" class="px-1.5 py-0.5 rounded bg-success-50 text-success-600 font-medium">{{ t('accounting.journal.history.current') }}</span>
              <span class="text-neutral-400">{{ formatDateTime(v.valid_from) }}</span>
              <span class="text-neutral-500">
                - {{ v.changed_by?.user_name || t('accounting.journal.history.unknown_user') }}
              </span>
            </div>

            <p v-if="!v.header_changes && !v.line_changes" class="text-xs text-neutral-400 mt-1">
              {{ t('accounting.journal.history.created') }}
            </p>

            <ul v-if="v.header_changes && Object.keys(v.header_changes).length" class="mt-1.5 space-y-0.5">
              <li v-for="(diff, field) in v.header_changes" :key="field" class="text-xs text-neutral-600">
                <span class="font-medium">{{ fieldLabel(String(field)) }}:</span>
                <span class="text-neutral-400 line-through ml-1">{{ fieldValue(String(field), diff.before) }}</span>
                <span class="mx-1">→</span>
                <span class="text-neutral-800">{{ fieldValue(String(field), diff.after) }}</span>
              </li>
            </ul>

            <div v-if="v.lines && v.lines.length" class="mt-2 overflow-x-auto" data-testid="history-lines">
              <table class="w-full text-xs">
                <thead>
                  <tr class="text-left text-neutral-500">
                    <th class="font-medium pr-3 py-0.5">{{ t('accounting.journal.history.col_pair') }}</th>
                    <th class="font-medium pr-3 py-0.5">{{ t('accounting.journal.history.col_account') }}</th>
                    <th class="font-medium pr-3 py-0.5">{{ lineSideLabel('debit') }}</th>
                    <th class="font-medium pr-3 py-0.5">{{ lineSideLabel('credit') }}</th>
                    <th class="font-medium py-0.5 w-full">{{ t('accounting.journal.history.col_change') }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="row in versionRows(v)" :key="row.key" :class="rowClass(row.state)" :data-state="row.state">
                    <td class="pr-3 py-0.5 whitespace-nowrap">{{ row.line.pair_no ? row.line.pair_no : '' }}</td>
                    <td class="pr-3 py-0.5">
                      <span class="font-mono" :class="row.state === 'removed' ? 'line-through' : ''">{{ row.line.account_code }}</span> {{ row.line.account_name }}
                    </td>
                    <td class="pr-3 py-0.5 text-right whitespace-nowrap tabular-nums">{{ row.line.side === 'debit' ? formatMoney(journalAmount(row.line)) : '' }}</td>
                    <td class="pr-3 py-0.5 text-right whitespace-nowrap tabular-nums">{{ row.line.side === 'credit' ? formatMoney(journalAmount(row.line)) : '' }}</td>
                    <td class="py-0.5 text-neutral-600">
                      <template v-if="row.state === 'added'"><span class="text-success-600 font-medium">+ {{ t('accounting.journal.history.line_added') }}</span></template>
                      <template v-else-if="row.state === 'removed'"><span class="text-danger-500 font-medium">− {{ t('accounting.journal.history.line_removed') }}</span></template>
                      <template v-else-if="row.state === 'changed' && row.before">
                        <span class="text-warning-600 font-medium">~ {{ t('accounting.journal.history.line_changed') }}:</span>
                        <span class="text-neutral-400 line-through ml-1">
                          <span class="font-mono">{{ row.before.account_code }}</span>
                          {{ lineSideLabel(row.before.side) }} {{ formatMoney(journalAmount(row.before)) }}
                        </span>
                      </template>
                    </td>
                  </tr>
                </tbody>
              </table>
            </div>
          </li>
        </ol>
      </template>
    </div>
  </CollapsibleSection>
</template>
