import { flushPromises } from '@vue/test-utils'
import source from '@/pages/bank/StatementDetail.vue?raw'
import { runInNewContext } from 'node:vm'
import ts from 'typescript'
import { nextTick, ref } from 'vue'
import { describe, expect, it, vi } from 'vitest'

// Stejně jako bankStatementRefresh.spec.ts spouštíme skutečné loadery stránky
// s řízenými odpověďmi API, bez kopírování navigační logiky do testu.
function setup(query: Record<string, string> = { tx: '42', detail: '1' }) {
  const start = source.indexOf('async function load(reset = true)')
  const end = source.indexOf('// --- PDF příloha', start)
  const code = ts.transpileModule(source.slice(start, end), {
    compilerOptions: { target: ts.ScriptTarget.ES2022 },
  }).outputText
  const mounted: (() => void)[] = []
  const unmounted: (() => void)[] = []
  const watchers: (() => void)[] = []
  const showConfirmed = ref(false)
  const confirmationFilter = ref('')
  const row = { scrollIntoView: vi.fn(), classList: { add: vi.fn(), remove: vi.fn() } }
  const context = {
    route: { params: { id: '7' }, query }, supplierStore: { currentSupplierId: 1 },
    statusFilter: ref(''), postingFilter: ref(''), txSort: { sort: ref(null), params: ref({}) },
    showConfirmed, confirmationFilter,
    noticeFilterParams: { get value() { return { show_confirmed: showConfirmed.value ? 1 : undefined, confirmation_state: confirmationFilter.value || undefined } } },
    txPage: ref(1), txTotal: ref(0), txPages: ref(1), loading: ref(false), loadingMore: ref(false),
    loadGeneration: 0, refreshing: ref(false), pendingLoadMore: null,
    statement: ref<{ transactions: { id: number }[] } | null>(null),
    bankActions: { setSuggestions: vi.fn(), textDetail: ref<{ id: number } | null>(null) },
    bankApi: { get: vi.fn(), matchSuggestions: vi.fn().mockResolvedValue({ suggestions: [] }) },
    paneDom: { querySelector: vi.fn(() => row) }, toast: { error: vi.fn() },
    apiErrorMessage: (error: Error) => error.message, nextTick, setTimeout: vi.fn(),
    onMounted: (callback: () => void) => mounted.push(callback),
    onUnmounted: (callback: () => void) => unmounted.push(callback),
    watch: (_source: unknown, callback: () => void) => watchers.push(callback),
  }
  const functions = runInNewContext(`${code}\n({ highlightLinkedTx })`, context) as {
    highlightLinkedTx: () => Promise<void>
  }
  const respond = (ids: number[], pages = 1) => ({
    transactions: ids.map(id => ({ id })), transactions_meta: { total: pages * 50, pages },
  })
  context.bankApi.get.mockResolvedValue(respond([42]))
  return { ...context, ...functions, row, respond,
    mount: () => mounted[0]!(), navigate: () => watchers[0]!(), unmount: () => unmounted.forEach(callback => callback()),
  }
}
async function settle() {
  await flushPromises()
  await flushPromises()
}

