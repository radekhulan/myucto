import { api } from './client'

/**
 * Datová schránka jako průřezový kanál podání.
 *
 * Není to mzdová odbočka: touhle cestou jde odeslat přiznání k DPH, kontrolní
 * i souhrnné hlášení, DPPO i přehledy zdravotním pojišťovnám.
 */

/** Doprava — co víme o cestě zprávy k příjemci. */
export type DispatchState =
  | 'ready'
  | 'sending'
  | 'send_uncertain'
  | 'sent'
  | 'delivered'
  | 'failed'
  | 'cancelled'

/**
 * Vyřízení — co o podání rozhodl ÚŘAD.
 *
 * ⚠️ Samostatná osa schválně. Datová schránka vrací doručenku, tedy důkaz
 * o DORUČENÍ, ne o zpracování; `acceptance_state` proto u ISDS podání zůstává
 * `unknown` i poté, co je `dispatch_state` `delivered`. Kdo obě osy v UI slije,
 * vyrobí podání, které se tváří jako přijaté, aniž o něm úřad rozhodl.
 */
export type AcceptanceState = 'unknown' | 'accepted' | 'rejected'

export type AcceptanceEvidence = 'epo_protocol' | 'agency_protocol_message' | 'manual_confirmation'

export type RecipientKind = 'tax_office' | 'cssz' | 'health_insurer' | 'other'

export type InboxClassification =
  | 'delivery_receipt'
  | 'cssz_protocol'
  | 'health_insurer_response'
  | 'tax_office_response'
  | 'unclassified'

export interface DataBoxCredential {
  id: number
  supplier_id: number
  environment: 'production' | 'test'
  channel: 'isds'
  label: string
  box_id: string
  auth_mode: 'certificate'
  certificate_fingerprint: string | null
  certificate_valid_to: string | null
  last_verified_at: string | null
  /** Legacy projekce; automatické vybírání není podporované a hodnota zůstává false. */
  inbox_polling_enabled: boolean
  inbox_polling_enabled_at: string | null
  inbox_polling_enabled_by: number | null
}

export interface DataBoxArchiveFolder {
  id: number
  parent_id: number | null
  name: string
}

export interface DataBoxInboxStorageSetting {
  supplier_id: number
  channel: 'isds'
  environment: 'production' | 'test'
  base_folder_id: number
  row_version: number
  updated_by: number | null
  created_at: string
  updated_at: string
}

export interface DataBoxInboxStorageSettings {
  items: DataBoxInboxStorageSetting[]
  folders: DataBoxArchiveFolder[]
}

export interface SubmissionRecipient {
  id: number
  supplier_id: number | null
  code: string
  name: string
  business_id: string | null
  address: string | null
  kind: RecipientKind
  isds_box_id: string | null
  source_url: string | null
  source_note: string | null
  is_active: boolean
  is_system: boolean
  /** Číselník smí být prázdný — u finančních úřadů doklad nemáme a nehádáme. */
  has_box_id: boolean
  verified_in_isds_at: string | null
}

/**
 * Jak podání opustilo aplikaci.
 *
 * `manual` = odeslal ho člověk ze své vlastní datové schránky. Není to nouzový
 * režim: strojové napojení na ISDS nasazené není, takže tohle je dnes běžná
 * cesta a UI podle ní musí uživateli říct, co má udělat.
 */
export type DispatchMode = 'channel' | 'manual'

/**
 * Čím se doručenka spárovala s podáním.
 *
 * `correlation_reference` a `external_message_id` jsou PŘESNÉ identifikátory —
 * podle nich se páruje automaticky. `manual` znamená, že vazbu potvrdil člověk;
 * nic slabšího automat nepoužije, protože špatně přiřazená doručenka tvrdí něco
 * o podání, o kterém nic neví.
 */
export type ReceiptMatchedBy = 'correlation_reference' | 'external_message_id' | 'manual'

