import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  foodServiceTips: vi.fn(),
  saveFoodServiceTips: vi.fn(),
  peopleOptions: vi.fn(),
  canWrite: vi.fn(),
}))

vi.mock('@/api/payrollBenefitBaskets', () => ({
  payrollBenefitBasketsApi: {
    foodServiceTips: m.foodServiceTips,
    saveFoodServiceTips: m.saveFoodServiceTips,
  },
}))
vi.mock('@/api/payroll', () => ({ payrollApi: { peopleOptions: m.peopleOptions } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: m.canWrite }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn() }) }))
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key }),
}))

import PayrollFoodServiceTipsPanel from '@/pages/payroll/PayrollFoodServiceTipsPanel.vue'

function view(overrides: Record<string, unknown> = {}) {
  return {
    period_start: '2027-03-01',
    available: true,
    statute: '§ 6 odst. 9 písm. g) ZDP',
    month: null,
    approved_recipients: [],
    locked: false,
    ...overrides,
  }
}

describe('PayrollFoodServiceTipsPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canWrite.mockReturnValue(true)
    m.peopleOptions.mockResolvedValue([{ id: 7, full_name: 'Syntetický číšník', is_active: true, needs_setup: false }])
  })

  it('uloží tržby a počet zaměstnanců při rovném dělení', async () => {
    m.foodServiceTips.mockResolvedValue(view())
    m.saveFoodServiceTips.mockResolvedValue(view())
    const wrapper = mount(PayrollFoodServiceTipsPanel, { props: { period: '2027-03' } })
    await flushPromises()

    await wrapper.find('[data-test="food-service-tips-revenue"]').setValue('100000')
    await wrapper.find('[data-test="food-service-tips-recipients"]').setValue('2')
    await wrapper.find('[data-test="food-service-tips-save"]').trigger('click')
    await flushPromises()

    expect(m.saveFoodServiceTips).toHaveBeenCalledWith('2027-03', {
      revenue_minor: 10_000_000,
      allocation_mode: 'per_capita',
      recipients_count: 2,
      allocations: [],
      evidence_reference: null,
      note: null,
    })
  })

  it('zamčený měsíc řekne proč a uložení nepustí', async () => {
    m.foodServiceTips.mockResolvedValue(view({ locked: true, approved_recipients: [7] }))
    const wrapper = mount(PayrollFoodServiceTipsPanel, { props: { period: '2027-03' } })
    await flushPromises()

    expect(wrapper.find('[data-test="food-service-tips-locked"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="food-service-tips-save"]').attributes('disabled')).toBeDefined()
  })
})
