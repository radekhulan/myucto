import { reactive, ref } from 'vue'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  listPeriods: vi.fn(),
  listAccounts: vi.fn(),
  listJournal: vi.fn(),
  exportReport: vi.fn(),
  getEntry: vi.fn(),
  replace: vi.fn(),
  routeQuery: {} as Record<string, string>,
}))

// Dimenze zapnuté: hodnota 5 typu Projekt (Firma → Dimenze).
vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: true }, canEdit: { value: true }, loading: { value: false },
    types: { value: [] }, values: { value: [] }, documentTypes: { value: [] },
    valueById: { value: new Map([[5, { id: 5, type_id: 1, code: 'P5', name: 'Projekt pět', is_active: true }]]) },
    typeById: { value: new Map([[1, { id: 1, name: 'Projekt' }]]) },
    load: () => Promise.resolve(), reload: () => Promise.resolve(),
    options: () => [], treeOf: () => [], pathOf: () => [], labelOf: () => '',
    valueLabel: (id: number) => (id === 5 ? 'P5 – Projekt pět' : `#${id}`),
  }),
}))
vi.mock('@/api/accounting', () => ({
  accountingApi: {
    listPeriods: m.listPeriods,
    listAccounts: m.listAccounts,
    listJournal: m.listJournal,
    exportReport: m.exportReport,
    getEntry: m.getEntry,
  },
}))
vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
  useRoute: () => ({ query: m.routeQuery }),
  useRouter: () => ({ replace: m.replace }),
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true, isDemo: false }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { accounting_framework: 'cz_500', entity_profile: 'unknown' } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatDateTime: (v: string) => v, formatMoney: (v: number) => String(v) }))
vi.mock('@/composables/useUserPrefs', () => ({ ensurePrefsLoaded: vi.fn().mockResolvedValue(undefined), getPagePrefs: () => ref({}) }))
vi.mock('@/composables/useTablePrefs', () => ({
  useTablePrefs: (_key: string, columns: unknown[]) => ({
    columns, orderedColumns: ref(columns), ready: ref(true), isVisible: () => true, densityClass: ref(''), setFlag: vi.fn(), flag: () => false,
    sort: ref(null), toggleSort: vi.fn(),
  }),
}))
vi.mock('@/composables/useSavedFilters', () => ({
  savedFilterTone: () => 'neutral',
  useSavedFilters: () => ({
    filters: ref([]), activeId: ref(null), clearActive: vi.fn(), apply: vi.fn(),
    applyDefaultIfAny: vi.fn().mockResolvedValue(false),
  }),
}))

import Journal from '@/pages/accounting/Journal.vue'
import DimensionReportFilters from '@/components/dimensions/DimensionReportFilters.vue'

const FilterBarStub = {
  name: 'FilterBar',
  props: ['chips', 'activeCount', 'collapsible'],
  template: `<div>
    <span v-for="c in chips" :key="c.key" class="chip" :data-key="c.key">{{ c.label }}: {{ c.value }}</span>
    <slot name="primary" /><slot /><slot name="actions" />
  </div>`,
}

import { windowContextKey } from '@/workspace/windowContext'
import type { JournalEntryDetail } from '@/api/accounting'

function mountJournal() {
  const root = document.createElement('div')
  document.body.appendChild(root)
  return mount(Journal, {
    attachTo: root,
    global: {
      provide: { [windowContextKey as symbol]: { root } },
      directives: { 'column-labels': {} },
      stubs: {
        FilterBar: FilterBarStub,
        DimensionReportFilters: true,
        ActivationBanner: true,
        SavedFiltersMenu: true,
        ColumnPicker: true,
        TableColorsMenu: true,
        DensityToggle: true,
        EmptyState: true,
        DateInput: true,
        JournalSourceDrawer: true,
        JournalEntryDetailPanel: true,
        AutomationBadge: true,
      },
    },
  })
}

function lastListCall(): Record<string, unknown> {
  return m.listJournal.mock.calls.at(-1)?.[0] ?? {}
}