export interface OutboxSubmission {
  id: number
  environment: 'production' | 'test'
  channel: 'epo' | 'isds'
  dispatch_mode: DispatchMode
  agenda_code: string
  recipient_id: number | null
  recipient_box_id: string | null
  subject: string
  artifact_kind: 'payroll_submission' | 'tax_submission' | 'document'
  artifact_id: number
  artifact_filename: string
  dispatch_state: DispatchState
  acceptance_state: AcceptanceState
  acceptance_evidence_kind: AcceptanceEvidence | null
  acceptance_note: string | null
  correlation_reference: string
  external_message_id: string | null
  artifact_validation_status: 'passed' | 'failed' | 'skipped' | null
  recipient_box_verified_at: string | null
  receipt_document_id: number | null
  /**
   * ⚠️ Vždycky `unverified`, dokud CMS podpis doručenky sami neověříme.
   * UI to musí říct nahlas — poctivé „nevíme" je lepší než falešná jistota.
   */
  receipt_signature_status: 'unverified' | 'trusted'
  receipt_matched_by: ReceiptMatchedBy | null
  receipt_inbox_message_id: number | null
  receipt_attached_at: string | null
  confirmed_by: number | null
  confirmed_at: string | null
  sent_at: string | null
  delivered_at: string | null
  accepted_at: string | null
  rejected_at: string | null
  last_error_code: string | null
  last_error_message: string | null
  row_version: number
  created_at: string
}

export interface OutboxAttempt {
  id: number
  attempt_no: number
  outcome: 'in_flight' | 'sent' | 'uncertain' | 'rejected' | 'failed'
  external_message_id: string | null
  error_code: string | null
  error_message: string | null
  started_at: string
  finished_at: string | null
}

export interface InboxMessage {
  id: number
  external_message_id: string
  sender_box_id: string | null
  sender_name: string | null
  subject: string | null
  sender_ident: string | null
  classification: InboxClassification
  matched_outbox_id: number | null
  document_id: number | null
  signature_status: 'unverified' | 'trusted'
  delivered_at: string | null
  accepted_at: string | null
  fetched_at: string
  hidden_at: string | null
  hidden_by: number | null
  local_content_state: 'available' | 'purging' | 'purged'
  local_content_purged_at: string | null
  local_content_purged_by: number | null
  lifecycle_row_version: number
  /** Rozhodný den doručení a čím je podložený — viz {@link DeliveryBasis}. */
  delivery_basis?: DeliveryBasis
  delivered_on?: string | null
  fiction_statutory_on?: string | null
  fiction_due_on?: string | null
  fiction_days?: number | null
  fiction_days_source?: 'ruleset' | 'statute' | null
  sender_is_public_authority?: boolean | null
  delivery_resolved_at?: string | null
  delivery_note?: string | null
}

/**
 * Čím je doručení podložené (§ 17 odst. 3 a 4 zák. 300/2008 Sb.).
 *
 * `pending` a `unknown` doručení NETVRDÍ — a nejsou totéž: `pending` znamená
 * „lhůta fikce běží", `unknown` znamená „nevíme". Ani jedno není „v pořádku".
 */
export type DeliveryBasis = 'login' | 'fiction' | 'login_or_fiction' | 'pending' | 'unknown'

/** Které písmeno § 74 odst. 1 daňového řádu výzva uvádí. */
export type DefectGround =
  | 'a_not_processable'
  | 'b_no_effects'
  | 'c_wrong_way'
  | 'd_wrong_format'
  | 'unknown'

/**
 * Následek neodstranění vady. Neúčinnost hrozí JEN u písmen a) a b)
 * (§ 74 odst. 4 DŘ) — u c) a d) podání nezaniká, ale hrozí pokuta podle
 * § 247a DŘ. `unknown` znamená, že to z evidence nejde určit.
 */
export type DefectConsequence = 'ineffective' | 'no_ineffectiveness' | 'unknown'

export type DefectNoticeStatus =
  | 'unknown'
  | 'open'
  | 'answered_in_time'
  | 'answered_late'
  | 'missed'
  | 'withdrawn'

export type DefectNoticeOutcome = 'unknown' | 'cured' | 'ineffective' | 'penalty_risk'

/** Vyhodnocení výzvy — co z ní právě teď plyne. */
export interface DefectNoticeAssessment {
  status: DefectNoticeStatus
  consequence: DefectConsequence
  outcome: DefectNoticeOutcome
  respond_by_on: string | null
  respond_by_source: 'stated_in_notice' | 'derived_from_days' | 'unknown'
  respond_by_shifted: boolean
  days_left: number | null
  sentence: string
  suspiciously_short_period: boolean
  needs_attention: boolean
}

