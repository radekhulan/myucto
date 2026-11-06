import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import BankPaymentSubmission from '../BankPaymentSubmission.vue'
import type { BankPaymentSubmission as Submission } from '@/api/bankConnections'

const m = vi.hoisted(() => ({ list: vi.fn(), submission: vi.fn(), submit: vi.fn(), sign: vi.fn(), refreshFromBank: vi.fn(), items: vi.fn(), canWrite: vi.fn(), canRead: vi.fn(), navigate: vi.fn() }))
vi.mock('@/api/bankConnections', async importOriginal => ({ ...(await importOriginal<typeof import('@/api/bankConnections')>()), bankConnectionsApi: m }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canRead: m.canRead, canWrite: m.canWrite }) }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (s: string) => s, formatDateTime: (s: string) => s, formatMoney: (n: number) => String(n) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, te: () => true }) }))
vi.mock('@/workspace/fullPageNavigation', () => ({ navigateFullPage: m.navigate }))

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

  describe('Česká spořitelna', () => {
    const csasOrder = { ...order, payer_bank_code: '0800' }
    const csasConnection = { ...connection, provider: 'csas', account: { ...connection.account, bank_code: '0800' } }
    const pending = (status: Submission['status'], extra: Partial<Submission> = {}): Submission =>
      ({ id: 1, payment_order_id: order.id, connection_id: 4, provider: 'csas', status, submitted_at: '2026-10-22', ...extra })
    const button = (wrapper: ReturnType<typeof mount>, test: string) => wrapper.find(`[data-test="${test}"]`)
    beforeEach(() => {
      m.list.mockResolvedValue({ providers: [{ code: 'csas', implemented: true, capabilities: { payment_order_submission: true } }], connections: [csasConnection] })
    })

    it('offers a connected ČS account for submission', async () => {
      m.submit.mockResolvedValue(pending('awaiting_signature'))
      const wrapper = mount(BankPaymentSubmission, { props: { orders: [csasOrder], modelValue: order.id } })
      await flushPromises()
      await sendButton(wrapper).trigger('click')
      await flushPromises()
      expect(m.submit).toHaveBeenCalledExactlyOnceWith(11, 4)
      expect(wrapper.text()).toContain('bank_connection.payment_status_awaiting_signature')
      expect(button(wrapper, 'sign').exists()).toBe(true)
    })

    it('redirects to the bank only for a bank signing address', async () => {
      m.submission.mockResolvedValue(pending('awaiting_signature'))
      m.sign.mockResolvedValueOnce({ signing_url: 'https://george.csas.cz/sign?id=1', submission: pending('awaiting_signature') })
      m.sign.mockResolvedValueOnce({ signing_url: 'https://evil.example/sign', submission: pending('awaiting_signature') })
      const wrapper = mount(BankPaymentSubmission, { props: { orders: [csasOrder], modelValue: order.id } })
      await flushPromises()
      expect(sendButton(wrapper)).toBeUndefined()
      await button(wrapper, 'sign').trigger('click')
      await flushPromises()
      expect(m.navigate).toHaveBeenCalledOnce()
      expect(String(m.navigate.mock.calls[0][0])).toBe('https://george.csas.cz/sign?id=1')
      await button(wrapper, 'sign').trigger('click')
      await flushPromises()
      expect(m.navigate).toHaveBeenCalledOnce()
      expect(wrapper.find('[role="alert"]').exists()).toBe(true)
      expect(m.submit).not.toHaveBeenCalled()
    })

    it.each<Submission['status']>(['awaiting_signature', 'signed'])('checks %s status at the bank without submitting again', async status => {
      m.submission.mockResolvedValue(pending(status))
      m.refreshFromBank.mockResolvedValue(pending('completed', { accepted_count: 2 }))
      const wrapper = mount(BankPaymentSubmission, { props: { orders: [csasOrder], modelValue: order.id } })
      await flushPromises()
      await button(wrapper, 'bank-status').trigger('click')
      await flushPromises()
      expect(m.refreshFromBank).toHaveBeenCalledExactlyOnceWith(11)
      expect(wrapper.text()).toContain('bank_connection.payment_status_completed')
      expect(m.submit).not.toHaveBeenCalled()
    })

    it('resumes an interrupted submission through the same connection after confirmation', async () => {
      m.submission.mockResolvedValue(pending('creating'))
      m.submit.mockResolvedValue(pending('awaiting_signature'))
      const wrapper = mount(BankPaymentSubmission, { props: { orders: [csasOrder], modelValue: order.id } })
      await flushPromises()
      expect(wrapper.text()).toContain('bank_connection.payment_status_creating')
      expect(sendButton(wrapper)).toBeUndefined()
      await button(wrapper, 'resume').trigger('click')
      await flushPromises()
      expect(window.confirm).toHaveBeenCalledWith('bank_connection.payment_resume_confirm')
      expect(m.submit).toHaveBeenCalledExactlyOnceWith(11, 4)
    })

    it('lists created payments of an unclear submission for a check in George', async () => {
      m.submission.mockResolvedValue(pending('unknown', { error_code: 'csas_payment_outcome_unknown' }))
      m.items.mockResolvedValue([
        { line_no: 1, status: 'created', amount: '100.00', creditor_iban: 'CZ1801000000001000000005', creditor_account: '1000000005/0100',
          variable_symbol: '2026001', instruction_identification: 'MU001x', provider_sign_id: '166609107206666', instruction_status: 'ACTC' },
        { line_no: 2, status: 'sending', amount: '200.00', creditor_iban: 'CZ4803000000191000000005', creditor_account: '19-1000000005/0300',
          variable_symbol: null, instruction_identification: 'MU002x', provider_sign_id: null, instruction_status: null },
        { line_no: 3, status: 'planned', amount: '300.00', creditor_iban: 'CZ1801000000001000000005', creditor_account: '1000000005/0100',
          variable_symbol: '3', instruction_identification: 'MU003x', provider_sign_id: null, instruction_status: null },
      ])
      const wrapper = mount(BankPaymentSubmission, { props: { orders: [csasOrder], modelValue: order.id } })
      await flushPromises()
      expect(m.items).toHaveBeenCalledExactlyOnceWith(11)
      const rows = wrapper.findAll('[data-test="submission-item"]')
      expect(rows).toHaveLength(2)
      expect(rows[0].text()).toContain('1000000005/0100')
      expect(rows[0].text()).toContain('bank_connection.payment_item_bank_id')
      expect(rows[1].text()).toContain('bank_connection.payment_item_unconfirmed')
    })

    it('offers no signing for an archived order', async () => {
      m.submission.mockResolvedValue(pending('awaiting_signature', { error_code: 'payment_order_no_longer_payable' }))
      const wrapper = mount(BankPaymentSubmission, { props: { orders: [csasOrder], modelValue: order.id } })
      await flushPromises()
      expect(button(wrapper, 'sign').exists()).toBe(false)
      expect(button(wrapper, 'bank-status').exists()).toBe(false)
      expect(wrapper.text()).toContain('bank_connection.payment_note_payment_order_no_longer_payable')
    })

    it('names the duplicate lines when the order is refused before any payment', async () => {
      m.submit.mockRejectedValue({ response: { status: 422, data: { error: { code: 'payment_order_duplicate_items', message: 'x', duplicate_lines: [[1, 3]] } } } })
      const wrapper = mount(BankPaymentSubmission, { props: { orders: [csasOrder], modelValue: order.id } })
      await flushPromises()
      await sendButton(wrapper).trigger('click')
      await flushPromises()
      expect(wrapper.find('[role="alert"]').text()).toBe('bank_connection.error_duplicate_items_lines')
    })

    it.each<Submission['status']>(['unknown', 'rejected', 'expired', 'completed', 'partially_rejected'])('offers neither signing nor resume for %s', async status => {
      m.submission.mockResolvedValue(pending(status, { error_code: 'csas_payment_outcome_unknown' }))
      const wrapper = mount(BankPaymentSubmission, { props: { orders: [csasOrder], modelValue: order.id } })
      await flushPromises()
      expect(button(wrapper, 'sign').exists()).toBe(false)
      expect(button(wrapper, 'resume').exists()).toBe(false)
      expect(sendButton(wrapper)).toBeUndefined()
      expect(wrapper.text()).toContain('bank_connection.payment_note_csas_payment_outcome_unknown')
    })
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
