import { api } from './client'
import type { MigrationNonprofitNotice } from './migrationApi'

export interface AbraFlexiConnection {
  nonprofit_notice?: MigrationNonprofitNotice
  configured: boolean
  imported: boolean
  years: Array<{ year: number; starts_on: string; ends_on: string }>
  default_years: number[]
  selected_years: number[]
  last_synced_at: string | null
  catalog: { last_synced_at: string; source_rows: number } | null
  active_job_id: number | null
  warnings: string[]
  source_company: { name: string; ico: string } | null
  target_company?: { name: string; ico: string }
  ico_mismatch?: boolean
}

export interface AbraFlexiCredentials {
  url: string
  username: string
  password: string
}

export interface AbraFlexiRun {
  id: number
  job_id: number
  status: string
  protocol?: {
    warnings?: string[]
    blocked?: boolean
    reconciliation?: { ok?: boolean; warnings?: string[]; years?: Array<{ year: number; ok: boolean }> }
    counts?: Record<string, number>
    report?: {
      warnings?: string[]
      blockers?: string[]
      blocked?: boolean
      reconciliation?: { ok?: boolean; warnings?: string[]; years?: Array<{ year: number; ok: boolean }> }
    }
  } | null
}

const BASE = '/admin/imports/abra-flexi'

export const abraFlexiApi = {
  connection: async () => (await api.get<AbraFlexiConnection>(`${BASE}/connection`)).data,
  save: async (credentials: AbraFlexiCredentials) => (await api.put<AbraFlexiConnection>(`${BASE}/connection`, credentials)).data,
  remove: async () => { await api.delete(`${BASE}/connection`) },
  discover: async () => (await api.post<AbraFlexiConnection>(`${BASE}/discover`, {})).data,
  start: async (years: number[]) => (await api.post<{ job_id: number }>(`${BASE}/start`, { years })).data,
  sync: async () => (await api.post<{ job_id: number }>(`${BASE}/sync`, {})).data,
  catalog: async () => (await api.post<{ job_id: number }>(`${BASE}/catalog`, {})).data,
  runs: async () => (await api.get<{ items: AbraFlexiRun[] }>(`${BASE}/runs`)).data.items,
  run: async (id: number) => (await api.get<AbraFlexiRun>(`${BASE}/runs/${id}`)).data,
}