/** Výzva k odstranění vad podání podle § 74 daňového řádu. */
export interface DefectNotice {
  id: number
  environment: 'production' | 'test'
  outbox_id: number | null
  inbox_message_id: number | null
  notice_reference: string | null
  authority_kind: RecipientKind
  defect_ground: DefectGround
  consequence: DefectConsequence
  delivered_on: string | null
  respond_by_on: string | null
  respond_by_source: 'stated_in_notice' | 'derived_from_days' | 'unknown'
  stated_period_days: number | null
  respond_by_shifted: boolean
  status: DefectNoticeStatus
  responded_on: string | null
  response_outbox_id: number | null
  outcome: DefectNoticeOutcome
  note: string | null
  row_version: number
  created_at: string
  assessment: DefectNoticeAssessment
}

/** Vstup pro založení výzvy. Lhůtu aplikace nedopočítává — musí přijít odsud. */
export interface DefectNoticeInput {
  environment: string
  outbox_id?: number | null
  inbox_message_id?: number | null
  notice_reference?: string | null
  authority_kind?: RecipientKind
  defect_ground?: DefectGround
  delivered_on?: string | null
  respond_by_on?: string | null
  stated_period_days?: number | null
  note?: string | null
}

/**
 * Stav dotazování schránky.
 *
 * `last_attempt_at` a `last_ok_at` jsou zvlášť schválně: bez toho by „žádné
 * nové zprávy" a „na schránku se nedovoláme" vypadaly v UI stejně.
 */
export interface InboxPollState {
  last_attempt_at: string | null
  last_ok_at: string | null
  last_ok_count: number | null
  consecutive_failures: number
  last_error_code: string | null
  last_error_message: string | null
}

/** Jedno podání, ke kterému by nahraná doručenka mohla patřit. */
export interface ReceiptCandidate {
  id: number
  subject: string
  agenda_code: string
  recipient_box_id: string | null
  dispatch_state: DispatchState
  correlation_reference: string
  created_at: string
  /** Které signály sedí: `recipient_box`, `subject`, `period`. Ne důkaz, nápověda. */
  reasons: string[]
}

/**
 * Výsledek nahrání doručenky.
 *
 * `status` je celý smysl téhle odpovědi:
 *   - `matched`            — spárováno přes přesný identifikátor, stav se posunul,
 *   - `candidates`         — nabízíme podání, ale ROZHODUJE ČLOVĚK; nic se nezměnilo,
 *   - `unmatched`          — nemáme co nabídnout, doručenka leží v nezařazených,
 *   - `already_processed`  — tuhle doručenku už máme, druhý průchod nic nedělá.
 */
export interface ReceiptUploadResult {
  status: 'matched' | 'candidates' | 'unmatched' | 'already_processed'
  message: string
  reason: string
  inbox_message_id: number
  document_id: number | null
  outbox_id: number | null
  matched_by: ReceiptMatchedBy | null
  candidates: ReceiptCandidate[]
  submission: OutboxSubmission | null
  delivery_recorded?: boolean
  validation?: { status: string; checked: boolean; errors: string[] } | null
  receipt: {
    message_id: string
    sender_box_id: string | null
    sender_name: string | null
    recipient_box_id: string | null
    recipient_name: string | null
    sender_ident: string | null
    subject: string | null
    sent_at: string | null
    delivered_at: string | null
    signature_status: 'unverified' | 'trusted'
  }
}

/**
 * Registrace odesílací brány ISDS — provozovatelská, ne zákaznická.
 *
 * Certifikát platí provozovatel a je jeden pro celou službu, takže tenhle
 * výpis vidí jen účet s právem na `settings.signing`. Zákazník k odesílání
 * přes bránu nenastavuje nic.
 */
export interface IsdsGatewayRegistration {
  id: number
  environment: 'production' | 'test'
  ats_id: string
  label: string
  return_url: string
  error_url: string | null
  concept_ttl_seconds: number
  portal_host: string
  service_host: string
  user_login_policy: 'unknown' | 'password_required' | 'portal_sso_or_password'
  certificate_fingerprint: string | null
  certificate_valid_to: string | null
  is_active: boolean
}

/**
 * Výchozí hostitelé prostředí ISDS — jen předvolby formuláře.
 *
 * Závazná je hodnota uložená v registraci: staré domény `mojedatovaschranka.cz`
 * poběží podle Provozního řádu souběžně minimálně do 31. 12. 2027.
 */
