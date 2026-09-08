import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import { createMemoryHistory, createRouter } from 'vue-router'
import { nextTick } from 'vue'

const mocks = vi.hoisted(() => ({
  list: vi.fn(),
  defaultQuery: null as Record<string, string> | null,
  apply: null as ((query: Record<string, string>) => void) | null,
}))
vi.mock('@/api/invoices', () => ({ invoicesApi: { listGrouped: mocks.list } }))
vi.mock('@/api/clients', () => ({ clientsApi: { list: async () => ({ data: [] }) } }))
vi.mock('@/api/codebooks', () => ({ codebooksApi: { currencies: async () => [] } }))
vi.mock('@/api/revenueCategories', () => ({ revenueCategoriesApi: { list: async () => [] } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({}) }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => ({}) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('@/composables/useYearOptions', () => ({ useYearOptions: () => [] }))
vi.mock('@/composables/useListKeyboard', () => ({ useListKeyboard: () => ({ activeIndex: -1 }) }))
vi.mock('@/composables/useTablePrefs', () => ({ useTablePrefs: () => ({}) }))
vi.mock('@/composables/useSavedFilters', () => ({
  useSavedFilters: (_page: string, opts: { applyQuery: (query: Record<string, string>) => void }) => {
    mocks.apply = opts.applyQuery
    return {
      applyDefaultIfAny: async () => {
        if (!mocks.defaultQuery) return false
        opts.applyQuery(mocks.defaultQuery)
        return true
      },
    }
  },
  savedFilterTone: () => 'neutral',
}))
vi.mock('vue-i18n', async importOriginal => ({
  ...await importOriginal<typeof import('vue-i18n')>(),
  useI18n: () => ({ t: (key: string) => key }),
}))
import InvoiceList from '../InvoiceList.vue'

async function open(query: Record<string, string> = {}) {
  const router = createRouter({ history: createMemoryHistory(), routes: [{ path: '/invoices', component: { render: () => null } }] })
  await router.push({ path: '/invoices', query })
  const wrapper = mount({ ...InvoiceList, render: () => null }, { global: { plugins: [router] } })
  await flushPromises()
  return { wrapper, router }
}

describe('invoice list loading', () => {
  beforeEach(() => {
    vi.useFakeTimers()
    mocks.defaultQuery = null
    mocks.list.mockReset().mockResolvedValue({ data: [], meta: { total: 0, pages: 1 } })
  })
  afterEach(() => { vi.useRealTimers() })

  it.each<Record<string, string>>([{}, { q: 'synthetic', revenue_category: '7', month: '2' }])('hydrates URL once without a delayed search request: %j', async query => {
    const { wrapper } = await open(query)
    await vi.advanceTimersByTimeAsync(350)
    expect(mocks.list).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('applies a saved default and another saved view once each', async () => {
    mocks.defaultQuery = { q: 'default', revenue_category: '7' }
    const { wrapper } = await open()
    await vi.advanceTimersByTimeAsync(350)
    expect(mocks.list).toHaveBeenCalledTimes(1)
    mocks.apply!({ q: 'view', booked: '0' })
    await flushPromises()
    await vi.advanceTimersByTimeAsync(350)
    expect(mocks.list).toHaveBeenCalledTimes(2)
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ q: 'view', booked: '0' }))
    wrapper.unmount()
  })

  it('preserves a saved month when replacing a date range', async () => {
    const { wrapper, router } = await open({ from: '2026-01-01', to: '2026-03-31' })
    mocks.apply!({ year: '2026', month: '2' })
    await flushPromises()
    expect(mocks.list).toHaveBeenCalledTimes(2)
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ year: 2026, month: 2 }))
    expect(router.currentRoute.value.query).toEqual({ year: '2026', month: '2' })
    wrapper.unmount()
  })

  it('debounces typing and cancels that delay when a saved view is applied', async () => {
    const { wrapper } = await open()
    const vm = wrapper.vm as unknown as { search: string }
    vm.search = 'first'
    await nextTick()
    vm.search = 'second'
    await nextTick()
    await vi.advanceTimersByTimeAsync(300)
    expect(mocks.list).toHaveBeenCalledTimes(2)
    vm.search = 'pending'
    await nextTick()
    mocks.apply!({ status: 'paid' })
    await flushPromises()
    await vi.advanceTimersByTimeAsync(350)
    expect(mocks.list).toHaveBeenCalledTimes(3)
    wrapper.unmount()
  })

  it('resets through the menu once and keeps manual filters reactive', async () => {
    const { wrapper, router } = await open({ q: 'synthetic', status: 'paid' })
    await router.push('/invoices')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(350)
    expect(mocks.list).toHaveBeenCalledTimes(2)
    const vm = wrapper.vm as unknown as { statusFilter: string }
    vm.statusFilter = 'issued'
    await flushPromises()
    expect(mocks.list).toHaveBeenCalledTimes(3)
    expect(mocks.list).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'issued' }))
    wrapper.unmount()
  })
})
