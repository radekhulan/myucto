import { describe, expect, it, vi, beforeEach } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import YearEndTaxEstimate from '@/components/accounting/YearEndTaxEstimate.vue'
import type { YearEndTaxEstimate as Estimate } from '@/api/accounting'

const getYearEndTaxEstimate = vi.fn()

vi.mock('@/api/accounting', () => ({
  accountingApi: { getYearEndTaxEstimate: (id: number) => getYearEndTaxEstimate(id) },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
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

function mountBlock() {
  return mount(YearEndTaxEstimate, {
    props: { periodId: 7, format: (v: number | null | undefined) => String(v) },
    global: { stubs: { RouterLink: RouterLinkStub } },
  })
}

describe('YearEndTaxEstimate', () => {
  beforeEach(() => getYearEndTaxEstimate.mockReset())

  it('loads the estimate lazily and shows every figure with its source', async () => {
    getYearEndTaxEstimate.mockResolvedValue(estimate())
    const w = mountBlock()
    expect(w.find('[data-test="estimate-loading"]').exists()).toBe(true)
    await flushPromises()

    expect(getYearEndTaxEstimate).toHaveBeenCalledWith(7)
    expect(w.text()).toContain('accounting.statement_accounts.estimate.badge')
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
    expect(w.find('[data-test="estimate-vh-before-tax"]').exists()).toBe(true)
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
