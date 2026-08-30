import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  routeQuery: {} as Record<string, string | string[]>,
  routerReplace: vi.fn(),
  listDocuments: vi.fn(),
  listAnnualDocuments: vi.fn(),
  peoplePage: vi.fn(),
  peopleOptions: vi.fn(),
  person: vi.fn(),
  downloadDocumentById: vi.fn(),
  generatePayrollSheet: vi.fn(),
  generateTaxCertificate: vi.fn(),
  generateMonthlyBundle: vi.fn(),
  generateDocumentBatch: vi.fn(),
  documentBatch: vi.fn(),
  documentBatchItems: vi.fn(),
  retryDocumentBatchItem: vi.fn(),
  enqueueAnnualDocumentBatch: vi.fn(),
  annualDocumentBatch: vi.fn(),
  annualDocumentBatchItems: vi.fn(),
  retryAnnualDocumentBatchItem: vi.fn(),
  downloadPeriodExport: vi.fn(),
  downloadDocument: vi.fn(),
  documentSecureLinks: vi.fn(),
  sendDocumentSecureLink: vi.fn(),
  revokeDocumentSecureLink: vi.fn(),
  toastSuccess: vi.fn(),
  toastError: vi.fn(),
}))

// Stránka čte předvýběr z adresy (odkaz z karty zaměstnance), takže potřebuje
// router. Originál se rozprostře, ať zůstanou i ostatní exporty (RouterLink).
vi.mock('vue-router', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-router')>()),
  useRoute: () => ({ query: m.routeQuery }),
  useRouter: () => ({ replace: m.routerReplace }),
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    listDocuments: m.listDocuments,
    listAnnualDocuments: m.listAnnualDocuments,
    peoplePage: m.peoplePage,
    peopleOptions: m.peopleOptions,
    person: m.person,
    downloadDocumentById: m.downloadDocumentById,
    generatePayrollSheet: m.generatePayrollSheet,
    generateTaxCertificate: m.generateTaxCertificate,
    generateMonthlyBundle: m.generateMonthlyBundle,
    generateDocumentBatch: m.generateDocumentBatch,
    documentBatch: m.documentBatch,
    documentBatchItems: m.documentBatchItems,
    retryDocumentBatchItem: m.retryDocumentBatchItem,
    enqueueAnnualDocumentBatch: m.enqueueAnnualDocumentBatch,
    annualDocumentBatch: m.annualDocumentBatch,
    annualDocumentBatchItems: m.annualDocumentBatchItems,
    retryAnnualDocumentBatchItem: m.retryAnnualDocumentBatchItem,
    downloadPeriodExport: m.downloadPeriodExport,
    downloadDocument: m.downloadDocument,
    documentSecureLinks: m.documentSecureLinks,
    sendDocumentSecureLink: m.sendDocumentSecureLink,
    revokeDocumentSecureLink: m.revokeDocumentSecureLink,
  },
}))

vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({
    canWrite: (permission: string) => permission === 'payroll.documents',
  }),
}))

vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: m.toastSuccess, error: m.toastError }),
}))

// `useTablePrefs` táhne @/i18n, které volá skutečné `createI18n` — továrna
// proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string, params?: Record<string, unknown>) =>
      key === 'payroll.people.exit_documents.blockers.weekly_hours_evidence_missing'
        ? 'Chybí doložená týdenní pracovní doba.'
        : params ? `${key}:${JSON.stringify(params)}` : key,
    te: (key: string) => key
      === 'payroll.people.exit_documents.blockers.weekly_hours_evidence_missing',
  }),
}))

// Preference tabulek jdou přes Pinii a API; v testu stačí prázdné výchozí.
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})

import PayrollDocuments from '@/pages/payroll/PayrollDocuments.vue'

function deferred<T>(): {
  promise: Promise<T>
  resolve: (value: T) => void
} {
  let resolve!: (value: T) => void
  const promise = new Promise<T>((resolver) => {
    resolve = resolver
  })
  return { promise, resolve }
}

