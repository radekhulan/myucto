import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  profit: vi.fn(),
  cashFlow: vi.fn(),
  exportProfit: vi.fn(),
  exportCashFlow: vi.fn(),
  getTrialBalance: vi.fn(),
  responsibleCandidates: vi.fn(),
  replace: vi.fn(),
  routeQuery: {} as Record<string, string>,
  accountingMode: 'double_entry',
  canReports: true,
  periodStatus: 'open',
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: (permission: string) => permission !== 'reports' || m.canReports }),
}))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({
    get currentSupplier() { return { id: 1, accounting_mode: m.accountingMode } },
  }),
}))

vi.mock('@/composables/useDimensions', () => ({
  useDimensions: () => ({
    enabled: { value: true },
    types: { value: [
      { id: 1, name: 'Středisko', level: 'company', is_active: true },
      { id: 2, name: 'Projekt skupiny', level: 'global', is_active: true },
    ] },
    valueById: { value: new Map([[9, { id: 9, type_id: 2, code: 'G1', name: 'Stavba', is_active: true }]]) },
    load: () => Promise.resolve(),
  }),
}))
vi.mock('@/api/dimensions', () => ({
  dimensionsApi: {
    profit: m.profit,
    cashFlow: m.cashFlow,
    exportProfit: m.exportProfit,
    exportCashFlow: m.exportCashFlow,
    responsibleCandidates: m.responsibleCandidates,
  },
}))
vi.mock('@/api/accounting', () => ({
  accountingApi: {
    listPeriods: async () => [{ id: 2, fiscal_year: 2094, starts_on: '2094-01-01', ends_on: '2094-12-31', status: m.periodStatus }],
    getTrialBalance: m.getTrialBalance,
  },
}))
vi.mock('@/composables/useUserPrefs', () => ({ ensurePrefsLoaded: () => Promise.resolve() }))
vi.mock('@/composables/useTablePrefs', () => ({
  useTablePrefs: () => ({ densityClass: { value: '' }, sort: { value: null }, flag: () => false, isVisible: () => true }),
}))
vi.mock('vue-router', async (importOriginal) => ({
  ...await importOriginal<typeof import('vue-router')>(),
  useRoute: () => ({ query: m.routeQuery }),
  useRouter: () => ({ replace: m.replace }),
}))
const economicKey = 'dimensions.system_values.activity.economic'
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => (key === economicKey ? 'Economic activity' : key), te: (key: string) => key === economicKey, locale: { value: 'cs' } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatMoney: (v: number) => String(v), formatDate: (v: string) => v }))

import DimensionProfit from '../DimensionProfit.vue'
import DimensionProfitMatrix from '@/components/dimensions/DimensionProfitMatrix.vue'
import DimensionReportLinks from '@/components/dimensions/DimensionReportLinks.vue'
import TrialBalance from '../TrialBalance.vue'

const stubs = {
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a :data-to="JSON.stringify(to)"><slot /></a>' },
  DimensionPicker: { name: 'DimensionPicker', props: ['typeId', 'modelValue'], template: '<div data-test="picker" />' },
  DimensionReportFilter: { name: 'DimensionReportFilter', props: ['valueId', 'descendants'], template: '<div data-test="report-filter" />' },
  DimensionReportFilters: { name: 'DimensionReportFilters', props: ['valueIds', 'descendants'], template: '<div data-test="report-filters" />' },
  EmptyState: { template: '<div data-test="empty" />' },
}

const profitReport = {
  type: { id: 1, name: 'Středisko' }, from: '2094-01-01', to: '2094-12-31', supplier_ids: [1], hidden_companies: 0,
  restricted: false,
  rows: [{ value_id: 5, parent_id: null, code: 'S1', name: 'Výroba', is_active: true, depth: 0, has_children: false,
           responsible_user_name: 'Jana', own: { revenue: 0, cost: 10, result: -10 }, total: { revenue: 0, cost: 10, result: -10 } }],
  unassigned: { revenue: 0, cost: 0, result: 0 },
  totals: { revenue: 0, cost: 10, result: -10 },
}

