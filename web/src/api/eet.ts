import { api } from './client'
import { appendStepUpProof, stepUpProofBody, type EpoStepUpProof } from './epoSubmissions'

export type EetEnvironment = 'playground' | 'production'
export type EetRegime = 'standard' | 'exempt' | 'eet_off'
export type EetUnitKind = 'premises' | 'online' | 'vehicle' | 'mobile' | 'other'
export type EetPermissionLevel = 'none' | 'read' | 'write'
/** Evidence v pověření: none = sám nebo v zastoupení, single = situace 4, multiple = situace 5. */
export type EetDelegationMode = 'none' | 'single' | 'multiple'

/** Aktivní zařízení z capabilities (výběr v dialogu i bez práva eet.settings). */
export interface EetCapabilityDevice {
  id: number
  code: string
  name: string
  unit_id: number
  unit_number: number
  unit_name: string
  /** Účetní pokladna namapovaná na zařízení (tržby z jejích příjmových dokladů jdou na něj). */
  cash_register_id: number | null
  delegation?: EetDelegationMode
  delegating_eic?: string | null
}

export interface EetCapabilities {
  contract_version: string
  enabled: boolean
  environment: EetEnvironment
  regime: EetRegime
  eic: string | null
  active_from: string | null
  supported_scenarios: string[]
  unsupported_scenarios: string[]
  permissions: Record<string, EetPermissionLevel>
  default_device_id: number | null
  devices: EetCapabilityDevice[]
  has_active_certificate: boolean
  certificate_expires_at: string | null
  commercial_license: boolean
}

export interface EetSettings {
  enabled: boolean
  environment: EetEnvironment
  eic: string | null
  active_from: string | null
  active_to: string | null
  regime: EetRegime
  exemption_reason: string | null
  default_device_id: number | null
  response_timeout_ms: number
  dispatch_blocked: boolean
  row_version: number
  updated_by: number | null
  updated_at: string | null
}

export type EetSettingsPatch = Partial<Pick<EetSettings,
  'enabled' | 'environment' | 'eic' | 'active_from' | 'active_to' | 'regime'
  | 'exemption_reason' | 'default_device_id' | 'response_timeout_ms'>> & { row_version?: number }

export interface EetActivationObstacle {
  code: string
  message: string
}

export interface EetSettingsHistoryItem {
  id: number
  changed_at: string | null
  changed_by: number | null
  snapshot: Partial<EetSettings>
}

export interface EetSettingsResponse {
  settings: EetSettings
  activation_obstacles: EetActivationObstacle[]
  history?: EetSettingsHistoryItem[]
}

export interface EetUnit {
  id: number
  unit_number: number
  name: string
  kind: EetUnitKind
  valid_from: string
  valid_to: string | null
  notified_to_fs: boolean
  created_at: string | null
  updated_at: string | null
}

export interface EetUnitInput {
  unit_number: number
  name: string
  kind: EetUnitKind
  valid_from: string
  valid_to?: string | null
  notified_to_fs?: boolean
}

export interface EetDevice {
  id: number
  unit_id: number
  code: string
  name: string | null
  cash_register_id: number | null
  delegation: EetDelegationMode
  delegating_eic: string | null
  active: boolean
  sequence_year: number | null
  next_sequence: number
  unit_number: number
  unit_name: string
  unit_valid_from: string
  unit_valid_to: string | null
  created_at: string | null
  updated_at: string | null
}

export interface EetDeviceInput {
  unit_id: number
  code: string
  name?: string | null
  cash_register_id?: number | null
  delegation?: EetDelegationMode
  delegating_eic?: string | null
  active?: boolean
}

export type EetCertificateExpiryWarning = 'expired' | 'critical' | 'warning' | null

export interface EetCertificate {
  id: number
  environment: EetEnvironment
  eic: string
  subject_cn: string | null
  serial_number: string | null
  fingerprint_sha256: string
  issuer: string | null
  not_before: string
  not_after: string
  label: string | null
  status: string
  uploaded_by: number | null
  created_at: string | null
  retired_at: string | null
  valid_now: boolean
  expires_in_days: number
  expiry_warning: EetCertificateExpiryWarning
}

