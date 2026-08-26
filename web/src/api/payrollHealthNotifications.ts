import { api } from './client'

// ── MZ-23 — podání zdravotním pojišťovnám ───────────────────────────────────
// Modul mluví o dvou různých věcech a nesmí je slít:
//
// * **HOZ** (hromadné oznámení zaměstnavatele) — oznamovací povinnost z § 10
//   zákona č. 48/1997 Sb. Aplikace ji umí VYHODNOTIT a připomenout lhůtu.
// * **PPZ** (přehled o platbě pojistného) — měsíční přehled podle § 25 odst. 3
//   zákona č. 592/1992 Sb. Ten aplikace umí i SESTAVIT do odesílatelné podoby
//   a vydat jako XML ověřené proti připnutému XSD.
//
// Portálové API se bez doložené obálky nevolá. Validované XML lze u pojišťovny
// s doloženou schránkou připravit do obecné ISDS fronty; odeslání vždy
// potvrzuje uživatel.

/** Proč aplikace nesmí odeslat sama. Nikdy to není prázdno — vždy je to kód. */
export type HealthDispatchReasonCode =
  | 'zp_transport_envelope_undocumented'
  | 'zp_portal_gateway_description_on_request'
  | 'zp_b2b_interface_not_published'
  | 'zp_shared_data_message_acceptance_unconfirmed'

export type HealthDutyKind =
  | 'employment_start'
  | 'employment_end'
  | 'employee_data_change'
  | 'insurer_change'
  | 'maternity_leave_start'
  | 'parental_leave_start'
  | 'maternity_or_parental_leave_end'
  | 'state_category_other'

/** Jak je pravidlo doložené. `external_unverified` = ne textem zákona. */
export type HealthSourceStatus = 'statute_verified' | 'external_unverified'
export type HealthIsdsAttachmentFormat = 'xml' | 'text_pdf' | 'none'

export interface HealthInsurerChannel {
  insurer_code: string
  insurer_name: string | null
  kind?: string
  data_box_id?: string | null
  business_id?: string | null
  address?: string | null
  recipient_source?: 'system' | 'company' | 'missing'
  portal_url?: string | null
  isds_attachment_format: HealthIsdsAttachmentFormat
  isds_attachment_rules: Array<{
    from: string
    to: string | null
    format: HealthIsdsAttachmentFormat
  }>
  /** Zpětně kompatibilní odvození: true právě při aktuálním formátu XML. */
  accepts_shared_data_message?: boolean
  automated_dispatch_documented?: boolean
  undocumented_reason_code: HealthDispatchReasonCode | string
  note: string
}

export interface HealthDutyRule {
  kind: HealthDutyKind
  label: string
  employer_reports: boolean
  effective_from: string
  effective_to: string | null
  act: string
  section: string | null
  source: string
  source_status: HealthSourceStatus
  verified_on: string
  note: string
}

export interface HealthDeadlineWindow {
  earliest_submission_on: string
  due_on: string
  calendar_basis: string
  ruleset_id: string
  ruleset_hash: string
  source: string
  source_status: HealthSourceStatus
}

/**
 * Kód změny datové věty pro daný druh povinnosti.
 *
 * `documented: false` NENÍ chyba načtení — u tří druhů povinnosti (změna
 * údajů, přestup mezi pojišťovnami, ostatní státní skutečnosti) schéma jediný
 * kód neurčuje a `reason` říká proč. Odhadnout ho by znamenalo podat větu
 * s kódem, který neplyne z ničeho.
 */
export interface HealthChangeCode {
  documented: boolean
  code: string | null
  reason: string | null
}

export interface HealthDispatchDescription {
  supported: false
  reason_code: HealthDispatchReasonCode | string
  reason: string
  channel: HealthInsurerChannel
}

export interface HealthDutyItem {
  id: string
  obligation_id: number | null
  employment_id: number
  employee_id: number
  full_name: string
  kind: HealthDutyKind
  label: string
  insurer_code: string
  occurred_on: string
  reported_by_employer: boolean
  rule: HealthDutyRule
  /** `null` u povinnosti, kterou zaměstnavatel nehlásí — lhůta mu neběží. */
  deadline: HealthDeadlineWindow | null
  change_code: HealthChangeCode
  channel: HealthInsurerChannel
  dispatch: HealthDispatchDescription
}

/**
 * Vztah, u kterého povinnost odvodit NELZE.
 *
 * Nevypouští se z odpovědi: chybějící pojišťovna znamená, že oznámení nemá
 * komu odejít, a to je vada k opravě, ne prázdno v seznamu.
 */
export interface HealthUnresolvedEmployment {
  employment_id: number
  full_name: string
  reason_code: string
  reason: string
}

export interface HealthDutySummary {
  total: number
  reported_by_employer: number
  reported_by_insured: number
  code_documented: number
  code_undocumented: number
  overdue: number
}

export interface HealthDutyPage {
  period: string
  environment: string
  items: HealthDutyItem[]
  total: number
  limit: number
  offset: number
  summary: HealthDutySummary
  unresolved_employments: HealthUnresolvedEmployment[]
}

