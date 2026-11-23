import type { BulkItem, SetNoteParams, SetVariableSymbolParams } from '@/api/bulkActions'
import type { ChangeRow } from './bulkActionsForm'

/**
 * Formulář a výsledek dvou hromadných akcí: poznámka faktur (`set_note`) a variabilní
 * symbol bankovních pohybů (`set_variable_symbol`). Kontroly odpovídají serveru
 * (SetNoteOperation, SetVariableSymbolOperation), aby běh nanečisto nešel spustit
 * s parametry, které server odmítne.
 */

type Translate = (key: string, params?: Record<string, unknown>) => string

export type NoteField = SetNoteParams['note_field']
export type NoteMode = SetNoteParams['note_mode']

export interface BulkNoteForm {
  field: NoteField
  mode: NoteMode
  text: string
  includeIssued: boolean
}

export interface BulkSymbolForm {
  mode: 'constant' | 'pattern'
  value: string
  pattern: string
}

export const NOTE_FIELDS: NoteField[] = ['note_above_items', 'note_below_items']
export const NOTE_MODES: NoteMode[] = ['replace', 'append', 'clear']
export const NOTE_TEXT_MAX = 2000
export const VS_PATTERN_MAX = 40
export const VS_TOKENS = ['{ss}', '{ks}', '{vs}', '{zprava}'] as const

/** Popisky polí sdílí editor faktury. */
export function noteFieldLabelKey(field: NoteField): string {
  return field === 'note_above_items' ? 'invoice.note_above' : 'invoice.note_below'
}

export function emptyNoteForm(): BulkNoteForm {
  return { field: 'note_below_items', mode: 'replace', text: '', includeIssued: false }
}

export function emptySymbolForm(): BulkSymbolForm {
  return { mode: 'constant', value: '', pattern: '' }
}

/** Platný VS: jen číslice, 1 až 10. */
export function isValidVariableSymbol(value: string): boolean {
  return /^[0-9]{1,10}$/.test(value.trim())
}

/** Vzor: nejvýš 40 znaků, číslice a značky, alespoň jedna značka. */
export function isValidVsPattern(pattern: string): boolean {
  const p = pattern.trim()
  if (p === '' || p.length > VS_PATTERN_MAX) return false
  let rest = p
  for (const token of VS_TOKENS) rest = rest.split(token).join('')
  return rest !== p && /^[0-9]*$/.test(rest)
}

export function noteTextError(form: BulkNoteForm): 'required' | 'too_long' | null {
  if (form.mode === 'clear') return null
  const text = form.text.trim()
  if (text === '') return 'required'
  return text.length > NOTE_TEXT_MAX ? 'too_long' : null
}

export function buildNoteParams(form: BulkNoteForm): SetNoteParams | null {
  if (!NOTE_FIELDS.includes(form.field) || !NOTE_MODES.includes(form.mode) || noteTextError(form)) return null
  const params: SetNoteParams = { note_field: form.field, note_mode: form.mode, include_issued: form.includeIssued }
  if (form.mode !== 'clear') params.note_text = form.text.trim()
  return params
}

export function buildSymbolParams(form: BulkSymbolForm): SetVariableSymbolParams | null {
  if (form.mode === 'constant') {
    return isValidVariableSymbol(form.value) ? { vs_mode: 'constant', vs_value: form.value.trim() } : null
  }
  return isValidVsPattern(form.pattern) ? { vs_mode: 'pattern', vs_pattern: form.pattern.trim() } : null
}

// ── Důvody ─────────────────────────────────────────────────────────────────

/**
 * Důvody obou akcí s vlastním textem. Kódy z úpravy faktury (not_editable,
 * force_mode_required, validation_failed …) mají v položce zprávu serveru, ta se ukáže sama.
 */
export const NOTE_SYMBOL_REASON_CODES = [
  'not_draft', 'session_required', 'period_closed', 'note_too_long',
  'invalid_variable_symbol', 'ignored', 'already_matched', 'already_settled', 'posted',
  'notice_superseded', 'rematch_failed',
] as const

/** Text důvodu, nebo `null`, když kód k těmto akcím nepatří. Selhané přepárování nese i detail serveru. */
export function noteSymbolReasonText(item: Pick<BulkItem, 'reason_code' | 'message'>, t: Translate): string | null {
  const code = item.reason_code
  if (!code || !(NOTE_SYMBOL_REASON_CODES as readonly string[]).includes(code)) return null
  const text = t('bulk_actions.ns_reason.' + code)
  return code === 'rematch_failed' && item.message ? `${text}: ${item.message}` : text
}

/** Provedená položka s důvodem (VS změněn, ale přepárování selhalo) ukáže důvod i na záložce Provedeno. */
export function showsReasonWhenApplied(item: Pick<BulkItem, 'reason_code'>): boolean {
  return item.reason_code === 'rematch_failed'
}

// ── Před a po ──────────────────────────────────────────────────────────────

const NOTE_PREVIEW = 80

function asObject(v: unknown): Record<string, unknown> {
  return v && typeof v === 'object' && !Array.isArray(v) ? v as Record<string, unknown> : {}
}

function notePreview(v: unknown): string {
  if (typeof v !== 'string' || v.trim() === '') return '—'
  const s = v.replace(/\s*\n\s*/g, ' ↵ ').trim()
  return s.length > NOTE_PREVIEW ? s.slice(0, NOTE_PREVIEW - 1) + '…' : s
}

/** Poznámka: změněné pole (nad / pod položkami), u vystaveného dokladu s upozorněním. */
export function noteChanges(before: unknown, after: unknown, strategy: string | null | undefined, t: Translate): ChangeRow[] {
  const b = asObject(before)
  const a = asObject(after)
  const out: ChangeRow[] = []
  for (const field of NOTE_FIELDS) {
    if (!(field in a) && !(field in b)) continue
    const from = notePreview(b[field])
    const to = field in a ? notePreview(a[field]) : from
    if (from === to) continue
    const label = t(noteFieldLabelKey(field))
    out.push({ label: strategy === 'issued' ? `${label} (${t('bulk_actions.note.issued_doc')})` : label, from, to })
  }
  return out
}

/** Variabilní symbol pohybu a stav párování po změně (pohyb se po změně znovu páruje). */
export function symbolChanges(before: unknown, after: unknown, t: Translate): ChangeRow[] {
  const b = asObject(before)
  const a = asObject(after)
  if (!('variable_symbol' in a)) return []
  const vs = (v: unknown) => (typeof v === 'string' && v !== '' ? v : '—')
  const status = (v: unknown) => (typeof v === 'string' && v !== '' ? t('bank.match_status.' + v) : '—')
  const out: ChangeRow[] = []
  if (vs(b.variable_symbol) !== vs(a.variable_symbol)) {
    out.push({ label: t('bulk_actions.vs.change_label'), from: vs(b.variable_symbol), to: vs(a.variable_symbol) })
  }
  if ('match_status' in a) {
    out.push({ label: t('bulk_actions.vs.match_label'), from: status(b.match_status), to: status(a.match_status) })
  }
  return out
}