export interface IsdsGatewayHosts {
  portal: string
  service: string
}

export interface IsdsGatewaySettings {
  items: IsdsGatewayRegistration[]
  default_hosts: Record<'production' | 'test', IsdsGatewayHosts>
}

/**
 * Vstup pro uložení registrace.
 *
 * Certifikát je PKCS#12 **včetně soukromého klíče** — používá se jako klientský
 * certifikát TLS. Heslo k němu se posílá jen sem a zpátky se nikdy nevrací;
 * proto je to `File` + `string`, ne pole, které by šlo předvyplnit.
 */
export interface IsdsGatewayRegistrationInput {
  environment: 'production' | 'test'
  ats_id: string
  label: string
  return_url: string
  error_url: string | null
  concept_ttl_seconds: number
  portal_host: string
  service_host: string
  user_login_policy: IsdsGatewayRegistration['user_login_policy']
  certificate: File
  certificate_password: string
}

/** Odpověď na zahájení odeslání — kam poslat prohlížeč a co uživateli říct. */
export interface GatewayStart {
  session_id: number
  app_token: string
  redirect_url: string
  /** Text PŘED přesměrováním. Nikdy netvrdí víc, než je doloženo. */
  login_guidance: string
  login_policy_documented: boolean
  expires_at: string
  /** Uživatel se vrací do relace, kterou už má rozpracovanou (dvojí kliknutí). */
  resumed: boolean
}

export interface InboxPollResult {
  fetched: number
  stored: number
  skipped: number
  failed: number
  unclassified: number
  error?: string | null
}

export interface MobileKeyInboxStart {
  flow_token: string
  state: number
  description: string
  expires_at: string
}

/**
 * Odeslání datovkou v relaci potvrzené Mobilním klíčem. Stav a odeslání jsou
 * jedno volání schválně — potvrzení relace se dá vyzvednout jen jednou, takže
 * kdyby se stav zjišťoval zvlášť, relace by se spotřebovala a odeslat už by
 * v ní nešlo.
 */
export interface MobileKeyOutboxConfirm {
  state: number
  description: string
  result: { row: OutboxSubmission; dispatched: boolean } | null
}

export interface MobileKeyInboxStatus {
  state: number
  description: string
  result: InboxPollResult | null
}

export interface IsdsMobileCredentialProfile {
  id?: number
  saved: boolean
  username: string | null
  environment: 'production' | 'test'
}

export interface SmsInboxStart {
  flow_token: string
  description: string
  expires_at: string
}

export interface IsdsGatewayCapability {
  environment: 'production' | 'test'
  available: boolean
}

/**
 * Stav relace po návratu z ISDS.
 *
 * `awaiting_approval` = koncept leží v datové schránce a čeká na schválení.
 * `uncertain` = NEVÍME, jestli zpráva odešla; podání se nesmí odeslat znovu,
 * dokud si to uživatel neověří v odeslaných zprávách své schránky.
 */
export type GatewaySessionState =
  | 'awaiting_login'
  | 'awaiting_approval'
  | 'approved'
  | 'rejected'
  | 'failed'
  | 'uncertain'
  | 'expired'

export interface GatewayComplete {
  state: GatewaySessionState
  outbox_id: number
  /** Kam poslat prohlížeč dál (schvalovací obrazovka konceptu), nebo `null`. */
  redirect_url: string | null
  external_message_id: string | null
  message: string
}

