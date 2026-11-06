import { afterEach, describe, expect, it, vi } from 'vitest'
import type { AxiosAdapter } from 'axios'
import { api } from '../client'

const adapter: AxiosAdapter = async config => ({ data: {}, status: 200, statusText: 'OK', headers: {}, config })

describe('ohlášení zápisu správci oken', () => {
  const listener = vi.fn()
  afterEach(() => {
    window.removeEventListener('myucto:data-mutation', listener)
    listener.mockClear()
  })

  it('zápis ohlásí i s cestou bez dotazu, čtení neohlásí', async () => {
    window.addEventListener('myucto:data-mutation', listener)
    await api.get('/invoices/5', { adapter })
    expect(listener).not.toHaveBeenCalled()
    await api.post('/accounting/journal/9/reverse?force=1', {}, { adapter })
    expect((listener.mock.calls[0]![0] as CustomEvent).detail).toEqual({ resource: 'accounting', path: '/accounting/journal/9/reverse' })
  })
})
