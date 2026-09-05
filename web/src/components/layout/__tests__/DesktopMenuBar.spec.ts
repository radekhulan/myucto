import { describe, it, expect, vi, beforeEach } from 'vitest'
import { mount } from '@vue/test-utils'
import { createRouter, createMemoryHistory, RouterLink } from 'vue-router'
import { createPinia } from 'pinia'
import DesktopMenuBar from '../DesktopMenuBar.vue'

// Klik na název sekce v horním menu: na myši je submenu otevřené už od hoveru,
// takže klik má skočit na první položku (Prodej → Vydané faktury). Na dotyku
// klepnutí submenu jen otevírá a zavírá — jinak by byl každý překlep navigace.

const SECTIONS = [
  {
    key: 'sales',
    title: 'Prodej',
    accent: 'primary' as const,
    items: [
      { to: '/invoices', label: 'Vydané faktury', icon: 'M0 0' },
      { to: '/recurring', label: 'Pravidelné faktury', icon: 'M0 0' },
    ],
  },
  {
    key: 'help',
    title: 'Nápověda',
    items: [{ to: 'https://example.test/manual', label: 'Manuál', icon: 'M0 0', external: true }],
  },
]

function mountBar(hoverCapable: boolean) {
  const matchMedia = vi.fn().mockReturnValue({
    matches: hoverCapable,
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
  })
  vi.stubGlobal('matchMedia', matchMedia)

  const router = createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: { template: '<div />' } },
      { path: '/invoices', component: { template: '<div />' } },
      { path: '/recurring', component: { template: '<div />' } },
    ],
  })
  const push = vi.spyOn(router, 'push')

  const wrapper = mount(DesktopMenuBar, {
    props: {
      sections: SECTIONS,
      isActive: () => false,
      canCreate: () => false,
      createTarget: () => '/',
      shortcutFor: () => '',
      quickNewLabel: 'Nový',
      menuLabel: 'Hlavní menu',
    },
    global: { plugins: [createPinia(), router], stubs: { RouterLink: RouterLink as never } },
  })

  return { wrapper, push, sales: wrapper.findAll('button')[0]! }
}

describe('DesktopMenuBar — klik na otevřenou sekci', () => {
  beforeEach(() => vi.unstubAllGlobals())

  it('na myši skočí na první položku sekce', async () => {
    const { wrapper, push, sales } = mountBar(true)

    await wrapper.findAll('.relative.flex')[0]!.trigger('pointerenter')
    expect(sales.attributes('aria-expanded')).toBe('true')

    await sales.trigger('click')
    expect(push).toHaveBeenCalledWith('/invoices')
    expect(sales.attributes('aria-expanded')).toBe('false')
  })

  it('bez hoveru (klávesnice) první klik jen otevře, druhý naviguje', async () => {
    const { push, sales } = mountBar(true)

    await sales.trigger('click')
    expect(push).not.toHaveBeenCalled()
    expect(sales.attributes('aria-expanded')).toBe('true')

    await sales.trigger('click')
    expect(push).toHaveBeenCalledWith('/invoices')
  })

  it('na dotyku se jen přepíná otevření, nikam se neskáče', async () => {
    const { push, sales } = mountBar(false)

    await sales.trigger('click')
    expect(sales.attributes('aria-expanded')).toBe('true')

    await sales.trigger('click')
    expect(sales.attributes('aria-expanded')).toBe('false')
    expect(push).not.toHaveBeenCalled()
  })

  it('sekce jen s externím odkazem zůstane otevřená', async () => {
    const { wrapper, push } = mountBar(true)
    const help = wrapper.findAll('button')[1]!

    await wrapper.findAll('.relative.flex')[1]!.trigger('pointerenter')
    await help.trigger('click')

    expect(push).not.toHaveBeenCalled()
    expect(help.attributes('aria-expanded')).toBe('true')
  })

  it.each(['system_global', 'system_signing'])('skryje %s pouze s manuálem a obnoví ji při přidání další položky', async key => {
    const { wrapper } = mountBar(true)
    const manual = { to: '/manual', label: 'Nápověda (manuál)', icon: 'M0 0', external: true }
    const system = { key, title: 'Systém', items: [manual] }
    await wrapper.setProps({ sections: [...SECTIONS, system] })
    expect(wrapper.findAll('button').some(button => button.text() === 'Systém')).toBe(false)
    expect(wrapper.findAll('button').some(button => button.text() === 'Nápověda')).toBe(true)

    await wrapper.setProps({ sections: [...SECTIONS, { ...system, items: [manual, { to: '/admin/users', label: 'Uživatelé', icon: 'M0 0' }] }] })
    expect(wrapper.findAll('button').some(button => button.text() === 'Systém')).toBe(true)

    await wrapper.setProps({ sections: [...SECTIONS, { ...system, items: [{ to: '/admin/users', label: 'Uživatelé', icon: 'M0 0' }] }] })
    expect(wrapper.findAll('button').some(button => button.text() === 'Systém')).toBe(true)
    wrapper.unmount()
  })
})
