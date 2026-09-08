import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import BankConnectionAccount from '../BankConnectionAccount.vue'
import type { BankConnection } from '@/api/bankConnections'
import type { CurrencyAccount } from '@/api/settings'

const m = vi.hoisted(() => ({ save: vi.fn(), sync: vi.fn(), disconnect: vi.fn() }))
vi.mock('@/api/bankConnections', () => ({ bankConnectionsApi: m }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
vi.mock('@/composables/useFormat', () => ({
  formatDate: (s: string) => s,
  formatDateTime: (s: string) => s,
  formatMoney: (amount: number, currency: string) => `${amount.toFixed(2)} ${currency}`,
}))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }) }))

const account: CurrencyAccount = {
  id: 3, code: 'CZK', label: 'Test', symbol: 'Kč', name_cs: 'Koruna', name_en: 'Crown', decimals: 2,
  is_active: true, is_default: true, account_number: '1000000005', bank_code: '0100', bank_name: null, iban: null, bic: null,
}
const connection: BankConnection = {
  id: 4, currency_id: 3, provider: 'test', enabled: true, has_token: true, validated_at: '2026-09-07',
  last_sync_at: null, last_sync_status: null, last_sync_error_code: null, next_sync_from: null,
  created_at: '2026-09-07', updated_at: '2026-09-07', account,
}
const provider = { code: 'test', label: 'Test', implemented: true, bank_codes: ['0100'], capabilities: { statement_import: true, payment_order_submission: false } }
async function open(overrides: { connection?: BankConnection | null; canWrite?: boolean } = {}) {
  const wrapper = mount(BankConnectionAccount, {
    props: { account, connection, canWrite: true, providers: [provider], ...overrides },
    global: { stubs: { DateInput: true, RouterLink: true } },
  })
  await wrapper.find('button').trigger('click')
  return wrapper
}
beforeEach(() => {
  vi.clearAllMocks()
  m.save.mockResolvedValue(connection)
  m.sync.mockResolvedValue({ status: 'success', import_result: { transactions: 0, matched: 0 }, period: { from: '2026-01-01', to: '2026-01-31' } })
})

