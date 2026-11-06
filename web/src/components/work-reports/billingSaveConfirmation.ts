import type { InvoiceItem } from '@/api/invoices'
import type { WorkReportBillingPreview, WorkReportBillingSnapshot } from '@/api/workReportBilling'

interface BillingSaveState {
  snapshot: WorkReportBillingSnapshot | null
  stale: boolean
  pending: boolean
  reportId: number | null
  preview: Pick<WorkReportBillingPreview, 'items'> | null
  invoiceItems: readonly InvoiceItem[]
}

/** Uložení výkazu nepřenáší cenu samo; varujeme i po ruční změně navázaných řádků. */
export function billingSaveNeedsConfirmation(state: BillingSaveState): boolean {
  if (!state.snapshot || state.pending) return false
  if (state.stale || state.reportId == null || state.preview == null) return true
  const actual = state.invoiceItems.filter(item => item.linked_work_report_id === state.reportId && item.work_report_billing_key != null)
  if (actual.length !== state.preview.items.length) return true
  const byKey = new Map(actual.map(item => [item.work_report_billing_key, item]))
  if (byKey.size !== actual.length) return true
  return state.preview.items.some(expected => {
    const item = byKey.get(expected.billing_key)
    if (!item || item.item_kind === 'text' || item.item_kind === 'discount' || item.description !== expected.description || item.unit !== expected.unit) return true
    if ((item.duration_minutes ?? null) !== (expected.duration_minutes ?? null)) return true
    // Přesné časové řádky mají množství odvozené z minut, uložené desetinné hodiny jsou jen kompatibilita.
    if (expected.duration_minutes == null && Math.abs(Number(item.quantity) - Number(expected.quantity)) > 0.000001) return true
    if (Math.abs(Number(item.unit_price_without_vat) - Number(expected.unit_price_without_vat)) > 0.000001) return true
    // OSS může vybrat jiný číselníkový řádek stejné sazby; rozhoduje uložené procento.
    return item.vat_rate_snapshot != null && expected.vat_rate_snapshot != null
      ? Math.abs(Number(item.vat_rate_snapshot) - Number(expected.vat_rate_snapshot)) > 0.000001
      : (item.vat_rate_id ?? null) !== (expected.vat_rate_id ?? null)
  })
}
