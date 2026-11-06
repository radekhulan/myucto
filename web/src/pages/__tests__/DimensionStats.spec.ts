import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ref } from 'vue'

const mocks = vi.hoisted(() => ({ analytics: vi.fn(), exportAnalytics: vi.fn(), replace: vi.fn(), chartCreated: vi.fn(), values: [] as { id: number; type_id: number }[] }))

vi.mock('chart.js', () => ({
  Chart: class { constructor() { mocks.chartCreated() } static register() {} destroy() {} },
  BarController: class {}, BarElement: class {}, CategoryScale: class {}, LinearScale: class {},
  LineController: class {}, LineElement: class {}, PointElement: class {}, Tooltip: class {}, Legend: class {},
}))
vi.mock('@/api/dimensions', () => ({ dimensionsApi: { analytics: mocks.analytics, exportAnalytics: mocks.exportAnalytics } }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('@/composables/useDimensions', () => ({ useDimensions: () => ({
  enabled: ref(true), types: ref([{ id: 7, name: 'Projekt', level: 'global', is_active: true }]), load: async () => {},
  overview: ref({}), values: ref(mocks.values),
}) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1, currentSupplier: { company_name: 'Mateřská firma' } }) }))
vi.mock('@/composables/useTheme', () => ({ useChartColors: () => ref({ primary: '#123456', primarySoft: '#654321', neutral: '#888888', success: '#009900', warning: '#aa8800', danger: '#aa0000', tick: '#333333', grid: '#cccccc', tooltipBg: '#000000' }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoneyWhole: (value: number) => String(value) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }), useRouter: () => ({ replace: mocks.replace }) }))

import DimensionStats from '../DimensionStats.vue'

const amounts = (revenue: number, cost: number, nonDeductible = 0) => ({ revenue, cost, result: revenue - cost, tax_deductible_cost: cost - nonDeductible, non_deductible_cost: nonDeductible, income_tax_cost: 0 })
const report = {
  type: { id: 7, name: 'Projekt', level: 'global' }, year: 2094, supplier_ids: [1, 2], hidden_companies: 1,
  rows: [{ value_id: 10, code: 'P', name: 'Projekt P', depth: 0, total: amounts(100, 40) }, { value_id: 11, code: 'ZERO', name: 'Prázdný', depth: 0, total: amounts(0, 0) }],
  totals: amounts(100, 50, 10), unassigned: amounts(0, 10, 10),
  value_totals: { '10': amounts(100, 40), '11': amounts(0, 0), '': amounts(0, 10, 10) },
  monthly: Array.from({ length: 12 }, (_, index) => ({ month: `2094-${String(index + 1).padStart(2, '0')}`, ...amounts(index === 4 ? 100 : 0, index === 4 ? 50 : 0, index === 4 ? 10 : 0) })),
  previous_monthly: Array.from({ length: 12 }, (_, index) => ({ month: `2093-${String(index + 1).padStart(2, '0')}`, ...amounts(0, 0) })),
  value_monthly: { '10': Array.from({ length: 12 }, (_, index) => ({ month: `2094-${String(index + 1).padStart(2, '0')}`, ...amounts(index === 4 ? 100 : 0, index === 4 ? 40 : 0) })) },
  companies: [{ id: 1, name: 'Mateřská firma', ...amounts(100, 0) }, { id: 2, name: 'Dceřiná firma', ...amounts(0, 50) }],
  company_value_totals: { '1': { '10': amounts(100, 0) }, '2': { '10': amounts(0, 40) } },
  available_companies: [{ id: 1, company_name: 'Mateřská firma' }, { id: 2, company_name: 'Dceřiná firma' }],
}

beforeEach(() => { vi.clearAllMocks(); mocks.values.splice(0, mocks.values.length, { id: 10, type_id: 7 }); mocks.analytics.mockResolvedValue(report); mocks.exportAnalytics.mockResolvedValue(new Blob(['x'])) })

