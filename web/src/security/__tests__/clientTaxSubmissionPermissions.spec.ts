import { describe, expect, it } from 'vitest'
import cs from '@/i18n/cs.json'
import en from '@/i18n/en.json'
import { isClientPermission, rolePermissionLevels, setRolePermission, type PermissionValue } from '../permissions'

describe('client tax submission permissions', () => {
  it('allows prepared archive and sending without internal report or management permissions', () => {
    expect(isClientPermission('reports.submissions')).toBe(true)
    expect(isClientPermission('reports.submit')).toBe(true)
    for (const key of ['reports', 'reports.export', 'reports.finalize', 'reports.reopen', 'reports.submissions.manage'] as const) {
      expect(isClientPermission(key)).toBe(false)
    }
  })

  it('offers only archive read and submit write in the role editor', () => {
    expect(rolePermissionLevels('client', 'reports.submissions')).toEqual([0, 1])
    expect(rolePermissionLevels('client', 'reports.submit')).toEqual([0, 2])
    expect(rolePermissionLevels('staff', 'reports.submit')).toEqual([0, 1, 2])
    expect(rolePermissionLevels('staff', 'reports.submissions.manage')).toEqual([0, 2])
  })

  it('grants read with send and removes send when read is removed', () => {
    const permissions: Record<string, PermissionValue> = {}
    setRolePermission(permissions, 'client', 'reports.submit', 2)
    expect(permissions).toEqual({ 'reports.submissions': 1, 'reports.submit': 2 })
    setRolePermission(permissions, 'client', 'reports.submissions', 0)
    expect(permissions).toEqual({ 'reports.submissions': 0, 'reports.submit': 0 })
  })

  it('normalizes client levels without changing staff submission rights', () => {
    const permissions: Record<string, PermissionValue> = {}
    setRolePermission(permissions, 'client', 'reports.submissions', 2)
    expect(permissions['reports.submissions']).toBe(1)
    setRolePermission(permissions, 'client', 'reports.submit', 1)
    expect(permissions['reports.submit']).toBe(2)
    setRolePermission(permissions, 'staff', 'reports.submit', 1)
    expect(permissions['reports.submit']).toBe(1)
  })
})

it('localizes every submission permission in both root locale namespaces', () => {
  for (const messages of [cs, en]) {
    for (const key of ['reports_submissions', 'reports_submit', 'reports_submissions_manage'] as const) {
      expect(messages.roles.submission_permissions[key]).toBeTypeOf('string')
    }
  }
})
