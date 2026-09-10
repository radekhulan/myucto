import { flushPromises, mount } from '@vue/test-utils'
import { nextTick, reactive } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ProductImport from '../ProductImport.vue'

const mocks = vi.hoisted(() => ({
  canWrite: vi.fn<(permission: string) => boolean>(),
  upload: vi.fn(),
  sample: vi.fn(),
  profiles: vi.fn(),
  createProfile: vi.fn(),
  updateProfile: vi.fn(),
  preview: vi.fn(),
  apply: vi.fn(),
  items: vi.fn(),
  getJob: vi.fn(),
  cancelJob: vi.fn(),
  retryJob: vi.fn(),
  replaceRoute: vi.fn(),
}))

const supplierStore = reactive({ currentSupplierId: 1 })
const routeState = reactive<{ query: Record<string, string | undefined> }>({ query: {} })

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) => params ? `${key}:${JSON.stringify(params)}` : key,
  }),
}))

vi.mock('vue-router', () => ({
  useRoute: () => routeState,
  useRouter: () => ({ replace: mocks.replaceRoute }),
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: mocks.canWrite }),
}))

vi.mock('@/stores/supplier', () => ({
  useSupplierStore: () => supplierStore,
}))

vi.mock('@/api/catalogImport', () => ({
  catalogImportApi: {
    upload: mocks.upload,
    sample: mocks.sample,
    profiles: mocks.profiles,
    createProfile: mocks.createProfile,
    updateProfile: mocks.updateProfile,
    preview: mocks.preview,
    apply: mocks.apply,
    items: mocks.items,
  },
}))

vi.mock('@/api/catalogJobs', () => ({
  catalogJobsApi: {
    get: mocks.getJob,
    cancel: mocks.cancelJob,
    retry: mocks.retryJob,
  },
}))

const source = {
  id: 14,
  original_name: 'catalog.csv',
  format: 'csv' as const,
  size_bytes: 128,
  sha256: 'abc',
}

const sample = {
  source,
  header: ['SKU', 'Název', 'cena', 'Ceny JSON'],
  rows: [['A-1', 'První', '123,45', '[]']],
  fields: ['sku', 'name', 'price', 'prices'],
}

const abraPreset = {
  id: 'abra-flexi-cenik-csv-v1',
  system: 'abra_flexi',
  version: 1,
  format: 'csv',
  documentation_url: 'https://example.test/abra',
  schema_evidence: 'public_demo_header',
  version_export_verified: false,
  supported_columns: [
    { source: 'SKU', target: 'sku' },
    { source: 'Název', target: 'name' },
  ],
  config: {
    identity: 'sku',
    source_key: null,
    mode: 'upsert',
    mapping: { sku: 'SKU', name: 'Název' },
    blank: 'preserve',
    operations: {},
    reader: { encoding: 'UTF-8', delimiter: ';', sheet: 0 },
  },
}

function job(overrides: Record<string, unknown> = {}) {
  return {
    id: 31,
    supplier_id: 1,
    kind: 'catalog_import_stage',
    input_version: null,
    status: 'completed',
    checkpoint: 1,
    total: 1,
    report: { counts: { ready: 1 } },
    error_code: null,
    cancel_requested: false,
    created_at: '2026-09-10 10:00:00',
    updated_at: '2026-09-10 10:00:00',
    finished_at: '2026-09-10 10:00:01',
    attempts: 1,
    ...overrides,
  }
}

function report(status = 'ready') {
  return {
    items: [{
      ordinal: 1,
      source_row: 2,
      stock_item_id: 8,
      status,
      before: {
        id: 8,
        row_version: 3,
        sku: 'A-1',
        price: '100.00',
        prices: [{ currency_code: 'CZK', fixed_price: '100.00', computed_context: { internal: true } }],
      },
      after: {
        id: 8,
        row_version: 4,
        sku: 'A-1',
        price: '123.45',
        prices: [{ currency_code: 'CZK', fixed_price: '123.45', computed_context: { internal: false } }],
      },
      error_code: null,
      input: { raw: ['A-1', 'První', '123,45', '[]'] },
    }],
    pagination: { page: 1, pages: 1, total: 1, limit: 50 },
  }
}

