import { api } from './client'

export interface InstanceUpdateStatus {
  current: string
  latest: string | null
  has_update: boolean
  last_check_at: string | null
  cache_stale: boolean
  upgrade_in_progress: boolean
  check_failed: boolean
  other_recent_sessions: number
  managed: boolean
  upgrade_progress: { step_index: number; step_count: number } | null
  last_upgrade_result: { status: string; target_version?: string; applied_at?: string } | null
}

export const instanceUpdatesApi = {
  check: () => api.post<InstanceUpdateStatus>('/instance-admin/updates/check').then(r => r.data),
  trigger: (version: string, confirmActiveSessions = false) => api.post<{ status: string }>('/instance-admin/updates/trigger', { target_version: version, confirm_active_sessions: confirmActiveSessions }).then(r => r.data),
  status: (signal?: AbortSignal) => api.get<InstanceUpdateStatus>('/instance-admin/updates', { signal }).then(r => r.data),
}
