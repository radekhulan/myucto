import type { WidgetInstance, WidgetResult } from './types'
/** Úvodní průvodce závisí na dokladech, nikoliv na dostupných akcích nebo termínech. */
export function hasBusinessActivity(results: Record<string, WidgetResult>, instances: WidgetInstance[]): boolean {
  return instances.some(instance => {
    const result = results[instance.id]
    if (result?.status !== 'ok' || !result.data) return false
    const data = result.data
    if (instance.type === 'business.kpi' || instance.type === 'business.monthly') return (data.rows ?? []).some(row => ['invoice_count','purchase_count','invoiced','costs'].some(key => Number((row as unknown as Record<string, unknown>)[key] ?? 0) !== 0))
    if (instance.type === 'sales.summary') return Number(data.issued_count_ytd ?? 0) > 0 || Number(data.overdue_count ?? 0) > 0
    if (instance.type === 'purchases.summary') return data.has_purchase_invoices === true || ['purchase_count_ytd','purchase_unpaid_count','purchase_costs_ytd'].some(key => Number(data[key] ?? 0) !== 0)
    if (instance.type.startsWith('invoices.') && ['invoices.overdue','invoices.upcoming','invoices.drafts'].includes(instance.type)) return (data.items?.length ?? 0) > 0 || Number(data.total ?? 0) > 0
    return false
  })
}
