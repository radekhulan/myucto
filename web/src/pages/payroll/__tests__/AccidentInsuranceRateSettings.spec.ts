import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  accidentInsuranceRates: vi.fn(),
  createAccidentInsuranceRate: vi.fn(),
  accidentInsuranceRateSchedule: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    accidentInsuranceRates: m.accidentInsuranceRates,
    createAccidentInsuranceRate: m.createAccidentInsuranceRate,
    accidentInsuranceRateSchedule: m.accidentInsuranceRateSchedule,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

import AccidentInsuranceRateSettings from '@/pages/payroll/AccidentInsuranceRateSettings.vue'

async function render() {
  const wrapper = mount(AccidentInsuranceRateSettings, { props: { canWrite: true } })
  await flushPromises()
  return wrapper
}

function rateInput(wrapper: Awaited<ReturnType<typeof render>>) {
  return wrapper.find('input[inputmode="decimal"]')
}

describe('AccidentInsuranceRateSettings', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.accidentInsuranceRates.mockResolvedValue([])
    m.accidentInsuranceRateSchedule.mockRejectedValue(new Error('v tomhle testu nepotřeba'))
  })

  it('sazbu z přílohy nepovažuje za podezřelou', async () => {
    const wrapper = await render()

    await rateInput(wrapper).setValue('4,2')

    expect(wrapper.find('[data-testid="accident-rate-outside-annex"]').exists()).toBe(false)
  })

  it('upozorní na sazbu mimo přílohu č. 2, ale nezablokuje uložení', async () => {
    m.createAccidentInsuranceRate.mockResolvedValue({})
    const wrapper = await render()

    await wrapper.find('input[maxlength="32"]').setValue('KOOP')
    await rateInput(wrapper).setValue('3,5')

    expect(wrapper.find('[data-testid="accident-rate-outside-annex"]').exists()).toBe(true)

    const submit = wrapper.findAll('button')
      .find(button => button.text().includes('accident_insurance.add_rate'))
    expect(submit).toBeDefined()
    await submit!.trigger('click')
    await flushPromises()

    expect(m.createAccidentInsuranceRate).toHaveBeenCalledWith({
      institution_code: 'KOOP',
      rate_per_mille: '3.5',
      effective_from: expect.any(String),
    })
  })

  it('sazba vybraná ze sazebníku se jen předvyplní do pole', async () => {
    const wrapper = await render()

    wrapper.findComponent({ name: 'AccidentInsuranceRatePicker' }).vm.$emit('select', '9.80')
    await flushPromises()

    expect((rateInput(wrapper).element as HTMLInputElement).value).toBe('9.80')
    expect(m.createAccidentInsuranceRate).not.toHaveBeenCalled()
  })
})
