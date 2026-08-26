import { beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  routeQuery: {} as Record<string, string | string[]>,
  routerReplace: vi.fn(),
  listDocuments: vi.fn(),
  listAnnualDocuments: vi.fn(),
  peopleOptions: vi.fn(),
  generatePayrollSheet: vi.fn(),
  generateTaxCertificate: vi.fn(),
  generateMonthlyBundle: vi.fn(),
  generateDocumentBatch: vi.fn(),
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
    peopleOptions: m.peopleOptions,
    generatePayrollSheet: m.generatePayrollSheet,
    generateTaxCertificate: m.generateTaxCertificate,
    generateMonthlyBundle: m.generateMonthlyBundle,
    generateDocumentBatch: m.generateDocumentBatch,
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
    })
    m.generateMonthlyBundle.mockResolvedValue({
      id: 22,
      document_kind: 'monthly_bundle',
      revision_id: 12,
      file_sha256: 'b'.repeat(64),
      size_bytes: 9876,
    })
    m.generateDocumentBatch.mockResolvedValue({
      run_id: 11,
      revision_id: 12,
      period_start: '2026-07-01',
      period_end: '2026-07-31',
      complete: false,
      payslips: { required: 1, archived: 1 },
      employment_exits: [{
        employment_id: 73,
        employee_id: 31,
        employee_name: null,
        end_date: '2026-07-31',
        relation_type: 'employment',
        documents: {
          employment_certificate: {
            required: true,
            archived: false,
            document_id: null,
            available: false,
            readiness_code: 'weekly_hours_evidence_missing',
          },
        },
      }],
    })
    m.listAnnualDocuments.mockResolvedValue({
      year: 2026,
      items: [],
    })
    m.peopleOptions.mockResolvedValue([{
      id: 31,
      full_name: 'Testovací Zaměstnanec',
      is_active: true,
      needs_setup: false,
    }])
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

  it('creates an idempotent monthly bundle and downloads individual artifacts', async () => {
    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    await wrapper.get('[data-test="generate-bundle"]').trigger('click')
    expect(m.generateMonthlyBundle).toHaveBeenCalledWith(11, 12, expect.any(String))

    const buttons = wrapper.findAll('[data-test="download-document"]')
    await buttons[0].trigger('click')
    expect(m.downloadDocument).toHaveBeenCalledWith(
      expect.objectContaining({ id: 21, mime_type: 'application/pdf' }),
    )
  })

  it('v dávkovém výsledku přeloží blokaci a nepoužije interní ID jako jméno', async () => {
    const wrapper = mount(PayrollDocuments)
    await flushPromises()

    await wrapper.get('[data-test="generate-document-batch"]').trigger('click')
    await flushPromises()

    const report = wrapper.get('[data-test="document-batch-report"]')
    expect(report.text()).toContain('Chybí doložená týdenní pracovní doba.')
    expect(report.text()).toContain('payroll.documents.batch_exit_employee_unknown')
    expect(report.text()).not.toContain('weekly_hours_evidence_missing')
    expect(report.text()).not.toContain('73')
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
    expect(m.peopleOptions).not.toHaveBeenCalled()
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
    expect(m.peopleOptions).not.toHaveBeenCalled()
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
})