function entry(id: number): JournalEntryDetail {
  return { id, supplier_id: 1, period_id: 1, entry_date: '2010-03-02', document_date: null,
    document_no: `SYNTH-${id}`, description: '', source_type: 'bank', source_id: id,
    posted_at: '2010-03-02', posted_by: 1, reversed_by: null, row_version: 1,
    created_at: '2010-03-02', updated_at: '2010-03-02', lines: [] }
}
const scroll = vi.fn()
afterEach(() => { localStorage.removeItem('myinvoice.current_supplier_id') })

beforeEach(() => {
  vi.clearAllMocks()
  m.routeQuery = reactive({})
  m.listPeriods.mockResolvedValue([])
  m.listAccounts.mockResolvedValue([])
  m.getEntry.mockImplementation(async (id: number) => entry(id))
  m.listJournal.mockImplementation(async (params: { entry_id?: number }) => ({
    items: params.entry_id ? [entry(params.entry_id)] : [], total: params.entry_id ? 1 : 0, page: 1, per_page: 50,
  }))
  Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', { configurable: true, value: scroll })
})

describe('journal entry focus', () => {
  it.each(['desktop', 'mobile'])('opens the exact historical entry and scrolls the visible %s row inside its pane', async layout => {
    vi.spyOn(HTMLElement.prototype, 'getClientRects').mockImplementation(function (this: HTMLElement) {
      const visible = layout === 'desktop' ? this.tagName === 'TR' : this.tagName === 'DIV'
      return (visible ? [{}] : []) as unknown as DOMRectList
    })
    m.routeQuery = reactive({ entry_id: '7', date_from: '2026-01-01', posted: 'draft', reversal: 'reversed' })
    const wrapper = mountJournal()
    await flushPromises()
    expect(lastListCall()).toMatchObject({ entry_id: 7, date_from: '2010-03-02', date_to: '2010-03-02' })
    expect(lastListCall().posted).toBeUndefined()
    expect(lastListCall().reversal).toBeUndefined()
    expect(wrapper.findAll('journal-entry-detail-panel-stub')).toHaveLength(2)
    expect(scroll).toHaveBeenCalledTimes(1)
    const target = scroll.mock.instances[0] as HTMLElement
    expect(target.dataset.journalEntry).toBe('7')
    expect(target.tagName).toBe(layout === 'desktop' ? 'TR' : 'DIV')
    expect(wrapper.element.contains(target)).toBe(true)
    expect(target.className).toContain('bg-primary-50')
  })

  it('ignores an earlier detail response when the same route changes entry during loading', async () => {
    vi.spyOn(HTMLElement.prototype, 'getClientRects').mockReturnValue([{}] as unknown as DOMRectList)
    let finishOld!: (value: JournalEntryDetail) => void
    m.getEntry.mockImplementationOnce(() => new Promise(resolve => { finishOld = resolve }))
    m.routeQuery = reactive({ entry_id: '7' })
    const wrapper = mountJournal()
    await flushPromises()
    m.routeQuery.entry_id = '8'
    await flushPromises()
    finishOld(entry(7))
    await flushPromises()
    expect(lastListCall().entry_id).toBe(8)
    expect(wrapper.text()).toContain('SYNTH-8')
    expect(wrapper.text()).not.toContain('SYNTH-7')
    expect(scroll.mock.instances.at(-1)).toHaveProperty('dataset.journalEntry', '8')
  })

  it('ignores a stale list response after a new entry was already loaded', async () => {
    vi.spyOn(HTMLElement.prototype, 'getClientRects').mockReturnValue([{}] as unknown as DOMRectList)
    let finishOld!: (value: object) => void
    m.listJournal.mockImplementationOnce(() => new Promise(resolve => { finishOld = resolve }))
    m.routeQuery = reactive({ entry_id: '7' })
    const wrapper = mountJournal()
    await flushPromises()
    m.routeQuery.entry_id = '8'
    await flushPromises()
    finishOld({ items: [entry(7)], total: 1, page: 1, per_page: 50 })
    await flushPromises()
    expect(wrapper.text()).toContain('SYNTH-8')
    expect(wrapper.text()).not.toContain('SYNTH-7')
    expect(scroll.mock.instances.at(-1)).toHaveProperty('dataset.journalEntry', '8')
  })

  it('ignores the old supplier response during a company switch', async () => {
    localStorage.setItem('myinvoice.current_supplier_id', '1')
    let finish!: (value: JournalEntryDetail) => void
    m.getEntry.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    m.routeQuery = reactive({ entry_id: '7' })
    const wrapper = mountJournal()
    await flushPromises()
    localStorage.setItem('myinvoice.current_supplier_id', '2')
    finish(entry(7))
    await flushPromises()
    expect(m.listJournal).not.toHaveBeenCalled()
    expect(wrapper.findAll('journal-entry-detail-panel-stub')).toHaveLength(0)
    expect(scroll).not.toHaveBeenCalled()
  })

  it('does not reopen a pending entry after the user applies a filter', async () => {
    let finish!: (value: JournalEntryDetail) => void
    m.getEntry.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    m.routeQuery = reactive({ entry_id: '7' })
    const wrapper = mountJournal()
    await flushPromises()
    wrapper.findComponent(DimensionReportFilters).vm.$emit('update:valueIds', [5])
    await flushPromises()
    finish(entry(7))
    await flushPromises()
    expect(lastListCall()).toMatchObject({ dimension_value_id: '5', entry_id: undefined })
    expect(wrapper.findAll('journal-entry-detail-panel-stub')).toHaveLength(0)
    expect(scroll).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('common.loading')
  })

  it('loads the normal journal when the pending focus query is removed', async () => {
    let finish!: (value: JournalEntryDetail) => void
    m.getEntry.mockImplementationOnce(() => new Promise(resolve => { finish = resolve }))
    m.routeQuery = reactive({ entry_id: '7' })
    const wrapper = mountJournal()
    await flushPromises()
    delete m.routeQuery.entry_id
    await flushPromises()
    finish(entry(7))
    await flushPromises()
    expect(lastListCall().entry_id).toBeUndefined()
    expect(wrapper.findAll('journal-entry-detail-panel-stub')).toHaveLength(0)
    expect(scroll).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('common.loading')
  })

  it('clears the previous target when the next entry is unavailable or belongs to another company', async () => {
    vi.spyOn(HTMLElement.prototype, 'getClientRects').mockReturnValue([{}] as unknown as DOMRectList)
    m.routeQuery = reactive({ entry_id: '7' })
    const wrapper = mountJournal()
    await flushPromises()
    expect(wrapper.findAll('[aria-current="true"]')).toHaveLength(2)
    scroll.mockClear()
    m.getEntry.mockRejectedValueOnce(new Error('Not found'))
    m.routeQuery.entry_id = '8'
    await flushPromises()
    expect(wrapper.findAll('[aria-current="true"]')).toHaveLength(0)
    expect(wrapper.findAll('.ring-primary-500')).toHaveLength(0)
    expect(wrapper.findAll('journal-entry-detail-panel-stub')).toHaveLength(0)
    expect(scroll).not.toHaveBeenCalled()
  })

  it('never expands or scrolls an unavailable or foreign entry', async () => {
    m.getEntry.mockRejectedValueOnce(new Error('Not found'))
    m.routeQuery = reactive({ entry_id: '7' })
    const wrapper = mountJournal()
    await flushPromises()
    expect(wrapper.findAll('journal-entry-detail-panel-stub')).toHaveLength(0)
    expect(scroll).not.toHaveBeenCalled()
  })

  it('preserves document source drilldown when navigating on the reused route', async () => {
    m.routeQuery = reactive({ source_type: 'invoice', source_id: '21' })
    mountJournal()
    await flushPromises()
    expect(lastListCall()).toMatchObject({ source_type: 'invoice', source_id: 21 })
    m.routeQuery.source_id = '22'
    await flushPromises()
    expect(lastListCall()).toMatchObject({ source_type: 'invoice', source_id: 22 })
  })
})