export const dataBoxApi = {
  credentials: () =>
    api.get<{ items: DataBoxCredential[] }>('/settings/databox').then(r => r.data.items),

  saveCredential: (data: FormData) =>
    api.post<DataBoxCredential>('/settings/databox', data, {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data),

  deleteCredential: (environment: string) =>
    api.delete(`/settings/databox/${environment}`).then(r => r.data),

  mobileKeyProfile: (environment: 'production' | 'test') =>
    api.get<IsdsMobileCredentialProfile>('/settings/databox/mobile-key', {
      params: { environment },
    }).then(r => r.data),

  saveMobileKeyProfile: (environment: 'production' | 'test', username: string, communicationCode: string) =>
    api.post<IsdsMobileCredentialProfile>('/settings/databox/mobile-key', {
      environment,
      username,
      communication_code: communicationCode,
    }).then(r => r.data),

  deleteMobileKeyProfile: (environment: 'production' | 'test') =>
    api.delete<{ deleted: boolean }>(`/settings/databox/mobile-key/${environment}`).then(r => r.data),

  inboxStorage: () =>
    api.get<DataBoxInboxStorageSettings>('/settings/databox/inbox-storage').then(r => r.data),

  saveInboxStorage: (
    environment: 'production' | 'test',
    baseFolderId: number | null,
    rowVersion: number,
  ) => api.put<{ item: DataBoxInboxStorageSetting | null }>(
    `/settings/databox/inbox-storage/${environment}`,
    { base_folder_id: baseFolderId, row_version: rowVersion },
  ).then(r => r.data.item),

  recipients: (kind?: RecipientKind) =>
    api.get<{ items: SubmissionRecipient[] }>('/submissions/recipients', {
      params: kind ? { kind } : undefined,
    }).then(r => r.data.items),

  saveRecipient: (data: Partial<SubmissionRecipient>) =>
    api.post<{ id: number }>('/submissions/recipients', data).then(r => r.data),

  deleteRecipient: (id: number) =>
    api.delete(`/submissions/recipients/${id}`).then(r => r.data),

  outbox: (environment: string) =>
    api.get<{ items: OutboxSubmission[] }>('/submissions/outbox', {
      params: { environment },
    }).then(r => r.data.items),

  attempts: (id: number) =>
    api.get<{ items: OutboxAttempt[] }>(`/submissions/outbox/${id}/attempts`).then(r => r.data.items),

  confirm: (id: number, environment: string) =>
    api.post<{ row: OutboxSubmission; dispatched: boolean }>(
      `/submissions/outbox/${id}/confirm`,
      { environment },
    ).then(r => r.data),

  resolve: (id: number, environment: string) =>
    api.post<OutboxSubmission>(`/submissions/outbox/${id}/resolve`, { environment }).then(r => r.data),

  cancel: (id: number) =>
    api.post<OutboxSubmission>(`/submissions/outbox/${id}/cancel`, {}).then(r => r.data),

  // ── Odesílací brána ISDS ───────────────────────────────────────────────────

  /**
   * Registrace brány. Bez práva `settings.signing` vrátí 403 — volající to
   * musí umět přejít mlčky: nepřítomnost výpisu znamená „nevím", ne „není".
   */
  gatewayRegistrations: () =>
    api.get<{ items: IsdsGatewayRegistration[] }>('/settings/isds-gateway').then(r => r.data.items),

  /** Totéž co {@link gatewayRegistrations}, ale i s předvolbami hostitelů pro formulář. */
  gatewaySettings: () =>
    api.get<IsdsGatewaySettings>('/settings/isds-gateway').then(r => r.data),

  /**
   * Uloží registraci brány. Multipart, protože jde o soubor PKCS#12.
   *
   * ⚠️ Po uložení je registrace VŽDY vypnutá — zapíná se zvlášť přes
   * {@link setGatewayActive}, až když ji provozovatel ověří pokusem.
   */
  saveGatewayRegistration: (input: IsdsGatewayRegistrationInput) =>
    api.post<IsdsGatewayRegistration>('/settings/isds-gateway', gatewayForm(input), {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data),

  /** Zapnutí/vypnutí registrace. Zapnout nejde neexistující ani prošlou. */
  setGatewayActive: (environment: string, active: boolean) =>
    api.post<{ environment: string; active: boolean }>('/settings/isds-gateway/active', {
      environment,
      active,
    }).then(r => r.data),

  deleteGatewayRegistration: (environment: string) =>
    api.delete<{ deleted: boolean }>(`/settings/isds-gateway/${environment}`).then(r => r.data),

  /**
   * Zahájí odeslání přes bránu. Server sám nepřesměrovává — vrátí adresu,
   * aby šlo uživateli nejdřív ukázat, co ho v datové schránce čeká.
   */
  gatewayStart: (id: number) =>
    api.post<GatewayStart>(`/submissions/outbox/${id}/gateway`, {}).then(r => r.data),

  gatewayStartPayroll: (id: number) =>
    api.post<GatewayStart>(`/payroll/submissions/isds-gateway/outbox/${id}`, {}).then(r => r.data),

  gatewayCapabilities: () =>
    api.get<{ items: IsdsGatewayCapability[] }>('/submissions/gateway/capability')
      .then(r => r.data.items),

  /**
   * Návrat z ISDS. Volá se pro OBĚ přesměrování — o tom, která fáze to je,
   * rozhoduje stav relace na serveru, ne parametr z prohlížeče.
   */
  gatewayComplete: (appToken: string, sessionId: string) =>
    api.post<GatewayComplete>('/submissions/gateway/callback', {
      app_token: appToken,
      session_id: sessionId,
    }).then(r => r.data),

  gatewayCompletePayroll: (appToken: string, sessionId: string) =>
    api.post<GatewayComplete>('/payroll/submissions/isds-gateway/callback', {
      app_token: appToken,
      session_id: sessionId,
    }).then(r => r.data),

  inbox: (
    environment: string,
    classification?: InboxClassification,
    visibility: 'active' | 'hidden' | 'all' = 'active',
  ) =>
    api.get<{ items: InboxMessage[]; state: InboxPollState | null }>('/submissions/inbox', {
      params: { environment, classification, visibility },
    }).then(r => r.data),

  hideInboxMessage: (id: number, rowVersion: number) =>
    api.post<{ item: InboxMessage }>(`/submissions/inbox/${id}/hide`, {
      row_version: rowVersion,
    }).then(r => r.data.item),

  restoreInboxMessage: (id: number, rowVersion: number) =>
    api.post<{ item: InboxMessage }>(`/submissions/inbox/${id}/restore`, {
      row_version: rowVersion,
    }).then(r => r.data.item),

  purgeInboxLocalContent: (id: number, rowVersion: number) =>
    api.delete<{ item: InboxMessage }>(`/submissions/inbox/${id}/local-content`, {
      data: { row_version: rowVersion, acknowledged: true },
    }).then(r => r.data.item),

  pollInbox: (environment: string) =>
    api.post<InboxPollResult>(
      '/submissions/inbox/poll',
      { environment, acknowledged: true },
    ).then(r => r.data),

  pollInboxWithPassword: (environment: string, username: string, password: string) =>
    api.post<InboxPollResult>('/submissions/inbox/poll/password', {
      environment,
      username,
      password,
      acknowledged: true,
    }).then(r => r.data),

  startMobileKeyInbox: (environment: string, username: string, communicationCode: string, useSaved = false) =>
    api.post<MobileKeyInboxStart>('/submissions/inbox/mobile-key/start', {
      environment,
      username,
      communication_code: communicationCode,
      use_saved_credentials: useSaved,
      acknowledged: true,
    }).then(r => r.data),

  mobileKeyInboxStatus: (flowToken: string, environment: string) =>
    api.post<MobileKeyInboxStatus>('/submissions/inbox/mobile-key/status', {
      flow_token: flowToken,
      environment,
    }).then(r => r.data),

  startMobileKeyOutbox: (id: number, environment: string, username: string, communicationCode: string, useSaved = false) =>
    api.post<MobileKeyInboxStart>(`/submissions/outbox/${id}/mobile-key/start`, {
      environment,
      username,
      communication_code: communicationCode,
      use_saved_credentials: useSaved,
    }).then(r => r.data),

  mobileKeyOutboxConfirm: (id: number, flowToken: string, environment: string) =>
    api.post<MobileKeyOutboxConfirm>(`/submissions/outbox/${id}/mobile-key/confirm`, {
      flow_token: flowToken,
      environment,
    }).then(r => r.data),

  startSmsInbox: (environment: string, username: string, password: string) =>
    api.post<SmsInboxStart>('/submissions/inbox/sms/start', {
      environment,
      username,
      password,
      acknowledged: true,
    }).then(r => r.data),

  completeSmsInbox: (flowToken: string, smsCode: string, environment: string) =>
    api.post<InboxPollResult>('/submissions/inbox/sms/complete', {
      flow_token: flowToken,
      sms_code: smsCode,
      environment,
    }).then(r => r.data),

  classify: (
    id: number,
    classification: InboxClassification,
    outboxId: number | null,
    rowVersion: number,
  ) => api.post(`/submissions/inbox/${id}/classify`, {
    classification,
    outbox_id: outboxId,
    row_version: rowVersion,
  }).then(r => r.data),

  /**
   * „Odeslal jsem to ručně." ID zprávy není formalita — je to přesný
   * identifikátor, podle kterého se doručenka spáruje sama, i kdyby v ní naše
   * spisová značka nebyla.
   */
  markSentManually: (id: number, externalMessageId: string, sentAt?: string) =>
    api.post<{ row: OutboxSubmission; recorded: boolean; validation: { status: string; checked: boolean; errors: string[] } }>(
      `/submissions/outbox/${id}/mark-sent`,
      { external_message_id: externalMessageId, sent_at: sentAt },
    ).then(r => r.data),

  /** Nahrání doručenky přímo u podání — vazbu určuje uživatel. */
  uploadReceiptFor: (id: number, environment: string, file: File) =>
    api.post<ReceiptUploadResult>(`/submissions/outbox/${id}/receipt`, receiptForm(environment, file), {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data),

  /** Nahrání doručenky bez určeného podání — párování hledá aplikace. */
  uploadReceipt: (environment: string, file: File) =>
    api.post<ReceiptUploadResult>('/submissions/receipts', receiptForm(environment, file), {
      headers: { 'Content-Type': 'multipart/form-data' },
    }).then(r => r.data),

  unmatchedReceipts: (environment: string) =>
    api.get<{ items: InboxMessage[] }>('/submissions/receipts/unmatched', {
      params: { environment },
    }).then(r => r.data.items),

  receiptCandidates: (inboxMessageId: number) =>
    api.get<{ items: ReceiptCandidate[] }>(`/submissions/receipts/${inboxMessageId}/candidates`)
      .then(r => r.data.items),

  matchReceipt: (inboxMessageId: number, outboxId: number) =>
    api.post<ReceiptUploadResult>(`/submissions/receipts/${inboxMessageId}/match`, { outbox_id: outboxId })
      .then(r => r.data),

  /**
   * Přepočet rozhodného dne doručení. Nesahá na síť ani na schránku — jen
   * znovu posoudí už stažené zprávy. Běžící lhůta fikce se totiž mění pouhým
   * během času a bez přepočtu by zpráva zůstala navěky v „lhůta běží".
   */
  refreshDelivery: (environment: string) =>
    api.post<{ checked: number; changed: number; delivered_by_fiction: number }>(
      '/submissions/inbox/delivery/refresh',
      { environment },
    ).then(r => r.data),

  /**
   * Výzvy k odstranění vad. `notice` v odpovědi je důležité: prázdný seznam
   * znamená „žádná zaevidovaná", ne „žádná nepřišla" — aplikace výzvy
   * z datové schránky sama nerozpoznává.
   */
  defectNotices: (environment: string, openOnly = false) =>
    api.get<{ supported: boolean; items: DefectNotice[]; notice: string }>('/submissions/defect-notices', {
      params: { environment, open: openOnly ? '1' : undefined },
    }).then(r => r.data),

  createDefectNotice: (data: DefectNoticeInput) =>
    api.post<DefectNotice & { created: boolean }>('/submissions/defect-notices', data).then(r => r.data),

  amendDefectNotice: (id: number, rowVersion: number, data: Partial<DefectNoticeInput> & { withdrawn?: boolean }) =>
    api.patch<DefectNotice>(`/submissions/defect-notices/${id}`, { ...data, row_version: rowVersion })
      .then(r => r.data),

  answerDefectNotice: (id: number, rowVersion: number, respondedOn: string, responseOutboxId?: number | null) =>
    api.post<DefectNotice>(`/submissions/defect-notices/${id}/response`, {
      row_version: rowVersion,
      responded_on: respondedOn,
      response_outbox_id: responseOutboxId ?? null,
    }).then(r => r.data),
}

function gatewayForm(input: IsdsGatewayRegistrationInput): FormData {
  const form = new FormData()
  form.append('environment', input.environment)
  form.append('ats_id', input.ats_id)
  form.append('label', input.label)
  form.append('return_url', input.return_url)
  form.append('error_url', input.error_url ?? '')
  form.append('concept_ttl_seconds', String(input.concept_ttl_seconds))
  form.append('portal_host', input.portal_host)
  form.append('service_host', input.service_host)
  form.append('user_login_policy', input.user_login_policy)
  form.append('certificate', input.certificate)
  form.append('certificate_password', input.certificate_password)
  return form
}

function receiptForm(environment: string, file: File): FormData {
  const form = new FormData()
  form.append('environment', environment)
  form.append('receipt', file)
  return form
}
