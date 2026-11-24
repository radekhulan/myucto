import { requestSupplierId } from '@/workspace/supplierContext'
import { api } from './client'
import type { OutboxSubmission } from './dataBox'

export type SubmissionStatus = 'draft' | 'generated' | 'downloaded' | 'submitted' | 'accepted' | 'rejected'
export type ValidationStatus = 'passed' | 'failed' | 'skipped'
export type AttemptStatus =
  | 'prepared' | 'handoff_created' | 'awaiting_confirmation' | 'confirmed'
  | 'testing' | 'test_passed' | 'test_failed' | 'submitting' | 'processing'
  | 'submitted' | 'rejected' | 'uncertain' | 'failed' | 'expired' | 'cancelled'
export type ArtifactKind =
  | 'source_xml' | 'epo_xml' | 'signed_submission_p7s' | 'confirmation_p7s'
  // Odvozené z potvrzenky přímého podání — aplikace je vytáhne sama, u asistovaného
  // podání je uživatel nahrává ručně přes „Nahrát výstupy z EPO".
  | 'confirmation_xml' | 'epo_echo'
  | 'confirmation_signer_cert' | 'submission_signer_cert'
  | 'epo_error_xml' | 'epo_status_xml' | 'receipt_pdf' | 'other'
  // Podání odeslané datovou schránkou: doručenka z ISDS a zpráva od finančního úřadu.
  | 'isds_delivery_zfo' | 'isds_response_zfo'
export type VerificationStatus = 'not_applicable' | 'pending' | 'valid' | 'warning' | 'invalid'

export interface EpoMessage {
  type: string | null
  code: string | null
  text: string | null
  field: string | null
  section: string | null
  line: string | null
}

export interface EpoAttempt {
  id: number
  channel: 'epo_assisted' | 'epo_direct'
  epo_environment: 'production' | 'test'
  status: AttemptStatus
  request_sha256: string
  signing_credential_id: number | null
  signing_fingerprint: string | null
  response_http_status: number | null
  test_passed: boolean | null
  test_messages: EpoMessage[]
  tested_at: string | null
  error_code: string | null
  error_message: string | null
  requested_by: number | null
  requested_at: string
  handoff_expires_at: string | null
  submitted_at: string | null
  remote_submission_ref: string | null
  remote_status: Record<string, string> | null
  last_status_at: string | null
  confirmed_at: string | null
  poll_count: number
  next_poll_at: string | null
  status_query_available: boolean
  refresh_available: boolean
  confirmation_recovery_available: boolean
  resolution_code: string | null
  resolution_note: string | null
  resolved_by: number | null
  resolved_at: string | null
  updated_at: string
}

/** Identita z certifikátu, jak ji aplikace vytáhla z dodejky. */
export interface EpoCertificateSummary {
  common_name?: string
  organization?: string
  serial_number?: string
  issuer?: string
  valid_from?: string
  valid_to?: string
  fingerprint_sha256?: string
}

/**
 * Shrnutí dodejky EPO. Heslo pro dotaz na stav v něm ZÁMĚRNĚ není — má vlastní,
 * auditovanou cestu ven (`revealStatePassword`).
 */
export interface EpoReceiptSummary {
  reference?: string
  submitted_at?: string
  receipt_checksum?: string
  zarep?: boolean
  submitted_md5?: string
  submitted_name?: string
  office_code?: string
  seal?: EpoCertificateSummary
  submitted_by?: EpoCertificateSummary
}

/**
 * Co se povedlo přečíst z PDF opisu staženého z portálu. Na rozdíl od dodejky je to
 * jen odečtený text, ne podepsaný důkaz — slouží k předvyplnění, nikdy k doložení.
 */
export interface EpoReceiptHint {
  text_available: boolean
  reference: string | null
  submitted_at: string | null
  checksum: string | null
  office_code: string | null
}

/** Rozdíl ručně nahraného XML proti archivovanému snapshotu. */
export interface EpoXmlDiff {
  comparable: boolean
  form_code: string | null
  expected_form_code: string | null
  form_match: boolean | null
  difference_count: number
  differences: Array<{ path: string; expected: string | null; actual: string | null }>
}

