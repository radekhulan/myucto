import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

const context = vi.hoisted(() => ({ requestToken: null as string | null }))
vi.mock('@/security/domainLoginContext', () => ({
  pendingDomainLoginRequestContext: () => context.requestToken
    ? { requestToken: context.requestToken, state: 's'.repeat(43) }
    : null,
}))

class TestChannel {
  static instance: TestChannel
  postMessage = vi.fn()
  listeners = new Set<(event: MessageEvent<unknown>) => void>()
  constructor(_name: string) { TestChannel.instance = this }
  addEventListener(_type: string, listener: (event: MessageEvent<unknown>) => void) { this.listeners.add(listener) }
  removeEventListener(_type: string, listener: (event: MessageEvent<unknown>) => void) { this.listeners.delete(listener) }
  receive(data: unknown) { this.listeners.forEach(listener => listener({ data } as MessageEvent<unknown>)) }
}

beforeEach(() => {
  vi.resetModules()
  context.requestToken = null
  vi.stubGlobal('BroadcastChannel', TestChannel)
})
afterEach(() => vi.unstubAllGlobals())

describe('oddělení událostí dočasného přihlášení', () => {
  it('hlavní okno ignoruje odhlášení dočasné relace', async () => {
    const { subscribeSessionEvents } = await import('../sessionChannel')
    const listener = vi.fn()
    const unsubscribe = subscribeSessionEvents(listener)
    TestChannel.instance.receive({ type: 'logout', domainLoginRequest: 'r'.repeat(43) })
    expect(listener).not.toHaveBeenCalled()
    TestChannel.instance.receive({ type: 'locked' })
    expect(listener).toHaveBeenCalledExactlyOnceWith('locked')
    unsubscribe()
    expect(TestChannel.instance.listeners.size).toBe(0)
  })

  it('dočasné okno přijímá jen události svého požadavku a sleduje změnu kontextu', async () => {
    const { subscribeSessionEvents } = await import('../sessionChannel')
    const listener = vi.fn()
    subscribeSessionEvents(listener)
    context.requestToken = 'r'.repeat(43)
    TestChannel.instance.receive({ type: 'logout' })
    TestChannel.instance.receive({ type: 'logout', domainLoginRequest: 'n'.repeat(43) })
    expect(listener).not.toHaveBeenCalled()
    TestChannel.instance.receive({ type: 'unlocked', domainLoginRequest: context.requestToken })
    expect(listener).toHaveBeenCalledExactlyOnceWith('unlocked')
    context.requestToken = null
    TestChannel.instance.receive({ type: 'logout', domainLoginRequest: 'r'.repeat(43) })
    expect(listener).toHaveBeenCalledTimes(1)
  })

  it('odesílá události s aktuálním rozsahem relace', async () => {
    const { broadcastSessionEvent } = await import('../sessionChannel')
    broadcastSessionEvent('locked')
    expect(TestChannel.instance.postMessage).toHaveBeenLastCalledWith({ type: 'locked' })
    context.requestToken = 'r'.repeat(43)
    broadcastSessionEvent('logout')
    expect(TestChannel.instance.postMessage).toHaveBeenLastCalledWith({
      type: 'logout', domainLoginRequest: context.requestToken,
    })
  })
})
