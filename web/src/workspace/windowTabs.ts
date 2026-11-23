import type { WorkspaceWindowState } from '@/stores/workspace'

export const MIN_WINDOW_TABS = 2
/** Prázdná stránka, kterou otevírá + v liště záložek. */
export const NEW_TAB_PATH = '/new-tab'
export const MAX_WINDOW_TABS = 8
/** Šířka jedné záložky včetně mezery; z ní a šířky lišty vychází počet záložek. */
const TAB_SLOT_WIDTH = 230
/** Ikona všech oken a křížek na konci lišty. */
const TAB_CONTROLS_WIDTH = 80
const HIDDEN_STORAGE_KEY = 'myinvoice.workspace.window_tabs_hidden'

/** Malý tablet ukáže dvě záložky, velký monitor osm. */
export function windowTabCapacity(stripWidth: number): number {
  const fitting = Math.floor((stripWidth - TAB_CONTROLS_WIDTH) / TAB_SLOT_WIDTH)
  return Math.min(MAX_WINDOW_TABS, Math.max(MIN_WINDOW_TABS, fitting))
}

/** Okna se stejným klíčem tvoří jednu záložku. */
export function windowTabKey(window: WorkspaceWindowState): string {
  return window.tab ?? window.id
}

export function windowTabOrder(window: WorkspaceWindowState): number {
  return window.order ?? (Number(window.id.replace(/\D+/g, '')) || 0)
}

export interface WindowTab {
  key: string
  /** Okno, které záložka ukazuje a vybere: to v aktivním panelu, jinak naposledy použité. */
  window: WorkspaceWindowState
  /** Okna záložky podle posledního použití. */
  windows: WorkspaceWindowState[]
  order: number
}

/**
 * Okna jedné agendy (seznam, detail a úprava faktury) tvoří jednu záložku.
 * Záložky jsou seřazené podle posledního použití jako seznam oken.
 */
export function windowTabGroups(windows: readonly WorkspaceWindowState[], activePaneId: string | null = null): WindowTab[] {
  const groups = new Map<string, WindowTab>()
  for (const window of windows) {
    const key = windowTabKey(window)
    const group = groups.get(key)
    if (!group) {
      groups.set(key, { key, window, windows: [window], order: windowTabOrder(window) })
      continue
    }
    group.windows.push(window)
    group.order = Math.min(group.order, windowTabOrder(window))
    if (activePaneId !== null && window.paneId === activePaneId) group.window = window
  }
  return [...groups.values()]
}

/**
 * Lišta ukazuje naposledy použité záložky, ale v pořadí otevření. Podle
 * posledního použití by záložka po kliknutí uskočila pod kurzorem doleva;
 * takhle se lišta mění jen při otevření nebo zavření záložky a při výběru
 * záložky, která v liště nebyla.
 */
export function visibleWindowTabs(windows: readonly WorkspaceWindowState[], capacity: number, activePaneId: string | null = null): WindowTab[] {
  return windowTabGroups(windows, activePaneId).slice(0, Math.max(0, capacity)).sort((a, b) => a.order - b.order)
}

export function readWindowTabsHidden(): boolean {
  try { return localStorage.getItem(HIDDEN_STORAGE_KEY) === '1' } catch { return false }
}

export function storeWindowTabsHidden(hidden: boolean): void {
  try {
    if (hidden) localStorage.setItem(HIDDEN_STORAGE_KEY, '1')
    else localStorage.removeItem(HIDDEN_STORAGE_KEY)
  } catch { /* Bez úložiště platí volba jen do obnovení stránky. */ }
}
