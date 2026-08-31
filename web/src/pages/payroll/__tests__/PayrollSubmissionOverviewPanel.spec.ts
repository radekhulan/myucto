import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { flushPromises, mount } from '@vue/test-utils'

const m = vi.hoisted(() => ({
  submissionOverview: vi.fn(),
  runs: vi.fn(),
  jmhzPvpojOffices: vi.fn(),
  jmhzPvpojPreview: vi.fn(),
  healthPaymentOverviews: vi.fn(),
  submissionDetail: vi.fn(),
  downloadSubmissionArtifact: vi.fn(),
  prepareHealthOverview: vi.fn(),
  enqueueHealthIsds: vi.fn(),
  gatewayStartPayroll: vi.fn(),
  mobileKeyProfile: vi.fn(),
  startMobileKeyOutbox: vi.fn(),
  mobileKeyOutboxConfirm: vi.fn(),
  startMobileKeyOutboxBatch: vi.fn(),
  mobileKeyOutboxConfirmBatch: vi.fn(),
}))
vi.mock('@/api/payrollHealthNotifications', () => ({
  payrollHealthNotificationApi: {
    preparePaymentOverview: m.prepareHealthOverview,
    enqueuePaymentOverviewIsds: m.enqueueHealthIsds,
  },
}))
vi.mock('@/api/dataBox', () => ({
  dataBoxApi: {
    gatewayStartPayroll: m.gatewayStartPayroll,
    mobileKeyProfile: m.mobileKeyProfile,
    startMobileKeyOutbox: m.startMobileKeyOutbox,
    mobileKeyOutboxConfirm: m.mobileKeyOutboxConfirm,
    startMobileKeyOutboxBatch: m.startMobileKeyOutboxBatch,
    mobileKeyOutboxConfirmBatch: m.mobileKeyOutboxConfirmBatch,
  },
}))

vi.mock('@/api/payroll', () => ({
  payrollApi: {
    submissionOverview: m.submissionOverview,
    runs: m.runs,
    jmhzPvpojOffices: m.jmhzPvpojOffices,
    jmhzPvpojPreview: m.jmhzPvpojPreview,
    healthPaymentOverviews: m.healthPaymentOverviews,
    submissionDetail: m.submissionDetail,
    downloadSubmissionArtifact: m.downloadSubmissionArtifact,
  },
}))
vi.mock('@/stores/auth', () => ({
  useAuthStore: () => ({ canWrite: () => true }),
}))
vi.mock('@/composables/useToast', () => ({
  useToast: () => ({ success: vi.fn(), error: vi.fn() }),
}))
// `useFormat` (sdílené formátování) táhne @/i18n, které volá skutečné
// `createI18n` — továrna proto musí původní modul rozprostřít, ne nahradit.
vi.mock('vue-i18n', async (importOriginal) => ({
  ...(await importOriginal<typeof import('vue-i18n')>()),
  useI18n: () => ({
    t: (key: string) => key,
    te: () => true,
    locale: { value: 'cs' },
  }),
}))

// `useTablePrefs` jde přes Pinii a API; v testu stačí prázdné výchozí předvolby.
vi.mock('@/composables/useUserPrefs', async () => {
  const { computed } = await import('vue')
  return {
    ensurePrefsLoaded: () => Promise.resolve(),
    getPagePrefs: () => computed(() => ({})),
    patchPagePrefs: () => {},
  }
})

import PayrollSubmissionOverviewPanel from '@/pages/payroll/PayrollSubmissionOverviewPanel.vue'

/**
 * Regrese: období se odvozovalo přes `new Date().toISOString().slice(0, 7)`.
 * `toISOString()` je UTC, takže v pásmu s kladným posunem (CET/CEST) vracelo
 * mezi půlnocí a ránem prvního dne v měsíci ještě měsíc PŘEDCHOZÍ — účetní
 * ráno prvního otevřela podání a viděla období, které už uzavřela.
 */
