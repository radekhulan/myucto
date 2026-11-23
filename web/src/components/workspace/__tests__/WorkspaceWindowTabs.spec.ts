import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import { createI18n } from 'vue-i18n'
import WorkspaceWindowTabs from '@/components/workspace/WorkspaceWindowTabs.vue'
import { useWorkspaceStore, type WorkspaceWindowState } from '@/stores/workspace'

const mocks = vi.hoisted(() => ({
  select: vi.fn(),
  closeWindows: vi.fn(),
  openTab: vi.fn(),
  info: vi.fn(),
}))

vi.mock('@/workspace/windowRegistry', () => ({
  getWindowManager: () => ({ select: mocks.select, closeWindows: mocks.closeWindows, openTab: mocks.openTab }),
}))

vi.mock('@/composables/useKeyboardShortcuts', () => ({
  formatShortcut: (shortcut: string) => shortcut ? `formatted:${shortcut}` : '',
  useKeyboardShortcuts: () => ({ comboFor: (id: string) => id === 'windows.switcher' ? 'alt+w' : '' }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ info: mocks.info }),
}))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => ({ hasMultiple: true, currentSupplierId: 1 }),
}))

class ResizeObserverStub {
  observe(): void {}
  disconnect(): void {}
}

const i18n = createI18n({
  legacy: false,
  locale: 'cs',
  messages: {
    cs: {
      workspace: {
        homepage: 'Homepage',
        panel_number: 'Panel {number}',
        close_window: 'Zavřít okno',
        close_window_named: 'Zavřít okno {title}',
        tabs_label: 'Záložky oken',
        tabs_hide: 'Skrýt záložky',
        tabs_all_windows: 'Všechna okna ({count})',
        tabs_hidden_toast: 'Skryto.',
        tabs_hidden_toast_shortcut: 'Skryto, zapnete přes {shortcut}.',
        tab_other_company: 'okno firmy {name}',
        tab_new: 'Nová záložka',
        tab_new_hint: 'Nová záložka s aktuální stránkou',
        tab_window_count: 'Oken v záložce: {count}',
        close_tab_named: 'Zavřít záložku {title} (oken: {count})',
        tab_duplicate: 'Duplikovat záložku',
        tab_duplicate_hint: 'Duplikovat záložku se stejnou stránkou',
        tab_close: 'Zavřít záložku',
        tab_close_others: 'Zavřít ostatní záložky',
      },
    },
  },
})

let wrapper: VueWrapper | null = null

function fixtures(): WorkspaceWindowState[] {
  // Store drží okna podle posledního použití, nejnovější první.
  return [
    { id: 'window-3', order: 3, title: 'Účetní deník', fullPath: '/accounting/journal', paneId: 'primary', supplierId: 1 },
    { id: 'window-1', order: 1, title: '/', fullPath: '/', paneId: null, supplierId: 1 },
    { id: 'window-2', order: 2, title: 'Klienti', fullPath: '/clients', paneId: null, supplierId: 2, supplierName: 'Druhá firma s.r.o.' },
    { id: 'window-4', order: 4, title: 'Banka', fullPath: '/bank', paneId: null, supplierId: 1 },
  ]
}

function mountTabs(width = 800, props: Record<string, unknown> = {}) {
  const pinia = createPinia()
  setActivePinia(pinia)
  const workspace = useWorkspaceStore()
  workspace.resetLayout(1, '/accounting/journal')
  workspace.windows = fixtures()
  vi.spyOn(HTMLElement.prototype, 'clientWidth', 'get').mockReturnValue(width)
  wrapper = mount(WorkspaceWindowTabs, { props, attachTo: document.body, global: { plugins: [pinia, i18n] } })
  return workspace
}

function tabLabels(): string[] {
  return wrapper!.findAll('[data-window-tab]').map(tab => tab.text())
}

