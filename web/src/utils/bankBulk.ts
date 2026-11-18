import type {
  BankBulkAction,
  BankBulkDirection,
  BankBulkDocumentType,
  BankBulkFilter,
  BankBulkItem,
  BankBulkMatchTarget,
  BankBulkPostLine,
} from '@/api/bankBulk'
import { csvAmount, type CsvCell } from '@/utils/bulkJobCsv'
import { parseAmount } from '@/utils/journalRebook'

/** Stav formuláře boxu „Bankovní pohyby podle filtru" (částky jako text z inputu). */
export interface BankBulkForm {
  action: BankBulkAction
  date_from: string
  date_to: string
  bank_account: string
  direction: BankBulkDirection
  text: string
  counterparty_account: string
  variable_symbol: string
  amount_min: string
  amount_max: string
  counter_account: string
  description: string
  document_type: BankBulkDocumentType
  document_id: number | null
}

/** Důvody vynechání, které umí box přeložit; ostatní (`posting_failed`, `match_failed`) ukazují zprávu serveru. */
export const BANK_BULK_REASON_CODES = [
  'changed_after_dry_run', 'changed_after_apply', 'excluded_by_user', 'already_posted',
  'period_not_open', 'date_locked', 'bank_invariant', 'currency', 'not_found',
  'over_remaining', 'not_candidate', 'forbidden',
] as const

const ISO_DATE = /^\d{4}-\d{2}-\d{2}$/

export function isBankBulkFormValid(form: BankBulkForm): boolean {
  if (!ISO_DATE.test(form.date_from) || !ISO_DATE.test(form.date_to) || form.date_from > form.date_to) return false
  const min = parseAmount(form.amount_min)
  const max = parseAmount(form.amount_max)
  if (Number.isNaN(min) || Number.isNaN(max)) return false
  if (min !== null && max !== null && min > max) return false
  if (form.action === 'post') return form.counter_account.trim() !== ''
  return form.document_id !== null && form.document_id > 0
}

export function buildBankBulkFilter(form: BankBulkForm): BankBulkFilter {
  const opt = (s: string): string | null => (s.trim() === '' ? null : s.trim())
  const abs = (n: number | null): number | null => (n === null ? null : Math.abs(n))
  const base: BankBulkFilter = {
    action: form.action,
    date_from: form.date_from,
    date_to: form.date_to,
    bank_account: opt(form.bank_account),
    text: opt(form.text),
    counterparty_account: opt(form.counterparty_account),
    variable_symbol: opt(form.variable_symbol),
    amount_min: abs(parseAmount(form.amount_min)),
    amount_max: abs(parseAmount(form.amount_max)),
  }
  if (form.action === 'post') {
    return { ...base, direction: form.direction, counter_account: form.counter_account.trim(), description: opt(form.description) }
  }
  return { ...base, document_type: form.document_type, document_id: form.document_id ?? 0 }
}

export function isMatchTarget(x: BankBulkPostLine | BankBulkMatchTarget): x is BankBulkMatchTarget {
  return 'document_id' in x
}

/** Sloupec „Zaúčtování / Doklad": řádky zápisu (MD 518000 / D 221000) nebo čísla dokladů. */
export function bankBulkAfterText(item: Pick<BankBulkItem, 'after'>, t: (key: string) => string): string {
  return item.after.map(x => isMatchTarget(x)
    ? (x.document_no || `#${x.document_id}`)
    : `${t('accounting.bank_bulk.side_' + x.side)} ${x.account_code ?? '—'}`).join(' / ')
}

export function bankBulkReasonText(
  item: Pick<BankBulkItem, 'reason_code' | 'message'>,
  t: (key: string) => string,
): string {
  const code = item.reason_code
  if (code && (BANK_BULK_REASON_CODES as readonly string[]).includes(code)) {
    return t('accounting.bank_bulk.reason.' + code)
  }
  return item.message || code || '—'
}

export const BANK_BULK_CSV_COLUMNS = [
  'col_vs', 'col_date', 'col_description', 'col_amount', 'col_status', 'col_after', 'col_reason',
] as const

export function bankBulkCsvRows(items: BankBulkItem[], t: (key: string) => string): CsvCell[][] {
  return items.map(item => [
    item.document_no,
    item.entry_date,
    item.description,
    csvAmount(item.amount),
    t('accounting.bank_bulk.tab.' + item.status),
    bankBulkAfterText(item, t),
    item.reason_code || item.message ? bankBulkReasonText(item, t) : '',
  ])
}
