import { describe, expect, it, vi } from 'vitest'
import { defineComponent, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { useDashboardBoard } from '../useDashboardBoard'
import type { BoardId, DashboardLayout, WidgetCatalog, QueryResponse } from '../types'
import { cloneLayout, moveInstance, visibleInstances } from '../types'
import { widgetComponents } from '../registry'
import manifest from '../../../shared/dashboard-widgets.json'
const initial: DashboardLayout = { schemaVersion: 1, presetVersion: 1, widgets: [{ id: 'kpi', type: 'business.kpi', span: 3, options: { period: 'ytd', currency: 'all' } }, { id: 'internal', type: 'accounting.status', span: 12, options: {} }] }
const catalog: WidgetCatalog = { board: 'home', widgets: [{ type: 'business.kpi', titleKey: 'kpi', descriptionKey: 'kpi', spans: [3,6], defaultSpan: 3, repeatable: true, options: { period: { type: 'enum', values: ['ytd','current_month'], default: 'ytd' } } }], defaultLayout: initial }
function deferred<T>() { let resolve!: (value: T) => void; const promise = new Promise<T>(r => { resolve = r }); return { promise, resolve } }
function setup() {
  const api = {
    catalog: vi.fn().mockResolvedValue(catalog), layout: vi.fn().mockResolvedValue({ layout: initial, revision: 'r1' }),
    query: vi.fn().mockResolvedValue({ widgets: { kpi: { status: 'ok', data: { rows: [] } } } }),
    save: vi.fn().mockImplementation((_board, layout) => Promise.resolve({ layout, revision: 'r2' })),
    reset: vi.fn().mockResolvedValue({ layout: null, revision: 'tombstone-r3' }),
  }
  const board = ref<BoardId>('home')
  let state!: ReturnType<typeof useDashboardBoard>
  const wrapper = mount(defineComponent({ setup() { state = useDashboardBoard(board, api); return () => null } }))
  return { state, api, wrapper, board }
}
describe('shared dashboard state', () => {
  it('has a renderer for exactly every manifest type', () => { expect(Object.keys(widgetComponents).sort()).toEqual(manifest.widgets.map(w => w.type).sort()) })
  it('filters visibility without deleting unavailable configuration', async () => {
    const { state, api, wrapper } = setup(); await state.load()
    expect(state.visible.value.map(w => w.id)).toEqual(['kpi'])
    expect(state.saved.value?.widgets).toHaveLength(2)
    expect(api.query.mock.calls[0]?.[1]).toHaveLength(1)
    state.edit(); state.draft.value!.widgets[0]!.options.period = 'current_month'; await state.persist()
    expect(api.save.mock.calls[0]?.[1].widgets[1]).toEqual(initial.widgets[1]); wrapper.unmount()
  })
  it('edits a deep copy and cancel makes no write', async () => {
    const { state, api, wrapper } = setup(); await state.load(); state.edit()
    state.draft.value!.widgets[0]!.options.period = 'current_month'
    expect(state.saved.value!.widgets[0]!.options.period).toBe('ytd')
    state.cancel(); expect(state.editing.value).toBe(false); expect(api.save).not.toHaveBeenCalled(); wrapper.unmount()
  })
  it('refresh preserves a dirty draft and does not reload saved preferences', async () => {
    const { state, api, wrapper } = setup(); await state.load(); state.edit(); state.draft.value!.widgets[0]!.span = 6
    await state.load(); expect(api.layout).toHaveBeenCalledTimes(1); expect(state.draft.value!.widgets[0]!.span).toBe(6); wrapper.unmount()
  })
  it('drops a previous company load and clears private state synchronously', async () => {
    const { state, api, wrapper } = setup(); const old = deferred<WidgetCatalog>()
    api.catalog.mockReturnValueOnce(old.promise)
    const first = state.load(); state.invalidate(); expect(state.results.value).toEqual({}); await state.load()
    old.resolve({ ...catalog, widgets: [] }); await first
    expect(state.catalog.value?.widgets).toHaveLength(1); wrapper.unmount()
  })
  it('drops late query responses after switching context', async () => {
    const { state, api, wrapper } = setup(); await state.load(); const old = deferred<QueryResponse>()
    api.query.mockReturnValueOnce(old.promise); const pending = state.query(); state.invalidate()
    old.resolve({ widgets: { leaked: { status: 'ok', data: { secret: 123 } } }, today: '', currency: 'CZK' }); await pending
    expect(state.results.value).toEqual({}); wrapper.unmount()
  })
  it('rejects an older concurrent query within the same company', async () => {
    const { state, api, wrapper } = setup(); await state.load(); const old = deferred<QueryResponse>()
    api.query.mockReturnValueOnce(old.promise); const pending = state.query(); await state.query()
    old.resolve({ widgets: { stale: { status: 'ok' } }, today: '', currency: 'CZK' }); await pending
    expect(state.results.value).not.toHaveProperty('stale'); wrapper.unmount()
  })
  it('preserves draft and revision when another window wins the conditional write', async () => {
    const { state, api, wrapper } = setup(); await state.load(); state.edit(); state.draft.value!.widgets[0]!.span = 6
    api.save.mockRejectedValueOnce({ response: { status: 409 } }); await state.persist()
    expect(state.error.value).toBe('conflict'); expect(state.editing.value).toBe(true); expect(state.draft.value!.widgets[0]!.span).toBe(6); expect(state.revision.value).toBe('r1'); wrapper.unmount()
  })
  it('reset uses conditional deletion and keeps the returned tombstone revision', async () => {
    const { state, api, wrapper } = setup(); await state.load(); state.edit(); await state.persist(true)
    expect(api.reset).toHaveBeenCalledWith('home','r1'); expect(state.revision.value).toBe('tombstone-r3'); expect(state.saved.value).toEqual(initial); wrapper.unmount()
  })
  it('orders the full layout while retaining hidden widgets and independent option objects', () => {
    const layout = cloneLayout(initial); layout.widgets.push({ ...layout.widgets[0]!, id: 'second', options: {} })
    moveInstance(layout, 'second', 'kpi'); expect(layout.widgets.map(w => w.id)).toEqual(['second','kpi','internal'])
    expect(visibleInstances(layout, catalog).map(w => w.id)).toEqual(['second','kpi'])
    layout.widgets[1]!.options.currency = 'EUR'; expect(initial.widgets[0]!.options.currency).toBe('all')
  })
  it('never queries layout-only separators and excludes them from the 32-widget limit', async () => {
    const { state, api, wrapper } = setup(); await state.load(); state.edit()
    const divider = { type: 'layout.divider' as const, titleKey: 'divider', descriptionKey: 'divider', spans: [12], defaultSpan: 12, options: {}, repeatable: true }
    for (let i = 0; i < 32; i++) state.add(divider)
    expect(state.draft.value!.widgets.filter(w => w.type === 'layout.divider')).toHaveLength(32)
    state.add(divider); expect(state.draft.value!.widgets).toHaveLength(34)
    state.catalog.value = { ...catalog, widgets: [...catalog.widgets, divider] }
    await state.query()
    expect(api.query.mock.calls.at(-1)?.[1].some((w: { type: string }) => w.type === 'layout.divider')).toBe(false)
    wrapper.unmount()
  })
  it('retains catalog activity metadata with no visible data widgets and no query metadata', async () => {
    const { state, api, wrapper } = setup()
    api.catalog.mockResolvedValueOnce({ ...catalog, businessActivity: { hasDocuments:true } })
    api.layout.mockResolvedValueOnce({ layout:{...initial,widgets:[]}, revision:'r1' })
    api.query.mockResolvedValueOnce({ widgets:{} })
    await state.load()
    expect(api.query).toHaveBeenCalledWith('home',[])
    expect(state.businessActivity.value).toBe(true)
    state.invalidate();expect(state.businessActivity.value).toBeNull();wrapper.unmount()
  })
  it('does not accept an old save result in a new context', async () => {
    const { state, api, wrapper } = setup(); await state.load(); state.edit(); const old = deferred<{ layout: DashboardLayout; revision: string }>()
    api.save.mockReturnValueOnce(old.promise); const pending = state.persist(); state.invalidate(); await state.load()
    old.resolve({ layout: { ...initial, widgets: [] }, revision: 'stale' }); await pending
    expect(state.revision.value).toBe('r1'); expect(state.saved.value?.widgets.length).toBe(2); wrapper.unmount()
  })
})
