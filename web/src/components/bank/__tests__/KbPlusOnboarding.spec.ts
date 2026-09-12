import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import KbPlusOnboarding from '../KbPlusOnboarding.vue'
import type { KbPlusOnboardingStatus } from '@/api/kbPlusOnboarding'

const m = vi.hoisted(() => ({ status: vi.fn(), start: vi.fn(), navigate: vi.fn(), demo: vi.fn(), route: { query: {} as Record<string, string> } }))
vi.mock('@/api/kbPlusOnboarding', () => ({
  kbPlusOnboardingApi: { status: m.status, start: m.start },
  kbPlusCredentialFields: ['client_registration_api_key', 'oauth_api_key', 'adaa_api_key', 'batchda_api_key', 'certificate_p12', 'certificate_password'],
}))
vi.mock('@/utils/kbPlusOnboarding', async importOriginal => ({ ...await importOriginal<typeof import('@/utils/kbPlusOnboarding')>(), navigateToKbPlus: m.navigate }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: m.demo }) }))
vi.mock('@/composables/useFormat', () => ({ formatDateTime: (value: string) => value }))
vi.mock('vue-router', () => ({ useRoute: () => m.route }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

const registration: KbPlusOnboardingStatus = {
  provider: 'kb_plus', status: 'not_registered', server_ready: true, blockers: [],
  required_fields: ['client_registration_api_key', 'oauth_api_key', 'adaa_api_key', 'batchda_api_key', 'certificate_p12', 'certificate_password'],
  registration_fields: ['client_registration_api_key', 'oauth_api_key', 'adaa_api_key', 'batchda_api_key', 'certificate_p12', 'certificate_password'],
  optional_fields: ['batchda_api_key', 'certificate_password'],
  capabilities: { statement_import: true, payment_batch_submission: false, payment_batch_status: 'not_registered' },
}
async function open(status: Partial<KbPlusOnboardingStatus> = {}, canWrite = true) {
  m.status.mockResolvedValue({ ...registration, ...status })
  const wrapper = mount(KbPlusOnboarding, { props: { currencyId: 3, canWrite } })
  await flushPromises()
  return wrapper
}
async function fill(wrapper: VueWrapper, keys = ['client_registration_api_key', 'oauth_api_key', 'adaa_api_key', 'batchda_api_key']) {
  for (const key of keys) {
    await wrapper.find(`input[name="${key}"]`).setValue(`synthetic-${key}`)
  }
  const input = wrapper.find('input[type="file"]')
  Object.defineProperty(input.element, 'files', { configurable: true, value: [{ name: 'synthetic.p12', size: 3, arrayBuffer: async () => new Uint8Array([1, 2, 3]).buffer }] })
  await input.trigger('change')
  await flushPromises()
}
beforeEach(() => {
  vi.clearAllMocks()
  m.demo.mockReturnValue(false)
  m.navigate.mockReturnValue(true)
  m.route.query = {}
  m.start.mockResolvedValue({ status: 'registration_pending', redirect_url: 'https://api-gateway.kb.cz/client-registration-ui/v2/saml/register?request=synthetic', expires_at: '2026-09-07T12:00:00Z' })
})

describe('KB+ onboarding', () => {
  it('shows prerequisites and a styled certificate picker with an icon', async () => {
    const wrapper = await open()
    expect(m.status).toHaveBeenCalledExactlyOnceWith(3)
    expect(wrapper.text()).toContain('kb_plus.prerequisite_subscriptions')
    expect(wrapper.text()).toContain('kb_plus.prerequisite_bank')
    expect(wrapper.text()).toContain('kb_plus.prerequisite_plan')
    expect(wrapper.text()).toContain('kb_plus.prerequisite_activation')
    expect(wrapper.find('a[href="https://www.kb.cz/cs/kbapi/extra-sluzba-api-business"]').exists()).toBe(true)
    expect(wrapper.find('a[href="https://www.kb.cz/cs/kbapi/caste-dotazy-rozcestnik/caste-dotazy-extra-sluzba-api-business"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('kb_plus.prerequisite_certificate')
    const button = wrapper.findAll('button').find(item => item.text() === 'kb_plus.choose_certificate')!
    expect(button.classes()).toContain('border')
    expect(button.find('svg').exists()).toBe(true)
    const click = vi.spyOn(wrapper.find('input[type="file"]').element as HTMLInputElement, 'click').mockImplementation(() => {})
    await button.trigger('click')
    expect(click).toHaveBeenCalledOnce()
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
  })
  it('sends all subscription keys and certificate only to POST then immediately redirects', async () => {
    const storage = vi.spyOn(Storage.prototype, 'setItem')
    const wrapper = await open()
    await fill(wrapper)
    expect(wrapper.text()).toContain('synthetic.p12')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.start).toHaveBeenCalledExactlyOnceWith(3, {
      client_registration_api_key: 'synthetic-client_registration_api_key', oauth_api_key: 'synthetic-oauth_api_key',
      adaa_api_key: 'synthetic-adaa_api_key', batchda_api_key: 'synthetic-batchda_api_key', certificate_p12: 'AQID', certificate_password: '',
      payment_batches: false,
    })
    expect(m.navigate).toHaveBeenCalledExactlyOnceWith('https://api-gateway.kb.cz/client-registration-ui/v2/saml/register?request=synthetic')
    expect(wrapper.find('input[type="password"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('synthetic.p12')
    expect(storage).not.toHaveBeenCalled()
    storage.mockRestore()
  })
  it('shows explicit server blockers and never renders a registration form', async () => {
    const wrapper = await open({ server_ready: false, blockers: ['app_url_missing', 'callback_query_redaction_required'] })
    expect(wrapper.text()).toContain('kb_plus.error_app_url')
    expect(wrapper.text()).toContain('kb_plus.error_callback_redaction')
    expect(wrapper.find('form').exists()).toBe(false)
    expect(m.start).not.toHaveBeenCalled()
  })
  it('does not offer writes to readonly users', async () => {
    const wrapper = await open({}, false)
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.find('input').exists()).toBe(false)
    expect(m.start).not.toHaveBeenCalled()
  })
  it('uses an empty body for an already registered application', async () => {
    const wrapper = await open({ required_fields: [] })
    expect(wrapper.text()).toContain('kb_plus.existing_client_hint')
    expect(wrapper.find('input[type="password"]').exists()).toBe(false)
    expect(wrapper.find('input[name="payment_batches"]').exists()).toBe(false)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.start).toHaveBeenCalledExactlyOnceWith(3, {})
  })
  it('requires confirmation before invalidating a pending registration', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(false)
    const wrapper = await open({ status: 'registration_pending', required_fields: [] })
    await wrapper.find('form').trigger('submit')
    expect(confirm).toHaveBeenCalledExactlyOnceWith('kb_plus.restart_confirm')
    expect(m.start).not.toHaveBeenCalled()
    confirm.mockRestore()
  })
  it('clears secrets and renders only a safe error on failure', async () => {
    m.start.mockRejectedValue({ response: { data: { error: { code: 'kb_plus_registration_invalid', message: 'unsafe-secret-from-provider' } } } })
    const wrapper = await open()
    await fill(wrapper)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('kb_plus.error_registration_invalid')
    expect(wrapper.text()).not.toContain('unsafe-secret-from-provider')
    expect(wrapper.text()).not.toContain('synthetic.p12')
    for (const input of wrapper.findAll('input[type="password"]')) expect((input.element as HTMLInputElement).value).toBe('')
    expect(m.navigate).not.toHaveBeenCalled()
  })
  it('never opens an unsafe redirect and blocks another automatic submission', async () => {
    m.navigate.mockReturnValue(false)
    const wrapper = await open({ required_fields: [] })
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('kb_plus.error_redirect')
    expect(wrapper.text()).not.toContain('kb_plus.redirecting')
    expect(wrapper.find('form').exists()).toBe(false)
  })
  it('requires a server-confirmed connection rather than trusting the return query', async () => {
    m.route.query = { kb_plus: 'connected', currency_id: '3' }
    const wrapper = await open({ status: 'authorization_pending', required_fields: [] })
    expect(wrapper.text()).not.toContain('kb_plus.connected')
    expect(wrapper.text()).toContain('kb_plus.status_authorization_pending')
  })
  it('does not show callback errors for another account', async () => {
    m.route.query = { kb_plus: 'error', currency_id: '4', code: 'kb_plus_account_not_found' }
    const wrapper = await open()
    expect(wrapper.text()).not.toContain('kb_plus.error_account_not_found')
  })
  it('ignores a completed request after switching accounts', async () => {
    let resolve!: (value: unknown) => void
    m.start.mockReturnValue(new Promise(done => { resolve = done }))
    const wrapper = await open({ required_fields: [] })
    await wrapper.find('form').trigger('submit')
    await wrapper.setProps({ currencyId: 4 })
    await flushPromises()
    resolve({ redirect_url: 'https://login.kb.cz/autfe/ssologin?state=synthetic' })
    await flushPromises()
    expect(m.navigate).not.toHaveBeenCalled()
    expect(m.status).toHaveBeenLastCalledWith(4)
  })
  it('rejects oversized certificates before reading them', async () => {
    const wrapper = await open()
    const read = vi.fn()
    const input = wrapper.find('input[type="file"]')
    Object.defineProperty(input.element, 'files', { value: [{ name: 'synthetic.p12', size: 24577, arrayBuffer: read }] })
    await input.trigger('change')
    expect(read).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('kb_plus.error_certificate')
  })
  it('refreshes status and tells the account list to refresh its connection', async () => {
    const wrapper = await open({ status: 'connected', required_fields: [] })
    const button = wrapper.findAll('button').find(item => item.text() === 'kb_plus.refresh')!
    await button.trigger('click')
    await flushPromises()
    expect(wrapper.emitted('changed')).toHaveLength(1)
    expect(m.status).toHaveBeenCalledTimes(2)
  })
  it('lets the BatchDA key stay empty for read-only access', async () => {
    const wrapper = await open()
    await fill(wrapper, ['client_registration_api_key', 'oauth_api_key', 'adaa_api_key'])
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeUndefined()
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.start).toHaveBeenCalledExactlyOnceWith(3, {
      client_registration_api_key: 'synthetic-client_registration_api_key', oauth_api_key: 'synthetic-oauth_api_key',
      adaa_api_key: 'synthetic-adaa_api_key', batchda_api_key: '', certificate_p12: 'AQID', certificate_password: '',
      payment_batches: false,
    })
  })
  it('requests payment batches without a separate BatchDA key', async () => {
    const wrapper = await open()
    await fill(wrapper, ['client_registration_api_key', 'oauth_api_key', 'adaa_api_key'])
    const checkbox = wrapper.find('input[name="payment_batches"]')
    expect((checkbox.element as HTMLInputElement).checked).toBe(false)
    expect(wrapper.text()).toContain('kb_plus.payment_batches')
    await checkbox.setValue(true)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(m.start).toHaveBeenCalledExactlyOnceWith(3, {
      client_registration_api_key: 'synthetic-client_registration_api_key', oauth_api_key: 'synthetic-oauth_api_key',
      adaa_api_key: 'synthetic-adaa_api_key', batchda_api_key: '', certificate_p12: 'AQID', certificate_password: '',
      payment_batches: true,
    })
  })
  it('explains a read-only registration and preselects batches when keys are re-entered', async () => {
    const wrapper = await open({ status: 'connected', required_fields: [], capabilities: { statement_import: true, payment_batch_submission: false, payment_batch_status: 'registration_scope_missing' } })
    expect(wrapper.text()).toContain('kb_plus.batch_unavailable_registration')
    expect(wrapper.find('input[name="batchda_api_key"]').exists()).toBe(false)
    await wrapper.findAll('button').find(item => item.text() === 'kb_plus.reenter_keys')!.trigger('click')
    expect(wrapper.find('input[name="batchda_api_key"]').exists()).toBe(true)
    expect(wrapper.find('input[type="file"]').exists()).toBe(true)
    expect((wrapper.find('input[name="payment_batches"]').element as HTMLInputElement).checked).toBe(true)
  })
  it('asks only for a new consent when the registration already has bpisp', async () => {
    const wrapper = await open({ status: 'connected', required_fields: [], capabilities: { statement_import: true, payment_batch_submission: false, payment_batch_status: 'authorization_scope_missing' } })
    expect(wrapper.text()).toContain('kb_plus.batch_unavailable_authorization')
    expect(wrapper.text()).not.toContain('kb_plus.batch_unavailable_registration')
    expect(wrapper.find('button[type="submit"]').text()).toBe('kb_plus.restart')
  })
  it('does not claim a reason it cannot verify', async () => {
    const wrapper = await open({ status: 'connected', required_fields: [], capabilities: { statement_import: true, payment_batch_submission: false, payment_batch_status: 'unknown' } })
    expect(wrapper.text()).toContain('kb_plus.batch_unavailable')
    expect(wrapper.text()).not.toContain('kb_plus.batch_unavailable_registration')
    expect(wrapper.text()).not.toContain('kb_plus.batch_unavailable_authorization')
  })
  it('hides the batch warning once the connection can submit batches', async () => {
    const wrapper = await open({ status: 'connected', required_fields: [], capabilities: { statement_import: true, payment_batch_submission: true, payment_batch_status: 'available' } })
    expect(wrapper.text()).not.toContain('kb_plus.batch_unavailable')
  })
})
