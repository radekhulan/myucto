import type {
  BulkApplyRequest,
  BulkDryRunRequest,
  BulkItem,
  BulkLabels,
  BulkOperation,
  BulkParams,
  BulkSelection,
  BulkTarget,
  DimensionChange,
  DimensionSnapshotRow,
  PostingRuleSnapshot,
} from '@/api/bulkActions'
import type { JournalRebookItemLine, JournalRebookMode, JournalRebookSide } from '@/api/journalRebook'
import { csvAmount, type CsvCell } from '@/utils/bulkJobCsv'
import {
  buildNoteParams, buildSymbolParams, emptyNoteForm, emptySymbolForm, noteChanges, noteSymbolReasonText, symbolChanges,
  type BulkNoteForm, type BulkSymbolForm,
} from './noteSymbolForm'

/** Volba u jednoho typu dimenze: `keep` = beze změny (do požadavku nejde). */
export type BulkDimensionChoiceMode = 'keep' | DimensionChange['mode']

export interface BulkDimensionChoice {
  mode: BulkDimensionChoiceMode
  valueId: number | null
}

/** Stav formuláře hromadné akce (všechny operace najednou, použije se jen zvolená). */
export interface BulkActionForm {
  operation: BulkOperation | ''
  rebook: { from_account: string; to_account: string; side: JournalRebookSide; mode: JournalRebookMode }
  dimensions: Record<number, BulkDimensionChoice>
  scope: 'document' | 'items'
  accountPrefixes: string
  ruleKey: string
  ruleMode: JournalRebookMode
  journalFallback: 'skip' | 'reverse'
  note: BulkNoteForm
  symbol: BulkSymbolForm
}

export function emptyBulkForm(): BulkActionForm {
  return {
    operation: '',
    rebook: { from_account: '', to_account: '', side: 'both', mode: 'rewrite' },
    dimensions: {},
    scope: 'document',
    accountPrefixes: '',
    ruleKey: '',
    ruleMode: 'rewrite',
    journalFallback: 'skip',
    note: emptyNoteForm(),
    symbol: emptySymbolForm(),
  }
}

export const BULK_OPERATIONS: BulkOperation[] = ['rebook_account', 'set_dimensions', 'set_posting_rule', 'set_note', 'set_variable_symbol', 'delete']

/** „5, 6" → ['5', '6']; prázdné hodnoty a duplicity vypadnou. */
export function parseAccountPrefixes(raw: string): string[] {
  const out: string[] = []
  for (const part of raw.split(/[,;\s]+/)) {
    const p = part.trim()
    if (p !== '' && !out.includes(p)) out.push(p)
  }
  return out
}

/**
 * Změny dimenzí z formuláře. `null` = volba není kompletní (přepsat nebo doplnit bez hodnoty)
 * nebo se nemění nic.
 */
export function dimensionChanges(choices: Record<number, BulkDimensionChoice>): DimensionChange[] | null {
  const out: DimensionChange[] = []
  for (const [typeId, choice] of Object.entries(choices)) {
    if (choice.mode === 'keep') continue
    if (choice.mode === 'clear') {
      out.push({ type_id: Number(typeId), mode: 'clear' })
      continue
    }
    if (!choice.valueId) return null
    out.push({ type_id: Number(typeId), mode: choice.mode, value_id: choice.valueId })
  }
  return out.length > 0 ? out : null
}

/** Parametry zvolené operace pro daný cíl; `null` = formulář není kompletní. */
export function buildBulkParams(target: BulkTarget, form: BulkActionForm): BulkParams | null {
  switch (form.operation) {
    case 'rebook_account': {
      const from = form.rebook.from_account.trim()
      const to = form.rebook.to_account.trim()
      if (!from || !to || from.toUpperCase() === to.toUpperCase()) return null
      return { from_account: from, to_account: to, side: form.rebook.side, mode: form.rebook.mode }
    }
    case 'set_dimensions': {
      const dimensions = dimensionChanges(form.dimensions)
      if (!dimensions) return null
      if (target === 'journal_entry') {
        const prefixes = parseAccountPrefixes(form.accountPrefixes)
        return prefixes.length > 0 ? { dimensions, account_prefixes: prefixes } : { dimensions }
      }
      return { dimensions, scope: form.scope }
    }
    case 'set_posting_rule': {
      const ruleKey = form.ruleKey.trim()
      if (!ruleKey) return null
      return { rule_key: ruleKey, mode: form.ruleMode }
    }
    case 'delete':
      return target === 'journal_entry' ? { journal_fallback: form.journalFallback } : {}
    case 'set_note':
      return buildNoteParams(form.note)
    case 'set_variable_symbol':
      return buildSymbolParams(form.symbol)
    default:
      return null
  }
}