describe('Bank connection account', () => {
  it('offers a styled certificate button and accepts a certificate without a password', async () => {
    const wrapper = mount(BankConnectionAccount, {
      props: { account: { ...account, bank_code: '0300' }, connection: null, canWrite: true,
        providers: [{ ...provider, code: 'csob', bank_codes: ['0300'] }] },
      global: { stubs: { DateInput: true, RouterLink: true } },
    })
    await wrapper.find('button').trigger('click')
    const help = wrapper.find('[data-testid="connection-help"]')
    expect(help.classes()).toContain('bg-neutral-50')
    expect(help.text()).toContain('bank_connection.csob_help_title')
    expect(help.text()).toContain('bank_connection.csob_hint')
    expect(help.text()).toContain('bank_connection.credentials_storage_hint')
    expect(help.text()).toContain('bank_connection.payment_help_hint')
    const picker = wrapper.findAll('button').find(button => button.text() === 'bank_connection.choose_certificate')!
    expect(picker.classes()).toContain('border')
    expect(picker.find('svg').exists()).toBe(true)
    const input = wrapper.find('input[type="file"]')
    const click = vi.spyOn(input.element as HTMLInputElement, 'click').mockImplementation(() => {})
    await picker.trigger('click')
    expect(click).toHaveBeenCalledOnce()
    await wrapper.find('input[autocomplete="off"]').setValue('10000001')
    Object.defineProperty(input.element, 'files', { value: [{ name: 'synthetic.pfx', size: 3, arrayBuffer: async () => new Uint8Array([1, 2, 3]).buffer }] })
    await input.trigger('change')
    await flushPromises()
    expect(wrapper.text()).toContain('synthetic.pfx')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.save).toHaveBeenCalledExactlyOnceWith(3, { provider: 'csob', enabled: true,
      credentials: { contract_number: '10000001', certificate: 'AQID', password: '' } })
    expect(wrapper.text()).not.toContain('synthetic.pfx')
  })
  it('shows a retained connection row without its token as disconnected', async () => {
    const wrapper = await open({ connection: { ...connection, enabled: false, has_token: false } })
    expect(wrapper.text()).toContain('bank_connection.disconnected')
    expect(wrapper.text()).not.toContain('bank_connection.paused')
  })
  it('sends an empty period for automatic synchronization', async () => {
    const wrapper = await open()
    await wrapper.findAll('form')[1].trigger('submit')
    await flushPromises()
    expect(m.sync).toHaveBeenCalledExactlyOnceWith(3, {})
    expect(wrapper.findAllComponents({ name: 'DateInput' })[1].props('disabled')).toBe(true)
  })
  it('sends both dates for a manually selected period of 31 days', async () => {
    const wrapper = await open()
    const dates = wrapper.findAllComponents({ name: 'DateInput' })
    dates[0].vm.$emit('update:modelValue', '2026-01-01')
    dates[1].vm.$emit('update:modelValue', '2026-01-31')
    await flushPromises()
    await wrapper.findAll('form')[1].trigger('submit')
    await flushPromises()
    expect(m.sync).toHaveBeenCalledExactlyOnceWith(3, { from: '2026-01-01', to: '2026-01-31' })
  })
  it.each(['', '2026-02-01'])('blocks incomplete or overlong manual periods ending at %s', async end => {
    const wrapper = await open()
    const dates = wrapper.findAllComponents({ name: 'DateInput' })
    dates[0].vm.$emit('update:modelValue', '2026-01-01')
    dates[1].vm.$emit('update:modelValue', end)
    await flushPromises()
    await wrapper.findAll('form')[1].trigger('submit')
    await flushPromises()
    expect(m.sync).not.toHaveBeenCalled()
  })
  it('retains an existing token by omitting the field from the request', async () => {
    const wrapper = await open()
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.save).toHaveBeenCalledExactlyOnceWith(3, { provider: 'test', enabled: true })
    expect(wrapper.find('input[type="password"]').element.getAttribute('value')).toBeNull()
  })
  it('requires a token for the first connection and clears it after save', async () => {
    const wrapper = await open({ connection: null })
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
    await wrapper.find('input[type="password"]').setValue('synthetic-test-token')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.save).toHaveBeenCalledExactlyOnceWith(3, { provider: 'test', enabled: true, token: 'synthetic-test-token' })
    expect((wrapper.find('input[type="password"]').element as HTMLInputElement).value).toBe('')
  })
  it('does not render mutation controls for readonly access', async () => {
    const wrapper = await open({ canWrite: false })
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.find('input[type="password"]').exists()).toBe(false)
    expect(m.save).not.toHaveBeenCalled()
  })
  it('clears an entered token when the editor is closed', async () => {
    const wrapper = await open()
    await wrapper.find('input[type="password"]').setValue('synthetic-test-token')
    await wrapper.find('button').trigger('click')
    await wrapper.find('button').trigger('click')
    expect((wrapper.find('input[type="password"]').element as HTMLInputElement).value).toBe('')
  })
  it('shows reconciliation evidence and retries only after an explicit confirmation', async () => {
    vi.useFakeTimers()
    try {
      const candidate = {
        confirmation_key: 'a'.repeat(64), posted_at: '2026-01-12', amount: '1250.00', currency: 'CZK',
        existing_transaction_id: 901, existing_statement_id: 801,
        description: 'Nový syntetický popis', existing_description: 'Dřívější syntetický popis',
        counterparty_account: 'synthetic-new-account', existing_counterparty_account: 'synthetic-old-account',
        variable_symbol: '1001', existing_variable_symbol: '1002',
      }
      m.sync
        .mockRejectedValueOnce({ response: { data: { error: {
          code: 'statement_reconciliation_required', message: 'synthetic',
          reconciliation_candidates: [candidate],
        } } } })
        .mockRejectedValueOnce({ response: { data: { error: {
          code: 'statement_reconciliation_required', message: 'synthetic',
          reconciliation_candidates: [{ ...candidate, confirmation_key: 'b'.repeat(64) }],
        } } } })
        .mockResolvedValueOnce({ status: 'success', import_result: { transactions: 0, matched: 0 }, period: { from: '2026-01-01', to: '2026-01-31' } })
      const wrapper = await open()

      await wrapper.findAll('form')[1].trigger('submit')
      await Promise.resolve()
      await wrapper.vm.$nextTick()

      const panel = wrapper.find('[data-testid="reconciliation-panel"]')
      expect(panel.text()).toContain('Nový syntetický popis')
      expect(panel.text()).toContain('Dřívější syntetický popis')
      expect(panel.text()).toContain('1001 / 1002')
      const confirm = panel.find('[data-testid="confirm-reconciliation"]')
      expect(confirm.attributes('disabled')).toBeDefined()

      vi.advanceTimersByTime(30_000)
      await wrapper.vm.$nextTick()
      expect(confirm.attributes('disabled')).toBeUndefined()
      await confirm.trigger('click')
      await Promise.resolve()
      await wrapper.vm.$nextTick()

      expect(m.sync).toHaveBeenNthCalledWith(1, 3, {})
      expect(m.sync).toHaveBeenNthCalledWith(2, 3, { reconciliation_confirmations: ['a'.repeat(64)] })
      vi.advanceTimersByTime(30_000)
      await wrapper.vm.$nextTick()
      await wrapper.find('[data-testid="confirm-reconciliation"]').trigger('click')
      await Promise.resolve()
      await wrapper.vm.$nextTick()
      expect(m.sync).toHaveBeenNthCalledWith(3, 3, { reconciliation_confirmations: ['a'.repeat(64), 'b'.repeat(64)] })
      expect(wrapper.find('[data-testid="reconciliation-panel"]').exists()).toBe(false)
    } finally {
      vi.useRealTimers()
    }
  })
})
