import { describe, expect, it, vi } from 'vitest'

describe('useIsPhone', () => {
  it('měří šířku okna prohlížeče a nese ji atributem data-phone', async () => {
    vi.resetModules()
    const listeners: Record<string, (() => void)[]> = {}
    const state: Record<string, boolean> = { '(max-width: 767.98px)': true, '(max-width: 639.98px)': false }
    vi.stubGlobal('matchMedia', (query: string) => ({
      get matches() { return state[query] },
      addEventListener: (_: string, fn: () => void) => { (listeners[query] ??= []).push(fn) },
      removeEventListener: () => {},
    }))
    const { useIsPhone } = await import('../useIsPhone')
    const phone = useIsPhone()
    expect(phone.value).toBe(true)
    expect(document.documentElement.hasAttribute('data-phone')).toBe(true)
    expect(document.documentElement.hasAttribute('data-phone-sm')).toBe(false)
    state['(max-width: 767.98px)'] = false
    listeners['(max-width: 767.98px)'].forEach(fn => fn())
    expect(phone.value).toBe(false)
    expect(document.documentElement.hasAttribute('data-phone')).toBe(false)
    vi.unstubAllGlobals()
  })
})
