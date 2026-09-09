import { config, flushPromises, shallowMount } from '@vue/test-utils'
import { afterEach, describe, expect, it, vi } from 'vitest'
import type { CatalogJob } from '@/api/catalogJobs'

const jobs = vi.hoisted(() => ({
  create: vi.fn(),
  get: vi.fn(),
  cancel: vi.fn(),
  retry: vi.fn(),
}))

function deferred<T>() {
  let resolve: (value: T) => void
  const promise = new Promise<T>(resolver => {
    resolve = resolver
  })
  return { promise, resolve: resolve! }
}

vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key }) }))
vi.mock('@/api/catalogExport', () => ({
  CATALOG_EXPORT_FIELDS: ['sku', 'name', 'ean', 'is_active', 'prices', 'availability'],
  catalogExportApi: {
    create: jobs.create,
    downloadUrl: (id: number, supplierId: number) => `/api/catalog/exports/${id}/download?supplier_id=${supplierId}`,
  },
}))
vi.mock('@/api/catalogJobs', () => ({
  catalogJobsApi: { get: jobs.get, cancel: jobs.cancel, retry: jobs.retry },
}))

import CatalogExportDialog from '../CatalogExportDialog.vue'

config.global.renderStubDefaultSlot = true

const queuedJob = {
  id: 81,
  supplier_id: 1,
  kind: 'catalog_export',
  input_version: 5,
  status: 'queued' as const,
  checkpoint: 0,
  total: 2,
  report: null,
  error_code: null,
  cancel_requested: false,
  created_at: '2026-09-09 12:00:00',
  updated_at: '2026-09-09 12:00:00',
  finished_at: null,
  attempts: 1,
}