function mountPage() {
  return mount(ProductImport, {
    global: {
      stubs: {
        CatalogJobProgress: {
          props: ['job', 'cancelling', 'canCancel'],
          emits: ['cancel'],
          template: '<button v-if="canCancel" data-test="cancel-job" @click="$emit(\'cancel\')">cancel</button>',
        },
      },
    },
  })
}

async function uploadFile(wrapper: ReturnType<typeof mountPage>) {
  const input = wrapper.get('[data-test="file-input"]')
  Object.defineProperty(input.element, 'files', {
    configurable: true,
    value: [new File(['SKU;Název'], 'catalog.csv', { type: 'text/csv' })],
  })
  await input.trigger('change')
  await flushPromises()
}

describe('ProductImport', () => {
  it('removes the resumed job from the URL when starting a new import', async () => {
    routeState.query = { tab: 'import', import_job: '31' }
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="new-import"]').trigger('click')
    expect(mocks.replaceRoute).toHaveBeenCalledWith({ query: { tab: 'import' } })
    expect(wrapper.find('[data-test="file-input"]').exists()).toBe(true)
    wrapper.unmount()
  })

  beforeEach(() => {
    vi.clearAllMocks()
    supplierStore.currentSupplierId = 1
    routeState.query = {}
    mocks.canWrite.mockReturnValue(true)
    mocks.upload.mockResolvedValue(source)
    mocks.sample.mockResolvedValue(sample)
    mocks.profiles.mockResolvedValue({ items: [], presets: [] })
    mocks.items.mockResolvedValue(report())
    mocks.preview.mockResolvedValue(job())
    mocks.getJob.mockResolvedValue(job())
    mocks.apply.mockResolvedValue(job({
      id: 32,
      kind: 'catalog_import_apply',
      report: { counts: { applied: 1 } },
    }))
  })

  it('uploads, auto-maps the CZK price, previews, and applies ready rows', async () => {
    mocks.items
      .mockResolvedValueOnce(report('ready'))
      .mockResolvedValueOnce(report('applied'))
    const wrapper = mountPage()

    await uploadFile(wrapper)

    expect(mocks.upload).toHaveBeenCalledWith(expect.any(File), expect.any(AbortSignal))
    expect(wrapper.find('[data-test="mapping-step"]').exists()).toBe(true)
    expect((wrapper.get('[data-test="mapping-select-price"]').element as HTMLSelectElement).value).toBe('cena')
    expect(wrapper.get('[data-test="mapping-select-prices"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="preview-import"]').trigger('click')
    await flushPromises()

    expect(mocks.preview).toHaveBeenCalledWith(14, expect.objectContaining({
      identity: 'sku',
      mapping: expect.objectContaining({ sku: 'SKU', name: 'Název', price: 'cena' }),
      blank: 'preserve',
    }), expect.any(AbortSignal))
    expect(mocks.items).toHaveBeenNthCalledWith(1, 31, 1, expect.any(AbortSignal))
    expect(wrapper.text()).toContain('eshop.import2.status.ready')
    expect(wrapper.text()).toContain('CZK: 123.45')
    expect(wrapper.text()).not.toContain('eshop.import2.fields.id')
    expect(wrapper.text()).not.toContain('eshop.import2.fields.row_version')
    expect(wrapper.text()).not.toContain('computed_context')

    await wrapper.get('[data-test="apply-import"]').trigger('click')
    await flushPromises()

    expect(mocks.apply).toHaveBeenCalledWith(31, expect.any(AbortSignal))
    expect(mocks.items).toHaveBeenNthCalledWith(2, 32, 1, expect.any(AbortSignal))
    expect(wrapper.text()).toContain('eshop.import2.status.applied')
  })

  it('resumes a catalog import job from the route query and loads its report', async () => {
    routeState.query = { import_job: '31' }
    const wrapper = mountPage()
    await flushPromises()

    expect(mocks.getJob).toHaveBeenCalledWith(31, expect.any(AbortSignal))
    expect(mocks.items).toHaveBeenCalledWith(31, 1, expect.any(AbortSignal))
    expect(wrapper.find('[data-test="preview-step"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="apply-import"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="back-mapping"]').exists()).toBe(false)
  })

  it('rejects a resumed job returned outside the active supplier scope', async () => {
    routeState.query = { import_job: '31' }
    mocks.getJob.mockResolvedValue(job({ supplier_id: 2 }))
    const wrapper = mountPage()
    await flushPromises()

    expect(wrapper.find('[data-test="source-step"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="preview-step"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="import-error"]').text()).toBe('eshop.import2.resume_error')
    expect(mocks.items).not.toHaveBeenCalled()
  })

  it('discards and aborts a late preview response after switching supplier', async () => {
    let resolvePreview!: (value: ReturnType<typeof job>) => void
    mocks.preview.mockReturnValue(new Promise(resolve => { resolvePreview = resolve }))
    const wrapper = mountPage()
    await uploadFile(wrapper)

    await wrapper.get('[data-test="preview-import"]').trigger('click')
    const signal = mocks.preview.mock.calls[0][2] as AbortSignal
    supplierStore.currentSupplierId = 2
    await nextTick()
    resolvePreview(job())
    await flushPromises()

    expect(signal.aborted).toBe(true)
    expect(wrapper.find('[data-test="source-step"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="preview-step"]').exists()).toBe(false)
    expect(mocks.items).not.toHaveBeenCalled()
    expect(mocks.getJob).not.toHaveBeenCalled()
  })

  it('cancels an active job, aborts its poll, and allows a retry', async () => {
    mocks.preview.mockResolvedValue(job({
      status: 'queued',
      checkpoint: 0,
      finished_at: null,
    }))
    mocks.getJob.mockReturnValue(new Promise(() => {}))
    mocks.cancelJob.mockResolvedValue(job({ status: 'cancelled' }))
    mocks.retryJob.mockResolvedValue(job())
    const wrapper = mountPage()
    await uploadFile(wrapper)

    await wrapper.get('[data-test="preview-import"]').trigger('click')
    await flushPromises()
    const pollSignal = mocks.getJob.mock.calls[0][1] as AbortSignal

    await wrapper.get('[data-test="cancel-job"]').trigger('click')
    await flushPromises()

    expect(pollSignal.aborted).toBe(true)
    expect(mocks.cancelJob).toHaveBeenCalledWith(31, expect.any(AbortSignal))
    expect(wrapper.find('[data-test="retry-job"]').exists()).toBe(true)

    await wrapper.get('[data-test="retry-job"]').trigger('click')
    await flushPromises()

    expect(mocks.retryJob).toHaveBeenCalledWith(31, expect.any(AbortSignal))
    expect(wrapper.text()).toContain('eshop.import2.status.ready')
  })

  it('keeps all import mutations unavailable without both write permissions', async () => {
    mocks.canWrite.mockImplementation(permission => permission !== 'stock.items.write')
    const wrapper = mountPage()

    expect(wrapper.find('[data-test="readonly-notice"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="file-input"]').attributes('disabled')).toBeDefined()

    await uploadFile(wrapper)

    expect(mocks.upload).not.toHaveBeenCalled()
    expect(mocks.preview).not.toHaveBeenCalled()
    expect(mocks.apply).not.toHaveBeenCalled()
    expect(mocks.createProfile).not.toHaveBeenCalled()
    expect(mocks.updateProfile).not.toHaveBeenCalled()
  })

  it('loads an existing mapping profile and updates it with optimistic versioning', async () => {
    const profile = {
      id: 7,
      name: 'Dodavatel A',
      version: 4,
      config: {
        identity: 'sku' as const,
        source_key: null,
        mode: 'update' as const,
        mapping: { sku: 'SKU', name: 'Název' },
        blank: 'preserve' as const,
        operations: { sku: 'set' as const, name: 'set' as const },
        reader: { encoding: 'UTF-8' as const, delimiter: ';' as const, sheet: 0 },
      },
    }
    mocks.profiles.mockResolvedValue({ items: [profile], presets: [] })
    mocks.updateProfile.mockResolvedValue({ ...profile, name: 'Dodavatel B', version: 5 })
    const wrapper = mountPage()
    await uploadFile(wrapper)

    await wrapper.get('[data-test="profile-select"]').setValue('7')
    await flushPromises()
    expect((wrapper.get('[data-test="profile-name"]').element as HTMLInputElement).value).toBe('Dodavatel A')
    expect(mocks.sample).toHaveBeenCalledTimes(2)

    await wrapper.get('[data-test="profile-name"]').setValue('Dodavatel B')
    await wrapper.get('[data-test="save-profile"]').trigger('click')
    await flushPromises()

    expect(mocks.updateProfile).toHaveBeenCalledWith(
      7,
      'Dodavatel B',
      expect.objectContaining({
        identity: 'sku',
        mapping: expect.objectContaining({ sku: 'SKU', name: 'Název' }),
      }),
      4,
      expect.any(AbortSignal),
    )
  })

  it('keeps the source key when an SKU profile maps a parent by external ID', async () => {
    const parentProfile = {
      id: 8,
      name: 'Varianty dodavatele',
      version: 1,
      config: {
        identity: 'sku' as const,
        source_key: 'supplier_catalog',
        mode: 'upsert' as const,
        mapping: { sku: 'SKU', master_external_id: 'Externí ID masteru' },
        blank: 'preserve' as const,
        operations: { sku: 'set' as const, master_external_id: 'set' as const },
        reader: { encoding: 'UTF-8' as const, delimiter: ';' as const, sheet: 0 },
      },
    }
    mocks.sample.mockResolvedValue({
      ...sample,
      header: [...sample.header, 'Externí ID masteru'],
      fields: [...sample.fields, 'master_external_id'],
    })
    mocks.profiles.mockResolvedValue({ items: [parentProfile], presets: [] })
    const wrapper = mountPage()
    await uploadFile(wrapper)

    await wrapper.get('[data-test="profile-select"]').setValue('8')
    await flushPromises()

    expect((wrapper.get('[data-test="source-key"]').element as HTMLInputElement).value).toBe('supplier_catalog')
    expect(wrapper.get('[data-test="preview-import"]').attributes('disabled')).toBeUndefined()

    await wrapper.get('[data-test="preview-import"]').trigger('click')
    await flushPromises()

    expect(mocks.preview).toHaveBeenCalledWith(14, expect.objectContaining({
      identity: 'sku',
      source_key: 'supplier_catalog',
      mapping: expect.objectContaining({ sku: 'SKU', master_external_id: 'Externí ID masteru' }),
    }), expect.any(AbortSignal))
  })

  it('waits for the polling interval after an active job response', async () => {
    vi.useFakeTimers()
    mocks.preview.mockResolvedValue(job({ status: 'queued', checkpoint: 0, finished_at: null }))
    mocks.getJob
      .mockResolvedValueOnce(job({ status: 'running', checkpoint: 0, finished_at: null }))
      .mockReturnValue(new Promise(() => {}))
    const wrapper = mountPage()

    try {
      await uploadFile(wrapper)
      await wrapper.get('[data-test="preview-import"]').trigger('click')
      await flushPromises()

      expect(mocks.getJob).toHaveBeenCalledTimes(1)
      await vi.advanceTimersByTimeAsync(1999)
      expect(mocks.getJob).toHaveBeenCalledTimes(1)
      await vi.advanceTimersByTimeAsync(1)
      expect(mocks.getJob).toHaveBeenCalledTimes(2)
    } finally {
      wrapper.unmount()
      vi.useRealTimers()
    }
  })

  it('applies an ERP preset while keeping its mapping editable and refreshing the sample', async () => {
    mocks.profiles.mockResolvedValue({ items: [], presets: [abraPreset] })
    const wrapper = mountPage()
    await uploadFile(wrapper)

    await wrapper.get('[data-test="apply-preset-abra-flexi-cenik-csv-v1"]').trigger('click')
    await flushPromises()

    expect(mocks.sample).toHaveBeenCalledTimes(2)
    expect((wrapper.get('[data-test="mapping-select-sku"]').element as HTMLSelectElement).value).toBe('SKU')
    expect((wrapper.get('[data-test="mapping-select-name"]').element as HTMLSelectElement).value).toBe('Název')

    await wrapper.get('[data-test="mapping-select-name"]').setValue('')
    expect((wrapper.get('[data-test="mapping-select-name"]').element as HTMLSelectElement).value).toBe('')
  })

  it('shows media URL mapping and follows the separate media job after apply', async () => {
    mocks.sample.mockResolvedValue({
      ...sample,
      header: [...sample.header, 'Média'],
      rows: [['A-1', 'První', '123,45', '[]', '["https://cdn.example.test/a.png"]']],
      fields: [...sample.fields, 'media_urls'],
    })
    mocks.apply.mockResolvedValue(job({
      id: 32,
      kind: 'catalog_import_apply',
      report: { counts: { applied: 1 }, media_job_id: 44 },
    }))
    mocks.getJob.mockResolvedValue(job({
      id: 44,
      kind: 'catalog_import_media',
      report: { counts: { applied: 1 }, source_job_id: 32 },
    }))
    mocks.items
      .mockResolvedValueOnce(report('ready'))
      .mockResolvedValueOnce(report('applied'))

    const wrapper = mountPage()
    await uploadFile(wrapper)

    expect(wrapper.find('[data-test="advanced-fields"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="mapping-select-media_urls"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="operation-media_urls"] option[value="clear"]').attributes('disabled')).toBeDefined()

    await wrapper.get('[data-test="preview-import"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="apply-import"]').trigger('click')
    await flushPromises()

    expect(mocks.getJob).toHaveBeenCalledWith(44)
    expect(mocks.items).toHaveBeenLastCalledWith(44, 1, expect.any(AbortSignal))
    expect(wrapper.text()).toContain('eshop.import2.media_report_title')
  })

  it('keeps apply conflicts accessible while the media child job runs', async () => {
    mocks.apply.mockResolvedValue(job({
      id: 32,
      kind: 'catalog_import_apply',
      report: { counts: { applied: 1, failed: 2, conflict: 1 }, media_job_id: 44 },
    }))
    mocks.getJob.mockResolvedValue(job({
      id: 44,
      kind: 'catalog_import_media',
      status: 'running',
      finished_at: null,
      report: { counts: { applied: 1 }, source_job_id: 32 },
    }))
    mocks.items
      .mockResolvedValueOnce(report('ready'))
      .mockResolvedValueOnce(report('conflict'))

    const wrapper = mountPage()
    await uploadFile(wrapper)
    await wrapper.get('[data-test="preview-import"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="apply-import"]').trigger('click')
    await flushPromises()

    expect(mocks.items).toHaveBeenNthCalledWith(2, 32, 1, expect.any(AbortSignal))
    expect(wrapper.get('[data-test="apply-failure-summary"]').text()).toContain('eshop.import2.status.failed: 2')
    expect(wrapper.get('[data-test="apply-failure-summary"]').text()).toContain('eshop.import2.status.conflict: 1')
    expect(wrapper.text()).toContain('eshop.import2.status.conflict')
    expect(wrapper.find('[data-test="show-media-report"]').exists()).toBe(true)

    wrapper.unmount()
  })

  it('does not attach the old media child after the supplier changes while loading the apply report', async () => {
    let resolveApplyReport!: (value: ReturnType<typeof report>) => void
    mocks.apply.mockResolvedValue(job({
      id: 32,
      kind: 'catalog_import_apply',
      report: { counts: { applied: 1, conflict: 1 }, media_job_id: 44 },
    }))
    mocks.items
      .mockResolvedValueOnce(report('ready'))
      .mockReturnValueOnce(new Promise(resolve => { resolveApplyReport = resolve }))

    const wrapper = mountPage()
    await uploadFile(wrapper)
    await wrapper.get('[data-test="preview-import"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="apply-import"]').trigger('click')
    await nextTick()

    supplierStore.currentSupplierId = 2
    await nextTick()
    resolveApplyReport(report('conflict'))
    await flushPromises()

    expect(mocks.getJob).not.toHaveBeenCalledWith(44)
    expect(wrapper.find('[data-test="source-step"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="preview-step"]').exists()).toBe(false)

    wrapper.unmount()
  })
})
