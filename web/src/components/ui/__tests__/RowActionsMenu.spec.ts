import { afterEach, describe, expect, it, vi } from 'vitest'
import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils'

vi.mock('vue-router', () => ({
  RouterLink: { name: 'RouterLink', props: ['to'], template: '<a><slot /></a>' },
}))

vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({ t: (key: string) => key }),
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

// Nabídka je teleportovaná; ruční mazání <body> by rozbilo odmontování a
// další test by běžel nad zbytkem předchozího.
enableAutoUnmount(afterEach)

describe('RowActionsMenu', () => {
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

    window.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }))
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
