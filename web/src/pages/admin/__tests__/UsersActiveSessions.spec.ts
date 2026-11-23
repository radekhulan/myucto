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
    const tabs = wrapper.findAll('[role="tablist"] [role="tab"]')
    expect(tabs.map(tab => tab.get('svg path').attributes('d'))).toEqual([ICONS.user, ICONS.lock])
    expect(tabs.map(tab => tab.attributes('aria-selected'))).toEqual(['true', 'false'])
    expect(wrapper.find('[data-active-sessions]').exists()).toBe(false)
    await wrapper.get('[data-sessions-tab]').trigger('click')
    expect(wrapper.get('[data-sessions-tab]').attributes('aria-selected')).toBe('true')
    expect(wrapper.find('[data-active-sessions]').exists()).toBe(true)
    expect(wrapper.text()).not.toContain('users.new')
    wrapper.unmount()
  })

  it('šipkou vpravo se přepne na relace', async () => {
    const wrapper = mount(Users, { attachTo: document.body })
    await flushPromises()
    await wrapper.get('[data-tab="users"]').trigger('keydown', { key: 'ArrowRight' })
    expect(wrapper.find('[data-active-sessions]').exists()).toBe(true)
    wrapper.unmount()
  })
})
