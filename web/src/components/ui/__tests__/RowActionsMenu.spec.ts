import { describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { computed, ref } from 'vue'
import { windowContextKey } from '@/workspace/windowContext'

vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ locale: { value: 'cs' }, t: (key: string) => key }),
}))

import RowActionsMenu, { type RowAction } from '@/components/ui/RowActionsMenu.vue'

/**
 * Nabídka je teleportovaná na konec <body>, takže se do ní Tabem z řádku nedá
 * dostat. Kdyby ji neuměly otevřít a projít šipky, byly by rychlé akce pro
 * uživatele bez myši nedosažitelné — a to je přístupnostní vada, ne kosmetika.
 */
function actions(): RowAction[] {
  return [
    { key: 'a', label: 'První', run: vi.fn() },
    { key: 'b', label: 'Druhá', run: vi.fn() },
    { key: 'c', label: 'Třetí', run: vi.fn() },
    { key: 'd', label: 'Zakázaná', disabled: true, disabledReason: 'Chybí oprávnění.' },
  ]
}

function mountMenu(props: Record<string, unknown> = {}) {
  return mount(RowActionsMenu, {
    attachTo: document.body,
    props: { actions: actions(), inlineCount: 1, ...props },
  })
}

describe('RowActionsMenu', () => {
  it('po otevření odkazu v jiném panelu zavře původní nabídku i při návratu', async () => {
    const active = ref(true)
    const portal = document.createElement('div')
    document.body.append(portal)
    const wrapper = mount(RowActionsMenu, {
      props: { actions: actions(), inlineCount: 1 },
      global: { provide: { [windowContextKey as symbol]: {
        paneId: computed(() => 'primary'), active: computed(() => active.value),
        root: document.createElement('div'), portal,
      } } },
    })
    try {
      const trigger = wrapper.get('button[aria-haspopup="menu"]')
      await trigger.trigger('click')
      expect(trigger.attributes('aria-expanded')).toBe('true')
      active.value = false
      await flushPromises()
      expect(trigger.attributes('aria-expanded')).toBe('false')
      active.value = true
      await flushPromises()
      expect(trigger.attributes('aria-expanded')).toBe('false')
      expect(portal.querySelector('[role="menu"]')).toBeNull()
    } finally {
      wrapper.unmount()
      portal.remove()
    }
  })
  it('menuOnly udrží i jedinou akci pod třemi tečkami', async () => {
    const wrapper = mountMenu({ actions: [{ key: 'journal', label: 'Deník', menuOnly: true, to: '/journal' }], inlineCount: 1 })
    expect(wrapper.find('a').exists()).toBe(false)
    await wrapper.get('button[aria-haspopup="menu"]').trigger('click')
    expect(document.querySelector('[data-action="journal"]')?.textContent).toContain('Deník')
    wrapper.unmount()
  })

  it('vlastní navigace může před zpracováním routeru převzít obyčejný klik', async () => {
    const onNavigate = vi.fn((event: MouseEvent) => { if (!event.ctrlKey) event.preventDefault() })
    const wrapper = mountMenu({ actions: [{ key: 'journal', label: 'Deník', menuOnly: true, to: '/journal', onNavigate, workspaceOpen: 'beside' }] })
    await wrapper.get('button[aria-haspopup="menu"]').trigger('click')
    const link = document.querySelector('[data-action="journal"]')!
    const event = new MouseEvent('click', { button: 0, cancelable: true, bubbles: true })
    link.dispatchEvent(event)
    expect(onNavigate).toHaveBeenCalledExactlyOnceWith(event)
    expect(event.defaultPrevented).toBe(true)
    expect(link.getAttribute('data-workspace-open')).toBe('beside')
    wrapper.unmount()
  })

  it('otevře nabídku šipkou dolů a stoupne na první položku', async () => {
    const wrapper = mountMenu()
    await wrapper.get('button[aria-haspopup="menu"]').trigger('keydown', { key: 'ArrowDown' })
    await flushPromises()

    const items = document.querySelectorAll('[data-menu-item]')
    expect(items.length).toBe(3)
    expect(document.activeElement).toBe(items[0])
  })

  it('šipkami se chodí dokola a Escape vrátí ohnisko na spouštěč', async () => {
    const wrapper = mountMenu()
    const trigger = wrapper.get('button[aria-haspopup="menu"]')
    await trigger.trigger('keydown', { key: 'ArrowUp' })
    await flushPromises()

    const menu = document.querySelector('[role="menu"]')!
    // Zakázaná položka se přeskakuje — poslední „procházitelná" je třetí.
    expect((document.activeElement as HTMLElement).textContent).toContain('Třetí')

    menu.dispatchEvent(new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }))
    await flushPromises()
    expect((document.activeElement as HTMLElement).textContent).toContain('Druhá')

    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await flushPromises()
    expect(document.querySelector('[role="menu"]')).toBeNull()
    expect(document.activeElement).toBe(trigger.element)
  })

  it('zakázaná položka není odkaz a nese viditelný důvod', async () => {
    const wrapper = mountMenu()
    await wrapper.get('button[aria-haspopup="menu"]').trigger('click')
    await flushPromises()

    const blocked = document.querySelector('[aria-disabled="true"]')!
    expect(blocked.tagName).toBe('SPAN')
    expect(blocked.getAttribute('tabindex')).toBe('-1')
    expect(blocked.textContent).toContain('Chybí oprávnění.')
  })

  it('v ikonovém režimu zůstane popisek pro čtečku i tooltip', () => {
    const wrapper = mountMenu({ iconOnly: true })
    const inline = wrapper.get('button:not([aria-haspopup])')
    expect(inline.attributes('aria-label')).toBe('První')
    expect(inline.attributes('title')).toBe('První')
    expect(inline.get('span').classes()).toContain('sr-only')
  })
})
