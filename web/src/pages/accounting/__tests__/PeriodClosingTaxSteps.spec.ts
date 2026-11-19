import { describe, it, expect, beforeEach, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import type { ClosingState, ClosingStep, DeferredTaxPreview, IncomeTaxPreview } from '@/api/closing'

const m = vi.hoisted(() => ({
  state: vi.fn(),
  runStep: vi.fn(),
  revertStep: vi.fn(),
  deferredTaxPreview: vi.fn(),
  incomeTaxPreview: vi.fn(),
  smallAssetAccrualPreview: vi.fn(),
  listAccounts: vi.fn(),
}))

vi.mock('@/api/closing', () => ({
  closingApi: {
    state: m.state,
    runStep: m.runStep,
    revertStep: m.revertStep,
    deferredTaxPreview: m.deferredTaxPreview,
    incomeTaxPreview: m.incomeTaxPreview,
    start: vi.fn(), abort: vi.fn(), fxPreview: vi.fn(), provisionsPreview: vi.fn(),
    estimatesSuggest: vi.fn(), smallAssetAccrualPreview: m.smallAssetAccrualPreview,
    prepaidExpenseAccrualPreview: vi.fn(), profitDistributionPreview: vi.fn(),
    profitDistribution: vi.fn(), profitDistributionRevert: vi.fn(), createEntry: vi.fn(),
    reverseEntry: vi.fn(), close: vi.fn(), openNext: vi.fn(),
  },
}))

vi.mock('@/api/accounting', () => ({
  accountingApi: { listAccounts: m.listAccounts },
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '1' } }),
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ can: () => true, canWrite: () => true }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn() }),
}))

vi.mock('@/composables/useFormat', () => ({
  formatMoney: (v: number | null | undefined) => String(v ?? 0),
  formatDate: (d: string | null | undefined) => String(d ?? ''),
}))

import PeriodClosing from '@/pages/accounting/PeriodClosing.vue'

const ORDER_500 = [
  'precheck', 'depreciation', 'fx_revaluation', 'estimates', 'deferrals', 'provisions',
  'income_tax', 'deferred_tax', 'stock', 'close_books', 'open_next',
]

function makeState(overrides: Partial<ClosingState> = {}): ClosingState {
  return {
    step_order: ORDER_500,
    period: { id: 1, fiscal_year: 2025, starts_on: '2025-01-01', ends_on: '2025-12-31', status: 'closing', row_version: 7 },
    steps: [],
    stock_step_required: false,
    depreciation_step_required: false,
    can_close: true,
    ...overrides,
  } as unknown as ClosingState
}

function preview(overrides: Partial<DeferredTaxPreview> = {}): DeferredTaxPreview {
  return {
    as_of: '2025-12-31',
    period: { id: 1, fiscal_year: 2025 },
    taxpayer_type: 'po',
    applicable: true,
    computation: {
      rate: 0.21, rate_year: 2026, rate_is_fallback: false,
      titles: [{ key: 'assets', label: 'Dlouhodobý majetek', difference: 100000, kind: 'taxable' }],
      net_difference: 100000, deferred_tax: 21000, kind: 'liability', requires_prudence_check: false, warnings: [],
    },
    balance_481: -5000,
    balance_592: 0,
    movement: 16000,
    existing_entry_id: null,
    existing_amount: null,
    ...overrides,
  }
}

async function select(wrapper: ReturnType<typeof mount>, key: string) {
  const btn = wrapper.findAll('button').find(b => new RegExp(`steps\\.${key}\\.title`).test(b.text()))
  expect(btn).toBeTruthy()
  await btn!.trigger('click')
  await flushPromises()
}

