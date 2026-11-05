import { api } from './client'

export interface OtherItemSchedule {
  id: number
  source_item_id: number | null
  frequency: 'monthly' | 'quarterly' | 'yearly'
  anchor_on: string
  due_days: number
  ends_on: string | null
  next_index: number
  status: 'active' | 'paused'
  auto_post: boolean
  template: Record<string, unknown>
  occurrences?: Array<{ occurrence_index: number; item_id: number; issued_on: string; status: string }>
}

export type OtherItemInstallmentStatus = 'unpaid' | 'partial' | 'paid' | 'overdue'

export interface OtherItemInstallment {
  id?: number
  position?: number
  due_on: string
  amount: number
  /** Jen uložené splátky: uhrazeno, zbývá a stav podle přiřazených úhrad. */
  paid_amount?: number
  remaining_amount?: number
  status?: OtherItemInstallmentStatus
}

export const otherItemPlansApi = {
  schedules: () => api.get<{ items: OtherItemSchedule[] }>('/accounting/other-items/schedules').then(r => r.data.items),
  schedule: (id: number) => api.get<OtherItemSchedule>(`/accounting/other-items/schedules/${id}`).then(r => r.data),
  createSchedule: (itemId: number, payload: { frequency: OtherItemSchedule['frequency']; ends_on: string | null; auto_post?: boolean }) =>
    api.post<OtherItemSchedule>(`/accounting/other-items/${itemId}/schedule`, payload).then(r => r.data),
  generate: (id: number, through: string) =>
    api.post<{ created_ids: number[]; posted_ids: number[]; schedule: OtherItemSchedule }>(`/accounting/other-items/schedules/${id}/generate`, { through }).then(r => r.data),
  setStatus: (id: number, status: OtherItemSchedule['status'], auto_post?: boolean) =>
    api.put<OtherItemSchedule>(`/accounting/other-items/schedules/${id}/status`, { status, ...(auto_post === undefined ? {} : { auto_post }) }).then(r => r.data),
  deleteSchedule: (id: number) => api.delete(`/accounting/other-items/schedules/${id}`).then(() => undefined),
  installments: (itemId: number) => api.get<{ items: OtherItemInstallment[] }>(`/accounting/other-items/${itemId}/installments`).then(r => r.data.items),
  setInstallments: (itemId: number, items: OtherItemInstallment[]) =>
    api.put<{ items: OtherItemInstallment[] }>(`/accounting/other-items/${itemId}/installments`, { items }).then(r => r.data.items),
}