describe('Grafy dimenzí', () => {
  it('bez hodnot dimenzí pošle firmu do jejich správy', async () => {
    mocks.values.splice(0)
    const wrapper = mount(DimensionStats, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } })
    await flushPromises()
    const empty = wrapper.find('[data-test="dimension-stats-no-values"]')
    expect(empty.exists()).toBe(true)
    expect(empty.find('a').attributes('href') ?? empty.html()).toContain('/company/dimensions')
    expect(wrapper.find('[data-test="dimension-stats-type"]').exists()).toBe(false)
  })

  it('ukáže souhrn a po volbě skupiny načte součet přístupných firem', async () => {
    const wrapper = mount(DimensionStats, { global: { stubs: { RouterLink: { template: '<a><slot /></a>' }, EmptyState: true } } })
    await flushPromises()
    expect(mocks.analytics).toHaveBeenCalledWith(expect.objectContaining({ type_id: 7, supplier_id: 'all' }))
    expect(mocks.chartCreated.mock.calls.length).toBeGreaterThanOrEqual(3)
    expect(wrapper.find('[data-test="dimension-stats-comparison"]').text()).toContain('Projekt P')
    expect(wrapper.find('[data-test="dimension-stats-companies"]').text()).toContain('Dceřiná firma')
    expect(wrapper.find('[data-test="dimension-stats-comparison"]').text()).not.toContain('Prázdný')
    expect(wrapper.findAll('[data-test="dimension-stats-monthly"] tbody tr')).toHaveLength(1)
    expect(wrapper.find('[data-test="dimension-stats-comparison"] tfoot').text()).toContain('100')
    expect(wrapper.find('[data-test="dimension-stats-companies"] tfoot').text()).toContain('50')
    const revenuePill = wrapper.findAll('[data-test="dimension-stats-metric"] button').find(button => button.text() === 'dimensions.analytics_revenue')!
    await revenuePill.trigger('click')
    expect(revenuePill.attributes('aria-pressed')).toBe('true')
    const comparisonRow = wrapper.find('[data-test="dimension-stats-row-10"]').element.closest('tr')!
    expect(comparisonRow.classList.contains('cursor-pointer')).toBe(true)
    await wrapper.find('[data-test="dimension-stats-row-10"]').element.closest('tr')!.querySelectorAll('td')[1]!.click()
    await flushPromises()
    expect(wrapper.find('[data-test="dimension-stats-selected"]').text()).toContain('Projekt P')
    expect(wrapper.find('[data-test="dimension-stats-monthly"]').text()).toContain('40')
    expect(wrapper.find('[data-test="dimension-stats-monthly"]').text()).not.toContain('50')
    expect(wrapper.find('[data-test="dimension-stats-companies"]').text()).toContain('40')
    const createUrl = vi.fn(() => 'blob:x')
    Object.assign(URL, { createObjectURL: createUrl, revokeObjectURL: vi.fn() })
    await wrapper.find('[data-test="dimension-stats-export-monthly-pdf"]').trigger('click')
    await flushPromises()
    expect(mocks.exportAnalytics).toHaveBeenCalledWith(expect.objectContaining({ table: 'monthly', value_id: 10, supplier_id: 'all', format: 'pdf' }))
    await wrapper.find('[data-test="dimension-stats-export-companies-xlsx"]').trigger('click')
    await flushPromises()
    expect(mocks.exportAnalytics).toHaveBeenCalledWith(expect.objectContaining({ table: 'companies', value_id: 10, format: 'xlsx' }))
    expect(createUrl).toHaveBeenCalledTimes(2)
    await wrapper.find('[data-test="dimension-stats-company"]').setValue('2')
    await flushPromises()
    expect(mocks.analytics).toHaveBeenCalledWith(expect.objectContaining({ type_id: 7, supplier_id: 2 }))
    await wrapper.find('[data-test="dimension-stats-company"]').setValue('all')
    await flushPromises()
    expect(mocks.analytics).toHaveBeenCalledWith(expect.objectContaining({ type_id: 7, supplier_id: 'all' }))
  })
})
