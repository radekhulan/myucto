import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { reactive } from 'vue'
const m = vi.hoisted(() => ({ route: undefined as any, replace: vi.fn(), permissions: [] as string[], client: true }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ get isClientRole() { return m.client }, canWrite: (permission: string) => m.permissions.includes(permission) }) }))
vi.mock('vue-router', () => ({ useRoute: () => m.route, useRouter: () => ({ replace: m.replace }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/pages/admin/EmailProfiles.vue', () => ({ default: { template: '<div data-test="email-profiles" />' } }))
vi.mock('@/pages/admin/Branding.vue', () => ({ default: { template: '<div data-test="branding" />' } }))
vi.mock('@/components/settings/ClientSigningProfiles.vue', () => ({ default: { template: '<div data-test="signing-profiles" />' } }))
vi.mock('@/components/settings/ClientInvoiceDefaults.vue', () => ({ default: { template: '<div data-test="invoice-defaults" />' } }))
vi.mock('@/components/settings/ClientPaymentQrSettings.vue', () => ({ default: { template: '<div />' } }))
vi.mock('@/components/settings/CompanyEmailTemplates.vue', () => ({ default: { props: { clientScoped: Boolean }, template: '<div data-test="company-templates">{{ clientScoped }}</div>' } }))
import ClientCompanySettings from './ClientCompanySettings.vue'

describe('client operational settings tabs', () => {
  beforeEach(() => {
    m.replace.mockReset()
    m.client = true
    m.permissions = ['settings.email_templates', 'settings.branding']
  })
  it('opens the first allowed tab when a forbidden tab is requested', () => {
    m.permissions = ['settings.branding']
    m.route = reactive({ query: { tab: 'email-profiles' } })
    const wrapper = mount(ClientCompanySettings)
    expect(wrapper.findAll('nav button').map(b => b.text())).toEqual(['nav.branding'])
    expect(wrapper.find('[data-test="branding"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="email-profiles"]').exists()).toBe(false)
    wrapper.unmount()
  })
  it('allows staff to use delegated operational tabs without exposing client signing', () => {
    m.client = false
    m.permissions = ['settings.payment_qr', 'settings.signing_profiles']
    m.route = reactive({ query: { tab: 'signing-profiles' } })
    const wrapper = mount(ClientCompanySettings)
    expect(wrapper.findAll('nav button').map(b => b.text())).toEqual(['settings.payment_qr_tab'])
    expect(wrapper.find('[data-test="signing-profiles"]').exists()).toBe(false)
    wrapper.unmount()
  })
  it('mounts no editor without a targeted write grant', () => {
    m.permissions = ['settings.company']
    m.route = reactive({ query: { tab: 'email-templates' } })
    const wrapper = mount(ClientCompanySettings)
    expect(wrapper.findAll('nav button')).toHaveLength(0)
    expect(wrapper.find('[data-test]').exists()).toBe(false)
    wrapper.unmount()
  })
  it('unmounts a revoked editor when permissions change', async () => {
    const permissions = reactive(['settings.email_templates', 'settings.branding'])
    m.permissions = permissions
    m.route = reactive({ query: { tab: 'email-templates' } })
    const wrapper = mount(ClientCompanySettings)
    expect(wrapper.find('[data-test="company-templates"]').exists()).toBe(true)
    permissions.splice(0, 1)
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-test="company-templates"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="branding"]').exists()).toBe(true)
    wrapper.unmount()
  })
  it('supports a direct link on the existing custom-domain settings route', () => {
    m.route = reactive({ query: { tab: 'email-templates' } })
    const wrapper = mount(ClientCompanySettings)
    expect(wrapper.get('[data-test="company-templates"]').text()).toBe('true')
    wrapper.unmount()
  })
  it('retains other query parameters when selecting the template tab', async () => {
    m.route = reactive({ query: { tab: 'branding', supplier_id: '12' } })
    const wrapper = mount(ClientCompanySettings)
    await wrapper.findAll('nav button').find(b => b.text() === 'users.email_templates_title')!.trigger('click')
    expect(m.replace).toHaveBeenCalledWith({ query: { tab: 'email-templates', supplier_id: '12' } })
    expect(wrapper.get('[data-test="company-templates"]').text()).toBe('true')
    wrapper.unmount()
  })
  it.each([true, false])('opens invoice defaults with a targeted grant (client=%s)', client => {
    m.client = client
    m.permissions = ['settings.invoice_defaults']
    m.route = reactive({ query: { tab: 'invoice-defaults' } })
    const wrapper = mount(ClientCompanySettings)
    expect(wrapper.findAll('nav button').map(b => b.text())).toEqual(['settings.invoice_defaults_title'])
    expect(wrapper.find('[data-test="invoice-defaults"]').exists()).toBe(true)
    wrapper.unmount()
  })

})
