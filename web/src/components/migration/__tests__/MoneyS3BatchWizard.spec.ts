import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  list: vi.fn(),
  jobs: vi.fn(),
  job: vi.fn(),
  start: vi.fn(),
  fetchImportJob: vi.fn(),
}))

vi.mock('vue-i18n', () => ({
  useI18n: () => ({ t: (key: string, params?: Record<string, unknown>) => (params ? `${key}:${JSON.stringify(params)}` : key), locale: { value: 'cs' } }),
}))
vi.mock('@/api/moneyS3Batch', async () => {
  const actual = await vi.importActual<typeof import('@/api/moneyS3Batch')>('@/api/moneyS3Batch')
  return { ...actual, moneyS3BatchApi: { list: m.list, jobs: m.jobs, job: m.job, start: m.start, uploadChunked: vi.fn(), deleteUpload: vi.fn(), uploadFiling: vi.fn(), deleteFiling: vi.fn() } }
})
vi.mock('@/api/imports', () => ({ fetchImportJob: m.fetchImportJob, cancelImportJob: vi.fn() }))
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ success: vi.fn(), error: vi.fn(), info: vi.fn() }) }))
vi.mock('@/stores/auth', () => ({ useAuthStore: () => ({ canWrite: () => true }) }))

import MoneyS3BatchWizard from '@/components/migration/MoneyS3BatchWizard.vue'

const agenda = (ico: string, name: string) => ({
  name, ico, dic: 'CZ' + ico, street: '', city: '', zip: '', version: '26.600', version_verified: true, backup_at: '10.01.2026 08:15',
  years: [{ dir: 'ROK.001', fiscal_year: 2024 }, { dir: 'ROK.002', fiscal_year: 2025 }], partners: 3, warnings: [],
})

const global = { stubs: { RouterLink: { template: '<a><slot /></a>' }, MoneyS3Protocol: { template: '<div class="protocol" />' } } }

describe('MoneyS3BatchWizard', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.jobs.mockResolvedValue({ items: [] })
    m.list.mockResolvedValue({
      items: [
        { token: 'a'.repeat(16), status: 'ready', file_name: 'alfa.lz', uploaded_at: '2026-09-23', agenda: agenda('24681351', 'Alfa s.r.o.'), suggested_from_year: 2025, company: { access: 'none', supplier_id: null, name: null } },
        { token: 'b'.repeat(16), status: 'ready', file_name: 'beta.lz', uploaded_at: '2026-09-23', agenda: agenda('13579240', 'Beta s.r.o.'), suggested_from_year: null, company: { access: 'forbidden', supplier_id: null, name: null } },
      ],
      filings: [],
      can_create_companies: true,
      current_group_id: null,
    })
  })

  it('lists uploaded backups with target company and selects only reachable ones', async () => {
    const w = mount(MoneyS3BatchWizard, { global })
    await flushPromises()

    const rows = w.findAll('tbody tr')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Alfa s.r.o.')
    expect(rows[0].text()).toContain('2025')
    expect(rows[0].text()).toContain('money_s3.batch.company_new')
    expect(rows[1].text()).toContain('money_s3.batch.company_forbidden')
    expect((w.get(`[data-testid="batch-select-${'a'.repeat(16)}"]`).element as HTMLInputElement).checked).toBe(true)
    const forbidden = w.get(`[data-testid="batch-select-${'b'.repeat(16)}"]`).element as HTMLInputElement
    expect(forbidden.checked).toBe(false)
    expect(forbidden.disabled).toBe(true)
    expect(w.text()).toContain('money_s3.batch.will_create:{"n":1}')
  })

  it('starts a dry run of the selected backups and shows the batch protocol with K1-K4', async () => {
    m.start.mockResolvedValue({ job_id: 5, status: 'queued', mode: 'dry_run', companies: 1, superseded: [] })
    m.fetchImportJob.mockResolvedValue({ id: 5, status: 'completed_with_warnings', total_items: 17, processed: 17, created_count: 1, skipped_count: 0, failed_count: 0, current_step: 'Hotovo' })
    m.job.mockResolvedValue({
      id: 5, status: 'completed_with_warnings', created_at: '2026-09-23', finished_at: '2026-09-23', current_step: 'Hotovo', total_items: 17, processed: 17, last_error: null,
      options: { mode: 'dry_run' },
      items: [{
        id: 1, position: 1, file_name: 'alfa.lz', agenda_ico: '24681351', agenda_name: 'Alfa s.r.o.', target_supplier_id: null, target_name: null,
        status: 'completed_with_warnings', company_action: 'created', from_year: 2025, run_id: null, error: null,
        summary: { criteria: { years: {}, total: { K1: true, K2: true, K3: false, K4: null } }, notices: ['Ověřte plátcovství.'], filings_available: [2024] },
      }],
    })
    const w = mount(MoneyS3BatchWizard, { global })
    await flushPromises()

    const dry = w.findAll('button').find(b => b.text().includes('money_s3.batch.dry_run_start'))!
    await dry.trigger('click')
    await flushPromises()

    expect(m.start).toHaveBeenCalledWith(expect.objectContaining({ tokens: ['a'.repeat(16)], mode: 'dry_run', from_year: 'auto', existing: 'skip', to_year: 'auto', opening_only_next: false }))
    const protocol = w.get('[data-testid="batch-protocol"]')
    expect(protocol.text()).toContain('Alfa s.r.o.')
    expect(protocol.text()).toContain('money_s3.batch.action.created')
    expect(protocol.text()).toContain('Ověřte plátcovství.')
    expect(protocol.text()).toContain('money_s3.batch.filings_pending:{"years":"2024"}')
    const badges = protocol.findAll('span').filter(s => /^K[1-4]$/.test(s.text()))
    expect(badges.map(b => b.classes().some(c => c.includes('success')))).toEqual([true, true, false, false])
  })

  it('keeps the live conversion disabled until it is confirmed', async () => {
    const w = mount(MoneyS3BatchWizard, { global })
    await flushPromises()
    const live = () => w.findAll('button').find(b => b.text().includes('money_s3.batch.import_start'))!
    expect(live().attributes('disabled')).toBeDefined()
    await w.get('[data-testid="batch-confirm"]').setValue(true)
    expect(live().attributes('disabled')).toBeUndefined()
  })

  it('sends the last converted year and opening balances of the next year', async () => {
    const w = mount(MoneyS3BatchWizard, { global })
    await flushPromises()
    const options = w.get('[data-testid="batch-to-year"]').findAll('option').map(o => o.attributes('value'))
    expect(options).toEqual(['auto', '2024', '2025'])
    await w.get('[data-testid="batch-to-year"]').setValue('2024')
    await w.get('[data-testid="batch-opening-only-next"]').setValue(true)
    const dry = w.findAll('button').find(b => b.text().includes('money_s3.batch.dry_run_start'))!
    await dry.trigger('click')
    await flushPromises()
    expect(m.start).toHaveBeenCalledWith(expect.objectContaining({ to_year: 2024, opening_only_next: true }))
  })
})
