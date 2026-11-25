import type { ListFilters } from './invoices'
import type { PurchaseListFilters } from './purchaseInvoices'
import type { JournalFilters } from './accounting'

/**
 * Query parametry výběru seznamů (vydané a přijaté faktury, účetní deník) bez stránkování,
 * řazení a doplňkových sloupců. Seznam, kniha faktur i hromadné akce („všech N podle filtru")
 * je posílají stejně, takže server vybere stejné záznamy, jaké uživatel vidí.
 */
export type ListQuery = Record<string, string | number>

export function invoiceListFilterParams(filters: ListFilters): ListQuery {
  const params: ListQuery = {}
  if (filters.tag_ids) params['filter[tag_ids]'] = filters.tag_ids
  if (filters.q) params.q = filters.q
  if (filters.status) {
    params['filter[status]'] = Array.isArray(filters.status) ? filters.status.join(',') : filters.status
  }
  if (filters.type) {
    params['filter[type]'] = Array.isArray(filters.type) ? filters.type.join(',') : filters.type
  }
  if (filters.client_id)   params['filter[client_id]']   = filters.client_id
  if (filters.project_id)  params['filter[project_id]']  = filters.project_id
  if (filters.year)        params['filter[year]']        = filters.year
  if (filters.month)       params['filter[month]']       = filters.month
  if (filters.date_from)   params['filter[date_from]']   = filters.date_from
  if (filters.date_to)     params['filter[date_to]']     = filters.date_to
  if (filters.currency)    params['filter[currency]']    = filters.currency
  if (filters.unpaid_only) params['filter[unpaid_only]'] = 1
  if (filters.cashflow_draft_unscheduled) params['filter[cashflow_draft_unscheduled]'] = 1
  if (filters.overdue)     params['filter[overdue]']     = 1
  if (filters.unpaid_as_of) params['filter[unpaid_as_of]'] = filters.unpaid_as_of
  if (filters.booked)      params['filter[booked]']      = filters.booked
  if (filters.oss_review)  params['filter[oss_review]']  = filters.oss_review
  if (filters.revenue_category_id?.length) {
    params['filter[revenue_category_id]'] = filters.revenue_category_id.join(',')
  }
  if (filters.revenue_category_exclude?.length) {
    params['filter[revenue_category_exclude]'] = filters.revenue_category_exclude.join(',')
  }
  dimensionFilterParams(params, filters)
  return params
}

/** Filtr dimenze seznamů faktur: stejný tvar jako v účetním deníku, jen v `filter[...]`. */
function dimensionFilterParams(params: ListQuery, filters: { dimension_value_id?: string, dimension_descendants?: boolean }) {
  if (!filters.dimension_value_id) return
  params['filter[dimension_value_id]'] = filters.dimension_value_id
  if (filters.dimension_descendants === false) params['filter[dimension_descendants]'] = 0
}

export function purchaseListFilterParams(filters: PurchaseListFilters): ListQuery {
  const params: ListQuery = {}
  if (filters.q) params.q = filters.q
  if (filters.status) {
    params['filter[status]'] = Array.isArray(filters.status) ? filters.status.join(',') : filters.status
  }
  if (filters.document_kind) {
    params['filter[document_kind]'] = Array.isArray(filters.document_kind)
      ? filters.document_kind.join(',')
      : filters.document_kind
  }
  if (filters.vendor_id)   params['filter[vendor_id]']   = filters.vendor_id
  if (filters.project_id)  params['filter[project_id]']  = filters.project_id
  if (filters.year)        params['filter[year]']        = filters.year
  if (filters.month)       params['filter[month]']       = filters.month
  if (filters.date_from)   params['filter[date_from]']   = filters.date_from
  if (filters.date_to)     params['filter[date_to]']     = filters.date_to
  if (filters.currency)    params['filter[currency]']    = filters.currency
  if (filters.unpaid_only)  params['filter[unpaid_only]']  = 1
  if (filters.overdue)      params['filter[overdue]']      = 1
  if (filters.unpaid_as_of) params['filter[unpaid_as_of]'] = filters.unpaid_as_of
  if (filters.unmatched)    params['filter[unmatched]']    = 1
  if (filters.paid_shortfall) params['filter[paid_shortfall]'] = 1
  if (filters.needs_review) params['filter[needs_review]'] = 1
  if (filters.approval_status) {
    // Smlouva říká jen „filtr approval_status"; ostatní filtry jedou přes filter[...], proto obě podoby.
    params['filter[approval_status]'] = filters.approval_status
    params.approval_status = filters.approval_status
  }
  if (filters.payment_ordered) params['filter[payment_ordered]'] = filters.payment_ordered
  if (filters.booked)      params['filter[booked]']      = filters.booked
  if (filters.has_visual_document !== undefined) params['filter[has_visual_document]'] = filters.has_visual_document
  if (filters.has_structured_source !== undefined) params['filter[has_structured_source]'] = filters.has_structured_source
  if (filters.import_batch_id) params['filter[import_batch_id]'] = filters.import_batch_id
  dimensionFilterParams(params, filters)
  return params
}

export function journalListFilterParams(filters?: JournalFilters): ListQuery {
  const params: ListQuery = {}
  if (filters?.document_no) params.document_no = filters.document_no
  if (filters?.period_id) params.period_id = filters.period_id
  if (filters?.date_from) params.date_from = filters.date_from
  if (filters?.date_to) params.date_to = filters.date_to
  if (filters?.source_type) params.source_type = filters.source_type
  if (filters?.source_id) params.source_id = filters.source_id
  if (filters?.entry_id) params.entry_id = filters.entry_id
  if (filters?.posted !== undefined) params.posted = filters.posted ? '1' : '0'
  if (filters?.reversal) params.reversal = filters.reversal
  if (filters?.automation) params.automation = filters.automation
  if (filters?.q) params.q = filters.q
  if (filters?.account_from) params.account_from = filters.account_from
  if (filters?.account_to) params.account_to = filters.account_to
  if (filters?.amount_from !== undefined) params.amount_from = filters.amount_from
  if (filters?.amount_to !== undefined) params.amount_to = filters.amount_to
  if (filters?.integrity) params.integrity = filters.integrity
  if (filters?.pair_status) params.pair_status = filters.pair_status
  if (filters?.dimension_value_id) {
    params.dimension_value_id = filters.dimension_value_id
    if (filters.dimension_descendants === false) params.dimension_descendants = 0
  }
  return params
}
