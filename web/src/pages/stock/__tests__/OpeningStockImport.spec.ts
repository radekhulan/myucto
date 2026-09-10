import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import OpeningStockImport from '../OpeningStockImport.vue'

const mocks = vi.hoisted(() => ({
  upload: vi.fn(), sample: vi.fn(), preview: vi.fn(), job: vi.fn(), items: vi.fn(),
  apply: vi.fn(), retry: vi.fn(), cancel: vi.fn(), warehouses: vi.fn(), replace: vi.fn(),
  route: { query: {} as Record<string, string> },
  supplierStore: undefined as any,
}))
vi.mock('@/api/openingStockImport', () => ({ openingStockImportApi: {
  upload: mocks.upload, sample: mocks.sample, preview: mocks.preview, job: mocks.job,
  items: mocks.items, apply: mocks.apply, retry: mocks.retry, cancel: mocks.cancel,
} }))
vi.mock('@/api/stock', () => ({ stockApi: { listWarehouses: mocks.warehouses } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))
vi.mock('@/stores/supplier', async () => {
  const { reactive } = await import('vue')
  mocks.supplierStore = reactive({ currentSupplierId: 1 })
  return { useSupplierStore: () => mocks.supplierStore }
})
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('vue-router', () => ({ useRoute: () => mocks.route, useRouter: () => ({ replace: mocks.replace }) }))

const base = { id: 31, supplier_id: 1, kind: 'stock_opening_import_stage', input_version: 1,
  checkpoint: 50, total: 101, report: { counts: { ready: 50 } }, error_code: null,
  cancel_requested: false, created_at: '', updated_at: '', finished_at: null, attempts: 1 }
const emptyItems = { items: [], pagination: { page: 1, limit: 50, total: 0, pages: 0 } }
const sample = { source: { id: 7, original_name: 'opening.csv', format: 'csv', size_bytes: 10, sha256: 'x', created_at: '' },
  header: ['external_id', 'sku', 'quantity', 'unit_cost'], rows: [['1', 'SKU-1', '2', '10']],
  fields: ['external_id', 'sku', 'quantity', 'unit_cost'] }

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>(done => { resolve = done })
  return { promise, resolve }
}

function mountPage() {
  return shallowMount(OpeningStockImport, { global: { stubs: {
    RouterLink: true,
    CatalogJobProgress: { name: 'CatalogJobProgress', props: ['job'], emits: ['cancel'], template: '<button data-test="cancel-job" @click="$emit(\'cancel\')">{{ job.checkpoint }}</button>' },
  } } })
}

async function uploadFile(wrapper: ReturnType<typeof mountPage>) {
  const input = wrapper.get('input[type="file"]')
  Object.defineProperty(input.element, 'files', { configurable: true, value: [new File(['x'], 'opening.csv')] })
  await input.trigger('change')
  await flushPromises()
}

beforeEach(() => {
  mocks.route.query = {}
  mocks.supplierStore.currentSupplierId = 1
  mocks.warehouses.mockResolvedValue([{ id: 1, code: 'A', name: 'A', is_default: true }])
  mocks.upload.mockResolvedValue(sample.source)
  mocks.sample.mockResolvedValue(sample)
  mocks.items.mockResolvedValue(emptyItems)
  mocks.replace.mockImplementation(async ({ query }: { query: Record<string, string> }) => { mocks.route.query = query })
})

afterEach(() => vi.clearAllMocks())

