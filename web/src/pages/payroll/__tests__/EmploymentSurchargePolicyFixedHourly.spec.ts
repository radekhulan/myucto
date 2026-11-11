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
    updateEmploymentSurchargePolicy: vi.fn(),
    closeEmploymentSurchargePolicy: vi.fn(),
  },
}))

const toastMocks = vi.hoisted(() => ({ success: vi.fn(), error: vi.fn() }))

vi.mock('@/composables/useToast', () => ({
  useToast: () => toastMocks,
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: { value: 'cs' },
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

/** Verze sjednaná PROCENTEM — na ní se zkouší přepnutí na pevnou částku. */
function percentPolicy(): PayrollEmploymentSurchargePolicy {
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
    weekend_rate_bp: 2000,
    difficult_environment_rate_bp: null,
    overtime_fixed_hourly_minor: null,
    holiday_fixed_hourly_minor: null,
    night_fixed_hourly_minor: null,
    weekend_fixed_hourly_minor: null,
    difficult_environment_fixed_hourly_minor: null,
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
  vi.mocked(payrollApi.createEmploymentSurchargePolicy).mockResolvedValue(percentPolicy())
  vi.mocked(payrollApi.updateEmploymentSurchargePolicy)
    .mockResolvedValue({ ...percentPolicy(), row_version: 2 })
})

describe('EmploymentSurchargePolicyPanel — pevná částka za hodinu', () => {
  it('pošle pevnou částku v haléřích a sazbu k témuž druhu vynuluje', async () => {
    const wrapper = await mountPanel()
    await wrapper.find('[data-test="surcharge-policy-add"]').trigger('click')

    await wrapper.find('[data-test="surcharge-policy-valid-from"]').setValue('2026-07-01')
    await wrapper.find('[data-test="surcharge-policy-form-weekend"]').setValue('fixed')
    await wrapper.find('[data-test="surcharge-policy-fixed-weekend"]').setValue('42')
    // Přesčas zůstává procentem — způsob se volí u každého druhu zvlášť.
    await wrapper.find('[data-test="surcharge-policy-rate-overtime"]').setValue('30')
    await wrapper.find('[data-test="surcharge-policy-form"]').trigger('submit')
    await flushPromises()

    expect(payrollApi.createEmploymentSurchargePolicy).toHaveBeenCalledWith(10, expect.objectContaining({
      valid_from: '2026-07-01',
      weekend_fixed_hourly_minor: 4200,
      weekend_rate_bp: null,
      overtime_rate_bp: 3000,
      overtime_fixed_hourly_minor: null,
    }))
  })

  it('u pevné částky nabídne pole v Kč a schová pole procent', async () => {
    const wrapper = await mountPanel()
    await wrapper.find('[data-test="surcharge-policy-add"]').trigger('click')

    expect(wrapper.find('[data-test="surcharge-policy-rate-night"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="surcharge-policy-fixed-night"]').exists()).toBe(false)

    await wrapper.find('[data-test="surcharge-policy-form-night"]').setValue('fixed')

    expect(wrapper.find('[data-test="surcharge-policy-rate-night"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="surcharge-policy-fixed-night"]').exists()).toBe(true)
  })

  /*
   * Jádro verzování: přepnutí na pevnou částku je NOVÁ verze, ne přepis té
   * staré. Panel musí vyjít z platné verze (procenta) a poslat sjednání, kde je
   * u přepnutého druhu částka a sazba je null — jinak by v jednom řádku zůstalo
   * obojí a server by ho odmítl.
   */
  it('přepnutí z procenta na částku posílá jako novou verzi od zvoleného dne', async () => {
    vi.mocked(payrollApi.employmentSurchargePolicies)
      .mockResolvedValue(response([percentPolicy()]))
    const wrapper = await mountPanel()

    await wrapper.find('[data-test="surcharge-policy-add"]').trigger('click')
    // Nová verze vychází z platné, takže víkend je předvyplněný procentem.
    const weekendForm = wrapper.get('[data-test="surcharge-policy-form-weekend"]')
    expect((weekendForm.element as HTMLSelectElement).value).toBe('percent')

    await wrapper.find('[data-test="surcharge-policy-valid-from"]').setValue('2026-07-01')
    await weekendForm.setValue('fixed')
    await wrapper.find('[data-test="surcharge-policy-fixed-weekend"]').setValue('42')
    await wrapper.find('[data-test="surcharge-policy-form"]').trigger('submit')
    await flushPromises()

    expect(payrollApi.createEmploymentSurchargePolicy).toHaveBeenCalledWith(10, expect.objectContaining({
      valid_from: '2026-07-01',
      weekend_fixed_hourly_minor: 4200,
      weekend_rate_bp: null,
    }))
    // Stará verze se neopravuje — historie zůstává, jak byla.
    expect(payrollApi.updateEmploymentSurchargePolicy).not.toHaveBeenCalled()
  })

  it('uloženou pevnou částku načte zpět v korunách a předvybere způsob', async () => {
    vi.mocked(payrollApi.employmentSurchargePolicies).mockResolvedValue(response([{
      ...percentPolicy(),
      weekend_rate_bp: null,
      weekend_fixed_hourly_minor: 4200,
    }]))
    const wrapper = await mountPanel()

    expect(wrapper.find('[data-test="surcharge-policy-current-fixed-weekend"]').text())
      .toContain('42')

    await wrapper.find('[data-test="surcharge-policy-edit"]').trigger('click')
    expect(
      (wrapper.get('[data-test="surcharge-policy-form-weekend"]').element as HTMLSelectElement).value,
    ).toBe('fixed')
    expect(
      (wrapper.get('[data-test="surcharge-policy-fixed-weekend"]').element as HTMLInputElement).value,
    ).toBe('42')
  })

  /*
   * U pevné částky se zákonné minimum odvíjí od průměrného výdělku konkrétního
   * člověka, který prohlížeč nezná. Varovat by tedy znamenalo hádat — a hádat
   * u kogentní podlahy je horší než mlčet, protože planý poplach naučí uživatele
   * varování přehlížet.
   */
  it('u pevné částky nevaruje na podlezení, i když je částka nízká', async () => {
    const wrapper = await mountPanel()
    await wrapper.find('[data-test="surcharge-policy-add"]').trigger('click')

    await wrapper.find('[data-test="surcharge-policy-rate-holiday"]').setValue('50')
    expect(wrapper.find('[data-test="surcharge-policy-below-statutory"]').exists()).toBe(true)

    // Týž druh, ale sjednaný částkou: klient o zákonném minimu nerozhoduje.
    await wrapper.find('[data-test="surcharge-policy-form-holiday"]').setValue('fixed')
    await wrapper.find('[data-test="surcharge-policy-fixed-holiday"]').setValue('1')
    expect(wrapper.find('[data-test="surcharge-policy-below-statutory"]').exists()).toBe(false)
  })

  it('částku nad podporovaný rozsah zablokuje ještě před odesláním', async () => {
    const wrapper = await mountPanel()
    await wrapper.find('[data-test="surcharge-policy-add"]').trigger('click')

    await wrapper.find('[data-test="surcharge-policy-form-night"]').setValue('fixed')
    await wrapper.find('[data-test="surcharge-policy-fixed-night"]').setValue('1001')

    const save = wrapper.get('[data-test="surcharge-policy-save"]')
    expect(save.attributes('disabled')).toBeDefined()
    expect(save.attributes('title'))
      .toContain('payroll.people.surcharge_policy.fixed_invalid')

    await wrapper.find('[data-test="surcharge-policy-form"]').trigger('submit')
    await flushPromises()
    expect(payrollApi.createEmploymentSurchargePolicy).not.toHaveBeenCalled()
  })
})