export interface HealthDutyFilters {
  insurer_code?: string | null
  kind?: HealthDutyKind | null
  reported?: boolean | null
  undocumented_code_only?: boolean
  limit?: number
  offset?: number
}

export interface HealthRegisteredObligation {
  duty_id: string
  duty: {
    kind: HealthDutyKind
    employment_id: number
    employee_id: number
    insurer_code: string
    occurred_on: string
    reported_by_employer: boolean
  }
  obligation_id: number | null
  created: boolean
  skipped_reason_code: string | null
}

export interface HealthPeriodObligationSync {
  items: Array<{
    duty_id: string
    obligation_id: number
    created: boolean
  }>
  total: number
  created: number
}

export interface HealthSchemaDocument {
  xsd_version: string
  namespace: string
  root: string
  schema_pinned: boolean
  schema_sha256: string | null
  schema_url: string | null
}

export interface HealthCapability {
  schema_reference: string
  shared_data_message_since: string
  documents: Record<string, HealthSchemaDocument>
  channels: Record<string, HealthInsurerChannel>
  automated_dispatch: { supported: false; reason_code: string }
  isds_dispatch: {
    supported: true
    requires_user_confirmation: true
    automatic_inbox: false
  }
  change_codes: {
    total: number
    narrowing_effective_from: string
    mapping_from_duty_documented: HealthDutyKind[]
  }
  duties: HealthDutyRule[]
  verification_reference: string
}

/** Výsledek sestavení přehledu o platbě. `schema_validated:false` = výhrada. */
export interface HealthPreparedOverview {
  submission_id: number
  obligation_id: number
  part_id?: number
  artifact_id?: number
  pdf_artifact_id?: number
  status: string
  row_version: number
  insurer_code: string
  period: string
  agenda_code: string
  artifact_sha256: string
  pdf_artifact_sha256?: string
  created: boolean
  deadline: HealthDeadlineWindow
  /**
   * `false` = artefakt vznikl, ale zůstal v `draft` s blokující výhradou ve
   * fázi `xsd`. Soubor tedy JE, jen není připravený k odeslání — proto se
   * stahování nesmí schovávat za tuhle vlajku.
   */
  schema_validated: boolean
  dispatch: HealthDispatchDescription
}

export interface HealthIsdsEnqueueResult {
  outbox_id: number
  created: boolean
  recipient: { box_id: string; name: string }
  subject: string
  attachment: {
    filename: string
    mime: string
    sha256: string
    bytes: number
    format: HealthIsdsAttachmentFormat
  }
  transport: {
    automatic: boolean
    channel: 'gateway' | 'manual_upload'
    reason: string | null
  }
  outbox_url: string
}

export const payrollHealthNotificationApi = {
  capability: () =>
    api.get<HealthCapability>('/payroll/submissions/health-notifications/capability')
      .then(response => response.data),

  /** Přehled povinností za období. Filtr i stránka jdou na SERVER. */
  duties: (period: string, filters: HealthDutyFilters = {}) =>
    api.get<HealthDutyPage>('/payroll/submissions/health-notifications/duties', {
      params: {
        period,
        insurer_code: filters.insurer_code || undefined,
        kind: filters.kind || undefined,
        reported: filters.reported ?? undefined,
        undocumented_code_only: filters.undocumented_code_only || undefined,
        limit: filters.limit,
        offset: filters.offset,
      },
    }).then(response => response.data),

  registerObligations: (employmentId: number, onDate: string) =>
    api.post<{ items: HealthRegisteredObligation[] }>(
      `/payroll/submissions/health-notifications/duties/${employmentId}/obligations`,
      {},
      { params: { on_date: onDate } },
    ).then(response => response.data),

  preparePaymentOverview: (
    revisionId: number,
    insurerCode: string,
    environment: 'production' | 'test' = 'production',
  ) =>
    api.post<HealthPreparedOverview>(
      `/payroll/submissions/health-notifications/payment-overview/${revisionId}/${insurerCode}/prepare`,
      { environment },
    ).then(response => response.data),

  registerPeriodObligations: (period: string) =>
    api.post<HealthPeriodObligationSync>(
      '/payroll/submissions/health-notifications/duties/obligations',
      {},
      { params: { period } },
    ).then(response => response.data),

  enqueuePaymentOverviewIsds: (submissionId: number, insurerCode: string) =>
    api.post<HealthIsdsEnqueueResult>(
      `/payroll/submissions/${submissionId}/health-isds/${insurerCode}`,
    ).then(response => response.data),

  // Artefakt se NESTAHUJE odsud. Podání zdravotní pojišťovně leží v téže
  // platformě jako JMHZ, takže stažení jde přes `payrollApi.submissionDetail`
  // + `payrollApi.downloadSubmissionArtifact` — ty už umí jednorázový token
  // z `download-grant` i rozbalení chybové odpovědi doručené jako Blob.
  // Vlastní kopie by se s nimi dřív nebo později rozešla.
}