export interface EpoArtifact {
  id: number
  tax_submission_id: number
  attempt_id: number | null
  document_id: number
  artifact_kind: ArtifactKind
  sha256: string
  verification_status: VerificationStatus
  verification: {
    signature_valid?: boolean
    chain_valid?: boolean
    is_confirmation?: boolean
    epo_signer_valid?: boolean
    reference?: string | null
    submitted_at?: string | null
    content_match?: boolean | null
    form_match?: boolean | null
    snapshot_sha256_match?: boolean
    receipt?: EpoReceiptSummary
    diff?: EpoXmlDiff
    hint?: EpoReceiptHint
  } | null
  title: string
  original_name: string
  size_bytes: number
  doc_type: string
  folder_id: number | null
  created_at: string
}

/** Výkazy, u kterých server umí PDF podání (TaxSubmissionPrintout::FORM_CODES). */
export const PRINTABLE_SUBMISSION_FORMS = ['dphdp3', 'dphkh1', 'dphshv'] as const

/** Uložené doklady o podání, nebo proč chybí (TaxSubmissionReceiptEvidence). */
export interface TaxSubmissionReceiptEvidence {
  available: boolean
  reason: 'not_submitted' | 'isds_receipt_pending' | 'submitted_outside' | null
  reason_label: string | null
  reference: string | null
  submitted_at: string | null
  items: Array<{ artifact_id: number; kind: string; label: string; name: string }>
}

export interface TaxSubmission {
  id: number
  form_code: string
  form_variant?: string
  period_year: number
  period_month: number | null
  period_quarter: number | null
  xml_size_bytes: number
  xml_sha256: string
  validation_status: ValidationStatus
  validation_errors: string[]
  status: SubmissionStatus
  submitted_at: string | null
  submission_ref: string | null
  summary: Record<string, unknown> | null
  generated_at: string
  notes: string | null
  attempts: EpoAttempt[]
  artifacts: EpoArtifact[]
  /** Vrací seznam a detail podání; odpovědi akcí ho nést nemusí. */
  receipt_evidence?: TaxSubmissionReceiptEvidence
  /** Smazatelnost počítá backend — UI jen zrcadlí to, co brána skutečně udělá. */
  deletable: boolean
  delete_blocker: SubmissionDeleteBlocker | null
  /** Nedořešené předání jde odemknout vědomým potvrzením „nepodáno". */
  delete_needs_acknowledgement: boolean
  /**
   * Výsledek zúčtování DPH (343) spuštěného podáním — vrací ho jen endpoint „označit
   * jako podané". `skipped`/`error` znamená, že rozdíl daně v hlavní knize NENÍ; typicky
   * u dodatečného přiznání do období, které je už zamčené (`date_locked`). Musí se
   * dostat k účetní, jinak o tom nikdo neví.
   */
  vat_clearing?: VatClearingOutcome | null
  /** Poslední zpráva datové schránky k tomuto snapshotu, `null` = datovkou se neposílalo. */
  isds_dispatch?: TaxIsdsDispatch | null
}

export type TaxIsdsDispatchState =
  | 'ready' | 'sending' | 'send_uncertain' | 'sent' | 'delivered' | 'failed' | 'cancelled'

export interface TaxIsdsDispatch {
  id: number
  dispatch_state: TaxIsdsDispatchState
  dispatch_mode: string | null
  recipient_id: number | null
  recipient_box_id: string | null
  recipient_name: string | null
  subject: string
  correlation_reference: string | null
  external_message_id: string | null
  sent_at: string | null
  delivered_at: string | null
  receipt_document_id: number | null
  receipt_attached_at: string | null
  last_error_code: string | null
  last_error_message: string | null
  created_at: string
}

/** Skupiny podání, pro které se dá zvlášť zvolit schránka finančního úřadu. */
export type TaxIsdsRouteGroup = 'vat' | 'kh' | 'sh' | 'income'

export interface TaxIsdsRecipient {
  id: number
  code: string
  name: string
  kind: 'tax_office' | 'other'
  isds_box_id: string | null
  address: string | null
  own: boolean
}

