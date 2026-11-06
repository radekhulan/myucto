import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { nextTick } from 'vue'
import { createI18n } from 'vue-i18n'
import WorkspaceWindowSwitcher from '@/components/workspace/WorkspaceWindowSwitcher.vue'
import { useWorkspaceStore, type WorkspaceWindowState } from '@/stores/workspace'

const mocks = vi.hoisted(() => ({
  select: vi.fn(),
  closeWindow: vi.fn(),
  lockBodyScroll: vi.fn(),
  unlockBodyScroll: vi.fn(),
}))

vi.mock('@/workspace/windowRegistry', () => ({
  getWindowManager: () => ({ select: mocks.select, closeWindow: mocks.closeWindow }),
}))

vi.mock('@/composables/useKeyboardShortcuts', () => ({
  formatShortcut: (shortcut: string) => `formatted:${shortcut}`,
  useKeyboardShortcuts: () => ({
    comboFor: (id: string) => id === 'windows.recent' ? 'ctrl+tab' : '',
  }),
}))

vi.mock('@/utils/bodyScrollLock', () => ({
  lockBodyScroll: mocks.lockBodyScroll,
  unlockBodyScroll: mocks.unlockBodyScroll,
}))

const windowFixtures: WorkspaceWindowState[] = [
  { id: 'current', title: 'Vydaná faktura', fullPath: '/invoices/1', paneId: 'primary' },
  { id: 'previous', title: 'Dodavatelé', fullPath: '/clients?role=vendors', paneId: 'secondary-2' },
  { id: 'parked', title: 'Účetní deník', fullPath: '/accounting/journal', paneId: null },
]

const i18n = createI18n({
  legacy: false,
  locale: 'cs',
  messages: {
    cs: {
      common: { close: 'Zavřít' },
      workspace: {
        windows: 'Okna',
        find_window: 'Hledat okno',
        no_windows: 'Žádná okna',
        active_panel_number: 'Aktivní panel {number}',
        panel_number: 'Panel {number}',
        window_keyboard_hint: 'Šipkami vyberte okno.',
        recent_windows: 'Poslední okna',
        window_memory_hint: 'Okna si uchovávají obsah.',
      },
    },
  },
})

let wrapper: VueWrapper | null = null

function dialog(): HTMLElement {
  const element = document.body.querySelector<HTMLElement>('[role="dialog"]')
  if (!element) throw new Error('Přepínač oken není otevřený.')
  return element
}

function searchInput(): HTMLInputElement {
  const element = document.body.querySelector<HTMLInputElement>('[role="combobox"]')
  if (!element) throw new Error('Vyhledávací pole přepínače neexistuje.')
  return element
}

function options(): HTMLButtonElement[] {
  return [...document.body.querySelectorAll<HTMLButtonElement>('[role="option"]')]
}

function press(key: string, init: KeyboardEventInit = {}): KeyboardEvent {
  const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true, ...init })
  dialog().dispatchEvent(event)
  return event
}

async function mountSwitcher() {
  const pinia = createPinia()
  setActivePinia(pinia)
  const workspace = useWorkspaceStore()
  workspace.resetLayout(2, '/invoices/1')
  workspace.windows = windowFixtures.map(window => ({ ...window }))
  wrapper = mount(WorkspaceWindowSwitcher, { global: { plugins: [pinia, i18n] } })
  return workspace
}

async function openSwitcher(workspace: ReturnType<typeof useWorkspaceStore>): Promise<void> {
  workspace.windowSwitcherOpen = true
  await nextTick()
  await nextTick()
}

async function searchFor(query: string): Promise<void> {
  const input = searchInput()
  input.value = query
  input.dispatchEvent(new Event('input', { bubbles: true }))
  await nextTick()
}

