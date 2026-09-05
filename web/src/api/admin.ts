import { api } from './client'
import type { RoleSummary } from './auth'

export interface ActivityLogEntry {
  id: number
  user_id: number | null
  user_email: string | null
  user_name: string | null
  action: string
  entity_type: string | null
  entity_id: number | null
  payload: Record<string, unknown> | null
  ip: string | null
  created_at: string
}

export interface ActivityLogResponse {
  data: ActivityLogEntry[]
  total: number
  limit: number
  offset: number
  actions: Array<{ action: string; cnt: number }>
}

export interface SentEmail {
  id: number
  action: string
  /** Logický typ e-mailu = success akce (i u failed řádku) — podle něj se vybírá popisek/badge. */
  type: string
  status: 'sent' | 'failed'
  created_at: string
  user_name: string | null
  user_email: string | null
  invoice_id: number | null
  invoice_varsymbol: string | null
  client_company_name: string | null
  recipients: string[]
  smtp_response: string | null
  /** Chybový text u status='failed', jinak null. */
  error: string | null
}

export interface SentEmailsResponse {
  data: SentEmail[]
  total: number
  limit: number
  offset: number
  types: Array<{ action: string; cnt: number; failed: number }>
  failed_total: number
}

/** Jedna jednotná událost z logu poštovního serveru (kind: submission|delivery|notice). */
export interface SmtpLogEvent {
  ts: string
  kind: 'submission' | 'delivery' | 'notice'
  status: 'delivered' | 'queued' | 'deferred' | 'rejected' | 'error' | 'info'
  mail_from: string | null
  recipients: string[]
  remote_host: string | null
  remote_ip: string | null
  code: number | null
  response: string | null
  message_id: string | null
  source_file: string
  session: string
  subject: string | null
  /** Doplněno korelací s activity_log (odeslané e-maily aplikace), jinak null. */
  invoice_id: number | null
  invoice_varsymbol: string | null
}

export interface SmtpLogRecipientRollup {
  recipient: string
  delivered: number
  deferred: number
  rejected: number
  error: number
  last_ts: string
  last_status: string
}

export interface SmtpLogAnalysis {
  enabled: boolean
  reason: string | null
  path?: string
  glob_matched?: number
  connector: { key: string; label: string } | null
  connectors: Array<{ key: string; label: string }>
  window?: { files_total: number; files_parsed: number; limited: boolean }
  scanned: Array<{ file: string; size: number | null; truncated: boolean; events: number }>
  summary: {
    total_events: number
    deliveries: number
    submissions: number
    by_status: Record<string, number>
    by_day: Record<string, Record<string, number>>
    by_host: Record<string, Record<string, number>>
    recipients: SmtpLogRecipientRollup[]
    problems: SmtpLogEvent[]
  }
  events: SmtpLogEvent[]
  total: number
  limit: number
  offset: number
}

/** SMTP analýza vázaná na fakturu (box v detailu faktury). */
export interface InvoiceSmtpLog {
  enabled: boolean
  sent: boolean
  connector: { key: string; label: string } | null
  sends: Array<{ ts: string; recipients: string[]; action: string }>
  recipients: Array<{
    recipient: string
    delivered: number
    deferred: number
    rejected: number
    error: number
    last_status: string | null
    last_ts: string
  }>
  events: SmtpLogEvent[]
}

export interface AdminUser {
  id: number
  email: string
  name: string
  role_id: number
  role: RoleSummary
  is_superadmin?: boolean
  locale: 'cs' | 'en'
  is_active: boolean
  created_at: string
  last_login_at: string | null
}

/** Epic F0 — membership uživatel ↔ firma (user_suppliers). role null = zdědit globální users.role. */
export interface UserSupplierAssignment {
  supplier_id: number
  name: string
  ic: string | null
  role_id: number | null
  effective_role: RoleSummary
}

export interface AdminSupplierSearchItem {
  id: number
  name: string
  ic: string | null
}

