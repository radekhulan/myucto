import type { RouteLocationRaw } from 'vue-router'
import type { ShortcutAction } from '@/composables/useKeyboardShortcuts'

export type WindowFrameTheme = 'light' | 'dark'
export type WindowFrameLocale = 'cs' | 'en'
export type WindowFramePaneId = 'primary' | 'secondary-2' | 'secondary-3'

export interface WindowFrameKeyboardEvent {
  key: string
  code: string
  altKey: boolean
  ctrlKey: boolean
  metaKey: boolean
  shiftKey: boolean
  isComposing: boolean
  repeat: boolean
  editable?: boolean
}

export interface WindowFrameBootstrap {
  id: string
  supplierId: number
}

export interface WindowFrameInitMessage {
  type: 'myucto-window-init'
  id: string
  to: RouteLocationRaw
  active: boolean
  theme: WindowFrameTheme
  locale: WindowFrameLocale
  globalShortcuts?: string[]
  paneId?: WindowFramePaneId | null
  canOpenRight?: boolean
  canOpenRightWithSplit?: boolean
  shortcutActions?: ShortcutAction[]
  csrfToken?: string
  accent?: string
  singlePanel?: boolean
}

export interface WindowFrameStateMessage {
  type: 'myucto-window-state'
  id: string
  active: boolean
  theme: WindowFrameTheme
  locale: WindowFrameLocale
  globalShortcuts?: string[]
  refreshAuth?: boolean
  paneId?: WindowFramePaneId | null
  canOpenRight?: boolean
  canOpenRightWithSplit?: boolean
  shortcutActions?: ShortcutAction[]
  csrfToken?: string
  accent?: string
  singlePanel?: boolean
  invalidation?: { supplierId: number; resource: string; self?: boolean }
}

export type WindowFrameParentMessage = WindowFrameInitMessage | WindowFrameStateMessage

export type WindowFrameChildMessage =
  | { type: 'myucto-window-ready'; id: string }
  | { type: 'myucto-window-route'; id: string; fullPath: string; title: string; subtitle?: string | null }
  | { type: 'myucto-window-navigate'; id: string; to: RouteLocationRaw; newPane?: boolean; placement?: 'beside' | 'right' }
  | { type: 'myucto-window-key'; id: string; event: WindowFrameKeyboardEvent }
  | { type: 'myucto-window-activate'; id: string }
  | { type: 'myucto-window-history'; id: string; direction: -1 | 1 }
  | { type: 'myucto-window-activity'; id: string }
  | { type: 'myucto-window-demo-read-only'; id: string }
  | { type: 'myucto-window-quota'; id: string; headers: Record<string, string | null>; trusted: boolean }
  | { type: 'myucto-window-mutation'; id: string; resource: string; path?: string }
  | { type: 'myucto-window-global'; id: string; to: RouteLocationRaw; mode?: 'assign' | 'replace' }
  | { type: 'myucto-window-company'; id: string; supplierId: number; to?: RouteLocationRaw }
  | { type: 'myucto-window-locked'; id: string }
  | { type: 'myucto-window-shortcuts-changed'; id: string }
  | { type: 'myucto-window-error'; id: string; code: 'access-revoked' | 'bootstrap-failed' | 'locale-failed' }

const FRAME_ID = /^[a-zA-Z0-9_-]{1,80}$/

export function parseWindowFrameBootstrap(search: string, embedded: boolean): WindowFrameBootstrap | null {
  if (!embedded) return null
  const params = new URLSearchParams(search)
  const id = params.get('__myucto_window') ?? ''
  const supplierId = Number(params.get('__myucto_supplier'))
  if (!FRAME_ID.test(id) || !Number.isSafeInteger(supplierId) || supplierId <= 0) return null
  return { id, supplierId }
}

export function isWindowFrameParentMessage(value: unknown, id: string): value is WindowFrameParentMessage {
  if (!value || typeof value !== 'object') return false
  const message = value as Record<string, unknown>
  if (message.id !== id || typeof message.active !== 'boolean'
    || (message.theme !== 'light' && message.theme !== 'dark')
    || (message.locale !== 'cs' && message.locale !== 'en')) return false
  if (message.globalShortcuts !== undefined
    && (!Array.isArray(message.globalShortcuts) || !message.globalShortcuts.every(item => typeof item === 'string'))) return false
  if (message.paneId !== undefined && message.paneId !== null
    && !['primary', 'secondary-2', 'secondary-3'].includes(String(message.paneId))) return false
  if (message.canOpenRightWithSplit !== undefined && typeof message.canOpenRightWithSplit !== 'boolean') return false
  if (message.canOpenRight !== undefined && typeof message.canOpenRight !== 'boolean') return false
  if (message.csrfToken !== undefined && typeof message.csrfToken !== 'string') return false
  if (message.singlePanel !== undefined && typeof message.singlePanel !== 'boolean') return false
  if (message.accent !== undefined && (typeof message.accent !== 'string' || !['primary', 'primaryDeep', 'warning', 'success', 'danger', 'accent', 'teal', 'payroll', 'neutral'].includes(message.accent))) return false
  if (message.invalidation !== undefined) {
    const invalidation = message.invalidation as Record<string, unknown> | null
    if (!invalidation || typeof invalidation !== 'object' || !Number.isSafeInteger(invalidation.supplierId)
      || typeof invalidation.resource !== 'string' || !/^[a-z0-9-]+$/.test(invalidation.resource)
      || (invalidation.self !== undefined && typeof invalidation.self !== 'boolean')) return false
  }
  if (message.type === 'myucto-window-init') {
    return typeof message.to === 'string' || (!!message.to && typeof message.to === 'object')
  }
  return message.type === 'myucto-window-state'
    && (message.refreshAuth === undefined || typeof message.refreshAuth === 'boolean')
}

export function isWindowFrameChildMessage(value: unknown): value is WindowFrameChildMessage {
  if (!value || typeof value !== 'object') return false
  const message = value as Record<string, unknown>
  if (typeof message.id !== 'string' || !FRAME_ID.test(message.id)) return false
  return [
    'myucto-window-ready',
    'myucto-window-route',
    'myucto-window-navigate',
    'myucto-window-key',
    'myucto-window-activate',
    'myucto-window-history',
    'myucto-window-activity',
    'myucto-window-demo-read-only',
    'myucto-window-quota',
    'myucto-window-mutation',
    'myucto-window-global',
    'myucto-window-company',
    'myucto-window-locked',
    'myucto-window-shortcuts-changed',
    'myucto-window-error',
  ].includes(String(message.type))
}

export function postWindowFrameMessage(message: WindowFrameChildMessage): void {
  if (window.parent === window) return
  window.parent.postMessage(message, window.location.origin)
}
