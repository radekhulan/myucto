import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import PayrollBankPaymentSubmission from '../PayrollBankPaymentSubmission.vue'
import type { PayrollPaymentBatch } from '@/api/payrollPayments'

const m = vi.hoisted(() => ({ status: vi.fn(), submit: vi.fn(), canRead: vi.fn(), canWrite: vi.fn() }))
vi.mock('@/api/payrollBankSubmissions', () => ({ payrollBankSubmissionsApi: m }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => m }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('@/composables/useFormat', () => ({ formatDate: (value: string) => value, formatDateTime: (value: string) => value, formatMoneyMinor: (value: number) => String(value / 100) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string, values?: unknown) => key + (values ? JSON.stringify(values) : '') }) }))
const batch: PayrollPaymentBatch = { id: 7, batch_reference: 'TEST-7', channel: 'bank', export_format: 'abo', planned_payment_date: '2026-09-30', statutory_due_on: null, is_shifted: false, currency_code: 'CZK', declared_total_minor: 12345, declared_item_count: 2, settled_minor: 0, created_at: '2026-09-01', exports: [] }
const props = { batches: [batch], canWrite: true }
const result = { id: 4, payroll_batch_id: 7, connection_id: 3, status: 'accepted_awaiting_authorization', submitted_at: null }
const ready = { submission: null, connections: [{ id: 3, provider: 'fio', label: 'Test bank' }], blocked_reason: null }
async function selected() {
  const wrapper = mount(PayrollBankPaymentSubmission, { props })
  await wrapper.get('[data-test="batch"]').setValue(7)
  await flushPromises()
  return wrapper
}
describe('PayrollBankPaymentSubmission', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canRead.mockReturnValue(true)
    m.canWrite.mockReturnValue(true)
    m.status.mockImplementation(async () => structuredClone(ready))
    m.submit.mockResolvedValue({ created: true, submission: result })
    vi.spyOn(window, 'confirm').mockReturnValue(true)
  })
  it('requires both read permissions and does not request state when hidden', () => {
    m.canRead.mockImplementation((key: string) => key !== 'settings.bank_accounts')
    const wrapper = mount(PayrollBankPaymentSubmission, { props })
    expect(wrapper.find('section').exists()).toBe(false)
    expect(m.status).not.toHaveBeenCalled()
  })
  it('requires both write permissions', async () => {
    m.canWrite.mockImplementation((key: string) => key !== 'payroll.payments')
    const wrapper = await selected()
    expect(wrapper.find('[data-test="submit"]').exists()).toBe(false)
  })
  it('offers only bank CZK ABO batches', () => {
    const wrapper = mount(PayrollBankPaymentSubmission, { props: { ...props, batches: [batch, { ...batch, id: 8, currency_code: 'EUR' }, { ...batch, id: 9, channel: 'cash' }, { ...batch, id: 10, export_format: 'sepa' }] } })
    expect(wrapper.findAll('[data-test="batch"] option')).toHaveLength(2)
  })
  it('confirms the saved batch amount count and bank and sends identifiers only', async () => {
    const wrapper = await selected()
    await wrapper.get('[data-test="submit"]').trigger('click')
    await flushPromises()
    expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('TEST-7'))
    expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('Test bank'))
    expect(window.confirm).toHaveBeenCalledWith(expect.stringContaining('"count":2'))
    expect(m.submit).toHaveBeenCalledExactlyOnceWith(7, 3)
    expect(wrapper.find('[data-test="submit"]').exists()).toBe(false)
  })
  it('does not submit after cancel', async () => {
    vi.mocked(window.confirm).mockReturnValue(false)
    const wrapper = await selected()
    await wrapper.get('[data-test="submit"]').trigger('click')
    expect(m.submit).not.toHaveBeenCalled()
  })
  it.each(['accepted_awaiting_authorization', 'import_started', 'unknown', 'rejected'])('never resends persisted %s', async status => {
    m.status.mockResolvedValue({ ...ready, submission: { ...result, status } })
    const wrapper = await selected()
    expect(wrapper.find('[data-test="submit"]').exists()).toBe(false)
  })
  it('keeps an uncertain attempt blocked even after an empty refresh', async () => {
    m.submit.mockRejectedValue(new Error('secret raw transport'))
    const wrapper = await selected()
    await wrapper.get('[data-test="submit"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="refresh"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="submit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).not.toContain('secret raw transport')
  })
  it('blocks when status is unavailable', async () => {
    m.status.mockRejectedValue(new Error('secret'))
    const wrapper = await selected()
    expect(wrapper.get('[data-test="submit"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).not.toContain('secret')
  })
})
