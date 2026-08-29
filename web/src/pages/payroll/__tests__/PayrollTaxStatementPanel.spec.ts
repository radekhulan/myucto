import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  preview: vi.fn(),
  xmlUrl: vi.fn(
    (year: number, form: string, variant: string, discoveredOn?: string) =>
      `/payroll/reports/tax-statement?year=${year}&form=${form}&variant=${variant}`
      + (discoveredOn ? `&d_zjist=${discoveredOn}` : ''),
  ),
  download: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: { taxStatementPreview: m.preview, taxStatementXmlUrl: m.xmlUrl },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canRead: (permission: string) =>
      permission === 'payroll.reports' || permission === 'reports.export',
  }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))
vi.mock('@/utils/downloadFile', () => ({ downloadApiFile: m.download }))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs-CZ' } }),
}))

import PayrollTaxStatementPanel from '@/pages/payroll/PayrollTaxStatementPanel.vue'

function preview(overrides: Record<string, unknown> = {}) {
  return {
    year: 2025,
    statements: {
      dpzvd6: {
        form_code: 'dpzvd6',
        year: 2025,
        variant: 'B',
        months: [{
          month: 1,
          headcount: 4,
          advance_due: 48200,
          advance_withheld: 48200,
          prescribed: 0,
          annual_overpayment: 0,
          bonus_paid: 1260,
          adjustments: 1260,
          settled_amount: 46940,
          correction_difference: 0,
          remitted: 46940,
        }],
        total: {
          advance_due: 48200,
          advance_withheld: 48200,
          prescribed: 0,
          annual_overpayment: 0,
          bonus_paid: 1260,
          adjustments: 1260,
          settled_amount: 46940,
          correction_difference: 0,
          remitted: 46940,
        },
        annual_overpayment_total: 0,
        annual_bonus_top_up_total: 0,
        overpayment_payouts: [],
        workplaces: [{
          municipality_code: '554782',
          municipality_name: 'Hlavní město Praha',
          district_name: 'Hlavní město Praha',
          headcount: 4,
        }],
        non_resident_count: 0,
        warnings: ['Měsíce 2, 3 nemají schválený mzdový běh.'],
        ...(overrides.dpzvd6 as object ?? {}),
      },
      dpsvd2: {
        form_code: 'dpsvd2',
        year: 2025,
        variant: 'B',
        income_kind: '772',
        months: [],
        total: {
          tax_due_minor: 90000,
          tax_withheld_minor: 90000,
          due_with_return_minor: 0,
          declaration_linked_minor: 0,
          prescribed_minor: 0,
          settled_amount_minor: 90000,
          correction_difference_minor: 0,
          remitted_minor: 90000,
        },
        balance_minor: 0,
        warnings: [],
        ...(overrides.dpsvd2 as object ?? {}),
      },
    },
  }
}

describe('PayrollTaxStatementPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.preview.mockResolvedValue(preview())
  })

  it('defaults to the previous year, because the statement is filed after the year ends', async () => {
    const wrapper = mount(PayrollTaxStatementPanel, { props: { initialYear: 2026 } })
    await flushPromises()

    expect(m.preview).toHaveBeenCalledWith(2025, 'B')
    expect(wrapper.get('[data-test="payroll-tax-statement"]').text())
      .toContain('payroll.tax_statement.title')
    expect(wrapper.get('[data-test="tax-statement-workplaces"]').text())
      .toContain('Hlavní město Praha')
  })

  it('shows the warnings from both statements without duplicating them', async () => {
    m.preview.mockResolvedValue(preview({
      dpsvd2: { warnings: ['Měsíce 2, 3 nemají schválený mzdový běh.'] },
    }))
    const wrapper = mount(PayrollTaxStatementPanel, { props: { initialYear: 2026 } })
    await flushPromises()

    const warnings = wrapper.get('[data-test="tax-statement-warnings"]').findAll('li')
    expect(warnings).toHaveLength(1)
    expect(warnings[0]?.text()).toContain('nemají schválený mzdový běh')
  })

  it('reloads when the reported year changes', async () => {
    const wrapper = mount(PayrollTaxStatementPanel, { props: { initialYear: 2026 } })
    await flushPromises()

    await wrapper.get('[data-test="tax-statement-year"]').setValue('2024')
    await flushPromises()

    expect(m.preview).toHaveBeenLastCalledWith(2024, 'B')
  })

  it('asks for the discovery date only for a supplementary statement', async () => {
    const wrapper = mount(PayrollTaxStatementPanel, { props: { initialYear: 2026 } })
    await flushPromises()
    expect(wrapper.find('[data-test="tax-statement-discovered-on"]').exists()).toBe(false)

    await wrapper.get('[data-test="tax-statement-variant"]').setValue('D')
    await flushPromises()

    expect(wrapper.find('[data-test="tax-statement-discovered-on"]').exists()).toBe(true)
    expect(m.preview).toHaveBeenLastCalledWith(2025, 'D')
  })

  it('downloads each form separately, because they are two filings', async () => {
    const wrapper = mount(PayrollTaxStatementPanel, { props: { initialYear: 2026 } })
    await flushPromises()

    const buttons = wrapper.findAll('button')
    const dpz = buttons.find(button => button.text().includes('download_dpzvd6'))
    expect(dpz).toBeTruthy()
    await dpz?.trigger('click')
    await flushPromises()

    expect(m.xmlUrl).toHaveBeenCalledWith(2025, 'dpzvd6', 'B', undefined)
    expect(m.download).toHaveBeenCalledWith(
      '/payroll/reports/tax-statement?year=2025&form=dpzvd6&variant=B',
      'dpzvd6-2025.xml',
    )
    expect(m.success).toHaveBeenCalled()
  })

  it('reports a refused year instead of rendering an empty statement', async () => {
    m.preview.mockRejectedValue({
      response: { data: { error: { message: 'Za zvolený rok není žádný schválený mzdový běh.' } } },
    })
    const wrapper = mount(PayrollTaxStatementPanel, { props: { initialYear: 2026 } })
    await flushPromises()

    expect(wrapper.get('[data-test="tax-statement-error"]').text())
      .toContain('Za zvolený rok není žádný schválený mzdový běh.')
    expect(wrapper.find('[data-test="tax-statement-months"]').exists()).toBe(false)
  })
})
