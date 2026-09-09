import { defineComponent, nextTick, ref } from 'vue'
import { enableAutoUnmount, flushPromises, mount, shallowMount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import PriceMatrix from '../PriceMatrix.vue'

enableAutoUnmount(afterEach)

const RouterLinkStub = defineComponent({
  name: 'RouterLink',
  props: { to: { type: [String, Object], required: true } },
  template: '<a><slot /></a>',
})

const mocks = vi.hoisted(() => ({
  canWrite: vi.fn<(permission: string) => boolean>(),
  listCurrencies: vi.fn(),
  searchItems: vi.fn(),
  previewMatrix: vi.fn(),
  applyMatrix: vi.fn(),
  matrixItems: vi.fn(),
  exportMatrix: vi.fn(),
  importMatrix: vi.fn(),
  getJob: vi.fn(),
  routerReplace: vi.fn(),
  routeState: null as null | { query: Record<string, string> },
  supplierState: null as null | { currentSupplierId: number },
}))

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', async () => {
  const { reactive } = await import('vue')
  const route = reactive({ query: {} as Record<string, string> })
  mocks.routeState = route
  return {
    useRoute: () => route,
    useRouter: () => ({ replace: mocks.routerReplace }),
  }
})
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: mocks.canWrite }) }))
vi.mock('@/stores/supplier', async () => {
  const { reactive } = await import('vue')
  const supplier = reactive({ currentSupplierId: 1 })
  mocks.supplierState = supplier
  return { useSupplierStore: () => supplier }
})
vi.mock('@/api/eshop', () => ({ eshopApi: { listCurrencies: mocks.listCurrencies } }))
vi.mock('@/api/stock', () => ({ stockApi: { searchItems: mocks.searchItems } }))
vi.mock('@/api/catalogJobs', () => ({ catalogJobsApi: { get: mocks.getJob } }))
vi.mock('@/api/catalogPricing', () => ({
  catalogPricingApi: {
    previewMatrix: mocks.previewMatrix,
    applyMatrix: mocks.applyMatrix,
    matrixItems: mocks.matrixItems,
    exportMatrix: mocks.exportMatrix,
    importMatrix: mocks.importMatrix,
  },
}))
vi.mock('@/api/errors', () => ({
  apiErrorMessage: (error: unknown, fallback: string) => error instanceof Error ? error.message : fallback,
}))

const item = {
  id: 17,
  sku: 'SKU-17',
  name: 'Test item',
  unit: 'ks',
  vat_rate_id: null,
  sale_price_without_vat: '100.00',
}
const queuedJob = {
  id: 55,
  supplier_id: 1,
  kind: 'price_matrix_preview',
  input_version: 1,
  status: 'queued',
  checkpoint: 0,
  total: 1,
  report: {},
  error_code: null,
  cancel_requested: false,
  created_at: '2026-09-10 10:00:00',
  updated_at: '2026-09-10 10:00:00',
  finished_at: null,
  attempts: 0,
}

function completedJob(id: number, extra: Record<string, unknown> = {}) {
  return {
    ...queuedJob,
    id,
    status: 'completed',
    report: { counts: { ready: 1 } },
    finished_at: '2026-09-10 10:01:00',
    ...extra,
  }
}

function mountPage(props: Record<string, unknown> = {}) {
  return shallowMount(PriceMatrix, {
    props,
    global: { stubs: { CatalogJobProgress: true, RouterLink: RouterLinkStub } },
  })
}

async function selectItem(wrapper: ReturnType<typeof mountPage>) {
  await wrapper.get('[data-test="matrix-search"]').setValue('SKU')
  await wrapper.get('[data-test="matrix-search"]').trigger('keyup.enter')
  await flushPromises()
  await wrapper.get('[data-test="matrix-result-17"]').trigger('click')
}

beforeEach(() => {
  vi.clearAllMocks()
  mocks.routeState!.query = {}
  mocks.supplierState!.currentSupplierId = 1
  mocks.routerReplace.mockImplementation(async (location: { query?: Record<string, string> }) => {
    mocks.routeState!.query = location.query ?? {}
  })
  mocks.canWrite.mockReturnValue(true)
  mocks.listCurrencies.mockResolvedValue([
    { id: 1, code: 'CZK', name: 'Koruna', symbol: 'Kč', display_order: 1, is_default: true, archived: false },
  ])
  mocks.searchItems.mockResolvedValue([item])
  mocks.previewMatrix.mockResolvedValue(queuedJob)
  mocks.matrixItems.mockImplementation(async (id: number) => ({
    job: id === queuedJob.id ? queuedJob : completedJob(id, { currencies: ['CZK'] }),
    items: [],
    pagination: { page: 1, limit: 100, total: 0, pages: 0 },
  }))
})

afterEach(() => {
  vi.useRealTimers()
  vi.restoreAllMocks()
})

