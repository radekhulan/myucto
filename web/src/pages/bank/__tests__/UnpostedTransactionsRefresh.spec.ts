import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { nextTick } from 'vue'

const mocks = vi.hoisted(() => ({
  list: vi.fn(), suggestions: vi.fn(), setSuggestions: vi.fn(),
  refresh: null as null | (() => Promise<void>),
}))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({ currentSupplierId: 1 }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ hasCommercialFeatures: true }) }))
vi.mock('@/api/bankPosting', () => ({ bankPostingApi: { listUnposted: mocks.list } }))
vi.mock('@/api/bank', () => ({ bankApi: { matchSuggestions: mocks.suggestions } }))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(), useI18n: () => ({ t: (key: string) => key }),
}))
vi.mock('@/composables/useBankTransactionActions', () => ({
  useBankTransactionActions: (options: { refresh: () => Promise<void> }) => {
    mocks.refresh = options.refresh
    return { setSuggestions: mocks.setSuggestions }
  },
}))
import UnpostedTransactions from '../UnpostedTransactions.vue'

const result = (id: number, total = 1) => ({
  items: [{ id, statement_id: id }], total, per_page: 50, years: [2026], accounts: [],
})
function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: Error) => void
  const promise = new Promise<T>((done, fail) => { resolve = done; reject = fail })
  return { promise, resolve, reject }
}
async function open() {
  const wrapper = mount({ ...UnpostedTransactions, render: () => null })
  await flushPromises()
  const vm = wrapper.vm as unknown as {
    items: { id: number }[]; total: number; loading: boolean; year: number | null; search: string; page: number; loadingMore: boolean; loadMore: () => Promise<void>
  }
  return { wrapper, vm }
}

