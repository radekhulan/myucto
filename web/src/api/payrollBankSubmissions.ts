import { api } from './client'
import type { BankPaymentSubmissionItem, BankPaymentSubmissionStatus } from './bankConnections'

export interface PayrollBankSubmission {
  id: number
  payroll_batch_id: number
  connection_id: number
  provider?: string
  status: BankPaymentSubmissionStatus
  provider_reference?: string | null
  error_code?: string | null
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
  sign: (batchId: number) => api.post<{ signing_url: string; submission: PayrollBankSubmission }>(`/payroll/payments/batches/${batchId}/bank-submission/sign`).then(response => response.data),
  refreshFromBank: (batchId: number) => api.post<{ submission: PayrollBankSubmission }>(`/payroll/payments/batches/${batchId}/bank-submission/refresh`).then(response => response.data.submission),
  items: (batchId: number) => api.get<{ items: BankPaymentSubmissionItem[] }>(`/payroll/payments/batches/${batchId}/bank-submission/items`).then(response => response.data.items),
}
