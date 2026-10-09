import { beforeEach, afterEach, describe, expect, it, vi } from 'vitest'
import { mount, flushPromises } from '@vue/test-utils'
import MyuctoMigration from '../MyuctoMigration.vue'

const m = vi.hoisted(() => ({ upload: vi.fn(), run: vi.fn(), runs: vi.fn(), status: vi.fn(), supplier: undefined as any, canWrite: true }))
vi.mock('@/api/myuctoImport', () => ({ MYUCTO_IMPORT_MAX_BYTES: 2 * 1024 * 1024 * 1024, myuctoImportApi: { upload: m.upload, run: m.run, runs: m.runs, status: m.status } }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => m.canWrite }) }))
vi.mock('@/stores/supplier', async () => {
  const { reactive } = await import('vue')
  m.supplier = reactive({ currentSupplierId: 4, currentSupplier: { company_name: 'Synthetic target' } })
  return { useSupplierStore: () => m.supplier }
})
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, te: () => true }) }))

const result = (dryRun = true) => ({ token: 'synthetic-token', company_name: 'Synthetic source', ic: '00000000', report: {
  dry_run: dryRun, supplier_id: 4, source_supplier_id: 1, created: { invoices: 2 }, reused: {}, existing: {}, outside_scope: { documents: 1 }, files: 0, reconciliation: { invoices: 2 },
} })
const wrappers: ReturnType<typeof mount>[] = []
function page() {
  const wrapper = mount(MyuctoMigration, { global: { stubs: { CompanyProfileBox: true, RouterLink: { template: '<a><slot /></a>' } } } })
  wrappers.push(wrapper)
  return wrapper
}
async function chooseFile(wrapper: ReturnType<typeof mount>) {
  const input = wrapper.get('input[type="file"]')
  Object.defineProperty(input.element, 'files', { value: [new File(['synthetic'], 'synthetic.zip', { type: 'application/zip' })], configurable: true })
  await input.trigger('change')
}
async function preview(wrapper: ReturnType<typeof mount>) {
  await chooseFile(wrapper)
  await wrapper.get('button').trigger('click')
  await flushPromises()
}
beforeEach(() => {
  vi.resetAllMocks(); m.canWrite = true; m.supplier.currentSupplierId = 4
  m.upload.mockResolvedValue({ token: 'synthetic-token', job_id: null })
  m.run.mockResolvedValue({ job_id: 12, status: 'queued' })
  m.runs.mockResolvedValue([])
  m.status.mockResolvedValue({ id: 12, status: 'completed', mode: 'dry_run', result: result() })
})
afterEach(() => wrappers.splice(0).forEach(wrapper => wrapper.unmount()))

describe('MyÚčto import', () => {
  it('requires preview and confirmation before applying into the current company', async () => {
    const wrapper = page()
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
    await preview(wrapper)
    expect(m.run).toHaveBeenCalledWith('synthetic-token', 'puvodni-instance', '', false)
    expect(wrapper.text()).toContain('myucto_import.outside_scope')
    const apply = wrapper.findAll('button').find(button => button.text() === 'myucto_import.apply')!
    expect(apply.attributes('disabled')).toBeDefined()
    await wrapper.get('input[type="checkbox"]').setValue(true)
    m.status.mockResolvedValueOnce({ id: 13, status: 'completed', mode: 'import', result: result(false) })
    await apply.trigger('click'); await flushPromises()
    expect(m.run).toHaveBeenLastCalledWith('synthetic-token', 'puvodni-instance', '', true)
    expect(wrapper.text()).toContain('myucto_import.completed')
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
  })
  it('invalidates preview when changing the source', async () => {
    const wrapper = page(); await preview(wrapper)
    await wrapper.get('#myucto-source').setValue('different-instance')
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
    expect(wrapper.text()).not.toContain('myucto_import.checked')
  })
  it('ignores a late result and clears credentials when switching company', async () => {
    let resolve!: (value: unknown) => void
    m.run.mockReturnValue(new Promise(done => { resolve = done }))
    const wrapper = page(); await chooseFile(wrapper)
    await wrapper.get('#myucto-password').setValue('synthetic-password')
    await wrapper.get('button').trigger('click'); await flushPromises()
    m.supplier.currentSupplierId = 5; await flushPromises()
    resolve({ job_id: 12, status: 'queued' }); await flushPromises()
    expect(wrapper.text()).not.toContain('myucto_import.checked')
    expect((wrapper.get('#myucto-password').element as HTMLInputElement).value).toBe('')
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
  })
  it('cannot start without write permissions', async () => {
    m.canWrite = false
    const wrapper = page(); await chooseFile(wrapper)
    expect(wrapper.get('button').attributes('disabled')).toBeDefined()
    expect(m.upload).not.toHaveBeenCalled()
  })
  it('resumes an active background run from history and stops polling on company switch', async () => {
    vi.useFakeTimers()
    const queued = { id: 12, token: 'synthetic-token', source_name: 'synthetic', status: 'queued', mode: 'dry_run', result: null }
    m.runs.mockResolvedValue([queued])
    m.status.mockResolvedValue({ ...queued, status: 'running', current_step: 'Synthetic check' })
    const wrapper = page()
    await flushPromises()
    expect(m.status).toHaveBeenCalledWith(12)
    expect(wrapper.text()).toContain('Synthetic check')
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
    m.supplier.currentSupplierId = 5
    m.runs.mockResolvedValue([])
    await flushPromises()
    m.status.mockClear()
    await vi.advanceTimersByTimeAsync(2000)
    expect(m.status).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('Synthetic check')
    vi.useRealTimers()
  })
  it('shows a failed background run without authorizing apply', async () => {
    m.status.mockResolvedValue({ id: 12, status: 'failed', mode: 'dry_run', last_error: 'Synthetic archive rejected', result: null })
    const wrapper = page()
    await preview(wrapper)
    expect(wrapper.text()).toContain('Synthetic archive rejected')
    expect(wrapper.find('input[type="checkbox"]').exists()).toBe(false)
  })

})