describe('unposted transaction refresh ordering', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    vi.resetAllMocks()
    sessionStorage.clear()
    mocks.list.mockResolvedValue(result(1))
    mocks.suggestions.mockResolvedValue({ suggestions: [] })
  })
  afterEach(() => { vi.useRealTimers() })

  it('keeps filtered results when an earlier background refresh completes last', async () => {
    const { wrapper, vm } = await open()
    const old = deferred<ReturnType<typeof result>>()
    mocks.list.mockReturnValueOnce(old.promise)
    const refresh = mocks.refresh!()
    mocks.list.mockResolvedValueOnce(result(2, 2))
    vm.year = 2026
    await flushPromises()
    old.resolve(result(3, 30))
    await refresh
    expect(vm.items).toEqual([{ id: 2, statement_id: 2 }])
    expect(vm.total).toBe(2)
    wrapper.unmount()
  })

  it('invalidates a background response immediately while search is still debouncing', async () => {
    const { wrapper, vm } = await open()
    const old = deferred<ReturnType<typeof result>>()
    mocks.list.mockReturnValueOnce(old.promise)
    const refresh = mocks.refresh!()
    vm.search = 'synthetic'
    await nextTick()
    old.resolve(result(3))
    await refresh
    expect(vm.items[0]?.id).toBe(1)
    mocks.list.mockResolvedValueOnce(result(2))
    await vi.advanceTimersByTimeAsync(300)
    await flushPromises()
    expect(vm.items[0]?.id).toBe(2)
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ q: 'synthetic' }))
    wrapper.unmount()
  })

  it('does not clear the foreground loading indicator when an old background request settles', async () => {
    const { wrapper, vm } = await open()
    const old = deferred<ReturnType<typeof result>>()
    const current = deferred<ReturnType<typeof result>>()
    mocks.list.mockReturnValueOnce(old.promise).mockReturnValueOnce(current.promise)
    const refresh = mocks.refresh!()
    vm.year = 2026
    await nextTick()
    old.resolve(result(3))
    await refresh
    expect(vm.loading).toBe(true)
    current.resolve(result(2))
    await flushPromises()
    expect(vm.loading).toBe(false)
    wrapper.unmount()
  })

  it('keeps current match suggestions when an older suggestion batch arrives last', async () => {
    const { wrapper, vm } = await open()
    const old = deferred<{ suggestions: { bank_transaction_id: number; status: string }[] }>()
    mocks.suggestions.mockReturnValueOnce(old.promise)
    await mocks.refresh!()
    mocks.list.mockResolvedValueOnce(result(2))
    const current = { bank_transaction_id: 2, status: 'pending' }
    mocks.suggestions.mockResolvedValueOnce({ suggestions: [current] })
    vm.year = 2026
    await flushPromises()
    old.resolve({ suggestions: [{ bank_transaction_id: 1, status: 'pending' }] })
    await flushPromises()
    expect(mocks.setSuggestions).toHaveBeenLastCalledWith(new Map([[2, current]]))
    wrapper.unmount()
  })

  it('retains silent pagination fallback after the final row on a page disappears', async () => {
    const { wrapper, vm } = await open()
    vm.page = 2
    await flushPromises()
    const current = deferred<ReturnType<typeof result>>()
    mocks.list.mockResolvedValueOnce({ ...result(1), items: [], total: 50 }).mockReturnValueOnce(current.promise)
    await mocks.refresh!()
    await nextTick()
    expect(vm.page).toBe(1)
    expect(vm.loading).toBe(false)
    current.resolve(result(2, 50))
    await flushPromises()
    expect(vm.items[0]?.id).toBe(2)
    wrapper.unmount()
  })

  it('appends the next page only after a successful response and rejects stale append results', async () => {
    mocks.list.mockResolvedValueOnce(result(1, 100))
    const { wrapper, vm } = await open()
    const old = deferred<ReturnType<typeof result>>()
    mocks.list.mockReturnValueOnce(old.promise)
    const append = vm.loadMore()
    expect(vm.page).toBe(1)
    expect(vm.loadingMore).toBe(true)
    mocks.list.mockResolvedValueOnce(result(2))
    vm.year = 2026
    await flushPromises()
    old.resolve(result(3, 100))
    await append
    expect(vm.items).toEqual([{ id: 2, statement_id: 2 }])
    expect(vm.page).toBe(1)
    expect(vm.loadingMore).toBe(false)
    wrapper.unmount()
  })

  it('keeps the next page available after a load-more failure', async () => {
    mocks.list.mockResolvedValueOnce(result(1, 100))
    const { wrapper, vm } = await open()
    mocks.list.mockRejectedValueOnce(new Error('Synthetic append failure'))
    await expect(vm.loadMore()).rejects.toThrow('Synthetic append failure')
    expect(vm.page).toBe(1)
    expect(vm.items[0]?.id).toBe(1)
    mocks.list.mockResolvedValueOnce(result(2, 100))
    await vm.loadMore()
    expect(vm.page).toBe(2)
    expect(vm.items.map(item => item.id)).toEqual([1, 2])
    wrapper.unmount()
  })

  it('refreshes pagination when the remaining page disappears during append', async () => {
    mocks.list.mockResolvedValueOnce(result(1, 150))
    const { wrapper, vm } = await open()
    mocks.list.mockResolvedValueOnce(result(2, 150))
    await vm.loadMore()
    mocks.list.mockResolvedValueOnce({ ...result(1, 100), items: [] })
      .mockResolvedValueOnce(result(2, 100))
      .mockResolvedValueOnce(result(1, 100))
    await vm.loadMore()
    await flushPromises()
    expect(vm.page).toBe(2)
    expect(vm.total).toBe(100)
    const calls = mocks.list.mock.calls.length
    await vm.loadMore()
    expect(mocks.list).toHaveBeenCalledTimes(calls)
    wrapper.unmount()
  })

  it('preserves rows and propagates a failed background refresh', async () => {
    const { wrapper, vm } = await open()
    const error = new Error('Synthetic failure')
    mocks.list.mockRejectedValueOnce(error)
    await expect(mocks.refresh!()).rejects.toBe(error)
    expect(vm.items[0]?.id).toBe(1)
    expect(vm.loading).toBe(false)
    wrapper.unmount()
  })
})
