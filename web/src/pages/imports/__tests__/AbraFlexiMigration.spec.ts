import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import AbraFlexiMigration from '../AbraFlexiMigration.vue'

const m = vi.hoisted(() => ({ connection: vi.fn(), save: vi.fn(), discover: vi.fn(), start: vi.fn(), sync: vi.fn(), remove: vi.fn(), runs: vi.fn(), run: vi.fn(), fetchJob: vi.fn(), cancelJob: vi.fn(), activationRefresh: vi.fn(), supplier: undefined as any }))
vi.mock('@/api/abraFlexi', () => ({ abraFlexiApi: { connection: m.connection, save: m.save, discover: m.discover, start: m.start, sync: m.sync, remove: m.remove, runs: m.runs, run: m.run } }))
vi.mock('@/api/imports', () => ({ fetchImportJob: m.fetchJob, cancelImportJob: m.cancelJob }))
vi.mock('@/composables/useActivationStatus', () => ({ useActivationStatus: () => ({ refresh: m.activationRefresh }) }))
vi.mock('@/stores/supplier', async () => {
  const { reactive } = await import('vue')
  m.supplier = reactive({ currentSupplierId: 1 })
  return { useSupplierStore: () => m.supplier }
})
vi.mock('vue-i18n', () => ({ useI18n: () => ({ t: (key: string) => key, te: () => true, locale: { value: 'cs' } }) }))

const state = () => ({ configured: true, imported: false, years: [2024, 2025, 2026].map(year => ({ year, starts_on: `${year}-01-01`, ends_on: `${year}-12-31` })), default_years: [2025, 2026], selected_years: [], last_synced_at: null, active_job_id: null, warnings: [], source_company: null })
const job = (status = 'running') => ({ id: 8, status, total_items: 2, processed: 1, created_count: 1, skipped_count: 0, failed_count: 0, current_step: null, log_text: null, last_error: null })
const wrappers: ReturnType<typeof mount>[] = []

async function page() {
  const wrapper = mount(AbraFlexiMigration, { global: { stubs: { ActionBar: { props: ['actions'], template: '<div><button v-for="action in actions.filter(a => a.show !== false)" :key="action.key" :data-action="action.key" :disabled="action.disabled" @click="action.run">{{ action.label }}</button></div>' } } } })
  wrappers.push(wrapper)
  await flushPromises()
  return wrapper
}

beforeEach(() => {
  vi.resetAllMocks()
  m.supplier.currentSupplierId = 1
  m.connection.mockResolvedValue(state())
  m.runs.mockResolvedValue([])
  m.start.mockResolvedValue({ job_id: 8 })
  m.sync.mockResolvedValue({ job_id: 8 })
  m.fetchJob.mockResolvedValue(job())
  m.activationRefresh.mockResolvedValue(null)
})
afterEach(() => { wrappers.splice(0).forEach(wrapper => wrapper.unmount()); vi.useRealTimers() })

