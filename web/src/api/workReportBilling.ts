import { api } from './client'
import type { InvoiceItem, WorkReport } from './invoices'

export interface WorkReportBillingBand {
  up_to_hours: number | null
  rate: number
  description: string
}
export interface WorkReportBillingSnapshot {
  name: string
  currency: string
  fixed_fee: number | null
  included_hours: number
  fixed_description: string
  bands: WorkReportBillingBand[]
}
export interface WorkReportBillingTemplate extends WorkReportBillingSnapshot { id: number }
export interface WorkReportBillingPreview {
  items: (Omit<InvoiceItem, 'id' | 'order_index'> & { billing_key: string; total_amount: number; order_index?: number })[]
  total_hours: number
  included_hours: number
  extra_hours: number
  total_amount: number
  fingerprint: string
}
/** Veřejná rekapitulace respektuje skryté hodiny, sazby i částky. */
export interface PublicWorkReportBillingPreview {
  items: { billing_key: string; description: string; quantity: number | null; unit: string; unit_price_without_vat: number | null; total_amount: number | null }[]
  total_hours: number | null
  included_hours: number | null
  extra_hours: number | null
  total_amount: number | null
}
export function newBillingSnapshot(currency = 'CZK'): WorkReportBillingSnapshot {
  return { name: '', currency, fixed_fee: null, included_hours: 0, fixed_description: '{nazev} – {obdobi}', bands: [{ up_to_hours: null, rate: 1500, description: '{nazev} – {od}–{do} h ({obdobi})' }] }
}
export const workReportBillingApi = {
  list: () => api.get<WorkReportBillingTemplate[]>('/work-report-billing-templates').then(r => r.data),
  create: (payload: WorkReportBillingSnapshot) => api.post<WorkReportBillingTemplate>('/work-report-billing-templates', payload).then(r => r.data),
  update: (id: number, payload: WorkReportBillingSnapshot) => api.put<WorkReportBillingTemplate>(`/work-report-billing-templates/${id}`, payload).then(r => r.data),
  delete: (id: number) => api.delete(`/work-report-billing-templates/${id}`),
  previewDraft: (payload: Record<string, unknown>) => api.post<WorkReportBillingPreview>('/work-report-billing-preview', payload).then(r => r.data),
  preview: (invoiceId: number) => api.get<WorkReportBillingPreview>(`/invoices/${invoiceId}/work-report/billing-preview`).then(r => r.data),
  transfer: (invoiceId: number, fingerprint: string, force = false, legacyItemId?: number) => api.post<{ work_report: WorkReport; items: InvoiceItem[]; preview: WorkReportBillingPreview }>(`/invoices/${invoiceId}/work-report/billing-transfer`, { fingerprint, ...(legacyItemId ? { legacy_item_id: legacyItemId } : {}) }, { params: force ? { force: 1 } : undefined }).then(r => r.data),
}
