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
  downloadPeriodExport: vi.fn(),
  downloadDocument: vi.fn(),
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
    downloadPeriodExport: m.downloadPeriodExport,
    downloadDocument: m.downloadDocument,
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
   * X-11: roční dokumenty šly jen po jednom — pro 500 lidí 1500 kliknutí,
   * zatímco měsíční pásky hromadné dávno jsou. Rozsah „za všechny" projede
   * stejné tři akce nad celou firmou.
   */
  it('vystaví roční dokument všem aktivním zaměstnancům najednou', async () => {
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

    // Jen aktivní lidé; bývalý zaměstnanec do dávky nepatří.
    expect(m.generatePayrollSheet).toHaveBeenCalledTimes(2)
    expect(m.generatePayrollSheet.mock.calls.map(call => call[0])).toEqual([31, 32])
    expect(wrapper.get('[data-test="annual-batch-report"]').text())
      .toContain('payroll.documents.batch_annual.progress')
  })

  /**
   * Neúspěšný řádek se nesmí ztratit v počtu — dávka ho vypíše jménem
   * i důvodem, jinak zbývá otevřít 500 lidí a hádat, kdo chybí.
   */
  it('neúspěšné řádky dávky vypíše jménem', async () => {
    m.generatePayrollSheet.mockImplementation((employeeId: number) =>
      employeeId === 32
        ? Promise.reject({ response: { data: { error: { message: 'Chybí schválená revize.' } } } })
        : Promise.resolve({ id: 41 }))
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
    expect(failures.text()).not.toContain('Testovací Zaměstnanec')
  })

  /**
   * Osoba, která dokument za rok už má, se PŘESKOČÍ — nahrazení je oprava
   * s povinným důvodem. Vypadnout ale musí viditelně, jménem.
   */
  it('osobu s hotovým dokumentem přeskočí a řekne to', async () => {
    m.listAnnualDocuments.mockResolvedValue({
      year: 2026,
      items: [{
        id: 55,
        employee_id: 31,
        employee_name: 'Testovací Zaměstnanec',
        document_kind: 'annual_payroll_sheet',
        document_revision_no: 1,
        mime_type: 'application/pdf',
        suggested_filename: 'mzdovy-list.pdf',
        file_sha256: 'c'.repeat(64),
        size_bytes: 1234,
        created_at: '2026-08-01 08:00:00',
      }],
      total: 1,
    })
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

    expect(m.generatePayrollSheet).toHaveBeenCalledTimes(1)
    expect(m.generatePayrollSheet).toHaveBeenCalledWith(32, expect.any(Number))
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
})
