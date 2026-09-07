import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import CreditasConnectionSetup from '../CreditasConnectionSetup.vue'
import type { BankConnection } from '@/api/bankConnections'

const m = vi.hoisted(() => ({ save: vi.fn(), disconnect: vi.fn(), demo: vi.fn() }))
vi.mock('@/api/bankConnections', () => ({ bankConnectionsApi: m }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: m.demo }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

const stored: BankConnection = {
  id: 4, currency_id: 3, provider: 'creditas', enabled: true, has_token: true, validated_at: '2026-09-07',
  last_sync_at: null, last_sync_status: null, last_sync_error_code: null, next_sync_from: null,
  created_at: '2026-09-07', updated_at: '2026-09-07',
  account: { id: 3, code: 'CZK', label: 'Synthetic', account_number: '1000000005', bank_code: '2250', iban: null },
}
const syntheticToken = 'A1'.repeat(32)
function open(connection: BankConnection | null = null, canWrite = true) {
  return mount(CreditasConnectionSetup, { props: { currencyId: 3, connection, canWrite } })
}
async function fill(wrapper: VueWrapper) {
  await wrapper.find('input[name="bearer_token"]').setValue(syntheticToken)
  await wrapper.find('input[name="account_id"]').setValue('synthetic-account-1')
  const input = wrapper.find('input[type="file"]')
  Object.defineProperty(input.element, 'files', { configurable: true, value: [{ name: 'synthetic.p12', size: 3, arrayBuffer: async () => new Uint8Array([1, 2, 3]).buffer }] })
  await input.trigger('change')
  await flushPromises()
}
beforeEach(() => {
  vi.clearAllMocks()
  m.demo.mockReturnValue(false)
  m.save.mockResolvedValue(stored)
  m.disconnect.mockResolvedValue(undefined)
})

describe('CREDITAS connection setup', () => {
  it('explains optional mTLS and uses a styled file picker', async () => {
    const wrapper = open()
    expect(wrapper.text()).toContain('creditas_bank.mtls_hint')
    expect(wrapper.text()).toContain('creditas_bank.account_hint')
    const button = wrapper.findAll('button').find(item => item.text() === 'creditas_bank.choose_certificate')!
    expect(button.classes()).toContain('border')
    expect(button.find('svg').exists()).toBe(true)
    const click = vi.spyOn(wrapper.find('input[type="file"]').element as HTMLInputElement, 'click').mockImplementation(() => {})
    await button.trigger('click')
    expect(click).toHaveBeenCalledOnce()
  })
  it('accepts a token and account ID without a client certificate', async () => {
    const wrapper = open()
    await wrapper.find('input[name="bearer_token"]').setValue(syntheticToken)
    await wrapper.find('input[name="account_id"]').setValue('synthetic-account-1')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.save).toHaveBeenCalledExactlyOnceWith(3, { provider: 'creditas', enabled: true, credentials: {
      bearer_token: syntheticToken, account_id: 'synthetic-account-1', account_type: 'current',
    } })
    expect(wrapper.find('input[name="certificate_password"]').exists()).toBe(false)
  })
  it('sends all credentials and selected savings type, allowing an unprotected PKCS12', async () => {
    const storage = vi.spyOn(Storage.prototype, 'setItem')
    const wrapper = open()
    await fill(wrapper)
    await wrapper.find('select').setValue('savings')
    expect(wrapper.text()).toContain('synthetic.p12')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.save).toHaveBeenCalledExactlyOnceWith(3, { provider: 'creditas', enabled: true, credentials: {
      bearer_token: syntheticToken, account_id: 'synthetic-account-1', account_type: 'savings', certificate: 'AQID', password: '',
    } })
    expect(wrapper.text()).not.toContain('synthetic.p12')
    expect((wrapper.find('input[name="bearer_token"]').element as HTMLInputElement).value).toBe('')
    expect(wrapper.emitted('changed')).toHaveLength(1)
    expect(wrapper.emitted('busy-change')).toEqual([[true], [false]])
    expect(storage).not.toHaveBeenCalled()
    storage.mockRestore()
  })
  it.each(['A'.repeat(63), 'A'.repeat(63) + '-'])('blocks an invalid bearer token', async token => {
    const wrapper = open()
    await fill(wrapper)
    await wrapper.find('input[name="bearer_token"]').setValue(token)
    await wrapper.find('form').trigger('submit')
    expect(m.save).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('creditas_bank.invalid_token')
  })
  it('includes the optional certificate password only when a certificate is selected', async () => {
    const wrapper = open()
    expect(wrapper.find('input[name="certificate_password"]').exists()).toBe(false)
    await fill(wrapper)
    await wrapper.find('input[name="certificate_password"]').setValue('synthetic-password')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.save).toHaveBeenCalledExactlyOnceWith(3, { provider: 'creditas', enabled: true, credentials: {
      bearer_token: syntheticToken, account_id: 'synthetic-account-1', account_type: 'current', certificate: 'AQID', password: 'synthetic-password',
    } })
  })
  it.each(['synthetic/id', 'A'.repeat(41)])('blocks malformed account IDs', async accountId => {
    const wrapper = open()
    await fill(wrapper)
    await wrapper.find('input[name="account_id"]').setValue(accountId)
    await wrapper.find('form').trigger('submit')
    expect(m.save).not.toHaveBeenCalled()
  })
  it('retains stored credentials by omitting the entire credentials field', async () => {
    const wrapper = open(stored)
    expect(wrapper.find('input[type="password"]').exists()).toBe(false)
    await wrapper.find('input[name="enabled"]').setValue(false)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.save).toHaveBeenCalledExactlyOnceWith(3, { provider: 'creditas', enabled: false })
  })
  it('requires a complete replacement and can cancel it without changing stored secrets', async () => {
    const wrapper = open(stored)
    await wrapper.findAll('button').find(item => item.text() === 'creditas_bank.replace')!.trigger('click')
    await wrapper.find('input[name="bearer_token"]').setValue(syntheticToken)
    await wrapper.find('form').trigger('submit')
    expect(m.save).not.toHaveBeenCalled()
    await wrapper.findAll('button').find(item => item.text() === 'creditas_bank.cancel')!.trigger('click')
    expect(wrapper.find('input[name="bearer_token"]').exists()).toBe(false)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.save).toHaveBeenCalledExactlyOnceWith(3, { provider: 'creditas', enabled: true })
  })
  it('does not reuse a token belonging to another provider', async () => {
    const wrapper = open({ ...stored, provider: 'fio' })
    await wrapper.find('form').trigger('submit')
    expect(m.save).not.toHaveBeenCalled()
    expect(wrapper.find('input[name="bearer_token"]').exists()).toBe(true)
  })
  it('has no write controls for readonly users', () => {
    const wrapper = open(stored, false)
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.find('input').exists()).toBe(false)
  })
  it('respects demo mode and parent operation locks', async () => {
    const wrapper = open(stored)
    m.demo.mockReturnValue(true)
    await wrapper.find('form').trigger('submit')
    expect(m.save).not.toHaveBeenCalled()
    m.demo.mockReturnValue(false)
    await wrapper.setProps({ disabled: true })
    await wrapper.find('form').trigger('submit')
    expect(m.save).not.toHaveBeenCalled()
  })
  it('rejects oversized certificates without reading their bytes', async () => {
    const wrapper = open()
    await wrapper.find('input[name="bearer_token"]').setValue(syntheticToken)
    await wrapper.find('input[name="account_id"]').setValue('synthetic-account-1')
    const read = vi.fn()
    const input = wrapper.find('input[type="file"]')
    Object.defineProperty(input.element, 'files', { value: [{ name: 'synthetic.p12', size: 24577, arrayBuffer: read }] })
    await input.trigger('change')
    expect(read).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('creditas_bank.error_certificate')
    await wrapper.find('form').trigger('submit')
    expect(m.save).not.toHaveBeenCalled()
  })
  it('never shows raw provider error messages containing secrets', async () => {
    const wrapper = open(stored)
    m.save.mockRejectedValue({ response: { data: { error: { code: 'creditas_account_mismatch', message: 'unsafe-provider-token' } } } })
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('creditas_bank.error_account')
    expect(wrapper.text()).not.toContain('unsafe-provider-token')
  })
  it('explains a token rejected by the bank without requiring a certificate', async () => {
    const wrapper = open(stored)
    m.save.mockRejectedValue({ response: { data: { error: { code: 'invalid_token', message: 'unsafe-secret' } } } })
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('creditas_bank.error_token_rejected')
    expect(wrapper.text()).not.toContain('unsafe-secret')
  })
  it('requires confirmation before disconnecting and preserves the account scope', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const wrapper = open(stored)
    const button = wrapper.findAll('button').find(item => item.text() === 'creditas_bank.disconnect')!
    await button.trigger('click')
    expect(m.disconnect).not.toHaveBeenCalled()
    confirm.mockReturnValue(true)
    await button.trigger('click')
    await flushPromises()
    expect(m.disconnect).toHaveBeenCalledExactlyOnceWith(3)
    expect(wrapper.emitted('changed')).toHaveLength(1)
    confirm.mockRestore()
  })
  it('ignores old save completion after switching the account', async () => {
    let resolve!: (value: unknown) => void
    m.save.mockReturnValue(new Promise(done => { resolve = done }))
    const wrapper = open(stored)
    await wrapper.find('form').trigger('submit')
    await wrapper.setProps({ currencyId: 8, connection: null })
    resolve(stored)
    await flushPromises()
    expect(wrapper.emitted('changed')).toBeUndefined()
    expect(wrapper.text()).not.toContain('creditas_bank.saved')
  })
})
