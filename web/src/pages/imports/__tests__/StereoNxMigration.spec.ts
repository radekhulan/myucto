import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'
import StereoNxMigration from '../StereoNxMigration.vue'

const m = vi.hoisted(() => ({
  uploads: vi.fn(), upload: vi.fn(), preview: vi.fn(), run: vi.fn(), remove: vi.fn(), fillCompanyProfile: vi.fn(),
  toastError: vi.fn(), supplierStore: undefined as any,
}))

vi.mock('@/api/stereoNx', () => ({ stereoNxApi: {
  uploads: m.uploads, upload: m.upload, preview: m.preview, run: m.run, remove: m.remove, fillCompanyProfile: m.fillCompanyProfile,
} }))
vi.mock('@/stores/supplier', async () => {
  const { reactive } = await import('vue')
  m.supplierStore = reactive({ currentSupplierId: 1 })
  return { useSupplierStore: () => m.supplierStore }
})
vi.mock('@/composables/useToast', () => ({ useToast: () => ({ error: m.toastError }) }))
vi.mock('@/components/settings/CompanyProfileBox.vue', () => ({ default: { template: '<div />' } }))
vi.mock('vue-i18n', () => ({ useI18n: () => ({
  t: (key: string) => key, te: () => true, tm: () => ['Synthetic backup instruction'],
  rt: (value: string) => value, locale: { value: 'cs' },
}) }))

const upload = { token: 'synthetic-token', filename: 'synthetic.zip', size: 1024,
  received: 1024, complete: true, created_at: 1700000000 }
const company = { index: 0, label: 'Syntetická firma',
  identity: { ico: '12345678', dic: 'CZ12345678', name: 'Syntetická firma', vat_payer: true, accounting_mode: 'tax_evidence' },
  matches_target: true }
const preview = { companies: [company], target: { ico: '12345678', accounting_mode: 'tax_evidence', vat_payer: true } }
const dryReport = { ok: true, counts: { issued: 1, purchases: 1, requires_draft: 1 },
  review_reasons: { vat_participation_unassigned: 1 }, errors: [], warnings: [],
  written: { issued: 1, purchases: 1 } }
const importReport = { ...dryReport, database_writes: true, written: { issued: 1, purchases: 1 } }

function primaryButton(wrapper: Awaited<ReturnType<typeof mountPage>>, key: string) {
  const button = wrapper.get('[data-testid="stereo-actions"]').findAll('button').find(item => item.text().includes(`stereo_nx.${key}`))
  expect(button, `missing button ${key}`).toBeDefined()
  return button!
}

async function mountPage() {
  const wrapper = mount(StereoNxMigration, { global: { stubs: { RouterLink: { props: ['to'], template: '<a :href="to"><slot /></a>' } } } })
  await flushPromises()
  return wrapper
}

async function openExisting(wrapper: Awaited<ReturnType<typeof mountPage>>) {
  const button = wrapper.findAll('button').find(item => item.text().includes('stereo_nx.open_upload'))
  expect(button).toBeDefined()
  await button!.trigger('click')
  await flushPromises()
}

async function reachDryRun(wrapper: Awaited<ReturnType<typeof mountPage>>) {
  await openExisting(wrapper)
  expect(wrapper.get('[data-testid="stereo-company-select"]').element).toBeTruthy()
  await primaryButton(wrapper, 'continue').trigger('click')
  await flushPromises()
  await primaryButton(wrapper, 'dry_run').trigger('click')
  await flushPromises()
}

beforeEach(() => {
  vi.resetAllMocks()
  m.supplierStore.currentSupplierId = 1
  m.uploads.mockResolvedValue([upload])
  m.upload.mockResolvedValue({ token: upload.token })
  m.preview.mockResolvedValue(preview)
  m.run.mockImplementation(async (_token: string, _company: number, mode: string) => mode === 'dry_run' ? dryReport : importReport)
  m.remove.mockResolvedValue(undefined)
  m.fillCompanyProfile.mockResolvedValue({ filled_fields: [] })
})