describe('PeriodClosing.vue - daň z příjmů a odložená daň', () => {
  beforeEach(() => {
    for (const fn of Object.values(m)) fn.mockReset()
    m.listAccounts.mockResolvedValue([])
    m.runStep.mockResolvedValue({})
    m.incomeTaxPreview.mockResolvedValue(null)
  })

  it('u 500 nabízí volitelnou odloženou daň hned po splatné dani a zaúčtuje navrženou částku', async () => {
    m.state.mockResolvedValue(makeState())
    m.deferredTaxPreview.mockResolvedValue(preview())
    const wrapper = mount(PeriodClosing)
    await flushPromises()

    const keys = wrapper.findAll('button').map(b => b.text().match(/steps\.(\w+)\.title/)?.[1]).filter(Boolean)
    expect(keys.indexOf('deferred_tax')).toBe(keys.indexOf('income_tax') + 1)

    await select(wrapper, 'deferred_tax')
    expect(m.deferredTaxPreview).toHaveBeenCalledWith(1)
    expect(wrapper.text()).toContain('accounting.closing.steps.deferred_tax.description_500')
    expect(wrapper.text()).toContain('accounting.closing.deferred_tax500.hint')
    expect(wrapper.text()).toContain('Dlouhodobý majetek')

    await wrapper.get('[data-test="deferred-tax-500-book"]').trigger('click')
    await flushPromises()
    expect(m.runStep).toHaveBeenCalledWith(1, 'deferred_tax', { row_version: 7, amount: 16000 })
  })

  it('opakované zaúčtování navrhne celou částku, ne nulový rozdíl proti vlastnímu zápisu', async () => {
    const done: ClosingStep = { step_key: 'deferred_tax', status: 'done', payload: { amount: 16000, document_no: 'UZ-1' } as unknown as ClosingStep['payload'], note: null, done_at: '2025-12-31', done_by: 1 }
    m.state.mockResolvedValue(makeState({ steps: [done] }))
    m.deferredTaxPreview.mockResolvedValue(preview({ balance_481: -21000, movement: 0, existing_entry_id: 9, existing_amount: 16000 }))
    const wrapper = mount(PeriodClosing)
    await flushPromises()
    await select(wrapper, 'deferred_tax')

    await wrapper.get('[data-test="deferred-tax-500-book"]').trigger('click')
    await flushPromises()
    expect(m.runStep).toHaveBeenCalledWith(1, 'deferred_tax', { row_version: 7, amount: 16000 })
  })

  it('přeskočení odložené daně jde přes potvrzení kroku', async () => {
    m.state.mockResolvedValue(makeState())
    m.deferredTaxPreview.mockResolvedValue(preview())
    const wrapper = mount(PeriodClosing)
    await flushPromises()
    await select(wrapper, 'deferred_tax')

    await wrapper.get('[data-test="deferred-tax-500-skip"]').trigger('click')
    await flushPromises()
    expect(m.runStep).toHaveBeenCalledWith(1, 'deferred_tax', { row_version: 7, status: 'skipped' })
  })

  it('pohledávku nepošle bez potvrzení posouzení, s ním ano', async () => {
    m.state.mockResolvedValue(makeState())
    m.deferredTaxPreview.mockResolvedValue(preview({ movement: -3000 }))
    const wrapper = mount(PeriodClosing)
    await flushPromises()
    await select(wrapper, 'deferred_tax')

    await wrapper.get('[data-test="deferred-tax-500-prudence"] input').setValue(true)
    await wrapper.get('[data-test="deferred-tax-500-book"]').trigger('click')
    await flushPromises()
    expect(m.runStep).toHaveBeenCalledWith(1, 'deferred_tax', { row_version: 7, amount: -3000, prudence_confirmed: true })
  })

  it('daň k přepočtu ukáže v kroku daně i u uzavření knih a jde potvrdit beze změny', async () => {
    const tax: ClosingStep = { step_key: 'income_tax', status: 'done', payload: { amount: 1000 } as unknown as ClosingStep['payload'], note: null, done_at: '2025-12-31', done_by: 1 }
    m.state.mockResolvedValue(makeState({
      steps: [tax],
      can_close: false,
      income_tax_stale: { caused_by: ['stock'], at: '2026-01-10 10:00:00' },
    }))
    const wrapper = mount(PeriodClosing)
    await flushPromises()

    await select(wrapper, 'close_books')
    expect(wrapper.text()).toContain('accounting.closing.close.blocked_income_tax_stale')

    await select(wrapper, 'income_tax')
    expect(wrapper.get('[data-test="income-tax-stale"]').text()).toContain('accounting.closing.income_tax.stale')
    await wrapper.get('[data-test="income-tax-stale-confirm"]').trigger('click')
    await flushPromises()
    expect(m.runStep).toHaveBeenCalledWith(1, 'income_tax', { row_version: 7, status: 'done' })
  })

  it('chybějící posouzení vysvětlí u uzavření knih místo obecné hlášky', async () => {
    m.state.mockResolvedValue(makeState({ can_close: false, close_blockers: ['nonprofit_tax_assessment'] }))
    const wrapper = mount(PeriodClosing)
    await flushPromises()
    await select(wrapper, 'close_books')
    expect(wrapper.text()).toContain('accounting.closing.close.blocked_assessment')
    expect(wrapper.text()).not.toContain('accounting.closing.close.blocked_steps')
  })

  it('zbytek po převzaté uzávěrce vysvětlí u uzavření knih i s účty', async () => {
    m.state.mockResolvedValue(makeState({
      can_close: false, closing_taken_over: true,
      close_blockers: ['closing_taken_over_residual'], taken_over_residual_accounts: ['518', '321'],
    }))
    const wrapper = mount(PeriodClosing)
    await flushPromises()
    await select(wrapper, 'close_books')
    expect(wrapper.text()).toContain('accounting.closing.close.blocked_taken_over_residual')
    expect(wrapper.text()).not.toContain('accounting.closing.close.blocked_assessment')
  })

  function taxPreview(overrides: Partial<IncomeTaxPreview> = {}): IncomeTaxPreview {
    return {
      as_of: '2025-12-31', period: { id: 1, fiscal_year: 2025 }, suggested_amount: 691950,
      suggested_source: 'finalized_return', has_finalized_return: true, taxpayer_type: 'po', applicable: true,
      rate_hint: 0.21, balance_341: -691950, balance_591: 691950, booked_amount: 0, booked_outside_wizard: 0,
      closing_taken_over: false, existing_entry_id: null, existing_amount: null, ...overrides,
    }
  }

  it('v roce s převzatou uzávěrkou daň jen potvrdí, nový předpis nenabídne', async () => {
    m.state.mockResolvedValue(makeState({ closing_taken_over: true }))
    m.incomeTaxPreview.mockResolvedValue(taxPreview({ closing_taken_over: true, booked_amount: 691950, booked_outside_wizard: 691950 }))
    const wrapper = mount(PeriodClosing)
    await flushPromises()
    await select(wrapper, 'income_tax')
    expect(wrapper.get('[data-test="income-tax-booked-outside"]').text()).toContain('accounting.closing.income_tax.booked_taken_over')
    expect(wrapper.find('[data-test="income-tax-book"]').exists()).toBe(false)
    const confirmBtn = wrapper.get('[data-test="income-tax-confirm-booked"]')
    expect(confirmBtn.text()).toContain('accounting.closing.income_tax.confirm_taken_over')
    await confirmBtn.trigger('click')
    await flushPromises()
    expect(m.runStep).toHaveBeenCalledWith(1, 'income_tax', { row_version: 7, status: 'done' })
  })

  it('daň zaúčtovanou ručním zápisem nezdvojí, nabídne potvrzení', async () => {
    m.state.mockResolvedValue(makeState())
    m.incomeTaxPreview.mockResolvedValue(taxPreview({ booked_amount: 691950, booked_outside_wizard: 691950 }))
    const wrapper = mount(PeriodClosing)
    await flushPromises()
    await select(wrapper, 'income_tax')
    expect(wrapper.get('[data-test="income-tax-booked-outside"]').text()).toContain('accounting.closing.income_tax.booked_outside')
    expect(wrapper.get('[data-test="income-tax-confirm-booked"]').text()).toContain('accounting.closing.income_tax.confirm_booked')
    expect(wrapper.find('[data-test="income-tax-book"]').exists()).toBe(false)
  })

  it('bez daně zaúčtované jinde nabízí běžné zaúčtování', async () => {
    m.state.mockResolvedValue(makeState())
    m.incomeTaxPreview.mockResolvedValue(taxPreview())
    const wrapper = mount(PeriodClosing)
    await flushPromises()
    await select(wrapper, 'income_tax')
    expect(wrapper.find('[data-test="income-tax-booked-outside"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="income-tax-book"]').exists()).toBe(true)
  })

  it('odklad drobného majetku zaúčtovaný jiným zápisem ukáže a znovu ho nenabídne zaúčtovat', async () => {
    m.state.mockResolvedValue(makeState())
    m.smallAssetAccrualPreview.mockResolvedValue({
      as_of: '2025-12-31', period: { id: 1, fiscal_year: 2025, starts_on: '2025-01-01', ends_on: '2025-12-31' },
      mode: 'flat_pct', pct: 50, period_days: 365, items: [], total: 90140.47, materiality: null,
      cards_total: 179271.85, breakdown_501_small_asset: 180280.94, cards_vs_501_diff: -1009.09, existing: null,
      booked_outside: { amount: 90141, entries: [{ entry_id: 5, document_no: 'INT 19', entry_date: '2025-12-31', amount: 90141 }] },
      closing_taken_over: false, post_blocked: 'booked_outside',
    })
    const wrapper = mount(PeriodClosing)
    await flushPromises()
    await select(wrapper, 'deferrals')
    expect(wrapper.get('[data-test="small-asset-post-blocked"]').text()).toContain('accounting.closing.small_asset.booked_outside')
    expect(wrapper.find('[data-test="small-asset-post"]').exists()).toBe(false)
  })

  it('převzatou uzávěrku ohlásí bannerem i se zmrazenými dimenzemi', async () => {
    m.state.mockResolvedValue(makeState({ closing_taken_over: true }))
    const wrapper = mount(PeriodClosing)
    await flushPromises()
    const banner = wrapper.get('[data-test="closing-taken-over"]').text()
    expect(banner).toContain('accounting.closing.taken_over.title')
    expect(banner).toContain('accounting.closing.taken_over.dimensions')
  })

  it('bez převzaté uzávěrky banner nezobrazí', async () => {
    m.state.mockResolvedValue(makeState())
    const wrapper = mount(PeriodClosing)
    await flushPromises()
    expect(wrapper.find('[data-test="closing-taken-over"]').exists()).toBe(false)
  })
})
