import { ref } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  listPeriods: vi.fn(),
  listAccounts: vi.fn(),
  listJournal: vi.fn(),
  exportReport: vi.fn(),
  replace: vi.fn(),
  toastError: vi.fn(),
  routeQuery: {} as Record<string, string>,
  supplier: { accounting_framework: 'cz_500', entity_profile: 'unknown' } as Record<string, string>,
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
    getEntry: vi.fn(),
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
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ get currentSupplier() { return m.supplier } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, success: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => String(v) }))
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

function mountJournal() {
  return mount(Journal, {
    global: {
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

describe('Journal – filtr dimenze', () => {
  beforeEach(() => {
    for (const fn of [m.listPeriods, m.listAccounts, m.listJournal, m.exportReport, m.replace, m.toastError]) fn.mockReset()
    m.listPeriods.mockResolvedValue([])
    m.listAccounts.mockResolvedValue([])
    m.listJournal.mockResolvedValue({ items: [], total: 0, page: 1, per_page: 50 })
    m.routeQuery = {}
  })

  it('neplatný filtr ukáže hlášku serveru, ne obecnou chybu', async () => {
    m.routeQuery = { account_from: '6', account_to: '5' }
    m.listJournal.mockRejectedValue({ response: { status: 422, data: { error: { code: 'validation_failed', message: 'Počáteční účet nesmí být větší než koncový.' } } } })
    mountJournal()
    await flushPromises()
    expect(m.toastError).toHaveBeenCalledWith('Počáteční účet nesmí být větší než koncový.')
  })

  it('převezme hodnotu dimenze z URL, pošle ji serveru a ukáže chip', async () => {
    m.routeQuery = { dimension_value_id: '5', dimension_descendants: '0' }
    const wrapper = mountJournal()
    await flushPromises()

    expect(lastListCall()).toMatchObject({ dimension_value_id: '5', dimension_descendants: false })
    const filter = wrapper.findComponent(DimensionReportFilters)
    expect(filter.exists()).toBe(true)
    expect(filter.props()).toMatchObject({ valueIds: [5], descendants: false })
    const chip = wrapper.find('.chip[data-key="dimension"]')
    expect(chip.text()).toContain('Projekt')
    expect(chip.text()).toContain('P5 – Projekt pět')
    expect(chip.text()).toContain('accounting.journal.filter_dimension_only_value')
  })

  it('výběr hodnoty filtruje deník a zrcadlí se do URL; zrušení chipu filtr odebere', async () => {
    const wrapper = mountJournal()
    await flushPromises()
    expect(lastListCall().dimension_value_id).toBeUndefined()

    wrapper.findComponent(DimensionReportFilters).vm.$emit('update:valueIds', [5])
    await flushPromises()
    expect(lastListCall()).toMatchObject({ dimension_value_id: '5', dimension_descendants: true })
    expect(m.replace).toHaveBeenLastCalledWith({ query: { dimension_value_id: '5' } })

    wrapper.findComponent(DimensionReportFilters).vm.$emit('update:descendants', false)
    await flushPromises()
    expect(m.replace).toHaveBeenLastCalledWith({ query: { dimension_value_id: '5', dimension_descendants: '0' } })

    wrapper.findComponent({ name: 'FilterBar' }).vm.$emit('clear', 'dimension')
    await flushPromises()
    expect(lastListCall().dimension_value_id).toBeUndefined()
    expect(m.replace).toHaveBeenLastCalledWith({ query: {} })
  })

  it('víc hodnot různých dimenzí pošle serveru i do URL jako seznam a ukáže je v chipu', async () => {
    m.routeQuery = { dimension_value_id: '5,5,abc,8' }
    const wrapper = mountJournal()
    await flushPromises()

    expect(lastListCall()).toMatchObject({ dimension_value_id: '5,8' })
    expect(wrapper.findComponent(DimensionReportFilters).props()).toMatchObject({ valueIds: [5, 8] })
    const chip = wrapper.find('.chip[data-key="dimension"]')
    expect(chip.text()).toContain('P5 – Projekt pět')

    wrapper.findComponent(DimensionReportFilters).vm.$emit('update:valueIds', [5, 8, 9])
    await flushPromises()
    expect(lastListCall()).toMatchObject({ dimension_value_id: '5,8,9' })
    expect(m.replace).toHaveBeenLastCalledWith({ query: { dimension_value_id: '5,8,9' } })
  })

  it('export posílá stejný filtr dimenze jako seznam', async () => {
    m.routeQuery = { dimension_value_id: '5', dimension_descendants: '0' }
    m.exportReport.mockResolvedValue({ data: new Blob(['x']) })
    const createObjectURL = vi.fn(() => 'blob:x')
    Object.assign(URL, { createObjectURL, revokeObjectURL: vi.fn() })
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
    const wrapper = mountJournal()
    await flushPromises()

    const xlsx = wrapper.findAll('button').find(b => b.text().includes('accounting.journal.export_xlsx'))
    await xlsx!.trigger('click')
    await flushPromises()
    expect(m.exportReport).toHaveBeenCalledWith('/accounting/reports/journal/export',
      expect.objectContaining({ dimension_value_id: '5', dimension_descendants: 0, format: 'xlsx' }))
  })

  it('zdroj Předpis SVJ nabízí filtr jen firmě s profilem SVJ', async () => {
    const options = async () => {
      const wrapper = mountJournal()
      await flushPromises()
      return wrapper.findAll('option').map(option => option.attributes('value'))
    }
    m.supplier = { accounting_framework: 'cz_500', entity_profile: 'unknown' }
    expect(await options()).toContain('invoice')
    expect(await options()).not.toContain('unit_charge')
    m.supplier = { accounting_framework: 'cz_504', entity_profile: 'svj' }
    expect(await options()).toContain('unit_charge')
  })
})
