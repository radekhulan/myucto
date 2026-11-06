import { describe, expect, it, vi } from 'vitest'
import { createFrameSurface } from '../frameSurface'
import { readStorageQuotaHeaders, storageQuota } from '@/api/storageQuota'

describe('window frame shell bridges', () => {
  it('accepts only its own same-origin frame and preserves quota and navigation semantics', () => {
    const src = vi.spyOn(HTMLIFrameElement.prototype, 'src', 'set').mockImplementation(() => undefined)
    const activity = vi.fn()
    const demo = vi.fn()
    const globalNavigate = vi.fn()
    const mutation = vi.fn()
    window.addEventListener('myucto:window-activity', activity)
    window.addEventListener('myinvoice:demo-read-only', demo)
    const root = document.createElement('div')
    document.body.append(root)
    const surface = createFrameSurface({ id: 'window-test', supplierId: 11, to: '/invoices', root,
      appearance: () => ({ active: true, theme: 'light', locale: 'cs', globalShortcuts: [], csrfToken: 'synthetic-current-token' }),
      route: vi.fn(), navigate: vi.fn(), activate: vi.fn(), keyboard: vi.fn(), globalNavigate, mutation,
      error: vi.fn(), accessRevoked: vi.fn(), switchCompany: vi.fn() })
    const post = vi.spyOn(surface.iframe.contentWindow!, 'postMessage').mockImplementation(() => undefined)
    function message(type: string, extra = {}, origin = window.location.origin, source: MessageEventSource | null = surface.iframe.contentWindow) {
      window.dispatchEvent(new MessageEvent('message', { origin, source, data: { type, id: 'window-test', ...extra } }))
    }
    try {
      message('myucto-window-activity', {}, 'https://untrusted.example')
      message('myucto-window-activity', {}, window.location.origin, window)
      message('myucto-window-activity', { id: 'other-window' })
      expect(activity).not.toHaveBeenCalled()
      message('myucto-window-ready')
      expect(post).toHaveBeenCalledWith(expect.objectContaining({ type: 'myucto-window-init', csrfToken: 'synthetic-current-token' }), window.location.origin)
      message('myucto-window-activity')
      message('myucto-window-demo-read-only')
      expect(activity).toHaveBeenCalledTimes(1)
      expect(demo).toHaveBeenCalledTimes(1)
      message('myucto-window-global', { to: '/callback', mode: 'assign' })
      expect(globalNavigate).toHaveBeenCalledWith('/callback', 'assign')
      message('myucto-window-mutation', { resource: 'invoices', path: '/invoices/5' })
      message('myucto-window-mutation', { resource: '../invoices' })
      expect(mutation).toHaveBeenCalledExactlyOnceWith({ resource: 'invoices', path: '/invoices/5' })
      message('myucto-window-quota', { headers: { 'x-storage-quota-state': 'exhausted' }, trusted: false })
      expect(storageQuota.isCriticallyExhausted.value).toBe(true)
      message('myucto-window-quota', { headers: {}, trusted: false })
      expect(storageQuota.isCriticallyExhausted.value).toBe(true)
      message('myucto-window-quota', { headers: {}, trusted: true })
      expect(storageQuota.isCriticallyExhausted.value).toBe(false)
      surface.invalidate(11, 'invoices')
      expect(post).toHaveBeenLastCalledWith(expect.objectContaining({ invalidation: { supplierId: 11, resource: 'invoices' } }), window.location.origin)
      surface.invalidate(11, 'invoices', true)
      expect(post).toHaveBeenLastCalledWith(expect.objectContaining({ invalidation: { supplierId: 11, resource: 'invoices', self: true } }), window.location.origin)
    } finally {
      surface.destroy()
      root.remove()
      window.removeEventListener('myucto:window-activity', activity)
      window.removeEventListener('myinvoice:demo-read-only', demo)
      readStorageQuotaHeaders({})
      src.mockRestore()
      post.mockRestore()
    }
  })
})