describe('ABRA Flexi transfer', () => {
  it('loads the failed job protocol, shows blockers and reconciliation without source values', async () => {
    m.connection.mockResolvedValueOnce({ ...state(), active_job_id: 8 }).mockResolvedValue(state())
    m.fetchJob.mockResolvedValue(job('failed'))
    m.runs.mockResolvedValue([
      { id: 2, job_id: 9, status: 'completed' },
      { id: 1, job_id: 8, status: 'failed' },
    ])
    m.run.mockResolvedValue({ id: 1, job_id: 8, status: 'failed', protocol: { warnings: ['source_record_changed:https://private.invalid/secret', 'source_trial_balance_missing'], blocked: true, reconciliation: { ok: false, years: [{ year: 2026, ok: false }] }, report: { conflicts: [{ source_key: 'PRIVATE-SOURCE-ID' }] } } })
    const wrapper = await page()
    expect(m.runs).toHaveBeenCalledOnce()
    expect(m.run).toHaveBeenCalledWith(1)
    expect(wrapper.get('[data-testid="abra-protocol"]').text()).toContain('abra_flexi.blocked')
    expect(wrapper.text()).toContain('abra_flexi.issues.source_record_changed')
    expect(wrapper.get('[data-testid="abra-reconciliation"]').text()).toContain('abra_flexi.reconciliation_failed')
    expect(wrapper.text()).not.toContain('private.invalid')
    expect(wrapper.text()).not.toContain('PRIVATE-SOURCE-ID')
  })

  it('uses backend defaults, allows changing the years and polls the resulting job', async () => {
    const wrapper = await page()
    const years = wrapper.findAll('[data-testid="abra-years"] input[type="checkbox"]')
    expect(years.map(input => (input.element as HTMLInputElement).checked)).toEqual([false, true, true])
    await years[0]!.setValue(true)
    await years[2]!.setValue(false)
    await wrapper.get('[data-action="start"]').trigger('click')
    await flushPromises()
    expect(m.start).toHaveBeenCalledWith([2025, 2024])
    expect(m.fetchJob).toHaveBeenCalledWith(8)
  })

  it('hides years after importing and requests incremental synchronization', async () => {
    m.connection.mockResolvedValue({ ...state(), imported: true, selected_years: [2025] })
    const wrapper = await page()
    expect(m.activationRefresh).toHaveBeenCalledWith(true)
    expect(wrapper.find('[data-testid="abra-years"]').exists()).toBe(false)
    expect(wrapper.get('[data-action="start"]').text()).toBe('abra_flexi.sync')
    await wrapper.get('[data-action="start"]').trigger('click')
    await flushPromises()
    expect(m.sync).toHaveBeenCalledOnce()
    expect(m.start).not.toHaveBeenCalled()
  })

  it('warns about a different source company and does not allow starting the transfer', async () => {
    m.connection.mockResolvedValue({ ...state(), source_company: { name: 'Source s.r.o.', ico: '10000009' }, target_company: { name: 'Target s.r.o.', ico: '20000002' }, ico_mismatch: true })
    const wrapper = await page()
    expect(wrapper.get('[data-testid="abra-ico-mismatch"]').text()).toContain('abra_flexi.ico_mismatch_title')
    const start = wrapper.get('[data-action="start"]')
    expect(start.attributes('disabled')).toBeDefined()
    await start.trigger('click')
    expect(m.start).not.toHaveBeenCalled()
  })

  it('clears plaintext immediately on submission even when saving fails', async () => {
    m.connection.mockResolvedValue({ ...state(), configured: false })
    let reject!: (reason: unknown) => void
    m.save.mockReturnValue(new Promise((_resolve, fail) => { reject = fail }))
    const wrapper = await page()
    await wrapper.get('[data-testid="abra-url"]').setValue('https://synthetic.invalid/c/demo')
    await wrapper.get('[data-testid="abra-username"]').setValue('synthetic-reader')
    await wrapper.get('[data-testid="abra-password"]').setValue('synthetic-password')
    await wrapper.get('form').trigger('submit')
    for (const input of wrapper.findAll('form input')) expect((input.element as HTMLInputElement).value).toBe('')
    reject(new Error('Unsafe transport error containing credentials'))
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toBe('abra_flexi.failed')
  })

  it('ignores an old tenant response and clears credentials when switching companies', async () => {
    m.connection.mockResolvedValueOnce({ ...state(), configured: false })
    let resolve!: (value: unknown) => void
    m.save.mockReturnValue(new Promise(done => { resolve = done }))
    const wrapper = await page()
    await wrapper.get('[data-testid="abra-url"]').setValue('https://synthetic.invalid/c/demo')
    await wrapper.get('[data-testid="abra-username"]').setValue('synthetic-reader')
    await wrapper.get('[data-testid="abra-password"]').setValue('synthetic-password')
    await wrapper.get('form').trigger('submit')
    m.connection.mockResolvedValue({ ...state(), configured: false })
    m.supplier.currentSupplierId = 2
    await flushPromises()
    resolve({ ...state(), imported: true, source_company: { name: 'Old tenant', ico: '12345678' } })
    await flushPromises()
    expect(wrapper.text()).not.toContain('Old tenant')
    expect(wrapper.find('[data-testid="abra-imported"]').exists()).toBe(false)
    expect((wrapper.get('[data-testid="abra-password"]').element as HTMLInputElement).value).toBe('')
  })

  it('cancels a restored active job and stops polling after unmounting', async () => {
    vi.useFakeTimers()
    m.connection.mockResolvedValue({ ...state(), active_job_id: 8 })
    const wrapper = await page()
    const cancel = wrapper.findAll('button').find(button => button.text() === 'imports.job_cancel')!
    await cancel.trigger('click')
    await flushPromises()
    expect(m.cancelJob).toHaveBeenCalledWith(8)
    wrapper.unmount()
    await vi.advanceTimersByTimeAsync(5000)
    expect(m.fetchJob).toHaveBeenCalledTimes(1)
  })
})
