import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
const m = vi.hoisted(() => ({ canSigning: true, listEmailProfiles: vi.fn().mockResolvedValue([]), listSigningProfiles: vi.fn(), createEmailProfile: vi.fn().mockResolvedValue({}), browseEmailProfileImapFolders: vi.fn(), success: vi.fn(), error: vi.fn() }))
vi.mock('@/api/settings', () => ({ settingsApi: m }))
vi.mock('@/composables/useToast', () => ({ useToast: () => m }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isManagedInstallation: false, canWrite: () => m.canSigning, canRead: () => m.canSigning }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import EmailProfiles from '../EmailProfiles.vue'

describe('client e-mail signing selection', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.canSigning = true
  })
  it('loads email profiles independently when signing management is not granted', async () => {
    m.canSigning = false
    const wrapper = mount(EmailProfiles, { props: { clientScoped: true } })
    await flushPromises()
    expect(m.listEmailProfiles).toHaveBeenCalledWith(true)
    expect(m.listSigningProfiles).not.toHaveBeenCalled()
    expect(m.error).not.toHaveBeenCalled()
    await wrapper.findAll('button').find(b => b.text().includes('settings.email_profiles_new'))!.trigger('click')
    expect(wrapper.findAll('label').some(l => l.text().includes('settings.email_profile_signing_profile'))).toBe(false)
    wrapper.unmount()
  })
  it('uses Microsoft 365 credentials to browse folders before saving the profile', async () => {
    m.listSigningProfiles.mockResolvedValue([])
    m.browseEmailProfileImapFolders.mockResolvedValue({ ok: true, folders: [] })
    const wrapper = mount(EmailProfiles, { props: { clientScoped: true } })
    await flushPromises()
    const button = (key: string) => wrapper.findAll('button').find(item => item.text().includes(key))!
    const label = (key: string) => wrapper.findAll('label').find(item => item.text().startsWith(key))!
    await button('settings.email_profiles_new').trigger('click')
    await label('settings.email_profile_configure_imap_sent').get('input').setValue(true)
    await label('settings.imap_auth_mode').get('select').setValue('microsoft365')
    await label('settings.imap_oauth_mailbox').get('input').setValue('shared@example.test')
    await label('settings.imap_oauth_tenant_id').get('input').setValue('11111111-1111-4111-8111-111111111111')
    await label('settings.imap_oauth_client_id').get('input').setValue('22222222-2222-4222-8222-222222222222')
    await label('settings.imap_oauth_client_secret').get('input').setValue('synthetic-client-secret')
    await button('settings.email_profile_imap_browse').trigger('click')
    await flushPromises()
    expect(m.browseEmailProfileImapFolders).toHaveBeenCalledWith(expect.objectContaining({
      imap_auth_mode: 'microsoft365',
      imap_username: 'shared@example.test',
      imap_oauth_tenant_id: '11111111-1111-4111-8111-111111111111',
      imap_oauth_client_id: '22222222-2222-4222-8222-222222222222',
      imap_oauth_client_secret: 'synthetic-client-secret',
      imap_password: null,
    }), null, true)
    wrapper.unmount()
  })

  it('loads client signing profiles, offers only active company S/MIME profiles, and saves the selection', async () => {
    const company = { id: 12, owner_user_id: null, is_active: true, name: 'Company S/MIME', code: 'synthetic', allowed_usages: ['email_smime'] }
    m.listSigningProfiles.mockResolvedValue([company, { ...company, id: 13, owner_user_id: 7, name: 'Personal' }, { ...company, id: 14, allowed_usages: ['pdf'], name: 'PDF only' }])
    const wrapper = mount(EmailProfiles, { props: { clientScoped: true } })
    await flushPromises()
    expect(m.listSigningProfiles).toHaveBeenCalledWith(true)
    const create = wrapper.findAll('button').find(b => b.text().includes('settings.email_profiles_new'))!
    await create.trigger('click')
    const signing = wrapper.findAll('label').find(l => l.text().includes('settings.email_profile_signing_profile'))!.find('select')
    expect(signing.text()).toContain('Company S/MIME')
    expect(signing.text()).not.toContain('Personal')
    expect(signing.text()).not.toContain('PDF only')
    expect(wrapper.findAll('option').some(option => option.attributes('value') === 'sendmail')).toBe(false)
    await signing.setValue('12')
    const name = wrapper.findAll('label').find(l => l.text().startsWith('settings.email_profile_name'))!.find('input')
    await name.setValue('Synthetic sender')
    const code = wrapper.findAll('label').find(l => l.text().startsWith('settings.email_profile_code'))!.find('input')
    await code.setValue('synthetic')
    const from = wrapper.findAll('label').find(l => l.text().startsWith('settings.email_profile_from_email'))!.find('input')
    await from.setValue('sender@example.invalid')
    await wrapper.findAll('button').find(b => b.text().includes('common.save'))!.trigger('click')
    await flushPromises()
    expect(m.createEmailProfile).toHaveBeenCalledWith(expect.objectContaining({ signing_profile_id: 12 }), true)
    expect(m.createEmailProfile.mock.calls[0]![0]).not.toHaveProperty('sendmail_command')
  })
})
