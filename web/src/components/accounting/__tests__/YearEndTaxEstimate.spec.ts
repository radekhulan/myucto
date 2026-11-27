import { describe, expect, it, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import YearEndTaxEstimate from '@/components/accounting/YearEndTaxEstimate.vue'
import type { YearEndTaxEstimate as Estimate } from '@/api/accounting'
import { setLoadedDisplayPrefs } from '@/composables/useDisplayPrefs'

const getYearEndTaxEstimate = vi.fn()

vi.mock('@/api/accounting', () => ({
  accountingApi: { getYearEndTaxEstimate: (id: number, asOf?: string) => getYearEndTaxEstimate(id, asOf) },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key} ${JSON.stringify(params)}` : key }),
}))

vi.mock('@/composables/useFormat', () => ({
  formatDate: (v: string) => v,
}))

const RouterLinkStub = {
  name: 'RouterLink',
  props: ['to'],
  template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
}

function estimate(patch: Partial<Estimate> = {}): Estimate {
  return {
    applicable: true,
    reason: null,
    period: { id: 7, fiscal_year: 2099, starts_on: '2099-01-01', ends_on: '2099-12-31', status: 'open' },
    return_status: 'draft',
    vh_posted: 650000,
    closing_items: [
      { key: 'small_asset_accrual', label_key: 'taxReturn.proj_small_asset', amount: 20000, sign: 1, optional: false },
      { key: 'estimate', label_key: 'taxReturn.proj_estimate', amount: 11000, sign: -1, optional: true },
      { key: 'depreciation', label_key: 'taxReturn.proj_depreciation', amount: 24000, sign: -1, optional: false },
    ],
    is_projection: true,
    vh_before_tax: 670000,
    increases: 50000,
    decreases: 0,
    tax_base: 720000,
    tax: 151200,
    advances_paid: 10000,
    advances_source: 'return',
    balance_due: 141200,
    vh_after_tax: 518800,
    ...patch,
  }
}

function mountBlock(props: { asOf?: string; part?: 'profit_loss' | 'balance' } = {}) {
  return mount(YearEndTaxEstimate, {
    props: { periodId: 7, format: (v: number | null | undefined) => String(v), ...props },
    global: { stubs: { RouterLink: RouterLinkStub } },
  })
}

async function expand(w: ReturnType<typeof mountBlock>) {
  await w.find('[data-test="estimate-toggle"]').trigger('click')
}

describe('YearEndTaxEstimate', () => {
  beforeEach(() => {
    getYearEndTaxEstimate.mockReset()
    setLoadedDisplayPrefs({})
  })

  it('s Předvolbou zobrazení ukáže rozpad rovnou, ruční sbalení ji nemění', async () => {
    setLoadedDisplayPrefs({ estimate_open: true })
    getYearEndTaxEstimate.mockResolvedValue(estimate())
    const w = mountBlock({ asOf: '2099-11-27' })
    await flushPromises()
    expect(w.find('[data-test="estimate-toggle"]').attributes('aria-expanded')).toBe('true')
    expect(w.find('[data-test="estimate-detail"]').exists()).toBe(true)
    await expand(w)
    expect(w.find('[data-test="estimate-detail"]').exists()).toBe(false)
  })

  it('loads the estimate lazily and shows every figure with its source', async () => {
    getYearEndTaxEstimate.mockResolvedValue(estimate())
    const w = mountBlock()
    expect(w.find('[data-test="estimate-loading"]').exists()).toBe(true)
    await flushPromises()

    expect(getYearEndTaxEstimate).toHaveBeenCalledWith(7, undefined)
    expect(w.text()).toContain('accounting.statement_accounts.estimate.badge')
    expect(w.find('[data-test="estimate-detail"]').exists()).toBe(false)
    await expand(w)
    expect(w.find('[data-test="estimate-vh-before-tax"]').text()).toContain('670000')
    expect(w.find('[data-test="estimate-tax"]').text()).toContain('− 151200')
    expect(w.find('[data-test="estimate-vh-after-tax"]').text()).toContain('518800')
    expect(w.find('[data-test="estimate-closing-small_asset_accrual"]').text()).toContain('+ 20000')
    const optional = w.find('[data-test="estimate-closing-estimate"]')
    expect(optional.text()).toContain('accounting.statement_accounts.estimate.optional_hint')
    expect(optional.classes()).toContain('text-neutral-400')
    const dep = w.find('[data-test="estimate-closing-depreciation"]')
    expect(dep.text()).toContain('taxReturn.proj_depreciation')
    expect(dep.text()).toContain('− 24000')
    expect(dep.classes()).not.toContain('text-neutral-400')
    expect(dep.find('a').attributes('data-to')).toBe(JSON.stringify({ name: 'accounting-assets' }))

    const links = w.findAll('a').map(a => a.attributes('data-to'))
    expect(links).toContain(JSON.stringify({ name: 'reports-income-tax', query: { year: '2099', tab: 'nahled' } }))
    expect(links).toContain(JSON.stringify({ name: 'reports-income-tax', query: { year: '2099', tab: 'zalohy' } }))
    expect(links).toContain(JSON.stringify({ name: 'accounting-period-closing', params: { id: 7 } }))
    expect(links).toContain(JSON.stringify({ name: 'accounting-assets' }))
  })

  it('warns that the projection is incomplete when a closing step preview failed', async () => {
    getYearEndTaxEstimate.mockResolvedValue(estimate({ projection_warnings: [{ source: 'small_asset', reason: 'Neplatná politika' }] }))
    const w = mountBlock()
    await flushPromises()
    const box = w.find('[data-test="estimate-projection-warnings"]')
    expect(box.exists()).toBe(true)
    expect(box.text()).toContain('taxReturn.projection_incomplete')
    expect(box.text()).toContain('taxReturn.proj_small_asset: Neplatná politika')
    expect(w.find('[data-test="estimate-main-before-tax"]').exists()).toBe(true)
  })

  it('shows no projection warning when every step was previewed', async () => {
    getYearEndTaxEstimate.mockResolvedValue(estimate({ projection_warnings: [] }))
    const w = mountBlock()
    await flushPromises()
    expect(w.find('[data-test="estimate-projection-warnings"]').exists()).toBe(false)
  })

  it('lists depreciation warnings from the DPPO preview', async () => {
    getYearEndTaxEstimate.mockResolvedValue(estimate({ depreciation_warnings: ['Karty majetku v konceptu (2) se neodpisují.'] }))
    const w = mountBlock()
    await flushPromises()
    const box = w.find('[data-test="estimate-depreciation-warnings"]')
    expect(box.exists()).toBe(true)
    expect(box.text()).toContain('Karty majetku v konceptu (2) se neodpisují.')
  })

  it('shows the headline estimate and keeps the breakdown collapsed until asked', async () => {
    getYearEndTaxEstimate.mockResolvedValue(estimate())
    const w = mountBlock({ asOf: '2099-11-27' })
    await flushPromises()
    expect(getYearEndTaxEstimate).toHaveBeenCalledWith(7, '2099-11-27')
    expect(w.find('[data-test="estimate-main-before-tax"]').text()).toContain('670000')
    expect(w.find('[data-test="estimate-main-after-tax"]').text()).toContain('518800')
    expect(w.find('[data-test="estimate-main-341"]').exists()).toBe(false)
    expect(w.find('[data-test="estimate-detail"]').exists()).toBe(false)
    expect(w.find('[data-test="estimate-toggle"]').attributes('aria-expanded')).toBe('false')
    await expand(w)
    expect(w.find('[data-test="estimate-detail"]').exists()).toBe(true)
    await expand(w)
    expect(w.find('[data-test="estimate-detail"]').exists()).toBe(false)
  })

  it('hides the after-tax headline when the tax is unknown', async () => {
    getYearEndTaxEstimate.mockResolvedValue(estimate({ tax: undefined }))
    const w = mountBlock()
    await flushPromises()
    expect(w.find('[data-test="estimate-main-before-tax"]').exists()).toBe(true)
    expect(w.find('[data-test="estimate-main-after-tax"]').exists()).toBe(false)
  })

  it('bridges the report-date result to the estimate, each amount once and in order', async () => {
    getYearEndTaxEstimate.mockResolvedValue(estimate({
      vh_posted: 164099.61,
      bridge: { as_of: '2099-11-27', ends_on: '2099-12-31', vh_as_of: -2291099.41, vh_posted: 164099.61, groups: [
        { key: 'deferred_revenue', amount: 3118243.66 },
        { key: 'prepaid_expenses', amount: -38944.85 },
        { key: 'depreciation', amount: -624099.79 },
      ] },
    }))
    const w = mountBlock({ asOf: '2099-11-27' })
    await flushPromises()
    await expand(w)
    const order = w.findAll('[data-test="estimate-detail"] tr[data-test]').map(r => r.attributes('data-test'))
    expect(order).toEqual([
      'estimate-bridge-as-of',
      'estimate-bridge-deferred_revenue',
      'estimate-bridge-prepaid_expenses',
      'estimate-bridge-depreciation',
      'estimate-vh-posted',
      'estimate-closing-small_asset_accrual',
      'estimate-closing-estimate',
      'estimate-closing-depreciation',
      'estimate-vh-before-tax',
      'estimate-tax',
      'estimate-balance-due',
      'estimate-vh-after-tax',
    ])
    expect(w.find('[data-test="estimate-bridge-as-of"]').text()).toContain('-2291099.41')
    expect(w.find('[data-test="estimate-bridge-deferred_revenue"]').text()).toContain('+ 3118243.66')
    expect(w.find('[data-test="estimate-bridge-prepaid_expenses"]').text()).toContain('− 38944.85')
    expect(w.find('[data-test="estimate-bridge-depreciation"] a').attributes('data-to'))
      .toBe(JSON.stringify({ name: 'accounting-journal', query: { date_from: '2099-11-28', date_to: '2099-12-31', posted: 'posted' } }))
    const posted = w.find('[data-test="estimate-vh-posted"]')
    expect(posted.text()).toContain('accounting.statement_accounts.estimate.vh_posted')
    expect(posted.text()).toContain('164099.61')
  })

  it('shows balance sheet changes and the 341 tax payable only on the balance sheet', async () => {
    getYearEndTaxEstimate.mockResolvedValue(estimate({
      balance_effects: [
        { key: 'small_asset_accrual', label_key: 'taxReturn.proj_small_asset', accounts: '381', side: 'asset', assets: 20000, liabilities: 0, optional: false },
        { key: 'estimate', label_key: 'taxReturn.proj_estimate', accounts: '389', side: 'liability', assets: 0, liabilities: 11000, optional: true },
        { key: 'depreciation', label_key: 'taxReturn.proj_depreciation', accounts: '07x/08x', side: 'asset', assets: -24000, liabilities: 0, optional: false },
        { key: 'income_tax', label_key: 'accounting.statement_accounts.estimate.balance_income_tax', accounts: '341', side: 'liability', assets: 0, liabilities: 151200, optional: false },
      ],
      balance_check: { assets: -4000, liabilities: 151200, difference: -155200, expected: -155200, matches: true },
    }))
    const w = mountBlock({ asOf: '2099-11-27', part: 'balance' })
    await flushPromises()
    expect(w.find('[data-test="estimate-main-341"]').text()).toContain('141200')
    await expand(w)
    expect(w.find('[data-test="estimate-balance-depreciation"]').text()).toContain('07x/08x')
    expect(w.find('[data-test="estimate-balance-depreciation"]').text()).toContain('− 24000')
    expect(w.find('[data-test="estimate-balance-income_tax"]').text()).toContain('+ 151200')
    expect(w.find('[data-test="estimate-balance-estimate"]').classes()).toContain('text-neutral-400')
    expect(w.find('[data-test="estimate-balance-check"]').text()).toContain('balance_check_ok')

    getYearEndTaxEstimate.mockResolvedValue(estimate())
    const pl = mountBlock()
    await flushPromises()
    await expand(pl)
    expect(pl.find('[data-test="estimate-balance-effects"]').exists()).toBe(false)
  })

  it('renders nothing for a closed year or a posted income tax', async () => {
    getYearEndTaxEstimate.mockResolvedValue(estimate({ applicable: false, reason: 'income_tax_posted' }))
    const w = mountBlock()
    await flushPromises()
    expect(w.find('[data-test="year-end-estimate"]').exists()).toBe(false)
  })

  it('stays hidden without the tax return permission', async () => {
    getYearEndTaxEstimate.mockRejectedValueOnce({ response: { status: 403 } })
    const w = mountBlock()
    await flushPromises()
    expect(w.find('[data-test="year-end-estimate"]').exists()).toBe(false)
    expect(w.find('[data-test="estimate-error"]').exists()).toBe(false)
  })
})
