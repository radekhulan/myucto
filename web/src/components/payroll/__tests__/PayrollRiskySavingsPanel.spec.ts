import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  riskySavings: vi.fn(),
  institutionAccounts: vi.fn(),
  saveEvidence: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    riskySavings: m.riskySavings,
    institutionAccounts: m.institutionAccounts,
    saveRiskySavingsEvidence: m.saveEvidence,
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))
vi.mock('@/api/errors', () => ({ apiErrorMessage: (_: unknown, fallback: string) => fallback }))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      `${key}${params ? JSON.stringify(params) : ''}`,
  }),
}))

import PayrollRiskySavingsPanel from '@/components/payroll/PayrollRiskySavingsPanel.vue'

const employments = [{
  employment_id: 84,
  employee_id: 42,
  full_name: 'Syntetická osoba',
  code: 'SYN-PP-1',
  relation_type: 'employment',
}]

describe('PayrollRiskySavingsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.riskySavings.mockResolvedValue({
      items: [],
      minimum_shift_eighths: 24,
      rate_basis_points: 400,
    })
    m.saveEvidence.mockResolvedValue({ id: 1 })
    m.institutionAccounts.mockResolvedValue([{
      id: 55,
      institution_type: 'other_recipient',
      institution_name: 'Testovací penzijní',
      bank_account_masked: '******0005 / 0100',
      currency_code: 'CZK',
      variable_symbol: '123456',
      specific_symbol: null,
    }])
  })

  it('schválí přesně osminy směn a doložené platební údaje', async () => {
    const wrapper = mount(PayrollRiskySavingsPanel, {
      props: { period: '2026-08', employments },
      global: {
        stubs: {
          PayrollPersonSearchSelect: {
            template: '<button data-test="person" @click="$emit(\'update:modelValue\', 42)">person</button>',
          },
          SearchableSelect: {
            props: ['options'],
            template: '<button data-test="search-select" @click="$emit(\'update:modelValue\', options[0]?.value)">select</button>',
          },
        },
      },
    })
    await flushPromises()

    expect(m.riskySavings).toHaveBeenCalledWith('2026-08')
    await wrapper.get('[data-test="person"]').trigger('click')
    const selects = wrapper.findAll('[data-test="search-select"]')
    await selects[0].trigger('click')
    await selects[1].trigger('click')
    await selects[2].trigger('click')
    await wrapper.get('[data-testid="risky-full-shifts"]').setValue('1')
    await wrapper.get('[data-testid="risky-other-hours"]').setValue('16')
    await wrapper.get('[data-testid="risky-claimed-on"]').setValue('2026-07-31')
    await wrapper.get('[data-testid="risky-informed-on"]').setValue('2026-07-01')
    await wrapper.get('[data-testid="risky-company"]').setValue('Testovací penzijní')
    await wrapper.get('[data-testid="risky-product"]').setValue('SYNTHETIC-PRODUCT')
    await wrapper.get('[data-testid="risky-variable-symbol"]').setValue('123456')
    await wrapper.get('[data-testid="risky-approve"]').trigger('click')
    await flushPromises()

    expect(m.saveEvidence).toHaveBeenCalledWith(expect.objectContaining({
      employment_id: 84,
      period: '2026-08',
      qualifying_shift_eighths: 24,
      risk_factor: 'vibration',
      right_claimed_on: '2026-07-31',
      employee_informed_on: '2026-07-01',
      institution_account_id: 55,
      approve: true,
    }))
  })

  it('po změně období načte pouze nové měsíční podklady', async () => {
    const wrapper = mount(PayrollRiskySavingsPanel, {
      props: { period: '2026-08', employments },
      global: {
        stubs: {
          PayrollPersonSearchSelect: true,
          SearchableSelect: true,
        },
      },
    })
    await flushPromises()
    await wrapper.setProps({ period: '2026-09' })
    await flushPromises()

    expect(m.riskySavings).toHaveBeenNthCalledWith(2, '2026-09')
  })
})
