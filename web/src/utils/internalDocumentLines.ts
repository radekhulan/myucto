import type { JournalSide } from '@/api/accounting'
import type { DimensionMap } from '@/api/dimensions'

/** Řádek rychlého zadání interního dokladu (částka může být rozepsaná/prázdná). */
export interface DraftLine {
  account_code: string
  side: JournalSide
  amount: number | null
  is_red_storno?: boolean
  cost_center: string
  dimensions: DimensionMap
}

export function emptyLine(side: JournalSide = 'debit', account = ''): DraftLine {
  return { account_code: account, side, amount: null, is_red_storno: false, cost_center: '', dimensions: {} }
}

function cents(value: number | null | undefined): number {
  const n = Number(value)
  return Number.isFinite(n) ? Math.round(n * 100) : 0
}

function signed(line: DraftLine, amount: number | null = line.amount): number {
  return cents(amount) * (line.is_red_storno ? -1 : 1)
}

function hasAmount(line: DraftLine): boolean {
  return line.amount !== null && Number(line.amount) > 0
}

/**
 * Dorovnávací řádek: právě jeden řádek s vyplněným účtem a prázdnou částkou.
 * Jeho částka se dopočítá z ostatních, aby účetní u zápočtu nebo přeúčtování
 * psala částku jen jednou.
 */
export function autoBalanceIndex(lines: DraftLine[]): number | null {
  const open = lines
    .map((line, index) => ({ line, index }))
    .filter(({ line }) => !hasAmount(line))
  if (open.length !== 1 || !open[0].line.account_code.trim()) return null
  return open[0].index
}

/** Částka dorovnávacího řádku, nebo null, když ho nelze kladnou částkou vyrovnat. */
export function autoBalanceAmount(lines: DraftLine[]): number | null {
  const index = autoBalanceIndex(lines)
  if (index === null) return null
  let debit = 0
  let credit = 0
  lines.forEach((line, i) => {
    if (i === index) return
    if (line.side === 'debit') debit += signed(line)
    else credit += signed(line)
  })
  const target = lines[index]
  const missing = (target.side === 'debit' ? credit - debit : debit - credit) * (target.is_red_storno ? -1 : 1)
  return missing > 0 ? missing / 100 : null
}

/** Řádky s doplněnou částkou dorovnávacího řádku (pro uložení i součty). */
export function resolvedLines(lines: DraftLine[]): DraftLine[] {
  const index = autoBalanceIndex(lines)
  const amount = autoBalanceAmount(lines)
  if (index === null || amount === null) return lines
  return lines.map((line, i) => (i === index ? { ...line, amount } : line))
}

export function lineTotals(lines: DraftLine[]): { debit: number; credit: number; diff: number } {
  let debit = 0
  let credit = 0
  for (const line of resolvedLines(lines)) {
    if (line.side === 'debit') debit += signed(line)
    else credit += signed(line)
  }
  return { debit: debit / 100, credit: credit / 100, diff: (debit - credit) / 100 }
}

/** MD = D a každý řádek má účet a kladnou částku (po dorovnání). */
export function isBalanced(lines: DraftLine[]): boolean {
  const resolved = resolvedLines(lines)
  if (resolved.length < 2 || resolved.some(line => !line.account_code.trim() || !hasAmount(line))) return false
  return lineTotals(lines).diff === 0
}

/** Řádek, který rozdíl stran dorovná (tlačítko „Dorovnat"). */
export function balancingLine(lines: DraftLine[]): DraftLine | null {
  if (autoBalanceIndex(lines) !== null) return null
  const { diff } = lineTotals(lines)
  if (diff === 0) return null
  return { ...emptyLine(diff > 0 ? 'credit' : 'debit'), amount: Math.abs(diff) }
}