beforeEach(() => {
  vi.clearAllMocks()
  m.routeQuery = {}
  m.accountingMode = 'double_entry'
  m.canReports = true
  m.periodStatus = 'open'
  m.profit.mockResolvedValue(profitReport)
  m.cashFlow.mockResolvedValue({
    from: '2094-01-01', to: '2094-12-31', supplier_ids: [1, 3], hidden_companies: 0, dimension: null, profit: 0,
    non_cash: { total: 0, accounts: [] }, working_capital: { total: 0, accounts: [] }, operating: 0,
    investing: { total: 0, accounts: [] }, financing: { total: 0, accounts: [] },
    net_cash_flow: 0, cash_movement: 0, untagged_cash: 0, reconciles: true,
  })
  m.responsibleCandidates.mockResolvedValue([{ id: 4, name: 'Jana' }])
})

describe('Výkazy po dimenzi', () => {
  it('načte výsledovku prvního typu a pošle větev, odpovědnou osobu a rozpad po účtech z URL', async () => {
    m.routeQuery = { type_id: '1', value_id: '5', responsible_user_id: '4', accounts: '1', from: '2094-01-01', to: '2094-12-31' }
    const w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    expect(m.profit).toHaveBeenCalledWith({
      type_id: 1, from: '2094-01-01', to: '2094-12-31', value_id: 5, responsible_user_id: 4, accounts: 1,
    })
    expect(w.find('[data-test="profit-table"]').text()).toContain('Jana')
  })

  it('přepne na peněžní tok a u globální hodnoty pošle součet za skupinu', async () => {
    m.routeQuery = { tab: 'cash_flow', dimension_value_id: '9', dimension_descendants: '0', scope: 'group', from: '2094-01-01', to: '2094-12-31' }
    const w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    expect(m.cashFlow).toHaveBeenCalledWith({
      from: '2094-01-01', to: '2094-12-31', dimension_value_id: 9, dimension_descendants: 0, scope: 'group',
    })
    expect(m.profit).not.toHaveBeenCalled()
    expect(w.find('[data-test="cash-flow-empty"]').exists()).toBe(true)
    expect(w.text()).toContain('dimensions.profit_companies')
    expect(JSON.parse(w.find('[data-test="profit-other-reports"] a[data-to]').attributes('data-to') ?? '{}').query.dimension_descendants).toBe('0')

    await w.find('[data-test="tab-profit"]').trigger('click')
    await flushPromises()
    expect(m.profit).toHaveBeenCalledTimes(1)
  })

  it('export stáhne XLSX aktivní záložky', async () => {
    m.exportProfit.mockResolvedValue(new Blob(['x']))
    const createUrl = vi.fn(() => 'blob:x')
    Object.assign(URL, { createObjectURL: createUrl, revokeObjectURL: vi.fn() })
    const w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    await w.find('[data-test="dimension-export"]').trigger('click')
    await flushPromises()
    expect(m.exportProfit).toHaveBeenCalledWith(expect.objectContaining({ type_id: 1 }), 'xlsx')
    expect(m.exportCashFlow).not.toHaveBeenCalled()
    expect(createUrl).toHaveBeenCalled()
    await w.find('[data-test="dimension-export-pdf"]').trigger('click')
    await flushPromises()
    expect(m.exportProfit).toHaveBeenCalledWith(expect.objectContaining({ type_id: 1 }), 'pdf')
  })

  it('vestavěnou hodnotu ukáže přeloženým názvem bez interního kódu', async () => {
    m.profit.mockResolvedValue({
      ...profitReport,
      type: { id: 1, name: 'Činnost', system_key: 'activity' },
      rows: [{ ...profitReport.rows[0], code: 'economic', name: 'Hospodářská činnost', semantic_key: 'economic' }],
    })
    const w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    const table = w.find('[data-test="profit-table"]')
    expect(table.text()).toContain('Economic activity')
    expect(table.text()).not.toContain('economic')
    expect(table.text()).not.toContain('Hospodářská činnost')
    expect(w.find('[data-test="profit-comparison"]').text()).toContain('Economic activity')
    expect(w.find('[data-test="profit-comparison"]').text()).not.toContain('economic')
  })

  it('skryje nulové hodnoty a zachová aktivní nadřazenou větev', async () => {
    m.profit.mockResolvedValue({
      ...profitReport,
      rows: [
        { value_id: 1, parent_id: null, code: 'P', name: 'Projekt', is_active: true, depth: 0, has_children: true,
          own: { revenue: 0, cost: 0, result: 0 }, total: { revenue: 0, cost: 10, result: -10 } },
        { value_id: 2, parent_id: 1, code: 'P1', name: 'Aktivní', is_active: true, depth: 1, has_children: false,
          own: { revenue: 0, cost: 10, result: -10 }, total: { revenue: 0, cost: 10, result: -10 } },
        { value_id: 3, parent_id: 1, code: 'P2', name: 'Nulový', is_active: true, depth: 1, has_children: false,
          own: { revenue: 0, cost: 0, result: 0 }, total: { revenue: 0, cost: 0, result: 0 } },
      ],
    })
    const w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    const table = w.find('[data-test="profit-table"]')
    expect(table.text()).toContain('Projekt')
    expect(table.text()).toContain('Aktivní')
    expect(table.text()).not.toContain('Nulový')
    expect(table.text()).not.toContain('dimensions.profit_unassigned')
  })

  it('předá vybranou dimenzi a období do dalších sestav', async () => {
    m.routeQuery = { type_id: '1', value_id: '5', from: '2094-01-01', to: '2094-12-31' }
    const w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    const links = w.findAll('[data-test="profit-other-reports"] a[data-to]')
    expect(links).toHaveLength(3)
    for (const link of links) {
      expect(JSON.parse(link.attributes('data-to') ?? '{}').query).toEqual({
        from: '2094-01-01', to: '2094-12-31', dimension_value_id: '5', dimension_descendants: '1',
      })
    }
    const rowLinks = w.findAll('[data-test="profit-row-report-5"]')
    expect(rowLinks).toHaveLength(3)
    expect(JSON.parse(rowLinks[0]!.attributes('data-to') ?? '{}').query).toEqual({
      from: '2094-01-01', to: '2094-12-31', dimension_value_id: '5', dimension_descendants: '1',
    })
  })

  it('po změně filtrů načte výsledovku bez potvrzovacího tlačítka', async () => {
    const w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    expect(w.text()).not.toContain('dimensions.profit_show')

    await w.get('[data-test="profit-type"]').setValue('2')
    await flushPromises()
    expect(m.profit).toHaveBeenLastCalledWith(expect.objectContaining({ type_id: 2 }))

    await w.get('[data-test="profit-accounts"]').setValue(true)
    await flushPromises()
    expect(m.profit).toHaveBeenLastCalledWith(expect.objectContaining({ type_id: 2, accounts: 1 }))

    await w.findAll('input[type="date"]')[0]!.setValue(`${new Date().getFullYear()}-02-01`)
    await flushPromises()
    expect(m.profit).toHaveBeenLastCalledWith(expect.objectContaining({ from: `${new Date().getFullYear()}-02-01` }))
  })

  it('volitelně zobrazí rozpad součtu skupiny po firmách', async () => {
    m.routeQuery = { type_id: '2', scope: 'group', from: '2094-01-01', to: '2094-12-31' }
    m.profit.mockResolvedValue({ ...profitReport, supplier_ids: [1, 3], companies: [
      { id: 1, name: 'První firma', revenue: 0, cost: 10, result: -10 },
      { id: 3, name: 'Druhá firma', revenue: 0, cost: 0, result: 0 },
    ] })
    const w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-test="profit-companies"]').exists()).toBe(false)
    await w.get('[data-test="profit-companies-filter"]').setValue(true)
    await flushPromises()
    expect(m.profit).toHaveBeenLastCalledWith(expect.objectContaining({ scope: 'group', companies: 1 }))
    expect(w.get('[data-test="profit-companies"]').text()).toContain('První firma')
    expect(w.get('[data-test="profit-companies"]').text()).not.toContain('Druhá firma')
    expect(m.replace).toHaveBeenLastCalledWith({ query: expect.objectContaining({ companies: '1' }) })
  })
})

