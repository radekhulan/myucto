import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import {
  payrollApi,
  type PayrollEmploymentSurchargePolicies,
  type PayrollEmploymentSurchargePolicy,
} from '@/api/payroll'

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    employmentSurchargePolicies: vi.fn(),
    createEmploymentSurchargePolicy: vi.fn(),
  },
}))

const toastMocks = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))

vi.mock('@/composables/useToast', () => ({
  useToast: () => toastMocks,
}))

// Identita místo překladu: test kontroluje chování, ne texty. Původní modul se
// musí rozprostřít, jinak by se rozbily komponenty tahající skutečné createI18n.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

import EmploymentSurchargePolicyPanel from '@/pages/payroll/EmploymentSurchargePolicyPanel.vue'

function kinds(): PayrollEmploymentSurchargePolicies['kinds'] {
  return [
    {
      kind: 'overtime',
      section: '§ 114',
      label: 'Přesčas',
      component_code: 'SUR_OT',
      basis: 'average_earning',
      statutory_rate_basis_points: 2500,
      allows_lower_agreed_rate: false,
      allows_compensatory_time_off: true,
      allows_quick_manual_entry: false,
    },
    {
      kind: 'holiday',
      section: '§ 115',
      label: 'Svátek',
      component_code: 'SUR_HOL',
      basis: 'average_earning',
      statutory_rate_basis_points: 10000,
      allows_lower_agreed_rate: false,
      allows_compensatory_time_off: true,
      allows_quick_manual_entry: true,
    },
    {
      kind: 'night',
      section: '§ 116',
      label: 'Noční práce',
      component_code: 'SUR_NIGHT',
      basis: 'average_earning',
      statutory_rate_basis_points: 1000,
      allows_lower_agreed_rate: true,
      allows_compensatory_time_off: false,
      allows_quick_manual_entry: true,
    },
    {
      kind: 'weekend',
      section: '§ 118',
      label: 'Sobota a neděle',
      component_code: 'SUR_WKND',
      basis: 'average_earning',
      statutory_rate_basis_points: 1000,
      allows_lower_agreed_rate: true,
      allows_compensatory_time_off: false,
      allows_quick_manual_entry: true,
    },
    {
      kind: 'difficult_environment',
      section: '§ 117',
      label: 'Ztížené prostředí',
      component_code: 'SUR_DIFF',
      basis: 'minimum_wage_hourly',
      statutory_rate_basis_points: 1000,
      allows_lower_agreed_rate: false,
      allows_compensatory_time_off: false,
      allows_quick_manual_entry: true,
    },
  ]
}

function response(
  policies: PayrollEmploymentSurchargePolicy[] = [],
): PayrollEmploymentSurchargePolicies {
  return {
    policies,
    statutory_default: {
      overtime_mode: 'surcharge',
      holiday_mode: 'compensatory_time_off',
      difficult_environment_factors: null,
    },
    kinds: kinds(),
    ruleset_id: 'cz-payroll-2026-synthetic',
  }
}

function policy(): PayrollEmploymentSurchargePolicy {
  return {
    id: 5,
    employment_id: 10,
    valid_from: '2026-01-01',
    valid_to: null,
    overtime_mode: 'surcharge',
    holiday_mode: 'surcharge',
    difficult_environment_factors: 2,
    overtime_rate_bp: 3000,
    holiday_rate_bp: 10000,
    night_rate_bp: null,
    weekend_rate_bp: null,
    difficult_environment_rate_bp: null,
    agreement_reference: 'KS čl. 12',
    note: null,
    row_version: 1,
  }
}

async function mountPanel(canWrite = true) {
  const wrapper = mount(EmploymentSurchargePolicyPanel, {
    props: { employmentId: 10, canWrite },
  })
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.clearAllMocks()
  vi.mocked(payrollApi.employmentSurchargePolicies).mockResolvedValue(response())
  vi.mocked(payrollApi.createEmploymentSurchargePolicy).mockResolvedValue(policy())
})