export interface AdminSupplierSearchResponse {
  data: AdminSupplierSearchItem[]
  next_cursor: string | null
}

export interface AdminBankRuleTemplate {
  id: number
  template_key: string
  name_cs: string
  name_en: string
  direction: 'incoming' | 'outgoing'
  operation_type: string
  counterparty_bank: string | null
  counterparty_prefix: string | null
  vs_placeholder: string | null
  message_contains: string | null
  rule_key: string
  debit_account_code: string | null
  credit_account_code: string | null
  default_priority: number
  sort_order: number
  is_active: boolean
  usage_count: number
}

export type AdminBankRuleTemplatePayload = Omit<
  AdminBankRuleTemplate,
  'id' | 'debit_account_code' | 'credit_account_code' | 'usage_count'
>

export interface AdminBankRuleTemplateCatalog {
  templates: AdminBankRuleTemplate[]
  operation_types: string[]
  posting_rules: Array<{
    rule_key: string
    description: string
    debit_account_code: string | null
    credit_account_code: string | null
  }>
}

export const adminApi = {
  activityLog: (params: { action?: string; user_id?: number; entity_type?: string; entity_id?: number; limit?: number; offset?: number } = {}) =>
    api.get<ActivityLogResponse>('/admin/activity-log', { params }).then(r => r.data),

  sentEmails: (params: { type?: string; status?: 'sent' | 'failed'; limit?: number; offset?: number } = {}) =>
    api.get<SentEmailsResponse>('/admin/sent-emails', { params }).then(r => r.data),

  smtpLogAnalysis: (params: { date_from?: string; date_to?: string; status?: string; kind?: string; search?: string; limit?: number; offset?: number } = {}) =>
    api.get<SmtpLogAnalysis>('/admin/smtp-log-analysis', { params }).then(r => r.data),

  smtpLogStatus: () =>
    api.get<{ enabled: boolean }>('/admin/smtp-log-analysis/status').then(r => r.data),

  invoiceSmtpLog: (id: number) =>
    api.get<InvoiceSmtpLog>(`/admin/invoices/${id}/smtp-log`).then(r => r.data),

  // Users
  listUsers: () => api.get<AdminUser[]>('/admin/users').then(r => r.data),
  createUser: (payload: { email: string; name: string; role_id: number; locale?: 'cs' | 'en'; password: string }) =>
    api.post<AdminUser>('/admin/users', payload).then(r => r.data),
  updateUser: (id: number, payload: Partial<{ name: string; role_id: number; locale: 'cs' | 'en'; is_active: boolean; password: string }>) =>
    api.put<AdminUser>(`/admin/users/${id}`, payload).then(r => r.data),
  deleteUser: (id: number) => api.delete(`/admin/users/${id}`),
  // Epic F0 — přiřazení firem uživateli (prázdné = bez omezení, vidí všechny firmy)
  listUserSuppliers: (id: number) =>
    api.get<UserSupplierAssignment[]>(`/admin/users/${id}/suppliers`).then(r => r.data),
  setUserSuppliers: (id: number, assignments: Array<{ supplier_id: number; role_id: number | null }>) =>
    api.put<UserSupplierAssignment[]>(`/admin/users/${id}/suppliers`, { assignments }).then(r => r.data),
  searchSuppliers: (params: { q?: string; limit?: number; cursor?: string } = {}) =>
    api.get<AdminSupplierSearchResponse>('/admin/suppliers/search', { params }).then(r => r.data),

  // Approvals inbox
  listApprovals: (params: { status?: 'requested' | 'approved' | 'rejected' | 'all'; overdue_days?: number; page?: number; per_page?: number } = {}) =>
    api.get<ApprovalListResponse>('/admin/approvals', { params }).then(r => r.data),

  // Email templates
  listEmailTemplates: () =>
    api.get<{ data: EmailTemplateListItem[] }>('/admin/email-templates').then(r => r.data.data),
  getEmailTemplate: (code: string, locale: string) =>
    api.get<EmailTemplate>(`/admin/email-templates/${code}/${locale}`).then(r => r.data),
  saveEmailTemplate: (code: string, locale: string, payload: { subject: string; body_html: string; body_text: string }) =>
    api.put(`/admin/email-templates/${code}/${locale}`, payload),
  resetEmailTemplate: (code: string, locale: string) =>
    api.delete(`/admin/email-templates/${code}/${locale}`),

  // Firemní šablony bankovních pravidel
  listBankRuleTemplates: () =>
    api.get<AdminBankRuleTemplateCatalog>('/admin/bank-rule-templates').then(r => r.data),
  createBankRuleTemplate: (payload: AdminBankRuleTemplatePayload) =>
    api.post<AdminBankRuleTemplate>('/admin/bank-rule-templates', payload).then(r => r.data),
  updateBankRuleTemplate: (id: number, payload: AdminBankRuleTemplatePayload) =>
    api.put<AdminBankRuleTemplate>(`/admin/bank-rule-templates/${id}`, payload).then(r => r.data),
  deleteBankRuleTemplate: (id: number) =>
    api.delete<{ deleted: boolean }>(`/admin/bank-rule-templates/${id}`).then(r => r.data),

  // Cron jobs (Systém → Plánované úlohy)
  cronJobs: (signal?: AbortSignal) =>
    api.get<CronJobsResponse>('/admin/cron-jobs', { signal }).then(r => r.data),
  runCronJob: (script: string) =>
    api.post<{ script: string; started: boolean }>(`/admin/cron-jobs/${encodeURIComponent(script)}/run`).then(r => r.data),
  setCronScheduleMode: (mode: CronScheduleMode) =>
    api.put<SetCronScheduleModeResponse>('/admin/cron-jobs/schedule-mode', { mode }).then(r => r.data),

  // Ukázková (sample) data — stav + odebrání (issue #162)
  sampleDataStatus: () =>
    api.get<SampleDataStatus>('/maintenance/sample-data').then(r => r.data),
  deleteSampleData: () =>
    api.delete<{ deleted: Record<string, number> }>('/maintenance/sample-data').then(r => r.data),
}

