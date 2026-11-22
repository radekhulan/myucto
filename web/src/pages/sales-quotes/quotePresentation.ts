import type { QuoteItem, QuoteSection, QuoteStatus, QuoteTotals } from '@/api/salesQuotes'

export const quoteStatusBadge: Record<QuoteStatus, string> = {
  draft: 'bg-neutral-100 text-neutral-700',
  sent: 'bg-primary-50 text-primary-800',
  accepted: 'bg-success-50 text-success-700',
  declined: 'bg-danger-50 text-danger-700',
  expired: 'bg-warning-50 text-warning-700',
  withdrawn: 'bg-neutral-100 text-neutral-700',
}

function roundAmount(value: number): number {
  return Math.round((value + Number.EPSILON) * 100) / 100
}

export function lineTotals(item: QuoteItem, pricesIncludeVat: boolean, reverseCharge = false): QuoteTotals {
  const quantity = item.duration_minutes != null ? item.duration_minutes / 60 : Number(item.quantity)
  const amount = roundAmount(quantity * Number(item.unit_price) * (1 - Number(item.discount_percent) / 100))
  const rate = reverseCharge ? 0 : Number(item.vat_rate) / 100
  const withoutVat = pricesIncludeVat ? roundAmount(amount / (1 + rate)) : amount
  const vat = pricesIncludeVat ? roundAmount(amount - withoutVat) : roundAmount(withoutVat * rate)
  return { without_vat: withoutVat, vat, with_vat: roundAmount(withoutVat + vat) }
}

export function sectionTotals(section: QuoteSection, pricesIncludeVat: boolean, useSaved = false, reverseCharge = false): QuoteTotals {
  return addTotals(section.items.map(item => useSaved && item.totals ? item.totals : lineTotals(item, pricesIncludeVat, reverseCharge)))
}

export function addTotals(rows: QuoteTotals[]): QuoteTotals {
  return {
    without_vat: roundAmount(rows.reduce((sum, row) => sum + Number(row.without_vat), 0)),
    vat: roundAmount(rows.reduce((sum, row) => sum + Number(row.vat), 0)),
    with_vat: roundAmount(rows.reduce((sum, row) => sum + Number(row.with_vat), 0)),
  }
}
