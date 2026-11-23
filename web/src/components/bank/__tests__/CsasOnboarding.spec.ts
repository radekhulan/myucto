import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import CsasOnboarding from '../CsasOnboarding.vue'

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), route: { query: {} as Record<string, string> } }))
vi.mock('@/api/client', () => ({ api: { get: mocks.get, post: mocks.post } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ useRoute: () => mocks.route }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
beforeEach(() => {
  vi.clearAllMocks()
  mocks.route.query = {}
  mocks.get.mockResolvedValue({ data: { server_ready: true, callback_url: 'https://example.invalid/callback' } })
})
describe('CSAS consent setup', () => {
  it('shows sandbox instructions and rejects production authorization in sandbox mode', async () => {
    mocks.get.mockResolvedValue({ data: { server_ready: true, callback_url: 'https://example.invalid/callback', environment: 'sandbox' } })
    mocks.post.mockResolvedValue({ data: { redirect_url: 'https://bezpecnost.csas.cz/api/psd2/fl/oidc/v1/auth?state=synthetic' } })
    const wrapper = mount(CsasOnboarding, { props: { currencyId: 3, canWrite: true } })
    await flushPromises()
    expect(wrapper.text()).toContain('bank_connection.csas_sandbox_label')
    expect(wrapper.text()).toContain('bank_connection.csas_sandbox_hint')
    for (const field of wrapper.findAll('input[type="password"]')) await field.setValue('synthetic-value')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
  })
  it('explains read-only scope and logging without blocking on logging', async () => {
    const wrapper = mount(CsasOnboarding, { props: { currencyId: 3, canWrite: true } })
    await flushPromises()
    expect(wrapper.text()).toContain('bank_connection.csas_read_only')
    expect(wrapper.text()).toContain('bank_connection.csas_logging')
    expect(wrapper.text()).toContain('https://example.invalid/callback')
    for (const field of wrapper.findAll('input[type="password"]')) await field.setValue('synthetic-value')
    expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
    expect(mocks.post).not.toHaveBeenCalled()
  })
  it('requests read-only consent by default and payments only after an explicit choice', async () => {
    mocks.post.mockResolvedValue({ data: { redirect_url: 'https://example.invalid/phishing' } })
    const wrapper = mount(CsasOnboarding, { props: { currencyId: 3, canWrite: true } })
    await flushPromises()
    expect((wrapper.find('[data-test="csas-payments"]').element as HTMLInputElement).checked).toBe(false)
    for (const field of wrapper.findAll('input[type="password"]')) await field.setValue('synthetic-value')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(mocks.post.mock.calls[0][1]).toMatchObject({ payments: false })
    for (const field of wrapper.findAll('input[type="password"]')) await field.setValue('synthetic-value')
    await wrapper.find('[data-test="csas-payments"]').setValue(true)
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(mocks.post.mock.calls[1][1]).toMatchObject({ payments: true })
  })
  it.each(['available', 'authorization_scope_missing', 'account_not_suitable'])('shows payment state %s of the connected account', async state => {
    mocks.get.mockResolvedValue({ data: { server_ready: true, callback_url: 'https://example.invalid/callback', payment_submission: state } })
    const wrapper = mount(CsasOnboarding, { props: { currencyId: 3, canWrite: true } })
    await flushPromises()
    expect(wrapper.find('[data-test="csas-payments-state"]').text()).toBe(`bank_connection.csas_payments_${state}`)
  })
  it('explains a consent granted for another George Business workspace only at the affected account', async () => {
    mocks.route.query = { csas: 'error', currency_id: '3', code: 'statement_account_mismatch' }
    const affected = mount(CsasOnboarding, { props: { currencyId: 3, canWrite: true } })
    const other = mount(CsasOnboarding, { props: { currencyId: 4, canWrite: true } })
    await flushPromises()
    expect(affected.find('[data-test="csas-error"]').text()).toBe('bank_connection.csas_error_account_mismatch')
    expect(other.find('[data-test="csas-error"]').exists()).toBe(false)
  })
  it('keeps the generic callback error for other reasons and for returns without an account', async () => {
    mocks.route.query = { csas: 'error', currency_id: '3', code: 'csas_oauth_failed' }
    const coded = mount(CsasOnboarding, { props: { currencyId: 3, canWrite: true } })
    await flushPromises()
    expect(coded.find('[data-test="csas-error"]').text()).toBe('bank_connection.csas_error')
    mocks.route.query = { csas: 'error' }
    const legacy = mount(CsasOnboarding, { props: { currencyId: 4, canWrite: true } })
    await flushPromises()
    expect(legacy.find('[data-test="csas-error"]').text()).toBe('bank_connection.csas_error')
  })
  it('does not expose a credential form to read-only users', async () => {
    const wrapper = mount(CsasOnboarding, { props: { currencyId: 3, canWrite: false } })
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(false)
  })
  it('rejects redirects outside the bank after a consent request', async () => {
    mocks.post.mockResolvedValue({ data: { redirect_url: 'https://example.invalid/phishing' } })
    const wrapper = mount(CsasOnboarding, { props: { currencyId: 3, canWrite: true } })
    await flushPromises()
    for (const field of wrapper.findAll('input[type="password"]')) await field.setValue('synthetic-value')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.findAll('input[type="password"]').every(input => (input.element as HTMLInputElement).value === '')).toBe(true)
    expect(mocks.post).toHaveBeenCalledOnce()
  })
})
