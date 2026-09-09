import source from '@/pages/bank/StatementDetail.vue?raw'
import { runInNewContext } from 'node:vm'
import ts from 'typescript'
import { ref } from 'vue'
import { expect, it, vi } from 'vitest'

// Exercise the page's refresh with delayed API responses and real reactive refs.
function setup() {
  const start = source.indexOf('async function load(reset = true)')
  const end = source.indexOf('\nonMounted(', start)
  const code = ts.transpileModule(source.slice(start, end), { compilerOptions: { target: ts.ScriptTarget.ES2022 } }).outputText
  const context = {
    route: { params: { id: '7' } }, statusFilter: ref('unmatched'), postingFilter: ref(''),
    txPage: ref(3), txTotal: ref(120), txPages: ref(3), loading: ref(false), loadingMore: ref(false),
    loadGeneration: 0, refreshing: ref(false), pendingLoadMore: null,
    statement: ref({ transactions: [{ id: 99 }], matched_count: 0 }),
    bankActions: { setSuggestions: vi.fn() },
    bankApi: { get: vi.fn(), matchSuggestions: vi.fn().mockResolvedValue({ suggestions: [] }) },
  }
  const functions = runInNewContext(`${code}\n({ refresh: refreshTransactions, load })`, context) as {
    refresh: () => Promise<void>; load: (reset?: boolean) => Promise<void>
  }
  return { ...context, ...functions }
}
it('refreshes all loaded pages while keeping the list visible and server totals', async () => {
  const page = setup()
  let complete!: (value: unknown) => void
  page.bankApi.get.mockImplementation((_id, params) => params.page === 1
    ? new Promise(resolve => { complete = resolve })
    : Promise.resolve({ transactions: [{ id: params.page }], transactions_meta: { total: 120, pages: 3 } }))
  const pending = page.refresh()
  expect(page.loading.value).toBe(false)
  expect(page.statement.value.transactions[0].id).toBe(99)
  complete({ transactions: [{ id: 1 }], transactions_meta: { total: 119, pages: 3 }, matched_count: 5 })
  await pending
  expect(page.bankApi.get).toHaveBeenCalledTimes(3)
  expect(page.bankApi.get).toHaveBeenLastCalledWith(7, { page: 3, status: 'unmatched', posting_status: undefined })
  expect(page.statement.value.transactions.map(tx => tx.id)).toEqual([1, 2, 3])
  expect(page.txTotal.value).toBe(119)
  expect(page.statement.value.matched_count).toBe(5)
  expect(page.txPage.value).toBe(3)
})
it('clamps loaded pages when ignoring removes the last page', async () => {
  const page = setup()
  page.bankApi.get.mockResolvedValue({ transactions: [], transactions_meta: { total: 0, pages: 0 } })
  await page.refresh()
  expect(page.bankApi.get).toHaveBeenCalledTimes(1)
  expect(page.txPage.value).toBe(1)
  expect(page.txTotal.value).toBe(0)
})
it('does not overwrite a different statement opened while refresh was pending', async () => {
  const page = setup()
  let complete!: (value: unknown) => void
  page.bankApi.get.mockImplementation(() => new Promise(resolve => { complete = resolve }))
  const pending = page.refresh()
  page.route.params.id = '8'
  complete({ transactions: [{ id: 1 }], transactions_meta: { total: 1, pages: 1 } })
  await pending
  expect(page.statement.value.transactions[0].id).toBe(99)
})
it('does not append a stale load-more page after a mutation refresh included that page', async () => {
  const page = setup()
  let complete!: (value: unknown) => void
  page.bankApi.get.mockImplementationOnce(() => new Promise(resolve => { complete = resolve }))
  const loading = page.load(false)
  page.bankApi.get.mockImplementation((_id, params) => Promise.resolve({
    transactions: [{ id: params.page }], transactions_meta: { total: 180, pages: 4 },
  }))
  await page.refresh()
  complete({ transactions: [{ id: 999 }], transactions_meta: { total: 190, pages: 4 } })
  await loading
  expect(page.statement.value.transactions.map(tx => tx.id)).toEqual([1, 2, 3, 4])
  expect(page.txTotal.value).toBe(180)
  expect(page.loadingMore.value).toBe(false)
})
it('blocks load-more during refresh without changing the requested page', async () => {
  const page = setup()
  let complete!: (value: unknown) => void
  page.bankApi.get.mockImplementationOnce(() => new Promise(resolve => { complete = resolve }))
  page.bankApi.get.mockResolvedValue({ transactions: [{ id: 2 }], transactions_meta: { total: 100, pages: 2 } })
  const refresh = page.refresh()
  await page.load(false)
  expect(page.bankApi.get).toHaveBeenCalledTimes(1)
  expect(page.txPage.value).toBe(3)
  complete({ transactions: [{ id: 1 }], transactions_meta: { total: 100, pages: 2 } })
  await refresh
  expect(page.refreshing.value).toBe(false)
})
it('keeps a newer foreground loader active after an older refresh settles', async () => {
  const page = setup()
  let completeRefresh!: (value: unknown) => void
  let completeLoad!: (value: unknown) => void
  page.bankApi.get.mockImplementationOnce(() => new Promise(resolve => { completeRefresh = resolve }))
  page.bankApi.get.mockImplementationOnce(() => new Promise(resolve => { completeLoad = resolve }))
  const refresh = page.refresh()
  const load = page.load()
  completeRefresh({ transactions: [{ id: 999 }], transactions_meta: { total: 1, pages: 1 } })
  await refresh
  expect(page.loading.value).toBe(true)
  expect(page.statement.value.transactions[0].id).toBe(99)
  completeLoad({ transactions: [{ id: 1 }], transactions_meta: { total: 1, pages: 1 } })
  await load
  expect(page.loading.value).toBe(false)
  expect(page.statement.value.transactions[0].id).toBe(1)
})
it('shares a pending load-more request between the button and linked-transaction lookup', async () => {
  const page = setup()
  page.txPage.value = 1
  let complete!: (value: unknown) => void
  page.bankApi.get.mockImplementation(() => new Promise(resolve => { complete = resolve }))
  const first = page.load(false)
  let lookupCompleted = false
  const second = page.load(false).then(() => { lookupCompleted = true })
  await Promise.resolve()
  expect(page.bankApi.get).toHaveBeenCalledTimes(1)
  expect(page.txPage.value).toBe(2)
  expect(lookupCompleted).toBe(false)
  complete({ transactions: [{ id: 2 }], transactions_meta: { total: 120, pages: 3 } })
  await Promise.all([first, second])
  expect(page.statement.value.transactions.map(tx => tx.id)).toEqual([99, 2])
  expect(lookupCompleted).toBe(true)
  expect(page.loadingMore.value).toBe(false)
})
