import { api } from './client'
import type { SsoProvider } from './auth'

export interface ExternalIdentity {
  id: number
  provider: string
  email: string | null
  display_name: string | null
  linked_by: 'self' | 'admin' | 'invite'
  created_at: string
  last_used_at: string | null
}

/** Ověření citlivé operace: proof ze step-upu, nebo heslo (+ TOTP kód). */
export interface ReauthFields {
  step_up_token?: string
  password?: string
  totp_code?: string
}

export const oidcApi = {
  identities: () =>
    api.get<{ identities: ExternalIdentity[] }>('/auth/oidc/identities').then(r => r.data.identities),
  link: (provider: string, returnTo: string, reauth: ReauthFields) =>
    api.post<{ url: string }>(`/auth/oidc/${encodeURIComponent(provider)}/link`, { return_to: returnTo, ...reauth })
      .then(r => r.data.url),
  unlink: (id: number, reauth: ReauthFields) =>
    api.delete<{ ok: true }>(`/auth/oidc/identities/${id}`, { data: reauth }).then(r => r.data),
  stepUp: (provider: string, operation: string, returnTo: string) =>
    api.post<{ url: string }>(`/auth/oidc/${encodeURIComponent(provider)}/step-up`, { operation, return_to: returnTo })
      .then(r => r.data.url),

  adminIdentities: (userId: number) =>
    api.get<{ identities: ExternalIdentity[]; providers: SsoProvider[] }>(`/admin/users/${userId}/identities`)
      .then(r => r.data),
  adminInvite: (userId: number, provider: string) =>
    api.post<{ ok: true }>(`/admin/users/${userId}/identities/invite`, { provider }).then(r => r.data),
  adminUnlink: (userId: number, identityId: number) =>
    api.delete<{ ok: true }>(`/admin/users/${userId}/identities/${identityId}`).then(r => r.data),
}
