import type { RouteLocationRaw } from 'vue-router'

/**
 * Položky projekce uzávěrky (ClosingProjectionCalculator) — popisek a stránka, ze které
 * částka pochází. Sdílí je náhled DPPO i odhad do konce roku ve výsledovce po účtech,
 * literály klíčů drží jmenný prostor `taxReturn` v mapě překladů obou stránek.
 */
export const CLOSING_PROJECTION_LABELS: Record<string, string> = {
  small_asset_accrual: 'taxReturn.proj_small_asset',
  prepaid_expense_accrual: 'taxReturn.proj_prepaid',
  deferred_revenue_accrual: 'taxReturn.proj_deferred_revenue',
  fx_revaluation: 'taxReturn.proj_fx',
  prior_deferral_release: 'taxReturn.proj_prior_release',
  accrual_agenda_release: 'taxReturn.proj_accrual_agenda',
  provision: 'taxReturn.proj_provision',
  estimate: 'taxReturn.proj_estimate',
  stock_closing: 'taxReturn.proj_stock',
  depreciation: 'taxReturn.proj_depreciation',
}

export function closingProjectionLabelKey(item: { key: string; label_key: string }): string {
  return CLOSING_PROJECTION_LABELS[item.key] ?? item.label_key
}

/** Odkaz na zdroj položky: odpisy vedou na majetek, rozpuštění agendy na Časové rozlišení, ostatní na uzávěrku období. */
export function closingProjectionSource(key: string, periodId: number | null | undefined): { to: RouteLocationRaw; labelKey: string } | null {
  if (key === 'depreciation') {
    return { to: { name: 'accounting-assets' }, labelKey: 'taxReturn.proj_source_assets' }
  }
  if (key === 'accrual_agenda_release') {
    return { to: { name: 'accounting-accruals' }, labelKey: 'taxReturn.proj_source_accruals' }
  }
  if (!periodId) return null
  return { to: { name: 'accounting-period-closing', params: { id: periodId } }, labelKey: 'taxReturn.proj_source_closing' }
}

/** Krok uzávěrky, jehož náhled selhal a v projekci chybí (`closing_projection.warnings`). */
export interface ClosingProjectionWarning {
  source: string
  reason: string
}

/** Zdroj varování (krok náhledu) → položka projekce, jejíž popisek se zobrazí. */
const CLOSING_PROJECTION_WARNING_ITEMS: Record<string, string> = {
  small_asset: 'small_asset_accrual',
  prepaid: 'prepaid_expense_accrual',
  deferred_revenue: 'deferred_revenue_accrual',
  fx: 'fx_revaluation',
  prior_release: 'prior_deferral_release',
  accrual_agenda: 'accrual_agenda_release',
  provisions: 'provision',
  estimates: 'estimate',
  stock: 'stock_closing',
  depreciation: 'depreciation',
}

export function closingProjectionWarningLabelKey(source: string): string | null {
  const item = CLOSING_PROJECTION_WARNING_ITEMS[source]
  return item ? (CLOSING_PROJECTION_LABELS[item] ?? null) : null
}
