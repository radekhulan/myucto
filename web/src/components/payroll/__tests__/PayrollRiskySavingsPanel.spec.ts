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

  function mountPanel() {
    return mount(PayrollRiskySavingsPanel, {
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
  }

  /**
   * Vypnuté tlačítko nad patnácti poli ve čtyřech sloupcích je hádanka:
   * uživatel nemá jak zjistit, který ze čtyř povinných údajů schází.
   */
  it('vypnuté uložení vyjmenuje, co ještě chybí', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    const hint = () => wrapper.get('[data-testid="risky-missing-fields"]').text()
    expect(hint()).toContain('payroll.risky_savings.employment')
    expect(hint()).toContain('payroll.risky_savings.payment_target')
    expect(hint()).toContain('payroll.risky_savings.claimed_on')
    expect(hint()).toContain('payroll.risky_savings.product_reference')
    expect(wrapper.get('[data-testid="risky-approve"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="person"]').trigger('click')
    expect(hint()).not.toContain('payroll.risky_savings.employment')

    const selects = wrapper.findAll('[data-test="search-select"]')
    await selects[2].trigger('click')
    await wrapper.get('[data-testid="risky-claimed-on"]').setValue('2026-07-31')
    await wrapper.get('[data-testid="risky-product"]').setValue('SYNTHETIC-PRODUCT')

    expect(wrapper.find('[data-testid="risky-missing-fields"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="risky-approve"]').attributes('disabled')).toBeUndefined()
  })

  /** Prázdný měsíc a nenačtený měsíc vypadaly stejně — tedy nijak. */
  it('prázdná evidence měsíce se řekne, nezůstane po ní prázdno', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.get('[data-testid="risky-empty"]').text())
      .toContain('payroll.risky_savings.empty')
  })

  /** Z chybového stavu vedl dřív jen refresh celé stránky. */
  it('z nenačtené evidence vede tlačítko zpět k pokusu', async () => {
    m.riskySavings.mockRejectedValueOnce(new Error('500'))
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-testid="risky-empty"]').exists()).toBe(false)
    await wrapper.get('[data-testid="risky-retry"]').trigger('click')
    await flushPromises()

    expect(m.riskySavings).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="risky-empty"]').exists()).toBe(true)
  })

  /**
   * Po „Upravit" se panel zamkl do konkrétního záznamu a nic z toho nevedlo
   * ven: další uložení by přepsalo cizí řádek.
   */
  it('z rozepsané úpravy se dá vrátit k novému záznamu', async () => {
    m.riskySavings.mockResolvedValue({
      items: [{
        id: 9,
        employment_id: 84,
        full_name: 'Syntetická osoba',
        employment_code: 'SYN-PP-1',
        risk_factor: 'vibration',
        qualifying_shift_eighths: 24,
        right_claimed_on: '2026-07-31',
        employee_informed_on: null,
        pension_company: 'Testovací penzijní',
        institution_account_id: 55,
        institution_account_masked: '******0005 / 0100',
        payment_target_name: 'Testovací penzijní',
        product_reference: 'SYNTHETIC-PRODUCT',
        variable_symbol: '123456',
        specific_symbol: null,
        payment_message: null,
        evidence_reference: null,
        contribution_minor: 1000,
        contribution_status: 'draft',
        status: 'draft',
        payment_due_on: null,
        row_version: 3,
      }],
      minimum_shift_eighths: 24,
      rate_basis_points: 400,
    })
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-testid="risky-new"]').exists()).toBe(false)

    await wrapper.get('table tbody button').trigger('click')
    expect(wrapper.find('[data-testid="risky-new"]').exists()).toBe(true)

    await wrapper.get('[data-testid="risky-new"]').trigger('click')
    expect(wrapper.find('[data-testid="risky-new"]').exists()).toBe(false)
    expect((wrapper.get('[data-testid="risky-product"]').element as HTMLInputElement).value).toBe('')
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
  /*
   * Účty institucí se hledají k poslednímu dni VYKAZOVANÉHO měsíce. `Date.UTC`
   * bere měsíc od nuly, takže s `month + 1` vycházel poslední den měsíce
   * následujícího — za srpen 30. 9. Nabídka pak mohla obsahovat účet, který
   * v období ještě neplatil, a ten jde do evidence jako cíl platby.
   */
  it('hledá platné účty k poslednímu dni vykazovaného měsíce', async () => {
    const wrapper = mount(PayrollRiskySavingsPanel, {
      props: { period: '2026-08', employments },
    })
    await flushPromises()

    expect(m.institutionAccounts).toHaveBeenCalledWith('2026-08-31')

    wrapper.unmount()
  })
})
