import { beforeEach, describe, expect, it, vi } from 'vitest'
import type {
  PayrollDocument,
  PayrollEmploymentCertificateEvidence,
} from '@/api/payroll'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/client', () => ({
  api: {
    get: m.get,
    post: m.post,
  },
}))

import { payrollApi } from '@/api/payroll'

describe('payroll document downloads', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.post.mockResolvedValue({
      data: {
        token: 'c'.repeat(64),
        expires_at: '2026-08-03 12:05:00',
      },
    })
    m.get.mockResolvedValue({ data: new Blob(['synthetic']) })
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:synthetic')
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)
  })

  it('keeps the one-time token in a request header and out of the URL', async () => {
    const item: PayrollDocument = {
      id: 42,
      run_id: 7,
      revision_id: 8,
      employee_id: 9,
      employee_name: 'Testovací Zaměstnanec',
      document_kind: 'payslip',
      file_sha256: 'a'.repeat(64),
      size_bytes: 9,
      mime_type: 'application/pdf',
      suggested_filename: 'vyplatni-paska-2026-07-abcdef123456.pdf',
      created_at: '2026-08-03 12:00:00',
    }

    await payrollApi.downloadDocument(item)

    expect(m.post).toHaveBeenCalledWith('/payroll/documents/42/download-grant')
    expect(m.get).toHaveBeenCalledWith(
      '/payroll/documents/42/download',
      expect.objectContaining({
        responseType: 'blob',
        headers: { 'X-Payroll-Download-Token': 'c'.repeat(64) },
      }),
    )
    expect(m.get.mock.calls[0][0]).not.toContain('token=')
  })

  it('uses the dedicated annual anchor endpoints', async () => {
    m.get.mockResolvedValueOnce({ data: { year: 2026, items: [] } })
    await payrollApi.listAnnualDocuments(2026)
    expect(m.get).toHaveBeenCalledWith('/payroll/documents/annual', {
      params: { year: 2026 },
    })

    m.post.mockResolvedValueOnce({ data: { id: 77 } })
    await payrollApi.generatePayrollSheet(9, 2026)
    expect(m.post).toHaveBeenCalledWith(
      '/payroll/people/9/documents/payroll-sheet/2026',
      {},
    )
  })

  it('creates a period archive and keeps its one-time token in a POST body', async () => {
    m.post
      .mockResolvedValueOnce({
        data: {
          id: 91,
          scope: 'monthly',
          period_start: '2026-08-01',
          period_end: '2026-08-31',
          file_sha256: 'e'.repeat(64),
          size_bytes: 12345,
          suggested_filename: 'mzdy-2026-08-abcdef123456.zip',
        },
      })
      .mockResolvedValueOnce({
        data: {
          grant_id: 92,
          export_id: 91,
          token: 'one-time-secret',
          expires_at: '2026-08-03T12:02:00+00:00',
        },
      })
      .mockResolvedValueOnce({ data: new Blob(['synthetic zip']) })

    await payrollApi.downloadPeriodExport('monthly', '2026-08')

    expect(m.post).toHaveBeenNthCalledWith(
      1,
      '/payroll/exports/monthly/2026-08',
      {},
    )
    expect(m.post).toHaveBeenNthCalledWith(
      2,
      '/payroll/exports/91/download-grants',
      { ttl_seconds: 120 },
    )
    expect(m.post).toHaveBeenNthCalledWith(
      3,
      '/payroll/exports/download',
      { token: 'one-time-secret' },
      { responseType: 'blob' },
    )
    expect(m.post.mock.calls[2][0]).not.toContain('token=')
  })

  it('uses dedicated exit-document endpoints and an idempotency header', async () => {
    m.get.mockResolvedValueOnce({
      data: {
        employment_id: 12,
        readiness: {
          employment_certificate: {
            available: true,
            readiness_code: null,
            deduction_claim_ids: [91],
          },
          average_earnings_certificate: {
            available: false,
            readiness_code: 'average_earnings_ruleset_not_ready',
          },
        },
        items: [],
      },
    })

    await payrollApi.employmentExitDocuments(12)
    expect(m.get).toHaveBeenCalledWith('/payroll/employments/12/documents/exit')

    const payload: PayrollEmploymentCertificateEvidence = {
      work_description: 'Synthetic work',
      achieved_qualification: 'Synthetic qualification',
      exposure_assessment_complete: true,
      exposure_facts: [],
      deduction_assessment_complete: true,
      deductions: [{
        source_claim_id: 91,
        beneficiary: 'Synthetic beneficiary',
        ordering_authority: 'Synthetic authority',
        decision_reference: 'TEST-91',
      }],
      pension_category_assessment_complete: true,
      pre1993_pension_category_periods: [],
      dpp_issuance_basis: null,
      correction_reason: null,
    }
    m.post.mockResolvedValueOnce({ data: { id: 88 } })

    await payrollApi.generateEmploymentCertificate(12, payload, 'exit-idempotency')

    expect(m.post).toHaveBeenCalledWith(
      '/payroll/employments/12/documents/exit/employment-certificate',
      payload,
      { headers: { 'Idempotency-Key': 'exit-idempotency' } },
    )
    expect(JSON.stringify(payload)).not.toContain('net_amount')
    expect(JSON.stringify(payload)).not.toContain('average_earnings')
  })
})
