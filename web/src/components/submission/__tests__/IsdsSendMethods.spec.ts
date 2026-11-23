import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia } from 'pinia'
import { useSupplierStore } from '@/stores/supplier'

const m = vi.hoisted(() => ({
  gatewayCapabilities: vi.fn(),
  credentials: vi.fn(),
  choices: vi.fn(),
  remember: vi.fn(),
  forget: vi.fn(),
  gatewayStart: vi.fn(),
  passwordSend: vi.fn(),
  smsSendStart: vi.fn(),
  smsSendComplete: vi.fn(),
  certificateSend: vi.fn(),
}))

vi.mock('@/api/dataBox', () => ({ dataBoxApi: m }))
vi.mock('@/api/isdsAccess', () => ({ isdsAccessApi: m }))
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (_error: unknown, fallback: string) => fallback,
}))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      params ? `${key}|${JSON.stringify(params)}` : key,
  }),
}))

import IsdsSendMethods from '../IsdsSendMethods.vue'

const MobileKeyStub = {
  name: 'MobileKeySendButton',
  props: { outboxId: Number, environment: String, autoOpen: Boolean },
  emits: ['sent'],
  template: '<div data-test="mobile-key-stub" />',
}

function mountMethods(props: Record<string, unknown> = {}, pinia = createPinia()) {
  return mount(IsdsSendMethods, {
    props: { outboxId: 42, environment: 'production', ...props },
    global: { plugins: [pinia], stubs: { MobileKeySendButton: MobileKeyStub } },
  })
}

async function choose(wrapper: ReturnType<typeof mountMethods>, method: string) {
  await wrapper.get(`[data-test="isds-send-method-${method}"] input`).setValue(true)
  await wrapper.get(`[data-test="isds-send-method-${method}"] input`).trigger('change')
}

beforeEach(() => {
  vi.clearAllMocks()
  m.gatewayCapabilities.mockResolvedValue([])
  m.credentials.mockResolvedValue([])
  m.choices.mockResolvedValue([])
})