describe('Odhad do konce roku ve výsledovce po dimenzi', () => {
  const zero = { posted_later: 0, accruals: 0, depreciation: 0, other: 0 }
  const estimateReport = {
    ...profitReport,
    to: '2094-11-27',
    rows: [
      { ...profitReport.rows[0], estimate: { own: { ...zero, accruals: 18200, result: 18190 }, total: { ...zero, accruals: 18200, result: 18190 } } },
      { value_id: 6, parent_id: null, code: 'S2', name: 'Stroje', is_active: true, depth: 0, has_children: false,
        own: { revenue: 0, cost: 0, result: 0 }, total: { revenue: 0, cost: 0, result: 0 },
        estimate: { own: { ...zero, posted_later: 5000, depreciation: -21600, result: -16600 }, total: { ...zero, posted_later: 5000, depreciation: -21600, result: -16600 } } },
    ],
    estimate: { applicable: true, reason: null, period: { id: 2, fiscal_year: 2094, starts_on: '2094-01-01', ends_on: '2094-12-31' },
      posted_later_from: '2094-11-28', has_other: false, check: { vh_before_tax: 1590, income_tax_posted: 0, total: 1590, matches: true } },
    unassigned_estimate: { ...zero, result: 0 },
    totals_estimate: { posted_later: 5000, accruals: 18200, depreciation: -21600, other: 0, result: 1590 },
  }

  it('přepínač pošle estimate=1 a doplní sloupce odhadu u hodnot, souhrnu i hodnoty jen s odhadem', async () => {
    m.routeQuery = { type_id: '1', from: '2094-01-01', to: '2094-11-27' }
    m.profit.mockResolvedValue(estimateReport)
    const w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    expect(m.profit).toHaveBeenLastCalledWith({ type_id: 1, from: '2094-01-01', to: '2094-11-27' })

    await w.get('[data-test="profit-estimate"]').setValue(true)
    await flushPromises()
    expect(m.profit).toHaveBeenLastCalledWith({ type_id: 1, from: '2094-01-01', to: '2094-11-27', estimate: 1 })
    expect(m.replace).toHaveBeenLastCalledWith({ query: expect.objectContaining({ estimate: '1' }) })
    expect(w.findAll('[data-test^="profit-estimate-col-"]').map(c => c.attributes('data-test'))).toEqual([
      'profit-estimate-col-posted_later', 'profit-estimate-col-accruals', 'profit-estimate-col-depreciation', 'profit-estimate-col-result',
    ])
    expect(w.get('[data-test="profit-estimate-col-posted_later"]').attributes('title')).toBe('dimensions.profit_estimate_posted_later_hint')
    expect(w.get('[data-test="profit-estimate-5-accruals"]').text()).toBe('18200')
    expect(w.get('[data-test="profit-estimate-6-depreciation"]').text()).toBe('-21600')
    expect(w.get('[data-test="profit-estimate-6-posted_later"]').text()).toBe('5000')
    expect(w.get('[data-test="profit-estimate-total-result"]').text()).toBe('1590')
    expect(w.find('[data-test="profit-estimate-note"]').text()).toContain('dimensions.profit_estimate_badge')
    expect(w.find('[data-test="profit-estimate-unassigned-result"]').exists()).toBe(false)
  })

  it('bez otevřeného období od jeho začátku, se skupinou, rozpadem po účtech nebo bez práva přepínač nenabídne', async () => {
    m.routeQuery = { type_id: '1', from: '2094-02-01', to: '2094-11-27', estimate: '1' }
    let w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-test="profit-estimate"]').exists()).toBe(false)
    expect(m.profit).toHaveBeenLastCalledWith(expect.not.objectContaining({ estimate: 1 }))

    m.routeQuery = { type_id: '1', from: '2094-01-01', to: '2094-11-27', accounts: '1' }
    w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-test="profit-estimate"]').exists()).toBe(false)

    m.routeQuery = { type_id: '2', from: '2094-01-01', to: '2094-11-27', scope: 'group' }
    w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-test="profit-estimate"]').exists()).toBe(false)

    m.routeQuery = { type_id: '1', from: '2094-01-01', to: '2094-11-27' }
    m.periodStatus = 'closed'
    w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-test="profit-estimate"]').exists()).toBe(false)

    m.periodStatus = 'open'
    m.canReports = false
    w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    expect(w.find('[data-test="profit-estimate"]').exists()).toBe(false)
  })

  it('nepoužitelný odhad vysvětlí důvodem a sloupce nepřidá', async () => {
    m.routeQuery = { type_id: '1', from: '2094-01-01', to: '2094-11-27', estimate: '1' }
    m.profit.mockResolvedValue({ ...profitReport, estimate: { applicable: false, reason: 'income_tax_posted' } })
    const w = mount(DimensionProfit, { global: { stubs } })
    await flushPromises()
    expect(m.profit).toHaveBeenLastCalledWith(expect.objectContaining({ estimate: 1 }))
    expect(w.get('[data-test="profit-estimate-unavailable"]').text()).toBe('dimensions.profit_estimate_unavailable')
    expect(w.findAll('[data-test^="profit-estimate-col-"]')).toHaveLength(0)
  })
})

