import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import BankPaymentSubmission from '../BankPaymentSubmission.vue'
import type { BankPaymentSubmission as Submission } from '@/api/bankConnections'

const m = vi.hoisted(() => ({ list: vi.fn(), submission: vi.fn(), submit: vi.fn(), canWrite: vi.fn(), canRead: vi.fn() }))
vi.mock('@/api/bankConnections', () => ({ bankConnectionsApi: m }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: m.canRead, canWrite: m.canWrite }) }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (s: string) => s, formatDateTime: (s: string) => s, formatMoney: (n: number) => String(n) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

const order = {
  id: 11, currency: 'CZK', payment_date: '2026-09-07', total_amount: 100, item_count: 1,
  mark_paid: false, note: null, created_at: '2026-09-07', payer_account_label: 'Test',
  payer_account_number: '1000000005', payer_bank_code: '2010', payer_iban: null,
}
const connection = {
  id: 4, currency_id: 3, provider: 'test', enabled: true, has_token: true, validated_at: '2026-09-07',
  account: { id: 3, code: 'CZK', label: 'Test', account_number: '1000000005', bank_code: '2010', iban: null },
}
const sendButton = (wrapper: ReturnType<typeof mount>) => wrapper.findAll('button').find(button => button.text() === 'bank_connection.payment_submit')!
beforeEach(() => {
  vi.clearAllMocks()
  m.canRead.mockReturnValue(true)
  m.canWrite.mockReturnValue(true)
  m.list.mockResolvedValue({ providers: [{ code: 'test', implemented: true, capabilities: { payment_order_submission: true } }], connections: [connection] })
  m.submission.mockResolvedValue(null)
  vi.spyOn(window, 'confirm').mockReturnValue(true)
})

describe('Bank payment submission', () => {
  it('does not offer a Slovak connection for Czech ABO submission', async () => {
    m.list.mockResolvedValue({ providers: [{ code: 'fio', implemented: true, capabilities: { payment_order_submission: true } }], connections: [{ ...connection, provider: 'fio', account: { ...connection.account, bank_code: '8330' } }] })
    const wrapper = mount(BankPaymentSubmission, { props: { orders: [{ ...order, payer_bank_code: '8330' }], modelValue: order.id } })
    await flushPromises()
    expect(sendButton(wrapper).attributes('disabled')).toBeDefined()
    expect(m.submit).not.toHaveBeenCalled()
  })

  it.each<Submission['status']>(['accepted_awaiting_authorization', 'rejected', 'unknown'])('loads persisted %s and blocks another submission', async status => {
    m.submission.mockResolvedValue({ id: 1, payment_order_id: order.id, connection_id: 4, status, submitted_at: '2026-09-07' })
    const wrapper = mount(BankPaymentSubmission, { props: { orders: [order], modelValue: order.id } })
    await flushPromises()
    expect(wrapper.text()).toContain(`bank_connection.payment_status_${status}`)
    expect(sendButton(wrapper)).toBeUndefined()
    expect(m.submit).not.toHaveBeenCalled()
  })

  it('requires both write permissions', async () => {
    m.canWrite.mockImplementation((permission: string) => permission !== 'settings.bank_accounts')
    const wrapper = mount(BankPaymentSubmission, { props: { orders: [order], modelValue: order.id } })
    await flushPromises()
    expect(wrapper.text()).toContain('bank_connection.payment_permissions')
    expect(sendButton(wrapper)).toBeUndefined()
  })

  it('shows the accepted and rejected counts for a partial submission', async () => {
    m.submission.mockResolvedValue({ id: 1, payment_order_id: order.id, connection_id: 4, status: 'unknown', accepted_count: 1, rejected_count: 0 })
    const wrapper = mount(BankPaymentSubmission, { props: { orders: [order], modelValue: order.id } })
    await flushPromises()
    expect(wrapper.text()).toContain('bank_connection.payment_accepted_count')
    expect(wrapper.text()).toContain('bank_connection.payment_rejected_count')
    expect(sendButton(wrapper)).toBeUndefined()
  })

  it('does not offer a connection with another bank code or currency', async () => {
    m.list.mockResolvedValue({ providers: [{ code: 'test', implemented: true, capabilities: { payment_order_submission: true } }], connections: [{ ...connection, account: { ...connection.account, bank_code: '0100' } }] })
    const wrapper = mount(BankPaymentSubmission, { props: { orders: [order], modelValue: order.id } })
    await flushPromises()
    expect(sendButton(wrapper).attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('bank_connection.payment_no_connection')
    await wrapper.setProps({ orders: [{ ...order, currency: 'EUR' }] })
    expect(wrapper.text()).toContain('bank_connection.payment_czk_only')
  })

  it('asks for confirmation and submits only the saved order and selected connection once', async () => {
    m.submit.mockResolvedValue({ id: 1, payment_order_id: 11, connection_id: 4, status: 'accepted_awaiting_authorization' })
    const wrapper = mount(BankPaymentSubmission, { props: { orders: [order], modelValue: order.id } })
    await flushPromises()
    await sendButton(wrapper).trigger('click')
    await flushPromises()
    expect(window.confirm).toHaveBeenCalled()
    expect(m.submit).toHaveBeenCalledExactlyOnceWith(11, 4)
    expect(sendButton(wrapper)).toBeUndefined()
  })

  it('keeps an uncertain attempt blocked across refresh and selection changes', async () => {
    m.submit.mockRejectedValue(new Error('Offline'))
    const wrapper = mount(BankPaymentSubmission, { props: { orders: [order], modelValue: order.id } })
    await flushPromises()
    await sendButton(wrapper).trigger('click')
    await flushPromises()
    expect(sendButton(wrapper).attributes('disabled')).toBeDefined()
    await wrapper.setProps({ modelValue: null })
    await wrapper.setProps({ modelValue: order.id })
    await flushPromises()
    expect(sendButton(wrapper).attributes('disabled')).toBeDefined()
    expect(m.submit).toHaveBeenCalledTimes(1)
  })

  it('does not submit when status lookup fails', async () => {
    m.submission.mockRejectedValue(new Error('Offline'))
    const wrapper = mount(BankPaymentSubmission, { props: { orders: [order], modelValue: order.id } })
    await flushPromises()
    expect(sendButton(wrapper).attributes('disabled')).toBeDefined()
    expect(m.submit).not.toHaveBeenCalled()
  })

  it.each(['bank_rate_limited', 'bank_connection_busy'])('allows an explicit retry after a safe %s preflight refusal', async code => {
    m.submit.mockRejectedValueOnce({ response: { status: 409, data: { error: { code, message: 'Wait' } } } })
    const wrapper = mount(BankPaymentSubmission, { props: { orders: [order], modelValue: order.id } })
    await flushPromises()
    await sendButton(wrapper).trigger('click')
    await flushPromises()
    expect(sendButton(wrapper).attributes('disabled')).toBeUndefined()
    expect(m.submit).toHaveBeenCalledTimes(1)
  })
})
