import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import type { CatalogBulkItemState, CatalogBulkJobItems, CatalogBulkSelection } from '@/api/catalogBulk'
import CatalogBulkDialog from '../CatalogBulkDialog.vue'

const mocks = vi.hoisted(() => ({
  preview: vi.fn(),
  apply: vi.fn(),
  restore: vi.fn(),
  items: vi.fn(),
  get: vi.fn(),
  cancel: vi.fn(),
  retry: vi.fn(),
}))

vi.mock('@/api/catalogBulk', async (importOriginal) => {
  const actual = await importOriginal<typeof import('@/api/catalogBulk')>()
  return {
    ...actual,
    catalogBulkApi: {
      preview: mocks.preview,
      apply: mocks.apply,
      restore: mocks.restore,
      items: mocks.items,
    },
  }
})
vi.mock('@/api/catalogJobs', () => ({
  catalogJobsApi: {
    get: mocks.get,
    cancel: mocks.cancel,
    retry: mocks.retry,
  },
}))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn() }) }))
vi.mock('@/i18n', () => ({ i18n: { global: { locale: { value: 'cs' } } } }))
vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))
vi.mock('@/components/ui/buttonStyles', () => ({ btnFilled: () => '', btnOutline: () => '' }))

const modalStub = {
  template: '<section><slot/><footer><slot name="footer"/></footer></section>',
}
const progressStub = {
  props: ['job', 'cancelling', 'canCancel'],
  template: '<div data-test="progress">{{ job.id }}:{{ job.kind }}:{{ job.status }}</div>',
}

function deferred<T>() {
  let resolve!: (value: T) => void
  const promise = new Promise<T>(done => { resolve = done })
  return { promise, resolve }
}

function job(
  id: number,
  kind: 'catalog_bulk_preview' | 'catalog_bulk_apply' | 'catalog_bulk_restore',
  status: 'queued' | 'running' | 'completed' = 'completed',
  counts: Record<string, number> = {},
) {
  return {
    id,
    kind,
    status,
    total: 2,
    checkpoint: status === 'completed' ? 2 : 0,
    report: { counts },
    error_code: null,
    cancel_requested: false,
  }
}

function state(overrides: Partial<CatalogBulkItemState> = {}): CatalogBulkItemState {
  return {
    id: 31,
    sku: 'SKU-31',
    name: 'Test item',
    row_version: 1,
    manufacturer_id: 1,
    category_ids: [11, 12],
    categories: [
      { category_id: 11, is_primary: false, display_order: 7 },
      { category_id: 12, is_primary: false, display_order: 20 },
    ],
    tag_ids: [],
    is_active: true,
    export_eshop: false,
    min_qty: null,
    ...overrides,
  }
}

function page(pageNumber = 1, overrides: Partial<CatalogBulkJobItems['items'][number]> = {}): CatalogBulkJobItems {
  return {
    items: [{
      ordinal: pageNumber,
      stock_item_id: pageNumber === 1 ? 31 : 32,
      status: 'ready',
      before: state({ id: 31 + pageNumber - 1, sku: `SKU-${pageNumber}` }),
      after: state({
        id: 31 + pageNumber - 1,
        sku: `SKU-${pageNumber}`,
        row_version: 3,
        categories: [
          { category_id: 11, is_primary: true, display_order: 0 },
          { category_id: 12, is_primary: false, display_order: 1 },
        ],
      }),
      error_code: null,
      ...overrides,
    }],
    pagination: { page: pageNumber, pages: 2, total: 2, limit: 50 },
  }
}

function mountDialog(
  selection: CatalogBulkSelection = { all_matching: false, ids: [31] },
  selectedCount = 1,
  initialJobId?: number,
) {
  return mount(CatalogBulkDialog, {
    props: {
      selection,
      selectedCount,
      manufacturers: [{ id: 1, name: 'Before' }, { id: 2, name: 'After' }] as any,
      categories: [{ id: 11, name: 'First' }, { id: 12, name: 'Second' }] as any,
      tags: [],
      initialJobId,
    },
    global: {
      stubs: {
        Modal: modalStub,
        CatalogJobProgress: progressStub,
      },
    },
  })
}

