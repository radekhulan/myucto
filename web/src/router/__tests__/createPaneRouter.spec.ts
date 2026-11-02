import { describe, expect, it, vi } from 'vitest'

vi.mock('@/i18n', () => ({
  ensureNamespaces: vi.fn().mockResolvedValue(undefined),
  namespacesForRoute: () => [],
}))

import { createPaneRouter } from '@/router/createPaneRouter'

describe('createPaneRouter', () => {
  it('předá přímý globální cíl hlavní aplikaci místo autorizace neznámé panelové routy', async () => {
    const onGlobalNavigation = vi.fn()
    const guard = vi.fn(() => true)
    const router = createPaneRouter({ prepareRoutes: () => {}, guard, onGlobalNavigation })
    await router.push('/instance-admin?tab=smtp#targets')
    expect(onGlobalNavigation).toHaveBeenCalledWith('/instance-admin?tab=smtp#targets')
    expect(guard).not.toHaveBeenCalled()
    expect(router.currentRoute.value.matched).toHaveLength(0)
  })

  it('řeší stejné params a query nad paměťovou historií', () => {
    const router = createPaneRouter({
      prepareRoutes: () => {},
      guard: () => true,
      onGlobalNavigation: vi.fn(),
    })

    const resolved = router.resolve('/purchase-invoices/42?tab=activity')
    expect(resolved.name).toBe('purchase-invoice-detail')
    expect(resolved.params.id).toBe('42')
    expect(resolved.query.tab).toBe('activity')
  })

  it('předá cíl mimo business routy hlavní aplikaci', async () => {
    const onGlobalNavigation = vi.fn()
    const router = createPaneRouter({
      prepareRoutes: () => {},
      guard: () => ({ name: 'login' }),
      onGlobalNavigation,
    })

    await router.push('/purchase-invoices')

    expect(onGlobalNavigation).toHaveBeenCalledWith({ name: 'login' })
    expect(router.currentRoute.value.matched).toHaveLength(0)
  })
})
