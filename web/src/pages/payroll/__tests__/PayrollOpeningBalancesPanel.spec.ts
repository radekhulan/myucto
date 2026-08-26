import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import type { PayrollOpeningMonth } from '@/api/payroll'

const m = vi.hoisted(() => ({
  load: vi.fn(),
  save: vi.fn(),
  success: vi.fn(),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    statutoryOpenings: m.load,
    saveStatutoryOpenings: m.save,
  },
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.success }),
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string) => key }),
}))

import PayrollOpeningBalancesPanel from '@/pages/payroll/PayrollOpeningBalancesPanel.vue'

describe('PayrollOpeningBalancesPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.load.mockResolvedValue({
      locked: false,
      months: [],
      openings: { social_insurance: null, income_tax: null },
      source_reference: '',
    })
    m.save.mockResolvedValue({
      locked: false,
      months: [],
      openings: { social_insurance: 1, income_tax: 2 },
      source_reference: '',
    })
  })

  it('starts a transferred employee opening with the actual employment month', async () => {
    const wrapper = mount(PayrollOpeningBalancesPanel, {
      props: {
        personId: 8,
        startPeriod: '2026-08',
        canWrite: true,
        includePriorMonths: true,
        firstIncludedMonth: 3,
      },
    })
    await flushPromises()

    const save = wrapper.get('[data-test="openings-save"]')
    expect(save.attributes('disabled')).toBeUndefined()
    await save.trigger('click')
    await flushPromises()

    expect(m.save).toHaveBeenCalledWith(8, {
      year: 2026,
      source_reference: '',
      months: expect.arrayContaining([
        expect.objectContaining({ month: 3, social_assessment_base_minor_units: 0 }),
        expect.objectContaining({ month: 7, advance_base_minor_units: 0 }),
      ]),
    })
    expect(m.save.mock.calls[0][1].months).toHaveLength(5)
    expect(m.save.mock.calls[0][1].months.map((month: PayrollOpeningMonth) => month.month))
      .toEqual([3, 4, 5, 6, 7])
  })

  it('saves an explicit zero state without fictitious completed months for a new hire', async () => {
    const wrapper = mount(PayrollOpeningBalancesPanel, {
      props: {
        personId: 9,
        startPeriod: '2026-08',
        canWrite: true,
        includePriorMonths: false,
        firstIncludedMonth: null,
      },
    })
    await flushPromises()

    await wrapper.get('[data-test="openings-save"]').trigger('click')
    await flushPromises()

    expect(m.save).toHaveBeenCalledWith(9, {
      year: 2026,
      source_reference: '',
      months: [],
    })
  })

  it('reports incomplete or malformed opening ids as not saved', async () => {
    m.load.mockResolvedValue({ locked: false, months: [], source_reference: '' })
    const wrapper = mount(PayrollOpeningBalancesPanel, {
      props: {
        personId: 10,
        startPeriod: '2026-08',
        canWrite: true,
        includePriorMonths: false,
        firstIncludedMonth: null,
      },
    })
    await flushPromises()

    expect(wrapper.emitted('loaded')).toEqual([[false]])
  })

  it('reports the opening as saved only when both accumulator ids exist', async () => {
    m.load.mockResolvedValue({
      locked: false,
      months: [],
      openings: { social_insurance: 11, income_tax: 12 },
      source_reference: '',
    })
    const wrapper = mount(PayrollOpeningBalancesPanel, {
      props: {
        personId: 11,
        startPeriod: '2026-08',
        canWrite: true,
        includePriorMonths: false,
        firstIncludedMonth: null,
      },
    })
    await flushPromises()

    expect(wrapper.emitted('loaded')).toEqual([[true]])
  })
})
