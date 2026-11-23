import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  closingPending: vi.fn(),
  closingPost: vi.fn(),
  permissions: new Set<string>(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

vi.mock('@/api/accruals', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/accruals')>()
  return { ...actual, accrualsApi: { closingPending: m.closingPending, closingPost: m.closingPost } }
})
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canRead: (key: string) => m.permissions.has(key), canWrite: (key: string) => m.permissions.has(key) }),
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.toastSuccess, error: m.toastError }) }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => `${v} Kč` }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import AccrualClosingBlock from '../AccrualClosingBlock.vue'

const ALL = ['accounting.accruals', 'accounting.accruals.write', 'accounting.journal.post', 'accounting.periods.close']
const yearlyRow = { schedule_id: 1, due_on: '2099-12-31', amount_planned: 1200, item_id: 4, item_no: 'CR-2099-0003', title: 'Syntetické nájemné',
  kind: 'prepaid_expense', method: 'yearly', accrual_account_code: '381', counter_account_code: '518' }

function mountBlock() {
  return mount(AccrualClosingBlock, { props: { periodId: 7 }, global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } } })
}

describe('AccrualClosingBlock', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.permissions = new Set(ALL)
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })

  it('roční řádky zaúčtuje krokem uzávěrky a na měsíční upozorní', async () => {
    m.closingPending.mockResolvedValue({ period_id: 7, ends_on: '2099-12-31', yearly: [yearlyRow], monthly: [{ ...yearlyRow, schedule_id: 2, method: 'monthly_days' }],
      yearly_total: 1200, monthly_total: 1200 })
    m.closingPost.mockResolvedValue({ posted: 1, total: 1200 })
    const wrapper = mountBlock()
    await flushPromises()
    expect(m.closingPending).toHaveBeenCalledWith(7)
    expect(wrapper.find('[data-test="accrual-closing-monthly"]').exists()).toBe(true)
    await wrapper.get('[data-test="accrual-closing-post"]').trigger('click')
    await flushPromises()
    expect(m.closingPost).toHaveBeenCalledWith(7)
    expect(wrapper.emitted('posted')).toHaveLength(1)
  })

  it('bez práva uzavírat období tlačítko zaúčtování nenabídne', async () => {
    m.permissions = new Set(['accounting.accruals', 'accounting.accruals.write', 'accounting.journal.post'])
    m.closingPending.mockResolvedValue({ period_id: 7, ends_on: '2099-12-31', yearly: [yearlyRow], monthly: [], yearly_total: 1200, monthly_total: 0 })
    const wrapper = mountBlock()
    await flushPromises()
    expect(wrapper.find('[data-test="accrual-closing-yearly"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="accrual-closing-post"]').exists()).toBe(false)
  })

  it('bez práva k agendě se blok nenačte ani nevykreslí', async () => {
    m.permissions = new Set()
    const wrapper = mountBlock()
    await flushPromises()
    expect(m.closingPending).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="accrual-closing-block"]').exists()).toBe(false)
  })
})
