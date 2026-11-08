import { afterEach, describe, expect, it } from 'vitest'
import type { WorkspaceWindowState } from '@/stores/workspace'
import { readWindowTabsHidden, storeWindowTabsHidden, visibleWindowTabs, windowTabCapacity } from '../windowTabs'

function window(id: number, order?: number): WorkspaceWindowState {
  return { id: `window-${id}`, fullPath: `/page-${id}`, title: `Stránka ${id}`, paneId: null, ...(order === undefined ? {} : { order }) }
}

describe('windowTabCapacity', () => {
  it.each([
    [768, 2], // malý tablet
    [1024, 4],
    [1280, 5],
    [1536, 6],
    [1920, 8], // velký monitor
    [3840, 8],
  ])('lišta široká %i px ukáže %i záložek', (width, expected) => {
    expect(windowTabCapacity(width)).toBe(expected)
  })

  it('nikdy neklesne pod dvě záložky', () => {
    expect(windowTabCapacity(0)).toBe(2)
  })
})

describe('visibleWindowTabs', () => {
  it('vybere naposledy použitá okna a seřadí je podle otevření', () => {
    const mru = [window(5), window(2), window(7), window(1)]
    expect(visibleWindowTabs(mru, 3).map(tab => tab.window.id)).toEqual(['window-2', 'window-5', 'window-7'])
  })

  it('kliknutí na záložku pořadí lišty nezmění', () => {
    const before = visibleWindowTabs([window(3), window(2), window(1)], 3).map(tab => tab.key)
    const after = visibleWindowTabs([window(1), window(3), window(2)], 3).map(tab => tab.key)
    expect(after).toEqual(before)
  })

  it('obnovené okno drží místo původního okna', () => {
    const tabs = visibleWindowTabs([window(9, 2), window(3), window(1)], 3)
    expect(tabs.map(tab => tab.window.id)).toEqual(["window-1", "window-9", "window-3"])
  })

  it('okna jedné agendy sloučí do jedné záložky s naposledy použitým oknem', () => {
    const edit = { ...window(3), tab: '1:/invoices', fullPath: '/invoices/12/edit' }
    const detail = { ...window(2), tab: '1:/invoices', fullPath: '/invoices/12' }
    const list = { ...window(1), tab: '1:/invoices', fullPath: '/invoices' }
    const bank = { ...window(4), tab: '1:/bank' }
    const tabs = visibleWindowTabs([edit, bank, detail, list], 8)
    expect(tabs.map(tab => [tab.key, tab.window.id, tab.windows.length])).toEqual([
      ['1:/invoices', 'window-3', 3],
      ['1:/bank', 'window-4', 1],
    ])
  })

  it('záložka ukazuje okno z aktivního panelu, i když jiné okno téže záložky bylo použito později', () => {
    const list = { ...window(1), tab: 'a', paneId: 'primary' as const }
    const detail = { ...window(2), tab: 'a', paneId: 'secondary-2' as const }
    expect(visibleWindowTabs([detail, list], 2, 'primary')[0]!.window.id).toBe('window-1')
    expect(visibleWindowTabs([detail, list], 2, 'secondary-2')[0]!.window.id).toBe('window-2')
  })

  it('kapacita počítá záložky, ne okna', () => {
    const tabs = visibleWindowTabs([
      { ...window(5), tab: 'a' }, { ...window(4), tab: 'a' }, { ...window(3), tab: 'b' }, { ...window(2), tab: 'c' },
    ], 2)
    expect(tabs.map(tab => tab.key)).toEqual(['b', 'a'])
  })

  it('nemění pořadí seznamu oken ve store', () => {
    const mru = [window(2), window(1)]
    visibleWindowTabs(mru, 2)
    expect(mru.map(tab => tab.id)).toEqual(['window-2', 'window-1'])
  })
})

describe('skrytí záložek', () => {
  afterEach(() => localStorage.clear())

  it('pamatuje si volbu v prohlížeči', () => {
    expect(readWindowTabsHidden()).toBe(false)
    storeWindowTabsHidden(true)
    expect(readWindowTabsHidden()).toBe(true)
    storeWindowTabsHidden(false)
    expect(readWindowTabsHidden()).toBe(false)
  })
})
