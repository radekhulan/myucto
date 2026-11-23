import { api } from './client'

export type MigrationCheckStatus = 'ok' | 'fixable' | 'review' | 'error'
export type MigrationCheckItemState = 'fixable' | 'locked' | 'review'
export type MigrationCheckEntity = 'invoice' | 'purchase_invoice' | 'bank_statement' | 'account' | 'other_item'

export interface MigrationCheckSummary {
  key: string
  title: string
  fixable: boolean
  manual: string
  status: MigrationCheckStatus
  count: number
  fixable_count: number
  locked_count: number
  review_count: number
  amount_czk: number
  fixable_amount_czk: number
  notes: string[]
}

export interface MigrationCheckOverview {
  sources: string[]
  checks: MigrationCheckSummary[]
  can_fix: boolean
}

export interface MigrationCheckItem {
  entity_type: MigrationCheckEntity
  entity_id: number | null
  label: string
  date: string | null
  amount_czk: number | null
  amount: number | null
  currency: string | null
  state: MigrationCheckItemState
  reason: string
  details: Record<string, string | number | boolean | null>
}

export interface MigrationCheckDetail extends MigrationCheckSummary {
  options: { key: string; label: string }[]
  items: MigrationCheckItem[]
  pagination: { page: number; per_page: number; total: number }
  can_fix: boolean
}

export interface MigrationCheckPreview {
  key: string
  title: string
  can_fix: boolean
  blocked: string | null
  fingerprint: string
  count: number
  amount_czk: number
  changes: MigrationCheckItem[]
  skipped: MigrationCheckItem[]
  notes: string[]
}

export interface MigrationCheckFixResult {
  key: string
  fixed_count: number
  amount_czk: number
  skipped_count: number
  notes: string[]
  after: MigrationCheckSummary
}

export type MigrationCheckOptions = Record<string, boolean>

export const migrationChecksApi = {
  overview: () => api.get<MigrationCheckOverview>('/accounting/migration-checks').then(r => r.data),
  detail: (key: string, params: { page?: number; per_page?: number; state?: MigrationCheckItemState } = {}) =>
    api.get<MigrationCheckDetail>(`/accounting/migration-checks/${encodeURIComponent(key)}`, { params }).then(r => r.data),
  preview: (key: string, options: MigrationCheckOptions = {}) =>
    api.post<MigrationCheckPreview>(`/accounting/migration-checks/${encodeURIComponent(key)}/preview`, { options }).then(r => r.data),
  fix: (key: string, fingerprint: string, options: MigrationCheckOptions = {}) =>
    api.post<MigrationCheckFixResult>(`/accounting/migration-checks/${encodeURIComponent(key)}/fix`, { confirm: true, fingerprint, options }).then(r => r.data),
}

/** Proklik z nálezu na doklad, výpis nebo saldokonto účtu. */
export function migrationCheckItemLink(item: MigrationCheckItem): string | { path: string; query: Record<string, string> } | null {
  if (item.entity_type === 'account') {
    const d = item.details
    if (typeof d.period_id === 'number' && typeof d.account === 'string') {
      return { path: '/accounting/saldo', query: { period_id: String(d.period_id), account: d.account, ...(typeof d.as_of === 'string' ? { as_of: d.as_of } : {}) } }
    }
    return '/accounting/general-ledger'
  }
  if (item.entity_id === null) return null
  switch (item.entity_type) {
    case 'invoice': return `/invoices/${item.entity_id}`
    case 'purchase_invoice': return `/purchase-invoices/${item.entity_id}`
    case 'bank_statement': return `/bank/${item.entity_id}`
    case 'other_item': return `/other-items/${item.entity_id}`
    default: return null
  }
}
