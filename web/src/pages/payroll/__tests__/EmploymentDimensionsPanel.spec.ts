import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollDimension, PayrollEmploymentDimension } from '@/api/payroll'

const m = vi.hoisted(() => ({
  employmentDimensions: vi.fn(),
  payrollDimensions: vi.fn(),
  createEmploymentDimension: vi.fn(),
  updateEmploymentDimension: vi.fn(),
  success: vi.fn(),
  error: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    employmentDimensions: m.employmentDimensions,
    payrollDimensions: m.payrollDimensions,
    createEmploymentDimension: m.createEmploymentDimension,
    updateEmploymentDimension: m.updateEmploymentDimension,
  },
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success, error: m.error }),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }),
}))

import EmploymentDimensionsPanel from '@/pages/payroll/EmploymentDimensionsPanel.vue'

function dimension(overrides: Partial<PayrollDimension> = {}): PayrollDimension {
  return {
    id: 7,
    dimension_type: 'cost_center',
    code: 'S01',
    name: 'Provoz',
    account_code: null,
    valid_from: '2026-01-01',
    valid_to: null,
    is_active: true,
    row_version: 1,
    ...overrides,
  } as PayrollDimension
}

function assignment(
  overrides: Partial<PayrollEmploymentDimension> = {},
): PayrollEmploymentDimension {
  return {
    id: 3,
    employment_id: 12,
    dimension_id: 7,
    dimension_type: 'cost_center',
    dimension_code: 'S01',
    dimension_name: 'Provoz',
    valid_from: '2026-01-01',
    valid_to: null,
    row_version: 1,
    ...overrides,
  } as PayrollEmploymentDimension
}

function mountPanel() {
  return mount(EmploymentDimensionsPanel, {
    props: { employmentId: 12, canWrite: true },
    global: {
      stubs: {
        RouterLink: {
          props: ['to'],
          template: '<a :data-to="JSON.stringify(to)"><slot /></a>',
        },
        SearchableSelect: {
          props: ['modelValue'],
          template: '<span />',
        },
      },
    },
  })
}

describe('EmploymentDimensionsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.employmentDimensions.mockResolvedValue([assignment()])
    m.payrollDimensions.mockResolvedValue([dimension()])
    m.createEmploymentDimension.mockResolvedValue(assignment({ id: 4 }))
  })

  it('řekne, proč Uložit nic neudělalo, když chybí dimenze', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    await wrapper.get('button').trigger('click')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.createEmploymentDimension).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="dimensions-invalid-reason"]').text())
      .toBe('payroll.people.dimensions.dimension_required')
  })

  it('u obráceného intervalu pojmenuje datum, ne mlčí', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    // Přes „upravit" u existujícího přiřazení — dimenze je tím vybraná
    // a zbývá jediná vada: obrácený interval.
    await wrapper.findAll('button')[1].trigger('click')
    const dates = wrapper.findAll('input[type="date"]')
    await dates[0].setValue('2026-05-01')
    await dates[1].setValue('2026-04-01')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(m.updateEmploymentDimension).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="dimensions-invalid-reason"]').text())
      .toBe('payroll.people.dimensions.valid_to_invalid')
  })

  it('prázdný číselník nabídne cestu ven do nastavení mezd', async () => {
    m.payrollDimensions.mockResolvedValue([])
    const wrapper = mountPanel()
    await flushPromises()

    const link = wrapper.get('[data-test="dimensions-none-available"]').get('a')
    expect(link.attributes('data-to')).toContain('payroll-settings')
    expect(link.attributes('data-to')).toContain('dimensions')
  })

  it('nabídku ven neukazuje, dokud je v číselníku účinná dimenze', async () => {
    const wrapper = mountPanel()
    await flushPromises()

    expect(wrapper.find('[data-test="dimensions-none-available"]').exists()).toBe(false)
  })
})
