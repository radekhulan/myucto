import { beforeEach, describe, expect, it, vi } from 'vitest'
import { kbPlusOnboardingApi } from '../kbPlusOnboarding'

const m = vi.hoisted(() => ({ get: vi.fn(), post: vi.fn() }))
vi.mock('../client', () => ({ api: m }))
beforeEach(() => { vi.clearAllMocks() })

describe('KB+ onboarding API', () => {
  it('reads account-scoped public status without sending credentials', async () => {
    const status = { provider: 'kb_plus', status: 'not_registered' }
    m.get.mockResolvedValue({ data: status })
    expect(await kbPlusOnboardingApi.status(3)).toBe(status)
    expect(m.get).toHaveBeenCalledExactlyOnceWith('/settings/bank-connections/3/kb-plus/onboarding')
    expect(m.post).not.toHaveBeenCalled()
  })
  it('sends registration credentials only in the POST body', async () => {
    const credentials = { certificate_p12: 'c3ludGhldGlj', certificate_password: 'synthetic-password' }
    const response = { status: 'registration_pending', redirect_url: 'https://login.kb.cz/autfe/ssologin?state=synthetic' }
    m.post.mockResolvedValue({ data: response })
    expect(await kbPlusOnboardingApi.start(3, credentials)).toBe(response)
    expect(m.post).toHaveBeenCalledExactlyOnceWith('/settings/bank-connections/3/kb-plus/onboarding', credentials)
    expect(m.get).not.toHaveBeenCalled()
  })
})
