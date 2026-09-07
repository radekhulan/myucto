import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import CsasOnboarding from '../CsasOnboarding.vue'

const mocks = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('@/api/client', () => ({ api: mocks }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ useRoute: () => ({ query: {} }) }))
vi.mock('@/composables/useDemoMode', () => ({ useDemoMode: () => ({ blockDemoMutation: () => false }) }))
beforeEach(() => {
  vi.clearAllMocks()
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
    for (const field of wrapper.findAll('input')) await field.setValue('synthetic-value')
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
    for (const field of wrapper.findAll('input')) await field.setValue('synthetic-value')
    expect(wrapper.find('button').attributes('disabled')).toBeUndefined()
    expect(mocks.post).not.toHaveBeenCalled()
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
    for (const field of wrapper.findAll('input')) await field.setValue('synthetic-value')
    await wrapper.find('form').trigger('submit')
    await flushPromises()
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    expect(wrapper.findAll('input').every(input => (input.element as HTMLInputElement).value === '')).toBe(true)
    expect(mocks.post).toHaveBeenCalledOnce()
  })
})