describe('OpeningStockImport', () => {
  it('resumes a durable job and refreshes its progress and row report', async () => {
    mocks.route.query = { opening_job: '31' }
    mocks.job.mockResolvedValueOnce({ ...base, status: 'running' })
      .mockResolvedValueOnce({ ...base, status: 'completed', checkpoint: 101, report: { counts: { ready: 101 } } })
    const wrapper = mountPage()
    await flushPromises()
    expect(mocks.job).toHaveBeenCalledTimes(2)
    expect(mocks.items).toHaveBeenCalledWith(31, 1)
    expect(wrapper.get('[data-test="cancel-job"]').text()).toBe('101')
    expect(wrapper.text()).toContain('stock.opening_import.status.ready: 101')
    wrapper.unmount()
  })

  it('loads a later report page and resets to page one for the apply job', async () => {
    mocks.route.query = { opening_job: '31' }
    const completedStage = { ...base, status: 'completed', checkpoint: 101, report: { counts: { ready: 101 } } }
    const completedApply = { ...base, id: 32, kind: 'stock_opening_import_apply', status: 'completed', checkpoint: 101, report: { counts: { applied: 101 } } }
    mocks.job.mockResolvedValueOnce(completedStage).mockResolvedValueOnce(completedStage).mockResolvedValueOnce(completedApply)
    mocks.items.mockResolvedValueOnce({ items: [], pagination: { page: 1, limit: 50, total: 101, pages: 3 } })
      .mockResolvedValueOnce({ items: [], pagination: { page: 2, limit: 50, total: 101, pages: 3 } })
      .mockResolvedValueOnce({ items: [], pagination: { page: 1, limit: 50, total: 101, pages: 3 } })
    mocks.apply.mockResolvedValue(completedApply)
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="next-page"]').trigger('click')
    await flushPromises()
    expect(mocks.items).toHaveBeenNthCalledWith(2, 31, 2)
    await wrapper.get('[data-test="apply-import"]').trigger('click')
    await flushPromises()
    expect(mocks.items).toHaveBeenNthCalledWith(3, 32, 1)
    expect(wrapper.get('[data-test="previous-page"]').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('automatically reloads the sample with the selected reader controls', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await uploadFile(wrapper)
    await wrapper.get('[data-test="reader-encoding"]').setValue('Windows-1250')
    await wrapper.get('[data-test="reader-delimiter"]').setValue('\t')
    await wrapper.get('[data-test="reader-sheet"]').setValue('2')
    await flushPromises()
    expect(mocks.sample).toHaveBeenLastCalledWith(7, { encoding: 'Windows-1250', delimiter: '\t', sheet: 2 })
    wrapper.unmount()
  })

  it('does not let the initial upload sample overwrite changed reader settings', async () => {
    const initial = deferred<any>()
    mocks.sample.mockReturnValueOnce(initial.promise)
    const wrapper = mountPage()
    await flushPromises()
    await uploadFile(wrapper)
    mocks.sample.mockResolvedValueOnce({ ...sample, header: ['latest'] })
    await wrapper.get('[data-test="reader-delimiter"]').setValue(',')
    await flushPromises()
    initial.resolve({ ...sample, header: ['older'] })
    await flushPromises()
    expect(wrapper.text()).toContain('latest')
    expect(wrapper.text()).not.toContain('older')
    wrapper.unmount()
  })

  it('keeps only the latest sample when reader changes resolve out of order', async () => {
    const wrapper = mountPage()
    await flushPromises()
    await uploadFile(wrapper)
    const older = deferred<any>()
    const latest = deferred<any>()
    mocks.sample.mockReturnValueOnce(older.promise).mockReturnValueOnce(latest.promise)
    await wrapper.get('[data-test="reader-encoding"]').setValue('Windows-1250')
    await wrapper.get('[data-test="reader-delimiter"]').setValue(',')
    latest.resolve({ ...sample, header: ['latest'] })
    await flushPromises()
    older.resolve({ ...sample, header: ['older'] })
    await flushPromises()
    expect(wrapper.text()).toContain('latest')
    expect(wrapper.text()).not.toContain('older')
    wrapper.unmount()
  })

  it('discards a stale upload response and unlocks the new supplier form', async () => {
    const pending = deferred<typeof sample.source>()
    mocks.upload.mockReturnValueOnce(pending.promise)
    const wrapper = mountPage()
    await flushPromises()
    const input = wrapper.get('input[type="file"]')
    Object.defineProperty(input.element, 'files', { configurable: true, value: [new File(['x'], 'old.csv')] })
    await input.trigger('change')
    mocks.supplierStore.currentSupplierId = 2
    await flushPromises()
    pending.resolve(sample.source)
    await flushPromises()
    expect(mocks.sample).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="reader-encoding"]').exists()).toBe(false)
    expect(wrapper.get('input[type="file"]').attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })

  it('keeps the new supplier warehouse when an earlier initialization finishes late', async () => {
    const first = deferred<any[]>()
    mocks.warehouses.mockReturnValueOnce(first.promise)
      .mockResolvedValueOnce([{ id: 2, code: 'B', name: 'B', is_default: true }])
    const wrapper = mountPage()
    mocks.supplierStore.currentSupplierId = 2
    await flushPromises()
    first.resolve([{ id: 1, code: 'A', name: 'A', is_default: true }])
    await flushPromises()
    expect((wrapper.get('select').element as HTMLSelectElement).value).toBe('2')
    wrapper.unmount()
  })

  it('does not install or poll a preview returned for a previous supplier', async () => {
    const pending = deferred<any>()
    mocks.preview.mockReturnValueOnce(pending.promise)
    const wrapper = mountPage()
    await flushPromises()
    await uploadFile(wrapper)
    await wrapper.get('input[placeholder="erp-2026"]').setValue('opening-1')
    const previewButton = wrapper.findAll('button').find(button => button.text().includes('stock.opening_import.preview'))!
    await previewButton.trigger('click')
    mocks.supplierStore.currentSupplierId = 2
    await flushPromises()
    pending.resolve({ ...base, status: 'queued' })
    await flushPromises()
    expect(mocks.replace).not.toHaveBeenCalledWith({ query: expect.objectContaining({ opening_job: '31' }) })
    expect(wrapper.find('[data-test="cancel-job"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
