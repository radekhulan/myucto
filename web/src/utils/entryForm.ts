import type { EntryForm, EntryFormHistoryRow } from '@/api/accounting'

/**
 * Forma účetního zápisu firmy k datu: řádek historie s nejvyšším `valid_from <= date`,
 * bez řádku `composite`. Stejné pravidlo jako EntryFormService::formAt na serveru
 * (rozhoduje datum zápisu, ne datum pořízení).
 */
export function entryFormAt(history: EntryFormHistoryRow[], date: string): EntryForm {
  let form: EntryForm = 'composite'
  let from = ''
  for (const row of history) {
    if (row.valid_from <= date && row.valid_from >= from) {
      form = row.entry_form
      from = row.valid_from
    }
  }
  return form
}

/** Má firma kdy koli jednoduchou formu (pak se mají ukazovat prvky dvojic)? */
export function hasAnySingle(history: EntryFormHistoryRow[]): boolean {
  return history.some(row => row.entry_form === 'single')
}

/** Kódy chyb jednoduchého zápisu a změny formy, které UI překládá (accounting.error.*). */
export const PAIR_ERROR_CODES = [
  'pair_missing_debit_account',
  'pair_missing_credit_account',
  'pair_same_account',
  'pair_amount_not_positive',
  'pair_unbalanced',
  'pair_incomplete',
  'pair_numbering',
  'pair_in_composite_form',
  'single_unpaired',
  'pairing_required',
  'entry_form_requires_double_entry',
  'entry_form_unsupported_module',
  'entry_form_period_not_open',
  'entry_form_date_locked',
  'entry_form_newer_change_exists',
  'entry_form_unchanged',
  'invalid_entry_form_date',
  'not_implemented',
  'payroll_reversal_forbidden',
] as const

export interface ApiErrorInfo {
  code: string
  message: string
  /** Číslo dvojice (1..n), na kterou chyba ukazuje (`error.pair_no`), jinak null. */
  pairNo: number | null
}

/**
 * Rozbalí chybu z axiosu: kód, text serveru a číslo dvojice. Server slévá kontext výjimky
 * přímo do objektu `error` (`error.pair_no`), pro jistotu se čte i vnořené `context`/`data`.
 */
export function apiErrorInfo(e: unknown): ApiErrorInfo {
  const err = (e as { response?: { data?: { error?: Record<string, unknown> } } })?.response?.data?.error
  const nested = [err?.context, err?.data].find(v => v && typeof v === 'object') as Record<string, unknown> | undefined
  const raw = err?.pair_no ?? nested?.pair_no
  const pairNo = typeof raw === 'number' && Number.isInteger(raw) && raw > 0 ? raw : null
  return {
    code: typeof err?.code === 'string' ? err.code : '',
    message: typeof err?.message === 'string' ? err.message : '',
    pairNo,
  }
}

/**
 * Srozumitelná věta pro chybu API: překlad `accounting.error.<kód>` pro kódy dvojic
 * a změny formy (u dvojice s číslem dvojice), jinak text serveru, jinak obecná chyba.
 * `allCodes` překládá i ostatní kódy `accounting.error.*` (ruční zápis to dělal vždy).
 */
export function apiErrorText(
  e: unknown,
  t: (key: string, params?: Record<string, unknown>) => string,
  allCodes = false,
): string {
  const info = apiErrorInfo(e)
  const known = (PAIR_ERROR_CODES as readonly string[]).includes(info.code)
  if (info.code && (known || allCodes)) {
    const key = `accounting.error.${info.code}`
    const localized = t(key)
    if (localized && localized !== key) {
      return info.pairNo !== null && info.code.startsWith('pair_')
        ? `${t('accounting.pairs.pair_label', { n: info.pairNo })}: ${localized}`
        : localized
    }
  }
  return info.message || t('common.error')
}

/** Řádek složeného zápisu, ze kterého se skládají dvojice (a naopak). */
export interface PairableLine {
  account_code: string
  side: 'debit' | 'credit'
  amount: number | null
  is_red_storno?: boolean
}

/** Dvojice MD/Dal bez dalších údajů, jak ji skládá {@link linesToPairs}. */
export interface PairDraft {
  debit_account_code: string
  credit_account_code: string
  amount: number
  is_red_storno?: boolean
}

const cents = (v: number | null | undefined): number => Math.round(Number(v || 0) * 100)

/**
 * Převede řádky složeného zápisu na dvojice tam, kde je párování jednoznačné:
 * jedna strana má jediný řádek (1 : N), nebo si částky odpovídají jedna ku jedné.
 * Jinak vrací null a uživatel dvojice zadá sám (server M : N nepáruje).
 */
export function linesToPairs(lines: PairableLine[]): PairDraft[] | null {
  if (lines.length === 0) return null
  if (lines.some(l => !!l.is_red_storno !== !!lines[0].is_red_storno)) return null
  const red = !!lines[0].is_red_storno
  const debits = lines.filter(l => l.side === 'debit')
  const credits = lines.filter(l => l.side === 'credit')
  if (debits.length === 0 || credits.length === 0) return null
  const sum = (ls: PairableLine[]) => ls.reduce((s, l) => s + cents(l.amount), 0)
  if (sum(debits) !== sum(credits)) return null
  const make = (d: string, c: string, amount: number): PairDraft =>
    ({ debit_account_code: d, credit_account_code: c, amount, ...(red ? { is_red_storno: true } : {}) })

  if (debits.length === 1) {
    return credits.map(c => make(debits[0].account_code, c.account_code, Number(c.amount)))
  }
  if (credits.length === 1) {
    return debits.map(d => make(d.account_code, credits[0].account_code, Number(d.amount)))
  }
  const pool = [...credits]
  const out: PairDraft[] = []
  for (const d of debits) {
    const idx = pool.findIndex(c => cents(c.amount) === cents(d.amount))
    if (idx < 0) return null
    out.push(make(d.account_code, pool[idx].account_code, Number(d.amount)))
    pool.splice(idx, 1)
  }
  return out
}

/** Dvojice zpět na řádky složeného zápisu (MD, pak Dal). */
export function pairsToLines(pairs: PairDraft[]): PairableLine[] {
  return pairs.flatMap(p => [
    { account_code: p.debit_account_code, side: 'debit' as const, amount: p.amount, is_red_storno: !!p.is_red_storno },
    { account_code: p.credit_account_code, side: 'credit' as const, amount: p.amount, is_red_storno: !!p.is_red_storno },
  ])
}
