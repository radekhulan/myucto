import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import PaymentOrders from '../PaymentOrders.vue'
import type { OtherPayablePaymentCandidate, PayerAccount } from '@/api/paymentOrders'

const m = vi.hoisted(() => ({
  list: vi.fn(), candidates: vi.fn(), create: vi.fn(), markOrdered: vi.fn(), download: vi.fn(),
  success: vi.fn(), error: vi.fn(), warning: vi.fn(), info: vi.fn(),
}))
vi.mock('@/api/paymentOrders', () => ({
  paymentOrdersApi: {
    list: m.list, candidates: m.candidates, create: m.create, markOrdered: m.markOrdered,
    downloadPaymentOrder: m.download,
  },
}))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: m.success, error: m.error, warning: m.warning, info: m.info }) }))
vi.mock('vue-i18n', async importOriginal => ({ ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }), useRouter: () => ({ push: vi.fn() }) }))

const czk = { id: 7, code: 'CZK', label: 'Synthetic account', account_number: '1000000005', bank_code: '0100', iban: null, bic: null, is_default: true, is_active: true } as unknown as PayerAccount
const eur = { id: 8, code: 'EUR', label: 'Synthetic EUR', account_number: null, bank_code: null, iban: 'DE06123456780000000005', bic: null, is_default: false, is_active: true } as unknown as PayerAccount
const other = (over: Partial<OtherPayablePaymentCandidate>): OtherPayablePaymentCandidate => ({
  key: 'other:31:41', other_item_id: 31, installment_id: 41, installment_position: 1, installment_count: 3,
  side: 'payable', kind: 'loan', kind_name: null, title: 'Synthetic loan', partner_name: 'Synthetic lender',
  document_no: 'OZ-2099-1', due_date: '2099-01-31', amount: 1000, currency: 'CZK',
  account_number: '1000000005', bank_code: '0100', iban: null, variable_symbol: '2099001',
  has_account: true, abo_eligible: true, ordered_at: null, payable: true, reason: null, ...over,
})
const empty = { data: [], meta: { total: 0, page: 1, pages: 1, per_page: 50 } }

type State = {
  toggleOther: (c: OtherPayablePaymentCandidate) => void
  toggleAllOthers: () => void
  createAndDownload: (format: 'abo') => Promise<void>
  markOnly: () => Promise<void>
  onPayerChange: () => void
  selectedTotal: number
  selectedOtherKeys: string[]
  otherCandidates: OtherPayablePaymentCandidate[]
  visibleOthers: OtherPayablePaymentCandidate[]
  hideOrdered: boolean
  selectedPayerId: number | ''
}

beforeEach(() => {
  vi.clearAllMocks()
  m.list.mockResolvedValue(empty)
  m.candidates.mockResolvedValue({
    ...empty,
    payer_accounts: [czk, eur],
    other_candidates: [
      other({}),
      other({ key: 'other:32', other_item_id: 32, installment_id: null, installment_position: null, installment_count: null, amount: 2500 }),
      other({ key: 'other:33', other_item_id: 33, installment_id: null, installment_position: null, installment_count: null,
        account_number: null, bank_code: null, has_account: false, abo_eligible: false, payable: false, reason: 'no_account' }),
      other({ key: 'other:34:44', other_item_id: 34, installment_id: 44, ordered_at: '2099-01-02 10:00:00', payable: false, reason: 'already_ordered' }),
    ],
  })
  m.create.mockResolvedValue({ order_id: 5, view: { item_count: 2 }, skipped: [], clamped_date: false })
})

describe('Platební příkazy: ostatní závazky', () => {
  it('posílá vybrané závazky jako ID položky a nabídnuté splátky, ne jako účet a částku', async () => {
    const wrapper = shallowMount(PaymentOrders)
    await flushPromises()
    const vm = wrapper.vm as unknown as State
    expect(wrapper.find('[data-testid="other-payables"]').exists()).toBe(true)
    const [installment, whole, noAccount, ordered] = vm.otherCandidates
    vm.toggleOther(installment)
    vm.toggleOther(whole)
    vm.toggleOther(noAccount)
    vm.toggleOther(ordered)
    expect(vm.selectedOtherKeys).toEqual(['other:31:41', 'other:32'])
    expect(vm.selectedTotal).toBe(3500)

    await vm.createAndDownload('abo')
    expect(m.create).toHaveBeenCalledWith(expect.objectContaining({
      invoice_ids: [],
      other_items: [{ id: 31, installment_id: 41 }, { id: 32, installment_id: null }],
      payer_currency_id: 7,
    }))
    wrapper.unmount()
  })

  it('výběr všech přeskočí závazky bez účtu a už zařazené, filtr skryje zařazené', async () => {
    const wrapper = shallowMount(PaymentOrders)
    await flushPromises()
    const vm = wrapper.vm as unknown as State
    vm.toggleAllOthers()
    expect(vm.selectedOtherKeys).toEqual(['other:31:41', 'other:32'])
    expect(vm.visibleOthers).toHaveLength(4)
    vm.hideOrdered = true
    await flushPromises()
    expect(vm.visibleOthers.map(c => c.key)).toEqual(['other:31:41', 'other:32', 'other:33'])
    wrapper.unmount()
  })

  it('cizoměnový účet plátce výběr ostatních závazků zruší', async () => {
    const wrapper = shallowMount(PaymentOrders)
    await flushPromises()
    const vm = wrapper.vm as unknown as State
    vm.toggleOther(vm.otherCandidates[0])
    vm.selectedPayerId = 8
    await flushPromises()
    vm.onPayerChange()
    expect(vm.selectedOtherKeys).toEqual([])
    vm.toggleOther(vm.otherCandidates[0])
    expect(vm.selectedOtherKeys).toEqual([])
    wrapper.unmount()
  })

  it('„Jen označit" ostatní závazky neoznačuje', async () => {
    const wrapper = shallowMount(PaymentOrders)
    await flushPromises()
    const vm = wrapper.vm as unknown as State
    vm.toggleOther(vm.otherCandidates[0])
    await vm.markOnly()
    expect(m.markOrdered).not.toHaveBeenCalled()
    expect(m.error).toHaveBeenCalledWith('payment_order.other_mark_only_unsupported')
    wrapper.unmount()
  })
})
