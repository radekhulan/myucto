import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type {
  PayrollPostingReconciliation,
  PayrollPostingReconciliationCategory,
} from '@/api/payrollPosting'

const m = vi.hoisted(() => ({
  reconciliation: vi.fn(),
}))

vi.mock('@/api/payrollPosting', () => ({
  payrollPostingApi: { reconciliation: m.reconciliation },
}))
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (_error: unknown, fallback: string) => fallback,
}))
vi.mock('@/composables/useFormat', () => ({
  formatMoneyMinor: (value: number | null | undefined) => value == null ? '—' : `money:${value}`,
}))
vi.mock('@/pages/payroll/payrollComponentsUi', () => ({
  localPayrollPeriod: () => '2026-08',
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

import PayrollPostingReconciliationPage from '@/pages/payroll/PayrollPostingReconciliation.vue'

function category(
  overrides: Partial<PayrollPostingReconciliationCategory> = {},
): PayrollPostingReconciliationCategory {
  return {
    key: 'gross_wages',
    payroll_minor: 1000,
    journal_minor: 1000,
    payments_liability_minor: 1000,
    payments_paid_minor: 1000,
    diff_payroll_journal_minor: 0,
    diff_payroll_payments_minor: 0,
    status: 'match',
    ...overrides,
  }
}

function reconciliation(
  overrides: Partial<PayrollPostingReconciliation> = {},
): PayrollPostingReconciliation {
  return {
    schema_version: 'payroll-posting-reconciliation.v1',
    supplier_id: 1,
    period: '2026-08',
    accounting_mode: 'double_entry',
    run: { id: 10, status: 'approved' },
    revision: { id: 20, revision_no: 1, status: 'approved' },
    journal_state: 'posted',
    payments_state: 'materialized',
    overall_status: 'reconciled',
    categories: [category()],
    ...overrides,
  }
}

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason?: unknown) => void
  const promise = new Promise<T>((promiseResolve, promiseReject) => {
    resolve = promiseResolve
    reject = promiseReject
  })
  return { promise, resolve, reject }
}

describe('PayrollPostingReconciliation', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.reconciliation.mockResolvedValue(reconciliation())
  })

  it('renders match, difference and not-applicable categories in statutory order on desktop and mobile', async () => {
    m.reconciliation.mockResolvedValue(reconciliation({
      overall_status: 'diff',
      categories: [
        category({
          key: 'net_wage',
          payroll_minor: 3000,
          journal_minor: null,
          payments_liability_minor: null,
          payments_paid_minor: null,
          diff_payroll_journal_minor: null,
          diff_payroll_payments_minor: null,
          status: 'not_applicable',
        }),
        category({
          key: 'income_tax',
          payroll_minor: 2000,
          journal_minor: 1875,
          payments_liability_minor: 2000,
          payments_paid_minor: 2050,
          diff_payroll_journal_minor: 125,
          diff_payroll_payments_minor: -50,
          status: 'diff',
        }),
        category({ key: 'gross_wages', status: 'match' }),
      ],
    }))

    const wrapper = mount(PayrollPostingReconciliationPage)
    await flushPromises()

    const desktop = wrapper.get('[data-test="reconciliation-desktop"]')
    const mobile = wrapper.get('[data-test="reconciliation-mobile"]')
    expect(desktop.classes()).toEqual(expect.arrayContaining(['hidden', 'md:block']))
    expect(mobile.classes()).toEqual(expect.arrayContaining(['md:hidden']))

    const expectedOrder = [
      'payroll.posting_reconciliation.categories.gross_wages',
      'payroll.posting_reconciliation.categories.income_tax',
      'payroll.posting_reconciliation.categories.net_wage',
    ]
    expect(desktop.findAll('[data-test^="reconciliation-desktop-toggle-"]').map(node => node.text()))
      .toEqual(expectedOrder)
    expect(mobile.findAll('[data-test^="reconciliation-mobile-toggle-"]').map(node => node.text()))
      .toEqual(expectedOrder)
    expect(desktop.text()).toContain('payroll.posting_reconciliation.category_status.match')
    expect(desktop.text()).toContain('payroll.posting_reconciliation.category_status.diff')
    expect(desktop.text()).toContain('payroll.posting_reconciliation.category_status.not_applicable')
    expect(wrapper.text()).toContain('+money:125')
    expect(wrapper.text()).toContain('−money:50')
    expect(wrapper.text()).toContain('payroll.posting_reconciliation.summary.diff')
  })

  it('distinguishes unposted and not-materialized data from a reconciliation difference', async () => {
    m.reconciliation.mockResolvedValue(reconciliation({
      journal_state: 'unposted',
      payments_state: 'not_materialized',
      overall_status: 'info',
      categories: [category({
        journal_minor: null,
        payments_liability_minor: null,
        payments_paid_minor: null,
        diff_payroll_journal_minor: null,
        diff_payroll_payments_minor: null,
        status: 'not_applicable',
      })],
    }))

    const wrapper = mount(PayrollPostingReconciliationPage)
    await flushPromises()

    expect(wrapper.text()).toContain('payroll.posting_reconciliation.summary.unposted')
    expect(wrapper.text()).toContain('payroll.posting_reconciliation.hint_unposted')
    expect(wrapper.text()).toContain('payroll.posting_reconciliation.hint_payments_not_materialized')
    expect(wrapper.text()).not.toContain('payroll.posting_reconciliation.summary.diff')
  })

  it('presents tax-evidence journal reconciliation as not applicable', async () => {
    m.reconciliation.mockResolvedValue(reconciliation({
      accounting_mode: 'tax_evidence',
      journal_state: 'not_applicable',
      overall_status: 'info',
      categories: [category({
        journal_minor: null,
        diff_payroll_journal_minor: null,
        status: 'not_applicable',
      })],
    }))

    const wrapper = mount(PayrollPostingReconciliationPage)
    await flushPromises()

    expect(wrapper.text()).toContain('payroll.posting_reconciliation.summary.tax_evidence')
    expect(wrapper.text()).toContain('payroll.posting_reconciliation.hint_tax_evidence')
    expect(wrapper.text()).toContain('payroll.posting_reconciliation.category_status.not_applicable')
  })

  it('shows the dedicated empty state when no payroll run exists', async () => {
    m.reconciliation.mockResolvedValue(reconciliation({
      run: null,
      revision: null,
      journal_state: 'no_revision',
      overall_status: 'info',
      categories: [],
    }))

    const wrapper = mount(PayrollPostingReconciliationPage)
    await flushPromises()

    expect(wrapper.text()).toContain('payroll.posting_reconciliation.empty_no_run.title')
    expect(wrapper.text()).toContain('payroll.posting_reconciliation.empty_no_run.message')
    expect(wrapper.find('[data-test="reconciliation-desktop"]').exists()).toBe(false)
  })

  it('reloads, reports an API failure and recovers through retry', async () => {
    const wrapper = mount(PayrollPostingReconciliationPage)
    await flushPromises()

    const failedReload = deferred<PayrollPostingReconciliation>()
    m.reconciliation.mockReturnValueOnce(failedReload.promise)
    await wrapper.get('[data-test="reconciliation-reload"]').trigger('click')
    expect(wrapper.text()).toContain('common.loading')
    expect(wrapper.get('[data-test="reconciliation-reload"]').attributes('disabled')).toBeDefined()

    failedReload.reject(new Error('network unavailable'))
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text())
      .toContain('payroll.posting_reconciliation.load_failed')

    m.reconciliation.mockResolvedValueOnce(reconciliation({ overall_status: 'reconciled' }))
    await wrapper.get('[data-test="reconciliation-retry"]').trigger('click')
    await flushPromises()

    expect(m.reconciliation).toHaveBeenCalledTimes(3)
    expect(wrapper.find('[role="alert"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('payroll.posting_reconciliation.summary.reconciled')
  })

  it('loads a changed period and ignores a late response for the previous period', async () => {
    const august = deferred<PayrollPostingReconciliation>()
    const july = deferred<PayrollPostingReconciliation>()
    m.reconciliation.mockReturnValueOnce(august.promise).mockReturnValueOnce(july.promise)

    const wrapper = mount(PayrollPostingReconciliationPage)
    expect(wrapper.text()).toContain('common.loading')

    await wrapper.get('input[type="month"]').setValue('2026-07')
    expect(m.reconciliation).toHaveBeenNthCalledWith(1, '2026-08')
    expect(m.reconciliation).toHaveBeenNthCalledWith(2, '2026-07')

    july.resolve(reconciliation({
      period: '2026-07',
      categories: [category({ payroll_minor: 700 })],
    }))
    await flushPromises()
    expect(wrapper.text()).toContain('money:700')

    august.resolve(reconciliation({
      period: '2026-08',
      overall_status: 'diff',
      categories: [category({ payroll_minor: 800, status: 'diff' })],
    }))
    await flushPromises()
    expect(wrapper.text()).toContain('money:700')
    expect(wrapper.text()).not.toContain('money:800')
    expect(wrapper.text()).toContain('payroll.posting_reconciliation.summary.reconciled')
  })

  it('uses native keyboard controls with ARIA state for desktop and mobile expansion', async () => {
    const wrapper = mount(PayrollPostingReconciliationPage)
    await flushPromises()

    const desktopToggle = wrapper.get('[data-test="reconciliation-desktop-toggle-gross_wages"]')
    const mobileToggle = wrapper.get('[data-test="reconciliation-mobile-toggle-gross_wages"]')
    expect(desktopToggle.element.tagName).toBe('BUTTON')
    expect(mobileToggle.element.tagName).toBe('BUTTON')
    expect(desktopToggle.attributes('aria-expanded')).toBe('false')
    expect(mobileToggle.attributes('aria-expanded')).toBe('false')

    await desktopToggle.trigger('click')
    const desktopDetailId = desktopToggle.attributes('aria-controls')
    const mobileDetailId = mobileToggle.attributes('aria-controls')
    expect(desktopToggle.attributes('aria-expanded')).toBe('true')
    expect(mobileToggle.attributes('aria-expanded')).toBe('true')
    expect(wrapper.get(`#${desktopDetailId}`).text())
      .toContain('payroll.posting_reconciliation.detail_hint')
    expect(wrapper.get(`#${mobileDetailId}`).text())
      .toContain('payroll.posting_reconciliation.detail_hint')

    await mobileToggle.trigger('click')
    expect(desktopToggle.attributes('aria-expanded')).toBe('false')
    expect(mobileToggle.attributes('aria-expanded')).toBe('false')
    expect(wrapper.find(`#${desktopDetailId}`).exists()).toBe(false)
    expect(wrapper.find(`#${mobileDetailId}`).exists()).toBe(false)
  })
})
