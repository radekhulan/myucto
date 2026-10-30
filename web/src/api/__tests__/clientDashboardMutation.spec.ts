import { beforeEach, describe, expect, it, vi } from 'vitest'
import { api } from '../client'

vi.mock('@/api/storageQuota', () => ({ readStorageQuotaHeaders: vi.fn() }))
vi.mock('@/security/domainLoginContext', () => ({ domainLoginRequestContext: () => null, domainLoginContinuationQuery: () => null }))
vi.mock('@/workspace/supplierContext', () => ({ getWindowSupplierScope: () => null, requestSupplierId: () => null }))
vi.mock('@/workspace/frameProtocol', () => ({ postWindowFrameMessage: vi.fn() }))

describe('dashboard batch reads and cross-window refresh', () => {
  beforeEach(() => {
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
      expect(changed.mock.calls[0]![0].detail).toEqual({ resource: 'invoices' })
    } finally {
      window.removeEventListener('myucto:data-mutation', changed)
    }
  })

  it('continues to broadcast layout saves and similarly named writes', async () => {
    const changed = vi.fn()
    window.addEventListener('myucto:data-mutation', changed)
    try {
      await api.put('/user/dashboard-layouts/portal', {})
      await api.post('/dashboard-widgets/query/write', {})
      expect(changed).toHaveBeenCalledTimes(2)
    } finally {
      window.removeEventListener('myucto:data-mutation', changed)
    }
  })
})