export interface SampleDataStatus {
  has: boolean
  total: number
  counts: Partial<Record<
    | 'client' | 'vendor' | 'project' | 'invoice' | 'credit_note' | 'purchase_invoice'
    | 'recurring_template' | 'car' | 'journal_entry' | 'bank_statement'
    | 'supplier_bank_account' | 'asset' | 'stock_document' | 'stock_item' | 'warehouse'
    | 'cash_document' | 'cash_register' | 'manufacturer' | 'stock_category',
    number
  >>
}

/**
 * `idle` = úloha se v režimu dispatcheru nespouští, protože pro ni není práce.
 * Není to problém — zdraví se u ní posuzuje podle heartbeatu dispatcheru
 * (`health_source: 'dispatcher'`).
 *
 * `pending` = nikdy neběžela, ale instalace ještě nestihla ani jednu její
 * periodu (`max_age_hours`) — čerstvý stav, ne poplach. Teprve když instalace
 * periodu přeroste a heartbeat pořád chybí, přepne se na `never_ran`.
 *
 * `disabled` = úlohu výslovně vypnula konfigurace instalace (`cron.disabled_jobs`,
 * typicky spravovaný hosting) — záměr, ne porucha (`health_source: 'config'`).
 */
export type CronJobHealth =
  | 'ok' | 'idle' | 'pending' | 'overdue' | 'failing' | 'overdue_and_failing' | 'never_ran' | 'disabled'

