import { api } from './client'

export interface PayrollBankSubmission {
  id: number
  payroll_batch_id: number
  connection_id: number
  status: 'accepted_awaiting_authorization' | 'rejected' | 'unknown' | 'import_started'
  provider_reference?: string | null
  accepted_count?: number | null
  rejected_count?: number | null
  submitted_at: string | null
}

export interface PayrollBankSubmissionState {
  submission: PayrollBankSubmission | null
  connections: Array<{ id: number; provider: string; label: string }>
  blocked_reason: string | null
}

export const payrollBankSubmissionsApi = {
  status: (batchId: number) => api.get<PayrollBankSubmissionState>(`/payroll/payments/batches/${batchId}/bank-submission`).then(response => response.data),
  submit: (batchId: number, connectionId: number) => api.post<{ created: boolean; submission: PayrollBankSubmission }>(`/payroll/payments/batches/${batchId}/bank-submission`, { connection_id: connectionId }).then(response => response.data),
}
