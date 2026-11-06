import { beforeEach, expect, it, vi } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { authApi } from '@/api/auth'
import { useAuthStore } from '@/stores/auth'
import { useSessionSecurityStore } from '@/stores/sessionSecurity'
vi.mock('@/api/auth', () => ({ authApi: { sessionActivity: vi.fn(), sessionLock: vi.fn(), sessionStatus: vi.fn() } }))
vi.mock('@/security/sessionChannel', () => ({ broadcastSessionEvent: vi.fn(), subscribeSessionEvents: vi.fn() }))
beforeEach(() => {
  setActivePinia(createPinia())
  vi.spyOn(document, 'visibilityState', 'get').mockReturnValue('visible')
})
it.each(['activity', 'lock'])('po změně přihlašovacího toku nezapíše opožděný canonical %s do nové relace', async method => {
  const auth = useAuthStore()
  const security = useSessionSecurityStore()
  security.state = { session_state: 'active', lock_after_minutes: 5, unlock_methods: ['passkey'] } as never
  let finish!: (value: never) => void
  const response = new Promise<never>(resolve => { finish = resolve })
  vi.mocked(method === 'activity' ? authApi.sessionActivity : authApi.sessionLock).mockReturnValueOnce(response)
  const pending = method === 'activity' ? security.recordActivity() : security.lock()
  security.clear()
  auth.setSessionCsrfToken('new-flow-csrf')
  finish({ csrf_token: 'old-canonical-csrf', session_state: 'active', idle_expires_at: null } as never)
  await pending
  expect(auth.csrfToken).toBe('new-flow-csrf')
  expect(security.state).toBeNull()
})

it('opožděná hydratace profilu po změně toku neobnoví starý stav relace', async () => {
  const auth = useAuthStore()
  const security = useSessionSecurityStore()
  let finish!: (value: boolean) => void
  const hydration = new Promise<boolean>(resolve => { finish = resolve })
  const refresh = vi.spyOn(auth, 'refresh').mockReturnValueOnce(hydration)
  vi.mocked(authApi.sessionStatus).mockResolvedValueOnce({ csrf_token: 'old-canonical-csrf', session_state: 'active' } as never)
  const pending = security.refresh()
  await vi.waitFor(() => expect(refresh).toHaveBeenCalledOnce())
  security.clear()
  auth.setSessionCsrfToken('new-flow-csrf')
  auth.profileHydrated = true
  finish(true)
  await pending
  expect(auth.csrfToken).toBe('new-flow-csrf')
  expect(security.state).toBeNull()
})