export interface TaxIsdsRoute {
  /** Odvozený z kódu finančního úřadu firmy. */
  default: TaxIsdsRecipient | null
  /** Ručně zvolený příjemce; `null` = platí výchozí. */
  override: TaxIsdsRecipient | null
  effective: TaxIsdsRecipient | null
}

export interface TaxIsdsRoutes {
  office_code: string | null
  groups: Record<TaxIsdsRouteGroup, TaxIsdsRoute>
  recipients: TaxIsdsRecipient[]
}

export type TaxIsdsRouteInput = Partial<Record<TaxIsdsRouteGroup, number | null>>

export interface TaxIsdsEnqueueResult {
  outbox_id: number
  created: boolean
  row: OutboxSubmission
  recipient: {
    id: number
    box_id: string
    name: string
    code: string
    source: 'override' | 'office_code'
  }
  /** Kód FÚ (c_ufo) z hlavičky XML. */
  xml_office_code: string | null
  office_mismatch: boolean
  subject: string
  attachment: { filename: string; mime: string; sha256: string; bytes: number }
  transport: {
    automatic: boolean
    channel: 'gateway' | 'mobile_key' | 'manual_upload'
    reason: string | null
  }
}

/** Platební údaje přiznání k DPH (účet FÚ podle `c_ufo` z XML snapshotu). */
export interface VatPaymentInstruction {
  supported: boolean
  amendment?: boolean
  payable?: number
  refund?: number
  due_date?: string | null
  variable_symbol?: string | null
  office_code?: string | null
  account?: {
    office_code: string
    office_name: string
    prefix: string
    number: string
    bank_code: string
    account: string
    iban: string
  } | null
  payment?: { paid: boolean; paid_amount: number; paid_at: string | null } | null
  qr_data_uri?: string | null
}

export interface TaxIsdsInboxItem {
  id: number
  external_message_id: string | null
  sender_box_id: string | null
  sender_name: string | null
  subject: string | null
  classification: 'tax_office_response' | 'unclassified'
  delivered_at: string | null
  matched: boolean
}

/** Výkazy, které jde poslat datovou schránkou finančního úřadu. OSS a mzdy nikdy. */
export const TAX_ISDS_FORMS: ReadonlySet<string> = new Set([
  'dphdp3', 'dphkh1', 'dphshv', 'dppdp9', 'dpfdp5', 'dpfdp7',
])

export interface TaxSubmissionStats {
  total: number
  waiting: number
  submitted: number
  problems: number
}

export interface TaxSubmissionListParams {
  submission_id?: number
  status?: SubmissionStatus | 'all'
  form_code?: string
  q?: string
  limit?: number
  offset?: number
  /** `payroll` = jen mzdová vyúčtování (DPZVD6, DPSVD2), `tax` = vše ostatní. */
  scope?: TaxSubmissionScope
}

export type TaxSubmissionScope = 'tax' | 'payroll'

export interface TaxSubmissionListResponse {
  data: TaxSubmission[]
  meta: {
    /** Počet řádků odpovídajících filtru — základ stránkování. */
    total: number
    limit: number
    offset: number
    /** Souhrn nad celým viditelným archivem, ne nad stránkou ani filtrem. */
    stats: TaxSubmissionStats
    /** Kódy výkazů v archivu — nabídka filtru nesmí záviset na načtené stránce. */
    form_codes: string[]
  }
}

export interface VatClearingOutcome {
  status: string
  reason?: string | null
  error?: string | null
  period?: string | null
  entry_id?: number | null
}

/**
 * `submitted_snapshot` / `delivered_attempt` = prokazatelně podáno, mazat nelze.
 * `unresolved_attempt` = nevíme, jak předání dopadlo — uživatel to může uzavřít.
 */
export type SubmissionDeleteBlocker =
  | 'submitted_snapshot'
  | 'delivered_attempt'
  | 'unresolved_attempt'
  | 'isds_pending'

export interface EpoFolder {
  id: number
  parent_id: number | null
  name: string
}

export interface EpoSettingsInput {
  vat_root_folder_id: number | null
  income_tax_root_folder_id: number | null
}