describe('IsdsSendMethods', () => {
  it('nabídne všech pět cest a nedostupnou bránu a certifikát vysvětlí', async () => {
    const wrapper = mountMethods()
    await flushPromises()

    for (const method of ['gateway', 'mobile_key', 'password', 'sms', 'certificate']) {
      expect(wrapper.find(`[data-test="isds-send-method-${method}"]`).exists()).toBe(true)
    }
    expect(wrapper.get('[data-test="isds-send-method-gateway"] input').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="isds-send-unavailable-gateway"]').text()).toBe('databox.send.gateway.unavailable')
    expect(wrapper.get('[data-test="isds-send-method-certificate"] input').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-test="isds-send-access-link"]').attributes('href')).toBe('/admin/databox?tab=access')
    // Bez brány se doporučí a předvybere Mobilní klíč s formulářem rovnou otevřeným.
    expect(wrapper.getComponent(MobileKeyStub).props('autoOpen')).toBe(true)
    expect(wrapper.getComponent(MobileKeyStub).props('outboxId')).toBe(42)
  })

  it('zaregistrovanou bránu doporučí a spustí funkcí volajícího', async () => {
    m.gatewayCapabilities.mockResolvedValue([{ environment: 'production', available: true }])
    const gatewayStart = vi.fn().mockResolvedValue({
      session_id: 1,
      app_token: 'x',
      redirect_url: 'https://example.invalid/isds',
      login_guidance: 'Přihlaste se v ISDS.',
      login_policy_documented: false,
      expires_at: '2026-10-12T10:00:00Z',
      resumed: false,
    })
    const assign = vi.fn()
    Object.defineProperty(window, 'location', { configurable: true, value: { assign } })
    const wrapper = mountMethods({ gatewayStart })
    await flushPromises()

    expect(wrapper.find('[data-test="isds-send-panel-gateway"]').exists()).toBe(true)
    await wrapper.get('[data-test="isds-send-gateway-start"]').trigger('click')
    await flushPromises()

    expect(gatewayStart).toHaveBeenCalledWith(42)
    expect(m.gatewayStart).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Přihlaste se v ISDS.')
    expect(wrapper.text()).toContain('databox.gateway.methodsByIsds')
    await wrapper.get('[data-test="isds-send-gateway-continue"]').trigger('click')
    expect(assign).toHaveBeenCalledWith('https://example.invalid/isds')
  })

  it('automatický transport ze zařazení bránu zpřístupní i bez výpisu registrací', async () => {
    m.gatewayCapabilities.mockRejectedValue({ response: { status: 403 } })
    const wrapper = mountMethods({ transport: { automatic: true, channel: 'gateway', reason: null } })
    await flushPromises()

    expect(wrapper.get('[data-test="isds-send-method-gateway"] input').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-test="isds-send-panel-gateway"]').exists()).toBe(true)
  })

  it('jménem a heslem odešle, heslo zahodí a ohlásí odeslání', async () => {
    m.passwordSend.mockResolvedValue({ row: { id: 42 }, dispatched: true })
    const wrapper = mountMethods({ environment: 'test' })
    await flushPromises()

    expect(wrapper.find('[data-test="isds-send-test-warning"]').exists()).toBe(true)
    await choose(wrapper, 'password')
    await wrapper.get('[data-test="isds-send-username"]').setValue('user1')
    await wrapper.get('[data-test="isds-send-password"]').setValue('secret')
    await wrapper.get('[data-test="isds-send-password-submit"]').trigger('click')
    await flushPromises()

    expect(m.passwordSend).toHaveBeenCalledWith(42, 'test', { username: 'user1', password: 'secret' })
    expect(m.remember).not.toHaveBeenCalled()
    expect((wrapper.get('[data-test="isds-send-password"]').element as HTMLInputElement).value).toBe('')
    expect(wrapper.emitted('sent')).toEqual([[true]])
  })

  it('sdílený přístup odešle pouze po potvrzení a pouze pod identifikátorem', async () => {
    m.choices.mockResolvedValue([{ id: 83, label: 'Kancelář', box_id: 'abcdefg', scope: 'shared', method: 'certificate' }])
    m.passwordSend.mockResolvedValue({ row: { id: 42 }, dispatched: true })
    const wrapper = mountMethods()
    await flushPromises()

    await choose(wrapper, 'saved')
    const submit = wrapper.get('[data-test="isds-send-saved-submit"]')
    expect(submit.attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="isds-saved-access-select"]').setValue('83')
    expect(submit.attributes('disabled')).toBeDefined()
    expect(wrapper.find('input[type="password"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="isds-saved-access-forget"]').exists()).toBe(false)
    expect(m.passwordSend).not.toHaveBeenCalled()

    await wrapper.get('[data-test="isds-saved-access-confirm"]').setValue(true)
    await submit.trigger('click')
    await flushPromises()

    expect(m.passwordSend).toHaveBeenCalledWith(42, 'production', { access_id: 83, authorized: true })
    expect(wrapper.emitted('sent')).toEqual([[true]])
  })

  it('na výslovné přání zapamatuje zadané heslo a odešle přes uložený přístup', async () => {
    m.remember.mockResolvedValue({ id: 84, label: 'Moje schránka', box_id: 'abcdefg', scope: 'personal', method: 'password' })
    m.passwordSend.mockResolvedValue({ row: { id: 42 }, dispatched: true })
    const wrapper = mountMethods({ environment: 'test' })
    await flushPromises()

    await choose(wrapper, 'password')
    await wrapper.get('[data-test="isds-send-username"]').setValue('user1')
    await wrapper.get('[data-test="isds-send-password"]').setValue('secret')
    await wrapper.get('[data-test="isds-remember-access"]').setValue(true)
    await wrapper.get('[data-test="isds-send-password-submit"]').trigger('click')
    await flushPromises()

    expect(m.remember).toHaveBeenCalledWith('test', 'send', 'user1', 'secret')
    expect(m.passwordSend).toHaveBeenCalledWith(42, 'test', { access_id: 84, authorized: true })
    expect((wrapper.get('[data-test="isds-send-password"]').element as HTMLInputElement).value).toBe('')
  })

  it('po změně prostředí zahodí potvrzení i rozpracované zapamatování', async () => {
    let finishRemember!: (saved: { id: number; label: string; box_id: string; scope: string; method: string }) => void
    m.remember.mockReturnValue(new Promise(resolve => { finishRemember = resolve }))
    const wrapper = mountMethods()
    await flushPromises()

    await choose(wrapper, 'password')
    await wrapper.get('[data-test="isds-send-username"]').setValue('user1')
    await wrapper.get('[data-test="isds-send-password"]').setValue('secret')
    await wrapper.get('[data-test="isds-remember-access"]').setValue(true)
    await wrapper.get('[data-test="isds-send-password-submit"]').trigger('click')
    expect(m.remember).toHaveBeenCalled()
    await wrapper.setProps({ environment: 'test' })
    finishRemember({ id: 84, label: 'Moje schránka', box_id: 'abcdefg', scope: 'personal', method: 'password' })
    await flushPromises()

    expect(m.passwordSend).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="isds-send-method-saved"]').exists()).toBe(false)
    await choose(wrapper, 'password')
    expect((wrapper.get('[data-test="isds-send-password"]').element as HTMLInputElement).value).toBe('')
    expect((wrapper.get('[data-test="isds-remember-access"]').element as HTMLInputElement).checked).toBe(false)
  })

  it('při změně firmy odstraní původní uložený přístup i potvrzení', async () => {
    m.choices
      .mockResolvedValueOnce([{ id: 83, label: 'První firma', box_id: 'abcdefg', scope: 'shared', method: 'password' }])
      .mockResolvedValueOnce([{ id: 85, label: 'Druhá firma', box_id: 'hijklmn', scope: 'shared', method: 'password' }])
    const pinia = createPinia()
    const supplier = useSupplierStore(pinia)
    supplier.setSupplier(1)
    const wrapper = mountMethods({}, pinia)
    await flushPromises()

    await choose(wrapper, 'saved')
    await wrapper.get('[data-test="isds-saved-access-select"]').setValue('83')
    await wrapper.get('[data-test="isds-saved-access-confirm"]').setValue(true)
    supplier.setSupplier(2)
    await flushPromises()

    await choose(wrapper, 'saved')
    const choices = wrapper.get('[data-test="isds-saved-access-select"]')
    expect(choices.find('option[value="83"]').exists()).toBe(false)
    expect(choices.find('option[value="85"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="isds-send-saved-submit"]').attributes('disabled')).toBeDefined()
    expect(m.passwordSend).not.toHaveBeenCalled()
  })

  it('osobní uložený přístup lze zapomenout po potvrzení', async () => {
    m.choices.mockResolvedValueOnce([{ id: 84, label: 'Moje schránka', box_id: 'abcdefg', scope: 'personal', method: 'password' }])
    m.forget.mockResolvedValue(undefined)
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    try {
      const wrapper = mountMethods()
      await flushPromises()
      await choose(wrapper, 'saved')
      await wrapper.get('[data-test="isds-saved-access-select"]').setValue('84')
      const forget = wrapper.get('[data-test="isds-saved-access-forget"]')
      await forget.trigger('click')
      expect(m.forget).not.toHaveBeenCalled()
      confirm.mockReturnValue(true)
      await forget.trigger('click')
      await flushPromises()

      expect(m.forget).toHaveBeenCalledWith(84)
      expect(wrapper.find('[data-test="isds-saved-access-forget"]').exists()).toBe(false)
      expect(wrapper.get('[data-test="isds-send-saved-submit"]').attributes('disabled')).toBeDefined()
    } finally {
      confirm.mockRestore()
    }
  })

  it('403 vysvětlí chybějící oprávnění a odkáže do odchozích podání', async () => {
    m.passwordSend.mockRejectedValue({ response: { status: 403 } })
    const wrapper = mountMethods()
    await flushPromises()

    await choose(wrapper, 'password')
    await wrapper.get('[data-test="isds-send-username"]').setValue('user1')
    await wrapper.get('[data-test="isds-send-password"]').setValue('secret')
    await wrapper.get('[data-test="isds-send-password-submit"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="isds-send-error"]').text()).toContain('databox.send.forbidden')
    expect(wrapper.get('[data-test="isds-send-open-outbox"]').attributes('href'))
      .toBe('/admin/databox?tab=outbox&outbox=42')
    expect(wrapper.emitted('sent')).toBeUndefined()
  })

  it('SMS kódem: nejdřív vyžádá kód, pak ho ověří a zprávu odešle', async () => {
    m.smsSendStart.mockResolvedValue({ flow_token: 'flow-1', description: 'Kód odešel.', expires_at: '2026-10-12T10:00:00Z' })
    m.smsSendComplete.mockResolvedValue({ row: { id: 42 }, dispatched: true })
    const wrapper = mountMethods()
    await flushPromises()

    await choose(wrapper, 'sms')
    await wrapper.get('[data-test="isds-send-username"]').setValue('user1')
    await wrapper.get('[data-test="isds-send-password"]').setValue('secret')
    await wrapper.get('[data-test="isds-send-sms-request"]').trigger('click')
    await flushPromises()

    expect(m.smsSendStart).toHaveBeenCalledWith(42, 'production', 'user1', 'secret')
    expect(wrapper.find('[data-test="isds-send-password"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="isds-send-sms-status"]').text()).toBe('Kód odešel.')

    await wrapper.get('[data-test="isds-send-sms-code"]').setValue('123456')
    await wrapper.get('[data-test="isds-send-sms-confirm"]').trigger('click')
    await flushPromises()

    expect(m.smsSendComplete).toHaveBeenCalledWith(42, 'production', 'flow-1', '123456')
    expect(wrapper.emitted('sent')).toEqual([[true]])
  })

  it('certifikátem odešle až po vědomém potvrzení, když ho firma má', async () => {
    m.credentials.mockResolvedValue([{ environment: 'production', credential_missing: false }])
    m.certificateSend.mockResolvedValue({ row: { id: 42 }, dispatched: false })
    const wrapper = mountMethods()
    await flushPromises()

    await choose(wrapper, 'certificate')
    expect(wrapper.get('[data-test="isds-send-certificate-submit"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="isds-send-certificate-confirm"]').setValue(true)
    await wrapper.get('[data-test="isds-send-certificate-submit"]').trigger('click')
    await flushPromises()

    expect(m.certificateSend).toHaveBeenCalledWith(42, 'production')
    expect(wrapper.emitted('sent')).toEqual([[false]])
    expect(wrapper.get('[data-test="isds-send-error"]').text()).toBe('databox.send.notDispatched')
  })
})

it('klientská daňová brána nenačítá nastavení ani nenabízí obecné odesílání', async () => {
  const wrapper = mountMethods({ gatewayOnly: true, transport: { automatic: true, channel: 'gateway' } })
  await flushPromises()
  expect(m.choices).not.toHaveBeenCalled()
  expect(m.gatewayCapabilities).not.toHaveBeenCalled()
  expect(m.credentials).not.toHaveBeenCalled()
  expect(wrapper.find('[data-test="isds-send-method-gateway"]').exists()).toBe(true)
  for (const method of ['mobile_key', 'password', 'sms', 'certificate']) {
    expect(wrapper.find(`[data-test="isds-send-method-${method}"]`).exists()).toBe(false)
  }
  expect(wrapper.find('a[href^="/admin/databox"]').exists()).toBe(false)
})
