import type { RouteLocationRaw } from 'vue-router'
import type { PaneId } from '@/stores/workspace'
import type { ShortcutAction } from '@/composables/useKeyboardShortcuts'
import { readStorageQuotaHeaders } from '@/api/storageQuota'

export interface FrameKeyboardEvent {
  key: string
  code: string
  altKey: boolean
  ctrlKey: boolean
  metaKey: boolean
  shiftKey: boolean
  repeat?: boolean
  isComposing?: boolean
  editable?: boolean
}

export interface FrameAppearance {
  active: boolean
  theme: 'dark' | 'light'
  locale: string
  globalShortcuts: string[]
  paneId?: PaneId | null
  canOpenRight?: boolean
  canOpenRightWithSplit?: boolean
  shortcutActions?: ShortcutAction[]
  csrfToken?: string
  accent?: string
  singlePanel?: boolean
}

export interface FrameMutation {
  resource: string
  /** Cesta API bez dotazu, podle ní `dataDependencies.ts` pozná dotčené agendy. */
  path: string
}

interface FrameSurfaceOptions {
  id: string
  supplierId: number
  to: RouteLocationRaw
  root: HTMLElement
  appearance: () => FrameAppearance
  route: (fullPath: string, title: string, subtitle: string | null) => void
  navigate: (to: RouteLocationRaw, newPane: boolean, placement?: 'beside' | 'right') => void
  activate: () => void
  history?: (direction: -1 | 1) => void
  keyboard: (event: FrameKeyboardEvent) => void
  globalNavigate: (to: RouteLocationRaw, mode?: 'assign' | 'replace') => void
  error: (error: unknown) => void
  accessRevoked: () => void
  switchCompany: (id: number, to?: RouteLocationRaw) => void
  mutation?: (change: FrameMutation) => void
}

export function createFrameSurface(options: FrameSurfaceOptions) {
  const iframe = document.createElement('iframe')
  iframe.className = 'block h-full w-full border-0 bg-neutral-50'
  iframe.title = options.id
  iframe.setAttribute('allow', 'clipboard-write; publickey-credentials-get; publickey-credentials-create')
  const url = new URL('/', window.location.origin)
  url.searchParams.set('__myucto_window', options.id)
  url.searchParams.set('__myucto_supplier', String(options.supplierId))
  let destroyed = false
  let initialized = false
  function send(type: string, extra: Record<string, unknown> = {}): void {
    iframe.contentWindow?.postMessage({ type, id: options.id, ...options.appearance(), ...extra }, window.location.origin)
  }
  function onMessage(event: MessageEvent): void {
    if (destroyed || event.origin !== window.location.origin || event.source !== iframe.contentWindow) return
    const data = event.data
    if (!data || typeof data !== 'object' || data.id !== options.id) return
    if (data.type === 'myucto-window-ready') {
      initialized = true
      send('myucto-window-init', { to: options.to })
    } else if (data.type === 'myucto-window-route' && typeof data.fullPath === 'string' && data.fullPath.startsWith('/') && !data.fullPath.startsWith('//')) {
      options.route(data.fullPath, typeof data.title === 'string' ? data.title : data.fullPath, typeof data.subtitle === 'string' ? data.subtitle : null)
    } else if (data.type === 'myucto-window-navigate' && (typeof data.to === 'string' || typeof data.to === 'object' && data.to !== null)) {
      if (data.placement === 'beside' || data.placement === 'right') options.navigate(data.to, data.newPane === true, data.placement)
      else options.navigate(data.to, data.newPane === true)
    } else if (data.type === 'myucto-window-activate') options.activate()
    else if (data.type === 'myucto-window-history' && (data.direction === -1 || data.direction === 1)) options.history?.(data.direction)
    else if (data.type === 'myucto-window-activity') window.dispatchEvent(new CustomEvent('myucto:window-activity'))
    else if (data.type === 'myucto-window-demo-read-only') window.dispatchEvent(new CustomEvent('myinvoice:demo-read-only'))
    else if (data.type === 'myucto-window-quota' && data.headers && typeof data.headers === 'object' && typeof data.trusted === 'boolean') readStorageQuotaHeaders(data.headers, { trusted: data.trusted })
    else if (data.type === 'myucto-window-mutation' && typeof data.resource === 'string' && /^[a-z0-9-]+$/.test(data.resource)) options.mutation?.({ resource: data.resource, path: typeof data.path === 'string' && data.path.startsWith('/') ? data.path : `/${data.resource}` })
    else if (data.type === 'myucto-window-key' && data.event && typeof data.event.key === 'string' && typeof data.event.code === 'string') {
      const keyboard = data.event
      options.keyboard({ key: keyboard.key, code: keyboard.code, altKey: keyboard.altKey === true, ctrlKey: keyboard.ctrlKey === true,
        metaKey: keyboard.metaKey === true, shiftKey: keyboard.shiftKey === true, repeat: keyboard.repeat === true, isComposing: keyboard.isComposing === true, editable: keyboard.editable === true })
    } else if (data.type === 'myucto-window-global' && (typeof data.to === 'string' || typeof data.to === 'object' && data.to !== null)) options.globalNavigate(data.to, data.mode === 'assign' || data.mode === 'replace' ? data.mode : undefined)
    else if (data.type === 'myucto-window-company' && Number.isSafeInteger(data.supplierId) && data.supplierId > 0) options.switchCompany(data.supplierId, data.to)
    else if (data.type === 'myucto-window-locked') window.dispatchEvent(new CustomEvent('myinvoice:session-locked'))
    else if (data.type === 'myucto-window-shortcuts-changed') window.dispatchEvent(new CustomEvent('myucto:shortcuts-changed'))
    else if (data.type === 'myucto-window-access-revoked' || data.type === 'myucto-window-error' && data.code === 'access-revoked') options.accessRevoked()
    else if (data.type === 'myucto-window-error') options.error(new Error(String(data.message ?? data.type)))
  }
  window.addEventListener('message', onMessage)
  iframe.src = url.toString()
  options.root.append(iframe)
  return {
    iframe,
    update: (refreshAuth = false) => { if (initialized) send('myucto-window-state', { refreshAuth }) },
    invalidate: (supplierId: number, resource: string, self = false) => { if (initialized) send('myucto-window-state', { invalidation: { supplierId, resource, ...(self ? { self } : {}) } }) },
    focus: () => iframe.contentWindow?.focus(),
    destroy: () => {
      destroyed = true
      window.removeEventListener('message', onMessage)
      iframe.remove()
    },
  }
}
