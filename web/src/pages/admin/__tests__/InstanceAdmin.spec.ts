import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({ status: vi.fn(), smtp: vi.fn(), suppliers: vi.fn(), audit: vi.fn(), saveSmtp: vi.fn(), testSmtp: vi.fn(), resetSmtpOverride: vi.fn(), supplierStatus: vi.fn(), isSuperadmin: false }))
vi.mock('@/api/instanceAdmin', () => ({ instanceAdminApi: m }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => m }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ locale: { value: 'cs' }, t: (key: string, vars?: Record<string, string>) => vars ? `${key} ${JSON.stringify(vars)}` : key }) }))
import InstanceAdmin from '../InstanceAdmin.vue'

const target = { id: 7, name: 'Approved relay', host: 'smtp.example.test', port: 587, encryption: 'tls', username: 'operator', from_email: 'noreply@example.test', test_recipient: 'approved@example.test' }
const smtp = { managed: false, read_only: false, read_only_reason: null, configured: true, source: 'approved_target', target_id: 7, password_set: true, targets: [target, { ...target, id: 8, name: 'Other approved relay' }], test_recipient: target.test_recipient }

describe('Technická správa — SMTP', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.restoreAllMocks()
    m.isSuperadmin = false
    m.status.mockResolvedValue({ version: 'test', php_version: '8.5', database: { available: true, version: '11.8' }, extensions: [], storage: { available: true, free_bytes: 1000 }, managed: false, smtp: { configured: true, read_only: false }, license: null })
    m.smtp.mockResolvedValue({ ...smtp })
    m.suppliers.mockResolvedValue({ data: [], next_cursor: null })
    m.audit.mockResolvedValue({ data: [], next_cursor: null })
    m.saveSmtp.mockResolvedValue(smtp)
    m.testSmtp.mockResolvedValue({ status: 'sent', sent_at: '2026-01-01T12:00:00Z', test_recipient: target.test_recipient })
    m.resetSmtpOverride.mockResolvedValue({ ...smtp, source: 'configuration', target_id: null, password_set: false, test_recipient: null })
  })

  it('neotevře správci schvalování cílů ani editaci příjemce a test neposílá klientská data', async () => {
    const wrapper = mount(InstanceAdmin)
    await flushPromises()
    expect(wrapper.find('[data-smtp-password]').element).toHaveProperty('value', '')
    expect(wrapper.find('input[type="email"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('instance_admin.target_management')
    expect(wrapper.find('[data-smtp-reset]').exists()).toBe(false)
    await wrapper.get('[data-smtp-test]').trigger('click')
    await flushPromises()
    expect(m.testSmtp).toHaveBeenCalledExactlyOnceWith()
    expect(wrapper.text()).toContain('approved@example.test')
  })

  it('nový cíl vyžaduje nové heslo a po uložení jej vymaže', async () => {
    const wrapper = mount(InstanceAdmin)
    await flushPromises()
    await wrapper.get('[data-smtp-target]').setValue('8')
    expect(wrapper.get('[data-smtp-save]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-smtp-password]').setValue('synthetic-new-secret')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(m.saveSmtp).toHaveBeenCalledWith({ target_id: 8, password: 'synthetic-new-secret' })
    expect(wrapper.get('[data-smtp-password]').element).toHaveProperty('value', '')
  })

  it('nastavení z prostředí je pouze pro čtení', async () => {
    m.smtp.mockResolvedValue({ ...smtp, read_only: true, read_only_reason: 'environment' })
    const wrapper = mount(InstanceAdmin)
    await flushPromises()
    expect(wrapper.get('[data-smtp-target]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-smtp-save]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('instance_admin.environment_locked')
  })

  it('bez schválených cílů vysvětlí nutný krok superadmina', async () => {
    m.smtp.mockResolvedValue({ ...smtp, source: 'configuration', target_id: null, targets: [], test_recipient: null, password_set: false })
    const wrapper = mount(InstanceAdmin)
    await flushPromises()
    expect(wrapper.text()).toContain('instance_admin.no_approved_targets')
    expect(wrapper.get('[data-smtp-save]').attributes('disabled')).toBeDefined()
    expect(wrapper.get('[data-smtp-test]').attributes('disabled')).toBeDefined()
  })

  it('chyba neposkytne surovou zprávu serveru', async () => {
    m.testSmtp.mockRejectedValue({ response: { data: { error: { code: 'smtp_test_failed', message: 'secret-from-unsafe-server' } } } })
    const wrapper = mount(InstanceAdmin)
    await flushPromises()
    await wrapper.get('[data-smtp-test]').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('instance_admin.errors.smtp_test_failed')
    expect(wrapper.text()).not.toContain('secret-from-unsafe-server')
  })

  it('superadmin po potvrzení obnoví konfiguraci instance a přenačte stav', async () => {
    m.isSuperadmin = true
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(InstanceAdmin)
    await flushPromises()
    m.smtp.mockResolvedValue({ ...smtp, source: 'configuration', target_id: null, password_set: false, test_recipient: null })
    await wrapper.get('[data-smtp-reset]').trigger('click')
    await flushPromises()
    expect(confirm).toHaveBeenCalledWith('instance_admin.reset_override_confirm')
    expect(m.resetSmtpOverride).toHaveBeenCalledExactlyOnceWith()
    expect(m.smtp).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-smtp-reset]').exists()).toBe(false)
    expect(wrapper.text()).toContain('instance_admin.reset_override_done')
  })

  it('odmítnuté potvrzení přepis zachová', async () => {
    m.isSuperadmin = true
    vi.spyOn(window, 'confirm').mockReturnValue(false)
    const wrapper = mount(InstanceAdmin)
    await flushPromises()
    await wrapper.get('[data-smtp-reset]').trigger('click')
    expect(m.resetSmtpOverride).not.toHaveBeenCalled()
  })

  it('v režimu pouze pro čtení neukáže reset ani superadminovi', async () => {
    m.isSuperadmin = true
    m.smtp.mockResolvedValue({ ...smtp, read_only: true, read_only_reason: 'environment' })
    const wrapper = mount(InstanceAdmin)
    await flushPromises()
    expect(wrapper.find('[data-smtp-reset]').exists()).toBe(false)
  })
})
