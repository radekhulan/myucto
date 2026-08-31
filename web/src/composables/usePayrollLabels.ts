import { useI18n } from 'vue-i18n'

const SUBMISSION_STATUSES = new Set([
  'open',
  'draft',
  'validated',
  'prepared',
  'ready',
  'submitted',
  'processing',
  'accepted',
  'partially_accepted',
  'rejected',
  'waiting_for_identity',
  'correction_required',
  'superseded',
  'cancelled_in_time',
  'fulfilled',
  'overdue',
  'cancelled',
  'manual_review',
])

const SUBMISSION_CHANNELS = new Set([
  'manual_upload',
  'isds',
  'vrep_apep',
  'pikr',
  'health_portal',
  'other',
])

const SUBMISSION_KINDS = new Set(['regular', 'correction', 'cancellation'])
const ARTIFACT_KINDS = new Set([
  'outbound_xml',
  'outbound_pdf',
  'outbound_zip',
  'validation_protocol',
  'receipt_original',
  'receipt_parsed',
  'manual_attachment',
])
const ISSUE_SEVERITIES = new Set(['blocker', 'error', 'warning', 'info'])
const VALIDATION_STAGES = new Set(['source', 'xsd', 'catalog', 'transport', 'remote'])
const VERIFICATION_STATUSES = new Set(['unverified', 'trusted'])
const SUBMISSION_ISSUE_CODES = new Set([
  'receipt_unverified',
  'jmhz_protocol_untrusted',
  'jmhz_xsd_validation_failed',
  'zp_xsd_validation_failed',
  'eldp_xsd_validation_failed',
  'ozuspoj_xsd_validation_failed',
  'regzel_xsd_validation_failed',
  'registration_xsd_validation_failed',
])

/**
 * Kódy agend, které zná katalog dalších povinností
 * ({@see \MyInvoice\Service\Payroll\Submission\PayrollStatutoryAgendaCatalog})
 * a jeho záložka — sdílejí s ním stejné znění (`payroll.submissions.statutory.agenda.*`),
 * ať se v appce neříká totéž dvakrát jinak.
 */
const STATUTORY_CATALOG_AGENDA_CODES = new Set([
  'NEMPRI', 'HZUPN', 'ELDP', 'JMHZ25', 'OZUSPOJ', 'REGZELDOPL25', 'STATUTORY_ACCIDENT_INSURANCE',
])
/** Zbylé agendové kódy, které katalog dalších povinností nezná — vlastní slovník měsíčního přehledu. */
const MONTHLY_CHECKLIST_AGENDA_CODES = new Set([
  'PREZEC26', 'REGZEC25', 'REGZEL26', 'HOZ_2026', 'PPZ_2026',
])

export function usePayrollLabels() {
  const { t, te } = useI18n()

  function knownLabel(
    prefix: string,
    value: string | null | undefined,
    known: Set<string>,
    fallbackKey: string,
  ): string {
    return value && known.has(value)
      ? t(`${prefix}.${value}`)
      : t(fallbackKey)
  }

  function submissionStatusLabel(status: string | null | undefined): string {
    return knownLabel(
      'payroll.submissions.overview.status',
      status,
      SUBMISSION_STATUSES,
      'payroll.submissions.overview.status.unknown',
    )
  }

  function submissionChannelLabel(channel: string | null | undefined): string {
    return knownLabel(
      'payroll.submissions.overview.channel',
      channel,
      SUBMISSION_CHANNELS,
      'payroll.submissions.overview.channel.unknown',
    )
  }

  function submissionKindLabel(kind: string | null | undefined): string {
    return knownLabel(
      'payroll.submissions.overview.submission_kind',
      kind,
      SUBMISSION_KINDS,
      'payroll.submissions.overview.submission_kind.unknown',
    )
  }

  function artifactKindLabel(kind: string | null | undefined): string {
    return knownLabel(
      'payroll.submissions.overview.artifact_kind',
      kind,
      ARTIFACT_KINDS,
      'payroll.submissions.overview.artifact_kind.unknown',
    )
  }

  function issueSeverityLabel(severity: string | null | undefined): string {
    return knownLabel(
      'payroll.submissions.overview.issue_severity',
      severity,
      ISSUE_SEVERITIES,
      'payroll.submissions.overview.issue_severity.unknown',
    )
  }

  function validationStageLabel(stage: string | null | undefined): string {
    return knownLabel(
      'payroll.submissions.overview.validation_stage',
      stage,
      VALIDATION_STAGES,
      'payroll.submissions.overview.validation_stage.unknown',
    )
  }

  function verificationStatusLabel(status: string | null | undefined): string {
    return knownLabel(
      'payroll.submissions.overview.verification_status',
      status,
      VERIFICATION_STATUSES,
      'payroll.submissions.overview.verification_status.unknown',
    )
  }

  function submissionIssueMessage(issueCode: string | null | undefined): string {
    return knownLabel(
      'payroll.submissions.overview.issue_message',
      issueCode,
      SUBMISSION_ISSUE_CODES,
      'payroll.submissions.overview.issue_message.unknown',
    )
  }

  /**
   * Lidský název agendové povinnosti z jejího `agenda_code` — kód sám o sobě
   * (`JMHZ25`, `HOZ_2026`, `social_jmhz_change`, …) nic účetní neřekne.
   * Jedna cesta pro měsíční přehled, inbox, přehled podání i záložku Dalších
   * povinností: kód, který zná katalog dalších povinností, dostane STEJNÉ
   * znění jako jeho vlastní záložka; zbytek jde přes vlastní slovník
   * měsíčního přehledu (`payroll.submissions.monthly_checklist.agenda.*`).
   * Neznámý kód padá na poctivé „neznámo", ne na syrový i18n klíč.
   */
  function submissionAgendaLabel(agendaCode: string | null | undefined): string {
    if (agendaCode && STATUTORY_CATALOG_AGENDA_CODES.has(agendaCode)) {
      return t(`payroll.submissions.statutory.agenda.${agendaCode}`)
    }
    return knownLabel(
      'payroll.submissions.monthly_checklist.agenda',
      agendaCode,
      MONTHLY_CHECKLIST_AGENDA_CODES,
      'payroll.submissions.monthly_checklist.unknown',
    )
  }

  function submissionIssueRemediation(stage: string | null | undefined): string {
    return knownLabel(
      'payroll.submissions.overview.issue_remediation',
      stage,
      VALIDATION_STAGES,
      'payroll.submissions.overview.issue_remediation.unknown',
    )
  }

  function employmentExitReadinessLabel(
    code: string | null | undefined,
    params: Record<string, unknown> = {},
  ): string {
    if (!code) return t('payroll.people.exit_documents.ready')
    const key = `payroll.people.exit_documents.blockers.${code}`
    return te(key)
      ? t(key, params)
      : t('payroll.people.exit_documents.blockers.unknown')
  }

  return {
    artifactKindLabel,
    employmentExitReadinessLabel,
    issueSeverityLabel,
    submissionAgendaLabel,
    submissionChannelLabel,
    submissionKindLabel,
    submissionIssueMessage,
    submissionIssueRemediation,
    submissionStatusLabel,
    validationStageLabel,
    verificationStatusLabel,
  }
}