describe('Propojení účetních sestav', () => {
  it('odkaz účtu z předvahy předá výpisu všechny hodnoty dimenze i volbu podřízených', async () => {
    m.routeQuery = { dimension_value_id: '9,4', dimension_descendants: '0' }
    m.getTrialBalance.mockResolvedValue({
      period: { fiscal_year: 2094 }, from: '2094-01-01', to: '2094-12-31',
      rows: [{ account_id: 7, account_code: '518', name: 'Služby', account_type: 'expense' }],
      totals: {}, checks: {},
    })
    const w = mount(TrialBalance, { global: {
      directives: { 'column-labels': {} },
      stubs: { ...stubs, ActivationBanner: true, ColumnPicker: true, DensityToggle: true, DateInput: true, SortableTh: true },
    } })
    await flushPromises()
    const links = w.findAll('a[data-to]').map(link => JSON.parse(link.attributes('data-to') ?? '{}'))
    expect(links.find(link => link.name === 'accounting-account-statement')).toEqual({
      name: 'accounting-account-statement', params: { accountId: 7 },
      query: { from: '2094-01-01', to: '2094-12-31', dimension_value_id: '9,4', dimension_descendants: '0' },
    })
  })

  it('předá z předvahy hodnotu dimenze i do výsledovky a ostatních sestav', async () => {
    const w = mount(DimensionReportLinks, {
      props: { current: 'trial', from: '2094-01-01', to: '2094-12-31', valueIds: [9], descendants: false },
      global: { stubs },
    })
    await flushPromises()
    const links = w.findAll('a[data-to]').map(link => JSON.parse(link.attributes('data-to') ?? '{}'))
    expect(links).toHaveLength(3)
    expect(links.find(link => link.name === 'accounting-dimension-profit').query).toEqual({
      from: '2094-01-01', to: '2094-12-31', type_id: '2', value_id: '9',
    })
    expect(links.find(link => link.name === 'accounting-balance-sheet').query).toEqual({
      from: '2094-01-01', to: '2094-12-31', dimension_value_id: '9', dimension_descendants: '0',
    })
  })

  it('filtr na víc hodnot předá ostatním sestavám celý, výsledovku po dimenzi vynechá', async () => {
    const w = mount(DimensionReportLinks, {
      props: { current: 'trial', from: '2094-01-01', to: '2094-12-31', valueIds: [9, 4], descendants: true },
      global: { stubs },
    })
    await flushPromises()
    const links = w.findAll('a[data-to]').map(link => JSON.parse(link.attributes('data-to') ?? '{}'))
    expect(links.map(link => link.name)).toEqual(['accounting-balance-sheet', 'accounting-general-ledger'])
    expect(links[0].query).toEqual({ from: '2094-01-01', to: '2094-12-31', dimension_value_id: '9,4', dimension_descendants: '1' })
  })
})

describe('Rozpad výsledovky po účtech', () => {
  it('skryje nulové účty i sloupce, ale zachová nenulový účet při nulovém výsledku', () => {
    const w = mount(DimensionProfitMatrix, { props: { matrix: {
      columns: [
        { key: '1', value_id: 1, code: 'P1', name: 'Aktivní' },
        { key: '2', value_id: 2, code: 'P2', name: 'Nulový' },
      ],
      rows: [
        { code: '601', name: 'Výnos', account_type: 'revenue', cells: [10, 0], total: 10 },
        { code: '501', name: 'Náklad', account_type: 'expense', cells: [10, 0], total: 10 },
        { code: '502', name: 'Nulový účet', account_type: 'expense', cells: [0, 0], total: 0 },
      ],
      results: [0, 0], total_result: 0,
    } } })
    expect(w.findAll('[data-test="matrix-row"]')).toHaveLength(2)
    expect(w.text()).toContain('P1 Aktivní')
    expect(w.text()).not.toContain('P2 Nulový')
    expect(w.text()).not.toContain('Nulový účet')
  })
})
