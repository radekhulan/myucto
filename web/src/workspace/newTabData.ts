import { shallowRef } from 'vue'
import type { WorkspaceWindowState } from '@/stores/workspace'
import { NEW_TAB_PATH, windowTabGroups } from './windowTabs'

/**
 * Data pro stránku nové záložky. Menu i seznam oken drží shell; okno nové
 * záložky je dostane zprávou a výběr okna vrací zpět přes `selectOpenTab`.
 */

/** Položka hlavního menu pro hledání. */
export interface WorkspaceMenuItem {
  to: string
  label: string
  icon: string
  external?: boolean
}

/** Otevřená záložka v přehledu nové záložky. */
export interface WorkspaceOpenTab {
  windowId: string
  title: string
  subtitle: string
  supplierName: string
  windows: number
}

export const workspaceMenuItems = shallowRef<WorkspaceMenuItem[]>([])
export const workspaceOpenTabs = shallowRef<WorkspaceOpenTab[]>([])

let selectHandler: ((windowId: string) => void) | null = null

export function setOpenTabSelectHandler(handler: ((windowId: string) => void) | null): void {
  selectHandler = handler
}

export function selectOpenTab(windowId: string): void {
  selectHandler?.(windowId)
}

const MAX_MENU_ITEMS = 500
const MAX_OPEN_TABS = 20
const WINDOW_ID = /^window-\d{1,9}$/

export function plainMenuItems(items: readonly WorkspaceMenuItem[]): WorkspaceMenuItem[] {
  return items.map(item => ({ to: item.to, label: item.label, icon: item.icon, ...(item.external ? { external: true } : {}) }))
}

/** Záložky podle posledního použití, bez prázdných nových záložek. */
export function openTabsSummary(windows: readonly WorkspaceWindowState[]): WorkspaceOpenTab[] {
  return windowTabGroups(windows)
    .filter(tab => tab.window.fullPath.split(/[?#]/)[0] !== NEW_TAB_PATH)
    .slice(0, MAX_OPEN_TABS)
    .map(tab => ({
      windowId: tab.window.id,
      title: tab.window.title && tab.window.title !== tab.window.fullPath ? tab.window.title : tab.window.fullPath,
      subtitle: tab.window.subtitle ?? '',
      supplierName: tab.window.supplierName ?? '',
      windows: tab.windows.length,
    }))
}

/** Ze zprávy převezme jen platné položky; interní cesty relativní, externí jen přes http(s). */
export function sanitizeMenuItems(value: unknown): WorkspaceMenuItem[] {
  if (!Array.isArray(value)) return []
  return value.slice(0, MAX_MENU_ITEMS).flatMap(item => {
    if (!item || typeof item !== 'object') return []
    const { to, label, icon, external } = item as Record<string, unknown>
    if (typeof to !== 'string' || typeof label !== 'string' || typeof icon !== 'string') return []
    if (external !== undefined && typeof external !== 'boolean') return []
    const valid = external ? /^https?:\/\//i.test(to) : to.startsWith('/') && !to.startsWith('//')
    return valid ? [{ to, label, icon, ...(external ? { external: true } : {}) }] : []
  })
}

export function sanitizeOpenTabs(value: unknown): WorkspaceOpenTab[] {
  if (!Array.isArray(value)) return []
  return value.slice(0, MAX_OPEN_TABS).flatMap(item => {
    if (!item || typeof item !== 'object') return []
    const { windowId, title, subtitle, supplierName, windows } = item as Record<string, unknown>
    if (typeof windowId !== 'string' || !WINDOW_ID.test(windowId) || typeof title !== 'string') return []
    return [{
      windowId,
      title,
      subtitle: typeof subtitle === 'string' ? subtitle : '',
      supplierName: typeof supplierName === 'string' ? supplierName : '',
      windows: Number.isSafeInteger(windows) && (windows as number) > 0 ? windows as number : 1,
    }]
  })
}

export function isWorkspaceWindowId(value: unknown): value is string {
  return typeof value === 'string' && WINDOW_ID.test(value)
}