describe('WorkspaceWindowSwitcher', () => {
  beforeEach(() => {
    mocks.select.mockReset().mockResolvedValue(undefined)
    mocks.closeWindow.mockReset().mockResolvedValue(undefined)
    mocks.lockBodyScroll.mockReset()
    mocks.unlockBodyScroll.mockReset()
    Object.defineProperty(Element.prototype, 'scrollIntoView', {
      configurable: true,
      value: vi.fn(),
    })
  })

  afterEach(() => {
    wrapper?.unmount()
    wrapper = null
    document.body.replaceChildren()
  })

  it('filtruje okna podle názvu i celé cesty', async () => {
    const workspace = await mountSwitcher()
    await openSwitcher(workspace)

    await searchFor('deník')
    expect(options()).toHaveLength(1)
    expect(options()[0]?.textContent).toContain('Účetní deník')

    await searchFor('role=vendors')
    expect(options()).toHaveLength(1)
    expect(options()[0]?.textContent).toContain('Dodavatelé')

    await searchFor('nenalezené')
    expect(options()).toHaveLength(0)
    expect(document.body.textContent).toContain('Žádná okna')
  })

  it('křížek i Delete zavřou okno bez jeho otevření', async () => {
    const workspace = await mountSwitcher()
    await openSwitcher(workspace)

    press('Delete')
    await flushPromises()
    expect(mocks.closeWindow).toHaveBeenCalledWith('previous')

    document.body.querySelectorAll<HTMLButtonElement>('[data-close-window]')[2]!.click()
    await flushPromises()
    expect(mocks.closeWindow).toHaveBeenLastCalledWith('parked')
    expect(workspace.windowSwitcherOpen).toBe(true)
    expect(mocks.select).not.toHaveBeenCalled()
  })

  it('předvolí předchozí okno a Enter je vybere', async () => {
    const workspace = await mountSwitcher()
    await openSwitcher(workspace)

    expect(searchInput().getAttribute('aria-activedescendant')).toBe('workspace-option-previous')
    expect(document.querySelector('#workspace-option-previous')?.getAttribute('aria-selected')).toBe('true')

    const event = press('Enter')
    await flushPromises()

    expect(event.defaultPrevented).toBe(true)
    expect(mocks.select).toHaveBeenCalledOnce()
    expect(mocks.select).toHaveBeenCalledWith('previous')
    expect(workspace.windowSwitcherOpen).toBe(false)
  })

  it('cyklí šipkami přes hranice a podporuje Home a End', async () => {
    const workspace = await mountSwitcher()
    await openSwitcher(workspace)

    press('ArrowDown')
    await nextTick()
    expect(searchInput().getAttribute('aria-activedescendant')).toBe('workspace-option-parked')

    press('ArrowDown')
    await nextTick()
    expect(searchInput().getAttribute('aria-activedescendant')).toBe('workspace-option-current')

    press('ArrowUp')
    await nextTick()
    expect(searchInput().getAttribute('aria-activedescendant')).toBe('workspace-option-parked')

    press('Home')
    await nextTick()
    expect(searchInput().getAttribute('aria-activedescendant')).toBe('workspace-option-current')

    press('End')
    await nextTick()
    expect(searchInput().getAttribute('aria-activedescendant')).toBe('workspace-option-parked')
  })

  it('Escape zavře přepínač a vrátí fokus do původního pole', async () => {
    const previousField = document.createElement('input')
    document.body.append(previousField)
    previousField.focus()
    const workspace = await mountSwitcher()
    await openSwitcher(workspace)

    expect(document.activeElement).toBe(searchInput())
    const event = press('Escape')
    await nextTick()

    expect(event.defaultPrevented).toBe(true)
    expect(workspace.windowSwitcherOpen).toBe(false)
    expect(document.activeElement).toBe(previousField)
    expect(mocks.lockBodyScroll).toHaveBeenCalledOnce()
    expect(mocks.unlockBodyScroll).toHaveBeenCalledOnce()
  })

  it('udrží Tab a Shift+Tab uvnitř dialogu', async () => {
    const workspace = await mountSwitcher()
    await openSwitcher(workspace)
    const focusable = [...dialog().querySelectorAll<HTMLElement>('input, button')]
    const first = focusable[0]!
    const last = focusable.at(-1)!

    first.focus()
    const backwards = press('Tab', { shiftKey: true })
    expect(backwards.defaultPrevented).toBe(true)
    expect(document.activeElement).toBe(last)

    const forwards = press('Tab')
    expect(forwards.defaultPrevented).toBe(true)
    expect(document.activeElement).toBe(first)
  })

  it('zavře přepínač ihned po volbě i během čekajícího přepnutí', async () => {
    let finishSelection: (() => void) | undefined
    mocks.select.mockReturnValue(new Promise<void>(resolve => { finishSelection = resolve }))
    const workspace = await mountSwitcher()
    await openSwitcher(workspace)

    document.querySelector<HTMLButtonElement>('#workspace-option-previous')!.click()
    await nextTick()

    expect(mocks.select).toHaveBeenCalledWith('previous')
    expect(workspace.windowSwitcherOpen).toBe(false)
    expect(document.body.querySelector('[role="dialog"]')).toBeNull()

    finishSelection?.()
    await flushPromises()
  })
})