export interface EpoSettings extends EpoSettingsInput {
  folders: EpoFolder[]
  epo_environment: 'production' | 'test'
}

export interface HandoffResult {
  attempt_id: number
  url: string
  expires_at: string
  archive_folder_id: number | null
  source_document_id: number | null
  environment: 'production' | 'test'
}

export interface EpoSigningCredential {
  id: number
  label: string
  fingerprint_sha256: string
  subject_dn: string
  issuer_dn: string
  serial_hex: string | null
  valid_from: string
  valid_to: string
  ik_mpsv_present: boolean
  epo_verified: boolean
  epo_verified_at: string | null
  valid_now: boolean
  created_at: string
  enabled_for_supplier: boolean
  linked_profiles_count: number
  linked_supplier_profiles_count: number
  /** Přístupy k datové schránce, které se certifikátem přihlašují (blokují smazání). */
  linked_data_box_count?: number
  /** Registrace odesílací brány ISDS s tímto certifikátem (blokují smazání). */
  linked_isds_gateway_count?: number
  /** Volby certifikátu pro mzdová podání; smazání certifikátu je zruší. */
  linked_payroll_selections_count?: number
}

export interface EpoTestResult {
  attempt_id: number
  passed: boolean
  messages: EpoMessage[]
  large_submission: boolean
  environment: 'production' | 'test'
  artifacts: EpoArtifact[]
  attempts: EpoAttempt[]
}

export interface EpoDirectResult {
  attempt_id: number
  status: 'processing' | 'confirmed' | 'rejected' | 'uncertain' | 'cancelled'
  reference?: string
  submitted_at?: string
  chain_valid?: boolean
  message?: string
  messages?: EpoMessage[]
  remote_status?: Record<string, string>
  environment: 'production' | 'test'
  artifacts?: EpoArtifact[]
  attempts?: EpoAttempt[]
}

export interface ArtifactUploadResult {
  created: EpoArtifact[]
  errors: Array<{ name: string; code: string; message?: string }>
  artifacts: EpoArtifact[]
  attempts: EpoAttempt[]
  /** Z ověřené dodejky: podací číslo, rozhodný čas a zda z ní šlo převzít heslo. */
  confirmation: {
    reference: string | null
    submitted_at: string | null
    verification_status: VerificationStatus
    is_confirmation: boolean
    receipt: EpoReceiptSummary
    attempt_id: number | null
    status_query_available: boolean
  } | null
  /** Z PDF opisu — pouze jako předvyplnění, když dodejka není. */
  hint: EpoReceiptHint | null
}

/**
 * Čerstvé ověření pro práci s podpisovým certifikátem a přímým podáním.
 * Účet s passkey/TOTP posílá jednorázový `step_up_token` (u TOTP stačí i přímo
 * `totp_code`); `password` zůstává jen pro účet bez jakéhokoli silného faktoru.
 */
export interface EpoStepUpProof {
  password?: string
  totp_code?: string
  step_up_token?: string
}

export function stepUpProofBody(proof: EpoStepUpProof): Record<string, string> {
  const body: Record<string, string> = {}
  if (proof.step_up_token) body.step_up_token = proof.step_up_token
  else if (proof.totp_code) body.totp_code = proof.totp_code
  if (proof.password) body.password = proof.password
  return body
}

export function appendStepUpProof(data: FormData, proof: EpoStepUpProof): void {
  for (const [key, value] of Object.entries(stepUpProofBody(proof))) {
    data.append(key, value)
  }
}

function sid(): string {
  const value = requestSupplierId()
  return value && /^\d+$/.test(value) ? value : ''
}