export interface EetCertificateUpload {
  file: File
  password: string
  environment: EetEnvironment
  label?: string
  proof: EpoStepUpProof
}

export interface EetDiagnosticsResult {
  ok: boolean
  outcome: string
  error_code: number | null
  message: string | null
  duration_ms: number
  environment: EetEnvironment
  device_id: number
  certificate_id: number
}

/** Částka jako řetězec s dvěma desetinnými místy (např. '1210.00'). */
export type EetMoney = string

export type EetRecordStatus =
  | 'pending' | 'sending' | 'retry_wait' | 'confirmed' | 'confirmed_warning' | 'action_required' | 'verification_ok'
  /** Tržbu evidoval jiný systém (e-shop); MyÚčto ji neodesílá. */
  | 'external'

export type EetPaymentChannel =
  | 'cash' | 'card_terminal' | 'qr_in_person' | 'bank_transfer' | 'card_online' | 'cash_on_delivery' | 'other' | 'stored_value'
export type EetPaymentMethod =
  | 'bank_transfer' | 'direct_debit' | 'card' | 'cash' | 'cash_on_delivery' | 'offset' | 'other'
export type EetContactContext = 'in_person' | 'on_premises' | 'in_vehicle' | 'remote' | 'unknown'
export type EetCorrectionReason = 'refund' | 'failed_payment' | 'wrong_data' | 'duplicate' | 'other'

export interface EetPaymentRequest {
  amount: EetMoney
  currency?: string
  channel?: EetPaymentChannel
  payment_method?: EetPaymentMethod
  contact_context?: EetContactContext
  business_purpose?: string
  invoice_id?: number
  device_id?: number
  received_at?: string
  note?: string
}

export interface EetPolicyResult {
  decision: 'required' | 'voluntary' | 'not_subject' | 'unknown' | 'unsupported' | 'inactive' | 'external'
  reason_code: string
  message: string
  policy_version: string
}

export interface EetPaymentPreview {
  eet: EetPolicyResult
  creates_record: boolean
  blocks_acceptance: boolean
  requires: Array<'channel' | 'contact_context'>
  amount: EetMoney
  currency: string
  /** Částka, která se zaeviduje (vždy Kč); null, když pro den platby chybí kurz. */
  amount_czk?: EetMoney | null
  exchange_rate?: string | null
  exchange_rate_source?: string | null
  exchange_rate_error?: { code: string; message: string } | null
  /** Část tržby v Kč pro urceno_cerp_zuct (dobití kreditu / hodnotový poukaz). */
  credit_topup_amount?: EetMoney | null
  /** Část tržby v Kč pro cerp_zuct (úhrada poukazem / kreditem). */
  credit_draw_amount?: EetMoney | null
  /** Upozornění, která platbu neblokují (např. úhrada poukazem nad zůstatek prodaných poukazů). */
  warnings?: EetPaymentWarning[]
  invoice?: { id: number; currency: string; remaining: EetMoney } | null
}

/** Upozornění k platbě; `code` je klíč překladu, `message` český text ze serveru. */
export interface EetPaymentWarning {
  code: string
  message: string
  balance?: number
  amount?: number
}

export interface EetRecordWarning {
  code?: number | string
  message?: string
}

export interface EetRecordState {
  record_id: number
  public_id: string
  status: EetRecordStatus
  action_code?: string | null
  pok?: string | null
  environment: EetEnvironment
  warnings: EetRecordWarning[]
  overdue: boolean
  attempt_count?: number
  next_attempt_at?: string | null
  last_error?: { code?: number | null; message?: string | null } | null
  attempted?: boolean
}

export interface EetAllocation {
  invoice_id?: number | null
  invoice_payment_id?: number | null
  cash_document_id?: number | null
  bank_transaction_id?: number | null
  amount?: EetMoney
}

