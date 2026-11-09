import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  detail: vi.fn(),
  exportDetail: vi.fn(),
}))

vi.mock('@/api/payrollPosting', () => ({
  payrollPostingApi: { detail: m.detail, exportDetail: m.exportDetail },
}))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, te: () => true, locale: { value: 'cs-CZ' } }),
}))
vi.mock('./../payrollComponentsUi', () => ({ payrollWorkingPeriod: () => '2026-09' }))

import PayrollPostingDetail from '@/pages/payroll/PayrollPostingDetail.vue'

const report = {
  period: '2026-09',
  runs: [{ run_id: 1, revision_no: 1, journal_entry_id: 10 }],
  summary: [
    { label: 'Měsíční mzda', debit_account: '521', credit_account: '331', hours: null, amount_minor: 3500000 },
  ],
  rows: [
    {
      employee_id: 7, personal_number: 'SYN-1', name: 'Syntetický Zaměstnanec', label: 'Měsíční mzda',
      component_code: 'MZDA', debit_account: '521', credit_account: '331', cost_center: null, hours: null, amount_minor: 3500000,
    },
    {
      employee_id: null, personal_number: null, name: null, label: 'Sociální pojištění hrazené zaměstnavatelem',
      component_code: null, debit_account: null, credit_account: '336.100', cost_center: null, hours: null, amount_minor: 1,
    },
  ],
  totals: { amount_minor: 3500001, hours: null },
}

describe('PayrollPostingDetail', () => {
  beforeEach(() => {
    m.detail.mockReset()
    m.exportDetail.mockReset()
  })

  it('shows the company summary above the employee breakdown', async () => {
    m.detail.mockResolvedValue(report)
    const wrapper = mount(PayrollPostingDetail, { global: { stubs: { EmptyState: true } } })
    await flushPromises()

    expect(m.detail).toHaveBeenCalledWith('2026-09')
    const sections = wrapper.findAll('section')
    expect(sections[0].attributes('data-test')).toBe('posting-detail-summary')
    expect(sections[1].attributes('data-test')).toBe('posting-detail-rows')
    expect(sections[1].text()).toContain('SYN-1 - Syntetický Zaměstnanec')
    expect(sections[1].text()).toContain('payroll.posting_detail.company_wide')
  })

  it('downloads the export in the chosen format', async () => {
    m.detail.mockResolvedValue(report)
    m.exportDetail.mockResolvedValue(undefined)
    const wrapper = mount(PayrollPostingDetail, { global: { stubs: { EmptyState: true } } })
    await flushPromises()

    await wrapper.find('[data-test="posting-detail-export-xlsx"]').trigger('click')
    await wrapper.find('[data-test="posting-detail-export-csv"]').trigger('click')
    await flushPromises()

    expect(m.exportDetail).toHaveBeenNthCalledWith(1, 'xlsx', '2026-09')
    expect(m.exportDetail).toHaveBeenNthCalledWith(2, 'csv', '2026-09')
  })

  it('offers the payroll runs when nothing is posted', async () => {
    m.detail.mockResolvedValue({ ...report, runs: [], summary: [], rows: [], totals: { amount_minor: 0, hours: null } })
    const wrapper = mount(PayrollPostingDetail, { global: { stubs: { EmptyState: true } } })
    await flushPromises()

    expect(wrapper.find('empty-state-stub').exists()).toBe(true)
    expect(wrapper.find('[data-test="posting-detail-export-xlsx"]').attributes('disabled')).toBeDefined()
  })
})
