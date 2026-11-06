import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, shallowMount } from '@vue/test-utils'
import StockHistoryImport from '../StockHistoryImport.vue'
import DateInput from '@/components/ui/DateInput.vue'

const mocks = vi.hoisted(() => ({
  status: vi.fn(), upload: vi.fn(), sample: vi.fn(), preview: vi.fn(), job: vi.fn(), items: vi.fn(),
  apply: vi.fn(), rollback: vi.fn(), retry: vi.fn(), cancel: vi.fn(), replace: vi.fn(), endState: vi.fn(), downloadEndState: vi.fn(),
  route: { query: {} as Record<string, string> },
  auth: { canWrite: () => true, isCompanyAdminRole: true },
  supplierStore: undefined as any,
}))
vi.mock('@/api/stockHistoryImport', () => ({ stockHistoryImportApi: {
  status: mocks.status, upload: mocks.upload, sample: mocks.sample, preview: mocks.preview, job: mocks.job,
  items: mocks.items, apply: mocks.apply, rollback: mocks.rollback, retry: mocks.retry, cancel: mocks.cancel,
  endState: mocks.endState, downloadEndState: mocks.downloadEndState,
} }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => mocks.auth }))
vi.mock('@/stores/supplier', async () => {
  const { reactive } = await import('vue')
  mocks.supplierStore = reactive({ currentSupplierId: 1 })
  return { useSupplierStore: () => mocks.supplierStore }
})
vi.mock('vue-i18n', async () => {
  const { ref } = await import('vue')
  return { useI18n: () => ({ t: (key: string) => key, locale: ref('cs') }) }
})
vi.mock('@/composables/useFormat', () => ({
  formatDate: (value: string | null | undefined) => value ?? '—',
  formatNumber: (value: number) => String(value),
}))
vi.mock('vue-router', () => ({ useRoute: () => mocks.route, useRouter: () => ({ replace: mocks.replace }) }))

const header = ['doklad', 'typ', 'datum', 'sklad', 'sklad_cil', 'sku', 'mnozstvi', 'cena']
const sample = { source: { id: 7, original_name: 'historie.csv', format: 'csv', size_bytes: 10, sha256: 'x', created_at: '' },
  header, rows: [['P1', 'prijemka', '2020-01-02', 'HL', '', 'ZB-1', '2', '10']],
  fields: ['doc_number', 'doc_type', 'doc_date', 'warehouse', 'sku', 'quantity'],
  optional_fields: ['warehouse_to', 'unit_cost', 'partner', 'description', 'invoice_number', 'purchase_invoice_number', 'note'] }
const stage = { id: 41, supplier_id: 1, kind: 'stock_history_import_stage', input_version: 1, status: 'completed',
  checkpoint: 3, total: 3, error_code: null, cancel_requested: false, created_at: '', updated_at: '', finished_at: null, attempts: 1,
  report: { counts: { ready: 3 }, cutover_date: '2021-01-01', fill_negative: false, documents: { receipt: 1, issue: 1, transfer: 0, surplus: 0, shortage: 0 },
    lines: 3, date_from: '2020-01-02', date_to: '2020-02-01', negative_count: 0, negatives: [], closed_period_documents: 0 } }
const emptyItems = { items: [], pagination: { page: 1, limit: 50, total: 0, pages: 0 } }

function mountPage(realDateInput = false) {
  return shallowMount(StockHistoryImport, { global: { stubs: {
    DateInput: !realDateInput,
    RouterLink: true,
    CatalogJobProgress: { name: 'CatalogJobProgress', props: ['job', 'canCancel'], emits: ['cancel'], template: '<div data-test="progress">{{ job.kind }}</div>' },
  } } })
}

async function uploadFile(wrapper: ReturnType<typeof mountPage>) {
  const input = wrapper.get('input[type="file"]')
  Object.defineProperty(input.element, 'files', { configurable: true, value: [new File(['x'], 'historie.csv')] })
  await input.trigger('change')
  await flushPromises()
}