export type EetPaymentOrderStatus = 'initiated' | 'operator_confirmed' | 'settled' | 'failed' | 'cancelled'

export interface EetPaymentEvent {
  payment_event_id: string
  kind: 'payment' | 'refund' | 'intent'
  origin: string
  /** Částka a měna, jak platba proběhla. */
  amount: EetMoney
  currency: string
  /** Částka evidovaná v EET (vždy Kč). */
  amount_czk?: EetMoney
  exchange_rate?: string | null
  exchange_rate_source?: string | null
  payment_method?: string | null
  channel?: string | null
  contact_context?: string
  business_purpose?: string
  invoice_id?: number | null
  ordered_at?: string | null
  received_at?: string | null
  recorded_at: string
  paid_on?: string | null
  payment_order_status: EetPaymentOrderStatus
  bank_verification_status: 'not_applicable' | 'pending' | 'verified'
  accounting_settlement_status: 'not_applicable' | 'pending' | 'settled'
  eet_decision: { decision?: string; reason_code?: string | null; policy_version?: string | null }
  allocations?: EetAllocation[]
  eet: EetRecordState | null
  idempotent_replay?: boolean
  poll_after_seconds?: number
  warnings?: EetPaymentWarning[]
}

export interface EetPaymentIntentRequest {
  invoice_id: number
  amount?: EetMoney
  device_id?: number
  contact_context?: 'in_person' | 'on_premises' | 'in_vehicle'
  note?: string
}

export interface EetPaymentIntent extends EetPaymentEvent {
  qr?: {
    spd: string
    image_data_uri?: string | null
    account_number?: string | null
    bank_code?: string | null
    iban?: string | null
    variable_symbol?: string | null
    amount?: string
  } | null
}

export interface EetRecord extends EetRecordState {
  kind: 'sale' | 'correction' | 'diagnostic'
  payment_event_id?: number | null
  corrects_record_id?: number | null
  correction_reason?: EetCorrectionReason | null
  eic?: string
  /** EIČ pověřujícího poplatníka (eic_poverujiciho), jak odešlo ve zprávě. */
  delegating_eic?: string | null
  /** Pověření více poplatníky (povereni_vice_popl). */
  multiple_delegators?: boolean
  /** U tržby evidované externím systémem může chybět jednotka i zařízení. */
  unit_id?: number | null
  unit_number?: number | null
  device_id?: number | null
  device_code?: string | null
  sequence_number?: string | null
  /** Tržbu evidoval jiný systém (stav external). */
  external?: boolean
  sale_at?: string
  total_amount?: EetMoney
  credit_topup_amount?: EetMoney | null
  credit_draw_amount?: EetMoney | null
  currency?: string
  policy?: { decision?: string | null; reason_code?: string | null; version?: string | null }
  deadline_at?: string
  confirmed_at?: string | null
  created_at?: string | null
}

export interface EetAttempt {
  attempt_no: number
  uuid_zpravy: string
  sent_at: string
  first_attempt?: boolean
  verification?: boolean
  certificate_id?: number | null
  outcome: string
  http_status?: number | null
  error_code?: number | null
  error_message?: string | null
  pok?: string | null
  test_flag?: boolean | null
  signature_verified?: boolean
  duration_ms?: number | null
  request_sha256?: string | null
  received_at?: string | null
  request_xml?: string | null
  response_xml?: string | null
}

export interface EetRecordDetail extends EetRecord {
  payment_event?: EetPaymentEvent | null
  source_invoices?: { id: number; number: string }[]
  corrections?: EetRecord[]
  attempts?: EetAttempt[]
}

export interface EetRecordListParams {
  cursor?: string
  limit?: number
  status?: EetRecordStatus
  from?: string
  to?: string
  unit_id?: number
  device_id?: number
  kind?: 'sale' | 'correction'
  cash_document_id?: number
}