export interface CronJob {
  script: string
  recommended: string
  linux_cron: string
  windows_schtasks: string
  weekdays_only: boolean
  critical: boolean
  max_age_hours: number
  health: CronJobHealth
  /** Z čeho stav vychází: vlastní heartbeat úlohy, heartbeat dispatcheru, nebo konfigurace instalace. */
  health_source?: 'self' | 'dispatcher' | 'config'
  last_started_at: string | null
  last_finished_at: string | null
  last_status: 'running' | 'ok' | 'error' | null
  last_duration_ms: number | null
  last_exit_code: number | null
  last_host: string | null
  last_message: string | null
  last_report: Record<string, unknown> | null
  last_ok_started_at: string | null
  last_ok_finished_at: string | null
  age_sec_since_ok: number | null
  /** Běhy, které něco udělaly nebo selhaly — prázdné ticky se do historie nezapisují. */
  counts_24h: { ok: number; error: number; total: number }
  /** Poslední tick jakéhokoli výsledku (i prázdný) — důkaz, že cron žije. */
  last_tick_at?: string | null
  /** Poslední tick, který reálně něco udělal. */
  last_work_at?: string | null
  /** Kolikrát se úloha probudila a neměla co dělat. */
  noop_ticks?: number
  /** Je to sám plánovač (režim dispatcher)? */
  is_dispatcher?: boolean
  /** Má ji admin registrovat do crontabu sám? V režimu dispatcher ne. */
  scheduled_directly?: boolean
}

/**
 * individual — 20 samostatných položek v crontabu / Task Scheduleru (default)
 * dispatcher — jediná položka `cron-dispatch` každou minutu, která spouští zbytek
 */
export type CronScheduleMode = 'individual' | 'dispatcher'

export interface CronScheduleContext {
  mode: CronScheduleMode
  modes: CronScheduleMode[]
  dispatcher_script: string
  individual_count: number
  /** Přepnutí režimu samo nic nepřeplánuje — crontab se musí vygenerovat znovu. */
  requires_replan: boolean
}

export interface SetCronScheduleModeResponse {
  mode: CronScheduleMode
  previous_mode: CronScheduleMode
  changed: boolean
  requires_replan: boolean
  next_step: string
}

/** Skutečné cesty běžícího nasazení — návod na plánování úloh se z nich sestaví. */
export interface CronInstallContext {
  project_root: string
  cmd_dir: string
  log_dir: string
  os_family: string
  is_docker: boolean
  data_dir: string | null
  php_binary: string
  docker_managed: boolean
}

export interface CronJobsResponse {
  jobs: CronJob[]
  server_time: string
  install?: CronInstallContext
  schedule?: CronScheduleContext
}

export interface ApprovalListMeta {
  total: number
  page: number
  per_page: number
  pages: number
  status_counts?: { all: number; requested: number; approved: number; rejected: number }
}

export interface ApprovalListResponse {
  data: ApprovalInboxItem[]
  meta: ApprovalListMeta
}

export interface ApprovalInboxItem {
  id: number
  varsymbol: string | null
  invoice_type: 'invoice' | 'proforma' | 'credit_note' | 'cancellation'
  status: string
  client_id: number
  project_id: number | null
  client_company_name: string
  client_main_email: string | null
  project_name: string | null
  currency: string
  total_with_vat: number
  amount_to_pay: number
  approval_status: 'none' | 'requested' | 'approved' | 'rejected'
  approval_token: string | null
  approval_token_expires_at: string | null
  approval_requested_at: string | null
  approval_decided_at: string | null
  approval_decided_by_email: string | null
  approval_rejection_reason: string | null
  approval_reminder_at: string | null
  approval_reminder_count: number
}

export interface EmailTemplateListItem {
  code: string
  locale: 'cs' | 'en'
  has_override: boolean
  updated_at: string | null
}

export interface EmailTemplate {
  code: string
  locale: 'cs' | 'en'
  subject: string
  body_html: string
  body_text: string
  has_override: boolean
  updated_at: string | null
  defaults: { subject: string; body_html: string; body_text: string }
}
