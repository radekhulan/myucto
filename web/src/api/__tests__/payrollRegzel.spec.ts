import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { PayrollRegzelSnapshot } from '@/api/payroll'

const m = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  put: vi.fn(),
}))

vi.mock('@/api/client', () => ({
  api: {
    get: m.get,
    post: m.post,
    put: m.put,
  },
}))

import { payrollApi } from '@/api/payroll'

describe('payroll REGZEL API', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('posílá prostředí odděleně v historii, prepare i downloadu', async () => {
    m.get.mockResolvedValueOnce({ data: { items: [], summary: {}, period: '2026-08' } })
    await payrollApi.submissionOverview('production', '2026-08')
    expect(m.get).toHaveBeenCalledWith(
      '/payroll/submissions/overview',
      { params: { environment: 'production', period: '2026-08' } },
    )

    m.get.mockResolvedValueOnce({ data: { items: [] } })
    await payrollApi.regzelSnapshots('test')
    expect(m.get).toHaveBeenCalledWith(
      '/payroll/submissions/regzel/snapshots',
      { params: { environment: 'test' } },
    )

    m.post.mockResolvedValueOnce({ data: { snapshot: { id: 9 } } })
    await payrollApi.prepareRegzel({
      office_id: 42,
      environment: 'test',
      evidence_confirmed: true,
      idempotency_key: 'synthetic-key',
    })
    expect(m.post).toHaveBeenCalledWith(
      '/payroll/submissions/regzel/prepare',
      expect.objectContaining({
        office_id: 42,
        environment: 'test',
        evidence_confirmed: true,
      }),
    )

    const snapshot: PayrollRegzelSnapshot = {
      id: 9,
      environment: 'test',
      office_id: 42,
      document_type: 'REGZELDOPL25',
      interaction_code: 'supplemental_information',
      mapping_version: 'regzeldopl25-map-1',
      xsd_version: '1.2',
      source_snapshot_hash: 'a'.repeat(64),
      xml_sha256: 'b'.repeat(64),
      xml_byte_size: 123,
    }
    m.get.mockResolvedValueOnce({ data: new Blob(['synthetic']) })
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:synthetic-regzel')
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)

    await payrollApi.downloadRegzelSnapshot(snapshot)
    expect(m.get).toHaveBeenLastCalledWith(
      '/payroll/submissions/regzel/snapshots/9/xml',
      {
        params: { environment: 'test' },
        responseType: 'blob',
      },
    )
  })

  it('načte a stáhne oficiální přehled zdravotní pojišťovny podle revize', async () => {
    m.get.mockResolvedValueOnce({
      data: {
        items: [],
        electronic_submission: {
          direct_portal: {
            supported: false,
            reason_code: 'health_insurance_portal_transport_undocumented',
          },
          isds: {
            supported: true,
            requires_ready: true,
            requires_production_gate: true,
            requires_user_confirmation: true,
          },
        },
      },
    })
    await payrollApi.healthPaymentOverviews(18)
    expect(m.get).toHaveBeenCalledWith(
      '/payroll/submissions/health-overviews/18',
    )

    const overview = {
      revision_id: 18,
      insurer: { code: '111' },
      filename: 'zp-prehled-2026-08-111-revize-18.json',
    } as Parameters<typeof payrollApi.downloadHealthPaymentOverview>[0]
    m.get.mockResolvedValueOnce({
      data: new Blob(['%PDF-synthetic-health'], { type: 'application/pdf' }),
      headers: {
        'content-type': 'application/pdf',
        'content-disposition': 'attachment; filename="zp-prehled-2026-08-111-revize-18.pdf"',
      },
    })
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:synthetic-health')
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
    const click = vi.spyOn(HTMLAnchorElement.prototype, 'click')
      .mockImplementation(function (this: HTMLAnchorElement) {
        expect(this.download).toBe(
          'zp-prehled-2026-08-111-revize-18.pdf',
        )
      })

    await payrollApi.downloadHealthPaymentOverview(overview)
    expect(m.get).toHaveBeenLastCalledWith(
      '/payroll/submissions/health-overviews/18/111/download',
      { responseType: 'blob' },
    )
    expect(click).toHaveBeenCalledOnce()
  })

  it('načte a stáhne pouze interní PVPOJ kontrolní náhled', async () => {
    m.get.mockResolvedValueOnce({
      data: {
        revision_id: 18,
        workflow_status: 'preview_only',
        filename: 'jmhz-pvpoj-preview-2026-08-revize-18-uctarna-4.json',
        office: {
          office_id: 4,
          code: 'HLAVNI',
          name: 'Hlavni uctarna',
          social_security_variable_symbol: '1234567890',
          submittable: true,
        },
      },
    })
    const preview = await payrollApi.jmhzPvpojPreview(18)
    // Bez zvolené účtárny se nesmí poslat žádný parametr — jinak by
    // jednoúčtárenský běh mířil na jinou registraci, než ze které vznikl.
    expect(m.get).toHaveBeenCalledWith('/payroll/submissions/jmhz-pvpoj/18', undefined)

    m.get.mockResolvedValueOnce({ data: new Blob(['synthetic-pvpoj']) })
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:synthetic-pvpoj')
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)

    await payrollApi.downloadJmhzPvpojPreview(preview)
    expect(m.get).toHaveBeenLastCalledWith(
      '/payroll/submissions/jmhz-pvpoj/18/download',
      { responseType: 'blob', params: { office: 4 } },
    )
  })

  it('načte a potvrdí ordinary evidence s přesnými false fakty', async () => {
    const scopes = [
      { employee_id: 11, employment_id: 101, employee_name: 'Osoba A', confirmed: false },
      { employee_id: 12, employment_id: 102, employee_name: 'Osoba B', confirmed: true },
    ]
    m.get.mockResolvedValueOnce({ data: { scopes, evidences: [] } })
    expect(await payrollApi.jmhzOrdinaryEvidence(18)).toEqual({ scopes, evidences: [] })
    expect(m.get).toHaveBeenCalledWith(
      '/payroll/submissions/jmhz-ordinary-evidence/18',
    )

    m.post.mockResolvedValueOnce({ data: { id: 31, created: true } })
    await payrollApi.confirmJmhzOrdinaryEvidence(18, 101, 'synthetic-idempotency-key')
    expect(m.post).toHaveBeenCalledWith(
      '/payroll/submissions/jmhz-ordinary-evidence/18/101',
      {
        facts: {
          reportable_wage_deductions_recorded: false,
          employee_social_discount_claimed: false,
          specific_legal_fact_occurred: false,
          ozp_employment_support_claimed: false,
          deep_mining_work_occurred: false,
        },
        evidence_confirmed: true,
      },
      { headers: { 'Idempotency-Key': 'synthetic-idempotency-key' } },
    )
  })

  it('načte bezpečný detail posledního podání bez parametrů prostředí v URL', async () => {
    m.get.mockResolvedValueOnce({
      data: {
        submission: { id: 31 },
        parts: [],
        artifacts: [],
        receipts: [],
        issues: [],
      },
    })

    const detail = await payrollApi.submissionDetail(31)

    expect(detail.submission.id).toBe(31)
    expect(m.get).toHaveBeenCalledWith('/payroll/submissions/31')
  })

  it('stáhne artefakt podání přes session-only jednorázový token v hlavičce', async () => {
    m.post.mockResolvedValueOnce({
      data: {
        token: 'synthetic-opaque-token',
        expires_at: '2026-08-04T12:00:00+00:00',
      },
    })
    m.get.mockResolvedValueOnce({
      data: new Blob(['synthetic-artifact']),
      headers: {
        'content-disposition': 'attachment; filename="jmhz-synthetic.xml"',
      },
    })
    vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:synthetic-artifact')
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined)
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined)

    await payrollApi.downloadSubmissionArtifact(31, {
      id: 51,
      part_id: 41,
      artifact_kind: 'outbound_xml',
      direction: 'outbound',
      mime_type: 'application/xml',
      byte_size: 2048,
      xsd_version: '1.4.3.4',
      catalog_version: null,
      channel: 'manual_upload',
      created_at: '2026-09-01 08:01:00',
    })

    expect(m.post).toHaveBeenCalledWith(
      '/payroll/submissions/31/artifacts/51/download-grant',
    )
    expect(m.get).toHaveBeenCalledWith(
      '/payroll/submissions/31/artifacts/51/download',
      {
        responseType: 'blob',
        headers: {
          'X-Payroll-Download-Token': 'synthetic-opaque-token',
        },
      },
    )
  })

  it('dekóduje bezpečnou JSON chybu i při blob downloadu artefaktu', async () => {
    m.post.mockResolvedValueOnce({
      data: {
        token: 'synthetic-opaque-token',
        expires_at: '2026-08-04T12:00:00+00:00',
      },
    })
    const error = {
      response: {
        status: 404,
        data: new Blob([JSON.stringify({
          error: {
            code: 'payroll_artifact_not_found',
            message: 'Artefakt již není dostupný.',
          },
        })], { type: 'application/json' }),
      },
      message: 'Request failed with status code 404',
    }
    m.get.mockRejectedValueOnce(error)

    await expect(payrollApi.downloadSubmissionArtifact(31, {
      id: 51,
      part_id: 41,
      artifact_kind: 'outbound_xml',
      direction: 'outbound',
      mime_type: 'application/xml',
      byte_size: 2048,
      xsd_version: '1.4.3.4',
      catalog_version: null,
      channel: 'manual_upload',
      created_at: '2026-09-01 08:01:00',
    })).rejects.toMatchObject({
      response: {
        data: {
          error: {
            code: 'payroll_artifact_not_found',
            message: 'Artefakt již není dostupný.',
          },
        },
      },
    })
  })
})
