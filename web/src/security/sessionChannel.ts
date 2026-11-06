import { pendingDomainLoginRequestContext } from '@/security/domainLoginContext'

export type SessionChannelEvent = 'locked' | 'unlocked' | 'logout'

const CHANNEL_NAME = 'myinvoice-session-state'
let channel: BroadcastChannel | null | undefined

function getChannel(): BroadcastChannel | null {
  if (channel !== undefined) return channel
  channel = typeof BroadcastChannel === 'undefined'
    ? null
    : new BroadcastChannel(CHANNEL_NAME)
  return channel
}

export function broadcastSessionEvent(type: SessionChannelEvent): void {
  const request = pendingDomainLoginRequestContext()?.requestToken
  getChannel()?.postMessage({ type, ...(request ? { domainLoginRequest: request } : {}) })
}

export function subscribeSessionEvents(
  listener: (type: SessionChannelEvent) => void,
): () => void {
  const current = getChannel()
  if (!current) return () => undefined

  const receive = (event: MessageEvent<unknown>) => {
    // Dočasné ověření klienta nesmí odhlásit ani zamknout hlavní okno.
    // Rozsah čteme až při doručení, protože požadavek se může v okně změnit.
    const scope = (event.data as { domainLoginRequest?: unknown } | null)?.domainLoginRequest
    if ((scope ?? null) !== (pendingDomainLoginRequestContext()?.requestToken ?? null)) return
    const type = (event.data as { type?: unknown } | null)?.type
    if (type === 'locked' || type === 'unlocked' || type === 'logout') {
      listener(type)
    }
  }
  current.addEventListener('message', receive)
  return () => current.removeEventListener('message', receive)
}