async function chooseActiveChange(wrapper: ReturnType<typeof mountDialog>) {
  await wrapper.findAll('select')[3]!.setValue('false')
}

beforeEach(() => {
  vi.clearAllMocks()
  mocks.items.mockResolvedValue(page())
})

afterEach(() => {
  vi.useRealTimers()
})

describe('CatalogBulkDialog', () => {
  it('snapshots reactive all-matching filters as a plain API payload', async () => {
    mocks.preview.mockResolvedValue(job(7, 'catalog_bulk_preview', 'completed', { ready: 1 }))
    const selection = reactive({
      all_matching: true as const,
      filters: {
        tag_ids: reactive([13]),
        attribute_filters: reactive([{ attribute_id: 14, option_id: 15 }]),
      },
      excluded_ids: reactive([32]),
    })
    const wrapper = mountDialog(selection, 124)

    await chooseActiveChange(wrapper)
    await wrapper.get('[data-test="preview"]').trigger('click')
    await flushPromises()

    expect(mocks.preview).toHaveBeenCalledWith({
      all_matching: true,
      filters: {
        tag_ids: [13],
        attribute_filters: [{ attribute_id: 14, option_id: 15 }],
      },
      excluded_ids: [32],
    }, { is_active: false })
  })

  it('keeps a numeric minimum quantity as a decimal string in the preview request', async () => {
    mocks.preview.mockResolvedValue(job(7, 'catalog_bulk_preview', 'completed', { ready: 1 }))
    const wrapper = mountDialog()

    await wrapper.get('input[type="number"]').setValue('7')
    await wrapper.get('[data-test="preview"]').trigger('click')
    await flushPromises()

    expect(mocks.preview).toHaveBeenCalledWith(
      { all_matching: false, ids: [31] },
      { min_qty: '7' },
    )
  })

  it('reopens a completed preview without creating it again and can apply it', async () => {
    mocks.get.mockResolvedValue(job(7, 'catalog_bulk_preview', 'completed', { ready: 1 }))
    mocks.apply.mockResolvedValue(job(8, 'catalog_bulk_apply', 'completed', { applied: 1 }))
    const wrapper = mountDialog({ all_matching: false, ids: [31] }, 1, 7)
    await flushPromises()

    expect(mocks.get).toHaveBeenCalledWith(7)
    expect(mocks.preview).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="apply"]').attributes('disabled')).toBeUndefined()
    await wrapper.get('[data-test="apply"]').trigger('click')
    await flushPromises()
    expect(mocks.apply).toHaveBeenCalledWith(7)
  })

  it('freezes selection, shows a read-only preview and explains category metadata and unknown errors', async () => {
    mocks.preview.mockResolvedValue(job(7, 'catalog_bulk_preview', 'completed', { ready: 1 }))
    mocks.items.mockResolvedValue(page(1, { error_code: 'future_error' }))
    const wrapper = mountDialog()

    await wrapper.setProps({
      selection: { all_matching: false, ids: [99] },
      selectedCount: 99,
    })
    await chooseActiveChange(wrapper)
    await wrapper.get('[data-test="preview"]').trigger('click')
    await flushPromises()

    expect(mocks.preview).toHaveBeenCalledWith(
      { all_matching: false, ids: [31] },
      { is_active: false },
    )
    expect(wrapper.get('[data-test="selection-count"]').text()).toContain('"count":1')
    expect(wrapper.find('[data-test="bulk-form"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('stock.items.bulk.category_primary')
    expect(wrapper.text()).toContain('stock.items.bulk.category_order')
    expect(wrapper.text()).toContain('stock.items.bulk.error.unknown')
    expect(wrapper.get('[data-test="apply"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.find('[data-test="restore"]').exists()).toBe(false)
  })

  it('keeps a page-two report when a concurrent poll finishes', async () => {
    vi.useFakeTimers()
    mocks.preview.mockResolvedValue(job(7, 'catalog_bulk_preview', 'queued'))
    const secondPage = deferred<CatalogBulkJobItems>()
    mocks.items
      .mockResolvedValueOnce(page(1))
      .mockImplementationOnce(() => secondPage.promise)
    mocks.get.mockResolvedValue(job(7, 'catalog_bulk_preview', 'running'))
    const wrapper = mountDialog()
    await chooseActiveChange(wrapper)
    await wrapper.get('[data-test="preview"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-test="report-next"]').trigger('click')
    await vi.advanceTimersByTimeAsync(2000)
    secondPage.resolve(page(2))
    await flushPromises()

    expect(mocks.items).toHaveBeenLastCalledWith(7, { page: 2, limit: 50 })
    expect(wrapper.get('[data-test="report-item"]').text()).toContain('SKU-2')
    expect(wrapper.text()).toContain('"page":2')
    wrapper.unmount()
  })

  it('ignores an old preview poll after apply switches to a new job', async () => {
    vi.useFakeTimers()
    mocks.preview.mockResolvedValue(job(7, 'catalog_bulk_preview', 'queued'))
    const oldPoll = deferred<ReturnType<typeof job>>()
    mocks.get
      .mockImplementationOnce(() => oldPoll.promise)
      .mockResolvedValueOnce(job(7, 'catalog_bulk_preview', 'completed', { ready: 1 }))
      .mockResolvedValue(job(8, 'catalog_bulk_apply', 'running'))
    mocks.apply.mockResolvedValue(job(8, 'catalog_bulk_apply', 'queued'))
    const wrapper = mountDialog()
    await chooseActiveChange(wrapper)
    await wrapper.get('[data-test="preview"]').trigger('click')
    await flushPromises()

    await vi.advanceTimersByTimeAsync(4000)
    await flushPromises()
    expect(wrapper.find('[data-test="apply"]').exists()).toBe(true)
    await wrapper.get('[data-test="apply"]').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-test="progress"]').text()).toContain('8:catalog_bulk_apply')

    oldPoll.resolve(job(7, 'catalog_bulk_preview', 'running'))
    await flushPromises()
    expect(wrapper.get('[data-test="progress"]').text()).toContain('8:catalog_bulk_apply')
    wrapper.unmount()
  })

  it('guards duplicate actions, emits completion once and offers restore only for completed apply', async () => {
    mocks.preview.mockResolvedValue(job(7, 'catalog_bulk_preview', 'completed', { ready: 1 }))
    const applyRequest = deferred<ReturnType<typeof job>>()
    const applyReport = deferred<CatalogBulkJobItems>()
    const restoreRequest = deferred<ReturnType<typeof job>>()
    const restoreReport = deferred<CatalogBulkJobItems>()
    mocks.apply.mockImplementation(() => applyRequest.promise)
    mocks.restore.mockImplementation(() => restoreRequest.promise)
    mocks.items
      .mockResolvedValueOnce(page())
      .mockImplementationOnce(() => applyReport.promise)
      .mockImplementationOnce(() => restoreReport.promise)
    const wrapper = mountDialog()
    await chooseActiveChange(wrapper)
    await wrapper.get('[data-test="preview"]').trigger('click')
    await flushPromises()

    await wrapper.get('[data-test="apply"]').trigger('click')
    await wrapper.get('[data-test="apply"]').trigger('click')
    expect(mocks.apply).toHaveBeenCalledTimes(1)
    applyRequest.resolve(job(8, 'catalog_bulk_apply', 'completed', { applied: 1 }))
    await flushPromises()
    expect(wrapper.emitted('completed')).toHaveLength(1)
    expect(wrapper.find('[data-test="apply"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="restore"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="restore"]').attributes('disabled')).toBeDefined()

    applyReport.resolve(page(1, { status: 'applied' }))
    await flushPromises()

    await wrapper.get('[data-test="restore"]').trigger('click')
    await wrapper.get('[data-test="restore"]').trigger('click')
    expect(mocks.restore).toHaveBeenCalledTimes(1)
    restoreRequest.resolve(job(9, 'catalog_bulk_restore', 'completed', { applied: 1 }))
    await flushPromises()
    expect(wrapper.emitted('completed')).toHaveLength(2)
    expect(wrapper.find('[data-test="restore"]').exists()).toBe(false)

    restoreReport.resolve(page(1, { status: 'applied' }))
    await flushPromises()
    expect(wrapper.emitted('completed')).toHaveLength(2)
  })
})