export function buildDryRunRequest(target: BulkTarget, selection: BulkSelection, form: BulkActionForm): BulkDryRunRequest | null {
  const params = buildBulkParams(target, form)
  if (!params || !form.operation) return null
  return { dry_run: true, operation: form.operation, target, selection, params }
}

/** Kolik položek ostrý běh provede: změněné nanečisto bez ručně vyřazených. */
export function applyItemCount(wouldChange: number, excludedCount: number): number {
  return Math.max(0, wouldChange - excludedCount)
}

/** Opsaný počet u smazání musí přesně sedět (jen číslice, bez mezer kolem). */
export function deleteConfirmMatches(typed: string, expected: number): boolean {
  const s = typed.trim()
  return /^\d+$/.test(s) && Number(s) === expected && expected > 0
}

export function buildApplyRequest(
  operation: BulkOperation,
  dryRunJobId: number,
  excludedIds: Iterable<number>,
  applyCount: number,
): BulkApplyRequest {
  const body: BulkApplyRequest = { dry_run: false, dry_run_job_id: dryRunJobId, excluded_item_ids: [...excludedIds] }
  if (operation === 'delete') body.confirm_count = applyCount
  return body
}

/** Důvody, které UI umí přeložit; ostatní ukážou zprávu serveru. */
export const BULK_REASON_CODES = [
  'period_not_open', 'date_locked', 'entry_reversed', 'needs_reverse', 'not_item_account',
  'bank_invariant', 'posting_group', 'changed_after_dry_run', 'changed_after_apply', 'excluded_by_user',
  'entry_not_found', 'tax_neutral_rewrite', 'cash_account', 'taken_over',
  'dimensions_frozen', 'dimension_required', 'split_in_locked_period', 'account_change_in_locked_period',
  'account_change_needs_repost', 'no_matching_lines', 'not_posted', 'forbidden', 'admin_required',
  'retention_period', 'not_deletable', 'document_locked', 'delete_blocked', 'entry_delete_not_supported',
  'reversed_instead', 'not_found', 'invalid_posting_rule', 'dimensions_disabled',
  'document_cancelled', 'rollback_not_supported', 'journal_period_not_open', 'calendar_not_deletable',
] as const

export function bulkReasonText(item: Pick<BulkItem, 'reason_code' | 'message'>, t: (key: string) => string): string {
  const code = item.reason_code
  if (code && (BULK_REASON_CODES as readonly string[]).includes(code)) return t('bulk_actions.reason.' + code)
  const own = noteSymbolReasonText(item, t)
  if (own) return own
  return item.message || code || '—'
}

// ── Před a po podle operace ─────────────────────────────────────────────────

export interface ChangeRow {
  label: string
  from: string
  to: string
}

function asArray<T>(v: unknown): T[] {
  return Array.isArray(v) ? v as T[] : []
}

/** Přeúčtování: jen řádky, kterým se změnil účet (párují se podle pořadí). */
export function rebookChanges(before: unknown, after: unknown, t: (key: string) => string): ChangeRow[] {
  const b = asArray<JournalRebookItemLine>(before)
  const a = asArray<JournalRebookItemLine>(after)
  const out: ChangeRow[] = []
  for (let i = 0; i < Math.max(a.length, b.length); i++) {
    const from = b[i]?.account_code ?? ''
    const to = a[i]?.account_code ?? ''
    if (from === to) continue
    const line = a[i] ?? b[i]!
    out.push({ label: `${t('bulk_actions.side.' + line.side)} ${csvAmount(line.amount)}`, from: from || '—', to: to || '—' })
  }
  return out
}

function snapshotKey(row: DimensionSnapshotRow): string {
  return `${row.kind}:${row.line_id ?? row.no ?? ''}`
}

function snapshotLabel(row: DimensionSnapshotRow, t: (key: string, params?: Record<string, unknown>) => string): string {
  const base = t('bulk_actions.snapshot.' + row.kind, { no: row.no ?? '' })
  return row.account_code ? `${base} ${row.account_code}` : base
}

/** PHP posílá prázdnou mapu jako `[]`. */
function asMap<T>(v: unknown): Record<string, T> {
  return v && typeof v === 'object' && !Array.isArray(v) ? v as Record<string, T> : {}
}

function formatShare(share: number): string {
  const pct = share <= 1 ? share * 100 : share
  return `${Math.round(pct * 100) / 100} %`
}

function dimValueText(row: DimensionSnapshotRow | undefined, typeId: string, labels: BulkLabels | undefined): string {
  if (!row) return '—'
  const split = asMap<number>(asMap<unknown>(row.splits)[typeId])
  const parts = Object.entries(split)
  if (parts.length > 0) {
    return parts.map(([valueId, share]) => `${labels?.values[valueId] ?? '#' + valueId} ${formatShare(Number(share))}`).join(', ')
  }
  const id = asMap<number>(row.dimensions)[typeId]
  if (!id) return '—'
  return labels?.values[String(id)] ?? `#${id}`
}