afterEach(() => vi.clearAllMocks())

describe('Stereo NX migration wizard', () => {
  it('links historical wages only after a committed transfer', async () => {
    const written = { historical_payroll_created: 2 }
    m.run.mockImplementation(async (_token: string, _company: number, mode: string) => mode === 'dry_run'
      ? { ...dryReport, written } : { ...importReport, written })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    expect(wrapper.find('[data-testid="stereo-payroll-link"]').exists()).toBe(false)
    await primaryButton(wrapper, 'continue').trigger('click')
    await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    expect(wrapper.get('[data-testid="stereo-payroll-link"]').attributes('href')).toBe('/payroll/imports?tab=takeover')
    wrapper.unmount()
  })

  it('links committed review records and never links rolled-back dry-run IDs', async () => {
    const reviews = {
      review_documents: [{ kind: 'purchase', source_key: 'synthetic', document_no: 'TEST', review_codes: ['vat_participation_unassigned'], target_id: 123 }],
      review_movements: [{ kind: 'bank', source_key: 'bank', review_codes: ['pending_reconciliation'], target_id: 456, statement_id: 789 },
        { kind: 'cash', source_key: 'cash', review_codes: ['pending_reconciliation'], target_id: 321 }],
    }
    m.run.mockImplementation(async (_token: string, _company: number, mode: string) => mode === 'dry_run'
      ? { ...dryReport, ...reviews } : { ...importReport, ...reviews })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    expect(wrapper.find('[data-testid="stereo-review-links"]').exists()).toBe(false)
    await primaryButton(wrapper, 'continue').trigger('click')
    await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    const hrefs = wrapper.get('[data-testid="stereo-review-links"]').findAll('a').map(a => a.attributes('href'))
    expect(hrefs).toEqual(['/purchase-invoices/123', '/bank/789?tx=456', '/accounting/cash/321/edit'])
    wrapper.unmount()
  })

  it('allows a double-entry company to run the same dry-run workflow', async () => {
    m.preview.mockResolvedValue({ ...preview, companies: [{ ...company, identity: { ...company.identity, accounting_mode: 'double_entry' } }], target: { ...preview.target, accounting_mode: 'double_entry' } })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    expect(m.run).toHaveBeenCalledWith(upload.token, 0, 'dry_run', false, expect.any(Function))
    wrapper.unmount()
  })

  it.each([
    ['tax_evidence', 'double_entry'],
    ['double_entry', 'tax_evidence'],
  ])('blocks mismatched source %s and target %s and links to accounting settings', async (source, target) => {
    m.preview.mockResolvedValue({ ...preview, companies: [{ ...company, identity: { ...company.identity, accounting_mode: source } }], target: { ...preview.target, accounting_mode: target } })
    const wrapper = await mountPage()
    await openExisting(wrapper)
    expect(wrapper.get('[data-testid="stereo-mode-mismatch"]').text()).toContain('stereo_nx.mode_mismatch')
    expect(wrapper.get('[data-testid="stereo-mode-mismatch"]').text()).toContain('stereo_nx.open_accounting_settings')
    expect(primaryButton(wrapper, 'continue').attributes('disabled')).toBeDefined()
    expect(m.run).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('explains an unknown source mode while allowing validation to continue', async () => {
    m.preview.mockResolvedValue({ ...preview, companies: [{ ...company, identity: { ...company.identity, accounting_mode: null } }] })
    const wrapper = await mountPage()
    await openExisting(wrapper)
    expect(wrapper.text()).toContain('stereo_nx.source_mode_unknown')
    expect(primaryButton(wrapper, 'continue').attributes('disabled')).toBeUndefined()
    wrapper.unmount()
  })

  it('selects empty fields by default and allows an individual overwrite with expected values', async () => {
    const proposal = { ...company, profile_suggestions: { city: 'Vzorov', street: 'Testovací 1' }, profile_current: { city: '', street: 'Původní 2' } }
    m.preview.mockResolvedValueOnce({ ...preview, companies: [proposal] }).mockResolvedValueOnce(preview)
    m.fillCompanyProfile.mockResolvedValue({ filled_fields: ['city', 'street'] })
    const wrapper = await mountPage()
    await openExisting(wrapper)
    expect(wrapper.get('[data-testid="stereo-profile-suggestions"]').text()).toContain('Vzorov')
    expect(wrapper.get('[data-testid="stereo-profile-suggestions"]').text()).toContain('Testovací 1')
    const checkboxes = wrapper.get('[data-testid="stereo-profile-suggestions"]').findAll('input[type="checkbox"]')
    expect((checkboxes[0].element as HTMLInputElement).checked).toBe(false)
    expect((checkboxes[1].element as HTMLInputElement).checked).toBe(true)
    await checkboxes[0].setValue(true)
    await wrapper.findAll('button').find(button => button.text().includes('stereo_nx.fill_company_profile'))!.trigger('click')
    await flushPromises()
    expect(m.fillCompanyProfile).toHaveBeenCalledWith(upload.token, 0, ['street', 'city'], { street: 'Původní 2', city: '' })
    expect(m.preview).toHaveBeenCalledTimes(2)
    expect(wrapper.find('[data-testid="stereo-profile-suggestions"]').exists()).toBe(false)
    expect(wrapper.get('[data-testid="stereo-profile-filled"]').text()).toContain('stereo_nx.profile_filled')
    wrapper.unmount()
  })

  it('keeps document review reasons in the review step without a generic duplicate in summary', async () => {
    m.run.mockResolvedValue({ ...dryReport,
      warnings: [{ level: 'warning', code: 'issued_lines_aggregated', document_no: 'TEST', message: 'Specific draft warning' }],
      review_documents: [{ kind: 'issued', source_key: 'synthetic-issued', document_no: 'TEST', review_codes: ['issued_lines_aggregated'], target_id: null }],
    })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    expect(wrapper.get('[data-testid="stereo-dry-report"]').text()).not.toContain('Specific draft warning')
    expect(wrapper.get('[data-testid="stereo-dry-report"]').text()).toContain('stereo_nx.review_document')
    wrapper.unmount()
  })

  it('shows shared reconciliation checks and differences in the transfer protocol', async () => {
    m.run.mockResolvedValue({ ...dryReport, ok: false, reconciliation: [{
      year: 2030, period_id: 1, ok: false,
      checks: [{ key: 'stereo_nx_journal', ok: false }],
      journal_diffs: [{ account: '518', myucto: [0, 120, 120], money: [0, 100, 100] }],
      money_report: null, documents: [],
    }] })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    const report = wrapper.get('[data-testid="stereo-dry-report"]')
    expect(report.text()).toContain('stereo_nx.checks.stereo_nx_journal')
    expect(report.text()).toContain('518')
    wrapper.unmount()
  })

  it('keeps the ZIP when accounting reports agendas requiring further transfer', async () => {
    m.run.mockImplementation(async (_token: string, _company: number, mode: string) => mode === 'dry_run'
      ? { ...dryReport, partial: true } : { ...importReport, partial: true })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    await primaryButton(wrapper, 'continue').trigger('click')
    expect(wrapper.get('[data-testid="stereo-delete-after-import"]').attributes('disabled')).toBeDefined()
    expect(wrapper.text()).toContain('stereo_nx.partial_backup_kept')
    await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    expect(m.remove).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('opens an existing ZIP and gates import behind company selection, dry run and confirmation', async () => {
    const wrapper = await mountPage()
    expect(wrapper.find('[data-testid="stereo-upload-input"]').exists()).toBe(true)
    await openExisting(wrapper)
    expect(m.preview).toHaveBeenCalledWith(upload.token)
    expect(wrapper.find('[data-testid="stereo-company-select"]').exists()).toBe(true)
    await primaryButton(wrapper, 'continue').trigger('click')
    expect(wrapper.find('[data-testid="stereo-dry-report"]').exists()).toBe(true)
    await primaryButton(wrapper, 'dry_run').trigger('click')
    await flushPromises()
    expect(m.run).toHaveBeenCalledWith(upload.token, 0, 'dry_run', false, expect.any(Function))
    expect(wrapper.find('[data-testid="stereo-dry-report"]').exists()).toBe(true)
    await primaryButton(wrapper, 'continue').trigger('click')
    expect(wrapper.find('[data-testid="stereo-import-report"]').exists()).toBe(true)
    expect(primaryButton(wrapper, 'import').attributes('disabled')).toBeDefined()
    expect(m.run).toHaveBeenCalledTimes(1)
    const confirm = wrapper.get('input[type="checkbox"][data-testid="stereo-import-confirm"]')
    await confirm.setValue(true)
    expect(primaryButton(wrapper, 'import').attributes('disabled')).toBeUndefined()
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    expect(m.run).toHaveBeenLastCalledWith(upload.token, 0, 'import', false, expect.any(Function))
    expect(wrapper.find('[data-testid="stereo-import-report"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('stereo_nx.protocol.title')
    expect(wrapper.text()).not.toContain('#null')
    wrapper.unmount()
  })

  it('keeps an unsuccessful dry run from enabling the import', async () => {
    m.run.mockResolvedValueOnce({ ...dryReport, ok: false,
      errors: [{ level: 'error', code: 'period_closed', message: 'Synthetic closed period' }] })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    expect(wrapper.get('[data-testid="stereo-dry-report"]').text()).toContain('Synthetic closed period')
    expect(wrapper.find('[data-testid="stereo-import-report"]').exists()).toBe(false)
    expect(wrapper.findAll('button').some(item => item.text().includes('stereo_nx.import') && !item.attributes('disabled'))).toBe(false)
    expect(m.run).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('deletes the source only after a successful import and preserves its protocol', async () => {
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    await primaryButton(wrapper, 'continue').trigger('click')
    await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
    await wrapper.get('[data-testid="stereo-delete-after-import"]').setValue(true)
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    expect(m.remove).toHaveBeenCalledOnce()
    expect(m.remove).toHaveBeenCalledWith(upload.token)
    expect(wrapper.get('[data-testid="stereo-import-report"]').text()).toContain('stereo_nx.protocol.title')
    expect(wrapper.find('[data-testid="stereo-step-4"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('shows the background job progress while the dry run is running', async () => {
    let finish!: (report: typeof dryReport) => void
    m.run.mockImplementationOnce((_token: string, _company: number, _mode: string, _blank: boolean, onJob: (job: object) => void) => {
      onJob({ id: 5, status: 'running', total_items: 1, processed: 0, created_count: 0, skipped_count: 0, failed_count: 0, current_step: 'Synthetic dry run step' })
      return new Promise(resolve => { finish = resolve })
    })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    expect(wrapper.get('[data-testid="stereo-dry-report"]').text()).toContain('Synthetic dry run step')
    finish(dryReport)
    await flushPromises()
    expect(wrapper.get('[data-testid="stereo-dry-report"]').text()).not.toContain('Synthetic dry run step')
    wrapper.unmount()
  })

  it('retains a failed import and its ZIP even when deletion was selected', async () => {
    m.run.mockImplementation(async (_token: string, _company: number, mode: string) => mode === 'dry_run' ? dryReport
      : { ...importReport, ok: false, database_writes: false,
        errors: [{ level: 'error', code: 'source_changed', message: 'Synthetic changed source' }] })
    const wrapper = await mountPage()
    await reachDryRun(wrapper)
    await primaryButton(wrapper, 'continue').trigger('click')
    await wrapper.get('[data-testid="stereo-import-confirm"]').setValue(true)
    await wrapper.get('[data-testid="stereo-delete-after-import"]').setValue(true)
    await primaryButton(wrapper, 'import').trigger('click')
    await flushPromises()
    expect(m.remove).not.toHaveBeenCalled()
    expect(wrapper.get('[data-testid="stereo-import-report"]').text()).toContain('Synthetic changed source')
    wrapper.unmount()
  })
})
