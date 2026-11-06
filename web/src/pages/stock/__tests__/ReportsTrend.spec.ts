import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const m = vi.hoisted(() => ({
  toastError: vi.fn(),
  reportStatus: vi.fn(),
  listWarehouses: vi.fn(),
  listCategories: vi.fn(),
  listAttributes: vi.fn(),
  createValueTrend: vi.fn(),
  getValueTrendStatus: vi.fn(),
  getValueTrend: vi.fn(),
  cancelValueTrend: vi.fn(),
}))

vi.mock('@/api/stock', () => ({
  stockApi: { reportStatus: m.reportStatus, reportExportUrl: vi.fn(), listWarehouses: m.listWarehouses },
}))
vi.mock('@/api/eshop', () => ({ eshopApi: { listCategories: m.listCategories, listAttributes: m.listAttributes } }))
vi.mock('@/api/catalogJobs', () => ({
  catalogJobsApi: { createValueTrend: m.createValueTrend, getValueTrendStatus: m.getValueTrendStatus, getValueTrend: m.getValueTrend, cancelValueTrend: m.cancelValueTrend },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: (key: string) => key === 'eshop', canWrite: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError, warning: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({
  formatMoney: (value: number) => String(value), formatDate: (value: string) => value, formatNumber: (value: number) => String(value), formatDateTime: (value: string) => value,
}))
vi.mock('@/composables/useTablePrefs', () => ({
  useTablePrefs: (_key: string, columns: unknown[]) => ({ columns, isVisible: () => true, densityClass: ref('') }),
}))
vi.mock('@/components/ui/buttonStyles', () => ({ ICONS: new Proxy({}, { get: () => 'M0 0' }), btnOutline: () => '' }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }), RouterLink: { template: '<a><slot /></a>' } }))

import Reports from '../Reports.vue'

const stubs = {
  ClientSearchSelect: true, PaginationBar: true, EmptyState: true, DateInput: true, CatalogJobProgress: true, ColumnPicker: true,
  RouterLink: { template: '<a><slot /></a>' },
}

const report = (jobId: number, total: string) => ({
  job_id: jobId, dates: ['2026-10-31'], parent: null,
  groups: [{ kind: 'category', category_id: 1, code: '001', name: '001 MOTOCYKLY', values: { '2026-10-31': total } }],
  totals: { '2026-10-31': { value_total: total, count: 1 } },
})

describe('Reports – vývoj hodnoty', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.reportStatus.mockResolvedValue({ items: [], totals: { value_total: '0', count: 0 } })
    m.listWarehouses.mockResolvedValue([])
    m.listCategories.mockResolvedValue([{ id: 12, name: 'Typ zboží', path: '/12/', depth: 0, archived: false }])
    m.listAttributes.mockResolvedValue([])
    m.getValueTrendStatus.mockImplementation(async (id: number) => ({ id, status: 'completed' }))
    m.cancelValueTrend.mockResolvedValue({})
  })

  it('stará odpověď nepřepíše novější výsledek', async () => {
    let resolveFirst: (job: unknown) => void = () => {}
    m.createValueTrend
      .mockImplementationOnce(() => new Promise(resolve => { resolveFirst = resolve }))
      .mockResolvedValueOnce({ id: 2, status: 'queued' })
    m.getValueTrend.mockImplementation(async (id: number) => report(id, id === 2 ? '222' : '111'))
    const wrapper = mount(Reports, { global: { stubs } })
    await flushPromises()
    await wrapper.find('[data-test="tab-trend"]').trigger('click')
    await flushPromises()
    await wrapper.find('[data-test="trend-group"]').setValue(12)
    await flushPromises()
    resolveFirst({ id: 1, status: 'queued' })
    await flushPromises()
    expect(m.getValueTrend).toHaveBeenCalledTimes(1)
    expect(m.getValueTrend).toHaveBeenCalledWith(2)
    expect(wrapper.find('[data-test="trend-table"]').text()).toContain('222')
    expect(wrapper.find('[data-test="trend-table"]').text()).not.toContain('111')
  })

  it('chybu limitu dat ukáže uživateli', async () => {
    m.createValueTrend.mockRejectedValue({ response: { data: { error: { message: 'Zvolené období má víc než 36 dat.' } } } })
    const wrapper = mount(Reports, { global: { stubs } })
    await flushPromises()
    await wrapper.find('[data-test="tab-trend"]').trigger('click')
    await flushPromises()
    expect(m.toastError).toHaveBeenCalledWith('Zvolené období má víc než 36 dat.')
    expect(wrapper.text()).not.toContain('common.loading')
  })
  it('při přepnutí záložky zruší běžící výpočet', async () => {
    m.createValueTrend.mockResolvedValue({ id: 7, status: 'running' })
    m.getValueTrendStatus.mockResolvedValue({ id: 7, status: 'running' })
    const wrapper = mount(Reports, { global: { stubs } })
    await flushPromises()
    await wrapper.find('[data-test="tab-trend"]').trigger('click')
    await flushPromises()
    await wrapper.findAll('button').find(b => b.text() === 'stock.reports.tab_status')!.trigger('click')
    await flushPromises()
    expect(m.cancelValueTrend).toHaveBeenCalledWith(7)
    wrapper.unmount()
  })

  it('sdílenou úlohu jiného uživatele při opuštění nezruší', async () => {
    m.createValueTrend.mockResolvedValue({ id: 9, status: 'running', reused: true })
    m.getValueTrendStatus.mockResolvedValue({ id: 9, status: 'running', report: { restarts: 1 } })
    const wrapper = mount(Reports, { global: { stubs } })
    await flushPromises()
    await wrapper.find('[data-test="tab-trend"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-test="trend-restart"]').exists()).toBe(true)
    await wrapper.findAll('button').find(b => b.text() === 'stock.reports.tab_status')!.trigger('click')
    await flushPromises()
    expect(m.cancelValueTrend).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('při 404 ve sledování skončí s chybou místo opakování', async () => {
    vi.useFakeTimers()
    try {
      m.createValueTrend.mockResolvedValue({ id: 8, status: 'queued' })
      m.getValueTrendStatus.mockRejectedValue({ response: { status: 404, data: { error: { message: 'Výpočet nenalezen.' } } } })
      const wrapper = mount(Reports, { global: { stubs } })
      await flushPromises()
      await wrapper.find('[data-test="tab-trend"]').trigger('click')
      await flushPromises()
      await vi.advanceTimersByTimeAsync(20000)
      expect(m.getValueTrendStatus).toHaveBeenCalledTimes(1)
      expect(m.toastError).toHaveBeenCalledWith('Výpočet nenalezen.')
      wrapper.unmount()
    } finally { vi.useRealTimers() }
  })
})