describe('CatalogExportDialog', () => {
  afterEach(() => {
    vi.useRealTimers()
    vi.clearAllMocks()
  })

  it('creates the selected export with its default projection and polls until it is downloadable', async () => {
    vi.useFakeTimers()
    jobs.create.mockResolvedValue(queuedJob)
    jobs.get.mockResolvedValue({
      ...queuedJob,
      status: 'completed',
      checkpoint: 2,
      report: { processed: 2, counts: { ready: 2, failed: 0, conflict: 0 } },
      finished_at: '2026-09-09 12:00:04',
    })

    const selection = { all_matching: false as const, ids: [41, 42] }
    const wrapper = shallowMount(CatalogExportDialog, {
      props: {
        selection,
        selectedCount: 2,
        canManageJobs: false,
      },
      global: {
        stubs: { CatalogJobProgress: { template: '<div />' } },
      },
    })

    await wrapper.get('[data-test="catalog-export-start"]').trigger('click')
    await flushPromises()

    expect(jobs.create).toHaveBeenCalledWith({
      selection,
      projection: {
        fields: ['sku', 'name', 'ean', 'is_active'],
        locales: ['cs'],
        currencies: ['CZK'],
      },
    })

    await vi.advanceTimersByTimeAsync(2_000)
    await flushPromises()

    expect(jobs.get).toHaveBeenCalledWith(81)
    expect(wrapper.get('a[download]').attributes('href')).toBe('/api/catalog/exports/81/download?supplier_id=1')
    expect(wrapper.text()).toContain('stock.items.export.report.exported')
    expect(wrapper.text()).toContain('stock.items.export.readonly_jobs_hint')
    expect(jobs.cancel).not.toHaveBeenCalled()
    expect(jobs.retry).not.toHaveBeenCalled()
  })

  it('reopens a completed export without creating another job and offers its download', async () => {
    jobs.get.mockResolvedValue({
      ...queuedJob,
      status: 'completed',
      checkpoint: 2,
      report: { processed: 2, counts: { ready: 2, failed: 0, conflict: 0 } },
      finished_at: '2026-09-09 12:00:04',
    })
    const wrapper = shallowMount(CatalogExportDialog, {
      props: {
        initialJobId: 81,
        selection: { all_matching: false, ids: [1] },
        selectedCount: 0,
        canManageJobs: false,
      },
    })
    await flushPromises()

    expect(jobs.get).toHaveBeenCalledWith(81)
    expect(jobs.create).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="catalog-export-start"]').exists()).toBe(false)
    expect(wrapper.find('input').exists()).toBe(false)
    expect(wrapper.get('a[download]').attributes('href')).toBe('/api/catalog/exports/81/download?supplier_id=1')
  })

  it('keeps a newer cancellation result when an earlier poll completes late', async () => {
    vi.useFakeTimers()
    const delayedPoll = deferred<CatalogJob>()
    jobs.create.mockResolvedValue({ ...queuedJob, status: 'running' })
    jobs.get.mockReturnValue(delayedPoll.promise)
    jobs.cancel.mockResolvedValue({
      ...queuedJob,
      status: 'cancelled',
      cancel_requested: true,
      finished_at: '2026-09-09 12:00:03',
    })

    const wrapper = shallowMount(CatalogExportDialog, {
      props: {
        selection: { all_matching: false, ids: [41, 42] },
        selectedCount: 2,
        canManageJobs: true,
      },
      global: {
        stubs: { CatalogJobProgress: { name: 'CatalogJobProgress', props: ['job'], template: '<button @click="$emit(\'cancel\')" />' } },
      },
    })

    await wrapper.get('[data-test="catalog-export-start"]').trigger('click')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(2_000)

    await wrapper.findComponent({ name: 'CatalogJobProgress' }).vm.$emit('cancel')
    await flushPromises()
    delayedPoll.resolve({ ...queuedJob, status: 'running' })
    await flushPromises()

    expect(jobs.cancel).toHaveBeenCalledWith(81)
    expect(wrapper.findComponent({ name: 'CatalogJobProgress' }).props('job')).toMatchObject({ status: 'cancelled' })
  })

  it('does not send a cancellation request for a read-only export', async () => {
    jobs.create.mockResolvedValue({ ...queuedJob, status: 'running' })
    const wrapper = shallowMount(CatalogExportDialog, {
      props: {
        selection: { all_matching: false, ids: [41] },
        selectedCount: 1,
        canManageJobs: false,
      },
      global: {
        stubs: { CatalogJobProgress: { name: 'CatalogJobProgress', props: ['job'], template: '<button @click="$emit(\'cancel\')" />' } },
      },
    })

    await wrapper.get('[data-test="catalog-export-start"]').trigger('click')
    await flushPromises()
    await wrapper.findComponent({ name: 'CatalogJobProgress' }).vm.$emit('cancel')

    expect(jobs.cancel).not.toHaveBeenCalled()
  })

  it('locks the projection and prevents a second create request while a job is running', async () => {
    const pendingCreate = deferred<CatalogJob>()
    jobs.create.mockReturnValue(pendingCreate.promise)
    const wrapper = shallowMount(CatalogExportDialog, {
      props: {
        selection: { all_matching: false, ids: [41] },
        selectedCount: 1,
        canManageJobs: false,
      },
    })

    const start = wrapper.get('[data-test="catalog-export-start"]')
    await start.trigger('click')
    await start.trigger('click')

    expect(jobs.create).toHaveBeenCalledTimes(1)
    expect(start.attributes('disabled')).toBeDefined()

    pendingCreate.resolve({ ...queuedJob, status: 'running' })
    await flushPromises()

    expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined()
    expect(wrapper.get('input[type="text"]').attributes('disabled')).toBeDefined()
  })

  it('does not restart polling after an unmounted create request resolves', async () => {
    vi.useFakeTimers()
    const pendingCreate = deferred<CatalogJob>()
    jobs.create.mockReturnValue(pendingCreate.promise)
    const wrapper = shallowMount(CatalogExportDialog, {
      props: {
        selection: { all_matching: false, ids: [41] },
        selectedCount: 1,
        canManageJobs: false,
      },
    })

    await wrapper.get('[data-test="catalog-export-start"]').trigger('click')
    wrapper.unmount()
    pendingCreate.resolve({ ...queuedJob, status: 'running' })
    await flushPromises()
    await vi.advanceTimersByTimeAsync(4_000)

    expect(jobs.get).not.toHaveBeenCalled()
  })

  it('resumes polling after a cancellation request fails', async () => {
    vi.useFakeTimers()
    jobs.create.mockResolvedValue({ ...queuedJob, status: 'running' })
    jobs.cancel.mockRejectedValue(new Error('network'))
    jobs.get.mockResolvedValue({ ...queuedJob, status: 'running' })
    const wrapper = shallowMount(CatalogExportDialog, {
      props: {
        selection: { all_matching: false, ids: [41] },
        selectedCount: 1,
        canManageJobs: true,
      },
      global: {
        stubs: { CatalogJobProgress: { name: 'CatalogJobProgress', props: ['job'], template: '<button @click="$emit(\'cancel\')" />' } },
      },
    })

    await wrapper.get('[data-test="catalog-export-start"]').trigger('click')
    await flushPromises()
    await wrapper.findComponent({ name: 'CatalogJobProgress' }).vm.$emit('cancel')
    await flushPromises()
    await vi.advanceTimersByTimeAsync(2_000)

    expect(jobs.cancel).toHaveBeenCalledWith(81)
    expect(jobs.get).toHaveBeenCalledWith(81)
  })
})
