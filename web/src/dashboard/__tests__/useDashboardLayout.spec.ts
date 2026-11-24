import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { defineComponent, h, reactive } from 'vue'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import type { dashboardLayoutApi } from '../api'
import { useDashboardLayout, type DashboardLayoutState } from '../useDashboardLayout'
import { defaultLayout } from '../layoutRegistry'
import type { DashboardLayout, LayoutResponse } from '../types'

const stores = vi.hoisted(() => ({ auth: {} as Record<string, unknown>, supplier: {} as Record<string, unknown> }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => stores.auth }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => stores.supplier }))
vi.mock('@/composables/useWindowRefresh', () => ({ useWindowRefresh: vi.fn() }))
vi.mock('@/i18n', () => ({ ensureNamespaces: vi.fn().mockResolvedValue(undefined) }))
const definition = { type: 'business.kpi', titleKey: 'kpi', descriptionKey: 'description', options: { period: { type: 'enum', default: 'ytd', values: ['ytd', 'last_month'] } } }
const savedLayout = (): DashboardLayout => ({ schemaVersion: 2, items: [{ id: 'synthetic-kpi', kind: 'widget', type: 'business.kpi', span: 6, height: 'auto', options: { period: 'ytd' } }] })
const personal = (layout: DashboardLayout | null = null): LayoutResponse => ({ layout, revision: 'a'.repeat(32), recovered: false, writable: true })
let wrapper: VueWrapper
let state: DashboardLayoutState
let api: typeof dashboardLayoutApi
function render() {
  wrapper = mount(defineComponent({ setup() { state = useDashboardLayout('home', api); return () => h('div') } }))
}
beforeEach(() => {
  stores.auth = reactive({ user: { id: 71 }, permissions: { dashboard: 1 }, license: {}, isDemo: false })
  stores.supplier = reactive({ hasSupplier: true, currentSupplierId: 901 })
  api = { catalog: vi.fn().mockResolvedValue({ board: 'home', widgets: [definition] }), layout: vi.fn().mockResolvedValue(personal()),
    query: vi.fn().mockResolvedValue({ widgets: { 'synthetic-kpi': { status: 'ok', data: { rows: [{ currency: 'CZK', invoiced: 100 }] } } } }),
    save: vi.fn().mockResolvedValue(personal(savedLayout())), reset: vi.fn().mockResolvedValue(personal()) }
})
afterEach(() => wrapper?.unmount())

describe('personal dashboard layout', () => {
  it('retains original sections and does not query business widgets by default', async () => {
    render(); await flushPromises()
    expect(state.layout.value).toEqual(defaultLayout('home'))
    expect(api.query).not.toHaveBeenCalled()
    expect(state.visible.value).toHaveLength(11)
  })
  it('keeps the original layout available when preference loading fails', async () => {
    vi.mocked(api.layout).mockRejectedValue(new Error('offline'))
    render(); await flushPromises()
    expect(state.layout.value).toEqual(defaultLayout('home'))
    expect(state.error.value).toBe('loadError')
    state.edit(); expect(state.editing.value).toBe(false)
  })
  it('preserves a temporarily forbidden widget when saving other edits', async () => {
    const layout = savedLayout()
    layout.items.push({ id: 'hidden', kind: 'widget', type: 'accounting.status', span: 12, height: 'auto', options: {} })
    vi.mocked(api.layout).mockResolvedValue(personal(layout))
    render(); await flushPromises()
    expect(state.visible.value.map(item => item.id)).toEqual(['synthetic-kpi'])
    expect(state.hiddenCount.value).toBe(1)
    state.edit(); state.draft.value!.items[0]!.span = 8
    await state.persist()
    expect(api.save).toHaveBeenCalledWith('home', expect.objectContaining({ items: expect.arrayContaining([expect.objectContaining({ id: 'hidden' })]) }), 'a'.repeat(32))
  })
  it('keeps drafts on conflict and sends their revision without an automatic overwrite', async () => {
    render(); await flushPromises(); state.edit()
    state.remove('section-sales')
    vi.mocked(api.save).mockRejectedValue({ response: { status: 409 } })
    await state.persist()
    expect(state.error.value).toBe('conflict')
    expect(state.editing.value).toBe(true)
    expect(state.draft.value!.items.some(item => item.id === 'section-sales')).toBe(false)
    expect(api.save).toHaveBeenCalledTimes(1)
    state.cancel()
    expect(state.layout.value.items.some(item => item.id === 'section-sales')).toBe(true)
  })
  it('permits a demo preview but does not issue a save or reset', async () => {
    vi.mocked(api.layout).mockResolvedValue({ ...personal(), writable: false })
    render(); await flushPromises(); state.edit(); state.remove('section-sales')
    expect(state.visible.value.some(item => item.id === 'section-sales')).toBe(false)
    await state.persist(); await state.persist(true)
    expect(api.save).not.toHaveBeenCalled(); expect(api.reset).not.toHaveBeenCalled()
    expect(state.editing.value).toBe(true)
  })
  it('can reset a recovered invalid preference with the actual stored revision', async () => {
    vi.mocked(api.layout).mockResolvedValue({ ...personal(), recovered: true })
    render(); await flushPromises(); state.edit()
    await state.persist(true)
    expect(api.reset).toHaveBeenCalledWith('home', 'a'.repeat(32))
    expect(state.recovered.value).toBe(false)
    expect(state.layout.value).toEqual(defaultLayout('home'))
  })
  it('clears data synchronously on company switch and ignores the previous pending response', async () => {
    vi.mocked(api.layout).mockResolvedValue(personal(savedLayout()))
    let finishOld!: (value: { widgets: Record<string, { status: 'ok'; data: { secret: string } }> }) => void
    vi.mocked(api.query).mockImplementationOnce(() => new Promise(resolve => { finishOld = resolve }))
    render(); await flushPromises()
    expect(api.query).toHaveBeenCalledTimes(1)
    vi.mocked(api.layout).mockResolvedValue(personal())
    stores.supplier.currentSupplierId = 902
    expect(state.results.value).toEqual({})
    await flushPromises()
    finishOld({ widgets: { 'synthetic-kpi': { status: 'ok', data: { secret: 'synthetic old company' } } } })
    await flushPromises()
    expect(state.results.value).toEqual({})
    expect(state.layout.value).toEqual(defaultLayout('home'))
  })
  it('ignores an in-flight save response after the user context changes', async () => {
    let finishOld!: (value: LayoutResponse) => void
    render(); await flushPromises(); state.edit()
    vi.mocked(api.save).mockImplementationOnce(() => new Promise(resolve => { finishOld = resolve }))
    const pending = state.persist()
    stores.auth.user = { id: 72 }
    await flushPromises()
    finishOld(personal(savedLayout())); await pending
    expect(state.layout.value).toEqual(defaultLayout('home'))
    expect(state.editing.value).toBe(false)
  })
})
