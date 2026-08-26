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
