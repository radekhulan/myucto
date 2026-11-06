import { afterEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import { reactive } from 'vue'
import CatalogJobs from '../CatalogJobs.vue'

const mocks = vi.hoisted(() => ({ list: vi.fn(), retry: vi.fn(), supplier: { currentSupplierId: 1 }, stockWrite: true, admin: true }))
vi.mock('@/api/catalogJobs', () => ({ catalogJobsApi: { list: mocks.list, retry: mocks.retry } }))
vi.mock('@/stores/supplier', () => ({ useSupplierStore: () => mocks.supplier }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: (key: string) => key !== 'stock.items.write' || mocks.stockWrite, isCompanyAdminRole: mocks.admin }) }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: vi.fn(), success: vi.fn() }) }))
vi.mock('@/composables/useFormat', () => ({ formatDateTime: (value: string) => value }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/api/eshop', () => ({ eshopApi: {} }))

const wrappers: ReturnType<typeof mount>[] = []
function render() {
  const wrapper = mount(CatalogJobs, { global: { stubs: {
    CatalogBulkDialog: true, CatalogExportDialog: true, EmptyState: true,
    CatalogJobProgress: { props: ['job', 'canCancel'], template: '<div data-test="progress" :data-can-cancel="String(canCancel)" />' },
    RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' },
  } } })
  wrappers.push(wrapper)
  return wrapper
}
function job(id: number, kind = 'catalog_import_stage') {
  return { id, kind, status: 'completed', report: {}, created_at: '2026-09-10', updated_at: '2026-09-10', attempts: 1 }
}
afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); vi.clearAllMocks(); mocks.stockWrite = true; mocks.admin = true })

describe('CatalogJobs', () => {
  it('opens import and matrix workflows from durable history', async () => {
    mocks.list.mockResolvedValue([job(1), job(2, 'price_matrix_preview')])
    const wrapper = render()
    await flushPromises()
    expect(wrapper.find('a[href="/eshop?tab=import&import_job=1"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/eshop?tab=price-matrix&matrix_job=2"]').exists()).toBe(true)
  })
  it('offers retry of a stock history import or rollback only for the latest one', async () => {
    mocks.list.mockResolvedValue([
      { ...job(5, 'stock_history_import_apply'), status: 'cancelled' },
      { ...job(4, 'stock_history_import_stage'), status: 'failed' },
      { ...job(3, 'stock_history_import_rollback'), status: 'failed' },
      { ...job(2, 'stock_history_import_apply'), status: 'failed' },
    ])
    const wrapper = render()
    await flushPromises()
    const retried = wrapper.findAll('article').filter(article => article.findAll('button').some(button => button.text() === 'common.retry'))
    expect(retried.map(article => article.text().match(/#(\d+)/)?.[1])).toEqual(['5', '4'])
  })
  it('lets only a company administrator stop a stock history import', async () => {
    mocks.list.mockResolvedValue([{ ...job(5, 'stock_history_import_apply'), status: 'running' }])
    mocks.admin = false
    let wrapper = render()
    await flushPromises()
    expect(wrapper.get('[data-test="progress"]').attributes('data-can-cancel')).toBe('false')
    wrapper.unmount()
    mocks.admin = true
    wrapper = render()
    await flushPromises()
    expect(wrapper.get('[data-test="progress"]').attributes('data-can-cancel')).toBe('true')
  })
  it('requires both write permissions to reopen internal workflows', async () => {
    mocks.stockWrite = false
    mocks.list.mockResolvedValue([job(1)])
    const wrapper = render()
    await flushPromises()
    expect(wrapper.findAll('a')).toHaveLength(0)
  })
  it('discards an old company response after switching company', async () => {
    mocks.supplier = reactive({ currentSupplierId: 1 })
    let resolveOld!: (value: unknown[]) => void
    mocks.list.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
      .mockResolvedValueOnce([job(22)])
    const wrapper = render()
    mocks.supplier.currentSupplierId = 2
    await flushPromises()
    resolveOld([job(11)])
    await flushPromises()
    expect(wrapper.text()).toContain('#22')
    expect(wrapper.text()).not.toContain('#11')
  })
  it('does not regress a job when an older refresh arrives last', async () => {
    let resolveOld!: (value: unknown[]) => void
    mocks.list.mockImplementationOnce(() => new Promise(resolve => { resolveOld = resolve }))
      .mockResolvedValueOnce([job(22)])
    const wrapper = render()
    await (wrapper.vm as any).load(true)
    resolveOld([{ ...job(22), status: 'running' }])
    await flushPromises()
    expect(wrapper.text()).toContain('eshop.jobs.status.completed')
    expect(wrapper.text()).not.toContain('eshop.jobs.status.running')
  })
  it('does not clear a new company action when an old action finishes', async () => {
    mocks.supplier = reactive({ currentSupplierId: 1 })
    mocks.list.mockResolvedValue([job(22)])
    let resolveOld!: () => void
    let resolveNew!: () => void
    mocks.retry.mockImplementationOnce(() => new Promise<void>(resolve => { resolveOld = resolve }))
      .mockImplementationOnce(() => new Promise<void>(resolve => { resolveNew = resolve }))
    const wrapper = render()
    await flushPromises()
    const oldAction = (wrapper.vm as any).retry(11)
    mocks.supplier.currentSupplierId = 2
    await flushPromises()
    const newAction = (wrapper.vm as any).retry(22)
    resolveOld()
    await oldAction
    expect((wrapper.vm as any).actingId).toBe(22)
    resolveNew()
    await newAction
  })
})
