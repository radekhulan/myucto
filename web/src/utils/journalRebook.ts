import type {
  JournalRebookFilter,
  JournalRebookItem,
  JournalRebookItemLine,
  JournalRebookMode,
  JournalRebookSide,
  JournalRebookSourceType,
} from '@/api/journalRebook'

/** Stav formuláře „Co přeúčtovat" (částky jako text z inputu). */
export interface JournalRebookForm {
  from_account: string
  to_account: string
  side: JournalRebookSide
  date_from: string
  date_to: string
  source_types: JournalRebookSourceType[]
  text: string
  amount_min: string
  amount_max: string
  mode: JournalRebookMode
}

export const REBOOK_SOURCE_TYPES: JournalRebookSourceType[] = ['invoice', 'purchase_invoice', 'bank']

/** Důvody vynechání, které umí stránka přeložit; ostatní (a `repost_failed`) ukazují zprávu serveru. */
export const REBOOK_REASON_CODES = [
  'period_not_open', 'date_locked', 'entry_reversed', 'posting_group', 'needs_reverse',
  'not_item_account', 'changed_after_dry_run', 'changed_after_apply', 'excluded_by_user',
  'bank_invariant', 'taken_over',
] as const

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/

/** Částka z inputu: prázdná = null, neplatná = NaN (formulář pak není validní). */
export function parseAmount(raw: string): number | null {
  const s = raw.replace(/\s/g, '').replace(',', '.')
  if (s === '') return null
  return /^-?\d+(\.\d+)?$/.test(s) ? Number(s) : NaN
}

export function isRebookFormValid(form: JournalRebookForm): boolean {
  const from = form.from_account.trim()
  const to = form.to_account.trim()
  if (!from || !to || from.toUpperCase() === to.toUpperCase()) return false
  if (!ISO_DATE.test(form.date_from) || !ISO_DATE.test(form.date_to) || form.date_from > form.date_to) return false
  if (form.source_types.length === 0) return false
  const min = parseAmount(form.amount_min)
  const max = parseAmount(form.amount_max)
  if (Number.isNaN(min) || Number.isNaN(max)) return false
  if (min !== null && max !== null && min > max) return false
  return true
}

export function buildRebookFilter(form: JournalRebookForm): JournalRebookFilter {
  const text = form.text.trim()
  return {
    from_account: form.from_account.trim(),
    to_account: form.to_account.trim(),
    side: form.side,
    date_from: form.date_from,
    date_to: form.date_to,
    source_types: REBOOK_SOURCE_TYPES.filter(s => form.source_types.includes(s)),
    text: text === '' ? null : text,
    amount_min: parseAmount(form.amount_min),
    amount_max: parseAmount(form.amount_max),
  }
}

export interface RebookLineChange {
  side: 'debit' | 'credit'
  amount: number
  from: string
  to: string
}

/** Jen řádky, kterým se změnil účet (před a po se párují podle pořadí). */
export function changedLines(item: Pick<JournalRebookItem, 'before' | 'after'>): RebookLineChange[] {
  const out: RebookLineChange[] = []
  const n = Math.max(item.before.length, item.after.length)
  for (let i = 0; i < n; i++) {
    const b: JournalRebookItemLine | undefined = item.before[i]
    const a: JournalRebookItemLine | undefined = item.after[i]
    const from = b?.account_code ?? ''
    const to = a?.account_code ?? ''
    if (from === to) continue
    out.push({ side: (a ?? b)!.side, amount: (a ?? b)!.amount, from: from || '—', to: to || '—' })
  }
  return out
}

/** Lokalizovaný důvod vynechání; neznámý kód nebo `repost_failed` spadne na zprávu serveru. */
export function rebookReasonText(
  item: Pick<JournalRebookItem, 'reason_code' | 'message'>,
  t: (key: string) => string,
): string {
  const code = item.reason_code
  if (code && (REBOOK_REASON_CODES as readonly string[]).includes(code)) {
    return t('accounting.journal_rebook.reason.' + code)
  }
  return item.message || code || '—'
}
