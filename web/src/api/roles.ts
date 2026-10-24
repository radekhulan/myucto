import { api } from './client'
import type { PermissionValue } from '@/security/permissions'

export type RoleType = 'superadmin' | 'staff' | 'client' | 'instance_admin'

export interface RoleListItem {
  id: number
  system_key: string | null
  name: string
  role_type: RoleType
  is_active: boolean
  created_at: string
  updated_at: string
  default_usage: number
  override_usage: number
}

export interface RoleDetail extends RoleListItem {
  permissions: Record<string, PermissionValue>
  revision?: string
  usage?: { default: number; overrides: number; total: number }
}

export interface PermissionDefinition {
  key: string
  group: string
  label: string
  description: string
  role_types: Array<'staff' | 'client'>
  kind: 'module' | 'action'
}

export interface PermissionGroup {
  key: string
  label?: string
  permissions: PermissionDefinition[]
}

export interface PermissionCatalogResponse {
  version: string
  groups: Record<string, PermissionDefinition[]>
}

export const rolesApi = {
  list: () => api.get<RoleListItem[]>('/admin/roles').then(r => r.data),
  catalog: () => api.get<PermissionCatalogResponse>('/admin/roles/permissions').then(r => r.data),
  detail: (id: number) => api.get<RoleDetail>(`/admin/roles/${id}`).then(r => r.data),
  create: (payload: { name: string; type: 'staff' | 'client'; permissions: Record<string, PermissionValue> }) =>
    api.post<RoleDetail>('/admin/roles', payload).then(r => r.data),
  update: (id: number, payload: { name: string; is_active: boolean; permissions: Record<string, PermissionValue>; revision?: string; updated_at?: string }) =>
    api.put<RoleDetail>(`/admin/roles/${id}`, payload).then(r => r.data),
  duplicate: (id: number, name?: string) =>
    api.post<RoleDetail>(`/admin/roles/${id}/duplicate`, name ? { name } : {}).then(r => r.data),
  remove: (id: number) => api.delete(`/admin/roles/${id}`),
}
