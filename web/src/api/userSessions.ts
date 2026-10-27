import { api } from './client'

export interface UserSession {
  id: string
  user_id: number
  name: string
  email: string | null
  ip: string | null
  user_agent: string | null
  created_at: string
  last_seen: string
  expires_at: string
  locked_at: string | null
  recent_contact: boolean
  is_current: boolean
}

export interface UserSessionPage {
  current_session: UserSession | null
  data: UserSession[]
  next_cursor: string | null
  can_terminate: boolean
}

export const userSessionsApi = {
  list: (cursor?: string | null, signal?: AbortSignal) => api.get<UserSessionPage>('/instance-admin/sessions', {
    params: cursor ? { cursor } : {}, signal,
  }).then(r => r.data),
  terminateSelected: (ids: string[]) => api.post<{ terminated: number; current_session_revoked: boolean }>('/admin/user-sessions/terminate', { ids }).then(r => r.data),
  terminate: (id: string) => api.delete<{ current_session_revoked: boolean }>(`/admin/user-sessions/${encodeURIComponent(id)}`).then(r => r.data),
}