export interface EetRecordListResponse {
  items: EetRecord[]
  next_cursor: string | null
}

export interface EetCorrectionRequest {
  amount?: EetMoney
  /** Částka v původní měně cizoměnové tržby (místo `amount`). */
  original_amount?: EetMoney
  reason?: EetCorrectionReason
  note?: string
  device_id?: number
  /** Vratka tržby uhrazené poukazem / kreditem zpět na kartu / kredit. */
  refund_to_stored_value?: boolean
}

export interface EetCorrectionPreview {
  original: EetRecord
  corrected_total: EetMoney
  remaining: EetMoney
  /** Tržba byla uhrazena poukazem / kreditem. */
  stored_value_payment?: boolean
  foreign?: {
    currency: string
    original_amount: EetMoney
    remaining: EetMoney | null
    exchange_rate: string
    exchange_rate_source?: string | null
  } | null
  proposed: {
    total_amount: EetMoney
    original_amount?: EetMoney | null
    credit_topup_amount?: EetMoney | null
    credit_draw_amount?: EetMoney | null
    refund_to_stored_value?: boolean
    reason?: string | null
    device_id?: number
    environment?: string
    sale_at?: string
  }
  allowed: boolean
  error?: string | null
  accounting?: string
}

export interface EetCorrection extends EetPaymentEvent {
  correction?: EetRecord | null
  original_remaining?: EetMoney
}

export interface EetQueueSummary {
  counts?: { pending?: number; sending?: number; retry_wait?: number; action_required?: number }
  open_total?: number
  action_required_by_code?: Record<string, number>
  overdue?: number
  oldest_deadline_at?: string | null
  last_confirmed_at?: string | null
  environment?: EetEnvironment
  dispatch_blocked?: boolean
  certificates?: Record<string, { valid_until?: string | null; expired?: boolean }>
}

export interface EetInvoiceSummaryItem extends EetPaymentEvent {
  eet_record?: EetRecord | null
}

/** Dobropis: kolik z evidovaných tržeb opravované faktury zbývá opravit při vrácení peněz. */
export interface EetCreditNoteRefundInfo {
  invoice_id: number
  invoice_number: string
  currency: string
  refund_amount: EetMoney
  evidenced: { records: number; remaining_czk: EetMoney; remaining: EetMoney | null; currency: string | null }
}

/** Vrácení peněz k dobropisu zaevidované jako záporné tržby (odpověď mark-paid / výdajového dokladu). */
export interface EetCreditNoteRefundResult {
  corrections: { event_id: number; record_id: number; total_amount: EetMoney; original_amount: EetMoney | null }[]
  requested: EetMoney
  refunded: EetMoney
  refunded_czk: EetMoney
  not_recorded: EetMoney
  warning: string | null
  replayed: boolean
}

export type EetExportParams = Omit<EetRecordListParams, 'cursor' | 'limit' | 'kind'>

