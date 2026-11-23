import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  reportExportUrl: vi.fn(),
  listWarehouses: vi.fn(),
  listCategories: vi.fn(),
  listAttributes: vi.fn(),
  getValuation: vi.fn(),
  getValuationStatus: vi.fn(),
  createValuation: vi.fn(),
}))

vi.mock('@/api/stock', () => ({
  stockApi: { reportStatus: vi.fn(), reportExportUrl: m.reportExportUrl, listWarehouses: m.listWarehouses },
}))
vi.mock('@/api/eshop', () => ({ eshopApi: { listCategories: m.listCategories, listAttributes: m.listAttributes } }))
vi.mock('@/api/catalogJobs', () => ({ catalogJobsApi: { getValuation: m.getValuation, getValuationStatus: m.getValuationStatus, createValuation: m.createValuation } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: (key: string) => key === 'eshop', canWrite: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), warning: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({
  formatMoney: (value: number) => String(value), formatDate: (value: string) => value, formatNumber: (value: number) => String(value),
}))
vi.mock('@/composables/useTablePrefs', () => ({
  useTablePrefs: (_key: string, columns: unknown[]) => ({ columns, isVisible: () => true, densityClass: ref('') }),
}))
vi.mock('@/components/ui/buttonStyles', () => ({ ICONS: new Proxy({}, { get: () => 'M0 0' }), btnOutline: () => '' }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: { valuation_job: '5' } }), RouterLink: { template: '<a><slot /></a>' } }))

import Reports from '../Reports.vue'

const stubs = {
  ClientSearchSelect: true, PaginationBar: true, EmptyState: true, DateInput: true, CatalogJobProgress: true, ColumnPicker: true,
  RouterLink: { template: '<a><slot /></a>' },
}

const result = {
  job_id: 5, date: '2026-09-30',
  items: [{ warehouse_id: 1, warehouse_code: 'HL', warehouse_name: 'Hlavní', stock_item_id: 7, sku: 'A-1', name: 'Karta', unit: 'ks', qty: '1.000', value_total: '100.00' }],
  totals: { value_total: '130.00', count: 2 },
  pagination: { page: 1, limit: 50, total: 2, pages: 1 },
  source_versions: {},
}

describe('Reports – ocenění podle kategorií', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.listWarehouses.mockResolvedValue([])
    m.listCategories.mockResolvedValue([{ id: 12, name: 'Motocykly', path: '/12/', depth: 0, archived: false }])
    m.listAttributes.mockResolvedValue([])
    m.getValuationStatus.mockResolvedValue({ id: 5, status: 'completed' })
    m.reportExportUrl.mockReturnValue('/export')
  })

  it('loads the page without grouping by default', async () => {
    m.getValuation.mockResolvedValue(result)
    mount(Reports, { global: { stubs } })
    await flushPromises()
    expect(m.getValuation).toHaveBeenCalledWith(5, { page: 1, limit: 50 })
  })

  it('reloads the finished job with category groups and exports them', async () => {
    m.getValuation.mockResolvedValue(result)
    const open = vi.spyOn(window, 'open').mockReturnValue(null)
    const wrapper = mount(Reports, { global: { stubs } })
    await flushPromises()

    m.getValuation.mockResolvedValue({
      ...result,
      category_groups: {
        parent: { id: 12, code: 'MOTO', name: 'Motocykly' },
        groups: [
          { kind: 'category', category_id: 20, code: 'NOVE', name: 'Nové', count: 1, value_total: '100.00' },
          { kind: 'parent', category_id: 12, code: 'MOTO', name: 'Motocykly', count: 0, value_total: '0.00' },
          { kind: 'uncategorized', category_id: null, code: '', name: '', count: 1, value_total: '30.00' },
        ],
        count: 2, value_total: '130.00',
      },
    })
    await wrapper.find('[data-test="valuation-group"]').setValue('12')
    await flushPromises()

    expect(m.getValuation).toHaveBeenLastCalledWith(5, { page: 1, limit: 50, group_by: 'category', group_parent_id: 12 })
    const text = wrapper.text()
    expect(text).toContain('Nové')
    expect(text).toContain('stock.reports.group_parent:{"name":"Motocykly"}')
    expect(text).toContain('stock.reports.group_outside:{"name":"Motocykly"}')
    expect(text).toContain('stock.reports.group_note')

    await wrapper.findAll('button').find(b => b.text().includes('stock.reports.export_xlsx'))!.trigger('click')
    expect(m.reportExportUrl).toHaveBeenCalledWith('valuation', 'xlsx', expect.objectContaining({ job_id: 5, group_by: 'category', group_parent_id: 12 }))
    open.mockRestore()
  })

  it('starts a valuation limited to the chosen category and shows the category of a loaded job', async () => {
    m.getValuation.mockResolvedValue({ ...result, category: { id: 12, code: 'MOTO', name: 'Motocykly' } })
    m.createValuation.mockResolvedValue({ id: 6, status: 'completed' })
    const wrapper = mount(Reports, { global: { stubs } })
    await flushPromises()
    const select = wrapper.find<HTMLSelectElement>('[data-test="valuation-category"]')
    expect(select.element.value).toBe('12')

    m.getValuation.mockResolvedValue(result)
    await select.setValue('')
    await flushPromises()
    expect(m.createValuation).toHaveBeenLastCalledWith({ date: '2026-09-30', warehouse_id: undefined, category_id: undefined })

    await select.setValue('12')
    await flushPromises()
    expect(m.createValuation).toHaveBeenLastCalledWith({ date: '2026-09-30', warehouse_id: undefined, category_id: 12 })
  })

  it('groups by top-level categories without a parent', async () => {
    m.getValuation.mockResolvedValue(result)
    const wrapper = mount(Reports, { global: { stubs } })
    await flushPromises()
    await wrapper.find('[data-test="valuation-group"]').setValue('top')
    await flushPromises()
    expect(m.getValuation).toHaveBeenLastCalledWith(5, { page: 1, limit: 50, group_by: 'category' })
  })
})