describe('PayrollDocuments', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.routeQuery = {}
    m.listDocuments.mockResolvedValue({
      period: '2026-07',
      revisions: [{
        run_id: 11,
        revision_id: 12,
        revision_no: 2,
        status: 'approved',
        office_id: null,
        office_name: 'Praha',
      }],
      items: [
        {
          id: 21,
          run_id: 11,
          revision_id: 12,
          revision_no: 2,
          employee_id: 31,
          employee_name: 'Testovací Zaměstnanec',
          office_name: 'Praha',
          document_kind: 'payslip',
          document_revision_no: 1,
          mime_type: 'application/pdf',
          suggested_filename: 'vyplatni-paska-2026-07-abcdef123456.pdf',
          file_sha256: 'a'.repeat(64),
          size_bytes: 4567,
          created_at: '2026-08-01 08:00:00',
        },
        {
          id: 22,
          run_id: 11,
          revision_id: 12,
          revision_no: 2,
          employee_id: null,
          employee_name: null,
          office_name: 'Praha',
          document_kind: 'monthly_bundle',
          document_revision_no: 2,
          mime_type: 'application/zip',
          suggested_filename: 'mzdovy-balicek-2026-07-bcdef1234567.zip',
          file_sha256: 'b'.repeat(64),
          size_bytes: 9876,
          created_at: '2026-08-01 08:05:00',
        },
      ],
      total: 2,
    })
    m.generateMonthlyBundle.mockResolvedValue({
      id: 22,
      document_kind: 'monthly_bundle',
      revision_id: 12,
      file_sha256: 'b'.repeat(64),
      size_bytes: 9876,
    })
    m.generateDocumentBatch.mockResolvedValue({
      id: 81,
      run_id: 11,
      revision_id: 12,
      period_start: '2026-07-01',
      status: 'queued',
      item_count: 2,
      succeeded_count: 0,
      failed_count: 0,
      bundle_document_id: null,
      bundle_filename: null,
      created_at: '2026-08-01 08:00:00',
      started_at: null,
      completed_at: null,
      updated_at: '2026-08-01 08:00:00',
    })
    m.documentBatch.mockResolvedValue({
      id: 81,
      run_id: 11,
      revision_id: 12,
      period_start: '2026-07-01',
      status: 'failed',
      item_count: 2,
      succeeded_count: 1,
      failed_count: 1,
      bundle_document_id: null,
      bundle_filename: null,
      created_at: '2026-08-01 08:00:00',
      started_at: '2026-08-01 08:00:01',
      completed_at: null,
      updated_at: '2026-08-01 08:00:04',
    })
    m.documentBatchItems.mockResolvedValue({
      items: [{
        id: 91,
        batch_id: 81,
        employee_id: 31,
        employee_name: 'Testovací Zaměstnanec',
        status: 'failed',
        attempt_count: 3,
        available_at: '2026-08-01 08:00:04',
        document_id: null,
        last_error_code: 'render_domain_exception',
        last_error_message: 'Chybí povinný podklad výplatní pásky.',
        completed_at: null,
        updated_at: '2026-08-01 08:00:04',
      }],
      total: 1,
    })
    m.retryDocumentBatchItem.mockResolvedValue({ status: 'queued' })
    m.enqueueAnnualDocumentBatch.mockResolvedValue({
      id: 501,
      tax_year: 2026,
      document_kind: 'payroll_sheet',
      scope: 'all',
      status: 'queued',
      item_count: 3,
      succeeded_count: 0,
      failed_count: 0,
      skipped_count: 0,
      created_at: '2026-08-01 08:00:00',
      started_at: null,
      completed_at: null,
      updated_at: '2026-08-01 08:00:00',
    })
    m.annualDocumentBatch.mockResolvedValue({
      id: 501,
      tax_year: 2026,
      document_kind: 'payroll_sheet',
      scope: 'all',
      status: 'completed',
      item_count: 3,
      succeeded_count: 1,
      failed_count: 1,
      skipped_count: 1,
      created_at: '2026-08-01 08:00:00',
      started_at: '2026-08-01 08:00:01',
      completed_at: '2026-08-01 08:00:09',
      updated_at: '2026-08-01 08:00:09',
    })
    m.annualDocumentBatchItems.mockResolvedValue({
      items: [
        {
          id: 601,
          batch_id: 501,
          employee_id: 31,
          employee_name: 'Testovací Zaměstnanec',
          status: 'skipped',
          attempt_count: 1,
          available_at: '2026-08-01 08:00:05',
          document_id: null,
          last_error_code: 'annual_document_exists',
          last_error_message: 'Osoba už potvrzení za rok má.',
          completed_at: '2026-08-01 08:00:05',
          updated_at: '2026-08-01 08:00:05',
        },
        {
          id: 602,
          batch_id: 501,
          employee_id: 32,
          employee_name: 'Druhá Osoba',
          status: 'failed',
          attempt_count: 3,
          available_at: '2026-08-01 08:00:07',
          document_id: null,
          last_error_code: 'render_domain_exception',
          last_error_message: 'Chybí schválená revize.',
          completed_at: null,
          updated_at: '2026-08-01 08:00:07',
        },
        {
          id: 603,
          batch_id: 501,
          employee_id: 33,
          employee_name: 'Třetí Osoba',
          status: 'succeeded',
          attempt_count: 1,
          available_at: '2026-08-01 08:00:09',
          document_id: 71,
          last_error_code: null,
          last_error_message: null,
          completed_at: '2026-08-01 08:00:09',
          updated_at: '2026-08-01 08:00:09',
        },
      ],
      total: 3,
    })
    m.retryAnnualDocumentBatchItem.mockResolvedValue({ id: 602, status: 'queued' })
    m.listAnnualDocuments.mockResolvedValue({
      year: 2026,
      items: [],
      total: 0,
    })
    m.peoplePage.mockResolvedValue({
      items: [{
        id: 31,
        full_name: 'Testovací Zaměstnanec',
        is_active: true,
        needs_setup: false,
      }],
      total: 1,
      limit: 25,
      offset: 0,
    })
    m.person.mockResolvedValue({
      id: 31,
      full_name: 'Testovací Zaměstnanec',
      is_active: true,
      needs_setup: false,
    })
    m.peopleOptions.mockResolvedValue([
      { id: 31, full_name: 'Testovací Zaměstnanec', is_active: true, needs_setup: false },
      { id: 32, full_name: 'Druhá Osoba', is_active: true, needs_setup: false },
      // Neaktivní člověk se do dávky nebere.
      { id: 33, full_name: 'Bývalý Zaměstnanec', is_active: false, needs_setup: false },
    ])
    m.downloadDocumentById.mockResolvedValue(undefined)
    m.generatePayrollSheet.mockResolvedValue({
      id: 41,
      document_kind: 'payroll_sheet',
    })
    m.generateTaxCertificate.mockResolvedValue({
      id: 42,
      document_kind: 'taxable_income_advance_certificate',
    })
    m.downloadDocument.mockResolvedValue(undefined)
    m.documentSecureLinks.mockResolvedValue([])
    m.sendDocumentSecureLink.mockResolvedValue({
      link_id: 1,
      created: true,
      recipient_masked: 'te***@example.com',
      expires_at: '2026-08-08 00:00:00',
    })
    m.revokeDocumentSecureLink.mockResolvedValue({ revoked: true })
    m.downloadPeriodExport.mockResolvedValue({
      id: 91,
      scope: 'monthly',
      period_start: '2026-07-01',
      period_end: '2026-07-31',
      file_sha256: 'e'.repeat(64),
      size_bytes: 12345,
      suggested_filename: 'mzdy-2026-07-abcdef123456.zip',
    })
  })

  it('renders responsive document cards and a desktop table without exposing hashes as names', async () => {
    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    expect(m.listDocuments).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[data-test="documents-table"]').classes()).toContain('md:block')
    expect(wrapper.get('[data-test="documents-cards"]').classes()).toContain('md:hidden')
    expect(wrapper.text()).toContain('Testovací Zaměstnanec')
    expect(wrapper.text()).toContain('Praha')
    expect(wrapper.text()).toContain('payroll.documents.document_revision')
    expect(wrapper.text()).toContain('payroll.documents.company')
    expect(wrapper.text()).toContain('payroll.documents.kind.payslip')
    expect(wrapper.text()).not.toContain('a'.repeat(64))
  })

  it('downloads individual artifacts without offering a premature ZIP action', async () => {
    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    expect(wrapper.find('[data-test="generate-bundle"]').exists()).toBe(false)

    const buttons = wrapper.findAll('[data-test="download-document"]')
    await buttons[0].trigger('click')
    expect(m.downloadDocument).toHaveBeenCalledWith(
      expect.objectContaining({ id: 21, mime_type: 'application/pdf' }),
    )
  })

  it('polls asynchronous progress and retries one failed person', async () => {
    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    await wrapper.get('[data-test="generate-document-batch"]').trigger('click')
    await flushPromises()

    const report = wrapper.get('[data-test="document-batch-report"]')
    expect(m.documentBatch).toHaveBeenCalledWith(81)
    expect(m.documentBatchItems).toHaveBeenCalledWith(81, { limit: 100, offset: 0 })
    expect(report.text()).toContain('Testovací Zaměstnanec')
    expect(report.text()).toContain('Chybí povinný podklad výplatní pásky.')
    expect(report.text()).toContain('payroll.documents.batch_progress')
    expect(report.text()).not.toContain('render_domain_exception')

    await wrapper.get('[data-test="retry-document-batch-item"]').trigger('click')
    await flushPromises()
    expect(m.retryDocumentBatchItem).toHaveBeenCalledWith(81, 91)
    wrapper.unmount()
  })

  it('exports monthly and annual archives without loading the employee list', async () => {
    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    const archiveTab = wrapper.findAll('nav button')
      .find(button => button.text() === 'payroll.documents.tabs.archive')
    expect(archiveTab).toBeDefined()
    await archiveTab!.trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-test="period-export-panel"]').exists()).toBe(true)
    expect(m.peoplePage).not.toHaveBeenCalled()
    expect(m.listAnnualDocuments).not.toHaveBeenCalled()

    await wrapper.get<HTMLInputElement>('[data-test="period-export-month"]')
      .setValue('2026-08')
    await wrapper.get('[data-test="download-monthly-period-export"]')
      .trigger('click')
    await flushPromises()
    expect(m.downloadPeriodExport).toHaveBeenCalledWith('monthly', '2026-08')

    await wrapper.get<HTMLInputElement>('[data-test="period-export-year"]')
      .setValue('2025')
    await wrapper.get('[data-test="download-annual-period-export"]')
      .trigger('click')
    await flushPromises()
    expect(m.downloadPeriodExport).toHaveBeenCalledWith('annual', 2025)
    expect(m.toastSuccess).toHaveBeenCalledWith(expect.stringContaining(
      'mzdy-2026-07-abcdef123456.zip',
    ))
  })

  it('opens the archive directly without loading document or employee lists', async () => {
    m.routeQuery = { tab: 'archive' }

    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    expect(wrapper.find('[data-test="period-export-panel"]').exists()).toBe(true)
    expect(m.listDocuments).not.toHaveBeenCalled()
    expect(m.listAnnualDocuments).not.toHaveBeenCalled()
    expect(m.peoplePage).not.toHaveBeenCalled()
  })

  it('ke generování ročních dokumentů načítá jen omezenou stránku hledaných zaměstnanců', async () => {
    m.peoplePage.mockResolvedValue({
      items: Array.from({ length: 25 }, (_, index) => ({
        id: index + 1,
        full_name: `Syntetický zaměstnanec ${String(index + 1).padStart(3, '0')}`,
        is_active: true,
        needs_setup: false,
      })),
      total: 500,
      limit: 25,
      offset: 0,
    })

    const wrapper = mount(PayrollDocuments)
    await flushPromises()
    const annualTab = wrapper.findAll('nav button')
      .find(button => button.text() === 'payroll.documents.tabs.annual')
    await annualTab!.trigger('click')
    await flushPromises()

    const personSelect = wrapper.get('[data-test="payroll-documents-person"]')
    await personSelect.get('input[role="combobox"]').trigger('focus')
    await flushPromises()

    expect(m.peoplePage).toHaveBeenCalledWith({ limit: 25, offset: 0, q: '' })
    expect(personSelect.findAll('[role="option"]')).toHaveLength(25)
    expect(personSelect.get('[data-test="searchable-select-truncated"]').text())
      .toBe('payroll.person_search.truncated')
  })

  it('ignores an older period response that arrives after a newer request', async () => {
    const first = deferred<Awaited<ReturnType<typeof m.listDocuments>>>()
    const second = deferred<Awaited<ReturnType<typeof m.listDocuments>>>()
    m.listDocuments
      .mockReturnValueOnce(first.promise)
      .mockReturnValueOnce(second.promise)

    const wrapper = mount(PayrollDocuments)
    const periodInput = wrapper.get('input[type="month"]')
    expect(m.listDocuments).toHaveBeenCalledTimes(1)
    await periodInput.setValue('2026-08')
    expect(m.listDocuments).toHaveBeenCalledTimes(2)

    second.resolve({
      period: '2026-08',
      revisions: [],
      items: [{
        id: 81,
        employee_name: 'Novější období',
        document_kind: 'payslip',
        size_bytes: 1,
        created_at: '2026-08-01 08:00:00',
      }],
    })
    await flushPromises()
    first.resolve({
      period: '2026-07',
      revisions: [],
      items: [{
        id: 71,
        employee_name: 'Starší období',
        document_kind: 'payslip',
        size_bytes: 1,
        created_at: '2026-07-01 08:00:00',
      }],
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Novější období')
    expect(wrapper.text()).not.toContain('Starší období')
  })

  it('creates both annual tax certificate variants from the annual tab', async () => {
    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    const annualTab = wrapper.findAll('nav button')
      .find(button => button.text() === 'payroll.documents.tabs.annual')
    expect(annualTab).toBeDefined()
    await annualTab!.trigger('click')
    await flushPromises()

    const personSelect = wrapper.get('[data-test="payroll-documents-person"]')
    await personSelect.get('input[role="combobox"]').trigger('focus')
    await flushPromises()
    expect(m.peoplePage).toHaveBeenCalledWith({ limit: 25, offset: 0, q: '' })
    await personSelect.get('[role="option"]').trigger('click')

    const advanceButton = wrapper.findAll('button').find(button =>
      button.text() === 'payroll.documents.generate_tax_certificate_advance')
    expect(advanceButton).toBeDefined()
    await advanceButton!.trigger('click')
    await flushPromises()
    expect(m.generateTaxCertificate).toHaveBeenCalledWith(
      31,
      expect.any(Number),
      'taxable_income_advance_certificate',
      {
        supersedes_document_id: null,
        correction_reason: null,
      },
    )

    const withholdingButton = wrapper.findAll('button').find(button =>
      button.text() === 'payroll.documents.generate_tax_certificate_withholding')
    expect(withholdingButton).toBeDefined()
    await withholdingButton!.trigger('click')
    await flushPromises()
    expect(m.generateTaxCertificate).toHaveBeenCalledWith(
      31,
      expect.any(Number),
      'taxable_income_withholding_certificate',
      {
        supersedes_document_id: null,
        correction_reason: null,
      },
    )
    expect(m.toastSuccess).toHaveBeenCalledWith(
      'payroll.documents.tax_certificate_created',
    )
  })

  it('requires a concrete reason and references the latest certificate when correcting it', async () => {
    m.listAnnualDocuments.mockResolvedValue({
      year: 2026,
      items: [{
        id: 77,
        run_id: null,
        revision_id: null,
        annual_revision_id: 8,
        annual_revision_no: 2,
        tax_year: 2026,
        employee_id: 31,
        employee_name: 'Testovací Zaměstnanec',
        document_kind: 'taxable_income_advance_certificate',
        document_revision_no: 2,
        supersedes_document_id: 70,
        mime_type: 'application/pdf',
        suggested_filename: 'potvrzeni.pdf',
        file_sha256: 'c'.repeat(64),
        size_bytes: 4567,
        created_at: '2026-08-04 12:00:00',
      }],
    })
    const wrapper = mount(PayrollDocuments)
    await flushPromises()
    const annualTab = wrapper.findAll('nav button')
      .find(button => button.text() === 'payroll.documents.tabs.annual')
    await annualTab!.trigger('click')
    await flushPromises()

    const personSelect = wrapper.get('[data-test="payroll-documents-person"]')
    await personSelect.get('input[role="combobox"]').trigger('focus')
    await flushPromises()
    await personSelect.get('[role="option"]').trigger('click')

    const advanceButton = wrapper.findAll('button').find(button =>
      button.text() === 'payroll.documents.generate_tax_certificate_advance')
    await advanceButton!.trigger('click')
    expect(m.generateTaxCertificate).not.toHaveBeenCalled()
    expect(wrapper.find('[data-test="tax-certificate-correction"]').exists()).toBe(true)

    await wrapper.get('[data-test="submit-tax-certificate-correction"]').trigger('submit')
    expect(m.toastError).toHaveBeenCalledWith(
      'payroll.documents.correction_reason_required',
    )
    await wrapper.get<HTMLTextAreaElement>('[data-test="correction-reason"]')
      .setValue('Oprava nesprávně uvedeného identifikátoru poplatníka.')
    await wrapper.get('[data-test="tax-certificate-correction"]').trigger('submit')
    await flushPromises()

    expect(m.generateTaxCertificate).toHaveBeenCalledWith(
      31,
      expect.any(Number),
      'taxable_income_advance_certificate',
      {
        supersedes_document_id: 77,
        correction_reason: 'Oprava nesprávně uvedeného identifikátoru poplatníka.',
      },
    )
  })

  /**
   * Roční dokumenty za celou firmu jdou do SERVEROVÉ fronty, ne do smyčky
   * v prohlížeči. Odejde jeden požadavek, ne jeden na zaměstnance — u 500 lidí
   * to byl rozdíl mezi „hotovo" a timeoutem na zavřené záložce.
   */
  it('roční dokumenty za všechny zařadí do serverové fronty jedním požadavkem', async () => {
    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    const annualTab = wrapper.findAll('nav button')
      .find(button => button.text() === 'payroll.documents.tabs.annual')
    await annualTab!.trigger('click')
    await flushPromises()

    // Bez vybrané osoby tlačítka drží a věta říká proč.
    expect(wrapper.text()).toContain('payroll.documents.batch_annual.blocked_no_person')

    await wrapper.get('[data-test="annual-scope-all"]').trigger('click')
    await flushPromises()

    const sheetButton = wrapper.findAll('button').find(button =>
      button.text() === 'payroll.documents.generate_payroll_sheet')
    await sheetButton!.trigger('click')
    await flushPromises()

    expect(m.enqueueAnnualDocumentBatch).toHaveBeenCalledTimes(1)
    expect(m.enqueueAnnualDocumentBatch)
      .toHaveBeenCalledWith('payroll_sheet', expect.any(Number), 'all', null)
    // Nikdo se negeneruje po jednom a seznam osob se kvůli dávce netahá.
    expect(m.generatePayrollSheet).not.toHaveBeenCalled()
    expect(m.peopleOptions).not.toHaveBeenCalled()
    expect(wrapper.get('[data-test="annual-batch-report"]').text())
      .toContain('payroll.documents.batch_annual.progress')
  })

  /** Zařazení je idempotentní: druhý klik do běžící dávky nic nepošle. */
  it('druhý klik do rozdělané dávky nezaloží další', async () => {
    m.annualDocumentBatch.mockResolvedValue({
      id: 501,
      tax_year: 2026,
      document_kind: 'payroll_sheet',
      scope: 'all',
      status: 'running',
      item_count: 3,
      succeeded_count: 1,
      failed_count: 0,
      skipped_count: 0,
      created_at: '2026-08-01 08:00:00',
      started_at: '2026-08-01 08:00:01',
      completed_at: null,
      updated_at: '2026-08-01 08:00:03',
    })
    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    await wrapper.findAll('nav button')
      .find(button => button.text() === 'payroll.documents.tabs.annual')!
      .trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="annual-scope-all"]').trigger('click')
    const sheetButton = wrapper.findAll('button')
      .find(button => button.text() === 'payroll.documents.generate_payroll_sheet')!
    await sheetButton.trigger('click')
    await flushPromises()
    await sheetButton.trigger('click')
    await flushPromises()

    expect(m.enqueueAnnualDocumentBatch).toHaveBeenCalledTimes(1)
    // Rozdělaná dávka se doptává v intervalu; bez odmontování by časovač
    // přežil test.
    wrapper.unmount()
  })

  /**
   * Neúspěšný řádek se nesmí ztratit v počtu — dávka ho vypíše jménem
   * i důvodem, jinak zbývá otevřít 500 lidí a hádat, kdo chybí. Chyba u jednoho
   * člověka přitom dávku nezhodí: ostatní doběhnou.
   */
  it('neúspěšné řádky dávky vypíše jménem a nabídne opakování', async () => {
    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    await wrapper.findAll('nav button')
      .find(button => button.text() === 'payroll.documents.tabs.annual')!
      .trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="annual-scope-all"]').trigger('click')
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.documents.generate_payroll_sheet')!
      .trigger('click')
    await flushPromises()

    const failures = wrapper.get('[data-test="annual-batch-failures"]')
    expect(failures.text()).toContain('Druhá Osoba')
    expect(failures.text()).toContain('Chybí schválená revize.')
    expect(failures.text()).not.toContain('Třetí Osoba')

    await wrapper.get('[data-test="retry-annual-batch-item"]').trigger('click')
    await flushPromises()
    expect(m.retryAnnualDocumentBatchItem).toHaveBeenCalledWith(501, 602)
  })

  /**
   * Osoba, která dokument za rok už má, se PŘESKOČÍ — nahrazení je oprava
   * s povinným důvodem. O tom rozhoduje server, prohlížeč jen vypíše jméno.
   */
  it('přeskočenou osobu vypíše jménem', async () => {
    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    await wrapper.findAll('nav button')
      .find(button => button.text() === 'payroll.documents.tabs.annual')!
      .trigger('click')
    await flushPromises()
    await wrapper.get('[data-test="annual-scope-all"]').trigger('click')
    await wrapper.findAll('button')
      .find(button => button.text() === 'payroll.documents.generate_payroll_sheet')!
      .trigger('click')
    await flushPromises()

    expect(wrapper.get('[data-test="annual-batch-skipped"]').text())
      .toContain('Testovací Zaměstnanec')
  })

  /**
   * X-16: hotový měsíční ZIP se dřív jen OZNÁMIL větou, ze které nevedl odkaz.
   * Teď je z něj tlačítko, které soubor stáhne.
   */
  it('hotový měsíční balík nabídne ke stažení, ne jen oznámí', async () => {
    m.documentBatch.mockResolvedValue({
      id: 81,
      run_id: 11,
      revision_id: 12,
      period_start: '2026-07-01',
      status: 'completed',
      item_count: 1,
      succeeded_count: 1,
      failed_count: 0,
      bundle_document_id: 22,
      bundle_filename: 'mzdovy-balicek-2026-07.zip',
      created_at: '2026-08-01 08:00:00',
      started_at: '2026-08-01 08:00:01',
      completed_at: '2026-08-01 08:00:09',
      updated_at: '2026-08-01 08:00:09',
    })
    m.documentBatchItems.mockResolvedValue({ items: [], total: 0 })
    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    await wrapper.get('[data-test="generate-document-batch"]').trigger('click')
    await flushPromises()

    const download = wrapper.get('[data-test="download-batch-bundle"]')
    expect(wrapper.text()).toContain('mzdovy-balicek-2026-07.zip')
    await download.trigger('click')
    await flushPromises()

    expect(m.downloadDocumentById).toHaveBeenCalledWith(22, 'mzdovy-balicek-2026-07.zip')
  })

  /**
   * Osobní dokument (`employee_id` vyplněné) nabídne odeslání zabezpečeného
   * odkazu; po odeslání a dotažení stavu se objeví i jeho zneplatnění. Odkaz
   * ani token se nikde v UI neobjeví — API je záměrně nevrací.
   */
  it('nabídne odeslání zabezpečeného odkazu a po odeslání jeho zneplatnění', async () => {
    // Mock dat 21 nemá `delivery.secure_link_sent_at`, takže se odkazy PŘED
    // odesláním vůbec nedotahují — jediné volání přijde až po kliknutí na
    // odeslání a vrátí právě založený živý odkaz.
    m.documentSecureLinks
      .mockResolvedValueOnce([{
        id: 5,
        document_id: 21,
        employee_id: 31,
        recipient_masked: 'te***@example.com',
        dispatch_state: 'sent',
        attempt_count: 1,
        last_error_code: null,
        expires_at: '2026-08-08 00:00:00',
        sent_at: '2026-08-01 08:00:00',
        revoked_at: null,
        first_downloaded_at: null,
        last_downloaded_at: null,
        download_count: 0,
        is_live: true,
      }])

    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    // Dokument bez employee_id (monthly_bundle) žádnou akci nenabízí. Dva
    // zbylé tlačítka jsou desktopová tabulka + mobilní karta téhož řádku.
    const sendButtons = wrapper.findAll('[data-test="send-secure-link"]')
    expect(sendButtons).toHaveLength(2)
    expect(wrapper.find('[data-test="revoke-secure-link"]').exists()).toBe(false)

    await sendButtons[0].trigger('click')
    await flushPromises()

    expect(m.sendDocumentSecureLink).toHaveBeenCalledWith(21)
    expect(m.toastSuccess).toHaveBeenCalledWith(
      expect.stringContaining('te***@example.com'),
    )

    const revokeButton = wrapper.get('[data-test="revoke-secure-link"]')
    vi.spyOn(window, 'confirm').mockReturnValue(true)
    await revokeButton.trigger('click')
    await flushPromises()

    expect(m.revokeDocumentSecureLink).toHaveBeenCalledWith(21, 5)
    expect(m.toastSuccess).toHaveBeenCalledWith(
      'payroll.documents.secure_delivery.link_revoked',
    )
  })

  /** 409 `secure_delivery_blocked` se přeloží na srozumitelnou větu podle `reason`. */
  it('zamítnuté odeslání ukáže důvod podle kódu z backendu', async () => {
    m.sendDocumentSecureLink.mockRejectedValueOnce({
      response: { data: { error: { code: 'secure_delivery_blocked', reason: 'recipient_email_missing', message: 'blocked' } } },
    })

    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    await wrapper.get('[data-test="send-secure-link"]').trigger('click')
    await flushPromises()

    expect(m.toastError).toHaveBeenCalledWith(
      'payroll.documents.secure_delivery.reason.recipient_email_missing',
    )
  })
})
