import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  getIncomeStatementByFunction: vi.fn(),
  getStatementFunctionMap: vi.fn(),
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: {
    listPeriods: async () => [{ id: 7, fiscal_year: 2094, starts_on: '2094-01-01', ends_on: '2094-12-31', status: 'open' }],
    getIncomeStatementByFunction: m.getIncomeStatementByFunction,
    getStatementFunctionMap: m.getStatementFunctionMap,
    setStatementFunctionMapping: vi.fn(),
    exportReport: vi.fn(),
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/composables/useAccrualSpread', () => ({
  useAccrualSpread: () => ({ enabled: { value: false }, restore: () => Promise.resolve(), set: vi.fn(), param: () => ({}) }),
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => `${v} Kč` }))

import IncomeStatementByFunction from '../IncomeStatementByFunction.vue'

const stubs = {
  ActionBar: { template: '<div />' },
  EmptyState: { template: '<div />' },
  AccrualSpreadToggle: { template: '<div />' },
  AccrualSpreadNote: { template: '<div />' },
  YearEndTaxEstimate: { name: 'YearEndTaxEstimate', props: ['periodId', 'asOf', 'format'], template: '<div data-test="estimate" />' },
}

const report = {
  statement_type: 'income_statement_purpose', version_code: 'P2', as_of: '2094-11-27', scope: 'full',
  entity: {}, period: { id: 7, fiscal_year: 2094, starts_on: '2094-01-01', ends_on: '2094-12-31' }, prev_period: null,
  rows: [{ row_code: 'A', display_code: 'A.', label: 'Čistý obrat', level: 1, row_type: 'line', amount: 100, prev_amount: 0 }],
  checks: { profit_current: 100, net_turnover: 100 },
}

beforeEach(() => {
  vi.clearAllMocks()
  m.getStatementFunctionMap.mockResolvedValue({ rows: [], unassigned: [] })
})

describe('VZZ v účelovém členění: odhad do konce roku', () => {
  it('pod výkaz přidá blok odhadu s obdobím a datem výkazu', async () => {
    m.getIncomeStatementByFunction.mockResolvedValue(report)
    const w = mount(IncomeStatementByFunction, { global: { stubs } })
    await flushPromises()
    const estimate = w.getComponent({ name: 'YearEndTaxEstimate' })
    expect(estimate.props('periodId')).toBe(7)
    expect(estimate.props('asOf')).toBe('2094-11-27')
    expect((estimate.props('format') as (v: number) => string)(5)).toBe('5 Kč')
  })

  it('bez sestaveného výkazu (neúplná mapa funkcí) blok odhadu nemá', async () => {
    m.getIncomeStatementByFunction.mockRejectedValue({ response: { data: { error: { code: 'function_map_incomplete', message: 'Chybí přiřazení' } } } })
    const w = mount(IncomeStatementByFunction, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-test="estimate"]').exists()).toBe(false)
  })

  it('výkaz filtrovaný na dimenzi blok odhadu nemá', async () => {
    m.getIncomeStatementByFunction.mockResolvedValue({ ...report, dimension: { type_id: 1, value_ids: [3] } })
    const w = mount(IncomeStatementByFunction, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-test="estimate"]').exists()).toBe(false)
  })
})