export const epoSubmissionsApi = {
  list: (params: TaxSubmissionListParams = {}) =>
    api.get<TaxSubmissionListResponse>('/reports/submissions', {
      params: {
        submission_id: params.submission_id,
        status: params.status && params.status !== 'all' ? params.status : undefined,
        form_code: params.form_code && params.form_code !== 'all' ? params.form_code : undefined,
        q: params.q?.trim() || undefined,
        limit: params.limit,
        offset: params.offset || undefined,
        scope: params.scope,
      },
    }).then(r => r.data),

  settings: () => api.get<EpoSettings>('/reports/submissions/settings').then(r => r.data),

  updateSettings: (settings: EpoSettingsInput) =>
    api.put<EpoSettings>('/reports/submissions/settings', settings).then(r => r.data),

  handoff: (id: number, replaceActive = false) =>
    api.post<HandoffResult>(`/reports/submissions/${id}/epo-handoff`, {
      replace_active: replaceActive,
    }).then(r => r.data),

  credentials: () =>
    api.get<EpoSigningCredential[]>('/reports/submissions/epo-credentials').then(r => r.data),

  uploadCredential: (
    file: File,
    label: string,
    pfxPassword: string,
    proof: EpoStepUpProof,
  ) => {
    const data = new FormData()
    data.append('file', file, file.name)
    data.append('label', label)
    data.append('pfx_password', pfxPassword)
    appendStepUpProof(data, proof)
    return api.post<EpoSigningCredential>('/reports/submissions/epo-credentials', data, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },

  setCredentialSupplier: (
    credentialId: number,
    enabled: boolean,
    proof: EpoStepUpProof,
  ) => api.put<{ credentials: EpoSigningCredential[] }>(
    `/reports/submissions/epo-credentials/${credentialId}/supplier`,
    { enabled, ...stepUpProofBody(proof) },
  ).then(r => r.data),

  deleteCredential: (credentialId: number, proof: EpoStepUpProof) =>
    api.delete<{ deleted: boolean }>(`/reports/submissions/epo-credentials/${credentialId}`, {
      data: stepUpProofBody(proof),
    }).then(r => r.data),

  testDirect: (
    id: number,
    credentialId: number,
    proof: EpoStepUpProof,
  ) =>
    api.post<EpoTestResult>(`/reports/submissions/${id}/epo-test`, {
      credential_id: credentialId,
      ...stepUpProofBody(proof),
    }).then(r => r.data),

  submitDirect: (
    id: number,
    attemptId: number,
    proof: EpoStepUpProof,
    supersedeHandoff = false,
  ) => api.post<EpoDirectResult>(`/reports/submissions/${id}/epo-submit`, {
    attempt_id: attemptId,
    ...(supersedeHandoff ? { supersede_handoff: true } : {}),
    ...stepUpProofBody(proof),
  }).then(r => r.data),

  refreshDirectStatus: (id: number, attemptId: number) =>
    api.post<EpoDirectResult>(`/reports/submissions/${id}/epo-status`, {
      attempt_id: attemptId,
    }).then(r => r.data),

  recoverDirectConfirmation: (
    id: number,
    attemptId: number,
    file: File,
    proof: EpoStepUpProof,
  ) => {
    const data = new FormData()
    data.append('file', file, file.name)
    appendStepUpProof(data, proof)
    return api.post<EpoDirectResult>(
      `/reports/submissions/${id}/epo-attempts/${attemptId}/confirmation`,
      data,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    ).then(r => r.data)
  },

  resolveDirectNotSubmitted: (
    id: number,
    attemptId: number,
    note: string,
    proof: EpoStepUpProof,
  ) => api.post<EpoDirectResult>(
    `/reports/submissions/${id}/epo-attempts/${attemptId}/resolve-not-submitted`,
    {
      verified_not_submitted: true,
      note,
      ...stepUpProofBody(proof),
    },
  ).then(r => r.data),

  /**
   * Odhalí heslo, kterým se na Daňovém portálu dohledá stav podání a stáhne opis.
   * EPO ho vydá jen jednou v dodejce; aplikace ho jinak drží zašifrované. Proto POST
   * se step-up ověřením — server odhalení zapíše do auditu.
   */
  revealStatePassword: (
    id: number,
    attemptId: number,
    proof: EpoStepUpProof,
  ) => api.post<{ attempt_id: number; reference: string | null; state_password: string }>(
    `/reports/submissions/${id}/epo-attempts/${attemptId}/state-password`,
    stepUpProofBody(proof),
  ).then(r => r.data),

  uploadArtifacts: (
    id: number,
    files: File[],
    attemptId?: number,
    onProgress?: (percent: number) => void,
  ) => {
    const data = new FormData()
    for (const file of files) data.append('file[]', file, file.name)
    if (attemptId) data.append('attempt_id', String(attemptId))
    return api.post<ArtifactUploadResult>(`/reports/submissions/${id}/artifacts`, data, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (event) => {
        if (onProgress && event.total) {
          onProgress(Math.round((event.loaded / event.total) * 100))
        }
      },
    }).then(r => r.data)
  },

  markSubmitted: (id: number, submittedAt: string, submissionRef: string) =>
    api.post<TaxSubmission>(`/reports/submissions/${id}/submit`, {
      submitted_at: submittedAt,
      submission_ref: submissionRef,
    }).then(r => r.data),

  /**
   * `notSubmittedNote` = vědomé potvrzení, že nedořešené předání nakonec podané nebylo.
   * Tvrdé blokace (prokazatelně podáno) neuvolní — ty backend odmítne i s poznámkou.
   */
  remove: (id: number, notSubmittedNote?: string) =>
    api.delete<{ deleted: boolean; released_attempts: number }>(
      `/reports/submissions/${id}`,
      notSubmittedNote ? { data: { not_submitted_note: notSubmittedNote } } : undefined,
    ).then(r => r.data),

  isdsRoutes: () =>
    api.get<TaxIsdsRoutes>('/reports/submissions/isds-routes').then(r => r.data),

  /** `null` u skupiny vrací výchozí schránku podle kódu finančního úřadu. */
  updateIsdsRoutes: (groups: TaxIsdsRouteInput) =>
    api.put<TaxIsdsRoutes>('/reports/submissions/isds-routes', { groups }).then(r => r.data),

  /**
   * Zařadí podání do fronty datové schránky. NEODESÍLÁ: odeslání je samostatný
   * úkon jednou ze čtyř přihlašovacích cest. Opakované volání pro týž snapshot
   * a příjemce vrátí už založenou zprávu (`created: false`).
   */
  enqueueIsds: (id: number) =>
    api.post<TaxIsdsEnqueueResult>(`/reports/submissions/${id}/isds`, {}).then(r => r.data),

  /** Zruší zprávu ve frontě (jen stav `ready`, jinak 409 `isds_dispatch_not_cancellable`). */
  cancelIsds: (id: number) =>
    api.post<TaxSubmission>(`/reports/submissions/${id}/isds-cancel`, {}).then(r => r.data),

  uploadIsdsReceipt: (id: number, file: File) => {
    const data = new FormData()
    data.append('file', file, file.name)
    return api.post<TaxSubmission>(`/reports/submissions/${id}/isds-receipt`, data, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },

  isdsInbox: (id: number) =>
    api.get<{ items: TaxIsdsInboxItem[] }>(`/reports/submissions/${id}/isds-inbox`)
      .then(r => r.data.items),

  attachIsdsInboxMessage: (id: number, messageId: number) =>
    api.post<TaxSubmission>(`/reports/submissions/${id}/isds-inbox/${messageId}`, {})
      .then(r => r.data),

  vatPayment: (id: number) =>
    api.get<VatPaymentInstruction>(`/reports/submissions/${id}/payment`).then(r => r.data),

  xmlUrl: (id: number) => {
    const params = new URLSearchParams()
    const supplierId = sid()
    if (supplierId) params.set('supplier_id', supplierId)
    const query = params.toString()
    return `/api/reports/submissions/${id}/xml${query ? `?${query}` : ''}`
  },

  pdfUrl: (id: number) => {
    const params = new URLSearchParams()
    const supplierId = sid()
    if (supplierId) params.set('supplier_id', supplierId)
    const query = params.toString()
    return `/api/reports/submissions/${id}/pdf${query ? `?${query}` : ''}`
  },

  artifactDownloadUrl: (submissionId: number, artifactId: number) => {
    const params = new URLSearchParams()
    const supplierId = sid()
    if (supplierId) params.set('supplier_id', supplierId)
    const query = params.toString()
    return `/api/reports/submissions/${submissionId}/artifacts/${artifactId}/download${query ? `?${query}` : ''}`
  },
}
