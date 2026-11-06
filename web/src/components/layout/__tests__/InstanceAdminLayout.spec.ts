import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createMemoryHistory, createRouter } from 'vue-router'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, locale: { value: 'cs' } }) }))
vi.mock('@/i18n', () => ({ ensureLocaleLoaded: vi.fn().mockResolvedValue(undefined) }))
// Logo se v testu nenačítá: jde o import assetu z public adresáře.
vi.mock('../AppBrand.vue', () => ({ default: { template: '<span data-brand>MyÚčto</span>' } }))

import InstanceAdminLayout from '../InstanceAdminLayout.vue'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'

const stub = { template: '<div data-page />' }

async function mountLayout(path = '/instance-admin') {
  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/login', name: 'login', component: stub },
      { path: '/instance-admin', component: InstanceAdminLayout, children: [
        { path: '', name: 'instance-admin', component: stub },
        { path: 'profile', name: 'instance-admin-profile', component: stub },
      ] },
    ],
  })
  await router.push(path)
  await router.isReady()
  const wrapper = mount({ template: '<RouterView />' }, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('Layout správce instance', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    const auth = useAuthStore()
    auth.user = {
      id: 42, email: 'operator@example.test', name: 'Operator',
      role: { id: 8, name: 'Operator', type: 'instance_admin', system_key: 'instance_admin' },
      is_superadmin: false, is_instance_admin: true,
    } as never
    useSessionSecurityStore().state = { session_state: 'active', unlock_methods: [] } as never
  })

  it('má stejnou horní lištu jako aplikace a v menu jen Přehled a Profil', async () => {
    const { wrapper } = await mountLayout()
    const header = wrapper.get('header')
    expect(header.classes()).toEqual(expect.arrayContaining(['nav-inverted', 'sticky', 'top-0']))
    expect(header.get('div').classes()).toContain('h-12')
    const links = wrapper.get('[data-instance-nav]').findAll('a')
    expect(links.map(link => link.text())).toEqual(['instance_admin.overview', 'auth.profile_title'])
    expect(links.map(link => link.attributes('href'))).toEqual(['/instance-admin', '/instance-admin/profile'])
    expect(links[0]!.attributes('aria-current')).toBe('page')
    expect(links[1]!.attributes('aria-current')).toBeUndefined()
    expect(links[0]!.classes()).toContain('bg-primary-50')
    expect(wrapper.get('[data-instance-drawer]').findAll('nav a').map(link => link.attributes('href')))
      .toEqual(['/instance-admin', '/instance-admin/profile'])
  })

  it('starý pruh s orámovanými tlačítky a návrat do aplikace jsou pryč', async () => {
    const { wrapper } = await mountLayout()
    expect(wrapper.text()).not.toContain('instance_admin.back_to_app')
    expect(wrapper.find('a[href="/"]').exists()).toBe(false)
    expect(wrapper.get('header').text()).not.toContain('instance_admin.title')
    expect(wrapper.get('header').text()).not.toContain('nav.logout')
    expect(wrapper.get('[data-instance-nav]').find('button').exists()).toBe(false)
  })

  it('uživatelské menu nabízí profil, téma, jazyk a odhlášení, bez zkratek a firemních odkazů', async () => {
    const { wrapper, router } = await mountLayout()
    await wrapper.get('header button[aria-haspopup="menu"]').trigger('click')
    const menu = wrapper.get('[role="menu"]')
    expect(menu.text()).toContain('instance_admin.menu_theme')
    expect(menu.text()).toContain('instance_admin.menu_language')
    expect(menu.text()).toContain('nav.logout')
    expect(menu.text()).not.toContain('keyboard_shortcuts.title')
    for (const link of menu.findAll('a')) expect(link.attributes('href')).toMatch(/^\/instance-admin\/profile/)
    expect(menu.findAll('a')).toHaveLength(4)

    await menu.get('a[href="/instance-admin/profile?tab=totp"]').trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.fullPath).toBe('/instance-admin/profile?tab=totp')
    expect(wrapper.find('[role="menu"]').exists()).toBe(false)
  })

  it('mobilní zásuvka se otevírá hamburgerem a je zavřená mimo tab pořadí', async () => {
    const { wrapper } = await mountLayout()
    const drawer = wrapper.get('[data-instance-drawer]')
    expect(drawer.classes()).toContain('translate-x-full')
    expect((drawer.element as HTMLElement).inert).toBe(true)
    await wrapper.get('header button[aria-label="Menu"]').trigger('click')
    expect(drawer.classes()).toContain('translate-x-0')
    expect((drawer.element as HTMLElement).inert).toBe(false)
    expect(drawer.text()).toContain('nav.logout')
  })

  it('odhlášení přejde na přihlášení a neúspěch ukáže hlášku', async () => {
    const auth = useAuthStore()
    const logout = vi.spyOn(auth, 'logout').mockRejectedValueOnce(new Error('offline'))
    const { wrapper, router } = await mountLayout()
    const button = wrapper.get('[data-instance-drawer]').findAll('button').find(item => item.text() === 'nav.logout')!
    await button.trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('auth.logout_failed')
    logout.mockResolvedValueOnce(undefined)
    await button.trigger('click')
    await flushPromises()
    expect(router.currentRoute.value.name).toBe('login')
  })

  it('nesahá na firemní menu, přepínač firem ani pracovní plochu', () => {
    const source = readFileSync(resolve(process.cwd(), 'src/components/layout/InstanceAdminLayout.vue'), 'utf8')
    for (const forbidden of ['SupplierSwitcher', 'useSupplierStore', 'AppLayout', 'WorkspaceNavLink', 'GlobalSearch', 'CommandPalette', 'useWorkspace']) {
      expect(source).not.toContain(forbidden)
    }
  })
})
