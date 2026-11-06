import { beforeEach, describe, expect, it, vi } from 'vitest'
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))

import AppUserMenu from '../AppUserMenu.vue'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'

const link = { props: ['to'], template: '<a :href="to"><slot /></a>' }

function signIn(type: string) {
  useAuthStore().user = {
    id: 1, email: 'user@example.test', name: 'Jana Testová',
    role: { id: 1, name: type, type, system_key: type },
    is_superadmin: false, is_instance_admin: type === 'instance_admin',
  } as never
}

describe('AppUserMenu', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('běžné roli nabídne stejné položky jako dřív, se zkratkami a odkazy na /profile/password', () => {
    signIn('staff')
    useSessionSecurityStore().state = { session_state: 'active', unlock_methods: ['passkey'] } as never
    const wrapper = mount(AppUserMenu, { props: { link, open: true } })
    expect(wrapper.findAll('a').map(item => item.attributes('href'))).toEqual([
      '/profile/password',
      '/profile/password?tab=totp',
      '/profile/password?tab=passkeys',
      '/profile/password?tab=session-lock',
      '/profile/password?tab=shortcuts',
    ])
    expect(wrapper.text()).toContain('session_lock.lock_now')
    expect(wrapper.text()).toContain('nav.logout')
  })

  it('kompaktní tlačítko ukáže iniciály a popisek pro čtečky', () => {
    signIn('staff')
    const wrapper = mount(AppUserMenu, { props: { link, compact: true } })
    expect(wrapper.get('button').text()).toBe('JT')
    expect(wrapper.get('button').attributes('aria-label')).toBe('nav.user_menu: Jana Testová')
    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
  })

  it('předá kliknutí na odhlášení rodiči a během odhlašování je blokuje', async () => {
    signIn('staff')
    const wrapper = mount(AppUserMenu, { props: { link, open: true, logoutBusy: true } })
    const logout = wrapper.findAll('button').find(item => item.text() === 'nav.logout')!
    expect(logout.attributes('disabled')).toBeDefined()
    await wrapper.setProps({ logoutBusy: false })
    await logout.trigger('click')
    expect(wrapper.emitted('logout')).toHaveLength(1)
  })
})
