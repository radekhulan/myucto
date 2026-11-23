import { api } from './client'
import type { EpoStepUpProof } from './epoSubmissions'

export type IsdsAccessEnvironment = 'production' | 'test'
export type IsdsAccessOperation = 'send' | 'receive' | 'receipts'
export type IsdsAccessMethod = 'password' | 'certificate' | 'user_certificate'
export type IsdsStoredAccess = { access_id: number; authorized: true }
export type IsdsDirectAccess = { username: string; password: string } | IsdsStoredAccess

export interface IsdsSavedAccess {
  id: number
  label: string
  box_id: string
  method: IsdsAccessMethod
  scope: 'personal' | 'shared'
}

interface IsdsAccessGrant {
  supplier_id: number
  send: boolean
  receive: boolean
  receipts: boolean
}

export interface IsdsAdminAccess extends IsdsSavedAccess {
  environment: IsdsAccessEnvironment
  certificate_id: number | null
  grants: IsdsAccessGrant[]
}

interface IsdsAdminOverview {
  items: IsdsAdminAccess[]
  companies: { id: number; name: string }[]
  certificates: { id: number; label: string; valid_to: string | null }[]
}

interface IsdsAdminAccessInput {
  label: string
  environment: IsdsAccessEnvironment
  method: IsdsAccessMethod
  username?: string
  password?: string
  certificate_id?: number
  grants: IsdsAccessGrant[]
  step_up: EpoStepUpProof
}

export const isdsAccessApi = {
  choices: (environment: IsdsAccessEnvironment, operation: IsdsAccessOperation) =>
    api.get<{ items: IsdsSavedAccess[] }>('/submissions/isds/access', { params: { environment, operation } })
      .then(r => r.data.items),

  remember: (environment: IsdsAccessEnvironment, operation: IsdsAccessOperation, username: string, password: string) =>
    api.post<{ item: IsdsSavedAccess }>('/submissions/isds/access/personal', { environment, operation, username, password })
      .then(r => r.data.item),

  forget: (id: number) => api.delete(`/submissions/isds/access/personal/${id}`).then(() => undefined),

  adminList: () => api.get<IsdsAdminOverview>('/admin/isds/access').then(r => r.data),

  adminSave: (id: number | null, payload: IsdsAdminAccessInput) =>
    (id === null
      ? api.post<{ item: IsdsAdminAccess }>('/admin/isds/access', payload)
      : api.put<{ item: IsdsAdminAccess }>(`/admin/isds/access/${id}`, payload))
      .then(r => r.data.item),

  adminRemove: (id: number, stepUp: EpoStepUpProof) =>
    api.delete(`/admin/isds/access/${id}`, { data: { step_up: stepUp } }).then(() => undefined),
}
