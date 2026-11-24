import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { licenseApi } from '../license'

const { post } = vi.hoisted(() => ({ post: vi.fn() }))
vi.mock('../client', () => ({ api: { post } }))

const storageKey = 'myucto.license.pending_change_orders'

describe('sledování změny licence', () => {
  beforeEach(() => {
    post.mockReset()
    window.localStorage.removeItem(storageKey)
    vi.useFakeTimers()
  })

  afterEach(() => vi.useRealTimers())

  it('po odmítnuté kartě sleduje stávající objednávku až do doplacení', async () => {
    post.mockResolvedValueOnce({ data: { state: 'failed', applied: false } })
      .mockRejectedValueOnce({ response: { status: 503 } })
      .mockResolvedValueOnce({ data: { state: 'paid', applied: true } })

    const checkout = licenseApi.waitForCheckout('42')
    const resumed = licenseApi.resumePendingChanges()
    await vi.advanceTimersByTimeAsync(10_000)

    await expect(checkout).resolves.toMatchObject({ state: 'paid', applied: true })
    await expect(resumed).resolves.toEqual([{ state: 'paid', applied: true }])
    expect(post.mock.calls).toEqual(Array(3).fill(['/license/change-status', { order_id: '42' }]))
    expect(window.localStorage.getItem(storageKey)).toBe('[]')
  })

  it('načte i starší rozpracovanou objednávku s číselným ID', async () => {
    window.localStorage.setItem(storageKey, '[42]')
    post.mockResolvedValueOnce({ data: { state: 'paid', applied: true } })

    await expect(licenseApi.resumePendingChanges()).resolves.toEqual([{ state: 'paid', applied: true }])
    expect(post).toHaveBeenCalledWith('/license/change-status', { order_id: '42' })
  })

  it('zveřejní zaplacenou změnu, i když jiná objednávka stále čeká', async () => {
    window.localStorage.setItem(storageKey, '["42","43"]')
    let resolve!: (value: unknown) => void
    const promise = new Promise<unknown>((done) => { resolve = done })
    post.mockResolvedValueOnce({ data: { applied: true } }).mockReturnValueOnce(promise)
    const onApplied = vi.fn()

    const resumed = licenseApi.resumePendingChanges(onApplied)
    await vi.advanceTimersByTimeAsync(0)

    expect(onApplied).toHaveBeenCalledTimes(1)
    resolve({ data: { applied: false, state: 'failed' } })
    await resumed
  })
})
