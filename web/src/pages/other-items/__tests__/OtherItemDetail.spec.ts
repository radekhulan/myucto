import { flushPromises, shallowMount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  allocations: vi.fn(),
  paymentCandidates: vi.fn(),
  updateCounterpartyAccount: vi.fn(),
}))

vi.mock('@/api/otherItems', () => ({
  otherItemsApi: { get: m.get, allocations: m.allocations, paymentCandidates: m.paymentCandidates,
    updateCounterpartyAccount: m.updateCounterpartyAccount },
}))
vi.mock('@/api/accounting', () => ({ accountingApi: { listAccounts: vi.fn() } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true, canRead: () => true }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplier: { accounting_mode: 'tax_evidence' } }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number) => `${v} Kč` }))
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '9' } }),
  useRouter: () => ({ push: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key }),
}))

import OtherItemDetail from '../OtherItemDetail.vue'

describe('OtherItemDetail', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.get.mockResolvedValue({
      id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null, issued_on: '2099-01-01',
      due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000, status: 'confirmed',
      variable_symbol: '2099777', counterparty_account: '19-1000000005', counterparty_bank_code: '0100',
    })
    m.allocations.mockResolvedValue([])
    m.paymentCandidates.mockResolvedValue([
      { source: 'bank', id: 41, payment_on: '2099-03-09', amount: 1000, currency: 'CZK', description: 'Syntetická platba',
        match_score: 75, match_reasons: ['counterparty_account', 'installment_amount'],
        installment: { id: 5, position: 1, due_on: '2099-03-10', remaining_amount: 1000 } },
      { source: 'bank', id: 42, payment_on: '2099-03-30', amount: 999, currency: 'CZK', description: 'Jiná platba',
        match_score: 0, match_reasons: [], installment: null },
    ])
  })

  it('ukáže účet protistrany a u kandidátů úhrady důvody shody', async () => {
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    expect(wrapper.get('[data-test="counterparty-account"]').text()).toBe('19-1000000005 / 0100')

    await wrapper.get('input[type="search"]').trigger('focus')
    await flushPromises()
    const groups = wrapper.findAll('[data-test="match-reasons"]')
    expect(groups).toHaveLength(1)
    expect(groups[0]!.findAll('span').map(span => span.text())).toEqual([
      'other_items.match_reason.counterparty_account:{"number":1}',
      'other_items.match_reason.installment_amount:{"number":1}',
    ])
  })

  it('upraví účet protistrany potvrzeného dokladu a označí automatické přiřazení', async () => {
    m.allocations.mockResolvedValue([{ id: 3, installment_id: null, bank_transaction_id: 77, cash_document_id: null,
      amount: 1000, payment_on: '2099-03-10', auto_match_reason: 'other_item_vs_account', reversed_on: null }])
    m.updateCounterpartyAccount.mockResolvedValue({
      id: 9, side: 'payable', kind: 'loan', title: 'Syntetický úvěr', partner_name: null, issued_on: '2099-01-01',
      due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 1000, remaining_amount: 2000, status: 'confirmed',
      variable_symbol: '2099777', counterparty_account: '1000000005', counterparty_bank_code: '0100',
    })
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    expect(wrapper.get('[data-test="allocation-auto"]').attributes('title')).toBe('other_items.auto_match_reason.other_item_vs_account')

    await wrapper.get('[data-test="counterparty-edit"]').trigger('click')
    const input = wrapper.get('[data-test="counterparty-form"] input')
    expect((input.element as HTMLInputElement).value).toBe('19-1000000005 / 0100')
    await input.setValue('1000000005/0100')
    await wrapper.get('[data-test="counterparty-form"]').trigger('submit')
    await flushPromises()

    expect(m.updateCounterpartyAccount).toHaveBeenCalledWith(9, '1000000005/0100')
    expect(wrapper.find('[data-test="counterparty-form"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="counterparty-account"]').text()).toBe('1000000005 / 0100')
  })

  it('koncept účet protistrany samostatně neupravuje', async () => {
    m.get.mockResolvedValue({ id: 9, side: 'payable', kind: 'loan', title: 'Koncept', partner_name: null,
      issued_on: '2099-01-01', due_on: '2099-03-10', currency: 'CZK', amount: 3000, paid_amount: 0, remaining_amount: 3000,
      status: 'draft', variable_symbol: null, counterparty_account: null, counterparty_bank_code: null })
    const wrapper = shallowMount(OtherItemDetail)
    await flushPromises()
    expect(wrapper.find('[data-test="counterparty-edit"]').exists()).toBe(false)
  })
})
