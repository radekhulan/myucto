import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { watch } from 'vue'

const get = vi.fn()
vi.mock('@/api/client', () => ({ api: { get: (...args: unknown[]) => get(...args) } }))
const setDefaultSupplier = vi.fn()
vi.mock('@/api/auth', () => ({ authApi: { setDefaultSupplier: (...args: unknown[]) => setDefaultSupplier(...args) } }))
const refresh = vi.fn()
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ isDemo: false, clearPermissions: vi.fn(), refresh: () => refresh() }),
}))
const currentRoute = { value: {} as Record<string, unknown> }
vi.mock('@/router', () => ({ router: { currentRoute } }))

import { manualSwitchDestination } from '../useSupplierSwitch'

const tick = () => new Promise(resolve => setTimeout(resolve, 0))

function detail(name: string, id: string, path: string) {
  return { name, params: { id }, query: {}, path, fullPath: path } as never
}

/** Čerstvé moduly = stav aplikace po (pře)načtení stránky. */
async function loadApp(serverCurrent: number) {
  vi.resetModules()
  const deepLink = await import('@/router/supplierDeepLink')
  const switcher = await import('../useSupplierSwitch')
  const { useSupplierStore } = await import('@/stores/supplier')
  setActivePinia(createPinia())
  const store = useSupplierStore()
  store.setAvailable([{ id: 1 }, { id: 2 }] as never, serverCurrent)
  return { switchSupplierForDeepLink: deepLink.switchSupplierForDeepLink, useSupplierSwitch: switcher.useSupplierSwitch, store }
}

describe('ruční přepnutí firmy z detailu dokladu', () => {
  const originalLocation = window.location
  let location: { href: string, pathname: string, search: string, reload: ReturnType<typeof vi.fn> }

  beforeEach(() => {
    localStorage.clear()
    sessionStorage.clear()
    get.mockReset()
    setDefaultSupplier.mockReset().mockImplementation(async () => { await tick(); return {} })
    refresh.mockReset().mockImplementation(async () => { await tick(); return true })
    location = { href: '', pathname: '/purchase-invoices/674', search: '', reload: vi.fn() }
    Object.defineProperty(window, 'location', { value: location, configurable: true, writable: true })
    // Doklad 674 patří firmě 1, uživatel stojí na jeho detailu ve firmě 1.
    get.mockImplementation(async () => ({ data: { supplier_id: 1 } }))
    currentRoute.value = detail('purchase-invoice-detail', '674', '/purchase-invoices/674')
  })

  afterEach(() => {
    Object.defineProperty(window, 'location', { value: originalLocation, configurable: true, writable: true })
  })

  it('zvolená firma vyhraje nad vlastníkem dokladu a URL je přehled, ne detail', async () => {
    const app = await loadApp(1)
    const route = currentRoute.value as never
    // Jako WorkspaceHost: změna firmy přehodnotí aktuální routu guardem odkazu.
    const guardRuns: Promise<boolean>[] = []
    const stop = watch(() => app.store.currentSupplierId, () => { guardRuns.push(app.switchSupplierForDeepLink(route)) })

    await app.useSupplierSwitch().switchTo(2)
    await Promise.all(guardRuns)
    for (let i = 0; i < 5; i++) await tick()
    stop()

    expect(app.store.currentSupplierId).toBe(2)
    expect(localStorage.getItem('myinvoice.current_supplier_id')).toBe('2')
    expect(setDefaultSupplier).toHaveBeenLastCalledWith(2)
    expect(location.href).toBe('/purchase-invoices')
  })

  it('první navigace po přenačtení příznak spotřebuje, další odkaz na doklad jiné firmy zase přepíná', async () => {
    const before = await loadApp(1)
    await before.useSupplierSwitch().switchTo(2)
    expect(sessionStorage.getItem('myinvoice.manual_supplier_switch')).toBe('1')

    const after = await loadApp(2)
    expect(after.store.currentSupplierId).toBe(2)

    await expect(after.switchSupplierForDeepLink(detail('purchase-invoice-detail', '674', '/purchase-invoices/674'))).resolves.toBe(false)
    expect(sessionStorage.getItem('myinvoice.manual_supplier_switch')).toBeNull()
    expect(after.store.currentSupplierId).toBe(2)

    await expect(after.switchSupplierForDeepLink(detail('purchase-invoice-detail', '674', '/purchase-invoices/674'))).resolves.toBe(true)
    expect(after.store.currentSupplierId).toBe(1)
    expect(location.href).toBe('/purchase-invoices/674')
  })
})

describe('manualSwitchDestination', () => {
  it('z routy dokladu bez číselného segmentu jde na přehled nebo domů', () => {
    expect(manualSwitchDestination({ name: 'purchase-invoice-edit', path: '/purchase-invoices/674/edit', fullPath: '/purchase-invoices/674/edit' })).toBe('/purchase-invoices')
    expect(manualSwitchDestination({ name: 'invoice-detail', path: '/invoices/abc', fullPath: '/invoices/abc' })).toBe('/')
    expect(manualSwitchDestination({ name: 'accounting-journal', path: '/accounting/journal', fullPath: '/accounting/journal?entry_id=5' })).toBe('/accounting/journal')
    expect(manualSwitchDestination({ name: 'bank', path: '/bank', fullPath: '/bank' })).toBeNull()
  })
})
