import { defineStore } from 'pinia'
import { ref } from 'vue'
import { advanceCanonicalDomainLoginSession, canonicalDomainLoginSessionCsrf } from '@/security/domainLogin'
import { authApi, type SessionState } from '@/api/auth'
import { cancelActiveWebAuthnCeremony, getCredential } from '@/security/webauthn'
import { useAuthStore } from './auth'
import { broadcastSessionEvent, subscribeSessionEvents } from '@/security/sessionChannel'

const REFRESH_COOLDOWN_MS = 750

interface RefreshOptions {
  force?: boolean
}

export const useSessionSecurityStore = defineStore('session-security', () => {
  const state = ref<SessionState | null>(null)
  const privacyCurtain = ref(false)
  const busy = ref(false)
  const error = ref('')
  let deadlineTimer: number | null = null
  let deadlineDueAt: number | null = null
  let deadlineElapsed = false
  let refreshPromise: Promise<boolean> | null = null
  let lastRefreshCompletedAt = 0
  let refreshGeneration = 0

  function clearDeadlineTimer() {
    if (deadlineTimer !== null) {
      window.clearTimeout(deadlineTimer)
      deadlineTimer = null
    }
    deadlineDueAt = null
  }

  function coverElapsedDeadline() {
    if (deadlineDueAt === null || Date.now() < deadlineDueAt) return
    deadlineDueAt = null
    deadlineElapsed = true
    privacyCurtain.value = true
  }

  function scheduleDeadline(next: SessionState) {
    clearDeadlineTimer()
    deadlineElapsed = false
    if (next.session_state !== 'active' || next.idle_expires_at === null) return
    const remaining = Date.parse(next.idle_expires_at) - Date.parse(next.server_time)
    if (!Number.isFinite(remaining) || remaining <= 0) {
      deadlineElapsed = true
      privacyCurtain.value = true
      void refresh({ force: true })
      return
    }
    deadlineDueAt = Date.now() + remaining
    deadlineTimer = window.setTimeout(() => {
      deadlineTimer = null
      deadlineDueAt = null
      deadlineElapsed = true
      privacyCurtain.value = true
      void refresh({ force: true })
    }, Math.min(remaining, 2_147_000_000))
  }

  function apply(next: SessionState) {
    state.value = next
    error.value = ''
    const auth = useAuthStore()
    auth.setSessionCsrfToken(next.csrf_token)
    auth.setLockedSession(next.session_state === 'locked' ? next : null)
    privacyCurtain.value = next.session_state === 'locked'
    scheduleDeadline(next)
  }

  function markLocked() {
    clearDeadlineTimer()
    deadlineElapsed = false
    cancelActiveWebAuthnCeremony()
    privacyCurtain.value = true
    if (state.value) state.value = { ...state.value, session_state: 'locked' }
  }

  async function performRefresh(generation: number): Promise<boolean> {
    try {
      const next = await authApi.sessionStatus()
      if (generation !== refreshGeneration) return false
      const auth = useAuthStore()
      if (next.session_state === 'active' && !auth.profileHydrated) {
        auth.setSessionCsrfToken(next.csrf_token)
        const refreshed = await auth.refresh()
        if (generation !== refreshGeneration) return false
        if (!refreshed) {
          error.value = 'session_status_failed'
          return false
        }
        if (!auth.profileHydrated) {
          if (auth.lockedSession) apply(auth.lockedSession)
          error.value = 'session_status_failed'
          return false
        }
      }
      apply(next)
      return true
    } catch {
      if (generation !== refreshGeneration) return false
      error.value = 'session_status_failed'
      if (state.value?.session_state === 'locked') {
        privacyCurtain.value = true
      } else if (!deadlineElapsed) {
        privacyCurtain.value = false
      }
      return false
    }
  }

  function refresh(options: RefreshOptions = {}): Promise<boolean> {
    coverElapsedDeadline()
    if (refreshPromise) return refreshPromise

    const now = Date.now()
    if (!options.force
      && state.value !== null
      && now - lastRefreshCompletedAt < REFRESH_COOLDOWN_MS
    ) {
      return Promise.resolve(error.value !== 'session_status_failed')
    }

    const generation = refreshGeneration
    const pending = performRefresh(generation)
    refreshPromise = pending
    void pending.finally(() => {
      if (refreshPromise === pending) {
        refreshPromise = null
        lastRefreshCompletedAt = Date.now()
      }
    })
    return pending
  }

  async function recordActivity() {
    if (document.visibilityState !== 'visible'
      || state.value?.session_state === 'locked'
      || state.value?.lock_after_minutes === 0
    ) return
    const generation = refreshGeneration
    try {
      const next = await authApi.sessionActivity()
      if (generation === refreshGeneration) apply(next)
    } catch {
      // 423 zpracuje interceptor; síťový výpadek nesmí lokálně odemknout.
    }
  }

  async function lock() {
    if (state.value?.session_state !== 'active'
      || !state.value.unlock_methods.includes('passkey')
    ) return
    const generation = refreshGeneration
    const next = await authApi.sessionLock()
    if (generation !== refreshGeneration) return
    apply(next)
    broadcastSessionEvent('locked')
  }

  async function unlock() {
    const generation = refreshGeneration
    busy.value = true
    error.value = ''
    try {
      const flow = await authApi.sessionUnlockOptions()
      if (generation !== refreshGeneration) return
      const credential = await getCredential(flow.public_key)
      if (generation !== refreshGeneration) return
      const previousCsrf = canonicalDomainLoginSessionCsrf()
      const unlocked = await authApi.sessionUnlockVerify(flow.flow_token, credential, previousCsrf ?? undefined)
      if (generation !== refreshGeneration) return
      if (previousCsrf) advanceCanonicalDomainLoginSession(previousCsrf, unlocked.csrf_token)
      const auth = useAuthStore()
      auth.setSessionCsrfToken(unlocked.csrf_token)
      const refreshed = await auth.refresh()
      if (generation !== refreshGeneration) return
      if (!refreshed || !auth.profileHydrated) {
        throw new Error('session_restore_failed')
      }
      apply(unlocked)
      broadcastSessionEvent('unlocked')
    } catch (e: any) {
      if (generation !== refreshGeneration) return
      error.value = e?.response?.data?.error?.message || 'unlock_failed'
      markLocked()
    } finally {
      if (generation === refreshGeneration) busy.value = false
    }
  }

  function clear() {
    refreshGeneration += 1
    refreshPromise = null
    lastRefreshCompletedAt = 0
    deadlineElapsed = false
    clearDeadlineTimer()
    state.value = null
    privacyCurtain.value = false
    busy.value = false
    error.value = ''
  }

  subscribeSessionEvents((type) => {
    if (type === 'locked') {
      markLocked()
    } else if (type === 'unlocked') {
      void refresh({ force: true })
    } else {
      clear()
      window.location.href = '/login'
    }
  })

  return {
    state,
    privacyCurtain,
    busy,
    error,
    apply,
    markLocked,
    refresh,
    recordActivity,
    lock,
    unlock,
    clear,
  }
})
