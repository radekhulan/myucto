import type { AdvanceDrawdownCandidate, InvoiceAdvanceDrawdownSummary } from '@/api/invoices'

/** Zadání čerpané částky: s DPH, bez DPH, nebo procento z plnění faktury (s DPH). */
export type AdvanceDrawdownMode = 'gross' | 'net' | 'percent'

/** Výchozí procento z plnění faktury (typická záloha). */
export const DEFAULT_DRAWDOWN_PERCENT = 30

const round2 = (n: number): number => Math.round(n * 100) / 100

/**
 * Plnění faktury, které ještě nekryjí odpočty záloh (bez zálohy `exceptProformaId`,
 * jejíž čerpání se právě upravuje), v základu režimu.
 */
export function uncoveredSupply(
  summary: InvoiceAdvanceDrawdownSummary | null | undefined,
  basis: 'gross' | 'net',
  exceptProformaId: number | null = null,
): number {
  if (!summary) return 0
  const key = basis === 'net' ? 'base' : 'gross'
  const covered = summary.drawdowns
    .filter(d => d.proforma_id !== exceptProformaId)
    .reduce((sum, d) => sum + Number(d[key] ?? 0), 0)
  return round2(Number(summary.supply[key] ?? 0) - covered)
}

/**
 * Výchozí částka čerpání zálohy: co z ní koncept čerpá teď, jinak zbytek zálohy, nejvýš
 * plnění faktury, které ještě nekryjí ostatní zálohy. Celý zůstatek zálohy se nenabízí,
 * když převyšuje plnění faktury.
 */
export function defaultDrawdownAmount(
  candidate: AdvanceDrawdownCandidate,
  mode: AdvanceDrawdownMode,
  summary: InvoiceAdvanceDrawdownSummary | null | undefined,
): number {
  if (mode === 'percent') return DEFAULT_DRAWDOWN_PERCENT
  const drawn = Number(mode === 'net' ? (candidate.drawn_base ?? 0) : (candidate.drawn ?? 0))
  if (drawn > 0.005) return round2(drawn)
  const available = Number(mode === 'net' ? (candidate.available_base ?? candidate.available) : candidate.available)
  if (!summary) return round2(available)
  return round2(Math.max(0, Math.min(available, uncoveredSupply(summary, mode, candidate.id))))
}
