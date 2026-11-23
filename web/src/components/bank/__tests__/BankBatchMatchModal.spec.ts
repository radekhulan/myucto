import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import BankBatchMatchModal from '../BankBatchMatchModal.vue'

const m = vi.hoisted(() => ({ search: vi.fn(), preview: vi.fn(), confirm: vi.fn() }))
vi.mock('@/api/bank', () => ({
  bankApi: { matchCandidates: m.search, previewBatchMatch: m.preview, confirmBatchMatch: m.confirm },
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 7 }) }))
vi.mock('@/composables/useBankTransactionActions', () => ({ POSTING_REASON_KEYS: { period_closed: 'bank.posting.err_period_closed' } }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (v: string) => v, formatMoney: (v: number, currency: string) => `${v} ${currency}` }))

const document = { type: 'purchase_invoice' as const, id: 21, ref: 'TEST-21' }
const stub = { Modal: { template: '<section><slot /><slot name="footer" /></section>' } }
const statementRow = { currency: 'CZK', account_number: '100', bank_code: '0100', counterparty_name: 'Dodavatel', variable_symbol: '21', description: null }
const preview = {
  currency: 'CZK',
  paid_cents: 100000,
  remaining_cents: 400000,
  selected_cents: 350000,
  blocked: null,
  items: [
    { ...statementRow, id: 31, posted_at: '2026-06-13', amount: -2000 },
    { ...statementRow, id: 32, posted_at: '2026-06-14', amount: -1500 },
  ],
  version: 'selected-movements',
}

beforeEach(() => {
  vi.resetAllMocks()
  m.preview.mockResolvedValue(preview)
  m.confirm.mockResolvedValue({ items: [
    { id: 31, posting: { action: 'posted' } },
    { id: 32, posting: { action: 'skipped', reason: 'period_closed' } },
  ] })
})

describe('BankBatchMatchModal', () => {
  it('from the bank searches invoices, previews the exact selection and shows the posting result', async () => {
    m.search.mockResolvedValue({ candidates: [{ ...document, amount: 5000, currency: 'CZK', party: 'Dodavatel' }] })
    const wrapper = mount(BankBatchMatchModal, { props: { ids: [31, 32] }, global: { stubs: stub } })
    await wrapper.get('input[type="search"]').setValue('TEST-21')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.search).toHaveBeenCalledWith(31, 'TEST-21', true)
    await wrapper.get('li button').trigger('click')
    await flushPromises()
    expect(m.preview).toHaveBeenCalledWith(document, [31, 32])
    expect(wrapper.text()).toContain('3500 CZK')
    expect(wrapper.text()).toContain('4000 CZK')
    expect(wrapper.text()).toContain('1000 CZK')
    expect(m.confirm).not.toHaveBeenCalled()
    await wrapper.get('[data-test="confirm-bank-batch"]').trigger('click')
    await flushPromises()
    expect(m.confirm).toHaveBeenCalledWith(document, [31, 32], 'selected-movements')
    expect(wrapper.emitted('done')).toHaveLength(1)
    expect(wrapper.text()).toContain('bank.posting.matched_and_posted')
    expect(wrapper.text()).toContain('bank.posting.matched_not_posted')
    expect(wrapper.text()).toContain('500 CZK')
    wrapper.unmount()
  })

  it('blocks overpayment, and a stale confirmation requires a new preview', async () => {
    m.preview.mockResolvedValueOnce({ ...preview, remaining_cents: 200000, blocked: 'overpayment' })
    const blocked = mount(BankBatchMatchModal, { props: { ids: [31, 32], document }, global: { stubs: stub } })
    await flushPromises()
    expect(blocked.get('[role="alert"]').text()).toBe('bank.batch_match.overpayment')
    expect(blocked.get('[data-test="confirm-bank-batch"]').attributes('disabled')).toBeDefined()
    blocked.unmount()

    m.confirm.mockRejectedValueOnce(new Error('Zůstatek se změnil'))
    const wrapper = mount(BankBatchMatchModal, { props: { ids: [31, 32], document }, global: { stubs: stub } })
    await flushPromises()
    await wrapper.get('[data-test="confirm-bank-batch"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('Zůstatek se změnil')
    expect(wrapper.find('[data-test="confirm-bank-batch"]').exists()).toBe(false)
    expect(wrapper.emitted('done')).toBeUndefined()
    m.preview.mockResolvedValueOnce({ ...preview, version: 'new-version' })
    await wrapper.findAll('button').find(button => button.text() === 'bank.batch_match.reload_preview')!.trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="confirm-bank-batch"]').trigger('click')
    await flushPromises()
    expect(m.confirm).toHaveBeenLastCalledWith(document, [31, 32], 'new-version')
    wrapper.unmount()
  })
})
