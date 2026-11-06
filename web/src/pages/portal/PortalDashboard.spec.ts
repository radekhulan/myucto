import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { computed, ref } from 'vue'
import type { PortalSummary } from '@/api/portal'
import { windowContextKey } from '@/workspace/windowContext'

const m = vi.hoisted(() => ({ summary: vi.fn(), supplierId: 1 }))

vi.mock('@/api/portal', () => ({ portalApi: { summary: m.summary } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: () => false, canWrite: () => false }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1 }) }))
vi.mock('@/workspace/supplierContext', () => ({ getWindowSupplierScope: () => ({ supplierId: m.supplierId, windowId: 'portal-test' }) }))
vi.mock('@/composables/useFormat', () => ({
  formatMoney: (value: number) => String(value),
  formatDate: (value: string) => value,
  formatMonth: (value: string) => value,
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, args?: Record<string, unknown>) =>
  args ? `${key}:${Object.values(args).join(',')}` : key }) }))

import PortalDashboard from './PortalDashboard.vue'

type Vat = Pick<PortalSummary['vat'], 'is_vat_payer' | 'status'>

function summary(vat: Vat): PortalSummary {
  return {
    company: { name: 'Syntetická firma', period: { ytd_from: '2026-01-01', today: '2026-10-27', current_month: '2026-10' } },
    kpi: { current_month: [], last_month: [], ytd: [], prev_year_ytd: [], last_12m: [], currencies: [] },
    monthly: [{ period: '2026-10', currency: 'CZK', invoiced: 1, costs: 0, profit: 1, invoiced_czk: 1, costs_czk: 0, profit_czk: 1, invoice_count: 1, purchase_count: 0 }],
    cashflow: { receivables: [], payables: [], forecast: { currency: 'CZK', weeks: [], total_in: 0, total_out: 0, total_net: 0 } },
    vat: { ...vat, deadlines: [] },
    document_requests_open: { open: 0, overdue: 0 },
  }
}

function vatPeriodText(wrapper: VueWrapper) {
  return wrapper.findAll('dt').find(dt => dt.text() === 'portal.vat_period')
    ?.element.nextElementSibling?.textContent?.trim()
}

describe('PortalDashboard VAT period', () => {
  beforeEach(() => {
    m.summary.mockReset()
  })

  async function render(vat: Vat, active = ref(true)) {
    m.summary.mockResolvedValue(summary(vat))
    const wrapper = mount(PortalDashboard, { global: {
      stubs: { PortalMonthlyChart: true, EmptyState: true, RouterLink: true },
      provide: { [windowContextKey as symbol]: {
        active: computed(() => active.value), paneId: computed(() => 'primary' as const), root: document.body, portal: document.body,
      } },
    } })
    await flushPromises()
    return { wrapper, active }
  }

  it.each([
    ['2026-03', 1, 2026],
    ['2026-06', 2, 2026],
    ['2026-09', 3, 2026],
    ['2026-10', 4, 2026],
    ['2026-12', 4, 2026],
    ['2027-03', 1, 2027],
  ] as const)('shows the quarter and year for period %s', async (period, quarter, year) => {
    const { wrapper } = await render({ is_vat_payer: true, status: {
      period, period_type: 'quarterly', quarter,
      vat_output: 0, vat_input: 0, tax_due: 0, is_excess_deduction: false, submission_deadline: '2026-07-27',
    } })
    expect(vatPeriodText(wrapper)).toBe(`crm.tax_calendar.period_quarter:${quarter},${year}`)
    wrapper.unmount()
  })

  it('keeps monthly periods in year-month form', async () => {
    const { wrapper } = await render({ is_vat_payer: true, status: {
      period: '2026-10', period_type: 'monthly', quarter: null,
      vat_output: 0, vat_input: 0, tax_due: 0, is_excess_deduction: false, submission_deadline: '2026-11-25',
    } })
    expect(vatPeriodText(wrapper)).toBe('2026-10')
    wrapper.unmount()
  })

  it('shows the non-payer state without a VAT period', async () => {
    const { wrapper } = await render({ is_vat_payer: false, status: null })
    expect(wrapper.text()).toContain('portal.vat_not_payer')
    expect(vatPeriodText(wrapper)).toBeUndefined()
    wrapper.unmount()
  })

  it('refreshes VAT settings changes from another window after this window is reactivated', async () => {
    const monthly = summary({ is_vat_payer: true, status: {
      period: '2026-10', period_type: 'monthly', quarter: null,
      vat_output: 0, vat_input: 0, tax_due: 0, is_excess_deduction: false, submission_deadline: '2026-11-25',
    } })
    const quarterly = summary({ is_vat_payer: true, status: {
      period: '2026-09', period_type: 'quarterly', quarter: 3,
      vat_output: 0, vat_input: 0, tax_due: 0, is_excess_deduction: false, submission_deadline: '2026-10-26',
    } })
    m.summary.mockResolvedValueOnce(monthly).mockResolvedValueOnce(quarterly)
    const active = ref(true)
    const { wrapper } = await render({ is_vat_payer: true, status: monthly.vat.status }, active)
    expect(m.summary).toHaveBeenCalledTimes(1)

    active.value = false
    window.dispatchEvent(new CustomEvent('myucto:window-data-changed', { detail: { supplierId: 1, resource: 'settings' } }))
    window.dispatchEvent(new CustomEvent('myucto:window-data-changed', { detail: { supplierId: 2, resource: 'suppliers' } }))
    window.dispatchEvent(new CustomEvent('myucto:window-data-changed', { detail: { supplierId: 1, resource: 'clients' } }))
    await flushPromises()
    expect(m.summary).toHaveBeenCalledTimes(1)

    active.value = true
    await flushPromises()
    expect(m.summary).toHaveBeenCalledTimes(2)
    expect(vatPeriodText(wrapper)).toBe('crm.tax_calendar.period_quarter:3,2026')
    wrapper.unmount()
  })

  it('retains old data and retries a failed quiet refresh on the next activation', async () => {
    const current = summary({ is_vat_payer: true, status: {
      period: '2026-10', period_type: 'monthly', quarter: null,
      vat_output: 0, vat_input: 0, tax_due: 0, is_excess_deduction: false, submission_deadline: '2026-11-25',
    } })
    const updated = summary({ is_vat_payer: true, status: {
      period: '2026-09', period_type: 'quarterly', quarter: 3,
      vat_output: 0, vat_input: 0, tax_due: 0, is_excess_deduction: false, submission_deadline: '2026-10-26',
    } })
    m.summary.mockResolvedValueOnce(current).mockRejectedValueOnce(new Error('Synthetic refresh failure')).mockResolvedValueOnce(updated)
    const active = ref(true)
    const { wrapper } = await render({ is_vat_payer: true, status: current.vat.status }, active)

    active.value = false
    window.dispatchEvent(new CustomEvent('myucto:window-data-changed', { detail: { supplierId: 1, resource: 'settings' } }))
    await flushPromises()
    active.value = true
    await flushPromises()
    expect(m.summary).toHaveBeenCalledTimes(2)
    expect(vatPeriodText(wrapper)).toBe('2026-10')

    active.value = false
    await flushPromises()
    active.value = true
    await flushPromises()
    expect(m.summary).toHaveBeenCalledTimes(3)
    expect(vatPeriodText(wrapper)).toBe('crm.tax_calendar.period_quarter:3,2026')
    wrapper.unmount()
  })
})