export const eetApi = {
  capabilities: () => api.get<EetCapabilities>('/eet/capabilities').then(r => r.data),

  settings: () => api.get<EetSettingsResponse>('/eet/settings').then(r => r.data),
  saveSettings: (patch: EetSettingsPatch) =>
    api.put<EetSettingsResponse>('/eet/settings', patch).then(r => r.data),

  units: () => api.get<{ items: EetUnit[] }>('/eet/units').then(r => r.data.items),
  createUnit: (input: EetUnitInput) => api.post<EetUnit>('/eet/units', input).then(r => r.data),
  updateUnit: (id: number, input: Partial<EetUnitInput>) =>
    api.put<EetUnit>(`/eet/units/${id}`, input).then(r => r.data),
  endUnit: (id: number, validTo?: string) =>
    api.delete<EetUnit>(`/eet/units/${id}`, { params: validTo ? { valid_to: validTo } : undefined }).then(r => r.data),

  devices: () => api.get<{ items: EetDevice[] }>('/eet/devices').then(r => r.data.items),
  createDevice: (input: EetDeviceInput) => api.post<EetDevice>('/eet/devices', input).then(r => r.data),
  updateDevice: (id: number, input: Partial<EetDeviceInput>) =>
    api.put<EetDevice>(`/eet/devices/${id}`, input).then(r => r.data),
  deactivateDevice: (id: number) => api.delete<EetDevice>(`/eet/devices/${id}`).then(r => r.data),

  certificates: () => api.get<{ items: EetCertificate[] }>('/eet/certificates').then(r => r.data.items),
  uploadCertificate: (payload: EetCertificateUpload) => {
    const data = new FormData()
    data.append('file', payload.file, payload.file.name)
    data.append('environment', payload.environment)
    data.append('pfx_password', payload.password)
    if (payload.label) data.append('label', payload.label)
    appendStepUpProof(data, payload.proof)
    return api.post<EetCertificate>('/eet/certificates', data, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data)
  },
  deactivateCertificate: (id: number, proof: EpoStepUpProof) =>
    api.post<EetCertificate>(`/eet/certificates/${id}/deactivate`, stepUpProofBody(proof)).then(r => r.data),

  diagnostics: (deviceId?: number | null) =>
    api.post<EetDiagnosticsResult>('/eet/diagnostics', deviceId ? { device_id: deviceId } : {}).then(r => r.data),

  previewPayment: (body: EetPaymentRequest) =>
    api.post<EetPaymentPreview>('/eet/payments/preview', body).then(r => r.data),
  createPayment: (body: EetPaymentRequest, idempotencyKey: string) =>
    api.post<EetPaymentEvent>('/eet/payments', body, { headers: { 'Idempotency-Key': idempotencyKey } }).then(r => r.data),
  payment: (publicId: string) =>
    api.get<EetPaymentEvent>(`/eet/payments/${encodeURIComponent(publicId)}`).then(r => r.data),

  createPaymentIntent: (body: EetPaymentIntentRequest, idempotencyKey?: string) =>
    api.post<EetPaymentIntent>('/eet/payment-intents', body, {
      headers: idempotencyKey ? { 'Idempotency-Key': idempotencyKey } : undefined,
    }).then(r => r.data),
  confirmPaymentIntent: (publicId: string, idempotencyKey: string) =>
    api.post<EetPaymentEvent>(`/eet/payment-intents/${encodeURIComponent(publicId)}/confirm`, {}, {
      headers: { 'Idempotency-Key': idempotencyKey },
    }).then(r => r.data),
  cancelPaymentIntent: (publicId: string) =>
    api.post<EetPaymentEvent>(`/eet/payment-intents/${encodeURIComponent(publicId)}/cancel`).then(r => r.data),

  records: (params: EetRecordListParams = {}) =>
    api.get<EetRecordListResponse>('/eet/records', { params }).then(r => r.data),
  record: (id: number) => api.get<EetRecordDetail>(`/eet/records/${id}`).then(r => r.data),
  retryRecord: (id: number) => api.post<EetRecordState>(`/eet/records/${id}/retry`).then(r => r.data),
  previewCorrection: (id: number, body: EetCorrectionRequest) =>
    api.post<EetCorrectionPreview>(`/eet/records/${id}/corrections/preview`, body).then(r => r.data),
  createCorrection: (id: number, body: EetCorrectionRequest, idempotencyKey: string) =>
    api.post<EetCorrection>(`/eet/records/${id}/corrections`, body, {
      headers: { 'Idempotency-Key': idempotencyKey },
    }).then(r => r.data),

  queue: () => api.get<EetQueueSummary>('/eet/queue').then(r => r.data),
  exportCsv: (params: EetExportParams = {}) =>
    api.get<Blob>('/eet/export', { params, responseType: 'blob' }).then(r => r.data),
  invoiceSummary: (invoiceId: number) =>
    api.get<{ items: EetInvoiceSummaryItem[]; refund?: EetCreditNoteRefundInfo }>(`/invoices/${invoiceId}/eet`).then(r => r.data),
}
