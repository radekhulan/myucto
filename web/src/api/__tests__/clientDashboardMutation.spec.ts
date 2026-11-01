import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../client'
import { postWindowFrameMessage } from '@/workspace/frameProtocol'

const state = vi.hoisted(() => ({ scope: null as null | { windowId: string; supplierId: number } }))

vi.mock('@/api/storageQuota', () => ({ readStorageQuotaHeaders: vi.fn() }))
vi.mock('@/security/domainLoginContext', () => ({ domainLoginRequestContext: () => null, domainLoginContinuationQuery: () => null }))
vi.mock('@/workspace/supplierContext', () => ({ getWindowSupplierScope: () => state.scope, requestSupplierId: () => null }))
vi.mock('@/workspace/frameProtocol', () => ({ postWindowFrameMessage: vi.fn() }))

describe('dashboard batch reads and cross-window refresh', () => {
  beforeEach(() => {
    state.scope = null
    vi.clearAllMocks()
    api.defaults.adapter = async config => ({ config, data: {}, status: 200, statusText: 'OK', headers: {} })
  })

  it('does not broadcast a business mutation for repeated dashboard reads', async () => {
    const changed = vi.fn()
    window.addEventListener('myucto:data-mutation', changed)
    try {
      for (const url of ['/dashboard-widgets/query', '/api/dashboard-widgets/query', '/api/v1/dashboard-widgets/query']) {
        await api.post(url, { board: 'portal', widgets: [] })
      }
      expect(changed).not.toHaveBeenCalled()
      await api.post('/invoices', {})
      expect(changed).toHaveBeenCalledTimes(1)
      expect(changed.mock.calls[0]![0].detail).toEqual({ resource: 'invoices', path: '/invoices' })
    } finally {
      window.removeEventListener('myucto:data-mutation', changed)
    }
  })

  it('continues to broadcast writes and similarly named paths', async () => {
    const changed = vi.fn()
    window.addEventListener('myucto:data-mutation', changed)
    try {
      await api.put('/user/preferences/table.invoices', {})
      await api.post('/dashboard-widgets/query/write', {})
      expect(changed).toHaveBeenCalledTimes(2)
    } finally {
      window.removeEventListener('myucto:data-mutation', changed)
    }
  })

  it('keeps the mutation path in window messages and ignores batch reads in a panel', async () => {
    state.scope = { windowId: 'synthetic-panel', supplierId: 7 }
    await api.post('/api/v1/dashboard-widgets/query', { board: 'portal', widgets: [] })
    expect(postWindowFrameMessage).not.toHaveBeenCalled()
    await api.put('/invoices/123', {})
    expect(postWindowFrameMessage).toHaveBeenCalledExactlyOnceWith({
      type: 'myucto-window-mutation', id: 'synthetic-panel', resource: 'invoices', path: '/invoices/123',
    })
  })
})