describe('PriceMatrix', () => {
  it('sends selected currencies and an explicit fixed-price exception in preview payload', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await selectItem(wrapper)
    await wrapper.get('[data-test="matrix-exception-item"]').setValue('17')
    await wrapper.get('[data-test="matrix-exception-currency"]').setValue('CZK')
    await wrapper.get('[data-test="matrix-exception-operation"]').setValue('set_fixed')
    await wrapper.get('[data-test="matrix-exception-price"]').setValue('149,90')
    await wrapper.get('[data-test="matrix-add-exception"]').trigger('click')
    await wrapper.get('[data-test="matrix-preview"]').trigger('click')
    await flushPromises()

    expect(mocks.previewMatrix).toHaveBeenCalledWith(
      { all_matching: false, ids: [17] },
      expect.objectContaining({
        currencies: ['CZK'],
        ensure_missing: true,
        reprice: true,
        overrides: [{ item_id: 17, currency_code: 'CZK', operation: 'set_fixed', fixed_price: '149.90' }],
      }),
    )
  })

  it('passes an all-matching filter selection without expanding 30000 item IDs', async () => {
    const selection = {
      all_matching: true as const,
      filters: { active: true, category_id: 9, q: 'camera' },
      excluded_ids: [12],
    }
    const wrapper = mountPage({ selection, selectedCount: 30000, embedded: true })
    await flushPromises()

    expect(wrapper.find('[data-test="matrix-search"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="matrix-external-selection"]').text()).toContain('eshop.price_matrix.selected_count')
    await wrapper.get('[data-test="matrix-preview"]').trigger('click')
    await flushPromises()

    expect(mocks.previewMatrix).toHaveBeenCalledWith(
      selection,
      expect.objectContaining({ currencies: ['CZK'], overrides: [] }),
    )
  })

  it('keeps the configured selection visible after preview creation fails', async () => {
    mocks.previewMatrix.mockRejectedValue(new Error('Preview failed'))
    const wrapper = mountPage()
    await flushPromises()
    await selectItem(wrapper)
    await wrapper.get('[data-test="matrix-preview"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="matrix-error"]').text()).toBe('Preview failed')
    expect(wrapper.text()).toContain('SKU-17')
    expect(wrapper.find('[data-test="matrix-preview"]').exists()).toBe(true)
  })

  it('does not load sensitive data or render mutation controls without both permissions', async () => {
    mocks.canWrite.mockImplementation(permission => permission !== 'stock.items.write')
    const wrapper = mountPage()
    await flushPromises()

    expect(mocks.canWrite).toHaveBeenCalledWith('eshop.write')
    expect(mocks.canWrite).toHaveBeenCalledWith('stock.items.write')
    expect(mocks.listCurrencies).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="matrix-preview"]').exists()).toBe(false)
    expect(mocks.previewMatrix).not.toHaveBeenCalled()
  })

  it('invalidates an old-tenant response when the supplier changes with unchanged permissions', async () => {
    const currencyResolvers: Array<(value: Array<Record<string, unknown>>) => void> = []
    mocks.listCurrencies.mockImplementation(() => new Promise(resolve => currencyResolvers.push(resolve)))
    const wrapper = mountPage()
    await nextTick()
    expect(mocks.listCurrencies).toHaveBeenCalledTimes(1)

    mocks.supplierState!.currentSupplierId = 2
    await nextTick()
    await flushPromises()
    expect(mocks.listCurrencies).toHaveBeenCalledTimes(2)

    currencyResolvers[1]([{ id: 2, code: 'EUR', name: 'Euro', is_default: true, archived: false }])
    await flushPromises()
    currencyResolvers[0]([{ id: 1, code: 'CZK', name: 'Koruna', is_default: true, archived: false }])
    await flushPromises()

    expect(wrapper.text()).toContain('EUR · Euro')
    expect(wrapper.text()).not.toContain('CZK · Koruna')
  })

  it('does not poll when deferred preview completes after unmount', async () => {
    let resolvePreview!: (value: typeof queuedJob) => void
    mocks.previewMatrix.mockReturnValue(new Promise(resolve => { resolvePreview = resolve }))
    const wrapper = mountPage()
    await flushPromises()
    await selectItem(wrapper)
    await wrapper.get('[data-test="matrix-preview"]').trigger('click')
    wrapper.unmount()
    resolvePreview(queuedJob)
    await flushPromises()

    expect(mocks.getJob).not.toHaveBeenCalled()
  })

  it('uses the local calendar day for the default calculation date', async () => {
    const year = vi.spyOn(Date.prototype, 'getFullYear').mockReturnValue(2026)
    const month = vi.spyOn(Date.prototype, 'getMonth').mockReturnValue(0)
    const day = vi.spyOn(Date.prototype, 'getDate').mockReturnValue(2)
    const wrapper = mountPage()
    await flushPromises()

    expect((wrapper.get('[data-test="matrix-on-date"]').element as HTMLInputElement).value).toBe('2026-01-02')
    year.mockRestore()
    month.mockRestore()
    day.mockRestore()
  })

  it('reopens a completed preview from the matrix_job query and allows apply', async () => {
    mocks.routeState!.query = { matrix_job: '77' }
    const completed = completedJob(77)
    mocks.getJob.mockResolvedValue(completed)
    mocks.applyMatrix.mockResolvedValue({ ...queuedJob, id: 78, kind: 'price_matrix_apply' })

    const wrapper = mountPage()
    await flushPromises()

    expect(mocks.getJob).toHaveBeenCalledWith(77)
    await wrapper.get('[data-test="matrix-apply"]').trigger('click')
    await flushPromises()
    expect(mocks.applyMatrix).toHaveBeenCalledWith(77)
    expect(mocks.routerReplace).toHaveBeenCalledWith({ query: { matrix_job: '78' } })
  })

  it('ignores a stale matrix_job response and clears the query when starting a new matrix', async () => {
    mocks.routeState!.query = { tab: 'price-matrix', matrix_job: '77' }
    let resolve77!: (value: ReturnType<typeof completedJob>) => void
    let resolve88!: (value: ReturnType<typeof completedJob>) => void
    mocks.getJob.mockImplementation((id: number) => new Promise(resolve => {
      if (id === 77) resolve77 = resolve
      else resolve88 = resolve
    }))
    mocks.applyMatrix.mockResolvedValue({ ...queuedJob, id: 89, kind: 'price_matrix_apply' })
    const wrapper = mountPage()
    await flushPromises()

    mocks.routeState!.query = { tab: 'price-matrix', matrix_job: '88' }
    await nextTick()
    expect(mocks.getJob).toHaveBeenNthCalledWith(1, 77)
    expect(mocks.getJob).toHaveBeenNthCalledWith(2, 88)
    resolve88(completedJob(88))
    await flushPromises()
    resolve77(completedJob(77))
    await flushPromises()
    await wrapper.get('[data-test="matrix-apply"]').trigger('click')
    expect(mocks.applyMatrix).toHaveBeenCalledWith(88)
    expect(mocks.matrixItems).not.toHaveBeenCalledWith(77, expect.anything())

    const newButton = wrapper.findAll('button').find(button => button.text() === 'eshop.price_matrix.new')
    expect(newButton).toBeDefined()
    await newButton!.trigger('click')
    await flushPromises()
    expect(mocks.routerReplace).toHaveBeenLastCalledWith({ query: { tab: 'price-matrix' } })
  })

  it('does not offer apply twice and links to the existing apply job', async () => {
    mocks.routeState!.query = { matrix_job: '77' }
    const completed = completedJob(77, { apply_job_id: 78 })
    mocks.getJob.mockResolvedValue(completed)
    mocks.matrixItems.mockResolvedValue({
      job: completed,
      items: [],
      pagination: { page: 1, limit: 100, total: 0, pages: 0 },
    })
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="matrix-apply"]').exists()).toBe(false)
    expect(wrapper.getComponent(RouterLinkStub).props('to')).toEqual({
      path: '/eshop',
      query: { tab: 'price-matrix', matrix_job: '78' },
    })
  })

  it('stops polling while kept alive off-screen and resumes after activation', async () => {
    vi.useFakeTimers()
    mocks.routeState!.query = { matrix_job: '77' }
    mocks.getJob.mockResolvedValue({ ...queuedJob, id: 77 })
    const shown = ref(true)
    const Host = defineComponent({
      components: { PriceMatrix },
      setup: () => ({ shown }),
      template: '<KeepAlive><PriceMatrix v-if="shown" /></KeepAlive>',
    })
    const wrapper = mount(Host, { global: { stubs: { CatalogJobProgress: true, RouterLink: RouterLinkStub } } })
    await flushPromises()
    expect(mocks.getJob).toHaveBeenCalledTimes(1)

    shown.value = false
    await nextTick()
    await vi.advanceTimersByTimeAsync(1600)
    expect(mocks.getJob).toHaveBeenCalledTimes(1)

    shown.value = true
    await nextTick()
    await vi.advanceTimersByTimeAsync(1600)
    expect(mocks.getJob).toHaveBeenCalledTimes(2)
    wrapper.unmount()
  })

  it('opens the current query job after it changes while the kept-alive page is inactive', async () => {
    mocks.routeState!.query = { matrix_job: '77' }
    mocks.getJob.mockImplementation(async (id: number) => id === 77
      ? { ...queuedJob, id: 77 }
      : completedJob(88))
    const shown = ref(true)
    const Host = defineComponent({
      components: { PriceMatrix },
      setup: () => ({ shown }),
      template: '<KeepAlive><PriceMatrix v-if="shown" /></KeepAlive>',
    })
    const wrapper = mount(Host, { global: { stubs: { CatalogJobProgress: true, RouterLink: RouterLinkStub } } })
    await flushPromises()
    expect(mocks.getJob).toHaveBeenCalledTimes(1)

    shown.value = false
    await nextTick()
    mocks.routeState!.query = { matrix_job: '88' }
    await nextTick()
    await flushPromises()
    expect(mocks.getJob).toHaveBeenCalledTimes(1)

    shown.value = true
    await nextTick()
    await flushPromises()
    expect(mocks.getJob).toHaveBeenNthCalledWith(2, 88)
    wrapper.unmount()
  })
})
