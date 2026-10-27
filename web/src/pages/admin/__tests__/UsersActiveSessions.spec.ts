import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { ICONS } from '@/components/ui/buttonStyles'

vi.mock('@/api/admin', () => ({ adminApi: { listUsers: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/api/roles', () => ({ rolesApi: { list: vi.fn().mockResolvedValue([]) } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({}) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({}) }))
vi.mock('@/components/security/ActiveSessions.vue', () => ({ default: { template: '<div data-active-sessions />' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
import Users from '../Users.vue'

describe('Záložky Uživatelů', () => {
  it('obě záložky mají platnou ikonu a přepnutí zobrazí přehled relací', async () => {
    const wrapper = mount(Users)
    await flushPromises()
    const tabs = wrapper.findAll('nav button')
    expect(tabs.map(tab => tab.get('svg path').attributes('d'))).toEqual([ICONS.user, ICONS.lock])
    expect(wrapper.find('[data-active-sessions]').exists()).toBe(false)
    await wrapper.get('[data-sessions-tab]').trigger('click')
    expect(wrapper.find('[data-active-sessions]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('users.new')
    wrapper.unmount()
  })
})
