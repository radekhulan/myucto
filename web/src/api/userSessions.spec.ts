import { beforeEach, describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn(), delete: vi.fn() }))
vi.mock('./client', () => ({ api }))
import { userSessionsApi } from './userSessions'

describe('API přihlášených relací superadmina', () => {
  beforeEach(() => {
    vi.resetAllMocks()
    api.get.mockResolvedValue({ data: { data: [], current_session: null, next_cursor: null, can_terminate: true } })
    api.post.mockResolvedValue({ data: { terminated: 2, current_session_revoked: false } })
    api.delete.mockResolvedValue({ data: { current_session_revoked: false } })
  })

  it('stránkuje pouze na administrátorské cestě a posílá abort signál', async () => {
    const signal = new AbortController().signal
    await userSessionsApi.list('next', signal)
    expect(api.get).toHaveBeenCalledExactlyOnceWith('/admin/user-sessions', { params: { cursor: 'next' }, signal })
  })

  it('odešle vybrané relace v jedné dávce a jednotlivou relaci v URL bezpečně zakóduje', async () => {
    await userSessionsApi.terminateSelected(['a'.repeat(64), 'b'.repeat(64)])
    expect(api.post).toHaveBeenCalledExactlyOnceWith('/admin/user-sessions/terminate', { ids: ['a'.repeat(64), 'b'.repeat(64)] })
    await userSessionsApi.terminate('a/b')
    expect(api.delete).toHaveBeenCalledExactlyOnceWith('/admin/user-sessions/a%2Fb')
  })
})
