import { describe, expect, it, vi } from 'vitest'

vi.mock('vue-i18n', () => ({
  useI18n: () => ({
    t: (key: string) => key,
    te: () => false,
  }),
}))

import { usePayrollLabels } from '@/composables/usePayrollLabels'

describe('usePayrollLabels', () => {
  it('neukáže neznámé interní hodnoty jako uživatelský text', () => {
    const labels = usePayrollLabels()

    expect(labels.submissionStatusLabel('future_status'))
      .toBe('payroll.submissions.overview.status.unknown')
    expect(labels.submissionChannelLabel('future_channel'))
      .toBe('payroll.submissions.overview.channel.unknown')
    expect(labels.submissionKindLabel('future_kind'))
      .toBe('payroll.submissions.overview.submission_kind.unknown')
    expect(labels.artifactKindLabel('future_artifact'))
      .toBe('payroll.submissions.overview.artifact_kind.unknown')
    expect(labels.artifactKindLabel('outbound_pdf'))
      .toBe('payroll.submissions.overview.artifact_kind.outbound_pdf')
    expect(labels.issueSeverityLabel('future_severity'))
      .toBe('payroll.submissions.overview.issue_severity.unknown')
    expect(labels.validationStageLabel('future_stage'))
      .toBe('payroll.submissions.overview.validation_stage.unknown')
    expect(labels.verificationStatusLabel('future_verification'))
      .toBe('payroll.submissions.overview.verification_status.unknown')
    expect(labels.submissionIssueMessage('future_issue'))
      .toBe('payroll.submissions.overview.issue_message.unknown')
    expect(labels.submissionIssueMessage('zp_xsd_validation_failed'))
      .toBe('payroll.submissions.overview.issue_message.zp_xsd_validation_failed')
    expect(labels.submissionIssueRemediation('future_stage'))
      .toBe('payroll.submissions.overview.issue_remediation.unknown')
    expect(labels.employmentExitReadinessLabel('future_readiness'))
      .toBe('payroll.people.exit_documents.blockers.unknown')
  })
})