describe('WorkspaceWindowTabs', () => {
  beforeEach(() => {
    vi.stubGlobal('ResizeObserver', ResizeObserverStub)
    localStorage.clear()
    mocks.select.mockReset().mockResolvedValue(undefined)
    mocks.closeWindows.mockReset().mockResolvedValue(undefined)
    mocks.openTab.mockReset().mockResolvedValue(undefined)
    mocks.info.mockReset()
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    vi.restoreAllMocks()
    vi.unstubAllGlobals()
  })

  it('na malém tabletu ukáže dvě naposledy použitá okna v pořadí otevření', async () => {
    mountTabs(768)
    await nextTick()
    expect(tabLabels()).toEqual(['Homepage', 'Účetní deník'])
    expect(wrapper!.get('[data-window-tabs-switcher]').text()).toBe('+2')
  })

  it('na širokém monitoru ukáže všechna okna a aktivní označí', async () => {
    mountTabs(1920)
    await nextTick()
    expect(tabLabels().map(label => label.replace(/\s*okno firmy.*/, ''))).toEqual(['Homepage', 'Klienti', 'Účetní deník', 'Banka'])
    const active = wrapper!.findAll('[data-window-tab]').filter(tab => tab.attributes('aria-current') === 'page')
    expect(active.map(tab => tab.text())).toEqual(['Účetní deník'])
    expect(wrapper!.get('[data-window-tabs-switcher]').text()).toBe('')
  })

  it('okno jiné firmy nese její název pro čtečku i v popisku', async () => {
    mountTabs(1920)
    await nextTick()
    const clients = wrapper!.findAll('[data-window-tab]')[1]!
    expect(clients.text()).toContain('okno firmy Druhá firma s.r.o.')
    expect(clients.attributes('data-hint')).toContain('Druhá firma s.r.o.')
  })

  it('kliknutí přepne okno, prostřední tlačítko a křížek jej zavřou', async () => {
    mountTabs(1920)
    await nextTick()
    await wrapper!.findAll('[data-window-tab]')[3]!.trigger('click')
    expect(mocks.select).toHaveBeenCalledWith('window-4')
    await wrapper!.findAll('[data-window-tab-item]')[1]!.trigger('auxclick', { button: 1 })
    expect(mocks.closeWindows).toHaveBeenCalledWith(['window-2'])
    await wrapper!.findAll('[data-close-window-tab]')[0]!.trigger('click')
    expect(mocks.closeWindows).toHaveBeenLastCalledWith(['window-1'])
    expect(mocks.select).toHaveBeenCalledTimes(1)
  })

  it('šipky přesouvají fokus mezi záložkami a Delete zavře zaměřené okno', async () => {
    mountTabs(1920)
    await nextTick()
    const tabs = wrapper!.findAll<HTMLButtonElement>('[data-window-tab]')
    tabs[0]!.element.focus()
    await tabs[0]!.trigger('keydown', { key: 'ArrowLeft' })
    expect(document.activeElement).toBe(tabs[3]!.element)
    await tabs[3]!.trigger('keydown', { key: 'Delete' })
    expect(mocks.closeWindows).toHaveBeenCalledWith(['window-4'])
  })

  it('seznam, detail a úprava faktury jsou jedna záložka; křížek zavře všechna její okna', async () => {
    const workspace = mountTabs(1920)
    workspace.windows = [
      { id: 'window-7', order: 7, tab: '1:/invoices', title: 'FV2026-0012', fullPath: '/invoices/12/edit', paneId: 'primary', supplierId: 1 },
      { id: 'window-6', order: 6, tab: '1:/invoices', title: 'FV2026-0012', fullPath: '/invoices/12', paneId: null, supplierId: 1 },
      { id: 'window-5', order: 5, tab: '1:/invoices', title: 'Vydané faktury', fullPath: '/invoices', paneId: null, supplierId: 1 },
      { id: 'window-8', order: 8, tab: '1:/bank', title: 'Banka', fullPath: '/bank', paneId: null, supplierId: 1 },
    ]
    await nextTick()
    expect(tabLabels()).toEqual(['FV2026-0012', 'Banka'])
    expect(wrapper!.findAll('[data-window-tab]')[0]!.attributes('data-hint')).toContain('Oken v záložce: 3')
    await wrapper!.findAll('[data-close-window-tab]')[0]!.trigger('click')
    expect(mocks.closeWindows).toHaveBeenCalledWith(['window-7', 'window-6', 'window-5'])
  })

  it('plus otevře novou prázdnou záložku', async () => {
    mountTabs(1920)
    await nextTick()
    await wrapper!.get('[data-window-tab-new]').trigger('click')
    expect(mocks.openTab).toHaveBeenCalledWith()
  })

  it('jen aktivní záložka nabízí duplikaci své stránky', async () => {
    mountTabs(1920)
    await nextTick()
    const duplicates = wrapper!.findAll('[data-duplicate-window-tab]')
    expect(duplicates).toHaveLength(1)
    expect(duplicates[0]!.element.closest('[data-window-tab-item]')?.textContent).toContain('Účetní deník')
    await duplicates[0]!.trigger('click')
    expect(mocks.openTab).toHaveBeenCalledWith('window-3')
    expect(mocks.select).not.toHaveBeenCalled()
  })

  it('pravé tlačítko nabídne duplikaci a zavření ostatních záložek; Esc nabídku zavře', async () => {
    mountTabs(1920)
    await nextTick()
    await wrapper!.findAll('[data-window-tab-item]')[1]!.trigger('contextmenu', { clientX: 40, clientY: 40 })
    await nextTick()
    const menu = () => document.body.querySelector<HTMLElement>('[data-window-tab-menu]')
    expect(menu()).not.toBeNull()
    expect(document.activeElement).toBe(menu()!.querySelector('[data-tab-menu-duplicate]'))
    menu()!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }))
    await nextTick()
    expect(menu()).toBeNull()

    await wrapper!.findAll('[data-window-tab-item]')[1]!.trigger('contextmenu', { clientX: 40, clientY: 40 })
    await nextTick()
    menu()!.querySelector<HTMLButtonElement>('[data-tab-menu-close-others]')!.click()
    await nextTick()
    expect(mocks.closeWindows).toHaveBeenCalledWith(['window-3', 'window-1', 'window-4'])
    expect(menu()).toBeNull()

    await wrapper!.findAll('[data-window-tab-item]')[1]!.trigger('contextmenu', { clientX: 40, clientY: 40 })
    await nextTick()
    menu()!.querySelector<HTMLButtonElement>('[data-tab-menu-duplicate]')!.click()
    expect(mocks.openTab).toHaveBeenCalledWith('window-2')
  })

  it('ikona oken otevře přehled všech oken', async () => {
    const workspace = mountTabs(1920)
    await nextTick()
    await wrapper!.get('[data-window-tabs-switcher]').trigger('click')
    expect(workspace.windowSwitcherOpen).toBe(true)
  })

  it('křížek lištu skryje, zapamatuje si to a poradí, kde ji zapnout', async () => {
    const workspace = mountTabs(1920)
    await nextTick()
    await wrapper!.get('[data-window-tabs-hide]').trigger('click')
    expect(workspace.windowTabsHidden).toBe(true)
    expect(localStorage.getItem('myinvoice.workspace.window_tabs_hidden')).toBe('1')
    expect(mocks.info).toHaveBeenCalledWith('Skryto, zapnete přes formatted:alt+w.')
    await flushPromises()
    expect(wrapper!.find('nav').exists()).toBe(false)
    // Kořen zůstává, aby pracovní plocha zaznamenala změnu výšky.
    expect(wrapper!.find('[data-workspace-tabs]').exists()).toBe(true)
  })

  it('na telefonu s lištou ikon se nezobrazí', async () => {
    mountTabs(1920, { disabled: true })
    await nextTick()
    expect(wrapper!.find('nav').exists()).toBe(false)
  })
})
