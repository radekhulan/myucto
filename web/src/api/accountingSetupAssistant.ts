import { api } from './client'

export type SetupJobStatus = 'queued' | 'running' | 'completed' | 'completed_with_warnings' | 'failed' | 'cancelled'
export type ProposalType = 'chart_account' | 'expense_rule' | 'posting_rule' | 'bank_rule' | 'asset_candidate' | 'data_quality'

export interface SetupJob {
  id: number
  source: string
  status: SetupJobStatus
  total_items: number | null
  processed: number
  created_count: number
  skipped_count: number
  failed_count: number
  current_step: string | null
  last_error: string | null
  params: Record<string, unknown>
  created_at: string | null
  finished_at: string | null
  log_text?: string | null
  rollback_snapshot_available?: boolean
}

export interface SetupRun {
  id: number
  job_id: number
  job_status: SetupJobStatus
  input_hash: string | null
  summary_json: {
    documents: number
    items: number
    proposals: number
    unclassified: number
    classification_coverage_pct?: number
    catalog_version: number
    catalog_locales: string[]
    locked_period_documents: number
    ai?: {
      requested: boolean
      status: 'not_requested' | 'skipped' | 'failed' | 'partial' | 'ok'
      error?: string | null
      sample_limit?: number
      samples_sent: number
      requests_sent?: number
      classified_items: number
      proposals: number
      provider?: string | null
      model?: string | null
      validation?: {
        kind_scorable: number
        kind_agreement_pct: number | null
        account_scorable: number
        account_agreement_pct: number | null
      }
    }
    validation?: {
      kind_scorable: number
      kind_agreement_pct: number | null
      account_scorable: number
      account_agreement_pct: number | null
    }
  } | null
  bundle_id: number | null
  bundle_hash: string | null
  total_items: number | null
  processed: number
  current_step: string | null
  last_error: string | null
  created_at: string
  completed_at: string | null
}

export interface SetupProposal {
  id: number
  proposal_type: ProposalType
  title: string
  confidence: number
  occurrence_count: number
  affected_amount: number
  proposal_json: Record<string, unknown>
  evidence_json: Record<string, unknown>
  decision: 'pending' | 'approved' | 'rejected'
}

export interface SetupProposalUpdate {
  name?: string
  create?: boolean
  replacement_account_code?: string | null
  description?: string
  description_contains?: string
  message_contains?: string | null
  expense_kind?: string
  target_account_code?: string
  debit_account_code?: string
  credit_account_code?: string
}

export interface ReclassificationLine {
  account_code: string
  side: 'debit' | 'credit'
  amount: number
}

export interface ReclassificationItem {
  id: number
  purchase_invoice_id: number
  correction_entry_id: number | null
  status: 'pending' | 'unchanged' | 'would_change' | 'applied' | 'skipped' | 'failed'
  error_code: string | null
  error_message: string | null
  before_json: { entry_id?: number; lines?: ReclassificationLine[] } | null
  after_json: { entry_id?: number; lines?: ReclassificationLine[] } | null
}

export const accountingSetupAssistantApi = {
  status: (): Promise<{ runs: SetupRun[]; analysis_jobs: SetupJob[]; reclassification_jobs: SetupJob[]; active_expense_rule_count: number; ai_available: boolean }> =>
    api.get('/accounting/setup-assistant').then(r => r.data),
  startAnalysis: (payload: { date_from?: string | null; date_to?: string | null; use_ai?: boolean; ai_sample_limit?: 50 | 100 | 200 }) =>
    api.post<{ job_id: number }>('/accounting/setup-assistant/analysis', payload).then(r => r.data),
  run: (id: number): Promise<{ run: SetupRun }> =>
    api.get(`/accounting/setup-assistant/runs/${id}`).then(r => r.data),
  proposals: (runId: number): Promise<{ items: SetupProposal[] }> =>
    api.get(`/accounting/setup-assistant/runs/${runId}/proposals`).then(r => r.data),
  updateProposal: (runId: number, proposalId: number, payload: SetupProposalUpdate): Promise<{ proposal: SetupProposal }> =>
    api.put(`/accounting/setup-assistant/runs/${runId}/proposals/${proposalId}`, payload).then(r => r.data),
  approve: (runId: number, proposalIds: number[]): Promise<{ bundle: { id: number; bundle_hash: string } }> =>
    api.post(`/accounting/setup-assistant/runs/${runId}/approve`, { proposal_ids: proposalIds }).then(r => r.data),
  startReclassification: (bundleId: number, dryRun: boolean, dryRunJobId?: number, dateFrom?: string | null, dateTo?: string | null, scopeMode: 'matched' | 'all' = 'matched') =>
    api.post<{ job_id: number }>(`/accounting/setup-assistant/bundles/${bundleId}/reclassification`, {
      dry_run: dryRun,
      dry_run_job_id: dryRunJobId,
      date_from: dateFrom || null,
      date_to: dateTo || null,
      scope_mode: scopeMode,
    }).then(r => r.data),
  job: (id: number): Promise<{ job: SetupJob; run?: SetupRun; items?: ReclassificationItem[] }> =>
    api.get(`/accounting/setup-assistant/jobs/${id}`).then(r => r.data),
  cancel: (id: number): Promise<void> =>
    api.post(`/accounting/setup-assistant/jobs/${id}/cancel`, {}).then(() => undefined),
  rollback: (id: number): Promise<{ job_id: number }> =>
    api.post(`/accounting/setup-assistant/jobs/${id}/rollback`, {}).then(r => r.data),
  deleteSnapshot: (id: number): Promise<{ deleted_items: number }> =>
    api.delete(`/accounting/setup-assistant/jobs/${id}/snapshot`, { data: { confirm: true } }).then(r => r.data),
}
