import type { RouteLocationRaw } from 'vue-router'

/** Proklik na zdrojový doklad položky časového rozlišení (nebo null, když zdroj nemá detail). */
export function accrualSourceRoute(type: string | null | undefined, id: number | null | undefined): RouteLocationRaw | null {
  if (!id) return null
  if (type === 'invoice') return { name: 'invoice-detail', params: { id } }
  if (type === 'purchase_invoice') return { name: 'purchase-invoice-detail', params: { id } }
  if (type === 'other_item') return { name: 'other-item-detail', params: { id } }
  if (type === 'internal_document') return { name: 'accounting-internal-document-detail', params: { id } }
  return null
}

/** Předvyplnění editoru nové položky z query (z dokladu nebo z řádku 38x bez položky). */
export interface AccrualPrefill {
  source_type?: string
  source_id?: number
  origin_entry_id?: number
  origin_amount?: number
  account?: string
  amount?: number
  from?: string
  to?: string
  partner_id?: number
  partner_name?: string
  title?: string
}

/** Query pro editor nové položky; prázdné hodnoty vynechá. */
export function accrualPrefillQuery(prefill: AccrualPrefill): Record<string, string> {
  const query: Record<string, string> = {}
  for (const [key, value] of Object.entries(prefill)) {
    if (value === undefined || value === null || value === '') continue
    query[key] = String(value)
  }
  return query
}