describe('PayrollSubmissionOverviewPanel — odvození období', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    m.submissionOverview.mockResolvedValue({
      items: [],
      total: 0,
      deadline_summary: {
        not_open: 0,
        open: 0,
        due_soon: 0,
        due_today: 0,
        overdue: 0,
        awaiting_result: 0,
        fulfilled: 0,
        action_required: 0,
        cancelled: 0,
      },
    })
    m.runs.mockResolvedValue([])
    m.jmhzPvpojOffices.mockResolvedValue([])
    m.mobileKeyProfile.mockResolvedValue({ saved: false, username: null, environment: 'production' })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('vrátí ve 00:30 prvního dne v měsíci aktuální měsíc, ne předchozí', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2026, 7, 1, 0, 30))

    const wrapper = mount(PayrollSubmissionOverviewPanel, {
      props: { mode: 'jmhz' },
      // Podřízené panely mají vlastní testy; tady jen zavazí.
      global: { stubs: { PayrollJmhzOrdinaryEvidencePanel: true, PayrollJmhzXmlDryRunPanel: true, PayrollJmhzDispatchPanel: true } },
    })
    await flushPromises()

    const period = wrapper.get('[data-test="submission-overview-period"]')
      .element as HTMLInputElement
    expect(period.value).toBe('2026-08')
    expect(period.value).not.toBe('2026-07')
  })

  it('drží místní datum i o půlnoci na Nový rok', async () => {
    vi.useFakeTimers()
    vi.setSystemTime(new Date(2027, 0, 1, 0, 5))

    const wrapper = mount(PayrollSubmissionOverviewPanel, {
      props: { mode: 'jmhz' },
      // Podřízené panely mají vlastní testy; tady jen zavazí.
      global: { stubs: { PayrollJmhzOrdinaryEvidencePanel: true, PayrollJmhzXmlDryRunPanel: true, PayrollJmhzDispatchPanel: true } },
    })
    await flushPromises()

    const period = wrapper.get('[data-test="submission-overview-period"]')
      .element as HTMLInputElement
    expect(period.value).toBe('2027-01')
  })

  it('pro VZP připraví a stáhne PDF artefakt místo interního JSON', async () => {
    m.runs.mockResolvedValue([{
      revision_status: 'approved',
      revision_id: 12,
    }])
    m.healthPaymentOverviews.mockResolvedValue({
      items: [{
        schema_reference: 'payroll-health-payment-overview.v1',
        document_kind: 'internal_health_payment_overview',
        official_submission: { supported: false, reason_code: 'internal' },
        supplier_id: 1,
        run_id: 1,
        revision_id: 12,
        revision_no: 3,
        period: '2026-08',
        currency_code: 'CZK',
        insurer: { code: '111' },
        source: { statutory_result_id: 1, statutory_result_hash: 'a', ruleset_id: 'r', ruleset_hash: 'h' },
        totals: {
          person_count: 2,
          assessment_base_minor_units: 5700000,
          employee_contribution_minor_units: 256500,
          employer_contribution_minor_units: 756000,
          total_contribution_minor_units: 1012500,
        },
        people: [],
        sha256: 'old-json',
        filename: 'internal.json',
      }],
    })
    m.prepareHealthOverview.mockResolvedValue({
      submission_id: 57,
      obligation_id: 70,
      artifact_id: 80,
      pdf_artifact_id: 81,
      status: 'ready',
      row_version: 4,
      insurer_code: '111',
      period: '2026-08',
      agenda_code: 'PPZ',
      artifact_sha256: 'xml',
      pdf_artifact_sha256: 'pdf',
      created: true,
      deadline: {},
      schema_validated: true,
      dispatch: {
        supported: false,
        reason_code: 'zp_transport_envelope_undocumented',
        reason: 'Ruční potvrzení.',
        channel: { insurer_code: '111', isds_attachment_format: 'text_pdf' },
      },
    })
    const pdfArtifact = { id: 81, mime_type: 'application/pdf' }
    m.submissionDetail.mockResolvedValue({ submission: { id: 57 }, artifacts: [pdfArtifact] })

    const wrapper = mount(PayrollSubmissionOverviewPanel, { props: { mode: 'health' } })
    await flushPromises()
    await wrapper.get('[data-test="health-overview-download"]').trigger('click')
    await flushPromises()

    expect(m.prepareHealthOverview).toHaveBeenCalledWith(12, '111', 'production')
    expect(m.downloadSubmissionArtifact).toHaveBeenCalledWith(57, pdfArtifact)
    expect(m.downloadSubmissionArtifact).not.toHaveBeenCalledWith(expect.anything(), expect.objectContaining({ mime_type: 'application/json' }))
  })

  /**
   * Kanál `mobile_key` musí nabídnout tlačítko rovnou na kartě — ne jen
   * přesměrovat do odchozí fronty, kde by účetní musela hledat, co dál.
   */
  it('u kanálu mobile_key nabídne odeslání rovnou na kartě přehledu', async () => {
    m.runs.mockResolvedValue([{ revision_status: 'approved', revision_id: 12 }])
    m.healthPaymentOverviews.mockResolvedValue({
      items: [{
        schema_reference: 'payroll-health-payment-overview.v1',
        document_kind: 'internal_health_payment_overview',
        official_submission: { supported: false, reason_code: 'internal' },
        supplier_id: 1,
        run_id: 1,
        revision_id: 12,
        revision_no: 3,
        period: '2026-08',
        currency_code: 'CZK',
        insurer: { code: '205' },
        source: { statutory_result_id: 1, statutory_result_hash: 'a', ruleset_id: 'r', ruleset_hash: 'h' },
        totals: {
          person_count: 2,
          assessment_base_minor_units: 5700000,
          employee_contribution_minor_units: 256500,
          employer_contribution_minor_units: 756000,
          total_contribution_minor_units: 1012500,
        },
        people: [],
        sha256: 'xml-sha',
        filename: 'ppz.xml',
      }],
    })
    m.prepareHealthOverview.mockResolvedValue({
      submission_id: 57,
      obligation_id: 70,
      artifact_id: 80,
      status: 'ready',
      row_version: 4,
      insurer_code: '205',
      period: '2026-08',
      agenda_code: 'PPZ',
      artifact_sha256: 'xml',
      created: true,
      deadline: {},
      schema_validated: true,
      dispatch: {
        supported: false,
        reason_code: 'zp_portal_gateway_description_on_request',
        reason: 'Ruční potvrzení.',
        channel: { insurer_code: '205', isds_attachment_format: 'xml' },
      },
    })
    m.enqueueHealthIsds.mockResolvedValue({
      outbox_id: 91,
      created: true,
      recipient: { box_id: 'mk5ab8i', name: 'ČPZP (205)' },
      subject: 'PPPZ 2026-08 — zdravotní pojišťovna 205',
      attachment: { filename: 'ppz.xml', mime: 'application/xml', sha256: 'xml', bytes: 500, format: 'xml' },
      transport: { automatic: false, channel: 'mobile_key', reason: null },
      outbox_url: '/admin/databox?tab=outbox',
    })
    m.startMobileKeyOutbox.mockResolvedValue({
      flow_token: 'flow-1',
      state: 1,
      description: 'Čeká se na potvrzení.',
      expires_at: '2026-08-25T15:00:00Z',
    })
    m.mobileKeyOutboxConfirm.mockResolvedValue({
      state: 2,
      description: 'Potvrzeno.',
      result: { row: { id: 91 }, dispatched: true },
    })

    const wrapper = mount(PayrollSubmissionOverviewPanel, { props: { mode: 'health' } })
    await flushPromises()
    await wrapper.get('[data-test="health-overview-send-isds"]').trigger('click')
    await flushPromises()

    // Kanál mobile_key nabídne přímo tlačítko na kartě, žádné přesměrování.
    expect(wrapper.find('[data-test="health-overview-send-isds"]').exists()).toBe(false)
    const sendButton = wrapper.get('[data-test="mobile-key-send-action"]')

    await sendButton.trigger('click')
    await flushPromises()
    const form = wrapper.get('[data-test="mobile-key-send-form"]')
    await form.find('input[type="text"]').setValue('jan.novak')
    await form.find('input[type="password"]').setValue('kod123')
    await wrapper.get('[data-test="mobile-key-send-request"]').trigger('click')
    await flushPromises()

    expect(m.startMobileKeyOutbox).toHaveBeenCalledWith(91, 'production', 'jan.novak', 'kod123', false)
    expect(wrapper.get('[data-test="health-overview-mobile-key-sent"]').text())
      .toContain('databox.outbox.mobileKey.sent')
  })

  /**
   * Jedno potvrzení v mobilu pro víc vybraných přehledů — bez toho by účetní
   * musela potvrzovat zvlášť pro každou pojišťovnu.
   */
  it('u dvou a víc karet nabídne výběr a hromadné odeslání jedním potvrzením', async () => {
    m.runs.mockResolvedValue([{ revision_status: 'approved', revision_id: 12 }])
    const overviewFixture = (insurer: string) => ({
      schema_reference: 'payroll-health-payment-overview.v1',
      document_kind: 'internal_health_payment_overview',
      official_submission: { supported: false, reason_code: 'internal' },
      supplier_id: 1,
      run_id: 1,
      revision_id: 12,
      revision_no: 3,
      period: '2026-08',
      currency_code: 'CZK',
      insurer: { code: insurer },
      source: { statutory_result_id: 1, statutory_result_hash: 'a', ruleset_id: 'r', ruleset_hash: 'h' },
      totals: {
        person_count: 2,
        assessment_base_minor_units: 5700000,
        employee_contribution_minor_units: 256500,
        employer_contribution_minor_units: 756000,
        total_contribution_minor_units: 1012500,
      },
      people: [],
      sha256: `xml-sha-${insurer}`,
      filename: 'ppz.xml',
    })
    m.healthPaymentOverviews.mockResolvedValue({
      items: [overviewFixture('205'), overviewFixture('207')],
    })
    m.prepareHealthOverview.mockImplementation((_revisionId: number, insurerCode: string) => Promise.resolve({
      submission_id: insurerCode === '205' ? 57 : 58,
      obligation_id: 70,
      artifact_id: 80,
      status: 'ready',
      row_version: 4,
      insurer_code: insurerCode,
      period: '2026-08',
      agenda_code: 'PPZ',
      artifact_sha256: 'xml',
      created: true,
      deadline: {},
      schema_validated: true,
      dispatch: {
        supported: false,
        reason_code: 'zp_portal_gateway_description_on_request',
        reason: 'Ruční potvrzení.',
        channel: { insurer_code: insurerCode, isds_attachment_format: 'xml' },
      },
    }))
    m.enqueueHealthIsds.mockImplementation((submissionId: number, insurerCode: string) => Promise.resolve({
      outbox_id: submissionId + 1000,
      created: true,
      recipient: { box_id: 'mk5ab8i', name: `Pojišťovna (${insurerCode})` },
      subject: `PPPZ 2026-08 — zdravotní pojišťovna ${insurerCode}`,
      attachment: { filename: 'ppz.xml', mime: 'application/xml', sha256: 'xml', bytes: 500, format: 'xml' },
      transport: { automatic: false, channel: 'mobile_key', reason: null },
      outbox_url: '/admin/databox?tab=outbox',
    }))
    m.startMobileKeyOutboxBatch.mockResolvedValue({
      flow_token: 'batch-flow-1',
      state: 1,
      description: 'Čeká se na potvrzení.',
      expires_at: '2026-08-25T15:00:00Z',
    })
    m.mobileKeyOutboxConfirmBatch.mockResolvedValue({
      state: 2,
      description: 'Potvrzeno.',
      results: [
        { id: 1057, dispatched: true, row: { id: 1057 }, error_code: null, error_message: null },
        { id: 1058, dispatched: true, row: { id: 1058 }, error_code: null, error_message: null },
      ],
    })

    const wrapper = mount(PayrollSubmissionOverviewPanel, { props: { mode: 'health' } })
    await flushPromises()

    const checkboxes = wrapper.findAll('[data-test="health-overview-select"]')
    expect(checkboxes).toHaveLength(2)
    await checkboxes[0]!.setValue(true)
    await checkboxes[1]!.setValue(true)
    expect(wrapper.get('[data-test="health-batch-toolbar"]').text())
      .toContain('payroll.submissions.overview.mobile_key_batch.selected')

    await wrapper.get('[data-test="health-batch-send"]').trigger('click')
    await flushPromises()

    expect(m.enqueueHealthIsds).toHaveBeenCalledTimes(2)
    expect(m.enqueueHealthIsds).toHaveBeenCalledWith(57, '205')
    expect(m.enqueueHealthIsds).toHaveBeenCalledWith(58, '207')

    const batchButton = wrapper.get('[data-test="mobile-key-batch-send-action"]')
    await batchButton.trigger('click')
    await flushPromises()
    const form = wrapper.get('[data-test="mobile-key-batch-send-form"]')
    await form.find('input[type="text"]').setValue('jan.novak')
    await form.find('input[type="password"]').setValue('kod123')
    await wrapper.get('[data-test="mobile-key-batch-send-request"]').trigger('click')
    await flushPromises()

    expect(m.mobileKeyOutboxConfirmBatch).toHaveBeenCalledWith([1057, 1058], 'batch-flow-1', 'production')
    expect(wrapper.get('[data-test="health-batch-sent-result"]').text())
      .toContain('payroll.submissions.overview.mobile_key_batch.sent_summary')
  })

  it('ukáže v detailu lidské stavy a technický kód problému oddělí od hlavní zprávy', async () => {
    m.submissionOverview.mockResolvedValue({
      items: [{
        id: 7,
        environment: 'test',
        agenda_code: 'SYNTH',
        agenda_group: 'other',
        subject_type: 'employer',
        subject_reference: 'Syntetický zaměstnavatel',
        period_start: '2026-08-01',
        period_end: '2026-08-31',
        obligation_kind: 'monthly',
        preferred_channel: 'isds',
        status: 'manual_review',
        row_version: 1,
        earliest_submission_on: '2026-08-01',
        due_on: '2026-09-20',
        calendar_basis: 'calendar_days',
        deadline: {
          phase: 'action_required',
          days_to_due: 25,
          is_action_required: true,
          is_overdue: false,
        },
        latest_submission: {
          id: 56,
          status: 'correction_required',
          submission_kind: 'correction',
          channel: 'isds',
          submitted_at: null,
          decided_at: null,
        },
      }],
      total: 1,
      deadline_summary: {
        not_open: 0,
        open: 0,
        due_soon: 0,
        due_today: 0,
        overdue: 0,
        awaiting_result: 0,
        fulfilled: 0,
        action_required: 1,
        cancelled: 0,
      },
    })
    m.submissionDetail.mockResolvedValue({
      submission: {
        id: 56,
        environment: 'test',
        obligation_id: 7,
        agenda_code: 'SYNTH',
        subject_type: 'employer',
        subject_reference: 'Syntetický zaměstnavatel',
        period_start: '2026-08-01',
        period_end: '2026-08-31',
        submission_kind: 'correction',
        channel: 'isds',
        status: 'correction_required',
        row_version: 1,
        source_revision_id: 10,
        corrects_submission_id: 55,
        correlation_reference: null,
        submitted_at: null,
        decided_at: null,
        created_at: '2026-08-26 09:00:00',
        updated_at: '2026-08-26 09:00:00',
      },
      parts: [],
      artifacts: [{
        id: 9,
        part_id: null,
        artifact_kind: 'outbound_xml',
        direction: 'outbound',
        mime_type: 'application/xml',
        byte_size: 128,
        xsd_version: '1.0',
        catalog_version: null,
        channel: 'isds',
        created_at: '2026-08-26 09:00:00',
      }],
      issues: [{
        id: 11,
        part_id: null,
        severity: 'blocker',
        validation_stage: 'xsd',
        issue_code: 'zp_xsd_validation_failed',
        entity_type: null,
        entity_reference: null,
        is_resolved: false,
        row_version: 1,
        resolved_at: null,
        created_at: '2026-08-26 09:00:00',
        updated_at: '2026-08-26 09:00:00',
      }],
      receipts: [{
        id: 12,
        part_id: null,
        artifact_id: 9,
        receipt_reference: 'SYNTH-RECEIPT',
        correlation_reference: null,
        protocol_code: 'SYNTH-PROTOCOL',
        remote_status: 'correction_required',
        verification_status: 'unverified',
        received_at: '2026-08-26 09:01:00',
        created_at: '2026-08-26 09:01:00',
      }],
    })

    const wrapper = mount(PayrollSubmissionOverviewPanel, {
      props: { mode: 'other' },
    })
    await flushPromises()
    await wrapper.findAll('[data-test="submission-detail-open"]')[0]!.trigger('click')
    await flushPromises()

    const detail = wrapper.get('[data-test="submission-detail"]')
    expect(detail.text()).toContain('payroll.submissions.overview.submission_kind.correction')
    expect(detail.text()).toContain('payroll.submissions.overview.artifact_kind.outbound_xml')
    expect(detail.text()).toContain('payroll.submissions.overview.issue_severity.blocker')
    expect(detail.text()).toContain('payroll.submissions.overview.validation_stage.xsd')
    expect(detail.text()).toContain('payroll.submissions.overview.verification_status.unverified')
    expect(detail.text()).toContain('payroll.submissions.overview.status.correction_required')
    expect(detail.get('[data-test="submission-issue-message"]').text())
      .toBe('payroll.submissions.overview.issue_message.zp_xsd_validation_failed')
    expect(detail.get('[data-test="submission-issue-remediation"]').text())
      .toBe('payroll.submissions.overview.issue_remediation.xsd')
    expect(detail.get('[data-test="submission-issue-technical"]').text())
      .toContain('zp_xsd_validation_failed')
  })
})