/** Dimenze: změněné typy po řádcích snapshotu (hlavička, položka, řádek zápisu). */
export function dimensionSnapshotChanges(
  before: unknown,
  after: unknown,
  labels: BulkLabels | undefined,
  t: (key: string, params?: Record<string, unknown>) => string,
): ChangeRow[] {
  const b = asArray<DimensionSnapshotRow>(before)
  const a = asArray<DimensionSnapshotRow>(after)
  const beforeByKey = new Map(b.map(r => [snapshotKey(r), r]))
  const rows = a.length > 0 ? a : b
  const out: ChangeRow[] = []
  for (const row of rows) {
    const prev = beforeByKey.get(snapshotKey(row))
    const next = a.length > 0 ? row : undefined
    const typeIds = new Set([
      ...Object.keys(asMap(prev?.dimensions)), ...Object.keys(asMap(prev?.splits)),
      ...Object.keys(asMap(next?.dimensions)), ...Object.keys(asMap(next?.splits)),
    ])
    for (const typeId of typeIds) {
      const from = dimValueText(prev, typeId, labels)
      const to = next ? dimValueText(next, typeId, labels) : from
      if (from === to) continue
      const typeName = labels?.types[typeId] ?? `#${typeId}`
      out.push({ label: `${snapshotLabel(row, t)} · ${typeName}`, from, to })
    }
  }
  return out
}

/** Předkontace: položky se změněnou předkontací nebo účty a změněné účty v zápisu. */
export function postingRuleChanges(before: unknown, after: unknown, t: (key: string, params?: Record<string, unknown>) => string): ChangeRow[] {
  const b = (before && typeof before === 'object' ? before : {}) as Partial<PostingRuleSnapshot>
  const a = (after && typeof after === 'object' ? after : {}) as Partial<PostingRuleSnapshot>
  const out: ChangeRow[] = []
  const prevItems = new Map((b.items ?? []).map(i => [i.item_id, i]))
  for (const item of a.items ?? []) {
    const prev = prevItems.get(item.item_id)
    const fmt = (i: typeof item | undefined) => i
      ? `${i.posting_rule_key ?? '—'} (${i.account_code ?? '…'} / ${i.counter_account_code ?? '…'})`
      : '—'
    const from = fmt(prev)
    const to = fmt(item)
    if (from === to) continue
    out.push({ label: item.description || t('bulk_actions.snapshot.item', { no: item.item_id }), from, to })
  }
  for (const row of rebookChanges(b.lines ?? [], a.lines ?? [], t)) out.push(row)
  return out
}

/** Smazání: co se s dokladem stane (smazat, nebo stornovat). */
export function deleteChange(before: unknown, after: unknown, t: (key: string) => string): ChangeRow[] {
  const a = (after && typeof after === 'object' ? after : null) as { action?: string } | null
  const b = (before && typeof before === 'object' ? before : null) as { status?: string | null; posted?: boolean } | null
  if (!a?.action) return []
  return [{
    label: b?.posted ? t('bulk_actions.snapshot.posted') : t('bulk_actions.snapshot.not_posted'),
    from: b?.status ?? '—',
    to: t('bulk_actions.delete_action.' + a.action),
  }]
}

export function itemChanges(
  operation: BulkOperation,
  item: Pick<BulkItem, 'before' | 'after'> & Partial<Pick<BulkItem, 'strategy'>>,
  labels: BulkLabels | undefined,
  t: (key: string, params?: Record<string, unknown>) => string,
): ChangeRow[] {
  switch (operation) {
    case 'rebook_account': return rebookChanges(item.before, item.after, t)
    case 'set_dimensions': return dimensionSnapshotChanges(item.before, item.after, labels, t)
    case 'set_posting_rule': return postingRuleChanges(item.before, item.after, t)
    case 'delete': return deleteChange(item.before, item.after, t)
    case 'set_note': return noteChanges(item.before, item.after, item.strategy, t)
    case 'set_variable_symbol': return symbolChanges(item.before, item.after, t)
  }
}

export const BULK_CSV_COLUMNS = ['col_document', 'col_date', 'col_description', 'col_amount', 'col_status', 'col_change', 'col_reason'] as const

export function bulkCsvRows(
  operation: BulkOperation,
  items: BulkItem[],
  labels: BulkLabels | undefined,
  t: (key: string, params?: Record<string, unknown>) => string,
): CsvCell[][] {
  return items.map(item => [
    item.document_no,
    item.entry_date,
    item.description,
    csvAmount(item.amount),
    t('bulk_actions.tab.' + item.status),
    itemChanges(operation, item, labels, t).map(c => `${c.label}: ${c.from} -> ${c.to}`).join(' | '),
    item.reason_code || item.message ? bulkReasonText(item, t) : '',
  ])
}
