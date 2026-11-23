import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
const m = vi.hoisted(() => ({ managed: true, listEmailProfiles: vi.fn().mockResolvedValue([]), listSigningProfiles: vi.fn().mockResolvedValue([]), success: vi.fn(), error: vi.fn() }))
vi.mock('@/api/settings', () => ({ settingsApi: m }))
vi.mock('@/composables/useToast', () => ({ useToast: () => m }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ isManagedInstallation: m.managed, canWrite: () => true, canRead: () => true }) }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import EmailProfiles from '../EmailProfiles.vue'

async function openNewProfile() {
  const wrapper = mount(EmailProfiles)
  await flushPromises()
  await wrapper.findAll('button').find(b => b.text().includes('settings.email_profiles_new'))!.trigger('click')
  return wrapper
}

describe('sender address in a managed installation', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })
  it('warns that the From address cannot be changed without ordering it from support', async () => {
    m.managed = true
    const wrapper = await openNewProfile()
    const warning = wrapper.get('[data-testid="managed-sender-warning"]')
    expect(warning.text()).toContain('settings.email_profile_managed_sender_title')
    expect(warning.text()).toContain('settings.email_profile_managed_sender_warning')
    wrapper.unmount()
  })
  it('self-hosted installation shows no such warning', async () => {
    m.managed = false
    const wrapper = await openNewProfile()
    expect(wrapper.find('[data-testid="managed-sender-warning"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