describe('EmploymentSurchargePolicyPanel', () => {
  it('ukáže zákonný výchozí stav, když zásada neexistuje — u svátku náhradní volno', async () => {
    const wrapper = await mountPanel()

    expect(wrapper.find('[data-test="surcharge-policy-empty"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="surcharge-policy-current"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="surcharge-policy-statutory-holiday"]').text())
      .toBe('payroll.people.surcharge_policy.modes.compensatory_time_off')
    expect(wrapper.find('[data-test="surcharge-policy-statutory-overtime"]').text())
      .toBe('payroll.people.surcharge_policy.modes.surcharge')
  })

  it('u svátku nenabízí režim „zahrnuto ve mzdě“', async () => {
    const wrapper = await mountPanel()
    await wrapper.find('[data-test="surcharge-policy-add"]').trigger('click')

    const holidayOptions = wrapper.find('[data-test="surcharge-policy-holiday-mode"]')
      .findAll('option')
      .map(option => option.attributes('value'))
    expect(holidayOptions).toEqual(['compensatory_time_off', 'surcharge'])

    const overtimeOptions = wrapper.find('[data-test="surcharge-policy-overtime-mode"]')
      .findAll('option')
      .map(option => option.attributes('value'))
    expect(overtimeOptions).toContain('included_in_wage')
  })

  it('posílá sazby převedené z procent na bázové body', async () => {
    const wrapper = await mountPanel()
    await wrapper.find('[data-test="surcharge-policy-add"]').trigger('click')

    await wrapper.find('[data-test="surcharge-policy-valid-from"]').setValue('2026-03-01')
    await wrapper.find('[data-test="surcharge-policy-rate-overtime"]').setValue('30')
    await wrapper.find('[data-test="surcharge-policy-rate-night"]').setValue('12.5')
    await wrapper.find('[data-test="surcharge-policy-factors"]').setValue('3')
    await wrapper.find('[data-test="surcharge-policy-holiday-mode"]').setValue('surcharge')
    await wrapper.find('[data-test="surcharge-policy-form"]').trigger('submit')
    await flushPromises()

    expect(payrollApi.createEmploymentSurchargePolicy).toHaveBeenCalledWith(10, expect.objectContaining({
      valid_from: '2026-03-01',
      overtime_mode: 'surcharge',
      holiday_mode: 'surcharge',
      difficult_environment_factors: 3,
      overtime_rate_bp: 3000,
      night_rate_bp: 1250,
      holiday_rate_bp: null,
      weekend_rate_bp: null,
      difficult_environment_rate_bp: null,
    }))
    expect(toastMocks.success).toHaveBeenCalled()
  })

  it('upozorní na podlezení kogentní podlahy, ale zápis nechá rozhodnout server', async () => {
    vi.mocked(payrollApi.createEmploymentSurchargePolicy).mockRejectedValue({
      isAxiosError: true,
      response: {
        status: 422,
        data: {
          error: {
            code: 'validation_failed',
            message: 'Sjednaná sazba příplatku § 115 nesmí být nižší než zákonné minimum 1.',
          },
        },
      },
    })

    const wrapper = await mountPanel()
    await wrapper.find('[data-test="surcharge-policy-add"]').trigger('click')
    await wrapper.find('[data-test="surcharge-policy-rate-holiday"]').setValue('50')

    // Klient varuje hned, ale tlačítko Uložit neblokuje — autorita je server.
    const warning = wrapper.find('[data-test="surcharge-policy-below-statutory"]')
    expect(warning.exists()).toBe(true)
    expect(warning.text()).toContain('payroll.people.surcharge_policy.kinds.holiday')
    expect(
      wrapper.find('[data-test="surcharge-policy-save"]').attributes('disabled'),
    ).toBeUndefined()

    await wrapper.find('[data-test="surcharge-policy-form"]').trigger('submit')
    await flushPromises()

    expect(payrollApi.createEmploymentSurchargePolicy).toHaveBeenCalled()
    expect(wrapper.find('[data-test="surcharge-policy-error"]').text())
      .toContain('nesmí být nižší než zákonné minimum')
  })

  it('vypíše platnou zásadu i zákonný výchozí stav vedle sebe', async () => {
    vi.mocked(payrollApi.employmentSurchargePolicies).mockResolvedValue(response([policy()]))

    const wrapper = await mountPanel()

    expect(wrapper.find('[data-test="surcharge-policy-current"]').text()).toContain('2026-01-01')
    expect(wrapper.find('[data-test="surcharge-policy-statutory"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="surcharge-policy-empty"]').exists()).toBe(false)
  })

  it('bez práva zápisu je přidání zašedlé a důvod je vidět', async () => {
    const wrapper = await mountPanel(false)

    expect(wrapper.find('[data-test="surcharge-policy-add"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('payroll.people.surcharge_policy.no_permission')
  })
})