describe('statement transaction deep link', () => {
  it('opens the actual transaction detail and highlights its row', async () => {
    const page = setup()
    page.mount()
    await settle()
    expect(page.bankActions.textDetail.value?.id).toBe(42)
    expect(page.row.scrollIntoView).toHaveBeenCalled()
  })
  it('keeps the original tx-only link as highlight without opening a dialog', async () => {
    const page = setup({ tx: '42' })
    page.mount()
    await settle()
    expect(page.bankActions.textDetail.value).toBeNull()
    expect(page.row.scrollIntoView).toHaveBeenCalled()
  })
  it('finds later pages even if the transaction row is not rendered yet', async () => {
    const page = setup()
    page.bankApi.get.mockImplementation((_id, params) => Promise.resolve(
      page.respond(params.page === 1 ? [1] : [42], 2)))
    page.paneDom.querySelector.mockReturnValue(null as never)
    page.mount()
    await settle()
    expect(page.bankApi.get).toHaveBeenCalledTimes(2)
    expect(page.bankActions.textDetail.value?.id).toBe(42)
  })
  it('clears unrelated remembered filters for explicit detail', async () => {
    const page = setup()
    page.statusFilter.value = 'unmatched'
    page.postingFilter.value = 'unposted'
    page.mount()
    await settle()
    expect(page.bankApi.get).toHaveBeenCalledWith(7, { page: 1, status: undefined, posting_status: undefined })
    expect(page.bankActions.textDetail.value?.id).toBe(42)
  })
  it('preserves filters for a tx-only link', async () => {
    const page = setup({ tx: '42' })
    page.statusFilter.value = 'unmatched'
    page.mount()
    await settle()
    expect(page.bankApi.get).toHaveBeenCalledWith(7, { page: 1, status: 'unmatched', posting_status: undefined })
  })
  it('passes notice confirmation filters when loading the statement', async () => {
    const page = setup({})
    page.showConfirmed.value = true
    page.confirmationFilter.value = 'review'
    page.bankApi.get.mockResolvedValue(page.respond([42]))
    page.mount()
    await settle()
    expect(page.bankApi.get).toHaveBeenCalledWith(7, {
      show_confirmed: 1, confirmation_state: 'review', page: 1,
      status: undefined, posting_status: undefined,
    })
  })
  it('reports a page-load error once without retrying or opening a wrong transaction', async () => {
    const page = setup()
    page.bankApi.get.mockResolvedValueOnce(page.respond([1], 2)).mockRejectedValueOnce(new Error('failed'))
    page.mount()
    await settle()
    expect(page.bankApi.get).toHaveBeenCalledTimes(2)
    expect(page.toast.error).toHaveBeenCalledWith('failed')
    expect(page.bankActions.textDetail.value).toBeNull()
  })
  it('stops lookup when a concurrent filter change invalidates the next page', async () => {
    const page = setup()
    let resolve!: (value: unknown) => void
    page.bankApi.get.mockResolvedValueOnce(page.respond([1], 3))
      .mockImplementationOnce(() => new Promise(done => { resolve = done }))
    page.mount()
    await settle()
    page.statusFilter.value = 'ignored'
    resolve(page.respond([42], 3))
    await settle()
    expect(page.bankApi.get).toHaveBeenCalledTimes(2)
    expect(page.bankActions.textDetail.value).toBeNull()
  })
  it.each(['statement', 'supplier', 'transaction', 'detail', 'unmount'])('ignores pending detail after changing %s', async change => {
    const page = setup()
    let resolve!: (value: unknown) => void
    page.bankApi.get.mockResolvedValueOnce(page.respond([1], 2))
      .mockImplementationOnce(() => new Promise(done => { resolve = done }))
    page.mount()
    await settle()
    if (change === 'statement') page.route.params.id = '8'
    if (change === 'supplier') page.supplierStore.currentSupplierId = 2
    if (change === 'transaction') page.route.query.tx = '43'
    if (change === 'detail') page.route.query.detail = '0'
    if (change === 'unmount') page.unmount()
    resolve(page.respond([42], 2))
    await settle()
    expect(page.bankActions.textDetail.value).toBeNull()
    expect(page.row.scrollIntoView).not.toHaveBeenCalled()
  })
  it('closes old detail and loads the new statement when the component is recycled', async () => {
    const page = setup()
    page.mount()
    await settle()
    expect(page.bankActions.textDetail.value?.id).toBe(42)
    page.route.params.id = '8'
    page.route.query.tx = '43'
    page.bankApi.get.mockResolvedValue(page.respond([43]))
    page.navigate()
    expect(page.bankActions.textDetail.value).toBeNull()
    await settle()
    expect(page.bankActions.textDetail.value?.id).toBe(43)
    expect(page.bankApi.get).toHaveBeenLastCalledWith(8, { page: 1, status: undefined, posting_status: undefined })
  })
  it('only focuses another transaction of the already loaded statement', async () => {
    const page = setup()
    page.bankApi.get.mockResolvedValue(page.respond([42, 43]))
    page.mount()
    await settle()
    const loaded = page.statement.value
    expect(page.bankApi.get).toHaveBeenCalledTimes(1)
    page.row.scrollIntoView.mockClear()
    page.route.query.tx = '43'
    page.navigate()
    expect(page.statement.value).toBe(loaded)
    await settle()
    expect(page.bankApi.get).toHaveBeenCalledTimes(1)
    expect(page.bankApi.matchSuggestions).toHaveBeenCalledTimes(1)
    expect(page.statement.value).toBe(loaded)
    expect(page.bankActions.textDetail.value?.id).toBe(43)
    expect(page.row.scrollIntoView).toHaveBeenCalled()
  })
  it('closes the previous detail when a tx-only link targets the same statement', async () => {
    const page = setup()
    page.bankApi.get.mockResolvedValue(page.respond([42, 43]))
    page.mount()
    await settle()
    page.route.query = { tx: '43' }
    page.navigate()
    await settle()
    expect(page.bankApi.get).toHaveBeenCalledTimes(1)
    expect(page.bankActions.textDetail.value).toBeNull()
  })
  it('reloads the same statement when remembered filters would hide the linked detail', async () => {
    const page = setup()
    page.bankApi.get.mockResolvedValue(page.respond([42, 43]))
    page.mount()
    await settle()
    page.statusFilter.value = 'ignored'
    page.route.query.tx = '43'
    page.navigate()
    await settle()
    expect(page.bankApi.get).toHaveBeenCalledTimes(2)
    expect(page.bankApi.get).toHaveBeenLastCalledWith(7, { page: 1, status: undefined, posting_status: undefined })
    expect(page.bankActions.textDetail.value?.id).toBe(43)
  })
  it('does not open detail for a missing transaction', async () => {
    const page = setup()
    page.bankApi.get.mockResolvedValue(page.respond([1]))
    page.mount()
    await settle()
    expect(page.bankActions.textDetail.value).toBeNull()
    expect(page.bankApi.get).toHaveBeenCalledTimes(1)
  })
})
