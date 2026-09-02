import { describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import type { RouteRecordRaw } from 'vue-router'
import { applyAuthorizationMeta, router } from '@/router'
import { createWorkspaceRoutes } from '@/router/workspaceRoutes'
import { useAuthStore } from '@/stores/auth'

function routeShape(routes: ReturnType<typeof createWorkspaceRoutes>) {
  return routes.map(route => ({
    path: route.path,
    name: route.name,
    meta: route.meta,
    redirect: Boolean(route.redirect),
    props: route.props,
    component: String(route.component),
  }))
}

describe('workspace route factory', () => {
  it('vrací čerstvé pole a čerstvé route záznamy', () => {
    const first = createWorkspaceRoutes()
    const second = createWorkspaceRoutes()

    expect(first).not.toBe(second)
    expect(first).not.toContainEqual(undefined)
    expect(first.length).toBeGreaterThan(100)
    expect(first[0]).not.toBe(second[0])

    first.splice(0, 1)
    expect(second.length).toBeGreaterThan(100)
  })

  it('udržuje stabilní routovou paritu mezi instancemi factory', () => {
    const first = createWorkspaceRoutes()
    const second = createWorkspaceRoutes()

    expect(routeShape(first)).toEqual(routeShape(second))
    expect(new Set(first.map(route => route.name)).size).toBe(first.length)
  })

  it('obsahuje reprezentativní list/detail/editor a bezpečnostní metadata', () => {
    const routes = createWorkspaceRoutes()
    const byName = new Map(routes.map(route => [route.name, route]))

    expect(byName.get('purchase-invoices')?.path).toBe('purchase-invoices')
    expect(byName.get('purchase-invoice-detail')?.path).toBe('purchase-invoices/:id(\\d+)')
    expect(byName.get('purchase-invoice-edit')?.meta).toMatchObject({ requiresSupplier: true })
    expect(byName.get('accounting-journal')?.meta).toMatchObject({ requiresDoubleEntry: true })
  })

  it('má po aplikaci guard metadat permission paritu s globálním routerem', () => {
    const paneRecords: RouteRecordRaw[] = [{
      path: '/',
      meta: { requiresAuth: true },
      children: createWorkspaceRoutes(),
    }]
    applyAuthorizationMeta(paneRecords)
    const paneRoutes = paneRecords[0]!.children ?? []

    for (const name of ['purchase-invoices', 'purchase-invoice-edit', 'accounting-journal', 'payroll-runs']) {
      const globalMeta = router.getRoutes().find(route => route.name === name)?.meta
      const paneMeta = paneRoutes.find(route => route.name === name)?.meta
      expect(paneMeta, name).toEqual(globalMeta)
    }
  })

  it('řídí importy a firemní číselníky jejich skutečnými oprávněními', () => {
    const records: RouteRecordRaw[] = [{
      path: '/',
      meta: { requiresAuth: true },
      children: createWorkspaceRoutes(),
    }]
    applyAuthorizationMeta(records)
    const byName = new Map((records[0]!.children ?? []).map(route => [route.name, route]))

    expect(byName.get('invoices-import')?.meta).toMatchObject({ permission: 'invoices', access: 'read' })
    expect(byName.get('purchase-invoices-import')?.meta).toMatchObject({
      permission: 'purchase_invoices',
      access: 'read',
    })

    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.$patch({
      user: { role: { type: 'staff' } } as never,
      permissions: { 'utilities.import': 2 },
    })
    const issuedGuard = byName.get('invoices-import')?.beforeEnter as () => unknown
    const purchaseGuard = byName.get('purchase-invoices-import')?.beforeEnter as () => unknown
    expect(issuedGuard()).toBe(true)
    expect(purchaseGuard()).toBe(true)

    auth.permissions = {}
    expect(issuedGuard()).toEqual({ path: '/invoices/export' })
    expect(purchaseGuard()).toEqual({ path: '/purchase-invoices/export' })

    auth.$patch({
      user: { role: { type: 'client' } } as never,
      permissions: { 'utilities.import': 2 },
    })
    expect(issuedGuard()).toEqual({ path: '/invoices/export' })
    expect(purchaseGuard()).toEqual({ path: '/purchase-invoices/export' })
    expect(byName.get('admin-codebooks')?.meta).toMatchObject({
      permission: 'settings.company',
      access: 'read',
    })
    expect(byName.get('admin-codebooks')?.meta?.superadminOnly).not.toBe(true)
    expect(byName.get('admin-support')?.meta).toMatchObject({
      permission: 'profile',
      access: 'read',
    })
    expect(byName.get('admin-support')?.meta?.superadminOnly).not.toBe(true)
  })
})
