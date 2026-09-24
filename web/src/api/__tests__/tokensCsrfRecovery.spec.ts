import { beforeEach, describe, expect, it, vi } from 'vitest'
import { tokensApi, type CreateTokenPayload } from '@/api/tokens'

const mocks = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  setCsrfToken: vi.fn(),
}))

vi.mock('@/api/client', () => ({
  api: { get: mocks.get, post: mocks.post },
  setCsrfToken: mocks.setCsrfToken,
}))

const payload: CreateTokenPayload = {
  name: 'test integration',
  password: 'synthetic-password',
  supplier_id: 7,
  scope: 'read_write',
}

const csrfFailure = {
  response: { status: 403, data: { error: { code: 'csrf_failed' } } },
}

describe('vytvoření API tokenu po změně relace', () => {
  beforeEach(() => {
    mocks.get.mockReset()
    mocks.post.mockReset()
    mocks.setCsrfToken.mockReset()
  })

  it('po odmítnutí starého CSRF obnoví token relace a zopakuje vytvoření pro stejného uživatele', async () => {
    mocks.post.mockRejectedValueOnce(csrfFailure).mockResolvedValueOnce({
      data: { id: 3, token: 'synthetic-token', prefix: 'synthetic' },
    })
    mocks.get.mockResolvedValueOnce({
      data: { user: { id: 42 }, csrf_token: 'fresh-csrf' },
    })

    const result = await Reflect.apply(tokensApi.create, tokensApi, [payload, 42])

    expect(result.token).toBe('synthetic-token')
    expect(mocks.get).toHaveBeenCalledWith('/auth/me')
    expect(mocks.setCsrfToken).toHaveBeenCalledWith('fresh-csrf')
    expect(mocks.post).toHaveBeenCalledTimes(2)
    expect(mocks.post).toHaveBeenNthCalledWith(2, '/auth/tokens', payload)
  })

  it('nevydá token pod jiným účtem po přepnutí relace', async () => {
    mocks.post.mockRejectedValueOnce(csrfFailure)
    mocks.get.mockResolvedValueOnce({
      data: { user: { id: 99 }, csrf_token: 'other-user-csrf' },
    })

    await expect(Reflect.apply(tokensApi.create, tokensApi, [payload, 42])).rejects.toBe(csrfFailure)
    expect(mocks.post).toHaveBeenCalledTimes(1)
  })

  it('neopakuje požadavek při jiné chybě', async () => {
    const forbidden = { response: { status: 403, data: { error: { code: 'forbidden_permission' } } } }
    mocks.post.mockRejectedValueOnce(forbidden)

    await expect(Reflect.apply(tokensApi.create, tokensApi, [payload, 42])).rejects.toBe(forbidden)
    expect(mocks.get).not.toHaveBeenCalled()
    expect(mocks.post).toHaveBeenCalledTimes(1)
  })
})
