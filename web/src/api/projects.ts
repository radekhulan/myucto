import { api } from './client'
import type { ListResponse } from './clients'

export type ProjectEmailUsage = 'documents' | 'reminders' | 'approvals'

export interface BillingEmail {
  position: 1 | 2 | 3
  email: string
  label?: string | null
  /** Typy zpráv, pro které se e-mail použije (#86); null = všechny (default). */
  usages?: ProjectEmailUsage[] | null
}

export interface Project {
  id: number
  client_id: number
  name: string
  payment_due_days: number
  /** NULL = dědit jednotku z klienta → dodavatele (PaymentDueResolver). */
  payment_due_unit?: 'days' | 'month' | null
  /** Jen v detailu — jednotka klienta, aby šlo zobrazit skutečně platnou splatnost. */
  client_payment_due_unit?: 'days' | 'month' | null
  project_number?: string | null
  contract_number?: string | null
  budget_total?: number | null
  budget_yearly?: number | null
  budget_monthly?: number | null
  billing_template_id?: number | null
  hourly_rate: number
  currency_id: number
  currency: string
  status: 'active' | 'paused' | 'closed'
  requires_work_report_approval: boolean
  note?: string | null
  default_revenue_category_id?: number | null
  archived_at?: string | null
  invoices_count?: number
  revenue_category_backfilled?: number
  client_company_name?: string
  client_main_email?: string
  billing_emails: BillingEmail[]
  /** Kombinace e-mailů zakázky s kontakty klienta (#86): auto = dosavadní per-typ chování. */
  billing_emails_mode?: 'auto' | 'append' | 'replace'
  created_at?: string
  updated_at?: string
  // Cache stats z project_revenue_cache (per p.currency)
  revenue?: number
  last_invoice_date?: string | null
  invoice_count?: number
  // Per-detail stats — populated by GetProjectAction
  revenue_by_month?: Array<{ month: string; currency: string; total: number }>
  revenue_by_year?:  Array<{ year: number; currency: string; total: number; count: number }>
  unpaid_summary?:   Array<{ currency: string; unpaid_total: number; unpaid_count: number; overdue_total: number; overdue_count: number }>
}

export interface ProjectPayload {
  client_id: number
  name: string
  payment_due_days: number
  payment_due_unit?: 'days' | 'month' | null
  project_number?: string | null
  contract_number?: string | null
  budget_total?: number | null
  budget_yearly?: number | null
  budget_monthly?: number | null
  billing_template_id?: number | null
  hourly_rate: number
  currency_id: number
  status: 'active' | 'paused' | 'closed'
  requires_work_report_approval?: boolean
  note?: string | null
  default_revenue_category_id?: number | null
  billing_emails: BillingEmail[]
  billing_emails_mode?: 'auto' | 'append' | 'replace'
}

export const projectsApi = {
  listForClient: (clientId: number) =>
    api.get<{ data: Project[] }>(`/clients/${clientId}/projects`).then((r) => r.data.data),

  list: (params?: { status?: string; client_id?: number; page?: number; per_page?: number; sort?: 'name' | 'revenue' | 'last_activity' | 'client' }) =>
    api
      .get<ListResponse<Project>>('/projects', {
        params: {
          page: params?.page,
          per_page: params?.per_page,
          sort: params?.sort,
          ...(params?.status ? { 'filter[status]': params.status } : {}),
          ...(params?.client_id ? { 'filter[client_id]': params.client_id } : {}),
        },
      })
      .then((r) => r.data),

  get:    (id: number) => api.get<Project>(`/projects/${id}`).then((r) => r.data),
  create: (p: ProjectPayload) => api.post<Project>('/projects', p).then((r) => r.data),
  update: (id: number, p: Omit<ProjectPayload, 'client_id'>) =>
    api.put<Project>(`/projects/${id}`, p).then((r) => r.data),
  archive: (id: number) => api.post(`/projects/${id}/archive`),
  delete:  (id: number) => api.delete(`/projects/${id}`).then((r) => r.data),

  stats: () => api.get<ProjectStats>('/projects/stats').then(r => r.data),

  /** Výsledovka po zakázkách — výnos, náklad, marže napříč protistranami (issue #29). */
  profitability: (params?: ProjectProfitFilters & { include_archived?: boolean }) =>
    api.get<ProjectProfitOverview>('/projects/profitability', { params }).then(r => r.data),

  /** Ekonomika jedné zakázky + doklady, ze kterých vznikla. */
  profit: (id: number, params?: ProjectProfitFilters) =>
    api.get<ProjectProfitDetail>(`/projects/${id}/profit`, { params }).then(r => r.data),
}

export interface ProjectProfitFilters {
  date_from?: string
  date_to?: string
}

export interface ProjectProfitItem {
  id: number
  name: string
  project_number: string | null
  contract_number: string | null
  status: 'active' | 'paused' | 'closed'
  client_company_name: string | null
  revenue: number
  cost: number
  margin: number
  /** null = zakázka zatím nemá výnos, procento by nedávalo smysl. */
  margin_percent: number | null
  budget_total: number | null
  budget_used_percent: number | null
  /** Doklady se zakázkou, které ještě nejsou zaúčtované (jen v režimu 'journal'). */
  unposted_documents: number
}

/** 'journal' = podvojné účetnictví (čte se deník), 'documents' = daňová evidence. */
export type ProjectProfitSource = 'journal' | 'documents'

export interface ProjectProfitOverview {
  source: ProjectProfitSource
  currency: string
  date_from: string | null
  date_to: string | null
  items: ProjectProfitItem[]
  totals: { revenue: number; cost: number; margin: number }
}

export interface ProjectProfitDocument {
  kind: 'invoice' | 'purchase_invoice' | 'cash_document'
  direction: 'revenue' | 'cost'
  id: number
  number: string | null
  doc_date: string
  partner_name: string | null
  currency: string
  amount: number
  amount_czk: number
  status: string
  booked: boolean
}

export type ProjectProfitDetail = ProjectProfitItem & {
  source: ProjectProfitSource
  currency: string
  date_from: string | null
  date_to: string | null
  documents: ProjectProfitDocument[]
}

export interface ProjectStatsTopItem {
  id: number
  name: string
  client_company_name: string
  revenue: number
  invoice_count: number
}

export interface ProjectStats {
  this_year: number
  prev_year: number
  primary_currency: string
  top_this_year: { top: ProjectStatsTopItem[]; others: { revenue: number; count: number } }
  top_prev_year: { top: ProjectStatsTopItem[]; others: { revenue: number; count: number } }
  top_12m: { top: ProjectStatsTopItem[]; others: { revenue: number; count: number } }
  totals_per_year: Array<{ year: number; currency: string; total: number; invoice_count: number }>
  status_breakdown: Array<{ status: 'active' | 'paused' | 'closed'; count: number }>
  is_vat_payer: boolean
}