beforeEach(() => {
  mocks.route.query = {}
  mocks.auth.isCompanyAdminRole = true
  mocks.supplierStore.currentSupplierId = 1
  mocks.status.mockResolvedValue({ locked_until: null, first_document_date: '2021-01-01', active: null, orphan_job_id: null, max_rows: 300000 })
  mocks.upload.mockResolvedValue(sample.source)
  mocks.sample.mockResolvedValue(sample)
  mocks.items.mockResolvedValue(emptyItems)
  mocks.endState.mockResolvedValue({ cutover_date: '2021-01-01', items: [], totals: { count: 0, differences: 0 } })
  mocks.replace.mockImplementation(async ({ query }: { query: Record<string, string> }) => { mocks.route.query = query })
})

afterEach(() => vi.clearAllMocks())

describe('StockHistoryImport', () => {
  it.each(['31. 2. 2020', '2. 1. 2021'])('blocks invalid or over-limit cutover text %s before preview', async value => {
    mocks.preview.mockResolvedValue(stage)
    mocks.job.mockResolvedValue(stage)
    const wrapper = mountPage(true)
    await flushPromises()
    await uploadFile(wrapper)
    await wrapper.get('[data-test="source-key"]').setValue('stary-sklad')
    const date = wrapper.getComponent(DateInput)
    const text = date.get<HTMLInputElement>('input[type="text"]')
    await text.trigger('focus')
    await text.setValue(value)
    expect(date.props('modelValue')).toBe(value === '31. 2. 2020' ? '2021-01-01' : '2021-01-02')
    expect(text.element.checkValidity()).toBe(false)
    await wrapper.get('[data-test="create-preview"]').trigger('click')
    await flushPromises()
    expect(mocks.preview).not.toHaveBeenCalled()
    await text.setValue('1. 1. 2020')
    await wrapper.get('[data-test="create-preview"]').trigger('click')
    await flushPromises()
    expect(mocks.preview).toHaveBeenCalledWith(7, expect.objectContaining({ cutover_date: '2020-01-01' }))
    wrapper.unmount()
  })

  it.each(['upload', 'preview', 'apply', 'report'] as const)('releases the new company after a pending %s and ignores its late response', async operation => {
    let resolve!: (value: any) => void
    const pending = new Promise<any>(done => { resolve = done })
    const pages = { ...emptyItems, pagination: { page: 1, limit: 50, total: 100, pages: 2 } }
    if (operation === 'apply' || operation === 'report') {
      mocks.route.query = { history_job: '41' }
      mocks.job.mockResolvedValue(stage)
      mocks.items.mockResolvedValue(pages)
    }
    const wrapper = mountPage()
    await flushPromises()
    if (operation === 'upload') {
      mocks.upload.mockReturnValueOnce(pending)
      await uploadFile(wrapper)
    } else if (operation === 'preview') {
      await uploadFile(wrapper)
      await wrapper.get('[data-test="source-key"]').setValue('stary-sklad')
      mocks.preview.mockReturnValueOnce(pending)
      await wrapper.get('[data-test="create-preview"]').trigger('click')
    } else if (operation === 'apply') {
      mocks.apply.mockReturnValueOnce(pending)
      await wrapper.get('[data-test="apply-import"]').trigger('click')
    } else {
      mocks.items.mockReturnValueOnce(pending)
      await wrapper.findAll('button').find(button => button.text() === 'common.next')!.trigger('click')
    }
    await flushPromises()
    mocks.status.mockResolvedValue({ locked_until: null, first_document_date: '2022-02-01', active: null, orphan_job_id: null, max_rows: 300000 })
    mocks.supplierStore.currentSupplierId = 2
    await flushPromises()
    expect(wrapper.get('input[type="file"]').attributes('disabled')).toBeUndefined()
    expect(wrapper.getComponent(DateInput).props('modelValue')).toBe('2022-02-01')
    if (operation === 'report') {
      const next = { ...stage, id: 62, supplier_id: 2 }
      mocks.preview.mockResolvedValueOnce(next)
      mocks.job.mockResolvedValue(next)
      mocks.items.mockResolvedValue(pages)
      await uploadFile(wrapper)
      await wrapper.get('[data-test="source-key"]').setValue('novy-sklad')
      await wrapper.get('[data-test="create-preview"]').trigger('click')
      await flushPromises()
      expect(wrapper.findAll('button').find(button => button.text() === 'common.next')!.attributes('disabled')).toBeUndefined()
    }
    const sampleCalls = mocks.sample.mock.calls.length
    resolve(operation === 'upload' ? { ...sample.source, original_name: 'OLD-TENANT.csv' }
      : operation === 'report' ? { ...pages, items: [{ ordinal: 2, source_row: 3, status: 'ready', error_code: null,
        input: { values: { doc_number: 'OLD-TENANT', sku: 'OLD-TENANT' } } }] }
        : { ...stage, id: 41, supplier_id: 1 })
    await flushPromises()
    expect(wrapper.text()).not.toContain('OLD-TENANT')
    expect(mocks.sample).toHaveBeenCalledTimes(sampleCalls)
    expect(mocks.route.query.history_job).toBe(operation === 'report' ? '62' : undefined)
    wrapper.unmount()
  })

  it('closes the old company rollback dialog and clears its confirmation', async () => {
    const active = { ...stage, id: 50, kind: 'stock_history_import_apply', input: { profile: { cutover_date: null } } }
    mocks.status.mockResolvedValue({ locked_until: '2020-02-01', first_document_date: null, active, orphan_job_id: null, max_rows: 300000 })
    mocks.rollback.mockRejectedValueOnce({ response: { data: { error: { code: 'history_import_closed_periods_unconfirmed' } } } })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="rollback-open"]').trigger('click')
    await wrapper.get('[data-test="rollback-confirm"]').trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="rollback-confirm-closed"]').setValue(true)
    mocks.status.mockResolvedValue({ locked_until: '2020-02-01', first_document_date: null,
      active: { ...active, id: 60, supplier_id: 2 }, orphan_job_id: null, max_rows: 300000 })
    mocks.supplierStore.currentSupplierId = 2
    await flushPromises()
    expect(wrapper.find('[data-test="rollback-confirm"]').exists()).toBe(false)
    await wrapper.get('[data-test="rollback-open"]').trigger('click')
    expect(wrapper.find('[data-test="rollback-confirm-closed"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('proposes the first stock document as cut-over and blocks the live mode for a company with documents', async () => {
    mocks.preview.mockResolvedValue({ ...stage, status: 'queued' })
    mocks.job.mockResolvedValue({ ...stage, status: 'queued' })
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.getComponent(DateInput).props('modelValue')).toBe('2021-01-01')
    expect(wrapper.get('[data-test="mode-live"]').attributes('disabled')).toBeDefined()

    await uploadFile(wrapper)
    expect((wrapper.get('[data-test="map-doc_number"]').element as HTMLSelectElement).value).toBe('doklad')
    expect((wrapper.get('[data-test="map-warehouse_to"]').element as HTMLSelectElement).value).toBe('sklad_cil')
    await wrapper.get('[data-test="source-key"]').setValue('stary-sklad')
    await wrapper.get('[data-test="fill-negative"]').setValue(true)
    await wrapper.get('[data-test="create-preview"]').trigger('click')
    await flushPromises()
    expect(mocks.preview).toHaveBeenCalledWith(7, expect.objectContaining({
      source_key: 'stary-sklad', cutover_date: '2021-01-01', fill_negative: true,
      mapping: expect.objectContaining({ doc_number: 'doklad', unit_cost: 'cena', partner: '' }),
    }))
    expect(mocks.replace).toHaveBeenCalledWith({ query: { history_job: '41' } })
    wrapper.unmount()
  })

  it('sends no cut-over date in the live mode of a company without stock documents', async () => {
    mocks.status.mockResolvedValue({ locked_until: null, first_document_date: null, active: null, orphan_job_id: null, max_rows: 300000 })
    mocks.preview.mockResolvedValue(stage)
    mocks.job.mockResolvedValue(stage)
    const wrapper = mountPage()
    await flushPromises()
    expect((wrapper.get('[data-test="mode-live"]').element as HTMLInputElement).checked).toBe(true)
    await uploadFile(wrapper)
    await wrapper.get('[data-test="source-key"]').setValue('stary-sklad')
    await wrapper.get('[data-test="create-preview"]').trigger('click')
    await flushPromises()
    expect(mocks.preview).toHaveBeenCalledWith(7, expect.objectContaining({ cutover_date: null }))
    wrapper.unmount()
  })

  it('blocks apply on negative stock without filling and asks to confirm closed periods', async () => {
    mocks.route.query = { history_job: '41' }
    const negative = { ...stage, report: { ...stage.report, negative_count: 1,
      negatives: [{ source_row: 3, doc_number: 'V1', doc_date: '2020-02-01', warehouse: 'HL', sku: 'ZB-1', missing_quantity: '1.000', unit_cost: '10.000000' }] } }
    mocks.job.mockResolvedValue(negative)
    let wrapper = mountPage()
    await flushPromises()
    expect(wrapper.get('[data-test="negatives"]').text()).toContain('ZB-1')
    expect(wrapper.get('[data-test="apply-import"]').attributes('disabled')).toBeDefined()
    wrapper.unmount()

    const closed = { ...stage, report: { ...stage.report, closed_period_documents: 2 } }
    mocks.job.mockResolvedValue(closed)
    mocks.apply.mockResolvedValue({ ...stage, id: 42, kind: 'stock_history_import_apply', status: 'queued', report: {} })
    wrapper = mountPage()
    await flushPromises()
    expect(wrapper.get('[data-test="apply-import"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="confirm-closed"]').setValue(true)
    await wrapper.get('[data-test="apply-import"]').trigger('click')
    await flushPromises()
    expect(mocks.apply).toHaveBeenCalledWith(41, true)
    wrapper.unmount()
  })

  it('offers confirmation when a period closes after the preview', async () => {
    mocks.route.query = { history_job: '41' }
    mocks.job.mockResolvedValue(stage)
    mocks.apply.mockRejectedValueOnce({ response: { data: { error: { code: 'history_import_closed_periods_unconfirmed' } } } })
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-test="confirm-closed"]').exists()).toBe(false)

    await wrapper.get('[data-test="apply-import"]').trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="apply-import"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="confirm-closed"]').setValue(true)
    mocks.apply.mockResolvedValueOnce({ ...stage, id: 42, kind: 'stock_history_import_apply', status: 'queued', report: {} })
    await wrapper.get('[data-test="apply-import"]').trigger('click')
    await flushPromises()
    expect(mocks.apply).toHaveBeenLastCalledWith(41, true)
    wrapper.unmount()
  })

  it('lets only a company administrator roll back the active import', async () => {
    const active = { ...stage, id: 50, kind: 'stock_history_import_apply', report: { documents_created: 2 },
      input: { profile: { cutover_date: '2021-01-01' }, date_from: '2020-01-02', date_to: '2020-02-01' } }
    mocks.status.mockResolvedValue({ locked_until: '2020-12-31', first_document_date: '2021-01-01', active, orphan_job_id: null, max_rows: 300000 })
    mocks.auth.isCompanyAdminRole = false
    let wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-test="create-preview"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="rollback-open"]').exists()).toBe(false)
    wrapper.unmount()

    mocks.auth.isCompanyAdminRole = true
    mocks.rollback.mockResolvedValue({ ...stage, id: 51, kind: 'stock_history_import_rollback', status: 'queued', report: {} })
    mocks.job.mockResolvedValue({ ...stage, id: 51, kind: 'stock_history_import_rollback', status: 'completed', report: { documents_deleted: 2 } })
    wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="rollback-open"]').trigger('click')
    expect(wrapper.text()).toContain('stock.history_import.rollback_text_cutover')
    await wrapper.get('[data-test="rollback-confirm"]').trigger('click')
    await flushPromises()
    expect(mocks.rollback).toHaveBeenCalledWith(50, false)
    expect(wrapper.find('[data-test="rollback-summary"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('asks to confirm deleting documents in a closed period during rollback', async () => {
    const active = { ...stage, id: 50, kind: 'stock_history_import_apply', report: {}, input: { profile: { cutover_date: '2021-01-01' } } }
    mocks.status.mockResolvedValue({ locked_until: '2020-12-31', first_document_date: '2021-01-01', active, orphan_job_id: null, max_rows: 300000 })
    mocks.rollback
      .mockRejectedValueOnce({ response: { data: { error: { code: 'history_import_closed_periods_unconfirmed' } } } })
      .mockResolvedValueOnce({ ...stage, id: 51, kind: 'stock_history_import_rollback', status: 'queued', report: {} })
    mocks.job.mockResolvedValue({ ...stage, id: 51, kind: 'stock_history_import_rollback', status: 'queued', report: {} })
    const wrapper = mountPage()
    await flushPromises()
    await wrapper.get('[data-test="rollback-open"]').trigger('click')
    await wrapper.get('[data-test="rollback-confirm"]').trigger('click')
    await flushPromises()
    expect(mocks.rollback).toHaveBeenLastCalledWith(50, false)
    expect(wrapper.get('[data-test="rollback-confirm"]').attributes('disabled')).toBeDefined()
    await wrapper.get('[data-test="rollback-confirm-closed"]').setValue(true)
    await wrapper.get('[data-test="rollback-confirm"]').trigger('click')
    await flushPromises()
    expect(mocks.rollback).toHaveBeenLastCalledWith(50, true)
    wrapper.unmount()
  })

  it('offers rollback of history without an import job and hides a new import', async () => {
    mocks.status.mockResolvedValue({ locked_until: '2020-12-31', first_document_date: '2021-01-01', active: null, orphan_job_id: 77, max_rows: 300000 })
    mocks.rollback.mockResolvedValue({ ...stage, id: 78, kind: 'stock_history_import_rollback', status: 'queued', report: {} })
    mocks.job.mockResolvedValue({ ...stage, id: 78, kind: 'stock_history_import_rollback', status: 'queued', report: {} })
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-test="orphan-import"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="create-preview"]').exists()).toBe(false)
    await wrapper.get('[data-test="rollback-open"]').trigger('click')
    expect(wrapper.text()).toContain('stock.history_import.rollback_text_orphan')
    await wrapper.get('[data-test="rollback-confirm"]').trigger('click')
    await flushPromises()
    expect(mocks.rollback).toHaveBeenCalledWith(77, false)
    wrapper.unmount()
  })

  it('shows the end state against stock at cut-over and downloads it as XLSX', async () => {
    mocks.route.query = { history_job: '41' }
    mocks.job.mockResolvedValue(stage)
    mocks.endState.mockResolvedValue({ cutover_date: '2021-01-01', totals: { count: 2, differences: 1 }, items: [
      { warehouse_code: 'HL', sku: 'ZB-1', name: 'Karta', unit: 'ks', history_qty: '10.000', stock_qty: '10.000', difference: '0.000' },
      { warehouse_code: 'HL', sku: 'ZB-2', name: 'Karta 2', unit: 'ks', history_qty: '4.000', stock_qty: '5.000', difference: '-1.000' },
    ] })
    const wrapper = mountPage()
    await flushPromises()
    expect(mocks.endState).toHaveBeenCalledWith(41)
    const table = wrapper.get('[data-test="end-state"]')
    expect(table.text()).toContain('ZB-2')
    expect(table.text()).not.toContain('ZB-1')
    await wrapper.get('[data-test="end-state-download"]').trigger('click')
    expect(mocks.downloadEndState).toHaveBeenCalledWith(41)
    wrapper.unmount()
  })

  it('explains recovery instead of retrying an import blocked by a new period closure', async () => {
    const stopped = { ...stage, id: 42, kind: 'stock_history_import_apply', status: 'failed',
      error_code: 'history_import_closed_periods_unconfirmed', report: {} }
    mocks.route.query = { history_job: '42' }
    mocks.status.mockResolvedValue({ locked_until: '9999-12-31', first_document_date: null,
      active: stopped, orphan_job_id: null, max_rows: 300000 })
    mocks.job.mockResolvedValue(stopped)
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-test="retry"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="retry-unsupported"]').text()).toBe('stock.history_import.apply_period_closed')
    expect(wrapper.find('[data-test="rollback-open"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('offers retry of the file check and of the current stopped import, not of an older one', async () => {
    const stopped = { ...stage, id: 42, kind: 'stock_history_import_apply', status: 'cancelled', report: {} }
    mocks.route.query = { history_job: '42' }
    mocks.status.mockResolvedValue({ locked_until: '9999-12-31', first_document_date: null, active: stopped, orphan_job_id: null, max_rows: 300000 })
    mocks.job.mockResolvedValue(stopped)
    let wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-test="retry"]').exists()).toBe(true)
    expect(wrapper.get('[data-test="retry-unsupported"]').text()).toBe('stock.history_import.apply_stopped')
    wrapper.unmount()

    mocks.status.mockResolvedValue({ locked_until: null, first_document_date: null, active: null, orphan_job_id: null, max_rows: 300000 })
    wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-test="retry"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="retry-unsupported"]').text()).toBe('stock.history_import.apply_stale')
    wrapper.unmount()

    mocks.route.query = { history_job: '41' }
    mocks.job.mockResolvedValue({ ...stage, status: 'failed' })
    wrapper = mountPage()
    await flushPromises()
    expect(wrapper.find('[data-test="retry"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('lets only a company administrator stop a running import', async () => {
    const running = { ...stage, id: 42, kind: 'stock_history_import_apply', status: 'running', report: {} }
    mocks.route.query = { history_job: '42' }
    mocks.job.mockResolvedValue(running)
    mocks.auth.isCompanyAdminRole = false
    let wrapper = mountPage()
    await flushPromises()
    expect(wrapper.getComponent({ name: 'CatalogJobProgress' }).props('canCancel')).toBe(false)
    wrapper.unmount()
    mocks.auth.isCompanyAdminRole = true
    wrapper = mountPage()
    await flushPromises()
    expect(wrapper.getComponent({ name: 'CatalogJobProgress' }).props('canCancel')).toBe(true)
    wrapper.unmount()
  })

  it('lists documents that will not link to an invoice and reports links dropped on apply', async () => {
    mocks.route.query = { history_job: '41' }
    mocks.job.mockResolvedValue({ ...stage, report: { ...stage.report, invoice_unlinked_count: 3, invoice_unlinked: [
      { source_row: 3, doc_number: 'V1', invoice_number: '2025001', reason: 'own_stock' },
      { source_row: 4, doc_number: 'V2', invoice_number: '2025002', reason: 'draft' },
    ] } })
    let wrapper = mountPage()
    await flushPromises()
    const section = wrapper.get('[data-test="invoice-unlinked"]')
    expect(section.text()).toContain('stock.history_import.invoice_unlinked_title')
    expect(section.findAll('tbody tr').map(row => row.text())).toEqual([
      '3V12025001stock.history_import.invoice_unlinked_reason.own_stock',
      '4V22025002stock.history_import.invoice_unlinked_reason.draft',
    ])
    expect(section.text()).toContain('stock.history_import.invoice_unlinked_more')
    wrapper.unmount()

    mocks.route.query = { history_job: '42' }
    mocks.job.mockResolvedValue({ ...stage, id: 42, kind: 'stock_history_import_apply', report: { documents_created: 5, invoice_unlinked_on_apply: 1, invoice_unlinked_on_apply_list: [
      { source_row: 6, doc_number: 'V4', invoice_number: '2025004', reason: 'own_stock' },
    ] } })
    wrapper = mountPage()
    await flushPromises()
    const applied = wrapper.get('[data-test="invoice-unlinked-on-apply"]')
    expect(applied.text()).toContain('stock.history_import.invoice_unlinked_on_apply')
    expect(applied.findAll('tbody tr').map(row => row.text())).toEqual(['6V42025004stock.history_import.invoice_unlinked_reason.own_stock'])
    expect(applied.text()).not.toContain('stock.history_import.invoice_unlinked_more')
    wrapper.unmount()
  })

  it('describes a running lock in a whole sentence and keeps dates of the period unbroken', async () => {
    const active = { ...stage, id: 42, kind: 'stock_history_import_apply', status: 'running', report: {}, input: { profile: { cutover_date: null }, date_from: '2020-01-02', date_to: '2020-02-01' } }
    mocks.status.mockResolvedValue({ locked_until: '9999-12-31', first_document_date: null, active, orphan_job_id: null, max_rows: 300000 })
    let wrapper = mountPage()
    await flushPromises()
    expect(wrapper.get('[data-test="active-import"]').text()).toContain('stock.history_import.active_text_running')
    wrapper.unmount()

    mocks.status.mockResolvedValue({ locked_until: '9999-12-31', first_document_date: null,
      active: { ...active, status: 'cancelled' }, orphan_job_id: null, max_rows: 300000 })
    wrapper = mountPage()
    await flushPromises()
    expect(wrapper.get('[data-test="active-import"]').text()).toContain('stock.history_import.active_text_stopped')
    wrapper.unmount()

    mocks.status.mockResolvedValue({ locked_until: '9999-12-31', first_document_date: null, active: null, orphan_job_id: 0, max_rows: 300000 })
    wrapper = mountPage()
    await flushPromises()
    expect(wrapper.get('[data-test="orphan-import"]').text()).toContain('stock.history_import.orphan_text_running')
    wrapper.unmount()

    mocks.route.query = { history_job: '41' }
    mocks.status.mockResolvedValue({ locked_until: null, first_document_date: '2021-01-01', active: null, orphan_job_id: null, max_rows: 300000 })
    mocks.job.mockResolvedValue(stage)
    wrapper = mountPage()
    await flushPromises()
    expect(wrapper.get('[data-test="summary"]').findAll('span.whitespace-nowrap').map(span => span.text())).toEqual(['2020-01-02', '2020-02-01'])
    wrapper.unmount()
  })

  it('shows the file content of a row without recognised values and labels taken-over rows', async () => {
    mocks.route.query = { history_job: '41' }
    mocks.job.mockResolvedValue({ ...stage, report: { ...stage.report, counts: { applied: 1, failed: 1 } } })
    mocks.items.mockResolvedValue({ items: [
      { ordinal: 1, source_row: 2, status: 'applied', error_code: null, input: { values: { doc_number: 'P1', sku: 'ZB-1' } }, before: null, after: null },
      { ordinal: 2, source_row: 3, status: 'failed', error_code: 'history_import_doc_date_future', input: { values: [], raw: ['P2', 'prijemka', '2099-01-01', ''] }, before: null, after: null },
    ], pagination: { page: 1, limit: 50, total: 2, pages: 1 } })
    const wrapper = mountPage()
    await flushPromises()
    expect(wrapper.text()).toContain('P2; prijemka; 2099-01-01')
    expect(wrapper.text()).toContain('stock.history_import.status_applied')
    expect(wrapper.text()).not.toContain('stock.opening_import.status.applied')
    wrapper.unmount()
  })
})
